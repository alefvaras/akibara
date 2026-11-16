<?php
/**
 * Upgrade cleanup must preserve settings still consumed by registered modules.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests;

use Akibara\Business\Modules\AddressAutocomplete\AddressAutocomplete;
use Akibara\Business\Modules\ImageQuality\ImageQuality;
use Akibara\Business\Modules\LlmsManifest\LlmsManifest;
use Akibara\Business\Modules\NativeCart\Settings as CartSettings;
use Akibara\Business\Modules\Recommendations\Settings as RecommendationSettings;
use Akibara\Business\Modules\ScriptDiagnostics\CrossOriginScripts;
use Akibara\Business\Internal\UpgradeRetryRequired;
use Akibara\Business\Plugin;
use Akibara\Business\Tests\Support\CatalogoDeAccesos;
use Akibara\Business\Utilities\Text;
use Akibara\ProviderAccess\Access;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/** Tests the real upgrade cleanup against an isolated option store. */
final class PluginOrphanOptionsTest extends TestCase {

	private const RETIRED_OPTIONS = array(
		'akibara_robots_policy_version',
		'akibara_rank_math_product_schema_migration',
		'akb_series_dispatch_enabled',
		'akibara_announcement_speed',
		'akibara_smtp_host',
		'akibara_smtp_port',
		'akibara_smtp_secure',
		'akibara_smtp_user',
		'akibara_smtp_pass',
		'akibara_mail_failures',
		'akb_wishlist_flushed',
		'akb_disponibles_rewrite_version',
		'akb_brand_color_primary',
		'akb_brand_color_surface',
		'akb_image_recompress_state',
		'akb_image_recompress_lock',
		'akibara_announcement_bg',
		'akibara_announcement_color',
		'akibara_announcement_border',
		'akibara_announcement_promo_follow_discount',
		'akibara_announcement_promo_from',
		'akibara_announcement_promo_to',
		'akibara_taxonomy_discount_stacks_with_coupons',
		'akb_analytics_after_load',
		'akb_lean_cart',
		'akibara_edge_purge_enabled',
		'akb_cache_warm_enabled',
		'akb_catalog_order_showcase',
		'akb_series_landing_per_page',
		'akb_collection_catalog_v1',
		'akb_collection_catalog_revision',
	);

	/** The System Under Test. */
	private Plugin $sut;

	/** @var array<string,mixed> */
	private array $options = array();

	/** @var list<string> */
	private array $deleted = array();

	/** @var mixed */
	private $previous_wpdb;

	private bool $had_wpdb;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->sut           = ( new ReflectionClass( Plugin::class ) )->newInstanceWithoutConstructor();
		$this->had_wpdb      = array_key_exists( 'wpdb', $GLOBALS );
		$this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
		Functions\when( 'get_option' )->alias( fn( string $name, $default = false ) => $this->options[ $name ] ?? $default );
		Functions\when( 'delete_option' )->alias(
			function ( string $name ): bool {
				$this->deleted[] = $name;
				$exists          = array_key_exists( $name, $this->options );
				unset( $this->options[ $name ] );
				return $exists;
			}
		);
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'delete_transient' )->justReturn( true );
		$this->database_returns( array() );
	}

	protected function tearDown(): void {
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->previous_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		if ( class_exists( Access::class, false ) ) {
			Access::flush();
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Capture the SQL boundary without reimplementing SQL or touching WordPress data.
	 *
	 * @param array<array-key,mixed>|null $names Database names, including intentionally invalid test results.
	 * @param list<string>      $remaining Names still present after deletion.
	 * @param string            $error Database error reported by WordPress.
	 */
	private function database_returns( ?array $names, array $remaining = array(), string $error = '' ): void {
		$db             = Mockery::mock();
		$db->options    = 'isolated_options';
		$db->last_error = $error;
		$db->shouldReceive( 'esc_like' )->with( 'akibara_eda_brand_' )->andReturn( 'akibara\\_eda\\_brand\\_' );
		$db->shouldReceive( 'prepare' )->withArgs(
			static function ( string $query, array $args ): bool {
				$placeholders = implode( ', ', array_fill( 0, count( self::RETIRED_OPTIONS ), '%s' ) );
				self::assertSame( "SELECT option_name FROM %i WHERE option_name IN ({$placeholders}) OR option_name LIKE %s", $query );
				self::assertSame( array_merge( array( 'isolated_options' ), self::RETIRED_OPTIONS, array( 'akibara\\_eda\\_brand\\_%' ) ), $args );
				return true;
			}
		)->andReturn( 'prepared-retired-options' );
		$db->shouldReceive( 'get_col' )->with( 'prepared-retired-options' )->andReturn( $names, $remaining )->byDefault();
		$db->shouldReceive( 'query' )->never();
		$GLOBALS['wpdb'] = $db;
	}

	/** @testdox Repeated cleanup preserves non-default settings and unrelated family sentinels. */
	public function test_cleanup_preserves_active_options_and_family_boundaries(): void {
		$this->options = array(
			'akb_crossorigin_scripts_enabled'          => 'no',
			'akibara_address_autocomplete_enabled'     => 'yes',
			'akibara_google_maps_api_key'              => 'synthetic-preservation-sentinel',
			'akb_image_avif_quality'                   => '75',
			'akibara_llms_identity'                    => 'Identidad configurada por el dueño.',
			'akb_reco_relacionados'                    => 'comprables',
			'akb_reco_serie_agotada'                   => 'ocultar',
			'akb_reco_mas_vendidos'                    => 'tal_cual',
			'akb_reco_mas_vendidos_busqueda'            => 'no',
			'akb_copy_search_placeholder'              => 'Busca tu siguiente tomo',
			'akb_copy_promise_installments'            => 'Consulta las cuotas disponibles',
			'akb_copy_cart_sin_stock'                  => 'Este tomo se agotó',
			'akb_copy_future_sentinel'                 => 'Conservar familia activa',
			'akibara_native_cart_enabled'             => 'no',
			'akibara_native_cart_auto_open'           => 'no',
			'akibara_native_cart_trigger_selector'    => '.mi-carrito',
			'akibara_native_cart_mobile_fullscreen'   => 'no',
			'akibara_native_cart_drawer_side'         => 'left',
			'akibara_native_cart_drawer_width'        => '480',
			'akibara_native_cart_show_thumbnails'     => 'no',
			'akibara_native_cart_show_subtotal'       => 'no',
			'akibara_native_cart_show_free_shipping'  => 'no',
			'akibara_native_cart_show_recommendations' => 'no',
			'akibara_native_cart_recommendation_limit' => '5',
			'akibara_native_cart_recommendation_source' => 'none',
			'akibara_native_cart_empty_copy'          => 'Tu carrito espera una lectura',
			'akibara_native_cart_checkout_copy'       => 'Ir al pago',
			'akibara_native_cart_continue_copy'       => 'Volver al catálogo',
			'akibara_native_cart_continue_url'        => 'https://example.test/catalogo/',
			'akibara_native_cart_reduced_motion'      => 'yes',
			'akibara_native_cart_recommendations_copy' => 'Lecturas sugeridas',
			'akibara_native_cart_recommendation_add_copy' => 'Sumar tomo',
			'akibara_native_cart_future_sentinel'     => 'Conservar familia activa',
			'akibara_eda_branding_sentinel'           => 'No coincide con el prefijo retirado',
			'akibaraXedaXbrandXsentinel'              => 'Los guiones bajos no son comodines',
		);
		$before = $this->options;

		$this->sut->cleanup_orphan_options();
		$this->sut->cleanup_orphan_options();

		self::assertSame( array(), $this->deleted, 'An empty retired selection must not issue delete_option calls.' );
		$GLOBALS['wpdb']->shouldHaveReceived( 'get_col' )->times( 4 );
		self::assertSame( $before, $this->options, 'Cleanup must not reset any active preference or broad family.' );
		self::assertFalse( CrossOriginScripts::enabled(), 'The emergency off switch must survive an upgrade.' );
		self::assertSame( 75, ImageQuality::current_quality() );
		self::assertSame( 'Identidad configurada por el dueño.', LlmsManifest::identity() );
		self::assertSame( 'comprables', RecommendationSettings::current( RecommendationSettings::OPTION_RELATED ) );
		self::assertSame( 'ocultar', RecommendationSettings::current( RecommendationSettings::OPTION_SERIE_AGOTADA ) );
		self::assertSame( 'tal_cual', RecommendationSettings::current( RecommendationSettings::OPTION_MAS_VENDIDOS ) );
		self::assertFalse( RecommendationSettings::mas_vendidos_en_busqueda() );
		self::assertSame( 'Busca tu siguiente tomo', Text::one_line_option( 'akb_copy_search_placeholder', 'Default' ) );
		$cart = new CartSettings();
		self::assertFalse( $cart->is_enabled() );
		self::assertFalse( $cart->should_auto_open() );
		self::assertSame( 'left', $cart->get_drawer_side() );
		self::assertSame( 480, $cart->get_drawer_width() );
		self::assertSame( 'Ir al pago', $cart->get_checkout_copy() );
	}

	/** @testdox Address autocomplete remains ready with its synthetic configured credential after cleanup. */
	public function test_cleanup_preserves_address_autocomplete_readiness(): void {
		CatalogoDeAccesos::cargar();
		Access::flush();
		$this->options = array(
			'akibara_address_autocomplete_enabled' => 'yes',
			'akibara_google_maps_api_key'          => 'AIza' . str_repeat( 'a', 35 ),
		);
		$module = new AddressAutocomplete();
		self::assertTrue( $module->is_ready(), 'The synthetic fixture must be configured before cleanup.' );

		$this->sut->cleanup_orphan_options();
		Access::flush();

		self::assertTrue( $module->is_ready(), 'An upgrade must preserve both the switch and configured credential.' );
		$this->options['akibara_address_autocomplete_enabled'] = 'no';
		$this->sut->cleanup_orphan_options();
		self::assertFalse( $module->is_ready(), 'The disabled preference must also survive cleanup.' );
	}

	/** @testdox Only retired EDA option names are selected and deleted through the native option API. */
	public function test_cleanup_deletes_retired_rows_through_wordpress(): void {
		$retired = array_merge( self::RETIRED_OPTIONS, array( 'akibara_eda_brand_12', 'akibara_eda_brand_99' ) );
		$this->database_returns( $retired );
		$this->options = array_fill_keys( $retired, 'retired' );

		$this->sut->cleanup_orphan_options();

		self::assertSame( array(), $this->options, 'Retired rows must still be removed.' );
		self::assertEqualsCanonicalizing( $retired, $this->deleted, 'Deletion must stay limited to the reviewed retired keys.' );
	}

	/** @testdox A failed EDA selection must leave the upgrade retryable, including WordPress returning an empty array on SQL error. */
	public function test_cleanup_retries_after_a_database_read_error(): void {
		$this->database_returns( array(), array(), 'synthetic database failure' );
		$this->expectException( UpgradeRetryRequired::class );

		$this->sut->cleanup_orphan_options();
	}

	/** @testdox An invalid database result must not certify a successful cleanup. */
	public function test_cleanup_retries_after_an_invalid_database_result(): void {
		$this->database_returns( null );
		$this->expectException( UpgradeRetryRequired::class );

		$this->sut->cleanup_orphan_options();
	}

	/** @testdox Non-string database rows must fail before any delete is attempted. */
	public function test_cleanup_rejects_non_string_option_names(): void {
		$this->database_returns( array( 123 ) );
		$this->expectException( UpgradeRetryRequired::class );

		try {
			$this->sut->cleanup_orphan_options();
		} finally {
			self::assertSame( array(), $this->deleted );
		}
	}

	/** @testdox A SQL error during the final verification must also leave the upgrade retryable. */
	public function test_cleanup_retries_when_the_verification_query_fails(): void {
		$db    = $GLOBALS['wpdb'];
		$reads = 0;
		$db->shouldReceive( 'get_col' )->with( 'prepared-retired-options' )->andReturnUsing(
			static function () use ( $db, &$reads ): array {
				++$reads;
				$db->last_error = 2 === $reads ? 'synthetic verification failure' : '';
				return array();
			}
		);
		$this->expectException( UpgradeRetryRequired::class );

		$this->sut->cleanup_orphan_options();
	}

	/** @testdox A failed native deletion remains retryable even when the WordPress cache pretends the row is absent. */
	public function test_cleanup_checks_database_after_a_failed_delete(): void {
		$name = 'akibara_eda_brand_12';
		$this->database_returns( array( $name ), array( $name ) );
		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'delete_option' )->alias(
			function ( string $option ): bool {
				$this->deleted[] = $option;
				return false;
			}
		);
		$this->expectException( UpgradeRetryRequired::class );
		$this->expectExceptionMessage( 'Persisten opciones retiradas' );

		try {
			$this->sut->cleanup_orphan_options();
		} finally {
			self::assertSame( array( $name ), $this->deleted, 'Only the selected retired row may be deleted.' );
		}
	}

	/** @testdox A concurrent deletion is successful when the database confirms the retired row is gone. */
	public function test_cleanup_accepts_a_row_removed_concurrently(): void {
		$name = 'akibara_eda_brand_12';
		$this->database_returns( array( $name ) );
		Functions\when( 'delete_option' )->alias(
			function ( string $option ): bool {
				$this->deleted[] = $option;
				return false;
			}
		);

		$this->sut->cleanup_orphan_options();

		self::assertSame( array( $name ), $this->deleted );
		$GLOBALS['wpdb']->shouldHaveReceived( 'get_col' )->twice();
	}

	/** @testdox An already removed retired option is idempotent and needs only the two database checks. */
	public function test_cleanup_with_no_retired_rows_does_not_attempt_deletes(): void {
		$this->sut->cleanup_orphan_options();

		self::assertSame( array(), $this->deleted );
		$GLOBALS['wpdb']->shouldHaveReceived( 'get_col' )->twice();
	}
}
