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
 * @package WDPLP
 * @since   1.0.0
 */

namespace WDPLP;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Activator
 *
 * Registered as the activation callback in the main plugin file:
 *   register_activation_hook( WDPLP_PLUGIN_FILE, [ WDPLP\Activator::class, 'activate' ] );
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
		update_option( 'wdplp_version', WDPLP_VERSION );

		// Step 4: flush rewrite rules. Not strictly needed for this plugin,
		// but included as a no-op placeholder — remove if this plugin never
		// registers custom post types or rewrite rules.
		flush_rewrite_rules();
	}

	/**
	 * Prevent instantiation. This is a static utility class.
	 */
	private function __construct() {}
}
