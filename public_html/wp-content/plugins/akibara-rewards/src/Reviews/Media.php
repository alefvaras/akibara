<?php
/**
 * Media — detección y validación de fotos de reseña.
 *
 * Compatibilidad por ingeniería inversa de CusRev (04-cusrev-ingenieria-
 * inversa.md): las fotos locales viven en `ivole_review_image2` como UNA
 * FILA DE COMMENT_META POR ADJUNTO (int, unique=false) — NO un array — y las
 * remotas en `ivole_review_image` (array con 'url').
 *
 * Esas DOS son las únicas claves con lectores vivos: la local la escribe
 * `attach_photo()` y la leen el carrusel del home, las superficies de foto,
 * el exportador de privacidad y el premio; la remota sólo tiene lectores,
 * ningún escritor propio (la escribía el tercero, hoy desinstalado). Las
 * metas de video y el contador agregado de CusRev (`ivole_media_count`) se
 * retiraron: nadie del árbol propio las escribía ni las leía.
 *
 * La foto REAL se valida con `wp_attachment_is('image')` — un video no
 * cuenta para el premio (decisión del dueño, heredada del gate del cupón).
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Reviews;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.2.0
 */
final class Media {

	public const META_LOCAL_IMAGE  = 'ivole_review_image2';
	public const META_REMOTE_IMAGE = 'ivole_review_image';

	/**
	 * ¿La reseña tiene al menos UNA foto real?
	 *
	 * Locales: cada fila es un ID de adjunto que debe seguir siendo imagen
	 * (el adjunto pudo borrarse). Remotas: basta la fila con 'url' no vacía
	 * (heredado del gate del cupón del mu-plugin).
	 *
	 * @param int $comment_id Reseña.
	 * @return bool
	 */
	public static function has_real_photo( int $comment_id ): bool {
		foreach ( (array) get_comment_meta( $comment_id, self::META_LOCAL_IMAGE ) as $attachment_id ) {
			if ( (int) $attachment_id > 0 && wp_attachment_is( 'image', (int) $attachment_id ) ) {
				return true;
			}
		}

		foreach ( (array) get_comment_meta( $comment_id, self::META_REMOTE_IMAGE ) as $remote ) {
			if ( is_array( $remote ) && ! empty( $remote['url'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Adjunta una foto local a una reseña con las claves compatibles y
	 * reparentado al producto (mismo comportamiento que la ruta local de
	 * CusRev: add_comment_meta unique=false + wp_update_post post_parent).
	 *
	 * @param int $comment_id    Reseña.
	 * @param int $attachment_id Adjunto (debe ser imagen).
	 * @param int $product_id    Producto para el reparentado.
	 * @return bool False si el adjunto no es una imagen.
	 */
	public static function attach_photo( int $comment_id, int $attachment_id, int $product_id ): bool {
		if ( $attachment_id <= 0 || ! wp_attachment_is( 'image', $attachment_id ) ) {
			return false;
		}

		add_comment_meta( $comment_id, self::META_LOCAL_IMAGE, $attachment_id, false );

		if ( $product_id > 0 ) {
			wp_update_post(
				array(
					'ID'          => $attachment_id,
					'post_parent' => $product_id,
				)
			);
		}

		return true;
	}
}
