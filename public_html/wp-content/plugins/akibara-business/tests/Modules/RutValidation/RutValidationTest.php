<?php
/**
 * Tests for historical RUT display outside Checkout Blocks.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\RutValidation;

use Akibara\Business\Modules\RutValidation\RutValidation;
use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class RutValidationTest extends TestCase {

	/**
	 * Loads isolated WooCommerce presentation fixtures.
	 */
	protected function setUp(): void {
		parent::setUp();

		if ( ! class_exists( '\WC_Order' ) ) {
			eval(
				'namespace { class WC_Order {
					private array $meta;
					public function __construct( array $meta = array() ) { $this->meta = $meta; }
					public function get_meta( string $key ): string { return $this->meta[ $key ] ?? ""; }
				} }'
			);
		}

		if ( ! function_exists( 'esc_html' ) ) {
			eval(
				'namespace { function esc_html( string $text ): string {
					return htmlspecialchars( $text, ENT_QUOTES | ENT_HTML5, "UTF-8" );
				} }'
			);
		}
	}

	/**
	 * @testdox Should not duplicate the RUT in admin when WooCommerce renders the native field.
	 */
	public function test_admin_skips_legacy_rut_when_native_meta_exists(): void {
		$sut   = new RutValidation();
		$order = new \WC_Order(
			array(
				'_wc_other/akibara/rut' => '12.345.678-5',
				'_billing_rut'          => '12.345.678-5',
			)
		);

		ob_start();
		$sut->display_in_admin_order( $order );
		$output = (string) ob_get_clean();

		self::assertSame( '', $output, 'WooCommerce already owns the native additional-field display.' );
	}

	/**
	 * @testdox Should retain legacy RUT display for historical orders.
	 */
	public function test_admin_keeps_legacy_rut_for_historical_orders(): void {
		$sut   = new RutValidation();
		$order = new \WC_Order( array( '_billing_rut' => '12.345.678-5' ) );

		ob_start();
		$sut->display_in_admin_order( $order );
		$output = (string) ob_get_clean();

		self::assertStringContainsString( '<strong>RUT:</strong> 12.345.678-5', $output );
	}

	/**
	 * R2-d7f6773b: el docblock de cabecera dice que este módulo es sólo
	 * «compatibilidad para pedidos históricos», pero `add_to_email()` no tiene
	 * el guard contra el meta nativo que sí tiene `display_in_admin_order()`
	 * (línea 50). El campo nativo se registra con `show_in_order_confirmation
	 * => false` (CheckoutFields.php), así que HOY es la única vía que muestra el
	 * RUT en el correo de confirmación, para CUALQUIER pedido, viejo o nuevo.
	 *
	 * @testdox Should show the RUT in the order email even when the native field also wrote its meta.
	 */
	public function test_add_to_email_muestra_rut_aunque_exista_el_campo_nativo(): void {
		$sut   = new RutValidation();
		$order = new \WC_Order(
			array(
				'_wc_other/akibara/rut' => '12.345.678-5',
				'_billing_rut'          => '12.345.678-5',
			)
		);

		$fields = $sut->add_to_email( array(), false, $order );

		self::assertArrayHasKey( 'billing_rut', $fields );
		self::assertSame( '12.345.678-5', $fields['billing_rut']['value'] );
	}

	/**
	 * Mismo razonamiento que el test de arriba, para Mi cuenta → Ver pedido:
	 * `CheckoutFieldsFrontend::render_order_other_fields` respeta
	 * `show_in_order_confirmation => false` y no pinta el campo nativo ahí, así
	 * que `display_in_my_account()` es hoy la única superficie, sin excepción
	 * de pedido histórico.
	 *
	 * @testdox Should show the RUT in My Account → Order details even when the native field also wrote its meta.
	 */
	public function test_display_in_my_account_muestra_rut_aunque_exista_el_campo_nativo(): void {
		$sut   = new RutValidation();
		$order = new \WC_Order(
			array(
				'_wc_other/akibara/rut' => '12.345.678-5',
				'_billing_rut'          => '12.345.678-5',
			)
		);

		ob_start();
		$sut->display_in_my_account( 'billing', $order );
		$output = (string) ob_get_clean();

		self::assertStringContainsString( '12.345.678-5', $output );
	}
}
