<?php
/**
 * Cron runner — iterates all network sites and fires wp-cron.php.
 *
 * @package All_Sites_Cron
 * @since   2.0.0
 */

namespace Soderlind\Multisite\AllSitesCron;

/**
 * Handles the actual execution of wp-cron.php across all public sites.
 */
class Cron_Runner {

	/**
	 * Run wp-cron on every public, non-archived, non-deleted, non-spam site.
	 *
	 * Uses batched get_sites() queries to keep memory usage bounded.
	 *
	 * @return array{success: bool, message: string, count: int, errors?: int, error_code?: string}
	 */
	public function run_all_sites(): array {
		if ( ! is_multisite() ) {
			return [
				'success' => false,
				'message' => __( 'This plugin requires WordPress Multisite', 'all-sites-cron' ),
			];
		}

		$errors        = [];
		$total_count   = 0;
		$doing_wp_cron = sprintf( '%.22F', microtime( true ) );
		$timeout       = get_filter( 'all_sites_cron_request_timeout', 'dss_cron_request_timeout', ALL_SITES_CRON_DEFAULT_TIMEOUT );
		$batch_size    = (int) apply_filters( 'all_sites_cron_batch_size', ALL_SITES_CRON_DEFAULT_BATCH_SIZE );
		$max_sites     = (int) get_filter( 'all_sites_cron_number_of_sites', 'dss_cron_number_of_sites', ALL_SITES_CRON_DEFAULT_MAX_SITES );
		$offset        = 0;

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
					'blocking'   => false,
					'sslverify'  => apply_filters( 'https_local_ssl_verify', false ),
					'user-agent' => 'All Sites Cron; ' . home_url( '/' ),
				] );

				if ( is_wp_error( $response ) ) {
					$error_msg = sprintf(
						/* translators: 1: site URL 2: error message */
						__( 'Error for %1$s: %2$s', 'all-sites-cron' ),
						$url,
						$response->get_error_message()
					);
					$errors[]  = $error_msg;
					error_log( sprintf( '[All Sites Cron] %s', $error_msg ) );
				}

				$total_count++;
			}

			$offset += $batch_size;

			if ( $total_count >= $max_sites ) {
				break;
			}
		} while ( count( $sites ) === $batch_size );

		if ( 0 === $total_count ) {
			return [
				'success' => false,
				'message' => __( 'No public sites found in the network', 'all-sites-cron' ),
			];
		}

		if ( ! empty( $errors ) ) {
			return [
				'success'    => false,
				'message'    => sprintf(
					/* translators: 1: error count 2: error details */
					__( 'Completed with %1$d error(s): %2$s', 'all-sites-cron' ),
					count( $errors ),
					implode( '; ', array_slice( $errors, 0, 3 ) ) . ( count( $errors ) > 3 ? '...' : '' )
				),
				'count'      => $total_count,
				'errors'     => count( $errors ),
				'error_code' => 'PARTIAL_FAILURE',
			];
		}

		return [
			'success' => true,
			'message' => '',
			'count'   => $total_count,
		];
	}

	/**
	 * Execute cron on all sites and handle cleanup (lock release, transient update).
	 *
	 * @param int  $timestamp Current GMT timestamp.
	 * @param Lock $lock      Lock instance to release in the finally block.
	 * @return array Execution result.
	 */
	public function execute_and_cleanup( int $timestamp, Lock $lock ): array {
		$cooldown = (int) get_filter(
			'all_sites_cron_rate_limit_seconds',
			'dss_cron_rate_limit_seconds',
			ALL_SITES_CRON_DEFAULT_COOLDOWN
		);

		try {
			$result = $this->run_all_sites();

			set_site_transient(
				'all_sites_cron_last_run_ts',
				$timestamp,
				$cooldown > 0 ? $cooldown : ALL_SITES_CRON_DEFAULT_COOLDOWN
			);

			if ( empty( $result[ 'success' ] ) && ! empty( $result[ 'message' ] ) ) {
				$error_code = $result[ 'error_code' ] ?? 'EXECUTION_FAILED';
				error_log( sprintf( '[All Sites Cron] Execution failed (Code: %s): %s', $error_code, $result[ 'message' ] ) );
			} else {
				$count       = $result[ 'count' ] ?? 0;
				$errors      = $result[ 'errors' ] ?? 0;
				$log_message = $errors > 0
					? sprintf( 'Execution completed: %d sites processed (%d errors)', $count, $errors )
					: sprintf( 'Execution completed: %d sites processed', $count );
				error_log( sprintf( '[All Sites Cron] %s', $log_message ) );
			}

			return $result;
		} catch (\Exception $e) {
			$error_code = 'EXCEPTION_' . $e->getCode();
			error_log( sprintf( '[All Sites Cron] Exception (Code: %s): %s', $error_code, $e->getMessage() ) );
			return [
				'success'    => false,
				'message'    => 'Internal error: ' . $e->getMessage(),
				'count'      => 0,
				'error_code' => $error_code,
			];
		} finally {
			$lock->release();
		}
	}
}
