"""Recover the page cover when XVideos exposes it only as OpenGraph metadata."""
from urllib.parse import urlsplit

from yt_dlp.extractor.xvideos import XVideosIE as BuiltinXVideosIE


class XVideosIE(BuiltinXVideosIE, plugin_name="ciro"):
    _TESTS = []

    def _download_webpage(self, url_or_request, video_id, *args, **kwargs):
        page = super()._download_webpage(url_or_request, video_id, *args, **kwargs)
        if isinstance(url_or_request, str) and self.suitable(url_or_request) and isinstance(page, str):
            self._ciro_page = page
        return page

    def _real_extract(self, url):
        self._ciro_page = None
        result = super()._real_extract(url)
        if not self.get_param("writethumbnail") or result.get("thumbnail") or result.get("thumbnails") or not self._ciro_page:
            return result
        candidate = self._og_search_thumbnail(self._ciro_page, default=None)
        try:
            image = urlsplit(candidate or "")
            host = image.hostname or ""
            if (image.scheme == "https" and not image.username and not image.password
                    and image.port is None and len(candidate or "") <= 2048
                    and any(host == domain or host.endswith("." + domain)
                            for domain in ("xvideos.com", "xvideos-cdn.com"))):
                result["thumbnails"] = [{"url": candidate}]
        except ValueError:
            pass
        return result
