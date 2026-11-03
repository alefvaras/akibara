<?php
/**
 * Autoridad COMPLETA del precio editorial: todas las fronteras, no sólo term-meta.
 *
 * La ronda 4 cerró `get_the_terms() => WP_Error` y el fallo de `get_term_meta()`,
 * pero dejó abiertas cinco rutas por las que el proveedor estricto seguía
 * devolviendo un precio con `is_authoritative() === true`:
 *
 *   1. `get_option()` puede devolver el DEFAULT porque `alloptions`/`notoptions`
 *      están contaminados — sin error SQL que observar, porque no hubo consulta;
 *   2. lo mismo para from/to, apply_to y sale_policy: el default cambia la
 *      semántica del precio en silencio;
 *   3. `wc_get_attribute_taxonomies()` degrada a `[]` y transienta ese vacío, con
 *      lo que una taxonomía entera desaparece del barrido;
 *   4. el object cache de relaciones y de term-meta puede estar envenenado con
 *      vacíos que son indistinguibles de una ausencia legítima;
 *   5. `wc_get_price_decimals()` o la zona horaria degradan a un default y
 *      cambian el precio redondeado o la vigencia de la ventana.
 *
 * Por eso el camino estricto no consulta las APIs cacheadas de WordPress: toma
 * una foto RAW y observable de todo lo que decide el precio. Las ausencias
 * legítimas siguen siendo válidas; los defaults nacidos de un error, no.
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

require_once dirname( __DIR__, 2 ) . '/Support/AuthorityWpdb.php';

final class PriceAuthorityRawTest extends TestCase {

	private const PRODUCT = 4242;

	private const TERM_BRAND = 501;

	private const TERM_ATTR = 777;

	private AuthorityWpdb $wpdb;

	/**
	 * Lo que las APIs CACHEADAS de WordPress responden. Se mantiene aparte de
	 * `$this->wpdb` a propósito: la divergencia entre ambas es el contraejemplo.
	 *
	 * @var array<string, mixed>
	 */
	private array $cached_options = array();

	/** @var array<int, mixed> */
	private array $cached_terms = array();

	/** @var array<int, mixed> */
	private array $cached_term_meta = array();

	/** @var array<int, object> */
	private array $cached_attributes = array();

	private int $cached_decimals = 0;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->wpdb                    = new AuthorityWpdb();
		$this->wpdb->option_rows       = array(
			Settings::OPTION_ENABLED     => 'yes',
			Settings::OPTION_APPLY_TO    => Settings::APPLY_ALL,
			Settings::OPTION_SALE_POLICY => Settings::POLICY_GREATEST_WINS,
			'woocommerce_price_num_decimals' => '0',
			'timezone_string'                => 'America/Santiago',
		);
		$this->wpdb->term_meta_rows    = array( self::TERM_BRAND => '15' );
		$this->wpdb->relationships     = array(
			self::PRODUCT => array( array( self::TERM_BRAND, 'product_brand' ) ),
		);
		$this->wpdb->attribute_rows    = array(
			array(
				'attribute_id'    => '7',
				'attribute_name'  => 'editorial',
				'attribute_label' => 'Editorial',
			),
		);

		$GLOBALS['wpdb'] = $this->wpdb;

		// Por defecto las APIs cacheadas dicen la VERDAD; cada caso las desvía.
		$this->cached_options = array(
			Settings::OPTION_ENABLED     => 'yes',
			Settings::OPTION_APPLY_TO    => Settings::APPLY_ALL,
			Settings::OPTION_SALE_POLICY => Settings::POLICY_GREATEST_WINS,
		);
		$this->cached_terms      = array( self::PRODUCT => array( $this->term( self::TERM_BRAND, 'product_brand' ) ) );
		$this->cached_term_meta  = array( self::TERM_BRAND => '15' );
		$this->cached_attributes = array( (object) array( 'attribute_name' => 'editorial', 'attribute_label' => 'Editorial' ) );
		$this->cached_decimals   = 0;

		$options    = &$this->cached_options;
		$terms      = &$this->cached_terms;
		$term_meta  = &$this->cached_term_meta;
		$attributes = &$this->cached_attributes;
		$decimals   = &$this->cached_decimals;

		Functions\when( 'is_wp_error' )->alias( static fn( $thing ): bool => $thing instanceof \WP_Error );
		Functions\when( 'get_option' )->alias(
			static function ( string $name, $default_value = false ) use ( &$options ) {
				return array_key_exists( $name, $options ) ? $options[ $name ] : $default_value;
			}
		);
		Functions\when( 'get_the_terms' )->alias(
			static function ( $post_id, $taxonomy ) use ( &$terms ) {
				$found = $terms[ (int) $post_id ] ?? false;
				if ( ! is_array( $found ) ) {
					return $found;
				}
				$filtered = array_values( array_filter( $found, static fn( $t ) => $t->taxonomy === $taxonomy ) );
				return empty( $filtered ) ? false : $filtered;
			}
		);
		Functions\when( 'get_term_meta' )->alias(
			static function ( $term_id, $key, $single = false ) use ( &$term_meta ) {
				unset( $key, $single );
				return $term_meta[ (int) $term_id ] ?? '';
			}
		);
		Functions\when( 'wc_get_attribute_taxonomies' )->alias(
			static function () use ( &$attributes ) {
				return $attributes;
			}
		);
		Functions\when( 'wc_get_price_decimals' )->alias(
			static function () use ( &$decimals ): int {
				return $decimals;
			}
		);
		Functions\when( 'taxonomy_exists' )->alias(
			static fn( string $taxonomy ): bool => in_array( $taxonomy, array( 'product_brand', 'product_cat', 'product_tag', 'pa_editorial' ), true )
		);
		Functions\when( 'current_time' )->justReturn( '2026-08-06' );
		Functions\when( 'clean_object_term_cache' )->justReturn( null );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	// -----------------------------------------------------------------------
	// Utilidades.
	// -----------------------------------------------------------------------

	private function term( int $term_id, string $taxonomy ): \WP_Term {
		$term           = new \WP_Term( $term_id, 'slug-' . $term_id, 'Term ' . $term_id );
		$term->taxonomy = $taxonomy;
		return $term;
	}

	private function product( float $regular = 10000.0, float $sale = 0.0 ): WC_Product {
		$product                    = new WC_Product( 'instock' );
		$product->akb_id            = self::PRODUCT;
		$product->akb_regular_price = (string) $regular;
		if ( $sale > 0 ) {
			$product->akb_sale_price = (string) $sale;
			$product->akb_on_sale    = true;
		}
		return $product;
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
	// 1 y 2 — opciones: el default nacido de un cache contaminado.
	// -----------------------------------------------------------------------

	/**
	 * @testdox Contraejemplo 1: la DB dice enabled=yes pero get_option() devuelve el default; el estricto usa la DB.
	 */
	public function test_enabled_option_is_read_raw_not_from_poisoned_cache(): void {
		$this->cached_options[ Settings::OPTION_ENABLED ] = 'no'; // notoptions contaminado.

		self::assertSame( 8500.0, $this->strict_price( 10000.0, $this->product() ) );
	}

	/**
	 * @testdox Contraejemplo 1b: si la lectura RAW de opciones falla, el proveedor lanza.
	 */
	public function test_failed_raw_option_read_breaks_authority(): void {
		$this->wpdb->fail_next( 'wp_options', 'MySQL server has gone away' );

		$this->expectException( PriceAuthorityUnavailable::class );
		$this->strict_price( 10000.0, $this->product() );
	}

	/**
	 * @testdox Contraejemplo 2: sale_policy degradada al default cambia el precio; el estricto usa la de la DB.
	 */
	public function test_sale_policy_default_does_not_decide_the_price(): void {
		// La DB manda GREATEST_WINS; el cache degrada a SKIP_IF_ON_SALE, que con
		// un producto en oferta devolvería el precio de oferta en vez del mejor.
		$this->cached_options[ Settings::OPTION_SALE_POLICY ] = Settings::POLICY_SKIP_IF_ON_SALE;

		$product = $this->product( 10000.0, 9000.0 );

		// greatest_wins: min(10000*0.85, 9000) = 8500.
		self::assertSame( 8500.0, $this->strict_price( 9000.0, $product ) );
	}

	/**
	 * @testdox Contraejemplo 2b: una opción con valor fuera de dominio rompe la autoridad.
	 */
	public function test_out_of_domain_option_breaks_authority(): void {
		$this->wpdb->option_rows[ Settings::OPTION_SALE_POLICY ] = 'politica-inventada';

		$this->expectException( PriceAuthorityUnavailable::class );
		$this->strict_price( 10000.0, $this->product() );
	}

	// -----------------------------------------------------------------------
	// 3 — atributos WC.
	// -----------------------------------------------------------------------

	/**
	 * @testdox Contraejemplo 3: si wc_get_attribute_taxonomies() degrada a vacío, el estricto igual barre pa_editorial.
	 */
	public function test_attribute_taxonomies_are_read_raw(): void {
		$this->cached_attributes = array(); // transient envenenado con vacío.

		// El descuento vive en un término de pa_editorial, no en product_brand.
		$this->wpdb->relationships  = array( self::PRODUCT => array( array( self::TERM_ATTR, 'pa_editorial' ) ) );
		$this->wpdb->term_meta_rows = array( self::TERM_ATTR => '15' );
		$this->cached_terms         = array( self::PRODUCT => array( $this->term( self::TERM_ATTR, 'pa_editorial' ) ) );
		$this->cached_term_meta     = array( self::TERM_ATTR => '15' );

		self::assertSame( 8500.0, $this->strict_price( 10000.0, $this->product() ) );
	}

	/**
	 * @testdox Contraejemplo 3b: si la lectura RAW de atributos falla, el proveedor lanza.
	 */
	public function test_failed_attribute_read_breaks_authority(): void {
		$this->wpdb->fail_next( 'attribute_taxonomies', 'Table is marked as crashed' );

		$this->expectException( PriceAuthorityUnavailable::class );
		$this->strict_price( 10000.0, $this->product() );
	}

	// -----------------------------------------------------------------------
	// 4 — relaciones objeto ↔ término.
	// -----------------------------------------------------------------------

	/**
	 * @testdox Contraejemplo 4: un relationship cache vacío no puede leerse como ausencia legítima.
	 */
	public function test_poisoned_relationship_cache_does_not_hide_the_discount(): void {
		$this->cached_terms = array(); // update_object_term_cache dejó vacío.

		self::assertSame( 8500.0, $this->strict_price( 10000.0, $this->product() ) );
		self::assertTrue( $this->wpdb->queried( 'wp_term_relationships' ), 'El estricto debe consultar relaciones en crudo.' );
	}

	/**
	 * @testdox Contraejemplo 4b: si la consulta RAW de relaciones falla, el proveedor lanza.
	 */
	public function test_failed_relationship_read_breaks_authority(): void {
		$this->wpdb->fail_next( 'wp_term_relationships', 'Lost connection to MySQL server' );

		$this->expectException( PriceAuthorityUnavailable::class );
		$this->strict_price( 10000.0, $this->product() );
	}

	// -----------------------------------------------------------------------
	// 5 — term-meta.
	// -----------------------------------------------------------------------

	/**
	 * @testdox Contraejemplo 5: un term-meta cacheado vacío no puede leerse como "sin descuento".
	 */
	public function test_poisoned_term_meta_cache_does_not_hide_the_discount(): void {
		$this->cached_term_meta = array(); // update_meta_cache cacheó vacío.

		self::assertSame( 8500.0, $this->strict_price( 10000.0, $this->product() ) );
		self::assertTrue( $this->wpdb->queried( 'wp_termmeta' ) );
	}

	/**
	 * @testdox Contraejemplo 5b: si la lectura RAW de term-meta falla, el proveedor lanza.
	 */
	public function test_failed_raw_term_meta_read_breaks_authority(): void {
		$this->wpdb->fail_next( 'wp_termmeta', 'Deadlock found when trying to get lock' );

		$this->expectException( PriceAuthorityUnavailable::class );
		$this->strict_price( 10000.0, $this->product() );
	}

	/**
	 * @testdox Una ausencia REAL de term-meta sigue siendo válida y conserva precio base.
	 */
	public function test_genuinely_absent_term_meta_keeps_base_price(): void {
		$this->wpdb->term_meta_rows = array(); // no hay fila: ausencia legítima.
		$this->cached_term_meta     = array();

		self::assertSame( 10000.0, $this->strict_price( 10000.0, $this->product() ) );
	}

	/**
	 * @testdox Un producto sin relaciones conserva precio base y mantiene la autoridad.
	 */
	public function test_product_without_terms_keeps_base_price(): void {
		$this->wpdb->relationships = array();
		$this->cached_terms        = array();

		$resolver = new DiscountResolver( new Settings() );
		self::assertNull( $resolver->resolve_strict( $this->product() ) );
		self::assertTrue( $resolver->is_authoritative() );
	}

	// -----------------------------------------------------------------------
	// 6 — decimales y zona horaria.
	// -----------------------------------------------------------------------

	/**
	 * @testdox Contraejemplo 6: los decimales degradados cambiarían el precio; el estricto usa los de la DB.
	 */
	public function test_price_decimals_are_read_raw(): void {
		$this->wpdb->option_rows['woocommerce_price_num_decimals'] = '2';
		$this->cached_decimals = 0; // wc_get_price_decimals() degradado.

		// 9999 * 0.85 = 8499.15 → con 2 decimales, 8499.15; con 0, 8499.
		self::assertSame( 8499.15, $this->strict_price( 9999.0, $this->product( 9999.0 ) ) );
	}

	/**
	 * @testdox Contraejemplo 6b: decimales fuera de dominio rompen la autoridad.
	 */
	public function test_out_of_domain_decimals_break_authority(): void {
		$this->wpdb->option_rows['woocommerce_price_num_decimals'] = '99';

		$this->expectException( PriceAuthorityUnavailable::class );
		$this->strict_price( 10000.0, $this->product() );
	}

	/**
	 * @testdox El límite compartido de ocho decimales sigue siendo autoritativo.
	 */
	public function test_eight_price_decimals_match_the_adopted_contract(): void {
		$this->wpdb->option_rows['woocommerce_price_num_decimals'] = '8';

		self::assertSame( 1.04938271, $this->strict_price( 1.23456789, $this->product( 1.23456789 ) ) );
	}

	/**
	 * @testdox Una fila de decimales presente y vacía no se confunde con ausencia.
	 */
	public function test_empty_price_decimals_break_authority(): void {
		$this->wpdb->option_rows['woocommerce_price_num_decimals'] = '';

		$this->expectException( PriceAuthorityUnavailable::class );
		$this->strict_price( 10000.0, $this->product() );
	}

	/**
	 * @testdox La ausencia real de la fila conserva el default dos.
	 */
	public function test_absent_price_decimals_use_two(): void {
		unset( $this->wpdb->option_rows['woocommerce_price_num_decimals'] );

		self::assertSame( 8499.15, $this->strict_price( 9999.0, $this->product( 9999.0 ) ) );
	}

	/**
	 * La ventana de vigencia se evalúa contra una fecha; si la zona horaria
	 * degrada a un default, un descuento vigente puede leerse como expirado.
	 *
	 * @testdox Contraejemplo 6c: una zona horaria ilegible rompe la autoridad.
	 */
	public function test_unreadable_timezone_breaks_authority(): void {
		$this->wpdb->option_rows[ Settings::OPTION_TO ] = '2026-08-06';
		unset( $this->wpdb->option_rows['timezone_string'] );
		$this->wpdb->option_rows['gmt_offset'] = 'no-es-un-offset';

		$this->expectException( PriceAuthorityUnavailable::class );
		$this->strict_price( 10000.0, $this->product() );
	}

	// -----------------------------------------------------------------------
	// 7 — filtro de taxonomías soportadas.
	// -----------------------------------------------------------------------

	/**
	 * @testdox Contraejemplo 7: un filtro de taxonomías con forma inválida rompe la autoridad.
	 */
	public function test_invalid_taxonomy_filter_breaks_authority(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( string $hook, $value ) {
				return 'akibara_taxonomy_discount_taxonomies' === $hook ? 'no-soy-un-array' : $value;
			}
		);

		$this->expectException( PriceAuthorityUnavailable::class );
		$this->strict_price( 10000.0, $this->product() );
	}

	// -----------------------------------------------------------------------
	// Addendum A — memo tolerante precalentado, DB cambiada, provider estricto.
	// -----------------------------------------------------------------------

	/**
	 * El storefront calcula y memoiza con la configuración `A`; la base pasa a
	 * `B`; Search corre después sobre la MISMA instancia. El proveedor estricto
	 * no puede servir el memo `A`.
	 *
	 * @testdox Addendum A: el proveedor estricto nunca reutiliza el memo tolerante previo.
	 */
	public function test_strict_provider_never_reuses_the_tolerant_memo(): void {
		$settings = new Settings();
		$resolver = new DiscountResolver( $settings );

		// (A) el storefront memoiza con 15 %.
		self::assertSame( 8500.0, $resolver->resolve( $this->product() ) );
		$fingerprint_a = $settings->reader()->fingerprint();

		// (B) la base cambia: el término pasa a 10 %.
		$this->wpdb->term_meta_rows[ self::TERM_BRAND ] = '10';
		$this->cached_term_meta[ self::TERM_BRAND ]     = '15'; // el cache sigue en A.

		self::assertSame( 9000.0, $resolver->resolve_strict( $this->product() ), 'El estricto debe leer B, no el memo A.' );
		self::assertNotSame( $fingerprint_a, $settings->reader()->fingerprint(), 'La huella debe cambiar con la DB.' );
	}

	/**
	 * @testdox Addendum A: tras el proveedor estricto, el precio tolerante posterior coincide con el autoritativo.
	 */
	public function test_price_html_after_strict_reuses_the_authoritative_value(): void {
		$settings = new Settings();
		$resolver = new DiscountResolver( $settings );

		self::assertSame( 8500.0, $resolver->resolve_strict( $this->product() ) );

		// El camino tolerante (el que alimenta get_price_html) reutiliza el
		// resultado ya autoritativo en vez de recalcular contra el cache.
		$this->cached_term_meta[ self::TERM_BRAND ] = '5';

		self::assertSame( 8500.0, $resolver->resolve( $this->product() ) );
	}

	// -----------------------------------------------------------------------
	// Storefront: no lanza, pero reporta.
	// -----------------------------------------------------------------------

	/**
	 * @testdox El storefront no lanza ante un fallo, pero deja la autoridad en false.
	 */
	public function test_storefront_reports_lost_authority_without_throwing(): void {
		$this->wpdb->fail_next( 'wp_termmeta', 'Query execution was interrupted' );

		$settings = new Settings();
		$resolver = new DiscountResolver( $settings );

		self::assertNull( $resolver->resolve_strict( $this->product() ) );
		self::assertFalse( $resolver->is_authoritative() );
		self::assertNotSame( '', $resolver->authority_error() );
	}

	/**
	 * @testdox El predicado de autoridad queda expuesto para que Search marque no-cache.
	 */
	public function test_authority_predicate_is_exposed_for_consumers(): void {
		$this->wpdb->fail_next( 'wp_termmeta', 'Query execution was interrupted' );

		$resolver = new DiscountResolver( new Settings() );
		$resolver->resolve_strict( $this->product() );

		// Sin filtro puente: Search resuelve el objeto proveedor y le pregunta
		// directamente [ronda 6].
		$provider = new SearchPriceProvider( $resolver );
		self::assertFalse( $provider->is_authoritative() );
	}

	// -----------------------------------------------------------------------
	// Addenda P0 del revisor: paridad de atributos, decimales y offset.
	// -----------------------------------------------------------------------

	/**
	 * Un callback del filtro que decide POR LABEL sólo funciona si el mapa que
	 * ve Business es idéntico al que Search entrega: label real y orden por
	 * `attribute_id`. Con un label fabricado (`pa_slug`) este filtro no
	 * encontraría nada y el precio se calcularía sobre otro conjunto.
	 *
	 * @testdox El filtro recibe el LABEL REAL de cada atributo, en orden de attribute_id.
	 */
	public function test_attribute_map_exposes_real_labels_in_id_order(): void {
		$this->wpdb->attribute_rows = array(
			array( 'attribute_id' => '9', 'attribute_name' => 'genero', 'attribute_label' => 'Género' ),
			array( 'attribute_id' => '3', 'attribute_name' => 'editorial', 'attribute_label' => 'Editorial' ),
		);

		$seen = array();
		Functions\when( 'apply_filters' )->alias(
			static function ( string $hook, $value ) use ( &$seen ) {
				if ( 'akibara_taxonomy_discount_taxonomies' === $hook && is_array( $value ) ) {
					$seen = $value;
				}
				return $value;
			}
		);
		Functions\when( 'taxonomy_exists' )->justReturn( true );

		$this->strict_price( 10000.0, $this->product() );

		self::assertSame( 'Editorial', $seen['pa_editorial'] ?? null, 'El label debe ser el real, no pa_slug.' );
		self::assertSame( 'Género', $seen['pa_genero'] ?? null );

		$attribute_keys = array_values( array_filter( array_keys( $seen ), static fn( $k ) => 0 === strpos( $k, 'pa_' ) ) );
		self::assertSame(
			array( 'pa_editorial', 'pa_genero' ),
			$attribute_keys,
			'El orden debe seguir attribute_id (3 antes que 9), no el de inserción.'
		);
	}

	/**
	 * @testdox Un atributo sin attribute_name rompe la autoridad: el barrido quedaría incompleto.
	 */
	public function test_attribute_without_name_breaks_authority(): void {
		$this->wpdb->attribute_rows = array(
			array( 'attribute_id' => '3', 'attribute_name' => '', 'attribute_label' => 'Roto' ),
		);

		$this->expectException( PriceAuthorityUnavailable::class );
		$this->strict_price( 10000.0, $this->product() );
	}

	/**
	 * @testdox Con option=2 y filtro no-op, los decimales son 2 y el precio se redondea a 2.
	 */
	public function test_decimals_option_two_with_noop_filter(): void {
		$this->wpdb->option_rows['woocommerce_price_num_decimals'] = '2';

		$settings = new Settings();
		$resolver = new DiscountResolver( $settings );

		self::assertSame( 8499.15, $resolver->resolve_strict( $this->product( 9999.0 ) ) );
		self::assertNotSame( '', $settings->authority()->token() );
	}

	/**
	 * El filtro `wc_get_price_decimals` se aplica UNA vez sobre el valor crudo:
	 * si un tema lo baja a 0, ese 0 manda y tiene que reflejarse tanto en el
	 * precio como en el token.
	 *
	 * @testdox Con option=2 y filtro => 0, mandan los 0 decimales y el token cambia.
	 */
	public function test_decimals_option_two_with_filter_forcing_zero(): void {
		$this->wpdb->option_rows['woocommerce_price_num_decimals'] = '2';

		$baseline_settings = new Settings();
		$baseline          = new DiscountResolver( $baseline_settings );
		$baseline->resolve_strict( $this->product( 9999.0 ) );
		$token_with_two = $baseline_settings->authority()->token();

		Functions\when( 'apply_filters' )->alias(
			static function ( string $hook, $value ) {
				return 'wc_get_price_decimals' === $hook ? 0 : $value;
			}
		);

		$settings = new Settings();
		$resolver = new DiscountResolver( $settings );

		self::assertSame( 8499.0, $resolver->resolve_strict( $this->product( 9999.0 ) ) );
		self::assertNotSame( $token_with_two, $settings->authority()->token(), 'Los decimales efectivos son parte de la huella.' );
	}

	/**
	 * @testdox Un gmt_offset fraccional (5.5) no se trunca a horas enteras.
	 */
	public function test_fractional_gmt_offset_is_not_truncated(): void {
		unset( $this->wpdb->option_rows['timezone_string'] );
		$this->wpdb->option_rows['gmt_offset'] = '5.5';

		$reader = new \Akibara\Business\Modules\TaxonomyDiscount\AuthoritativeReader(
			new \Akibara\Business\Modules\TaxonomyDiscount\PriceAuthority()
		);

		$expected = ( new \DateTimeImmutable( 'now', new \DateTimeZone( '+05:30' ) ) )->format( 'Y-m-d' );
		self::assertSame( $expected, $reader->site_today() );
	}

	/**
	 * @testdox Un offset de cuarto de hora (5.75) tampoco se trunca.
	 */
	public function test_quarter_hour_gmt_offset_is_respected(): void {
		unset( $this->wpdb->option_rows['timezone_string'] );
		$this->wpdb->option_rows['gmt_offset'] = '5.75';

		$reader = new \Akibara\Business\Modules\TaxonomyDiscount\AuthoritativeReader(
			new \Akibara\Business\Modules\TaxonomyDiscount\PriceAuthority()
		);

		$expected = ( new \DateTimeImmutable( 'now', new \DateTimeZone( '+05:45' ) ) )->format( 'Y-m-d' );
		self::assertSame( $expected, $reader->site_today() );
	}

	// -----------------------------------------------------------------------
	// API pública A → B, sin Reflection.
	// -----------------------------------------------------------------------

	/**
	 * La secuencia exacta del addendum: `A` precalentado, la base pasa a `B`, el
	 * consumidor usa SÓLO la API pública para adoptar `B`, y el precio que
	 * obtiene es el de `B`.
	 *
	 * @testdox API pública: A precalentado, DB en B, el consumidor adopta B y obtiene B.
	 */
	public function test_public_api_adopts_b_after_a_was_warmed(): void {
		$settings = new Settings();
		$resolver = new DiscountResolver( $settings );

		// (A) el storefront precalienta con 15 %.
		self::assertSame( 8500.0, $resolver->resolve( $this->product() ) );

		// (B) la base cambia.
		$this->wpdb->term_meta_rows[ self::TERM_BRAND ] = '10';
		$this->cached_term_meta[ self::TERM_BRAND ]     = '15'; // el cache sigue en A.

		// Captura nueva explícita y relectura: el precio debe venir de B.
		$resolver->invalidate_derived_memos();

		self::assertSame( 9000.0, $resolver->resolve_strict( $this->product() ), 'El precio debe venir de B.' );
		self::assertNotSame( '', $settings->authority()->token() );
	}

	/**
	 * @testdox API pública: cualquier invalidación posterior hace fallar el final-check.
	 */
	public function test_public_api_final_check_detects_later_invalidation(): void {
		$settings = new Settings();
		$resolver = new DiscountResolver( $settings );
		$resolver->resolve_strict( $this->product() );
		$token = $settings->authority()->token();

		// Otro actor invalida los memos: el epoch avanza y el token caduca.
		$resolver->invalidate_derived_memos();
		$resolver->resolve_strict( $this->product() );

		self::assertNotSame( $token, $settings->authority()->token() );
	}

	/**
	 * @testdox API pública: si la foto no se puede acreditar, prepare falla cerrado.
	 */
	public function test_public_api_fails_closed_when_snapshot_cannot_be_taken(): void {
		$this->wpdb->fail_next( 'wp_options', 'MySQL server has gone away' );

		$resolver = new DiscountResolver( new Settings() );
		$resolver->resolve_strict( $this->product() );

		self::assertFalse( $resolver->is_authoritative(), 'Sin foto acreditable no hay autoridad.' );
	}

	/**
	 * @testdox La foto canónica se lee en UNA sola consulta de opciones.
	 */
	public function test_snapshot_reads_options_once(): void {
		$this->strict_price( 10000.0, $this->product() );

		$option_queries = array_filter(
			$this->wpdb->queries,
			static fn( string $query ): bool => false !== strpos( $query, 'option_name' )
		);

		self::assertCount( 1, $option_queries, 'La foto canónica debe leerse de una sola vez.' );
	}
}
