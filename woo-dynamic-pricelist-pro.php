<?php
/**
 * Plugin Name:       WooCommerce Dynamic Price List Pro
 * Plugin URI:        https://github.com/your-username/woo-dynamic-pricelist-pro
 * Description:       Professional WooCommerce plugin for dynamic, per-user price lists imported from CSV. Supports multi-tier pricing (L40, L45, L50N1, etc.) with per-user assignment and full WP-CLI support.
 * Version:           1.3.2
 * Author:            Mavida s.n.c
 * Author URI:        https://mavida.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       woo-dynamic-pricelist-pro
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * WC requires at least: 7.0
 * WC tested up to:   9.5.
 */

// Prevent direct access — this file must only be loaded by WordPress.
if (!defined('ABSPATH')) {
    exit;
}

/*
 * PHP version check.
 *
 * Deactivates the plugin gracefully and shows an admin notice if the server
 * is running a PHP version older than the minimum required (8.0).
 * This must run before any PHP 8-only syntax is encountered, so it is placed
 * at the very top before any other code.
 */
if (version_compare(PHP_VERSION, '8.0', '<')) {
    add_action(
        'admin_notices',
        static function (): void {
            printf(
                '<div class="notice notice-error"><p>%s</p></div>',
                esc_html(
                    sprintf(
                        /* translators: 1: Plugin name, 2: Required PHP version, 3: Current PHP version */
                        __('%1$s requires PHP %2$s or higher. You are running PHP %3$s. Please upgrade PHP or deactivate the plugin.', 'woo-dynamic-pricelist-pro'),
                        'WooCommerce Dynamic Price List Pro',
                        '8.0',
                        PHP_VERSION
                    )
                )
            );
        }
    );

    // Deactivate the plugin to prevent any further loading.
    add_action(
        'admin_init',
        static function (): void {
            deactivate_plugins(plugin_basename(__FILE__));
        }
    );

    return;
}

// ---------------------------------------------------------------------------
// Plugin-wide Constants
// ---------------------------------------------------------------------------

/*
 * Plugin version. Bump this on every release.
 * Used for cache-busting asset URLs and database migration checks.
 */
define('WDPLP_VERSION', '1.3.2');

/*
 * Absolute path to the plugin root directory (trailing slash included).
 * Example: /var/www/html/wp-content/plugins/woo_dynamic_pricelist_pro/
 */
define('WDPLP_PLUGIN_DIR', plugin_dir_path(__FILE__));

/*
 * Public URL of the plugin root directory (trailing slash included).
 * Used for enqueueing CSS and JS assets.
 */
define('WDPLP_PLUGIN_URL', plugin_dir_url(__FILE__));

/*
 * Absolute path to this bootstrap file.
 * Used for register_activation_hook, register_deactivation_hook, and
 * plugin_basename() calls that need to reference the main plugin file.
 */
define('WDPLP_PLUGIN_FILE', __FILE__);

/*
 * Text domain for i18n. Must match the "Text Domain" header above.
 */
define('WDPLP_TEXT_DOMAIN', 'woo-dynamic-pricelist-pro');

/*
 * Minimum WooCommerce version required.
 * The Plugin singleton re-checks this at runtime for a user-friendly notice.
 */
define('WDPLP_MIN_WC', '7.0');

/*
 * Minimum PHP version string (already enforced above, kept as constant for
 * programmatic access elsewhere in the codebase).
 */
define('WDPLP_MIN_PHP', '8.0');

/*
 * Absolute path to the log directory inside wp-content/uploads/.
 * The directory is created on activation and protected with .htaccess.
 */
define('WDPLP_LOG_DIR', WP_CONTENT_DIR.'/uploads/wc-price-logs/');

// ---------------------------------------------------------------------------
// Autoloader
// ---------------------------------------------------------------------------

/**
 * Load the PSR-4-style autoloader before anything else.
 * The Autoloader class registers its spl_autoload_register callback
 * immediately in the ::register() call below, making all WDPLP\* classes
 * available from this point onward.
 */
require_once WDPLP_PLUGIN_DIR.'includes/Autoloader.php';
WDPLP\Autoloader::register();

// ---------------------------------------------------------------------------
// Activation / Deactivation Hooks
// ---------------------------------------------------------------------------

/*
 * Activation hook: creates the custom database table and log directory.
 * Runs only when the plugin is activated via the WordPress admin or WP-CLI.
 *
 * NOTE: register_activation_hook must be called in the main plugin file,
 * not inside a class constructor or a hook callback, for WordPress to
 * detect and register it correctly.
 */
register_activation_hook(WDPLP_PLUGIN_FILE, [WDPLP\Activator::class, 'activate']);

/*
 * Deactivation hook: performs safe cleanup (no destructive operations).
 * The database table and logs are preserved on deactivation; they are
 * removed only on full uninstall (see uninstall.php).
 */
register_deactivation_hook(WDPLP_PLUGIN_FILE, [WDPLP\Deactivator::class, 'deactivate']);

// ---------------------------------------------------------------------------
// WooCommerce HPOS (High-Performance Order Storage) Compatibility Declaration
// ---------------------------------------------------------------------------

/*
 * Declare compatibility with WooCommerce's Custom Order Tables (HPOS).
 * This suppresses the "Unknown compatibility" notice in WooCommerce > Status.
 * The plugin does not directly interact with order tables, so compatibility
 * is declared as true.
 */
add_action(
    'before_woocommerce_init',
    static function (): void {
        if (class_exists(Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
            Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                'custom_order_tables',
                WDPLP_PLUGIN_FILE,
                true
            );
        }
    }
);

// ---------------------------------------------------------------------------
// Bootstrap — deferred to plugins_loaded
// ---------------------------------------------------------------------------

/*
 * Initialise the plugin singleton after all plugins have loaded.
 *
 * Priority 11 ensures WooCommerce (which loads at priority 10) is already
 * available when we check for it in Plugin::initialize().
 *
 * We use a static factory method so the singleton is created lazily and
 * only once, regardless of how many times this action fires.
 */
add_action('plugins_loaded', [WDPLP\Plugin::class, 'get_instance'], 11);
