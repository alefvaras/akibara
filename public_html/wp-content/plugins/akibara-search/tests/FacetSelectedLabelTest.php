<?php
/**
 * Contrato de la etiqueta de un filtro SELECCIONADO que quedó sin conteo.
 *
 * El conteo de una faceta se calcula con la base «todos los filtros MENOS
 * ésta». Cuando la combinación no deja productos, el valor elegido no vuelve en
 * ninguna fila y `mark_selected()` tiene que sintetizar su opción para que el
 * chip siga existiendo (es la única forma de deshacer). Hasta 0.1.22 esa opción
 * sintética se quedaba con el SLUG crudo, y eso se veía justo en la pantalla de
 * 0 resultados: «Editorial: milky-way», «Demografía: manhwa».
 *
 * Este test fija las dos mitades de la corrección:
 *   1. la etiqueta legible se resuelve desde el índice;
 *   2. si esa lectura falla, se cae al slug SIN apagar las facetas — el lookup
 *      es cosmético y no puede tumbar el catálogo.
 *
 * @package Akibara\Search\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Search\Tests;

use Akibara\Search\FacetService;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

require_once dirname( __DIR__ ) . '/src/FilterSet.php';
require_once dirname( __DIR__ ) . '/src/Install.php';
require_once dirname( __DIR__ ) . '/src/FacetService.php';

/** wpdb mínimo para el lookup de etiquetas. */
final class FacetLabelWpdb {

	public string $prefix = 'wp_';

	public string $last_error = '';

	/** @var array<int,string> */
	public array $queries = array();

	/** @var array<int,object>|null Filas a devolver; null = devolver algo inválido. */
	public ?array $rows = array();

	/** Si es true, `get_results()` lanza. */
	public bool $throws = false;

	/** Si es true, deja `last_error` tras la lectura. */
	public bool $sets_error = false;

	/**
	 * @param string $query   Sentencia.
	 * @param mixed  ...$args Argumentos.
	 */
	public function prepare( $query, ...$args ): string {
		$flat = array();
		foreach ( $args as $arg ) {
			if ( is_array( $arg ) ) {
				foreach ( $arg as $item ) {
					$flat[] = $item;
				}
				continue;
			}
			$flat[] = $arg;
		}
		foreach ( $flat as $value ) {
			$query = preg_replace( '/%s/', (string) $value, (string) $query, 1 ) ?? (string) $query;
		}

		return (string) $query;
	}

	/**
	 * @param string $query Sentencia.
	 * @return array<int,object>|null
	 */
	public function get_results( $query ) {
		$this->queries[] = (string) $query;
		if ( $this->throws ) {
			throw new \RuntimeException( 'db caída' );
		}
		if ( $this->sets_error ) {
			$this->last_error = 'lectura fallida';
		}

		return $this->rows;
	}
}

/**
 * @since 0.1.23
 */
final class FacetSelectedLabelTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'esc_sql' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function service( FacetLabelWpdb $db ): FacetService {
		// `Install::facets_table()` resuelve el nombre desde el $wpdb global.
		$GLOBALS['wpdb'] = $db;

		return new FacetService( $db );
	}

	/**
	 * @param array<string,array<string,mixed>> $options
	 * @param array<int,string>                 $selected
	 * @return array<string,array<string,mixed>>
	 */
	private function markSelected( FacetService $service, array $options, array $selected, string $facet ): array {
		$method = new ReflectionMethod( FacetService::class, 'mark_selected' );
		$method->setAccessible( true );

		return $method->invoke( $service, $options, $selected, $facet );
	}

	private function isAvailable( FacetService $service ): bool {
		$property = new ReflectionProperty( FacetService::class, 'available' );
		$property->setAccessible( true );

		return (bool) $property->getValue( $service );
	}

	public function test_selected_value_without_count_gets_its_human_label(): void {
		$db       = new FacetLabelWpdb();
		$db->rows = array( (object) array( 'value' => 'milky-way', 'label' => 'Milky Way' ) );

		$options = $this->markSelected( $this->service( $db ), array(), array( 'milky-way' ), 'editorial' );

		self::assertSame( 'Milky Way', $options['milky-way']['label'] );
		self::assertSame( 0, $options['milky-way']['count'] );
		self::assertTrue( $options['milky-way']['selected'] );
		self::assertCount( 1, $db->queries, 'Una sola consulta por faceta, no una por valor.' );
	}

	public function test_all_missing_values_resolve_in_a_single_query(): void {
		$db       = new FacetLabelWpdb();
		$db->rows = array(
			(object) array( 'value' => 'manhwa', 'label' => 'Manhwa' ),
			(object) array( 'value' => 'josei', 'label' => 'Josei' ),
		);

		$options = $this->markSelected( $this->service( $db ), array(), array( 'manhwa', 'josei' ), 'demografia' );

		self::assertSame( 'Manhwa', $options['manhwa']['label'] );
		self::assertSame( 'Josei', $options['josei']['label'] );
		self::assertCount( 1, $db->queries );
	}

	public function test_counted_options_do_not_trigger_the_lookup(): void {
		$db      = new FacetLabelWpdb();
		$options = $this->markSelected(
			$this->service( $db ),
			array( 'milky-way' => array( 'value' => 'milky-way', 'label' => 'Milky Way', 'count' => 12, 'selected' => false ) ),
			array( 'milky-way' ),
			'editorial'
		);

		self::assertTrue( $options['milky-way']['selected'] );
		self::assertSame( 12, $options['milky-way']['count'] );
		self::assertSame( array(), $db->queries, 'Sin valores huérfanos no se consulta nada.' );
	}

	public function test_lookup_failure_falls_back_to_the_slug_and_keeps_facets_alive(): void {
		$db          = new FacetLabelWpdb();
		$db->throws  = true;
		$service     = $this->service( $db );

		$options = $this->markSelected( $service, array(), array( 'milky-way' ), 'editorial' );

		self::assertSame( 'milky-way', $options['milky-way']['label'], 'Cae al slug: visible y removible gana a invisible.' );
		self::assertTrue( $options['milky-way']['selected'] );
		self::assertTrue(
			$this->isAvailable( $service ),
			'Un fallo del lookup cosmético NO puede apagar las facetas del catálogo.'
		);
	}

	public function test_read_error_also_falls_back_without_disabling_the_service(): void {
		$db             = new FacetLabelWpdb();
		$db->sets_error = true;
		$db->rows       = array();
		$service        = $this->service( $db );

		$options = $this->markSelected( $service, array(), array( 'milky-way' ), 'editorial' );

		self::assertSame( 'milky-way', $options['milky-way']['label'] );
		self::assertTrue( $this->isAvailable( $service ) );
		self::assertSame( '', $db->last_error, 'El error propio se limpia para no contaminar la próxima lectura.' );
	}

	public function test_unknown_value_keeps_the_slug(): void {
		$db       = new FacetLabelWpdb();
		$db->rows = array();

		$options = $this->markSelected( $this->service( $db ), array(), array( 'valor-fantasma' ), 'editorial' );

		self::assertSame( 'valor-fantasma', $options['valor-fantasma']['label'] );
	}
}
