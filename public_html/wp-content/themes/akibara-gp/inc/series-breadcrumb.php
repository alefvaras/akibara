<?php
/**
 * Breadcrumb de las landings de serie (`/serie/*`), de autor (`/autor/*`) y de
 * género (`/genero/*`).
 *
 * QUÉ ROMPÍA. WooCommerce servía "Inicio / Serie del producto / The Climber" y
 * el peldaño del medio era TEXTO PLANO SIN ENLACE: la etiqueta cruda de la
 * taxonomía `pa_serie`, jerga de máquina, justo en el sitio donde el usuario
 * espera subir un nivel. El 72,5% de las visitas a estas páginas llega desde
 * Google directo, sin contexto previo, y se encontraba con que la única ruta de
 * salida no llevaba a ninguna parte: medido, 21 de los 23 enlaces de `<main>`
 * apuntan a los mismos 17 tomos de la propia serie.
 *
 * Existe un destino real para ese peldaño —el hub con las 104 series— y no se
 * estaba usando.
 *
 * POR QUÉ POR POSICIÓN Y NO POR URL. El precedente first-party
 * (`DisponiblesRoute::filter_breadcrumb()`, `PreventasRoute`) empareja los
 * peldaños por su URL y descarta los que no la tienen
 * (`if ( empty( $crumb[1] ) ) { continue; }`). Acá el peldaño que hay que
 * reemplazar es EXACTAMENTE el que no tiene URL, así que ese matcher no sirve:
 * se empareja por posición (el penúltimo) y se confirma con la ausencia de URL,
 * que es la firma del peldaño-etiqueta de taxonomía.
 *
 * SI NO HAY HUB, NO HAY ENLACE. La página del directorio puede no existir (el
 * módulo `SeriesDirectory` del plugin es inerte sin ella). En ese caso el
 * peldaño se queda como texto, pero con la palabra que un humano entiende en
 * vez de con la etiqueta de la taxonomía: el arreglo de lenguaje no depende del
 * de navegación.
 *
 * @package akibara-gp
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * URL del hub de series, o cadena vacía si la página no existe.
 *
 * NO se resuelve por slug: `/series/` es una página de WordPress que el dueño
 * puede mover o renombrar, y un slug clavado acá se convertiría en un 404
 * silencioso en 106 páginas. Se busca la que CONTIENE el shortcode del
 * directorio, que es lo que define funcionalmente al hub.
 *
 * Cacheado en transient porque esto corre en cada landing de serie y la consulta
 * recorre el contenido de las páginas. Se invalida solo al guardar cualquier
 * página (ver más abajo): mover el shortcode es justo el evento que tiene que
 * refrescarlo.
 *
 * DOS DETALLES QUE SE PAGABAN CAROS. (1) La consulta pedía UNA página y luego la
 * filtraba con `has_shortcode`: si la mejor coincidencia de la búsqueda de WP era
 * una página que sólo NOMBRA el shortcode en prosa, el hub real ni siquiera
 * entraba en la lista y la defensa sólo podía producir un falso negativo. Se
 * piden cinco. (2) Ese falso negativo se cacheaba 12 h igual que un acierto, así
 * que el enlace se perdía en 106 landings durante medio día; el negativo ahora
 * caduca en una hora.
 *
 * @return string URL absoluta, o '' si no hay hub publicado.
 */
function akibara_gp_series_hub_url(): string {
	$cached = get_transient( 'akb_gp_series_hub_url' );
	if ( is_string( $cached ) ) {
		return $cached;
	}

	$url = '';

	$pages = get_posts(
		array(
			'post_type'              => 'page',
			'post_status'            => 'publish',
			'posts_per_page'         => 5,
			's'                      => 'akibara_series_directory',
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		)
	);

	foreach ( $pages as $page_id ) {
		$content = (string) get_post_field( 'post_content', $page_id );
		// `has_shortcode` y no el `s` a secas: la búsqueda de WP encuentra
		// también una página que sólo MENCIONE el nombre del shortcode en prosa.
		if ( has_shortcode( $content, 'akibara_series_directory' ) ) {
			$url = (string) get_permalink( $page_id );
			break;
		}
	}

	set_transient(
		'akb_gp_series_hub_url',
		$url,
		'' === $url ? HOUR_IN_SECONDS : 12 * HOUR_IN_SECONDS
	);

	return $url;
}

/**
 * Olvida el hub cacheado cuando se guarda cualquier página.
 *
 * Barato y suficiente: publicar, mover o despublicar la página del directorio
 * pasa por acá, y el costo de recalcular es una consulta cada 12 h.
 *
 * @param int $post_id ID del post guardado.
 */
function akibara_gp_forget_series_hub_url( $post_id ): void {
	if ( 'page' === get_post_type( (int) $post_id ) ) {
		delete_transient( 'akb_gp_series_hub_url' );
	}
}
add_action( 'save_post', 'akibara_gp_forget_series_hub_url' );
add_action( 'delete_post', 'akibara_gp_forget_series_hub_url' );

/**
 * Etiquetas humanas para el peldaño-etiqueta de las taxonomías de atributo.
 *
 * Convierte los nombres técnicos de taxonomías en etiquetas legibles
 * para el usuario en la miga de pan («Series», «Autores», «Géneros»).
 * Sólo `pa_serie` enlaza a su directorio; las demás se exhiben como texto plano.
 */
const AKIBARA_GP_ATTRIBUTE_CRUMB_LABELS = array(
	'pa_serie'  => 'Series',
	'pa_autor'  => 'Autores',
	'pa_genero' => 'Géneros',
);

/**
 * Reemplaza el peldaño-etiqueta de `pa_serie`/`pa_autor`/`pa_genero` por su
 * palabra humana; el de series, además, enlazado al hub.
 *
 * @param mixed $crumbs Array de peldaños `[ [nombre, url], … ]`.
 * @return mixed
 */
function akibara_gp_series_breadcrumb( $crumbs ) {
	if ( ! is_array( $crumbs ) || count( $crumbs ) < 2 ) {
		return $crumbs;
	}
	if ( ! function_exists( 'is_tax' ) ) {
		return $crumbs;
	}
	$taxonomia = '';
	foreach ( array_keys( AKIBARA_GP_ATTRIBUTE_CRUMB_LABELS ) as $candidata ) {
		if ( is_tax( $candidata ) ) {
			$taxonomia = $candidata;
			break;
		}
	}
	if ( '' === $taxonomia ) {
		return $crumbs;
	}

	// La firma del peldaño-etiqueta: sin URL y NO es el último (el último es el
	// término en la página 1, o «Página N» en las siguientes: `paged_trail()`
	// añade ese peldaño y el matcher posicional de antes —el penúltimo— se iba
	// al término y hacía bail desde la página 2; revisión R12, 2). Si
	// WooCommerce algún día enlaza la etiqueta, esto se vuelve un no-op solo.
	$i = null;
	foreach ( $crumbs as $pos => $crumb ) {
		if ( 0 === $pos || $pos === count( $crumbs ) - 1 || ! is_array( $crumb ) ) {
			continue;
		}
		if ( empty( $crumb[1] ) ) {
			$i = $pos;
			break;
		}
	}
	if ( null === $i ) {
		return $crumbs;
	}

	$crumbs[ $i ][0] = AKIBARA_GP_ATTRIBUTE_CRUMB_LABELS[ $taxonomia ];
	if ( 'pa_serie' === $taxonomia ) {
		$hub = akibara_gp_series_hub_url();
		if ( '' !== $hub ) {
			$crumbs[ $i ][1] = $hub;
		}
	}
	return $crumbs;
}
add_filter( 'woocommerce_get_breadcrumb', 'akibara_gp_series_breadcrumb', 20 );

/**
 * Traduce el `aria-label` del breadcrumb de WooCommerce.
 *
 * El literal "Breadcrumb" está HARDCODEADO en `wc_get_breadcrumb()`
 * (wc-template-functions.php) SIN pasar por `__()`, así que no hay filtro de
 * traducción que lo alcance y copiar la plantilla tampoco sirve: el `wrap_before`
 * se arma en los defaults. Es el ÚNICO landmark de orientación de la página y se
 * anunciaba en inglés en un documento `lang="es"`, donde los otros tres `nav` sí
 * están en español.
 *
 * Se reescribe el atributo dentro del `wrap_before` en vez de reconstruirlo
 * entero, para no perder las clases ni la estructura que WooCommerce (o un
 * plugin) hayan puesto ahí.
 *
 * @param mixed $defaults Defaults del breadcrumb.
 * @return mixed
 */
function akibara_gp_breadcrumb_defaults( $defaults ) {
	if ( ! is_array( $defaults ) || empty( $defaults['wrap_before'] ) || ! is_string( $defaults['wrap_before'] ) ) {
		return $defaults;
	}

	$defaults['wrap_before'] = str_replace(
		array( 'aria-label="Breadcrumb"', "aria-label='Breadcrumb'" ),
		'aria-label="Ruta de navegación"',
		$defaults['wrap_before']
	);

	return $defaults;
}
add_filter( 'woocommerce_breadcrumb_defaults', 'akibara_gp_breadcrumb_defaults', 20 );
