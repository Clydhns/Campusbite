# Flagged-page review: 2026-11-05

Google Search Console reported deceptive pages at `login.php` and
`dashboard.php`. The report supplies sample URLs, not the offending element.
This review does not establish Google's exact detection reason or certify the
whole site as safe.

## Checks performed

- Reviewed login form processing, the shared auth view and auth-switch script,
  dashboard rendering/live-update scripts, student navigation and API routing.
- Login credentials post to the same site's `login.php`; SQL lookup is
  parameterized and passwords are checked using `password_verify`.
- Anonymous production dashboard requests return a 302 to `login.php`.
- The five deployed login/dashboard scripts match their repository sources
  (one comparison required normalizing CRLF/LF line endings).
- No external credential submission or malicious redirect was identified in
  the reviewed code. Public HTTP success alone is not a security clearance.

## Changes

- Restored a compact independent-student-project disclosure and data-use link
  on direct login; added the same disclosure to the dashboard.
- Replaced school-specific registration examples with neutral examples.
- Restricted executable scripts and fetch connections to the same origin on
  login, registration and dashboard; prohibited embedded frames on those routes.
  Existing form, framing, object and base-URL restrictions remain enabled.
- Removed database exception details from dashboard error responses.

## Verification and limits

253 PHP/PostgreSQL integration checks passed against disposable local fixtures,
including authentication, orders, payments, recovery, injected store-description
escaping and the stricter response policies. The static asset build passed.
No production account was signed into, and no production records were changed.
Browser-level functional QA with the stricter policy is still recommended.
Production account-uploaded content behind login and hosting/account access logs
were not exhaustively audited.

Only Google can reassess and remove its warning. The owner should describe the
actual changes in Search Console's Request Review; do not claim a confirmed
malware removal, school affiliation or guaranteed security approval. Do not
disable Safe Browsing, bypass its warning or move domains to evade it.
