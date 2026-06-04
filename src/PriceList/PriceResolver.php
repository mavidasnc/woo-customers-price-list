<?php
/**
 * Price Resolver.
 *
 * Determines the correct display price for a given product + logged-in user
 * combination by orchestrating the lookup chain:
 *
 *   1. Read the user's assigned price list code from user meta.
 *   2. Query the custom_price_lists table for a matching (sku, cod) price.
 *   3. Apply the `wdplp_final_price` filter so developers can add markups,
 *      discounts, or any further pricing logic.
 *   4. Fall back to null if no custom price exists (caller uses WC default).
 *
 * This class is the single point of responsibility for price resolution;
 * both PriceHooks (display) and CartConsistency (checkout) delegate to it.
 *
 * Caching:
 *   Results are cached in a private in-memory array for the duration of the
 *   current request. A single page load may resolve the same (user, sku) pair
 *   multiple times (e.g. loop + cart sidebar + meta box) — the cache avoids
 *   duplicate DB queries.
 *
 * Developer filter:
 *   apply_filters( 'wdplp_final_price', float $price, string $sku, int $user_id, string $cod )
 *   — Allows external code to modify the resolved price before it is used.
 *   — Return null to fall back to the WooCommerce default price.
 *
 * @package WDPLP\PriceList
 * @since   1.0.0
 */

namespace WDPLP\PriceList;

use WDPLP\User\UserProfileField;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PriceResolver
 */
class PriceResolver {

	/**
	 * Price list repository for database lookups.
	 *
	 * @var Repository
	 */
	private Repository $repository;

	/**
	 * In-memory request-scoped price cache.
	 *
	 * Key format: "{user_id}_{sku}" → float|null
	 * A null value means "checked, but no custom price found".
	 * Using null as a cached miss prevents re-querying on every call.
	 *
	 * @var array<string, float|null>
	 */
	private array $cache = [];

	// ---------------------------------------------------------------------------
	// Constructor
	// ---------------------------------------------------------------------------

	/**
	 * Initialise the resolver with an optional Repository instance.
	 *
	 * @param Repository|null $repository Optional repository for DI / testing.
	 */
	public function __construct( ?Repository $repository = null ) {
		$this->repository = $repository ?? new Repository();
	}

	// ---------------------------------------------------------------------------
	// Public API
	// ---------------------------------------------------------------------------

	/**
	 * Resolve the price for a product SKU as seen by a specific user.
	 *
	 * Resolution algorithm:
	 *   1. User must be logged in with an assigned price list (cod).
	 *   2. Look up (sku, cod) in the database.
	 *   3. Apply the `wdplp_final_price` filter.
	 *   4. Return the resolved price, or null to signal "use WC default".
	 *
	 * @param int    $user_id    WordPress user ID.
	 * @param string $sku        WooCommerce product SKU.
	 * @return float|null        Resolved price, or null if no custom price applies.
	 */
	public function get_price_for_user( int $user_id, string $sku ): ?float {
		if ( $user_id <= 0 || '' === $sku ) {
			return null;
		}

		// Check in-memory cache first.
		$cache_key = $user_id . '_' . $sku;
		if ( array_key_exists( $cache_key, $this->cache ) ) {
			return $this->cache[ $cache_key ];
		}

		// ── Step 1: get user's assigned cod ────────────────────────────────────
		$cod = $this->get_user_cod( $user_id );

		if ( null === $cod || '' === $cod ) {
			// No price list assigned — signal fallback to WC default.
			$this->cache[ $cache_key ] = null;
			return null;
		}

		// ── Step 2: database lookup ────────────────────────────────────────────
		$db_price = $this->repository->get_price_for_sku_and_cod( $sku, $cod );

		if ( null === $db_price ) {
			// SKU+COD combination not found — signal fallback to WC default.
			$this->cache[ $cache_key ] = null;
			return null;
		}

		// ── Step 3: developer filter ───────────────────────────────────────────
		/**
		 * Filter the final resolved price for a product and user.
		 *
		 * Allows external code to apply additional markups, discounts, or
		 * overrides after the price has been looked up from the database.
		 *
		 * Return null to fall back to the WooCommerce default price.
		 *
		 * @since 1.0.0
		 * @param float  $price   The resolved list price from the database.
		 * @param string $sku     The product SKU.
		 * @param int    $user_id The WordPress user ID.
		 * @param string $cod     The price list code assigned to the user.
		 */
		$filtered_price = apply_filters( 'wdplp_final_price', $db_price, $sku, $user_id, $cod );

		// Normalise: accept float or null from filter; discard other types.
		$resolved = is_numeric( $filtered_price ) ? (float) $filtered_price : null;

		// Treat zero or negative prices as "no custom price" and fall back to
		// WooCommerce default. A zero entry in the custom table is either a data
		// import error or a WooCommerce _sale_price = 0 artefact leaking through
		// an external filter — never a valid B2B list price.
		if ( null !== $resolved && $resolved <= 0 ) {
			$resolved = null;
		}

		// Cache and return.
		$this->cache[ $cache_key ] = $resolved;
		return $resolved;
	}

	/**
	 * Clear the in-memory price cache.
	 *
	 * Useful after an import run or after cart modifications to ensure fresh
	 * prices are resolved on the next lookup.
	 *
	 * @return void
	 */
	public function clear_cache(): void {
		$this->cache = [];
	}

	// ---------------------------------------------------------------------------
	// Private Helpers
	// ---------------------------------------------------------------------------

	/**
	 * Return the price list code (cod) assigned to a user, or null if none.
	 *
	 * Reads user meta key `_assigned_price_list` (defined in UserProfileField).
	 * Returns null for guests (user_id = 0) or when no list is assigned.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return string|null The assigned cod string, or null if not set.
	 */
	private function get_user_cod( int $user_id ): ?string {
		$cod = get_user_meta( $user_id, UserProfileField::META_KEY, true );

		if ( ! is_string( $cod ) || '' === $cod ) {
			return null;
		}

		return $cod;
	}
}
