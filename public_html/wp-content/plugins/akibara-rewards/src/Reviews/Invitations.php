<?php
/**
 * Invitations — invitación a reseñar por correo con enlace de TOKEN (Fase 2b).
 *
 * Diseño sellado (00-DISENO §Flujo de invitación):
 *   - Dos rutas configurables: despacho y entrega directa o retiro.
 *     La primera agenda UN solo job de Action Scheduler por pedido.
 *   - Compuerta de preventa/a-pedido de business
 *     (CompletionPolicy::can_complete): un pedido con preventa no
 *     invita hasta que todos sus productos están listos; si se revierte, se
 *     desagenda. Esto REEMPLAZA al OrderPreventaAdapter (manifiesto de baja,
 *     ítem C) — requisito previo cumplido aquí.
 *   - Token por pedido en {prefix}akb_rewards_review_forms: caducidad
 *     configurable (30 días), un solo uso, re-invitación posible tras
 *     reversa (tokens históricos quedan auditablemente en la tabla).
 *   - Correo brandeado sellado SOLO con transporte confirmado (disciplina
 *     de Emails.php); en fallo, reintento acotado vía el mismo scheduler.
 *   - Interruptor global de activación inmediata en un solo clic.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Reviews;

use Akibara\Rewards\Support\Options;

use Akibara\Rewards\Points\Emails;
use Akibara\Rewards\Support\Log;
use Akibara\Rewards\Support\OrderStates;
use Akibara\Rewards\Support\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.3.0
 */
final class Invitations {

	public const HOOK           = 'akb_rewards_send_review_invite';
	public const META_SCHEDULED = '_akb_rewards_invite_scheduled';
	/**
	 * Estado con el que se agendó ESTA invitación.
	 *
	 * Preserva el disparador de la invitación en cola aunque el dueño cambie
	 * posteriormente el estado configurado para las nuevas invitaciones.
	 */
	public const META_TRIGGER   = '_akb_rewards_invite_trigger';
	public const META_SENT      = '_akb_rewards_invite_sent';
	public const META_ATTEMPTS  = '_akb_rewards_invite_attempts';
	public const META_DUE       = '_akb_rewards_invite_due';
	public const META_REASON    = '_akb_rewards_invite_reason';
	private const MAX_ATTEMPTS  = 3;

	/**
	 * ¿El carril está encendido? (clic del dueño).
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		return 'yes' === (string) get_option( 'akb_rewards_invite_enabled', 'yes' );
	}

	/**
	 * Estado del pedido que dispara la invitación.
	 *
	 * @return string
	 */
	public static function trigger_status(): string {
		$status = sanitize_key( (string) get_option( 'akb_rewards_invite_status', 'completed' ) );
		return '' !== $status ? $status : 'completed';
	}

	/**
	 * Días de espera tras el estado disparador.
	 *
	 * @return int
	 */
	public static function delay_days(): int {
		return max( 0, (int) get_option( 'akb_rewards_invite_delay_days', 3 ) );
	}

	/** Estado alternativo para una entrega directa o retiro. */
	public static function pickup_status(): string {
		return sanitize_key( (string) get_option( 'akb_rewards_invite_pickup_status', 'completed' ) );
	}

	/** Días corridos de la ruta de entrega directa; hereda el plazo existente. */
	public static function pickup_delay_days(): int {
		return max( 0, (int) get_option( 'akb_rewards_invite_pickup_delay_days', self::delay_days() ) );
	}

	/** Una ruta configurada, independiente de la empresa de transporte. */
	public static function is_trigger( string $status ): bool {
		return in_array( $status, array( self::trigger_status(), self::pickup_status() ), true );
	}

	/**
	 * Requisitos comunes, también comprobados cuando llega el día de envío.
	 *
	 * @param \WC_Order $order Pedido.
	 * @return string Motivo para esperar; vacío si puede seguir.
	 */
	public static function waiting_reason( \WC_Order $order ): string {
		$status = (string) $order->get_status();
		if ( OrderStates::is_down( $status ) ) {
			return 'Pedido cancelado, fallido o reembolsado.';
		}
		if ( in_array( $status, array( 'pending', 'on-hold', 'checkout-draft' ), true ) || ! $order->get_date_paid() ) {
			return 'Esperando confirmación del pago.';
		}
		if ( class_exists( '\\Akibara\\Business\\Modules\\OrderPreventa\\CompletionPolicy' ) ) {
			// La disponibilidad sigue perteneciendo a business. Sólo la reseña
			// admite aquí despacho O retiro; no altera cómo se completa un pedido.
			$ready = \Akibara\Business\Modules\OrderPreventa\CompletionPolicy::can_complete( $order );
			if ( ! $ready || ! apply_filters( 'akb_order_preventa_can_request_review', $ready, $order ) ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- contrato público de business.
				return 'Esperando productos a pedido o preventa disponibles para entregar.';
			}
		}
		return '';
	}

	/** Una transición posterior no invalida la ruta con la que se agendó. */
	private static function still_dispatched( \WC_Order $order ): bool {
		$status  = (string) $order->get_status();
		$trigger = (string) $order->get_meta( self::META_TRIGGER );
		return '' !== $trigger
			? ( OrderStates::is_at_or_after( $status, $trigger ) || $status === self::pickup_status() )
			: self::is_trigger( $status );
	}

	/**
	 * Vida del token en días.
	 *
	 * @return int
	 */
	public static function token_days(): int {
		return max( 1, (int) get_option( 'akb_rewards_invite_token_days', 30 ) );
	}

	/**
	 * Cuelga agenda, desagenda y el job.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_status_changed' ), 20, 3 );
		add_action( self::HOOK, array( $this, 'send_invite' ) );
		add_action( 'woocommerce_admin_order_data_after_order_details', array( new AdminOverview(), 'render_order' ) );
	}

	/**
	 * Agenda al entrar al estado disparador; desagenda si el pedido se cae.
	 *
	 * @param int    $order_id   Pedido.
	 * @param string $old_status Estado anterior (sin usar).
	 * @param string $new_status Estado nuevo.
	 * @return void
	 */
	public function on_status_changed( $order_id, $old_status, $new_status ): void {
		unset( $old_status );

		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		$order_id   = (int) $order_id;
		$new_status = (string) $new_status;

		if ( OrderStates::is_down( $new_status ) ) {
			as_unschedule_all_actions( self::HOOK, array( $order_id ) );
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		if ( '' !== (string) $order->get_meta( self::META_SENT ) ) {
			return; // Ya invitado en la historia del pedido.
		}

		$reason = self::waiting_reason( $order );
		if ( '' !== $reason || ( ! self::is_trigger( $new_status ) && ! self::still_dispatched( $order ) ) ) {
			as_unschedule_all_actions( self::HOOK, array( $order_id ) );
			$order->update_meta_data( self::META_REASON, '' !== $reason ? $reason : 'Esperando despacho al transportista o entrega directa.' );
			$order->save_meta_data();
			return;
		}
		if ( ! self::enabled() || ! self::is_trigger( $new_status ) ) {
			return;
		}

		if ( false !== as_next_scheduled_action( self::HOOK, array( $order_id ) ) ) {
			return; // Un solo job por pedido.
		}

		$days = $new_status === self::trigger_status() ? self::delay_days() : self::pickup_delay_days();
		$due  = time() + $days * DAY_IN_SECONDS;
		$job  = as_schedule_single_action(
			$due,
			self::HOOK,
			array( $order_id ),
			'',
			true
		);
		if ( ! $job ) {
			$order->update_meta_data( self::META_REASON, 'No se pudo programar la invitación. Revisa la cola de acciones.' );
			$order->save_meta_data();
			return;
		}
		$order->update_meta_data( self::META_DUE, $due );
		$order->update_meta_data( self::META_REASON, '' );
		$order->update_meta_data( self::META_SCHEDULED, current_time( 'mysql' ) );
		// Guarda la ruta elegida para respetar la cola si cambian los ajustes.
		$order->update_meta_data( self::META_TRIGGER, $new_status );
		$order->save_meta_data();
	}

	/**
	 * El job: crea el token y manda el correo. Sella SOLO con transporte
	 * confirmado; en fallo re-agenda con tope de intentos.
	 *
	 * @param int $order_id Pedido.
	 * @return void
	 */
	public function send_invite( $order_id ): void {
		if ( ! self::enabled() ) {
			return;
		}

		$order = wc_get_order( (int) $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		if ( '' !== (string) $order->get_meta( self::META_SENT ) ) {
			return; // Idempotente: job duplicado o reintento tardío.
		}
		if ( (int) $order->get_meta( self::META_ATTEMPTS ) >= self::MAX_ATTEMPTS ) {
			return; // Un job duplicado no vuelve a enviar tras agotar los intentos.
		}

		// Revalida pago, disponibilidad y avance sin cambiar la política
		// guardada cuando se creó la invitación.
		$reason = self::waiting_reason( $order );
		if ( '' !== $reason || ! self::still_dispatched( $order ) ) {
			$reason = '' !== $reason ? $reason : 'El pedido volvió a un estado anterior al despacho o retiro.';
			$order->update_meta_data( self::META_REASON, $reason );
			$order->save_meta_data();
			$order->add_order_note(
				'Invitación a reseñar detenida: ' . $reason
			);
			return;
		}

		$to = (string) $order->get_billing_email();
		if ( '' === $to ) {
			$order->update_meta_data( self::META_REASON, 'No enviada: el pedido no tiene correo de contacto.' );
			$order->save_meta_data();
			return;
		}

		// Comprueba la baja al enviar: puede haberse solicitado después de agendar.
		if ( InviteOptOut::opted_out( $to ) ) {
			$order->update_meta_data( self::META_REASON, 'No enviada: el cliente pidió no recibir invitaciones.' );
			$order->save_meta_data();
			return;
		}

		$token = self::issue_token( (int) $order->get_id() );
		if ( '' === $token ) {
			return; // Infraestructura de DB: la nota queda en el log.
		}

		$sent = $this->deliver( $order, $to, $token );

		if ( $sent ) {
			$order->update_meta_data( self::META_SENT, current_time( 'mysql' ) );
			$order->save_meta_data();
			return;
		}

		$attempts = (int) $order->get_meta( self::META_ATTEMPTS ) + 1;
		$order->update_meta_data( self::META_ATTEMPTS, $attempts );
		$order->save_meta_data();

		if ( $attempts < self::MAX_ATTEMPTS && function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + 6 * HOUR_IN_SECONDS, self::HOOK, array( (int) $order->get_id() ) );
		} elseif ( self::MAX_ATTEMPTS === $attempts ) {
			$order->add_order_note( 'La invitación a reseñar no salió tras tres intentos. Revisa la dirección del cliente y la conexión de correo de la tienda.' );
			/**
			 * El circuito de alertas también cubre la invitación agotada.
			 *
			 * @param string           $hook Trabajo agotado.
			 * @param array<int,mixed> $args Pedido e intento final.
			 */
			do_action( 'akb_rewards_email_gave_up', self::HOOK, array( (int) $order->get_id(), $attempts ) );
		}
	}

	/**
	 * Emite un token nuevo para el pedido.
	 *
	 * @param int $order_id Pedido.
	 * @return string '' si el insert falló.
	 */
	public static function issue_token( int $order_id ): string {
		global $wpdb;

		$token = wp_generate_password( 40, false, false );

		$ok = $wpdb->insert(
			Schema::review_forms_table(),
			array(
				'order_id'   => $order_id,
				'token'      => $token,
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() + self::token_days() * DAY_IN_SECONDS ),
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s' )
		);

		if ( false === $ok ) {
			Log::error( sprintf( 'Token de invitación no emitido para el pedido #%d: %s', $order_id, (string) $wpdb->last_error ) );
			return '';
		}

		return $token;
	}

	/**
	 * Correo brandeado de invitación con el enlace por token.
	 *
	 * @param \WC_Order $order Pedido.
	 * @param string    $to    Destinatario.
	 * @param string    $token Token emitido.
	 * @return bool True si el transporte confirmó.
	 */
	private function deliver( \WC_Order $order, string $to, string $token ): bool {
		$nombre = trim( (string) $order->get_billing_first_name() );
		$url    = ReviewForm::url_for( $token );

		$rewards = Options::reviews_lane_on();
		$premio = $rewards
			? '<p style="margin:0 0 12px">Cuéntanos tu experiencia con una foto real de tu compra para solicitar puntos. La reseña y el premio quedan pendientes de revisión.</p>'
			: '<p style="margin:0 0 12px">Cuéntanos tu experiencia en una reseña voluntaria sin puntos; la foto es opcional.</p>';
		$reglas = RewardMessage::text();
		if ( '' !== $reglas ) {
			$premio .= '<p style="margin:0 0 12px">' . esc_html( $reglas ) . '</p>';
		}

		// Este correo PIDE algo; los demás del programa cuentan un hecho. Por
		// eso —y sólo aquí— lleva su salida: un enlace visible al pie y la
		// cabecera que pinta el «Cancelar suscripción» nativo de Gmail.
		// Con firma propia, NO con el token del formulario: ese secreto publica
		// reseñas y acredita puntos, y esta URL viaja en una cabecera que leen
		// pasarelas de correo y servicios de baja masiva.
		$baja = InviteOptOut::url_for( $order );

		$dias_token = self::token_days();
		$plazo      = 1 === $dias_token ? '1 día' : sprintf( '%d días', $dias_token );

		$body = sprintf(
			'<p style="margin:0 0 12px">%s¿Qué te pareció tu pedido? Tu opinión ayuda a otros coleccionistas a decidir.</p>
			%s
			%s
			<p style="margin:12px 0 0;font-size:12px;opacity:.7">El enlace es personal y vence en %s.</p>
			<p style="margin:8px 0 0;font-size:12px;opacity:.7">¿Prefieres que no te pidamos reseñas? <a href="%s" style="color:inherit">Dínoslo aquí</a> y dejamos de hacerlo; los correos de tus pedidos y tus puntos seguirán llegando.</p>',
			'' !== $nombre ? esc_html( $nombre ) . ', ' : '',
			$premio,
			Emails::cta( $rewards ? 'Reseñar y obtener puntos' : 'Escribir mi reseña', $url ),
			esc_html( $plazo ),
			esc_url( $baja )
		);

		return Emails::send_branded(
			$to,
			'[Akibara] ¿Qué te pareció tu pedido?',
			'Cuéntanos qué te pareció',
			$body,
			Emails::context( 'review_invite', (int) $order->get_customer_id(), $order ),
			array(
				'List-Unsubscribe: <' . $baja . '>',
				'List-Unsubscribe-Post: List-Unsubscribe=One-Click',
			)
		);
	}
}
