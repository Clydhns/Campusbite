import { readdir, mkdir, copyFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const source=path.join(root,'app'), output=path.join(root,'public');
const allowed=new Set(['.css','.js','.png','.jpg','.jpeg','.webp','.svg','.ico','.woff','.woff2']);
await mkdir(output,{recursive:true});
let count=0;
async function copy(dir,relative='') {
  for (const entry of await readdir(dir,{withFileTypes:true})) {
    if (entry.isSymbolicLink()) throw new Error('Refusing symlink in static sources');
    const rel=path.join(relative,entry.name);
    if (entry.isDirectory()) {
      if ((relative==='' && entry.name==='assets') || relative==='assets' || relative.startsWith('assets'+path.sep)) {
        await copy(path.join(dir,entry.name),rel);
      }
    } else if (entry.isFile() && allowed.has(path.extname(entry.name).toLowerCase())) {
      const dest=path.join(output,rel);
      await mkdir(path.dirname(dest),{recursive:true});
      await copyFile(path.join(dir,entry.name),dest); count++;
    }
  }
}
await copy(source);
async function verify(dir) {
  for (const entry of await readdir(dir,{withFileTypes:true})) {
    if (entry.isSymbolicLink()) throw new Error('Unexpected symlink in public output');
    const file=path.join(dir,entry.name);
    if (entry.isDirectory()) await verify(file);
    else {
      if (!allowed.has(path.extname(entry.name).toLowerCase())) throw new Error('Unsafe file in static output: '+entry.name);
      if (path.extname(entry.name).toLowerCase()==='.php') throw new Error('PHP source in static output');
    }
  }
}
await verify(output);
console.log(`Built ${count} static assets. PHP, configuration, tests, and uploads are not public files.`);
