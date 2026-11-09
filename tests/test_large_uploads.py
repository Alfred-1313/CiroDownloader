import importlib.util
import os
from pathlib import Path
import select
import signal
import subprocess
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
        self.assertEqual(values['LOCAL_API_SHARED_STORAGE'], '1')
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

    def test_stopped_bot_is_rejected_before_the_expensive_build(self):
        with patch.object(FakeMaintenance, 'running', return_value=['worker']), patch.object(large, 'request') as request:
            with self.assertRaisesRegex(large.MaintenanceError, 'Start both bot and worker'):
                large.install('123456', 'a'*32)
        self.assertEqual(self.events, [])
        request.assert_not_called()
        self.assertIn('BOT_API_URL=https://api.telegram.org', (self.project / '.env').read_text())


class BuildOutputTests(unittest.TestCase):
    def command(self, code):
        return [sys.executable, '-B', '-c', 'import sys; sys.path.insert(0, ' + repr(str(SCRIPTS)) +
                '); from large_uploads import run, MaintenanceError; ' + code]

    def test_compiler_output_is_visible_before_build_finishes(self):
        build = 'import sys; print("Compiling fixture", flush=True); input(); print("Linking fixture", file=sys.stderr, flush=True)'
        command = self.command('run(sys.executable, "-u", "-c", ' + repr(build) + ', stream=True)')
        with subprocess.Popen(command, stdin=subprocess.PIPE, stdout=subprocess.PIPE,
                              stderr=subprocess.STDOUT, text=True, start_new_session=True) as process:
            try:
                self.assertTrue(select.select([process.stdout], [], [], 5)[0], 'Build output was hidden while the compiler was running')
                self.assertEqual(process.stdout.readline().strip(), 'Compiling fixture')
                output, _ = process.communicate('\n', timeout=5)
                self.assertEqual(process.returncode, 0)
                self.assertIn('Linking fixture', output)
            finally:
                if process.poll() is None:
                    os.killpg(process.pid, signal.SIGKILL)
                    process.wait()

    def test_streamed_build_failure_is_not_reported_as_success(self):
        command = self.command('run(sys.executable, "-c", "import sys; print(\'Compiler failure fixture\', file=sys.stderr); sys.exit(7)", stream=True)')
        result = subprocess.run(command, capture_output=True, text=True, timeout=5)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('Compiler failure fixture', result.stdout)
        self.assertIn('A Docker operation failed', result.stderr)

    def test_timeout_reports_the_limit_without_exposing_command_arguments(self):
        with self.assertRaisesRegex(large.MaintenanceError, 'timed out after 0.1 seconds') as error:
            large.run(sys.executable, '-c', 'import time; time.sleep(5)', 'FAKE_PRIVATE_ARGUMENT', timeout=0.1)
        self.assertNotIn('FAKE_PRIVATE_ARGUMENT', str(error.exception))


if __name__ == '__main__':
    unittest.main()

