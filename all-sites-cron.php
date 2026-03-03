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
 * Version: 2.0.0
 * Author: Per Soderlind
 * Author URI: https://soderlind.no
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Network: true
 * Text Domain: all-sites-cron
 */

namespace Soderlind\Multisite\AllSitesCron;

if ( ! function_exists( 'add_action' ) ) {
	return; // Abort if WordPress isn't bootstrapped.
}

define( 'ALL_SITES_CRON_FILE', __FILE__ );
define( 'ALL_SITES_CRON_PATH', plugin_dir_path( ALL_SITES_CRON_FILE ) );

// Default configuration constants.
define( 'ALL_SITES_CRON_DEFAULT_TIMEOUT', 0.01 );
define( 'ALL_SITES_CRON_DEFAULT_BATCH_SIZE', 50 );
define( 'ALL_SITES_CRON_DEFAULT_MAX_SITES', 1000 );
define( 'ALL_SITES_CRON_DEFAULT_COOLDOWN', 60 );
define( 'ALL_SITES_CRON_LOCK_TIMEOUT', MINUTE_IN_SECONDS * 5 );

require_once ALL_SITES_CRON_PATH . 'vendor/autoload.php';

// Include the generic updater class.
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

// Register REST routes.
add_action( 'rest_api_init', __NAMESPACE__ . '\\register_rest_routes' );

// Run one-time upgrade migration for cleaning legacy transients.
add_action( 'plugins_loaded', __NAMESPACE__ . '\\maybe_migrate_legacy_transients', 5 );

// Register activation and deactivation hooks.
register_activation_hook( ALL_SITES_CRON_FILE, __NAMESPACE__ . '\\activation' );
register_deactivation_hook( ALL_SITES_CRON_FILE, __NAMESPACE__ . '\\deactivation' );

// ---------------------------------------------------------------------------
// REST route registration
// ---------------------------------------------------------------------------

/**
 * Get REST API route arguments.
 *
 * @return array
 */
function get_rest_args(): array {
	return [
		'ga'    => [
			'description'       => 'GitHub Actions plain text output mode',
			'type'              => 'boolean',
			'required'          => false,
			'sanitize_callback' => 'rest_sanitize_boolean',
			'default'           => false,
		],
		'defer' => [
			'description'       => 'Deferred mode: respond immediately, process in background',
			'type'              => 'boolean',
			'required'          => false,
			'sanitize_callback' => 'rest_sanitize_boolean',
			'default'           => false,
		],
	];
}

/**
 * Register REST API routes.
 *
 * /wp-json/all-sites-cron/v1/run         – main trigger
 * /wp-json/dss-cron/v1/run               – backward-compatible alias (deprecated)
 * /wp-json/all-sites-cron/v1/process-queue – Redis queue worker
 */
function register_rest_routes(): void {
	$permission = [ Auth::class, 'permission_callback' ];

	$route_config = [
		'methods'             => 'GET',
		'callback'            => __NAMESPACE__ . '\\rest_run',
		'permission_callback' => $permission,
		'args'                => get_rest_args(),
	];

	register_rest_route( 'all-sites-cron/v1', '/run', $route_config );

	// Backward compatibility: old namespace dss-cron still works (deprecated).
	register_rest_route( 'dss-cron/v1', '/run', $route_config );

	// Redis queue worker endpoint.
	register_rest_route( 'all-sites-cron/v1', '/process-queue', [
		'methods'             => 'POST',
		'callback'            => __NAMESPACE__ . '\\rest_process_queue',
		'permission_callback' => $permission,
	] );
}

// ---------------------------------------------------------------------------
// REST callback: /run
// ---------------------------------------------------------------------------

/**
 * REST callback handler.
 *
 * Order: rate-limit check → lock acquisition → execution.
 * Checking the rate limit first avoids acquiring (then immediately releasing)
 * a lock when the request will be rejected anyway.
 *
 * @param \WP_REST_Request $request Request instance.
 * @return \WP_REST_Response|\WP_Error
 */
function rest_run( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
	if ( ! is_multisite() ) {
		return new \WP_Error(
			'all_sites_cron_not_multisite',
			__( 'This plugin requires WordPress Multisite', 'all-sites-cron' ),
			[ 'status' => 400 ]
		);
	}

	$ga_mode = (bool) $request->get_param( 'ga' );

	// 1. Check rate limiting BEFORE acquiring the lock.
	$rate_limit_result = check_rate_limit();
	if ( is_array( $rate_limit_result ) ) {
		$response = Response::create(
			$ga_mode,
			$rate_limit_result[ 'message' ],
			429,
			$rate_limit_result
		);
		$response->header( 'Retry-After', (string) $rate_limit_result[ 'retry_after' ] );
		return $response;
	}

	// 2. Acquire an atomic lock.
	$lock        = new Lock();
	$lock_result = $lock->acquire();

	if ( is_wp_error( $lock_result ) ) {
		return Response::create( $ga_mode, $lock_result->get_error_message(), 409 );
	}

	$defer_mode = (bool) $request->get_param( 'defer' );
	$now_gmt    = time();

	if ( function_exists( 'ignore_user_abort' ) ) {
		ignore_user_abort( true );
	}

	$runner = new Cron_Runner();

	// Deferred mode: respond immediately, process in background.
	if ( $defer_mode ) {
		$redis_queue = new Redis_Queue();
		$use_redis   = apply_filters( 'all_sites_cron_use_redis_queue', $redis_queue->is_available() );

		if ( $use_redis ) {
			$queued = $redis_queue->push( $now_gmt );

			if ( $queued ) {
				// Release lock — the worker will re-acquire when it processes.
				$lock->release();

				$extra_data = $ga_mode ? [] : [
					'success'   => true,
					'status'    => 'queued',
					'timestamp' => gmdate( 'Y-m-d H:i:s', $now_gmt ),
					'mode'      => 'redis',
				];

				return Response::create(
					$ga_mode,
					__( 'Cron job queued to Redis for background processing', 'all-sites-cron' ),
					202,
					$extra_data
				);
			}

			// Redis queue failed — fall through to FastCGI method.
			error_log( '[All Sites Cron] Redis queue failed, falling back to FastCGI method' );
		}

		// Fallback to FastCGI / connection-close method.
		$extra_data = $ga_mode ? [] : [
			'success'   => true,
			'status'    => 'queued',
			'timestamp' => gmdate( 'Y-m-d H:i:s', $now_gmt ),
			'mode'      => 'deferred',
		];

		$response = Response::create(
			$ga_mode,
			__( 'Cron job queued for background processing', 'all-sites-cron' ),
			202,
			$extra_data
		);

		// Close connection and continue processing (webserver dependent).
		Response::close_connection_and_continue( $response );

		// Process in background and clean up (releases lock in finally).
		$runner->execute_and_cleanup( $now_gmt, $lock );
		exit; // Intentional: prevent WordPress from sending a second response.
	}

	// Standard synchronous mode.
	$result = $runner->execute_and_cleanup( $now_gmt, $lock );

	if ( $ga_mode ) {
		$message = empty( $result[ 'success' ] )
			? $result[ 'message' ]
			: sprintf(
				/* translators: %d: number of sites */
				__( 'Running wp-cron on %d sites', 'all-sites-cron' ),
				$result[ 'count' ]
			);
		return Response::create( true, $message, 200 );
	}

	$payload = [
		'success'   => (bool) ( $result[ 'success' ] ?? false ),
		'count'     => (int) ( $result[ 'count' ] ?? 0 ),
		'message'   => (string) ( $result[ 'message' ] ?? '' ),
		'timestamp' => gmdate( 'Y-m-d H:i:s', $now_gmt ),
		'endpoint'  => 'rest',
	];
	return new \WP_REST_Response( $payload, 200 );
}

// ---------------------------------------------------------------------------
// REST callback: /process-queue
// ---------------------------------------------------------------------------

/**
 * REST callback handler for processing the Redis queue.
 *
 * Pops one job, acquires the lock, and runs cron. If the lock is held the
 * job is pushed back onto the queue so it isn't lost.
 *
 * @return \WP_REST_Response
 */
function rest_process_queue(): \WP_REST_Response {
	if ( ! is_multisite() ) {
		return new \WP_REST_Response( [
			'success' => false,
			'message' => __( 'This plugin requires WordPress Multisite', 'all-sites-cron' ),
		], 400 );
	}

	$redis_queue = new Redis_Queue();

	if ( ! $redis_queue->is_available() ) {
		return new \WP_REST_Response( [
			'success' => false,
			'message' => __( 'Redis is not available', 'all-sites-cron' ),
		], 503 );
	}

	$job = $redis_queue->pop();

	if ( null === $job ) {
		return new \WP_REST_Response( [
			'success' => true,
			'message' => 'No jobs in queue',
			'count'   => 0,
		], 200 );
	}

	// Acquire lock before executing. If we can't, re-queue the job.
	$lock        = new Lock();
	$lock_result = $lock->acquire();

	if ( is_wp_error( $lock_result ) ) {
		$redis_queue->repush( $job );

		return new \WP_REST_Response( [
			'success' => false,
			'message' => __( 'Lock held — job re-queued for later processing.', 'all-sites-cron' ),
			'retry'   => true,
		], 409 );
	}

	$runner = new Cron_Runner();
	$result = $runner->execute_and_cleanup( $job[ 'timestamp' ], $lock );

	return new \WP_REST_Response( $result, $result[ 'success' ] ? 200 : 500 );
}

// ---------------------------------------------------------------------------
// Shared helper (kept as namespace function for backward compat)
// ---------------------------------------------------------------------------

/**
 * Get filter value with legacy fallback support.
 *
 * Applies both new and legacy filters to maintain backward compatibility.
 * The legacy filter is applied first, then the new filter is applied to that result.
 *
 * @since 1.5.2
 * @param string $new_filter    New filter name.
 * @param string $legacy_filter Legacy filter name for backward compatibility.
 * @param mixed  $default       Default value if no filters are applied.
 * @return mixed The filtered value.
 */
function get_filter( string $new_filter, string $legacy_filter, $default ): mixed {
	return apply_filters( $new_filter, apply_filters( $legacy_filter, $default ) );
}

/**
 * Check rate limiting.
 *
 * @return array|true Array with rate limit info if limited, true if OK.
 */
function check_rate_limit() {
	$cooldown      = (int) get_filter( 'all_sites_cron_rate_limit_seconds', 'dss_cron_rate_limit_seconds', ALL_SITES_CRON_DEFAULT_COOLDOWN );
	$now_gmt       = time();
	$last_run      = (int) get_site_transient( 'all_sites_cron_last_run_ts' );
	$seconds_since = $last_run ? ( $now_gmt - $last_run ) : $cooldown + 1;

	if ( $cooldown > 0 && $seconds_since < $cooldown ) {
		$retry_after = $cooldown - $seconds_since;
		return [
			'success'      => false,
			'error'        => 'rate_limited',
			'message'      => sprintf(
				/* translators: %d: seconds until retry is allowed */
				__( 'Rate limited. Try again in %d seconds.', 'all-sites-cron' ),
				$retry_after
			),
			'retry_after'  => $retry_after,
			'cooldown'     => $cooldown,
			'last_run_gmt' => $last_run ?: null,
			'timestamp'    => gmdate( 'Y-m-d H:i:s', $now_gmt ),
		];
	}

	return true;
}

// ---------------------------------------------------------------------------
// Backward-compat wrapper functions (delegate to new classes)
// ---------------------------------------------------------------------------

/**
 * Acquire execution lock.
 *
 * @deprecated 2.0.0 Use Lock::acquire() directly.
 * @return true|\WP_Error
 */
function acquire_lock() {
	return ( new Lock() )->acquire();
}

/**
 * Release execution lock.
 *
 * @deprecated 2.0.0 Use Lock::release() directly.
 * @return void
 */
function release_lock(): void {
	( new Lock() )->release();
}

/**
 * Run wp-cron on all public sites.
 *
 * @deprecated 2.0.0 Use Cron_Runner::run_all_sites() directly.
 * @return array
 */
function run_cron_on_all_sites(): array {
	return ( new Cron_Runner() )->run_all_sites();
}

/**
 * Execute cron and cleanup.
 *
 * @deprecated 2.0.0 Use Cron_Runner::execute_and_cleanup() directly.
 * @param int $timestamp Current GMT timestamp.
 * @return array
 */
function execute_and_cleanup( int $timestamp ): array {
	$lock = new Lock();
	return ( new Cron_Runner() )->execute_and_cleanup( $timestamp, $lock );
}

/**
 * Create an error response array.
 *
 * @deprecated 2.0.0 Use Response::error_array() directly.
 * @param string $error_message Error message.
 * @return array
 */
function create_error_response( string $error_message ): array {
	return Response::error_array( $error_message );
}

/**
 * Create a REST response based on mode (GA or JSON).
 *
 * @deprecated 2.0.0 Use Response::create() directly.
 * @param bool   $ga_mode    Whether GitHub Actions mode is enabled.
 * @param string $message    Message to send.
 * @param int    $status     HTTP status code.
 * @param array  $extra_data Additional data for JSON mode.
 * @return \WP_REST_Response
 */
function create_response( bool $ga_mode, string $message, int $status = 200, array $extra_data = [] ): \WP_REST_Response {
	return Response::create( $ga_mode, $message, $status, $extra_data );
}

/**
 * Check if Redis is available.
 *
 * @deprecated 2.0.0 Use Redis_Queue::is_available() directly.
 * @return bool
 */
function is_redis_available(): bool {
	return ( new Redis_Queue() )->is_available();
}

/**
 * Close connection and continue processing.
 *
 * @deprecated 2.0.0 Use Response::close_connection_and_continue() directly.
 * @param \WP_REST_Response $response Response to send.
 * @return void
 */
function close_connection_and_continue( \WP_REST_Response $response ): void {
	Response::close_connection_and_continue( $response );
}

// ---------------------------------------------------------------------------
// Activation / deactivation / migration
// ---------------------------------------------------------------------------

/**
 * Activation hook: Run on plugin activation.
 */
function activation(): void {
	if ( ! is_multisite() ) {
		return;
	}
	$lock = new Lock();
	$lock->release();
	delete_site_transient( 'all_sites_cron_last_run_ts' );
	delete_site_transient( 'all_sites_cron_sites' );
}

/**
 * Deactivation hook: Run on plugin deactivation.
 */
function deactivation(): void {
	if ( ! is_multisite() ) {
		return;
	}
	$lock = new Lock();
	$lock->release();
	delete_site_transient( 'all_sites_cron_last_run_ts' );
	delete_site_transient( 'all_sites_cron_sites' );
}

/**
 * One-time migration: remove legacy dss_cron_* transients after rename.
 *
 * On multisite, network-level site transients are stored in wp_sitemeta
 * (not wp_options). This function queries the correct table.
 */
function maybe_migrate_legacy_transients(): void {
	if ( ! is_multisite() ) {
		return;
	}
	$done_flag = 'all_sites_cron_migrated_legacy_transients';
	if ( get_site_option( $done_flag ) ) {
		return;
	}
	global $wpdb;

	$site_transient_pattern = $wpdb->esc_like( '_site_transient_dss_cron_' ) . '%';
	$transient_pattern      = $wpdb->esc_like( '_transient_dss_cron_' ) . '%';

	// Network site-transients live in wp_sitemeta on multisite.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$sitemeta_rows = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT meta_key FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s OR meta_key LIKE %s",
			$site_transient_pattern,
			$transient_pattern
		)
	);

	if ( ! empty( $sitemeta_rows ) ) {
		foreach ( $sitemeta_rows as $meta_key ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete( $wpdb->sitemeta, [ 'meta_key' => $meta_key ] );
		}
	}

	// Also check wp_options as a fallback (e.g. single-site transients or
	// environments that were converted from single-site to multisite).
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$option_rows = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$site_transient_pattern,
			$transient_pattern
		)
	);

	if ( ! empty( $option_rows ) ) {
		foreach ( $option_rows as $option_name ) {
			delete_option( $option_name );
		}
	}

	update_site_option( $done_flag, time() );
}
