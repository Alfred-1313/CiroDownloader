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

- The current tab is green. Items are listed eight per page with Previous and Next, and a white title shows the page.
- A **track** is queued at once, in its own status message, and the menu stays open for the next one. Tapping the same track twice within two minutes is answered with "already on its way". Taps do not wait for the pause between separate links, but the usual limits (active requests per user, queue size, administrator-only mode, group switch) still apply.
- An **album**, **playlist** or **SoundCloud set** opens its tracks in the same message, with Back (to the same page of the list) and Close.
- A **similar artist** opens its own menu as a new message.
- The menu belongs to the person who sent the link, in that chat and forum topic. Others get "this menu is for the sender". It expires after 24 hours (the oldest are dropped past eight per user); a stale button says so and removes its keyboard. **Close** deletes the message.

## Where the lists come from

No account, key or cookie is involved.

- **Spotify's embed pages** give exactly what the artist's page shows as popular tracks and an album's or playlist's tracks. They carry Spotify track IDs, so those tracks go through the normal Spotify path.
- **Deezer's public API** gives the rest of an artist's catalogue: albums, singles, EPs, playlists and similar artists. Names alone are not trusted: the Deezer artist must share popular tracks with the Spotify page, or the menu shows only Spotify's popular tracks with a note. If Deezer cannot be reached the menu still opens the same way. A track chosen from a Deezer list is looked up by its title, artists, length, year and cover, then found and tagged like any Spotify track.
- **SoundCloud** lists are read with yt-dlp one at a time, when a tab is first opened, and kept in the menu. Some tracks inside a set come without a title from SoundCloud; they are shown as "Track N" and get their real title when sent.

## Customising

**🎨 شخصی‌سازی ← 🗂 منوی هنرمند** (under the «🎧 موسیقی» heading) edits every menu text (loading, header, status line, notes, expired, other person's menu, sent, repeated) and every menu button (each tab, track/album/playlist/artist rows, Previous, Next, Back, Close, page title). Rows and navigation buttons cannot be hidden; tabs can. Track titles come from the sources and are not editable.

## Verification

`php tests/music_menu.php` uses local copies of the Spotify embed pages, a fake Deezer API, a stand-in for yt-dlp and a local Telegram endpoint with real FFmpeg: link recognition, tabs and counts, paging, albums and Back, queueing from Spotify and Deezer details (no Spotify page read for the latter), the repeated-tap guard, similar artists, ownership and expiry in private chats and forum topics, Deezer failures, SoundCloud lazy lists and untitled tracks, and cleanup. It makes no request to any real site.
