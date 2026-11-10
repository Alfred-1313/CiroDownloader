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
        image = '/cover.webp' if settings.get('webp') else '/cover.jpg'
        cover = settings.get('cover_url', 'https://cdn42-pic.xvideos-cdn.com' + image)
        cover_field = 'twitter:image' if settings.get('twitter_only') else 'og:image'
        thumb = '' if settings.get('og_only') else 'setThumbUrl("' + BASE + image + '");'
        if settings.get('broken_preferred'):
            thumb += 'setThumbUrl169("' + BASE + '/missing-cover.jpg");'
        if settings.get('broken_all'):
            thumb = 'setThumbUrl("' + BASE + '/missing-cover.jpg");setThumbUrl169("' + BASE + '/missing-cover.jpg");'
        return ('<html><head><meta property="og:title" content="Neutral Fixture Video">'
                '<meta property="og:duration" content="2"><meta property="' + cover_field + '" content="' + cover + '"></head><body><script>'
                + thumb +
                'setVideoHLS("' + BASE + '/master.m3u8");</script></body></html>')
    return original(self, url, video_id, *args, **kwargs)

InfoExtractor._download_webpage = page

def open_request(self, request):
    for image in ('/cover.jpg', '/cover.webp'):
        if request.url == 'https://cdn42-pic.xvideos-cdn.com' + image:
            request.update(url=BASE + ('/missing-cover.jpg' if settings.get('cover_unreachable') else image))
    return original_open(self, request)

yt_dlp.YoutubeDL.urlopen = open_request
yt_dlp.main()
