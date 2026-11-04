<?php
/**
 * BacsLifecycleManager — Gestión del ciclo de vida de transferencias bancarias (BACS) v1.1.
 *
 * Resuelve el secuestro indefinido de stock físico y puntos en órdenes 'on-hold':
 * - Recordatorio a las 12 horas.
 * - Cancelación automática a las 24 horas (restituyendo stock, puntos y cupones).
 * - Prórroga de +48 horas de gracia si el cliente informa su comprobante («Ya Transferí»).
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\BacsLifecycle;

use Akibara\Business\Support\BusinessOptions;

defined( 'ABSPATH' ) || exit;

final class BacsLifecycleManager {

	public const GROUP           = 'akibara-business';
	public const ACTION_REMINDER = 'akb_bacs_send_reminder_email';
	public const ACTION_EXPIRE   = 'akb_bacs_auto_expire_order';

	public const META_ON_HOLD_SINCE     = '_akb_bacs_on_hold_since';
	public const META_RECEIPT_REPORTED  = '_akb_bacs_receipt_reported';
	public const META_RECEIPT_REFERENCE = '_akb_bacs_receipt_reference';

	public function register(): void {
		add_action( 'woocommerce_order_status_on-hold', array( $this, 'on_order_hold' ), 20, 2 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_status_changed' ), 20, 4 );
		add_action( self::ACTION_REMINDER, array( $this, 'send_reminder' ), 10, 1 );
		add_action( self::ACTION_EXPIRE, array( $this, 'expire_order' ), 10, 1 );

		add_action( 'wp_ajax_akb_submit_bacs_receipt', array( $this, 'ajax_submit_receipt' ) );
		add_action( 'wp_ajax_nopriv_akb_submit_bacs_receipt', array( $this, 'ajax_submit_receipt' ) );

		add_action( 'woocommerce_thankyou_bacs', array( $this, 'render_bacs_portal' ), 20, 1 );
		add_action( 'woocommerce_view_order', array( $this, 'render_bacs_portal' ), 20, 1 );
		add_action( 'woocommerce_email_before_order_table', array( $this, 'email_bacs_instructions' ), 10, 4 );
	}

	/**
	 * Limpia las acciones programadas de recordatorio y vencimiento cuando el pedido sale de on-hold.
	 *
	 * @param int            $order_id ID del pedido.
	 * @param string         $from     Estado anterior.
	 * @param string         $to       Estado nuevo.
	 * @param \WC_Order|null $order    Pedido.
	 */
	public function on_status_changed( $order_id, $from, $to, $order = null ): void {
		unset( $order );
		if ( 'on-hold' === (string) $from && 'on-hold' !== (string) $to ) {
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( self::ACTION_REMINDER, array( 'order_id' => (int) $order_id ), self::GROUP );
				as_unschedule_all_actions( self::ACTION_EXPIRE, array( 'order_id' => (int) $order_id ), self::GROUP );
			}
		}
	}

	public function on_order_hold( $order_id, $order = null ): void {
		if ( ! BusinessOptions::bacs_auto_cancel_enabled() ) {
			return;
		}

		if ( ! $order instanceof \WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order instanceof \WC_Order || 'bacs' !== $order->get_payment_method() ) {
			return;
		}

		$now = time();
		$order->update_meta_data( self::META_ON_HOLD_SINCE, $now );
		$order->save_meta_data();

		$reminder_secs = BusinessOptions::bacs_reminder_hours() * HOUR_IN_SECONDS;
		$expiry_secs   = BusinessOptions::bacs_expiry_hours() * HOUR_IN_SECONDS;

		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( $now + $reminder_secs, self::ACTION_REMINDER, array( 'order_id' => (int) $order_id ), self::GROUP );
			as_schedule_single_action( $now + $expiry_secs, self::ACTION_EXPIRE, array( 'order_id' => (int) $order_id ), self::GROUP );
		}
	}

	/**
	 * Retorna los datos bancarios para transferencia, extrayéndolos de la configuración oficial
	 * de WooCommerce (woocommerce_bacs_accounts) con fallback canónico a Akibara SpA.
	 *
	 * @return array{bank_name: string, account_type: string, account_number: string, rut: string, holder: string, email: string}
	 */
	public static function get_bank_details(): array {
		$accounts = get_option( 'woocommerce_bacs_accounts', array() );
		if ( is_array( $accounts ) && ! empty( $accounts[0] ) && is_array( $accounts[0] ) ) {
			$acc = $accounts[0];
			return array(
				'bank_name'      => ! empty( $acc['bank_name'] ) ? (string) $acc['bank_name'] : 'Banco de Chile',
				'account_type'   => ! empty( $acc['sort_code'] ) ? (string) $acc['sort_code'] : 'Cuenta Vista',
				'account_number' => ! empty( $acc['account_number'] ) ? (string) $acc['account_number'] : '39625300',
				'rut'            => ! empty( $acc['iban'] ) ? (string) $acc['iban'] : '78.274.225-6',
				'holder'         => ! empty( $acc['account_name'] ) ? (string) $acc['account_name'] : 'Akibara SpA',
				'email'          => ! empty( $acc['bic'] ) ? (string) $acc['bic'] : (string) get_option( 'admin_email', 'contacto@akibara.cl' ),
			);
		}

		return array(
			'bank_name'      => 'Banco de Chile',
			'account_type'   => 'Cuenta Vista',
			'account_number' => '39625300',
			'rut'            => '78.274.225-6',
			'holder'         => 'Akibara SpA',
			'email'          => 'contacto@akibara.cl',
		);
	}

	public function send_reminder( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || 'on-hold' !== $order->get_status() || 'bacs' !== $order->get_payment_method() ) {
			return;
		}

		// Si ya subió comprobante, no enviar recordatorio de urgencia
		if ( '' !== (string) $order->get_meta( self::META_RECEIPT_REPORTED ) ) {
			return;
		}

		$to = (string) $order->get_billing_email();
		if ( '' === $to ) {
			return;
		}

		$hours           = BusinessOptions::bacs_expiry_hours();
		$reminder_hours  = BusinessOptions::bacs_reminder_hours();
		$remaining_hours = max( 1, $hours - $reminder_hours );
		$bank            = self::get_bank_details();

		$default_subject = sprintf( 'Recordatorio: Tu pedido #%s en Akibara espera tu comprobante de transferencia', $order->get_order_number() );
		$subject         = (string) apply_filters( 'akb_bacs_reminder_email_subject', $default_subject, $order );
		$heading         = (string) apply_filters( 'akb_bacs_reminder_email_heading', 'Tu pedido espera transferencia', $order );
		$order_url       = \Akibara\Business\Modules\EmailBrand\EmailBrand::order_url( $order );
		if ( '' === $order_url ) {
			$order_url = (string) $order->get_view_order_url();
		}
		$body      = sprintf(
			'<p style="margin:0 0 12px">¡Hola %s!</p>
			<p style="margin:0 0 12px">Te recordamos que tu pedido <strong>#%s</strong> está reservado por transferencia bancaria.</p>
			<div class="akb-email-callout" style="margin:16px 0;padding:16px 20px;background:#fbf8f0;border:1px solid #141416;border-left:4px solid #E60012;border-radius:0;">
				<p style="margin:0 0 8px;font-weight:bold;color:#0d0d0d;">Datos para transferir:</p>
				<p style="margin:0;font-size:14px;line-height:1.6;color:#2b2b2b;">
					<strong>Banco:</strong> %s<br>
					<strong>Tipo de cuenta:</strong> %s<br>
					<strong>N.º de cuenta:</strong> %s<br>
					<strong>RUT:</strong> %s<br>
					<strong>Titular:</strong> %s<br>
					<strong>Correo:</strong> %s
				</p>
			</div>
			<p style="margin:0 0 12px">Por favor informa tu comprobante o N.º de operación en el detalle de tu pedido o responde este correo dentro de las próximas %d horas para asegurar tus tomos.</p>%s',
			esc_html( $order->get_billing_first_name() ),
			esc_html( (string) $order->get_order_number() ),
			esc_html( $bank['bank_name'] ),
			esc_html( $bank['account_type'] ),
			esc_html( $bank['account_number'] ),
			esc_html( $bank['rut'] ),
			esc_html( $bank['holder'] ),
			esc_html( $bank['email'] ),
			$remaining_hours,
			\Akibara\Business\Services\EmailButton::render( 'Informar Comprobante / Ver Pedido', $order_url )
		);

		$sent = \Akibara\Business\Services\EmailTemplateGateway::send( $to, $subject, $heading, $body, array( 'key' => 'bacs_reminder' ) );
		if ( $sent ) {
			$order->add_order_note( 'Puntos & BACS: Recordatorio automático de transferencia enviado al cliente.' );
		}
	}

	public function expire_order( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || 'on-hold' !== $order->get_status() || 'bacs' !== $order->get_payment_method() ) {
			return;
		}

		// Revisar si hubo comprobante informado y si estamos dentro de la prórroga de gracia
		$reported_time = (int) $order->get_meta( self::META_RECEIPT_REPORTED );
		if ( $reported_time > 0 ) {
			$grace_secs = BusinessOptions::bacs_receipt_grace_hours() * HOUR_IN_SECONDS;
			if ( time() < ( $reported_time + $grace_secs ) ) {
				// Aún en período de gracia para revisión humana
				return;
			}
		}

		// Cancelar la orden: WooCommerce repone automáticamente stock y Puntos Akibara revierte el canje
		$order->update_status(
			'cancelled',
			'Orden por transferencia bancaria cancelada automáticamente por vencimiento del plazo de pago (24h sin comprobante verificado).'
		);
	}

	public function ajax_submit_receipt(): void {
		check_ajax_referer( 'akb_bacs_receipt_nonce' );

		$order_id  = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$reference = isset( $_POST['reference'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['reference'] ) ) : '';

		if ( $order_id <= 0 || '' === $reference ) {
			wp_send_json_error( array( 'message' => 'Número de operación o comprobante requerido.' ) );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			wp_send_json_error( array( 'message' => 'Pedido no encontrado.' ) );
		}

		$user_id   = get_current_user_id();
		$order_key = isset( $_POST['order_key'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['order_key'] ) ) : '';
		$is_admin  = current_user_can( 'manage_woocommerce' );
		$is_owner  = $user_id > 0 && (int) $order->get_customer_id() === $user_id;
		$valid_key = '' !== $order_key && method_exists( $order, 'get_order_key' ) && hash_equals( (string) $order->get_order_key(), $order_key );

		if ( ! $is_admin && ! $is_owner && ! $valid_key ) {
			wp_send_json_error( array( 'message' => 'No autorizado para este pedido.' ) );
		}

		$now = time();
		$order->update_meta_data( self::META_RECEIPT_REPORTED, $now );
		$order->update_meta_data( self::META_RECEIPT_REFERENCE, $reference );

		$grace_secs = BusinessOptions::bacs_receipt_grace_hours() * HOUR_IN_SECONDS;
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::ACTION_REMINDER, array( 'order_id' => $order_id ), self::GROUP );
			as_unschedule_all_actions( self::ACTION_EXPIRE, array( 'order_id' => $order_id ), self::GROUP );
		}
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( $now + $grace_secs, self::ACTION_EXPIRE, array( 'order_id' => $order_id ), self::GROUP );
		}

		$order->add_order_note( sprintf(
			'Cliente informó comprobante de transferencia (Ref: %s). Expiración automática extendida por +%d horas.',
			$reference,
			BusinessOptions::bacs_receipt_grace_hours()
		) );
		$order->save_meta_data();

		wp_send_json_success( array(
			'message' => '¡Comprobante informado con éxito! Hemos extendido el plazo y estamos verificando tu pago.',
		) );
	}

	public function render_bacs_portal( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || 'on-hold' !== $order->get_status() || 'bacs' !== $order->get_payment_method() ) {
			return;
		}

		$reported_time = (int) $order->get_meta( self::META_RECEIPT_REPORTED );
		$reported_ref  = (string) $order->get_meta( self::META_RECEIPT_REFERENCE );
		$on_hold_since = (int) $order->get_meta( self::META_ON_HOLD_SINCE );
		if ( $on_hold_since <= 0 ) {
			$on_hold_since = time();
		}

		$expiry_secs  = BusinessOptions::bacs_expiry_hours() * HOUR_IN_SECONDS;
		$deadline     = $on_hold_since + $expiry_secs;
		$remaining_s  = max( 0, $deadline - time() );
		$rem_hours    = (int) floor( $remaining_s / 3600 );
		$rem_minutes  = (int) floor( ( $remaining_s % 3600 ) / 60 );
		$bank         = self::get_bank_details();

		$nonce = wp_create_nonce( 'akb_bacs_receipt_nonce' );
		?>
		<div class="akb-bacs-portal-box" style="margin:25px 0; padding:20px; border:2px solid #0073aa; border-radius:6px; background:#f4f8fb;">
			<h3 style="margin-top:0; color:#0073aa;">Informar Pago por Transferencia Bancaria (<?php echo esc_html( $bank['bank_name'] ); ?>)</h3>
			<p>Para asegurar tus tomos y coordinar el despacho, por favor realiza tu transferencia y sube aquí tu número de operación.</p>

			<div style="background:#fff; padding:12px 16px; border:1px solid #c3c4c7; border-radius:4px; margin-bottom:15px; font-size:14px;">
				<strong>Datos de la Cuenta Oficial:</strong><br>
				• <strong>Banco:</strong> <?php echo esc_html( $bank['bank_name'] ); ?><br>
				• <strong>Tipo de Cuenta:</strong> <?php echo esc_html( $bank['account_type'] ); ?><br>
				• <strong>N.º de Cuenta:</strong> <?php echo esc_html( $bank['account_number'] ); ?><br>
				• <strong>RUT:</strong> <?php echo esc_html( $bank['rut'] ); ?><br>
				• <strong>Nombre:</strong> <?php echo esc_html( $bank['holder'] ); ?><br>
				• <strong>Correo de notificación:</strong> <?php echo esc_html( $bank['email'] ); ?><br>
				• <strong>Monto Exacto a Transferir:</strong> $<?php echo number_format( (float) $order->get_total(), 0, ',', '.' ); ?> CLP
			</div>

			<?php if ( $reported_time > 0 ) : ?>
				<div style="padding:10px 14px; background:#e7f5ea; border-left:4px solid #46b450; color:#1e4620; font-weight:bold;">
					✓ Ya informaste tu transferencia (Operación: <?php echo esc_html( $reported_ref ); ?>). Nuestro equipo está validando el abono en el banco. Tu reserva está protegida.
				</div>
			<?php else : ?>
				<div style="margin-bottom:15px;">
					<strong>Tiempo restante para transferir:</strong>
					<span style="display:inline-block; padding:3px 8px; background:#fff2d6; color:#855a00; border-radius:3px; font-weight:bold;">
						<?php echo esc_html( sprintf( '%d horas y %d minutos', $rem_hours, $rem_minutes ) ); ?>
					</span>
				</div>

				<form id="akb_bacs_submit_receipt_form" style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
					<input type="hidden" name="action" value="akb_submit_bacs_receipt">
					<input type="hidden" name="_ajax_nonce" value="<?php echo esc_attr( $nonce ); ?>">
					<input type="hidden" name="order_id" value="<?php echo esc_attr( (string) $order->get_id() ); ?>">
					<input type="hidden" name="order_key" value="<?php echo esc_attr( method_exists( $order, 'get_order_key' ) ? (string) $order->get_order_key() : '' ); ?>">
					<input type="text" name="reference" id="akb_bacs_ref_input" placeholder="N.º de Transferencia o ID de Operación" required style="padding:8px 12px; border:1px solid #8c8f94; border-radius:4px; min-width:260px;">
					<button type="submit" class="button button-primary" style="padding:4px 16px; height:auto;">Ya Transferí / Informar Pago</button>
				</form>
				<div id="akb_bacs_receipt_msg" style="margin-top:10px;"></div>

				<script>
				document.addEventListener('DOMContentLoaded', function() {
					var form = document.getElementById('akb_bacs_submit_receipt_form');
					var msg = document.getElementById('akb_bacs_receipt_msg');
					if (form) {
						form.addEventListener('submit', function(e) {
							e.preventDefault();
							var btn = form.querySelector('button');
							btn.disabled = true;
							btn.innerText = 'Enviando...';
							var fd = new FormData(form);
							fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', { method: 'POST', body: fd })
								.then(function(r) { return r.json(); })
								.then(function(res) {
									if (res.success) {
										msg.innerHTML = '<span style="color:#46b450; font-weight:bold;">' + res.data.message + '</span>';
										setTimeout(function() { location.reload(); }, 1500);
									} else {
										msg.innerHTML = '<span style="color:#d63638; font-weight:bold;">' + (res.data.message || 'Error al enviar') + '</span>';
										btn.disabled = false;
										btn.innerText = 'Ya Transferí / Informar Pago';
									}
								});
						});
					}
				});
				</script>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Muestra un banner explicativo en el correo 'customer-on-hold-order' de WooCommerce.
	 *
	 * @param \WC_Order|null $order
	 * @param bool           $sent_to_admin
	 * @param bool           $plain_text
	 * @param mixed          $email
	 */
	public function email_bacs_instructions( $order, bool $sent_to_admin = false, bool $plain_text = false, $email = null ): void {
		if ( ! $order instanceof \WC_Order || $sent_to_admin ) {
			return;
		}

		if ( 'bacs' !== $order->get_payment_method() || 'on-hold' !== $order->get_status() ) {
			return;
		}

		$order_url = \Akibara\Business\Modules\EmailBrand\EmailBrand::order_url( $order );
		if ( '' === $order_url ) {
			$order_url = (string) $order->get_view_order_url();
		}
		$hours     = BusinessOptions::bacs_expiry_hours();
		$grace     = BusinessOptions::bacs_receipt_grace_hours();
		$bank      = self::get_bank_details();

		if ( $plain_text ) {
			echo "\n" . sprintf( "⏱ Plazo de transferencia: %d horas\n", $hours );
			echo "Para asegurar el stock de tu pedido, realiza la transferencia antes de que termine el plazo.\n";
			echo sprintf( "Una vez transferido, informa tu N.º de operación en tu pedido para extender la reserva por +%d hrs mientras validamos con %s:\n", $grace, $bank['bank_name'] );
			echo $order_url . "\n\n";
			return;
		}

		echo '<div class="akb-email-callout" style="margin:20px 0; padding:16px 20px; background:#fbf8f0; border:1px solid #141416; border-left:4px solid #E60012; border-radius:0;">';
		echo '<h4 style="margin:0 0 8px 0; color:#0d0d0d; font-size:15px; font-weight:bold;">⏱ Plazo de transferencia: ' . esc_html( (string) $hours ) . ' horas</h4>';
		echo '<p style="margin:0 0 10px 0; font-size:14px; line-height:1.5; color:#2b2b2b;">Para asegurar el stock de tus tomos, realiza tu transferencia a ' . esc_html( $bank['bank_name'] ) . ' dentro de las próximas ' . esc_html( (string) $hours ) . ' horas.</p>';
		echo '<p style="margin:0; font-size:14px; line-height:1.5; color:#2b2b2b;">Una vez transferido, <a href="' . esc_url( $order_url ) . '" style="color:#CC0010; font-weight:bold; text-decoration:underline;">ingresa a tu pedido</a> para registrar tu N.º de operación o responde a este correo con tu comprobante y extenderemos tu reserva por +' . esc_html( (string) $grace ) . ' horas de gracia mientras confirmamos el pago.</p>';
		echo '</div>';
	}
}
