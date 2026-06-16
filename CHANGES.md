# Changelog

All notable changes to **WooCommerce Dynamic Price List Pro** are documented here.

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).
Versioning follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [Unreleased]

---

## [1.4.2] — 2026-06-16

### Fixed

- Tab Aggiornamenti: il link "Repository" mostra ora solo `autore/repo` (`mavidasnc/woo-dynamic-pricelist-pro`) invece dell'URL completo, coerentemente con il plugin woo-multi-stock.

---

## [1.4.1] — 2026-06-16

### Fixed

- Tab "Aggiornamenti" visualizzata in inglese: aggiunte tutte le traduzioni italiane mancanti per le stringhe introdotte in 1.4.0 (`includes/Updater.php`, `src/Admin/UpdatesPage.php`, `src/Admin/AdminController.php`).
- Completate le traduzioni mancanti per il visualizzatore log (`Download`, `Errore`, `Nome del file di log non valido`, `File di log non trovato o non leggibile`).

---

## [1.4.0] — 2026-06-16

### Added

- Sistema di aggiornamento automatico via GitHub Releases (`includes/Updater.php`): iniezione nel transient `update_plugins`, popup "Visualizza dettagli" via `plugins_api`, rinomina cartella estratta (`upgrader_source_selection`), invalidazione cache dopo update.
- Tab **Updates** nella pagina admin (`src/Admin/UpdatesPage.php`): versione installata, versione remota, pulsante "Check for updates" con risposta AJAX (changelog + pulsante "Update now").

### Fixed

- Corretta `Plugin URI` nell'header del plugin (rimosso placeholder `your-username`).

---

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

[1.0.0]: https://github.com/your-username/woo-dynamic-pricelist-pro/releases/tag/v1.0.0
