=== COA Vault — Lab Certificates for WooCommerce ===
Contributors: beenacle
Tags: woocommerce, certificate of analysis, coa, lab results, certificate
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.1.5
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

== Changelog ==

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

= 0.1.5 =
The display shortcode is now just `[coa_vault]` — update any `[coa]` or `[cf_coa]` references.
Certificates (including PDFs) now render as native, theme-styled content.

= 0.1.4 =
Security: REST write responses no longer expose internal migration metadata. Adds a
"supply your own display" switch and a COA-coverage view on the Products screen.
