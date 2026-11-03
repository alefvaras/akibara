<?php
/**
 * El tope de canje tiene que ser UNO SOLO en todas las superficies.
 *
 * El dueño lo pidió el 2026-09-02 («coloca tope de canje de puntos») y su
 * regla de la casa manda que todo se configure desde la pantalla: si el motor
 * topa al 50 % y la caja del checkout, Mi cuenta o la página pública dicen otra
 * cosa, el cliente descubre el recorte al pagar. Este candado fija que el
 * número sale siempre de `Options::redeem_max_pct()`.
 *
 * @package Akibara\Rewards\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Tests\Points;

use Akibara\Rewards\Points\ProgramPage;
use Akibara\Rewards\Points\Redemption;
use Akibara\Rewards\Support\Options;
use Akibara\Rewards\Tests\Support\RewardsTestCase;
use Brain\Monkey\Functions;

final class RedeemCapSurfacesTest extends RewardsTestCase {

	/**
	 * Aísla el tope PORCENTUAL: el de pesos se apaga a propósito, porque manda
	 * el más estricto de los dos y su default ($5.000) taparía lo que se mide
	 * aquí. Los dos juntos se ejercen en
	 * `test_amount_and_percentage_caps_take_the_strictest()`.
	 *
	 * @param int $pct Tope porcentual a fijar.
	 * @return void
	 */
	private function con_tope( int $pct ): void {
		Functions\when( 'get_option' )->alias(
			static fn ( string $option, $default_value = false ) => array(
				'akb_rewards_redeem_max_pct'    => $pct,
				'akb_rewards_redeem_max_amount' => 0,
			)[ $option ] ?? $default_value
		);
	}

	/** @testdox El accessor acota el porcentaje al rango 0-100. */
	public function test_option_is_clamped(): void {
		$this->con_tope( 250 );
		self::assertSame( 100, Options::redeem_max_pct() );

		$this->con_tope( -5 );
		self::assertSame( 0, Options::redeem_max_pct() );

		$this->con_tope( 30 );
		self::assertSame( 30, Options::redeem_max_pct() );
	}

	/** @testdox El default del panel y el del accessor son el mismo número. */
	public function test_panel_and_accessor_share_the_default(): void {
		$panel = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Support/SettingsPage.php' );

		self::assertStringContainsString( "'akb_rewards_redeem_max_pct'", $panel, 'El tope se configura desde la pantalla, no en el código.' );
		self::assertStringContainsString( 'Options::DEFAULT_REDEEM_MAX_PCT', $panel, 'Un literal repetido en el panel se desincroniza del motor.' );
		self::assertGreaterThan( 0, Options::DEFAULT_REDEEM_MAX_PCT );
		self::assertLessThanOrEqual( 100, Options::DEFAULT_REDEEM_MAX_PCT );

		// Lo mismo para el tope en pesos, que es el que de verdad muerde.
		self::assertStringContainsString( "'akb_rewards_redeem_max_amount'", $panel, 'También se opera desde la pantalla.' );
		self::assertStringContainsString( 'Options::DEFAULT_REDEEM_MAX_AMOUNT', $panel );
		self::assertSame(
			5000,
			Options::DEFAULT_REDEEM_MAX_AMOUNT,
			'$5.000 no es un número redondo cualquiera: es el tope que publica el único competidor chileno de manga con programa de puntos (Dame Mi Manga, benchmark 2026-09-02). Cambiarlo es una decisión comercial del dueño, no un retoque de código.'
		);
	}

	/**
	 * El tope en pesos existe porque así lo publica el nicho: el único
	 * competidor chileno de manga con tope (Dame Mi Manga) dice «$5.000 por
	 * compra» y ninguna de las 11 tiendas revisadas el 2026-09-02 usa
	 * porcentajes. Con los dos topes activos manda el más estricto.
	 *
	 * @testdox Entre el tope en pesos y el porcentual gana el más estricto.
	 */
	public function test_amount_and_percentage_caps_take_the_strictest(): void {
		$mapa = static fn ( array $valores ) => Functions\when( 'get_option' )->alias(
			static fn ( string $option, $default_value = false ) => $valores[ $option ] ?? $default_value
		);

		// Carrito $42.476: 50 % son $21.238, pero el tope en pesos deja $5.000.
		$mapa( array( 'akb_rewards_redeem_max_pct' => 50, 'akb_rewards_redeem_max_amount' => 5000 ) );
		self::assertSame( 5000.0, Redemption::redeemable_gross( 42476.0 ) );

		// Carrito chico: el porcentual es más estricto que los $5.000.
		$mapa( array( 'akb_rewards_redeem_max_pct' => 50, 'akb_rewards_redeem_max_amount' => 5000 ) );
		self::assertSame( 4000.0, Redemption::redeemable_gross( 8000.0 ) );

		// Solo tope en pesos.
		$mapa( array( 'akb_rewards_redeem_max_pct' => 0, 'akb_rewards_redeem_max_amount' => 5000 ) );
		self::assertSame( 5000.0, Redemption::redeemable_gross( 42476.0 ) );

		// Ninguno: el techo es el carrito.
		$mapa( array( 'akb_rewards_redeem_max_pct' => 0, 'akb_rewards_redeem_max_amount' => 0 ) );
		self::assertSame( 42476.0, Redemption::redeemable_gross( 42476.0 ) );

		// El tope en pesos nunca supera al carrito.
		$mapa( array( 'akb_rewards_redeem_max_pct' => 0, 'akb_rewards_redeem_max_amount' => 90000 ) );
		self::assertSame( 42476.0, Redemption::redeemable_gross( 42476.0 ) );
	}

	/** @testdox La frase pública nombra los dos topes cuando ambos están activos. */
	public function test_public_sentence_names_both_caps(): void {
		Functions\when( 'get_option' )->alias(
			static fn ( string $option, $default_value = false ) => array(
				'akb_rewards_redeem_max_pct'    => 50,
				'akb_rewards_redeem_max_amount' => 5000,
			)[ $option ] ?? $default_value
		);

		$frase = ( new ProgramPage() )->render( array( 'solo' => 'canje' ) );
		self::assertStringContainsString( 'el 50% de los productos', $frase );
		self::assertStringContainsString( '$5.000', $frase );
	}

	/** @testdox La frase pública dice el mismo porcentaje que aplica el motor. */
	public function test_public_sentence_matches_the_engine(): void {
		$this->con_tope( 40 );

		$frase = ( new ProgramPage() )->render( array( 'solo' => 'canje' ) );
		self::assertStringContainsString( 'hasta el 40% de los productos', $frase );
		self::assertSame( 4000.0, Redemption::redeemable_gross( 10000.0 ) );

		$this->con_tope( 0 );
		$sin = ( new ProgramPage() )->render( array( 'solo' => 'canje' ) );
		self::assertStringNotContainsString( 'de los productos.', str_replace( 'productos elegibles', '', $sin ), 'Con 0 no se promete un tope que no existe.' );
		self::assertSame( 10000.0, Redemption::redeemable_gross( 10000.0 ) );
	}

	/**
	 * El porcentaje no viaja: `max_pct` iba en cada respuesta del carrito y el JS
	 * no lo leía nunca (quitado en 0.4.3). El número del panel llega igual,
	 * resuelto en puntos dentro de `max` vía `Redemption::redeemable_gross()`.
	 */
	/** @testdox El checkout de bloques publica el tope ya resuelto para el carrito. */
	public function test_checkout_exposes_the_cap(): void {
		$fuente = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Blocks/CheckoutIntegration.php' );

		// Sin fijar los argumentos: lo que importa es QUIÉN resuelve el tope, no
		// cómo se le pasa la base. Desde 0.4.4 recibe el bruto ya calculado, para
		// no recorrer el carrito tres veces por petición.
		self::assertMatchesRegularExpression(
			"/'max'\\s*=>\\s*\\\$limits\\['max_points'\\]/",
			$fuente,
			'El tope del carrito lo resuelve el servidor, no la caja.'
		);
		self::assertStringContainsString( 'Redemption::redemption_limits( $cart, $user_id )', $fuente, 'El tope sale del mismo cálculo que aplica el motor.' );
		self::assertStringContainsString( "'max'           => \$entero", $fuente, 'Sin schema, el Store API descarta el campo.' );
		self::assertStringContainsString( 'akb_rewards_redeem_max_pct', $fuente, 'La option entra en el precargado de options del carrito.' );

		$js = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/checkout-blocks.js' );
		self::assertStringContainsString( 'parseInt( data.max, 10 )', $js, 'La caja lee el tope del servidor, no lo recalcula.' );
		self::assertStringContainsString( 'max: techo', $js );
	}

	/**
	 * Mi cuenta nombraba SÓLO el porcentaje. Con la configuración de fábrica el
	 * que manda es el de pesos ($5.000), así que el aviso prometía un descuento
	 * que el carrito no iba a dar (hallazgo S-d215a066, 2026-09-18).
	 *
	 * @testdox Mi cuenta avisa de los DOS topes al guardar la intención.
	 */
	public function test_account_warns_about_both_caps(): void {
		$fuente = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Points/AccountRewards.php' );

		self::assertStringContainsString( 'Redemption::techo_humano()', $fuente, 'El aviso sale del motor, no de una frase propia.' );
		self::assertStringContainsString( 'Tope por compra:', $fuente );
		self::assertStringNotContainsString(
			'Options::redeem_max_pct()',
			$fuente,
			'Mi cuenta no puede volver a leer una sola de las dos palancas.'
		);
	}

	/** @testdox La frase de los topes la dice UNA función, y las dos superficies la usan. */
	public function test_the_cap_sentence_has_a_single_author(): void {
		foreach ( array( 'AccountRewards', 'ProgramPage' ) as $clase ) {
			$fuente = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Points/' . $clase . '.php' );

			self::assertStringContainsString(
				'Redemption::techo_humano()',
				$fuente,
				$clase . ' tiene que decir los topes con las palabras del motor.'
			);
			self::assertStringNotContainsString(
				'redeem_max_amount()',
				$fuente,
				$clase . ' no arma su propia frase con las palancas sueltas.'
			);
		}
	}

	/** @testdox techo_humano() dice exactamente lo que topa redeemable_gross(). */
	public function test_cap_sentence_matches_the_engine(): void {
		$mapa = static fn ( array $valores ) => Functions\when( 'get_option' )->alias(
			static fn ( string $option, $default_value = false ) => $valores[ $option ] ?? $default_value
		);

		$mapa( array( 'akb_rewards_redeem_max_pct' => 50, 'akb_rewards_redeem_max_amount' => 5000 ) );
		self::assertSame( 'el 50% de los productos, y como máximo $5.000', Redemption::techo_humano() );

		$mapa( array( 'akb_rewards_redeem_max_pct' => 0, 'akb_rewards_redeem_max_amount' => 5000 ) );
		self::assertSame( '$5.000', Redemption::techo_humano() );

		$mapa( array( 'akb_rewards_redeem_max_pct' => 40, 'akb_rewards_redeem_max_amount' => 0 ) );
		self::assertSame( 'el 40% de los productos', Redemption::techo_humano() );

		// Sin topes no se promete ninguno: la frase desaparece sola.
		$mapa( array( 'akb_rewards_redeem_max_pct' => 0, 'akb_rewards_redeem_max_amount' => 0 ) );
		self::assertSame( '', Redemption::techo_humano() );

		// Y el 100 % no es un tope: es el carrito entero.
		$mapa( array( 'akb_rewards_redeem_max_pct' => 100, 'akb_rewards_redeem_max_amount' => 0 ) );
		self::assertSame( '', Redemption::techo_humano() );
		self::assertSame( 10000.0, Redemption::redeemable_gross( 10000.0 ) );
	}
}
