import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile, readdir } from 'node:fs/promises';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const read = relative => readFile(path.join(root, relative));
const pngSignature = Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]);

test('CampusBite icons have valid scalable and raster formats', async () => {
  const svg = (await read('app/favicon.svg')).toString();
  assert.match(svg, /viewBox="0 0 64 64"/);
  assert.match(svg, /CampusBite CB logo/);
  assert.doesNotMatch(svg, /<script|(?:href|src)="https?:|<text\b/);
  const png = await read('app/apple-touch-icon.png');
  assert.deepEqual(png.subarray(0, 8), pngSignature);
  assert.equal(png.readUInt32BE(16), 180);
  assert.equal(png.readUInt32BE(20), 180);
  const ico = await read('app/favicon.ico');
  assert.equal(ico.readUInt16LE(0), 0);
  assert.equal(ico.readUInt16LE(2), 1);
  assert.equal(ico.readUInt16LE(4), 1);
  assert.equal(ico[6], 32);
  assert.equal(ico[7], 32);
  assert.equal(ico.readUInt32LE(18), 22);
  assert.equal(ico.readUInt32LE(14), ico.length - 22);
  assert.deepEqual(ico.subarray(22, 30), pngSignature);
  assert.equal(ico.readUInt32BE(38), 32);
  assert.equal(ico.readUInt32BE(42), 32);
});

test('Every HTML page head uses the shared favicon links', async () => {
  let heads = 0;
  for (const file of await readdir(path.join(root, 'app'))) {
    if (!file.endsWith('.php')) continue;
    const source = (await read('app/' + file)).toString();
    const head = source.match(/<head\b[^>]*>([\s\S]*?)<\/head>/i);
    if (!head) continue;
    assert.match(head[1], /require __DIR__ \. '\/components\/favicon\.php'/, file);
    heads++;
  }
  assert.equal(heads, 15);
  const result = spawnSync(process.env.PHP_BINARY || 'C:/xampp/php/php.exe',
    ['app/components/favicon.php'], { cwd: root, encoding: 'utf8' });
  assert.equal(result.status, 0, result.stderr);
  assert.match(result.stdout, /href="favicon\.ico\?v=\d+"/);
  assert.match(result.stdout, /href="favicon\.svg\?v=\d+" type="image\/svg\+xml"/);
  assert.match(result.stdout, /rel="apple-touch-icon"[^>]*sizes="180x180"/);
});

test('The static build publishes all three icons unchanged', async () => {
  for (const file of ['favicon.svg', 'favicon.ico', 'apple-touch-icon.png']) {
    assert.deepEqual(await read('public/' + file), await read('app/' + file), file);
  }
});
