<?php
/**
 * Frontend Assets — Public stylesheet enqueue.
 *
 * Loads the plugin's public CSS only on WooCommerce pages
 * (shop, single product, product taxonomy pages, cart, checkout).
 *
 * This class is intentionally separate from PriceHooks so that styling
 * can be added or removed without touching the pricing logic.
 *
 * @package WDPLP\Frontend
 * @since   1.3.0
 */

namespace WDPLP\Frontend;

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

	/**
	 * Enqueue the plugin's public stylesheet.
	 *
	 * Only runs on WooCommerce pages. No dependency on 'woocommerce-general'
	 * is declared because some themes deregister that handle, which would
	 * silently prevent this stylesheet from being output. Our classes are
	 * standalone and do not override WooCommerce core styles.
	 *
	 * @return void
	 */
	public function enqueue_assets(): void {
		if ( ! $this->is_woocommerce_page() ) {
			return;
		}

		wp_enqueue_style(
			'wdplp-public',
			WDPLP_PLUGIN_URL . 'public/css/wdplp-public.css',
			[],
			WDPLP_VERSION
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
