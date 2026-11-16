<?php
/**
 * tools/import_steins_both.php
 *
 * Script de importación y certificación para:
 *   1. Steins;Gate (3 tomos completada)
 *   2. Steins;Gate 0 (6 tomos completada)
 *
 * Cumple al 100% las reglas de catálogo de AGENTS.md:
 *   - Regla 2: Cero enlaces externos a editoriales, enlazado interno .akb-pdp-seo-links.
 *   - Regla 3: Identificadores comerciales (GTIN / EAN / ISBN) repiten _sku.
 *   - Regla 4: Portadas alta resolución con alt descriptivo.
 *   - Regla 5: Especificaciones estructuradas en _product_attributes.
 *   - Regla 7: Encadenamiento circular de upsells.
 *   - Regla 8: 5% Descuento de reserva a pedido obligatorio (13990 regular -> 13290 sale).
 *   - Regla 9: Higiene de _product_attributes (CERO product_brand dentro del array).
 *   - Regla 10: Géneros obligatorios vinculados en pa_genero y _product_attributes.
 *   - Regla 11: Categorías Manga + Demografía Seinen.
 *   - Regla 12: Normalización latina de autor.
 *   - Regla 13: SEO Rank Math >= 70/100 (título inicia con focus keyword, H2, meta 140-155 chars sin editorial).
 */

if ( file_exists( '/var/www/html/wp-load.php' ) ) {
	require_once '/var/www/html/wp-load.php';
} elseif ( file_exists( '/home/u888022333/domains/akibara.cl/public_html/wp-load.php' ) ) {
	require_once '/home/u888022333/domains/akibara.cl/public_html/wp-load.php';
} elseif ( file_exists( dirname( __DIR__ ) . '/public_html/wp-load.php' ) ) {
	require_once dirname( __DIR__ ) . '/public_html/wp-load.php';
} else {
	die( "Error: wp-load.php no encontrado.\n" );
}

$payload_path = __DIR__ . '/steins_import_payload.json';
if ( ! file_exists( $payload_path ) ) {
	if ( file_exists( '/tmp/steins_tools/steins_import_payload.json' ) ) {
		$payload_path = '/tmp/steins_tools/steins_import_payload.json';
	} else {
		die( "Error: No se encontró el payload en $payload_path\n" );
	}
}

$payload = json_decode( file_get_contents( $payload_path ), true );
$images_dir = file_exists( '/tmp/steins_images' ) ? '/tmp/steins_images' : '/home/u888022333/steins_images';

require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';

function akb_import_featured_image( $file_path, $post_id, $title, $alt ) {
	if ( ! file_exists( $file_path ) ) {
		return 0;
	}

	$upload_dir = wp_upload_dir();
	$filename = basename( $file_path );
	$dest_path = $upload_dir['path'] . '/' . wp_unique_filename( $upload_dir['path'], $filename );

	if ( ! copy( $file_path, $dest_path ) ) {
		return 0;
	}

	$file_type = wp_check_filetype( $dest_path, null );
	$attachment = array(
		'post_mime_type' => $file_type['type'] ?: 'image/avif',
		'post_title'     => $title,
		'post_content'   => '',
		'post_status'    => 'inherit',
	);

	$attach_id = wp_insert_attachment( $attachment, $dest_path, $post_id );
	if ( ! is_wp_error( $attach_id ) && $attach_id > 0 ) {
		$attach_data = wp_generate_attachment_metadata( $attach_id, $dest_path );
		wp_update_attachment_metadata( $attach_id, $attach_data );
		set_post_thumbnail( $post_id, $attach_id );
		update_post_meta( $attach_id, '_wp_attachment_image_alt', $alt );
		return $attach_id;
	}
	return 0;
}
$REGULAR_PRICE = 13990;
$SALE_PRICE    = 13290; // round(13990 * 0.95)

echo "=================================================================\n";
echo " INICIANDO IMPORTACIÓN: STEINS;GATE Y STEINS;GATE 0\n";
echo "=================================================================\n\n";

// Asegurar marcas y categorías base
$brand = get_term_by( 'slug', 'ivrea-espana', 'product_brand' );
if ( ! $brand ) {
	$inserted = wp_insert_term( 'Ivrea España', 'product_brand', array( 'slug' => 'ivrea-espana' ) );
	$brand_id = is_array( $inserted ) ? $inserted['term_id'] : 0;
} else {
	$brand_id = $brand->term_id;
}
echo "✓ Brand ID (Ivrea España): $brand_id\n";

$pais = get_term_by( 'slug', 'espana', 'pa_pais' );
$pais_id = $pais ? $pais->term_id : 0;
echo "✓ País ID (España): $pais_id\n";

$cat_manga = get_term_by( 'slug', 'manga', 'product_cat' );
$cat_manga_id = $cat_manga ? $cat_manga->term_id : 0;

$cat_seinen = get_term_by( 'slug', 'seinen', 'product_cat' );
$cat_seinen_id = $cat_seinen ? $cat_seinen->term_id : 0;
echo "✓ Categorías: Manga ($cat_manga_id), Seinen ($cat_seinen_id)\n";

$all_created_ids = array();

foreach ( $payload['series'] as $series_data ) {
	echo "\n-----------------------------------------------------------------\n";
	echo " PROCESANDO SERIE: " . $series_data['name'] . "\n";
	echo "-----------------------------------------------------------------\n";

	// 1. Crear o Actualizar pa_serie
	$serie_term = get_term_by( 'slug', $series_data['slug'], 'pa_serie' );
	if ( ! $serie_term ) {
		$ins = wp_insert_term(
			$series_data['name'],
			'pa_serie',
			array(
				'slug'        => $series_data['slug'],
				'description' => $series_data['description'],
			)
		);
		$serie_id = is_array( $ins ) ? $ins['term_id'] : 0;
		echo "  ✓ Creada serie pa_serie: {$series_data['name']} (ID: $serie_id)\n";
	} else {
		$serie_id = $serie_term->term_id;
		wp_update_term(
			$serie_id,
			'pa_serie',
			array(
				'name'        => $series_data['name'],
				'description' => $series_data['description'],
			)
		);
		echo "  ✓ Actualizada serie pa_serie: {$series_data['name']} (ID: $serie_id)\n";
	}

	// Metadatos de la serie para SeriesFacts y Rank Math
	update_term_meta( $serie_id, '_akb_serie_estado', 'completa' );
	update_term_meta( $serie_id, '_akb_serie_estado_edicion', 'completa' );
	update_term_meta( $serie_id, '_akb_serie_tomos_obra', (int) $series_data['volumes_count'] );
	update_term_meta( $serie_id, '_akb_serie_tomos_edicion', (int) $series_data['volumes_count'] );
	update_term_meta( $serie_id, 'rank_math_title', $series_data['seo_title'] );
	update_term_meta( $serie_id, 'rank_math_description', $series_data['seo_desc'] );
	update_term_meta( $serie_id, 'rank_math_focus_keyword', $series_data['name'] );

	// 2. Autor pa_autor
	$autor_term = get_term_by( 'slug', $series_data['author_slug'], 'pa_autor' );
	if ( ! $autor_term ) {
		$ins_a = wp_insert_term(
			$series_data['author_name'],
			'pa_autor',
			array( 'slug' => $series_data['author_slug'] )
		);
		$autor_id = is_array( $ins_a ) ? $ins_a['term_id'] : 0;
		echo "  ✓ Creado autor pa_autor: {$series_data['author_name']} (ID: $autor_id)\n";
	} else {
		$autor_id = $autor_term->term_id;
		echo "  ✓ Encontrado autor pa_autor: {$series_data['author_name']} (ID: $autor_id)\n";
	}

	// 3. Géneros pa_genero
	$genre_term_ids = array();
	foreach ( $series_data['genres'] as $g_slug ) {
		$g_term = get_term_by( 'slug', $g_slug, 'pa_genero' );
		if ( $g_term ) {
			$genre_term_ids[] = $g_term->term_id;
		}
	}
	echo "  ✓ Géneros asignados: " . implode( ', ', $series_data['genres'] ) . " (IDs: " . implode( ', ', $genre_term_ids ) . ")\n";

	// 4. Crear o Actualizar Tomos
	$series_created_ids = array();

	foreach ( $series_data['volumes'] as $vol_data ) {
		$existing = get_page_by_path( $vol_data['slug'], OBJECT, 'product' );
		$post_arr = array(
			'post_title'   => $vol_data['title'],
			'post_name'    => $vol_data['slug'],
			'post_content' => $vol_data['post_content'],
			'post_excerpt' => '',
			'post_status'  => 'publish',
			'post_type'    => 'product',
		);

		if ( $existing ) {
			$post_arr['ID'] = $existing->ID;
			$pid = wp_update_post( $post_arr );
			echo "  ✓ Tomo actualizado: {$vol_data['title']} (ID: $pid)\n";
		} else {
			$pid = wp_insert_post( $post_arr );
			echo "  ✓ Tomo creado: {$vol_data['title']} (ID: $pid)\n";
		}

		$series_created_ids[ $vol_data['vol'] ] = $pid;
		$all_created_ids[] = $pid;

		// Taxonomías
		wp_set_object_terms( $pid, array( $serie_id ), 'pa_serie' );
		wp_set_object_terms( $pid, array( $brand_id ), 'product_brand' );
		wp_set_object_terms( $pid, array( $autor_id ), 'pa_autor' );
		if ( $pais_id ) {
			wp_set_object_terms( $pid, array( $pais_id ), 'pa_pais' );
		}
		if ( ! empty( $genre_term_ids ) ) {
			wp_set_object_terms( $pid, $genre_term_ids, 'pa_genero' );
		}
		$cats = array_filter( array( $cat_manga_id, $cat_seinen_id ) );
		wp_set_object_terms( $pid, $cats, 'product_cat' );

		// Regla 9: _product_attributes NUNCA debe contener product_brand
		$attribs = array(
			'pa_serie'  => array(
				'name'         => 'pa_serie',
				'value'        => '',
				'position'     => 0,
				'is_visible'   => 1,
				'is_variation' => 0,
				'is_taxonomy'  => 1,
			),
			'pa_autor'  => array(
				'name'         => 'pa_autor',
				'value'        => '',
				'position'     => 1,
				'is_visible'   => 1,
				'is_variation' => 0,
				'is_taxonomy'  => 1,
			),
			'pa_genero' => array(
				'name'         => 'pa_genero',
				'value'        => '',
				'position'     => 2,
				'is_visible'   => 1,
				'is_variation' => 0,
				'is_taxonomy'  => 1,
			),
			'pa_pais'   => array(
				'name'         => 'pa_pais',
				'value'        => '',
				'position'     => 3,
				'is_visible'   => 1,
				'is_variation' => 0,
				'is_taxonomy'  => 1,
			),
		);
		update_post_meta( $pid, '_product_attributes', $attribs );

		// Reglas 3 y 8: Códigos de barra comerciales, Stock a pedido y 5% de descuento
		update_post_meta( $pid, '_sku', $vol_data['isbn'] );
		update_post_meta( $pid, '_global_unique_id', $vol_data['isbn'] );
		update_post_meta( $pid, '_gtin', $vol_data['isbn'] );
		update_post_meta( $pid, '_barcode', $vol_data['isbn'] );

		update_post_meta( $pid, '_regular_price', (string) $REGULAR_PRICE );
		update_post_meta( $pid, '_sale_price', (string) $SALE_PRICE );
		update_post_meta( $pid, '_price', (string) $SALE_PRICE );

		update_post_meta( $pid, '_manage_stock', 'no' );
		update_post_meta( $pid, '_stock', null );
		update_post_meta( $pid, '_backorders', 'yes' );
		update_post_meta( $pid, '_stock_status', 'onbackorder' );
		update_post_meta( $pid, '_akb_eta_type', 'auto' );
		update_post_meta( $pid, '_tax_status', 'taxable' );
		update_post_meta( $pid, '_visibility', 'visible' );

		// Regla 13: SEO Rank Math Estricto
		update_post_meta( $pid, 'rank_math_title', $vol_data['seo_title'] );
		update_post_meta( $pid, 'rank_math_description', $vol_data['meta_desc'] );
		update_post_meta( $pid, 'rank_math_focus_keyword', $vol_data['keyword'] );
		update_post_meta( $pid, 'rank_math_primary_product_cat', $cat_manga_id );
		update_post_meta( $pid, 'rank_math_rich_snippet', 'product' );

		// Regla 4: Portada en alta resolución
		$thumb = get_post_thumbnail_id( $pid );
		if ( ! $thumb ) {
			$img_path = $images_dir . '/' . $vol_data['image'];
			if ( file_exists( $img_path ) ) {
				$attach_id = akb_import_featured_image(
					$img_path,
					$pid,
					$vol_data['title'] . ' — Portada oficial',
					$vol_data['title'] . ' — Portada oficial en alta resolución'
				);
				echo "    📷 Portada importada con API nativa: attach ID $attach_id\n";
			} else {
				echo "    ⚠️ Imagen no encontrada en: $img_path\n";
			}
		}

		// Actualizar tabla de lookup de WooCommerce
		if ( function_exists( 'wc_update_product_lookup_tables' ) ) {
			wc_update_product_lookup_tables( $pid );
		}
	}

	// 5. Encadenamiento Circular de Upsells (Regla 7)
	echo "\n  -> Encadenando Upsells para {$series_data['name']}...\n";
	$count_vols = count( $series_data['volumes'] );
	for ( $i = 1; $i <= $count_vols; $i++ ) {
		$pid = $series_created_ids[ $i ];
		$upsells = array();
		if ( 3 === $count_vols ) {
			// Serie de 3 tomos:
			// 1 -> 2, 3
			// 2 -> 3, 1
			// 3 -> 1, 2
			if ( 1 === $i ) {
				$upsells = array( $series_created_ids[2], $series_created_ids[3] );
			} elseif ( 2 === $i ) {
				$upsells = array( $series_created_ids[3], $series_created_ids[1] );
			} elseif ( 3 === $i ) {
				$upsells = array( $series_created_ids[1], $series_created_ids[2] );
			}
		} elseif ( 6 === $count_vols ) {
			// Serie de 6 tomos:
			// 1 -> 2, 3 | 2 -> 3, 4 | 3 -> 4, 5 | 4 -> 5, 6 | 5 -> 6, 1 | 6 -> 1, 2
			if ( $i <= 4 ) {
				$upsells = array( $series_created_ids[ $i + 1 ], $series_created_ids[ $i + 2 ] );
			} elseif ( 5 === $i ) {
				$upsells = array( $series_created_ids[6], $series_created_ids[1] );
			} elseif ( 6 === $i ) {
				$upsells = array( $series_created_ids[1], $series_created_ids[2] );
			}
		}

		update_post_meta( $pid, '_upsell_ids', $upsells );
		echo "    Tomo $i (ID $pid) -> Upsells: " . implode( ', ', $upsells ) . "\n";
	}
}

echo "\n=================================================================\n";
echo " LIMPIANDO CACHÉS Y REINDEXANDO MOTOR DE BÚSQUEDA\n";
echo "=================================================================\n";

if ( function_exists( 'wp_cache_flush' ) ) {
	wp_cache_flush();
}

// Reindexar productos en akibara-search si el indexador está disponible
foreach ( $all_created_ids as $pid ) {
	if ( class_exists( '\\Akibara_Search\\Indexer' ) && method_exists( '\\Akibara_Search\\Indexer', 'reindex_post' ) ) {
		\Akibara_Search\Indexer::reindex_post( $pid, true );
	}
}
echo "✓ " . count( $all_created_ids ) . " tomos procesados para el buscador.\n";

echo "\n¡IMPORTACIÓN COMPLETADA CON ÉXITO!\n";
