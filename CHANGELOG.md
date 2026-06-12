# Changelog

Notable changes to COA Vault. Each version is a GitHub Release; the same log (released
versions) is in `readme.txt` for WordPress.

## 0.1.5
- One shortcode: consolidated to `[coa_vault]` (removed the `[coa]` / `[cf_coa]` aliases).
- Catalog archive: `[coa_vault all="true"]` lists every published product's COAs.
- Native rendering: images and PDFs render via `wp_get_attachment_image()` (PDFs show
  their generated preview); semantic, theme-styled markup with structure-only CSS.
- Frontend assets load wherever `[coa_vault]` is used, not only on product pages.

## 0.1.4
- New: `coa_vault_frontend` opt-out switch — use your own display; the REST API stays available.
- New: COA-coverage column + "No COA" filter on the Products screen.
- Fix: REST write responses no longer return internal `source` metadata.
- Fix: uninstall removes the `coa_vault_frontend` / `coa_vault_autoinject` options.
- Added a `Requires Plugins` header and `readme.txt`; tested up to WordPress 7.0.

## 0.1.3
- Fix: admin COA list pagination total is now correct and constant across pages.

## 0.1.2
- Labs: added AccuMark Labs and BT Lab Testing; dropped MZ Biolabs; shortened "TrustPointe".
- Reports: image-vs-file is decided by the attachment's real MIME type.
- Admin: COA list shows the lab verify link; removed the Source column.

## 0.1.1
- First public release: distribution-oriented core + GitHub Releases self-updater.
