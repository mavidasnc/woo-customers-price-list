<?php
/**
 * Core Plugin Singleton — the central orchestrator.
 *
 * Responsibilities:
 *   - Checks the runtime environment (WooCommerce installed and active,
 *     PHP version already enforced in the bootstrap).
 *   - Lazy-initialises subsystems based on execution context:
 *       • WP-CLI  → registers the CLI command class.
 *       • Admin   → registers admin pages, import handler, user profile field.
 *       • Frontend → registers price display and cart consistency hooks.
 *   - Guards each component instantiation with class_exists() so the plugin
 *     loads without fatal errors during Step 1, before src/ classes exist.
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
 * Class Plugin
 *
 * Implements the Singleton pattern. A single instance is created on the
 * `plugins_loaded` hook (priority 11) and stored in a private static property.
 *
 * Usage (already wired up in the bootstrap file):
 *   add_action( 'plugins_loaded', [ WDPLP\Plugin::class, 'get_instance' ], 11 );
 */
final class Plugin {

	/**
	 * The single instance of this class.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Retrieve (or create) the singleton instance.
	 *
	 * This method is safe to call multiple times; only the first call creates
	 * and initialises the instance. Subsequent calls return the same object.
	 *
	 * @return Plugin
	 */
	public static function get_instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->initialize();
		}

		return self::$instance;
	}

	/**
	 * Private constructor — use get_instance() to obtain the singleton.
	 *
	 * Intentionally empty: all setup is deferred to initialize() so that
	 * the singleton reference is stored before any hook callbacks fire
	 * (prevents re-entrant construction in edge-case WordPress setups).
	 */
	private function __construct() {}

	/**
	 * Prevent cloning of the singleton.
	 *
	 * @return void
	 */
	private function __clone(): void {}

	/**
	 * Prevent unserialisation of the singleton.
	 *
	 * @throws \LogicException Always — the singleton must never be deserialised.
	 * @return void
	 */
	public function __wakeup(): void {
		throw new \LogicException( 'The Plugin singleton cannot be deserialised.' );
	}

	// ---------------------------------------------------------------------------
	// Initialisation
	// ---------------------------------------------------------------------------

	/**
	 * Run all startup logic.
	 *
	 * Executed once by get_instance() after the instance is stored.
	 * Early-exits with an admin notice if WooCommerce is not available.
	 *
	 * @return void
	 */
	private function initialize(): void {
		// Guard: WooCommerce must be active.
		if ( ! $this->is_woocommerce_active() ) {
			add_action( 'admin_notices', [ $this, 'notice_woocommerce_missing' ] );
			return;
		}

		// Guard: WooCommerce minimum version check.
		if ( ! $this->is_woocommerce_version_compatible() ) {
			add_action( 'admin_notices', [ $this, 'notice_woocommerce_version' ] );
			return;
		}

		// Load translations.
		add_action( 'init', [ $this, 'load_textdomain' ] );

		// Updater: registrato fuori da is_admin() — il cron WP non è in contesto admin.
		if ( class_exists( \WDPLP\Updater::class ) ) {
			( new \WDPLP\Updater() )->register_hooks();
		}

		// Context-based subsystem initialisation.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			// WP-CLI context: register the price-list command only.
			$this->register_cli_commands();
		} elseif ( is_admin() ) {
			// WordPress admin context: menu pages, import handler, user profile field.
			$this->register_admin_hooks();
		} else {
			// Public frontend context: price display and cart consistency.
			$this->register_frontend_hooks();
		}
	}

	// ---------------------------------------------------------------------------
	// Environment Checks
	// ---------------------------------------------------------------------------

	/**
	 * Check whether the WooCommerce plugin class is available.
	 *
	 * Relies on the WooCommerce main class being present in memory, which is
	 * guaranteed when WooCommerce is installed and active on `plugins_loaded`.
	 *
	 * @return bool True if WooCommerce is active.
	 */
	private function is_woocommerce_active(): bool {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Check whether the active WooCommerce version meets our minimum requirement.
	 *
	 * WC_VERSION is defined by WooCommerce itself; we compare it against the
	 * WDPLP_MIN_WC constant defined in the bootstrap file.
	 *
	 * @return bool True if WooCommerce meets or exceeds the required version.
	 */
	private function is_woocommerce_version_compatible(): bool {
		return defined( 'WC_VERSION' ) && version_compare( WC_VERSION, WDPLP_MIN_WC, '>=' );
	}

	// ---------------------------------------------------------------------------
	// Text Domain
	// ---------------------------------------------------------------------------

	/**
	 * Load the plugin's translation files.
	 *
	 * Hooked to `init` so that WordPress has already determined the current
	 * locale before we attempt to load the .mo file.
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			WDPLP_TEXT_DOMAIN,
			false,
			dirname( plugin_basename( WDPLP_PLUGIN_FILE ) ) . '/languages/'
		);
	}

	// ---------------------------------------------------------------------------
	// Context-specific Initialisation
	// ---------------------------------------------------------------------------

	/**
	 * Register admin-side subsystems.
	 *
	 * Instantiates each admin class only if it exists (class_exists guard).
	 * During Step 1 of the build, src/ classes are stubs — the guards prevent
	 * fatal errors while ensuring the plugin activates cleanly.
	 *
	 * @return void
	 */
	private function register_admin_hooks(): void {
		// Main admin menu and asset enqueueing.
		if ( class_exists( \WDPLP\Admin\AdminController::class ) ) {
			( new \WDPLP\Admin\AdminController() )->register_hooks();
		}

		// Import form handler (POST action + admin notice feedback).
		if ( class_exists( \WDPLP\Admin\ImportPage::class ) ) {
			( new \WDPLP\Admin\ImportPage() )->register_hooks();
		}

		// Log file download handler (GET action via admin-post.php).
		if ( class_exists( \WDPLP\Admin\LogsPage::class ) ) {
			( new \WDPLP\Admin\LogsPage() )->register_hooks();
		}

		// "Assigned Price List" dropdown on user profile pages.
		if ( class_exists( \WDPLP\User\UserProfileField::class ) ) {
			( new \WDPLP\User\UserProfileField() )->register_hooks();
		}
	}

	/**
	 * Register frontend (public-facing) subsystems.
	 *
	 * Instantiates each frontend class only if it exists (class_exists guard).
	 *
	 * @return void
	 */
	private function register_frontend_hooks(): void {
		// Public stylesheet (WooCommerce pages only).
		if ( class_exists( \WDPLP\Frontend\FrontendAssets::class ) ) {
			( new \WDPLP\Frontend\FrontendAssets() )->register_hooks();
		}

		// Price display: hides prices for guests, applies list price for users.
		if ( class_exists( \WDPLP\Frontend\PriceHooks::class ) ) {
			( new \WDPLP\Frontend\PriceHooks() )->register_hooks();
		}

		// Cart consistency: re-applies list price before totals are calculated.
		if ( class_exists( \WDPLP\Frontend\CartConsistency::class ) ) {
			( new \WDPLP\Frontend\CartConsistency() )->register_hooks();
		}
	}

	/**
	 * Register WP-CLI commands.
	 *
	 * Called only when the WP_CLI constant is defined and truthy.
	 * The command class is guarded with class_exists in case it has not been
	 * created yet during incremental development.
	 *
	 * @return void
	 */
	private function register_cli_commands(): void {
		if ( class_exists( \WDPLP\Cli\PriceListCommand::class ) ) {
			\WP_CLI::add_command( 'price-list', new \WDPLP\Cli\PriceListCommand() );
		}
	}

	// ---------------------------------------------------------------------------
	// Admin Notices
	// ---------------------------------------------------------------------------

	/**
	 * Display an admin notice when WooCommerce is not installed or active.
	 *
	 * Hooked to `admin_notices` when initialize() detects WooCommerce is absent.
	 *
	 * @return void
	 */
	public function notice_woocommerce_missing(): void {
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			wp_kses(
				sprintf(
					/* translators: %s: WooCommerce plugin name in bold */
					__( '<strong>WooCommerce Dynamic Price List Pro</strong> requires %s to be installed and active.', WDPLP_TEXT_DOMAIN ),
					'<strong>WooCommerce</strong>'
				),
				[ 'strong' => [] ]
			)
		);
	}

	/**
	 * Display an admin notice when the installed WooCommerce version is too old.
	 *
	 * Hooked to `admin_notices` when initialize() detects an incompatible
	 * WooCommerce version.
	 *
	 * @return void
	 */
	public function notice_woocommerce_version(): void {
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: 1: Plugin name, 2: Required WC version, 3: Installed WC version */
					__( '%1$s requires WooCommerce %2$s or higher. You are running WooCommerce %3$s.', WDPLP_TEXT_DOMAIN ),
					'WooCommerce Dynamic Price List Pro',
					WDPLP_MIN_WC,
					defined( 'WC_VERSION' ) ? WC_VERSION : __( 'unknown', WDPLP_TEXT_DOMAIN )
				)
			)
		);
	}
}
