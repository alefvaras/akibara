<?php
/**
 * Pruebas del bridge SEO para la ruta virtual de preventas.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\PreventasRoute;

use Akibara\Business\Modules\PreventasRoute\PreventasRoute;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * `prepare()` imita el quoting real de WordPress para `%s` porque el contrato
 * que se prueba es literalmente qué literal viaja al WHERE. Este candado nació
 * de un bug real (1.109.0→.3): FACET_VALUE cumplía dos roles —valor de la
 * faceta Y valor del stock_status en SQL— y al separarse los vocabularios el
 * WHERE quedó buscando `stock_status='a_pedido'`, que no existe en la lookup:
 * /a-pedido/ sirvió «0 productos» en producción y ningún test lo vio.
 */
final class PreventasRouteWpdb {

	public string $prefix = 'wp_';
	public string $posts  = 'wp_posts';

	/** Consultas preparadas que ha visto este doble, en orden. */
	public array $queries = array();

	/** Filas de `post_modified` por `stock_status`, como en la lookup real. */
	public array $modified_by_status = array();

	/**
	 * @param string $query Consulta con placeholders.
	 * @param mixed  ...$args Valores.
	 */
	public function prepare( string $query, ...$args ): string {
		$sql = $query;
		foreach ( $args as $arg ) {
			$sql = preg_replace( '/%s/', "'" . (string) $arg . "'", $sql, 1 ) ?? $sql;
		}

		return $sql;
	}

	/**
	 * Responde como la lookup: sólo hay filas para los estados que existen en
	 * esa columna. Un WHERE con el valor comercial devuelve NULL, igual que en
	 * producción.
	 *
	 * @param string $query Consulta ya preparada.
	 * @return string|null
	 */
	public function get_var( string $query ) {
		$this->queries[] = $query;

		foreach ( $this->modified_by_status as $status => $modified ) {
			if ( false !== strpos( $query, "stock_status = '" . $status . "'" ) ) {
				return $modified;
			}
		}

		return null;
	}
}

final class PreventasRouteTest extends TestCase {

	/** @var array<string,mixed> */
	private array $original_server = array();

	/** @var array<string,mixed> */
	private array $original_get = array();

	/** @var array<string,int> */
	private array $query_vars = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->original_server        = $_SERVER;
		$this->original_get           = $_GET;
		$_SERVER['REQUEST_URI']       = '/a-pedido/';
		$_GET                         = array();
		$this->query_vars             = array( 'paged' => 1 );

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
		// Señal de frescura del listado (ver render_updated_line): defaults
		// deterministas para que el intro se renderee sin depender del orden.
		Functions\when( 'get_transient' )->justReturn( '' );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'wp_date' )->alias( static fn( string $f, int $ts ): string => gmdate( 'j/n/Y', $ts ) );
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'wp_kses' )->alias(
			static fn( string $html ): string => strip_tags( $html, '<strong><em><br>' )
		);
	}

	protected function tearDown(): void {
		$_SERVER = $this->original_server;
		$_GET    = $this->original_get;
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox Should keep adjacent links inside the virtual preventas route.
	 */
	public function test_rewrites_adjacent_links_to_preventas(): void {
		$_SERVER['REQUEST_URI'] = '/a-pedido/page/2/';
		$this->query_vars['paged'] = 2;
		$sut = new PreventasRoute();

		self::assertSame(
			'<link rel="prev" href="https://akibara.cl/a-pedido/" />',
			$sut->rm_prev_rel_link( '<link rel="prev" href="https://akibara.cl/tienda/" />' )
		);
		self::assertSame(
			'<link rel="next" href="https://akibara.cl/a-pedido/page/3/" />',
			$sut->rm_next_rel_link( '<link rel="next" href="https://akibara.cl/tienda/page/3/" />' )
		);

		$_SERVER['REQUEST_URI'] = '/a-pedido/page/3/';
		$this->query_vars['paged'] = 3;
		self::assertSame(
			'<link rel="prev" href="https://akibara.cl/a-pedido/page/2/" />',
			$sut->rm_prev_rel_link( '' )
		);
	}

	/**
	 * @testdox Should add the virtual route once to the product sitemap.
	 */
	public function test_adds_preventas_once_to_product_sitemap(): void {
		$sut  = new PreventasRoute();
		$once = $sut->rm_product_sitemap_content( '' );

		self::assertStringContainsString( '<loc>https://akibara.cl/a-pedido/</loc>', $once );
		self::assertSame( $once, $sut->rm_product_sitemap_content( $once ) );
	}

	/**
	 * @testdox Should leave unrelated routes untouched.
	 */
	public function test_keeps_unrelated_routes_untouched(): void {
		$_SERVER['REQUEST_URI'] = '/tienda/page/2/';
		$this->query_vars['paged'] = 2;
		$sut = new PreventasRoute();

		self::assertSame( 'prev-original', $sut->rm_prev_rel_link( 'prev-original' ) );
		self::assertSame( 'next-original', $sut->rm_next_rel_link( 'next-original' ) );
	}

	public function test_preventas_description_states_nationwide_shipping(): void {
		$description = ( new PreventasRoute() )->rm_description( 'Original' );

		self::assertStringContainsString( 'despacho a todo Chile', $description );
		self::assertStringNotContainsString( 'envío nacional', $description );
	}

	/**
	 * @testdox Should render editable copy and a published guide only on the clean first page.
	 */
	public function test_renders_intro_and_guide_only_on_clean_page_one(): void {
		Functions\when( 'get_option' )->alias(
			static function ( string $key, $default = false ) {
				if ( PreventasRoute::OPTION_INTRO === $key ) {
					return 'Reserva <strong>con confianza</strong>.';
				}
				if ( PreventasRoute::OPTION_GUIDE === $key ) {
					return 44;
				}
				return $default;
			}
		);
		Functions\when( 'get_post_type' )->justReturn( 'post' );
		Functions\when( 'get_post_status' )->justReturn( 'publish' );
		Functions\when( 'get_permalink' )->justReturn( 'https://akibara.cl/blog/guia-preventas/' );

		$sut = new PreventasRoute();
		ob_start();
		$sut->render_intro();
		$html = (string) ob_get_clean();
		$text = preg_replace( '/\s+/', ' ', trim( strip_tags( $html ) ) );

		self::assertStringContainsString( 'class="akb-preventas-intro"', $html );
		self::assertStringContainsString( '<strong>con confianza</strong>', $html );
		self::assertStringContainsString( 'href="https://akibara.cl/blog/guia-preventas/"', $html );
		self::assertStringContainsString(
			PreventasRoute::GUIDE_PREFIX . ' ' . PreventasRoute::GUIDE_LINK_COPY,
			(string) $text
		);

		$_GET = array( '_genero' => 'aventura' );
		ob_start();
		$sut->render_intro();
		self::assertSame( '', (string) ob_get_clean() );
	}

	/**
	 * @testdox Should omit the editorial intro from paginated preventas archives.
	 */
	public function test_omits_intro_from_paginated_route(): void {
		$_SERVER['REQUEST_URI'] = '/a-pedido/page/2/';
		$this->query_vars['paged'] = 2;
		Functions\expect( 'get_option' )->never();

		ob_start();
		( new PreventasRoute() )->render_intro();
		self::assertSame( '', (string) ob_get_clean() );
	}

	/**
	 * @testdox Should allow only basic emphasis in editable preventas copy.
	 */
	public function test_sanitizes_editable_preventas_copy(): void {
		$sanitized = PreventasRoute::sanitize_intro_content(
			'<div><h1>Título</h1><strong>Reserva</strong><script>alert(1)</script><em>segura</em></div>'
		);

		self::assertStringContainsString( '<strong>Reserva</strong>', $sanitized );
		self::assertStringContainsString( '<em>segura</em>', $sanitized );
		self::assertStringNotContainsString( '<h1', $sanitized );
		self::assertStringNotContainsString( '<script', $sanitized );
		self::assertStringNotContainsString( '<div', $sanitized );
	}
	/**
	 * INVARIANTE CRÍTICO (decisión del dueño 2026-08-09): «disponible ahora» se
	 * trata como un ESTADO del catálogo, no como una sección, así que
	 * /disponibles/ no vive en ningún menú. Este párrafo pasó a ser su ÚNICA vía
	 * de descubrimiento interno: si desaparece, la ruta queda huérfana y vuelve
	 * el problema que el benchmark midió (C06 1/9 sin ruta rastreable vs C07 6/9
	 * con ella). Por eso se renderea incluso con el copy editable vacío.
	 *
	 * @testdox Should always link the twin route, even with empty editable copy.
	 */
	public function test_always_links_the_twin_route(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $key, $default = false ) => PreventasRoute::OPTION_INTRO === $key ? '' : $default
		);

		ob_start();
		( new PreventasRoute() )->render_intro();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'https://akibara.cl/disponibles/', $html, 'Sin este enlace /disponibles/ es huérfana.' );
		self::assertStringContainsString( PreventasRoute::SIBLING_LINK_COPY, $html );
		self::assertStringContainsString( 'class="akb-preventas-intro__sibling"', $html );
	}

	/**
	 * @testdox Should keep both twin routes pointing at each other.
	 */
	public function test_twin_routes_point_at_each_other(): void {
		$preventas   = new \ReflectionClass( PreventasRoute::class );
		$disponibles = new \ReflectionClass( \Akibara\Business\Modules\DisponiblesRoute\DisponiblesRoute::class );

		self::assertSame(
			$disponibles->getConstant( 'ROUTE_PATH' ),
			$preventas->getConstant( 'SIBLING_ROUTE_PATH' ),
			'Renombrar /disponibles/ dejaría este enlace en 404 silencioso.'
		);
		self::assertSame(
			$preventas->getConstant( 'ROUTE_PATH' ),
			$disponibles->getConstant( 'SIBLING_ROUTE_PATH' ),
			'Y viceversa: el clúster se enlaza en los dos sentidos.'
		);
	}
	/**
	 * @testdox El query var suelto en la portada redirige a la ruta canónica.
	 *
	 * `akb_preventas_route` se registra en `query_vars`, o sea que es público: cualquiera
	 * escribe `/?akb_preventas_route=1` y WordPress deja de tratar la portada como portada,
	 * sirviendo el listado del blog. Medido el 2026-08-23 en local y en
	 * producción: `/?foo=1` devuelve la portada con 32 productos y
	 * `/?akb_preventas_route=1` devuelve el blog con cero.
	 *
	 * El guard `is_main_query()` del filtro NO cubre este caso —ahí ES la
	 * consulta principal—, y durante meses el comentario del módulo afirmó que sí.
	 */
	public function test_public_query_var_on_the_home_redirects_to_the_route(): void {
		$_SERVER['REQUEST_URI'] = '/';
		$_GET['akb_preventas_route']        = '1';
		$redirects              = array();

		Functions\when( 'wp_safe_redirect' )->alias(
			function ( string $location, int $status ) use ( &$redirects ): bool {
				$redirects[] = array( $location, $status );
				throw new \RuntimeException( 'redirected' );
			}
		);

		try {
			( new PreventasRoute() )->redirect_public_query_var();
			self::fail( 'Una URL pública que rompe la portada tiene que ir a alguna parte.' );
		} catch ( \RuntimeException $e ) {
			self::assertSame( 'redirected', $e->getMessage() );
		}

		self::assertCount( 1, $redirects );
		self::assertStringEndsWith( '/a-pedido/', $redirects[0][0] );
		self::assertSame( 301, $redirects[0][1], 'La variable siempre quiso decir esta ruta: es permanente.' );
	}

	/**
	 * El path legacy `/preventas/*` debe ir con 301 a la canónica `/a-pedido/*`
	 * preservando la página (v3, separación A pedido/Preventa 1.109.0). Este
	 * candado existe porque la primera implementación NO redirigía: el gate
	 * `is_preventas_route()` solo reconocía la canónica, así que en el path
	 * legacy el redirect retornaba temprano y la URL vieja quedaba sirviéndose
	 * con el chrome del shop.
	 *
	 * @testdox El path legacy /preventas/ redirige 301 a /a-pedido/ preservando la página.
	 * @dataProvider provide_legacy_paths
	 */
	public function test_legacy_preventas_path_redirects_permanently( string $uri, string $destino ): void {
		$_SERVER['REQUEST_URI'] = $uri;
		$redirects              = array();

		Functions\when( 'wp_safe_redirect' )->alias(
			function ( string $location, int $status ) use ( &$redirects ): bool {
				$redirects[] = array( $location, $status );
				throw new \RuntimeException( 'redirected' );
			}
		);

		try {
			( new PreventasRoute() )->redirect_legacy_path();
			self::fail( 'El path legacy tiene que redirigir a la canónica.' );
		} catch ( \RuntimeException $e ) {
			self::assertSame( 'redirected', $e->getMessage() );
		}

		self::assertCount( 1, $redirects );
		self::assertStringEndsWith( $destino, $redirects[0][0] );
		self::assertSame( 301, $redirects[0][1], 'El renombre es permanente; 302 dejaría a Google dudando.' );
	}

	/** @return array<string, array{string, string}> */
	public static function provide_legacy_paths(): array {
		return array(
			'raíz'     => array( '/preventas/', '/a-pedido/' ),
			'paginada' => array( '/preventas/page/7/', '/a-pedido/page/7/' ),
		);
	}

	/**
	 * @testdox En la ruta de verdad no redirige (la puso la regla de reescritura).
	 */
	public function test_the_real_route_is_never_redirected(): void {
		$_SERVER['REQUEST_URI'] = '/a-pedido/';
		$_GET['akb_preventas_route']        = '1';

		Functions\when( 'wp_safe_redirect' )->alias(
			static function (): bool {
				throw new \RuntimeException( 'no debía redirigir' );
			}
		);

		( new PreventasRoute() )->redirect_public_query_var();
		self::assertTrue( true, 'La ruta canónica se sirve, no se redirige a sí misma.' );
	}

	/**
	 * @testdox Sin la variable en la URL no hace nada: corre en cada petición del sitio.
	 */
	public function test_does_nothing_without_the_query_var(): void {
		$_SERVER['REQUEST_URI'] = '/';
		unset( $_GET['akb_preventas_route'] );

		Functions\when( 'wp_safe_redirect' )->alias(
			static function (): bool {
				throw new \RuntimeException( 'no debía redirigir' );
			}
		);

		( new PreventasRoute() )->redirect_public_query_var();
		self::assertTrue( true );
	}

	/**
	 * El WHERE va por el stock CRUDO de la lookup (`onbackorder`), NUNCA por el
	 * valor comercial de la faceta (`a_pedido`, que en esa columna no existe).
	 * Verificado en rojo contra el bug real: con FACET_VALUE en el prepare, el
	 * assert del literal falla exactamente como falló producción.
	 *
	 * @testdox Should filter the archive by the raw onbackorder lookup row, never by the facet value.
	 */
	public function test_filters_the_archive_by_the_raw_onbackorder_lookup_row(): void {
		$original_wpdb   = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = new PreventasRouteWpdb();
		Functions\when( 'is_admin' )->justReturn( false );

		try {
			$query   = new \WP_Query( array( 'akb_preventas_route' => '1' ) );
			$clauses = ( new PreventasRoute() )->filter_by_stock_lookup(
				array(
					'join'  => '',
					'where' => ' AND 1=1',
				),
				$query
			);

			self::assertStringContainsString(
				'LEFT JOIN wp_wc_product_meta_lookup AS akb_sl ON wp_posts.ID = akb_sl.product_id',
				$clauses['join']
			);
			self::assertSame(
				" AND 1=1 AND akb_sl.stock_status = 'onbackorder'",
				$clauses['where'],
				'El SQL filtra por el stock crudo de la lookup; el vocabulario comercial es de la faceta, no de esta columna.'
			);
			self::assertStringNotContainsString( 'a_pedido', $clauses['where'] );

			$twice = ( new PreventasRoute() )->filter_by_stock_lookup( $clauses, $query );
			self::assertSame( 1, substr_count( $twice['join'], 'AS akb_sl' ) );
		} finally {
			if ( null === $original_wpdb ) {
				unset( $GLOBALS['wpdb'] );
			} else {
				$GLOBALS['wpdb'] = $original_wpdb;
			}
		}
	}

	/**
	 * SEGUNDA COSECHA DEL MISMO BUG: la fecha de frescura consultaba la lookup
	 * con el valor COMERCIAL de la faceta (`a_pedido`), que en `stock_status` no
	 * existe. La consulta devolvía NULL siempre, la línea no se imprimía nunca
	 * —y como el fallo sale antes del `set_transient()`, se repetía en CADA
	 * pageload del archive.
	 *
	 * El experimento factorial de GEO midió que un timestamp ausente es uno de
	 * los cuatro factores con mayor efecto para NO ser citado: la línea no es
	 * decorativa.
	 *
	 * @testdox Should read the freshness date with the schema stock value, not the commercial facet value.
	 */
	public function test_freshness_date_reads_the_schema_stock_value(): void {
		$original_wpdb                      = $GLOBALS['wpdb'] ?? null;
		$database                           = new PreventasRouteWpdb();
		$database->modified_by_status       = array( 'onbackorder' => '2026-08-30 11:04:07' );
		$GLOBALS['wpdb']                    = $database;
		$guardado                           = array();

		Functions\when( 'get_option' )->alias( static fn( string $key, $default = false ) => $default );
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->alias(
			static function ( string $key, $value ) use ( &$guardado ): bool {
				$guardado[ $key ] = $value;
				return true;
			}
		);

		try {
			ob_start();
			( new PreventasRoute() )->render_intro();
			$html = (string) ob_get_clean();

			self::assertCount( 1, $database->queries );
			self::assertStringContainsString( "stock_status = 'onbackorder'", $database->queries[0] );
			self::assertStringNotContainsString( 'a_pedido', $database->queries[0] );

			// La línea de frescura se IMPRIME, con la fecha del catálogo.
			self::assertStringContainsString( '30/8/2026', $html );

			// Y queda cacheada: sin esto la consulta se repetía en cada pageload.
			self::assertSame( array( 'akb_preventas_updated' => '2026-08-30' ), $guardado );
		} finally {
			if ( null === $original_wpdb ) {
				unset( $GLOBALS['wpdb'] );
			} else {
				$GLOBALS['wpdb'] = $original_wpdb;
			}
		}
	}
}
