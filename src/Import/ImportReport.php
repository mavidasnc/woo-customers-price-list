<?php
/**
 * Import Report Value Object.
 *
 * Accumulates all statistics and error details produced during a single
 * import run. Passed as the argument to the `wdplp_after_import` action
 * and returned to the caller (admin form handler or WP-CLI command).
 *
 * Usage pattern:
 *   $report = new ImportReport();
 *   // ... import runs ...
 *   $report->finish();
 *   $array = $report->to_array();   // for admin display
 *   $text  = $report->summary();    // for WP-CLI output
 *
 * @package WDPLP\Import
 * @since   1.0.0
 */

namespace WDPLP\Import;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ImportReport
 */
class ImportReport {

	// ---------------------------------------------------------------------------
	// Counters (public so CsvImporter can increment them directly)
	// ---------------------------------------------------------------------------

	/**
	 * Total CSV data rows read (excluding the header row).
	 *
	 * @var int
	 */
	public int $total_rows = 0;

	/**
	 * Number of WooCommerce products / variations whose _regular_price was
	 * successfully updated from the LORDO column.
	 *
	 * @var int
	 */
	public int $wc_prices_updated = 0;

	/**
	 * Number of rows successfully inserted or updated (UPSERT) in the
	 * custom_price_lists table.
	 *
	 * @var int
	 */
	public int $pricelist_rows_upserted = 0;

	/**
	 * Number of rows skipped (empty SKU, all-empty price cells, etc.).
	 *
	 * @var int
	 */
	public int $rows_skipped = 0;

	// ---------------------------------------------------------------------------
	// SKU tracking
	// ---------------------------------------------------------------------------

	/**
	 * List of SKUs that were present in the CSV but had no matching WooCommerce
	 * product or variation.
	 *
	 * Stored as a unique set (values = SKU strings, keyed by SKU to prevent
	 * duplicates from being added twice for the same SKU).
	 *
	 * @var array<string, string>
	 */
	private array $skus_not_found = [];

	// ---------------------------------------------------------------------------
	// Error log
	// ---------------------------------------------------------------------------

	/**
	 * Technical errors and warnings that occurred during import.
	 *
	 * Each entry: [ 'row' => int|null, 'level' => 'error'|'warning', 'message' => string ]
	 *
	 * @var array<int, array{row: int|null, level: string, message: string}>
	 */
	private array $errors = [];

	// ---------------------------------------------------------------------------
	// Timestamps
	// ---------------------------------------------------------------------------

	/**
	 * Timestamp recorded when the ImportReport is constructed.
	 *
	 * @var \DateTimeImmutable
	 */
	private \DateTimeImmutable $started_at;

	/**
	 * Timestamp recorded when finish() is called.
	 * Null until finish() is called.
	 *
	 * @var \DateTimeImmutable|null
	 */
	private ?\DateTimeImmutable $finished_at = null;

	// ---------------------------------------------------------------------------
	// Constructor
	// ---------------------------------------------------------------------------

	/**
	 * Initialise the report and record the start timestamp.
	 */
	public function __construct() {
		$this->started_at = new \DateTimeImmutable();
	}

	// ---------------------------------------------------------------------------
	// Mutation API
	// ---------------------------------------------------------------------------

	/**
	 * Mark the import as finished and record the completion timestamp.
	 *
	 * Should be called once, at the very end of the import run.
	 *
	 * @return void
	 */
	public function finish(): void {
		$this->finished_at = new \DateTimeImmutable();
	}

	/**
	 * Record a SKU that was present in the CSV but not found in WooCommerce.
	 *
	 * Deduplicates automatically: the same SKU added multiple times counts once.
	 *
	 * @param string $sku The missing SKU string.
	 * @return void
	 */
	public function add_sku_not_found( string $sku ): void {
		$this->skus_not_found[ $sku ] = $sku;
	}

	/**
	 * Record a technical error or warning.
	 *
	 * @param string   $message Human-readable description of the problem.
	 * @param string   $level   Severity: 'error' or 'warning'. Defaults to 'error'.
	 * @param int|null $row     1-based CSV row index, or null for file-level errors.
	 * @return void
	 */
	public function add_error( string $message, string $level = 'error', ?int $row = null ): void {
		$this->errors[] = [
			'row'     => $row,
			'level'   => $level,
			'message' => $message,
		];
	}

	// ---------------------------------------------------------------------------
	// Read API
	// ---------------------------------------------------------------------------

	/**
	 * Return the list of SKUs not found in WooCommerce (deduplicated).
	 *
	 * @return string[] Array of SKU strings.
	 */
	public function get_skus_not_found(): array {
		return array_values( $this->skus_not_found );
	}

	/**
	 * Return the count of unique SKUs not found.
	 *
	 * @return int
	 */
	public function count_skus_not_found(): int {
		return count( $this->skus_not_found );
	}

	/**
	 * Return all recorded errors and warnings.
	 *
	 * @return array<int, array{row: int|null, level: string, message: string}>
	 */
	public function get_errors(): array {
		return $this->errors;
	}

	/**
	 * Return only entries with level = 'error' (excludes warnings).
	 *
	 * @return array<int, array{row: int|null, level: string, message: string}>
	 */
	public function get_errors_only(): array {
		return array_values(
			array_filter( $this->errors, static fn( $e ) => 'error' === $e['level'] )
		);
	}

	/**
	 * Return the start timestamp.
	 *
	 * @return \DateTimeImmutable
	 */
	public function get_started_at(): \DateTimeImmutable {
		return $this->started_at;
	}

	/**
	 * Return the finish timestamp, or null if finish() has not been called yet.
	 *
	 * @return \DateTimeImmutable|null
	 */
	public function get_finished_at(): ?\DateTimeImmutable {
		return $this->finished_at;
	}

	/**
	 * Return elapsed time in seconds (float), or null if not finished.
	 *
	 * @return float|null
	 */
	public function elapsed_seconds(): ?float {
		if ( null === $this->finished_at ) {
			return null;
		}

		return (float) $this->finished_at->format( 'U.u' )
			- (float) $this->started_at->format( 'U.u' );
	}

	// ---------------------------------------------------------------------------
	// Serialisation
	// ---------------------------------------------------------------------------

	/**
	 * Serialise the report to a plain array suitable for JSON encoding,
	 * admin-notice rendering, or WP-CLI table display.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return [
			'total_rows'              => $this->total_rows,
			'wc_prices_updated'       => $this->wc_prices_updated,
			'pricelist_rows_upserted' => $this->pricelist_rows_upserted,
			'rows_skipped'            => $this->rows_skipped,
			'skus_not_found_count'    => $this->count_skus_not_found(),
			'skus_not_found'          => $this->get_skus_not_found(),
			'error_count'             => count( $this->get_errors_only() ),
			'errors'                  => $this->errors,
			'started_at'              => $this->started_at->format( 'Y-m-d H:i:s' ),
			'finished_at'             => $this->finished_at?->format( 'Y-m-d H:i:s' ),
			'elapsed_seconds'         => $this->elapsed_seconds(),
		];
	}

	/**
	 * Return a compact one-line summary string, useful for log files and
	 * WP-CLI output.
	 *
	 * Example:
	 *   "Processed 250 rows in 1.23s: 248 WC prices updated,
	 *    744 list rows upserted, 2 SKUs not found, 0 errors."
	 *
	 * @return string
	 */
	public function summary(): string {
		$elapsed = $this->elapsed_seconds();
		$time    = $elapsed !== null ? sprintf( ' in %.2fs', $elapsed ) : '';

		return sprintf(
			'Processed %d rows%s: %d WC prices updated, %d list rows upserted, %d SKUs not found, %d errors.',
			$this->total_rows,
			$time,
			$this->wc_prices_updated,
			$this->pricelist_rows_upserted,
			$this->count_skus_not_found(),
			count( $this->get_errors_only() )
		);
	}
}
