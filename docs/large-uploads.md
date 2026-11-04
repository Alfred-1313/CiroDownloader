# Large Telegram uploads with Docker

The hosted Telegram Bot API has a 50 MB upload limit. The optional local Bot API is built from the official Telegram source and accepts uploads up to 2000 MB. Ciro Downloader sets its limit to 1990 MB to leave a margin. The server needs enough free disk space for downloading, preparing, and uploading the file. Available space is checked during processing, and a large upload needs room for an additional copy; smaller files do not require the full 1990 MB limit in free space.

## Required Telegram credentials

Uploads over 50 MB require a running local Bot API server and **both an API ID and an API hash**. The normal bot token alone is not sufficient for this feature. Uploads through the hosted API continue to work within the default 49 MB application limit without these additional credentials.

1. Sign in to [my.telegram.org/apps](https://my.telegram.org/apps) with your Telegram account.
2. Open **API development tools** and create an application if you do not already have one.
3. Copy the application's **API ID** (a positive number) and **API hash** (32 hexadecimal characters). These belong to your Telegram application; the API ID is different from your administrator user ID, and the API hash is different from the BotFather bot token.
4. Enter them only in the server manager when prompted below. The hash input is hidden. Never put real credentials in the Dockerfile, Compose files, Git, or shared documentation.

## Enable the Docker service

Use the managed Linux installation as root, with Docker Engine and the Compose plugin available. Update Ciro Downloader first, start both `bot` and `worker` through **Services > Start**, and wait for any active download to finish.

```bash
CiroDownloader settings
```

Alternatively, run `bash manager.sh` as root from the installation checkout and open **Settings**.

1. Choose **4 — Large uploads**.
2. Enter the API ID and API hash obtained above. Do not replace the existing bot token with either value.
3. Wait for the first build and migration to finish. The build compiles the official local server and may take a while. The bot is briefly paused. The manager logs the bot out of the hosted API, verifies local login, and restarts the bot and worker against the local API. Returning to the hosted Bot API after logout requires Telegram's specified 10-minute wait.
4. After setup reports success, request a video whose final file is over 50 MB but within 1990 MB, select its quality, and confirm that Telegram receives the complete file.

Changing `MAX_FILE_BYTES` alone cannot increase the hosted API's limit. Starting `compose.telegram-api.yaml` alone also does not migrate the bot; use the manager so logout, login, and configuration changes run in order.

### If the build appears stuck

The first build compiles Telegram's C++ server. It can take tens of minutes or longer on a small server; the Dockerfile uses one compiler job to limit memory use. The manager shows the current step and elapsed time, not a compiler percentage. An animated indicator alone does not prove that compilation is progressing.

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

