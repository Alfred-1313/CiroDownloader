"""Run the real XNXX extractor and HLS downloader using neutral local fixtures."""
import json
import sys
import yt_dlp
from yt_dlp.extractor.common import InfoExtractor
from yt_dlp.extractor.xnxx import XNXXIE

with open(TRACE, 'a') as trace:
    trace.write(json.dumps(sys.argv[1:]) + '\n')
original = InfoExtractor._download_webpage

def page(self, url, video_id, *args, **kwargs):
    if isinstance(self, XNXXIE) and XNXXIE.suitable(url):
        return ('<html><head><meta property="og:title" content="Neutral Fixture Video">'
                '<meta property="og:image" content="' + BASE + '/cover.jpg">'
                '<meta property="og:duration" content="2"></head><body>'
                '<script>setVideoHLS("' + BASE + '/master.m3u8");</script></body></html>')
    return original(self, url, video_id, *args, **kwargs)

InfoExtractor._download_webpage = page
yt_dlp.main()
