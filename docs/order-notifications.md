# Student Payment Notices

The shared student navbar shows a green My Orders count for the signed-in
student's active orders (Pending, Preparing, Ready for Pickup) whose payment is
Paid and has no rejection marker. It is an active-payment count, not an unread
message counter: opening My Orders does not clear it, but completing, cancelling
or refunding an order removes it from the count. Historical completed orders do
not generate a navbar badge.

The read-only notification endpoint derives the student ID from the authenticated
server session and checks the current account through the existing router. It
returns only that student's ID and count, never accepts another student's ID,
releases the session lock before its query, and uses private/no-store responses.
There are no browser database keys, new tables or RLS changes.

My Orders shows an explicit payment-confirmed notice within each paid,
non-cancelled/non-rejected order. Unpaid receipts awaiting verification and
refunded payments cannot trigger it. The final steps are Ready to pickup and
Completed; the database status names and staff workflow are unchanged.

Badge checks run after a three-second interval, pause in hidden/leaving tabs and
back off on errors. Order-card checks run after 1.5 seconds and use conditional
versions to skip unchanged HTML. Actual update latency includes server/network
time. Receipt edits and confirmation dialogs are preserved. These are in-site
notices, not browser push notifications.
