#!/usr/bin/env python3
"""Keep private manager logs bounded; never stop reading the command's output."""
import os
from pathlib import Path
import stat
import sys
import time

LIMIT = 2 * 1024 * 1024
KEEP = 20
DAYS = 7


def prune(directory):
    entries = []
    for path in Path(directory).glob('step-*.log'):
        info = path.lstat()
        if stat.S_ISREG(info.st_mode):
            entries.append((info.st_mtime, path))
    for index, (modified, path) in enumerate(sorted(entries, reverse=True)):
        if index >= KEEP or modified < time.time() - DAYS * 86400:
            path.unlink(missing_ok=True)
        elif path.stat().st_size > LIMIT:
            with path.open('r+b') as log:
                log.seek(-LIMIT, os.SEEK_END)
                tail = log.read(LIMIT)
                log.seek(0); log.write(tail); log.truncate()


def capture(path):
    # The manager creates this 0600 file; refuse symlinks rather than follow them.
    fd = os.open(path, os.O_RDWR | os.O_NOFOLLOW)
    with os.fdopen(fd, 'r+b', buffering=0) as log:
        log.truncate(0)
        while chunk := sys.stdin.buffer.read1(65536):
            if log.tell() + len(chunk) > LIMIT:
                log.seek(-LIMIT // 2, os.SEEK_END)
                tail = log.read(LIMIT // 2)
                log.seek(0); log.write(tail); log.truncate()
            log.write(chunk)


if __name__ == '__main__':
    if sys.argv[1] == '--prune':
        prune(sys.argv[2])
    else:
        capture(sys.argv[1])
