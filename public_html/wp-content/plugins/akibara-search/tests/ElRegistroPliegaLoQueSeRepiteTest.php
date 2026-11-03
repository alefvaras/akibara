<?php
/**
 * El «Registro de cambios» pliega lo que se repite, para que el ruido de la
 * máquina no expulse los cambios del dueño.
 *
 * El cuaderno guarda 50 filas. Un diagnóstico que se escribe en cada reconcile
 * con la barrera cerrada —`receipt_reproof_rechazado`, hasta una vez por
 * minuto— lo llenaba en menos de una hora y se llevaba por delante lo único
 * para lo que existe: que el dueño vea qué tocó (hallazgo S-c08a35b2).
 *
 * @package Akibara\Search\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Search\Tests;

use Akibara\Search\AuditLog;
use Akibara\Search\SearchAdminSection;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/src/AuditLog.php';
require_once dirname( __DIR__ ) . '/src/SearchAdminSection.php';

final class ElRegistroPliegaLoQueSeRepiteTest extends TestCase {

	/** @var array<string,mixed> */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'get_option' )->alias( fn( string $n, $d = false ) => $this->options[ $n ] ?? $d );
		Functions\when( 'update_option' )->alias(
			function ( string $n, $v ): bool {
				$this->options[ $n ] = $v;
				return true;
			}
		);
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'wp_get_current_user' )->justReturn( null );
		Functions\when( 'number_format_i18n' )->alias( static fn( $n ): string => number_format( (float) $n, 0, ',', '.' ) );
		Functions\when( 'wp_date' )->alias( static fn( string $f, int $t ): string => gmdate( $f, $t ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @testdox Seiscientas repeticiones de un aviso de máquina ocupan UNA fila, y el cambio del dueño sigue ahí. */
	public function test_el_ruido_de_maquina_no_expulsa_los_cambios_del_dueno(): void {
		AuditLog::record( 'ajustes_guardados', 'Autocompletado: se apagó' );

		for ( $i = 0; $i < 600; $i++ ) {
			AuditLog::record( 'receipt_reproof_rechazado', 'barrera cerrada' );
		}

		$filas = AuditLog::entries();
		self::assertCount( 2, $filas );
		self::assertSame( 600, $filas[0]['n'] );
		self::assertSame( 'ajustes_guardados', $filas[1]['action'], 'Lo que el dueño tocó sigue en el cuaderno.' );
	}

	/** @testdox Dos avisos que se alternan también se pliegan: no hace falta que sean consecutivos. */
	public function test_dos_avisos_alternados_tambien_se_pliegan(): void {
		for ( $i = 0; $i < 40; $i++ ) {
			AuditLog::record( 'receipt_reproof_rechazado', 'barrera cerrada' );
			AuditLog::record( 'serving_alarma', 'sigue caído' );
		}

		self::assertCount( 2, AuditLog::entries() );
	}

	/** @testdox Un detalle distinto es una fila distinta: plegar no es perder información. */
	public function test_un_detalle_distinto_no_se_pliega(): void {
		AuditLog::record( 'ajustes_guardados', 'Autocompletado: se apagó' );
		AuditLog::record( 'ajustes_guardados', 'Autocompletado: se activó' );

		self::assertCount( 2, AuditLog::entries() );
		self::assertArrayNotHasKey( 'n', AuditLog::entries()[0] );
	}

	/** @testdox El panel dice cuántas veces y desde cuándo. */
	public function test_el_panel_cuenta_las_repeticiones(): void {
		self::assertSame( 'barrera cerrada', SearchAdminSection::audit_detail( array( 'detail' => 'barrera cerrada' ) ) );

		$texto = SearchAdminSection::audit_detail(
			array(
				'detail' => 'barrera cerrada',
				'n'      => 1200,
				't0'     => 1789776000,
			)
		);

		self::assertStringContainsString( 'se repitió 1.200 veces', $texto );
		self::assertStringContainsString( 'desde el', $texto );
	}
}
