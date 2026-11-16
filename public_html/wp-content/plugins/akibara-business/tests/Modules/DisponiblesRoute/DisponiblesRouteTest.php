<?php
/**
 * Pruebas de la ruta histórica /disponibles/.
 *
 * Contrato vigente (ADR-010: catálogo 100 % stock físico): la ruta existe
 * únicamente para que WordPress reconozca la URL histórica y la mande con un
 * `301` a la tienda. No sirve contenido propio, así que no debe colgar filtros
 * de consulta, título, canonical, JSON-LD ni sitemap.
 *
 * ── Por qué setUp() declara los guards de contexto ───────────────────────────
 * Brain Monkey define la función en el runtime de PHP la primera vez que un
 * test la mockea, y esa definición SOBREVIVE al `Monkey\tearDown()`: lo que se
 * limpia es la expectativa, no el símbolo. A partir de ahí, un test que llame a
 * `wp_parse_url()` sin stubearla no recibe el comportamiento nativo — recibe el
 * stub vacío, que devuelve null. Traducido a esta suite: `is_disponibles_route()`
 * calcularía path '' y los asserts de ruta fallarían… pero solo en las
 * permutaciones donde otro test corre antes. Verde en orden natural, rojo con
 * `--order-by=random`, que es la peor clase de verde.
 *
 * Por eso acá se fijan defaults DETERMINISTAS de todo lo que los guards tocan,
 * aunque el test concreto no lo use.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\DisponiblesRoute;

use Akibara\Business\Modules\DisponiblesRoute\DisponiblesRoute;
use Akibara\Business\Internal\UpgradeManager;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class DisponiblesRouteTest extends TestCase {

	/** @var array<string,mixed> */
	private array $original_server = array();

	/** @var array<string,mixed> */
	private array $original_get = array();

	/** @var array<int, array{0:string,1:string,2:string}> Reglas capturadas de add_rewrite_rule(). */
	private array $rewrites = array();

	/** @var array<int, array{0:string,1:int}> Redirecciones capturadas de wp_safe_redirect(). */
	private array $redirects = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->original_server  = $_SERVER;
		$this->original_get     = $_GET;
		$_SERVER['REQUEST_URI'] = '/disponibles/';
		$_GET                   = array();
		$this->rewrites         = array();
		$this->redirects        = array();

		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_parse_url' )->alias(
			static fn( string $url, int $component ) => parse_url( $url, $component )
		);
		Functions\when( 'home_url' )->alias(
			static fn( string $path = '' ): string => 'https://akibara.cl' . $path
		);
		Functions\when( 'wp_kses' )->alias(
			static fn( string $html ): string => strip_tags( $html, '<strong><em><br>' )
		);
		Functions\when( 'add_rewrite_rule' )->alias(
			function ( string $regex, string $query, string $after = 'bottom' ): void {
				$this->rewrites[] = array( $regex, $query, $after );
			}
		);
		Functions\when( 'wc_get_page_id' )->justReturn( 24433 );
		Functions\when( 'get_permalink' )->justReturn( 'https://akibara.cl/tienda/' );
		// Corta antes del `exit` del método real y deja constancia del destino.
		Functions\when( 'wp_safe_redirect' )->alias(
			function ( string $location, int $status = 302 ): bool {
				$this->redirects[] = array( $location, $status );
				throw new \RuntimeException( 'redirected' );
			}
		);
	}

	protected function tearDown(): void {
		$_SERVER = $this->original_server;
		$_GET    = $this->original_get;
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Ejecuta `redirect_to_shop()` y devuelve si llegó a redirigir.
	 */
	private function run_redirect(): bool {
		try {
			( new DisponiblesRoute() )->redirect_to_shop();
		} catch ( \RuntimeException $e ) {
			self::assertSame( 'redirected', $e->getMessage() );
			return true;
		}
		return false;
	}

	/**
	 * @testdox Should register the route and its paginated variant, most specific first.
	 */
	public function test_registers_the_route_and_its_paginated_variant(): void {
		( new DisponiblesRoute() )->add_rewrite();

		self::assertSame(
			array(
				array(
					'^disponibles/page/([0-9]{1,})/?$',
					'index.php?post_type=product&akb_disponibles_route=1&paged=$matches[1]',
					'top',
				),
				array(
					'^disponibles/?$',
					'index.php?post_type=product&akb_disponibles_route=1',
					'top',
				),
			),
			$this->rewrites,
			'La paginada debe ir PRIMERO: `^disponibles/?$` está anclado y no matchea /page/N, '
				. 'que caería en redirect_canonical antes de llegar al 301 hacia la tienda.'
		);
	}

	/**
	 * @testdox Should expose the query var through core and never through WC()->query.
	 */
	public function test_registers_the_query_var_without_touching_wc_query(): void {
		$route = new DisponiblesRoute();

		Monkey\Filters\expectAdded( 'query_vars' )
			->once()
			->with( array( $route, 'register_query_var' ) );
		// El incidente histórico (feedback_no_wc_query_vars_for_plugin_endpoints):
		// registrar un endpoint de plugin en WC()->query rompía el ruteo de la
		// cuenta y del shop. La ruta virtual NO lo necesita.
		Monkey\Filters\expectAdded( 'woocommerce_get_query_vars' )->never();

		$route->register();

		self::assertSame(
			array( 'existente', 'akb_disponibles_route' ),
			$route->register_query_var( array( 'existente' ) ),
			'El query var se agrega al filtro nativo, sin pisar los ya registrados.'
		);

		// Segunda mitad del contrato, a nivel de código fuente: un `WC()->query`
		// dentro del módulo no aparecería en los hooks registrados y pasaría el
		// assert de arriba.
		$source = (string) file_get_contents(
			(string) ( new ReflectionClass( DisponiblesRoute::class ) )->getFileName()
		);
		self::assertStringNotContainsString( "add_filter( 'woocommerce_get_query_vars'", $source );
		self::assertStringNotContainsString( 'WC()->query->', $source );
	}

	/**
	 * `register()` es la única superficie que WordPress ve: rewrite en `init`,
	 * query var público y el 301 en `template_redirect` a prioridad 0 (antes de
	 * cualquier otro callback que pudiera servir contenido en la ruta).
	 *
	 * @testdox Should wire only the rewrite, the query var and the 301 at template_redirect priority 0.
	 */
	public function test_register_wires_rewrite_query_var_and_redirect_only(): void {
		$route = new DisponiblesRoute();

		Monkey\Actions\expectAdded( 'init' )
			->once()
			->with( array( $route, 'add_rewrite' ) );
		Monkey\Filters\expectAdded( 'query_vars' )
			->once()
			->with( array( $route, 'register_query_var' ) );
		Monkey\Actions\expectAdded( 'template_redirect' )
			->once()
			->with( array( $route, 'redirect_to_shop' ), 0 );

		$route->register();

		self::assertTrue( true, 'Los hooks quedaron registrados según las expectativas.' );
	}

	/**
	 * Una ruta que responde 301 no sirve contenido: cualquier hook de consulta,
	 * título, breadcrumb, intro o SEO colgado de ella sería código muerto, y los
	 * de consulta/sitemap serían además perjudiciales (SQL desperdiciado antes
	 * del redirect y una URL redirigida listada en el sitemap de Rank Math).
	 *
	 * @testdox Should not hook query, title, breadcrumb, intro, sitemap or SEO filters on a redirecting route.
	 */
	public function test_register_does_not_hook_content_or_seo_filters(): void {
		Monkey\Filters\expectAdded( 'posts_clauses' )->never();
		Monkey\Filters\expectAdded( 'akb_search_query_vars' )->never();
		Monkey\Filters\expectAdded( 'woocommerce_page_title' )->never();
		Monkey\Filters\expectAdded( 'pre_get_document_title' )->never();
		Monkey\Filters\expectAdded( 'woocommerce_get_breadcrumb' )->never();
		Monkey\Actions\expectAdded( 'woocommerce_before_shop_loop' )->never();
		Monkey\Filters\expectAdded( 'rank_math/sitemap/product_content' )->never();
		Monkey\Filters\expectAdded( 'rank_math/frontend/title' )->never();
		Monkey\Filters\expectAdded( 'rank_math/frontend/description' )->never();
		Monkey\Filters\expectAdded( 'rank_math/frontend/canonical' )->never();
		Monkey\Filters\expectAdded( 'rank_math/frontend/prev_rel_link' )->never();
		Monkey\Filters\expectAdded( 'rank_math/frontend/next_rel_link' )->never();
		Monkey\Filters\expectAdded( 'rank_math/json_ld' )->never();

		( new DisponiblesRoute() )->register();

		// Segunda mitad del contrato, a nivel de código: aunque Rank Math esté
		// activo (los hooks rank_math/* se registraban sólo entonces), ni el
		// registro ni los métodos que los atendían pueden volver a existir.
		$source = (string) file_get_contents(
			(string) ( new ReflectionClass( DisponiblesRoute::class ) )->getFileName()
		);
		self::assertStringNotContainsString( 'rank_math/', $source );
		self::assertStringNotContainsString( 'RANK_MATH_VERSION', $source );
		self::assertStringNotContainsString( 'posts_clauses', $source );

		foreach (
			array(
				'filter_by_stock_lookup',
				'preload_akb_filter',
				'filter_page_title',
				'filter_document_title',
				'filter_breadcrumb',
				'render_intro',
				'last_updated_date',
				'render_updated_line',
				'rm_title',
				'rm_description',
				'rm_canonical',
				'rm_prev_rel_link',
				'rm_next_rel_link',
				'rm_product_sitemap_content',
				'rm_jsonld',
				'sanitize_intro_content',
			) as $removed
		) {
			self::assertFalse(
				method_exists( DisponiblesRoute::class, $removed ),
				"{$removed}() era inalcanzable tras el 301 y no debe volver."
			);
		}

		self::assertFalse(
			defined( DisponiblesRoute::class . '::OPTION_INTRO' ),
			'OPTION_INTRO ya no debe existir en DisponiblesRoute.'
		);
		self::assertFalse(
			defined( DisponiblesRoute::class . '::DEFAULT_INTRO_CONTENT' ),
			'DEFAULT_INTRO_CONTENT ya no debe existir en DisponiblesRoute.'
		);

		self::assertTrue( true, 'Ningún hook de contenido ni SEO quedó registrado.' );
	}

	/**
	 * @testdox Should 301 /disponibles/ to the shop permalink.
	 */
	public function test_redirects_the_route_to_the_shop_with_a_301(): void {
		$_SERVER['REQUEST_URI'] = '/disponibles/';

		self::assertTrue( $this->run_redirect(), 'La ruta histórica tiene que redirigir.' );
		self::assertSame( array( array( 'https://akibara.cl/tienda/', 301 ) ), $this->redirects );
	}

	/**
	 * @testdox Should 301 the paginated variant too, without carrying the page to the shop.
	 */
	public function test_redirects_the_paginated_route_to_the_shop_with_a_301(): void {
		$_SERVER['REQUEST_URI'] = '/disponibles/page/3/';

		self::assertTrue( $this->run_redirect() );
		self::assertSame( array( array( 'https://akibara.cl/tienda/', 301 ) ), $this->redirects );
	}

	/**
	 * `/?akb_disponibles_route=1` rompía la portada (WordPress dejaba de tratarla
	 * como portada): el mismo 301 la saca de ahí en vez de servir el listado del
	 * blog.
	 *
	 * @testdox Should 301 the bare public query var on any other URL.
	 */
	public function test_redirects_the_bare_public_query_var_with_a_301(): void {
		$_SERVER['REQUEST_URI']        = '/';
		$_GET['akb_disponibles_route'] = '1';

		self::assertTrue( $this->run_redirect() );
		self::assertSame( array( array( 'https://akibara.cl/tienda/', 301 ) ), $this->redirects );
	}

	/**
	 * @testdox Should fall back to the catalogue and then the home when the shop page cannot be resolved.
	 */
	public function test_redirect_falls_back_when_the_shop_page_is_unknown(): void {
		Functions\when( 'wc_get_page_id' )->justReturn( -1 );

		self::assertTrue( $this->run_redirect() );
		self::assertSame( array( array( 'https://akibara.cl/catalogo/', 301 ) ), $this->redirects );

		$this->redirects = array();
		Functions\when( 'wc_get_page_id' )->justReturn( 24433 );
		Functions\when( 'get_permalink' )->justReturn( false );

		self::assertTrue( $this->run_redirect() );
		self::assertSame( array( array( 'https://akibara.cl/', 301 ) ), $this->redirects );
	}

	/**
	 * @testdox Should not interfere with any other route.
	 *
	 * @dataProvider unrelatedRoutes
	 */
	public function test_does_not_touch_unrelated_routes( string $uri ): void {
		$_SERVER['REQUEST_URI'] = $uri;

		self::assertFalse( $this->run_redirect(), "{$uri} no es /disponibles/ y no debe redirigirse." );
		self::assertSame( array(), $this->redirects );
	}

	/** @return array<string, array{0: string}> */
	public static function unrelatedRoutes(): array {
		return array(
			'portada'                   => array( '/' ),
			'tienda'                    => array( '/tienda/' ),
			'ficha'                     => array( '/producto/one-piece-052/' ),
			'prefijo parecido'          => array( '/disponibles-ahora/' ),
			'subruta que no es paginada' => array( '/disponibles/extra/' ),
			'tienda con facetas'        => array( '/tienda/?_disponibilidad=instock' ),
		);
	}

	/**
	 * @testdox Ninguna ruta gemela flushea reglas ni guarda su propio marcador.
	 *
	 * Tenían un auto-flush versionado propio (`akb_*_rewrite_version`) que sólo
	 * podía dispararse cuando su constante cambiaba — o sea dentro de un
	 * release, que es justo cuando `UpgradeManager::run_upgrade_tasks()` ya hace el
	 * flush global en `init` 999 con TODAS las reglas registradas. No podían
	 * volver a hacer nada y se cobraban una lectura de option en cada petición.
	 */
	public function test_the_routes_do_not_flush_or_stamp_anything(): void {
		$flushes = 0;
		Functions\when( 'flush_rewrite_rules' )->alias(
			function ( bool $hard = true ) use ( &$flushes ): void {
				++$flushes;
			}
		);

		( new DisponiblesRoute() )->register();

		self::assertSame( 0, $flushes, 'Un flush por módulo regenera el cache de reglas fuera del único momento en que hace falta.' );

		Functions\when( 'wp_unschedule_hook' )->justReturn( 0 );
		Functions\when( 'as_unschedule_all_actions' )->justReturn( null );
		Functions\expect( 'do_action' )->once()->with( 'akibara_business_upgraded', '1.122.1', '1.122.0' );
		( new UpgradeManager() )->run_upgrade_tasks( '1.122.1', '1.122.0' );
		self::assertSame( 1, $flushes, 'El gestor compartido regenera las reglas una sola vez.' );
	}
}
