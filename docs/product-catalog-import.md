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
