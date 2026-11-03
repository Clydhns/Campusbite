import { PGlite } from '@electric-sql/pglite';
import { PGLiteSocketServer } from '@electric-sql/pglite-socket';
import { readFile, readdir, writeFile, mkdir, rm } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import { spawn, spawnSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

// Local-only dev server: PGlite PostgreSQL saved in tools/.dev-data plus the PHP built-in server.
// Pass --reset to delete the saved data and start again from the seed accounts.
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const php=process.env.PHP_BINARY || 'C:/xampp/php/php.exe';
const dbPort=Number(process.env.DEV_DB_PORT || 18768), appPort=Number(process.env.DEV_APP_PORT || 18769);
const dataDir=process.env.DEV_DATA_DIR || path.join(root,'tools/.dev-data');
const password=process.env.DEV_PASSWORD || 'campusbite-dev';
const accounts=[[1,'ADMIN','admin'],[2,'STAFF','staff'],[3,'STUDENT','student']];

if(process.argv.includes('--reset')) { await rm(dataDir,{recursive:true,force:true}); console.log('Local dev data reset.'); }
await mkdir(dataDir,{recursive:true});
// Uploaded photos and receipts are kept on disk here instead of Supabase Storage.
const storageDir=path.join(dataDir,'storage');
await mkdir(storageDir,{recursive:true});
// Keep the session secret with the data so sign-ins survive restarts.
const secretFile=path.join(dataDir,'session-secret');
if(!existsSync(secretFile)) await writeFile(secretFile,randomBytes(48).toString('hex'));
const sessionSecret=(await readFile(secretFile,'utf8')).trim();

const db=new PGlite(path.join(dataDir,'pgdata'));
await db.exec("SET TIME ZONE 'Asia/Manila'");
// Apply migrations in filename order, remembering which ones this local database already has.
const fresh=!(await db.query("SELECT to_regclass('public.users') AS users")).rows[0].users;
const migrations=(await readdir(path.join(root,'supabase/migrations'))).filter(name=>name.endsWith('.sql')).sort();
await db.exec('CREATE SCHEMA IF NOT EXISTS campusbite_dev; CREATE TABLE IF NOT EXISTS campusbite_dev.applied_migrations(name text PRIMARY KEY);');
// Stand-ins for Supabase's built-in roles and Storage bucket table, as in tools/runtime.test.mjs.
if(fresh) await db.exec('CREATE ROLE anon; CREATE ROLE authenticated; CREATE SCHEMA storage; CREATE TABLE storage.buckets(id text PRIMARY KEY,name text,public boolean,file_size_limit bigint,allowed_mime_types text[]);');
else if(!(await db.query('SELECT 1 FROM campusbite_dev.applied_migrations')).rows.length) {
  // Databases created before tracking existed already contain the first four migrations.
  for(const name of migrations.filter(name=>name<'20261102')) await db.query('INSERT INTO campusbite_dev.applied_migrations VALUES ($1)',[name]);
}
const applied=new Set((await db.query('SELECT name FROM campusbite_dev.applied_migrations')).rows.map(row=>row.name));
for(const name of migrations.filter(name=>!applied.has(name))) {
  await db.exec(await readFile(path.join(root,'supabase/migrations',name),'utf8'));
  await db.query('INSERT INTO campusbite_dev.applied_migrations VALUES ($1)',[name]);
  if(!fresh) console.log('Applied migration '+name);
}
if(fresh) {
  const hashResult=spawnSync(php,['-r','echo password_hash($argv[1], PASSWORD_DEFAULT);',password],{encoding:'utf8'});
  if(hashResult.status!==0) throw new Error('Unable to run PHP at '+php+'. Set PHP_BINARY to override.');
  for(const [role,number,email] of accounts) {
    await db.query('INSERT INTO public.users(role_id,id_number,first_name,last_name,email,password_hash) VALUES ($1,$2,$3,$4,$5,$6)',[role,'DEV-'+number,'Dev',number,email+'@example.invalid',hashResult.stdout]);
  }
  await db.exec("INSERT INTO public.stores(staff_user_id,store_name,opens_at,closes_at) VALUES (2,'Dev Kitchen','00:00','23:59'); INSERT INTO public.menu_items(category_id,item_name,price,store_id) SELECT category_id,'Dev Meal',50,1 FROM public.categories WHERE category_name='Rice Meals'; INSERT INTO public.daily_menu(menu_date,item_id,quantity_prepared,quantity_remaining) VALUES (CURRENT_DATE,1,10,10);");
  console.log('Created a new local database with the seed accounts.');
} else console.log('Using saved local data from '+dataDir);

const server=new PGLiteSocketServer({db,host:'127.0.0.1',port:dbPort,maxConnections:6});
await server.start();
// Static files are served live from app/ by the router, so CSS/JS edits only need a browser refresh.
const child=spawn(php,['-d','extension=php_pdo_pgsql.dll','-S','127.0.0.1:'+appPort,'-t','app','tools/dev-router.php'],{cwd:root,stdio:'inherit',env:{...process.env,
  PGHOST:'127.0.0.1',PGPORT:String(dbPort),PGDATABASE:'postgres',PGUSER:'postgres',PGPASSWORD:'local-dev-only',
  APP_SESSION_SECRET:sessionSecret,CAMPUSBITE_LOCAL_TEST:'1',CAMPUSBITE_LOCAL_STORAGE_DIR:storageDir,
  SUPABASE_URL:'',SUPABASE_SECRET_KEY:''}});

console.log(`\nCampusBite dev server: http://127.0.0.1:${appPort}/login.php`);
for(const [,,email] of accounts) console.log(`  ${email}@example.invalid / ${password} (unless changed)`);
console.log('Press Ctrl+C to stop. Uploaded photos are saved in '+storageDir+'\n');

// Close the database cleanly so the saved data is flushed to disk.
let stopping=false;
const stop=async code=>{
  if(stopping) return; stopping=true;
  child.kill();
  await server.stop().catch(()=>{});
  await db.close().catch(()=>{});
  process.exit(code);
};
process.on('SIGINT',()=>stop(0));
process.on('SIGTERM',()=>stop(0));
child.on('exit',code=>{ if(!stopping) { console.error('PHP server exited with code '+code); stop(code ?? 1); } });
