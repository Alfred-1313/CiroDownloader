# Yoinks downloader core

Unmodified `src/lib/ytdlp.ts` and `src/lib/format.ts` from
[pablostanley/yoinks](https://github.com/pablostanley/yoinks), version 0.3.1,
commit `6f03720813c22f907d3fb9ba98567f2169446b82`. The MIT license is retained
in `LICENSE`. `upstream.json` pins the original source checksums.

The production Node build stage verifies those checksums and strips TypeScript
with Node's built-in transformer; no npm dependencies are needed for these core
functions. The original terminal UI and automatic binary downloader are not
invoked. `bin/yoinks-adapter.mjs` calls the actual upstream `probe` and `download`
exports and uses the already installed, pinned yt-dlp/FFmpeg binaries. The
wrapper adds isolation, proxy/size settings and exact format choices required by
the Telegram bot. Both providers ultimately depend on yt-dlp; this integration
does not remove YouTube login or IP challenges.
