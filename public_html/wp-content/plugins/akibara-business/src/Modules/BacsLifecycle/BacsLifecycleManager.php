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

defined( 'ABSPATH' ) || exit;

/**
 * @api
 */
final class BacsLifecycleManager {

	public const GROUP           = 'akibara-business';
	public const ACTION_REMINDER = 'akb_bacs_send_reminder_email';
	public const ACTION_EXPIRE   = 'akb_bacs_auto_expire_order';

	public const META_ON_HOLD_SINCE     = '_akb_bacs_on_hold_since';
	public const META_RECEIPT_REPORTED  = '_akb_bacs_receipt_reported';
	public const META_RECEIPT_REFERENCE = '_akb_bacs_receipt_reference';

	public const OPT_AUTO_CANCEL_ENABLED = 'akb_bacs_auto_cancel_enabled';
	public const OPT_REMINDER_HOURS      = 'akb_bacs_reminder_hours';
	public const OPT_EXPIRY_HOURS        = 'akb_bacs_expiry_hours';
	public const OPT_RECEIPT_GRACE_HOURS = 'akb_bacs_receipt_grace_hours';

	public static function auto_cancel_enabled(): bool {
		return 'yes' === get_option( self::OPT_AUTO_CANCEL_ENABLED, 'yes' );
	}

	public static function reminder_hours(): int {
		return max( 1, (int) get_option( self::OPT_REMINDER_HOURS, 12 ) );
	}

	public static function expiry_hours(): int {
		return max( 2, (int) get_option( self::OPT_EXPIRY_HOURS, 24 ) );
	}

	public static function receipt_grace_hours(): int {
		return max( 1, (int) get_option( self::OPT_RECEIPT_GRACE_HOURS, 48 ) );
	}

	public function register(): void {
		add_action( 'woocommerce_order_status_on-hold', array( $this, 'on_order_hold' ), 20, 2 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_status_changed' ), 20, 3 );
		add_action( self::ACTION_REMINDER, array( $this, 'send_reminder' ), 10, 1 );
		add_action( self::ACTION_EXPIRE, array( $this, 'expire_order' ), 10, 1 );

		add_action( 'wp_ajax_akb_submit_bacs_receipt', array( $this, 'ajax_submit_receipt' ), 10, 0 );
		add_action( 'wp_ajax_nopriv_akb_submit_bacs_receipt', array( $this, 'ajax_submit_receipt' ), 10, 0 );

		add_action( 'woocommerce_thankyou_bacs', array( $this, 'render_bacs_portal' ), 20, 1 );
		add_action( 'woocommerce_view_order', array( $this, 'render_bacs_portal' ), 20, 1 );
		add_action( 'woocommerce_email_before_order_table', array( $this, 'email_bacs_instructions' ), 10, 3 );
	}

	/**
	 * Limpia las acciones programadas de recordatorio y vencimiento cuando el pedido sale de on-hold.
	 *
	 * @param int    $order_id ID del pedido.
	 * @param string $from     Estado anterior.
	 * @param string $to       Estado nuevo.
	 */
	public function on_status_changed( $order_id, $from, $to ): void {
		if ( 'on-hold' === (string) $from && 'on-hold' !== (string) $to ) {
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( self::ACTION_REMINDER, array( 'order_id' => (int) $order_id ), self::GROUP );
				as_unschedule_all_actions( self::ACTION_EXPIRE, array( 'order_id' => (int) $order_id ), self::GROUP );
			}
		}
	}

	/**
	 * @param mixed $order
	 * @param mixed $order_id
	 */
	public function on_order_hold( $order_id, $order = null ): void {
		if ( ! self::auto_cancel_enabled() ) {
			return;
		}

		if ( ! $order instanceof \WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order instanceof \WC_Order || 'bacs' !== $order->get_payment_method() ) {
			return;
		}

		$now = time();
		$order->update_meta_data( self::META_ON_HOLD_SINCE, (string) $now );
		$order->save_meta_data();

		$reminder_secs = self::reminder_hours() * HOUR_IN_SECONDS;
		$expiry_secs   = self::expiry_hours() * HOUR_IN_SECONDS;

		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::ACTION_REMINDER, array( 'order_id' => (int) $order_id ), self::GROUP );
			as_unschedule_all_actions( self::ACTION_EXPIRE, array( 'order_id' => (int) $order_id ), self::GROUP );
		}

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

		$hours           = self::expiry_hours();
		$reminder_hours  = self::reminder_hours();
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
			$grace_secs = self::receipt_grace_hours() * HOUR_IN_SECONDS;
			if ( time() < ( $reported_time + $grace_secs ) ) {
				// Aún en período de gracia para revisión humana
				return;
			}
		}

		// Cancelar la orden: WooCommerce repone automáticamente stock y Puntos Akibara revierte el canje.
		$cancel_note = $reported_time > 0
			? sprintf(
				'Orden por transferencia bancaria cancelada automáticamente tras vencer la prórroga de +%dh desde el aviso de comprobante sin verificación manual.',
				self::receipt_grace_hours()
			)
			: sprintf(
				'Orden por transferencia bancaria cancelada automáticamente por vencimiento del plazo de pago (%dh sin comprobante verificado).',
				self::expiry_hours()
			);

		$order->update_status( 'cancelled', $cancel_note );
	}

	public function ajax_submit_receipt(): void {
		check_ajax_referer( 'akb_bacs_receipt_nonce' );

		$order_id  = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$reference = isset( $_POST['reference'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['reference'] ) ) : '';

		if ( $order_id <= 0 || '' === $reference ) {
			wp_send_json_error( array( 'message' => 'Número de operación o comprobante requerido.' ), 400 );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			wp_send_json_error( array( 'message' => 'Pedido no encontrado.' ), 404 );
		}

		$user_id   = get_current_user_id();
		$order_key = isset( $_POST['order_key'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['order_key'] ) ) : '';
		$is_admin  = current_user_can( 'manage_woocommerce' );
		$is_owner  = $user_id > 0 && (int) $order->get_customer_id() === $user_id;
		$valid_key = '' !== $order_key && hash_equals( (string) $order->get_order_key(), $order_key );

		if ( ! $is_admin && ! $is_owner && ! $valid_key ) {
			wp_send_json_error( array( 'message' => 'No autorizado para este pedido.' ), 403 );
		}

		if ( 'on-hold' !== $order->get_status() || 'bacs' !== $order->get_payment_method() ) {
			wp_send_json_error( array( 'message' => 'Este pedido no admite informar comprobante de transferencia en su estado actual.' ), 409 );
		}

		if ( (int) $order->get_meta( self::META_RECEIPT_REPORTED ) > 0 ) {
			$reported_ref = (string) $order->get_meta( self::META_RECEIPT_REFERENCE );
			$msg          = '' !== $reported_ref
				? sprintf( 'Ya informaste un comprobante (%s) para este pedido. Nuestro equipo lo está validando.', $reported_ref )
				: 'Ya informaste tu comprobante para este pedido. Nuestro equipo lo está validando.';
			wp_send_json_error( array( 'message' => $msg ), 409 );
		}

		$now = time();
		$order->update_meta_data( self::META_RECEIPT_REPORTED, (string) $now );
		$order->update_meta_data( self::META_RECEIPT_REFERENCE, $reference );

		$grace_secs = self::receipt_grace_hours() * HOUR_IN_SECONDS;
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
			self::receipt_grace_hours()
		) );
		$order->save_meta_data();

		wp_send_json_success( array(
			'message' => '¡Comprobante informado con éxito! Hemos extendido el plazo y estamos verificando tu pago.',
		) );
	}

	/**
	 * @param mixed $order_id
	 */
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

		$expiry_secs  = self::expiry_hours() * HOUR_IN_SECONDS;
		$deadline     = $on_hold_since + $expiry_secs;
		$remaining_s  = max( 0, $deadline - time() );
		$rem_hours    = (int) floor( $remaining_s / 3600 );
		$rem_minutes  = (int) floor( ( $remaining_s % 3600 ) / 60 );
		$bank         = self::get_bank_details();
		$amount_html  = wc_price( (float) $order->get_total(), array( 'currency' => $order->get_currency() ) );

		if ( $reported_time <= 0 ) {
			$this->enqueue_receipt_script();
		}

		$nonce = wp_create_nonce( 'akb_bacs_receipt_nonce' );
		?>
		<div class="akb-bacs-portal">
			<h3 id="akb-bacs-portal-heading" class="akb-bacs-portal__title">Informar pago por transferencia bancaria (<?php echo esc_html( $bank['bank_name'] ); ?>)</h3>
			<p class="akb-bacs-portal__intro">Para asegurar tus tomos y coordinar el despacho, realiza tu transferencia y registra aquí tu número de operación.</p>

			<dl class="akb-bacs-portal__details">
				<div class="akb-bacs-portal__detail">
					<dt>Banco</dt>
					<dd><?php echo esc_html( $bank['bank_name'] ); ?></dd>
				</div>
				<div class="akb-bacs-portal__detail">
					<dt>Tipo de cuenta</dt>
					<dd><?php echo esc_html( $bank['account_type'] ); ?></dd>
				</div>
				<div class="akb-bacs-portal__detail">
					<dt>N.º de cuenta</dt>
					<dd><?php echo esc_html( $bank['account_number'] ); ?></dd>
				</div>
				<div class="akb-bacs-portal__detail">
					<dt>RUT</dt>
					<dd><?php echo esc_html( $bank['rut'] ); ?></dd>
				</div>
				<div class="akb-bacs-portal__detail">
					<dt>Titular</dt>
					<dd><?php echo esc_html( $bank['holder'] ); ?></dd>
				</div>
				<div class="akb-bacs-portal__detail">
					<dt>Correo de notificación</dt>
					<dd><?php echo esc_html( $bank['email'] ); ?></dd>
				</div>
				<div class="akb-bacs-portal__detail akb-bacs-portal__detail--amount">
					<dt>Monto exacto a transferir</dt>
					<dd><?php echo wp_kses_post( $amount_html ); ?></dd>
				</div>
			</dl>

			<?php if ( $reported_time > 0 ) : ?>
				<div class="akb-bacs-portal__status akb-bacs-portal__status--reported" role="status">
					<span aria-hidden="true">✓</span>
					<span>Ya informaste tu transferencia (Operación: <?php echo esc_html( $reported_ref ); ?>). Nuestro equipo está validando el abono en el banco. Tu reserva está protegida.</span>
				</div>
			<?php else : ?>
				<p class="akb-bacs-portal__countdown">
					<span>Tiempo restante para transferir:</span>
					<strong class="akb-bacs-portal__countdown-badge">
						<?php echo esc_html( sprintf( '%d horas y %d minutos', $rem_hours, $rem_minutes ) ); ?>
					</strong>
				</p>

				<form id="akb_bacs_submit_receipt_form" class="akb-bacs-portal__form" method="post">
					<input type="hidden" name="action" value="akb_submit_bacs_receipt">
					<input type="hidden" name="_ajax_nonce" value="<?php echo esc_attr( $nonce ); ?>">
					<input type="hidden" name="order_id" value="<?php echo esc_attr( (string) $order->get_id() ); ?>">
					<input type="hidden" name="order_key" value="<?php echo esc_attr( (string) $order->get_order_key() ); ?>">
					<div class="akb-bacs-portal__field">
						<label class="akb-bacs-portal__label" for="akb_bacs_ref_input">N.º de transferencia o ID de operación</label>
						<input
							type="text"
							name="reference"
							id="akb_bacs_ref_input"
							class="akb-bacs-portal__input"
							placeholder="Ej: 123456789"
							autocomplete="off"
							autocorrect="off"
							autocapitalize="none"
							spellcheck="false"
							enterkeyhint="done"
							required
						>
					</div>
					<button type="submit" class="button alt akb-bacs-portal__submit">Ya transferí / Informar pago</button>
				</form>
				<div id="akb_bacs_receipt_msg" class="akb-bacs-portal__msg" role="status" aria-live="polite" hidden></div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Encola el guion cliente para informar el comprobante sin JS embebido.
	 */
	private function enqueue_receipt_script(): void {
		if ( ! function_exists( 'wp_enqueue_script' ) ) {
			return;
		}

		$base = trailingslashit( AKIBARA_BUSINESS_URL );
		$js   = 'assets/js/bacs-receipt.js';

		wp_enqueue_script(
			'akb-bacs-receipt',
			$base . $js,
			array(),
			\Akibara\Business\Utilities\PluginAssetVersion::for_path( $js ),
			true
		);

		wp_localize_script(
			'akb-bacs-receipt',
			'akbBacsReceiptConfig',
			array(
				'ajaxUrl'         => esc_url_raw( admin_url( 'admin-ajax.php' ) ),
				'submitLabel'     => 'Ya transferí / Informar pago',
				'submittingLabel' => 'Enviando...',
				'genericError'    => 'Error al enviar el comprobante. Inténtalo de nuevo.',
			)
		);
	}

	/**
	 * Muestra un banner explicativo en el correo 'customer-on-hold-order' de WooCommerce.
	 *
	 * @param \WC_Order|null $order
	 * @param bool           $sent_to_admin
	 * @param bool           $plain_text
	 */
	public function email_bacs_instructions( $order, bool $sent_to_admin = false, bool $plain_text = false ): void {
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
		$hours     = self::expiry_hours();
		$grace     = self::receipt_grace_hours();
		$bank      = self::get_bank_details();

		if ( $plain_text ) {
			echo "\n" . wp_kses( sprintf( "⏱ Plazo de transferencia: %d horas\n", $hours ), array() );
			echo "Para asegurar el stock de tu pedido, realiza la transferencia antes de que termine el plazo.\n";
			echo wp_kses( sprintf( "Una vez transferido, informa tu N.º de operación en tu pedido para extender la reserva por +%d hrs mientras validamos con %s:\n", $grace, $bank['bank_name'] ), array() );
			echo wp_kses( $order_url, array() ) . "\n\n";
			return;
		}

		echo '<div class="akb-email-callout" style="margin:20px 0; padding:16px 20px; background:#fbf8f0; border:1px solid #141416; border-left:4px solid #E60012; border-radius:0;">';
		echo '<h4 style="margin:0 0 8px 0; color:#0d0d0d; font-size:15px; font-weight:bold;">⏱ Plazo de transferencia: ' . esc_html( (string) $hours ) . ' horas</h4>';
		echo '<p style="margin:0 0 10px 0; font-size:14px; line-height:1.5; color:#2b2b2b;">Para asegurar el stock de tus tomos, realiza tu transferencia a ' . esc_html( $bank['bank_name'] ) . ' dentro de las próximas ' . esc_html( (string) $hours ) . ' horas.</p>';
		echo '<p style="margin:0; font-size:14px; line-height:1.5; color:#2b2b2b;">Una vez transferido, <a href="' . esc_url( $order_url ) . '" style="color:#CC0010; font-weight:bold; text-decoration:underline;">ingresa a tu pedido</a> para registrar tu N.º de operación o responde a este correo con tu comprobante y extenderemos tu reserva por +' . esc_html( (string) $grace ) . ' horas de gracia mientras confirmamos el pago.</p>';
		echo '</div>';
	}
}
