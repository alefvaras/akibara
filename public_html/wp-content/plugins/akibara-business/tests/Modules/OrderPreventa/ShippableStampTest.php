<?php
/**
 * Contrato del sello de «despachable».
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\OrderPreventa;

use Akibara\Business\Modules\OrderPreventa\MetaKeys;
use Akibara\Business\Modules\OrderPreventa\ShippableStamp;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * @since 1.108.22
 */
final class ShippableStampTest extends TestCase {

	private const AHORA = 1787000000;

	private ShippableStamp $sut;

	/** @var array<string, mixed> */
	private array $opciones = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->sut = new ShippableStamp( static fn(): int => self::AHORA );

		Functions\when( 'sanitize_key' )->alias(
			static fn( string $v ): string => strtolower( (string) preg_replace( '/[^a-zA-Z0-9_\-]/', '', $v ) )
		);
		Functions\when( 'get_option' )->alias(
			fn( string $option, $default_value = false ) => $this->opciones[ $option ] ?? $default_value
		);
		// `function_exists()` miente en la suite (Patchwork): hay que doblarla.
		Functions\when( 'wc_get_order_status_name' )->alias( static fn( string $s ): string => 'shipping-progress' === $s ? 'Listo para enviar' : $s );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Pedido doble sobre el stub del proyecto.
	 *
	 * Se extiende el stub en vez de reimplementarlo: un doble propio con firmas
	 * distintas ya nos costó caro antes ({@see PublicationProtocolTest}), porque
	 * pasa en verde una ruta que en producción no existe.
	 *
	 * @param array<string,mixed> $meta
	 */
	private function pedido( array $meta ): \WC_Order {
		$order            = new class() extends \WC_Order {
			public int $saves = 0;

			public function save(): int {
				++$this->saves;
				return 1;
			}

			public function save_meta_data() {
				++$this->saves;
			}
		};
		$order->akb_meta = $meta;

		return $order;
	}

	/**
	 * @testdox Sella el primer cruce a un estado despachable.
	 */
	public function test_it_stamps_the_first_shippable_transition(): void {
		$order = $this->pedido( array( MetaKeys::ORDER_HAS_PREVENTA => 'yes' ) );

		$this->sut->stamp( 10, 'processing', 'shipping-progress', $order );

		self::assertSame( self::AHORA, $order->akb_meta[ MetaKeys::ORDER_SHIPPABLE_AT ] );
		self::assertSame( 1, $order->saves );
	}

	/**
	 * @testdox Cruzar al transportista con tomos de preventa sin llegar se sella igual, pero queda dicho en el pedido.
	 */
	public function test_it_says_when_tomos_are_still_missing(): void {
		$item                 = new \WC_Order_Item_Product();
		$item->akb_id         = 3;
		$item->akb_product_id = 501;
		$item->akb_quantity   = 2;
		$item->akb_meta       = array( MetaKeys::ITEM_WAS_PREVENTA => 'yes' );
		$order                = $this->pedido( array( MetaKeys::ORDER_HAS_PREVENTA => 'yes' ) );
		$order->akb_items     = array( $item );
		$this->sut->stamp( 10, 'processing', 'shipping-progress', $order );
		self::assertSame( self::AHORA, $order->akb_meta[ MetaKeys::ORDER_SHIPPABLE_AT ], 'El sello es una decisión del dueño: se conserva.' );
		self::assertCount( 1, $order->akb_notes );
		self::assertStringContainsString( '2 tomos de preventa sin llegar', $order->akb_notes[0], 'Se cuentan unidades, no líneas.' );

		$listo = $this->pedido( array( MetaKeys::ORDER_HAS_PREVENTA => 'yes' ) );
		$this->sut->stamp( 11, 'processing', 'shipping-progress', $listo );
		self::assertSame( array(), $listo->akb_notes, 'Sin tomos pendientes no hay nada que advertir.' );
	}

	/**
	 * @testdox Ir y volver de estado NO reescribe el primer sello.
	 *
	 * Reescribirlo borraría justamente el atraso que se quiere medir: un pedido
	 * que se despacha, se devuelve a «procesando» y se vuelve a despachar
	 * quedaría contado como si hubiera llegado en la segunda fecha.
	 */
	public function test_a_round_trip_never_rewrites_the_first_stamp(): void {
		$order = $this->pedido(
			array(
				MetaKeys::ORDER_HAS_PREVENTA  => 'yes',
				MetaKeys::ORDER_SHIPPABLE_AT  => 1786000000,
			)
		);

		$this->sut->stamp( 10, 'processing', 'shipping-progress', $order );

		self::assertSame( 1786000000, $order->akb_meta[ MetaKeys::ORDER_SHIPPABLE_AT ] );
		self::assertSame( 0, $order->saves, 'Sin cambio no hay escritura: no se paga un save por transición.' );
	}

	/**
	 * @testdox «Procesando» no es despachable: ahí la mercadería puede no existir.
	 *
	 * Es el tramo que el informe mide. Sellarlo acá haría que toda preventa
	 * pareciera cumplida el día del pago.
	 */
	public function test_processing_is_not_shippable(): void {
		foreach ( array( 'processing', 'on-hold', 'pending', 'cancelled', 'refunded' ) as $estado ) {
			$order = $this->pedido( array( MetaKeys::ORDER_HAS_PREVENTA => 'yes' ) );

			$this->sut->stamp( 10, 'pending', $estado, $order );

			self::assertArrayNotHasKey( MetaKeys::ORDER_SHIPPABLE_AT, $order->akb_meta, "«{$estado}» no puede sellar." );
			self::assertSame( 0, $order->saves );
		}
	}

	/**
	 * @testdox Sólo se sellan pedidos con preventa.
	 *
	 * El hook corre en CADA cambio de estado de la tienda; sin este corte se
	 * escribiría una meta en cada pedido para un informe que no los mira.
	 */
	public function test_only_preorder_orders_get_stamped(): void {
		foreach ( array( 'no', '', 'unknown' ) as $marca ) {
			$order = $this->pedido( array( MetaKeys::ORDER_HAS_PREVENTA => $marca ) );

			$this->sut->stamp( 10, 'processing', 'completed', $order );

			self::assertArrayNotHasKey( MetaKeys::ORDER_SHIPPABLE_AT, $order->akb_meta );
			self::assertSame( 0, $order->saves );
		}
	}

	/**
	 * @testdox Sin pedido utilizable no revienta ni escribe.
	 */
	public function test_it_survives_a_missing_order(): void {
		Functions\when( 'wc_get_order' )->justReturn( false );

		$this->sut->stamp( 10, 'processing', 'completed', null );

		self::assertTrue( true, 'No lanzó.' );
	}

	/**
	 * @testdox Sella el estado de BlueExpress que el dueño tenga configurado.
	 *
	 * `noBlueStatus` es un campo editable de su pantalla de integración. Fijarlo a
	 * mano significaba que el día que lo cambiara, el sello dejaría de escribirse
	 * EN SILENCIO y el informe de cumplimiento volvería a la señal mala del cron
	 * sin que nada avisara.
	 */
	public function test_it_honours_the_configured_handoff_status(): void {
		$this->opciones['woocommerce_correios_settings'] = array( 'noBlueStatus' => 'wc-en-reparto' );

		$order = $this->pedido( array( MetaKeys::ORDER_HAS_PREVENTA => 'yes' ) );
		$this->sut->stamp( 10, 'processing', 'en-reparto', $order );
		self::assertSame( self::AHORA, $order->akb_meta[ MetaKeys::ORDER_SHIPPABLE_AT ] );

		// Y el estado por defecto sigue contando: los pedidos que ya pasaron por él
		// no dejan de haber sido despachados.
		$viejo = $this->pedido( array( MetaKeys::ORDER_HAS_PREVENTA => 'yes' ) );
		$this->sut->stamp( 11, 'processing', 'shipping-progress', $viejo );
		self::assertSame( self::AHORA, $viejo->akb_meta[ MetaKeys::ORDER_SHIPPABLE_AT ] );
	}

	/**
	 * @testdox «completed» también sella, no sólo el estado de BlueExpress.
	 */
	public function test_completed_also_stamps(): void {
		$order = $this->pedido( array( MetaKeys::ORDER_HAS_PREVENTA => 'yes' ) );

		$this->sut->stamp( 10, 'processing', 'completed', $order );

		self::assertSame( self::AHORA, $order->akb_meta[ MetaKeys::ORDER_SHIPPABLE_AT ] );
	}
}
