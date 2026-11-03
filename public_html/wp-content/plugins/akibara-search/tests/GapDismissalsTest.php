<?php
/**
 * Contrato del descarte de búsquedas del radar (pedido del dueño 2026-08-30).
 *
 * Fija el ciclo de vida completo: descartar oculta sin borrar el log,
 * restaurar revive, el tope de 200 impide el crecimiento indefinido del
 * propio mecanismo, y la función puente excluye descartadas para que las DOS
 * superficies (Centro de Mando y Buscador → Búsquedas) digan lo mismo.
 *
 * @package Akibara\Search\Tests
 */

declare( strict_types = 1 );

use Akibara\Search\DemandGaps;
use Akibara\Search\GapDismissals;
use Akibara\Search\SearchAdminSection;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/src/SearchAdminSection.php';

/**
 * @since 0.1.39
 */
final class GapDismissalsTest extends TestCase {

	/** @var array<string,mixed> Store de options del doble. */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->options = array();

		// Dobles por Brain Monkey y NO por funciones globales declaradas a mano:
		// una función global sobrevive a la clase que la declaró y contagia el
		// orden de ejecución de toda la suite [memoria: function_exists miente].
		Functions\when( 'get_option' )->alias(
			fn( string $name, $default = false ) => $this->options[ $name ] ?? $default
		);
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value ): bool {
				$this->options[ $name ] = $value;
				return true;
			}
		);
		// dismiss()/restore() relee de la base bajo candado desde 2026-09-19
		// (R3-558b3ea7); sin `$wpdb` en el doble, OptionLock::acquire() no toma
		// candado (mejor esfuerzo) y esto sólo evita el fatal por función no
		// definida.
		Functions\when( 'wp_cache_delete' )->justReturn( true );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Ciclo de vida del descarte sobre el origen que leen las pantallas
	 * (`GapDismissals::all()`). Que lo descartado no vuelva a salir en el radar
	 * lo fija `test_the_bridge_excludes_dismissed_gaps_for_both_surfaces()` por
	 * `DemandGaps::rows()`, el camino vivo. `is_dismissed()` y `filter_rows()` no
	 * tenían llamador en producción y se retiraron en 0.1.64.
	 */
	public function test_dismiss_and_restore_move_the_stored_set(): void {
		GapDismissals::dismiss( 'naruto' );

		$this->assertArrayHasKey( 'naruto', GapDismissals::all() );

		GapDismissals::restore( 'naruto' );

		$this->assertArrayNotHasKey( 'naruto', GapDismissals::all() );
		$this->assertSame( array(), GapDismissals::all() );
	}

	public function test_the_cap_drops_the_oldest_never_grows_unbounded(): void {
		// 200 descartes «viejos» con timestamps crecientes…
		$viejos = array();
		for ( $i = 1; $i <= GapDismissals::CAP; $i++ ) {
			$viejos[ "vieja-{$i}" ] = 1000 + $i;
		}
		$this->options[ GapDismissals::OPTION ] = $viejos;

		// …y entra la 201: cae la MÁS ANTIGUA (vieja-1), no otra.
		GapDismissals::dismiss( 'nueva' );

		$all = GapDismissals::all();
		$this->assertCount( GapDismissals::CAP, $all );
		$this->assertArrayNotHasKey( 'vieja-1', $all );
		$this->assertArrayHasKey( 'vieja-2', $all );
		$this->assertArrayHasKey( 'nueva', $all );
	}

	public function test_blank_queries_and_double_restores_are_harmless(): void {
		GapDismissals::dismiss( '   ' );
		$this->assertSame( array(), GapDismissals::all() );

		GapDismissals::restore( 'inexistente' );
		$this->assertSame( array(), GapDismissals::all() );
	}

	/**
	 * R3-558b3ea7 (2026-09-19): `dismiss()`/`restore()` hacían get_option →
	 * mutación en memoria → update_option sin relectura fresca. Dos pestañas
	 * del panel (o dos operadores) descartando/restaurando casi a la vez podían
	 * pisarse: el segundo update_option sobrescribía la fila entera SIN el
	 * descarte que el primero acababa de guardar.
	 *
	 * Mismo patrón de dobles que
	 * `ElCuadernoNoPierdeLoQueEscribeOtroProcesoTest` (AuditLog): `get_option()`
	 * sirve la copia del PROCESO hasta que algo la invalide, y «el otro
	 * proceso» escribe en la base sin tocar esa copia — así se modela una
	 * carrera entre procesos sin hilos de verdad.
	 *
	 * @testdox Lo que otro proceso descartó entre medio no desaparece al descartar algo distinto.
	 */
	public function test_un_descarte_no_pisa_lo_que_otro_proceso_escribio_entre_medio(): void {
		$base  = array();
		$copia = array();

		Functions\when( 'get_option' )->alias(
			function ( string $n, $d = false ) use ( &$base, &$copia ) {
				if ( ! array_key_exists( $n, $copia ) ) {
					$copia[ $n ] = $base[ $n ] ?? $d;
				}
				return $copia[ $n ];
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( string $n, $v ) use ( &$base, &$copia ): bool {
				$base[ $n ]  = $v;
				$copia[ $n ] = $v;
				return true;
			}
		);
		Functions\when( 'wp_cache_delete' )->alias(
			function ( string $n ) use ( &$copia ): bool {
				unset( $copia[ $n ] );
				return true;
			}
		);

		GapDismissals::dismiss( 'naruto' );

		// «Otro proceso» descarta una fila DISTINTA directo en la base: su
		// propia copia en memoria, no la de este proceso/test.
		$base[ GapDismissals::OPTION ]['berserk'] = 555;

		GapDismissals::dismiss( 'bleach' );

		self::assertSame(
			array( 'naruto', 'berserk', 'bleach' ),
			array_keys( $base[ GapDismissals::OPTION ] ),
			'El descarte de otro proceso, escrito entre medio, no puede desaparecer.'
		);

		// Restaurar tiene la MISMA relectura: no puede resucitar lo que otro
		// proceso descartó entre medio de la lectura y la escritura.
		GapDismissals::restore( 'bleach' );
		$base[ GapDismissals::OPTION ]['jjk'] = 777;
		GapDismissals::restore( 'naruto' );

		self::assertSame(
			array( 'berserk', 'jjk' ),
			array_keys( $base[ GapDismissals::OPTION ] ),
			'El descarte concurrente de otro proceso no puede perderse al restaurar.'
		);
	}

	/**
	 * @testdox Descartar y restaurar corren DENTRO de un candado entre procesos, y lo sueltan siempre — incluso cuando restaurar no tiene nada que quitar.
	 */
	public function test_descartar_y_restaurar_corren_dentro_del_candado(): void {
		$GLOBALS['wpdb'] = $this->wpdb( '1' );

		GapDismissals::dismiss( 'naruto' );
		self::assertSame( array( 'GET_LOCK', 'RELEASE_LOCK' ), $this->pasos, 'dismiss() con algo que escribir toma y suelta el candado.' );

		$this->pasos = array();
		GapDismissals::restore( 'inexistente' );
		self::assertSame( array( 'GET_LOCK', 'RELEASE_LOCK' ), $this->pasos, 'restore() sin nada que quitar también suelta el candado (early return DENTRO del try).' );
	}

	/** @testdox Sin poder tomar el candado se descarta igual: el radar nunca se congela por un candado ocupado. */
	public function test_sin_candado_se_descarta_igual(): void {
		$GLOBALS['wpdb'] = $this->wpdb( '0' );

		GapDismissals::dismiss( 'naruto' );

		self::assertSame( array( 'GET_LOCK' ), $this->pasos, 'Ni espera eterna ni RELEASE de un candado que no es suyo.' );
		self::assertArrayHasKey( 'naruto', GapDismissals::all() );
	}

	/** @var array<int,string> Bitácora de pasos del wpdb de candado, compartida con el doble. */
	private array $pasos = array();

	/** @param string $get_lock Lo que contesta GET_LOCK ('1' tomado, '0' ocupado). */
	private function wpdb( string $get_lock ): object {
		$this->pasos = array();
		$pasos       = &$this->pasos;

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

	/**
	 * @testdox La acción del radar se expone con su nonce, para las dos superficies.
	 *
	 * El Centro de Mando de business pinta el MISMO control desde otro riel de
	 * despliegue y duplicaba a mano el literal de una constante privada —acción
	 * Y nonce—. Con el par en dos sitios, renombrarlo en uno rompía el otro en
	 * silencio: el enlace seguía existiendo y el guard lo rechazaba.
	 */
	public function test_la_accion_del_radar_es_un_contrato_publico(): void {
		Functions\when( 'add_query_arg' )->alias(
			static fn( array $args, string $url ): string => $url . '?' . http_build_query( $args )
		);
		Functions\when( 'admin_url' )->alias( static fn( string $p = '' ): string => 'https://example.test/wp-admin/' . $p );
		Functions\when( 'wp_nonce_url' )->alias(
			static fn( string $url, string $action ): string => $url . '&_wpnonce=' . md5( $action )
		);

		$url = SearchAdminSection::gap_action_url( SearchAdminSection::ACTION_GAP_DISMISS, 'bakemonogatari' );

		$this->assertStringContainsString( 'action=' . SearchAdminSection::ACTION_GAP_DISMISS, $url );
		$this->assertStringContainsString( 'query=bakemonogatari', $url );
		$this->assertStringContainsString( '_wpnonce=' . md5( SearchAdminSection::ACTION_GAP_DISMISS ), $url );

		// Una acción ajena no obtiene una URL nonceada del radar.
		$this->assertSame( '', SearchAdminSection::gap_action_url( 'akb_search_rebuild', 'x' ) );
	}

	/**
	 * El descarte tiene que valer en las DOS superficies (Centro de Mando y
	 * Buscador → Búsquedas). Ambas leen del MISMO origen, `DemandGaps::rows()`,
	 * así que la invariante se comprueba ahí: lo descartado no vuelve.
	 *
	 * Antes esto era un grep al fuente de la función puente. Fijaba una línea
	 * concreta —`isset( $descartadas[ $query ] )`— en vez de la propiedad, y se
	 * puso en rojo en cuanto las dos copias del SQL se unificaron, sin que nada
	 * se hubiera roto.
	 */
	public function test_the_bridge_excludes_dismissed_gaps_for_both_surfaces(): void {
		// El rescatador de typos se sirve de su caché: acá se prueba el filtro
		// de descartes, no el corrector. La caché trae VEREDICTOS —las dos
		// búsquedas medidas, con cero resultados—: hasta el 2026-09-19 venía
		// vacía, y «berserk» entraba en la lista sólo porque la ausencia de
		// veredicto se leía como hueco real, que es justo el defecto S-d050fee5.
		Functions\when( 'get_transient' )->justReturn(
			array(
				'naruto'  => array( 'hits' => 0, 'fix' => '' ),
				'berserk' => array( 'hits' => 0, 'fix' => '' ),
			)
		);

		$GLOBALS['wpdb'] = new GapDismissalsWpdb(
			array(
				(object) array( 'query_norm' => 'naruto', 's' => 40, 's30' => 10 ),
				(object) array( 'query_norm' => 'berserk', 's' => 12, 's30' => 4 ),
			)
		);

		GapDismissals::dismiss( 'naruto' );

		$visibles = array_map(
			static fn( object $row ): string => $row->query_norm,
			DemandGaps::rows( $GLOBALS['wpdb'], 90, 8 )
		);

		$this->assertSame( array( 'berserk' ), $visibles );
	}
}

/** wpdb mínimo: ranking sintético con el NOT IN y el LIMIT aplicados. */
final class GapDismissalsWpdb {

	public string $prefix = 'wp_';

	public string $last_error = '';

	/** @var array<int, object> */
	private array $ranking;

	/** @param array<int, object> $ranking Filas ordenadas por demanda. */
	public function __construct( array $ranking ) {
		$this->ranking = $ranking;
	}

	/**
	 * @param string $query   Sentencia.
	 * @param mixed  ...$args Valores.
	 * @return string
	 */
	public function prepare( string $query, ...$args ): string {
		$values = 1 === count( $args ) && is_array( $args[0] ) ? $args[0] : $args;
		foreach ( $values as $value ) {
			$query = preg_replace( '/%[sd]/', is_int( $value ) ? (string) $value : "'" . $value . "'", $query, 1 ) ?? $query;
		}

		return $query;
	}

	/**
	 * @param string $query Sentencia ya interpolada.
	 * @return array<int, object>
	 */
	public function get_results( string $query ): array {
		$excluded = array();
		if ( preg_match( '/NOT IN \(([^)]*)\)/', $query, $matches ) ) {
			$excluded = array_map(
				static fn( string $v ): string => trim( trim( $v ), "'" ),
				explode( ',', $matches[1] )
			);
		}
		preg_match( '/LIMIT (\d+)/', $query, $limit );

		return array_slice(
			array_values(
				array_filter(
					$this->ranking,
					static fn( object $row ): bool => ! in_array( $row->query_norm, $excluded, true )
				)
			),
			0,
			(int) ( $limit[1] ?? 0 )
		);
	}
}
