<?php
/**
 * Import Page — Form Handler and Renderer.
 *
 * Responsibilities:
 *   - Hooks into `admin_post_wcpl_import` to process the import form
 *     submission (nonce, capability, file/URL validation, import run, redirect).
 *   - Renders the import form HTML (called by AdminController on the "import" tab).
 *
 * POST processing flow:
 *   1. Verify nonce and capability.
 *   2. Determine source: 'file' (upload) or 'url' (remote).
 *   3. Validate the provided input.
 *   4. Run CsvImporter::import_from_file() or ::import_from_url().
 *   5. Store the ImportReport as a transient keyed by user ID.
 *   6. Redirect back to the admin page with ?imported=1.
 *
 * The import result is retrieved and deleted by AdminController::render_page()
 * and passed here via render_content() on the next page load.
 *
 * Security:
 *   - Nonce: action 'wcpl_import', field name 'wcpl_import_nonce'.
 *   - Capability: 'manage_woocommerce'.
 *   - File extension whitelist: .csv, .txt.
 *   - URL validation: Helpers::is_valid_remote_url() (wraps wp_http_validate_url).
 *
 * @package WCPL\Admin
 * @since   1.0.0
 */

namespace WCPL\Admin;

use WCPL\Helpers;
use WCPL\Import\CsvImporter;
use WCPL\Import\ImportReport;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ImportPage
 */
class ImportPage {

	/**
	 * Nonce action and field name constants.
	 */
	private const NONCE_ACTION = 'wcpl_import';
	private const NONCE_FIELD  = 'wcpl_import_nonce';

	/**
	 * POST action name (used in the form's hidden 'action' field
	 * and mapped to admin_post_{action}).
	 */
	private const POST_ACTION = 'wcpl_import';

	/**
	 * Allowed file extensions for uploaded CSV files.
	 *
	 * @var string[]
	 */
	private const ALLOWED_EXTENSIONS = [ 'csv', 'txt' ];

	/**
	 * Transient lifetime for the import result (in seconds).
	 * 5 minutes is enough time for the redirect + page load cycle.
	 *
	 * @var int
	 */
	private const RESULT_TRANSIENT_TTL = 5 * MINUTE_IN_SECONDS;

	/**
	 * User meta keys for persisting last import preferences across sessions.
	 * Stored per-user so multiple admins do not overwrite each other's settings.
	 */
	private const META_LAST_SOURCE = '_wcpl_last_import_source';
	private const META_LAST_URL    = '_wcpl_last_import_url';
	private const META_LAST_LIMIT  = '_wcpl_last_import_limit';

	// ---------------------------------------------------------------------------
	// Hooks
	// ---------------------------------------------------------------------------

	/**
	 * Register all WordPress hooks needed by this class.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		// Handle the import form POST. The hook fires for authenticated users;
		// unauthenticated POST attempts go to admin_post_nopriv_wcpl_import
		// which we intentionally do not register.
		add_action( 'admin_post_' . self::POST_ACTION, [ $this, 'handle_import' ] );
	}

	// ---------------------------------------------------------------------------
	// POST Handler
	// ---------------------------------------------------------------------------

	/**
	 * Process the import form submission.
	 *
	 * Validates all inputs, runs the appropriate importer, stores the result,
	 * and redirects back to the admin page.
	 *
	 * @return void
	 */
	public function handle_import(): void {
		// ── Security: nonce + capability ──────────────────────────────────────
		check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die(
				esc_html__( 'You do not have permission to perform this action.', WCPL_TEXT_DOMAIN ),
				esc_html__( 'Permission Denied', WCPL_TEXT_DOMAIN ),
				[ 'response' => 403 ]
			);
		}

		$importer = new CsvImporter();
		$report   = null;
		$error    = '';

		// ── Determine source ───────────────────────────────────────────────────
		$source = isset( $_POST['wcpl_source'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			? sanitize_key( wp_unslash( $_POST['wcpl_source'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			: 'file';

		// ── Row limit (0 = no limit) ───────────────────────────────────────────
		$limit = isset( $_POST['wcpl_limit_rows'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			? max( 0, (int) $_POST['wcpl_limit_rows'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			: 0;

		if ( 'url' === $source ) {
			// ── URL import ─────────────────────────────────────────────────────
			$raw_url = isset( $_POST['wcpl_url'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
				? sanitize_text_field( wp_unslash( $_POST['wcpl_url'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
				: '';

			$url = esc_url_raw( $raw_url );

			if ( empty( $url ) ) {
				$error = __( 'Please enter a valid CSV URL to import from.', WCPL_TEXT_DOMAIN );
			} elseif ( ! Helpers::is_valid_remote_url( $url ) ) {
				$error = __( 'The URL provided is not valid or is not allowed.', WCPL_TEXT_DOMAIN );
			} else {
				$report = $importer->import_from_url( $url, false, $limit );
			}
		} else {
			// ── File upload import ─────────────────────────────────────────────
			$upload_error = $_FILES['wcpl_csv_file']['error'] ?? UPLOAD_ERR_NO_FILE; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

			if ( UPLOAD_ERR_NO_FILE === $upload_error ) {
				$error = __( 'Please select a CSV file to upload.', WCPL_TEXT_DOMAIN );
			} elseif ( UPLOAD_ERR_OK !== $upload_error ) {
				$error = sprintf(
					/* translators: %d: PHP file upload error code */
					__( 'File upload failed with error code %d. Please try again.', WCPL_TEXT_DOMAIN ),
					(int) $upload_error
				);
			} else {
				$tmp_name  = $_FILES['wcpl_csv_file']['tmp_name'] ?? ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				$orig_name = $_FILES['wcpl_csv_file']['name'] ?? ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

				if ( ! $this->is_valid_csv_upload( (string) $tmp_name, (string) $orig_name ) ) {
					$error = __( 'Invalid file type. Please upload a .csv or .txt file.', WCPL_TEXT_DOMAIN );
				} else {
					$report = $importer->import_from_file( (string) $tmp_name, false, $limit );
				}
			}
		}

		// ── Build synthetic error report if validation failed ──────────────────
		if ( ! empty( $error ) && null === $report ) {
			$report = new ImportReport();
			$report->add_error( $error );
			$report->finish();
		}

		// ── Store result in transient for display after redirect ───────────────
		if ( null !== $report ) {
			$transient_key = 'wcpl_import_result_' . get_current_user_id();
			set_transient( $transient_key, $report->to_array(), self::RESULT_TRANSIENT_TTL );
		}

		// ── Persist per-user import preferences for pre-filling the form ──────
		$user_id = get_current_user_id();
		update_user_meta( $user_id, self::META_LAST_SOURCE, $source );
		update_user_meta( $user_id, self::META_LAST_LIMIT,  $limit );
		// $url is defined only in the 'url' branch; save it only when meaningful.
		if ( 'url' === $source && ! empty( $url ) ) {
			update_user_meta( $user_id, self::META_LAST_URL, esc_url_raw( $url ) );
		}

		// ── Redirect back to the import tab ────────────────────────────────────
		wp_safe_redirect(
			add_query_arg(
				[
					'page'     => AdminController::PAGE_SLUG,
					'tab'      => 'import',
					'imported' => '1',
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	// ---------------------------------------------------------------------------
	// Rendering
	// ---------------------------------------------------------------------------

	/**
	 * Render the import tab content (form + optional result notice).
	 *
	 * Called by AdminController::render_page() when the 'import' tab is active.
	 * The $import_result array (from a previous POST) is passed in if available.
	 *
	 * @param array<string, mixed> $import_result Serialised ImportReport from to_array(),
	 *                                            or empty array if no result to display.
	 * @return void
	 */
	public function render_content( array $import_result = [] ): void {
		$user_id    = get_current_user_id();
		$result     = $import_result;
		$action_url = esc_url( admin_url( 'admin-post.php' ) );
		$nonce      = wp_create_nonce( self::NONCE_ACTION );

		// Load per-user last import preferences for pre-filling the form.
		$last_source = get_user_meta( $user_id, self::META_LAST_SOURCE, true ) ?: 'file';
		$last_url    = get_user_meta( $user_id, self::META_LAST_URL,    true ) ?: '';
		$last_limit  = (int) ( get_user_meta( $user_id, self::META_LAST_LIMIT, true ) ?: 0 );

		require WCPL_PLUGIN_DIR . 'admin/views/import-page.php';
	}

	// ---------------------------------------------------------------------------
	// Private Helpers
	// ---------------------------------------------------------------------------

	/**
	 * Validate an uploaded file as a CSV/text file.
	 *
	 * Checks:
	 *   1. The temp file actually exists on disk (basic sanity check).
	 *   2. The original filename has an allowed extension (.csv or .txt).
	 *
	 * We intentionally do NOT check MIME type because browsers report different
	 * Content-Types for CSV files (text/csv, text/plain, application/octet-stream,
	 * application/vnd.ms-excel). The actual content validation happens in the
	 * parser when it tries to read the headers.
	 *
	 * @param string $tmp_name   Path to the uploaded temp file.
	 * @param string $orig_name  Original filename as provided by the browser.
	 * @return bool True if the file passes validation.
	 */
	private function is_valid_csv_upload( string $tmp_name, string $orig_name ): bool {
		if ( empty( $tmp_name ) || ! is_uploaded_file( $tmp_name ) ) {
			return false;
		}

		$ext = strtolower( pathinfo( $orig_name, PATHINFO_EXTENSION ) );

		return in_array( $ext, self::ALLOWED_EXTENSIONS, true );
	}
}
