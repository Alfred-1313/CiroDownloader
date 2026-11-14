import io
import json
from pathlib import Path
import subprocess
import sys
import urllib.error
import unittest
from unittest.mock import patch

import test_updater as fixtures

updater = fixtures.updater


class UpdateReportTests(unittest.TestCase):
    setUp = fixtures.UpdaterTests.setUp
    tearDown = fixtures.UpdaterTests.tearDown
    queue = fixtures.UpdaterTests.queue
    commands = fixtures.UpdaterTests.commands

    def commit_notes(self, revision, changes):
        (self.project / 'CHANGELOG.json').write_text(json.dumps([
            {'version': '1.1.0', 'revision': revision, 'changes': changes}]))
        if not (self.project / '.git').exists():
            subprocess.run(['git', 'init', '-q'], cwd=self.project, check=True, capture_output=True)
        subprocess.run(['git', 'add', '.'], cwd=self.project, check=True, capture_output=True)
        subprocess.run(['git', '-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.invalid',
                        'commit', '-qm', revision], cwd=self.project, check=True, capture_output=True)
        return subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=self.project, text=True).strip()

    def test_same_version_changes_collect_intermediate_notes_without_repeats(self):
        installed = {'id': 'installed', 'title': 'Installed', 'what': 'Already present'}
        first = self.commit_notes('main-1', [installed])
        new = {'id': 'new', 'title': 'New caption', 'what': 'Post text appears'}
        self.commit_notes('main-2', [new])
        last = self.commit_notes('main-3', [new, installed, 'Latest improvement'])
        self.assertEqual(updater.revision_changes(first, last), [new, 'Latest improvement'])
        self.assertEqual(updater.revision_changes(last, last), [])
        self.assertEqual(updater.revision_changes(last, first), [])
        self.assertEqual(updater.revision_changes('bad', last), [])

    def test_success_records_same_version_report_and_recheck_preserves_it(self):
        first = self.commit_notes('main-1', ['Installed'])
        note = {'id': 'caption-fix', 'title': 'Caption fixed', 'what': 'Single files include text'}
        last = self.commit_notes('main-2', [note])
        self.queue(); self.agent.claim()
        self.agent.finish(self.agent.ui('update_success'), from_version='1.1.0', from_revision=first, to_revision=last)
        report = json.loads(self.agent.setting('update_new_changes'))
        self.assertEqual(report['from'], report['to'])
        self.assertEqual(report['changes'], [note])
        self.assertEqual(report['to_revision'], 'main-2')
        edits = [params for method, params in self.agent.calls if method == 'editMessageText']
        self.assertIn('Caption fixed', edits[-1]['text'])
        self.agent.finish(self.agent.ui('update_already_current'))
        self.assertEqual(json.loads(self.agent.setting('update_new_changes')), report)
        last_edit = self.agent.calls[-1][1]
        self.assertNotIn('Caption fixed', last_edit['text'])
        self.assertIn('update:changes', json.dumps(last_edit['reply_markup']))

    def test_report_is_hidden_after_rollback_to_another_main_revision(self):
        self.commit_notes('main-1', ['Installed'])
        self.agent.set('update_new_changes', json.dumps({'to': '1.1.0', 'to_revision': 'main-2', 'changes': ['Other revision']}))
        self.assertEqual(self.agent.saved_changes(), [])

    def test_rollback_clears_future_report_for_older_same_version_images(self):
        old = self.commit_notes('main-1', ['Installed'])
        self.commit_notes('main-2', ['New report'])
        self.agent.set('update_new_changes', json.dumps({'to': '1.1.0', 'to_revision': 'main-2', 'changes': ['New report']}))
        self.agent.record_changes(to_revision=old)
        self.assertEqual(self.agent.setting('update_new_changes'), '')
        self.assertEqual(self.agent.setting('update_installed_revision'), '')

    def test_manual_pull_with_old_containers_rebuilds_and_reports(self):
        self.commit_notes('main-1', ['Installed'])
        last = self.commit_notes('main-2', [{'id': 'main-fix', 'title': 'Main fix', 'what': 'New code'}])
        self.queue()
        def current_checkout(*args, **kwargs):
            if args[:2] == ('git', 'rev-parse'):
                return last
            if args[:4] == ('docker', 'compose', 'exec', '-T'):
                return '1.1.0' if args[-1] == '/app/VERSION' else json.dumps([{'version': '1.1.0', 'revision': 'main-1'}])
            return self.commands(*args, **kwargs)
        with patch.object(updater, 'command', current_checkout), patch.object(updater, 'prune_previous'):
            self.agent.tick()
        self.assertTrue(any(method == 'run' and args[:3] == ('docker', 'compose', 'build') for method, args in self.agent.calls))
        self.assertEqual(json.loads(self.agent.setting('update_new_changes'))['changes'][0]['title'], 'Main fix')

    def test_matching_running_manifest_skips_build_and_keeps_report(self):
        last = self.commit_notes('main-2', ['Installed'])
        self.agent.set('update_new_changes', json.dumps({'to': '1.1.0', 'to_revision': 'main-2', 'changes': ['Last report']}))
        self.queue()
        def current_runtime(*args, **kwargs):
            if args[:2] == ('git', 'rev-parse'):
                return last
            if args[:4] == ('docker', 'compose', 'exec', '-T'):
                return '1.1.0' if args[-1] == '/app/VERSION' else (self.project / 'CHANGELOG.json').read_text()
            return self.commands(*args, **kwargs)
        with patch.object(updater, 'command', current_runtime):
            self.agent.tick()
        self.assertFalse(any(method == 'run' and args[:3] == ('docker', 'compose', 'build') for method, args in self.agent.calls))
        self.assertEqual(self.agent.saved_changes(), ['Last report'])
        self.assertIn(self.agent.ui('update_already_current'), self.agent.calls[-1][1]['text'])

    def test_cleanup_warning_never_marks_a_verified_install_failed(self):
        self.queue()
        error = updater.UpdateFailure('cleanup_image', 'image_used', 1)
        with patch.object(updater, 'command', self.commands), patch.object(updater, 'prune_previous', side_effect=error):
            self.agent.tick()
        self.assertFalse(self.agent.restored)
        with self.agent.db() as db:
            self.assertEqual(db.execute('SELECT status FROM update_requests').fetchone()[0], 'done')
            self.assertFalse(db.execute("SELECT 1 FROM settings WHERE key IN ('recent_errors','last_job_error')").fetchone())
        alerts = [p for method, p in self.agent.calls if method == 'sendMessage']
        self.assertEqual([p['chat_id'] for p in alerts], [42])
        self.assertIn(self.agent.plain('update_warning_title'), alerts[0]['text'])
        self.assertIn(self.agent.plain('update_reason_image_used'), alerts[0]['text'])
        self.assertIn('کد خروج: <code>1</code>', alerts[0]['text'])
        self.assertIn(self.agent.ui('update_success'), self.agent.calls[-1][1]['text'])
        self.assertIn(self.agent.ui('update_cleanup_notice'), self.agent.calls[-1][1]['text'])

    def test_cleanup_migration_warning_identifies_its_phase(self):
        previous = {'sha': 'a' * 40, 'image': 'ciro-downloader:backup-' + 'a' * 12 + '-1', 'backup': 'pre-update-20260101-000000-1.tar.gz'}
        (self.state / 'previous.json').write_text(json.dumps(previous))
        with patch.object(updater, 'prune_previous', side_effect=RuntimeError('Retained backup is missing')):
            self.agent.tick()
        alerts = [p for method, p in self.agent.calls if method == 'sendMessage']
        self.assertEqual(len(alerts), 1)
        self.assertIn(self.agent.plain('update_cleanup'), alerts[0]['text'])
        self.assertIn(self.agent.plain('update_reason_backup_missing'), alerts[0]['text'])
        self.assertTrue((self.state / 'retention-v2.json').exists())

    def test_failed_build_has_context_and_preserves_recovery(self):
        self.queue(); self.agent.fail_build = True
        with patch.object(updater, 'command', self.commands):
            self.agent.tick()
        self.assertTrue(self.agent.restored)
        with self.agent.db() as db:
            self.assertEqual(db.execute('SELECT status FROM update_requests').fetchone()[0], 'failed')
        self.assertIn(self.agent.ui('update_build'), self.agent.calls[-1][1]['text'])
        self.assertNotIn('در لاگ سرویس سرور ثبت شده', self.agent.calls[-1][1]['text'])

    def test_command_errors_never_expose_raw_output_or_arguments(self):
        failure = subprocess.CompletedProcess(['fixture'], 1, b'', b'PRIVATE_COOKIE https://secret.invalid/token: no space left on device')
        with patch.object(updater.subprocess, 'run', return_value=failure):
            with self.assertRaises(updater.UpdateFailure) as caught:
                updater.command('docker', 'compose', 'build', 'PRIVATE_ARG')
        self.agent.error_alert(caught.exception, stage='update_build')
        alert = self.agent.calls[-1][1]['text']
        self.assertIn(self.agent.plain('update_reason_storage'), alert)
        self.assertNotIn('PRIVATE', alert)
        self.assertNotIn('secret.invalid', alert)
        self.assertNotIn('PRIVATE', str(caught.exception))

    def test_noisy_process_is_drained_without_logs_or_secret_notifications(self):
        program = "import sys;sys.stdout.write('PRIVATE_TOKEN'+'x'*2000000);sys.stderr.write('no space left on device');sys.exit(7)"
        with self.assertRaises(updater.UpdateFailure) as caught:
            updater.Agent.run(self.agent, sys.executable, '-c', program, timeout=10)
        self.assertEqual(caught.exception.reason, 'storage')
        self.assertEqual(caught.exception.code, 7)
        self.assertNotIn('PRIVATE_TOKEN', str(caught.exception))
        self.assertFalse(list(self.root.rglob('*.log')))

    def test_completion_edit_failure_retries_same_message_after_restart(self):
        first = self.commit_notes('main-1', ['Installed'])
        last = self.commit_notes('main-2', ['New main note'])
        self.queue(); self.agent.claim()
        previous = {'sha': first, 'version': '1.1.0', 'image': 'ciro-downloader:backup-' + first[:12] + '-1'}
        updater.atomic_json(self.state / 'previous.json', previous)
        original = self.agent.api
        def reject_edit(method, params):
            if method == 'editMessageText':
                raise RuntimeError('Notification unavailable')
            return original(method, params)
        self.agent.api = reject_edit
        self.agent.finish(self.agent.ui('update_success'), from_version='1.1.0', from_revision=first, to_revision=last)
        self.assertTrue((self.state / 'completion-pending.json').exists())
        self.agent.api = original; self.agent.job = None
        self.agent.refresh_completion()
        self.assertFalse((self.state / 'completion-pending.json').exists())
        edits = [p for method, p in self.agent.calls if method == 'editMessageText']
        self.assertTrue(edits)
        self.assertEqual({p['message_id'] for p in edits}, {99})
        self.assertIn('New main note', edits[-1]['text'])
        self.assertFalse(any(method == 'sendMessage' for method, _ in self.agent.calls))

    def test_notification_retry_accepts_message_already_edited(self):
        self.agent.env = {'BOT_TOKEN': '123:secret-test'}
        response = io.BytesIO(json.dumps({'description': 'Bad Request: message is not modified'}).encode())
        rejected = urllib.error.HTTPError('https://redacted.invalid', 400, 'rejected', {}, response)
        with patch.object(updater.urllib.request, 'urlopen', side_effect=rejected):
            self.assertTrue(updater.Agent.api(self.agent, 'editMessageText', {}))

    def test_first_upgrade_from_old_agent_recovers_deleted_report_refresh(self):
        first = self.commit_notes('main-1', ['Installed'])
        self.commit_notes('main-2', ['First same-version report'])
        self.queue(); self.agent.claim()
        with self.agent.db() as db:
            db.execute("UPDATE update_requests SET status='done'")
        updater.atomic_json(self.state / 'previous.json', {'sha': first, 'version': '1.1.0',
            'image': 'ciro-downloader:backup-' + first[:12] + '-1', 'backup': 'pre-update-20261114-100000-1.tar.gz'})
        self.agent.set('update_new_changes', '')
        # Simulate the old finish() deleting completion-pending.json after installing the new agent.
        with patch.object(self.agent, 'runtime_release_revision', return_value='main-2'):
            self.agent.refresh_completion()
        self.assertEqual(self.agent.saved_changes(), ['First same-version report'])
        self.assertEqual(self.agent.calls[-1][1]['message_id'], 99)
        self.assertIn('First same-version report', self.agent.calls[-1][1]['text'])
        count = len(self.agent.calls)
        with patch.object(self.agent, 'runtime_release_revision', return_value='main-2'):
            self.agent.refresh_completion()
        self.assertEqual(len(self.agent.calls), count, 'The bootstrap announcement runs once, never every timer tick')

    def test_old_cli_update_restores_notes_without_sending_an_unrelated_message(self):
        first = self.commit_notes('main-1', ['Installed'])
        self.commit_notes('main-2', ['CLI change report'])
        updater.atomic_json(self.state / 'previous.json', {'sha': first, 'version': '1.1.0',
            'image': 'ciro-downloader:backup-' + first[:12] + '-1', 'backup': 'pre-update-20261114-100000-0.tar.gz'})
        with patch.object(self.agent, 'runtime_release_revision', return_value='main-2'):
            self.agent.refresh_completion()
        self.assertEqual(self.agent.saved_changes(), ['CLI change report'])
        self.assertEqual(self.agent.calls, [])

    def test_long_update_summary_stays_small_and_keeps_all_entries_in_report(self):
        first = self.commit_notes('main-1', ['Installed'])
        notes = [{'id': 'note-' + str(i), 'title': 'عنوان ' + str(i), 'what': 'توضیح ' * 45,
                  'example': 'نمونه ' * 40, 'default': 'on', 'path': ['مسیری طولانی' * 4] * 6} for i in range(14)]
        last = self.commit_notes('main-2', notes)
        self.queue(); self.agent.claim()
        self.agent.finish(self.agent.ui('update_success'), from_version='1.1.0', from_revision=first, to_revision=last)
        self.assertEqual(self.agent.saved_changes(), notes)
        text = self.agent.calls[-1][1]['text']
        self.assertLess(len(text.encode('utf-16-le')) // 2, 3900)
        self.assertIn('14', text)


if __name__ == '__main__':
    unittest.main()
