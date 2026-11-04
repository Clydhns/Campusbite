BEGIN;

-- Preserve foreign-key history while removing accounts from the active directory.
ALTER TABLE public.users ADD COLUMN archived_at timestamptz;
ALTER TABLE public.users ADD CONSTRAINT archived_users_inactive
    CHECK (archived_at IS NULL OR is_active = 0);

COMMIT;
