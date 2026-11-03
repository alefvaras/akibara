<?php
/**
 * R4-fb4b69e8 (2026-09-19): un fallo SOSTENIDO de `EdgePurge` (credenciales de
 * Cloudflare rotadas, API caída) antes se quedaba en el log de WooCommerce →
 * Estado → Registros; nadie del lado del dueño lo veía sin ir a buscarlo ahí.
 * `AdminDashboard::health_alerts()` ahora suma `EdgePurge::health_alert()` a la
 * misma tarjeta «Alertas del Sistema» donde ya se avisan los crons atrasados.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\AdminDashboard;

use Akibara\Business\Modules\AdminDashboard\AdminDashboard;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class AlertaDePurgaDeBordeTest extends TestCase {

	/** @var mixed */
	private $wpdb_previo;

	/** @var mixed */
	private $options_previas;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Sin `$wpdb`, el registro de contacto responde «nada pendiente»: aquí
		// sólo importa lo que agrega `EdgePurge::health_alert()`.
		$this->wpdb_previo     = $GLOBALS['wpdb'] ?? null;
		$this->options_previas = $GLOBALS['akibara_test_options'] ?? null;
		unset( $GLOBALS['wpdb'] );

		Functions\when( 'wp_next_scheduled' )->alias( static fn() => time() + HOUR_IN_SECONDS );
		Functions\when( 'human_time_diff' )->justReturn( '20 minutos' );
	}

	protected function tearDown(): void {
		if ( null === $this->wpdb_previo ) {
			unset( $GLOBALS['wpdb'] );
		} else {
			$GLOBALS['wpdb'] = $this->wpdb_previo;
		}
		if ( null === $this->options_previas ) {
			unset( $GLOBALS['akibara_test_options'] );
		} else {
			$GLOBALS['akibara_test_options'] = $this->options_previas;
		}

		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @return array<int, array<string, string>>
	 */
	private function alertas(): array {
		$metodo = new ReflectionMethod( AdminDashboard::class, 'health_alerts' );
		$metodo->setAccessible( true );

		return $metodo->invoke( new AdminDashboard() );
	}

	/** @testdox Con las purgas al día, no hay alerta de Cloudflare. */
	public function test_sin_fallos_no_hay_alerta(): void {
		$GLOBALS['akibara_test_options'] = array(
			'akibara_edge_purge_last' => array( 'ok' => true, 'agotado' => false, 'motivo' => 'ok' ),
		);

		self::assertSame( array(), $this->alertas() );
	}

	/** @testdox Un fallo con reintento pendiente (no agotado) tampoco enciende la alerta: sería un fallo suelto. */
	public function test_un_fallo_no_agotado_no_alarma(): void {
		$GLOBALS['akibara_test_options'] = array(
			'akibara_edge_purge_last' => array(
				'ok'      => false,
				'agotado' => false,
				'motivo'  => 'HTTP 500',
				'time'    => time(),
			),
		);

		self::assertSame( array(), $this->alertas() );
	}

	/** @testdox Reintentos agotados: la alerta de Cloudflare llega a la misma tarjeta que los crons. */
	public function test_un_fallo_agotado_llega_a_la_tarjeta(): void {
		$GLOBALS['akibara_test_options'] = array(
			'akibara_edge_purge_last' => array(
				'ok'      => false,
				'agotado' => true,
				'motivo'  => 'HTTP 401',
				'time'    => time(),
			),
		);

		$alertas = $this->alertas();

		self::assertCount( 1, $alertas );
		self::assertSame( 'warning', $alertas[0]['level'] );
		self::assertStringContainsString( 'LiteSpeed Cache', $alertas[0]['html'] );
	}
}
