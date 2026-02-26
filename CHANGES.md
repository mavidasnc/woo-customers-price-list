# Changelog

All notable changes to **WooCommerce Dynamic Price List Pro** are documented here.

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).
Versioning follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

[1.1.0]: https://github.com/your-username/woo-dynamic-pricelist-pro/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/your-username/woo-dynamic-pricelist-pro/releases/tag/v1.0.0
