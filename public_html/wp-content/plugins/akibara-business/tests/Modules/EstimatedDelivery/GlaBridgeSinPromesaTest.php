<?php
/**
 * R8-fecha-google-a-pedido (decisión del dueño, 2026-09-20): los tomos «a
 * pedido» con «Sin promesa de fecha» estaban rechazados por Merchant Center
 * (`presync_error_availabilityDate`) porque el puente no inventaba fecha. El
 * dueño eligió resolverlo como la competencia y como admite Google: el modo lo
 * decide en Akibara → Entregas y de fábrica van como agotados para Google.
 *
 * La primera versión del arreglo llamaba `setAvailability()` en el adaptador:
 * ese método sólo existe en el de Content API (el que hoy únicamente valida),
 * no en el de Merchant API, que es el que arma el payload que viaja. La
 * revisión independiente lo cazó antes de desplegar; por eso estas pruebas
 * miden el ARRAY devuelto —el único camino que gana en los dos adaptadores— y
 * corren contra un doble de cada uno.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\EstimatedDelivery;

use Akibara\Business\Modules\EstimatedDelivery\DateCalculator;
use Akibara\Business\Modules\EstimatedDelivery\GlaBridge;
use Akibara\Business\Modules\EstimatedDelivery\PedidoPolicy;
use Akibara\Business\Modules\EstimatedDelivery\Settings;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class GlaBridgeSinPromesaTest extends TestCase {

	private const TZ = 'America/Santiago';

	/** @var array<string,string> option → valor */
	private array $options = array();

	/** @var array<int,string> tipo de ETA por producto */
	private array $tipos = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->options = array();
		$this->tipos   = array();

		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_timezone' )->alias( static fn(): \DateTimeZone => new \DateTimeZone( self::TZ ) );
		Functions\when( 'get_option' )->alias( fn( string $option, $default_value = false ) => $this->options[ $option ] ?? $default_value );
		Functions\when( 'get_post_meta' )->alias( fn( $id, $key = '', $single = false ) => DateCalculator::META_TYPE === $key ? ( $this->tipos[ (int) $id ] ?? '' ) : '' );
		Functions\when( 'get_term_by' )->justReturn( false );
		Functions\when( 'get_the_terms' )->justReturn( false );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function puente(): GlaBridge {
		$tz          = new \DateTimeZone( self::TZ );
		$calculadora = new DateCalculator(
			new Settings(),
			static fn(): int => ( new \DateTimeImmutable( '2026-09-20 12:00', $tz ) )->getTimestamp(),
			$tz,
			new PedidoPolicy()
		);

		return new GlaBridge( $calculadora, new Settings() );
	}

	/**
	 * Doble del adaptador de Merchant API (`WCProductInputAdapter`), el que
	 * arma el payload real en producción: sin `setAvailability()` ni
	 * `getAvailabilityDate()`; lo ya mapeado sólo se ve por `get_product_input()`.
	 *
	 * @param array<string,mixed> $mapeado Atributos que GLA ya mapeó antes del filtro.
	 */
	private function adaptador_merchant_api( array $mapeado = array() ): object {
		return new class( $mapeado ) {
			/** @param array<string,mixed> $mapeado */
			public function __construct( private array $mapeado ) {}
			public function get_product_input(): object {
				return new class( $this->mapeado ) {
					/** @param array<string,mixed> $attributes */
					public function __construct( private array $attributes ) {}
					/** @return array<string,mixed> */
					public function get_attributes(): array {
						return $this->attributes;
					}
				};
			}
		};
	}

	/** Doble del adaptador de Content API (`WCProductAdapter`), el que hoy sólo valida. */
	private function adaptador_content_api( ?string $fecha_manual = null ): object {
		return new class( $fecha_manual ) {
			public function __construct( private ?string $fecha_manual ) {}
			public function getAvailabilityDate(): ?string {
				return $this->fecha_manual;
			}
			public function setAvailability( string $value ): void {
				throw new \LogicException( 'El puente no debe mutar el adaptador: ' . $value );
			}
		};
	}

	/** @return array<string,array{0:object}> */
	public static function adaptadores(): array {
		return array(
			'Merchant API (payload real)' => array( 'merchant' ),
			'Content API (validación)'    => array( 'content' ),
		);
	}

	private function adaptador( string $cual ): object {
		return 'merchant' === $cual ? $this->adaptador_merchant_api() : $this->adaptador_content_api();
	}

	/**
	 * @testdox De fábrica, un tomo «a pedido» sin promesa de fecha va a Google como agotado y sin fecha, por el array
	 * @dataProvider adaptadores
	 */
	public function test_sin_promesa_de_fabrica_es_agotado_para_google( string $cual ): void {
		$producto                           = new \WC_Product( 'onbackorder', array(), true );
		$this->tipos[ $producto->get_id() ] = 'disabled';

		$attributes = $this->puente()->inject_availability_date( array(), $producto, $this->adaptador( $cual ) );

		self::assertSame( array( 'availability' => 'out_of_stock' ), $attributes );
	}

	/**
	 * @testdox Una preventa sin fecha confirmada (tba) tampoco tiene fecha que darle a Google: mismo modo de fábrica.
	 */
	public function test_una_preventa_sin_fecha_confirmada_va_por_la_misma_puerta(): void {
		$producto                           = new \WC_Product( 'onbackorder', array(), true );
		$this->tipos[ $producto->get_id() ] = 'tba';

		self::assertSame( array( 'availability' => 'out_of_stock' ), $this->puente()->inject_availability_date( array(), $producto, $this->adaptador_merchant_api() ) );
	}

	/**
	 * @testdox Con «Dejar con error» no se toca nada, como antes del 2026-09-20.
	 * @dataProvider adaptadores
	 */
	public function test_modo_leave_no_toca_nada( string $cual ): void {
		$this->options[ Settings::GOOGLE_SIN_PROMESA_OPTION ] = 'leave';
		$producto                                             = new \WC_Product( 'onbackorder', array(), true );
		$this->tipos[ $producto->get_id() ]                   = 'disabled';

		self::assertSame( array(), $this->puente()->inject_availability_date( array(), $producto, $this->adaptador( $cual ) ) );
	}

	/**
	 * @testdox Un valor inválido de la option cae al modo de fábrica; un tomo con promesa o con stock no pasa por la puerta.
	 */
	public function test_valor_invalido_y_tomo_con_promesa(): void {
		$this->options[ Settings::GOOGLE_SIN_PROMESA_OPTION ] = 'cualquier-cosa';
		self::assertSame( 'out_of_stock', ( new Settings() )->google_sin_promesa() );

		$normal                           = new \WC_Product( 'onbackorder', array(), true );
		$this->tipos[ $normal->get_id() ] = 'auto';
		self::assertSame(
			array(),
			$this->puente()->inject_availability_date( array(), $normal, $this->adaptador_merchant_api() ),
			'Un «a pedido» normal sigue su camino (fecha por país/editorial), nunca se marca agotado.'
		);

		$disponible                           = new \WC_Product( 'instock', array(), true );
		$this->tipos[ $disponible->get_id() ] = 'disabled';
		self::assertSame(
			array( 'x' => 1 ),
			$this->puente()->inject_availability_date( array( 'x' => 1 ), $disponible, $this->adaptador_merchant_api() ),
			'Con stock no es reservable: no se toca.'
		);
	}

	/**
	 * @testdox La fecha manual del campo nativo de GLA se respeta en los dos adaptadores (en Merchant API se pisaba en silencio).
	 */
	public function test_fecha_manual_se_respeta_en_ambos_adaptadores(): void {
		$producto                           = new \WC_Product( 'onbackorder', array(), true );
		$this->tipos[ $producto->get_id() ] = 'disabled';

		$merchant = $this->adaptador_merchant_api( array( 'availability' => 'BACKORDER', 'availabilityDate' => '2026-12-01T00:00:00-03:00' ) );
		self::assertSame( array(), $this->puente()->inject_availability_date( array(), $producto, $merchant ) );

		$content = $this->adaptador_content_api( '2026-12-01T00:00:00-03:00' );
		self::assertSame( array(), $this->puente()->inject_availability_date( array(), $producto, $content ) );

		self::assertSame(
			array( 'availabilityDate' => 'x' ),
			$this->puente()->inject_availability_date( array( 'availabilityDate' => 'x' ), $producto, $this->adaptador_merchant_api() ),
			'Lo que otro plugin ya puso en el array también se respeta.'
		);
	}

	/**
	 * @testdox En modo «fecha del próximo pedido», la calculadora trata el tomo como un «a pedido» normal.
	 */
	public function test_modo_next_cycle_calcula_como_a_pedido_normal(): void {
		$this->options[ Settings::GOOGLE_SIN_PROMESA_OPTION ] = 'next_cycle';
		$producto                                             = new \WC_Product( 'onbackorder', array(), true );
		$this->tipos[ $producto->get_id() ]                   = 'disabled';
		$tz                                                   = new \DateTimeZone( self::TZ );
		$calculadora                                          = new DateCalculator( new Settings(), static fn(): int => ( new \DateTimeImmutable( '2026-09-20 12:00', $tz ) )->getTimestamp(), $tz, new PedidoPolicy() );

		self::assertTrue( $calculadora->is_sin_promesa( $producto ) );
		self::assertNull( $calculadora->calculate( $producto ), 'Para la ficha sigue sin fecha.' );
		// Sin país ni editorial en este doble no hay fecha que calcular, pero la
		// puerta ya no corta en seco: la respuesta es la de un «a pedido» normal
		// sin datos (null), no la del tipo «disabled».
		self::assertNull( $calculadora->calculate( $producto, true ) );
		self::assertSame(
			array(),
			$this->puente()->inject_availability_date( array(), $producto, $this->adaptador_merchant_api() ),
			'En este modo nunca se marca agotado.'
		);
	}
}
