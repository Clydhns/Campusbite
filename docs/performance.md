# Performance Pass

The October 31 optimization keeps private pages uncached and revalidates active
accounts on every request. Checkout still checks current ownership, prices and
availability under transaction locks. No schema migration is required.

Verified database round-trip reductions using disposable PostgreSQL fixtures:

- A 20-item cart uses one catalog query instead of 20 per-item queries. Empty carts
  use no catalog query. Store grouping remains separately authorized from database rows.
- Existing staff stores load with one query instead of three. First-time store
  creation and concurrent-create fallback still use the unique staff/store constraint.
- All 14 admin statistics are computed in one query, aggregating each relevant
  table once. Completed-order sales and Philippine-day totals retain their meaning.
- Staff order-item reads are limited to the 50 displayed orders, not the store's
  entire historical item list. Overall statistics still include the full history.

Menu, cart, order-list and staff pages save session changes and release the session
lock before their remaining read-only work. Order polling aborts when navigation
starts, pauses while hidden, and resumes after back-forward cache restoration.

Dashboards without orders skip the 255,119-byte raw tracking JavaScript bundle.
Compressed transfer savings depend on hosting/browser caching. Dashboards with
orders retain the existing tracking component.

Run `node tools/runtime.test.mjs` and the Node tests in `app/tests/` to verify.
Query counts are measured by a test-only PDO delegate. These are not production
load tests or guarantees of a particular end-to-end navigation time; network
latency, cold starts, uploads and real concurrency still affect response times.
