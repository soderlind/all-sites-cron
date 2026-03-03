<?php
/**
 * Tests for the Lock class.
 *
 * Exercises the object-cache path (wp_cache_add) via Brain Monkey stubs.
 *
 * @package All_Sites_Cron
 */

namespace Soderlind\Multisite\AllSitesCron\Tests;

use Brain\Monkey\Functions;
use Soderlind\Multisite\AllSitesCron\Lock;

/**
 * @covers \Soderlind\Multisite\AllSitesCron\Lock
 */
class LockTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		// Lock constructor & release() always call these.
		Functions\when( 'wp_cache_add_global_groups' )->justReturn( null );
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( true );
	}

	// ------------------------------------------------------------------
	// Basic acquire / release
	// ------------------------------------------------------------------

	public function test_acquire_returns_true_when_unlocked(): void {
		Functions\expect( 'wp_cache_add' )
			->once()
			->andReturn( true );

		$lock = new Lock();
		$this->assertTrue( $lock->acquire() );
	}

	public function test_acquire_returns_wp_error_when_already_locked(): void {
		// wp_cache_add fails (key exists), cache holds a recent timestamp.
		Functions\expect( 'wp_cache_add' )
			->once()
			->andReturn( false );
		Functions\expect( 'wp_cache_get' )
			->once()
			->andReturn( time() );

		$lock   = new Lock();
		$second = $lock->acquire();

		$this->assertWPError( $second );
		$this->assertSame( 'all_sites_cron_locked', $second->get_error_code() );
	}

	public function test_release_allows_reacquire(): void {
		Functions\expect( 'wp_cache_add' )
			->twice()
			->andReturn( true, true );
		Functions\expect( 'wp_cache_delete' )
			->once()
			->andReturn( true );

		$lock = new Lock();
		$lock->acquire();
		$lock->release();

		$this->assertTrue( $lock->acquire() );
	}

	// ------------------------------------------------------------------
	// Stale lock detection
	// ------------------------------------------------------------------

	public function test_stale_lock_is_overridden(): void {
		// First wp_cache_add fails (key exists), second succeeds after delete.
		Functions\expect( 'wp_cache_add' )
			->twice()
			->andReturn( false, true );
		Functions\expect( 'wp_cache_get' )
			->once()
			->andReturn( time() - 600 ); // 10 min ago — stale for a 1-second lock.
		Functions\expect( 'wp_cache_delete' )
			->once()
			->andReturn( true );

		$lock = new Lock( 1 );
		$this->assertTrue( $lock->acquire() );
	}

	// ------------------------------------------------------------------
	// WP_Error data
	// ------------------------------------------------------------------

	public function test_locked_error_contains_409_status(): void {
		Functions\expect( 'wp_cache_add' )
			->once()
			->andReturn( false );
		Functions\expect( 'wp_cache_get' )
			->once()
			->andReturn( time() );

		$lock  = new Lock();
		$error = $lock->acquire();

		$this->assertWPError( $error );
		$data = $error->get_error_data();
		$this->assertArrayHasKey( 'status', $data );
		$this->assertSame( 409, $data[ 'status' ] );
	}
}
