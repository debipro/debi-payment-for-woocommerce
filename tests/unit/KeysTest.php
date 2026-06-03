<?php
/**
 * Unit tests for DEBIPRO_Keys (environment inference + key-pair validation).
 *
 * These are pure helpers with no WooCommerce dependency, so they run under the
 * lightweight Brain Monkey harness without bootstrapping WordPress.
 *
 * @package Debi_Payment_For_WooCommerce
 */

declare( strict_types=1 );

namespace Tucuota\DebiPaymentForWooCommerce\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

if ( ! defined( 'WPINC' ) ) {
	define( 'WPINC', 'cli-test' );
}
require_once DEBI_PLUGIN_DIR . '/includes/class-debipro-keys.php';

/**
 * Covers key environment/kind inference and pair validation.
 */
final class KeysTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// validate_pair() wraps messages in __(); return the raw string.
		Functions\when( '__' )->returnArg( 1 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_environment_is_inferred_from_prefix(): void {
		$this->assertSame( 'test', \DEBIPRO_Keys::environment( 'sk_test_abc' ) );
		$this->assertSame( 'test', \DEBIPRO_Keys::environment( 'pk_test_abc' ) );
		$this->assertSame( 'live', \DEBIPRO_Keys::environment( 'sk_live_abc' ) );
		$this->assertSame( 'live', \DEBIPRO_Keys::environment( 'pk_live_abc' ) );
	}

	public function test_environment_is_empty_for_unknown_or_blank(): void {
		$this->assertSame( '', \DEBIPRO_Keys::environment( '' ) );
		$this->assertSame( '', \DEBIPRO_Keys::environment( 'legacy-token-without-prefix' ) );
		$this->assertSame( '', \DEBIPRO_Keys::environment( 'sk_unknown_abc' ) );
	}

	public function test_kind_distinguishes_secret_from_publishable(): void {
		$this->assertSame( 'secret', \DEBIPRO_Keys::kind( 'sk_test_abc' ) );
		$this->assertSame( 'secret', \DEBIPRO_Keys::kind( 'sk_live_abc' ) );
		$this->assertSame( 'publishable', \DEBIPRO_Keys::kind( 'pk_test_abc' ) );
		$this->assertSame( 'publishable', \DEBIPRO_Keys::kind( 'pk_live_abc' ) );
		$this->assertSame( '', \DEBIPRO_Keys::kind( 'nope' ) );
	}

	public function test_validate_pair_allows_empty_pair(): void {
		$this->assertNull( \DEBIPRO_Keys::validate_pair( '', '' ) );
	}

	public function test_validate_pair_allows_matching_environments(): void {
		$this->assertNull( \DEBIPRO_Keys::validate_pair( 'sk_test_abc', 'pk_test_xyz' ) );
		$this->assertNull( \DEBIPRO_Keys::validate_pair( 'sk_live_abc', 'pk_live_xyz' ) );
	}

	public function test_validate_pair_rejects_publishable_in_secret_field(): void {
		$this->assertNotNull( \DEBIPRO_Keys::validate_pair( 'pk_test_abc', 'pk_test_xyz' ) );
	}

	public function test_validate_pair_rejects_secret_in_publishable_field(): void {
		$this->assertNotNull( \DEBIPRO_Keys::validate_pair( 'sk_test_abc', 'sk_test_xyz' ) );
	}

	public function test_validate_pair_rejects_environment_mismatch(): void {
		$this->assertNotNull( \DEBIPRO_Keys::validate_pair( 'sk_test_abc', 'pk_live_xyz' ) );
		$this->assertNotNull( \DEBIPRO_Keys::validate_pair( 'sk_live_abc', 'pk_test_xyz' ) );
	}

	public function test_validate_pair_rejects_unprefixed_secret(): void {
		$this->assertNotNull( \DEBIPRO_Keys::validate_pair( 'legacy-token', '' ) );
	}
}
