# Owner product table import — 23 September 2026

Imported the table supplied in chat into `resources/content/product-catalog.json` and the local catalog database:

- FULLSIZE BROWNIES: 14 products.
- HALFSIZE BROWNIES: 14 products.
- SAUCE: 8 products.

Prices are whole rupiah (`80.000` becomes `80000`). Fullsize and halfsize are distinct sellable records, with the category identifying size. Internal SKUs use `ORL-FB-001…014`, `ORL-HB-001…014`, and `ORL-SC-001…008`; these are local identifiers, not claimed Erzap mappings.

Names and descriptions are preserved verbatim after trimming table whitespace. In particular `Bluberry Cheese`, `Nutella Ovolamaltine`, `cheedar`, and the apparent name/topping mismatches remain as provided. Owner review is needed before correcting them. Empty sauce descriptions and the `<br><br>` placeholder become `null`, not visible HTML.

Products start inactive for PO until enabled by Admin. No outlet price overrides or stock values were invented. Existing marketing categories/outlets remain intact. The public website has not been changed by this import.

`php artisan db:seed --class=ProductCatalogSeeder` imports atomically using stable SKU keys and records system-actor audit entries. Re-running it never overwrites later dashboard changes or duplicates products. New installs include it through DatabaseSeeder. Future price changes should be applied through the dashboard or an explicitly reviewed update migration; editing the JSON alone does not overwrite existing rows.

## Updates from Erzap

Later catalog updates come from Erzap: **Products → Import from Erzap** (see [Erzap integration](erzap-integration.md)). The first import matches these 36 products by name and size and fills their barcode and Erzap product code; photos and descriptions stay.

## Hampers and boxes with chosen contents

A product can let the customer choose what goes inside (**Products → edit → Contents and sale period**, or the Excel columns `Tipe`, `Jumlah Isi`, `Isi Dari`, `Harga Isi` of `docs/templates/produk-orlena.xlsx`):

| Type | Contents | Price |
|---|---|---|
| **Hampers** | 1 item from its "Isi Dari" list, usually the same size (e.g. Hampers Galungan Fullsize + one Fullsize brownies) | hampers price + the item's normal price |
| **Box** | `Jumlah Isi` items (e.g. 4 for Mini Size 2x2), repeats allowed | box price; contents Rp 0 |

- Each size is its own product and card on the order form. A regular product also offers the active hampers that allow it ("Jadikan hampers?"), so hampers are reachable from the product and from the Hampers category.
- Each unit chooses its own contents; the same hampers with different contents become separate cart lines.
- Contents are stored as order lines under the hampers/box line (their own name, barcode and price) and sent to Erzap as separate products; the same barcode at the same price is one Erzap line.
- The "Isi Dari" list is kept by hand: new products are never added to a hampers or box automatically. Box contents (e.g. Mini flavours at Rp 0) may stay inactive so they are not sold alone.
- A fixed package (set contents) remains a regular product with the "Fixed hampers package" text.
