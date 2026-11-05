import { readFile, writeFile, mkdtemp, rm } from 'node:fs/promises';
import { spawnSync } from 'node:child_process';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

// Render every icon from the same artwork used by the shared website header.
// Desktop icons have transparent rounded corners; mobile maskable icons retain a full safe-zone background.
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const chrome = process.env.CHROME_BINARY || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const artwork = await readFile(path.join(root, 'app/assets/campusbite-logo.svg'), 'utf8');
const logo = Buffer.from(artwork).toString('base64');
const work = await mkdtemp(path.join(tmpdir(), 'campusbite-icons-'));
try {
  const embedded = artwork.replace('<svg ', '<svg x="3" y="3" width="58" height="58" ');
  await writeFile(path.join(root, 'app/favicon.svg'), `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" role="img" aria-labelledby="title"><title id="title">CampusBite CB logo</title><rect width="64" height="64" rx="14" fill="#fff3e8"/>${embedded}</svg>\n`);
  for (const [file, size] of [['favicon-32.png', 32], ['assets/campusbite-email.png', 128], ['apple-touch-icon.png', 180], ['icon-192.png', 192], ['icon-512.png', 512], ['icon-maskable-512.png', 512]]) {
    const rounded = file === 'favicon-32.png' || /^icon-(192|512)\.png$/.test(file);
    const page = path.join(work, path.basename(file) + '.html');
    await writeFile(page, `<!doctype html><html><body style="margin:0;width:${size}px;height:${size}px;background:transparent">
<div style="width:100%;height:100%;display:grid;place-items:center;background:#fff3e8;border-radius:${rounded ? '24%' : '0'};overflow:hidden">
<img src="data:image/svg+xml;base64,${logo}" style="width:${size<=128 ? 92 : 62}%;height:${size<=128 ? 92 : 62}%" alt=""></div></body></html>`);
    const output = path.join(work, path.basename(file));
    const result = spawnSync(chrome, ['--headless=new', '--disable-gpu', '--hide-scrollbars', '--force-device-scale-factor=1', ...(rounded ? ['--default-background-color=00000000'] : []),
      '--user-data-dir=' + path.join(work, 'profile'), '--window-size=' + size + ',' + size, '--screenshot=' + output, pathToFileURL(page).href],
      { stdio: 'ignore', timeout: 60000, windowsHide: true });
    if (result.status !== 0) throw new Error('Chrome could not render ' + file + '. Set CHROME_BINARY to override.');
    const png = await readFile(output);
    if (png.readUInt32BE(16) !== size || png.readUInt32BE(20) !== size) throw new Error(file + ' was not rendered at ' + size + 'px.');
    if (size === 32) {
      const header = Buffer.alloc(22);
      header.writeUInt16LE(1, 2); header.writeUInt16LE(1, 4);
      header[6] = 32; header[7] = 32;
      header.writeUInt16LE(1, 10); header.writeUInt16LE(32, 12);
      header.writeUInt32LE(png.length, 14); header.writeUInt32LE(22, 18);
      await writeFile(path.join(root, 'app/favicon.ico'), Buffer.concat([header, png]));
    } else await writeFile(path.join(root, 'app', file), png);
  }
  console.log('Built browser, email and home-screen icons from the shared CampusBite artwork.');
} finally {
  await rm(work, { recursive: true, force: true });
}
