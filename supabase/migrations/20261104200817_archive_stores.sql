BEGIN;
ALTER TABLE public.stores ADD COLUMN archived_at timestamptz;
ALTER TABLE public.stores ADD COLUMN retained_at timestamptz;
ALTER TABLE public.stores ADD CONSTRAINT archived_stores_closed
    CHECK (archived_at IS NULL OR is_open = 0);
ALTER TABLE public.stores ADD CONSTRAINT store_retention_choice
    CHECK (archived_at IS NULL OR retained_at IS NULL);
COMMIT;
