# Changelog

Notable changes to COA Vault. Each version is a GitHub Release; the same log (released
versions) is in `readme.txt` for WordPress.

## 0.1.6
- Security: public REST reads (`/products/{id}/coas`, `/resolve`, `/coas`, `/coas/{id}`) now
  only return COAs for **published** products; signed-in editors (`edit_products`) still see
  drafts. Previously a draft/pending product's certificate data could be read anonymously.
- Fix: `Normalize::size_token()` no longer emits "Undefined array key" warnings and correctly
  defaults to `mg` for a number followed by an unrecognized unit (e.g. `"30 caps"`).
- Fix: auto-inject no longer duplicates a panel already placed by `[coa_vault]` or the block —
  the shared renderer now signals placement, so the de-dup guard actually fires.
- Fix: the `coa-vault/panel` block loads its styles/script (and REST base/nonce) on non-product
  pages, so per-variation swapping works wherever the block is placed.
- Fix: selecting "Whole product (all sizes)" in the product editor now records the all-sizes
  flag, so the "All sizes" label shows consistently.
- Hardening: admin COA characteristic fields are HTML-escaped when re-rendered for editing;
  multisite new-site provisioning loads the plugin API before use; REST `create` validates the
  product id.

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
