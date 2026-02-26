# WooCommerce Dynamic Price List Pro

A professional WooCommerce plugin for managing dynamic, per-user price lists
imported from CSV files. Supports multi-tier pricing (L40, L45, L50N1, etc.)
with per-user list assignment and full WP-CLI support.

---

## Requirements

| Dependency       | Minimum Version |
|------------------|-----------------|
| PHP              | 8.0             |
| WordPress        | 6.0             |
| WooCommerce      | 7.0             |
| MySQL / MariaDB  | 5.6.5 / 10.0    |

---

## Installation

### Manual

1. Download or clone this repository into your WordPress plugins directory:
   ```
   wp-content/plugins/woo_dynamic_pricelist_pro/
   ```
2. Log in to the WordPress admin.
3. Navigate to **Plugins > Installed Plugins**.
4. Find **WooCommerce Dynamic Price List Pro** and click **Activate**.

On activation the plugin automatically:
- Creates the `{prefix}custom_price_lists` database table.
- Creates the log directory at `wp-content/uploads/wc-price-logs/`.

### Via WP-CLI

```bash
wp plugin activate woo_dynamic_pricelist_pro
```

---

## CSV File Format

The import file must be a UTF-8 encoded CSV with a header row.

### Column Rules

| Column        | Required | Description                                                      |
|---------------|----------|------------------------------------------------------------------|
| `SKU`         | Yes      | WooCommerce product SKU. Case-insensitive header.                |
| `LORDO`       | Yes      | The full (gross) price. Updates WooCommerce `_regular_price`.    |
| `L40`, `L45`, `L50N1`, … | No | Per-list prices. Column header becomes the `cod` value in the database. |

**Rules:**
- Any column header that is NOT `SKU` and NOT `LORDO` is treated as a price list code.
- Empty cells in price list columns are silently skipped (no row is created).
- Duplicate combinations of `(sku, cod, date_from)` trigger an UPSERT — the price is updated, never duplicated.

### Example

```csv
SKU,LORDO,L40,L45,L50N1
PROD-001,100.00,85.00,80.00,75.00
PROD-002,200.00,170.00,160.00,
PROD-003,50.00,,,42.50
```

**Result for row 1 (`PROD-001`):**
- WooCommerce `_regular_price` set to `100.00`
- Three rows inserted into `custom_price_lists`: `(PROD-001, L40, 85.00)`, `(PROD-001, L45, 80.00)`, `(PROD-001, L50N1, 75.00)`

---

## Admin UI

Navigate to **WooCommerce > Price Lists** in the WordPress admin.

### Import Tab

- **Upload CSV** — select a local file and click **Import**.
- **Import from URL** — paste a remote CSV URL and click **Import**.

After import, a report is displayed showing:
- Total rows processed
- Products/variations updated
- SKUs not found
- Any errors encountered

### Logs Tab

Displays recent import logs stored in `wp-content/uploads/wc-price-logs/`.

---

## User Profile

Each user can be assigned a price list via their profile page:

1. Go to **Users > All Users** and click **Edit** on a user.
2. Find the **Price List Settings** section.
3. Select a list code from the **Assigned Price List** dropdown.
4. Click **Update User**.

The assigned list is stored in user meta as `_assigned_price_list`.

---

## WP-CLI Usage

### Import from a local file

```bash
wp price-list import --file=/absolute/path/to/prices.csv
```

### Import from a remote URL

```bash
wp price-list import --url=https://example.com/prices.csv
```

### Output

The command displays a real-time progress bar and a final summary table:

```
Importing... ████████████████████ 100% (250/250)

+---------------------------+-------+
| Metric                    | Value |
+---------------------------+-------+
| Total rows processed      | 250   |
| WC prices updated         | 250   |
| Price list rows inserted  | 750   |
| SKUs not found            | 3     |
| Errors                    | 0     |
+---------------------------+-------+
Missing SKUs: PROD-999, PROD-998, PROD-997
```

---

## Frontend Behaviour

### Guests (not logged in)

- All product prices are **hidden**.
- The **Add to Cart** button is **disabled**.
- Displays a configurable message prompting login.

### Logged-in users

- If the user has an **assigned price list**:
  - The price from `custom_price_lists` matching `(sku, cod)` is shown.
  - If no match, falls back to the WooCommerce default (`_regular_price`).
- If the user has **no assigned list**, the WooCommerce default price is shown.
- Cart totals are recalculated using the assigned list price on every page load.

---

## Developer Hooks

### Actions

| Hook | When | Arguments |
|------|------|-----------|
| `wdplp_before_import` | Before CSV parsing begins | `array $source_info` |
| `wdplp_after_import` | After all rows are processed | `ImportReport $report` |

**Example:**
```php
add_action( 'wdplp_after_import', function ( $report ) {
    if ( $report->rows_skipped > 0 ) {
        // notify admin via email
    }
} );
```

### Filters

| Filter | Purpose | Arguments |
|--------|---------|-----------|
| `wdplp_final_price` | Modify the resolved price before display/cart | `float $price, string $sku, int $user_id, string $cod` |
| `wdplp_remote_download_timeout` | HTTP timeout for remote CSV downloads | `int $seconds` |

**Example — apply a 10% markup for a specific user role:**
```php
add_filter( 'wdplp_final_price', function ( $price, $sku, $user_id, $cod ) {
    if ( user_has_role( $user_id, 'wholesale' ) ) {
        return $price * 0.90; // 10% discount for wholesale
    }
    return $price;
}, 10, 4 );
```

---

## Database Schema

```sql
CREATE TABLE {prefix}custom_price_lists (
    id         BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    sku        VARCHAR(100)        NOT NULL DEFAULT '',
    cod        VARCHAR(50)         NOT NULL DEFAULT '',
    price      DECIMAL(15,4)       NOT NULL DEFAULT '0.0000',
    date_from  DATE                NOT NULL,
    created_at DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    status     TINYINT(1)          NOT NULL DEFAULT 1,
    PRIMARY KEY  (id),
    KEY        idx_sku (sku),
    UNIQUE KEY idx_sku_cod_date (sku, cod, date_from)
);
```

---

## Project Structure

```
woo_dynamic_pricelist_pro/
├── woo-dynamic-pricelist-pro.php   Main bootstrap file
├── uninstall.php                   Cleanup on full delete
├── includes/
│   ├── Autoloader.php              PSR-4-style class autoloader
│   ├── Plugin.php                  Core singleton orchestrator
│   ├── Activator.php               Activation hook handler
│   ├── Deactivator.php             Deactivation hook handler
│   ├── Database.php                DDL — CREATE TABLE, DROP TABLE
│   └── Helpers.php                 Static utility functions
├── src/
│   ├── Admin/                      Admin menu, import page, logs page
│   ├── Import/                     CSV importer, transformer, logger, report
│   ├── PriceList/                  DB repository, price resolver
│   ├── Frontend/                   Price display hooks, cart consistency
│   ├── User/                       User profile field
│   └── Cli/                        WP-CLI command
├── admin/
│   ├── css/
│   ├── js/
│   └── views/                      PHP HTML templates
└── languages/                      .pot translation template
```

---

## Changelog

### 1.0.0 (2026-02-19)
- Initial release.
- Custom database table for price lists.
- CSV importer with Wide-to-Long transformation.
- Admin UI under WooCommerce > Price Lists.
- User profile "Assigned Price List" dropdown.
- Frontend price/cart hooks.
- WP-CLI `wp price-list import` command.
- Developer hooks: `wdplp_before_import`, `wdplp_after_import`, `wdplp_final_price`.

---

## License

GPL-2.0-or-later — see [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html).
