# Deploying to Hostinger (shared hosting)

No Node server, Redis, Docker or queue worker is needed. PHP, MySQL and one cron job are enough.

## 1. Prepare on your computer

```powershell
composer install --no-dev --optimize-autoloader
npm.cmd ci
npm.cmd run build
```

Upload everything except `node_modules`, `.env`, `tests`, and `storage/app/private/*` (keep the empty folder structure of `storage/`).

## 2. Hostinger settings

- PHP **8.2, 8.3 or 8.4** (the locked OpenSpout version does not install on 8.5), for both the website and the cron job's PHP (`/usr/bin/php`), with extensions `pdo_mysql`, `mbstring`, `openssl`, `curl`, `zip`, `xmlreader` and `libxml` (Excel import/export), `gd` with WebP (photo resizing, logo in PDF), `dom`, `fileinfo`, `exif` (photo rotation, optional). Check with `php -v` and `php -m` over SSH; `composer check-platform-reqs` lists anything missing.
- Upload limits for photos and Erzap image sync: `upload_max_filesize` ≥ 8M, `post_max_size` ≥ 32M, `max_file_uploads` ≥ 20.
- Point the domain's document root to the project's `public/` folder. The project folder itself must not be web-accessible.
- Create a MySQL database and user.

## 3. Environment

Create `.env` on the server from `.env.example`:

- `APP_ENV=production`, `APP_DEBUG=false`, `LOG_LEVEL=warning`, `APP_URL=https://<domain>`, then `php artisan key:generate`. `.env.example` holds development defaults (debug on); never copy those to the live site. Developer tools shows "debug ON" in red if it is left on.
- `DB_*` from Hostinger, `SESSION_SECURE_COOKIE=true`
- `SITE_INDEXABLE=true` and `META_PIXEL_ENABLED=true` only after approval
- Payments: choose one setup in step 6. `DOKU_MODE=demo` is blocked when `APP_ENV=production` unless `DOKU_ALLOW_DEMO=true` (only for a demo server such as dev.caro-studio.com; never on the live shop, since a simulated payment counts as paid and goes to Erzap).
- `ERZAP_*`: base URL, token, outlet and sales user IDs from Erzap; `ERZAP_INBOUND_TOKEN` only if Erzap gives a separate token for its calls to `/web_service/...` (see [Erzap integration](erzap-integration.md)). `API_TOKENS` only if an integration needs it.

## 4. First run (SSH or Hostinger terminal)

```bash
php artisan migrate --force --seed
php artisan orlena:create-admin
php artisan storage:link
php artisan optimize
```

## 5. Cron (hPanel → Advanced → Cron Jobs), every minute

```
cd /home/<user>/<project> && php artisan schedule:run >> /dev/null 2>&1
```

This cancels orders still unpaid at the payment close (every minute), checks DOKU payments (every 10 minutes) and sends Erzap syncs (every 5 minutes). Developer tools shows when the cron last ran; red means it is not set up. The full go-live order is in [Go-live](go-live.md).

## 6. DOKU

Pick one mode and fill only its settings (details in [payments](payments-and-fulfillment.md)). Test one sandbox payment end to end, then switch `DOKU_IS_PRODUCTION=true` with the production keys.

| Mode | `.env` | Notification URL in the DOKU dashboard | Sandbox test |
|---|---|---|---|
| Customer chooses **VA BCA or QRIS** on `/bayar` (planned for Orlena) | `DOKU_ENABLED=true`, `DOKU_MODE=direct`, `DOKU_METHODS=va_bca,qris`, `DOKU_CLIENT_ID`, `DOKU_SECRET_KEY` (or `DOKU_SNAP_CLIENT_SECRET`), `DOKU_VA_BCA_PARTNER_SERVICE_ID`, `DOKU_VA_BCA_CUSTOMER_PREFIX`, `DOKU_QRIS_MERCHANT_ID`, `DOKU_QRIS_TERMINAL_ID`, SNAP private key at `storage/app/private/doku-snap-private.pem` (generate it in Developer tools and upload the public key to DOKU) | VA: `https://<domain>/webhooks/doku-va`; QRIS: `https://<domain>/webhooks/doku-qris` | VA only (`DOKU_METHODS=va_bca`): the DOKU sandbox has no QRIS. Pay with the VA simulator. |
| **QRIS only** on `/bayar` | `DOKU_ENABLED=true`, `DOKU_MODE=qris`, the QRIS and SNAP settings above | `https://<domain>/webhooks/doku-qris` | Production only |
| **DOKU Checkout page** (all methods, or `DOKU_MODE=va` for VA only) | `DOKU_ENABLED=true`, `DOKU_MODE=checkout` or `va`, `DOKU_CLIENT_ID`, `DOKU_SECRET_KEY` | `https://<domain>/webhooks/doku` (also the Checkout expired notification) | Sandbox simulator |
| **No online payment** | `DOKU_ENABLED=false` | none | Staff use Mark as paid |

Developer tools (`/admin/system`, Developer role) shows the active mode, which settings are filled and the Notification URLs to copy.

## 7. Smoke test after each release

- Homepage, About, Blog and `/order` load; place a test order and open WhatsApp.
- Log in to `/admin`, open the order and confirm it. Pay it through the mode chosen in step 6 (VA simulator in the sandbox, or a real small payment in production); the order turns **Paid** within a minute and is sent to Erzap. Mark as paid alone does not prove the DOKU setup.
- Response headers include `X-Frame-Options`, `X-Content-Type-Options` and, over HTTPS, `Strict-Transport-Security`.
- Open **Laporan** and download PDF and Excel.
- `php artisan schedule:list` shows both scheduled commands.

## Updating

Upload the new files, then `php artisan migrate --force` and `php artisan optimize`. Keep the previous release folder until the smoke test passes, so you can switch back.
