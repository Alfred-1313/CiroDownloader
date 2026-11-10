"""Exercise the real XVideos extractor and HLS downloader against local fixtures."""
import json
import sys
import yt_dlp
from yt_dlp.extractor.common import InfoExtractor
from yt_dlp.extractor.xvideos import XVideosIE

with open(TRACE, 'a') as trace:
    trace.write(json.dumps(sys.argv[1:]) + '\n')
original = InfoExtractor._download_webpage
original_open = yt_dlp.YoutubeDL.urlopen
with open(MODE) as mode:
    settings = json.load(mode)

def page(self, url, video_id, *args, **kwargs):
    if isinstance(self, XVideosIE) and XVideosIE.suitable(url):
        cover = settings.get('cover_url', 'https://cdn42-pic.xvideos-cdn.com/cover.jpg')
        thumb = '' if settings.get('og_only') else 'setThumbUrl("' + BASE + '/cover.jpg");'
        if settings.get('broken_preferred'):
            thumb += 'setThumbUrl169("' + BASE + '/missing-cover.jpg");'
        return ('<html><head><meta property="og:title" content="Neutral Fixture Video">'
                '<meta property="og:duration" content="2"><meta property="og:image" content="' + cover + '"></head><body><script>'
                + thumb +
                'setVideoHLS("' + BASE + '/master.m3u8");</script></body></html>')
    return original(self, url, video_id, *args, **kwargs)

InfoExtractor._download_webpage = page

def open_request(self, request):
    if request.url == 'https://cdn42-pic.xvideos-cdn.com/cover.jpg':
        request.update(url=BASE + '/cover.jpg')
    return original_open(self, request)

yt_dlp.YoutubeDL.urlopen = open_request
yt_dlp.main()
