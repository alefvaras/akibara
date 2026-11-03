<?php
/**
 * ReviewNudge — Aviso de puntos por reseña con foto en el correo de entrega.
 *
 * Superficie complementaria en el correo de pedido completado: un párrafo con la
 * promesa exacta del programa de recompensas. Solo opera cuando el motor de
 * reseñas está habilitado.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Reviews;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.2.0
 */
final class ReviewNudge {

	/**
	 * Cuelga el párrafo en los detalles del correo.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'woocommerce_email_order_details', array( $this, 'render' ), 25, 4 );
	}

	/**
	 * Pinta el empujón solo en el correo de pedido COMPLETADO del cliente.
	 *
	 * @param \WC_Order $order         Pedido.
	 * @param bool      $sent_to_admin Si va al admin.
	 * @param bool      $plain_text    Si es texto plano.
	 * @param object    $email         Instancia del correo.
	 * @return void
	 */
	public function render( $order, $sent_to_admin, $plain_text, $email = null ): void {

		// El dueño selecciona los correos que incluyen el aviso.
		$ids = array_filter( array_map( 'trim', explode( ',', (string) get_option( 'akb_rewards_nudge_email_ids', 'customer_completed_order' ) ) ) );

		if ( $sent_to_admin || null === $email || ! in_array( (string) ( $email->id ?? '' ), $ids, true ) ) {
			return;
		}

		$reglas = RewardMessage::text();
		if ( '' === $reglas || ! Invitations::enabled() || 'yes' !== (string) get_option( 'akb_rewards_review_nudge_enabled', 'yes' ) ) {
			return;
		}

		// Respetar la preferencia de baja del cliente (InviteOptOut) para no incluir
		// recordatorios de reseña en los correos transaccionales del pedido.
		if ( $order instanceof \WC_Order && InviteOptOut::opted_out( (string) $order->get_billing_email() ) ) {
			return;
		}

		$sent = $order instanceof \WC_Order && '' !== (string) $order->get_meta( Invitations::META_SENT );
		if ( $sent ) {
			$texto = 'Busca nuestro correo «¿Qué te pareció tu pedido?»: lleva tu enlace personal para reseñar y solicitar puntos, con foto obligatoria.';
		} else {
			$texto = 'La invitación para escribir tu reseña llega en un correo separado. ';
			$texto .= sprintf(
				'Despacho: %s días después de «%s». Retiro o entrega directa: %s días después de «%s».',
				number_format_i18n( Invitations::delay_days() ),
				wc_get_order_status_name( Invitations::trigger_status() ),
				number_format_i18n( Invitations::pickup_delay_days() ),
				wc_get_order_status_name( Invitations::pickup_status() )
			);
			$texto .= ' Los pedidos a pedido o en preventa esperan a que sus productos estén disponibles para entregar. Escribe tu opinión cuando hayas recibido la compra.';
		}
		$texto .= ' ' . $reglas;

		// El enlace personal se emite al enviar la invitación, nunca al pintar
		// un correo de Procesando. No se dirige al formulario nativo sin foto.
		if ( $plain_text ) {
			echo "\n" . esc_html( '📸 ' . $texto ) . "\n";
			return;
		}

		printf(
			'<p class="akb-email-callout" style="margin:18px 0;padding:14px 18px;background:#fbf8f0;border:1px solid #141416;border-left:4px solid #E60012;border-radius:0;color:#2b2b2b;-webkit-text-fill-color:#2b2b2b;font-size:14px;line-height:1.5;">📸 %s</p>',
			esc_html( $texto )
		);
	}
}
