"""Use the real Beeg extractor and HLS downloader with neutral local fixtures."""
import json
import sqlite3
import sys
import urllib.parse

import yt_dlp
from yt_dlp.extractor.beeg import BeegIE
from yt_dlp.utils import ExtractorError

args = sys.argv[1:]
settings = json.load(open(MODE))
with open(TRACE, "a") as trace:
    trace.write(json.dumps(args) + "\n")
original_hls = BeegIE._extract_m3u8_formats


def page(self, url, video_id, *args, **kwargs):
    if not BeegIE.suitable(url):
        raise AssertionError("Not a Beeg video URL")
    return "<html><head><title>Neutral fixture</title></head><body></body></html>"


def metadata(self, url, video_id, *args, **kwargs):
    if not url.startswith("https://store.externulls.com/facts/file/"):
        raise AssertionError("Unexpected Beeg metadata endpoint")
    if settings.get("failure"):
        raise ExtractorError("SSL: CERTIFICATE_VERIFY_FAILED at https://hidden.example/private-value", expected=True)
    phase = "inspect" if "--dump-single-json" in sys.argv else "download"
    if settings.get("disable") == phase:
        with sqlite3.connect(DATABASE) as database:
            database.execute("UPDATE settings SET value='0' WHERE key='adult.beeg'")
    return {"file": {"fl_duration": 2, "stuff": {"sf_name": "Neutral Fixture Video", "sf_story": "Local test"},
                     "hls_resources": {"fl_cdn_240": "240/stream.m3u8", "fl_cdn_480": "480/stream.m3u8"}},
            "fc_facts": [{"id": 1, "fc_created": "2026-11-09T00:00:00Z"}],
            "tags": [{"tg_name": "Topic One"}, {"tg_name": "Tag Two"}]}


def hls(self, url, video_id, *args, **kwargs):
    if not url.startswith("https://video.beeg.com/"):
        raise AssertionError("Unexpected Beeg HLS host")
    return original_hls(self, BASE + urllib.parse.urlsplit(url).path, video_id, *args, **kwargs)


BeegIE._download_webpage = page
BeegIE._download_json = metadata
BeegIE._extract_m3u8_formats = hls
yt_dlp.main()
