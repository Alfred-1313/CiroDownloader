#!/usr/bin/env bash
set -Eeuo pipefail

REPOSITORY_URL='https://github.com/Alfred-1313/CiroDownloader.git'
INSTALL_DIR='/opt/ciro-downloader'

[[ $EUID -eq 0 ]] || { echo 'Run this setup script as root.' >&2; exit 1; }

echo 'Ciro Downloader bootstrap v2.0.0'
echo 'Fetching the current installer from GitHub…'

if ! command -v git >/dev/null 2>&1; then
  apt-get update
  apt-get install -y git ca-certificates
fi

if [[ -d "$INSTALL_DIR/.git" ]]; then
  if [[ -n "$(git -C "$INSTALL_DIR" status --porcelain)" ]]; then
    echo "Cannot update $INSTALL_DIR because it contains local changes." >&2
    exit 1
  fi
  git -C "$INSTALL_DIR" remote set-url origin "$REPOSITORY_URL"
  git -C "$INSTALL_DIR" fetch origin main
  git -C "$INSTALL_DIR" checkout main
  git -C "$INSTALL_DIR" merge --ff-only origin/main
elif [[ ! -e "$INSTALL_DIR" ]]; then
  git clone --branch main "$REPOSITORY_URL" "$INSTALL_DIR"
else
  echo "Cannot install: $INSTALL_DIR exists but is not a Git checkout." >&2
  exit 1
fi

exec bash "$INSTALL_DIR/manager.sh" "$@"
