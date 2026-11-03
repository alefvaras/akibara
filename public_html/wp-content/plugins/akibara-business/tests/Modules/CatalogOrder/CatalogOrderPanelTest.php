<?php
/**
 * Candado de la pantalla de la vitrina: estado visible y botón que encola.
 *
 * `last_run()` existía desde el principio con un docblock que decía «para el CLI
 * y para cualquier panel que lo quiera mostrar» — y ningún panel lo mostraba. La
 * vitrina reordenaba 1.568 productos a diario y la única forma de saberlo era
 * una shell que quien administra la tienda no tiene.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\CatalogOrder;

use Akibara\Business\Modules\CatalogOrder\CatalogOrder;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class CatalogOrderPanelTest extends TestCase {

	/** Acciones únicas encoladas: hook => timestamp. */
	private array $single = array();

	/** Destino del último `wp_safe_redirect`. */
	private string $redirect = '';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->single   = array();
		$this->redirect = '';
		$_GET           = array();

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_admin_referer' )->justReturn( true );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'admin_url' )->alias(
			static fn( string $path = '' ): string => 'https://akibara.test/wp-admin/' . $path
		);
		Functions\when( 'add_query_arg' )->alias(
			static fn( array $args, string $url ): string => $url . '?' . http_build_query( $args )
		);
		Functions\when( 'esc_url' )->returnArg( 1 );
		Functions\when( 'esc_attr' )->returnArg( 1 );
		Functions\when( 'esc_html' )->returnArg( 1 );
		Functions\when( 'sanitize_key' )->alias( static fn( string $value ): string => strtolower( $value ) );
		Functions\when( 'wp_unslash' )->returnArg( 1 );
		Functions\when( 'number_format_i18n' )->alias( static fn( $number ): string => (string) $number );
		Functions\when( 'wp_date' )->alias(
			static fn( string $format, int $timestamp ): string => gmdate( 'Y-m-d H:i', $timestamp )
		);
		// Desde el arreglo del aviso falso, `schedule_recompute()` pregunta por lo
		// PENDIENTE y por lo que está EN CURSO. Los dobles viven aquí con su valor
		// por defecto —cola vacía— porque Brain Monkey define la función para toda
		// la suite en cuanto otro test la dobla, y sin expectativa propia esta
		// quedaría sin ella.
		//
		// S-1c0e2031 (2026-09-19): `hay_recalculo_pendiente()` ya no usa
		// `as_has_scheduled_action()` — consulta `as_get_scheduled_actions()` con
		// `status => 'pending'` directamente (ver CatalogOrder.php), así que el
		// doble de `as_get_scheduled_actions` tiene que reflejar lo agendado en
		// `$this->single` igual que ya lo hacía `as_has_scheduled_action`; un
		// `justReturn( array() )` fijo aquí apagaba el guard sin que ningún test
		// de este fichero lo notara (esta suite no modela corridas «en curso»).
		Functions\when( 'as_has_scheduled_action' )->alias(
			fn( string $hook ): bool => isset( $this->single[ $hook ] )
		);
		Functions\when( 'as_get_scheduled_actions' )->alias(
			function ( array $query ) {
				$hook   = $query['hook'] ?? '';
				$status = $query['status'] ?? null;

				return ( 'pending' === $status && isset( $this->single[ $hook ] ) ) ? array( 1 ) : array();
			}
		);
		Functions\when( 'as_next_scheduled_action' )->alias(
			fn( string $hook ) => $this->single[ $hook ] ?? false
		);
		Functions\when( 'as_schedule_single_action' )->alias(
			function ( int $timestamp, string $hook ): int {
				$this->single[ $hook ] = $timestamp;
				return count( $this->single );
			}
		);
		Functions\when( 'wp_safe_redirect' )->alias(
			function ( string $url ): bool {
				$this->redirect = $url;
				throw new PanelRedirected();
			}
		);
	}

	protected function tearDown(): void {
		$_GET = array();
		unset( $GLOBALS['akibara_test_options'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Corre el handler capturando el `exit` (que el doble convierte en excepción).
	 */
	private function pulsar_el_boton(): void {
		try {
			( new CatalogOrder() )->handle_admin_recompute();
			self::fail( 'El handler tiene que redirigir.' );
		} catch ( PanelRedirected $expected ) {
			unset( $expected );
		}
	}

	/**
	 * @testdox El botón encola el recálculo sin esperar el debounce de las ráfagas
	 */
	public function test_the_button_queues_the_recompute_now(): void {
		$antes = time();

		$this->pulsar_el_boton();

		self::assertArrayHasKey( 'akibara_catalog_order_recompute', $this->single );
		self::assertLessThanOrEqual(
			$antes + 1,
			$this->single['akibara_catalog_order_recompute'],
			'El manual no espera los 120 s del debounce: hay una persona mirando la pantalla.'
		);
		self::assertStringContainsString( 'akb_vitrina=encolada', $this->redirect );
		self::assertStringContainsString( 'tab=' . CatalogOrder::PANEL_TAB_ID, $this->redirect );
	}

	/**
	 * @testdox Pulsarlo dos veces no duplica la corrida y lo DICE
	 */
	public function test_a_second_click_does_not_duplicate_the_run(): void {
		$this->pulsar_el_boton();
		$primero = $this->single['akibara_catalog_order_recompute'];

		$this->pulsar_el_boton();

		self::assertSame( $primero, $this->single['akibara_catalog_order_recompute'] );
		self::assertStringContainsString( 'akb_vitrina=ya-encolada', $this->redirect );
	}

	/**
	 * @testdox Sin corridas registradas la pantalla lo dice en vez de fingir una fecha
	 */
	public function test_the_screen_admits_it_never_ran(): void {
		$GLOBALS['akibara_test_options'] = array();

		ob_start();
		( new CatalogOrder() )->render_admin_section();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Nunca se ha recalculado', $html );
		self::assertStringContainsString( 'Recalcular la vitrina ahora', $html );
	}

	/**
	 * @testdox La pantalla publica la fecha y los dos conteos de la última corrida
	 */
	public function test_the_screen_publishes_the_last_run(): void {
		$GLOBALS['akibara_test_options'] = array(
			'akb_catalog_order_last_run' => array(
				'time'    => 1756700000,
				'rows'    => 1568,
				'applied' => 1122,
			),
		);

		ob_start();
		( new CatalogOrder() )->render_admin_section();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( '1568 productos revisados, 1122 reordenados', $html );
		self::assertStringContainsString( gmdate( 'Y-m-d H:i', 1756700000 ), $html );
	}

	/**
	 * @testdox El ajuste tiene su propio encabezado, después del bloque de recálculo
	 *
	 * B1-R2: el formulario del panel va justo después de esta pantalla y el
	 * panel borra el título de su primera sección. Sin un encabezado propio, la
	 * explicación del ajuste se leía como parte de «Recálculo». Los dos son
	 * subsecciones (h3) del título de la pestaña, que es el único h2.
	 */
	public function test_the_setting_has_its_own_heading_after_the_recompute_block(): void {
		ob_start();
		( new CatalogOrder() )->render_admin_section();
		$html = (string) ob_get_clean();

		self::assertStringNotContainsString( '<h2', $html, 'El h2 es el título de la pestaña («Vitrina»).' );

		$recalculo = strpos( $html, '<h3>Recálculo</h3>' );
		$boton     = strpos( $html, 'Recalcular la vitrina ahora' );
		$ajuste    = strpos( $html, '<h3>Qué ve primero el cliente</h3>' );
		$destacado = strpos( $html, 'Es el orden «Destacados»' );

		self::assertIsInt( $recalculo );
		self::assertIsInt( $ajuste );
		self::assertIsInt( $destacado );
		self::assertTrue( $recalculo < $boton && $boton < $ajuste && $ajuste < $destacado, 'Recálculo → botón → encabezado del ajuste → su explicación; el formulario viene a continuación.' );
		self::assertStringContainsString( 'Novedades primero', $html, 'Y avisa qué pasa con la otra opción de las landings de serie.' );
	}

	/**
	 * @testdox Tras encolar, la pantalla explica que lo ejecuta el cron del servidor
	 */
	public function test_the_screen_tells_the_truth_about_when_it_runs(): void {
		$_GET['akb_vitrina'] = 'encolada';

		ob_start();
		( new CatalogOrder() )->render_admin_section();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'cron del servidor', $html );
		self::assertStringNotContainsString( 'próxima visita al sitio', $html );
	}
}

/** Señal de que el handler llamó a `wp_safe_redirect` (el `exit` no es testeable). */
final class PanelRedirected extends \RuntimeException {}
