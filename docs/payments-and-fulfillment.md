# Staff confirmation, Midtrans payment, and fulfillment milestone

## Decisions confirmed in this session

- Customers pay the full order total (subtotal + delivery fee) in one Midtrans transaction. No deposits.
- A payment link is valid for 24 hours, but always closes by the PO cutoff (H-1 18:00 WITA) for the requested date. A link with less than 15 minutes left is refused. Staff must agree a new date with the customer first.
- Staff may create a new link after the previous one failed, expired, or was cancelled. A reason is required. The old attempt stays in the history.
- Fulfillment statuses are part of this milestone.

## Working flow

Review (delivery fee, optional reschedule, internal note) → **Confirm & Create Payment** → the order becomes `confirmed` and the backend requests a Snap link → staff copy the link or open a prefilled WhatsApp message to the customer → a Midtrans notification or a staff status check sets the payment status → `processing` (only once paid) → `ready` → `delivering` (delivery only) → `completed`. Pickup orders go from `ready` straight to `completed`.

The review form stays editable while the order is `pending_review` or `confirmed` with no open or paid link. The next link always uses the current total.

## Implementation boundaries

- Every link is a `payments` row with its own Midtrans `order_id` (`{order_code}-P{attempt}`). `open_order_id` is unique and set only while a link is `creating`/`pending`, so the database allows one open link per order. A double-click or a concurrent confirm is rejected.
- If Midtrans fails or times out, the order stays `confirmed`, the attempt is recorded as `creation_failed` with a short error (never credentials), and staff can retry. A `creating` attempt with no outcome after 2 minutes is treated as failed.
- The `/webhooks/midtrans` route is CSRF-exempt, rate-limited, and verifies `SHA512(order_id + status_code + gross_amount + server key)`. Each distinct event is stored once in `payment_events`, so duplicates are no-ops. An amount that does not match is recorded but never marks the order paid.
- Status changes never regress: `paid` only moves to `refunded`, and a late event for an older attempt never overwrites the newest link's status. Money received after cancellation is still recorded as paid. The dashboard then shows a refund warning.
- Snap pages that expire before the customer picks a payment method send no webhook. `payments:reconcile` (scheduled every 10 minutes through the cron `schedule:run`) checks expired pending links with the Get Status API and expires them locally when Midtrans has no transaction. Staff can also press **Cek status di Midtrans**.
- Cancellation requires a reason and is written to the status history. Open links are cancelled locally, and the backend tries to close them at Midtrans (Snap cancel, then Core cancel). If that fails, staff see a warning. Only Admin can cancel a paid order. Refunds are done manually in the Midtrans dashboard, and the resulting `refund` notification is recorded.
- The order detail page polls every 15 seconds while the order is active or a link is open. Polling pauses in background tabs and during staff actions.
- The Snap token is stored for cancellation, hidden from Inertia props, and the server key is backend-only.

## Setup

1. Set `MIDTRANS_SERVER_KEY` (sandbox key first) and `MIDTRANS_IS_PRODUCTION=false` in `.env`.
2. In the Midtrans dashboard, set Payment Notification URL to `{APP_URL}/webhooks/midtrans`. It must be public HTTPS. Local testing needs a tunnel.
3. Production cron: `php artisan schedule:run` every minute.

## Not yet included

- Erzap transaction sync after `completed` (API contract not verified).
- Customer-facing payment page or automatic WhatsApp sending; staff still send the link manually.
- Partial refunds and chargebacks are recorded as `needs_review` events only.
