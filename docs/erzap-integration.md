# Erzap integration

Scope for now: **every paid order is sent to Erzap** as a sales order through the OLZAP API
(`POST {ERZAP_BASE_URL}/apis/simpan_pesanan_penjualan`). Stock, product and status callbacks from Erzap
(`/web_service/...` URLs in the OLZAP document) are not built yet.

## Flow

1. A Midtrans payment becomes **paid** → one row is added to `integration_syncs` (never twice per order).
2. `php artisan erzap:sync` (scheduled every 5 minutes) sends due rows:
   - settings missing → `waiting_config` (the log names the missing `.env` keys)
   - outlet without an Erzap outlet ID (and no default) or a product without a barcode → `needs_mapping`, retried after Mapping is saved
   - Erzap answers `status: "0"`, an HTTP error or times out → `failed`, retried after 5, 15, 60, 180 minutes (max 5), then only via **Resend**
   - Erzap answers `status: "1"` → `synced`
3. Orders cancelled or refunded after payment are **not** sent again or voided; correct them in Erzap by hand.

## Request mapping (`shopping_carts`)

| Erzap field | From the website |
|---|---|
| `kode` | Order Code |
| `nama`, `telepon`, `email` | Customer name, WhatsApp, email |
| `alamat`, `alamat_pengiriman` | Delivery address (empty for pickup) |
| `tempat_penjemputan` | Pickup outlet name (pickup only) |
| `pelanggan_ekspedisi` | `GOJEK/GRAB` or `PICKUP` |
| `ongkos_kirim` | Delivery fee (delivery only) |
| `total_pesanan` | Product subtotal |
| `total_pembayaran` | Amount paid via Midtrans |
| `konfirmasi_dari_bank`, `konfirmasi_tanggal_bayar`, `pelanggan_payment_channel` | `Midtrans`, paid time (WITA), e.g. `MIDTRANS-QRIS` |
| `informasi_tambahan_text` | Pickup/delivery, PO date and time, payment method, customer note |
| `idoutlet_penerima_pesanan_online_erzap` | Outlet's Erzap outlet ID (Mapping), else `ERZAP_DEFAULT_OUTLET_ID` |
| `iduser_sales_penerima_pesanan_online_erzap` | `ERZAP_SALES_USER_ID` |
| `token_erzap` | `ERZAP_TOKEN` (added when sending, never stored in the log) |
| `shopping_cart_details[]` | `barcode_produk` (product barcode), `harga_satuan`, `jumlah` |

## Setup

1. Get from Erzap: server address (e.g. `https://domain_erzap:4443`), `token_erzap`, receiving outlet ID(s) and sales user ID.
2. Fill `.env`: `ERZAP_BASE_URL`, `ERZAP_TOKEN`, `ERZAP_SALES_USER_ID`, optionally `ERZAP_DEFAULT_OUTLET_ID`, then `ERZAP_ENABLED=true`.
3. Dashboard → **Erzap integration → Outlet & product mapping**: Erzap outlet ID per outlet (or rely on the default) and the **Erzap barcode for every product sold on the website, including hampers**.
4. Pay one test order and check the log shows **Sent** and the order appears in Erzap.

## Open questions for Erzap

- Does the order arrive as a finished (paid) sale or as a sales order to process in Erzap?
- Is a repeated `kode` rejected (protection against duplicates)?
- Exact request format (JSON body, `Content-Type`) and whether port 4443 is required; shared hosting may block outgoing ports other than 80/443.
