# TikTok and X

Both services follow the Instagram flow: send a post link, receive its media automatically, and navigate multi-item posts in the same Telegram message. There is no quality prompt. The main provider is the existing pinned **gallery-dl 1.32.19**. Its HLS downloader uses the installed **yt-dlp 2026.10.26** when needed; no external downloader service is added.

## TikTok

Supported links are `tiktok.com/@USER/video/ID`, `/@USER/photo/ID`, `/share/video/ID`, and short links on `vm.tiktok.com`, `vt.tiktok.com` or `www.tiktok.com/t/…`. Tracking parameters and photo-selection indices are removed so the whole post is considered. Short-link resolution follows at most four validated TikTok short hops and must end at a single post. Cycles, profiles, live pages and unrelated hosts are rejected.

Videos use the extractor's best available direct media automatically. A video's own soundtrack is preserved. Photo posts support up to 35 ordered images; standalone music, covers and subtitles are excluded. Incomplete or oversized posts fail before delivery rather than returning a truncated album.

Public access may work without cookies. Login, challenges, throttling and a deployment's IP can still affect access. For a session, open **⚙️ پنل مدیریت → 🍪 ایمپورت کوکی → تیک‌تاک** and send a Netscape TXT export containing only `tiktok.com` cookies, at most 1 MiB. A nonempty, unexpired/session `sessionid`, `sessionid_ss` or `sid_tt` is required. The default private path is `/app/storage/tiktok-cookies.txt`; `TIKTOK_COOKIES_FILE` can override it.

## X (Twitter)

Supported links include `x.com/USER/status/ID`, legacy `twitter.com` and mobile equivalents, and `/i/status/ID` or `/i/web/status/ID`. A `/photo/N` or `/video/N` suffix selects the full post for download. Profiles, spaces, searches and feeds are not downloaded.

Only native photos, videos and animated-GIF videos from the requested post are included. Quoted posts, conversation expansion, external cards and X long-form articles are excluded. Mixed posts use the same Previous/Next carousel. HLS-only videos are processed through the bundled downloader.

For authenticated access, open **⚙️ پنل مدیریت → 🍪 ایمپورت کوکی → X** and send only `x.com` browser cookies in Netscape format, at most 1 MiB, including an unexpired/session `auth_token`. Keep `ct0` in the export when present. Old `twitter.com` post links are accepted, but the cookie export must come from the current `x.com` login. The default private path is `/app/storage/twitter-cookies.txt`; `TWITTER_COOKIES_FILE` can override it. Guest access is attempted when there is no stored jar, and availability depends on X.

## Shared controls

Each service has its own reply-keyboard cookie panel for import, metadata-only viewing, protected export and hash-bound deletion confirmation. Jars stay independent from YouTube, Instagram, Pinterest and each other. Downloads use private temporary copies; imports validate structure and expiry, while actual access is tested by sending a post.

**🎨 شخصی‌سازی → تیک‌تاک / X** exposes separate captions, progress/error texts, Previous/Next labels, source visibility and optional article output. Blue headings are display-only and feature controls are neutral. The source button defaults off, and default captions omit source links. Accepted links receive the configured reaction, default 👾.

Carousels belong to the sender, chat and original forum topic. New clicks are bounded and revision-checked; only one media message is edited, and confirmed file IDs are reused when returning. Private prepared slides are kept for 24 hours in `tiktok-pages` or `twitter-pages`, cleaned on failure/expiry and excluded from backups. Optional external articles are enabled separately per service and use the existing ordered, confirmed-publication pipeline.

## Verification

Local fixtures run the actual pinned parsers, iterators, downloaders, media conversion and Telegram HTTP client. They cover session recognition and media delivery without contacting live platforms. A deployment's IP, actual session validity and platform changes still require checking with a real post after updating the bot.
