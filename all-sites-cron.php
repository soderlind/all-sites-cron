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
 * Version: 1.5.2
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
add_action( 'rest_api_init', __NAMESPACE__ . '\\register_rest_routes' );

// Run one-time upgrade migration for cleaning legacy transients.
add_action( 'plugins_loaded', __NAMESPACE__ . '\\maybe_migrate_legacy_transients', 5 );

// Register activation and deactivation hooks.
register_activation_hook( ALL_SITES_CRON_FILE, __NAMESPACE__ . '\\activation' );
register_deactivation_hook( ALL_SITES_CRON_FILE, __NAMESPACE__ . '\\deactivation' );

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
 * Register REST API route: /wp-json/all-sites-cron/v1/run
 */
function register_rest_routes(): void {
	$route_config = [
		'methods'             => 'GET',
		'callback'            => __NAMESPACE__ . '\\rest_run',
		'permission_callback' => '__return_true',
		'args'                => get_rest_args(),
	];

	register_rest_route( 'all-sites-cron/v1', '/run', $route_config );

	// Backward compatibility: old namespace dss-cron still works (deprecated).
	register_rest_route( 'dss-cron/v1', '/run', $route_config );

	// Redis queue worker endpoint.
	register_rest_route( 'all-sites-cron/v1', '/process-queue', [
		'methods'             => 'POST',
		'callback'            => __NAMESPACE__ . '\\rest_process_queue',
		'permission_callback' => '__return_true',
	] );
}

/**
 * REST callback handler.
 *
 * @param \WP_REST_Request $request Request instance.
 * @return \WP_REST_Response|\WP_Error
 */
function rest_run( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
	if ( ! is_multisite() ) {
		return new \WP_Error( 'all_sites_cron_not_multisite', __( 'This plugin requires WordPress Multisite', 'all-sites-cron' ), [ 'status' => 400 ] );
	}

	$ga_mode = (bool) $request->get_param( 'ga' );

	// Try to acquire lock.
	$lock_result = acquire_lock();
	if ( is_wp_error( $lock_result ) ) {
		return create_response(
			$ga_mode,
			$lock_result->get_error_message(),
			409
		);
	}	// Check rate limiting.
	$rate_limit_result = check_rate_limit();
	if ( is_array( $rate_limit_result ) ) {
		// Rate limited - release lock and return error.
		release_lock();

		$response = create_response(
			$ga_mode,
			$rate_limit_result[ 'message' ],
			429,
			$rate_limit_result
		);
		$response->header( 'Retry-After', (string) $rate_limit_result[ 'retry_after' ] );
		return $response;
	}

	$defer_mode = (bool) $request->get_param( 'defer' );
	$now_gmt    = time();

	if ( function_exists( 'ignore_user_abort' ) ) {
		ignore_user_abort( true );
	}

	// Deferred mode: respond immediately, process in background.
	if ( $defer_mode ) {
		// Check if we should use Redis queue.
		$use_redis = apply_filters( 'all_sites_cron_use_redis_queue', is_redis_available() );

		if ( $use_redis ) {
			// Queue to Redis.
			$queued = queue_to_redis( $now_gmt );

			if ( $queued ) {
				// Release lock immediately since job is queued.
				release_lock();

				$extra_data = $ga_mode ? [] : [
					'success'   => true,
					'status'    => 'queued',
					'timestamp' => gmdate( 'Y-m-d H:i:s', $now_gmt ),
					'mode'      => 'redis',
				];

				return create_response(
					$ga_mode,
					__( 'Cron job queued to Redis for background processing', 'all-sites-cron' ),
					202,
					$extra_data
				);
			}

			// Redis queue failed, fall through to FastCGI method.
			error_log( '[All Sites Cron] Redis queue failed, falling back to FastCGI method' );
		}

		// Fallback to FastCGI/connection close method.
		$extra_data = $ga_mode ? [] : [
			'success'   => true,
			'status'    => 'queued',
			'timestamp' => gmdate( 'Y-m-d H:i:s', $now_gmt ),
			'mode'      => 'deferred',
		];
		$response   = create_response(
			$ga_mode,
			__( 'Cron job queued for background processing', 'all-sites-cron' ),
			202,
			$extra_data
		);

		// Close connection and continue processing (webserver dependent).
		close_connection_and_continue( $response );

		// Process in background and cleanup.
		execute_and_cleanup( $now_gmt );
		exit;
	}

	// Standard synchronous mode.
	$result = execute_and_cleanup( $now_gmt );

	// Return appropriate response based on mode.
	if ( $ga_mode ) {
		$message = empty( $result[ 'success' ] )
			? $result[ 'message' ]
			: sprintf( __( 'Running wp-cron on %d sites', 'all-sites-cron' ), $result[ 'count' ] );
		return create_response( true, $message, 200 );
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

/**
 * REST callback handler for processing Redis queue.
 * Should be called by a worker process or cron job.
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

	// Check if Redis is available.
	if ( ! is_redis_available() ) {
		return new \WP_REST_Response( [
			'success' => false,
			'message' => __( 'Redis is not available', 'all-sites-cron' ),
		], 503 );
	}

	// Process the queue.
	$result = process_redis_queue();

	return new \WP_REST_Response( $result, $result[ 'success' ] ? 200 : 500 );
}

/**
 * Run wp-cron on all public sites in the multisite network.
 * Uses batch processing for large networks to prevent memory issues.
 * 
 * @return array
 */
function run_cron_on_all_sites(): array {
	if ( ! is_multisite() ) {
		return create_error_response( __( 'This plugin requires WordPress Multisite', 'all-sites-cron' ) );
	}

	$errors        = [];
	$total_count   = 0;
	$doing_wp_cron = sprintf( '%.22F', microtime( true ) );
	$timeout       = get_filter( 'all_sites_cron_request_timeout', 'dss_cron_request_timeout', 0.01 );
	$batch_size    = (int) apply_filters( 'all_sites_cron_batch_size', 50 );
	$max_sites     = (int) get_filter( 'all_sites_cron_number_of_sites', 'dss_cron_number_of_sites', 1000 );
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
 * Acquire execution lock.
 *
 * @return true|\WP_Error True on success, WP_Error on failure.
 */
function acquire_lock() {
	$lock_key     = 'all_sites_cron_lock';
	$lock_timeout = MINUTE_IN_SECONDS * 5;
	$now          = time();

	$existing_lock = get_site_transient( $lock_key );

	if ( false !== $existing_lock ) {
		if ( ( $now - $existing_lock ) < $lock_timeout ) {
			return new \WP_Error(
				'all_sites_cron_locked',
				__( 'Another cron process is currently running. Please try again later.', 'all-sites-cron' ),
				[ 'status' => 409 ]
			);
		}
		error_log( sprintf( '[All Sites Cron] Stale lock detected and removed (age: %d seconds)', $now - $existing_lock ) );
	}

	set_site_transient( $lock_key, $now, $lock_timeout );
	return true;
}

/**
 * Release execution lock.
 *
 * @return void
 */
function release_lock(): void {
	delete_site_transient( 'all_sites_cron_lock' );
}

/**
 * Check rate limiting.
 *
 * @return array|true Array with rate limit info if limited, true if OK.
 */
function check_rate_limit() {
	$cooldown      = (int) get_filter( 'all_sites_cron_rate_limit_seconds', 'dss_cron_rate_limit_seconds', 60 );
	$now_gmt       = time();
	$last_run      = (int) get_site_transient( 'all_sites_cron_last_run_ts' );
	$seconds_since = $last_run ? ( $now_gmt - $last_run ) : $cooldown + 1;

	if ( $cooldown > 0 && $seconds_since < $cooldown ) {
		$retry_after = $cooldown - $seconds_since;
		return [
			'success'      => false,
			'error'        => 'rate_limited',
			'message'      => sprintf( __( 'Rate limited. Try again in %d seconds.', 'all-sites-cron' ), $retry_after ),
			'retry_after'  => $retry_after,
			'cooldown'     => $cooldown,
			'last_run_gmt' => $last_run ?: null,
			'timestamp'    => gmdate( 'Y-m-d H:i:s', $now_gmt ),
		];
	}

	return true;
}

/**
 * Execute cron and cleanup (with error handling).
 *
 * @param int $timestamp Current timestamp.
 * @return array Execution result.
 */
function execute_and_cleanup( int $timestamp ): array {
	$cooldown = (int) get_filter( 'all_sites_cron_rate_limit_seconds', 'dss_cron_rate_limit_seconds', 60 );

	try {
		$result = run_cron_on_all_sites();
		set_site_transient( 'all_sites_cron_last_run_ts', $timestamp, $cooldown > 0 ? $cooldown : 60 );

		if ( empty( $result[ 'success' ] ) && ! empty( $result[ 'message' ] ) ) {
			error_log( sprintf( '[All Sites Cron] Execution failed: %s', $result[ 'message' ] ) );
		} else {
			error_log( sprintf( '[All Sites Cron] Execution completed: %d sites processed', $result[ 'count' ] ?? 0 ) );
		}

		return $result;
	} catch (\Exception $e) {
		error_log( sprintf( '[All Sites Cron] Exception: %s', $e->getMessage() ) );
		return [
			'success' => false,
			'message' => 'Internal error: ' . $e->getMessage(),
			'count'   => 0,
		];
	} finally {
		release_lock();
	}
}

/**
 * Create an error response.
 *
 * @param string $error_message Error message.
 * @return array
 */
function create_error_response( $error_message ): array {
	return [
		'success' => false,
		'message' => $error_message,
	];
}

/**
 * Create a REST response based on mode (GA or JSON).
 *
 * @param bool   $ga_mode    Whether GitHub Actions mode is enabled.
 * @param string $message    Message to send.
 * @param int    $status     HTTP status code.
 * @param array  $extra_data Additional data for JSON mode.
 * @return \WP_REST_Response
 */
function create_response( bool $ga_mode, string $message, int $status = 200, array $extra_data = [] ): \WP_REST_Response {
	if ( $ga_mode ) {
		$prefix = $status >= 400 ? '::error::' : '::notice::';
		$txt    = "{$prefix}{$message}\n";
		if ( $status === 409 ) {
			$prefix = '::warning::';
			$txt    = "{$prefix}{$message}\n";
		}
		$response = new \WP_REST_Response( $txt, $status );
		$response->header( 'Content-Type', 'text/plain; charset=utf-8' );
		return $response;
	}

	// JSON mode.
	$data = array_merge( [ 'message' => $message ], $extra_data );
	return new \WP_REST_Response( $data, $status );
}

/**
 * Get filter value with legacy fallback support.
 *
 * @param string $new_filter    New filter name.
 * @param string $legacy_filter Legacy filter name.
 * @param mixed  $default       Default value.
 * @return mixed
 */
function get_filter( string $new_filter, string $legacy_filter, $default ) {
	return apply_filters( $new_filter, apply_filters( $legacy_filter, $default ) );
}

/**
 * Check if Redis is available and properly configured.
 *
 * @return bool
 */
function is_redis_available(): bool {
	// Check if Redis extension is loaded.
	if ( ! extension_loaded( 'redis' ) ) {
		return false;
	}

	// Check if Redis object cache is available (common in WordPress setups).
	if ( function_exists( 'wp_cache_get_redis_instance' ) ) {
		return true;
	}

	// Try to connect to Redis directly.
	try {
		$redis = new \Redis();
		$host  = apply_filters( 'all_sites_cron_redis_host', '127.0.0.1' );
		$port  = apply_filters( 'all_sites_cron_redis_port', 6379 );

		if ( $redis->connect( $host, $port, 1 ) ) {
			$redis->close();
			return true;
		}
	} catch (\Exception $e) {
		return false;
	}

	return false;
}

/**
 * Get Redis instance for queue operations.
 *
 * @return \Redis|null
 */
function get_redis_instance(): ?\Redis {
	// Try to get Redis instance from WordPress object cache.
	if ( function_exists( 'wp_cache_get_redis_instance' ) ) {
		return wp_cache_get_redis_instance();
	}

	// Create new Redis connection.
	try {
		$redis = new \Redis();
		$host  = apply_filters( 'all_sites_cron_redis_host', '127.0.0.1' );
		$port  = apply_filters( 'all_sites_cron_redis_port', 6379 );
		$db    = apply_filters( 'all_sites_cron_redis_db', 0 );

		if ( ! $redis->connect( $host, $port, 1 ) ) {
			return null;
		}

		if ( $db > 0 ) {
			$redis->select( $db );
		}

		return $redis;
	} catch (\Exception $e) {
		error_log( sprintf( '[All Sites Cron] Redis connection failed: %s', $e->getMessage() ) );
		return null;
	}
}

/**
 * Queue job to Redis.
 *
 * @param int $timestamp Current timestamp.
 * @return bool True on success, false on failure.
 */
function queue_to_redis( int $timestamp ): bool {
	$redis = get_redis_instance();
	if ( ! $redis ) {
		return false;
	}

	try {
		$queue_key = apply_filters( 'all_sites_cron_redis_queue_key', 'all_sites_cron:jobs' );
		$job_data  = [
			'timestamp' => $timestamp,
			'queued_at' => time(),
		];

		// Push job to Redis list.
		$result = $redis->rPush( $queue_key, wp_json_encode( $job_data ) );

		if ( $result ) {
			error_log( sprintf( '[All Sites Cron] Job queued to Redis (queue length: %d)', $result ) );
			return true;
		}
	} catch (\Exception $e) {
		error_log( sprintf( '[All Sites Cron] Redis queue failed: %s', $e->getMessage() ) );
	}

	return false;
}

/**
 * Process jobs from Redis queue.
 * Should be called by a separate worker process or cron job.
 *
 * @return array Processing result.
 */
function process_redis_queue(): array {
	$redis = get_redis_instance();
	if ( ! $redis ) {
		return [
			'success' => false,
			'message' => 'Redis not available',
			'count'   => 0,
		];
	}

	try {
		$queue_key = apply_filters( 'all_sites_cron_redis_queue_key', 'all_sites_cron:jobs' );
		$job_json  = $redis->lPop( $queue_key );

		if ( ! $job_json ) {
			return [
				'success' => true,
				'message' => 'No jobs in queue',
				'count'   => 0,
			];
		}

		$job_data  = json_decode( $job_json, true );
		$timestamp = $job_data[ 'timestamp' ] ?? time();

		error_log( sprintf( '[All Sites Cron] Processing job from Redis (queued %d seconds ago)', time() - ( $job_data[ 'queued_at' ] ?? time() ) ) );

		// Process the job.
		return execute_and_cleanup( $timestamp );
	} catch (\Exception $e) {
		error_log( sprintf( '[All Sites Cron] Redis queue processing failed: %s', $e->getMessage() ) );
		return [
			'success' => false,
			'message' => 'Redis error: ' . $e->getMessage(),
			'count'   => 0,
		];
	}
}

/**
 * Close connection to client and continue processing in background.
 * Supports multiple webserver configurations.
 *
 * @param \WP_REST_Response $response The response to send before closing connection.
 * @return void
 */
function close_connection_and_continue( \WP_REST_Response $response ): void {
	// Try FastCGI method (Nginx + PHP-FPM, Apache + mod_fcgid, etc.).
	if ( function_exists( 'fastcgi_finish_request' ) ) {
		// Send the response.
		status_header( $response->get_status() );
		foreach ( $response->get_headers() as $header => $value ) {
			header( sprintf( '%s: %s', $header, $value ) );
		}
		echo wp_json_encode( $response->get_data() );

		// Close the connection and flush buffers.
		fastcgi_finish_request();
		return;
	}

	// Fallback for Apache mod_php and other configurations.
	// This attempts to close the connection early, but may not work on all setups.
	if ( ! headers_sent() ) {
		// Disable output buffering and compression.
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		// Start output buffering to capture response.
		ob_start();

		// Send response.
		status_header( $response->get_status() );
		foreach ( $response->get_headers() as $header => $value ) {
			header( sprintf( '%s: %s', $header, $value ) );
		}
		header( 'Connection: close' );
		header( 'Content-Encoding: none' );

		echo wp_json_encode( $response->get_data() );

		$size = ob_get_length();
		header( 'Content-Length: ' . $size );

		// Flush output buffers.
		ob_end_flush();
		if ( function_exists( 'flush' ) ) {
			flush();
		}
	}

	// Set timeout to allow long-running process.
	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 300 ); // 5 minutes max.
	}
}

/**
 * Activation hook: Run on plugin activation.
 */
function activation(): void {
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
function deactivation(): void {
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
function maybe_migrate_legacy_transients(): void {
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
