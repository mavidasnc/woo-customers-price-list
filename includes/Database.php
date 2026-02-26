<?php
/**
 * Database Schema Manager.
 *
 * Handles all DDL (Data Definition Language) operations for the plugin:
 *   - Creating the custom price list table on activation.
 *   - Running schema migrations on plugin update (version-gated via dbDelta).
 *   - Dropping the table on uninstall (called from uninstall.php).
 *
 * Why dbDelta?
 *   dbDelta() is WordPress's idempotent table creation function. It compares
 *   the desired schema against the existing table structure and applies only
 *   the necessary changes (ADD COLUMN, MODIFY, etc.) without data loss.
 *   This makes it safe to call on every activation and version upgrade.
 *
 * dbDelta SQL Formatting Rules (must be followed precisely):
 *   1. Each column definition must be on its own line.
 *   2. PRIMARY KEY must be written as:  PRIMARY KEY  (column)  — two spaces.
 *   3. Other indexes use KEY, not INDEX.
 *   4. No trailing comma on the last definition before the closing parenthesis.
 *
 * @package WDPLP
 * @since   1.0.0
 */

namespace WDPLP;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Database
 *
 * All methods are static; this class is never instantiated.
 */
final class Database {

	/**
	 * Table name suffix (without the $wpdb->prefix).
	 *
	 * The full table name is always retrieved via get_table_name() to ensure
	 * the correct prefix is used across single and multisite installations.
	 *
	 * @var string
	 */
	private const TABLE_SUFFIX = 'custom_price_lists';

	/**
	 * Return the full (prefixed) table name for use in SQL queries.
	 *
	 * Always use this method instead of constructing the table name manually
	 * to ensure multisite compatibility ($wpdb->prefix changes per blog).
	 *
	 * @global \wpdb $wpdb WordPress database object.
	 * @return string Full table name, e.g. "wp_custom_price_lists".
	 */
	public static function get_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_SUFFIX;
	}

	/**
	 * Create or upgrade the custom price lists table.
	 *
	 * Safe to call multiple times — dbDelta() will only apply the delta
	 * (differences) between the desired schema and the current table structure.
	 *
	 * Column descriptions:
	 *   id         — Auto-increment primary key.
	 *   sku        — WooCommerce product SKU (VARCHAR 100, same as WC's internal limit).
	 *   cod        — Price list code, e.g. "L40", "L45", "L50N1" (from CSV header).
	 *   price      — The list price for this sku/cod combination.
	 *                DECIMAL(15,4) is used — never FLOAT for monetary values.
	 *   date_from  — Date from which this price is valid (set to import date by default).
	 *   created_at — Timestamp of the insert (auto-populated by MySQL).
	 *   status     — Soft-disable flag: 1 = active, 0 = inactive. Allows price rows
	 *                to be hidden without deletion.
	 *
	 * Indexes:
	 *   PRIMARY KEY (id)                   — standard auto-increment PK.
	 *   KEY idx_sku (sku)                  — fast lookup by SKU.
	 *   UNIQUE KEY idx_sku_cod_date        — enforces one price per SKU+list+date;
	 *     (sku, cod, date_from)              also enables safe UPSERT via
	 *                                        INSERT … ON DUPLICATE KEY UPDATE.
	 *
	 * @global \wpdb $wpdb WordPress database object.
	 * @return void
	 */
	public static function create_table(): void {
		global $wpdb;

		// dbDelta() requires wp-admin/includes/upgrade.php.
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name      = self::get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		/*
		 * IMPORTANT: dbDelta SQL formatting rules apply here.
		 * - Two spaces between "PRIMARY KEY" and "(" — do not change.
		 * - Each field on its own line.
		 * - Use KEY, not INDEX, for non-primary indexes.
		 */
		$sql = "CREATE TABLE {$table_name} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  sku varchar(100) NOT NULL DEFAULT '',
  cod varchar(50) NOT NULL DEFAULT '',
  price decimal(15,4) NOT NULL DEFAULT '0.0000',
  date_from date NOT NULL,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  status tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY  (id),
  KEY idx_sku (sku),
  UNIQUE KEY idx_sku_cod_date (sku,cod,date_from)
) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Drop the custom price lists table from the database.
	 *
	 * This is a DESTRUCTIVE, IRREVERSIBLE operation.
	 * It should ONLY be called from uninstall.php, never on deactivation.
	 *
	 * @global \wpdb $wpdb WordPress database object.
	 * @return void
	 */
	public static function drop_table(): void {
		global $wpdb;

		$table_name = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DROP TABLE IF EXISTS `{$table_name}`" ); // @phpstan-ignore-line
	}

	/**
	 * Prevent instantiation. This is a static utility class.
	 */
	private function __construct() {}
}
