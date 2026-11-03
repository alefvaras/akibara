<?php
/**
 * Condiciones del premio compartidas por la invitación y el formulario.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Reviews;

use Akibara\Rewards\Support\Options;

defined( 'ABSPATH' ) || exit;

final class RewardMessage {

	/** Importe de la oferta o de los puntos efectivamente acreditados. */
	public static function amount_label( ?int $points = null ): string {
		$points = $points ?? Options::review_points();
		return sprintf( '%s Puntos Akibara = %s CLP de descuento', number_format_i18n( $points ), Options::points_label( $points ) );
	}

	/**
	 * Describe la política vigente sin prometer un crédito incondicional.
	 *
	 * @param bool $include_choice Explicar cómo optar en el formulario.
	 * @return string Texto plano, vacío si no hay programa de reseñas.
	 */
	public static function text( bool $include_choice = true ): string {
		if ( ! Options::reviews_lane_on() ) {
			return '';
		}

		$every = Options::review_every_n();
		$parts = $include_choice
			? array( 'La invitación es para reseñar y solicitar puntos: debes adjuntar una foto real de tu compra.' )
			: array();
		$parts = array_merge( $parts, array(
			1 === $every
				? sprintf( 'Puedes obtener %s por una reseña con foto aprobada que cumpla los requisitos.', self::amount_label() )
				: sprintf( 'Puedes obtener %s por cada %s reseñas con foto aprobadas que cumplan los requisitos; las intermedias suman avance.', self::amount_label(), number_format_i18n( $every ) ),
			'Un premio por producto y cliente.',
		) );
		if ( 'yes' === (string) get_option( 'akb_rewards_review_require_verified', 'yes' ) ) {
			$parts[] = 'La compra debe estar verificada.';
		}
		$max = Options::review_max_per_user();
		if ( $max > 0 ) {
			$parts[] = sprintf( 'Tope de %s premios por cliente.', number_format_i18n( $max ) );
		}
		$parts[] = 'Necesitas una cuenta con el correo de tu compra. Si aún no tienes cuenta, el premio queda pendiente hasta que la crees y verifiques el correo.';
		return implode( ' ', $parts );
	}
}
