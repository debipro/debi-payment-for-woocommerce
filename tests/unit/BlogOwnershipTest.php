<?php
/**
 * Unit tests for deciding whether a Debi resource belongs to another site.
 *
 * @package Debi_Payment_For_WooCommerce
 */

declare( strict_types=1 );

namespace Tucuota\DebiPaymentForWooCommerce\Tests\Unit;

use DebiPro\Webhook\BlogOwnership;
use PHPUnit\Framework\TestCase;

/**
 * @covers \DebiPro\Webhook\BlogOwnership
 */
final class BlogOwnershipTest extends TestCase {

	public function test_subscription_stamp_wins_over_the_payment_copy(): void {
		$this->assertSame(
			7,
			BlogOwnership::stamped_blog_id( (object) array( 'blog_id' => '7' ), array( 'blog_id' => '3' ) )
		);
	}

	public function test_falls_back_to_the_payment_stamp(): void {
		$this->assertSame( 3, BlogOwnership::stamped_blog_id( (object) array(), (object) array( 'blog_id' => 3 ) ) );
		$this->assertSame( 3, BlogOwnership::stamped_blog_id( null, array( 'blog_id' => '3' ) ) );
	}

	public function test_no_stamp_is_zero(): void {
		$this->assertSame( 0, BlogOwnership::stamped_blog_id( null, null ) );
		$this->assertSame( 0, BlogOwnership::stamped_blog_id( array( 'blog_id' => '' ), array() ) );
	}

	public function test_only_a_different_stamped_site_is_foreign(): void {
		$this->assertTrue( BlogOwnership::is_foreign( 7, 3 ) );
		$this->assertFalse( BlogOwnership::is_foreign( 3, 3 ) );
		$this->assertFalse( BlogOwnership::is_foreign( 0, 3 ), 'Unstamped resources keep the previous behavior.' );
	}
}
