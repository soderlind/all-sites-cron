<?php
/**
 * Tests for the Cron_Runner class.
 *
 * @package All_Sites_Cron
 */

namespace Soderlind\Multisite\AllSitesCron\Tests;

use Brain\Monkey\Functions;
use Soderlind\Multisite\AllSitesCron\Cron_Runner;

/**
 * @covers \Soderlind\Multisite\AllSitesCron\Cron_Runner
 */
class CronRunnerTest extends TestCase {

	// ------------------------------------------------------------------
	// Non-multisite guard
	// ------------------------------------------------------------------

	public function test_run_all_sites_fails_on_single_site(): void {
		Functions\when( 'is_multisite' )->justReturn( false );

		$runner = new Cron_Runner();
		$result = $runner->run_all_sites();

		$this->assertFalse( $result[ 'success' ] );
		$this->assertStringContainsString( 'Multisite', $result[ 'message' ] );
	}

	// ------------------------------------------------------------------
	// Multisite — basic execution
	// ------------------------------------------------------------------

	public function test_run_all_sites_returns_success_on_multisite(): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'home_url' )->justReturn( 'https://example.com/' );
		Functions\when( 'wp_remote_post' )->justReturn( [ 'response' => [ 'code' => 200 ] ] );
		Functions\when( 'get_sites' )->justReturn( [ $this->createMockSite( 'https://example.com' ) ] );

		$runner = new Cron_Runner();
		$result = $runner->run_all_sites();

		$this->assertTrue( $result[ 'success' ] );
		$this->assertGreaterThanOrEqual( 1, $result[ 'count' ] );
	}

	// ------------------------------------------------------------------
	// Filter: max_sites
	// ------------------------------------------------------------------

	public function test_max_sites_filter_caps_count(): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'home_url' )->justReturn( 'https://example.com/' );
		Functions\when( 'wp_remote_post' )->justReturn( [ 'response' => [ 'code' => 200 ] ] );
		Functions\when( 'get_sites' )->justReturn( [ $this->createMockSite( 'https://example.com' ) ] );

		add_filter(
			'all_sites_cron_number_of_sites',
			function () {
				return 1;
			}
		);

		$runner = new Cron_Runner();
		$result = $runner->run_all_sites();

		$this->assertLessThanOrEqual( 1, $result[ 'count' ] );
	}

	// ------------------------------------------------------------------
	// Helper
	// ------------------------------------------------------------------

	/**
	 * Create a mock site object with a __get method (mimics WP_Site).
	 */
	private function createMockSite( string $url ): object {
		return new class ($url) {
			private string $siteurl;

			public function __construct( string $siteurl ) {
				$this->siteurl = $siteurl;
			}

			/**
			 * @return mixed
			 */
			public function __get( string $name ) {
				return match ( $name ) {
					'siteurl' => $this->siteurl,
					default   => null,
				};
			}
		};
	}
}
