=== COA Vault — Lab Certificates for WooCommerce ===
Contributors: beenacle
Tags: woocommerce, certificate of analysis, coa, lab results, certificate
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.2.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Manage Certificates of Analysis (COA / lab results) for WooCommerce products — custom-table storage, simple + variable products, and a REST API.

== Description ==

COA Vault gives WooCommerce a proper home for Certificate of Analysis (lab result)
data. Instead of stuffing certificates into ad-hoc custom fields, it stores them in
dedicated database tables and renders them on the storefront — with first-class
support for **per-size / per-variation** certificates.

**What it does**

* Stores COAs in custom tables (records + characteristics + size aliases), not postmeta.
* Supports simple **and** variable products: attach a COA to the whole product, to a
  size, or to a specific variation. The storefront resolves the right COA as the
  shopper picks a size.
* Each COA holds a report file/image, an optional lab verification link, batch,
  analysis date, lab, purity/mass, and free-form characteristics.
* Displays COAs via a block, the `[coa_vault]` shortcode (including
  `[coa_vault all="true"]` for a catalog-wide archive), or automatic placement on the
  product page — or hand all of that off to your own theme/page builder while still
  reading from the same records.
* Exposes a REST API under `coa-vault/v1` for headless / custom displays.
* Admin: a per-product COA editor and a catalog-wide COA list, plus a COA-coverage
  column and a "No COA" filter on the Products screen so you can spot gaps at a glance.
* Settings page (COA → Settings) for the display and data options.
* Scan / import: drop a certificate image or PDF on a product to read its QR verify link
  and pre-fill a new COA for review — optionally reading the figures off the document with
  AI (see the FAQ).
* HPOS (High-Performance Order Storage) compatible.

**Requirements**

* WooCommerce 8.0+ (the plugin will not run without WooCommerce active).
* PHP 8.1+.

**Updates**

COA Vault delivers its own updates from GitHub Releases (no WordPress.org dependency)
via the `Update URI` header — new versions appear under Dashboard → Updates like any
other plugin.

== Installation ==

1. Upload the `coa-vault` folder to `/wp-content/plugins/`, or install the zip via
   Plugins → Add New → Upload Plugin.
2. Activate **COA Vault** through the Plugins screen. WooCommerce must be active.
3. The COA tables are created automatically on activation. Manage certificates from a
   product's edit screen or from the **COA Vault** admin menu.

== Frequently Asked Questions ==

= Does it support variable products? =

Yes. A COA can apply to the whole product, to a size, or to a specific variation. On
the storefront the matching COA resolves as the shopper selects a size.

= Can I use my own design instead of the bundled display? =

Yes. Set the `coa_vault_frontend` option to `0` (or use the `coa_vault_frontend`
filter) to disable the bundled renderers and supply your own markup. The REST API
stays available so your template can still resolve COAs.

= What happens to my data when I uninstall? =

Nothing, by default — COA data is compliance-relevant, so the tables are preserved on
delete. They are only dropped if you explicitly opt in via the
`coa_vault_drop_data_on_uninstall` option.

= How do I import existing certificates? =

A separate, optional **COA Vault — Migration** companion handles one-time imports from
legacy schemas. The core plugin has no dependency on it.

= How does "Scan / import certificate" work? =

On a product's COA box, click **Scan / import certificate** and choose the lab's
certificate (image or PDF). The QR code is read in your browser to fill the lab and
verification link, the file is added to the Media Library, and a new COA is pre-filled for
you to review and save — nothing is saved automatically. If an Anthropic API key is set
(COA → Settings, or the `COA_VAULT_ANTHROPIC_KEY` constant), it also reads the batch,
purity, mass and analysis date off the document; without a key you fill those in yourself.

= Is the AI reading required, and is my data sent anywhere? =

It is optional and off until you add a key. When enabled, the certificate is sent to the
Anthropic API to extract the fields (a fraction of a cent per certificate). Certificates
are public lab documents, not customer data. Defining `COA_VAULT_ANTHROPIC_KEY` in
`wp-config.php` keeps the key out of the database.

== Changelog ==

= 0.2.2 =
* New: a PDF certificate shows a "View full report (PDF)" link beneath its preview, so a multi-page
  COA is fully reachable (the preview shows page 1; the link opens the complete file).
* Change: editors previewing a draft product now see its certificates by default; the public still
  only sees COAs on published products. Filterable via coa_vault_published_only_default.

= 0.2.1 =
* New: a plugin icon, shown on the Plugins screen and the update / "View details" modal.
* New: the Anthropic API key field (COA → Settings) shows a masked preview when a key is saved.
* Change: the catalog archive ([coa_vault all="true"]) is now a native single-open accordion
  (collapsed by default), with a cleaner, lighter default style. No JavaScript.
* Fix: whole-number purity/mass rendered incorrectly on the storefront (a 10 mg mass showed as
  "1 mg", 100% as "1%"). Now shown correctly.
* Fix: size normalization handles thousands separators ("1,000mg") and leading-dot decimals
  (".5mg" = "0.5mg"), so a certificate resolves to the right size / variation.
* Fix: on multisite, uninstall now cleans every site's tables, options and stored API key.
* Fix: the changelog modal now shows the release notes instead of a bare compare link.

= 0.2.0 =
* New: a **Settings** page (COA → Settings) for the display and data options — storefront
  display, automatic product-page placement, and whether to delete COA data on uninstall.
* New: **Scan / import certificate** on the product COA box — one media zone where you drag a
  certificate, upload, or pick from the Media Library; it attaches the file and reads it in a
  single step, then shows a thumbnail with Replace / Remove. The QR fills the lab and verify link;
  with an optional Anthropic API key it also reads the batch, purity, mass and date (blends pre-fill
  each component's mass; triplicate results are averaged to one figure). Without a key it attaches
  the file and reads the QR, and you enter the figures. A new COA is always pre-filled for review,
  never saved automatically.
* Admin: the first COA submenu is now "All COAs", and the Settings wording is clearer about the
  storefront display master switch.

= 0.1.6 =
* Security: public REST reads now return certificates only for published products; signed-in
  editors still see drafts. Previously a draft product's COA data could be read anonymously.
* Fix: size-token normalization no longer warns (and defaults to mg) for a number followed by
  an unrecognized unit such as "30 caps".
* Fix: auto-injected product-page panel no longer duplicates one already placed by the
  `[coa_vault]` shortcode or the COA Panel block.
* Fix: the COA Panel block loads its styles/script wherever it is used, not only on product pages.
* Fix: choosing "Whole product (all sizes)" now shows the "All sizes" label consistently.
* Hardening: admin characteristic fields are escaped on edit; REST create validates the product id.

= 0.1.5 =
* Change: one display shortcode — `[coa_vault]` (the `[coa]` and `[cf_coa]` aliases were removed).
* New: `[coa_vault all="true"]` renders a catalog archive of every published product's COAs.
* Native rendering: images and PDFs both render via `wp_get_attachment_image()` (PDFs show their
  generated first-page preview); semantic, theme-styled markup with structure-only CSS.
* Frontend assets now load wherever `[coa_vault]` is used, not only on product pages.

= 0.1.4 =
* New: `coa_vault_frontend` opt-out switch — disable all bundled on-storefront COA
  renderers and supply your own markup. The REST API stays available either way.
* New: COA-coverage column and a "No COA" filter on the Products admin screen, to find
  products that are missing a certificate.
* Fix: REST write endpoints (`POST/PUT/PATCH /coa-vault/v1/coas`) no longer return
  internal migration metadata — they now match the public read shape.
* Fix: uninstall now also removes the `coa_vault_frontend` and `coa_vault_autoinject`
  options.
* Tidy: well-formed admin lab datalist; removed an unused legacy option. Tested up to
  WordPress 7.0.

= 0.1.3 =
* Fix: admin COA list pagination reported a fake, growing total instead of the real
  row count. The total is now correct and constant across pages.

= 0.1.2 =
* Labs: added AccuMark Labs and BT Lab Testing; dropped MZ Biolabs; shortened
  "TrustPointe Analytics" to "TrustPointe".
* Reports: image-vs-file is decided by the attachment's real MIME type.
* Admin: the COA list shows the lab verify link; removed the low-value Source column.

= 0.1.1 =
* First public release of the distribution-oriented core, with a GitHub Releases
  self-updater. Legacy import lives in a separate companion plugin.

== Upgrade Notice ==

= 0.2.2 =
Adds a "View full report (PDF)" link so multi-page PDF certificates are fully viewable, and lets
editors preview a draft product's COAs. No action needed; public visibility is unchanged.

= 0.2.1 =
Fixes a storefront display bug where whole-number purity/mass values showed incorrectly (e.g. a
10 mg mass as "1 mg"); adds a plugin icon and a native accordion for the COA archive. Recommended.

= 0.2.0 =
Adds a Settings page and an optional "Scan / import certificate" tool (QR + AI reading). No
action needed — existing certificates and behaviour are unchanged, and the AI reading stays
off until you add an API key.

= 0.1.6 =
Security fix: public REST endpoints no longer expose certificates for unpublished products.
Recommended for all sites that use the REST API or have draft products.

= 0.1.5 =
The display shortcode is now just `[coa_vault]` — update any `[coa]` or `[cf_coa]` references.
Certificates (including PDFs) now render as native, theme-styled content.

= 0.1.4 =
Security: REST write responses no longer expose internal migration metadata. Adds a
"supply your own display" switch and a COA-coverage view on the Products screen.
