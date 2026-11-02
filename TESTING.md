# Verification

GitHub Actions runs these checks on each push:

- `php tests/run.php`: URL validation, admin authorization, queue limits, Persian help alerts, stored settings and actionable/redacted process errors.
- `php tests/media.php`: real FFmpeg processing of landscape MP4, 480x854 portrait Shorts and higher-resolution WebM; real multipart HTTP file upload against a local test endpoint.
- PHP and Bash syntax, Compose configuration and production Docker build.

The media test replaces YouTube transport with local fixtures and Telegram with a local HTTP endpoint. It does not prove that a deployment's IP can access YouTube or that its bot token can upload to Telegram.

For deployment verification, start a conversation with the bot, then select **Run delivery diagnostics** in the server manager and paste a public YouTube URL. Success means Telegram accepted the actual MP4 upload to the first configured administrator.

When a job fails, `docker compose logs --tail=80 worker` and the administrator's `/health` command include a sanitized error and its processing stage. Tokens and signed media URLs are redacted.
