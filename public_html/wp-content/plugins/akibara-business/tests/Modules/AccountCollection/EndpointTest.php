<?php
/**
 * Contratos del endpoint privado Mi colección.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\AccountCollection;

use Akibara\Business\Modules\AccountCollection\Endpoint;
use Akibara\Business\Modules\AccountCollection\Renderer;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

defined( 'EP_ROOT' ) || define( 'EP_ROOT', 64 );
defined( 'EP_PAGES' ) || define( 'EP_PAGES', 4096 );
defined( 'AKIBARA_BUSINESS_DIR' ) || define( 'AKIBARA_BUSINESS_DIR', dirname( __DIR__, 3 ) . '/' );
defined( 'AKIBARA_BUSINESS_URL' ) || define( 'AKIBARA_BUSINESS_URL', 'https://akibara.test/wp-content/plugins/akibara-business/' );
defined( 'AKIBARA_BUSINESS_VERSION' ) || define( 'AKIBARA_BUSINESS_VERSION', 'test-version' );

final class EndpointTest extends TestCase {

	private Endpoint $endpoint;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'is_account_page' )->justReturn( false );
		Functions\when( 'is_wc_endpoint_url' )->justReturn( false );
		Functions\when( 'home_url' )->alias( static fn( string $path = '' ): string => 'https://akibara.test' . $path );

		$this->endpoint = new Endpoint(
			new Renderer( static fn( int $user_id ): array => array( 'status' => 'empty', 'series' => array() ) )
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @testdox Registra el endpoint WC, la navegación, privacidad y CSS sin AJAX. */
	public function test_registers_the_complete_server_rendered_endpoint(): void {
		Monkey\Actions\expectAdded( 'init' )
			->once()
			->with( array( $this->endpoint, 'add_endpoint' ) );
		Monkey\Filters\expectAdded( 'woocommerce_get_query_vars' )
			->once()
			->with( array( $this->endpoint, 'add_query_var' ) );
		Monkey\Filters\expectAdded( 'woocommerce_account_menu_items' )
			->once()
			->with( array( $this->endpoint, 'menu_item' ), 30 );
		Monkey\Filters\expectAdded( 'woocommerce_endpoint_coleccion_title' )
			->once()
			->with( array( $this->endpoint, 'endpoint_title' ) );
		Monkey\Filters\expectAdded( 'document_title_parts' )
			->once()
			->with( array( $this->endpoint, 'document_title_parts' ), 99 );
		Monkey\Filters\expectAdded( 'rank_math/frontend/title' )
			->once()
			->with( array( $this->endpoint, 'rank_math_title' ), 99 );
		Monkey\Actions\expectAdded( 'woocommerce_account_coleccion_endpoint' )
			->once()
			->with( array( $this->endpoint, 'render' ) );
		Monkey\Actions\expectAdded( 'wp_enqueue_scripts' )
			->once()
			->with( array( $this->endpoint, 'enqueue_assets' ) );
		Monkey\Filters\expectAdded( 'wp_headers' )
			->once()
			->with( array( $this->endpoint, 'private_headers' ) );
		Monkey\Actions\expectAdded( 'template_redirect' )
			->once()
			->with( array( $this->endpoint, 'disable_page_cache' ), 0 );

		$this->endpoint->register();
		self::addToAssertionCount( 10 );
	}

	/** @testdox Usa el slug coleccion también dentro de WC query vars. */
	public function test_registers_the_rewrite_and_wc_query_var(): void {
		Functions\expect( 'add_rewrite_endpoint' )
			->once()
			->with( 'coleccion', EP_ROOT | EP_PAGES );

		$this->endpoint->add_endpoint();

		self::assertSame(
			array(
				'orders'    => 'Pedidos',
				'coleccion' => 'coleccion',
			),
			$this->endpoint->add_query_var( array( 'orders' => 'Pedidos' ) )
		);
	}

	/** @testdox Colección queda después de Pedidos y nunca después de Salir. */
	public function test_places_collection_immediately_after_orders_without_losing_items(): void {
		$items = $this->endpoint->menu_item(
			array(
				'orders'          => 'Pedidos',
				'favoritos'       => 'Favoritos',
				'coleccion'       => 'Etiqueta anterior',
				'downloads'       => 'Descargas',
				'customer-logout' => 'Salir',
			)
		);

		self::assertSame(
			array( 'orders', 'coleccion', 'favoritos', 'downloads', 'customer-logout' ),
			array_keys( $items )
		);
		self::assertSame( 'Colección', $items['coleccion'] );
		self::assertSame( 'Colección', $this->endpoint->endpoint_title( 'Original' ) );
	}

	/** @testdox El título del documento coincide con Mi colección en WordPress y Rank Math. */
	public function test_scopes_the_browser_title_to_the_collection_endpoint(): void {
		$parts = array( 'title' => 'Mi cuenta', 'site' => 'Akibara' );
		self::assertSame( $parts, $this->endpoint->document_title_parts( $parts ) );
		self::assertSame( 'Mi cuenta - Akibara', $this->endpoint->rank_math_title( 'Mi cuenta - Akibara' ) );
		Monkey\tearDown();
		Monkey\setUp();

		Functions\when( 'is_account_page' )->justReturn( true );
		Functions\when( 'is_wc_endpoint_url' )->alias( static fn( string $slug ): bool => 'coleccion' === $slug );
		Functions\when( 'get_bloginfo' )->alias( static fn( string $show ): string => 'name' === $show ? 'Akibara' : '' );

		self::assertSame(
			array( 'title' => 'Mi colección', 'site' => 'Akibara' ),
			$this->endpoint->document_title_parts( $parts )
		);
		self::assertSame( 'Mi colección - Akibara', $this->endpoint->rank_math_title( 'Mi cuenta - Akibara' ) );
	}

	/** @testdox CSS se carga sólo dentro de /mi-cuenta/coleccion/. */
	public function test_enqueues_css_only_on_the_collection_endpoint(): void {
		Functions\expect( 'wp_enqueue_style' )->never();
		Functions\expect( 'wp_enqueue_script' )->never();
		$this->endpoint->enqueue_assets();
		Monkey\tearDown();
		Monkey\setUp();

		Functions\when( 'is_account_page' )->justReturn( true );
		Functions\when( 'is_wc_endpoint_url' )->alias( static fn( string $slug ): bool => 'coleccion' === $slug );
		Functions\when( 'rest_url' )->alias( static fn( string $path ): string => 'https://akibara.test/wp-json/' . ltrim( $path, '/' ) );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce-de-prueba' );
		Functions\when( 'wp_localize_script' )->justReturn( true );
		Functions\expect( 'wp_enqueue_style' )
			->once()
			->with(
				'akb-account-collection',
				AKIBARA_BUSINESS_URL . 'assets/css/account-collection.css',
				array(),
				AKIBARA_BUSINESS_VERSION . '.' . filemtime( AKIBARA_BUSINESS_DIR . 'assets/css/account-collection.css' )
			);
		// El script del interruptor viaja con la MISMA puerta que el estilo: si
		// alguna vez se encolara fuera del endpoint, entraría en `wp_head` global
		// —el camino exacto por el que un `use` faltante tumbó las 1.568 fichas—.
		Functions\expect( 'wp_enqueue_script' )
			->once()
			->with(
				'akb-series-follow',
				AKIBARA_BUSINESS_URL . 'assets/js/series-follow.js',
				array(),
				AKIBARA_BUSINESS_VERSION . '.' . filemtime( AKIBARA_BUSINESS_DIR . 'assets/js/series-follow.js' ),
				true
			);

		$this->endpoint->enqueue_assets();
		self::addToAssertionCount( 1 );
	}

	/** @testdox No-store y private sólo modifican la respuesta de Colección. */
	public function test_applies_private_no_store_headers_only_to_collection(): void {
		$existing = array( 'X-Test' => 'preserved' );
		self::assertSame( $existing, $this->endpoint->private_headers( $existing ) );
		Monkey\tearDown();
		Monkey\setUp();

		Functions\when( 'is_account_page' )->justReturn( true );
		Functions\when( 'is_wc_endpoint_url' )->alias( static fn( string $slug ): bool => 'coleccion' === $slug );

		$headers = $this->endpoint->private_headers( $existing );

		self::assertSame( 'preserved', $headers['X-Test'] );
		self::assertSame( 'no-store, no-cache, must-revalidate, max-age=0, private', $headers['Cache-Control'] );
		self::assertSame( 'no-cache', $headers['Pragma'] );
	}

	/**
	 * @testdox url() es ABSOLUTA también sin request (cron): pasa la base de Mi cuenta.
	 *
	 * El doble reproduce la semántica REAL de wc_get_endpoint_url: sin
	 * `$permalink` resuelve contra el permalink del request actual, y en
	 * cron/Action Scheduler no hay ninguno — la URL sale RELATIVA. Así fue
	 * como el digest de SeriesFollow envió correos con «/coleccion/» muerto
	 * (hallazgo del QA vivo 2026-08-31). Un stub que devuelva absoluta
	 * incondicional taparía la regresión.
	 */
	public function test_url_is_absolute_even_without_a_request_context(): void {
		Functions\when( 'wc_get_endpoint_url' )->alias(
			static function ( string $slug, string $value = '', string $permalink = '' ): string {
				return ( '' !== $permalink ? rtrim( $permalink, '/' ) . '/' : '/' ) . $slug . '/';
			}
		);
		Functions\when( 'wc_get_page_permalink' )->alias(
			static fn( string $page ): string => 'https://akibara.test/mi-cuenta/'
		);

		self::assertSame( 'https://akibara.test/mi-cuenta/coleccion/', Endpoint::url() );
	}
}
