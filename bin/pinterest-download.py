"""Download one Pinterest pin, retaining ordered visual media only."""

import json
import re
import sys
from pathlib import Path
from urllib.parse import urljoin, urlsplit

from gallery_dl import exception, main
from gallery_dl.extractor.message import Message
from gallery_dl.extractor.pinterest import PinterestExtractor, PinterestPinExtractor, PinterestPinitExtractor

DOMAINS = json.loads((Path(__file__).resolve().parents[1] / "resources/pinterest-domains.json").read_text())
HOSTS = {"pinterest." + domain for domain in DOMAINS}
extract_files = PinterestExtractor._extract_files
extract_video = PinterestExtractor._extract_video


def canonical_pin(url):
    try:
        parsed = urlsplit(urljoin("https://www.pinterest.com/", url))
        port = parsed.port
    except ValueError:
        raise exception.ExtractionError("Pinterest requires a single pin URL (reason=malformed)") from None
    host = re.sub(r"^(?:www|[a-z]{2})\.", "", parsed.hostname or "")
    # Pinterest's share links can redirect to /pin/ID/sent/. Extract the pin
    # identifier before dropping the sharing path and query parameters.
    match = re.fullmatch(r"/pin/(?:[^/?#]+--)?([0-9]{1,30})(?:/sent)?/?", parsed.path)
    reason = ("scheme" if parsed.scheme not in ("https", "http") else
              "host" if host not in HOSTS else
              "authority" if parsed.username is not None or parsed.password is not None or port is not None else
              "path" if not match else "")
    if reason:
        raise exception.ExtractionError(f"Pinterest requires a single pin URL (reason={reason})")
    return "https://www.pinterest.com/pin/" + match[1] + "/"


def single_short_pin(self):
    # A shared board/profile must never turn into a bulk download.
    location = self.request_location(
        f"https://api.pinterest.com/url_shortener/{self.groups[0]}/redirect/")
    if not location:
        raise exception.NotFoundError("pin")
    yield Message.Queue, canonical_pin(location), {"_extractor": PinterestPinExtractor}


def visual_pin(self, pin):
    story = pin.get("story_pin_data")
    if story:
        expected = 0
        for page in story.get("pages", ()):
            for block in page.get("blocks", ()):
                kind = block.get("type", "")
                if kind in ("story_pin_image_block", "story_pin_video_block") or "video" in block:
                    expected += 1
                elif kind not in ("story_pin_music_block", "story_pin_paragraph_block",
                                  "story_pin_product_sticker_block", "story_pin_static_sticker_block"):
                    raise exception.ExtractionError("Unsupported Pinterest story block")
    elif pin.get("carousel_data"):
        expected = len(pin["carousel_data"].get("carousel_slots", ()))
    else:
        expected = 1
    if not 1 <= expected <= 20:
        raise exception.ExtractionError("Pinterest pin must contain between 1 and 20 visual items")
    files = [media for media in extract_files(self, pin)
             if not media.get("audio_url") and not media.get("url", "").startswith("text:")]
    if len(files) != expected:
        raise exception.ExtractionError("Pinterest returned incomplete visual media")
    return files


def direct_video(self, video):
    # Prefer an original direct MP4 over downloading many HLS fragments.
    direct = [media for media in video.get("video_list", {}).values()
              if urlsplit(media.get("url", "")).path.lower().endswith(".mp4")]
    return max(direct, key=lambda media: (media.get("width", 0), media.get("height", 0))) if direct else extract_video(self, video)


if __name__ == "__main__":
    PinterestExtractor._extract_files = visual_pin
    PinterestExtractor._extract_video = direct_video
    PinterestPinitExtractor.items = single_short_pin
    sys.exit(main())
