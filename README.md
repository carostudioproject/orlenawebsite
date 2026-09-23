# Orlena Website

Public website, staff/catalog foundation, and customer pre-order flow using Laravel 12 + Inertia 2 + Vue 3 + TypeScript + Tailwind 4. One application with MySQL and database sessions.

## Run locally (Windows PowerShell)

Requirements: PHP 8.2+, Composer, Node 22.12+ (verified with Node 24), npm, and a running MySQL-compatible database. The local workspace uses XAMPP MariaDB; deployment remains MySQL-compatible.

```powershell
composer install
npm.cmd ci
Copy-Item .env.example .env
php artisan key:generate
# Create an empty `orlena` database and configure DB_* in .env first.
php artisan migrate --seed
php artisan orlena:create-admin
php artisan storage:link
npm.cmd run build
php artisan serve --host=127.0.0.1 --port=8000
```

Open http://127.0.0.1:8000. Only copy `.env.example` on first setup; do not overwrite an existing environment. For frontend development run `npm.cmd run dev` in a second terminal while PHP remains running.

## Implemented routes

- `/`: homepage
- `/about`: About and Our Story
- `/our-story`: permanent redirect to `/about`
- `/blog` and `/blog/{slug}`: listing and all three original articles
- `/sitemap.xml`, `/robots.txt`: environment-aware indexing
- `/admin/login`: staff login; `/admin`: protected dashboard
- `/admin/products`, `/admin/categories`, `/admin/outlets`: searchable, paginated catalog; Admin creates/updates, Staff reads
- `/admin/users`: Admin-only account management (roles: Admin, Staff, Finance, Content Editor)
- `/admin/content`, `/admin/posts`: Admin/Content Editor editing of homepage copy, outlet cards, collaborations, and blog ([content and roles](docs/content-and-roles.md))
- `/order`: one-page order form with product/quantity selection, customer details, and pickup/delivery schedule
- `/orders/{code}`: private confirmation for the originating browser session
- `/admin/orders`, `/admin/orders/{id}`: staff order search, review, Confirm & Create Payment, fulfillment status, and cancellation
- `/webhooks/midtrans`: signed Midtrans payment notifications
- `/order/tambah`, `/orders/{code}/tambah`: customers add items to an unpaid order ([order additions](docs/order-additions.md))
- `/admin/payments`, `/admin/customers`: all payment attempts; customers grouped by WhatsApp number (Admin, Staff, Finance)
- `/admin/reports`: sales and performance report with print, PDF and Excel export (Admin, Finance)
- `/admin/schedule`: PO cutoff time, closed weekdays and dates, daily capacity warning (Admin, Staff)
- `/admin/integrations`: Erzap sync log, retry and outlet/product mapping (Admin); see [Erzap](docs/erzap-integration.md)
- `/api/v1/*`: REST API, public catalog plus token-protected orders and reports ([REST API](docs/rest-api.md))

Unknown pages/articles return 404. The existing WhatsApp, maps, social links, section anchors, and footer are retained. Customer PO submission, staff review, Midtrans payment links, and fulfillment statuses are implemented ([payment notes](docs/payments-and-fulfillment.md)). Erzap sync is built and queues paid orders, but sends nothing until Erzap's API documentation and credentials are configured. Products have free-text variants (Fullsize, Halfsize, Box isi 6, ...), and hampers are products with a contents list and an optional sale period. Products/outlets are still inactive until reviewed by Admin; see [customer ordering notes](docs/customer-ordering.md).

The local first-admin credentials are in `storage/app/private/admin-initial-access.json` (ignored by Git, never served publicly). **Staff log in with a username, not an email**; the local admin's username is `admin` (existing accounts received the part of their email before @). Each user can change their name, username, email, and password under **Profil saya** (`/admin/profile`). They were generated for this workspace only. Change name, email, and password in **Akun tim → Ubah**. Fresh installations should use the interactive `orlena:create-admin` command. The optional `--local-preview` flag only works locally when no users exist; it never overwrites an existing account.

## Configuration

- `APP_URL`: canonical origin; set to the actual environment URL.
- `APP_KEY`: generate per environment; never commit.
- `APP_ENV`, `APP_DEBUG`: use `production` and `false` when releasing.
- `SITE_INDEXABLE`: defaults to `false` for local/staging; set `true` only on approved production.
- `META_PIXEL_ENABLED`: defaults to `false`; retains the original Pixel script for production activation.
- `DB_CONNECTION=mysql`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`: fill for your MySQL database.
- `SESSION_DRIVER=database`, `SESSION_ENCRYPT=true`: encrypted database sessions; database migrations are required.
- `CACHE_STORE=file`: login throttling uses shared file cache on the single application host.
- `SESSION_SECURE_COOKIE=true`: set on production HTTPS.
- `QUEUE_CONNECTION=database`: no permanent worker is needed.
- `WHATSAPP_NUMBER`: business handoff number, defaulting to the existing `6282145809558`.
- `MIDTRANS_SERVER_KEY`, `MIDTRANS_IS_PRODUCTION`: backend-only Midtrans credentials (sandbox first). Set the Midtrans Payment Notification URL to `{APP_URL}/webhooks/midtrans` over public HTTPS.
- `ERZAP_*`: Erzap connection, leave `ERZAP_ENABLED=false` until the documentation and sandbox arrive ([Erzap](docs/erzap-integration.md)).
- `API_TOKENS`: comma-separated bearer tokens for the protected REST API; empty keeps those endpoints closed.
- Cron: run `php artisan schedule:run` every minute in production (reconciles expired payment links every 10 minutes and sends Erzap syncs every 5). Locally use `php artisan schedule:work`.

Timezone is `Asia/Makassar`. Local migrations have been applied for users/session, cache/jobs, roles, categories, outlets, products, outlet prices, audit logs, customers, orders, item snapshots, and initial order history. Seeders import 6 existing marketing categories, 5 outlets, and the owner-provided 36 products in 3 additional categories (14 fullsize brownies, 14 halfsize brownies, 8 sauces). Products and outlets start inactive for ordering. Seeders do not overwrite later dashboard edits or create default accounts. See [product import notes](docs/product-catalog-import.md). Payment attempts and Midtrans events are stored in `payments` and `payment_events`.

## Verify

Create a separate empty `orlena_test` database on the local MySQL server. PHP feature tests run migrations and transactions only against this database; do not place business data in it. The application database `orlena` is not used by tests.

```powershell
npm.cmd run lint
npm.cmd run build
npm.cmd test
php vendor/bin/pint --test
php artisan test
```

GitHub Actions runs these checks on a clean checkout. Copy-baseline tests work independently of the old repository; additional direct comparisons run when the adjacent Angular folder is present. Migration scripts in `scripts/` are historical tools, not setup steps: re-running them would overwrite migrated components or reset baselines.

See [migration audit](docs/migration-audit.md) and [staff foundation notes](docs/staff-foundation.md). User accepted the initial public preview and deferred further public-page changes. Browser QA of the new dashboard and client acceptance remain required before production.

## Deployment preparation

Step-by-step guide: [Hostinger deployment](docs/deployment-hostinger.md). Staff guide (Indonesian): [panduan staff](docs/panduan-staff.md).

Hostinger document root must point to `public/`. Keep application source, `.env`, `vendor`, and private storage outside the web root. Install PHP dependencies with `composer install --no-dev --optimize-autoloader`, build assets locally, and include `public/build`, `public/assets`, and `resources/content` when uploading. Run `php artisan optimize` after configuring the environment. Verify the Hostinger PHP version before choosing the release framework version. PDF export uses dompdf: enable the PHP `gd` extension for the logo (without it the PDF uses a text wordmark); Excel export needs the `zip` extension.

No Node server, Docker, Redis, PostgreSQL, VPS, or permanent queue worker is required. Do not switch production before responsive/visual acceptance and a rollback target are established. Supplied `Orlena-System-Docs` and local secrets remain ignored by Git.

Staff review: open an order under `/admin/orders` to set delivery fees, reschedule, confirm and create the payment link, and move the order through fulfillment. See [ordering notes](docs/customer-ordering.md) and [payment notes](docs/payments-and-fulfillment.md).
