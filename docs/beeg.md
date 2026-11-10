# Beeg

Beeg uses the existing Pornhub/YouTube quality-selection flow through the installed `yt-dlp`. This addition stays on `main` without changing the version or creating a release.

The pinned upstream Beeg extractor still sends leading-zero IDs to the facts API and expects formats in `hls_resources`. The bundled Beeg-only compatibility plugin sends a numeric API ID with the site's Origin/Referer headers, reads `/video/play_url/ID`, and parses the returned master playlist. Headers also accompany playlist and segment requests. The public link and saved job URL retain their original ID. This addresses the HTTP 400 reported in [yt-dlp #14037](https://github.com/yt-dlp/yt-dlp/issues/14037) and [#17680](https://github.com/yt-dlp/yt-dlp/issues/17680); YouTube and Pornhub use their existing extractors.

## Enable and test

1. Open **🎨 شخصی‌سازی ← ⚙️ قابلیت‌ها و محدودیت‌ها ← 🔞 تنظیمات سایت بزرگسال**.
2. Turn on **🔞 Beeg**. It defaults off and is independent of the Pornhub switch.
3. Send a single video link, for example the shape `https://beeg.com/-VIDEO_ID`, then choose an offered quality or audio. Only the requester can choose.

The existing **فقط مدیرها** restriction applies to both adult sites; it defaults on. Group use must also be enabled. A bot administrator receives a private explanation when the site or group switch prevents intake. Ordinary users receive the same refusal as the Pornhub flow.

## Accepted links

Single numeric video pages on `beeg.com` or `www.beeg.com` are supported, with an optional leading minus or `/video/` path. Leading zeros are preserved. Tracking and time-selection query parameters are removed so the full video is requested. Home pages, profiles, categories, other hosts, embedded credentials, ports and extra path components are rejected.

Beeg stays outside the always-on public media list. Enabling Pornhub does not enable Beeg, and disabling Pornhub does not disable an independently enabled Beeg.

## Delivery and settings

The same status message becomes the quality menu. The plugin reads the page’s OpenGraph cover on the site’s HTTPS image hosts and yt-dlp uploads it into that same message, with the quality caption beneath it. If it is missing, the menu remains text. The delivered MP4 includes a small JPEG thumbnail taken from the actual video; it is generated locally without a second download. Its caption uses the measured resolution of the validated MP4, never an estimated bitrate label. The existing ladder (240/360/480/720/1080, as offered) and upload-size checks apply. The audio option extracts MP3 from the smallest available stream. Downloads use the configured social-media proxy when present, without passing YouTube cookies.

The quality-cover caption uses the same customizable cover text as Pornhub and XVideos; offered qualities appear on the buttons. **🎨 شخصی‌سازی ← 🔞 Beeg** contains the independent delivery caption, people/views/tags, deletion notice and error texts. Caption structure follows Pornhub: deletion notice first in a quote, an empty line, title and details, then collapsed tags. There is no source link or button. Missing metadata fields stay empty.

The existing private/group auto-delete switches and delay are shared with Pornhub; their saved values are retained. Defaults are on with a ten-second delay. Pending deletions survive restart. Access is rechecked before extraction, after inspection and before upload, so disabling a site during processing prevents subsequent delivery.

Reports count Beeg separately, including download/upload speed when a sample meets the existing minimum size. Failures retain a short cause such as `beeg=certificate` in administrator diagnostics; raw URLs and tokens are not retained in recent errors.

## Verification

`tests/beeg.php` runs the pinned yt-dlp with the bundled Beeg plugin, real HTTP metadata/playback requests, HLS parser/downloader and FFmpeg against neutral local fixtures, with real multipart uploads to a local Telegram endpoint. The API fixture returns HTTP 400 for a leading-zero ID or missing site headers and supplies formats only through the new playback endpoint. It covers URL rules, independent switches, access restrictions, quality ownership, MP4/MP3 delivery, captions, deletion and reports. This validates the integration without contacting Beeg or Telegram; real access from a deployment's IP is tested by sending a video link after updating.
