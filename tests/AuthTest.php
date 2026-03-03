<?php
/**
 * Tests for the Auth class.
 *
 * @package All_Sites_Cron
 */

namespace Soderlind\Multisite\AllSitesCron\Tests;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Soderlind\Multisite\AllSitesCron\Auth;

/**
 * @covers \Soderlind\Multisite\AllSitesCron\Auth
 */
class AuthTest extends TestCase {

	// ------------------------------------------------------------------
	// Auth disabled (default)
	// ------------------------------------------------------------------

	public function test_returns_true_when_auth_not_required(): void {
		Filters\expectApplied( 'all_sites_cron_require_auth' )
			->once()
			->andReturn( false );

		$request = new \WP_REST_Request( 'GET', '/all-sites-cron/v1/run' );
		$result  = Auth::permission_callback( $request );

		$this->assertTrue( $result );
	}

	// ------------------------------------------------------------------
	// Auth enabled — no token configured
	// ------------------------------------------------------------------

	public function test_fails_when_auth_required_but_no_token_configured(): void {
		Filters\expectApplied( 'all_sites_cron_require_auth' )
			->once()
			->andReturn( true );
		Functions\when( 'get_site_option' )->justReturn( '' );

		$request = new \WP_REST_Request( 'GET', '/all-sites-cron/v1/run' );
		$result  = Auth::permission_callback( $request );

		$this->assertWPError( $result );
		$this->assertSame( 'all_sites_cron_auth_not_configured', $result->get_error_code() );
	}

	// ------------------------------------------------------------------
	// Auth enabled — missing token
	// ------------------------------------------------------------------

	public function test_fails_when_token_missing(): void {
		Filters\expectApplied( 'all_sites_cron_require_auth' )
			->once()
			->andReturn( true );
		Functions\when( 'get_site_option' )->justReturn( 'secret123' );

		$request = new \WP_REST_Request( 'GET', '/all-sites-cron/v1/run' );
		$result  = Auth::permission_callback( $request );

		$this->assertWPError( $result );
		$this->assertSame( 'all_sites_cron_auth_missing', $result->get_error_code() );
	}

	// ------------------------------------------------------------------
	// Auth enabled — wrong token
	// ------------------------------------------------------------------

	public function test_fails_when_token_invalid(): void {
		Filters\expectApplied( 'all_sites_cron_require_auth' )
			->once()
			->andReturn( true );
		Functions\when( 'get_site_option' )->justReturn( 'secret123' );

		$request = new \WP_REST_Request( 'GET', '/all-sites-cron/v1/run' );
		$request->set_param( 'token', 'wrong-token' );
		$result = Auth::permission_callback( $request );

		$this->assertWPError( $result );
		$this->assertSame( 'all_sites_cron_auth_invalid', $result->get_error_code() );
	}

	// ------------------------------------------------------------------
	// Auth enabled — valid token via query param
	// ------------------------------------------------------------------

	public function test_succeeds_with_valid_query_param_token(): void {
		Filters\expectApplied( 'all_sites_cron_require_auth' )
			->once()
			->andReturn( true );
		Functions\when( 'get_site_option' )->justReturn( 'secret123' );

		$request = new \WP_REST_Request( 'GET', '/all-sites-cron/v1/run' );
		$request->set_param( 'token', 'secret123' );
		$result = Auth::permission_callback( $request );

		$this->assertTrue( $result );
	}

	// ------------------------------------------------------------------
	// Auth enabled — valid token via Bearer header
	// ------------------------------------------------------------------

	public function test_succeeds_with_valid_bearer_header(): void {
		Filters\expectApplied( 'all_sites_cron_require_auth' )
			->once()
			->andReturn( true );
		Functions\when( 'get_site_option' )->justReturn( 'secret123' );

		$request = new \WP_REST_Request( 'GET', '/all-sites-cron/v1/run' );
		$request->set_header( 'Authorization', 'Bearer secret123' );
		$result = Auth::permission_callback( $request );

		$this->assertTrue( $result );
	}
}
