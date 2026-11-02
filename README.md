# Ciro Downloader

A lightweight Telegram bot that downloads YouTube videos and Shorts as MP4 files up to 480p.

## Features

- English Telegram interface
- YouTube video and Shorts support
- Queue with download status messages
- Private admin panel
- Docker-based installation
- SQLite storage

## Install

Requirements: Linux server, Docker, a Telegram bot token and your Telegram user ID.

```bash
git clone https://github.com/Alfred-1313/CiroDownloader.git
cd CiroDownloader
sudo bash install.sh
```

The installer asks for:

- `BOT_TOKEN` — your Telegram bot token
- `ADMIN_IDS` — comma-separated Telegram admin IDs

After installation, open the bot and send:

```text
/start
```

Admins can open the panel with:

```text
/admin
```

## Manual configuration

```bash
cp .env.example .env
chmod 600 .env
nano .env
docker compose up -d --build
```

## Useful commands

```bash
docker compose ps
docker compose logs --tail=100
docker compose restart
docker compose stop
```

## License

Provided as-is. Check YouTube and Telegram rules before deployment.
