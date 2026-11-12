import importlib.util
import json
import io
import tarfile
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).parents[1] / 'scripts'))
import updater
import cleanup


class RetentionTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        self.state = self.root / 'state'; self.state.mkdir()
        self.backups = self.root / 'backups'; self.backups.mkdir()
        self.old = 'pre-update-20260101-120000-123456-1.tar.gz'
        self.keep = 'pre-update-20260102-120000-123456-2.tar.gz'
        self.previous = {'sha': 'a' * 40, 'image': 'ciro-downloader:backup-' + 'a' * 12 + '-2', 'backup': self.keep}
        for name in (self.old, self.keep, 'ciro-backup-manual.tar.gz', 'unrelated.tar.gz'):
            (self.backups / name).write_bytes(b'archive')
        self.valid_archive()
        (self.state / 'previous.json').write_text(json.dumps(self.previous))
        self.calls = []
        self.patches = [patch.object(updater, 'STATE', self.state), patch.object(updater, 'BACKUPS', self.backups), patch.object(updater, 'command', self.command)]
        for p in self.patches: p.start()

    def valid_archive(self):
        with tarfile.open(self.backups / self.keep, 'w:gz') as archive:
            for name in ('source/.env', 'data/zero.sqlite'):
                info = tarfile.TarInfo(name); info.size = 4
                archive.addfile(info, io.BytesIO(b'data'))

    def tearDown(self):
        for p in reversed(self.patches): p.stop()
        self.temp.cleanup()

    def command(self, *args, **kwargs):
        self.calls.append(args)
        if args[:3] == ('docker', 'image', 'ls'):
            return '\n'.join([self.previous['image'], 'ciro-downloader:backup-' + 'b' * 12 + '-1', 'another-app:backup-123', 'ciro-downloader:local'])
        return ''

    def test_only_previous_automatic_artifacts_survive(self):
        updater.prune_previous()
        self.assertFalse((self.backups / self.old).exists())
        self.assertTrue((self.backups / self.keep).exists())
        self.assertTrue((self.backups / 'ciro-backup-manual.tar.gz').exists())
        self.assertTrue((self.backups / 'unrelated.tar.gz').exists())
        removals = [a for a in self.calls if a[:3] == ('docker', 'image', 'rm')]
        self.assertEqual(removals, [('docker', 'image', 'rm', 'ciro-downloader:backup-' + 'b' * 12 + '-1')])

    def test_legacy_archives_reported_on_full_server_are_removed(self):
        legacy = ['pre-update-20261109-113622-134.tar.gz',
                  'pre-update-20261109-224803-139.tar.gz',
                  'pre-update-20261111-042818-159.tar.gz']
        for name in legacy:
            (self.backups / name).write_bytes(b'legacy automatic backup')
        updater.prune_previous()
        self.assertTrue((self.backups / self.keep).is_file())
        self.assertTrue((self.backups / 'ciro-backup-manual.tar.gz').is_file())
        self.assertFalse(any((self.backups / name).exists() for name in legacy))

    def test_legacy_retained_version_and_pending_archive_remain_protected(self):
        self.keep = 'pre-update-20261111-042818-159.tar.gz'
        self.previous['backup'] = self.keep
        self.valid_archive()
        (self.state / 'previous.json').write_text(json.dumps(self.previous))
        pending_name = 'pre-update-20261110-190150-152.tar.gz'
        (self.backups / pending_name).write_bytes(b'pending recovery')
        (self.state / 'recovery.json').write_text(json.dumps({'previous': {
            'sha': 'b' * 40, 'image': 'ciro-downloader:backup-' + 'b' * 12 + '-1', 'backup': pending_name}}))
        updater.prune_previous()
        self.assertTrue((self.backups / self.keep).is_file())
        self.assertTrue((self.backups / pending_name).is_file())
        self.assertFalse((self.backups / self.old).exists())

    def test_pending_recovery_and_completion_are_protected(self):
        for name in ('recovery.json', 'completion-pending.json'):
            (self.state / name).write_text(json.dumps({'previous': {'sha': 'b' * 40, 'image': 'ciro-downloader:backup-' + 'b' * 12 + '-1', 'backup': self.old}}))
        updater.prune_previous()
        self.assertTrue((self.backups / self.old).exists())
        self.assertFalse(any(a[:3] == ('docker', 'image', 'rm') for a in self.calls))

    def test_missing_archive_or_image_never_prunes(self):
        (self.backups / self.keep).unlink()
        with self.assertRaises(RuntimeError): updater.prune_previous()
        self.assertTrue((self.backups / self.old).exists())
        self.assertEqual(self.calls, [])
        self.valid_archive()
        with patch.object(updater, 'command', side_effect=RuntimeError('image missing')):
            with self.assertRaises(RuntimeError): updater.prune_previous()
        self.assertTrue((self.backups / self.old).exists())

    def test_corrupt_retained_archive_never_prunes(self):
        (self.backups / self.keep).write_bytes(b'corrupt archive')
        with self.assertRaises(tarfile.TarError): updater.prune_previous()
        self.assertTrue((self.backups / self.old).exists())
        self.assertEqual(self.calls, [])

    def test_no_previous_record_never_guesses_what_to_delete(self):
        (self.state / 'previous.json').unlink()
        updater.prune_previous()
        self.assertTrue((self.backups / self.old).exists())
        self.assertEqual(self.calls, [])

    def test_cleanup_preserves_persistent_data_and_symlink_targets(self):
        data = self.root / 'data'; data.mkdir()
        downloads = data / 'downloads'; downloads.mkdir()
        logs = self.root / 'logs'; logs.mkdir()
        for name in ('zero.sqlite', 'youtube-cookies.txt'): (data / name).write_text('persistent')
        for name in ('radio', 'music-batches', 'instagram-pages'): (data / name).mkdir()
        folder = downloads / 'job-123-abcdef'; folder.mkdir(); (folder / 'ready.mp4').write_bytes(b'sent')
        outside = self.root / 'private'; outside.write_text('must survive')
        (folder / 'symlink').symlink_to(outside)
        (logs / 'step-old.log').write_text('old log')
        (data / 'yt-cache').mkdir()
        self.assertEqual(cleanup.clean_files(data, logs), 3)
        self.assertTrue(folder.exists())
        cleanup.clean_files(data, logs, apply=True)
        self.assertFalse(folder.exists()); self.assertFalse((logs / 'step-old.log').exists())
        self.assertEqual(outside.read_text(), 'must survive')
        self.assertEqual((data / 'zero.sqlite').read_text(), 'persistent')
        self.assertTrue((data / 'radio').exists()); self.assertTrue((data / 'music-batches').exists())
        self.assertTrue((data / 'instagram-pages').exists())


if __name__ == '__main__': unittest.main()
