# Changelog

Notable changes to COA Vault. Each version is a GitHub Release; the same log (released
versions) is in `readme.txt` for WordPress.

## 0.3.2
- Change: certificates are now read with **Claude Haiku 5.5** (`claude-haiku-5-5`) instead of
  Claude Haiku 4.5. It reads better on Anthropic's benchmarks and costs about a tenth as much per
  token ($0.10 / $0.50 per million input / output tokens for a certificate-sized request, against
  $1 / $5).
- Change: the read request now leaves room for the model's thinking. Haiku 5.5 thinks by default
  and its thinking counts toward the output cap, so the 4096-token cap tuned for Haiku 4.5 could
  have cut a dense certificate off mid-read — reported as "nothing could be read". The cap is now
  16,000 tokens, and the request asks for `low` effort, the level the docs recommend for simple
  extraction. Override with the new `coa_vault_claude_effort` filter (`low` | `medium` | `high` |
  `xhigh` | `max`, or `''` to use the model's default). Effort is never sent to Claude Haiku 4.5,
  which rejects it, so a site pinned to that model keeps working.
- To stay on Haiku 4.5, set `define('COA_VAULT_CLAUDE_MODEL', 'claude-haiku-4-5');` in
  `wp-config.php` or use the `coa_vault_claude_model` filter.

## 0.3.1
- Fix (storefront): a batch row for a specific size now shows that size, named as in the product's size picker (25 g · Lab · date). On the archive, or before a size is picked, every size carries its own "Latest" tag, and without the size two "Latest" rows were ambiguous. The name comes from the certificate's own variation, else from the product's published variations; where none carries the size, or two name it differently, the stored size key is shown, as in the admin list.
- Fix (storefront): the COA archive ([coa_vault all="true"]) lists each product's sizes in the same order as the product page, shorter sizes first, so 5 g comes before 25 g.
- Fix (storefront): a COA saved for a specific size is no longer labelled "All sizes" when an old whole-product flag was left on it; the size wins, as in the admin list.
- Privacy: the AI reading request sends COA-Vault/0.3.1 as its user agent instead of WordPress's default, which includes the site address. Only the certificate file and a fixed instruction are sent.
- Docs: the optional AI reading is described precisely: only the scanned certificate file is sent to the Anthropic API, with a fixed instruction, and nothing else from the store; the file can contain anything printed on it, so review certificates before enabling it. The "public lab documents, not customer data" assurance is removed from the readme and the Settings screen. The readme's cost wording now matches Settings ("around a cent or less per certificate").
- Docs: the uninstall FAQ no longer calls COA data "compliance-relevant", and says an API key saved in Settings is always removed.

## 0.3.0
- **Fix (data correctness): an ambiguous certificate date is no longer guessed.** `06/04/2026`
  is June 4 to a US lab and 6 April to an EU one, and the parser silently assumed day-first —
  storing a date up to months off and mis-ordering which batch counts as "Latest". Such a date
  is now left unset, with the certificate's own text kept and shown in the product's COA list so
  you can correct it. Declare your labs' convention under **COA → Settings → Certificate date
  order** (or the `coa_vault_date_order` filter) to have them read automatically again.
- The scan / import flow says so too: a certificate date it reads but cannot resolve is reported
  in the review notice and carried onto the saved record, instead of the Date field just appearing
  blank. Picking a date clears the kept text; re-saving a record never discards it.
- New: unambiguous US dates now parse — `03/25/2026` is read correctly, as are `-` and `.`
  separators. ISO dates (`2026-06-04`, `20260604`) and dates picked in the editor are unaffected.
- New: characteristics can record the certificate's **stated limit and pass/fail verdict**
  (`spec_text` / `passed`), so a full-panel COA — Purity `(>98%)`, Endotoxin `(<5 EU/vial)`,
  pH `(6.0 - 8.0)` — keeps its reference column instead of losing it. Shown on the storefront
  beside each result and editable per row in the product COA box.
- Fix: a result or limit beginning with "<" (`<0.05`, `<5 EU/vial`) was stored HTML-encoded and
  then escaped again, so the storefront printed a literal `&lt;0.05`. Such values are now stored
  and shown as the lab printed them; existing rows are corrected on read, with no migration.
- New: Kovera Labs and Horizon Analytical added to the built-in lab list, including verify-link
  host inference.

## 0.2.4
- New: **bulk "Read data with AI"** on the All COAs screen. Select records whose attached
  certificate still has empty figures (typical after a migration that only carried the
  files), run the bulk action, and each certificate is read in turn with a progress bar —
  then ONE review table shows every proposed value for you to tick and apply. Strictly
  **fill-blanks-only**: only empty fields are ever written, checked again server-side, so
  a bad read can never overwrite good data. A per-row **Read data** link runs the same
  flow for a single record, and a notice on the list counts the records still missing
  figures. Requires an Anthropic key (COA → Settings).
- Change: a "Purity" or "Mass" row typed into the extra-characteristics repeater (in the
  column's own unit) is now folded into the headline Purity/Mass field on save instead of
  being stored twice; textual or off-unit values still stay as characteristics.
- Fix: **storefront variation swap on cached pages.** Full-page caches could serve a stale
  security token and silently break the per-variation COA swap; the swap now recovers by
  itself (and aborts stale requests, so fast size-switching can't show the wrong COA).
- Fix: **product editor reliability.** A failed save/delete (e.g. after the edit screen sat
  open overnight) now shows the real error instead of failing silently; replacing the file
  on an existing record updates THAT record instead of quietly creating a duplicate (the
  form now says which batch it is editing); "Remove" actually detaches the file; a
  double-click can't save twice; editing a record no longer discards stored textual
  purity/mass characteristics.
- Fix: hand-edited records are now owned by the store — a migration re-run no longer
  reverts or hides an admin's corrections.
- Fix: variable products whose COAs are all size-specific now show them on first load
  instead of "No certificates available"; a record whose Media-Library file was deleted
  falls back to its report URL; sizes sort numerically (5mg before 10mg).
- Fix: partial REST updates (PATCH) no longer blank the fields they omit; catalog REST
  responses carry standard pagination headers; the "Latest" flag is now correct on
  filtered/paginated reads.
- Fix: dense multi-analyte certificates no longer come back as "nothing read" (bigger AI
  read budget, and a truncated read is reported as a failure instead of an empty result).
- Dev: settings page shows "Settings saved."; sturdier install/upgrade on multisite and
  restricted databases; bundled jsQR now ships with its full Apache-2.0 license text.

## 0.2.3
- New: **re-read data from a certificate.** In the product COA editor, an attached
  certificate now has a **Re-read data** action that runs the AI reader over the file
  again and shows a review/diff — one tick per figure (batch, lab, date, purity, mass)
  that differs from what's saved — so you can backfill a blank field or correct a wrong
  one without retyping. Nothing changes until you tick it and click Apply, and it never
  touches the size, the attached file, or the verify link. Handy after a bulk import.
  Requires an Anthropic key (COA → Settings); off without one.
- New: **sortable catalog list.** The catalog-wide **All COAs** screen now sorts by
  Product, Lab, Date and Purity — one click on the column header — for scanning a whole
  catalog's certificates at a glance.
- New: **smarter scan / import.** Dropping a certificate now prefers the lab's real
  verification link and **ignores compound-reference links** (PubChem, NCBI, Wikipedia,
  ChemSpider, etc.) that some COAs print — so the "verify link" field no longer fills with
  a molecule-database URL. A file that contains **more than one certificate** (e.g. two
  size variants in one PDF) is flagged with a warning instead of silently reading only the
  first — you add the others by hand.
- New: **in-box scan progress.** While a dropped certificate is read, the upload zone shows
  a native spinner and locks, so a second click or drop can't fire a duplicate import.
- Change: the built-in lab suggestions were expanded and curated (Janoshik, Chromate,
  Krause Analytical, BT Lab Testing, TrustPointe, Freedom Diagnostics, MZ Biolabs, AccuMark
  Labs), with better host inference so a lab's link is recognized on scan. Any other lab
  still works as free text.

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
