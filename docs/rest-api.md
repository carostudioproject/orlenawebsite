# REST API (v1)

Base URL: `{APP_URL}/api/v1`. JSON only, rate limited to 60 requests per minute per token (or per IP for public endpoints). Times are WITA (`Asia/Makassar`), amounts are whole rupiah.

## Public (no token)

The same data the order form already shows.

| Method | Path | Returns |
|---|---|---|
| GET | `/categories` | Active categories: `id`, `name`, `image` |
| GET | `/outlets` | Outlets taking PO: `id`, `code`, `name`, `address`, `maps_url`, `image`, `is_delivery_hub` |
| GET | `/products?outlet_id=` | Orderable products (active, on sale). Prices follow the outlet when given. Fields: `id`, `name`, `category_id`, `variant`, `price`, `is_hamper`, `hamper_items`, `sale_ends_on`, `image`, `description` |
| GET | `/schedule` | `earliest_date`, `cutoff_time`, `cutoff_label`, `closed_weekdays` (0 = Sunday), `closed_dates` |

## Protected (`Authorization: Bearer <token>`)

Tokens come from `API_TOKENS` in `.env` (comma separated, use long random strings, one per integration). With no token configured these endpoints answer `503`; a wrong token gets `401`.

| Method | Path | Notes |
|---|---|---|
| GET | `/orders` | Paginated (`per_page` ≤ 100). Filters: `status`, `payment_status`, `date_from`/`date_to` (requested date), `updated_since` |
| GET | `/orders/{order_code}` | One order with items and payment attempts |
| GET | `/reports/sales?from=&to=&outlet_id=&product_id=&status=` | Same figures as the dashboard report (top 50 products) |

Order responses include the customer's name only. WhatsApp number, email and delivery address are never returned.
