<?php
/**
 * WooCommerce — UI filters minimalistas (rename labels, etc.).
 *
 * Solo afecta strings UI WC. Lógica de negocio (pricing, shipping, RUT)
 * vive en akibara-business plugin, no acá.
 *
 * @package akibara-gp
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Customiza items del menú lateral de mi-cuenta.
 *
 *   - Remueve "Descargas" (Akibara no vende productos descargables — todo es
 *     manga/cómic físico, el item default de WC es ruido).
 *
 * Prioridad alta (99) para sobrescribir el orden de cualquier plugin terceros.
 *
 * @param array<string, string> $items Menu items keyed by slug.
 * @return array<string, string>
 */
function akibara_gp_rename_account_menu_items( array $items ): array {
	unset( $items['downloads'] );
	return $items;
}
add_filter( 'woocommerce_account_menu_items', 'akibara_gp_rename_account_menu_items', 99 );

/**
 * Contextos que deben comportarse como catálogo: shop, taxonomías y
 * búsqueda de productos.
 */
function akibara_gp_is_catalog_archive(): bool {
	if ( ! function_exists( 'is_woocommerce' ) ) {
		return false;
	}

	return is_shop()
		|| is_product_taxonomy()
		|| ( is_search() && 'product' === get_query_var( 'post_type' ) );
}

/**
 * Redirige el endpoint `/mi-cuenta/downloads/` al dashboard.
 *
 * El filter de arriba esconde la tab del menú, pero el endpoint queda
 * accesible vía URL directa (bookmark, link externo, autocomplete). Sin
 * digital products en el catálogo el endpoint solo muestra "No hay descargas
 * disponibles" — ruido. Redirige todo hit a /mi-cuenta/.
 */
function akibara_gp_redirect_downloads_endpoint(): void {
	if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'downloads' ) ) {
		wp_safe_redirect( wc_get_page_permalink( 'myaccount' ), 301 );
		exit;
	}
}
add_action( 'template_redirect', 'akibara_gp_redirect_downloads_endpoint' );

/**
 * Limpia sufijo SEO "en Akibara" / "EN AKIBARA" del final de los H2 dentro
 * del post_content de single product.
 *
 * Contexto: el copywriter inyectó `... en Akibara` al final de cada H2
 * principal de producto para SEO (ej. "MANGA NARUTO VOL. 72 – EL DUELO FINAL
 * EN EL VALLE DEL FIN EN AKIBARA"). Al usuario se le ve como ruido visible.
 * Strip el sufijo cuando aparece justo antes del cierre `</h2>`.
 *
 * Auto-crítica:
 *  - Solo aplica en `is_product()` para no alterar otros contextos.
 *  - Regex anchored al cierre `</h2>` con whitespace tolerante; case insensitive
 *    para capturar tanto "en Akibara" como "EN AKIBARA".
 *  - No toca otros usos de "en Akibara" en párrafos (intencional — solo H2 del
 *    título principal lleva este artefacto).
 *  - Idempotente: si el sufijo no está, no hace nada.
 *
 * @param string $content HTML de la ficha.
 * @return string
 */
function akibara_gp_strip_seo_suffix_from_product_h2( string $content ): string {
	if ( ! function_exists( 'is_product' ) || ! is_product() ) {
		return $content;
	}

	return (string) preg_replace(
		'#(\s+en\s+Akibara\.?)\s*</h2>#iu',
		'</h2>',
		$content
	);
}
add_filter( 'the_content', 'akibara_gp_strip_seo_suffix_from_product_h2', 20 );

/**
 * Sidebar layout — DUEÑO ÚNICO del filtro `generate_sidebar_layout`.
 *
 * Antes había dos callbacks del propio tema encadenados en la misma prioridad
 * (`inc/setup.php` y este), así que la respuesta salía de componer uno sobre
 * otro y nadie podía leer la regla en un solo sitio. La composición que se
 * servía —y que esta función reproduce exactamente— era:
 *
 *   - single product        → no-sidebar (el PDP usa full width).
 *   - pa_serie / pa_autor   → no-sidebar (tomos de una serie / obras de un autor:
 *                             filtrar ahí es redundante y las portadas piden ancho).
 *   - archive de catálogo   → left-sidebar (hospeda las facetas).
 *   - búsqueda nativa       → left-sidebar (la de entradas; la de productos ya
 *                             entra por `akibara_gp_is_catalog_archive()`).
 *   - resto de singulares   → lo que diga `_generate-sidebar-layout-meta` si el
 *                             editor lo fijó; si no, no-sidebar.
 *   - todo lo demás         → no-sidebar (Akibara nunca usó sidebar en blog/pages).
 *
 * El `class_exists( 'WooCommerce' )` que guardaba la rama de búsqueda se retiró:
 * WooCommerce es dependencia dura del tema, la guarda era siempre verdadera.
 *
 * @param string $layout Layout actual ('left-sidebar', 'right-sidebar', 'no-sidebar', 'both-sidebars').
 */
function akibara_gp_archive_sidebar_layout( string $layout ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- el contrato del filtro lo pasa; el tema decide el layout entero.
	// El valor entrante no se devuelve en ninguna rama: el tema decide el layout
	// entero. Se conserva el parámetro porque el contrato del filtro lo pasa.
	if ( is_singular( 'product' ) ) {
		return 'no-sidebar';
	}
	if ( is_tax( array( 'pa_serie', 'pa_autor' ) ) ) {
		return 'no-sidebar';
	}
	if ( akibara_gp_is_catalog_archive() || is_search() ) {
		return 'left-sidebar';
	}

	$per_page = is_singular() ? get_post_meta( (int) get_the_ID(), '_generate-sidebar-layout-meta', true ) : '';

	return $per_page ? (string) $per_page : 'no-sidebar';
}
add_filter( 'generate_sidebar_layout', 'akibara_gp_archive_sidebar_layout' );

/**
 * Hero de marca para los singles de editorial (product_brand) y serie (pa_serie).
 *
 * Reemplaza el header vainilla de WC (H1 pelado + result-count) por un bloque con
 * logo (solo editoriales), kicker, título, descripción curada y conteo — reusando
 * la misma fuente de datos que el directorio /editoriales/ (term meta thumbnail_id
 * o el SVG local por slug). Sin esto, los singles caían a la grilla genérica de WC
 * y la copy de venta quedaba enterrada al pie.
 */
function akibara_gp_is_brand_or_series_archive(): bool {
	return function_exists( 'is_tax' ) && is_tax( array( 'product_brand', 'pa_serie' ) );
}

/**
 * Resuelve el logo de una editorial: SVG local por slug o imagen del término.
 *
 * FUENTE ÚNICA de las tres superficies que lo pintan (hero del archive,
 * directorio /editoriales/ y carrusel del home). Estaba copiada tres veces con
 * tres políticas de dimensiones distintas —120×120, 200×100 y ninguna— y sólo
 * dos de las tres declaraban `width`/`height`.
 *
 * Las cajas son de tamaño FIJO por CSS (`.akb-brand-card__logo img`,
 * `.akb-brands-home__logo img` y `.akb-archive-hero__logo img` usan
 * `object-fit: contain` dentro de una placa), así que `w`/`h` sólo sirven para
 * que el navegador reserve el hueco antes de que llegue la hoja: se declara la
 * proporción de la placa, no la del fichero. Los SVG del tema van de 1,04:1 a
 * 6,6:1 y ninguna medida única los describiría.
 *
 * @param string $slug         Slug del término de editorial.
 * @param int    $thumbnail_id Adjunto del término (0 = ninguno).
 * @return array{url:string,w:int,h:int} `url` vacío = sin logo.
 */
function akibara_gp_publisher_matrix_key( string $slug ): string {
	$slug = function_exists( 'sanitize_title' ) ? sanitize_title( $slug ) : strtolower( trim( $slug ) );
	if ( '' === $slug ) {
		return '';
	}

	$matrix = array(
		'ivrea'               => 'ivrea-argentina',
		'ivrea-argentina'     => 'ivrea-argentina',
		'ivrea-espana'        => 'ivrea-espana',
		'panini'              => 'panini',
		'panini-argentina'    => 'panini',
		'panini-espana'       => 'panini',
		'panini-manga'        => 'panini',
		'planeta-comic'       => 'planeta-comic',
		'planeta-espana'      => 'planeta-comic',
		'milky-way'           => 'milky-way',
		'milky-way-ediciones' => 'milky-way',
		'ovni-press'          => 'ovni-press',
		'arechi-manga'        => 'arechi-manga',
		'arechi'              => 'arechi-manga',
		'distrito-manga'      => 'distrito-manga',
		'distrito'            => 'distrito-manga',
		'devir'               => 'devir',
		'devir-iberia'        => 'devir',
		'devir-espana'        => 'devir',
	);

	if ( isset( $matrix[ $slug ] ) ) {
		return $matrix[ $slug ];
	}

	foreach ( array( 'ivrea', 'panini', 'planeta', 'milky', 'ovni', 'arechi', 'distrito', 'devir' ) as $prefix ) {
		if ( 0 === strpos( $slug, $prefix ) ) {
			if ( 'ivrea' === $prefix ) {
				return false !== strpos( $slug, 'espana' ) ? 'ivrea-espana' : 'ivrea-argentina';
			}
			if ( 'planeta' === $prefix ) {
				return 'planeta-comic';
			}
			if ( 'milky' === $prefix ) {
				return 'milky-way';
			}
			if ( 'ovni' === $prefix ) {
				return 'ovni-press';
			}
			if ( 'arechi' === $prefix ) {
				return 'arechi-manga';
			}
			if ( 'distrito' === $prefix ) {
				return 'distrito-manga';
			}
			if ( 'devir' === $prefix ) {
				return 'devir';
			}
			return $prefix;
		}
	}

	return '';
}

/**
 * Renderiza el marcado SVG accesible y limpio para un logotipo de editorial.
 *
 * Emplea el SVG sprite centralizado (<use href="#akb-logo-{matriz}">) sin etiquetas
 * <text> ni <title> para evitar duplicación SEO en Google. Añade aria-hidden="true"
 * y focusable="false" ya que el nombre de la marca siempre acompaña en texto visible.
 *
 * @param string $slug  Slug de la editorial o variante.
 * @param string $class Clases CSS adicionales opcionales.
 * @return string HTML del marcado SVG o elemento img de respaldo si no es matriz.
 */
function akibara_gp_render_publisher_logo( string $slug, string $class = '' ): string {
	$matrix_key = akibara_gp_publisher_matrix_key( $slug );
	$extra_cls  = '' !== $class ? ' ' . esc_attr( trim( $class ) ) : '';

	if ( '' !== $matrix_key ) {
		return sprintf(
			'<svg class="akb-publisher-logo akb-publisher-logo--%s%s" aria-hidden="true" focusable="false"><use href="#akb-logo-%s"></use></svg>',
			esc_attr( $matrix_key ),
			$extra_cls,
			esc_attr( $matrix_key )
		);
	}

	$logo = akibara_gp_brand_logo( $slug );
	if ( '' !== $logo['url'] ) {
		return sprintf(
			'<img src="%s" alt="" width="%d" height="%d" class="akb-publisher-logo-fallback%s" aria-hidden="true" decoding="async">',
			esc_url( $logo['url'] ),
			(int) $logo['w'],
			(int) $logo['h'],
			$extra_cls
		);
	}

	return '';
}

function akibara_gp_brand_logo( string $slug, int $thumbnail_id = 0 ): array {
	if ( '' !== $slug ) {
		$logo_rel = 'assets/img/editoriales/' . $slug . '.svg';
		if ( is_readable( get_stylesheet_directory() . '/' . $logo_rel ) ) {
			return array(
				'url' => get_stylesheet_directory_uri() . '/' . $logo_rel,
				'w'   => 200,
				'h'   => 100,
			);
		}

		$matrix_key = akibara_gp_publisher_matrix_key( $slug );
		if ( '' !== $matrix_key ) {
			$matrix_rel = 'assets/images/publishers/' . $matrix_key . '.svg';
			if ( is_readable( get_stylesheet_directory() . '/' . $matrix_rel ) ) {
				return array(
					'url' => get_stylesheet_directory_uri() . '/' . $matrix_rel,
					'w'   => 200,
					'h'   => 100,
				);
			}
		}
	}

	if ( $thumbnail_id > 0 ) {
		$src = wp_get_attachment_image_src( $thumbnail_id, 'full' );
		if ( is_array( $src ) && ! empty( $src[0] ) ) {
			return array(
				'url' => (string) $src[0],
				'w'   => (int) ( $src[1] ?? 0 ) > 0 ? (int) $src[1] : 200,
				'h'   => (int) ( $src[2] ?? 0 ) > 0 ? (int) $src[2] : 100,
			);
		}
	}

	return array(
		'url' => '',
		'w'   => 0,
		'h'   => 0,
	);
}

// Suprimir el H1/título default de WC en estos archives (el hero aporta el H1 único).
add_filter(
	'woocommerce_show_page_title',
	static function ( $show ) {
		return akibara_gp_is_brand_or_series_archive() ? false : $show;
	}
);

/**
 * Cabecera de las landings de editorial y de serie: logo, título y el primer
 * párrafo de la descripción del término.
 */
function akibara_gp_render_archive_hero(): void {
	if ( ! akibara_gp_is_brand_or_series_archive() ) {
		return;
	}

	// Aquí vivían dos `remove_action` contra `woocommerce_after_main_content` que
	// no quitaban nada: WooCommerce registra ambas funciones en
	// `woocommerce_archive_description` (wc-template-hooks.php:69-70), y de las dos
	// sólo `woocommerce_product_archive_description` llega a after_main_content —
	// la mueve SeoEnhancements, y su cuerpo sale antes si no es
	// `is_post_type_archive('product')`, o sea nunca en editorial ni en serie.
	// La descripción del término la reubica el plugin con un callback propio,
	// acotado al archive de Manga. El hero sigue siendo la única fuente acá.
	$term = get_queried_object();
	if ( ! $term instanceof WP_Term ) {
		return;
	}
	$is_brand = ( 'product_brand' === $term->taxonomy );

	// Las descripciones de series y editoriales se presentan íntegras en el
	// hero (arriba de los productos). Para no empujar el catálogo en móviles,
	// se utiliza clamping progresivo accesible con botón «Leer más» (akibara-archive-intro).
	// Los enlaces y el formato enriquecido (strong, em, p, br) se conservan de forma segura.
	$desc           = trim( (string) $term->description );
	$series_desc_id = 'akb-series-intro-' . $term->term_id;
	$allowed_html   = array(
		'a'      => array(
			'href'  => true,
			'title' => true,
		),
		'strong' => array(),
		'em'     => array(),
		'b'      => array(),
		'i'      => array(),
		'p'      => array(),
		'br'     => array(),
	);

	// Logo solo para editoriales: SVG local por slug (preferido), o thumbnail_id.
	$logo = $is_brand
		? akibara_gp_brand_logo( $term->slug, (int) get_term_meta( $term->term_id, 'thumbnail_id', true ) )
		: array(
			'url' => '',
			'w'   => 0,
			'h'   => 0,
		);
	?>
	<header class="akb-archive-hero <?php echo esc_attr( $is_brand ? 'akb-archive-hero--brand' : 'akb-archive-hero--series' ); ?>">
		<?php if ( '' !== $logo['url'] ) : ?>
			<div class="akb-archive-hero__logo">
				<img src="<?php echo esc_url( $logo['url'] ); ?>" alt="<?php echo esc_attr( 'Logo de ' . $term->name ); ?>" width="<?php echo (int) $logo['w']; ?>" height="<?php echo (int) $logo['h']; ?>" loading="eager" decoding="async">
			</div>
		<?php endif; ?>
		<div class="akb-archive-hero__body">
			<p class="akb-archive-hero__kicker"><?php echo esc_html( $is_brand ? 'Editorial' : 'Serie' ); ?></p>
			<h1 class="akb-archive-hero__title"><?php echo esc_html( $term->name ); ?></h1>
			<?php
			// El plugin Business conecta aquí ediciones físicas de una misma obra.
			// El hook vive en el hero porque el cambio de edición es navegación de
			// identidad, no un filtro de stock de la grilla.
			if ( ! $is_brand ) {
				do_action( 'akibara_gp_series_hero_after_title', $term );
			}
			?>
			<?php if ( '' !== $desc ) : ?>
				<div
					class="akb-archive-hero__desc<?php echo ! $is_brand ? ' akb-archive-intro__content akb-archive-intro__content--series is-collapsed' : ''; ?>"
					<?php if ( ! $is_brand ) : ?>
						id="<?php echo esc_attr( $series_desc_id ); ?>"
						data-akb-archive-intro
					<?php endif; ?>
				><?php echo wp_kses_post( wpautop( wp_kses( $desc, $allowed_html ) ) ); ?></div>
				<?php if ( ! $is_brand ) : ?>
					<button
						type="button"
						class="akb-archive-hero__desc-toggle akb-archive-intro__toggle"
						data-akb-archive-intro-toggle
						data-label-expand="<?php esc_attr_e( 'Leer más', 'akibara-gp' ); ?>"
						data-label-collapse="<?php esc_attr_e( 'Leer menos', 'akibara-gp' ); ?>"
						aria-expanded="false"
						aria-controls="<?php echo esc_attr( $series_desc_id ); ?>"
						hidden
					><?php esc_html_e( 'Leer más', 'akibara-gp' ); ?></button>
					<noscript><style>.akb-archive-intro__content.is-collapsed{display:block;-webkit-line-clamp:unset;line-clamp:unset;overflow:visible}</style></noscript>
				<?php endif; ?>
			<?php endif; ?>
			<?php
			/*
			 * Aquí se imprimía "%s títulos" con `$term->count`. Se retiró: ese
			 * número es el MISMO conteo de productos que la barra de resultados
			 * publica justo debajo, pero con otra palabra. `$term->count` cuenta
			 * productos; por eso la unidad pública debe seguir siendo «producto»
			 * incluso cuando el archivo esté acotado a Manga. Antes la editorial
			 * decía "855 títulos" y dos líneas más abajo se leía "855 productos".
			 *
			 * Peor que la redundancia: el de la ficha es estático. Al marcar una
			 * faceta la barra baja a "492 productos" y la ficha seguía anunciando
			 * 855, que a esa altura ya no describe nada de lo que se ve. Un solo
			 * número, en la barra, que siempre dice la verdad de la lista.
			 */
			?>
		</div>
	</header>
	<?php
}
add_action( 'woocommerce_before_main_content', 'akibara_gp_render_archive_hero', 25 );

/**
 * Obsolescencia controlada: anteriormente se imprimía al pie del archive el
 * resto de la descripción del término. Ahora toda la descripción vive
 * íntegramente en el hero arriba de los productos, por lo que este hook
 * queda retirado para evitar duplicaciones o fragmentación.
 */
function akibara_gp_render_archive_desc_rest(): void {
	// No-op: la descripción completa se presenta arriba de los productos en el hero.
}
// Retirado por mejores prácticas UX y SEO (2026-10): el contenido vive siempre arriba de los productos.
// add_action( 'woocommerce_after_main_content', 'akibara_gp_render_archive_desc_rest', 5 );

/**
 * «Lo más vendido» dentro del aviso de «sin resultados» del buscador (UM-8 =
 * UC-1): una búsqueda que da cero dejaba al cliente sin una sola tarjeta ni
 * salida comercial, porque akibara-search sirve su propio aviso y quita el de
 * WooCommerce (y con él `woocommerce/loop/no-products-found.php`, que sí la
 * traía).
 *
 * El buscador pone el sitio y el tema lo pinta: `akibara_search_empty_after`
 * corre DENTRO de `[data-akb-empty]`, así que la venta cruzada entra y sale con
 * el aviso en el PJAX (0→N la retira, N→0 la trae) y nunca queda una copia
 * huérfana. Su `ul.products` no es la rejilla del catálogo: akb-search.js la
 * distingue (`mainGrid()`). Sus estilos van en loop.css, que está también en
 * los listados cargados con resultados (inc/enqueue.php,
 * `akibara_gp_consulta_principal_vacia()`).
 *
 * Sólo cuando lo que el cliente ESCRIBIÓ no encuentra nada (`busqueda`). Si el
 * cero lo causan los filtros marcados (`filtros`: sin texto, o con un texto que
 * sí encuentra productos), la salida es quitar un chip, y repetir los más
 * vendidos en cada rejilla vaciada por un filtro sería ruido (riesgo anotado en
 * UM-8). La landing de una serie sin tomos (`serie`) queda como hoy, con su
 * enlace al directorio: ponerle venta cruzada es decisión del dueño.
 *
 * Si se pinta lo decide el dueño en Akibara → Recomendaciones (casilla
 * «Mostrar "Lo más vendido" cuando una búsqueda no encuentra nada», encendida
 * de fábrica): akibara-business responde al filtro
 * `akibara_show_popular_in_empty_search` con ese ajuste. El tema sólo pregunta.
 * Sin el plugin la respuesta es la de fábrica, y la fila tampoco sale porque
 * no hay más vendidos que pintar.
 *
 * @param string $q      Lo que buscó el cliente (sin uso: la venta cruzada es la misma).
 * @param string $motivo `busqueda`, `filtros` o `serie`.
 */
function akibara_gp_search_empty_cross_sell( $q, $motivo ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- firma del hook de akibara-search.
	if ( 'busqueda' !== $motivo || ! apply_filters( 'akibara_show_popular_in_empty_search', true ) ) {
		return;
	}

	get_template_part(
		'template-parts/empty/cross-sell',
		null,
		array(
			'heading_id'   => 'akb-search-empty-popular-title',
			'heading_tag'  => 'h2', // Las tarjetas de debajo también son h2 en un archivo, como en no-products-found.php.
			'heading_text' => akibara_gp_empty_popular_title(),
		)
	);
}
add_action( 'akibara_search_empty_after', 'akibara_gp_search_empty_cross_sell', 10, 2 );

/**
 * Atributos ARIA de accesibilidad para los disparadores del carrito.
 *
 * Configura los atributos modales para NativeCart únicamente cuando el
 * cajón nativo se encuentra activo y la página actual no es `/carrito/` ni `/checkout/`.
 * En dichas páginas el disparador opera como enlace de navegación estándar.
 *
 * Función compartida entre el icono del header y la pestaña del bottom-nav.
 *
 * @return string Atributo popup si corresponde, o cadena vacía.
 */
function akibara_gp_cart_trigger_aria(): string {
	$en_checkout = function_exists( 'is_checkout' ) && is_checkout();
	$en_carrito  = function_exists( 'is_cart' ) && is_cart();

	if ( $en_checkout || $en_carrito ) {
		return '';
	}

	// Guarda de seguridad: previene fatals si el módulo de carrito no está disponible.
	if ( ! class_exists( '\\Akibara\\Business\\Modules\\NativeCart\\Settings' ) ) {
		return '';
	}
	$carrito_nativo_activo = 'yes' === get_option( \Akibara\Business\Modules\NativeCart\Settings::OPTION_ENABLED, 'yes' );
	if ( ! $carrito_nativo_activo ) {
		return '';
	}

	return ' aria-haspopup="dialog"';
}

/**
 * Renderiza el `<a class="header-icon header-icon--cart">` con el count del cart
 * actual en `data-akb-cart-count`. Aislado en función propia para que el
 * filter `woocommerce_add_to_cart_fragments` (abajo) reuse el mismo render —
 * single source of truth entre server-render y refresh AJAX.
 */
function akibara_gp_render_cart_icon(): string {
	$cart_url = \Akibara\Business\Services\NavigationLinks::cart_url();
	$count    = ( function_exists( 'WC' ) && WC()->cart ) ? (int) WC()->cart->get_cart_contents_count() : 0;

	ob_start();
	?>
	<a href="<?php echo esc_url( $cart_url ); ?>" class="header-icon header-icon--cart akb-cart-trigger" aria-label="Carrito"<?php echo akibara_gp_cart_trigger_aria(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- akibara_gp_cart_trigger_aria() sólo devuelve una de dos cadenas literales fijas (o vacío). No interpola nada del usuario. ?> data-akb-cart-count="<?php echo esc_attr( (string) $count ); ?>">
		<?php echo akibara_gp_icon( 'cart', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- akibara_gp_icon() devuelve un SVG de la lista blanca de nombres de inc/icons.php; el tamaño entra como %d en sprintf. No interpola nada del usuario. ?>
	</a>
	<?php
	return (string) ob_get_clean();
}

/**
 * Registra el icono del cart como WC fragment.
 *
 * WC dispara `added_to_cart` y `removed_from_cart` en document.body después
 * de cada operación AJAX. El segundo argumento del evento es el array de
 * fragments (selector → HTML) que `wc-add-to-cart.js` aplica via
 * `replaceWith`. Devolviendo nuestro `<a>` con el count actualizado bajo
 * la key `a.header-icon--cart` logramos badge en tiempo real sin recarga
 * y sin mantener un endpoint REST custom.
 *
 * Tradeoff conocido: cada add/remove reemplaza el `<a>` entero, perdiendo
 * focus si el user lo tenía focado (caso edge — el flow normal es click
 * "agregar al carrito" sobre la card del producto, no sobre el icono).
 *
 * @param array<string, string> $fragments Fragmentos que WooCommerce reemplaza tras cada cambio del carrito.
 * @return array<string, string>
 */
function akibara_gp_cart_icon_fragment( array $fragments ): array {
	$fragments['a.header-icon--cart'] = akibara_gp_render_cart_icon();
	return $fragments;
}
add_filter( 'woocommerce_add_to_cart_fragments', 'akibara_gp_cart_icon_fragment' );

/**
 * Reduce items de paginación WC.
 *
 * Default WC: mid_size=2, end_size=1 → en página 2 de 56 outputtea 11 items
 * (`[←][1][2][3][4][5][…][54][55][56][→]`) que en mobile 390px wrappean a
 * 2 filas. Reducimos a mid_size=1, end_size=1 → en página 2 outputtea 7
 * items (`[←][1][2][3][…][56][→]`), en intermedia ~9, todos caben en 1 fila.
 *
 * Mantiene jump-to-first/last (end_size=1) para que el fan navegando series
 * largas pueda saltar a la última y para que Google crawlee páginas profundas.
 *
 * También lleva la página 1 a la URL base (ver
 * akibara_gp_pagination_pagina_1_en_la_base()).
 *
 * Y da nombre a las flechas y al número actual (QA-ESC-TECLADO-11). La
 * plantilla pasa «←»/«→» a secas, así que el enlace a la página siguiente se
 * llamaba «flecha derecha» o nada. Ahora la flecha es decorativa y el nombre
 * es «Página siguiente»/«Página anterior» (cadenas de WordPress, ya traducidas:
 * no es copy nuevo). El número actual lleva «Página » oculto delante, para que
 * diga lo mismo que los enlaces sin el `aria-label` que WooCommerce le pone a
 * un <span> sin rol (ver akibara_gp_pagination_sin_aria_label_en_la_actual()).
 *
 * @param array<string, mixed> $args Argumentos de `paginate_links()` que arma WooCommerce.
 * @return array<string, mixed>
 */
function akibara_gp_pagination_args( array $args ): array {
	$args['mid_size']           = 1;
	$args['end_size']           = 1;
	$args['prev_text']          = akibara_gp_pagination_flecha( is_rtl() ? '&rarr;' : '&larr;', __( 'Previous page' ) );
	$args['next_text']          = akibara_gp_pagination_flecha( is_rtl() ? '&larr;' : '&rarr;', __( 'Next page' ) );
	$args['before_page_number'] = '<span class="screen-reader-text">' . esc_html( __( 'Page', 'woocommerce' ) ) . ' </span>';
	return akibara_gp_pagination_pagina_1_en_la_base( $args );
}
add_filter( 'woocommerce_pagination_args', 'akibara_gp_pagination_args' );

/**
 * Flecha de la paginación: la que se ve, oculta al lector, más su nombre.
 *
 * @param string $flecha Entidad de la flecha (`&larr;` o `&rarr;`).
 * @param string $nombre Lo que anuncia el lector.
 */
function akibara_gp_pagination_flecha( string $flecha, string $nombre ): string {
	return '<span aria-hidden="true">' . $flecha . '</span><span class="screen-reader-text">' . esc_html( $nombre ) . '</span>';
}

/**
 * Quita el `aria-label` del número de la página ACTUAL.
 *
 * WooCommerce (`wc_add_aria_label_to_pagination_numbers`, prioridad 10) pone
 * `aria-label="Página N"` a todos los números, también al <span> de la página
 * actual. En un <span> sin rol ese atributo está prohibido (axe
 * `aria-prohibited-attr`) y varios lectores lo ignoran. El «Página » ya va
 * dentro del número como texto oculto (akibara_gp_pagination_args()), así que
 * el nombre no se pierde; en los enlaces el atributo es válido y se queda.
 *
 * Sólo en la paginación que pasó por akibara_gp_pagination_args() (la del
 * catálogo): en otra (el blog) el número no lleva ese texto oculto y el
 * atributo, aunque no sea fiable, es lo único que dice «Página».
 *
 * @param string|mixed        $html Salida de `paginate_links()`.
 * @param array<string,mixed> $args Argumentos con que se llamó.
 */
function akibara_gp_pagination_sin_aria_label_en_la_actual( $html, $args = array() ): string {
	if ( ! is_string( $html ) ) {
		return '';
	}
	$antes = is_array( $args ) && isset( $args['before_page_number'] ) ? (string) $args['before_page_number'] : '';
	if ( '' === $html || false === strpos( $antes, 'screen-reader-text' ) || ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
		return $html;
	}
	$p = new WP_HTML_Tag_Processor( $html );
	while ( $p->next_tag( array( 'tag_name' => 'SPAN', 'class_name' => 'current' ) ) ) {
		if ( $p->has_class( 'page-numbers' ) ) {
			$p->remove_attribute( 'aria-label' );
		}
	}
	return $p->get_updated_html();
}
add_filter( 'paginate_links_output', 'akibara_gp_pagination_sin_aria_label_en_la_actual', 11, 2 );

/**
 * La página 1 del catálogo enlaza a la URL canónica base, evitando
 * redirecciones 301 internas intermedias hacia `/page/1/`.
 *
 * Utiliza el marcador `%_%` en `base` y el tramo de página en `format` para que
 * `paginate_links()` aplique el formato solo a partir de la página 2.
 *
 * @param array<string, mixed> $args Argumentos de `paginate_links()`.
 * @return array<string, mixed>
 */
function akibara_gp_pagination_pagina_1_en_la_base( array $args ): array {
	$base = isset( $args['base'] ) && is_string( $args['base'] ) ? $args['base'] : '';
	if ( '' === $base || '' !== (string) ( $args['format'] ?? '' ) || false !== strpos( $base, '%_%' ) ) {
		return $args;
	}

	$rewrite = $GLOBALS['wp_rewrite'] ?? null;
	$tramo   = is_object( $rewrite ) && isset( $rewrite->pagination_base ) && '' !== (string) $rewrite->pagination_base
		? (string) $rewrite->pagination_base
		: 'page';

	if ( ! preg_match( '#/' . preg_quote( $tramo, '#' ) . '/%\#%(/?)#', $base, $m, PREG_OFFSET_CAPTURE ) ) {
		return $args;
	}

	// `/tienda/page/%#%/?x` → base `/tienda/%_%?x` y format `page/%#%/`.
	$barra          = $m[1][0];
	$args['base']   = substr( $base, 0, $m[0][1] ) . ( '/' === $barra ? '/%_%' : '%_%' ) . substr( $base, $m[0][1] + strlen( $m[0][0] ) );
	$args['format'] = ( '/' === $barra ? '' : '/' ) . $tramo . '/%#%' . $barra;

	return $args;
}

/**
 * Sin descripción, el botón «Añadir» de la tarjeta no apunta a ninguna (AI-11).
 *
 * WooCommerce (loop/add-to-cart.php) pone `aria-describedby` y pinta un
 * `<span id="woocommerce_loop_add_to_cart_link_describedby_{ID}">` siempre que
 * el argumento EXISTE, aunque venga vacío, y para un tomo simple viene vacío
 * (`add_to_cart_aria_describedby()` devuelve '' salvo que alguien lo filtre).
 * Resultado medido en el espejo: 32 spans vacíos en la portada, y cuando el
 * mismo tomo sale en dos secciones (en producción, «Llegados al Distrito» y
 * «Distrito Manga»), dos elementos con el mismo id (axe `duplicate-id-aria`).
 * El nombre del botón ya lo da su `aria-label` («Añadir al carrito: “…”»):
 * quitar el argumento vacío no le quita nada a quien usa lector.
 *
 * Con texto (variables, o si un plugin lo filtra) se deja tal cual.
 *
 * @param array<string, mixed> $args Argumentos de woocommerce_template_loop_add_to_cart().
 * @return array<string, mixed>
 */
function akibara_gp_loop_add_to_cart_sin_descripcion_vacia( array $args ): array {
	if ( array_key_exists( 'aria-describedby_text', $args ) && '' === trim( (string) $args['aria-describedby_text'] ) ) {
		unset( $args['aria-describedby_text'] );
	}
	return $args;
}
add_filter( 'woocommerce_loop_add_to_cart_args', 'akibara_gp_loop_add_to_cart_sin_descripcion_vacia', 20 );

/**
 * Rutas limpias para categorías principales del catálogo.
 *
 * El menú público usa URLs cortas (`/comics/`, `/shonen/`, etc.) mientras que
 * WooCommerce conserva la base interna `categoria-producto`. Registramos
 * aliases explícitos para que esas entradas de navegación resuelvan al archive
 * `product_cat` correcto sin depender de redirecciones canónicas ambiguas.
 */
function akibara_gp_add_catalog_alias_rewrites(): void {
	$slugs = array(
		'manga',
		'comics',
		'shonen',
		'seinen',
		'shojo',
		'manhwa',
		'vertigo',
		'independiente',
	);

	$pattern = '(' . implode( '|', array_map( 'preg_quote', $slugs ) ) . ')';

	add_rewrite_rule(
		'^' . $pattern . '/?$',
		'index.php?product_cat=$matches[1]',
		'top'
	);
	add_rewrite_rule(
		'^' . $pattern . '/page/([0-9]+)/?$',
		'index.php?product_cat=$matches[1]&paged=$matches[2]',
		'top'
	);
}
add_action( 'init', 'akibara_gp_add_catalog_alias_rewrites' );

/**
 * Redirige paths legacy/aliases con 404 a sus URLs reales.
 *
 *   - `/cart/`                  → wc_get_cart_url() (slug español WC)
 *   - `/preguntas-frecuentes/`  → /faq/ (página real con post_id 185)
 *
 * Evita 404s desde links externos, browser autocomplete, herramientas que
 * asumen URLs WC en inglés, o slugs SEO-friendly que no son los reales.
 * 301 permanente para SEO. Solo dispara si WP ya devolvió 404 para el
 * path exacto — no intercepta ningún otro flujo.
 */
function akibara_gp_redirect_legacy_aliases(): void {
	if ( ! is_404() ) {
		return;
	}
	$path = isset( $_SERVER['REQUEST_URI'] )
		? trim( strtok( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), '?' ), '/' )
		: '';
	if ( '' === $path ) {
		return;
	}

	$map = array(
		'cart'                     => function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : null,
		'finalizar-compra'         => function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : null,
		'preguntas-frecuentes'     => home_url( '/faq/' ),
		'my-account'               => function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : null,
		'mi-cuenta/puntos-akibara' => function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( 'rewards' ) : null,
	);

	if ( isset( $map[ $path ] ) && $map[ $path ] ) {
		wp_safe_redirect( $map[ $path ], 301 );
		exit;
	}

	if ( str_starts_with( $path, 'finalizar-compra/' ) ) {
		$extra = substr( $path, strlen( 'finalizar-compra/' ) );
		$base  = function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : home_url( '/checkout/' );
		$query = ! empty( $_SERVER['QUERY_STRING'] ) ? '?' . sanitize_text_field( wp_unslash( $_SERVER['QUERY_STRING'] ) ) : '';
		wp_safe_redirect( trailingslashit( $base ) . $extra . $query, 301 );
		exit;
	}

	if ( str_starts_with( $path, 'my-account/' ) ) {
		$endpoint = substr( $path, strlen( 'my-account/' ) );
		$target   = function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( $endpoint ) : null;
		if ( $target ) {
			wp_safe_redirect( $target, 301 );
			exit;
		}
	}
}
add_action( 'template_redirect', 'akibara_gp_redirect_legacy_aliases' );

/**
 * Labels chilenos para campos BACS (transferencia) en emails y thank-you.
 *
 * WC core renderiza Sort code / IBAN / BIC — labels UK/Europa irrelevantes
 * en Chile. Reusamos los slots con labels que el customer chileno entiende.
 * Valores en `woocommerce_bacs_accounts` option (wp-admin > WC > Pagos).
 *
 * @param array<string, array<string, string>> $fields Field map keyed by WC slug.
 * @return array<string, array<string, string>>
 */
function akibara_gp_bacs_account_field_labels( array $fields ): array {
	if ( isset( $fields['sort_code'] ) ) {
		$fields['sort_code']['label'] = 'Tipo de cuenta';
	}
	if ( isset( $fields['iban'] ) ) {
		$fields['iban']['label'] = 'RUT';
	}
	if ( isset( $fields['bic'] ) ) {
		$fields['bic']['label'] = 'Email';
	}
	return $fields;
}
add_filter( 'woocommerce_bacs_account_fields', 'akibara_gp_bacs_account_field_labels' );

/**
 * Steppers −/+ alrededor del input de cantidad (V15).
 *
 * WC renderiza el input numérico pelado (global/quantity-input.php); en touch
 * el único camino es el teclado numérico. Los hooks before/after inyectan
 * botones de 44px; el handler de click vive en main.js (delegado, respeta
 * min/max/step y dispara `change` para que el botón "Actualizar carrito"
 * se habilite igual que con edición manual).
 */
/**
 * ¿Hay algo que elegir en este selector?
 *
 * Cuando `min === max` WooCommerce emite el campo como `type="hidden"`
 * (global/quantity-input.php): ya declaró que la cantidad no se puede cambiar.
 * Cuando el producto cuenta con una única unidad disponible, los botones
 * de ajuste se omiten para evitar controles inactivos y permitir que el
 * botón de compra aproveche el ancho completo del contenedor.
 */
function akibara_gp_quantity_is_adjustable(): bool {
	global $product;
	if ( ! $product instanceof WC_Product ) {
		return true; // Carrito y otros contextos: el stepper sí sirve.
	}

	try {
		if ( $product->is_sold_individually() ) {
			return false;
		}

		$max = $product->get_max_purchase_quantity();
		if ( ! is_numeric( $max ) ) {
			return true;
		}

		$max = (int) $max;

		// WooCommerce devuelve -1 cuando no hay límite de compra (productos
		// a pedido, reservas o sin control de inventario). En estos casos
		// los botones de ajuste de cantidad deben permanecer habilitados.
		if ( $max < 0 ) {
			return true;
		}

		return $max > 1;
	} catch ( \Throwable $throwable ) {
		return true;
	}
}

/**
 * Botón «−» del selector de cantidad, sólo si la cantidad se puede cambiar.
 */
function akibara_gp_qty_minus_button(): void {
	if ( ! akibara_gp_quantity_is_adjustable() ) {
		return;
	}
	echo '<button type="button" class="akb-qty-btn akb-qty-btn--minus" aria-label="Disminuir cantidad">&minus;</button>';
}
add_action( 'woocommerce_before_quantity_input_field', 'akibara_gp_qty_minus_button' );

/**
 * Botón «+» del selector de cantidad, sólo si la cantidad se puede cambiar.
 */
function akibara_gp_qty_plus_button(): void {
	if ( ! akibara_gp_quantity_is_adjustable() ) {
		return;
	}
	echo '<button type="button" class="akb-qty-btn akb-qty-btn--plus" aria-label="Aumentar cantidad">+</button>';
}
add_action( 'woocommerce_after_quantity_input_field', 'akibara_gp_qty_plus_button' );

/**
 * El extracto de la ficha se posiciona tras el formulario de compra (prioridad 36).
 *
 * Mantiene la descripción informativa accesible para SEO y lectores de pantalla
 * sin interponer texto extenso entre el precio y el botón principal de compra.
 */
function akibara_gp_extracto_tras_la_compra(): void {
	remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_excerpt', 20 );
	add_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_excerpt', 36 );
}
akibara_gp_extracto_tras_la_compra();

/*
 * Título de la sección de reseñas de la ficha de producto:
 * Renderiza como texto real accesible el nombre de marca (`akibara_gp_reviews_title()`),
 * garantizando coherencia visual y lectura fiel por tecnologías de asistencia.
 *
 * WooCommerce gestiona el encabezado según existan o no valoraciones:
 *  - Con reseñas: `woocommerce_reviews_title` reemplaza el encabezado completo.
 *  - Sin reseñas: se traduce de forma acotada y efímera durante la carga de la plantilla.
 */

/**
 * Camino «con reseñas»: el título entero pasa a ser el de marca. El número ya lo
 * dice la pestaña («Valoraciones (1)») y, en móvil, la etiqueta del panel.
 *
 * @return string HTML (WooCommerce lo imprime tal cual).
 */
function akibara_gp_reviews_title_with_reviews(): string {
	akibara_gp_reviews_title_disarm();

	return esc_html( akibara_gp_reviews_title() );
}
add_filter( 'woocommerce_reviews_title', 'akibara_gp_reviews_title_with_reviews', 10, 0 );

/**
 * Arma el reemplazo del camino «sin reseñas» sólo cuando WordPress va a cargar
 * la plantilla de reseñas de WooCommerce (el cargador de WooCommerce ya decidió
 * la ruta en la prioridad 10).
 *
 * @param mixed $template Ruta de la plantilla de comentarios.
 * @return mixed La misma ruta, intacta.
 */
function akibara_gp_reviews_title_arm( $template ) {
	if ( is_string( $template ) && 'single-product-reviews.php' === basename( $template ) ) {
		add_filter( 'gettext_woocommerce', 'akibara_gp_reviews_title_without_reviews', 10, 2 );
	}

	return $template;
}
add_filter( 'comments_template', 'akibara_gp_reviews_title_arm', 99 );

/**
 * Camino «sin reseñas»: el primer «Reviews» de WooCommerce tras armar es el del
 * título; se cambia y el filtro se retira en el acto.
 *
 * @param mixed $translation Texto ya traducido («Valoraciones»).
 * @param mixed $text        Literal original de WooCommerce.
 * @return mixed
 */
function akibara_gp_reviews_title_without_reviews( $translation, $text ) {
	if ( 'Reviews' !== $text ) {
		return $translation;
	}
	akibara_gp_reviews_title_disarm();

	// esc_html_e() escapa lo que devolvemos.
	return akibara_gp_reviews_title();
}

/**
 * Retira el gettext de un solo uso (idempotente).
 */
function akibara_gp_reviews_title_disarm(): void {
	remove_filter( 'gettext_woocommerce', 'akibara_gp_reviews_title_without_reviews', 10 );
}
add_action( 'woocommerce_product_after_tabs', 'akibara_gp_reviews_title_disarm' );

/*
 * Nota de arquitectura: los ganchos clásicos de totales y cabecera de checkout
 * no operan en esta tienda, dado que el flujo utiliza WooCommerce Blocks nativo.
 * Las extensiones y pistas de envío se gestionan vía `akibara-cart-checkout-blocks`.
 */

/**
 * ¿El archive actual es una de las DOS puertas top-level del distrito?
 * Devuelve 'manga' | 'comics' | '' (vacío = cualquier otra cosa).
 *
 * Solo matchea el término product_cat exacto (NO ancestros), así que
 * subcategorías (Shonen/Seinen/Vértigo/Manhwa), atributos/sagas y la tienda
 * (is_shop) quedan EXCLUIDAS: "Distrito Manga" sí, "Distrito Shonen" no (un
 * género no es un distrito).
 *
 * Compara el slug del término actual de forma eficiente en memoria.
 * Solo afecta estilos visuales de encabezado (body_class `akb-distrito`)
 * sin alterar etiquetas de navegación ni encabezados H1.
 */
function akibara_gp_distrito_slug(): string {
	if ( ! akibara_gp_wc_activo() ) {
		return '';
	}

	if ( is_shop() || ! is_product_taxonomy() ) {
		return '';
	}
	$term = get_queried_object();
	if ( ! ( $term instanceof WP_Term ) || 'product_cat' !== $term->taxonomy ) {
		return '';
	}

	return in_array( $term->slug, array( 'manga', 'comics' ), true ) ? $term->slug : '';
}

/**
 * Marca los dos archives top-level (manga/comics) con `akb-distrito` para que
 * el CSS aplique la firma de marca (eyebrow "DISTRITO" + barra roja) SOLO ahí,
 * sin pegarle al selector global de títulos de archive.
 */
add_filter(
	'body_class',
	static function ( array $classes ): array {
		if ( '' !== akibara_gp_distrito_slug() ) {
			$classes[] = 'akb-distrito';
		}
		return $classes;
	}
);

/**
 * Etiqueta de impuesto → "IVA" (Chile). WC por defecto muestra "Impuesto" en los
 * totales ("incluye $X Impuesto"); en Chile el término correcto y esperado es IVA.
 * Afecta toda la tienda (carrito/checkout/emails) — es la localización correcta.
 */
add_filter(
	'woocommerce_countries_tax_or_vat',
	static function (): string {
		return 'IVA';
	}
);

/**
 * Renombra la etiqueta de impuesto en los totales de la orden a "IVA" en tiempo
 * de display (carrito/checkout/emails/Mi cuenta). La tasa en BD se llama
 * "Impuesto"; este filtro la muestra como "IVA" sin tocar datos ni la tasa, y
 * aplica también a órdenes ya creadas (la etiqueta vive en el tax item guardado).
 *
 * @param array<int|string, object> $tax_totals
 * @return array<int|string, object>
 */
add_filter(
	'woocommerce_order_get_tax_totals',
	static function ( $tax_totals ) {
		if ( is_array( $tax_totals ) ) {
			foreach ( $tax_totals as $tax ) {
				if ( is_object( $tax ) && isset( $tax->label ) ) {
					$tax->label = 'IVA';
				}
			}
		}
		return $tax_totals;
	}
);

/**
 * Email de reembolso al cliente: heading + subject en voz activa Akibara
 * ("Te reembolsamos tu pedido #X") en vez de la voz pasiva predeterminada.
 *
 * Distingue reembolsos parciales de totales evaluando `$email->partial_refund`
 * para informar con precisión al cliente sobre el alcance de la devolución.
 *
 * @param string          $text  Heading/subject ya con placeholders resueltos.
 * @param \WC_Order|mixed $order Orden (2º arg del filtro WC).
 * @param mixed           $email Objeto del correo (3er arg del filtro WC).
 * @return string
 */
function akibara_gp_refunded_active_voice( $text, $order = null, $email = null ): string {
	if ( ! $order instanceof \WC_Order ) {
		return (string) $text;
	}

	$parcial = is_object( $email ) && ! empty( $email->partial_refund );

	return $parcial
		? 'Te reembolsamos parte de tu pedido #' . $order->get_order_number()
		: 'Te reembolsamos tu pedido #' . $order->get_order_number();
}
add_filter( 'woocommerce_email_heading_customer_refunded_order', 'akibara_gp_refunded_active_voice', 10, 3 );
add_filter( 'woocommerce_email_subject_customer_refunded_order', 'akibara_gp_refunded_active_voice', 10, 3 );

/**
 * Sustituye el pie configurable de WooCommerce por la misma frase pública que
 * ya usa el pie HTML del tema, en todo correo de texto plano.
 *
 * Prioridad 20 — ejecutada tras `WC_Emails::replace_placeholders()` (prioridad 10)
 * para asegurar que la línea informativa pública reemplace de forma canónica
 * cualquier configuración de dirección física u opciones heredadas.
 *
 * @return string
 */
function akibara_gp_email_footer_public_line(): string {
	return 'Tienda 100% online · Sin local abierto al público · Retiro con coordinación previa en San Miguel, Santiago';
}
add_filter( 'woocommerce_email_footer_text', 'akibara_gp_email_footer_public_line', 20 );

/**
 * Corrige la descripción del campo «Footer text» en WooCommerce → Ajustes →
 * Correos electrónicos → General para informar la política de pie público unificado.
 *
 * `woocommerce_email_settings` es el filtro que devuelve
 * `WC_Settings_Emails::get_settings_for_default_section()`: no se toca
 * ningún fichero de WooCommerce, sólo se reescribe el `desc` de ESE campo.
 *
 * @param array<int, array<string, mixed>> $settings Campos de Ajustes → Correos → General.
 * @return array<int, array<string, mixed>>
 */
function akibara_gp_email_footer_desc_truth( array $settings ): array {
	foreach ( $settings as $indice => $campo ) {
		if ( ! isset( $campo['id'] ) || 'woocommerce_email_footer_text' !== $campo['id'] ) {
			continue;
		}

		$settings[ $indice ]['desc']     = __( 'Este campo no se usa: esta tienda reemplaza siempre el pie de TODOS sus correos (HTML y texto plano, de cliente y de administrador) por una línea pública fija, para no publicar la dirección operativa privada. Lo que escribas acá no llega a ningún correo.', 'akibara-gp' );
		$settings[ $indice ]['desc_tip'] = false;
	}

	return $settings;
}
add_filter( 'woocommerce_email_settings', 'akibara_gp_email_footer_desc_truth' );

/* --- Peldaño intermedio del srcset de las portadas -------------------- */

/**
 * Registra tamaños de imagen intermedios optimizados para el catálogo y la marca:
 * - `akb-card-2x` (450px): cubre tarjetas en pantallas móviles DPR 2.
 * - `akibara-logo-footer` (560px): peldaño intermedio para isotipos y densidades altas.
 */
function akibara_gp_register_card_image_size(): void {
	add_image_size( 'akb-card-2x', 450, 0, false );
	add_image_size( 'akibara-logo-footer', 560, 0, false );
}
add_action( 'after_setup_theme', 'akibara_gp_register_card_image_size', 20 );

/**
 * Higiene automática del atributo `alt` en portadas de producto.
 *
 * Si el adjunto conserva el nombre crudo de archivo en `_wp_attachment_image_alt`
 * (ej. `onepiece110 akibara`, `OYA-2-akibara`, `centuria_01-akibara`) o está
 * vacío, reemplaza el atributo `alt` por el título limpio del tomo
 * (`$product->get_name()`), preservando los textos alternativos editoriales
 * curados («Portada de …»).
 *
 * @param string $image   HTML `<img>` de la portada.
 * @param mixed  $product Producto propietario de la portada.
 * @return string
 */
function akibara_gp_normalize_product_cover_alt( string $image, $product ): string {
	if ( '' === $image || ! is_object( $product ) || ! method_exists( $product, 'get_name' ) ) {
		return $image;
	}
	if ( ! preg_match( '/\balt=(["\'])(.*?)\1/i', $image, $m ) ) {
		return $image;
	}
	$alt   = trim( html_entity_decode( $m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	$title = trim( (string) $product->get_name() );
	if ( '' === $title ) {
		return $image;
	}
	$is_raw_filename = '' === $alt
		|| (bool) preg_match( '/[_]|(?:-|\.)akiba(?:ra|ta)\b|\b(?:onepiece|tokyoghoul|oyasumipunpun)\d|\.(?:jpe?g|webp|png|avif)\b/i', $alt )
		|| ( false === strpos( $alt, ' ' ) && false !== strpos( $title, ' ' ) );

	if ( ! $is_raw_filename ) {
		return $image;
	}

	return (string) preg_replace(
		'/\balt=(["\'])(.*?)\1/i',
		'alt="' . esc_attr( $title ) . '"',
		$image,
		1
	);
}
add_filter( 'woocommerce_product_get_image', 'akibara_gp_normalize_product_cover_alt', 20, 2 );
