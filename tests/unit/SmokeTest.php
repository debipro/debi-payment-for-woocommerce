<?php
/**
 * Smoke tests.
 *
 * Minimal sanity checks asserting that the plugin's entry files are present
 * and parseable. Real unit/integration tests will be added alongside the
 * plugin rewrite. The point of this file is to prove the test runner works.
 *
 * @package Debi_Payment_For_WooCommerce
 */

declare( strict_types=1 );

namespace Tucuota\DebiPaymentForWooCommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Smoke tests for the plugin scaffolding.
 */
final class SmokeTest extends TestCase {

	/**
	 * The plugin entry file exists.
	 */
	public function test_plugin_entry_file_exists(): void {
		$this->assertFileExists( DEBI_PLUGIN_FILE );
	}

	/**
	 * The plugin entry file declares a WordPress plugin header.
	 */
	public function test_plugin_header_present(): void {
		$contents = file_get_contents( DEBI_PLUGIN_FILE );
		$this->assertIsString( $contents );
		$this->assertStringContainsString( 'Plugin Name:', $contents );
		$this->assertStringContainsString( 'Debi Payment for WooCommerce', $contents );
	}

	/**
	 * The fixtures file ships with the workbench.
	 */
	public function test_fixtures_file_is_valid_json(): void {
		$path = DEBI_PLUGIN_DIR . '/bin/fixtures.json';
		$this->assertFileExists( $path );
		$decoded = json_decode( (string) file_get_contents( $path ), true );
		$this->assertIsArray( $decoded );
		$this->assertArrayHasKey( 'products', $decoded );
		$this->assertArrayHasKey( 'customer', $decoded );
		$this->assertArrayHasKey( 'gateway', $decoded );
	}
}
