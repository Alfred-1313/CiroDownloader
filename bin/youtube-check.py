"""Bounded snapshots of a channel's own recent videos, Shorts and community posts."""
import argparse
import http.cookiejar
import importlib.util
import json
import re
import sys
import time
from pathlib import Path
from urllib.parse import urlsplit

from yt_dlp import YoutubeDL
from yt_dlp.extractor.youtube import YoutubeTabIE
from yt_dlp.utils import ExtractorError
import requests

spec = importlib.util.spec_from_file_location("youtube_posts", Path(__file__).with_name("youtube-post.py"))
posts = importlib.util.module_from_spec(spec)
spec.loader.exec_module(posts)
CHANNEL = re.compile(r"UC[A-Za-z0-9_-]{22}\Z")
VIDEO = re.compile(r"[A-Za-z0-9_-]{11}\Z")
POST = re.compile(r"Ug[A-Za-z0-9_-]{8,126}\Z")


def walk(value):
    stack = [(value, 0)]
    visited = 0
    while stack:
        node, depth = stack.pop()
        visited += 1
        if visited > 60000 or depth > 70:
            raise posts.PostError("format")
        if isinstance(node, dict):
            yield node
            stack.extend((v, depth + 1) for v in reversed(list(node.values())) if isinstance(v, (dict, list)))
        elif isinstance(node, list):
            stack.extend((v, depth + 1) for v in reversed(node) if isinstance(v, (dict, list)))


def published(value):
    match = re.search(r"(\d+)\s+(second|minute|hour|day|week|month|year)s?\s+ago", posts.text(value), re.I)
    if not match:
        return 0
    seconds = {"second": 1, "minute": 60, "hour": 3600, "day": 86400, "week": 604800, "month": 2592000, "year": 31536000}
    return max(0, int(time.time()) - int(match[1]) * seconds[match[2].lower()])


def avatar(data):
    """Only the verified channel's metadata/header avatar, never a video or banner."""
    def at(value, *keys):
        for key in keys:
            if not isinstance(value, dict):
                return {}
            value = value.get(key, {})
        return value

    metadata = at(data, "metadata", "channelMetadataRenderer")
    legacy = at(data, "header", "c4TabbedHeaderRenderer")
    modern = at(data, "header", "pageHeaderRenderer", "content", "pageHeaderViewModel")
    if not isinstance(metadata, dict):
        return ""
    if not isinstance(legacy, dict):
        legacy = {}
    sources = at(metadata, "avatar", "thumbnails")
    if not sources and legacy.get("channelId", metadata.get("externalId")) == metadata.get("externalId"):
        sources = at(legacy, "avatar", "thumbnails")
    if not sources:
        sources = at(modern, "image", "decoratedAvatarViewModel", "avatar", "avatarViewModel", "image", "sources")
    valid = []
    for item in sources[:20] if isinstance(sources, list) else []:
        if not isinstance(item, dict):
            continue
        url = item.get("url", "")
        if not isinstance(url, str) or len(url) > 2048 or not posts.trusted(url, posts.IMAGE_HOSTS):
            continue
        width = item.get("width", 0)
        valid.append((width if isinstance(width, int) else 0, url))
    return max(valid, default=(0, ""))[1]


def parse(data, kind, expected=""):
    metadata = data.get("metadata", {}).get("channelMetadataRenderer", {})
    account = metadata.get("externalId", "")
    if not CHANNEL.fullmatch(account) or (expected and account != expected):
        raise posts.PostError("format")
    tabs = data.get("contents", {}).get("twoColumnBrowseResultsRenderer", {}).get("tabs", [])
    allowed = {"video": {"videos"}, "short": {"shorts"}, "post": {"posts", "community"}}[kind]
    selected = None
    available = False
    for item in tabs:
        tab = item.get("tabRenderer", {})
        endpoint = tab.get("endpoint", {}).get("commandMetadata", {}).get("webCommandMetadata", {}).get("url", "")
        route = urlsplit(endpoint).path.rstrip("/").rsplit("/", 1)[-1]
        if route in allowed:
            available = True
            if tab.get("selected") is True:
                selected = tab.get("content", {})
    if selected is None:
        if available or not tabs:
            raise posts.PostError("format")
        return account, []  # The channel has no tab of this type.
    result = {}
    for node in walk(selected):
        if kind == "post":
            item = node.get("backstagePostRenderer") or node.get("postRenderer")
            if not isinstance(item, dict):
                continue
            identity = item.get("postId", "")
            owner = item.get("authorEndpoint", {}).get("browseEndpoint", {}).get("browseId", "")
            if owner and owner != account:
                continue
            if not POST.fullmatch(identity):
                raise posts.PostError("format")
            result.setdefault(identity, {"id": identity, "url": "https://www.youtube.com/post/" + identity, "time": published(item.get("publishedTimeText"))})
        else:
            item = node.get("videoRenderer") or node.get("gridVideoRenderer") or node.get("reelItemRenderer") or node.get("shortsLockupViewModel")
            if not isinstance(item, dict):
                continue
            identity = item.get("videoId") or item.get("onTap", {}).get("innertubeCommand", {}).get("reelWatchEndpoint", {}).get("videoId", "")
            if not VIDEO.fullmatch(identity):
                raise posts.PostError("format")
            overlays = [o.get("thumbnailOverlayTimeStatusRenderer", {}).get("style") for o in item.get("thumbnailOverlays", [])]
            if item.get("upcomingEventData") or item.get("isLive") or any(s in ("LIVE", "UPCOMING") for s in overlays) or any("LIVE" in str(b.get("metadataBadgeRenderer", {}).get("style", "")) for b in item.get("badges", [])):
                continue
            result.setdefault(identity, {"id": identity, "url": ("https://www.youtube.com/shorts/" if kind == "short" else "https://www.youtube.com/watch?v=") + identity, "time": published(item.get("publishedTimeText"))})
        if len(result) >= 24:
            break
    return account, list(result.values())


def scan(args):
    if not (CHANNEL.fullmatch(args.channel) or re.fullmatch(r"@[\w.-]{3,30}", args.channel, re.UNICODE)) or not 1 <= args.mode <= 7:
        raise posts.PostError("format")
    session = posts.http_session()
    if args.proxy:
        session.proxies.update({"http": args.proxy, "https": args.proxy})
    if args.cookies:
        jar = http.cookiejar.MozillaCookieJar(args.cookies)
        jar.load(ignore_discard=True, ignore_expires=True)
        for cookie in list(jar):
            if cookie.expires == 0:
                cookie.expires, cookie.discard = None, True
            elif cookie.is_expired():
                jar.clear(cookie.domain, cookie.path, cookie.name)
        session.cookies.update(jar)
    account = args.channel if CHANNEL.fullmatch(args.channel) else ""
    result = {"account_id": account, "mode": args.mode, "videos": [], "shorts": [], "posts": [], "avatar": ""}
    for bucket, kind, bit, path in [("videos", "video", 1, "videos"), ("shorts", "short", 2, "shorts"), ("posts", "post", 4, "posts")]:
        if not args.mode & bit:
            continue
        base = "/channel/" + account if account else "/" + args.channel
        url = "https://www.youtube.com" + base + "/" + path
        try:
            page = b"".join(posts.chunks(posts.response(session, url, posts.PAGE_HOSTS), 8 * 1024 * 1024)).decode("utf-8", "replace")
        except posts.PostError as error:
            if kind != "post" or error.reason != "restricted":
                raise
            # Older channels may still expose /community; never retry a rate refusal.
            url = "https://www.youtube.com" + base + "/community"
            page = b"".join(posts.chunks(posts.response(session, url, posts.PAGE_HOSTS), 8 * 1024 * 1024)).decode("utf-8", "replace")
        try:
            with YoutubeDL({"quiet": True, "no_warnings": True}) as ydl:
                data = YoutubeTabIE(ydl).extract_yt_initial_data("checker", page)
        except ExtractorError:
            raise posts.PostError("format") from None
        account, result[bucket] = parse(data, kind, account)
        if not result["avatar"]:
            result["avatar"] = avatar(data)
        result["account_id"] = account
    return result


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--channel", required=True)
    parser.add_argument("--mode", type=int, required=True)
    parser.add_argument("--cookies")
    parser.add_argument("--proxy")
    args = parser.parse_args()
    try:
        print(json.dumps(scan(args), ensure_ascii=False))
    except posts.PostError as error:
        print("YOUTUBE_CHECK_ERROR " + error.reason, file=sys.stderr)
        return 1
    except requests.RequestException:
        print("YOUTUBE_CHECK_ERROR network", file=sys.stderr)
        return 1
    except Exception:
        print("YOUTUBE_CHECK_ERROR format", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
