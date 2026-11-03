<?php
/**
 * Read-only overview of review moderation, rewards and invitations.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Reviews;

use Akibara\Rewards\Support\Options;
use Akibara\Rewards\Support\SettingsPage;

defined( 'ABSPATH' ) || exit;

/** Review operations at the top of the program settings. */
final class AdminOverview {

	private const SAMPLE_LIMIT = 10;

	/**
	 * Read bounded operational data without querying the order storage directly.
	 *
	 * @internal
	 * @return array<string,mixed>
	 */
	public function snapshot(): array {
		if ( ! current_user_can( SettingsPage::CAPABILITY ) ) {
			return array();
		}

		$data = array( 'pending' => 0, 'approved' => 0, 'queue' => null, 'next' => null, 'orders' => array() );
		foreach ( array( 'pending' => 'hold', 'approved' => 'approve' ) as $key => $status ) {
			$data[ $key ] = (int) get_comments(
				array(
					'post_type' => 'product',
					'type__in'  => array( 'review', 'comment' ),
					'parent'    => 0,
					'status'    => $status,
					'count'     => true,
				)
			);
		}

		if ( function_exists( 'as_get_scheduled_actions' ) ) {
			$actions = as_get_scheduled_actions(
				array(
					'hook'     => Invitations::HOOK,
					'status'   => 'pending',
					'per_page' => self::SAMPLE_LIMIT + 1,
					'orderby'  => 'date',
					'order'    => 'ASC',
				)
			);
			$data['queue'] = count( $actions );
			foreach ( $actions as $action ) {
				$date = $action->get_schedule()->get_date();
				if ( $date instanceof \DateTimeInterface ) {
					$data['next'] = $date->getTimestamp();
					break;
				}
			}
		}

		if ( function_exists( 'wc_get_orders' ) ) {
			$orders = wc_get_orders( array( 'type' => 'shop_order', 'limit' => self::SAMPLE_LIMIT, 'orderby' => 'date', 'order' => 'DESC' ) );
			foreach ( $orders as $order ) {
				if ( ! $order instanceof \WC_Order ) {
					continue;
				}
				$next = function_exists( 'as_next_scheduled_action' ) ? as_next_scheduled_action( Invitations::HOOK, array( (int) $order->get_id() ) ) : false;
				$data['orders'][] = array(
					'id'        => (int) $order->get_id(),
					'url'       => $order->get_edit_order_url(),
					'status'    => (string) $order->get_status(),
					'scheduled' => (string) $order->get_meta( Invitations::META_SCHEDULED ),
					'sent'      => (string) $order->get_meta( Invitations::META_SENT ),
					'failures'  => max( 0, (int) $order->get_meta( Invitations::META_ATTEMPTS ) ),
					'next'      => is_int( $next ) ? $next : null,
					'running'   => true === $next,
					'reason'    => self::order_reason( $order ),
				);
			}
		}

		return $data;
	}

	/**
	 * Render the current workflow and read-only invitation sample.
	 *
	 * @internal
	 */
	public function render(): void {
		if ( ! current_user_can( SettingsPage::CAPABILITY ) ) {
			return;
		}

		$data       = $this->snapshot();
		$engine     = Options::reviews_engine_on();
		$lane       = Options::reviews_lane_on();
		$invites    = $engine && Invitations::enabled();
		$moderation = 'yes' === (string) get_option( 'akb_rewards_moderate_reviews', 'yes' );
		$labels     = SettingsPage::order_status_choices();
		$status     = $labels[ Invitations::trigger_status() ] ?? Invitations::trigger_status();
		$pickup     = $labels[ Invitations::pickup_status() ] ?? Invitations::pickup_status();
		$reviews    = admin_url( 'edit.php?post_type=product&page=product-reviews' );
		$queue_url  = admin_url( 'admin.php?page=wc-status&tab=action-scheduler&s=' . rawurlencode( Invitations::HOOK ) );

		echo '<section aria-labelledby="akb-review-overview" class="akb-review-overview">';
		echo '<style>.akb-review-overview{background:#fff;border:1px solid #c3c4c7;padding:16px;margin:20px 0}.akb-review-overview .akb-review-steps{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,260px),1fr));gap:20px}.akb-review-overview h3{margin:0 0 10px}.akb-review-overview .akb-review-links{display:flex;flex-wrap:wrap;gap:8px}.akb-review-overview .akb-review-table{overflow:auto}.akb-review-overview th,.akb-review-overview td{vertical-align:top}.akb-review-overview .akb-review-table table{min-width:620px}</style>';
		echo '<h2 id="akb-review-overview">Gestión de reseñas</h2>';
		if ( ! $engine ) {
			echo '<div class="notice notice-warning inline"><p><strong>El motor propio de reseñas está inactivo.</strong> CusRev, cuando está activo, lo desactiva para evitar duplicados; también puede desactivarse mediante una integración. Los ajustes de invitaciones y premios de Akibara no se ejecutan mientras este motor esté inactivo.</p></div>';
		}
		echo '<p><strong>' . esc_html( number_format_i18n( $data['pending'] ) ) . ' pendientes de revisión</strong> · ' . esc_html( number_format_i18n( $data['approved'] ) ) . ' publicadas. Se cuentan reseñas de productos, sin las respuestas del hilo.</p>';
		echo '<p class="akb-review-links"><a class="button button-primary" href="' . esc_url( $reviews . '&comment_status=moderated' ) . '">Revisar pendientes</a> <a class="button" href="' . esc_url( $reviews ) . '">Todas las valoraciones</a>';
		if ( $engine ) {
			echo ' <a class="button" href="' . esc_url( admin_url( 'edit-comments.php?page=akb-rewards-new-review' ) ) . '">Transcribir una reseña recibida</a>';
		}
		echo '</p><div class="akb-review-steps"><div><h3>1. Cuándo se invita</h3>';
		echo '<p><strong>' . esc_html( $invites ? 'Invitaciones automáticas activas.' : 'Invitaciones automáticas inactivas.' ) . '</strong> Despacho: ' . esc_html( (string) Invitations::delay_days() ) . ' días corridos desde «' . esc_html( $status ) . '». Entrega directa o retiro: ' . esc_html( (string) Invitations::pickup_delay_days() ) . ' días corridos desde «' . esc_html( $pickup ) . '». Una sola invitación por pedido; pasar por ambos estados no reinicia el plazo. El enlace dura ' . esc_html( (string) Invitations::token_days() ) . ' días.</p>';
		echo '<p>Se exige pago confirmado y productos a pedido o preventa listos para entregar. Antes de enviar se comprueba otra vez el pedido y la baja voluntaria del cliente.</p><p>Guardar ajustes conserva las fechas de la cola y no invita retroactivamente a pedidos anteriores. El recordatorio en correos del pedido es un ajuste aparte.</p></div>';
		echo '<div><h3>2. Dónde revisar y aprobar</h3><p><strong>' . esc_html( $engine && $moderation ? 'Moderación obligatoria activa.' : ( $engine ? 'Moderación obligatoria inactiva.' : 'Moderación propia inactiva.' ) ) . '</strong> ';
		echo esc_html( $engine ? 'Las reseñas por invitación siempre quedan pendientes. Este interruptor controla además la moderación de los formularios nativos de las fichas.' : 'Comprueba las reglas del motor de reseñas que esté activo.' );
		echo '</p><p>Abre Productos → Valoraciones, revisa el texto, la puntuación y la foto, y pulsa Aprobar. Una reseña pendiente aún no aparece en la tienda ni cobra puntos.</p><p>La transcripción es para una opinión que el cliente ya compartió y autorizó publicar: queda identificada como publicada por la tienda y no genera premios.</p></div>';
		echo '<div><h3>3. Cuándo se acreditan</h3>';
		if ( $lane ) {
			$every = Options::review_every_n();
			$max   = Options::review_max_per_user();
			echo '<p><strong>' . esc_html( number_format_i18n( Options::review_points() ) ) . ' puntos ' . esc_html( 1 === $every ? 'por cada reseña que cumple los requisitos.' : 'cada ' . $every . ' reseñas que cumplen los requisitos.' ) . '</strong> ' . esc_html( $max > 0 ? 'Hasta ' . $max . ' premios vigentes por cliente.' : 'Sin tope de premios por cliente.' ) . '</p>';
			echo '<p>La invitación por correo es para solicitar puntos y exige una foto real de la compra. Para acreditar el premio se evalúan al aprobar: foto real, cuenta del cliente' . ( 'yes' === (string) get_option( 'akb_rewards_review_require_verified', 'yes' ) ? ', compra verificada' : '' ) . ' y producto publicado. Las nuevas reseñas voluntarias de la ficha admiten foto opcional y no generan puntos, aunque lleven foto. Las reseñas anteriores conservan su política vigente. Las estrellas no deciden el premio. Sin cuenta, el premio solicitado espera a que la cree y verifique su correo.</p><p>Retirar la aprobación revierte los puntos acreditados. El historial se consulta en Clientes → Gestionar puntos, y el cliente lo ve en Mi cuenta → Puntos Akibara.</p>';
		} else {
			echo '<p><strong>Los premios por reseña están inactivos.</strong> Revisa el interruptor, el monto del premio y el estado del motor antes de ofrecer puntos a un cliente.</p>';
		}
		echo '</div></div>';

		echo '<h3>Cola de invitaciones</h3><p><a class="button" href="' . esc_url( InvitationReschedule::url() ) . '">Reprogramar pendientes</a> Revisa las nuevas fechas antes de aplicar cambios.</p><p>';
		if ( null === $data['queue'] ) {
			echo 'No se pudo consultar Action Scheduler.';
		} else {
			echo esc_html( $data['queue'] > self::SAMPLE_LIMIT ? 'Más de ' . self::SAMPLE_LIMIT : (string) $data['queue'] ) . ' acciones pendientes.';
			if ( null !== $data['next'] ) {
				echo ' Primera fecha programada: <strong>' . esc_html( $this->format_timestamp( $data['next'] ) ) . '</strong>.';
				if ( $data['next'] < time() ) {
					echo ' La fecha ya pasó: revisa la cola y su ejecución.';
				}
			}
		}
		echo ' <a href="' . esc_url( $queue_url ) . '">Ver acciones y errores de invitaciones</a>.</p>';
		if ( ! $invites && ! empty( $data['queue'] ) ) {
			echo '<p><strong>Hay acciones pendientes, pero las invitaciones están inactivas: estar en cola no garantiza que se envíen.</strong></p>';
		}
		echo '<details><summary><strong>Invitaciones en los últimos 10 pedidos</strong></summary><p>Es una muestra de pedidos recientes, no el historial completo. «Alta en cola» registra cuándo se agendó; la fecha de ejecución se lee de la cola actual. Las fechas usan la zona horaria de la tienda.</p>';
		$this->render_orders( $data['orders'], $labels );
		echo '<p class="description">«Enviada al transporte» significa que el correo fue aceptado por el sistema de envío; la recepción se confirma en la bandeja. «Fallos de envío» cuenta intentos fallidos, no todos los intentos. Para revisar un caso, abre el pedido y sus notas.</p></details>';
		echo '<p class="akb-review-links"><a href="#akb-rewards-section-reviews">Ajustar reseñas e invitaciones</a> · <a href="#akb-rewards-section-carousel">Ajustar carrusel</a> · <a href="#akb-rewards-section-points">Ajustar puntos por compra</a></p></section>';
	}

	/** Render order evidence without exposing recipient data or invitation tokens. */
	private function render_orders( array $orders, array $labels ): void {
		if ( array() === $orders ) {
			echo '<p>No hay pedidos en esta muestra.</p>';
			return;
		}
		echo '<div class="akb-review-table" tabindex="0" role="region" aria-label="Invitaciones por pedido"><table class="widefat striped"><thead><tr><th scope="col">Pedido</th><th scope="col">Estado actual</th><th scope="col">Invitación</th><th scope="col">Fechas</th><th scope="col">Fallos de envío</th></tr></thead><tbody>';
		foreach ( $orders as $order ) {
			$state = $order['reason'];
			if ( '' !== $order['sent'] ) {
				$state = 'Enviada al transporte';
			} elseif ( $order['running'] ) {
				$state = 'En ejecución';
			} elseif ( null !== $order['next'] ) {
				$state = $order['failures'] > 0 ? 'Reintento programado' : 'Programada';
			} elseif ( $order['failures'] > 0 ) {
				$state = 'Con fallos, sin próximo envío';
			} elseif ( '' !== $order['scheduled'] ) {
				$state = $order['reason'];
			}
			echo '<tr><th scope="row"><a href="' . esc_url( $order['url'] ) . '">#' . esc_html( (string) $order['id'] ) . '</a></th><td>' . esc_html( $labels[ $order['status'] ] ?? $order['status'] ) . '</td><td>' . esc_html( $state ) . '</td><td>';
			$dates = array();
			if ( '' !== $order['scheduled'] ) {
				$dates[] = 'Alta en cola: ' . $order['scheduled'];
			}
			if ( null !== $order['next'] ) {
				$dates[] = 'Próximo envío: ' . $this->format_timestamp( $order['next'] );
			}
			if ( '' !== $order['sent'] ) {
				$dates[] = 'Enviada: ' . $order['sent'];
			}
			echo implode( '<br>', array_map( 'esc_html', $dates ) ) . '</td><td>' . esc_html( (string) $order['failures'] ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/** Format scheduler timestamps in the store timezone. */
	private function format_timestamp( int $timestamp ): string {
		return wp_date( 'd-m-Y H:i', $timestamp, wp_timezone() );
	}

	/** Motivo actual, leído sin alterar el pedido ni su cola. */
	public static function order_reason( \WC_Order $order ): string {
		if ( ! Invitations::enabled() ) {
			return 'Invitaciones automáticas apagadas.';
		}
		$reason = Invitations::waiting_reason( $order );
		if ( '' !== $reason ) {
			return $reason;
		}
		$recorded = (string) $order->get_meta( Invitations::META_REASON );
		return '' !== $recorded ? $recorded : 'Esperando despacho al transportista o entrega directa.';
	}

	/** Evidencia de invitación dentro del pedido oficial (HPOS o clásico). */
	public function render_order( $order ): void {
		if ( ! current_user_can( SettingsPage::CAPABILITY ) || ! $order instanceof \WC_Order ) {
			return;
		}
		$sent    = (string) $order->get_meta( Invitations::META_SENT );
		$next    = as_next_scheduled_action( Invitations::HOOK, array( (int) $order->get_id() ) );
		$labels  = SettingsPage::order_status_choices();
		$trigger = (string) $order->get_meta( Invitations::META_TRIGGER );
		$message = self::order_reason( $order );
		if ( '' !== $sent ) {
			$message = 'Invitación enviada al sistema de correo: ' . $sent . '.';
		} elseif ( is_int( $next ) ) {
			$message = 'Invitación prevista para ' . $this->format_timestamp( $next ) . ', desde «' . ( $labels[ $trigger ] ?? $trigger ) . '».';
		} elseif ( true === $next ) {
			$message = 'La invitación se está procesando.';
		} elseif ( (int) $order->get_meta( Invitations::META_ATTEMPTS ) >= 3 ) {
			$message = 'No enviada: tres fallos. Revisa la conexión de correo y las notas del pedido.';
		}
		echo '<div class="order_data_column" style="clear:both;width:100%"><h3>Invitación a reseñar</h3><p>' . esc_html( $message ) . '</p><p><a href="' . esc_url( admin_url( 'admin.php?page=akb-rewards-settings#akb-rewards-section-reviews' ) ) . '">Ajustar plazos y estados</a></p></div>';
	}
}
