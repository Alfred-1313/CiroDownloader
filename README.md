<div align="center">

# Ciro Downloader

YouTube and Instagram media through Telegram.

![PHP 8.3](https://img.shields.io/badge/PHP-8.3-777BB4?style=flat-square&logo=php&logoColor=white)
![Docker](https://img.shields.io/badge/Docker-ready-2496ED?style=flat-square&logo=docker&logoColor=white)

</div>

Download YouTube videos and Shorts with a quality or MP3 choice. Instagram photos, videos and Reels arrive directly; multi-slide posts become linked articles. See [Instagram setup and privacy](docs/instagram.md).

## Install

Run this command on your Linux server as root:

```bash
bash <(curl -fsSL https://raw.githubusercontent.com/Alfred-1313/CiroDownloader/refs/heads/main/install.sh)
```

The installer asks for your Telegram bot token and admin ID, then starts the bot automatically.

For uploads over 50 MB, obtain a Telegram **API ID** and **API hash**, then enable the [local Bot API with Docker](docs/large-uploads.md). The application limit is 1990 MB.
