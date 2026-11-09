#!/usr/bin/env python3
"""Switch this installation from the hosted Bot API to a private local server."""
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import time
import urllib.error
import urllib.request

from maintenance import Maintenance, MaintenanceError, atomic, environment, operation_lock

PROJECT = Path(__file__).resolve().parents[1]
PRIVATE = Path('/var/lib/ciro-downloader-telegram-api')
MARKER = PRIVATE / 'migration.json'
LOCAL = 'http://ciro-telegram-api:8081'
CLOUD = 'https://api.telegram.org'


def run(*args, timeout=1800, stream=False):
    try:
        result = subprocess.run(args, cwd=PROJECT, stdout=None if stream else subprocess.PIPE,
                                stderr=subprocess.STDOUT if stream else subprocess.PIPE, timeout=timeout)
    except subprocess.TimeoutExpired:
        raise MaintenanceError(f'A Docker operation timed out after {timeout} seconds. Check the private step log before retrying.') from None
    if result.returncode:
        raise MaintenanceError('A Docker operation failed. Check local Bot API service status and disk space.')
    return result.stdout.decode(errors='replace').strip() if result.stdout is not None else ''


def request(base, token, method):
    # Never log a URL or the exception: both can contain the bot token.
    try:
        with urllib.request.urlopen(urllib.request.Request(base + '/bot' + token + '/' + method, data=b''), timeout=90) as response:
            data = json.load(response)
        return data.get('ok') is True
    except (OSError, ValueError, urllib.error.URLError):
        return False


def marker(phase):
    atomic(MARKER, json.dumps({'phase': phase}).encode())


def queue_pause():
    # Block new jobs atomically with testing for a running one. If busy, leave it untouched.
    code = '''import sqlite3
db=sqlite3.connect('/app/storage/zero.sqlite',timeout=10,isolation_level=None)
db.execute('BEGIN IMMEDIATE')
busy=db.execute("SELECT 1 FROM jobs WHERE status IN ('running','confirming') LIMIT 1").fetchone()
if busy: db.execute('ROLLBACK'); raise SystemExit(2)
db.execute("INSERT INTO settings(key,value) VALUES('update_in_progress','1') ON CONFLICT(key) DO UPDATE SET value='1'")
db.execute('COMMIT')'''
    try:
        run('docker', 'compose', 'run', '--rm', '--no-deps', '-T', 'worker', 'python3', '-c', code)
    except MaintenanceError:
        raise MaintenanceError('A download may be running. Wait for it to finish, then retry setup.') from None


def queue_resume():
    code = '''import sqlite3
db=sqlite3.connect('/app/storage/zero.sqlite',timeout=10)
db.execute("INSERT INTO settings(key,value) VALUES('update_in_progress','0') ON CONFLICT(key) DO UPDATE SET value='0'")
db.commit()'''
    run('docker', 'compose', 'run', '--rm', '--no-deps', '-T', 'worker', 'python3', '-c', code)


def install(api_id, api_hash):
    if not re.fullmatch(r'[1-9]\d{0,15}', api_id) or not re.fullmatch(r'[0-9a-fA-F]{32}', api_hash):
        raise MaintenanceError('API ID or API hash is invalid.')
    if PRIVATE.is_symlink():
        raise MaintenanceError('Private API directory cannot be a symlink.')
    PRIVATE.mkdir(mode=0o700, parents=True, exist_ok=True)
    PRIVATE.chmod(0o700)
    app = Maintenance()
    app.check_project()
    path = PROJECT / '.env'
    values = environment(path.read_text(encoding='utf-8'))
    endpoint = values.get('BOT_API_URL', CLOUD).rstrip('/')
    phase = json.loads(MARKER.read_text()).get('phase') if MARKER.exists() else ''
    if endpoint not in (CLOUD, LOCAL) or (endpoint == LOCAL and not phase):
        raise MaintenanceError('A different or already enabled Bot API is configured; no migration was performed.')
    if not phase and set(app.running()) - {'radio'} != {'bot', 'worker'}:
        raise MaintenanceError('Start both bot and worker before enabling large uploads.')
    atomic(PRIVATE / 'api.env', ('TELEGRAM_API_ID=' + api_id + '\nTELEGRAM_API_HASH=' + api_hash + '\n').encode())
    api_compose = ('docker', 'compose', '-f', 'compose.telegram-api.yaml')
    print('Building the official Telegram Bot API server; this can take several minutes.', flush=True)
    # Let the manager's private step log receive compiler output as it happens.
    run(*api_compose, '--progress', 'plain', 'build', timeout=7200, stream=True)
    print('Starting the local Telegram Bot API service.', flush=True)
    run(*api_compose, 'up', '-d', '--no-build', timeout=180, stream=True)
    for _ in range(60):
        try:
            urllib.request.urlopen('http://127.0.0.1:18081/', timeout=2).close()
            break
        except urllib.error.HTTPError:
            break
        except OSError:
            time.sleep(1)
    else:
        raise MaintenanceError('The local Bot API did not start. The current bot configuration was kept.')
    if not phase:
        services = app.running()
        if set(services) - {'radio'} != {'bot', 'worker'}:
            raise MaintenanceError('Start both bot and worker before enabling large uploads.')
        marker('preparing')
        phase = 'preparing'
    if phase == 'preparing':
        print('Pausing bot and worker for migration.', flush=True)
        queue_pause()
        try:
            run('docker', 'compose', 'stop', 'bot', 'worker')
        except BaseException:
            queue_resume()
            raise
        # A retry after interruption knows it must finish migration before starting the bot.
        marker('pending_logout')
        phase = 'pending_logout'
    if endpoint == CLOUD:
        values['BOT_API_URL'] = LOCAL
        values['MAX_FILE_BYTES'] = '1990000000'
        atomic(path, ''.join(key + '=' + value + '\n' for key, value in values.items()).encode())
    if phase == 'pending_logout':
        print('Logging the bot out of the hosted Telegram API.', flush=True)
        if not request(CLOUD, values['BOT_TOKEN'], 'logOut'):
            raise MaintenanceError('Cloud logout was not confirmed. Bot is paused; retry this setup. Do not start it manually.')
        marker('logged_out')
        phase = 'logged_out'
    if phase == 'logged_out':
        print('Verifying login through the local Telegram API.', flush=True)
        if not request('http://127.0.0.1:18081', values['BOT_TOKEN'], 'getMe'):
            raise MaintenanceError('Local Telegram login failed. Bot is paused; verify API credentials and retry setup.')
        marker('authenticated')
        phase = 'authenticated'
    if phase != 'authenticated':
        raise MaintenanceError('Invalid migration state; contact support before starting the bot.')
    # A previous attempt may have restarted the services before being interrupted.
    # Do not recreate them while a resumed download is running.
    queue_pause()
    print('Restarting bot and worker with the local Telegram API.', flush=True)
    run('docker', 'compose', 'up', '-d', '--no-deps', '--force-recreate', 'bot', 'worker')
    queue_resume()
    MARKER.unlink(missing_ok=True)
    print('Local Bot API enabled. Upload limit: 1990 MB; allow sufficient free disk space.')


def main():
    if os.geteuid() != 0 or len(sys.argv) != 2:
        raise MaintenanceError('Run setup as root with a private input file.')
    os.umask(0o077)
    data = Path(sys.argv[1]).read_text(encoding='utf-8').splitlines()
    if len(data) != 2:
        raise MaintenanceError('API credentials are incomplete.')
    with operation_lock():
        install(data[0], data[1])


if __name__ == '__main__':
    try:
        main()
    except (MaintenanceError, OSError, ValueError, subprocess.SubprocessError):
        error = sys.exc_info()[1]
        print(str(error) if isinstance(error, MaintenanceError) else 'Large upload setup failed; check service status before retrying.', file=sys.stderr)
        sys.exit(1)

