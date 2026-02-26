<?php
/**
 * Transform Result Value Object.
 *
 * Holds the two output collections produced by CsvTransformer::transform():
 *   1. wc_price_updates  — one entry per SKU whose LORDO column was non-empty.
 *                          Used to update WooCommerce _regular_price / _price.
 *   2. price_list_rows   — one entry per (SKU × non-LORDO column) pair where
 *                          the cell was non-empty and numeric.
 *                          Used to UPSERT into the custom_price_lists table.
 *
 * This is intentionally a plain data holder with no behaviour. It is populated
 * by CsvTransformer and consumed by CsvImporter — keeping the two classes
 * loosely coupled.
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
 * Class TransformResult
 *
 * Mutable value object: CsvTransformer writes to it, CsvImporter reads from it.
 */
class TransformResult {

	/**
	 * WooCommerce price updates.
	 *
	 * Each element is an associative array:
	 * [
	 *   'sku'   => string,  // sanitised product SKU
	 *   'price' => float,   // LORDO (gross) price
	 * ]
	 *
	 * @var array<int, array{sku: string, price: float}>
	 */
	public array $wc_price_updates = [];

	/**
	 * Custom price list rows to insert/upsert.
	 *
	 * Each element is an associative array:
	 * [
	 *   'sku'       => string,  // sanitised product SKU
	 *   'cod'       => string,  // price list code (column header), e.g. "L40"
	 *   'price'     => float,   // list price for this SKU+COD combination
	 *   'date_from' => string,  // MySQL DATE string, e.g. "2026-02-19"
	 * ]
	 *
	 * @var array<int, array{sku: string, cod: string, price: float, date_from: string}>
	 */
	public array $price_list_rows = [];

	/**
	 * Total number of data rows read from the CSV (excluding the header row).
	 *
	 * Incremented by CsvTransformer for every row it processes, including
	 * skipped rows (empty SKU, etc.).
	 *
	 * @var int
	 */
	public int $total_rows_read = 0;

	/**
	 * Number of rows skipped because the SKU cell was empty.
	 *
	 * @var int
	 */
	public int $rows_skipped = 0;

	/**
	 * Human-readable skip/warning messages for rows that were not processed.
	 *
	 * Each entry: [ 'row' => int (1-based), 'message' => string ]
	 *
	 * @var array<int, array{row: int, message: string}>
	 */
	public array $warnings = [];

	/**
	 * Record a warning for a skipped or partially-invalid row.
	 *
	 * @param int    $row_number 1-based row index (header = 0, first data row = 1).
	 * @param string $message    Human-readable description of the issue.
	 * @return void
	 */
	public function add_warning( int $row_number, string $message ): void {
		$this->warnings[] = [
			'row'     => $row_number,
			'message' => $message,
		];
	}
}
