# Roles, website content editing, and dashboard branding

## Decisions confirmed in this session

- The public menu no longer shows **Pre-order**. `/order` still works through a direct link. Re-add the entry in `AppHeader.vue` when the owner launches ordering.
- New roles: **Content Editor** and **Finance**, next to Admin and Staff.
- Content Editor can edit the blog and the homepage text, outlet cards, and brand collaborations.
- The dashboard UI follows the brand guideline.

## Permissions

| Role | Access |
|---|---|
| Admin | Everything, including team accounts and cancelling paid orders |
| Staff | Orders (review, payment, fulfillment) and read-only catalog |
| Finance | Orders and payments, read-only; no catalog, content, or accounts |
| Content Editor | Homepage content and blog only; no orders, customer data, or catalog |

Gates live in `AppServiceProvider` (`view-orders`, `review-orders`, `view-catalog`, `manage-catalog`, `manage-content`, `manage-users`, `cancel-paid-orders`). Every route checks them on the server. The `auth.can` Inertia prop only hides menu items and buttons.

## Content model

- Approved copy stays the default in `resources/content` (`home.json`, `outlets.json`, `collaborations.json`, `blogs.json`). Dashboard edits are stored as overrides in `content_entries`. **Kembalikan ke copy awal** deletes the override. The content parity test still checks the defaults against the Angular source.
- Blog posts live in `posts`. The migration imports the three approved articles in their original order. New posts are listed first. Drafts are hidden from the website, the homepage, and the sitemap.
- Articles are built from paragraph and heading blocks and rendered as escaped text, so no HTML is stored or run.
- Images are uploaded to `storage/app/public/content` (JPG, PNG, or WebP, max 4 MB) and served from `/storage/content/...`. Stored image paths must be a site asset or an earlier upload. Production needs `php artisan storage:link` once.
- Editable sections: hero slider (image + alt), homepage text, Brand Collaboration, and the About page (title, description, Our Story lead, paragraphs, closing, outlet title). Defaults are `hero.json`, `home.json`, `collaborations.json`, `about.json`.
- **Baked Goods cards are the active categories** from the **Kategori** menu (name + photo + Aktif), in the order set under **Urutan Baked Goods**. The migration copied the approved photos and order from `baked-goods.json` (still the parity default) and turned the product-only categories (FULLSIZE/HALFSIZE BROWNIES, SAUCE) off so the homepage is unchanged.
- The homepage journal shows the 5 newest published posts; `/blog` lists every published post.
- **Outlets have one record** in the **Outlet** menu: name, address, Maps link, photo, **Aktif** (operating and shown on the website) and **Bisa menerima PO** (default on). The order form lists outlets that are both. Website content only sets the card order (**Urutan outlet**). The migration copied the approved photos and order from `outlets.json` and kept each outlet's previous PO setting.
- Products have an optional photo. Products and outlets can be activated or deactivated straight from the list; a product needs a base price before it can be activated.
- All admin tables show 10 rows per page.

## Dashboard branding

- Brand tokens: Cream background, Chocolate text and primary buttons, Almond for the active menu item.
- Status badges use Matcha (done), Berry (stopped), Light Blue (info), and Rose (in progress). Every badge also shows a text label.
- Alerts use a coloured side bar with Chocolate text, so contrast stays readable.
- Headings use Neue Regrade and body text uses Aileron.

## Website visibility for outlets and categories

- **Aktif** (Outlet/Kategori menu) and **shown on the website** are separate. Outlet *Aktif* = operating (PO also needs *Bisa menerima PO*). Category *Aktif* = its products can be ordered; inactive categories hide their products from the order form.
- **Konten website → Outlet di website / Kategori di Baked Goods** chooses which existing records appear and in what order. **Tambah dari yang sudah ada** adds a record; the eye-slash button hides it again. Only records that are both chosen and active are rendered.
- New outlets and categories are not added to the website automatically. The catalog list shows a **Di website** badge for records currently chosen.
- The migration kept the website unchanged: records that were showing stay shown. Categories were all set back to active, because "active" used to mean "on the homepage".
