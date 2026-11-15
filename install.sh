#!/usr/bin/env bash
# Stable bootstrap: keep this file small and backwards-compatible. The
# interactive UI lives in manager.sh. Existing installations update through the
# manager's locked backup/build/health flow, never while just opening its menu.
set -Eeuo pipefail

REPOSITORY_URL='https://github.com/Alfred-1313/CiroDownloader.git'
INSTALL_DIR='/opt/ciro-downloader'
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"

open_manager() {
  # Running from an existing checkout (for example: sudo bash install.sh).
  if [[ -f "$SCRIPT_DIR/manager.sh" && -f "$SCRIPT_DIR/compose.yaml" ]]; then
    exec bash "$SCRIPT_DIR/manager.sh" "$@"
  fi

  if [[ -d "$INSTALL_DIR/.git" ]]; then
    if [[ ! -f "$INSTALL_DIR/manager.sh" || ! -f "$INSTALL_DIR/compose.yaml" ]]; then
      echo "Cannot open manager: $INSTALL_DIR is an incomplete checkout. Its files were kept." >&2
      return 1
    fi
    echo 'Opening the installed Ciro Downloader manager. Choose Update to install GitHub changes.'
    exec bash "$INSTALL_DIR/manager.sh" "$@"
  elif [[ -e "$INSTALL_DIR" ]]; then
    echo "Cannot install: $INSTALL_DIR exists but is not a Git checkout." >&2
    return 1
  fi

  echo 'Preparing the latest Ciro Downloader manager…'
  if ! command -v git >/dev/null 2>&1; then
    apt-get update
    apt-get install -y git ca-certificates
  fi
  git clone --branch main "$REPOSITORY_URL" "$INSTALL_DIR"
  exec bash "$INSTALL_DIR/manager.sh" "$@"
}

main() {
  [[ $EUID -eq 0 ]] || { echo 'Run this installer as root.' >&2; return 1; }
  open_manager "$@"
}
if [[ ${BASH_SOURCE[0]} == "$0" ]]; then main "$@"; fi
