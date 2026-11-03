<?php
/**
 * Candado: la barra de envío gratis no cobra donde no aparece.
 *
 * Tres gastos medidos, uno por superficie:
 *   1. `fragments()` se registraba FUERA del `if` del mini-carrito, así que
 *      apagar «mostrar en mini-carrito» apagaba la barra y seguía pagando el
 *      cálculo completo del umbral en cada add-to-cart.
 *   2. `enqueue_styles()` publicaba 14,7 KB de CSS en TODA página con un guard
 *      (`function_exists('is_woocommerce')`) que en una tienda WooCommerce es
 *      siempre verdadero — /carrito/ incluido, donde no hay un solo nodo que
 *      los use.
 *   3. El HTML se armaba DOS veces por refresco de fragments (widget + filtro)
 *      para producir exactamente la misma cadena.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\FreeShippingProgress;

use Akibara\Business\Modules\FreeShippingProgress\FreeShippingProgress;
use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

defined( 'AKIBARA_BUSINESS_DIR' ) || define( 'AKIBARA_BUSINESS_DIR', dirname( __DIR__, 3 ) . '/' );
defined( 'AKIBARA_BUSINESS_URL' ) || define( 'AKIBARA_BUSINESS_URL', 'https://akibara.test/wp-content/plugins/akibara-business/' );

final class RegistrationAndCostTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param array<string, string> $options
	 */
	private function stub_options( array $options ): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $option, $default = false ) => $options[ $option ] ?? $default
		);
	}

	private function source(): string {
		return (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Modules/FreeShippingProgress/FreeShippingProgress.php' );
	}

	/**
	 * @testdox Con el mini-carrito apagado no queda ni un fragment que recalcular.
	 */
	public function test_turning_the_mini_cart_off_also_stops_the_fragment_cost(): void {
		$this->stub_options( array( 'akibara_fsp_show_mini_cart' => 'no' ) );

		Filters\expectAdded( 'woocommerce_add_to_cart_fragments' )->never();

		( new FreeShippingProgress() )->register();

		self::assertTrue( true, 'La expectativa la verifica Brain Monkey al cerrar.' );
	}

	/**
	 * @testdox Con el mini-carrito encendido el fragment sigue registrado.
	 */
	public function test_the_fragment_survives_when_the_mini_cart_is_on(): void {
		$this->stub_options( array() );

		Filters\expectAdded( 'woocommerce_add_to_cart_fragments' )->once();

		( new FreeShippingProgress() )->register();

		self::assertTrue( true, 'La expectativa la verifica Brain Monkey al cerrar.' );
	}

	/**
	 * La regla «¿este carrito ya calificó?» no depende de que la BARRA se pinte:
	 * con «Mostrar en mini carrito» apagado el aviso del cajón volvía a decir «se
	 * calcula al finalizar la compra» a un carrito ya con envío gratis (ronda 5,
	 * 2026-09-19). Esta prueba fijaba justo lo contrario.
	 *
	 * @testdox Con el mini-carrito apagado, el drawer SIGUE pudiendo preguntar si el carrito ya calificó.
	 */
	public function test_the_qualified_filter_survives_with_the_mini_cart_off(): void {
		$this->stub_options( array( 'akibara_fsp_show_mini_cart' => 'no' ) );

		$sut = new FreeShippingProgress();
		Filters\expectAdded( 'akb_native_cart_fsp_qualified' )->once()->with( array( $sut, 'is_qualified' ) );
		Filters\expectAdded( 'akb_native_cart_fsp_html' )->never();

		$sut->register();

		self::assertTrue( true, 'La expectativa la verifica Brain Monkey al cerrar.' );
	}

	/**
	 * @testdox R3-2cf1a611: con el mini-carrito encendido, `NativeCart` puede preguntar por la MISMA regla que pinta la barra.
	 */
	public function test_the_qualified_filter_survives_when_the_mini_cart_is_on(): void {
		$this->stub_options( array() );

		$sut = new FreeShippingProgress();
		Filters\expectAdded( 'akb_native_cart_fsp_qualified' )->once()->with( array( $sut, 'is_qualified' ) );

		$sut->register();

		self::assertTrue( true, 'La expectativa la verifica Brain Monkey al cerrar.' );
	}

	/**
	 * @testdox El CSS no viaja donde la barra no puede aparecer.
	 */
	public function test_the_stylesheet_is_gated_by_surface(): void {
		Functions\when( 'is_woocommerce' )->justReturn( false );

		// Mini-carrito apagado: ninguna superficie pinta la barra.
		$this->stub_options( array( 'akibara_fsp_show_mini_cart' => 'no' ) );
		Functions\when( 'is_cart' )->justReturn( false );
		Functions\when( 'is_checkout' )->justReturn( false );
		Functions\expect( 'wp_enqueue_style' )->never();
		( new FreeShippingProgress() )->enqueue_styles();

		self::assertTrue( true );
	}

	/**
	 * @testdox En /carrito/ y en el checkout el CSS tampoco viaja.
	 */
	public function test_the_stylesheet_skips_cart_and_checkout(): void {
		Functions\when( 'is_woocommerce' )->justReturn( false );
		$this->stub_options( array() );
		Functions\when( 'is_cart' )->justReturn( true );
		Functions\when( 'is_checkout' )->justReturn( false );
		Functions\expect( 'wp_enqueue_style' )->never();

		( new FreeShippingProgress() )->enqueue_styles();

		self::assertTrue( true );
	}

	/**
	 * @testdox En una ficha normal el CSS sí viaja.
	 */
	public function test_the_stylesheet_still_travels_where_the_drawer_lives(): void {
		Functions\when( 'is_woocommerce' )->justReturn( true );
		$this->stub_options( array() );
		Functions\when( 'is_cart' )->justReturn( false );
		Functions\when( 'is_checkout' )->justReturn( false );
		Functions\expect( 'wp_enqueue_style' )->once()->with(
			'akb-fsp',
			AKIBARA_BUSINESS_URL . 'assets/css/free-shipping-progress.css',
			array(),
			\Mockery::type( 'string' )
		);

		( new FreeShippingProgress() )->enqueue_styles();

		self::assertTrue( true );
	}

	/**
	 * @testdox El HTML se arma una vez por petición y se olvida al mover el carrito.
	 */
	public function test_the_html_is_memoized_and_invalidated_by_woocommerce(): void {
		$src = $this->source();

		self::assertStringContainsString( 'private function cached_html(): string', $src );
		self::assertSame(
			0,
			substr_count( $src, '$this->get_html()' ) - 1,
			'`get_html()` sólo puede llamarse desde el memo; cada llamador directo devuelve el gasto duplicado.'
		);
		self::assertMatchesRegularExpression(
			"/add_action\(\s*'woocommerce_after_calculate_totals',\s*array\( \\\$this, 'flush_html_cache' \)/",
			$src,
			'Sin invalidación, una mutación del carrito serviría el HTML del carrito anterior.'
		);
	}

	/**
	 * @testdox El porcentaje tiene una sola definición.
	 */
	public function test_the_progress_percentage_has_a_single_owner(): void {
		$src = $this->source();

		self::assertSame(
			2,
			substr_count( $src, 'self::progress_percent(' ),
			'El marcado y el estado estructurado tienen que leer el MISMO porcentaje: sin redondear, el umbral del «casi lo logras» y el ancho de la barra discrepaban del estado que consumen los bloques.'
		);
		self::assertStringNotContainsString(
			'$threshold > 0 ? min( 100.0, ( $cart_total / $threshold ) * 100 ) : 0.0',
			$src,
			'La segunda cuenta a mano es justo la que divergía.'
		);
	}

	/**
	 * @testdox El motivo del envío gratis ya no se emite como clase sin estilo.
	 */
	public function test_the_unstyled_qualified_variant_classes_are_gone(): void {
		self::assertStringNotContainsString(
			'akb-fsp-q--<?php',
			$this->source(),
			'Se documentaba como contrato de estilo y ninguna hoja la usaba; el motivo vive en structured_state()[\'reason\'].'
		);
		self::assertStringNotContainsString(
			'akb-fsp-q--',
			(string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/css/free-shipping-progress.css' )
		);
	}
}
