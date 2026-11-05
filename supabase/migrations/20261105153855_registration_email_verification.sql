BEGIN;
-- Temporary requests only: no account exists until email verification completes.
CREATE TABLE campusbite_private.pending_registrations (
    token_hash char(64) PRIMARY KEY,
    email varchar(100) NOT NULL UNIQUE,
    details jsonb NOT NULL CHECK (jsonb_typeof(details) = 'object'),
    expires_at timestamptz NOT NULL
);
CREATE INDEX pending_registrations_expiry ON campusbite_private.pending_registrations(expires_at);
ALTER TABLE campusbite_private.pending_registrations ENABLE ROW LEVEL SECURITY;
REVOKE ALL ON campusbite_private.pending_registrations FROM PUBLIC, anon, authenticated;
GRANT SELECT, INSERT, UPDATE, DELETE ON campusbite_private.pending_registrations TO campusbite_app;
CREATE POLICY server_application ON campusbite_private.pending_registrations
    TO campusbite_app USING (true) WITH CHECK (true);
COMMIT;
