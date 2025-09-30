<?php
/**
 * All Sites Cron
 *
 * @package     All_Sites_Cron
 * @author      Per Soderlind
 * @copyright   2024 Per Soderlind
 * @license     GPL-2.0+
 * 
 * Plugin Name: All Sites Cron
 * Plugin URI: https://github.com/soderlind/all-sites-cron
 * Description: Run wp-cron on all public sites in a multisite network via REST API.
 * Version: 1.3.0
 * Author: Per Soderlind
 * Author URI: https://soderlind.no
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Network: true
 * Text Domain: all-sites-cron
 */

namespace Soderlind\Multisite\Cron;

if ( ! function_exists( 'add_action' ) ) {
	return; // Abort if WordPress isn't bootstrapped.
}

// Register REST route.
add_action( 'rest_api_init', __NAMESPACE__ . '\\all_sites_cron_register_rest' );

// Run one-time upgrade migration for cleaning legacy transients.
add_action( 'plugins_loaded', __NAMESPACE__ . '\\all_sites_cron_maybe_migrate_legacy_transients', 5 );

/**
 * Register REST API route: /wp-json/all-sites-cron/v1/run
 */
function all_sites_cron_register_rest(): void {
	register_rest_route( 'all-sites-cron/v1', '/run', [
		'methods'             => 'GET',
		'callback'            => __NAMESPACE__ . '\\all_sites_cron_rest_run',
		'permission_callback' => '__return_true', // Public like original endpoint.
		'args'                => [
			'ga' => [
				'description' => 'GitHub Actions plain text output mode',
				'type'        => 'boolean',
				'required'    => false,
			],
		],
	] );

	// Backward compatibility: old namespace dss-cron still works (deprecated).
	register_rest_route( 'dss-cron/v1', '/run', [
		'methods'             => 'GET',
		'callback'            => __NAMESPACE__ . '\\all_sites_cron_rest_run',
		'permission_callback' => '__return_true',
		'args'                => [ 'ga' => [ 'type' => 'boolean', 'required' => false ] ],
	] );
}

/**
 * REST callback handler.
 *
 * @param \WP_REST_Request $request Request instance.
 * @return \WP_REST_Response|\WP_Error
 */
function all_sites_cron_rest_run( \WP_REST_Request $request ) {
	if ( ! is_multisite() ) {
		return new \WP_Error( 'all_sites_cron_not_multisite', __( 'This plugin requires WordPress Multisite', 'all-sites-cron' ), [ 'status' => 400 ] );
	}

	// Rate limiting: deny if last run within cooldown window.
	$cooldown      = (int) apply_filters( 'all_sites_cron_rate_limit_seconds', apply_filters( 'dss_cron_rate_limit_seconds', 60 ) ); // Support legacy filter.
	$now_gmt       = time();
	$last_run      = (int) get_site_transient( 'all_sites_cron_last_run_ts' );
	$seconds_since = $last_run ? ( $now_gmt - $last_run ) : $cooldown + 1;
	if ( $cooldown > 0 && $seconds_since < $cooldown ) {
		$retry_after = $cooldown - $seconds_since;
		$message     = sprintf( __( 'Rate limited. Try again in %d seconds.', 'all-sites-cron' ), $retry_after );
		$ga_mode_tmp = (bool) $request->get_param( 'ga' );
		if ( $ga_mode_tmp ) {
			$txt      = "::error::{$message}\n";
			$response = new \WP_REST_Response( $txt, 429 );
			$response->header( 'Content-Type', 'text/plain; charset=utf-8' );
			$response->header( 'Retry-After', (string) $retry_after );
			return $response;
		}
		$payload  = [
			'success'      => false,
			'error'        => 'rate_limited',
			'message'      => $message,
			'retry_after'  => $retry_after,
			'cooldown'     => $cooldown,
			'last_run_gmt' => $last_run ?: null,
			'timestamp'    => gmdate( 'Y-m-d H:i:s', $now_gmt ),
		];
		$response = new \WP_REST_Response( $payload, 429 );
		$response->header( 'Retry-After', (string) $retry_after );
		return $response;
	}

	$ga_mode = (bool) $request->get_param( 'ga' );
	if ( function_exists( 'ignore_user_abort' ) ) {
		ignore_user_abort( true );
	}
	$result = all_sites_run_cron_on_all_sites();
	// Store last successful (attempted) run timestamp regardless of success to enforce cooldown.
	set_site_transient( 'all_sites_cron_last_run_ts', $now_gmt, $cooldown > 0 ? $cooldown : 60 );

	if ( $ga_mode ) {
		$txt      = empty( $result[ 'success' ] )
			? "::error::{$result[ 'message' ]}\n"
			: "::notice::Running wp-cron on {$result[ 'count' ]} sites\n";
		$response = new \WP_REST_Response( $txt, 200 );
		$response->header( 'Content-Type', 'text/plain; charset=utf-8' );
		return $response;
	}

	$payload = [
		'success'   => (bool) ( $result[ 'success' ] ?? false ),
		'count'     => (int) ( $result[ 'count' ] ?? 0 ),
		'message'   => (string) ( $result[ 'message' ] ?? '' ),
		'timestamp' => function_exists( 'current_time' ) ? current_time( 'mysql', true ) : gmdate( 'Y-m-d H:i:s' ),
		'endpoint'  => 'rest',
	];
	return new \WP_REST_Response( $payload, 200 );
}

/**
 * Run wp-cron on all public sites in the multisite network.
 * 
 * @return array
 */
function all_sites_run_cron_on_all_sites(): array {
	if ( ! is_multisite() ) {
		return create_error_response( __( 'This plugin requires WordPress Multisite', 'all-sites-cron' ) );
	}

	$sites = get_site_transient( 'all_sites_cron_sites' );
	if ( false === $sites ) {
		$sites = get_sites( [
			'public'   => 1,
			'archived' => 0,
			'deleted'  => 0,
			'spam'     => 0,
			'number'   => apply_filters( 'all_sites_cron_number_of_sites', apply_filters( 'dss_cron_number_of_sites', 200 ) ), // Legacy filter fallback.
		] );
		set_site_transient( 'all_sites_cron_sites', $sites, apply_filters( 'all_sites_cron_sites_transient', apply_filters( 'dss_cron_sites_transient', HOUR_IN_SECONDS ) ) );
	}

	if ( empty( $sites ) ) {
		return create_error_response( __( 'No public sites found in the network', 'all-sites-cron' ) );
	}

	$errors        = [];
	$doing_wp_cron = sprintf( '%.22F', microtime( true ) );
	$timeout       = apply_filters( 'all_sites_cron_request_timeout', apply_filters( 'dss_cron_request_timeout', 0.01 ) ); // ultra-short like core spawn_cron(); legacy fallback.
	foreach ( (array) $sites as $site ) {
		$url      = $site->__get( 'siteurl' );
		$cron_url = $url . '/wp-cron.php?doing_wp_cron=' . $doing_wp_cron;
		$response = wp_remote_post( $cron_url, [
			'timeout'    => $timeout,
			'blocking'   => false, // fire and forget
			'sslverify'  => apply_filters( 'https_local_ssl_verify', false ),
			'user-agent' => 'All Sites Cron; ' . home_url( '/' ),
		] );
		if ( is_wp_error( $response ) ) {
			$errors[] = sprintf( __( 'Error for %s: %s', 'all-sites-cron' ), $url, $response->get_error_message() );
		}
	}

	if ( ! empty( $errors ) ) {
		return create_error_response( implode( "\n", $errors ) );
	}

	return [
		'success' => true,
		'message' => '',
		'count'   => count( (array) $sites ),
	];
}

/**
 * Create an error response.
 *
 * @param string $error_message
 * @return array
 */
function create_error_response( $error_message ): array {
	return [
		'success' => false,
		'message' => $error_message,
	];
}

// Activation/deactivation placeholders.
function all_sites_cron_activation(): void {}
function all_sites_cron_deactivation(): void {}

/**
 * One-time migration: remove legacy dss_cron_* transients after rename.
 *
 * Because WordPress stores site (network) transients in options with keys like '_site_transient_{name}',
 * we can query for those starting with the legacy prefix. We keep it lightweight and only run once.
 */
function all_sites_cron_maybe_migrate_legacy_transients(): void {
	if ( ! is_multisite() ) {
		return; // Only relevant in multisite context where plugin operates.
	}
	$done_flag = 'all_sites_cron_migrated_legacy_transients';
	if ( get_site_option( $done_flag ) ) {
		return; // Already migrated.
	}
	global $wpdb;

	// Look for both site_transient and regular transient naming just in case.
	$like_patterns = [
		$wpdb->esc_like( '_site_transient_dss_cron_' ) . '%',
		$wpdb->esc_like( '_transient_dss_cron_' ) . '%',
	];

	$placeholders = implode( ' OR option_name LIKE ', array_fill( 0, count( $like_patterns ), '%s' ) );
	$query        = "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE $placeholders";
	$rows         = $wpdb->get_col( $wpdb->prepare( $query, $like_patterns ) );
	if ( ! empty( $rows ) ) {
		foreach ( $rows as $option_name ) {
			delete_option( $option_name );
		}
	}
	update_site_option( $done_flag, time() );
}
