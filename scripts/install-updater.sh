#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
[[ $EUID -eq 0 ]] || { echo 'Run as root.' >&2; exit 1; }
PROJECT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
[[ "$PROJECT_DIR" == /opt/ciro-downloader ]] || { echo 'Telegram updates require /opt/ciro-downloader.' >&2; exit 1; }
command -v python3 >/dev/null || { apt-get update; apt-get install -y python3; }
command -v systemctl >/dev/null || { echo 'systemd is required for Telegram updates.' >&2; exit 1; }
install -d -m 700 /usr/local/lib/ciro-downloader /var/lib/ciro-downloader-updater /var/backups/ciro-downloader
install -m 700 "$PROJECT_DIR/scripts/updater.py" /usr/local/lib/ciro-downloader/updater.py
cat > /etc/systemd/system/ciro-downloader-updater.service <<'UNIT'
[Unit]
Description=Ciro Downloader administrator update agent
After=docker.service network-online.target
Requires=docker.service
[Service]
Type=oneshot
User=root
UMask=0077
ExecStart=/usr/bin/python3 /usr/local/lib/ciro-downloader/updater.py
TimeoutStartSec=50min
UNIT
cat > /etc/systemd/system/ciro-downloader-updater.timer <<'UNIT'
[Unit]
Description=Check Ciro Downloader administrator update requests
[Timer]
OnBootSec=15s
OnUnitInactiveSec=10s
AccuracySec=1s
[Install]
WantedBy=timers.target
UNIT
systemctl daemon-reload
systemctl enable --now ciro-downloader-updater.timer
echo 'Telegram updates enabled. Send /update as an administrator.'
