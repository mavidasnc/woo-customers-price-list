/**
 * WooCommerce Dynamic Price List Pro — Frontend Script
 *
 * Initialises Select2 on every <select> inside the WooCommerce sidebar
 * (#sidebar) that has not already been enhanced by another script.
 *
 * Dependencies (declared via wp_enqueue_script):
 *   jquery, wdplp-select2, wdplp-select2-i18n-it
 *
 * @package WDPLP
 * @since   1.3.3
 */

( function ( $ ) {
	'use strict';

	/**
	 * Initialise Select2 on sidebar <select> elements.
	 *
	 * The `.not('.select2-hidden-accessible')` guard prevents double-init
	 * if another script (e.g. the theme) has already enhanced a select.
	 *
	 * minimumResultsForSearch: 0 — search box always visible.
	 */
	function initSidebarSelects() {
		$( '#sidebar select' )
			.not( '.select2-hidden-accessible' )
			.select2( {
				language:                  'it',
				width:                     '100%',
				minimumResultsForSearch:   0,
			} );
	}

	$( document ).ready( function () {
		initSidebarSelects();
	} );

} )( jQuery );
