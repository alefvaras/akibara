<?php
/**
 * Contrato de los textos del pie y de la promesa de cuotas que el dueño edita
 * en Akibara → Textos.
 *
 * @package akibara-gp\Tests
 */

declare( strict_types = 1 );

namespace Akibara\GP\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class SiteCopyTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		require_once dirname( __DIR__ ) . '/inc/site-copy.php';
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Siembra el estado de wp_options para esta prueba.
	 *
	 * @param array<string,mixed> $options Options guardadas.
	 */
	private function con_options( array $options ): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $name, $default = false ) => array_key_exists( $name, $options )
				? $options[ $name ]
				: $default
		);
	}

	/**
	 * @testdox Sin nada guardado, el pie dice exactamente lo que decía antes
	 */
	public function test_defaults_are_the_literals_the_footer_had(): void {
		$this->con_options( array() );

		self::assertSame( 'Horario de soporte: Lun a Vie · 10:00 a 18:00 hrs', akibara_gp_footer_support_hours() );
		self::assertSame( '+56 9 4424 2844', akibara_gp_footer_phone() );
		self::assertSame( '56944242844', akibara_gp_footer_phone_digits() );
		self::assertSame( '3 cuotas sin interés', akibara_gp_promise_installments() );
	}

	/**
	 * @testdox Un solo teléfono guardado alimenta el texto visible, el tel: y el wa.me
	 */
	public function test_one_phone_field_feeds_the_three_forms(): void {
		$this->con_options( array( 'akb_copy_footer_phone' => '+56 2 2345 6789' ) );

		self::assertSame( '+56 2 2345 6789', akibara_gp_footer_phone() );
		self::assertSame( '56223456789', akibara_gp_footer_phone_digits() );
	}

	/**
	 * @testdox Vaciar un campo apaga su línea en vez de dejar una etiqueta huérfana
	 */
	public function test_blank_values_switch_the_line_off(): void {
		$this->con_options(
			array(
				'akb_copy_footer_support_hours' => '   ',
				'akb_copy_footer_phone'         => '',
			)
		);

		self::assertSame( '', akibara_gp_footer_support_hours() );
		self::assertSame( '', akibara_gp_footer_phone() );
		self::assertSame( '', akibara_gp_footer_phone_digits() );
	}

	/**
	 * @testdox La promesa de cuotas nunca queda vacía: sin valor legible vuelve al de fábrica
	 */
	public function test_installments_never_renders_empty(): void {
		$this->con_options( array( 'akb_copy_promise_installments' => '  ' ) );
		self::assertSame( '3 cuotas sin interés', akibara_gp_promise_installments() );

		$this->con_options( array( 'akb_copy_promise_installments' => '6 cuotas sin interés' ) );
		self::assertSame( '6 cuotas sin interés', akibara_gp_promise_installments() );
	}

	/**
	 * @testdox El buscador de la cabecera invita a escribir la serie o el autor, lo que diga el dueño, y nunca queda sin pista (UC-16)
	 */
	public function test_search_placeholder(): void {
		$this->con_options( array() );
		self::assertSame( 'Buscar manga, series o autor…', akibara_gp_search_placeholder() );

		$this->con_options( array( 'akb_copy_search_placeholder' => 'Busca tu próxima serie' ) );
		self::assertSame( 'Busca tu próxima serie', akibara_gp_search_placeholder() );

		$this->con_options( array( 'akb_copy_search_placeholder' => '   ' ) );
		self::assertSame( 'Buscar manga, series o autor…', akibara_gp_search_placeholder() );
	}

	/**
	 * @testdox El nombre accesible del buscador es la pista visible sin los puntos suspensivos, sin partir letras (QA-ESC-TECLADO-6)
	 */
	public function test_search_label_is_the_visible_hint(): void {
		$this->con_options( array() );
		self::assertSame( 'Buscar manga, series o autor', akibara_gp_search_label() );

		$this->con_options( array( 'akb_copy_search_placeholder' => 'Busca tu próxima serie...' ) );
		self::assertSame( 'Busca tu próxima serie', akibara_gp_search_label() );

		// Sin puntos suspensivos, tal cual: lo que se ve es lo que se dice.
		$this->con_options( array( 'akb_copy_search_placeholder' => 'Busca tu próxima serie' ) );
		self::assertSame( 'Busca tu próxima serie', akibara_gp_search_label() );

		// «À» termina en el byte 0x80, que también es de «…»: un rtrim() por bytes la partía.
		$this->con_options( array( 'akb_copy_search_placeholder' => 'Busca por autor: À' ) );
		self::assertSame( 'Busca por autor: À', akibara_gp_search_label() );

		// Una pista que sólo son puntos suspensivos no deja el campo sin nombre.
		$this->con_options( array( 'akb_copy_search_placeholder' => '…' ) );
		self::assertSame( 'Buscar manga, series o autor', akibara_gp_search_label() );
	}

	/**
	 * @testdox El cierre de las entradas conserva sus literales de siempre y los textos nuevos de la serie son del dueño (UC-12)
	 */
	public function test_guide_copy(): void {
		$this->con_options( array() );
		self::assertSame(
			array(
				'texto'  => '¿Te inspiró? Suma estos títulos a tu colección.',
				'tienda' => 'Explorar la tienda',
				'serie'  => 'Ver los tomos de {serie}',
				'kicker' => 'La serie en Akibara',
				'boton'  => 'Ver los tomos',
			),
			akibara_gp_guide_copy()
		);

		$this->con_options(
			array(
				'akb_copy_guide_cta_series' => 'Todo {serie} aquí',
				'akb_copy_guide_card_cta'   => '',
			)
		);
		$copy = akibara_gp_guide_copy();
		self::assertSame( 'Todo {serie} aquí', $copy['serie'] );
		self::assertSame( 'Ver los tomos', $copy['boton'], 'un botón vacío no se pinta: vuelve al de fábrica' );
	}
}
