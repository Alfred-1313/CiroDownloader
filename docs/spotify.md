# Spotify

Send a link to one Spotify track and the bot returns it as an MP3 with the real title, artists, year and cover. There is **no Spotify login, no Spotify cookie and no quality prompt**.

## How it works

1. The bot reads the public page of the track (`open.spotify.com/embed/track/ID`) for the title, artists, length, release year and cover.
2. It searches YouTube for `ARTIST TITLE audio` and picks the result whose **length is closest** to the track and whose title or channel shows the name or the artist. Live, cover, remix, karaoke, slowed, instrumental and similar versions lose to the studio recording unless the Spotify track itself is that version. If nothing matches well enough, the bot says the track was not found instead of sending something random.
3. The best audio of that video is downloaded through the existing YouTube path (same yt-dlp, proxy, PO Token and cookie options), converted to MP3 and tagged with the Spotify title, artists, year and cover.

Spotify's own audio streams are protected and are never downloaded. The result is the matching recording from YouTube, so it can differ slightly from Spotify's master (for example a remaster). Tracks that were never uploaded to YouTube, very new releases and some region-limited tracks may not be found.

## Cookies

Spotify needs none: the public track page is readable without an account. The YouTube step uses the YouTube cookies you already configured only when YouTube asks for them (see [cookies](cookies.md)); with "cookies as a last resort" on, it tries anonymously first. The optional social-media proxy (`GALLERY_PROXY`) also covers the Spotify page and cover, and the YouTube proxy covers the YouTube step.

## Supported links

`open.spotify.com/track/ID`, with or without a language prefix such as `intl-de`, a `?si=` share parameter or `/embed/`. Albums, playlists, artists, podcasts and `spotify.link` short links are not accepted; the bot replies with the list of supported links.

## Controls

**🎨 شخصی‌سازی ← 🎧 اسپاتیفای** (زیر تیتر «🎧 موسیقی») edits the queued, downloading and uploading messages, the caption (`{title}`, `{artist}`, `{year}`, `{duration}`, `{url}`), every error text and the source button. The **مشاهده در اسپاتیفای** button is off by default and the default caption has no link to the original. Accepted links receive the configured reaction, default 👾, and the whole request uses one status message. In groups the audio stays in the forum topic that asked for it.

## Speed

A typical track takes a few seconds for the page and the search, then the audio download and MP3 conversion. The job runs in the media lane, so it never delays quality menus of YouTube links.

## Verification

`php tests/spotify.php` uses a local copy of the track page, a stand-in for yt-dlp and a local Telegram endpoint with real FFmpeg: link validation, matching rules, tagged MP3 with cover, thumbnail, group topic, source button, failures and cleanup. It makes no request to Spotify, YouTube or Telegram.
