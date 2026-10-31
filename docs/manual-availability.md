# Manual Food Availability

The cloud application uses `menu_items.is_available` as its availability control.
Staff can change it in the food editor or immediately from each food card.
Available food stays orderable across days until staff switches it off. Store
closure, inactive staff accounts, archived food and authorization still block orders.

Daily serving counts no longer limit orders, reset availability, or appear in the
staff editor or student food cards. Existing `daily_menu` rows remain in the database
for legacy records; new food saves, checkouts, cancellations and payment rejections
do not adjust their serving counts. No schema migration or data reset is required.

Checkouts recheck availability and current prices while holding menu/store row
locks. Availability changes affect new orders only, not already-placed orders.
Quantity limits remain: 20 per menu addition and 99 of each food per store cart.

Quick switches require an authenticated store owner and valid CSRF token. They
set an explicit state so retries are idempotent. JavaScript updates the control
only after server confirmation; regular form submission remains the fallback.
