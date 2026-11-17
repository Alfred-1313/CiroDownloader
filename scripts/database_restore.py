"""Database-only restore, called under the host manager/updater operation lock."""
from contextlib import closing
from datetime import datetime, timezone
import hashlib
import hmac
import json
import os
from pathlib import Path
import re
import shutil
import sqlite3
import tempfile
import zipfile

MAX_ZIP = 20 * 1024 * 1024
MAX_DATABASE = 256 * 1024 * 1024
SERVICES = ('bot', 'worker', 'radio', 'adult-handoff')


def write_json(path, value):
    temporary = path.with_suffix('.partial')
    try:
        with temporary.open('w') as stream:
            os.chmod(temporary, 0o600)
            json.dump(value, stream)
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(temporary, path)
        descriptor = os.open(path.parent, os.O_DIRECTORY)
        try:
            os.fsync(descriptor)
        finally:
            os.close(descriptor)
    finally:
        temporary.unlink(missing_ok=True)


def unpack(path, folder, bot_token):
    if path.is_symlink() or not path.is_file() or path.stat().st_size > MAX_ZIP:
        raise ValueError('Invalid backup')
    destination = Path(folder) / 'zero.sqlite'
    with zipfile.ZipFile(path) as archive:
        if sorted(archive.namelist()) != ['manifest.json', 'zero.sqlite']:
            raise ValueError('Invalid backup members')
        if archive.getinfo('manifest.json').file_size > 4096 or not 100 <= archive.getinfo('zero.sqlite').file_size <= MAX_DATABASE:
            raise ValueError('Invalid backup size')
        manifest = json.loads(archive.read('manifest.json'))
        bot_id = bot_token.split(':', 1)[0]
        if manifest.get('format') != 'ciro-database-backup' or manifest.get('schema') != 1 or manifest.get('bot_id') != bot_id:
            raise ValueError('Invalid backup identity')
        signature = hmac.new(bot_token.encode(), ('ciro-database-backup:1:' + bot_id + ':' + str(manifest.get('sha256', ''))).encode(), hashlib.sha256).hexdigest()
        if not isinstance(manifest.get('signature'), str) or not hmac.compare_digest(signature, manifest['signature']):
            raise ValueError('Invalid backup signature')
        digest = hashlib.sha256()
        size = 0
        with archive.open('zero.sqlite') as source, destination.open('xb') as output:
            os.chmod(destination, 0o600)
            while block := source.read(1024 * 1024):
                size += len(block)
                if size > MAX_DATABASE:
                    raise ValueError('Invalid expanded size')
                digest.update(block)
                output.write(block)
        if digest.hexdigest() != manifest.get('sha256'):
            raise ValueError('Invalid backup digest')
    with destination.open('rb') as stream:
        if stream.read(16) != b'SQLite format 3\0':
            raise ValueError('Invalid database header')
    with closing(sqlite3.connect(destination.as_uri() + '?mode=ro', uri=True)) as db:
        # The signature is checked before opening SQLite. Keep native JSON expression indexes
        # readable on system SQLite versions that do not yet mark json_type() innocuous.
        objects = db.execute('SELECT type,name,sql FROM sqlite_master').fetchall()
        if any(kind not in ('table', 'index') or 'virtual table' in (sql or '').lower() for kind, _, sql in objects):
            raise ValueError('Unsupported database objects')
        required = {'settings': {'key', 'value'}, 'jobs': {'id', 'status', 'user_id', 'chat_id'}, 'users': {'id'}}
        for table, columns in required.items():
            if not columns.issubset({row[1] for row in db.execute('PRAGMA table_info(' + table + ')')}):
                raise ValueError('Invalid application database')
        if db.execute('PRAGMA quick_check(1)').fetchone()[0] != 'ok':
            raise ValueError('Damaged database')
    return destination


def sanitize(path, current, job):
    """Retain durable settings/subscriptions, invalidate work and Telegram controls from the past."""
    with closing(sqlite3.connect(path)) as db:
        tables = {row[0] for row in db.execute("SELECT name FROM sqlite_master WHERE type='table'")}
        offset = db.execute("SELECT value FROM settings WHERE key='offset'").fetchone()
        # Telegram can choose a new update-ID sequence after a long idle period.
        # Preserve the live cursor exactly; an old backup must neither rewind nor jump it forward.
        current['offset'] = str(int(current.get('offset', offset[0] if offset else 0)))
        for table in ('states', 'jobs', 'help_messages', 'music_menus', 'timed_deletes', 'shares',
                      'ig_check_panels', 'yt_check_panels', 'checker_menus', 'radio_panels', 'radio_sessions',
                      'radio_queue', 'radio_picks', 'update_requests', 'database_restore_files'):
            if table in tables:
                db.execute('DELETE FROM ' + table)
        for prefix in ('ig_check_', 'yt_check_'):
            if prefix + 'subs' not in tables:
                continue
            columns = {row[1] for row in db.execute('PRAGMA table_info(' + prefix + 'subs)')}
            fields = [field + '=0' for field in ('posts_ready', 'stories_ready', 'extra_ready') if field in columns]
            db.execute('UPDATE ' + prefix + 'subs SET ' + ','.join(fields + ['generation=generation+1']))
            db.execute("UPDATE " + prefix + "targets SET lease='',lease_until=0,next_check=0")
            db.execute("UPDATE " + prefix + "deliveries SET status=CASE WHEN status='sending' THEN 'uncertain' ELSE 'cancelled' END WHERE status IN ('pending','queued','retry','sending','offered')")
        if 'radio_stations' in tables:
            db.execute("UPDATE radio_stations SET live=0,live_since=0,command='',now=''")
        db.execute("DELETE FROM settings WHERE key GLOB 'database.menu.*' OR key GLOB 'database.backup.now.*' OR key GLOB 'update_menu*' OR key GLOB 'update_rollback_confirm*' OR key IN ('poller_heartbeat','worker_heartbeat','updater_heartbeat','update_in_progress')")
        current.update({'poller_heartbeat': '0', 'worker_heartbeat': '0', 'update_in_progress': '1', 'database.restore.active': '1'})
        db.executemany('INSERT INTO settings(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value', current.items())
        db.execute('''CREATE TABLE IF NOT EXISTS update_requests(id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,chat_id INTEGER NOT NULL,message_id INTEGER NOT NULL DEFAULT 0,
            action TEXT NOT NULL,status TEXT NOT NULL DEFAULT 'queued',created INTEGER NOT NULL,thread_id INTEGER NOT NULL DEFAULT 0)''')
        if 'thread_id' not in {row[1] for row in db.execute('PRAGMA table_info(update_requests)')}:
            db.execute('ALTER TABLE update_requests ADD COLUMN thread_id INTEGER NOT NULL DEFAULT 0')
        db.execute("INSERT INTO update_requests(id,user_id,chat_id,message_id,action,status,created,thread_id) VALUES(?,?,?,?,'database_restore','running',?,0)",
                   (job['id'], job['user_id'], job['chat_id'], job['message_id'], job['created']))
        db.commit()
        db.execute('PRAGMA wal_checkpoint(TRUNCATE)')
        db.execute('PRAGMA journal_mode=DELETE')


class Restore:
    def __init__(self, agent, state, backups):
        self.agent = agent
        self.state, self.backups = Path(state), Path(backups)
        self.journal = self.state / 'database-restore.json'

    def notify(self, key):
        # Never let an old database record rebind the original requested private result message.
        try:
            self.agent.api('editMessageText', {'chat_id': self.agent.job['chat_id'], 'message_id': self.agent.job['message_id'],
                           'text': self.agent.ui(key), 'parse_mode': 'HTML', 'reply_markup': {'inline_keyboard': []}})
        except Exception:
            pass

    def stop(self):
        self.agent.run('docker', 'compose', 'stop', *SERVICES, timeout=90)

    def start(self, services):
        if not services or any(service not in SERVICES for service in services):
            raise ValueError('Invalid restore services')
        self.agent.run('docker', 'compose', 'up', '-d', '--no-deps', '--no-build', *services, timeout=180)
        self.agent.verify()

    def replace(self, source):
        destination = self.agent.data / 'zero.sqlite'
        temporary = self.agent.data / '.database-restore.sqlite'
        if destination.is_symlink() or temporary.is_symlink():
            raise ValueError('Invalid database path')
        try:
            with source.open('rb') as src, temporary.open('wb') as dst:
                shutil.copyfileobj(src, dst, 1024 * 1024)
                dst.flush()
                os.fsync(dst.fileno())
            os.chmod(temporary, 0o600)
            os.chown(temporary, self.agent.uid, -1)
            # All container writers are stopped and all host connections closed at this point.
            for suffix in ('-wal', '-shm'):
                destination.with_name(destination.name + suffix).unlink(missing_ok=True)
            os.replace(temporary, destination)
            descriptor = os.open(destination.parent, os.O_DIRECTORY)
            try:
                os.fsync(descriptor)
            finally:
                os.close(descriptor)
        finally:
            temporary.unlink(missing_ok=True)

    def finish(self, key, failed=False):
        with self.agent.db() as db:
            db.execute("UPDATE update_requests SET status=? WHERE id=? AND action='database_restore'", ('failed' if failed else 'done', self.agent.job['id']))
            db.execute("INSERT INTO settings(key,value) VALUES('update_in_progress','0') ON CONFLICT(key) DO UPDATE SET value='0'")
            db.execute("INSERT INTO settings(key,value) VALUES('database.restore.active','0') ON CONFLICT(key) DO UPDATE SET value='0'")
            db.execute("INSERT INTO settings(key,value) VALUES('updater_heartbeat',?) ON CONFLICT(key) DO UPDATE SET value=excluded.value", (str(int(datetime.now(timezone.utc).timestamp())),))
            if db.execute("SELECT 1 FROM sqlite_master WHERE name='database_restore_files' AND type='table'").fetchone():
                db.execute('DELETE FROM database_restore_files WHERE request_id=?', (self.agent.job['id'],))
        self.notify(key)

    def recover(self):
        if not self.journal.exists():
            return False
        record = json.loads(self.journal.read_text())
        self.agent.job = record['job']
        name = record['backup']
        if not re.fullmatch(r'database-before-[0-9]{8}-[0-9]{6}-[a-f0-9]{32}\.sqlite', name):
            raise ValueError('Invalid recovery path')
        backup = self.backups / name
        if backup.is_symlink() or not backup.is_file():
            raise ValueError('Missing recovery database')
        if record.get('phase') == 'committed':
            self.finish('database_restore_done')
        else:
            self.stop()
            self.replace(backup)
            self.agent.set('poller_heartbeat', 0)
            self.agent.set('worker_heartbeat', 0)
            self.agent.set('update_in_progress', 1)
            self.agent.set('database.restore.active', 1)
            self.start(record['services'])
            self.finish('database_restore_recovered', failed=True)
        self.journal.unlink()
        self.cleanup_input(record.get('token', ''))
        return True

    def cleanup_input(self, token):
        if re.fullmatch('[a-f0-9]{32}', token):
            path = self.agent.data / 'database-backups' / ('import-' + token + '.zip')
            if not path.parent.is_symlink():
                path.unlink(missing_ok=True)

    def run(self):
        token = ''
        stopped = False
        services = None
        try:
            job = self.agent.job
            with self.agent.db() as db:
                if not self.agent.authorized(job, db) or job['chat_id'] != job['user_id']:
                    raise ValueError('Unauthorized restore')
                row = db.execute('SELECT token,sha256 FROM database_restore_files WHERE request_id=?', (job['id'],)).fetchone()
                if not row or not re.fullmatch('[a-f0-9]{32}', row['token']):
                    raise ValueError('Invalid restore request')
                token, expected = row['token'], row['sha256']
            folder = self.agent.data / 'database-backups'
            archive = folder / ('import-' + token + '.zip')
            if folder.is_symlink() or archive.is_symlink() or archive.stat().st_size > MAX_ZIP:
                raise ValueError('Invalid restore archive')
            if hashlib.sha256(archive.read_bytes()).hexdigest() != expected:
                raise ValueError('Changed restore archive')
            self.backups.mkdir(mode=0o700, parents=True, exist_ok=True)
            with tempfile.TemporaryDirectory(prefix='.database-import-', dir=self.backups) as temporary:
                source = unpack(archive, temporary, self.agent.env['BOT_TOKEN'])
                self.notify('database_restore_working')
                self.agent.set('update_in_progress', 1)
                services = self.agent.database_services()
                stopped = True
                self.stop()
                backup = self.backups / ('database-before-' + datetime.now(timezone.utc).strftime('%Y%m%d-%H%M%S') + '-' + token + '.sqlite')
                with self.agent.db() as db:
                    with closing(sqlite3.connect(backup)) as destination:
                        db.backup(destination)
                    current = dict(db.execute("SELECT key,value FROM settings WHERE key IN ('offset','bot_id','bot_username','update_installed_revision','update_new_changes','update_previous_version','last_update_at','database.backup.enabled','database.backup.chat','database.backup.minutes','database.backup.next')"))
                os.chmod(backup, 0o600)
                record = {'job': job, 'backup': backup.name, 'services': services, 'token': token, 'phase': 'prepared'}
                write_json(self.journal, record)
                sanitize(source, current, job)
                self.replace(source)
                self.start(services)
                record['phase'] = 'committed'
                write_json(self.journal, record)
                self.finish('database_restore_done')
                self.journal.unlink()
                # Keep the previous database for recovery, not an unbounded series of automatic copies.
                try:
                    for old in self.backups.glob('database-before-*.sqlite'):
                        if old != backup and re.fullmatch(r'database-before-[0-9]{8}-[0-9]{6}-[a-f0-9]{32}\.sqlite', old.name) and not old.is_symlink():
                            old.unlink()
                except OSError:
                    pass  # Optional retention cannot turn a verified restore into a failed result.
        except Exception:
            if self.journal.exists():
                try:
                    self.recover()
                except Exception:
                    self.notify('database_restore_recovery_pending')
                    return
            else:
                # No replacement can have happened before the durable recovery record.
                if stopped:
                    try:
                        self.start(services)
                    except Exception:
                        pass
                self.finish('database_restore_failed', failed=True)
        finally:
            if not self.journal.exists():
                self.cleanup_input(token)
