import { readFile, writeFile, mkdtemp, rm } from 'node:fs/promises';
import { spawnSync } from 'node:child_process';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

// Renders the home-screen icons from app/assets/campusbite-logo.svg (the cropped C.svg logo) with headless Chrome.
// The logo sits on an opaque peach tile inside the maskable safe zone, so iOS and Android can round or mask it.
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const chrome = process.env.CHROME_BINARY || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const logo = (await readFile(path.join(root, 'app/assets/campusbite-logo.svg'))).toString('base64');
const work = await mkdtemp(path.join(tmpdir(), 'campusbite-icons-'));
try {
  for (const [file, size] of [['apple-touch-icon.png', 180], ['icon-192.png', 192], ['icon-512.png', 512]]) {
    const page = path.join(work, size + '.html');
    await writeFile(page, `<!doctype html><html><body style="margin:0;width:${size}px;height:${size}px;display:grid;place-items:center;background:#fff3e8">
<img src="data:image/svg+xml;base64,${logo}" style="width:62%;height:62%" alt=""></body></html>`);
    const output = path.join(work, file);
    const result = spawnSync(chrome, ['--headless=new', '--disable-gpu', '--hide-scrollbars', '--force-device-scale-factor=1',
      '--user-data-dir=' + path.join(work, 'profile'), '--window-size=' + size + ',' + size, '--screenshot=' + output, pathToFileURL(page).href],
      { stdio: 'ignore', timeout: 60000 });
    if (result.status !== 0) throw new Error('Chrome could not render ' + file + '. Set CHROME_BINARY to override.');
    const png = await readFile(output);
    if (png.readUInt32BE(16) !== size || png.readUInt32BE(20) !== size) throw new Error(file + ' was not rendered at ' + size + 'px.');
    await writeFile(path.join(root, 'app', file), png);
  }
  console.log('Built 180px, 192px and 512px home-screen icons from the CampusBite logo.');
} finally {
  await rm(work, { recursive: true, force: true });
}
