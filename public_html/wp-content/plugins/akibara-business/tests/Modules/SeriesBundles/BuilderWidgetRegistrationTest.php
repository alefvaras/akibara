<?php
/**
 * Tests for the Series Bundles builder registration.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\SeriesBundles;

use Akibara\Business\Modules\SeriesBundles\BuilderWidget;
use Akibara\Business\Modules\SeriesBundles\CartSeriesCollector;
use Akibara\Business\Modules\SeriesBundles\EligibilityGuard;
use Akibara\Business\Modules\SeriesBundles\Settings;
use Akibara\Business\Modules\SeriesBundles\VolumeTierResolver;
use Akibara\Business\Services\NavigationLinks;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class BuilderWidgetRegistrationTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox Should render the builder full-width after the product summary and before tabs.
	 */
	public function test_registers_builder_after_product_summary(): void {
		$actions = array();
		Functions\when( 'add_action' )->alias(
			static function ( string $hook, $callback, int $priority = 10 ) use ( &$actions ): void {
				$actions[] = array(
					'hook'     => $hook,
					'callback' => $callback,
					'priority' => $priority,
				);
			}
		);

		$settings  = new Settings();
		$guard     = new EligibilityGuard( $settings );
		$collector = new CartSeriesCollector( $settings, $guard );
		$builder   = new BuilderWidget( $settings, $guard, new VolumeTierResolver(), $collector );

		$builder->register();

		self::assertContains(
			array(
				'hook'     => 'woocommerce_after_single_product_summary',
				'callback' => array( $builder, 'render' ),
				'priority' => 5,
			),
			$actions
		);
		self::assertNotContains(
			array(
				'hook'     => 'woocommerce_single_product_summary',
				'callback' => array( $builder, 'render' ),
				'priority' => 32,
			),
			$actions
		);
	}

	/** @testdox WP-CLI and API term assignment registers the six-argument membership hook. */
	public function test_registers_set_object_terms_contract_for_wp_cli_and_api(): void {
		$actions = array();
		Functions\when( 'add_action' )->alias(
			static function ( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ) use ( &$actions ): void {
				$actions[ $hook ] = array( $callback, $priority, $accepted_args );
			}
		);

		$settings  = new Settings();
		$guard     = new EligibilityGuard( $settings );
		$collector = new CartSeriesCollector( $settings, $guard );
		$builder   = new BuilderWidget( $settings, $guard, new VolumeTierResolver(), $collector );
		$builder->register();

		self::assertSame(
			array( array( $builder, 'handle_series_terms_set' ), 10, 6 ),
			$actions['set_object_terms']
		);
		self::assertSame( array( array( $builder, 'handle_series_relationship_change' ), 10, 3 ), $actions['added_term_relationship'] );
		self::assertSame( array( array( $builder, 'handle_series_relationship_change' ), 10, 3 ), $actions['deleted_term_relationships'] );
		self::assertSame( array( array( $builder, 'handle_deleted_product' ), 10, 2 ), $actions['deleted_post_product'] );
		self::assertSame( array( array( $builder, 'handle_catalog_post_change' ), 10, 1 ), $actions['trashed_post'] );
		self::assertSame( array( array( $builder, 'handle_catalog_post_change' ), 10, 1 ), $actions['untrashed_post'] );
	}

	/** @testdox Series membership invalidation is pa_serie-only, product-only, idempotent and coalesced per request. */
	public function test_set_object_terms_invalidation_is_precise_and_coalesced(): void {
		$version = 'legacy-token';
		$writes  = array();
		$shutdown = array();
		Functions\when( 'wp_generate_uuid4' )->justReturn( 'membership-token-1' );
		Functions\when( 'add_action' )->alias(
			static function ( string $hook, $callback, int $priority = 10 ) use ( &$shutdown ): void {
				if ( 'shutdown' === $hook ) {
					$shutdown[] = array( $callback, $priority );
				}
			}
		);
		Functions\when( 'get_post_type' )->alias(
			static fn( int $post_id ): string => 51 === $post_id ? 'product' : 'post'
		);
		Functions\when( 'get_option' )->alias(
			static function ( string $key, $default = false ) use ( &$version ) {
				return 'akb_sb_vols_cache_ver' === $key ? $version : $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( string $key, string $value, bool $autoload ) use ( &$version, &$writes ): bool {
				self::assertSame( 'akb_sb_vols_cache_ver', $key );
				self::assertFalse( $autoload );
				$version  = $value;
				$writes[] = $value;
				return true;
			}
		);

		$settings  = new Settings();
		$guard     = new EligibilityGuard( $settings );
		$collector = new CartSeriesCollector( $settings, $guard );
		$builder   = new BuilderWidget( $settings, $guard, new VolumeTierResolver(), $collector );

		$builder->handle_series_terms_set( 51, array( 2 ), array( 2 ), 'product_cat', false, array( 1 ) );
		$builder->handle_series_terms_set( 99, array( 2 ), array( 2 ), 'pa_serie', false, array( 1 ) );
		$builder->handle_series_terms_set( 51, array( 1, 2 ), array( 2, '1' ), 'pa_serie', false, array( 1, 2 ) );
		self::assertSame( array(), $writes, 'Otra taxonomía, otro post y el reemplazo idempotente no invalidan.' );
		self::assertSame( array(), $shutdown );

		$builder->bump_series_vols_cache(); // `save_post_product` antes de que REST guarde los términos.
		$builder->handle_series_terms_set( 51, array( 2 ), array( 2 ), 'pa_serie', false, array( 1 ) );
		$builder->handle_series_terms_set( 51, array( 3 ), array( 3 ), 'pa_serie', false, array( 2 ) );
		$builder->handle_catalog_post_change( 51 );

		self::assertSame( array(), $writes, 'El token definitivo no se publica mientras la membresía aún puede cambiar.' );
		self::assertCount( 1, $shutdown );
		self::assertSame( PHP_INT_MAX, $shutdown[0][1] );
		self::assertSame( array( $builder, 'commit_series_vols_cache_bump' ), $shutdown[0][0] );
		call_user_func( $shutdown[0][0] );
		call_user_func( $shutdown[0][0] );
		self::assertSame( array( 'membership-token-1' ), $writes, 'REST/WP-CLI + save/trash del mismo request hacen un solo bump.' );
		self::assertSame( 'membership-token-1', $version );
	}

	/** @testdox Direct add/remove APIs, conservative append and REST hard-delete each write a unique cache namespace. */
	public function test_direct_membership_apis_and_hard_delete_use_unique_tokens(): void {
		$token_number = 0;
		$writes       = array();
		$shutdown     = array();
		Functions\when( 'wp_generate_uuid4' )->alias(
			static function () use ( &$token_number ): string {
				return 'membership-token-' . ++$token_number;
			}
		);
		Functions\when( 'get_post_type' )->justReturn( 'product' );
		Functions\when( 'add_action' )->alias(
			static function ( string $hook, $callback, int $priority = 10 ) use ( &$shutdown ): void {
				if ( 'shutdown' === $hook ) {
					$shutdown[] = array( $callback, $priority );
				}
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( string $key, string $value, bool $autoload ) use ( &$writes ): bool {
				self::assertSame( 'akb_sb_vols_cache_ver', $key );
				self::assertFalse( $autoload );
				$writes[] = $value;
				return true;
			}
		);

		$settings  = new Settings();
		$guard     = new EligibilityGuard( $settings );
		$collector = new CartSeriesCollector( $settings, $guard );
		$added     = new BuilderWidget( $settings, $guard, new VolumeTierResolver(), $collector );
		$removed   = new BuilderWidget( $settings, $guard, new VolumeTierResolver(), $collector );
		$deleted   = new BuilderWidget( $settings, $guard, new VolumeTierResolver(), $collector );
		$appended  = new BuilderWidget( $settings, $guard, new VolumeTierResolver(), $collector );

		$added->handle_series_relationship_change( 51, 8, 'product_cat' );
		$added->handle_series_relationship_change( 51, 8, 'pa_serie' );
		$added->handle_series_relationship_change( 51, 9, 'pa_serie' ); // Mismo request: coalescido.
		$removed->handle_series_relationship_change( 51, array( 8 ), 'pa_serie' );
		$deleted->handle_deleted_product( 51 );
		$appended->handle_series_terms_set( 51, array( 8 ), array( 8 ), 'pa_serie', true, array() );
		$appended->handle_series_terms_set( 51, array( 8 ), array( 8 ), 'pa_serie', true, array() );
		self::assertSame( array(), $writes );
		self::assertCount( 4, $shutdown );
		foreach ( $shutdown as $scheduled ) {
			self::assertSame( PHP_INT_MAX, $scheduled[1] );
			call_user_func( $scheduled[0] );
		}

		self::assertSame(
			array( 'membership-token-1', 'membership-token-2', 'membership-token-3', 'membership-token-4' ),
			$writes,
			'Append no puede demostrar el set anterior y por seguridad hace un bump O(1), también coalescido.'
		);
	}

	/**
	 * @testdox Should clear both product columns and retain the mobile breakpoint.
	 */
	public function test_styles_make_direct_child_builder_full_width_without_removing_mobile_rules(): void {
		$stylesheet = (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/css/series-bundles.css' );

		self::assertStringContainsString( '.woocommerce div.product > .akb-sb-builder', $stylesheet );
		self::assertStringContainsString( 'clear: both;', $stylesheet );
		self::assertStringContainsString( '@media (min-width: 769px)', $stylesheet );
		self::assertStringContainsString( '@media (max-width: 768px)', $stylesheet );
		self::assertStringContainsString( 'grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));', $stylesheet );
		self::assertStringContainsString( '.akb-sb-builder__total[hidden]', $stylesheet );
		self::assertStringContainsString( '.akb-sb-builder__savings[hidden]', $stylesheet );
		self::assertStringContainsString( '.akb-sb-landing__collection button', $stylesheet );
		self::assertStringContainsString( 'min-height: var(--touch-target-min, 44px);', $stylesheet );
		self::assertStringContainsString( '@media (prefers-reduced-motion: reduce)', $stylesheet );
		self::assertStringContainsString( ':focus-visible', $stylesheet );
	}

	/**
	 * @testdox The single-tier promise limits the discount to selected eligible volumes.
	 */
	public function test_single_tier_copy_names_the_discount_scope(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Modules/SeriesBundles/BuilderWidget.php' )
			. (string) file_get_contents( dirname( __DIR__, 3 ) . '/templates/series-bundles/builder-widget.php' );
		$script = (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/js/series-bundles.js' );

		self::assertStringContainsString( 'descuento en los tomos elegibles que selecciones.', $source );
		self::assertStringNotContainsString( 'descuento en la serie.', $source );
		self::assertStringContainsString( 'data-discount-eligible=', $source );
		self::assertStringContainsString( "resolvePct( tiers, eligibleQty )", $script );
		self::assertStringContainsString( 'tomos elegibles · %2$d%% de descuento', $source );
		self::assertStringContainsString( 'Cada tomo distinto cuenta una vez; el descuento aplica a una unidad de cada tomo elegible.', $source );
	}

	/** @testdox The PDP selection summary is an atomic polite live region. */
	public function test_builder_summary_is_an_atomic_polite_live_region(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/templates/series-bundles/builder-widget.php' );

		self::assertStringContainsString(
			'data-akb-sb-summary aria-live="polite" aria-atomic="true"',
			$source
		);
		self::assertStringContainsString( 'data-akb-sb-msg role="status" aria-live="polite"', $source );
	}

	/**
	 * La landing de serie (`LandingOffer`, 2026-08-10) monta el pack en
	 * `woocommerce_before_shop_loop`. La ficha NO cambió de sitio: sigue siendo la
	 * única superficie del módulo que se cuelga de los hooks de producto, y no se
	 * cuelga de los de archive. Si algún día alguien "unifica" las dos, este assert
	 * es el que avisa antes que el DOM.
	 *
	 * @testdox Should keep the product page hooks untouched by the series landing work.
	 */
	public function test_builder_stays_out_of_the_archive_hooks(): void {
		$actions = array();
		Functions\when( 'add_action' )->alias(
			static function ( string $hook, $callback, int $priority = 10 ) use ( &$actions ): void {
				$actions[] = array(
					'hook'     => $hook,
					'callback' => $callback,
					'priority' => $priority,
				);
			}
		);

		$settings  = new Settings();
		$guard     = new EligibilityGuard( $settings );
		$collector = new CartSeriesCollector( $settings, $guard );
		$builder   = new BuilderWidget( $settings, $guard, new VolumeTierResolver(), $collector );

		$builder->register();

		foreach ( $actions as $action ) {
			self::assertStringStartsNotWith(
				'woocommerce_before_shop_loop',
				$action['hook'],
				'El builder es de la ficha; la landing la sirve LandingOffer.'
			);
			self::assertStringStartsNotWith( 'woocommerce_after_shop_loop', $action['hook'] );
		}
	}

	/**
	 * El `id` del título del builder pasó de literal a constante porque ahora lo
	 * consume la landing como ancla (`…/tomo/#akb-sb-builder-title-{term_id}`).
	 * Este assert fija el VALOR: cambiarlo no rompería ninguna prueba de la ficha,
	 * pero dejaría 106 landings enlazando a un fragmento inexistente — y el fallo
	 * es mudo, el navegador simplemente no salta.
	 *
	 * @testdox Should keep the builder title id stable for the landing anchor.
	 */
	public function test_builder_title_anchor_is_stable(): void {
		self::assertSame( 'akb-sb-builder-title-', BuilderWidget::TITLE_ID_PREFIX );

		$source = (string) file_get_contents(
			dirname( __DIR__, 3 ) . '/templates/series-bundles/builder-widget.php'
		);

		self::assertStringContainsString( 'id="<?php echo esc_attr( $title_id_prefix . (int) $term_id ); ?>"', $source );
		self::assertStringContainsString( 'aria-labelledby="<?php echo esc_attr( $title_id_prefix . (int) $term_id ); ?>"', $source );
	}

	/**
	 * @testdox El acceso del invitado va a la cuenta del tema y devuelve a la ficha.
	 *
	 * Lo que este candado protege es que el enlace NO salga por `wp_login_url()`:
	 * eso publica `/wp-login.php?redirect_to=…`, una pantalla sin el tema y con
	 * la mecánica de WordPress a la vista.
	 *
	 * Hasta el 2026-08-23 también prohibía la cadena `redirect_to` a secas, y con
	 * ella el enlace mandaba a `/mi-cuenta/` pelado: el cliente entraba y
	 * aterrizaba en el panel de su cuenta, lejos de la serie que estaba mirando y
	 * sin más camino de vuelta que el botón atrás. WooCommerce SÍ honra ese
	 * parámetro sobre su propia página —lo hornea como `<input name="redirect">`,
	 * medido en producción—, así que ahora se usa. `/mi-cuenta/` responde
	 * `noindex, follow` con y sin el parámetro (también medido), de modo que la
	 * variante no entra al índice.
	 */
	public function test_guest_login_returns_to_the_page_without_exposing_wp_login(): void {
		$original_uri            = $_SERVER['REQUEST_URI'] ?? null;
		$_SERVER['REQUEST_URI'] = '/one-piece-tomo-1/?utm_source=ig';

		Functions\when( 'home_url' )->alias(
			static fn( string $path = '/' ): string => 'https://akibara.cl' . $path
		);
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_parse_url' )->alias(
			static fn( string $url, int $component = -1 ) => parse_url( $url, $component )
		);
		Functions\when( 'wc_get_page_permalink' )->justReturn( 'https://akibara.cl/mi-cuenta/' );
		Functions\when( 'add_query_arg' )->alias(
			static fn( string $key, string $value, string $url ): string => $url . '?' . $key . '=' . $value
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );

		try {
			$url = NavigationLinks::account_url_returning_here();

			// El destino es la PÁGINA, sin su cadena de consulta, y la puerta es
			// «Mi cuenta» de WooCommerce: `wp-login.php` no tiene el formulario de
			// la tienda y perdería el `redirect_to` que WooCommerce sí hornea.
			self::assertSame(
				'https://akibara.cl/mi-cuenta/?redirect_to=' . rawurlencode( 'https://akibara.cl/one-piece-tomo-1/' ),
				$url
			);
			self::assertStringNotContainsString( 'wp-login.php', $url );
		} finally {
			if ( null === $original_uri ) {
				unset( $_SERVER['REQUEST_URI'] );
			} else {
				$_SERVER['REQUEST_URI'] = $original_uri;
			}
		}

		$source = (string) file_get_contents(
			dirname( __DIR__, 3 ) . '/src/Modules/SeriesBundles/BuilderWidget.php'
		);

		self::assertStringNotContainsString( 'wp_login_url(', $source );
		self::assertStringNotContainsString( 'wp-login.php', $source );
	}

	/** @testdox Interactive builder controls stay hidden until init and no-JS users receive real PDP links. */
	public function test_builder_has_a_real_progressive_fallback(): void {
		$source = (string) file_get_contents(
			dirname( __DIR__, 3 ) . '/templates/series-bundles/builder-widget.php'
		);
		$script = (string) file_get_contents(
			dirname( __DIR__, 3 ) . '/assets/js/series-bundles.js'
		);

		self::assertStringContainsString( 'data-akb-sb-interactive hidden', $source );
		self::assertStringContainsString( '<noscript>', $source );
		self::assertStringContainsString( 'href="<?php echo esc_url( (string) $vol[\'url\'] ); ?>"', $source );
		self::assertStringContainsString( "querySelectorAll( '[data-akb-sb-interactive]' )", $script );
		self::assertStringContainsString( 'interactive.hidden = false;', $script );
	}
}
