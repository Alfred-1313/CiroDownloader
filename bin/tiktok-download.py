"""Download one TikTok post and expose only its ordered visual media."""

import re
import sys
from urllib.parse import urljoin, urlsplit

from gallery_dl import exception, main
from gallery_dl.extractor.message import Message
from gallery_dl.extractor.tiktok import TiktokExtractor, TiktokPostExtractor, TiktokVmpostExtractor

original_items = TiktokExtractor.items
SHORT_HOSTS = {"vm.tiktok.com", "vt.tiktok.com"}
WEB_HOSTS = {"www.tiktok.com", "tiktok.com", "m.tiktok.com"}


def canonical_post(url, base="https://www.tiktok.com/"):
    try:
        parsed = urlsplit(urljoin(base, url))
        if (parsed.scheme not in ("https", "http") or parsed.hostname not in WEB_HOSTS
                or parsed.username is not None or parsed.password is not None or parsed.port is not None):
            raise ValueError
        match = re.fullmatch(r"/(?:@([A-Za-z0-9_.-]{0,64})|share)/(video|photo)/([0-9]{1,30})/?", parsed.path)
        if not match:
            raise ValueError
    except ValueError:
        raise exception.ExtractionError("TikTok requires a single video or photo post URL") from None
    return f"https://www.tiktok.com/@{match[1] or ''}/{match[2]}/{match[3]}"


def short_url(url):
    try:
        parsed = urlsplit(url)
        if (parsed.scheme not in ("https", "http") or parsed.username is not None
                or parsed.password is not None or parsed.port is not None):
            return False
        return bool((parsed.hostname in SHORT_HOSTS and re.fullmatch(r"/[A-Za-z0-9_-]{1,64}/?", parsed.path))
                    or (parsed.hostname in WEB_HOSTS and re.fullmatch(r"/t/[A-Za-z0-9_-]{1,64}/?", parsed.path)))
    except ValueError:
        return False


def single_short_post(self):
    current = self.url
    seen = set()
    for _ in range(4):
        if not short_url(current) or current in seen:
            break
        seen.add(current)
        location = self.request_location(current, headers={"User-Agent": "facebookexternalhit/1.1"}, notfound="post")
        if not location:
            break
        destination = urljoin(current, location)
        try:
            post = canonical_post(destination)
        except exception.ExtractionError:
            current = destination
            continue
        yield Message.Queue, post, {"_extractor": TiktokPostExtractor}
        return
    raise exception.ExtractionError("TikTok short link did not resolve to a single post")


def visual_post(self):
    expected = sent = 0
    description = ""
    for message in original_items(self):
        if message[0] == Message.Directory:
            post = message[2]
            if str(post.get("id")) != self.groups[1]:
                raise exception.ExtractionError("TikTok returned a different post")
            images = post.get("imagePost", {}).get("images")
            expected = len(images) if images is not None else 1
            if not 1 <= expected <= 35:
                raise exception.ExtractionError("TikTok post must contain between 1 and 35 visual items")
            if images is not None and any(not image.get("imageURL", {}).get("urlList") for image in images):
                raise exception.ExtractionError("TikTok returned incomplete photos")
            description = post.get("desc") or ""
            yield Message.Directory, message[1], dict(post, count=expected, description=description)
        elif message[0] == Message.Url:
            if message[2].get("type") not in ("image", "video"):
                continue
            sent += 1
            if sent > expected:
                raise exception.ExtractionError("TikTok returned unexpected visual media")
            yield Message.Url, message[1], dict(message[2], num=sent, count=expected, description=description)
        else:
            raise exception.ExtractionError("TikTok post unexpectedly queued another resource")
    if not expected or sent != expected:
        raise exception.ExtractionError("TikTok returned incomplete visual media")


if __name__ == "__main__":
    TiktokPostExtractor.items = visual_post
    TiktokVmpostExtractor.items = single_short_post
    sys.exit(main())
