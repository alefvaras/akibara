<?php
/**
 * Script de saneamiento y purga de preventa, a pedido y arma tu pack para producción.
 *
 * Ejecutable vía WP-CLI:
 *   wp eval-file ops/limpieza-preventa-a-pedido-prod.php
 *
 * Idempotente y seguro: no altera datos no relacionados y maneja correctamente
 * la deserialización de arrays en wp_options y eventos de WP-Cron.
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit( "Este script debe ejecutarse en el contexto de WordPress (wp eval-file).\n" );
}

echo "=== INICIANDO SANEAMIENTO DE PREVENTA, A PEDIDO Y ARMA TU PACK ===\n\n";

// 1. DESPROGRAMAR HOOKS DE WP-CRON
echo "1. Desprogramando hooks huérfanos de WP-Cron...\n";
$cron_hooks = array(
	'akb_preventa_vencida_sweep',
	'akb_order_preventa_recompute_ready',
	'akb_order_preventa_t7_reminder_scan',
	'akb_order_preventa_dispatch_ready_email',
	'akb_order_preventa_recompute_for_product',
	'akb_order_preventa_release_date_changed_batch',
	'akb_order_preventa_verify_carrier_handoff',
);
foreach ( $cron_hooks as $hook ) {
	$cleared = wp_clear_scheduled_hook( $hook );
	echo "   - Hook '$hook': " . ( false !== $cleared ? 'desprogramado/limpio' : 'no encontrado' ) . "\n";
}

// 2. PURGAR Y ACTUALIZAR WP_OPTIONS
echo "\n2. Saneando wp_options...\n";

// 2.1 Eliminar opciones obsoletas y transients
delete_option( 'akb_copy_disponibles_sibling_link' );
delete_option( 'akb_copy_disponibles_sibling_prefix' );
delete_option( 'rank_math_seo_analysis_results' );
delete_option( 'rank_math_seo_analysis_competitor_results' );
delete_option( 'rank_math_backups' );
delete_option( 'rank_math_indexnow_log' );

global $wpdb;
$purged_transients = $wpdb->query(
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_akb_series_facts_%' OR option_name LIKE '_transient_timeout_akb_series_facts_%'"
);
echo "   - Purgados $purged_transients transients de series facts.\n";

// 2.2 Actualizar textos canónicos planos
update_option(
	'akibara_llms_identity',
	'Tienda online chilena especializada en manga y cómics originales en español, con tomos disponibles e importación directa. Despacho a todo Chile y retiro coordinado en Metro San Miguel, Santiago. Pagos con Mercado Pago y Flow (Webpay).'
);
update_option( 'akb_copy_wall_sub', 'Elige cómo continuar para comprar, seguir tus pedidos y revisar tu colección.' );
update_option( 'akb_copy_wall_sub_login', 'Elige cómo continuar para comprar, seguir tus pedidos y revisar tu colección.' );

// 2.3 Actualizar plantillas serializadas de Rank Math
$rm_titles = get_option( 'rank-math-options-titles' );
if ( is_array( $rm_titles ) ) {
	$rm_titles['pt_product_description']         = 'Compra %title% al mejor precio en Chile. Novedades, reposiciones y tomos originales. Envío a todo Chile en Akibara.';
	$rm_titles['tax_product_brand_description']  = 'Compra manga y cómics de %term% en Akibara. Catálogo completo con envío a todo Chile. Stock disponible y novedades.';
	$rm_titles['tax_pa_serie_description']       = 'Revisa el catálogo y completa tu colección de %term% en Akibara Chile. Tomos disponibles y reposiciones, con despacho a todo el país.';
	$rm_titles['organization_description']       = 'Akibara es una tienda chilena especializada en manga y cómics originales, con despacho según cobertura y retiro coordinado en Metro San Miguel.';
	$rm_titles['pt_product_archive_description'] = 'Manga y cómics 100% originales en Chile: novedades, reposiciones y tomos de Ivrea, Panini y Milky Way. Envío a todo el país y 3 cuotas sin interés.';
	update_option( 'rank-math-options-titles', $rm_titles );
	echo "   - Plantillas de Rank Math Titles actualizadas.\n";
}

$rm_gen = get_option( 'rank-math-options-general' );
if ( is_array( $rm_gen ) && isset( $rm_gen['llms_extra_content'] ) ) {
	$rm_gen['llms_extra_content'] = str_replace(
		'stock, preventas, editoriales',
		'stock disponible, novedades, reposiciones, editoriales',
		$rm_gen['llms_extra_content']
	);
	update_option( 'rank-math-options-general', $rm_gen );
	echo "   - Contenido LLMS en Rank Math General actualizado.\n";
}

// 2.4 Actualizar Brevo / Sendinblue opt-in label
$sib = get_option( 'sendinblue_woocommerce_user_connection_settings' );
if ( is_array( $sib ) && isset( $sib['displayOptInLabel'] ) ) {
	$sib['displayOptInLabel'] = str_replace(
		array( 'preventas, lanzamientos y ofertas', 'preventas' ),
		array( 'lanzamientos y ofertas exclusivas', 'novedades' ),
		$sib['displayOptInLabel']
	);
	update_option( 'sendinblue_woocommerce_user_connection_settings', $sib );
	echo "   - Etiqueta opt-in de Brevo actualizada.\n";
}

// 3. SANEAR ARTÍCULOS DE BLOG (wp_posts)
echo "\n3. Saneando artículos de blog en wp_posts...\n";
$blog_replacements = array(
	22640 => array(
		'reposiciones y preventas del momento' => 'reposiciones y tomos disponibles del momento',
	),
	22641 => array(
		'armar un pack grande con una sola serie, conviene confirmar el stock y las preventas con anticipación' => 'reunir muchos tomos juntos de una misma serie, conviene confirmar el stock y la disponibilidad con anticipación',
	),
	23323 => array(
		'reposiciones o preventas por volumen' => 'reposiciones o nuevos ingresos por volumen',
	),
	23324 => array(
		'las preventas y las reposiciones' => 'las novedades y las reposiciones',
	),
	27454 => array(
		'fecha de preventa' => 'fecha estimada',
	),
	27457 => array(
		'Disponible, preventa y agotado describen situaciones distintas. Una preventa usa una fecha estimada y un producto agotado no tiene una reposición garantizada. Lee la ficha antes de juntar estados en un mismo pedido.' => 'Disponible y agotado describen situaciones distintas. Un producto disponible cuenta con stock físico en bodega para despacho inmediato, mientras que un producto agotado permite activar un aviso por correo para su reposición.',
	),
	27458 => array(
		'altas, preventas y fichas agotadas' => 'altas, novedades y fichas agotadas',
	),
	27528 => array(
		'disponibles o en preventa'          => 'disponibles',
		'preventa y disponibilidad inmediata' => 'disponibilidad inmediata y productos agotados con aviso de reposición',
	),
);

foreach ( $blog_replacements as $post_id => $reps ) {
	$post = get_post( $post_id );
	if ( ! $post ) {
		continue;
	}
	$content = $post->post_content;
	$changed = false;
	foreach ( $reps as $search => $replace ) {
		if ( false !== strpos( $content, $search ) ) {
			$content = str_replace( $search, $replace, $content );
			$changed = true;
		}
	}
	if ( $changed ) {
		wp_update_post( array( 'ID' => $post_id, 'post_content' => $content ) );
		echo "   - Post $post_id ({$post->post_name}): contenido actualizado.\n";
	} else {
		echo "   - Post $post_id ({$post->post_name}): ya limpio o sin coincidencias.\n";
	}
}

// 4. SANEAR TÉRMINOS Y TAXONOMÍAS (wp_term_taxonomy)
echo "\n4. Saneando términos y taxonomías...\n";

// 4.1 Categoría Manga (term_id: 28)
$term28 = get_term( 28, 'product_cat' );
if ( $term28 && ! is_wp_error( $term28 ) ) {
	$d28 = $term28->description;
	$d28 = str_replace( 'stock disponible y preventas', 'stock disponible, novedades y reposiciones', $d28 );
	$d28 = preg_replace(
		'/<strong>Disponible o preventa\.<\/strong>.*?Revisa siempre el estado del producto y los datos del checkout antes de pagar\./us',
		'<strong>Disponibilidad y stock.</strong> <strong>Disponible</strong> indica que el producto tiene stock físico en bodega para compra y entrega inmediata. Si un tomo figura como agotado, puedes solicitar aviso por correo para enterarte en cuanto vuelva a ingresar. Revisa siempre el estado del producto y los datos del checkout antes de pagar.',
		$d28
	);
	$d28 = preg_replace(
		'/<strong>¿Puedo reservar un lanzamiento\?<\/strong>\s*Sí, cuando el producto está publicado como preventa\. La fecha o ventana estimada vigente aparece en su ficha\./u',
		"<strong>¿Qué hago si un tomo está agotado?</strong>\nSi el tomo que buscas no tiene stock inmediato, puedes dejar tu correo en el botón «Avísame cuando vuelva» de su ficha para recibir una notificación automática en cuanto ingrese a bodega.",
		$d28
	);
	if ( $d28 !== $term28->description ) {
		wp_update_term( 28, 'product_cat', array( 'description' => $d28 ) );
		echo "   - Categoría 28 (Manga): descripción actualizada.\n";
	}
}

// 4.2 Series Hanako-kun (term_ids: 795, 796, 797)
$series_reps = array(
	795 => array( 'Disponibles a pedido con importación directa y envíos protegidos a todo Chile.' => 'Disponibles con importación oficial y envíos protegidos a todo Chile.' ),
	796 => array( 'Disponible a pedido con envíos a todo Chile en Akibara.' => 'Disponible con envíos a todo Chile en Akibara.' ),
	797 => array( 'Disponible a pedido en Akibara con despacho seguro a todo el país.' => 'Disponible en Akibara con despacho seguro a todo el país.' ),
);
foreach ( $series_reps as $tid => $reps ) {
	$term = get_term( $tid, 'pa_serie' );
	if ( $term && ! is_wp_error( $term ) ) {
		$desc = $term->description;
		foreach ( $reps as $s => $r ) {
			$desc = str_replace( $s, $r, $desc );
		}
		if ( $desc !== $term->description ) {
			wp_update_term( $tid, 'pa_serie', array( 'description' => $desc ) );
			echo "   - Serie $tid ({$term->name}): descripción actualizada.\n";
		}
	}
}

// 5. SANEAR TEXTOS ALT DE IMÁGENES (wp_postmeta)
echo "\n5. Saneando textos alt en wp_postmeta...\n";
$alt_reps = array(
	23494 => 'Manga en Akibara: Todo lo que necesitas saber',
	27595 => 'Dandadan, manga publicado por Ivrea Argentina',
	27598 => 'Novedades de manga en Akibara',
	27628 => 'Dandadan, uno de los títulos destacados del catálogo',
);
foreach ( $alt_reps as $att_id => $new_alt ) {
	update_post_meta( $att_id, '_wp_attachment_image_alt', $new_alt );
	echo "   - Imagen $att_id: alt actualizado a '$new_alt'.\n";
}

echo "\n=== PROCESO COMPLETADO EXITOSAMENTE ===\n";
