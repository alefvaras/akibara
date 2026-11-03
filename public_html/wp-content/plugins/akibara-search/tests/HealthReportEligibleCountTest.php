<?php
/**
 * Cómo cuenta el panel los productos elegibles.
 *
 * `HealthReport::collect()` sólo necesita un NÚMERO, pero llamaba a
 * `RebuildJob::eligible_product_ids_snapshot()` y le hacía `count()`: traía la
 * lista completa de IDs publicados para tirarla acto seguido. El panel repolla
 * ese `collect()` cada 4 s mientras hay una reconstrucción en curso.
 *
 * Medido el 2026-08-23 en Docker contra la DB real (1.509 productos
 * publicados, media de 30 llamadas por lado):
 *   - lista de IDs + count(): 2,83 ms y un array de 21.534 bytes serializados.
 *   - COUNT(*):               0,64 ms.
 *
 * La invariante que fija esta suite es esa —el conteo se resuelve EN LA DB, sin
 * materializar la lista— más el contrato fail-closed: `verdict()` distingue 0
 * elegibles (catálogo vacío) de null (`eligible_source_failed`), así que una
 * lectura fallida no puede devolver 0.
 *
 * @package Akibara\Search\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Search\Tests;

use Akibara\Search\HealthReport;
use Brain\Monkey;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Doble mínimo de $wpdb: registra las sentencias y devuelve el escalar pedido.
 */
final class EligibleCountWpdb {

	public string $posts = 'wp_posts';

	public string $last_error = '';

	/** @var array<int, string> */
	public array $queries = array();

	/** @var mixed */
	public $var_value = '1509';

	public string $error_on_get_var = '';

	/**
	 * @param mixed ...$args Argumentos del prepare.
	 */
	public function prepare( string $query, ...$args ): string {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}

		$query   = str_replace( array( '%d', '%f' ), '%s', $query );
		$escaped = array_map(
			static fn( $value ): string => "'" . (string) $value . "'",
			$args
		);

		return vsprintf( $query, $escaped );
	}

	/**
	 * @return mixed
	 */
	public function get_var( string $query ) {
		$this->queries[] = $query;
		if ( '' !== $this->error_on_get_var ) {
			$this->last_error = $this->error_on_get_var;
			return null;
		}

		return $this->var_value;
	}

	/**
	 * @return array<int, mixed>
	 */
	public function get_col( string $query ): array {
		$this->queries[] = $query;
		return array();
	}
}

final class HealthReportEligibleCountTest extends TestCase {

	/** @var mixed */
	private $previous_wpdb;

	private bool $had_previous_wpdb = false;

	private EligibleCountWpdb $db;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->had_previous_wpdb = array_key_exists( 'wpdb', $GLOBALS );
		$this->previous_wpdb     = $GLOBALS['wpdb'] ?? null;
		$this->db                = new EligibleCountWpdb();
		$GLOBALS['wpdb']         = $this->db;
	}

	protected function tearDown(): void {
		if ( $this->had_previous_wpdb ) {
			$GLOBALS['wpdb'] = $this->previous_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @return int|null
	 */
	private function contar(): ?int {
		$method = new ReflectionMethod( HealthReport::class, 'eligible_product_count' );
		$method->setAccessible( true );

		return $method->invoke( null );
	}

	public function test_cuenta_en_la_base_sin_materializar_la_lista_de_ids(): void {
		self::assertSame( 1509, $this->contar() );

		$sql = implode( "\n", $this->db->queries );
		self::assertStringContainsString( 'COUNT(*)', $sql );
		self::assertStringNotContainsString(
			'SELECT ID FROM',
			$sql,
			'El panel sólo necesita el número: traer los IDs para contarlos costaba 2,83 ms por poll.'
		);
		self::assertCount( 1, $this->db->queries );
	}

	public function test_solo_cuenta_productos_publicados(): void {
		$this->contar();

		$sql = $this->db->queries[0];
		self::assertStringContainsString( "'product'", $sql );
		self::assertStringContainsString( "'publish'", $sql );
	}

	public function test_una_lectura_fallida_devuelve_null_y_nunca_cero(): void {
		$this->db->error_on_get_var = 'Table wp_posts doesn\'t exist';

		// 0 significaría «catálogo vacío» y dispararía el veredicto de cobertura
		// con el índice sano; null es lo que verdict() lee como no verificable.
		self::assertNull( $this->contar() );
	}
}
