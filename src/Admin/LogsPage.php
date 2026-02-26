<?php
/**
 * Logs Page — Import History and Log File Viewer.
 *
 * Responsibilities:
 *   - Fetches the recent import summary from the database (via Repository).
 *   - Lists available log files from the log directory.
 *   - Displays the content of the selected (or most recent) log file.
 *   - Renders the "Logs & History" tab content (called by AdminController).
 *
 * Security:
 *   - Only lists files within WDPLP_LOG_DIR (never arbitrary paths).
 *   - The requested log filename is validated against the directory listing
 *     before reading (prevents path traversal: ../../etc/passwd etc.).
 *   - Log file content is escaped with esc_html() before output.
 *
 * @package WDPLP\Admin
 * @since   1.0.0
 */

namespace WDPLP\Admin;

use WDPLP\Helpers;
use WDPLP\PriceList\Repository;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class LogsPage
 */
class LogsPage {

	/**
	 * Maximum number of log file lines to display in the viewer.
	 * Prevents memory issues when displaying very large log files.
	 *
	 * @var int
	 */
	private const MAX_LOG_LINES = 500;

	// ---------------------------------------------------------------------------
	// Rendering
	// ---------------------------------------------------------------------------

	/**
	 * Render the logs tab content.
	 *
	 * Called by AdminController::render_page() when the 'logs' tab is active.
	 * Collects all necessary data and passes it to the logs view template.
	 *
	 * @return void
	 */
	public function render_content(): void {
		$repository = new Repository();

		// ── 1. Recent import summary from database ─────────────────────────────
		$import_history = $repository->get_recent_import_summary( 30 );

		// ── 2. Log files list from filesystem ─────────────────────────────────
		$log_files = $this->get_log_files();

		// ── 3. Determine which log file to display ─────────────────────────────
		$requested_file = isset( $_GET['log_file'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			? sanitize_file_name( wp_unslash( $_GET['log_file'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			: '';

		// Default to the most recent log file.
		$selected_file = '';
		if ( ! empty( $requested_file ) && in_array( $requested_file, $log_files, true ) ) {
			$selected_file = $requested_file;
		} elseif ( ! empty( $log_files ) ) {
			$selected_file = $log_files[0]; // first = most recent (sorted DESC by name)
		}

		// ── 4. Read the selected log file content ──────────────────────────────
		$log_content = '';
		if ( '' !== $selected_file ) {
			$log_content = $this->read_log_file( $selected_file );
		}

		// ── 5. Render the template ─────────────────────────────────────────────
		require WDPLP_PLUGIN_DIR . 'admin/views/logs-page.php';
	}

	// ---------------------------------------------------------------------------
	// Private Helpers
	// ---------------------------------------------------------------------------

	/**
	 * Return a list of log filenames from the log directory, sorted newest first.
	 *
	 * Only files matching the pattern "wc-price-log-*.log" are included.
	 * The .htaccess, index.php, and any other files are excluded.
	 *
	 * @return string[] Array of bare filenames (not full paths), newest first.
	 */
	private function get_log_files(): array {
		$log_dir = Helpers::get_log_dir();

		if ( ! is_dir( $log_dir ) ) {
			return [];
		}

		$pattern = $log_dir . 'wc-price-log-*.log';
		$files   = glob( $pattern );

		if ( false === $files || empty( $files ) ) {
			return [];
		}

		// Sort descending (newest date first) using filename lexicographic order.
		// Works because filenames are "wc-price-log-YYYY-MM-DD.log" — ISO date format
		// sorts lexicographically in the same order as chronologically.
		rsort( $files );

		// Return only the bare filenames (not full paths) for security.
		return array_map( 'basename', $files );
	}

	/**
	 * Read and return the content of a log file as a string.
	 *
	 * Only reads from within WDPLP_LOG_DIR. The filename is validated against
	 * the directory listing before use (path traversal prevention).
	 *
	 * Limits output to the last MAX_LOG_LINES lines of the file to prevent
	 * memory issues with very large log files.
	 *
	 * @param string $filename Bare filename (e.g. "wc-price-log-2026-02-19.log").
	 * @return string File contents, or an empty string if the file cannot be read.
	 */
	private function read_log_file( string $filename ): string {
		// Validate: allow only expected filename format.
		if ( ! preg_match( '/^wc-price-log-\d{4}-\d{2}-\d{2}\.log$/', $filename ) ) {
			return '';
		}

		$full_path = Helpers::get_log_dir() . $filename;

		// Confirm the resolved path is still within the log directory.
		$real_path    = realpath( $full_path );
		$real_log_dir = realpath( Helpers::get_log_dir() );

		if (
			false === $real_path ||
			false === $real_log_dir ||
			! str_starts_with( $real_path, $real_log_dir )
		) {
			return '';
		}

		if ( ! is_readable( $real_path ) ) {
			return '';
		}

		// Read the file into lines.
		$lines = file( $real_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );

		if ( false === $lines ) {
			return '';
		}

		// Return only the last MAX_LOG_LINES lines.
		if ( count( $lines ) > self::MAX_LOG_LINES ) {
			$lines = array_slice( $lines, - self::MAX_LOG_LINES );
			array_unshift( $lines, sprintf( '... (showing last %d lines) ...', self::MAX_LOG_LINES ) );
		}

		return implode( PHP_EOL, $lines );
	}
}
