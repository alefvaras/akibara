<?php
/**
 * El doble compartido de `WC_Order` no trae los datos de cliente que piden
 * las plantillas de correo. Vive en Support para que lo usen varios tests.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Support;

use WC_Order;

final class PedidoConDatosDeCliente extends WC_Order {

	public function get_billing_first_name(): string {
		return 'Cliente';
	}

	public function get_view_order_url(): string {
		return 'https://akibara.test/mi-cuenta/view-order/' . $this->akb_id . '/';
	}

	/**
	 * Cuenta detrás del pedido.
	 *
	 * Lo pide `EmailBrand::order_url()`, que es quien decide el destino del
	 * botón de los correos: con cuenta va a «view-order» y sin ella a la página
	 * de recibido, que WooCommerce autoriza con la `key` del propio pedido. Por
	 * defecto hay cuenta, que es el caso de la mayoría de estas plantillas; un
	 * test que quiera el invitado pone `akb_customer_id = 0`.
	 *
	 * @var int
	 */
	public int $akb_customer_id = 5;

	public function get_customer_id(): int {
		return $this->akb_customer_id;
	}

	public function get_checkout_order_received_url(): string {
		return 'https://akibara.test/checkout/order-received/' . $this->akb_id . '/?key=wc_order_qa';
	}
}
