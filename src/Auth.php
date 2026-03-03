<?php
/**
 * Optional authentication for All Sites Cron REST endpoints.
 *
 * When enabled (via the `all_sites_cron_require_auth` filter or by
 * defining the ALL_SITES_CRON_AUTH_TOKEN constant), requests must
 * include a valid token in the Authorization header or query string.
 *
 * @package All_Sites_Cron
 * @since   2.0.0
 */

namespace Soderlind\Multisite\AllSitesCron;

/**
 * REST endpoint authentication via a shared secret token.
 */
class Auth {

	/**
	 * Permission callback for REST routes.
	 *
	 * Always returns true when auth is disabled (backward-compatible default).
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return true|\WP_Error
	 */
	public static function permission_callback( \WP_REST_Request $request ) {
		/**
		 * Whether authentication is required for All Sites Cron endpoints.
		 *
		 * Automatically enabled when ALL_SITES_CRON_AUTH_TOKEN is defined.
		 *
		 * @since 2.0.0
		 * @param bool $require Default false (opt-in).
		 */
		$require_auth = (bool) apply_filters(
			'all_sites_cron_require_auth',
			defined( 'ALL_SITES_CRON_AUTH_TOKEN' )
		);

		if ( ! $require_auth ) {
			return true;
		}

		$expected_token = self::get_expected_token();

		if ( empty( $expected_token ) ) {
			// Auth is enabled but no token is configured — fail closed.
			return new \WP_Error(
				'all_sites_cron_auth_not_configured',
				__( 'Authentication is enabled but no token is configured. Define ALL_SITES_CRON_AUTH_TOKEN.', 'all-sites-cron' ),
				[ 'status' => 500 ]
			);
		}

		$provided_token = self::extract_token( $request );

		if ( empty( $provided_token ) ) {
			return new \WP_Error(
				'all_sites_cron_auth_missing',
				__( 'Authentication required. Provide a token via Authorization header or ?token= query parameter.', 'all-sites-cron' ),
				[ 'status' => 401 ]
			);
		}

		if ( ! hash_equals( $expected_token, $provided_token ) ) {
			return new \WP_Error(
				'all_sites_cron_auth_invalid',
				__( 'Invalid authentication token.', 'all-sites-cron' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	/**
	 * Get the expected token from constant or site option.
	 *
	 * @return string Empty string if not configured.
	 */
	private static function get_expected_token(): string {
		if ( defined( 'ALL_SITES_CRON_AUTH_TOKEN' ) && '' !== ALL_SITES_CRON_AUTH_TOKEN ) {
			return (string) ALL_SITES_CRON_AUTH_TOKEN;
		}

		return (string) get_site_option( 'all_sites_cron_auth_token', '' );
	}

	/**
	 * Extract the bearer token from the request.
	 *
	 * Checks the Authorization header first, then the ?token= query param.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return string The token, or empty string if not found.
	 */
	private static function extract_token( \WP_REST_Request $request ): string {
		// 1. Authorization: Bearer <token>
		$header = $request->get_header( 'Authorization' );
		if ( $header && preg_match( '/^Bearer\s+(.+)$/i', $header, $matches ) ) {
			return trim( $matches[ 1 ] );
		}

		// 2. ?token=<token>
		$query_token = $request->get_param( 'token' );
		if ( is_string( $query_token ) && '' !== $query_token ) {
			return $query_token;
		}

		return '';
	}
}
