<?php
/**
 * Import Logger.
 *
 * Writes timestamped log entries to daily log files stored in the plugin's
 * dedicated log directory (wp-content/uploads/wc-price-logs/).
 *
 * Log file naming convention:
 *   wc-price-log-YYYY-MM-DD.log    (e.g. wc-price-log-2026-02-19.log)
 *
 * Each log line format:
 *   [2026-02-19 14:30:00] [INFO] Message text here
 *   [2026-02-19 14:30:01] [WARNING] Something to note
 *   [2026-02-19 14:30:02] [ERROR] Something went wrong
 *
 * A new log file is created automatically on the first write of each day.
 * Log files accumulate indefinitely; there is currently no automatic rotation.
 * The directory is protected by .htaccess (written by Helpers::ensure_log_dir()).
 *
 * @package WDPLP\Import
 * @since   1.0.0
 */

namespace WDPLP\Import;

use WDPLP\Helpers;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Logger
 *
 * Instantiate once per import run (CsvImporter creates a new instance).
 * The log file path is computed in the constructor and reused across calls.
 */
class Logger {

	/**
	 * Log severity levels.
	 */
	public const LEVEL_INFO    = 'INFO';
	public const LEVEL_WARNING = 'WARNING';
	public const LEVEL_ERROR   = 'ERROR';

	/**
	 * Absolute path to the log file for today's date.
	 *
	 * Computed once in the constructor via get_log_filepath().
	 *
	 * @var string
	 */
	private string $log_filepath;

	/**
	 * Whether the log directory has been confirmed as writable.
	 *
	 * Checked lazily on the first write attempt to avoid filesystem calls
	 * when no log entries are actually produced.
	 *
	 * @var bool|null  null = not checked yet, true = writable, false = not writable
	 */
	private ?bool $dir_ready = null;

	// ---------------------------------------------------------------------------
	// Constructor
	// ---------------------------------------------------------------------------

	/**
	 * Set up the logger for the current day's log file.
	 *
	 * The log file path is based on the WordPress site timezone so that the
	 * date in the filename matches what administrators see in the admin.
	 */
	public function __construct() {
		$this->log_filepath = $this->get_log_filepath();
	}

	// ---------------------------------------------------------------------------
	// Public API
	// ---------------------------------------------------------------------------

	/**
	 * Write a full import report to the log file.
	 *
	 * Writes:
	 *   - A start separator line with the timestamp.
	 *   - A one-line summary of the import results.
	 *   - Each missing SKU (if any).
	 *   - Each error (if any).
	 *   - An end separator line.
	 *
	 * @param ImportReport $report The completed import report to log.
	 * @return void
	 */
	public function log_report( ImportReport $report ): void {
		$this->log_line( self::LEVEL_INFO, '--- Import started at ' . $report->get_started_at()->format( 'Y-m-d H:i:s' ) . ' ---' );
		$this->log_line( self::LEVEL_INFO, $report->summary() );

		// Log each SKU that was not found in WooCommerce.
		foreach ( $report->get_skus_not_found() as $sku ) {
			$this->log_line( self::LEVEL_WARNING, 'SKU not found in WooCommerce: ' . $sku );
		}

		// Log each technical error.
		foreach ( $report->get_errors() as $error ) {
			$level   = 'warning' === $error['level'] ? self::LEVEL_WARNING : self::LEVEL_ERROR;
			$row_tag = $error['row'] !== null ? ' [row ' . $error['row'] . ']' : '';
			$this->log_line( $level, $row_tag . ' ' . $error['message'] );
		}

		$finished = $report->get_finished_at();
		$this->log_line( self::LEVEL_INFO, '--- Import ended at ' . ( $finished ? $finished->format( 'Y-m-d H:i:s' ) : 'N/A' ) . ' ---' );
	}

	/**
	 * Write a single timestamped line to the log file.
	 *
	 * This is the low-level logging primitive. Callers can use it directly
	 * for one-off messages outside of a full import report.
	 *
	 * @param string $level   One of the LEVEL_* constants (INFO, WARNING, ERROR).
	 * @param string $message The log message. Must not contain newlines.
	 * @return void
	 */
	public function log_line( string $level, string $message ): void {
		// Ensure the directory exists and is writable (lazy check, once per instance).
		if ( null === $this->dir_ready ) {
			$this->dir_ready = Helpers::ensure_log_dir();
		}

		if ( ! $this->dir_ready ) {
			// Silently fail — we never throw from a logger to avoid breaking the import.
			return;
		}

		// Sanitise the message: strip newlines to keep one entry per line.
		$clean_message = str_replace( [ "\r\n", "\r", "\n" ], ' ', $message );

		$line = sprintf(
			'[%s] [%s] %s' . PHP_EOL,
			current_time( 'Y-m-d H:i:s' ),
			strtoupper( $level ),
			$clean_message
		);

		$this->write( $line );
	}

	// ---------------------------------------------------------------------------
	// Path helpers
	// ---------------------------------------------------------------------------

	/**
	 * Return the absolute path to the log file for the current calendar day.
	 *
	 * The date is computed using the WordPress site timezone so that
	 * "today" matches what administrators expect.
	 *
	 * @return string Absolute file path, e.g. ".../wc-price-logs/wc-price-log-2026-02-19.log"
	 */
	private function get_log_filepath(): string {
		$date = current_time( 'Y-m-d' );
		return Helpers::get_log_dir() . 'wc-price-log-' . $date . '.log';
	}

	/**
	 * Return the log filepath (for external reference, e.g. admin UI display).
	 *
	 * @return string
	 */
	public function get_current_log_filepath(): string {
		return $this->log_filepath;
	}

	// ---------------------------------------------------------------------------
	// Filesystem
	// ---------------------------------------------------------------------------

	/**
	 * Append a raw string to the log file.
	 *
	 * Uses FILE_APPEND | LOCK_EX to prevent partial writes when concurrent
	 * requests attempt to log at the same time.
	 *
	 * @param string $content The exact string to append (should end with PHP_EOL).
	 * @return void
	 */
	private function write( string $content ): void {
		file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$this->log_filepath,
			$content,
			FILE_APPEND | LOCK_EX
		);
	}
}
