# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

---

## Build Tools (SCSS)

SCSS sorgente in `admin/scss/` e `public/scss/`. CSS compilato (output) in `admin/css/` e `public/css/`.
**NON modificare i file `.css` direttamente** — editare i `.scss` e compilare.

```bash
npm install          # installa dart-sass (prima volta)
npm run build        # compila tutti gli SCSS — expanded, no source map
npm run watch        # watch mode per sviluppo
npm run build:prod   # compila compresso per produzione
```

| Sorgente SCSS | Output CSS compilato |
|---|---|
| `admin/scss/wcpl-admin.scss` | `admin/css/wcpl-admin.css` |
| `public/scss/wcpl-public.scss` | `public/css/wcpl-public.css` |

I file CSS compilati **vanno committati** nel repo (produzione non ha npm).
`node_modules/` è già in `.gitignore`.

---

## Common Commands

### Syntax check a single file
```bash
php -l src/Import/CsvImporter.php
```

### Syntax check all PHP files (show only errors)
```bash
find . -name "*.php" | xargs -I{} php -l {} 2>&1 | grep -v "No syntax"
```

### WP-CLI import (inside a WordPress environment)
```bash
wp price-list import --file=/absolute/path/to/prices.csv
wp price-list import --url=https://example.com/prices.csv
wp price-list import --file=/tmp/prices.csv --dry-run
wp price-list status
```

---

## Autoloader Resolution

The custom autoloader (`includes/Autoloader.php`) is **not Composer** — it uses a two-directory rule:

| FQCN pattern | Resolves to |
|---|---|
| `WCPL\SomeClass` (no sub-namespace) | `includes/SomeClass.php` |
| `WCPL\SubNs\SomeClass` (has sub-namespace) | `src/SubNs/SomeClass.php` |

Practical consequence: all classes in `includes/` are in the **root namespace** `WCPL\`; all classes in `src/` carry a **sub-namespace** (e.g. `WCPL\Import\CsvImporter`).

---

## Plugin Lifecycle

```
woo-customers-price-list.php
  └── PHP 8.0 check (deactivates + notice if fails)
  └── defines 8 WCPL_* constants
  └── require_once includes/Autoloader.php → WCPL\Autoloader::register()
  └── register_activation_hook → Activator::activate()
        └── Database::create_table() (dbDelta — idempotent)
        └── Helpers::ensure_log_dir()
  └── add_action('plugins_loaded', Plugin::get_instance(), priority 11)

Plugin::initialize()
  └── checks WooCommerce active + version (>= 7.0)
  └── WP_CLI context   → PriceListCommand registered via WP_CLI::add_command()
  └── is_admin()       → AdminController + ImportPage + LogsPage (hooks) + UserProfileField
  └── else (frontend)  → FrontendAssets + PriceHooks + CartConsistency
```

All component instantiation in `Plugin.php` is wrapped in `class_exists()` guards — new classes can be added incrementally without breaking the active plugin.

---

## Key Architectural Patterns

### Import pipeline (`CsvImporter::run_import`)
Wide CSV → transform to "long" → SKU validation → WC price updates → DB upsert

1. `parse_csv()` — strips BOM, auto-detects delimiter (`,` `;` `\t`), pads short rows; optional `$limit` trims rows after parsing
2. `CsvTransformer::transform()` — `LORDO` column → `wc_price_updates[]`; all other columns → `price_list_rows[]`; invalid-price warnings grouped per row (not per column)
3. `validate_all_skus()` — looks up every unique SKU once via `wc_get_product_id_by_sku()` (searches simple products and variations); unknown SKUs are reported once and their rows filtered out before any write
4. For each valid WC update: `update_post_meta(_regular_price + _price)` + `wc_delete_product_transients()`
5. `Repository::upsert_rows()` — `INSERT … ON DUPLICATE KEY UPDATE` on `(sku, cod, date_from)`
6. Supports `$dry_run = true` — runs steps 1–3 but skips 4–5; report still counts what *would* have been written

`Helpers::sanitize_price()` handles all real-world formats: `"€ 68,25"` → `68.25`, `"1.234,56"` → `1234.56`, `"1,234.56"` → `1234.56`. Uses last-separator-wins heuristic for decimal detection.

### Price resolution (`PriceResolver::get_price_for_user`)
1. Read `_assigned_price_list` user meta (the `cod` string)
2. `Repository::get_price_for_sku_and_cod()` — `ORDER BY date_from DESC LIMIT 1` (most recent wins)
3. `apply_filters('wcpl_final_price', $price, $sku, $user_id, $cod)` — developer override point
4. Returns `null` to signal "use WooCommerce default"

Results are cached in a private `array $cache` keyed `"{user_id}_{sku}"` (null = confirmed miss, to avoid re-querying).

### Admin tab routing
Single slug `wcpl-import` with `?tab=` routing. Valid tabs: `import` (default), `logs`, `data`.

- **import** → `ImportPage::render_content()` — file/URL upload form with optional row-limit field; POST→redirect→GET via transient `wcpl_import_result_{user_id}` (TTL 5 min). Last-used source, URL, and row-limit saved as user meta (`_wcpl_last_import_source`, `_wcpl_last_import_url`, `_wcpl_last_import_limit`) and pre-filled on next load.
- **logs** → `LogsPage::render_content()` — import history + log file viewer + "Download" button per file. `LogsPage::handle_download()` hooked to `admin_post_wcpl_download_log`.
- **data** → `DataPage::render_content()` — paginated read-only table of `custom_price_lists` rows; GET params `filter_sku` (LIKE), `filter_cod` (exact), `paged`; 50 rows/page.

### Frontend hooks
- `woocommerce_get_price_html` (priority 20) — full price HTML replacement (not `woocommerce_get_price`)
- `woocommerce_is_purchasable` (priority 20) — returns `false` for guests
- `woocommerce_before_calculate_totals` (priority 20) — calls `$product->set_price()` on each cart item (non-persistent, per-request only)

---

## Database Table

Table name: `{$wpdb->prefix}custom_price_lists`

- `DECIMAL(15,4)` for prices — never `FLOAT`
- `UNIQUE KEY idx_sku_cod_date (sku, cod, date_from)` — enables UPSERT
- `status TINYINT(1)` — soft-disable (1 = active); all queries filter by `status = 1`
- `date_from DATE` — most recent date wins on multi-date queries (`ORDER BY date_from DESC LIMIT 1`)

---

## Constants

| Constant | Value |
|---|---|
| `WCPL_VERSION` | `'1.3.5'` |
| `WCPL_PLUGIN_DIR` | `plugin_dir_path(__FILE__)` (trailing slash) |
| `WCPL_PLUGIN_URL` | `plugin_dir_url(__FILE__)` (trailing slash) |
| `WCPL_PLUGIN_FILE` | `__FILE__` of bootstrap |
| `WCPL_TEXT_DOMAIN` | `'woo-customers-price-list'` |
| `WCPL_MIN_WC` | `'7.0'` |
| `WCPL_MIN_PHP` | `'8.0'` |
| `WCPL_LOG_DIR` | `WP_CONTENT_DIR . '/uploads/wc-price-logs/'` |

---

## Security Conventions

Every admin action uses the **WordPress Security Trinity**:

```php
check_admin_referer( 'wcpl_import', 'wcpl_import_nonce' );   // nonce
current_user_can( 'manage_woocommerce' );                        // capability
sanitize_text_field( $raw );  /  Helpers::sanitize_sku( $raw ); // sanitise
```

Log file viewer (`LogsPage::read_log_file`) prevents path traversal with:
```php
realpath($path) starts with realpath(WCPL_LOG_DIR)
```

### Log download endpoint

- **Action**: `admin_post_wcpl_download_log`
- **Handler**: `LogsPage::handle_download()`
- **Nonce**: action `wcpl_download_log`, field `wcpl_download_nonce`
- **Link generation**: `wp_nonce_url()` in `admin/views/logs-page.php`
- **Validation chain**: nonce → `manage_woocommerce` capability → regex (`wc-price-log-YYYY-MM-DD.log`) → `realpath()` containment check → `readfile()` with prior `ob_end_clean()` loop

---

## Developer Hooks Reference

| Hook | Type | Signature |
|---|---|---|
| `wcpl_before_import` | action | `( array $source_info )` |
| `wcpl_after_import` | action | `( ImportReport $report )` |
| `wcpl_final_price` | filter | `( float $price, string $sku, int $user_id, string $cod )` — return `null` to fall back to WC default |
| `wcpl_login_prompt_html` | filter | `( string $message, string $login_url )` |
| `wcpl_remote_download_timeout` | filter | `( int $seconds, string $url )` |

---

## Adding a New Class

1. Decide namespace: root-level utility → `includes/` + namespace `WCPL`; domain class → `src/SubNs/` + namespace `WCPL\SubNs`
2. Create the file — autoloader resolves it automatically
3. Register hooks in `Plugin::register_admin_hooks()` or `register_frontend_hooks()`, wrapped in `class_exists()`

---

## Internazionalizzazione (i18n)

Text domain: `woo-customers-price-list` (constant `WCPL_TEXT_DOMAIN`).
Translation files live in `languages/`. Loaded via `load_plugin_textdomain()` on `init`.

| File | Purpose |
|---|---|
| `languages/woo-customers-price-list.pot` | Template — source of truth for all msgid strings (~75 entries) |
| `languages/woo-customers-price-list-it_IT.po` | Italian translation (human-readable) |
| `languages/woo-customers-price-list-it_IT.mo` | Italian translation (binary, loaded by WordPress) |
| `tools/compile-mo.php` | Standalone `.po` → `.mo` compiler (no external tools needed) |

### Adding a new translatable string
Use `__( 'String', WCPL_TEXT_DOMAIN )` (or `esc_html__`, `esc_html_e` etc.). Add a `/* translators: */` comment when the string contains `%s`/`%d` placeholders.

**Exception — `CsvTransformer.php`**: this class is pure PHP with no WP dependency by design. Its internal warning strings are intentionally left in English.

### Updating translations after code changes
```bash
# Regenerate .pot (requires WP-CLI in a WP environment)
wp i18n make-pot . languages/woo-customers-price-list.pot

# Merge new strings into existing .po without losing translations
msgmerge --update languages/woo-customers-price-list-it_IT.po \
         languages/woo-customers-price-list.pot

# Recompile .mo (no external tools needed)
php tools/compile-mo.php languages/woo-customers-price-list-it_IT.po
```
