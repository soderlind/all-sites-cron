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
 * Description: Run wp-cron on all public sites in a multisite network.
 * Version: 1.1.0
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

// Flush rewrite rules on plugin activation and deactivation.
register_activation_hook( __FILE__, __NAMESPACE__ . '\dss_cron_activation' );
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\dss_cron_deactivation' );


// Hook into a custom endpoint to run the cron job.
add_action( 'init', __NAMESPACE__ . '\dss_cron_init' );
add_action( 'template_redirect', __NAMESPACE__ . '\dss_cron_template_redirect' );
// Ultra-early fast response for HEAD requests hitting /dss-cron to avoid any perceived hang.
// Prevent canonical 301 redirect for our endpoint.
add_filter( 'redirect_canonical', function ($redirect_url) {
	// Bypass canonical redirect for our endpoint, even if query vars not parsed yet.
	$raw_uri = isset( $_SERVER[ 'REQUEST_URI' ] ) ? strtok( $_SERVER[ 'REQUEST_URI' ], '?' ) : '';
	if ( rtrim( $raw_uri, '/' ) === '/dss-cron' ) {
		return false;
	}
	if ( function_exists( 'get_query_var' ) && get_query_var( 'dss_cron' ) == 1 ) { // Fallback after parse.
		return false;
	}
	return $redirect_url;
}, 10, 1 );


/**
 * Initialize the custom rewrite rule and tag for the cron endpoint.
 * 
 * @return void
 */
function dss_cron_init(): void {
	add_rewrite_rule( '^dss-cron/?$', 'index.php?dss_cron=1', 'top' );
	add_rewrite_tag( '%dss_cron%', '1' );
	add_rewrite_tag( '%ga%', '1' );
}

/**
 * Check for the custom query variable and run the cron job if it is set.
 * 
 * @return void
 */
function dss_cron_template_redirect(): void {
	if ( get_query_var( 'dss_cron' ) != 1 ) {
		return;
	}
	status_header( 200 );
	nocache_headers();

	$ga_mode = isset( $_GET[ 'ga' ] );
	if ( $ga_mode ) {
		header( 'Content-Type: text/plain; charset=utf-8' );
	} else {
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'X-DSS-Cron: json' );
	}
	if ( function_exists( 'ignore_user_abort' ) ) {
		ignore_user_abort( true );
	}
	ob_start();
	$result = dss_run_cron_on_all_sites();
	if ( $ga_mode ) {
		if ( empty( $result[ 'success' ] ) ) {
			echo "::error::{$result[ 'message' ]}\n";
		} else {
			echo "::notice::Running wp-cron on {$result[ 'count' ]} sites\n";
		}
	} else {
		$payload = [ 
			'success'   => (bool) ( $result[ 'success' ] ?? false ),
			'count'     => (int) ( $result[ 'count' ] ?? 0 ),
			'message'   => (string) ( $result[ 'message' ] ?? '' ),
			'timestamp' => function_exists( 'current_time' ) ? current_time( 'mysql', true ) : gmdate( 'Y-m-d H:i:s' ),
		];
		echo function_exists( 'wp_json_encode' ) ? wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : json_encode( $payload );
	}
	$body = ob_get_clean();
	header( 'Content-Length: ' . strlen( $body ) );
	echo $body;
	if ( function_exists( 'fastcgi_finish_request' ) ) {
		@fastcgi_finish_request();
	}
	exit;
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

/**
 * Early exit for HEAD /dss-cron to avoid full WP load if possible.
 * Pattern match REQUEST_URI since rewrite parsing may not yet have populated query vars.
 */
// Early head exit removed per request; HEAD now follows the same path as GET and returns a body (possibly empty JSON/text).


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

/**
 * Flush rewrite rules on plugin activation.
 * 
 * @return void
 */
function dss_cron_activation(): void {
	dss_cron_init();
	flush_rewrite_rules();
}

/**
 * Flush rewrite rules on plugin deactivation.
 * 
 * @return void
 */
function dss_cron_deactivation(): void {
	flush_rewrite_rules();
}

