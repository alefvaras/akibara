<?php
/**
 * Tests for the distributable plugin version.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests;

use PHPUnit\Framework\TestCase;

final class PluginVersionTest extends TestCase {

	/**
	 * @testdox Should keep the header and the runtime constant on the same release version.
	 */
	public function test_release_version_is_consistent(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/akibara-business.php' );

		self::assertIsString( $source, 'The main plugin file should be readable.' );
		self::assertSame( 1, preg_match( '/^\s*\*\s*Version:\s+([0-9.]+)\s*$/m', $source, $header ) );
		self::assertSame(
			1,
			preg_match( "/define\\( 'AKIBARA_BUSINESS_VERSION', '([0-9.]+)' \\);/", $source, $constant )
		);
		// Se fija la invariante (cabecera y constante en la MISMA versión con
		// forma semántica), no el número: clavar el literal obligaba a tocar
		// este test en cada release y lo dejaba en rojo sin defecto detrás.
		self::assertSame(
			1,
			preg_match( '/^\d+\.\d+\.\d+$/', $header[1] ),
			'The package header should carry a three-part release version.'
		);
		self::assertSame( $header[1], $constant[1], 'The runtime constant must match the package header.' );
		self::assertSame( 1, preg_match( '/^\s*\*\s*Requires PHP:\s+([0-9.]+)\s*$/m', $source, $php_header ) );
		self::assertSame(
			1,
			preg_match( "/define\\( 'AKIBARA_BUSINESS_MIN_PHP', '([0-9.]+)' \\);/", $source, $php_constant )
		);
		self::assertSame( '8.1', $php_header[1], 'Readonly properties require PHP 8.1 or newer.' );
		self::assertSame( $php_header[1], $php_constant[1], 'The activation guard must match the plugin header.' );
	}

	/**
	 * @testdox The declared WooCommerce minimum is actually enforced, not just documented.
	 */
	public function test_woocommerce_minimum_is_enforced(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/akibara-business.php' );

		self::assertIsString( $source );
		self::assertSame(
			1,
			preg_match( "/define\\( 'AKIBARA_BUSINESS_MIN_WC', '([0-9.]+)' \\);/", $source, $constant ),
			'The minimum WooCommerce version must stay declared as a constant.'
		);

		// El defecto que cubre este test (BIZ-16 de la auditoría 2026-07-29) era
		// que la constante existía y NADIE la leía: el único gate era
		// class_exists('WooCommerce'), así que el plugin arrancaba sobre una
		// versión no soportada sin un solo aviso. Se exige el uso real, no la
		// declaración.
		self::assertMatchesRegularExpression(
			'/version_compare\(\s*WC_VERSION\s*,\s*AKIBARA_BUSINESS_MIN_WC\s*,\s*[\'"]<[\'"]\s*\)/',
			$source,
			'AKIBARA_BUSINESS_MIN_WC debe compararse contra WC_VERSION en el bootstrap.'
		);
	}
}
