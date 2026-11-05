# Server manager

The English terminal manager follows the visual style of the [Ciro reference](https://github.com/Alfred-1313/Ciro/blob/8f0787789ee4a2cdf0a3ba7905d65ff1f0e5e683/install.sh): blue rules, a clear title, numbered rows with short descriptions, and step feedback. Only CiroDownloader was changed. Its command is **`CiroDownloader`**, distinct from `Ciro`.

Run the README install command to fetch the current manager. Opening the menu as root installs its shortcut; `bash manager.sh` also works from the checkout.

```text
CiroDownloader
CiroDownloader status
CiroDownloader update
CiroDownloader settings
CiroDownloader large-uploads
CiroDownloader backup
CiroDownloader restore /absolute/path/backup.tar.gz
```

The main menu groups the existing numbered actions into Setup, Operations, Data & Settings, and Tools. Remove is separated at the bottom; all action numbers and command shortcuts are unchanged. Services contains start, stop, restart and separate bot/worker logs. Submenus show their location, and Back returns one level. Invalid selections are corrected at the same prompt without an extra Enter; end-of-input exits.

The layout adapts to terminals from 28 columns upward, wrapping descriptions, long paths, prompts and step results. Width is refreshed when a screen is drawn. `--plain` disables color and screen redraw; `NO_COLOR` also disables colors. `bash manager.sh --preview` renders the interface without requiring root or changing the server. [Desktop and mobile-width previews](docs/manager-preview.html) use the actual shell renderer with simulated service status. Regenerate them with `python3 docs/render_manager_preview.py`.

Progress counts completed steps, rather than guessing a Docker build percentage. Application builds stream their output while retaining the private log. Errors return to the menu with the failed step and a private log under `/var/log/ciro-downloader`. A failed Compose status query is shown as **Unknown**, rather than **Not installed**. Maintenance shares a lock with the Telegram updater so the two cannot change the installation simultaneously.

First-time setup explains where to obtain a bot token, keeps it hidden, and lets you correct a typo without restarting the installation. Administrator IDs are numeric user IDs, not usernames. Pressing Enter at either prompt cancels setup before any configuration is saved.

Large-upload setup streams Docker build output and migration stages directly to the terminal while retaining the private log. The first build can take tens of minutes or longer on a small server. `CiroDownloader large-uploads` opens the setup directly, with the same hidden credential prompts as **Settings > Large uploads**.

## Settings

Settings edits the bot token, comma-separated admin IDs, and optional HTTP(S)/SOCKS proxy. Token and proxy input are hidden and are never passed as command arguments. Enter keeps the current value; `clear` disables the proxy. A configuration snapshot is kept before a change. Previously running services are recreated to load the new environment; stopped services stay stopped. A new token is checked with Telegram before refreshing active services. Failed application restores the previous configuration.

**Settings > Large uploads** enables the local Telegram Bot API with Docker for files over 50 MB. It requires your own Telegram API ID and API hash from [my.telegram.org/apps](https://my.telegram.org/apps), in addition to the bot token. Follow the [Docker setup and credential requirements](docs/large-uploads.md) before enabling it.

## Backup and restore

Backups are kept in `/var/backups/ciro-downloader` with private permissions. They contain the SQLite database, cookies and `.env`, including the bot token. Temporary downloads and process locks are excluded. Backup briefly stops bot/worker for a consistent database snapshot, then resumes only services that were already running.

Restore accepts this manager's `.tar.gz` format, validates archive paths, file types, limits and SQLite integrity, and requires `RESTORE`. It saves a recovery backup before replacing this installation's data and configuration. Failed restoration stops partially started services before putting the previous state back. Historical pending downloads and update requests are not replayed; users can submit a fresh link. The source code is kept at its current version. The Telegram updater's source/image rollback archives use a different format and are managed through `/update`.

The data volume must be the managed `ciro-downloader_data` mounted at `/app/storage`; backups or restoration refuse another Compose project or an external storage mount. The current archive limit is 512 MB of application data. Removal requires `DELETE` and retains source code, private logs and backups.

## YouTube checks

Cookies accepts any readable `.txt` export path. Terminal import validates its structure; use the Telegram import panel for access verification before replacement, or choose Diagnostics → Delivery test for an actual YouTube download and Telegram upload. Server checks do not send messages.
