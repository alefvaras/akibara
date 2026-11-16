<?php
/**
 * El precio publicado sale de la foto adoptada — contrato ejecutable.
 *
 * `PriceSnapshotIntegrationTest` acredita que Search adopta el proveedor REAL de
 * Business y que el recheck final cierra ante una invalidación o un drift de
 * options. Lo que ahí no ocurre nunca es la parte que de verdad materializa
 * precios: **nadie invoca el filtro**. Sin eso, el gate demuestra que los dos
 * plugins se dan la mano, no que el número que Search sella provenga de la foto
 * que Business firmó.
 *
 * Este archivo cubre esa mitad y las propiedades que sólo el objeto real puede
 * exhibir (el doble del suite de Search devuelve `$base` sin mirar nada):
 *
 *   - el descuento aplicado viene de `term_meta` de la foto;
 *   - los presupuestos de lectura son DOS y hay que declararlos por separado —
 *     autoridad (≤ 11) y producto (1 consulta de relaciones por producto);
 *   - cruzar `boundary_at` entre la captura y la respuesta cierra la
 *     publicación aunque la foto siga siendo byte-idéntica;
 *   - un fallo del proveedor es sticky para el resto del request, en todas las
 *     fases;
 *   - un callback ajeno en el hook deja a Search sin proveedor resoluble.
 *
 * @package Akibara\SearchBusiness\Tests
 */

declare( strict_types = 1 );

namespace Akibara\SearchBusiness\Tests;

use Akibara\Business\Modules\TaxonomyDiscount\DiscountResolver;
use Akibara\Business\Modules\TaxonomyDiscount\PriceAuthority;
use Akibara\Business\Modules\TaxonomyDiscount\PriceAuthorityUnavailable;
use Akibara\Business\Modules\TaxonomyDiscount\SearchPriceProvider;
use Akibara\Business\Modules\TaxonomyDiscount\Settings;
use Akibara\Search\EditorialDiscountPublication;
use Brain\Monkey;
use Brain\Monkey\Functions;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use WC_Product;

/** Doble de `$wpdb` que además sirve las relaciones término↔producto. */
final class FilterIntegrationWpdb {
	public string $prefix = 'wp_';
	public string $options = 'wp_options';
	public string $termmeta = 'wp_termmeta';
	public string $last_error = '';

	/** @var array<string, string> */
	public array $raw_options = array();

	/** @var array<int, object> */
	public array $database_rows = array();

	/** @var array<int, array<int, int>> object_id => term_ids */
	public array $relationships = array();

	/** Lecturas de AUTORIDAD: options, state y termmeta/atributos. */
	public int $authority_reads = 0;

	/** Lecturas de PRODUCTO: relaciones término↔producto. */
	public int $product_reads = 0;

	/** @param mixed ...$args */
	public function prepare( string $query, ...$args ): string {
		$flat = array();
		foreach ( $args as $arg ) {
			foreach ( is_array( $arg ) ? $arg : array( $arg ) as $value ) {
				$flat[] = $value;
			}
		}
		foreach ( $flat as $value ) {
			$replacement = is_int( $value ) ? (string) $value : "'" . str_replace( "'", "''", (string) $value ) . "'";
			$query       = (string) preg_replace( '/%[sd]/', $replacement, $query, 1 );
		}

		return $query;
	}

	/** @return array<int, object> */
	public function get_results( string $query ): array {
		++$this->authority_reads;
		$this->last_error = '';

		if ( false !== strpos( $query, "SELECT 'meta' source_kind" ) ) {
			return $this->database_rows;
		}
		if ( false === strpos( $query, 'option_name, option_value' ) ) {
			return array();
		}

		$rows = array();
		foreach ( $this->raw_options as $name => $value ) {
			if ( false !== strpos( $query, "'" . str_replace( "'", "''", $name ) . "'" ) ) {
				$rows[] = (object) array( 'option_name' => $name, 'option_value' => $value );
			}
		}
		usort( $rows, static fn( object $a, object $b ): int => strcmp( $a->option_name, $b->option_name ) );

		return $rows;
	}

	/** @return array<int, string> */
	public function get_col( string $query ): array {
		$this->last_error = '';

		if ( false !== strpos( $query, 'term_relationships' ) ) {
			++$this->product_reads;

			if ( 1 === preg_match( '/tr\.object_id = (\d+)/', $query, $match ) ) {
				return array_map( 'strval', $this->relationships[ (int) $match[1] ] ?? array() );
			}

			return array();
		}

		++$this->authority_reads;

		return array();
	}

	public function get_var( string $query ): ?string {
		++$this->authority_reads;
		$this->last_error = '';

		if ( 1 === preg_match( "/option_name = '((?:''|[^'])*)'/", $query, $match ) ) {
			$name = str_replace( "''", "'", $match[1] );
			return $this->raw_options[ $name ] ?? null;
		}

		return null;
	}

	/** @return object|null */
	public function get_row( string $query, $output = null ) {
		++$this->authority_reads;
		$this->last_error = '';
		unset( $output );

		return null;
	}
}

/**
 * @covers \Akibara\Search\EditorialDiscountPublication
 * @covers \Akibara\Business\Modules\TaxonomyDiscount\SearchPriceProvider
 */
final class PriceFilterIntegrationTest extends TestCase {

	private const TERM = 501;

	public const PRODUCT = 4242;

	private const OTHER_PRODUCT = 4243;

	private FilterIntegrationWpdb $db;

	private SearchPriceProvider $provider;

	private DiscountResolver $resolver;

	private int $now = 0;

	/** @var array<string, mixed> */
	private array $original = array();

	/** @var array<string, mixed> */
	private array $filter_overrides = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// El reloj de Business tiene que ser el MISMO que el de Search: Search
		// fecha su ventana con `new DateTimeImmutable('now', $tz)` y Business la
		// recomputa con su reloj inyectado. Congelarlo en un instante arbitrario
		// haría que los dos lados fecharan años distintos.
		$this->now = time();

		$this->db              = new FilterIntegrationWpdb();
		$GLOBALS['wpdb']       = $this->db;
		$this->filter_overrides = array();

		$authority      = new PriceAuthority();
		$settings       = new Settings( $authority );
		$this->resolver = new DiscountResolver( $settings, $authority );
		$this->provider = new SearchPriceProvider( $this->resolver, fn(): int => $this->now );
		$this->install_provider( $this->provider );

		Functions\when( 'taxonomy_exists' )->alias( static fn( string $slug ): bool => 'product_brand' === $slug );
		// `apply_filters` DESPACHA de verdad el hook del precio: el punto del
		// gate es que el número salga por donde sale en producción —el callback
		// registrado— y no de una llamada directa al objeto. Para el resto de
		// hooks se comporta como identidad, salvo override explícito del caso.
		Functions\when( 'apply_filters' )->alias(
			function ( string $hook, $value = null, ...$args ) {
				if ( array_key_exists( $hook, $this->filter_overrides ) ) {
					return $this->filter_overrides[ $hook ];
				}

				$registered = $GLOBALS['wp_filter'][ $hook ] ?? null;
				if ( ! is_object( $registered ) || ! isset( $registered->callbacks ) ) {
					return $value;
				}

				$callbacks = $registered->callbacks;
				ksort( $callbacks, SORT_NUMERIC );
				foreach ( $callbacks as $rows ) {
					foreach ( $rows as $row ) {
						$accepted = (int) ( $row['accepted_args'] ?? 1 );
						$passed   = array_slice( array_merge( array( $value ), $args ), 0, max( 1, $accepted ) );
						$value    = ( $row['function'] )( ...$passed );
					}
				}

				return $value;
			}
		);
		Functions\when( 'wp_json_encode' )->alias( static fn( $value ) => json_encode( $value ) );
		Functions\when( 'wc_get_price_decimals' )->justReturn( 0 );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'clean_object_term_cache' )->justReturn( null );
		Functions\when( 'update_object_term_cache' )->justReturn( null );
		Functions\when( 'update_meta_cache' )->justReturn( array() );

		foreach ( $this->static_defaults() as $name => $value ) {
			$property = new ReflectionProperty( EditorialDiscountPublication::class, $name );
			$property->setAccessible( true );
			$this->original[ $name ] = $property->getValue();
			$property->setValue( null, $value );
		}

		$this->db->raw_options = array(
			'akibara_business_search_feed_contract_version' => '2',
			'akibara_taxonomy_discount_enabled'             => 'yes',
			'akibara_taxonomy_discount_apply_to'            => 'all',
			'akibara_taxonomy_discount_sale_policy'         => 'greatest_wins',
			'gmt_offset'                                    => '',
			'timezone_string'                               => 'America/Santiago',
			'woocommerce_price_num_decimals'                => '0',
		);
		$this->db->database_rows = array(
			(object) array(
				'source_kind' => 'meta',
				'source_id'   => 11,
				'parent_id'   => self::TERM,
				'value_one'   => '15',
				'value_two'   => '',
			),
		);
		$this->db->relationships = array(
			self::PRODUCT       => array( self::TERM ),
			self::OTHER_PRODUCT => array( self::TERM ),
		);

		$this->install_ready_state();
	}

	protected function tearDown(): void {
		foreach ( $this->original as $name => $value ) {
			$property = new ReflectionProperty( EditorialDiscountPublication::class, $name );
			$property->setAccessible( true );
			$property->setValue( null, $value );
		}

		unset( $GLOBALS['wpdb'], $GLOBALS['wp_filter']['akb_search_effective_price'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	// -----------------------------------------------------------------------
	// El número publicado sale de la foto.
	// -----------------------------------------------------------------------

	/**
	 * @testdox El precio efectivo aplica el porcentaje que venía en la foto adoptada.
	 */
	public function test_published_price_comes_from_the_adopted_snapshot(): void {
		$capture = EditorialDiscountPublication::capture_price_authority();
		self::assertIsArray( $capture, 'Search debe capturar y adoptar.' );
		self::assertSame(
			array( self::TERM ),
			array_column( $capture['price_snapshot']['term_meta'], 'term_id' ),
			'La foto tiene que traer el término con descuento.'
		);
		self::assertSame(
			array( 'product_brand' ),
			$capture['price_snapshot']['taxonomies'],
			'La foto tiene que traer la taxonomía soportada.'
		);
		self::assertTrue( $capture['price_snapshot']['window_active'], 'La ventana tiene que estar activa.' );

		$price = apply_filters( 'akb_search_effective_price', 10000.0, $this->product( self::PRODUCT ) );

		self::assertSame( 8500.0, $price, '15% sobre 10000 según el term_meta de la foto.' );
		self::assertSame( SearchPriceProvider::STATE_ACTIVE, $this->provider->state() );
		self::assertTrue( EditorialDiscountPublication::price_authority_matches( $capture ) );
	}

	/**
	 * Los dos presupuestos son distintos y hay que declararlos por separado: el
	 * de AUTORIDAD (captura + recheck, ≤ 11) no crece con el catálogo, y el de
	 * PRODUCTO sí — una consulta de relaciones por producto. El doble del suite
	 * de Search devuelve `$base` sin consultar, así que allí este coste es
	 * invisible.
	 *
	 * @testdox Autoridad y producto tienen presupuestos separados: 10 y 1 por producto.
	 */
	public function test_authority_and_product_budgets_are_separate(): void {
		$this->db->authority_reads = 0;
		$this->db->product_reads   = 0;

		$capture = EditorialDiscountPublication::capture_price_authority();
		self::assertIsArray( $capture );
		self::assertSame( 5, $this->db->authority_reads );
		self::assertSame( 0, $this->db->product_reads, 'Adoptar no toca relaciones.' );

		apply_filters( 'akb_search_effective_price', 10000.0, $this->product( self::PRODUCT ) );
		self::assertSame( 1, $this->db->product_reads );

		apply_filters( 'akb_search_effective_price', 20000.0, $this->product( self::OTHER_PRODUCT ) );
		self::assertSame( 2, $this->db->product_reads, 'Una consulta por producto.' );

		// Repetir un producto ya resuelto SÍ vuelve a consultar: `resolve_strict()`
		// descarta a propósito el memo de ese producto antes de recalcular, para
		// no heredar un valor que pudo salir del camino tolerante con otra
		// configuración. El coste real, entonces, es una consulta por
		// INVOCACIÓN y no por producto — irrelevante para el Indexer (cada
		// producto se indexa una vez) y relevante para el Presenter, que puede
		// pedir el mismo producto en dos superficies de la misma respuesta.
		apply_filters( 'akb_search_effective_price', 10000.0, $this->product( self::PRODUCT ) );
		self::assertSame( 3, $this->db->product_reads, 'El camino estricto no reutiliza memos.' );

		self::assertSame( 5, $this->db->authority_reads, 'Calcular precios no relee configuración.' );

		self::assertTrue( EditorialDiscountPublication::price_authority_matches( $capture ) );
		self::assertSame( 10, $this->db->authority_reads );
		self::assertLessThanOrEqual( 11, $this->db->authority_reads );
	}

	// -----------------------------------------------------------------------
	// Vigencia.
	// -----------------------------------------------------------------------

	/**
	 * La foto sigue siendo byte-idéntica: lo que cambió es el reloj. Sin esta
	 * comprobación, una publicación iniciada un minuto antes del cierre sellaría
	 * precios ya vencidos.
	 *
	 * @testdox Cruzar boundary_at entre la captura y la respuesta cierra la publicación.
	 */
	public function test_crossing_the_boundary_between_capture_and_response_closes(): void {
		$zone  = new DateTimeZone( 'America/Santiago' );
		$today = ( new DateTimeImmutable( '@' . $this->now ) )->setTimezone( $zone )->format( 'Y-m-d' );

		$this->db->raw_options['akibara_taxonomy_discount_to'] = $today;
		$this->install_ready_state();

		$capture = EditorialDiscountPublication::capture_price_authority();
		self::assertIsArray( $capture, 'Con la ventana cerrando hoy la foto sigue siendo válida.' );

		$boundary = $capture['price_snapshot']['boundary_at'];
		self::assertIsInt( $boundary, 'Dentro de la ventana el cierre es un límite futuro.' );
		self::assertTrue( $this->provider->snapshot_is_fresh( $capture['provider_token'] ) );

		$this->now = $boundary;

		self::assertFalse(
			$this->provider->snapshot_is_fresh( $capture['provider_token'] ),
			'Al alcanzar el cierre la foto deja de estar vigente.'
		);
		self::assertFalse(
			EditorialDiscountPublication::price_authority_matches( $capture ),
			'Y Search no puede publicar con ella.'
		);

		$this->expectException( PriceAuthorityUnavailable::class );
		( $this->provider )( 10000.0, $this->product( self::PRODUCT ) );
	}

	// -----------------------------------------------------------------------
	// Contención.
	// -----------------------------------------------------------------------

	/**
	 * @testdox Un fallo del proveedor es sticky para el resto del request.
	 */
	public function test_provider_failure_is_sticky_across_phases(): void {
		$capture = EditorialDiscountPublication::capture_price_authority();
		self::assertIsArray( $capture );

		// El feed pide un precio sin producto: el proveedor falla cerrado.
		try {
			( $this->provider )( 10000.0, null );
			self::fail( 'Debió lanzar.' );
		} catch ( PriceAuthorityUnavailable $e ) {
			unset( $e );
		}

		self::assertSame( SearchPriceProvider::STATE_FAILED, $this->provider->state() );
		self::assertFalse( $this->provider->snapshot_is_fresh( $capture['provider_token'] ) );
		self::assertFalse(
			EditorialDiscountPublication::price_authority_matches( $capture ),
			'Ninguna fase posterior del mismo request puede publicar.'
		);

		$this->expectException( PriceAuthorityUnavailable::class );
		( $this->provider )( 10000.0, $this->product( self::PRODUCT ) );
	}

	/**
	 * `resolve_price_provider()` exige un único callback en el hook. Un plugin
	 * de terceros que se enganche ahí no degrada a precio base: apaga la
	 * publicación entera.
	 *
	 * @testdox Un callback ajeno en el hook deja a Search sin proveedor resoluble.
	 */
	public function test_a_foreign_callback_on_the_hook_closes_the_capture(): void {
		$GLOBALS['wp_filter']['akb_search_effective_price']->callbacks[20] = array(
			'tercero' => array(
				'function'      => static fn( $base ) => $base,
				'accepted_args' => 1,
			),
		);

		self::assertNull(
			EditorialDiscountPublication::capture_price_authority(),
			'Con dos callbacks el proveedor no es resoluble y no hay captura.'
		);
	}

	/**
	 * Divergencia de dominio conocida [auditoría 7.4]: Search publica como
	 * taxonomía cualquier clave que devuelva el filtro
	 * `akibara_taxonomy_discount_taxonomies` con sólo un `trim()`, mientras
	 * Business exige `^[a-z0-9_-]{1,32}$`. Un tercero que inyecte `Pa_Editorial`
	 * no pierde ESE slug: tumba la publicación completa.
	 *
	 * El test fija el comportamiento de HOY —fail-closed, sin precio publicado—
	 * para que la corrección en Search (validar el dominio en origen, candidata
	 * a 0.1.15) tenga que actualizarlo conscientemente.
	 *
	 * @testdox Un slug fuera de dominio publicado por Search cierra la adopción entera.
	 */
	public function test_out_of_domain_slug_from_search_closes_the_whole_adoption(): void {
		$this->filter_overrides['akibara_taxonomy_discount_taxonomies'] = array(
			'product_brand' => 'Editoriales',
			'Pa_Editorial'  => 'Inyectada por un tercero',
		);
		$this->install_ready_state();

		self::assertNull(
			EditorialDiscountPublication::capture_price_authority(),
			'Business rechaza la foto y Search no puede publicar nada.'
		);
		self::assertSame( SearchPriceProvider::STATE_FAILED, $this->provider->state() );
	}

	// -----------------------------------------------------------------------
	// Utilidades.
	// -----------------------------------------------------------------------

	/**
	 * El doble se construía con `new WC_Product( 'instock' )`, de cuando la
	 * clase de prueba tomaba el estado de stock como primer argumento. El
	 * WC_Product canónico del buscador (tests/Support/wp-function-stubs.php)
	 * tipa `int $id`, así que la llamada lanzaba un TypeError ANTES de tocar el
	 * proveedor: los dos tests que esperan `PriceAuthorityUnavailable` fallaban
	 * por el motivo equivocado y el gate no protegía nada [2026-09-05].
	 *
	 * @param int $id Producto.
	 * @return WC_Product
	 */
	private function product( int $id ): WC_Product {
		return new PriceFilterProduct( $id );
	}

	private function install_provider( SearchPriceProvider $provider ): void {
		$GLOBALS['wp_filter']['akb_search_effective_price'] = (object) array(
			'callbacks' => array(
				10 => array(
					'provider' => array(
						'function'      => $provider,
						'accepted_args' => 2,
					),
				),
			),
		);
	}

	private function install_ready_state(): void {
		$options_method = new ReflectionMethod( EditorialDiscountPublication::class, 'source_options_from_bundle' );
		$options_method->setAccessible( true );
		$source_method = new ReflectionMethod( EditorialDiscountPublication::class, 'source_snapshot_from_options' );
		$source_method->setAccessible( true );

		$options = $options_method->invoke( null, $this->db->raw_options );
		$source  = $source_method->invoke(
			null,
			$options,
			array(
				'meta'       => array(
					array( 'meta_id' => 11, 'term_id' => self::TERM, 'raw' => '15' ),
				),
				'attributes' => array(),
			)
		);

		if ( ! is_array( $source ) ) {
			// Los casos que fuerzan un rechazo en origen no pueden sembrar el
			// state; el propio rechazo es lo que se está midiendo.
			$this->reset_source_latches();
			return;
		}

		$this->db->raw_options[ EditorialDiscountPublication::OPTION_STATE ] = (string) json_encode(
			array(
				'protocol'           => 1,
				'contract'           => 'akb-editorial-materialization-v1',
				'wanted_seq'         => 7,
				'wanted_fingerprint' => $source['fingerprint'],
				'window_active'      => (bool) $source['window_active'],
				'next_boundary_at'   => $source['boundary_at'],
				'source_term_ids'    => array( self::TERM ),
				'debt_staged'        => true,
				'active_run_id'      => '',
				'purge_pre_seq'      => 7,
				'purge_post_seq'     => 7,
				'reason'             => 'real-filter-integration-test',
				'updated_at'         => $this->now,
			)
		);
		$this->reset_source_latches();
	}

	/** @return array<string, mixed> */
	private function static_defaults(): array {
		return array(
			'registered'                => true,
			'runtime'                   => null,
			'state_read_failed'         => false,
			'test_serving_override'     => null,
			'test_price_match_sequence' => null,
			'term_meta_row_failed'      => false,
			'source_memo_set'           => false,
			'read_authority_available'  => true,
			'read_authority_error'      => '',
			'adopted_price_provider'    => null,
			'adopted_price_token'       => null,
			'adopted_price_snapshot'    => null,
			'adopted_provider_absent'   => false,
			'source_memo'               => null,
		);
	}

	private function reset_source_latches(): void {
		foreach (
			array(
				'source_memo_set'          => false,
				'source_memo'              => null,
				'state_read_failed'        => false,
				'read_authority_available' => true,
				'read_authority_error'     => '',
			) as $name => $value
		) {
			$property = new ReflectionProperty( EditorialDiscountPublication::class, $name );
			$property->setAccessible( true );
			$property->setValue( null, $value );
		}
	}
}
/**
 * Producto de este escenario: precio de lista 10.000 y 20.000, que son los
 * números con los que están escritas las expectativas (8.500 = 10.000 con el
 * 15 % editorial). El WC_Product canónico del suite devuelve 9.990 fijo, que es
 * correcto para el indexador y equivocado para esta aritmética.
 */
final class PriceFilterProduct extends WC_Product {

	/** @param int $id Producto. */
	public function __construct( int $id ) {
		parent::__construct( $id );
	}

	/**
	 * @param string $context Contexto, como en WooCommerce.
	 * @return string
	 */
	public function get_regular_price( string $context = 'view' ) {
		unset( $context );

		return PriceFilterIntegrationTest::PRODUCT === $this->get_id() ? '10000' : '20000';
	}

	/**
	 * @param string $context Contexto.
	 * @return string
	 */
	public function get_price( $context = 'view' ) {
		unset( $context );

		return $this->get_regular_price();
	}
}

