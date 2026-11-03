<?php
/**
 * Los datos por edición: lo que escribe el dueño y lo que se calcula solo.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\BrandsDirectory;

use Akibara\Business\Modules\BrandsDirectory\EditionFacts;
use Akibara\Business\Services\VolumeVariant;
use Akibara\Business\Tests\Support\EditionsWpdb;
use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WP_Post;
use WP_Term;

final class EditionFactsTest extends TestCase {

	/** @var array<int, array<string, string>> */
	private array $meta = array();

	/** @var array<string, mixed> */
	private array $transients = array();

	private EditionsWpdb $wpdb;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->meta       = array();
		$this->transients = array();
		$this->wpdb       = new EditionsWpdb();
		$GLOBALS['wpdb']  = $this->wpdb;

		Functions\when( 'wp_strip_all_tags' )->alias( static fn( string $v ): string => strip_tags( $v ) );
		Functions\when( 'get_term_meta' )->alias( fn( int $id, string $key, bool $single = false ) => $this->meta[ $id ][ $key ] ?? '' );
		Functions\when( 'update_term_meta' )->alias(
			function ( int $id, string $key, $value ): bool {
				$this->meta[ $id ][ $key ] = (string) $value;
				return true;
			}
		);
		Functions\when( 'delete_term_meta' )->alias(
			function ( int $id, string $key ): bool {
				unset( $this->meta[ $id ][ $key ] );
				return true;
			}
		);
		Functions\when( 'get_transient' )->alias( fn( string $k ) => $this->transients[ $k ] ?? false );
		Functions\when( 'set_transient' )->alias(
			function ( string $k, $v ): bool {
				$this->transients[ $k ] = $v;
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( string $k ): bool {
				unset( $this->transients[ $k ] );
				return true;
			}
		);
		Functions\when( 'get_term' )->alias(
			static function ( int $id, string $tax = '' ) {
				$series = array( 11 => 'Fire Force', 12 => 'Golden Kamuy', 13 => 'Jujutsu Kaisen', 14 => 'Kaguya-sama', 15 => 'Claymore', 16 => 'Noragami', 17 => 'Frieren' );
				return isset( $series[ $id ] ) ? new WP_Term( $id, 'serie-' . $id, $series[ $id ] ) : null;
			}
		);
		Functions\when( 'get_term_link' )->alias( static fn( WP_Term $t ): string => 'https://akibara.test/serie/' . $t->slug . '/' );
		Functions\when( 'get_the_post_thumbnail_url' )->alias( static fn( int $id ): string => 'https://akibara.test/p/' . $id . '.jpg' );
	}

	protected function tearDown(): void {
		EditionFacts::set_price_override_probe( null );
		unset( $GLOBALS['wpdb'] );
		\WP_Query::$akb_resolver = null;
		Monkey\tearDown();
		parent::tearDown();
	}

	private function edicion(): WP_Term {
		$t                   = new WP_Term( 263, 'panini-argentina', 'Panini Argentina' );
		$t->term_taxonomy_id = 2630;
		return $t;
	}

	/** @testdox Un hecho se guarda como una línea, sin etiquetas y acotado. */
	public function test_facts_are_one_clean_line(): void {
		self::assertSame( 'Español rioplatense (voseo)', EditionFacts::sanitize_fact( "  Español <b>rioplatense</b>\n (voseo) " ) );
		self::assertSame( EditionFacts::MAX_LEN, mb_strlen( EditionFacts::sanitize_fact( str_repeat( 'a', 500 ) ) ) );
		self::assertSame( '', EditionFacts::sanitize_fact( array( 'no' ) ) );
	}

	/** @testdox Las series elegidas: enteros positivos, sin repetidos, hasta seis. */
	public function test_series_list_is_bounded_and_unique(): void {
		self::assertSame( array( 3, 1, 2 ), EditionFacts::sanitize_series( '3, 1, x, 2, 3, 0, -4' ) );
		self::assertCount( EditionFacts::MAX_SERIES, EditionFacts::sanitize_series( range( 1, 20 ) ) );
	}

	/** @testdox Guardar escribe lo que hay, borra lo vacío y olvida la caché. */
	public function test_save_writes_deletes_and_invalidates(): void {
		$this->transients['akb_edition_facts_263'] = array( 'series' => array(), 'price_from' => 1.0 );
		EditionFacts::save( 263, array( EditionFacts::META_IDIOMA => ' Español de España ', EditionFacts::META_FORMATO => '' ), array( 12, 11 ) );

		self::assertSame( 'Español de España', $this->meta[263][ EditionFacts::META_IDIOMA ] );
		self::assertArrayNotHasKey( EditionFacts::META_FORMATO, $this->meta[263] );
		self::assertSame( '12,11', $this->meta[263][ EditionFacts::META_SERIES ] );
		self::assertArrayNotHasKey( 'akb_edition_facts_263', $this->transients, 'La caché de series y precio caduca al guardar.' );
		self::assertSame( array( 'Idioma' => 'Español de España' ), EditionFacts::facts( 263 ) );
		self::assertSame( 'Español de España', EditionFacts::snapshot( 263 )[ EditionFacts::META_IDIOMA ] );
	}

	/** @testdox Sin elección del dueño, las destacadas son las series con más tomos de la edición, con su portada. */
	public function test_featured_series_fall_back_to_volume(): void {
		$this->wpdb->series[2630] = array( 11 => 33, 12 => 31, 13 => 30, 14 => 28, 15 => 27, 16 => 27, 17 => 5 );
		// La portada se busca DENTRO de la edición: la consulta lleva la serie Y la marca.
		\WP_Query::$akb_resolver = static function ( array $args ): array {
			$tax = array_column( $args['tax_query'], 'terms', 'taxonomy' );
			if ( ( $tax['product_brand'][0] ?? 0 ) !== 263 ) {
				return array();
			}
			return array( 700 + (int) $tax['pa_serie'][0] );
		};

		$series = EditionFacts::featured_series( $this->edicion() );

		self::assertSame( array( 'Fire Force', 'Golden Kamuy', 'Jujutsu Kaisen', 'Kaguya-sama', 'Claymore', 'Noragami' ), array_column( $series, 'name' ), 'Seis, por volumen; Frieren (5 tomos) queda fuera.' );
		self::assertSame( 'https://akibara.test/p/711.jpg', $series[0]['cover'] );
		self::assertSame( 'https://akibara.test/serie/serie-11/', $series[0]['url'] );
		self::assertArrayHasKey( 'akb_edition_facts_263', $this->transients, 'Se calcula una vez cada 12 h.' );
	}

	/** @testdox Lo elegido a mano va primero y lo automático rellena hasta seis. */
	public function test_chosen_series_come_first(): void {
		$this->meta[263][ EditionFacts::META_SERIES ] = '17,16';
		$this->wpdb->series[2630]                     = array( 11 => 33, 12 => 31, 13 => 30, 14 => 28, 15 => 27, 16 => 27 );
		\WP_Query::$akb_resolver                      = static fn(): array => array();

		$series = EditionFacts::featured_series( $this->edicion() );

		self::assertSame( array( 'Frieren', 'Noragami', 'Fire Force', 'Golden Kamuy', 'Jujutsu Kaisen', 'Kaguya-sama' ), array_column( $series, 'name' ) );
	}

	/** @testdox El precio «desde» sale de la tabla de precios de WooCommerce y también se cachea. */
	public function test_price_from(): void {
		$this->wpdb->price[2630] = 10990.0;
		self::assertSame( 10990.0, EditionFacts::price_from( $this->edicion() ) );
		self::assertStringContainsString( "l.stock_status IN ('instock', 'onbackorder')", implode( "\n", $this->wpdb->queries ), 'Sólo lo que se puede comprar hoy.' );

		$this->wpdb->price[2630] = 1.0; // ya cacheado: no se relee
		self::assertSame( 10990.0, EditionFacts::price_from( $this->edicion() ) );
	}

	/** @testdox Al cambiar el stock o guardar un producto, sus ediciones olvidan el precio y las portadas cacheados. */
	public function test_a_product_change_invalidates_its_editions(): void {
		$this->transients['akb_edition_facts_263'] = array( 'series' => array(), 'price_from' => 1.0 );
		$this->transients['akb_edition_facts_999'] = array( 'series' => array(), 'price_from' => 2.0 );
		$brand = new WP_Term( 263, 'panini-argentina', 'Panini Argentina' );
		Functions\when( 'get_the_terms' )->alias( static fn( int $id, string $tax ) => 700 === $id && 'product_brand' === $tax ? array( $brand ) : false );

		Functions\when( 'wp_strip_all_tags' )->alias( static fn( string $t ): string => strip_tags( $t ) );
		Functions\when( 'get_page_by_path' )->alias( static fn( string $slug ) => 'panini' === $slug ? new WP_Post( 27450, 'panini' ) : null );
		Functions\when( 'get_permalink' )->justReturn( 'https://akibara.test/panini/' );
		$purged = array();
		Functions\when( 'do_action' )->alias(
			static function ( string $hook, ...$args ) use ( &$purged ): void {
				if ( 'litespeed_purge_url' === $hook ) {
					$purged[] = (string) $args[0];
				}
			}
		);
		$facts = new EditionFacts();

		$facts->invalidate_product( 700 );
		$facts->invalidate_product( 700 );

		self::assertArrayNotHasKey( 'akb_edition_facts_263', $this->transients );
		self::assertArrayHasKey( 'akb_edition_facts_999', $this->transients, 'Sólo las ediciones del producto.' );
		self::assertSame( array(), $purged, 'La purga espera al final del request.' );
		self::assertSame( PHP_INT_MAX, Actions\has( 'shutdown', array( $facts, 'purge_queued_urls' ) ) );

		$facts->purge_queued_urls();

		self::assertSame( array( 'https://akibara.test/serie/panini-argentina/', 'https://akibara.test/panini/' ), $purged, 'El archivo de la edición y la landing de la familia, una vez cada uno.' );
	}

	/** @testdox La invalidación cuelga de los hooks de producto de WooCommerce, y el módulo la registra. */
	public function test_registers_the_invalidation_hooks(): void {
		$facts = new EditionFacts();

		$facts->register();

		self::assertSame( 10, Actions\has( 'woocommerce_product_set_stock_status', array( $facts, 'invalidate_product' ) ), 'Cambiar el stock tiene que olvidar el precio «desde».' );
		self::assertSame( 10, Actions\has( 'woocommerce_update_product', array( $facts, 'invalidate_product' ) ), 'Guardar un producto también.' );

		// Y la marca de variante, que cambia el orden de «series con más tomos»
		// y se pone por WP-CLI: ninguno de los dos hooks de arriba la ve.
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $meta_hook ) {
			self::assertSame( 10, Actions\has( $meta_hook, array( $facts, 'invalidate_on_variant_meta' ) ), 'Marcar una variante por WP-CLI tiene que olvidar el orden.' );
		}

		$modulo = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Modules/BrandsDirectory/BrandsDirectory.php' );
		self::assertStringContainsString( '$this->edition_facts->register();', $modulo, 'Sin esta línea los hooks no existen y nada se ve roto.' );
	}

	/** @testdox Con una campaña editorial vigente, la tarjeta NO anuncia un «desde» que la rejilla desmiente. */
	public function test_el_desde_calla_con_descuento_vigente(): void {
		$this->transients['akb_edition_facts_263'] = array( 'series' => array(), 'price_from' => 9500.0 );
		$edicion = $this->edicion();

		EditionFacts::set_price_override_probe( null );
		self::assertSame( 9500.0, EditionFacts::price_from( $edicion ), 'Sin campaña manda la tabla de precios.' );

		EditionFacts::set_price_override_probe( static fn (): bool => true );

		self::assertSame( 0.0, EditionFacts::price_from( $edicion ), 'Anunciar $9.500 sobre tomos a $8.075 es un dato falso para quien lo lee.' );
		self::assertSame( 9500.0, EditionFacts::price_from_lookup( $edicion ), 'El panel del dueño sí ve el precio de lista.' );
		self::assertTrue( EditionFacts::price_is_overridden() );

		// Apagar la campaña lo devuelve en la siguiente visita, sin esperar las 12 h.
		EditionFacts::set_price_override_probe( static fn (): bool => false );
		self::assertSame( 9500.0, EditionFacts::price_from( $edicion ) );
	}

	/** @testdox Borrar un tomo, mandarlo a la papelera o restaurarlo también olvida lo cacheado. */
	public function test_la_papelera_y_el_borrado_invalidan(): void {
		$brand = new WP_Term( 263, 'panini-argentina', 'Panini Argentina' );
		Functions\when( 'get_the_terms' )->alias( static fn( int $id, string $tax ) => 700 === $id && 'product_brand' === $tax ? array( $brand ) : false );
		Functions\when( 'get_post_type' )->alias( static fn( int $id ) => 700 === $id ? 'product' : 'page' );
		Functions\when( 'get_page_by_path' )->justReturn( null );
		Functions\when( 'do_action' )->justReturn( null );
		$facts = new EditionFacts();

		$facts->register();

		// Antes de que WordPress suelte las relaciones de término: después ya no
		// se sabría a qué edición pertenecía el tomo.
		self::assertSame( 10, Actions\has( 'wp_trash_post', array( $facts, 'invalidate_on_post_change' ) ) );
		self::assertSame( 10, Actions\has( 'before_delete_post', array( $facts, 'invalidate_on_post_change' ) ) );
		self::assertSame( 10, Actions\has( 'untrashed_post', array( $facts, 'invalidate_on_post_change' ) ) );

		$this->transients['akb_edition_facts_263'] = array( 'series' => array(), 'price_from' => 1.0 );
		$facts->invalidate_on_post_change( 700 );

		self::assertArrayNotHasKey( 'akb_edition_facts_263', $this->transients, 'La tarjeta seguía contando un tomo que ya no existe.' );
	}

	/** @testdox [NEG] Borrar cualquier otra entrada no cuesta una consulta de términos. */
	public function test_borrar_una_pagina_no_toca_las_ediciones(): void {
		$this->transients['akb_edition_facts_263'] = array( 'series' => array(), 'price_from' => 1.0 );
		Functions\when( 'get_post_type' )->justReturn( 'page' );
		Functions\expect( 'get_the_terms' )->never();

		( new EditionFacts() )->invalidate_on_post_change( 27450 );
		( new EditionFacts() )->invalidate_on_post_change( 0 );

		self::assertArrayHasKey( 'akb_edition_facts_263', $this->transients );
	}

	/** @testdox [NEG] El hook de meta ignora cualquier clave que no sea la marca. */
	public function test_variant_meta_hook_ignores_other_keys(): void {
		$this->transients['akb_edition_facts_263'] = array( 'series' => array(), 'price_from' => 1.0 );
		Functions\expect( 'get_the_terms' )->never();

		( new EditionFacts() )->invalidate_on_variant_meta( 7, 700, '_price' );

		self::assertArrayHasKey( 'akb_edition_facts_263', $this->transients );
	}

	/** @testdox Un producto sin edición no invalida nada. */
	public function test_invalidate_ignores_products_without_edition(): void {
		$this->transients['akb_edition_facts_263'] = array( 'series' => array(), 'price_from' => 1.0 );
		Functions\when( 'get_the_terms' )->justReturn( false );

		( new EditionFacts() )->invalidate_product( 0 );
		( new EditionFacts() )->invalidate_product( 700 );

		self::assertArrayHasKey( 'akb_edition_facts_263', $this->transients );
	}

	/** @testdox series_by_volume devuelve id → tomos en orden y respeta el límite. */
	public function test_series_by_volume(): void {
		$this->wpdb->series[2630] = array( 11 => 33, 12 => 31, 13 => 30 );
		self::assertSame( array( 11 => 33, 12 => 31 ), EditionFacts::series_by_volume( 2630, 2 ) );
		self::assertSame( array(), EditionFacts::series_by_volume( 0, 5 ) );
	}

	/**
	 * El método se llama «por volumen» y ese orden rellena las tarjetas de la
	 * landing de editorial: una edición especial del mismo número no es un tomo
	 * más, así que no puede empujar a una serie por encima de otra.
	 *
	 * @testdox series_by_volume descuenta las variantes del mismo tomo.
	 */
	public function test_series_by_volume_descuenta_las_variantes(): void {
		$this->wpdb->series[2630] = array( 11 => 33 );
		EditionFacts::series_by_volume( 2630, 2 );

		$sql = $this->wpdb->queries[0];

		self::assertStringContainsString( "vm.meta_key = '" . VolumeVariant::META_KEY . "'", $sql );
		foreach ( VolumeVariant::variant_kinds() as $kind ) {
			self::assertStringContainsString( $kind, $sql );
		}
	}
}
