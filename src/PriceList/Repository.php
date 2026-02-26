<?php
/**
 * Price List Repository.
 *
 * The single point of access for all database operations on the
 * {prefix}custom_price_lists table. Every method that reads or writes
 * the table must go through this class — no other class should build
 * raw SQL against the custom table directly.
 *
 * Design principles:
 *   - $wpdb is injected via the constructor (testability) and defaults to
 *     the global instance (convenience for normal WordPress usage).
 *   - All queries that accept user-supplied values use $wpdb->prepare().
 *   - Write methods return meaningful values (rows affected / boolean) so
 *     callers can check success without catching exceptions.
 *   - Read methods return typed PHP values (never raw $wpdb row objects).
 *
 * @package WDPLP\PriceList
 * @since   1.0.0
 */

namespace WDPLP\PriceList;

use WDPLP\Database;
use WDPLP\Helpers;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Repository
 */
class Repository {

	/**
	 * WordPress database object.
	 *
	 * @var \wpdb
	 */
	private \wpdb $wpdb;

	// ---------------------------------------------------------------------------
	// Constructor
	// ---------------------------------------------------------------------------

	/**
	 * Initialise the repository.
	 *
	 * Accepts an optional $wpdb instance for testing purposes. In production
	 * WordPress usage, the global $wpdb is used automatically.
	 *
	 * @param \wpdb|null $wpdb Optional wpdb instance for dependency injection.
	 */
	public function __construct( ?\wpdb $wpdb = null ) {
		if ( null !== $wpdb ) {
			$this->wpdb = $wpdb;
		} else {
			global $wpdb;
			$this->wpdb = $wpdb;
		}
	}

	// ---------------------------------------------------------------------------
	// Write Methods
	// ---------------------------------------------------------------------------

	/**
	 * Insert or update a single price list row.
	 *
	 * Uses INSERT … ON DUPLICATE KEY UPDATE so that re-importing the same
	 * (sku, cod, date_from) combination updates the price rather than failing
	 * on the UNIQUE KEY constraint.
	 *
	 * $data keys:
	 *   sku       (string)  — product SKU, sanitised by caller
	 *   cod       (string)  — price list code, e.g. "L40"
	 *   price     (float)   — list price
	 *   date_from (string)  — MySQL DATE string "YYYY-MM-DD" (defaults to today)
	 *   status    (int)     — 1 = active (default)
	 *
	 * @param array<string, mixed> $data Associative array of column values.
	 * @return bool True if the row was inserted or updated successfully.
	 */
	public function upsert_row( array $data ): bool {
		$table    = Database::get_table_name();
		$today    = Helpers::today_mysql();
		$now      = Helpers::now_mysql();

		$result = $this->wpdb->query(
			$this->wpdb->prepare(
				"INSERT INTO `{$table}` (sku, cod, price, date_from, created_at, status)
				 VALUES (%s, %s, %f, %s, %s, %d)
				 ON DUPLICATE KEY UPDATE
				   price      = VALUES(price),
				   created_at = VALUES(created_at),
				   status     = VALUES(status)",
				$data['sku'],
				$data['cod'],
				(float) ( $data['price'] ?? 0 ),
				$data['date_from'] ?? $today,
				$now,
				(int) ( $data['status'] ?? 1 )
			)
		);

		// $wpdb->query() returns the number of rows affected (int) or false on error.
		// ON DUPLICATE KEY UPDATE returns 2 for an update, 1 for an insert.
		return false !== $result;
	}

	/**
	 * Batch upsert multiple price list rows.
	 *
	 * Iterates over $rows and calls upsert_row() for each. Returns the total
	 * count of successful operations (inserts + updates).
	 *
	 * For very large imports (10k+ rows) a bulk INSERT would be faster, but
	 * the per-row approach is sufficient for typical price-list sizes and keeps
	 * the code simple and auditable.
	 *
	 * @param array<int, array<string, mixed>> $rows Array of row data arrays.
	 * @return int Number of rows successfully inserted or updated.
	 */
	public function upsert_rows( array $rows ): int {
		$count = 0;

		foreach ( $rows as $row ) {
			if ( $this->upsert_row( $row ) ) {
				++$count;
			}
		}

		return $count;
	}

	// ---------------------------------------------------------------------------
	// Read Methods
	// ---------------------------------------------------------------------------

	/**
	 * Fetch the most recent active price for a given SKU and price list code.
	 *
	 * When multiple rows exist for the same (sku, cod) pair with different
	 * date_from values, the row with the latest date is returned (i.e. the
	 * currently applicable price).
	 *
	 * @param string $sku Product SKU.
	 * @param string $cod Price list code, e.g. "L40".
	 * @return float|null The price as a float, or null if no active row exists.
	 */
	public function get_price_for_sku_and_cod( string $sku, string $cod ): ?float {
		$table = Database::get_table_name();

		$price = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT price
				 FROM   `{$table}`
				 WHERE  sku    = %s
				   AND  cod    = %s
				   AND  status = 1
				 ORDER BY date_from DESC
				 LIMIT 1",
				$sku,
				$cod
			)
		);

		return $price !== null ? (float) $price : null;
	}

	/**
	 * Return all distinct, active price list codes (cod values) sorted alphabetically.
	 *
	 * Used to populate the "Assigned Price List" dropdown on user profile pages.
	 * Returns an empty array if the table is empty or all rows are inactive.
	 *
	 * @return string[] Sorted array of unique cod strings.
	 */
	public function get_all_cod_values(): array {
		$table = Database::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $this->wpdb->get_col(
			"SELECT DISTINCT cod FROM `{$table}` WHERE status = 1 ORDER BY cod ASC"
		);

		return $results ?: [];
	}

	/**
	 * Return a summary of recent import activity grouped by import date.
	 *
	 * Queries the table's created_at column to identify when imports happened
	 * and how many rows/distinct SKUs were inserted on each date.
	 * Used by the admin Logs page to show import history.
	 *
	 * Each returned element:
	 * [
	 *   'import_date'   => string  "YYYY-MM-DD"
	 *   'sku_count'     => int     distinct SKUs touched on that date
	 *   'row_count'     => int     total rows inserted/updated on that date
	 * ]
	 *
	 * @param int $limit Maximum number of dates to return. Defaults to 30.
	 * @return array<int, array{import_date: string, sku_count: int, row_count: int}>
	 */
	public function get_recent_import_summary( int $limit = 30 ): array {
		$table = Database::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT
				   DATE(created_at)        AS import_date,
				   COUNT(DISTINCT sku)     AS sku_count,
				   COUNT(*)                AS row_count
				 FROM   `{$table}`
				 GROUP  BY DATE(created_at)
				 ORDER  BY import_date DESC
				 LIMIT  %d",
				$limit
			),
			ARRAY_A
		);

		if ( ! $rows ) {
			return [];
		}

		// Cast numeric strings returned by MySQL to PHP ints.
		return array_map(
			static fn( array $row ): array => [
				'import_date' => (string) $row['import_date'],
				'sku_count'   => (int) $row['sku_count'],
				'row_count'   => (int) $row['row_count'],
			],
			$rows
		);
	}

	/**
	 * Check whether the custom table exists in the database.
	 *
	 * Useful for diagnostic purposes (e.g. admin health-check) and as a guard
	 * before running queries if there is any doubt the table was created.
	 *
	 * @return bool True if the table exists.
	 */
	public function table_exists(): bool {
		$table = Database::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $this->wpdb->get_var(
			$this->wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
		);

		return $result === $table;
	}

	/**
	 * Count the total number of active rows in the price list table.
	 *
	 * @return int Total active row count.
	 */
	public function count_active_rows(): int {
		$table = Database::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $this->wpdb->get_var(
			"SELECT COUNT(*) FROM `{$table}` WHERE status = 1"
		);
	}

	/**
	 * Fetch a paginated, filtered slice of active price list rows.
	 *
	 * Used by the admin Data tab to display the custom_price_lists table with
	 * optional SKU (LIKE) and COD (exact) filters.
	 *
	 * Rows are ordered by sku ASC, cod ASC, date_from DESC so that the most
	 * recent price for each (sku, cod) pair appears first within each group.
	 *
	 * @param int    $page        1-based page number.
	 * @param int    $per_page    Rows per page (default 50).
	 * @param string $filter_sku  Partial SKU to match with LIKE. Empty = no filter.
	 * @param string $filter_cod  Exact COD to match. Empty = no filter.
	 * @return array<int, array{id: int, sku: string, cod: string, price: string, date_from: string, created_at: string}>
	 */
	public function get_rows_paginated(
		int    $page       = 1,
		int    $per_page   = 50,
		string $filter_sku = '',
		string $filter_cod = ''
	): array {
		$table  = Database::get_table_name();
		$offset = ( max( 1, $page ) - 1 ) * $per_page;

		[ $where, $params ] = $this->build_filter_where( $filter_sku, $filter_cod );

		$params[] = $per_page;
		$params[] = $offset;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT id, sku, cod, price, date_from, created_at
				 FROM   `{$table}`
				 WHERE  {$where}
				 ORDER  BY sku ASC, cod ASC, date_from DESC
				 LIMIT  %d OFFSET %d",
				...$params
			),
			ARRAY_A
		);

		return $rows ?: [];
	}

	/**
	 * Count active rows matching the given filters.
	 *
	 * Used by the admin Data tab for pagination calculation.
	 *
	 * @param string $filter_sku Partial SKU (LIKE). Empty = no filter.
	 * @param string $filter_cod Exact COD. Empty = no filter.
	 * @return int Total matching row count.
	 */
	public function count_rows_filtered( string $filter_sku = '', string $filter_cod = '' ): int {
		$table = Database::get_table_name();

		[ $where, $params ] = $this->build_filter_where( $filter_sku, $filter_cod );

		if ( empty( $params ) ) {
			// No user-supplied values — safe to run without prepare().
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return (int) $this->wpdb->get_var(
				"SELECT COUNT(*) FROM `{$table}` WHERE {$where}"
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT COUNT(*) FROM `{$table}` WHERE {$where}",
				...$params
			)
		);
	}

	/**
	 * Return all distinct active COD values, sorted alphabetically.
	 *
	 * Used to populate the COD filter dropdown in the Data tab.
	 *
	 * @return string[] Sorted list of unique COD strings.
	 */
	public function get_distinct_cods(): array {
		$table = Database::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $this->wpdb->get_col(
			"SELECT DISTINCT cod FROM `{$table}` WHERE status = 1 ORDER BY cod ASC"
		);

		return is_array( $results ) ? $results : [];
	}

	// ---------------------------------------------------------------------------
	// Private Helpers
	// ---------------------------------------------------------------------------

	/**
	 * Build the WHERE clause and corresponding prepared values for filter queries.
	 *
	 * Always includes `status = 1`. Appends SKU (LIKE) and COD (=) conditions
	 * when the respective filter strings are non-empty.
	 *
	 * @param string $filter_sku Partial SKU to match. Empty = skip.
	 * @param string $filter_cod Exact COD to match. Empty = skip.
	 * @return array{ 0: string, 1: list<mixed> } [ where_sql, params_array ]
	 */
	private function build_filter_where( string $filter_sku, string $filter_cod ): array {
		$parts  = [ 'status = 1' ];
		$params = [];

		if ( '' !== $filter_sku ) {
			$parts[]  = 'sku LIKE %s';
			$params[] = '%' . $this->wpdb->esc_like( $filter_sku ) . '%';
		}

		if ( '' !== $filter_cod ) {
			$parts[]  = 'cod = %s';
			$params[] = $filter_cod;
		}

		return [ implode( ' AND ', $parts ), $params ];
	}
}
