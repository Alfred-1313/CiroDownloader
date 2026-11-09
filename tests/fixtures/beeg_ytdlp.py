"""Use the real Beeg extractor and HLS downloader with neutral local fixtures."""
import json
import sqlite3
import sys
import urllib.parse

import yt_dlp
from yt_dlp.extractor.beeg import BeegIE
from yt_dlp.extractor.common import InfoExtractor
from yt_dlp.utils import ExtractorError

args = sys.argv[1:]
settings = json.load(open(MODE))
with open(TRACE, "a") as trace:
    trace.write(json.dumps(args) + "\n")
original_page = InfoExtractor._download_webpage
original_json = InfoExtractor._download_json
original_open = yt_dlp.YoutubeDL.urlopen
original_hls = InfoExtractor._extract_m3u8_formats


def page(self, url, video_id, *args, **kwargs):
    if BeegIE.suitable(url):
        cover = "" if settings.get("no_cover") else '<meta property="og:image" content="https://thumbs.externulls.com/cover.jpg">'
        return "<html><head>" + cover + "<title>Neutral fixture</title></head><body></body></html>"
    if url.startswith("https://store.externulls.com/video/play_url/"):
        return original_page(self, BASE + urllib.parse.urlsplit(url).path, video_id, *args, **kwargs)
    return original_page(self, url, video_id, *args, **kwargs)


def metadata(self, url, video_id, *args, **kwargs):
    if not url.startswith("https://store.externulls.com/facts/file/"):
        raise AssertionError("Unexpected Beeg metadata endpoint")
    if settings.get("failure"):
        raise ExtractorError("SSL: CERTIFICATE_VERIFY_FAILED at https://hidden.example/private-value", expected=True)
    phase = "inspect" if "--dump-single-json" in sys.argv else "download"
    if settings.get("disable") == phase:
        with sqlite3.connect(DATABASE) as database:
            database.execute("UPDATE settings SET value='0' WHERE key='adult.beeg'")
    return original_json(self, BASE + urllib.parse.urlsplit(url).path, video_id, *args, **kwargs)


def hls(self, url, video_id, *args, **kwargs):
    if not url.startswith("https://video.beeg.com/"):
        raise AssertionError("Unexpected Beeg HLS host")
    return original_hls(self, BASE + urllib.parse.urlsplit(url).path, video_id, *args, **kwargs)


def open_request(self, request):
    if request.url == "https://thumbs.externulls.com/cover.jpg":
        request.update(url=BASE + "/cover.jpg")
    return original_open(self, request)


yt_dlp.YoutubeDL.urlopen = open_request
InfoExtractor._download_webpage = page
InfoExtractor._download_json = metadata
InfoExtractor._extract_m3u8_formats = hls
yt_dlp.main()
