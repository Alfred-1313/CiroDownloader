"""Beeg API compatibility for the pinned yt-dlp, loaded only for Beeg jobs."""
from urllib.parse import urljoin, urlsplit

from yt_dlp.extractor.beeg import BeegIE as BuiltinBeegIE
from yt_dlp.utils import ExtractorError, int_or_none, str_or_none, unified_timestamp


class BeegIE(BuiltinBeegIE, plugin_name="ciro"):
    _TESTS = []
    _HEADERS = {"Origin": "https://beeg.com", "Referer": "https://beeg.com/"}

    @staticmethod
    def _title(data):
        file = data.get("file") or {}
        title = (file.get("stuff") or {}).get("sf_name")
        if not title:
            title = next((item.get("cd_value") for item in file.get("data") or []
                          if isinstance(item, dict) and item.get("cd_column") == "sf_name"), None)
        return title.strip() if isinstance(title, str) and title.strip() else None

    def _real_extract(self, url):
        video_id = self._match_id(url)
        # Public links keep their leading zeros; the facts/playback API wants a numeric ID.
        api_id = video_id.lstrip("0") or "0"
        api_url = f"https://store.externulls.com/facts/file/{api_id}"
        video = self._download_json(api_url, video_id, headers=self._HEADERS)
        if not isinstance(video, dict) or not isinstance(video.get("file"), dict):
            raise ExtractorError("Beeg returned no video metadata", expected=True)

        # Formats are no longer in file.hls_resources. This endpoint returns the master playlist path.
        play_path = self._download_webpage(
            f"https://store.externulls.com/video/play_url/{api_id}", video_id,
            note="Downloading playback address", headers=self._HEADERS).strip()
        if not play_path or len(play_path) > 8192 or any(char.isspace() for char in play_path):
            raise ExtractorError("Beeg returned an invalid playback address", expected=True)
        playlist = urljoin("https://video.beeg.com/", play_path)
        try:
            parts = urlsplit(playlist)
            valid = (parts.scheme == "https" and parts.hostname in ("video.beeg.com", "video.externulls.com")
                     and not parts.username and not parts.password and parts.port is None)
        except ValueError:
            valid = False
        if not valid:
            raise ExtractorError("Beeg returned an unsupported playback address", expected=True)
        formats = self._extract_m3u8_formats(
            playlist, video_id, ext="mp4", m3u8_id="hls", headers=self._HEADERS)

        title = self._title(video)
        if not title:
            details = self._download_json(api_url, video_id, query={"tag": "27173"},
                                          headers=self._HEADERS, fatal=False)
            if isinstance(details, dict):
                title = self._title(details)
        # The API has no stable thumbnail field. The page advertises its own cover via OpenGraph.
        # Only inspection writes a thumbnail; avoid another page request for each selected download.
        thumbnail = None
        if self.get_param("writethumbnail"):
            webpage = self._download_webpage(url, video_id, headers=self._HEADERS, fatal=False)
            if webpage:
                candidate = self._og_search_thumbnail(webpage, default=None)
                try:
                    image = urlsplit(candidate or "")
                    host = image.hostname or ""
                    if (image.scheme == "https" and not image.username and not image.password
                            and image.port is None and len(candidate or "") <= 2048
                            and any(host == domain or host.endswith("." + domain)
                                    for domain in ("beeg.com", "externulls.com"))):
                        thumbnail = candidate
                except ValueError:
                    pass
                title = title or self._og_search_title(webpage, default=None)
        facts = [fact for fact in video.get("fc_facts") or [] if isinstance(fact, dict)]
        first = min(facts, key=lambda fact: int_or_none(fact.get("id")) or 0, default={})
        file = video["file"]
        return {
            "id": video_id, "display_id": str_or_none(first.get("id")), "title": title or video_id,
            "thumbnail": thumbnail,
            "description": (file.get("stuff") or {}).get("sf_story"),
            "duration": int_or_none(file.get("fl_duration")),
            "timestamp": unified_timestamp(first.get("fc_created")),
            "tags": [tag["tg_name"] for tag in video.get("tags") or []
                     if isinstance(tag, dict) and isinstance(tag.get("tg_name"), str)],
            "age_limit": 18, "formats": formats, "http_headers": self._HEADERS,
        }
