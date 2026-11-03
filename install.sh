#!/usr/bin/env bash
# Stable bootstrap: keep this file small and backwards-compatible. The
# interactive UI lives in manager.sh and is updated through Git on every run.
set -Eeuo pipefail

REPOSITORY_URL='https://github.com/Alfred-1313/CiroDownloader.git'
INSTALL_DIR='/opt/ciro-downloader'
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"

[[ $EUID -eq 0 ]] || { echo 'Run this installer as root.' >&2; exit 1; }

# Running from an existing checkout (for example: sudo bash install.sh).
if [[ -f "$SCRIPT_DIR/manager.sh" && -f "$SCRIPT_DIR/compose.yaml" ]]; then
  exec bash "$SCRIPT_DIR/manager.sh" "$@"
fi

echo 'Preparing the latest Ciro Downloader manager…'

if ! command -v git >/dev/null 2>&1; then
  apt-get update
  apt-get install -y git ca-certificates
fi

if [[ -d "$INSTALL_DIR/.git" ]]; then
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
