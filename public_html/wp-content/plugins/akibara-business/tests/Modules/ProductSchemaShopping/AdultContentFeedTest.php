<?php
/**
 * Candado: la lista de series +18 del feed de Merchant es DATO, no código.
 *
 * Estaba en un `private const` del módulo, así que marcar una serie nueva como
 * adulta exigía editar código, empaquetar y desplegar para una decisión que es
 * puramente editorial. Ahora vive en la term meta `_akb_adult_content` de
 * `pa_serie` y la constante quedó sólo como semilla.
 *
 * La INVARIANTE: marcar o desmarcar una serie desde el admin cambia lo que
 * declara el feed, sin tocar el código. El segundo candado —la semilla responde
 * mientras la migración no corrió— existe porque perder la declaración aunque
 * sea un rato es justo lo que hace que Merchant vuelva a rechazar los productos.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\ProductSchemaShopping;

use Akibara\Business\Modules\ProductSchemaShopping\AdultContentFeed;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class AdultContentFeedTest extends TestCase {

	/** Opciones en memoria. */
	private array $options = array();

	/** term_id => valor de `_akb_adult_content`. */
	private array $term_meta = array();

	/** slug => term_id de los términos que existen en este entorno. */
	private array $terms = array();

	/** Slugs con los que se preguntó `has_term()` en la última llamada. */
	private array $asked_slugs = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->options     = array();
		$this->term_meta   = array();
		$this->asked_slugs = array();
		$this->terms       = array(
			'ten-count'         => 101,
			'prison-school'     => 102,
			'ayakashi-triangle' => 103,
			'mushoku-tensei'    => 104,
			'made-in-abyss'     => 105,
			'berserk'           => 200,
		);

		Functions\when( 'get_option' )->alias(
			fn( string $key, $default = false ) => $this->options[ $key ] ?? $default
		);
		Functions\when( 'update_option' )->alias(
			function ( string $key, $value ): bool {
				$this->options[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'taxonomy_exists' )->justReturn( true );
		Functions\when( 'get_term_by' )->alias(
			function ( string $field, string $value, string $taxonomy ) {
				if ( ! isset( $this->terms[ $value ] ) ) {
					return false;
				}
				return new \WP_Term( $this->terms[ $value ], $value, $value );
			}
		);
		Functions\when( 'update_term_meta' )->alias(
			function ( int $term_id, string $key, $value ): bool {
				$this->term_meta[ $term_id ] = $value;
				return true;
			}
		);
		Functions\when( 'get_term_meta' )->alias(
			fn( int $term_id ) => $this->term_meta[ $term_id ] ?? ''
		);
		// Espeja `WP_Term_Query` con `meta_query`: devuelve los slugs marcados.
		Functions\when( 'get_terms' )->alias(
			function (): array {
				$slugs = array();
				foreach ( $this->terms as $slug => $term_id ) {
					if ( '1' === ( $this->term_meta[ $term_id ] ?? '' ) ) {
						$slugs[] = $slug;
					}
				}
				return $slugs;
			}
		);
		Functions\when( 'has_term' )->alias(
			function ( $terms ): bool {
				$this->asked_slugs = (array) $terms;
				return in_array( 'berserk', (array) $terms, true );
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox Marcar una serie desde el admin la declara adulta sin desplegar.
	 */
	public function test_marking_a_series_from_the_admin_declares_it_adult(): void {
		$feed = new AdultContentFeed();
		$feed->seed_from_constant();

		// Berserk NO está en la semilla: es exactamente el caso que antes exigía
		// tocar el código.
		self::assertSame(
			array(),
			$feed->declare_adult_attribute( array(), $this->product( 5000 ) ),
			'Sin marcar, el feed no declara nada.'
		);

		$this->term_meta[ $this->terms['berserk'] ] = '1';

		$otro = new AdultContentFeed();
		self::assertSame(
			array( 'adult' => true ),
			$otro->declare_adult_attribute( array(), $this->product( 5000 ) )
		);
		self::assertContains( 'berserk', $this->asked_slugs );
	}

	/**
	 * @testdox Desmarcar una serie sembrada la saca del feed.
	 */
	public function test_unmarking_a_seeded_series_removes_it(): void {
		( new AdultContentFeed() )->seed_from_constant();

		$antes = new AdultContentFeed();
		$antes->declare_adult_attribute( array(), $this->product( 5000 ) );
		self::assertContains( 'made-in-abyss', $this->asked_slugs );

		unset( $this->term_meta[ $this->terms['made-in-abyss'] ] );

		$despues = new AdultContentFeed();
		$despues->declare_adult_attribute( array(), $this->product( 5000 ) );
		self::assertNotContains( 'made-in-abyss', $this->asked_slugs );
	}

	/**
	 * @testdox Antes de sembrar responde la semilla: el feed nunca queda mudo.
	 */
	public function test_before_seeding_the_constant_still_answers(): void {
		$feed = new AdultContentFeed();
		$feed->declare_adult_attribute( array(), $this->product( 5000 ) );

		self::assertSame(
			array( 'ten-count', 'prison-school', 'ayakashi-triangle', 'mushoku-tensei', 'made-in-abyss' ),
			$this->asked_slugs,
			'Sin siembra, la decisión editorial vigente sigue siendo la de la constante.'
		);
	}

	/**
	 * @testdox La siembra es idempotente: no reimpone lo que el dueño desmarcó.
	 */
	public function test_seeding_does_not_undo_a_manual_unmark(): void {
		$feed = new AdultContentFeed();
		$feed->seed_from_constant();
		unset( $this->term_meta[ $this->terms['prison-school'] ] );

		$feed->seed_from_constant();

		self::assertArrayNotHasKey( $this->terms['prison-school'], $this->term_meta );
	}

	/**
	 * @testdox Una variación hereda la decisión de la serie del padre.
	 */
	public function test_variations_inherit_the_parent_series(): void {
		$this->options['akb_adult_series_seeded'] = '1';
		$this->term_meta[ $this->terms['berserk'] ] = '1';

		$variation = new class() {
			public function get_id(): int {
				return 6001;
			}
			public function get_parent_id(): int {
				return 6000;
			}
		};

		self::assertSame(
			array( 'adult' => true ),
			( new AdultContentFeed() )->declare_adult_attribute( array(), $variation )
		);
	}

	private function product( int $id ): object {
		return new class( $id ) {
			private int $id;
			public function __construct( int $id ) {
				$this->id = $id;
			}
			public function get_id(): int {
				return $this->id;
			}
			public function get_parent_id(): int {
				return 0;
			}
		};
	}
}
