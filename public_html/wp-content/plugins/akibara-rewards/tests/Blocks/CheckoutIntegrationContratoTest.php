<?php
/**
 * Lo que viaja al checkout lo usa el checkout, y el JS carga donde monta.
 *
 * El 2026-09-15 se cerró un hallazgo del documento de arquitectura: `max_pct`
 * viajaba en CADA respuesta del carrito del Store API y el JS no lo leía nunca.
 *
 * El otro hallazgo del mismo documento era FALSO y casi cuesta una regresión:
 * decía que el JS se encolaba en el carrito sin montar nada. En WC 11.1 el
 * bloque Carrito monta los plugins con `scope: 'woocommerce-checkout'` (slots
 * con `context: 'woocommerce/cart'`); medido en producción, /carrito/ muestra
 * el aviso para invitados. Quitar ese encolado lo borraba, y la revisión
 * independiente lo cazó antes de desplegar.
 *
 * Esta prueba lee el fuente, no ejecuta.
 *
 * @package Akibara\Rewards\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Tests\Blocks;

use Akibara\Rewards\Blocks\CheckoutIntegration;
use Akibara\Rewards\Points\Calculator;
use Akibara\Rewards\Points\Ledger;
use Akibara\Rewards\Points\Redemption;
use Akibara\Rewards\Points\Tiers;
use Akibara\Rewards\Tests\Support\RewardsTestCase;
use Brain\Monkey\Functions;

/**
 * @since 0.4.3
 */
final class CheckoutIntegrationContratoTest extends RewardsTestCase {

	private static function sin_comentarios( string $codigo ): string {
		return (string) preg_replace( '#/\*.*?\*/|//[^\n]*#s', '', $codigo );
	}

	private static function php(): string {
		return self::sin_comentarios( (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Blocks/CheckoutIntegration.php' ) );
	}

	private static function js(): string {
		return self::sin_comentarios( (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/checkout-blocks.js' ) );
	}

	/**
	 * Claves `'x' =>` del cuerpo de un método.
	 *
	 * @return array<int,string>
	 */
	private static function claves_de( string $metodo ): array {
		$codigo = self::php();
		$inicio = strpos( $codigo, 'function ' . $metodo . '(' );
		self::assertIsInt( $inicio, "No encontré {$metodo}(): la prueba no estaría midiendo." );
		$fin    = strpos( $codigo, "\n\t}", $inicio );
		$cuerpo = substr( $codigo, $inicio, false === $fin ? null : $fin - $inicio );

		preg_match_all( "/^\t{3}'([a-z_]+)'\s*=>/m", $cuerpo, $m );

		return $m[1];
	}

	/** @testdox El carrito del Store API y su esquema declaran exactamente los mismos campos. */
	public function test_datos_y_esquema_coinciden(): void {
		$datos   = self::claves_de( 'cart_data' );
		$esquema = self::claves_de( 'cart_schema' );

		$this->assertNotSame( array(), $datos );
		sort( $datos );
		sort( $esquema );
		$this->assertSame( $esquema, $datos );
	}

	/** @testdox Cada campo que viaja al checkout lo lee el JS (si nadie lo lee, se borra). */
	public function test_cada_campo_lo_lee_el_js(): void {
		$js = self::js();

		$sin_lector = array_values(
			array_filter(
				self::claves_de( 'cart_schema' ),
				static fn( string $clave ): bool => 1 !== preg_match( '/\bdata\.' . preg_quote( $clave, '/' ) . '\b/', $js )
			)
		);

		$this->assertSame( array(), $sin_lector, 'Campos del Store API que el checkout no usa.' );
	}

	/** @testdox Checkout promises the exact net cart points, personal tier and program gates. */
	public function test_lo_que_promete_es_lo_que_acredita(): void {
		$this->install_fake_wc();
		$options = array( 'akb_rewards_program_start' => '2020-01-01 00:00:00' );
		Functions\when( 'get_option' )->alias( static function ( $key, $default = false ) use ( &$options ) { return $options[ $key ] ?? $default; } );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'wc_price' )->alias( static fn ( $value ) => '$' . number_format( $value, 0, ',', '.' ) );
		$this->wpdb->user_meta[7][Ledger::META_TOTAL_EARNED] = 20000;
		$this->wpdb->user_meta[7][Tiers::META_TIER_EXPIRES_AT] = time() + 86400;
		$cart = new class() extends \WC_Cart {
			public function get_fees(): array {
				return array( (object) array( 'name' => Redemption::FEE_NAME, 'total' => -840.34, 'tax' => -159.66 ) );
			}
		};
		$cart->contents = array( array( 'line_total' => 10000.0, 'line_tax' => 1900.0 ) );
		WC()->cart = $cart;
		$sut = new CheckoutIntegration();
		$data = $sut->cart_data();
		self::assertSame( Calculator::points_for( Calculator::award_base_for_cart( $cart, 1000.0 ), 7 ), $data['earn_now'] );
		self::assertSame( 327, $data['earn_now'], 'The effective 1,000-peso fee and the 1.5 tier both matter.' );
		$limits = Redemption::redemption_limits( $cart, 7 );
		self::assertSame( $limits['max_points'], $data['max'] );
		self::assertSame( $limits['missing'], $data['missing'] );
		foreach ( array( 'ayer', gmdate( 'Y-m-d H:i:s', time() + 86400 ) ) as $start ) {
			$options['akb_rewards_program_start'] = $start;
			$closed = $sut->cart_data();
			foreach ( array( 'earn_now', 'earn_points', 'earn_amount', 'welcome_bonus' ) as $field ) {
				self::assertSame( 0, $closed[ $field ], $field . ' cannot promise outside the program.' );
			}
		}
	}

	/** @testdox El JS se encola en el Carrito y en el Checkout de bloques: los dos montan el plugin. */
	public function test_el_js_se_encola_en_carrito_y_checkout(): void {
		$this->assertStringContainsString( "'woocommerce_blocks_enqueue_checkout_block_scripts_after'", self::php() );
		$this->assertStringContainsString( "'woocommerce_blocks_enqueue_cart_block_scripts_after'", self::php() );
		$this->assertMatchesRegularExpression( "/scope:\s*'woocommerce-checkout'/", self::js() );
	}
}
