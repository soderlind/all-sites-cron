<?php
/**
 * Atomic execution lock for All Sites Cron.
 *
 * Uses wp_cache_add() for atomic lock acquisition when a persistent
 * object cache is available, falling back to a database-level atomic
 * INSERT for environments without one.
 *
 * @package All_Sites_Cron
 * @since   2.0.0
 */

namespace Soderlind\Multisite\AllSitesCron;

/**
 * Manages an exclusive execution lock to prevent overlapping cron runs.
 */
class Lock {

	/**
	 * Cache / option key used for the lock.
	 *
	 * @var string
	 */
	private string $key = 'all_sites_cron_lock';

	/**
	 * Cache group (used with wp_cache_*).
	 *
	 * @var string
	 */
	private string $group = 'all_sites_cron';

	/**
	 * Maximum lock lifetime in seconds before it is considered stale.
	 *
	 * @var int
	 */
	private int $timeout;

	/**
	 * Constructor.
	 *
	 * @param int $timeout Lock timeout in seconds. Defaults to ALL_SITES_CRON_LOCK_TIMEOUT.
	 */
	public function __construct( int $timeout = 0 ) {
		$this->timeout = $timeout > 0 ? $timeout : (int) ALL_SITES_CRON_LOCK_TIMEOUT;

		// Register as a global cache group so it is shared across switched sites.
		if ( function_exists( 'wp_cache_add_global_groups' ) ) {
			wp_cache_add_global_groups( $this->group );
		}
	}

	/**
	 * Try to acquire the lock atomically.
	 *
	 * @return true|\WP_Error True on success, WP_Error when already locked.
	 */
	public function acquire() {
		$now = time();

		if ( wp_using_ext_object_cache() ) {
			return $this->acquire_via_object_cache( $now );
		}

		return $this->acquire_via_database( $now );
	}

	/**
	 * Release the lock.
	 *
	 * @return void
	 */
	public function release(): void {
		if ( wp_using_ext_object_cache() ) {
			wp_cache_delete( $this->key, $this->group );
		} else {
			delete_site_transient( $this->key );
		}
	}

	// ------------------------------------------------------------------
	// Private helpers
	// ------------------------------------------------------------------

	/**
	 * Acquire lock via the external object cache (atomic wp_cache_add).
	 *
	 * @param int $now Current Unix timestamp.
	 * @return true|\WP_Error
	 */
	private function acquire_via_object_cache( int $now ) {
		// wp_cache_add is atomic: returns false if key already exists.
		if ( wp_cache_add( $this->key, $now, $this->group, $this->timeout ) ) {
			return true;
		}

		// Key exists — check for staleness.
		$existing = (int) wp_cache_get( $this->key, $this->group );

		if ( ( $now - $existing ) >= $this->timeout ) {
			// Stale lock. Delete and retry once.
			error_log( sprintf( '[All Sites Cron] Stale lock detected and removed (age: %d seconds)', $now - $existing ) );
			wp_cache_delete( $this->key, $this->group );

			if ( wp_cache_add( $this->key, $now, $this->group, $this->timeout ) ) {
				return true;
			}
		}

		return $this->locked_error();
	}

	/**
	 * Acquire lock via the database (site transient with atomic INSERT).
	 *
	 * Site transients on multisite without an external object cache are stored
	 * in wp_sitemeta. We use a direct INSERT IGNORE to make the operation atomic.
	 *
	 * @param int $now Current Unix timestamp.
	 * @return true|\WP_Error
	 */
	private function acquire_via_database( int $now ) {
		global $wpdb;

		if ( is_multisite() ) {
			return $this->acquire_via_sitemeta( $wpdb, $now );
		}

		// Single-site fallback: use options table.
		return $this->acquire_via_options( $wpdb, $now );
	}

	/**
	 * Atomic lock via wp_sitemeta (multisite without object cache).
	 *
	 * @param \wpdb $wpdb  WordPress database object.
	 * @param int   $now   Current Unix timestamp.
	 * @return true|\WP_Error
	 */
	private function acquire_via_sitemeta( \wpdb $wpdb, int $now ) {
		$meta_key = '_site_transient_' . $this->key;
		$site_id  = get_current_network_id();

		// Try atomic insert. INSERT IGNORE silently fails if key exists.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->sitemeta} (site_id, meta_key, meta_value) VALUES (%d, %s, %s)",
				$site_id,
				$meta_key,
				(string) $now
			)
		);

		if ( $inserted ) {
			// Also set the timeout entry so WP's transient expiry works.
			set_site_transient( $this->key . '_timeout', $now + $this->timeout );
			return true;
		}

		// Row exists — check for staleness.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->sitemeta} WHERE site_id = %d AND meta_key = %s",
				$site_id,
				$meta_key
			)
		);

		if ( ( $now - $existing ) >= $this->timeout ) {
			error_log( sprintf( '[All Sites Cron] Stale lock detected and removed (age: %d seconds)', $now - $existing ) );
			delete_site_transient( $this->key );

			// Retry the insert.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$inserted = $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$wpdb->sitemeta} (site_id, meta_key, meta_value) VALUES (%d, %s, %s)",
					$site_id,
					$meta_key,
					(string) $now
				)
			);

			if ( $inserted ) {
				set_site_transient( $this->key . '_timeout', $now + $this->timeout );
				return true;
			}
		}

		return $this->locked_error();
	}

	/**
	 * Atomic lock via wp_options (single-site fallback).
	 *
	 * @param \wpdb $wpdb  WordPress database object.
	 * @param int   $now   Current Unix timestamp.
	 * @return true|\WP_Error
	 */
	private function acquire_via_options( \wpdb $wpdb, int $now ) {
		$option_key = '_site_transient_' . $this->key;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				$option_key,
				(string) $now
			)
		);

		if ( $inserted ) {
			return true;
		}

		// Row exists — check for staleness.
		$existing = (int) get_site_transient( $this->key );

		if ( ( $now - $existing ) >= $this->timeout ) {
			error_log( sprintf( '[All Sites Cron] Stale lock detected and removed (age: %d seconds)', $now - $existing ) );
			delete_site_transient( $this->key );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$inserted = $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
					$option_key,
					(string) $now
				)
			);

			if ( $inserted ) {
				return true;
			}
		}

		return $this->locked_error();
	}

	/**
	 * Return a standardised WP_Error for a locked state.
	 *
	 * @return \WP_Error
	 */
	private function locked_error(): \WP_Error {
		return new \WP_Error(
			'all_sites_cron_locked',
			__( 'Another cron process is currently running. Please try again later.', 'all-sites-cron' ),
			[ 'status' => 409 ]
		);
	}
}
