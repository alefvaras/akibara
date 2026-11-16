<?php
/**
 * Enriquecimiento de Enlaces Internos SEO (Serie y Editorial) en el Catálogo de Productos.
 *
 * Ejecutable vía CLI:
 *   php tools/enriquecer-enlaces-seo-catalogo.php [--dry-run] [--limit=N] [--id=ID]
 */

if ( php_sapi_name() !== 'cli' ) {
	die( "Solo CLI\n" );
}

// Cargar WordPress si no está cargado
if ( ! defined( 'ABSPATH' ) ) {
	$paths = [
		__DIR__ . '/../public_html/wp-load.php',
		__DIR__ . '/../../public_html/wp-load.php',
		dirname( __DIR__ ) . '/public_html/wp-load.php',
		'/var/www/html/wp-load.php',
		'domains/akibara.cl/public_html/wp-load.php',
		( isset( $_SERVER['HOME'] ) ? $_SERVER['HOME'] . '/domains/akibara.cl/public_html/wp-load.php' : '' ),
	];
	foreach ( $paths as $p ) {
		if ( file_exists( $p ) ) {
			require_once $p;
			break;
		}
	}
}

if ( ! defined( 'ABSPATH' ) ) {
	die( "No se pudo cargar WordPress (wp-load.php no encontrado)\n" );
}

function akb_procesar_enlaces_seo_producto( $post_id, bool $dry_run = false ): array {
	$post = get_post( $post_id );
	if ( ! $post || $post->post_type !== 'product' || $post->post_status !== 'publish' ) {
		return [ 'status' => 'skipped', 'reason' => 'not_published_product' ];
	}

	$content = $post->post_content;
	$original_content = $content;

	// 1. Obtener Brand y Serie
	$brand_terms = wp_get_post_terms( $post_id, 'product_brand' );
	$brand_term = ( ! empty( $brand_terms ) && ! is_wp_error( $brand_terms ) ) ? $brand_terms[0] : null;
	$brand_slug = $brand_term ? $brand_term->slug : null;
	$brand_name = $brand_term ? $brand_term->name : null;

	$serie_terms = wp_get_post_terms( $post_id, 'pa_serie' );
	$serie_term = ( ! empty( $serie_terms ) && ! is_wp_error( $serie_terms ) ) ? $serie_terms[0] : null;
	$serie_slug = $serie_term ? $serie_term->slug : null;
	$serie_name = $serie_term ? $serie_term->name : null;

	// 2. Limpieza de enlaces salientes de editores (desviar fuera de Akibara)
	// Skybound: desvincular enlace a texto plano
	if ( stripos( $content, 'skybound.com' ) !== false ) {
		$content = preg_replace( '/<a[^>]*href=[\'"][^\'"]*skybound\.com[^\'"]*[\'"][^>]*>(.*?)<\/a>/i', '$1', $content );
	}
	// Panini, Milky Way, Planeta, Arechi, Ivrea, Norma externas
	$external_publishers_regex = '/<a[^>]*href=[\'"]https?:\/\/(www\.)?(panini\.es|milkywayediciones\.com|planetadelibros\.com|arechimanga\.com|editorialivrea\.com|normaeditorial\.com)[^\'"]*[\'"][^>]*>(.*?)<\/a>/i';
	if ( preg_match( $external_publishers_regex, $content ) ) {
		$content = preg_replace_callback( $external_publishers_regex, function ( $m ) use ( $brand_slug ) {
			$anchor_text = $m[3];
			if ( $brand_slug ) {
				return '<a href="/marca/' . esc_attr( $brand_slug ) . '/">' . $anchor_text . '</a>';
			}
			return $anchor_text;
		}, $content );
	}

	// 3. Normalizar URLs absolutas locales o de dominio a root-relative (/serie/..., /marca/...)
	$content = str_replace( 'http://localhost:8080/', '/', $content );
	$content = preg_replace( '/href=[\'"]https?:\/\/akibara\.cl(\/[^\'"]*)[\'"]/i', 'href="$1"', $content );

	// 4. Limpieza de bloques de especificaciones redundantes en post_content
	if ( stripos( $content, '<h3>Edición oficial y coleccionismo</h3>' ) !== false ) {
		$content = preg_replace( '/<h3>Edición oficial y coleccionismo<\/h3>\s*/i', '', $content );
	}
	$content = preg_replace( '/<p><strong>Edición.*?ISBN.*?<\/p>\s*/is', '', $content );

	// 5. Enriquecimiento de Enlaces Internos Canónicos
	$has_serie_link = $serie_slug && (
		stripos( $content, '/serie/' . $serie_slug . '/' ) !== false ||
		stripos( $content, '/serie/' . $serie_slug . '"' ) !== false
	);
	$has_brand_link = $brand_slug && (
		stripos( $content, '/marca/' . $brand_slug . '/' ) !== false ||
		stripos( $content, '/marca/' . $brand_slug . '"' ) !== false
	);

	// Preparar párrafo canónico
	$seo_p = '';
	if ( $serie_slug && $brand_slug ) {
		$seo_p = '<p class="akb-pdp-seo-links">Puedes explorar la colección completa en nuestra sección de <a href="/serie/' . esc_attr( $serie_slug ) . '/">' . esc_html( $serie_name ) . '</a> y descubrir todos los títulos de <a href="/marca/' . esc_attr( $brand_slug ) . '/">' . esc_html( $brand_name ) . '</a> disponibles en Akibara con despacho rápido a todo Chile.</p>';
	} elseif ( $serie_slug ) {
		$seo_p = '<p class="akb-pdp-seo-links">Puedes explorar la colección completa en nuestra sección de <a href="/serie/' . esc_attr( $serie_slug ) . '/">' . esc_html( $serie_name ) . '</a> disponibles en Akibara con despacho rápido a todo Chile.</p>';
	} elseif ( $brand_slug ) {
		$seo_p = '<p class="akb-pdp-seo-links">Puedes explorar todos los títulos de <a href="/marca/' . esc_attr( $brand_slug ) . '/">' . esc_html( $brand_name ) . '</a> disponibles en Akibara con despacho rápido a todo Chile.</p>';
	}

	if ( $seo_p ) {
		if ( stripos( $content, 'class="akb-pdp-seo-links"' ) !== false ) {
			// Reemplazar limpiamente versión previa
			$content = preg_replace( '/<p class="akb-pdp-seo-links">.*?<\/p>/is', $seo_p, $content );
		} elseif ( preg_match( '/<p>(Para conocer más sobre esta (historia|obra)|Puedes explorar (la colección|nuestra sección)).*?<\/p>/is', $content ) ) {
			// Reemplazar párrafo de cierre antiguo por el canónico
			$content = preg_replace( '/<p>(Para conocer más sobre esta (historia|obra)|Puedes explorar (la colección|nuestra sección)).*?<\/p>/is', $seo_p, $content );
		} elseif ( ! $has_serie_link && ! $has_brand_link ) {
			// Anexar párrafo al final
			$content = rtrim( $content ) . "\n\n" . $seo_p;
		} elseif ( ! $has_brand_link && $has_serie_link && $brand_slug ) {
			$brand_p = '<p class="akb-pdp-seo-links">Encuentra todos los títulos de <a href="/marca/' . esc_attr( $brand_slug ) . '/">' . esc_html( $brand_name ) . '</a> disponibles en Akibara con despacho rápido a todo Chile.</p>';
			$content = rtrim( $content ) . "\n\n" . $brand_p;
		} elseif ( $has_brand_link && ! $has_serie_link && $serie_slug ) {
			$serie_p = '<p class="akb-pdp-seo-links">Explora la colección completa en nuestra sección de <a href="/serie/' . esc_attr( $serie_slug ) . '/">' . esc_html( $serie_name ) . '</a> en Akibara.</p>';
			$content = rtrim( $content ) . "\n\n" . $serie_p;
		}
	}

	if ( $content !== $original_content ) {
		if ( ! $dry_run ) {
			global $wpdb;
			$wpdb->update( $wpdb->posts, [ 'post_content' => $content ], [ 'ID' => $post_id ] );
			clean_post_cache( $post_id );
		}
		return [
			'status'    => 'updated',
			'title'     => $post->post_title,
			'serie'     => $serie_slug,
			'brand'     => $brand_slug,
		];
	}

	return [ 'status' => 'unchanged', 'title' => $post->post_title ];
}

// CLI args parsing
$args = getopt( '', [ 'dry-run', 'limit::', 'id::' ] );
$dry_run = isset( $args['dry-run'] );
$limit = isset( $args['limit'] ) ? intval( $args['limit'] ) : -1;
$single_id = isset( $args['id'] ) ? intval( $args['id'] ) : 0;

echo "=== Enriquecimiento de Enlaces Internos SEO ===\n";
echo "Modo: " . ( $dry_run ? "DRY RUN (Sin guardar cambios)" : "EJECUCIÓN REAL" ) . "\n";

if ( $single_id > 0 ) {
	$products = [ $single_id ];
} else {
	global $wpdb;
	$limit_clause = $limit > 0 ? " LIMIT " . intval( $limit ) : "";
	$products = array_map( 'intval', $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type='product' AND post_status='publish' ORDER BY ID DESC" . $limit_clause ) );
}

echo "Total productos seleccionados: " . count( $products ) . "\n\n";

$updated = 0;
$unchanged = 0;
$skipped = 0;

foreach ( $products as $pid ) {
	$res = akb_procesar_enlaces_seo_producto( $pid, $dry_run );
	if ( $res['status'] === 'updated' ) {
		$updated++;
		echo "[ACTUALIZADO] ID {$pid} | {$res['title']} (Serie: {$res['serie']} | Brand: {$res['brand']})\n";
	} elseif ( $res['status'] === 'unchanged' ) {
		$unchanged++;
	} else {
		$skipped++;
	}
}

echo "\n================ Resumen ================\n";
echo "Actualizados: {$updated}\n";
echo "Sin cambios (ya canónicos): {$unchanged}\n";
echo "Omitidos: {$skipped}\n";
echo "Total evaluados: " . count( $products ) . "\n";
