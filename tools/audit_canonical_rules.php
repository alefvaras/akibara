<?php
/**
 * Canonical 21 Rules, Search Engine & SEO Deep Audit for Akibara
 * Runs read-only checks on both Local and Production.
 */

if ( ! defined( 'ABSPATH' ) ) {
    if ( file_exists( 'wp-load.php' ) ) {
        require_once 'wp-load.php';
    } elseif ( file_exists( __DIR__ . '/../public_html/wp-load.php' ) ) {
        require_once __DIR__ . '/../public_html/wp-load.php';
    }
}

global $wpdb;

$env = wp_get_environment_type();

$audit = array(
    'environment' => $env,
    'site_url'    => get_site_url(),
    'timestamp'   => current_time( 'mysql' ),
    'summary'     => array(),
    'search_engine' => array(),
    'rules'       => array(),
);

// -------------------------------------------------------------
// MOTOR AKIBARA-SEARCH
// -------------------------------------------------------------
$search_docs   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}akb_search_docs" );
$search_tokens = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}akb_search_tokens" );
$search_facets = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}akb_search_facet_index" );
$search_qlog   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}akb_search_qlog" );

$last_rebuild = get_option( 'akb_search_rebuild_state', array() );
$last_receipt = get_option( 'akb_search_editorial_receipt', array() );
$last_reconcile = get_option( 'akb_search_reconcile_receipt', array() );

$audit['search_engine'] = array(
    'docs_count'     => $search_docs,
    'tokens_count'   => $search_tokens,
    'facets_count'   => $search_facets,
    'qlog_count'     => $search_qlog,
    'rebuild_state'  => $last_rebuild,
    'reconcile_state'=> $last_reconcile,
);

// -------------------------------------------------------------
// LOAD ALL PUBLISHED PRODUCTS & METAS
// -------------------------------------------------------------
$products = $wpdb->get_results( "
    SELECT ID, post_title, post_name, post_excerpt, post_content
    FROM {$wpdb->posts}
    WHERE post_type = 'product' AND post_status = 'publish'
    ORDER BY ID ASC
" );

$audit['summary']['total_published_products'] = count( $products );
$post_ids = wp_list_pluck( $products, 'ID' );

// Batch fetch postmeta
$lookup_meta = array();
if ( ! empty( $post_ids ) ) {
    $chunks = array_chunk( $post_ids, 500 );
    foreach ( $chunks as $chunk ) {
        $ids_str = implode( ',', array_map( 'intval', $chunk ) );
        $rows = $wpdb->get_results( "
            SELECT post_id, meta_key, meta_value
            FROM {$wpdb->postmeta}
            WHERE post_id IN ($ids_str)
            AND meta_key IN (
                '_sku', '_gtin', '_barcode', '_global_unique_id',
                '_regular_price', '_sale_price', '_price',
                '_stock_status', '_manage_stock', '_stock', '_backorders',
                '_product_attributes', '_thumbnail_id', '_upsell_ids',
                'rank_math_focus_keyword', 'rank_math_title', 'rank_math_description',
                'rank_math_seo_score'
            )
        " );
        foreach ( $rows as $r ) {
            $lookup_meta[ $r->post_id ][ $r->meta_key ] = $r->meta_value;
        }
    }
}

// Batch fetch terms
$product_terms = array();
if ( ! empty( $post_ids ) ) {
    $chunks = array_chunk( $post_ids, 500 );
    foreach ( $chunks as $chunk ) {
        $ids_str = implode( ',', array_map( 'intval', $chunk ) );
        $rows = $wpdb->get_results( "
            SELECT tr.object_id, tt.taxonomy, t.term_id, t.name, t.slug
            FROM {$wpdb->term_relationships} tr
            INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
            INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
            WHERE tr.object_id IN ($ids_str)
        " );
        foreach ( $rows as $r ) {
            $product_terms[ $r->object_id ][ $r->taxonomy ][] = array(
                'term_id' => (int) $r->term_id,
                'name'    => $r->name,
                'slug'    => $r->slug,
            );
        }
    }
}

// -------------------------------------------------------------
// REGLA 1: Norma Editorial NO existe en Akibara
// -------------------------------------------------------------
$regla1_fails = array();
foreach ( $products as $p ) {
    $haystack = $p->post_title . ' ' . $p->post_excerpt . ' ' . $p->post_content;
    if ( stripos( $haystack, 'Norma Editorial' ) !== false || preg_match( '/\bNorma\b/i', $p->post_title ) ) {
        // Exclude innocent mentions if any, but Norma Editorial is strictly forbidden
        if ( stripos( $haystack, 'Norma Editorial' ) !== false ) {
            $regla1_fails[] = array( 'id' => $p->ID, 'slug' => $p->post_name, 'title' => $p->post_title, 'location' => 'product content/excerpt/title' );
        }
    }
}

// Series with Norma
$series_terms = get_terms( array( 'taxonomy' => 'pa_serie', 'hide_empty' => false ) );
$series_norma = array();
foreach ( $series_terms as $st ) {
    if ( stripos( $st->name . ' ' . $st->description, 'Norma Editorial' ) !== false ) {
        $series_norma[] = array( 'id' => $st->term_id, 'slug' => $st->slug, 'name' => $st->name );
    }
}

// Brands with Norma
$brands_terms = get_terms( array( 'taxonomy' => 'product_brand', 'hide_empty' => false ) );
$brands_norma = array();
foreach ( $brands_terms as $bt ) {
    if ( stripos( $bt->name . ' ' . $bt->description, 'Norma' ) !== false ) {
        $brands_norma[] = array( 'id' => $bt->term_id, 'slug' => $bt->slug, 'name' => $bt->name );
    }
}

$audit['rules']['regla_1_norma_editorial'] = array(
    'product_violations' => count( $regla1_fails ),
    'series_violations'  => count( $series_norma ),
    'brand_violations'   => count( $brands_norma ),
    'details' => array(
        'products' => array_slice( $regla1_fails, 0, 10 ),
        'series'   => $series_norma,
        'brands'   => $brands_norma,
    ),
);

// -------------------------------------------------------------
// REGLA 2: Cero enlaces externos a editoriales en fichas
// -------------------------------------------------------------
$regla2_fails = array();
$external_domains_regex = '/https?:\/\/(?:www\.)?(?:editorialivrea\.com|panini\.es|planetadelibros\.com|milkywayediciones\.com|arechilibros\.com|distritomanga\.com|normaeditorial\.com|ovnipress\.com\.ar)[^\s"\'<>]+/i';

foreach ( $products as $p ) {
    if ( preg_match_all( $external_domains_regex, $p->post_content . ' ' . $p->post_excerpt, $matches ) ) {
        $regla2_fails[] = array(
            'id'    => $p->ID,
            'slug'  => $p->post_name,
            'links' => array_unique( $matches[0] ),
        );
    }
}

// Also check series descriptions
$series_ext_links = array();
foreach ( $series_terms as $st ) {
    if ( preg_match_all( $external_domains_regex, $st->description, $matches ) ) {
        $series_ext_links[] = array(
            'id'    => $st->term_id,
            'slug'  => $st->slug,
            'links' => array_unique( $matches[0] ),
        );
    }
}

$audit['rules']['regla_2_cero_enlaces_externos'] = array(
    'product_violations' => count( $regla2_fails ),
    'series_violations'  => count( $series_ext_links ),
    'details' => array(
        'products' => array_slice( $regla2_fails, 0, 10 ),
        'series'   => $series_ext_links,
    ),
);

// -------------------------------------------------------------
// REGLA 3: Identificadores comerciales (GTIN / Barcode) repiten SKU
// -------------------------------------------------------------
$regla3_missing_sku   = array();
$regla3_missing_gtin  = array();
$regla3_gtin_mismatch = array();

foreach ( $products as $p ) {
    $id = $p->ID;
    $m  = isset( $lookup_meta[$id] ) ? $lookup_meta[$id] : array();
    $sku = isset( $m['_sku'] ) ? trim( $m['_sku'] ) : '';
    $gtin = isset( $m['_gtin'] ) ? trim( $m['_gtin'] ) : '';
    $barcode = isset( $m['_barcode'] ) ? trim( $m['_barcode'] ) : '';
    $guid = isset( $m['_global_unique_id'] ) ? trim( $m['_global_unique_id'] ) : '';

    if ( empty( $sku ) ) {
        $regla3_missing_sku[] = array( 'id' => $id, 'slug' => $p->post_name );
        continue;
    }

    $active_gtin = ! empty( $guid ) ? $guid : ( ! empty( $gtin ) ? $gtin : $barcode );

    if ( empty( $active_gtin ) ) {
        // Regla 3 permite _global_unique_id vacío solo en packs/variantes internas sin código editorial único.
        if ( 1 === preg_match( '/^\d{13}$/', $sku ) ) {
            $regla3_missing_gtin[] = array( 'id' => $id, 'slug' => $p->post_name, 'sku' => $sku );
        }
    } elseif ( ! preg_match( '/^\d{13}$/', preg_replace( '/[\s\-]+/', '', $active_gtin ) ) ) {
        // If not matching SKU and not a real 13-digit EAN, note it
        $regla3_gtin_mismatch[] = array( 'id' => $id, 'slug' => $p->post_name, 'sku' => $sku, 'active_gtin' => $active_gtin );
    }
}

$audit['rules']['regla_3_gtin_sku'] = array(
    'missing_sku_count'   => count( $regla3_missing_sku ),
    'missing_gtin_count'  => count( $regla3_missing_gtin ),
    'gtin_mismatch_count' => count( $regla3_gtin_mismatch ),
    'sample_missing_gtin' => array_slice( $regla3_missing_gtin, 0, 10 ),
    'sample_mismatch'     => array_slice( $regla3_gtin_mismatch, 0, 10 ),
);

// -------------------------------------------------------------
// REGLA 8 & 18: Descuento 5% Reserva con round_100 & Blindaje Inventario
// -------------------------------------------------------------
$regla8_onbackorder_total = 0;
$regla8_discount_ok       = 0;
$regla8_not_rounded_100   = array();
$regla8_wrong_discount    = array();
$regla8_no_sale_price     = array();

$regla18_instock_count    = 0;
$regla18_onbackorder_count= 0;
$regla18_outofstock_count = 0;
$regla18_contract_fails   = array();

// Also check product_visibility terms
$terms_vis = wp_get_object_terms( $post_ids, 'product_visibility', array( 'fields' => 'all_with_object_id' ) );
$vis_outofstock_map = array();
if ( ! is_wp_error( $terms_vis ) ) {
    foreach ( $terms_vis as $vt ) {
        if ( $vt->slug === 'outofstock' ) {
            $vis_outofstock_map[ (int) $vt->object_id ] = true;
        }
    }
}

foreach ( $products as $p ) {
    $id = $p->ID;
    $m  = isset( $lookup_meta[$id] ) ? $lookup_meta[$id] : array();
    $status = isset( $m['_stock_status'] ) ? $m['_stock_status'] : 'instock';
    $manage = isset( $m['_manage_stock'] ) ? $m['_manage_stock'] : 'no';
    $qty    = isset( $m['_stock'] ) ? $m['_stock'] : '';
    $backorders = isset( $m['_backorders'] ) ? $m['_backorders'] : 'no';
    $reg    = isset( $m['_regular_price'] ) ? (float) $m['_regular_price'] : 0.0;
    $sale   = isset( $m['_sale_price'] ) && '' !== $m['_sale_price'] ? (float) $m['_sale_price'] : null;
    $price  = isset( $m['_price'] ) ? (float) $m['_price'] : 0.0;
    $has_oos_vis = isset( $vis_outofstock_map[$id] );

    if ( 'instock' === $status ) {
        $regla18_instock_count++;
        if ( 'yes' !== $manage || ! is_numeric( $qty ) || (float) $qty <= 0 ) {
            $regla18_contract_fails[] = array( 'id' => $id, 'slug' => $p->post_name, 'status' => 'instock', 'issue' => 'manage_stock!=yes or qty<=0', 'manage' => $manage, 'qty' => $qty );
        }
        if ( $has_oos_vis ) {
            $regla18_contract_fails[] = array( 'id' => $id, 'slug' => $p->post_name, 'status' => 'instock', 'issue' => 'instock has product_visibility outofstock' );
        }
    } elseif ( 'onbackorder' === $status ) {
        $regla18_onbackorder_count++;
        $regla8_onbackorder_total++;

        // Stock contract checks
        if ( 'no' !== $manage || ( $qty !== '' && $qty !== null && (float) $qty != 0 ) ) {
            $regla18_contract_fails[] = array( 'id' => $id, 'slug' => $p->post_name, 'status' => 'onbackorder', 'issue' => 'manage_stock!=no or qty!=0', 'manage' => $manage, 'qty' => $qty );
        }
        if ( $has_oos_vis ) {
            $regla18_contract_fails[] = array( 'id' => $id, 'slug' => $p->post_name, 'status' => 'onbackorder', 'issue' => 'onbackorder has product_visibility outofstock' );
        }

        // Regla 8: Gobierno Nativo de WooCommerce (el dueño fija descuentos libremente; no forzar 5%).
        $regla8_discount_ok++;
    } elseif ( 'outofstock' === $status ) {
        $regla18_outofstock_count++;
        if ( null !== $sale ) {
            $regla18_contract_fails[] = array( 'id' => $id, 'slug' => $p->post_name, 'status' => 'outofstock', 'issue' => 'residual sale_price on outofstock', 'sale' => $sale );
        }
        if ( ! $has_oos_vis ) {
            $regla18_contract_fails[] = array( 'id' => $id, 'slug' => $p->post_name, 'status' => 'outofstock', 'issue' => 'missing product_visibility outofstock' );
        }
    }
}

$audit['rules']['regla_8_descuento_reserva_round100'] = array(
    'total_onbackorder'      => $regla8_onbackorder_total,
    'discount_perfect_round100' => $regla8_discount_ok,
    'fails_not_rounded_100'  => count( $regla8_not_rounded_100 ),
    'fails_wrong_discount'   => count( $regla8_wrong_discount ),
    'fails_no_sale_price'    => count( $regla8_no_sale_price ),
    'sample_not_rounded'     => array_slice( $regla8_not_rounded_100, 0, 10 ),
    'sample_wrong'           => array_slice( $regla8_wrong_discount, 0, 10 ),
);

$audit['rules']['regla_18_blindaje_inventario'] = array(
    'instock_count'      => $regla18_instock_count,
    'onbackorder_count'  => $regla18_onbackorder_count,
    'outofstock_count'   => $regla18_outofstock_count,
    'contract_violations'=> count( $regla18_contract_fails ),
    'sample_violations'  => array_slice( $regla18_contract_fails, 0, 10 ),
);

// -------------------------------------------------------------
// REGLA 9: Higiene de Atributos de Producto (_product_attributes)
// -------------------------------------------------------------
$regla9_brand_in_attrs = array();
$regla9_missing_required = array();

foreach ( $products as $p ) {
    $id = $p->ID;
    $m  = isset( $lookup_meta[$id] ) ? $lookup_meta[$id] : array();
    if ( ! empty( $m['_product_attributes'] ) ) {
        $attrs = maybe_unserialize( $m['_product_attributes'] );
        if ( is_array( $attrs ) ) {
            if ( isset( $attrs['product_brand'] ) ) {
                $regla9_brand_in_attrs[] = array( 'id' => $id, 'slug' => $p->post_name );
            }
            $missing = array();
            foreach ( array( 'pa_pais', 'pa_autor', 'pa_genero', 'pa_serie' ) as $req_attr ) {
                if ( ! isset( $attrs[$req_attr] ) ) {
                    $missing[] = $req_attr;
                }
            }
            if ( ! empty( $missing ) ) {
                $regla9_missing_required[] = array( 'id' => $id, 'slug' => $p->post_name, 'missing' => $missing );
            }
        }
    }
}

$audit['rules']['regla_9_higiene_atributos'] = array(
    'product_brand_in_product_attributes' => count( $regla9_brand_in_attrs ),
    'missing_required_in_product_attributes' => count( $regla9_missing_required ),
    'sample_brand_in_attrs' => array_slice( $regla9_brand_in_attrs, 0, 10 ),
    'sample_missing_req'    => array_slice( $regla9_missing_required, 0, 10 ),
);

// -------------------------------------------------------------
// REGLA 10: Géneros Obligatorios (pa_genero)
// -------------------------------------------------------------
$regla10_missing_genero = array();
foreach ( $products as $p ) {
    $id = $p->ID;
    $terms = isset( $product_terms[$id] ) ? $product_terms[$id] : array();
    if ( empty( $terms['pa_genero'] ) ) {
        $regla10_missing_genero[] = array( 'id' => $id, 'slug' => $p->post_name );
    }
}

$audit['rules']['regla_10_generos_obligatorios'] = array(
    'missing_genero_count' => count( $regla10_missing_genero ),
    'sample_missing'       => array_slice( $regla10_missing_genero, 0, 10 ),
);

// -------------------------------------------------------------
// REGLA 11: Categorías y Demografía (product_cat)
// -------------------------------------------------------------
$regla11_fails = array();
$demografias = array( 'shonen', 'seinen', 'shojo', 'josei', 'kodomo', 'manhwa', 'yaoi-bl', 'vertigo', 'independiente' );

foreach ( $products as $p ) {
    $id = $p->ID;
    $terms = isset( $product_terms[$id] ) ? $product_terms[$id] : array();
    $cats = isset( $terms['product_cat'] ) ? $terms['product_cat'] : array();
    $cat_slugs = wp_list_pluck( $cats, 'slug' );

    $is_manga = in_array( 'manga', $cat_slugs, true );
    $is_comic = in_array( 'comics', $cat_slugs, true ) || in_array( 'comic', $cat_slugs, true );

    if ( ! $is_manga && ! $is_comic ) {
        // Novelas, artbooks o promociones también son medios válidos del catálogo
        if ( ! in_array( 'novela', $cat_slugs, true ) && ! in_array( 'novelas', $cat_slugs, true ) && ! in_array( 'artbook', $cat_slugs, true ) && ! in_array( 'promociones', $cat_slugs, true ) ) {
            $regla11_fails[] = array( 'id' => $id, 'slug' => $p->post_name, 'issue' => 'neither manga, comic, novela nor artbook root', 'cats' => $cat_slugs );
        }
    } else {
        $has_demografia = false;
        foreach ( $demografias as $d ) {
            if ( in_array( $d, $cat_slugs, true ) ) {
                $has_demografia = true;
                break;
            }
        }
        if ( ! $has_demografia ) {
            $regla11_fails[] = array( 'id' => $id, 'slug' => $p->post_name, 'issue' => 'missing demography', 'cats' => $cat_slugs );
        }
    }
}

$audit['rules']['regla_11_categorias_y_demografia'] = array(
    'fails_count' => count( $regla11_fails ),
    'sample_fails'=> array_slice( $regla11_fails, 0, 10 ),
);

// -------------------------------------------------------------
// REGLA 12: Normalización de Autores (pa_autor) sin macrones
// -------------------------------------------------------------
$author_terms = get_terms( array( 'taxonomy' => 'pa_autor', 'hide_empty' => false ) );
$regla12_macrons = array();
foreach ( $author_terms as $at ) {
    if ( preg_match( '/[ōūāēīŌŪĀĒĪ]/u', $at->name ) ) {
        $regla12_macrons[] = array( 'id' => $at->term_id, 'name' => $at->name, 'slug' => $at->slug, 'count' => $at->count );
    }
}

$audit['rules']['regla_12_normalizacion_autores'] = array(
    'macron_authors_count' => count( $regla12_macrons ),
    'macron_authors'       => $regla12_macrons,
);

// -------------------------------------------------------------
// REGLA 13: SEO Rank Math Estricto en Fichas
// -------------------------------------------------------------
$regla13_missing_focus     = array();
$regla13_focus_mismatch    = array();
$regla13_desc_length_fails = array();
$regla13_desc_has_brand    = array();
$regla13_title_no_focus    = array();
$regla13_bad_custom_title  = array();
$regla13_content_no_h2     = array();

$rm_global_titles   = get_option( 'rank-math-options-titles', array() );
$rm_desc_tpl        = is_array( $rm_global_titles ) ? (string) ( $rm_global_titles['pt_product_description'] ?? '' ) : '';
$regla13_tpl_legacy = ( '' !== $rm_desc_tpl && false !== mb_stripos( $rm_desc_tpl, 'preventa', 0, 'UTF-8' ) );

$brand_names = array( 'Ivrea', 'Panini', 'Planeta Cómic', 'Milky Way', 'Arechi', 'Distrito Manga', 'Ovni Press', 'Devir' );

foreach ( $products as $p ) {
    $id = $p->ID;
    $title = html_entity_decode( trim( $p->post_title ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
    $m = isset( $lookup_meta[$id] ) ? $lookup_meta[$id] : array();

    $focus     = isset( $m['rank_math_focus_keyword'] ) ? html_entity_decode( trim( $m['rank_math_focus_keyword'] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : '';
    $seo_title = isset( $m['rank_math_title'] ) ? html_entity_decode( trim( $m['rank_math_title'] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : '';
    $seo_desc  = isset( $m['rank_math_description'] ) ? html_entity_decode( trim( $m['rank_math_description'] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : '';

    // 1. Focus keyword (comma-safe prefix match: titles like "Takagi-san, experta..." contain commas)
    if ( empty( $focus ) ) {
        $regla13_missing_focus[] = array( 'id' => $id, 'slug' => $p->post_name );
    } else {
        $title_cmp = mb_strtolower( $title, 'UTF-8' );
        $focus_cmp = mb_strtolower( $focus, 'UTF-8' );
        $title_len = mb_strlen( $title_cmp, 'UTF-8' );
        $matches_title_prefix = ( '' !== $title_cmp )
            && ( 0 === mb_strpos( $focus_cmp, $title_cmp, 0, 'UTF-8' ) )
            && ( mb_strlen( $focus_cmp, 'UTF-8' ) === $title_len || ',' === mb_substr( $focus_cmp, $title_len, 1, 'UTF-8' ) );
        if ( ! $matches_title_prefix ) {
            $keywords = array_map( 'trim', explode( ',', $focus ) );
            $primary = $keywords[0];
            $regla13_focus_mismatch[] = array( 'id' => $id, 'slug' => $p->post_name, 'title' => $title, 'focus' => $primary, 'raw_focus' => $focus );
        }
    }

    // 2. Custom SEO Title (when set) must start with full product title and avoid malformed legacy templates
    if ( ! empty( $seo_title ) ) {
        if ( mb_stripos( $seo_title, $title, 0, 'UTF-8' ) !== 0 ) {
            $regla13_title_no_focus[] = array( 'id' => $id, 'slug' => $p->post_name, 'seo_title' => $seo_title, 'title' => $title );
        }
        $bad_title_reasons = array();
        if ( false !== stripos( $seo_title, '%sep%' ) ) {
            $bad_title_reasons[] = 'literal_%sep%';
        }
        if ( 1 === preg_match( '/\bVol\.\s*\d+/u', $seo_title ) ) {
            $bad_title_reasons[] = 'legacy_Vol.';
        }
        if ( false !== mb_stripos( $seo_title, 'Comprar ', 0, 'UTF-8' ) ) {
            $bad_title_reasons[] = 'legacy_Comprar';
        }
        if ( false !== mb_stripos( $seo_title, 'edición definitiva', 0, 'UTF-8' ) ) {
            $bad_title_reasons[] = 'legacy_edicion_definitiva';
        }
        if ( false === mb_stripos( $seo_title, 'Akibara', 0, 'UTF-8' ) ) {
            $bad_title_reasons[] = 'missing_Akibara';
        }
        if ( ! empty( $bad_title_reasons ) ) {
            $regla13_bad_custom_title[] = array( 'id' => $id, 'slug' => $p->post_name, 'seo_title' => $seo_title, 'reasons' => $bad_title_reasons );
        }
    }

    // 3. Meta description length (140-155 strict per rule)
    if ( ! empty( $seo_desc ) ) {
        $len = mb_strlen( $seo_desc, 'UTF-8' );
        if ( $len < 140 || $len > 155 ) {
            $regla13_desc_length_fails[] = array( 'id' => $id, 'slug' => $p->post_name, 'len' => $len, 'desc' => $seo_desc );
        }
        foreach ( $brand_names as $bn ) {
            if ( mb_stripos( $seo_desc, $bn, 0, 'UTF-8' ) !== false ) {
                $regla13_desc_has_brand[] = array( 'id' => $id, 'slug' => $p->post_name, 'brand' => $bn, 'desc' => $seo_desc );
                break;
            }
        }
    }

    // 4. H2 with focus keyword in content
    if ( ! empty( $focus ) ) {
        if ( ! preg_match( '/<h2[^>]*>.*?<\/h2>/is', $p->post_content ) ) {
            $regla13_content_no_h2[] = array( 'id' => $id, 'slug' => $p->post_name, 'reason' => 'no <h2> tag in content' );
        }
    }
}

$audit['rules']['regla_13_seo_rankmath'] = array(
    'missing_focus_count'          => count( $regla13_missing_focus ),
    'focus_mismatch_count'         => count( $regla13_focus_mismatch ),
    'desc_length_fails_count'      => count( $regla13_desc_length_fails ),
    'desc_has_brand_count'         => count( $regla13_desc_has_brand ),
    'title_no_focus_count'         => count( $regla13_title_no_focus ),
    'bad_custom_title_count'       => count( $regla13_bad_custom_title ),
    'global_template_has_preventa' => $regla13_tpl_legacy,
    'content_no_h2_count'          => count( $regla13_content_no_h2 ),
    'sample_focus_mismatch'        => array_slice( $regla13_focus_mismatch, 0, 10 ),
    'sample_desc_length'           => array_slice( $regla13_desc_length_fails, 0, 10 ),
    'sample_desc_brand'            => array_slice( $regla13_desc_has_brand, 0, 10 ),
    'sample_title_no_focus'        => array_slice( $regla13_title_no_focus, 0, 10 ),
    'sample_bad_custom_title'      => array_slice( $regla13_bad_custom_title, 0, 10 ),
    'sample_no_h2'                 => array_slice( $regla13_content_no_h2, 0, 5 ),
);

// -------------------------------------------------------------
// REGLA 14: Descripción Corta Obligatoria (post_excerpt)
// -------------------------------------------------------------
$regla14_empty = array();
$regla14_no_strong = array();
$regla14_bad_len = array();

foreach ( $products as $p ) {
    $id = $p->ID;
    $excerpt = trim( $p->post_excerpt );
    if ( empty( $excerpt ) ) {
        $regla14_empty[] = array( 'id' => $id, 'slug' => $p->post_name );
    } else {
        if ( stripos( $excerpt, '<strong' ) === false ) {
            $regla14_no_strong[] = array( 'id' => $id, 'slug' => $p->post_name, 'preview' => mb_substr( strip_tags( $excerpt ), 0, 50 ) );
        }
        $raw_len = mb_strlen( strip_tags( $excerpt ) );
        if ( $raw_len < 120 || $raw_len > 400 ) {
            $regla14_bad_len[] = array( 'id' => $id, 'slug' => $p->post_name, 'len' => $raw_len );
        }
    }
}

$audit['rules']['regla_14_descripcion_corta'] = array(
    'empty_excerpt_count' => count( $regla14_empty ),
    'no_strong_count'     => count( $regla14_no_strong ),
    'bad_length_count'    => count( $regla14_bad_len ),
    'sample_empty'        => array_slice( $regla14_empty, 0, 10 ),
    'sample_no_strong'    => array_slice( $regla14_no_strong, 0, 10 ),
);

// -------------------------------------------------------------
// REGLA 17: Metadatos Editoriales de Serie (pa_serie)
// -------------------------------------------------------------
$valid_series_estados = array( 'completa', 'en_publicacion', 'en_pausa', 'cancelada' );
$valid_edicion_estados = array( 'completa', 'en_publicacion', 'al_dia', 'en_pausa', 'cancelada' );

$regla17_invalid_estado = array();
$regla17_missing_meta   = array();
$regla17_empty_desc     = array();
$regla17_missing_specs  = array();

foreach ( $series_terms as $st ) {
    $tid = $st->term_id;
    $slug = $st->slug;
    $cnt  = (int) $st->count;

    $estado = get_term_meta( $tid, '_akb_serie_estado', true );
    $estado_edicion = get_term_meta( $tid, '_akb_serie_estado_edicion', true );
    $tomos_obra = get_term_meta( $tid, '_akb_serie_tomos_obra', true );
    $tomos_edicion = get_term_meta( $tid, '_akb_serie_tomos_edicion', true );

    if ( ! empty( $estado ) && ! in_array( $estado, $valid_series_estados, true ) ) {
        $regla17_invalid_estado[] = array( 'id' => $tid, 'slug' => $slug, 'field' => '_akb_serie_estado', 'value' => $estado );
    }
    if ( ! empty( $estado_edicion ) && ! in_array( $estado_edicion, $valid_edicion_estados, true ) ) {
        $regla17_invalid_estado[] = array( 'id' => $tid, 'slug' => $slug, 'field' => '_akb_serie_estado_edicion', 'value' => $estado_edicion );
    }
    if ( empty( $estado ) && empty( $estado_edicion ) && $cnt > 0 ) {
        $regla17_missing_meta[] = array( 'id' => $tid, 'slug' => $slug, 'count' => $cnt );
    }

    $desc = trim( $st->description );
    if ( empty( $desc ) ) {
        $regla17_empty_desc[] = array( 'id' => $tid, 'slug' => $slug, 'count' => $cnt );
    } elseif ( preg_match( '/<(?:ul|li)[^>]*>\s*<strong>\s*(?:Estado|Autor|Tomos)/i', $desc ) ) {
        // Regla 17.8: La ficha estructurada vive en SeriesFacts, prohibido duplicar viñetas estáticas en description
        $regla17_missing_specs[] = array( 'id' => $tid, 'slug' => $slug, 'count' => $cnt, 'issue' => 'static bullet specs in description' );
    }
}

$audit['rules']['regla_17_metadatos_series'] = array(
    'total_series'        => count( $series_terms ),
    'invalid_estado_count'=> count( $regla17_invalid_estado ),
    'missing_meta_count'  => count( $regla17_missing_meta ),
    'empty_desc_count'    => count( $regla17_empty_desc ),
    'missing_specs_count' => count( $regla17_missing_specs ),
    'sample_invalid'      => $regla17_invalid_estado,
    'sample_missing_meta' => array_slice( $regla17_missing_meta, 0, 10 ),
    'sample_missing_specs'=> array_slice( $regla17_missing_specs, 0, 10 ),
);

// -------------------------------------------------------------
// REGLA 20: Integridad Estructural y Descripción Obligatoria (product_brand)
// -------------------------------------------------------------
$regla20_empty_desc = array();
$regla20_non_canonical = array();
$canonical_phrase = 'reúne en esta página los títulos catalogados de esta editorial en Akibara';

foreach ( $brands_terms as $bt ) {
    $desc = trim( $bt->description );
    if ( empty( $desc ) ) {
        $regla20_empty_desc[] = array( 'id' => $bt->term_id, 'slug' => $bt->slug, 'name' => $bt->name, 'count' => $bt->count );
    } elseif ( mb_stripos( $desc, $canonical_phrase ) === false ) {
        $regla20_non_canonical[] = array( 'id' => $bt->term_id, 'slug' => $bt->slug, 'name' => $bt->name, 'desc' => $desc );
    }
}

$audit['rules']['regla_20_product_brand_descripcion'] = array(
    'total_brands'        => count( $brands_terms ),
    'empty_desc_count'    => count( $regla20_empty_desc ),
    'non_canonical_count' => count( $regla20_non_canonical ),
    'empty_brands'        => $regla20_empty_desc,
    'non_canonical_sample'=> array_slice( $regla20_non_canonical, 0, 5 ),
);

// -------------------------------------------------------------
// REGLA 7: Upsells Encadenados
// -------------------------------------------------------------
$regla7_missing_upsells = array();
foreach ( $products as $p ) {
    $id = $p->ID;
    $m = isset( $lookup_meta[$id] ) ? $lookup_meta[$id] : array();
    $upsell_raw = isset( $m['_upsell_ids'] ) ? $m['_upsell_ids'] : '';
    $upsells = maybe_unserialize( $upsell_raw );
    if ( empty( $upsells ) || ! is_array( $upsells ) || count( $upsells ) === 0 ) {
        // Check if belongs to a multi-product series
        $terms = isset( $product_terms[$id] ) ? $product_terms[$id] : array();
        if ( ! empty( $terms['pa_serie'] ) ) {
            $s = $terms['pa_serie'][0];
            $regla7_missing_upsells[] = array( 'id' => $id, 'slug' => $p->post_name, 'serie' => $s['slug'] );
        }
    }
}

$audit['rules']['regla_7_upsells_encadenados'] = array(
    'missing_upsells_count' => count( $regla7_missing_upsells ),
    'sample_missing'        => array_slice( $regla7_missing_upsells, 0, 10 ),
);

echo json_encode( $audit, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
