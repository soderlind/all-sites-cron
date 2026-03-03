<?php
/**
 * Integration-level tests for the REST endpoint callbacks.
 *
 * Because rest_run() and rest_process_queue() create internal objects
 * (Lock, Cron_Runner, Redis_Queue), we mock the underlying WP functions
 * that those objects call rather than injecting mock collaborators.
 *
 * @package All_Sites_Cron
 */

namespace Soderlind\Multisite\AllSitesCron\Tests;

use Brain\Monkey\Functions;
use Soderlind\Multisite\AllSitesCron\Redis_Queue;

use function Soderlind\Multisite\AllSitesCron\rest_run;
use function Soderlind\Multisite\AllSitesCron\rest_process_queue;

/**
 * @covers \Soderlind\Multisite\AllSitesCron\rest_run
 * @covers \Soderlind\Multisite\AllSitesCron\rest_process_queue
 */
class RestEndpointsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		// Reset Redis_Queue static availability cache between tests.
		$ref = new \ReflectionProperty( Redis_Queue::class, 'available_cache' );
		$ref->setAccessible( true );
		$ref->setValue( null, null );
	}

	// ------------------------------------------------------------------
	// Non-multisite guard
	// ------------------------------------------------------------------

	public function test_rest_run_returns_error_on_single_site(): void {
		Functions\when( 'is_multisite' )->justReturn( false );

		$request  = new \WP_REST_Request( 'GET', '/all-sites-cron/v1/run' );
		$response = rest_run( $request );

		$this->assertWPError( $response );
	}

	// ------------------------------------------------------------------
	// Rate limiting
	// ------------------------------------------------------------------

	public function test_rest_run_returns_429_when_rate_limited(): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'get_site_transient' )->justReturn( time() ); // Recent run.

		$request  = new \WP_REST_Request( 'GET', '/all-sites-cron/v1/run' );
		$response = rest_run( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 429, $response->get_status() );
	}

	// ------------------------------------------------------------------
	// Successful sync run
	// ------------------------------------------------------------------

	public function test_rest_run_returns_200_on_success(): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'get_site_transient' )->justReturn( false ); // No previous run.

		// Lock (object-cache path).
		Functions\when( 'wp_cache_add_global_groups' )->justReturn( null );
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( true );
		Functions\when( 'wp_cache_add' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );

		// Cron runner — one site returned.
		$site = new class () {
			public function __get( string $name ) {
				return 'https://example.com';
			}
		};
		Functions\when( 'get_sites' )->justReturn( [ $site ] );
		Functions\when( 'wp_remote_post' )->justReturn( [] );
		Functions\when( 'home_url' )->justReturn( 'https://example.com/' );
		Functions\when( 'set_site_transient' )->justReturn( true );

		$request  = new \WP_REST_Request( 'GET', '/all-sites-cron/v1/run' );
		$response = rest_run( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertTrue( $data[ 'success' ] );
		$this->assertGreaterThanOrEqual( 1, $data[ 'count' ] );
	}

	// ------------------------------------------------------------------
	// Lock contention
	// ------------------------------------------------------------------

	public function test_rest_run_returns_409_when_locked(): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'get_site_transient' )->justReturn( false ); // Not rate-limited.

		// Lock fails.
		Functions\when( 'wp_cache_add_global_groups' )->justReturn( null );
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( true );
		Functions\when( 'wp_cache_add' )->justReturn( false ); // Lock held.
		Functions\when( 'wp_cache_get' )->justReturn( time() ); // Recent, not stale.

		$request  = new \WP_REST_Request( 'GET', '/all-sites-cron/v1/run' );
		$response = rest_run( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 409, $response->get_status() );
	}
}
