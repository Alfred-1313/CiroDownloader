# Ciro Downloader

A focused Telegram bot that downloads YouTube videos and Shorts as MP4 files up to 480p. It is written in PHP 8.3, uses SQLite for a durable queue, and runs the polling bot and download worker as isolated Docker services.

## What it does

- Accepts YouTube watch, Shorts, live-page and `youtu.be` links.
- Downloads one video per request and sends it back with audio as an MP4.
- Selects MP4 video up to 480p and rejects playlists, live streams and videos longer than 20 minutes.
- Shows clear progress states: queued, downloading, uploading and failed.
- Limits one active request per user, 20 active/queued jobs globally and 15 seconds between requests.
- Keeps the admin panel private to the IDs in `ADMIN_IDS`.
- Gives admins a real Telegram reply keyboard for the main controls and inline controls for detailed settings.
- Lets admins pause the service and edit/reset English messages, buttons and the video caption.

## Requirements

- Ubuntu 24.04 or Debian 12 recommended
- At least 2 GB RAM and several GB of free disk space
- Root or sudo access
- A Telegram bot token from [@BotFather](https://t.me/BotFather)
- The numeric Telegram ID of each administrator
- Outbound access to Telegram, YouTube, Docker Hub and the OS package repositories

No domain, SSL certificate or inbound web-server port is required. The bot uses long polling.

## Install

Clone the repository and run the installer as root:

```bash
git clone https://github.com/Alfred-1313/CiroDownloader.git
cd CiroDownloader
sudo bash install.sh
```

The installer installs Docker if needed, asks for the bot token and administrator IDs, creates a protected `.env`, builds the image, starts the `bot` and `worker` services, and checks the token and webhook state.

## Manual Docker setup

```bash
cp .env.example .env
chmod 600 .env
nano .env
docker compose up -d --build
docker compose logs --tail=100
```

Example configuration:

```dotenv
BOT_TOKEN=123456789:replace_with_your_token
ADMIN_IDS=123456789,987654321
BOT_API_URL=https://api.telegram.org
MAX_FILE_BYTES=49000000
STORAGE_DIR=/app/storage
YT_DLP_BIN=/opt/yt/bin/yt-dlp
```

Never commit the real `.env` file, bot token or SQLite database. They are excluded by `.gitignore` and runtime data is stored in a private Compose volume.

## Admin controls

- Send `/start` to see the main menu.
- Send `/id` to display your Telegram ID.
- Send `/admin` to open the admin panel.
- Use the reply keyboard to pause or resume the downloader.
- Use **Edit messages** or **Edit buttons** to customize copy.
- Use `/cancel` to leave an editing flow.

The Help button gives immediate callback feedback and then shows the full guide in the chat. Admin controls are hidden from non-admin users and callback authorization is checked server-side.

## Service commands

```bash
docker compose up -d
docker compose stop
docker compose restart
docker compose ps
docker compose logs --tail=100
docker compose down
```

The installer also provides an interactive menu with install, start, stop, restart, logs, status and update actions. Updates require a clean Git worktree and preserve `.env` plus the persistent data volume.

## Architecture

- `bin/bot.php` polls Telegram and manages user/admin interactions.
- `bin/worker.php` claims queued jobs and performs downloads.
- `src/Core.php` contains configuration, Telegram transport, SQLite storage and URL validation.
- `src/Bot.php` contains the interaction model, copy and admin settings.
- `src/Download.php` runs `yt-dlp`, FFmpeg and ffprobe with timeouts and size limits.
- `storage/` is a private persistent Docker volume and is not part of the repository.

## Testing

With PHP 8.3 and the required extensions installed:

```bash
php tests/run.php
find src bin tests -name '*.php' -exec php -l {} +
bash -n install.sh
docker compose config
```

The test suite covers URL allowlisting, admin authorization, maintenance mode, copy persistence/reset, editing cancellation, duplicate updates, queue capacity and subprocess timeout/failure handling.

## Limitations

- Telegram's public Bot API has a practical upload limit of about 49 MB in this configuration.
- Private, age-restricted, region-blocked or unavailable videos may fail.
- YouTube availability and `yt-dlp` compatibility can change over time.
- Each bot token should have one polling instance.

## License

This project is provided as-is. Review YouTube and Telegram terms, copyright law and local regulations before deploying it.
