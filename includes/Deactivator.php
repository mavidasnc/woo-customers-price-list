<?php
/**
 * Plugin Deactivation Handler.
 *
 * Executes cleanup tasks when the plugin is deactivated through the
 * WordPress admin or WP-CLI.
 *
 * IMPORTANT — Deactivation vs Uninstall:
 *   Deactivation is a temporary state; the plugin can be re-activated at
 *   any time. Therefore we must NOT delete user data (database table, logs,
 *   user meta) here. Those destructive operations belong exclusively in
 *   uninstall.php, which WordPress executes only when the plugin is
 *   permanently deleted.
 *
 * What we DO on deactivation:
 *   - Clear any scheduled WP-Cron events registered by this plugin.
 *   - Flush rewrite rules (clean up any rules we may have added).
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
 * Class Deactivator
 *
 * Registered as the deactivation callback in the main plugin file:
 *   register_deactivation_hook( WCPL_PLUGIN_FILE, [ WCPL\Deactivator::class, 'deactivate' ] );
 */
final class Deactivator {

	/**
	 * Run all deactivation tasks.
	 *
	 * This method is intentionally minimal. Add WP-Cron unscheduling here
	 * if scheduled events are introduced in future versions.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		// Unschedule any WP-Cron events this plugin may register in the future.
		// Pattern: wp_clear_scheduled_hook( 'wcpl_scheduled_event_name' );

		// Flush rewrite rules to remove any custom rules this plugin may add.
		flush_rewrite_rules();
	}

	/**
	 * Prevent instantiation. This is a static utility class.
	 */
	private function __construct() {}
}
