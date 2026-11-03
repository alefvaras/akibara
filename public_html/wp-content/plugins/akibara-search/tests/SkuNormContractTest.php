<?php
/**
 * Por qué `sku_norm` no es una columna de sobra.
 *
 * Con el catálogo de HOY la columna parece redundante: `sku` y `sku_norm` son
 * idénticas en las 1.568 filas y la rama `sku_norm = %s` del OR no puede casar
 * nada que la primera no case. Eso es un hecho del import actual, no del
 * esquema: los SKU de este catálogo son ISBN-13, que en el mundo editorial se
 * publican CON guiones.
 *
 * Este test fija la CONDUCTA que la columna defiende —un SKU guardado con
 * guiones se encuentra escribiendo el código pelado— en vez de fijar el texto
 * de la consulta. Si alguien retira la rama `sku_norm` del OR, el primer caso
 * deja de encontrar el tomo y la suite lo dice.
 *
 * @package Akibara\Search\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Search\Tests;

use Akibara\Search\QueryEngine;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

require_once dirname( __DIR__ ) . '/src/QueryEngine.php';

/**
 * Doble de `$wpdb` con UNA fila de `docs` y el WHERE del fast-path evaluado de
 * verdad: sólo casa por las ramas que la sentencia trae escritas. Un doble que
 * devolviera el post_id sin mirar la consulta convertiría este test en un sello
 * de copy —pasaría igual con la columna borrada— y no serviría de nada.
 */
final class SkuCatalogWpdb {

	/** Prefijo de tablas, como el $wpdb real. */
	public string $prefix = 'wp_';

	/** Último error, como el $wpdb real. */
	public string $last_error = '';

	/**
	 * Sentencias recibidas por get_var(), en orden.
	 *
	 * @var array<int, string>
	 */
	public array $queries = array();

	/** SKU tal como quedó guardado en el índice. */
	public string $sku = '';

	/** Normalización persistida del SKU (sin guiones ni espacios). */
	public string $sku_norm = '';

	/** post_id de la única fila del catálogo de prueba. */
	public int $post_id = 0;

	/**
	 * @param string $sku      SKU guardado.
	 * @param string $sku_norm Normalización guardada.
	 * @param int    $post_id  Identidad de la fila.
	 */
	public function __construct( string $sku, string $sku_norm, int $post_id ) {
		$this->sku      = $sku;
		$this->sku_norm = $sku_norm;
		$this->post_id  = $post_id;
	}

	/**
	 * Interpola sin escapar de verdad: acá interesan los literales, no el
	 * escapado (eso lo cubre el gate de integración contra MariaDB).
	 *
	 * @param string $query   Sentencia con placeholders.
	 * @param mixed  ...$args Argumentos.
	 * @return string
	 */
	public function prepare( string $query, ...$args ): string {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}

		$escaped = array_map(
			static fn( $value ): string => "'" . str_replace( "'", "''", (string) $value ) . "'",
			$args
		);

		return vsprintf( str_replace( array( '%d', '%f' ), '%s', $query ), $escaped );
	}

	/**
	 * @param string $query Sentencia ya interpolada.
	 * @return string|null post_id de la fila que casa, o null.
	 */
	public function get_var( string $query ) {
		$this->queries[] = $query;

		return $this->row_matches( $query ) ? (string) $this->post_id : null;
	}

	/**
	 * ¿Alguna rama presente en la sentencia casa la fila?
	 *
	 * @param string $query Sentencia ya interpolada.
	 * @return bool
	 */
	private function row_matches( string $query ): bool {
		if ( in_array( $this->sku, $this->in_literals( $query, 'sku' ), true ) ) {
			return true;
		}

		return in_array( $this->sku_norm, $this->equality_literals( $query, 'sku_norm' ), true );
	}

	/**
	 * Literales de un `columna IN ( ... )`, o vacío si esa rama no está escrita.
	 *
	 * @param string $query  Sentencia.
	 * @param string $column Columna.
	 * @return array<int, string>
	 */
	private function in_literals( string $query, string $column ): array {
		if ( 1 !== preg_match( '/\b' . preg_quote( $column, '/' ) . '\s+IN\s*\(([^)]*)\)/i', $query, $match ) ) {
			return array();
		}

		preg_match_all( "/'([^']*)'/", $match[1], $literals );

		return $literals[1];
	}

	/**
	 * Literales de un `columna = '...'`, o vacío si esa rama no está escrita.
	 *
	 * @param string $query  Sentencia.
	 * @param string $column Columna.
	 * @return array<int, string>
	 */
	private function equality_literals( string $query, string $column ): array {
		preg_match_all( '/\b' . preg_quote( $column, '/' ) . "\s*=\s*'([^']*)'/i", $query, $literals );

		return $literals[1];
	}
}

final class SkuNormContractTest extends TestCase {

	/** @var array<string, mixed>|null */
	private $previous_wpdb;

	protected function setUp(): void {
		parent::setUp();
		$this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
	}

	protected function tearDown(): void {
		if ( null === $this->previous_wpdb ) {
			unset( $GLOBALS['wpdb'] );
		} else {
			$GLOBALS['wpdb'] = $this->previous_wpdb;
		}
		parent::tearDown();
	}

	/**
	 * @testdox Un SKU guardado CON guiones se encuentra escribiendo el ISBN pelado.
	 */
	public function test_un_sku_con_guiones_se_encuentra_con_el_codigo_pelado(): void {
		$db = $this->catalogo( '978-84-1234-567-8', '9788412345678' );

		self::assertSame( 4211, $this->fast_path( $db, '9788412345678' ) );
	}

	/**
	 * Contracara del anterior con el catálogo de hoy: el import carga los SKU
	 * sin guiones y el cliente pega el ISBN como lo publica la editorial.
	 *
	 * @testdox Con el SKU guardado sin guiones, el ISBN con guiones sigue encontrando el tomo.
	 */
	public function test_el_isbn_con_guiones_encuentra_el_sku_guardado_sin_guiones(): void {
		$db = $this->catalogo( '9788412345678', '9788412345678' );

		self::assertSame( 4211, $this->fast_path( $db, '978-84-1234-567-8' ) );
	}

	/**
	 * @testdox Una consulta que no parece código no llega a la base.
	 */
	public function test_una_consulta_de_texto_no_toca_la_base(): void {
		$db = $this->catalogo( '978-84-1234-567-8', '9788412345678' );

		self::assertNull( $this->fast_path( $db, 'ranma' ) );
		self::assertSame( array(), $db->queries );
	}

	/**
	 * @param string $sku      SKU guardado.
	 * @param string $sku_norm Normalización guardada.
	 * @return SkuCatalogWpdb
	 */
	private function catalogo( string $sku, string $sku_norm ): SkuCatalogWpdb {
		$db              = new SkuCatalogWpdb( $sku, $sku_norm, 4211 );
		$GLOBALS['wpdb'] = $db;

		return $db;
	}

	/**
	 * @param SkuCatalogWpdb $db  Conexión doble.
	 * @param string         $raw Consulta cruda del usuario.
	 * @return int|null
	 */
	private function fast_path( SkuCatalogWpdb $db, string $raw ): ?int {
		$method = new ReflectionMethod( QueryEngine::class, 'sku_fast_path' );
		$method->setAccessible( true );

		return $method->invoke( new QueryEngine( $db ), $raw );
	}
}
