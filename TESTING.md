# Verification

Validated locally on PHP 8.3.32:

- `php tests/run.php`: 28 checks passed, including URL allowlisting, forged admin callbacks, maintenance mode, caption/button persistence and reset, edit cancellation, duplicate updates, per-user admission, queue capacity and subprocess success/failure/timeout.
- All six PHP files passed `php -l`.
- `bash -n install.sh` passed.
- Compose YAML parsed successfully; poller and worker share the persistent data volume.
- Real `.env`, tokens, keys and generated database files are excluded from version control.

Not yet validated: building the Docker image, running the installer on the target server, and real YouTube-to-Telegram delivery. These require the deployment environment and a configured bot. The README contains the manual acceptance checklist.
