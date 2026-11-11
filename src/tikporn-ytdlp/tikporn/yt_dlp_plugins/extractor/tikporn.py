"""TikPorn's observed page player, with bounded metadata reads of its signed MP4 sources."""
import re
import struct
from html.parser import HTMLParser
from urllib.parse import urljoin, urlsplit

from yt_dlp.extractor.common import InfoExtractor
from yt_dlp.utils import ExtractorError


class _Videos(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.videos = []
        self.current = None

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == 'video':
            self.current = {'poster': attrs.get('poster'), 'sources': []}
            self.videos.append(self.current)
            if attrs.get('src'):
                self.current['sources'].append(attrs['src'])
        elif tag == 'source' and self.current is not None and attrs.get('src'):
            self.current['sources'].append(attrs['src'])

    def handle_endtag(self, tag):
        if tag == 'video':
            self.current = None


class TikPornIE(InfoExtractor):
    _VALID_URL = r'https?://(?:www\.)?tik\.porn/video/(?P<id>[0-9]{1,20})(?:[/?#]|$)'
    _TESTS = []
    _HEADERS = {'Referer': 'https://tik.porn/', 'Origin': 'https://tik.porn'}
    _CHUNK = 65536
    _MAX_MOOV = 2097152

    @staticmethod
    def _trusted(url, host):
        try:
            parts = urlsplit(url or '')
            return (parts.scheme == 'https' and parts.hostname == host and not parts.username
                    and not parts.password and parts.port is None and len(url or '') <= 8192)
        except ValueError:
            return False

    @staticmethod
    def _header(data):
        if len(data) < 8:
            return None
        size, kind = struct.unpack('>I4s', data[:8])
        header = 8
        if size == 1:
            if len(data) < 16:
                return None
            size, header = struct.unpack('>Q', data[8:16])[0], 16
        return (size, kind, header) if size == 0 or size >= header else None

    @classmethod
    def _boxes(cls, data):
        offset = 0
        for _ in range(4096):
            box = cls._header(data[offset:offset + 16])
            if box is None:
                return
            size, kind, header = box
            size = size or len(data) - offset
            if offset + size > len(data):
                return
            yield kind, data[offset + header:offset + size]
            offset += size

    @classmethod
    def _child(cls, data, *path):
        for name in path:
            data = next((body for kind, body in cls._boxes(data) if kind == name), b'')
        return data

    @classmethod
    def _metadata(cls, moov):
        movie = cls._child(moov, b'mvhd')
        duration = 0
        if len(movie) >= 20 and movie[0] == 0:
            scale, ticks = struct.unpack('>II', movie[12:20])
            duration = ticks / scale if scale else 0
        elif len(movie) >= 32 and movie[0] == 1:
            scale, ticks = struct.unpack('>IQ', movie[20:32])
            duration = ticks / scale if scale else 0
        width = height = 0
        video_codec = audio_codec = None
        for kind, track in cls._boxes(moov):
            if kind != b'trak':
                continue
            tkhd = cls._child(track, b'tkhd')
            sample = cls._child(track, b'mdia', b'minf', b'stbl', b'stsd')
            entries = list(cls._boxes(sample[8:]))
            codecs = [kind.decode('ascii', errors='ignore') for kind, _ in entries]
            if len(tkhd) >= 84:
                w, h = (value >> 16 for value in struct.unpack('>II', tkhd[-8:]))
                codec = next((codec for codec in codecs if codec in ('avc1', 'avc3', 'hvc1', 'hev1', 'av01', 'vp09', 'vp08', 'mp4v')), None)
                visual = next((body for kind, body in entries if kind.decode('ascii', errors='ignore') == codec), b'')
                if len(visual) >= 28:
                    w, h = struct.unpack('>HH', visual[24:28])
                if codec and 1 <= w <= 16384 and 1 <= h <= 16384:
                    width, height = w, h
                    video_codec = codec
            audio_codec = audio_codec or next((codec for codec in codecs if codec in ('mp4a', 'Opus', 'ac-3', 'ec-3')), None)
        return {'width': width, 'height': height, 'duration': duration,
                'vcodec': video_codec, 'acodec': audio_codec}

    def _range(self, url, video_id, offset, length):
        response = self._request_webpage(url, video_id, note='Reading MP4 metadata',
                                         headers={**self._HEADERS, 'Range': f'bytes={offset}-{offset + length - 1}'},
                                         impersonate=True)
        try:
            raw = response.read(length + 1)
            content_range = re.fullmatch(r'bytes (\d+)-(\d+)/(\d+)', response.headers.get('Content-Range', ''))
            if len(raw) > length or (offset and not content_range):
                raise ExtractorError('Requested format is not available: bounded MP4 ranges unsupported', expected=True)
            total = None
            if content_range:
                start, end, total = map(int, content_range.groups())
                if start != offset or end < start or end >= total or len(raw) != end - start + 1:
                    raise ExtractorError('Requested format is not available: invalid MP4 range', expected=True)
            elif response.headers.get('Content-Length', '').isdigit():
                total = int(response.headers['Content-Length'])
            return raw, total
        finally:
            response.close()

    def _probe(self, url, video_id):
        data, total = self._range(url, video_id, 0, self._CHUNK)
        base = offset = 0
        for _ in range(32):
            if not base <= offset <= base + len(data) - 16:
                data, _ = self._range(url, video_id, offset, self._CHUNK)
                base = offset
            box = self._header(data[offset - base:offset - base + 16])
            if box is None:
                break
            size, kind, header = box
            if not size or (total and offset + size > total):
                break
            if kind == b'moov':
                if size > self._MAX_MOOV:
                    break
                raw = data[offset - base:offset - base + size]
                if len(raw) < size:
                    raw, _ = self._range(url, video_id, offset, size)
                info = self._metadata(raw[header:])
                if info['width'] and info['height'] and info['vcodec'] and info['acodec'] and 0 < info['duration'] <= 86400:
                    return {**info, 'filesize': total}
                break
            offset += size
            if total and offset >= total:
                break
        raise ExtractorError('Requested format is not available: MP4 metadata is missing or invalid', expected=True)

    def _real_extract(self, url):
        video_id = self._match_id(url)
        webpage = self._download_webpage(url, video_id, headers=self._HEADERS, impersonate=True)
        canonical = self._og_search_property('url', webpage, default=None)
        if canonical and not re.fullmatch(rf'https?://(?:www\.)?tik\.porn/video/{re.escape(video_id)}/?(?:[?#].*)?', canonical):
            raise ExtractorError('Requested format is not available: page belongs to another video', expected=True)
        page = _Videos()
        page.feed(webpage)
        selected = None
        for video in page.videos:
            try:
                poster = urljoin(url, video.get('poster') or '')
            except ValueError:
                continue
            if (self._trusted(poster, 'image-cdn.tik.porn')
                    and re.fullmatch(rf'/video/[0-9]+/{re.escape(video_id)}/poster\.(?:jpg|jpeg|png|webp|avif)', urlsplit(poster).path)):
                selected = {**video, 'poster': poster}
                break
        if selected is None:
            raise ExtractorError('Requested format is not available: requested video player is missing', expected=True)
        formats = []
        for source in dict.fromkeys(selected['sources']):
            try:
                source = urljoin(url, source)
            except ValueError:
                continue
            if not self._trusted(source, 'video-cdn.tik.porn') or not urlsplit(source).path.endswith('.mp4'):
                continue
            if len(formats) >= 4:
                break
            info = self._probe(source, video_id)
            formats.append({**info, 'url': source, 'ext': 'mp4', 'protocol': 'https',
                            'format_id': f'http-{min(info["width"], info["height"])}-{len(formats)}'})
        if not formats:
            raise ExtractorError('Requested format is not available: no supported video sources', expected=True)
        title = self._og_search_title(webpage, default=None) or self._html_extract_title(webpage) or video_id
        return {'id': video_id, 'title': re.sub(r'\s*\|\s*Tik\.Porn\s*$', '', title, flags=re.I),
                'formats': formats, 'duration': max(fmt['duration'] for fmt in formats),
                'thumbnail': selected['poster'], 'age_limit': 18, 'http_headers': self._HEADERS}
