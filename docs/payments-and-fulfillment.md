# Staff confirmation, DOKU payment, and fulfillment

Payments use **DOKU Checkout** (non-SNAP API). Midtrans was replaced on 29 September 2026; older payment rows keep `provider = midtrans`.

## Decisions

- Customers pay the full order total (subtotal + delivery fee) in one DOKU Checkout transaction. No deposits.
- A payment link is valid for 24 hours, but always closes by the order deadline (H-1, default 18:00 WITA) for the requested date. A link with less than 15 minutes left is refused. Staff must agree a new date with the customer first.
- Staff may create a new link after the previous one failed, expired, or was cancelled. A reason is required. The old attempt stays in the history.

## Working flow

Review (delivery fee, optional reschedule, internal note) → **Confirm & Create Payment** → the order becomes `confirmed` and the backend creates a DOKU Checkout page → staff copy the link or open a prefilled WhatsApp message to the customer → a DOKU notification or a staff status check sets the payment status → `processing` (only once paid) → `ready` → `delivering` (delivery only) → `completed`. Pickup orders go from `ready` straight to `completed`.

The review form stays editable while the order is `pending_review` or `confirmed` with no open or paid link. The next link always uses the current total.

## Implementation

- `App\Services\Doku\DokuClient` signs every request: `Signature: HMACSHA256=base64(HMAC-SHA256(secret, "Client-Id:…\nRequest-Id:…\nRequest-Timestamp:…\nRequest-Target:<path>\nDigest:base64(SHA256(body))"))`. GET requests have no Digest.
- Create: `POST /checkout/v1/payment` with `order.invoice_number` = `{order_code}-P{attempt}`, `order.amount`, line items (only when they add up to the amount), `payment.payment_due_date` in minutes, and the customer's name, WhatsApp and email. The returned `payment.url` is the link staff send; `token_id` and the Request-Id are stored (hidden from the dashboard), and DOKU's `session_id` is shown as the transaction reference.
- Every link is a `payments` row. `open_order_id` is unique and set only while a link is `creating`/`pending`, so the database allows one open link per order. A double-click or a concurrent confirm is rejected.
- If DOKU fails or times out, the order stays `confirmed`, the attempt is recorded as `creation_failed` with a short error (never credentials), and staff can retry. A `creating` attempt with no outcome after 2 minutes is treated as failed.
- `/webhooks/doku` is CSRF-exempt, rate-limited, and verifies the notification Signature (Request-Target `/webhooks/doku`) and Client-Id. Each distinct event is stored once in `payment_events`, so duplicates are no-ops. An amount that does not match is recorded but never marks the order paid.
- Status mapping: `SUCCESS` → paid; `PENDING`, `TIMEOUT`, `REDIRECT` → pending; `FAILED` → stays pending (DOKU Checkout lets the customer try another method on the same page, as DOKU recommends); `EXPIRED` → expired; `REFUNDED` → refunded.
- Status changes never regress: `paid` only moves to `refunded`, and a late event for an older attempt never overwrites the newest link's status. Money received after cancellation is still recorded as paid, and the dashboard shows a refund warning.
- Links that expire without payment may send no notification. `payments:reconcile` (every 10 minutes through the cron `schedule:run`) checks expired pending links with `GET /orders/v1/status/{invoice}` and expires them locally when DOKU has no paid transaction. Staff can also press **Check status in DOKU**.
- Cancellation requires a reason and is written to the status history. Open links are cancelled locally, and the backend asks DOKU to cancel the checkout (`POST /checkout/v3/cancellations`). DOKU only cancels unpaid bank transfer (VA) and QRIS checkouts, and only when **Order Cancellation** is enabled in the DOKU Back Office; otherwise staff see a warning to watch for payments. Only Admin can cancel a paid order. Refunds are done manually in the DOKU Back Office.
- The order detail page polls every 15 seconds while the order is active or a link is open.

## Setup

1. DOKU Back Office (sandbox first) → API Keys: copy **Client ID** and **Secret Key** to `DOKU_CLIENT_ID` and `DOKU_SECRET_KEY`, with `DOKU_IS_PRODUCTION=false`.
2. Set the **Notification URL** to `{APP_URL}/webhooks/doku`, and enable the Checkout **Expired Notification** with the same URL. It must be public HTTPS; local testing needs a tunnel.
3. Optional: enable **Order Cancellation** (Settings → Checkout Appearance → System Settings) so cancelled orders close their VA/QRIS checkout.
4. Production cron: `php artisan schedule:run` every minute.
5. Pay one sandbox order end to end (DOKU has a payment simulator), then switch to production keys.

## Not included

- Customer-facing payment page or automatic WhatsApp sending; staff still send the link manually.
- Partial refunds and chargebacks are recorded as `needs_review` events only.

## Without DOKU (`DOKU_ENABLED=false`, the default)

Until DOKU is configured, **Confirm order** only confirms the order (no payment link, no call to DOKU). Staff ask the customer to pay by transfer or at the outlet, then use **Mark as paid** on the order (method: bank transfer, cash, QRIS at the outlet, other; optional note). This records a `payments` row with `provider = manual` and goes through the same status handling as a DOKU SUCCESS, so the order becomes paid and is queued for Erzap. **Mark as paid** stays available after DOKU is turned on, for payments received outside DOKU.

## QRIS on Orlena's own payment page (`DOKU_MODE=qris`, the default)

With DOKU enabled, **Confirm & Create Payment** asks the DOKU QRIS Direct API (SNAP) for a QRIS and the payment link becomes `{APP_URL}/bayar/{order code}`: Orlena's page with the order summary, the QR (drawn in the browser), the deadline and a "Simpan QR" button. It shows no name, phone number or address. Cek Pesanan's "Bayar sekarang" and the staff WhatsApp message open the same page.

- Auth: a B2B access token (`/authorization/v1/access-token/b2b`, SHA256withRSA over `clientId|timestamp` with our private key) cached for 10 minutes; QRIS calls are signed with HMAC-SHA512 over `POST:path:token:sha256(body):timestamp`.
- Payment detection: the page polls `/bayar/{code}/status` every 5 s, which asks DOKU (QRIS query, at most every 5 s per payment). `/webhooks/doku-qris` only triggers the same query, so a forged notification cannot mark an order paid. `payments:reconcile` also checks and expires old links.
- Cancelling an order expires its QRIS (`qr-expire`).
- `DOKU_MODE=checkout` switches back to the hosted DOKU Checkout page.

Setup (sandbox first): ask DOKU to activate **QRIS** and send the **Merchant ID (mall ID)** and **Terminal ID**; generate an RSA key pair on the server (`openssl genrsa -out storage/app/private/doku-snap-private.pem 2048`, then `openssl rsa -in … -pubout`) and upload the public key in the DOKU dashboard; set the QRIS Notification URL to `{APP_URL}/webhooks/doku-qris`; fill `DOKU_ENABLED=true`, `DOKU_CLIENT_ID`, `DOKU_SECRET_KEY` (or `DOKU_SNAP_CLIENT_SECRET`), `DOKU_QRIS_MERCHANT_ID`, `DOKU_QRIS_TERMINAL_ID`, `DOKU_QRIS_POSTAL_CODE`. Test with the DOKU QRIS simulator: https://sandbox.doku.com/qris-simulator/
