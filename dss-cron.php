<?php
/**
 * DSS Cron
 *
 * @package     DSS_Cron
 * @author      Per Soderlind
 * @copyright   2024 Per Soderlind
 * @license     GPL-2.0+
 * 
 * Plugin Name: DSS Cron
 * Plugin URI: https://github.com/soderlind/dss-cron
 * Description: Run wp-cron on all public sites in a multisite network via REST API.
 * Version: 1.2.0
 * Author: Per Soderlind
 * Author URI: https://soderlind.no
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Network: true
 */

namespace Soderlind\Multisite\Cron;
/**
 * NOTE: All runtime logic relies on WordPress being loaded. If this file is parsed
 * outside of WordPress (e.g. static analysis), short‑circuit gracefully.
 */
if ( ! function_exists( 'add_action' ) ) {
	return; // Abort if WordPress isn't bootstrapped.
}

// Register REST route instead of custom rewrite endpoint.
add_action( 'rest_api_init', __NAMESPACE__ . '\\dss_cron_register_rest' );

/**
 * Register REST API route: /wp-json/dss-cron/v1/run
 */
function dss_cron_register_rest(): void {
	register_rest_route( 'dss-cron/v1', '/run', [
		'methods'             => 'GET',
		'callback'            => __NAMESPACE__ . '\\dss_cron_rest_run',
		'permission_callback' => '__return_true', // Public like original endpoint.
		'args'                => [
			'ga' => [
				'description' => 'GitHub Actions plain text output mode',
				'type'        => 'boolean',
				'required'    => false,
			],
		],
	] );
}

/**
 * REST callback handler.
 *
 * @param \WP_REST_Request $request Request instance.
 * @return \WP_REST_Response|\WP_Error
 */
function dss_cron_rest_run( \WP_REST_Request $request ) {
	if ( ! is_multisite() ) {
		return new \WP_Error( 'dss_cron_not_multisite', __( 'This plugin requires WordPress Multisite', 'dss-cron' ), [ 'status' => 400 ] );
	}

	// Rate limiting: deny if last run within cooldown window.
	$cooldown      = (int) apply_filters( 'dss_cron_rate_limit_seconds', 60 ); // Default 60s.
	$now_gmt       = time();
	$last_run      = (int) get_site_transient( 'dss_cron_last_run_ts' );
	$seconds_since = $last_run ? ( $now_gmt - $last_run ) : $cooldown + 1;
	if ( $cooldown > 0 && $seconds_since < $cooldown ) {
		$retry_after = $cooldown - $seconds_since;
		$message     = sprintf( __( 'Rate limited. Try again in %d seconds.', 'dss-cron' ), $retry_after );
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
	$result = dss_run_cron_on_all_sites();
	// Store last successful (attempted) run timestamp regardless of success to enforce cooldown.
	set_site_transient( 'dss_cron_last_run_ts', $now_gmt, $cooldown > 0 ? $cooldown : 60 );

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
function dss_run_cron_on_all_sites(): array {
	if ( ! is_multisite() ) {
		return create_error_response( __( 'This plugin requires WordPress Multisite', 'dss-cron' ) );
	}

	$sites = get_site_transient( 'dss_cron_sites' );
	if ( false === $sites ) {
		$sites = get_sites( [
			'public'   => 1,
			'archived' => 0,
			'deleted'  => 0,
			'spam'     => 0,
			'number'   => apply_filters( 'dss_cron_number_of_sites', 200 ),
		] );
		set_site_transient( 'dss_cron_sites', $sites, apply_filters( 'dss_cron_sites_transient', HOUR_IN_SECONDS ) );
	}

	if ( empty( $sites ) ) {
		return create_error_response( __( 'No public sites found in the network', 'dss-cron' ) );
	}

	$errors        = [];
	$doing_wp_cron = sprintf( '%.22F', microtime( true ) );
	$timeout       = apply_filters( 'dss_cron_request_timeout', 0.01 ); // ultra-short like core spawn_cron()
	foreach ( (array) $sites as $site ) {
		$url      = $site->__get( 'siteurl' );
		$cron_url = $url . '/wp-cron.php?doing_wp_cron=' . $doing_wp_cron;
		$response = wp_remote_post( $cron_url, [
			'timeout'    => $timeout,
			'blocking'   => false, // fire and forget
			'sslverify'  => apply_filters( 'https_local_ssl_verify', false ),
			'user-agent' => 'DSS Cron; ' . home_url( '/' ),
		] );
		if ( is_wp_error( $response ) ) {
			$errors[] = sprintf( __( 'Error for %s: %s', 'dss-cron' ), $url, $response->get_error_message() );
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

// Legacy rewrite / template_redirect code removed in 1.2.0 (migrated to REST route).


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

// Activation/deactivation no longer need rewrite flush; left intentionally empty for backward compatibility if hooks referenced.
function dss_cron_activation(): void {}
function dss_cron_deactivation(): void {}

