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

- PHP 8.2 or newer with extensions `pdo_mysql`, `mbstring`, `openssl`, `zip` (Excel export), `gd` (logo in PDF), `dom`, `fileinfo`.
- Point the domain's document root to the project's `public/` folder. The project folder itself must not be web-accessible.
- Create a MySQL database and user.

## 3. Environment

Create `.env` on the server from `.env.example`:

- `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://<domain>`, then `php artisan key:generate`
- `DB_*` from Hostinger, `SESSION_SECURE_COOKIE=true`
- `SITE_INDEXABLE=true` and `META_PIXEL_ENABLED=true` only after approval
- `DOKU_CLIENT_ID`, `DOKU_SECRET_KEY` (sandbox first, production keys at launch), `DOKU_IS_PRODUCTION`
- `ERZAP_*` stays disabled until the Erzap documentation arrives; `API_TOKENS` only if an integration needs it

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

This reconciles expired DOKU links (every 10 minutes) and sends Erzap syncs (every 5 minutes).

## 6. DOKU

In the DOKU Back Office set the **Notification URL** (and the Checkout expired notification) to `https://<domain>/webhooks/doku`. Test one sandbox payment end to end before switching to production keys. See [payments](payments-and-fulfillment.md).

## 7. Smoke test after each release

- Homepage, About, Blog and `/order` load; place a test order and open WhatsApp.
- Log in to `/admin`, open the order, confirm, and pay with the DOKU sandbox simulator; the order turns **Paid** within a minute.
- Open **Laporan** and download PDF and Excel.
- `php artisan schedule:list` shows both scheduled commands.

## Updating

Upload the new files, then `php artisan migrate --force` and `php artisan optimize`. Keep the previous release folder until the smoke test passes, so you can switch back.
