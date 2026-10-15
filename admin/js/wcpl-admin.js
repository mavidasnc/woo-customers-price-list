/**
 * Customers Price List for WooCommerce — Admin JavaScript
 *
 * Enhances the import admin page with:
 *   1. Source toggle: show/hide the file upload or URL section based on the
 *      selected radio button.
 *   2. Form submission UX: disable the submit button and show a spinner while
 *      the import is running to prevent double submissions.
 *   3. File validation: basic client-side check that the selected file has an
 *      accepted extension (.csv or .txt) before submitting.
 *   4. Updates tab: force a GitHub-release check via wcpl_check_update AJAX.
 *
 * Depends on: jQuery (loaded by WordPress admin).
 * Data: `wcplAdmin` object localised by AdminController::enqueue_assets().
 *
 * @package WCPL
 * @since   1.0.0
 */

/* global wcplAdmin, jQuery */

( function ( $ ) {
	'use strict';

	// Accepted file extensions for client-side validation (lowercase).
	var ACCEPTED_EXTENSIONS = [ 'csv', 'txt' ];

	/**
	 * Initialise all admin page behaviours once the DOM is ready.
	 */
	$( function () {
		initSourceToggle();
		initFormSubmit();
		initUpdates();
	} );

	// ─────────────────────────────────────────────────────────────────────────
	// 1. Source Toggle
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Wire up the "file" / "url" radio buttons to show/hide the corresponding
	 * input section with a smooth transition.
	 *
	 * State is also applied on page load in case the browser restores a
	 * previously selected value (e.g. after a Back navigation).
	 */
	function initSourceToggle() {
		var $radios     = $( 'input[name="wcpl_source"]' );
		var $fileSection = $( '#wcpl-file-section' );
		var $urlSection  = $( '#wcpl-url-section' );

		if ( ! $radios.length ) {
			return; // Not on the import page.
		}

		/**
		 * Show the section matching the currently selected source,
		 * hide the other one.
		 */
		function applyToggle() {
			var selectedSource = $( 'input[name="wcpl_source"]:checked' ).val();

			if ( 'url' === selectedSource ) {
				$fileSection.hide();
				$urlSection.show().find( '#wcpl_url' ).trigger( 'focus' );
			} else {
				$urlSection.hide();
				$fileSection.show().find( '#wcpl_csv_file' ).trigger( 'focus' );
			}
		}

		// Apply on page load.
		applyToggle();

		// Apply on radio change.
		$radios.on( 'change', applyToggle );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// 2. Form Submission UX
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * When the import form is submitted:
	 *   - Run client-side validation (file extension check).
	 *   - If valid, disable the submit button and show the spinner to signal
	 *     that the import is in progress and prevent double submissions.
	 *   - If invalid, show an inline error and stop submission.
	 */
	function initFormSubmit() {
		var $form   = $( '#wcpl-import-form' );
		var $btn    = $( '#wcpl-import-btn' );
		var $spinner = $form.find( '.wcpl-spinner' );

		if ( ! $form.length ) {
			return;
		}

		$form.on( 'submit', function ( event ) {
			// Run client-side validation first.
			var validationError = validateForm();

			if ( validationError ) {
				event.preventDefault();
				showInlineError( validationError );
				return;
			}

			// Hide any previous inline error.
			clearInlineError();

			// Disable button + show spinner to prevent double-submit.
			var importingText = ( wcplAdmin && wcplAdmin.importing_text )
				? wcplAdmin.importing_text
				: 'Importing, please wait…';

			$btn
				.prop( 'disabled', true )
				.text( importingText );

			$spinner.addClass( 'is-active' );
		} );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// 3. Client-side Validation
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Run lightweight client-side validation before the form is submitted.
	 *
	 * Checks:
	 *   - File source selected but no file chosen.
	 *   - File source selected but file has a disallowed extension.
	 *   - URL source selected but URL field is empty.
	 *
	 * Returns an error message string on failure, or null on success.
	 *
	 * @returns {string|null}
	 */
	function validateForm() {
		var source = $( 'input[name="wcpl_source"]:checked' ).val();

		if ( 'url' === source ) {
			var url = $.trim( $( '#wcpl_url' ).val() );
			if ( ! url ) {
				return 'Please enter a CSV URL before importing.';
			}
			// Basic URL format check.
			if ( ! /^https?:\/\/.+/.test( url ) ) {
				return 'Please enter a valid URL (must start with http:// or https://).';
			}
		} else {
			var fileInput = $( '#wcpl_csv_file' );
			var files = fileInput[ 0 ] && fileInput[ 0 ].files;

			if ( ! files || files.length === 0 ) {
				return 'Please select a CSV file before importing.';
			}

			var filename  = files[ 0 ].name.toLowerCase();
			var extension = filename.split( '.' ).pop();

			if ( ACCEPTED_EXTENSIONS.indexOf( extension ) === -1 ) {
				return 'Invalid file type ".' + extension + '". Please select a .csv or .txt file.';
			}
		}

		return null; // Validation passed.
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Inline Error Helpers
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Show an inline error message below the form submit button.
	 *
	 * Creates a dismissible notice element if one does not already exist,
	 * then populates it with the error text.
	 *
	 * @param {string} message Error message to display.
	 */
	function showInlineError( message ) {
		var $existingError = $( '#wcpl-inline-error' );

		if ( $existingError.length ) {
			$existingError.text( message ).show();
		} else {
			var $error = $( '<p>', {
				id:    'wcpl-inline-error',
				class: 'wcpl-inline-error notice notice-error inline',
				text:  message
			} );
			$( '#wcpl-import-btn' ).after( $error );
		}
	}

	/**
	 * Remove any currently visible inline error message.
	 */
	function clearInlineError() {
		$( '#wcpl-inline-error' ).hide();
	}

	// ─────────────────────────────────────────────────────────────────────────
	// 4. Updates tab
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Wire up the "Check for updates" button: calls wcpl_check_update AJAX,
	 * fills the latest-version cell and renders an availability notice.
	 */
	function initUpdates() {
		var $btn = $( '#wcpl-check-update' );

		if ( ! $btn.length ) { return; }

		var i18n    = ( wcplAdmin && wcplAdmin.i18n ) ? wcplAdmin.i18n : {};
		var $status = $( '#wcpl-update-status' );
		var $latest = $( '#wcpl-latest-version' );
		var $result = $( '#wcpl-update-result' );

		$btn.on( 'click', function () {
			$btn.prop( 'disabled', true );
			$status.text( i18n.checkingUpdate || '' );
			$result.empty();

			$.post( wcplAdmin.ajaxUrl, {
				action: 'wcpl_check_update',
				nonce:  wcplAdmin.nonce
			} )
				.done( function ( response ) {
					if ( ! response || ! response.success || ! response.data ) {
						$status.text( extractMessage( response ) || i18n.errorCheck || '' );
						return;
					}

					var d = response.data;
					$status.text( '' );
					$latest.text( d.latest );

					if ( d.update_available ) {
						$result.html( buildUpdateNotice( d ) );
					} else {
						$result.html(
							'<div class="notice notice-success inline"><p>' +
							escHtml( i18n.upToDate || '' ) +
							'</p></div>'
						);
					}
				} )
				.fail( function () {
					$status.text( i18n.errorCheck || '' );
				} )
				.always( function () {
					$btn.prop( 'disabled', false );
				} );
		} );
	}

	/**
	 * Costruisce il notice "nuova versione disponibile" con changelog e pulsante update.
	 *
	 * @param {Object} d  Dati della risposta wcpl_check_update.
	 * @return {string}   Markup HTML.
	 */
	function buildUpdateNotice( d ) {
		var i18n    = ( wcplAdmin && wcplAdmin.i18n ) ? wcplAdmin.i18n : {};
		var heading = ( i18n.updateAvailable || '%s' ).replace( '%s', escHtml( d.latest ) );

		var html = '<div class="notice notice-warning inline"><p><strong>' + heading + '</strong></p>';

		// Il changelog è HTML già sanitizzato lato server (wp_kses_post) → inserito as-is.
		if ( d.changelog ) {
			html += '<details style="margin:6px 0;"><summary>' + escHtml( i18n.changelog || 'Changelog' ) +
				'</summary><div class="wcpl-changelog">' + d.changelog + '</div></details>';
		}

		if ( d.upgrade_url ) {
			html += '<p><a href="' + escAttr( d.upgrade_url ) +
				'" class="button button-primary">' + escHtml( i18n.updateNow || 'Update now' ) + '</a></p>';
		}

		html += '</div>';

		return html;
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Utilities
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * HTML-escape a string usando il DOM.
	 *
	 * @param {string} str
	 * @return {string}
	 */
	function escHtml( str ) {
		return $( '<div>' ).text( String( str ) ).html();
	}

	/**
	 * Attribute-escape a string.
	 *
	 * @param {string} str
	 * @return {string}
	 */
	function escAttr( str ) {
		return escHtml( str )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#039;' );
	}

	/**
	 * Estrae il messaggio da una risposta wp_send_json_error().
	 *
	 * @param {Object} response
	 * @return {string}
	 */
	function extractMessage( response ) {
		if ( ! response || ! response.data ) { return ''; }
		if ( typeof response.data === 'string' ) { return response.data; }
		if ( typeof response.data.message === 'string' ) { return response.data.message; }
		return '';
	}

}( jQuery ) );
