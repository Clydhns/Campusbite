BEGIN;

-- Runtime privileges are server-only; this NOLOGIN role cannot be an API user.
DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname='campusbite_app') THEN
        CREATE ROLE campusbite_app NOLOGIN NOINHERIT;
    END IF;
END $$;
GRANT campusbite_app TO postgres;
GRANT USAGE ON SCHEMA public TO campusbite_app;
GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE
    public.roles, public.users, public.categories, public.order_statuses,
    public.stores, public.menu_items, public.daily_menu, public.orders,
    public.order_items, public.payments, public.order_status_logs,
    public.sales_records TO campusbite_app;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO campusbite_app;

DO $$ DECLARE table_name text; BEGIN
    FOREACH table_name IN ARRAY ARRAY['roles','users','categories','order_statuses',
        'stores','menu_items','daily_menu','orders','order_items','payments',
        'order_status_logs','sales_records'] LOOP
        EXECUTE format('CREATE POLICY server_application ON public.%I TO campusbite_app USING (true) WITH CHECK (true)', table_name);
    END LOOP;
END $$;

CREATE SCHEMA campusbite_private;
REVOKE ALL ON SCHEMA campusbite_private FROM PUBLIC, anon, authenticated;
GRANT USAGE ON SCHEMA campusbite_private TO campusbite_app;
CREATE TABLE campusbite_private.sessions (
    session_key char(64) PRIMARY KEY,
    payload text NOT NULL,
    expires_at timestamptz NOT NULL
);
CREATE INDEX sessions_expiry ON campusbite_private.sessions(expires_at);
ALTER TABLE campusbite_private.sessions ENABLE ROW LEVEL SECURITY;
CREATE POLICY server_application ON campusbite_private.sessions
    TO campusbite_app USING (true) WITH CHECK (true);
REVOKE ALL ON TABLE campusbite_private.sessions FROM PUBLIC, anon, authenticated;
GRANT SELECT, INSERT, UPDATE, DELETE ON campusbite_private.sessions TO campusbite_app;

-- A locked date counter avoids the old MAX(order_number)+1 checkout race.
CREATE TABLE campusbite_private.order_counters (
    order_date date PRIMARY KEY,
    last_number integer NOT NULL CHECK (last_number BETWEEN 1 AND 9999999)
);
ALTER TABLE campusbite_private.order_counters ENABLE ROW LEVEL SECURITY;
CREATE POLICY server_application ON campusbite_private.order_counters
    TO campusbite_app USING (true) WITH CHECK (true);
REVOKE ALL ON TABLE campusbite_private.order_counters FROM PUBLIC, anon, authenticated;
GRANT SELECT, INSERT, UPDATE ON campusbite_private.order_counters TO campusbite_app;
CREATE FUNCTION public.cb_generate_order_number() RETURNS trigger
LANGUAGE plpgsql SET search_path = pg_catalog AS $$
DECLARE next_number integer; day date;
BEGIN
    IF NEW.order_number IS NULL OR NEW.order_number='' THEN
        day := timezone('Asia/Manila', CURRENT_TIMESTAMP)::date;
        INSERT INTO campusbite_private.order_counters(order_date,last_number)
            VALUES (day,1) ON CONFLICT (order_date) DO UPDATE
            SET last_number=campusbite_private.order_counters.last_number+1
            RETURNING last_number INTO next_number;
        NEW.order_number := 'ORD-' || to_char(day,'YYYYMMDD') || '-' ||
            lpad(next_number::text, greatest(3,length(next_number::text)), '0');
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER orders_generate_number BEFORE INSERT ON public.orders
FOR EACH ROW EXECUTE FUNCTION public.cb_generate_order_number();

CREATE FUNCTION public.cb_require_confirmed_payment() RETURNS trigger
LANGUAGE plpgsql SET search_path = pg_catalog AS $$
BEGIN
    IF NEW.status_id IN (2,3,4) AND NEW.status_id<>OLD.status_id AND NOT EXISTS (
        SELECT 1 FROM public.payments WHERE order_id=NEW.order_id AND payment_status='Paid'
    ) THEN
        RAISE EXCEPTION 'Payment must be confirmed before changing the order status.';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER orders_require_payment BEFORE UPDATE ON public.orders
FOR EACH ROW EXECUTE FUNCTION public.cb_require_confirmed_payment();
REVOKE ALL ON FUNCTION public.cb_generate_order_number(), public.cb_require_confirmed_payment()
FROM PUBLIC, anon, authenticated;
GRANT EXECUTE ON FUNCTION public.cb_set_updated_at(), public.cb_generate_order_number(),
    public.cb_require_confirmed_payment() TO campusbite_app;

-- Public reference dictionaries only; no seeded accounts or passwords.
INSERT INTO public.roles(role_id,role_name) VALUES
    (1,'Administrator'),(2,'Canteen Staff'),(3,'Student');
INSERT INTO public.order_statuses(status_id,status_name) VALUES
    (1,'Pending'),(2,'Preparing'),(3,'Ready for Pickup'),(4,'Completed'),(5,'Cancelled');
SELECT setval(pg_get_serial_sequence('public.roles','role_id'),3);
SELECT setval(pg_get_serial_sequence('public.order_statuses','status_id'),5);
COMMIT;
