<?php
/**
 * Asset enqueue — CSS modular + JS único.
 *
 * Estrategia:
 *   - Cache busting via filemtime (cada archivo invalida individualmente).
 *   - Se sirve la copia `.min` que genera el build (salvo SCRIPT_DEBUG), y
 *     woocommerce.css partida por contexto: ver akibara_gp_asset_servido().
 *   - Critical CSS inline first → tokens/fonts → base/header/footer → JS diferido.
 *   - Cero llamadas dinámicas: las URLs se calculan al inicio del request.
 *
 * @package akibara-gp
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Cache buster: versión del tema + `filemtime` del archivo individual.
 *
 * EN PRODUCCIÓN EL TOKEN ES GLOBAL, Y ES A PROPÓSITO. El empaquetador congela
 * los mtimes del ZIP a 1980-01-01 para que el artefacto sea reproducible (misma
 * entrada, mismo sha256), así que los ~103 ficheros del tema comparten
 * `315532800` y el sufijo vale lo mismo para todos: lo que de verdad invalida es
 * el número de versión del tema, que sube en cada release. La parte `filemtime`
 * sí discrimina por fichero en Docker y en cualquier árbol sin empaquetar, que
 * es donde hace falta.
 *
 * O sea: no es invalidación por fichero en el sitio en vivo, y el nombre podía
 * hacerlo creer. Se deja así —en vez de forzar un hash por contenido— porque un
 * token por release es exactamente lo que necesita un despliegue atómico, y
 * porque hacer reproducible el ZIP fue una decisión anterior y más cara.
 * Contrato ejecutable en `tests/AssetVersionTest.php`.
 *
 * @param string $rel_path Ruta del asset relativa a la raíz del tema.
 * @return string Token para el `?ver=`.
 */
function akibara_gp_asset_ver( string $rel_path ): string {
	$theme_version = function_exists( 'wp_get_theme' )
		? trim( (string) wp_get_theme()->get( 'Version' ) )
		: '';
	$theme_version = '' !== $theme_version ? $theme_version : '1';

	$asset_path = get_stylesheet_directory() . '/' . ltrim( $rel_path, '/' );
	$modified   = is_file( $asset_path ) ? filemtime( $asset_path ) : false;

	return false !== $modified ? $theme_version . '.' . $modified : $theme_version;
}

/**
 * ¿Se sirven las fuentes legibles en vez de las copias `.min`?
 *
 * Sólo con `SCRIPT_DEBUG`, el mismo interruptor con que WordPress sirve sus
 * propios `.js`/`.css` sin minificar. Es la palanca de depuración: con él
 * encendido el navegador recibe exactamente los ficheros que se editan.
 *
 * @return bool
 */
function akibara_gp_servir_fuentes(): bool {
	return defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG;
}

/**
 * Ruta (relativa al tema) del fichero que se SIRVE para una fuente.
 *
 * Entrega versiones `.min` en producción para eliminar comentarios y espacios
 * de recursos bloqueantes en páginas dinámicas no cacheadas (carrito, checkout,
 * mi cuenta y búsquedas). Si falta la copia minificada, recurre a la fuente original.
 *
 * @param string $rel_path Fuente, p. ej. `assets/css/loop.css`.
 * @param string $min_path Copia servida si no es la `.min` hermana (woocommerce.css
 *                         se sirve partida por contexto).
 * @return string
 */
function akibara_gp_asset_servido( string $rel_path, string $min_path = '' ): string {
	if ( akibara_gp_servir_fuentes() ) {
		return $rel_path;
	}

	if ( '' === $min_path ) {
		$min_path = (string) preg_replace( '/\.(css|js)$/', '.min.$1', $rel_path );
	}

	return ( $min_path !== $rel_path && is_file( get_stylesheet_directory() . '/' . $min_path ) )
		? $min_path
		: $rel_path;
}

/**
 * URL y `?ver` del fichero servido, listos para `wp_enqueue_*()`.
 *
 * La versión se calcula sobre el fichero SERVIDO (akibara_gp_asset_ver()): en
 * Docker, regenerar la `.min` invalida su caché aunque la fuente no cambie.
 *
 * @param string $rel_path Fuente.
 * @param string $min_path Ver akibara_gp_asset_servido().
 * @return array{0: string, 1: string}
 */
function akibara_gp_asset( string $rel_path, string $min_path = '' ): array {
	$servido = akibara_gp_asset_servido( $rel_path, $min_path );

	return array( get_stylesheet_directory_uri() . '/' . $servido, akibara_gp_asset_ver( $servido ) );
}

/**
 * Contexto de woocommerce.css para la página actual.
 *
 * Segmenta la hoja modular de WooCommerce en subconjuntos específicos
 * (`woocommerce-<contexto>.min.css`), reduciendo la transferencia bloqueante
 * preservando el orden de cascada original.
 *
 * @return string listado|ficha|carrito|cuenta
 */
function akibara_gp_woocommerce_css_contexto(): string {
	if ( function_exists( 'is_product' ) && is_product() ) {
		return 'ficha';
	}
	if ( function_exists( 'is_cart' ) && ( is_cart() || is_checkout() ) ) {
		return 'carrito';
	}
	if ( function_exists( 'is_account_page' ) && is_account_page() ) {
		return 'cuenta';
	}

	// Catálogo, búsqueda de productos, portada, 404 y portada alternativa.
	return 'listado';
}

/**
 * ¿La consulta principal no trajo ningún resultado?
 *
 * Decide si un listado necesita empty-states.css (PC-15): con resultados, esa
 * hoja no aplicaba ni una regla y bloqueaba igual (y vacío tampoco, si el aviso
 * lo pinta akibara-search: ver akibara_gp_enqueue()). Lo que llega por PJAX al
 * filtrar a cero NO puede depender de ella, porque el listado se cargó sin la
 * hoja: el aviso (`.akb-empty-state` de akibara-search) lo pinta woocommerce.css
 * y la venta cruzada que el tema imprime DENTRO del aviso
 * (`akibara_search_empty_after`), loop.css. Las dos van en todos los listados.
 * Una regla para algo que viaja dentro de `[data-akb-empty]` no va en
 * empty-states.css (revisión T4-R3).
 *
 * @return bool
 */
function akibara_gp_consulta_principal_vacia(): bool {
	$consulta = $GLOBALS['wp_query'] ?? null;

	return is_object( $consulta ) && isset( $consulta->post_count ) && 0 === (int) $consulta->post_count;
}

/**
 * Compacta el CSS crítico que viaja inline en cada documento.
 *
 * El fichero es la fuente mantenible y está comentado a conciencia: medido,
 * 3.646 de sus 5.322 bytes son comentarios de bloque. Como el bloque se sirve
 * INLINE, esos comentarios se pagan en todas las respuestas HTML del sitio, no
 * una vez en un asset cacheable.
 *
 * Deliberadamente conservador: sólo quita comentarios y colapsa espacio en
 * blanco. NO toca los dos puntos, las comas ni el punto y coma final de un
 * bloque, que es donde los minificadores caseros rompen la cascada. El memo es
 * por petición (una sola llamada por request); persistirlo en una option
 * costaría más que el `preg_replace` sobre 5 KB.
 *
 * @param string $css Hoja cruda.
 * @return string Hoja compactada.
 */
function akibara_gp_compact_css( string $css ): string {
	$sin_comentarios = preg_replace( '#/\*.*?\*/#s', '', $css );
	if ( ! is_string( $sin_comentarios ) ) {
		return $css;
	}

	$compacto = preg_replace( '/\s+/', ' ', $sin_comentarios );
	if ( ! is_string( $compacto ) ) {
		return $css;
	}

	$compacto = preg_replace( '/\s*([{};])\s*/', '$1', $compacto );

	return is_string( $compacto ) ? trim( $compacto ) : $css;
}

/**
 * Encola el CSS crítico como bloque inline real.
 *
 * El archivo sigue siendo la fuente mantenible, pero no se publica como una
 * petición externa. Así el fondo oscuro y los estados cerrados de los drawers
 * existen en el primer HTML incluso mientras LiteSpeed/Cloudflare reconstruyen
 * recursos minificados. Esto resuelve el flash blanco en la causa, sin excluir
 * el header de la optimización.
 */
function akibara_gp_enqueue_critical_css(): void {
	static $memo = array();

	$relative_path = 'assets/css/critical.css';
	$absolute_path = get_stylesheet_directory() . '/' . $relative_path;
	$modified      = is_file( $absolute_path ) ? filemtime( $absolute_path ) : 0;
	$memo_key      = $absolute_path . ':' . $modified;

	if ( isset( $memo[ $memo_key ] ) ) {
		$critical_css = $memo[ $memo_key ];
	} else {
		$raw = is_readable( $absolute_path ) ? file_get_contents( $absolute_path ) : false;

		// Fallback mínimo y autocontenido: nunca entregar un primer paint blanco si
		// el archivo faltara durante un despliegue incompleto.
		$critical_css = ( false === $raw || '' === trim( $raw ) )
			? 'html,body{background:#121212;color:#f5f5f5}'
			: akibara_gp_compact_css( $raw );

		$memo[ $memo_key ] = $critical_css;
	}

	wp_register_style(
		'akibara-critical',
		false,
		array(),
		akibara_gp_asset_ver( $relative_path )
	);
	wp_enqueue_style( 'akibara-critical' );
	wp_add_inline_style( 'akibara-critical', $critical_css );
}

/**
 * Encola CSS modular en orden de dependencia + JS deferred.
 *
 * Dependencia: critical inline → tokens/fonts → base → header/footer.
 */
function akibara_gp_enqueue(): void {
	$theme_uri = get_stylesheet_directory_uri();
	$is_catalog_archive = function_exists( 'akibara_gp_is_catalog_archive' )
		&& akibara_gp_is_catalog_archive();

	// El handle existe únicamente para enlazar los estilos dinámicos de GeneratePress
	// (`generate-style-inline-css`). Al registrarse sin src (`false`), WordPress
	// emite el CSS inline sin generar una petición HTTP bloqueante por una hoja vacía.
	wp_register_style( 'generate-style', false, array(), wp_get_theme( get_template() )->get( 'Version' ) );
	wp_enqueue_style( 'generate-style' );

	akibara_gp_enqueue_critical_css();

	/*
	 * El núcleo del tema se sirve unificado en `core.css` para minimizar contención
	 * de conexiones en la carga inicial sobre dispositivos móviles.
	 *
	 * Las hojas individuales (`tokens`, `fonts`, `base`, `header`, `footer`) se
	 * mantienen como fuentes modulares y se concatenan en tiempo de compilación.
	 * Los handles individuales se conservan registrados con src `false` para preservar
	 * la resolución de dependencias de plugins externos sin emitir enlaces duplicados.
	 */
	list( $src, $ver ) = akibara_gp_asset( 'assets/css/core.css' );
	wp_enqueue_style( 'akibara-core', $src, array( 'akibara-critical' ), $ver );

	foreach ( array( 'akibara-tokens', 'akibara-fonts', 'akibara-base', 'akibara-header', 'akibara-footer' ) as $akibara_gp_core_alias ) {
		wp_register_style( $akibara_gp_core_alias, false, array( 'akibara-core' ), null );
		wp_enqueue_style( $akibara_gp_core_alias );
	}
	unset( $akibara_gp_core_alias );

	// Conditional: WC contexts (shop, single product, cart, checkout, mi-cuenta).
	// Cargar woocommerce.css en cualquier contexto que renderee elementos WC:
	//   - Pages WC nativas: shop, archives, single product, cart, checkout, account
	//     (mi-cuenta cubre /mi-cuenta/favoritos/ del módulo Wishlist propio)
	//   - Home / home alternativa: usa shortcodes [products] / carruseles que muestran
	//     star-rating, badges, prices, add-to-cart buttons — necesitan el styling
	//     brand del child theme (bug histórico: stars salían azul cyan en home
	//     porque is_woocommerce() es false fuera de pages WC)
	//   - 404 / search no-results: cross-sell con WC products
	//
	// Aquí vivían `is_page( 'wishlist' )` e `is_page( 'manga' )`. Ninguna de las
	// dos casaba nunca: no hay página con esos slugs en producción (wishlist es
	// el endpoint /mi-cuenta/favoritos/, que ya entra por `is_account_page()`, y
	// /manga/ es un archivo de categoría de producto, que ya entra por
	// `is_woocommerce()`). Se retiraron en 2.1.139 — la cobertura real no cambia.
	//
	// Se sirve PARTIDA por contexto (akibara_gp_woocommerce_css_contexto()): cada
	// página baja sólo las reglas que pueden casar en ella, en el orden original.
	// El handle sigue siendo uno y sigue imprimiéndose antes de loop.css.
	if (
		( function_exists( 'is_woocommerce' ) && ( is_woocommerce() || is_cart() || is_checkout() || is_account_page() ) )
		|| $is_catalog_archive
		|| is_front_page()
		|| is_404()
		|| is_page( AKIBARA_GP_HOME_ALT )
		|| ( is_search() && 'product' === get_query_var( 'post_type' ) )
	) {
		list( $src, $ver ) = akibara_gp_asset(
			'assets/css/woocommerce.css',
			'assets/css/woocommerce-' . akibara_gp_woocommerce_css_contexto() . '.min.css'
		);
		wp_enqueue_style( 'akibara-woocommerce', $src, array( 'akibara-base' ), $ver );
	}

	// Conditional: contextos donde aparece el shop loop (shop, category,
	// tag, brand taxonomy, search results, home y home alternativa). Brand taxonomy
	// = `product_brand` nativa WC 9.4+, URL /marca/<slug>/. Plugin PWB
	// (taxonomy `pwb-brand` con URL /brand/) fue removido — sus URLs
	// legacy se mantienen desde la interfaz de Redirecciones de Rank Math.
	// `is_product()` también enquea loop.css para los badges `.akb-badge-single`
	// que el módulo LoopEnhancer renderiza en single product (priority 4 antes del title).
	// Empty/error states (404, cart, account=favoritos) renderizan cross-sell o
	// grid con `wc_get_template_part('content', 'product')` — necesitan loop.css
	// para que badges/precios/CTAs hereden el styling brand (sin esto, salen con
	// defaults WP cyan/blue links + price azul). is_account_page() cubre el
	// endpoint /mi-cuenta/favoritos/ del módulo Wishlist.
	//
	// La lista vive en `akibara_gp_surface_has_product_loop()` (inc/page-targets.php):
	// era la copia autoritativa de tres, y las otras dos habían derivado.
	$has_loop = akibara_gp_surface_has_product_loop();
	if ( $has_loop ) {
		list( $src, $ver ) = akibara_gp_asset( 'assets/css/loop.css' );
		wp_enqueue_style( 'akibara-loop', $src, array( 'akibara-base' ), $ver );
	}

	// Las introducciones editoriales de series, géneros y autores se pliegan
	// solo en móvil y conservan el texto completo en el DOM. Sin JS quedan
	// totalmente visibles.
	if ( function_exists( 'is_tax' ) && ( is_product_category() || is_tax( array( 'pa_serie', 'pa_genero', 'pa_autor' ) ) ) ) {
		list( $src, $ver ) = akibara_gp_asset( 'assets/js/archive-intro.js' );
		wp_enqueue_script(
			'akibara-archive-intro',
			$src,
			array(),
			$ver,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}

	// Conditional: páginas estáticas content-driven que usan el sistema
	// `.akb-page__hero / .akb-page__section / .akb-info-grid / .akb-feature-grid /
	// .akb-faq / .akb-page__toc` declarado en pages.css. El reset
	// `.page .entry-header { display:none }` vive en ese mismo CSS y evita
	// doble H1 con el hero del contenido.
	//
	// El conjunto vive por SLUG en inc/page-targets.php (AKIBARA_GP_CONTENT_PAGES).
	if ( akibara_gp_is_content_page() ) {
		list( $src, $ver ) = akibara_gp_asset( 'assets/css/pages.css' );
		wp_enqueue_style( 'akibara-pages', $src, array( 'akibara-base' ), $ver );
	}

	// Conditional: contextos donde puede renderizarse un empty/error state
	// branded (404, cart vacío, search/category sin resultados, wishlist vacío
	// en /mi-cuenta/favoritos/). El CSS `empty-states.css` define `.akb-empty`,
	// `.akb-404`, `.akb-cart-empty`, `.akb-no-results` — todos los selectores
	// son scoped, así que enquearlo fuera de contexto no genera conflictos.
	//
	// En los listados sólo cuando la consulta principal vino vacía (PC-15): con
	// resultados era una hoja bloqueante sin una sola regla aplicada (medido en
	// /tienda/ y /manga/). Y ni siquiera vacía si el filtrado propio de
	// akibara-search está activo: entonces el aviso es su `.akb-empty-state`
	// (lo estiliza woocommerce.css) y el buscador retira no-products-found.php,
	// la única plantilla de listado que usa esta hoja (`.akb-no-results`). Medido
	// en /tienda/ y /serie/blue-lock/ con `?_buscar=` sin resultados: 0 de sus 31
	// reglas casaban, en 390 y 1440 (revisión rev-T4-3-D2). Con las facetas
	// apagadas (fail-open) sale la plantilla del tema y la hoja vuelve. Lo que un
	// listado recibe vacío por PJAX no se estiliza aquí (ver
	// akibara_gp_consulta_principal_vacia()). El carrito la lleva siempre:
	// además del carrito vacío, reserva el alto del carrito lleno mientras el
	// bloque hidrata.
	$is_listado = ( function_exists( 'is_woocommerce' ) && ( is_shop() || is_product_category() || is_product_tag() ) )
		|| $is_catalog_archive
		|| ( is_search() && 'product' === get_query_var( 'post_type' ) );
	$is_native_search   = is_search() && 'product' !== get_query_var( 'post_type' );
	$aviso_del_buscador = function_exists( 'akibara_search_facets_active' ) && akibara_search_facets_active();
	if (
		is_404()
		|| ( function_exists( 'is_cart' ) && is_cart() )
		|| ( $is_listado && akibara_gp_consulta_principal_vacia() && ! $aviso_del_buscador )
		|| ( $is_native_search && akibara_gp_consulta_principal_vacia() )
		|| ( function_exists( 'is_account_page' ) && is_account_page() )
	) {
		list( $src, $ver ) = akibara_gp_asset( 'assets/css/empty-states.css' );
		wp_enqueue_style( 'akibara-empty-states', $src, array( 'akibara-base' ), $ver );
	}

	// Conditional: blog index (/blog/), single posts, category/tag/author
	// archives. blog.css trae los wrappers `.akb-blog`, `.akb-blog-card`,
	// `.akb-post` con styling brand-aligned (links rojo, h2 con accent,
	// padding lateral, prose readable). Sin esto el contenido editorial
	// hereda GeneratePress vanilla — links azul WP default, sin padding,
	// look inconsistente con el e-commerce.
	//
	// La búsqueda nativa (`/?s=`) entra también: lista entradas con el mismo
	// marcado y encima es donde vive `.akb-search-bridge`, el bloque que manda
	// al buscador de la tienda. Sin la hoja, ese bloque se publicaba sin un solo
	// estilo — el fallo clásico de pedir una clase que ninguna hoja define.
	$is_post_archive = is_home() || is_category() || is_tag() || is_author() || is_date();
	if ( is_singular( 'post' ) || $is_post_archive || $is_native_search ) {
		list( $src, $ver ) = akibara_gp_asset( 'assets/css/blog.css' );
		wp_enqueue_style( 'akibara-blog', $src, array( 'akibara-base' ), $ver );
	}

	// Scroll-spy de `.akb-page__toc` en páginas legales y del bloque
	// oficial `#rank-math-toc` en posts. No-opea si la página no tiene índice.
	if ( akibara_gp_is_content_page() || is_singular( 'post' ) ) {
		list( $src, $ver ) = akibara_gp_asset( 'assets/js/page-toc.js' );
		wp_enqueue_script( 'akibara-page-toc', $src, array(), $ver, array( 'in_footer' => true, 'strategy' => 'defer' ) );
	}

	// home.css contiene el SISTEMA `.akb-section` (header con bullet rojo +
	// border-bottom) que estructura las secciones del home (Recién llegados,
	// Manga, Cómics, Preventas, Editoriales). Solo se enqueuea en home /
	// home alternativa (Inicio).
	if ( akibara_gp_is_home_surface() ) {
		list( $src, $ver ) = akibara_gp_asset( 'assets/css/home.css' );
		wp_enqueue_style( 'akibara-home', $src, array( 'akibara-base' ), $ver );
	}

	// Conditional: home + home alternativa (Inicio) — bloques de UI del home.
	// El hero configurable `.akb-hero*` se sirve desde akibara-business; el theme
	// conserva solo trust + brand-carousel wrappers propios.
	if ( akibara_gp_is_home_surface() ) {
		list( $src, $ver ) = akibara_gp_asset( 'assets/css/trust.css' );
		wp_enqueue_style( 'akibara-trust', $src, array( 'akibara-base' ), $ver );
		list( $src, $ver ) = akibara_gp_asset( 'assets/css/brands-home.css' );
		wp_enqueue_style( 'akibara-brands-home', $src, array( 'akibara-base' ), $ver );
	}

	// Shim puente jQuery → CustomEvent. WC clásico dispara sus eventos con
	// `jQuery(document.body).trigger(...)`, que `addEventListener` nativo NO oye.
	// Este shim los re-emite como CustomEvent `akb:*` para que los listeners del
	// theme (main.js, animations.js) reaccionen en vanilla.
	// Debe cargar ANTES que ellos y estar registrado antes de la primera
	// interacción → mismo tratamiento anti-LiteSpeed que main.js/atc-guard más
	// abajo. jQuery como dep (WC core lo carga; el shim es el único punto jQuery
	// que necesita el bus del theme). NO saca jQuery del sitio: es mantenibilidad.
	list( $src, $ver ) = akibara_gp_asset( 'assets/js/wc-event-bridge.js' );
	wp_enqueue_script(
		'akibara-wc-event-bridge',
		$src,
		array( 'jquery' ),
		$ver,
		array(
			'in_footer' => false,
			'strategy'  => 'defer',
		)
	);

	list( $src, $ver ) = akibara_gp_asset( 'assets/js/main.js' );
	wp_enqueue_script(
		'akibara-main',
		$src,
		array( 'akibara-wc-event-bridge' ),
		$ver,
		array(
			'in_footer' => false,
			'strategy'  => 'defer',
		)
	);

	/*
	 * Franja inferior fija: mide en vivo cuánto ocupan el bottom-nav y, en la
	 * ficha, la barra de compra apilada encima, y lo escribe en
	 * `--akb-franja-inferior` para el `scroll-padding-bottom` de base.css.
	 * Va en TODAS las páginas porque el bottom-nav lo pinta footer.php en todas
	 * (visible ≤1024px por CSS); en escritorio sólo mide la barra de compra de la
	 * ficha, pegada al borde (UC-4), y en el resto de páginas mide 0. Sin
	 * dependencias: no toca jQuery ni el bus de eventos. Diferido al pie: la
	 * medida inicial se toma en DOMContentLoaded y el respaldo del CSS (peor
	 * caso) cubre hasta entonces.
	 */
	list( $src, $ver ) = akibara_gp_asset( 'assets/js/franja-inferior.js' );
	wp_enqueue_script(
		'akibara-franja-inferior',
		$src,
		array(),
		$ver,
		array(
			'in_footer' => true,
			'strategy'  => 'defer',
		)
	);

	/*
	 * Aquí se encolaba `cart-empty-reload.js`, que recargaba /carrito/ cuando el
	 * AJAX de borrado dejaba la tabla sin filas, para que se sirviera el
	 * `cart-empty.php` branded. Ese guion era del carrito CLÁSICO: la página es de
	 * bloques y no hay `tr.cart_item` que contar, así que su sonda leía cero filas
	 * siempre — y el evento que la despertaba habría disparado un
	 * `location.reload()` en bucle. El vaciado ya lo re-renderea el propio
	 * empty-cart-block.
	 */

	// Conditional: solo en single product.
	// - sticky-atc.js (UX P2-V7): IntersectionObserver sobre form.cart +
	//   proxy click al botón real. Markup en template-parts/woocommerce/sticky-atc.php.
	// - pdp-ajax-atc.js (UX P2-V10): intercepta el submit de form.cart →
	//   wc-ajax=add_to_cart sin reload + estados is-adding/is-added. jQuery
	//   como dependencia: dispara `added_to_cart` (NativeCart drawer escucha).
	if ( function_exists( 'is_product' ) && is_product() ) {
		list( $src, $ver ) = akibara_gp_asset( 'assets/js/sticky-atc.js' );
		wp_enqueue_script(
			'akibara-sticky-atc',
			$src,
			array(),
			$ver,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		list( $src, $ver ) = akibara_gp_asset( 'assets/js/pdp-ajax-atc.js' );
		wp_enqueue_script(
			'akibara-pdp-ajax-atc',
			$src,
			array( 'jquery', 'akibara-wc-event-bridge' ),
			$ver,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}

	// Guard del add-to-cart de las cards: bloquea el 2º request en vuelo, que en
	// stock=1 expulsaba al PDP, y ante un rechazo del servidor pinta el motivo en
	// la tarjeta en vez de dejar que WooCommerce mande a la ficha (UM-4). No
	// depende de `wc-add-to-cart` como handle (escucha eventos jQuery de WC en
	// document.body y prefiltra su petición); inerte si WC no carga. Va en TODA superficie con rejilla —la misma lista que decide el
	// dequeue de `wc-add-to-cart`—: donde hay botón de compra tiene que haber
	// guard, y su copia drifteada dejaba /genero/ y /autor/ sin él.
	if ( $has_loop ) {
		list( $src, $ver ) = akibara_gp_asset( 'assets/js/atc-guard.js' );
		wp_enqueue_script(
			'akibara-atc-guard',
			$src,
			array( 'jquery' ),
			$ver,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}

	// Micro-animaciones GSAP (reveal de cards al scroll, intro del hero, vuelo al
	// carrito, marquesina) en páginas con loops de producto. El reveal es
	// progressive enhancement: sin GSAP las cards se ven normales por CSS.
	//
	// Se deriva de la MISMA lista que el guard para que no vuelva a derivar, menos
	// carrito y Mi cuenta: ahí la rejilla es cross-sell/favoritos, no superficie de
	// descubrimiento, y GSAP + ScrollTrigger son 46 KB que no se justifican.
	//
	// Los tres ficheros se REGISTRAN siempre (el carrusel de reseñas de rewards
	// los pide como dependencia si están registrados), pero sólo la portada los
	// ENCOLA: ahí el intro del hero corre al cargar. En el resto los inyecta
	// animations-loader.js tras `load` o a la primera interacción, fuera de la
	// ventana del LCP (PC-8/PM-11; ver akibara_gp_deferred_animation_sources()).
	// El cargador va en todas: es el dueño de «agregar → cajón», que no puede
	// esperar a GSAP.
	if ( akibara_gp_surface_has_animations( $has_loop ) ) {
		wp_register_script( 'gsap', $theme_uri . '/assets/js/vendor/gsap.min.js', array(), akibara_gp_asset_ver( 'assets/js/vendor/gsap.min.js' ), array( 'in_footer' => true, 'strategy' => 'defer' ) );
		wp_register_script( 'gsap-scrolltrigger', $theme_uri . '/assets/js/vendor/ScrollTrigger.min.js', array( 'gsap' ), akibara_gp_asset_ver( 'assets/js/vendor/ScrollTrigger.min.js' ), array( 'in_footer' => true, 'strategy' => 'defer' ) );
		list( $src, $ver ) = akibara_gp_asset( 'assets/js/animations.js' );
		wp_register_script( 'akibara-animations', $src, array( 'gsap', 'gsap-scrolltrigger' ), $ver, array( 'in_footer' => true, 'strategy' => 'defer' ) );
		list( $src, $ver ) = akibara_gp_asset( 'assets/js/animations-loader.js' );
		wp_enqueue_script(
			'akibara-animations-loader',
			$src,
			// Escucha `akb:added_to_cart`, que emite el shim al re-emitir el
			// evento jQuery `added_to_cart` de WC: la dependencia garantiza que
			// el puente exista antes.
			array( 'akibara-wc-event-bridge' ),
			$ver,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		if ( akibara_gp_is_home_surface() ) {
			wp_enqueue_script( 'akibara-animations' );
		}
	}
}
add_action( 'wp_enqueue_scripts', 'akibara_gp_enqueue' );

/**
 * ¿Lleva esta superficie las micro-animaciones (y su cargador)?
 *
 * Toda superficie con rejilla de producto menos carrito y Mi cuenta.
 *
 * @param bool $has_loop Resultado de akibara_gp_surface_has_product_loop().
 * @return bool
 */
function akibara_gp_surface_has_animations( bool $has_loop ): bool {
	return $has_loop
		&& ! ( function_exists( 'is_cart' ) && is_cart() )
		&& ! ( function_exists( 'is_account_page' ) && is_account_page() );
}

/**
 * URLs que animations-loader.js inyecta tarde, en orden de ejecución.
 *
 * Vacío cuando animations.js ya va encolado en el HTML (la portada) o cuando
 * la página no registró las animaciones. Se leen del registro de WordPress
 * —la misma `src` y el mismo `?ver` que imprimiría él, pasando por
 * `script_loader_src`— para que el día que cambie una ruta o el cache buster
 * no haya una segunda copia que derive.
 *
 * @return array<int, string>
 */
function akibara_gp_deferred_animation_sources(): array {
	if ( ! wp_script_is( 'akibara-animations', 'registered' ) || wp_script_is( 'akibara-animations', 'enqueued' ) ) {
		return array();
	}

	$registered = wp_scripts()->registered;
	$sources    = array();
	foreach ( array( 'gsap', 'gsap-scrolltrigger', 'akibara-animations' ) as $handle ) {
		if ( ! isset( $registered[ $handle ] ) || ! is_string( $registered[ $handle ]->src ) || '' === $registered[ $handle ]->src ) {
			return array();
		}
		$script = $registered[ $handle ];
		$src    = is_string( $script->ver ) && '' !== $script->ver
			? add_query_arg( 'ver', $script->ver, $script->src )
			: $script->src;
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- filtro de core: el MISMO que aplica WP_Scripts al imprimir la src, no un hook inventado.
		$src = apply_filters( 'script_loader_src', $src, $handle );
		if ( ! is_string( $src ) || '' === $src ) {
			return array();
		}
		$sources[] = $src;
	}

	return $sources;
}

/**
 * Precarga las fuentes críticas del above-the-fold (Bebas Neue = headings/CTA/
 * logo; Inter = cuerpo) para eliminar FOUT y desplazamientos de layout.
 *
 * Los pesos precargados corresponden estrictamente a los requeridos en el
 * primer viewport móvil: Inter 400, 500 y 700, y Bebas Neue 400.
 */
function akibara_gp_preload_fonts(): void {
	$base  = get_stylesheet_directory_uri() . '/assets/fonts/';
	$fonts = array(
		'bebas-neue-v16-latin-regular.woff2',
		'inter-v18-latin-regular.woff2',
		'inter-v18-latin-500.woff2',
		'inter-v18-latin-700.woff2',
	);
	foreach ( $fonts as $font ) {
		printf(
			'<link rel="preload" href="%s" as="font" type="font/woff2" crossorigin>' . "\n",
			esc_url( $base . $font )
		);
	}
}
add_action( 'wp_head', 'akibara_gp_preload_fonts', 1 );

/**
 * Imprime en línea el captador de toques tempranos (QAM-2, assets/js/toque-temprano.js).
 *
 * Con la red lenta el cliente ve la página ~2,4 s antes de que el JS diferido
 * enganche sus manejadores, y un toque en ☰, «Buscar» o «Añadir al carrito» de
 * una tarjeta se perdía. El guion retiene ese toque y lo repite al terminar de
 * arrancar. Tiene que ir EN LÍNEA y en el <head>: como fichero diferido llegaría
 * con los demás, justo cuando ya no hace falta. Son ~1,7 KB minificados.
 *
 * `data-no-optimize` / `data-no-defer`: que LiteSpeed no lo atrase ni lo
 * difiera (lo mismo que main.js, ver akibara_gp_main_script_loader_tag()). Se
 * imprime la copia SERVIDA (akibara_gp_asset_servido(): la `.min`, o la fuente
 * si falta); sin ninguna de las dos no se imprime nada y la página queda como
 * antes.
 */
function akibara_gp_print_toque_temprano(): void {
	static $memo = null;

	if ( null === $memo ) {
		$ruta = get_stylesheet_directory() . '/' . akibara_gp_asset_servido( 'assets/js/toque-temprano.js' );
		$js   = is_readable( $ruta ) ? file_get_contents( $ruta ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichero local del tema, no una URL.
		$memo = is_string( $js ) ? trim( $js ) : '';
	}
	if ( '' === $memo ) {
		return;
	}

	wp_print_inline_script_tag(
		$memo,
		array(
			'id'                => 'akb-toque-temprano',
			'data-no-optimize'  => '1',
			'data-no-defer'     => '1',
		)
	);
}
add_action( 'wp_head', 'akibara_gp_print_toque_temprano', 2 );

/**
 * Rutas de un guion del tema para las listas de exclusión de LiteSpeed.
 *
 * LiteSpeed compara cada entrada como SUBCADENA de la URL
 * (`Utility::str_hit_array()`), y `assets/js/main.js` no es subcadena de
 * `assets/js/main.min.js`: desde que se sirve la copia `.min`
 * (akibara_gp_asset_servido()) hay que listar las dos, o la exclusión dejaría de
 * casar en silencio. La que de verdad protege es `data-no-optimize`
 * (akibara_gp_main_script_loader_tag()); esta lista es la segunda llave.
 *
 * @param string $nombre Nombre del guion sin extensión (p. ej. `main`).
 * @return array<int, string>
 */
function akibara_gp_litespeed_rutas_js( string $nombre ): array {
	$rutas = array();
	foreach ( array( $nombre . '.js', $nombre . '.min.js' ) as $fichero ) {
		$rutas[] = 'assets/js/' . $fichero;
		$rutas[] = 'wp-content/themes/akibara-gp/assets/js/' . $fichero;
	}

	return $rutas;
}

/**
 * Keep the header interaction bundle out of LiteSpeed combine/delay.
 *
 * The search box, mobile drawer, and live-search history are visible above the
 * fold. Serving this small cached file directly avoids a first-interaction race
 * where a combined LiteSpeed bundle can execute after the user submits search.
 *
 * @param mixed $exclusions Existing LiteSpeed exclusions.
 * @return array<int, string>
 */
function akibara_gp_litespeed_main_js_exclusions( $exclusions ): array {
	$list = is_array( $exclusions )
		? $exclusions
		: ( preg_split( '/[\r\n]+/', (string) $exclusions ) ?: array() );

	$list[] = 'akibara-main';
	$list   = array_merge( $list, akibara_gp_litespeed_rutas_js( 'main' ) );

	// Shim jQuery→CustomEvent: es la FUENTE de los eventos `akb:*` que oyen
	// main.js y animations.js. Si LiteSpeed lo combina/atrasa
	// hasta interacción, el `.on()` jQuery del shim puede engancharse DESPUÉS de
	// que WC ya disparó el evento → ninguna re-emisión ocurre (badges/vuelo/reload
	// mudos). Se excluye igual que main.js/atc-guard.
	$list[] = 'akibara-wc-event-bridge';
	$list   = array_merge( $list, akibara_gp_litespeed_rutas_js( 'wc-event-bridge' ) );

	// GSAP + animaciones: deben ejecutar en load (no esperar combine/delay de LiteSpeed).
	// En prod el bundle combinado NO exponía window.gsap → animations.js no-opeaba y el
	// "vuela al carrito" / reveals / hero no corrían. Se excluyen como main.js (siguen
	// con defer nativo + footer, solo se sacan del combine/delay de LiteSpeed).
	$list[] = 'gsap';
	$list[] = 'gsap-scrolltrigger';
	$list[] = 'akibara-animations';
	$list[] = 'assets/js/vendor/gsap.min.js';
	$list[] = 'assets/js/vendor/ScrollTrigger.min.js';
	$list[] = 'wp-content/themes/akibara-gp/assets/js/vendor/gsap.min.js';
	$list[] = 'wp-content/themes/akibara-gp/assets/js/vendor/ScrollTrigger.min.js';
	$list   = array_merge( $list, akibara_gp_litespeed_rutas_js( 'animations' ) );
	// El cargador abre el cajón al agregar y lee sus URLs de su propio
	// `data-akb-diferidos` vía `document.currentScript`: combinado o atrasado
	// por LiteSpeed, el cajón no abriría hasta la primera interacción y el
	// atributo no llegaría con él.
	$list[] = 'akibara-animations-loader';
	$list   = array_merge( $list, akibara_gp_litespeed_rutas_js( 'animations-loader' ) );

	// Guard anti doble-disparo del add-to-cart: el listener escucha el evento jQuery
	// `should_send_ajax_request.adding_to_cart` de WC-core. Bajo combine/delay puede
	// engancharse DESPUÉS de que WC ya disparó el evento → el guard no-opea y el bug
	// (2º request → window.location al PDP) regresa en prod. Se excluye como los
	// hermanos jQuery-event (gsap/animations).
	$list[] = 'akibara-atc-guard';
	// La ficha es MÁS frágil que el guard: si LiteSpeed atrasa pdp-ajax-atc.js
	// hasta la primera interacción, ese primer clic hace submit nativo de
	// form.cart y produce la recarga completa en el camino FELIZ. Hoy no muerde
	// porque el mu-plugin de barandas fuerza `optm-js_comb` a false, pero eso es
	// un ajuste, no una garantía: vuelve con un clic desde el panel.
	$list[] = 'akibara-pdp-ajax-atc';
	$list[] = 'akibara-sticky-atc';
	$list   = array_merge( $list, akibara_gp_litespeed_rutas_js( 'atc-guard' ) );

	// Directory filters must read incoming region deep links before first paint.
	// LiteSpeed delay would otherwise leave ?region=argentina/espana unfiltered
	// until the visitor's first interaction. Lo encola akibara-business y desde
	// T4 sirve la copia `.min` (ThemeAssetVersion::served_path()): las dos rutas.
	$list[] = 'akibara-brands-filter';
	$list   = array_merge( $list, akibara_gp_litespeed_rutas_js( 'akibara-brands-filter' ) );

	return array_values( array_unique( array_filter( array_map( 'trim', $list ) ) ) );
}
add_filter( 'litespeed_optimize_js_excludes', 'akibara_gp_litespeed_main_js_exclusions' );
add_filter( 'litespeed_optm_js_defer_exc', 'akibara_gp_litespeed_main_js_exclusions' );
add_filter( 'litespeed_optm_gm_js_exc', 'akibara_gp_litespeed_main_js_exclusions' );

/**
 * Add LiteSpeed opt-out attributes to the main theme script tag.
 *
 * @param string $tag    Script tag HTML.
 * @param string $handle Registered script handle.
 * @return string
 */
function akibara_gp_main_script_loader_tag( string $tag, string $handle ): string {
	// main.js + GSAP/animaciones: opt-out del optimize/delay de LiteSpeed (conservan el
	// `defer` nativo de WP; solo evitan que LiteSpeed los combine/atrase hasta interacción).
	$handles = array( 'akibara-wc-event-bridge', 'akibara-main', 'gsap', 'gsap-scrolltrigger', 'akibara-animations', 'akibara-animations-loader', 'akibara-atc-guard', 'akibara-pdp-ajax-atc', 'akibara-sticky-atc', 'akibara-brands-filter' );
	if ( ! in_array( $handle, $handles, true ) ) {
		return $tag;
	}

	if ( false === strpos( $tag, 'data-no-optimize' ) ) {
		$tag = str_replace( '<script ', '<script data-no-optimize="1" data-no-defer="1" ', $tag );
	}

	// Lo que el cargador inyecta tarde fuera de la portada (PC-8/PM-11).
	if ( 'akibara-animations-loader' === $handle && false === strpos( $tag, 'data-akb-diferidos' ) ) {
		$sources = akibara_gp_deferred_animation_sources();
		if ( array() !== $sources ) {
			$tag = preg_replace(
				'/<script\b(?=[^>]*\bid=["\']akibara-animations-loader-js["\'])/',
				'<script data-akb-diferidos="' . esc_attr( (string) wp_json_encode( $sources ) ) . '"',
				$tag,
				1
			) ?? $tag;
		}
	}

	return $tag;
}
add_filter( 'script_loader_tag', 'akibara_gp_main_script_loader_tag', 10, 2 );

/**
 * El `style.css` del child theme no se sirve como recurso HTTP independiente
 * al contener únicamente metadatos de WordPress. Los estilos se gestionan
 * modularmente desde `assets/css/` vía `akibara_gp_enqueue()`.
 */
add_filter( 'generate_load_child_theme_stylesheet', '__return_false' );
