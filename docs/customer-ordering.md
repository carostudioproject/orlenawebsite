# Customer pre-order milestone

## Confirmed inputs

- Product corrections are deferred; existing draft/inactive records are not automatically published.
- Pickup and delivery are both supported. Delivery fee is confirmed by staff.
- The single order form collects name, WhatsApp, chosen outlet, requested date, optional requested time, optional email, optional note, and a required delivery address for delivery.
- Requested time is a customer preference, not a promised slot: outlet hours and production capacity are still confirmed by staff.

## Working flow

`/order` -> `/orders/{code}` -> customer clicks **Continue via WhatsApp**.

The owner explicitly requested one page containing product/quantity selection and personal details. This supersedes the earlier separate catalog/cart/checkout flow. Old public product, cart, and checkout URLs redirect to `/order`; those Vue pages and cart services have been removed. The Pre-order menu entry is currently hidden at the owner's request; `/order` remains reachable by direct link (see [content and roles](content-and-roles.md)).

Only active products with positive prices are selectable. Customers add product rows and quantities, enter their details, choose pickup/delivery, outlet, and schedule, and submit once. Changing outlet refreshes prices on the same page while preserving input. Submission is blocked until prices match the selected outlet; failed refreshes offer a retry. Product options are grouped by category, row validation errors are displayed beside the relevant input, and failed submission focuses the error summary. Staff can read orders through `/admin/orders` and `/admin/orders/{id}`, search by code/name/WhatsApp, and filter by requested date.

Staff review now supports manual delivery fees and internal notes with an immutable review history. Confirmation, payment creation, cancellation and fulfillment transitions remain deferred. No payment is created by customer submission; current initial statuses follow the latest project guide: `pending_review` and `not_created`.

## Implementation boundaries

- Product IDs and quantities are submitted with the form. The server rejects duplicate products, empty selections, quantities outside 1-99, and more than 50 distinct products. Prices and totals are calculated from the database.
- Order creation rechecks product/outlet active flags, reads authoritative prices, locks the relevant rows, and compares displayed unit prices against current database prices. Price changes require review and resubmission. No stock quantity is read, reserved, or decremented.
- Cutoff uses `Asia/Makassar`: exactly H-1 18:00:00 is accepted; later times move the earliest date forward. The server checks again at submission even if checkout was opened earlier.
- A unique checkout UUID and session ownership hash make matching retries return the existing order after submission. A changed payload under a committed key is rejected. Session locks serialize shopping requests; database unique constraints independently prevent duplicate checkout keys.
- Public Order Code format is currently `ORL-YYMMDD-XXXXXXXXXX` (10 random alphanumeric characters). This is a technical default, not a final business formatting commitment. The code contains no personal information.
- Orders preserve product name, category/size, SKU, price, quantity, outlet name, and total snapshots. Later catalog changes do not rewrite historical orders.
- Delivery fee `null` means awaiting staff confirmation; pickup fee `0` means no delivery charge. Displayed total is initial and excludes unconfirmed delivery fee.
- New customer records are created per order; no identities are merged based on unverified phone/email input.
- Confirmation requires the original browser session, or a browser that verified the Order Code together with the WhatsApp number used for the order (see [order additions](order-additions.md)). Order Code alone grants no access. Email/address are omitted from WhatsApp text. No message is sent automatically; the customer clicks the link after the order has been committed.
- Shopping responses use no-store, noindex, no-referrer, and encrypted Inertia history. Meta Pixel is excluded from shopping and admin pages. Generic floating WhatsApp is hidden within the ordering layout so handoff occurs on confirmation.
- Login/public CSRF remains enabled. Form submission is limited to 5 requests/minute per IP. Basic order creation history and an audit entry are stored without customer PII in the audit payload.

## Schema and configuration

Migration `2026_09_23_000002_create_preorders` adds `customers`, `orders`, `order_items`, `order_status_histories`. Applied locally to `orlena`; tests use only `orlena_test`. Existing imported catalog records are unchanged.

`WHATSAPP_NUMBER` defaults to the existing business number `6282145809558`; configure per environment. Payment credentials and Erzap contracts are not required by this milestone. No new dependencies or permanent workers.

## Validation

- 31 PHP tests / 525 assertions passed, including multi-product submission and outlet price snapshots.
- TypeScript production build, ESLint, Pint, 5 content comparisons, and route-cache compilation passed.
- Feature tests cover the single form without a cart, legacy redirects, active-only selections, quantities, price validation, pickup/delivery, cutoff, idempotency, and confirmation privacy.
- Browser visual/responsive and interaction QA remains pending: connected browser inventory returned no available browsers.

## Review locally

Open `/order` or the **Pre-order** navigation link. The form uses the current active product and outlet settings; this change does not alter publication status. An Admin can review and update these selections. If no active products or outlets exist, the form shows a preparation notice and disables submission.

Then select products and quantities, fill customer/date details and choose an outlet, all on the same form, and submit. The generated order appears under **Dashboard → Pesanan**. Actual complete order creation is covered by isolated test fixtures rather than activating live draft data.

Still pending: final product corrections and images, variant/hamper requirements, fulfillment-hour rules, production QA, staff review/edit/cancel rules, shipping amount entry, payment policy, and payment/Erzap integrations (payments now use DOKU). No production deployment has occurred.

## Staff review milestone (2026-09-23)

Admin and Staff can save reviews from the order detail page while the order is `pending_review` and payment is `not_created`. Delivery fee is nullable (unknown), zero (free), or a nonnegative integer. Pickup always has fee zero. Every save requires an internal note and stores the actor, timestamp, old fee, and new fee; notes never appear on customer confirmation or in WhatsApp text. Audit logs contain fee/version changes, not note content.

A locked transaction checks `review_version` to prevent overwriting a newer staff review. Total is recalculated from the stored item subtotal plus delivery fee; submitted totals/statuses are ignored. No paid order may be changed through this action. No status transitions or external messages are triggered.

Migration `2026_09_23_000003_add_order_reviews` adds `orders.review_version` and `order_reviews`. No new environment variables or dependencies. Tests cover both authorized roles, guests, disabled staff, stale submissions, invalid fees, payment/status locks, history, totals, and internal-note privacy.

Next milestone: Confirm & Create Payment (built; now DOKU Checkout, see payments-and-fulfillment.md). Full payment/deposit, expiry, cancellation/refund permissions, and sandbox credentials must be resolved before that workflow is activated.

## Order form v2 (owner request)

- Flow: choose a **category** → **product** → **size** (Fullsize/Halfsize, only for products that have sizes) → **quantity** → **Tambah** into the cart shown beside the form (sticky on desktop, a bottom bar on mobile). Then customer details, then fulfillment and schedule.
- Sizes are a product attribute (`products.size`). Products with the same name in the same category are shown as one item with size choices. The owner's FULLSIZE/HALFSIZE BROWNIES categories were merged into **Brownies** with sizes; SKUs, prices, and active flags did not change. Orders keep the size in `order_items.variant_snapshot`.
- **Pickup** asks for an outlet. **Delivery (Gojek/Grab)** has no outlet choice: it always ships from the outlet marked **Outlet pengiriman delivery** in the Outlet menu (one outlet only), and its prices apply. If that outlet is inactive or not taking PO, delivery is unavailable.
- Requested time is now required.
- The WhatsApp message is formatted with bold labels, one line per item (with size), subtotal, delivery/pickup, schedule in Indonesian date format, and the note. Address and email are still left out.
- Instagram/TikTok links and the WhatsApp number come from **Konten website → Sosial media & WhatsApp** and are used by the header, mobile menu, footer, floating WhatsApp button, and the order handoff. An empty social link hides it.
