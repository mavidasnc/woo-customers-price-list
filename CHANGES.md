# Changelog

All notable changes to **WooCommerce Dynamic Price List Pro** are documented here.

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).
Versioning follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [1.3.4] — 2026-06-04

### Fixed

- **Prezzo €0 su prodotti variabili con `_sale_price = 0`** (`src/Frontend/PriceHooks.php`):
  `WC_Product_Variable::get_price_html()` applica il filtro `woocommerce_variable_price_html`
  (non `woocommerce_get_price_html`), che il plugin non intercettava. Di conseguenza, il prezzo
  iniziale del prodotto padre (mostrato prima che l'utente selezioni una variante) ignorava
  completamente il listino e mostrava il prezzo WooCommerce grezzo. Quando una variante aveva
  `_sale_price = 0` (anche per errore), il padre visualizzava `€0,00`.
  Aggiunto hook su `woocommerce_variable_price_html` (priorità 20) con il callback
  `filter_variable_price_html()`: itera sulle varianti visibili, raccoglie i prezzi dal listino
  dell'utente tramite `PriceResolver`, e restituisce un singolo prezzo o un range `min–max`.
  Se nessuna variante ha un prezzo nel listino, cade in fallback al comportamento WooCommerce
  di default. Il listino dell'utente vince sempre sul `_sale_price` impostato sul prodotto.

---

## [1.3.3] — 2026-03-02

### Added

- **Select2 sui filtri sidebar WooCommerce** (`src/Frontend/FrontendAssets.php`,
  `public/js/wdplp-public.js`, `public/vendor/select2/`):
  Select2 v4.1.0-rc.0 viene caricato come libreria locale (CSS + JS + locale italiano).
  Il plugin inizializza automaticamente ogni `<select>` dentro `#sidebar` che non sia
  già stato migliorato da altri script (guard `.select2-hidden-accessible`).
  - Search box sempre visibile (`minimumResultsForSearch: 0`)
  - Larghezza al 100% del contenitore
  - Localizzazione italiana (`language: 'it'`)
- **`public/js/wdplp-public.js`**: nuovo script frontend con la funzione
  `initSidebarSelects()`. Tutte le dipendenze (jquery, Select2, locale it) sono
  dichiarate via `wp_enqueue_script`.
- **`package.json` — script `copy-vendor`**: copia automaticamente i file Select2 da
  `node_modules/` a `public/vendor/select2/` durante il build
  (`npm run build` e `npm run build:prod`). I file vendor sono committati in git
  per il deploy in produzione (senza npm).

---

## [1.3.2] — 2026-03-02

### Fixed

- **Frontend CSS non caricato su pagine WooCommerce** (`src/Frontend/FrontendAssets.php`):
  rimossa la dipendenza da `woocommerce-general`. Alcuni temi integrano WooCommerce
  deregistrando quell'handle CSS; WordPress omette silenziosamente qualsiasi stylesheet
  la cui dipendenza non sia registrata, impedendo il caricamento di `wdplp-public.css`.
  Poiché i selettori del plugin (`.uc-filter-num`) non sovrascrivono stili WooCommerce
  core, l'ordine di caricamento è irrilevante e la dipendenza non è necessaria.

---

## [1.3.1] — 2026-03-02

### Added

- **`.filter-3-columns` modifier** (`public/scss/wdplp-public.scss`): aggiungendo la classe
  `filter-3-columns` al contenitore `.filter-num` la griglia passa da 5 a 3 colonne
  (`.filter-num.filter-3-columns ul { grid-template-columns: repeat(3, 1fr) }`).
- **`font-size: 11px` su `.filter-num ul li`**: dimensione testo ridotta per adattarsi meglio
  alla griglia compatta dei filtri numerici/taglia.
- **SCSS compilation in `deploy.sh`**: il deploy live esegue automaticamente
  `npm run build:prod` prima di trasferire i file, garantendo che i CSS compressi
  siano sempre aggiornati. I sorgenti SCSS (`admin/scss/`, `public/scss/`) e
  `node_modules/` sono ora esclusi dal mirror FTP.

---

## [1.3.0] — 2026-03-02

### Added

- **SCSS build system** (`admin/scss/`): il CSS admin è ora gestito tramite SCSS modulare.
  Entry point `admin/scss/wdplp-admin.scss` importa 8 file parziali (`_variables`,
  `_layout`, `_cards`, `_forms`, `_notices`, `_tables`, `_logs`, `_pagination`).
  Usa `@use` con namespace (`v.$color-*`, `v.$spacing-*`) invece del deprecato `@import`.
  Compilare con: `npm install && npm run build` (o `npm run watch` in sviluppo,
  `npm run build:prod` per produzione compressa). NON editare `admin/css/wdplp-admin.css`
  direttamente.
- **Frontend stylesheet** (`public/scss/wdplp-public.scss`, `src/Frontend/FrontendAssets.php`):
  nuovo CSS pubblico caricato **solo sulle pagine WooCommerce** (shop, prodotto,
  tassonomie prodotto, carrello, checkout) tramite dipendenza su `woocommerce-general`.
  Contiene gli stili per `.filter-num` (griglia 5 colonne per filtri numerici/taglia con
  stato `.chosen` evidenziato e conteggio `.count` nascosto).
- `package.json` con `devDependency: sass ^1.77`: script `build`, `watch`, `build:prod`.

---

## [1.2.0] — 2026-03-02

### Fixed

- **SKU sanitisation with `+` character** (`includes/Helpers.php`): `Helpers::sanitize_sku()`
  now preserves the `+` character. SKUs such as `895.2002240406+6` were previously stripped
  to `895.20022404006` by the character-class regex, causing "SKU not found" errors on import
  even when the product existed in WooCommerce. The fix adds `+` to the allowed-characters
  whitelist (inside `[...]` it is a literal, not a quantifier — no escaping required).

### Added

- **Import preferences memory** (`src/Admin/ImportPage.php`, `admin/views/import-page.php`):
  the import form now restores the last used source type (file / remote URL), the remote URL,
  and the row-limit value on page load. Preferences are saved as WordPress user meta
  (`_wdplp_last_import_source`, `_wdplp_last_import_url`, `_wdplp_last_import_limit`) and
  are scoped per admin user — multiple administrators do not overwrite each other's settings.

- **Log file download** (`src/Admin/LogsPage.php`, `admin/views/logs-page.php`):
  each log file in the Logs & History tab now shows a "Download" button that serves the raw
  `.log` file as a `text/plain` attachment. The endpoint (`admin_post_wdplp_download_log`,
  handled by `LogsPage::handle_download()`) enforces nonce validation, `manage_woocommerce`
  capability, regex filename validation, and `realpath()` path-traversal prevention — the
  same security chain as the log viewer. `LogsPage` is now also instantiated in
  `Plugin::register_admin_hooks()` to register the download hook on every admin request.

---

## [1.1.0] — 2026-02-26

### Added

- **Admin "Data" tab**: read-only paginated view of the `custom_price_lists` table (50 rows/page), with SKU partial-match filter (LIKE) and COD exact-match dropdown; clicking a COD value applies the filter automatically.
- **Import row limit**: numeric field in the import form to limit how many CSV rows are processed — useful for test imports on large files (0 = no limit).
- **Italian translation** (`it_IT`): full `.pot` template with 75 translatable strings, compiled `.po` / `.mo` files for `it_IT` locale. Standalone `tools/compile-mo.php` script for future recompilation without external tools.
- `Repository::get_rows_paginated()` — paginated SELECT with optional SKU (LIKE) and COD (exact) filters.
- `Repository::count_rows_filtered()` — COUNT(*) for the same filter combination, used for pagination.
- `Repository::get_distinct_cods()` — returns all distinct active COD values sorted alphabetically (used for the dropdown filter in the Data tab).
- `src/Admin/DataPage.php` — new admin class rendering the Data tab content.
- `admin/views/data-page.php` — template for the Data tab with filter form and paginated table.
- `tools/compile-mo.php` — standalone PHP script to compile `.po` → `.mo` without requiring `msgfmt` or WP-CLI.

### Changed

- **Price format parsing** (`Helpers::sanitize_price`): now strips currency symbols (€, $, £, …) and handles all real-world European/US number formats:
  - `"€ 68,25"` → `68.25`
  - `"1.234,56"` (EU thousands + comma decimal) → `1234.56`
  - `"1,234.56"` (US thousands + dot decimal) → `1234.56`
- **Per-row warning grouping** (`CsvTransformer`): instead of emitting one warning per invalid-price column, all unparseable columns for a row are collected and reported in a single message (e.g. `SKU X: invalid price format in 10 column(s) (LORDO, L40, L45, …)`).
- **SKU pre-validation** (`CsvImporter`): all unique SKUs are validated against WooCommerce **before** any DB write. Rows for SKUs not found in WC are skipped entirely; each missing SKU is reported exactly once regardless of how many columns it appeared in.
- Admin tab navigation extended to 3 tabs: Import, Logs & History, Data.
- Four error strings in `CsvImporter::parse_csv()` / `run_import()` are now wrapped in `__()` and appear correctly in translated environments.

### Fixed

- European-format prices (with currency prefix and comma decimal) were silently dropped as "invalid numbers" — now parsed correctly.
- Import log showed one warning per CSV column for the same SKU when prices had an unrecognised format; now shows a single grouped warning per row.

## [1.0.0] — 2026-02-19

### Added

- Custom database table `{prefix}custom_price_lists` with UPSERT support on `(sku, cod, date_from)`.
- CSV importer with Wide-to-Long transformation:
  - `LORDO` column updates WooCommerce `_regular_price` / `_price`.
  - All other columns are stored as per-list prices keyed by column header (`cod`).
  - Auto-detection of CSV delimiter (comma, semicolon, tab).
  - UTF-8 BOM stripping; Windows-1252 and European decimal comma support.
  - `--dry-run` mode: full parse/validate without DB writes.
- Remote CSV import via URL using `wp_remote_get()` with configurable timeout (`wdplp_remote_download_timeout` filter).
- Admin UI under **WooCommerce > Price Lists**:
  - **Import** tab: file upload or remote URL, import result notice with per-row error details.
  - **Logs** tab: import history table (last 30 dates) and log file viewer with dark-terminal display.
- Daily log files written to `wp-content/uploads/wc-price-logs/`, protected by `.htaccess`.
- User profile **Assigned Price List** dropdown, stored in `_assigned_price_list` user meta.
- Frontend behaviour:
  - Guest users see a configurable "Login to see prices" prompt; Add to Cart is disabled.
  - Logged-in users see their list price; falls back to WooCommerce default when no custom price exists.
  - Cart totals recalculated with the list price on every page load (`woocommerce_before_calculate_totals`).
- WP-CLI command group `wp price-list`:
  - `import --file=<path>` / `--url=<url>` — import from local file or remote URL.
  - `import --dry-run` — validate without writing.
  - `import --format=<table|csv|json|yaml>` — machine-readable output.
  - `status` — show active row count and distinct COD values.
- Developer hooks: `wdplp_before_import`, `wdplp_after_import`, `wdplp_final_price`, `wdplp_login_prompt_html`.
- WooCommerce HPOS (Custom Order Tables) compatibility declaration.
- PSR-4-style autoloader (no Composer dependency).
- `uninstall.php`: drops table, deletes options, removes all `_assigned_price_list` user meta on full delete.

[1.3.4]: https://github.com/your-username/woo-dynamic-pricelist-pro/compare/v1.3.3...v1.3.4
[1.3.3]: https://github.com/your-username/woo-dynamic-pricelist-pro/compare/v1.3.2...v1.3.3
[1.3.2]: https://github.com/your-username/woo-dynamic-pricelist-pro/compare/v1.3.1...v1.3.2
[1.3.1]: https://github.com/your-username/woo-dynamic-pricelist-pro/compare/v1.3.0...v1.3.1
[1.3.0]: https://github.com/your-username/woo-dynamic-pricelist-pro/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/your-username/woo-dynamic-pricelist-pro/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/your-username/woo-dynamic-pricelist-pro/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/your-username/woo-dynamic-pricelist-pro/releases/tag/v1.0.0
