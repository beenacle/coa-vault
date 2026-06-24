# Changelog

Notable changes to COA Vault. Each version is a GitHub Release; the same log (released
versions) is in `readme.txt` for WordPress.

## 0.2.2
- New: a PDF certificate now shows a **"View full report (PDF)"** link beneath its preview, so a
  multi-page COA is fully reachable — the preview shows page 1, and the link opens the complete file
  (e.g. a second page with endotoxin / sterility results) in the browser's PDF viewer.
- Change: editors previewing a **draft** product now see its certificates by default. The public
  still only sees COAs on published products — this just lets a logged-in editor preview a draft
  without extra setup (the default is filterable via `coa_vault_published_only_default`).
- Change: the Settings page's two storefront-display checkboxes are now a single, clearer **3-way
  choice** — automatic placement, manual (shortcode / block), or off. No behaviour change; the same
  underlying options are written, and the previously confusing "off + automatic" dead state is gone.

## 0.2.1
- New: a plugin **icon**, shown on the Plugins screen and the update / "View details" modal.
- New: the Anthropic API key field (COA → Settings) shows a **masked preview** when a key is saved,
  so you can confirm a key is set (and which one) without exposing it.
- Change: the catalog archive (`[coa_vault all="true"]`) is now a native single-open **accordion**
  (one product open at a time, collapsed by default), and the bundled frontend ships a cleaner,
  lighter default style. No JavaScript.
- Fix: whole-number purity/mass values rendered incorrectly on the storefront (a 10 mg mass showed
  as "1 mg", 100% as "1%", a 0 reading as blank). They now display correctly.
- Fix: size normalization now handles thousands separators ("1,000mg" → 1000mg) and leading-dot
  decimals (".5mg" ≡ "0.5mg"), so a certificate always resolves to the right size / variation.
- Fix: on multisite networks, uninstall now cleans every site's COA tables, options and stored API
  key — previously only the main site was cleaned.
- Fix: the "View details" changelog now shows the release notes instead of a bare compare link.

## 0.2.0
- New: a **Settings** page (COA → Settings) for the plugin's display and data options —
  storefront display, automatic product-page placement, and whether to delete COA data when
  the plugin is uninstalled. These previously existed only as database options / filters.
- New: **Scan / import certificate** on the product COA box. Drop a certificate image or PDF:
  its QR code is read in the browser to fill the lab and verification link, the file is added to
  the Media Library, and a new COA is pre-filled for review — including matching the certificate's
  labelled size to the product's variation ("Applies to") — never saved automatically. With an
  optional Anthropic API key (a setting, or the `COA_VAULT_ANTHROPIC_KEY` constant) it also reads
  the batch, purity, mass and analysis date off the document (model overridable via
  `coa_vault_claude_model`); without a key it attaches the file and reads the QR and you enter the
  figures. Blend / multi-component certificates are handled — each component's mass is pre-filled
  as an extra characteristic. Where a result is measured several times (triplicate samples), the
  values are averaged to one representative figure (computed server-side, not by the model) and
  never duplicated into extra rows. Non-mg quantities (mL, mcg, IU) keep their real unit, and a
  declared label size is never mistaken for a measured mass.
- New: the product COA box uses one native media zone — **drag a certificate, Upload, or pick from
  the Media Library** — that attaches the file and reads it in a single step (replacing the
  separate "Scan" and "Select report" buttons). Once attached it shows a thumbnail + filename with
  Replace / Remove; the report URL and verify link moved into a collapsed **Advanced** section.
- Admin polish: the first COA submenu is now **All COAs** (was a second "COA"), and the Settings
  page wording makes clear that "Storefront display" is the master switch and "Automatic placement"
  depends on it.
- The API key is read from the `COA_VAULT_ANTHROPIC_KEY` constant first (kept out of the database)
  and is always purged on uninstall, regardless of the data-retention setting.

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
