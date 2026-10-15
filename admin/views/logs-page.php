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
 * @package WCPL
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
				'action'   => 'wcpl_download_log',
				'log_file' => $selected_file,
			],
			admin_url( 'admin-post.php' )
		),
		'wcpl_download_log',
		'wcpl_download_nonce'
	);
}
?>

<?php // ── Import History Table ───────────────────────────────────────────── ?>
<div class="wcpl-card postbox">
	<div class="postbox-header">
		<h2 class="hndle"><?php esc_html_e( 'Import History', WCPL_TEXT_DOMAIN ); ?></h2>
	</div>
	<div class="inside">

		<?php if ( empty( $import_history ) ) : ?>
			<p class="wcpl-empty-state">
				<?php esc_html_e( 'No import history found. Run your first import to see activity here.', WCPL_TEXT_DOMAIN ); ?>
			</p>
		<?php else : ?>
			<table class="wp-list-table widefat fixed striped wcpl-history-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Import Date', WCPL_TEXT_DOMAIN ); ?></th>
						<th class="num"><?php esc_html_e( 'Distinct SKUs', WCPL_TEXT_DOMAIN ); ?></th>
						<th class="num"><?php esc_html_e( 'Total Rows', WCPL_TEXT_DOMAIN ); ?></th>
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
				<?php esc_html_e( 'Showing the last 30 import dates. Statistics are based on the created_at column in the price list table.', WCPL_TEXT_DOMAIN ); ?>
			</p>
		<?php endif; ?>

	</div>
</div>

<?php // ── Log File Viewer ────────────────────────────────────────────────── ?>
<div class="wcpl-card postbox">
	<div class="postbox-header">
		<h2 class="hndle"><?php esc_html_e( 'Log File Viewer', WCPL_TEXT_DOMAIN ); ?></h2>
	</div>
	<div class="inside">

		<?php if ( empty( $log_files ) ) : ?>
			<p class="wcpl-empty-state">
				<?php esc_html_e( 'No log files found. Log files are created automatically on the first import.', WCPL_TEXT_DOMAIN ); ?>
			</p>
		<?php else : ?>

			<?php // ── File selector ─────────────────────────────────────── ?>
			<form method="get" action="" class="wcpl-log-selector-form">
				<input type="hidden" name="page" value="wcpl-import">
				<input type="hidden" name="tab" value="logs">

				<label for="wcpl_log_file">
					<?php esc_html_e( 'Select log file:', WCPL_TEXT_DOMAIN ); ?>
				</label>

				<select name="log_file" id="wcpl_log_file" onchange="this.form.submit()">
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
						<?php esc_html_e( 'Load', WCPL_TEXT_DOMAIN ); ?>
					</button>
				</noscript>
			</form>

			<?php // ── Log file content ─────────────────────────────────── ?>
			<?php if ( '' !== $selected_file ) : ?>
				<div class="wcpl-log-meta">
					<span class="wcpl-log-filename">
						<?php echo esc_html( $selected_file ); ?>
					</span>
					<?php if ( '' !== $download_url ) : ?>
						<a
							href="<?php echo esc_url( $download_url ); ?>"
							class="button button-small wcpl-download-log"
						>
							<?php esc_html_e( 'Download', WCPL_TEXT_DOMAIN ); ?>
						</a>
					<?php endif; ?>
					<?php if ( '' !== $log_content ) : ?>
						<span class="wcpl-log-lines">
							—
							<?php
							printf(
								/* translators: %d: number of lines shown */
								esc_html__( '%d lines shown', WCPL_TEXT_DOMAIN ),
								substr_count( $log_content, "\n" ) + 1
							);
							?>
						</span>
					<?php endif; ?>
				</div>

				<?php if ( '' === $log_content ) : ?>
					<p class="wcpl-empty-state">
						<?php esc_html_e( 'This log file is empty or could not be read.', WCPL_TEXT_DOMAIN ); ?>
					</p>
				<?php else : ?>
					<pre class="wcpl-log-content"><?php echo esc_html( $log_content ); ?></pre>
				<?php endif; ?>
			<?php endif; ?>

		<?php endif; ?>

	</div>
</div>
