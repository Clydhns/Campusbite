BEGIN;

CREATE TABLE campusbite_private.password_resets (
    token_hash char(64) PRIMARY KEY,
    user_id bigint NOT NULL REFERENCES public.users(user_id) ON DELETE CASCADE,
    email varchar(100) NOT NULL,
    credential_stamp char(64) NOT NULL,
    expires_at timestamptz NOT NULL,
    used_at timestamptz
);
CREATE INDEX password_resets_user ON campusbite_private.password_resets(user_id);
CREATE INDEX password_resets_expiry ON campusbite_private.password_resets(expires_at);

CREATE TABLE campusbite_private.recovery_limits (
    limit_key char(64) PRIMARY KEY,
    attempts integer NOT NULL CHECK (attempts > 0),
    expires_at timestamptz NOT NULL
);
CREATE INDEX recovery_limits_expiry ON campusbite_private.recovery_limits(expires_at);

ALTER TABLE campusbite_private.password_resets ENABLE ROW LEVEL SECURITY;
ALTER TABLE campusbite_private.recovery_limits ENABLE ROW LEVEL SECURITY;
REVOKE ALL ON campusbite_private.password_resets, campusbite_private.recovery_limits
    FROM PUBLIC, anon, authenticated;
GRANT SELECT, INSERT, UPDATE, DELETE ON campusbite_private.password_resets,
    campusbite_private.recovery_limits TO campusbite_app;
CREATE POLICY server_application ON campusbite_private.password_resets
    TO campusbite_app USING (true) WITH CHECK (true);
CREATE POLICY server_application ON campusbite_private.recovery_limits
    TO campusbite_app USING (true) WITH CHECK (true);

COMMIT;
