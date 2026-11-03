<?php
/**
 * Contrato de las cachés auto-invalidantes del buscador.
 *
 * El vocabulario del fuzzy y los counts de facetas metían el estado del índice
 * DENTRO de la clave del transient. Cada bump de generación —y cada hora, con
 * Novedad ON— estrenaba fila y no borraba la anterior: 23 copias vivas del
 * mismo vocabulario medidas en la BD (217 KB), y una fila de counts por
 * contexto × hora × generación, sin techo ni poda. Con el object cache
 * persistente ya encendido, el GC de core (`delete_expired_transients`) no
 * corre nunca, así que esas filas dejaron de ser temporales.
 *
 * La invariante: la CLAVE es estable (una fila por contexto, que se pisa a sí
 * misma) y el estado viaja en el VALOR, de modo que un estado que no calza es
 * un miss. No es lo mismo que «no cachear»: la garantía TOCTOU se conserva —
 * un reindex a mitad del conteo sigue descartando el resultado desgarrado.
 *
 * @package Akibara\Search\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Search\Tests;

use Akibara\Search\FacetRegistry;
use Akibara\Search\FacetService;
use Akibara\Search\FilterSet;
use Akibara\Search\Install;
use Akibara\Search\Novedad;
use Akibara\Search\SemanticPublication;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

require_once dirname( __DIR__ ) . '/src/FilterSet.php';
require_once dirname( __DIR__ ) . '/src/FacetService.php';

/** wpdb mínimo: devuelve el sello del índice que el caso quiera. */
final class RotatingTransientsWpdb {

	public string $prefix = 'wp_';

	public string $posts = 'wp_posts';

	public string $last_error = '';

	/** @var array<int, string> */
	public array $queries = array();

	/** @var array<int, string> Sentencias que llegaron a `prepare()`. */
	public array $prepared = array();

	/** Sello que devuelve la sonda sobre `docs`. */
	public object $stamp;

	public function __construct() {
		$this->stamp = (object) array( 'c' => 1568, 'm' => '2026-08-31 07:00:00' );
	}

	/** @param mixed ...$args Argumentos. */
	public function prepare( string $query, ...$args ): string {
		$this->prepared[] = $query;
		return $query . implode( '|', array_map( 'strval', $args ) );
	}

	/** @return object|null */
	public function get_row( string $query ) {
		$this->queries[] = $query;
		return $this->stamp;
	}

	/** @return array<int, object> */
	public function get_results( string $query ): array {
		$this->queries[] = $query;
		return array();
	}
}

final class RotatingTransientsTest extends TestCase {

	/** @var array<string, mixed> */
	private array $store = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->store = array();

		$store = &$this->store;
		Functions\when( 'get_transient' )->alias(
			static fn( string $key ) => array_key_exists( $key, $store ) ? $store[ $key ] : false
		);
		Functions\when( 'set_transient' )->alias(
			static function ( string $key, $value ) use ( &$store ): bool {
				$store[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'taxonomy_exists' )->justReturn( false );

		// Install::docs_table() resuelve el prefijo por el $wpdb global.
		$GLOBALS['wpdb'] = new RotatingTransientsWpdb();

		SemanticPublication::reset_authoritative_state_for_tests();
		SemanticPublication::adopt_authoritative_snapshot(
			array(
				Install::OPTION_INDEX_GEN => 'gen-A',
				Novedad::OPTION           => 'no',
				FacetRegistry::OPTION_KEY => serialize(
					array( 'categoria' => array( 'label' => 'Categoría', 'type' => 'taxonomy', 'taxonomy' => 'product_cat' ) )
				),
			)
		);
	}

	protected function tearDown(): void {
		SemanticPublication::reset_authoritative_state_for_tests();
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @testdox La clave de counts depende sólo del contexto: no del estado del índice ni del reloj. */
	public function test_la_clave_de_counts_es_estable_por_contexto(): void {
		$db      = new RotatingTransientsWpdb();
		$service = new FacetService( $db );

		$landing = $this->cache_key( $service, new FilterSet( array() ) );
		$archive = $this->cache_key( $service, new FilterSet( array( 'categoria' => array( 'manga' ) ) ) );

		self::assertNotSame( $landing, $archive, 'Dos contextos distintos no comparten fila.' );
		self::assertSame( $landing, $this->cache_key( $service, new FilterSet( array() ) ) );
		self::assertStringStartsWith( 'akb_lc_', $landing, 'uninstall.php barre por este prefijo.' );
		self::assertSame( array(), $db->queries, 'La clave no puede costar una lectura: el estado va en el valor.' );
	}

	/**
	 * @testdox Reindexar pisa la fila del contexto en vez de estrenar una nueva.
	 *
	 * Éste es el defecto medido: N estados del índice dejaban N filas vivas del
	 * MISMO contexto, ninguna borrada, y nadie las barría.
	 */
	public function test_un_estado_nuevo_pisa_la_fila_en_vez_de_agregarla(): void {
		$db      = new RotatingTransientsWpdb();
		$service = new FacetService( $db );
		$filters = new FilterSet( array() );
		$key     = $this->cache_key( $service, $filters );

		$this->store_counts( $service, $key, $this->stamp( $service ), array( 'primero' ) );
		self::assertSame( array( $key ), array_keys( $this->store ) );
		self::assertSame( array( 'primero' ), $this->store[ $key ]['data'] );

		// Reindex: cambia el estado del índice, no el contexto.
		$db->stamp = (object) array( 'c' => 1570, 'm' => '2026-08-31 09:00:00' );
		$this->store_counts( $service, $key, $this->stamp( $service ), array( 'segundo' ) );

		self::assertSame( array( $key ), array_keys( $this->store ), 'Una fila por contexto, siempre.' );
		self::assertSame( array( 'segundo' ), $this->store[ $key ]['data'] );
	}

	/**
	 * @testdox Un reindex a mitad del conteo descarta el resultado desgarrado.
	 *
	 * La garantía TOCTOU que protegía la clave-foto tiene que sobrevivir al
	 * cambio: ahora la comparación es contra el sello, no contra la clave.
	 */
	public function test_un_reindex_a_mitad_del_conteo_no_se_archiva(): void {
		$db      = new RotatingTransientsWpdb();
		$service = new FacetService( $db );
		$key     = $this->cache_key( $service, new FilterSet( array() ) );
		$stamp   = $this->stamp( $service );

		$db->stamp = (object) array( 'c' => 1571, 'm' => '2026-08-31 10:00:00' );
		$this->store_counts( $service, $key, $stamp, array( 'desgarrado' ) );

		self::assertSame( array(), $this->store, 'Counts mezclados no pueden quedar sellados como vigentes.' );
	}

	/**
	 * @testdox Sin facetas activas el conteo no paga un DISTINCT que no puede cambiar nada.
	 *
	 * La PK de la tabla espejo es (facet, post_id, value): dentro de un grupo
	 * (facet, value) el post_id es único, así que DISTINCT sólo tiene sentido
	 * cuando `facet_joins()` multiplica filas. Es la query más cara del plugin
	 * y el camino del landing: 39,02 → 22,14 ms medidos.
	 */
	public function test_sin_joins_el_conteo_no_paga_distinct(): void {
		$db = new RotatingTransientsWpdb();

		$this->count_facets( new FacetService( $db ), new FilterSet( array() ) );
		$sin_joins = $db->prepared;

		$db = new RotatingTransientsWpdb();
		$this->count_facets(
			new FacetService( $db ),
			new FilterSet( array( 'categoria' => array( 'manga', 'comic' ) ) )
		);
		$con_joins = $db->prepared;

		self::assertNotSame( array(), $sin_joins, 'La consulta tiene que haberse preparado.' );
		self::assertStringContainsString( 'COUNT(*)', $sin_joins[0] );
		self::assertStringNotContainsString( 'COUNT(DISTINCT', $sin_joins[0] );

		self::assertNotSame( array(), $con_joins, 'La consulta tiene que haberse preparado.' );
		self::assertStringContainsString(
			'COUNT(DISTINCT fx.post_id)',
			$con_joins[0],
			'Con multi-selección un producto SÍ multiplica filas: el DISTINCT se conserva.'
		);
	}

	/** @testdox Un valor cacheado con otro estado del índice es un miss, no un dato viejo servido. */
	public function test_un_sello_ajeno_no_se_sirve(): void {
		$service = new FacetService( new RotatingTransientsWpdb() );

		$read = new ReflectionMethod( FacetService::class, 'cached_counts' );
		$read->setAccessible( true );

		$this->store['akb_lc_x'] = array( 'stamp' => 'estado-A', 'data' => array( 'lo que sea' ) );

		self::assertNull( $read->invoke( $service, 'akb_lc_x', 'estado-B' ), 'Otro estado del índice es un miss.' );
		self::assertNull( $read->invoke( $service, 'akb_lc_x', '' ), 'Sin sello legible no se sirve caché.' );

		// Forma legada (payload plano, sin sello): tampoco se sirve.
		$this->store['akb_lc_x'] = array( 'categoria' => array() );
		self::assertNull( $read->invoke( $service, 'akb_lc_x', 'estado-A' ) );
	}

	/**
	 * @param FacetService $service Servicio bajo prueba.
	 * @param FilterSet    $filters Facetas activas del set base.
	 * @return void
	 */
	private function count_facets( FacetService $service, FilterSet $filters ): void {
		$method = new ReflectionMethod( FacetService::class, 'count_facets' );
		$method->setAccessible( true );
		$method->invoke( $service, array( 'categoria' ), $filters, null );
	}

	/**
	 * @param FacetService $service Servicio bajo prueba.
	 * @param FilterSet    $filters Contexto.
	 * @return string
	 */
	private function cache_key( FacetService $service, FilterSet $filters ): string {
		$method = new ReflectionMethod( FacetService::class, 'counts_cache_key' );
		$method->setAccessible( true );

		return (string) $method->invoke( $service, $filters );
	}

	/**
	 * @param FacetService $service Servicio bajo prueba.
	 * @return string
	 */
	private function stamp( FacetService $service ): string {
		$method = new ReflectionMethod( FacetService::class, 'counts_cache_stamp' );
		$method->setAccessible( true );

		return (string) $method->invoke( $service );
	}

	/**
	 * @param FacetService      $service Servicio bajo prueba.
	 * @param string            $key     Clave estable.
	 * @param string            $stamp   Sello leído al empezar a contar.
	 * @param array<int, mixed> $counts  Payload.
	 * @return void
	 */
	private function store_counts( FacetService $service, string $key, string $stamp, array $counts ): void {
		$method = new ReflectionMethod( FacetService::class, 'store_counts' );
		$method->setAccessible( true );
		$method->invoke( $service, $key, $stamp, $counts );
	}
}
