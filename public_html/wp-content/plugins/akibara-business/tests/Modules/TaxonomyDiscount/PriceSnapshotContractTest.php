<?php
/**
 * Snapshot canónico COMPARABLE y forma cruda cerrada.
 *
 * Un token que sólo demuestre la estabilidad interna de Business no cierra la
 * frontera con Search: hace falta que ambos lados puedan comparar **la misma
 * foto efectiva**. El contraejemplo que obliga a esto secuencia el hook por
 * caller — Search captura `max_pct` 15, Business observa 25, el final-check de
 * Search vuelve a ver 15 y el token de Business sigue "fresco". Ese precio no
 * puede servirse bajo un receipt de 15.
 *
 * Y antes de declarar autoridad, las lecturas crudas tienen que rechazar toda
 * forma que no sea exactamente la esperada: `intval()` convierte basura en un
 * entero plausible, `is_numeric()` acepta `1e2` y `' 15 '`, y un `LIMIT 1` sobre
 * meta duplicada elige una fila al azar.
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

final class PriceSnapshotContractTest extends TestCase {

	private const PRODUCT = 4242;

	private const TERM = 501;

	private AuthorityWpdb $wpdb;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->wpdb                 = new AuthorityWpdb();
		$this->wpdb->option_rows    = array(
			Settings::OPTION_ENABLED         => 'yes',
			Settings::OPTION_APPLY_TO        => Settings::APPLY_ALL,
			Settings::OPTION_SALE_POLICY     => Settings::POLICY_GREATEST_WINS,
			'woocommerce_price_num_decimals' => '2',
			'timezone_string'                => 'America/Santiago',
		);
		$this->wpdb->term_meta_rows = array( self::TERM => '15' );
		$this->wpdb->relationships  = array(
			self::PRODUCT => array( array( self::TERM, 'product_brand' ) ),
		);
		$this->wpdb->attribute_rows = array(
			array( 'attribute_id' => '7', 'attribute_name' => 'editorial', 'attribute_label' => 'Editorial' ),
		);

		$GLOBALS['wpdb'] = $this->wpdb;

		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'get_the_terms' )->justReturn( false );
		Functions\when( 'get_term_meta' )->justReturn( '' );
		Functions\when( 'wc_get_attribute_taxonomies' )->justReturn( array() );
		Functions\when( 'wc_get_price_decimals' )->justReturn( 2 );
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

	private function product(): WC_Product {
		$product                    = new WC_Product( 'instock' );
		$product->akb_id            = self::PRODUCT;
		$product->akb_regular_price = '10000';
		return $product;
	}

	/**
	 * Lector crudo listo para usar. Desde 1.99.16 la captura propia dejó de
	 * exponerse por el proveedor —que ahora adopta la foto de Search—, pero la
	 * proyección canónica sigue viviendo acá y es la que Search compara.
	 */
	private function reader(): \Akibara\Business\Modules\TaxonomyDiscount\AuthoritativeReader {
		$settings = new Settings();
		$settings->enter_strict_mode();
		return $settings->reader();
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
	// Snapshot comparable Search ↔ Business.
	// -----------------------------------------------------------------------

	/**
	 * @testdox El snapshot canónico expone opciones, timezone, max_pct, decimales y taxonomías efectivas.
	 */
	public function test_canonical_snapshot_exposes_every_comparable_field(): void {
		$snapshot = $this->reader()->canonical_snapshot();

		self::assertSame(
			array( 'decimals', 'max_pct', 'options', 'taxonomies', 'timezone' ),
			array_keys( $snapshot ),
			'Las claves deben ser estables y ordenadas para poder compararse literalmente.'
		);
		self::assertSame( 2, $snapshot['decimals'] );
		self::assertSame( 15, $snapshot['max_pct'] );
		self::assertSame( 'America/Santiago', $snapshot['timezone'] );
		self::assertSame( 'yes', $snapshot['options'][ Settings::OPTION_ENABLED ] );

		// Slugs efectivos: trim, únicos y ordenados como los entrega Search.
		self::assertSame(
			array( 'pa_editorial', 'product_brand', 'product_cat', 'product_tag' ),
			$snapshot['taxonomies']
		);
	}

	/**
	 * El contraejemplo del revisor: el filtro de `max_pct` responde distinto
	 * según quién lo invoque. Si `max_pct` no entra en el snapshot, Business
	 * calcula con 25 mientras Search sella un receipt de 15 — y el token
	 * seguiría "fresco".
	 *
	 * @testdox Un max_pct distinto cambia el snapshot y el token: Search puede detectarlo.
	 */
	public function test_max_pct_is_part_of_the_comparable_snapshot(): void {
		$baseline = $this->reader();
		$token_15 = $baseline->config_fingerprint();

		self::assertSame( 15, $baseline->canonical_snapshot()['max_pct'] );

		// Otro caller ve 25.
		Functions\when( 'apply_filters' )->alias(
			static function ( string $hook, $value ) {
				return 'akibara_taxonomy_discount_max_pct' === $hook ? 25 : $value;
			}
		);

		$other = $this->reader();

		self::assertSame( 25, $other->canonical_snapshot()['max_pct'] );
		self::assertNotSame( $token_15, $other->config_fingerprint(), 'La huella debe delatar el max_pct distinto.' );
	}

	/**
	 * @testdox Las taxonomías efectivas del snapshot son las que salen del filtro, no el mapa crudo.
	 */
	public function test_effective_taxonomies_come_from_the_filter(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( string $hook, $value ) {
				if ( 'akibara_taxonomy_discount_taxonomies' === $hook ) {
					return array( 'product_brand' => 'Editoriales' ); // el filtro recorta.
				}
				return $value;
			}
		);

		self::assertSame( array( 'product_brand' ), $this->reader()->canonical_snapshot()['taxonomies'] );
	}

	/**
	 * @testdox Si wp_json_encode falla, no se sella un hash de cadena vacía: se pierde la autoridad.
	 */
	public function test_invalid_json_encoding_poisons_the_authority(): void {
		Functions\when( 'wp_json_encode' )->justReturn( false );

		$settings = new Settings();
		$settings->enter_strict_mode();

		self::assertSame( '', $settings->reader()->config_fingerprint(), 'Un encode inválido no puede sellar un hash.' );
		self::assertFalse( $settings->authority_available() );
	}

	// -----------------------------------------------------------------------
	// Forma cruda cerrada: opciones.
	// -----------------------------------------------------------------------

	/**
	 * @testdox Una fila de options con claves extra cierra la autoridad.
	 */
	public function test_option_row_with_extra_keys_fails_closed(): void {
		$this->wpdb->option_raw = array(
			array( 'option_name' => Settings::OPTION_ENABLED, 'option_value' => 'yes', 'autoload' => 'yes' ),
		);

		$this->expectException( PriceAuthorityUnavailable::class );
		$this->strict_price( 10000.0, $this->product() );
	}

	/**
	 * @testdox Una fila de options duplicada cierra la autoridad.
	 */
	public function test_duplicated_option_row_fails_closed(): void {
		$this->wpdb->option_raw = array(
			array( 'option_name' => Settings::OPTION_ENABLED, 'option_value' => 'yes' ),
			array( 'option_name' => Settings::OPTION_ENABLED, 'option_value' => 'no' ),
		);

		$this->expectException( PriceAuthorityUnavailable::class );
		$this->strict_price( 10000.0, $this->product() );
	}

	/**
	 * @testdox Una opción fuera del set canónico cierra la autoridad.
	 */
	public function test_unexpected_option_row_fails_closed(): void {
		$this->wpdb->option_raw = array(
			array( 'option_name' => 'siteurl', 'option_value' => 'https://akibara.cl' ),
		);

		$this->expectException( PriceAuthorityUnavailable::class );
		$this->strict_price( 10000.0, $this->product() );
	}

	/**
	 * @testdox Un option_value que no es string cierra la autoridad.
	 */
	public function test_non_string_option_value_fails_closed(): void {
		$this->wpdb->option_raw = array(
			array( 'option_name' => Settings::OPTION_ENABLED, 'option_value' => array( 'yes' ) ),
		);

		$this->expectException( PriceAuthorityUnavailable::class );
		$this->strict_price( 10000.0, $this->product() );
	}

	// -----------------------------------------------------------------------
	// Forma cruda cerrada: atributos.
	// -----------------------------------------------------------------------

	/**
	 * @testdox Un attribute_id no canónico cierra la autoridad.
	 */
	public function test_non_canonical_attribute_id_fails_closed(): void {
		$this->wpdb->attribute_raw = array(
			array( 'attribute_id' => '07', 'attribute_name' => 'editorial', 'attribute_label' => 'Editorial' ),
		);

		$this->expectException( PriceAuthorityUnavailable::class );
		$this->strict_price( 10000.0, $this->product() );
	}

	/**
	 * @testdox Dos atributos con el mismo id cierran la autoridad.
	 */
	public function test_duplicated_attribute_id_fails_closed(): void {
		$this->wpdb->attribute_raw = array(
			array( 'attribute_id' => '3', 'attribute_name' => 'editorial', 'attribute_label' => 'Editorial' ),
			array( 'attribute_id' => '3', 'attribute_name' => 'genero', 'attribute_label' => 'Género' ),
		);

		$this->expectException( PriceAuthorityUnavailable::class );
		$this->strict_price( 10000.0, $this->product() );
	}

	/**
	 * @testdox Dos atributos con el mismo slug cierran la autoridad.
	 */
	public function test_duplicated_attribute_slug_fails_closed(): void {
		$this->wpdb->attribute_raw = array(
			array( 'attribute_id' => '3', 'attribute_name' => 'editorial', 'attribute_label' => 'Editorial' ),
			array( 'attribute_id' => '4', 'attribute_name' => 'editorial', 'attribute_label' => 'Otra' ),
		);

		$this->expectException( PriceAuthorityUnavailable::class );
		$this->strict_price( 10000.0, $this->product() );
	}

	/**
	 * @testdox Una fila de atributos con claves extra cierra la autoridad.
	 */
	public function test_attribute_row_with_extra_keys_fails_closed(): void {
		$this->wpdb->attribute_raw = array(
			array( 'attribute_id' => '3', 'attribute_name' => 'editorial', 'attribute_label' => 'Editorial', 'attribute_type' => 'select' ),
		);

		$this->expectException( PriceAuthorityUnavailable::class );
		$this->strict_price( 10000.0, $this->product() );
	}

	/**
	 * @testdox Un mapa efectivo con slug vacío tras el filtro cierra la autoridad.
	 */
	public function test_filter_returning_blank_slug_fails_closed(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( string $hook, $value ) {
				return 'akibara_taxonomy_discount_taxonomies' === $hook ? array( '  ' => 'Vacío' ) : $value;
			}
		);

		$this->expectException( PriceAuthorityUnavailable::class );
		$this->strict_price( 10000.0, $this->product() );
	}

	// -----------------------------------------------------------------------
	// Forma cruda cerrada: relaciones.
	// -----------------------------------------------------------------------

	/**
	 * @testdox Un term_id cero o negativo en las relaciones cierra la autoridad.
	 */
	public function test_non_positive_term_id_fails_closed(): void {
		$this->wpdb->relationship_raw = array( self::PRODUCT => array( '0' ) );

		$this->expectException( PriceAuthorityUnavailable::class );
		$this->strict_price( 10000.0, $this->product() );
	}

	/**
	 * @testdox Un term_id no canónico ('12abc') cierra la autoridad en vez de normalizarse con intval().
	 */
	public function test_garbage_term_id_is_not_normalized(): void {
		$this->wpdb->relationship_raw = array( self::PRODUCT => array( '12abc' ) );

		$this->expectException( PriceAuthorityUnavailable::class );
		$this->strict_price( 10000.0, $this->product() );
	}

	/**
	 * @testdox Un objeto en la lista de relaciones cierra la autoridad.
	 */
	public function test_object_term_id_fails_closed(): void {
		$this->wpdb->relationship_raw = array( self::PRODUCT => array( (object) array( 'term_id' => 501 ) ) );

		$this->expectException( PriceAuthorityUnavailable::class );
		$this->strict_price( 10000.0, $this->product() );
	}

	/**
	 * @testdox Términos duplicados en las relaciones cierran la autoridad.
	 */
	public function test_duplicated_term_ids_fail_closed(): void {
		$this->wpdb->relationship_raw = array( self::PRODUCT => array( '501', '501' ) );

		$this->expectException( PriceAuthorityUnavailable::class );
		$this->strict_price( 10000.0, $this->product() );
	}

	// -----------------------------------------------------------------------
	// Forma cruda cerrada: term-meta.
	// -----------------------------------------------------------------------

	/**
	 * @testdox Dos filas de term-meta para el mismo término cierran la autoridad.
	 */
	public function test_duplicated_term_meta_rows_fail_closed(): void {
		$this->wpdb->term_meta_rows = array(
			self::TERM => array(
				array( 'meta_id' => '11', 'meta_value' => '15' ),
				array( 'meta_id' => '12', 'meta_value' => '10' ),
			),
		);

		$this->expectException( PriceAuthorityUnavailable::class );
		$this->strict_price( 10000.0, $this->product() );
	}

	/**
	 * @testdox Un term-meta en notación exponencial (1e2) cierra la autoridad.
	 */
	public function test_exponential_term_meta_fails_closed(): void {
		$this->wpdb->term_meta_rows = array( self::TERM => array( array( 'meta_id' => '11', 'meta_value' => '1e2' ) ) );

		$this->expectException( PriceAuthorityUnavailable::class );
		$this->strict_price( 10000.0, $this->product() );
	}

	/**
	 * @testdox Un term-meta con espacios (' 15 ') cierra la autoridad.
	 */
	public function test_padded_term_meta_fails_closed(): void {
		$this->wpdb->term_meta_rows = array( self::TERM => array( array( 'meta_id' => '11', 'meta_value' => ' 15 ' ) ) );

		$this->expectException( PriceAuthorityUnavailable::class );
		$this->strict_price( 10000.0, $this->product() );
	}

	/**
	 * @testdox Un term-meta decimal (12.5) cierra la autoridad.
	 */
	public function test_decimal_term_meta_fails_closed(): void {
		$this->wpdb->term_meta_rows = array( self::TERM => array( array( 'meta_id' => '11', 'meta_value' => '12.5' ) ) );

		$this->expectException( PriceAuthorityUnavailable::class );
		$this->strict_price( 10000.0, $this->product() );
	}

	/**
	 * @testdox Un meta_id no positivo cierra la autoridad.
	 */
	public function test_non_positive_meta_id_fails_closed(): void {
		$this->wpdb->term_meta_rows = array( self::TERM => array( array( 'meta_id' => '0', 'meta_value' => '15' ) ) );

		$this->expectException( PriceAuthorityUnavailable::class );
		$this->strict_price( 10000.0, $this->product() );
	}

	/**
	 * @testdox La ausencia real de term-meta sigue siendo válida.
	 */
	public function test_absent_term_meta_is_still_legitimate(): void {
		$this->wpdb->term_meta_rows = array();

		self::assertSame( 10000.0, $this->strict_price( 10000.0, $this->product() ) );
	}

	// -----------------------------------------------------------------------
	// Modo estricto idempotente por request.
	// -----------------------------------------------------------------------

	/**
	 * Si entrar en estricto reaplicara los hooks por producto, dos productos de
	 * la misma foto podrían resolverse con configuraciones distintas sin que el
	 * token cambie.
	 *
	 * @testdox El modo estricto se entra una sola vez: la foto gobierna todos los productos.
	 */
	public function test_strict_mode_entry_is_idempotent_within_a_request(): void {
		$resolver = new DiscountResolver( new Settings() );

		$first = $resolver->resolve_strict( $this->product() );

		// Un hook que cambiara de opinión a mitad de request no puede alterar la
		// foto ya adoptada.
		Functions\when( 'apply_filters' )->alias(
			static function ( string $hook, $value ) {
				return 'akibara_taxonomy_discount_max_pct' === $hook ? 25 : $value;
			}
		);

		$second = $resolver->resolve_strict( $this->product() );

		self::assertSame( $first, $second, 'Los dos productos del snapshot deben usar la misma configuración.' );
		self::assertTrue( $resolver->is_authoritative() );
	}

	/**
	 * @testdox Sólo invalidate_derived_memos() inicia una captura nueva.
	 */
	public function test_only_explicit_invalidation_starts_a_new_capture(): void {
		$settings = new Settings();
		$resolver = new DiscountResolver( $settings );

		$resolver->resolve_strict( $this->product() );
		$epoch_a = $settings->authority()->token();

		$resolver->invalidate_derived_memos();
		$resolver->resolve_strict( $this->product() );
		$epoch_b = $settings->authority()->token();

		self::assertNotSame( $epoch_a, $epoch_b, 'Cada invalidación explícita abre una captura nueva (epoch).' );
	}
}
