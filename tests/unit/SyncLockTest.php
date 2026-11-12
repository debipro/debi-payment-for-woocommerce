<?php
/**
 * @package Debi_Payment_For_WooCommerce
 */

declare( strict_types=1 );

namespace Tucuota\DebiPaymentForWooCommerce\Tests\Unit;

use DebiPro\Webhook\SyncLock;
use PHPUnit\Framework\TestCase;

/**
 * @covers \DebiPro\Webhook\SyncLock
 */
final class SyncLockTest extends TestCase {

	/** @var mixed */
	private $previous_wpdb;

	protected function setUp(): void {
		global $wpdb;
		$this->previous_wpdb = $wpdb ?? null;
	}

	protected function tearDown(): void {
		global $wpdb;
		$wpdb = $this->previous_wpdb;
	}

	public function test_runs_callback_while_holding_the_lock_and_releases_it(): void {
		$db = $this->fake_wpdb( '1' );

		$result = ( new SyncLock( 'debipro_sync_x' ) )->run(
			static function () use ( $db ): string {
				return $db->held ? 'ran_locked' : 'ran_unlocked';
			}
		);

		$this->assertSame( 'ran_locked', $result );
		$this->assertFalse( $db->held, 'lock released after the callback' );
	}

	public function test_returns_null_without_running_when_the_lock_is_busy(): void {
		$this->fake_wpdb( '0' );
		$ran = false;

		$result = ( new SyncLock( 'debipro_sync_x' ) )->run(
			static function () use ( &$ran ): string {
				$ran = true;
				return 'projected_paid';
			}
		);

		$this->assertNull( $result );
		$this->assertFalse( $ran, 'a busy lock must not route the payment unguarded' );
	}

	public function test_releases_the_lock_when_the_callback_throws(): void {
		$db = $this->fake_wpdb( '1' );

		try {
			( new SyncLock( 'debipro_sync_x' ) )->run(
				static function (): string {
					throw new \RuntimeException( 'boom' );
				}
			);
			$this->fail( 'exception should propagate' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'boom', $e->getMessage() );
		}

		$this->assertFalse( $db->held );
	}

	public function test_lock_name_is_scoped_per_site_and_fits_mysql_limit(): void {
		global $wpdb;
		$wpdb         = $this->fake_wpdb( '1' );
		$wpdb->prefix = 'wp_3_';
		$site_three   = SyncLock::for_current_blog()->name();
		$wpdb->prefix = 'wp_10_';
		$site_ten     = SyncLock::for_current_blog()->name();

		$this->assertNotSame( $site_three, $site_ten );
		$this->assertLessThanOrEqual( 64, strlen( $site_three ) );
	}

	private function fake_wpdb( string $get_lock_result ): object {
		global $wpdb;
		$wpdb = new class( $get_lock_result ) {
			public string $prefix = 'wp_';
			public bool $held     = false;
			private string $get_lock_result;

			public function __construct( string $get_lock_result ) {
				$this->get_lock_result = $get_lock_result;
			}

			public function prepare( string $query, ...$args ): string {
				return $query;
			}

			public function get_var( string $query ): string {
				if ( 0 === strpos( $query, 'SELECT GET_LOCK' ) && '1' === $this->get_lock_result ) {
					$this->held = true;
				}
				return $this->get_lock_result;
			}

			public function query( string $query ): int {
				if ( 0 === strpos( $query, 'SELECT RELEASE_LOCK' ) ) {
					$this->held = false;
				}
				return 1;
			}
		};
		return $wpdb;
	}
}
