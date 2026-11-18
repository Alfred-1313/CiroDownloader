import contextlib
import hashlib
import hmac
import importlib.util
import json
import os
from pathlib import Path
import sqlite3
import shutil
import subprocess
import tempfile
import time
import unittest
import zipfile

spec = importlib.util.spec_from_file_location('database_restore', Path(__file__).parents[1] / 'scripts/database_restore.py')
restore = importlib.util.module_from_spec(spec)
spec.loader.exec_module(restore)


def database(path, label, offset=10):
    with sqlite3.connect(path) as db:
        db.executescript('''CREATE TABLE settings(key TEXT PRIMARY KEY,value TEXT NOT NULL);
            CREATE TABLE users(id INTEGER PRIMARY KEY);
            CREATE TABLE jobs(id INTEGER PRIMARY KEY,status TEXT,user_id INTEGER,chat_id INTEGER);
            CREATE TABLE states(user_id INTEGER,key TEXT,expires INTEGER);
            CREATE TABLE membership_downloads(panel TEXT PRIMARY KEY,revision TEXT,url TEXT,update_id INTEGER,source_message INTEGER,expires INTEGER);
            CREATE TABLE update_requests(id INTEGER PRIMARY KEY,user_id INTEGER,chat_id INTEGER,message_id INTEGER,action TEXT,status TEXT,created INTEGER,thread_id INTEGER DEFAULT 0);
            CREATE TABLE database_restore_files(request_id INTEGER PRIMARY KEY,token TEXT,sha256 TEXT);
            CREATE TABLE radio_stations(chat_id INTEGER,live INTEGER,live_since INTEGER,command TEXT,now TEXT);
            CREATE TABLE radio_queue(id INTEGER);
            CREATE TABLE timed_deletes(chat_id INTEGER,message_id INTEGER,at INTEGER);
            CREATE TABLE ig_check_subs(id INTEGER,posts_ready INTEGER,stories_ready INTEGER,generation INTEGER);
            CREATE TABLE ig_check_targets(id INTEGER,lease TEXT,lease_until INTEGER,next_check INTEGER);
            CREATE TABLE ig_check_deliveries(id INTEGER,status TEXT);
            CREATE TABLE yt_check_subs(id INTEGER,posts_ready INTEGER,stories_ready INTEGER,extra_ready INTEGER,generation INTEGER);
            CREATE TABLE yt_check_targets(id INTEGER,lease TEXT,lease_until INTEGER,next_check INTEGER);
            CREATE TABLE yt_check_deliveries(id INTEGER,status TEXT);
            INSERT INTO jobs VALUES(1,'running',42,42);
            INSERT INTO states VALUES(42,'cookie.upload',0);
            INSERT INTO membership_downloads VALUES('fixture','old','https://www.youtube.com/watch?v=AAAAAAAAAAA',25,26,9999999999);
            INSERT INTO radio_stations VALUES(-100,1,123,'play','song');
            INSERT INTO radio_queue VALUES(1);
            INSERT INTO timed_deletes VALUES(-100,777,0);
            INSERT INTO ig_check_subs VALUES(1,1,1,5);
            INSERT INTO yt_check_subs VALUES(1,1,1,1,5);
            INSERT INTO ig_check_targets VALUES(1,'busy',9999999999,9999999999);
            INSERT INTO yt_check_targets VALUES(1,'busy',9999999999,9999999999);
            INSERT INTO ig_check_deliveries VALUES(1,'sending');
            INSERT INTO yt_check_deliveries VALUES(1,'offered');''')
        db.executemany('INSERT INTO settings VALUES(?,?)', [('fixture', label), ('offset', str(offset)),
            ('database.menu.42', 'old'), ('update_menu.42', '999'), ('database.backup.enabled', '1'),
            ('database.backup.chat', '42'), ('database.backup.minutes', '60'), ('database.backup.next', '100')])


def archive(path, source, bot='123', extra=None):
    raw = source.read_bytes()
    meta = {'format': 'ciro-database-backup', 'schema': 1, 'bot_id': bot, 'sha256': hashlib.sha256(raw).hexdigest()}
    meta['signature'] = hmac.new(b'123:fixture', ('ciro-database-backup:1:' + bot + ':' + meta['sha256']).encode(), hashlib.sha256).hexdigest()
    with zipfile.ZipFile(path, 'w', zipfile.ZIP_DEFLATED) as z:
        z.writestr('manifest.json', json.dumps(meta))
        z.writestr('zero.sqlite', raw)
        if extra:
            z.writestr(extra, b'not allowed')


class Agent:
    def __init__(self, data, job):
        self.data, self.job = data, job
        self.uid = os.getuid()
        self.env = {'BOT_TOKEN': '123:fixture'}
        self.running = ['bot', 'worker', 'radio']
        self.calls = []
        self.fail_verify = 0
        self.authorize = True

    @contextlib.contextmanager
    def db(self):
        db = sqlite3.connect(self.data / 'zero.sqlite')
        db.row_factory = sqlite3.Row
        try:
            yield db
            db.commit()
        finally:
            db.close()

    def authorized(self, job, db=None):
        return self.authorize and job['user_id'] == 42 and job['chat_id'] == 42 and job['action'] == 'database_restore'

    def set(self, key, value):
        with self.db() as db:
            db.execute('INSERT INTO settings VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value', (key, str(value)))

    def database_services(self):
        return self.running.copy()

    def run(self, *args, **kwargs):
        self.calls.append(args)
        if args[2] == 'stop':
            self.running = []
        elif args[2] == 'up':
            self.running = [name for name in args[3:] if name in restore.SERVICES]

    def verify(self):
        self.assert_ready = bool('bot' in self.running and 'worker' in self.running)
        if not self.assert_ready or self.fail_verify:
            self.fail_verify = max(0, self.fail_verify - 1)
            raise RuntimeError('Fixture failed startup')
        self.set('poller_heartbeat', int(time.time()))
        self.set('worker_heartbeat', int(time.time()))

    def api(self, method, params):
        self.calls.append((method, params))
        return True

    def ui(self, key):
        return key


class RestoreTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        self.data = self.root / 'data'
        self.data.mkdir()
        self.state = self.root / 'state'
        self.state.mkdir()
        self.backups = self.root / 'backups'
        self.backups.mkdir()
        database(self.data / 'zero.sqlite', 'current', 1000)
        self.source = self.root / 'source.sqlite'
        database(self.source, 'imported', 10)
        self.token = 'a' * 32
        (self.data / 'database-backups').mkdir()
        self.payload = self.data / 'database-backups' / ('import-' + self.token + '.zip')
        archive(self.payload, self.source)
        self.job = {'id': 1, 'user_id': 42, 'chat_id': 42, 'message_id': 555, 'action': 'database_restore', 'status': 'running', 'created': int(time.time()), 'thread_id': 0}
        self.agent = Agent(self.data, self.job)
        with self.agent.db() as db:
            db.execute('INSERT INTO update_requests VALUES(?,?,?,?,?,?,?,?)', tuple(self.job[k] for k in ('id','user_id','chat_id','message_id','action','status','created','thread_id')))
            db.execute('INSERT INTO database_restore_files VALUES(?,?,?)', (1, self.token, hashlib.sha256(self.payload.read_bytes()).hexdigest()))
        self.runner = restore.Restore(self.agent, self.state, self.backups)
        self.cookie = self.data / 'youtube-cookies.txt'
        self.cookie.write_text('PRIVATE_FIXTURE_COOKIE')

    def tearDown(self):
        self.temp.cleanup()

    def setting(self, key):
        with self.agent.db() as db:
            row = db.execute('SELECT value FROM settings WHERE key=?', (key,)).fetchone()
            return row[0] if row else None

    def test_roundtrip_replaces_only_database_after_stopping_and_keeps_recovery(self):
        replace = self.runner.replace
        def stopped_replace(source):
            self.assertEqual(self.agent.running, [])
            replace(source)
        self.runner.replace = stopped_replace
        self.runner.run()
        self.assertEqual(self.setting('fixture'), 'imported')
        self.assertEqual(self.setting('offset'), '1000')
        self.assertEqual(self.setting('update_in_progress'), '0')
        self.assertEqual(self.setting('database.restore.active'), '0')
        self.assertEqual(self.cookie.read_text(), 'PRIVATE_FIXTURE_COOKIE')
        self.assertEqual(self.agent.running, ['bot', 'worker', 'radio'])
        self.assertFalse(self.payload.exists())
        self.assertFalse(self.runner.journal.exists())
        backups = list(self.backups.glob('database-before-*.sqlite'))
        self.assertEqual(len(backups), 1)
        with sqlite3.connect(backups[0]) as db:
            self.assertEqual(db.execute("SELECT value FROM settings WHERE key='fixture'").fetchone()[0], 'current')
        with self.agent.db() as db:
            for table in ('jobs','states','membership_downloads','radio_queue','timed_deletes'):
                self.assertEqual(db.execute('SELECT count(*) FROM ' + table).fetchone()[0], 0)
            self.assertEqual(tuple(db.execute('SELECT posts_ready,stories_ready,generation FROM ig_check_subs').fetchone()), (0,0,6))
            self.assertEqual(tuple(db.execute('SELECT posts_ready,stories_ready,extra_ready FROM yt_check_subs').fetchone()), (0,0,0))
            self.assertEqual(db.execute('SELECT status FROM ig_check_deliveries').fetchone()[0], 'uncertain')
            self.assertEqual(db.execute('SELECT status FROM yt_check_deliveries').fetchone()[0], 'cancelled')
            self.assertEqual(db.execute('SELECT live FROM radio_stations').fetchone()[0], 0)
            self.assertEqual(db.execute('SELECT status FROM update_requests').fetchone()[0], 'done')
        self.assertIsNone(self.setting('database.menu.42'))
        edits = [call[1] for call in self.agent.calls if call[0] == 'editMessageText']
        self.assertEqual(edits[-1]['text'], 'database_restore_done')
        self.assertTrue(all(p['chat_id'] == 42 and p['message_id'] == 555 for p in edits))

    def test_failed_startup_restores_previous_database_and_services(self):
        self.agent.fail_verify = 1
        self.runner.run()
        self.assertEqual(self.setting('fixture'), 'current')
        self.assertEqual(self.setting('update_in_progress'), '0')
        self.assertFalse(self.runner.journal.exists())
        self.assertIn(('editMessageText', {'chat_id':42,'message_id':555,'text':'database_restore_recovered','parse_mode':'HTML','reply_markup':{'inline_keyboard':[]}}), self.agent.calls)

    def test_process_interruption_after_replacement_recovers_on_next_agent_tick(self):
        replace = self.runner.replace
        def interrupted(source):
            replace(source)
            raise KeyboardInterrupt()
        self.runner.replace = interrupted
        with self.assertRaises(KeyboardInterrupt):
            self.runner.run()
        self.assertEqual(self.setting('fixture'), 'imported')
        self.assertTrue(self.runner.journal.exists())
        restore.Restore(self.agent, self.state, self.backups).recover()
        self.assertEqual(self.setting('fixture'), 'current')
        self.assertFalse(self.runner.journal.exists())

    def test_committed_interruption_keeps_verified_import(self):
        self.runner.finish = lambda *args, **kwargs: (_ for _ in ()).throw(KeyboardInterrupt())
        with self.assertRaises(KeyboardInterrupt):
            self.runner.run()
        self.assertEqual(json.loads(self.runner.journal.read_text())['phase'], 'committed')
        restore.Restore(self.agent, self.state, self.backups).recover()
        self.assertEqual(self.setting('fixture'), 'imported')

    def test_changed_or_unauthorized_upload_never_stops_services(self):
        self.payload.write_bytes(b'changed')
        self.runner.run()
        self.assertEqual(self.setting('fixture'), 'current')
        self.assertFalse(any(call[:3] == ('docker','compose','stop') for call in self.agent.calls))

    def test_revoked_administrator_is_checked_again_on_host(self):
        self.agent.authorize = False
        self.runner.run()
        self.assertEqual(self.setting('fixture'), 'current')
        self.assertFalse(any(call[:3] == ('docker','compose','stop') for call in self.agent.calls))

    def test_archive_rejects_other_bot_paths_and_foreign_database_objects(self):
        for bot, extra in [('456', None), ('123', '../zero.sqlite'), ('123', 'cookies.txt')]:
            archive(self.payload, self.source, bot, extra)
            with tempfile.TemporaryDirectory(dir=self.root) as folder, self.assertRaises(ValueError):
                restore.unpack(self.payload, folder, '123:fixture')
        with sqlite3.connect(self.source) as db:
            db.execute("CREATE TRIGGER unexpected AFTER INSERT ON users BEGIN DELETE FROM settings; END")
        archive(self.payload, self.source)
        with tempfile.TemporaryDirectory(dir=self.root) as folder, self.assertRaises(ValueError):
            restore.unpack(self.payload, folder, '123:fixture')

    def test_import_path_cannot_escape_private_queue(self):
        with self.agent.db() as db:
            db.execute("UPDATE database_restore_files SET token='../zero.sqlite'")
        self.runner.run()
        self.assertEqual(self.setting('fixture'), 'current')
        self.assertTrue(self.cookie.exists())

    def test_signature_cannot_be_replaced_with_a_new_digest(self):
        with zipfile.ZipFile(self.payload) as z:
            meta = json.loads(z.read('manifest.json'))
            raw = z.read('zero.sqlite')
        meta['sha256'] = '0' * 64
        with zipfile.ZipFile(self.payload, 'w') as z:
            z.writestr('manifest.json', json.dumps(meta))
            z.writestr('zero.sqlite', raw)
        with tempfile.TemporaryDirectory(dir=self.root) as folder, self.assertRaisesRegex(ValueError, 'signature'):
            restore.unpack(self.payload, folder, '123:fixture')

    @unittest.skipUnless(shutil.which('php'), 'Native PHP is required for the cross-language roundtrip')
    def test_real_php_export_and_python_restore_remain_readable_by_the_bot(self):
        project = Path(__file__).resolve().parents[1]
        storage = self.root / 'native-export'
        code = '''require $argv[1].'/src/Core.php';require $argv[1].'/src/Bot.php';
            $c=new Zero\\Config('123:fixture',[42],$argv[2]);$s=new Zero\\Store($c->storage);
            $s->set('native.roundtrip','preserved');$s->set('offset','12');
            $a=new class implements Zero\\Api {public function call(string $m,array $p=[]):mixed{return true;}};
            $b=new Zero\\Bot($c,$s,$a);new Zero\\InstagramChecker($c,$s,$a);new Zero\\YoutubeChecker($c,$s,$a);new Zero\\Radio($c,$s);
            echo (new Zero\\DatabaseBackups($b))->snapshot();'''
        output = subprocess.check_output(['php','-r',code,str(project),str(storage)], text=True)
        destination = self.root / 'native-import'
        destination.mkdir()
        restored = restore.unpack(Path(output), destination, '123:fixture')
        restore.sanitize(restored, {'offset':'1000'}, self.job)
        probe = '''require $argv[1].'/src/Core.php';$s=new Zero\\Store($argv[2]);echo $s->get('native.roundtrip').':'.$s->get('offset');'''
        result = subprocess.check_output(['php','-r',probe,str(project),str(destination)], text=True)
        self.assertEqual(result, 'preserved:1000')

    def test_real_host_authorization_keeps_database_restoration_private(self):
        path = Path(__file__).parents[1] / 'scripts/updater.py'
        spec = importlib.util.spec_from_file_location('backup_updater', path)
        updater = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(updater)
        agent = updater.Agent.__new__(updater.Agent)
        agent.admins = {42}
        self.assertTrue(agent.authorized(self.job))
        self.assertFalse(agent.authorized(dict(self.job, chat_id=-100)))
        self.assertFalse(agent.authorized(dict(self.job, thread_id=17)))
        self.assertFalse(agent.authorized(dict(self.job, action='database_restore; command')))

    def test_old_backup_cannot_jump_past_the_current_telegram_update_sequence(self):
        with sqlite3.connect(self.source) as db:
            db.execute("UPDATE settings SET value='9000000' WHERE key='offset'")
        restore.sanitize(self.source, {'offset':'1000'}, self.job)
        with sqlite3.connect(self.source) as db:
            self.assertEqual(db.execute("SELECT value FROM settings WHERE key='offset'").fetchone()[0], '1000')
            self.assertEqual(db.execute("SELECT value FROM settings WHERE key='database.restore.active'").fetchone()[0], '1')

    @unittest.skipUnless(os.geteuid() == 0, 'Requires root to reproduce the host-to-container UID transition')
    def test_real_runtime_uid_can_snapshot_before_writing_root_only_recovery(self):
        runtime_uid = 10001
        os.chmod(self.root, 0o711)
        os.chmod(self.backups, 0o700)
        for path in [self.data, *self.data.rglob('*')]:
            os.chown(path, runtime_uid, -1)
        self.agent.uid = runtime_uid
        @contextlib.contextmanager
        def runtime_db():
            os.seteuid(runtime_uid)
            db = None
            try:
                db = sqlite3.connect(self.data / 'zero.sqlite')
                db.row_factory = sqlite3.Row
                yield db
                db.commit()
            finally:
                if db is not None:
                    db.close()
                os.seteuid(0)
        self.agent.db = runtime_db
        self.runner.run()
        self.assertEqual(self.setting('fixture'), 'imported')
        self.assertEqual(self.setting('database.restore.active'), '0')
        self.assertEqual(len(list(self.backups.glob('database-before-*.sqlite'))), 1)
        self.assertFalse(list((self.data / 'database-backups').glob('recovery-*.sqlite')))


if __name__ == '__main__':
    unittest.main()
