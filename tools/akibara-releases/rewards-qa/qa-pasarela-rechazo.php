<?php
/**
 * Pasarela de QA que RECHAZA el pago sin tocar el estado del pedido, como
 * MercadoPago con tarjeta, más dos palancas de QA (recargo y fallo tras crear
 * el pedido). Sólo para el ESPEJO.
 *
 * qa-canje-pago-rechazado.mjs la copia a mu-plugins al empezar y la BORRA al
 * terminar (también si falla). Sólo aparece para la cuenta qa.canje, así que no
 * cambia la caja de nadie más. Nunca se empaqueta ni se despliega.
 *
 * @package Akibara\Rewards\QA
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			return;
		}

		/**
		 * Rechaza siempre.
		 */
		final class Akb_QA_Pasarela_Rechazo extends WC_Payment_Gateway {

			public function __construct() {
				$this->id                 = 'akb_qa_rechazo';
				$this->method_title       = 'QA rechazo';
				$this->title              = 'QA rechazo';
				$this->method_description = 'Pasarela de QA del espejo.';
				$this->enabled            = 'yes';
				$this->supports           = array( 'products' );
			}

			public function is_available(): bool {
				$user = wp_get_current_user();
				return $user instanceof WP_User && 'qa.canje' === $user->user_login;
			}

			/**
			 * @param int $order_id Pedido.
			 * @return array<string,string>
			 */
			public function process_payment( $order_id ): array {
				unset( $order_id );
				return array(
					'result'  => 'failure',
					'message' => 'Tarjeta rechazada (QA).',
				);
			}
		}

		/**
		 * Acepta y REDIRIGE, sin cobrar: el pedido queda pendiente mientras el
		 * cliente «paga» fuera, como Flow o MercadoPago con redirección.
		 */
		final class Akb_QA_Pasarela_Redirige extends WC_Payment_Gateway {

			public function __construct() {
				$this->id                 = 'akb_qa_redirige';
				$this->method_title       = 'QA redirige';
				$this->title              = 'QA redirige';
				$this->method_description = 'Pasarela de QA del espejo.';
				$this->enabled            = 'yes';
				$this->supports           = array( 'products' );
			}

			public function is_available(): bool {
				$user = wp_get_current_user();
				return $user instanceof WP_User && 'qa.canje' === $user->user_login;
			}

			/**
			 * @param int $order_id Pedido.
			 * @return array<string,string>
			 */
			public function process_payment( $order_id ): array {
				$order = wc_get_order( $order_id );
				return array(
					'result'   => 'success',
					'redirect' => $order ? $order->get_checkout_payment_url() : home_url( '/' ),
				);
			}
		}

		add_filter(
			'woocommerce_payment_gateways',
			static function ( array $gateways ): array {
				$gateways[] = 'Akb_QA_Pasarela_Rechazo';
				$gateways[] = 'Akb_QA_Pasarela_Redirige';
				return $gateways;
			}
		);
	},
	5
);

/**
 * Sólo para la cuenta qa.canje.
 */
function akb_qa_es_cuenta_de_canje(): bool {
	$user = wp_get_current_user();
	return $user instanceof WP_User && 'qa.canje' === $user->user_login;
}

// Recargo de QA: permite cambiar el canje dejando el MISMO total (lo que en la
// tienda hace un despacho distinto que lo compensa).
add_action(
	'woocommerce_cart_calculate_fees',
	static function ( $cart ): void {
		$recargo = (int) get_option( 'akb_qa_recargo', 0 );
		if ( $recargo > 0 && akb_qa_es_cuenta_de_canje() && $cart instanceof WC_Cart ) {
			$cart->add_fee( 'QA recargo', $recargo, true ); // Imponible, como un despacho: no altera la tasa del carrito.
		}
	},
	10
);

// Fallo forzado DESPUÉS de crear el pedido y anotarlo en la sesión: una sola vez.
add_action(
	'woocommerce_store_api_checkout_update_order_from_request',
	static function (): void {
		if ( get_option( 'akb_qa_fallar_tras_crear' ) && akb_qa_es_cuenta_de_canje() ) {
			delete_option( 'akb_qa_fallar_tras_crear' );
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'akb_qa_fallo', 'Fallo de QA tras crear el pedido.', 400 );
		}
	},
	1
);
