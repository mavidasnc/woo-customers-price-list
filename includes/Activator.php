<?php
/**
 * Plugin Activation Handler.
 *
 * Executes all one-time setup tasks when the plugin is first activated
 * or re-activated through the WordPress admin or WP-CLI.
 *
 * Design notes:
 *   - Uses static methods so WordPress can call them without needing
 *     an object instance (register_activation_hook expects a callable).
 *   - All operations are idempotent: running activate() a second time
 *     (e.g. after an update or deactivation/re-activation) must produce
 *     the same result without duplicating data or causing errors.
 *
 * @package WCPL
 * @since   1.0.0
 */

namespace WCPL;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Activator
 *
 * Registered as the activation callback in the main plugin file:
 *   register_activation_hook( WCPL_PLUGIN_FILE, [ WCPL\Activator::class, 'activate' ] );
 */
final class Activator {

	/**
	 * Run all activation tasks.
	 *
	 * Execution order is intentional:
	 *   1. Database: table must exist before anything tries to query it.
	 *   2. Log directory: must exist before the first import runs.
	 *   3. Option: version stamp enables future migration checks.
	 *
	 * @return void
	 */
	public static function activate(): void {
		// Step 1: create (or upgrade) the custom database table.
		Database::create_table();

		// Step 2: create the log directory and protect it from web access.
		Helpers::ensure_log_dir();

		// Step 3: store the current plugin version as a WordPress option.
		// update_option() is used (not add_option()) so that version bumps
		// on re-activation also update the stored value.
		update_option( 'wcpl_version', WCPL_VERSION );

		// Step 3b: carry over the data saved by the plugin before the rename.
		self::migrate_legacy_data();

		// Step 4: flush rewrite rules. Not strictly needed for this plugin,
		// but included as a no-op placeholder — remove if this plugin never
		// registers custom post types or rewrite rules.
		flush_rewrite_rules();
	}

	/**
	 * Migrate the data saved under the pre-2.0.0 prefix (woo-dynamic-pricelist-pro).
	 *
	 * The price list table (custom_price_lists) and the assigned list user meta
	 * (_assigned_price_list) never had a prefix, so they are shared as they are.
	 * Only the import form preferences and the version option need a rename.
	 * Idempotent: running it twice finds nothing left to rename.
	 *
	 * @return void
	 */
	private static function migrate_legacy_data(): void {
		global $wpdb;

		// Rename the per-admin import form preferences (_wdplp_last_import_* → _wcpl_last_import_*).
		foreach ( [ 'source', 'url', 'limit' ] as $suffix ) {
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->usermeta,
				[ 'meta_key' => '_wcpl_last_import_' . $suffix ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				[ 'meta_key' => '_wdplp_last_import_' . $suffix ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				[ '%s' ],
				[ '%s' ]
			);
		}

		// The old version stamp is replaced by wcpl_version (Step 3).
		delete_option( 'wdplp_version' );

		// Release info cached by the pre-2.0.0 updater (now wcpl_github_release).
		delete_transient( 'wdplp_github_release' );

	}

	/**
	 * Prevent instantiation. This is a static utility class.
	 */
	private function __construct() {}
}
