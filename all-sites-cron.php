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
 * Version: 1.3.1
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

define( 'ALL_SITES_CRON_FILE', __FILE__ );
define( 'ALL_SITES_CRON_PATH', plugin_dir_path( ALL_SITES_CRON_FILE ) );

require_once ALL_SITES_CRON_PATH . 'vendor/autoload.php';
// Include the generic updater class
if ( ! class_exists( 'Soderlind\WordPress\GitHub_Plugin_Updater' ) ) {
	require_once ALL_SITES_CRON_PATH . 'class-github-plugin-updater.php';
}
// Initialize the updater with configuration.
$all_sites_cron_updater = \Soderlind\WordPress\GitHub_Plugin_Updater::create_with_assets(
	'https://github.com/soderlind/all-sites-cron',
	ALL_SITES_CRON_FILE,
	'all-sites-cron',
	'/all-sites-cron\.zip/',
	'main'
);

// Register REST route.
add_action( 'rest_api_init', __NAMESPACE__ . '\\all_sites_cron_register_rest' );

// Run one-time upgrade migration for cleaning legacy transients.
add_action( 'plugins_loaded', __NAMESPACE__ . '\\all_sites_cron_maybe_migrate_legacy_transients', 5 );

// Register activation and deactivation hooks.
register_activation_hook( ALL_SITES_CRON_FILE, __NAMESPACE__ . '\\all_sites_cron_activation' );
register_deactivation_hook( ALL_SITES_CRON_FILE, __NAMESPACE__ . '\\all_sites_cron_deactivation' );

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
				'description'       => 'GitHub Actions plain text output mode',
				'type'              => 'boolean',
				'required'          => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => false,
			],
		],
	] );

	// Backward compatibility: old namespace dss-cron still works (deprecated).
	register_rest_route( 'dss-cron/v1', '/run', [
		'methods'             => 'GET',
		'callback'            => __NAMESPACE__ . '\\all_sites_cron_rest_run',
		'permission_callback' => '__return_true',
		'args'                => [
			'ga' => [
				'type'              => 'boolean',
				'required'          => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => false,
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
function all_sites_cron_rest_run( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
	if ( ! is_multisite() ) {
		return new \WP_Error( 'all_sites_cron_not_multisite', __( 'This plugin requires WordPress Multisite', 'all-sites-cron' ), [ 'status' => 400 ] );
	}

	// Request locking: prevent concurrent executions.
	$lock_key = 'all_sites_cron_lock';
	if ( false !== get_site_transient( $lock_key ) ) {
		$message       = __( 'Another cron process is currently running. Please try again later.', 'all-sites-cron' );
		$ga_mode_check = (bool) $request->get_param( 'ga' );
		if ( $ga_mode_check ) {
			$txt      = "::warning::{$message}\n";
			$response = new \WP_REST_Response( $txt, 409 );
			$response->header( 'Content-Type', 'text/plain; charset=utf-8' );
			return $response;
		}
		return new \WP_Error( 'all_sites_cron_locked', $message, [ 'status' => 409 ] );
	}
	// Set lock for 4 minutes maximum.
	set_site_transient( $lock_key, time(), MINUTE_IN_SECONDS * 4 );

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
	// Release the lock.
	delete_site_transient( 'all_sites_cron_lock' );

	// Log errors if any.
	if ( empty( $result[ 'success' ] ) && ! empty( $result[ 'message' ] ) ) {
		error_log( sprintf( '[All Sites Cron] Execution failed: %s', $result[ 'message' ] ) );
	}

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
 * Uses batch processing for large networks to prevent memory issues.
 * 
 * @return array
 */
function all_sites_run_cron_on_all_sites(): array {
	if ( ! is_multisite() ) {
		return create_error_response( __( 'This plugin requires WordPress Multisite', 'all-sites-cron' ) );
	}

	$errors        = [];
	$total_count   = 0;
	$doing_wp_cron = sprintf( '%.22F', microtime( true ) );
	$timeout       = apply_filters( 'all_sites_cron_request_timeout', apply_filters( 'dss_cron_request_timeout', 0.01 ) ); // ultra-short like core spawn_cron(); legacy fallback.
	$batch_size    = (int) apply_filters( 'all_sites_cron_batch_size', 50 );
	$max_sites     = (int) apply_filters( 'all_sites_cron_number_of_sites', apply_filters( 'dss_cron_number_of_sites', 1000 ) ); // Legacy filter fallback.
	$offset        = 0;

	// Process sites in batches to prevent memory issues.
	do {
		$sites = get_sites( [
			'public'   => 1,
			'archived' => 0,
			'deleted'  => 0,
			'spam'     => 0,
			'number'   => $batch_size,
			'offset'   => $offset,
		] );

		if ( empty( $sites ) ) {
			break;
		}

		foreach ( $sites as $site ) {
			$url      = $site->__get( 'siteurl' );
			$cron_url = $url . '/wp-cron.php?doing_wp_cron=' . $doing_wp_cron;
			$response = wp_remote_post( $cron_url, [
				'timeout'    => $timeout,
				'blocking'   => false, // fire and forget
				'sslverify'  => apply_filters( 'https_local_ssl_verify', false ),
				'user-agent' => 'All Sites Cron; ' . home_url( '/' ),
			] );
			if ( is_wp_error( $response ) ) {
				$error_msg = sprintf( __( 'Error for %s: %s', 'all-sites-cron' ), $url, $response->get_error_message() );
				$errors[]  = $error_msg;
				// Log individual site errors.
				error_log( sprintf( '[All Sites Cron] %s', $error_msg ) );
			}
			$total_count++;
		}

		$offset += $batch_size;

		// Stop if we've reached the maximum number of sites.
		if ( $total_count >= $max_sites ) {
			break;
		}
	} while ( count( $sites ) === $batch_size );

	if ( 0 === $total_count ) {
		return create_error_response( __( 'No public sites found in the network', 'all-sites-cron' ) );
	}

	if ( ! empty( $errors ) ) {
		// Return partial success with error details.
		return [
			'success' => false,
			'message' => sprintf(
				__( 'Completed with %d error(s): %s', 'all-sites-cron' ),
				count( $errors ),
				implode( '; ', array_slice( $errors, 0, 3 ) ) . ( count( $errors ) > 3 ? '...' : '' )
			),
			'count'   => $total_count,
			'errors'  => count( $errors ),
		];
	}

	return [
		'success' => true,
		'message' => '',
		'count'   => $total_count,
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

/**
 * Activation hook: Run on plugin activation.
 */
function all_sites_cron_activation(): void {
	if ( ! is_multisite() ) {
		return;
	}
	// Clear any existing locks and rate limit transients on activation.
	delete_site_transient( 'all_sites_cron_lock' );
	delete_site_transient( 'all_sites_cron_last_run_ts' );
	delete_site_transient( 'all_sites_cron_sites' );
}

/**
 * Deactivation hook: Run on plugin deactivation.
 */
function all_sites_cron_deactivation(): void {
	if ( ! is_multisite() ) {
		return;
	}
	// Clear transients on deactivation.
	delete_site_transient( 'all_sites_cron_lock' );
	delete_site_transient( 'all_sites_cron_last_run_ts' );
	delete_site_transient( 'all_sites_cron_sites' );
}

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
	$site_transient_pattern = $wpdb->esc_like( '_site_transient_dss_cron_' ) . '%';
	$transient_pattern      = $wpdb->esc_like( '_transient_dss_cron_' ) . '%';

	// Use proper prepared statement with explicit placeholders.
	$query = $wpdb->prepare(
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$site_transient_pattern,
		$transient_pattern
	);
	$rows  = $wpdb->get_col( $query );
	if ( ! empty( $rows ) ) {
		foreach ( $rows as $option_name ) {
			delete_option( $option_name );
		}
	}
	update_site_option( $done_flag, time() );
}
