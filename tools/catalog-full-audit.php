<?php
/**
 * Akibara Full Catalog QA Audit Script
 * Executes deep, adversarial checks across ALL published products and series.
 */

if ( ! defined( 'ABSPATH' ) ) {
    if ( file_exists( 'wp-load.php' ) ) {
        require_once 'wp-load.php';
    } elseif ( file_exists( __DIR__ . '/../public_html/wp-load.php' ) ) {
        require_once __DIR__ . '/../public_html/wp-load.php';
    }
}

$env = wp_get_environment_type();
$is_prod = ( 'production' === $env );

$report = array(
    'environment' => $env,
    'timestamp'   => current_time( 'mysql' ),
    'series'      => array(
        'total' => 0,
        'valid_canonical_status' => 0,
        'invalid_status_keys'    => array(),
        'missing_termmeta'       => array(),
        'empty_descriptions'     => array(),
        'forbidden_mentions'     => array(),
        'external_links'         => array(),
        'missing_structured_specs' => array(),
        'series_without_products'  => array(),
    ),
    'products'    => array(
        'total' => 0,
        'rule9_attribute_hygiene_fails' => array(),
        'rule14_empty_excerpt_fails'    => array(),
        'rule14_excerpt_no_strong'      => array(),
        'rule8_onbackorder_discount_fails' => array(),
        'rule15_outofstock_sale_price_fails' => array(),
        'rule3_sku_missing'             => array(),
        'rule3_gtin_mismatch'           => array(),
        'rule1_norma_mentions'          => array(),
        'rule2_external_links'          => array(),
        'rule4_missing_thumbnail'       => array(),
        'rule4_missing_alt'             => array(),
        'missing_genero'                => array(),
        'missing_category'              => array(),
        'author_with_macrons'           => array(),
    ),
);

global $wpdb;

// ==========================================
// 1. AUDIT ALL SERIES (pa_serie)
// ==========================================
$valid_statuses = array( 'completa', 'en_publicacion', 'en_pausa', 'cancelada' );
$valid_edicion_statuses = array( 'completa', 'en_publicacion', 'al_dia', 'en_pausa', 'cancelada' );

$series_terms = get_terms( array(
    'taxonomy'   => 'pa_serie',
    'hide_empty' => false,
) );

$report['series']['total'] = count( $series_terms );

foreach ( $series_terms as $term ) {
    $term_id = $term->term_id;
    $slug    = $term->slug;
    $name    = $term->name;
    $count   = (int) $term->count;

    // Check products count
    if ( $count === 0 ) {
        $report['series']['series_without_products'][] = array(
            'id'   => $term_id,
            'name' => $name,
            'slug' => $slug,
        );
    }

    // Check termmeta
    $estado         = get_term_meta( $term_id, '_akb_serie_estado', true );
    $estado_edicion = get_term_meta( $term_id, '_akb_serie_estado_edicion', true );
    $tomos_obra     = get_term_meta( $term_id, '_akb_serie_tomos_obra', true );
    $tomos_edicion  = get_term_meta( $term_id, '_akb_serie_tomos_edicion', true );

    if ( ! empty( $estado ) && ! in_array( $estado, $valid_statuses, true ) ) {
        $report['series']['invalid_status_keys'][] = array(
            'id'    => $term_id,
            'slug'  => $slug,
            'field' => '_akb_serie_estado',
            'value' => $estado,
        );
    }

    if ( ! empty( $estado_edicion ) && ! in_array( $estado_edicion, $valid_edicion_statuses, true ) ) {
        $report['series']['invalid_status_keys'][] = array(
            'id'    => $term_id,
            'slug'  => $slug,
            'field' => '_akb_serie_estado_edicion',
            'value' => $estado_edicion,
        );
    }

    if ( empty( $estado ) && empty( $estado_edicion ) ) {
        $report['series']['missing_termmeta'][] = array(
            'id'   => $term_id,
            'slug' => $slug,
        );
    } else {
        $report['series']['valid_canonical_status']++;
    }

    // Description editorial quality
    $desc = $term->description;
    if ( empty( trim( $desc ) ) ) {
        $report['series']['empty_descriptions'][] = array(
            'id'   => $term_id,
            'slug' => $slug,
        );
    } else {
        if ( stripos( $desc, 'Norma Editorial' ) !== false ) {
            $report['series']['forbidden_mentions'][] = array(
                'id'   => $term_id,
                'slug' => $slug,
                'type' => 'Norma Editorial in description',
            );
        }
        if ( preg_match( '/https?:\/\/(?!akibara\.cl)[a-zA-Z0-9.\-_]+(?:\.es|\.com|\.net)/i', $desc, $m ) ) {
            $report['series']['external_links'][] = array(
                'id'   => $term_id,
                'slug' => $slug,
                'link' => $m[0],
            );
        }
        // Check structured specs
        $has_specs = ( stripos( $desc, 'Autor' ) !== false || stripos( $desc, 'Dibujo' ) !== false || stripos( $desc, 'Estado en' ) !== false );
        if ( ! $has_specs && $count > 0 ) {
            $report['series']['missing_structured_specs'][] = array(
                'id'   => $term_id,
                'slug' => $slug,
            );
        }
    }
}

// ==========================================
// 2. AUDIT ALL PUBLISHED PRODUCTS
// ==========================================
$products = $wpdb->get_results( "
    SELECT ID, post_title, post_name, post_excerpt, post_content
    FROM {$wpdb->posts}
    WHERE post_type = 'product' AND post_status = 'publish'
    ORDER BY ID ASC
" );

$report['products']['total'] = count( $products );

// Batch fetch postmeta for speed
$post_ids = wp_list_pluck( $products, 'ID' );

// We can query postmeta efficiently
$lookup_data = array();
if ( ! empty( $post_ids ) ) {
    $chunks = array_chunk( $post_ids, 500 );
    foreach ( $chunks as $chunk ) {
        $ids_str = implode( ',', array_map( 'intval', $chunk ) );
        $metas = $wpdb->get_results( "
            SELECT post_id, meta_key, meta_value
            FROM {$wpdb->postmeta}
            WHERE post_id IN ($ids_str)
            AND meta_key IN (
                '_sku', '_gtin', '_barcode', '_global_unique_id',
                '_regular_price', '_sale_price', '_price',
                '_stock_status', '_product_attributes', '_thumbnail_id'
            )
        " );
        foreach ( $metas as $m ) {
            $lookup_data[ $m->post_id ][ $m->meta_key ] = $m->meta_value;
        }
    }
}

// Taxonomy cache
$all_terms_by_post = array();
if ( ! empty( $post_ids ) ) {
    $chunks = array_chunk( $post_ids, 500 );
    foreach ( $chunks as $chunk ) {
        $ids_str = implode( ',', array_map( 'intval', $chunk ) );
        $terms_rows = $wpdb->get_results( "
            SELECT tr.object_id, tt.taxonomy, t.name, t.slug
            FROM {$wpdb->term_relationships} tr
            INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
            INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
            WHERE tr.object_id IN ($ids_str)
        " );
        foreach ( $terms_rows as $tr ) {
            $all_terms_by_post[ $tr->object_id ][ $tr->taxonomy ][] = array(
                'name' => $tr->name,
                'slug' => $tr->slug,
            );
        }
    }
}

foreach ( $products as $prod ) {
    $id = (int) $prod->ID;
    $title = $prod->post_title;
    $slug  = $prod->post_name;
    $meta  = isset( $lookup_data[ $id ] ) ? $lookup_data[ $id ] : array();
    $p_terms = isset( $all_terms_by_post[ $id ] ) ? $all_terms_by_post[ $id ] : array();

    // 1. Regla 9: product_brand in _product_attributes
    if ( ! empty( $meta['_product_attributes'] ) ) {
        $attrs = maybe_unserialize( $meta['_product_attributes'] );
        if ( is_array( $attrs ) && isset( $attrs['product_brand'] ) ) {
            $report['products']['rule9_attribute_hygiene_fails'][] = array(
                'id'   => $id,
                'slug' => $slug,
            );
        }
    }

    // 2. Regla 14: post_excerpt
    $excerpt = trim( $prod->post_excerpt );
    if ( empty( $excerpt ) ) {
        $report['products']['rule14_empty_excerpt_fails'][] = array(
            'id'   => $id,
            'slug' => $slug,
        );
    } else {
        if ( stripos( $excerpt, '<strong' ) === false ) {
            $report['products']['rule14_excerpt_no_strong'][] = array(
                'id'   => $id,
                'slug' => $slug,
                'preview' => mb_substr( strip_tags( $excerpt ), 0, 50 ),
            );
        }
    }

    // 3. Regla 8: onbackorder 5% discount
    $stock_status = isset( $meta['_stock_status'] ) ? $meta['_stock_status'] : '';
    $reg_price = isset( $meta['_regular_price'] ) ? (float) $meta['_regular_price'] : 0.0;
    $sale_price = isset( $meta['_sale_price'] ) && '' !== $meta['_sale_price'] ? (float) $meta['_sale_price'] : null;
    $price = isset( $meta['_price'] ) ? (float) $meta['_price'] : 0.0;

    if ( 'onbackorder' === $stock_status ) {
        if ( $reg_price > 0 ) {
            $expected_sale = round( $reg_price * 0.95 );
            if ( null === $sale_price || abs( $sale_price - $expected_sale ) > 2.0 || abs( $price - $expected_sale ) > 2.0 ) {
                $report['products']['rule8_onbackorder_discount_fails'][] = array(
                    'id'            => $id,
                    'slug'          => $slug,
                    'regular_price' => $reg_price,
                    'sale_price'    => $sale_price,
                    'price'         => $price,
                    'expected_sale' => $expected_sale,
                );
            }
        }
    }

    // 4. Regla 15: outofstock
    if ( 'outofstock' === $stock_status ) {
        if ( null !== $sale_price && '' !== $meta['_sale_price'] ) {
            $report['products']['rule15_outofstock_sale_price_fails'][] = array(
                'id'         => $id,
                'slug'       => $slug,
                'sale_price' => $sale_price,
            );
        }
    }

    // 5. Regla 3: SKU & GTIN
    $sku = isset( $meta['_sku'] ) ? trim( $meta['_sku'] ) : '';
    if ( empty( $sku ) ) {
        $report['products']['rule3_sku_missing'][] = array(
            'id'   => $id,
            'slug' => $slug,
        );
    } else {
        $gtin = isset( $meta['_gtin'] ) ? trim( $meta['_gtin'] ) : '';
        $barcode = isset( $meta['_barcode'] ) ? trim( $meta['_barcode'] ) : '';
        $guid = isset( $meta['_global_unique_id'] ) ? trim( $meta['_global_unique_id'] ) : '';
        // If neither GTIN nor barcode is set, flag it
        if ( empty( $gtin ) && empty( $barcode ) && empty( $guid ) ) {
            $report['products']['rule3_gtin_mismatch'][] = array(
                'id'   => $id,
                'slug' => $slug,
                'sku'  => $sku,
                'reason' => 'gtin/barcode empty',
            );
        }
    }

    // 6. Regla 1 & 2: Norma editorial and external links in content/excerpt
    $full_text = $prod->post_title . ' ' . $prod->post_excerpt . ' ' . $prod->post_content;
    if ( stripos( $full_text, 'Norma Editorial' ) !== false ) {
        $report['products']['rule1_norma_mentions'][] = array(
            'id'   => $id,
            'slug' => $slug,
        );
    }
    if ( preg_match( '/https?:\/\/(?!akibara\.cl)[a-zA-Z0-9.\-_]+(?:\.es|\.com|\.net)\/[a-zA-Z0-9_\-\/]+/i', $prod->post_content, $m ) ) {
        $report['products']['rule2_external_links'][] = array(
            'id'   => $id,
            'slug' => $slug,
            'link' => $m[0],
        );
    }

    // 7. Regla 4: Portadas
    $thumb_id = isset( $meta['_thumbnail_id'] ) ? (int) $meta['_thumbnail_id'] : 0;
    if ( $thumb_id <= 0 ) {
        $report['products']['rule4_missing_thumbnail'][] = array(
            'id'   => $id,
            'slug' => $slug,
        );
    } else {
        $alt = get_post_meta( $thumb_id, '_wp_attachment_image_alt', true );
        if ( empty( trim( $alt ) ) ) {
            $report['products']['rule4_missing_alt'][] = array(
                'id'       => $id,
                'thumb_id' => $thumb_id,
                'slug'     => $slug,
            );
        }
    }

    // 8. Taxonomies
    if ( empty( $p_terms['pa_genero'] ) ) {
        $report['products']['missing_genero'][] = array(
            'id'   => $id,
            'slug' => $slug,
        );
    }
    if ( empty( $p_terms['product_cat'] ) ) {
        $report['products']['missing_category'][] = array(
            'id'   => $id,
            'slug' => $slug,
        );
    }

    // Author macrons
    if ( ! empty( $p_terms['pa_autor'] ) ) {
        foreach ( $p_terms['pa_autor'] as $author ) {
            if ( preg_match( '/[ōūāēīŌŪĀĒĪ]/u', $author['name'] ) ) {
                $report['products']['author_with_macrons'][] = array(
                    'id'          => $id,
                    'slug'        => $slug,
                    'author_name' => $author['name'],
                );
            }
        }
    }
}

echo json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
