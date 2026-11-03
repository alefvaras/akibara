<?php
/**
 * Alerts — el dueño se entera cuando algo del programa necesita una mano.
 *
 * Notifica proactivamente al administrador cuando un evento del programa requiere intervención manual.
 *
 * Avisa sólo lo que NO se arregla solo. Lo que se reintenta —un candado
 * ocupado, un correo que no salió— avisa recién cuando agota sus intentos.
 * Cada correo dice qué pasó, con cuánto, qué hacer, y trae el enlace directo.
 * El mismo aviso no se repite en el día: varias reversas corren por dos
 * caminos a la vez (el reembolso y el cambio de estado).
 *
 * El interruptor y los destinatarios viven en WooCommerce → Puntos y Reseñas
 * → Alertas.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Support;

use Akibara\Rewards\Points\Emails;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.4.1
 */
final class Alerts {

	/** Prefijo del transient que evita repetir el mismo aviso en el día. */
	private const TRANSIENT = 'akb_rw_alerta_';

	/**
	 * Cuelga los oyentes.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'akb_rewards_redemption_shortfall', array( $this, 'on_redemption_shortfall' ), 10, 4 );
		add_action( 'akb_rewards_reversal_failed', array( $this, 'on_reversal_failed' ), 10, 2 );
		add_action( 'akb_rewards_manual_review_needed', array( $this, 'on_manual_review_needed' ), 10, 2 );
		add_action( 'akb_rewards_review_pay_failed', array( $this, 'on_review_pay_failed' ), 10, 3 );
		add_action( 'akb_rewards_redemption_stuck', array( $this, 'on_redemption_stuck' ), 10, 1 );
		add_action( 'akb_rewards_email_gave_up', array( $this, 'on_email_gave_up' ), 10, 2 );
		add_action( 'akb_rewards_referral_pay_failed', array( $this, 'on_referral_pay_failed' ), 10, 3 );
	}

	/**
	 * El premio a quien invitó no se pudo acreditar (sistema ocupado).
	 *
	 * Un pedido Completado casi nunca vuelve a cambiar de estado, así que
	 * esperar «la próxima transición» dejaba al padrino sin su premio.
	 *
	 * @param \WC_Order $order    Pedido del invitado.
	 * @param int       $referrer Padrino.
	 * @param int       $reward   Puntos que debía cobrar.
	 * @return void
	 */
	public function on_referral_pay_failed( $order, $referrer, $reward ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$this->send(
			'referral_' . $order->get_id(),
			sprintf( 'Un premio de referido no se acreditó (pedido #%d)', $order->get_id() ),
			sprintf(
				'<p style="margin:0 0 12px">La compra del pedido <strong>#%d</strong> debía pagar %s Puntos Akibara a quien invitó a ese cliente (cuenta #%d), pero el sistema estaba ocupado y no se acreditaron.</p><p style="margin:0">Para reintentar, cambia el pedido a otro estado y vuelve a marcarlo como %s. El premio no se paga dos veces.</p>',
				(int) $order->get_id(),
				esc_html( number_format_i18n( (int) $reward ) ),
				(int) $referrer,
				// El estado que paga el premio lo elige el dueño en el panel.
				esc_html( wc_get_order_status_name( Options::referral_status() ) )
			),
			$order->get_edit_order_url(),
			'Abrir el pedido',
			$order
		);
	}

	/**
	 * Un pedido quedó con descuento de puntos que el saldo no cubría.
	 *
	 * @param \WC_Order $order     Pedido.
	 * @param int       $points    Puntos del descuento.
	 * @param int       $debited   Puntos que sí se cobraron.
	 * @param int       $shortfall Puntos sin respaldo.
	 * @return void
	 */
	public function on_redemption_shortfall( $order, $points, $debited, $shortfall ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$this->send(
			'shortfall_' . $order->get_id(),
			sprintf( 'Canje sin saldo suficiente en el pedido #%d', $order->get_id() ),
			sprintf(
				'<p style="margin:0 0 12px">El pedido <strong>#%d</strong> lleva un descuento de %s Puntos Akibara, pero al cobrarlos el saldo del cliente sólo cubría %s. Faltaron <strong>%s puntos</strong>, que la tienda descontó sin respaldo.</p><p style="margin:0">Pasa cuando el cobro no ocurre en la caja (por ejemplo, al revivir un pedido anulado después de que el cliente gastó los puntos devueltos). Revisa el pedido y decide con el cliente si se ajusta.</p>',
				(int) $order->get_id(),
				esc_html( number_format_i18n( (int) $points ) ),
				esc_html( number_format_i18n( (int) $debited ) ),
				esc_html( number_format_i18n( (int) $shortfall ) )
			),
			$order->get_edit_order_url(),
			'Abrir el pedido',
			$order
		);
	}

	/**
	 * Una devolución o reversa de puntos no se pudo cobrar completa.
	 *
	 * El disparador es un pedido (anulación/reembolso) o una reseña con foto retirada
	 * tras haber pagado: utiliza el hook unificado `akb_rewards_reversal_failed`.
	 *
	 * @param \WC_Order|\WP_Comment $sujeto   Pedido o reseña que disparó la reversa.
	 * @param int                    $faltante Puntos que no se recuperaron.
	 * @return void
	 */
	public function on_reversal_failed( $sujeto, $faltante ): void {
		if ( $sujeto instanceof \WC_Order ) {
			$this->send(
				'reversal_' . $sujeto->get_id() . '_' . (int) $faltante,
				sprintf( 'Puntos que no se pudieron descontar en el pedido #%d', $sujeto->get_id() ),
				sprintf(
					'<p style="margin:0 0 12px">Al anular o devolver el pedido <strong>#%d</strong> había que descontar Puntos Akibara que ese pedido había dado, y faltaron <strong>%s puntos</strong>: la cuenta ya los había gastado.</p><p style="margin:0">El detalle está en las notas del pedido. Si corresponde, corrígelo en WooCommerce → Puntos y Reseñas → «Otorgar o quitar puntos a mano».</p>',
					(int) $sujeto->get_id(),
					esc_html( number_format_i18n( (int) $faltante ) )
				),
				$sujeto->get_edit_order_url(),
				'Abrir el pedido',
				$sujeto
			);
			return;
		}

		if ( $sujeto instanceof \WP_Comment ) {
			$id = (int) $sujeto->comment_ID;
			$this->send(
				'review_reversal_' . $id . '_' . (int) $faltante,
				sprintf( 'Puntos que no se pudieron descontar al retirar la reseña #%d', $id ),
				sprintf(
					'<p style="margin:0 0 12px">Al dejar de aprobar o borrar la reseña <strong>#%d</strong> había que descontar Puntos Akibara que había pagado, y faltaron <strong>%s puntos</strong>: la cuenta ya los había gastado.</p><p style="margin:0">Si corresponde, corrígelo en WooCommerce → Puntos y Reseñas → «Otorgar o quitar puntos a mano».</p>',
					$id,
					esc_html( number_format_i18n( (int) $faltante ) )
				),
				admin_url( 'comment.php?action=editcomment&c=' . $id ),
				'Abrir la reseña',
				null,
				$sujeto
			);
		}
	}

	/**
	 * Se borró un reembolso que ya había descontado puntos.
	 *
	 * @param \WC_Order $order     Pedido.
	 * @param int       $refund_id Reembolso borrado.
	 * @return void
	 */
	public function on_manual_review_needed( $order, $refund_id ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$this->send(
			'refund_deleted_' . $order->get_id() . '_' . (int) $refund_id,
			sprintf( 'Se borró un reembolso con puntos en el pedido #%d', $order->get_id() ),
			sprintf(
				'<p style="margin:0 0 12px">Se borró el reembolso <strong>#%d</strong> del pedido <strong>#%d</strong>. Los Puntos Akibara que ese reembolso había descontado <strong>no se devuelven solos</strong>.</p><p style="margin:0">Revisa el historial de puntos del cliente y corrígelo en WooCommerce → Puntos y Reseñas → «Otorgar o quitar puntos a mano».</p>',
				(int) $refund_id,
				(int) $order->get_id()
			),
			$order->get_edit_order_url(),
			'Abrir el pedido',
			$order
		);
	}

	/**
	 * Una reseña aprobada no acreditó sus puntos.
	 *
	 * @param \WP_Comment $comment Reseña.
	 * @param int         $user_id Cliente.
	 * @param int         $points  Puntos que debía cobrar.
	 * @return void
	 */
	public function on_review_pay_failed( $comment, $user_id, $points ): void {
		if ( ! $comment instanceof \WP_Comment ) {
			return;
		}

		$id = (int) $comment->comment_ID;
		$this->send(
			'review_' . $id,
			sprintf( 'Una reseña aprobada no acreditó sus puntos (#%d)', $id ),
			sprintf(
				'<p style="margin:0 0 12px">La reseña <strong>#%d</strong> quedó aprobada, pero sus %s Puntos Akibara no se acreditaron a la cuenta #%d: el sistema estaba ocupado en ese momento.</p><p style="margin:0">Para reintentar, desapruébala y vuelve a aprobarla en Productos → Valoraciones. El premio no se paga dos veces.</p>',
				$id,
				esc_html( number_format_i18n( (int) $points ) ),
				(int) $user_id
			),
			admin_url( 'comment.php?action=editcomment&c=' . $id ),
			'Abrir la reseña',
			null,
			$comment
		);
	}

	/**
	 * Una operación de canje agotó sus reintentos.
	 *
	 * @param \WC_Order $order Pedido.
	 * @return void
	 */
	public function on_redemption_stuck( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$this->send(
			'stuck_' . $order->get_id(),
			sprintf( 'Puntos canjeados sin resolver en el pedido #%d', $order->get_id() ),
			sprintf(
				'<p style="margin:0 0 12px">El pedido <strong>#%d</strong> tiene un movimiento de puntos canjeados que no se pudo completar tras varios reintentos: devolver los puntos de un pedido anulado, o cobrar el canje de uno vigente.</p><p style="margin:0">Revisa el historial de puntos del cliente y las notas del pedido, y corrígelo en WooCommerce → Puntos y Reseñas → «Otorgar o quitar puntos a mano» si hace falta.</p>',
				(int) $order->get_id()
			),
			$order->get_edit_order_url(),
			'Abrir el pedido',
			$order
		);
	}

	/**
	 * Un correo al cliente no salió tras todos sus intentos.
	 *
	 * @param string           $hook Reintento agotado.
	 * @param array<int,mixed> $args Sus argumentos.
	 * @return void
	 */
	public function on_email_gave_up( $hook, $args ): void {
		$args = array_values( (array) $args );
		$id   = (int) ( $args[0] ?? 0 );

		switch ( (string) $hook ) {
			case \Akibara\Rewards\Reviews\Invitations::HOOK:
				$que    = sprintf( 'la invitación a reseñar del pedido #%d', $id );
				$order  = wc_get_order( $id );
				$enlace = $order instanceof \WC_Order ? $order->get_edit_order_url() : admin_url( 'admin.php?page=wc-orders' );
				break;
			case Emails::HOOK_RETRY_ORDER:
				$que    = sprintf( 'el correo de puntos del pedido #%d', $id );
				$enlace = admin_url( 'post.php?post=' . $id . '&action=edit' );
				break;
			case Emails::HOOK_RETRY_REVIEW:
				$que    = sprintf( 'el correo del premio de la reseña #%d', $id );
				$enlace = admin_url( 'comment.php?action=editcomment&c=' . $id );
				break;
			case Emails::HOOK_RETRY_REFERRAL:
				$que    = sprintf( 'el correo a quien invitó (cuenta #%d)', $id );
				$enlace = admin_url( 'user-edit.php?user_id=' . $id );
				break;
			default:
				return;
		}

		$this->send(
			'email_' . md5( (string) $hook . '|' . implode( '|', array_map( 'strval', array_slice( $args, 0, -1 ) ) ) ),
			'Un correo de Puntos Akibara no salió',
			sprintf(
				'<p style="margin:0 0 12px">Tras tres intentos en doce horas no salió %s.</p><p style="margin:0">Si también fallan otros correos de la tienda, revisa el correo del sitio (la conexión de envío). Si no, revisa que la dirección del cliente esté bien escrita.</p>',
				esc_html( $que )
			),
			$enlace,
			'Abrir'
		);
	}

	/**
	 * Envía el aviso a los destinatarios del panel, una vez al día por clave.
	 *
	 * @param string           $key     Qué aviso es (para no repetirlo).
	 * @param string           $subject Asunto sin prefijo.
	 * @param string           $body    Cuerpo HTML.
	 * @param string           $url     Enlace del botón.
	 * @param string           $label   Texto del botón.
	 * @param \WC_Order|null   $order   Pedido del aviso, si lo hay.
	 * @param \WP_Comment|null $comment Reseña del aviso, si la hay.
	 * @return void
	 */
	private function send( string $key, string $subject, string $body, string $url, string $label, $order = null, $comment = null ): void {
		// Al registro de WooCommerce SIEMPRE, antes que el interruptor y la marca
		// del día: el correo que no sale suele ser la causa de la alerta, y con
		// las alertas apagadas el registro es la única traza.
		Log::error( 'Alerta para el dueño: ' . $subject );

		if ( ! Options::alerts_on() || ! function_exists( 'WC' ) ) {
			return;
		}

		$destinatarios = Options::alerts_recipients();
		if ( array() === $destinatarios ) {
			return;
		}

		$marca = self::TRANSIENT . md5( $key );
		if ( false !== get_transient( $marca ) ) {
			return;
		}

		$enviado = Emails::send_branded(
			implode( ', ', $destinatarios ),
			'[Akibara Puntos] ' . $subject,
			'Puntos Akibara: revisar',
			$body . Emails::cta( $label, $url ),
			Emails::context( 'admin_alert', 0, $order, $comment )
		);

		if ( $enviado ) {
			set_transient( $marca, 1, DAY_IN_SECONDS );
		}
	}
}
