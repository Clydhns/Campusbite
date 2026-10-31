import { PGlite } from '@electric-sql/pglite';
import { PGLiteSocketServer } from '@electric-sql/pglite-socket';
import { readFile, mkdir, writeFile, copyFile } from 'node:fs/promises';
import { spawn, spawnSync } from 'node:child_process';
import { randomBytes, createHmac } from 'node:crypto';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import assert from 'node:assert/strict';

const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const php=process.env.PHP_BINARY || 'C:/xampp/php/php.exe';
const password=randomBytes(24).toString('hex');
const sessionSecret=randomBytes(48).toString('hex');
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
function client(initialCookie='') {
  let cookie=initialCookie;
  return async (route,body,options={}) => {
    const response=await fetch(base+'/'+route,{method:body?'POST':'GET',redirect:'manual',signal:AbortSignal.timeout(20000),
      headers:{...(cookie?{Cookie:cookie}:{}),...(body?{'Content-Type':'application/x-www-form-urlencoded'}:{}),...options.headers},body:body?new URLSearchParams(body):undefined});
    for(const item of response.headers.getSetCookie()) if(item.startsWith('campusbite_session=')) cookie=item.split(';')[0];
    const html=await response.text();
    assert.ok(!/Fatal error:|Warning:|SQLSTATE\[|Dashboard error:|Unable to load user information/.test(html),'No PHP/SQL error in response');
    return {status:response.status,html,location:response.headers.get('location'),csp:response.headers.get('content-security-policy'),sessionCookie:cookie};
  };
}
async function signin(request,email) {
  const page=await request('login.php'); assert.equal(page.status,200);
  const response=await request('login.php',{email,password,csrf_token:token(page.html)});
  assert.equal(response.status,302,'Login succeeds');
}
try {
  // Seed the same Philippine calendar day used by the application connections.
  await db.exec("SET TIME ZONE 'Asia/Manila'");
  await db.exec('CREATE ROLE anon; CREATE ROLE authenticated;');
  for(const name of ['202610290001_initial_schema.sql','202610290002_runtime.sql','20261031135602_cash_pay_at_pickup.sql','20261031173553_store_hours.sql']) {
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
  // Baseline order-flow fixtures stay open around the real test time, including midnight.
  await db.exec("UPDATE public.stores SET opens_at=((clock_timestamp() AT TIME ZONE 'Asia/Manila')-INTERVAL '8 hours')::time,closes_at=((clock_timestamp() AT TIME ZONE 'Asia/Manila')+INTERVAL '8 hours')::time");
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
  check(reuse.multi_cart_authority,
    'Cart grouping ignores forged session store labels, rejects malformed selectors and removes only selected item keys');
  check(reuse.batch_catalog_valid && reuse.query_counts.cart_items===20 && reuse.query_counts.legacy_cart_queries===20
    && reuse.query_counts.batch_cart_queries===1 && reuse.query_counts.empty_cart_queries===0,
    'A 20-item cart validates current prices and availability in one catalog query instead of 20; empty carts use none');
  check(reuse.store_lookup_valid && reuse.query_counts.existing_store_queries===1,
    'An existing staff store loads in one query while inactive owners are still rejected');
  check(reuse.admin_stats_valid && reuse.query_counts.admin_stats_queries===1,
    'Admin statistics use one query while retaining correct empty, current-day and historical totals');
  child=spawn(php,['-d','extension=php_pdo_pgsql.dll','-d','display_errors=0','-S','127.0.0.1:18759','api/index.php'],{cwd:root,windowsHide:true,stdio:['ignore','pipe','pipe'],env:{...process.env,
    PGHOST:'127.0.0.1',PGPORT:'18758',PGDATABASE:'postgres',PGUSER:'postgres',PGPASSWORD:'local-fixture-only',
    APP_SESSION_SECRET:sessionSecret,CAMPUSBITE_LOCAL_TEST:'1'}});
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
  const flow=introduction.html.match(/<ol class="steps">([\s\S]*?)<\/ol>/)?.[1] || '';
  check((flow.match(/<li>/g) || []).length===3
    && (introduction.html.match(/<ol\b/g) || []).length===1
    && !introduction.html.includes('flow-preview')
    && introduction.html.includes('aria-labelledby="about-title"')
    && introduction.html.includes('id="about-title"')
    && introduction.html.includes('href="register.php"')
    && introduction.html.includes('href="login.php"')
    && introduction.html.includes('id="about"')
    && introduction.html.includes('href="#data-use"')
    && introduction.html.includes('Not an official school portal.')
    && introduction.html.includes('Never enter your school-account password'),
    'Minimal landing page retains account links and project notices with one non-duplicated three-step pickup guide');
  check(introduction.html.includes('class="intro-copy"')
    && introduction.html.includes('class="intro-backdrop" aria-hidden="true"')
    && introduction.html.includes('class="button hero-button"')
    && (introduction.html.match(/class="button hero-button"/g) || []).length===1
    && !introduction.html.includes('pickup-bag')
    && introduction.html.includes('class="intro-explore" href="#about"')
    && introduction.html.includes('class="returning-user"')
    && introduction.html.includes('class="password-note"')
    && !/<script\b|<img\b|<form\b/i.test(introduction.html),
    'Centered public landing has hidden decorative lettering, one primary hero action and account/privacy links without extra scripts, photos or forms');
  const publicLogin=await anon('login.php');
  const loginView=publicLogin.html.match(/<section\b[^>]*id="login-view"[\s\S]*?<\/section>/)?.[0] || '';
  const registerView=publicLogin.html.match(/<section\b[^>]*id="register-view"[\s\S]*?<\/section>/)?.[0] || '';
  check(loginView.includes('action="login.php"') && loginView.includes('id="login-password-help"')
    && !loginView.includes('auth-project-note')
    && registerView.includes('auth-project-note') && registerView.includes('index.php#data-use'),
    'Login omits the repeated project notice while preserving password guidance, the login form and registration disclosure');
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
  check(!(await student('dashboard.php')).html.includes('src="assets/order-tracking/tracking.js'),
    'An empty dashboard skips the 255 KB order-tracking JavaScript bundle');
  const dashboardCard=(html,id)=>html.match(new RegExp(`<a\\b[^>]*data-food-id="${id}"[^>]*>[\\s\\S]*?<\\/a>`))?.[0] || '';
  const heroSection=html=>html.match(/<section class="cb-hero cb-food-hero"[\s\S]*?<\/section>/)?.[0] || '';
  const fixturePhoto='uploads/foods/'+'a'.repeat(32)+'.png';
  await db.query('UPDATE public.menu_items SET image_path=$1 WHERE item_id=1',[fixturePhoto]);
  const availabilityStaff=client(); await signin(availabilityStaff,'staff@example.invalid');
  const availabilityPage=await availabilityStaff('staff_menu.php');
  const availabilityToken=token(availabilityPage.html);
  const setAvailability=(item,available,csrf=availabilityToken)=>availabilityStaff('staff_menu.php',
    {action:'set_food_availability',item_id:String(item),available:String(available),csrf_token:csrf},
    {headers:{Accept:'application/json'}});
  check(availabilityPage.html.includes('role="switch"') && availabilityPage.html.includes('data-food-availability')
    && !availabilityPage.html.includes('name="stock"') && !availabilityPage.html.includes('remaining today'),
    'Staff editor and food cards show accessible availability switches instead of serving counts');
  const denied=await setAvailability(1,0,'invalid');
  check(denied.status===422 && !JSON.parse(denied.html).ok
    && Number((await db.query('SELECT is_available FROM public.menu_items WHERE item_id=1')).rows[0].is_available)===1,
    'Invalid CSRF cannot change food availability');
  const studentToggle=await student('staff_menu.php',{action:'set_food_availability',item_id:'1',available:'0',csrf_token:availabilityToken},
    {headers:{Accept:'application/json'}});
  check(studentToggle.status===302
    && Number((await db.query('SELECT is_available FROM public.menu_items WHERE item_id=1')).rows[0].is_available)===1,
    'A student cannot use a staff availability endpoint even with a valid staff token');
  for(const [id,value] of [[2,0],[999999,1],[1,'invalid']]) {
    const blocked=await setAvailability(id,value);
    assert.equal(blocked.status,422); assert.equal(JSON.parse(blocked.html).ok,false);
  }
  check(Number((await db.query('SELECT is_available FROM public.menu_items WHERE item_id=2')).rows[0].is_available)===1,
    'Availability updates reject another store, missing foods and invalid states');
  const off=await setAvailability(1,0);
  check(off.status===200 && JSON.parse(off.html).available===false && JSON.parse(off.html).workspace==='2-1',
    'Quick switch saves the requested state and returns the owning workspace without a redirect');
  const dashboard=await student('dashboard.php');
  const card=dashboardCard(dashboard.html,1);
  const hero=heroSection(dashboard.html);
  check(card.includes('Fixture Meal') && card.includes(fixturePhoto) && card.includes('Currently unavailable') && card.includes('50.00')
    && card.includes('data-available="false"') && card.includes('menu.php?store=1') && !card.includes('<form')
    && hero.includes('Fixture Meal') && hero.includes(fixturePhoto) && hero.includes('Currently unavailable'),
    'Switching off keeps photos, prices and store links visible while marking food unavailable');
  const unavailableMenu=await student('menu.php?store=1');
  const kitchenOptions=html=>html.match(/<nav class="kitchen-options"[\s\S]*?<\/nav>/)?.[0] || '';
  check(kitchenOptions(unavailableMenu.html).includes(fixturePhoto)
    && kitchenOptions((await student('menu.php')).html).includes(fixturePhoto),
    'Kitchen cards retain their food photo when meals are unavailable in selected-store and all-store views');
  const snackCategory=(await db.query("INSERT INTO public.categories(category_name) VALUES ('Snacks') RETURNING category_id")).rows[0];
  const snackPhoto='uploads/foods/'+'b'.repeat(32)+'.png';
  const snack=(await db.query("INSERT INTO public.menu_items(category_id,item_name,price,store_id,image_path,is_available) VALUES ($1,'Fixture Snack Photo',10,2,$2,0) RETURNING item_id",[snackCategory.category_id,snackPhoto])).rows[0];
  const kitchenFallback=await student('menu.php?store=1');
  check(kitchenOptions(kitchenFallback.html).includes(snackPhoto)
    && kitchenOptions(kitchenFallback.html).includes(fixturePhoto),
    'Each kitchen uses its own photo, falling back to a snack photo when no photographed meal exists');
  await db.query('UPDATE public.menu_items SET archived_at=NOW() WHERE item_id=$1',[snack.item_id]);
  check(!kitchenOptions((await student('menu.php?store=1')).html).includes(snackPhoto),
    'Removed food photos are not reused as kitchen thumbnails');
  const rejected=await student('menu.php?store=1',{csrf_token:token(unavailableMenu.html),item_id:'1',quantity:'1'});
  check(rejected.html.includes('This food is currently unavailable.') && (await student('cart.php')).html.includes('Your cart is empty for now'),
    'Food switched off cannot be added to a cart');
  await setAvailability(1,0);
  check(Number((await db.query('SELECT is_available FROM public.menu_items WHERE item_id=1')).rows[0].is_available)===0,
    'Repeating an availability request is idempotent rather than flipping the food back on');
  await setAvailability(1,1);
  await db.exec('UPDATE public.daily_menu SET quantity_remaining=0 WHERE item_id=1');
  const shown=await student('dashboard.php');
  check(dashboardCard(shown.html,1).includes('data-available="true"') && !/\d+\s+available today/.test(dashboardCard(shown.html,1)),
    'Zero servings do not limit a food explicitly enabled for today');
  await db.exec('UPDATE public.daily_menu SET quantity_remaining=10 WHERE item_id=1');
  await db.exec('UPDATE public.daily_menu SET menu_date=CURRENT_DATE-1 WHERE item_id=1');
  const expiredHome=await student('dashboard.php');
  const expiredStaff=await availabilityStaff('staff_menu.php?edit=1');
  const expiredMenu=await student('menu.php?store=1');
  const expiredAdd=await student('menu.php?store=1',{csrf_token:token(expiredMenu.html),item_id:'1',quantity:'1'});
  check(dashboardCard(expiredHome.html,1).includes('data-available="false"')
    && /data-item-id="1"[\s\S]*?aria-checked="false"/.test(expiredStaff.html)
    && expiredAdd.html.includes('This food is currently unavailable.'),
    'Yesterday\'s availability expires on Home, staff switches and menu add requests');
  const enabledToday=await setAvailability(1,1);
  const expectedExpiry=(await db.query("SELECT EXTRACT(EPOCH FROM ((CURRENT_DATE+1)::timestamp AT TIME ZONE 'Asia/Manila'))*1000 AS expires")).rows[0].expires;
  const dailyRows=(await db.query('SELECT menu_date,is_available,quantity_remaining FROM public.daily_menu WHERE item_id=1 ORDER BY menu_date')).rows;
  check(JSON.parse(enabledToday.html).available===true && JSON.parse(enabledToday.html).available_until===Number(expectedExpiry)
    && dailyRows.length===2 && Number(dailyRows[0].quantity_remaining)===10 && Number(dailyRows[1].quantity_remaining)===0,
    'Staff can enable a new day without serving counts or altering yesterday\'s quantities');
  const midnight=(await db.query("SELECT (TIMESTAMPTZ '2026-10-31 15:59:59+00' AT TIME ZONE 'Asia/Manila')::date::text AS before, (TIMESTAMPTZ '2026-10-31 16:00:00+00' AT TIME ZONE 'Asia/Manila')::date::text AS after")).rows[0];
  check(midnight.before==='2026-10-31' && midnight.after==='2026-11-01',
    'The daily boundary is Philippine midnight, not midnight UTC');
  const beverages=(await db.query("INSERT INTO public.categories(category_name) VALUES ('Beverages') RETURNING category_id")).rows[0];
  const juice=(await db.query("INSERT INTO public.menu_items(category_id,item_name,price,store_id) VALUES ($1,'Cup of Juice',20,1) RETURNING item_id",[beverages.category_id])).rows[0];
  await setAvailability(juice.item_id,1);
  check(dashboardCard((await student('dashboard.php')).html,juice.item_id).includes('data-available="true"'),
    'Staff can enable cups of juice for today using the same switch');
  await db.query('UPDATE public.daily_menu SET menu_date=CURRENT_DATE-1 WHERE item_id=$1',[juice.item_id]);
  check(dashboardCard((await student('dashboard.php')).html,juice.item_id).includes('data-available="false"'),
    'Daily expiry applies to drinks as well as meals');
  const packaged=[];
  for (const [name,persistent,category] of [
    ['Coke',true,beverages.category_id],['Sprite',true,beverages.category_id],
    ['Bottled Water (500ml)',true,beverages.category_id],['Water',true,beverages.category_id],
    ['Pepsi',true,beverages.category_id],['Royal',true,beverages.category_id],['7UP',true,beverages.category_id],
    ['Coke Float',false,beverages.category_id],['Watermelon Juice',false,beverages.category_id],
    ['Iced Tea',false,beverages.category_id],['Coke Chicken',false,1]
  ]) {
    const item=(await db.query('INSERT INTO public.menu_items(category_id,item_name,price,store_id) VALUES ($1,$2,20,1) RETURNING item_id',[category,name])).rows[0];
    packaged.push({...item,name,persistent});
  }
  const drinkHome=await student('dashboard.php');
  for(const item of packaged) assert.ok(dashboardCard(drinkHome.html,item.item_id).includes(`data-available="${item.persistent}"`),item.name);
  check(true,'Soft drinks and water stay available without a daily record, but juice, fresh drinks and food do not inherit that exception');
  const coke=packaged.find(item=>item.name==='Coke');
  const packagedOff=await setAvailability(coke.item_id,0);
  check(JSON.parse(packagedOff.html).keeps_availability===true && JSON.parse(packagedOff.html).available_until===null
    && dashboardCard((await student('dashboard.php')).html,coke.item_id).includes('data-available="false"'),
    'Staff can manually switch soft drinks off; no-reset mode never overrides their choice');
  await setAvailability(coke.item_id,1);
  await db.query('DELETE FROM public.daily_menu WHERE item_id=$1',[coke.item_id]);
  const staffDrinks=await availabilityStaff('staff_menu.php?edit='+coke.item_id);
  check(staffDrinks.html.includes(`data-availability-reset="never" data-item-id="${coke.item_id}"`)
    && staffDrinks.html.includes('No daily reset'),
    'Staff cards label soft drinks as no-reset items and retain the exception in the editor');
  const desserts=(await db.query("INSERT INTO public.categories(category_name) VALUES ('Desserts') RETURNING category_id")).rows[0];
  await db.query("INSERT INTO public.menu_items(category_id,item_name,price,store_id) VALUES ($1,'Banana cue',15,1),($2,'Cake slice',30,1),($3,'Other Kitchen Juice',20,2)",
    [snackCategory.category_id,desserts.category_id,beverages.category_id]);
  for(const route of ['menu.php?store=1','menu.php?store=2','menu.php']) {
    const sorted=await student(route);
    const cards=[...sorted.html.matchAll(/<article\s+class="food-card"[\s\S]*?<\/article>/g)].map(match=>({
      category:match[0].match(/data-category="([^"]+)"/)[1],
      store:match[0].match(/class="cb-food-store">([^<]+)</)[1]
    }));
    const ranks=new Map();
    for(const card of cards) {
      const rank=card.category==='Beverages'?3:card.category==='Rice Meals'?0:card.category==='Snacks'?1:2;
      assert.ok(rank >= (ranks.get(card.store) ?? -1),route+': '+card.category);
      ranks.set(card.store,rank);
    }
    assert.ok(cards.length>0);
    assert.ok(sorted.html.indexOf('data-category="Rice Meals"') < sorted.html.indexOf('data-category="Beverages"'));
  }
  check(true,'Menus and category tabs show meals first, snacks and desserts next, and drinks last in individual and all-kitchen views');
  const picks=(await student('dashboard.php')).html.match(/<section class="menu-grid">([\s\S]*?)<\/section>/)?.[1] || '';
  const pickCards=[...picks.matchAll(/<a class="food-card cb-food-display"[\s\S]*?<\/a>/g)].map(match=>({
    category:match[0].match(/class="food-category">\s*([^<]+)</)[1].trim(),
    store:match[0].match(/data-store="(\d+)"/)[1],
    html:match[0]
  }));
  const pickRank=card=>card.category==='Rice Meals'?0:card.category==='Snacks'?1:card.category==='Desserts'?2:3;
  assert.ok(['Rice Meals','Snacks','Desserts','Beverages'].every(category=>pickCards.some(card=>card.category===category)));
  for(const cards of [pickCards,...['1','2'].map(store=>pickCards.filter(card=>card.store===store))]) {
    assert.ok(cards.length>0);
    cards.forEach((card,index)=>{
      assert.ok(index===0 || pickRank(cards[index-1])<=pickRank(card),'Today\'s picks: food precedes desserts and drinks');
      assert.ok(card.html.includes('href="menu.php?store='+card.store+'"'),'Picks still link to their own kitchen');
      assert.ok(card.html.includes('class="cb-food-availability"'),'Availability remains visible');
    });
  }
  check(true,'Today\'s picks shows meals and snacks before desserts and drinks across all kitchens and each kitchen, retaining store links and availability labels');
  await db.exec('UPDATE public.menu_items SET store_id=1 WHERE item_id=2; UPDATE public.menu_items SET is_available=0 WHERE item_id=1');
  const alternative=await student('dashboard.php');
  check(heroSection(alternative.html).includes('Other Meal') && !heroSection(alternative.html).includes('Fixture Meal')
    && dashboardCard(alternative.html,1).includes('Currently unavailable'),
    'Hero prefers an available meal while unavailable meals remain in the catalog');
  await db.exec('UPDATE public.menu_items SET store_id=2 WHERE item_id=2; UPDATE public.menu_items SET is_available=1 WHERE item_id=1');
  for(const [change,restore,label] of [
    ['UPDATE public.menu_items SET archived_at=NOW() WHERE item_id=1','UPDATE public.menu_items SET archived_at=NULL WHERE item_id=1','removed food'],
    ['UPDATE public.stores SET is_open=0 WHERE store_id=1','UPDATE public.stores SET is_open=1 WHERE store_id=1','closed stores'],
    ['UPDATE public.users SET is_active=0 WHERE user_id=2','UPDATE public.users SET is_active=1 WHERE user_id=2','inactive staff stores'],
    ['UPDATE public.users SET role_id=3 WHERE user_id=2','UPDATE public.users SET role_id=2 WHERE user_id=2','non-staff owners']
  ]) {
    await db.exec(change);
    const hidden=await student('dashboard.php');
    check(!dashboardCard(hidden.html,1) && !heroSection(hidden.html).includes('Fixture Meal'),`Home still excludes ${label}`);
    if(label==='removed food') {
      const archivedToggle=await setAvailability(1,1);
      check(archivedToggle.status===422,'An archived food cannot be re-enabled through the quick switch');
    }
    await db.exec(restore);
  }
  await db.exec('UPDATE public.menu_items SET image_path=NULL WHERE item_id=1');
  const menu=await student('menu.php?store=1');
  const add=await student('menu.php?store=1',{csrf_token:token(menu.html),item_id:'1',quantity:'2'});
  assert.ok(add.html.includes('added to cart'));
  const otherMenu=await student('menu.php?store=2');
  const mixed=await student('menu.php?store=2',{csrf_token:token(otherMenu.html),item_id:'2',quantity:'1'});
  check(mixed.html.includes('added to cart'),'Food from different stores can coexist in the student cart');
  const grouped=await student('cart.php');
  check(grouped.html.includes('data-cart-store="1"') && grouped.html.includes('data-cart-store="2"')
    && grouped.html.includes('checkout.php?store=1') && grouped.html.includes('checkout.php?store=2'),
    'Each store renders its own cart card and separate checkout link');
  const chooseStore=await student('checkout.php');
  check(chooseStore.html.includes('Choose a store cart') && !chooseStore.html.includes('id="checkoutForm"'),
    'A mixed-store cart cannot silently check out without an explicit store choice');
  await student('checkout.php',{csrf_token:token(grouped.html),action:'place_order',store_id:'999999',payment_method:'Cash',remarks:''});
  check((await db.query('SELECT order_id FROM public.orders')).rows.length===0,'Invalid store selection cannot create an order');
  await db.exec('UPDATE public.stores SET is_open=0 WHERE store_id=2');
  const openKitchen=await student('checkout.php?store=1');
  check(openKitchen.html.includes('id="checkoutForm"'),'A closed kitchen in another cart does not block checkout for an open kitchen');
  await db.exec("UPDATE public.stores SET is_open=1,gcash_number='09170000000' WHERE store_id=2");
  const checkout=await student('checkout.php?store=1'); assert.equal(checkout.status,200);
  const otherCheckout=await student('checkout.php?store=2');
  if(process.env.CAMPUSBITE_QA_EXPORT) {
    const destination=path.resolve(process.env.CAMPUSBITE_QA_EXPORT);
    await mkdir(destination,{recursive:true});
    await writeFile(path.join(destination,'cart.html'),grouped.html);
    await writeFile(path.join(destination,'checkout.html'),checkout.html);
    const assets=new Set([...grouped.html.matchAll(/(?:href|src)="([^"?#]+\.(?:css|js))(?:\?[^"#]*)?"/g),
      ...checkout.html.matchAll(/(?:href|src)="([^"?#]+\.(?:css|js))(?:\?[^"#]*)?"/g)].map(match=>match[1]));
    for(const asset of assets) {
      assert.ok(!asset.includes('..') && !asset.includes(':'));
      await mkdir(path.dirname(path.join(destination,asset)),{recursive:true});
      await copyFile(path.join(root,'app',asset),path.join(destination,asset));
    }
    console.log('Exported synthetic cart/checkout HTML for local responsive QA; no customer data included.');
  }
  check(checkout.html.includes('name="store_id" value="1"') && checkout.html.includes('Fixture Meal') && !checkout.html.includes('Other Meal'),
    'Checkout displays only the selected kitchen and carries its store ID through submission');
  check(!checkout.html.includes('src="checkout-refresh.js'),
    'An unsubmitted checkout form does not poll or risk replacing payment choices');
  await db.exec('UPDATE public.daily_menu SET is_available=0 WHERE item_id=1 AND menu_date=CURRENT_DATE');
  const expiredCheckout=await student('checkout.php',{csrf_token:token(checkout.html),store_id:'1',action:'place_order',payment_method:'Cash',remarks:''});
  check(expiredCheckout.html.includes('is currently unavailable') && (await db.query('SELECT order_id FROM public.orders')).rows.length===0,
    'An old checkout page cannot order food that is no longer enabled for the current day');
  await setAvailability(1,1);
  const staffBeforeOrder=await availabilityStaff('staff_dashboard.php');
  const staffVersion=html=>html.match(/data-staff-orders-version="([a-f0-9]{64})"/)?.[1];
  const emptyStaffVersion=staffVersion(staffBeforeOrder.html);
  check(emptyStaffVersion && staffBeforeOrder.html.includes('id="staff-orders-list"')
    && staffBeforeOrder.html.includes('src="staff-orders-sync.js')
    && staffBeforeOrder.html.includes('id="staff-orders-sync-status"'),
    'An empty staff dashboard includes automatic order detection before any first order exists');
  const unchangedStaff=await availabilityStaff('staff_dashboard.php?sync=1&version='+emptyStaffVersion);
  check(unchangedStaff.status===204 && unchangedStaff.html==='',
    'Unchanged staff polling skips the page HTML and tracking render with an empty 204 response');
  check((await student('staff_dashboard.php?sync=1&version='+emptyStaffVersion)).status===302
    && (await anon('staff_dashboard.php?sync=1&version='+emptyStaffVersion)).status===302,
    'Staff polling still rejects students and signed-out visitors');
  const placed=await student('checkout.php',{csrf_token:token(checkout.html),store_id:'1',action:'place_order',payment_method:'Cash',remarks:'Fixture only',item_id:'2',price:'0.01'});
  assert.equal(placed.status,302,placed.html.slice(0,200));
  const order=(await db.query('SELECT order_id,order_number,total_amount,status_id FROM public.orders')).rows[0];
  check(order && /^ORD-\d{8}-001$/.test(order.order_number) && Number(order.total_amount)===100,'Checkout generates an order number, current-price total and atomic order records');
  const staffNewOrder=await availabilityStaff('staff_dashboard.php?sync=1&version='+emptyStaffVersion);
  check(staffNewOrder.status===200 && staffVersion(staffNewOrder.html)!==emptyStaffVersion
    && staffNewOrder.html.includes('data-staff-order="'+order.order_id+'"'),
    'A student checkout causes the staff sync response to include the new order and fresh counts');
  assert.equal(Number((await db.query('SELECT quantity_remaining FROM public.daily_menu WHERE daily_menu_id=1')).rows[0].quantity_remaining),10);
  check(true,'Checkout uses today\'s availability without decrementing legacy serving counts');
  const retained=await student('cart.php');
  check(retained.html.includes('data-cart-store="2"') && !retained.html.includes('data-cart-store="1"')
    && Number((await db.query('SELECT quantity_remaining FROM public.daily_menu WHERE item_id=2')).rows[0].quantity_remaining)===10,
    'Successful checkout removes only the selected store cart and leaves legacy stock untouched');
  const confirmation=await student('checkout.php?success=1');
  check(confirmation.html.includes('View remaining store carts'),'Order confirmation links back to unpurchased store carts');
  const placedNotifications=JSON.parse((await student('order_notifications.php')).html);
  check(placedNotifications.active_count===1 && placedNotifications.confirmed_count===0
    && /id="student-order-notifications-count"[^>]*>1<\/span>/.test(confirmation.html),
    'A newly placed unpaid cash order immediately shows the My Orders badge without claiming payment confirmation');
  const checkoutVersion=confirmation.html.match(/data-order-version="([a-f0-9]{64})"/)?.[1];
  const unchangedCheckout=await student('checkout.php?success=1&order='+order.order_id+'&sync=1&version='+checkoutVersion);
  check(checkoutVersion && unchangedCheckout.status===204 && unchangedCheckout.html==='',
    'Unchanged checkout checks return an empty 204 response after verifying order ownership');
  check(placed.location==='checkout.php?success=1&order='+order.order_id
    && confirmation.html.includes('src="checkout-refresh.js')
    && confirmation.html.includes('id="checkout-live-card"')
    && confirmation.html.includes('data-order-id="'+order.order_id+'"')
    && confirmation.html.includes('id="checkout-sync-status"')
    && confirmation.html.includes('id="checkout-sync-refresh"'),
    'Successful checkout pins its order ID and renders live status checks with a manual retry control');
  for(const value of ['999999','-1','bad']) {
    const invalid=await student('checkout.php?success=1&order='+value);
    assert.equal(invalid.status,302);assert.equal(invalid.location,'orders.php');
  }
  check(true,'Invalid or missing order IDs cannot render a checkout confirmation');
  await student('checkout.php',{csrf_token:token(checkout.html),store_id:'1',action:'place_order',payment_method:'Cash',remarks:'Repeat'});
  check((await db.query('SELECT order_id FROM public.orders')).rows.length===1,'A stale checkout submission cannot duplicate an order or consume another store cart');
  await student('checkout.php',{csrf_token:token(otherCheckout.html),store_id:'2',action:'place_order',payment_method:'GCash',remarks:'Second kitchen'});
  const otherOrder=(await db.query('SELECT o.store_id,o.total_amount,p.method FROM public.orders o JOIN public.payments p ON p.order_id=o.order_id WHERE o.order_id<>$1',[order.order_id])).rows[0];
  check(Number(otherOrder.store_id)===2 && Number(otherOrder.total_amount)===60 && otherOrder.method==='GCash',
    'An already-open second-store checkout remains valid and creates its own order, total and payment');
  const otherStoreSync=await availabilityStaff('staff_dashboard.php?sync=1&version='+staffVersion(staffNewOrder.html));
  check(otherStoreSync.status===204,'Another kitchen receiving an order does not change this staff store snapshot');
  const pinnedConfirmation=await student('checkout.php?success=1&order='+order.order_id);
  check(pinnedConfirmation.html.includes('data-order-id="'+order.order_id+'"')
    && pinnedConfirmation.html.includes(order.order_number),
    'A confirmation tab continues tracking its original order after checkout at another kitchen');
  const orders=await student('orders.php');
  check((await student('dashboard.php')).html.includes('src="assets/order-tracking/tracking.js'),
    'Order tracking still loads when the signed-in student has orders to display');
  const cancelled=await student('orders.php',{csrf_token:token(orders.html),action:'cancel_order',order_id:String(order.order_id),cancel_reason:'Fixture cancellation'});
  assert.equal(cancelled.status,200);
  assert.equal(Number((await db.query('SELECT status_id FROM public.orders WHERE order_id=$1',[order.order_id])).rows[0].status_id),5);
  assert.equal(Number((await db.query('SELECT quantity_remaining FROM public.daily_menu WHERE daily_menu_id=1')).rows[0].quantity_remaining),10);
  check(true,'Customer cancellation updates the order without changing legacy serving counts');
  const cancelledConfirmation=await student('checkout.php?success=1&order='+order.order_id);
  check(cancelledConfirmation.html.includes('data-order-status="5"') && cancelledConfirmation.html.includes('data-payment-state="cancelled"'),
    'Checkout confirmation reads the current cancelled state, not its original session snapshot');
  const changedCheckout=await student('checkout.php?success=1&order='+order.order_id+'&sync=1&version='+checkoutVersion);
  const staffCancelled=await availabilityStaff('staff_dashboard.php?sync=1&version='+staffVersion(staffNewOrder.html));
  check(changedCheckout.status===200 && changedCheckout.html.includes('data-order-status="5"')
    && staffCancelled.status===200 && staffVersion(staffCancelled.html)!==staffVersion(staffNewOrder.html)
    && staffCancelled.html.includes('Cancelled'),
    'Customer cancellations invalidate both checkout and staff snapshots automatically');
  await student('orders.php',{csrf_token:token(orders.html),action:'cancel_order',order_id:String(order.order_id),cancel_reason:'Repeat'});
  assert.equal(Number((await db.query('SELECT quantity_remaining FROM public.daily_menu WHERE daily_menu_id=1')).rows[0].quantity_remaining),10);
  check(true,'Repeating cancellation cannot change legacy serving counts');
  const staff=client(); await signin(staff,'staff@example.invalid');
  for(const route of ['staff_dashboard.php','staff_menu.php','staff_profile.php']) assert.equal((await staff(route)).status,200);
  for(const [route,active] of [['staff_dashboard.php','Dashboard'],['staff_menu.php','Menu'],['staff_menu.php?view=settings','Store Profile'],['staff_profile.php','Account details']]) {
    const page=await staff(route);
    const navigation=page.html.match(/<nav\b[^>]*aria-label="Store navigation"[\s\S]*?<\/nav>/)?.[0] || '';
    assert.ok(navigation.includes('Store workspace') && !navigation.includes('My Store') && !navigation.includes('My Profile'));
    assert.ok(navigation.includes('href="staff_menu.php"') && navigation.includes('href="staff_menu.php?view=settings"')
      && navigation.includes('href="staff_profile.php"'));
    assert.equal((navigation.match(/aria-current="page"/g) || []).length,1);
    assert.ok(navigation.includes('aria-current="page">'+active+'</a>'));
  }
  check(true,'Shared navbar separates Menu, Store Profile and Account details with one correct active item on every store page');
  const storeDashboard=await staff('staff_dashboard.php');
  const storeProfile=await staff('staff_menu.php?view=settings');
  const accountDetails=await staff('staff_profile.php');
  check(storeDashboard.html.includes('Store Dashboard') && !storeDashboard.html.includes('Staff Dashboard')
    && storeProfile.html.includes('id="store-settings-title">Store Profile</h2>')
    && !storeProfile.html.includes('class="btn btn-secondary" href="staff_profile.php"')
    && accountDetails.html.includes('<h1>Account details</h1>') && accountDetails.html.includes('name="current_password"')
    && accountDetails.html.includes('Back to Store Profile'),
    'Store Profile and password-protected Account details have distinct titles and direct navigation');
  const store=await staff('staff_menu.php');
  const food=await staff('staff_menu.php',{csrf_token:token(store.html),action:'save_food',item_id:'0',item_name:'New Fixture Meal',description:'Test fixture',price:'75',category_id:'1',is_available:'1'});
  assert.equal(food.status,302);
  const newFood=(await db.query("SELECT item_id FROM public.menu_items WHERE item_name='New Fixture Meal' AND store_id=1")).rows[0];
  const newDay=(await db.query('SELECT is_available,quantity_prepared,quantity_remaining FROM public.daily_menu WHERE item_id=$1 AND menu_date=CURRENT_DATE',[newFood.item_id])).rows[0];
  check(newFood && newDay && Number(newDay.is_available)===1 && Number(newDay.quantity_prepared)===0 && Number(newDay.quantity_remaining)===0,
    'Staff can add food enabled for today without entering serving counts');
  const fallbackOff=await staff('staff_menu.php',{action:'set_food_availability',item_id:String(newFood.item_id),available:'0',csrf_token:token(store.html)});
  const fallbackPage=await staff('staff_menu.php');
  check(fallbackOff.status===302 && fallbackPage.html.includes('New Fixture Meal is now unavailable.'),
    'The availability form also saves and confirms changes without JavaScript');
  await staff('staff_menu.php',{action:'set_food_availability',item_id:String(newFood.item_id),available:'1',csrf_token:token(store.html)});
  const latestMenu=await student('menu.php?store=1');
  await student('menu.php?store=1',{csrf_token:token(latestMenu.html),item_id:'1',quantity:'1'});
  const secondCheckout=await student('checkout.php');
  await student('checkout.php',{csrf_token:token(secondCheckout.html),action:'place_order',payment_method:'Cash',remarks:''});
  const second=(await db.query('SELECT order_id,order_number FROM public.orders ORDER BY order_id DESC LIMIT 1')).rows[0];
  check(second.order_number.endsWith('-003'),'Successive per-store checkouts receive distinct order numbers');
  const unpaidGcash=(await db.query("SELECT o.order_id FROM public.orders o JOIN public.payments p ON p.order_id=o.order_id WHERE p.method='GCash' AND p.payment_status='Unpaid' LIMIT 1")).rows[0];
  await assert.rejects(db.query('UPDATE public.orders SET status_id=2 WHERE order_id=$1',[unpaidGcash.order_id]));
  check(true,'Database trigger still prevents preparing an unpaid GCash order');
  const staffOrders=await staff('staff_dashboard.php');
  const unpaidOrders=await student('orders.php');
  const ordersVersion=html=>html.match(/data-orders-version="([a-f0-9]{64})"/)?.[1];
  const emptyNotifications=await student('order_notifications.php');
  check(emptyNotifications.status===200 && JSON.parse(emptyNotifications.html).confirmed_count===0
    && JSON.parse(emptyNotifications.html).active_count===2
    && /id="student-order-notifications-count"[^>]*>2<\/span>/.test(unpaidOrders.html)
    && !unpaidOrders.html.includes('data-payment-confirmed="'+second.order_id+'"'),
    'Pending cash and GCash orders contribute to the active badge but never produce a paid-order message');
  check((await anon('order_notifications.php')).status===401
    && (await staff('order_notifications.php')).status===403
    && (await student('order_notifications.php',{action:'change'})).status===405,
    'Payment notifications are read-only and reject signed-out visitors and non-student accounts');
  await staff('staff_dashboard.php',{csrf_token:token(staffOrders.html),action:'confirm_payment',order_id:String(second.order_id)});
  assert.equal((await db.query('SELECT payment_status FROM public.payments WHERE order_id=$1',[second.order_id])).rows[0].payment_status,'Paid');
  const paidConfirmation=await student('checkout.php?success=1&order='+second.order_id);
  check(paidConfirmation.html.includes('data-payment-state="paid"') && paidConfirmation.html.includes('Payment confirmed'),
    'Checkout live responses reflect payment confirmed by the owning staff account');
  const paidNotifications=await student('order_notifications.php?student_id=999999');
  const paidOrders=await student('orders.php?sync=1&version='+ordersVersion(unpaidOrders.html));
  check(JSON.parse(paidNotifications.html).confirmed_count===1 && paidOrders.status===200
    && paidOrders.html.includes('data-payment-confirmed="'+second.order_id+'"')
    && paidOrders.html.includes('has confirmed your payment.')
    && /id="student-order-notifications-count"[^>]*>2<\/span>/.test(paidOrders.html),
    'Staff confirmation retains the two active-order badge and adds payment-confirmed messaging, ignoring forged student selectors');
  check((await student('orders.php?sync=1&version='+ordersVersion(paidOrders.html))).status===204,
    'Unchanged My Orders checks avoid redrawing the page or payment notice');
  for(const route of ['dashboard.php','menu.php','cart.php','profile.php','checkout.php?success=1&order='+second.order_id]) {
    assert.ok(/id="student-order-notifications-count"[^>]*>2<\/span>/.test((await student(route)).html),route);
  }
  check(true,'Active-order badges are present across all shared student navigation pages');
  const staffPaid=await staff('staff_dashboard.php?sync=1&version='+staffVersion(staffOrders.html));
  check(staffPaid.status===200 && staffVersion(staffPaid.html)!==staffVersion(staffOrders.html),
    'Confirmed payments invalidate the staff live snapshot');
  for(const next of [2,3,4]) {
    const current=await staff('staff_dashboard.php');
    await staff('staff_dashboard.php',{csrf_token:token(current.html),action:'update_status',order_id:String(second.order_id),status_id:String(next)});
    assert.equal(Number((await db.query('SELECT status_id FROM public.orders WHERE order_id=$1',[second.order_id])).rows[0].status_id),next);
    const progress=await student('checkout.php?success=1&order='+second.order_id);
    assert.ok(progress.html.includes('data-order-status="'+next+'"'));
    assert.ok(progress.html.includes({2:'Your order is being prepared',3:'Ready for pickup',4:'Order completed'}[next]));
  }
  check(true,'Staff payment confirmation and Preparing / Ready / Completed transitions work');
  check(true,'Checkout live responses show the latest preparing, ready-for-pickup and completed headings');
  const completedOrders=await student('orders.php');
  const completedProgress=[...completedOrders.html.matchAll(/<ol class="order-progress"[\s\S]*?<\/ol>/g)].map(match=>match[0]);
  check(completedProgress.length>0 && completedProgress.every(progress=>progress.includes('Completed</li>') && !progress.includes('Picked up')),
    'My Orders labels its final progress step Completed to match the completed order status');
  check(completedProgress.every(progress=>progress.includes('Ready to pickup</li>'))
    && JSON.parse((await student('order_notifications.php')).html).confirmed_count===0
    && JSON.parse((await student('order_notifications.php')).html).active_count===1
    && completedOrders.html.includes('data-payment-confirmed="'+second.order_id+'"'),
    'Completed orders retain payment confirmation while the badge counts only the remaining active order');
  await db.query("UPDATE public.payments SET payment_status='Refunded' WHERE order_id=$1",[second.order_id]);
  check(!(await student('orders.php')).html.includes('data-payment-confirmed="'+second.order_id+'"'),
    'Refunded payments are not shown as confirmed payments');
  await db.query("UPDATE public.payments SET payment_status='Paid' WHERE order_id=$1",[second.order_id]);
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
  check((await admin('admin_stores.php?store=1')).html.includes('Cup of Juice</h3><p>Beverages &middot; Unavailable'),
    'Admin food labels use the same daily expiry for drinks as staff and students');
  const adminUsers=await admin('admin_users.php');
  await admin('admin_users.php',{csrf_token:token(adminUsers.html),action:'create_staff',id_number:'FIXTURE-NEWSTAFF',first_name:'New',last_name:'Fixture',email:'newstaff@example.invalid',password:'Fixture-Only-Password9!',confirm_password:'Fixture-Only-Password9!',contact_number:''});
  check((await db.query("SELECT s.store_id FROM public.stores s JOIN public.users u ON u.user_id=s.staff_user_id WHERE u.email='newstaff@example.invalid'")).rows.length===1,'Creating staff also creates its own store using RETURNING');
  const registration=client(); const register=await registration('register.php');
  const signupPassword='Fixture-Only-Password9!';
  const signup={csrf_token:token(register.html),id_number:'FIXTURE-NEWSTUDENT',first_name:'New',last_name:'Student',email:'newstudent@example.invalid',contact_number:'',grade_section:'',password:signupPassword,confirm_password:signupPassword};
  const beforeSignup=Number((await db.query('SELECT COUNT(*) AS n FROM public.users')).rows[0].n);
  const badCsrf=await registration('register.php',{...signup,csrf_token:'invalid'});
  const mismatch=await registration('register.php',{...signup,confirm_password:'different-password'});
  const duplicateEmail=await registration('register.php',{...signup,email:'STUDENT@example.invalid'});
  const duplicateId=await registration('register.php',{...signup,id_number:'FIXTURE-STUDENT'});
  check([badCsrf,mismatch,duplicateEmail,duplicateId].every(result=>result.status===200)
    && badCsrf.html.includes('Your form expired') && mismatch.html.includes('Passwords do not match')
    && duplicateEmail.html.includes('already registered') && duplicateId.html.includes('already registered')
    && Number((await db.query('SELECT COUNT(*) AS n FROM public.users')).rows[0].n)===beforeSignup
    && (await registration('dashboard.php')).status===302,
    'Invalid CSRF, mismatched passwords and duplicate accounts cannot register or authenticate a visitor');
  const beforeSession=await registration('register.php');
  const signedUp=await registration('register.php',{...signup,csrf_token:token(beforeSession.html),role_id:'1',role_name:'Administrator'});
  const newStudent=(await db.query("SELECT user_id,role_id,is_active,password_hash FROM public.users WHERE email='newstudent@example.invalid'")).rows[0];
  check(signedUp.status===303 && signedUp.location==='dashboard.php' && Number(newStudent.role_id)===3 && Number(newStudent.is_active)===1
    && newStudent.password_hash!==signupPassword,
    'Successful registration creates only an active student with a hashed password and redirects directly to the dashboard');
  check(signedUp.sessionCookie!==beforeSession.sessionCookie
    && (await client(beforeSession.sessionCookie)('dashboard.php')).status===302,
    'Automatic sign-in rotates the session ID and leaves the old anonymous cookie unauthenticated');
  const welcome=await registration('dashboard.php');
  check(welcome.status===200 && welcome.html.includes('data-dashboard-user="'+newStudent.user_id+'"')
    && (await registration('profile.php')).html.includes('newstudent@example.invalid'),
    'The newly registered student can open their own dashboard and profile without logging in again');
  await db.query('UPDATE public.orders SET status_id=2 WHERE order_id=$1',[second.order_id]);
  check(JSON.parse((await registration('order_notifications.php?student_id=4')).html).confirmed_count===0
    && JSON.parse((await registration('order_notifications.php?student_id=4')).html).active_count===0
    && JSON.parse((await student('order_notifications.php')).html).confirmed_count===1,
    'Students can only receive their own payment counts, even when another student has an active paid order');
  await db.query('UPDATE public.orders SET status_id=4 WHERE order_id=$1',[second.order_id]);
  const privateConfirmation=await registration('checkout.php?success=1&order='+second.order_id);
  check(privateConfirmation.status===302 && privateConfirmation.location==='orders.php'
    && !privateConfirmation.html.includes(second.order_number),
    'A different student cannot read an order through the pinned checkout confirmation URL');
  check((await registration('admin_dashboard.php')).status===302 && (await registration('staff_dashboard.php')).status===302,
    'Posted administrator role fields cannot grant privileged access during automatic sign-in');
  await registration('logout.php');
  const returning=await registration('login.php');
  const signedBackIn=await registration('login.php',{csrf_token:token(returning.html),email:'newstudent@example.invalid',password:signupPassword});
  check(signedBackIn.status===302 && signedBackIn.location==='dashboard.php','The new account still supports normal password login after logout');
  const separateCarts=client(); await signin(separateCarts,'student@example.invalid');
  for(const store of [1,2]) {
    const page=await separateCarts('menu.php?store='+store);
    const added=await separateCarts('menu.php?store='+store,{csrf_token:token(page.html),item_id:String(store),quantity:'1'});
    assert.ok(added.html.includes('added to cart'));
  }
  const allCarts=await separateCarts('cart.php');
  await separateCarts('cart.php',{csrf_token:'invalid',action:'clear_store',store_id:'1'});
  let afterClear=await separateCarts('cart.php');
  check(afterClear.html.includes('data-cart-store="1"') && afterClear.html.includes('data-cart-store="2"'),'Store-cart removal requires valid CSRF protection');
  await separateCarts('cart.php',{csrf_token:token(allCarts.html),action:'clear_store',store_id:'1'});
  afterClear=await separateCarts('cart.php');
  check(!afterClear.html.includes('data-cart-store="1"') && afterClear.html.includes('data-cart-store="2"'),'Removing one store cart preserves the other store cart');
  const restore=await separateCarts('menu.php?store=1');
  await separateCarts('menu.php?store=1',{csrf_token:token(restore.html),item_id:'1',quantity:'1'});
  const cancelStore=await separateCarts('checkout.php?store=2');
  await separateCarts('checkout.php',{csrf_token:token(cancelStore.html),store_id:'2',action:'cancel_checkout'});
  const afterCancel=await separateCarts('cart.php');
  check(afterCancel.html.includes('data-cart-store="1"') && !afterCancel.html.includes('data-cart-store="2"'),'Cancelling checkout clears only that store and leaves the other cart saved');
  const addOther=await separateCarts('menu.php?store=2');
  await separateCarts('menu.php?store=2',{csrf_token:token(addOther.html),item_id:'2',quantity:'1'});
  const stockCheck=await separateCarts('checkout.php?store=1');
  const orderCount=(await db.query('SELECT COUNT(*) AS n FROM public.orders')).rows[0].n;
  await setAvailability(1,0);
  const failedStock=await separateCarts('checkout.php',{csrf_token:token(stockCheck.html),store_id:'1',action:'place_order',payment_method:'Cash',remarks:''});
  assert.ok(failedStock.html.includes('is currently unavailable'));
  await setAvailability(1,1);
  const afterFailure=await separateCarts('cart.php');
  check((await db.query('SELECT COUNT(*) AS n FROM public.orders')).rows[0].n===orderCount
    && afterFailure.html.includes('data-cart-store="1"') && afterFailure.html.includes('data-cart-store="2"'),
    'Switching off food after checkout opens blocks order creation and preserves both store carts');
  await db.exec('UPDATE public.daily_menu SET quantity_remaining=0 WHERE item_id=1');
  const quantityPage=await separateCarts('cart.php');
  const oversized=await separateCarts('cart.php',{csrf_token:token(quantityPage.html),action:'update',item_id:'1',quantity:'100'});
  check(oversized.html.includes('Quantity must be between 1 and 99.'),'Cart quantities retain a bounded per-item limit even without serving counts');
  const largerCart=await separateCarts('cart.php',{csrf_token:token(quantityPage.html),action:'update',item_id:'1',quantity:'12'});
  check(largerCart.html.includes('Cart updated successfully.'),'Cart quantities are no longer limited by a zero legacy serving count');
  const manualCheckout=await separateCarts('checkout.php?store=1');
  const manualPlaced=await separateCarts('checkout.php',{csrf_token:token(manualCheckout.html),store_id:'1',action:'place_order',payment_method:'Cash',remarks:'Manual availability fixture'});
  check(manualPlaced.status===302 && Number((await db.query('SELECT quantity FROM public.order_items ORDER BY order_item_id DESC LIMIT 1')).rows[0].quantity)===12
    && Number((await db.query('SELECT quantity_remaining FROM public.daily_menu WHERE item_id=1')).rows[0].quantity_remaining)===0,
    'An available food checks out above old stock counts without decrementing legacy inventory');
  const cashOrderId=Number(new URL(manualPlaced.location,base).searchParams.get('order'));
  const cashPage=await staff('staff_dashboard.php');
  const cashCard=html=>html.match(new RegExp('<article class="order-card" data-staff-order="'+cashOrderId+'"[\\s\\S]*?<\\/article>'))?.[0] || '';
  check(cashCard(cashPage.html).includes('Start Preparing') && cashCard(cashPage.html).includes('Cash due at pickup - not paid yet'),
    'An unpaid cash order offers preparation while clearly stating that payment is still due');
  check(cashCard(cashPage.html).indexOf('Start Preparing') < cashCard(cashPage.html).indexOf('Confirm Cash Payment'),
    'Cash actions place Start Preparing before Confirm Cash Payment in visual and keyboard order');
  for(const next of [2,3]) {
    const current=await staff('staff_dashboard.php');
    await staff('staff_dashboard.php',{csrf_token:token(current.html),action:'update_status',order_id:String(cashOrderId),status_id:String(next)});
    assert.equal(Number((await db.query('SELECT status_id FROM public.orders WHERE order_id=$1',[cashOrderId])).rows[0].status_id),next);
    const payment=(await db.query('SELECT payment_status,paid_at,received_by FROM public.payments WHERE order_id=$1',[cashOrderId])).rows[0];
    assert.equal(payment.payment_status,'Unpaid');assert.equal(payment.paid_at,null);assert.equal(payment.received_by,null);
    if(next===2) {
      const preparedCash=cashCard((await staff('staff_dashboard.php')).html);
      assert.ok(preparedCash.includes('Ready for Pickup') && preparedCash.indexOf('Ready for Pickup') < preparedCash.indexOf('Confirm Cash Payment'));
    }
    const confirmation=await separateCarts('checkout.php?success=1&order='+cashOrderId);
    assert.ok(confirmation.html.includes('data-payment-state="unpaid"') && confirmation.html.includes('Unpaid - pay at pickup'));
    assert.ok(confirmation.html.includes(next===2?'Your order is being prepared':'Ready for pickup'));
  }
  check(true,'Cash orders prepare and become ready without marking payment paid or recording a cashier');
  const unpaidCashPage=await staff('staff_dashboard.php');
  check(cashCard(unpaidCashPage.html).includes('Confirm Cash Payment') && !cashCard(unpaidCashPage.html).includes('Mark Completed'),
    'Ready cash orders retain the cashier confirmation action and do not offer unpaid completion');
  await staff('staff_dashboard.php',{csrf_token:token(unpaidCashPage.html),action:'update_status',order_id:String(cashOrderId),status_id:'4'});
  await assert.rejects(db.query('UPDATE public.orders SET status_id=4 WHERE order_id=$1',[cashOrderId]));
  check(Number((await db.query('SELECT status_id FROM public.orders WHERE order_id=$1',[cashOrderId])).rows[0].status_id)===3,
    'Both PHP and the database reject completing a cash order before payment is received');
  const customerCash=await student('orders.php');
  check(customerCash.html.includes('data-cash-payment="'+cashOrderId+'"') && !customerCash.html.includes('data-payment-confirmed="'+cashOrderId+'"'),
    'Customers see the order number, pickup payment instructions and no false payment confirmation while cash is due');
  await other('staff_dashboard.php',{csrf_token:otherToken,action:'confirm_payment',order_id:String(cashOrderId)});
  check((await db.query('SELECT payment_status FROM public.payments WHERE order_id=$1',[cashOrderId])).rows[0].payment_status==='Unpaid',
    'Another store cannot confirm receipt of this cash payment');
  await staff('staff_dashboard.php',{csrf_token:'invalid',action:'confirm_payment',order_id:String(cashOrderId)});
  check((await db.query('SELECT payment_status FROM public.payments WHERE order_id=$1',[cashOrderId])).rows[0].payment_status==='Unpaid',
    'Cash collection still requires a valid CSRF token');
  await other('staff_dashboard.php',{csrf_token:otherToken,action:'update_status',order_id:String(unpaidGcash.order_id),status_id:'2',payment_method:'Cash'});
  check(Number((await db.query('SELECT status_id FROM public.orders WHERE order_id=$1',[unpaidGcash.order_id])).rows[0].status_id)===1,
    'Posting a fake Cash method cannot bypass GCash payment verification');
  await staff('staff_dashboard.php',{csrf_token:token(unpaidCashPage.html),action:'confirm_payment',order_id:String(cashOrderId)});
  const receivedCash=(await db.query('SELECT payment_status,paid_at,received_by FROM public.payments WHERE order_id=$1',[cashOrderId])).rows[0];
  check(receivedCash.payment_status==='Paid' && receivedCash.paid_at && Number(receivedCash.received_by)===2,
    'Cash is marked Paid only when the owning cashier explicitly confirms receipt, with time and staff recorded');
  const paidCashPage=await staff('staff_dashboard.php');
  check(cashCard(paidCashPage.html).includes('Mark Completed'), 'Cash completion becomes available after actual payment confirmation');
  await staff('staff_dashboard.php',{csrf_token:token(paidCashPage.html),action:'update_status',order_id:String(cashOrderId),status_id:'4'});
  check(Number((await db.query('SELECT status_id FROM public.orders WHERE order_id=$1',[cashOrderId])).rows[0].status_id)===4,
    'The cashier can complete a prepared cash order after collecting payment');
  const drinkShopper=client(); await signin(drinkShopper,'student@example.invalid');
  const drinksMenu=await drinkShopper('menu.php?store=1');
  const addedCoke=await drinkShopper('menu.php?store=1',{csrf_token:token(drinksMenu.html),item_id:String(coke.item_id),quantity:'1'});
  assert.ok(addedCoke.html.includes('added to cart'));
  const drinkCheckout=await drinkShopper('checkout.php?store=1');
  const drinkPlaced=await drinkShopper('checkout.php',{csrf_token:token(drinkCheckout.html),store_id:'1',action:'place_order',payment_method:'Cash',remarks:'No-reset drink fixture'});
  check(drinkPlaced.status===302 && (await db.query('SELECT daily_menu_id FROM public.daily_menu WHERE item_id=$1',[coke.item_id])).rows.length===0,
    'Soft drinks pass menu, cart and checkout validation without a daily availability record');
  const menuWorkspace=await staff('staff_menu.php');
  check(menuWorkspace.html.includes('aria-label="Store navigation"')
    && /<a href="staff_menu.php"[^>]*aria-current="page"/.test(menuWorkspace.html)
    && /<details\b[^>]*id="food-editor"\s*>/.test(menuWorkspace.html)
    && menuWorkspace.html.includes('<h2>Your menu</h2>')
    && !menuWorkspace.html.includes('name="opens_at"') && !menuWorkspace.html.includes('cb-store-menu-column')
    && !menuWorkspace.html.includes('cb-store-view-tabs'),
    'Default staff workspace prioritizes a full-width menu and collapsed food editor, separate from store settings');
  const editingFood=await staff('staff_menu.php?edit=1');
  const addingFood=await staff('staff_menu.php?add=1');
  check(/<details\b[^>]*id="food-editor"[^>]*\bopen/.test(editingFood.html)
    && /<details\b[^>]*id="food-editor"[^>]*\bopen/.test(addingFood.html),
    'Editing a food or following the Add Food URL opens the native editor without JavaScript');
  const invalidFood=await staff('staff_menu.php',{action:'save_food',csrf_token:token(menuWorkspace.html),item_id:'0',item_name:'Invalid fixture',description:'',category_id:'1',price:'0'});
  check(invalidFood.html.includes('Enter a positive price')
    && /<details\b[^>]*id="food-editor"[^>]*\bopen/.test(invalidFood.html)
    && invalidFood.html.includes('value="Invalid fixture"'),
    'Food validation errors keep the editor open and retain entered values');
  const hoursPage=await staff('staff_menu.php?view=settings');
  check(hoursPage.html.includes('class="cb-store-profile-form"')
    && (hoursPage.html.match(/<section\b[^>]*cb-store-profile-column/g) || []).length===2
    && hoursPage.html.includes('<h3 id="store-details-title">Store details</h3>')
    && hoursPage.html.includes('<h3 id="payment-methods-title">Payment methods</h3>')
    && !hoursPage.html.includes('<legend>')
    && hoursPage.html.includes('class="cb-store-profile-actions"')
    && (hoursPage.html.match(/value="save_store"/g) || []).length===1
    && hoursPage.html.indexOf('name="is_open"') < hoursPage.html.indexOf('id="store-payments"'),
    'Store Profile groups store details/hours and payments into two panels within one save form');
  const profileStyles=await readFile(path.join(root,'app/stores.css'),'utf8');
  check(/\.cb-store-profile-form\s*\{[^}]*align-items:stretch/.test(profileStyles)
    && /\.cb-store-profile-form > \.cb-store-profile-column\s*\{[^}]*align-self:stretch/.test(profileStyles)
    && /@media\(max-width:960px\)[^\n]*align-self:start/.test(profileStyles),
    'Store Profile styles share panel height on desktop and restore natural height in the stacked layout');
  check(/href="staff_menu.php\?view=settings"[^>]*aria-current="page"/.test(hoursPage.html)
    && !hoursPage.html.includes('<h2>Your menu</h2>') && !hoursPage.html.includes('value="save_food"'),
    'Store settings has its own focused view without menu cards or the food form');
  check(hoursPage.html.includes('name="opens_at" type="time"') && hoursPage.html.includes('name="closes_at" type="time"'),
    'Staff can edit their daily opening and closing times with Philippine-time guidance');
  const previousHours=(await db.query('SELECT opens_at,closes_at FROM stores WHERE store_id=1')).rows[0];
  const settings={action:'save_store',csrf_token:token(hoursPage.html),store_name:'Fixture Kitchen',store_description:'',payment_options_present:'1',is_open:'1'};
  await staff('staff_menu.php',{...settings,opens_at:'06:00',closes_at:'06:00'});
  assert.deepEqual((await db.query('SELECT opens_at,closes_at FROM stores WHERE store_id=1')).rows[0],previousHours);
  await staff('staff_menu.php',{...settings,csrf_token:'invalid',opens_at:'06:00',closes_at:'18:00'});
  assert.deepEqual((await db.query('SELECT opens_at,closes_at FROM stores WHERE store_id=1')).rows[0],previousHours);
  await student('staff_menu.php',{...settings,opens_at:'06:00',closes_at:'18:00'});
  assert.deepEqual((await db.query('SELECT opens_at,closes_at FROM stores WHERE store_id=1')).rows[0],previousHours);
  check(true,'Invalid/equal hours, invalid CSRF and student requests cannot change store schedules');
  const hoursShopper=client(); await signin(hoursShopper,'student@example.invalid');
  const hoursMenu=await hoursShopper('menu.php?store=1');
  await hoursShopper('menu.php?store=1',{csrf_token:token(hoursMenu.html),item_id:String(coke.item_id),quantity:'1'});
  const beforeClosing=await hoursShopper('checkout.php?store=1');
  const beforeOrders=Number((await db.query('SELECT COUNT(*) AS n FROM orders')).rows[0].n);
  // Set a valid one-hour window safely away from now, without changing the host clock.
  const closedHours=(await db.query("SELECT to_char(((clock_timestamp() AT TIME ZONE 'Asia/Manila')+INTERVAL '4 hours')::time,'HH24:MI') AS opens,to_char(((clock_timestamp() AT TIME ZONE 'Asia/Manila')+INTERVAL '5 hours')::time,'HH24:MI') AS closes")).rows[0];
  const savedSettings=await staff('staff_menu.php',{...settings,opens_at:closedHours.opens,closes_at:closedHours.closes});
  check(savedSettings.status===302 && savedSettings.location==='staff_menu.php?view=settings',
    'Saving store details returns staff to settings rather than unexpectedly switching to the menu');
  const closedStore=(await db.query('SELECT is_open,opens_at,closes_at FROM stores WHERE store_id=1')).rows[0];
  check(Number(closedStore.is_open)===1 && closedStore.opens_at.startsWith(closedHours.opens)
    && closedStore.closes_at.startsWith(closedHours.closes), 'Owning staff saves custom store hours independently of the manual open switch');
  const closedMenu=await hoursShopper('menu.php?store=1');
  check(closedMenu.html.includes('Store closed') && closedMenu.html.includes('daily (Philippine time)')
    && closedMenu.html.includes(coke.name), 'Closed kitchens still show their menus, prices and store hours, including no-reset drinks');
  const closedAdd=await hoursShopper('menu.php?store=1',{csrf_token:token(closedMenu.html),item_id:String(coke.item_id),quantity:'1'});
  check(closedAdd.html.includes('This store is closed.'), 'Closed-store water and soft drinks cannot be added even through a direct POST');
  for(const method of ['Cash','GCash']) {
    await hoursShopper('checkout.php',{csrf_token:token(beforeClosing.html),store_id:'1',action:'place_order',payment_method:method});
  }
  check(Number((await db.query('SELECT COUNT(*) AS n FROM orders')).rows[0].n)===beforeOrders,
    'A checkout opened before closing cannot place either a Cash or GCash order after closing');
  await assert.rejects(db.query("INSERT INTO orders(order_number,student_id,store_id,total_amount,order_datetime) VALUES ('CLOSED-DIRECT',4,1,50,CURRENT_TIMESTAMP-INTERVAL '1 day')"));
  check(true,'The database also rejects new closed-store orders, even with a forged earlier order time');
  const savedClosed=await hoursShopper('cart.php');
  check(savedClosed.html.includes('data-cart-store="1"') && savedClosed.html.includes('Checkout unavailable'),
    'Closing blocks checkout without deleting the customer\'s saved cart');
  const oldStatus=(await db.query('SELECT status_id FROM orders WHERE order_id=$1',[cashOrderId])).rows[0].status_id;
  const existingAfterHours=await hoursShopper('orders.php');
  check(existingAfterHours.status===200 && (await staff('staff_dashboard.php')).status===200
    && (await db.query('SELECT status_id FROM orders WHERE order_id=$1',[cashOrderId])).rows[0].status_id===oldStatus,
    'Closing leaves existing order history and staff order management intact');
  await db.query('UPDATE stores SET opens_at=$1,closes_at=$2 WHERE store_id=1',[previousHours.opens_at,previousHours.closes_at]);
  const switchSignup=await separateCarts('register.php');
  await separateCarts('register.php',{...signup,csrf_token:token(switchSignup.html),id_number:'FIXTURE-SWITCH',email:'switch@example.invalid',first_name:'Switch'});
  const switchedCart=await separateCarts('cart.php');
  check(switchedCart.status===200 && switchedCart.html.includes('Your cart is empty for now')
    && !switchedCart.html.includes('data-cart-store="1"') && !switchedCart.html.includes('data-cart-store="2"')
    && (await separateCarts('profile.php')).html.includes('switch@example.invalid'),
    'A newly created account cannot inherit another signed-in account\'s cart or identity');
  const profile=await student('profile.php');
  await student('profile.php',{csrf_token:token(profile.html),first_name:'Updated',last_name:'STUDENT',email:'student@example.invalid',contact_number:'',grade_section:'',current_password:password});
  check((await db.query("SELECT first_name FROM public.users WHERE email='student@example.invalid'")).rows[0].first_name==='Updated','Profile saving verifies the password and updates the correct account');
  await db.exec('UPDATE public.users SET is_active=0 WHERE user_id=3');
  assert.equal((await other('staff_dashboard.php')).status,302);
  check(true,'A deactivated account is rejected on its next request');
  const securityPage=await staff('staff_menu.php?view=settings');
  const passwordForm=securityPage.html.match(/<form\b[^>]*class="cb-store-password-form"[\s\S]*?<\/form>/)?.[0] || '';
  check(passwordForm.includes('value="change_password"') && passwordForm.includes('name="current_password"')
    && passwordForm.includes('name="new_password"') && passwordForm.includes('name="confirm_password"'),
    'Store Profile offers a separate current/new/confirm password form without mixing credentials into store settings');
  const originalStaffHash=(await db.query('SELECT password_hash FROM users WHERE user_id=2')).rows[0].password_hash;
  const otherStaffHash=(await db.query('SELECT password_hash FROM users WHERE user_id=3')).rows[0].password_hash;
  const newStaffPassword='New fixture '+randomBytes(18).toString('hex');
  const change={action:'change_password',csrf_token:token(passwordForm),current_password:password,new_password:newStaffPassword,confirm_password:newStaffPassword};
  for(const overrides of [
    {csrf_token:'invalid'}, {current_password:'Wrong fixture password'},
    {confirm_password:'Does not match'}, {new_password:'short',confirm_password:'short'},
    {new_password:'x'.repeat(73),confirm_password:'x'.repeat(73)},
    {new_password:'contains\0null',confirm_password:'contains\0null'},
    {new_password:password,confirm_password:password},
    {new_password:undefined,'new_password[]':'Invalid array'}
  ]) {
    const attempt=await staff('staff_menu.php?view=settings',{...change,...overrides});
    assert.equal(attempt.status,200);
    assert.ok(attempt.html.includes('role="alert"'));
    assert.ok(!attempt.html.includes(newStaffPassword) && !attempt.html.includes(password));
    assert.equal((await db.query('SELECT password_hash FROM users WHERE user_id=2')).rows[0].password_hash,originalStaffHash);
  }
  check(true,'Wrong passwords, invalid CSRF, mismatches, short/oversized/null/array input and password reuse cannot change credentials or echo secrets');
  assert.equal((await student('staff_menu.php?view=settings',change)).status,302);
  assert.equal((await client()('staff_menu.php?view=settings',change)).status,302);
  check((await db.query('SELECT password_hash FROM users WHERE user_id=2')).rows[0].password_hash===originalStaffHash,
    'Students and signed-out visitors cannot use the staff password action');
  const otherDevice=client(); await signin(otherDevice,'staff@example.invalid');
  const preChange=await staff('staff_menu.php?view=settings');
  const obsoleteSession=client(preChange.sessionCookie);
  const beforePasswordStore=(await db.query('SELECT * FROM stores WHERE store_id=1')).rows[0];
  const changedPassword=await staff('staff_menu.php?view=settings',{...change,user_id:'3',role_id:'1'});
  check(changedPassword.status===302 && changedPassword.location==='staff_menu.php?view=settings#change-password'
    && changedPassword.sessionCookie!==preChange.sessionCookie,
    'Successful password change returns to Store Profile and rotates the current session ID');
  const changedStaffHash=(await db.query('SELECT password_hash FROM users WHERE user_id=2')).rows[0].password_hash;
  check(changedStaffHash!==originalStaffHash && changedStaffHash!==newStaffPassword
    && (await db.query('SELECT password_hash FROM users WHERE user_id=3')).rows[0].password_hash===otherStaffHash,
    'A new salted hash is saved only for the authenticated store account, ignoring forged account and role fields');
  assert.deepEqual((await db.query('SELECT * FROM stores WHERE store_id=1')).rows[0],beforePasswordStore);
  const passwordConfirmed=await staff('staff_menu.php?view=settings');
  check(passwordConfirmed.status===200 && passwordConfirmed.html.includes('Password changed.')
    && !passwordConfirmed.html.includes(newStaffPassword),
    'Password saving leaves store settings unchanged and the current session signed in with a non-secret confirmation');
  check((await otherDevice('staff_dashboard.php')).status===302 && (await obsoleteSession('staff_dashboard.php')).status===302,
    'Other staff sessions and the pre-rotation session cookie are rejected after a password change');
  const replay=await staff('staff_menu.php?view=settings',change);
  check(replay.html.includes('Invalid request.') && (await db.query('SELECT password_hash FROM users WHERE user_id=2')).rows[0].password_hash===changedStaffHash,
    'The old password-change CSRF token cannot be replayed after success');
  const passwordLogin=client();
  const oldLoginPage=await passwordLogin('login.php');
  const oldLogin=await passwordLogin('login.php',{csrf_token:token(oldLoginPage.html),email:'staff@example.invalid',password});
  assert.equal(oldLogin.status,200); assert.ok(oldLogin.html.includes('Invalid email or password.'));
  const newLogin=await passwordLogin('login.php',{csrf_token:token(oldLogin.html),email:'staff@example.invalid',password:newStaffPassword});
  check(newLogin.status===302 && newLogin.location==='staff_dashboard.php'
    && (await passwordLogin('staff_dashboard.php')).status===200,
    'The old password stops working and the new password signs the store account in normally');
  const legacyKey=createHmac('sha256',sessionSecret).update(newLogin.sessionCookie.split('=')[1]).digest('hex');
  const legacyPayload=(await db.query('SELECT payload FROM campusbite_private.sessions WHERE session_key=$1',[legacyKey])).rows[0].payload;
  const legacyDecoded=Buffer.from(legacyPayload,'base64').toString('utf8');
  const withoutStamp=legacyDecoded.replace(/staff_credential_stamp\|s:64:"[a-f0-9]{64}";/,'');
  assert.notEqual(withoutStamp,legacyDecoded);
  await db.query('UPDATE campusbite_private.sessions SET payload=$1 WHERE session_key=$2',[Buffer.from(withoutStamp).toString('base64'),legacyKey]);
  check((await passwordLogin('staff_dashboard.php')).status===302,
    'Legacy staff sessions without a credential stamp must sign in again instead of bypassing password-change revocation');
  const throttlingPage=await staff('staff_menu.php?view=settings');
  const throttlingForm=throttlingPage.html.match(/<form\b[^>]*class="cb-store-password-form"[\s\S]*?<\/form>/)[0];
  const throttledChange={...change,csrf_token:token(throttlingForm),current_password:'Wrong current fixture',new_password:'Another fixture password',confirm_password:'Another fixture password'};
  for(let attempt=0;attempt<5;attempt++) await staff('staff_menu.php?view=settings',throttledChange);
  const throttled=await staff('staff_menu.php?view=settings',{...throttledChange,current_password:newStaffPassword});
  check(throttled.html.includes('Too many attempts.')
    && (await db.query('SELECT password_hash FROM users WHERE user_id=2')).rows[0].password_hash===changedStaffHash,
    'Five incorrect current-password attempts pause further password changes for one minute without modifying the account');
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
