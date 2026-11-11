"""Exercise the observed TikPorn player structure with neutral MP4s and fresh fixture-only signatures."""
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
original_open = yt_dlp.YoutubeDL.urlopen
with open(MODE) as mode:
    settings = json.load(mode)
phase = 'inspect' if '--dump-single-json' in sys.argv else 'download'


def request(self, url, video_id, *args, **kwargs):
    if isinstance(url, str) and url == 'https://tik.porn/video/2344693':
        if settings.get('blocked'):
            raise ExtractorError('Unable to download webpage: HTTP Error 403: Forbidden at https://hidden.example/private', expected=True)
        poster_id = '9999999' if settings.get('wrong_video') else '2344693'
        page_id = '9999999' if settings.get('wrong_page') else '2344693'
        host = 'foreign.example' if settings.get('foreign_source') else 'video-cdn.tik.porn'
        sources = ''.join(f'<source src="https://{host}/videos/496300_fixture/{phase}-token/9999999999/qid5073v1_h264_1000_960_{height}.mp4" type="video/mp4" media="(min-width: 10000px)">' for height in (240, 480, 540))
        # The archive has related videos as well as the requested player. Never select the first video blindly.
        page = ('<!doctype html><html><head><title>Neutral Fixture Video | Tik.Porn</title>'
                '<meta property="og:title" content="Neutral Fixture Video | Tik.Porn">'
                f'<meta property="og:url" content="https://tik.porn/video/{page_id}">'
                '<meta property="og:image" content="https://image-cdn.tik.porn/video/2344/2344693/tryptic.jpg?ver=2"></head>'
                '<body><div data-basepath="/video/2344693#related"><video crossorigin="anonymous" '
                'poster="https://image-cdn.tik.porn/video/2344/2344694/poster.jpg?ver=2">'
                '<source src="https://video-cdn.tik.porn/videos/related/never-fetch.mp4" type="video/mp4"></video></div>'
                '<div data-basepath="/video/2344693"><video crossorigin="anonymous" style="transform:scale(0)" preload="metadata" '
                f'poster="https://image-cdn.tik.porn/video/2344/{poster_id}/poster.jpg?ver=2">'
                + sources + '</video></div></body></html>')
        return Response(io.BytesIO(page.encode()), url, headers={'Content-Type': 'text/html'})
    return original_request(self, url, video_id, *args, **kwargs)


def open_request(self, request):
    if request.url.startswith('https://image-cdn.tik.porn/video/2344/2344693/poster.jpg'):
        request.update(url=BASE + '/cover.jpg')
    elif request.url.startswith('https://video-cdn.tik.porn/videos/496300_fixture/'):
        height = request.url.rsplit('_', 1)[-1].split('.')[0]
        assert height in ('240', '480', '540')
        request.update(url=BASE + '/' + height + '.mp4?phase=' + phase)
    elif 'tik.porn' in request.url:
        raise AssertionError('Unexpected or related-video request')
    return original_open(self, request)


InfoExtractor._request_webpage = request
yt_dlp.YoutubeDL.urlopen = open_request
yt_dlp.main()
