import contextlib
import importlib.util
import json
from pathlib import Path
import py_compile
import sqlite3
import subprocess
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
        self.env = {}
        self.admins = {42}
        self.job = None
        self.last_beat = 0
        self.calls = []
        self.restored = False
        self.fail_build = False

    @contextlib.contextmanager
    def db(self):
        db = sqlite3.connect(self.data / 'zero.sqlite')
        try:
            db.row_factory = sqlite3.Row
            with db:
                yield db
        finally:
            db.close()

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
                CREATE TABLE update_requests(id INTEGER PRIMARY KEY,user_id INTEGER,chat_id INTEGER,message_id INTEGER,action TEXT,status TEXT,created INTEGER,thread_id INTEGER NOT NULL DEFAULT 0);
                CREATE UNIQUE INDEX one_update ON update_requests((1)) WHERE status IN ('queued','running');""")
        (self.data / 'youtube-cookies.txt').write_text('PRIVATE_TEST_COOKIE')

    def tearDown(self):
        for item in reversed(self.patches):
            item.stop()
        self.temp.cleanup()

    def queue(self, user=42, chat=42, action='update', message=99, thread=0):
        with self.agent.db() as db:
            db.execute('INSERT INTO update_requests VALUES(1,?,?,?,?,?,?,?)', (user, chat, message, action, 'queued', int(time.time()), thread))

    def commands(self, *args, **kwargs):
        if args[:4] == ('git', 'remote', 'get-url', 'origin'):
            return updater.ORIGIN
        if args[:3] == ('git', 'status', '--porcelain'):
            return ''
        if args[:2] == ('git', 'rev-parse'):
            return 'a' * 40 if args[2] == 'HEAD' else 'b' * 40
        return ''

    def source_checkout(self):
        (self.project / '.gitignore').write_bytes((Path(__file__).parents[1] / '.gitignore').read_bytes())
        (self.project / 'scripts').mkdir()
        source = self.project / 'scripts/maintenance.py'
        source.write_text('fixture = True\n')
        for args in [('init', '-q'), ('add', '.'),
                     ('-c', 'user.name=Test', '-c', 'user.email=test@example.invalid', 'commit', '-qm', 'Fixture')]:
            subprocess.run(['git', *args], cwd=self.project, check=True, capture_output=True)
        return source

    def checkout_commands(self, *args, **kwargs):
        if args == ('git', 'status', '--porcelain'):
            return subprocess.check_output(args, cwd=self.project, text=True).strip()
        return self.commands(*args, **kwargs)

    def test_python_import_cache_does_not_block_update(self):
        source = self.source_checkout()
        cache = Path(py_compile.compile(str(source), doraise=True))
        self.assertTrue(cache.is_file())
        (self.project / 'scripts/legacy.pyc').write_bytes(cache.read_bytes())
        self.queue()
        with patch.object(updater, 'command', self.checkout_commands):
            self.agent.tick()
        with self.agent.db() as db:
            self.assertEqual(db.execute('SELECT status FROM update_requests').fetchone()[0], 'done')
        self.assertTrue(cache.is_file(), 'Updating must not delete the cache to bypass the source check')

    def test_real_source_changes_still_block_update(self):
        source = self.source_checkout()
        py_compile.compile(str(source), doraise=True)
        for changed in (source, self.project / 'scripts/new_feature.py'):
            with self.subTest(path=changed.name):
                source.write_text('fixture = True\n')
                changed.write_text('fixture = "local work"\n')
                with self.agent.db() as db:
                    db.execute('DELETE FROM update_requests')
                self.agent.calls.clear()
                self.queue()
                with patch.object(updater, 'command', self.checkout_commands):
                    self.agent.tick()
                with self.agent.db() as db:
                    self.assertEqual(db.execute('SELECT status FROM update_requests').fetchone()[0], 'failed')
                self.assertFalse(any(method == 'run' for method, _ in self.agent.calls))
                self.assertFalse((self.state / 'recovery.json').exists())

    def test_denies_forged_requests(self):
        for user, chat, action in [(1, 1, 'update'), (1, -100123, 'update'), (42, 7, 'update'), (42, 0, 'update'), (42, 42, 'sh rm anything')]:
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
                db.execute("INSERT INTO update_requests VALUES(2,42,42,100,'update','queued',0,0)")

    def test_group_progress_and_completion_edit_the_same_message(self):
        self.queue(chat=-100123, thread=17)
        with self.agent.db() as db:
            db.execute("INSERT INTO settings VALUES('update_menu.42','777')")
        self.assertTrue(self.agent.claim())
        self.agent.progress('update_build', 65)
        self.agent.finish('✅ با موفقیت نصب شد')
        edits = [params for method, params in self.agent.calls if method == 'editMessageText']
        self.assertEqual(len(edits), 2)
        self.assertTrue(all(item['chat_id'] == -100123 and item['message_id'] == 99 for item in edits))
        self.assertIn('65%', edits[0]['text'])
        self.assertIn('با موفقیت نصب شد', edits[-1]['text'])
        self.assertFalse(any(method == 'sendMessage' for method, _ in self.agent.calls))
        with self.agent.db() as db:
            self.assertEqual(db.execute("SELECT value FROM settings WHERE key='update_menu.42.-100123.17'").fetchone()[0], '99')
            self.assertEqual(db.execute("SELECT value FROM settings WHERE key='update_menu.42'").fetchone()[0], '777')

    def test_rejects_invalid_update_topics(self):
        for chat, thread in [(-100123, -1), (42, 17)]:
            with self.subTest(chat=chat, thread=thread):
                with self.agent.db() as db:
                    db.execute('DELETE FROM update_requests')
                self.queue(chat=chat, thread=thread)
                self.assertFalse(self.agent.claim())

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
        self.assertNotIn('100%', edits[-1]['text'])
        self.assertIn('<blockquote><b>✅ با موفقیت نصب شد</b></blockquote>', edits[-1]['text'])
        self.assertIn('update:start', json.dumps(edits[-1]['reply_markup']))
        self.assertIn('update:rollback', json.dumps(edits[-1]['reply_markup']))
        self.assertTrue(all(method != 'sendMessage' for method, _ in self.agent.calls))
        progress = [params for params in edits if 'update:progress' in json.dumps(params['reply_markup'])]
        self.assertGreaterEqual(len(progress), 5)
        # Completion is displayed only after the application health check.
        verified = next(index for index, call in enumerate(self.agent.calls) if call[0] == 'verify')
        finished = next(index for index, call in enumerate(self.agent.calls) if call[0] == 'editMessageText' and 'با موفقیت نصب شد' in call[1]['text'])
        self.assertGreater(finished, verified)

    def test_progress_and_completion_follow_the_reopened_menu(self):
        self.queue()
        self.assertTrue(self.agent.claim())
        with self.agent.db() as db:
            db.execute('UPDATE update_requests SET message_id=123')
            db.execute('INSERT INTO settings(key,value) VALUES(?,?)', ('update_menu.42', '123'))
        self.agent.progress('update_build', 35)
        self.assertEqual(self.agent.calls[-1][1]['message_id'], 123)
        self.agent.finish('✅ با موفقیت نصب شد')
        self.assertEqual(self.agent.calls[-1][1]['message_id'], 123)
        with self.agent.db() as db:
            self.assertEqual(db.execute("SELECT value FROM settings WHERE key='update_percent'").fetchone()[0], '35')
            self.assertEqual(db.execute("SELECT value FROM settings WHERE key='update_menu.42'").fetchone()[0], '123')

    def test_current_source_rebuilds_outdated_or_missing_services(self):
        for versions in [('1.0.0', '1.0.0'), ('1.1.0', ''), ('1.1.0', '1.0.0')]:
            with self.subTest(versions=versions):
                with self.agent.db() as db:
                    db.execute('DELETE FROM update_requests')
                self.agent.calls.clear()
                self.queue()

                def current_source(*args, **kwargs):
                    if args[:2] == ('git', 'rev-parse'):
                        return 'a' * 40
                    if args[:4] == ('docker', 'compose', 'exec', '-T'):
                        version = versions[0 if args[4] == 'bot' else 1]
                        if not version:
                            raise RuntimeError('Service is not running')
                        return version
                    return self.commands(*args, **kwargs)

                with patch.object(updater, 'command', current_source):
                    self.agent.tick()
                builds = [args for method, args in self.agent.calls if method == 'run' and args[:3] == ('docker', 'compose', 'build')]
                self.assertEqual(len(builds), 1)
                saved = json.loads((self.state / 'previous.json').read_text())
                self.assertEqual(saved['version'], versions[0])
                with self.agent.db() as db:
                    self.assertEqual(db.execute('SELECT status FROM update_requests').fetchone()[0], 'done')

    def test_current_source_and_running_services_skip_rebuild(self):
        self.queue()

        def current_runtime(*args, **kwargs):
            if args[:2] == ('git', 'rev-parse'):
                return 'a' * 40
            if args[:4] == ('docker', 'compose', 'exec', '-T'):
                return '1.1.0'
            return self.commands(*args, **kwargs)

        with patch.object(updater, 'command', current_runtime):
            self.agent.tick()
        self.assertFalse(any(method == 'run' and args[:3] == ('docker', 'compose', 'build') for method, args in self.agent.calls))
        self.assertFalse((self.state / 'previous.json').exists())
        edits = [params for method, params in self.agent.calls if method == 'editMessageText']
        self.assertNotIn(self.agent.ui('update_success'), edits[-1]['text'])

    def test_completion_does_not_replace_a_newer_idle_menu(self):
        self.queue()
        self.assertTrue(self.agent.claim())
        with self.agent.db() as db:
            db.execute('INSERT INTO settings(key,value) VALUES(?,?)', ('update_menu.42', '200'))
        self.agent.finish('✅ با موفقیت نصب شد')
        with self.agent.db() as db:
            self.assertEqual(db.execute("SELECT value FROM settings WHERE key='update_menu.42'").fetchone()[0], '200')

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

    def test_old_agent_completion_uses_new_renderer_without_an_extra_message(self):
        self.queue()
        self.agent.claim()
        previous = {'sha': 'a' * 40, 'image': 'ciro-downloader:backup-' + 'a' * 12 + '-123', 'version': '1.0.0'}
        updater.atomic_json(self.state / 'completion-pending.json', {'previous': previous, 'job': self.agent.job})
        with self.agent.db() as db:
            db.execute("UPDATE update_requests SET status='done'")
        self.agent.job = None
        self.agent.tick()
        edits = [params for method, params in self.agent.calls if method == 'editMessageText']
        self.assertEqual(len(edits), 1)
        self.assertEqual(edits[0]['message_id'], 99)
        self.assertIn('<blockquote><b>✅ با موفقیت نصب شد</b></blockquote>', edits[0]['text'])
        self.assertNotIn('100%', edits[0]['text'])
        self.assertFalse(any(method == 'sendMessage' for method, _ in self.agent.calls))
        self.assertFalse((self.state / 'completion-pending.json').exists())

    def test_group_completion_after_restart_edits_the_original_progress_message(self):
        self.queue(chat=-100123, thread=17)
        self.agent.claim()
        previous = {'sha': 'a' * 40, 'image': 'ciro-downloader:backup-' + 'a' * 12 + '-123', 'version': '1.0.0'}
        updater.atomic_json(self.state / 'completion-pending.json', {'previous': previous, 'job': self.agent.job})
        with self.agent.db() as db:
            db.execute("UPDATE update_requests SET status='done'")
        self.agent.job = None
        self.agent.tick()
        edits = [params for method, params in self.agent.calls if method == 'editMessageText']
        self.assertEqual(len(edits), 1)
        self.assertEqual((edits[0]['chat_id'], edits[0]['message_id']), (-100123, 99))
        self.assertIn('با موفقیت نصب شد', edits[0]['text'])
        self.assertFalse(any(method == 'sendMessage' for method, _ in self.agent.calls))

    def test_changes_exclude_installed_and_repeated_features(self):
        entries = [
            {'version': '1.0.0', 'changes': ['existing feature']},
            {'version': '1.1.0', 'changes': ['another installed feature']},
            {'version': '1.2.0', 'changes': ['existing feature', 'new feature']},
            {'version': '1.10.0', 'changes': ['new feature', 'newer feature']},
            {'version': '2.0.0', 'changes': ['future feature']},
        ]
        (self.project / 'CHANGELOG.json').write_text(json.dumps(entries))
        self.assertEqual(updater.changes_since('1.1.0', '1.10.0'), ['new feature', 'newer feature'])
        self.assertEqual(updater.changes_since('1.10.0', '1.10.0'), [])
        self.assertEqual(updater.changes_since('1.10.0', '1.1.0'), [])

    def test_latest_version_does_not_repeat_old_feature_announcements(self):
        self.queue()
        self.agent.claim()
        self.agent.set('update_new_changes', json.dumps({'from': '1.0.0', 'to': '1.1.0', 'changes': ['old feature']}))
        self.agent.finish('')
        edits = [params for method, params in self.agent.calls if method == 'editMessageText']
        self.assertNotIn('old feature', edits[-1]['text'])
        self.assertNotIn('update:changes', json.dumps(edits[-1]['reply_markup']))
        with self.agent.db() as db:
            self.assertEqual(db.execute("SELECT value FROM settings WHERE key='update_new_changes'").fetchone()[0], '')

    def test_actual_upgrade_only_announces_the_new_version_range(self):
        self.queue()
        self.agent.claim()
        self.agent.finish('✅ با موفقیت نصب شد', from_version='1.0.0')
        edits = [params for method, params in self.agent.calls if method == 'editMessageText']
        self.assertIn('راهنمای بهتر', edits[-1]['text'])
        self.assertIn('update:changes', json.dumps(edits[-1]['reply_markup']))


if __name__ == '__main__':
    unittest.main()

