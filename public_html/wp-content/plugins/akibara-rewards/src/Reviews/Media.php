<?php
/**
 * Media — detección y validación de fotos de reseña.
 *
 * Las fotos de reseña viven en `_akb_review_image_id` como una fila de
 * `commentmeta` por adjunto (int, `unique=false`) — no un array — y se
 * validan contra `wp_attachment_is('image')` (un video o un adjunto borrado
 * no cuentan para el premio).
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Reviews;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.2.0
 * @api
 */
final class Media {

	public const META_IMAGE_ID    = '_akb_review_image_id';
	public const META_LOCAL_IMAGE = self::META_IMAGE_ID;

	/**
	 * ¿La reseña tiene al menos UNA foto real?
	 *
	 * Cada fila es un ID de adjunto que debe seguir siendo imagen (el adjunto
	 * pudo borrarse).
	 *
	 * @param int $comment_id Reseña.
	 * @return bool
	 */
	public static function has_real_photo( int $comment_id ): bool {
		foreach ( (array) get_comment_meta( $comment_id, self::META_IMAGE_ID ) as $attachment_id ) { // phpcs:ignore WordPress.WP.GetMetaSingle.Missing
			if ( (int) $attachment_id > 0 && wp_attachment_is( 'image', (int) $attachment_id ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Adjunta una foto local a una reseña y la reparenta al producto.
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

		add_comment_meta( $comment_id, self::META_IMAGE_ID, $attachment_id, false );

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
