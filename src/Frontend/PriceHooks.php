<?php
/**
 * Frontend Price Hooks.
 *
 * Implements the two core frontend pricing behaviours:
 *
 *   A. Guest restriction — users who are not logged in:
 *      - See no prices (woocommerce_get_price_html returns empty string).
 *      - Cannot add products to cart (woocommerce_is_purchasable returns false).
 *      - See a customisable "Login to see prices" message instead.
 *
 *   B. Per-user list price — logged-in users:
 *      - If the user has an assigned price list AND the product SKU is found in
 *        the custom table, the list price replaces the WooCommerce default.
 *      - If no custom price exists for this user/SKU combination, the
 *        WooCommerce default (_regular_price / _price) is shown as a fallback.
 *
 * Hooks used:
 *   woocommerce_get_price_html      — filter: replaces the rendered price HTML
 *                                     for simple products and individual variations.
 *   woocommerce_variable_price_html — filter: replaces the rendered price range HTML
 *                                     for variable products (parent object, no SKU).
 *                                     Without this hook, any variation with _sale_price = 0
 *                                     causes the parent to display €0,00 regardless of
 *                                     the user's assigned price list.
 *   woocommerce_is_purchasable      — filter: disables "Add to Cart" for guests.
 *
 * Design notes:
 *   - All price calculations are delegated to PriceResolver, which handles
 *     the DB lookup and the `wdplp_final_price` developer filter.
 *   - A single PriceResolver instance is shared across all hook calls in this
 *     request, so its in-memory cache is reused (avoids duplicate DB queries).
 *   - woocommerce_get_price_html is used (not woocommerce_get_price) because
 *     it controls the entire rendered price block including the <span> wrapper,
 *     currency symbol, and sale price display. This is the correct hook to use
 *     when you want to replace the full price string shown to the customer.
 *
 * @package WDPLP\Frontend
 * @since   1.0.0
 */

namespace WDPLP\Frontend;

use WDPLP\PriceList\PriceResolver;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PriceHooks
 */
class PriceHooks {

	/**
	 * Shared PriceResolver instance for this request.
	 *
	 * Created once in register_hooks() and reused across all filter calls
	 * to benefit from the resolver's in-memory cache.
	 *
	 * @var PriceResolver
	 */
	private PriceResolver $resolver;

	// ---------------------------------------------------------------------------
	// Hooks
	// ---------------------------------------------------------------------------

	/**
	 * Register all frontend price hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		$this->resolver = new PriceResolver();

		// Filter the rendered price HTML block for simple products and variations.
		// Priority 20 to run after WooCommerce's own price rendering (priority 10).
		add_filter( 'woocommerce_get_price_html', [ $this, 'filter_price_html' ], 20, 2 );

		// Filter the rendered price range HTML for variable products (parent object).
		// WC_Product_Variable::get_price_html() uses a dedicated filter rather than
		// woocommerce_get_price_html, so we must hook it separately.
		add_filter( 'woocommerce_variable_price_html', [ $this, 'filter_variable_price_html' ], 20, 2 );

		// Prevent guests from adding items to cart.
		add_filter( 'woocommerce_is_purchasable', [ $this, 'restrict_purchase_for_guests' ], 20, 2 );
	}

	// ---------------------------------------------------------------------------
	// Filters
	// ---------------------------------------------------------------------------

	/**
	 * Replace or hide the product price HTML.
	 *
	 * Guest behaviour:
	 *   Returns a "login to see prices" message (translatable, filterable).
	 *   The message is wrapped in a <span class="wdplp-login-prompt"> for styling.
	 *
	 * Logged-in user behaviour:
	 *   - Attempts to resolve a list price via PriceResolver.
	 *   - If a custom price is found, generates a fresh WooCommerce-formatted
	 *     price HTML string using wc_price() (respects currency, decimals, etc.).
	 *   - If no custom price is found, returns the original $price_html unchanged
	 *     (WooCommerce default is used as the fallback).
	 *
	 * @param string      $price_html The original WooCommerce price HTML string.
	 * @param \WC_Product $product    The product object.
	 * @return string                 The modified price HTML to display.
	 */
	public function filter_price_html( string $price_html, \WC_Product $product ): string {
		// ── A. Guest: hide prices ──────────────────────────────────────────────
		if ( ! is_user_logged_in() ) {
			return $this->get_login_prompt();
		}

		// ── B. Logged-in: attempt list price resolution ────────────────────────
		$sku = $product->get_sku();

		if ( '' === $sku ) {
			// Product has no SKU — we cannot look it up in our table.
			return $price_html;
		}

		$user_id = get_current_user_id();
		$price   = $this->resolver->get_price_for_user( $user_id, $sku );

		if ( null === $price ) {
			// No custom price found → fall back to WooCommerce default.
			return $price_html;
		}

		// Render the custom price using WooCommerce's price formatter.
		// wc_price() applies currency symbol, decimal separator, and any
		// other active WooCommerce price filters (e.g. tax display).
		return wc_price( $price );
	}

	/**
	 * Prevent guests from purchasing products.
	 *
	 * Sets `is_purchasable` to false for all products when the user is not
	 * logged in. This removes the "Add to Cart" button and prevents direct
	 * URL-based add-to-cart actions for guest users.
	 *
	 * Logged-in users are not affected — their purchasable status depends on
	 * WooCommerce's own logic (stock, visibility, etc.).
	 *
	 * @param bool        $purchasable Whether the product is currently purchasable.
	 * @param \WC_Product $product     The product object.
	 * @return bool                    Modified purchasable flag.
	 */
	public function restrict_purchase_for_guests( bool $purchasable, \WC_Product $product ): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		return $purchasable;
	}

	/**
	 * Replace or hide the variable product price range HTML.
	 *
	 * WooCommerce calls woocommerce_variable_price_html (not woocommerce_get_price_html)
	 * for WC_Product_Variable objects. The parent variable product typically has no SKU,
	 * so filter_price_html cannot resolve a listino price for it. This method iterates
	 * all visible child variations, collects their listino prices, and rebuilds the
	 * displayed range from the listino data.
	 *
	 * Without this hook, any variation with _sale_price = 0 (even an erroneous one)
	 * causes the parent to display €0,00 as part of the price range, overriding the
	 * correct listino prices entirely.
	 *
	 * Guest behaviour: returns the login prompt (same as filter_price_html).
	 * Logged-in, no listino prices found: returns $price_html unchanged (WC default).
	 * Logged-in, prices found: returns wc_price( single ) or "min – max" range.
	 *
	 * @param string               $price_html The original WooCommerce price range HTML.
	 * @param \WC_Product_Variable $product    The variable product object.
	 * @return string                          The modified price HTML to display.
	 */
	public function filter_variable_price_html( string $price_html, \WC_Product_Variable $product ): string {
		// ── A. Guest: hide prices ──────────────────────────────────────────────
		if ( ! is_user_logged_in() ) {
			return $this->get_login_prompt();
		}

		// ── B. Logged-in: collect listino prices from all visible variations ───
		$user_id = get_current_user_id();
		$prices  = [];

		foreach ( $product->get_children() as $variation_id ) {
			$variation = wc_get_product( $variation_id );

			if ( ! ( $variation instanceof \WC_Product_Variation ) ) {
				continue;
			}

			if ( ! $variation->variation_is_visible() ) {
				continue;
			}

			$sku = $variation->get_sku();

			if ( '' === $sku ) {
				continue;
			}

			$price = $this->resolver->get_price_for_user( $user_id, $sku );

			if ( null !== $price ) {
				$prices[] = $price;
			}
		}

		if ( empty( $prices ) ) {
			// No variation has a listino price — fall back to WooCommerce default range.
			return $price_html;
		}

		$min = min( $prices );
		$max = max( $prices );

		if ( $min === $max ) {
			return wc_price( $min );
		}

		return sprintf( '%s&ndash;%s', wc_price( $min ), wc_price( $max ) );
	}

	// ---------------------------------------------------------------------------
	// Private Helpers
	// ---------------------------------------------------------------------------

	/**
	 * Return the "login to see prices" HTML prompt shown to guests.
	 *
	 * The prompt text is filterable to allow theme/plugin customisation.
	 *
	 * @return string HTML string safe for output (all dynamic parts escaped).
	 */
	private function get_login_prompt(): string {
		$login_url = wp_login_url( get_permalink() );

		$message = sprintf(
			'<a href="%s">%s</a>',
			esc_url( $login_url ),
			esc_html__( 'Login to see prices', WDPLP_TEXT_DOMAIN )
		);

		/**
		 * Filter the "login to see prices" HTML shown to guest users.
		 *
		 * @since 1.0.0
		 * @param string $message   The default HTML message with login link.
		 * @param string $login_url The URL of the login page with a redirect back.
		 */
		$message = (string) apply_filters( 'wdplp_login_prompt_html', $message, $login_url );

		return sprintf(
			'<span class="wdplp-login-prompt">%s</span>',
			wp_kses(
				$message,
				[
					'a'      => [ 'href' => [], 'class' => [], 'title' => [] ],
					'span'   => [ 'class' => [] ],
					'strong' => [],
					'em'     => [],
				]
			)
		);
	}
}
