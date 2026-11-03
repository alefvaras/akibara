<?php
/**
 * Contrato ejecutable de los botones − / + de cantidad.
 *
 * Invariante que fija: los botones se esconden SÓLO cuando de verdad no se
 * puede llevar más de uno (tomo vendido de a uno, o queda una sola unidad).
 *
 * Por qué existe: `get_max_purchase_quantity()` de WooCommerce devuelve **-1**
 * cuando NO hay tope —el producto no gestiona stock o admite encargo, que es
 * justo lo que son «a pedido» y «preventa»— y la condición original
 * (`$max <= 1`) metía ese -1 en el mismo saco que «queda uno». Resultado
 * medido el 2026-09-12 en el espejo: ficha a pedido con `input` sin `max` y
 * CERO botones, ficha con stock 2 con los dos. Son ~494 de 1.628 fichas
 * publicadas, y son las de serie, donde el cliente suele llevarse varios.
 *
 * @package akibara-gp\Tests
 */

declare( strict_types = 1 );

namespace {
	if ( ! class_exists( 'WC_Product' ) ) {
		/** Doble mínimo del producto de WooCommerce: el tema sólo comprueba el tipo. */
		class WC_Product {}
	}

	/** Producto de prueba con el tope y la venta unitaria que pida cada caso. */
	class Akb_Qty_Producto_Doble extends WC_Product {

		/** @var int|string|null */
		private $max;

		private bool $individual;

		/**
		 * @param int|string|null $max               Lo que devuelve get_max_purchase_quantity().
		 * @param bool            $sold_individually Si el tomo se vende de a uno.
		 */
		public function __construct( $max, bool $sold_individually = false ) {
			$this->max        = $max;
			$this->individual = $sold_individually;
		}

		/** @return int|string|null */
		public function get_max_purchase_quantity() {
			return $this->max;
		}

		public function is_sold_individually(): bool {
			return $this->individual;
		}
	}
}

namespace Akibara\GP\Tests {

	use Akb_Qty_Producto_Doble;
	use Brain\Monkey;
	use Brain\Monkey\Functions;
	use PHPUnit\Framework\TestCase;

	/**
	 * @coversNothing
	 */
	final class QuantityStepperTest extends TestCase {

		protected function setUp(): void {
			parent::setUp();
			Monkey\setUp();
			Functions\when( 'is_woocommerce' )->justReturn( true );
			require_once dirname( __DIR__ ) . '/inc/woocommerce.php';
		}

		protected function tearDown(): void {
			unset( $GLOBALS['product'] );
			Monkey\tearDown();
			parent::tearDown();
		}

		/**
		 * Deja en `$product` el doble con el tope que pide el caso.
		 *
		 * @param int|string|null $max               Tope de compra.
		 * @param bool            $sold_individually Vendido de a uno.
		 */
		private function producto( $max, bool $sold_individually = false ): void {
			$GLOBALS['product'] = new Akb_Qty_Producto_Doble( $max, $sold_individually );
		}

		/** @testdox Sin tope (-1), que es como se ve «a pedido» y «preventa», los botones se pintan. */
		public function test_sin_tope_se_pintan(): void {
			$this->producto( -1 );
			$this->assertTrue( akibara_gp_quantity_is_adjustable() );
		}

		/** @testdox Con stock de sobra los botones se pintan. */
		public function test_con_stock_de_sobra_se_pintan(): void {
			$this->producto( 5 );
			$this->assertTrue( akibara_gp_quantity_is_adjustable() );
		}

		/** @testdox Con una sola unidad no hay nada que subir: se esconden. */
		public function test_con_una_unidad_se_esconden(): void {
			$this->producto( 1 );
			$this->assertFalse( akibara_gp_quantity_is_adjustable() );
		}

		/** @testdox Agotado (0) se esconden. */
		public function test_agotado_se_esconden(): void {
			$this->producto( 0 );
			$this->assertFalse( akibara_gp_quantity_is_adjustable() );
		}

		/** @testdox Vendido de a uno se esconden, aunque no haya tope de stock. */
		public function test_vendido_de_a_uno_se_esconde_aunque_no_haya_tope(): void {
			$this->producto( -1, true );
			$this->assertFalse( akibara_gp_quantity_is_adjustable() );
		}

		/** @testdox Un tope que no es número (un filtro ajeno devolviendo basura) no esconde nada. */
		public function test_un_tope_que_no_es_numero_no_esconde_nada(): void {
			$this->producto( null );
			$this->assertTrue( akibara_gp_quantity_is_adjustable() );
		}

		/** @testdox Fuera de una ficha de producto el stepper sirve igual. */
		public function test_fuera_de_una_ficha_sirve_igual(): void {
			unset( $GLOBALS['product'] );
			$this->assertTrue( akibara_gp_quantity_is_adjustable() );
		}

		/** @testdox Los dos botones salen juntos o no sale ninguno. */
		public function test_los_dos_botones_van_juntos(): void {
			$this->producto( -1 );

			ob_start();
			akibara_gp_qty_minus_button();
			akibara_gp_qty_plus_button();
			$con_tope_libre = (string) ob_get_clean();

			$this->assertStringContainsString( 'akb-qty-btn--minus', $con_tope_libre );
			$this->assertStringContainsString( 'akb-qty-btn--plus', $con_tope_libre );

			$this->producto( 1 );

			ob_start();
			akibara_gp_qty_minus_button();
			akibara_gp_qty_plus_button();
			$con_una_unidad = (string) ob_get_clean();

			$this->assertSame( '', $con_una_unidad );
		}
	}
}
