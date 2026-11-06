import {readFileSync, writeFileSync, mkdirSync} from 'node:fs';
import {createHash} from 'node:crypto';
import {stripTypeScriptTypes} from 'node:module';
import {fileURLToPath} from 'node:url';
import path from 'node:path';
const root = path.dirname(fileURLToPath(import.meta.url));
const manifest = JSON.parse(readFileSync(path.join(root, 'upstream.json'), 'utf8'));
mkdirSync(path.join(root, 'lib'), {recursive: true});
for (const [name, checksum] of Object.entries(manifest.files)) {
  const source = readFileSync(path.join(root, 'src', name));
  if (createHash('sha256').update(source).digest('hex') !== checksum) throw new Error(`Yoinks source verification failed: ${name}`);
  const js = stripTypeScriptTypes(source.toString('utf8'), {mode: 'strip'});
  writeFileSync(path.join(root, 'lib', name.replace(/\.ts$/, '.js')), js);
}
