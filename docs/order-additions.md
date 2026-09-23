# Study case: adding items to an unpaid order

## Scenario

Sinta orders 2x Brownies Berry (full) for pickup at Orlena Padangsambian on Friday at 10:00. Total: Rp 160.000. She sends the order to Orlena on WhatsApp. That evening she decides to add 3x Nutella sauce. Orlena has not confirmed the order and no payment link exists yet.

Before this feature, Sinta had to place a second order, or ask on WhatsApp so staff could edit the order by hand. Now she adds the items herself with her Order Code.

## Customer flow

**Same browser as the original order**

1. Open `/orders/{code}` (the confirmation page) and click **Tambah pesanan**.
2. On `/orders/{code}/tambah`, pick a category, product, size and quantity (the same picker as `/order`), then click **Tambah**.
3. The summary shows the current items, the additions, and the new initial total. Click **Simpan tambahan**.
4. The confirmation page shows a **Tambahan terakhir** card with a **Kirim update via WhatsApp** button. The message lists only the added items, the added subtotal, and the new initial total.

**Another device, or the session has expired**

1. Open `/order/tambah`. The order form links to it: *"Sudah punya Order Code? Tambah ke pesanan yang belum dibayar"*.
2. Enter the Order Code and the WhatsApp number used for the order. `08…`, `+62…`, spaces and dashes are accepted, and the code is not case-sensitive.
3. When both match, the browser is granted access to that order and goes to `/orders/{code}/tambah`. From here the flow is the same as above.

Opening `/orders/{code}/tambah` without access redirects to `/order/tambah?code={code}` with the code already filled in.

## Rules

| Rule | Behaviour |
|---|---|
| Who can add | Only the original browser session, or a browser that verified Order Code + WhatsApp number. The Order Code alone grants no access. |
| When | Only while `order_status = pending_review`, `payment_status = not_created`, no payment link is open, and before the cutoff (H-1 18:00 WITA, Asia/Makassar). |
| After confirmation or payment | Blocked. The page explains why and tells the customer to contact Orlena. Staff handle the change manually. |
| Add only | Customers cannot reduce or remove items that were already ordered. Reductions go through staff. |
| Merging | A product that is already in the order at the same price is merged into that line (2x + 1x = 3x). Otherwise a new line is added. |
| Limits | Max 99 per line and max 50 lines in total, the same limits as a new order. |
| Prices | Taken from the database at the order's outlet, including outlet-specific prices. A price that changed while the page was open is rejected and the customer must review it again. |
| Double submit | Each addition carries a unique `add_key`. Sending the same form again returns the stored addition and never doubles it. |
| Guessing | Lookup attempts are limited to 5 per device and 5 per Order Code (5-minute lock). The error message never says whether the code or the number was wrong. |
| Delivery fee | Unchanged. For delivery it stays "awaiting confirmation" until staff set it. |

## What changes in the data

- `order_items`: new lines or increased quantities (snapshots of name, size and price).
- `orders`: `subtotal` and `total` are recalculated. `review_version` is incremented so an open staff review screen notices the change.
- `order_additions`: one row per addition, with the items, `subtotal_added`, `previous_total`, `new_total` and the time.
- `audit_logs`: action `order.items_added`.

## What staff see

`/admin/orders/{id}` shows an alert when the customer added items, plus a **Tambahan dari pelanggan** section with each addition (time, items, added subtotal, total before and after). The page refreshes automatically, so a reviewer sees an addition that arrives while they are reading. Staff still confirm availability before confirming the order and creating the payment link. The payment link is always created from the latest total.

## Edge cases

| Case | Result |
|---|---|
| Customer adds at 18:01 on H-1 | Rejected: "past the H-1 18:00 WITA cutoff". Total unchanged. |
| Staff confirm while the customer's add page is open | Saving is rejected and the page shows that the order is already confirmed. |
| Product deactivated after the page was opened | Rejected at save; the customer picks again. |
| Wrong WhatsApp number | Generic "Order Code atau nomor WhatsApp tidak cocok." After 5 tries, locked for 5 minutes, even with the correct number. |
| Outlet stopped taking pre-orders | Rejected; the customer contacts Orlena. |

## Out of scope

- Reducing or removing items, or changing the date, time, outlet or delivery method (handled by staff).
- Adding items after a payment link exists. Staff would have to expire the link, adjust the order and create a new link, which is the existing "new link with a reason" flow.
- Automatic notification to staff. The customer sends the WhatsApp update, and the admin order page shows the addition.

## Tests

`tests/Feature/OrderAdditionTest.php` covers merging and totals, double submit, the admin view, verification from another device, the lookup rate limit, the confirmed-order and cutoff blockers, price tampering, line limits and outlet prices.
