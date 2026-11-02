#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
PROJECT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
cd "$PROJECT_DIR"
trap 'echo "خطا در خط $LINENO؛ نصب کامل نشده است." >&2' ERR

require_docker() {
  if ! command -v docker >/dev/null; then
    echo 'در حال نصب Docker از نصب‌کننده رسمی…'
    command -v curl >/dev/null || { apt-get update; apt-get install -y curl ca-certificates; }
    installer=$(mktemp)
    curl --fail --show-error --silent --location https://get.docker.com -o "$installer"
    sh "$installer"
    rm -f "$installer"
  fi
  docker compose version >/dev/null || { echo 'افزونه Docker Compose نصب نیست؛ آن را نصب کنید.'; exit 1; }
  docker info >/dev/null
}

configure() {
  if [[ -f .env ]]; then
    echo 'فایل .env موجود است؛ تنظیمات و توکن قبلی حفظ می‌شوند.'
    return
  fi
  read -rsp 'توکن ربات: ' bot_token; echo
  [[ "$bot_token" =~ ^[0-9]+:[A-Za-z0-9_-]+$ ]] || { echo 'توکن نامعتبر است.'; exit 1; }
  read -rp 'شناسه عددی ادمین‌ها، جداشده با کاما: ' admin_ids
  [[ "$admin_ids" =~ ^[1-9][0-9]*(,[1-9][0-9]*)*$ ]] || { echo 'شناسه ادمین نامعتبر است.'; exit 1; }
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
  echo 'سرویس‌ها راه‌اندازی شدند. در تلگرام /start و سپس یک لینک کوتاه بفرستید.'
  echo 'منوی ادمین: /admin — شناسه شما: /id'
}

update_bot() {
  [[ -d .git ]] || { echo 'برای Update پروژه را با git clone دریافت کنید.'; return; }
  [[ -z "$(git status --porcelain)" ]] || { echo 'تغییرات محلی وجود دارد؛ ابتدا آن‌ها را بررسی کنید.'; return; }
  git pull --ff-only
  require_docker
  docker compose build
  docker compose up -d --no-build
}

[[ $EUID -eq 0 ]] || { echo 'با sudo bash install.sh اجرا کنید.'; exit 1; }
while true; do
  printf '\nCiro Downloader\n1) نصب / راه‌اندازی\n2) روشن کردن سرویس\n3) توقف سرویس\n4) راه‌اندازی مجدد\n5) لاگ‌ها\n6) وضعیت\n7) Update\n0) خروج\n'
  read -rp 'انتخاب: ' choice || exit 0
  case "$choice" in
    1) install_bot ;;
    2) require_docker; docker compose up -d ;;
    3) require_docker; docker compose stop ;;
    4) require_docker; docker compose restart ;;
    5) require_docker; docker compose logs --tail=100 ;;
    6) require_docker; docker compose ps ;;
    7) update_bot ;;
    0) exit 0 ;;
    *) echo 'گزینه نامعتبر است.' ;;
  esac
done
