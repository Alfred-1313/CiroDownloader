# Verification

GitHub Actions runs these checks on each push:

- `php tests/run.php`: URL validation, admin authorization, queue limits, Persian help alerts, cookie export validation and redacted process/proxy errors.
- `php tests/cookies.php`: administrator-only imports with arbitrary TXT filenames, persistent waiting and same-message navigation; real downloader invocation against local metadata fixtures gates replacement, with expired cookies, YouTube rejection and network failures preserving the active jar.
- `php tests/posters.php`: poster URL validation, stored photo state across restarts, photo-caption edits, missing/rejected poster fallback, caption limits and cleanup after delivery.
- `php tests/ux_fixes.php`: upgrade of saved historical defaults, preservation of real custom captions, plain pre-delivery messages, Persian cookie diagnostics, private user statistics, legacy reply-button labels and active-update menu reopening.
- `php tests/quality_waiting.php`: no selection deadline or cancel row, migration of old waiting menus, immediate new links without cancelling previous choices, safe selection while another request is active, queue capacity based on actual work, and recreation of the previous release's unique index during rollback with multiple waiting menus and an active job. The real worker test also checks that an old unchosen menu remains untouched.
- `php tests/personalization_scope.php`: traverse every editor group and page, include only UI ordinary users see, preserve captions and access notices, block admin field edits through callbacks, old routes and saved sessions, and retain user color/access controls.
- `php tests/personalization_recovery.php`: save and reset every user-editable field, verify persistence after restart, exercise all button colors and emoji, return to the selected catalog page, repair rejected saved previews, preserve edits across deleted messages and preview taps, and retain previous values after rejection or transport failure.
- `php tests/admin_keyboard.php`: switching home/administration reply keyboards, actual routing of every tool, stopping/starting, returning from submenus, clearing pending input, old inline navigation and deletion failure, and denying forged public reply labels. A strict fake rejects reply keyboards on Telegram edit methods.
- `php tests/groups.php`: default-on group use, native invitation URL and neutral styling, editable invitation/user guides, refreshing displayed guides after toggles, ownership and chat validation, forum destinations, addressed commands, privacy-mode fallback, quiet handling of unrelated messages, keeping administrator state private, and combined Help length validation with safe rendering/refresh of oversized legacy settings.
- `php tests/media.php`: real FFmpeg processing of landscape MP4, 480x854 portrait Shorts and higher-resolution WebM; isolated cookie copies, YouTube proxy forwarding and real multipart HTTP file upload against a local test endpoint.
- `php tests/audio.php`: real AAC, Opus and combined video-to-MP3 conversion, complete audio duration, private cookie copies, proxy forwarding, saved format validation and file size enforcement.
- `php tests/quality_worker.php`, `--audio` and `--shorts`: link inspection, conversion of the same text message into a poster, quality/audio choice, button removal, actual multipart delivery, duration and the blue source link, then clearing the poster caption. A strict local API rejects text edits on photo messages.
- `php tests/quality_worker.php --legacy-caption` repeats the real upload path after upgrading a database with the historical caption, checking that rich metadata and the blue URL button belong to the delivered video.
- `php tests/quality_worker.php --group` and `--group --audio`: real MP4/MP3 multipart delivery into the original group topic. `--group --disabled` verifies the real worker rejects queued group work before invoking YouTube and edits the existing group status without uploading or redirecting to private chat.
- PHP and Bash syntax, Compose configuration and production Docker build.
- `bash tests/manager.sh`: real step-process I/O, failure recovery, plain/color output, mixed service state, submenu Back/EOF behavior and operation-lock contention.
- `test_maintenance.py`: private backup round trips, invalid archive rejection, settings validation, stopped-service preservation and rollback on failed settings/restore application.
- `test_updater.py`: recovery and rollback, tracking a reopened progress menu, and rebuilding outdated or missing services even when the Git checkout is already current. Rebuilding is skipped only when both running application versions also match.
- `integration_maintenance.py`: after the production image build, an isolated CI Compose volume verifies actual SQLite/cookie backup and restore with runtime UID 10001; it makes no Telegram requests.

The media test replaces YouTube transport with local fixtures and Telegram with a local HTTP endpoint. It does not prove that a deployment's IP can access YouTube or that its bot token can upload to Telegram.

For deployment verification, start a conversation with the bot, then select **Diagnostics → Delivery test** in the server manager and paste a public YouTube URL. Success means Telegram accepted the actual MP4 upload to the first configured administrator.

When a job fails, `docker compose logs --tail=80 worker` includes a sanitized error and its processing stage. The administrator's `/health` command shows the corresponding Persian explanation and private usage statistics. Tokens and signed media URLs are redacted from process diagnostics.
