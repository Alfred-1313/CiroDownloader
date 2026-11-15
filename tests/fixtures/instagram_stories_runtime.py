"""The pinned extractor/parser/downloader, with only the upstream reel response replaced."""
import json
import os
import runpy
import sys
from pathlib import Path

from gallery_dl import exception
from gallery_dl.extractor.instagram import InstagramAPI

ROOT = Path(__file__).resolve().parents[2]
WORK = Path(sys.argv.pop(1))
mode = json.loads((WORK / "mode.json").read_text())
args = sys.argv[1:]
cookie = args[args.index("--cookies") + 1] if "--cookies" in args else None
with (WORK / "backend.jsonl").open("a") as trace:
    trace.write(json.dumps({"args": args, "cookie_private": bool(cookie and os.stat(cookie).st_mode & 0o777 == 0o600)}) + "\n")
base = (WORK / "base").read_text()
user = {"pk": "7", "id": "7", "username": "fixture", "full_name": "Fixture"}
items = [{"pk": 100 + i, "code": "ABC_def12", "taken_at": 1700000000 + i,
          "image_versions2": {"candidates": [{"url": base + "/photo.jpg", "width": 160, "height": 288}]},
          "video_versions": [{"url": base + "/clip.mp4", "width": 160, "height": 288}] if kind == "video" else []}
         for i, kind in enumerate(mode.get("kinds", []))]
if mode.get("missing"):
    items[-1].pop("image_versions2")
reel = {"id": "7", "user": user, "seen": 1700000000, "items": items}


def reels(self, ids):
    if mode.get("limited"):
        raise exception.ExtractionError("HTTP Error 429: Too Many Requests")
    if mode.get("empty"):
        return ()
    if mode.get("neighbour"):
        other = dict(reel, id="8", user=dict(user, id="8", pk="8", username="other_account"))
        return [other, reel]
    if mode.get("mismatch"):
        return [dict(reel, user=dict(user, id="8", pk="8", username="other_account"))]
    return [reel]


InstagramAPI.user = lambda self, handle, check_private=True: dict(user)
InstagramAPI.reels_media = reels
runpy.run_path(str(ROOT / "bin/instagram-download.py"), run_name="__main__")
