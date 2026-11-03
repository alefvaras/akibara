<?php
/**
 * Pruebas de la ruta virtual /disponibles/ (gemela de /preventas/).
 *
 * ── Por qué setUp() declara TODOS los guards de contexto ─────────────────────
 * Brain Monkey define la función en el runtime de PHP la primera vez que un
 * test la mockea, y esa definición SOBREVIVE al `Monkey\tearDown()`: lo que se
 * limpia es la expectativa, no el símbolo. A partir de ahí, un test que llame a
 * `wp_parse_url()` sin stubearla no recibe el comportamiento nativo — recibe el
 * stub vacío, que devuelve null. Traducido a esta suite: `is_disponibles_route()`
 * calcularía path '' y TODOS los asserts de ruta fallarían… pero solo en las
 * permutaciones donde otro test corre antes. Verde en orden natural, rojo con
 * `--order-by=random`, que es la peor clase de verde (mismo razonamiento que las
 * constantes de `$wpdb` en tests/bootstrap.php).
 *
 * Por eso acá se fijan defaults DETERMINISTAS de todo lo que los guards tocan,
 * aunque el test concreto no lo use.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\DisponiblesRoute;

use Akibara\Business\Modules\DisponiblesRoute\DisponiblesRoute;
use Akibara\Business\Modules\PreventasRoute\PreventasRoute;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Doble de `$wpdb` que conserva el SQL emitido.
 *
 * `prepare()` imita el quoting real de WordPress para `%s` porque el contrato
 * que se prueba es literalmente qué literal viaja al WHERE: si el filtro pegara
 * 'onbackorder' en vez de 'instock', la página prometería stock y mostraría
 * preventas. Un doble que devolviera el formato sin sustituir escondería eso.
 */
final class DisponiblesRouteWpdb {

	public string $prefix = 'wp_';
	public string $posts  = 'wp_posts';

	/** @var array<int, string> */
	public array $prepared = array();

	/** Fecha que devuelve la consulta de frescura; null = catálogo sin dato. */
	public ?string $last_updated = null;

	/** @return string|null */
	public function get_var( string $query ) {
		return $this->last_updated;
	}

	/**
	 * @param string $query Consulta con placeholders.
	 * @param mixed  ...$args Valores.
	 */
	public function prepare( string $query, ...$args ): string {
		$sql = $query;
		foreach ( $args as $arg ) {
			$sql = preg_replace( '/%s/', "'" . (string) $arg . "'", $sql, 1 ) ?? $sql;
		}
		$this->prepared[] = $sql;

		return $sql;
	}
}

final class DisponiblesRouteTest extends TestCase {

	/** @var array<string,mixed> */
	private array $original_server = array();

	/** @var array<string,mixed> */
	private array $original_get = array();

	/** @var mixed */
	private $original_wpdb = null;

	/** @var array<string,int> */
	private array $query_vars = array();

	/** @var array<string,mixed> */
	private array $options = array();

	/** @var array<int, array{0:string,1:string,2:string}> Reglas capturadas de add_rewrite_rule(). */
	private array $rewrites = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->original_server  = $_SERVER;
		$this->original_get     = $_GET;
		$this->original_wpdb    = $GLOBALS['wpdb'] ?? null;
		$_SERVER['REQUEST_URI'] = '/disponibles/';
		$_GET                   = array();
		$this->query_vars       = array( 'paged' => 1 );
		$this->options          = array();
		$this->rewrites         = array();

		$GLOBALS['wpdb'] = new DisponiblesRouteWpdb();

		// `is_admin()` es guard del filtro SQL: default determinista, cada test lo
		// sobrescribe si necesita el camino admin.
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_parse_url' )->alias(
			static fn( string $url, int $component ) => parse_url( $url, $component )
		);
		Functions\when( 'home_url' )->alias(
			static fn( string $path = '' ): string => 'https://akibara.cl' . $path
		);
		Functions\when( 'get_query_var' )->alias(
			fn( string $key ): int => $this->query_vars[ $key ] ?? 0
		);
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'absint' )->alias( static fn( $value ): int => abs( (int) $value ) );
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'wp_kses' )->alias(
			static fn( string $html ): string => strip_tags( $html, '<strong><em><br>' )
		);
		Functions\when( 'get_option' )->alias(
			fn( string $key, $default = false ) => $this->options[ $key ] ?? $default
		);
		Functions\when( 'update_option' )->alias(
			function ( string $key, $value ): bool {
				$this->options[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'add_rewrite_rule' )->alias(
			function ( string $regex, string $query, string $after = 'bottom' ): void {
				$this->rewrites[] = array( $regex, $query, $after );
			}
		);
		// Sin default, `get_permalink`/`wc_get_page_id` devolverían null y el
		// breadcrumb caería siempre al fallback, ocultando el camino real.
		// Señal de frescura: sin estos defaults el render del intro explota según
		// el orden de la suite (misma disciplina que el resto de guards).
		Functions\when( 'get_transient' )->justReturn( '' );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'wp_date' )->alias( static fn( string $f, int $ts ): string => gmdate( $f, $ts ) );
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'wc_get_page_id' )->justReturn( 24433 );
		Functions\when( 'get_permalink' )->justReturn( 'https://akibara.cl/tienda/' );
	}

	protected function tearDown(): void {
		$_SERVER = $this->original_server;
		$_GET    = $this->original_get;
		if ( null === $this->original_wpdb ) {
			unset( $GLOBALS['wpdb'] );
		} else {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		}
		Monkey\tearDown();
		parent::tearDown();
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
				. 'que caería en redirect_canonical → 301 (el crawl-trap que ya se pagó en /preventas/).'
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
	 * @testdox Should filter the archive by the indexed instock row of the WC lookup table.
	 */
	public function test_filters_the_archive_by_the_instock_lookup_row(): void {
		$query = new \WP_Query( array( 'akb_disponibles_route' => '1' ) );

		$clauses = ( new DisponiblesRoute() )->filter_by_stock_lookup(
			array(
				'join'  => ' INNER JOIN algo_previo ',
				'where' => ' AND 1=1',
			),
			$query
		);

		self::assertStringContainsString( ' INNER JOIN algo_previo ', $clauses['join'], 'El JOIN previo se compone, no se reemplaza.' );
		self::assertStringContainsString(
			'LEFT JOIN wp_wc_product_meta_lookup AS akb_dl ON wp_posts.ID = akb_dl.product_id',
			$clauses['join'],
			'El filtro debe ir por la lookup indexada, no por un meta_query sobre _stock_status.'
		);
		self::assertSame(
			" AND 1=1 AND akb_dl.stock_status = 'instock'",
			$clauses['where'],
			"Solo 'instock' estricto: WooCommerce trata onbackorder como in-stock y colaría preventas."
		);
		self::assertStringNotContainsString( 'onbackorder', $clauses['where'] );

		// Idempotencia del JOIN: el mismo filtro corriendo dos veces no puede
		// duplicar el alias (SQL error opaco en runtime).
		$twice = ( new DisponiblesRoute() )->filter_by_stock_lookup( $clauses, $query );
		self::assertSame( 1, substr_count( $twice['join'], 'AS akb_dl' ) );
	}

	/**
	 * @testdox Should leave any query without the route var untouched.
	 */
	public function test_leaves_queries_without_the_route_var_untouched(): void {
		$clauses = array( 'join' => '', 'where' => ' AND 1=1' );

		self::assertSame(
			$clauses,
			( new DisponiblesRoute() )->filter_by_stock_lookup( $clauses, new \WP_Query() ),
			'Sin el query var no hay ruta: tocar el archive general sería un filtro global accidental.'
		);
	}

	/**
	 * @testdox Should preload the search facet with the value the index actually stores.
	 */
	public function test_preloads_the_search_facet_with_the_indexed_instock_value(): void {
		$route = new DisponiblesRoute();

		// `instock` es el valor CRUDO que DocumentBuilder indexa para la faceta
		// `disponibilidad` ('Disponible' es solo la etiqueta visible).
		self::assertSame(
			array( '_disponibilidad' => 'instock' ),
			$route->preload_akb_filter( array() )
		);

		// Una elección explícita del usuario manda sobre el hint de la ruta.
		self::assertSame(
			array( '_disponibilidad' => 'onbackorder' ),
			$route->preload_akb_filter( array( '_disponibilidad' => 'onbackorder' ) )
		);

		$_SERVER['REQUEST_URI'] = '/tienda/';
		self::assertSame( array(), $route->preload_akb_filter( array() ) );
	}

	/**
	 * @testdox Should own its title, breadcrumb and self-referencing canonical.
	 */
	public function test_owns_its_title_breadcrumb_and_canonical(): void {
		$route = new DisponiblesRoute();
		$title = 'Manga en stock disponible en Chile | Akibara';

		self::assertSame( 'Disponibles', $route->filter_page_title( 'Tienda' ) );
		self::assertSame( $title, $route->filter_document_title( 'Tienda - Akibara' ) );
		self::assertSame( $title, $route->rm_title( 'Tienda - Akibara' ) );
		// Sin canonical propio Rank Math publica el de /tienda/ y Google
		// desindexa la ruta por duplicate content.
		self::assertSame( 'https://akibara.cl/disponibles/', $route->rm_canonical( 'https://akibara.cl/tienda/' ) );

		self::assertSame(
			array(
				array( 'Inicio', 'https://akibara.cl/' ),
				array( 'Disponibles', 'https://akibara.cl/tienda/' ),
				array( 'Página 2', '' ),
			),
			$route->filter_breadcrumb(
				array(
					array( 'Inicio', 'https://akibara.cl/' ),
					array( 'Tienda', 'https://akibara.cl/tienda/' ),
					array( 'Página 2', '' ),
				)
			),
			'Se renombra el crumb del shop IN-PLACE; los previos y el "Página N" posterior se conservan.'
		);

		// Paginadas: el canonical es self-referencing, si no las páginas 2+
		// quedan fuera del índice.
		$_SERVER['REQUEST_URI']    = '/disponibles/page/3/';
		$this->query_vars['paged'] = 3;
		self::assertSame( 'https://akibara.cl/disponibles/page/3/', $route->rm_canonical( '' ) );
		self::assertSame( $title, $route->rm_title( 'Tienda - Akibara' ) );
		self::assertSame(
			'<link rel="prev" href="https://akibara.cl/disponibles/page/2/" />',
			$route->rm_prev_rel_link( '<link rel="prev" href="https://akibara.cl/tienda/page/2/" />' )
		);
		self::assertSame(
			'<link rel="next" href="https://akibara.cl/disponibles/page/4/" />',
			$route->rm_next_rel_link( '' )
		);
	}

	/**
	 * @testdox Should leave unrelated routes untouched.
	 */
	public function test_keeps_unrelated_routes_untouched(): void {
		$_SERVER['REQUEST_URI']    = '/preventas/page/2/';
		$this->query_vars['paged'] = 2;
		$route                     = new DisponiblesRoute();

		self::assertSame( 'Preventas', $route->filter_page_title( 'Preventas' ) );
		self::assertSame( 'canonical-original', $route->rm_canonical( 'canonical-original' ) );
		self::assertSame( 'prev-original', $route->rm_prev_rel_link( 'prev-original' ) );
		self::assertSame( 'next-original', $route->rm_next_rel_link( 'next-original' ) );
		self::assertSame( array( 'intacto' ), $route->rm_jsonld( array( 'intacto' ), null ) );
	}

	/**
	 * @testdox Should describe purchasable products without claiming physical possession or unsupported coverage.
	 */
	public function test_description_does_not_promise_unsupported_national_coverage(): void {
		$description = ( new DisponiblesRoute() )->rm_description( 'Original' );

		self::assertSame(
			'Catálogo de manga y cómics disponibles para comprar ahora, sin espera de importación. Despacho o retiro según las opciones disponibles para tu destino.',
			$description
		);
		self::assertStringContainsString( 'disponibles para comprar ahora, sin espera de importación', $description );
		self::assertStringNotContainsString( 'stock físico', mb_strtolower( $description, 'UTF-8' ) );
		self::assertStringNotContainsString( 'bodega', mb_strtolower( $description, 'UTF-8' ) );
		self::assertStringNotContainsString( 'envío nacional', $description );
		self::assertStringNotContainsString( 'todo chile', mb_strtolower( $description, 'UTF-8' ) );
	}

	/**
	 * @testdox Should add the virtual route once to the product sitemap.
	 */
	public function test_adds_disponibles_once_to_product_sitemap(): void {
		$route = new DisponiblesRoute();
		$once  = $route->rm_product_sitemap_content( '' );

		self::assertStringContainsString( '<loc>https://akibara.cl/disponibles/</loc>', $once );
		self::assertSame( $once, $route->rm_product_sitemap_content( $once ) );
	}

	/**
	 * @testdox Ninguna ruta gemela flushea reglas ni guarda su propio marcador.
	 *
	 * Las dos tenían un auto-flush versionado propio (`akb_*_rewrite_version`)
	 * que sólo podía dispararse cuando su constante cambiaba — o sea dentro de un
	 * release, que es justo cuando `Plugin::run_upgrade_tasks()` ya hace el flush
	 * global en `init` 999 con TODAS las reglas registradas. No podían volver a
	 * hacer nada y se cobraban una lectura de option en cada petición del sitio.
	 */
	public function test_the_routes_do_not_flush_or_stamp_anything(): void {
		$flushes = 0;
		Functions\when( 'flush_rewrite_rules' )->alias(
			function ( bool $hard = true ) use ( &$flushes ): void {
				++$flushes;
			}
		);

		( new DisponiblesRoute() )->register();
		( new PreventasRoute() )->register();

		self::assertSame( 0, $flushes, 'Un flush por módulo regenera el cache de reglas fuera del único momento en que hace falta.' );
		self::assertArrayNotHasKey( 'akb_disponibles_rewrite_version', $this->options );
		self::assertArrayNotHasKey( 'akb_preventas_rewrite_version', $this->options );

		// Y el flush global tiene que seguir teniendo dueño: si alguien lo quita,
		// las rutas se quedan sin quién regenere sus reglas tras un deploy.
		$plugin = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Plugin.php' );
		self::assertStringContainsString( 'flush_rewrite_rules( true );', $plugin );
		self::assertStringContainsString( "delete_option( 'akb_disponibles_rewrite_version' );", $plugin );
		self::assertStringContainsString( "delete_option( 'akb_preventas_rewrite_version' );", $plugin );
		self::assertStringContainsString( "delete_option( 'akb_wishlist_flushed' );", $plugin );
	}

	/**
	 * @testdox Should render the editable intro and the sibling link only on the clean first page.
	 */
	public function test_renders_intro_and_sibling_link_only_on_clean_page_one(): void {
		$this->options[ DisponiblesRoute::OPTION_INTRO ] = 'Disponible <strong>para comprar ahora</strong>.';

		$route = new DisponiblesRoute();
		ob_start();
		$route->render_intro();
		$html = (string) ob_get_clean();
		$text = preg_replace( '/\s+/', ' ', trim( strip_tags( $html ) ) );

		self::assertStringContainsString( 'class="akb-disponibles-intro"', $html );
		self::assertStringContainsString( '<strong>para comprar ahora</strong>', $html );
		self::assertStringContainsString( 'href="https://akibara.cl/a-pedido/"', $html );
		self::assertStringContainsString(
			DisponiblesRoute::SIBLING_PREFIX . ' ' . DisponiblesRoute::SIBLING_LINK_COPY,
			(string) $text
		);

		// Con filtros activos el intro se omite: es contenido editorial de la
		// raíz, no del resultado filtrado.
		$_GET = array( '_genero' => 'aventura' );
		ob_start();
		$route->render_intro();
		self::assertSame( '', (string) ob_get_clean() );
	}

	/**
	 * @testdox Should omit the editorial intro from paginated archives.
	 */
	public function test_omits_intro_from_paginated_route(): void {
		$_SERVER['REQUEST_URI']    = '/disponibles/page/2/';
		$this->query_vars['paged'] = 2;
		Functions\expect( 'get_option' )->never();

		ob_start();
		( new DisponiblesRoute() )->render_intro();
		self::assertSame( '', (string) ob_get_clean() );
	}

	/**
	 * @testdox Should allow only basic emphasis in the editable copy.
	 */
	public function test_sanitizes_editable_copy(): void {
		$sanitized = DisponiblesRoute::sanitize_intro_content(
			'<div><h1>Título</h1><strong>Disponible ahora</strong><script>alert(1)</script><em>hoy</em></div>'
		);

		self::assertStringContainsString( '<strong>Disponible ahora</strong>', $sanitized );
		self::assertStringContainsString( '<em>hoy</em>', $sanitized );
		self::assertStringNotContainsString( '<h1', $sanitized );
		self::assertStringNotContainsString( '<script', $sanitized );
		self::assertSame( '', DisponiblesRoute::sanitize_intro_content( array( 'no escalar' ) ) );
	}

	/**
	 * @testdox The sibling link must keep pointing at the real preventas route.
	 */
	public function test_sibling_link_matches_the_preventas_route_constant(): void {
		$disponibles = new ReflectionClass( DisponiblesRoute::class );
		$preventas   = new ReflectionClass( PreventasRoute::class );

		self::assertSame(
			$preventas->getConstant( 'ROUTE_PATH' ),
			$disponibles->getConstant( 'SIBLING_ROUTE_PATH' ),
			'El enlace entre las dos rutas del clúster se copió a mano; si alguien renombra '
				. '/preventas/, este assert es lo único que impide publicar un 404 interno.'
		);
	}

	/**
	 * @testdox The two twin routes must not collide on path, query var or SQL alias.
	 */
	public function test_twin_routes_do_not_collide(): void {
		$disponibles = new ReflectionClass( DisponiblesRoute::class );
		$preventas   = new ReflectionClass( PreventasRoute::class );

		self::assertNotSame( $preventas->getConstant( 'ROUTE_PATH' ), $disponibles->getConstant( 'ROUTE_PATH' ) );
		self::assertNotSame( $preventas->getConstant( 'QUERY_VAR' ), $disponibles->getConstant( 'QUERY_VAR' ) );
		self::assertNotSame( $preventas->getConstant( 'FACET_VALUE' ), $disponibles->getConstant( 'FACET_VALUE' ) );
		self::assertSame( $preventas->getConstant( 'FACET_NAME' ), $disponibles->getConstant( 'FACET_NAME' ) );

		// Los dos filtros cuelgan de `posts_clauses`: alias de JOIN distintos o
		// cualquier solape futuro es un error SQL opaco.
		$source_d = (string) file_get_contents( $disponibles->getFileName() );
		$source_p = (string) file_get_contents( $preventas->getFileName() );
		self::assertStringContainsString( 'AS akb_dl', $source_d );
		self::assertStringNotContainsString( 'AS akb_dl', $source_p );
		self::assertStringNotContainsString( 'AS akb_sl', $source_d );
	}

	/**
	 * Hallazgo HIGH de la revisión adversarial (2026-08-09): `register()` sólo
	 * comprobaba `query_vars`, así que BORRAR el `add_filter('posts_clauses')`
	 * dejaba la suite entera en verde — y en prod la ruta serviría el catálogo
	 * completo (con las preventas y los agotados) bajo un título que promete
	 * stock. Las dos capas de filtrado (SQL propio + post__in del buscador) se
	 * enmascaran mutuamente, así que este test es la única red.
	 *
	 * @testdox Should wire every hook the route depends on.
	 */
	public function test_registers_every_hook_the_route_depends_on(): void {
		$route = new DisponiblesRoute();

		Monkey\Filters\expectAdded( 'posts_clauses' )
			->once()
			->with( array( $route, 'filter_by_stock_lookup' ), 20, 2 );
		Monkey\Filters\expectAdded( 'akb_search_query_vars' )
			->once()
			->with( array( $route, 'preload_akb_filter' ) );
		Monkey\Filters\expectAdded( 'woocommerce_page_title' )->once();
		Monkey\Filters\expectAdded( 'pre_get_document_title' )->once();
		Monkey\Filters\expectAdded( 'woocommerce_get_breadcrumb' )->once();
		Monkey\Actions\expectAdded( 'woocommerce_before_shop_loop' )->once();
		// Sólo `add_rewrite`. El flush ya no es asunto del módulo: lo hace
		// `Plugin::run_upgrade_tasks()` una vez por bump, en `init` 999.
		Monkey\Actions\expectAdded( 'init' )->once();

		$route->register();

		// Las expectativas de Brain Monkey se verifican en tearDown y PHPUnit no
		// las cuenta como assertions: sin esto el test queda marcado «risky».
		self::assertTrue( true, 'Los hooks quedaron registrados según las expectativas.' );
	}

	/**
	 * Hallazgo HIGH de la revisión adversarial: `rm_jsonld()` es el método más
	 * intrincado del módulo y sólo estaba probado por su camino NEGATIVO. Un
	 * typo en 'CollectionPage' pasaba verde y dejaba el JSON-LD anunciando
	 * `name: "Tienda"` y `@id: /tienda/#webpage` justo en la ruta cuya razón de
	 * existir es ser citable por un motor.
	 *
	 * @testdox Should rewrite the collection page and the last breadcrumb on the route.
	 */
	public function test_rewrites_jsonld_on_the_route(): void {
		$_SERVER['REQUEST_URI'] = '/disponibles/';

		$input = array(
			'WebPage'    => array(
				'@type' => 'CollectionPage',
				'@id'   => 'https://akibara.cl/tienda/#webpage',
				'name'  => 'Tienda',
				'url'   => 'https://akibara.cl/tienda/',
			),
			'BreadcrumbList' => array(
				'@type'           => 'BreadcrumbList',
				'itemListElement' => array(
					array( 'item' => array( 'name' => 'Inicio', '@id' => 'https://akibara.cl/' ) ),
					array( 'item' => array( 'name' => 'Tienda', '@id' => 'https://akibara.cl/tienda/' ) ),
				),
			),
		);

		$out = ( new DisponiblesRoute() )->rm_jsonld( $input, null );

		self::assertSame( 'Manga en stock disponible - Akibara', $out['WebPage']['name'] );
		self::assertSame( 'https://akibara.cl/disponibles/', $out['WebPage']['url'] );
		self::assertStringStartsWith(
			'https://akibara.cl/disponibles/',
			(string) $out['WebPage']['@id'],
			'El @id no puede seguir apuntando a /tienda/: sería la misma entidad que el archive.'
		);

		$last = end( $out['BreadcrumbList']['itemListElement'] );
		self::assertSame( 'Manga en stock disponible', $last['item']['name'] );
		self::assertSame( 'https://akibara.cl/disponibles/', $last['item']['@id'] );
		// El primer nivel del breadcrumb no se toca.
		self::assertSame( 'Inicio', $out['BreadcrumbList']['itemListElement'][0]['item']['name'] );
	}

	/**
	 * @testdox Should keep the public query var from hijacking queries outside the archive.
	 */
	public function test_public_query_var_cannot_hijack_other_queries(): void {
		// `/?akb_disponibles_route=1` en el home: cualquiera puede armar esa URL
		// porque el query var es público. Sin el guard, el JOIN + el AND vacían
		// la portada del blog.
		$query = new \WP_Query();
		$query->set( 'akb_disponibles_route', '1' );
		$query->is_main_query_result = false;

		$clauses = array( 'join' => '', 'where' => '' );

		self::assertSame(
			$clauses,
			( new DisponiblesRoute() )->filter_by_stock_lookup( $clauses, $query ),
			'Fuera del main query el filtro no debe tocar nada.'
		);
	}
	/**
	 * Defecto medido EN PRODUCCIÓN el 2026-08-09 (heredado del gemelo): la barra
	 * lateral mostraba «Preventa (420)» dentro de /disponibles/ y el clic daba
	 * 404 duro, porque el filtro SQL de la ruta borraba los 420 ids. La ruta no
	 * puede prometer un número y entregar un callejón.
	 *
	 * @testdox Should send a conflicting availability choice to the general archive.
	 */
	public function test_rescues_a_conflicting_availability_choice(): void {
		$_GET['_disponibilidad'] = array( 'onbackorder' );
		$redirects               = array();

		Functions\when( 'sanitize_key' )->alias(
			static fn( string $v ): string => strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', $v ) )
		);
		Functions\when( 'add_query_arg' )->alias(
			static fn( array $args, string $url ): string => $url . '?' . http_build_query( $args )
		);
		Functions\when( 'wp_safe_redirect' )->alias(
			function ( string $location, int $status ) use ( &$redirects ): bool {
				$redirects[] = array( $location, $status );
				// Cortamos antes del `exit` del método real.
				throw new \RuntimeException( 'redirected' );
			}
		);

		try {
			( new DisponiblesRoute() )->redirect_conflicting_facet();
			self::fail( 'Debía redirigir en vez de dejar el 404.' );
		} catch ( \RuntimeException $e ) {
			self::assertSame( 'redirected', $e->getMessage() );
		}

		self::assertCount( 1, $redirects );
		self::assertStringStartsWith( 'https://akibara.cl/tienda/', $redirects[0][0] );
		self::assertStringContainsString( 'onbackorder', $redirects[0][0], 'La selección del usuario viaja intacta.' );
		self::assertSame( 302, $redirects[0][1], 'Intención de sesión, no cambio permanente de URL.' );
	}

	/**
	 * @testdox Should not redirect when the choice matches the route.
	 */
	public function test_does_not_redirect_when_choice_matches_route(): void {
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'wp_safe_redirect' )->alias(
			static function (): bool {
				throw new \RuntimeException( 'no debía redirigir' );
			}
		);

		$route = new DisponiblesRoute();

		// Sin parámetro: la visita normal a la ruta.
		$route->redirect_conflicting_facet();

		// Con el MISMO valor de la ruta: tampoco.
		$_GET['_disponibilidad'] = array( 'instock' );
		$route->redirect_conflicting_facet();

		self::assertTrue( true, 'Ninguna de las dos visitas debe redirigir.' );
	}
	/**
	 * Gatekeeper medido (252.000 ensayos, 6 LLMs): un timestamp ausente es uno
	 * de los cuatro factores con mayor efecto para NO ser citado. Antes del
	 * 2026-08-09 estas rutas no mostraban ninguna fecha salvo el © del footer.
	 *
	 * @testdox Should show a real freshness date taken from the catalogue.
	 */
	public function test_shows_a_real_freshness_date(): void {
		$GLOBALS['wpdb']->last_updated = '2026-08-07 14:30:00';

		ob_start();
		( new DisponiblesRoute() )->render_intro();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'akb-route-updated', $html );
		self::assertStringContainsString( 'datetime="2026-08-07"', $html, 'La fecha sale del catálogo, no de hoy.' );
	}

	/**
	 * @testdox Should print no date at all when the catalogue gives none.
	 */
	public function test_prints_no_date_when_unknown(): void {
		$GLOBALS['wpdb']->last_updated = null;

		ob_start();
		( new DisponiblesRoute() )->render_intro();
		$html = (string) ob_get_clean();

		self::assertStringNotContainsString( 'akb-route-updated', $html, 'Mejor ninguna fecha que una inventada.' );
	}

	/**
	 * @testdox El rescate de faceta conserva el formato con comas en vez de fusionarlo.
	 *
	 * `redirect_conflicting_facet()` existe para sacar al cliente de una URL que
	 * no se puede servir. Aplicaba `sanitize_key()` sobre el valor crudo, y esa
	 * función BORRA la coma: `instock,onbackorder` —un formato que el buscador
	 * acepta, ver `FilterSet::values()`— se fusionaba en `instockonbackorder`.
	 * Medido el 2026-08-23 contra Docker:
	 * `/disponibles/?_disponibilidad=instock,onbackorder` acababa en **404**,
	 * mientras la misma URL sobre `/tienda/` respondía 200. El rescate fabricaba
	 * el callejón que debía evitar.
	 *
	 * @dataProvider facetValueFormats
	 *
	 * @param string|array<int,string> $raw      Valor tal como llega en $_GET.
	 * @param array<int,string>        $esperado Valores que deben sobrevivir.
	 */
	public function test_rescue_preserves_every_supported_facet_format( $raw, array $esperado ): void {
		Functions\when( 'sanitize_key' )->alias(
			static fn( string $v ): string => strtolower( (string) preg_replace( '/[^a-zA-Z0-9_\-]/', '', trim( $v ) ) )
		);

		$partes = array();
		foreach ( (array) $raw as $valor ) {
			if ( is_array( $valor ) ) {
				continue;
			}
			foreach ( explode( ',', (string) $valor ) as $pieza ) {
				$partes[] = $pieza;
			}
		}
		$normalizado = array_values( array_unique( array_filter( array_map( 'sanitize_key', $partes ) ) ) );

		self::assertSame( $esperado, $normalizado );
	}

	/** @return array<string, array{0: string|array<int,string>, 1: array<int,string>}> */
	public static function facetValueFormats(): array {
		return array(
			'separado por comas'   => array( 'instock,onbackorder', array( 'instock', 'onbackorder' ) ),
			'repetido en array'    => array( array( 'instock', 'onbackorder' ), array( 'instock', 'onbackorder' ) ),
			'comas con espacios'   => array( 'instock, onbackorder', array( 'instock', 'onbackorder' ) ),
			'valor único'          => array( 'onbackorder', array( 'onbackorder' ) ),
			'duplicados colapsan'  => array( 'instock,instock', array( 'instock' ) ),
			'vacíos se descartan'  => array( 'instock,,onbackorder', array( 'instock', 'onbackorder' ) ),
		);
	}

	/**
	 * @testdox El método de rescate aplana por comas ANTES de sanear.
	 */
	public function test_rescue_explodes_before_sanitizing(): void {
		$src = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Modules/DisponiblesRoute/DisponiblesRoute.php' );
		$inicio = strpos( $src, 'public function redirect_conflicting_facet' );
		self::assertNotFalse( $inicio );
		$cuerpo = substr( $src, $inicio, 2600 );

		$posExplode  = strpos( $cuerpo, "explode( ','" );
		$posSanitize = strpos( $cuerpo, "array_map( 'sanitize_key'" );

		self::assertNotFalse( $posExplode, 'Sin explode por comas, sanitize_key fusiona los valores.' );
		self::assertNotFalse( $posSanitize );
		self::assertLessThan( $posSanitize, $posExplode, 'El aplanado por comas tiene que ir ANTES del saneado.' );
	}
	/**
	 * @testdox El query var suelto en la portada redirige a la ruta canónica.
	 *
	 * `akb_disponibles_route` se registra en `query_vars`, o sea que es público: cualquiera
	 * escribe `/?akb_disponibles_route=1` y WordPress deja de tratar la portada como portada,
	 * sirviendo el listado del blog. Medido el 2026-08-23 en local y en
	 * producción: `/?foo=1` devuelve la portada con 32 productos y
	 * `/?akb_disponibles_route=1` devuelve el blog con cero.
	 *
	 * El guard `is_main_query()` del filtro NO cubre este caso —ahí ES la
	 * consulta principal—, y durante meses el comentario del módulo afirmó que sí.
	 */
	public function test_public_query_var_on_the_home_redirects_to_the_route(): void {
		$_SERVER['REQUEST_URI'] = '/';
		$_GET['akb_disponibles_route']        = '1';
		$redirects              = array();

		Functions\when( 'wp_safe_redirect' )->alias(
			function ( string $location, int $status ) use ( &$redirects ): bool {
				$redirects[] = array( $location, $status );
				throw new \RuntimeException( 'redirected' );
			}
		);

		try {
			( new DisponiblesRoute() )->redirect_public_query_var();
			self::fail( 'Una URL pública que rompe la portada tiene que ir a alguna parte.' );
		} catch ( \RuntimeException $e ) {
			self::assertSame( 'redirected', $e->getMessage() );
		}

		self::assertCount( 1, $redirects );
		self::assertStringEndsWith( '/disponibles/', $redirects[0][0] );
		self::assertSame( 301, $redirects[0][1], 'La variable siempre quiso decir esta ruta: es permanente.' );
	}

	/**
	 * @testdox En la ruta de verdad no redirige (la puso la regla de reescritura).
	 */
	public function test_the_real_route_is_never_redirected(): void {
		$_SERVER['REQUEST_URI'] = '/disponibles/';
		$_GET['akb_disponibles_route']        = '1';

		Functions\when( 'wp_safe_redirect' )->alias(
			static function (): bool {
				throw new \RuntimeException( 'no debía redirigir' );
			}
		);

		( new DisponiblesRoute() )->redirect_public_query_var();
		self::assertTrue( true, 'La ruta canónica se sirve, no se redirige a sí misma.' );
	}

	/**
	 * @testdox Sin la variable en la URL no hace nada: corre en cada petición del sitio.
	 */
	public function test_does_nothing_without_the_query_var(): void {
		$_SERVER['REQUEST_URI'] = '/';
		unset( $_GET['akb_disponibles_route'] );

		Functions\when( 'wp_safe_redirect' )->alias(
			static function (): bool {
				throw new \RuntimeException( 'no debía redirigir' );
			}
		);

		( new DisponiblesRoute() )->redirect_public_query_var();
		self::assertTrue( true );
	}
}
