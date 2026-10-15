<?php
/**
 * CSV Wide-to-Long Transformer.
 *
 * Converts the "wide" CSV format (one row per product, one column per price list)
 * into the two "long" collections consumed by CsvImporter:
 *
 *   Wide format (input):
 *     SKU     | LORDO  | L40   | L45   | L50N1
 *     PROD-01 | 100.00 | 85.00 | 80.00 | 75.00
 *     PROD-02 | 200.00 | 170.00|       | 160.00
 *
 *   Long format (output — wc_price_updates):
 *     PROD-01 → 100.00
 *     PROD-02 → 200.00
 *
 *   Long format (output — price_list_rows):
 *     PROD-01 / L40  / 85.00  / 2026-02-19
 *     PROD-01 / L45  / 80.00  / 2026-02-19
 *     PROD-01 / L50N1/ 75.00  / 2026-02-19
 *     PROD-02 / L40  / 170.00 / 2026-02-19
 *     PROD-02 / L50N1/ 160.00 / 2026-02-19  ← L45 was empty, skipped
 *
 * Design principles:
 *   - Pure transformation class: no database calls, no WordPress side-effects.
 *   - Accepts the date_from value as a parameter (injected by CsvImporter from
 *     Helpers::today_mysql()) so that this class remains unit-testable without
 *     a WordPress environment.
 *   - Column detection is case-insensitive: "sku", "SKU", "Sku" all match.
 *   - Empty numeric cells are silently skipped (no price_list_row created).
 *   - Invalid (non-numeric) price cells produce a warning in TransformResult.
 *
 * @package WCPL\Import
 * @since   1.0.0
 */

namespace WCPL\Import;

use WCPL\Helpers;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CsvTransformer
 */
class CsvTransformer {

	/**
	 * Expected column name for the product SKU (case-insensitive match).
	 *
	 * @var string
	 */
	private const COL_SKU = 'sku';

	/**
	 * Expected column name for the gross/regular price (case-insensitive match).
	 *
	 * @var string
	 */
	private const COL_LORDO = 'lordo';

	// ---------------------------------------------------------------------------
	// Public API
	// ---------------------------------------------------------------------------

	/**
	 * Transform an array of raw CSV rows into two output collections.
	 *
	 * @param string[] $headers   The header row from the CSV file, as a plain
	 *                            indexed array of column name strings.
	 *                            Example: ['SKU', 'LORDO', 'L40', 'L45']
	 *
	 * @param array<int, string[]> $rows
	 *                            All data rows from the CSV. Each element is an
	 *                            indexed array aligned with $headers.
	 *                            Example: [['PROD-01', '100.00', '85.00', '80.00'], ...]
	 *
	 * @param string $date_from   MySQL DATE string to use as date_from for every
	 *                            price_list_row. Pass Helpers::today_mysql().
	 *
	 * @return TransformResult    Populated result with wc_price_updates,
	 *                            price_list_rows, and warning messages.
	 */
	public function transform( array $headers, array $rows, string $date_from ): TransformResult {
		$result = new TransformResult();

		// Detect column indices from the headers array.
		$column_map = $this->build_column_map( $headers );

		// If no SKU column is found, the whole file is invalid — bail early.
		if ( ! isset( $column_map['sku_index'] ) ) {
			$result->add_warning( 0, 'No SKU column found in CSV headers. Expected a column named "SKU" (case-insensitive).' );
			return $result;
		}

		$sku_index    = $column_map['sku_index'];
		$lordo_index  = $column_map['lordo_index'] ?? null;
		$price_cols   = $column_map['price_cols']; // [ col_index => cod_string ]

		// Process each data row.
		foreach ( $rows as $row_number_zero_based => $row ) {
			// Row number for messages is 1-based (header = 0, first data row = 1).
			$row_num = $row_number_zero_based + 1;
			++$result->total_rows_read;

			// Extract and sanitise the SKU value.
			$raw_sku = isset( $row[ $sku_index ] ) ? trim( (string) $row[ $sku_index ] ) : '';
			$sku     = Helpers::sanitize_sku( $raw_sku );

			if ( '' === $sku ) {
				// Row has no SKU — nothing to associate data with.
				++$result->rows_skipped;
				$result->add_warning( $row_num, 'Row skipped: SKU cell is empty.' );
				continue;
			}

			// Collect columns whose price value is non-empty but unparseable, so
			// we can emit a single grouped warning per row instead of one per column.
			$invalid_cols = [];

			// ── LORDO column → WooCommerce regular price update ────────────────
			if ( $lordo_index !== null ) {
				$raw_lordo = isset( $row[ $lordo_index ] ) ? (string) $row[ $lordo_index ] : '';
				$lordo     = Helpers::sanitize_price( $raw_lordo );

				if ( $lordo !== null ) {
					$result->wc_price_updates[] = [
						'sku'   => $sku,
						'price' => $lordo,
					];
				} elseif ( '' !== trim( $raw_lordo ) ) {
					// Non-empty but unparseable — collect for the grouped warning.
					$invalid_cols[] = 'LORDO';
				}
			}

			// ── Price list columns → custom_price_lists rows ──────────────────
			$has_any_price = false;

			foreach ( $price_cols as $col_index => $cod ) {
				$raw_price = isset( $row[ $col_index ] ) ? (string) $row[ $col_index ] : '';
				$price     = Helpers::sanitize_price( $raw_price );

				if ( $price === null ) {
					if ( '' !== trim( $raw_price ) ) {
						// Non-empty but unparseable — collect for the grouped warning.
						$invalid_cols[] = $cod;
					}
					// Empty cell: silently skip (expected for sparse price lists).
					continue;
				}

				$result->price_list_rows[] = [
					'sku'       => $sku,
					'cod'       => Helpers::sanitize_cod( $cod ),
					'price'     => $price,
					'date_from' => $date_from,
				];

				$has_any_price = true;
			}

			// Emit a single grouped warning for all unparseable price columns in
			// this row, instead of one warning per column.
			if ( ! empty( $invalid_cols ) ) {
				$result->add_warning(
					$row_num,
					sprintf(
						'SKU %s: invalid price format in %d column(s) (%s) — check number format (expected numeric, got non-numeric value).',
						$sku,
						count( $invalid_cols ),
						implode( ', ', $invalid_cols )
					)
				);
			}

			// If neither LORDO nor any list price was found for this SKU, warn.
			if ( $lordo_index === null && ! $has_any_price ) {
				++$result->rows_skipped;
				$result->add_warning( $row_num, "SKU {$sku}: no price data found in any column — row skipped." );
			}
		}

		return $result;
	}

	// ---------------------------------------------------------------------------
	// Private Helpers
	// ---------------------------------------------------------------------------

	/**
	 * Analyse the CSV headers and return a column mapping array.
	 *
	 * The returned array contains:
	 *   sku_index   (int|null)        — index of the SKU column
	 *   lordo_index (int|null)        — index of the LORDO column (null if absent)
	 *   price_cols  (array<int,string>) — [ col_index => cod_string ] for all
	 *                                    remaining columns (the price lists)
	 *
	 * Column matching is case-insensitive and trims surrounding whitespace.
	 * Column headers used as cod values are NOT lowercased — they are stored
	 * as-is (after sanitize_cod, which uppercases them) so that "L40" is stored
	 * as "L40", matching the CSV header exactly.
	 *
	 * @param string[] $headers Raw header row from the CSV.
	 * @return array<string, mixed>
	 */
	private function build_column_map( array $headers ): array {
		$sku_index   = null;
		$lordo_index = null;
		$price_cols  = [];

		foreach ( $headers as $index => $header ) {
			$normalised = strtolower( trim( $header ) );

			if ( self::COL_SKU === $normalised ) {
				$sku_index = $index;
			} elseif ( self::COL_LORDO === $normalised ) {
				$lordo_index = $index;
			} elseif ( '' !== $normalised ) {
				// Any non-empty header that is not SKU or LORDO is a price list code.
				$price_cols[ $index ] = trim( $header ); // keep original case for cod
			}
		}

		return [
			'sku_index'   => $sku_index,
			'lordo_index' => $lordo_index,
			'price_cols'  => $price_cols,
		];
	}
}
