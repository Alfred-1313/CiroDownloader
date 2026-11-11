"""Run the pinned native YouPorn extractor against neutral page/API/HLS/MP4 fixtures."""
import json
import sqlite3
import sys

import yt_dlp
from yt_dlp.extractor.common import InfoExtractor
from yt_dlp.extractor.youporn import YouPornIE
from yt_dlp.utils import ExtractorError

with open(TRACE, 'a') as trace:
    trace.write(json.dumps(sys.argv[1:]) + '\n')
with open(MODE) as mode:
    settings = json.load(mode)
original_page = InfoExtractor._download_webpage
original_json = InfoExtractor._download_json
original_open = yt_dlp.YoutubeDL.urlopen


def page(self, url, video_id, *args, **kwargs):
    if isinstance(self, YouPornIE) and str(url).startswith('https://www.youporn.com/watch/'):
        assert self._get_cookies(url)['age_verified'].value == '1'
        if settings.get('blocked'):
            raise ExtractorError('Unable to download webpage: HTTP Error 403: Forbidden at https://hidden.example/private', expected=True)
        phase = 'inspect' if '--dump-single-json' in sys.argv else 'download'
        if settings.get('disable') == phase:
            with sqlite3.connect(DATABASE) as database:
                database.execute("UPDATE settings SET value='0' WHERE key='adult.youporn'")
        player = {'duration': 2, 'mediaDefinitions': [
            {'format': kind, 'videoUrl': f'https://www.youporn.com/api/fixture/{kind}?token=fixture-token'}
            for kind in ('hls', 'mp4')]}
        cover = '' if settings.get('no_cover') else 'var imageurl="https://thumbs.ypncdn.com/cover.jpg";'
        return ('<html><head><meta property="og:title" content="Neutral Fixture Video">'
                '<meta name="rating" content="RTA-5042-1996-1400-1577-RTA"></head><body>'
                '<div id="watch-container"><div class="watchVideoTitle">Neutral Fixture Video</div>'
                '<div class="submitByLink">Fixture Channel</div><script>var config={playervars:'
                + json.dumps(player) + '};' + cover + '</script></div></body></html>')
    return original_page(self, url, video_id, *args, **kwargs)


def metadata(self, url, video_id, *args, **kwargs):
    if str(url).startswith('https://www.youporn.com/api/fixture/'):
        kind = url.split('/api/fixture/', 1)[1].split('?', 1)[0]
        assert kind in ('hls', 'mp4')
        return original_json(self, BASE + '/media-' + kind, video_id, *args, **kwargs)
    return original_json(self, url, video_id, *args, **kwargs)


def open_request(self, request):
    if request.url == 'https://thumbs.ypncdn.com/cover.jpg':
        request.update(url=BASE + '/cover.jpg')
    return original_open(self, request)


InfoExtractor._download_webpage = page
InfoExtractor._download_json = metadata
yt_dlp.YoutubeDL.urlopen = open_request
yt_dlp.main()
