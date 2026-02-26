<?php
/**
 * Static Utility Helpers.
 *
 * A collection of pure static utility functions shared across the plugin.
 * All methods are stateless: they produce the same output for the same input
 * and have no side effects other than filesystem operations in ensure_log_dir().
 *
 * Design notes:
 *   - No constructor; this class is never instantiated.
 *   - Methods are intentionally narrow in scope. If a helper grows complex
 *     or stateful, it should be promoted to its own class.
 *   - All sanitisation methods wrap WordPress core functions to centralise
 *     the sanitisation strategy and make future changes easy.
 *
 * @package WDPLP
 * @since   1.0.0
 */

namespace WDPLP;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Helpers
 */
final class Helpers {

	// ---------------------------------------------------------------------------
	// Sanitisation
	// ---------------------------------------------------------------------------

	/**
	 * Sanitise a product SKU string.
	 *
	 * WooCommerce SKUs are free-form strings. We trim whitespace and remove
	 * any characters that are not alphanumeric, hyphens, underscores, or dots —
	 * the characters WooCommerce itself accepts for SKUs.
	 *
	 * @param string $sku Raw SKU value.
	 * @return string Sanitised SKU, safe for database insertion.
	 */
	public static function sanitize_sku( string $sku ): string {
		// sanitize_text_field handles HTML entities and extra whitespace.
		$sku = sanitize_text_field( $sku );

		// Allow only alphanumeric characters plus the symbols WooCommerce accepts.
		// This prevents injection in contexts where $wpdb->prepare is not used.
		$sku = preg_replace( '/[^a-zA-Z0-9\-_.\/]/', '', $sku );

		return trim( $sku );
	}

	/**
	 * Sanitise a price list code (cod) string.
	 *
	 * Codes come directly from CSV column headers (e.g. "L40", "L50N1").
	 * They should be short uppercase alphanumeric strings, optionally containing
	 * digits and letters only.
	 *
	 * @param string $cod Raw code value.
	 * @return string Sanitised code, uppercase, safe for database insertion.
	 */
	public static function sanitize_cod( string $cod ): string {
		$cod = sanitize_text_field( $cod );

		// Allow alphanumeric characters and hyphens only.
		$cod = preg_replace( '/[^a-zA-Z0-9\-_]/', '', $cod );

		return strtoupper( trim( $cod ) );
	}

	/**
	 * Sanitise and validate a price value from CSV.
	 *
	 * Handles multiple real-world number formats found in exported price lists:
	 *   - Standard:           "68.25"      → 68.25
	 *   - Comma decimal:      "68,25"      → 68.25
	 *   - With currency:      "€ 68,25"    → 68.25
	 *   - Thousands (EU):     "1.234,56"   → 1234.56
	 *   - Thousands (US):     "1,234.56"   → 1234.56
	 *
	 * The decimal separator is determined by which of '.' or ',' appears last
	 * in the string: the last separator is the decimal, all earlier occurrences
	 * of the other separator are treated as thousands markers and removed.
	 *
	 * @param string $value Raw price string from CSV cell.
	 * @return float|null Numeric price, or null if the cell is empty/invalid.
	 */
	public static function sanitize_price( string $value ): ?float {
		$value = trim( $value );

		if ( '' === $value ) {
			return null;
		}

		// Strip everything that cannot be part of a numeric value:
		// currency symbols (€, $, £, …), spaces, letters, etc.
		// Keeps: digits, comma, dot, minus sign, plus sign.
		$value = preg_replace( '/[^\d,.\-+]/', '', $value );

		if ( '' === $value ) {
			return null;
		}

		// Determine which separator plays the role of decimal by finding the
		// last occurrence of each candidate.
		$last_dot   = strrpos( $value, '.' );
		$last_comma = strrpos( $value, ',' );

		if ( false !== $last_dot && false !== $last_comma ) {
			if ( $last_comma > $last_dot ) {
				// Format: "1.234,56" — dot is thousands separator, comma is decimal.
				$value = str_replace( '.', '', $value );   // remove thousands dots
				$value = str_replace( ',', '.', $value );  // decimal comma → dot
			} else {
				// Format: "1,234.56" — comma is thousands separator, dot is decimal.
				$value = str_replace( ',', '', $value );   // remove thousands commas
			}
		} elseif ( false !== $last_comma ) {
			// Only a comma present: treat it as the decimal separator.
			$value = str_replace( ',', '.', $value );
		}
		// If only a dot (or neither): already in standard format — no change needed.

		if ( ! is_numeric( $value ) ) {
			return null;
		}

		return (float) $value;
	}

	// ---------------------------------------------------------------------------
	// Log Directory Management
	// ---------------------------------------------------------------------------

	/**
	 * Return the absolute path to the log directory (trailing slash).
	 *
	 * The directory lives inside wp-content/uploads/ so it is writable by
	 * default on correctly-configured WordPress installations.
	 *
	 * @return string Absolute path with trailing slash.
	 */
	public static function get_log_dir(): string {
		return WDPLP_LOG_DIR;
	}

	/**
	 * Ensure the log directory exists and is protected from direct web access.
	 *
	 * Steps performed:
	 *   1. Create the directory (and any missing parent directories) if absent.
	 *   2. Write an .htaccess file that denies direct HTTP access to all files.
	 *   3. Write a silence index.php guard as a secondary defence.
	 *
	 * Uses wp_mkdir_p() for directory creation (handles permissions correctly
	 * and is multisite-aware).
	 *
	 * @return bool True if the directory exists and is writable after the call;
	 *              false if directory creation failed (e.g. permission error).
	 */
	public static function ensure_log_dir(): bool {
		$log_dir = self::get_log_dir();

		// Create directory if it does not exist.
		if ( ! file_exists( $log_dir ) ) {
			if ( ! wp_mkdir_p( $log_dir ) ) {
				// Directory could not be created (likely a permissions issue).
				return false;
			}
		}

		// Guard 1: .htaccess — prevents Apache from serving any file directly.
		$htaccess_path = $log_dir . '.htaccess';
		if ( ! file_exists( $htaccess_path ) ) {
			file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				$htaccess_path,
				"# Managed by WooCommerce Dynamic Price List Pro — do not delete.\n"
				. "Order allow,deny\n"
				. "Deny from all\n"
			);
		}

		// Guard 2: index.php — prevents directory listing on servers without .htaccess support.
		$index_path = $log_dir . 'index.php';
		if ( ! file_exists( $index_path ) ) {
			file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				$index_path,
				"<?php // Silence is golden.\n"
			);
		}

		return is_writable( $log_dir );
	}

	// ---------------------------------------------------------------------------
	// Date / Time
	// ---------------------------------------------------------------------------

	/**
	 * Return today's date formatted as a MySQL DATE string (YYYY-MM-DD).
	 *
	 * Uses WordPress's current_time() to respect the site timezone configured
	 * in Settings > General, rather than the server's system timezone.
	 *
	 * @return string Date string, e.g. "2026-02-19".
	 */
	public static function today_mysql(): string {
		return current_time( 'Y-m-d' );
	}

	/**
	 * Return the current datetime formatted as a MySQL DATETIME string.
	 *
	 * Uses WordPress's current_time() to respect the site timezone.
	 *
	 * @return string Datetime string, e.g. "2026-02-19 14:30:00".
	 */
	public static function now_mysql(): string {
		return current_time( 'mysql' );
	}

	// ---------------------------------------------------------------------------
	// Miscellaneous
	// ---------------------------------------------------------------------------

	/**
	 * Check whether a string is a valid URL suitable for a remote CSV download.
	 *
	 * Wraps wp_http_validate_url() which checks scheme, structure, and that the
	 * URL is not blocked by WordPress's internal HTTP blocklist (e.g. 127.0.0.1,
	 * 169.254.x.x).
	 *
	 * @param string $url The URL string to validate.
	 * @return bool True if the URL is valid and allowed by WordPress HTTP.
	 */
	public static function is_valid_remote_url( string $url ): bool {
		return (bool) wp_http_validate_url( $url );
	}

	/**
	 * Prevent instantiation. This is a static utility class.
	 */
	private function __construct() {}
}
