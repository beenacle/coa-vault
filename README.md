# COA Vault

Certificate of Analysis (COA) management for WooCommerce — by **Beenacle**.

Plugin-owned custom tables, simple **and** variable product support, multi-COA per
size/variation, a frontend display (block / shortcode / auto-inject with per-variation
swap), an admin editor, and a REST API.

> **Status: v0.1.5.** Core is self-contained and distribution-oriented. Legacy data
> import lives in a separate, optional **COA Vault — Migration** companion plugin, so
> the shippable core carries no site-specific code. See [CHANGELOG.md](CHANGELOG.md).

## Requirements
- PHP 8.1+
- WordPress 6.4+ / WooCommerce 8.0+ (HPOS-compatible; declares compatibility)

## Architecture
```
coa-vault.php                 Bootstrap: header, HPOS declare, autoloader, activation
src/Plugin.php                Container; wires data + REST + frontend + admin (exposes records()/aliases())
src/Support/                  Normalize · Vocab · Report · Hash  (one source of truth)
src/Data/                     Schema (3 tables) · Installer · DTOs · Repositories · SizeAliasBuilder · RecordInput
src/Rest/                     RestServiceProvider · ProductCoaController · CoaController · RecordSchema
src/Frontend/                 RenderService · VariationInjector · Shortcode · Block · AutoInject · Assets
src/Admin/                    ProductPanel · BatchController · AdminRenderer · ListTable · AdminMenu · Assets
assets/ · blocks/             frontend + admin JS/CSS, coa-vault/panel block
```

## REST API (`coa-vault/v1`)
- `GET /products/{id}/coas[?size=&latest=]` — all COAs for a product
- `GET /products/{id}/resolve?variation_id=&size=` — the variation→COA matching contract (returns records + rendered html)
- `GET /coas?lab=&purity_max=&site=&page=` — catalog-wide reporting
- `GET /coas/{id}` · `POST/PUT/DELETE /coas` (writes require `edit_products`)

## Display
- **Block:** `coa-vault/panel`
- **Shortcode:** `[coa_vault]` (current product), `[coa_vault product_id="N"]`, or `[coa_vault all="true"]` for a catalog archive of every published product's COAs.
- **Auto-inject:** opt-out via `coa_vault_autoinject` option/filter; placement on `woocommerce_single_product_summary`.
- Selecting a variation lazy-fetches that size's COAs via REST (no page bloat).

### Tables
- `wp_coa_vault_records` — one row per COA batch (`product_id` + nullable `variation_id` + normalized `size_token`).
- `wp_coa_vault_characteristics` — normalized purity/mass/custom measurements.
- `wp_coa_vault_size_aliases` — per-product size_token → real variation/term map (exact hybrid matching).

## Usage
Activate the plugin — tables are created on activation and kept current on update
(`dbDelta`, versioned via `coa_vault_db_version`). Add COAs from the product editor's
**Certificates of Analysis** box, or place `[coa_vault]` / the `coa-vault/panel` block.

## Decisions baked in
- **Storage:** custom tables (not ACF/CPT/meta).
- **Binding:** hybrid `product_id` + nullable `variation_id` + `size_token`; resolution ladder variation → size → product-level.
- **Scope:** core COA/lab data; certificate **file** and lab **verify link** kept as distinct fields.
- **Labs:** free-text with autocomplete; custom labs get a slug so they're filterable; standard set in `Support/Vocab.php`.
- **Media:** local uploads.

## Updates
COA Vault updates itself from this repo's **GitHub Releases** — it is not on the
WordPress.org directory. Each site checks `releases/latest` (cached 6h to respect
GitHub's rate limit) and offers new versions from **Dashboard → Updates**, just like
any other plugin. No tokens or secrets are needed for a public repo. The logic lives
in `src/Update/GitHubUpdater.php`; the `Update URI` header keeps any same-slug
WordPress.org plugin from hijacking the update.

## Releasing
Cutting a release is one tagged commit — CI does the rest:

1. Bump the version in **two** places: the `Version:` header and `COA_VAULT_VERSION`
   in `coa-vault.php` (keep them equal; the constant is the runtime source of truth).
2. Commit, then tag and push:
   ```bash
   git commit -am "Release vX.Y.Z"
   git tag vX.Y.Z && git push origin main --tags
   ```
3. `.github/workflows/release.yml` builds `coa-vault.zip` (dev files stripped via
   `.distignore`, the tag version stamped into the header as a safety net) and
   publishes it as the release asset.

Within ~6h (or immediately via **Dashboard → Updates → Check again**) every site sees
the new version and can update in one click. Use `vX.Y.Z-rc1`-style pre-release tags
for testing — the updater only follows the *latest* full (non-prerelease) release.

## Migration (separate companion)
Importing legacy ACF / `lab-result` CPT / native `coa` data is handled by a separate
**COA Vault — Migration** companion plugin. Install it only where a one-time import is
needed; the core plugin has no dependency on it.

## Notes for static analysis
Files are namespaced; unqualified calls to WordPress/WooCommerce functions resolve
to the global namespace at runtime (standard for WP plugins). Load WP/WooCommerce
stubs (e.g. `php-stubs/wordpress-stubs`, `php-stubs/woocommerce-stubs`) in your IDE
to silence "undefined function" notices.
