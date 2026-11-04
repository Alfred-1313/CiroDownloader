import importlib.util
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import patch

SCRIPTS = Path(__file__).parents[1] / 'scripts'
sys.path.insert(0, str(SCRIPTS))
spec = importlib.util.spec_from_file_location('large_uploads', SCRIPTS / 'large_uploads.py')
large = importlib.util.module_from_spec(spec)
spec.loader.exec_module(large)


class FakeMaintenance:
    def check_project(self): pass
    def running(self): return ['bot', 'worker']


class Response:
    def close(self): pass


class LargeUploadMigrationTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.root = Path(self.tmp.name)
        self.project = self.root / 'project'; self.project.mkdir()
        (self.project / '.env').write_text('BOT_TOKEN=123:FAKE_TOKEN\nADMIN_IDS=42\nBOT_API_URL=https://api.telegram.org\nMAX_FILE_BYTES=49000000\n', encoding='utf-8')
        self.private = self.root / 'private'
        self.events = []
        self.patches = [patch.object(large, 'PROJECT', self.project), patch.object(large, 'PRIVATE', self.private),
                        patch.object(large, 'MARKER', self.private / 'migration.json'),
                        patch.object(large, 'Maintenance', FakeMaintenance),
                        patch.object(large, 'run', side_effect=lambda *args, **kw: self.events.append(('run', args))),
                        patch.object(large, 'queue_pause', side_effect=lambda: self.events.append(('pause',))),
                        patch.object(large, 'queue_resume', side_effect=lambda: self.events.append(('resume',))),
                        patch.object(large.urllib.request, 'urlopen', return_value=Response())]
        for item in self.patches: item.start()

    def tearDown(self):
        for item in reversed(self.patches): item.stop()
        self.tmp.cleanup()

    def test_switch_stops_polling_before_logout_and_resumes_after_local_login(self):
        def request(base, token, method):
            self.events.append(('request', base, method)); return True
        with patch.object(large, 'request', side_effect=request):
            large.install('123456', 'a'*32)
        stop = next(i for i, item in enumerate(self.events) if item[0] == 'run' and 'stop' in item[1])
        logout = self.events.index(('request', large.CLOUD, 'logOut'))
        login = self.events.index(('request', 'http://127.0.0.1:18081', 'getMe'))
        resume = self.events.index(('resume',))
        self.assertLess(stop, logout); self.assertLess(logout, login); self.assertLess(login, resume)
        values = large.environment((self.project / '.env').read_text())
        self.assertEqual(values['BOT_API_URL'], large.LOCAL)
        self.assertEqual(values['MAX_FILE_BYTES'], '1990000000')
        self.assertFalse((self.private / 'migration.json').exists())
        self.assertNotIn('a'*32, (self.project / '.env').read_text())

    def test_logout_failure_keeps_bot_paused_and_retry_does_not_skip_logout(self):
        with patch.object(large, 'request', return_value=False):
            with self.assertRaisesRegex(large.MaintenanceError, 'paused'):
                large.install('123456', 'a'*32)
        self.assertIn('pending_logout', (self.private / 'migration.json').read_text())
        self.assertNotIn(('resume',), self.events)
        calls = []
        with patch.object(large, 'request', side_effect=lambda base, token, method: calls.append(method) or True):
            large.install('123456', 'a'*32)
        self.assertEqual(calls, ['logOut', 'getMe'])

    def test_login_failure_does_not_logout_cloud_again_on_retry(self):
        with patch.object(large, 'request', side_effect=[True, False]):
            with self.assertRaisesRegex(large.MaintenanceError, 'paused'):
                large.install('123456', 'a'*32)
        self.assertIn('logged_out', (self.private / 'migration.json').read_text())
        calls = []
        with patch.object(large, 'request', side_effect=lambda base, token, method: calls.append(method) or True):
            large.install('123456', 'a'*32)
        self.assertEqual(calls, ['getMe'])

    def test_invalid_credentials_never_touch_services(self):
        with self.assertRaises(large.MaintenanceError):
            large.install('123', 'FAKE_SECRET')
        self.assertEqual(self.events, [])
        self.assertFalse(self.private.exists())

    def test_failed_build_never_logs_out_or_changes_bot_configuration(self):
        with patch.object(large, 'run', side_effect=large.MaintenanceError('build failed')), patch.object(large, 'request') as request:
            with self.assertRaises(large.MaintenanceError):
                large.install('123456', 'a'*32)
        request.assert_not_called()
        self.assertIn('BOT_API_URL=https://api.telegram.org', (self.project / '.env').read_text())
        self.assertFalse((self.private / 'migration.json').exists())


if __name__ == '__main__':
    unittest.main()

