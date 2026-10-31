# Cash Pay At Pickup

Apply `supabase/migrations/20261031135602_cash_pay_at_pickup.sql` to the CampusBite
database before deploying the matching application change. The connected
Supabase account available during implementation did not list this project;
do not run this migration on another project. It updates only the existing
trigger function and its comment/execute permissions. It does not delete or
update customer, order or payment records. Re-running the migration is safe.

The expected function comment can be checked without customer data:

```sql
SELECT obj_description('public.cb_require_confirmed_payment()'::regprocedure, 'pg_proc') AS cash_pickup_rule;
```

It should start with `CampusBite cash-pay-at-pickup v1`.

## Rules

- Cash + Unpaid may move sequentially from Pending to Preparing to Ready for Pickup.
- Preparing and Ready do not mark cash Paid or populate paid_at/received_by.
- The cashier checks the displayed order number, receives the full cash amount,
  then explicitly uses Confirm Cash Payment. This is available in any active
  cash state, allowing early payment at the counter too.
- Mark Completed appears only after Paid, and both PHP and PostgreSQL block
  unpaid completion. Confirmation records the cashier and payment time.
- GCash still requires a verified reference/receipt and staff confirmation
  before preparation. Missing payments, refunded payments and rejected payments
  cannot use the cash exception. Browser-submitted payment methods are ignored.
- Existing store ownership, CSRF, sequential status transitions and transaction
  locks remain. Existing orders are not automatically charged or modified.
- Student notices distinguish cash due at pickup from a real confirmed payment;
  active-payment notification counts still include Paid orders only.

## Verification

Run `node tools/cash-payment-guard.test.mjs`, `node tools/runtime.test.mjs`, and the
Node tests in `app/tests`. These use disposable fixtures, not hosted customer
records. They cover cash preparation/ready while unpaid, rejected unpaid
completion, cashier confirmation at Ready, cross-store/CSRF failures, and forged
Cash parameters on a stored GCash order. The isolated trigger matrix also covers
missing, refunded and rejected payments and verifies restricted function access.

Production migration execution must be confirmed separately. A Vercel deployment
does not automatically apply SQL migrations.
