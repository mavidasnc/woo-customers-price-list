<?php
/**
 * Remote CSV Downloader.
 *
 * Downloads a CSV file from a remote URL to a local temporary file using
 * WordPress's wp_remote_get() (which respects the HTTP API configuration,
 * SSL verification, proxies, and WordPress's URL blocklist).
 *
 * Security notes:
 *   - The URL is validated by Helpers::is_valid_remote_url() (which calls
 *     wp_http_validate_url()) before making any request. This blocks requests
 *     to localhost, loopback addresses, and private IP ranges when WordPress's
 *     HTTP security is enabled.
 *   - The downloaded content is written to a temp file via wp_tempnam(), which
 *     is placed in the system's temporary directory, not inside the webroot.
 *   - The temp file is deleted by the caller (CsvImporter) after use.
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
 * Class RemoteDownloader
 */
class RemoteDownloader {

	/**
	 * Default HTTP request timeout in seconds.
	 *
	 * Can be overridden via the wdplp_remote_download_timeout filter.
	 *
	 * @var int
	 */
	private const DEFAULT_TIMEOUT = 60;

	/**
	 * Maximum allowed response body size in bytes (10 MB).
	 *
	 * Prevents memory exhaustion when an unexpectedly large file is served.
	 * Note: wp_remote_get() loads the full body into memory by default; to
	 * handle truly large files a streaming approach would be required. 10 MB
	 * covers the vast majority of real-world price-list CSVs.
	 *
	 * @var int
	 */
	private const MAX_BODY_SIZE = 10 * 1024 * 1024; // 10 MB

	// ---------------------------------------------------------------------------
	// Public API
	// ---------------------------------------------------------------------------

	/**
	 * Download a remote CSV file and save it to a local temp file.
	 *
	 * @param string $url The remote URL of the CSV file.
	 * @return string     Absolute path to the downloaded temporary file.
	 *
	 * @throws \RuntimeException If the URL is invalid, the request fails,
	 *                           the HTTP status is not 200, the response is
	 *                           empty, or the file cannot be written.
	 */
	public function download( string $url ): string {
		// ── 1. Validate URL ────────────────────────────────────────────────────
		if ( ! Helpers::is_valid_remote_url( $url ) ) {
			throw new \RuntimeException(
				sprintf( 'Invalid or disallowed URL: "%s".', esc_url_raw( $url ) )
			);
		}

		// ── 2. Determine timeout (filterable) ──────────────────────────────────
		/**
		 * Filter the HTTP request timeout for remote CSV downloads.
		 *
		 * @since 1.0.0
		 * @param int    $timeout Timeout in seconds. Default 60.
		 * @param string $url     The URL being downloaded.
		 */
		$timeout = (int) apply_filters( 'wdplp_remote_download_timeout', self::DEFAULT_TIMEOUT, $url );

		// ── 3. Make the HTTP request ───────────────────────────────────────────
		$response = wp_remote_get(
			$url,
			[
				'timeout'    => $timeout,
				'user-agent' => 'WordPress/WDPLP-Importer (+' . get_bloginfo( 'url' ) . ')',
				'sslverify'  => true, // Never disable SSL verification in production.
			]
		);

		// ── 4. Check for WP_Error ──────────────────────────────────────────────
		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException(
				sprintf( 'HTTP request failed for URL "%s": %s', esc_url_raw( $url ), $response->get_error_message() )
			);
		}

		// ── 5. Validate HTTP status code ───────────────────────────────────────
		$status_code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			throw new \RuntimeException(
				sprintf( 'Remote URL returned HTTP %d for "%s".', $status_code, esc_url_raw( $url ) )
			);
		}

		// ── 6. Retrieve and validate body ──────────────────────────────────────
		$body = wp_remote_retrieve_body( $response );

		if ( empty( $body ) ) {
			throw new \RuntimeException(
				sprintf( 'Remote URL returned an empty response body for "%s".', esc_url_raw( $url ) )
			);
		}

		if ( strlen( $body ) > self::MAX_BODY_SIZE ) {
			throw new \RuntimeException(
				sprintf(
					'Remote file exceeds maximum allowed size (%d MB) for "%s".',
					self::MAX_BODY_SIZE / ( 1024 * 1024 ),
					esc_url_raw( $url )
				)
			);
		}

		// ── 7. Validate Content-Type (soft check — some servers send wrong type) ──
		$content_type = wp_remote_retrieve_header( $response, 'content-type' );
		if ( $content_type && ! $this->is_acceptable_content_type( $content_type ) ) {
			// Log a warning but don't block — the server may misconfigure Content-Type.
			// CsvImporter will catch any parse errors downstream.
		}

		// ── 8. Write body to a temporary file ─────────────────────────────────
		// wp_tempnam() creates a unique temp file in the system temp directory.
		$temp_file = wp_tempnam( 'wdplp-csv-' );

		if ( false === $temp_file ) {
			throw new \RuntimeException( 'Could not create a temporary file for the downloaded CSV.' );
		}

		$bytes_written = file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$temp_file,
			$body
		);

		if ( false === $bytes_written ) {
			@unlink( $temp_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			throw new \RuntimeException(
				sprintf( 'Could not write downloaded CSV to temp file "%s".', $temp_file )
			);
		}

		return $temp_file;
	}

	// ---------------------------------------------------------------------------
	// Private Helpers
	// ---------------------------------------------------------------------------

	/**
	 * Check whether the Content-Type header suggests a CSV / text file.
	 *
	 * Acceptable content types:
	 *   text/csv, text/plain, application/csv, application/octet-stream,
	 *   application/vnd.ms-excel
	 *
	 * @param string $content_type The raw Content-Type header value.
	 * @return bool True if the content type is likely a CSV.
	 */
	private function is_acceptable_content_type( string $content_type ): bool {
		$acceptable = [
			'text/csv',
			'text/plain',
			'application/csv',
			'application/octet-stream',
			'application/vnd.ms-excel',
		];

		// Extract the MIME type (before any semicolon charset parameter).
		$mime = strtolower( trim( explode( ';', $content_type )[0] ) );

		return in_array( $mime, $acceptable, true );
	}
}
