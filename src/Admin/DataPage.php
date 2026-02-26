<?php
/**
 * Data Page — Price List Table Viewer.
 *
 * Renders the "Data" tab: a paginated, filterable read-only view of the
 * `custom_price_lists` database table.
 *
 * Filters (via GET parameters):
 *   - `filter_sku` (string) — partial SKU match (LIKE %value%).
 *   - `filter_cod` (string) — exact COD match.
 *   - `paged`      (int)    — current page number (default: 1).
 *
 * Security:
 *   - All GET values are sanitised before use.
 *   - All output is escaped with esc_html() / esc_attr() / esc_url().
 *   - No write operations; capability check inherited from AdminController
 *     (page requires `manage_woocommerce`).
 *
 * @package WDPLP\Admin
 * @since   1.0.0
 */

namespace WDPLP\Admin;

use WDPLP\PriceList\Repository;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class DataPage
 */
class DataPage {

	/**
	 * Number of rows displayed per page.
	 *
	 * @var int
	 */
	private const PER_PAGE = 50;

	// ---------------------------------------------------------------------------
	// Rendering
	// ---------------------------------------------------------------------------

	/**
	 * Render the data tab content.
	 *
	 * Reads filter/paging parameters from $_GET, queries the repository,
	 * and delegates HTML output to the view template.
	 *
	 * @return void
	 */
	public function render_content(): void {
		// ── Read and sanitise GET parameters ──────────────────────────────────
		$filter_sku = isset( $_GET['filter_sku'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			? sanitize_text_field( wp_unslash( $_GET['filter_sku'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			: '';

		$filter_cod = isset( $_GET['filter_cod'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			? sanitize_key( wp_unslash( $_GET['filter_cod'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			: '';

		$current_page = isset( $_GET['paged'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			? max( 1, (int) $_GET['paged'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			: 1;

		// ── Query data ────────────────────────────────────────────────────────
		$repository = new Repository();
		$rows       = $repository->get_rows_paginated( $current_page, self::PER_PAGE, $filter_sku, $filter_cod );
		$total_rows = $repository->count_rows_filtered( $filter_sku, $filter_cod );
		$all_cods   = $repository->get_distinct_cods();

		// ── Pagination calculations ───────────────────────────────────────────
		$total_pages = $total_rows > 0 ? (int) ceil( $total_rows / self::PER_PAGE ) : 1;
		$current_page = min( $current_page, $total_pages );

		// ── Pass variables to template ────────────────────────────────────────
		$per_page    = self::PER_PAGE;
		$base_url    = AdminController::get_page_url( 'data' );

		require WDPLP_PLUGIN_DIR . 'admin/views/data-page.php';
	}
}
