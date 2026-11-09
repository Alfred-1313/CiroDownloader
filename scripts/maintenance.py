#!/usr/bin/env python3
"""Private backups and transactional settings for Ciro Downloader only."""
import argparse
from contextlib import contextmanager, closing
from datetime import datetime, timezone
import io
import json
import os
from pathlib import Path, PurePosixPath
import re
import signal
import sqlite3
import subprocess
import sys
import tarfile
import tempfile
from urllib.parse import urlsplit

PROJECT = Path(__file__).resolve().parents[1]
BACKUPS = Path('/var/backups/ciro-downloader')
MAX_BYTES = 512 * 1024 * 1024
FIELDS = {'BOT_TOKEN', 'ADMIN_IDS', 'YOUTUBE_PROXY', 'GALLERY_PROXY'}
ENV_KEYS = FIELDS | {'LOCAL_API_SHARED_STORAGE', 'BOT_API_URL', 'MAX_FILE_BYTES', 'STORAGE_DIR', 'YT_DLP_BIN', 'YOUTUBE_COOKIES_FILE', 'WORKER_CONCURRENCY', 'GALLERY_DL_BIN', 'INSTAGRAM_COOKIES_FILE', 'YOUTUBE_POT_URL', 'YOUTUBE_POT_PLUGIN', 'TIKTOK_PREFER_H264'}


class MaintenanceError(RuntimeError):
    pass


def environment(content):
    values = {}
    for line in content.splitlines():
        if not line.strip() or line.lstrip().startswith('#'):
            continue
        match = re.fullmatch(r'([A-Z_]+)=(.*)', line.strip())
        if not match or match[1] not in ENV_KEYS or match[1] in values:
            raise MaintenanceError('Unsupported or duplicate configuration field.')
        values[match[1]] = match[2].strip(' \t\"\'')
    validate('BOT_TOKEN', values.get('BOT_TOKEN', ''))
    validate('ADMIN_IDS', values.get('ADMIN_IDS', ''))
    validate('YOUTUBE_PROXY', values.get('YOUTUBE_PROXY', ''))
    if not re.fullmatch(r'[1-8]', values.get('WORKER_CONCURRENCY') or '3'):
        raise MaintenanceError('WORKER_CONCURRENCY must be between 1 and 8.')
    if values.get('LOCAL_API_SHARED_STORAGE', '0') not in ('0', '1'):
        raise MaintenanceError('LOCAL_API_SHARED_STORAGE must be 0 or 1.')
    if values.get('STORAGE_DIR', '/app/storage') != '/app/storage':
        raise MaintenanceError('Backups require the managed /app/storage volume.')
    try:
        api = values.get('BOT_API_URL', 'https://api.telegram.org')
        parsed = urlsplit(api)
        limit = int(values.get('MAX_FILE_BYTES', '49000000'))
        if parsed.scheme not in ('http', 'https') or not parsed.hostname or not 1000000 <= limit <= (49000000 if api == 'https://api.telegram.org' else 2000000000):
            raise ValueError()
    except ValueError:
        raise MaintenanceError('The API address or file limit is invalid.') from None
    return values


def validate(field, value):
    if '\n' in value or '\r' in value or '\x00' in value:
        raise MaintenanceError('Configuration values must be single-line text.')
    if field == 'BOT_TOKEN' and not re.fullmatch(r'\d+:[A-Za-z0-9_-]+', value):
        raise MaintenanceError('Enter a valid Telegram bot token.')
    if field == 'ADMIN_IDS' and not re.fullmatch(r'[1-9]\d*(,[1-9]\d*)*', value):
        raise MaintenanceError('Enter numeric administrator IDs separated by commas.')
    if field in ('YOUTUBE_PROXY', 'GALLERY_PROXY') and value:
        try:
            parsed = urlsplit(value)
            valid = parsed.scheme in ('http', 'https', 'socks5', 'socks5h') and parsed.hostname and parsed.port and not re.search(r'[\s\x00-\x1f]', value)
        except ValueError:
            valid = False
        if not valid:
            raise MaintenanceError('Enter an HTTP(S) or SOCKS5 proxy with a host and port.')


def atomic(path, content):
    if path.is_symlink():
        raise MaintenanceError('Refusing to replace a configuration symlink.')
    handle, name = tempfile.mkstemp(prefix='.ciro-write-', dir=path.parent)
    try:
        with os.fdopen(handle, 'wb') as output:
            os.chmod(name, 0o600)
            output.write(content)
            output.flush()
            os.fsync(output.fileno())
        os.replace(name, path)
    finally:
        Path(name).unlink(missing_ok=True)


def safe_name(name):
    path = PurePosixPath(name)
    if path.is_absolute() or '..' in path.parts or '\\' in name or '\x00' in name:
        raise MaintenanceError('Unsafe backup path.')
    return path


class Maintenance:
    def __init__(self, project=PROJECT, backups=BACKUPS):
        self.project, self.backups = Path(project), Path(backups)

    def command(self, *args, input=None, output=None, timeout=180):
        result = subprocess.run(args, cwd=self.project, input=input, stdout=output or subprocess.PIPE,
                                stderr=subprocess.PIPE, timeout=timeout)
        if result.returncode:
            # Docker/HTTP error bodies can include credentials; keep them out of output.
            raise MaintenanceError('The ' + args[0] + ' operation failed. Check the service logs.')
        return (result.stdout or b'').decode('utf-8', errors='replace').strip()

    def check_project(self):
        config = json.loads(self.command('docker', 'compose', 'config', '--format', 'json'))
        mounts = config.get('services', {}).get('worker', {}).get('volumes', [])
        matching = [m for m in mounts if m.get('target') == '/app/storage']
        if config.get('name') != 'ciro-downloader' or len(matching) != 1:
            raise MaintenanceError('This is not the managed Ciro Downloader project.')
        mount = matching[0]
        name = config.get('volumes', {}).get(mount.get('source'), {}).get('name')
        if mount.get('type') != 'volume' or name != 'ciro-downloader_data' or any(m.get('target', '').startswith('/app/storage/') for m in mounts):
            raise MaintenanceError('The data volume does not belong to this installation.')

    def running(self):
        names = self.command('docker', 'compose', 'ps', '--status', 'running', '--services').splitlines()
        return [name for name in names if name in ('bot', 'worker', 'radio')]

    def start(self, services, recreate=False):
        if not services:
            return
        if recreate:
            self.command('docker', 'compose', 'up', '-d', '--no-deps', '--force-recreate', *services)
        else:
            self.command('docker', 'compose', 'start', *services)

    def private_directory(self):
        if self.backups.is_symlink():
            raise MaintenanceError('The private backup directory cannot be a symlink.')
        self.backups.mkdir(mode=0o700, parents=True, exist_ok=True)
        self.backups.chmod(0o700)

    def filename(self, kind, suffix):
        stamp = datetime.now(timezone.utc).strftime('%Y%m%d-%H%M%S-%f')
        return self.backups / ('ciro-' + kind + '-' + stamp + suffix)

    def backup(self, kind='backup'):
        self.check_project()
        env = (self.project / '.env').read_bytes()
        environment(env.decode('utf-8'))
        self.private_directory()
        path = self.filename(kind, '.tar.gz')
        services = self.running()
        stopped = False
        try:
            # A stopped SQLite writer gives a consistent DB + WAL snapshot.
            stopped = True
            self.command('docker', 'compose', 'stop')
            with tempfile.TemporaryDirectory(prefix='.snapshot-', dir=self.backups) as folder:
                data = Path(folder) / 'data.tar'
                with data.open('xb') as output:
                    self.command('docker', 'compose', 'run', '--rm', '--no-deps', '-T', '--entrypoint', 'tar', 'worker',
                                 '-cf', '-', '--exclude=./downloads', '--exclude=./instagram-pages', '--exclude=./pinterest-pages', '--exclude=./tiktok-pages', '--exclude=./twitter-pages', '--exclude=./music-batches', '--exclude=./.youtube-blocked', '--exclude=./.youtube-ok', '--exclude=./yt-cache', '--exclude=./radio', '--exclude=./.keepalive-*', '--exclude=./*.lock', '--exclude=./.cookie-check-*',
                                 '-C', '/app/storage', '.', output=output)
                if data.stat().st_size > MAX_BYTES:
                    raise MaintenanceError('The backup is larger than the supported 512 MB data limit.')
                manifest = json.dumps({'format': 'ciro-downloader-backup', 'schema': 1,
                    'version': (self.project / 'VERSION').read_text(encoding='utf-8').strip(),
                    'created': datetime.now(timezone.utc).isoformat()}).encode()
                with path.open('xb') as raw:
                    os.chmod(path, 0o600)
                    with tarfile.open(fileobj=raw, mode='w:gz') as archive:
                        for name, content in [('manifest.json', manifest), ('config.env', env)]:
                            info = tarfile.TarInfo(name); info.size = len(content); info.mode = 0o600
                            archive.addfile(info, io.BytesIO(content))
                        archive.add(data, arcname='data.tar', recursive=False)
            return path
        except BaseException:
            path.unlink(missing_ok=True)
            raise
        finally:
            if stopped:
                self.start(services)

    def unpack(self, path, folder):
        if Path(path).stat().st_size > MAX_BYTES:
            raise MaintenanceError('The backup archive is too large.')
        folder = Path(folder); total = 0
        with tarfile.open(path, 'r:gz') as archive:
            seen = set()
            for member in archive:
                safe_name(member.name)
                if member.name not in ('manifest.json', 'config.env', 'data.tar') or not member.isfile() or member.name in seen:
                    raise MaintenanceError('Unsupported backup contents.')
                seen.add(member.name); total += member.size
                if total > MAX_BYTES or (member.name != 'data.tar' and member.size > 65536):
                    raise MaintenanceError('The expanded backup exceeds its size limit.')
                with (folder / member.name).open('xb') as output:
                    os.chmod(folder / member.name, 0o600)
                    output.write(archive.extractfile(member).read())
        if seen != {'manifest.json', 'config.env', 'data.tar'}:
            raise MaintenanceError('The backup is incomplete.')
        manifest = json.loads((folder / 'manifest.json').read_text(encoding='utf-8'))
        if manifest.get('format') != 'ciro-downloader-backup' or manifest.get('schema') != 1:
            raise MaintenanceError('Choose a Ciro Downloader data backup.')
        environment((folder / 'config.env').read_text(encoding='utf-8'))
        data = folder / 'data'; data.mkdir(mode=0o700)
        with tarfile.open(folder / 'data.tar', 'r:') as archive:
            seen = set(); total = 0
            for member in archive:
                name = safe_name(member.name)
                if name == PurePosixPath('.') and member.isdir():
                    continue
                if name in seen or len(seen) >= 4096 or not (member.isfile() or member.isdir()):
                    raise MaintenanceError('Unsafe or duplicate data backup member.')
                seen.add(name); total += member.size
                if total > MAX_BYTES:
                    raise MaintenanceError('The expanded data exceeds its size limit.')
                destination = data.joinpath(*name.parts)
                destination.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
                if member.isdir():
                    destination.mkdir(mode=0o700, exist_ok=True)
                else:
                    with destination.open('xb') as output:
                        os.chmod(destination, 0o600)
                        output.write(archive.extractfile(member).read())
        database = data / 'zero.sqlite'
        if not database.is_file():
            raise MaintenanceError('The backup has no application database.')
        with closing(sqlite3.connect(database)) as connection:
            if connection.execute('PRAGMA quick_check').fetchone()[0] != 'ok':
                raise MaintenanceError('The backup database is damaged.')
            tables = {row[0] for row in connection.execute("SELECT name FROM sqlite_master WHERE type='table'")}
            if not {'settings', 'jobs'}.issubset(tables):
                raise MaintenanceError('This database is not from Ciro Downloader.')
            connection.execute("UPDATE settings SET value='0' WHERE key='update_in_progress'")
            connection.execute("UPDATE jobs SET status='failed' WHERE status IN ('queued','running','choosing','confirming')")
            if 'update_requests' in tables:
                connection.execute("UPDATE update_requests SET status='failed' WHERE status IN ('queued','running')")
            connection.commit()
            connection.execute('PRAGMA wal_checkpoint(TRUNCATE)')
        return data

    def replace(self, folder):
        data = Path(folder) / 'data'
        transfer = io.BytesIO()
        with tarfile.open(fileobj=transfer, mode='w') as archive:
            for item in sorted(data.rglob('*')):
                if item.name.endswith(('-wal', '-shm', '.lock')):
                    continue
                archive.add(item, arcname=str(item.relative_to(data)), recursive=False)
        # The volume was verified above. No user-supplied value is shell code.
        script = 'find /app/storage -mindepth 1 -maxdepth 1 -exec rm -rf -- {} + && tar -xf - --no-same-owner --no-same-permissions -C /app/storage'
        self.command('docker', 'compose', 'run', '--rm', '--no-deps', '-T', '--entrypoint', 'sh', 'worker', '-c', script,
                     input=transfer.getvalue())
        atomic(self.project / '.env', (Path(folder) / 'config.env').read_bytes())

    def restore(self, path):
        self.check_project(); self.private_directory()
        with tempfile.TemporaryDirectory(prefix='.restore-', dir=self.backups) as folder:
            self.unpack(path, folder)  # Validate all paths and DB before pausing anything.
            current_api = environment((self.project / '.env').read_text(encoding='utf-8')).get('BOT_API_URL', 'https://api.telegram.org').rstrip('/')
            backup_api = environment((Path(folder) / 'config.env').read_text(encoding='utf-8')).get('BOT_API_URL', 'https://api.telegram.org').rstrip('/')
            if current_api != backup_api:
                raise MaintenanceError('This backup uses a different Telegram API server. Migrate the bot first before restoring it.')
            recovery = self.backup('pre-restore')
            services = self.running()
            try:
                self.command('docker', 'compose', 'stop')
                self.replace(folder)
                self.start(services, recreate=True)
            except BaseException as original:
                try:
                    # A failed recreation may already have started a DB writer.
                    # Never replace recovery data until every service is stopped.
                    self.command('docker', 'compose', 'stop')
                    with tempfile.TemporaryDirectory(prefix='.rollback-', dir=self.backups) as old:
                        self.unpack(recovery, old); self.replace(old)
                    self.start(services, recreate=True)
                except BaseException:
                    raise MaintenanceError('Restore and automatic rollback failed. Recovery backup: ' + str(recovery)) from None
                raise MaintenanceError('Restore failed; previous data and settings were restored. Recovery backup: ' + str(recovery)) from original
        return recovery

    def configure(self, field, value):
        if field not in FIELDS:
            raise MaintenanceError('Unsupported setting.')
        validate(field, value); self.check_project(); self.private_directory()
        path = self.project / '.env'; previous = path.read_bytes()
        values = environment(previous.decode('utf-8')); values[field] = value
        updated = ''.join(key + '=' + item + '\n' for key, item in values.items()).encode()
        saved = self.filename('config', '.env'); atomic(saved, previous)
        services = self.running()
        try:
            atomic(path, updated)
            self.command('docker', 'compose', 'config', '--quiet')
            if services and field == 'BOT_TOKEN':
                self.command('docker', 'compose', 'run', '--rm', '--no-deps', '-T', 'bot', 'php', '-r',
                             'require "src/Core.php"; $api=new Zero\\Telegram(Zero\\Config::load()); $api->call("getMe"); if(!empty($api->call("getWebhookInfo")["url"])) throw new RuntimeException("A webhook is configured.");')
            self.start(services, recreate=True)
        except BaseException:
            atomic(path, previous)
            try:
                self.start(services, recreate=True)
            except BaseException:
                raise MaintenanceError('Settings were restored, but services need attention. Use Services > Start.') from None
            raise MaintenanceError('The new setting could not be applied. Previous configuration restored.') from None
        return saved


@contextmanager
def operation_lock():
    import fcntl
    lock_path = Path('/run/ciro-downloader-updater.lock')
    supplied = os.environ.get('CIRO_MANAGER_LOCK_FD', '')
    if supplied.isdigit():
        fd = int(supplied)
        current, expected = os.fstat(fd), lock_path.stat()
        if (current.st_dev, current.st_ino) != (expected.st_dev, expected.st_ino):
            raise MaintenanceError('Invalid manager operation lock.')
        try:
            fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            raise MaintenanceError('Another update or maintenance operation is running.') from None
        yield
    else:
        with lock_path.open('a') as handle:
            try:
                fcntl.flock(handle, fcntl.LOCK_EX | fcntl.LOCK_NB)
            except BlockingIOError:
                raise MaintenanceError('Another update or maintenance operation is running.') from None
            yield


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('action', choices=['backup', 'restore', 'configure', 'list', 'summary'])
    parser.add_argument('value', nargs='?')
    parser.add_argument('--input-file', type=Path)
    args = parser.parse_args()
    if os.geteuid() != 0:
        raise MaintenanceError('Run maintenance as root.')
    os.umask(0o077)
    app = Maintenance()
    if args.action == 'list':
        for path in sorted(BACKUPS.glob('ciro-*.tar.gz'), reverse=True):
            print(str(path) + '  (' + str(path.stat().st_size // 1024) + ' KB)')
        return
    if args.action == 'summary':
        values = environment((PROJECT / '.env').read_text(encoding='utf-8'))
        print('Token: configured\nAdministrators: ' + str(len(values['ADMIN_IDS'].split(','))) + '\nYouTube proxy: ' + ('configured' if values.get('YOUTUBE_PROXY') else 'disabled') + '\nSocial media proxy: ' + ('configured' if values.get('GALLERY_PROXY') else 'disabled'))
        return
    with operation_lock():
        if args.action == 'backup':
            print('Backup saved: ' + str(app.backup()))
        elif args.action == 'restore':
            if not args.value:
                raise MaintenanceError('Choose an absolute backup file path.')
            print('Restore complete. Previous state saved: ' + str(app.restore(Path(args.value))))
        else:
            if not args.input_file or not args.value:
                raise MaintenanceError('A private input file and field are required.')
            value = args.input_file.read_text(encoding='utf-8').strip()
            print('Setting saved. Previous configuration: ' + str(app.configure(args.value, value)))


if __name__ == '__main__':
    def interrupted(*_):
        raise KeyboardInterrupt()
    signal.signal(signal.SIGTERM, interrupted)
    try:
        main()
    except KeyboardInterrupt:
        print('Maintenance interrupted. Check service status before retrying.', file=sys.stderr)
        sys.exit(130)
    except (MaintenanceError, OSError, ValueError, sqlite3.Error, tarfile.TarError, subprocess.SubprocessError) as error:
        print(str(error) if isinstance(error, MaintenanceError) else 'Maintenance could not complete (' + type(error).__name__ + ').', file=sys.stderr)
        sys.exit(1)

