<?php
/**
 * Redis-backed job queue for All Sites Cron.
 *
 * Handles push / pop / re-push with retry tracking,
 * and manages the Redis connection lifecycle.
 *
 * @package All_Sites_Cron
 * @since   2.0.0
 */

namespace Soderlind\Multisite\AllSitesCron;

/**
 * Redis job queue with connection lifecycle management.
 */
class Redis_Queue {

	/**
	 * Redis queue key.
	 *
	 * @var string
	 */
	private string $queue_key;

	/**
	 * Maximum number of times a job may be re-queued before being discarded.
	 *
	 * @var int
	 */
	private int $max_retries;

	/**
	 * Cached availability result (null = not yet checked).
	 *
	 * @var bool|null
	 */
	private static ?bool $available_cache = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->queue_key   = (string) apply_filters( 'all_sites_cron_redis_queue_key', 'all_sites_cron:jobs' );
		$this->max_retries = (int) apply_filters( 'all_sites_cron_redis_max_retries', 3 );
	}

	// ------------------------------------------------------------------
	// Public API
	// ------------------------------------------------------------------

	/**
	 * Check whether Redis is reachable (result cached per request).
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		if ( null !== self::$available_cache ) {
			return self::$available_cache;
		}

		self::$available_cache = $this->check_availability();
		return self::$available_cache;
	}

	/**
	 * Push a new job onto the queue.
	 *
	 * @param int $timestamp The originating timestamp for the cron run.
	 * @return bool True on success.
	 */
	public function push( int $timestamp ): bool {
		$connection = $this->open();
		if ( ! $connection ) {
			return false;
		}

		try {
			$job_data = [
				'timestamp' => $timestamp,
				'queued_at' => time(),
				'retries'   => 0,
			];

			$result = $connection[ 'redis' ]->rPush( $this->queue_key, wp_json_encode( $job_data ) );

			if ( $result ) {
				error_log( sprintf( '[All Sites Cron] Job queued to Redis (queue length: %d)', $result ) );
				return true;
			}
		} catch (\Exception $e) {
			error_log( sprintf( '[All Sites Cron] Redis queue push failed: %s', $e->getMessage() ) );
		} finally {
			$this->maybe_close( $connection );
		}

		return false;
	}

	/**
	 * Pop the next job from the queue.
	 *
	 * Returns the decoded job array or null when the queue is empty.
	 *
	 * @return array{timestamp: int, queued_at: int, retries: int}|null
	 */
	public function pop(): ?array {
		$connection = $this->open();
		if ( ! $connection ) {
			return null;
		}

		try {
			$job_json = $connection[ 'redis' ]->lPop( $this->queue_key );

			if ( ! $job_json ) {
				return null;
			}

			$job_data = json_decode( $job_json, true );

			if ( ! is_array( $job_data ) || ! isset( $job_data[ 'timestamp' ] ) ) {
				error_log( '[All Sites Cron] Malformed job popped from Redis queue — discarding.' );
				return null;
			}

			// Ensure retries key exists.
			$job_data[ 'retries' ] = (int) ( $job_data[ 'retries' ] ?? 0 );

			error_log(
				sprintf(
					'[All Sites Cron] Processing job from Redis (queued %d seconds ago, retries: %d)',
					time() - ( $job_data[ 'queued_at' ] ?? time() ),
					$job_data[ 'retries' ]
				)
			);

			return $job_data;
		} catch (\Exception $e) {
			error_log( sprintf( '[All Sites Cron] Redis queue pop failed: %s', $e->getMessage() ) );
			return null;
		} finally {
			$this->maybe_close( $connection );
		}
	}

	/**
	 * Re-push a job that could not be processed (e.g. lock held).
	 *
	 * Increments the retry counter. Returns false when max retries exceeded
	 * (job is intentionally dropped).
	 *
	 * @param array $job_data The job array as returned by pop().
	 * @return bool True if re-queued, false if discarded.
	 */
	public function repush( array $job_data ): bool {
		$job_data[ 'retries' ] = ( $job_data[ 'retries' ] ?? 0 ) + 1;

		if ( $job_data[ 'retries' ] > $this->max_retries ) {
			error_log(
				sprintf(
					'[All Sites Cron] Job exceeded max retries (%d) — discarding.',
					$this->max_retries
				)
			);
			return false;
		}

		$connection = $this->open();
		if ( ! $connection ) {
			return false;
		}

		try {
			$result = $connection[ 'redis' ]->rPush( $this->queue_key, wp_json_encode( $job_data ) );

			if ( $result ) {
				error_log(
					sprintf(
						'[All Sites Cron] Job re-queued (retry %d/%d, queue length: %d)',
						$job_data[ 'retries' ],
						$this->max_retries,
						$result
					)
				);
				return true;
			}
		} catch (\Exception $e) {
			error_log( sprintf( '[All Sites Cron] Redis re-push failed: %s', $e->getMessage() ) );
		} finally {
			$this->maybe_close( $connection );
		}

		return false;
	}

	// ------------------------------------------------------------------
	// Connection management
	// ------------------------------------------------------------------

	/**
	 * Open a Redis connection.
	 *
	 * Returns an associative array with the Redis instance and an 'owned'
	 * flag indicating whether we created the connection (and should close it).
	 *
	 * @return array{redis: \Redis, owned: bool}|null
	 */
	private function open(): ?array {
		// Prefer the WP object-cache Redis instance (shared, not owned).
		if ( function_exists( 'wp_cache_get_redis_instance' ) ) {
			$redis = wp_cache_get_redis_instance();
			if ( $redis instanceof \Redis ) {
				return [ 'redis' => $redis, 'owned' => false ];
			}
		}

		// Create our own connection.
		try {
			$redis = new \Redis();
			$host  = (string) apply_filters( 'all_sites_cron_redis_host', '127.0.0.1' );
			$port  = (int) apply_filters( 'all_sites_cron_redis_port', 6379 );
			$db    = (int) apply_filters( 'all_sites_cron_redis_db', 0 );

			if ( ! $redis->connect( $host, $port, 1 ) ) {
				return null;
			}

			if ( $db > 0 ) {
				$redis->select( $db );
			}

			return [ 'redis' => $redis, 'owned' => true ];
		} catch (\Exception $e) {
			error_log( sprintf( '[All Sites Cron] Redis connection failed: %s', $e->getMessage() ) );
			return null;
		}
	}

	/**
	 * Close an owned Redis connection.
	 *
	 * Shared connections (from WP object cache) are left open.
	 *
	 * @param array{redis: \Redis, owned: bool}|null $connection Connection array.
	 * @return void
	 */
	private function maybe_close( ?array $connection ): void {
		if ( $connection && $connection[ 'owned' ] ) {
			try {
				$connection[ 'redis' ]->close();
			} catch (\Exception $e) {
				// Ignored — we're tearing down anyway.
			}
		}
	}

	/**
	 * Perform the actual availability check (not cached).
	 *
	 * @return bool
	 */
	private function check_availability(): bool {
		if ( ! extension_loaded( 'redis' ) ) {
			return false;
		}

		if ( function_exists( 'wp_cache_get_redis_instance' ) ) {
			return true;
		}

		try {
			$redis = new \Redis();
			$host  = (string) apply_filters( 'all_sites_cron_redis_host', '127.0.0.1' );
			$port  = (int) apply_filters( 'all_sites_cron_redis_port', 6379 );

			if ( $redis->connect( $host, $port, 1 ) ) {
				$redis->close();
				return true;
			}
		} catch (\Exception $e) {
			return false;
		}

		return false;
	}
}
