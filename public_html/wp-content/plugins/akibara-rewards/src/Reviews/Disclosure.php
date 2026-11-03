<?php
/**
 * Disclosure — una reseña premiada dice que fue premiada.
 *
 * Resuelve el aviso de incentivo para las reseñas que cobraron puntos o
 * contaron para un premio. La presentación responde a la opción del panel.
 *
 * Va por `comment_text` a prioridad 24: después de la insignia de reseña
 * transcrita (20) y antes de la foto (26), así el orden es texto → insignia →
 * aviso → foto.
 *
 * La visualización del aviso responde a la opción `akb_rewards_disclosure_enabled`.
 * El criterio para determinar si una reseña fue incentivada permanece activo
 * independientemente del interruptor visual, dado que es requerido por filtros
 * de exclusión y datos estructurados.
 *
 * Es la única autoridad de «¿esta reseña fue incentivada?»: la usan también el
 * carrusel del home y el JSON-LD. Cuenta la que cobró puntos y, con «premiar
 * cada N reseñas», la intermedia que sumó para el premio sin cobrar.
 *
 * Se registra fuera del motor de reseñas: si Customer Reviews vuelve y el motor
 * se duerme, las reseñas ya pagadas no pueden perder el aviso.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Reviews;

use Akibara\Rewards\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.4.1
 */
final class Disclosure {

	/** La reseña cobró puntos. */
	public const TEXTO_PREMIADA = 'Quien escribió esta reseña ganó Puntos Akibara por publicarla con foto.';

	/** «Premiar cada N reseñas»: no cobró, pero cuenta para el premio. */
	public const TEXTO_SUMA = 'Quien escribió esta reseña sumó para un premio de Puntos Akibara.';

	public function register(): void {
		add_filter( 'comment_text', array( $this, 'append_notice' ), 24, 2 );
	}

	/**
	 * El aviso de una reseña, si el dueño lo tiene encendido.
	 *
	 * @param int $comment_id Reseña.
	 * @return string
	 */
	public static function visible_notice_for( int $comment_id ): string {
		return Options::disclosure_on() ? self::notice_for( $comment_id ) : '';
	}

	/**
	 * El aviso que corresponde a una reseña; '' si no fue incentivada.
	 *
	 * El sello `pending_account` (invitado que todavía no cobró) castea a 0:
	 * aún no es un premio.
	 *
	 * @param int $comment_id Reseña.
	 * @return string
	 */
	public static function notice_for( int $comment_id ): string {
		if ( $comment_id <= 0 ) {
			return '';
		}

		if ( (int) get_comment_meta( $comment_id, ReviewPoints::META_POINTS_PAID, true ) > 0 ) {
			return self::TEXTO_PREMIADA;
		}

		return (int) get_comment_meta( $comment_id, ReviewPoints::META_COUNTED, true ) > 0 ? self::TEXTO_SUMA : '';
	}

	/**
	 * Añade el aviso al final del texto de una reseña premiada.
	 *
	 * @param string           $text    Texto ya filtrado.
	 * @param \WP_Comment|null $comment Comentario, cuando el llamador lo pasa.
	 * @return string
	 */
	public function append_notice( $text, $comment = null ): string {
		$text = (string) $text;

		if ( ! $comment instanceof \WP_Comment ) {
			return $text;
		}
		if ( 'product' !== get_post_type( (int) $comment->comment_post_ID ) ) {
			return $text;
		}
		$aviso = self::visible_notice_for( (int) $comment->comment_ID );
		if ( '' === $aviso ) {
			return $text;
		}

		// «Clara y prominente» es la vara de Google para una reseña incentivada:
		// sin opacidad, con su propio recuadro y a tamaño de lectura. En gris
		// claro se leía como letra chica, que es justo lo que la guía descarta.
		return $text . '<p class="akb-rw-disclosure" style="margin:8px 0 0;padding:6px 10px;border-left:3px solid var(--aki-red,#E60012);background:rgba(230,0,18,.06);font-size:0.9em;line-height:1.4">'
			. esc_html( $aviso )
			. '</p>';
	}
}
