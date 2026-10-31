BEGIN;
ALTER TABLE public.stores
    ADD COLUMN opens_at time without time zone NOT NULL DEFAULT '06:00',
    ADD COLUMN closes_at time without time zone NOT NULL DEFAULT '18:00',
    ADD CONSTRAINT stores_hours_valid CHECK (opens_at <> closes_at AND opens_at < TIME '24:00' AND closes_at < TIME '24:00');

-- Opening is inclusive; closing is exclusive. Overnight schedules wrap midnight.
CREATE FUNCTION public.cb_store_accepts_orders(manual_open smallint, opens time, closes time, at_time timestamptz)
RETURNS boolean LANGUAGE sql STABLE SET search_path = pg_catalog AS $$
    SELECT COALESCE(manual_open=1 AND opens<>closes AND CASE WHEN opens<closes THEN
        (at_time AT TIME ZONE 'Asia/Manila')::time >= opens AND (at_time AT TIME ZONE 'Asia/Manila')::time < closes
    ELSE (at_time AT TIME ZONE 'Asia/Manila')::time >= opens OR (at_time AT TIME ZONE 'Asia/Manila')::time < closes END, false);
$$;
CREATE FUNCTION public.cb_require_open_store() RETURNS trigger
LANGUAGE plpgsql SET search_path = pg_catalog AS $$
DECLARE store_open smallint; opening time; closing time; owner_active smallint; owner_role smallint;
BEGIN
    SELECT s.is_open,s.opens_at,s.closes_at,u.is_active,u.role_id
        INTO store_open,opening,closing,owner_active,owner_role
        FROM public.stores s JOIN public.users u ON u.user_id=s.staff_user_id
        WHERE s.store_id=NEW.store_id FOR SHARE OF s,u;
    -- Check wall-clock time after acquiring locks, not the transaction start time.
    IF NOT FOUND OR owner_active<>1 OR owner_role<>2
        OR NOT public.cb_store_accepts_orders(store_open,opening,closing,clock_timestamp()) THEN
        RAISE EXCEPTION 'This store is closed. New orders are accepted only during its Philippine-time store hours.';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER orders_require_open_store BEFORE INSERT ON public.orders
    FOR EACH ROW EXECUTE FUNCTION public.cb_require_open_store();
REVOKE ALL ON FUNCTION public.cb_store_accepts_orders(smallint,time,time,timestamptz),public.cb_require_open_store() FROM PUBLIC,anon,authenticated;
GRANT EXECUTE ON FUNCTION public.cb_store_accepts_orders(smallint,time,time,timestamptz),public.cb_require_open_store() TO campusbite_app;
COMMIT;
