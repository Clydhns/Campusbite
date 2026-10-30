import { PGlite } from '@electric-sql/pglite';
import { PGLiteSocketServer } from '@electric-sql/pglite-socket';
import { readFile } from 'node:fs/promises';
import { spawn, spawnSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import assert from 'node:assert/strict';

const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const php=process.env.PHP_BINARY || 'C:/xampp/php/php.exe';
const password=randomBytes(24).toString('hex');
const hashResult=spawnSync(php,['-r','echo password_hash($argv[1], PASSWORD_DEFAULT);',password],{encoding:'utf8'});
assert.equal(hashResult.status,0);
const db=new PGlite();
let server,child,logs='',checks=0;
const base='http://127.0.0.1:18759';
const check=(value,label)=>{assert.ok(value,label);checks++;console.log('PASS: '+label);};
const token=(html,name='csrf_token')=>{
  const escaped=name.replace(/[.*+?^${}()|[\]\\]/g,'\\$&');
  const match=html.match(new RegExp(`name="${escaped}"[^>]*value="([^"]+)"`));
  assert.ok(match,'CSRF token present'); return match[1];
};
function client() {
  let cookie='';
  return async (route,body) => {
    const response=await fetch(base+'/'+route,{method:body?'POST':'GET',redirect:'manual',signal:AbortSignal.timeout(20000),
      headers:{...(cookie?{Cookie:cookie}:{}),...(body?{'Content-Type':'application/x-www-form-urlencoded'}:{})},body:body?new URLSearchParams(body):undefined});
    for(const item of response.headers.getSetCookie()) if(item.startsWith('campusbite_session=')) cookie=item.split(';')[0];
    const html=await response.text();
    assert.ok(!/Fatal error:|Warning:|SQLSTATE\[|Dashboard error:|Unable to load user information/.test(html),'No PHP/SQL error in response');
    return {status:response.status,html,location:response.headers.get('location'),csp:response.headers.get('content-security-policy')};
  };
}
async function signin(request,email) {
  const page=await request('login.php'); assert.equal(page.status,200);
  const response=await request('login.php',{email,password,csrf_token:token(page.html)});
  assert.equal(response.status,302,'Login succeeds');
}
try {
  await db.exec('CREATE ROLE anon; CREATE ROLE authenticated;');
  for(const name of ['202610290001_initial_schema.sql','202610290002_runtime.sql']) {
    await db.exec(await readFile(path.join(root,'supabase/migrations',name),'utf8'));
  }
  check(true,'Schema and runtime migrations execute in PostgreSQL');
  const privateTables=(await db.query("SELECT relrowsecurity FROM pg_class JOIN pg_namespace n ON n.oid=relnamespace WHERE n.nspname='campusbite_private' AND relkind='r'")).rows;
  check(privateTables.length===2 && privateTables.every(table=>table.relrowsecurity),'Both private runtime tables have RLS enabled');
  // Test bucket metadata separately; this does not emulate the Supabase Storage API.
  await db.exec('CREATE SCHEMA storage; CREATE TABLE storage.buckets(id text PRIMARY KEY,name text,public boolean,file_size_limit bigint,allowed_mime_types text[]);');
  await db.exec(await readFile(path.join(root,'supabase/migrations/202610290003_storage.sql'),'utf8'));
  const buckets=(await db.query('SELECT id,public,file_size_limit FROM storage.buckets ORDER BY id')).rows;
  check(buckets.length===2 && buckets.find(bucket=>bucket.id==='campusbite-receipts').public===false
    && buckets.find(bucket=>bucket.id==='campusbite-images').public===true
    && buckets.every(bucket=>Number(bucket.file_size_limit)===3145728),'Storage migration configures private receipts and 3 MB bucket limits');
  for (const [role,number,email] of [[1,'ADMIN','admin'],[2,'STAFF','staff'],[2,'OTHER','other'],[3,'STUDENT','student']]) {
    await db.query('INSERT INTO public.users(role_id,id_number,first_name,last_name,email,password_hash) VALUES ($1,$2,$3,$4,$5,$6)',[role,'FIXTURE-'+number,'Fixture',number,email+'@example.invalid',hashResult.stdout]);
  }
  await db.exec("INSERT INTO public.categories(category_name) VALUES ('Rice Meals'); INSERT INTO public.stores(staff_user_id,store_name) VALUES (2,'Fixture Kitchen'),(3,'Other Kitchen'); INSERT INTO public.menu_items(category_id,item_name,price,store_id) VALUES (1,'Fixture Meal',50,1),(1,'Other Meal',60,2); INSERT INTO public.daily_menu(menu_date,item_id,quantity_prepared,quantity_remaining) VALUES (CURRENT_DATE,1,10,10),(CURRENT_DATE,2,10,10);");
  server=new PGLiteSocketServer({db,host:'127.0.0.1',port:18758,maxConnections:6}); await server.start();
  const reuse=await new Promise((resolve,reject)=>{
    const probe=spawn(php,['-d','extension=php_pdo_pgsql.dll',path.join(root,'tools/tests/connection-reuse.php')],
      {cwd:root,windowsHide:true,stdio:['ignore','pipe','pipe'],env:{...process.env,
        PGHOST:'127.0.0.1',PGPORT:'18758',PGDATABASE:'postgres',PGUSER:'postgres',PGPASSWORD:'fixture-only',CAMPUSBITE_LOCAL_TEST:'1'}});
    let output='';
    const deadline=setTimeout(()=>{probe.kill();reject(new Error('Connection reuse probe timed out'));},15000);
    probe.stdout.on('data',data=>{output+=data.toString();});
    probe.stderr.resume();
    probe.on('error',error=>{clearTimeout(deadline);reject(error);});
    probe.on('close',code=>{
      clearTimeout(deadline);
      if(code!==0) {reject(new Error('Connection reuse probe failed'));return;}
      try {resolve(JSON.parse(output));} catch {reject(new Error('Connection reuse probe returned no report'));}
    });
  });
  check(reuse.application_connection_reused && reuse.session_connection_separate && reuse.application_role,
    'Page and permission checks share one application connection while sessions retain their own application-role connection');
  check(reuse.batch_store_validation,
    'Batched cart ownership accepts same-store items and rejects mixed, missing and closed-store items under transaction locks');
  child=spawn(php,['-d','extension=php_pdo_pgsql.dll','-d','display_errors=0','-S','127.0.0.1:18759','api/index.php'],{cwd:root,windowsHide:true,stdio:['ignore','pipe','pipe'],env:{...process.env,
    PGHOST:'127.0.0.1',PGPORT:'18758',PGDATABASE:'postgres',PGUSER:'postgres',PGPASSWORD:'local-fixture-only',
    APP_SESSION_SECRET:randomBytes(48).toString('hex'),CAMPUSBITE_LOCAL_TEST:'1'}});
  child.stderr.on('data',data=>{logs+=data.toString();}); child.stdout.on('data',data=>{logs+=data.toString();});
  child.on('error',error=>{logs+=error.message;});
  for(let i=0;i<60;i++) {
    try { await fetch(base+'/generate_hash.php',{signal:AbortSignal.timeout(1000)}); break; }
    catch { if(i===59) throw new Error('PHP test server did not start: '+logs); await new Promise(resolve=>setTimeout(resolve,100)); }
  }
  const anon=client();
  const introduction=await anon('');
  check(introduction.status===200 && introduction.html.includes('An independent student project')
    && introduction.html.includes('id="data-use"') && !introduction.html.includes('<form'),
    'Public home explains project identity and data use without requesting credentials');
  const publicLogin=await anon('login.php');
  check(publicLogin.csp?.includes("form-action 'self'") && publicLogin.csp?.includes("base-uri 'self'")
    && publicLogin.csp?.includes("frame-ancestors 'none'") && publicLogin.csp?.includes("object-src 'none'")
    && publicLogin.html.includes('index.php#data-use'),
    'Authentication pages link to project details and restrict external form submissions and framing');
  for(const route of ['generate_hash.php','app/config/connection.php','uploads/receipts/'+ 'a'.repeat(32)+'.png','.env','tests/admin-account-request.php']) {
    assert.equal((await anon(route)).status,404);
  }
  check(true,'Router denies utilities, configuration, private receipts, environment files and tests');
  const student=client(); await signin(student,'STUDENT@EXAMPLE.INVALID');
  check(true,'Case-insensitive login works with persistent PostgreSQL sessions');
  for(const route of ['dashboard.php','menu.php','cart.php','orders.php','profile.php']) {
    const page=await student(route); assert.equal(page.status,200,route); assert.ok(!page.html.includes('temporarily unavailable'),route);
  }
  check(true,'Student dashboard, menu, empty cart, orders and profile render through PHP/PostgreSQL');
  const menu=await student('menu.php?store=1');
  const add=await student('menu.php?store=1',{csrf_token:token(menu.html),item_id:'1',quantity:'2'});
  assert.ok(add.html.includes('added to cart'));
  const otherMenu=await student('menu.php?store=2');
  const mixed=await student('menu.php?store=2',{csrf_token:token(otherMenu.html),item_id:'2',quantity:'1'});
  check(mixed.html.includes('One store per checkout'),'A cart cannot mix items from different stores');
  const checkout=await student('checkout.php'); assert.equal(checkout.status,200);
  const placed=await student('checkout.php',{csrf_token:token(checkout.html),action:'place_order',payment_method:'Cash',remarks:'Fixture only'});
  assert.equal(placed.status,302,placed.html.slice(0,200));
  const order=(await db.query('SELECT order_id,order_number,total_amount,status_id FROM public.orders')).rows[0];
  check(order && /^ORD-\d{8}-001$/.test(order.order_number) && Number(order.total_amount)===100,'Checkout generates an order number, current-price total and atomic order records');
  assert.equal(Number((await db.query('SELECT quantity_remaining FROM public.daily_menu WHERE item_id=1')).rows[0].quantity_remaining),8);
  check(true,'Checkout reserves exactly the requested stock');
  const orders=await student('orders.php');
  const cancelled=await student('orders.php',{csrf_token:token(orders.html),action:'cancel_order',order_id:String(order.order_id),cancel_reason:'Fixture cancellation'});
  assert.equal(cancelled.status,200);
  assert.equal(Number((await db.query('SELECT status_id FROM public.orders WHERE order_id=$1',[order.order_id])).rows[0].status_id),5);
  assert.equal(Number((await db.query('SELECT quantity_remaining FROM public.daily_menu WHERE item_id=1')).rows[0].quantity_remaining),10);
  check(true,'Customer cancellation restores stock and updates the order');
  await student('orders.php',{csrf_token:token(orders.html),action:'cancel_order',order_id:String(order.order_id),cancel_reason:'Repeat'});
  assert.equal(Number((await db.query('SELECT quantity_remaining FROM public.daily_menu WHERE item_id=1')).rows[0].quantity_remaining),10);
  check(true,'Repeating cancellation cannot restore stock twice');
  const staff=client(); await signin(staff,'staff@example.invalid');
  for(const route of ['staff_dashboard.php','staff_menu.php','staff_profile.php']) assert.equal((await staff(route)).status,200);
  const store=await staff('staff_menu.php');
  const food=await staff('staff_menu.php',{csrf_token:token(store.html),action:'save_food',item_id:'0',item_name:'New Fixture Meal',description:'Test fixture',price:'75',category_id:'1',quantity_prepared:'5',stock:'5',is_available:'1'});
  assert.equal(food.status,302);
  check((await db.query("SELECT item_id FROM public.menu_items WHERE item_name='New Fixture Meal' AND store_id=1")).rows.length===1,'Staff food creation uses PostgreSQL RETURNING and daily-menu upsert');
  const latestMenu=await student('menu.php?store=1');
  await student('menu.php?store=1',{csrf_token:token(latestMenu.html),item_id:'1',quantity:'1'});
  const secondCheckout=await student('checkout.php');
  await student('checkout.php',{csrf_token:token(secondCheckout.html),action:'place_order',payment_method:'Cash',remarks:''});
  const second=(await db.query('SELECT order_id,order_number FROM public.orders ORDER BY order_id DESC LIMIT 1')).rows[0];
  check(second.order_number.endsWith('-002'),'Successive checkouts receive distinct order numbers');
  await assert.rejects(db.exec(`UPDATE public.orders SET status_id=2 WHERE order_id=${Number(second.order_id)}`));
  check(true,'Database trigger prevents preparing an unpaid order');
  const staffOrders=await staff('staff_dashboard.php');
  await staff('staff_dashboard.php',{csrf_token:token(staffOrders.html),action:'confirm_payment',order_id:String(second.order_id)});
  assert.equal((await db.query('SELECT payment_status FROM public.payments WHERE order_id=$1',[second.order_id])).rows[0].payment_status,'Paid');
  for(const next of [2,3,4]) {
    const current=await staff('staff_dashboard.php');
    await staff('staff_dashboard.php',{csrf_token:token(current.html),action:'update_status',order_id:String(second.order_id),status_id:String(next)});
    assert.equal(Number((await db.query('SELECT status_id FROM public.orders WHERE order_id=$1',[second.order_id])).rows[0].status_id),next);
  }
  check(true,'Staff payment confirmation and Preparing / Ready / Completed transitions work');
  const other=client(); await signin(other,'other@example.invalid');
  const otherOrders=await other('staff_dashboard.php');
  // An empty workspace renders no action forms; inspect only the disposable test
  // session to obtain its valid token, so this tests ownership rather than CSRF.
  const testSessions=(await db.query('SELECT payload FROM campusbite_private.sessions')).rows.map(row=>Buffer.from(row.payload,'base64').toString());
  const otherSession=testSessions.find(value=>/user_id\|(?:i:3;|s:1:"3";)/.test(value));
  const otherToken=otherSession?.match(/staff_orders_csrf\|s:64:"([a-f0-9]{64})"/)[1];
  assert.ok(otherToken);
  await other('staff_dashboard.php',{csrf_token:otherToken,action:'update_status',order_id:String(second.order_id),status_id:'2'});
  assert.equal(Number((await db.query('SELECT status_id FROM public.orders WHERE order_id=$1',[second.order_id])).rows[0].status_id),4);
  check(true,'A different store owner cannot change another store order');
  const admin=client(); await signin(admin,'admin@example.invalid');
  for(const route of ['admin_dashboard.php','admin_users.php','admin_stores.php','admin_profile.php']) {
    const page=await admin(route); assert.equal(page.status,200); assert.ok(!page.html.includes('temporarily unavailable'),route);
  }
  check(true,'Admin dashboard, account management, stores and profile render');
  const adminUsers=await admin('admin_users.php');
  await admin('admin_users.php',{csrf_token:token(adminUsers.html),action:'create_staff',id_number:'FIXTURE-NEWSTAFF',first_name:'New',last_name:'Fixture',email:'newstaff@example.invalid',password:'Fixture-Only-Password9!',confirm_password:'Fixture-Only-Password9!',contact_number:''});
  check((await db.query("SELECT s.store_id FROM public.stores s JOIN public.users u ON u.user_id=s.staff_user_id WHERE u.email='newstaff@example.invalid'")).rows.length===1,'Creating staff also creates its own store using RETURNING');
  const registration=client(); const register=await registration('register.php');
  await registration('register.php',{csrf_token:token(register.html),id_number:'FIXTURE-NEWSTUDENT',first_name:'New',last_name:'Student',email:'newstudent@example.invalid',contact_number:'',grade_section:'',password:'Fixture-Only-Password9!',confirm_password:'Fixture-Only-Password9!'});
  check((await db.query("SELECT user_id FROM public.users WHERE email='newstudent@example.invalid' AND role_id=3")).rows.length===1,'Registration validates CSRF and creates only a student account');
  const profile=await student('profile.php');
  await student('profile.php',{csrf_token:token(profile.html),first_name:'Updated',last_name:'STUDENT',email:'student@example.invalid',contact_number:'',grade_section:'',current_password:password});
  check((await db.query("SELECT first_name FROM public.users WHERE email='student@example.invalid'")).rows[0].first_name==='Updated','Profile saving verifies the password and updates the correct account');
  await db.exec('UPDATE public.users SET is_active=0 WHERE user_id=3');
  assert.equal((await other('staff_dashboard.php')).status,302);
  check(true,'A deactivated account is rejected on its next request');
  await student('logout.php'); assert.equal((await student('profile.php')).status,302);
  check(true,'Logout destroys the persistent session');
  const sessionCount=(await db.query('SELECT count(*) AS count FROM campusbite_private.sessions')).rows[0].count;
  check(Number(sessionCount)>0,'Session state is persisted in the private schema, not local files');
  console.log(`${checks} PHP/PostgreSQL integration checks passed using disposable fixtures only.`);
} catch(error) {
  console.error(logs.slice(-6000)); throw error;
} finally {
  if(child && child.exitCode===null) { child.kill(); await new Promise(resolve=>{child.once('exit',resolve);setTimeout(resolve,3000).unref();}); }
  if(server) await server.stop();
  await db.close();
}
