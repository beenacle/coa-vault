# Changelog

All notable changes to **COA Vault**. Releases are published as GitHub Releases and
delivered to sites through the built-in self-updater (Dashboard → Updates).

## 0.1.4

- **New — `coa_vault_frontend` opt-out switch.** A site can now disable all of the
  bundled on-storefront COA renderers (variation injector, the
  `[coa]`/`[coa_vault]`/`[cf_coa]` shortcodes, the block, auto-inject and the frontend
  assets) and supply its own markup fed from the same records. Set the
  `coa_vault_frontend` option to `'0'`, or use the `coa_vault_frontend` filter. The
  REST API stays registered either way, so a custom display can still resolve COAs.
- **New — COA coverage on the Products screen.** A "COAs" column shows how many live
  certificates each product has, and a "No COA" view filter lists published products
  that have none — so coverage gaps are easy to spot.
- **Fix — REST write responses no longer leak internal migration metadata.**
  `POST /coa-vault/v1/coas` and `PUT|PATCH /coa-vault/v1/coas/{id}` now return the same
  public shape as the read endpoints (the internal `source` block is stripped).
- **Fix — uninstall cleanup.** Removing the plugin (with data-drop opted in) now also
  deletes the `coa_vault_frontend` and `coa_vault_autoinject` options.
- **Tidy.** Added a `Requires Plugins: woocommerce` header, a wp.org-format
  `readme.txt`, well-formed admin lab `<datalist>` options, and removed an unused
  legacy option. Tested up to WordPress 7.0.

## 0.1.3

- **Fix — admin COA list pagination.** The catalog list reported a fake, growing total
  (`page*30+1`), so "X items / of N" changed on every page. `CoaRepository::count()`
  now shares `query()`'s WHERE/params, so the total is correct and constant.

## 0.1.2

- **Labs.** Added AccuMark Labs and BT Lab Testing; dropped MZ Biolabs; shortened
  "TrustPointe Analytics" to "TrustPointe".
- **Reports.** Image-vs-file is decided by the attachment's real MIME type, so a PDF
  stored in an ACF image field renders as a downloadable file instead of a broken image.
- **Admin.** The COA list Report column shows the lab verify link; the low-value Source
  column was removed.

## 0.1.1

- **New — GitHub Releases self-updater.** Sites receive updates from Dashboard → Updates
  without WordPress.org, via the `Update URI` header and `src/Update/GitHubUpdater.php`.
  First public release of the distribution-oriented core (migration code lives in a
  separate companion plugin).
