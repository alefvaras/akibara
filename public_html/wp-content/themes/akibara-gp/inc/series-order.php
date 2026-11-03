<?php
/**
 * Orden por defecto de la rejilla en las landings de serie (`/serie/*`).
 *
 * QUÉ ROMPÍA. `/serie/the-climber/` servía los 17 tomos así:
 *
 *     17, 16, 15, 14, 13, 12, 10, 09, 07, 05, 04, 02, 01 | 11, 08, 06, 03
 *
 * El corte cae exacto entre los 13 vendibles y los 4 agotados, y el tomo 01
 * queda en la posición 13 de 17. El origen NO es un bug: `menu_order` lo
 * escribe el interleaver de round-robin de akibara-business, que ordena dentro
 * de cada serie por `sellable DESC → date DESC → id DESC` para que la página 1
 * de `/tienda/` no sea un muro de una sola serie. Esa lógica está bien pensada
 * ahí — pero en un archive `pa_serie` la dimensión "no repitas serie" COLAPSA
 * (todos los productos son de la misma serie) y sólo sobrevive el criterio
 * intra-serie, que nadie diseñó para esta superficie. Medido en One Piece
 * (107 tomos, 24 por página): el tomo 001 era el ÚLTIMO ítem de la página 5
 * de 5, mientras el pie de esa misma página invita, escrito a mano, a
 * "empezar por el East Blue… tomo a tomo".
 *
 * POR QUÉ `title` Y NO OTRA COSA. El auto-padding numérico de títulos
 * (`akibara-business.php`, "Gannibal 01") existe justo para esto y su docblock
 * lo declara: título ASC == orden de tomo. Verificado con curl contra
 * producción — `?orderby=title` devuelve 01…17 exacto en The Climber y
 * 001…024 limpio en One Piece. No hace falta inventar un criterio nuevo.
 *
 * ACOTADO A `pa_serie` A PROPÓSITO. `/tienda/`, las categorías y el resto de
 * los archives conservan `menu_order`: ahí la vitrina es lo correcto y
 * matarla globalmente sería cambiar la portada del catálogo por accidente.
 *
 * «NOVEDADES PRIMERO» EN LANDINGS DE SERIE: `menu_order` es interpretado
 * por akibara-business (`CatalogOrder\SeriesNewestOrder`) para posicionar
 * lanzamientos recientes arriba y agotados al final. Este módulo provee la
 * etiqueta y la opción en el selector, delegando el criterio de ordenación en business.
 *
 * TRAMPA VERIFICADA (WooCommerce 11.0.0, wc-template-functions.php:1680/1726).
 * `woocommerce_catalog_ordering()` calcula
 * `$show_default_orderby = 'menu_order' === apply_filters('woocommerce_default_catalog_orderby', …)`
 * ANTES de disparar `woocommerce_catalog_orderby`, y después hace
 * `unset($catalog_orderby_options['menu_order'])` cuando ese flag es falso. O
 * sea: al mover el default a `title`, WooCommerce BORRA "Destacados" del
 * selector en las 106 landings — justo la salida que acá se renombra a
 * "Novedades primero". Reinyectarla desde `woocommerce_catalog_orderby` no
 * sirve: ese filtro corre ANTES del unset. Por eso la reinyección vive en el
 * override `woocommerce/loop/orderby.php`, que es el único punto posterior al
 * unset, y se hace de forma declarativa ("si falta, repónla") — si un
 * WooCommerce futuro deja de borrarla, la reposición se vuelve un no-op sola,
 * en vez de duplicar la opción.
 *
 * DESCARTADO: alternar el valor del filtro según la llamada (devolver
 * `menu_order` a la primera invocación y `title` a la segunda). Funciona hoy y
 * se rompe EN SILENCIO en cuanto WooCommerce reordene sus dos `apply_filters`
 * internos, dejando el selector mintiendo sobre el orden que se ve.
 *
 * @package akibara-gp
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * ¿Estamos en una landing de serie (`/serie/<slug>/`, taxonomía `pa_serie`)?
 *
 * Es el ÚNICO gate de este módulo: fuera de acá nada cambia. Se resuelve por
 * la condicional de WordPress y no por la URL porque el archive también se
 * sirve paginado (`/serie/one-piece/page/5/`) y bajo el PJAX del buscador
 * propio, y ambos casos deben ordenarse igual que la página 1.
 */
function akibara_gp_is_series_archive(): bool {
	return function_exists( 'is_tax' ) && is_tax( 'pa_serie' );
}

/**
 * ¿La landing se está filtrando por texto (`?_buscar=…`)?
 *
 * Con búsqueda el orden lo decide la relevancia del buscador: akibara-search
 * conserva su puntaje mientras el cliente no elija otro orden (o elija la
 * primera opción, `menu_order`), y akibara-business llama «Relevancia» a esa
 * opción. Nada de lo que este módulo cambia para la serie sin búsqueda (el
 * orden por tomo por defecto y el «Novedades primero») describe ese resultado.
 */
function akibara_gp_series_search_active(): bool {
	$raw = isset( $_GET['_buscar'] ) ? wp_unslash( $_GET['_buscar'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- lectura de un GET público de navegación del catálogo (igual que WooCommerce con `orderby`); se sanea más abajo, tras descartar la forma de array.

	if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
		return false;
	}

	return '' !== trim( sanitize_text_field( $raw ) );
}

/**
 * Etiquetas del selector de orden en las landings de serie.
 *
 * Los textos globales los resuelve akibara-business desde Textos y Copys, y
 * son correctos para `/tienda/`: ahí "Título: A-Z" describe el resultado. En
 * una landing donde los 17 títulos empiezan con la misma palabra, "Título:
 * A-Z" no significa nada — nombra el mecanismo, no el resultado. Estos dos
 * literales nombran el RESULTADO, que es lo que el lector está buscando
 * ("¿dónde está el tomo 1?" / "¿qué llegó nuevo?").
 *
 * Fijos en código a propósito: aplican sólo a esta superficie y describen una
 * garantía del catálogo (el padding numérico), no una preferencia editorial.
 *
 * @return array<string, string> Mapa clave de orden → etiqueta visible.
 */
function akibara_gp_series_orderby_labels(): array {
	return array(
		'menu_order' => 'Novedades primero',
		'title'      => 'Número de tomo',
	);
}

/**
 * Mueve el orden por defecto de la rejilla a `title` (tomo 1 → N) en `pa_serie`.
 *
 * Sin `?orderby` en la URL este valor decide DOS cosas a la vez: el orden real
 * de la consulta (`WC_Query::get_catalog_ordering_args()` traduce `title` a
 * `ORDER BY post_title ASC`) y la opción que sale marcada en el selector. Por
 * eso no se puede resolver sólo con `woocommerce_get_catalog_ordering_args`:
 * eso ordenaría bien la rejilla mientras el selector seguiría anunciando
 * "Destacados", que es una mentira visible.
 *
 * CON BÚSQUEDA NO (revisión B1-fix, lote de integración). La rejilla sale por
 * relevancia —el buscador ignora un orden que el cliente no eligió—, y con el
 * defecto en `title` el selector marcaba «Tomo 1 → N» mientras la lista iba
 * por puntaje, y WooCommerce borraba `menu_order` para que este módulo lo
 * repusiera como «Novedades primero». Con el defecto de siempre la opción
 * `menu_order` queda marcada y con el nombre que le da business: «Relevancia».
 *
 * @param mixed $orderby Valor por defecto vigente (normalmente `menu_order`).
 * @return mixed
 */
function akibara_gp_series_default_orderby( $orderby ) {
	return akibara_gp_is_series_archive() && ! akibara_gp_series_search_active() ? 'title' : $orderby;
}
add_filter( 'woocommerce_default_catalog_orderby', 'akibara_gp_series_default_orderby' );

/**
 * Renombra las salidas del selector en las landings de serie.
 *
 * Prioridad 20: akibara-business reconstruye el array completo en prioridad 10
 * (ahí nace `title` y ahí se resuelven los textos configurables). Correr antes
 * dejaría estas etiquetas pisadas sin dejar rastro.
 *
 * Si `title` no estuviera —única forma: el plugin desactivado, con el que el
 * theme tiene dependencia dura— se añade igual: en esta superficie es el orden
 * por defecto, y un selector que no ofrece su propio valor por defecto hace que
 * WooCommerce marque otra opción cualquiera (wc-template-functions.php:1735).
 *
 * @param mixed $options Mapa clave → etiqueta del selector.
 * @return mixed
 */
function akibara_gp_series_orderby_options( $options ) {
	if ( ! is_array( $options ) || ! akibara_gp_is_series_archive() ) {
		return $options;
	}

	$labels = akibara_gp_series_orderby_labels();

	// Con búsqueda, `menu_order` es la relevancia y su nombre lo pone
	// akibara-business («Relevancia»): «Novedades primero» anunciaba un orden
	// que no era el de la lista (revisión B1-fix).
	if ( akibara_gp_series_search_active() ) {
		unset( $labels['menu_order'] );
	}

	if ( ! isset( $options['title'] ) ) {
		$options['title'] = $labels['title'];
	}

	foreach ( $labels as $key => $label ) {
		if ( isset( $options[ $key ] ) ) {
			$options[ $key ] = $label;
		}
	}

	return $options;
}
add_filter( 'woocommerce_catalog_orderby', 'akibara_gp_series_orderby_options', 20 );

/**
 * Repone "Novedades primero" (`menu_order`) si WooCommerce ya la borró.
 *
 * La llama el override `woocommerce/loop/orderby.php`, que es el primer punto
 * del render POSTERIOR al `unset()` de wc-template-functions.php:1726 (ver el
 * docblock de arriba). Vuelve a la cabecera del array porque ése es el orden
 * que arma akibara-business y el selector no debería reordenarse sólo porque
 * cambió el criterio por defecto.
 *
 * Idempotente y declarativa: si la opción sigue ahí, no toca nada.
 *
 * @param array<string, string> $options Opciones que recibió la plantilla.
 * @return array<string, string>
 */
function akibara_gp_series_orderby_restore_menu_order( array $options ): array {
	if ( isset( $options['menu_order'] ) || ! akibara_gp_is_series_archive() ) {
		return $options;
	}

	$labels = akibara_gp_series_orderby_labels();

	return array( 'menu_order' => $labels['menu_order'] ) + $options;
}

/**
 * Repara la opción marcada del selector tras reponer `menu_order`.
 *
 * SEGUNDA MITAD DE LA MISMA TRAMPA, y no estaba en el informe: reponer la
 * opción no basta. WooCommerce cierra con
 * `if (!array_key_exists($orderby, $catalog_orderby_options)) $orderby = current(array_keys(...))`
 * (wc-template-functions.php:1735) DESPUÉS de su propio `unset()`. Es decir,
 * cuando el lector elegía "Novedades primero" el `?orderby=menu_order` que él
 * mismo pidió ya no figuraba en la lista y WooCommerce lo descartaba, marcando
 * la primera opción que quedara ("Más recientes"). La rejilla SÍ salía en
 * `menu_order` —`WC_Query` lee `$_GET` directo y nunca consulta esta lista—,
 * así que el selector terminaba anunciando un orden distinto del que se veía y
 * la elección del usuario parecía no haber tomado efecto.
 *
 * Simulado contra el pipeline real antes de escribir esto; sin esta función el
 * arreglo del orden por defecto introducía ese desfase en las 106 landings.
 *
 * No reimplementa nada: vuelve a correr la regla :1735 de WooCommerce contra el
 * array ya repuesto. Sólo puede devolver una clave que exista en el selector,
 * así que un `?orderby` inventado sigue cayendo al fallback de WooCommerce.
 *
 * @param mixed                 $orderby Opción marcada que calculó WooCommerce.
 * @param array<string, string> $options Opciones ya repuestas.
 * @return mixed
 */
function akibara_gp_series_orderby_selected( $orderby, array $options ) {
	if ( ! akibara_gp_is_series_archive() ) {
		return $orderby;
	}

	// `sanitize_key` y no `wc_clean`: las claves de orden son slugs
	// (`menu_order`, `price-desc`, `title`) y ninguna sobrevive alterada. WC
	// admite además `?orderby[]=…`, de ahí el `is_string` antes de sanear.
	$raw = isset( $_GET['orderby'] ) ? wp_unslash( $_GET['orderby'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Recommended -- se sanea en la línea siguiente, tras descartar la forma `?orderby[]=…` que WC también admite; y `?orderby` es navegación pública del catálogo (un GET que sólo ordena la rejilla), no el envío de un formulario que altere estado.

	$requested = is_string( $raw ) && '' !== $raw ? sanitize_key( $raw ) : '';

	if ( '' !== $requested && isset( $options[ $requested ] ) ) {
		return $requested;
	}

	return $orderby;
}
