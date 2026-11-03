<?php
/**
 * Blog Enhancements
 *
 * Image accessibility, formulario de comentarios y puente de búsqueda.
 *
 * @package Akibara
 * @since   4.6.0
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/*
 * Aquí vivía el shim del hero legacy (`aki-page-hero`): un filtro de
 * `the_content` que recortaba del cuerpo un `<div>` con H1 duplicado y una
 * función que releía `post_content` para reponer su bajada en el hero actual.
 *
 * Era una migración, y terminó: cero entradas conservan ese marcado. Lo que
 * quedaba era el precio — una lectura extra de `post_content` por artículo, más
 * dos `preg_match` sobre el cuerpo entero en cada render— pagado por una
 * conversión que ya no puede ocurrir. Si un día reapareciera contenido con ese
 * hero, lo que corresponde es arreglar la entrada, no volver a filtrar 33.
 */

/* --- Image sizes ---------------------------------------------------------- */

/**
 * `sizes` de la cabecera de una entrada (`single.php`): ajustado al ancho real.
 *
 * Tramos geométricos según padding y bordes de layout:
 *   ≤768  100vw - 26   `.akb-post__article` con 12 px de relleno y 1 px de borde
 *                      de `.akb-post__media` por lado.
 *   ≤911  100vw - 34   relleno de 16 por lado.
 *   >911  878px        `max-width: 880px` de contenido menos los dos bordes.
 *
 * @return string
 */
function akibara_blog_post_image_sizes(): string {
    return '(max-width: 768px) calc(100vw - 26px), (max-width: 911px) calc(100vw - 34px), 878px';
}

/**
 * `sizes` de las tarjetas del índice y los archivos del blog (`home.php`,
 * `archive.php`).
 *
 * QUÉ ROMPÍA (PM-7). `(max-width: 600px) 100vw, (max-width: 900px) 50vw, 33vw`
 * no seguía a la rejilla, que es `auto-fill` de pistas de 320 px mínimo: pedía
 * de más en móvil (la de 1.536 para una tarjeta de 378 px) y de MENOS entre 601 y
 * 687 px y entre 901 y 1.055 px, donde la tarjeta es una o dos columnas y el
 * `sizes` suponía dos o tres (portadas blandas).
 *
 * Los cortes son los de `auto-fill`, no los de una media query: la pista cabe
 * cuando el ancho útil llega a N × 320 + (N − 1) × hueco. Por eso los tramos van
 * de mayor a menor con `min-width`, que es cómo decide la rejilla:
 *   ≥1200  366px                    contenedor de 1.200 (3 columnas, techo)
 *   ≥1073  (100vw - 96) / 3 - 2     3 columnas: relleno 24 y hueco 24
 *   ≥769   50vw - 38                2 columnas: relleno 24 y hueco 24
 *   ≥705   50vw - 26                2 columnas: relleno 16 y hueco 16 (≤768)
 *   resto  100vw - 34               1 columna
 * Cada tarjeta pierde además 1 px de borde por lado.
 *
 * Con barra de desplazamiento clásica (Windows: 17 px; Mac con ratón: 15) la
 * rejilla mide 17 px menos que la media query: la 3.ª columna entra con 1.073
 * de ventana y no con 1.056, y la 2.ª con 705 y no con 688. Por eso los cortes
 * van 17 px más arriba de lo que da la cuenta sin barra: con los de la cuenta,
 * entre 1.056 y 1.072 (y entre 688 y 704) declaraba tres columnas (dos) donde
 * había dos (una), y la portada salía blanda (revisión T1-D1, medido en
 * Chromium con barras visibles). Sin barra (teléfonos, Mac con trackpad) en
 * esas dos franjas de 17 px declara la columna de más: una imagen más grande
 * de la que hace falta, nunca más pequeña.
 *
 * @return string
 */
function akibara_blog_card_image_sizes(): string {
    return '(min-width: 1200px) 366px, (min-width: 1073px) calc((100vw - 96px) / 3 - 2px), (min-width: 769px) calc(50vw - 38px), (min-width: 705px) calc(50vw - 26px), calc(100vw - 34px)';
}

/* --- Image Alt Fallback ----------------------------------------------- */

/**
 * Derive a useful alt text for a blog image.
 *
 * Priority:
 *  1) Attachment meta `_wp_attachment_image_alt` (editor-provided).
 *  2) Post title provided by the caller (accurate in-context).
 *  3) Attachment post_excerpt (caption).
 *  4) Attachment post_title (often the filename prettified).
 *  5) Generic site fallback (last resort — never leave empty).
 *
 * @param int    $attachment_id Attachment post ID (0 = no attachment).
 * @param string $context_title Usually the post/product title.
 */
function akibara_blog_image_alt( int $attachment_id, string $context_title = '' ): string {
    // 1) Editor-provided alt (explicit intent — always wins).
    if ( $attachment_id ) {
        $alt = trim( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );
        if ( $alt !== '' ) {
            return $alt;
        }
    }

    // 2) Context title from caller (the post/product title — contextually accurate).
    $context_title = trim( wp_strip_all_tags( $context_title ) );
    if ( $context_title !== '' ) {
        return $context_title;
    }

    // 3) Attachment caption / title as lower-value fallback.
    if ( $attachment_id ) {
        $attachment = get_post( $attachment_id );
        if ( $attachment ) {
            if ( $attachment->post_excerpt !== '' ) {
                return wp_strip_all_tags( $attachment->post_excerpt );
            }
            if ( $attachment->post_title !== '' && preg_match( '/\s/', $attachment->post_title ) ) {
                return $attachment->post_title;
            }
        }
    }

    return 'Akibara — manga y cómics en Chile';
}

/**
 * Global fallback so any `wp_get_attachment_image()` call gets a usable alt,
 * even when the editor forgot to set one. Does NOT overwrite existing alts.
 *
 * Filtro único del tema para `wp_get_attachment_image_attributes`:
 * Prioriza el título del producto o contenido visible y luego aplica la
 * cadena de fallback de `akibara_blog_image_alt()`, de modo que el alt nunca quede vacío.
 */
function akibara_blog_image_alt_fallback( array $attr, $attachment, $size ): array {
    if ( isset( $attr['alt'] ) && trim( $attr['alt'] ) !== '' ) {
        return $attr;
    }

    $attachment_id = is_object( $attachment ) ? (int) $attachment->ID : (int) $attachment;
    $context_title = '';

    // 1) Contexto de página: si estamos viendo un producto, su título es el alt
    //    más pertinente para el SEO de ESA página, aun cuando la imagen no esté
    //    parentada al producto (galerías con media reutilizada).
    global $post;
    if ( $post instanceof WP_Post && in_array( $post->post_type, array( 'product', 'product_variation' ), true ) ) {
        $context_title = get_the_title( $post->ID );
    } elseif ( is_singular() && get_post_thumbnail_id() === $attachment_id ) {
        // 2) Thumbnail (imagen destacada) del post actual.
        $context_title = get_the_title();
    } elseif ( is_object( $attachment ) && $attachment->post_parent ) {
        // 3) La imagen "pertenece" a su post padre.
        $context_title = get_the_title( $attachment->post_parent );
    }

    $attr['alt'] = akibara_blog_image_alt( $attachment_id, $context_title );
    return $attr;
}
add_filter( 'wp_get_attachment_image_attributes', 'akibara_blog_image_alt_fallback', 20, 3 );


/* --- Lógica de negocio movida al plugin (regla theme = solo UI) -------- */
// - Related posts: era dead code (sin llamadas) → removido.
// - Auto product links (the_content): movido al plugin akibara-business
//   (módulo Blog), que además corrige la inyección de backreference de
//   preg_replace. El theme conserva solo image_alt.

/* --- Formulario de comentarios ---------------------------------------- */

/**
 * Prepara un campo del formulario de comentarios para mostrarse con etiqueta.
 *
 * Tres cosas, en este orden:
 *
 * 1. Quita el `placeholder`. Un placeholder no es un nombre de campo: desaparece
 *    al escribir, y aquí además repetía literalmente el texto de la etiqueta.
 *    El patrón exige `\s+` delante — sin esa frontera parte `data-placeholder`
 *    por la mitad y deja un `data-` suelto en el HTML.
 * 2. Asigna una clase propia a la etiqueta en lugar de `screen-reader-text`,
 *    asegurando un flujo visual limpio en el formulario de comentarios sin
 *    colisiones de especificidad con estilos de plugins externos.
 * 3. Devuelve el asterisco de obligatorio, que vivía solo dentro del placeholder
 *    borrado. Va `aria-hidden` porque lo que anuncian los lectores de pantalla
 *    es el atributo `required` del input.
 *
 * @param string $field HTML del campo.
 * @return string
 */
function akibara_blog_prepare_comment_field( $field ) {
    if ( ! is_string( $field ) || '' === $field ) {
        return $field;
    }

    $limpio = preg_replace( '/\s+placeholder=(["\'])[^"\']*\1/', '', $field );
    if ( is_string( $limpio ) ) {
        $field = $limpio;
    }

    $etiquetado = preg_replace(
        '/(<label\b[^>]*\bclass=(["\'])[^"\']*?)\bscreen-reader-text\b/',
        '$1akb-comment-label',
        $field,
        1
    );
    if ( is_string( $etiquetado ) ) {
        $field = $etiquetado;
    }

    if ( false !== strpos( $field, ' required' ) ) {
        $marcado = preg_replace(
            '/(<label\b[^>]*>.*?)(<\/label>)/s',
            '$1 <span class="akb-required" aria-hidden="true">*</span>$2',
            $field,
            1
        );
        if ( is_string( $marcado ) ) {
            $field = $marcado;
        }
    }

    return $field;
}

/**
 * Aplica la preparación a nombre, correo y web.
 *
 * @param array<string,string> $fields Campos del formulario.
 * @return array<string,string>
 */
function akibara_blog_comment_fields_without_placeholder( $fields ) {
    if ( ! is_array( $fields ) ) {
        return $fields;
    }
    foreach ( $fields as $key => $field ) {
        $fields[ $key ] = akibara_blog_prepare_comment_field( $field );
    }

    return $fields;
}
add_filter( 'comment_form_default_fields', 'akibara_blog_comment_fields_without_placeholder', 99 );

/**
 * Y al textarea del comentario, que NO viaja en `comment_form_default_fields`.
 *
 * Se quedaba con la etiqueta `screen-reader-text` encima del área de texto —el
 * campo más grande del formulario y el único imprescindible— mientras los otros
 * tres ya estaban corregidos.
 *
 * @param array<string,mixed> $defaults Argumentos de `comment_form()`.
 * @return array<string,mixed>
 */
function akibara_blog_comment_textarea_label( $defaults ) {
    if ( ! is_array( $defaults ) || ! isset( $defaults['comment_field'] ) ) {
        return $defaults;
    }
    $defaults['comment_field'] = akibara_blog_prepare_comment_field( $defaults['comment_field'] );

    return $defaults;
}
add_filter( 'comment_form_defaults', 'akibara_blog_comment_textarea_label', 99 );

/**
 * Puente desde la búsqueda de WordPress al buscador de la tienda.
 *
 * `/?s=algo` es la búsqueda nativa de WordPress y sólo mira entradas: el motor
 * propio indexa 1.568 productos y CERO posts, así que quien llega ahí buscando
 * un tomo recibe una página que dice «Resultados de la búsqueda» con tres
 * artículos del blog y ni un libro. En una tienda de manga eso se lee como «no
 * lo tenemos».
 *
 * No se redirige: las guías y las entradas sólo se encuentran por esta vía, y
 * mandarlo todo a `/tienda/` las dejaría inalcanzables. Se ofrece el camino, con
 * el término ya escrito, y quien buscaba un artículo sigue teniéndolo debajo.
 *
 * Ningún formulario propio apunta aquí —la cabecera, el cajón móvil y el 404 ya
 * van a `/tienda/`, y el `SearchAction` del JSON-LD también—, así que esto cubre
 * la entrada de fuera: la barra del navegador, un marcador viejo, un enlace
 * ajeno.
 *
 * @return void
 */
function akibara_search_bridge_to_shop() {
    if ( ! is_search() || is_admin() ) {
        return;
    }
    // La búsqueda de productos ya vive en el motor propio; este puente es sólo
    // para la búsqueda nativa de entradas.
    if ( 'product' === get_query_var( 'post_type' ) ) {
        return;
    }

    $term = get_search_query();
    if ( ! is_string( $term ) || '' === trim( $term ) ) {
        return;
    }

    if ( ! function_exists( 'wc_get_page_permalink' ) ) {
        return;
    }
    $shop = wc_get_page_permalink( 'shop' );
    if ( ! is_string( $shop ) || '' === $shop ) {
        return;
    }

    $url = add_query_arg( '_buscar', rawurlencode( $term ), $shop );
    ?>
    <aside class="akb-search-bridge">
        <p class="akb-search-bridge__text">
            ¿Buscabas un tomo? Esta página busca en el blog.
        </p>
        <a class="akb-search-bridge__cta" href="<?php echo esc_url( $url ); ?>">
            Buscar «<?php echo esc_html( $term ); ?>» en la tienda
        </a>
    </aside>
    <?php
}
add_action( 'generate_before_main_content', 'akibara_search_bridge_to_shop' );
