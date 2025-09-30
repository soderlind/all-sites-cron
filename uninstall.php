<?php
/**
 * Uninstall script for All Sites Cron
 *
 * Removes all plugin data from the database when the plugin is deleted.
 *
 * @package     All_Sites_Cron
 * @author      Per Soderlind
 * @copyright   2024 Per Soderlind
 * @license     GPL-2.0+
 */

// If uninstall not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Clean up all plugin transients and options.
 * This runs when the plugin is deleted (not just deactivated).
 */
function all_sites_cron_uninstall_cleanup() {
	if ( ! is_multisite() ) {
		return;
	}

	// Remove all site transients created by the plugin.
	delete_site_transient( 'all_sites_cron_lock' );
	delete_site_transient( 'all_sites_cron_last_run_ts' );
	delete_site_transient( 'all_sites_cron_sites' );

	// Remove migration flag.
	delete_site_option( 'all_sites_cron_migrated_legacy_transients' );

	// Clean up any legacy transients that might still exist.
	global $wpdb;

	// Prepare patterns for legacy transients.
	$patterns = [
		$wpdb->esc_like( '_site_transient_dss_cron_' ) . '%',
		$wpdb->esc_like( '_transient_dss_cron_' ) . '%',
		$wpdb->esc_like( '_site_transient_all_sites_cron_' ) . '%',
		$wpdb->esc_like( '_transient_all_sites_cron_' ) . '%',
		$wpdb->esc_like( '_site_transient_timeout_dss_cron_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_dss_cron_' ) . '%',
		$wpdb->esc_like( '_site_transient_timeout_all_sites_cron_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_all_sites_cron_' ) . '%',
	];

	// Remove all matching options in batches.
	foreach ( $patterns as $pattern ) {
		$query   = $wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
			$pattern
		);
		$options = $wpdb->get_col( $query );

		if ( ! empty( $options ) ) {
			foreach ( $options as $option_name ) {
				delete_option( $option_name );
			}
		}
	}
}

// Execute cleanup.
all_sites_cron_uninstall_cleanup();
