<?php
/**
 * Import Page Template — "Import" Tab Content.
 *
 * This template is required by ImportPage::render_content().
 *
 * Available variables (set by the calling method):
 *   $result      (array)  — ImportReport::to_array() from a previous import,
 *                           or an empty array if no import has run yet.
 *   $action_url  (string) — Escaped URL of admin-post.php.
 *   $nonce       (string) — Nonce value for the hidden nonce field.
 *   $last_source (string) — Last used source: 'file' or 'url' (from user meta).
 *   $last_url    (string) — Last used remote URL (from user meta).
 *   $last_limit  (int)    — Last used row limit (from user meta).
 *
 * Security: all dynamic output is escaped with esc_html() / esc_attr() / esc_url().
 *
 * @since   1.0.0
 */

// Prevent direct access.
if (!defined('ABSPATH')) {
    exit;
}

// Ensure required variables are available.
$result      = $result      ?? [];
$action_url  = $action_url  ?? esc_url( admin_url( 'admin-post.php' ) );
// Per-user import preferences (populated by ImportPage::render_content).
$last_source = $last_source ?? 'file';
$last_url    = $last_url    ?? '';
$last_limit  = $last_limit  ?? 0;
?>

<?php // ── Import result notice ───────────────────────────────────────────?>
<?php if (!empty($result)) { ?>
	<?php
    $has_errors = !empty($result['error_count']) && $result['error_count'] > 0;
    $has_skus_miss = !empty($result['skus_not_found_count']) && $result['skus_not_found_count'] > 0;
    $notice_class = $has_errors ? 'notice-error' : ($has_skus_miss ? 'notice-warning' : 'notice-success');
    ?>
	<div class="notice <?php echo esc_attr($notice_class); ?> wdplp-import-notice">

		<h3><?php esc_html_e('Import Completed', WDPLP_TEXT_DOMAIN); ?></h3>

		<table class="wdplp-stats-table">
			<tbody>
				<tr>
					<th><?php esc_html_e('Total rows processed', WDPLP_TEXT_DOMAIN); ?></th>
					<td><strong><?php echo esc_html((int) ($result['total_rows'] ?? 0)); ?></strong></td>
				</tr>
				<tr>
					<th><?php esc_html_e('WooCommerce prices updated', WDPLP_TEXT_DOMAIN); ?></th>
					<td><strong><?php echo esc_html((int) ($result['wc_prices_updated'] ?? 0)); ?></strong></td>
				</tr>
				<tr>
					<th><?php esc_html_e('Price list rows inserted/updated', WDPLP_TEXT_DOMAIN); ?></th>
					<td><strong><?php echo esc_html((int) ($result['pricelist_rows_upserted'] ?? 0)); ?></strong></td>
				</tr>
				<tr>
					<th><?php esc_html_e('Rows skipped', WDPLP_TEXT_DOMAIN); ?></th>
					<td><?php echo esc_html((int) ($result['rows_skipped'] ?? 0)); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e('SKUs not found in WooCommerce', WDPLP_TEXT_DOMAIN); ?></th>
					<td>
						<?php echo esc_html((int) ($result['skus_not_found_count'] ?? 0)); ?>
						<?php
                            // If there are missing SKUs, show a list of them in parentheses.
                            if ($has_skus_miss) { ?>
							<span class="wdplp-sku-list">
								<?php // echo esc_html(implode(', ', (array) $result['skus_not_found']));?>
							</span>
						<?php } ?>
					</td>
				</tr>
				<?php if (!empty($result['elapsed_seconds'])) { ?>
				<tr>
					<th><?php esc_html_e('Duration', WDPLP_TEXT_DOMAIN); ?></th>
					<td><?php echo esc_html(number_format((float) $result['elapsed_seconds'], 2)); ?>s</td>
				</tr>
				<?php } ?>
			</tbody>
		</table>

		<?php // Show individual errors/warnings if any.?>
		<?php if (!empty($result['errors'])) { ?>
			<details class="wdplp-error-details">
				<summary>
					<?php
                    printf(
                        /* translators: %d: number of errors/warnings */
                        esc_html__('View %d error/warning message(s)', WDPLP_TEXT_DOMAIN),
                        count((array) $result['errors'])
                    );
		    ?>
				</summary>
				<ul>
					<?php foreach ((array) $result['errors'] as $err) { ?>
						<li>
							<?php if (!empty($err['row'])) { ?>
								<strong><?php printf(esc_html__('Row %d:', WDPLP_TEXT_DOMAIN), (int) $err['row']); ?></strong>
							<?php } ?>
							<span class="wdplp-err-<?php echo esc_attr($err['level'] ?? 'error'); ?>">
								<?php echo esc_html($err['message'] ?? ''); ?>
							</span>
						</li>
					<?php } ?>
				</ul>
			</details>
		<?php } ?>

	</div>
<?php } ?>

<?php // ── Import form ──────────────────────────────────────────────────────?>
<div class="wdplp-card postbox">
	<div class="postbox-header">
		<h2 class="hndle"><?php esc_html_e('Import Price List CSV', WDPLP_TEXT_DOMAIN); ?></h2>
	</div>

	<div class="inside">

		<?php // CSV format hint.?>
		<p class="description">
			<?php
		    esc_html_e(
		        'Upload a CSV file or provide a URL. The CSV must include a SKU column and a LORDO column. All other columns (e.g. L40, L45, L50N1) are treated as price list codes.',
		        WDPLP_TEXT_DOMAIN
		    );
?>
		</p>
		<p class="description">
			<?php esc_html_e('Example header row:', WDPLP_TEXT_DOMAIN); ?>
			<code>SKU,LORDO,L40,L45,L50N1</code>
		</p>

		<hr>

		<form
			id="wdplp-import-form"
			method="post"
			action="<?php echo esc_url($action_url); ?>"
			enctype="multipart/form-data"
		>
			<?php // WordPress nonce field for CSRF protection.?>
			<?php wp_nonce_field('wdplp_import', 'wdplp_import_nonce'); ?>
			<input type="hidden" name="action" value="wdplp_import">

			<?php // ── Source toggle ───────────────────────────────────────?>
			<fieldset class="wdplp-source-fieldset">
				<legend><?php esc_html_e('Import source', WDPLP_TEXT_DOMAIN); ?></legend>

				<label class="wdplp-radio-label">
					<input
						type="radio"
						name="wdplp_source"
						value="file"
						id="wdplp_source_file"
						<?php checked( $last_source, 'file' ); ?>
					>
					<?php esc_html_e('Upload a file from your computer', WDPLP_TEXT_DOMAIN); ?>
				</label>

				<label class="wdplp-radio-label">
					<input
						type="radio"
						name="wdplp_source"
						value="url"
						id="wdplp_source_url"
						<?php checked( $last_source, 'url' ); ?>
					>
					<?php esc_html_e('Import from a remote URL', WDPLP_TEXT_DOMAIN); ?>
				</label>
			</fieldset>

			<?php // ── File upload section ───────────────────────────────?>
			<div id="wdplp-file-section" class="wdplp-input-section">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="wdplp_csv_file">
								<?php esc_html_e('CSV File', WDPLP_TEXT_DOMAIN); ?>
							</label>
						</th>
						<td>
							<input
								type="file"
								name="wdplp_csv_file"
								id="wdplp_csv_file"
								accept=".csv,.txt,text/csv,text/plain"
								class="wdplp-file-input"
							>
							<p class="description">
								<?php esc_html_e('Accepted formats: .csv, .txt (UTF-8 or Windows-1252 encoding, comma or semicolon delimiter).', WDPLP_TEXT_DOMAIN); ?>
							</p>
						</td>
					</tr>
				</table>
			</div>

			<?php // ── URL section (hidden by default) ─────────────────────?>
			<div id="wdplp-url-section" class="wdplp-input-section" style="display:none;">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="wdplp_url">
								<?php esc_html_e('CSV URL', WDPLP_TEXT_DOMAIN); ?>
							</label>
						</th>
						<td>
							<input
								type="url"
								name="wdplp_url"
								id="wdplp_url"
								class="regular-text"
								placeholder="https://example.com/prices.csv"
								value="<?php echo esc_attr( $last_url ); ?>"
							>
							<p class="description">
								<?php esc_html_e('The URL must be publicly accessible. HTTPS is strongly recommended.', WDPLP_TEXT_DOMAIN); ?>
							</p>
						</td>
					</tr>
				</table>
			</div>

			<?php // ── Row limit ───────────────────────────────────────────?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="wdplp_limit_rows">
							<?php esc_html_e('Row limit', WDPLP_TEXT_DOMAIN); ?>
						</label>
					</th>
					<td>
						<input
							type="number"
							name="wdplp_limit_rows"
							id="wdplp_limit_rows"
							value="<?php echo esc_attr( $last_limit ); ?>"
							min="0"
							step="1"
							class="small-text"
						>
						<p class="description">
							<?php esc_html_e('Maximum number of CSV rows to import (0 = no limit). Useful for testing a portion of a large file.', WDPLP_TEXT_DOMAIN); ?>
						</p>
					</td>
				</tr>
			</table>

			<?php // ── Submit ──────────────────────────────────────────────?>
			<p class="submit">
				<button
					type="submit"
					id="wdplp-import-btn"
					class="button button-primary button-large"
				>
					<?php esc_html_e('Import Price List', WDPLP_TEXT_DOMAIN); ?>
				</button>
				<span class="wdplp-spinner spinner" aria-hidden="true"></span>
			</p>

		</form>

	</div><!-- .inside -->
</div><!-- .postbox -->

<?php // ── CSV format reference card ────────────────────────────────────────?>
<div class="wdplp-card postbox">
	<div class="postbox-header">
		<h2 class="hndle"><?php esc_html_e('CSV Format Reference', WDPLP_TEXT_DOMAIN); ?></h2>
	</div>
	<div class="inside">
		<table class="widefat fixed wdplp-example-table">
			<thead>
				<tr>
					<th>SKU</th>
					<th>LORDO</th>
					<th>L40</th>
					<th>L45</th>
					<th>L50N1</th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td>PROD-001</td>
					<td>100.00</td>
					<td>85.00</td>
					<td>80.00</td>
					<td>75.00</td>
				</tr>
				<tr>
					<td>PROD-002</td>
					<td>200.00</td>
					<td>170.00</td>
					<td>160.00</td>
					<td></td>
				</tr>
			</tbody>
		</table>
		<ul class="wdplp-legend">
			<li>
				<strong>SKU</strong> —
				<?php esc_html_e('WooCommerce product SKU. Case-insensitive column header.', WDPLP_TEXT_DOMAIN); ?>
			</li>
			<li>
				<strong>LORDO</strong> —
				<?php esc_html_e('Gross price. Updates WooCommerce regular price. Case-insensitive.', WDPLP_TEXT_DOMAIN); ?>
			</li>
			<li>
				<strong>L40, L45, L50N1…</strong> —
				<?php esc_html_e('Price list codes. Any other column header is stored as a list code. Empty cells are skipped.', WDPLP_TEXT_DOMAIN); ?>
			</li>
		</ul>
	</div>
</div>
