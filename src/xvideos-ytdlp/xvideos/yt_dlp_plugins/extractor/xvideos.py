"""Keep the page cover as a fallback when XVideos player thumbnails are missing or broken."""
from urllib.parse import urljoin, urlsplit

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
        if not self.get_param("writethumbnail") or not self._ciro_page:
            return result
        candidate = self._og_search_thumbnail(self._ciro_page, default=None) or self._html_search_meta(
            ("twitter:image", "twitter:image:src"), self._ciro_page, default=None)
        if not candidate:
            return result
        try:
            candidate = urljoin(url, candidate)
            image = urlsplit(candidate or "")
            host = image.hostname or ""
            if (image.scheme == "https" and not image.username and not image.password
                    and image.port is None and len(candidate or "") <= 2048
                    and any(host == domain or host.endswith("." + domain)
                            for domain in ("xvideos.com", "xvideos-cdn.com"))):
                thumbnails = result.get("thumbnails") or []
                if not any(item.get("url") == candidate for item in thumbnails):
                    # yt-dlp tries the player images first, then this page image if they all fail.
                    result["thumbnails"] = [{"url": candidate, "preference": -1}, *thumbnails]
        except ValueError:
            pass
        return result
