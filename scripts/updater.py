#!/usr/bin/env python3
"""Root host agent. Telegram can request only update/rollback, never shell commands."""
import contextlib
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
import urllib.request
from datetime import datetime
from zoneinfo import ZoneInfo

PROJECT = Path('/opt/ciro-downloader')
STATE = Path('/var/lib/ciro-downloader-updater')
BACKUPS = Path('/var/backups/ciro-downloader')
ORIGIN = 'https://github.com/Alfred-1313/CiroDownloader.git'
IMAGE = 'ciro-downloader:local'
SHA = re.compile(r'^[0-9a-f]{40}$')


def command(*args, timeout=90):
    # No shell, interpolation, or arguments supplied by Telegram.
    result = subprocess.run(args, cwd=PROJECT, capture_output=True, timeout=timeout)
    if result.returncode:
        raise RuntimeError('Server operation failed: ' + args[0])
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
        changes = [str(item)[:240] for item in changes[:8]]
    except (OSError, ValueError, KeyError, StopIteration, TypeError):
        changes = ['فهرست تغییرات این نسخه ثبت نشده است.']
    return version, changes


def valid_recovery(value):
    return (isinstance(value, dict) and bool(SHA.fullmatch(value.get('sha', '')))
            and bool(re.fullmatch(r'ciro-downloader:backup-[0-9a-f]{12}-\d+', value.get('image', ''))))


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

    def beat(self):
        if time.time() - self.last_beat >= 8:
            self.set('updater_heartbeat', int(time.time()))
            self.last_beat = time.time()

    def api(self, method, params):
        url = self.env.get('BOT_API_URL', 'https://api.telegram.org').rstrip('/')
        url += '/bot' + self.env['BOT_TOKEN'] + '/' + method
        body = urllib.parse.urlencode({key: json.dumps(value, ensure_ascii=False) if isinstance(value, (dict, list)) else value for key, value in params.items()}).encode()
        with urllib.request.urlopen(urllib.request.Request(url, data=body), timeout=30) as response:
            result = json.load(response)
        if not result.get('ok'):
            raise RuntimeError('Telegram notification failed')
        return result['result']

    def notify(self, text, buttons=None):
        params = {'chat_id': self.job['chat_id'], 'message_id': self.job['message_id'],
                  'text': text, 'parse_mode': 'HTML', 'disable_web_page_preview': 'true',
                  'reply_markup': {'inline_keyboard': buttons or []}}
        try:
            self.api('editMessageText', params)
        except Exception:
            # A deleted progress message or temporary API outage must not abort recovery.
            print('Progress notification unavailable', flush=True)

    def progress(self, text):
        self.beat()
        self.notify('<b>🔄 به‌روزرسانی سیرو دانلودر</b>\n\n' + text + '\n\nلطفاً منتظر بمان؛ نتیجه در همین پیام نمایش داده می‌شود.')

    def run(self, *args, timeout=900):
        with tempfile.TemporaryFile() as output:
            proc = subprocess.Popen(args, cwd=PROJECT, stdout=output, stderr=subprocess.STDOUT)
            deadline = time.monotonic() + timeout
            try:
                while proc.poll() is None:
                    self.beat()
                    if time.monotonic() >= deadline:
                        raise TimeoutError('Server operation timed out')
                    time.sleep(1)
                if proc.returncode:
                    raise RuntimeError('Server operation failed: ' + args[0])
            finally:
                if proc.poll() is None:
                    proc.terminate()
                    try:
                        proc.wait(timeout=10)
                    except subprocess.TimeoutExpired:
                        proc.kill()
                        proc.wait()

    def claim(self):
        with self.db() as db:
            db.execute('BEGIN IMMEDIATE')
            # A bot process may have died between creating the request and sending its message.
            db.execute("UPDATE update_requests SET status='failed' WHERE status='queued' AND message_id=0 AND created<?", (int(time.time()) - 120,))
            row = db.execute("SELECT * FROM update_requests WHERE status='queued' AND message_id>0 ORDER BY id LIMIT 1").fetchone()
            if row:
                allowed = (row['user_id'] in self.admins and row['chat_id'] == row['user_id'] and row['action'] in ('update', 'rollback'))
                db.execute('UPDATE update_requests SET status=? WHERE id=?', ('running' if allowed else 'denied', row['id']))
                if allowed:
                    self.job = dict(row)
        return self.job is not None

    def backup(self):
        BACKUPS.mkdir(mode=0o700, parents=True, exist_ok=True)
        path = BACKUPS / ('pre-update-' + datetime.now().strftime('%Y%m%d-%H%M%S') + '-' + str(self.job['id']) + '.tar.gz')
        snapshot = self.data / '.updater-snapshot.sqlite'
        try:
            with self.db() as db:
                destination = sqlite3.connect(snapshot)
                try:
                    db.backup(destination)
                finally:
                    destination.close()
            with tarfile.open(path, 'w:gz') as archive:
                for item in PROJECT.iterdir():
                    if item.name not in ('.git', 'storage', '__pycache__'):
                        archive.add(item, arcname='source/' + item.name)
                archive.add(snapshot, arcname='data/zero.sqlite')
                for item in self.data.iterdir():
                    if item.name not in ('zero.sqlite', 'zero.sqlite-wal', 'zero.sqlite-shm', 'downloads', snapshot.name) and not item.name.endswith('.lock'):
                        archive.add(item, arcname='data/' + item.name)
            path.chmod(0o600)
            return path.name
        finally:
            snapshot.unlink(missing_ok=True)

    def wait_idle(self):
        self.set('update_in_progress', 1)
        self.progress('⏳ در انتظار پایان دانلود فعلی؛ درخواست جدید موقتاً پذیرفته نمی‌شود.')
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

    def finish(self, title, detail='', failed=False):
        self.set('update_in_progress', 0)
        version, changes = release_notes()
        previous = STATE / 'previous.json'
        buttons = [[{'text': '🔄 بررسی دوباره و به‌روزرسانی', 'callback_data': 'update:run'}]]
        if previous.is_file():
            buttons.append([{'text': '↩️ بازگشت به نسخهٔ قبلی', 'callback_data': 'update:rollback'}])
        buttons.append([{'text': 'پنل مدیریت', 'callback_data': 'admin'}])
        stamp = datetime.now(ZoneInfo('Asia/Tehran')).strftime('%Y-%m-%d %H:%M')
        notes = '\n'.join('• ' + html.escape(item) for item in changes)
        text = ('<b>' + title + '</b>\n\nنسخهٔ فعلی: <code>' + html.escape(version) + '</code>\n'
                + 'زمان بررسی: ' + stamp + ' (تهران)\n\n' + detail + '\n\n<b>تغییرات این نسخه</b>\n' + notes
                + '\n\nتنظیمات، کاربران و کوکی فعلی حفظ می‌شوند. پشتیبان روی سرور نگه‌داری می‌شود.')
        self.set('last_update_at', stamp)
        self.notify(text[:4000], buttons)
        with self.db() as db:
            db.execute('UPDATE update_requests SET status=? WHERE id=?', ('failed' if failed else 'done', self.job['id']))

    def execute(self):
        recovery = STATE / 'recovery.json'
        if command('git', 'remote', 'get-url', 'origin').removesuffix('.git') != ORIGIN.removesuffix('.git'):
            raise RuntimeError('Unexpected repository origin')
        if command('git', 'status', '--porcelain'):
            raise RuntimeError('Local source changes prevent an update')
        current = command('git', 'rev-parse', 'HEAD')
        previous = {'sha': current, 'version': release_notes()[0],
                    'image': 'ciro-downloader:backup-' + current[:12] + '-' + str(int(time.time()))}
        if self.job['action'] == 'rollback':
            saved = json.loads((STATE / 'previous.json').read_text())
            if not valid_recovery(saved):
                raise RuntimeError('No valid previous release')
            command('docker', 'image', 'inspect', saved['image'])
            command('git', 'cat-file', '-e', saved['sha'] + '^{commit}')
            target = saved['sha']
        else:
            self.progress('🔎 در حال بررسی آخرین نسخه در گیت‌هاب…')
            self.run('git', 'fetch', '--prune', 'origin', 'main', timeout=120)
            target = command('git', 'rev-parse', 'origin/main')
            if target == current:
                self.finish('✅ آخرین نسخه را داری', 'ربات به‌روز است؛ تغییر جدیدی در گیت‌هاب وجود ندارد.')
                return
            command('git', 'merge-base', '--is-ancestor', current, target)
        if not SHA.fullmatch(target):
            raise RuntimeError('Invalid release reference')
        self.wait_idle()
        command('docker', 'tag', IMAGE, previous['image'])
        atomic_json(recovery, {'previous': previous, 'job': self.job})
        self.progress('📦 در حال تهیهٔ پشتیبان از نسخه، تنظیمات و داده‌ها…')
        self.run('docker', 'compose', 'stop', timeout=60)
        previous['backup'] = self.backup()
        atomic_json(recovery, {'previous': previous, 'job': self.job})
        if self.job['action'] == 'rollback':
            self.progress('↩️ در حال بازگردانی نسخهٔ قبلی…')
            command('git', 'checkout', '--detach', target)
            command('docker', 'tag', saved['image'], IMAGE)
        else:
            command('git', 'merge', '--ff-only', target)
            self.progress('⚙️ در حال ساخت نسخهٔ جدید؛ این مرحله ممکن است چند دقیقه طول بکشد…')
            self.run('docker', 'compose', 'build', '--pull', timeout=1200)
        self.progress('🧪 در حال راه‌اندازی و بررسی سلامت ربات و پردازشگر…')
        self.set('poller_heartbeat', 0)
        self.set('worker_heartbeat', 0)
        self.run('docker', 'compose', 'up', '-d', '--no-build', '--force-recreate', timeout=180)
        self.verify()
        # Update the next invocation of this agent only after the new application is healthy.
        if (PROJECT / 'scripts/install-updater.sh').is_file():
            self.run('bash', 'scripts/install-updater.sh', timeout=60)
        atomic_json(STATE / 'previous.json', previous)
        self.set('update_previous_version', previous['version'])
        recovery.unlink(missing_ok=True)
        detail = 'نسخهٔ قبلی پشتیبان گرفته شد:\n<code>' + html.escape(previous['backup']) + '</code>'
        self.finish('✅ ' + ('نسخهٔ قبلی بازیابی شد' if self.job['action'] == 'rollback' else 'ربات به‌روزرسانی شد'), detail)

    def tick(self):
        # Recover a host reboot or killed updater before accepting another request.
        recovery = STATE / 'recovery.json'
        if recovery.is_file():
            saved = json.loads(recovery.read_text())
            self.job = saved['job']
            self.progress('♻️ عملیات قبلی ناتمام مانده؛ در حال بازیابی نسخهٔ سالم…')
            self.restore(saved['previous'])
            recovery.unlink()
            self.finish('♻️ نسخهٔ قبلی پس از توقف عملیات بازیابی شد', failed=True)
            return
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
            print('Update failed: ' + type(exc).__name__, flush=True)
            if recovery.is_file():
                try:
                    self.progress('♻️ به‌روزرسانی ناموفق بود؛ در حال بازیابی خودکار نسخهٔ قبلی…')
                    self.restore(json.loads(recovery.read_text())['previous'])
                    recovery.unlink()
                    self.finish('⚠️ ارتقا ناموفق بود؛ نسخهٔ قبلی بازیابی شد', 'جزئیات اجرایی در لاگ سرویس سرور ثبت شده است.', failed=True)
                except Exception:
                    self.notify('<b>⚠️ بازیابی خودکار کامل نشد.</b>\nمدیر باید سرویس ciro-downloader-updater و کانتینرها را روی سرور بررسی کند. پشتیبان محفوظ است.')
                    raise
            else:
                self.finish('⚠️ به‌روزرسانی انجام نشد', 'اتصال گیت‌هاب، تغییرات محلی، فضای دیسک یا سرویس سرور را بررسی کن. نسخهٔ اجراشده تغییر نکرده است.', failed=True)


def main():
    if os.geteuid() != 0:
        raise RuntimeError('Run the host updater as root')
    os.umask(0o077)
    STATE.mkdir(mode=0o700, parents=True, exist_ok=True)
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
        print('Host updater error: ' + type(error).__name__, file=sys.stderr)
        sys.exit(1)
