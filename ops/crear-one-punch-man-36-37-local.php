<?php
/**
 * Crea One Punch-Man 36 y 37 y actualiza 35 y la serie One Punch-Man en Docker local.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';

echo "=== INICIANDO CREACIÓN DE ONE PUNCH-MAN 36 Y 37 EN LOCAL ===\n";

// 1. Registrar adjunto para tomo 36 si no existe
function get_or_create_attachment( $filename, $title ) {
	$upload_dir = wp_upload_dir();
	$file_path  = $upload_dir['basedir'] . '/2026/10/' . $filename;
	$file_url   = $upload_dir['baseurl'] . '/2026/10/' . $filename;

	if ( ! file_exists( $file_path ) ) {
		echo "ERROR: Archivo no existe: $file_path\n";
		return 0;
	}

	// Buscar si ya existe
	global $wpdb;
	$existing = $wpdb->get_var( $wpdb->prepare(
		"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s",
		'2026/10/' . $filename
	) );

	if ( $existing ) {
		echo "Adjunto ya existe (ID $existing): $filename\n";
		return (int) $existing;
	}

	$attachment = array(
		'post_mime_type' => 'image/avif',
		'post_title'     => $title,
		'post_content'   => '',
		'post_status'    => 'inherit',
		'guid'           => $file_url,
	);

	$attach_id = wp_insert_attachment( $attachment, '2026/10/' . $filename );
	if ( ! is_wp_error( $attach_id ) ) {
		update_post_meta( $attach_id, '_wp_attached_file', '2026/10/' . $filename );
		echo "Creado nuevo adjunto ID $attach_id: $filename\n";
		return (int) $attach_id;
	}

	echo "Error creando adjunto: " . $attach_id->get_error_message() . "\n";
	return 0;
}

$attach_36 = get_or_create_attachment( 'one-punch-man-36-ivrea-espana.avif', 'One Punch-Man 36 Ivrea España' );
$attach_37 = get_or_create_attachment( 'one-punch-man-37-ivrea-espana.avif', 'One Punch-Man 37 Ivrea España' );

// 2. Actualizar Tomo 35 (ID 27318)
$post_35 = get_post( 27318 );
if ( $post_35 ) {
	update_post_meta( 27318, '_stock_status', 'onbackorder' );
	update_post_meta( 27318, '_backorders', 'yes' );
	update_post_meta( 27318, '_manage_stock', 'no' );
	update_post_meta( 27318, '_akb_eta_type', 'auto' );
	update_post_meta( 27318, '_regular_price', '12990' );
	update_post_meta( 27318, '_sale_price', '12340' );
	update_post_meta( 27318, '_price', '12340' );
	update_post_meta( 27318, '_product_image_gallery', '' );
	wp_remove_object_terms( 27318, 'outofstock', 'product_visibility' );
	echo "Actualizado Tomo 35 (ID 27318): onbackorder, a pedido (auto), precio $12.340\n";
}

// 3. Helper para crear un tomo
function create_opm_volume( $vol, $isbn, $attach_id, $content, $excerpt, $rm_title, $rm_desc, $rm_kw ) {
	$slug = "one-punch-man-{$vol}-ivrea-espana";
	$existing_id = post_exists( "One Punch-Man {$vol}", '', '', 'product' );
	if ( ! $existing_id ) {
		$existing_page = get_page_by_path( $slug, OBJECT, 'product' );
		if ( $existing_page ) {
			$existing_id = $existing_page->ID;
		}
	}

	$post_data = array(
		'post_title'   => "One Punch-Man {$vol}",
		'post_name'    => $slug,
		'post_content' => $content,
		'post_excerpt' => $excerpt,
		'post_status'  => 'publish',
		'post_type'    => 'product',
	);

	if ( $existing_id ) {
		$post_data['ID'] = $existing_id;
		$prod_id = wp_update_post( $post_data );
		echo "Actualizando Tomo $vol existente (ID $prod_id)\n";
	} else {
		$prod_id = wp_insert_post( $post_data );
		echo "Insertado nuevo Tomo $vol (ID $prod_id)\n";
	}

	if ( is_wp_error( $prod_id ) ) {
		echo "ERROR al guardar Tomo $vol: " . $prod_id->get_error_message() . "\n";
		return;
	}

	// Metadatos WooCommerce
	update_post_meta( $prod_id, '_sku', $isbn );
	update_post_meta( $prod_id, '_global_unique_id', $isbn );
	update_post_meta( $prod_id, '_regular_price', '12990' );
	update_post_meta( $prod_id, '_sale_price', '12340' );
	update_post_meta( $prod_id, '_price', '12340' );
	update_post_meta( $prod_id, '_manage_stock', 'no' );
	update_post_meta( $prod_id, '_backorders', 'yes' );
	update_post_meta( $prod_id, '_stock_status', 'onbackorder' );
	update_post_meta( $prod_id, '_akb_eta_type', 'auto' );
	update_post_meta( $prod_id, '_tax_status', 'taxable' );
	update_post_meta( $prod_id, '_weight', '0.25' );
	update_post_meta( $prod_id, '_length', '17.0' );
	update_post_meta( $prod_id, '_width', '11.5' );
	update_post_meta( $prod_id, '_height', '1.5' );
	update_post_meta( $prod_id, '_product_version', '11.1.0' );
	update_post_meta( $prod_id, '_sold_individually', 'no' );
	update_post_meta( $prod_id, '_virtual', 'no' );
	update_post_meta( $prod_id, '_downloadable', 'no' );
	update_post_meta( $prod_id, '_thumbnail_id', $attach_id );
	update_post_meta( $prod_id, '_product_image_gallery', '' );

	// Rank Math
	update_post_meta( $prod_id, 'rank_math_title', $rm_title );
	update_post_meta( $prod_id, 'rank_math_description', $rm_desc );
	update_post_meta( $prod_id, 'rank_math_focus_keyword', $rm_kw );
	update_post_meta( $prod_id, 'rank_math_rich_snippet', 'product' );

	// Taxonomías
	wp_set_object_terms( $prod_id, 'simple', 'product_type' );
	wp_set_object_terms( $prod_id, array( 28, 51 ), 'product_cat' ); // Manga, Shonen
	wp_set_object_terms( $prod_id, 431, 'pa_serie' ); // One Punch-Man
	wp_set_object_terms( $prod_id, 362, 'product_brand' ); // Ivrea España
	wp_set_object_terms( $prod_id, 154, 'pa_pais' ); // España
	wp_set_object_terms( $prod_id, 259, 'pa_autor' ); // ONE
	wp_set_object_terms( $prod_id, array( 320, 323, 347 ), 'pa_genero' ); // Acción, Comedia, Superhéroes
	wp_remove_object_terms( $prod_id, 'outofstock', 'product_visibility' );

	// Atributos de producto
	$attribs = array(
		'pa_pais' => array(
			'name'         => 'pa_pais',
			'value'        => '',
			'position'     => 1,
			'is_visible'   => 1,
			'is_variation' => 0,
			'is_taxonomy'  => 1,
		),
		'pa_autor' => array(
			'name'         => 'pa_autor',
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
		'pa_serie' => array(
			'name'         => 'pa_serie',
			'value'        => '',
			'position'     => 5,
			'is_visible'   => 1,
			'is_variation' => 0,
			'is_taxonomy'  => 1,
		),
	);
	update_post_meta( $prod_id, '_product_attributes', $attribs );

	echo "Tomo $vol guardado con éxito (ID $prod_id).\n";
}

// 4. Contenido para Tomo 36
$content_36 = <<<HTML
<h2>One Punch-Man 36: El choque psíquico y la furia de Tatsumaki</h2>

<p><strong>One Punch-Man 36</strong> lleva a su punto más álgido el esperado <strong>arco de las Hermanas Psíquicas</strong>. En las instalaciones subterráneas de la Asociación de Héroes, tras la neutralización de la amenaza de la organización Tsukuyomi, estalla el conflicto más visceral entre <strong>Tatsumaki</strong> y su hermana <strong>Fubuki</strong>. Decidida a aislar a Fubuki para protegerla a su manera extrema, la heroína número 2 de Clase S le exige que disuelva de inmediato el Grupo Fubuki y corte todo vínculo con sus subordinados.</p>

<p>Fubuki se planta con determinación inquebrantable, defendiendo los lazos que ha forjado por mérito propio frente al control asfixiante de su hermana. La tensión llega a niveles críticos hasta que <strong>Saitama</strong> interviene directamente para detener la discusión. Sin embargo, con su característica falta de diplomacia y su actitud despreocupada, Saitama termina desatando la furia absoluta de Tatsumaki, provocando un devastador duelo que destroza los cimientos del complejo y se traslada a cielo abierto.</p>

<p>Con el dibujo insuperable de <strong>Yusuke Murata</strong> y la agudeza narrativa de <strong>ONE</strong>, este tomo combina acción colosal a escala épica, comedia de situaciones y el desarrollo emocional más profundo de las dos hermanas psíquicas, marcando un hito visual imprescindible dentro de la serie.</p>

<p><strong>Edición Editorial Ivrea España</strong> · Tomo 36 de una serie en curso · Tankōbon en rústica con sobrecubierta · 11,5 × 17 cm · 200 páginas en blanco y negro · Recopila los Punches 182 al 188 y capítulo extra · Guion de <strong>ONE</strong> · Dibujo de <strong>Yusuke Murata</strong> · Demografía seinen · Géneros: acción, comedia, ciencia ficción, superhéroes y sobrenatural · Lectura oriental · Idioma español · ISBN 9791388450341 · Publicado en España en agosto de 2026 · Envío a todo Chile desde Akibara.</p>
HTML;

$excerpt_36 = "<strong>One Punch-Man 36</strong>, de ONE y Yusuke Murata: clímax del arco de las Hermanas Psíquicas. El enfrentamiento entre Tatsumaki y Fubuki desencadena un choque colosal contra Saitama. Edición Ivrea España con envío a todo Chile desde Akibara.";
$rm_title_36 = "One Punch-Man 36 Ivrea España | Akibara";
$rm_desc_36  = "Compra One Punch-Man 36 de ONE y Yusuke Murata: Tatsumaki vs Saitama y el clímax psíquico. Edición Ivrea España con envío a todo Chile.";
$rm_kw_36    = "One Punch-Man 36, One Punch Man tomo 36, One Punch-Man Ivrea España, Tatsumaki vs Saitama, Hermanas Psíquicas";

create_opm_volume( 36, '9791388450341', $attach_36, $content_36, $excerpt_36, $rm_title_36, $rm_desc_36, $rm_kw_36 );

// 5. Contenido para Tomo 37
$content_37 = <<<HTML
<h2>One Punch-Man 37: Las consecuencias del combate y la sombra de los Neo Heroes</h2>

<p><strong>One Punch-Man 37</strong> concluye el monumental enfrentamiento entre <strong>Saitama</strong> y <strong>Tatsumaki</strong>, cuyas ondas de choque sacudieron múltiples sectores de la ciudad. Tras resistir sin el menor rasguño los poderes telequinéticos más devastadores de la Tornado del Terror, Saitama logra apaciguar el combate, dejando a Tatsumaki exhausta y obligada a reconocer, muy a su pesar, la existencia de un ser cuya fuerza escapa a cualquier límite comprensible.</p>

<p>En las secuelas de la batalla, la Asociación de Héroes queda gravemente cuestionada por la opinión pública y los patrocinadores debido a su incapacidad para controlar la destrucción colateral y la fuga de criminales. En este escenario de crisis de confianza, comienza a tomar forma una organización rival que amenaza con cambiar el equilibrio del mundo: los <strong>Neo Heroes</strong>, decididos a reclutar a miembros clave de Clase S mediante tecnología avanzada y promesas de eficiencia absoluta.</p>

<p>ONE y Yusuke Murata continúan expandiendo el universo de One Punch-Man, situando este volumen a la par de las ediciones japonesas contemporáneas. Una lectura imprescindible cargada de intriga corporativa, humor irreverente y combates espectaculares.</p>

<p><strong>Edición Editorial Ivrea España</strong> · Tomo 37 de una serie en curso (al día con Japón) · Tankōbon en rústica con sobrecubierta · 11,5 × 17 cm · 200 páginas en blanco y negro · Recopila los Punches 189 al 194 · Guion de <strong>ONE</strong> · Dibujo de <strong>Yusuke Murata</strong> · Demografía seinen · Géneros: acción, comedia, ciencia ficción, superhéroes y sobrenatural · Lectura oriental · Idioma español · ISBN 9791388487361 · Publicado en España en octubre de 2026 · Envío a todo Chile desde Akibara.</p>
HTML;

$excerpt_37 = "<strong>One Punch-Man 37</strong>, de ONE y Yusuke Murata: desenlace de la batalla de Tatsumaki contra Saitama, secuelas en la Asociación de Héroes y el alzamiento de los Neo Heroes. Edición Ivrea España con envío a todo Chile.";
$rm_title_37 = "One Punch-Man 37 Ivrea España | Akibara";
$rm_desc_37  = "Compra One Punch-Man 37 de ONE y Yusuke Murata: conclusión de Tatsumaki vs Saitama y el inicio de los Neo Heroes. Edición Ivrea España con envío a todo Chile.";
$rm_kw_37    = "One Punch-Man 37, One Punch Man tomo 37, One Punch-Man Ivrea España, Saitama, Neo Heroes, Asociación de Héroes";

create_opm_volume( 37, '9791388487361', $attach_37, $content_37, $excerpt_37, $rm_title_37, $rm_desc_37, $rm_kw_37 );

// 6. Actualizar metadatos de la taxonomía serie (pa_serie: 431)
update_term_meta( 431, '_akb_serie_tomos_edicion', 37 );
update_term_meta( 431, '_akb_serie_tomos_obra', 37 );
update_term_meta( 431, '_akb_serie_estado', 'en_publicacion' );
update_term_meta( 431, '_akb_serie_estado_edicion', 'en_publicacion' );
update_term_meta( 431, 'rank_math_title', 'One Punch-Man manga: 37 tomos en español | Akibara Chile' );
update_term_meta( 431, 'rank_math_description', 'One Punch-Man de ONE: 37 tomos catalogados de la edición Ivrea España en español. Revisa precio y disponibilidad en Akibara.' );

echo "Metadatos de la serie One Punch-Man (ID 431) actualizados a 37 tomos.\n";
echo "=== OPERACIÓN LOCAL COMPLETADA EXITOSAMENTE ===\n";
