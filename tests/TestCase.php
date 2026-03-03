<?php
/**
 * Base test case for All Sites Cron Brain Monkey tests.
 *
 * Sets up and tears down Brain Monkey per test, and provides
 * common WP function stubs and assertion helpers.
 *
 * @package All_Sites_Cron\Tests
 */

namespace Soderlind\Multisite\AllSitesCron\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * Abstract base test case — extend this instead of WP_UnitTestCase.
 */
abstract class TestCase extends PHPUnitTestCase {

	// Let Mockery report unmet expectations as PHPUnit failures.
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Functions that almost every test path triggers.
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( 'esc_attr__' )->returnArg( 1 );
		Functions\when( 'error_log' )->justReturn( true );

		Functions\when( 'is_wp_error' )->alias(
			function ( $thing ) {
				return $thing instanceof \WP_Error;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * Assert that the given value is a WP_Error instance.
	 *
	 * Mirrors WP_UnitTestCase::assertWPError().
	 *
	 * @param mixed  $actual  Value to test.
	 * @param string $message Optional failure message.
	 */
	protected function assertWPError( $actual, string $message = '' ): void {
		$this->assertInstanceOf( \WP_Error::class, $actual, $message );
	}
}
