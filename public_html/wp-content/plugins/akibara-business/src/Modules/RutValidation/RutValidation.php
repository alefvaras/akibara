<?php
/**
 * RUT display and metadata bridge for transactional emails, My Account, and Admin.
 *
 * Exposes the Chilean customer RUT in order emails, customer order views,
 * and admin order details across classic and block checkout orders.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\RutValidation;

use Akibara\Business\Utilities\PluginAssetVersion;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * RUT validation and display module.
 *
 * @since 1.0.0
 */
final class RutValidation {

	public const META_KEY  = '_billing_rut';
	public const FIELD_KEY = 'billing_rut';

	private const NATIVE_META_KEY = '_wc_other/akibara/rut';

	/**
	 * Conecta los hooks del módulo.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( $this, 'display_in_admin_order' ) );
		add_filter( 'woocommerce_email_order_meta_fields',            array( $this, 'add_to_email' ), 10, 3 );
		add_action( 'woocommerce_order_details_after_customer_address', array( $this, 'display_in_my_account' ), 10, 2 );
		add_action( 'wp_enqueue_scripts',                             array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Muestra RUT en admin order detail (debajo de billing address).
	 *
	 * Omite la salida si la orden ya posee el metadato nativo de bloques para evitar duplicidad.
	 *
	 * @param WC_Order $order Orden de WooCommerce.
	 * @return void
	 */
	public function display_in_admin_order( WC_Order $order ): void {
		if ( '' !== trim( (string) $order->get_meta( self::NATIVE_META_KEY ) ) ) {
			return;
		}

		$rut = (string) $order->get_meta( self::META_KEY );
		if ( '' === $rut ) {
			return;
		}

		echo '<p><strong>RUT:</strong> ' . esc_html( $rut ) . '</p>';
	}

	/**
	 * Agrega el RUT al bloque de metadatos en correos transaccionales de orden.
	 *
	 * @param array<string, array<string, string>> $fields Metadatos de la plantilla de email.
	 * @param bool                                 $sent_to_admin Si el email va dirigido al administrador.
	 * @param WC_Order                             $order Orden de WooCommerce.
	 * @return array<string, array<string, string>>
	 */
	public function add_to_email( array $fields, bool $sent_to_admin, WC_Order $order ): array {
		unset( $sent_to_admin );

		$rut = (string) $order->get_meta( self::META_KEY );
		if ( '' !== $rut ) {
			$fields[ self::FIELD_KEY ] = array(
				'label' => 'RUT',
				'value' => $rut,
			);
		}

		return $fields;
	}

	/**
	 * Muestra el RUT en Mi Cuenta > Detalle de pedido dentro de la dirección de facturación.
	 *
	 * @param string   $address_type Tipo de dirección ('billing' o 'shipping').
	 * @param WC_Order $order Orden de WooCommerce.
	 * @return void
	 */
	public function display_in_my_account( string $address_type, WC_Order $order ): void {
		if ( 'billing' !== $address_type ) {
			return;
		}

		$rut = (string) $order->get_meta( self::META_KEY );
		if ( '' === $rut ) {
			return;
		}

		echo '<p class="woocommerce-customer-details--rut">' . esc_html( $rut ) . '</p>';
	}

	/**
	 * Encola la hoja de estilos de presentación del RUT en Mi Cuenta.
	 *
	 * @return void
	 */
	public function enqueue_assets(): void {
		if ( is_view_order_page() ) {
			wp_enqueue_style(
				'akibara-rut-account',
				AKIBARA_BUSINESS_URL . 'assets/css/rut-account.css',
				array(),
				PluginAssetVersion::for_path( 'assets/css/rut-account.css' )
			);
		}
	}
}
