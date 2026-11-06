import fs from 'node:fs/promises';
import path from 'node:path';
import {fileURLToPath, pathToFileURL} from 'node:url';
process.umask(0o077);
const controller = new AbortController();
for (const signal of ['SIGTERM', 'SIGINT']) process.on(signal, () => controller.abort());
let infoPath;
try {
  const request = JSON.parse(await fs.readFile(process.argv[2], 'utf8'));
  const folder = await fs.realpath(request.folder);
  const url = new URL(request.url);
  if (url.protocol !== 'https:' || url.hostname !== 'www.youtube.com' || url.pathname !== '/watch' || !/^[\w-]{11}$/.test(url.searchParams.get('v') ?? '')) throw new Error('Invalid YouTube URL');
  if (!Array.isArray(request.options) || request.options.some(value => typeof value !== 'string')) throw new Error('Invalid downloader options');
  if (![null, 'video', 'source'].includes(request.prefix)) throw new Error('Invalid output kind');
  process.env.TMPDIR = folder;
  const local = fileURLToPath(new URL('../third_party/yoinks/lib/ytdlp.js', import.meta.url));
  const modulePath = await fs.access('/opt/yoinks/lib/ytdlp.js').then(() => '/opt/yoinks/lib/ytdlp.js').catch(() => local);
  const {probe, download} = await import(pathToFileURL(modulePath).href);
  const shim = fileURLToPath(new URL('./yoinks-ytdlp.php', import.meta.url));
  const result = await probe(shim, url.href, controller.signal);
  infoPath = result.infoJsonPath;
  if (request.prefix === null) {
    process.stdout.write(JSON.stringify(result.info));
  } else {
    // Native options are generated from the owner's saved quality selection.
    // The upstream library owns its output template and filepath stdout protocol.
    const args = [];
    for (let i = 0; i < request.options.length; i++) {
      const value = request.options[i];
      if (value === '--print' || value === '-o') { i++; continue; }
      args.push(value);
    }
    const file = await download({ytdlp: shim, url: url.href, infoJsonPath: infoPath,
      choice: {kind: request.prefix === 'source' ? 'audio' : 'video', label: 'selected', args}, outDir: folder},
      {onProgress() {}, onProcessing() {}}, controller.signal);
    const real = await fs.realpath(file);
    const relative = path.relative(folder, real);
    const ext = path.extname(real).toLowerCase();
    if (relative === '..' || relative.startsWith('..' + path.sep) || path.isAbsolute(relative) || !(await fs.lstat(file)).isFile()
        || !['.mp4', '.mkv', '.webm', '.mov', '.m4a', '.opus', '.ogg', '.mp3', '.aac', '.wav', '.flac'].includes(ext)) throw new Error('Invalid downloaded file');
    const destination = path.join(folder, request.prefix + ext);
    if (real !== destination) await fs.rename(real, destination);
    process.stdout.write(JSON.stringify(result.info.title || 'YouTube'));
  }
} catch (error) {
  // Keep upstream authentication errors useful; PHP redacts URLs and tokens.
  process.stderr.write('ERROR: ' + String(error?.message || 'Yoinks download failed') + '\n');
  process.exitCode = 1;
} finally {
  if (infoPath) await fs.unlink(infoPath).catch(() => {});
}
