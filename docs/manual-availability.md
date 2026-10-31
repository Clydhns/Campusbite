# Manual Food Availability

The cloud application combines `menu_items.is_available` with a dated daily flag.
Staff can change it in the food editor or immediately from each food card.
Availability is valid only for the current Philippine calendar day. At midnight
in Asia/Manila, all food and drinks become unavailable until staff enables them
for the new day. New food defaults to unavailable in the editor. Store
closure, inactive staff accounts, archived food and authorization still block orders.

Daily serving counts do not limit orders or appear in food cards. The existing
`daily_menu` date/item key and availability flag record the staff's daily choice;
new rows use zero default counts. Saving or toggling food changes only the daily
availability flag, preserving any old serving counts. Checkout, cancellation and
payment rejection never adjust serving counts. No migration or scheduled job is
needed: a missing or disabled flag for today's date means unavailable.

Checkouts recheck availability and current prices while holding menu/store row
locks. Availability changes affect new orders only, not already-placed orders.
Staff pages left open overnight reset their switches without discarding form text.
Quantity limits remain: 20 per menu addition and 99 of each food per store cart.

Quick switches require an authenticated store owner and valid CSRF token. They
set an explicit state so retries are idempotent. JavaScript updates the control
only after server confirmation; regular form submission remains the fallback.
