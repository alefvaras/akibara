<?php
/**
 * Candados de las dos palancas de porcentaje del acordeón «Configuración
 * general»: el tope máximo de descuento (dos motores) y el umbral del sello
 * rojo (dos lecturas).
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\TaxonomyDiscount;

use Akibara\Business\Modules\LoopEnhancer\SaleDiscount;
use Akibara\Business\Modules\SeriesBundles\VolumeTierResolver;
use Akibara\Business\Modules\TaxonomyDiscount\TaxonomyDiscount;
use Akibara\Business\Utilities\DiscountCeiling;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class DiscountCeilingSettingsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		// `has_filter()` lo trae Brain Monkey: sin su setUp la prueba sólo pasaba
		// si otra la había cargado antes (roja aislada y con --order-by=random).
		Monkey\setUp();
		$GLOBALS['akibara_test_options'] = array();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['akibara_test_options'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Guarda un valor como lo haría el panel.
	 *
	 * @param mixed $raw Lo que llega del formulario.
	 */
	private function guardar( string $option, $raw ): void {
		$GLOBALS['akibara_test_options'][ $option ] = $raw;
	}

	/**
	 * @testdox Sin nada guardado, el tope sigue siendo el 15% de siempre
	 */
	public function test_ceiling_defaults_to_the_historic_literal(): void {
		self::assertSame( 15, DiscountCeiling::pct() );
		self::assertSame( 15, VolumeTierResolver::pct_max() );
	}

	/**
	 * @testdox El tope guardado manda sobre los dos motores de descuento
	 */
	public function test_a_saved_ceiling_reaches_both_engines(): void {
		$this->guardar( DiscountCeiling::OPTION, '25' );

		// Motor 1 (descuento editorial): la option viaja como DEFAULT del
		// filtro, jamás como provider registrado — los lectores autoritativos
		// resuelven la suya desde bytes raw (falla del escéptico 2026-09-01).
		self::assertSame( 25, DiscountCeiling::pct() );
		self::assertFalse(
			has_filter( 'akibara_taxonomy_discount_max_pct' ),
			'El filtro debe quedar libre para overrides de terceros: un provider con get_option() rompe los lectores autoritativos.'
		);

		// Motor 2 (Arma tu pack): un tramo al 25% deja de ser inválido.
		self::assertSame( 25, VolumeTierResolver::pct_max() );
		self::assertTrue(
			( new VolumeTierResolver() )->normalize_tiers(
				array( array( 'min' => 5, 'pct' => 25 ) )
			)->is_ok()
		);
	}

	/**
	 * @testdox Bajar el tope invalida los tramos que lo superan y lo dice con el número nuevo
	 */
	public function test_lowering_the_ceiling_rejects_tiers_above_it(): void {
		$this->guardar( DiscountCeiling::OPTION, '8' );

		$resultado = ( new VolumeTierResolver() )->normalize_tiers(
			array( array( 'min' => 5, 'pct' => 10 ) )
		);

		self::assertFalse( $resultado->is_ok() );
		self::assertSame(
			'El porcentaje de cada tramo debe ser un entero entre 0 y 8',
			$resultado->message()
		);
	}

	/**
	 * @testdox Un tope ilegible cae al literal y uno fuera de rango se acota
	 */
	public function test_ceiling_sanitizing_clamps_and_falls_back(): void {
		$modulo = new TaxonomyDiscount();

		self::assertSame( 25, $modulo->sanitize_max_pct( '', array(), '25' ) );
		self::assertSame( 90, $modulo->sanitize_max_pct( '', array(), '250' ) );
		self::assertSame( 1, $modulo->sanitize_max_pct( '', array(), '0' ) );
		self::assertSame( 1, $modulo->sanitize_max_pct( '', array(), '-40' ) );
		self::assertSame( 15, $modulo->sanitize_max_pct( '', array(), 'quince' ) );
		self::assertSame( 15, $modulo->sanitize_max_pct( '', array(), '' ) );
		// `(int) '20%'` daría 20: un candidato no canónico cae al default en vez
		// de convertirse en un tope plausible.
		self::assertSame( 15, $modulo->sanitize_max_pct( '', array(), '20%' ) );
	}

	/**
	 * @testdox Sin nada guardado, el sello rojo sigue apareciendo desde el 10%
	 */
	public function test_badge_threshold_defaults_to_the_historic_literal(): void {
		self::assertSame( 10, SaleDiscount::configured_min_pct() );
	}

	/**
	 * @testdox El umbral guardado es el que deciden usar las dos lecturas del sello
	 */
	public function test_a_saved_badge_threshold_is_read_back(): void {
		$this->guardar( SaleDiscount::OPTION_MIN_PCT, '30' );

		self::assertSame( 30, SaleDiscount::configured_min_pct() );
	}

	/**
	 * @testdox Un umbral ilegible cae al literal y uno fuera de rango se acota
	 */
	public function test_badge_threshold_sanitizing_clamps_and_falls_back(): void {
		$modulo = new TaxonomyDiscount();

		self::assertSame( 0, $modulo->sanitize_sale_badge_min_pct( '', array(), '0' ) );
		self::assertSame( 90, $modulo->sanitize_sale_badge_min_pct( '', array(), '120' ) );
		self::assertSame( 0, $modulo->sanitize_sale_badge_min_pct( '', array(), '-5' ) );
		self::assertSame( 10, $modulo->sanitize_sale_badge_min_pct( '', array(), 'diez' ) );
		self::assertSame( 10, $modulo->sanitize_sale_badge_min_pct( '', array(), '' ) );
	}

	/**
	 * @testdox Las dos palancas se pintan en Descuentos con el literal de siempre
	 */
	public function test_both_levers_are_rendered_with_the_current_literal(): void {
		Monkey\setUp();
		Functions\when( 'taxonomy_exists' )->justReturn( false );
		Functions\when( 'apply_filters' )->alias( static fn( string $hook, $value = null ) => $value );
		Functions\when( 'wc_get_attribute_taxonomies' )->justReturn( array() );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'esc_html' )->returnArg( 1 );
		Functions\when( 'esc_attr' )->returnArg( 1 );

		// `get_settings()` cuenta el stock con el `$wpdb` global. Sin aislarlo, la prueba
		// leía el doble que hubiera dejado OTRA prueba y fallaba según el orden
		// (`--order-by=random`: «undefined method get_results()»). Sin `$wpdb`, el
		// conteo sale vacío, que es lo que esta prueba necesita.
		$habia_wpdb = array_key_exists( 'wpdb', $GLOBALS );
		$wpdb_previo = $GLOBALS['wpdb'] ?? null;
		unset( $GLOBALS['wpdb'] );

		$por_id = array();
		try {
			foreach ( ( new TaxonomyDiscount() )->get_settings() as $campo ) {
				if ( isset( $campo['id'] ) ) {
					$por_id[ $campo['id'] ] = $campo;
				}
			}
		} finally {
			if ( $habia_wpdb ) {
				$GLOBALS['wpdb'] = $wpdb_previo;
			}
		}

		Monkey\tearDown();

		self::assertArrayHasKey( DiscountCeiling::OPTION, $por_id );
		self::assertSame( '15', $por_id[ DiscountCeiling::OPTION ]['default'] );
		self::assertSame( '1', $por_id[ DiscountCeiling::OPTION ]['custom_attributes']['min'] );
		self::assertSame( '90', $por_id[ DiscountCeiling::OPTION ]['custom_attributes']['max'] );

		self::assertArrayHasKey( SaleDiscount::OPTION_MIN_PCT, $por_id );
		self::assertSame( '10', $por_id[ SaleDiscount::OPTION_MIN_PCT ]['default'] );
		self::assertSame( '0', $por_id[ SaleDiscount::OPTION_MIN_PCT ]['custom_attributes']['min'] );
		self::assertSame( '90', $por_id[ SaleDiscount::OPTION_MIN_PCT ]['custom_attributes']['max'] );
	}
}
