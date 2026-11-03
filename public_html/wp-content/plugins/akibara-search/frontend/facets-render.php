<?php
/**
 * akb-search-facets.php — render server-first del filtrado propio (fase 6).
 *
 * Contrato: este plugin (akibara-search) EXPONE el view-model vía
 * `akibara_search_facets_view()` e IMPRIME el render server-first. Cero `$wpdb`, cero clases
 * internas del plugin (se corrige la violación de inc/facets.php que leía
 * wp_facetwp_index directo).
 *
 * Crawl-safe: el sidebar es UN `<form method="get">` con checkboxes/radios —
 * los crawlers no expanden inputs, así que el espacio combinatorio de facetas
 * sigue sin enlazar (perfil de crawl idéntico al actual). El pjax (akb-search.js)
 * intercepta el submit para el swap sin recarga; sin JS, el form navega igual.
 *
 * Activación: estas funciones solo actúan si `akibara_search_facets_active()`.
 * Con el flag apagado no imprimen nada y WooCommerce queda intacto.
 *
 * @package Akibara\Search
 */

defined( 'ABSPATH' ) || exit;

/**
 * ¿Está activo el filtrado propio este request?
 *
 * PREDICADO ÚNICO del fichero. Convivían tres funciones para esta misma
 * pregunta —ésta, `akibara_gp_akb_search_serving_ready()` con el cuerpo
 * duplicado, y la puente del plugin— y cualquiera de las tres podía moverse sin
 * las otras. Lo que decide sigue siendo el receipt materializado: hay filtrado
 * si `FacetQueryIntegration::current()` devolvió una integración para ESTE
 * request. El frontend NO consulta la publicación editorial por su cuenta —un
 * candado de la suite lo impide, porque ese atajo se saltaba el receipt.
 *
 * @return bool
 */
function akibara_gp_akb_facets_active(): bool {
	return function_exists( 'akibara_search_facets_active' )
		&& akibara_search_facets_active();
}

/**
 * Determina si corresponde montar las facetas del catálogo de productos.
 * Acepta tienda, taxonomías de producto y búsquedas con post_type=product.
 * La búsqueda genérica de entradas y páginas conserva su interfaz nativa.
 *
 * @return bool
 */
function akibara_gp_akb_is_product_surface(): bool {
	return is_shop() || is_product_taxonomy()
		|| ( is_search() && 'product' === get_query_var( 'post_type' ) );
}

/**
 * Guard idempotente por request: el chrome (count/chips/empty-state) se engancha
 * a DOS hooks —`woocommerce_before_shop_loop` (hay productos) y
 * `woocommerce_no_products_found` (0 resultados)— para estar SIEMPRE presente,
 * también en 0 hits, donde `woocommerce_product_loop()` es false y el primer hook
 * no dispara. Normalmente son mutuamente excluyentes (if/else de WC), pero el
 * modo "subcategorías" puede disparar ambos: este guard garantiza un solo render.
 *
 * @param string $key Identificador del bloque.
 * @return bool True la primera vez en el request, false luego.
 */
function akibara_gp_akb_once( string $key ): bool {
	static $done = array();
	if ( isset( $done[ $key ] ) ) {
		return false;
	}
	$done[ $key ] = true;
	return true;
}

/**
 * Sidebar de filtros (form GET). Hook `dynamic_sidebar_before` (misma posición
 * que la versión FacetWP).
 *
 * @return void
 */
function akibara_gp_akb_render_sidebar(): void {
	if ( ! akibara_gp_akb_facets_active() || ! akibara_gp_akb_is_product_surface() ) {
		return;
	}

	// found_posts = universo REAL del grid (archive ∩ facetas): sin él, en un
	// archive filtrado el total mostraba el count de catálogo completo.
	$found = isset( $GLOBALS['wp_query'] ) && $GLOBALS['wp_query'] instanceof WP_Query
		? (int) $GLOBALS['wp_query']->found_posts
		: null;

	$view = akibara_search_facets_view( $found );
	// El formulario es el destino estable del intercambio PJAX y de los controles
	// de recuperación: limpiar, chips y estado vacío. Se conserva con facetas
	// o con una búsqueda activa, incluso si no hay resultados; sólo se omite
	// cuando no existe ningún criterio que el cliente pueda limpiar.
	if ( null === $view || ( empty( $view['facets'] ) && empty( $view['search'] ) ) ) {
		return;
	}

	$action = akibara_gp_akb_form_action();

	// Contexto del archive (categoria=manga en /categoria-producto/manga/): el
	// JS lo adjunta al REST /count del drawer — el form action ya lo preserva
	// por URL para submits y pjax.
	$ctx      = function_exists( 'akibara_search_facets_context' ) ? akibara_search_facets_context() : null;
	$ctx_attr = null === $ctx
		? ''
		: sprintf( ' data-akb-ctx-facet="%s" data-akb-ctx-value="%s"', esc_attr( '_' . $ctx['facet'] ), esc_attr( $ctx['value'] ) );

	echo '<form id="akb-facets-drawer" class="akb-facets" method="get" action="' . esc_url( $action ) . '" data-akb-facets' . $ctx_attr . ' aria-label="Filtrar productos">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $ctx_attr escapado arriba.

	// Cabecera del drawer mobile: título + botón de cierre. En desktop el CSS
	// la oculta (ahí el sidebar no es un modal). El botón es el primer tabbable
	// del focus-trap y da una salida explícita al teclado/lector (a11y M1).
	echo '<div class="akb-facets-head"><span class="akb-facets-head-title">Filtros</span><button type="button" class="akb-facets-close" data-akb-drawer-close aria-label="Cerrar filtros"><span aria-hidden="true">&times;</span></button></div>';

	// Preservar la búsqueda como hidden (no es una faceta).
	if ( ! empty( $view['search'] ) ) {
		printf( '<input type="hidden" name="_buscar" value="%s">', esc_attr( (string) $view['search'] ) );
	}

	// Y el orden vigente. El contrato es SIMÉTRICO: el form de ordenamiento del
	// tema copia todos los `_*` al suyo (woocommerce/loop/orderby.php), así que
	// éste tiene que devolver el favor. Sin este hidden, tildar una faceta —o
	// quitar un chip— tiraba el `orderby` y el catálogo volvía al orden por
	// defecto sin avisar, con JS y sin JS. Sólo viaja lo que WooCommerce acepta
	// como criterio, para no reflejar basura de la URL en el HTML.
	$orderby = akibara_gp_akb_active_orderby();
	if ( '' !== $orderby ) {
		printf( '<input type="hidden" name="orderby" value="%s">', esc_attr( $orderby ) );
	}

	// En móvil sólo el cuerpo del drawer se desplaza. La cabecera y el pie
	// permanecen en el layout flex con flex-shrink:0; se evita position:fixed
	// dentro de un ancestro transformado. En escritorio el cuerpo es un div neutro.
	echo '<div class="akb-facets-body">';
	foreach ( $view['facets'] as $facet ) {
		akibara_gp_akb_render_facet_group( $facet );
	}
	echo '</div>';

	// Footer del drawer mobile (batch, patrón Baymard): en mobile los cambios
	// NO recargan el grid; se acumulan y este botón aplica todo + cierra el
	// drawer, mostrando el total que resultaría. En desktop está oculto por CSS
	// (ahí el refresh es instantáneo). Sin JS, el <noscript> da el submit real.
	// La palabra va en su propio nodo porque el conteo del drawer la cambia
	// junto con el número (QAM-7: «Ver 1 productos»).
	$apply_total = (int) $view['total'];
	printf(
		'<div class="akb-facets-footer"><button type="submit" class="akb-facets-apply" data-akb-apply>Ver <span data-akb-apply-count>%s</span> <span data-akb-apply-noun>%s</span></button></div>',
		esc_html( number_format_i18n( $apply_total ) ),
		esc_html( akibara_gp_akb_products_noun( $apply_total ) )
	);
	echo '<noscript><style>.akb-facets-footer{display:block}</style></noscript>';
	echo '</form>';
}

/**
 * «producto» o «productos» para el botón «Ver N productos» del drawer.
 *
 * Una sola fuente para la primera carga (este archivo) y para el conteo en
 * vivo (`AKB_FACETS.noun`, que usa `akb-search.js`): si cada lado escribiera
 * la palabra, el botón podría decir una cosa al cargar y otra al filtrar.
 *
 * @param int $total Productos que resultarían.
 * @return string Sin escapar.
 */
function akibara_gp_akb_products_noun( int $total ): string {
	return _n( 'producto', 'productos', $total, 'akibara-search' );
}

/**
 * Un grupo de faceta (section colapsable con sus opciones).
 *
 * @param array<string, mixed> $facet Faceta del view-model.
 * @return void
 */
function akibara_gp_akb_render_facet_group( array $facet ): void {
	$name  = (string) $facet['name'];
	$param = (string) $facet['param'];
	$ui    = (string) $facet['ui'];
	$multi = 'radio' !== $ui;
	$type  = $multi ? 'checkbox' : 'radio';

	$open        = ! empty( $facet['open'] );
	$body_id     = 'akb-facet-body-' . $name;
	$list_id     = $body_id . '-list';
	$status_id   = $body_id . '-status';
	$is_alpha    = 'alpha' === $ui;
	$options     = (array) $facet['options'];
	$total       = count( $options );
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- estado visual GET, sin escritura.
	$expand_name = isset( $_GET['_akb_expand'] ) && is_string( $_GET['_akb_expand'] ) ? sanitize_key( wp_unslash( $_GET['_akb_expand'] ) ) : '';
	$expanded    = ! $is_alpha || 12 >= $total || $expand_name === $name;

	// Autor y Serie pueden superar 100 términos. El primer render conserva las
	// 12 opciones más relevantes y cualquier selección activa; el resto se pide
	// al escribir o pulsar «Ver todas». Sin JS, ese botón hace GET y el servidor
	// entrega la lista completa.
	$visible_options = $options;
	if ( $is_alpha && ! $expanded ) {
		$visible_options = array_slice( $options, 0, 12 );
		$visible_values  = array_fill_keys(
			array_map(
				static fn( array $option ): string => (string) $option['value'],
				$visible_options
			),
			true
		);

		foreach ( $options as $option ) {
			$value = (string) $option['value'];
			if ( ! empty( $option['selected'] ) && ! isset( $visible_values[ $value ] ) ) {
				$visible_options[]          = $option;
				$visible_values[ $value ]   = true;
			}
		}
	}

	// Sección colapsable (evidencia Baymard/NN-g: no abrir todas = muro). El
	// título es un <button> con aria-expanded; el JS persiste el estado.
	printf(
		'<section class="akb-facet akb-facet--%s%s" data-facet="%s"%s>',
		esc_attr( $ui ),
		$open ? '' : ' is-collapsed',
		esc_attr( $name ),
		$is_alpha
			? sprintf(
				' data-akb-facet-total="%s" data-akb-facet-loaded="%s"',
				esc_attr( (string) $total ),
				$expanded ? 'true' : 'false'
			)
			: ''
	);
	printf(
		'<button type="button" id="%s" class="akb-facet-title" aria-expanded="%s" aria-controls="%s" data-akb-facet-toggle>%s<span class="akb-facet-chevron" aria-hidden="true"></span></button>',
		esc_attr( $body_id . '-title' ),
		$open ? 'true' : 'false',
		esc_attr( $body_id ),
		esc_html( (string) $facet['label'] )
	);

	// Agrupa y nombra las opciones de cada faceta para lectores de pantalla
	// mediante role=group/radiogroup y aria-labelledby.
	printf(
		'<div class="akb-facet-body" id="%s" role="%s" aria-labelledby="%s"%s>',
		esc_attr( $body_id ),
		'radio' === $type ? 'radiogroup' : 'group',
		esc_attr( $body_id . '-title' ),
		$open ? '' : ' hidden'
	);

	// Las facetas grandes ofrecen búsqueda local y una lista compacta desplazable.
	// El input type=search conserva el control nativo para borrar la consulta.
	if ( $is_alpha ) {
		$search_label = mb_strtolower( (string) $facet['label'] );
		printf(
			'<input type="search" class="akb-facet-search" data-akb-facet-search placeholder="Buscar %s…" aria-label="Buscar %s" aria-controls="%s" aria-describedby="%s" autocomplete="off" spellcheck="false" disabled>',
			esc_attr( $search_label ),
			esc_attr( $search_label ),
			esc_attr( $list_id ),
			esc_attr( $status_id )
		);
	}

	$list_class = $is_alpha ? 'akb-facet-list akb-facet-list--scroll' : 'akb-facet-list';
	printf( '<ul id="%s" class="%s">', esc_attr( $list_id ), esc_attr( $list_class ) );
	foreach ( $visible_options as $opt ) {
		akibara_gp_akb_render_option( $param, $type, $opt );
	}
	echo '</ul>';

	if ( $is_alpha ) {
		printf(
			'<p id="%s" class="akb-facet-search-status" data-akb-facet-status aria-live="polite" aria-atomic="true">%s</p>',
			esc_attr( $status_id ),
			esc_html(
				$expanded
					? sprintf( '%s opciones disponibles.', number_format_i18n( $total ) )
					: sprintf(
						'Mostrando %1$s destacadas de %2$s.',
						number_format_i18n( count( $visible_options ) ),
						number_format_i18n( $total )
					)
			)
		);

		if ( ! $expanded ) {
			printf(
				'<button type="submit" name="_akb_expand" value="%s" class="akb-facet-expand" data-akb-facet-expand aria-controls="%s">Ver las %s opciones</button>',
				esc_attr( $name ),
				esc_attr( $list_id ),
				esc_html( number_format_i18n( $total ) )
			);
		}
	}

	echo '</div></section>';
}

/**
 * Una opción (checkbox/radio + label + count). Las de count 0 no seleccionadas
 * se marcan disabled (ghost) — el usuario ve que existen pero no suman nada.
 *
 * @param string               $param Nombre del input (`_facet`).
 * @param string               $type  checkbox|radio.
 * @param array<string, mixed> $opt   Opción {value,label,count,selected}.
 * @return void
 */
function akibara_gp_akb_render_option( string $param, string $type, array $opt ): void {
	$count    = (int) $opt['count'];
	$selected = ! empty( $opt['selected'] );
	$disabled = ( 0 === $count && ! $selected );

	printf(
		'<li class="akb-facet-item%s"><label><input type="%s" name="%s[]" value="%s"%s%s> <span class="akb-facet-label">%s</span> <span class="akb-facet-count">%s</span></label></li>',
		$disabled ? ' is-ghost' : '',
		esc_attr( $type ),
		esc_attr( $param ),
		esc_attr( (string) $opt['value'] ),
		checked( $selected, true, false ),
		disabled( $disabled, true, false ),
		esc_html( (string) $opt['label'] ),
		esc_html( number_format_i18n( $count ) )
	);
}

/**
 * Banda de serie sobre los resultados. Hook `woocommerce_before_shop_loop` prio 3.
 *
 * El problema que resuelve
 * -----------------------
 * El catálogo se usa como buscador, no como parrilla: medido sobre grabaciones
 * de sesión (30 días), 272 de 414 vistas de /tienda/ llevan `_buscar` y los
 * términos que se teclean son nombres de serie — vagabond, jujutsu kaisen,
 * dandadan, kagurabachi, hxh. La pregunta es «¿qué es esta serie y cuántos
 * tomos son?» y la respuesta que da el sitio son 24 tomos sueltos ordenados por
 * relevancia, sin decir si la obra está completa ni cuántos faltan.
 *
 * Esa respuesta YA existe en la landing `/serie/<slug>/`, y desde el catálogo
 * había CERO enlaces hacia ella (comprobado también buscando el nombre exacto).
 *
 * Por qué vive aquí y no en akibara-business
 * ------------------------------------------
 * Porque el matcher (`SeriesSuggestions`) vive en este plugin. Ponerla en
 * business obligaría a que un plugin dependa de las clases del otro, y el par
 * Business/Search descoordinado ya provocó un 500 global.
 *
 * Por qué el enlace dice sólo «Ver la serie»
 * -------------------------------------------
 * La primera versión decía «Ver la serie ordenada, del 1 al final». Es una
 * promesa redundante: el selector de orden que vive DOS LÍNEAS más abajo ya
 * ofrece «Título: A-Z», que con tomos numerados («Jujutsu Kaisen 01, 02…») da
 * ese mismo orden. Un enlace que promete lo que el control de al lado ya hace
 * compite con él y no añade motivo para pulsarlo.
 *
 * Lo que la landing SÍ tiene y la parrilla no —los tomos agotados con su
 * número, los packs— no se nombra aquí porque no todas las series lo tienen:
 * copy condicional por serie a cambio de poco.
 *
 * Por qué SIN conteos en vivo
 * ---------------------------
 * «13 disponibles hoy» saldría de `SeriesFacts`, que está en el otro plugin.
 * Y hay una razón mejor: el termmeta de estado es la MISMA fuente que imprime
 * la landing y la ficha de producto, así que las tres superficies no pueden
 * contradecirse. Un número que se desmiente a un click de distancia es peor que
 * ningún número.
 *
 * @return void
 */
function akibara_search_render_series_band(): void {
	if ( ! function_exists( 'is_shop' ) || is_admin() ) {
		return;
	}
	if ( ! akibara_gp_akb_facets_active() || ! akibara_gp_akb_once( 'series_band' ) ) {
		return;
	}

	$term = akibara_search_series_band_query();
	if ( '' === $term ) {
		return;
	}

	$serie = akibara_search_series_band_match( $term );
	if ( null === $serie ) {
		return;
	}

	$meta = akibara_search_series_band_meta( (int) $serie['id'] );

	// UNA sola línea, no tres apiladas. Medido en 375px: la versión apilada
	// ocupaba 115px y empujaba la primera card de 301px a 504px —200px de cromo
	// en la página con más abandono absoluto después de la home—. El texto
	// completo mide 344px, así que cabe en una línea y baja el costo a ~48px sin
	// perder lo que la hacía útil: sigue siendo un bloque propio con el filete
	// amarillo, o sea escaneable de un vistazo. Con nombres largos envuelve a
	// dos líneas, que sigue siendo la mitad que antes.
	printf(
		'<aside class="akb-series-band" aria-label="%s">'
			. '<span class="akb-series-band__name">%s</span>'
			. '%s'
			. '<a class="akb-series-band__link" href="%s">%s</a>'
			. '</aside>',
		esc_attr( sprintf( 'Serie encontrada: %s', $serie['name'] ) ),
		esc_html( $serie['name'] ),
		'' === $meta ? '' : '<span class="akb-series-band__meta">' . esc_html( $meta ) . '</span>',
		esc_url( $serie['url'] ),
		esc_html( 'Ver la serie' )
	);
}

/**
 * Término de búsqueda vigente, o '' si la vista no es una búsqueda.
 *
 * @return string
 */
function akibara_search_series_band_query(): string {
	$view = akibara_search_facets_view();
	if ( is_array( $view ) && isset( $view['search'] ) && is_string( $view['search'] ) ) {
		return trim( $view['search'] );
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lectura de una vista pública.
	$raw = isset( $_GET[ \Akibara\Search\FilterSet::PARAM_SEARCH ] )
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- misma lectura pública que la línea de arriba: el término de búsqueda viaja en la URL que el cliente comparte y no hay formulario que verificar; el valor pasa por wp_unslash() y sanitize_text_field() en la línea siguiente.
		? sanitize_text_field( wp_unslash( (string) $_GET[ \Akibara\Search\FilterSet::PARAM_SEARCH ] ) )
		: '';

	return trim( $raw );
}

/**
 * Primera serie que casa con la consulta, SÓLO en match fuerte.
 *
 * `SeriesSuggestions::suggest()` ordena por tier (0 = nombre idéntico,
 * 1 = empieza por la consulta, 2 = prefijo de palabra interior) pero no lo
 * devuelve. El tier 2 es el que produce ruido —«man» casaría con «Chainsaw
 * Man»— así que se recalcula aquí con la MISMA normalización del plugin y se
 * descarta todo lo que no sea 0 o 1.
 *
 * @param string $query Consulta del usuario.
 * @return array{id:int, name:string, url:string}|null
 */
function akibara_search_series_band_match( string $query ) {
	if ( ! class_exists( \Akibara\Search\SeriesSuggestions::class )
		|| ! class_exists( \Akibara\Search\Normalizer::class ) ) {
		return null;
	}

	$series      = new \Akibara\Search\SeriesSuggestions();
	$suggestions = $series->suggest( $query, 1 );
	if ( array() === $suggestions ) {
		return null;
	}

	$best = $suggestions[0];
	$norm = \Akibara\Search\Normalizer::normalize( $query );
	$name = \Akibara\Search\Normalizer::normalize( (string) ( $best['name'] ?? '' ) );

	if ( '' === $norm || '' === $name ) {
		return null;
	}

	// Sólo match fuerte. Tres formas, todas conservadoras:
	//
	//   a) nombre idéntico a la consulta,
	//   b) el nombre EMPIEZA por la consulta,
	//   c) iguales al colapsar los espacios.
	//
	// (c) existe porque «dandadan» es un término real medido en las grabaciones
	// y la serie se llama «Dan Da Dan»: el buscador SÍ devuelve sus 22 tomos,
	// pero por prefijo no casaba y la banda no salía justo en la consulta que
	// más la necesita. Se compara por IGUALDAD y no por prefijo para no
	// reintroducir ruido: «man» colapsado sigue sin igualar a «chainsawman».
	//
	// Lo que deliberadamente NO se cubre es la errata («demon smayer»): eso es
	// distancia de edición, no colapso, y el buscador ya muestra los resultados
	// correctos. Una banda que acierta la serie equivocada es peor que ninguna.
	$sin_espacios = static fn( string $t ): string => str_replace( ' ', '', $t );

	$exacto = $name === $norm || $sin_espacios( $name ) === $sin_espacios( $norm );
	$prefijo = 0 === strpos( $name, $norm );

	if ( ! $exacto && ! $prefijo ) {
		return null;
	}

	// Un PREFIJO corto no basta para afirmar la serie a ancho completo. Medido:
	// «one» es prefijo legítimo de «one piece» y pintaba su banda sobre 24
	// resultados que no eran todos de esa serie.
	//
	// El umbral se aplica SÓLO al prefijo: un nombre exacto vale por corto que
	// sea («Nana» tiene 4 letras y es la serie, no una coincidencia).
	//
	// Y va aquí y no en `SeriesSuggestions`: en el DESPLEGABLE, ofrecer «One
	// Piece» mientras se teclea «one» es exactamente lo que se espera de un
	// autocompletado. Una banda que AFIRMA la serie es otra cosa.
	if ( ! $exacto && strlen( $norm ) < 5 ) {
		return null;
	}

	return array(
		'id'   => (int) ( $best['id'] ?? 0 ),
		'name' => (string) ( $best['name'] ?? '' ),
		'url'  => (string) ( $best['url'] ?? '' ),
	);
}

/**
 * Línea de contexto: editorial y estado de la edición, desde termmeta.
 *
 * Devuelve '' cuando falta el dato — sólo 67 de las series lo tienen relleno,
 * así que el vacío es el caso normal y la banda se pinta igual, con el nombre
 * y el enlace, que ya es más de lo que había.
 *
 * @param int $term_id Término de `pa_serie`.
 * @return string
 */
function akibara_search_series_band_meta( int $term_id ): string {
	if ( $term_id < 1 ) {
		return '';
	}

	$partes = array();

	$estado = (string) get_term_meta( $term_id, '_akb_serie_estado_edicion', true );
	$tomos  = (int) get_term_meta( $term_id, '_akb_serie_tomos_edicion', true );

	if ( 'completa' === $estado && $tomos > 0 ) {
		$partes[] = sprintf( 'Completa en %d %s', $tomos, 1 === $tomos ? 'tomo' : 'tomos' );
	} elseif ( 'en_publicacion' === $estado ) {
		$partes[] = $tomos > 0
			? sprintf( 'En publicación · %d %s previstos', $tomos, 1 === $tomos ? 'tomo' : 'tomos' )
			: 'En publicación';
	} elseif ( 'al_dia' === $estado ) {
		$partes[] = 'Al día con la obra';
	}

	return implode( ' · ', $partes );
}

/**
 * Chips de filtros aplicados. Hook `woocommerce_before_shop_loop` prio 36.
 * Cada chip es un `<button>` (no `<a>`) que el JS usa para des-seleccionar;
 * sin JS el usuario destilda en el sidebar.
 *
 * @return void
 */
function akibara_gp_akb_render_chips(): void {
	if ( ! akibara_gp_akb_facets_active() || ! akibara_gp_akb_once( 'chips' ) ) {
		return;
	}
	$view = akibara_search_facets_view();
	if ( null === $view ) {
		return;
	}

	// El contenedor de chips existe aun vacío para proporcionar un destino
	// estable al intercambio PJAX cuando aparece el primer filtro.
	$chips = $view['chips'];
	// Región con nombre solo cuando tiene contenido visible: `[hidden]` la saca
	// del árbol accesible cuando no hay filtros y `role="region"` permite nombrar
	// correctamente este conjunto dinámico de controles.
	echo '<div class="akb-facet-chips" data-akb-chips role="region" aria-label="Filtros aplicados"' . ( empty( $chips ) ? ' hidden' : '' ) . '>';
	foreach ( $chips as $chip ) {
		printf(
			'<button type="button" class="akb-chip" data-facet="%s" data-value="%s">%s: %s <span aria-hidden="true">×</span><span class="screen-reader-text">Quitar filtro</span></button>',
			esc_attr( (string) $chip['facet'] ),
			esc_attr( (string) $chip['value'] ),
			esc_html( (string) $chip['facet_label'] ),
			esc_html( (string) $chip['label'] )
		);
	}
	if ( ! empty( $chips ) ) {
		echo '<button type="button" class="akb-chip akb-chip--clear" data-akb-clear>Limpiar todo</button>';
	}
	echo '</div>';
}

/**
 * Estado vacío (0 resultados). Hook `woocommerce_before_shop_loop` prio 37.
 * Momento de retención (skill search-ux): confirma la búsqueda + sugiere salida.
 *
 * @return void
 */
function akibara_gp_akb_render_empty_state(): void {
	if ( ! akibara_gp_akb_facets_active() || ! akibara_gp_akb_once( 'empty' ) ) {
		return;
	}
	// Mismo found_posts que el contador (prio 35): sin el override, view()
	// cuenta last_result — que filtra SIN el contexto del archive — y en un
	// archive con 0 resultados reales el empty-state se creía con resultados
	// y quedaba hidden mientras el contador decía "0 productos" [P2 opositor
	// bloque 1: /categoria-producto/shonen/?_categoria=seinen].
	$found = isset( $GLOBALS['wp_query'] ) && $GLOBALS['wp_query'] instanceof WP_Query
		? (int) $GLOBALS['wp_query']->found_posts
		: null;
	$view  = akibara_search_facets_view( $found );
	if ( null === $view ) {
		return;
	}

	akibara_gp_akb_print_empty_state( $view );
}

/**
 * Imprime el nodo `[data-akb-empty]` para una vista ya resuelta.
 *
 * Separado del render para poder probarlo sin el guard de una-vez-por-request.
 *
 * TODO lo que acompaña al aviso vive DENTRO del nodo, incluido lo que el tema
 * pinte en `akibara_search_empty_after`: el PJAX reemplaza el nodo entero en
 * cada búsqueda (`.akb-empty-state` está en su lista SWAP) y, con resultados,
 * el servidor lo devuelve vacío y `hidden`. Así, al volver a haber resultados
 * la venta cruzada se va con el aviso, y nunca queda una copia huérfana.
 *
 * @param array<string, mixed> $view View-model de facetas.
 * @return void
 */
function akibara_gp_akb_print_empty_state( array $view ): void {
	$has_results = (int) ( $view['total'] ?? 0 ) > 0;
	$q           = (string) ( $view['search'] ?? '' );

	echo '<div class="akb-empty-state" data-akb-empty' . ( $has_results ? ' hidden' : '' ) . '>';
	if ( $has_results ) {
		echo '</div>';
		return;
	}

	// `has_filters` es «el cliente marcó algo» (chips o búsqueda); el
	// contexto implícito del archive (la propia serie) no cuenta.
	$serie_vacia = '' === $q && empty( $view['has_filters'] ) && function_exists( 'is_tax' ) && is_tax( 'pa_serie' );
	// Un texto que SÍ encuentra productos donde está el cliente —en un archivo,
	// dentro del archivo— y un filtro marcado encima: el cero lo causa el
	// filtro. «Sin resultados para «frieren».» con Frieren en «Quizá buscabas»
	// sugería una errata que no existe; la salida es quitar el filtro
	// (QA-SRCH-4). `search_total` ya viene contado así (REV-FS1-1), y en 0 en
	// un archivo sin faceta dueña, donde no se sabe (REV-FS-1-1).
	$filtros_vaciaron = '' !== $q && (int) ( $view['search_total'] ?? 0 ) > 0
		&& array() !== array_filter(
			(array) ( $view['chips'] ?? array() ),
			static fn( $chip ): bool => 'buscar' !== ( $chip['facet'] ?? '' )
		);
	if ( '' !== $q && ! $filtros_vaciaron ) {
		$motivo = 'busqueda';
		printf( '<p class="akb-empty-title">Sin resultados para «%s».</p>', esc_html( $q ) );
	} elseif ( $serie_vacia ) {
		// Una serie sin un solo tomo (13 en producción: Berserk, Bleach,
		// Attack On Titan… cuyos tomos cuelgan de otros términos) decía
		// «ningún producto coincide con estos filtros» sin que el cliente
		// hubiera puesto ninguno (R8-serie-vacia). Se dice la verdad y se
		// ofrece el directorio; la landing sigue en noindex.
		$motivo  = 'serie';
		$termino = get_queried_object();
		$hub     = class_exists( '\Akibara\Business\Services\SeriesHubUrl' ) ? \Akibara\Business\Services\SeriesHubUrl::url() : home_url( '/series/' );
		printf(
			'<p class="akb-empty-title">Hoy no tenemos tomos de «%s».</p><p class="akb-empty-hint"><a href="%s">Ver todas las series</a></p>',
			esc_html( is_object( $termino ) && isset( $termino->name ) ? (string) $termino->name : 'esta serie' ),
			esc_url( $hub )
		);
	} else {
		$motivo = 'filtros';
		echo '<p class="akb-empty-title">Ningún producto coincide con estos filtros.</p>';
	}
	if ( 'serie' !== $motivo ) {
		// La pista se edita en Buscador → Ajustes → «Mensajes al cliente». El
		// HTML lo arma `EmptyStateCopy`, que escapa cada tramo y sólo convierte
		// en botón lo que el dueño puso entre corchetes: así el texto es libre y
		// el `data-akb-clear` que el JS necesita no depende de que alguien
		// escriba una etiqueta a mano.
		//
		// Cuando lo escrito sí encuentra productos y son los filtros los que
		// vacían la página, la pista es otra y su botón quita los filtros y
		// CONSERVA la búsqueda: con la de siempre, el «limpia todo» se llevaba
		// también «frieren» y el cliente acababa en la tienda entera (F-S-1).
		$pista = $filtros_vaciaron ? \Akibara\Search\EmptyStateCopy::filters_html() : \Akibara\Search\EmptyStateCopy::catalog_html();
		echo '<p class="akb-empty-hint">' . $pista . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- filters_html() y catalog_html() escapan cada tramo.
	}
	if ( 'busqueda' === $motivo ) {
		echo akibara_gp_akb_empty_suggestions_html( $q ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapado dentro.
	}

	/**
	 * Dentro del aviso de «sin resultados», al final: el sitio de la salida
	 * comercial que pinta el tema (la venta cruzada), sin que el buscador
	 * dependa de sus plantillas.
	 *
	 * Lo que se imprima aquí queda DENTRO de `[data-akb-empty]` y entra y sale
	 * con él en el PJAX. Una rejilla `ul.products` aquí dentro no es la del
	 * catálogo: `akb-search.js` la distingue (ver `mainGrid()`).
	 *
	 * @param string $q      Lo que buscó el cliente; vacío si no escribió nada o es la serie.
	 * @param string $motivo `busqueda` (el texto no encuentra nada, con o sin
	 *                       filtros), `filtros` (lo vaciaron los filtros
	 *                       marcados: sin texto, o con un texto que sí encuentra
	 *                       productos, y entonces la pista conserva la búsqueda)
	 *                       o `serie` (landing de una serie sin tomos).
	 *
	 * @since 0.1.75
	 */
	do_action( 'akibara_search_empty_after', $q, $motivo );

	echo '</div>';
}

/**
 * La salida que el motor propone en una búsqueda sin resultados: la serie que
 * más se parece a lo escrito y lo más buscado. Vacío si no hay nada que
 * ofrecer, si el dueño lo apagó o si la lectura falla.
 *
 * Lo más buscado apunta a la tienda entera y no al archivo actual: son
 * búsquedas del catálogo completo, y dentro de /comics/ «jujutsu kaisen» daría
 * otro cero. Llevan `nofollow` porque son páginas de búsqueda (noindex).
 *
 * @param string $q Lo que buscó el cliente.
 * @return string HTML ya escapado.
 */
function akibara_gp_akb_empty_suggestions_html( string $q ): string {
	if ( ! function_exists( 'akibara_search_empty_suggestions' ) ) {
		return '';
	}
	$sugerencias = akibara_search_empty_suggestions( $q );
	$series      = is_array( $sugerencias['series'] ?? null ) ? $sugerencias['series'] : array();
	$populares   = is_array( $sugerencias['popular'] ?? null ) ? $sugerencias['popular'] : array();
	$tienda      = function_exists( 'wc_get_page_permalink' ) ? (string) wc_get_page_permalink( 'shop' ) : '';
	if ( '' === $tienda ) {
		$populares = array();
	}

	$grupos = '';
	if ( array() !== $series ) {
		$items = '';
		foreach ( $series as $serie ) {
			// La coma oculta separa nombre y volumen para el lector de pantalla:
			// en pantalla los separa el `gap` del flex, que no se lee.
			$meta   = '' !== (string) ( $serie['meta'] ?? '' )
				? '<span class="screen-reader-text">, </span><span class="akb-empty-suggest__meta">' . esc_html( (string) $serie['meta'] ) . '</span>'
				: '';
			$items .= sprintf(
				'<li><a class="akb-empty-suggest__item akb-empty-suggest__item--series" href="%s">%s%s</a></li>',
				esc_url( (string) $serie['url'] ),
				esc_html( (string) $serie['name'] ),
				$meta
			);
		}
		$grupos .= '<div class="akb-empty-suggest__group"><p class="akb-empty-suggest__label" id="akb-empty-suggest-series">'
			. esc_html( \Akibara\Search\EmptyStateCopy::series_label() )
			. '</p><ul class="akb-empty-suggest__list" aria-labelledby="akb-empty-suggest-series">' . $items . '</ul></div>';
	}
	if ( array() !== $populares ) {
		$items = '';
		foreach ( $populares as $popular ) {
			$items .= sprintf(
				'<li><a class="akb-empty-suggest__item" href="%s" rel="nofollow">%s</a></li>',
				esc_url( add_query_arg( '_buscar', rawurlencode( (string) $popular ), $tienda ) ),
				esc_html( (string) $popular )
			);
		}
		$grupos .= '<div class="akb-empty-suggest__group"><p class="akb-empty-suggest__label" id="akb-empty-suggest-popular">'
			. esc_html( \Akibara\Search\EmptyStateCopy::popular_label() )
			. '</p><ul class="akb-empty-suggest__list" aria-labelledby="akb-empty-suggest-popular">' . $items . '</ul></div>';
	}

	return '' === $grupos ? '' : '<div class="akb-empty-suggest">' . $grupos . '</div>';
}

/**
 * Aviso de un filtro que no llega a cargar por la conexión (REV-C-S-1o-1 y
 * 1o-2): lo pone `akb-search.js` cuando un PJAX falla por la red y nadie se fue
 * de la página (ver avisarFalloDeRed()). Se pinta en el servidor y no en el
 * bundle para que el texto sea el del panel, escapado aquí como las pistas de
 * «sin resultados», y va en la barra de resultados —que el PJAX reemplaza—
 * junto a los otros dos avisos del buscador.
 *
 * En un `<template>` y no en un nodo oculto: su contenido no es texto de la
 * página. Un «no pudimos cargar los productos» en cada listado lo leerían los
 * buscadores y las IA que no miran `hidden` (la tienda quiere ser la fuente
 * de referencia del manga, y un aviso de error no es contenido).
 *
 * Dentro de un `.woocommerce-notices-wrapper`, el contenedor de avisos de la
 * tienda (REV-C-S-2o-1): el tema le da su propia línea al principio de la
 * barra a cualquier ancho y, desde 1025 px, vuelve a apilar la cabecera
 * cuando la barra trae un aviso. Suelto en la barra quedaba en la columna
 * derecha, al lado del H1, y el título bajaba 27 px. Es un contenedor propio y
 * no el que imprime WooCommerce: ése va más abajo en la barra (el orden de
 * lectura y de foco quedaría detrás del total y de «Filtrar») y en cero
 * resultados ni se imprime.
 *
 * @return string HTML escapado.
 */
function akibara_gp_akb_network_notice(): string {
	return '<template data-akb-aviso-red><div class="woocommerce-notices-wrapper"><p class="akb-aviso-red">' . \Akibara\Search\EmptyStateCopy::network_html() . '</p></div></template>';
}

/**
 * Aviso de corrección + contador accesible. En resultados se imprimen antes de
 * los controles para que el orden del DOM coincida con la lectura móvil:
 * corrección → total → Filtrar/Ordenar.
 *
 * @return void
 */
function akibara_gp_akb_render_result_count(): void {
	if ( ! akibara_gp_akb_facets_active() || ! akibara_gp_akb_once( 'count' ) ) {
		return;
	}
	$found = isset( $GLOBALS['wp_query'] ) && $GLOBALS['wp_query'] instanceof WP_Query
		? (int) $GLOBALS['wp_query']->found_posts
		: null;
	$view  = akibara_search_facets_view( $found );
	if ( null === $view ) {
		return;
	}

	// Cuando la búsqueda corrige un término, muestra la consulta corregida
	// para explicar al cliente por qué se sirven esos resultados.
	$correction = akibara_search_correction();
	if ( null !== $correction ) {
		printf(
			'<p class="akb-search-correction" data-akb-correction>%s</p>',
			wp_kses(
				sprintf(
					/* translators: 1: término tipeado por el usuario, 2: término corregido. */
					__( 'Sin resultados para <strong>«%1$s»</strong>. Mostrando lo más parecido a <strong>«%2$s»</strong>.', 'akibara-search' ),
					esc_html( $correction['typo'] ),
					esc_html( $correction['fix'] )
				),
				array( 'strong' => array() )
			)
		);
	} else {
		// Target estable del swap PJAX: al limpiar una búsqueda corregida el
		// nodo hidden reemplaza el aviso anterior, en vez de dejarlo obsoleto.
		echo '<p class="akb-search-correction" data-akb-correction hidden></p>';
	}

	// Editorial soltada: la búsqueda pedía una editorial que no tiene esa serie
	// y se mostró lo que sí hay. Sin decirlo, el cliente ve tomos de otra
	// editorial sin entender por qué, que es peor que el cero que había antes.
	$relaxed_notice = function_exists( 'akibara_search_relaxed_notice' ) ? akibara_search_relaxed_notice() : null;
	if ( null !== $relaxed_notice && '' !== $relaxed_notice ) {
		printf(
			'<p class="akb-relaxed" data-akb-relaxed role="status">%s</p>',
			wp_kses( $relaxed_notice, array( 'strong' => array( 'class' => array() ) ) )
		);
	} else {
		echo '<p class="akb-relaxed" data-akb-relaxed hidden></p>';
	}

	echo akibara_gp_akb_network_notice(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- EmptyStateCopy::network_html() escapa cada tramo.

	/**
	 * El conteo permanece visible en todas las superficies de catálogo.
	 * Su nodo es un destino estable del intercambio PJAX; el anuncio accesible
	 * se entrega por separado mediante #akb-live-region.
	 */
	$total = (int) $view['total'];
	/* translators: %s: cantidad total de productos del catálogo. */
	$unit  = _n( '%s producto', '%s productos', $total, 'akibara-search' );
	// Sin `data-akb-count`/`data-akb-unit`: ningún JS los leía, y el segundo
	// estaba clavado en singular. El `aria-live` tampoco iba acá — el anuncio a
	// lectores lo hace `#akb-live-region`, que el propio pjax mantiene; dos
	// regiones vivas para el mismo dato lo anunciaban dos veces.
	//
	// `akb_search_result_count_extra`: HTML que otro plugin quiere en la MISMA
	// línea que el total. Hoy lo usa akibara-business en las landings de serie
	// para el atajo «Último tomo: N →»: es la única línea sobre la rejilla con
	// ancho de sobra, así que el atajo no suma alto antes de la primera portada.
	// Es un filtro y no una llamada a una clase del otro plugin a propósito: con
	// cualquiera de los dos desactualizado, simplemente no sale nada.
	$extra = apply_filters( 'akb_search_result_count_extra', '', $view );
	echo akibara_search_result_count_html( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapa el total y pasa el extra por wp_kses_post().
		sprintf( $unit, number_format_i18n( $total ) ),
		is_string( $extra ) ? $extra : ''
	);
}

/**
 * Marcado de la línea del total.
 *
 * Sin extra, el de siempre: `<p class="akb-result-count">N productos</p>`. Con
 * extra, el total va en su propio `<span>` —lo que el pjax anuncia a lectores
 * de pantalla es SÓLO el total, no el enlace de al lado— y la clase
 * `--extra` le devuelve el salto de línea que el tema le quita con `nowrap`:
 * si el extra no cabe junto al total, baja a la línea siguiente en vez de
 * empujar la página en horizontal.
 *
 * @param string $label Total ya redactado («30 productos»), sin escapar.
 * @param string $extra HTML de `akb_search_result_count_extra`.
 * @return string HTML escapado.
 */
function akibara_search_result_count_html( string $label, string $extra ): string {
	$extra = trim( wp_kses_post( $extra ) );
	if ( '' === $extra ) {
		return '<p class="akb-result-count">' . esc_html( $label ) . '</p>';
	}

	return '<p class="akb-result-count akb-result-count--extra"><span class="akb-result-count__total">'
		. esc_html( $label ) . '</span> ' . $extra . '</p>';
}

/**
 * Obtiene la consulta del view-model para los títulos de búsqueda de productos.
 * La misma consulta alimenta el H1 de WooCommerce y los títulos de WordPress
 * y Rank Math. Devuelve una cadena vacía si la búsqueda no está activa.
 *
 * @return string Consulta activa, o '' si no hay búsqueda o el flag está OFF.
 */
function akibara_gp_akb_search_query(): string {
	// Sólo construye el view-model en una superficie de productos habilitada.
	// Fuera de ella devuelve una cadena vacía y evita consultar facetas o consumir
	// el chequeo terminal de la lease. Después de ese chequeo el contrato del
	// buscador no permite abrir nuevas lecturas.
	if ( ! akibara_gp_akb_facets_active() || ! akibara_gp_akb_is_product_surface() ) {
		return '';
	}
	$view = akibara_search_facets_view();
	return null === $view ? '' : trim( (string) ( $view['search'] ?? '' ) );
}

/**
 * H1 visible del archive. WC lo ECHOa sin escapar (histórico: admite HTML)
 * → la consulta, que viene del usuario, se escapa acá.
 *
 * @param string $title Título original de WC.
 * @return string
 */
function akibara_gp_akb_search_page_title( string $title ): string {
	$q = akibara_gp_akb_search_query();
	return '' === $q ? $title : sprintf( 'Resultados para «%s»', esc_html( $q ) );
}
add_filter( 'woocommerce_page_title', 'akibara_gp_akb_search_page_title' );

/**
 * <title> vía core (document_title_parts). Rank Math lo bypassea con
 * pre_get_document_title — para prod está el filtro de abajo.
 *
 * @param array<string, string> $parts Partes del título.
 * @return array<string, string>
 */
function akibara_gp_akb_search_document_title( array $parts ): array {
	$q = akibara_gp_akb_search_query();
	if ( '' !== $q ) {
		// Escapa la consulta antes de incorporarla al título, sin depender de que
		// otros consumidores conserven el escape posterior de WordPress.
		$parts['title'] = sprintf( 'Resultados para «%s»', esc_html( $q ) );
	}
	return $parts;
}
add_filter( 'document_title_parts', 'akibara_gp_akb_search_document_title' );

/**
 * <title> vía Rank Math (prod). Mismo copy; el separador/sitio se agregan
 * explícitos porque este filtro recibe el título ya compuesto.
 *
 * @param mixed $title Título compuesto por Rank Math.
 * @return mixed
 */
function akibara_gp_akb_search_rankmath_title( $title ) {
	$q = akibara_gp_akb_search_query();
	if ( '' === $q || ! is_string( $title ) ) {
		return $title;
	}
	return sprintf( 'Resultados para «%s» – Akibara', esc_html( $q ) );
}
add_filter( 'rank_math/frontend/title', 'akibara_gp_akb_search_rankmath_title', 20 );

/**
 * SearchAction del schema (sitelinks searchbox de Google): Rank Math apunta
 * por defecto a `/?s={search_term_string}` — la búsqueda genérica de WP, sin
 * grid de productos y donde este plugin decide NO montar facetas (ver
 * akibara_gp_akb_is_product_surface). La búsqueda real del sitio es
 * `/tienda/?_buscar=`; si Google muestra el searchbox, debe aterrizar ahí.
 *
 * Gate por el flag de facetas (la superficie `_buscar` solo existe con el
 * filtrado propio activo); con el flag OFF Rank Math queda intacto. El
 * placeholder `{search_term_string}` se concatena a mano — add_query_arg lo
 * urlencodearía y el schema quedaría inválido.
 *
 * @since 0.1.9
 *
 * @param mixed $data Entidades JSON-LD compuestas por Rank Math.
 * @return mixed
 */
function akibara_gp_akb_search_action_jsonld( $data ) {
	if ( ! is_array( $data ) || empty( $data['WebSite'] ) || ! is_array( $data['WebSite'] ) ) {
		return $data;
	}
	if ( ! akibara_gp_akb_facets_active() ) {
		return $data;
	}
	if ( ! function_exists( 'wc_get_page_permalink' ) ) {
		return $data;
	}

	$shop = (string) wc_get_page_permalink( 'shop' );
	if ( '' === $shop ) {
		return $data;
	}
	$target = $shop . ( str_contains( $shop, '?' ) ? '&' : '?' ) . '_buscar={search_term_string}';

	$action = $data['WebSite']['potentialAction'] ?? null;
	if ( ! is_array( $action ) ) {
		return $data;
	}

	// Rank Math clásico usa target string; el formato EntryPoint anida
	// urlTemplate. Se cubren ambos sin inventar la estructura si cambia.
	if ( isset( $action['target'] ) && is_string( $action['target'] ) ) {
		$data['WebSite']['potentialAction']['target'] = $target;
	} elseif ( isset( $action['target']['urlTemplate'] ) && is_string( $action['target']['urlTemplate'] ) ) {
		$data['WebSite']['potentialAction']['target']['urlTemplate'] = $target;
	}

	return $data;
}
add_filter( 'rank_math/json_ld', 'akibara_gp_akb_search_action_jsonld', 20 );

/**
 * Canonicaliza ?s=…&post_type=product a /tienda/?_buscar=… cuando está activo
 * el filtrado propio. La búsqueda genérica de entradas conserva su ruta.
 * Se redirige la URL completa para evitar combinar el LIKE nativo con
 * los resultados del motor propio. Usa 302 porque la palanca del panel
 * puede desactivar esta gramática y un 301 persistiría en el navegador.
 * La consulta normalizada comienza en la primera página, sin arrastrar paginación.
 *
 * @return void
 */
function akibara_gp_akb_canonicalize_product_search(): void {
	if ( is_admin() || is_feed() || is_robots()
		|| ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() )
		|| ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return;
	}
	if ( ! is_search() || 'product' !== get_query_var( 'post_type' ) ) {
		return;
	}
	// Con el filtrado propio apagado, /tienda/?_buscar= no busca nada: mandar ahí
	// al usuario sería cambiar un resultado pobre por ninguno.
	if ( ! akibara_gp_akb_facets_active() || ! function_exists( 'wc_get_page_permalink' ) ) {
		return;
	}

	$term = trim( (string) get_search_query( false ) );
	$shop = (string) wc_get_page_permalink( 'shop' );
	if ( '' === $term || '' === $shop ) {
		return;
	}

	// Las facetas ya seleccionadas viajan con la consulta: quien llega con
	// `_editorial=Ivrea` desde un enlace viejo no debe perder el filtro por el
	// salto. El plugin es el dueño del prefijo `_`; el resto del query string
	// (incluido `post_type`, que en /tienda/ sobra) se descarta a propósito.
	$args = array( '_buscar' => $term );
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lectura de navegación, sin efectos.
	$get = wp_unslash( $_GET );
	if ( is_array( $get ) ) {
		foreach ( $get as $key => $value ) {
			if ( ! is_string( $key ) || '_buscar' === $key || ! str_starts_with( $key, '_' ) || ! is_scalar( $value ) ) {
				continue;
			}
			$args[ $key ] = (string) $value;
		}
	}

	// add_query_arg ya urlencodea los valores; pasarlos codificados a mano dejaba
	// los `%` re-escapados y el término llegaba ilegible al motor.
	$destino = add_query_arg( $args, $shop );
	wp_safe_redirect( $destino, 302, 'akibara-search' );
	exit;
}
add_action( 'template_redirect', 'akibara_gp_akb_canonicalize_product_search', 0 );

/**
 * Devuelve la URL canónica del archivo actual, sin query string ni tramo
 * de paginación. Cada conjunto nuevo de filtros comienza en la primera página;
 * el formulario GET incorpora los criterios sobre esa URL limpia.
 *
 * @return string
 */
function akibara_gp_akb_form_action(): string {
	if ( function_exists( 'is_search' ) && is_search() && function_exists( 'wc_get_page_permalink' ) ) {
		return (string) wc_get_page_permalink( 'shop' );
	}
	global $wp;
	return home_url( user_trailingslashit( akibara_gp_akb_strip_pagination( isset( $wp->request ) ? (string) $wp->request : '' ) ) );
}

/**
 * Quita el tramo final de paginación (`page/N`) de una ruta.
 *
 * La base sale de `$wp_rewrite->pagination_base`, no de un literal: un sitio
 * puede traducirla («pagina»), y entonces `page` sería un slug cualquiera.
 *
 * @param string $request Ruta sin barras en los extremos (`tienda/page/2`).
 * @return string La misma ruta sin su paginación (`tienda`).
 */
function akibara_gp_akb_strip_pagination( string $request ): string {
	global $wp_rewrite;
	$base = is_object( $wp_rewrite ) && ! empty( $wp_rewrite->pagination_base ) ? (string) $wp_rewrite->pagination_base : 'page';

	return (string) preg_replace( '#(^|/)' . preg_quote( $base, '#' ) . '/\d+/?$#', '', trim( $request, '/' ) );
}

/**
 * Criterio de orden vigente en el request, o '' si no hay uno válido.
 *
 * Se coteja contra el catálogo REAL de WooCommerce
 * (`woocommerce_catalog_orderby`), no contra una lista propia: reflejar un
 * `?orderby=` arbitrario en un hidden del HTML sería eco de entrada del usuario,
 * y la lista propia se driftearía en cuanto el sitio agregue un criterio.
 *
 * @return string
 */
function akibara_gp_akb_active_orderby(): string {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- estado de lectura GET, sin escritura.
	$orderby = isset( $_GET['orderby'] ) && is_string( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : '';
	if ( '' === $orderby || ! function_exists( 'apply_filters' ) ) {
		return '';
	}

	return isset( akibara_gp_akb_catalog_orderby_options()[ $orderby ] ) ? $orderby : '';
}

/**
 * Obtiene los criterios de orden nativos de WooCommerce y los amplía mediante
 * woocommerce_catalog_orderby. El filtro recibe la misma lista base que el core,
 * porque WooCommerce no registra un callback que la añada a un array vacío.
 * Las etiquetas no se muestran: se valida y conserva la clave de orden elegida.
 *
 * @return array<string, string>
 */
function akibara_gp_akb_catalog_orderby_options(): array {
	$defaults = array(
		'menu_order' => 'Default sorting',
		'popularity' => 'Sort by popularity',
		'rating'     => 'Sort by average rating',
		'date'       => 'Sort by latest',
		'price'      => 'Sort by price: low to high',
		'price-desc' => 'Sort by price: high to low',
	);

	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- filtro de WooCommerce: aquí se LEE su lista de criterios de orden, no se declara un hook propio.
	$options = apply_filters( 'woocommerce_catalog_orderby', $defaults );

	return is_array( $options ) ? $options : $defaults;
}

/**
 * Encola el pjax + drawer + CSS del filtrado propio. Solo con el flag activo y
 * en superficies de catálogo. Vanilla, sin jQuery.
 *
 * @return void
 */
function akibara_gp_akb_enqueue_assets(): void {
	if ( ! akibara_gp_akb_facets_active() || ! akibara_gp_akb_is_product_surface() ) {
		return;
	}

	$base = rtrim( AKIBARA_SEARCH_DIR, '/' );
	$uri  = rtrim( AKIBARA_SEARCH_URL, '/' );

	// `.min` esbuild (ver Search::enqueue_assets); SCRIPT_DEBUG sirve el fuente.
	$min = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';

	$js_rel = '/assets/js/akb-search' . $min . '.js';
	if ( '' !== $min && ! file_exists( $base . $js_rel ) ) {
		$js_rel = '/assets/js/akb-search.js';
	}
	wp_enqueue_script(
		'akb-search',
		$uri . $js_rel,
		array(),
		\Akibara\Search\Search::asset_version( $js_rel ),
		true
	);
	wp_localize_script(
		'akb-search',
		'AKB_FACETS',
		array(
			'countUrl' => esc_url_raw( rest_url( 'akibara/v1/search/count' ) ),
			'mobileMax' => 768,
			// Las dos formas de la palabra del botón «Ver N productos».
			'noun'      => array(
				'one'   => akibara_gp_akb_products_noun( 1 ),
				'other' => akibara_gp_akb_products_noun( 2 ),
			),
		)
	);

	$css_rel = '/assets/css/akb-search' . $min . '.css';
	if ( '' !== $min && ! file_exists( $base . $css_rel ) ) {
		$css_rel = '/assets/css/akb-search.css';
	}
	wp_enqueue_style(
		'akb-search',
		$uri . $css_rel,
		array( 'akibara-base' ),
		\Akibara\Search\Search::asset_version( $css_rel )
	);
}
add_action( 'wp_enqueue_scripts', 'akibara_gp_akb_enqueue_assets', 20 );

// ---------------------------------------------------------------------------
// Hooks — se enganchan en posiciones del loop de WooCommerce, gateados por el flag del buscador propio.
// ---------------------------------------------------------------------------

/**
 * Con el filtrado propio habilitado se muestra un único resumen de resultados.
 * El resumen propio se intercambia mediante PJAX y anuncia sus cambios
 * por #akb-live-region. La prioridad 1 retira el contador nativo antes de que
 * se pinte; con el filtrado apagado WooCommerce conserva su salida.
 *
 * @return void
 */
function akibara_gp_akb_remove_wc_result_count(): void {
	if ( akibara_gp_akb_facets_active() ) {
		remove_action( 'woocommerce_before_shop_loop', 'woocommerce_result_count', 20 );
	}
}
add_action( 'woocommerce_before_shop_loop', 'akibara_gp_akb_remove_wc_result_count', 1 );
add_action( 'woocommerce_before_shop_loop', 'akibara_search_render_series_band', 3 );

/**
 * Fuerza el sidebar izquierdo (sidebar-2) como "activo" en las superficies de
 * catálogo donde inyectamos las facetas propias.
 *
 * GeneratePress solo renderea el contenedor `<aside id="left-sidebar">` —y con
 * él el layout de dos columnas— si `is_active_sidebar('sidebar-2')` es true.
 * Nuestras facetas se inyectan por código en `dynamic_sidebar_before` (no como
 * widgets en BD), así que sin este override el sidebar quedaría "vacío" → GP no
 * lo renderea → `dynamic_sidebar_before` nunca dispara → las facetas desaparecen.
 *
 * Se retiró de `inc/facets.php` del theme (última pieza viva de ese archivo
 * FacetWP) en la Fase 4.4 del cutover; vive acá, junto al render que la
 * necesita y gateada por la MISMA condición que `akibara_gp_akb_render_sidebar`.
 *
 * @param bool   $is_active Estado actual del sidebar.
 * @param string $index     Slug del sidebar.
 * @return bool
 */
function akibara_gp_akb_force_active_sidebar( $is_active, $index ) {
	if ( 'sidebar-2' === $index && akibara_gp_akb_facets_active() && akibara_gp_akb_is_product_surface() ) {
		return true;
	}
	return (bool) $is_active;
}
add_filter( 'is_active_sidebar', 'akibara_gp_akb_force_active_sidebar', 10, 2 );

/**
 * Sirve únicamente el grupo solicitado de una faceta grande cuando la URL
 * incluye _akb_fragment=1. La respuesta conserva .akb-facet[data-facet]
 * para que el cliente la procese igual que una página completa.
 * La representación parcial requiere URL propia: el borde no diferencia
 * las respuestas por X-Requested-With. Sin ese parámetro se sirve la página
 * completa, también para clientes anteriores o para navegación sin JavaScript.
 *
 * @return string|null HTML del grupo, o null si no corresponde fragmento.
 */
function akibara_gp_akb_facet_options_fragment(): ?string {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- estado visual GET, sin escritura.
	if ( ! isset( $_GET['_akb_fragment'] ) || '1' !== $_GET['_akb_fragment'] ) {
		return null;
	}
	if ( ! akibara_gp_akb_facets_active() || ! akibara_gp_akb_is_product_surface() ) {
		return null;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- estado visual GET, sin escritura.
	$name = isset( $_GET['_akb_expand'] ) && is_string( $_GET['_akb_expand'] ) ? sanitize_key( wp_unslash( $_GET['_akb_expand'] ) ) : '';
	if ( '' === $name ) {
		return null;
	}

	$view = akibara_search_facets_view( null );
	if ( null === $view || empty( $view['facets'] ) ) {
		return null;
	}

	foreach ( (array) $view['facets'] as $facet ) {
		if ( ! is_array( $facet ) || $name !== (string) ( $facet['name'] ?? '' ) ) {
			continue;
		}

		ob_start();
		akibara_gp_akb_render_facet_group( $facet );

		return (string) ob_get_clean();
	}

	return null;
}

/**
 * Sirve el fragmento y corta la request antes de que el tema renderice nada.
 *
 * @return void
 */
function akibara_gp_akb_render_facet_options_fragment(): void {
	$fragment = akibara_gp_akb_facet_options_fragment();
	if ( null === $fragment ) {
		return;
	}

	nocache_headers();
	header( 'Content-Type: text/html; charset=utf-8' );
	// Es un trozo de página con URL propia: que ningún buscador lo indexe.
	header( 'X-Robots-Tag: noindex, nofollow' );
	echo $fragment; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ya escapado por el renderer del grupo.
	echo akibara_gp_akb_fragment_bounce(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapado dentro.
	exit;
}
add_action( 'template_redirect', 'akibara_gp_akb_render_facet_options_fragment', 0 );

/**
 * Añade una salida navegable al documento de una faceta parcial.
 * La URL mantiene una única representación cacheable para todos los clientes.
 * El script sólo actúa si el fragmento es el documento principal y navega a
 * la misma URL sin _akb_fragment, conservando el grupo expandido.
 * Al importarlo con DOMParser el script no se ejecuta.
 *
 * @return string HTML ya escapado.
 */
function akibara_gp_akb_fragment_bounce(): string {
	$vuelta = esc_url_raw( remove_query_arg( '_akb_fragment' ) );
	if ( '' === $vuelta ) {
		return '';
	}

	return sprintf(
		'<script data-no-optimize="1" data-no-defer="1">location.replace(%s);</script><noscript><p><a href="%s">Ver la tienda completa</a></p></noscript>',
		(string) wp_json_encode( $vuelta, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES ),
		esc_url( $vuelta )
	);
}

/**
 * Marca el fail-open POR GATE en el HTML servido [revisión de 0.1.64].
 *
 * El PJAX y «Ver todas las opciones» reintentan una respuesta sin facetas SÓLO
 * si trae esta marca. Sin ella confundían dos casos: el archivo vacío que omite
 * el form a propósito (`akibara_gp_akb_render_sidebar()`) y el catálogo sin
 * filtrar de la ventana de guardado. Va en `<head>` porque las dos superficies
 * reciben la página completa, y porque el fetch del prefetch sólo conserva el
 * texto, no las cabeceras. La página que la lleva nunca se guarda en caché:
 * `EditorialDiscountPublication::cap_page_cache_ttl()` la marca no-store cuando
 * la captura de la publicación falla.
 *
 * @return void
 */
function akibara_gp_akb_print_fail_open_marker(): void {
	if ( ! function_exists( 'akibara_search_facets_fail_open' ) || ! akibara_search_facets_fail_open() ) {
		return;
	}
	if ( ! akibara_gp_akb_is_product_surface() ) {
		return;
	}
	echo '<meta name="akb-search-facets" content="indisponible">' . "\n";
}
add_action( 'wp_head', 'akibara_gp_akb_print_fail_open_marker', 1 );

add_action( 'dynamic_sidebar_before', 'akibara_gp_akb_render_sidebar', 4 );
add_action( 'woocommerce_before_shop_loop', 'akibara_gp_akb_render_result_count', 5 );
add_action( 'woocommerce_before_shop_loop', 'akibara_gp_akb_render_chips', 36 );
add_action( 'woocommerce_before_shop_loop', 'akibara_gp_akb_render_empty_state', 37 );

// 0 resultados: `woocommerce_product_loop()` es false (have_posts()=false y modo
// 'products'), así que `woocommerce_before_shop_loop` NO dispara y el grid no
// existe. Enganchamos el MISMO chrome a `woocommerce_no_products_found` para que
// count/chips/empty-state estén presentes también en 0 hits (ancla del swap pjax
// 0→N y empty-state server-first). El guard `akibara_gp_akb_once` evita el doble
// render en el modo subcategorías (donde ambos hooks podrían disparar).
add_action( 'woocommerce_no_products_found', 'akibara_gp_akb_suppress_wc_empty', 5 );
add_action( 'woocommerce_no_products_found', 'akibara_gp_akb_render_result_count', 12 );
add_action( 'woocommerce_no_products_found', 'akibara_gp_akb_render_chips', 16 );
add_action( 'woocommerce_no_products_found', 'akibara_gp_akb_render_empty_state', 17 );

/**
 * Con el filtrado propio activo, el empty-state propio reemplaza el aviso
 * genérico de WooCommerce (`wc_no_products_found`, prio 10): sin esto saldrían
 * DOS mensajes de "sin resultados". Corre a prio 5 (antes que el 10) y solo lo
 * quita si el filtrado propio está activo (si no, WC muestra el suyo normal).
 *
 * @return void
 */
function akibara_gp_akb_suppress_wc_empty(): void {
	if ( akibara_gp_akb_facets_active() ) {
		remove_action( 'woocommerce_no_products_found', 'wc_no_products_found', 10 );
	}
}
