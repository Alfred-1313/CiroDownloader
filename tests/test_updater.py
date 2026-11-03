import contextlib
import importlib.util
import json
from pathlib import Path
import sqlite3
import tarfile
import tempfile
import time
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('updater', Path(__file__).parents[1] / 'scripts/updater.py')
updater = importlib.util.module_from_spec(spec)
spec.loader.exec_module(updater)


class TestAgent(updater.Agent):
    def __init__(self, data):
        self.data = data
        self.admins = {42}
        self.job = None
        self.last_beat = 0
        self.calls = []
        self.restored = False
        self.fail_build = False

    @contextlib.contextmanager
    def db(self):
        with sqlite3.connect(self.data / 'zero.sqlite') as db:
            db.row_factory = sqlite3.Row
            yield db

    def api(self, method, params):
        self.calls.append((method, params))
        return {'message_id': 99}

    def run(self, *args, timeout=900):
        self.calls.append(('run', args))
        if self.fail_build and args[:3] == ('docker', 'compose', 'build'):
            raise RuntimeError('Simulated build failure')

    def verify(self):
        self.calls.append(('verify', None))

    def restore(self, previous):
        self.restored = True
        self.calls.append(('restore', previous))


class UpdaterTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        self.project = self.root / 'project'
        self.project.mkdir()
        (self.project / 'VERSION').write_text('1.1.0')
        (self.project / 'CHANGELOG.json').write_text(json.dumps([{'version': '1.1.0', 'changes': ['راهنمای بهتر']}]))
        (self.project / '.env').write_text('BOT_TOKEN=123:secret-test\n')
        self.data = self.root / 'data'
        self.data.mkdir()
        self.state = self.root / 'state'
        self.state.mkdir()
        self.backups = self.root / 'backups'
        self.patches = [patch.object(updater, 'PROJECT', self.project), patch.object(updater, 'STATE', self.state), patch.object(updater, 'BACKUPS', self.backups)]
        for item in self.patches:
            item.start()
        self.agent = TestAgent(self.data)
        with self.agent.db() as db:
            db.executescript("""CREATE TABLE settings(key TEXT PRIMARY KEY,value TEXT);
                CREATE TABLE jobs(id INTEGER,status TEXT);
                CREATE TABLE update_requests(id INTEGER PRIMARY KEY,user_id INTEGER,chat_id INTEGER,message_id INTEGER,action TEXT,status TEXT,created INTEGER);
                CREATE UNIQUE INDEX one_update ON update_requests((1)) WHERE status IN ('queued','running');""")
        (self.data / 'youtube-cookies.txt').write_text('PRIVATE_TEST_COOKIE')

    def tearDown(self):
        for item in reversed(self.patches):
            item.stop()
        self.temp.cleanup()

    def queue(self, user=42, chat=42, action='update', message=99):
        with self.agent.db() as db:
            db.execute('INSERT INTO update_requests VALUES(1,?,?,?,?,?,?)', (user, chat, message, action, 'queued', int(time.time())))

    def commands(self, *args, **kwargs):
        if args[:4] == ('git', 'remote', 'get-url', 'origin'):
            return updater.ORIGIN
        if args[:3] == ('git', 'status', '--porcelain'):
            return ''
        if args[:2] == ('git', 'rev-parse'):
            return 'a' * 40 if args[2] == 'HEAD' else 'b' * 40
        return ''

    def test_denies_forged_requests(self):
        for user, chat, action in [(1, 1, 'update'), (42, -1, 'update'), (42, 42, 'sh rm anything')]:
            with self.agent.db() as db:
                db.execute('DELETE FROM update_requests')
            self.queue(user, chat, action)
            self.assertFalse(self.agent.claim())
            with self.agent.db() as db:
                self.assertEqual(db.execute('SELECT status FROM update_requests').fetchone()[0], 'denied')

    def test_request_waits_for_message_and_is_global(self):
        self.queue(message=0)
        self.assertFalse(self.agent.claim())
        with self.agent.db() as db:
            with self.assertRaises(sqlite3.IntegrityError):
                db.execute("INSERT INTO update_requests VALUES(2,42,42,100,'update','queued',0)")

    def test_backup_contains_consistent_database_and_private_configuration(self):
        self.queue()
        self.assertTrue(self.agent.claim())
        name = self.agent.backup()
        path = self.backups / name
        self.assertEqual(path.stat().st_mode & 0o077, 0)
        with tarfile.open(path) as archive:
            self.assertIn('source/.env', archive.getnames())
            self.assertIn('data/youtube-cookies.txt', archive.getnames())
            snapshot = self.root / 'snapshot.sqlite'
            snapshot.write_bytes(archive.extractfile('data/zero.sqlite').read())
        with sqlite3.connect(snapshot) as db:
            self.assertEqual(db.execute('PRAGMA integrity_check').fetchone()[0], 'ok')

    def test_build_failure_restores_previous_image_and_resumes_jobs(self):
        self.queue()
        self.agent.fail_build = True
        with patch.object(updater, 'command', self.commands):
            self.agent.tick()
        self.assertTrue(self.agent.restored)
        self.assertFalse((self.state / 'recovery.json').exists())
        with self.agent.db() as db:
            self.assertEqual(db.execute('SELECT status FROM update_requests').fetchone()[0], 'failed')
            self.assertEqual(db.execute("SELECT value FROM settings WHERE key='update_in_progress'").fetchone()[0], '0')

    def test_success_records_rollback_target_and_finishes_same_message(self):
        self.queue()
        with patch.object(updater, 'command', self.commands):
            self.agent.tick()
        saved = json.loads((self.state / 'previous.json').read_text())
        self.assertEqual(saved['sha'], 'a' * 40)
        self.assertTrue(updater.valid_recovery(saved))
        edits = [params for method, params in self.agent.calls if method == 'editMessageText']
        self.assertTrue(all(params['message_id'] == 99 for params in edits))
        self.assertIn('مدیریت به‌روزرسانی', edits[-1]['text'])
        self.assertIn('۱۰۰٪', edits[-1]['text'])
        self.assertIn('ربات به‌روزرسانی شد', edits[-1]['text'])
        self.assertIn('update:start', json.dumps(edits[-1]['reply_markup']))
        self.assertIn('update:rollback', json.dumps(edits[-1]['reply_markup']))
        self.assertTrue(all(method != 'sendMessage' for method, _ in self.agent.calls))
        progress = [params for params in edits if 'update:progress' in json.dumps(params['reply_markup'])]
        self.assertGreaterEqual(len(progress), 5)
        # Completion is displayed only after the application health check.
        verified = next(index for index, call in enumerate(self.agent.calls) if call[0] == 'verify')
        finished = next(index for index, call in enumerate(self.agent.calls) if call[0] == 'editMessageText' and '۱۰۰٪' in call[1]['text'])
        self.assertGreater(finished, verified)

    def test_interrupted_update_recovers_before_accepting_new_work(self):
        self.queue()
        self.agent.claim()
        previous = {'sha': 'a' * 40, 'image': 'ciro-downloader:backup-' + 'a' * 12 + '-123'}
        updater.atomic_json(self.state / 'recovery.json', {'previous': previous, 'job': self.agent.job})
        self.agent.job = None
        self.agent.tick()
        self.assertTrue(self.agent.restored)
        self.assertFalse((self.state / 'recovery.json').exists())

    def test_recovery_rejects_untrusted_command_values(self):
        self.assertFalse(updater.valid_recovery({'sha': '--upload-pack=evil', 'image': 'anything'}))


if __name__ == '__main__':
    unittest.main()
