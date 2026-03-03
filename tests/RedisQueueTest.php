<?php
/**
 * Tests for the Redis_Queue class.
 *
 * Automatically skipped when the PHP Redis extension is not loaded or
 * Redis is unreachable, making them safe in CI without Redis.
 *
 * @package All_Sites_Cron
 */

namespace Soderlind\Multisite\AllSitesCron\Tests;

use Brain\Monkey\Functions;
use Soderlind\Multisite\AllSitesCron\Redis_Queue;

/**
 * @covers \Soderlind\Multisite\AllSitesCron\Redis_Queue
 */
class RedisQueueTest extends TestCase {

	private Redis_Queue $queue;

	private bool $redis_available = false;

	protected function setUp(): void {
		parent::setUp();

		// Reset the static availability cache between tests.
		$ref = new \ReflectionProperty( Redis_Queue::class, 'available_cache' );
		$ref->setAccessible( true );
		$ref->setValue( null, null );

		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$this->queue           = new Redis_Queue();
		$this->redis_available = $this->queue->is_available();

		if ( ! $this->redis_available ) {
			$this->markTestSkipped( 'Redis is not available.' );
		}

		// Drain the queue to ensure a clean state.
		while ( null !== $this->queue->pop() ) {
			// no-op
		}
	}

	protected function tearDown(): void {
		if ( $this->redis_available ) {
			while ( null !== $this->queue->pop() ) {
				// no-op
			}
		}
		parent::tearDown();
	}

	// ------------------------------------------------------------------
	// Push / Pop
	// ------------------------------------------------------------------

	public function test_push_and_pop_round_trip(): void {
		$ts = time();
		$this->assertTrue( $this->queue->push( $ts ) );

		$job = $this->queue->pop();
		$this->assertIsArray( $job );
		$this->assertSame( $ts, $job[ 'timestamp' ] );
		$this->assertSame( 0, $job[ 'retries' ] );
	}

	public function test_pop_returns_null_when_empty(): void {
		$this->assertNull( $this->queue->pop() );
	}

	// ------------------------------------------------------------------
	// Re-push / retry tracking
	// ------------------------------------------------------------------

	public function test_repush_increments_retry_counter(): void {
		$this->queue->push( time() );
		$job = $this->queue->pop();

		$this->assertTrue( $this->queue->repush( $job ) );

		$retried = $this->queue->pop();
		$this->assertSame( 1, $retried[ 'retries' ] );
	}

	public function test_repush_discards_after_max_retries(): void {
		$job = [
			'timestamp' => time(),
			'queued_at' => time(),
			'retries'   => 3, // default max is 3
		];

		$result = $this->queue->repush( $job );
		$this->assertFalse( $result );

		// Queue should be empty (job discarded).
		$this->assertNull( $this->queue->pop() );
	}

	// ------------------------------------------------------------------
	// Availability caching
	// ------------------------------------------------------------------

	public function test_is_available_returns_consistent_result(): void {
		$first  = $this->queue->is_available();
		$second = $this->queue->is_available();

		$this->assertSame( $first, $second );
	}
}
