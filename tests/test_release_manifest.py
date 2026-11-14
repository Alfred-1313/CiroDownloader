import json
from pathlib import Path
import subprocess
import unittest


class ReleaseManifestTests(unittest.TestCase):
    root = Path(__file__).resolve().parents[1]

    def git(self, *args):
        return subprocess.check_output(['git', *args], cwd=self.root, text=True, stderr=subprocess.DEVNULL).strip()

    def manifest(self):
        return json.loads((self.root / 'CHANGELOG.json').read_text())

    def test_latest_manifest_has_a_revision_and_stable_change_ids(self):
        entries = self.manifest()
        self.assertEqual(len(entries), 1)
        entry = entries[0]
        self.assertEqual(entry['version'], (self.root / 'VERSION').read_text().strip())
        self.assertRegex(entry['revision'], r'^[a-z0-9][a-z0-9_.-]{0,99}$')
        self.assertTrue(entry['changes'])
        ids = []
        for change in entry['changes']:
            self.assertRegex(change['id'], r'^[a-z0-9][a-z0-9_.-]{0,99}$')
            self.assertTrue(change['title'].strip())
            self.assertTrue(change['what'].strip())
            self.assertTrue(change['example'].strip())
            self.assertIn(change['default'], ['on', 'off'])
            self.assertIsInstance(change['path'], list)
            self.assertTrue(change['path'])
            self.assertTrue(all(isinstance(step, str) and step.strip() for step in change['path']))
            ids.append(change['id'])
        self.assertEqual(len(ids), len(set(ids)))

    def test_production_code_changes_always_register_new_release_notes(self):
        try:
            dirty = self.git('diff', '--name-only', 'HEAD').splitlines()
            base = 'HEAD' if dirty else 'HEAD^'
            paths = dirty if dirty else self.git('diff', '--name-only', base, 'HEAD').splitlines()
            old = json.loads(self.git('show', base + ':CHANGELOG.json'))[0]
        except (subprocess.CalledProcessError, OSError, ValueError, IndexError):
            self.skipTest('The parent commit is not available in this checkout')
        production = any(path.startswith(('src/', 'bin/', 'scripts/')) or path in
                         ('Dockerfile', 'compose.yaml', 'install.sh', 'manager.sh', 'resources/ui.json') for path in paths)
        if not production:
            return
        current = self.manifest()[0]
        self.assertNotEqual(current['revision'], old.get('revision', ''), 'Code changes need a new changelog revision even when VERSION stays fixed')
        current_ids = {change['id'] for change in current['changes']}
        old_ids = {change.get('id') for change in old.get('changes', []) if isinstance(change, dict)} - {None}
        self.assertTrue(current_ids - old_ids, 'Describe at least one new feature or fix for each published code update')
        self.assertFalse(current_ids & old_ids, 'Do not carry previously announced entries into the latest manifest')


if __name__ == '__main__':
    unittest.main()
