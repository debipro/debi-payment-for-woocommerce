<?php
/**
 * Unit tests for the admin list-screen query helpers on the subscriptions mirror.
 *
 * @package Debi_Payment_For_WooCommerce
 */

declare( strict_types=1 );

namespace Tucuota\DebiPaymentForWooCommerce\Tests\Unit;

use DebiPro\Subscription\SubscriptionsTable;
use PHPUnit\Framework\TestCase;

/**
 * @covers \DebiPro\Subscription\SubscriptionsTable
 */
final class SubscriptionsListQueriesTest extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	public function test_count_rows_without_status_has_no_where(): void {
		$wpdb            = $this->fake_wpdb( array( 'var' => 7 ) );
		$GLOBALS['wpdb'] = $wpdb; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test double.

		$count = SubscriptionsTable::count_rows();

		$this->assertSame( 7, $count );
		$this->assertCount( 0, $wpdb->prepared, 'No-status count must not go through prepare().' );
		$this->assertStringContainsString( 'SELECT COUNT(*)', $wpdb->last_var_query );
		$this->assertStringNotContainsString( 'WHERE', $wpdb->last_var_query );
	}

	public function test_count_rows_with_status_filters_and_binds(): void {
		$wpdb            = $this->fake_wpdb( array( 'var' => 3 ) );
		$GLOBALS['wpdb'] = $wpdb; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test double.

		$count = SubscriptionsTable::count_rows( 'active' );

		$this->assertSame( 3, $count );
		$this->assertCount( 1, $wpdb->prepared );
		$this->assertStringContainsString( 'WHERE status = %s', $wpdb->prepared[0]['query'] );
		$this->assertSame( array( 'active' ), $wpdb->prepared[0]['args'] );
	}

	public function test_paged_rows_binds_status_limit_and_offset_in_order(): void {
		$wpdb            = $this->fake_wpdb( array( 'rows' => array( array( 'subscription_id' => 'SB1' ) ) ) );
		$GLOBALS['wpdb'] = $wpdb; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test double.

		$rows = SubscriptionsTable::paged_rows( 20, 40, 'active', 'amount', 'ASC' );

		$this->assertSame( 'SB1', $rows[0]['subscription_id'] );
		$query = $wpdb->prepared[0]['query'];
		$this->assertStringContainsString( 'WHERE status = %s', $query );
		$this->assertStringContainsString( 'ORDER BY amount ASC', $query );
		$this->assertStringContainsString( 'LIMIT %d OFFSET %d', $query );
		// Status first, then limit, then offset.
		$this->assertSame( array( 'active', 20, 40 ), $wpdb->prepared[0]['args'] );
	}

	public function test_paged_rows_whitelists_orderby_and_order(): void {
		$wpdb            = $this->fake_wpdb( array( 'rows' => array() ) );
		$GLOBALS['wpdb'] = $wpdb; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test double.

		// Malicious orderby + bogus order must fall back to safe defaults.
		SubscriptionsTable::paged_rows( 20, 0, '', 'amount; DROP TABLE x', 'sideways' );

		$query = $wpdb->prepared[0]['query'];
		$this->assertStringContainsString( 'ORDER BY updated_at DESC', $query );
		$this->assertStringNotContainsString( 'DROP TABLE', $query );
		// No status → only limit + offset are bound.
		$this->assertSame( array( 20, 0 ), $wpdb->prepared[0]['args'] );
	}

	public function test_paged_rows_clamps_per_page_and_offset(): void {
		$wpdb            = $this->fake_wpdb( array( 'rows' => array() ) );
		$GLOBALS['wpdb'] = $wpdb; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test double.

		SubscriptionsTable::paged_rows( 10000, -5 );

		$this->assertSame( array( 200, 0 ), $wpdb->prepared[0]['args'] );
	}

	public function test_status_counts_maps_rows(): void {
		$wpdb            = $this->fake_wpdb(
			array(
				'rows' => array(
					array(
						'status' => 'active',
						'c'      => '5',
					),
					array(
						'status' => 'cancelled',
						'c'      => '2',
					),
				),
			)
		);
		$GLOBALS['wpdb'] = $wpdb; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test double.

		$counts = SubscriptionsTable::status_counts();

		$this->assertSame(
			array(
				'active'    => 5,
				'cancelled' => 2,
			),
			$counts
		);
	}

	private function fake_wpdb( array $results = array() ): object {
		return new class( $results ) {
			public string $prefix = 'wp_';
			/** @var array<int,array{query:string,args:array<int,mixed>}> */
			public array $prepared        = array();
			public string $last_var_query = '';
			/** @var array<string,mixed> */
			private array $results;

			/**
			 * @param array<string,mixed> $results
			 */
			public function __construct( array $results ) {
				$this->results = $results;
			}

			/**
			 * @param mixed ...$args
			 * @return string
			 */
			public function prepare( string $query, ...$args ): string {
				// WP accepts a single array of values too.
				if ( 1 === count( $args ) && is_array( $args[0] ) ) {
					$args = $args[0];
				}
				$this->prepared[] = array(
					'query' => $query,
					'args'  => array_values( $args ),
				);
				return $query;
			}

			/**
			 * @param string $query
			 * @return mixed
			 */
			public function get_var( $query ) {
				$this->last_var_query = (string) $query;
				return $this->results['var'] ?? 0;
			}

			/**
			 * @param string $query
			 * @param mixed  $output
			 * @return array<int,array<string,mixed>>
			 */
			public function get_results( $query, $output = null ) {
				return $this->results['rows'] ?? array();
			}
		};
	}
}
