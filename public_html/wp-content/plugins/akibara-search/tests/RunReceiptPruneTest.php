<?php
/**
 * La bitácora de publicaciones editoriales deja de crecer sin techo.
 *
 * Cada revisión publicada escribe un receipt inmutable propio
 * (`akb_search_editorial_run_receipt_<hash>`) y NADA los borraba, pese al
 * comentario del código que prometía «cleanup del supersedido»: 49 filas /
 * 40 KB en 22 días, medido, y creciendo con la actividad comercial. Con el
 * object cache persistente encendido esas filas tampoco caducan solas.
 *
 * Lo que este test fija: la poda conserva las revisiones RECIENTES, tira las
 * viejas, y nunca toca el receipt que se acaba de escribir.
 *
 * @package Akibara\Search\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Search\Tests;

use Akibara\Search\EditorialDiscountPublication;
use Akibara\Search\Support\OptionCodec;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/** wpdb con una tabla de options en memoria: LIKE por prefijo y DELETE por nombre. */
final class RunReceiptPruneWpdb {

	public string $prefix = 'wp_';

	public string $options = 'wp_options';

	public string $last_error = '';

	/** @var array<string, string> */
	public array $rows = array();

	/** @param mixed ...$args Valores. */
	public function prepare( string $query, ...$args ): string {
		foreach ( $args as $arg ) {
			$replacement = is_int( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'";
			$query       = (string) preg_replace( '/%[sd]/', $replacement, $query, 1 );
		}

		return $query;
	}

	public function esc_like( string $text ): string {
		return addcslashes( $text, '_%\\' );
	}

	/** @return array<int, object> */
	public function get_results( string $query ): array {
		preg_match( "/LIKE '((?:''|[^'])*)%'/", $query, $like );
		preg_match( "/option_name <> '((?:''|[^'])*)'/", $query, $skip );
		$prefix  = str_replace( array( "''", '\\_' ), array( "'", '_' ), $like[1] ?? '' );
		$exclude = str_replace( "''", "'", $skip[1] ?? '' );

		$out = array();
		foreach ( $this->rows as $name => $value ) {
			if ( 0 !== strpos( $name, $prefix ) || $name === $exclude ) {
				continue;
			}
			$out[] = (object) array( 'option_name' => $name, 'option_value' => $value );
		}

		return $out;
	}

	/**
	 * @param string               $table Tabla.
	 * @param array<string, mixed> $where Condiciones.
	 * @return int
	 */
	public function delete( string $table, array $where ): int {
		$name = (string) ( $where['option_name'] ?? '' );
		if ( ! array_key_exists( $name, $this->rows ) ) {
			return 0;
		}
		unset( $this->rows[ $name ] );

		return 1;
	}
}

final class RunReceiptPruneTest extends TestCase {

	private RunReceiptPruneWpdb $db;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_cache_delete' )->justReturn( true );

		$this->db        = new RunReceiptPruneWpdb();
		$GLOBALS['wpdb'] = $this->db;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @testdox La bitácora se recorta a las revisiones recientes y respeta la recién escrita. */
	public function test_la_bitacora_se_recorta_a_las_revisiones_recientes(): void {
		$prefix = 'akb_search_editorial_run_receipt_';

		// 40 revisiones históricas, de la más vieja a la más nueva.
		for ( $i = 1; $i <= 40; $i++ ) {
			$this->db->rows[ $prefix . sprintf( '%03d', $i ) ] = OptionCodec::encode(
				array( 'purge_all_dispatched_at' => 1756000000 + $i )
			);
		}
		// Una fila ilegible: entra con sello 0 y es lo primero que sobra.
		$this->db->rows[ $prefix . 'corrupta' ] = 'no-es-un-receipt';
		// Y la recién escrita, con el sello más VIEJO de todos a propósito.
		$this->db->rows[ $prefix . 'recien' ] = OptionCodec::encode(
			array( 'purge_all_dispatched_at' => 1 )
		);
		// Una option ajena al prefijo: la poda no puede alcanzarla.
		$this->db->rows['akb_search_db_version'] = '1.9.0';

		$prune = new ReflectionMethod( EditorialDiscountPublication::class, 'prune_run_receipts' );
		$prune->setAccessible( true );
		$prune->invoke( null, $prefix . 'recien' );

		$restantes = array_keys( $this->db->rows );

		self::assertContains( $prefix . 'recien', $restantes, 'La publicación en curso jamás se poda.' );
		self::assertContains( 'akb_search_db_version', $restantes, 'La poda no sale de su prefijo.' );
		self::assertNotContains( $prefix . 'corrupta', $restantes, 'Un payload ilegible es lo primero que sobra.' );

		// Sobreviven las 20 más recientes por sello, más la recién escrita.
		self::assertContains( $prefix . '040', $restantes );
		self::assertContains( $prefix . '021', $restantes );
		self::assertNotContains( $prefix . '020', $restantes );
		self::assertNotContains( $prefix . '001', $restantes );

		$receipts = array_filter( $restantes, static fn( string $n ): bool => 0 === strpos( $n, $prefix ) );
		self::assertCount( 21, $receipts, 'El techo es de filas: publicar más no hace crecer la tabla.' );
	}

	/** @testdox Por debajo del techo no se borra nada. */
	public function test_por_debajo_del_techo_no_se_borra_nada(): void {
		$prefix = 'akb_search_editorial_run_receipt_';
		for ( $i = 1; $i <= 5; $i++ ) {
			$this->db->rows[ $prefix . $i ] = OptionCodec::encode( array( 'purge_all_dispatched_at' => 1756000000 + $i ) );
		}

		$prune = new ReflectionMethod( EditorialDiscountPublication::class, 'prune_run_receipts' );
		$prune->setAccessible( true );
		$prune->invoke( null, $prefix . 'recien' );

		self::assertCount( 5, $this->db->rows );
	}
}
