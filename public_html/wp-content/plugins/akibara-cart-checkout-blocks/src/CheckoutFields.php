<?php
/**
 * Chile-specific Checkout Blocks fields.
 *
 * @package Akibara\CartCheckoutBlocks
 */

declare( strict_types = 1 );

namespace Akibara\CartCheckoutBlocks;

use Akibara\Business\Contracts\CheckoutDataProvider;
use WC_Customer;
use WC_Order;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Owns Blocks field registration, validation and order synchronization.
 *
 * @since 2.0.0
 */
final class CheckoutFields {

	private const RUT_FIELD_ID = 'akibara/rut';

	/**
	 * Address parts copied from billing when the store forces shipping to it.
	 */
	private const FORCED_SHIPPING_PARTS = array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone' );

	private CheckoutDataProvider $data_provider;

	private ?ComunaDirectory $comunas;

	private bool $rut_field_registered = false;

	/**
	 * Initializes the Checkout fields adapter.
	 *
	 * @param CheckoutDataProvider $data_provider Stable Business contract.
	 * @param ComunaDirectory|null $comunas       Commune catalog, null disables commune handling.
	 *
	 * @since 2.0.0
	 */
	public function __construct( CheckoutDataProvider $data_provider, ?ComunaDirectory $comunas = null ) {
		$this->data_provider = $data_provider;
		$this->comunas       = $comunas;
	}

	/**
	 * Registers Checkout Blocks fields and validation hooks.
	 *
	 * @since 2.0.0
	 */
	public function register(): void {
		add_filter( 'woocommerce_get_country_locale', array( $this, 'localize_chilean_checkout_fields' ) );
		add_filter(
			'woocommerce_get_default_value_for_' . self::RUT_FIELD_ID,
			array( $this, 'default_rut_from_legacy_meta' ),
			10,
			3
		);
		add_action( 'woocommerce_checkout_validate_order_before_payment', array( $this, 'validate_billing_phone' ), 10, 2 );
		add_action( 'woocommerce_set_additional_field_value', array( $this, 'sync_rut_to_legacy_meta' ), 10, 4 );
		add_action( 'woocommerce_cart_loaded_from_session', array( $this, 'sync_forced_shipping_address' ) );
		add_action( 'woocommerce_before_customer_object_save', array( $this, 'mirror_before_customer_save' ) );

		if ( null !== $this->comunas ) {
			add_action( 'woocommerce_store_api_cart_update_customer_from_request', array( $this, 'canonicalize_customer_comuna' ), 10, 1 );
			add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'canonicalize_order_comuna' ), 10, 1 );
			add_action( 'woocommerce_checkout_validate_order_before_payment', array( $this, 'validate_chilean_comuna' ), 10, 2 );
		}

		if ( did_action( 'woocommerce_init' ) ) {
			$this->register_rut_field();
			return;
		}

		add_action( 'woocommerce_init', array( $this, 'register_rut_field' ) );
	}

	/**
	 * Sincroniza la dirección de envío del cliente en sesión con la de facturación
	 * cuando la tienda restringe los envíos a la dirección de facturación.
	 *
	 * Asegura que las cotizaciones de Store API y los métodos de entrega de Blue
	 * calculen tarifas consistentes con los datos ingresados en el formulario.
	 *
	 * @internal
	 * @since 2.2.30
	 */
	public function sync_forced_shipping_address(): void {
		if ( ! $this->ships_to_billing_only() || ! function_exists( 'WC' ) ) {
			return;
		}

		$customer = WC()->customer ?? null;
		if ( $customer instanceof WC_Customer ) {
			$this->mirror_billing_into_shipping( $customer );
		}
	}

	/**
	 * Stores shipping = billing whenever a customer is saved in a billing-only store.
	 *
	 * Closes the source of the divergence the cart-load copy repairs: after an
	 * order with a Blue point WooCommerce writes the point as the account's
	 * shipping address (`OrderController::sync_customer_data_with_order()`), and
	 * «Mi cuenta» only saves billing. The order keeps the point; the account
	 * keeps the customer's own address.
	 *
	 * @internal
	 *
	 * @param mixed $customer Customer about to be saved.
	 *
	 * @since 2.2.31
	 */
	public function mirror_before_customer_save( $customer ): void {
		if ( $customer instanceof WC_Customer && $this->ships_to_billing_only() ) {
			$this->mirror_billing_into_shipping( $customer );
		}
	}

	/**
	 * Copies every billing address part into shipping when billing is complete.
	 *
	 * @internal
	 *
	 * @param WC_Customer $customer Customer to align.
	 * @return bool Whether any shipping part changed.
	 *
	 * @since 2.2.31
	 */
	public function mirror_billing_into_shipping( WC_Customer $customer ): bool {
		foreach ( array( 'country', 'state', 'city', 'address_1' ) as $part ) {
			if ( '' === trim( (string) $customer->{"get_billing_{$part}"}() ) ) {
				return false;
			}
		}

		$changed = false;
		foreach ( self::FORCED_SHIPPING_PARTS as $part ) {
			$billing = (string) $customer->{"get_billing_{$part}"}();
			if ( (string) $customer->{"get_shipping_{$part}"}() !== $billing ) {
				$customer->{"set_shipping_{$part}"}( $billing );
				$changed = true;
			}
		}

		return $changed;
	}

	/**
	 * Whether WooCommerce is set to ship to the billing address only.
	 */
	private function ships_to_billing_only(): bool {
		return function_exists( 'wc_ship_to_billing_address_only' ) && wc_ship_to_billing_address_only();
	}

	/**
	 * Localizes the Chilean phone and city fields.
	 *
	 * @internal
	 *
	 * @param array<string,array<string,mixed>> $locales WooCommerce locales.
	 * @return array<string,array<string,mixed>>
	 */
	public function localize_chilean_checkout_fields( array $locales ): array {
		$phone = $locales['CL']['phone'] ?? array();
		$city  = $locales['CL']['city'] ?? array();
		$phone = is_array( $phone ) ? $phone : array();
		$city  = is_array( $city ) ? $city : array();

		$locales['CL']['phone'] = array_merge(
			$phone,
			array(
				'label'        => 'Celular',
				// Placeholder con numeración nacional válida (excluye prefijos no móviles).
				'placeholder'  => '8765 4321',
				'required'     => true,
				'autocomplete' => 'tel-national',
				'inputmode'    => 'tel',
				'type'         => 'tel',
			)
		);
		$locales['CL']['city'] = array_merge(
			$city,
			array(
				'label'       => 'Comuna',
				'placeholder' => 'Ejemplo: Providencia',
			)
		);

		$state = $locales['CL']['state'] ?? array();
		$state = is_array( $state ) ? $state : array();

		// Región must precede Comuna so the combobox filters a chosen region,
		// matching the dependent-select flow of the classic checkout.
		$locales['CL']['state'] = array_merge(
			$state,
			array(
				'priority' => 69,
			)
		);

		return $locales;
	}

	/**
	 * Canonicalizes customer commune spelling before shipping recalculation.
	 *
	 * Runs on the Store API customer update so zone matching and the Blue
	 * Express quote receive the canonical accents ("nunoa" -> "Ñuñoa"). Only
	 * exact normalized matches are rewritten; partial input is never touched.
	 *
	 * @internal
	 *
	 * @param mixed $customer WooCommerce customer being updated.
	 */
	public function canonicalize_customer_comuna( $customer ): void {
		if ( null === $this->comunas || ! is_object( $customer ) ) {
			return;
		}

		foreach ( array( 'shipping', 'billing' ) as $group ) {
			$this->canonicalize_address_comuna( $customer, $group );
		}
	}

	/**
	 * Canonicalizes order commune spelling assembled by Checkout Blocks.
	 *
	 * @internal
	 *
	 * @param mixed $order WooCommerce order being populated from the request.
	 */
	public function canonicalize_order_comuna( $order ): void {
		if ( null === $this->comunas || ! is_object( $order ) ) {
			return;
		}

		foreach ( array( 'shipping', 'billing' ) as $group ) {
			$this->canonicalize_address_comuna( $order, $group );
		}
	}

	/**
	 * Validates the final Chilean communes before a Store API checkout is paid.
	 *
	 * Fails open: when the catalog is unavailable, or the address is not
	 * Chilean, the checkout proceeds untouched.
	 *
	 * @internal
	 *
	 * @param WC_Order $order  Order assembled by Checkout Blocks.
	 * @param WP_Error $errors Validation errors.
	 */
	public function validate_chilean_comuna( WC_Order $order, WP_Error $errors ): void {
		if ( null === $this->comunas || ! $this->comunas->is_available() ) {
			return;
		}

		foreach ( array( 'shipping', 'billing' ) as $group ) {
			$country = $this->read_address_part( $order, $group, 'country' );
			$city    = $this->read_address_part( $order, $group, 'city' );
			$region  = $this->read_address_part( $order, $group, 'state' );

			if ( 'CL' !== $country || '' === $city ) {
				continue;
			}

			$match = $this->comunas->match( $city );

			if ( null !== $match && ( '' === $region || $match['region'] === $region ) ) {
				continue;
			}

			if ( null !== $match ) {
				$errors->add(
					'akibara_comuna_region_mismatch',
					sprintf( '"%s" no pertenece a la región seleccionada. Revisa la región o corrige la comuna.', $match['name'] )
				);
				return;
			}

			$suggestion = $this->comunas->suggest( $city, $region );
			$message    = null !== $suggestion
				? sprintf( 'No encontramos la comuna "%s". ¿Quisiste decir "%s"?', $city, $suggestion )
				: sprintf( 'No encontramos la comuna "%s". Selecciónala de la lista de comunas.', $city );

			$errors->add( 'akibara_comuna_invalid', $message );
			return;
		}
	}

	/**
	 * Rewrites one address group's commune to its canonical spelling.
	 *
	 * @param object $subject WooCommerce order or customer.
	 * @param string $group   Either `billing` or `shipping`.
	 */
	private function canonicalize_address_comuna( object $subject, string $group ): void {
		$country = $this->read_address_part( $subject, $group, 'country' );
		$city    = $this->read_address_part( $subject, $group, 'city' );

		if ( 'CL' !== $country || '' === $city || null === $this->comunas ) {
			return;
		}

		$match = $this->comunas->match( $city );
		if ( null === $match || $match['name'] === $city ) {
			return;
		}

		$setter = 'set_' . $group . '_city';
		if ( method_exists( $subject, $setter ) ) {
			$subject->{$setter}( $match['name'] );
		}
	}

	/**
	 * Reads one sanitized address component from an order or customer.
	 *
	 * @param object $subject WooCommerce order or customer.
	 * @param string $group   Either `billing` or `shipping`.
	 * @param string $part    Address component: `country`, `state` or `city`.
	 */
	private function read_address_part( object $subject, string $group, string $part ): string {
		$getter = 'get_' . $group . '_' . $part;
		if ( ! method_exists( $subject, $getter ) ) {
			return '';
		}

		return sanitize_text_field( (string) $subject->{$getter}() );
	}

	/**
	 * Registers the RUT additional checkout field once.
	 *
	 * @internal
	 */
	public function register_rut_field(): void {
		if ( $this->rut_field_registered || ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}

		woocommerce_register_additional_checkout_field(
			array(
				'id'                         => self::RUT_FIELD_ID,
				'label'                      => 'RUT',
				'location'                   => 'contact',
				'type'                       => 'text',
				'required'                   => true,
				'show_in_order_confirmation' => false,
				'attributes'                 => array(
					'autocomplete' => 'off',
					'maxLength'    => '13',
					'pattern'      => '[0-9.\\-Kk]+',
				),
				'sanitize_callback'          => array( $this, 'sanitize_rut' ),
				'validate_callback'          => array( $this, 'validate_rut' ),
			)
		);

		$this->rut_field_registered = true;
	}

	/**
	 * Sanitizes a RUT supplied by the Store API.
	 *
	 * Canoniza en el punto de entrada porque las otras dos rutas del MISMO dato
	 * ya lo hacen: `sync_rut_to_legacy_meta()` escribe `format_rut()` en
	 * `_billing_rut`/`billing_rut` y `default_rut_from_legacy_meta()` devuelve
	 * `format_rut()`. Sin esto, teclear «123456785» dejaba el campo nativo con
	 * '123456785' y la meta legada con '12.345.678-5' — dos escrituras del
	 * mismo RUT en el mismo pedido. `format_rut()` es idempotente, así que un
	 * valor ya canónico no se toca.
	 *
	 * Un RUT inválido se devuelve tal cual: maquillarlo escondería lo que
	 * `validate_rut()` tiene que rechazar y le devolvería al cliente un valor
	 * que él no escribió.
	 *
	 * @internal
	 *
	 * @param mixed               $value Field value.
	 * @param array<string,mixed> $field Field definition.
	 * @return string
	 */
	public function sanitize_rut( $value, array $field ): string {
		unset( $field );

		$raw = sanitize_text_field( (string) $value );

		return $this->data_provider->is_valid_rut( $raw )
			? $this->data_provider->format_rut( $raw )
			: $raw;
	}

	/**
	 * Validates a RUT supplied by the Store API.
	 *
	 * @internal
	 *
	 * @param mixed               $value Field value.
	 * @param array<string,mixed> $field Field definition.
	 * @return true|WP_Error
	 */
	public function validate_rut( $value, array $field ) {
		unset( $field );

		if ( $this->data_provider->is_valid_rut( (string) $value ) ) {
			return true;
		}

		return new WP_Error(
			'akibara_invalid_rut',
			'El RUT ingresado no es válido. Verifica el formato y el dígito verificador.'
		);
	}

	/**
	 * Valida los teléfonos finales de facturación y envío antes del pago
	 * en Store API. Comparte los criterios de checkout-phone.js y rechaza
	 * un teléfono de entrega inválido aunque el cliente no ejecute JavaScript.
	 *
	 * @internal
	 * @param WC_Order $order  Order assembled by Checkout Blocks.
	 * @param WP_Error $errors Validation errors.
	 */
	public function validate_billing_phone( WC_Order $order, WP_Error $errors ): void {
		$telefonos = array(
			'facturación' => (string) $order->get_billing_phone(),
			'envío'       => method_exists( $order, 'get_shipping_phone' ) ? (string) $order->get_shipping_phone() : '',
		);

		$vistos = array();
		foreach ( $telefonos as $cual => $crudo ) {
			$raw = sanitize_text_field( $crudo );
			if ( '' === $raw || isset( $vistos[ $raw ] ) ) {
				// Vacío no es error —el campo puede ser opcional— y repetir el
				// mismo número en los dos campos no merece dos avisos iguales.
				continue;
			}
			$vistos[ $raw ] = true;

			$error = $this->data_provider->phone_validation_error( $raw );
			if ( null !== $error ) {
				$errors->add(
					$error['code'],
					2 === count( $telefonos ) && '' !== sanitize_text_field( $telefonos['envío'] ) && '' !== sanitize_text_field( $telefonos['facturación'] )
						? sprintf( '%s (celular de %s)', $error['message'], $cual )
						: $error['message']
				);
			}
		}
	}

	/**
	 * Supplies the native additional field from legacy customer/order metadata.
	 *
	 * @internal
	 *
	 * @param mixed  $default   Default supplied by WooCommerce.
	 * @param string $group     Additional field group.
	 * @param mixed  $wc_object WooCommerce customer or order.
	 * @return string|null
	 */
	public function default_rut_from_legacy_meta( $default, string $group, $wc_object ): ?string {
		unset( $group );

		if ( null !== $default || ! is_object( $wc_object ) || ! method_exists( $wc_object, 'get_meta' ) ) {
			return is_string( $default ) ? $default : null;
		}

		$meta_key = $wc_object instanceof WC_Order ? '_billing_rut' : 'billing_rut';
		$raw      = sanitize_text_field( (string) $wc_object->get_meta( $meta_key, true ) );

		if ( ! $this->data_provider->is_valid_rut( $raw ) ) {
			return null;
		}

		return $this->data_provider->format_rut( $raw );
	}

	/**
	 * Synchronizes WooCommerce's native additional field with legacy metadata.
	 *
	 * This hook is emitted by the official Checkout Fields persistence service
	 * for both the order and its customer, so no request parsing is needed.
	 *
	 * @internal
	 *
	 * @param string $key       Additional field id.
	 * @param mixed  $value     Persisted field value.
	 * @param string $group     Additional field group.
	 * @param mixed  $wc_object WooCommerce customer or order.
	 */
	public function sync_rut_to_legacy_meta( string $key, $value, string $group, $wc_object ): void {
		unset( $group );

		if ( self::RUT_FIELD_ID !== $key || ( ! $wc_object instanceof WC_Order && ! $wc_object instanceof WC_Customer ) ) {
			return;
		}

		$raw = sanitize_text_field( (string) $value );
		if ( ! $this->data_provider->is_valid_rut( $raw ) ) {
			return;
		}

		$formatted = $this->data_provider->format_rut( $raw );
		$meta_key  = $wc_object instanceof WC_Order ? '_billing_rut' : 'billing_rut';

		$wc_object->update_meta_data( $meta_key, $formatted );
	}
}
