# Disk use and cleanup

After Telegram confirms a delivery, the application removes its local file and artwork before optional status cleanup and bookkeeping. This applies to single video/audio, each track of a batch, and each completed ZIP. The source video is removed once the ready MP4 is validated. A failure, interrupted-worker recovery, or post-send metadata failure also clears the disposable work directory. Back/Share and previously viewed gallery slides reuse Telegram file IDs.

Unsent gallery slides remain for the 24-hour navigation window; deliberately paused batches remain for Continue and expire after 25 hours. Active radio/preparation files are not deleted while needed. These are pending work, not permanent copies of delivered files. Downloader output is bounded and drained in memory, without per-process log files.

## Updates and rollback

Both `CiroDownloader update` and Telegram updates use the same updater. Before replacing code or image it saves a consistent database/configuration/cookie archive and tags the current application image. Runtime downloads, media caches and radio keys are excluded. Only after the new application passes its health check is the recovery record switched to the immediately previous version. Older **automatic** `pre-update-*.tar.gz` archives and `ciro-downloader:backup-*` image tags are then removed. A failed update preserves recovery artifacts. Pending recovery/completion references remain protected. Missing or invalid recovery records prevent deletion instead of guessing.

Manual `ciro-backup-*`/pre-restore archives and unrelated Docker images/volumes are preserved. Older command-line-manager backups are indistinguishable from manual backups and must be reviewed separately. Docker build cache is separate; update retention does not run a system-wide Docker prune.

## Errors without logs

Docker logging is disabled for bot/worker/radio, the optional token provider and local API. The manager displays explicitly requested terminal output without creating step logs. The systemd updater discards stdout/stderr, and long builds no longer spool output to a temporary file. Recreate existing containers to apply Docker logging changes; restarting alone does not change their logging driver. Recreate the optional local API separately with its Compose file if used.

Errors send a short private alert (service, reason, validated source causes) to current bootstrap-authorized bot administrators. No URLs, raw exception output, cookies or tokens enter those alerts. Error-history buttons are removed, old callbacks show the new policy, and the database migration removes legacy `recent_errors` and `last_job_*` diagnostic rows. In-flight error details and duplicate suppression live only in process memory. Ordinary success/debug/timing logs are removed; numeric aggregate statistics remain.

Notifications are best-effort, bounded and not queued on disk. If Telegram or the network is unavailable, an alert can be lost; a failed alert never recursively creates another alert. Historical archives/system journals are not securely erased by the database migration or by disabling future logging.

## Reclaim existing disposable files

After updating the installed source, stop the application, run cleanup, then start it:

```bash
sudo CiroDownloader stop
sudo CiroDownloader cleanup
sudo CiroDownloader start
```

Cleanup shares the maintenance lock and refuses to run while bot, worker or radio is running. It deletes interrupted `downloads/job-*` folders, the regenerable `yt-cache`, historical manager `step-*.log` files, and obsolete automatic update artifacts under the retention rules above. It never removes the database, cookies, unsent galleries, paused batches, radio files, API sessions, or Docker volumes. `sudo python3 -B /opt/ciro-downloader/scripts/cleanup.py` previews disposable-file counts without deleting anything; `--apply` performs cleanup.

If the disk is too full to build an update, first inspect its use:

```bash
df -h
sudo docker system df
sudo du -sh /var/backups/ciro-downloader /var/log/ciro-downloader /var/lib/docker
```

`sudo docker builder prune -f` removes unused build cache, not application volumes or databases, but subsequent builds may take longer. Run the update once enough space is available. If more emergency space is needed, stop Ciro and remove only interrupted job directories and regenerable cache using its current worker image:

```bash
cd /opt/ciro-downloader
sudo CiroDownloader stop
sudo docker compose run --rm --no-deps --entrypoint sh worker -c 'find /app/storage/downloads -mindepth 1 -maxdepth 1 -type d -name "job-*" -exec rm -rf -- {} +; rm -rf -- /app/storage/yt-cache'
```

Then run `sudo CiroDownloader update` and start services. Do not delete `zero.sqlite`, cookies, `.env`, radio keys, or Docker volumes to reclaim space. If backups dominate the disk, inspect the retained version record before deleting any historical archive manually.

The local Telegram API has its own session/cache volume. Shared `/app/storage` uploads avoid a second upload copy when configured as described in [large uploads](large-uploads.md#avoiding-a-second-upload-copy-on-existing-installations). Application cleanup does not erase historical API caches or its session database. Report that volume's size separately before maintenance; never delete the whole API volume as a shortcut.

Publishing code does not deploy it or reclaim space on the user's server.
