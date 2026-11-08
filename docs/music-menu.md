# Music menus: artists, albums, playlists and SoundCloud profiles

Send a link to a **page that lists music** and the bot opens a menu in the same message instead of one download. One tap on a track sends it as a tagged MP3, exactly like pasting that track's link (see [Spotify](spotify.md) and [SoundCloud](soundcloud.md)).

## Links that open a menu

| Link | Menu |
| --- | --- |
| `open.spotify.com/artist/ID` | 🔥 popular tracks, 💿 albums, 💽 singles, 📀 EPs, 🗂 compilations, 📻 playlists, 👥 similar artists |
| `open.spotify.com/album/ID` | the album's tracks |
| `open.spotify.com/playlist/ID` | the playlist's tracks (the first 100) |
| `soundcloud.com/NAME` (also `/tracks`, `/albums`, `/sets`, `/reposts`, `/likes`) | 🎵 tracks, 💿 albums, 📻 playlists, 🔁 reposts, ❤️ likes |
| `soundcloud.com/NAME/sets/SET` | the set's tracks |
| `deezer.com/artist/ID` | the same artist menu as Spotify, from Deezer |

Only tabs that have something in them are shown, with their counts. Podcasts, shows and private or secret-token links are not accepted.

## What the menu does

- When the page has a picture (the artist, the album or playlist cover, the SoundCloud avatar or set artwork) the menu is a **photo** with the text as its caption. Opening an album or playlist replaces the picture with **that album's own cover** in the same message, and the red **Back** brings the artist and the same page of the list back. Without a picture, or if Telegram cannot fetch it, the menu stays a text message.
- The current tab is green. Items are listed eight per page with Previous and Next, and a white title shows the page.
- A **track** is queued at once. By default the menu then **turns into that download's status message** (no new message) and is deleted when the audio arrives; the audio has the album's caption structure (the album in bold when the track was chosen inside one, then the track in a bold collapsed quote) and the red **🔙 بازگشت به منوی قبل**, which deletes the audio and brings the menu back as a new message. With **بستن منو پس از انتخاب** turned off, the menu stays open and each track gets its own status message (no back button needed); tapping the same track twice within two minutes is answered with "already on its way". Taps do not wait for the pause between separate links, but the usual limits (active requests per user, queue size, administrator-only mode, group switch) still apply.
- Inside an album, playlist or SoundCloud set (and on an album or playlist page), **📥 دانلود همه** (green) and **🗜 همه در ZIP** (green) fetch every track (up to 50); a **single** (opened from the singles tab) has no download all. All tracks are prepared first in a **pool** (four at a time by default, `MUSIC_PARALLEL`; the next track starts the moment any slot frees up), with progress and a red **⏹ توقف** on the menu message; the progress lists, in a quote, the names of the tracks that could not be prepared. Only the person who asked can stop. Then **everything is sent together**: audio files as media groups (at most ten per group, sizes balanced), or ZIP files (each below the upload limit, stored without compression, in album order).
- **Stop** sends nothing: the menu comes back in the same message with a green **▶️ ادامه دانلود (N از M)**, and the tracks already prepared are kept (24 hours, `storage/music-batches`, not in backups) so continuing does not start over.
- The caption is on the **last track** of the last group: the album name in **bold**, an empty line, and a **collapsed (expandable) quote** with one bold `• track name` line per track (track names only, no artist; cut with "… و N آهنگ دیگر" if it would pass Telegram's limit). Tracks that could not be found are **not** in the caption. Telegram shows no buttons under a media group, so the red **🔙 بازگشت به منوی قبل** comes in a message right under the group whose text is invisible by default (`batch_back_text`, editable); a single sent track carries its caption and the button itself. The old menu message is **deleted**. Tapping the button **deletes all the sent messages** (and the button's message) and brings the album menu back as a new message (with its cover when it had one).
- A **similar artist** opens its own menu as a new message.
- The menu belongs to the person who sent the link, in that chat and forum topic. Others get "this menu is for the sender". It expires after 24 hours (the oldest are dropped past eight per user); a stale button says so and removes its keyboard. **Close** deletes the message.

## Where the lists come from

No account, key or cookie is involved.

- **Spotify's embed pages** give exactly what the artist's page shows as popular tracks, an album's or playlist's tracks, and their pictures. They carry Spotify track IDs, so those tracks go through the normal Spotify path.
- **Deezer's public API** gives the rest of an artist's catalogue: albums, singles, EPs, playlists and similar artists, with covers. The Deezer artist is found through the artist's own popular tracks (same title and length); only if that finds nothing are same-name artists compared by shared titles, or by exact lengths for names written in another alphabet.
- **Apple's iTunes Search API** is used when Deezer does not know the artist (many Persian artists, for example): albums, singles and EPs with their artwork, again only for an artist whose songs match the Spotify artist's popular tracks by title and length. iTunes has no playlists or similar artists. If neither knows the artist, the menu shows only Spotify's popular tracks with a note.
- A track chosen from a Deezer or iTunes list is looked up by its title, artists, length, year and cover, then found and tagged like any Spotify track.
- **SoundCloud** lists are read with yt-dlp one at a time, when a tab is first opened, and kept in the menu; the profile and set pictures come from their public pages. Some tracks inside a set come without a title from SoundCloud; they are shown as "Track N" and get their real title when sent.

## Customising

**🎨 شخصی‌سازی ← 🗂 منوی هنرمند** (under the «🎧 موسیقی» heading) edits every menu text (loading, header, status line, notes, expired, other person's menu, sent, repeated, download-all progress, summary, ZIP caption) and every menu button (each tab, track/album/playlist/artist rows, download all, ZIP, stop, Previous, Next, Back, Close, page title), and has the **بستن منو پس از انتخاب** switch and the **فرمت آهنگ** switch (fast, or always MP3). Rows, navigation and stop cannot be hidden; tabs and the download-all buttons can. Track titles come from the sources and are not editable.

## Verification

`php tests/music_menu.php` uses local copies of the Spotify embed pages, fake Deezer and iTunes APIs and SoundCloud pages (`tests/fixtures/music_sites.php`), a stand-in for yt-dlp and a local Telegram endpoint with real FFmpeg: link recognition, photo menus and album covers, the red Back, tabs and counts, paging, closing into the download, download all (one by one, ZIP split by size, skipped tracks, stop by the owner only), iTunes for artists Deezer lacks, queueing from Spotify and Deezer details (no Spotify page read for the latter), the repeated-tap guard, similar artists, ownership and expiry in private chats and forum topics, Deezer failures, SoundCloud lazy lists and untitled tracks, and cleanup. It makes no request to any real site.
