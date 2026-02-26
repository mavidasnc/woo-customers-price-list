<?php
/**
 * WP-CLI Command: price-list
 *
 * Registers the `wp price-list` command group for managing price list data
 * from the command line.
 *
 * Available subcommands:
 *
 *   wp price-list import --file=<path>
 *     Import a price list CSV from a local file path.
 *
 *   wp price-list import --url=<url>
 *     Import a price list CSV from a remote URL.
 *
 *   wp price-list status
 *     Show a summary of the current price list table (row count, COD values).
 *
 *   wp price-list clear-cache
 *     Not applicable at CLI level (cache is request-scoped), but included
 *     as a no-op stub for completeness and future extension.
 *
 * Usage examples:
 *
 *   wp price-list import --file=/tmp/prices.csv
 *   wp price-list import --url=https://example.com/prices.csv
 *   wp price-list import --file=/tmp/prices.csv --dry-run
 *   wp price-list status
 *
 * @package WDPLP\Cli
 * @since   1.0.0
 */

namespace WDPLP\Cli;

use WDPLP\Import\CsvImporter;
use WDPLP\Import\ImportReport;
use WDPLP\PriceList\Repository;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manage WooCommerce Dynamic Price List Pro data.
 *
 * ## EXAMPLES
 *
 *   # Import from a local file
 *   $ wp price-list import --file=/tmp/prices.csv
 *
 *   # Import from a remote URL
 *   $ wp price-list import --url=https://example.com/prices.csv
 *
 *   # Dry-run: validate CSV without writing to DB
 *   $ wp price-list import --file=/tmp/prices.csv --dry-run
 *
 *   # Show current table stats
 *   $ wp price-list status
 */
class PriceListCommand {

	// ---------------------------------------------------------------------------
	// Subcommands
	// ---------------------------------------------------------------------------

	/**
	 * Import a price list from a CSV file or URL.
	 *
	 * ## OPTIONS
	 *
	 * [--file=<path>]
	 * : Absolute or relative path to a local CSV file.
	 *
	 * [--url=<url>]
	 * : Public URL of a remote CSV file (HTTPS recommended).
	 *
	 * [--dry-run]
	 * : Parse and validate the CSV without writing anything to the database.
	 *   Useful for checking format and missing SKUs before a real import.
	 *
	 * [--format=<format>]
	 * : Output format for the summary table. Accepts: table, csv, json, yaml.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *   wp price-list import --file=/tmp/prices.csv
	 *   wp price-list import --url=https://example.com/prices.csv
	 *   wp price-list import --file=/tmp/prices.csv --dry-run
	 *   wp price-list import --file=/tmp/prices.csv --format=json
	 *
	 * @param array<int,string>    $args       Positional arguments (unused).
	 * @param array<string,string> $assoc_args Named arguments (--file, --url, --dry-run, --format).
	 * @return void
	 */
	public function import( array $args, array $assoc_args ): void {
		$file    = \WP_CLI\Utils\get_flag_value( $assoc_args, 'file', '' );
		$url     = \WP_CLI\Utils\get_flag_value( $assoc_args, 'url', '' );
		$dry_run = \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$format  = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

		// ── Validate input ──────────────────────────────────────────────────
		if ( '' === $file && '' === $url ) {
			\WP_CLI::error( 'You must provide either --file=<path> or --url=<url>.' );
			return; // WP_CLI::error() calls exit, but return satisfies static analysis.
		}

		if ( '' !== $file && '' !== $url ) {
			\WP_CLI::error( 'Specify only one of --file or --url, not both.' );
			return;
		}

		if ( $dry_run ) {
			\WP_CLI::log( \WP_CLI::colorize( '%ydry-run mode — no data will be written to the database.%n' ) );
		}

		// ── File validation ─────────────────────────────────────────────────
		if ( '' !== $file ) {
			$file = $this->resolve_file_path( $file );
		}

		// ── Run import ──────────────────────────────────────────────────────
		$importer = new CsvImporter();

		\WP_CLI::log( 'Starting import…' );
		$start = microtime( true );

		if ( $dry_run ) {
			$report = $this->run_dry_import( $importer, $file, $url );
		} elseif ( '' !== $file ) {
			$report = $importer->import_from_file( $file );
		} else {
			$report = $importer->import_from_url( $url );
		}

		$elapsed = round( microtime( true ) - $start, 2 );

		// ── Output results ──────────────────────────────────────────────────
		$this->render_report( $report, $format, $elapsed );
	}

	/**
	 * Show a summary of the current price list table.
	 *
	 * Displays the total number of active rows and the list of distinct COD
	 * (price list code) values stored in the database.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format. Accepts: table, csv, json, yaml.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *   wp price-list status
	 *   wp price-list status --format=json
	 *
	 * @param array<int,string>    $args       Positional arguments (unused).
	 * @param array<string,string> $assoc_args Named arguments.
	 * @return void
	 */
	public function status( array $args, array $assoc_args ): void {
		$format = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

		$repository = new Repository();

		if ( ! $repository->table_exists() ) {
			\WP_CLI::error( 'The price list table does not exist. Try activating the plugin or running the database upgrade.' );
			return;
		}

		$row_count  = $repository->count_active_rows();
		$cod_values = $repository->get_all_cod_values();

		\WP_CLI::log( sprintf( 'Active rows: %s', number_format( $row_count ) ) );
		\WP_CLI::log( sprintf( 'Distinct COD values (%d): %s', count( $cod_values ), implode( ', ', $cod_values ) ) );

		if ( 'table' === $format ) {
			// Print a simple summary table.
			$items = [];
			foreach ( $cod_values as $cod ) {
				$items[] = [ 'COD' => $cod ];
			}
			if ( ! empty( $items ) ) {
				\WP_CLI\Utils\format_items( $format, $items, [ 'COD' ] );
			}
		}
	}

	// ---------------------------------------------------------------------------
	// Private Helpers
	// ---------------------------------------------------------------------------

	/**
	 * Resolve and validate a local file path argument.
	 *
	 * Accepts absolute or relative (to CWD) paths. Calls WP_CLI::error() and
	 * exits if the file does not exist or is not readable.
	 *
	 * @param string $path Raw path from CLI argument.
	 * @return string      Resolved absolute path.
	 */
	private function resolve_file_path( string $path ): string {
		// If relative, resolve against the current working directory.
		if ( ! path_is_absolute( $path ) ) {
			$path = getcwd() . DIRECTORY_SEPARATOR . $path;
		}

		$real = realpath( $path );

		if ( false === $real ) {
			\WP_CLI::error( sprintf( 'File not found: %s', $path ) );
		}

		if ( ! is_readable( $real ) ) {
			\WP_CLI::error( sprintf( 'File is not readable: %s', $real ) );
		}

		$ext = strtolower( pathinfo( $real, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, [ 'csv', 'txt' ], true ) ) {
			\WP_CLI::error( sprintf( 'Unsupported file type ".%s". Accepted extensions: .csv, .txt', $ext ) );
		}

		return $real;
	}

	/**
	 * Perform a dry-run import: parse the CSV, collect stats, but skip DB writes.
	 *
	 * Uses CsvImporter's internal parse/transform pipeline without calling the
	 * repository upsert methods or WooCommerce price update functions.
	 *
	 * @param CsvImporter $importer Ready CsvImporter instance.
	 * @param string      $file     Resolved file path (empty string if URL mode).
	 * @param string      $url      Remote URL (empty string if file mode).
	 * @return ImportReport         Report with stats but 0 DB writes.
	 */
	private function run_dry_import( CsvImporter $importer, string $file, string $url ): ImportReport {
		// CsvImporter exposes a dry_run flag via the public API.
		if ( '' !== $file ) {
			return $importer->import_from_file( $file, true );
		}

		return $importer->import_from_url( $url, true );
	}

	/**
	 * Render the ImportReport as a summary table and WP-CLI status messages.
	 *
	 * @param ImportReport $report  The completed import report.
	 * @param string       $format  Output format (table|csv|json|yaml).
	 * @param float        $elapsed Wall-clock seconds from start to finish.
	 * @return void
	 */
	private function render_report( ImportReport $report, string $format, float $elapsed ): void {
		$data = $report->to_array();

		// ── Summary stats table ─────────────────────────────────────────────
		$rows = [
			[ 'Metric' => 'Total rows processed',            'Value' => number_format( (int) ( $data['total_rows'] ?? 0 ) ) ],
			[ 'Metric' => 'WooCommerce prices updated',      'Value' => number_format( (int) ( $data['wc_prices_updated'] ?? 0 ) ) ],
			[ 'Metric' => 'Price list rows inserted/updated','Value' => number_format( (int) ( $data['pricelist_rows_upserted'] ?? 0 ) ) ],
			[ 'Metric' => 'Rows skipped',                    'Value' => number_format( (int) ( $data['rows_skipped'] ?? 0 ) ) ],
			[ 'Metric' => 'SKUs not found in WooCommerce',   'Value' => number_format( (int) ( $data['skus_not_found_count'] ?? 0 ) ) ],
			[ 'Metric' => 'Duration (s)',                    'Value' => number_format( $elapsed, 2 ) ],
		];

		\WP_CLI\Utils\format_items( $format, $rows, [ 'Metric', 'Value' ] );

		// ── Missing SKUs detail ─────────────────────────────────────────────
		if ( ! empty( $data['skus_not_found'] ) ) {
			\WP_CLI::warning( 'The following SKUs were in the CSV but could not be matched to WooCommerce products:' );
			foreach ( (array) $data['skus_not_found'] as $sku ) {
				\WP_CLI::log( '  - ' . $sku );
			}
		}

		// ── Row-level errors / warnings ─────────────────────────────────────
		if ( ! empty( $data['errors'] ) ) {
			\WP_CLI::log( '' );
			\WP_CLI::log( sprintf( '%d error/warning message(s):', count( (array) $data['errors'] ) ) );
			foreach ( (array) $data['errors'] as $err ) {
				$prefix  = ! empty( $err['row'] ) ? sprintf( 'Row %d: ', (int) $err['row'] ) : '';
				$message = $prefix . ( $err['message'] ?? '' );

				if ( 'error' === ( $err['level'] ?? 'error' ) ) {
					\WP_CLI::warning( $message );
				} else {
					\WP_CLI::log( \WP_CLI::colorize( '%y' . $message . '%n' ) );
				}
			}
		}

		// ── Final status line ───────────────────────────────────────────────
		$has_errors = ! empty( $data['error_count'] ) && $data['error_count'] > 0;

		if ( $has_errors ) {
			\WP_CLI::warning( 'Import completed with errors. Review the messages above.' );
		} else {
			\WP_CLI::success( 'Import completed successfully.' );
		}
	}
}
