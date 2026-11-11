"""Exercise yt-dlp's generic HTML5/HLS path; this fixture does not establish live TikPorn compatibility."""
import io
import json
import sys

import yt_dlp
from yt_dlp.extractor.common import InfoExtractor
from yt_dlp.networking import Response
from yt_dlp.utils import ExtractorError

with open(TRACE, 'a') as trace:
    trace.write(json.dumps(sys.argv[1:]) + '\n')
original_request = InfoExtractor._request_webpage
with open(MODE) as mode:
    settings = json.load(mode)


def request(self, url, video_id, *args, **kwargs):
    if isinstance(url, str) and url == 'https://tik.porn/video/2344693':
        if settings.get('blocked'):
            raise ExtractorError('Unable to download webpage: HTTP Error 403: Forbidden at https://hidden.example/private', expected=True)
        page = ('<!doctype html><html><head><title>Neutral Fixture Video</title>'
                '<meta property="og:title" content="Neutral Fixture Video">'
                '<meta property="og:image" content="' + BASE + '/cover.jpg"></head>'
                '<body><video controls poster="' + BASE + '/cover.jpg">'
                '<source src="' + BASE + '/master.m3u8" type="application/x-mpegURL">'
                '</video></body></html>')
        return Response(io.BytesIO(page.encode()), url, headers={'Content-Type': 'text/html'})
    return original_request(self, url, video_id, *args, **kwargs)


InfoExtractor._request_webpage = request
yt_dlp.main()
