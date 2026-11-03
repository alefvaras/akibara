<?php
/**
 * Contrato del radar de demanda («Los más buscados que no tienes»).
 *
 * El defecto era de ORDEN: el SQL recortaba a 20 y los dos filtros —typos que
 * el corrector rescata, y descartes del dueño— corrían después. Bastaba con que
 * la cabeza del ranking se llenara de rescates o de descartes para que huecos
 * REALES de más abajo no llegaran nunca a la pantalla; la lista se vaciaba sola
 * a medida que el dueño la usaba.
 *
 * @package Akibara\Search\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Search\Tests;

use Akibara\Search\DemandGaps;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/src/SearchAdminSection.php';

/** wpdb que devuelve un ranking sintético y registra la consulta emitida. */
final class DemandGapsWpdb {

	public string $prefix = 'wp_';

	public string $last_error = '';

	/** @var array<int, string> */
	public array $queries = array();

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
	 * Emula el WHERE ... NOT IN y el LIMIT sobre el ranking sintético.
	 *
	 * @param string $query Sentencia ya interpolada.
	 * @return array<int, object>
	 */
	public function get_results( string $query ): array {
		$this->queries[] = $query;

		$excluded = array();
		if ( preg_match( '/NOT IN \(([^)]*)\)/', $query, $matches ) ) {
			$excluded = array_map(
				static fn( string $v ): string => trim( trim( $v ), "'" ),
				explode( ',', $matches[1] )
			);
		}
		preg_match( '/LIMIT (\d+)/', $query, $limit );

		$rows = array_values(
			array_filter(
				$this->ranking,
				static fn( object $row ): bool => ! in_array( $row->query_norm, $excluded, true )
			)
		);

		return array_slice( $rows, 0, (int) ( $limit[1] ?? 0 ) );
	}
}

final class DemandGapsTest extends TestCase {

	/** @var array<string, mixed> */
	private array $options = array();

	/** @var mixed */
	private $previous_wpdb;
	private bool $had_previous_wpdb = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->options = array();

		Functions\when( 'get_option' )->alias(
			fn( string $name, $default = false ) => $this->options[ $name ] ?? $default
		);
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value ): bool {
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );

		// Install::qlog_table() resuelve el prefijo por el $wpdb global.
		$this->had_previous_wpdb = array_key_exists( 'wpdb', $GLOBALS );
		$this->previous_wpdb     = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']         = new DemandGapsWpdb( array() );
	}

	protected function tearDown(): void {
		// Sin esto el doble se FUGA al resto del suite: `DemandGapsWpdb` no
		// declara `get_var()`, así que el siguiente test que tocara el fence
		// (PublicationProtocolTest → reconcile_on_init → WriteFence::acquire)
		// moría con «Call to undefined method». Flake medido: ~2 de cada 25
		// corridas con `--order-by=random`.
		if ( $this->had_previous_wpdb ) {
			$GLOBALS['wpdb'] = $this->previous_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		\Patchwork\restoreAll();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * El motor MIDE y contesta: por defecto, cero resultados para todo (huecos
	 * reales), salvo lo que se indique en `$con_resultados`. `$sin_medir` son las
	 * búsquedas que el motor no llegó a medir (el índice dejó de responder).
	 *
	 * Hasta el 2026-09-19 estas pruebas no doblaban el motor: corrían contra un
	 * `$wpdb` que no sabe buscar, el motor se declaraba no disponible, no había
	 * NINGÚN veredicto… y la lista salía igual, porque la ausencia de veredicto
	 * se leía como «hueco real». Pasaban gracias al defecto que S-d050fee5
	 * describe: un doble que no modelaba producción.
	 *
	 * @param array<string,int> $con_resultados Búsqueda → hits.
	 * @param array<int,string> $sin_medir      Búsquedas sin veredicto.
	 */
	private function el_motor_mide( array $con_resultados = array(), array $sin_medir = array() ): void {
		\Patchwork\redefine(
			\Akibara\Search\SearchAdminSection::class . '::zero_result_rescues',
			static function ( array $queries ) use ( $con_resultados, $sin_medir ): array {
				$out = array();
				foreach ( $queries as $q ) {
					if ( in_array( $q, $sin_medir, true ) ) {
						continue;
					}
					$out[ $q ] = array(
						'hits' => $con_resultados[ $q ] ?? 0,
						'fix'  => '',
					);
				}
				return $out;
			}
		);
	}

	/**
	 * @testdox Los descartes se restan ANTES del recorte: huecos reales de más abajo siguen llegando.
	 */
	public function test_los_descartes_no_vacian_la_lista(): void {
		// 30 búsquedas sin resultados; las 25 más buscadas ya fueron descartadas.
		$ranking = array();
		for ( $i = 1; $i <= 30; ++$i ) {
			$ranking[] = (object) array( 'query_norm' => 'q' . $i, 's' => 1000 - $i, 's30' => 0 );
		}
		$descartes = array();
		for ( $i = 1; $i <= 25; ++$i ) {
			$descartes[ 'q' . $i ] = 1756000000;
		}
		$this->options['akb_search_gap_descartadas'] = $descartes;
		$this->el_motor_mide();

		$db   = new DemandGapsWpdb( $ranking );
		$rows = DemandGaps::rows( $db, 90, 5 );

		self::assertSame(
			array( 'q26', 'q27', 'q28', 'q29', 'q30' ),
			array_map( static fn( object $row ): string => $row->query_norm, $rows ),
			'Con el recorte delante de los filtros la lista salía vacía.'
		);
		self::assertStringContainsString( 'NOT IN', $db->queries[0], 'El filtro exacto viaja al SQL.' );
	}

	/**
	 * @testdox El pool que pide el SQL tiene holgura sobre el límite, acotada por arriba y por abajo.
	 */
	public function test_el_pool_tiene_holgura_acotada(): void {
		$db = new DemandGapsWpdb( array() );

		DemandGaps::rows( $db, 90, 1 );
		self::assertStringContainsString( 'LIMIT 20', $db->queries[0], 'Piso: nunca menos de 20 candidatos.' );

		$db = new DemandGapsWpdb( array() );
		DemandGaps::rows( $db, 90, 10 );
		self::assertStringContainsString( 'LIMIT 40', $db->queries[0], 'Holgura sobre el límite pedido.' );

		$db = new DemandGapsWpdb( array() );
		DemandGaps::rows( $db, 90, 500 );
		self::assertStringContainsString( 'LIMIT 100', $db->queries[0], 'Techo: el rescatador no se desboca.' );
	}

	/** @testdox Sin descartes el SQL no lleva un NOT IN vacío, que no casaría nada. */
	public function test_sin_descartes_no_hay_not_in(): void {
		$db = new DemandGapsWpdb( array() );

		self::assertSame( array(), DemandGaps::rows( $db, 90, 8 ) );
		self::assertStringNotContainsString( 'NOT IN', $db->queries[0] );
		self::assertStringContainsString( 'hits = 0', $db->queries[0], 'Sólo búsquedas sin ningún resultado.' );
	}

	/**
	 * `zero_result_rescues()` corta cuando el índice deja de responder y devuelve
	 * sólo lo que alcanzó a medir — a propósito: no inventa veredictos. Pero aquí
	 * la ausencia se leía como `hits = 0`, o sea «hueco real»: una lectura rota
	 * del índice convertía la tabla entera en «tu lista corta de compra y
	 * fichas» (hallazgo S-d050fee5, 2026-09-19).
	 *
	 * @testdox Una búsqueda que el motor no llegó a medir NO es un hueco real.
	 */
	public function test_lo_que_no_se_midio_no_es_un_hueco(): void {
		$ranking = array(
			(object) array( 'query_norm' => 'berserk deluxe', 's' => 90, 's30' => 10 ),
			(object) array( 'query_norm' => 'vagabond', 's' => 80, 's30' => 9 ),
			(object) array( 'query_norm' => 'narutto', 's' => 70, 's30' => 8 ),
		);

		// El índice se cayó tras medir la primera: las otras dos no tienen veredicto.
		$this->el_motor_mide( array(), array( 'vagabond', 'narutto' ) );

		$rows = DemandGaps::rows( new DemandGapsWpdb( $ranking ), 90, 5 );

		self::assertSame(
			array( 'berserk deluxe' ),
			array_map( static fn( object $row ): string => $row->query_norm, $rows ),
			'Sólo lo MEDIDO como cero entra en la lista de compra.'
		);
	}

	/** @testdox Con el índice caído del todo, la lista de compra queda vacía en vez de llenarse de falsos huecos. */
	public function test_sin_ningun_veredicto_la_lista_queda_vacia(): void {
		$ranking = array(
			(object) array( 'query_norm' => 'one piece', 's' => 500, 's30' => 50 ),
			(object) array( 'query_norm' => 'naruto', 's' => 400, 's30' => 40 ),
		);

		$this->el_motor_mide( array(), array( 'one piece', 'naruto' ) );

		self::assertSame( array(), DemandGaps::rows( new DemandGapsWpdb( $ranking ), 90, 5 ), '«One Piece» no es un título que falte: es un índice que no contestó.' );
	}

	/** @testdox Lo que el corrector de tipeo ya resuelve sigue fuera de la lista. */
	public function test_lo_que_el_corrector_resuelve_no_es_un_hueco(): void {
		$ranking = array(
			(object) array( 'query_norm' => 'narutto', 's' => 70, 's30' => 8 ),
			(object) array( 'query_norm' => 'vagabond', 's' => 60, 's30' => 7 ),
		);

		$this->el_motor_mide( array( 'narutto' => 7 ) );

		self::assertSame(
			array( 'vagabond' ),
			array_map( static fn( object $row ): string => $row->query_norm, DemandGaps::rows( new DemandGapsWpdb( $ranking ), 90, 5 ) )
		);
	}

	/**
	 * El veredicto «hueco real» se cacheaba una hora con una clave que sólo
	 * dependía de las búsquedas: el dueño creaba la ficha del título que el radar
	 * le pedía y el radar lo seguía llamando hueco (hallazgo S-6829ad5f).
	 *
	 * @testdox La caché del veredicto se sella con la generación del índice: si el índice publica, se vuelve a medir.
	 */
	public function test_el_veredicto_cacheado_muere_con_la_generacion_del_indice(): void {
		$claves = array();
		Functions\when( 'get_transient' )->alias(
			static function ( string $clave ) use ( &$claves ) {
				$claves[] = $clave;
				return array( 'vagabond' => array( 'hits' => 0, 'fix' => '' ) ); // Acierto de caché: no se llega al motor.
			}
		);

		$this->options[ \Akibara\Search\Install::OPTION_INDEX_GEN ] = 'gen-antes-de-crear-la-ficha';
		\Akibara\Search\SearchAdminSection::zero_result_rescues( array( 'vagabond' ) );

		$this->options[ \Akibara\Search\Install::OPTION_INDEX_GEN ] = 'gen-despues-de-crear-la-ficha';
		\Akibara\Search\SearchAdminSection::zero_result_rescues( array( 'vagabond' ) );

		self::assertCount( 2, $claves );
		self::assertNotSame( $claves[0], $claves[1], 'Misma búsqueda, otro índice: otra clave.' );

		\Akibara\Search\SearchAdminSection::zero_result_rescues( array( 'vagabond' ) );
		self::assertSame( $claves[1], $claves[2], 'Y con el mismo índice, la misma clave: la caché sigue ahorrando.' );
	}
}
