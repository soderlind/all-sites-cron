<?php
/**
 * Tests for the rate limiter.
 *
 * @package All_Sites_Cron
 */

namespace Soderlind\Multisite\AllSitesCron\Tests;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

use function Soderlind\Multisite\AllSitesCron\check_rate_limit;

/**
 * @covers \Soderlind\Multisite\AllSitesCron\check_rate_limit
 */
class RateLimitTest extends TestCase {

	// ------------------------------------------------------------------
	// Basic behaviour
	// ------------------------------------------------------------------

	public function test_returns_true_when_no_previous_run(): void {
		Functions\when( 'get_site_transient' )->justReturn( false );

		$result = check_rate_limit();
		$this->assertTrue( $result );
	}

	public function test_returns_array_when_rate_limited(): void {
		// Simulate a very recent run.
		Functions\when( 'get_site_transient' )->justReturn( time() );

		$result = check_rate_limit();
		$this->assertIsArray( $result );
		$this->assertSame( 'rate_limited', $result[ 'error' ] );
		$this->assertArrayHasKey( 'retry_after', $result );
		$this->assertGreaterThan( 0, $result[ 'retry_after' ] );
	}

	public function test_returns_true_after_cooldown_expires(): void {
		// Set last run far enough in the past (default cooldown = 60 s).
		Functions\when( 'get_site_transient' )->justReturn( time() - 120 );

		$result = check_rate_limit();
		$this->assertTrue( $result );
	}

	// ------------------------------------------------------------------
	// Filter override
	// ------------------------------------------------------------------

	public function test_custom_cooldown_via_filter(): void {
		Filters\expectApplied( 'all_sites_cron_rate_limit_seconds' )
			->once()
			->andReturn( 5 );

		Functions\when( 'get_site_transient' )->justReturn( time() - 10 );

		$result = check_rate_limit();
		$this->assertTrue( $result );
	}

	public function test_zero_cooldown_disables_rate_limiting(): void {
		Filters\expectApplied( 'all_sites_cron_rate_limit_seconds' )
			->once()
			->andReturn( 0 );

		Functions\when( 'get_site_transient' )->justReturn( time() );

		$result = check_rate_limit();
		$this->assertTrue( $result );
	}
}
