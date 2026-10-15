<?php
/**
 * Frontend Assets — Public stylesheet and script enqueue.
 *
 * Loads the plugin's public CSS and JS only on WooCommerce pages
 * (shop, single product, product taxonomy pages, cart, checkout).
 *
 * Assets loaded:
 *   - Select2 CSS + JS (local vendor copy, v4.1.0-rc.0)
 *   - Select2 Italian locale
 *   - wcpl-public.css (custom plugin styles, e.g. .uc-filter-num)
 *   - wcpl-public.js (Select2 init on #sidebar select)
 *
 * This class is intentionally separate from PriceHooks so that styling
 * can be added or removed without touching the pricing logic.
 *
 * @package WCPL\Frontend
 * @since   1.3.0
 */

namespace WCPL\Frontend;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class FrontendAssets
 */
class FrontendAssets {

	// -------------------------------------------------------------------------
	// Hooks
	// -------------------------------------------------------------------------

	/**
	 * Register all WordPress hooks needed by this class.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	// -------------------------------------------------------------------------
	// Asset Enqueue
	// -------------------------------------------------------------------------

	/** Select2 version shipped with this plugin. */
	private const SELECT2_VERSION = '4.1.0-rc.0';

	/**
	 * Enqueue the plugin's public assets (CSS and JS).
	 *
	 * Only runs on WooCommerce pages. No dependency on 'woocommerce-general'
	 * is declared because some themes deregister that handle, which would
	 * silently prevent this stylesheet from being output. Our classes are
	 * standalone and do not override WooCommerce core styles.
	 *
	 * Load order:
	 *   1. Select2 CSS
	 *   2. wcpl-public CSS (plugin custom styles)
	 *   3. Select2 JS  (depends on jquery, already enqueued by WordPress)
	 *   4. Select2 Italian locale (depends on Select2 JS)
	 *   5. wcpl-public JS — init script (depends on Select2 JS + locale)
	 *
	 * @return void
	 */
	public function enqueue_assets(): void {
		if ( ! $this->is_woocommerce_page() ) {
			return;
		}

		$vendor = WCPL_PLUGIN_URL . 'public/vendor/select2/';

		// ── Select2 CSS ───────────────────────────────────────────────────────
		wp_enqueue_style(
			'wcpl-select2',
			$vendor . 'select2.min.css',
			[],
			self::SELECT2_VERSION
		);

		// ── Plugin custom CSS ─────────────────────────────────────────────────
		wp_enqueue_style(
			'wcpl-public',
			WCPL_PLUGIN_URL . 'public/css/wcpl-public.css',
			[],
			WCPL_VERSION
		);

		// ── Select2 JS (requires jQuery, globally available in WordPress) ─────
		wp_enqueue_script(
			'wcpl-select2',
			$vendor . 'select2.min.js',
			[ 'jquery' ],
			self::SELECT2_VERSION,
			true   // load in footer
		);

		// ── Select2 Italian locale ────────────────────────────────────────────
		wp_enqueue_script(
			'wcpl-select2-i18n-it',
			$vendor . 'i18n/it.js',
			[ 'wcpl-select2' ],
			self::SELECT2_VERSION,
			true
		);

		// ── Plugin init script ────────────────────────────────────────────────
		wp_enqueue_script(
			'wcpl-public',
			WCPL_PLUGIN_URL . 'public/js/wcpl-public.js',
			[ 'wcpl-select2', 'wcpl-select2-i18n-it' ],
			WCPL_VERSION,
			true
		);
	}

	// -------------------------------------------------------------------------
	// Private Helpers
	// -------------------------------------------------------------------------

	/**
	 * Check whether the current request is for a WooCommerce page.
	 *
	 * is_woocommerce() covers: shop archive, single product, product category/tag.
	 * is_cart() and is_checkout() cover the transactional pages.
	 *
	 * The function_exists() guard prevents fatal errors if WooCommerce is not
	 * active (although Plugin::initialize() already checks WC version).
	 *
	 * @return bool True if the current page is a WooCommerce storefront page.
	 */
	private function is_woocommerce_page(): bool {
		return function_exists( 'is_woocommerce' )
			&& ( is_woocommerce() || is_cart() || is_checkout() );
	}
}
