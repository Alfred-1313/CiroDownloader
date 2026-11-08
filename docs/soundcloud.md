# SoundCloud

Send a link to one SoundCloud track and the bot returns it as an MP3 with the track's own title, artist, release year and cover. There is **no account, no cookie and no quality prompt**.

## Links

`soundcloud.com/ARTIST/TRACK` (also `www.` and `m.`; share parameters such as `?si=` are dropped) and the share links of the mobile app, `on.soundcloud.com/CODE`. A share link is followed through at most three redirects and only if each step stays on SoundCloud's own track addresses; anything else is refused. Profiles, lists and sets open a [menu](music-menu.md) instead of one download. Charts, private tracks (links with a secret token) and other pages are not accepted; the bot answers with the list of supported links. A bare `api.soundcloud.com/tracks/ID` address is accepted too (menus use it for tracks a set lists without a title).

## How it works

The bot downloads the track page with the bundled yt-dlp, converts the audio to MP3 (192 kbps) and writes the title, artist, year and cover into the file without re-encoding it. Some tracks are public only as a **30 second preview**, and official uploads of some labels are **DRM-encrypted**; the bot never sends a preview as if it were the song and never decrypts anything. When SoundCloud's own file cannot be taken (DRM on an official upload, a preview only, or SoundCloud refusing the server), the bot reads the track's details and **finds the same recording on YouTube**, exactly as for a Spotify track, then tags it with SoundCloud's title, artist and cover. That needs YouTube to work on your server; if it does not, the message says so and the health report shows `soundcloud=format, youtube=blocked`.

The social-media proxy (`GALLERY_PROXY`) is used when one is set. SoundCloud is also the second source for [Spotify tracks](spotify.md) when YouTube refuses the server.

## Controls

**🎨 شخصی‌سازی ← 🎧 ساوندکلاود** (under the «🎧 موسیقی» heading) edits the status messages, the caption (`{title}`, `{artist}`, `{year}`, `{duration}`, `{url}`), every error text and the source button. The **مشاهده در ساوندکلاود** button is off by default and the default caption has no link. The request uses one status message, and in groups the audio stays in the forum topic that asked for it.

## Link reaction for music

Accepted links react on the user's message. Telegram offers bots no music emoji (no 🎧 or 🎵) among the reactions they may use, and custom-emoji reactions work only where chat administrators allowed them. Spotify and SoundCloud links therefore have their own choice, **🔥 by default**, set in **⚙️ قابلیت‌ها ← ری‌اکشن لینک**: pick one of the preset emoji, or "same as other links". Turning reactions off turns them off for music too. See [reactions](reactions.md).

## Verification

`php tests/soundcloud.php` uses a stand-in for yt-dlp and a local Telegram endpoint with real FFmpeg: link validation, share-link redirects (foreign hosts, loops, no redirect), tagged MP3, group topic, source button, the music reaction settings, preview-only refusal and cleanup. It makes no request to SoundCloud or Telegram.
