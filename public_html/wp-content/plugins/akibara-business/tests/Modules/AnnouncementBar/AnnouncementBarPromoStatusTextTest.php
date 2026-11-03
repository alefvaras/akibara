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

	private const HOY = '2026-09-19';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$GLOBALS['akibara_test_options'] = array();

		Functions\when( 'current_time' )->justReturn( self::HOY );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['akibara_test_options'] );
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
				'akibara_announcement_enabled'    => 'no',
				'akibara_announcement_promo_text' => 'Solo este finde: 10% en Ivrea',
				'akibara_announcement_promo_from' => '2026-09-18',
				'akibara_announcement_promo_to'   => '2026-09-20',
			)
		);

		// La promo SIGUE «vigente» según su propia ventana — es justo lo que
		// hace peligroso el texto viejo: `promo_active()` no es lo que decide
		// si el cliente la ve, `maybe_init_bar()` sí.
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
	 * Contraparte: con el interruptor ENCENDIDO (el default de fábrica), una
	 * promo vigente sigue diciendo «se está viendo» con normalidad. Sin este
	 * caso, invertir el guard nuevo (comparar `!==` en vez de `===`, o quitar
	 * el `&&` implícito del early-return) pasaría la prueba de arriba pero
	 * dejaría CUALQUIER promo vigente marcada como oculta.
	 */
	public function test_con_el_interruptor_encendido_la_promo_vigente_sigue_diciendo_se_esta_viendo(): void {
		$bar = $this->bar(
			array(
				'akibara_announcement_promo_text' => 'Solo este finde: 10% en Ivrea',
				'akibara_announcement_promo_from' => '2026-09-18',
				'akibara_announcement_promo_to'   => '2026-09-20',
			)
		);

		self::assertStringContainsString( 'se está viendo en la tienda', $this->status_text( $bar ) );
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
}
