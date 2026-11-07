# Pinterest

Pinterest uses the pinned **gallery-dl 1.32.19** already installed for Instagram. It supports native pin photos, videos, image carousels and visual items in story/Idea pins. yt-dlp also has a Pinterest video extractor; gallery-dl supplies the photo and multi-item flow needed here. When several direct MP4 formats exist, the adapter selects the largest dimensions without asking for quality; HLS remains available when there is no direct MP4.

Send a single `pinterest.com/pin/…/` or `pin.it/…` link in private chat or a supported group. Known regional Pinterest domains normalize to `www.pinterest.com`. A short link is resolved by the worker and must lead to a single pin on an accepted Pinterest domain. Profiles, boards and other destinations are rejected. There is no board-wide download.

The behavior follows Instagram: automatic single-file delivery, or one carousel message with neutral Previous/Next buttons. Each navigation replaces that message's media. Only the original sender in the original chat can navigate; explicit topic mismatches and stale/cross-service callbacks are rejected. Up to 20 visual items are supported. Missing/unsupported visual items fail the request before delivery. Story music and paragraph attachments are excluded; a video's own soundtrack is retained.

## Cookies

Cookies are optional for public pins when Pinterest permits anonymous access. Login requirements, account restrictions, throttling or a server IP restriction may still require a working session; neither tool guarantees anonymous downloads from every server.

Open **⚙️ پنل مدیریت → 🍪 ایمپورت کوکی → پینترست** to import, inspect, export or delete the Pinterest jar. Export only `pinterest.com` cookies from your own logged-in browser in Netscape format. The file must be at most 1 MiB and include an unexpired or session `_pinterest_sess` cookie. Import validates the file structure and session expiry; sending a pin tests actual access.

The default private path is `/app/storage/pinterest-cookies.txt`; `PINTEREST_COOKIES_FILE` can override it. YouTube and Instagram jars remain independent. Each download receives a mode-0600 private copy which is cleaned afterward. Exports are private administrator-only protected documents. Deletion requires confirmation bound to the jar's current hash.

## Personalization

**🎨 شخصی‌سازی → پینترست** has a blue display-only heading and neutral controls. Captions, statuses, errors and Previous/Next labels are independently editable. The source button defaults off, and default captions contain no original-post link. Its visibility can be toggled for both single files and future carousel transitions. The configured link reaction, default 👾, also applies to accepted Pinterest links.

The default multi-item output stays inside Telegram. Administrators can explicitly select the same external Telegraph article output supported for Instagram; ordered media hosting and confirmed-publication checks are shared, with separate Pinterest text settings. Mode changes affect new requests.

Prepared slides remain private in `storage/pinterest-pages` for 24 hours so Previous works after the last slide. Expired/failed sessions are cleaned by the worker. Backups include the cookie jar and settings but exclude this temporary page cache.

## Verification

Local tests use the actual pinned extractor, downloader, FFmpeg and Telegram HTTP client with controlled API/media fixtures. They verify the integration, not Pinterest access from a deployment's IP or the validity of a real login session.
