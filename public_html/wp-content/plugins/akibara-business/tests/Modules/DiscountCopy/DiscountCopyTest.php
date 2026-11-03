<?php
/**
 * Contrato de los shortcodes de campaña.
 *
 * Lo que se protege acá es una promesa comercial: el número que dice el banner
 * y el que cobra la ficha tienen que salir de la misma fuente. El día que se
 * separen, el cliente lee la contradicción antes que nosotros.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\DiscountCopy;

use Akibara\Business\Modules\DiscountCopy\DiscountCopy;
use Akibara\Business\Modules\TaxonomyDiscount\Settings;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class DiscountCopyTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$GLOBALS['akibara_test_options'] = array(
			Settings::OPTION_ENABLED => 'yes',
			Settings::OPTION_FROM    => '2026-09-05',
			Settings::OPTION_TO      => '2026-09-07',
		);

		Functions\when( 'current_time' )->justReturn( '2026-09-06' );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'set_transient' )->justReturn( true );
		// La foto ya calculada: este test mide la LECTURA de la campaña, no el
		// barrido de términos, que tiene su propio camino.
		Functions\when( 'get_transient' )->justReturn(
			array(
				'pct'       => 10,
				'term_name' => 'Ivrea Argentina',
			)
		);

		$this->reset_memo();
	}

	protected function tearDown(): void {
		$this->reset_memo();
		unset( $GLOBALS['akibara_test_options'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * El memo es request-local y estático: sin limpiarlo, el primer test
	 * decidiría el resultado de todos los demás.
	 */
	private function reset_memo(): void {
		$prop = new \ReflectionProperty( DiscountCopy::class, 'rule_memo' );
		$prop->setAccessible( true );
		$prop->setValue( null, false );
	}

	/**
	 * @testdox Bajar el TOPE de la tienda tira la foto del banner al instante.
	 *
	 * Hallazgo S-d8bf9867: el porcentaje que publica el banner sale acotado
	 * contra `akb_discount_max_pct`, pero esa option no estaba entre las que
	 * invalidan la foto. Bajar el tope de 15 a 8 cambiaba el precio de la ficha
	 * en el acto y dejaba la barra prometiendo el 15 % hasta doce horas: quien
	 * lee el banner y paga otra cosa no distingue un caché de una mentira.
	 */
	public function test_bajar_el_tope_invalida_la_foto(): void {
		$enganchados = array();
		Functions\when( 'add_shortcode' )->justReturn( true );
		Functions\when( 'add_action' )->alias(
			static function ( string $hook ) use ( &$enganchados ): bool {
				$enganchados[] = $hook;
				return true;
			}
		);

		( new DiscountCopy() )->register();

		self::assertContains(
			'update_option_' . \Akibara\Business\Utilities\DiscountCeiling::OPTION,
			$enganchados,
			'Sin este oyente, el banner promete un porcentaje que la tienda ya no cobra.'
		);
		self::assertContains( 'update_option_' . Settings::OPTION_ENABLED, $enganchados, 'Y las fronteras de siempre siguen.' );
	}

	public function test_dentro_de_campana_publica_porcentaje_y_editorial(): void {
		self::assertSame( '10%', DiscountCopy::pct_formatted() );
		self::assertSame( '10', DiscountCopy::pct_formatted( false ) );
		self::assertSame( 'Ivrea Argentina', DiscountCopy::editorial_name() );
		self::assertTrue( DiscountCopy::campaign_active() );
	}

	public function test_resuelve_los_dos_shortcodes_en_una_frase(): void {
		self::assertSame(
			'Solo este finde: 10% en Ivrea Argentina con stock',
			DiscountCopy::resolve_in( 'Solo este finde: [akb_descuento_pct] en [akb_descuento_editorial] con stock' )
		);
	}

	public function test_acepta_el_atributo_sin_signo(): void {
		self::assertSame(
			'Descuento de 10 por ciento',
			DiscountCopy::resolve_in( 'Descuento de [akb_descuento_pct signo="no"] por ciento' )
		);
	}

	public function test_un_texto_sin_shortcodes_no_se_toca(): void {
		$texto = 'Retiro sin costo en Metro San Miguel';
		self::assertSame( $texto, DiscountCopy::resolve_in( $texto ) );
	}

	/**
	 * El switch maestro apagado tiene que dejar los shortcodes en blanco aunque
	 * las reglas sigan guardadas: es exactamente el estado en el que el precio
	 * vuelve a ser el lleno.
	 */
	public function test_con_el_switch_apagado_no_hay_campana_que_nombrar(): void {
		$GLOBALS['akibara_test_options'][ Settings::OPTION_ENABLED ] = 'no';
		$this->reset_memo();

		self::assertFalse( DiscountCopy::campaign_active() );
		self::assertSame( '', DiscountCopy::pct_formatted() );
		self::assertSame( '', DiscountCopy::editorial_name() );
	}

	/**
	 * Un día después del cierre el precio ya subió. El banner tiene que callarse
	 * en el MISMO instante, no al día siguiente ni cuando alguien se acuerde.
	 */
	public function test_fuera_de_la_ventana_el_banner_se_calla(): void {
		Functions\when( 'current_time' )->justReturn( '2026-09-08' );
		$this->reset_memo();

		self::assertFalse( DiscountCopy::campaign_active() );
		self::assertSame(
			'Solo este finde:  en  con stock',
			DiscountCopy::resolve_in( 'Solo este finde: [akb_descuento_pct] en [akb_descuento_editorial] con stock' )
		);
	}

	/**
	 * Campaña vigente pero sin ninguna regla con porcentaje: la foto guarda ese
	 * «no hay nada» y los shortcodes se callan, en vez de anunciar un 0%.
	 */
	public function test_una_campana_sin_reglas_no_anuncia_nada(): void {
		Functions\when( 'get_transient' )->justReturn(
			array(
				'pct'       => 0,
				'term_name' => '',
			)
		);
		$this->reset_memo();

		self::assertFalse( DiscountCopy::campaign_active() );
		self::assertSame( '', DiscountCopy::pct_formatted() );
		self::assertSame( '', DiscountCopy::editorial_name() );
	}

	/** El último día de la ventana todavía cuenta: la vigencia es inclusiva. */
	public function test_el_ultimo_dia_de_la_ventana_sigue_vigente(): void {
		Functions\when( 'current_time' )->justReturn( '2026-09-07' );
		$this->reset_memo();

		self::assertTrue( DiscountCopy::campaign_active() );
	}
}
