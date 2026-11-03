<?php
/**
 * La alarma del buscador: que una caída deje de ser silenciosa.
 *
 * El 2026-09-05 la tienda estuvo tres horas sin buscador ni filtros por la
 * mañana y media hora más por la tarde. Las dos veces el único que se enteró fue
 * quien estaba mirando la pantalla: el watchdog ya intentaba recuperarse cada 15
 * minutos, pero cuando no lo conseguía no se lo decía a nadie.
 *
 * @package Akibara\Search\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Search\Tests;

use Akibara\Search\RebuildJob;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class ServingAlarmTest extends TestCase {

	/** @var array<int, array{0:string,1:string}> */
	private static array $enviados = array();

	/** @var array<string, mixed> */
	private static array $options = array();

	private static string $entorno = 'production';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		self::$enviados = array();
		self::$options  = array();
		self::$entorno  = 'production';
		self::$options['admin_email'] = 'ale.fvaras@gmail.com';

		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'get_option' )->alias(
			static fn( string $nombre, $default = false ) => self::$options[ $nombre ] ?? $default
		);
		Functions\when( 'update_option' )->alias(
			static function ( string $nombre, $valor ): bool {
				self::$options[ $nombre ] = $valor;

				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			static function ( string $nombre ): bool {
				unset( self::$options[ $nombre ] );

				return true;
			}
		);
		Functions\when( 'wp_mail' )->alias(
			static function ( string $para, string $asunto, string $cuerpo ): bool {
				self::$enviados[] = array( $asunto, $cuerpo );

				return true;
			}
		);
		Functions\when( 'wp_get_environment_type' )->alias( static fn(): string => self::$entorno );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function invoke( string $metodo ): void {
		$m = new ReflectionMethod( RebuildJob::class, $metodo );
		$m->setAccessible( true );
		$m->invoke( null );
	}

	private function estado(): array {
		$raw = self::$options['akb_search_serving_alarm'] ?? array();

		return is_array( $raw ) ? $raw : array();
	}

	/**
	 * @testdox La primera vuelta con el serving caído sólo anota la hora: no avisa todavía.
	 */
	public function test_the_first_failed_round_only_records_the_time(): void {
		$this->invoke( 'note_serving_down' );

		self::assertSame( array(), self::$enviados, 'Un rebuild normal dura ~6 min: avisar a la primera sería ruido.' );
		self::assertGreaterThan( 0, (int) ( $this->estado()['down_since'] ?? 0 ) );
		self::assertSame( 0, (int) ( $this->estado()['notified_at'] ?? -1 ) );
	}

	/**
	 * @testdox Pasado el umbral sale UN aviso, y sólo uno por episodio.
	 */
	public function test_after_the_threshold_exactly_one_warning_goes_out(): void {
		self::$options['akb_search_serving_alarm'] = array(
			'down_since'  => time() - 1000,
			'notified_at' => 0,
		);

		$this->invoke( 'note_serving_down' );
		$this->invoke( 'note_serving_down' );
		$this->invoke( 'note_serving_down' );

		self::assertCount( 1, self::$enviados, 'Reintentar cada cuarto de hora está bien para un rebuild, no para la bandeja del dueño.' );
		self::assertStringContainsString( 'buscador', mb_strtolower( self::$enviados[0][0] ) );
		self::assertStringContainsString( 'sin filtros', mb_strtolower( self::$enviados[0][1] ), 'El aviso tiene que decir qué ve el cliente.' );
		self::assertStringContainsString( 'wp akb-search', self::$enviados[0][1], 'Y qué hacer.' );
	}

	/**
	 * @testdox Antes del umbral no sale nada, aunque el watchdog insista.
	 */
	public function test_nothing_goes_out_before_the_threshold(): void {
		self::$options['akb_search_serving_alarm'] = array(
			'down_since'  => time() - 60,
			'notified_at' => 0,
		);

		$this->invoke( 'note_serving_down' );

		self::assertSame( array(), self::$enviados );
	}

	/**
	 * @testdox Al volver el servicio se avisa de la vuelta y se limpia el estado.
	 */
	public function test_the_recovery_is_announced_and_the_state_is_cleared(): void {
		self::$options['akb_search_serving_alarm'] = array(
			'down_since'  => time() - 1000,
			'notified_at' => time() - 500,
		);

		$this->invoke( 'note_serving_up' );

		self::assertCount( 1, self::$enviados, 'Sin esto, el dueño se queda con el correo de alarma y sin saber que ya está bien.' );
		self::assertStringContainsString( 'volvió', self::$enviados[0][0] );
		self::assertArrayNotHasKey( 'akb_search_serving_alarm', self::$options );
	}

	/**
	 * @testdox Si nunca se avisó de la caída, tampoco se avisa de la vuelta.
	 */
	public function test_a_recovery_nobody_was_warned_about_stays_quiet(): void {
		self::$options['akb_search_serving_alarm'] = array(
			'down_since'  => time() - 60,
			'notified_at' => 0,
		);

		$this->invoke( 'note_serving_up' );

		self::assertSame( array(), self::$enviados, 'Un bache de un minuto no merece dos correos.' );
		self::assertArrayNotHasKey( 'akb_search_serving_alarm', self::$options );
	}

	/**
	 * @testdox Fuera de producción no se manda nada.
	 */
	public function test_outside_production_nothing_is_sent(): void {
		self::$entorno = 'local';
		self::$options['akb_search_serving_alarm'] = array(
			'down_since'  => time() - 1000,
			'notified_at' => 0,
		);

		$this->invoke( 'note_serving_down' );

		self::assertSame( array(), self::$enviados, 'Un aviso en el espejo enseña a ignorar los avisos de verdad.' );
	}
}
