<?php
/**
 * Data Page Template — "Data" Tab Content.
 *
 * Read-only view of the custom_price_lists table with SKU/COD filters
 * and pagination.
 *
 * Available variables (set by DataPage::render_content()):
 *   $rows         (array)  — Paginated rows from Repository::get_rows_paginated().
 *   $total_rows   (int)    — Total matching rows (for pagination info).
 *   $total_pages  (int)    — Total page count.
 *   $current_page (int)    — Currently viewed page number.
 *   $per_page     (int)    — Rows per page.
 *   $filter_sku   (string) — Current SKU filter value.
 *   $filter_cod   (string) — Current COD filter value.
 *   $all_cods     (array)  — All distinct active COD values (for dropdown).
 *   $base_url     (string) — Base URL of the data tab (without filters/paging).
 *
 * Security: all dynamic output is escaped.
 *
 * @package WCPL
 * @since   1.0.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Ensure required variables have safe defaults.
$rows         = $rows ?? [];
$total_rows   = $total_rows ?? 0;
$total_pages  = $total_pages ?? 1;
$current_page = $current_page ?? 1;
$per_page     = $per_page ?? 50;
$filter_sku   = $filter_sku ?? '';
$filter_cod   = $filter_cod ?? '';
$all_cods     = $all_cods ?? [];
$base_url     = $base_url ?? '';

/**
 * Build a pagination URL preserving the active filters.
 *
 * @param int    $page     Page number.
 * @param string $base     Base URL (tab=data).
 * @param string $sku      Current SKU filter.
 * @param string $cod      Current COD filter.
 * @return string Escaped URL.
 */
$paged_url = static function ( int $page, string $base, string $sku, string $cod ): string {
	$args = [ 'paged' => $page ];
	if ( '' !== $sku ) {
		$args['filter_sku'] = $sku;
	}
	if ( '' !== $cod ) {
		$args['filter_cod'] = $cod;
	}
	return esc_url( add_query_arg( $args, $base ) );
};

// Offset of first row on this page (1-based, for display).
$first_row_offset = ( $current_page - 1 ) * $per_page + 1;
?>

<?php // ── Filter form ──────────────────────────────────────────────────────── ?>
<div class="wcpl-card postbox">
	<div class="postbox-header">
		<h2 class="hndle"><?php esc_html_e( 'Filter Price List Data', WCPL_TEXT_DOMAIN ); ?></h2>
	</div>
	<div class="inside">
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" id="wcpl-data-filter-form">
			<?php // Preserve the page and tab parameters. ?>
			<input type="hidden" name="page" value="<?php echo esc_attr( \WCPL\Admin\AdminController::PAGE_SLUG ); ?>">
			<input type="hidden" name="tab"  value="data">

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="filter_sku"><?php esc_html_e( 'SKU', WCPL_TEXT_DOMAIN ); ?></label>
					</th>
					<td>
						<input
							type="text"
							name="filter_sku"
							id="filter_sku"
							value="<?php echo esc_attr( $filter_sku ); ?>"
							class="regular-text"
							placeholder="<?php esc_attr_e( 'Partial SKU (e.g. PROD-0)', WCPL_TEXT_DOMAIN ); ?>"
						>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="filter_cod"><?php esc_html_e( 'Price List Code', WCPL_TEXT_DOMAIN ); ?></label>
					</th>
					<td>
						<select name="filter_cod" id="filter_cod">
							<option value=""><?php esc_html_e( '— All codes —', WCPL_TEXT_DOMAIN ); ?></option>
							<?php foreach ( $all_cods as $cod ) : ?>
								<option
									value="<?php echo esc_attr( $cod ); ?>"
									<?php selected( $filter_cod, $cod ); ?>
								>
									<?php echo esc_html( $cod ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
			</table>

			<p class="submit">
				<button type="submit" class="button button-primary">
					<?php esc_html_e( 'Apply Filters', WCPL_TEXT_DOMAIN ); ?>
				</button>
				<?php if ( '' !== $filter_sku || '' !== $filter_cod ) : ?>
					<a href="<?php echo esc_url( $base_url ); ?>" class="button button-secondary">
						<?php esc_html_e( 'Reset', WCPL_TEXT_DOMAIN ); ?>
					</a>
				<?php endif; ?>
			</p>
		</form>
	</div>
</div>

<?php // ── Data table ───────────────────────────────────────────────────────── ?>
<div class="wcpl-card postbox">
	<div class="postbox-header">
		<h2 class="hndle">
			<?php
			printf(
				/* translators: %d: total number of matching rows */
				esc_html__( 'Price List Rows (%d total)', WCPL_TEXT_DOMAIN ),
				(int) $total_rows
			);
			?>
		</h2>
	</div>
	<div class="inside">

		<?php if ( empty( $rows ) ) : ?>
			<p class="wcpl-no-data">
				<?php
				if ( '' !== $filter_sku || '' !== $filter_cod ) {
					esc_html_e( 'No rows match the current filters.', WCPL_TEXT_DOMAIN );
				} else {
					esc_html_e( 'No price list data found. Import a CSV file to get started.', WCPL_TEXT_DOMAIN );
				}
				?>
			</p>
		<?php else : ?>

			<?php // ── Pagination info bar ──────────────────────────────────── ?>
			<div class="wcpl-pagination-bar">
				<span class="wcpl-pagination-info">
					<?php
					$last_row_offset = min( $first_row_offset + $per_page - 1, $total_rows );
					printf(
						/* translators: 1: first row number, 2: last row number, 3: total rows */
						esc_html__( 'Showing %1$d–%2$d of %3$d rows', WCPL_TEXT_DOMAIN ),
						(int) $first_row_offset,
						(int) $last_row_offset,
						(int) $total_rows
					);
					?>
				</span>

				<?php if ( $total_pages > 1 ) : ?>
					<span class="wcpl-pagination-links">
						<?php if ( $current_page > 1 ) : ?>
							<a href="<?php echo $paged_url( 1, $base_url, $filter_sku, $filter_cod ); ?>" class="button button-secondary button-small">
								&laquo; <?php esc_html_e( 'First', WCPL_TEXT_DOMAIN ); ?>
							</a>
							<a href="<?php echo $paged_url( $current_page - 1, $base_url, $filter_sku, $filter_cod ); ?>" class="button button-secondary button-small">
								&lsaquo; <?php esc_html_e( 'Prev', WCPL_TEXT_DOMAIN ); ?>
							</a>
						<?php endif; ?>

						<span class="wcpl-page-indicator">
							<?php
							printf(
								/* translators: 1: current page, 2: total pages */
								esc_html__( 'Page %1$d of %2$d', WCPL_TEXT_DOMAIN ),
								(int) $current_page,
								(int) $total_pages
							);
							?>
						</span>

						<?php if ( $current_page < $total_pages ) : ?>
							<a href="<?php echo $paged_url( $current_page + 1, $base_url, $filter_sku, $filter_cod ); ?>" class="button button-secondary button-small">
								<?php esc_html_e( 'Next', WCPL_TEXT_DOMAIN ); ?> &rsaquo;
							</a>
							<a href="<?php echo $paged_url( $total_pages, $base_url, $filter_sku, $filter_cod ); ?>" class="button button-secondary button-small">
								<?php esc_html_e( 'Last', WCPL_TEXT_DOMAIN ); ?> &raquo;
							</a>
						<?php endif; ?>
					</span>
				<?php endif; ?>
			</div>

			<?php // ── Main data table ──────────────────────────────────────── ?>
			<table class="widefat fixed striped wcpl-data-table">
				<thead>
					<tr>
						<th class="wcpl-col-id"><?php esc_html_e( 'ID', WCPL_TEXT_DOMAIN ); ?></th>
						<th class="wcpl-col-sku"><?php esc_html_e( 'SKU', WCPL_TEXT_DOMAIN ); ?></th>
						<th class="wcpl-col-cod"><?php esc_html_e( 'Code', WCPL_TEXT_DOMAIN ); ?></th>
						<th class="wcpl-col-price"><?php esc_html_e( 'Price', WCPL_TEXT_DOMAIN ); ?></th>
						<th class="wcpl-col-date"><?php esc_html_e( 'Valid From', WCPL_TEXT_DOMAIN ); ?></th>
						<th class="wcpl-col-created"><?php esc_html_e( 'Imported At', WCPL_TEXT_DOMAIN ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td class="wcpl-col-id"><?php echo esc_html( (int) $row['id'] ); ?></td>
							<td class="wcpl-col-sku"><code><?php echo esc_html( $row['sku'] ); ?></code></td>
							<td class="wcpl-col-cod">
								<a href="<?php echo $paged_url( 1, $base_url, $filter_sku, $row['cod'] ); ?>">
									<?php echo esc_html( $row['cod'] ); ?>
								</a>
							</td>
							<td class="wcpl-col-price"><?php echo esc_html( number_format( (float) $row['price'], 2, '.', '' ) ); ?></td>
							<td class="wcpl-col-date"><?php echo esc_html( $row['date_from'] ); ?></td>
							<td class="wcpl-col-created"><?php echo esc_html( $row['created_at'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php // ── Bottom pagination links (repeat for convenience) ─────── ?>
			<?php if ( $total_pages > 1 ) : ?>
				<div class="wcpl-pagination-bar wcpl-pagination-bar--bottom">
					<?php if ( $current_page > 1 ) : ?>
						<a href="<?php echo $paged_url( 1, $base_url, $filter_sku, $filter_cod ); ?>" class="button button-secondary button-small">
							&laquo; <?php esc_html_e( 'First', WCPL_TEXT_DOMAIN ); ?>
						</a>
						<a href="<?php echo $paged_url( $current_page - 1, $base_url, $filter_sku, $filter_cod ); ?>" class="button button-secondary button-small">
							&lsaquo; <?php esc_html_e( 'Prev', WCPL_TEXT_DOMAIN ); ?>
						</a>
					<?php endif; ?>

					<span class="wcpl-page-indicator">
						<?php
						printf(
							/* translators: 1: current page, 2: total pages */
							esc_html__( 'Page %1$d of %2$d', WCPL_TEXT_DOMAIN ),
							(int) $current_page,
							(int) $total_pages
						);
						?>
					</span>

					<?php if ( $current_page < $total_pages ) : ?>
						<a href="<?php echo $paged_url( $current_page + 1, $base_url, $filter_sku, $filter_cod ); ?>" class="button button-secondary button-small">
							<?php esc_html_e( 'Next', WCPL_TEXT_DOMAIN ); ?> &rsaquo;
						</a>
						<a href="<?php echo $paged_url( $total_pages, $base_url, $filter_sku, $filter_cod ); ?>" class="button button-secondary button-small">
							<?php esc_html_e( 'Last', WCPL_TEXT_DOMAIN ); ?> &raquo;
						</a>
					<?php endif; ?>
				</div>
			<?php endif; ?>

		<?php endif; // empty $rows ?>

	</div><!-- .inside -->
</div><!-- .postbox -->
