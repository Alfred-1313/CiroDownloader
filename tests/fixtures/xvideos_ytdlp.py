"""Exercise the real XVideos extractor and HLS downloader against local fixtures."""
import json
import sys
import yt_dlp
from yt_dlp.extractor.common import InfoExtractor
from yt_dlp.extractor.xvideos import XVideosIE

with open(TRACE, 'a') as trace:
    trace.write(json.dumps(sys.argv[1:]) + '\n')
original = InfoExtractor._download_webpage

def page(self, url, video_id, *args, **kwargs):
    if isinstance(self, XVideosIE) and XVideosIE.suitable(url):
        return ('<html><head><meta property="og:title" content="Neutral Fixture Video">'
                '<meta property="og:duration" content="2"></head><body><script>'
                'setThumbUrl("' + BASE + '/cover.jpg");'
                'setVideoHLS("' + BASE + '/master.m3u8");</script></body></html>')
    return original(self, url, video_id, *args, **kwargs)

InfoExtractor._download_webpage = page
yt_dlp.main()
