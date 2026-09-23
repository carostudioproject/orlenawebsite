# Public website migration — 23 September 2026

User-approved first milestone: migrate the existing public website; ordering and dashboard follow later.

## Source inventory

Read-only source: adjacent `Orlenalycious` Angular 16 checkout. No source files modified.

| Existing URL | New route | Content |
| --- | --- | --- |
| `/` | `/` | 7 hero slides, Brand Mission, 6 Baked Goods categories, 5 outlets, 4 collaborations, 3 journal cards |
| `/about` | `/about` | About Orlena, Our Story, outlet slider |
| `/our-story` | 301 to `/about` | Permanent compatibility redirect |
| `/blog` | `/blog` | Full existing listing |
| `/blog/:slug` | `/blog/{slug}` | All 3 complete articles; unknown slugs return HTTP 404 |

Shared: existing logo, footer, Instagram/TikTok URLs, floating WhatsApp `6282145809558`. Anchors: `baked-goods`, `outlet`, `collaboration`, `blog`, `brand-mission`, and About `outlets`.

Copied 27 referenced images without recompression or cropping; retained bundled fonts. Article/outlet JSON is consumed by Vue and Laravel so SEO, sitemap, listing, and detail share the same source. Angular template checksums and normalized copy checksums live under `tests/fixtures`.

Splide: original breakpoints, spacing, per-page counts, drag/snap, and hero looping retained. Instances are destroyed on unmount. Sliders support focused arrow-key navigation. Mobile navigation uses a native modal dialog with focus containment, Escape dismissal, trigger focus restoration, and body scroll lock.

## Differences and decisions

- Best Sellers, Brownies Tart, Ice Cream, and a graphical story timeline are mentioned in planning documents but absent from active source templates. They were not reconstructed from unused assets.
- Hero and Brand Mission remain pending client approval according to the supplied documents. Blog approval is also not established by migration.
- The mobile menu follows the required Almond background; the source CSS used Cream.
- Category cards with `href="#"` are now non-interactive cards instead of jumping to the page top. No product routes or copy were invented.
- Missing homepage H1 is provided as a visually hidden `Orlena` heading. Existing visible copy is unchanged.
- Jinglers path casing/directory repaired; Aileron explicitly loaded from supplied font files. Font usage rights still need owner confirmation before release.
- Bootstrap runtime/layout dependency removed. Tailwind provides new layout utilities; authored brand CSS is retained for migration parity, including legacy specificity rules. Further CSS cleanup should follow visual approval.
- Meta Pixel source retained, disabled locally/staging with `META_PIXEL_ENABLED=false`; enable only for the production environment using the existing tracking configuration.
- Laravel 12 selected for the available PHP 8.2.12 runtime. Confirm Hostinger PHP before release and evaluate Laravel 13/PHP 8.3+ then. No host configuration was changed.
- MySQL is the configured database. Public pages need no database queries; file session/cache allow local review before database provisioning. The staff milestone must enable database sessions after migrations.

## Verification and remaining release gates

Automated: frontend typecheck/build/lint, full-copy comparison, Linux-sensitive image path checks, public-route tests, article data tests, 404/redirect checks, server-rendered SEO metadata, sitemap, and staging noindex behavior.

HTTP smoke checks: homepage, blog article, and a locally served font return 200.

**Not yet verified:** rendered visual parity and interaction checks at 360, 390, 430, 768, 1024, and 1440 px. The session browser connector reported `No browser is available`; no screenshot baseline or browser-based QA was possible. These are required before declaring parity or releasing.

The asset set remains about 114 MB because existing photography bytes were preserved. No image optimization was performed without visual comparison. Public content is Vue-rendered; important metadata is included in the initial server response, but crawl/render evaluation remains a release gate without SSR.

No production deployment, database migration, payment integration, or Erzap endpoint was performed. Angular remains the rollback source.
