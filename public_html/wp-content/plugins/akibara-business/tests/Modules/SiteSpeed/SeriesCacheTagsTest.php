<?php
/**
 * Purga por lo que muestra la ficha: sus series y cada producto que pinta en una
 * tarjeta. Se purga sólo cuando cambia algo que se ve desde otra ficha.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\SiteSpeed;

use Akibara\Business\Modules\SiteSpeed\EdgePurge;
use Akibara\Business\Modules\SiteSpeed\SeriesCacheTags;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class SeriesCacheTagsTest extends TestCase {

	/** @var array<int, array<int, mixed>> */
	private array $acciones = array();

	/** @var array<int, mixed> `_stock` ya persistido por producto, para crosses_scarcity_boundary(). */
	private array $stock_persistido = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->acciones        = array();
		$this->stock_persistido = array();
		Functions\when( 'do_action' )->alias(
			function ( ...$args ): void {
				$this->acciones[] = $args;
			}
		);
		Functions\when( 'get_post_meta' )->alias(
			fn( int $id, string $key = '', bool $single = false ) => '_stock' === $key ? ( $this->stock_persistido[ $id ] ?? '' ) : ''
		);
		Functions\when( 'wp_get_post_parent_id' )->alias( static fn( int $id ): int => 900 === $id ? 90 : 0 );
		// Desde 1.116.16 la purga por serie también anota la URL para el borde
		// (`EdgePurge`): estas son las piezas que necesita para construirla.
		Functions\when( 'home_url' )->justReturn( 'https://akibara.test' );
		Functions\when( 'get_permalink' )->alias( static fn( int $id ): string => 'https://akibara.test/producto-' . $id . '/' );
		Functions\when( 'get_term_link' )->alias( static fn( int $id ): string => 'https://akibara.test/serie-' . $id . '/' );
		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'add_action' )->justReturn( true );
		// term_id y term_taxonomy_id distintos a propósito: la etiqueta usa el segundo.
		Functions\when( 'get_the_terms' )->alias(
			static function ( int $id, string $taxonomy ) {
				if ( 'pa_serie' !== $taxonomy ) {
					return false;
				}
				return match ( $id ) {
					10, 11 => array( (object) array( 'term_id' => 5, 'term_taxonomy_id' => 15 ), (object) array( 'term_id' => 7, 'term_taxonomy_id' => 17 ) ),
					90     => array( (object) array( 'term_id' => 8, 'term_taxonomy_id' => 18 ) ),
					default => false,
				};
			}
		);
	}

	protected function tearDown(): void {
		// N-orden-suite (2026-09-19): esta prueba anota URLs en `EdgePurge`
		// (estático) vía `SeriesCacheTags` y nunca las despacha —el `shutdown`
		// real no corre bajo PHPUnit—, así que sin este reset quedan colgando
		// para el siguiente test que lea `EdgePurge::$pendientes`
		// (`EdgePurgeTest::test_solo_urls_propias`, con `--order-by=reverse`).
		$pendientes = new ReflectionProperty( EdgePurge::class, 'pendientes' );
		$pendientes->setAccessible( true );
		$pendientes->setValue( null, array() );

		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * URLs anotadas para el borde (`EdgePurge::$pendientes`) en este momento.
	 *
	 * @return array<int, string>
	 */
	private function pendientes_de_borde(): array {
		$pendientes = new ReflectionProperty( EdgePurge::class, 'pendientes' );
		$pendientes->setAccessible( true );

		return array_keys( (array) $pendientes->getValue() );
	}

	/** @return array<int, array<int, mixed>> */
	private function purgas(): array {
		return array_values(
			array_filter(
				$this->acciones,
				static fn( array $a ): bool => in_array( $a[0], array( 'litespeed_purge', 'litespeed_purge_post' ), true )
			)
		);
	}

	/**
	 * Doble de producto con cambios pendientes.
	 *
	 * @param array<string, mixed> $cambios Cambios antes de guardar.
	 */
	private function producto( int $id, array $cambios = array(), ?int $cantidad = null ): object {
		return new class( $id, $cambios, $cantidad ) {
			/** @param array<string, mixed> $cambios */
			public function __construct( private int $id, private array $cambios, private ?int $cantidad ) {}

			public function get_id(): int {
				return $this->id;
			}

			/** @return array<string, mixed> */
			public function get_changes(): array {
				return $this->cambios;
			}

			public function get_stock_quantity(): ?int {
				return $this->cantidad;
			}
		};
	}

	/** @testdox La ficha lleva una etiqueta por cada serie del tomo, con su term_taxonomy_id. */
	public function test_product_page_is_tagged_with_its_series(): void {
		Functions\when( 'is_singular' )->alias( static fn( $type = '' ): bool => 'product' === $type );
		Functions\when( 'get_queried_object_id' )->justReturn( 10 );

		( new SeriesCacheTags() )->tag_product_page();

		self::assertSame(
			array( array( 'litespeed_tag_add', 'akb_serie.15' ), array( 'litespeed_tag_add', 'akb_serie.17' ) ),
			$this->acciones
		);
	}

	/** @testdox Cada tarjeta que pinta la ficha la etiqueta con ese producto, una vez. */
	public function test_cards_on_the_product_page_are_tagged(): void {
		Functions\when( 'is_singular' )->alias( static fn( $type = '' ): bool => 'product' === $type );
		Functions\when( 'get_the_ID' )->justReturn( 33 );
		$tags = new SeriesCacheTags();

		$tags->tag_card();
		$tags->tag_card();

		self::assertSame( array( array( 'litespeed_tag_add', 'akb_muestra.33' ) ), $this->acciones );
	}

	/** @testdox Fuera de la ficha no se etiqueta nada. */
	public function test_other_pages_are_not_tagged(): void {
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\when( 'get_the_ID' )->justReturn( 33 );
		$tags = new SeriesCacheTags();

		$tags->tag_product_page();
		$tags->tag_card();

		self::assertSame( array(), $this->acciones );
	}

	/** @testdox Un cambio visible purga series, tarjetas y la ficha propia; uno invisible, nada. */
	public function test_save_purges_only_visible_changes(): void {
		( new SeriesCacheTags() )->purge_on_save( $this->producto( 10, array( 'description' => 'x', 'short_description' => 'y' ) ) );
		self::assertSame( array(), $this->purgas() );

		// `price` y `name` nunca llegan en woocommerce_product_object_updated_props.
		foreach ( array( 'price', 'name', 'status', 'catalog_visibility' ) as $prop ) {
			$this->acciones = array();
			( new SeriesCacheTags() )->purge_on_save( $this->producto( 10, array( $prop => 'nuevo' ) ) );
			self::assertSame(
				array(
					array( 'litespeed_purge', 'akb_serie.15' ),
					array( 'litespeed_purge', 'akb_serie.17' ),
					array( 'litespeed_purge', 'akb_muestra.10' ),
					array( 'litespeed_purge_post', 10 ),
				),
				$this->purgas(),
				'Cambio de ' . $prop
			);
		}
	}

	/** @testdox Guardar sólo la cantidad (lo que hace cada venta) no purga por el guardado. */
	public function test_a_sale_save_is_not_a_visible_change(): void {
		( new SeriesCacheTags() )->purge_on_save( $this->producto( 10, array( 'stock_quantity' => 3, 'date_modified' => 'x' ) ) );
		self::assertSame( array(), $this->purgas() );
	}

	/**
	 * R2-6984f3e5: un tomo con 1 unidad aparece "de copia única" en «Arma tu
	 * pack» (BuilderWidget cuenta `stock_qty === 1`). Reponer por encima de 1 sin
	 * tocar `stock_status` (sigue "instock") no disparaba ninguna purga: ni
	 * `purge_on_stock()` (sólo purga si la cantidad NUEVA es ≤1) ni
	 * `purge_on_save()` (`stock_quantity` no está en CAMBIOS_VISIBLES). El aviso
	 * de escasez quedaba sirviéndose desde la ficha cacheada indefinidamente.
	 *
	 * @testdox Reponer de 1 a 5 unidades cruza el umbral de escasez y purga.
	 */
	public function test_restock_above_one_purges_when_scarcity_boundary_crossed(): void {
		$this->stock_persistido[10] = 1;

		( new SeriesCacheTags() )->purge_on_save( $this->producto( 10, array( 'stock_quantity' => 5 ) ) );

		self::assertSame(
			array(
				array( 'litespeed_purge', 'akb_serie.15' ),
				array( 'litespeed_purge', 'akb_serie.17' ),
				array( 'litespeed_purge', 'akb_muestra.10' ),
				array( 'litespeed_purge_post', 10 ),
			),
			$this->purgas()
		);
	}

	/** @testdox Moverse entre cantidades que NO son escasas (5→6) no purga. */
	public function test_restock_away_from_the_boundary_does_not_purge(): void {
		$this->stock_persistido[10] = 5;

		( new SeriesCacheTags() )->purge_on_save( $this->producto( 10, array( 'stock_quantity' => 6 ) ) );

		self::assertSame( array(), $this->purgas() );
	}

	/** @testdox Bajar a 1 unidad TAMBIÉN cruza el umbral (simétrico) y purga desde purge_on_save(). */
	public function test_dropping_to_one_also_crosses_the_boundary(): void {
		$this->stock_persistido[10] = 5;

		( new SeriesCacheTags() )->purge_on_save( $this->producto( 10, array( 'stock_quantity' => 1 ) ) );

		self::assertCount( 4, $this->purgas() );
	}

	/**
	 * `wc_update_product_stock()` —venta, anulación que devuelve stock,
	 * sincronizaciones— escribe la cantidad directo en los datos del objeto: el
	 * guardado posterior no trae `stock_quantity` entre sus cambios y
	 * `purge_on_save()` no lo ve. La foto de `remember_stock()` es lo que deja a
	 * `purge_on_stock()` saber que se CRUZÓ el umbral hacia arriba.
	 *
	 * @testdox La anulación que devuelve el stock de 1 a 2 también borra el aviso de copia única.
	 */
	public function test_restock_through_wc_update_product_stock_purges_when_leaving_scarcity(): void {
		$tags = new SeriesCacheTags();
		$tags->remember_stock( $this->producto( 10, array(), 1 ) );
		$tags->purge_on_stock( $this->producto( 10, array(), 2 ) );

		self::assertCount( 4, $this->purgas(), 'De 1 a 2: «copia única» dejó de ser verdad.' );
	}

	/** @testdox Por ese mismo camino, 5 → 6 no purga; y sin foto previa rige la regla de siempre. */
	public function test_restock_through_wc_update_product_stock_away_from_the_boundary(): void {
		$tags = new SeriesCacheTags();
		$tags->remember_stock( $this->producto( 10, array(), 5 ) );
		$tags->purge_on_stock( $this->producto( 10, array(), 6 ) );
		self::assertSame( array(), $this->purgas() );

		// Un tercero que dispare `woocommerce_product_set_stock` a mano no pasa
		// por `before_set_stock`: sin foto sólo cuenta la cantidad nueva.
		$tags->purge_on_stock( $this->producto( 10, array(), 4 ) );
		self::assertSame( array(), $this->purgas(), 'Sin foto y lejos del umbral: nada.' );
		$tags->purge_on_stock( $this->producto( 10, array(), 1 ) );
		self::assertCount( 4, $this->purgas(), 'Sin foto pero quedó en 1: purga, como antes.' );
	}

	/** @testdox Un producto recién creado no purga: todavía no sale en ninguna ficha. */
	public function test_new_product_save_purges_nothing(): void {
		( new SeriesCacheTags() )->purge_on_save( $this->producto( 0, array( 'price' => '1000' ) ) );
		self::assertSame( array(), $this->purgas() );
	}

	/** @testdox Una variación purga lo de su producto padre. */
	public function test_variation_uses_its_parent(): void {
		( new SeriesCacheTags() )->purge_on_save( $this->producto( 900, array( 'stock_status' => 'outofstock' ) ) );

		self::assertSame(
			array(
				array( 'litespeed_purge', 'akb_serie.18' ),
				array( 'litespeed_purge', 'akb_muestra.90' ),
				array( 'litespeed_purge_post', 90 ),
			),
			$this->purgas()
		);
	}

	/** @testdox La cantidad sólo purga al quedar en 1 o menos. */
	public function test_stock_quantity_purges_only_at_one_or_less(): void {
		( new SeriesCacheTags() )->purge_on_stock( $this->producto( 10, array(), 5 ) );
		( new SeriesCacheTags() )->purge_on_stock( $this->producto( 10, array(), null ) );
		self::assertSame( array(), $this->purgas() );

		( new SeriesCacheTags() )->purge_on_stock( $this->producto( 10, array(), 1 ) );
		self::assertCount( 4, $this->purgas() );

		$this->acciones = array();
		( new SeriesCacheTags() )->purge_on_stock( $this->producto( 10, array(), 0 ) );
		self::assertCount( 4, $this->purgas() );
	}

	/** @testdox Pasar a agotado (o volver) purga, una sola vez por petición. */
	public function test_stock_status_purges_once_per_request(): void {
		$tags = new SeriesCacheTags();

		$tags->purge_for_product_id( 10 );
		$tags->purge_for_product_id( 10 );
		$tags->purge_on_save( $this->producto( 11, array( 'stock_status' => 'instock' ) ) );

		self::assertSame(
			array(
				array( 'litespeed_purge', 'akb_serie.15' ),
				array( 'litespeed_purge', 'akb_serie.17' ),
				array( 'litespeed_purge', 'akb_muestra.10' ),
				array( 'litespeed_purge_post', 10 ),
				array( 'litespeed_purge', 'akb_muestra.11' ),
				array( 'litespeed_purge_post', 11 ),
			),
			$this->purgas(),
			'El tomo 11 comparte series con el 10: no se repiten.'
		);
	}

	/** @testdox Un producto sin serie purga sus tarjetas y su ficha. */
	public function test_product_without_series(): void {
		( new SeriesCacheTags() )->purge_for_product_id( 55 );
		( new SeriesCacheTags() )->purge_for_product_id( 0 );

		self::assertSame(
			array( array( 'litespeed_purge', 'akb_muestra.55' ), array( 'litespeed_purge_post', 55 ) ),
			$this->purgas()
		);
	}

	/** @testdox Cambiar la serie de un tomo purga la serie nueva y la vieja; repetir la misma, nada. */
	public function test_series_change_purges_old_and_new(): void {
		$tags = new SeriesCacheTags();

		$tags->purge_on_terms( 10, array(), array( 20 ), 'product_cat', false, array( 15 ) );
		$tags->purge_on_terms( 10, array(), array( '15' ), 'pa_serie', false, array( 15 ) );
		self::assertSame( array(), $this->purgas() );
		self::assertSame( array(), $this->pendientes_de_borde(), 'Series sin cambio: tampoco se avisa al borde.' );

		$tags->purge_on_terms( 10, array(), array( 20 ), 'pa_serie', false, array( 15 ) );
		self::assertSame(
			array(
				array( 'litespeed_purge', 'akb_serie.20' ),
				array( 'litespeed_purge', 'akb_serie.15' ),
				array( 'litespeed_purge', 'akb_muestra.10' ),
			),
			$this->purgas()
		);
	}

	/**
	 * R3-d37235c6 (2026-09-19): antes `purge_on_terms()` era la ÚNICA purga de
	 * la clase que nunca avisaba a Cloudflare — sólo purgaba por TAG de
	 * LiteSpeed, que el borde ignora. Reasignar la serie de un tomo YA
	 * PUBLICADO (el camino real: `set_object_terms` sin tocar precio/estado)
	 * dejaba la landing de la serie vieja, la de la serie nueva y la ficha
	 * propia sirviendo la copia de antes del cambio desde Cloudflare.
	 *
	 * @testdox Cambiar la serie de un tomo también avisa al borde: la landing vieja, la nueva y la ficha propia.
	 */
	public function test_series_change_notifies_the_edge_for_old_new_and_own_product(): void {
		$tags = new SeriesCacheTags();

		$tags->purge_on_terms( 10, array(), array( 20 ), 'pa_serie', false, array( 15 ) );

		self::assertSame(
			array(
				'https://akibara.test/serie-20/',
				'https://akibara.test/serie-15/',
				'https://akibara.test/producto-10/',
			),
			$this->pendientes_de_borde()
		);
	}

	/** @testdox Publicar, retirar o borrar un tomo purga lo que lo muestra; lo demás no. */
	public function test_status_transitions(): void {
		$tomo = (object) array( 'ID' => 10, 'post_type' => 'product' );
		$post = (object) array( 'ID' => 10, 'post_type' => 'post' );
		$tags = new SeriesCacheTags();

		$tags->purge_on_status( 'draft', 'draft', $tomo );
		$tags->purge_on_status( 'publish', 'publish', $tomo );
		$tags->purge_on_status( 'publish', 'draft', $post );
		self::assertSame( array(), $this->purgas() );

		$tags->purge_on_status( 'trash', 'publish', $tomo );
		self::assertCount( 4, $this->purgas() );
	}

	/** @testdox Los enganches cubren el guardado de productos y variaciones, el stock y el cambio de serie. */
	public function test_registers_the_change_hooks(): void {
		$enganches = array();
		Functions\when( 'add_action' )->alias(
			static function ( string $hook ) use ( &$enganches ): void {
				$enganches[] = $hook;
			}
		);

		( new SeriesCacheTags() )->register();

		foreach ( array( 'wp', 'woocommerce_before_shop_loop_item', 'woocommerce_before_product_object_save', 'woocommerce_before_product_variation_object_save', 'woocommerce_product_set_stock_status', 'woocommerce_variation_set_stock_status', 'woocommerce_product_set_stock', 'woocommerce_variation_set_stock', 'set_object_terms', 'transition_post_status' ) as $hook ) {
			self::assertContains( $hook, $enganches );
		}
		self::assertNotContains( 'woocommerce_update_product', $enganches, 'Corre en cada venta: purgaría la serie por cada tomo vendido.' );
	}
}
