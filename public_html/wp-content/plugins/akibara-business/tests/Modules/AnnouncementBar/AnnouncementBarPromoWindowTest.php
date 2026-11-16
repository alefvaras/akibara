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
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'America/Santiago' ) );
		Functions\when( 'taxonomy_exists' )->alias( static fn( string $tax ): bool => 'product_brand' === $tax );
		Functions\when( 'get_terms' )->justReturn( array() );
		Functions\when( 'get_term_meta' )->justReturn( '' );
		Functions\when( 'get_term_link' )->justReturn( 'https://akibara.cl/marca/ivrea-argentina/' );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_parse_url' )->alias( static fn( string $url, int $component = -1 ) => parse_url( $url, $component ) );

		$GLOBALS['wpdb'] = new class {
			public string $term_taxonomy = 'wp_term_taxonomy';
			public string $prefix        = 'wp_';
			public string $last_error    = '';
			public function prepare( string $sql, ...$args ): string {
				return $sql;
			}
			public function get_results( string $sql ): array {
				return array();
			}
			public function get_var( string $sql ) {
				return 0;
			}
		};

		$this->reset_discount_memo();
	}

	protected function tearDown(): void {
		$this->reset_discount_memo();
		unset( $GLOBALS['akibara_test_options'], $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	private function seed_active_rule(): void {
		$prop = new ReflectionProperty( \Akibara\Business\Modules\DiscountCopy\DiscountCopy::class, 'rule_memo' );
		$prop->setAccessible( true );
		$prop->setValue(
			null,
			array(
				'v'           => 2,
				'pct'         => 10,
				'term_name'   => 'Ivrea Argentina',
				'term_id'     => 230,
				'taxonomy'    => 'product_brand',
				'term_path'   => '/marca/ivrea-argentina/',
				'rules_count' => 1,
			)
		);
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
		$this->seed_active_rule();
		$bar = $this->bar(
			array(
				'akibara_announcement_promo_text'    => 'Solo este finde: 10% en Ivrea',
				'akibara_taxonomy_discount_enabled'  => 'yes',
				'akibara_taxonomy_discount_from'     => '2026-09-05',
				'akibara_taxonomy_discount_to'       => '2026-09-07',
				'akibara_taxonomy_discount_apply_to' => 'all',
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
		$this->seed_active_rule();
		$bar = $this->bar(
			array(
				'akibara_announcement_promo_text'    => 'Promo',
				'akibara_taxonomy_discount_enabled'  => 'yes',
				'akibara_taxonomy_discount_from'     => $from,
				'akibara_taxonomy_discount_to'       => $to,
				'akibara_taxonomy_discount_apply_to' => 'all',
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
	 * Un texto vacío no se muestra aunque la campaña esté vigente.
	 */
	public function test_texto_vacio_apaga_el_carril_aunque_la_ventana_cubra_hoy(): void {
		$this->seed_active_rule();
		$bar = $this->bar(
			array(
				'akibara_announcement_promo_text'    => "   \n  ",
				'akibara_taxonomy_discount_enabled'  => 'yes',
				'akibara_taxonomy_discount_from'     => '2026-09-01',
				'akibara_taxonomy_discount_to'       => '2026-09-30',
				'akibara_taxonomy_discount_apply_to' => 'all',
			)
		);

		self::assertFalse( $bar->promo_active() );
		self::assertSame(
			array( 'Envío a todo Chile', 'Retiro en Metro San Miguel' ),
			$this->messages_of( $bar )
		);
	}

	/**
	 * Sin campaña vigente en Akibara → Descuentos (aunque quedaran residuos legados),
	 * el carril promocional NO se muestra: única fuente de verdad.
	 */
	public function test_atado_al_descuento_manda_la_campana_y_no_las_fechas_propias(): void {
		$this->reset_discount_memo();
		$bar = $this->bar(
			array(
				'akibara_announcement_promo_text'            => 'Solo este finde: [akb_descuento_pct] en [akb_descuento_editorial]',
				'akibara_announcement_promo_from'            => '2026-01-01',
				'akibara_announcement_promo_to'              => '2026-12-31',
				'akibara_announcement_promo_follow_discount' => 'no',
				'akibara_taxonomy_discount_enabled'          => 'no',
			)
		);

		self::assertFalse( $bar->promo_active(), 'Sin campaña vigente en Descuentos el mensaje no puede verse.' );
		self::assertSame(
			array( 'Envío a todo Chile', 'Retiro en Metro San Miguel' ),
			$this->messages_of( $bar )
		);
	}

	/** Con la campaña encendida en Descuentos, el texto hereda vigencia, números y enlace. */
	public function test_atado_al_descuento_hereda_la_vigencia_y_los_numeros(): void {
		$this->seed_active_rule();

		$bar = $this->bar(
			array(
				'akibara_announcement_promo_text'    => 'Solo este finde: [akb_descuento_pct] en [akb_descuento_editorial]',
				'akibara_taxonomy_discount_enabled'  => 'yes',
				'akibara_taxonomy_discount_from'     => '2026-09-01',
				'akibara_taxonomy_discount_to'       => '2026-09-30',
				'akibara_taxonomy_discount_apply_to' => 'all',
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
	 * El lunes siguiente: el descuento venció o quedó sin reglas > 0% y el mensaje
	 * se retira solo, sin que nadie entre al panel.
	 */
	public function test_al_vencer_la_campana_el_mensaje_se_retira_solo(): void {
		$this->reset_discount_memo();

		$bar = $this->bar(
			array(
				'akibara_announcement_promo_text'    => 'Solo este finde: [akb_descuento_pct] en [akb_descuento_editorial]',
				'akibara_taxonomy_discount_enabled'  => 'yes',
				'akibara_taxonomy_discount_from'     => '2026-09-01',
				'akibara_taxonomy_discount_to'       => '2026-09-30',
				'akibara_taxonomy_discount_apply_to' => 'all',
			)
		);

		self::assertFalse( $bar->promo_active(), 'Con ventana activa pero 0 reglas elegibles, tampoco promete un descuento falso.' );
		self::assertSame(
			array( 'Envío a todo Chile', 'Retiro en Metro San Miguel' ),
			$this->messages_of( $bar )
		);
	}
}
