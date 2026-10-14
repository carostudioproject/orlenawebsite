# Go-live on Orlena's hosting

Moving from the demo server (dev.caro-studio.com) to Orlena's own Hostinger account and domain. Technical details per step are in [Deploying to Hostinger](deployment-hostinger.md); this page is the order to do them in. Tick each box.

## 1. Accounts (Orlena)

- [ ] Hostinger **Unlimited** web hosting (daily backups), data centre in Asia, bought **in Orlena's name** and paid by Orlena.
- [ ] Domain in Orlena's name, added to the hosting (Hostinger DNS, or A record to the hosting IP).
- [ ] Access for Caro Studio: hPanel team access, or at least SSH (hPanel → Advanced → SSH Access, add our public key).

## 2. Server setup (hPanel + SSH)

- [ ] PHP **8.3** for the website and CLI (`php -v`), extensions as listed in the deployment guide (`php -m`).
- [ ] MySQL database and user (hPanel → Databases). Keep the password only in the server `.env`.
- [ ] SSL active (hPanel → Security → SSL) and HTTPS forced.
- [ ] Laravel folder next to `public_html`, and `public_html` pointing to its `public/` (as on dev):

```bash
cd domains/<domain>
mkdir orlena && mv public_html public_html.default && ln -s orlena/public public_html
```

## 3. First deploy (from the developer's computer)

- [ ] `scripts/deploy.live.env` from `scripts/deploy.example.env` (host, port 65002, user, key, `DEPLOY_APP_DIR=domains/<domain>/orlena`).
- [ ] Commit, then `scripts/deploy.sh live`. The first run stops at `migrate` because `.env` is missing; that is expected.
- [ ] On the server, `.env` from `.env.example` (see the deployment guide, section 3): `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://<domain>`, `DB_*`, `SESSION_SECURE_COOKIE=true`, `SITE_INDEXABLE=true` (only when the client approves), **no** `DOKU_ALLOW_DEMO`, `DOKU_ENABLED=false` and `ERZAP_ENABLED=false` until steps 7 and 8.
- [ ] `php artisan key:generate`, `php artisan migrate --force` (no `--seed`: the catalog comes from dev in step 4), `php artisan storage:link`, then `scripts/deploy.sh live` again; it ends with "Deployed."

## 4. Catalog, content and photos from dev

Only the catalog, website content and settings move (categories, outlets, products, outlet prices, hampers/box contents, articles, website texts, PO schedule, closed dates). Test orders, customers, payments and staff accounts stay on dev.

```bash
# on dev
php artisan orlena:export-catalog
tar -czf ~/orlena-photos.tgz -C storage/app/public .
# copy storage/app/private/catalog-export.json and ~/orlena-photos.tgz to the same places on the live server, then on live:
php artisan orlena:import-catalog            # add --replace if the database was seeded
tar -xzf ~/orlena-photos.tgz -C storage/app/public && php artisan optimize
```

- [ ] Delete `catalog-export.json` and `orlena-photos.tgz` from both servers afterwards.
- [ ] Outlets: all 6 present (add **Jimbaran**), daily quota (30 for Living World and Mal Bali Galeria), hourly quota, first slot and closing time, **Ships delivery** where it applies.
- [ ] Products: price and photo on every active product; real Erzap barcodes (no dummy barcodes such as 123456789 or 65478931).

## 5. Accounts

- [ ] `php artisan orlena:create-admin` for Orlena's admin (password typed by them, never sent in chat).
- [ ] One account per staff member / outlet under **Users** (no shared "Staff" account).

## 6. Cron (hPanel → Advanced → Cron Jobs), every minute

```
cd /home/<user>/domains/<domain>/orlena && php artisan schedule:run >> /dev/null 2>&1
```

- [ ] Added. Without it there is no auto-cancel at 21:00, no DOKU status check and no Erzap sending. Check after a few minutes: Developer tools shows the scheduler's last runs, and `php artisan schedule:list` lists `payments:reconcile`, `erzap:sync` and `orders:cancel-unpaid`.

## 7. DOKU (production)

- [ ] Production Client ID / Secret Key in `.env`, `DOKU_IS_PRODUCTION=true`, `DOKU_ENABLED=true`, `DOKU_MODE=direct`, `DOKU_METHODS=va_bca,qris`, VA BCA and QRIS IDs from DOKU.
- [ ] SNAP key pair generated on the **live** server (Developer tools → Generate SNAP key), public key uploaded to the DOKU dashboard; DOKU's public key saved as `storage/app/private/doku-snap-doku-public.pem`.
- [ ] Notification URLs in the DOKU dashboard on the new domain: `https://<domain>/webhooks/doku-va` and `https://<domain>/webhooks/doku-qris`.
- [ ] Deferred checks from the dev phase: SNAP signature format, VA number reuse (see the go-live notes in payments).

## 8. Erzap

- [ ] Erzap registers the new domain on the ORLENA account (Erzap calls `https://<domain>/web_service/...`).
- [ ] `ERZAP_*` in `.env`: base URL, token, inbound token if separate, sales user ID; `ERZAP_ENABLED=true`.
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
