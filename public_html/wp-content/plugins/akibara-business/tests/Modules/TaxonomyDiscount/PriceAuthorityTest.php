<?php
/**
 * Contrato de AUTORIDAD del precio editorial que consume Search.
 *
 * El defecto que fijan estas pruebas no es de cálculo sino epistémico: el
 * resolver no distinguía "este producto no tiene descuento" de "no pude
 * averiguarlo". Los dos caminos devolvían `0` / `null`, el proveedor del filtro
 * `akb_search_effective_price` devolvía entonces el precio BASE, y Search
 * publicaba y cacheaba ese precio como si fuera autoritativo — en un documento
 * sellado por un rebuild, además, donde la cobertura de IDs no dice nada sobre
 * si el precio materializado es correcto.
 *
 * Dos lecturas pueden fallar en silencio:
 *
 *   - `get_the_terms()` devuelve `WP_Error` (taxonomía inexistente, fallo de
 *     consulta) y el código sólo miraba `is_array()`;
 *   - `get_term_meta()` devuelve `''` ante un fallo de DB —la MISMA forma que
 *     "no hay meta"— y ese `0` se memoizaba para el resto de la petición.
 *
 * Y lo que hace el fallo indetectable a posteriori: `$wpdb->last_error` es
 * global y volátil; **cualquier consulta exitosa posterior lo limpia**. Por eso
 * la autoridad se observa en el momento exacto de cada lectura y, una vez
 * perdida, envenena la instancia durante toda la petición.
 *
 * Los dobles imitan la forma REAL de Core: `get_the_terms()` devuelve
 * `array<WP_Term>|false|WP_Error`, y `get_term_meta( …, true )` devuelve un
 * escalar o `''` — nunca tipos que WordPress no expone.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\TaxonomyDiscount;

use Akibara\Business\Modules\TaxonomyDiscount\DiscountResolver;
use Akibara\Business\Modules\TaxonomyDiscount\PriceAuthorityUnavailable;
use Akibara\Business\Modules\TaxonomyDiscount\SearchPriceProvider;
use Akibara\Business\Modules\TaxonomyDiscount\Settings;
use Akibara\Business\Tests\Support\AuthorityWpdb;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WC_Product;
use WP_Error;
use WP_Term;

require_once dirname( __DIR__, 2 ) . '/Support/AuthorityWpdb.php';

final class PriceAuthorityTest extends TestCase {

	/** Término con 15 % editorial. */
	private const TERM_DISCOUNTED = 501;

	/** Término sin descuento (meta ausente). */
	private const TERM_PLAIN = 502;

	/**
	 * Respuesta de `get_the_terms()` por producto, con forma real de Core.
	 *
	 * @var array<int, mixed>
	 */
	private array $terms_by_product = array();

	/**
	 * Term-meta simulada: term_id → valor escalar devuelto por get_term_meta().
	 *
	 * @var array<int, mixed>
	 */
	private array $term_meta = array();

	/**
	 * Términos cuya lectura de meta debe fallar como lo hace MySQL: devuelve
	 * `''` y deja `$wpdb->last_error`.
	 *
	 * @var array<int, string>
	 */
	private array $term_meta_failures = array();

	/** Doble de $wpdb que modela la base y su `last_error` volátil. */
	private AuthorityWpdb $wpdb;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->terms_by_product   = array();
		$this->term_meta          = array( self::TERM_DISCOUNTED => '15' );
		$this->term_meta_failures = array();

		// Desde 1.99.15 el proveedor lee en CRUDO, así que el doble tiene que
		// modelar la base y no sólo `last_error`. Las APIs cacheadas siguen
		// stubeadas porque el camino TOLERANTE —el del storefront— sí las usa.
		$this->wpdb                 = new AuthorityWpdb();
		$this->wpdb->option_rows    = array(
			Settings::OPTION_ENABLED     => 'yes',
			Settings::OPTION_APPLY_TO    => Settings::APPLY_ALL,
			Settings::OPTION_SALE_POLICY => Settings::POLICY_GREATEST_WINS,
		);
		$this->wpdb->term_meta_rows = array( self::TERM_DISCOUNTED => '15' );

		$GLOBALS['wpdb'] = $this->wpdb;

		$terms    = &$this->terms_by_product;
		$meta     = &$this->term_meta;
		$failures = &$this->term_meta_failures;
		$wpdb     = $this->wpdb;

		Functions\when( 'is_wp_error' )->alias(
			static fn( $thing ): bool => $thing instanceof WP_Error
		);

		// Forma real: array<WP_Term> | false | WP_Error.
		Functions\when( 'get_the_terms' )->alias(
			static function ( $post_id, $taxonomy ) use ( &$terms ) {
				unset( $taxonomy );
				return $terms[ (int) $post_id ] ?? false;
			}
		);

		// Forma real de `get_term_meta( $id, $key, true )`: escalar o ''. Un
		// fallo de DB NO cambia el tipo devuelto: sólo deja rastro en $wpdb.
		Functions\when( 'get_term_meta' )->alias(
			static function ( $term_id, $key, $single = false ) use ( &$meta, &$failures, $wpdb ) {
				unset( $key, $single );
				$term_id = (int) $term_id;

				if ( isset( $failures[ $term_id ] ) ) {
					$wpdb->last_error = $failures[ $term_id ];
					return '';
				}

				return $meta[ $term_id ] ?? '';
			}
		);

		Functions\when( 'get_option' )->alias(
			static function ( string $name, $default_value = false ) {
				if ( Settings::OPTION_ENABLED === $name ) {
					return 'yes';
				}
				if ( Settings::OPTION_APPLY_TO === $name ) {
					return Settings::APPLY_ALL;
				}
				if ( Settings::OPTION_SALE_POLICY === $name ) {
					return Settings::POLICY_GREATEST_WINS;
				}
				return $default_value;
			}
		);

		Functions\when( 'wc_get_price_decimals' )->justReturn( 0 );

		// El camino estricto descarta el object cache que su lectura cruda acaba
		// de desmentir; en la suite basta con que las funciones existan.
		// El camino TOLERANTE sí consulta los atributos por la API de WC.
		Functions\when( 'wc_get_attribute_taxonomies' )->justReturn( array() );
		Functions\when( 'clean_object_term_cache' )->justReturn( null );
		Functions\when( 'wp_cache_delete' )->justReturn( true );

		// Sólo `product_brand` existe: el escenario usa una única taxonomía para
		// que las aserciones hablen del contrato de autoridad y no del barrido.
		Functions\when( 'taxonomy_exists' )->alias(
			static fn( string $taxonomy ): bool => 'product_brand' === $taxonomy
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	// -----------------------------------------------------------------------
	// Utilidades del caso.
	// -----------------------------------------------------------------------

	private function product( int $id, float $regular = 10000.0 ): WC_Product {
		$product                    = new WC_Product( 'instock' );
		$product->akb_id            = $id;
		$product->akb_regular_price = (string) $regular;

		return $product;
	}

	/**
	 * @param array<int, int> $term_ids Términos que el producto tiene.
	 */
	private function with_terms( int $product_id, array $term_ids ): void {
		$this->wpdb->relationships[ $product_id ] = array_map(
			static fn( int $term_id ): array => array( $term_id, 'product_brand' ),
			$term_ids
		);

		$this->terms_by_product[ $product_id ] = array_map(
			static function ( int $term_id ): WP_Term {
				$term = new WP_Term( $term_id, 'slug-' . $term_id, 'Term ' . $term_id );
				$term->taxonomy = 'product_brand';
				return $term;
			},
			$term_ids
		);
	}

	private function provider(): SearchPriceProvider {
		return new SearchPriceProvider( new DiscountResolver( new Settings() ) );
	}

	/**
	 * Camino ESTRICTO de lectura cruda, que es lo que estas pruebas ejercitan.
	 *
	 * Desde 1.99.16 el proveedor ya no captura: adopta la foto de Search (ver
	 * `PriceSnapshotParityTest`). El lector crudo sigue existiendo como fallback
	 * interno y su contrato —distinguir ausencia legítima de fallo— se prueba
	 * acá directamente sobre el resolver.
	 *
	 * @param float       $base    Precio base.
	 * @param WC_Product  $product Producto.
	 * @return float
	 *
	 * @throws PriceAuthorityUnavailable Si la lectura no fue autoritativa.
	 */
	private function strict_price( float $base, WC_Product $product ): float {
		$resolver = new DiscountResolver( new Settings() );
		$value    = $resolver->resolve_strict( $product );

		if ( ! $resolver->is_authoritative() ) {
			throw new PriceAuthorityUnavailable( $resolver->authority_error() );
		}

		return null !== $value ? (float) $value : $base;
	}


	// -----------------------------------------------------------------------
	// T1 / T2 — el contrato feliz sigue intacto.
	// -----------------------------------------------------------------------

	/**
	 * @testdox T1 con 15 % entrega precio descontado al feed de Search.
	 */
	public function test_discounted_term_yields_discounted_price(): void {
		$this->with_terms( 1001, array( self::TERM_DISCOUNTED ) );

		self::assertSame( 8500.0, $this->strict_price( 10000.0, $this->product( 1001 ) ) );
	}

	/**
	 * @testdox T2 sin descuento entrega el precio base, que es un resultado VÁLIDO.
	 */
	public function test_term_without_discount_yields_base_price(): void {
		$this->with_terms( 1002, array( self::TERM_PLAIN ) );

		self::assertSame( 10000.0, $this->strict_price( 10000.0, $this->product( 1002 ) ) );
	}

	/**
	 * @testdox La ausencia legítima de términos conserva el precio base.
	 */
	public function test_product_without_terms_keeps_base_price(): void {
		// `get_the_terms()` devuelve false: el producto no tiene términos.
		self::assertSame( 10000.0, $this->strict_price( 10000.0, $this->product( 1003 ) ) );
	}

	// -----------------------------------------------------------------------
	// Fallos de autoridad — el feed NO puede recibir precio base.
	// -----------------------------------------------------------------------

	/**
	 * El caso central: la lectura de term-meta falla, y DESPUÉS una consulta
	 * exitosa limpia `$wpdb->last_error`. Si la autoridad se comprobara al final
	 * —y no en el momento de la lectura— el rastro habría desaparecido y el
	 * proveedor entregaría precio base como si fuera bueno.
	 *
	 * @testdox Un fallo de term-meta seguido de una query exitosa igual impide entregar precio base.
	 */
	public function test_term_meta_failure_survives_a_later_successful_query(): void {
		$this->with_terms( 1004, array( self::TERM_DISCOUNTED ) );
		$this->term_meta_failures[ self::TERM_DISCOUNTED ] = 'MySQL server has gone away';
		$this->wpdb->fail_next( 'wp_termmeta', 'MySQL server has gone away' );

		$resolver = new DiscountResolver( new Settings() );

		self::assertNull( $resolver->resolve_strict( $this->product( 1004 ) ) );
		self::assertFalse( $resolver->is_authoritative() );
		self::assertStringContainsString( 'MySQL server has gone away', $resolver->authority_error() );

		// Cualquier consulta posterior exitosa limpia el rastro global…
		$this->wpdb->last_error = '';
		unset( $this->wpdb->fail_once['wp_termmeta'] );

		// …y aun así la instancia sigue envenenada para esta petición: puede
		// volver a calcular un número, pero ya no puede llamarlo autoritativo, y
		// es esa marca la que impide publicarlo.
		$resolver->resolve_strict( $this->product( 1004 ) );
		self::assertFalse( $resolver->is_authoritative() );
	}

	/**
	 * Desde 1.99.15 el proveedor estricto ya no pasa por `get_the_terms()` —lee
	 * relaciones en crudo—, pero el STOREFRONT sí, y ahí el `WP_Error` sigue sin
	 * poder leerse como "sin descuento": no lanza, pero pierde la autoridad, que
	 * es la señal con la que Search marca la página `no-cache`.
	 *
	 * @testdox Un WP_Error de get_the_terms() rompe la autoridad del camino tolerante.
	 */
	public function test_wp_error_from_get_the_terms_breaks_authority(): void {
		$this->terms_by_product[1005] = new WP_Error( 'invalid_taxonomy', 'Taxonomía no válida.' );

		$resolver = new DiscountResolver( new Settings() );

		self::assertNull( $resolver->resolve( $this->product( 1005 ) ) );
		self::assertFalse( $resolver->is_authoritative() );
		self::assertStringContainsString( 'WP_Error', $resolver->authority_error() );
	}

	/**
	 * Mismo reparto que arriba: el estricto lee `termmeta` en crudo, pero el
	 * camino tolerante sigue expuesto a un filtro de terceros que devuelva una
	 * forma que Core nunca expone.
	 *
	 * @testdox Una forma inválida de term-meta rompe la autoridad del camino tolerante.
	 */
	public function test_invalid_term_meta_shape_breaks_authority(): void {
		$this->with_terms( 1006, array( self::TERM_DISCOUNTED ) );
		// Un filtro de terceros sobre `get_term_meta` devolviendo un array.
		$this->term_meta[ self::TERM_DISCOUNTED ] = array( 'inesperado' );

		$resolver = new DiscountResolver( new Settings() );

		self::assertNull( $resolver->resolve( $this->product( 1006 ) ) );
		self::assertFalse( $resolver->is_authoritative() );
	}

	/**
	 * @testdox El 0 de una lectura fallida NUNCA se memoiza.
	 */
	public function test_failed_read_is_never_memoized(): void {
		$settings = new Settings();
		$this->term_meta_failures[ self::TERM_DISCOUNTED ] = 'Lost connection to MySQL server';
		$this->wpdb->fail_next( 'wp_termmeta', 'Lost connection to MySQL server' );

		self::assertSame( 0, $settings->get_term_pct( self::TERM_DISCOUNTED ) );
		self::assertFalse( $settings->authority_available() );

		// La DB se recupera; el valor real vuelve a estar disponible.
		unset( $this->term_meta_failures[ self::TERM_DISCOUNTED ], $this->wpdb->fail_once['wp_termmeta'] );
		$this->wpdb->last_error = '';

		// Una instancia NUEVA (petición nueva) lee el valor verdadero: si el 0
		// se hubiera memoizado, este 15 sería imposible de recuperar.
		self::assertSame( 15, ( new Settings() )->get_term_pct( self::TERM_DISCOUNTED ) );
	}

	/**
	 * @testdox Una instancia nueva y sana vuelve a resolver con normalidad.
	 */
	public function test_a_fresh_instance_recovers(): void {
		$this->with_terms( 1007, array( self::TERM_DISCOUNTED ) );
		$this->term_meta_failures[ self::TERM_DISCOUNTED ] = 'Deadlock found';
		$this->wpdb->fail_next( 'wp_termmeta', 'Deadlock found' );

		$poisoned = $this->provider();
		try {
			$poisoned( 10000.0, $this->product( 1007 ) );
		} catch ( PriceAuthorityUnavailable $e ) {
			unset( $e );
		}

		unset( $this->term_meta_failures[ self::TERM_DISCOUNTED ], $this->wpdb->fail_once['wp_termmeta'] );
		$this->wpdb->last_error = '';

		self::assertSame( 8500.0, $this->strict_price( 10000.0, $this->product( 1007 ) ) );
	}

	// -----------------------------------------------------------------------
	// El contrato estricto es SÓLO para el feed de Search.
	// -----------------------------------------------------------------------

	/**
	 * El storefront usa el mismo resolver a través de los filtros de precio de
	 * WooCommerce. Ahí un fallo de lectura NO puede volverse fatal: degradar a
	 * precio base en una página es aceptable; sellarlo en el índice no.
	 *
	 * @testdox Con la autoridad perdida, el resolver sigue devolviendo null sin lanzar.
	 */
	public function test_storefront_path_does_not_become_fatal(): void {
		$this->with_terms( 1008, array( self::TERM_DISCOUNTED ) );
		$this->term_meta_failures[ self::TERM_DISCOUNTED ] = 'Query execution was interrupted';
		$this->wpdb->fail_next( 'wp_termmeta', 'Query execution was interrupted' );

		$resolver = new DiscountResolver( new Settings() );

		self::assertNull( $resolver->resolve( $this->product( 1008 ) ) );
		self::assertFalse( $resolver->is_authoritative() );
		self::assertNotSame( '', $resolver->authority_error() );
	}

	/**
	 * El contrato estricto tiene que quedarse en el feed. Si `PriceFilters`
	 * —los filtros de precio de WooCommerce que pintan tienda, carrito y PDP—
	 * empezara a usar el proveedor o su excepción, un fallo de lectura tumbaría
	 * el storefront entero. Se verifica sobre la FUENTE porque es una frontera
	 * arquitectónica, no un valor de runtime.
	 *
	 * @testdox Los filtros de precio de WooCommerce no consumen el contrato estricto de Search.
	 */
	public function test_strict_contract_does_not_leak_into_storefront_filters(): void {
		$source = (string) file_get_contents(
			dirname( __DIR__, 3 ) . '/src/Modules/TaxonomyDiscount/PriceFilters.php'
		);

		self::assertStringNotContainsString( 'SearchPriceProvider', $source );
		self::assertStringNotContainsString( 'PriceAuthorityUnavailable', $source );
	}

	/**
	 * @testdox El resolver no memoiza un precio calculado sin autoridad.
	 */
	public function test_resolver_does_not_memoize_unauthoritative_results(): void {
		$this->with_terms( 1009, array( self::TERM_DISCOUNTED ) );
		$this->term_meta_failures[ self::TERM_DISCOUNTED ] = 'Deadlock found';
		$this->wpdb->fail_next( 'wp_termmeta', 'Deadlock found' );

		$resolver = new DiscountResolver( new Settings() );
		self::assertNull( $resolver->resolve( $this->product( 1009 ) ) );

		// Aunque la DB se recupere, ESTA instancia queda envenenada a propósito;
		// lo que no puede pasar es que sirva el null memoizado como verdad.
		unset( $this->term_meta_failures[ self::TERM_DISCOUNTED ], $this->wpdb->fail_once['wp_termmeta'] );
		$this->wpdb->last_error = '';

		self::assertFalse( $resolver->is_authoritative() );
		self::assertSame( 8500.0, ( new DiscountResolver( new Settings() ) )->resolve( $this->product( 1009 ) ) );
	}
}
