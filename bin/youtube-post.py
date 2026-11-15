"""Download only the requested community post's own text and image attachments."""
import argparse
import http.cookiejar
import json
import os
import re
import sys
from pathlib import Path
from urllib.parse import urljoin, urlsplit

import requests
from yt_dlp import YoutubeDL
from yt_dlp.extractor.youtube import YoutubeTabIE
from yt_dlp.networking._requests import RequestsHTTPAdapter

PAGE_HOSTS = {"www.youtube.com", "youtube.com", "m.youtube.com"}
IMAGE_HOSTS = {"yt3.ggpht.com", "yt3.googleusercontent.com"}


class PostError(Exception):
    def __init__(self, reason):
        self.reason = reason


def trusted(url, hosts):
    try:
        p = urlsplit(url)
        return p.scheme == "https" and p.hostname in hosts and not p.username and not p.password and p.port is None
    except ValueError:
        return False


def response(session, url, hosts):
    for _ in range(4):
        if not trusted(url, hosts):
            raise PostError("format")
        r = session.get(url, stream=True, timeout=(8, 20), allow_redirects=False)
        if r.status_code in (301, 302, 303, 307, 308):
            destination = urljoin(url, r.headers.get("Location", ""))
            r.close()
            url = destination
            continue
        if r.status_code != 200:
            code = r.status_code
            r.close()
            raise PostError("blocked" if code in (403, 429) else "cookies" if code == 401 else "restricted" if code in (404, 410) else "network")
        return r
    raise PostError("blocked")


def chunks(r, limit):
    count = 0
    try:
        for part in r.iter_content(65536):
            count += len(part)
            if count > limit:
                raise PostError("size")
            yield part
    finally:
        r.close()


def text(value):
    if not isinstance(value, dict):
        return ""
    return value.get("simpleText", "") or "".join(run.get("text", "") for run in value.get("runs", []) if isinstance(run, dict))


def parse(data, post_id):
    found = []
    stack = [(data, 0)]
    visited = 0
    while stack:
        value, depth = stack.pop()
        visited += 1
        if visited > 100000 or depth > 80:
            raise PostError("format")
        if isinstance(value, dict):
            for name in ("backstagePostRenderer", "postRenderer"):
                post = value.get(name)
                if isinstance(post, dict) and post.get("postId") == post_id:
                    found.append(post)
            stack.extend((v, depth + 1) for v in value.values() if isinstance(v, (list, dict)))
        elif isinstance(value, list):
            stack.extend((v, depth + 1) for v in value if isinstance(v, (list, dict)))
    unique = {json.dumps(post, sort_keys=True): post for post in found}
    if len(unique) != 1:
        # A login page or a changed response does not prove the post was deleted.
        raise PostError("format")
    post = next(iter(unique.values()))
    attachment = post.get("backstageAttachment") or {}
    images = attachment.get("postMultiImageRenderer", {}).get("images")
    if images is None:
        images = [attachment] if "backstageImageRenderer" in attachment else []
    if not isinstance(images, list) or len(images) > 20:
        raise PostError("format")
    urls = []
    for image in images:
        thumbs = image.get("backstageImageRenderer", {}).get("image", {}).get("thumbnails", [])
        valid = [t for t in thumbs if isinstance(t, dict) and trusted(t.get("url", ""), IMAGE_HOSTS)]
        if not valid:
            raise PostError("format")
        largest = max(valid, key=lambda t: (int(t.get("width", 0)), int(t.get("height", 0))))
        urls.append(largest["url"])
    description = text(post.get("contentText", {}))
    poll = attachment.get("pollRenderer") or {}
    choices = [text(choice.get("text", {})) for choice in poll.get("choices", []) if isinstance(choice, dict)]
    if choices:
        description += "\n\n" + "\n".join("• " + choice for choice in choices if choice)
    video = attachment.get("videoRenderer", {}).get("videoId", "")
    if re.fullmatch(r"[A-Za-z0-9_-]{11}", video):
        description += "\n\nhttps://www.youtube.com/watch?v=" + video
    if not urls and not description.strip():
        raise PostError("format")
    return {"description": description[:20000], "author": text(post.get("authorText", {}))[:200], "images": urls}


def http_session():
    session = requests.Session()
    # Reuse the pinned downloader's SOCKS/HTTP transport; PySocks is optional
    # in gallery-dl and is not installed in the production image.
    adapter = RequestsHTTPAdapter(max_retries=0)
    session.mount("https://", adapter)
    session.mount("http://", adapter)
    session.trust_env = False
    session.headers.update({"User-Agent": "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36", "Accept-Language": "en-US,en;q=0.9"})
    return session


def download(args):
    if not re.fullmatch(r"https://www\.youtube\.com/post/Ug[A-Za-z0-9_-]{8,126}", args.url):
        raise PostError("format")
    session = http_session()
    if args.proxy:
        session.proxies.update({"http": args.proxy, "https": args.proxy})
    if args.cookies:
        jar = http.cookiejar.MozillaCookieJar(args.cookies)
        jar.load(ignore_discard=True, ignore_expires=True)
        # Browser exports use 0 for session cookies, while MozillaCookieJar
        # otherwise interprets that value as an expiry in 1970.
        for cookie in list(jar):
            if cookie.expires == 0:
                cookie.expires, cookie.discard = None, True
            elif cookie.is_expired():
                jar.clear(cookie.domain, cookie.path, cookie.name)
        session.cookies.update(jar)
    page = b"".join(chunks(response(session, args.url, PAGE_HOSTS), 8 * 1024 * 1024)).decode("utf-8", "replace")
    with YoutubeDL({"quiet": True, "no_warnings": True}) as ydl:
        data = YoutubeTabIE(ydl).extract_yt_initial_data(args.url.rsplit("/", 1)[1], page)
    post = parse(data, args.url.rsplit("/", 1)[1])
    files = []
    for index, url in enumerate(post.pop("images"), 1):
        path = Path(args.directory) / f"ytp-{index}.image"
        with path.open("xb") as output:
            os.chmod(path, 0o600)
            for part in chunks(response(session, url, IMAGE_HOSTS), min(args.max_bytes, 20000000)):
                output.write(part)
        files.append(path.name)
    return dict(post, files=files)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--directory", required=True)
    parser.add_argument("--max-bytes", required=True, type=int)
    parser.add_argument("--cookies")
    parser.add_argument("--proxy")
    parser.add_argument("url")
    args = parser.parse_args()
    os.umask(0o077)
    try:
        print(json.dumps(download(args), ensure_ascii=False))
    except PostError as error:
        print("YOUTUBE_POST_ERROR " + error.reason, file=sys.stderr)
        return 1
    except requests.RequestException:
        print("YOUTUBE_POST_ERROR network", file=sys.stderr)
        return 1
    except Exception:
        print("YOUTUBE_POST_ERROR format", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
