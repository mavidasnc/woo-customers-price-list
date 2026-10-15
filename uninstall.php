<?php
/**
 * Plugin Uninstall Handler.
 *
 * WordPress executes this file when a site administrator deletes the plugin
 * via Plugins > Installed Plugins > Delete. It is NOT run on deactivation.
 *
 * This file performs IRREVERSIBLE data deletion. All user data — including
 * the custom database table, plugin options, and user meta — is permanently
 * removed when this file runs.
 *
 * SECURITY: WordPress sets the WP_UNINSTALL_PLUGIN constant before calling
 * this file. We verify its presence as a mandatory security gate; direct
 * HTTP requests to this file will exit immediately without taking any action.
 *
 * @package WCPL
 * @since   1.0.0
 */

// Mandatory security check — exit immediately if not called by WordPress.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// ---------------------------------------------------------------------------
// 1. Drop the custom price lists table.
// ---------------------------------------------------------------------------
// We do NOT use the Database class here because the autoloader may not be
// available in the uninstall context. Instead we reference the table directly.
$table_name = $wpdb->prefix . 'custom_price_lists';

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( "DROP TABLE IF EXISTS `{$table_name}`" ); // @phpstan-ignore-line

// ---------------------------------------------------------------------------
// 2. Delete plugin options.
// ---------------------------------------------------------------------------
delete_option( 'wcpl_version' );
delete_option( 'wdplp_version' ); // Pre-2.0.0 name.
delete_option( 'wcpl_settings' );

// ---------------------------------------------------------------------------
// 3. Remove user meta for all users.
// ---------------------------------------------------------------------------
// This deletes the "Assigned Price List" value from every user profile.
// On large installations this query could be slow; it is acceptable here
// because uninstall is a one-time, administrator-initiated operation.
$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	$wpdb->usermeta,
	[ 'meta_key' => '_assigned_price_list' ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
	[ '%s' ]
);

// ---------------------------------------------------------------------------
// 4. Delete transients (if any are introduced in future versions).
// ---------------------------------------------------------------------------
// Pattern: delete_transient( 'wcpl_some_transient' );
// Site transients: delete_site_transient( 'wcpl_some_transient' );

// ---------------------------------------------------------------------------
// NOTE: Log files in wp-content/uploads/wc-price-logs/ are intentionally
// NOT deleted here. They are outside the plugin directory and may be useful
// for post-uninstall audit purposes. Administrators can remove them manually.
// ---------------------------------------------------------------------------
