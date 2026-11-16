

================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/src/Reviews/AccountReviewLink.php
================================================================

<?php
/** Recupera la invitación ya enviada desde los pedidos de su titular. */

declare( strict_types = 1 );

namespace Akibara\Rewards\Reviews;

use Akibara\Rewards\Support\Options;
use Akibara\Rewards\Support\Schema;

defined( 'ABSPATH' ) || exit;

final class AccountReviewLink {

	/** Registra los accesos en la lista y el detalle de pedidos. */
	public function register(): void {
		add_filter( 'woocommerce_my_account_my_orders_actions', array( $this, 'order_actions' ), 20, 2 );
		add_action( 'woocommerce_view_order', array( $this, 'view_order' ), 20 );
	}

	/** Añade el acceso únicamente si el cliente puede recuperar su invitación. */
	public function order_actions( array $actions, $order ): array {
		$url = $this->url_for_order( $order );
		if ( '' !== $url ) {
			$actions['akb_review_invitation'] = array( 'url' => $url, 'name' => $this->label() );
		}
		return $actions;
	}

	/** Muestra el mismo acceso en el detalle del pedido propio. */
	public function view_order( $order_id ): void {
		$url = $this->url_for_order( wc_get_order( (int) $order_id ) );
		if ( '' !== $url ) {
			printf( '<p><a class="button" href="%s">%s</a></p>', esc_url( $url ), esc_html( $this->label() ) );
		}
	}

	/** Lee un token vigente sin emitir enlaces ni modificar el pedido. */
	public function url_for_order( $order ): string {
		$user_id = (int) get_current_user_id();
		if ( $user_id <= 0 || ! $order instanceof \WC_Order
			|| $user_id !== (int) $order->get_customer_id()
			|| '' === (string) $order->get_meta( Invitations::META_SENT ) ) {
			return '';
		}

		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT order_id, token, expires_at, used_at FROM ' . Schema::review_forms_table()
				. ' WHERE order_id = %d AND used_at IS NULL AND expires_at >= %s ORDER BY id DESC LIMIT 1',
				(int) $order->get_id(),
				gmdate( 'Y-m-d H:i:s' )
			),
			ARRAY_A
		);
		if ( ! is_array( $row ) || (int) ( $row['order_id'] ?? 0 ) !== (int) $order->get_id()
			|| null !== ( $row['used_at'] ?? null )
			|| (string) ( $row['expires_at'] ?? '' ) < gmdate( 'Y-m-d H:i:s' )
			|| ! preg_match( '/^[A-Za-z0-9]{20,64}$/', (string) ( $row['token'] ?? '' ) ) ) {
			return '';
		}

		return ReviewForm::url_for( (string) $row['token'] );
	}

	/** Texto coherente con el carril vigente. */
	private function label(): string {
		return Options::reviews_lane_on() ? 'Reseñar y obtener puntos' : 'Escribir mi reseña';
	}
}


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/src/Reviews/AdminOverview.php
================================================================

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
		echo '<p>Se exige pago confirmado. Antes de enviar se comprueba otra vez el pedido y la baja voluntaria del cliente.</p><p>Guardar ajustes conserva las fechas de la cola y no invita retroactivamente a pedidos anteriores. El recordatorio en correos del pedido es un ajuste aparte.</p></div>';
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


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/src/Reviews/AdminReviews.php
================================================================

<?php
/**
 * AdminReviews — Creación y transcripción de reseñas con foto desde el panel de administración.
 *
 * El motor (`create_review`) clona el commentdata de la ruta de reseñas
 * (ingeniería inversa) para que TODAS las superficies existentes — schema,
 * carrusel, contadores de WooCommerce, mu-plugin del home — la vean como una
 * reseña más:
 *   - comment_type 'review', meta rating INT vía commentdata, verified 0/1.
 *   - `do_action('comment_post')` a mano tras wp_insert_comment (así lo hace
 *     CusRev; sin eso WooCommerce no escribe su meta 'verified').
 *   - `wp_update_comment_count()` explícito: es el ÚNICO disparador del
 *     recálculo de _wc_average_rating/_wc_review_count (ingeniería inversa
 *     de WC_Comments) y wp_insert_comment no lo llama por sí solo.
 *   - Marca interna `_akb_rewards_admin_created`: estas reseñas JAMÁS pagan
 *     puntos (las escribe la tienda) y el badge de autoría queda auditable.
 *
 * A diferencia del tercero, acá el rating SÍ se valida 1..5 (CusRev acepta
 * cualquier entero — trampa documentada).
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Reviews;

use Akibara\Rewards\Points\Ledger;
use Akibara\Rewards\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.2.0
 */
final class AdminReviews {

	public const META_ADMIN_CREATED = '_akb_rewards_admin_created';

	/** La divulgación de que la tienda escribió la reseña a nombre del cliente. */
	public const TEXTO_TRANSCRITA = 'Reseña compartida con la tienda y publicada por Akibara a nombre del cliente.';
	public const CAPABILITY         = 'manage_woocommerce';
	public const ACTION             = 'akb_rewards_create_review';
	public const ACTION_RECHECK     = 'akb_rewards_recheck_review_points';
	private const NOTICE_KEY        = 'akb_rw_review_recheck_';

	/**
	 * Cuelga la pantalla y el receptor del formulario.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_form' ) );
		add_action( 'admin_post_' . self::ACTION_RECHECK, array( $this, 'handle_recheck' ) );
		add_action( 'admin_notices', array( $this, 'recheck_notice' ) );
		add_filter( 'comment_row_actions', array( $this, 'points_row_action' ), 20, 2 );

		// Divulgación pública obligatoria de transcripción para cumplimiento normativo
		// sobre transparencia comercial y procedencia de reseñas.
		add_filter( 'comment_text', array( $this, 'transcription_badge' ), 20, 2 );
		add_filter( 'comment_text', array( $this, 'cadence_badge' ), 22, 2 );
		add_filter( 'comment_text', array( $this, 'points_badge' ), 23, 2 );
	}

	/**
	 * Acción disponible en la tabla nativa de reseñas de WooCommerce.
	 *
	 * @param array<string,string> $actions Acciones existentes.
	 * @param \WP_Comment         $comment Reseña de la fila.
	 * @return array<string,string>
	 */
	public function points_row_action( array $actions, $comment ): array {
		if ( ! current_user_can( self::CAPABILITY ) || ! self::can_recheck( $comment ) ) {
			return $actions;
		}
		$id = (int) $comment->comment_ID;
		$url = wp_nonce_url(
			add_query_arg( array( 'action' => self::ACTION_RECHECK, 'comment_id' => $id ), admin_url( 'admin-post.php' ) ),
			self::ACTION_RECHECK . '_' . $id
		);
		$actions['akb_review_points'] = '<a href="' . esc_url( $url ) . '">Revisar puntos</a>';
		return $actions;
	}

	/** @param mixed $comment Comentario a comprobar. @return bool */
	private static function can_recheck( $comment ): bool {
		return $comment instanceof \WP_Comment
			&& 'review' === (string) $comment->comment_type
			&& 0 === (int) $comment->comment_parent
			&& '1' === (string) $comment->comment_approved
			&& 'product' === get_post_type( (int) $comment->comment_post_ID )
			&& Options::reviews_lane_on();
	}

	/**
	 * Reevalúa con el motor normal, sin cambiar publicación ni saltar reglas.
	 *
	 * @param int $comment_id Reseña elegida por el administrador.
	 * @return array{type:string,message:string} Resultado verificable.
	 */
	public function recheck_points( int $comment_id ): array {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return array( 'type' => 'error', 'message' => 'No tienes permisos para revisar puntos.' );
		}
		$comment = get_comment( $comment_id );
		if ( ! self::can_recheck( $comment ) ) {
			return array( 'type' => 'error', 'message' => 'Sólo se revisan reseñas de producto aprobadas con el programa de reseñas activo.' );
		}
		$user_id = (int) $comment->user_id;
		if ( $user_id <= 0 ) {
			$user = get_user_by( 'email', (string) $comment->comment_author_email );
			$user_id = $user instanceof \WP_User ? (int) $user->ID : 0;
		}
		$product_id = (int) $comment->comment_post_ID;
		$before = $user_id > 0 ? Ledger::count_movements_for_user( $user_id, 'review', $product_id, 'review' ) : 0;
		$public_before = self::public_reward_state( $comment_id );
		( new ReviewPoints() )->maybe_pay( $comment );
		if ( $public_before !== self::public_reward_state( $comment_id ) ) {
			// La reseña sigue aprobada: no hay transición que retire del caché
			// su antigua insignia de compra o el criterio de reseña incentivada.
			delete_transient( Carousel::TRANSIENT );
			do_action( 'litespeed_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- mismo hook de la acreditación tardía por cuenta verificada.
		}
		$after = $user_id > 0 ? Ledger::count_movements_for_user( $user_id, 'review', $product_id, 'review' ) : 0;
		$paid = (int) get_comment_meta( $comment_id, ReviewPoints::META_POINTS_PAID, true );
		if ( $after > $before && $paid > 0 ) {
			return array( 'type' => 'success', 'message' => sprintf( 'Reseña #%d: se acreditaron %s Puntos Akibara. La reseña sigue publicada.', $comment_id, number_format_i18n( $paid ) ) );
		}
		return array( 'type' => 'info', 'message' => sprintf( 'Reseña #%d: no se acreditaron puntos nuevos. %s', $comment_id, self::points_status( $comment ) ) );
	}

	/** @param int $comment_id Reseña. @return array<int,string> Metas que cambian la presentación pública. */
	private static function public_reward_state( int $comment_id ): array {
		return array(
			(string) get_comment_meta( $comment_id, 'verified', true ),
			(string) get_comment_meta( $comment_id, ReviewPoints::META_POINTS_PAID, true ),
			(string) get_comment_meta( $comment_id, ReviewPoints::META_COUNTED, true ),
		);
	}

	/** Procesa la acción autenticada y vuelve siempre a la pantalla oficial. */
	public function handle_recheck(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( 'Sin permisos.' );
		}
		$comment_id = isset( $_GET['comment_id'] ) ? absint( $_GET['comment_id'] ) : 0;
		check_admin_referer( self::ACTION_RECHECK . '_' . $comment_id );
		$result = $this->recheck_points( $comment_id );
		set_transient( self::NOTICE_KEY . get_current_user_id(), $result, MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'edit.php?post_type=product&page=product-reviews' ) );
		exit;
	}

	/** Muestra una vez el resultado real, guardado sólo para este administrador. */
	public function recheck_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- sólo elige dónde pintar un aviso guardado en servidor.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( ! current_user_can( self::CAPABILITY ) || 'product-reviews' !== $page ) {
			return;
		}
		$key = self::NOTICE_KEY . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) || ! isset( $notice['type'], $notice['message'] ) ) {
			return;
		}
		delete_transient( $key );
		$type = in_array( $notice['type'], array( 'success', 'info', 'error' ), true ) ? $notice['type'] : 'info';
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( $notice['message'] ) );
	}

	/**
	 * Estado del premio en la lista de moderación, sin recalcular ni acreditar.
	 *
	 * @param string           $text Texto de la reseña.
	 * @param \WP_Comment|null $comment Reseña.
	 * @return string
	 */
	public function points_badge( $text, $comment = null ): string {
		if ( ! is_admin() || ! current_user_can( self::CAPABILITY ) || ! $comment instanceof \WP_Comment
			|| 'review' !== (string) $comment->comment_type || (int) $comment->comment_parent > 0
			|| 'product' !== get_post_type( (int) $comment->comment_post_ID ) ) {
			return (string) $text;
		}
		// El avance ya lo muestra cadence_badge en esta misma superficie.
		if ( '' !== (string) get_comment_meta( (int) $comment->comment_ID, ReviewPoints::META_PROGRESS, true ) ) {
			return (string) $text;
		}
		return (string) $text . '<p class="akb-rw-puntos-estado" style="margin:8px 0 0;font-size:12px">' . esc_html( self::points_status( $comment ) ) . '</p>';
	}

	/** @param \WP_Comment $comment Reseña. @return string Explicación del estado guardado. */
	private static function points_status( \WP_Comment $comment ): string {
		$id = (int) $comment->comment_ID;
		$seal = (string) get_comment_meta( $id, ReviewPoints::META_POINTS_PAID, true );
		if ( (int) $seal > 0 ) {
			return sprintf( '%s Puntos Akibara ya acreditados.', number_format_i18n( (int) $seal ) );
		}
		if ( '0' === (string) get_comment_meta( $id, ReviewPoints::META_POINTS_REQUESTED, true ) ) {
			return 'El cliente no solicitó puntos por esta reseña.';
		}
		if ( ReviewPoints::SEAL_PENDING_USER === $seal ) {
			return 'Premio pendiente de crear y verificar la cuenta del cliente.';
		}
		if ( '' !== (string) get_comment_meta( $id, self::META_ADMIN_CREATED, true ) ) {
			return 'La reseña transcrita por la tienda no recibe puntos.';
		}
		if ( '1' !== (string) $comment->comment_approved ) {
			return 'Los puntos se revisan cuando apruebes la reseña.';
		}
		$progress = (string) get_comment_meta( $id, ReviewPoints::META_PROGRESS, true );
		if ( 1 === preg_match( '/^(\d+)\/(\d+)$/', $progress, $parts ) ) {
			return sprintf( 'Avance: %d de %d reseñas para el próximo premio.', (int) $parts[1], (int) $parts[2] );
		}
		if ( ! Options::reviews_lane_on() ) {
			return 'El programa de puntos por reseñas está apagado.';
		}
		if ( '0' === $seal ) {
			return 'Evaluada sin premio adicional: revisa el límite por cliente, el producto ya premiado o un incentivo previo.';
		}
		return 'Sin premio acreditado: revisa foto, compra verificada, cuenta y requisitos del programa.';
	}

	/**
	 * Submenú bajo Comentarios.
	 *
	 * Ojo: desde WooCommerce 8 las reseñas de producto YA NO se listan en
	 * Comentarios (`ReviewsCommentsOverrides` las excluye); se moderan en
	 * Productos › Reseñas. Este submenú sólo aloja el formulario de
	 * transcripción; los enlaces «ver la reseña» apuntan a la pantalla de
	 * WooCommerce, no a Comentarios.
	 *
	 * @return void
	 */
	public function add_menu(): void {
		add_comments_page(
			'Crear reseña (Akibara)',
			'Crear reseña',
			self::CAPABILITY,
			'akb-rewards-new-review',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Insignia visible en la reseña transcrita: el lector sabe que la
	 * publicó la tienda a nombre del cliente.
	 *
	 * @param string           $text    Texto del comentario.
	 * @param \WP_Comment|null $comment Comentario.
	 * @return string
	 */
	/**
	 * Indicador de avance de cadencia visible exclusivamente en la moderación del panel de administración.
	 *
	 * Cuando opera la política de puntos por cadencia de reseñas, visualiza el progreso
	 * hacia el siguiente umbral de recompensa.
	 *
	 * @internal Callback de `comment_text`.
	 *
	 * @param mixed            $text    Texto ya filtrado.
	 * @param \WP_Comment|null $comment Comentario, cuando el llamador lo pasa.
	 * @return string
	 */
	public function cadence_badge( $text, $comment = null ): string {
		$text = (string) $text;

		if ( ! is_admin() || ! $comment instanceof \WP_Comment ) {
			return $text;
		}

		$avance = (string) get_comment_meta( (int) $comment->comment_ID, ReviewPoints::META_PROGRESS, true );
		if ( '' === $avance || 1 !== preg_match( '/^(\d+)\/(\d+)$/', $avance, $partes ) ) {
			return $text;
		}

		return $text . '<p class="akb-rw-cadencia" style="margin:8px 0 0;font-size:12px;opacity:.75">'
			. esc_html( sprintf( 'Va %d de %d para el próximo premio de Puntos Akibara.', (int) $partes[1], (int) $partes[2] ) )
			. '</p>';
	}

	public function transcription_badge( $text, $comment = null ): string {
		if ( ! $comment instanceof \WP_Comment ) {
			return (string) $text;
		}

		$aviso = self::transcription_notice( (int) $comment->comment_ID );
		if ( '' === $aviso ) {
			return (string) $text;
		}

		return (string) $text
			. '<p class="akb-rw-transcrita" style="margin:8px 0 0;font-size:12px;opacity:.75">📩 ' . esc_html( $aviso ) . '</p>';
	}

	/**
	 * El aviso de transcripción de una reseña; '' si la escribió su autor.
	 *
	 * Fuente común de texto sin HTML para la ficha, el carrusel y el JSON-LD.
	 * Las dos últimas superficies no pasan por el filtro `comment_text`.
	 *
	 * @since 0.4.9
	 *
	 * @param int $comment_id Reseña.
	 * @return string
	 */
	public static function transcription_notice( int $comment_id ): string {
		return 1 === (int) get_comment_meta( $comment_id, self::META_ADMIN_CREATED, true )
			? self::TEXTO_TRANSCRITA
			: '';
	}

	/**
	 * Crea una reseña con la forma de CusRev. Motor puro de la pantalla y de
	 * cualquier import futuro.
	 *
	 * @param array{product_id:int, author:string, rating:int, content:string, attachment_id:int, title?:string, email?:string, source?:string, approved?:bool} $args Datos.
	 * @return int|\WP_Error ID del comentario creado.
	 */
	public static function create_review( array $args ) {
		$product_id = (int) ( $args['product_id'] ?? 0 );
		if ( 'product' !== get_post_type( $product_id ) ) {
			return new \WP_Error( 'akb_rw_bad_product', 'El producto no existe.' );
		}

		$rating = (int) ( $args['rating'] ?? 0 );
		if ( $rating < 1 || $rating > 5 ) {
			return new \WP_Error( 'akb_rw_bad_rating', 'La valoración debe estar entre 1 y 5.' );
		}

		$author = trim( (string) ( $args['author'] ?? '' ) );
		if ( '' === $author ) {
			return new \WP_Error( 'akb_rw_bad_author', 'La reseña necesita un nombre.' );
		}

		$content = trim( (string) ( $args['content'] ?? '' ) );
		if ( '' === $content ) {
			return new \WP_Error( 'akb_rw_bad_content', 'La reseña necesita un texto.' );
		}

		// FOTO OBLIGATORIA: sin imagen real no hay reseña de admin.
		$attachment_id = (int) ( $args['attachment_id'] ?? 0 );
		if ( $attachment_id <= 0 || ! wp_attachment_is( 'image', $attachment_id ) ) {
			return new \WP_Error( 'akb_rw_photo_required', 'La reseña del admin exige una foto (imagen real).' );
		}

		// Email opcional del cliente: enlaza a una cuenta existente si coincide,
		// o registra como reseña directa de tienda si se omite.
		$email   = sanitize_email( (string) ( $args['email'] ?? '' ) );
		$user    = '' !== $email ? get_user_by( 'email', $email ) : false;
		$user_id = $user instanceof \WP_User ? (int) $user->ID : 0;

		$commentdata = array(
			'comment_author'       => $author,
			'comment_author_email' => $email,
			'comment_author_url'   => '',
			'user_id'              => $user_id,
			'comment_content'      => $content,
			'comment_post_ID'      => $product_id,
			'comment_type'         => 'review',
			'comment_approved'     => ! empty( $args['approved'] ?? true ) ? 1 : 0,
			'comment_meta'         => array(
				'rating'                  => $rating,
				// La marca debe existir antes de que wp_insert_comment dispare ReviewPoints.
				self::META_ADMIN_CREATED  => 1,
			),
		);

		$comment_id = wp_insert_comment( wp_slash( $commentdata ) );
		if ( ! $comment_id ) {
			return new \WP_Error( 'akb_rw_insert_failed', 'WordPress rechazó la reseña.' );
		}

		// Completa los metadatos y la foto antes de notificar a comment_post.
		$title = trim( (string) ( $args['title'] ?? '' ) );
		if ( '' !== $title ) {
			update_comment_meta( $comment_id, 'cr_rev_title', $title );
		}
		Media::attach_photo( $comment_id, $attachment_id, $product_id );

		// comment_post a mano, clonado de CusRev — pero SIN los correos de
		// core («nuevo comentario» al dueño por su propia reseña): esos dos
		// oyentes se retiran solo para esta request de admin.
		remove_action( 'comment_post', 'wp_new_comment_notify_moderator' );
		remove_action( 'comment_post', 'wp_new_comment_notify_postauthor' );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- no es un hook nuestro: es el de WP core, y lo disparamos a mano porque insertamos el comentario sin pasar por wp_new_comment(). Con otro nombre, Woo no estamparía «compra verificada» y nuestro motor no vería la reseña.
		do_action( 'comment_post', $comment_id, $commentdata['comment_approved'], $commentdata );
		// Restablece las notificaciones para los demás comentarios de la petición.
		add_action( 'comment_post', 'wp_new_comment_notify_moderator' );
		add_action( 'comment_post', 'wp_new_comment_notify_postauthor' );

		// La insignia de compra verificada se calcula programáticamente contra el
		// historial de compras del cliente y producto en WooCommerce para asegurar veracidad.
		$verified = ( '' !== $email || $user_id > 0 ) && function_exists( 'wc_customer_bought_product' )
			? (bool) wc_customer_bought_product( $email, $user_id, $product_id )
			: false;
		update_comment_meta( $comment_id, 'verified', (int) $verified );

		// Registro de procedencia y trazabilidad de consentimiento.
		$source = trim( (string) ( $args['source'] ?? '' ) );
		if ( '' !== $source ) {
			update_comment_meta( $comment_id, '_akb_rewards_source_note', $source );
		}

		// El único disparador del recálculo de ratings de WooCommerce.
		wp_update_comment_count( $product_id );

		return (int) $comment_id;
	}

	/**
	 * Receptor del formulario (admin-post, nonce + capability + subida).
	 *
	 * @return void
	 */
	public function handle_form(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( self::ACTION );

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachment_id = 0;
		if ( ! empty( $_FILES['akb_rw_photo']['name'] ) ) {
			$uploaded = media_handle_upload( 'akb_rw_photo', 0 );
			if ( is_wp_error( $uploaded ) ) {
				// Conserva el motivo específico del rechazo de la foto.
				wp_safe_redirect(
					add_query_arg(
						array( 'akb_rw_error' => rawurlencode( 'Foto rechazada: ' . $uploaded->get_error_message() ) ),
						admin_url( 'edit-comments.php?page=akb-rewards-new-review' )
					)
				);
				exit;
			}
			$attachment_id = (int) $uploaded;
		}

		$result = self::create_review(
			array(
				'product_id'    => isset( $_POST['akb_rw_product'] ) ? (int) $_POST['akb_rw_product'] : 0,
				'author'        => isset( $_POST['akb_rw_author'] ) ? sanitize_text_field( wp_unslash( $_POST['akb_rw_author'] ) ) : '',
				'rating'        => isset( $_POST['akb_rw_rating'] ) ? (int) $_POST['akb_rw_rating'] : 0,
				'title'         => isset( $_POST['akb_rw_title'] ) ? sanitize_text_field( wp_unslash( $_POST['akb_rw_title'] ) ) : '',
				'content'       => isset( $_POST['akb_rw_content'] ) ? sanitize_textarea_field( wp_unslash( $_POST['akb_rw_content'] ) ) : '',
				'attachment_id' => $attachment_id,
				'email'         => isset( $_POST['akb_rw_email'] ) ? sanitize_email( wp_unslash( $_POST['akb_rw_email'] ) ) : '',
				'source'        => isset( $_POST['akb_rw_source'] ) ? sanitize_text_field( wp_unslash( $_POST['akb_rw_source'] ) ) : '',
			)
		);

		if ( is_wp_error( $result ) && $attachment_id > 0 ) {
			// Sin reseña no queda adjunto huérfano en la Media Library (el
			// residuo sin GC que le reprochamos a CusRev).
			wp_delete_attachment( $attachment_id, true );
		}

		$arg = is_wp_error( $result )
			? array( 'akb_rw_error' => rawurlencode( $result->get_error_message() ) )
			: array( 'akb_rw_created' => (int) $result );

		wp_safe_redirect( add_query_arg( $arg, admin_url( 'edit-comments.php?page=akb-rewards-new-review' ) ) );
		exit;
	}

	/**
	 * Pantalla mínima y liviana (HTML plano del admin, sin JS propio).
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		echo '<div class="wrap"><h1>Crear reseña</h1>';

		if ( isset( $_GET['akb_rw_created'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- solo pinta un aviso.
			printf(
				'<div class="notice notice-success"><p>Reseña #%d creada. <a href="%s">Ver en Productos › Reseñas →</a></p></div>',
				(int) $_GET['akb_rw_created'], // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				// Enlace directo a la vista de reseñas de productos de WooCommerce.
				esc_url( admin_url( 'edit.php?post_type=product&page=product-reviews' ) )
			);
		}
		if ( isset( $_GET['akb_rw_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- aviso de interfaz, sin efecto.
				esc_html( rawurldecode( sanitize_text_field( wp_unslash( $_GET['akb_rw_error'] ) ) ) )
			);
		}

		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th scope="row"><label for="akb_rw_product">ID del producto</label></th><td><input name="akb_rw_product" id="akb_rw_product" type="number" required class="regular-text"><p class="description">El ID de la ficha (visible en Productos → pasar el mouse).</p></td></tr>';
		echo '<tr><th scope="row"><label for="akb_rw_author">Nombre a mostrar</label></th><td><input name="akb_rw_author" id="akb_rw_author" type="text" required class="regular-text"></td></tr>';
		echo '<tr><th scope="row"><label for="akb_rw_rating">Valoración</label></th><td><select name="akb_rw_rating" id="akb_rw_rating">';
		for ( $i = 5; $i >= 1; $i-- ) {
			printf( '<option value="%1$d">%1$d ★</option>', (int) $i );
		}
		echo '</select></td></tr>';
		echo '<tr><th scope="row"><label for="akb_rw_title">Título (opcional)</label></th><td><input name="akb_rw_title" id="akb_rw_title" type="text" class="regular-text"></td></tr>';
		echo '<tr><th scope="row"><label for="akb_rw_content">Reseña</label></th><td><textarea name="akb_rw_content" id="akb_rw_content" rows="5" required class="large-text"></textarea></td></tr>';
		echo '<tr><th scope="row"><label for="akb_rw_photo">Foto (obligatoria)</label></th><td><input name="akb_rw_photo" id="akb_rw_photo" type="file" accept="image/jpeg,image/png,image/webp" required></td></tr>';
		echo '<tr><th scope="row"><label for="akb_rw_email">Email del cliente (opcional)</label></th><td><input name="akb_rw_email" id="akb_rw_email" type="email" class="regular-text"><p class="description">Si el cliente existe y compró el producto, la insignia «compra verificada» se calcula sola contra sus pedidos reales. Nunca se marca a mano.</p></td></tr>';
		echo '<tr><th scope="row"><label for="akb_rw_source">¿Dónde te la compartió?</label></th><td><input name="akb_rw_source" id="akb_rw_source" type="text" class="regular-text" placeholder="Instagram DM 30-08, WhatsApp…"><p class="description">Procedencia y consentimiento del cliente (queda en la reseña como registro interno).</p></td></tr>';
		echo '</table>';
		submit_button( 'Crear reseña' );
		echo '</form></div>';
	}
}


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/src/Reviews/Carousel.php
================================================================

<?php
/**
 * Carousel — «Voces del Distrito» en la portada, first-party y LIVIANO.
 *
 * Reemplaza al mu-plugin akibara-home-reviews (que envolvía el slider Slick
 * de CusRev: ~188 KB de CSS+JS muertos en la home). Este carrusel es CSS
 * scroll-snap PURO: cero JavaScript, imágenes lazy, y cada perilla en una
 * option (regla del dueño: UI configurable y liviana).
 *
 * Compuertas de calidad:
 *   - Solo aparece cuando las reseñas elegibles sobrepasan el mínimo configurado
 *     (option `akb_rewards_carousel_min`, por defecto 8).
 *   - Elegible = aprobada, de producto publicado, ≥ 40 caracteres, y no
 *     incentivada (meta cr_coupon_code NOT EXISTS, la clave heredada).
 *
 * El shortcode se llama IGUAL que el del mu-plugin a propósito: los
 * mu-plugins cargan antes y este add_shortcode lo pisa — el post 210 de la
 * portada no se toca en el cutover.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Reviews;

use Akibara\Rewards\Support\Assets;
use Akibara\Rewards\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.2.0
 */
final class Carousel {

	public const SHORTCODE = 'akibara_home_reviews';
	public const TRANSIENT = 'akb_rewards_carousel_v7';

	/** El <style> sale una vez por petición (una instancia registra el shortcode). */
	private bool $style_printed = false;

	/**
	 * Cuelga shortcode y purgas.
	 *
	 * @return void
	 */
	public function register(): void {
		add_shortcode( self::SHORTCODE, array( $this, 'render' ) );
		HomeCache::register();

		// Invalida el transient y el HTML de portada al cambiar las reseñas.
		add_action( 'transition_comment_status', array( $this, 'flush_on_transition' ), 10, 3 );
		add_action( 'edit_comment', array( $this, 'flush_on_comment' ), 10, 1 );
		add_action( 'wp_insert_comment', array( $this, 'flush_on_insert' ), 10, 2 );
		add_action( 'deleted_comment', array( $this, 'flush_on_insert' ), 10, 2 );
		add_action( 'transition_post_status', array( $this, 'flush_for_product' ), 10, 3 );
		// Borrar EN FIRME un producto publicado no pasa por
		// transition_post_status: sin esto la portada servía hasta una hora
		// una tarjeta que enlazaba a la nada.
		add_action( 'before_delete_post', array( $this, 'flush_on_delete' ), 10, 2 );
		foreach ( array( 'added_comment_meta', 'updated_comment_meta', 'deleted_comment_meta' ) as $hook ) {
			add_action( $hook, array( $this, 'flush_on_media' ), 10, 3 );
		}
	}

	/**
	 * Cambiar una foto también puede cambiar la elegibilidad del carrusel.
	 *
	 * @param int|int[] $meta_id    Meta modificada.
	 * @param int       $comment_id Reseña.
	 * @param string    $meta_key   Clave modificada.
	 * @return void
	 */
	public function flush_on_media( $meta_id, $comment_id, $meta_key ): void {
		unset( $meta_id );
		if ( in_array( (string) $meta_key, array( Media::META_LOCAL_IMAGE, Media::META_REMOTE_IMAGE ), true ) ) {
			$this->flush_if_review( null, (int) $comment_id );
		}
	}

	/**
	 * Tira el caché de tarjetas y purga la portada servida.
	 *
	 * @return void
	 */
	public function flush(): void {
		delete_transient( self::TRANSIENT );

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- no es un hook nuestro: es el de LiteSpeed, y el nombre exacto es la única forma de que su plugin escuche y purgue la portada. Prefijarlo lo dejaría sin oyente.
		do_action( 'litespeed_purge_url', home_url( '/' ) );
		HomeCache::queue_purge();
	}

	/**
	 * ¿Este comentario toca al carrusel? Solo las RESEÑAS de PRODUCTO: un
	 * comentario del blog o un spam de Akismet no invalidan la portada.
	 *
	 * @param \WP_Comment|null $comment Comentario (o null para resolver por id).
	 * @param int              $id      ID de respaldo.
	 * @return void
	 */
	private function flush_if_review( $comment, int $id = 0 ): void {
		if ( ! $comment instanceof \WP_Comment && $id > 0 ) {
			$comment = get_comment( $id );
		}
		if ( ! $comment instanceof \WP_Comment ) {
			return;
		}
		if ( 'review' !== (string) $comment->comment_type ) {
			return;
		}
		if ( 'product' !== get_post_type( (int) $comment->comment_post_ID ) ) {
			return;
		}

		$this->flush();
	}

	/**
	 * Transición de estado de comentario.
	 *
	 * @param string           $new_status Estado nuevo.
	 * @param string           $old_status Estado anterior.
	 * @param \WP_Comment      $comment    Comentario.
	 * @return void
	 */
	public function flush_on_transition( $new_status, $old_status, $comment ): void {
		unset( $new_status, $old_status );
		$this->flush_if_review( $comment instanceof \WP_Comment ? $comment : null );
	}

	/**
	 * Edición de comentario (llega solo el id).
	 *
	 * @param int $comment_id ID.
	 * @return void
	 */
	public function flush_on_comment( $comment_id ): void {
		$this->flush_if_review( null, (int) $comment_id );
	}

	/**
	 * Inserción o borrado (id + objeto).
	 *
	 * @param int              $comment_id ID.
	 * @param \WP_Comment|null $comment    Comentario.
	 * @return void
	 */
	public function flush_on_insert( $comment_id, $comment = null ): void {
		$this->flush_if_review( $comment instanceof \WP_Comment ? $comment : null, (int) $comment_id );
	}

	/**
	 * Invalida ante un cambio efectivo de estado de producto o adjunto.
	 * transition_post_status también se dispara al guardar sin cambiar estado.
	 *
	 * @param string   $new_status Estado nuevo.
	 * @param string   $old_status Estado anterior.
	 * @param \WP_Post $post       Post.
	 * @return void
	 */
	public function flush_for_product( $new_status, $old_status, $post ): void {
		if ( (string) $new_status === (string) $old_status ) {
			return;
		}

		if ( is_object( $post ) && in_array( (string) ( $post->post_type ?? '' ), array( 'product', 'attachment' ), true ) ) {
			$this->flush();
		}
	}

	/**
	 * Borrado en firme: si el producto estaba PUBLICADO, sus tarjetas quedan
	 * enlazando a la nada.
	 *
	 * @param int           $post_id ID.
	 * @param \WP_Post|null $post    Post que se va (WP 6.6+ lo pasa).
	 * @return void
	 */
	public function flush_on_delete( $post_id, $post = null ): void {
		if ( ! is_object( $post ) ) {
			$post = get_post( (int) $post_id );
		}

		if ( is_object( $post ) && in_array( (string) ( $post->post_type ?? '' ), array( 'product', 'attachment' ), true ) ) {
			$this->flush();
		}
	}

	/**
	 * Render del shortcode. Devuelve '' bajo el umbral: la portada no muestra
	 * una sección a medio llenar.
	 *
	 * @return string
	 */
	public function render(): string {
		if ( ! Options::carousel_on() ) {
			return '';
		}

		$data = $this->data();

		if ( (int) $data['eligible'] < Options::carousel_min() ) {
			return '';
		}

		$this->enqueue_animation();

		return $this->markup( $data['cards'] );
	}

	/**
	 * Reseñas elegibles que ve HOY el carrusel, del MISMO caché con que pinta
	 * la portada. Topadas en el umbral (ver build_data): responde «¿llega al
	 * mínimo?» y, bajo el mínimo, es el número exacto. Lo lee el panel para
	 * decir si la portada muestra el título que el dueño está editando.
	 *
	 * @return int
	 */
	public function eligible_seen(): int {
		return (int) $this->data()['eligible'];
	}

	/**
	 * Tarjetas y umbral: del transient, o armados y guardados una hora.
	 *
	 * @return array{eligible:int, cards:array<int,array<string,mixed>>}
	 */
	private function data(): array {
		$data = get_transient( self::TRANSIENT );
		if ( ! is_array( $data ) ) {
			$data = $this->build_data();
			set_transient( self::TRANSIENT, $data, HOUR_IN_SECONDS );
		}

		return $data;
	}

	/**
	 * Reveal GSAP alineado con el sistema de animaciones del tema.
	 * Solo si el tema ya registró gsap/gsap-scrolltrigger en esta página: el
	 * carrusel jamás carga la librería por su cuenta, y sin ella las tarjetas
	 * se presentan con CSS nativo.
	 *
	 * @return void
	 */
	private function enqueue_animation(): void {
		if ( ! function_exists( 'wp_script_is' ) || ! wp_script_is( 'gsap', 'registered' ) || ! wp_script_is( 'gsap-scrolltrigger', 'registered' ) ) {
			return;
		}

		$rel = 'assets/js/carousel.js';

		wp_enqueue_script(
			'akb-rewards-carousel',
			Assets::url( $rel ),
			array( 'gsap', 'gsap-scrolltrigger' ),
			Assets::version( $rel ),
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}

	/**
	 * Arma las tarjetas y resuelve el umbral con UNA sola consulta.
	 *
	 * Antes eran dos con el mismo WHERE de 12 líneas copiado, una de ellas
	 * sólo para contar. El umbral no necesita un total exacto —es un «¿llega
	 * a N?»—, así que se piden tantas filas como la mayor de las dos perillas
	 * y se cuenta sobre el resultado: si vuelven menos que el mínimo, la
	 * portada no pinta la sección.
	 *
	 * @return array{eligible:int, cards:array<int,array<string,mixed>>}
	 *         `eligible` son las filas elegibles VISTAS, topadas en ese
	 *         límite: sirve para el umbral, no como total del catálogo.
	 */
	private function build_data(): array {
		global $wpdb;

		// El dueño puede excluir reseñas que cobraron puntos o contaron para
		// un premio. Disclosure resuelve el aviso incluido en cada tarjeta.
		$excluir_premiadas = '';
		if ( 'yes' === (string) get_option( 'akb_rewards_carousel_exclude_rewarded', 'no' ) ) {
			$excluir_premiadas = "AND NOT EXISTS (
				SELECT 1 FROM {$wpdb->commentmeta} pr
				WHERE pr.comment_id = c.comment_ID AND pr.meta_key IN ('_akb_rewards_review_points', '_akb_rewards_review_counted')
					AND CAST(pr.meta_value AS UNSIGNED) > 0
			)";
		}

		// El filtro de foto comprueba el adjunto igual que los premios: un adjunto
		// borrado, un video o una portada de producto no son una foto de reseña.
		// Las fotos remotas heredadas sólo aceptan URLs HTTP(S) seguras.
		// Se filtra ANTES del mínimo/límite; una página sin fotos no puede
		// ocultar fotos válidas que existan más atrás.
		$mode   = Options::carousel_media();
		$needed = max( Options::carousel_cards(), Options::carousel_min() );
		$batch  = 'mixed' === $mode ? $needed : max( 50, $needed );
		$offset = 0;
		$rows   = array();
		do {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fragmento fijo, sin entrada de usuario.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$page = (array) $wpdb->get_results(
				$wpdb->prepare(
					"SELECT c.comment_ID, c.comment_post_ID, c.comment_author, c.comment_content, c.comment_date
					FROM {$wpdb->comments} c
					INNER JOIN {$wpdb->posts} p ON p.ID = c.comment_post_ID AND p.post_type = 'product' AND p.post_status = 'publish'
					WHERE c.comment_approved = '1' AND c.comment_type = 'review' AND c.comment_parent = 0
						AND CHAR_LENGTH(c.comment_content) >= %d
						AND EXISTS (
							SELECT 1 FROM {$wpdb->commentmeta} r
							WHERE r.comment_id = c.comment_ID AND r.meta_key = 'rating'
								AND CAST(r.meta_value AS UNSIGNED) BETWEEN 1 AND 5
						)
						AND NOT EXISTS (
							SELECT 1 FROM {$wpdb->commentmeta} m
							WHERE m.comment_id = c.comment_ID AND m.meta_key = 'cr_coupon_code'
						)
						{$excluir_premiadas}
					ORDER BY c.comment_date_gmt DESC, c.comment_ID DESC
					LIMIT %d OFFSET %d",
					Options::carousel_min_chars(),
					$batch,
					$offset
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( array() !== $page && function_exists( 'update_meta_cache' ) ) {
				update_meta_cache( 'comment', array_map( static fn( $row ): int => (int) $row->comment_ID, $page ) );
			}
			foreach ( $page as $row ) {
				$image = self::review_photo( (int) $row->comment_ID );
				$has_photo = $image['id'] > 0 || '' !== $image['url'];
				if ( 'mixed' !== $mode && ( 'photos' === $mode ) !== $has_photo ) {
					continue;
				}
				$rows[] = $row;
				if ( count( $rows ) >= $needed ) {
					break;
				}
			}
			$offset += $batch;
		} while ( 'mixed' !== $mode && count( $page ) === $batch && count( $rows ) < $needed );

		$eligible = count( $rows );
		$cards    = array();

		if ( $eligible > 0 ) {
			// Del lote pedido para el umbral sólo se pintan las tarjetas
			// configuradas.
			$rows = array_slice( $rows, 0, Options::carousel_cards() );


			foreach ( $rows as $row ) {
				$comment_id = (int) $row->comment_ID;
				$rating     = (int) get_comment_meta( $comment_id, 'rating', true );
				$title      = (string) get_comment_meta( $comment_id, 'cr_rev_title', true );

				$image = self::review_photo( $comment_id );

				$cards[] = array(
					'author'    => (string) $row->comment_author,
					'text'      => (string) $row->comment_content,
					'rating'    => $rating,
					'title'     => $title,
					'photo_id'  => $image['id'],
					'photo_url' => $image['url'],
					'comment'   => $comment_id,
					'cover_id'  => (int) get_post_thumbnail_id( (int) $row->comment_post_ID ),
					'product'   => (int) $row->comment_post_ID,
					'aviso'     => Disclosure::visible_notice_for( $comment_id ),
					// El aviso de transcripción es independiente del de incentivo.
					'origen'    => AdminReviews::transcription_notice( $comment_id ),
				);
			}
		}

		return array(
			'eligible' => $eligible,
			'cards'    => $cards,
		);
	}

	/**
	 * Foto que se puede mostrar; nunca confunde una portada con foto del cliente.
	 *
	 * @param int $comment_id Reseña.
	 * @return array{id:int,url:string}
	 */
	private static function review_photo( int $comment_id ): array {
		foreach ( (array) get_comment_meta( $comment_id, Media::META_LOCAL_IMAGE ) as $attachment_id ) {
			if ( (int) $attachment_id > 0 && wp_attachment_is( 'image', (int) $attachment_id ) ) {
				return array( 'id' => (int) $attachment_id, 'url' => '' );
			}
		}
		foreach ( (array) get_comment_meta( $comment_id, Media::META_REMOTE_IMAGE ) as $remote ) {
			if ( is_array( $remote ) && ! empty( $remote['url'] ) ) {
				$url = esc_url( (string) $remote['url'], array( 'https', 'http' ) );
				if ( '' !== $url ) {
					return array( 'id' => 0, 'url' => $url );
				}
			}
		}
		return array( 'id' => 0, 'url' => '' );
	}

	/**
	 * HTML del carrusel: scroll-snap puro, sin JS, imágenes lazy, tokens de
	 * marca con fallback.
	 *
	 * @param array<int,array<string,mixed>> $cards Tarjetas.
	 * @return string
	 */
	private function markup( array $cards ): string {
		if ( array() === $cards ) {
			return '';
		}

		// Precalentamiento de caché para los productos referenciados por las tarjetas,
		// evitando consultas SQL N+1 en get_permalink() y get_the_title().
		$product_ids = array_values( array_unique( array_map( static fn( array $c ): int => (int) $c['product'], $cards ) ) );
		if ( function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( $product_ids, false, false );
		}

		// Tokens del tema; fotos y portadas tienen tratamientos distintos.
		// Las tarjetas se estiran a la misma altura y el pie permanece alineado.
		$css = '.akb-rw-carousel{--akb-rw-bg:var(--aki-surface-2,#1c1c1c);--akb-rw-fg:var(--aki-text,#f5f5f5);--akb-rw-accent:var(--aki-red-readable,#FF4756);box-sizing:border-box;width:100%;max-width:1280px;margin:clamp(32px,6vw,72px) auto;padding:0 16px}'
			. '.akb-rw-carousel .akb-rw-header{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;margin-bottom:8px}'
			. '.akb-rw-carousel h2{margin:0 0 4px;width:fit-content;font-family:var(--font-heading,inherit);font-size:clamp(26px,3.5vw,40px);line-height:1.15;letter-spacing:.04em;text-transform:uppercase;color:var(--aki-white,#fff);overflow-wrap:anywhere}'
			. '.akb-rw-carousel .akb-rw-nav{display:flex;align-items:center;gap:8px}'
			. '.akb-rw-carousel .akb-rw-nav[hidden],.akb-rw-carousel .akb-rw-nav[data-hidden="true"]{display:none!important}'
			. '.akb-rw-carousel .akb-rw-nav-btn{display:inline-flex;align-items:center;justify-content:center;width:44px;height:44px;background:var(--akb-rw-bg);color:var(--aki-white,#fff);border:1px solid var(--aki-border,rgba(255,255,255,.18));cursor:pointer;transition:background-color 150ms ease,border-color 150ms ease,transform 150ms ease,opacity 150ms ease;padding:0}'
			. '.akb-rw-carousel .akb-rw-nav-btn:hover:not(:disabled){background:var(--akb-rw-accent);border-color:var(--aki-white,#fff);transform:translateY(-2px)}'
			. '.akb-rw-carousel .akb-rw-nav-btn:active:not(:disabled){transform:translateY(0)}'
			. '.akb-rw-carousel .akb-rw-nav-btn:focus-visible{outline:2px solid var(--akb-rw-accent);outline-offset:2px}'
			. '.akb-rw-carousel .akb-rw-nav-btn:disabled,.akb-rw-carousel .akb-rw-nav-btn[aria-disabled="true"]{opacity:.3!important;cursor:not-allowed!important;pointer-events:none!important;transform:none!important;border-color:var(--aki-border,rgba(255,255,255,.12))!important;background:var(--akb-rw-bg)!important}'
			. '.akb-rw-carousel .akb-rw-track{display:flex;align-items:stretch;gap:20px;overflow-x:auto;scroll-snap-type:x mandatory;scroll-behavior:smooth;padding:8px 4px 20px;-webkit-overflow-scrolling:touch;list-style:none;margin:0}'
			. '.akb-rw-carousel .akb-rw-track:focus-visible{outline:2px solid var(--akb-rw-accent);outline-offset:-2px}'
			. '.akb-rw-carousel .akb-rw-track::-webkit-scrollbar{height:4px}'
			. '.akb-rw-carousel .akb-rw-track::-webkit-scrollbar-track{background:rgba(255,255,255,.06)}'
			. '.akb-rw-carousel .akb-rw-track::-webkit-scrollbar-thumb{background:var(--akb-rw-accent)}'
			. '.akb-rw-carousel .akb-rw-card{box-sizing:border-box;position:relative;display:flex;flex-direction:column;scroll-snap-align:start;flex:0 0 min(360px,86vw);max-width:min(360px,86vw);min-height:410px;background:var(--akb-rw-bg);color:var(--akb-rw-fg);border:1px solid var(--aki-border,rgba(255,255,255,.16));border-top:3px solid var(--akb-rw-accent);border-radius:0;padding:22px;margin:0;overflow:hidden;transition:transform .22s cubic-bezier(.16,1,.3,1),box-shadow .22s ease,border-color .22s ease}'
			. '.akb-rw-carousel .akb-rw-card:hover{transform:translateY(-4px);box-shadow:0 14px 34px rgba(0,0,0,.75),4px 4px 0 var(--akb-rw-accent);border-color:rgba(255,255,255,.28)}'
			. '.akb-rw-carousel .akb-rw-stars{color:var(--akb-rw-fg);letter-spacing:3px;font-size:18px;line-height:1.5}'
			. '.akb-rw-carousel .akb-rw-title{display:block;margin:0 0 6px}'
			. '.akb-rw-carousel .akb-rw-text{margin:10px 0 14px;font-size:15.5px;line-height:1.6;overflow-wrap:anywhere;display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:5;overflow:hidden}'
			. '.akb-rw-carousel .akb-rw-card:not(:has(.akb-rw-media)) .akb-rw-text{font-size:16px;line-height:1.65;-webkit-line-clamp:7}'
			. '.akb-rw-carousel .akb-rw-meta{font-size:14px;line-height:1.5;color:var(--akb-rw-fg);margin:0 0 12px}'
			. '.akb-rw-carousel .akb-rw-author{font-weight:600;margin:0 0 10px;overflow-wrap:anywhere}'
			. '.akb-rw-carousel .akb-rw-verified-pill{display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:var(--aki-green-bright,#86efac);background:rgba(20,83,45,.35);border:1px solid rgba(134,239,172,.3);padding:3px 8px;margin-bottom:12px;width:fit-content}'
			. '.akb-rw-carousel .akb-rw-media{position:relative;display:block;width:100%;height:180px;margin:6px 0 14px;background:#0d0d10;border:1px solid var(--aki-border,rgba(255,255,255,.14));box-shadow:2px 2px 0 #000;overflow:hidden;text-decoration:none}'
			. '.akb-rw-carousel .akb-rw-photo{display:block;width:100%!important;height:100%!important;object-fit:cover!important;object-position:center;margin:0;transition:transform .35s cubic-bezier(.16,1,.3,1)}'
			. '.akb-rw-carousel .akb-rw-card:hover .akb-rw-photo{transform:scale(1.04)}'
			. '.akb-rw-carousel .akb-rw-photo-tag{position:absolute;top:8px;left:8px;z-index:2;display:inline-flex;align-items:center;gap:5px;padding:3px 8px;background:rgba(10,10,12,.92);border:1px solid rgba(255,255,255,.2);color:var(--aki-white,#fff);font-size:10px;font-weight:700;letter-spacing:.05em;text-transform:uppercase}'
			. '.akb-rw-carousel .akb-rw-disclosure{margin:0 0 12px;padding:8px 10px;border-left:2px solid var(--akb-rw-accent);background:var(--aki-surface,rgba(255,255,255,.06));font-size:12px;line-height:1.5}'
			. '.akb-rw-carousel .akb-rw-product{display:flex;align-items:center;gap:14px;margin-top:auto;padding-top:16px;min-height:64px;border-top:1px solid var(--aki-border,rgba(255,255,255,.16));font-size:14px;line-height:1.45;color:var(--akb-rw-fg);text-decoration:none;overflow-wrap:anywhere}'
			. '.akb-rw-carousel .akb-rw-product:hover .akb-rw-product-name{text-decoration:underline}'
			. '.akb-rw-carousel .akb-rw-product:focus-visible{outline:2px solid var(--akb-rw-accent);outline-offset:3px;border-radius:4px}'
			. '.akb-rw-carousel .akb-rw-cover{width:44px!important;height:66px!important;object-fit:contain;flex:none;margin:0}'
			. '.akb-rw-carousel .akb-rw-product-label{display:block;font-size:12px;font-weight:400;margin-bottom:3px}'
			. '.akb-rw-carousel .akb-rw-product-name{display:block;font-weight:600}'
			. '.akb-rw-carousel .akb-rw-read{display:block;margin-top:5px;font-size:13px;text-decoration:underline;text-underline-offset:3px}'
			. '@media(min-width:768px){.akb-rw-carousel{padding-inline:24px}.akb-rw-carousel .akb-rw-track[data-count="1"],.akb-rw-carousel .akb-rw-track[data-count="2"],.akb-rw-carousel .akb-rw-track[data-count="3"]{display:grid;grid-template-columns:repeat(var(--akb-rw-count),minmax(0,1fr))}.akb-rw-carousel:has(.akb-rw-track[data-count="1"]) .akb-rw-nav,.akb-rw-carousel:has(.akb-rw-track[data-count="2"]) .akb-rw-nav,.akb-rw-carousel:has(.akb-rw-track[data-count="3"]) .akb-rw-nav{display:none!important}.akb-rw-carousel .akb-rw-track[data-count="1"]{max-width:640px}.akb-rw-carousel .akb-rw-track[data-count="1"] .akb-rw-card,.akb-rw-carousel .akb-rw-track[data-count="2"] .akb-rw-card,.akb-rw-carousel .akb-rw-track[data-count="3"] .akb-rw-card{max-width:none}}'
			. '@media(min-width:1024px){.akb-rw-carousel .akb-rw-track[data-count="4"]{display:grid;grid-template-columns:repeat(4,minmax(0,1fr))}.akb-rw-carousel:has(.akb-rw-track[data-count="4"]) .akb-rw-nav{display:none!important}.akb-rw-carousel .akb-rw-track[data-count="4"] .akb-rw-card{max-width:none}}'
			. '@media(prefers-reduced-motion:reduce){.akb-rw-carousel .akb-rw-card,.akb-rw-carousel .akb-rw-photo,.akb-rw-carousel .akb-rw-nav-btn{transition:none!important;transform:none!important}}';

		$items = '';
		foreach ( $cards as $card ) {
			$stars = str_repeat( '★', max( 0, min( 5, (int) $card['rating'] ) ) ) . str_repeat( '☆', 5 - max( 0, min( 5, (int) $card['rating'] ) ) );

			$photo = '';
			$photo_alt = sprintf( 'Foto de la reseña de %s', (string) $card['author'] );
			if ( (int) $card['photo_id'] > 0 ) {
				$photo = wp_get_attachment_image( (int) $card['photo_id'], 'medium', false, array( 'class' => 'akb-rw-photo', 'loading' => 'lazy', 'alt' => $photo_alt ) );
			} elseif ( '' !== (string) ( $card['photo_url'] ?? '' ) ) {
				$photo = '<img class="akb-rw-photo" src="' . esc_url( (string) $card['photo_url'] ) . '" alt="' . esc_attr( $photo_alt ) . '" loading="lazy" width="80" height="96">';
			}
			$has_photo = '' !== $photo;
			$comment_link = (string) get_permalink( (int) $card['product'] ) . '#comment-' . (int) ( $card['comment'] ?? 0 );
			$photo_html = $has_photo
				? '<figure class="akb-rw-media">'
					. '<a class="akb-rw-photo-link" href="' . esc_url( $comment_link ) . '" title="Ver reseña y foto completa en el tomo">'
					. $photo
					. '<span class="akb-rw-photo-tag" aria-hidden="true">📷 Foto del lector</span>'
					. '</a>'
				  . '</figure>'
				: '';

			$product_name = (string) get_the_title( (int) $card['product'] );
			$cover = (int) ( $card['cover_id'] ?? 0 ) > 0
				? wp_get_attachment_image( (int) $card['cover_id'], 'thumbnail', false, array( 'class' => 'akb-rw-cover', 'loading' => 'lazy', 'alt' => sprintf( 'Portada de %s', $product_name ) ) )
				: '';

			$title_html = '' !== (string) $card['title']
				? '<strong class="akb-rw-title">' . esc_html( (string) $card['title'] ) . '</strong>'
				: '';

			$verified_html = ! $has_photo
				? '<span class="akb-rw-verified-pill" aria-hidden="true">✓ Opinión verificada</span>'
				: '';

			$items .= '<li class="akb-rw-card">'
				. '<span class="akb-rw-stars" aria-hidden="true">' . esc_html( $stars ) . '</span>'
				. '<span class="screen-reader-text">' . esc_html( sprintf( '%d de 5 estrellas', (int) $card['rating'] ) ) . '</span>'
				. '<p class="akb-rw-text">' . $title_html . esc_html( self::excerpt( (string) $card['text'] ) ) . '</p>'
				. '<p class="akb-rw-meta akb-rw-author">' . esc_html( (string) $card['author'] ) . '</p>'
				. $verified_html
				. $photo_html
				. ( Options::carousel_disclosure_on() && '' !== (string) ( $card['aviso'] ?? '' ) ? '<p class="akb-rw-disclosure">' . esc_html( (string) $card['aviso'] ) . '</p>' : '' )
				. ( '' !== (string) ( $card['origen'] ?? '' ) ? '<p class="akb-rw-disclosure">' . esc_html( (string) $card['origen'] ) . '</p>' : '' )
				. '<a class="akb-rw-product" href="' . esc_url( $comment_link ) . '">'
				. $cover . '<span><span class="akb-rw-product-label">Sobre este producto</span><span class="akb-rw-product-name">' . esc_html( $product_name ) . '</span><span class="akb-rw-read">Leer la reseña completa →</span></span></a>'
				. '</li>';
		}

		// La pista con overflow es alcanzable por teclado (WCAG 2.1.1) y cuenta
		// con flechas de desplazamiento suave para escritorio.
		return '<section class="akb-rw-carousel" aria-label="Reseñas de la comunidad">'
			. $this->style_once( $css )
			. '<div class="akb-rw-header">'
				. '<div class="akb-rw-header-text">'
					. '<h2>' . esc_html( Options::carousel_title() ) . '</h2>'
					. '<p class="akb-rw-meta">La comunidad Akibara opina</p>'
				. '</div>'
				. '<div class="akb-rw-nav" aria-label="Navegación del carrusel">'
					. '<button type="button" class="akb-rw-nav-btn akb-rw-prev" data-akb-rw-prev aria-label="Ver reseñas anteriores" disabled aria-disabled="true">'
						. '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"></polyline></svg>'
					. '</button>'
					. '<button type="button" class="akb-rw-nav-btn akb-rw-next" data-akb-rw-next aria-label="Ver reseñas siguientes"' . ( count( $cards ) <= 1 ? ' disabled aria-disabled="true"' : '' ) . '>'
						. '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"></polyline></svg>'
					. '</button>'
				. '</div>'
			. '</div>'
			. '<ul class="akb-rw-track" data-count="' . count( $cards ) . '" style="--akb-rw-count:' . count( $cards ) . '" tabindex="0" aria-label="Reseñas, lista desplazable">' . $items . '</ul>'
			. '</section>';
	}

	/**
	 * Recorta a ~220 caracteres respetando límites de palabras con fallback seguro
	 * si mbstring no estuviese disponible.
	 *
	 * @param string $text Texto completo de la reseña.
	 * @return string
	 */
	public static function excerpt( string $text ): string {
		$limit = 220;
		$mb    = function_exists( 'mb_substr' ) && function_exists( 'mb_strlen' );

		$len = $mb ? mb_strlen( $text, 'UTF-8' ) : strlen( $text );
		if ( $len <= $limit ) {
			return $text;
		}

		$cut   = $mb ? mb_substr( $text, 0, $limit, 'UTF-8' ) : substr( $text, 0, $limit );
		$space = $mb && function_exists( 'mb_strrpos' ) ? mb_strrpos( $cut, ' ', 0, 'UTF-8' ) : strrpos( $cut, ' ' );

		// Cortar en el último espacio solo si no sacrifica media card: una
		// cadena sin espacios se recorta dura (mejor que desbordar).
		if ( false !== $space && $space > 120 ) {
			$cut = $mb ? mb_substr( $cut, 0, (int) $space, 'UTF-8' ) : substr( $cut, 0, (int) $space );
		}

		return rtrim( $cut, " \t\n\r.,;:" ) . '…';
	}

	/**
	 * El bloque <style> sale UNA vez por petición aunque el shortcode se
	 * pegue dos veces en la misma página.
	 *
	 * @param string $css CSS del carrusel.
	 * @return string
	 */
	private function style_once( string $css ): string {
		if ( $this->style_printed ) {
			return '';
		}
		$this->style_printed = true;

		return '<style>' . $css . '</style>';
	}
}


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/src/Reviews/Disclosure.php
================================================================

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


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/src/Reviews/HomeCache.php
================================================================

<?php
/** Invalida exclusivamente las copias de la portada en Cloudflare. @package Akibara\Rewards */
declare( strict_types = 1 );

namespace Akibara\Rewards\Reviews;

use Akibara\Rewards\Support\Log;

defined( 'ABSPATH' ) || exit;

final class HomeCache {

	public const TAG  = 'akb_reviews_home_v1';
	public const HOOK = 'akb_rewards_home_cache_retry';
	private static bool $queued = false;

	public static function register(): void {
		add_filter( 'wp_headers', array( self::class, 'tag_headers' ) );
		add_action( self::HOOK, array( self::class, 'purge' ), 10, 1 );
	}

	/** @param array<string,string> $headers Cabeceras de WordPress. @return array<string,string> */
	public static function tag_headers( array $headers ): array {
		if ( is_front_page() ) {
			$headers['Cache-Tag'] = isset( $headers['Cache-Tag'] ) ? $headers['Cache-Tag'] . ',' . self::TAG : self::TAG;
		}
		return $headers;
	}

	/** Una sola purga por petición, después de invalidar el origen. */
	public static function queue_purge(): void {
		if ( self::$queued || null === self::credentials() ) {
			return;
		}
		self::$queued = true;
		add_action( 'shutdown', array( self::class, 'purge' ), 30 );
	}

	/** @return array{zone:string,key:string,email:string}|null */
	private static function credentials(): ?array {
		// El espejo puede contener opciones copiadas: nunca debe llamar al borde real.
		if ( 'https://akibara.cl' !== rtrim( (string) get_option( 'home', '' ), '/' ) || 'production' !== wp_get_environment_type()
			|| ! get_option( 'litespeed.conf.cdn-cloudflare', false ) ) {
			return null;
		}
		$zone = (string) get_option( 'litespeed.conf.cdn-cloudflare_zone', '' );
		$key  = (string) get_option( 'litespeed.conf.cdn-cloudflare_key', '' );
		if ( 1 !== preg_match( '/^[a-f0-9]{32}$/', $zone ) || '' === $key ) {
			return null;
		}
		return array( 'zone' => $zone, 'key' => $key, 'email' => (string) get_option( 'litespeed.conf.cdn-cloudflare_email', '' ) );
	}

	/** @param int|string $attempt Reintento acotado; sin URL ni credencial en la cola. */
	public static function purge( int|string $attempt = 0 ): void {
		$attempt = (int) $attempt;
		$access = self::credentials();
		if ( null === $access ) {
			return;
		}
		$global = 0 === strpos( $access['key'], 'cfk_' ) || 1 === preg_match( '/^[0-9a-f]{37,45}$/', $access['key'] );
		$headers = array( 'Content-Type' => 'application/json' );
		if ( $global ) {
			$headers['X-Auth-Email'] = $access['email'];
			$headers['X-Auth-Key']   = $access['key'];
		} else {
			$headers['Authorization'] = 'Bearer ' . $access['key'];
		}
		$response = wp_remote_post(
			'https://api.cloudflare.com/client/v4/zones/' . $access['zone'] . '/purge_cache',
			array( 'timeout' => 8, 'headers' => $headers, 'body' => (string) wp_json_encode( array( 'tags' => array( self::TAG ) ) ) )
		);
		$body = is_wp_error( $response ) ? null : json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) && ! empty( $body['success'] ) ) {
			return;
		}
		// No registrar la respuesta externa: puede contener datos de autenticación.
		Log::error( 'No se pudo actualizar la caché pública de reseñas de la portada.' );
		if ( $attempt < 2 && function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + 75, self::HOOK, array( $attempt + 1 ), 'akibara-rewards', true );
		}
	}
}


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/src/Reviews/InvitationReschedule.php
================================================================

<?php
/** Explicit, previewed rescheduling of pending review invitations.
 *
 * @package Akibara\Rewards
 */
declare( strict_types = 1 );

namespace Akibara\Rewards\Reviews;

use Akibara\Rewards\Support\Options;
use Akibara\Rewards\Support\OrderStates;

defined( 'ABSPATH' ) || exit;

/** Keeps settings changes separate from existing queue changes. */
final class InvitationReschedule {
	public const SLUG = 'akb-review-reschedule';
	private const PREVIEW = 'akb_reviews_reschedule_preview';
	private const APPLY = 'akb_reviews_reschedule_apply';
	private const LIMIT = 50;
	private const TRANSIENT = 'akb_review_reschedule_';
	/** @var object|null Scheduler store; injectable for isolated queue tests. */
	private $store;

	/** Accept a scheduler store for tests; production resolves the official store. */
	public function __construct( $store = null ) {
		$this->store = $store;
	}

	/** Register the hidden review detail and explicit POST operations. */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ), 99 );
		add_action( 'admin_post_' . self::PREVIEW, array( $this, 'handle_preview' ) );
		add_action( 'admin_post_' . self::APPLY, array( $this, 'handle_apply' ) );
	}

	/** Register the page; the overview provides its navigation link. */
	public function add_page(): void {
		add_submenu_page( '', 'Reprogramar invitaciones', 'Reprogramar invitaciones', 'manage_woocommerce', self::SLUG, array( $this, 'render' ) );
	}

	/** Return the stable admin URL. */
	public static function url(): string {
		return admin_url( 'admin.php?page=' . self::SLUG );
	}

	/** Store a preview for this administrator without modifying orders or actions. */
	public function handle_preview(): void {
		$this->authorize( self::PREVIEW );
		$offset = isset( $_POST['preview_offset'] ) ? absint( $_POST['preview_offset'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- authorize() verifies the operation nonce above.
		$plan = $this->preview( $offset );
		$plan['token'] = wp_generate_uuid4();
		set_transient( self::TRANSIENT . get_current_user_id(), $plan, 10 * MINUTE_IN_SECONDS );
		wp_safe_redirect( self::url() );
		exit;
	}

	/** Consume exactly the server-side preview that the administrator approved. */
	public function handle_apply(): void {
		$this->authorize( self::APPLY );
		$key   = self::TRANSIENT . get_current_user_id();
		$plan  = get_transient( $key );
		$token = isset( $_POST['preview_token'] ) ? sanitize_text_field( wp_unslash( $_POST['preview_token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- authorize() verifies the operation nonce above.
		if ( ! is_array( $plan ) || '' === $token || ! hash_equals( (string) ( $plan['token'] ?? '' ), $token ) ) {
			wp_die( 'La vista previa venció o ya fue utilizada. Genera otra antes de aplicar.' );
		}
		delete_transient( $key );
		$result = $this->apply( $plan );
		set_transient( $key . '_result', $result, 10 * MINUTE_IN_SECONDS );
		wp_safe_redirect( self::url() );
		exit;
	}

	/** Read at most fifty candidates and freeze both the old and proposed dates. */
	public function preview( int $offset = 0 ): array {
		$plan = array( 'rows' => array(), 'skipped' => 0, 'more' => false, 'offset' => max( 0, $offset ), 'policy' => $this->policy(), 'created' => time() );
		if ( ! current_user_can( 'manage_woocommerce' ) || ! Invitations::enabled() || ! Options::reviews_engine_on() ) {
			return $plan;
		}
		$ids = $this->scheduler()->query_actions( array( 'hook' => Invitations::HOOK, 'status' => 'pending', 'claimed' => false, 'per_page' => self::LIMIT + 1, 'offset' => $plan['offset'], 'orderby' => 'date', 'order' => 'ASC' ) );
		$plan['more'] = count( $ids ) > self::LIMIT;
		foreach ( array_slice( $ids, 0, self::LIMIT ) as $id ) {
			$row = $this->candidate( (int) $id );
			if ( null === $row ) {
				++$plan['skipped'];
				continue;
			}
			$days = $row['trigger'] === Invitations::trigger_status() ? Invitations::delay_days() : Invitations::pickup_delay_days();
			$base = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $row['scheduled'], wp_timezone() );
			if ( false === $base || $base->format( 'Y-m-d H:i:s' ) !== $row['scheduled'] ) {
				++$plan['skipped'];
				continue;
			}
			$row['new_due'] = max( $plan['created'] + 5 * MINUTE_IN_SECONDS, $base->getTimestamp() + $days * DAY_IN_SECONDS );
			if ( $row['new_due'] === $row['old_due'] ) {
				++$plan['skipped'];
				continue;
			}
			$plan['rows'][] = $row;
		}
		return $plan;
	}

	/** Apply frozen dates only after claiming and revalidating the original actions. */
	public function apply( array $plan ): array {
		$result = array( 'changed' => 0, 'skipped' => 0, 'errors' => 0 );
		$rows   = array_slice( (array) ( $plan['rows'] ?? array() ), 0, self::LIMIT );
		if ( ! current_user_can( 'manage_woocommerce' ) || ! Invitations::enabled() || ! Options::reviews_engine_on() || ( $plan['policy'] ?? array() ) !== $this->policy() || (int) ( $plan['created'] ?? 0 ) < time() - 10 * MINUTE_IN_SECONDS ) {
			$result['skipped'] = count( $rows );
			return $result;
		}
		if ( ! $rows ) {
			return $result;
		}
		$groups = array();
		foreach ( $rows as $row ) {
			$groups[ (string) ( $row['group'] ?? '' ) ][] = $row;
		}
		if ( count( $groups ) > 1 ) {
			foreach ( $groups as $group_rows ) {
				$partial = $this->apply( array_merge( $plan, array( 'rows' => $group_rows ) ) );
				foreach ( $result as $key => $value ) {
					$result[ $key ] = $value + $partial[ $key ];
				}
			}
			return $result;
		}
		$store = $this->scheduler();
		$claim = null;
		$approved_ids = implode( ',', array_map( 'intval', array_column( $rows, 'action_id' ) ) );
		$claim_order = static function ( string $sql, $claim_id, array $hooks ) use ( $approved_ids ): string {
			unset( $claim_id );
			return array( Invitations::HOOK ) === $hooks ? 'ORDER BY CASE WHEN action_id IN (' . $approved_ids . ') THEN 0 ELSE 1 END, scheduled_date_gmt ASC, action_id ASC' : $sql;
		};
		$old_filters = array();
		if ( method_exists( $store, 'get_claim_filter' ) ) {
			foreach ( array( 'hooks', 'group', 'exclude-groups' ) as $filter ) {
				$old_filters[ $filter ] = $store->get_claim_filter( $filter );
			}
		}
		try {
			add_filter( 'action_scheduler_claim_actions_order_by', $claim_order, 10, 3 );
			$claim = $store->stake_claim( count( $rows ), new \DateTime( '@' . max( array_column( $rows, 'old_due' ) ) ), array( Invitations::HOOK ), (string) array_key_first( $groups ) );
			$claimed = array_map( 'intval', $claim->get_actions() );
			foreach ( $rows as $row ) {
				$id = (int) $row['action_id'];
				if ( ! in_array( $id, $claimed, true ) || (int) $row['new_due'] <= time() + 30 ) {
					++$result['skipped'];
					continue;
				}
				$current = $this->candidate( $id, (int) $claim->get_id() );
				$expected = $row;
				unset( $expected['new_due'] );
				if ( $current !== $expected ) {
					++$result['skipped'];
					continue;
				}
				$new_id = 0;
				try {
					$action = $store->fetch_action( $id );
					// Create the future replacement first: on write failure the old
					// invitation remains intact. The claimed old job cannot run.
					$new_id = (int) as_schedule_single_action( (int) $row['new_due'], Invitations::HOOK, array( (int) $row['order_id'] ), $action->get_group(), false, $action->get_priority() );
					if ( $new_id <= 0 ) {
						++$result['errors'];
						continue;
					}
					$store->cancel_action( $id );
					if ( 'canceled' !== $store->get_status( $id ) ) {
						throw new \RuntimeException( 'Original action was not canceled.' );
					}
				} catch ( \Throwable $error ) {
					// A cancellation hook can throw after the original was already
					// canceled. Keep its replacement in that case.
					if ( $new_id <= 0 || 'canceled' !== $store->get_status( $id ) ) {
						if ( $new_id > 0 ) {
							$store->cancel_action( $new_id );
						}
						++$result['errors'];
						continue;
					}
				}
				++$result['changed'];
				$order = wc_get_order( (int) $row['order_id'] );
				if ( $order instanceof \WC_Order ) {
					$order->update_meta_data( Invitations::META_DUE, (int) $row['new_due'] );
					$order->save_meta_data();
				}
			}
		} catch ( \Throwable $error ) {
			++$result['errors'];
		} finally {
			remove_filter( 'action_scheduler_claim_actions_order_by', $claim_order, 10 );
			if ( null !== $claim ) {
				$store->release_claim( $claim );
			}
			if ( method_exists( $store, 'set_claim_filter' ) ) {
				foreach ( $old_filters as $filter => $value ) {
					$store->set_claim_filter( $filter, $value );
				}
			}
		}
		return $result;
	}

	/** Render preview controls and exact dates, without performing queue mutations. */
	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$key = self::TRANSIENT . get_current_user_id();
		$plan = get_transient( $key );
		$result = get_transient( $key . '_result' );
		echo '<div class="wrap"><h1>Reprogramar invitaciones pendientes</h1><p><a href="' . esc_url( admin_url( 'admin.php?page=akb-rewards-settings' ) ) . '">← Volver a Puntos y Reseñas</a></p>';
		echo '<p>Aplica los plazos actuales desde la fecha original de despacho o retiro guardada al agendar. Se revisan hasta 50 acciones por tanda. Las enviadas, en ejecución, con fallos de envío o sin un pedido elegible se conservan.</p><p>Si la nueva fecha ya pasó, la vista previa propone cinco minutos desde ahora. Aplicar no envía correos directamente: cambia la fecha para que la cola los procese. Los cambios de ajustes por sí solos no reprograman.</p>';
		if ( is_array( $result ) ) {
			delete_transient( $key . '_result' );
			echo '<div class="notice notice-info inline"><p>Reprogramadas: ' . esc_html( (string) $result['changed'] ) . '. Omitidas por cambios o vencimiento: ' . esc_html( (string) $result['skipped'] ) . '. Errores: ' . esc_html( (string) $result['errors'] ) . '.</p></div>';
		}
		if ( ! Invitations::enabled() || ! Options::reviews_engine_on() ) {
			echo '<p>Activa el motor y las invitaciones antes de preparar una reprogramación.</p></div>';
			return;
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::PREVIEW );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::PREVIEW ) . '"><button class="button">Preparar vista previa</button></form>';
		if ( is_array( $plan ) ) {
			echo '<h2>Vista previa: ' . esc_html( (string) count( $plan['rows'] ) ) . ' invitaciones</h2><p>Sin cambios o no elegibles en esta tanda: ' . esc_html( (string) $plan['skipped'] ) . '. Fechas en la zona horaria de la tienda. La vista previa vence en diez minutos.</p>';
			if ( $plan['more'] ) {
				echo '<p>Hay más acciones pendientes. Puedes revisar otra tanda sin aplicar ésta.</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
				wp_nonce_field( self::PREVIEW );
				echo '<input type="hidden" name="action" value="' . esc_attr( self::PREVIEW ) . '"><input type="hidden" name="preview_offset" value="' . esc_attr( (string) ( $plan['offset'] + self::LIMIT ) ) . '"><button class="button">Ver siguientes 50 pendientes</button></form>';
			}
			if ( $plan['rows'] ) {
				echo '<table class="widefat striped"><thead><tr><th scope="col">Pedido</th><th scope="col">Fecha actual</th><th scope="col">Nueva fecha</th></tr></thead><tbody>';
				foreach ( $plan['rows'] as $row ) {
					echo '<tr><td>#' . esc_html( (string) $row['order_id'] ) . '</td><td>' . esc_html( wp_date( 'd-m-Y H:i', $row['old_due'] ) ) . '</td><td>' . esc_html( wp_date( 'd-m-Y H:i', $row['new_due'] ) ) . '</td></tr>';
				}
				echo '</tbody></table><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
				wp_nonce_field( self::APPLY );
				echo '<input type="hidden" name="action" value="' . esc_attr( self::APPLY ) . '"><input type="hidden" name="preview_token" value="' . esc_attr( $plan['token'] ) . '"><p><button class="button button-primary">Aplicar estas fechas a ' . esc_html( (string) count( $plan['rows'] ) ) . ' invitaciones</button></p></form>';
			}
		}
		echo '</div>';
	}

	/** Validate a specific original action against its live order and scheduling evidence. */
	private function candidate( int $id, int $claim_id = 0 ): ?array {
		$store = $this->scheduler();
		if ( 'pending' !== $store->get_status( $id ) || (int) $store->get_claim_id( $id ) !== $claim_id ) {
			return null;
		}
		$action = $store->fetch_action( $id );
		$args   = $action->get_args();
		if ( Invitations::HOOK !== $action->get_hook() || count( $args ) !== 1 || ! isset( $args[0] ) || $action->get_schedule()->is_recurring() ) {
			return null;
		}
		$order = wc_get_order( (int) $args[0] );
		if ( ! $order instanceof \WC_Order || '' !== (string) $order->get_meta( Invitations::META_SENT ) || (int) $order->get_meta( Invitations::META_ATTEMPTS ) > 0 || '' !== Invitations::waiting_reason( $order ) ) {
			return null;
		}
		$trigger = (string) $order->get_meta( Invitations::META_TRIGGER );
		$status  = (string) $order->get_status();
		if ( ! Invitations::is_trigger( $trigger ) || ( ! OrderStates::is_at_or_after( $status, $trigger ) && $status !== Invitations::pickup_status() ) ) {
			return null;
		}
		$jobs = $store->query_actions( array( 'hook' => Invitations::HOOK, 'args' => array( (int) $order->get_id() ), 'status' => array( 'pending', 'in-progress' ), 'per_page' => 2 ) );
		if ( count( $jobs ) !== 1 || (int) $jobs[0] !== $id ) {
			return null;
		}
		return array( 'action_id' => $id, 'order_id' => (int) $order->get_id(), 'old_due' => $action->get_schedule()->get_date()->getTimestamp(), 'status' => $status, 'trigger' => $trigger, 'scheduled' => (string) $order->get_meta( Invitations::META_SCHEDULED ), 'group' => $action->get_group() );
	}

	/** Resolve the official scheduler storage. */
	private function scheduler() {
		return $this->store ?? \ActionScheduler::store();
	}

	/** Reject previews made against a different set of route settings. */
	private function policy(): array {
		return array( Invitations::trigger_status(), Invitations::delay_days(), Invitations::pickup_status(), Invitations::pickup_delay_days() );
	}

	/** Require both administrator capability and the operation's nonce. */
	private function authorize( string $action ): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( $action );
	}
}


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/src/Reviews/Invitations.php
================================================================

<?php
/**
 * Invitations — invitación a reseñar por correo con enlace de TOKEN (Fase 2b).
 *
 * Diseño sellado (00-DISENO §Flujo de invitación):
 *   - Dos rutas configurables: despacho y entrega directa o retiro.
 *     La primera agenda UN solo job de Action Scheduler por pedido.
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


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/src/Reviews/InviteOptOut.php
================================================================

<?php
/**
 * InviteOptOut — la salida del cliente que no quiere que le pidan reseñas.
 *
 * La preferencia del cliente se aplica a las invitaciones para reseñar. Los
 * avisos transaccionales del pedido y del saldo conservan su propio flujo.
 *
 * Decisiones que explican la forma de este fichero:
 *
 *   - **La baja es por CORREO, no por cuenta.** La mitad de los pedidos son de
 *     invitado; una preferencia guardada en `user_meta` no existiría para
 *     ellos. La lista guarda el SHA-256 del correo normalizado: basta para
 *     reconocer a quien pidió la baja y no deja una lista de direcciones
 *     legible en la tabla de options.
 *   - **El enlace no da de baja por sí solo.** Un GET que cambia estado lo
 *     dispara cualquier prefetch de cliente de correo o antivirus de enlaces:
 *     el enlace abre una página con un botón, y la baja viaja por POST. La
 *     excepción es el «un clic» del RFC 8058, que Gmail manda como POST con
 *     su propio campo y por eso sí se atiende de inmediato.
 *   - **Nunca es un callejón.** La misma página deja volver a activarlas: una
 *     baja por error sin vuelta atrás obliga a escribir, que es justo lo que
 *     este fichero vino a evitar.
 *
 * El enlace lleva el número del pedido y una firma HMAC de ese número con la
 * sal del sitio. La dirección se resuelve desde el pedido al validar, por lo
 * que corregir su correo no invalida un enlace ya enviado. No reutiliza el
 * token del formulario de reseña:
 *
 *   - El token de reseña expira y se purga con el tiempo; la firma HMAC es permanente
 *     y garantiza que la desuscripción funcione indefinidamente.
 *   - Principio de mínimo privilegio: la cabecera `List-Unsubscribe` es leída por
 *     filtros antispam automáticos y no debe otorgar permisos para enviar reseñas ni acreditar puntos.
 *
 * La firma no vive en ninguna tabla (no caduca ni se purga), no abre nada más
 * que esta página y no expone el correo. Deja de valer en dos casos, y en los
 * dos la página responde 404 con el contacto a mano: si el sitio rota sus
 * sales, o si el pedido ya no existe.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Reviews;

use Akibara\Rewards\Support\PrivatePage;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.4.9
 */
final class InviteOptOut {

	public const QUERY_VAR = 'akb_rw_baja';

	/**
	 * Lista de bajas: array<string hash, int timestamp>.
	 */
	private const OPTION = 'akb_rewards_invite_optout';

	/**
	 * Enlace de baja para el pedido que recibe la invitación.
	 *
	 * Query string y no una ruta bonita a propósito: una `rewrite rule` nueva
	 * necesita un flush que un despliegue por ZIP no dispara, y la baja daría
	 * 404 justo en el correo que la ofrece.
	 *
	 * @param \WC_Order $order Pedido invitado.
	 * @return string '' si el pedido no tiene correo.
	 */
	public static function url_for( \WC_Order $order ): string {
		$ref = self::ref_for( (int) $order->get_id(), (string) $order->get_billing_email() );

		// Bajo la URL de Mi cuenta y no en la portada: el borde de la tienda
		// cachea diez minutos cualquier HTML de invitado aunque diga
		// `no-store`, y esa ruta es de las que deja pasar. Ver PrivatePage.
		return '' === $ref ? '' : add_query_arg( self::QUERY_VAR, $ref, PrivatePage::base_url() );
	}

	/**
	 * «<pedido>-<firma>»: lo que viaja en el enlace.
	 *
	 * @param int    $order_id Pedido.
	 * @param string $email    Correo de facturación del pedido.
	 * @return string '' si falta alguno de los dos.
	 */
	private static function ref_for( int $order_id, string $email ): string {
		$email = strtolower( trim( $email ) );
		if ( $order_id <= 0 || '' === $email ) {
			return '';
		}

		// La firma permanece válida al corregir el correo de facturación;
		// la validación obtiene la dirección actual desde el pedido firmado.
		return $order_id . '-' . substr( hash_hmac( 'sha256', 'akb_rw_baja|' . $order_id, wp_salt( 'auth' ) ), 0, 32 );
	}

	/**
	 * ¿Este correo pidió no recibir más invitaciones?
	 *
	 * @param string $email Dirección del pedido.
	 * @return bool
	 */
	public static function opted_out( string $email ): bool {
		$clave = self::clave( $email );

		return '' !== $clave && isset( self::lista()[ $clave ] );
	}

	/**
	 * Cuántos clientes pidieron la baja (para el panel del dueño).
	 *
	 * @return int
	 */
	public static function total(): int {
		return count( self::lista() );
	}

	/**
	 * Cuelga la página pública.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'query_vars', array( $this, 'register_query_vars' ) );
		// Prioridad 1, como las demás bajas de la tienda: antes de que la
		// página de Mi cuenta decida pintar su formulario de acceso.
		add_action( 'template_redirect', array( $this, 'maybe_render' ), 1 );
	}

	/**
	 * @param array<int,string> $vars Query vars públicas.
	 * @return array<int,string>
	 */
	public function register_query_vars( $vars ): array {
		$vars   = is_array( $vars ) ? $vars : array();
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Pinta la página de baja y termina la petición.
	 *
	 * Sólo la cáscara con efectos: el `exit` no deja probar nada, así que la
	 * decisión entera vive en `responder()`.
	 *
	 * @return void
	 */
	public function maybe_render(): void {
		$token = (string) get_query_var( self::QUERY_VAR );
		if ( '' === $token ) {
			return;
		}

		PrivatePage::no_cache( 'Akibara Rewards: preferencias de correo' );

		$metodo = isset( $_SERVER['REQUEST_METHOD'] )
			? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) )
			: 'GET';

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- el token del enlace es el secreto y viaja en el propio POST; ver responder().
		$post = (array) wp_unslash( $_POST );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$respuesta = self::responder( $token, $metodo, $post );

		// Un enlace que no se reconoce NO puede responder 200: el «Cancelar
		// suscripción» de Gmail es un POST sin pantalla, lee cualquier 2xx como
		// «hecho» y se lo dice al cliente — que seguiría recibiendo correos
		// creyendo que ya se dio de baja.
		status_header( $respuesta['status'] );

		echo $respuesta['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML propio, escapado por partes.
		exit;
	}

	/**
	 * La página entera para un token, un método y un POST dados.
	 *
	 * El token ES el secreto del enlace y viaja en el cuerpo del POST; un nonce
	 * de invitado no añadiría nada (depende de una sesión que un comprador sin
	 * cuenta no tiene) y sí rompería la baja de quien abre el correo semanas
	 * después.
	 *
	 * @param string              $token  Token del enlace.
	 * @param string              $metodo Método HTTP.
	 * @param array<string,mixed> $post   Cuerpo del POST.
	 * @return array{status:int,html:string} Código HTTP y página completa.
	 */
	public static function responder( string $token, string $metodo, array $post = array() ): array {
		$titulo = 'Preferencias de correo — Akibara';
		$email  = self::email_of_ref( $token );

		if ( '' === $email ) {
			// Enlace inválido: se dice sin filtrar si el pedido existe.
			return array(
				'status' => 404,
				'html'   => ReviewForm::shell( self::cuerpo_invalido(), $titulo ),
			);
		}

		if ( 'POST' !== strtoupper( $metodo ) ) {
			return array(
				'status' => 200,
				'html'   => ReviewForm::shell( self::cuerpo_confirmar( $token, self::opted_out( $email ) ), $titulo ),
			);
		}

		$accion = isset( $post['akb_rw_pref'] ) ? sanitize_key( (string) $post['akb_rw_pref'] ) : '';

		// «Un clic» del RFC 8058: Gmail y Outlook mandan este POST sin abrir la
		// página, y el correo lo anuncia en su cabecera List-Unsubscribe-Post.
		if ( isset( $post['List-Unsubscribe'] ) ) {
			$accion = 'baja';
		}

		if ( 'alta' === $accion ) {
			self::alta( $email );
			return array(
				'status' => 200,
				'html'   => ReviewForm::shell( self::cuerpo_hecho( $token, false ), $titulo ),
			);
		}

		self::baja( $email );

		return array(
			'status' => 200,
			'html'   => ReviewForm::shell( self::cuerpo_hecho( $token, true ), $titulo ),
		);
	}

	/**
	 * Correo del pedido al que pertenece un enlace, si la firma es la suya.
	 *
	 * @param string $ref «<pedido>-<firma>» del enlace.
	 * @return string '' si el enlace no es válido o el pedido ya no está.
	 */
	private static function email_of_ref( string $ref ): string {
		if ( 1 !== preg_match( '/^(\d{1,12})-([a-f0-9]{32})$/', $ref, $m ) || ! function_exists( 'wc_get_order' ) ) {
			return '';
		}

		$order = wc_get_order( (int) $m[1] );
		if ( ! $order instanceof \WC_Order ) {
			return '';
		}

		$email = (string) $order->get_billing_email();

		// Comparación en tiempo constante contra la firma recalculada: quien
		// conoce un número de pedido no puede dar de baja a su dueño.
		return hash_equals( self::ref_for( (int) $m[1], $email ), $ref ) ? $email : '';
	}

	/**
	 * Huella estable de una dirección.
	 *
	 * @param string $email Dirección.
	 * @return string '' si no hay dirección.
	 */
	private static function clave( string $email ): string {
		$email = strtolower( trim( $email ) );

		return '' === $email ? '' : hash( 'sha256', $email );
	}

	/**
	 * @return array<string,int>
	 */
	private static function lista(): array {
		$lista = get_option( self::OPTION, array() );

		return is_array( $lista ) ? $lista : array();
	}

	/**
	 * @param string $email Dirección que pide la baja.
	 * @return void
	 */
	private static function baja( string $email ): void {
		$clave = self::clave( $email );
		if ( '' === $clave ) {
			return;
		}

		$lista           = self::lista();
		$lista[ $clave ] = time();

		update_option( self::OPTION, $lista, false );
	}

	/**
	 * @param string $email Dirección que vuelve a aceptarlas.
	 * @return void
	 */
	private static function alta( string $email ): void {
		$clave = self::clave( $email );
		$lista = self::lista();

		if ( '' === $clave || ! isset( $lista[ $clave ] ) ) {
			return;
		}

		unset( $lista[ $clave ] );

		update_option( self::OPTION, $lista, false );
	}

	/**
	 * @param string $token Token del enlace.
	 * @param bool   $baja  Si el botón da de baja (o vuelve a activar).
	 * @return string
	 */
	private static function formulario( string $token, bool $baja ): string {
		return sprintf(
			'<form method="post" style="margin:16px 0 0">
				<input type="hidden" name="akb_rw_pref" value="%s">
				<input type="hidden" name="%s" value="%s">
				<button type="submit">%s</button>
			</form>',
			$baja ? 'baja' : 'alta',
			esc_attr( self::QUERY_VAR ),
			esc_attr( $token ),
			$baja ? 'No quiero recibirlas' : 'Volver a recibirlas'
		);
	}

	/**
	 * @param string $token Token del enlace.
	 * @param bool   $ya    Si ya estaba dado de baja.
	 * @return string
	 */
	private static function cuerpo_confirmar( string $token, bool $ya ): string {
		if ( $ya ) {
			return '<h1>Ya no te pedimos reseñas</h1>'
				. '<p>No vamos a enviarte más invitaciones a reseñar. Los correos de tus pedidos y de tus Puntos Akibara siguen llegando: son parte de la compra.</p>'
				. self::formulario( $token, false );
		}

		return '<h1>¿Dejamos de pedirte reseñas?</h1>'
			. '<p>Puedes decirnos que no te enviemos más invitaciones a reseñar tus compras. Los correos de tus pedidos y de tus Puntos Akibara seguirán llegando: son parte de la compra.</p>'
			. self::formulario( $token, true );
	}

	/**
	 * @param string $token Token del enlace.
	 * @param bool   $baja  Si acaba de darse de baja.
	 * @return string
	 */
	private static function cuerpo_hecho( string $token, bool $baja ): string {
		if ( $baja ) {
			return '<h1>Listo</h1>'
				. '<p>No volveremos a pedirte reseñas. Si fue sin querer, puedes revertirlo aquí mismo.</p>'
				. self::formulario( $token, false )
				. sprintf( '<p class="akb-rf-nota" style="margin-top:24px"><a href="%s" style="color:#f5f5f5">Volver a la tienda</a></p>', esc_url( home_url( '/' ) ) );
		}

		return '<h1>Volvimos a activarlas</h1>'
			. '<p>Te escribiremos de nuevo después de tus próximos pedidos para pedirte tu opinión.</p>'
			. self::formulario( $token, true )
			. sprintf( '<p class="akb-rf-nota" style="margin-top:24px"><a href="%s" style="color:#f5f5f5">Volver a la tienda</a></p>', esc_url( home_url( '/' ) ) );
	}

	/**
	 * @return string
	 */
	private static function cuerpo_invalido(): string {
		return '<h1>Ese enlace ya no sirve</h1>'
			. '<p class="akb-rf-aviso">No pudimos reconocer el enlace. Escríbenos a <a href="mailto:contacto@akibara.cl" style="color:#f5f5f5">contacto@akibara.cl</a> y lo hacemos nosotros.</p>'
			. sprintf( '<p class="akb-rf-nota" style="margin-top:24px"><a href="%s" style="color:#f5f5f5">Volver a la tienda</a></p>', esc_url( home_url( '/' ) ) );
	}
}


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/src/Reviews/Media.php
================================================================

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


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/src/Reviews/Moderation.php
================================================================

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


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/src/Reviews/PhotoSurfaces.php
================================================================

<?php
/**
 * Renderizado de la fotografía de la reseña en las superficies necesarias.
 *
 * Muestra la foto adjunta tanto en la ficha de producto (PDP) como en la
 * pantalla de moderación de comentarios en el escritorio de administración.
 *
 * Utiliza el filtro `comment_text` porque es el punto común que procesa ambas
 * superficies (listado de reseñas en el front y tabla de moderación administrativa).
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Reviews;

defined( 'ABSPATH' ) || exit;

final class PhotoSurfaces {

	/** Ancho máximo de la foto en la ficha, en píxeles. */
	private const ANCHO_FICHA = 240;

	/** Ancho de la miniatura en la pantalla de moderación, en píxeles. */
	private const ANCHO_ADMIN = 96;

	public function register(): void {
		// Prioridad 26: después de la insignia de reseña transcrita (20), para
		// mantener el orden secuencial texto -> insignia -> foto.
		add_filter( 'comment_text', array( $this, 'append_photo' ), 26, 2 );
	}

	/**
	 * Añade la foto de la reseña al final de su texto.
	 *
	 * @param string           $text    Texto del comentario ya filtrado.
	 * @param \WP_Comment|null $comment Comentario, cuando el llamador lo pasa.
	 * @return string
	 */
	public function append_photo( $text, $comment = null ): string {
		$text = (string) $text;

		if ( ! $comment instanceof \WP_Comment ) {
			return $text;
		}

		// Sólo reseñas de producto: los comentarios del blog no llevan foto.
		if ( 'product' !== get_post_type( (int) $comment->comment_post_ID ) ) {
			return $text;
		}

		$foto = self::foto( (int) $comment->comment_ID );
		if ( array() === $foto ) {
			return $text;
		}

		$autor = trim( (string) $comment->comment_author );
		$alt   = '' !== $autor
			? sprintf( 'Foto de la reseña de %s', $autor )
			: 'Foto de la reseña';

		return $text . self::figura( $foto, $alt );
	}

	/**
	 * La foto de una reseña: primero la local (adjunto), luego la remota.
	 *
	 * Misma vara que `Media::has_real_photo()` y que el carrusel: el adjunto
	 * tiene que EXISTIR y ser imagen. Una meta huérfana no puede colar un hueco
	 * donde iba la foto.
	 *
	 * @param int $comment_id Reseña.
	 * @return array{id:int,url:string}|array{} Vacío si no hay foto utilizable.
	 */
	private static function foto( int $comment_id ): array {
		foreach ( (array) get_comment_meta( $comment_id, Media::META_LOCAL_IMAGE ) as $attachment_id ) {
			$attachment_id = (int) $attachment_id;
			if ( $attachment_id > 0 && wp_attachment_is( 'image', $attachment_id ) ) {
				return array(
					'id'  => $attachment_id,
					'url' => (string) wp_get_attachment_image_url( $attachment_id, 'large' ),
				);
			}
		}

		foreach ( (array) get_comment_meta( $comment_id, Media::META_REMOTE_IMAGE ) as $remota ) {
			if ( ! is_array( $remota ) || empty( $remota['url'] ) || ! is_string( $remota['url'] ) ) {
				continue;
			}

			// En entornos HTTPS, asegurar esquema https para evitar bloqueo de contenido mixto.
			// En entornos locales o sin SSL, preservar el esquema original.
			$url = is_ssl() ? set_url_scheme( $remota['url'], 'https' ) : $remota['url'];

			// Sólo web: una meta manipulada no puede colar un esquema raro.
			if ( ! preg_match( '#^https?://#i', $url ) ) {
				continue;
			}

			return array(
				'id'  => 0,
				'url' => $url,
			);
		}

		return array();
	}

	/**
	 * Estilo en línea de la foto: tope de ancho contenido que respeta su contenedor responsivo.
	 *
	 * Utiliza min(ancho, 100%) para evitar desbordes en contenedores estrechos en vista móvil.
	 *
	 * @param int $ancho Tope en píxeles.
	 * @return string
	 */
	private static function estilo( int $ancho ): string {
		return sprintf( 'max-width:min(%dpx,100%%);height:auto;border-radius:4px;display:block;', $ancho );
	}

	/**
	 * El marcado de la foto, distinto según quién mira.
	 *
	 * En el escritorio va una miniatura: la tabla de moderación tiene una fila
	 * por reseña y una foto grande la vuelve ilegible. En la ficha va la imagen
	 * a tamaño de lectura, perezosa, porque puede haber muchas reseñas abajo.
	 *
	 * @param array{id:int,url:string} $foto Foto resuelta.
	 * @param string                   $alt  Texto alternativo.
	 * @return string
	 */
	private static function figura( array $foto, string $alt ): string {
		$en_admin = is_admin();
		$ancho    = $en_admin ? self::ANCHO_ADMIN : self::ANCHO_FICHA;

		if ( $foto['id'] > 0 ) {
			$img = wp_get_attachment_image(
				$foto['id'],
				$en_admin ? 'thumbnail' : 'medium',
				false,
				array(
					'class'   => 'akb-rw-foto__img',
					'alt'     => $alt,
					'loading' => 'lazy',
					'style'   => self::estilo( $ancho ),
				)
			);
		} else {
			$img = sprintf(
				'<img src="%s" alt="%s" class="akb-rw-foto__img" loading="lazy" style="%s">',
				esc_url( $foto['url'] ),
				esc_attr( $alt ),
				esc_attr( self::estilo( $ancho ) )
			);
		}

		if ( '' === $img ) {
			return '';
		}

		// En el escritorio la miniatura enlaza a la imagen completa: el dueño
		// aprueba mirando el detalle, no un sello de 96 píxeles.
		if ( $en_admin && '' !== $foto['url'] ) {
			$img = sprintf(
				'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
				esc_url( $foto['url'] ),
				$img
			);
		}

		return sprintf(
			'<figure class="akb-rw-foto" style="margin:10px 0 0;">%s</figure>',
			$img
		);
	}
}


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/src/Reviews/ReviewForm.php
================================================================

<?php
/**
 * ReviewForm — el formulario de reseña por TOKEN (Fase 2b) + ruta legacy.
 *
 * El cliente llega desde el correo de invitación con /resena/{token}: una
 * página propia, liviana (HTML del servidor, cero JS, 44px, es-CL), que
 * lista los productos de su pedido y solicita calificación más texto. El enlace abre la
 * oferta de puntos con foto requerida. La reseña voluntaria con foto opcional vive en la ficha.
 * La reseña nace PENDIENTE (moderación previa antes de publicar) y vinculada al pedido.
 *
 * Ruta legacy /cusrev/{formId}: los 3 enlaces del tercero ya enviados por
 * correo caen en una página amable que lleva a Mi cuenta (la tabla del
 * tercero muere en el cleanup; el enlace no puede validar, pero el cliente
 * no ve un 404).
 *
 * Los núcleos render_page()/process() son testeables (devuelven string /
 * resultado); el `exit` vive solo en el wrapper.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Reviews;

use Akibara\Rewards\Support\Options;
use Akibara\Rewards\Support\PrivatePage;
use Akibara\Rewards\Support\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.3.0
 */
final class ReviewForm {

	public const QUERY_VAR        = 'akb_review_token';

	/** Regla de /resena/{token}; AccountRewards comprueba que siga en las reglas guardadas. */
	public const RULE = '^resena/([A-Za-z0-9]{20,64})/?$';
	public const LEGACY_QUERY_VAR = 'akb_review_legacy';
	public const META_ORDER       = '_akb_rewards_review_order';
	private bool $points_requested = false;
	/** @var array<string,mixed> Campos del último POST con nonce válido. */
	private array $submitted = array();

	/**
	 * URL pública del formulario para un token.
	 *
	 * @param string $token Token emitido.
	 * @return string
	 */
	public static function url_for( string $token ): string {
		// Bajo la URL de Mi cuenta para asegurar que el borde CDN respete el no-store
		// y no sirva respuestas cacheadas a invitados (ver Support\PrivatePage).
		// La ruta antigua sigue viva para retrocompatibilidad mediante redirección.
		return add_query_arg( self::QUERY_VAR, rawurlencode( $token ), PrivatePage::base_url() );
	}

	/**
	 * ¿La petición llegó por la ruta antigua `/resena/<token>/`?
	 *
	 * @return bool
	 */
	private static function es_ruta_antigua(): bool {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sólo se compara contra un prefijo literal.

		return 1 === preg_match( '#^/resena/#', (string) wp_parse_url( $uri, PHP_URL_PATH ) );
	}

	/**
	 * Cuelga rutas y render.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'add_routes' ) );
		add_filter( 'query_vars', array( $this, 'register_query_vars' ) );
		add_filter( 'woocommerce_product_review_comment_form_args', array( $this, 'native_form_notice' ) );
		( new VoluntaryReviewForm() )->register();
		( new AccountReviewLink() )->register();
		// Prioridad 1: antes de que la página de Mi cuenta pinte su acceso.
		add_action( 'template_redirect', array( $this, 'maybe_render' ), 1 );
	}

	/**
	 * Explica la reseña voluntaria de la ficha sin prometer un premio.
	 *
	 * @param array<string,mixed> $args Formulario de WooCommerce.
	 * @return array<string,mixed>
	 */
	public function native_form_notice( array $args ): array {
		$notice = 'Esta reseña es voluntaria: puedes añadir una foto y no solicita puntos.';
		if ( 'yes' === (string) get_option( 'akb_rewards_moderate_reviews', 'yes' ) ) {
			$notice .= ' Tu reseña quedará pendiente de revisión.';
		}
		if ( Options::reviews_lane_on() ) {
			$notice .= ' Para solicitar puntos con una foto real de tu compra, usa el enlace de reseña de tu pedido.';
		}
		$args['comment_field'] = '<p class="akb-review-photo-notice">' . esc_html( $notice ) . '</p>'
			. (string) ( $args['comment_field'] ?? '' );
		return $args;
	}

	/**
	 * Reglas de rewrite propias + legacy.
	 *
	 * @return void
	 */
	public function add_routes(): void {
		add_rewrite_rule( self::RULE, 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );
		add_rewrite_rule( '^cusrev/([^/]+)/?$', 'index.php?' . self::LEGACY_QUERY_VAR . '=$matches[1]', 'top' );
	}

	/**
	 * @param array<int,string> $vars Query vars públicas.
	 * @return array<int,string>
	 */
	public function register_query_vars( $vars ): array {
		$vars   = is_array( $vars ) ? $vars : array();
		$vars[] = self::QUERY_VAR;
		$vars[] = self::LEGACY_QUERY_VAR;
		return $vars;
	}

	/**
	 * Wrapper con exit: decide y pinta. El trabajo vive en los núcleos.
	 *
	 * @return void
	 */
	public function maybe_render(): void {
		$token  = (string) get_query_var( self::QUERY_VAR );
		$legacy = (string) get_query_var( self::LEGACY_QUERY_VAR );

		if ( '' === $token && '' === $legacy ) {
			return;
		}

		$metodo = isset( $_SERVER['REQUEST_METHOD'] )
			? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) )
			: 'GET';

		// Un enlace de un correo ya enviado: se lleva a la URL que el borde no
		// cachea. Sólo el GET —un POST en vuelo se atiende donde llegó— y con
		// 302, porque el destino depende de dónde viva Mi cuenta.
		if ( '' !== $token && 'GET' === $metodo && self::es_ruta_antigua() ) {
			wp_safe_redirect( self::url_for( $token ), 302, 'Akibara Rewards' );
			exit;
		}

		PrivatePage::no_cache( 'Akibara Rewards: formulario de reseña' );

		if ( '' !== $legacy ) {
			echo self::shell( $this->legacy_body() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML propio, escapado por partes.
			exit;
		}

		$notice = '';
		if ( 'POST' === $metodo ) {
			$resultado = $this->process( $token );
			if ( true === $resultado ) {
				echo self::shell( $this->thanks_body() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				exit;
			}
			$notice = (string) $resultado;
		}

		echo self::shell( $this->render_page( $token, $notice ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Valida un token contra la tabla.
	 *
	 * @param string $token Token del enlace.
	 * @return array{order_id:int,expires_at:string}|null Null si no vale (inexistente/caducado/usado).
	 */
	public static function validate_token( string $token ) {
		global $wpdb;

		if ( ! preg_match( '/^[A-Za-z0-9]{20,64}$/', $token ) ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- el único interpolado es el nombre de nuestra tabla.
				'SELECT order_id, expires_at, used_at FROM ' . Schema::review_forms_table() . ' WHERE token = %s',
				$token
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) || null !== $row['used_at'] ) {
			return null;
		}

		if ( gmdate( 'Y-m-d H:i:s' ) > (string) $row['expires_at'] ) {
			return null;
		}

		return array( 'order_id' => (int) $row['order_id'], 'expires_at' => (string) $row['expires_at'] );
	}

	/**
	 * Núcleo del POST: valida todo y crea la reseña PENDIENTE.
	 *
	 * @param string $token Token del enlace.
	 * @return true|string True si quedó creada; si no, el aviso a mostrar.
	 */
	public function process( string $token ) {
		$valid = self::validate_token( $token );
		if ( null === $valid ) {
			return 'Este enlace ya se usó o caducó.';
		}

		if ( ! isset( $_POST['_akbnonce'] ) || ! is_string( $_POST['_akbnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_akbnonce'] ) ), 'akb_review_' . $token ) ) {
			return 'La sesión del formulario caducó: vuelve a intentarlo.';
		}

		$order = wc_get_order( $valid['order_id'] );
		if ( ! $order instanceof \WC_Order ) {
			return 'No encontramos el pedido de este enlace.';
		}

		$this->submitted = array(
			'mode'      => isset( $_POST['akb_rf_mode'] ) && 'voluntary' === $_POST['akb_rf_mode'] ? 'voluntary' : 'reward',
			'product'   => isset( $_POST['akb_rf_product'] ) && is_scalar( $_POST['akb_rf_product'] ) ? (int) $_POST['akb_rf_product'] : 0,
			'rating'    => isset( $_POST['akb_rf_rating'] ) && is_scalar( $_POST['akb_rf_rating'] ) ? (int) $_POST['akb_rf_rating'] : 0,
			'content'   => isset( $_POST['akb_rf_content'] ) && is_string( $_POST['akb_rf_content'] ) ? trim( sanitize_textarea_field( wp_unslash( $_POST['akb_rf_content'] ) ) ) : '',
			'requested' => isset( $_POST['akb_rf_points_requested'] ) && is_string( $_POST['akb_rf_points_requested'] ) && '1' === sanitize_key( wp_unslash( $_POST['akb_rf_points_requested'] ) ),
		);

		$product_id = (int) $this->submitted['product'];
		if ( ! self::order_has_product( $order, $product_id ) ) {
			return 'Elige uno de los productos de tu pedido.';
		}

		if ( ! VerifiedPurchase::order_confirms( $order, $product_id, (string) $order->get_billing_email(), (int) $order->get_customer_id() ) ) {
			return 'Este producto ya no corresponde a una compra válida para reseñar.';
		}

		$rating = (int) $this->submitted['rating'];
		if ( $rating < 1 || $rating > 5 ) {
			return 'Elige una calificación de 1 a 5 estrellas.';
		}

		$content = (string) $this->submitted['content'];
		if ( mb_strlen( $content ) < 10 ) {
			return 'Cuéntanos un poco más: al menos 10 caracteres.';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- el nonce se verificó al entrar en process().
		$requested = Options::reviews_lane_on();
		if ( $requested && ( 'voluntary' === $this->submitted['mode'] || ! $this->submitted['requested'] ) ) {
			return 'Este enlace solicita puntos y requiere una foto de tu compra. Vuelve a abrir el formulario y confirma el envío.';
		}
		$has_photo = self::photo_is_present( isset( $_FILES ) && is_array( $_FILES ) ? $_FILES : array() );
		if ( $requested && ! $has_photo ) {
			return 'Para solicitar puntos necesitas una foto de tu compra. Adjunta una foto real de tu compra y vuelve a enviarla.';
		}

		$attachment_id = 0;
		$file = $_FILES['akb_rf_photo'] ?? array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- estructura validada por upload_photo(), después del nonce.
		$upload_attempted = $has_photo || ! is_array( $file ) || ( isset( $file['error'] ) && UPLOAD_ERR_NO_FILE !== $file['error'] ) || ! empty( $file['name'] );
		if ( $upload_attempted ) {
			$subida = $this->handle_upload( $product_id );
			if ( is_wp_error( $subida ) ) {
				return 'La foto no se pudo subir: ' . $subida->get_error_message();
			}
			$attachment_id = (int) $subida;
		}

		$commentdata = array(
			'comment_post_ID'      => $product_id,
			'comment_author'       => trim( (string) $order->get_billing_first_name() . ' ' . (string) $order->get_billing_last_name() ),
			'comment_author_email' => (string) $order->get_billing_email(),
			'comment_author_url'   => '',
			'comment_content'      => $content,
			'comment_type'         => 'review',
			'comment_parent'       => 0,
			'comment_author_IP'    => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
			'comment_agent'        => isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 254 ) : '',
			'user_id'              => (int) $order->get_customer_id(),
			'comment_approved'     => 0,
			'comment_meta'         => array(
				'rating' => $rating,
				ReviewPoints::META_POINTS_REQUESTED => (int) $requested,
			),
		);

		// El token se quema ANTES de crear, con escritura CONDICIONAL: dos
		// envíos casi simultáneos pasaban las dos validaciones y creaban dos
		// reseñas (y dos premios). El UPDATE ... WHERE used_at IS NULL deja
		// pasar a UNO solo — la carrera la resuelve la base, no el orden de
		// llegada. Marcar usado después era la ventana.
		if ( ! self::claim_token( $token ) ) {
			// La foto ya está en disco: se subió arriba, antes de saber quién
			// ganaba la carrera. Ese orden es deliberado —si la subida falla, el
			// token sigue vivo y el cliente reintenta—, pero deja al perdedor con
			// un adjunto sin reseña, sin `post_parent` y con URL pública. El caso
			// corriente no es un atacante sino el doble clic en «Enviar mi
			// reseña» con foto. `AdminReviews::handle_form()` ya limpia en el
			// caso equivalente («el residuo sin GC que le reprochamos a CusRev»):
			// esta ruta es la que se saltaba la convención.
			self::discard_orphan_photo( $attachment_id );
			return 'Este enlace ya se usó o caducó.';
		}

		// El orden del núcleo es escapar, filtrar e insertar. wp_insert_comment()
		// espera datos escapados para conservar las barras escritas por el cliente.
		$comment_id = wp_insert_comment( wp_filter_comment( wp_slash( $commentdata ) ) );
		if ( ! $comment_id ) {
			// Nada que premiar: el enlace vuelve a servir para reintentar, y la
			// foto del intento fallido tampoco se queda (el reintento sube la
			// suya). Sin esto, un INSERT que falle de forma repetible —un cuerpo
			// que MySQL rechaza, por ejemplo— fuga un adjunto por vuelta, porque
			// `release_token()` devuelve el enlace al ruedo.
			self::release_token( $token );
			self::discard_orphan_photo( $attachment_id );
			return 'No pudimos guardar tu reseña; inténtalo otra vez.';
		}

		// Vínculo auditable reseña↔pedido (spec 2b: «guarda el vínculo al
		// pedido»); Woo escribe 'verified' en el comment_post de abajo.
		update_comment_meta( $comment_id, self::META_ORDER, (int) $order->get_id() );
		// Woo sólo reconoce processing/completed al verificar compras. El
		// pedido del token permite verificar también Listo para enviar pagado.
		// Se escribe antes de comment_post: el add_comment_meta unique de Woo
		// conserva esta prueba y la moderación muestra la insignia correcta.
		update_comment_meta(
			$comment_id,
			'verified',
			(int) VerifiedPurchase::order_confirms( $order, $product_id, (string) $order->get_billing_email(), (int) $order->get_customer_id() )
		);

		if ( $attachment_id > 0 ) {
			Media::attach_photo( (int) $comment_id, $attachment_id, $product_id );
		}

		/** Woo escucha comment_post para 'verified'; nuestro motor, para el premio (que espera la APROBACIÓN). */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- no es un hook nuestro: es el de WP core, y el nombre exacto es lo que hace que Woo y nuestro motor escuchen. Prefijarlo los dejaría sordos.
		do_action( 'comment_post', (int) $comment_id, 0, $commentdata );

		$this->points_requested = $requested;
		return true;
	}

	/**
	 * ¿El producto pertenece al pedido?
	 *
	 * @param \WC_Order $order      Pedido.
	 * @param int       $product_id Producto elegido.
	 * @return bool
	 */
	private static function order_has_product( \WC_Order $order, int $product_id ): bool {
		if ( $product_id <= 0 ) {
			return false;
		}

		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! method_exists( $item, 'get_product_id' ) || (int) $item->get_product_id() !== $product_id ) {
				continue;
			}
			if ( self::fue_devuelto( $order, (int) $item_id, $item ) ) {
				continue;
			}
			return true;
		}

		return false;
	}

	/**
	 * ¿Esta línea se devolvió entera?
	 *
	 * Sin esto, un tomo reembolsado sigue contando como comprado: el formulario lo
	 * ofrecía para reseñar y WooCommerce marcaba esa reseña como «compra
	 * verificada». Pasó de verdad — pedido #27782, Frieren 06: la editorial no
	 * tenía el tomo, se devolvió el dinero, y el formulario iba a pedirle al
	 * cliente que opinara de un libro que nunca tuvo en las manos.
	 *
	 * `get_qty_refunded_for_item()` devuelve la cantidad en NEGATIVO (-1 = una
	 * unidad devuelta), de ahí el valor absoluto.
	 *
	 * @since 0.3.6
	 *
	 * @param \WC_Order $order   Pedido.
	 * @param int       $item_id Id de la línea.
	 * @param mixed     $item    La línea.
	 * @return bool
	 */
	private static function fue_devuelto( \WC_Order $order, int $item_id, $item ): bool {
		if ( ! method_exists( $item, 'get_quantity' ) ) {
			return false;
		}

		$compradas  = (int) $item->get_quantity();
		$devueltas  = abs( (int) $order->get_qty_refunded_for_item( $item_id ) );

		return $compradas > 0 && $devueltas >= $compradas;
	}

	/**
	 * ¿Viene la foto en el envío?
	 *
	 * Separado de `handle_upload()` a propósito: esa carga
	 * `wp-admin/includes/media.php` y no se puede ejercer en la suite, mientras
	 * que esta comprobación —la que decide si la reseña se acepta— sí tiene que
	 * poder probarse.
	 *
	 * @param array<string, mixed> $files El `$_FILES` del envío.
	 */
	private static function photo_is_present( array $files ): bool {
		$foto = $files['akb_rf_photo'] ?? null;

		return is_array( $foto ) && is_string( $foto['name'] ?? null ) && '' !== trim( $foto['name'] );
	}

	/**
	 * Sube la foto del formulario (5 MB máx, solo imagen).
	 *
	 * Los `require_once` van guardados por `function_exists`: cargar de nuevo lo
	 * que ya está cargado no aporta nada en producción, y en la suite permite
	 * que los tests que prueban OTRA cosa —la carrera del doble clic, la
	 * devolución del token— sustituyan `media_handle_upload()` para llegar a lo
	 * suyo. Desde que la foto es obligatoria, TODO envío pasa por aquí.
	 *
	 * @param int $product_id Producto para el reparentado.
	 * @return int|\WP_Error ID del adjunto.
	 */
	private function handle_upload( int $product_id ) {
		unset( $product_id ); // Media::attach_photo() enlaza la imagen tras crear la reseña.
		return VoluntaryReviewForm::upload_photo( 'akb_rf_photo' );
	}

	/**
	 * Toma el token para ESTE envío: lo marca usado sólo si seguía libre.
	 *
	 * @param string $token Token.
	 * @return bool True si este envío se quedó con el token.
	 */
	private static function claim_token( string $token ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- tabla propia; el WHERE es el candado.
		$claimed = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- el único interpolado es el nombre de nuestra tabla.
				'UPDATE ' . Schema::review_forms_table() . ' SET used_at = %s WHERE token = %s AND used_at IS NULL',
				gmdate( 'Y-m-d H:i:s' ),
				$token
			)
		);

		return (int) $claimed > 0;
	}

	/**
	 * Borra una foto que se subió para una reseña que al final no nació.
	 *
	 * Se llama sólo en las salidas posteriores a la subida y anteriores a
	 * `Media::attach_photo()`, o sea cuando el adjunto es con certeza huérfano:
	 * todavía no tiene reseña ni `post_parent`. El `true` fuerza el borrado sin
	 * pasar por la papelera — los adjuntos no la usan de todos modos, y el punto
	 * es no dejar el fichero en `uploads/`.
	 *
	 * @param int $attachment_id Adjunto recién creado, o 0 si no hubo foto.
	 * @return void
	 */
	private static function discard_orphan_photo( int $attachment_id ): void {
		if ( $attachment_id > 0 && function_exists( 'wp_delete_attachment' ) ) {
			wp_delete_attachment( $attachment_id, true );
		}
	}

	/**
	 * Devuelve el token al ruedo: sólo cuando la reseña NO llegó a nacer.
	 *
	 * @param string $token Token.
	 * @return void
	 */
	private static function release_token( string $token ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- tabla propia.
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- el único interpolado es el nombre de nuestra tabla.
				'UPDATE ' . Schema::review_forms_table() . ' SET used_at = NULL WHERE token = %s',
				$token
			)
		);
	}

	/**
	 * Núcleo del GET: el formulario (o el aviso de enlace inválido).
	 *
	 * @param string $token  Token del enlace.
	 * @param string $notice Aviso de un POST fallido.
	 * @return string
	 */
	public function render_page( string $token, string $notice = '' ): string {
		$valid = self::validate_token( $token );
		if ( null === $valid ) {
			return $this->invalid_body();
		}

		$order = wc_get_order( $valid['order_id'] );
		if ( ! $order instanceof \WC_Order ) {
			return $this->invalid_body();
		}

		$productos = '';
		$n         = 0;
		foreach ( $order->get_items() as $item ) {
			if ( ! method_exists( $item, 'get_product_id' ) ) {
				continue;
			}
			// Un tomo devuelto no se ofrece para reseñar: el cliente nunca lo tuvo.
			if ( ! VerifiedPurchase::order_confirms( $order, (int) $item->get_product_id(), (string) $order->get_billing_email(), (int) $order->get_customer_id() ) ) {
				continue;
			}
			++$n;
			$productos .= sprintf(
				'<label class="akb-rf-prod"><input type="radio" name="akb_rf_product" value="%d" required%s> <span>%s</span></label>',
				(int) $item->get_product_id(),
				( isset( $this->submitted['product'] ) ? (int) $item->get_product_id() === (int) $this->submitted['product'] : 1 === $n ) ? ' checked' : '',
				esc_html( (string) $item->get_name() )
			);
		}

		// Pedido entero devuelto: no queda nada que reseñar. Sin esto el
		// formulario se pintaba vacío, con un radio `required` que era imposible
		// de marcar — el cliente veía un formulario que no se puede enviar.
		if ( 0 === $n ) {
			return $this->invalid_body();
		}

		$estrellas = '';
		for ( $i = 5; $i >= 1; $i-- ) {
			$estrellas .= sprintf(
				'<input type="radio" id="akb-rf-star%1$d" name="akb_rf_rating" value="%1$d" required%2$s><label for="akb-rf-star%1$d" aria-label="%1$d estrellas">★</label>',
				$i,
				$i === (int) ( $this->submitted['rating'] ?? 0 ) ? ' checked' : ''
			);
		}

		$reward = Options::reviews_lane_on();
		$premio = $reward
			? '<div class="akb-rf-premio"><strong>Reseña con puntos</strong><p>' . esc_html( RewardMessage::text() ) . '</p></div>'
			: '<p>Reseña voluntaria sin puntos. La foto es opcional; adjuntarla no solicita un premio.</p>';
		return sprintf(
			'<h1>Tu reseña del pedido #%d</h1>
			%s
			%s
			<details class="akb-rf-campo"><summary>Todavía no lo recibí</summary><p>Espera a recibir tu compra para contar tu experiencia. Guarda este enlace y vuelve cuando la tengas; este enlace vence el %s (UTC). Abrir este aviso no envía una reseña ni usa tu enlace.</p></details>
			<form method="post" enctype="multipart/form-data">
				%s
				<input type="hidden" name="akb_rf_mode" value="%s">
				<input type="hidden" name="akb_rf_points_requested" value="%d">
				<fieldset class="akb-rf-campo"><legend>¿Qué producto reseñas?</legend>%s</fieldset>
				<fieldset class="akb-rf-campo"><legend>Tu calificación</legend><div class="akb-rf-stars">%s</div></fieldset>
				<label class="akb-rf-campo" for="akb_rf_content">Tu reseña<textarea id="akb_rf_content" name="akb_rf_content" rows="5" required minlength="10" placeholder="¿Qué te pareció? Edición, papel, traducción, envío…">%s</textarea></label>
				<label class="akb-rf-campo" for="akb_rf_photo">Foto de tu compra (%s)<input type="file" id="akb_rf_photo" name="akb_rf_photo" accept="image/jpeg,image/png,image/webp,image/heic"%s aria-describedby="akb-rf-photo-help"><span id="akb-rf-photo-help" class="akb-rf-ayuda">%s Máximo 5 MB; JPG, PNG, WebP o HEIC.</span></label>
				<button type="submit">Enviar mi reseña</button>
				<p class="akb-rf-nota">Tu reseña se publica cuando la revisemos. ¡Gracias por ayudar a otros coleccionistas!</p>
			</form>',
			(int) $order->get_id(),
			$premio,
			'' !== $notice ? sprintf( '<p class="akb-rf-aviso" role="alert">%s</p>', esc_html( $notice ) ) : '',
			esc_html( $valid['expires_at'] ),
			wp_nonce_field( 'akb_review_' . $token, '_akbnonce', true, false ),
			$reward ? 'reward' : 'voluntary',
			(int) $reward,
			$productos,
			$estrellas,
			esc_html( (string) ( $this->submitted['content'] ?? '' ) ),
			$reward ? 'obligatoria para solicitar puntos' : 'opcional',
			$reward ? ' required' : '',
			$reward ? 'Adjunta una foto real de tu compra para solicitar puntos.' : 'Puedes enviarla sin foto. Esta reseña no solicita puntos.'
		);
	}

	/**
	 * Cuerpo del agradecimiento post-envío.
	 *
	 * @return string
	 */
	private function thanks_body(): string {
		return sprintf(
			'<h1>¡Gracias! 🎉</h1>
			<p>Tu reseña quedó en revisión: la publicamos apenas la aprobemos.</p>
			%s
			<p><a class="akb-rf-btn" href="%s">Volver a la tienda</a></p>',
			$this->points_requested
				? '<p>Recompensa pendiente de revisión: solicitaste puntos por esta reseña. ' . esc_html( RewardMessage::text( false ) ) . '</p>'
				: '<p>No solicitaste puntos. Tu reseña se revisará con normalidad.</p>',
			esc_url( home_url( '/' ) )
		);
	}

	/**
	 * Cuerpo del enlace inválido.
	 *
	 * @return string
	 */
	private function invalid_body(): string {
		return sprintf(
			'<h1>Este enlace ya no sirve</h1>
			<p>El enlace de reseña ya se usó o venció. Puedes escribir tu reseña desde la ficha del producto cuando quieras.</p>
			<p><a class="akb-rf-btn" href="%s">Ir a mis pedidos</a></p>',
			esc_url( function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( 'orders' ) : home_url( '/mi-cuenta/' ) )
		);
	}

	/**
	 * Cuerpo de la ruta legacy /cusrev/{formId}.
	 *
	 * @return string
	 */
	private function legacy_body(): string {
		return sprintf(
			'<h1>Cambiamos de sistema de reseñas</h1>
			<p>Este enlace venía de nuestro sistema anterior y ya no funciona, pero tu opinión nos sigue importando: puedes reseñar desde la ficha del producto o desde tus pedidos.</p>
			<p><a class="akb-rf-btn" href="%s">Ir a mis pedidos</a></p>',
			esc_url( function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( 'orders' ) : home_url( '/mi-cuenta/' ) )
		);
	}

	/**
	 * Cáscara HTML propia: liviana, brand, mobile-first, cero JS.
	 *
	 * Pública desde la 0.4.9 porque la página de preferencias de correo
	 * (`InviteOptOut`) es la misma pantalla para el cliente: dos carcasas
	 * distintas para dos enlaces del mismo correo se ven como dos sitios.
	 *
	 * @param string $body  Cuerpo ya escapado por partes.
	 * @param string $title Título de la pestaña.
	 * @return string
	 */
	public static function shell( string $body, string $title = 'Tu reseña — Akibara' ): string {
		return '<!doctype html><html lang="es-CL"><head><meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width, initial-scale=1">'
			. '<meta name="robots" content="noindex,nofollow">'
			. '<title>' . esc_html( $title ) . '</title><style>'
			. ':root{color-scheme:dark}'
			. 'body{margin:0;background:#0d0d0d;color:#f5f5f5;font:16px/1.6 system-ui,-apple-system,"Segoe UI",sans-serif;-webkit-font-smoothing:antialiased}'
			. '.akb-rf{max-width:560px;margin:0 auto;padding:24px 16px 64px}'
			. '.akb-rf-logo{display:block;margin:0 0 24px;font-weight:800;font-size:22px;color:#E60012;text-decoration:none}'
			. 'h1{font-size:26px;margin:0 0 16px}'
			. '.akb-rf-campo{display:block;border:1px solid #2b2b2b;border-radius:12px;padding:16px;margin:0 0 16px;background:#171717}'
			. 'legend{padding:0 6px;font-weight:700}'
			. 'textarea,input[type=file]{width:100%;box-sizing:border-box;margin-top:8px;background:#0d0d0d;color:#f5f5f5;border:1px solid #2b2b2b;border-radius:8px;padding:12px;font:inherit}'
			. '.akb-rf-prod{display:flex;gap:10px;align-items:center;min-height:44px}'
			. '.akb-rf-stars{display:flex;flex-direction:row-reverse;justify-content:flex-end;gap:4px}'
			. '.akb-rf-stars input{position:absolute;opacity:0;width:1px;height:1px}'
			. '.akb-rf-stars label{font-size:34px;line-height:44px;min-width:44px;text-align:center;color:#3a3a3a;cursor:pointer}'
			. '.akb-rf-stars input:checked ~ label,.akb-rf-stars label:hover,.akb-rf-stars label:hover ~ label{color:#FFD700}'
			. '.akb-rf-stars input:focus-visible + label{outline:2px solid #fff;outline-offset:2px;border-radius:6px}'
			. 'button,.akb-rf-btn{display:inline-flex;align-items:center;justify-content:center;min-height:48px;padding:12px 28px;border:0;border-radius:8px;background:#E60012;color:#fff;font-weight:700;font-size:16px;cursor:pointer;text-decoration:none}'
			. '.akb-rf-premio{border-left:3px solid #E60012;background:rgba(230,0,18,.08);padding:10px 12px;border-radius:0 8px 8px 0}'
			. '.akb-rf-aviso{border-left:3px solid #E60012;background:rgba(230,0,18,.1);padding:10px 12px;border-radius:0 8px 8px 0}'
			. '.akb-rf-nota{font-size:13px;opacity:.7}'
			. '</style></head><body><main class="akb-rf">'
			. '<a class="akb-rf-logo" href="' . esc_url( home_url( '/' ) ) . '">AKIBARA</a>'
			. $body
			. '</main></body></html>';
	}
}


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/src/Reviews/ReviewNudge.php
================================================================

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
			$texto .= ' Escribe tu opinión cuando hayas recibido la compra.';
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


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/src/Reviews/ReviewPoints.php
================================================================

<?php
/**
 * ReviewPoints — Puntos por reseña con foto tras moderación y aprobación administrativa.
 *
 * Disparadores: la aprobación puede llegar por transición de estado o por inserción directa.
 * Se escuchan ambos eventos garantizando idempotencia mediante metadatos y event_key.
 *
 * Compuertas de validación:
 *   - Compra verificada obligatoria en WooCommerce.
 *   - Un pago por cliente-producto en toda la historia (índice UNIQUE en el ledger).
 *   - Exclusión de reseñas previas con incentivos de terceros.
 *   - Producto publicado, fotografía real y exclusión de reseñas de administración.
 *   - Usuarios invitados: retención con sello pendiente hasta validación de cuenta.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Reviews;

use Akibara\Rewards\Points\Ledger;
use Akibara\Rewards\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.2.0
 */
final class ReviewPoints {

	public const META_POINTS_PAID   = '_akb_rewards_review_points';
	/** 1 = solicitó premio; 0 = reseña sin premio. Ausente conserva el trato anterior. */
	public const META_POINTS_REQUESTED = '_akb_rewards_review_points_requested';
	public const SEAL_PENDING_USER  = 'pending_account';

	/**
	 * Sello: pagada y luego REVERTIDA (rechazo, papelera, spam o borrado tras
	 * haber cobrado). REVERSIBLE: si se re-aprueba después, `maybe_pay()`
	 * la vuelve a evaluar desde cero.
	 */
	public const SEAL_REVERSED = 'reversed';

	/**
	 * Posición de la reseña en la cadencia «premiar cada N» (1, 2, 3…). Una
	 * reseña con avance y sin puntos igual cuenta para un premio.
	 */
	public const META_COUNTED       = '_akb_rewards_review_counted';

	/** Avance en la cadencia «premiar cada N» («2/3»), visible en moderación. */
	public const META_PROGRESS      = '_akb_rewards_review_progress';

	/**
	 * Cuelga los disparadores.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'transition_comment_status', array( $this, 'on_status_transition' ), 10, 3 );
		add_action( 'comment_post', array( $this, 'on_comment_born' ), 20, 2 );
		add_action( 'wp_insert_comment', array( $this, 'on_comment_inserted' ), 20, 2 );
		// El vínculo invitado→cuenta exige verificar el correo antes de pagar.
		add_action( 'woocommerce_customer_email_verified', array( $this, 'on_email_verified' ), 20, 1 );
		// 'delete_comment' se ejecuta antes de modificar la base de datos, asegurando
		// que META_POINTS_PAID aún exista y pueda efectuarse la reversa de puntos
		// antes de que wp_delete_comment() elimine los metadatos asociados.
		add_action( 'delete_comment', array( $this, 'on_comment_deleting' ), 10, 2 );
	}

	/**
	 * Transición de estado de comentario: pagar al APROBAR, revertir en
	 * cualquier otro destino (rechazo, papelera, spam, o el pseudo-estado
	 * 'delete' de un borrado en firme —cubierto también, sin daño, por
	 * `on_comment_deleting()`, que corre primero con la meta intacta).
	 *
	 * @param string      $new_status Estado nuevo ('approved', 'unapproved'…).
	 * @param string      $old_status Estado anterior.
	 * @param \WP_Comment $comment    Comentario.
	 * @return void
	 */
	public function on_status_transition( $new_status, $old_status, $comment ): void {
		unset( $old_status );

		if ( ! $comment instanceof \WP_Comment ) {
			return;
		}

		if ( 'approved' === (string) $new_status ) {
			$this->maybe_pay( $comment );
			return;
		}

		$this->maybe_reverse( $comment );
	}

	/**
	 * `delete_comment`: el punto MÁS TEMPRANO de `wp_delete_comment()`, con
	 * la commentmeta todavía intacta. Ver el comentario de `register()`.
	 *
	 * @param string           $comment_id ID del comentario (string en el hook nativo).
	 * @param \WP_Comment|null $comment    Comentario a borrar.
	 * @return void
	 */
	public function on_comment_deleting( $comment_id, $comment ): void {
		unset( $comment_id );

		if ( $comment instanceof \WP_Comment ) {
			$this->maybe_reverse( $comment );
		}
	}

	/**
	 * Nacimiento vía wp_new_comment (dispara comment_post con el veredicto).
	 *
	 * @param int        $comment_id ID del comentario.
	 * @param int|string $approved   1/0/'spam'.
	 * @return void
	 */
	public function on_comment_born( $comment_id, $approved ): void {
		if ( 1 !== (int) $approved ) {
			return;
		}

		$comment = get_comment( (int) $comment_id );
		if ( $comment instanceof \WP_Comment ) {
			$this->maybe_pay( $comment );
		}
	}

	/**
	 * Nacimiento vía wp_insert_comment directo (CusRev, imports): el hook
	 * comment_post no corre ahí; este sí, siempre.
	 *
	 * @param int         $comment_id ID.
	 * @param \WP_Comment $comment    Comentario recién insertado.
	 * @return void
	 */
	public function on_comment_inserted( $comment_id, $comment ): void {
		unset( $comment_id );

		if ( $comment instanceof \WP_Comment && '1' === (string) $comment->comment_approved ) {
			$this->maybe_pay( $comment );
		}
	}

	/**
	 * El gancho invitado→cuenta, tras VERIFICAR el email: sus reseñas
	 * aprobadas con sello pendiente cobran (el event_key impide dobles).
	 *
	 * El sello NO se borra antes: maybe_pay acepta `pending_account` y lo
	 * resuelve; si una compuerta posterior corta, la huella sobrevive.
	 *
	 * @param int $user_id Usuario con email verificado.
	 * @return void
	 */
	public function on_email_verified( $user_id ): void {
		$user = get_user_by( 'id', (int) $user_id );
		if ( ! $user instanceof \WP_User || '' === (string) $user->user_email ) {
			return;
		}

		$comments = get_comments(
			array(
				'type'         => 'review',
				'status'       => 'approve',
				'author_email' => (string) $user->user_email,
				'meta_key'     => self::META_POINTS_PAID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'   => self::SEAL_PENDING_USER, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		$pagadas = 0;
		foreach ( (array) $comments as $comment ) {
			if ( $comment instanceof \WP_Comment ) {
				$this->maybe_pay( $comment );
				// Comprueba si la reseña califica como incentivada para invalidar cachés.
				if ( '' !== Disclosure::notice_for( (int) $comment->comment_ID ) ) {
					++$pagadas;
				}
			}
		}

		// Si hubo reseñas que pasaron a incentivadas, purgar el transient del carrusel
		// y la caché de página para reflejar los avisos actualizados.
		if ( $pagadas > 0 ) {
			delete_transient( Carousel::TRANSIENT ); // La tarjeta del home también cambia de criterio.
			do_action( 'litespeed_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook público de LiteSpeed (tercero).
		}
	}

	/**
	 * Paga los puntos de una reseña aprobada si cumple todas las compuertas.
	 *
	 * @param \WP_Comment $comment Reseña aprobada.
	 * @return void
	 */
	public function maybe_pay( \WP_Comment $comment ): void {
		$points = Options::review_points();
		if ( $points <= 0 ) {
			return; // Programa apagado.
		}

		if ( 'review' !== (string) $comment->comment_type ) {
			return;
		}

		if ( (int) $comment->comment_parent > 0 ) {
			return; // Una RESPUESTA jamás cobra ni quema el event_key (2a).
		}

		$comment_id = (int) $comment->comment_ID;
		$product_id = (int) $comment->comment_post_ID;
		$requested = (string) get_comment_meta( $comment_id, self::META_POINTS_REQUESTED, true );
		if ( '' !== $requested && '1' !== $requested ) {
			return; // Publicar una reseña no inscribe al cliente en los premios.
		}

		if ( 'product' !== get_post_type( $product_id ) || 'publish' !== get_post_status( $product_id ) ) {
			return;
		}

		$seal = (string) get_comment_meta( $comment_id, self::META_POINTS_PAID, true );
		if ( '' !== $seal && self::SEAL_PENDING_USER !== $seal && self::SEAL_REVERSED !== $seal ) {
			return; // Ya resuelta (pagada o descartada con huella).
		}

		if ( '' !== (string) get_comment_meta( $comment_id, AdminReviews::META_ADMIN_CREATED, true ) ) {
			return; // Las reseñas escritas por la tienda no se premian a sí mismas.
		}

		if ( '' !== (string) get_comment_meta( $comment_id, 'cr_coupon_code', true ) ) {
			// Ya cobró el cupón del tercero: una recompensa por acción.
			update_comment_meta( $comment_id, self::META_POINTS_PAID, 0 );
			return;
		}

		if ( ! Media::has_real_photo( $comment_id ) ) {
			return; // Sin foto real no hay premio (video y texto no cuentan).
		}

		$user_id = self::resolve_user( $comment );
		if ( $user_id <= 0 ) {
			// Sin cuenta verificada todavía: el hook de verificación re-evalúa.
			update_comment_meta( $comment_id, self::META_POINTS_PAID, self::SEAL_PENDING_USER );
			return;
		}

		if ( ! self::verified_purchase( $comment, $user_id, $product_id ) ) {
			return; // Sin compra verificada no hay premio (interruptor).
		}

		// Una retirada puede haber encontrado ocupado el candado. Completar
		// esa limpieza antes de volver a contar la misma reseña restaurada.
		if ( self::SEAL_REVERSED === $seal && ! self::release_cadence_slot( $user_id, $comment_id ) ) {
			do_action( 'akb_rewards_review_pay_failed', $comment, $user_id, $points );
			return;
		}

		// Política de frecuencia: tope histórico por cliente y cadencia «cada N reseñas».
		// Ambas se validan contra el ledger. El tope cuenta productos con crédito
		// vigente (neto de reversas) para asegurar que reseñas revertidas liberen el cupo.
		$max = Options::review_max_per_user();
		if ( $max > 0 && Ledger::count_live_credits( $user_id, 'review', 'review', 'review_reversal' ) >= $max ) {
			update_comment_meta( $comment_id, self::META_POINTS_PAID, 0 );
			return; // El cliente ya alcanzó su tope histórico de premios.
		}

		$every = Options::review_every_n();
		if ( $every > 1 ) {
			$locked = Ledger::with_user_lock(
				$user_id,
				static function () use ( $user_id, $comment_id ): int {
					// Idempotencia por reseña: asegura que llamadas concurrentes
					// no consuman posiciones duplicadas en la cadencia.
					$pinned = (int) get_comment_meta( $comment_id, self::META_COUNTED, true );
					if ( $pinned > 0 ) {
						return $pinned;
					}

					$n = Ledger::raw_user_meta_int( $user_id, '_akb_rewards_review_qualifying' ) + 1;
					update_user_meta( $user_id, '_akb_rewards_review_qualifying', $n );
					update_comment_meta( $comment_id, self::META_COUNTED, $n );
					return $n;
				}
			);

			if ( null === $locked ) {
				// Candado ocupado: sin sellar y sin contar para reintentar en el próximo evento.
				do_action( 'akb_rewards_review_pay_failed', $comment, $user_id, $points );
				return;
			}

			$qualifying = (int) $locked;
			// Retirar una intermedia puede devolver el contador al mismo múltiplo
			// que ya pagó. Los créditos aún vigentes consumen esos ciclos: no
			// basta con volver a alcanzar N para pagar el mismo grupo dos veces.
			if ( 0 === $qualifying % $every && Ledger::count_live_credits( $user_id, 'review', 'review', 'review_reversal' ) >= intdiv( $qualifying, $every ) ) {
				update_comment_meta( $comment_id, self::META_POINTS_PAID, 0 );
				delete_comment_meta( $comment_id, self::META_PROGRESS );
				return;
			}
			if ( 0 !== $qualifying % $every ) {
				// Calificada pero aún no toca premio: sellada con 0 y registro de progreso.
				update_comment_meta( $comment_id, self::META_POINTS_PAID, 0 );
				update_comment_meta( $comment_id, self::META_PROGRESS, sprintf( '%d/%d', $qualifying % $every, $every ) );
				return;
			}
		}

		// Clave de evento por ocurrencia: si el cliente ya tuvo una reversa para este producto,
		// la clave incorpora el índice de reversa para garantizar unicidad e idempotencia en el ledger.
		$reversals = Ledger::count_movements_for_user( $user_id, 'review', $product_id, 'review_reversal' );
		$event_key = $reversals > 0
			? sprintf( 'review_u%d_p%d_r%d', $user_id, $product_id, $reversals )
			: sprintf( 'review_u%d_p%d', $user_id, $product_id );

		$moved_before = Ledger::count_movements_for_user( $user_id, 'review', $product_id, 'review' );

		$result = Ledger::credit(
			$user_id,
			$points,
			'review',
			'review',
			$product_id,
			sprintf(
				'Reseña con foto aprobada (#%d)%s',
				$comment_id,
				get_current_user_id() > 0 ? sprintf( ' por %s', wp_get_current_user()->user_login ) : ''
			),
			true,
			$event_key
		);

		if ( ! is_int( $result ) ) {
			// Infraestructura: huella + alarma; la próxima aprobación (o el
			// nacimiento re-procesado) reintenta.
			do_action( 'akb_rewards_review_pay_failed', $comment, $user_id, $points );
			return;
		}

		// El duplicado por cliente-producto vuelve como éxito sin movimiento
		// (saldo intacto): se sella con 0 y huella — re-reseñar no re-paga.
		// Y el hook de abajo solo se dispara si ESTE llamado movió saldo: un
		// sello perdido no puede duplicar el correo de aviso (2a).
		$moved_now = Ledger::count_movements_for_user( $user_id, 'review', $product_id, 'review' ) > $moved_before;
		update_comment_meta( $comment_id, self::META_POINTS_PAID, $moved_now ? $points : 0 );

		if ( ! $moved_now ) {
			return;
		}

		/**
		 * Reseña premiada con puntos. El correo de aviso escucha acá.
		 *
		 * @param \WP_Comment $comment Reseña.
		 * @param int         $user_id Cliente premiado.
		 * @param int         $points  Puntos acreditados.
		 */
		do_action( 'akb_rewards_review_points_paid', $comment, $user_id, $points );
	}

	/**
	 * Revierte los puntos de una reseña que YA COBRÓ y deja de estar aprobada
	 * (rechazo, papelera, spam) o se borra en firme. Mismo patrón que las
	 * demás reversas del programa —
	 * `Accrual::deduct_all_locked()` para pedidos anulados y
	 * `Referrals::maybe_reverse_referrer()` para el premio del padrino—:
	 * débito con clave de evento PROPIA e idempotente, topado al saldo
	 * disponible (`Ledger::debit()` con `$require_sufficient = false` nunca
	 * deja el saldo negativo), y si no se recuperó todo, la misma alarma que
	 * usan esas otras reversas.
	 *
	 * Sólo debita si el sello guarda puntos. Las reseñas intermedias de una
	 * cadencia también liberan su posición, aunque aún no hayan cobrado.
	 * Un segundo disparo no repite ni el débito ni el decremento del avance.
	 *
	 * La reversa descuenta del saldo sin enviar correo transaccional automático.
	 *
	 * @param \WP_Comment $comment Reseña que deja de estar aprobada, o se borra.
	 * @return void
	 */
	private function maybe_reverse( \WP_Comment $comment ): void {
		if ( 'review' !== (string) $comment->comment_type || (int) $comment->comment_parent > 0 ) {
			return;
		}

		$comment_id = (int) $comment->comment_ID;
		$points     = (int) get_comment_meta( $comment_id, self::META_POINTS_PAID, true );
		if ( $points <= 0 ) {
			// Una reseña intermedia no pagó, pero sí consumió una posición.
			// Su retirada también debe liberar ese avance. El sello permite
			// reintentar si el candado está ocupado, incluso al reaprobársela.
			if ( (int) get_comment_meta( $comment_id, self::META_COUNTED, true ) > 0 ) {
				$user_id = self::resolve_user( $comment );
				if ( $user_id > 0 ) {
					update_comment_meta( $comment_id, self::META_POINTS_PAID, self::SEAL_REVERSED );
					self::release_cadence_slot( $user_id, $comment_id );
				}
			}
			return;
		}

		$user_id = self::resolve_user( $comment );
		if ( $user_id <= 0 ) {
			return;
		}

		$product_id = (int) $comment->comment_post_ID;

		// Clave por OCURRENCIA, igual que Referrals::maybe_reverse_referrer:
		// si esta reseña ya se había revertido, se re-aprobó y volvió a
		// pagar, la SEGUNDA reversa necesita una clave distinta o el UNIQUE
		// la trataría como «ya aplicada» y no descontaría nada la segunda vez.
		$reversals = Ledger::count_movements_for_user( $user_id, 'review', $product_id, 'review_reversal' );

		$deducted = Ledger::debit(
			$user_id,
			$points,
			'review_reversal',
			'review',
			$product_id,
			'Reseña retirada',
			false,
			sprintf( 'review_reversal_u%d_p%d_r%d', $user_id, $product_id, $reversals )
		);

		if ( ! is_int( $deducted ) ) {
			// Candado ocupado: sin sellar, el próximo disparo reintenta con la
			// MISMA clave (no se insertó nada; `$reversals` no cambió).
			return;
		}

		// Sello REVERSIBLE: si el dueño la re-aprueba después, maybe_pay() la
		// vuelve a evaluar desde cero (mismo trato que pending_account).
		update_comment_meta( $comment_id, self::META_POINTS_PAID, self::SEAL_REVERSED );

		if ( $deducted < $points ) {
			// El cliente ya se había gastado los puntos: no hay saldo
			// negativo que cobrarle, y el dueño necesita verlo (mismo patrón
			// que Accrual::deduct_all_locked() y
			// Referrals::maybe_reverse_referrer()).
			do_action( 'akb_rewards_reversal_failed', $comment, $points - $deducted );
		}

		// Cadencia «cada N reseñas»: liberar la posición consumida bajo el candado
		// del usuario para que la siguiente reseña calificada pueda utilizarla.
		// Se eliminan META_COUNTED y META_PROGRESS para que, en caso de una posterior
		// re-aprobación, se calcule una posición limpia a partir del estado actual del contador.
		self::release_cadence_slot( $user_id, $comment_id );
	}

	/**
	 * Libera una posición una sola vez, conservando las marcas si el candado falla.
	 *
	 * @param int $user_id Cliente.
	 * @param int $comment_id Reseña retirada.
	 * @return bool Si la limpieza terminó o no quedaba posición que liberar.
	 */
	private static function release_cadence_slot( int $user_id, int $comment_id ): bool {
		if ( (int) get_comment_meta( $comment_id, self::META_COUNTED, true ) <= 0 ) {
			return true;
		}
		return true === Ledger::with_user_lock(
			$user_id,
			static function () use ( $user_id, $comment_id ): bool {
				if ( (int) get_comment_meta( $comment_id, self::META_COUNTED, true ) <= 0 ) {
					return true;
				}
				$current = Ledger::raw_user_meta_int( $user_id, '_akb_rewards_review_qualifying' );
				update_user_meta( $user_id, '_akb_rewards_review_qualifying', max( 0, $current - 1 ) );
				delete_comment_meta( $comment_id, self::META_COUNTED );
				delete_comment_meta( $comment_id, self::META_PROGRESS );
				return true;
			}
		);
	}

	/**
	 * Compra verificada, con el cálculo perezoso clonado de CusRev: si la
	 * meta `verified` falta, se computa con wc_customer_bought_product y se
	 * PERSISTE (mismo efecto de escritura que el tercero).
	 *
	 * @param \WP_Comment $comment    Reseña.
	 * @param int         $user_id    Cliente resuelto.
	 * @param int         $product_id Producto.
	 * @return bool
	 */
	private static function verified_purchase( \WP_Comment $comment, int $user_id, int $product_id ): bool {
		if ( 'yes' !== (string) get_option( 'akb_rewards_review_require_verified', 'yes' ) ) {
			return true;
		}

		// El formulario conoce el pedido exacto. Su prueba incluye el estado
		// propio Listo para enviar y se revalida aunque Woo dejara verified=0,
		// o aunque un verified=1 anterior preceda a una anulación/devolución.
		$linked = VerifiedPurchase::for_review( $comment, $user_id );
		if ( null !== $linked ) {
			update_comment_meta( (int) $comment->comment_ID, 'verified', (int) $linked );
			return $linked;
		}

		$verified = (string) get_comment_meta( (int) $comment->comment_ID, 'verified', true );

		if ( '' === $verified && function_exists( 'wc_customer_bought_product' ) ) {
			$bought = wc_customer_bought_product( (string) $comment->comment_author_email, $user_id, $product_id );
			add_comment_meta( (int) $comment->comment_ID, 'verified', (int) $bought, true );
			// Usa el valor persistido, incluso si otro proceso ganó la inserción única.
			$verified = (string) get_comment_meta( (int) $comment->comment_ID, 'verified', true );
		}

		return '1' === $verified;
	}

	/**
	 * Resuelve el usuario dueño de la reseña: user_id directo, o el email del
	 * autor contra la tabla de usuarios (los invitados de CusRev quedan con
	 * user_id 0 — ingeniería inversa). Resolver por email exige que Woo
	 * confirme su verificación: crear una cuenta ajena no prueba identidad.
	 *
	 * @param \WP_Comment $comment Reseña.
	 * @return int 0 si no hay cuenta.
	 */
	private static function resolve_user( \WP_Comment $comment ): int {
		if ( (int) $comment->user_id > 0 ) {
			return (int) $comment->user_id;
		}

		$email = (string) $comment->comment_author_email;
		if ( '' === $email ) {
			return 0;
		}

		$user = get_user_by( 'email', $email );

		if ( ! $user instanceof \WP_User || strtolower( (string) $user->user_email ) !== strtolower( $email ) || ! function_exists( 'wc_get_container' ) ) {
			return 0;
		}
		$verification = wc_get_container()->get( \Automattic\WooCommerce\Internal\CustomerEmailVerification\EmailVerificationService::class );
		return $verification->is_verified( (int) $user->ID ) ? (int) $user->ID : 0;
	}
}


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/src/Reviews/RewardMessage.php
================================================================

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


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/src/Reviews/Schema.php
================================================================

<?php
/**
 * Schema — el JSON-LD de reseñas con la forma EXACTA del plugin de terceros
 * (orden explícita del dueño), entregado DENTRO del Product de Rank Math.
 *
 * Ingeniería inversa de CR_StructuredData (04-cusrev-ingenieria-inversa.md):
 *   - aggregateRating con EXACTAMENTE 3 claves: @type, ratingValue (el string
 *     crudo de 2 decimales de WooCommerce, sin redondear) y reviewCount. SIN
 *     bestRating/worstRating/ratingCount a ese nivel.
 *   - review[] = las 5 MÁS RECIENTES con rating > 0; cada nodo con 5 claves
 *     en este orden: @type, reviewRating {@type Rating, bestRating '5',
 *     ratingValue crudo, worstRating '1'}, author {Person, name}, reviewBody
 *     (comment_content SIN filtrar: crudo, sin truncar y sin HTML) y
 *     datePublished (ISO-8601 local, formato 'c').
 *   - Gate: get_rating_count() > 0 — sin ratings, ni una clave se toca.
 *   - El associatedMedia de CusRev es CÓDIGO MUERTO en esta instalación (el
 *     filtro de Woo que lo emitiría no se dispara jamás): el schema real
 *     servido no lo lleva, así que replicarlo sería inventar.
 *
 * Por qué Rank Math y no wp_footer: CusRev emitiría un SEGUNDO nodo Product
 * suelto junto al @graph de Rank Math (entidad duplicada a la primera
 * reseña). Acá las mismas claves entran a la entidad Product,Book existente
 * vía `rank_math/snippet/rich_snippet_product_entity`, reemplazando la forma
 * propia de Rank Math (aggregateRating de 4 claves + review[] SIN límite),
 * que es distinta de la del plugin de terceros.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Reviews;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.2.0
 */
final class Schema {

	/**
	 * Prioridad tardía: los enriquecedores de akibara-business (Book, GTIN)
	 * corren antes y esto solo pisa las claves de reseñas.
	 */
	private const PRIORITY = 50;

	/**
	 * Cuelga el filtro de Rank Math.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'rank_math/snippet/rich_snippet_product_entity', array( $this, 'inject' ), self::PRIORITY );
	}

	/**
	 * Inyecta aggregateRating y review[] con la forma CusRev en la entidad.
	 *
	 * @param array<string,mixed> $entity Entidad Product de Rank Math.
	 * @return array<string,mixed>
	 */
	public function inject( $entity ) {
		if ( ! is_array( $entity ) ) {
			return $entity;
		}

		$product = function_exists( 'wc_get_product' ) ? wc_get_product() : false;
		if ( ! $product instanceof \WC_Product ) {
			return $entity;
		}

		// El gate del tercero: sin ratings no se toca nada (y se retira lo
		// que Rank Math hubiera puesto por su cuenta, para no publicar una
		// forma ajena a la del plugin que replicamos).
		if ( (int) $product->get_rating_count() <= 0 ) {
			unset( $entity['aggregateRating'], $entity['review'] );
			return $entity;
		}

		$entity['aggregateRating'] = array(
			'@type'       => 'AggregateRating',
			'ratingValue' => $product->get_average_rating(),
			'reviewCount' => $product->get_review_count(),
		);

		$reviews = self::latest_reviews( (int) $product->get_id() );
		if ( array() !== $reviews ) {
			$entity['review'] = $reviews;
		} else {
			unset( $entity['review'] );
		}

		return $entity;
	}

	/**
	 * Las 5 reseñas más recientes con rating > 0, con los nodos calcados.
	 *
	 * Query clonada del tercero: number 5, status approve, post publicado,
	 * top-level, meta rating NUMERIC > 0, sin orderby explícito (WordPress
	 * cae a comment_date_gmt DESC).
	 *
	 * El interruptor de exclusión omite de `review[]` las reseñas que cobraron
	 * puntos o contaron para un premio. `aggregateRating` conserva la nota y
	 * el total visibles en la ficha. Disclosure resuelve el aviso de incentivo;
	 * su visualización tiene un interruptor independiente.
	 *
	 * @param int $product_id Producto.
	 * @return array<int,array<string,mixed>>
	 */
	private static function latest_reviews( int $product_id ): array {
		$meta = array(
			array(
				'key'     => 'rating',
				'type'    => 'NUMERIC',
				'compare' => '>',
				'value'   => 0,
			),
		);

		if ( self::excludes_rewarded() ) {
			// `pending_account` (el sello de quien reseñó sin cuenta y aún no
			// cobró) castea a 0: todavía no es una reseña premiada. Mismo
			// criterio que Disclosure::notice_for().
			foreach ( array( ReviewPoints::META_POINTS_PAID, ReviewPoints::META_COUNTED ) as $clave ) {
				$meta[] = array(
					'relation' => 'OR',
					array(
						'key'     => $clave,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => $clave,
						'type'    => 'NUMERIC',
						'compare' => '<=',
						'value'   => 0,
					),
				);
			}
		}

		$comments = get_comments(
			array(
				'number'      => 5,
				'post_id'     => $product_id,
				'status'      => 'approve',
				'post_status' => 'publish',
				'post_type'   => 'product',
				'parent'      => 0,
				'meta_query'  => $meta, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- forma clonada del tercero.
			)
		);

		$nodes = array();
		foreach ( (array) $comments as $comment ) {
			if ( ! $comment instanceof \WP_Comment ) {
				continue;
			}

			// reviewBody sale del comment_content CRUDO: get_comment_text()
			// pasa por el filtro `comment_text` (wpautop + make_clickable) y
			// metía <p> y <br /> dentro del JSON-LD, contra lo que el docblock
			// de este módulo promete.
			$nodes[] = array(
				'@type'        => 'Review',
				'reviewRating' => array(
					'@type'       => 'Rating',
					'bestRating'  => '5',
					'ratingValue' => get_comment_meta( (int) $comment->comment_ID, 'rating', true ),
					'worstRating' => '1',
				),
				'author'       => array(
					'@type' => 'Person',
					'name'  => get_comment_author( $comment ),
				),
				// Incluye en el cuerpo el aviso de transcripción de AdminReviews.
				'reviewBody'   => trim(
					(string) $comment->comment_content
					. ' ' . AdminReviews::transcription_notice( (int) $comment->comment_ID )
				),
				'datePublished' => get_comment_date( 'c', $comment ),
			);
		}

		return $nodes;
	}

	/**
	 * ¿El dueño pidió dejar las reseñas premiadas fuera de los datos
	 * estructurados?
	 *
	 * @return bool
	 */
	private static function excludes_rewarded(): bool {
		return 'yes' === (string) get_option( 'akb_rewards_schema_exclude_rewarded', 'no' );
	}
}


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/src/Reviews/TokenCleanup.php
================================================================

<?php
/**
 * TokenCleanup — limpieza diaria de los enlaces de reseña caducados.
 *
 * La acción tiene agenda propia y se registra aunque estén apagados el motor
 * de reseñas o el vencimiento de puntos. Los enlaces existentes deben seguir
 * caducando sin depender de esos interruptores comerciales.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Reviews;

use Akibara\Rewards\Support\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.4.9
 */
final class TokenCleanup {

	public const HOOK = 'akb_rewards_review_tokens_cleanup';

	/**
	 * Memo del estado que quedó en la cola, para no consultar Action
	 * Scheduler en cada petición de admin.
	 */
	private const SCHEDULED_MEMO = 'akb_rewards_tokens_cleanup_scheduled';

	/**
	 * Cuelga el oyente y la agenda.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( 'init', array( $this, 'ensure_scheduled' ), 20 );
	}

	/**
	 * Borra los tokens caducados y nunca usados.
	 *
	 * @return void
	 */
	public function run(): void {
		Schema::purge_stale_review_tokens();
	}

	/**
	 * Agenda diaria, memoizada y sólo en admin/cron/CLI — el mismo patrón que
	 * `Points\Expiry`, para que el sitio del cliente no pague por ella.
	 *
	 * @return void
	 */
	public function ensure_scheduled(): void {
		if ( ! is_admin() && ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		if ( ! function_exists( 'as_next_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}
		if ( 'yes' === get_transient( self::SCHEDULED_MEMO ) ) {
			return;
		}

		if ( false === as_next_scheduled_action( self::HOOK ) ) {
			$action_id = as_schedule_recurring_action( time() + 3 * HOUR_IN_SECONDS, DAY_IN_SECONDS, self::HOOK );
			if ( ! is_int( $action_id ) || 0 >= $action_id ) {
				return; // Sin memo: se reintenta en la siguiente petición.
			}
		}

		set_transient( self::SCHEDULED_MEMO, 'yes', 12 * HOUR_IN_SECONDS );
	}
}


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/src/Reviews/VerifiedPurchase.php
================================================================

<?php
/**
 * Prueba de compra del pedido que emitió el enlace personal de reseña.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Reviews;

use Akibara\Rewards\Support\OrderStates;

defined( 'ABSPATH' ) || exit;

final class VerifiedPurchase {

	/**
	 * Revalida la compra de una reseña creada desde el enlace de un pedido.
	 *
	 * @param \WP_Comment $comment Reseña.
	 * @param int         $user_id Cuenta que recibiría los puntos.
	 * @return bool|null Null si no procede del formulario propio.
	 */
	public static function for_review( \WP_Comment $comment, int $user_id ): ?bool {
		$order_id = (int) get_comment_meta( (int) $comment->comment_ID, ReviewForm::META_ORDER, true );
		if ( $order_id <= 0 ) {
			return null;
		}
		$order = wc_get_order( $order_id );
		return $order instanceof \WC_Order && self::order_confirms( $order, (int) $comment->comment_post_ID, (string) $comment->comment_author_email, $user_id );
	}

	/**
	 * Comprueba identidad, pago y una unidad conservada del producto.
	 *
	 * WooCommerce no incluye los estados propios de entrega en su consulta.
	 * Sólo sirven como prueba con fecha de pago y una ruta autorizada.
	 *
	 * @param \WC_Order $order      Pedido enlazado por el token.
	 * @param int       $product_id Producto reseñado.
	 * @param string    $email      Correo del autor.
	 * @param int       $user_id    Cuenta del autor, o 0 para un invitado.
	 * @return bool
	 */
	public static function order_confirms( \WC_Order $order, int $product_id, string $email, int $user_id ): bool {
		$status = (string) $order->get_status();
		if ( $product_id <= 0 || OrderStates::is_down( $status ) || in_array( $status, array( 'pending', 'on-hold', 'checkout-draft' ), true ) ) {
			return false;
		}
		if ( ! in_array( $status, array( 'processing', 'completed' ), true ) ) {
			$authorized = in_array( $status, OrderStates::PAID, true ) || Invitations::is_trigger( $status ) || $status === (string) $order->get_meta( Invitations::META_TRIGGER );
			if ( ! $authorized || ! $order->get_date_paid() ) {
				return false;
			}
		}
		$billing_email = strtolower( trim( (string) $order->get_billing_email() ) );
		if ( '' === $billing_email || $billing_email !== strtolower( trim( $email ) ) ) {
			return false;
		}
		$customer_id = (int) $order->get_customer_id();
		if ( $customer_id > 0 && $customer_id !== $user_id ) {
			return false;
		}
		if ( 0 === $customer_id && $user_id > 0 ) {
			$user = get_user_by( 'id', $user_id );
			if ( ! $user instanceof \WP_User || strtolower( trim( (string) $user->user_email ) ) !== $billing_email ) {
				return false;
			}
		}
		$total = (float) $order->get_total();
		if ( $total > 0 && (float) $order->get_total_refunded() >= $total ) {
			return false;
		}
		foreach ( $order->get_items() as $item_id => $item ) {
			if ( (int) $item->get_product_id() !== $product_id ) {
				continue;
			}
			$quantity = (float) $item->get_quantity();
			if ( $quantity > 0 && abs( (float) $order->get_qty_refunded_for_item( (int) $item_id ) ) < $quantity ) {
				return true;
			}
		}
		return false;
	}
}


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/src/Reviews/VoluntaryReviewForm.php
================================================================

<?php
/**
 * Foto opcional para la reseña voluntaria nativa de WooCommerce.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Reviews;

defined( 'ABSPATH' ) || exit;

/** Mantiene la reseña de la ficha voluntaria, verificada y pendiente. */
final class VoluntaryReviewForm {

	private const PHOTO_FIELD = 'akb_vr_photo';
	private const NONCE_FIELD = '_akb_vr_nonce';
	private const MAX_UPLOAD  = 5242880;
	/** @var int Foto recién subida que se elimina si Core no crea la reseña. */
	private int $attachment_id = 0;
	/** @var int Producto del envío cuya foto espera insertarse. */
	private int $product_id = 0;
	/** @var int Nivel del buffer propio para no cerrar un buffer ajeno. */
	private int $buffer_level = 0;

	/** Conecta la presentación y la validación antes de insertar o premiar. */
	public function register(): void {
		add_filter( 'woocommerce_product_review_comment_form_args', array( $this, 'handle_form_args' ), 20 );
		add_action( 'comment_form_before', array( $this, 'handle_form_before' ), 0 );
		add_action( 'comment_form_after', array( $this, 'handle_form_after' ), PHP_INT_MAX );
		add_filter( 'preprocess_comment', array( $this, 'handle_preprocess_comment' ), 20 );
		add_filter( 'pre_comment_approved', array( $this, 'handle_pre_comment_approved' ), PHP_INT_MAX, 2 );
		add_action( 'wp_insert_comment', array( $this, 'handle_inserted_comment' ), 1, 2 );
		add_action( 'shutdown', array( $this, 'discard_orphan_photo' ) );
	}

	/**
	 * Añade foto y nonce al formulario real de Woo sin JavaScript.
	 *
	 * @param array<string,mixed> $args Argumentos del formulario.
	 * @return array<string,mixed>
	 */
	public function handle_form_args( array $args ): array {
		$product_id = (int) get_the_ID();
		if ( get_current_user_id() <= 0 || 'product' !== get_post_type( $product_id ) ) {
			return $args;
		}
		$args['comment_field'] = (string) ( $args['comment_field'] ?? '' )
			. wp_nonce_field( 'akb_voluntary_review_' . $product_id, self::NONCE_FIELD, true, false )
			. '<input type="hidden" name="akb_rf_points_requested" value="0">'
			. '<p class="comment-form-photo"><label for="akb_vr_photo">Foto de tu compra (opcional)</label><input type="file" id="akb_vr_photo" name="akb_vr_photo" accept="image/jpeg,image/png,image/webp,image/heic" aria-describedby="akb-vr-photo-help"><span id="akb-vr-photo-help">Máximo 5 MB; JPG, PNG, WebP o HEIC. Adjuntar una foto no solicita puntos.</span></p>';
		return $args;
	}

	/** Captura sólo el formulario del producto para añadir el enctype que Core no expone. */
	public function handle_form_before(): void {
		if ( ! is_product() || get_current_user_id() <= 0 || $this->buffer_level > 0 ) {
			return;
		}
		ob_start();
		$this->buffer_level = ob_get_level();
	}

	/** Devuelve el formulario nativo con una codificación multipart real. */
	public function handle_form_after(): void {
		if ( 0 === $this->buffer_level || ob_get_level() !== $this->buffer_level ) {
			return;
		}
		$html               = (string) ob_get_clean();
		$this->buffer_level = 0;
		$html               = (string) preg_replace( '/(<form\b[^>]*)(>)/i', '$1 enctype="multipart/form-data"$2', $html, 1 );
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML generado y escapado por comment_form().
	}

	/**
	 * Valida compra, nonce y foto; toda reseña nativa es voluntaria.
	 *
	 * @param array<string,mixed> $data Datos sin insertar.
	 * @return array<string,mixed>
	 */
	public function handle_preprocess_comment( array $data ): array {
		$path = (string) wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ), PHP_URL_PATH );
		if ( 1 !== preg_match( '#(?:^|/)wp-comments-post\.php$#', $path ) ) {
			return $data;
		}
		$product_id = (int) ( $data['comment_post_ID'] ?? 0 );
		if ( 'product' !== get_post_type( $product_id ) || (int) ( $data['comment_parent'] ?? 0 ) > 0 ) {
			return $data;
		}
		$user_id = get_current_user_id();
		$user    = $user_id > 0 ? get_user_by( 'id', $user_id ) : false;
		if ( ! $user instanceof \WP_User || (int) ( $data['user_id'] ?? $data['user_ID'] ?? 0 ) !== $user_id ) {
			wp_die(
				'Inicia sesión con la cuenta de tu compra para enviar esta reseña. Si compraste como invitado, usa el enlace de reseña de tu pedido.',
				'',
				array(
					'response'  => 403,
					'back_link' => true,
				)
			);
		}
		$nonce = isset( $_POST[ self::NONCE_FIELD ] ) && is_string( $_POST[ self::NONCE_FIELD ] ) ? sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- se verifica justo debajo.
		if ( ! wp_verify_nonce( $nonce, 'akb_voluntary_review_' . $product_id ) ) {
			wp_die(
				'La sesión del formulario caducó. Vuelve a la ficha y reinténtalo.',
				'',
				array(
					'response'  => 403,
					'back_link' => true,
				)
			);
		}
		$order_id = self::verified_order_id( $product_id, $user_id, (string) $user->user_email );
		if ( 0 === $order_id ) {
			wp_die(
				'Solo puedes reseñar un producto de una compra verificada que no hayas devuelto.',
				'',
				array(
					'response'  => 403,
					'back_link' => true,
				)
			);
		}

		$file = $_FILES[ self::PHOTO_FIELD ] ?? array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- estructura validada por upload_photo(), después del nonce.
		if ( ! is_array( $file ) || ( isset( $file['error'] ) && UPLOAD_ERR_NO_FILE !== $file['error'] ) || ! empty( $file['name'] ) ) {
			$upload = self::upload_photo( self::PHOTO_FIELD );
			if ( is_wp_error( $upload ) ) {
				wp_die(
					esc_html( 'La foto no se pudo subir: ' . $upload->get_error_message() ),
					'',
					array(
						'response'  => 400,
						'back_link' => true,
					)
				);
			}
			$this->attachment_id = $upload;
			$this->product_id    = $product_id;
		}
		$data['comment_type']                                        = 'review';
		$data['comment_meta']                                        = is_array( $data['comment_meta'] ?? null ) ? $data['comment_meta'] : array();
		$data['comment_meta'][ ReviewPoints::META_POINTS_REQUESTED ] = 0;
		$data['comment_meta'][ ReviewForm::META_ORDER ]              = $order_id;
		$data['comment_meta']['verified']                            = 1;
		return $data;
	}

	/**
	 * Respeta el interruptor nativo y conserva spam, papelera y errores del núcleo.
	 *
	 * @param int|string|\WP_Error $approved Veredicto previo.
	 * @param array<string,mixed>  $data Datos de reseña.
	 * @return int|string|\WP_Error
	 */
	public function handle_pre_comment_approved( $approved, array $data ) {
		if ( 'yes' !== (string) get_option( 'akb_rewards_moderate_reviews', 'yes' ) ) {
			return $approved;
		}
		if ( isset( $data['comment_meta'][ ReviewPoints::META_POINTS_REQUESTED ] )
			&& 0 === $data['comment_meta'][ ReviewPoints::META_POINTS_REQUESTED ]
			&& 'product' === get_post_type( (int) ( $data['comment_post_ID'] ?? 0 ) )
			&& ! is_wp_error( $approved ) && ! in_array( $approved, array( 'spam', 'trash' ), true ) ) {
			return 0;
		}
		return $approved;
	}

	/**
	 * Enlaza la foto antes del motor; Core ya guardó la meta explícita.
	 *
	 * @param int         $comment_id Reseña creada.
	 * @param \WP_Comment $comment Reseña creada por Core.
	 */
	public function handle_inserted_comment( int $comment_id, \WP_Comment $comment ): void {
		if ( $this->attachment_id <= 0 || (int) $comment->comment_post_ID !== $this->product_id
			|| '0' !== (string) get_comment_meta( $comment_id, ReviewPoints::META_POINTS_REQUESTED, true ) ) {
			return;
		}
		if ( ! Media::attach_photo( $comment_id, $this->attachment_id, $this->product_id ) ) {
			$this->discard_orphan_photo();
		}
		$this->attachment_id = 0;
		$this->product_id    = 0;
	}

	/** Limpia la subida si WordPress rechazó la reseña por duplicado, flood o INSERT fallido. */
	public function discard_orphan_photo(): void {
		if ( $this->attachment_id > 0 ) {
			wp_delete_attachment( $this->attachment_id, true );
			$this->attachment_id = 0;
			$this->product_id    = 0;
		}
	}

	/**
	 * Busca compras de la cuenta y del mismo correo en páginas de 50 pedidos.
	 *
	 * @param int    $product_id Producto reseñado.
	 * @param int    $user_id Cuenta del comprador.
	 * @param string $email Correo de la cuenta.
	 * @return int Pedido que prueba una unidad conservada, o cero.
	 */
	private static function verified_order_id( int $product_id, int $user_id, string $email ): int {
		foreach ( array( array( 'customer_id' => $user_id ), array( 'billing_email' => $email ) ) as $identity ) {
			$page = 1;
			do {
				$orders = wc_get_orders(
					$identity + array(
						'limit'   => 50,
						'page'    => $page,
						'orderby' => 'ID',
						'order'   => 'DESC',
						'return'  => 'objects',
					)
				);
				foreach ( $orders as $order ) {
					if ( $order instanceof \WC_Order && VerifiedPurchase::order_confirms( $order, $product_id, $email, $user_id ) ) {
						return (int) $order->get_id();
					}
				}
				$count = count( $orders );
				++$page;
			} while ( 50 === $count );
		}
		return 0;
	}

	/**
	 * Valida error, tamaño y MIME real antes de subir mediante Core.
	 *
	 * @param string $field Campo de archivo del formulario con nonce validado.
	 * @return int|\WP_Error Adjunto creado o error de validación/subida.
	 */
	public static function upload_photo( string $field ) {
		$file = $_FILES[ $field ] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- se validan cada tipo, error, tamaño y MIME antes de usar la estructura.
		if ( ! is_array( $file ) || ! is_string( $file['name'] ?? null ) || ! is_string( $file['tmp_name'] ?? null )
			|| ! is_scalar( $file['size'] ?? null ) || ! is_scalar( $file['error'] ?? null )
			|| UPLOAD_ERR_OK !== (int) $file['error'] || '' === $file['name'] || '' === $file['tmp_name'] ) {
			return new \WP_Error( 'akb_review_upload', 'elige una imagen válida y vuelve a adjuntarla.' );
		}
		if ( (int) $file['size'] > self::MAX_UPLOAD || (int) $file['size'] <= 0 ) {
			return new \WP_Error( 'akb_review_size', 'la imagen debe pesar más de 0 bytes y un máximo de 5 MB.' );
		}
		if ( ! in_array( wp_get_image_mime( $file['tmp_name'] ), array( 'image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif' ), true ) ) {
			return new \WP_Error( 'akb_review_type', 'usa una foto JPG, PNG, WebP o HEIC.' );
		}
		if ( ! function_exists( 'media_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		return media_handle_upload(
			$field,
			0,
			array(),
			array(
				'test_form' => false,
				'mimes'     => array(
					'jpg|jpeg|jpe' => 'image/jpeg',
					'png'          => 'image/png',
					'webp'         => 'image/webp',
					'heic'         => 'image/heic',
				),
			)
		);
	}
}


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/src/Support/Alerts.php
================================================================

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


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/src/Support/Assets.php
================================================================

<?php
/**
 * Assets — el `?ver=` de los assets del plugin, con un solo criterio.
 *
 * El token combina versión y filemtime para invalidar assets modificados.
 * Si el fichero no existe, conserva la versión sin consultar su mtime.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Support;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.3.1
 */
final class Assets {

	/**
	 * URL pública de un asset del plugin.
	 *
	 * @param string $relative Ruta relativa a la raíz del plugin.
	 * @return string
	 */
	public static function url( string $relative ): string {
		return AKIBARA_REWARDS_URL . $relative;
	}

	/**
	 * Token de caché: versión del plugin + mtime del fichero.
	 *
	 * @param string $relative Ruta relativa a la raíz del plugin.
	 * @return string
	 */
	public static function version( string $relative ): string {
		$path  = AKIBARA_REWARDS_DIR . $relative;
		$mtime = file_exists( $path ) ? (int) filemtime( $path ) : 0;

		return AKIBARA_REWARDS_VERSION . ( $mtime > 0 ? '.' . $mtime : '' );
	}
}


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/src/Support/Audit.php
================================================================

<?php
/**
 * Audit — reconciliación saldo-cacheado vs ledger.
 *
 * El saldo vive cacheado en usermeta y el ledger es la verdad contable.
 * Esta auditoría reconcilia desviaciones eventuales garantizando consistencia.
 *
 * La reparación NO escribe asientos: el ledger ya es correcto (el INSERT
 * ocurrió); lo que quedó viejo es la caché. Reparar = re-derivar la meta de
 * la suma, bajo el candado del usuario. Asentar «la diferencia» — lo que
 * hace el set_points_balance del oficial — movería la verdad para igualar
 * la caché: la dirección equivocada.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Support;

use Akibara\Rewards\Points\Ledger;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.3.0
 */
final class Audit {

	/**
	 * Usuarios cuya meta de saldo no cuadra con la suma del ledger.
	 *
	 * @return array<int,array{user_id:int,meta:int,ledger:int}>
	 */
	public static function mismatches(): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT l.user_id, COALESCE(m.meta_value, 0) + 0 AS meta, COALESCE(SUM(l.delta), 0) AS ledger'
				. ' FROM ' . Schema::ledger_table() . ' l' // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- tabla propia.
				. " LEFT JOIN {$wpdb->usermeta} m ON m.user_id = l.user_id AND m.meta_key = %s"
				// GREATEST(0, ...): la meta no baja de 0; ledgers con saldo negativo se reportan por separado.
				. ' GROUP BY l.user_id, m.meta_value HAVING meta <> GREATEST(0, ledger)',
				Ledger::META_BALANCE
			),
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'user_id' => (int) $row['user_id'],
				'meta'    => (int) $row['meta'],
				'ledger'  => (int) $row['ledger'],
			);
		}

		return $out;
	}

	/**
	 * Repara la meta de UN usuario re-derivándola del ledger, bajo candado y
	 * con la suma releída dentro (la foto de fuera puede ser vieja).
	 *
	 * @param int $user_id Usuario.
	 * @return int|null Saldo reparado, o null sin candado.
	 */
	/**
	 * Usuarios cuyo ledger suma valor negativo. Se reporta para inspección contable independiente.
	 *
	 * @return array<int, array{user_id: int, ledger: int}>
	 */
	public static function negatives(): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			'SELECT user_id, COALESCE(SUM(delta), 0) AS ledger FROM ' . Schema::ledger_table() // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- tabla propia, sin entrada de usuario.
			. ' GROUP BY user_id HAVING ledger < 0',
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'user_id' => (int) $row['user_id'],
				'ledger'  => (int) $row['ledger'],
			);
		}

		return $out;
	}

	public static function repair( int $user_id ) {
		return Ledger::with_user_lock(
			$user_id,
			static function () use ( $user_id ): int {
				$truth = max( 0, Ledger::balance_from_ledger( $user_id ) );
				update_user_meta( $user_id, Ledger::META_BALANCE, $truth );
				return $truth;
			}
		);
	}

	/**
	 * Comando `wp akb-rewards audit [--fix]`.
	 *
	 * @return void
	 */
	public static function register_cli(): void {
		if ( ! class_exists( 'WP_CLI' ) ) {
			return;
		}

		\WP_CLI::add_command(
			'akb-rewards audit',
			static function ( $args, $assoc ): void {
				unset( $args );
				foreach ( self::negatives() as $row ) {
					\WP_CLI::log( sprintf(
						'user %d: el ledger suma %d (negativo). No es una desviación: el saldo visible es 0. Le falta el movimiento de arranque de la migración; se corrige con un ajuste manual, no con --fix.',
						$row['user_id'],
						$row['ledger']
					) );
				}
				$bad = self::mismatches();

				if ( array() === $bad ) {
					\WP_CLI::success( 'Ledger y saldos cuadran: 0 desviaciones.' );
					return;
				}

				foreach ( $bad as $row ) {
					\WP_CLI::log( sprintf(
						'user %d: meta=%d ledger=%d (dif %+d)',
						$row['user_id'],
						$row['meta'],
						$row['ledger'],
						$row['ledger'] - $row['meta']
					) );

					if ( isset( $assoc['fix'] ) ) {
						$fixed = self::repair( $row['user_id'] );
						\WP_CLI::log( null === $fixed
							? sprintf( '  → SIN candado para user %d; reintentar.', $row['user_id'] )
							: sprintf( '  → meta reparada a %d (derivada del ledger).', $fixed ) );
					}
				}

				if ( ! isset( $assoc['fix'] ) ) {
					\WP_CLI::warning( sprintf( '%d desviaciones. Repite con --fix para reparar las metas desde el ledger.', count( $bad ) ) );
				}
			}
		);
	}
}


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/src/Support/Log.php
================================================================

<?php
/**
 * Log — la bitácora del programa, en el sitio donde el dueño puede leerla.
 *
 * Los siete eventos críticos del carril de DINERO (candado no disponible,
 * INSERT del ledger caído, configuración de vigencia rota, invitación no
 * emitida, migración de esquema fallida) iban a `error_log()`: un fichero que
 * hay que abrir por SSH, que rota sin avisar y que mezcla el ruido de todos
 * los plugins. WooCommerce ya trae la bitácora con pantalla propia
 * (WooCommerce → Estado → Registros), retención y filtro por origen, y es el
 * estándar del plugin hermano.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Support;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.3.1
 */
final class Log {

	/**
	 * Origen del registro: el desplegable de la pantalla de Woo filtra por él.
	 */
	public const SOURCE = 'akibara-rewards';

	/**
	 * Registra un evento crítico de dinero.
	 *
	 * @param string $message Mensaje ya formateado.
	 * @return void
	 */
	public static function error( string $message ): void {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->error( $message, array( 'source' => self::SOURCE ) );
			return;
		}

		// Sin el logger de Woo (activación a medias, arranque roto) el evento
		// NO puede quedar mudo: es la única traza de un movimiento de puntos
		// rechazado.
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- último recurso; ver arriba.
		error_log( '[' . self::SOURCE . '] ' . $message );
	}
}


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/src/Support/Options.php
================================================================

<?php
/**
 * Options — lectura tipada de la configuración del programa.
 *
 * Los valores por defecto replican las reglas base del programa de recompensas:
 * 2 puntos por cada $100 sobre subtotal tras descuentos con IVA, sin incluir envío;
 * canje 1 punto = $1 CLP; bono de bienvenida de 500 puntos; estado de acreditación
 * «Procesando» (ver `award_status()`).
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Support;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.1.0
 */
final class Options {

	/**
	 * Tope por defecto del canje, en porcentaje del pedido. Es SOLO el valor
	 * inicial del campo del panel: el dueño lo cambia en WooCommerce → Puntos
	 * y Reseñas sin tocar código (regla de la casa).
	 */
	public const DEFAULT_REDEEM_MAX_PCT = 50;

	/**
	 * Tope por defecto del canje en pesos por pedido. Igual que el porcentual:
	 * es sólo el valor inicial del campo; el dueño manda desde el panel.
	 * El mínimo de carrito se configura por separado mediante redeem_min_cart().
	 */
	public const DEFAULT_REDEEM_MAX_AMOUNT = 5000;

	/**
	 * Estado de pedido que acredita, de fábrica. Es sólo el valor inicial del
	 * selector del panel; el dueño manda desde WooCommerce → Puntos y Reseñas.
	 *
	 * «Procesando» es, en Akibara, el estado al que llega el pedido cuando la
	 * pasarela confirma el pago.
	 */
	public const DEFAULT_AWARD_STATUS = 'processing';

	/**
	 * Días de carencia por defecto antes de liberar los puntos de compra a saldo disponible.
	 */
	public const DEFAULT_ESCROW_DAYS = 7;

	/**
	 * Techo de fábrica de la RAZÓN del programa: cuántos pesos puede devolver
	 * la tienda por cada 100 gastados, contando las tres patas a la vez
	 * (puntos por tramo ÷ tamaño del tramo × pesos por punto). Hoy la razón
	 * vigente es 2 % (2 puntos por cada $100, a $1 el punto), así que 15 es
	 * 7,5 veces lo que la tienda regala: es el umbral del ABSURDO, no el
	 * óptimo.
	 *
	 * El número es el MISMO 15 del techo de descuento de la tienda
	 * (Akibara\Business\Utilities\DiscountCeiling::DEFAULT_PCT) porque un
	 * punto regalado es margen que se va igual que un descuento. Y como allá,
	 * es un AJUSTE del panel («Techo de devolución del programa»), no un muro
	 * en el código: la regla de la casa es que todo se opere sin programar.
	 * Lo que sí vive en código es HARD_MAX_GIVEBACK_PCT, el límite del campo.
	 *
	 * DECISIÓN EXPLÍCITA: el 15 se COPIA, no se enlaza con
	 * `akb_discount_max_pct`. Si esto lo leyera en vivo, una campaña que suba
	 * el techo de descuento al 30 % desbloquearía en silencio un programa de
	 * puntos al 30 %, y rewards cambiaría de conducta si business se desactiva.
	 */
	public const DEFAULT_MAX_GIVEBACK_PCT = 15;

	/**
	 * Límite del campo del techo. Devolver la mitad de cada compra en puntos
	 * ya no es un programa de fidelidad, es una rebaja disfrazada: es la
	 * baranda contra el tecleo (150, 1500), como los `max` del catálogo.
	 *
	 * Las dos constantes viven acá porque las consume la fila del catálogo
	 * (`SettingsPage::FIELDS['akb_rewards_max_giveback_pct']`, 'd' y 'max'), y
	 * ese catálogo es el ÚNICO que lee el techo: es una baranda del GUARDADO
	 * —el panel no deja persistir una razón que lo supere—, no una regla que
	 * el motor de puntos consulte en cada compra. Quien lea la option en
	 * runtime tiene que acotarla como lo hace `SettingsPage::clamp_int()`, que
	 * aplica el mismo min/max de la fila; no hay un segundo acotador aparte.
	 */
	public const HARD_MAX_GIVEBACK_PCT = 50;

	/**
	 * Puntos otorgados por cada tramo de $earn_amount().
	 *
	 * @return int
	 */
	public static function earn_points(): int {
		if ( ! self::lane_on( 'akb_rewards_earn_enabled' ) ) {
			return 0; // Interruptor del carril: apagado = nadie acumula.
		}
		return max( 0, (int) get_option( 'akb_rewards_earn_points', 2 ) );
	}

	/**
	 * Interruptor de UN CLIC por carril (todo activable/desactivable
	 * «ahora o después» por administración).
	 * Vive en el ACCESSOR a propósito: apagarlo aquí apaga a la vez el motor,
	 * los correos, Mi cuenta, la ficha y la página pública — nadie más tiene
	 * que preguntar. Los montos guardados se conservan para re-encender.
	 *
	 * @param string $option Option yes/no del carril.
	 * @return bool
	 */
	private static function lane_on( string $option ): bool {
		return 'yes' === (string) get_option( $option, 'yes' );
	}

	/**
	 * ¿El carrusel de la portada está encendido? El clic del panel manda.
	 *
	 * @return bool
	 */
	public static function carousel_on(): bool {
		return self::lane_on( 'akb_rewards_carousel_enabled' );
	}

	/**
	 * Tamaño del tramo en pesos sobre el que se otorgan earn_points().
	 *
	 * @return int
	 */
	public static function earn_amount(): int {
		// 0 APAGA la acumulación (la guarda de Calculator devuelve 0 puntos).
		// Evita que un campo vacío se convierta indebidamente en tramo de $1.
		return max( 0, (int) get_option( 'akb_rewards_earn_amount', 100 ) );
	}

	/**
	 * Estado de pedido que dispara la acreditación (sin prefijo wc-).
	 *
	 * Invariante de negocio: «Procesando» es el estado al que llega el pedido
	 * cuando la pasarela confirma el PAGO. El cliente que acaba de pagar ve sus
	 * puntos en el acto, en vez de esperar a despacho físico; lo que se acredita
	 * de más si la venta se cae lo devuelve la reversa (cancelado, fallido o
	 * reembolsado deduce todo).
	 *
	 * El panel lo gobierna vía `akb_rewards_award_status`.
	 *
	 * @return string
	 */
	public static function award_status(): string {
		$status = (string) get_option( 'akb_rewards_award_status', self::DEFAULT_AWARD_STATUS );
		return '' !== $status ? $status : self::DEFAULT_AWARD_STATUS;
	}

	/**
	 * Días de carencia para liberación de puntos ganados por compra.
	 *
	 * 7 días exactos tras completarse el pedido (status = completed), para prevenir
	 * arbitraje y devoluciones con puntos ya canjeados.
	 * En entorno de tests o si se configura 0, se acreditan de inmediato.
	 *
	 * @return int
	 */
	public static function escrow_days(): int {
		$raw = get_option( 'akb_rewards_accrual_escrow_days', null );
		if ( null === $raw || false === $raw ) {
			$days = defined( 'PHPUNIT_COMPOSER_INSTALL' ) ? 0 : self::DEFAULT_ESCROW_DAYS;
		} else {
			$days = (int) $raw;
		}

		return (int) apply_filters( 'akb_rewards_accrual_escrow_days', max( 0, $days ) );
	}

	/**
	 * Compra mínima, en pesos pagados con dinero, para el bono de bienvenida y
	 * el premio a quien invitó.
	 *
	 * Política de negocio: Sin compra mínima para el bono de bienvenida.
	 * El freno contra el farmeo lo pone el carrito mínimo del canje: el bono
	 * sólo se gasta dentro de una compra estándar completa.
	 * Con 0 basta con que haya dinero: una compra pagada entera con puntos nunca cuenta.
	 *
	 * @return int
	 */
	public static function first_purchase_min(): int {
		return max( 0, (int) get_option( 'akb_rewards_first_purchase_min', 0 ) );
	}

	/**
	 * Con qué señales se reconoce a la misma persona en una compra anterior.
	 *
	 * Señales antifraude: RUT, teléfono y correo electrónico. Cada señal
	 * tiene su interruptor por si alguna da falsos positivos (un teléfono
	 * familiar compartido, por ejemplo); la cuenta del cliente se compara
	 * siempre.
	 *
	 * @return array<int,string> Subconjunto de rut, phone, email.
	 */
	public static function first_purchase_checks(): array {
		$senales = array(
			'rut'   => 'akb_rewards_first_purchase_by_rut',
			'phone' => 'akb_rewards_first_purchase_by_phone',
			'email' => 'akb_rewards_first_purchase_by_email',
		);

		$activas = array();
		foreach ( $senales as $senal => $opcion ) {
			if ( 'yes' === (string) get_option( $opcion, 'yes' ) ) {
				$activas[] = $senal;
			}
		}

		return $activas;
	}

	/**
	 * Carrito mínimo, en pesos de productos, para poder canjear puntos.
	 *
	 * Carrito mínimo estándar: $50.000. Es lo que permite regalar puntos
	 * (bienvenida sin mínimo, cumpleaños, reseñas, referidos) sin riesgo de
	 * farmeo: sólo se gastan dentro de una compra sustancial. 0 = sin mínimo.
	 *
	 * @return int
	 */
	public static function redeem_min_cart(): int {
		return max( 0, (int) get_option( 'akb_rewards_redeem_min_cart', 50000 ) );
	}

	/**
	 * Estado del pedido del invitado que paga el premio a quien invitó.
	 *
	 * Estado de activación de referidos: «Completado». El premio se otorga
	 * cuando la compra ya se entregó; si posteriormente se anula o reembolsa
	 * por completo, la reversa descuenta el crédito.
	 *
	 * @return string Estado sin prefijo wc-.
	 */
	public static function referral_status(): string {
		$status = (string) get_option( 'akb_rewards_referral_status', 'completed' );
		return '' !== $status ? $status : 'completed';
	}

	/**
	 * Inicio de vigencia del programa (hora local del sitio).
	 *
	 * Pedidos CREADOS antes de esta fecha no acreditan aunque cambien de
	 * estado después: sin esta compuerta, mover un pedido antiguo a «Listo
	 * para enviar» regalaría el 2% + bono retroactivo (lección del 22-08).
	 *
	 * @return string Formato Y-m-d H:i:s.
	 */
	public static function program_start(): string {
		$start = (string) get_option( 'akb_rewards_program_start', '2026-08-17 00:00:00' );
		return '' !== $start ? $start : '2026-08-17 00:00:00';
	}

	/**
	 * Bono de bienvenida en puntos. 0 = desactivado.
	 *
	 * @return int
	 */
	public static function welcome_bonus(): int {
		if ( ! self::lane_on( 'akb_rewards_welcome_enabled' ) ) {
			return 0;
		}

		/**
		 * Permite ajustar el bono por código (campañas).
		 *
		 * @param int $bonus Puntos del bono.
		 */
		return max( 0, (int) apply_filters( 'akb_rewards_welcome_bonus', (int) get_option( 'akb_rewards_welcome_bonus', 500 ) ) );
	}

	/**
	 * «1 punto = $N» para las superficies (correo, Mi cuenta): SIEMPRE
	 * derivado de redeem_value — el «$1» escrito a mano contradecía la
	 * config en la misma frase.
	 *
	 * @return string
	 */
	public static function redeem_label(): string {
		return self::points_label( 1 );
	}

	/**
	 * Cuánto valen N puntos, en pesos y ya formateado («$480»).
	 *
	 * La equivalencia es el gancho: «480 puntos» obliga al cliente a hacer una
	 * cuenta que no tiene por qué saber hacer, «= $480» se entiende de una
	 * lectura directa en la ficha de producto. Vive aquí, junto al cambio,
	 * para que ninguna superficie lo multiplique por su cuenta: si cambia
	 * la conversión del punto, se actualizan todas a la vez.
	 *
	 * @param int $points Puntos a convertir.
	 * @return string
	 */
	public static function points_label( int $points ): string {
		return '$' . number_format_i18n( max( 0, $points ) * self::redeem_value() );
	}

	/**
	 * ¿El MOTOR propio de reseñas está despierto? Autoridad ÚNICA de la
	 * pregunta: la consultan el arranque (qué módulos cuelgan) y las siete
	 * superficies de copy. Con dos autoridades, el filtro documentado sólo lo
	 * obedecía una y el arranque decidía por su cuenta con un class_exists
	 * crudo.
	 *
	 * OJO: el arranque la llama en `plugins_loaded`, así que un filtro
	 * registrado más tarde no cambia qué módulos cuelgan — sólo el copy.
	 *
	 * @return bool
	 */
	public static function reviews_engine_on(): bool {
		/**
		 * Permite forzar el estado del motor de reseñas (pruebas, cutover).
		 *
		 * @param bool $on Despierto si CusRev no está activo.
		 */
		return (bool) apply_filters( 'akb_rewards_reviews_engine_on', ! class_exists( '\\CR_Reviews' ) );
	}

	/**
	 * ¿El carril de reseñas-con-puntos está VIVO? Exige monto > 0 y que el
	 * motor propio esté despierto (duerme mientras CusRev viva): ninguna
	 * superficie puede prometer un premio que ningún código puede pagar.
	 *
	 * @return bool
	 */
	public static function reviews_lane_on(): bool {
		return self::review_points() > 0 && self::reviews_engine_on();
	}

	/**
	 * Mínimo de puntos por canje (patrón estándar de fidelización).
	 * 0 = sin mínimo. Vive aquí para que el motor, Mi cuenta y la
	 * página pública obedezcan al unísono.
	 *
	 * @return int
	 */
	public static function redeem_min_points(): int {
		return max( 0, (int) get_option( 'akb_rewards_redeem_min_points', 100 ) );
	}

	/**
	 * Porcentaje MÁXIMO del pedido que se puede pagar con puntos.
	 *
	 * Sin tope, el canje podría cubrir el 100 % de las líneas: un cliente con
	 * saldo se llevaría productos sin flujo de caja. El tope porcentual
	 * protege los márgenes operativos de la tienda.
	 *
	 * 0 = sin tope (comportamiento anterior). Se acota a 100 porque más no
	 * significa nada: el canje jamás puede superar las líneas del carrito.
	 *
	 * @return int Porcentaje entre 0 y 100.
	 */
	public static function redeem_max_pct(): int {
		$pct = (int) get_option( 'akb_rewards_redeem_max_pct', self::DEFAULT_REDEEM_MAX_PCT );

		return max( 0, min( 100, $pct ) );
	}

	/**
	 * Tope del canje en PESOS por pedido. 0 = sin tope en pesos.
	 *
	 * Convive con el porcentual y rige el más restrictivo de los dos. Un tope
	 * nominal en pesos («$5.000 máximo») evita que el comprador tenga que
	 * calcular porcentajes mentales para saber cuánto puede descontar.
	 *
	 * @return int
	 */
	public static function redeem_max_amount(): int {
		return max( 0, (int) get_option( 'akb_rewards_redeem_max_amount', self::DEFAULT_REDEEM_MAX_AMOUNT ) );
	}

	/**
	 * Pesos descontados por punto canjeado (valor facial, IVA incluido).
	 *
	 * ENTERO: la tienda cobra en CLP, que no tiene centavos, y la pantalla de
	 * ajustes declara el campo como int. Devolverlo float sostenía una rama de
	 * dos decimales en redeem_label() que ningún valor guardable alcanzaba.
	 *
	 * @return int
	 */
	public static function redeem_value(): int {
		$value = (int) get_option( 'akb_rewards_redeem_value', 1 );
		return $value > 0 ? $value : 1;
	}

	/**
	 * Referidos: el interruptor y el monto del carril «invita a un amigo».
	 * Vive acá como los demás carriles — Referrals leía sus cuatro ajustes con
	 * get_option crudo y se saltaba la fuente única del programa.
	 *
	 * @return int
	 */
	public static function referral_points(): int {
		if ( ! self::lane_on( 'akb_rewards_referral_enabled' ) ) {
			return 0;
		}
		return max( 0, (int) get_option( 'akb_rewards_referral_points', 500 ) );
	}

	/**
	 * Días que la visita con ?ref= se recuerda antes de crear la cuenta.
	 *
	 * @return int
	 */
	public static function referral_cookie_days(): int {
		return max( 1, min( 90, (int) get_option( 'akb_rewards_referral_cookie_days', 30 ) ) );
	}

	/**
	 * Tope de premios por padrino EN LA HISTORIA. 0 = sin tope.
	 *
	 * @return int
	 */
	public static function referral_max_per_user(): int {
		return max( 0, (int) get_option( 'akb_rewards_referral_max_per_user', 0 ) );
	}

	/**
	 * Puntos por reseña con foto APROBADA. 0 = programa apagado.
	 *
	 * La reseña con foto aprobada otorga PUNTOS (no cupones de un solo uso),
	 * acreditados tras su moderación exitosa (500 puntos por defecto).
	 *
	 * @return int
	 */
	public static function review_points(): int {
		if ( ! self::lane_on( 'akb_rewards_reviews_enabled' ) ) {
			return 0;
		}
		return max( 0, (int) get_option( 'akb_rewards_review_points', 500 ) );
	}

	/**
	 * Puntos por cumpleaños (una vez al año). 0 = apagado.
	 *
	 * Puntos por cumpleaños: 1.000 de fábrica (superior al bono de bienvenida
	 * para premiar lealtad anual).
	 *
	 * @return int
	 */
	public static function birthday_points(): int {
		if ( ! self::lane_on( 'akb_rewards_birthday_enabled' ) ) {
			return 0;
		}
		return max( 0, (int) get_option( 'akb_rewards_birthday_points', 1000 ) );
	}

	/**
	 * Largo mínimo del texto para que una reseña sea elegible del carrusel.
	 *
	 * @return int
	 */
	public static function carousel_min_chars(): int {
		return max( 1, (int) get_option( 'akb_rewards_carousel_min_chars', 40 ) );
	}

	/**
	 * Máximo de tarjetas pintadas en el carrusel.
	 *
	 * @return int
	 */
	public static function carousel_cards(): int {
		return max( 1, min( 20, (int) get_option( 'akb_rewards_carousel_cards', 8 ) ) );
	}

	/**
	 * Tipo de reseñas elegido en el panel; la portada admite ambas por defecto.
	 *
	 * @return string mixed|photos|text.
	 */
	public static function carousel_media(): string {
		$mode = (string) get_option( 'akb_rewards_carousel_media', 'mixed' );
		return in_array( $mode, array( 'mixed', 'photos', 'text' ), true ) ? $mode : 'mixed';
	}

	/** Permite mostrar el aviso de puntos en el carrusel sin cambiar el de las fichas. */
	public static function carousel_disclosure_on(): bool {
		return 'yes' === (string) get_option( 'akb_rewards_carousel_disclosure_enabled', 'no' );
	}

	/**
	 * ¿Se pinta el aviso de incentivo en las reseñas premiadas?
	 *
	 * Estado del aviso de incentivo: desactivado por defecto. Permite activar
	 * el aviso de divulgación con un clic en el panel administrativo según
	 * las pautas de datos estructurados de motores de búsqueda.
	 *
	 * @return bool
	 */
	public static function disclosure_on(): bool {
		return 'yes' === (string) get_option( 'akb_rewards_disclosure_enabled', 'no' );
	}

	/**
	 * Título de las reseñas: el del carrusel de la portada y, en el tema, el de
	 * la sección de reseñas de cada ficha (regla F: configurable). En el panel,
	 * «Título de las reseñas (portada y fichas)».
	 *
	 * @return string
	 */
	public static function carousel_title(): string {
		$title = (string) get_option( 'akb_rewards_carousel_title', 'Voces del Distrito' );
		return '' !== $title ? $title : 'Voces del Distrito';
	}

	/**
	 * Cada cuántas reseñas calificadas se paga el premio. 1 = cada reseña.
	 *
	 * Frecuencia de acreditación: configurable para otorgar puntos en cada
	 * reseña o cada N reseñas aprobadas.
	 *
	 * @return int
	 */
	public static function review_every_n(): int {
		return max( 1, (int) get_option( 'akb_rewards_review_every_n', 1 ) );
	}

	/**
	 * Máximo de premios de reseña por cliente EN LA HISTORIA. 0 = sin tope.
	 *
	 * @return int
	 */
	public static function review_max_per_user(): int {
		return max( 0, (int) get_option( 'akb_rewards_review_max_per_user', 0 ) );
	}

	/**
	 * Mínimo de reseñas elegibles para que el carrusel del home aparezca.
	 *
	 * Decisión del dueño: «cuando sobrepase las 7» → 8 por defecto.
	 *
	 * @return int
	 */
	public static function carousel_min(): int {
		return max( 1, (int) get_option( 'akb_rewards_carousel_min', 8 ) );
	}

	/**
	 * ¿Avisar al dueño cuando algo del programa necesita revisión?
	 *
	 * @return bool
	 */
	public static function alerts_on(): bool {
		return 'yes' === (string) get_option( 'akb_rewards_alerts_enabled', 'yes' );
	}

	/**
	 * Destinatarios de las alertas: los del panel o, si está vacío, el correo
	 * de administración del sitio.
	 *
	 * @return array<int,string>
	 */
	public static function alerts_recipients(): array {
		$lista = self::email_list( (string) get_option( 'akb_rewards_alerts_email', '' ) );

		return array() !== $lista ? $lista : self::email_list( (string) get_option( 'admin_email', '' ) );
	}

	/**
	 * Correos válidos de un texto separado por comas, puntos y comas o espacios.
	 *
	 * La MISMA lectura para guardar y para enviar: lo que el panel acepta es
	 * exactamente lo que recibe el aviso.
	 *
	 * @param string            $texto       Lo escrito.
	 * @param array<int,string> $descartados Por referencia: lo que no era un correo.
	 * @return array<int,string> Correos válidos, sin repetir, en su orden.
	 */
	public static function email_list( string $texto, array &$descartados = array() ): array {
		$validos = array();

		foreach ( (array) preg_split( '/[\s,;]+/', $texto, -1, PREG_SPLIT_NO_EMPTY ) as $pieza ) {
			$correo = sanitize_email( (string) $pieza );
			if ( '' === $correo || false === strpos( $correo, '@' ) ) {
				$descartados[] = (string) $pieza;
				continue;
			}
			if ( ! in_array( $correo, $validos, true ) ) {
				$validos[] = $correo;
			}
		}

		return $validos;
	}
}


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/src/Support/OrderStates.php
================================================================

<?php
/**
 * OrderStates — el vocabulario de estados de pedido que comparte el programa.
 *
 * Existe porque el conjunto «pedido caído» vivía triplicado como literal en
 * tres módulos que TIENEN que coincidir: si la tienda estrena un estado de
 * anulación y sólo se actualizan dos copias, un pedido muerto revierte los
 * puntos pero no repone el canje (o al revés), y la invitación a reseñar sale
 * igual. Un solo literal, tres consumidores.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Support;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.3.1
 */
final class OrderStates {

	/**
	 * Pedido CAÍDO (sin prefijo wc-): la venta no existe. Revierte la
	 * acreditación, repone el canje restante y cancela la invitación a
	 * reseñar.
	 *
	 * @var array<int,string>
	 */
	public const DOWN = array( 'cancelled', 'refunded', 'failed', 'trash' );

	/**
	 * Pedido VIVO: acá se reintenta una captura de canje pendiente y se
	 * re-cobra la deuda del revival.
	 *
	 * @var array<int,string>
	 */
	public const ALIVE = array( 'pending', 'on-hold', 'processing', 'shipping-progress', 'completed' );

	/**
	 * Pedido PAGADO: una compra de verdad. Lo comparten «¿ya compró antes?»
	 * (FirstPurchase) y la invitación a reseñar, que sale mientras el pedido
	 * siga pagado aunque haya avanzado más allá del estado disparador.
	 *
	 * @var array<int,string>
	 */
	public const PAID = array( 'processing', 'shipping-progress', 'completed' );

	/**
	 * ¿`$status` es el estado pagado `$ref`, o uno posterior?
	 *
	 * El orden es el de PAID: procesando → listo para enviar → completado.
	 * Existe porque la acreditación no puede depender de pisar EXACTAMENTE el
	 * estado que fija el panel: un pedido que salta directo a «Completado»
	 * —pago manual, retiro en tienda, o el dueño moviéndolo a mano— pasaba de
	 * largo y el cliente no veía sus puntos nunca. Cambiar el disparador en el
	 * panel dejaba el mismo hueco con los pedidos ya en curso.
	 *
	 * Acreditar de más no es un riesgo: `maybe_award()` es idempotente bajo
	 * candado de pedido y un pedido caído revierte entero.
	 *
	 * Con un `$ref` fuera de PAID (el dueño puede elegir «En espera») no hay
	 * orden que seguir: sólo coincide el propio estado.
	 *
	 * @param string $status Estado al que ENTRA el pedido, sin prefijo wc-.
	 * @param string $ref    Estado de referencia, sin prefijo wc-.
	 * @return bool
	 */
	public static function is_at_or_after( string $status, string $ref ): bool {
		$desde = array_search( $ref, self::PAID, true );
		$hasta = array_search( $status, self::PAID, true );

		if ( false === $desde || false === $hasta ) {
			return $status === $ref;
		}

		return $hasta >= $desde;
	}

	/**
	 * ¿Este estado es de pedido caído?
	 *
	 * @param string $status Estado sin prefijo wc-.
	 * @return bool
	 */
	public static function is_down( string $status ): bool {
		return in_array( $status, self::DOWN, true );
	}

	/**
	 * ¿Este estado es de pedido vivo?
	 *
	 * @param string $status Estado sin prefijo wc-.
	 * @return bool
	 */
	public static function is_alive( string $status ): bool {
		return in_array( $status, self::ALIVE, true );
	}
}


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/src/Support/Privacy.php
================================================================

<?php
/**
 * Privacy — qué pasa con los puntos cuando una CUENTA se borra o pide sus
 * datos.
 *
 * Preservación de trazabilidad contable en el ledger al eliminar usuarios:
 * congela la identidad en la fila del asiento para mantener la inteligibilidad contable.
 * Integra con el sistema estándar de privacidad de WordPress (Herramientas -> Exportar/Borrar datos personales).
 *
 * El exportador entrega saldo, historial, cumpleaños, código de padrino,
 * vínculo de invitación y fotos adjuntas a las reseñas de la persona.
 *
 * El borrador elimina lo que sólo describe a la persona —el
 * día de su cumpleaños, su código de padrino y quién la invitó— y RETIENE,
 * diciéndolo, dos cosas distintas: el historial de puntos, que es registro
 * contable (respaldo de descuentos ya cobrados), y los sellos de «ya se pagó»
 * del bono, del cumpleaños y del premio de referido, que no llevan identidad y
 * sin los cuales el mismo premio se volvería a pagar.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Support;

use Akibara\Rewards\Points\Ledger;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.3.0
 */
final class Privacy {

	/**
	 * Cuelga los hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'delete_user', array( $this, 'stamp_identity' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
	}

	/**
	 * Congela la identidad del cliente en sus filas del ledger ANTES de que
	 * WordPress lo borre (delete_user corre antes del borrado real).
	 *
	 * @param int $user_id Usuario a borrar.
	 * @return void
	 */
	public function stamp_identity( $user_id ): void {
		$user = get_userdata( (int) $user_id );
		if ( false === $user || '' === (string) $user->user_email ) {
			return;
		}

		// Si el usuario solicitó supresión formal de datos, no se estampa el correo en el historial.
		$marca = '' !== (string) get_user_meta( (int) $user_id, self::META_SUPRESION, true )
			? ' [cuenta borrada a petición de su titular]'
			: ' [cuenta borrada: ' . (string) $user->user_email . ']';

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- tabla propia; corre una vez, al borrar la cuenta.
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . Schema::ledger_table() . ' SET note = SUBSTRING(CONCAT(note, %s), 1, 255) WHERE user_id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- tabla propia.
				$marca,
				(int) $user_id
			)
		);
	}

	/**
	 * Registra el exportador del flujo de privacidad del core.
	 *
	 * @param array<string,array<string,mixed>> $exporters Exportadores.
	 * @return array<string,array<string,mixed>>
	 */
	public function register_exporter( $exporters ): array {
		$exporters['akibara-rewards'] = array(
			'exporter_friendly_name' => 'Puntos Akibara',
			'callback'               => array( $this, 'export' ),
		);
		return (array) $exporters;
	}

	/**
	 * Registra el borrador del flujo de privacidad del core.
	 *
	 * @param array<string,array<string,mixed>> $erasers Borradores.
	 * @return array<string,array<string,mixed>>
	 */
	public function register_eraser( $erasers ): array {
		$erasers['akibara-rewards'] = array(
			'eraser_friendly_name' => 'Puntos Akibara',
			'callback'             => array( $this, 'erase' ),
		);
		return (array) $erasers;
	}

	/**
	 * Movimientos por página del exportador. El core vuelve a llamar con
	 * $page+1 mientras `done` sea false.
	 */
	private const PER_PAGE = 200;

	public const CUMPLEANOS = '_akb_rewards_birthday_md';

	/**
	 * Sello de «este titular pidió la supresión de sus datos» (fecha Unix).
	 *
	 * No describe a la persona: existe para que `stamp_identity()` cumpla lo
	 * que el informe de borrado prometió si después se elimina la cuenta.
	 */
	public const META_SUPRESION = '_akb_rewards_privacy_erased';

	/**
	 * Lo que el programa guarda SOBRE la persona, dicho como lo diría ella.
	 *
	 * Lista común al exportador y al borrador para mantener la cobertura de
	 * datos personales en ambos flujos.
	 *
	 * @var array<string,string>
	 */
	private const CAMPOS_PERSONALES = array(
		self::CUMPLEANOS             => 'Día de cumpleaños registrado',
		'_akb_rewards_ref_code'      => 'Tu código para invitar amigos',
	);

	/**
	 * Sellos de «ya se pagó»: se RETIENEN y se declaran.
	 *
	 * No llevan identidad —son un año, un id de pedido o un sí— y son lo único
	 * que impide que el mismo bono, el mismo regalo de cumpleaños o el mismo
	 * premio de referido se vuelvan a pagar. Borrarlos con la solicitud de
	 * borrado convertiría el derecho del cliente en una palanca de fraude.
	 *
	 * @var array<int,string>
	 */
	private const SELLOS = array(
		'_akb_rewards_welcome_paid',
		'_akb_rewards_birthday_paid_year',
		'_akb_rewards_referral_order',
	);

	/**
	 * Exporta saldo e historial de puntos del correo solicitado.
	 *
	 * PAGINADO de verdad: cortar en 200 y declarar `done = true` entregaba un
	 * historial incompleto en silencio, que es justo lo que un derecho de
	 * acceso no admite. El saldo va sólo en la primera página.
	 *
	 * @param string $email Correo de la solicitud.
	 * @param int    $page  Página (1-based) que pide el core.
	 * @return array{data:array<int,array<string,mixed>>,done:bool}
	 */
	public function export( $email, $page = 1 ): array {
		$page = max( 1, (int) $page );
		$user = get_user_by( 'email', (string) $email );
		if ( false === $user ) {
			// Compradores invitados pueden tener fotos asociadas a sus reseñas aunque no tengan cuenta activa.
			return array(
				'data' => 1 === $page ? self::items_de_fotos( (string) $email, 0 ) : array(),
				'done' => true,
			);
		}

		global $wpdb;

		$offset = ( $page - 1 ) * self::PER_PAGE;

		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT created_at, delta, note FROM ' . Schema::ledger_table() . ' WHERE user_id = %d ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- tabla propia.
				(int) $user->ID,
				self::PER_PAGE,
				$offset
			),
			ARRAY_A
		);

		$data = array();

		if ( 1 === $page ) {
			$perfil = array(
				array(
					'name'  => 'Saldo de puntos',
					'value' => (string) Ledger::balance( (int) $user->ID ),
				),
			);

			// El resto de lo que el programa guarda SOBRE la persona. Se
			// nombra en lenguaje de persona, no con la meta_key: quien pide
			// sus datos no sabe qué es `_akb_rewards_ref_code`.
			foreach ( self::CAMPOS_PERSONALES as $meta => $etiqueta ) {
				$valor = (string) get_user_meta( (int) $user->ID, $meta, true );
				if ( '' === $valor ) {
					continue;
				}

				$perfil[] = array(
					'name'  => $etiqueta,
					'value' => self::CUMPLEANOS === $meta ? self::cumpleanos_legible( $valor ) : $valor,
				);
			}

			// A quién invitó esta persona no se lista: son terceros, y su
			// identidad no es dato de quien pregunta. Sí va el vínculo
			// inverso —quién la invitó a ella—, que sí la describe.
			$padrino = (int) get_user_meta( (int) $user->ID, '_akb_rewards_referred_by', true );
			if ( $padrino > 0 ) {
				$perfil[] = array(
					'name'  => 'Llegó invitado por',
					'value' => self::nombre_de( $padrino ),
				);
			}

			$data[] = array(
				'group_id'    => 'akibara-rewards',
				'group_label' => 'Puntos Akibara',
				'item_id'     => 'akb-rewards-balance',
				'data'        => $perfil,
			);

			$data = array_merge( $data, self::items_de_fotos( (string) $email, (int) $user->ID ) );
		}

		foreach ( $rows as $i => $row ) {
			$data[] = array(
				'group_id'    => 'akibara-rewards',
				'group_label' => 'Puntos Akibara',
				'item_id'     => 'akb-rewards-mov-' . ( $offset + (int) $i ),
				'data'        => array(
					array(
						'name'  => 'Movimiento',
						'value' => sprintf( '%s: %+d — %s', (string) $row['created_at'], (int) $row['delta'], (string) $row['note'] ),
					),
				),
			);
		}

		return array(
			'data' => $data,
			'done' => count( $rows ) < self::PER_PAGE,
		);
	}

	/**
	 * Borra lo que sólo describe a la persona y declara lo que se retiene.
	 *
	 * @param string $email Correo de la solicitud.
	 * @param int    $page  Página.
	 * @return array{items_removed:bool,items_retained:bool,messages:array<int,string>,done:bool}
	 */
	public function erase( $email, $page = 1 ): array {
		unset( $page );

		$user = get_user_by( 'email', (string) $email );

		// Las fotos de las reseñas se declaran SIEMPRE, con cuenta o sin ella:
		// el núcleo anonimiza el comentario pero no toca sus adjuntos, y una
		// respuesta que calla lo que se queda es la que este fichero vino a
		// retirar. Si se borran o no es una decisión del dueño (la foto es
		// requisito de la reseña publicada); mientras tanto se dice la verdad
		// y se da el camino.
		$fotos = count( self::fotos_de( (string) $email, false === $user ? 0 : (int) $user->ID ) );
		$aviso_fotos = $fotos > 0
			? sprintf(
				1 === $fotos
					? 'Se conserva %d foto publicada junto a una reseña tuya: si quieres que la retiremos, escríbenos a contacto@akibara.cl.'
					: 'Se conservan %d fotos publicadas junto a tus reseñas: si quieres que las retiremos, escríbenos a contacto@akibara.cl.',
				$fotos
			)
			: '';

		if ( false === $user ) {
			return array(
				'items_removed'  => false,
				'items_retained' => '' !== $aviso_fotos,
				'messages'       => '' !== $aviso_fotos ? array( $aviso_fotos ) : array(),
				'done'           => true,
			);
		}

		// El sello va ANTES de borrar nada: si después se elimina la cuenta,
		// `stamp_identity()` lo lee para no estampar el correo en el historial.
		update_user_meta( (int) $user->ID, self::META_SUPRESION, time() );

		$borrados = array();
		foreach ( self::CAMPOS_PERSONALES as $meta => $etiqueta ) {
			if ( '' !== (string) get_user_meta( (int) $user->ID, $meta, true ) ) {
				delete_user_meta( (int) $user->ID, $meta );
				$borrados[] = $etiqueta;
			}
		}

		// El vínculo con quien la invitó describe una relación entre dos
		// personas. Se va; el SELLO del premio (`_akb_rewards_referral_order`)
		// se queda, y con él la imposibilidad de volver a cobrarlo.
		if ( '' !== (string) get_user_meta( (int) $user->ID, '_akb_rewards_referred_by', true ) ) {
			delete_user_meta( (int) $user->ID, '_akb_rewards_referred_by' );
			$borrados[] = 'quién la invitó';
		}

		$mensajes = array();

		if ( array() !== $borrados ) {
			$mensajes[] = 'Se borraron de Puntos Akibara: ' . implode( ', ', $borrados ) . '.';
		}

		$retenido = array();

		if ( array() !== $this->has_rows( (int) $user->ID ) ) {
			$retenido[] = 'el historial de puntos, que es registro contable (respaldo de descuentos ya aplicados) y queda sin tu correo: si la cuenta se elimina, tus movimientos no llevan ningún dato que te identifique';
		}

		foreach ( self::SELLOS as $meta ) {
			if ( '' !== (string) get_user_meta( (int) $user->ID, $meta, true ) ) {
				$retenido[] = 'la marca de que ya se pagó un premio del programa (sin ningún dato de identidad), para que no se pague dos veces';
				break;
			}
		}

		if ( array() !== $retenido ) {
			$mensajes[] = 'Se conservan ' . implode( ', y ', $retenido ) . '.';
		}

		if ( '' !== $aviso_fotos ) {
			$mensajes[] = $aviso_fotos;
		}

		return array(
			'items_removed'  => array() !== $borrados,
			'items_retained' => array() !== $retenido || '' !== $aviso_fotos,
			'messages'       => $mensajes,
			'done'           => true,
		);
	}

	/**
	 * «03-14» → «14 de marzo». El formato interno no le dice nada a nadie.
	 *
	 * @param string $md Mes-día guardado.
	 * @return string
	 */
	private static function cumpleanos_legible( string $md ): string {
		if ( ! preg_match( '/^(\d{2})-(\d{2})$/', $md, $m ) ) {
			return $md;
		}

		$meses = array( 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre' );
		$mes   = (int) $m[1];

		return isset( $meses[ $mes - 1 ] ) ? sprintf( '%d de %s', (int) $m[2], $meses[ $mes - 1 ] ) : $md;
	}

	/**
	 * Nombre público de la persona que invitó, sin filtrar su correo.
	 *
	 * @param int $user_id Padrino.
	 * @return string
	 */
	private static function nombre_de( int $user_id ): string {
		$padrino = get_userdata( $user_id );

		return false === $padrino ? 'otro cliente' : (string) $padrino->display_name;
	}

	/**
	 * Las fotos de reseña, ya con la forma que pide el exportador del núcleo.
	 *
	 * @param string $email   Correo de la solicitud.
	 * @param int    $user_id Cuenta, si la hay (0 = comprador invitado).
	 * @return array<int,array<string,mixed>>
	 */
	private static function items_de_fotos( string $email, int $user_id ): array {
		$items = array();

		foreach ( self::fotos_de( $email, $user_id ) as $i => $url ) {
			$items[] = array(
				'group_id'    => 'akibara-rewards',
				'group_label' => 'Puntos Akibara',
				'item_id'     => 'akb-rewards-foto-' . $i,
				'data'        => array(
					array(
						'name'  => 'Foto que subiste con una reseña',
						'value' => $url,
					),
				),
			);
		}

		return $items;
	}

	/**
	 * URLs de las fotos que esta persona subió con sus reseñas.
	 *
	 * Complementa la exportación de comentarios del core con las fotos de las
	 * reseñas asociadas al correo solicitado.
	 *
	 * @param string $email   Correo de la solicitud.
	 * @param int    $user_id Cuenta, si la hay.
	 * @return array<int,string>
	 */
	private static function fotos_de( string $email, int $user_id ): array {
		$comentarios = get_comments(
			array(
				'author_email' => $email,
				// 'status' => 'any' incluye cualquier estado (aprobado, pendiente, spam, papelera).
				'status'       => 'any',
				'number'       => 200,
				'fields'       => 'ids',
			)
		);

		if ( $user_id > 0 ) {
			$comentarios = array_unique(
				array_merge(
					(array) $comentarios,
					(array) get_comments(
						array(
							'user_id' => $user_id,
							'status'  => 'any',
							'number'  => 200,
							'fields'  => 'ids',
						)
					)
				)
			);
		}

		$urls = array();
		foreach ( (array) $comentarios as $comment_id ) {
			foreach ( (array) get_comment_meta( (int) $comment_id, \Akibara\Rewards\Reviews\Media::META_LOCAL_IMAGE ) as $attachment_id ) {
				$url = wp_get_attachment_url( (int) $attachment_id );
				if ( is_string( $url ) && '' !== $url ) {
					$urls[] = $url;
				}
			}
		}

		return array_values( array_unique( $urls ) );
	}

	/**
	 * ¿El usuario tiene filas en el ledger?
	 *
	 * @param int $user_id Usuario.
	 * @return array<int,string> Ids encontrados (vacío = ninguno).
	 */
	private function has_rows( int $user_id ): array {
		global $wpdb;

		return (array) $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM ' . Schema::ledger_table() . ' WHERE user_id = %d LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- tabla propia.
				$user_id
			)
		);
	}
}


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/src/Support/PrivatePage.php
================================================================

<?php
/**
 * PrivatePage — una página con el estado de UNA persona no se guarda en caché.
 *
 * Las dos páginas por enlace del plugin (el formulario de reseña y las
 * preferencias de correo) se defendían sólo con `nocache_headers()`, que son
 * cabeceras del origen y en esta tienda no deciden nada:
 *
 *   - LiteSpeed resuelve con sus propios ajustes y sólo se rinde ante
 *     `DONOTCACHEPAGE` o su hook `litespeed_control_set_nocache`.
 *   - Las reglas de caché HTML para invitados en CDN pueden ignorar `no-store`
 *     en rutas de contenido general.
 *
 * Para asegurar no-cache estricto, la defensa se basa en la ruta: estas páginas
 * se sirven bajo la URL canónica de Mi cuenta (`/mi-cuenta/`), que está excluida
 * permanentemente de la caché perimetral (DYNAMIC).
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Support;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.4.9
 */
final class PrivatePage {

	/**
	 * URL base bajo la que el borde no cachea: la página de Mi cuenta.
	 *
	 * @return string Con barra final.
	 */
	public static function base_url(): string {
		$cuenta = function_exists( 'wc_get_page_permalink' ) ? (string) wc_get_page_permalink( 'myaccount' ) : '';

		return '' !== $cuenta ? $cuenta : home_url( '/mi-cuenta/' );
	}

	/**
	 * Apaga toda caché de página para la petición en curso.
	 *
	 * @param string $motivo Texto que LiteSpeed anota en su cabecera de depuración.
	 * @return void
	 */
	public static function no_cache( string $motivo ): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- constante estándar de facto de los plugins de caché: prefijarla la dejaría inerte.
		}

		nocache_headers();

		do_action( 'litespeed_control_set_nocache', $motivo ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook público de LiteSpeed Cache: el nombre lo fija ese plugin.
	}
}


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/src/Support/QueueHooks.php
================================================================

<?php
/**
 * QueueHooks — los hooks de Action Scheduler de este plugin, para quien vigile la cola.
 *
 * Por qué existe
 * --------------
 * La tarjeta «Alertas del Sistema» de akibara-business vigila la cola de
 * Action Scheduler (`Utilities\QueueHealth`, 1.116.31), pero sólo conoce sus
 * propios hooks. Este plugin agenda once —cuatro diarios: caducidad,
 * cumpleaños, limpieza de enlaces de reseña y el barrido de acreditación— y
 * sin esto podían dejar de correr con la tarjeta en verde (revisión
 * independiente de la ronda 7, M4). Se suman por el filtro
 * `akibara_queue_health_hooks`; si business no está, el filtro no lo lee nadie
 * y no pasa nada.
 *
 * La lista se mantiene a mano y `uninstall.php` la repite (no puede cargar
 * clases); `LaColaQuedaLimpiaAlDesinstalarTest` y `QueueHooksTest` vigilan que
 * las tres fuentes —código, desinstalador y esta lista— coincidan.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Support;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.4.15
 */
final class QueueHooks {

	public const ALL = array(
		'akb_rewards_expiry_daily',
		'akb_rewards_expiry_batch',
		'akb_rewards_expiry_warn_async',
		'akb_rewards_birthday_daily',
		'akb_rewards_redemption_retry',
		'akb_rewards_accrual_sweep_daily',
		'akb_rewards_release_accrual_escrow',
		'akb_rewards_send_review_invite',
		'akb_rewards_order_email_retry',
		'akb_rewards_review_email_retry',
		'akb_rewards_referral_email_retry',
		'akb_rewards_review_tokens_cleanup',
		'akb_rewards_home_cache_retry',
	);

	/**
	 * @return void
	 */
	public function register(): void {
		add_filter( 'akibara_queue_health_hooks', array( $this, 'add_hooks' ) );
	}

	/**
	 * @param mixed $hooks Los que ya vigila business.
	 * @return array<int, string>
	 */
	public function add_hooks( $hooks ): array {
		$previos = is_array( $hooks ) ? array_map( 'strval', $hooks ) : array();

		return array_values( array_unique( array_merge( $previos, self::ALL ) ) );
	}
}


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/src/Support/Schema.php
================================================================

<?php
/**
 * Schema — tabla {prefix}akb_rewards_ledger.
 *
 * Mismo patrón que BackInStock\Install de akibara-business: activation hook +
 * guard de versión en cada boot (deploys por rsync no reactivan el plugin) y
 * sellado de la versión SOLO si la tabla quedó realmente creada — dbDelta
 * falla en silencio y sellar a ciegas envenena el módulo para siempre.
 *
 * Decisiones de schema:
 *   - `delta` FIRMADO + `balance_after`: el ledger de SPAR guardaba puntos sin
 *     signo y el sentido vivía en `type` add/remove; con el delta firmado la
 *     suma de la columna ES el saldo, y `balance_after` deja auditable cada
 *     movimiento sin reconstruir la historia.
 *   - `ref_type`/`ref_id`: referencia polimórfica (order, review, admin,
 *     migration, birthday) para reversas exactas por pedido.
 *   - `event_key` UNIQUE y NULLABLE: la red de seguridad FINAL contra la
 *     doble acreditación. Los movimientos de un solo disparo (acreditación,
 *     bono, captura de canje) llevan clave ('award_100'); un duplicado choca
 *     con el índice y se trata como ya-aplicado. Los ajustes que pueden
 *     repetirse legítimamente (reembolsos parciales) van con NULL — MySQL
 *     permite múltiples NULL en un índice UNIQUE.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Support;

use Akibara\Rewards\Points\AccountRewards;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.1.0
 */
final class Schema {

	public const DB_VERSION = '1.3.0';
	public const OPTION_KEY = 'akb_rewards_db_version';

	/**
	 * Nombre completo de la tabla del ledger (con prefix del sitio).
	 *
	 * @return string
	 */
	public static function ledger_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'akb_rewards_ledger';
	}

	/**
	 * Tabla de tokens del formulario de reseña por invitación (Fase 2b).
	 *
	 * @return string
	 */
	public static function review_forms_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'akb_rewards_review_forms';
	}

	/**
	 * Higiene: borra tokens de invitación que vencieron hace más de 6 meses y
	 * nunca se usaron. Los usados se conservan (auditoría de qué reseña vino
	 * de qué pedido). La llama el cron diario; LIMIT para no bloquear.
	 *
	 * @return void
	 */
	public static function purge_stale_review_tokens(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- tabla propia, mantenimiento.
		$wpdb->query( 'DELETE FROM ' . self::review_forms_table() . " WHERE used_at IS NULL AND expires_at < DATE_SUB(NOW(), INTERVAL 6 MONTH) LIMIT 500" );
	}

	/**
	 * Cuelga la migración en activación y en boot.
	 *
	 * @return void
	 */
	public static function register(): void {
		register_activation_hook( AKIBARA_REWARDS_FILE, array( self::class, 'maybe_install' ) );
		add_action( 'init', array( self::class, 'maybe_install' ), 5 );
	}

	/**
	 * Crea o migra la tabla si la versión guardada difiere. Idempotente.
	 *
	 * @return void
	 */
	public static function maybe_install(): void {
		if ( get_option( self::OPTION_KEY ) === self::DB_VERSION ) {
			return;
		}

		self::install();

		if ( ! self::schema_ready() ) {
			global $wpdb;
			Log::error(
				sprintf(
					'Migración %s falló: %s no existe tras dbDelta; no se sella %s. last_error=%s',
					self::DB_VERSION,
					self::ledger_table(),
					self::OPTION_KEY,
					(string) $wpdb->last_error
				)
			);
			return;
		}

		self::migrate_option_autoload();
		self::migrate_award_status();

		// autoload = true a propósito (ver migrate_option_autoload): esta
		// misma option se lee en CADA petición, en el guard de arriba.
		update_option( self::OPTION_KEY, self::DB_VERSION, true );
	}

	/**
	 * Options del programa que DEBEN viajar en `alloptions`.
	 *
	 * Son los 36 montos/interruptores de la pantalla de ajustes más las dos
	 * marcas de arranque. Todas se leen en el camino del cliente (ficha, Mi
	 * cuenta, carrito, correos) y son escalares diminutos: con autoload
	 * apagado, un render de Mi cuenta pagaba 17 SELECT sueltos y cada ficha
	 * de producto 6. Autoload existe exactamente para esto.
	 *
	 * @return array<int,string>
	 */
	public static function autoloaded_options(): array {
		return array_merge(
			SettingsPage::option_names(),
			array( self::OPTION_KEY, AccountRewards::FLUSH_OPTION )
		);
	}

	/**
	 * Pone en autoload las options del programa que ya existan.
	 *
	 * Cambiar el flag en la escritura NO reescribe las filas guardadas: sin
	 * esta pasada, una instalación viva se quedaba con las 36 en `off` para
	 * siempre. Corre una vez por versión de esquema.
	 *
	 * @return void
	 */
	private static function migrate_option_autoload(): void {
		if ( ! function_exists( 'wp_set_option_autoload_values' ) ) {
			return; // WP < 6.4: la escritura nueva ya nace con autoload.
		}

		wp_set_option_autoload_values( array_fill_keys( self::autoloaded_options(), true ) );
	}

	/**
	 * Mueve el estado que acredita al nuevo default, UNA sola vez (1.3.0).
	 *
	 * Cambiar el default no basta: el guardado del panel escribe todas las options,
	 * por lo que se migra explícitamente el valor previo para asegurar paridad.
	 *
	 * Sólo mueve el valor VIEJO de fábrica: si el dueño eligió otro estado a
	 * mano, se respeta. Y corre una vez por versión de esquema, así que
	 * volver a poner «Listo para enviar» desde el panel es definitivo.
	 *
	 * @return void
	 */
	private static function migrate_award_status(): void {
		$guardado = get_option( 'akb_rewards_award_status', '' );

		if ( 'shipping-progress' === (string) $guardado ) {
			update_option( 'akb_rewards_award_status', Options::DEFAULT_AWARD_STATUS, true );
		}
	}

	/**
	 * Ejecuta dbDelta con el schema vigente.
	 *
	 * @return void
	 */
	private static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::ledger_table();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			delta INT NOT NULL,
			balance_after INT NOT NULL,
			action VARCHAR(40) NOT NULL,
			ref_type VARCHAR(20) NOT NULL DEFAULT '',
			ref_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			event_key VARCHAR(191) DEFAULT NULL,
			note VARCHAR(255) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY event_key (event_key),
			KEY user_created (user_id, created_at),
			KEY ref (ref_type, ref_id)
		) {$charset};";

		dbDelta( $sql );

		// Tokens de invitación a reseñar (Fase 2b): un enlace por pedido,
		// calcado del mecanismo del tercero (cr_local_forms) pero con
		// caducidad y un solo uso auditables. token único; el pedido puede
		// tener más de un token en su historia (re-invitación tras reversa).
		$forms = self::review_forms_table();

		$sql_forms = "CREATE TABLE {$forms} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			order_id BIGINT UNSIGNED NOT NULL,
			token VARCHAR(64) NOT NULL,
			expires_at DATETIME NOT NULL,
			used_at DATETIME DEFAULT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token (token),
			KEY order_id (order_id)
		) {$charset};";

		dbDelta( $sql_forms );
	}

	/**
	 * Comprueba que la tabla exista de verdad.
	 *
	 * @return bool
	 */
	private static function schema_ready(): bool {
		global $wpdb;

		foreach ( array( self::ledger_table(), self::review_forms_table() ) as $table ) {
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
				return false;
			}
		}

		return true;
	}
}


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/src/Support/SettingsPage.php
================================================================

<?php
/**
 * SettingsPage — Panel de configuración de montos y superficies del
 * programa de fidelización y reseñas.
 *
 * Una sola pantalla bajo WooCommerce, en el idioma de la tienda: cada campo
 * es una option con default (los defaults replican la config de prod de los
 * terceros que reemplazamos), guardado por admin-post con nonce+capability y
 * sanitización por tipo. Sin JS, sin frameworks: liviana.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Support;

use Akibara\Rewards\Points\Calculator;
use Akibara\Rewards\Reviews\AdminOverview;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.2.0
 */
final class SettingsPage {

	public const CAPABILITY = 'manage_woocommerce';
	public const ACTION     = 'akb_rewards_save_settings';
	public const SLUG       = 'akb-rewards-settings';

	/**
	 * Avisos de un minuto del último guardado, POR USUARIO y con payload
	 * (`[ ['tipo' => 'error'|'warning', 'texto' => '…'], … ]`), como los de
	 * AdminAdjust. La marca global anterior se comía el aviso del otro cuando
	 * dos administradores guardaban a la vez.
	 */
	public const TRANSIENT_AVISOS = 'akb_rw_avisos_';

	/**
	 * Vara del ejemplo en pesos de los avisos.
	 *
	 * Es una cifra redonda de referencia: un valor de $10.000 deja el cálculo
	 * transparente e independiente de variaciones en el catálogo.
	 */
	private const EJEMPLO_COMPRA = 10000;

	/**
	 * Ajustes que se leen dentro del HTML de CADA ficha y le dicen algo al
	 * cliente que tiene que ser verdad: cambiar uno lanza la purga TOTAL.
	 *
	 * La copia de invitado de una ficha vive en DOS sitios: LiteSpeed en el
	 * origen y Cloudflare en el borde (hasta 10 minutos; ver EdgePurge en
	 * akibara-business). En esta zona sólo `litespeed_purge_all` llega al borde:
	 * es la única llamada de LiteSpeed a Cloudflare (`purge_everything`,
	 * dentro de `Purge::_purge_all()`), y producción la tiene encendida.
	 * Su precio es alto —vacía también el CSS/JS minificado, la caché de objetos
	 * y OPcache, y enfría el borde entero—, así que se paga sólo por una promesa:
	 *
	 * - El aviso «Gana N Puntos Akibara» y todo lo que su promesa lee: con la
	 *   tasa cambiada en el panel y la ficha sin purgar, la ficha prometía una
	 *   cifra y la acreditación daba otra. Incluye los interruptores de los
	 *   carriles: con «Dar puntos por compra» apagado la promesa es cero y el
	 *   aviso no sale. La suite pinta el aviso y exige que todo lo que lee del
	 *   panel esté aquí, y no en FORMA_DE_CADA_FICHA.
	 * - El aviso de divulgación en el listado de reseñas: le dice al comprador
	 *   que la reseña ganó puntos.
	 */
	private const PROMESA_EN_CADA_FICHA = array(
		'akb_rewards_disclosure_enabled',
		'akb_rewards_pdp_notice_enabled',
		'akb_rewards_earn_enabled',
		'akb_rewards_earn_points',
		'akb_rewards_earn_amount',
		'akb_rewards_redeem_value',
		'akb_rewards_welcome_enabled',
		'akb_rewards_welcome_bonus',
		'akb_rewards_first_purchase_min',
		'akb_rewards_program_start',
	);

	/**
	 * Ajustes de presentación compartidos por las fichas. Un cambio invalida
	 * el HTML de origen mediante `litespeed_purge` con `*`, sin vaciar assets,
	 * caché de objetos ni OPcache. La portada se invalida con Carousel::flush().
	 */
	private const FORMA_DE_CADA_FICHA = array(
		'akb_rewards_schema_exclude_rewarded',
		'akb_rewards_carousel_title',
	);

	/**
	 * Catálogo de campos: option → [tipo, default, etiqueta, ayuda, sección].
	 * ÚNICA fuente de la pantalla y del guardado — añadir una option nueva es
	 * añadir UNA fila acá. Opcionales: `min`/`max` de un entero y `largo` de un
	 * texto; cada uno pone el tope en el formulario y en el guardado a la vez.
	 *
	 * @var array<string,array{t:string,d:mixed,l:string,h:string,s:string,min?:int,max?:int,largo?:int}>
	 */
	private const FIELDS = array(
		'akb_rewards_earn_enabled' => array(
			't' => 'yesno',
			'd' => 'yes',
			'l' => 'Dar puntos por compra',
			'h' => 'Enciéndelo o apágalo y guarda los ajustes: el cambio se aplica al guardar en la tienda, los correos y Mi cuenta. Los montos guardados se conservan para cuando lo vuelvas a encender.',
			's' => 'Puntos por compra',
		),
		'akb_rewards_earn_points'              => array(
			't'   => 'int',
			'd'   => 2,
			// 10x lo vigente (2). Con el tramo de $100 eso devuelve el 20 %, que
			// sólo entra si el techo de devolución de abajo lo permite: quien
			// frena antes es la guarda de razón. Este tope es sólo la baranda
			// contra el tecleo (200, 2000).
			'max' => 20,
			'l'   => 'Puntos por tramo de compra',
			'h'   => 'Con 2 puntos por cada $100, una compra de $10.000 gana 200. El techo de devolución de más abajo manda sobre este máximo.',
			's'   => 'Puntos por compra',
		),
		'akb_rewards_earn_amount'              => array(
			't'   => 'int',
			'd'   => 100,
			// El peligro de este campo va hacia ABAJO (tramo de $1 = 200 % de
			// devolución) y lo cubre la guarda de razón, no un max. Hacia
			// arriba, lo absurdo es un tramo mayor que cualquier compra de la
			// tienda (el tomo más caro publicado son $83.000). Para apagar la
			// acumulación está el 0 y el interruptor del carril.
			'max' => 1000000,
			'l'   => 'Tamaño del tramo ($)',
			'h'   => 'Tramo en pesos de la acumulación. 0 APAGA los puntos por compra (nadie acredita).',
			's'   => 'Puntos por compra',
		),
		'akb_rewards_redeem_min_points' => array(
			't'   => 'int',
			'd'   => 100,
			// Derivado, no inventado: 5.000 puntos a $1 son los $5.000 del tope
			// de descuento por pedido. El saldo más alto de la tienda hoy son
			// 2.658 puntos.
			'max' => 5000,
			'l'   => 'Mínimo de puntos por canje',
			'h'   => 'El cliente no puede canjear menos que esto de una vez. 0 = sin mínimo.',
			's'   => 'Puntos por compra',
		),
		'akb_rewards_redeem_max_pct' => array(
			't' => 'int',
			'd' => \Akibara\Rewards\Support\Options::DEFAULT_REDEEM_MAX_PCT,
			// Es un porcentaje: el propio formulario impide guardar fuera de
			// rango, no sólo el accessor.
			'min' => 0,
			'max' => 100,
			'l' => 'Máximo del pedido que se paga con puntos (%)',
			'h' => 'Cuánto de un pedido puede cubrir el cliente con puntos. Con 50, en una compra de $40.000 puede usar hasta $20.000 en puntos y el resto lo paga. 0 = sin tope (podría pagar el pedido entero con puntos). El despacho nunca se paga con puntos.',
			's' => 'Puntos por compra',
		),
		'akb_rewards_redeem_max_amount' => array(
			't'   => 'int',
			'd'   => \Akibara\Rewards\Support\Options::DEFAULT_REDEEM_MAX_AMOUNT,
			'min' => 0,
			// 10x el vigente. Con el tomo promedio en $14.334, $50.000 ya paga
			// un carrito de tres tomos entero.
			'max' => 50000,
			'l'   => 'Máximo de descuento por pedido ($)',
			'h'   => 'Máximo descuento en pesos que el cliente puede canjear por compra. Si pones los dos topes, manda el más estricto. 0 = sin tope en pesos.',
			's'   => 'Puntos por compra',
		),
		'akb_rewards_redeem_min_cart'          => array(
			't'   => 'int',
			'd'   => 50000,
			'min' => 0,
			// 10x lo decidido: por encima ya casi ninguna compra de la tienda
			// podría canjear (la primera compra más alta medida fueron $227.700).
			'max' => 500000,
			'l'   => 'Carrito mínimo para canjear ($)',
			'h'   => 'El canje de puntos se activa cuando los productos del carrito suman al menos este monto (con IVA y cupones, sin despacho). Es lo que permite regalar puntos sin que se farmeen: sólo se gastan dentro de una compra de verdad. 0 = sin mínimo.',
			's'   => 'Puntos por compra',
		),
		'akb_rewards_redeem_value'             => array(
			't'   => 'int',
			'd'   => 1,
			'min' => 1,
			// 10x lo vigente. Con la acumulación de hoy (2 por cada $100) un
			// punto a $10 devuelve el 20 %: lo frena la guarda de razón salvo
			// que el techo de abajo lo permita.
			'max' => 10,
			'l'   => 'Pesos de descuento por punto canjeado',
			'h'   => '1 = un peso por punto (IVA incluido, exacto en el total). El techo de devolución de abajo manda sobre este máximo.',
			's'   => 'Puntos por compra',
		),
		'akb_rewards_max_giveback_pct'         => array(
			't'   => 'int',
			'd'   => \Akibara\Rewards\Support\Options::DEFAULT_MAX_GIVEBACK_PCT,
			'min' => 1,
			'max' => \Akibara\Rewards\Support\Options::HARD_MAX_GIVEBACK_PCT,
			'l'   => 'Techo de devolución del programa (%)',
			'h'   => 'Cuánto de cada compra puede volver al cliente en puntos, juntando los tres campos de arriba (puntos por tramo ÷ tamaño del tramo × pesos por punto). Hoy la tienda devuelve el 2 %. Un guardado que supere este techo no entra: se conserva lo que había y se explica en pesos.',
			's'   => 'Puntos por compra',
		),
		'akb_rewards_welcome_enabled' => array(
			't' => 'yesno',
			'd' => 'yes',
			'l' => 'Dar bono de bienvenida (primera compra)',
			'h' => 'Enciéndelo o apágalo y guarda los ajustes: el cambio se aplica al guardar en la tienda, los correos y Mi cuenta. Los montos guardados se conservan para cuando lo vuelvas a encender.',
			's' => 'Puntos por compra',
		),
		'akb_rewards_welcome_bonus'            => array(
			't'   => 'int',
			'd'   => 500,
			// 10x lo vigente, y justo el máximo canjeable por pedido: un bono
			// por encima del techo promete una compra que el carrito no deja
			// pagar con puntos.
			'max' => 5000,
			'l'   => 'Bono de bienvenida',
			'h'   => 'Se paga con la PRIMERA compra de la persona, aunque «Dar puntos por compra» esté apagado. Uno por persona (ver los interruptores de abajo). Nunca por solo registrarse. 0 lo apaga.',
			's'   => 'Puntos por compra',
		),
		'akb_rewards_first_purchase_min'       => array(
			't'   => 'int',
			'd'   => 0,
			'min' => 0,
			// 10x la mediana de la primera compra medida en producción ($24.000):
			// por encima, el bono deja de ser de bienvenida.
			'max' => 240000,
			'l'   => 'Compra mínima para bono y referido ($)',
			'h'   => 'Si pones un monto, la primera compra tiene que sumar al menos eso en productos pagados con dinero (con IVA y cupones; sin despacho ni puntos) para dar el bono de bienvenida y el premio a quien invitó. 0 = sin mínimo (recomendado): basta con que no se pague entera con puntos; el freno contra el abuso lo pone el carrito mínimo para canjear.',
			's'   => 'Puntos por compra',
		),
		'akb_rewards_first_purchase_by_rut'    => array(
			't' => 'yesno',
			'd' => 'yes',
			'l' => 'Uno por persona: mirar el RUT',
			'h' => 'Si el RUT del pedido ya aparece en una compra anterior (desde el inicio del programa), no hay bono ni premio a quien invitó, aunque sea otra cuenta.',
			's' => 'Puntos por compra',
		),
		'akb_rewards_first_purchase_by_phone'  => array(
			't' => 'yesno',
			'd' => 'yes',
			'l' => 'Uno por persona: mirar el teléfono',
			'h' => 'Igual, con el teléfono (se compara el número sin +56 ni espacios). Apágalo si ves falsos positivos, por ejemplo un teléfono familiar compartido.',
			's' => 'Puntos por compra',
		),
		'akb_rewards_first_purchase_by_email'  => array(
			't' => 'yesno',
			'd' => 'yes',
			'l' => 'Uno por persona: mirar el correo',
			'h' => 'Igual, con el correo del pedido. La cuenta del cliente se compara siempre.',
			's' => 'Puntos por compra',
		),
		'akb_rewards_welcome_teaser_enabled'   => array(
			't' => 'yesno',
			'd' => 'yes',
			'l' => 'Mostrar «puntos esperándote»',
			'h' => 'Promete el bono en el registro y en Mi cuenta hasta que se active con la primera compra. Nada se paga por solo registrarse.',
			's' => 'Puntos por compra',
		),
		'akb_rewards_award_status'             => array(
			't' => 'status',
			'd' => Options::DEFAULT_AWARD_STATUS,
			'l' => 'Estado del pedido que acredita',
			'h' => 'Los puntos se pagan cuando el pedido llega a este estado o a uno posterior. «Procesando» = la pasarela confirmó el pago, así el cliente ve sus puntos apenas paga; «Listo para enviar» espera a que prepares el despacho. Si la venta se cae (cancelado, fallido o reembolsado), los puntos se retiran solos.',
			's' => 'Puntos por compra',
		),
		'akb_rewards_accrual_escrow_days'      => array(
			't'   => 'number',
			'min' => 0,
			'max' => 60,
			'd'   => Options::DEFAULT_ESCROW_DAYS,
			'l'   => 'Días de carencia para liberación de puntos',
			'h'   => 'Tiempo en días que los puntos ganados por compra permanecen retenidos antes de pasar a saldo disponible tras completarse el pedido. 0 = acreditación inmediata.',
			's'   => 'Puntos por compra',
		),
		'akb_rewards_program_start'            => array(
			't' => 'text',
			'd' => '2026-08-17 00:00:00',
			'l' => 'Inicio de vigencia del programa',
			'h' => 'Pedidos creados antes de esta fecha no acreditan (formato AAAA-MM-DD HH:MM:SS).',
			's' => 'Puntos por compra',
		),
		'akb_rewards_expiry_enabled' => array(
			't' => 'yesno',
			'd' => 'yes',
			'l' => 'Hacer vencer los puntos',
			'h' => 'Apagado: los puntos duran para siempre. Encendido: duran los meses de abajo y se avisa por correo antes. El cambio se aplica al guardar.',
			's' => 'Puntos por compra',
		),
		'akb_rewards_expiry_months'            => array(
			't'   => 'int',
			'd'   => 12,
			// 10x lo vigente: diez años. Para «no vencen jamás» están el 0 y el
			// interruptor.
			'max' => 120,
			'l'   => 'Meses de vida de los puntos',
			'h'   => 'Cuánto tiempo tiene el cliente para gastarlos. 0 = no vencen jamás. Vencen primero los más antiguos (FIFO).',
			's'   => 'Puntos por compra',
		),
		'akb_rewards_expiry_warn_days'         => array(
			't'   => 'int',
			'd'   => 30,
			// 12x lo vigente y un año redondo. Avisar con más anticipación que
			// la vida de los puntos manda el correo el día de la compra; la
			// guarda de coherencia lo frena antes de llegar acá.
			'max' => 365,
			'l'   => 'Aviso previo al vencimiento (días)',
			'h'   => 'Correo «tienes N puntos por vencer» con esta anticipación (máx. uno al mes por cliente). 0 = sin aviso.',
			's'   => 'Puntos por compra',
		),
		'akb_rewards_invite_enabled' => array(
			't' => 'yesno',
			'd' => 'yes',
			'l' => 'Invitar a reseñar por correo',
			'h' => 'Un correo con enlace personal por pedido pagado, después del despacho o retiro. Cambiar ajustes conserva las fechas ya programadas y no reenvía invitaciones anteriores.',
			's' => 'Reseñas',
		),
		'akb_rewards_invite_status' => array(
			't' => 'status',
			'd' => 'completed',
			'l' => 'Despacho: estado que inicia la espera',
			'h' => 'Elige el estado que usas al entregar el pedido al transportista, por ejemplo «Listo para enviar». Puedes elegir el estado de despacho que use tu operación, con cualquier transportista. «Procesando» sólo confirma pago, no despacho.',
			's' => 'Reseñas',
		),
		'akb_rewards_invite_delay_days' => array(
			't'   => 'int',
			'd'   => 3,
			// 10x lo vigente y la vida por defecto del enlace: invitar a
			// reseñar más de un mes después del pedido no lo lee nadie.
			'max' => 30,
			'l'   => 'Despacho: días corridos antes de invitar',
			'h'   => 'Se cuentan desde la entrada al estado de despacho, no desde la compra ni el pago. Es un plazo estimado de recepción. Las invitaciones ya programadas conservan su fecha.',
			's'   => 'Reseñas',
		),
		'akb_rewards_invite_pickup_status' => array(
			't' => 'status',
			'd' => 'completed',
			'l' => 'Entrega directa o retiro: estado que inicia la espera',
			'h' => 'Por ejemplo «Completado» cuando entregas personalmente el pedido o lo retiran. No necesita pasar por el estado de despacho. Si ya había una invitación en cola, conserva su fecha y no crea otra. Si eliges el mismo estado en ambas rutas, se usa el plazo de despacho.',
			's' => 'Reseñas',
		),
		'akb_rewards_invite_pickup_delay_days' => array(
			't' => 'int',
			'd' => 6,
			'max' => 30,
			'l' => 'Entrega directa o retiro: días corridos antes de invitar',
			'h' => 'Espera después de la entrega o retiro. Sólo se aplica si ésta fue la primera ruta que programó la invitación.',
			's' => 'Reseñas',
		),
		'akb_rewards_invite_token_days' => array(
			't'   => 'int',
			'd'   => 30,
			// 12x lo vigente. Un enlace personal que vive más de un año es un
			// token eterno en la bandeja del cliente.
			'max' => 365,
			'l'   => 'Vida del enlace de reseña (días)',
			'h'   => 'Pasado este plazo el enlace personal vence.',
			's'   => 'Reseñas',
		),
		'akb_rewards_reviews_enabled' => array(
			't' => 'yesno',
			'd' => 'yes',
			'l' => 'Dar puntos por reseña con foto',
			'h' => 'Enciéndelo o apágalo y guarda los ajustes: el cambio se aplica al guardar en la tienda, los correos y Mi cuenta. Los montos guardados se conservan para cuando lo vuelvas a encender.',
			's' => 'Reseñas',
		),
		'akb_rewards_review_points'            => array(
			't'   => 'int',
			'd'   => 500,
			// 10x lo vigente y el máximo canjeable por pedido: una reseña no
			// puede pagar más que el techo de un canje.
			'max' => 5000,
			'l'   => 'Puntos por reseña con foto aprobada',
			'h'   => 'La invitación por correo solicita puntos y exige foto. Se acreditan al aprobar una reseña elegible. Las nuevas reseñas voluntarias de la ficha admiten foto opcional y no generan puntos; las anteriores conservan su política vigente. 0 apaga el programa de reseñas.',
			's'   => 'Reseñas',
		),
		'akb_rewards_review_every_n'           => array(
			't'   => 'int',
			'd'   => 1,
			// El min 1 es el que FALTABA: el panel dejaba guardar 0 y
			// Options::review_every_n() leía 1 — la pantalla mostraba un
			// número que no regía. El max 10: premiar una de cada diez ya es
			// no premiar, y para apagarlo está «Puntos por reseña» en 0.
			'min' => 1,
			'max' => 10,
			'l'   => 'Premiar cada cuántas reseñas',
			'h'   => '1 = se premia cada reseña elegible. 3 = se premia la tercera, la sexta… (las intermedias marcan su avance). La reseña de invitación debe tener foto y cumplir los requisitos. Las nuevas reseñas voluntarias de la ficha no generan puntos; las anteriores conservan su política vigente.',
			's'   => 'Reseñas',
		),
		'akb_rewards_review_max_per_user'      => array(
			't'   => 'int',
			'd'   => 0,
			// 10x el valor VIVO en producción (5), no el default del catálogo
			// (0 = sin tope): medido contra la fila real para que el primer
			// guardado no le baje el número al dueño.
			'max' => 50,
			'l'   => 'Tope de premios por cliente',
			'h'   => '0 = sin tope. Con 5, un cliente puede conservar hasta 5 premios de reseña. Retirar una reseña premiada revierte sus puntos y libera ese cupo.',
			's'   => 'Reseñas',
		),
		'akb_rewards_review_require_verified'  => array(
			't' => 'yesno',
			'd' => 'yes',
			'l' => 'Exigir compra verificada',
			'h' => 'Solo premia reseñas de quien compró ese producto en la tienda.',
			's' => 'Reseñas',
		),
		'akb_rewards_moderate_reviews'         => array(
			't' => 'yesno',
			'd' => 'yes',
			'l' => 'Moderación obligatoria',
			'h' => 'Exige aprobación en el formulario nativo de las fichas. Las reseñas recibidas por invitación siempre quedan pendientes. Se revisan en Productos → Valoraciones.',
			's' => 'Reseñas',
		),
		'akb_rewards_review_nudge_enabled'     => array(
			't' => 'yesno',
			'd' => 'yes',
			'l' => 'Invitación a reseñar en el correo',
			'h' => 'Un recordatorio informativo en los correos elegidos. La invitación con enlace personal se envía aparte, después del plazo de despacho o retiro.',
			's' => 'Reseñas',
		),
		'akb_rewards_nudge_email_ids'          => array(
			't' => 'emails',
			'd' => 'customer_completed_order',
			'l' => 'Correos que llevan la invitación',
			'h' => 'Marca los correos que llevan el recordatorio informativo de reseñas. Sin ninguno marcado, no aparece; esto no apaga la invitación automática con enlace personal.',
			's' => 'Reseñas',
		),
		'akb_rewards_disclosure_enabled'       => array(
			't' => 'yesno',
			'd' => 'no',
			'l' => 'Avisar en la reseña que ganó puntos',
			'h' => 'Añade bajo la reseña premiada «Quien escribió esta reseña ganó Puntos Akibara por publicarla con foto» en la ficha. El carrusel tiene un control adicional para mostrar u ocultar este aviso sólo en la portada. Permite que quien la lea sepa que recibió un incentivo, independientemente de las estrellas que haya dado.',
			's' => 'Reseñas',
		),
		'akb_rewards_schema_exclude_rewarded'  => array(
			't' => 'yesno',
			'd' => 'no',
			'l' => 'Sacar de Google el texto de las reseñas premiadas',
			// El filtro de JSON-LD es independiente del aviso visible en la ficha.
			'h' => 'Este interruptor no cambia lo que se ve en la ficha: sólo decide si el texto de las reseñas premiadas viaja en los datos que Google lee de cada ficha. La nota media y el total de reseñas las siguen contando, porque son los mismos números que se ven en la ficha. Por defecto viaja.',
			's' => 'Reseñas',
		),
		'akb_rewards_referral_enabled'         => array(
			't' => 'yesno',
			'd' => 'yes',
			'l' => 'Programa de referidos',
			'h' => 'Cada cliente tiene su enlace en Mi cuenta → Puntos Akibara.',
			's' => 'Referidos',
		),
		'akb_rewards_referral_points'          => array(
			't'   => 'int',
			'd'   => 500,
			// 10x lo vigente y el máximo canjeable por pedido.
			'max' => 5000,
			'l'   => 'Puntos para quien invita',
			'h'   => 'Se pagan cuando la primera compra del invitado llega al estado de abajo. Jamás por solo registrarse, y nunca si el invitado es la misma persona que invita (mismo RUT, teléfono o correo). 0 lo apaga.',
			's'   => 'Referidos',
		),
		'akb_rewards_referral_status'          => array(
			't' => 'status',
			'd' => 'completed',
			'l' => 'Estado que paga a quien invitó',
			'h' => 'El premio se paga cuando la primera compra del invitado ENTRA a este estado. Usa «Completado» si así registras una entrega o retiro. Si después se anula o se reembolsa por completo, el premio se descuenta.',
			's' => 'Referidos',
		),
		'akb_rewards_referral_cookie_days'     => array(
			't'   => 'int',
			'd'   => 30,
			// No es umbral del absurdo: es el rango que
			// Options::referral_cookie_days() YA impone y que la ayuda del
			// campo ya promete. El panel aceptaba 0 o 9999 y mostraba un
			// valor que el motor ignoraba.
			'min' => 1,
			'max' => 90,
			'l'   => 'Días de memoria del enlace',
			'h'   => 'Cuántos días vale la visita con el enlace antes de crear la cuenta (1-90).',
			's'   => 'Referidos',
		),
		'akb_rewards_referral_max_per_user'    => array(
			't'   => 'int',
			'd'   => 0,
			// 10x el valor VIVO en producción (10).
			'max' => 100,
			'l'   => 'Tope de premios por padrino',
			'h'   => '0 = sin tope. Cuentan los premios que conserva: si el pedido de un invitado se anula y el premio se descuenta, ese cupo vuelve a quedar libre.',
			's'   => 'Referidos',
		),
		'akb_rewards_birthday_enabled' => array(
			't' => 'yesno',
			'd' => 'yes',
			'l' => 'Dar regalo de cumpleaños',
			'h' => 'Enciéndelo o apágalo y guarda los ajustes: el cambio se aplica al guardar en la tienda, los correos y Mi cuenta. Los montos guardados se conservan para cuando lo vuelvas a encender.',
			's' => 'Cumpleaños',
		),
		'akb_rewards_birthday_points'          => array(
			't'   => 'int',
			'd'   => 1000,
			// 10x lo vigente.
			'max' => 10000,
			'l'   => 'Regalo de cumpleaños',
			'h'   => 'Puntos una vez al año, con el día y mes que el cliente registre en Mi cuenta. 0 lo apaga.',
			's'   => 'Cumpleaños',
		),
		'akb_rewards_carousel_enabled'         => array(
			't' => 'yesno',
			'd' => 'yes',
			'l' => 'Carrusel del home',
			'h' => 'La sección de reseñas de la portada.',
			's' => 'Carrusel del home',
		),
		'akb_rewards_carousel_disclosure_enabled' => array(
			't' => 'yesno',
			'd' => 'no',
			'l' => 'Mostrar también en el carrusel el aviso de puntos',
			'h' => 'Si el aviso de puntos está activado en Reseñas, lo muestra también bajo las reseñas premiadas de la portada. Apagar esta casilla sólo lo oculta en el carrusel: conserva el aviso en las fichas y las etiquetas de reseñas transcritas.',
			's' => 'Carrusel del home',
		),
		'akb_rewards_carousel_min'             => array(
			't'   => 'int',
			'min' => 1,
			'd'   => 8,
			// 10x lo vigente. Un umbral alto deja la sección apagada para
			// siempre sin decirlo; para apagarla está el interruptor.
			'max' => 80,
			'l'   => 'Mínimo de reseñas para aparecer',
			'h'   => 'Se cuenta después de aplicar el tipo de reseña elegido. Bajo este mínimo, la sección no aparece. Usa 1 para mostrarla desde la primera reseña elegible.',
			's'   => 'Carrusel del home',
		),
		'akb_rewards_carousel_media'           => array(
			't'       => 'select',
			'd'       => 'mixed',
			'l'       => 'Reseñas que se muestran',
			'h'       => 'Se muestran primero las más recientes aprobadas. «Con foto» se refiere a una foto adjunta a la reseña; la portada del producto no cuenta como foto del cliente. Publicar una reseña aquí no le otorga puntos.',
			'choices' => array(
				'mixed'  => 'Mixto: con y sin fotos',
				'photos' => 'Sólo reseñas con foto',
				'text'   => 'Sólo reseñas sin foto',
			),
			's'       => 'Carrusel del home',
		),
		'akb_rewards_carousel_min_chars'       => array(
			't'   => 'int',
			'min' => 1,
			'd'   => 40,
			// 10x lo vigente. 400 caracteres es un ensayo: deja el carrusel
			// vacío.
			'max' => 400,
			'l'   => 'Largo mínimo del texto',
			'h'   => 'Caracteres mínimos para que una reseña sea elegible.',
			's'   => 'Carrusel del home',
		),
		'akb_rewards_carousel_cards'           => array(
			't'   => 'int',
			'min' => 1,
			'max' => 20,
			'd'   => 8,
			'l' => 'Tarjetas máximas',
			'h' => 'Cuántas reseñas se pintan (1-20).',
			's' => 'Carrusel del home',
		),
		'akb_rewards_carousel_title'           => array(
			't'     => 'text',
			'd'     => 'Voces del Distrito',
			// El encabezado admite 60 caracteres; se limita en el formulario y
			// en el guardado, que informa si hubo recorte.
			'largo' => 60,
			// Comparte el título con la sección de reseñas de cada ficha.
			'l'     => 'Título de las reseñas (portada y fichas)',
			'h'     => 'Corto: es el encabezado de la sección de reseñas de cada ficha de producto y el título del carrusel de reseñas de la portada. El carrusel sólo sale en la portada cuando hay al menos tantas reseñas como pide «Mínimo de reseñas para aparecer», más arriba. Vacío = «Voces del Distrito».',
			's'     => 'Carrusel del home',
		),
		'akb_rewards_carousel_exclude_rewarded' => array(
			't' => 'yesno',
			'd' => 'no',
			'l' => 'Excluir reseñas premiadas con puntos',
			'h' => 'Por defecto entran (tú ya las curaste al aprobarlas).',
			's' => 'Carrusel del home',
		),
		'akb_rewards_pdp_notice_enabled'       => array(
			't' => 'yesno',
			'd' => 'yes',
			'l' => 'Aviso «Gana N puntos» en la ficha',
			// PdpNotice usa el formulario de compra y, si no existe, el resumen.
			'h' => 'Bajo el botón de compra; en un tomo agotado, que no tiene botón, bajo el precio. Con la matemática real del programa.',
			's' => 'Ficha de producto',
		),
		'akb_rewards_alerts_enabled'           => array(
			't' => 'yesno',
			'd' => 'yes',
			'l' => 'Avisarme cuando algo necesite revisión',
			'h' => 'Un correo cuando algo del programa no se arregla solo: un canje cobrado sin saldo suficiente, una devolución de puntos que quedó corta, un reembolso borrado, una reseña aprobada que no acreditó, o un correo al cliente que no salió tras tres intentos. Cada aviso dice qué hacer y trae el enlace directo. El mismo aviso no se repite en el día.',
			's' => 'Alertas',
		),
		'akb_rewards_alerts_email'             => array(
			't' => 'recipients',
			'd' => '',
			'l' => 'Correos que reciben las alertas',
			'h' => 'Separados por coma. Vacío = el correo de administración del sitio.',
			's' => 'Alertas',
		),
	);

	/**
	 * Correos de la tienda para elegir: id → título, tal como los lista
	 * WooCommerce → Ajustes → Correos electrónicos.
	 *
	 * @return array<string,string>
	 */
	public static function email_choices(): array {
		if ( ! function_exists( 'WC' ) || ! is_object( WC() ) ) {
			return array();
		}

		$lista = array();
		foreach ( (array) WC()->mailer()->get_emails() as $email ) {
			if ( ! is_object( $email ) || empty( $email->id ) ) {
				continue;
			}
			$titulo                        = isset( $email->title ) ? trim( (string) $email->title ) : '';
			$lista[ (string) $email->id ] = '' !== $titulo ? $titulo : (string) $email->id;
		}

		return $lista;
	}

	/**
	 * Los nombres de option del catálogo. Los consume la migración de
	 * autoload de Schema: una option nueva acá entra sola.
	 *
	 * @return array<int,string>
	 */
	public static function option_names(): array {
		return array_keys( self::FIELDS );
	}

	/**
	 * Cuelga menú y guardado.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_save' ) );
	}

	/**
	 * Load editing feedback only on this settings screen.
	 *
	 * @param string $hook Current admin screen.
	 * @return void
	 */
	public function enqueue_assets( string $hook ): void {
		if ( 'woocommerce_page_' . self::SLUG !== $hook ) {
			return;
		}
		wp_enqueue_script( 'akb-rewards-admin-settings', Assets::url( 'assets/js/admin-settings.js' ), array(), Assets::version( 'assets/js/admin-settings.js' ), true );
	}

	/**
	 * Submenú bajo WooCommerce.
	 *
	 * @return void
	 */
	public function add_menu(): void {
		add_submenu_page(
			'woocommerce',
			'Puntos y Reseñas (Akibara)',
			'Puntos y Reseñas',
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Pantalla: secciones con sus campos, del catálogo único.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		echo '<div class="wrap"><h1>Puntos y Reseñas</h1>';

		// Los rechazos del último guardado, antes del formulario: si una
		// baranda revierte un campo sin que se vea, es peor que no tenerla. Y
		// con un rechazo en pantalla el aviso verde cambia de frase: el resto
		// del formulario sí se guardó, pero «Ajustes guardados.» a secas debajo
		// de un «No se guardó…» era una contradicción (refutación 2026-09-12).
		$hubo_rechazo = false;
		$avisos       = get_transient( self::TRANSIENT_AVISOS . get_current_user_id() );
		if ( is_array( $avisos ) ) {
			delete_transient( self::TRANSIENT_AVISOS . get_current_user_id() );
			foreach ( $avisos as $aviso ) {
				if ( ! is_array( $aviso ) || '' === (string) ( $aviso['texto'] ?? '' ) ) {
					continue;
				}
				$hubo_rechazo = $hubo_rechazo || 'error' === ( $aviso['tipo'] ?? '' );
				// Por CONCATENACIÓN, no con printf: estos textos llevan
				// porcentajes y montos que escribe el programa, y la regla de
				// la casa mantiene los copys del dueño lejos de cualquier
				// formateador con «%».
				echo '<div class="notice notice-' . esc_attr( 'error' === ( $aviso['tipo'] ?? '' ) ? 'error' : 'warning' ) . '"><p>'
					. esc_html( (string) $aviso['texto'] ) . '</p></div>';
			}
		}
		if ( isset( $_GET['akb_rw_saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- solo pinta un aviso.
			echo '<div class="notice notice-success"><p>' . ( $hubo_rechazo ? 'Se guardó el resto de los ajustes.' : 'Ajustes guardados.' ) . '</p></div>';
		}
		( new AdminOverview() )->render();
		echo '<p class="description">Configura aquí los montos y las opciones del programa. Los cambios se aplican al guardar; las validaciones de seguridad siguen vigentes. Los cortes de pedidos por país se configuran en Productos → Atributos → País, y por editorial en Productos → Marcas.</p>';
		echo '<nav aria-label="Secciones de ajustes"><p>Ir a: ';
		$links = array();
		foreach ( array_unique( array_column( self::FIELDS, 's' ) ) as $section ) {
			$links[] = '<a href="#' . esc_attr( self::section_anchor( $section ) ) . '">' . esc_html( $section ) . '</a>';
		}
		echo implode( ' · ', $links ) . '</p></nav>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- links escaped individually.

		echo '<form data-akb-rewards-settings method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';

		submit_button( 'Guardar ajustes', 'primary', 'submit-top' );
		echo '<p data-akb-settings-status role="status" aria-live="polite">Los cambios se aplican al guardar.</p>';

		$seccion_abierta = '';
		foreach ( self::FIELDS as $option => $campo ) {
			if ( $campo['s'] !== $seccion_abierta ) {
				if ( '' !== $seccion_abierta ) {
					echo '</table>';
				}
				printf( '<h2 id="%s">%s</h2><table class="form-table" role="presentation">', esc_attr( self::section_anchor( $campo['s'] ) ), esc_html( $campo['s'] ) );
				$seccion_abierta = $campo['s'];
			}

			$valor = get_option( $option, 'akb_rewards_invite_pickup_delay_days' === $option ? \Akibara\Rewards\Reviews\Invitations::pickup_delay_days() : $campo['d'] );

			printf( '<tr><th scope="row"><label for="%1$s">%2$s</label></th><td>', esc_attr( $option ), esc_html( $campo['l'] ) );

			if ( 'yesno' === $campo['t'] ) {
				printf(
					'<label><input type="checkbox" name="%1$s" id="%1$s" value="yes"%2$s> Activado</label>',
					esc_attr( $option ),
					checked( 'yes', (string) $valor, false )
				);
			} elseif ( 'select' === $campo['t'] ) {
				printf( '<select name="%1$s" id="%1$s">', esc_attr( $option ) );
				foreach ( $campo['choices'] as $clave => $etiqueta ) {
					printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $clave ), selected( (string) $valor, $clave, false ), esc_html( $etiqueta ) );
				}
				echo '</select>';
			} elseif ( 'status' === $campo['t'] ) {
				// Selector de estados REALES de pedido (incluye estados custom
				// si algún día existen). Las claves viajan sin el prefijo wc-,
				// que es como las entrega woocommerce_order_status_changed.
				printf( '<select name="%1$s" id="%1$s">', esc_attr( $option ) );
				$estados = self::order_status_choices();
				// Un estado guardado que la tienda hoy no registra (el plugin que
				// lo aporta, apagado) se muestra en vez de perderse: sin esta
				// opción el navegador enviaba la primera de la lista y el guardado
				// movía la acreditación en silencio, sin llegar nunca a la rama que
				// conserva lo guardado y avisa. Lo mismo hace la lista de correos.
				if ( '' !== (string) $valor && ! array_key_exists( (string) $valor, $estados ) ) {
					$estados[ (string) $valor ] = (string) $valor . ' (este estado ya no está disponible)';
				}
				foreach ( $estados as $clave => $etiqueta ) {
					printf(
						'<option value="%1$s"%2$s>%3$s</option>',
						esc_attr( $clave ),
						selected( (string) $valor, $clave, false ),
						esc_html( $etiqueta )
					);
				}
				echo '</select>';
			} elseif ( 'int' === $campo['t'] ) {
				printf(
					'<input type="number" name="%1$s" id="%1$s" value="%2$s" min="%3$s"%4$s step="1" class="small-text">',
					esc_attr( $option ),
					esc_attr( (string) (int) $valor ),
					esc_attr( (string) (int) ( $campo['min'] ?? 0 ) ),
					isset( $campo['max'] ) ? ' max="' . esc_attr( (string) (int) $campo['max'] ) . '"' : ''
				);
			} elseif ( 'emails' === $campo['t'] ) {
				// Casillas con los correos REALES de la tienda: nadie tiene que
				// saber que «Pedido completado» se llama customer_completed_order.
				$marcados = array_filter( array_map( 'trim', explode( ',', (string) $valor ) ) );
				$opciones = self::email_choices();
				// Un correo guardado que la tienda ya no registra (un plugin
				// apagado) se muestra en vez de borrarse en silencio al guardar.
				foreach ( $marcados as $guardado ) {
					if ( ! isset( $opciones[ $guardado ] ) ) {
						$opciones[ $guardado ] = $guardado . ' (este correo ya no está disponible)';
					}
				}
				// El vacío viaja siempre: dice «el formulario llegó», para no
				// confundir «ninguna casilla» con «no se pintó la lista».
				printf( '<fieldset><input type="hidden" name="%s[]" value="">', esc_attr( $option ) );
				foreach ( $opciones as $id => $titulo ) {
					printf(
						'<label style="display:block;margin:0 0 4px"><input type="checkbox" name="%1$s[]" value="%2$s"%3$s> %4$s</label>',
						esc_attr( $option ),
						esc_attr( $id ),
						checked( in_array( $id, $marcados, true ), true, false ),
						esc_html( $titulo )
					);
				}
				echo '</fieldset>';
			} elseif ( 'recipients' === $campo['t'] ) {
				printf(
					'<input type="text" name="%1$s" id="%1$s" value="%2$s" class="regular-text" placeholder="%3$s" inputmode="email">',
					esc_attr( $option ),
					esc_attr( (string) $valor ),
					esc_attr( (string) get_option( 'admin_email', '' ) )
				);
			} else {
				// La fecha de vigencia lleva patrón y placeholder: el navegador
				// frena el formato incorrecto antes de que el servidor lo rechace.
				$extra = 'akb_rewards_program_start' === $option
					? ' placeholder="AAAA-MM-DD HH:MM:SS" pattern="\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}" inputmode="numeric"'
					: '';
				// El tope de largo de la fila, en las dos capas a la vez, como el
				// `max` de los enteros: el navegador no deja escribir más y el
				// guardado recorta lo que llegue por otra vía.
				if ( isset( $campo['largo'] ) ) {
					$extra .= ' maxlength="' . (int) $campo['largo'] . '"';
				}
				printf(
					'<input type="text" name="%1$s" id="%1$s" value="%2$s" class="regular-text"%3$s>',
					esc_attr( $option ),
					esc_attr( (string) $valor ),
					$extra // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literales fijos de la casa y un entero, sin datos de usuario.
				);
			}

			if ( '' !== $campo['h'] ) {
				// El tope se dice con el MISMO número que aplica el guardado.
				printf(
					'<p class="description">%s</p>',
					esc_html( $campo['h'] . ( isset( $campo['largo'] ) ? ' Hasta ' . (int) $campo['largo'] . ' caracteres.' : '' ) )
				);

				// Explica si el umbral de reseñas permite mostrar el carrusel.
				if ( 'akb_rewards_carousel_title' === $option ) {
					$encendido = Options::carousel_on();
					$evidencia = self::carousel_evidence_text(
						$encendido,
						$encendido ? ( new \Akibara\Rewards\Reviews\Carousel() )->eligible_seen() : 0,
						Options::carousel_min()
					);
					if ( '' !== $evidencia ) {
						printf( '<p class="description" style="color:#996800"><strong>%s</strong></p>', esc_html( $evidencia ) );
					}
				}

				// Contrasta el estado elegido con la actividad real de los pedidos.
				if ( 'status' === (string) $campo['t'] ) {
					$evidencia = self::status_evidence_text( self::status_counts_90d( (string) $valor ), (string) $valor, self::order_status_choices(), $option );
					if ( '' !== $evidencia ) {
						printf( '<p class="description" style="color:#996800"><strong>%s</strong></p>', esc_html( $evidencia ) );
					}
				}

				// Advierte en ambos campos si el aviso está activo sin correos elegidos.
				if ( in_array( $option, array( 'akb_rewards_review_nudge_enabled', 'akb_rewards_nudge_email_ids' ), true )
					&& 'yes' === (string) get_option( 'akb_rewards_review_nudge_enabled', 'yes' )
					&& array() === array_filter( array_map( 'trim', explode( ',', (string) get_option( 'akb_rewards_nudge_email_ids', 'customer_completed_order' ) ) ) ) ) {
					printf(
						'<p class="description" style="color:#996800"><strong>%s</strong></p>',
						esc_html( 'Ojo: está activado, pero no hay ningún correo marcado abajo, así que el párrafo no sale en ninguno. Marca al menos uno, o apaga el interruptor.' )
					);
				}

				// Explica la fecha futura o ilegible que suspende la acumulación.
				if ( 'akb_rewards_program_start' === $option ) {
					$estado_programa = \Akibara\Rewards\Points\Accrual::program_state();
					if ( \Akibara\Rewards\Points\Accrual::PROGRAMA_AGENDADO === $estado_programa ) {
						printf(
							'<p class="description" style="color:#996800"><strong>%s</strong></p>',
							esc_html( sprintf( 'Ojo: el programa empieza el %s. Hasta entonces nadie acumula puntos por comprar, y la tienda no los promete en la ficha, en Mi cuenta ni en la caja.', \Akibara\Rewards\Points\ProgramPage::start_label() ) )
						);
					} elseif ( \Akibara\Rewards\Points\Accrual::PROGRAMA_ILEGIBLE === $estado_programa ) {
						printf(
							'<p class="description" style="color:#b32d2e"><strong>%s</strong></p>',
							esc_html( 'La fecha guardada no se puede leer, así que HOY nadie está acumulando puntos y la tienda dejó de prometerlos. Escríbela de nuevo con el formato AAAA-MM-DD HH:MM:SS y guarda.' )
						);
					}
				}

				// Muestra el estado del aviso visible junto al filtro de JSON-LD.
				if ( 'akb_rewards_schema_exclude_rewarded' === $option
					&& 'yes' !== (string) get_option( 'akb_rewards_disclosure_enabled', 'no' ) ) {
					printf(
						'<p class="description" style="color:#996800"><strong>%s</strong></p>',
						esc_html( 'Ojo: hoy las reseñas premiadas se leen en la ficha SIN aviso de que ganaron puntos. El aviso se enciende en el interruptor de arriba.' )
					);
				}

				// Expone al dueño cuántos clientes pidieron dejar de recibir invitaciones.
				if ( 'akb_rewards_invite_enabled' === $option ) {
					$bajas = \Akibara\Rewards\Reviews\InviteOptOut::total();
					printf(
						'<p class="description">%s</p>',
						esc_html(
							0 === $bajas
								? 'Cada invitación lleva su propio enlace para que el cliente pida no recibir más. Nadie lo ha usado todavía.'
								: sprintf(
									1 === $bajas
										? '%s cliente pidió no recibir más invitaciones: a esa dirección ya no se le escribe.'
										: '%s clientes pidieron no recibir más invitaciones: a esas direcciones ya no se les escribe.',
									number_format_i18n( $bajas )
								)
						)
					);
				}
			}
			echo '</td></tr>';
		}
		echo '</table>';

		submit_button( 'Guardar ajustes' );
		echo '</form>';

		/**
		 * Bloques extra de la pantalla de ajustes (ajuste manual de puntos,
		 * futuros paneles). Dentro del .wrap, fuera del form de settings.
		 *
		 * @since 0.3.0
		 */
		do_action( 'akb_rewards_settings_after' );

		echo '</div>';
	}

	/**
	 * Guardado: sanea por tipo desde el MISMO catálogo.
	 *
	 * @return void
	 */
	public function handle_save(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( self::ACTION );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- el nonce se verifica en la línea de arriba.
		$this->save_fields( (array) wp_unslash( $_POST ) );

		// Invalida el transient del carrusel y el HTML de portada tras guardar.
		( new \Akibara\Rewards\Reviews\Carousel() )->flush();

		wp_safe_redirect( add_query_arg( 'akb_rw_saved', '1', admin_url( 'admin.php?page=' . self::SLUG ) ) );
		exit;
	}

	/**
	 * Núcleo del guardado: sanea por tipo desde el MISMO catálogo, aplica las
	 * barandas que miran a VARIOS campos a la vez, y recién entonces persiste.
	 *
	 * Sanea y valida todos los campos antes de persistirlos en el orden del
	 * catálogo. Las restricciones entre campos reciben la configuración completa.
	 *
	 * Separado del wrapper (capability, nonce, purga, redirect y `exit`) para
	 * poder invocarlo sin terminar la petición.
	 *
	 * @param array<string,mixed> $input Datos del formulario, ya des-escapados.
	 * @return array<string,mixed> Lo que quedó guardado, por option.
	 */
	public function save_fields( array $input ): array {
		$avisos   = array();
		$acotados = array();
		$valores  = $this->sanitize_fields( $input, $avisos, $acotados );

		$valores = $this->guard_giveback( $valores, $avisos );
		$valores = $this->guard_expiry_warning( $valores, $avisos );

		foreach ( $acotados as $option => $acotado ) {
			if ( (int) $valores[ $option ] === $acotado ) {
				$min      = (int) ( self::FIELDS[ $option ]['min'] ?? 0 );
				$max      = (int) self::FIELDS[ $option ]['max'];
				$avisos[] = array(
					'tipo'  => 'warning',
					'texto' => self::etiqueta( $option ) . ' admite entre ' . number_format_i18n( $min ) . ' y ' . number_format_i18n( $max ) . ': se guardó en ' . number_format_i18n( $acotado ) . '.',
				);
			}
		}

		// Captura las opciones incluidas en el HTML de las fichas para emitir
		// una sola invalidación si cambia cualquiera de ellas.
		$ficha_antes = array();
		foreach ( array_merge( self::PROMESA_EN_CADA_FICHA, self::FORMA_DE_CADA_FICHA ) as $option ) {
			$ficha_antes[ $option ] = (string) get_option( $option, (string) self::FIELDS[ $option ]['d'] );
		}
		// Las opciones del carrusel y el aviso de divulgación gobiernan su
		// contenido cacheado; la lista se obtiene del catálogo del panel.
		$resenas_antes = array(
			'akb_rewards_disclosure_enabled' => (string) get_option( 'akb_rewards_disclosure_enabled', 'no' ),
		);
		foreach ( array_keys( self::FIELDS ) as $option ) {
			if ( 0 === strpos( $option, 'akb_rewards_carousel_' ) ) {
				$resenas_antes[ $option ] = (string) get_option( $option, (string) self::FIELDS[ $option ]['d'] );
			}
		}

		foreach ( array_keys( self::FIELDS ) as $option ) {
			// autoload = true: son escalares diminutos que se leen en el camino
			// del cliente (ficha, Mi cuenta, carrito, correos). Ver
			// Schema::autoloaded_options().
			update_option( $option, $valores[ $option ], true );
		}

		// La purga total ya incluye la del origen: si cambió una promesa y
		// además la forma, basta con ella.
		if ( self::cambio_alguno( self::PROMESA_EN_CADA_FICHA, $ficha_antes, $valores ) ) {
			do_action( 'litespeed_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook público de LiteSpeed (tercero).
		} elseif ( self::cambio_alguno( self::FORMA_DE_CADA_FICHA, $ficha_antes, $valores ) ) {
			do_action( 'litespeed_purge', '*' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook público de LiteSpeed (tercero).
		}

		foreach ( $resenas_antes as $option => $antes ) {
			if ( $antes !== (string) $valores[ $option ] ) {
				// Invalida tanto el transient como el HTML de portada.
				( new \Akibara\Rewards\Reviews\Carousel() )->flush();
				break;
			}
		}

		if ( array() !== $avisos ) {
			set_transient( self::TRANSIENT_AVISOS . get_current_user_id(), $avisos, MINUTE_IN_SECONDS );
		}

		return $valores;
	}

	/**
	 * ¿Cambió en este guardado alguno de estos ajustes?
	 *
	 * @param array<int,string>    $options Ajustes a mirar.
	 * @param array<string,string> $antes   Lo que había, por option.
	 * @param array<string,mixed>  $valores Lo que quedó guardado, por option.
	 * @return bool
	 */
	private static function cambio_alguno( array $options, array $antes, array $valores ): bool {
		foreach ( $options as $option ) {
			if ( $antes[ $option ] !== (string) $valores[ $option ] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Primera pasada: el valor de cada campo del catálogo, saneado por tipo.
	 * NO escribe nada.
	 *
	 * @param array<string,mixed>                                $input    Formulario.
	 * @param array<int,array{tipo:string,texto:string}>         $avisos   Avisos para el dueño, por referencia.
	 * @param array<string,int>                                  $acotados Enteros acotados al rango del catálogo, por referencia.
	 * @return array<string,mixed> option => valor saneado.
	 */
	private function sanitize_fields( array $input, array &$avisos, array &$acotados = array() ): array {
		$valores = array();

		foreach ( self::FIELDS as $option => $campo ) {
			if ( 'yesno' === $campo['t'] ) {
				// Un checkbox apagado NO viaja en el POST: su ausencia es el
				// «no». Por eso se recorre el catálogo y no lo recibido.
				$valor = isset( $input[ $option ] ) && 'yes' === $input[ $option ] ? 'yes' : 'no';
			} elseif ( 'select' === $campo['t'] ) {
				$valor = isset( $input[ $option ] ) && is_string( $input[ $option ] ) ? $input[ $option ] : (string) get_option( $option, $campo['d'] );
				if ( ! array_key_exists( $valor, $campo['choices'] ) ) {
					$guardado = (string) get_option( $option, $campo['d'] );
					$valor    = array_key_exists( $guardado, $campo['choices'] ) ? $guardado : $campo['d'];
					$avisos[] = array( 'tipo' => 'warning', 'texto' => '«' . $campo['l'] . '» no contiene una opción válida: se conservó la selección anterior.' );
				}
			} elseif ( 'status' === $campo['t'] ) {
				// Rechaza estados nuevos desconocidos. Conserva un estado ya guardado
				// si falta temporalmente el plugin que lo registra, y avisa al dueño.
				$valor    = sanitize_key( (string) ( $input[ $option ] ?? '' ) );
				$guardado = (string) get_option( $option, (string) $campo['d'] );
				if ( ! array_key_exists( $valor, self::order_status_choices() ) ) {
					if ( '' !== $valor && $valor === $guardado ) {
						// Con la forma que la pantalla lee ({tipo, texto}): como
						// texto suelto, `render()` lo descartaba y el dueño nunca
						// veía por qué el estado no cambió.
						$avisos[] = array(
							'tipo'  => 'warning',
							'texto' => '«' . $campo['l'] . '» se quedó como estaba (' . $valor . '): ese estado no existe hoy en la tienda, seguramente porque el plugin que lo registra está desactivado.',
						);
					} else {
						$valor = $guardado !== '' && array_key_exists( $guardado, self::order_status_choices() )
							? $guardado
							: (string) $campo['d'];
					}
				}
			} elseif ( 'int' === $campo['t'] ) {
				$crudo = isset( $input[ $option ] ) ? (int) $input[ $option ] : (int) $campo['d'];
				$valor = self::clamp_int( $option, $crudo );
				if ( isset( $input[ $option ] ) && $valor !== $crudo ) {
					$acotados[ $option ] = $valor;
				}
			} elseif ( 'emails' === $campo['t'] ) {
				// Ninguna casilla marcada no viaja en el POST: es «ninguno».
				// Lo marcado se cruza con los correos que la tienda tiene de
				// verdad, en el orden de su lista.
				$valor = '';
				if ( isset( $input[ $option ] ) ) {
					$guardados = array_filter( array_map( 'trim', explode( ',', (string) get_option( $option, $campo['d'] ) ) ) );
					$opciones  = self::email_choices();
					if ( array() === $opciones ) {
						$valor = implode( ',', $guardados ); // Sin la lista no se puede juzgar: se conserva.
					} else {
						// Valen los correos de la tienda y los que ya estaban guardados
						// (el panel los muestra aunque su plugin esté apagado).
						$validos = array_values( array_unique( array_merge( array_keys( $opciones ), $guardados ) ) );
						$valor   = implode( ',', array_values( array_intersect( $validos, array_map( 'strval', (array) $input[ $option ] ) ) ) );
					}
				}
			} elseif ( 'recipients' === $campo['t'] ) {
				$descartados = array();
				$valor       = implode( ', ', Options::email_list( sanitize_text_field( (string) ( $input[ $option ] ?? '' ) ), $descartados ) );
				if ( array() !== $descartados ) {
					$avisos[] = array(
						'tipo'  => 'warning',
						'texto' => 'En «' . $campo['l'] . '» se descartó lo que no es un correo: ' . implode( ', ', $descartados ) . '.',
					);
				}
			} else {
				$valor = isset( $input[ $option ] ) ? sanitize_text_field( (string) $input[ $option ] ) : (string) $campo['d'];
				// Recorta por caracteres visibles sin rechazar el resto del formulario.
				// El aviso concatena el texto del dueño sin interpretarlo como formato.
				if ( isset( $campo['largo'] ) ) {
					$letras = self::letras_visibles( $valor );
					if ( count( $letras ) > (int) $campo['largo'] ) {
						$valor    = rtrim( implode( '', array_slice( $letras, 0, (int) $campo['largo'] ) ) );
						$avisos[] = array(
							'tipo'  => 'warning',
							'texto' => '«' . $campo['l'] . '» admite hasta ' . (int) $campo['largo'] . ' caracteres: se guardó recortado, como «' . $valor . '».',
						);
					}
				}
				// La fecha de vigencia la lee Accrual::program_allows() con
				// createFromFormat: un formato mal escrito BLOQUEABA toda la
				// acreditación en silencio (QA 2026-09-01). Se rechaza al
				// guardar, se conserva la vigente y se avisa en pantalla.
				if ( 'akb_rewards_program_start' === $option ) {
					// La misma lectura con la que el motor la va a interpretar: una
					// sola función, para que el panel no acepte lo que el motor rechaza.
					if ( null === \Akibara\Rewards\Points\Accrual::parse_program_start( $valor ) ) {
						$valor    = (string) get_option( $option, $campo['d'] );
						$avisos[] = array(
							'tipo'  => 'warning',
							'texto' => 'La fecha de «Inicio de vigencia del programa» no tiene el formato AAAA-MM-DD HH:MM:SS: se conservó la anterior. Con una fecha inválida nadie acreditaría puntos.',
						);
					}
				}
			}

			$valores[ $option ] = $valor;
		}

		return $valores;
	}

	/**
	 * Un texto ya saneado, partido en lo que el cliente ve como UN carácter.
	 *
	 * Las entidades HTML reconocidas se cuentan como un carácter y nunca se
	 * parten. El resto se cuenta por carácter Unicode, no por byte, para
	 * aplicar el límite sobre el texto visible en el formulario.
	 *
	 * @param string $texto Texto saneado, tal como se guardaría.
	 * @return string[]
	 */
	private static function letras_visibles( string $texto ): array {
		$letras = array();
		foreach ( preg_split( '/(&#?[a-z0-9]+;)/i', $texto, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY ) ?: array() as $trozo ) {
			if ( '&' === $trozo[0] && html_entity_decode( $trozo, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) !== $trozo ) {
				$letras[] = $trozo;
				continue;
			}
			array_push( $letras, ...mb_str_split( $trozo, 1, 'UTF-8' ) );
		}

		return $letras;
	}

	/**
	 * Acota un entero al rango que declara su fila del catálogo. Es la MISMA
	 * cuenta que hace el navegador con los atributos `min`/`max` del campo:
	 * añadir el dato a la fila enciende las dos capas de una vez, y el
	 * recorrido de PHP defiende del POST hecho con curl o desde las devtools.
	 *
	 * @param string $option Option del catálogo.
	 * @param int    $valor  Valor pretendido.
	 * @return int
	 */
	private static function clamp_int( string $option, int $valor ): int {
		$campo = self::FIELDS[ $option ];
		$valor = max( (int) ( $campo['min'] ?? 0 ), $valor );
		if ( isset( $campo['max'] ) ) {
			$valor = min( (int) $campo['max'], $valor );
		}
		if ( 'akb_rewards_redeem_value' === $option && $valor <= 0 ) {
			$valor = 1; // El motor cae a 1: la pantalla no puede mostrar un 0 que no rige.
		}

		return $valor;
	}

	/**
	 * BARANDA DE LA RAZÓN: cuántos pesos devuelve la tienda por cada 100
	 * gastados. No la fija ningún campo por separado sino los TRES a la vez
	 * (puntos por tramo ÷ tamaño del tramo × pesos por punto), y por eso
	 * ningún `max` por campo la cubre: con el «2» de siempre y un tramo de $1
	 * la devolución es del 200 % sin que ningún número se vea raro.
	 *
	 * El techo entra en la misma baranda como cuarto campo: bajarlo por
	 * debajo de la razón vigente es tan contradictorio como subir la razón por
	 * encima de él, y se conserva el techo que había. La comparación es en
	 * ENTEROS (Calculator::giveback_exceeds): en float, el 15 % clavado a veces
	 * «superaba» al 15.
	 *
	 * @param array<string,mixed>                        $valores Valores saneados.
	 * @param array<int,array{tipo:string,texto:string}> $avisos  Avisos, por referencia.
	 * @return array<string,mixed>
	 */
	private function guard_giveback( array $valores, array &$avisos ): array {
		$campos = array( 'akb_rewards_earn_points', 'akb_rewards_earn_amount', 'akb_rewards_redeem_value', 'akb_rewards_max_giveback_pct' );

		return $this->aplicar_baranda(
			$valores,
			$avisos,
			$campos,
			static function ( array $v ): bool {
				return Calculator::giveback_exceeds(
					(int) $v['akb_rewards_earn_points'],
					(int) $v['akb_rewards_earn_amount'],
					(int) $v['akb_rewards_redeem_value'],
					(int) $v['akb_rewards_max_giveback_pct']
				);
			},
			static function ( array $v ): string {
				$puntos = Calculator::points_for_base(
					(float) self::EJEMPLO_COMPRA,
					(int) $v['akb_rewards_earn_points'],
					(int) $v['akb_rewards_earn_amount']
				);
				$pct = Calculator::giveback_pct(
					(int) $v['akb_rewards_earn_points'],
					(int) $v['akb_rewards_earn_amount'],
					(int) $v['akb_rewards_redeem_value']
				);

				return str_replace(
					array( '{compra}', '{puntos}', '{pesos}', '{pct}', '{techo}' ),
					array(
						self::pesos_texto( (float) self::EJEMPLO_COMPRA ),
						number_format_i18n( $puntos ),
						self::pesos_texto( (float) $puntos * (int) $v['akb_rewards_redeem_value'] ),
						self::pct_texto( $pct ),
						self::pct_texto( (float) (int) $v['akb_rewards_max_giveback_pct'] ),
					),
					'Con ese valor, una compra de {compra} devolvería {puntos} puntos, que valen {pesos} de descuento: el {pct} de la compra, sobre el techo de {techo} del programa.'
				);
			},
			'akb_rewards_earn_enabled'
		);
	}

	/**
	 * BARANDA DE COHERENCIA DEL VENCIMIENTO: no se puede avisar con más
	 * anticipación que la vida de los puntos.
	 *
	 * `Expiry::expiring_soon()` mira una ventana de N días: si N cubre la vida
	 * entera, el correo «tienes puntos por vencer» sale el día mismo en que se
	 * ganan.
	 *
	 * @param array<string,mixed>                        $valores Valores saneados.
	 * @param array<int,array{tipo:string,texto:string}> $avisos  Avisos, por referencia.
	 * @return array<string,mixed>
	 */
	private function guard_expiry_warning( array $valores, array &$avisos ): array {
		$campos = array( 'akb_rewards_expiry_warn_days', 'akb_rewards_expiry_months' );

		return $this->aplicar_baranda(
			$valores,
			$avisos,
			$campos,
			static function ( array $v ): bool {
				$meses = (int) $v['akb_rewards_expiry_months'];
				$dias  = (int) $v['akb_rewards_expiry_warn_days'];

				// 0 meses = no vencen jamás, y entonces el aviso no rige.
				return $meses > 0 && $dias > 0 && $dias > $meses * 30;
			},
			static function ( array $v ): string {
				return str_replace(
					array( '{dias}', '{meses}', '{vida}' ),
					array(
						number_format_i18n( (int) $v['akb_rewards_expiry_warn_days'] ),
						number_format_i18n( (int) $v['akb_rewards_expiry_months'] ),
						number_format_i18n( (int) $v['akb_rewards_expiry_months'] * 30 ),
					),
					'Avisar con {dias} días de anticipación cuando los puntos viven {meses} meses (unos {vida} días) manda el correo «tienes puntos por vencer» el mismo día en que el cliente los gana.'
				);
			},
			'akb_rewards_expiry_enabled'
		);
	}

	/**
	 * El mecanismo común de las dos barandas: si el conjunto de campos viola
	 * la regla, se REVIERTEN sólo los que cambiaron en este envío y el resto
	 * del formulario se guarda normal.
	 *
	 * Revertir sólo los cambiados es lo que hace que el aviso diga la verdad
	 * («no se guardó ESTO») en vez de deshacer trabajo que el dueño no tocó. Y
	 * si NINGUNO cambió —valor heredado escrito por WP-CLI o SQL, o una
	 * instalación anterior a la baranda— se guarda igual y sólo se advierte:
	 * si no, un valor heredado fuera de rango bloquearía para siempre
	 * cualquier otro guardado del panel, que es peor que el problema.
	 *
	 * Con el interruptor del carril APAGADO la regla no rige hoy (el accessor
	 * devuelve 0 y nadie acumula ni vence), así que tampoco se revierte: se
	 * guarda y se advierte, diciendo que no rige y que hay que corregirlo
	 * antes de encender. Rechazar ahí era afirmar una consecuencia que no
	 * ocurre (refutación 2026-09-12).
	 *
	 * @param array<string,mixed>                        $valores      Valores saneados.
	 * @param array<int,array{tipo:string,texto:string}> $avisos       Avisos, por referencia.
	 * @param array<int,string>                          $involucrados Options que forman la regla.
	 * @param callable                                   $viola        fn(array):bool — ¿se rompe la regla?
	 * @param callable                                   $diagnostico  fn(array):string — la consecuencia, en pesos.
	 * @param string                                     $carril       Option yes/no del interruptor que hace regir la regla.
	 * @return array<string,mixed>
	 */
	private function aplicar_baranda( array $valores, array &$avisos, array $involucrados, callable $viola, callable $diagnostico, string $carril ): array {
		if ( ! $viola( $valores ) ) {
			return $valores;
		}

		$diagnostico_intentado = (string) $diagnostico( $valores );

		if ( 'yes' !== (string) ( $valores[ $carril ] ?? 'yes' ) ) {
			$avisos[] = array(
				'tipo'  => 'warning',
				'texto' => 'Ojo: ' . $diagnostico_intentado . ' Hoy no rige, porque ' . self::etiqueta( $carril ) . ' está apagado; corrige ' . self::lista_etiquetas( $involucrados, 'o' ) . ' antes de encenderlo.',
			);

			return $valores;
		}

		$previos   = array();
		$cambiados = array();
		foreach ( $involucrados as $option ) {
			$previos[ $option ] = self::clamp_int( $option, (int) get_option( $option, self::FIELDS[ $option ]['d'] ) );
			if ( $previos[ $option ] !== (int) $valores[ $option ] ) {
				$cambiados[] = $option;
			}
		}

		if ( array() === $cambiados ) {
			$avisos[] = array(
				'tipo'  => 'warning',
				'texto' => $diagnostico_intentado . ' Este guardado no cambió esos campos, así que quedaron como estaban. Corrige ' . self::lista_etiquetas( $involucrados, 'o' ) . '.',
			);

			return $valores;
		}

		$conservado = array();
		foreach ( $cambiados as $option ) {
			$valores[ $option ] = $previos[ $option ];
			$conservado[]       = self::etiqueta( $option ) . ' en ' . number_format_i18n( $previos[ $option ] );
		}

		$texto = 'No se guardó ' . self::lista_etiquetas( $cambiados, 'ni' ) . '. '
			. $diagnostico_intentado
			. ' Se conservó lo que había: ' . implode( ', ', $conservado ) . '.';

		if ( $viola( $valores ) ) {
			$texto .= ' Ojo: lo que quedó guardado sigue teniendo el mismo problema.';
		}

		$avisos[] = array(
			'tipo'  => 'error',
			'texto' => $texto,
		);

		return $valores;
	}

	/**
	 * La etiqueta que el dueño ve en pantalla, entrecomillada.
	 *
	 * @param string $option Option del catálogo.
	 * @return string
	 */
	private static function etiqueta( string $option ): string {
		return '«' . self::FIELDS[ $option ]['l'] . '»';
	}

	/**
	 * Enumeración legible de etiquetas: «A», «B» y/o «C».
	 *
	 * @param array<int,string> $options Options del catálogo.
	 * @param string            $nexo    Conector final («o», «ni», «y»).
	 * @return string
	 */
	private static function lista_etiquetas( array $options, string $nexo ): string {
		$etiquetas = array_map( array( self::class, 'etiqueta' ), array_values( $options ) );
		if ( count( $etiquetas ) < 2 ) {
			return (string) ( $etiquetas[0] ?? '' );
		}

		$ultima = array_pop( $etiquetas );

		return implode( ', ', $etiquetas ) . ' ' . $nexo . ' ' . $ultima;
	}

	/**
	 * Pesos con separador de miles, para los avisos.
	 *
	 * @param float $monto Monto.
	 * @return string
	 */
	private static function pesos_texto( float $monto ): string {
		return '$' . number_format_i18n( round( $monto ) );
	}

	/**
	 * Porcentaje para los avisos. Se arma por CONCATENACIÓN, jamás con
	 * sprintf: un `%` en un texto que lee el dueño es un fatal esperando (y la
	 * regla de la casa lo prohíbe).
	 *
	 * @param float $pct Porcentaje.
	 * @return string
	 */
	private static function pct_texto( float $pct ): string {
		$redondeado = round( $pct, 1 );
		$decimales  = (float) (int) $redondeado === $redondeado ? 0 : 1;

		return number_format_i18n( $redondeado, $decimales ) . '%';
	}

	/**
	 * Estados de pedido elegibles para disparar la invitación, SIN el
	 * prefijo `wc-` (así los entrega `woocommerce_order_status_changed`,
	 * que es contra lo que compara Invitations::trigger_status()).
	 *
	 * Con WooCommerce vivo la lista es la REAL del sitio (estados custom
	 * incluidos); sin ella (suite), el trío estándar para que la pantalla
	 * no quede vacía ni el sanitizado deje pasar cualquier cosa.
	 *
	 * @return array<string,string> clave => etiqueta.
	 */
	public static function order_status_choices(): array {
		if ( function_exists( 'wc_get_order_statuses' ) ) {
			$choices = array();
			foreach ( wc_get_order_statuses() as $clave => $etiqueta ) {
				$clave = str_starts_with( (string) $clave, 'wc-' ) ? substr( (string) $clave, 3 ) : (string) $clave;
				// Estados terminales-negativos fuera: invitar a reseñar un
				// pedido cancelado/reembolsado/fallido no tiene sentido.
				if ( in_array( $clave, array( 'cancelled', 'refunded', 'failed', 'checkout-draft' ), true ) ) {
					continue;
				}
				$choices[ $clave ] = (string) $etiqueta;
			}

			if ( array() !== $choices ) {
				return $choices;
			}
		}

		return array(
			'processing' => 'Procesando',
			'completed'  => 'Completado',
			'on-hold'    => 'En espera',
		);
	}

	/**
	 * Cuántos pedidos están HOY en cada estado pagado —más el elegido, si es
	 * otro (p. ej. «En espera»)— de los creados en los últimos 90 días. Tres o
	 * cuatro consultas ligeras, sólo en la pantalla del panel.
	 *
	 * @param string $current El estado elegido en el campo.
	 * @return array<string, int> estado => pedidos.
	 */
	private static function status_counts_90d( string $current = '' ): array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}

		$estados = OrderStates::PAID;
		if ( '' !== $current && ! in_array( $current, $estados, true ) ) {
			$estados[] = $current;
		}

		$desde = gmdate( 'Y-m-d', time() - 90 * DAY_IN_SECONDS );
		$out   = array();
		foreach ( $estados as $estado ) {
			$resultado      = wc_get_orders(
				array(
					'status'       => $estado,
					'date_created' => '>=' . $desde,
					'limit'        => 1,
					'paginate'     => true,
					'return'       => 'ids',
				)
			);
			$out[ $estado ] = is_object( $resultado ) && isset( $resultado->total ) ? (int) $resultado->total : 0;
		}

		return $out;
	}

	/** Stable destinations for the overview and settings navigation. */
	private static function section_anchor( string $section ): string {
		$anchors = array( 'Reseñas' => 'reviews', 'Carrusel del home' => 'carousel', 'Puntos por compra' => 'points' );
		return 'akb-rewards-section-' . ( $anchors[ $section ] ?? md5( $section ) );
	}

	/**
	 * Describe current order counts without inferring their transition history.
	 *
	 * @param array<string, int>    $counts  estado => pedidos (90 días).
	 * @param string                $current Estado elegido.
	 * @param array<string, string> $labels  estado => etiqueta.
	 * @param string                $option Campo de estado que muestra el aviso.
	 * @return string '' si no hay datos.
	 */
	public static function status_evidence_text( array $counts, string $current, array $labels, string $option = 'akb_rewards_invite_status' ): string {
		if ( array() === $counts || array_sum( $counts ) <= 0 ) {
			return '';
		}

		$partes = array();
		foreach ( $counts as $estado => $n ) {
			$partes[] = ( $labels[ $estado ] ?? $estado ) . ' ' . number_format_i18n( $n );
		}
		$texto = 'Pedidos de los últimos 90 días, por estado actual: ' . implode( ' · ', $partes ) . '.';

		// El estado más frecuente hoy no demuestra por qué estados pasó cada
		// pedido ni cuál será su estado final.
		$terminal = '';
		$maximo   = 0;
		foreach ( OrderStates::PAID as $estado ) {
			if ( (int) ( $counts[ $estado ] ?? 0 ) > $maximo ) {
				$maximo   = (int) $counts[ $estado ];
				$terminal = $estado;
			}
		}

		$actual = (int) ( $counts[ $current ] ?? 0 );
		if ( '' !== $terminal && $terminal !== $current && $actual < $maximo && OrderStates::is_at_or_after( $current, $terminal ) ) {
			$nombre_actual   = $labels[ $current ] ?? $current;
			$nombre_terminal = $labels[ $terminal ] ?? $terminal;
			$contextos = array(
				'akb_rewards_invite_status'   => 'las invitaciones',
				'akb_rewards_invite_pickup_status' => 'las invitaciones de entrega directa o retiro',
				'akb_rewards_referral_status' => 'los premios de referidos',
			);
			$contexto = $contextos[ $option ] ?? 'la acreditación de puntos';
			$texto   .= ' Ojo: hoy hay ' . number_format_i18n( $actual ) . ' pedidos en «' . $nombre_actual . '» y ' . number_format_i18n( $maximo ) . ' en «' . $nombre_terminal . '». Revisa que el estado elegido para ' . $contexto . ' coincida con tu flujo. Estos conteos no indican por qué estados pasó cada pedido ni prueban que el proceso haya fallado.';
		}

		return $texto;
	}

	/**
	 * Función pura: bajo el título de las reseñas, si HOY la portada muestra
	 * el carrusel que la etiqueta nombra. Lo que se promete contra lo que se
	 * ve: con menos reseñas elegibles que el mínimo, el carrusel no se pinta y
	 * ese título sólo se lee en las fichas.
	 *
	 * @param bool $encendido ¿Está encendido el carrusel?
	 * @param int  $elegibles Reseñas elegibles que ve el carrusel (topadas en el umbral).
	 * @param int  $minimo    Mínimo de reseñas para aparecer.
	 * @return string '' cuando el carrusel sí sale.
	 */
	public static function carousel_evidence_text( bool $encendido, int $elegibles, int $minimo ): string {
		if ( ! $encendido ) {
			return 'Hoy el carrusel de la portada está apagado (casilla «Carrusel del home», más arriba): este título sólo se ve en las fichas.';
		}
		if ( $elegibles >= $minimo ) {
			return '';
		}

		if ( $elegibles <= 0 ) {
			$cuantas = 'ninguna reseña cumple';
		} elseif ( 1 === $elegibles ) {
			$cuantas = 'sólo 1 reseña cumple';
		} else {
			$cuantas = 'sólo ' . number_format_i18n( $elegibles ) . ' reseñas cumplen';
		}

		return 'Hoy el carrusel no sale en la portada: ' . $cuantas . ' las condiciones de esta sección y el mínimo es ' . number_format_i18n( $minimo ) . '. Mientras tanto, este título sólo se ve en las fichas.';
	}
}


================================================================
### ARCHIVO: public_html/wp-content/themes/akibara-gp/assets/css/reviews-p5-v4.css
================================================================

/* ==========================================================================
   Reseñas de la ficha — estética Persona 5 / manga (WooCommerce #reviews)

   Arquitectura:
   - Especificidad deliberada sin !important: reglas acotadas con la cadena de
     WooCommerce (.woocommerce #reviews #comments ol.commentlist li.review) para
     prevalecer limpiamente sobre el CSS base.
   - Ámbito estricto a #reviews para prevenir colisiones con .star-rating en
     fichas de producto o tarjetas de catálogo.
   - Textos y títulos semánticos respetando el árbol de accesibilidad (H2 real
     sin superposición artificial con pseudo-elementos).
   ========================================================================== */

/* Lista y tarjetas */
.woocommerce #reviews #comments ol.commentlist {
    padding: 0;
    margin: 0;
    list-style: none;
}

.woocommerce #reviews #comments ol.commentlist li.review {
    padding: 0;
    margin: 0 0 40px;
    border: none;
    width: 100%;
    box-sizing: border-box;
}

#reviews .commentlist li.review .comment_container {
    background-color: var(--aki-surface-2);
    border: 2px solid var(--aki-border-light);
    border-left: 5px solid var(--aki-red);
    border-radius: 0;
    box-shadow: 5px 5px 0 var(--aki-black);
    padding: 24px;
    margin: 10px 0 24px;
    position: relative;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
    display: flex;
    flex-direction: column;
    box-sizing: border-box;
    width: 100%;
    max-width: 100%;
}

#reviews .commentlist li.review:nth-child(even) .comment_container {
    border-color: var(--aki-red-bright);
    border-left: 5px solid var(--aki-white);
}

/* Sólo con puntero real: en un teléfono el :hover queda pegado tras el toque. */
@media (hover: hover) {
    #reviews .commentlist li.review .comment_container:hover {
        transform: translateY(-4px);
    }
}

/* Autor (estilo nota de rescate) */
#reviews .commentlist li.review .woocommerce-review__author {
    font-family: var(--font-heading);
    font-size: 1.25rem;
    font-weight: 400;
    font-synthesis: none;
    text-transform: uppercase;
    background: var(--aki-white);
    color: var(--aki-black);
    padding: 4px 10px;
    display: inline-block;
    transform: rotate(2deg);
    margin-bottom: 16px;
    box-shadow: 3px 3px 0 var(--aki-red-bright);
    line-height: 1;
}

/* Avatar anguloso. WooCommerce: (2,2,4) → aquí (2,3,4). */
.woocommerce #reviews #comments ol.commentlist li.review img.avatar {
    border-radius: 0;
    clip-path: polygon(10% 0, 100% 10%, 90% 100%, 0 90%);
    border: 2px solid var(--aki-red-bright);
    background: var(--aki-surface);
    padding: 2px;
    width: 60px;
    height: 60px;
    position: absolute;
    top: -15px;
    left: -15px;
    z-index: 3;
    box-shadow: 4px 4px 0 rgba(0, 0, 0, 0.5);
}

/* WooCommerce: (2,3,2) → aquí (2,4,2). */
.woocommerce #reviews #comments ol.commentlist li.review .comment-text {
    margin-left: 50px;
    border: none;
    padding: 0;
}

/* Estrellas de la reseña.
 * `--aki-red-text` y no `--aki-red-bright`: #FF0A1D sobre la tarjeta (#1A1A1A)
 * da 4,40:1 y reprueba AA para texto chico; el token de rojo legible sí pasa
 * (R12-resena-contraste). */
#reviews .commentlist li.review .star-rating span::before {
    color: var(--aki-red-text);
    text-shadow: 1px 1px 0 #000;
}

#reviews .star-rating {
    font-size: 1.2rem;
    transform: skewX(-10deg);
}

/* Las metas que WooCommerce escribe encima de la tarjeta oscura (fecha y
 * «(propietario verificado)») traían su gris de tema claro: #767676 sobre
 * #1A1A1A = 3,83:1. Gris del sistema oscuro, ≥ 8:1. */
#reviews .commentlist li.review .woocommerce-review__published-date,
#reviews .commentlist li.review .woocommerce-review__dash,
#reviews .commentlist li.review .woocommerce-review__verified {
    color: var(--aki-gray-300);
}

/* Texto de la reseña */
.woocommerce #reviews #comments ol.commentlist li.review .description {
    font-family: var(--font-body);
    font-size: 1rem;
    color: var(--aki-white);
    line-height: 1.6;
    font-style: italic;
    border-left: 4px solid var(--aki-red);
    padding-left: 16px;
    margin-top: 10px;
}
