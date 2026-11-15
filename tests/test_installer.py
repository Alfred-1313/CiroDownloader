"""Installer bootstrap with real temporary Git repositories, without Docker/root mutations."""

from pathlib import Path
import subprocess
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[1]


def command(*args, cwd=None, check=True):
    return subprocess.run(args, cwd=cwd, check=check, capture_output=True, text=True)


class InstallerTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="ciro-installer-")
        self.root = Path(self.temp.name)
        self.origin = self.root / "origin"
        self.installed = self.root / "installed"
        self.origin.mkdir()
        command("git", "init", "-b", "main", str(self.origin))
        command("git", "config", "user.name", "Installer fixture", cwd=self.origin)
        command("git", "config", "user.email", "fixture@example.invalid", cwd=self.origin)
        (self.origin / "manager.sh").write_text('#!/usr/bin/env bash\nprintf "MANAGER_ARG=<%s>\\n" "$@"\n')
        (self.origin / "compose.yaml").write_text("name: ciro-downloader\n")
        (self.origin / "code.txt").write_text("old source\n")
        (self.origin / ".gitignore").write_text(".env\nstorage/\n")
        self.commit("Initial installation")

    def tearDown(self):
        self.temp.cleanup()

    def commit(self, message):
        command("git", "add", ".", cwd=self.origin)
        command("git", "commit", "-m", message, cwd=self.origin)

    def clone(self):
        command("git", "clone", "--branch", "main", str(self.origin), str(self.installed))

    def bootstrap(self, *args):
        # Sourcing exposes the same bootstrap function, while the CLI-only root
        # guard stays intact. All target paths are private fixture directories.
        script = '''source "$1"
SCRIPT_DIR="$2/non-checkout"
INSTALL_DIR="$2/installed"
REPOSITORY_URL="$2/origin"
shift 2
open_manager "$@"
'''
        return command("bash", "-c", script, "_", str(ROOT / "install.sh"), str(self.root), *args, check=False)

    def snapshot(self):
        return {"head": command("git", "rev-parse", "HEAD", cwd=self.installed).stdout,
                "status": command("git", "status", "--porcelain", cwd=self.installed).stdout,
                "origin": command("git", "remote", "get-url", "origin", cwd=self.installed).stdout,
                "refs": command("git", "show-ref", cwd=self.installed).stdout,
                "index": (self.installed / ".git/index").read_bytes()}

    def test_existing_clean_checkout_does_not_advance_before_backup(self):
        self.clone()
        before = self.snapshot()
        (self.origin / "code.txt").write_text("new source\n")
        self.commit("New upstream code")
        result = self.bootstrap("status", "argument with spaces")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("MANAGER_ARG=<status>", result.stdout)
        self.assertIn("MANAGER_ARG=<argument with spaces>", result.stdout)
        self.assertEqual(self.snapshot(), before)
        self.assertEqual((self.installed / "code.txt").read_text(), "old source\n")
        self.assertFalse((self.installed / ".git/FETCH_HEAD").exists())

    def test_dirty_checkout_opens_without_losing_any_changes(self):
        self.clone()
        (self.installed / "code.txt").write_text("local staged edits\n")
        command("git", "add", "code.txt", cwd=self.installed)
        (self.installed / "code.txt").write_text("local unstaged edits\n")
        (self.installed / "new-file.txt").write_text("untracked code\n")
        (self.installed / ".env").write_text("FIXTURE_SECRET=not-real\n")
        (self.installed / "storage").mkdir()
        (self.installed / "storage/database.sqlite").write_bytes(b"fixture database")
        before = self.snapshot()
        (self.origin / "code.txt").write_text("upstream conflicting edits\n")
        self.commit("Conflicting upstream code")
        result = self.bootstrap()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("Opening the installed", result.stdout)
        self.assertEqual(self.snapshot(), before)
        self.assertEqual((self.installed / "code.txt").read_text(), "local unstaged edits\n")
        self.assertEqual((self.installed / "new-file.txt").read_text(), "untracked code\n")
        self.assertEqual((self.installed / ".env").read_text(), "FIXTURE_SECRET=not-real\n")
        self.assertEqual((self.installed / "storage/database.sqlite").read_bytes(), b"fixture database")
        self.assertNotIn("FIXTURE_SECRET", result.stdout + result.stderr)
        self.assertFalse((self.installed / ".git/refs/stash").exists())

    def test_rollback_detached_checkout_stays_detached(self):
        self.clone()
        command("git", "checkout", "--detach", cwd=self.installed)
        before = self.snapshot()
        result = self.bootstrap("status")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(self.snapshot(), before)
        self.assertEqual(command("git", "branch", "--show-current", cwd=self.installed).stdout.strip(), "")

    def test_new_install_clones_main_and_forwards_arguments(self):
        result = self.bootstrap("install")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("MANAGER_ARG=<install>", result.stdout)
        self.assertEqual(command("git", "rev-parse", "HEAD", cwd=self.installed).stdout,
                         command("git", "rev-parse", "HEAD", cwd=self.origin).stdout)

    def test_existing_non_repository_and_incomplete_checkout_are_preserved(self):
        self.installed.mkdir()
        marker = self.installed / "keep.txt"
        marker.write_text("keep me")
        result = self.bootstrap()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("not a Git checkout", result.stderr)
        self.assertEqual(marker.read_text(), "keep me")
        command("git", "init", "-b", "main", str(self.installed))
        result = self.bootstrap()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("incomplete checkout", result.stderr)
        self.assertEqual(marker.read_text(), "keep me")

    def test_manager_explains_dirty_source_without_printing_contents(self):
        self.clone()
        (self.installed / "code.txt").write_text("PRIVATE_FIXTURE_EDIT\n")
        (self.installed / "new-file.txt").write_text("UNTRACKED_FIXTURE\n")
        before = self.snapshot()
        script = '''source "$1"
PROJECT_DIR="$2"
cd "$PROJECT_DIR"
export NO_COLOR=1 CIRO_ASCII=1 TERM=dumb
ui_init
require_clean_source
'''
        result = command("bash", "-c", script, "_", str(ROOT / "manager.sh"), str(self.installed), check=False)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("git stash push --include-untracked -m ciro-before-update", result.stdout)
        self.assertIn("untracked files", result.stdout)
        self.assertNotIn("PRIVATE_FIXTURE_EDIT", result.stdout + result.stderr)
        self.assertNotIn("UNTRACKED_FIXTURE", result.stdout + result.stderr)
        self.assertEqual(self.snapshot(), before)

    def test_suggested_stash_keeps_edits_and_excludes_runtime_data(self):
        self.clone()
        (self.installed / "code.txt").write_text("local tracked edit\n")
        (self.installed / "new-file.txt").write_text("local untracked code\n")
        (self.installed / ".env").write_text("fixture configuration\n")
        (self.installed / "storage").mkdir()
        (self.installed / "storage/database.sqlite").write_bytes(b"fixture database")
        command("git", "stash", "push", "--include-untracked", "-m", "ciro-before-update", cwd=self.installed)
        self.assertEqual(command("git", "status", "--porcelain", cwd=self.installed).stdout, "")
        self.assertEqual(command("git", "show", "stash@{0}:code.txt", cwd=self.installed).stdout, "local tracked edit\n")
        self.assertEqual(command("git", "show", "stash@{0}^3:new-file.txt", cwd=self.installed).stdout, "local untracked code\n")
        self.assertEqual((self.installed / ".env").read_text(), "fixture configuration\n")
        self.assertEqual((self.installed / "storage/database.sqlite").read_bytes(), b"fixture database")


if __name__ == "__main__":
    unittest.main()
