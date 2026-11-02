BEGIN;

-- Kitchens a student has saved with the heart button, listed on the Favourites page.
CREATE TABLE public.favorite_stores (
    student_id bigint NOT NULL REFERENCES public.users(user_id) ON DELETE CASCADE ON UPDATE CASCADE,
    store_id bigint NOT NULL REFERENCES public.stores(store_id) ON DELETE CASCADE ON UPDATE CASCADE,
    created_at timestamp NOT NULL DEFAULT timezone('Asia/Manila', CURRENT_TIMESTAMP),
    PRIMARY KEY (student_id, store_id)
);
CREATE INDEX idx_favorite_stores_store ON public.favorite_stores (store_id);

ALTER TABLE public.favorite_stores ENABLE ROW LEVEL SECURITY;
REVOKE ALL ON TABLE public.favorite_stores FROM PUBLIC, anon, authenticated;
GRANT SELECT, INSERT, DELETE ON TABLE public.favorite_stores TO campusbite_app;
CREATE POLICY server_application ON public.favorite_stores TO campusbite_app USING (true) WITH CHECK (true);

COMMIT;
