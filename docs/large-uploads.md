# Large Telegram uploads

The hosted Telegram Bot API has a 50 MB upload limit. The optional local Bot API is built from the official Telegram source and accepts uploads up to 2000 MB. Ciro Downloader sets its limit to 1990 MB to leave a margin. The server needs enough free disk space for downloading, preparing, and uploading the file. Available space is checked during processing, and a large upload needs room for an additional copy; smaller files do not require the full 1990 MB limit in free space.

1. Obtain your own API ID and API hash at https://my.telegram.org/apps. Do not share the hash or bot token.
2. Update Ciro Downloader, run `CiroDownloader`, then choose **Settings > Large uploads**. Enter the API ID and hidden API hash on the server.
3. Wait for the first build and migration to finish. The build compiles the official local server and may take a while. The bot is briefly paused. The hosted Bot API cannot immediately be used after logout; Telegram specifies a 10-minute wait.
4. Send a suitable video over 50 MB to the bot and check that Telegram receives it.

The API is reachable by the bot over its private Docker network and on server loopback for the updater. Its session and temporary files are in the dedicated `ciro-downloader-telegram-api-data` volume; the API credentials are stored in `/var/lib/ciro-downloader-telegram-api/api.env` with restricted permissions. Keep the volume and credentials private. The API session volume is not part of normal bot-data backups; to migrate to another server, follow Telegram's official instructions for transferring a local Bot API session.

If setup reports a failure after logout, keep the bot paused and choose **Settings > Large uploads** again. Correct the API credentials if necessary. The setup continues from the saved migration stage. Do not start the bot against both APIs simultaneously.

Source: https://github.com/tdlib/telegram-bot-api#moving-a-bot-to-a-local-server

