<?php
/**
 * PSR-4-style Autoloader for the WDPLP namespace.
 *
 * This class is intentionally free of any WordPress dependencies so that
 * it can be safely required and registered before anything else in the
 * bootstrap file, even before the wp_loaded hook fires.
 *
 * Namespace-to-path mapping rules:
 *   - WDPLP\SomeClass            → includes/SomeClass.php   (root-level classes)
 *   - WDPLP\SubNs\SomeClass      → src/SubNs/SomeClass.php  (namespaced classes)
 *
 * The plugin directory is calculated relative to __DIR__ to avoid any
 * dependency on the WDPLP_PLUGIN_DIR constant, making the Autoloader
 * completely self-contained.
 *
 * @package WDPLP
 * @since   1.0.0
 */

namespace WDPLP;

// Autoloader must not be executed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Autoloader
 *
 * Registers a single spl_autoload_register callback that resolves every
 * class whose fully-qualified name begins with the "WDPLP\" prefix.
 *
 * Usage (in the main plugin file, after require_once of this file):
 *   WDPLP\Autoloader::register();
 */
final class Autoloader {

	/**
	 * Absolute path to the plugin root directory (trailing slash).
	 *
	 * Calculated once in ::register() and stored as a static property so
	 * ::autoload() does not need to recompute it on every call.
	 *
	 * @var string
	 */
	private static string $plugin_dir = '';

	/**
	 * The root namespace prefix this autoloader handles.
	 *
	 * @var string
	 */
	private const NAMESPACE_PREFIX = 'WDPLP\\';

	/**
	 * Length of the namespace prefix (cached to avoid repeated strlen calls).
	 *
	 * @var int
	 */
	private const NAMESPACE_PREFIX_LEN = 6; // strlen('WDPLP\\')

	/**
	 * Register the autoloader callback with spl_autoload_register.
	 *
	 * This method is idempotent: calling it more than once will not register
	 * duplicate callbacks because spl_autoload_register deduplicates them.
	 *
	 * The plugin root is derived from __DIR__ (which is the includes/ folder)
	 * by going one level up with dirname().
	 *
	 * @return void
	 */
	public static function register(): void {
		// Calculate the plugin root directory once.
		// __DIR__ is "…/woo_dynamic_pricelist_pro/includes", so dirname gives plugin root.
		self::$plugin_dir = dirname( __DIR__ ) . DIRECTORY_SEPARATOR;

		// Register our autoload callback. prepend=false keeps the default load order.
		spl_autoload_register( [ static::class, 'autoload' ], true, false );
	}

	/**
	 * Autoload callback — resolves a fully-qualified class name to a file path.
	 *
	 * Resolution logic:
	 *   1. Ignore any class that does not start with "WDPLP\".
	 *   2. Strip the "WDPLP\" prefix to get the relative class name.
	 *   3. If the relative name contains NO namespace separator (\), the class
	 *      lives in includes/ (e.g. WDPLP\Plugin → includes/Plugin.php).
	 *   4. Otherwise the class lives in src/ and sub-directories mirror the
	 *      sub-namespace (e.g. WDPLP\Admin\AdminController → src/Admin/AdminController.php).
	 *   5. Replace any remaining "\" with the OS directory separator.
	 *   6. Require the file if it exists; silently bail if it does not
	 *      (other autoloaders in the stack may handle it).
	 *
	 * @param string $class Fully-qualified class name passed by PHP's autoload system.
	 * @return void
	 */
	public static function autoload( string $class ): void {
		// Step 1: bail early for classes outside our namespace.
		if ( ! str_starts_with( $class, self::NAMESPACE_PREFIX ) ) {
			return;
		}

		// Step 2: strip the "WDPLP\" prefix.
		$relative = substr( $class, self::NAMESPACE_PREFIX_LEN );

		// Step 3-5: determine the base directory and build the full file path.
		if ( ! str_contains( $relative, '\\' ) ) {
			// Root-namespace class (no sub-namespace) → lives in includes/.
			$file = self::$plugin_dir . 'includes' . DIRECTORY_SEPARATOR . $relative . '.php';
		} else {
			// Namespaced class → lives in src/, sub-namespace becomes directory.
			$file = self::$plugin_dir . 'src' . DIRECTORY_SEPARATOR
				. str_replace( '\\', DIRECTORY_SEPARATOR, $relative ) . '.php';
		}

		// Step 6: require the file only if it exists.
		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}

	/**
	 * Prevent instantiation. This class is a static utility.
	 */
	private function __construct() {}
}
