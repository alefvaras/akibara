<?php
/**
 * Safe guest order verification prompt in My Account.
 *
 * Silences the intrusive default WooCommerce verification banner and replaces it
 * with a subtle, privacy-preserving invitation link for unverified customers.
 * Does not automatically reassign orders without explicit email verification.
 *
 * @package Akibara\Business
 * @since 1.107.8
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\GuestOrderLinking;

defined( 'ABSPATH' ) || exit;

/**
 * Guest order linking prompt controller.
 *
 * @since 1.107.8
 */
final class GuestOrderLinking {

	/**
	 * Nombre del parámetro con que WooCommerce solicita el correo de verificación.
	 */
	private const PARAM_ENVIAR = 'wc_send_verification';

	/**
	 * Acción del nonce para envío de correo de verificación.
	 */
	private const ACCION_NONCE = 'woocommerce-send-verification-email';

	/**
	 * Si el aviso ruidoso se apagó en esta petición.
	 */
	private bool $aviso_apagado = false;

	/**
	 * Control de renderizado único por petición.
	 */
	private bool $ya_pintado = false;

	/**
	 * Conecta los filtros para personalizar el prompt de verificación de pedidos de invitado.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter(
			'woocommerce_customer_email_verification_should_show_prompt',
			array( $this, 'silenciar_aviso' ),
			10,
			2
		);
		add_action( 'woocommerce_before_account_orders', array( $this, 'pintar_invitacion' ), 20 );
	}

	/**
	 * Silencia el banner predeterminado de WooCommerce de forma consistente.
	 *
	 * @param mixed $mostrar Decisión original de WooCommerce.
	 * @param mixed $usuario ID del usuario.
	 * @return bool
	 */
	public function silenciar_aviso( $mostrar = false, $usuario = 0 ): bool {
		unset( $usuario );

		if ( $mostrar ) {
			$this->aviso_apagado = true;
		}

		return false;
	}

	/**
	 * Renderiza una invitación discreta y constante para que el usuario confirme su correo.
	 *
	 * @return void
	 */
	public function pintar_invitacion(): void {
		if ( ! $this->aviso_apagado || $this->ya_pintado ) {
			return;
		}
		$this->ya_pintado = true;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lectura de flag de notificación de WooCommerce.
		if ( isset( $_GET['wc_verify_notice'] ) ) {
			return;
		}

		$url = wp_nonce_url(
			add_query_arg( self::PARAM_ENVIAR, '1', wc_get_account_endpoint_url( 'orders' ) ),
			self::ACCION_NONCE
		);

		echo '<p class="akb-orders-claim"><a href="' . esc_url( $url ) . '">'
			. esc_html( '¿Compraste antes sin tener cuenta? Confirma tu correo y esos pedidos aparecen aquí.' )
			. '</a></p>';
	}
}
