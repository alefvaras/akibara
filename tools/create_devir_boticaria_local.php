<?php
/**
 * Crea e integra los 16 tomos de Los diarios de la boticaria (Devir) en el entorno Docker local.
 * Cumpliendo estrictamente con las 18 Reglas de Catálogo de AGENTS.md.
 */

if ( ! defined( 'ABSPATH' ) ) {
    require_once __DIR__ . '/../public_html/wp-load.php';
}

$json_path = WP_CONTENT_DIR . '/devir_boticaria_data.json';
if ( ! file_exists( $json_path ) ) {
    die( "No se encontró devir_boticaria_data.json\n" );
}
$data = json_decode( file_get_contents( $json_path ), true );

$paginas_map = array(
    1  => 184,
    2  => 184,
    3  => 200,
    4  => 200,
    5  => 184,
    6  => 184,
    7  => 184,
    8  => 184,
    9  => 184,
    10 => 184,
    11 => 176,
    12 => 184,
    13 => 184,
    14 => 184,
    15 => 176,
    16 => 168,
);

$brand_id    = 820; // Devir
$pais_id     = 154; // España
$cat_manga   = 28;  // Manga
$cat_seinen  = 47;  // Seinen
$genero_ids  = array( 324, 327, 335 ); // Drama, Misterio, Histórico
$author_ids  = array( 458, 821 );      // Natsu Hyuuga, Nekokurage
$serie_id    = 822; // Los diarios de la boticaria (Devir)

$created_product_ids = array();

for ( $num = 1; $num <= 16; $num++ ) {
    $num_pad  = sprintf( '%02d', $num );
    $slug     = "los-diarios-de-la-boticaria-{$num}-devir";
    $title    = "Los diarios de la boticaria {$num_pad} (Devir)";
    $item     = isset( $data[ (string) $num ] ) ? $data[ (string) $num ] : $data[ $num ];
    $ean      = preg_replace( '/[^0-9]/', '', $item['ean'] );
    $desc_raw = $item['desc'];
    $paginas  = isset( $paginas_map[ $num ] ) ? $paginas_map[ $num ] : 184;

    // Generar excerpt limpio de 180-260 caracteres
    // Tomar primer enunciado de la sinopsis
    $first_sentence = preg_split( '/(?<=[.?!])\s+/', $desc_raw )[0];
    if ( mb_strlen( $first_sentence ) > 130 ) {
        $first_sentence = mb_substr( $first_sentence, 0, 125 ) . '...';
    }
    $excerpt = "<strong>{$title}</strong>: {$first_sentence} Edición oficial importada desde España con reserva con 5% de descuento y despacho rápido a todo Chile.";

    // Contenido estructurado con H2 exacto, sinopsis y enlazado interno canónico (Reglas 2 y 5 de AGENTS.md)
    // Las especificaciones se renderizan automáticamente en .akb-pdp-specs-grid vía PdpEnhancer
    $content = "<h2>{$title}</h2>\n" .
        "<p>" . esc_html( $desc_raw ) . "</p>\n" .
        "<p class=\"akb-pdp-seo-links\">Explora la secuencia completa en <a href=\"/serie/los-diarios-de-la-boticaria-devir/\">Los diarios de la boticaria (Devir)</a> o visita el catálogo oficial de <a href=\"/marca/devir/\">Devir</a> en Akibara.</p>";

    // Comprobar si ya existe el producto
    $existing = get_page_by_path( $slug, OBJECT, 'product' );
    if ( $existing ) {
        $post_id = $existing->ID;
        wp_update_post( array(
            'ID'           => $post_id,
            'post_title'   => $title,
            'post_content' => $content,
            'post_excerpt' => $excerpt,
            'post_status'  => 'publish',
        ) );
        echo "Actualizado producto {$title} (ID {$post_id})\n";
    } else {
        $post_id = wp_insert_post( array(
            'post_title'   => $title,
            'post_name'    => $slug,
            'post_content' => $content,
            'post_excerpt' => $excerpt,
            'post_status'  => 'publish',
            'post_type'    => 'product',
        ) );
        if ( is_wp_error( $post_id ) ) {
            echo "Error creando {$title}: " . $post_id->get_error_message() . "\n";
            continue;
        }
        echo "Creado producto {$title} (ID {$post_id})\n";
    }

    $created_product_ids[] = $post_id;

    // Asignar términos de taxonomía
    wp_set_object_terms( $post_id, 'simple', 'product_type' );
    wp_set_object_terms( $post_id, array( $brand_id ), 'product_brand' );
    wp_set_object_terms( $post_id, array( $pais_id ), 'pa_pais' );
    wp_set_object_terms( $post_id, $author_ids, 'pa_autor' );
    wp_set_object_terms( $post_id, array( $serie_id ), 'pa_serie' );
    wp_set_object_terms( $post_id, $genero_ids, 'pa_genero' );
    wp_set_object_terms( $post_id, array( $cat_manga, $cat_seinen ), 'product_cat' );

    // Asegurar visibilidad: remover outofstock de product_visibility
    wp_remove_object_terms( $post_id, 'outofstock', 'product_visibility' );

    // Atributos de producto para la grilla nativa de WooCommerce (pa_pais, pa_autor, pa_genero, pa_serie)
    // REGLA 9: product_brand NUNCA en _product_attributes
    $product_attributes = array(
        'pa_pais' => array(
            'name'         => 'pa_pais',
            'value'        => '',
            'position'     => 0,
            'is_visible'   => 1,
            'is_variation' => 0,
            'is_taxonomy'  => 1,
        ),
        'pa_autor' => array(
            'name'         => 'pa_autor',
            'value'        => '',
            'position'     => 1,
            'is_visible'   => 1,
            'is_variation' => 0,
            'is_taxonomy'  => 1,
        ),
        'pa_serie' => array(
            'name'         => 'pa_serie',
            'value'        => '',
            'position'     => 2,
            'is_visible'   => 1,
            'is_variation' => 0,
            'is_taxonomy'  => 1,
        ),
        'pa_genero' => array(
            'name'         => 'pa_genero',
            'value'        => '',
            'position'     => 3,
            'is_visible'   => 1,
            'is_variation' => 0,
            'is_taxonomy'  => 1,
        ),
    );
    update_post_meta( $post_id, '_product_attributes', $product_attributes );

    // Precios con 5% de descuento obligatorio de reserva (Reglas 8 y 18)
    $reg_price  = 16500;
    $sale_price = (int) round( $reg_price * 0.95 ); // 15675

    update_post_meta( $post_id, '_regular_price', (string) $reg_price );
    update_post_meta( $post_id, '_sale_price', (string) $sale_price );
    update_post_meta( $post_id, '_price', (string) $sale_price );

    // Inventario onbackorder
    update_post_meta( $post_id, '_manage_stock', 'no' );
    update_post_meta( $post_id, '_stock_status', 'onbackorder' );
    update_post_meta( $post_id, '_backorders', 'notify' );
    delete_post_meta( $post_id, '_stock' );

    // Identificadores comerciales repiten el SKU (Regla 3)
    update_post_meta( $post_id, '_sku', $ean );
    update_post_meta( $post_id, '_global_unique_id', $ean );
    update_post_meta( $post_id, '_gtin', $ean );
    update_post_meta( $post_id, '_barcode', $ean );

    // Dimensiones y peso de envío
    update_post_meta( $post_id, '_weight', '0.25' );
    update_post_meta( $post_id, '_length', '18' );
    update_post_meta( $post_id, '_width', '12.8' );
    update_post_meta( $post_id, '_height', '1.5' );

    // SEO Rank Math Estricto (Regla 13)
    $focus_kw = $title;
    $meta_desc = "Consigue {$title} en Chile. Manga oficial en español con reserva con 5% de descuento y despacho rápido a todo el país.";
    if ( mb_strlen( $meta_desc ) > 155 ) {
        $meta_desc = mb_substr( $meta_desc, 0, 152 ) . '...';
    }

    update_post_meta( $post_id, 'rank_math_focus_keyword', $focus_kw );
    update_post_meta( $post_id, 'rank_math_title', "{$title} • Manga Oficial | Akibara" );
    update_post_meta( $post_id, 'rank_math_description', $meta_desc );

    // Adjuntar portada AVIF como thumbnail_id
    $cover_rel = "portadas/{$slug}.avif";
    $cover_abs = WP_CONTENT_DIR . "/uploads/{$cover_rel}";
    $cover_url = content_url( "/uploads/{$cover_rel}" );

    global $wpdb;
    $att_id = $wpdb->get_var( $wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND (guid LIKE %s OR post_title = %s)",
        "%{$slug}.avif",
        $title
    ) );

    if ( ! $att_id ) {
        $att_data = array(
            'guid'           => $cover_url,
            'post_mime_type' => 'image/avif',
            'post_title'     => $title,
            'post_content'   => '',
            'post_status'    => 'inherit',
        );
        $att_id = wp_insert_attachment( $att_data, $cover_abs, $post_id );
        if ( ! is_wp_error( $att_id ) ) {
            update_post_meta( $att_id, '_wp_attached_file', $cover_rel );
            update_post_meta( $att_id, '_wp_attachment_image_alt', $title );
            set_post_thumbnail( $post_id, $att_id );
            echo "  Portada adjunta ID {$att_id}\n";
        }
    } else {
        update_post_meta( $att_id, '_wp_attached_file', $cover_rel );
        update_post_meta( $att_id, '_wp_attachment_image_alt', $title );
        set_post_thumbnail( $post_id, $att_id );
        echo "  Portada existente asignada ID {$att_id}\n";
    }
}

echo "Todos los tomos procesados exitosamente (" . count( $created_product_ids ) . " tomos).\n";
