BEGIN;

CREATE OR REPLACE FUNCTION public.cb_require_confirmed_payment() RETURNS trigger
LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog AS $$
BEGIN
    IF NEW.status_id IN (2,3,4) AND NEW.status_id IS DISTINCT FROM OLD.status_id AND NOT EXISTS (
        SELECT 1 FROM public.payments p
        WHERE p.order_id=NEW.order_id AND p.payment_rejected_at IS NULL
          AND (p.payment_status='Paid'
            OR (p.method='Cash' AND p.payment_status='Unpaid' AND NEW.status_id IN (2,3)))
    ) THEN
        RAISE EXCEPTION 'GCash requires verified payment before preparation; cash must be received before completion.';
    END IF;
    RETURN NEW;
END;
$$;

COMMENT ON FUNCTION public.cb_require_confirmed_payment() IS
    'CampusBite cash-pay-at-pickup v1: cash may prepare unpaid; completion and GCash preparation require payment.';

REVOKE ALL ON FUNCTION public.cb_require_confirmed_payment() FROM PUBLIC, anon, authenticated;
GRANT EXECUTE ON FUNCTION public.cb_require_confirmed_payment() TO campusbite_app;

COMMIT;
