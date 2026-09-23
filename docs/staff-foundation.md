# Staff and catalog foundation

## Decisions confirmed in this session

- Public preview accepted as the initial version; public-page refinements deferred.
- Proceed with the next foundation milestone.
- Roles: **Admin** manages catalog, outlets, and accounts; **Staff** reads catalog now, handles orders when ordering is implemented. No per-outlet restrictions yet.
- Owner delegated the name/email of the first local administrator, provided both remain editable. Created Admin Orlena (`admin@orlena.test`) with a random password stored privately outside the web root and ignored by Git.

## Implemented flow

Login → authenticated dashboard → browse/filter products, categories, outlets → Admin creates/edits data → server validates and stores changes with actor/timestamp → logout.

Admin additionally manages accounts (name/email/role/active status/password). No public registration or default shared password. Initial admin can be created from a hidden CLI prompt. Passwords use Laravel hashing; role changes are not mass assignable through ordinary User fill operations.

Session authentication includes ID regeneration at login, logout invalidation, CSRF, per-account/IP and per-IP login throttles, disabled-account rejection on existing sessions, and backend authorization. Protected responses are not cached. Admin routes are always noindex and do not load Meta Pixel.

Account writes lock administrator rows in stable order before enforcing the last-active-admin rule. Password/disable changes revoke stored database sessions. User audit logs record role/active changes, not passwords, password hashes, or email. Catalog writes and audit entries occur in the same transaction.

## Data and price boundaries

- `categories`, `outlets`, `products`, `outlet_prices`, `audit_logs` added; `users` gains role and active state.
- Global prices and outlet overrides use whole integer rupiah, never floating point.
- Inactive products may have no price; active products require a positive base price.
- Outlet prices are unique per product/outlet; no override means base-price fallback.
- Seeded marketing outlet data stays separate from public JSON until a later approved content-management change. Changing dashboard data currently does not edit the marketing pages.
- Seeded outlets start inactive for ordering pending operational review. Six marketing categories are seeded; no fabricated SKU/product prices.
- No inventory counts, reservations, stock deduction, customer data, Erzap calls, or payment actions in this module.
- No hard-delete UI. Products/outlets/accounts can be deactivated; historical linkage can be added safely with the order schema.
- Variants, hampers, fulfillment fields, and payment policy still need definitions in the ordering milestone.

## Local verification

XAMPP MySQL-compatible server started; new `orlena` and isolated `orlena_test` databases created. Application migrations and seed applied only locally. Catalog migration rollback/up verified against `orlena_test` only.

Automated tests cover guest restrictions, role authorization, login failures/throttling/logout, inactive sessions, product pricing/validation, outlet overrides, seed idempotency, catalog audits, account editing, last-admin protection, and secret exclusion. Production build, TypeScript, ESLint, Pint, public content comparisons, and route-cache compilation checked.

Live HTTP checks exercised real CSRF cookies, successful admin login, dashboard/catalog/account pages, public homepage, and logout. Browser visual/responsive dashboard QA remains outstanding; the browser connector was unavailable in the prior phase.

## Hosting handoff

Production needs its own MySQL database, credentials, APP_KEY, HTTPS, `SESSION_SECURE_COOKIE=true`, and `SESSION_DRIVER=database`. Apply reviewed migrations after backup, seed approved baseline, then create the administrator interactively. Do not deploy local admin credential files, storage sessions, or local database contents.

No new package dependencies, permanent background process, or external service was added. MySQL and PHP are local development services. No production release has occurred.
