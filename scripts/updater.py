#!/usr/bin/env python3
"""Root host agent. Telegram can request only update/rollback, never shell commands."""
import contextlib
import errno
import fcntl
import html
import json
import os
from pathlib import Path
import re
import sqlite3
import subprocess
import sys
import tarfile
import tempfile
import time
import urllib.parse
import urllib.error
import urllib.request
from datetime import datetime
from zoneinfo import ZoneInfo

PROJECT = Path(__file__).resolve().parents[1] if '--cli-update' in sys.argv else Path('/opt/ciro-downloader')
STATE = Path('/var/lib/ciro-downloader-updater')
BACKUPS = Path('/var/backups/ciro-downloader')
ORIGIN = 'https://github.com/Alfred-1313/CiroDownloader.git'
IMAGE = 'ciro-downloader:local'
SHA = re.compile(r'^[0-9a-f]{40}$')


def operation_name(args):
    if args[:3] == ('docker', 'compose', 'build'):
        return 'build'
    if args[:3] == ('docker', 'compose', 'up'):
        return 'start'
    if args[:3] == ('docker', 'compose', 'stop'):
        return 'stop'
    if args[:3] == ('docker', 'image', 'rm'):
        return 'cleanup_image'
    if args[:3] == ('docker', 'image', 'inspect'):
        return 'backup'
    if args[:2] == ('git', 'fetch'):
        return 'fetch'
    if args[:2] in (('git', 'merge'), ('git', 'checkout')):
        return 'checkout'
    if args[:1] == ('git',):
        return 'source'
    if args[:1] == ('bash',):
        return 'agent'
    return 'server'


def output_reason(output):
    text = output.decode('utf-8', errors='replace').lower() if isinstance(output, bytes) else ''
    for reason, pattern in (
        ('storage', r'no space left|disk quota|not enough (?:disk|space)'),
        ('permission', r'permission denied|operation not permitted|access is denied'),
        ('image_used', r'image is being used|image is referenced|must be forced|conflict: unable to delete'),
        ('image_missing', r'no such image|image does not exist|no such object'),
        ('network', r'could not resolve|connection (?:refused|reset)|network is unreachable|tls handshake|certificate|timed out|timeout|temporary failure|failed to fetch'),
        ('local_changes', r'local changes|untracked working tree|would be overwritten|not possible to fast-forward|divergent branches'),
    ):
        if re.search(pattern, text):
            return reason
    return 'process'


class UpdateFailure(RuntimeError):
    def __init__(self, operation, reason='process', code=None):
        self.operation, self.reason, self.code = operation, reason, code
        super().__init__('Update operation failed: ' + operation + ' (' + reason + ')')


def failure_info(error):
    if isinstance(error, UpdateFailure):
        return error.operation, error.reason, error.code
    if isinstance(error, (TimeoutError, subprocess.TimeoutExpired)):
        return 'server', 'timeout', None
    if isinstance(error, OSError) and error.errno == errno.ENOSPC:
        return 'server', 'storage', None
    if isinstance(error, PermissionError):
        return 'server', 'permission', None
    if isinstance(error, (tarfile.TarError, KeyError, json.JSONDecodeError)):
        return 'backup', 'backup_invalid', None
    known = {'Local source changes prevent an update': ('source', 'local_changes'),
             'Unexpected repository origin': ('source', 'origin'),
             'Retained backup is missing': ('backup', 'backup_missing'),
             'Retained backup is incomplete': ('backup', 'backup_invalid'),
             'Invalid retained version': ('backup', 'backup_invalid'),
             'Invalid pending recovery': ('backup', 'backup_invalid'),
             'Unsafe backup directory': ('backup', 'backup_invalid'),
             'Bot startup health check failed': ('start', 'health')}
    operation, reason = known.get(str(error), ('server', 'process'))
    return operation, reason, None


def command(*args, timeout=90):
    # No shell, interpolation, or arguments supplied by Telegram.
    result = subprocess.run(args, cwd=PROJECT, capture_output=True, timeout=timeout)
    if result.returncode:
        raise UpdateFailure(operation_name(args), output_reason(result.stderr[-16384:]), result.returncode)
    return result.stdout.decode('utf-8', errors='replace').strip()


def environment():
    values = {}
    for line in (PROJECT / '.env').read_text().splitlines():
        match = re.fullmatch(r'([A-Z_]+)=(.*)', line.strip())
        if match:
            values[match[1]] = match[2].strip(' \t\"\'')
    if not re.fullmatch(r'\d+:[A-Za-z0-9_-]+', values.get('BOT_TOKEN', '')):
        raise RuntimeError('Invalid bot configuration')
    if not re.fullmatch(r'[1-9]\d*(,[1-9]\d*)*', values.get('ADMIN_IDS', '')):
        raise RuntimeError('Invalid admin configuration')
    return values


def atomic_json(path, value):
    temporary = path.with_suffix('.tmp')
    temporary.write_text(json.dumps(value, ensure_ascii=False))
    temporary.chmod(0o600)
    temporary.replace(path)


def release_notes(project=None):
    project = project or PROJECT
    version = (project / 'VERSION').read_text().strip() if (project / 'VERSION').is_file() else 'قدیمی'
    try:
        entries = json.loads((project / 'CHANGELOG.json').read_text())
        changes = next(entry['changes'] for entry in entries if entry['version'] == version)
        changes = [str(item.get('title', '') if isinstance(item, dict) else item)[:240] for item in changes[:8]]
    except (OSError, ValueError, KeyError, StopIteration, TypeError):
        changes = ['فهرست تغییرات این نسخه ثبت نشده است.']
    return version, changes


def changes_since(installed, target, project=None):
    """Only announce additions the installed version did not already contain."""
    def key(version):
        return tuple(map(int, version.split('.'))) if re.fullmatch(r'\d+\.\d+\.\d+', version) else None
    first, last = key(installed), key(target)
    if first is None or last is None or first >= last:
        return []
    try:
        entries = json.loads(((project or PROJECT) / 'CHANGELOG.json').read_text())
    except (OSError, ValueError):
        return []
    known, additions = set(), []
    for entry in sorted(entries, key=lambda entry: key(entry.get('version', '')) or (0, 0, 0)):
        version = key(entry.get('version', ''))
        if version is None or version > last:
            continue
        for change in entry.get('changes', []):
            title = change.get('title') if isinstance(change, dict) else change
            if not isinstance(title, str) or not title.strip():
                continue
            normalized = ' '.join(title.split()).casefold()
            if version > first and normalized not in known:
                additions.append(change)
            known.add(normalized)
    return additions


def change_key(change):
    title = change.get('title') if isinstance(change, dict) else change
    if not isinstance(title, str) or not title.strip():
        return ''
    if isinstance(change, dict) and re.fullmatch(r'[a-z0-9][a-z0-9_.-]{0,99}', str(change.get('id', ''))):
        return change['id']
    return ' '.join(title.split()).casefold()


def manifest_changes(document):
    entries = document if isinstance(document, list) else []
    return [item for entry in entries if isinstance(entry, dict) and isinstance(entry.get('changes'), list) for item in entry['changes']
            if change_key(item)]


def release_revision(project=None):
    try:
        entries = json.loads(((project or PROJECT) / 'CHANGELOG.json').read_text())
        version = release_notes(project)[0]
        revision = next(entry.get('revision', '') for entry in entries if entry.get('version') == version)
        return revision if isinstance(revision, str) and re.fullmatch(r'[a-z0-9][a-z0-9_.-]{0,99}', revision) else ''
    except (OSError, ValueError, TypeError, AttributeError, StopIteration):
        return ''


def history_git(project, *args):
    result = subprocess.run(['git', *args], cwd=project, capture_output=True, timeout=20)
    if result.returncode or len(result.stdout) > 1048576:
        raise RuntimeError('Update history is unavailable')
    return result.stdout.decode('utf-8', errors='replace').strip()


def source_revision(project=None):
    try:
        value = history_git(project or PROJECT, 'rev-parse', 'HEAD')
        return value if SHA.fullmatch(value) else ''
    except (OSError, RuntimeError, subprocess.TimeoutExpired):
        return ''


def revision_changes(installed, target, project=None):
    """Read public release notes across main commits, including updates with the same VERSION."""
    if not isinstance(installed, str) or not isinstance(target, str) or not SHA.fullmatch(installed) or not SHA.fullmatch(target) or installed == target:
        return []
    project = project or PROJECT
    def read(sha):
        try:
            return manifest_changes(json.loads(history_git(project, 'show', sha + ':CHANGELOG.json')))
        except (OSError, RuntimeError, ValueError, TypeError, subprocess.TimeoutExpired):
            return []
    try:
        history_git(project, 'merge-base', '--is-ancestor', installed, target)
        commits = history_git(project, 'log', '--reverse', '--format=%H', installed + '..' + target, '--', 'CHANGELOG.json').splitlines()
    except (OSError, RuntimeError, subprocess.TimeoutExpired):
        return []
    known = {change_key(item) for item in read(installed)}
    additions = []
    for sha in commits:
        if not SHA.fullmatch(sha):
            continue
        for item in read(sha):
            key = change_key(item)
            if key not in known:
                additions.append(item)
                known.add(key)
    return additions


def valid_recovery(value):
    return (isinstance(value, dict) and bool(SHA.fullmatch(value.get('sha', '')))
            and bool(re.fullmatch(r'ciro-downloader:backup-[0-9a-f]{12}-\d+', value.get('image', ''))))


def prune_previous():
    """Only Ciro's automatic update artifacts; never Docker volumes or manual backups."""
    record = STATE / 'previous.json'
    if not record.is_file():
        return  # Without a recovery record, deletion cannot be proven safe.
    previous = json.loads(record.read_text())
    if not valid_recovery(previous):
        raise RuntimeError('Invalid retained version')
    records = [previous]
    for name in ('recovery.json', 'completion-pending.json'):
        path = STATE / name
        if path.exists():
            pending = json.loads(path.read_text()).get('previous')
            if not valid_recovery(pending):
                raise RuntimeError('Invalid pending recovery')
            records.append(pending)
    keep_images = {item['image'] for item in records}
    keep_backups = {item.get('backup', '') for item in records}
    # Earlier releases omitted microseconds; both formats are automatic update archives.
    pattern = r'pre-update-\d{8}-\d{6}(?:-\d{6})?-\d+\.tar\.gz'
    if not previous.get('backup') or not re.fullmatch(pattern, previous['backup']):
        return  # An older record without its archive is not enough to prune backups.
    if BACKUPS.is_symlink():
        raise RuntimeError('Unsafe backup directory')
    archive = BACKUPS / previous['backup']
    if not archive.is_file() or archive.is_symlink():
        raise RuntimeError('Retained backup is missing')
    with tarfile.open(archive, 'r:gz') as retained:
        for required in ('source/.env', 'data/zero.sqlite'):
            member = retained.getmember(required)
            if not member.isfile() or member.size < 1:
                raise RuntimeError('Retained backup is incomplete')
    command('docker', 'image', 'inspect', previous['image'])
    # Inspect the actual keep image before removing any other tags.
    tags = command('docker', 'image', 'ls', '--format', '{{.Repository}}:{{.Tag}}').splitlines()
    for tag in tags:
        if re.fullmatch(r'ciro-downloader:backup-[0-9a-f]{12}-\d+', tag) and tag not in keep_images:
            command('docker', 'image', 'rm', tag)  # never --force, never prune unrelated images
    if BACKUPS.is_symlink():
        raise RuntimeError('Unsafe backup directory')
    for path in BACKUPS.iterdir():
        if re.fullmatch(pattern, path.name) and path.name not in keep_backups and path.is_file() and not path.is_symlink():
            path.unlink()


def ui_definitions():
    for path in (PROJECT / 'resources/ui.json', Path(__file__).with_name('ui.json'), Path(__file__).resolve().parents[1] / 'resources/ui.json'):
        if path.is_file():
            return json.loads(path.read_text(encoding='utf-8'))
    raise RuntimeError('UI catalog is unavailable')


class Agent:
    def __init__(self):
        self.env = environment()
        self.admins = {int(item) for item in self.env['ADMIN_IDS'].split(',')}
        self.data = Path(command('docker', 'volume', 'inspect', 'ciro-downloader_data', '--format', '{{.Mountpoint}}'))
        runtime_user = command('docker', 'image', 'inspect', IMAGE, '--format', '{{.Config.User}}')
        self.uid = 10001
        if runtime_user not in ('zero', '10001'):
            raise RuntimeError('Unexpected runtime identity')
        # Docker's parent directories are root-only. A private bind mount lets the
        # runtime UID open SQLite without changing Docker directory permissions.
        accessible = Path('/run/ciro-downloader-data')
        accessible.mkdir(mode=0o700, exist_ok=True)
        if not os.path.samefile(self.data, accessible):
            command('mount', '--bind', str(self.data), str(accessible))
        self.data = accessible
        self.job = None
        self.last_beat = 0
        with self.db() as db:
            db.executescript("""CREATE TABLE IF NOT EXISTS update_requests (
                id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL,
                chat_id INTEGER NOT NULL, message_id INTEGER NOT NULL DEFAULT 0,
                action TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'queued', created INTEGER NOT NULL);
                CREATE UNIQUE INDEX IF NOT EXISTS one_update ON update_requests((1)) WHERE status IN ('queued','running');""")
            if 'thread_id' not in {row['name'] for row in db.execute('PRAGMA table_info(update_requests)')}:
                db.execute('ALTER TABLE update_requests ADD COLUMN thread_id INTEGER NOT NULL DEFAULT 0')

    def authorized(self, job, db=None):
        return (self.is_admin(job['user_id'], db) and job['action'] in ('update', 'rollback')
                and (job['chat_id'] == job['user_id'] or job['chat_id'] < 0)
                and isinstance(job.get('thread_id', 0), int) and job.get('thread_id', 0) >= 0
                and (job['chat_id'] < 0 or job.get('thread_id', 0) == 0))

    def is_admin(self, user, db=None):
        if user in self.admins:
            return True
        if not self.admins:
            return False
        if db is None:
            with self.db() as connection:
                return self.is_admin(user, connection)
        if not db.execute("SELECT 1 FROM sqlite_master WHERE type='table' AND name='bot_admins'").fetchone():
            return False
        roots = list(self.admins)
        seeds = ','.join('(?)' for _ in roots)
        row = db.execute('WITH RECURSIVE authorized(id) AS (VALUES ' + seeds +
                         ' UNION SELECT a.user_id FROM bot_admins a JOIN authorized p ON a.added_by=p.id)'
                         ' SELECT 1 FROM authorized WHERE id=?', (*roots, user)).fetchone()
        return row is not None

    def menu_scope(self):
        scope = str(self.job['user_id'])
        if self.job['chat_id'] < 0:
            scope += '.' + str(self.job['chat_id']) + '.' + str(self.job.get('thread_id', 0))
        return scope

    @contextlib.contextmanager
    def db(self):
        # Keep SQLite WAL/SHM files writable by the container after this root process exits.
        os.seteuid(self.uid)
        db = None
        try:
            db = sqlite3.connect(self.data / 'zero.sqlite', timeout=10)
            db.row_factory = sqlite3.Row
            yield db
            db.commit()
        finally:
            if db is not None:
                db.close()
            os.seteuid(0)

    def set(self, key, value):
        with self.db() as db:
            db.execute('INSERT INTO settings(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value', (key, str(value)))

    def setting(self, key, default=''):
        with self.db() as db:
            value = db.execute('SELECT value FROM settings WHERE key=?', (key,)).fetchone()
        return value[0] if value else default

    def ui(self, key, _raw=(), **values):
        item = ui_definitions()['texts'][key]
        replacements = {name: str(value) if name in _raw else html.escape(str(value)) for name, value in values.items()}
        def render(template):
            return re.sub(r'\{([a-z_]+)\}', lambda match: replacements.get(match[1], match[0]), template)
        result = render(self.setting('text.' + key, item['value']))
        if len(html.unescape(re.sub(r'<[^>]+>', '', result)).encode('utf-16-le')) // 2 > 3900:
            result = render(item['value'])
        return result

    def button(self, key, data, **values):
        item = ui_definitions()['buttons'][key]
        label = self.setting('button.' + key, item['value'])
        label = re.sub(r'\{([a-z_]+)\}', lambda match: str(values.get(match[1], match[0])), label)
        result = {'text': label, 'callback_data': data}
        style = self.setting('button.style.' + key, item.get('style', ''))
        if style in ('primary', 'success', 'danger'):
            result['style'] = style
        icon = self.setting('button.icon.' + key)
        if re.fullmatch(r'\d{5,24}', icon):
            result['icon_custom_emoji_id'] = icon
        return result

    KEYCAPS = ['1️⃣', '2️⃣', '3️⃣', '4️⃣', '5️⃣', '6️⃣', '7️⃣', '8️⃣', '9️⃣']

    def plain(self, key):
        return html.unescape(re.sub(r'<[^>]+>', '', self.ui(key, _raw=())))

    def render_change(self, change, position):
        """One what's-new entry: what it is, an example, its default and the steps to find it."""
        if isinstance(change, str):
            return '• ' + html.escape(change[:240])
        keys = self.KEYCAPS
        lines = [self.ui('update_item', n=keys[position - 1] if position <= len(keys) else '▫️', title=str(change['title'])[:80], what=str(change.get('what', ''))[:220])]
        if isinstance(change.get('example'), str) and change['example']:
            lines.append(self.ui('update_item_example', example=change['example'][:200]))
        if change.get('default') in ('on', 'off'):
            lines.append(self.ui('update_item_default', state=self.plain('update_state_on' if change['default'] == 'on' else 'update_state_off')))
        steps = [self.ui('update_path_step', n=keys[index], label=label[:40]) for index, label in enumerate([x for x in change.get('path', []) if isinstance(x, str)][:6])]
        if steps:
            lines.append(self.ui('update_item_path', path=self.plain('update_path_sep').join(steps), _raw=('path',)))
        return '\n'.join(line for line in lines if re.sub(r'<[^>]+>', '', line).strip())

    def render_changes(self, changes, limit=3):
        return '\n\n'.join(self.render_change(change, index) for index, change in enumerate(changes[:limit], 1))

    def saved_changes(self):
        try:
            report = json.loads(self.setting('update_new_changes', '{}'))
            if (report.get('to') == release_notes()[0] and report.get('to_revision', '') == release_revision()
                    and isinstance(report.get('changes'), list)):
                return [item for item in report['changes'] if change_key(item)]
        except (ValueError, TypeError, AttributeError):
            pass
        return []

    def record_changes(self, from_version=None, from_revision=None, to_revision=None):
        version = release_notes()[0]
        additions = revision_changes(from_revision, to_revision or source_revision()) if from_revision else []
        if not additions and from_version:
            additions = changes_since(from_version, version)
        if (not additions and from_revision and release_revision()
                and self.setting('update_installed_revision') != release_revision()):
            # Older history may be missing, or a manual pull may have left the containers behind the checkout.
            try:
                additions = manifest_changes(json.loads((PROJECT / 'CHANGELOG.json').read_text()))
            except (OSError, ValueError, TypeError):
                pass
        if additions:
            self.set('update_new_changes', json.dumps({'from': from_version, 'to': version,
                     'from_sha': from_revision, 'to_sha': to_revision, 'to_revision': release_revision(),
                     'changes': additions}, ensure_ascii=False))
        elif to_revision and (not from_revision and not from_version or not self.saved_changes()):
            # Older rollback images only compare VERSION, so remove an incompatible report for them too.
            self.set('update_new_changes', '')
        if to_revision:
            self.set('update_installed_revision', release_revision() if from_revision or from_version else '')
        return additions

    def beat(self):
        if time.time() - self.last_beat >= 8:
            self.set('updater_heartbeat', int(time.time()))
            self.last_beat = time.time()

    def api(self, method, params):
        url = self.env.get('BOT_API_URL', 'https://api.telegram.org').rstrip('/')
        if url == 'http://ciro-telegram-api:8081':
            url = 'http://127.0.0.1:18081'
        url += '/bot' + self.env['BOT_TOKEN'] + '/' + method
        body = urllib.parse.urlencode({key: json.dumps(value, ensure_ascii=False) if isinstance(value, (dict, list)) else value for key, value in params.items()}).encode()
        try:
            with urllib.request.urlopen(urllib.request.Request(url, data=body), timeout=30) as response:
                result = json.load(response)
        except urllib.error.HTTPError as error:
            try:
                rejected = json.loads(error.read(8192))
            except (ValueError, OSError):
                rejected = {}
            if error.code == 400 and method == 'editMessageText' and 'message is not modified' in str(rejected.get('description', '')).lower():
                return True  # A retry of the same edit has already reached Telegram.
            raise UpdateFailure('notification', 'telegram', error.code) from None
        except urllib.error.URLError:
            raise UpdateFailure('notification', 'network') from None
        if not result.get('ok'):
            raise UpdateFailure('notification', 'telegram', result.get('error_code'))
        return result['result']

    def failure_detail(self, error=None, stage=None):
        operation, reason, code = failure_info(error)
        stages = {'update_check', 'update_idle', 'update_backup', 'update_build', 'update_starting', 'update_health', 'update_restore', 'update_cleanup', 'update_agent'}
        stage = stage or self.setting('update_stage', 'update_check')
        stage = stage if stage in stages else 'update_check'
        detail = self.ui('update_failure_context', stage=self.plain(stage), operation=self.plain('update_operation_' + operation), reason=self.plain('update_reason_' + reason))
        if isinstance(code, int):
            detail += '\n' + self.ui('update_exit_code', code=code)
        return detail

    def error_alert(self, error=None, stage=None, warning=False):
        try:
            detail = self.failure_detail(error, stage)
        except Exception:
            return ''  # Alerting must never replace the original failure or break a healthy update.
        try:
            with self.db() as db:
                roots = tuple(self.admins)
                placeholders = ','.join('(?)' for _ in roots)
                admins = {int(row[0]) for row in db.execute('WITH RECURSIVE authorized(id) AS (VALUES ' + placeholders + ' UNION SELECT a.user_id FROM bot_admins a JOIN authorized p ON a.added_by=p.id) SELECT id FROM authorized', roots)}
        except Exception:
            admins = self.admins
        for admin in admins:
            try:
                self.api('sendMessage', {'chat_id': admin, 'text': self.ui('update_private_alert', title=self.plain('update_warning_title' if warning else 'update_error_title'), detail=detail, _raw=('detail',)), 'parse_mode': 'HTML'})
            except Exception:
                pass
        return detail

    def sync_message(self):
        if not self.job or not self.job.get('id'):
            return
        with self.db() as db:
            current = db.execute('SELECT chat_id,thread_id,message_id FROM update_requests WHERE id=?', (self.job['id'],)).fetchone()
        if (current and current['message_id'] > 0 and current['chat_id'] == self.job['chat_id']
                and current['thread_id'] == self.job.get('thread_id', 0)):
            self.job['message_id'] = current['message_id']

    def notify(self, text, buttons=None):
        self.sync_message()
        params = {'chat_id': self.job['chat_id'], 'message_id': self.job['message_id'],
                  'text': text, 'parse_mode': 'HTML', 'disable_web_page_preview': 'true',
                  'reply_markup': {'inline_keyboard': buttons or []}}
        try:
            self.api('editMessageText', params)
            return True
        except Exception:
            # A deleted progress message or temporary API outage must not abort recovery.
            return False

    @staticmethod
    def bar(percent):
        number = str(percent)
        filled = percent // 10
        return '▰' * filled + '▱' * (10 - filled) + ' ' + number + '%'

    def progress(self, text, percent=5):
        self.beat()
        self.percent = max(getattr(self, 'percent', 0), min(99, percent))
        self.set('update_percent', self.percent)
        self.set('update_stage', text)
        number = str(self.percent)
        self.notify(self.ui('update_progress', bar=self.bar(self.percent), stage=self.ui(text), _raw=('stage',)),
                    [[self.button('update_progress', 'update:progress', percent=number)]])

    def run(self, *args, timeout=900):
        # A bounded tail exists only in RAM to classify failures; raw output is never logged or sent.
        proc = subprocess.Popen(args, cwd=PROJECT, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
        os.set_blocking(proc.stdout.fileno(), False)
        tail = bytearray()
        def drain():
            for _ in range(32):
                try:
                    chunk = os.read(proc.stdout.fileno(), 8192)
                except BlockingIOError:
                    break
                if not chunk:
                    break
                tail.extend(chunk)
                del tail[:-16384]
        deadline = time.monotonic() + timeout
        try:
            while proc.poll() is None:
                drain()
                self.beat()
                if time.monotonic() >= deadline:
                    raise UpdateFailure(operation_name(args), 'timeout')
                time.sleep(0.2)
            drain()
            if proc.returncode:
                raise UpdateFailure(operation_name(args), output_reason(bytes(tail)), proc.returncode)
        finally:
            if proc.poll() is None:
                proc.terminate()
                try:
                    proc.wait(timeout=10)
                except subprocess.TimeoutExpired:
                    proc.kill()
                    proc.wait()
            proc.stdout.close()

    def claim(self):
        with self.db() as db:
            db.execute('BEGIN IMMEDIATE')
            # A bot process may have died between creating the request and sending its message.
            db.execute("UPDATE update_requests SET status='failed' WHERE status='queued' AND message_id=0 AND created<?", (int(time.time()) - 120,))
            row = db.execute("SELECT * FROM update_requests WHERE status='queued' AND message_id>0 ORDER BY id LIMIT 1").fetchone()
            if row:
                allowed = self.authorized(dict(row), db)
                db.execute('UPDATE update_requests SET status=? WHERE id=?', ('running' if allowed else 'denied', row['id']))
                if allowed:
                    self.job = dict(row)
        return self.job is not None

    def backup(self):
        BACKUPS.mkdir(mode=0o700, parents=True, exist_ok=True)
        path = BACKUPS / ('pre-update-' + datetime.now().strftime('%Y%m%d-%H%M%S-%f') + '-' + str(self.job['id']) + '.tar.gz')
        snapshot = self.data / '.updater-snapshot.sqlite'
        temporary = None
        try:
            with self.db() as db:
                destination = sqlite3.connect(snapshot)
                try:
                    db.backup(destination)
                finally:
                    destination.close()
            excluded = {'zero.sqlite', 'zero.sqlite-wal', 'zero.sqlite-shm', snapshot.name,
                        'downloads', 'instagram-pages', 'pinterest-pages', 'tiktok-pages', 'twitter-pages',
                        'music-batches', 'yt-cache', 'radio', '.youtube-blocked', '.youtube-ok'}
            # Media queues/caches are runtime data, not a second copy of delivered files in every update backup.
            with tempfile.NamedTemporaryFile(prefix='.pre-update-', suffix='.partial', dir=BACKUPS, delete=False) as raw:
                temporary = Path(raw.name)
                with tarfile.open(fileobj=raw, mode='w:gz') as archive:
                    for item in PROJECT.iterdir():
                        if item.name not in ('.git', 'storage', '__pycache__'):
                            archive.add(item, arcname='source/' + item.name)
                    archive.add(snapshot, arcname='data/zero.sqlite')
                    for item in self.data.iterdir():
                        if (item.name not in excluded and not item.name.endswith('.lock')
                                and not item.name.startswith(('.keepalive-', '.cookie-check-', '.updater-snapshot.sqlite-'))):
                            archive.add(item, arcname='data/' + item.name)
            temporary.replace(path)
            return path.name
        finally:
            if temporary is not None:
                temporary.unlink(missing_ok=True)
            snapshot.unlink(missing_ok=True)

    def wait_idle(self):
        self.set('update_in_progress', 1)
        self.progress('update_idle', 20)
        deadline = time.monotonic() + 1300
        while True:
            with self.db() as db:
                running = db.execute("SELECT COUNT(*) FROM jobs WHERE status='running'").fetchone()[0]
            if not running:
                return
            if time.monotonic() > deadline:
                raise TimeoutError('Active download did not finish')
            self.beat()
            time.sleep(2)

    def verify(self):
        deadline = time.monotonic() + 100
        while time.monotonic() < deadline:
            with self.db() as db:
                values = dict(db.execute("SELECT key,value FROM settings WHERE key IN ('poller_heartbeat','worker_heartbeat')").fetchall())
            if all(int(values.get(key, 0)) > 0 and time.time() - int(values[key]) < 35 for key in ('poller_heartbeat', 'worker_heartbeat')):
                self.api('getMe', {})
                return
            self.beat()
            time.sleep(2)
        raise RuntimeError('Bot startup health check failed')

    def restore(self, previous):
        if not valid_recovery(previous):
            raise RuntimeError('Invalid recovery record')
        command('git', 'checkout', '--detach', previous['sha'])
        command('docker', 'tag', previous['image'], IMAGE)
        self.set('poller_heartbeat', 0)
        self.set('worker_heartbeat', 0)
        self.run('docker', 'compose', 'up', '-d', '--no-build', '--force-recreate', timeout=180)
        self.verify()

    def finish(self, title, detail='', failed=False, from_version=None, from_revision=None, to_revision=None):
        self.sync_message()
        self.set('update_in_progress', 0)
        version, _ = release_notes()
        additions = self.record_changes(from_version, from_revision, to_revision) if not failed else []
        previous = STATE / 'previous.json'
        buttons = [[self.button('update_start', 'update:start')]]
        secondary = []
        if self.saved_changes():
            secondary.append(self.button('update_changes', 'update:changes'))
        if previous.is_file():
            secondary.append(self.button('update_rollback', 'update:rollback'))
        if secondary:
            buttons.append(secondary)
        if self.job['chat_id'] < 0:
            buttons.append([self.button('close_update', 'update:back')])
        else:
            buttons.append([self.button('back_admin', 'update:back'), self.button('update_close', 'update:close')])
        stamp = datetime.now(ZoneInfo('Asia/Tehran')).strftime('%Y-%m-%d %H:%M')
        notes = self.render_changes(additions, limit=2)
        notice = self.ui('update_notice', title=title, detail='\n' + detail if detail else '', _raw=('title', 'detail')) if title else ''
        if additions:
            notice += self.ui('update_changes', changes=notes, _raw=('changes',)) + '\n\n'
            if len(additions) > 2:
                notice += self.ui('update_changes_more', count=len(additions)) + '\n\n'
        shown_previous = self.setting('update_previous_version') or self.plain('update_none')
        text = self.ui('update_menu', version=version, checked=stamp, notice=notice, previous=shown_previous, _raw=('notice',))
        self.set('last_update_at', stamp)
        with self.db() as db:
            db.execute('UPDATE update_requests SET status=? WHERE id=?', ('failed' if failed else 'done', self.job['id']))
            current = db.execute('SELECT message_id FROM update_requests WHERE id=?', (self.job['id'],)).fetchone()
            self.job['message_id'] = current['message_id']
            key = 'update_menu.' + self.menu_scope()
            menu = db.execute('SELECT value FROM settings WHERE key=?', (key,)).fetchone()
            # A fresh menu opened after completion must remain the current menu.
            if menu is None or str(menu['value']) in ('0', str(self.job['message_id'])):
                db.execute('INSERT INTO settings(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value', (key, str(self.job['message_id'])))
                db.execute('INSERT INTO settings(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value', ('update_menu_hash.' + self.menu_scope(), ''))
        delivered = self.notify(text, buttons)
        pending = STATE / 'completion-pending.json'
        if delivered is False:
            if not failed and from_revision and self.job['id'] > 0 and previous.is_file():
                retained = json.loads(previous.read_text())
                if valid_recovery(retained):
                    atomic_json(pending, {'previous': retained, 'job': self.job, 'target': to_revision})
        else:
            pending.unlink(missing_ok=True)

    def refresh_completion(self):
        pending = STATE / 'completion-pending.json'
        if not pending.is_file():
            self.recover_unreported_completion()
        if not pending.is_file():
            return
        saved = json.loads(pending.read_text())
        if not valid_recovery(saved.get('previous')):
            pending.unlink()
            return
        with self.db() as db:
            job = db.execute("SELECT * FROM update_requests WHERE id=? AND status='done'", (saved['job']['id'],)).fetchone()
            active = db.execute("SELECT 1 FROM update_requests WHERE status IN ('queued','running')").fetchone()
            newer = db.execute('SELECT 1 FROM update_requests WHERE id>?', (saved['job']['id'],)).fetchone()
        if active:
            return
        installed = source_revision() if saved.get('target') else ''
        if (not job or newer or not self.authorized(dict(job))
                or saved.get('target') and installed and saved['target'] != installed):
            pending.unlink()
            return
        if job['action'] not in ('update', 'rollback'):
            pending.unlink()
            return
        self.job = dict(job)
        self.finish(self.ui('update_success' if job['action'] == 'update' else 'update_rollback_done'),
                    from_version=saved['previous'].get('version') if job['action'] == 'update' else None,
                    from_revision=saved['previous'].get('sha') if job['action'] == 'update' else None,
                    to_revision=source_revision())
        self.job = None

    def recover_unreported_completion(self):
        """Old agents delete their pending renderer refresh and cannot report same-version updates."""
        revision = release_revision()
        previous_file = STATE / 'previous.json'
        if not revision or self.setting('update_installed_revision') == revision or not previous_file.is_file():
            return
        try:
            previous = json.loads(previous_file.read_text())
            target = source_revision()
            if not valid_recovery(previous) or not target:
                return
            match = re.fullmatch(r'pre-update-\d{8}-\d{6}(?:-\d{6})?-(\d+)\.tar\.gz', previous.get('backup', ''))
            if not match or not all(self.runtime_release_revision(service) == revision for service in ('bot', 'worker')):
                return
            with self.db() as db:
                if db.execute("SELECT 1 FROM update_requests WHERE status IN ('queued','running')").fetchone():
                    return
                job = db.execute("SELECT * FROM update_requests WHERE id=? AND action='update' AND status='done'", (int(match[1]),)).fetchone()
            if match[1] == '0':
                self.record_changes(previous.get('version'), previous['sha'], target)  # Previous CLI update; no fabricated Telegram message.
            elif job and self.authorized(dict(job)):
                atomic_json(STATE / 'completion-pending.json', {'previous': previous, 'job': dict(job), 'target': target})
        except (OSError, ValueError, TypeError, sqlite3.Error):
            return

    def runtime_version(self, service):
        try:
            version = command('docker', 'compose', 'exec', '-T', service, 'cat', '/app/VERSION', timeout=15)
            return version if re.fullmatch(r'\d+\.\d+\.\d+', version) else ''
        except (RuntimeError, subprocess.TimeoutExpired):
            return ''

    def runtime_release_revision(self, service):
        try:
            entries = json.loads(command('docker', 'compose', 'exec', '-T', service, 'cat', '/app/CHANGELOG.json', timeout=15))
            return next(entry.get('revision', '') for entry in entries if entry.get('version') == release_notes()[0])
        except (OSError, RuntimeError, ValueError, TypeError, AttributeError, StopIteration, subprocess.TimeoutExpired):
            return ''

    def execute(self):
        recovery = STATE / 'recovery.json'
        if command('git', 'remote', 'get-url', 'origin').removesuffix('.git') != ORIGIN.removesuffix('.git'):
            raise RuntimeError('Unexpected repository origin')
        if command('git', 'status', '--porcelain'):
            raise RuntimeError('Local source changes prevent an update')
        current = command('git', 'rev-parse', 'HEAD')
        source_version = release_notes()[0]
        bot_version = self.runtime_version('bot')
        worker_version = self.runtime_version('worker')
        previous = {'sha': current, 'version': bot_version or source_version,
                    'image': 'ciro-downloader:backup-' + current[:12] + '-' + str(int(time.time()))}
        if self.job['action'] == 'rollback':
            saved = json.loads((STATE / 'previous.json').read_text())
            if not valid_recovery(saved):
                raise RuntimeError('No valid previous release')
            command('docker', 'image', 'inspect', saved['image'])
            command('git', 'cat-file', '-e', saved['sha'] + '^{commit}')
            target = saved['sha']
        else:
            self.progress('update_check', 10)
            self.run('git', 'fetch', '--prune', 'origin', 'main', timeout=120)
            target = command('git', 'rev-parse', 'origin/main')
            manifest = release_revision()
            current_runtime = not manifest or all(self.runtime_release_revision(service) == manifest for service in ('bot', 'worker'))
            if target == current and bot_version == source_version and worker_version == source_version and current_runtime:
                self.finish(self.ui('update_already_current'))
                return
            command('git', 'merge-base', '--is-ancestor', current, target)
        if not SHA.fullmatch(target):
            raise RuntimeError('Invalid release reference')
        if self.env.get('BOT_API_URL', '').rstrip('/') == 'http://ciro-telegram-api:8081':
            compose = command('git', 'show', target + ':compose.yaml')
            updater = command('git', 'show', target + ':scripts/updater.py')
            if 'telegram-api-data:/telegram-api:ro' not in compose or '127.0.0.1:18081' not in updater:
                raise RuntimeError('Target release does not support the configured local Telegram API')
        self.wait_idle()
        command('docker', 'tag', IMAGE, previous['image'])
        atomic_json(recovery, {'previous': previous, 'job': self.job, 'target': target})
        self.progress('update_backup', 35)
        self.run('docker', 'compose', 'stop', timeout=60)
        previous['backup'] = self.backup()
        atomic_json(recovery, {'previous': previous, 'job': self.job, 'target': target})
        if self.job['action'] == 'rollback':
            self.progress('update_restore', 65)
            command('git', 'checkout', '--detach', target)
            command('docker', 'tag', saved['image'], IMAGE)
        else:
            command('git', 'merge', '--ff-only', target)
            self.progress('update_build', 65)
            self.run('docker', 'compose', 'build', '--pull', timeout=1200)
        self.progress('update_starting', 85)
        self.set('poller_heartbeat', 0)
        self.set('worker_heartbeat', 0)
        self.run('docker', 'compose', 'up', '-d', '--no-build', '--force-recreate', timeout=180)
        self.progress('update_health', 95)
        self.verify()
        # Update the next invocation of this agent only after the new application is healthy.
        if PROJECT == Path('/opt/ciro-downloader') and (PROJECT / 'scripts/install-updater.sh').is_file():
            self.progress('update_agent', 98)
            self.run('bash', 'scripts/install-updater.sh', timeout=60)
        atomic_json(STATE / 'previous.json', previous)
        self.set('update_previous_version', previous['version'])
        recovery.unlink(missing_ok=True)
        cleanup_notice = ''
        try:
            self.progress('update_cleanup', 99)
            prune_previous()
        except Exception as error:
            detail = self.error_alert(error, stage='update_cleanup', warning=True)
            cleanup_notice = self.ui('update_cleanup_notice') + '\n' + detail
        self.finish(self.ui('update_rollback_done' if self.job['action'] == 'rollback' else 'update_success'),
                    detail=cleanup_notice,
                    from_version=previous['version'] if self.job['action'] == 'update' else None,
                    from_revision=previous['sha'] if self.job['action'] == 'update' else None,
                    to_revision=target)

    def tick(self):
        if Path('/var/lib/ciro-downloader-telegram-api/migration.json').exists():
            return
        # Recover a host reboot or killed updater before accepting another request.
        recovery = STATE / 'recovery.json'
        if recovery.is_file():
            saved = json.loads(recovery.read_text())
            self.job = saved['job']
            self.progress('update_restore', 90)
            self.restore(saved['previous'])
            recovery.unlink()
            self.finish(self.ui('update_recovered'), failed=True)
            return
        self.refresh_completion()
        if (STATE / 'previous.json').is_file() and not (STATE / 'retention-v2.json').is_file():
            try:
                prune_previous()
            except Exception as error:
                self.error_alert(error, stage='update_cleanup', warning=True)
            finally:
                # One migration attempt, not a repeating error history/notification loop.
                # A later successful update or explicit cleanup retries retention.
                atomic_json(STATE / 'retention-v2.json', {'enabled': True})
        self.beat()
        with self.db() as db:
            # A crash before recovery.json exists has not modified code or image.
            db.execute("UPDATE update_requests SET status='queued' WHERE status='running'")
            db.execute("INSERT INTO settings(key,value) VALUES('update_in_progress','0') ON CONFLICT(key) DO UPDATE SET value='0'")
        if not self.claim():
            return
        try:
            self.execute()
        except Exception as exc:
            detail = self.error_alert(exc)
            if recovery.is_file():
                try:
                    self.progress('update_restore', 95)
                    self.restore(json.loads(recovery.read_text())['previous'])
                    recovery.unlink()
                    self.finish(self.ui('update_recovered'), detail + '\n' + self.ui('update_recovered_detail'), failed=True)
                except Exception as recovery_error:
                    self.error_alert(recovery_error, stage='update_restore')
                    self.notify(self.ui('update_recovery_failed'))
                    raise
            else:
                self.finish(self.ui('update_failed'), detail + '\n' + self.ui('update_failed_detail'), failed=True)


class CliAgent(Agent):
    """The root manager uses exactly the same backup, image, health and recovery flow."""
    def notify(self, text, buttons=None):
        # Console-only stage information, never a persistent manager log.
        print('Update progress: ' + str(getattr(self, 'percent', 0)) + '%', flush=True)

    def finish(self, title, detail='', failed=False, from_version=None, from_revision=None, to_revision=None):
        self.set('update_in_progress', 0)
        if failed:
            self.error_alert()
            raise RuntimeError('Update failed; recovery was attempted')
        self.record_changes(from_version, from_revision, to_revision)
        (STATE / 'completion-pending.json').unlink(missing_ok=True)

    def cli_update(self):
        recovery = STATE / 'recovery.json'
        if recovery.exists():
            saved = json.loads(recovery.read_text())
            self.restore(saved['previous'])
            with self.db() as db:
                db.execute("UPDATE update_requests SET status='failed' WHERE id=?", (saved.get('job', {}).get('id', 0),))
            recovery.unlink()
        with self.db() as db:
            if db.execute("SELECT 1 FROM update_requests WHERE status IN ('queued','running')").fetchone():
                raise RuntimeError('A Telegram update request is pending')
        self.job = {'id': 0, 'action': 'update', 'chat_id': min(self.admins), 'message_id': 0}
        try:
            self.execute()
        except Exception as error:
            self.error_alert(error)
            if recovery.exists():
                self.restore(json.loads(recovery.read_text())['previous'])
                recovery.unlink()
            self.set('update_in_progress', 0)
            raise


def main():
    if os.geteuid() != 0:
        raise RuntimeError('Run the host updater as root')
    os.umask(0o077)
    STATE.mkdir(mode=0o700, parents=True, exist_ok=True)
    if '--cli-update' in sys.argv:
        # Manager holds this exact lock; a forged or unrelated inherited FD is insufficient.
        fd = int(os.environ.get('CIRO_MANAGER_LOCK_FD', '-1'))
        if fd < 0 or not os.path.samestat(os.fstat(fd), os.stat('/run/ciro-downloader-updater.lock')):
            raise RuntimeError('Manager operation lock required')
        CliAgent().cli_update()
        return
    with open('/run/ciro-downloader-updater.lock', 'w') as lock:
        try:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            return
        Agent().tick()


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        # Never log urllib exceptions: their URLs contain the bot token.
        pass
        sys.exit(1)

