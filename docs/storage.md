# Disk use and cleanup

The application releases completed single-download work directories after success, failure and interrupted-worker recovery. A validated video discards its downloaded source before upload, keeping the ready MP4 only. Beeg’s generated JPEG thumbnail lives in the same temporary directory.

- Delivered music batches release their prepared audio and covers as soon as delivery completes. Back uses saved menu state and Telegram message IDs. A deliberately stopped batch keeps its files so Continue works; old abandoned batches expire after 25 hours. A periodic worker pass also clears delivered batches from older versions without touching active or queued preparations.
- Gallery slides are removed only after a valid Telegram file ID is persisted. Previous/Next uses that ID. Unsent slides remain private for the existing 24-hour navigation window. The worker also releases confirmed slides left by earlier versions. Missing/rejected Telegram IDs keep the local file until expiry so navigation is still possible.
- Downloader stdout is bounded to 16 MiB and oversized metadata fails; stderr keeps only its last 64 KiB in memory. Both streams are drained continuously; no growing stdout/stderr log files are written per process. Error diagnostics retain the sanitized tail.
- Docker service logs already rotate at 10 MiB × 3 files per container. Manager step logs now keep at most 2 MiB each, the 20 newest completed logs plus the current step, and expire after seven days when the manager runs again. Output is still displayed live and command failures retain their exit codes.
- Idle worker lanes read the queue without taking a SQLite writer lock. The queue has a status/phase index so inactive waiting menus do not force a full scan each time.

The local Telegram API owns a separate session/cache volume. [Shared local uploads](large-uploads.md#avoiding-a-second-upload-copy-on-existing-installations) avoid a second full upload copy for future requests after the shared mount is activated. These changes do not reclaim historical files in that separate volume, Docker build cache, administrator backups, or system journals. Inspect their size before a separate maintenance operation; never remove the database, cookies, radio keys or API session to free space.

No deployment or server cleanup is performed by publishing code to GitHub. Apply the update on the deployment before expecting disk or performance changes there.
