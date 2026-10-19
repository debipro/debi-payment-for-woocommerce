<?php
/**
 * Unit tests for the local subscriptions mirror table.
 *
 * @package Debi_Payment_For_WooCommerce
 */

declare( strict_types=1 );

namespace Tucuota\DebiPaymentForWooCommerce\Tests\Unit;

use Debi\DebiObject;
use Debi\Resource\Subscription;
use DebiPro\Subscription\SubscriptionsTable;
use PHPUnit\Framework\TestCase;

/**
 * @covers \DebiPro\Subscription\SubscriptionsTable
 */
final class SubscriptionsTableTest extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	public function test_row_from_resource_reads_metadata_customer_and_amount(): void {
		$md             = new DebiObject();
		$md->blog_id    = '3';
		$md->product_id = '7';
		$md->wp_user_id = '99';

		$customer     = new DebiObject();
		$customer->id = 'CUST1';

		$sub           = new Subscription();
		$sub->id       = 'SB1';
		$sub->amount   = 1500.5;
		$sub->status   = 'active';
		$sub->metadata = $md;
		$sub->customer = $customer;

		$row = SubscriptionsTable::row_from_resource( $sub );

		$this->assertSame( 'SB1', $row['subscription_id'] );
		$this->assertSame( 3, $row['blog_id'] );
		$this->assertSame( 7, $row['product_id'] );
		$this->assertSame( 99, $row['wp_user_id'] );
		$this->assertSame( 'CUST1', $row['customer_id'] );
		$this->assertSame( 1500.5, $row['amount'] );
		$this->assertSame( 'active', $row['status'] );
	}

	public function test_row_from_resource_handles_missing_metadata(): void {
		$sub         = new Subscription();
		$sub->id     = 'SB2';
		$sub->status = 'cancelled';

		$row = SubscriptionsTable::row_from_resource( $sub );

		$this->assertSame( 'SB2', $row['subscription_id'] );
		$this->assertSame( 0, $row['blog_id'] );
		$this->assertSame( 0, $row['product_id'] );
		$this->assertSame( 0, $row['wp_user_id'] );
		$this->assertSame( '', $row['customer_id'] );
		$this->assertSame( 0.0, $row['amount'] );
		$this->assertSame( 'cancelled', $row['status'] );
	}

	public function test_upsert_inserts_when_absent(): void {
		$wpdb            = $this->fake_wpdb();
		$GLOBALS['wpdb'] = $wpdb; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test double.

		SubscriptionsTable::upsert(
			array(
				'subscription_id' => 'SB1',
				'blog_id'         => 3,
				'product_id'      => 7,
				'wp_user_id'      => 99,
				'customer_id'     => 'CUST1',
				'amount'          => 1500.0,
				'status'          => 'active',
			)
		);

		$this->assertCount( 1, $wpdb->inserted );
		$this->assertCount( 0, $wpdb->updated );
		$this->assertSame( 'SB1', $wpdb->inserted[0]['subscription_id'] );
		$this->assertSame( 7, $wpdb->inserted[0]['product_id'] );
		$this->assertArrayHasKey( 'created_at', $wpdb->inserted[0] );
	}

	public function test_upsert_updates_when_present_without_clobbering_identity(): void {
		$wpdb = $this->fake_wpdb();
		$wpdb->seed( 'SB1' );
		$GLOBALS['wpdb'] = $wpdb; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test double.

		// Status-only refresh (e.g. subscription.cancelled with no product metadata).
		SubscriptionsTable::upsert(
			array(
				'subscription_id' => 'SB1',
				'product_id'      => 0,
				'status'          => 'cancelled',
			)
		);

		$this->assertCount( 0, $wpdb->inserted );
		$this->assertCount( 1, $wpdb->updated );
		$data = $wpdb->updated[0][0];
		$this->assertSame( 'cancelled', $data['status'] );
		$this->assertArrayHasKey( 'updated_at', $data );
		// product_id 0 must not overwrite the value learned at checkout.
		$this->assertArrayNotHasKey( 'product_id', $data );
	}

	public function test_upsert_ignores_empty_id(): void {
		$wpdb            = $this->fake_wpdb();
		$GLOBALS['wpdb'] = $wpdb; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test double.

		SubscriptionsTable::upsert( array( 'subscription_id' => '' ) );

		$this->assertCount( 0, $wpdb->inserted );
		$this->assertCount( 0, $wpdb->updated );
	}

	private function fake_wpdb(): object {
		return new class() {
			public string $base_prefix = 'wp_';
			/** @var array<int,array<string,mixed>> */
			public array $inserted = array();
			/** @var array<int,array{0:array<string,mixed>,1:array<string,mixed>}> */
			public array $updated = array();
			/** @var array<string,bool> */
			private array $rows = array();

			public function seed( string $id ): void {
				$this->rows[ $id ] = true;
			}

			public function get_charset_collate(): string {
				return '';
			}

			/**
			 * @param mixed ...$args
			 * @return array{0:string,1:array<int,mixed>}
			 */
			public function prepare( string $query, ...$args ): array {
				return array( $query, $args );
			}

			/**
			 * @param array{0:string,1:array<int,mixed>} $prepared
			 */
			public function get_var( $prepared ) {
				$id = $prepared[1][0] ?? null;
				return isset( $this->rows[ $id ] ) ? 1 : null;
			}

			/**
			 * @param array<string,mixed> $data
			 */
			public function insert( string $table, array $data ): void {
				$this->inserted[]                       = $data;
				$this->rows[ $data['subscription_id'] ] = true;
			}

			/**
			 * @param array<string,mixed> $data
			 * @param array<string,mixed> $where
			 */
			public function update( string $table, array $data, array $where ): void {
				$this->updated[] = array( $data, $where );
			}
		};
	}
}
