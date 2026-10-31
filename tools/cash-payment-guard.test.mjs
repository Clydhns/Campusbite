import { PGlite } from '@electric-sql/pglite';
import { readFile } from 'node:fs/promises';
import assert from 'node:assert/strict';
const db = new PGlite();
let checks = 0;
try {
    await db.exec(`CREATE ROLE anon; CREATE ROLE authenticated; CREATE ROLE campusbite_app;
        CREATE TABLE public.orders(order_id integer PRIMARY KEY,status_id integer NOT NULL);
        CREATE TABLE public.payments(order_id integer PRIMARY KEY,method text,payment_status text,payment_rejected_at timestamp);
        INSERT INTO public.orders VALUES (1,1);`);
    const sql = await readFile(new URL('../supabase/migrations/20261031135602_cash_pay_at_pickup.sql', import.meta.url), 'utf8');
    await db.exec(sql);
    await db.exec(`CREATE TRIGGER orders_require_payment BEFORE UPDATE ON public.orders
        FOR EACH ROW EXECUTE FUNCTION public.cb_require_confirmed_payment();`);
    for (const method of ['Cash','GCash']) {
        for (const payment of ['Unpaid','Paid','Refunded']) {
            for (const next of [2,3,4]) {
                await db.exec('UPDATE public.orders SET status_id=1; DELETE FROM public.payments;');
                await db.query('INSERT INTO public.payments VALUES (1,$1,$2,NULL)', [method,payment]);
                const operation = () => db.query('UPDATE public.orders SET status_id=$1 WHERE order_id=1', [next]);
                const allowed = payment === 'Paid' || (method === 'Cash' && payment === 'Unpaid' && next !== 4);
                if (allowed) await operation(); else await assert.rejects(operation);
                checks++;
            }
        }
    }
    await db.exec('UPDATE public.orders SET status_id=1; DELETE FROM public.payments;');
    for (const next of [2,3,4]) { await assert.rejects(db.query('UPDATE public.orders SET status_id=$1', [next])); checks++; }
    await db.exec("INSERT INTO public.payments VALUES (1,'GCash','Paid',CURRENT_TIMESTAMP);");
    await assert.rejects(db.query('UPDATE public.orders SET status_id=2')); checks++;
    await db.exec(sql);
    const privileges = (await db.query(`SELECT p.prosecdef,
        has_function_privilege('anon',p.oid,'EXECUTE') AS anon,
        has_function_privilege('authenticated',p.oid,'EXECUTE') AS authenticated,
        has_function_privilege('campusbite_app',p.oid,'EXECUTE') AS app
        FROM pg_proc p WHERE p.oid='public.cb_require_confirmed_payment()'::regprocedure`)).rows[0];
    assert.deepEqual(privileges,{prosecdef:false,anon:false,authenticated:false,app:true}); checks++;
    console.log(`${checks} isolated PostgreSQL cash/GCash guard checks passed; no hosted records accessed.`);
} finally { await db.close(); }
