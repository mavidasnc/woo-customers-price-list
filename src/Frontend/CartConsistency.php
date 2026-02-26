<?php
/**
 * Cart Price Consistency.
 *
 * Ensures that the user's list price is applied inside the WooCommerce cart
 * and at checkout, not just on the product display pages.
 *
 * Without this class, WooCommerce stores the price at the moment the item
 * was added to the cart. If the user is then assigned a different price list,
 * or if the admin imports a new CSV, the cart total would still reflect the
 * old cached price.
 *
 * How it works:
 *   - Hooks `woocommerce_before_calculate_totals` (fires on every cart
 *     recalculation, including on the cart page, mini-cart AJAX, and checkout).
 *   - Iterates over every cart item, retrieves the product's SKU, and asks
 *     PriceResolver for the current list price for the logged-in user.
 *   - If a custom price is found, calls $item['data']->set_price() to override
 *     the price for this recalculation cycle.
 *   - If no custom price is found (no price list assigned, SKU missing, etc.),
 *     the WooCommerce default price is left untouched.
 *
 * Design notes:
 *   - set_price() is a non-persistent setter — it changes the price only for
 *     the current request's cart calculation. The underlying product meta is
 *     never modified by this class.
 *   - A single PriceResolver instance is shared across the hook call for
 *     cache efficiency (avoid duplicate DB queries when a SKU appears in
 *     multiple cart items or is called more than once per request).
 *   - Guest users are skipped entirely (they have no price list and cannot
 *     add to cart in the first place, thanks to PriceHooks).
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
 * Class CartConsistency
 */
class CartConsistency {

	/**
	 * Shared PriceResolver instance for this request.
	 *
	 * Created once in register_hooks() and reused across all hook calls
	 * to benefit from the resolver's in-memory cache.
	 *
	 * @var PriceResolver
	 */
	private PriceResolver $resolver;

	// ---------------------------------------------------------------------------
	// Hooks
	// ---------------------------------------------------------------------------

	/**
	 * Register all cart-consistency hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		$this->resolver = new PriceResolver();

		// Re-apply list prices before WooCommerce calculates cart totals.
		// Priority 20 ensures we run after any other plugin that might set a
		// base price (e.g. role-based pricing plugins at priority 10–15).
		add_action( 'woocommerce_before_calculate_totals', [ $this, 'apply_list_prices_to_cart' ], 20, 1 );
	}

	// ---------------------------------------------------------------------------
	// Callbacks
	// ---------------------------------------------------------------------------

	/**
	 * Apply the user's list price to every applicable item in the cart.
	 *
	 * Runs before WooCommerce recalculates cart totals. For each item whose
	 * product has a SKU, the resolver is queried. If a custom price is found,
	 * it is set on the cart item's product object for this calculation cycle.
	 *
	 * @param \WC_Cart $cart The WooCommerce cart object.
	 * @return void
	 */
	public function apply_list_prices_to_cart( \WC_Cart $cart ): void {
		// Skip for guests — they cannot have a price list.
		if ( ! is_user_logged_in() ) {
			return;
		}

		// is_empty() check avoids iterating an empty cart on every page load.
		if ( $cart->is_empty() ) {
			return;
		}

		$user_id = get_current_user_id();

		foreach ( $cart->get_cart() as $cart_item ) {
			/** @var \WC_Product $product */
			$product = $cart_item['data'] ?? null;

			if ( ! ( $product instanceof \WC_Product ) ) {
				continue;
			}

			$sku = $product->get_sku();

			if ( '' === $sku ) {
				// Product has no SKU — cannot look it up in the price table.
				continue;
			}

			$price = $this->resolver->get_price_for_user( $user_id, $sku );

			if ( null === $price ) {
				// No custom price for this user/SKU — leave WooCommerce default.
				continue;
			}

			// Override price for this calculation cycle only.
			// set_price() is non-persistent: it affects only the in-memory
			// WC_Product object attached to this cart item.
			$product->set_price( $price );
		}
	}
}
