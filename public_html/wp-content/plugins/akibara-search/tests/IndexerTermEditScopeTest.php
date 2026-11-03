<?php
/**
 * Contrato del alcance de la reconstrucción por edición de término.
 *
 * `edit_terms` lo dispara CUALQUIER `wp_update_term()`, y la pantalla de edición
 * reenvía siempre nombre y slug aunque no los toques. Abrir una editorial y
 * pulsar «Actualizar» —para cambiar una term-meta, o sin cambiar nada—
 * reconstruía el índice ENTERO: 1.568 documentos y `suggest` en 503 durante unos
 * seis minutos, pagados por el cliente que estaba buscando.
 *
 * @package Akibara\Search\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Search\Tests;

use Akibara\Search\Indexer;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * @since 0.1.29
 */
final class IndexerTermEditScopeTest extends TestCase {

	private const TERM_ID = 306;

	/** @var array<int, string> */
	private array $rebuilds = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->rebuilds = array();

		Functions\when( 'get_term' )->alias(
			static function ( $term_id, $taxonomy = '' ) {
				if ( self::TERM_ID !== (int) $term_id ) {
					return null;
				}

				return new \WP_Term( 'product_brand', self::TERM_ID, 0, 'ivrea-argentina', 'Ivrea Argentina' );
			}
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Invoca el handler y devuelve si pidió reconstrucción.
	 *
	 * `Install::request_rebuild()` es estático; se detecta observando si el
	 * handler llega a llamarlo, mediante el filtro que la propia clase expone.
	 *
	 * @param array<string, mixed> $args
	 */
	private function pide_rebuild( array $args ): bool {
		$pedido = false;

		Functions\when( 'do_action' )->alias(
			static function ( string $hook ) use ( &$pedido ): void {
				if ( str_contains( $hook, 'rebuild' ) ) {
					$pedido = true;
				}
			}
		);

		return self::invocar( self::TERM_ID, $args );
	}

	/**
	 * La decisión es pura y estática: no necesita un Indexer construido.
	 *
	 * @param array<string, mixed> $args
	 */
	private static function invocar( int $term_id, array $args ): bool {
		$metodo = new \ReflectionMethod( Indexer::class, 'term_edit_touches_index' );
		$metodo->setAccessible( true );

		return (bool) $metodo->invoke( null, $term_id, 'product_brand', $args );
	}

	/**
	 * @testdox Guardar el término sin cambiar nombre ni slug NO reconstruye el índice.
	 */
	public function test_saving_a_term_unchanged_does_not_rebuild(): void {
		self::assertFalse(
			$this->pide_rebuild( array( 'name' => 'Ivrea Argentina', 'slug' => 'ivrea-argentina', 'parent' => 0 ) )
		);
	}

	/**
	 * @testdox Cambiar el nombre, el slug o el padre SÍ reconstruye.
	 *
	 * Son las tres únicas cosas del término que el índice usa: la etiqueta de la
	 * faceta, la URL de la colección y los ancestros que `facet_index` materializa.
	 */
	public function test_name_slug_or_parent_still_rebuild(): void {
		$base = array( 'name' => 'Ivrea Argentina', 'slug' => 'ivrea-argentina', 'parent' => 0 );

		self::assertTrue( $this->pide_rebuild( array( 'name' => 'Ivrea AR' ) + $base ) );
		self::assertTrue( $this->pide_rebuild( array( 'slug' => 'ivrea-ar' ) + $base ) );
		self::assertTrue( $this->pide_rebuild( array( 'parent' => 99 ) + $base ) );
	}

	/**
	 * @testdox Si no se puede leer el término, se reconstruye: fail-safe.
	 *
	 * Un rebuild de más cuesta seis minutos de degradación; uno de menos deja el
	 * buscador mintiendo hasta que alguien lo note.
	 */
	public function test_an_unreadable_term_falls_back_to_rebuilding(): void {
		self::assertTrue( self::invocar( 999999, array( 'name' => 'x' ) ) );
		self::assertTrue( self::invocar( 0, array( 'name' => 'x' ) ) );
	}

	/**
	 * @testdox Un update que no trae los campos no puede darse por inocuo.
	 *
	 * `wp_update_term()` admite actualizaciones parciales. Si el campo no viaja,
	 * no hay con qué comparar — pero tampoco cambia, así que no reconstruir es
	 * correcto y es lo que evita el rebuild del guardado de term-meta.
	 */
	public function test_a_partial_update_without_indexed_fields_is_inert(): void {
		self::assertFalse( $this->pide_rebuild( array( 'description' => 'otra cosa' ) ) );
		self::assertFalse( $this->pide_rebuild( array() ) );
	}
}
