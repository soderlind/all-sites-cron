<?php
/**
 * Tests for the Response helper class.
 *
 * Uses only the WP_REST_Response stub — no WP function mocking needed.
 *
 * @package All_Sites_Cron
 */

namespace Soderlind\Multisite\AllSitesCron\Tests;

use Soderlind\Multisite\AllSitesCron\Response;

/**
 * @covers \Soderlind\Multisite\AllSitesCron\Response
 */
class ResponseTest extends TestCase {

	// ------------------------------------------------------------------
	// JSON mode
	// ------------------------------------------------------------------

	public function test_create_json_response(): void {
		$response = Response::create( false, 'OK', 200, [ 'count' => 5 ] );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'OK', $data[ 'message' ] );
		$this->assertSame( 5, $data[ 'count' ] );
	}

	public function test_create_json_429_response(): void {
		$response = Response::create( false, 'Rate limited', 429 );

		$this->assertSame( 429, $response->get_status() );
	}

	// ------------------------------------------------------------------
	// GA mode
	// ------------------------------------------------------------------

	public function test_create_ga_notice_response(): void {
		$response = Response::create( true, 'All good', 200 );

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringContainsString( '::notice::', $response->get_data() );
		$this->assertStringContainsString( 'All good', $response->get_data() );
	}

	public function test_create_ga_error_response(): void {
		$response = Response::create( true, 'Something failed', 500 );

		$this->assertStringContainsString( '::error::', $response->get_data() );
	}

	public function test_create_ga_warning_for_409(): void {
		$response = Response::create( true, 'Locked', 409 );

		$this->assertStringContainsString( '::warning::', $response->get_data() );
	}

	// ------------------------------------------------------------------
	// Error array
	// ------------------------------------------------------------------

	public function test_error_array(): void {
		$result = Response::error_array( 'boom' );

		$this->assertFalse( $result[ 'success' ] );
		$this->assertSame( 'boom', $result[ 'message' ] );
	}
}
