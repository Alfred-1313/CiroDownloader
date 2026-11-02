#!/usr/bin/env bash
# Interactive installer and service manager. Launched by install.sh.
set -Eeuo pipefail
umask 077

REPOSITORY_URL='https://github.com/Alfred-1313/CiroDownloader.git'
INSTALL_DIR='/opt/ciro-downloader'
INSTALLER_VERSION='2.1.0'
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"

[[ $EUID -eq 0 ]] || { echo 'Run this installer with sudo or as root.' >&2; exit 1; }

bootstrap_remote_install() {
  echo 'Preparing Ciro Downloader…'
  if ! command -v git >/dev/null 2>&1; then
    apt-get update
    apt-get install -y git ca-certificates
  fi

  if [[ -d "$INSTALL_DIR/.git" ]]; then
    git -C "$INSTALL_DIR" pull --ff-only
  elif [[ ! -e "$INSTALL_DIR" ]]; then
    git clone "$REPOSITORY_URL" "$INSTALL_DIR"
  else
    echo "Cannot install: $INSTALL_DIR already exists and is not a Git checkout." >&2
    exit 1
  fi

  exec bash "$INSTALL_DIR/install.sh"
}

# A curl + process-substitution install has no project files beside the script.
# Fetch the repository first, then run the same installer from its real folder.
[[ -f "$SCRIPT_DIR/compose.yaml" ]] || bootstrap_remote_install

PROJECT_DIR="$SCRIPT_DIR"
cd "$PROJECT_DIR"

if [[ -t 1 ]] && command -v tput >/dev/null 2>&1 && [[ "${TERM:-dumb}" != 'dumb' ]]; then
  C_RESET="$(tput sgr0)"; C_BOLD="$(tput bold)"
  C_BLUE="$(tput setaf 4)"; C_GREEN="$(tput setaf 2)"
  C_YELLOW="$(tput setaf 3)"; C_RED="$(tput setaf 1)"; C_MUTED="$(tput setaf 7)"
else
  C_RESET=''; C_BOLD=''; C_BLUE=''; C_GREEN=''; C_YELLOW=''; C_RED=''; C_MUTED=''
fi

on_error() {
  local line="$1"
  printf '\n%sError:%s the operation stopped at line %s. Nothing else was changed.\n' "$C_RED" "$C_RESET" "$line" >&2
}
trap 'on_error "$LINENO"' ERR

line() { printf '%s%s%s\n' "$C_MUTED" '------------------------------------------------------------' "$C_RESET"; }
title() { printf '\n%s%s%s\n' "$C_BOLD$C_BLUE" "$1" "$C_RESET"; }
success() { printf '%sDone:%s %s\n' "$C_GREEN" "$C_RESET" "$1"; }
notice() { printf '%sNote:%s %s\n' "$C_YELLOW" "$C_RESET" "$1"; }
warning() { printf '%sWarning:%s %s\n' "$C_RED" "$C_RESET" "$1"; }
pause() { read -rp 'Press Enter to return to the menu…' _; }

docker_ready() {
  command -v docker >/dev/null 2>&1 \
    && docker compose version >/dev/null 2>&1 \
    && docker info >/dev/null 2>&1
}

require_docker() {
  if ! command -v docker >/dev/null 2>&1; then
    title 'Installing Docker'
    command -v curl >/dev/null 2>&1 || { apt-get update; apt-get install -y curl ca-certificates; }
    local installer
    installer="$(mktemp)"
    curl --fail --show-error --silent --location https://get.docker.com -o "$installer"
    sh "$installer"
    rm -f -- "$installer"
  fi
  docker compose version >/dev/null 2>&1 || { warning 'Docker Compose is unavailable. Install the Compose plugin and try again.'; return 1; }
  docker info >/dev/null 2>&1 || { warning 'Docker is not running or cannot be accessed.'; return 1; }
}

service_state() {
  if ! docker_ready; then
    printf '%sDocker unavailable%s' "$C_YELLOW" "$C_RESET"
    return
  fi

  local running
  running="$(docker ps --filter 'label=com.docker.compose.project=ciro-downloader' --format '{{.Names}}' 2>/dev/null || true)"
  if [[ -n "$running" ]]; then
    printf '%sRunning%s' "$C_GREEN" "$C_RESET"
  elif docker image inspect ciro-downloader:local >/dev/null 2>&1; then
    printf '%sStopped%s' "$C_YELLOW" "$C_RESET"
  else
    printf '%sNot installed%s' "$C_MUTED" "$C_RESET"
  fi
}

configure() {
  if [[ -f .env ]]; then
    notice 'Existing .env found. Current token and settings are preserved.'
    return
  fi

  title 'Bot configuration'
  local bot_token admin_ids
  read -rsp 'Telegram bot token: ' bot_token; echo
  [[ "$bot_token" =~ ^[0-9]+:[A-Za-z0-9_-]+$ ]] || { warning 'Invalid bot token.'; return 1; }
  read -rp 'Administrator Telegram IDs (comma-separated): ' admin_ids
  [[ "$admin_ids" =~ ^[1-9][0-9]*(,[1-9][0-9]*)*$ ]] || { warning 'Invalid administrator ID list.'; return 1; }

  {
    printf 'BOT_TOKEN=%s\nADMIN_IDS=%s\n' "$bot_token" "$admin_ids"
    printf 'BOT_API_URL=https://api.telegram.org\nMAX_FILE_BYTES=49000000\nSTORAGE_DIR=/app/storage\nYT_DLP_BIN=/opt/yt/bin/yt-dlp\n'
  } > .env
  chmod 600 .env
  unset bot_token admin_ids
  success 'Configuration saved.'
}

validate_bot() {
  docker compose run --rm --no-deps bot php -r 'require "src/Core.php"; $api=new Zero\Telegram(Zero\Config::load()); $me=$api->call("getMe"); $hook=$api->call("getWebhookInfo"); if(!empty($hook["url"])) {fwrite(STDERR,"A webhook is configured. Use a dedicated bot token or remove its webhook first.\n");exit(1);} echo "Connected as @".$me["username"].PHP_EOL;'
}

install_bot() {
  require_docker || return
  configure || return
  title 'Building Ciro Downloader'
  docker compose build --pull
  validate_bot
  docker compose up -d --no-build
  success 'Ciro Downloader is running.'
  printf 'Open your bot in Telegram and send %s/start%s. Admin panel: %s/admin%s\n' "$C_BOLD" "$C_RESET" "$C_BOLD" "$C_RESET"
}

start_services() {
  require_docker || return
  configure || return
  docker compose up -d
  success 'Services started.'
}

stop_services() {
  require_docker || return
  docker compose stop
  success 'Services stopped. Data and settings were kept.'
}

restart_services() {
  require_docker || return
  docker compose restart
  success 'Services restarted.'
}

show_logs() {
  require_docker || return
  docker compose logs --tail=100
}

show_status() {
  title 'Service status'
  if ! docker_ready; then
    notice 'Docker is unavailable.'
    return
  fi
  docker compose ps
}

update_bot() {
  [[ -d .git ]] || { warning 'This installation is not a Git checkout.'; return; }
  [[ -z "$(git status --porcelain)" ]] || { warning 'Local changes exist. Commit or discard them before updating.'; return; }
  title 'Updating Ciro Downloader'
  git pull --ff-only
  require_docker || return
  docker compose build --pull
  docker compose up -d --no-build
  success 'Update complete.'
}

run_diagnostics() {
  require_docker || return
  configure || return
  title 'End-to-end diagnostics'
  printf 'A short test video will be uploaded to the first ADMIN_IDS account.\n'
  read -rp 'Optional public YouTube URL (press Enter for an upload-only test): ' test_url
  docker compose build
  if [[ -n "$test_url" ]]; then
    docker compose run --rm --no-deps -e "TEST_VIDEO_URL=$test_url" worker php bin/diagnose.php
  else
    docker compose run --rm --no-deps worker php bin/diagnose.php
  fi
  success 'Diagnostics passed. Check Telegram for the test video.'
}

import_youtube_cookies() {
  require_docker || return
  configure || return
  title 'YouTube sign-in cookies'
  printf 'Export your own YouTube cookies in Netscape cookies.txt format.\n'
  printf 'Copy that file to this server, then enter its absolute path below.\n'
  printf 'Cookies are stored privately in the data volume and used for new downloads.\n'
  local cookie_file
  read -rp 'Cookies file path (Enter to cancel): ' cookie_file
  [[ -n "$cookie_file" ]] || return 0
  [[ "$cookie_file" = /* && -f "$cookie_file" && -r "$cookie_file" ]] || {
    warning 'Enter an absolute path to a readable cookie export.'
    return 0
  }
  docker compose build
  if docker compose run --rm --no-deps -T worker php bin/import-cookies.php < "$cookie_file"; then
    success 'Cookies imported. Choose 8 and enter a YouTube URL to test delivery.'
  else
    warning 'Import failed. The previous cookie file was preserved.'
  fi
}

delete_bot() {
  title 'Delete Ciro Downloader'
  warning 'This removes the project containers, image, persistent SQLite data and local .env token file.'
  notice "The source code in $PROJECT_DIR will remain on the server."
  read -rp 'Type DELETE to continue: ' confirmation
  if [[ "$confirmation" != 'DELETE' ]]; then
    notice 'Deletion cancelled.'
    return
  fi

  if docker_ready; then
    docker compose down --volumes --remove-orphans || true
    docker image rm ciro-downloader:local >/dev/null 2>&1 || true
  else
    notice 'Docker is unavailable, so containers and volumes could not be removed.'
  fi
  rm -f -- "$PROJECT_DIR/.env"
  success 'Ciro Downloader was removed. Source code was kept.'
}

menu() {
  while true; do
    printf '\033[2J\033[H'
    printf '%sCIRO DOWNLOADER%s  %sv%s%s\n' "$C_BOLD$C_BLUE" "$C_RESET" "$C_MUTED" "$INSTALLER_VERSION" "$C_RESET"
    line
    printf 'Project: %s\n' "$PROJECT_DIR"
    printf 'Status:  '; service_state; printf '\n'
    line
    printf '%s1%s  Install or rebuild\n' "$C_BLUE" "$C_RESET"
    printf '%s2%s  Start services\n' "$C_BLUE" "$C_RESET"
    printf '%s3%s  Stop services\n' "$C_BLUE" "$C_RESET"
    printf '%s4%s  Restart services\n' "$C_BLUE" "$C_RESET"
    printf '%s5%s  View logs\n' "$C_BLUE" "$C_RESET"
    printf '%s6%s  View status\n' "$C_BLUE" "$C_RESET"
    printf '%s7%s  Update from GitHub\n' "$C_BLUE" "$C_RESET"
    printf '%s8%s  Run delivery diagnostics\n' "$C_BLUE" "$C_RESET"
    printf '%s9%s  Delete installation%s\n' "$C_RED" "$C_RESET" "$C_RESET"
    printf '%s10%s Import YouTube cookies\n' "$C_BLUE" "$C_RESET"
    printf '%s0%s  Exit\n' "$C_MUTED" "$C_RESET"
    line
    read -rp 'Choose an option: ' choice || exit 0

    case "$choice" in
      1) install_bot; pause ;;
      2) start_services; pause ;;
      3) stop_services; pause ;;
      4) restart_services; pause ;;
      5) show_logs; pause ;;
      6) show_status; pause ;;
      7) update_bot; pause ;;
      8) run_diagnostics; pause ;;
      9) delete_bot; pause ;;
      10) import_youtube_cookies; pause ;;
      0) exit 0 ;;
      *) warning 'Choose a number from the menu.'; pause ;;
    esac
  done
}

menu
