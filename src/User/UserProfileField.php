<?php
/**
 * User Profile Field — "Assigned Price List" Dropdown.
 *
 * Adds a "Price List Settings" section to the WordPress user profile pages
 * (both profile.php for self-edit and user-edit.php for admin editing).
 * The section contains a single <select> dropdown that lets an administrator
 * assign one price list code (cod) to a user account.
 *
 * The selected value is stored in user meta as `_assigned_price_list` and is
 * read by PriceResolver when calculating the frontend price for that user.
 *
 * Hooks registered:
 *   show_user_profile          — renders the field on the user's own profile.
 *   edit_user_profile          — renders the field when an admin edits another user.
 *   personal_options_update    — saves the field when the user updates their own profile.
 *   edit_user_profile_update   — saves the field when an admin updates another user.
 *
 * Security (all three layers of the WordPress Security Trinity):
 *   - Nonce  : wp_verify_nonce() against the WordPress profile update nonce.
 *              (The profile form's own _wpnonce is reused — no extra nonce needed.)
 *   - Capability : current_user_can( 'edit_user', $user_id ) before saving.
 *   - Sanitise   : sanitize_text_field() + validation against the allowed cod list.
 *
 * @package WDPLP\User
 * @since   1.0.0
 */

namespace WDPLP\User;

use WDPLP\PriceList\Repository;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class UserProfileField
 */
class UserProfileField {

	/**
	 * The user meta key used to store the assigned price list code.
	 * The underscore prefix hides this meta from WordPress's custom fields UI.
	 *
	 * @var string
	 */
	public const META_KEY = '_assigned_price_list';

	/**
	 * HTML id / name attribute for the select element.
	 *
	 * @var string
	 */
	private const FIELD_NAME = 'wdplp_assigned_price_list';

	// ---------------------------------------------------------------------------
	// Hooks
	// ---------------------------------------------------------------------------

	/**
	 * Register all WordPress hooks needed by this class.
	 *
	 * Two render hooks (show_user_profile for self, edit_user_profile for others)
	 * and two save hooks (personal_options_update for self, edit_user_profile_update
	 * for others) cover all profile update scenarios.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		// Render hooks.
		add_action( 'show_user_profile', [ $this, 'render_field' ] );
		add_action( 'edit_user_profile', [ $this, 'render_field' ] );

		// Save hooks.
		add_action( 'personal_options_update',  [ $this, 'save_field' ] );
		add_action( 'edit_user_profile_update', [ $this, 'save_field' ] );
	}

	// ---------------------------------------------------------------------------
	// Rendering
	// ---------------------------------------------------------------------------

	/**
	 * Render the "Price List Settings" section on the user profile page.
	 *
	 * Outputs a standard WordPress profile-page table row containing a <select>
	 * dropdown populated with all active price list codes from the database.
	 *
	 * If no price lists exist yet (table is empty), a helper link to the import
	 * page is shown so the admin can import a CSV first.
	 *
	 * @param \WP_User $user The user object whose profile is being viewed/edited.
	 * @return void
	 */
	public function render_field( \WP_User $user ): void {
		// Only users who can edit this profile should see the field.
		if ( ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}

		$repository  = new Repository();
		$cod_values  = $repository->get_all_cod_values();
		$current_cod = (string) get_user_meta( $user->ID, self::META_KEY, true );

		// Detect "stale" assignment: stored value no longer in active cod list.
		$is_stale = '' !== $current_cod && ! in_array( $current_cod, $cod_values, true );

		?>
		<h2><?php esc_html_e( 'Price List Settings', WDPLP_TEXT_DOMAIN ); ?></h2>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="<?php echo esc_attr( self::FIELD_NAME ); ?>">
						<?php esc_html_e( 'Assigned Price List', WDPLP_TEXT_DOMAIN ); ?>
					</label>
				</th>
				<td>

					<?php if ( empty( $cod_values ) ) : ?>

						<?php // No price lists in the database yet. ─────────────── ?>
						<p class="description">
							<?php
							printf(
								wp_kses(
									/* translators: %s: link to the Price Lists admin page */
									__( 'No price lists found. <a href="%s">Import a CSV</a> to populate the list.', WDPLP_TEXT_DOMAIN ),
									[ 'a' => [ 'href' => [] ] ]
								),
								esc_url( admin_url( 'admin.php?page=wdplp-import&tab=import' ) )
							);
							?>
						</p>

					<?php else : ?>

						<select
							name="<?php echo esc_attr( self::FIELD_NAME ); ?>"
							id="<?php echo esc_attr( self::FIELD_NAME ); ?>"
						>
							<?php // "No assignment" option. ?>
							<option value="">
								— <?php esc_html_e( 'No price list assigned', WDPLP_TEXT_DOMAIN ); ?> —
							</option>

							<?php foreach ( $cod_values as $cod ) : ?>
								<option
									value="<?php echo esc_attr( $cod ); ?>"
									<?php selected( $current_cod, $cod ); ?>
								>
									<?php echo esc_html( $cod ); ?>
								</option>
							<?php endforeach; ?>
						</select>

						<?php // Description. ?>
						<p class="description">
							<?php
							esc_html_e(
								'Determines which price list prices are shown to this user on the frontend.',
								WDPLP_TEXT_DOMAIN
							);
							?>
						</p>

						<?php // Stale assignment warning. ?>
						<?php if ( $is_stale ) : ?>
							<p class="description" style="color:#9c7000;">
								<span class="dashicons dashicons-warning" aria-hidden="true"></span>
								<?php
								printf(
									/* translators: %s: the stale cod value stored in user meta */
									esc_html__( 'The previously assigned list "%s" is no longer active. Please re-assign or select "No price list assigned" to clear it.', WDPLP_TEXT_DOMAIN ),
									esc_html( $current_cod )
								);
								?>
							</p>
						<?php endif; ?>

					<?php endif; ?>

				</td>
			</tr>
		</table>
		<?php
	}

	// ---------------------------------------------------------------------------
	// Saving
	// ---------------------------------------------------------------------------

	/**
	 * Save the "Assigned Price List" field when the user profile is updated.
	 *
	 * Security flow:
	 *   1. Verify the WordPress profile update nonce (reuses the form's own nonce —
	 *      no extra hidden field needed). Returns silently on failure.
	 *   2. Check that the current user has permission to edit this user's profile.
	 *   3. Sanitise and validate the submitted cod value against the allowed list.
	 *   4. Persist with update_user_meta() or clear with delete_user_meta().
	 *
	 * @param int $user_id ID of the user whose profile was just saved.
	 * @return void
	 */
	public function save_field( int $user_id ): void {
		// ── 1. Nonce check ─────────────────────────────────────────────────────
		// WordPress's profile save form always includes a _wpnonce with action
		// "update-user_{user_id}". We verify it without triggering wp_die() so
		// that a failed nonce silently skips saving (the rest of the form still
		// processes normally).
		$nonce = isset( $_POST['_wpnonce'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			: '';

		if ( ! wp_verify_nonce( $nonce, 'update-user_' . $user_id ) ) {
			return;
		}

		// ── 2. Capability check ────────────────────────────────────────────────
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		// ── 3. Sanitise the submitted value ────────────────────────────────────
		$submitted = isset( $_POST[ self::FIELD_NAME ] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			? sanitize_text_field( wp_unslash( $_POST[ self::FIELD_NAME ] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			: '';

		// ── 4. Validate against the allowed cod list ───────────────────────────
		// An empty submission ("No assignment") is always valid.
		// A non-empty value must exist in the current active cod list.
		if ( '' !== $submitted && ! $this->is_valid_cod( $submitted ) ) {
			// Submitted value is not a recognised cod — bail silently.
			// This protects against forged POST requests submitting arbitrary values.
			return;
		}

		// ── 5. Persist ─────────────────────────────────────────────────────────
		if ( '' === $submitted ) {
			// Remove the meta key entirely rather than storing an empty string.
			// This keeps the usermeta table clean and lets get_user_meta()
			// return a falsy value (empty string) as expected.
			delete_user_meta( $user_id, self::META_KEY );
		} else {
			update_user_meta( $user_id, self::META_KEY, $submitted );
		}
	}

	// ---------------------------------------------------------------------------
	// Private Helpers
	// ---------------------------------------------------------------------------

	/**
	 * Check whether a cod string is a currently active price list code.
	 *
	 * Queries the database via Repository to get the live list of active codes.
	 * This prevents storing arbitrary strings in user meta even if a forged POST
	 * bypasses JavaScript validation.
	 *
	 * An empty string always returns true (it represents "no assignment").
	 *
	 * @param string $cod The cod value to validate.
	 * @return bool True if the value is valid (empty or exists in active cod list).
	 */
	private function is_valid_cod( string $cod ): bool {
		if ( '' === $cod ) {
			return true;
		}

		$repository = new Repository();
		$valid_cods = $repository->get_all_cod_values();

		return in_array( $cod, $valid_cods, true );
	}
}
