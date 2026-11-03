<?php
/**
 * Integra el RUT en la exportación y anonimización de datos personales
 * mediante los hooks oficiales de WooCommerce.
 * En el pedido contempla _billing_rut y _wc_other/akibara/rut;
 * en el cliente, billing_rut y _wc_other/akibara/rut.
 * La ubicación contact de CheckoutFields::register_rut_field() corresponde
 * al grupo nativo _wc_other/akibara/rut.
 *
 * @package Akibara\CartCheckoutBlocks
 */

declare( strict_types = 1 );

namespace Akibara\CartCheckoutBlocks;

defined( 'ABSPATH' ) || exit;

final class RutPrivacy {

	/** El campo nativo de bloques, en pedido y en cliente (ubicación `contact` → grupo `other`). */
	public const NATIVE_META = '_wc_other/akibara/rut';

	/** Las dos metas del RUT en un pedido. */
	public const ORDER_META = array( '_billing_rut', self::NATIVE_META );

	/** Las dos metas del RUT en un cliente. */
	public const CUSTOMER_META = array( 'billing_rut', self::NATIVE_META );

	public function register(): void {
		add_filter( 'woocommerce_privacy_export_order_personal_data_meta', array( $this, 'export_order_meta' ) );
		add_filter( 'woocommerce_privacy_remove_order_personal_data_meta', array( $this, 'remove_order_meta' ) );
		add_filter( 'woocommerce_privacy_remove_order_personal_data_meta_value', array( $this, 'delete_instead_of_anonymize' ), 10, 2 );
		add_filter( 'woocommerce_privacy_export_customer_personal_data', array( $this, 'export_customer' ), 10, 2 );
		add_filter( 'woocommerce_privacy_erase_personal_data_customer', array( $this, 'erase_customer' ), 10, 2 );
	}

	/**
	 * Una sola fila «RUT» por pedido: las dos metas guardan el mismo valor, y la
	 * histórica es la que existe también en los pedidos anteriores a los bloques.
	 *
	 * @param mixed $meta Metas a exportar (clave => etiqueta).
	 * @return array<string,string>
	 */
	public function export_order_meta( $meta ): array {
		$meta                 = is_array( $meta ) ? $meta : array();
		$meta['_billing_rut'] = 'RUT';

		return $meta;
	}

	/**
	 * Al borrar se van LAS DOS: dejar el campo nativo era dejar el RUT entero.
	 *
	 * @param mixed $meta Metas a retirar (clave => tipo de dato).
	 * @return array<string,string>
	 */
	public function remove_order_meta( $meta ): array {
		$meta = is_array( $meta ) ? $meta : array();
		foreach ( self::ORDER_META as $clave ) {
			$meta[ $clave ] = 'text';
		}

		return $meta;
	}

	/**
	 * WooCommerce anonimiza un texto dejándolo en «[deleted]», y la meta sigue
	 * ahí: el panel y los correos del pedido mostrarían «RUT: [deleted]». Con el
	 * valor vacío WooCommerce BORRA la meta, que es lo que corresponde.
	 *
	 * @param mixed $anon_value Valor anonimizado que propone WooCommerce.
	 * @param mixed $meta_key   Meta que se está retirando.
	 * @return mixed
	 */
	public function delete_instead_of_anonymize( $anon_value, $meta_key ) {
		return in_array( (string) $meta_key, self::ORDER_META, true ) ? '' : $anon_value;
	}

	/**
	 * @param mixed $personal_data Pares nombre/valor ya reunidos por WooCommerce.
	 * @param mixed $customer      Cliente.
	 * @return array<int,array{name:string,value:string}>
	 */
	public function export_customer( $personal_data, $customer ): array {
		$personal_data = is_array( $personal_data ) ? $personal_data : array();
		if ( ! is_object( $customer ) || ! is_callable( array( $customer, 'get_meta' ) ) ) {
			return $personal_data;
		}

		foreach ( self::CUSTOMER_META as $clave ) {
			$rut = trim( (string) $customer->get_meta( $clave ) );
			if ( '' !== $rut ) {
				$personal_data[] = array(
					'name'  => 'RUT',
					'value' => $rut,
				);
				break; // Las dos guardan el mismo RUT: una fila.
			}
		}

		return $personal_data;
	}

	/**
	 * @param mixed $response Respuesta del borrador de WooCommerce.
	 * @param mixed $customer Cliente.
	 * @return array<string,mixed>
	 */
	public function erase_customer( $response, $customer ): array {
		$response = is_array( $response ) ? $response : array();
		if ( ! is_object( $customer ) || ! is_callable( array( $customer, 'get_meta' ) ) ) {
			return $response;
		}

		$borrado = false;
		foreach ( self::CUSTOMER_META as $clave ) {
			if ( '' !== trim( (string) $customer->get_meta( $clave ) ) ) {
				$customer->delete_meta_data( $clave );
				$borrado = true;
			}
		}

		if ( $borrado ) {
			$customer->save();
			$response['items_removed'] = true;
			$response['messages']      = array_merge( (array) ( $response['messages'] ?? array() ), array( 'Se eliminó el RUT del cliente.' ) );
		}

		return $response;
	}
}
