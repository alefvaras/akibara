<?php
/**
 * Pruebas focales del fence MariaDB por conexión.
 *
 * @package Akibara\Search\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Search\Tests;

use Akibara\Search\WriteFence;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/src/WriteFence.php';

/** Doble secuencial mínimo de wpdb para funciones de lock. */
final class WriteFenceWpdb {

	public string $prefix = 'wp_';
	public string $dbname = 'akibara_test';
	public string $last_error = '';

	/** @var array<int, mixed> */
	public array $results;

	/** @var array<int, string> */
	public array $queries = array();

	/** @param array<int, mixed> $results Respuestas de get_var(). */
	public function __construct( array $results ) {
		$this->results = $results;
	}

	/** @param string $query SQL. @param mixed ...$args Valores. @return string */
	public function prepare( string $query, ...$args ): string {
		$query = str_replace( array( '%d', '%s' ), '%s', $query );
		$args  = array_map(
			static fn( $value ): string => "'" . str_replace( "'", "''", (string) $value ) . "'",
			$args
		);

		return vsprintf( $query, $args );
	}

	/** @param string $query SQL. @return mixed */
	public function get_var( string $query ) {
		$this->queries[] = $query;
		return array_shift( $this->results );
	}
}

/** @covers \Akibara\Search\WriteFence */
final class WriteFenceTest extends TestCase {

	/**
	 * @testdox Adquiere, verifica y libera exactamente una vez en la misma conexión.
	 */
	public function test_acquire_verify_and_release_once(): void {
		$wpdb  = new WriteFenceWpdb( array( '1', '71', '71', '71', '71', '71', '71', '71', '71', '1' ) );
		$fence = new WriteFence( $wpdb, 'akb-search-test' );

		self::assertTrue( $fence->acquire( 2 ) );
		self::assertFalse( $fence->acquire( 2 ), 'La misma instancia no puede adquirir anidado.' );
		self::assertTrue( $fence->is_owned() );
		$fence->assert_owned();
		self::assertTrue( $fence->release() );
		self::assertFalse( $fence->release(), 'Un release repetido no toca MariaDB.' );

		$release_queries = array_filter(
			$wpdb->queries,
			static fn( string $query ): bool => false !== strpos( $query, 'RELEASE_LOCK' )
		);
		self::assertCount( 1, $release_queries );
	}

	/**
	 * @testdox GET_LOCK sólo acepta el valor entero uno.
	 * @dataProvider rejected_get_lock_results
	 *
	 * @param mixed $result Respuesta MariaDB.
	 */
	public function test_get_lock_is_fail_closed( $result ): void {
		$fence = new WriteFence( new WriteFenceWpdb( array( $result ) ), 'akb-search-test' );

		self::assertFalse( $fence->acquire( 0 ) );
		self::assertFalse( $fence->is_owned() );
	}

	/** @return array<string, array<int, mixed>> */
	public static function rejected_get_lock_results(): array {
		return array(
			'busy zero' => array( '0' ),
			'error null' => array( null ),
			'error false' => array( false ),
			'non numeric' => array( 'true' ),
		);
	}

	/**
	 * @testdox Una reconexión pierde ownership y nunca libera el lock ajeno.
	 */
	public function test_reconnect_is_detected_without_foreign_release(): void {
		$wpdb  = new WriteFenceWpdb( array( '1', '71', '71', '72', '72' ) );
		$fence = new WriteFence( $wpdb, 'akb-search-test' );

		self::assertTrue( $fence->acquire() );
		self::assertFalse( $fence->is_owned() );
		self::assertFalse( $fence->release() );
		self::assertFalse(
			(bool) array_filter( $wpdb->queries, static fn( string $query ): bool => false !== strpos( $query, 'RELEASE_LOCK' ) ),
			'No debe liberar por nombre desde una conexión distinta.'
		);
	}

	/**
	 * @testdox Una sonda transitoria fallida conserva el estado y permite reintentar release.
	 */
	public function test_transient_connection_probe_failure_does_not_forget_ownership(): void {
		$wpdb  = new WriteFenceWpdb( array( '1', '71', '71', null, '71', '71', '1' ) );
		$fence = new WriteFence( $wpdb, 'akb-search-test' );

		self::assertTrue( $fence->acquire() );
		self::assertFalse( $fence->release() );
		self::assertFalse( $fence->acquire(), 'No debe adquirir recursivamente tras una sonda ambigua.' );
		self::assertTrue( $fence->release(), 'El mismo ownership debe poder liberarse al reintentar.' );
	}

	/**
	 * @testdox RELEASE_LOCK ambiguo tampoco borra ownership ni permite adquisición recursiva.
	 */
	public function test_failed_release_can_be_retried_without_recursive_acquire(): void {
		$wpdb  = new WriteFenceWpdb( array( '1', '71', '71', '71', '71', null, '71', '71', '1' ) );
		$fence = new WriteFence( $wpdb, 'akb-search-test' );

		self::assertTrue( $fence->acquire() );
		self::assertFalse( $fence->release() );
		self::assertFalse( $fence->acquire() );
		self::assertTrue( $fence->release() );
	}

	/**
	 * @testdox assert_owned falla cerrado cuando MariaDB ya no reporta dueño.
	 */
	public function test_assert_owned_throws_when_lock_is_lost(): void {
		$wpdb  = new WriteFenceWpdb( array( '1', '71', '71', '71', null ) );
		$fence = new WriteFence( $wpdb, 'akb-search-test' );
		self::assertTrue( $fence->acquire() );

		$this->expectException( \RuntimeException::class );
		$fence->assert_owned();
	}

	/**
	 * @testdox El nombre por defecto es estable por DB/prefix y nunca supera 64 bytes.
	 */
	public function test_default_name_is_stable_and_bounded(): void {
		$one = new WriteFence( new WriteFenceWpdb( array() ) );
		$two = new WriteFence( new WriteFenceWpdb( array() ) );

		self::assertSame( $one->name(), $two->name() );
		self::assertLessThanOrEqual( 64, strlen( $one->name() ) );
		self::assertLessThanOrEqual(
			64,
			strlen( ( new WriteFence( new WriteFenceWpdb( array() ), str_repeat( 'x', 100 ) ) )->name() )
		);
	}

	/**
	 * @testdox El nombre por defecto no intenta leer la propiedad protegida dbname de wpdb.
	 */
	public function test_default_name_does_not_read_protected_wpdb_dbname(): void {
		$wpdb = new class() {
			public string $prefix = 'wp_';
			protected string $dbname = 'inaccessible';
		};

		$fence = new WriteFence( $wpdb );

		self::assertLessThanOrEqual( 64, strlen( $fence->name() ) );
	}
}
