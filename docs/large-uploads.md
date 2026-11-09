# Large Telegram uploads with Docker

The hosted Telegram Bot API has a 50 MB upload limit. The optional local Bot API is built from the official Telegram source and accepts uploads up to 2000 MB. Ciro Downloader sets its limit to 1990 MB to leave a margin. The server needs enough free disk space for downloading, preparing, and uploading the file. Available space is checked during processing. Current setups share the prepared files read-only with the local API and send their local paths, avoiding another upload copy. Older multipart setups still need room for an additional copy; smaller files do not require the full 1990 MB limit in free space.

## Required Telegram credentials

Uploads over 50 MB require a running local Bot API server and **both an API ID and an API hash**. The normal bot token alone is not sufficient for this feature. Uploads through the hosted API continue to work within the default 49 MB application limit without these additional credentials.

1. Sign in to [my.telegram.org/apps](https://my.telegram.org/apps) with your Telegram account.
2. Open **API development tools** and create an application if you do not already have one.
3. Copy the application's **API ID** (a positive number) and **API hash** (32 hexadecimal characters). These belong to your Telegram application; the API ID is different from your administrator user ID, and the API hash is different from the BotFather bot token.
4. Enter them only in the server manager when prompted below. The hash input is hidden. Never put real credentials in the Dockerfile, Compose files, Git, or shared documentation.

## Enable the Docker service

Use the managed Linux installation as root, with Docker Engine and the Compose plugin available. Update Ciro Downloader first, start both `bot` and `worker` through **Services > Start**, and wait for any active download to finish.

```bash
CiroDownloader large-uploads
```

This opens the credential prompts directly. Alternatively, run `CiroDownloader settings` and choose **4 — Large uploads**, or run `bash manager.sh` as root from the installation checkout and open **Settings**.

1. Enter the API ID and API hash obtained above. Do not replace the existing bot token with either value.
2. Wait for the first build and migration to finish. Docker output, compiler progress, and migration stages are shown directly in the terminal and retained in the private step log. The build compiles the official local server and may take a while. The bot is briefly paused. The manager logs the bot out of the hosted API, verifies local login, and restarts the bot and worker against the local API. Returning to the hosted Bot API after logout requires Telegram's specified 10-minute wait.
3. After setup reports success, request a video whose final file is over 50 MB but within 1990 MB, select its quality, and confirm that Telegram receives the complete file.

To update the manager in an existing installation and open setup directly, first finish any currently running manager operation, then run as root:

```bash
cd /opt/ciro-downloader && git pull --ff-only origin main && bash manager.sh large-uploads
```

This preserves `.env` and stored data. If Git reports local changes or diverging history, resolve that message before retrying; do not reset the checkout or delete the database to enable large uploads.

Changing `MAX_FILE_BYTES` alone cannot increase the hosted API's limit. Starting `compose.telegram-api.yaml` alone also does not migrate the bot; use the manager so logout, login, and configuration changes run in order.

### If the build appears stuck

The first build compiles Telegram's C++ server. It can take tens of minutes or longer on a small server; the Dockerfile uses one compiler job to limit memory use. Docker's build output is streamed to the terminal, including the compiler's own percentage when available. The manager does not estimate a separate compiler percentage. Keep the SSH session open while setup runs.

The manager prints a **Private log** path before starting each step. Keep the installation running and open a second SSH session to inspect that exact file:

```bash
tail -f /var/log/ciro-downloader/step-XXXXXXXX.log
```

Replace `step-XXXXXXXX.log` with the filename printed by the manager. The large-upload step records Docker's plain build output, compiler activity, and migration stages as they happen. To check which processes are using CPU without showing command arguments or credentials:

```bash
ps -eo comm,pcpu,etime --sort=-pcpu | head -12
```

Compiler processes such as `cc1plus` using CPU indicate ongoing work. Low CPU use alone does not identify a failure: the build may be downloading dependencies. Check the build log for progress or errors before retrying. Older manager versions hide compiler output, so their step log may show only the initial build message. Update before a future attempt; do not start a second setup or update the checkout while the current migration is running.

## Docker configuration managed by setup

| Setting | Purpose |
| --- | --- |
| `Dockerfile.telegram-api` | Builds the pinned official server and starts it with `--local`. |
| `compose.telegram-api.yaml` | Runs the separate `telegram-api` service on the bot's Docker network. |
| `/var/lib/ciro-downloader-telegram-api/api.env` | Stores `TELEGRAM_API_ID` and `TELEGRAM_API_HASH` privately for that service. The manager creates this file; do not commit it. |
| `BOT_API_URL=http://ciro-telegram-api:8081` | Routes bot and worker requests to the local API; setup writes it to the bot's `.env`. |
| `MAX_FILE_BYTES=1990000000` | Sets the application limit to 1990 MB after migration. |

The API is reachable by the bot over its private Docker network and on server loopback for the updater. Its session and temporary files are in the dedicated `ciro-downloader-telegram-api-data` volume; the API credentials are stored in `/var/lib/ciro-downloader-telegram-api/api.env` with restricted permissions. Keep the volume and credentials private. The API session volume is not part of normal bot-data backups; to migrate to another server, follow Telegram's official instructions for transferring a local Bot API session.

If setup reports a failure after logout, keep the bot paused and choose **Settings > Large uploads** again. Correct the API credentials if necessary. The setup continues from the saved migration stage. Do not start the bot against both APIs simultaneously.

Source: https://github.com/tdlib/telegram-bot-api#moving-a-bot-to-a-local-server


## Avoiding a second upload copy on existing installations

New large-upload setups enable `LOCAL_API_SHARED_STORAGE=1` after starting the local API with the read-only `ciro-downloader_data:/app/storage` mount. The application sends `file:///app/storage/...` only to the managed local API, only for readable files inside that volume, and deletes the application’s temporary files after Telegram confirms the request. Cloud API uploads stay multipart.

For an existing local API installation, finish active jobs and stop `bot`, `worker` (and `radio` if running), then recreate the local API **from the updated checkout** before setting `LOCAL_API_SHARED_STORAGE=1` in `.env` and recreating the application services:

```bash
docker compose stop bot worker
docker compose -f compose.telegram-api.yaml up -d --no-build telegram-api
# Set LOCAL_API_SHARED_STORAGE=1 in .env only after the command above succeeds.
docker compose up -d --no-build --force-recreate bot worker
```

If radio was running, stop it before this operation and restart it afterward. Keep the setting at `0` if the shared mount has not been applied. The setting does not delete the local API’s older upload/download cache or its session database. Do not delete that volume while the API is running; historical API cache reclamation requires a separate, stopped-service maintenance operation. See [storage maintenance](storage.md).
