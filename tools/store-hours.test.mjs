import { PGlite } from '@electric-sql/pglite';
import { readFile } from 'node:fs/promises';
import { spawnSync } from 'node:child_process';
import assert from 'node:assert/strict';

const db=new PGlite();
let checks=0;
const check=(value,label)=>{assert.ok(value,label);checks++;console.log('PASS: '+label);};
const cases=[
  ['06:00:00','18:00:00','2026-11-01T05:59:59+08:00',false],
  ['06:00:00','18:00:00','2026-11-01T06:00:00+08:00',true],
  ['06:00:00','18:00:00','2026-11-01T17:59:59+08:00',true],
  ['06:00:00','18:00:00','2026-11-01T18:00:00+08:00',false],
  ['06:00:00','18:00:00','2026-11-02T00:00:00+08:00',false],
  ['06:00:00','18:00:00','2026-11-02T06:00:00+08:00',true],
  ['06:00:00','18:00:00','2026-11-01T10:00:00Z',false],
  ['22:00:00','02:00:00','2026-11-01T21:59:59+08:00',false],
  ['22:00:00','02:00:00','2026-11-01T22:00:00+08:00',true],
  ['22:00:00','02:00:00','2026-11-02T00:00:00+08:00',true],
  ['22:00:00','02:00:00','2026-11-02T01:59:59+08:00',true],
  ['22:00:00','02:00:00','2026-11-02T02:00:00+08:00',false],
];
try {
  await db.exec('CREATE ROLE anon; CREATE ROLE authenticated;');
  for(const name of ['202610290001_initial_schema.sql','202610290002_runtime.sql','20261031135602_cash_pay_at_pickup.sql','20261031173553_store_hours.sql']) {
    await db.exec(await readFile(new URL('../supabase/migrations/'+name,import.meta.url),'utf8'));
  }
  for(const [opens,closes,instant,expected] of cases) {
    const result=(await db.query('SELECT public.cb_store_accepts_orders(1::smallint,$1::time,$2::time,$3::timestamptz) AS open',[opens,closes,instant])).rows[0].open;
    check(result===expected,'SQL boundary '+opens+' / '+closes+' at '+instant);
  }
  check((await db.query("SELECT public.cb_store_accepts_orders(0::smallint,'06:00','18:00','2026-11-01 12:00+08') AS open")).rows[0].open===false,'Manual closure still overrides scheduled opening');
  const php=spawnSync(process.env.PHP_BINARY || 'C:/xampp/php/php.exe',['-r',
    `require 'app/config/store-hours.php'; $cases=json_decode(stream_get_contents(STDIN),true); $out=[]; foreach($cases as [$open,$close,$instant,$expected]) { $out[]=cbStoreAcceptingOrders(['is_open'=>1,'opens_at'=>$open,'closes_at'=>$close],new DateTimeImmutable($instant)); } echo json_encode($out);`],
    {encoding:'utf8',input:JSON.stringify(cases)});
  assert.equal(php.status,0,php.stderr);
  check(JSON.stringify(JSON.parse(php.stdout))===JSON.stringify(cases.map(row=>row[3])),'PHP labels and checkout guards agree with SQL at every boundary');
  await db.exec("INSERT INTO users(role_id,id_number,first_name,last_name,email,password_hash) VALUES (2,'HOURS-STAFF','Fixture','Staff','hours-staff@example.invalid','fixture'),(3,'HOURS-STUDENT','Fixture','Student','hours-student@example.invalid','fixture'); INSERT INTO stores(staff_user_id,store_name) VALUES (1,'Hours Fixture');");
  const defaults=(await db.query('SELECT opens_at,closes_at FROM stores')).rows[0];
  check(defaults.opens_at==='06:00:00' && defaults.closes_at==='18:00:00','New and migrated stores default to 6 AM - 6 PM');
  await assert.rejects(db.exec("UPDATE stores SET opens_at='06:00',closes_at='06:00'"));
  await assert.rejects(db.exec("UPDATE stores SET opens_at='24:00'"));
  check(true,'Database constraints reject equal hours and 24:00 ambiguity');
  await db.exec("UPDATE stores SET opens_at=((clock_timestamp() AT TIME ZONE 'Asia/Manila')-INTERVAL '1 hour')::time,closes_at=((clock_timestamp() AT TIME ZONE 'Asia/Manila')+INTERVAL '1 hour')::time; INSERT INTO orders(order_number,student_id,store_id,total_amount) VALUES ('HOURS-OPEN',2,1,10); INSERT INTO payments(order_id,method,amount) VALUES (1,'Cash',10);");
  await db.exec('UPDATE stores SET is_open=0');
  await assert.rejects(db.exec("INSERT INTO orders(order_number,student_id,store_id,total_amount,order_datetime) VALUES ('HOURS-CLOSED',2,1,10,'2000-01-01')"));
  check(true,'Insert trigger blocks new orders even with backdated order_datetime');
  await db.exec('UPDATE orders SET status_id=2 WHERE order_id=1');
  check(Number((await db.query('SELECT status_id FROM orders')).rows[0].status_id)===2,'Existing unpaid-cash orders can still be prepared after closure');
  await db.exec('SET ROLE anon');
  await assert.rejects(db.exec("SELECT public.cb_store_accepts_orders(1::smallint,'06:00','18:00',clock_timestamp())"));
  await db.exec('RESET ROLE');
  check(true,'New functions are not callable by anonymous API users');
  const security=(await db.query("SELECT p.proname,p.prosecdef,pg_get_userbyid(p.proowner) AS owner FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace WHERE n.nspname='public' AND p.proname IN ('cb_store_accepts_orders','cb_require_open_store')")).rows;
  check(security.length===2 && security.every(row=>row.prosecdef===false),'Both functions remain security-invoker and do not bypass RLS');
  console.log(`${checks} store-hours checks passed with disposable fixtures only.`);
} finally { await db.close(); }
