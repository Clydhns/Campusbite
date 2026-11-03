# CampusBite Cloud Migration

Status: converted cloud candidate; not deployed or connected to Supabase yet.
Do not import real accounts or make a production deployment until the remaining
remote checks below pass.

The XAMPP application remains unchanged in `C:/xampp/htdocs/campusbite`.
`app/` is the isolated, converted application. No user database records,
password hashes, food uploads, or payment receipts have been copied.

## Chosen platform

- GitHub for source control.
- Vercel with the community PHP runtime, preserving the existing PHP interface.
- Supabase PostgreSQL and Storage for persistent application data and uploads.

PHP is a community runtime on Vercel, not an officially maintained Vercel runtime.
Review the runtime version and test preview deployments before production.

## Prepared

- PostgreSQL queries, RETURNING IDs, daily-menu upserts and safe outer-join locks.
- RLS enabled; only the server application role can access application tables.
- A private PostgreSQL session store with session-scoped advisory locks.
- Order-number counter and payment verification triggers.
- Server-side Supabase Storage requests; public meal/QR images and private receipts.
- An allowlisted Vercel PHP router and a static-only public build.
- Login/registration CSRF protection and per-request active-account checks.
- A 3 MB image limit to leave multipart overhead below the function request limit.

The base schema passed 12 embedded PostgreSQL checks. The converted PHP app and
runtime schema passed 23 integration checks using disposable fixtures: login,
student/staff/admin pages, checkout and stock, cancellation, payment confirmation,
order progression, account creation, profile saves and session invalidation.
The socket test adapter shares one PostgreSQL process; this is NOT a production
concurrency/load test. Real Supabase credentials, TLS, pooling behavior and Storage
uploads have not been tested. No Supabase project has been modified.

## Database migrations to apply (added 2026-11-02)

The code in this repository now needs these three migrations. Someone with access to
the Supabase project must paste and run them in the SQL editor, in this order, **before**
this code is deployed. Without them, My Profile and the staff order page fail with a
database error, and staff cannot add food (there are no categories to choose from).

| Order | File | What it does |
|---|---|---|
| 1 | `supabase/migrations/20261102090000_default_categories.sql` | Adds the starting food categories: Rice Meals, Snacks, Beverages, Desserts. Safe to re-run; existing names are skipped. |
| 2 | `supabase/migrations/20261102120000_favorite_stores.sql` | Creates `favorite_stores` for students' saved kitchens (heart button), with RLS and server-role access only. |
| 3 | `supabase/migrations/20261102150000_profile_photos.sql` | Adds `users.avatar_path` for optional student profile photos. |

Notes:

- Apply the earlier migrations first if the project is still empty (see step 1 below).
- No new Storage bucket is needed. Profile photos go to the existing private
  `campusbite-receipts` bucket under `avatars/` and are served only by
  `profile_photo.php` after checking the viewer is the student, that kitchen's staff,
  or an admin.
- The local dev server (`npm run dev` in `tools/`) applies new migrations to its own
  local database automatically. That never touches Supabase.

## Still required before a preview

1. Review and apply every file in `supabase/migrations/` in filename order in the empty
   Supabase project. These contain schema, reference dictionaries and the default food
   categories (Rice Meals, Snacks, Beverages, Desserts) only, not dummy accounts.
   Administrators manage categories afterwards on the admin Categories page.
2. Configure the server-only values listed in `.env.example` through Vercel's
   environment settings. Never paste secrets into chat or commit a filled env file.
3. Use the Supabase SESSION pooler on port 5432, not the transaction pooler on 6543:
   PostgreSQL session locks, role settings and timezone require stable connections.
4. Verify real Storage uploads/deletions and receipt access permissions. Both
   buckets must be new; receipt bucket must remain private with no public policies.
5. Verify the PHP community runtime bundle and routing on a protected preview.
6. Test concurrent checkouts and same-session requests against actual PostgreSQL,
   plus mobile/browser behavior, rate limiting, timeouts and failure recovery.
7. Set up database backups, expired-session cleanup and orphaned-image cleanup.
8. Decide whether to migrate existing data or create fresh real accounts. Any
   private import must preserve IDs, reset identity sequences, and initialize each
   date's order counter above imported order numbers. Do not import rows by hand.
9. Change the existing example-password account before importing any real data.
10. Review source/Git history for secrets, then push this cloud project (not XAMPP)
    to the repository. Never put uploads, database exports or receipts in Git.

## Access model

The current application uses custom PHP authentication, not Supabase Auth.
Enabling RLS alone does not translate existing role checks into Supabase policies.
The schema intentionally grants no direct browser API access. The connection uses
the NOLOGIN `campusbite_app` role after authenticating through a server-only pooler
connection. This role is trusted by RLS; server code must enforce user identity and
store ownership. Never expose database credentials or the Storage secret key to
the browser. Keep `campusbite_private` out of Supabase's exposed API schemas.

## Validation tools

Run `php tools/inventory.php` to inspect migration hotspots without reading data.
Run `php tools/check-source.php` for syntax and source-copy safety checks.
For isolated PostgreSQL checks, run `npm ci --ignore-scripts` followed by
`npm run test:schema` in `tools/`. These use an in-memory database, not Supabase.
Run `npm run test:runtime` in `tools/` for the PHP integration checks. On Windows,
these temporarily load the installed PDO PostgreSQL extension through a CLI flag;
they do not modify php.ini or the XAMPP application. Set PHP_BINARY to override
the default C:/xampp/php/php.exe path. Loopback ports 18758/18759 must be available.

Run `npm run dev` in `tools/` to browse the app at http://127.0.0.1:18769/login.php.
It uses a local PGlite database saved in `tools/.dev-data/` (git-ignored), seeded with
admin/staff/student `@example.invalid` accounts using the password `campusbite-dev`.
PHP, CSS and JavaScript edits appear on refresh. Use `npm run dev -- --reset` to start
again from the seed data. Uploaded photos and receipts are saved on disk in
`tools/.dev-data/storage/` instead of Supabase Storage.

Run `npm run build` at the project root to assemble `public/`. Only CSS, JavaScript,
and approved static assets are copied. `app/` is bundled privately by the PHP
runtime and must never be configured as Vercel's output directory.

## Vercel preview settings, after migration review

- Framework preset: Other.
- Root directory: the cloud project root (the directory containing vercel.json).
- Build command: npm run build.
- Output directory: public.
- Node version: 22.x; PHP runtime pinned in vercel.json.
- Start with a preview and deployment protection, not a public production launch.

References:
- https://vercel.com/docs/functions/runtimes
- https://github.com/vercel-community/php
- https://supabase.com/docs/guides/database/connecting-to-postgres
- https://supabase.com/docs/guides/database/postgres/row-level-security
