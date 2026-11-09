import importlib.util
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
import os
import time

SCRIPT = Path(__file__).parents[1] / 'scripts' / 'bounded-log.py'
spec = importlib.util.spec_from_file_location('bounded_log', SCRIPT)
logs = importlib.util.module_from_spec(spec)
spec.loader.exec_module(logs)


class BoundedLogTests(unittest.TestCase):
    def test_noisy_command_keeps_bounded_tail(self):
        with tempfile.TemporaryDirectory() as root:
            path = Path(root) / 'step-fixture.log'; path.touch(mode=0o600)
            subprocess.run([sys.executable, str(SCRIPT), str(path)], input=b'x' * (logs.LIMIT * 4) + b'final-error', check=True)
            self.assertLessEqual(path.stat().st_size, logs.LIMIT)
            self.assertTrue(path.read_bytes().endswith(b'final-error'))
            self.assertEqual(path.stat().st_mode & 0o777, 0o600)

    def test_retention_does_not_touch_unrelated_files_or_symlinks(self):
        with tempfile.TemporaryDirectory() as root:
            root = Path(root)
            for i in range(25):
                path = root / f'step-{i:02}.log'; path.write_bytes(b'x' * 50)
                os.utime(path, (time.time() - i, time.time() - i))
            (root / 'keep.txt').write_text('keep')
            (root / 'step-link.log').symlink_to(root / 'keep.txt')
            logs.prune(root)
            self.assertEqual(len([p for p in root.glob('step-*.log') if not p.is_symlink()]), logs.KEEP)
            self.assertEqual((root / 'keep.txt').read_text(), 'keep')
            path = root / 'step-old.log'; path.touch()
            os.utime(path, (0, 0)); logs.prune(root)
            self.assertFalse(path.exists())
