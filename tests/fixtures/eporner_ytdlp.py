"""Run the pinned native Eporner extractor with neutral page/XHR/HLS/MP4 data."""
import json
import sqlite3
import sys
from types import SimpleNamespace

import yt_dlp
from yt_dlp.extractor.common import InfoExtractor
from yt_dlp.extractor.eporner import EpornerIE
from yt_dlp.utils import ExtractorError, encode_base_n

with open(TRACE, 'a') as trace:
    trace.write(json.dumps(sys.argv[1:]) + '\n')
with open(MODE) as mode:
    settings = json.load(mode)
original_page = InfoExtractor._download_webpage_handle
original_json = InfoExtractor._download_json
original_open = yt_dlp.YoutubeDL.urlopen
HASH = '0123456789abcdef0123456789abcdef'


def page(self, url, video_id, *args, **kwargs):
    if isinstance(self, EpornerIE) and str(url).startswith('https://www.eporner.com/video-'):
        if settings.get('blocked'):
            raise ExtractorError('Unable to download webpage: HTTP Error 403: Forbidden at https://hidden.example/private', expected=True)
        phase = 'inspect' if '--dump-single-json' in sys.argv else 'download'
        if settings.get('disable') == phase:
            with sqlite3.connect(DATABASE) as database:
                database.execute("UPDATE settings SET value='0' WHERE key='adult.eporner'")
        data = {'@context': 'https://schema.org', '@type': 'VideoObject',
                'name': 'Neutral Fixture Video', 'duration': 'PT2S',
                'uploadDate': '2026-01-01T00:00:00Z'}
        if not settings.get('no_cover'):
            data['thumbnailUrl'] = 'https://www.eporner.com/cover.jpg'
        html = ('<html><head><meta property="og:title" content="Neutral Fixture Video">'
                '<meta name="duration" content="00:02">'
                '<script type="application/ld+json">' + json.dumps(data) + '</script>'
                '</head><body><script>var hash="' + HASH + '";</script></body></html>')
        return html, SimpleNamespace(url=url)
    return original_page(self, url, video_id, *args, **kwargs)


def metadata(self, url, video_id, *args, **kwargs):
    if isinstance(self, EpornerIE) and str(url).startswith('http://www.eporner.com/xhr/video/'):
        assert url.endswith('/HmstDuT4BNE')
        query = kwargs.pop('query')
        assert query == {'hash': ''.join(encode_base_n(int(HASH[i:i+8], 16), 36) for i in range(0, 32, 8)),
                         'device': 'generic', 'domain': 'www.eporner.com', 'fallback': 'false'}
        return original_json(self, BASE + '/media', video_id, *args, **kwargs)
    return original_json(self, url, video_id, *args, **kwargs)


def open_request(self, request):
    if request.url == 'https://www.eporner.com/cover.jpg':
        request.update(url=BASE + '/cover.jpg')
    return original_open(self, request)


InfoExtractor._download_webpage_handle = page
InfoExtractor._download_json = metadata
yt_dlp.YoutubeDL.urlopen = open_request
yt_dlp.main()
