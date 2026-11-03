<?php
/**
 * Contrato de superficie del render de facetas (frontend/facets-render.php).
 *
 * Dos defectos distintos, la MISMA causa: el fichero mezclaba helpers que sólo
 * tienen sentido en el catálogo con filtros que WordPress dispara en todas las
 * páginas y con una gramática de búsqueda que sólo entiende `_buscar`.
 *
 * 1. `akibara_gp_akb_search_query()` lo consumen `document_title_parts` y
 *    `rank_math/frontend/title`, que corren en la portada y en el blog. Sin el
 *    guard de superficie construía el view-model COMPLETO de facetas ahí
 *    —`prepare_counts()` y el único check final de la lease, que es terminal por
 *    contrato— para tirarlo a la basura.
 *
 * 2. `?s=…&post_type=product` pintaba el drawer pero no pasaba por el motor y
 *    perdía el término al tocar una faceta (el form action apunta a la tienda y
 *    el hidden `_buscar` sólo se imprime si hay búsqueda EN la gramática propia).
 *
 * Los candados fijan la INVARIANTE, no la implementación: «fuera de una
 * superficie de producto no se abre el view-model» y «sólo la búsqueda de
 * productos se canonicaliza». Si mañana el guard se resuelve de otra forma,
 * estos tests siguen sirviendo.
 *
 * @package Akibara\Search\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Search\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Sustituye al `exit` del redirect para poder observarlo desde el test. */
final class RedireccionCapturada extends RuntimeException {

	public string $destino;

	public int $codigo;

	public function __construct( string $destino, int $codigo ) {
		parent::__construct( 'redirect' );
		$this->destino = $destino;
		$this->codigo  = $codigo;
	}
}

final class SearchSurfaceGuardsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Las funciones puente del plugin viven en akibara-search.php, que exige
		// WordPress cargado. Acá se doblan: lo que se prueba es el render.
		Functions\when( 'akibara_search_facets_active' )->justReturn( true );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_feed' )->justReturn( false );
		Functions\when( 'is_robots' )->justReturn( false );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'is_shop' )->justReturn( false );
		Functions\when( 'is_product_taxonomy' )->justReturn( false );
		Functions\when( 'is_search' )->justReturn( false );
		Functions\when( 'get_query_var' )->justReturn( '' );
		Functions\when( 'wc_get_page_permalink' )->justReturn( 'https://akibara.test/tienda/' );
		Functions\when( 'get_search_query' )->justReturn( '' );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'add_query_arg' )->alias(
			static function ( $args, $url ) {
				return $url . '?' . http_build_query( $args );
			}
		);
		Functions\when( 'wp_safe_redirect' )->alias(
			static function ( $destino, $codigo = 302 ) {
				throw new RedireccionCapturada( (string) $destino, (int) $codigo );
			}
		);

		// Se carga DESPUÉS de Monkey\setUp(): el fichero registra sus filtros al
		// incluirse y necesita add_action/add_filter doblados.
		require_once dirname( __DIR__ ) . '/frontend/facets-render.php';

		$_GET = array();
	}

	protected function tearDown(): void {
		$_GET = array();
		unset( $_SERVER['HTTP_X_REQUESTED_WITH'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/** Fuera del catálogo el título no puede abrir el view-model de facetas. */
	public function test_el_titulo_no_construye_el_view_model_fuera_del_catalogo(): void {
		// Portada/blog: ni tienda, ni taxonomía de producto, ni búsqueda.
		Functions\expect( 'akibara_search_facets_view' )->never();

		$this->assertSame( '', akibara_gp_akb_search_query() );
	}

	/** En la tienda sí: es donde `_buscar` existe y el h1/title lo necesitan. */
	public function test_en_la_tienda_el_titulo_lee_la_consulta(): void {
		Functions\when( 'is_shop' )->justReturn( true );
		Functions\expect( 'akibara_search_facets_view' )
			->once()
			->andReturn( array( 'search' => ' naruto ', 'facets' => array(), 'total' => 3 ) );

		$this->assertSame( 'naruto', akibara_gp_akb_search_query() );
	}

	/** La búsqueda de PRODUCTOS de WordPress se canonicaliza a la propia. */
	public function test_la_busqueda_de_productos_se_canonicaliza(): void {
		Functions\when( 'is_search' )->justReturn( true );
		Functions\when( 'get_query_var' )->justReturn( 'product' );
		Functions\when( 'get_search_query' )->justReturn( 'naruto' );
		$_GET = array(
			's'         => 'naruto',
			'post_type' => 'product',
			'_editorial' => 'Ivrea',
		);

		try {
			akibara_gp_akb_canonicalize_product_search();
			$this->fail( 'Se esperaba la redirección a la gramática propia.' );
		} catch ( RedireccionCapturada $redireccion ) {
			$this->assertStringContainsString( '/tienda/', $redireccion->destino );
			$this->assertStringContainsString( '_buscar=naruto', $redireccion->destino );
			// Las facetas que ya venían en la URL sobreviven al salto.
			$this->assertStringContainsString( '_editorial=Ivrea', $redireccion->destino );
			// `post_type` y `s` se quedan atrás: en /tienda/ no significan nada.
			$this->assertStringNotContainsString( 'post_type', $redireccion->destino );
			$this->assertStringNotContainsString( 's=naruto', str_replace( '_buscar=naruto', '', $redireccion->destino ) );
			// 302 y no 301: el destino sólo entiende `_buscar` mientras el
			// filtrado propio esté encendido, y eso es una palanca de admin. Un
			// 301 cacheado sobreviviría al apagado y dejaría al cliente en la
			// tienda SIN término, que es justo el bug que esto cierra.
			$this->assertSame( 302, $redireccion->codigo );
		}
	}

	/** La búsqueda nativa de ENTRADAS no se toca: el blog sólo se encuentra ahí. */
	public function test_la_busqueda_del_blog_no_se_redirige(): void {
		Functions\when( 'is_search' )->justReturn( true );
		Functions\when( 'get_query_var' )->justReturn( '' ); // sin post_type=product
		Functions\when( 'get_search_query' )->justReturn( 'embalaje' );
		$_GET = array( 's' => 'embalaje' );

		akibara_gp_akb_canonicalize_product_search();
		$this->assertTrue( true, 'No hubo redirección.' );
	}

	/** Con el filtrado propio apagado, /tienda/?_buscar= no busca nada. */
	public function test_sin_filtrado_propio_no_se_redirige(): void {
		Functions\when( 'akibara_search_facets_active' )->justReturn( false );
		Functions\when( 'is_search' )->justReturn( true );
		Functions\when( 'get_query_var' )->justReturn( 'product' );
		Functions\when( 'get_search_query' )->justReturn( 'naruto' );
		$_GET = array( 's' => 'naruto', 'post_type' => 'product' );

		akibara_gp_akb_canonicalize_product_search();
		$this->assertTrue( true, 'No hubo redirección.' );
	}

	/**
	 * @testdox El drawer de filtros conserva el orden vigente, y sólo si es un criterio real.
	 *
	 * El contrato es SIMÉTRICO y estaba roto por un lado: el form de
	 * ordenamiento del tema copia todos los `_*` al suyo, y el de facetas no
	 * devolvía el favor. Ordenar por precio y tildar cualquier faceta devolvía
	 * el catálogo al orden por defecto, con JS y sin JS.
	 */
	public function test_el_drawer_conserva_el_orden_vigente(): void {
		Functions\when( '_n' )->alias( static fn( string $one, string $many, int $n ): string => 1 === $n ? $one : $many );
		Functions\when( 'is_shop' )->justReturn( true );
		Functions\when( 'home_url' )->justReturn( 'https://akibara.test/tienda/' );
		Functions\when( 'user_trailingslashit' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_attr' )->alias( static fn( $v ): string => htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ) );
		Functions\when( 'esc_html' )->alias( static fn( $v ): string => htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ) );
		Functions\when( 'number_format_i18n' )->alias( static fn( $n ): string => (string) $n );
		Functions\when( 'sanitize_key' )->alias( static fn( string $v ): string => strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', $v ) ?? '' ) );
		Functions\when( 'akibara_search_facets_view' )->justReturn(
			array( 'search' => 'naruto', 'facets' => array(), 'total' => 3 )
		);
		// Como en producción: WooCommerce no registra oyentes en
		// `woocommerce_catalog_orderby`, pasa su lista como valor. El doble
		// anterior devolvía una lista propia y tapó que, sobre un array vacío,
		// el filtro real devuelve vacío y el hidden nunca se pintaba.
		Functions\when( 'apply_filters' )->alias(
			static fn( string $hook, $value ) => $value
		);

		$_GET = array( 'orderby' => 'price' );
		self::assertStringContainsString(
			'<input type="hidden" name="orderby" value="price">',
			$this->render_sidebar()
		);

		// Un criterio que WooCommerce no reconoce no se refleja en el HTML: sería
		// eco de la entrada del usuario dentro del documento.
		$_GET = array( 'orderby' => 'inventado' );
		self::assertStringNotContainsString( 'name="orderby"', $this->render_sidebar() );

		// Sin orden explícito no se inventa uno.
		$_GET = array();
		self::assertStringNotContainsString( 'name="orderby"', $this->render_sidebar() );

		// Un criterio que el sitio añade por filtro (las landings de serie
		// suman los suyos) también viaja.
		Functions\when( 'apply_filters' )->alias(
			static fn( string $hook, $value ) => 'woocommerce_catalog_orderby' === $hook
				? array_merge( (array) $value, array( 'novedades' => 'Novedades primero' ) )
				: $value
		);
		$_GET = array( 'orderby' => 'novedades' );
		self::assertStringContainsString( '<input type="hidden" name="orderby" value="novedades">', $this->render_sidebar() );
	}

	/**
	 * @testdox El botón del cajón concuerda en número desde la primera carga: «Ver 1 producto», «Ver 3 productos».
	 *
	 * QAM-7 (QA integral, 2026-09-24): la plantilla escribía «productos» fijo y
	 * el conteo del cajón sólo cambiaba el número. La palabra va ahora en su
	 * propio nodo, con la forma que corresponde al total; el conteo en vivo usa
	 * las MISMAS dos formas (`AKB_FACETS.noun`), probado en
	 * facetas-foco-movimiento-y-conteo.test.js.
	 */
	public function test_el_boton_del_cajon_concuerda_en_numero(): void {
		Functions\when( '_n' )->alias( static fn( string $one, string $many, int $n ): string => 1 === $n ? $one : $many );
		Functions\when( 'is_shop' )->justReturn( true );
		Functions\when( 'home_url' )->justReturn( 'https://akibara.test/tienda/' );
		Functions\when( 'user_trailingslashit' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_attr' )->alias( static fn( $v ): string => htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ) );
		Functions\when( 'esc_html' )->alias( static fn( $v ): string => htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ) );
		Functions\when( 'number_format_i18n' )->alias( static fn( $n ): string => number_format( (float) $n, 0, ',', '.' ) );
		Functions\when( 'sanitize_key' )->alias( static fn( string $v ): string => strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', $v ) ?? '' ) );
		Functions\when( 'apply_filters' )->alias( static fn( string $hook, $value ) => $value );
		$_GET = array();

		foreach ( array( 1 => 'Ver <span data-akb-apply-count>1</span> <span data-akb-apply-noun>producto</span></button>', 3 => 'Ver <span data-akb-apply-count>3</span> <span data-akb-apply-noun>productos</span></button>', 0 => 'Ver <span data-akb-apply-count>0</span> <span data-akb-apply-noun>productos</span></button>', 1234 => 'Ver <span data-akb-apply-count>1.234</span> <span data-akb-apply-noun>productos</span></button>' ) as $total => $boton ) {
			Functions\when( 'akibara_search_facets_view' )->justReturn( array( 'search' => 'naruto', 'facets' => array(), 'total' => $total ) );
			self::assertStringContainsString( $boton, $this->render_sidebar(), 'Con ' . $total . '.' );
		}

		self::assertSame( array( 'producto', 'productos' ), array( akibara_gp_akb_products_noun( 1 ), akibara_gp_akb_products_noun( 2 ) ), 'Las dos formas que recibe el conteo en vivo.' );
	}

	/**
	 * @testdox «Ver todas las opciones» se sirve como fragmento, no como página entera.
	 *
	 * Expandir Autor o Serie pedía la MISMA URL del catálogo y bajaba la página
	 * completa —render del grid, precios, imágenes: 230 KB medidos— para quedarse
	 * con un `<ul>` de 22 KB. Ahora el servidor corta antes de renderizar el
	 * catálogo. El fragmento tiene que seguir siendo parseable con el mismo
	 * selector que usa el cliente: `.akb-facet[data-facet=…]`.
	 */
	public function test_ver_todas_las_opciones_se_sirve_como_fragmento(): void {
		Functions\when( 'is_shop' )->justReturn( true );
		Functions\when( 'nocache_headers' )->justReturn( null );
		Functions\when( 'sanitize_key' )->alias( static fn( string $v ): string => strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', $v ) ?? '' ) );
		Functions\when( 'esc_attr' )->alias( static fn( $v ): string => htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ) );
		Functions\when( 'esc_html' )->alias( static fn( $v ): string => htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ) );
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'number_format_i18n' )->alias( static fn( $n ): string => (string) $n );
		Functions\when( 'disabled' )->justReturn( '' );
		Functions\when( 'selected' )->justReturn( '' );
		Functions\when( '_n' )->alias( static fn( string $one, string $many, int $n ): string => 1 === $n ? $one : $many );
		Functions\when( 'checked' )->alias(
			static fn( $checked, $current = true, $echo = true ): string => (string) $checked === (string) $current ? " checked='checked'" : ''
		);
		Functions\when( 'akibara_search_facets_view' )->justReturn(
			array(
				'search' => '',
				'total'  => 3,
				'facets' => array(
					array(
						'name'    => 'serie',
						'param'   => '_serie',
						'label'   => 'Serie o edición',
						'ui'      => 'alpha',
						'open'    => true,
						'options' => array(
							array( 'value' => 'one-piece', 'label' => 'One Piece', 'count' => 3, 'selected' => false ),
						),
					),
				),
			)
		);

		// La CABECERA sola ya no cambia la respuesta: dos respuestas distintas en
		// una misma URL era justo lo que el borde cacheaba cruzado —guardaba el
		// fragmento y lo servía como página (medido en producción el 2026-09-19,
		// hallazgo S-127b9306)—. Un JS antiguo que sólo mande la cabecera recibe
		// la página entera, que también sabe leer.
		$_SERVER['HTTP_X_REQUESTED_WITH'] = 'akb-facet-options';
		$_GET['_akb_expand']              = 'serie';
		self::assertNull( akibara_gp_akb_facet_options_fragment(), 'Misma URL que la página ⇒ misma respuesta que la página.' );
		unset( $_SERVER['HTTP_X_REQUESTED_WITH'] );

		// El fragmento vive en SU URL: la que lleva `_akb_fragment=1`.
		$_GET['_akb_fragment'] = '1';

		$html = (string) akibara_gp_akb_facet_options_fragment();

		unset( $_GET['_akb_expand'], $_GET['_akb_fragment'] );

		self::assertStringContainsString( 'data-facet="serie"', $html, 'El cliente busca la faceta por este atributo.' );
		self::assertStringContainsString( 'akb-facet-list', $html, 'Y dentro, la lista que reemplaza.' );
		self::assertStringNotContainsString( '<form id="akb-facets-drawer"', $html, 'El fragmento no arrastra el drawer entero.' );
	}

	/**
	 * Revisión independiente 2026-09-19 (MEDIA): con URL propia, el fragmento se
	 * puede ABRIR —pegado en la barra, desde un enlace compartido— y era una
	 * lista suelta sin tienda ni forma de volver.
	 *
	 * @testdox Quien abre la URL del fragmento rebota a la página completa, con el grupo desplegado.
	 */
	public function test_el_fragmento_abierto_como_pagina_rebota_a_la_tienda(): void {
		Functions\when( 'remove_query_arg' )->alias(
			static fn( string $clave ): string => '_akb_fragment' === $clave ? '/tienda/?_editorial=ivrea&_akb_expand=serie' : ''
		);
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'esc_url' )->alias( static fn( string $u ): string => str_replace( '&', '&#038;', $u ) );
		Functions\when( 'wp_json_encode' )->alias( static fn( $v, int $f = 0 ) => json_encode( $v, $f ) );

		$html = akibara_gp_akb_fragment_bounce();

		self::assertStringContainsString( 'location.replace("/tienda/?_editorial=ivrea\u0026_akb_expand=serie")', $html, 'Vuelve a la MISMA búsqueda, sin `_akb_fragment` y con el grupo desplegado.' );
		self::assertStringNotContainsString( '_akb_fragment', $html, 'Si conservara el parámetro, rebotaría a sí mismo para siempre.' );
		self::assertStringContainsString( '<noscript><p><a href="/tienda/?_editorial=ivrea&#038;_akb_expand=serie">', $html, 'Sin JS queda un enlace de vuelta.' );
		self::assertStringContainsString( 'data-no-optimize="1"', $html, 'LiteSpeed no lo difiere ni lo combina.' );
	}

	/** @testdox Una URL hostil no puede cerrar el script del rebote. */
	public function test_el_rebote_no_deja_cerrar_el_script(): void {
		Functions\when( 'remove_query_arg' )->justReturn( '/tienda/?q=</script><script>alert(1)</script>' );
		Functions\when( 'esc_url_raw' )->returnArg(); // El peor caso: que el saneado de URL no quitara nada.
		Functions\when( 'esc_url' )->alias( static fn( string $u ): string => htmlspecialchars( $u, ENT_QUOTES ) );
		Functions\when( 'wp_json_encode' )->alias( static fn( $v, int $f = 0 ) => json_encode( $v, $f ) );

		$html = akibara_gp_akb_fragment_bounce();

		self::assertSame( 1, substr_count( $html, '</script>' ), 'Sólo el cierre propio.' );
		self::assertStringNotContainsString( '<script>alert', $html );
	}

	/** @testdox Sin la cabecera propia se sirve la página completa: el camino sin JS no cambia. */
	public function test_sin_la_cabecera_propia_no_hay_fragmento(): void {
		Functions\when( 'is_shop' )->justReturn( true );
		Functions\expect( 'akibara_search_facets_view' )->never();

		$_GET['_akb_expand'] = 'serie';
		$fragment            = akibara_gp_akb_facet_options_fragment();
		unset( $_GET['_akb_expand'] );

		self::assertNull( $fragment );
	}

	/** @return string */
	private function render_sidebar(): string {
		ob_start();
		akibara_gp_akb_render_sidebar();

		return (string) ob_get_clean();
	}

	/** Una `s` vacía no puede mandar al cliente a la tienda sin consulta. */
	public function test_una_consulta_vacia_no_se_redirige(): void {
		Functions\when( 'is_search' )->justReturn( true );
		Functions\when( 'get_query_var' )->justReturn( 'product' );
		Functions\when( 'get_search_query' )->justReturn( '   ' );
		$_GET = array( 's' => '   ', 'post_type' => 'product' );

		akibara_gp_akb_canonicalize_product_search();
		$this->assertTrue( true, 'No hubo redirección.' );
	}

	/**
	 * `$wp->request` en la página 2 es `tienda/page/2`: el formulario de filtros
	 * apuntaba ahí, y quien tildaba «Ivrea» pedía la página 2 del conjunto
	 * FILTRADO, que casi nunca existe (hallazgo S-747bdfbd, 2026-09-19).
	 *
	 * @testdox Filtrar desde la página 2 lleva a la PRIMERA página del conjunto nuevo.
	 */
	public function test_filtrar_desde_la_pagina_dos_empieza_en_la_primera(): void {
		Functions\when( 'home_url' )->alias( static fn( string $ruta = '' ): string => 'https://akibara.test/' . ltrim( $ruta, '/' ) );
		Functions\when( 'user_trailingslashit' )->alias( static fn( string $r ): string => '' === $r ? '' : rtrim( $r, '/' ) . '/' );

		$GLOBALS['wp_rewrite'] = (object) array( 'pagination_base' => 'page' );

		foreach (
			array(
				'tienda/page/2'                     => 'https://akibara.test/tienda/',
				'tienda/page/37/'                   => 'https://akibara.test/tienda/',
				'categoria-producto/shonen/page/3'  => 'https://akibara.test/categoria-producto/shonen/',
				'tienda'                            => 'https://akibara.test/tienda/',
				// Una serie que se LLAMA «page» no es paginación.
				'serie/page'                        => 'https://akibara.test/serie/page/',
				'marca/page-one/page/2'             => 'https://akibara.test/marca/page-one/',
			) as $request => $esperada
		) {
			$GLOBALS['wp'] = (object) array( 'request' => $request );
			$this->assertSame( $esperada, akibara_gp_akb_form_action(), $request );
		}

		// Y con la base traducida, «page» pasa a ser un slug cualquiera.
		$GLOBALS['wp_rewrite'] = (object) array( 'pagination_base' => 'pagina' );
		$GLOBALS['wp']         = (object) array( 'request' => 'tienda/pagina/4' );
		$this->assertSame( 'https://akibara.test/tienda/', akibara_gp_akb_form_action() );

		unset( $GLOBALS['wp'], $GLOBALS['wp_rewrite'] );
	}
}
