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

use PHPUnit\Framework\TestCase;

/**
 * @since 0.4.3
 */
final class CheckoutIntegrationContratoTest extends TestCase {

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

	/**
	 * Lo que el checkout PROMETE tiene que salir de lo que después ACREDITA.
	 *
	 * El aviso dice ahora «este pedido suma N puntos». Si ese N se calculara
	 * aparte —en el navegador multiplicando el total, o con una copia de la regla
	 * en PHP— el día que el dueño cambie el tramo desde el panel el checkout
	 * seguiría prometiendo lo viejo, y el cliente vería una cifra al comprar y
	 * otra en su saldo. Por eso sale de `Calculator::points_for()` sobre la misma
	 * base (`award_base_for_cart`) que usa la acreditación.
	 *
	 * @testdox El número que promete el checkout sale de la función que acredita.
	 */
	public function test_lo_que_promete_es_lo_que_acredita(): void {
		$php = self::php();

		self::assertMatchesRegularExpression(
			"/'earn_now'\s*=>.{0,200}Calculator::points_for\(/s",
			$php,
			'el checkout no puede prometer puntos con una regla propia'
		);
		self::assertMatchesRegularExpression(
			'/\$limits\s*=\s*Redemption::redemption_limits\(\s*\$cart,\s*\$user_id\s*\)/',
			$php,
			'y los límites proceden del motor canónico del carrito'
		);
		self::assertMatchesRegularExpression(
			'/Calculator::award_base_for_cart\(\s*\$cart,\s*\$canje_bruto\s*\)/',
			$php,
			'la resta del canje la hace la propia Calculator, no una copia de la regla en la caja'
		);
		// Y lo que no acredita, no se promete: la vigencia del programa es una
		// compuerta del acreditado y tiene que gobernar también el aviso.
		self::assertMatchesRegularExpression(
			'/\$vigente\s*=\s*Accrual::program_open_now\(\)/',
			$php,
			'con el programa aún no vigente (o su fecha malformada) no se puede prometer nada'
		);
		foreach ( array( 'earn_now', 'earn_points', 'earn_amount', 'welcome_bonus' ) as $campo ) {
			self::assertMatchesRegularExpression(
				"/'" . $campo . "'\s*=>\s*\\\$vigente\s*\?/",
				$php,
				sprintf( '«%s» también promete acumulación: calla fuera de vigencia.', $campo )
			);
		}
	}

	/** @testdox El JS se encola en el Carrito y en el Checkout de bloques: los dos montan el plugin. */
	public function test_el_js_se_encola_en_carrito_y_checkout(): void {
		$this->assertStringContainsString( "'woocommerce_blocks_enqueue_checkout_block_scripts_after'", self::php() );
		$this->assertStringContainsString( "'woocommerce_blocks_enqueue_cart_block_scripts_after'", self::php() );
		$this->assertMatchesRegularExpression( "/scope:\s*'woocommerce-checkout'/", self::js() );
	}
}
