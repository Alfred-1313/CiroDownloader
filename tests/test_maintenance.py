import importlib.util
import io
import json
import os
from pathlib import Path
import sqlite3
import tarfile
import tempfile
import unittest

spec = importlib.util.spec_from_file_location('maintenance', Path(__file__).parents[1] / 'scripts/maintenance.py')
maintenance = importlib.util.module_from_spec(spec)
spec.loader.exec_module(maintenance)
ENV = b'BOT_TOKEN=123:FAKE_OLD_TOKEN\nADMIN_IDS=42\nSTORAGE_DIR=/app/storage\nYOUTUBE_PROXY=\n'


def data_archive(database, cookie=b'FAKE_PRIVATE_COOKIE'):
    stream = io.BytesIO()
    with tarfile.open(fileobj=stream, mode='w') as archive:
        for name, content in [('zero.sqlite', database), ('youtube-cookies.txt', cookie)]:
            member = tarfile.TarInfo(name); member.size = len(content); member.mode = 0o600
            archive.addfile(member, io.BytesIO(content))
    return stream.getvalue()


class FakeDocker(maintenance.Maintenance):
    def __init__(self, project, backups, data):
        super().__init__(project, backups)
        self.data = data; self.services = ['bot']; self.calls = []; self.fail_start = False
        self.project_name = 'ciro-downloader'

    def command(self, *args, input=None, output=None, timeout=180):
        self.calls.append(args)
        if args[2:4] == ('config', '--format'):
            return json.dumps({'name': self.project_name, 'services': {'worker': {'volumes': [
                {'type': 'volume', 'source': 'data', 'target': '/app/storage'}]}},
                'volumes': {'data': {'name': 'ciro-downloader_data'}}})
        if args[2:3] == ('ps',):
            return '\n'.join(self.services)
        if args[2:3] == ('stop',):
            self.services = []; return ''
        if args[2:3] in [('start',), ('up',)]:
            if self.fail_start:
                self.fail_start = False
                self.services = ['bot']
                raise maintenance.MaintenanceError('Simulated startup failure')
            self.services = [name for name in args[3:] if name in ('bot', 'worker')]; return ''
        if '--entrypoint' in args and args[args.index('--entrypoint') + 1] == 'tar':
            output.write(self.data); return ''
        if '--entrypoint' in args and args[args.index('--entrypoint') + 1] == 'sh':
            if self.services:
                raise AssertionError('Data must never be replaced while services are running')
            self.data = input; return ''
        return ''


class MaintenanceTests(unittest.TestCase):
    def test_parallel_worker_capacity_survives_environment_validation(self):
        for value in ('', '1', '3', '8'):
            content = ENV.decode() + f'WORKER_CONCURRENCY={value}\n'
            self.assertEqual(maintenance.environment(content)['WORKER_CONCURRENCY'], value)
        for value in ('0', '9', '-1', '2.5', '3; echo bad'):
            with self.assertRaises(maintenance.MaintenanceError):
                maintenance.environment(ENV.decode() + f'WORKER_CONCURRENCY={value}\n')

    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.root = Path(self.temporary.name)
        self.project = self.root / 'project'; self.project.mkdir()
        (self.project / '.env').write_bytes(ENV)
        (self.project / 'VERSION').write_text('1.3.0', encoding='utf-8')
        database = self.root / 'fixture.sqlite'
        with sqlite3.connect(database) as connection:
            connection.executescript('CREATE TABLE settings(key TEXT,value TEXT); CREATE TABLE jobs(id INTEGER,status TEXT); INSERT INTO settings VALUES("theme","original");')
        connection.close()
        self.app = FakeDocker(self.project, self.root / 'backups', data_archive(database.read_bytes()))

    def tearDown(self):
        self.temporary.cleanup()

    def test_backup_contains_configuration_and_private_cookie_data(self):
        backup = self.app.backup()
        self.assertEqual(self.app.services, ['bot'])
        self.assertEqual((self.project / '.env').read_bytes(), ENV)
        with tempfile.TemporaryDirectory(dir=self.root) as folder:
            data = self.app.unpack(backup, folder)
            self.assertEqual((data / 'youtube-cookies.txt').read_bytes(), b'FAKE_PRIVATE_COOKIE')
            self.assertEqual((Path(folder) / 'config.env').read_bytes(), ENV)
        if os.name != 'nt':
            self.assertEqual(backup.stat().st_mode & 0o777, 0o600)
            self.assertEqual(backup.parent.stat().st_mode & 0o777, 0o700)

    def test_backup_resumes_only_the_services_that_were_running(self):
        self.app.services = ['worker']; self.app.backup()
        self.assertEqual(self.app.services, ['worker'])
        self.app.services = []; self.app.backup()
        self.assertEqual(self.app.services, [])

    def test_configuration_changes_keep_unrelated_values_and_stopped_services(self):
        self.app.services = []
        self.app.configure('YOUTUBE_PROXY', 'socks5h://name:FAKE_PASSWORD@127.0.0.1:1080')
        values = maintenance.environment((self.project / '.env').read_text())
        self.assertEqual(values['BOT_TOKEN'], '123:FAKE_OLD_TOKEN')
        self.assertEqual(values['ADMIN_IDS'], '42')
        self.assertEqual(self.app.services, [])
        self.assertEqual(len(list(self.app.backups.glob('ciro-config-*.env'))), 1)

    def test_bad_inputs_never_change_configuration_or_leak_input(self):
        for field, value in [('BOT_TOKEN', 'bad SECRET'), ('ADMIN_IDS', '42,invalid'), ('YOUTUBE_PROXY', 'ftp://SECRET@host')]:
            with self.assertRaises(maintenance.MaintenanceError) as caught:
                self.app.configure(field, value)
            self.assertNotIn('SECRET', str(caught.exception))
            self.assertEqual((self.project / '.env').read_bytes(), ENV)

    def test_startup_failure_rolls_back_settings(self):
        self.app.fail_start = True
        with self.assertRaisesRegex(maintenance.MaintenanceError, 'Previous configuration restored'):
            self.app.configure('ADMIN_IDS', '42,99')
        self.assertEqual((self.project / '.env').read_bytes(), ENV)
        self.assertEqual(self.app.services, ['bot'])

    def test_token_change_checks_telegram_before_recreating_active_services(self):
        self.app.configure('BOT_TOKEN', '321:FAKE_NEW_TOKEN')
        calls = self.app.calls
        check = next(i for i, call in enumerate(calls) if 'php' in call)
        restart = next(i for i, call in enumerate(calls) if call[2:3] == ('up',))
        self.assertLess(check, restart)
        self.assertEqual(self.app.services, ['bot'])

    def test_restore_keeps_a_recovery_backup_and_restores_cookie_and_config(self):
        backup = self.app.backup()
        (self.project / '.env').write_bytes(ENV.replace(b'123:FAKE_OLD_TOKEN', b'321:FAKE_NEW_TOKEN'))
        recovery = self.app.restore(backup)
        self.assertTrue(recovery.is_file())
        self.assertEqual((self.project / '.env').read_bytes(), ENV)
        self.assertEqual(self.app.services, ['bot'])
        with tarfile.open(fileobj=io.BytesIO(self.app.data)) as archive:
            self.assertEqual(archive.extractfile('youtube-cookies.txt').read(), b'FAKE_PRIVATE_COOKIE')

    def test_restore_does_not_silently_move_between_cloud_and_local_api(self):
        backup = self.app.backup()
        local = ENV + b'BOT_API_URL=http://ciro-telegram-api:8081\nMAX_FILE_BYTES=1990000000\n'
        (self.project / '.env').write_bytes(local)
        with self.assertRaisesRegex(maintenance.MaintenanceError, 'different Telegram API'):
            self.app.restore(backup)
        self.assertEqual((self.project / '.env').read_bytes(), local)
        self.assertEqual(self.app.services, ['bot'])

    def test_failed_restore_rolls_back_data_and_configuration(self):
        backup = self.app.backup()
        changed = ENV.replace(b'123:FAKE_OLD_TOKEN', b'321:FAKE_NEW_TOKEN')
        (self.project / '.env').write_bytes(changed)
        self.app.fail_start = True
        # Simulate failure only when the restored containers are recreated.
        original = self.app.start
        def start(services, recreate=False):
            if not recreate:
                previous = self.app.fail_start; self.app.fail_start = False
                try: return original(services, recreate)
                finally: self.app.fail_start = previous
            return original(services, recreate)
        self.app.start = start
        with self.assertRaisesRegex(maintenance.MaintenanceError, 'previous data and settings were restored'):
            self.app.restore(backup)
        self.assertEqual((self.project / '.env').read_bytes(), changed)
        self.assertEqual(self.app.services, ['bot'])

    def bad_archive(self, name, kind=tarfile.REGTYPE):
        nested = io.BytesIO()
        with tarfile.open(fileobj=nested, mode='w') as archive:
            member = tarfile.TarInfo(name); member.type = kind; member.linkname = '/outside'
            member.size = 0; archive.addfile(member, io.BytesIO())
        path = self.root / 'bad.tar.gz'
        with tarfile.open(path, 'w:gz') as archive:
            manifest = json.dumps({'format': 'ciro-downloader-backup', 'schema': 1}).encode()
            for entry, body in [('manifest.json', manifest), ('config.env', ENV), ('data.tar', nested.getvalue())]:
                member = tarfile.TarInfo(entry); member.size = len(body); archive.addfile(member, io.BytesIO(body))
        return path

    def test_initial_restore_stop_failure_cannot_replace_data_with_running_services(self):
        backup = self.app.backup()
        original = self.app.command
        stops = 0
        def command(*args, **kwargs):
            nonlocal stops
            if args[2:3] == ('stop',):
                stops += 1
                if stops == 2:  # Recovery backup stopped/resumed; initial restore stop now fails.
                    raise maintenance.MaintenanceError('Simulated stop failure')
            return original(*args, **kwargs)
        self.app.command = command
        with self.assertRaisesRegex(maintenance.MaintenanceError, 'previous data and settings were restored'):
            self.app.restore(backup)
        self.assertEqual(stops, 3)
        self.assertEqual(self.app.services, ['bot'])
        self.assertEqual((self.project / '.env').read_bytes(), ENV)

    def test_unsafe_archives_are_rejected_before_stopping_services(self):
        for name, kind in [('../escape', tarfile.REGTYPE), ('/absolute', tarfile.REGTYPE), ('link', tarfile.SYMTYPE), ('hardlink', tarfile.LNKTYPE)]:
            with self.assertRaises(maintenance.MaintenanceError):
                self.app.restore(self.bad_archive(name, kind))
            self.assertEqual(self.app.services, ['bot'])
            self.assertFalse(any(call[2:3] == ('stop',) for call in self.app.calls))

    def test_other_projects_are_never_backed_up_or_changed(self):
        self.app.project_name = 'Ciro'
        with self.assertRaisesRegex(maintenance.MaintenanceError, 'not the managed'):
            self.app.backup()
        self.assertEqual((self.project / '.env').read_bytes(), ENV)
        self.assertEqual(self.app.services, ['bot'])

    def test_restoring_a_backup_does_not_replay_old_downloads_or_updates(self):
        database = self.root / 'pending.sqlite'
        connection = sqlite3.connect(database)
        try:
            connection.executescript('CREATE TABLE settings(key TEXT,value TEXT); CREATE TABLE jobs(id INTEGER,status TEXT); CREATE TABLE update_requests(id INTEGER,status TEXT); INSERT INTO settings VALUES("update_in_progress","1"); INSERT INTO jobs VALUES(1,"queued"),(2,"done"); INSERT INTO update_requests VALUES(1,"running");')
            connection.commit()
        finally:
            connection.close()
        self.app.data = data_archive(database.read_bytes())
        backup = self.app.backup()
        with tempfile.TemporaryDirectory(dir=self.root) as folder:
            data = self.app.unpack(backup, folder)
            restored = sqlite3.connect(data / 'zero.sqlite')
            try:
                self.assertEqual(restored.execute('SELECT status FROM jobs ORDER BY id').fetchall(), [('failed',), ('done',)])
                self.assertEqual(restored.execute('SELECT status FROM update_requests').fetchone()[0], 'failed')
                self.assertEqual(restored.execute('SELECT value FROM settings WHERE key="update_in_progress"').fetchone()[0], '0')
            finally:
                restored.close()


if __name__ == '__main__':
    unittest.main()

