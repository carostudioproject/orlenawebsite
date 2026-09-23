# Erzap integration

Status: **built, waiting for Erzap's API documentation, sandbox and credentials.** Until then nothing is sent; paid orders queue up and are sent automatically once configured.

## What happens

1. A Midtrans payment becomes **paid** → a `transaction.push` row is added to `integration_syncs` (inside the same database transaction, never twice per order).
2. A paid order that is **cancelled** by Admin, or a payment that is **refunded**, adds a `transaction.cancel` row. It is skipped if the transaction never reached Erzap.
3. `php artisan erzap:sync` (scheduled every 5 minutes) sends due rows:
   - Erzap not configured → `waiting_config`
   - outlet or a product without an Erzap ID/barcode → `needs_mapping` (retried after mapping is saved)
   - Erzap error → `failed`, retried automatically after 5, 15, 60, 180 minutes (max 5 attempts), then only by the **Kirim ulang** button
   - accepted → `synced` with Erzap's reference
4. Erzap may call back `/api/v1/erzap/sync-status` and push reference stock to `/api/v1/erzap/stock` ([REST API](rest-api.md)).

Reference stock is shown to staff (product list, mapping page) and **never** validates, reserves or deducts stock for a PO, as agreed in the proposal.

## Dashboard (Admin)

- **Integrasi Erzap** (`/admin/integrations`): connection status, counts, sync log with search/filter and retry.
- **Mapping** (`/admin/integrations/mapping`): Erzap outlet ID per outlet; Erzap product ID, variant ID and barcode per product.
- Each order page shows its Erzap sync status.

## To finish when the documentation arrives

1. Fill `.env`: `ERZAP_BASE_URL`, `ERZAP_API_TOKEN`, `ERZAP_TRANSACTION_PATH`, `ERZAP_TRANSACTION_CANCEL_PATH`, `ERZAP_WEBHOOK_TOKEN`, then `ERZAP_ENABLED=true`.
2. Align `App\Services\Erzap\ErzapClient` (auth header, response reference field) and the draft payload in `App\Actions\Integrations\ErzapSync::payload()` with the real field names.
3. Adjust the callback field names in `App\Http\Controllers\Api\ErzapWebhookController` if Erzap uses a different format.
4. Product/price import from Erzap (initial and scheduled sync) is added here once the product endpoints are known; mapping columns already exist.
5. Test on the Erzap sandbox, then fill the mapping for every outlet and product.
