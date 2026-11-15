"""The pinned extractor/parser/downloader, with only the upstream reel response replaced."""
import json
import os
import runpy
import sys
from pathlib import Path

from gallery_dl import exception
from gallery_dl.extractor.instagram import InstagramAPI
from requests import Response

ROOT = Path(__file__).resolve().parents[2]
WORK = Path(sys.argv.pop(1))
mode = json.loads((WORK / "mode.json").read_text())
args = sys.argv[1:]
cookie = args[args.index("--cookies") + 1] if "--cookies" in args else None
with (WORK / "backend.jsonl").open("a") as trace:
    trace.write(json.dumps({"args": args, "cookie_private": bool(cookie and os.stat(cookie).st_mode & 0o777 == 0o600), "story_session": bool(cookie and "STORY_ONLY" in Path(cookie).read_text())}) + "\n")
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
        response = Response()
        response.status_code = 429
        response._content = b""
        response._content_consumed = True
        raise exception.HttpError("HTTP 429", response)
    if mode.get("empty"):
        return ()
    if mode.get("neighbour"):
        other = dict(reel, id="8", user=dict(user, id="8", pk="8", username="other_account"))
        return [other, reel]
    if mode.get("mismatch"):
        return [dict(reel, user=dict(user, id="8", pk="8", username="other_account"))]
    return [reel]


def webpage(self, path):
    if mode.get("identity_limited") or mode.get("identity_http"):
        response = Response()
        response.status_code = mode.get("identity_http", 429)
        response._content = b""
        response._content_consumed = True
        raise exception.HttpError("HTTP " + str(response.status_code), response)
    if mode.get("identity_missing"):
        return '<link rel="canonical" href="https://www.instagram.com/fixture/">'
    return '<link rel="canonical" href="https://www.instagram.com/fixture/">"profile_id":"7"'


def forbidden_profile(self, *args, **kwargs):
    raise AssertionError("A story must not request a full profile")


InstagramAPI.user = forbidden_profile
InstagramAPI._webpage = webpage
InstagramAPI.reels_media = reels
runpy.run_path(str(ROOT / "bin/instagram-download.py"), run_name="__main__")
