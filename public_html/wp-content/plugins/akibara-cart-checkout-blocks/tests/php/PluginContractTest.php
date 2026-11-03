<?php
/**
 * Tests for the dedicated Blocks plugin release contract.
 *
 * @package Akibara\CartCheckoutBlocks\Tests
 */

declare( strict_types = 1 );

namespace Akibara\CartCheckoutBlocks\Tests;

use Akibara\CartCheckoutBlocks\CheckoutBlockIntegration;
use Akibara\CartCheckoutBlocks\StoreApiIntegration;
use PHPUnit\Framework\TestCase;

final class PluginContractTest extends TestCase {

	/**
	 * @testdox Should keep the client bundles reading the namespace and schema the server publishes.
	 *
	 * Los dos bundles descartan el payload del carrito cuando el namespace o la
	 * versión de esquema no coinciden, y lo hacen en silencio: la superficie
	 * queda muda sin un solo error. Es la familia del defecto
	 * `showPedidoPolicy` ≠ `showPreventaPolicy`, donde el servidor emitía una
	 * clave y el cliente leía otra durante meses.
	 */
	public function test_client_bundles_agree_with_the_store_api_contract(): void {
		foreach (
			array(
				'assets/js/cart-checkout-blocks.js',
				'assets/js/checkout-inner-blocks-frontend.js',
			) as $relative
		) {
			$bundle = file_get_contents( dirname( __DIR__, 2 ) . '/' . $relative );

			self::assertIsString( $bundle );
			self::assertStringContainsString(
				"'" . StoreApiIntegration::NAMESPACE . "'",
				$bundle,
				"{$relative} dejó de nombrar el namespace del Store API: descartaría todo el payload."
			);
			self::assertSame(
				1,
				preg_match( '/schema_version\)\s*===\s*(\d+)/', $bundle, $schema ),
				"{$relative} tiene que comparar la versión del esquema."
			);
			self::assertSame(
				StoreApiIntegration::SCHEMA_VERSION,
				(int) $schema[1],
				"{$relative} compara contra otra versión de esquema que la que el servidor emite."
			);
		}
	}

	/**
	 * @testdox Should not print integration script data no client reads.
	 *
	 * WooCommerce imprime `get_script_data()` como settings del bundle en cada
	 * checkout de bloques. Emitía `namespace` y `schemaVersion` sin un solo
	 * consumidor: los bundles los comparan contra sus propios literales, que es
	 * lo que fija la prueba de arriba.
	 */
	public function test_checkout_integration_publishes_no_unread_script_data(): void {
		// Se carga la interfaz REAL de WooCommerce, no un doble: si el
		// proveedor cambia el contrato, esto tiene que romperse aquí y no en un
		// checkout de producción.
		$vendor = dirname( __DIR__, 3 ) . '/woocommerce/src/Blocks/Integrations/IntegrationInterface.php';
		self::assertFileExists( $vendor, 'WooCommerce movió IntegrationInterface: relee la integración antes de confiar en ella.' );
		require_once $vendor;

		self::assertSame( array(), ( new CheckoutBlockIntegration() )->get_script_data() );
	}

	/**
	 * @testdox Should keep header and runtime version in sync and declare both hard dependencies.
	 */
	public function test_version_and_dependencies_are_explicit(): void {
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/akibara-cart-checkout-blocks.php' );

		self::assertIsString( $source, 'The main Blocks plugin file should be readable.' );
		self::assertSame( 1, preg_match( '/^\s*\*\s*Version:\s+([0-9.]+)\s*$/m', $source, $header ) );
		self::assertSame(
			1,
			preg_match( "/define\\( 'AKIBARA_CART_CHECKOUT_BLOCKS_VERSION', '([0-9.]+)' \\);/", $source, $constant )
		);
		// El contrato es que la cabecera y la constante NO se separen, no que la
		// versión sea una en concreto: fijar el literal convertía cada release en
		// un test rojo que no señalaba ningún defecto real.
		self::assertSame(
			1,
			preg_match( '/^\d+\.\d+\.\d+$/', $header[1] ),
			'The plugin header must carry a three-part semantic version.'
		);
		self::assertSame( $header[1], $constant[1], 'The runtime constant must match the plugin header.' );
		self::assertSame(
			1,
			preg_match( '/^\s*\*\s*Requires Plugins:\s+woocommerce,\s*akibara-business\s*$/m', $source ),
			'WordPress must prevent activation without both WooCommerce and Akibara Business.'
		);
		self::assertSame(
			1,
			preg_match( '/^\s*\*\s*Requires PHP:\s+8\.1\s*$/m', $source ),
			'The Blocks adapter must not claim support below its mandatory Business dependency.'
		);
	}

	/**
	 * @testdox Should not compensate for first-party classic checkout assets that no longer exist.
	 */
	public function test_first_party_classic_checkout_dequeues_are_removed(): void {
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/src/RuntimeCompatibility.php' );

		self::assertIsString( $source );
		foreach (
			array(
				'akibara-phone',
				'akibara-rut',
				'akb-checkout-autofill',
				'akibara-address-autocomplete',
				'akibara-checkout-pudo',
				'akb-series-bundles',
				'akb-estimated-delivery',
			) as $handle
		) {
			self::assertStringNotContainsString( "'{$handle}'", $source );
		}
		self::assertStringNotContainsString( 'dequeue_classic_checkout_assets', $source );
		self::assertStringContainsString( 'dequeue_classic_mercado_pago_assets', $source );
		self::assertStringNotContainsString(
			"wp_dequeue_style( 'akb-fsp' )",
			$source,
			'The free-shipping stylesheet remains required by the global NativeCart drawer.'
		);
	}
}
