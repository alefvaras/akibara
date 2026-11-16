<?php
/**
 * `promo_status_text()` tiene que decir la verdad que decide `maybe_init_bar()`.
 *
 * R3-eafda1cc (2026-09-19): el dueño desactiva la barra completa (interruptor
 * «Activar», que escribe `akibara_announcement_enabled = 'no'` — su propio
 * texto de ayuda dice «Al desactivarla los mensajes se conservan») mientras
 * deja un mensaje promocional con fecha vigente. `maybe_init_bar()` es el
 * ÚNICO gate real de renderizado: con el interruptor apagado corta antes de
 * enganchar `render_bar()` a `wp_body_open`, así que ningún cliente ve nada.
 * Pero la MISMA pantalla donde el dueño acaba de apagar todo seguía
 * informando «se está viendo en la tienda», porque `promo_status_text()` (y
 * `promo_active()`, de la que depende) nunca consultaban el interruptor
 * maestro.
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

final class AnnouncementBarPromoStatusTextTest extends TestCase {

	private function rel_date( string $modifier ): string {
		return ( new \DateTimeImmutable( $modifier, new \DateTimeZone( 'America/Santiago' ) ) )->format( 'Y-m-d' );
	}

	private function rel_cl( string $modifier ): string {
		return ( new \DateTimeImmutable( $modifier, new \DateTimeZone( 'America/Santiago' ) ) )->format( 'd/m/Y' );
	}

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$GLOBALS['akibara_test_options'] = array();

		Functions\when( 'current_time' )->justReturn( $this->rel_date( 'today' ) );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'admin_url' )->alias( static fn( string $path = '' ): string => 'https://akibara.cl/wp-admin/' . ltrim( $path, '/' ) );
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'America/Santiago' ) );
		Functions\when( 'taxonomy_exists' )->alias( static fn( string $tax ): bool => 'product_brand' === $tax );
		Functions\when( 'get_terms' )->justReturn(
			array(
				(object) array(
					'term_id'          => 230,
					'name'             => 'Ivrea',
					'taxonomy'         => 'product_brand',
					'term_taxonomy_id' => 230,
				),
			)
		);
		Functions\when( 'get_term_meta' )->justReturn( '15' );
		Functions\when( 'get_term_link' )->justReturn( 'https://akibara.cl/marca/ivrea/' );
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
	}

	protected function tearDown(): void {
		\Akibara\Business\Modules\DiscountCopy\DiscountCopy::forget();
		unset( $GLOBALS['akibara_test_options'], $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @param array<string,string> $extra */
	private function bar( array $extra = array() ): AnnouncementBar {
		$GLOBALS['akibara_test_options'] = array_merge( $GLOBALS['akibara_test_options'], $extra );

		return new AnnouncementBar();
	}

	private function status_text( AnnouncementBar $bar ): string {
		$method = new ReflectionMethod( AnnouncementBar::class, 'promo_status_text' );
		$method->setAccessible( true );

		return (string) $method->invoke( $bar );
	}

	/**
	 * El escenario exacto del hallazgo: promo vigente + interruptor maestro
	 * apagado. El texto tiene que decir «oculto», nunca «se está viendo».
	 */
	public function test_con_el_interruptor_maestro_apagado_el_estado_dice_oculto_aunque_la_promo_este_vigente(): void {
		$bar = $this->bar(
			array(
				'akibara_announcement_enabled'       => 'no',
				'akibara_announcement_promo_text'    => 'Solo este finde: 10% en Ivrea',
				'akibara_taxonomy_discount_enabled'  => 'yes',
				'akibara_taxonomy_discount_from'     => $this->rel_date( '-1 day' ),
				'akibara_taxonomy_discount_to'       => $this->rel_date( '+2 days' ),
				'akibara_taxonomy_discount_apply_to' => 'all',
			)
		);

		// La promo SIGUE «vigente» según Descuentos — `promo_active()` no decide
		// si el cliente la ve cuando el interruptor maestro de la barra está apagado.
		self::assertTrue( $bar->promo_active() );
		self::assertStringContainsString( 'oculto', $this->status_text( $bar ) );
		self::assertStringContainsString( 'interruptor', $this->status_text( $bar ) );
		self::assertStringNotContainsString( 'se está viendo', $this->status_text( $bar ) );
	}

	/**
	 * Interruptor apagado también gana sobre «sin mensaje promocional»: el
	 * motivo más relevante para el dueño es que la barra ENTERA está apagada,
	 * no que además no haya escrito una promo.
	 */
	public function test_el_interruptor_apagado_gana_incluso_sin_texto_promocional(): void {
		$bar = $this->bar( array( 'akibara_announcement_enabled' => 'no' ) );

		self::assertStringContainsString( 'interruptor', $this->status_text( $bar ) );
	}

	/**
	 * Contraparte: con el interruptor ENCENDIDO y campaña vigente en Descuentos,
	 * dice «se está viendo en la tienda» e identifica la regla ganadora real.
	 */
	public function test_con_el_interruptor_encendido_la_promo_vigente_sigue_diciendo_se_esta_viendo(): void {
		$bar = $this->bar(
			array(
				'akibara_announcement_promo_text'    => 'Solo este finde: [akb_descuento_pct] en [akb_descuento_editorial]',
				'akibara_taxonomy_discount_enabled'  => 'yes',
				'akibara_taxonomy_discount_from'     => $this->rel_date( '-1 day' ),
				'akibara_taxonomy_discount_to'       => $this->rel_date( '+2 days' ),
				'akibara_taxonomy_discount_apply_to' => 'all',
			)
		);

		$status = $this->status_text( $bar );
		self::assertStringContainsString( 'se está viendo en la tienda', $status );
		self::assertStringContainsString( 'Ivrea', $status );
		self::assertStringContainsString( '15%', $status );
	}

	/**
	 * Con el interruptor encendido y SIN promo, el texto original sigue
	 * intacto: este hallazgo no debía tocar ese mensaje.
	 */
	public function test_con_el_interruptor_encendido_y_sin_promo_el_texto_original_no_cambia(): void {
		$bar = $this->bar();

		self::assertSame(
			'Ahora mismo: <strong>no hay mensaje promocional</strong>; la barra muestra sólo los fijos.',
			$this->status_text( $bar )
		);
	}

	/** @testdox Los estados de Akibara → Descuentos (apagado, programado, expirado y sin reglas) se reportan con precisión. */
	public function test_estados_de_campana_enlazada_con_descuentos(): void {
		$scheduled = $this->bar(
			array(
				'akibara_announcement_promo_text'    => 'Promo',
				'akibara_taxonomy_discount_enabled'  => 'yes',
				'akibara_taxonomy_discount_from'     => $this->rel_date( '+5 days' ),
				'akibara_taxonomy_discount_to'       => $this->rel_date( '+10 days' ),
				'akibara_taxonomy_discount_apply_to' => 'all',
			)
		);
		self::assertStringContainsString( 'programado', $this->status_text( $scheduled ) );
		self::assertStringContainsString( $this->rel_cl( '+5 days' ), $this->status_text( $scheduled ) );

		$expired = $this->bar(
			array(
				'akibara_announcement_promo_text'    => 'Promo',
				'akibara_taxonomy_discount_enabled'  => 'yes',
				'akibara_taxonomy_discount_from'     => $this->rel_date( '-15 days' ),
				'akibara_taxonomy_discount_to'       => $this->rel_date( '-5 days' ),
				'akibara_taxonomy_discount_apply_to' => 'all',
			)
		);
		self::assertStringContainsString( 'ya terminó', $this->status_text( $expired ) );

		$disabled = $this->bar(
			array(
				'akibara_announcement_promo_text'   => 'Promo',
				'akibara_taxonomy_discount_enabled' => 'no',
			)
		);
		self::assertStringContainsString( 'no hay campaña vigente', $this->status_text( $disabled ) );

		Functions\when( 'get_terms' )->justReturn( array() );
		$no_rules = $this->bar(
			array(
				'akibara_announcement_promo_text'    => 'Promo',
				'akibara_taxonomy_discount_enabled'  => 'yes',
				'akibara_taxonomy_discount_from'     => $this->rel_date( '-1 day' ),
				'akibara_taxonomy_discount_to'       => $this->rel_date( '+2 days' ),
				'akibara_taxonomy_discount_apply_to' => 'all',
			)
		);
		self::assertStringContainsString( 'sin reglas &gt; 0% con stock elegible', $this->status_text( $no_rules ) );
	}
}
