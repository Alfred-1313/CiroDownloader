#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
PROJECT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
cd "$PROJECT_DIR"
trap 'echo "Installation failed at line $LINENO." >&2' ERR

require_docker() {
  if ! command -v docker >/dev/null; then
    echo 'Installing Docker with the official installer…'
    command -v curl >/dev/null || { apt-get update; apt-get install -y curl ca-certificates; }
    installer=$(mktemp)
    curl --fail --show-error --silent --location https://get.docker.com -o "$installer"
    sh "$installer"
    rm -f "$installer"
  fi
  docker compose version >/dev/null || { echo 'Docker Compose is not available. Install the Compose plugin first.'; exit 1; }
  docker info >/dev/null
}

configure() {
  if [[ -f .env ]]; then
    echo 'An existing .env file was found; current settings and tokens will be preserved.'
    return
  fi
  read -rsp 'Bot token: ' bot_token; echo
  [[ "$bot_token" =~ ^[0-9]+:[A-Za-z0-9_-]+$ ]] || { echo 'Invalid bot token.'; exit 1; }
  read -rp 'Administrator Telegram IDs, comma-separated: ' admin_ids
  [[ "$admin_ids" =~ ^[1-9][0-9]*(,[1-9][0-9]*)*$ ]] || { echo 'Invalid administrator ID list.'; exit 1; }
  {
    printf 'BOT_TOKEN=%s\nADMIN_IDS=%s\n' "$bot_token" "$admin_ids"
    printf 'BOT_API_URL=https://api.telegram.org\nMAX_FILE_BYTES=49000000\nSTORAGE_DIR=/app/storage\nYT_DLP_BIN=/opt/yt/bin/yt-dlp\n'
  } > .env
  chmod 600 .env
  unset bot_token admin_ids
}

install_bot() {
  require_docker
  configure
  docker compose build
  # Test the token without displaying it or consuming updates.
  docker compose run --rm --no-deps bot php -r 'require "src/Core.php"; $api=new Zero\Telegram(Zero\Config::load()); $me=$api->call("getMe"); $hook=$api->call("getWebhookInfo"); if(!empty($hook["url"])) {fwrite(STDERR,"Webhook exists; use a dedicated bot token or remove its webhook first.\n");exit(1);} echo "Bot: @".$me["username"].PHP_EOL;'
  docker compose up -d --no-build
  docker compose ps
  echo 'Services are running. Send /start in Telegram, then send a YouTube link.'
  echo 'Admin panel: /admin — your Telegram ID: /id'
}

update_bot() {
  [[ -d .git ]] || { echo 'Clone the project with Git before using Update.'; return; }
  [[ -z "$(git status --porcelain)" ]] || { echo 'Local changes exist; review them before updating.'; return; }
  git pull --ff-only
  require_docker
  docker compose build
  docker compose up -d --no-build
}

[[ $EUID -eq 0 ]] || { echo 'Run this installer with sudo bash install.sh.'; exit 1; }
while true; do
  printf '\nCiro Downloader\n1) Install / start\n2) Start services\n3) Stop services\n4) Restart services\n5) Logs\n6) Status\n7) Update\n0) Exit\n'
  read -rp 'Choose an option: ' choice || exit 0
  case "$choice" in
    1) install_bot ;;
    2) require_docker; docker compose up -d ;;
    3) require_docker; docker compose stop ;;
    4) require_docker; docker compose restart ;;
    5) require_docker; docker compose logs --tail=100 ;;
    6) require_docker; docker compose ps ;;
    7) update_bot ;;
    0) exit 0 ;;
    *) echo 'Invalid option.' ;;
  esac
done
