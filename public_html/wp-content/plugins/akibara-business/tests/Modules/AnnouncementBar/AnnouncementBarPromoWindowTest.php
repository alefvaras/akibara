<?php
/**
 * Contrato del carril promocional de la barra de anuncios.
 *
 * El defecto que cierra: el 2026-09-05 la promo de fin de semana se escribió a
 * mano entre los mensajes fijos. El descuento tenía vigencia y se apagaba solo
 * el lunes; el mensaje no, así que la barra iba a quedar prometiendo un 10% que
 * el carrito ya no cobraba. Un banner que sobrevive a su promoción no falla
 * ruidosamente: sigue ahí, convincente, hasta que alguien lo mira.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\AnnouncementBar;

use Akibara\Business\Modules\AnnouncementBar\AnnouncementBar;
use Akibara\Business\Modules\TaxonomyDiscount\Settings;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

final class AnnouncementBarPromoWindowTest extends TestCase {

	private const HOY = '2026-09-06';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$GLOBALS['akibara_test_options'] = array(
			'akibara_announcement_messages' => "Envío a todo Chile\nRetiro en Metro San Miguel",
		);

		Functions\when( 'current_time' )->justReturn( self::HOY );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );

		$this->reset_discount_memo();
	}

	protected function tearDown(): void {
		$this->reset_discount_memo();
		unset( $GLOBALS['akibara_test_options'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	private function reset_discount_memo(): void {
		$prop = new ReflectionProperty( \Akibara\Business\Modules\DiscountCopy\DiscountCopy::class, 'rule_memo' );
		$prop->setAccessible( true );
		$prop->setValue( null, false );
	}

	/** @param array<string,string> $extra */
	private function bar( array $extra = array() ): AnnouncementBar {
		$GLOBALS['akibara_test_options'] = array_merge( $GLOBALS['akibara_test_options'], $extra );

		return new AnnouncementBar();
	}

	/** @return array<int,string> */
	private function messages_of( AnnouncementBar $bar ): array {
		$method = new ReflectionMethod( AnnouncementBar::class, 'get_messages' );
		$method->setAccessible( true );

		return (array) $method->invoke( $bar );
	}

	public function test_sin_texto_promocional_no_hay_carril(): void {
		$bar = $this->bar();

		self::assertFalse( $bar->promo_active() );
		self::assertSame(
			array( 'Envío a todo Chile', 'Retiro en Metro San Miguel' ),
			$this->messages_of( $bar )
		);
	}

	/**
	 * La promo va PRIMERA. Es la que caduca, así que es la que el cliente tiene
	 * que leer antes de que se acabe.
	 */
	public function test_la_promo_vigente_se_pinta_antes_que_los_fijos(): void {
		$bar = $this->bar(
			array(
				'akibara_announcement_promo_text' => 'Solo este finde: 10% en Ivrea',
				'akibara_announcement_promo_from' => '2026-09-05',
				'akibara_announcement_promo_to'   => '2026-09-07',
			)
		);

		self::assertTrue( $bar->promo_active() );
		self::assertSame(
			array( 'Solo este finde: 10% en Ivrea', 'Envío a todo Chile', 'Retiro en Metro San Miguel' ),
			$this->messages_of( $bar )
		);
	}

	/** @dataProvider ventanas */
	public function test_la_ventana_es_inclusiva_en_los_dos_extremos( string $from, string $to, bool $visible ): void {
		$bar = $this->bar(
			array(
				'akibara_announcement_promo_text' => 'Promo',
				'akibara_announcement_promo_from' => $from,
				'akibara_announcement_promo_to'   => $to,
			)
		);

		self::assertSame( $visible, $bar->promo_active() );
	}

	/** @return array<string, array{0:string,1:string,2:bool}> */
	public static function ventanas(): array {
		return array(
			'hoy es el primer día'      => array( self::HOY, '2026-09-30', true ),
			'hoy es el último día'      => array( '2026-09-01', self::HOY, true ),
			'empieza mañana'            => array( '2026-09-07', '2026-09-30', false ),
			'terminó ayer'              => array( '2026-09-01', '2026-09-05', false ),
			'sin fecha de término'      => array( '2026-09-01', '', true ),
			'sin fecha de inicio'       => array( '', '2026-09-30', true ),
			'sin fechas: siempre visible' => array( '', '', true ),
		);
	}

	/**
	 * Un texto vacío no se muestra aunque las fechas cubran hoy: vaciar el campo
	 * es la forma de apagar el carril sin perder las fechas.
	 */
	public function test_texto_vacio_apaga_el_carril_aunque_la_ventana_cubra_hoy(): void {
		$bar = $this->bar(
			array(
				'akibara_announcement_promo_text' => "   \n  ",
				'akibara_announcement_promo_from' => '2026-09-01',
				'akibara_announcement_promo_to'   => '2026-09-30',
			)
		);

		self::assertFalse( $bar->promo_active() );
		self::assertSame(
			array( 'Envío a todo Chile', 'Retiro en Metro San Miguel' ),
			$this->messages_of( $bar )
		);
	}

	/**
	 * Atado al descuento, las fechas propias NO deciden: manda la campaña. Acá
	 * están puestas a un rango que cubre hoy justamente para probar que se
	 * ignoran.
	 */
	public function test_atado_al_descuento_manda_la_campana_y_no_las_fechas_propias(): void {
		$bar = $this->bar(
			array(
				'akibara_announcement_promo_text'            => 'Solo este finde: [akb_descuento_pct] en [akb_descuento_editorial]',
				'akibara_announcement_promo_from'            => '2026-01-01',
				'akibara_announcement_promo_to'              => '2026-12-31',
				'akibara_announcement_promo_follow_discount' => 'yes',
				Settings::OPTION_ENABLED                     => 'no',
			)
		);

		self::assertFalse( $bar->promo_active(), 'Sin campaña vigente el mensaje no puede verse.' );
		self::assertSame(
			array( 'Envío a todo Chile', 'Retiro en Metro San Miguel' ),
			$this->messages_of( $bar )
		);
	}

	/** Con la campaña encendida, el mismo texto aparece y con los números vivos. */
	public function test_atado_al_descuento_hereda_la_vigencia_y_los_numeros(): void {
		Functions\when( 'get_transient' )->justReturn(
			array(
				'pct'       => 10,
				'term_name' => 'Ivrea Argentina',
			)
		);
		$this->reset_discount_memo();

		$bar = $this->bar(
			array(
				'akibara_announcement_promo_text'            => 'Solo este finde: [akb_descuento_pct] en [akb_descuento_editorial]',
				'akibara_announcement_promo_follow_discount' => 'yes',
				Settings::OPTION_ENABLED                     => 'yes',
				Settings::OPTION_FROM                        => '2026-09-05',
				Settings::OPTION_TO                          => '2026-09-07',
			)
		);

		self::assertTrue( $bar->promo_active() );
		self::assertSame(
			array(
				'Solo este finde: 10% en Ivrea Argentina',
				'Envío a todo Chile',
				'Retiro en Metro San Miguel',
			),
			$this->messages_of( $bar )
		);
	}

	/**
	 * El lunes siguiente: el descuento venció y el mensaje se retira solo, sin
	 * que nadie entre al panel. Es el caso entero por el que existe este carril.
	 */
	public function test_al_vencer_la_campana_el_mensaje_se_retira_solo(): void {
		Functions\when( 'get_transient' )->justReturn(
			array(
				'pct'       => 10,
				'term_name' => 'Ivrea Argentina',
			)
		);
		Functions\when( 'current_time' )->justReturn( '2026-09-08' );
		$this->reset_discount_memo();

		$bar = $this->bar(
			array(
				'akibara_announcement_promo_text'            => 'Solo este finde: [akb_descuento_pct] en [akb_descuento_editorial]',
				'akibara_announcement_promo_follow_discount' => 'yes',
				Settings::OPTION_ENABLED                     => 'yes',
				Settings::OPTION_FROM                        => '2026-09-05',
				Settings::OPTION_TO                          => '2026-09-07',
			)
		);

		self::assertFalse( $bar->promo_active() );
		self::assertSame(
			array( 'Envío a todo Chile', 'Retiro en Metro San Miguel' ),
			$this->messages_of( $bar )
		);
	}
}
