<?php
/**
 * CSV Importer — Import Orchestrator.
 *
 * Coordinates the full import pipeline:
 *   1. Accept input (local file path or remote URL).
 *   2. If URL, delegate download to RemoteDownloader.
 *   3. Parse the CSV file with fgetcsv().
 *   4. Apply optional row limit (for testing with partial files).
 *   5. Delegate transformation to CsvTransformer (wide → long).
 *   6. Validate all unique SKUs against WooCommerce products/variations.
 *      SKUs not found are recorded once and their rows are skipped entirely.
 *   7. Update WooCommerce product prices from the LORDO column (valid SKUs only).
 *   8. Batch-upsert price list rows via Repository (valid SKUs only).
 *   9. Compile an ImportReport with full statistics.
 *  10. Write the report to the log file via Logger.
 *  11. Fire developer hooks before and after the import.
 *
 * Developer hooks fired:
 *   do_action( 'wcpl_before_import', array $source )   — before parsing.
 *   do_action( 'wcpl_after_import', ImportReport $report ) — after completion.
 *
 * CSV parsing notes:
 *   - UTF-8 BOM is stripped automatically from the first cell of the header row.
 *   - Delimiter is auto-detected (comma vs semicolon vs tab) by inspecting the
 *     raw first line of the file.
 *   - Empty trailing rows are silently skipped.
 *   - Rows with fewer cells than headers are padded with empty strings.
 *
 * @package WCPL\Import
 * @since   1.0.0
 */

namespace WCPL\Import;

use WCPL\Helpers;
use WCPL\PriceList\Repository;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CsvImporter
 */
class CsvImporter {

	/**
	 * Maximum line length passed to fgetcsv(). 0 = unlimited (PHP 5.1+).
	 *
	 * @var int
	 */
	private const CSV_MAX_LINE_LENGTH = 0;

	/**
	 * UTF-8 Byte Order Mark (BOM) to strip from the first cell.
	 *
	 * @var string
	 */
	private const UTF8_BOM = "\xEF\xBB\xBF";

	// ---------------------------------------------------------------------------
	// Dependencies (set in constructor)
	// ---------------------------------------------------------------------------

	/**
	 * @var Repository
	 */
	private Repository $repository;

	/**
	 * @var CsvTransformer
	 */
	private CsvTransformer $transformer;

	/**
	 * @var RemoteDownloader
	 */
	private RemoteDownloader $downloader;

	/**
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * In-memory SKU → product ID cache to avoid redundant DB lookups when the
	 * same SKU appears in multiple rows.
	 *
	 * @var array<string, int|false>
	 */
	private array $sku_cache = [];

	// ---------------------------------------------------------------------------
	// Constructor
	// ---------------------------------------------------------------------------

	/**
	 * Initialise the importer with its dependencies.
	 *
	 * All dependencies default to new instances so callers do not need to
	 * build the full object graph manually. Pass explicit instances to
	 * override in tests.
	 *
	 * @param Repository|null        $repository  Price list DB repository.
	 * @param CsvTransformer|null    $transformer Wide-to-long transformer.
	 * @param RemoteDownloader|null  $downloader  Remote URL downloader.
	 * @param Logger|null            $logger      Import logger.
	 */
	public function __construct(
		?Repository       $repository  = null,
		?CsvTransformer   $transformer = null,
		?RemoteDownloader $downloader  = null,
		?Logger           $logger      = null
	) {
		$this->repository  = $repository  ?? new Repository();
		$this->transformer = $transformer ?? new CsvTransformer();
		$this->downloader  = $downloader  ?? new RemoteDownloader();
		$this->logger      = $logger      ?? new Logger();
	}

	// ---------------------------------------------------------------------------
	// Public API
	// ---------------------------------------------------------------------------

	/**
	 * Import a price list from a local CSV file.
	 *
	 * @param string $filepath Absolute path to the CSV file on the server.
	 * @param bool   $dry_run  If true, parse and validate only — no DB writes.
	 * @param int    $limit    Max data rows to process. 0 = no limit.
	 * @return ImportReport    Completed report with all import statistics.
	 */
	public function import_from_file( string $filepath, bool $dry_run = false, int $limit = 0 ): ImportReport {
		return $this->run_import( $filepath, [ 'type' => 'file', 'source' => $filepath ], $dry_run, $limit );
	}

	/**
	 * Import a price list by downloading a CSV from a remote URL.
	 *
	 * The downloaded file is stored in a temporary location and deleted after
	 * import, regardless of success or failure.
	 *
	 * @param string $url     Remote URL of the CSV file.
	 * @param bool   $dry_run If true, parse and validate only — no DB writes.
	 * @param int    $limit   Max data rows to process. 0 = no limit.
	 * @return ImportReport   Completed report with all import statistics.
	 */
	public function import_from_url( string $url, bool $dry_run = false, int $limit = 0 ): ImportReport {
		$temp_file = null;
		$report    = new ImportReport();

		try {
			$temp_file = $this->downloader->download( $url );
		} catch ( \RuntimeException $e ) {
			$report->add_error( 'Download failed: ' . $e->getMessage() );
			$report->finish();
			$this->logger->log_report( $report );
			return $report;
		}

		$report = $this->run_import( $temp_file, [ 'type' => 'url', 'source' => $url ], $dry_run, $limit );

		// Always clean up the temp file.
		if ( $temp_file && file_exists( $temp_file ) ) {
			@unlink( $temp_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		return $report;
	}

	// ---------------------------------------------------------------------------
	// Core Import Pipeline
	// ---------------------------------------------------------------------------

	/**
	 * Run the full import pipeline for a local file path.
	 *
	 * This is the single entry point for both import_from_file() and
	 * import_from_url() (after the URL variant downloads to a temp file).
	 *
	 * @param string               $filepath    Absolute path to the CSV file.
	 * @param array<string, mixed> $source_info Context array passed to hooks:
	 *                                          [ 'type' => 'file'|'url', 'source' => string ]
	 * @param bool                 $dry_run     If true, skip all DB writes and WC price updates.
	 * @param int                  $limit       Max data rows to process. 0 = no limit.
	 * @return ImportReport
	 */
	private function run_import( string $filepath, array $source_info, bool $dry_run = false, int $limit = 0 ): ImportReport {
		$report = new ImportReport();

		/**
		 * Fires before the import process begins.
		 *
		 * @since 1.0.0
		 * @param array<string, mixed> $source_info [ 'type' => 'file'|'url', 'source' => string ]
		 */
		do_action( 'wcpl_before_import', $source_info );
		// Backward compatibility: the pre-2.0.0 hook name still works, with a deprecation notice.
		do_action_deprecated( 'wdplp_before_import', [ $source_info ], '2.0.0', 'wcpl_before_import' );

		// ── 1. Validate file ────────────────────────────────────────────────────
		if ( ! file_exists( $filepath ) || ! is_readable( $filepath ) ) {
			$report->add_error( sprintf(
				/* translators: %s: absolute path to the CSV file */
				__( 'File not found or not readable: "%s".', WCPL_TEXT_DOMAIN ),
				$filepath
			) );
			$report->finish();
			$this->logger->log_report( $report );
			return $report;
		}

		// ── 2. Parse CSV ────────────────────────────────────────────────────────
		[ $headers, $rows ] = $this->parse_csv( $filepath, $report );

		if ( empty( $headers ) ) {
			$report->finish();
			$this->logger->log_report( $report );
			return $report;
		}

		// ── 3. Apply row limit (for test imports on partial data) ───────────────
		if ( $limit > 0 && count( $rows ) > $limit ) {
			$rows = array_slice( $rows, 0, $limit );
		}

		// ── 4. Transform (wide → long) ──────────────────────────────────────────
		$transform_result = $this->transformer->transform( $headers, $rows, Helpers::today_mysql() );

		// Forward transformation warnings to the report.
		foreach ( $transform_result->warnings as $warning ) {
			$report->add_error( $warning['message'], 'warning', $warning['row'] );
		}

		$report->rows_skipped = $transform_result->rows_skipped;
		$report->total_rows   = $transform_result->total_rows_read;

		// ── 5. Validate SKUs against WooCommerce ────────────────────────────────
		// Every unique SKU appearing in any output collection is looked up once.
		// Invalid SKUs are added to the report a single time and their rows are
		// excluded from both the WC price update and the DB upsert.
		$valid_skus = $this->validate_all_skus( $transform_result, $report );

		$wc_updates = array_filter(
			$transform_result->wc_price_updates,
			static fn( array $u ): bool => isset( $valid_skus[ $u['sku'] ] )
		);

		$pl_rows = array_filter(
			$transform_result->price_list_rows,
			static fn( array $r ): bool => isset( $valid_skus[ $r['sku'] ] )
		);

		// ── 6. Apply WooCommerce price updates (LORDO column) ──────────────────
		// Skipped in dry-run mode — no persistent changes to product meta.
		if ( ! $dry_run ) {
			foreach ( $wc_updates as $update ) {
				$this->apply_wc_price( $update['sku'], $update['price'], $report );
			}
		} else {
			$report->wc_prices_updated = count( $wc_updates );
		}

		// ── 7. Upsert price list rows ───────────────────────────────────────────
		// Skipped in dry-run mode — no writes to the custom price list table.
		if ( ! $dry_run ) {
			if ( ! empty( $pl_rows ) ) {
				$report->pricelist_rows_upserted = $this->repository->upsert_rows( array_values( $pl_rows ) );
			}
		} else {
			$report->pricelist_rows_upserted = count( $pl_rows );
		}

		// ── 8. Finish and log ───────────────────────────────────────────────────
		$report->finish();
		$this->logger->log_report( $report );

		/**
		 * Fires after the import process completes.
		 *
		 * @since 1.0.0
		 * @param ImportReport $report The completed import report.
		 */
		do_action( 'wcpl_after_import', $report );
		// Backward compatibility: the pre-2.0.0 hook name still works, with a deprecation notice.
		do_action_deprecated( 'wdplp_after_import', [ $report ], '2.0.0', 'wcpl_after_import' );

		return $report;
	}

	// ---------------------------------------------------------------------------
	// SKU Validation
	// ---------------------------------------------------------------------------

	/**
	 * Validate all unique SKUs from the transform result against WooCommerce.
	 *
	 * Collects every SKU appearing in wc_price_updates or price_list_rows,
	 * performs a single WC lookup per unique SKU (using the in-memory cache),
	 * and records missing SKUs in the report exactly once each.
	 *
	 * @param TransformResult $result The output of CsvTransformer::transform().
	 * @param ImportReport    $report Report to record missing SKUs into.
	 * @return array<string, true>    Map of valid SKU strings (key = SKU, value = true).
	 */
	private function validate_all_skus( TransformResult $result, ImportReport $report ): array {
		// Collect all unique SKUs from both output collections.
		$all_skus = [];

		foreach ( $result->wc_price_updates as $update ) {
			$all_skus[ $update['sku'] ] = true;
		}

		foreach ( $result->price_list_rows as $row ) {
			$all_skus[ $row['sku'] ] = true;
		}

		// Look up each unique SKU once; record missing ones in the report.
		$valid_skus = [];

		foreach ( array_keys( $all_skus ) as $sku ) {
			$product_id = $this->get_product_id_by_sku( $sku );

			if ( false !== $product_id ) {
				$valid_skus[ $sku ] = true;
			} else {
				// add_sku_not_found() deduplicates internally, but we only call it
				// once per unique SKU anyway since $all_skus already deduplicates.
				$report->add_sku_not_found( $sku );
			}
		}

		return $valid_skus;
	}

	// ---------------------------------------------------------------------------
	// CSV Parsing
	// ---------------------------------------------------------------------------

	/**
	 * Open and parse a CSV file into a headers array and a rows array.
	 *
	 * Handles:
	 *   - UTF-8 BOM removal from the first cell.
	 *   - Auto-detection of delimiter (comma, semicolon, or tab).
	 *   - Padding of short rows (fewer cells than headers) with empty strings.
	 *   - Skipping of blank/empty rows.
	 *
	 * @param string       $filepath Absolute path to the CSV file.
	 * @param ImportReport $report   Report to record file-level errors into.
	 * @return array{ 0: string[], 1: array<int, string[]> }
	 *         Two-element array: [headers, rows].
	 *         Both are empty arrays on failure.
	 */
	private function parse_csv( string $filepath, ImportReport $report ): array {
		$handle = fopen( $filepath, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) {
			$report->add_error( sprintf(
				/* translators: %s: absolute path to the CSV file */
				__( 'Could not open CSV file: "%s".', WCPL_TEXT_DOMAIN ),
				$filepath
			) );
			return [ [], [] ];
		}

		// Detect delimiter from the raw first line (before calling fgetcsv).
		$first_line = fgets( $handle );
		if ( false === $first_line ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			$report->add_error( __( 'CSV file appears to be empty.', WCPL_TEXT_DOMAIN ) );
			return [ [], [] ];
		}

		$delimiter = $this->detect_delimiter( $first_line );

		// Rewind to start so fgetcsv reads from the beginning.
		rewind( $handle );

		// Read header row.
		$headers = fgetcsv( $handle, self::CSV_MAX_LINE_LENGTH, $delimiter );
		if ( false === $headers || empty( $headers ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			$report->add_error( __( 'CSV file has no header row.', WCPL_TEXT_DOMAIN ) );
			return [ [], [] ];
		}

		// Strip UTF-8 BOM from the first header cell if present.
		$headers[0] = ltrim( $headers[0], self::UTF8_BOM );

		// Trim all header values.
		$headers   = array_map( 'trim', $headers );
		$col_count = count( $headers );

		// Read all data rows.
		$rows = [];
		while ( ( $row = fgetcsv( $handle, self::CSV_MAX_LINE_LENGTH, $delimiter ) ) !== false ) {
			// Skip completely empty rows.
			if ( empty( $row ) || ( count( $row ) === 1 && trim( $row[0] ) === '' ) ) {
				continue;
			}

			// Pad short rows so every row has the same number of cells as headers.
			if ( count( $row ) < $col_count ) {
				$row = array_pad( $row, $col_count, '' );
			}

			$rows[] = $row;
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return [ $headers, $rows ];
	}

	/**
	 * Auto-detect the CSV delimiter from a raw line string.
	 *
	 * Strategy: count occurrences of each candidate delimiter in the line
	 * and return the one with the highest count. Falls back to comma if all
	 * counts are equal (or zero).
	 *
	 * @param string $line A raw line from the CSV file (typically the header row).
	 * @return string One of: ',' (comma), ';' (semicolon), "\t" (tab).
	 */
	private function detect_delimiter( string $line ): string {
		$candidates = [
			','  => substr_count( $line, ',' ),
			';'  => substr_count( $line, ';' ),
			"\t" => substr_count( $line, "\t" ),
		];

		arsort( $candidates );

		return (string) array_key_first( $candidates );
	}

	// ---------------------------------------------------------------------------
	// WooCommerce Price Update
	// ---------------------------------------------------------------------------

	/**
	 * Update the WooCommerce _regular_price and _price for a product/variation.
	 *
	 * Called only for SKUs already validated by validate_all_skus(), so the
	 * product is guaranteed to exist and get_product_id_by_sku() will hit cache.
	 *
	 * After updating meta:
	 *   - wc_delete_product_transients() clears the WooCommerce price cache.
	 *   - If the found ID is a variation, the parent product's cache is also cleared.
	 *
	 * @param string       $sku    Product SKU (guaranteed valid by the caller).
	 * @param float        $price  New regular price.
	 * @param ImportReport $report Report to increment the wc_prices_updated counter.
	 * @return void
	 */
	private function apply_wc_price( string $sku, float $price, ImportReport $report ): void {
		$product_id = $this->get_product_id_by_sku( $sku );

		if ( ! $product_id ) {
			// Should not happen — SKU was validated before calling this method.
			return;
		}

		// Update both the regular price and the currently active price.
		// _price equals _regular_price when there is no active sale.
		update_post_meta( $product_id, '_regular_price', wc_format_decimal( $price ) );
		update_post_meta( $product_id, '_price', wc_format_decimal( $price ) );

		// Clear WooCommerce's transient cache for this product.
		wc_delete_product_transients( $product_id );

		// If this is a product variation, also clear the parent's cache.
		$parent_id = wp_get_post_parent_id( $product_id );
		if ( $parent_id > 0 ) {
			wc_delete_product_transients( $parent_id );
		}

		++$report->wc_prices_updated;
	}

	/**
	 * Find a WooCommerce product or variation ID by SKU.
	 *
	 * Uses wc_get_product_id_by_sku() which searches both simple products
	 * (post_type = 'product') and variations (post_type = 'product_variation').
	 *
	 * Results are cached in $this->sku_cache for the lifetime of this importer
	 * instance to avoid redundant DB queries for the same SKU.
	 *
	 * @param string $sku The product SKU to look up.
	 * @return int|false  The product/variation post ID, or false if not found.
	 */
	private function get_product_id_by_sku( string $sku ): int|false {
		if ( ! array_key_exists( $sku, $this->sku_cache ) ) {
			// Returns 0 if not found — treat 0 as false.
			$id                      = (int) wc_get_product_id_by_sku( $sku );
			$this->sku_cache[ $sku ] = $id > 0 ? $id : false;
		}

		return $this->sku_cache[ $sku ];
	}
}
