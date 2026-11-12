#!/usr/bin/env python3
"""Reclaim Ciro's disposable files with its bot/worker/radio stopped. Never remove volumes."""
import argparse
import os
from pathlib import Path
import re
import shutil
import stat
import tarfile

from maintenance import Maintenance, MaintenanceError, operation_lock
import updater


def clean_files(data, logs, apply=False):
    data, logs = Path(data), Path(logs)
    if data.is_symlink() or not data.is_dir():
        raise MaintenanceError('Unsafe application data path.')
    paths = []
    downloads = data / 'downloads'
    if downloads.is_symlink():
        raise MaintenanceError('Unsafe downloads path.')
    if downloads.is_dir():
        paths.extend(p for p in downloads.iterdir() if re.fullmatch(r'job-\d+-[a-f0-9]+', p.name))
    cache = data / 'yt-cache'
    if cache.exists() or cache.is_symlink():
        paths.append(cache)
    if logs.is_dir() and not logs.is_symlink():
        paths.extend(p for p in logs.iterdir() if re.fullmatch(r'step-[A-Za-z0-9_-]+\.log', p.name))
    count = 0
    for path in paths:
        mode = path.lstat().st_mode
        if not (stat.S_ISREG(mode) or stat.S_ISDIR(mode) or stat.S_ISLNK(mode)):
            continue
        count += 1
        if apply:
            if stat.S_ISDIR(mode):
                shutil.rmtree(path)  # Python does not follow symlinks within the tree.
            else:
                path.unlink()
    return count


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--apply', action='store_true', help='delete disposable files and obsolete automatic update artifacts')
    args = parser.parse_args()
    if os.geteuid() != 0:
        raise MaintenanceError('Run cleanup as root.')
    app = Maintenance()
    with operation_lock():
        app.check_project()
        if app.running():
            raise MaintenanceError('Stop bot, worker and radio first: CiroDownloader stop')
        data = Path(app.command('docker', 'volume', 'inspect', 'ciro-downloader_data', '--format', '{{.Mountpoint}}'))
        count = clean_files(data, Path('/var/log/ciro-downloader'), args.apply)
        if args.apply:
            updater.prune_previous()
        print(('Removed ' if args.apply else 'Would remove ') + str(count) + ' disposable entries.')
        print('Database, cookies, radio, unsent galleries, paused batches and Telegram API sessions are preserved.')


if __name__ == '__main__':
    try:
        main()
    except (MaintenanceError, RuntimeError, OSError, ValueError, KeyError, tarfile.TarError):
        print('Cleanup stopped. Check service status and the retained rollback record; no volume should be removed.')
        raise SystemExit(1)
