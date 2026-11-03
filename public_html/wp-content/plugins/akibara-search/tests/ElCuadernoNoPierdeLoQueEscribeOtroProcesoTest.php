<?php
/**
 * El «Registro de cambios» no pierde lo que otro proceso anotó entre medio.
 *
 * `AuditLog::record()` es una lectura-modificación-escritura de UNA option. Un
 * runner de Action Scheduler vive medio minuto y anota varias veces; con la
 * copia de la option que su proceso guardó en memoria en la primera lectura,
 * la segunda anotación pisaba lo que el dueño había guardado en el panel entre
 * las dos —la fila para la que existe el cuaderno— sin error ni rastro
 * (revisión independiente 2026-09-19, MEDIA).
 *
 * Los dobles modelan lo que producción hace: `get_option()` sirve la copia del
 * PROCESO mientras nadie la invalide, y «el otro proceso» escribe en la base
 * sin tocar esa copia.
 *
 * @package Akibara\Search\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Search\Tests;

use Akibara\Search\AuditLog;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/src/AuditLog.php';

final class ElCuadernoNoPierdeLoQueEscribeOtroProcesoTest extends TestCase {

	/** @var array<string,mixed> La base de datos. */
	private array $base = array();

	/** @var array<string,mixed> La copia en memoria de ESTE proceso. */
	private array $copia = array();

	/** @var array<int,string> Lo que pasó, en orden. */
	private array $pasos = array();

	/** @var mixed */
	private $wpdb_anterior;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->base  = array();
		$this->copia = array();
		$this->pasos = array();

		Functions\when( 'get_option' )->alias(
			function ( string $n, $d = false ) {
				if ( ! array_key_exists( $n, $this->copia ) ) {
					$this->copia[ $n ] = $this->base[ $n ] ?? $d;
				}
				return $this->copia[ $n ];
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( string $n, $v ): bool {
				$this->pasos[]     = 'escribir';
				$this->base[ $n ]  = $v;
				$this->copia[ $n ] = $v;
				return true;
			}
		);
		Functions\when( 'wp_cache_delete' )->alias(
			function ( string $n ): bool {
				unset( $this->copia[ $n ] );
				return true;
			}
		);
		Functions\when( 'wp_get_current_user' )->justReturn( null );

		$this->wpdb_anterior = $GLOBALS['wpdb'] ?? null;
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->wpdb_anterior;
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @testdox Lo que otro proceso anotó entre dos anotaciones mías sigue en el cuaderno. */
	public function test_la_segunda_anotacion_no_pisa_la_de_otro_proceso(): void {
		AuditLog::record( 'serving_recovery', 'el vigilante pidió un rebuild' );

		// Otro proceso —el dueño en el panel— anota en la BASE. La copia en
		// memoria de este proceso no se entera.
		array_unshift(
			$this->base[ AuditLog::OPTION_KEY ],
			array(
				't'      => time(),
				'user'   => 'dueno',
				'action' => 'facets_saved',
				'detail' => 'Filtro «autor»: se activó',
			)
		);

		AuditLog::record( 'serving_alarma', 'sigue caído' );

		$acciones = array_column( $this->base[ AuditLog::OPTION_KEY ], 'action' );
		self::assertSame( array( 'serving_alarma', 'facets_saved', 'serving_recovery' ), $acciones, 'Con la copia vieja del proceso, la fila del dueño desaparecía sin dejar rastro.' );
	}

	/** @testdox La anotación se hace DENTRO de un candado entre procesos, y lo suelta siempre. */
	public function test_anota_dentro_del_candado(): void {
		$GLOBALS['wpdb'] = $this->wpdb( '1' );

		AuditLog::record( 'ajustes_guardados', 'Autocompletado: se apagó' );

		self::assertSame( array( 'GET_LOCK', 'escribir', 'RELEASE_LOCK' ), $this->pasos );
	}

	/** @testdox Sin poder tomar el candado se anota igual: una fila del cuaderno nunca frena la operación. */
	public function test_sin_candado_se_anota_igual(): void {
		$GLOBALS['wpdb'] = $this->wpdb( '0' );

		AuditLog::record( 'ajustes_guardados', 'Autocompletado: se apagó' );

		self::assertSame( array( 'GET_LOCK', 'escribir' ), $this->pasos, 'Ni espera eterna ni RELEASE de un candado que no es suyo.' );
		self::assertCount( 1, $this->base[ AuditLog::OPTION_KEY ] );
	}

	/**
	 * @param string $get_lock Lo que contesta GET_LOCK ('1' tomado, '0' ocupado).
	 */
	private function wpdb( string $get_lock ): object {
		$pasos = &$this->pasos;

		return new class( $pasos, $get_lock ) {
			public string $prefix = 'wp_';

			/** @var array<int,string> */
			private array $pasos;

			private string $get_lock;

			/**
			 * @param array<int,string> $pasos    Bitácora compartida con la prueba.
			 * @param string            $get_lock Respuesta de GET_LOCK.
			 */
			public function __construct( array &$pasos, string $get_lock ) {
				$this->pasos    = &$pasos;
				$this->get_lock = $get_lock;
			}

			public function prepare( string $sql, ...$args ): string {
				unset( $args );
				return $sql;
			}

			public function get_var( string $sql ): string {
				if ( false !== strpos( $sql, 'RELEASE_LOCK' ) ) {
					$this->pasos[] = 'RELEASE_LOCK';
					return '1';
				}
				$this->pasos[] = 'GET_LOCK';
				return $this->get_lock;
			}
		};
	}
}
