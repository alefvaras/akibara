<?php
/**
 * Moderation — retención de reseñas de producto hasta su aprobación.
 *
 * Fuerza `comment_approved = 0` para toda reseña de producto que entre por
 * `wp_new_comment` cuando el interruptor de moderación está activo.
 * Límites de la integración:
 *   - `wp_insert_comment` directo NO pasa por `pre_comment_approved`: las
 *     reseñas de AdminReviews las aprueba el propio dueño al crearlas, y las
 *     de CusRev (mientras viva) se moderan encendiendo su propia opción
 *     `ivole_enable_moderation` — paso manual del runbook pre-cutover.
 *   - El filtro corre después de la aprobación automática de WordPress para
 *     autores conocidos, por lo que las reseñas también quedan retenidas.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Reviews;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.2.0
 */
final class Moderation {

	/**
	 * Cuelga el filtro de pre-aprobación.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'pre_comment_approved', array( $this, 'hold_product_reviews' ), 50, 2 );
	}

	/**
	 * Toda reseña de producto queda EN ESPERA hasta que el dueño la acepte.
	 *
	 * @param int|string|\WP_Error $approved    Veredicto previo de WordPress.
	 * @param array<string,mixed>  $commentdata Datos del comentario.
	 * @return int|string|\WP_Error
	 */
	public function hold_product_reviews( $approved, $commentdata ) {
		if ( 'yes' !== (string) get_option( 'akb_rewards_moderate_reviews', 'yes' ) ) {
			return $approved;
		}

		if ( $approved instanceof \WP_Error || 'spam' === $approved ) {
			return $approved; // Jamás rescatar spam ni pisar un error.
		}

		// REST/XML-RPC pueden enviar los tipos '' o 'comment'. Toda reseña
		// top-level de producto se modera; pingbacks y trackbacks quedan fuera.
		$type = (string) ( $commentdata['comment_type'] ?? '' );
		if ( ! in_array( $type, array( 'review', 'comment', '' ), true ) ) {
			return $approved;
		}

		if ( (int) ( $commentdata['comment_parent'] ?? 0 ) > 0 ) {
			return $approved; // Las respuestas del hilo no son reseñas.
		}

		$post_id = (int) ( $commentdata['comment_post_ID'] ?? 0 );
		if ( 'product' !== get_post_type( $post_id ) ) {
			return $approved;
		}

		return 0;
	}
}
