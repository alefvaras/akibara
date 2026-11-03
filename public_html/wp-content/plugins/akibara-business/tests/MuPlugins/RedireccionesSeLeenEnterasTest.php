<?php
/**
 * Los conteos de redirecciones no pueden mentir en silencio — y esto lo EJECUTA.
 *
 * S-403094bb, REAPERTURA (2026-09-19): el candado anterior
 * (`test_se_pagina`/`test_el_tope_se_declara`) era un `file_get_contents()`
 * del propio fichero más `assertStringContainsString()` de literales: nunca
 * ejecutaba el bucle de paginación, nunca simulaba el corte en 10.000 filas y
 * pasaría igual con el bucle roto, con un off-by-one en `paged`, o con esos
 * literales dentro de un comentario. Es el mismo tipo de candado que la casa
 * ya rechazó por escrito en ProviderAccessTest: «se prueba EJECUTANDO el
 * cálculo, no con `grep` al fuente: esa falta de cobertura es lo que dejó
 * pasar el hueco».
 *
 * Esta versión ejecuta la lectura contra un doble de la API oficial de Rank
 * Math (@see \Akibara\Business\Tests\Support\RankMathRedirectionsDbDouble) y
 * confirma que las TRES superficies que el hallazgo original nombraba —
 * `akibara/redirections-status`, `akibara/redirections-snapshot` y
 * `akibara_rm_redirections_change_status()`— declaran el mismo corte en vez
 * de que sólo una de las tres lo haga.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\MuPlugins;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class RedireccionesSeLeenEnterasTest extends TestCase {

	private const MU_PLUGIN = '/mu-plugins/akibara-rankmath-redirections-abilities.php';

	/** @var callable|null Cierre de `wp_abilities_api_init` capturado al cargar el fichero. */
	private static $init = null;

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', '/tmp/' );
		}

		require_once dirname( __DIR__ ) . '/Support/RankMathRedirectionsDbDouble.php';

		Monkey\setUp();
		// El fichero registra sus abilities dentro de un cierre colgado de
		// `wp_abilities_api_init`; Brain Monkey no invoca hooks reales, así que
		// se intercepta `add_action` para quedarnos con el cierre y llamarlo a
		// mano (mismo patrón que AbilitiesDenylistTest con `add_filter`).
		Functions\when( 'add_action' )->alias(
			static function ( string $hook, $callback, int $prioridad = 10, int $args_count = 1 ): bool {
				if ( 'wp_abilities_api_init' === $hook ) {
					self::$init = $callback;
				}
				return true;
			}
		);

		// El fichero define constantes y funciones GLOBALES en include-time, sin
		// guarda `function_exists`: como cualquier mu-plugin real, sólo puede
		// cargarse una vez por proceso.
		if ( ! function_exists( 'akibara_rm_redirections_rows' ) ) {
			require_once dirname( __DIR__, 4 ) . self::MU_PLUGIN;
		}
		Monkey\tearDown();
	}

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'maybe_unserialize' )->alias( static fn( $v ) => $v );

		\RankMath\Redirections\DB::reset();
		$GLOBALS['akibara_rm_redirections_truncado'] = array();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param int    $cuantas Cantidad de filas a fabricar.
	 * @param string $status  Estado de esas filas.
	 * @return array<int,array<string,mixed>>
	 */
	private function filas( int $cuantas, string $status ): array {
		$filas = array();
		for ( $i = 1; $i <= $cuantas; $i++ ) {
			$filas[] = array(
				'id'          => $i,
				'sources'     => 'a:0:{}',
				'url_to'      => '/destino-' . $i,
				'header_code' => '301',
				'status'      => $status,
			);
		}

		return $filas;
	}

	/** @testdox 1.500 filas activas se leen ENTERAS y sin declarar corte. */
	public function test_1500_filas_no_se_trunca(): void {
		\RankMath\Redirections\DB::$dataset['active'] = $this->filas( 1500, 'active' );

		$rows = \akibara_rm_redirections_rows( array( 'active' ) );

		self::assertCount( 1500, $rows );
		self::assertSame( array(), $GLOBALS['akibara_rm_redirections_truncado'] );
	}

	/** @testdox 12.000 filas activas se cortan en el tope duro y el corte SE DECLARA. */
	public function test_12000_filas_se_corta_en_el_tope_y_se_declara(): void {
		\RankMath\Redirections\DB::$dataset['active'] = $this->filas( 12000, 'active' );

		$rows = \akibara_rm_redirections_rows( array( 'active' ) );

		self::assertCount( 10000, $rows, 'AKIBARA_RM_REDIRECTIONS_MAX_ROWS debe cortar en 10.000' );
		self::assertSame( array( 'active' => 12000 ), $GLOBALS['akibara_rm_redirections_truncado'] );
	}

	/** @testdox La paginación es 1-based y ninguna página se pide dos veces. */
	public function test_paginacion_es_1_based_y_no_repite(): void {
		\RankMath\Redirections\DB::$dataset['active'] = $this->filas( 1200, 'active' );

		\akibara_rm_redirections_rows( array( 'active' ) );

		$paginas = array_column( \RankMath\Redirections\DB::$paginas_pedidas, 'paged' );

		self::assertNotContains( 0, $paginas, 'paged es 1-based, igual que page($paged-1,$limit) en la clase real' );
		self::assertSame( $paginas, array_values( array_unique( $paginas ) ), 'ninguna página se repite' );
		self::assertSame( array( 1, 2, 3 ), $paginas, '1.200 filas a 500 por página son 3 páginas' );
	}

	/** @testdox `akibara/redirections-status` declara el corte en counts(). */
	public function test_el_conteo_declara_el_corte(): void {
		\RankMath\Redirections\DB::$dataset['active']   = $this->filas( 12000, 'active' );
		\RankMath\Redirections\DB::$dataset['inactive']  = array();
		\RankMath\Redirections\DB::$dataset['trashed']   = array();

		$counts = \akibara_rm_redirections_counts();

		self::assertArrayHasKey( '_incompleto', $counts );
		self::assertSame( array( 'active' => 12000 ), $counts['_incompleto'] );
	}

	/**
	 * @testdox REAPERTURA S-403094bb: `akibara/redirections-snapshot` también declara el corte, no sólo `redirections-status`.
	 */
	public function test_el_snapshot_tambien_declara_el_corte(): void {
		\RankMath\Redirections\DB::$dataset['active'] = $this->filas( 12000, 'active' );

		$registradas = array();
		Functions\when( 'wp_register_ability' )->alias(
			static function ( string $name, array $args ) use ( &$registradas ): bool {
				$registradas[ $name ] = $args;

				return true;
			}
		);

		self::assertIsCallable( self::$init, 'no se capturó el cierre de wp_abilities_api_init' );
		( self::$init )();

		self::assertArrayHasKey( 'akibara/redirections-snapshot', $registradas );

		$salida = ( $registradas['akibara/redirections-snapshot']['execute_callback'] )( array( 'statuses' => array( 'active' ) ) );

		self::assertSame(
			10000,
			$salida['total'],
			'el cierre anterior devolvía total=10000 con 12.000 filas activas SIN decir que faltaban 2.000'
		);
		self::assertSame(
			array( 'active' => 12000 ),
			$salida['_incompleto'] ?? null,
			'defecto original en esta superficie: 10.000 se presentaba como la verdad completa'
		);
	}

	/**
	 * @testdox REAPERTURA S-403094bb: un ID legítimo más allá del corte no sale por «no elegible» con un mensaje falso.
	 */
	public function test_un_id_mas_alla_del_corte_no_sale_por_no_elegible(): void {
		\RankMath\Redirections\DB::$dataset['trashed'] = $this->filas( 12000, 'trashed' );

		// La fila 12.000 es legítima y está en 'trashed', pero la lectura de
		// elegibles se cortó en 10.000: antes de la reapertura este ID salía
		// por `akibara_rm_ids_not_eligible` con un mensaje falso.
		$resultado = \akibara_rm_redirections_change_status(
			array(
				'ids'            => array( 12000 ),
				'expected_count' => 1,
				'dry_run'        => true,
			),
			array( 'trashed' ),
			'active'
		);

		self::assertInstanceOf( \WP_Error::class, $resultado );
		self::assertSame( 'akibara_rm_eligibles_incompletos', $resultado->get_error_code() );
	}

	/** @testdox Con la lectura completa, un ID que de verdad no existe sí sale por «no elegible». */
	public function test_un_id_inexistente_de_verdad_sale_por_no_elegible(): void {
		\RankMath\Redirections\DB::$dataset['trashed'] = $this->filas( 5, 'trashed' );

		$resultado = \akibara_rm_redirections_change_status(
			array(
				'ids'            => array( 999 ),
				'expected_count' => 1,
				'dry_run'        => true,
			),
			array( 'trashed' ),
			'active'
		);

		self::assertInstanceOf( \WP_Error::class, $resultado );
		self::assertSame( 'akibara_rm_ids_not_eligible', $resultado->get_error_code() );
	}

	/** @testdox El dry_run también declara el corte cuando el ID SÍ es elegible. */
	public function test_dry_run_declara_el_corte_cuando_el_id_es_elegible(): void {
		\RankMath\Redirections\DB::$dataset['trashed']  = $this->filas( 12000, 'trashed' );
		\RankMath\Redirections\DB::$dataset['active']   = array();
		\RankMath\Redirections\DB::$dataset['inactive'] = array();

		$resultado = \akibara_rm_redirections_change_status(
			array(
				'ids'            => array( 5 ),
				'expected_count' => 1,
				'dry_run'        => true,
			),
			array( 'trashed' ),
			'active'
		);

		self::assertIsArray( $resultado );
		self::assertSame( array( 'trashed' => 12000 ), $resultado['_incompleto'] ?? null );
	}
}
