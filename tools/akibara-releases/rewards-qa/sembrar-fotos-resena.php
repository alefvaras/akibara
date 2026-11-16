<?php
/**
 * Siembra (y retira) los casos de borde de la foto de la reseña en el ESPEJO.
 *
 * Uso (sin copiar nada al docroot: WP-CLI lee el código por STDIN):
 *   docker compose exec -T cron wp eval-file - sembrar < tools/akibara-releases/rewards-qa/sembrar-fotos-resena.php
 *   docker compose exec -T cron wp eval-file - limpiar < tools/akibara-releases/rewards-qa/sembrar-fotos-resena.php
 *
 * Todo lo sembrado lleva el autor «QA Foto Reseña» y se retira por ID guardado
 * en una option, nunca por correo ni por autor: una sonda que borra por
 * coincidencia de texto ya se llevó datos reales una vez.
 *
 * Casos:
 *   local      reseña aprobada con foto local válida  → figura en la ficha
 *   remota     reseña aprobada con foto remota http   → figura, servida por https
 *   huerfana   reseña aprobada con meta a adjunto que no existe → sin figura
 *   sin_foto   reseña aprobada sin foto              → sin figura
 *   pendiente  reseña PENDIENTE con foto local       → miniatura en moderación
 *   blog       comentario de blog con meta de foto   → sin figura
 *
 * @package Akibara\Rewards\QA
 */

$modo   = $args[0] ?? 'sembrar';
$option = 'akb_qa_fotos_resena_ids';
$autor  = 'QA Foto Reseña';

if ( 'limpiar' === $modo ) {
	$ids = (array) get_option( $option, array() );
	$n   = 0;
	foreach ( $ids as $id ) {
		if ( get_comment( (int) $id ) && wp_delete_comment( (int) $id, true ) ) {
			++$n;
		}
	}
	delete_option( $option );
	foreach ( array( 'producto', 'blog' ) as $clave ) {
		if ( ! empty( $ids[ '_' . $clave ] ) ) {
			clean_post_cache( (int) $ids[ '_' . $clave ] );
			if ( 'producto' === $clave && function_exists( 'wc_delete_product_transients' ) ) {
				wc_delete_product_transients( (int) $ids['_producto'] );
			}
		}
	}
	echo wp_json_encode( array( 'retirados' => $n ) ) . PHP_EOL;
	return;
}

if ( get_option( $option ) ) {
	WP_CLI::error( 'Ya hay una siembra viva: corre «limpiar» primero.' );
}

// Un tomo real si existe en el espejo (el mismo de la medición en prod); si no,
// el primero publicado.
$producto = wc_get_product( 27918 );
if ( ! $producto || 'publish' !== $producto->get_status() ) {
	$producto = wc_get_products( array( 'limit' => 1, 'status' => 'publish' ) )[0];
}
$producto_id = $producto->get_id();

$adjuntos = get_posts(
	array(
		'post_type'      => 'attachment',
		'post_mime_type' => 'image/jpeg',
		'numberposts'    => 1,
		'fields'         => 'ids',
	)
);
$adjunto  = (int) ( $adjuntos[0] ?? 0 );
if ( ! $adjunto || ! wp_attachment_is( 'image', $adjunto ) ) {
	WP_CLI::error( 'El espejo no tiene ninguna imagen en la biblioteca.' );
}

$blog = get_posts(
	array(
		'post_type'   => 'post',
		'post_status' => 'publish',
		'numberposts' => 1,
		'fields'      => 'ids',
	)
);

$crear = static function ( int $post_id, string $tipo, string $texto, int $aprobado ) use ( $autor ): int {
	return (int) wp_insert_comment(
		array(
			'comment_post_ID'      => $post_id,
			'comment_author'       => $autor,
			'comment_author_email' => 'qa-foto-resena@akibara.invalid',
			'comment_content'      => $texto,
			'comment_type'         => $tipo,
			'comment_approved'     => $aprobado,
		)
	);
};

$ids = array(
	'_producto' => $producto_id,
	'_blog'     => (int) ( $blog[0] ?? 0 ),
	'_adjunto'  => $adjunto,
);

$ids['local'] = $crear( $producto_id, 'review', 'QA-LOCAL: la edición llegó impecable.', 1 );
add_comment_meta( $ids['local'], 'ivole_review_image2', $adjunto );

$ids['remota'] = $crear( $producto_id, 'review', 'QA-REMOTA: foto heredada de otro sistema.', 1 );
add_comment_meta( $ids['remota'], 'ivole_review_image', array( 'url' => set_url_scheme( wp_get_attachment_image_url( $adjunto, 'medium' ), 'http' ) ) );

$ids['huerfana'] = $crear( $producto_id, 'review', 'QA-HUERFANA: el adjunto ya no existe.', 1 );
add_comment_meta( $ids['huerfana'], 'ivole_review_image2', 99999999 );

$ids['sin_foto'] = $crear( $producto_id, 'review', 'QA-SIN-FOTO: reseña de texto.', 1 );

$ids['pendiente'] = $crear( $producto_id, 'review', 'QA-PENDIENTE: esperando moderación.', 0 );
add_comment_meta( $ids['pendiente'], 'ivole_review_image2', $adjunto );

if ( $ids['_blog'] ) {
	$ids['blog'] = $crear( $ids['_blog'], 'comment', 'QA-BLOG: comentario con meta de foto.', 1 );
	add_comment_meta( $ids['blog'], 'ivole_review_image2', $adjunto );
}

update_option( $option, $ids, false );
if ( function_exists( 'wc_delete_product_transients' ) ) {
	wc_delete_product_transients( $producto_id );
}

echo wp_json_encode(
	array(
		'ids'           => $ids,
		'producto_url'  => get_permalink( $producto_id ),
		'blog_url'      => $ids['_blog'] ? get_permalink( $ids['_blog'] ) : null,
		'adjunto_large' => wp_get_attachment_image_url( $adjunto, 'large' ),
		'adjunto_thumb' => wp_get_attachment_image_url( $adjunto, 'thumbnail' ),
	)
) . PHP_EOL;
