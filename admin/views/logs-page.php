<?php
/**
 * Logs Page Template — "Logs & History" Tab Content.
 *
 * This template is required by LogsPage::render_content().
 *
 * Available variables (set by the calling method):
 *   $import_history  (array)  — from Repository::get_recent_import_summary()
 *                               Each element: [import_date, sku_count, row_count]
 *   $log_files       (array)  — list of bare log filenames, newest first
 *   $selected_file   (string) — currently selected log filename (or empty string)
 *   $log_content     (string) — raw text content of the selected log file
 *   $download_url    (string) — signed download URL for the selected log file (or '')
 *
 * Security: all dynamic output is escaped with esc_html() / esc_attr() / esc_url().
 *
 * @package WDPLP
 * @since   1.0.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Ensure required variables are available.
$import_history = $import_history ?? [];
$log_files      = $log_files      ?? [];
$selected_file  = $selected_file  ?? '';
$log_content    = $log_content    ?? '';

// Compute a signed download URL for the currently selected log file.
$download_url = '';
if ( '' !== $selected_file ) {
	$download_url = wp_nonce_url(
		add_query_arg(
			[
				'action'   => 'wdplp_download_log',
				'log_file' => $selected_file,
			],
			admin_url( 'admin-post.php' )
		),
		'wdplp_download_log',
		'wdplp_download_nonce'
	);
}
?>

<?php // ── Import History Table ───────────────────────────────────────────── ?>
<div class="wdplp-card postbox">
	<div class="postbox-header">
		<h2 class="hndle"><?php esc_html_e( 'Import History', WDPLP_TEXT_DOMAIN ); ?></h2>
	</div>
	<div class="inside">

		<?php if ( empty( $import_history ) ) : ?>
			<p class="wdplp-empty-state">
				<?php esc_html_e( 'No import history found. Run your first import to see activity here.', WDPLP_TEXT_DOMAIN ); ?>
			</p>
		<?php else : ?>
			<table class="wp-list-table widefat fixed striped wdplp-history-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Import Date', WDPLP_TEXT_DOMAIN ); ?></th>
						<th class="num"><?php esc_html_e( 'Distinct SKUs', WDPLP_TEXT_DOMAIN ); ?></th>
						<th class="num"><?php esc_html_e( 'Total Rows', WDPLP_TEXT_DOMAIN ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $import_history as $row ) : ?>
						<tr>
							<td><?php echo esc_html( $row['import_date'] ); ?></td>
							<td class="num"><?php echo esc_html( number_format( (int) $row['sku_count'] ) ); ?></td>
							<td class="num"><?php echo esc_html( number_format( (int) $row['row_count'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description">
				<?php esc_html_e( 'Showing the last 30 import dates. Statistics are based on the created_at column in the price list table.', WDPLP_TEXT_DOMAIN ); ?>
			</p>
		<?php endif; ?>

	</div>
</div>

<?php // ── Log File Viewer ────────────────────────────────────────────────── ?>
<div class="wdplp-card postbox">
	<div class="postbox-header">
		<h2 class="hndle"><?php esc_html_e( 'Log File Viewer', WDPLP_TEXT_DOMAIN ); ?></h2>
	</div>
	<div class="inside">

		<?php if ( empty( $log_files ) ) : ?>
			<p class="wdplp-empty-state">
				<?php esc_html_e( 'No log files found. Log files are created automatically on the first import.', WDPLP_TEXT_DOMAIN ); ?>
			</p>
		<?php else : ?>

			<?php // ── File selector ─────────────────────────────────────── ?>
			<form method="get" action="" class="wdplp-log-selector-form">
				<input type="hidden" name="page" value="wdplp-import">
				<input type="hidden" name="tab" value="logs">

				<label for="wdplp_log_file">
					<?php esc_html_e( 'Select log file:', WDPLP_TEXT_DOMAIN ); ?>
				</label>

				<select name="log_file" id="wdplp_log_file" onchange="this.form.submit()">
					<?php foreach ( $log_files as $filename ) : ?>
						<option
							value="<?php echo esc_attr( $filename ); ?>"
							<?php selected( $selected_file, $filename ); ?>
						>
							<?php
							// Display as a clean date: "wc-price-log-2026-02-19.log" → "2026-02-19"
							$display_date = preg_replace( '/^wc-price-log-(.+)\.log$/', '$1', $filename );
							echo esc_html( $display_date ?: $filename );
							?>
						</option>
					<?php endforeach; ?>
				</select>

				<noscript>
					<button type="submit" class="button">
						<?php esc_html_e( 'Load', WDPLP_TEXT_DOMAIN ); ?>
					</button>
				</noscript>
			</form>

			<?php // ── Log file content ─────────────────────────────────── ?>
			<?php if ( '' !== $selected_file ) : ?>
				<div class="wdplp-log-meta">
					<span class="wdplp-log-filename">
						<?php echo esc_html( $selected_file ); ?>
					</span>
					<?php if ( '' !== $download_url ) : ?>
						<a
							href="<?php echo esc_url( $download_url ); ?>"
							class="button button-small wdplp-download-log"
						>
							<?php esc_html_e( 'Download', WDPLP_TEXT_DOMAIN ); ?>
						</a>
					<?php endif; ?>
					<?php if ( '' !== $log_content ) : ?>
						<span class="wdplp-log-lines">
							—
							<?php
							printf(
								/* translators: %d: number of lines shown */
								esc_html__( '%d lines shown', WDPLP_TEXT_DOMAIN ),
								substr_count( $log_content, "\n" ) + 1
							);
							?>
						</span>
					<?php endif; ?>
				</div>

				<?php if ( '' === $log_content ) : ?>
					<p class="wdplp-empty-state">
						<?php esc_html_e( 'This log file is empty or could not be read.', WDPLP_TEXT_DOMAIN ); ?>
					</p>
				<?php else : ?>
					<pre class="wdplp-log-content"><?php echo esc_html( $log_content ); ?></pre>
				<?php endif; ?>
			<?php endif; ?>

		<?php endif; ?>

	</div>
</div>
