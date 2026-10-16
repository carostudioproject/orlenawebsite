# Go-live on Orlena's hosting

Moving from the demo server (dev.caro-studio.com) to Orlena's own Hostinger account and domain. Technical details per step are in [Deploying to Hostinger](deployment-hostinger.md); this page is the order to do them in. Tick each box.

**Status 2026-10-16:** `https://orlenalycious.com` runs on Orlena's Hostinger account (sections 1–6 done, with the notes below); online payment (DOKU) and Erzap are not live yet, so staff record payments by hand.

## 1. Accounts (Orlena)

- [x] Hostinger web hosting, data centre Indonesia. *To confirm: the plan (Unlimited, for daily backups) and that the account is in Orlena's name.*
- [x] Domain `orlenalycious.com` (registered at Spaceship, nameservers stay at Spaceship): A `@` → hosting IP, CNAME `www` → `orlenalycious.com`; Google verification records kept, Vercel records and project domains removed.
- [x] Access for Caro Studio: hPanel team access, or at least SSH (hPanel → Advanced → SSH Access, add our public key).

## 2. Server setup (hPanel + SSH)

- [x] PHP **8.3** for the website and CLI (`php -v`), extensions as listed in the deployment guide (`php -m`).
- [x] MySQL database and user (hPanel → Databases). Keep the password only in the server `.env`.
- [x] SSL active (hPanel → Security → SSL) and HTTPS forced; `www` redirects to the main domain (public/.htaccess).
- [x] Laravel folder next to `public_html`, and `public_html` pointing to its `public/` (as on dev):

```bash
cd domains/<domain>
mkdir orlena && mv public_html public_html.default && ln -s orlena/public public_html
```

## 3. First deploy (from the developer's computer)

- [x] `scripts/deploy.live.env` from `scripts/deploy.example.env` (host, port 65002, user, key, `DEPLOY_APP_DIR=domains/<domain>/orlena`).
- [x] Commit, then `scripts/deploy.sh live`. The first run stops at `migrate` because `.env` is missing; that is expected.
- [x] `public/storage` link by hand if PHP's `symlink` is disabled (`php artisan storage:link` then fails): `ln -s ../storage/app/public public/storage`.
- [x] On the server, `.env` from `.env.example` (see the deployment guide, section 3): `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://<domain>`, `DB_*`, `SESSION_SECURE_COOKIE=true`, `SITE_INDEXABLE=true` (only when the client approves), **no** `DOKU_ALLOW_DEMO`, `DOKU_ENABLED=false` and `ERZAP_ENABLED=false` until steps 7 and 8.
- [x] `php artisan key:generate`, `php artisan migrate --force` (no `--seed`: the catalog comes from dev in step 4), then `scripts/deploy.sh live` again; it ends with "Deployed."

## 4. Catalog, content and photos from dev

Only the catalog, website content and settings move (categories, outlets, products, outlet prices, hampers/box contents, articles, website texts, PO schedule, closed dates). Test orders, customers, payments and staff accounts stay on dev.

```bash
# on dev
php artisan orlena:export-catalog
tar -czf ~/orlena-photos.tgz -C storage/app/public .
# copy storage/app/private/catalog-export.json and ~/orlena-photos.tgz to the same places on the live server, then on live:
php artisan orlena:import-catalog --replace  # --replace: migrations add default articles
tar -xzf ~/orlena-photos.tgz -C storage/app/public && php artisan optimize
```

- [x] Delete `catalog-export.json` and `orlena-photos.tgz` from both servers afterwards.
- [ ] Outlets: all 6 present (add **Jimbaran**), daily quota (30 for Living World and Mal Bali Galeria), hourly quota, first slot and closing time, **Ships delivery** where it applies. *5 outlets present; Jimbaran opens next month; opening hours still the default 13:00–21:00.*
- [ ] Products: price and photo on every active product; real Erzap barcodes (no dummy barcodes such as 123456789 or 65478931). *Orlena's product sheet imported 2026-10-16; some products still have no photo; check the barcodes 123456789, 1234567956, 1234568016 and 888123456881 against Erzap.*

## 5. Accounts

- [x] Orlena's admin account (`admin`). *Created with a random password kept off the chat; Orlena changes it under Profile after the first login.*
- [ ] One account per staff member / outlet under **Users** (no shared "Staff" account). *So far: `tiwi` (Staff) and `developer` (Caro Studio).*

## 6. Cron (hPanel → Advanced → Cron Jobs), every minute

Choose type **PHP** (a "Custom" job never ran on Orlena's account) and, after the fixed `/usr/bin/php /home/<user>/` prefix, enter:

```
domains/<domain>/orlena/artisan schedule:run
```

Every field "Setiap … (*)". The scheduled tasks run inside `schedule:run` (routes/console.php), because Hostinger disables `proc_open` on some accounts.

- [x] Added. Without it there is no auto-cancel at 21:00, no DOKU status check and no Erzap sending. Check after a few minutes: Developer tools shows the scheduler as running (green), and `php artisan schedule:list` lists `scheduler-heartbeat`, `orders:cancel-unpaid`, `payments:reconcile` and `erzap:sync`.

## 7. DOKU (production)

- [ ] Production Client ID / Secret Key in `.env`, `DOKU_IS_PRODUCTION=true`, `DOKU_ENABLED=true`, `DOKU_MODE=direct`, `DOKU_METHODS=va_bca,qris`, VA BCA and QRIS IDs from DOKU.
- [ ] SNAP key pair generated on the **live** server (Developer tools → Generate SNAP key), public key uploaded to the DOKU dashboard; DOKU's public key saved as `storage/app/private/doku-snap-doku-public.pem`.
- [ ] Token URL in the DOKU dashboard (API Keys → Pengaturan SNAP): `https://<domain>/authorization/v1/access-token/b2b`. DOKU gets a token there before each notification; without it, payments are still found by the status checks.
- [ ] Notification URLs in the DOKU dashboard on the new domain: `https://<domain>/webhooks/doku-va` and `https://<domain>/webhooks/doku-qris`.
- [ ] Deferred checks from the dev phase: SNAP signature format, VA number reuse (see the go-live notes in payments).

## 8. Erzap

- [ ] Erzap registers the new domain on the ORLENA account (Erzap calls `https://<domain>/web_service/...`).
- [ ] `ERZAP_*` in `.env`: base URL, token, inbound token if separate, sales user ID; `ERZAP_ENABLED=true`. *Base URL set (`https://orlena.erzap.com:4443`, reachable from the server; Erzap to confirm); token and IDs pending.*
- [ ] Erzap outlet ID per outlet (Erzap → Mapping), so each sale is recorded at the right outlet.
- [ ] **Dev stops sending to Orlena's Erzap**: on dev set `ERZAP_ENABLED=false` (or Erzap's demo account) and keep `DOKU_ALLOW_DEMO` only there.

## 9. Settings check (dashboard)

- [ ] PO schedule: order deadline 19:00, payment close 21:00, order up to 30 days, delivery tolerance, delivery minimum / radius when known, CK WhatsApp number.
- [ ] Website content: WhatsApp number, outlets on the website, Instagram/TikTok.

## 10. Test before announcing (Rules Order Website, section 9)

Use a test product, pay small real amounts, then cancel/refund them.

- [ ] Order for tomorrow paid at 18:59: stays on tomorrow.
- [ ] Paid at 20:30: moves to the next open date; send the customer the WhatsApp message.
- [ ] Unpaid at 21:01: cancelled automatically.
- [ ] After 19:00 tomorrow is greyed out in the calendar.
- [ ] Pickup slots per outlet follow its hours; the 21st order in one hour closes that slot; the 31st order at LW/MBG closes the date.
- [ ] Delivery: outlet choice or nearest, Maps link and recipient WhatsApp required, tolerance text before paying.
- [ ] Paid order appears in Erzap with the right outlet, amount and Order Code; a reschedule does not create a second sale.
- [ ] Rekap CK page, PDF and WhatsApp text for tomorrow.
- [ ] Security headers and HTTPS (deployment guide, section 7).

## 11. After go-live

- [ ] Hostinger backups enabled (daily); download one manually after the first week.
- [ ] Old test data stays on dev; dev keeps `SITE_INDEXABLE=false`.
- [ ] Later releases: commit, then `scripts/deploy.sh live`, then the smoke test.
