<?php
/**
 * La tarjeta «Alertas del Sistema» vigila TODAS las tareas recurrentes del
 * plugin, no las tres que alguien copió a mano el primer día.
 *
 * Hallazgos S-66f38031 y S-d7f87778 (2026-09-19): la tarjeta llevaba su propia
 * lista con 3 de los 8 crons —preventa y lista de espera— y su comentario
 * hablaba de «refrescar el manifiesto», que no vigilaba. Con el cron del
 * `llms.txt`, el de las portadas JPG, el del apagado de la campaña o el de las
 * recomendaciones parados, el dueño leía «✅ Todos los sistemas operando
 * normalmente». Ahora lee de `Utilities\OwnCrons`, la misma lista con la que la
 * desactivación y la desinstalación los retiran.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\AdminDashboard;

use Akibara\Business\Modules\AdminDashboard\AdminDashboard;
use Akibara\Business\Modules\LlmsManifest\LlmsManifest;
use Akibara\Business\Modules\OrderPreventa\RemindersCron;
use Akibara\Business\Modules\OrderPreventa\Settings as OrderPreventaSettings;
use Akibara\Business\Modules\UpsellsAutofill\UpsellsAutofill;
use Akibara\Business\Utilities\OwnCrons;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class TareasVigiladasTest extends TestCase {

	/** @var mixed */
	private $wpdb_previo;

	/** @var mixed */
	private $options_previas;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Sin `$wpdb`, el registro de contacto responde «nada pendiente» y la
		// tarjeta sólo habla de tareas programadas, que es lo que aquí se mide.
		$this->wpdb_previo     = $GLOBALS['wpdb'] ?? null;
		$this->options_previas = $GLOBALS['akibara_test_options'] ?? null;
		unset( $GLOBALS['wpdb'] );
		$GLOBALS['akibara_test_options'] = array();

		Functions\when( 'human_time_diff' )->justReturn( '7 horas' );
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
	 * @param array<string, int|false> $agenda hook => próximo instante, o false.
	 * @return array<int, array<string, string>>
	 */
	private function alertas_con( array $agenda ): array {
		Functions\when( 'wp_next_scheduled' )->alias(
			static fn( string $hook ) => array_key_exists( $hook, $agenda ) ? $agenda[ $hook ] : time() + HOUR_IN_SECONDS
		);

		$metodo = new ReflectionMethod( AdminDashboard::class, 'health_alerts' );
		$metodo->setAccessible( true );

		return $metodo->invoke( new AdminDashboard() );
	}

	/** @testdox Con todo encendido, la tarjeta vigila las ocho tareas recurrentes del plugin. */
	public function test_vigila_todas_las_recurrentes(): void {
		$vigiladas = AdminDashboard::watched_crons();

		self::assertSame( OwnCrons::RECURRING, $vigiladas );
		self::assertCount( 8, $vigiladas );
		self::assertArrayHasKey(
			LlmsManifest::HOOK,
			$vigiladas,
			'El comentario de la tarjeta habla de «refrescar el manifiesto»: tiene que vigilarlo.'
		);
	}

	/** @testdox Una tarea apagada desde el panel no se espera: su módulo la retira a propósito. */
	public function test_no_espera_las_que_el_dueno_apago(): void {
		$GLOBALS['akibara_test_options'] = array( OrderPreventaSettings::OPT_T7_ENABLED => 'no' );
		$sin_recordatorio                = AdminDashboard::watched_crons();
		self::assertArrayNotHasKey( RemindersCron::HOOK, $sin_recordatorio );
		self::assertCount( 7, $sin_recordatorio );

		$GLOBALS['akibara_test_options'] = array( UpsellsAutofill::ENABLED_OPTION => 'no' );
		$sin_recomendaciones             = AdminDashboard::watched_crons();
		self::assertArrayNotHasKey( UpsellsAutofill::CRON_HOOK, $sin_recomendaciones );
		self::assertArrayHasKey( RemindersCron::HOOK, $sin_recomendaciones );
		self::assertCount( 7, $sin_recomendaciones );
	}

	/** @testdox Todo al día: ninguna alerta. */
	public function test_sin_atrasos_no_hay_alertas(): void {
		self::assertSame( array(), $this->alertas_con( array() ) );
	}

	/** @testdox El manifiesto atrasado siete horas sale en la tarjeta (antes decía «todo normal»). */
	public function test_avisa_de_una_tarea_atrasada_fuera_de_las_tres_de_siempre(): void {
		$alertas = $this->alertas_con( array( LlmsManifest::HOOK => time() - 7 * HOUR_IN_SECONDS ) );

		self::assertCount( 1, $alertas );
		self::assertStringContainsString( 'Tarea atrasada', $alertas[0]['html'] );
		self::assertStringContainsString( LlmsManifest::HOOK, $alertas[0]['html'] );
		self::assertStringContainsString( OwnCrons::RECURRING[ LlmsManifest::HOOK ], $alertas[0]['html'] );
	}

	/** @testdox Un cron desagendado de los que no se vigilaban también sale. */
	public function test_avisa_de_un_cron_desagendado_fuera_de_los_tres_de_siempre(): void {
		$alertas = $this->alertas_con( array( 'akb_taxonomy_discount_expiry_sweep' => false ) );

		self::assertCount( 1, $alertas );
		self::assertStringContainsString( 'Cron desagendado', $alertas[0]['html'] );
		self::assertStringContainsString( 'akb_taxonomy_discount_expiry_sweep', $alertas[0]['html'] );
	}

	/** @testdox Apagadas las recomendaciones, su cron ausente NO es una alerta. */
	public function test_un_cron_apagado_y_ausente_no_alarma(): void {
		$GLOBALS['akibara_test_options'] = array( UpsellsAutofill::ENABLED_OPTION => 'no' );

		self::assertSame( array(), $this->alertas_con( array( UpsellsAutofill::CRON_HOOK => false ) ) );
	}
}
