<div align="center">

# Ciro Downloader

Simple YouTube video downloads through Telegram.

![PHP 8.3](https://img.shields.io/badge/PHP-8.3-777BB4?style=flat-square&logo=php&logoColor=white)
![Docker](https://img.shields.io/badge/Docker-ready-2496ED?style=flat-square&logo=docker&logoColor=white)

</div>

## Features

- YouTube videos and Shorts as MP4
- Up to 480p with audio
- Queue and download status updates
- English Telegram interface
- Private admin panel
- Docker + SQLite

## Quick install

Requirements: Linux, Docker, a Telegram bot token and your Telegram user ID.

```bash
git clone https://github.com/Alfred-1313/CiroDownloader.git
cd CiroDownloader
sudo bash install.sh
```

The installer asks for your bot token and admin IDs, then starts the bot automatically.

## Bot commands

```text
/start   Open the main menu
/id      Show your Telegram ID
/admin   Open the admin panel
```

Only IDs listed in `ADMIN_IDS` can access the admin panel.

## Manual setup

```bash
cp .env.example .env
chmod 600 .env
nano .env
docker compose up -d --build
```

Required values:

```dotenv
BOT_TOKEN=your_bot_token
ADMIN_IDS=123456789
```

## Useful commands

```bash
docker compose ps
docker compose logs --tail=100
docker compose restart
docker compose stop
```

## License

Provided as-is. Follow YouTube and Telegram rules when deploying.
