<?php
/**
 * Request, selection and route validation helpers for BlueX PUDO integration.
 *
 * @package Akibara\CartCheckoutBlocks
 */

declare( strict_types = 1 );

namespace Akibara\CartCheckoutBlocks;

defined( 'ABSPATH' ) || exit;

/**
 * Validates Store API PUDO payloads, canonical selections and shipping rate identifiers.
 *
 * @internal
 * @since 2.2.38
 */
final class PudoPayloadValidator {

	public const METHOD_ID = 'bluex-pudo';

	private const DELIVERY_METHOD_ID = 'bluex-ex';

	private const ORIGINAL_METHOD_META = '_bluex_original_method';

	/**
	 * Accepts only the agency ID and optional minimal location hints.
	 *
	 * @param mixed $data Raw extension data.
	 * @return array{agency_id:string,address:string,region_code:string,attempt_id:string}
	 */
	public static function normalize_client_request( $data ): array {
		if ( ! is_array( $data ) ) {
			self::throw_route_error( 'akibara_pudo_invalid_payload', 'La selección del punto de retiro no tiene un formato válido.' );
		}

		$allowed = array( 'agency_id', 'address', 'region_code', 'attempt_id' );
		if ( array_diff( array_keys( $data ), $allowed ) ) {
			self::throw_route_error( 'akibara_pudo_untrusted_payload', 'La selección contiene datos no permitidos. Selecciona el punto nuevamente.' );
		}
		foreach ( $allowed as $key ) {
			if ( array_key_exists( $key, $data ) && ! is_string( $data[ $key ] ) ) {
				self::throw_route_error( 'akibara_pudo_invalid_payload', 'La selección del punto de retiro no tiene un formato válido.' );
			}
		}

		$agency_id  = trim( (string) ( $data['agency_id'] ?? '' ) );
		$address    = trim( (string) ( $data['address'] ?? '' ) );
		$region     = strtoupper( trim( (string) ( $data['region_code'] ?? '' ) ) );
		$attempt_id = strtolower( trim( (string) ( $data['attempt_id'] ?? '' ) ) );

		if (
			strlen( $agency_id ) > 64
			|| ( '' !== $agency_id && 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9._:-]*$/', $agency_id ) )
			|| strlen( $address ) > 200
			|| ( '' !== $region && 1 !== preg_match( '/^CL-[A-Z]{2}$/', $region ) )
			|| 1 !== preg_match( '/\A[a-f0-9]{32}\z/', $attempt_id )
		) {
			self::throw_route_error( 'akibara_pudo_invalid_payload', 'La selección del punto de retiro no tiene un formato válido.' );
		}

		return array(
			'agency_id'   => $agency_id,
			'address'     => $address,
			'region_code' => $region,
			'attempt_id'  => $attempt_id,
		);
	}

	/**
	 * Returns a valid server-side Chilean package destination.
	 *
	 * @param array<string,mixed> $package Shipping package.
	 * @return array{address:string,region:string}|null
	 */
	public static function package_destination( array $package ): ?array {
		$destination = $package['destination'] ?? null;
		if ( ! is_array( $destination ) || 'CL' !== strtoupper( (string) ( $destination['country'] ?? '' ) ) ) {
			return null;
		}

		$region  = strtoupper( trim( (string) ( $destination['state'] ?? '' ) ) );
		$address = trim( (string) ( $destination['city'] ?? '' ) );

		if ( '' === $address || 1 !== preg_match( '/^CL-[A-Z]{2}$/', $region ) ) {
			return null;
		}

		return array(
			'address' => $address,
			'region'  => $region,
		);
	}

	/**
	 * Returns the canonical subset consumed by the BlueX order adapter.
	 *
	 * @param array<string,mixed> $selection Business selection.
	 * @return array{
	 *   agency_id:string,
	 *   agency_name:string,
	 *   agency_address:string,
	 *   shipping_address:array{
	 *     address_1:string,
	 *     address_2:string,
	 *     city:string,
	 *     state:string,
	 *     country:string,
	 *     postcode:string
	 *   }
	 * }|null
	 */
	public static function canonical_selection( array $selection ): ?array {
		if (
			! is_string( $selection['agency_id'] ?? null )
			|| ! is_string( $selection['agency_name'] ?? null )
			|| ! is_string( $selection['agency_address'] ?? null )
			|| ! is_array( $selection['shipping_address'] ?? null )
		) {
			return null;
		}

		$agency_id        = trim( $selection['agency_id'] );
		$agency_name      = trim( $selection['agency_name'] );
		$agency_address   = trim( $selection['agency_address'] );
		$shipping_address = self::canonical_shipping_address( $selection['shipping_address'] );

		if (
			'' === $agency_id
			|| '' === $agency_name
			|| '' === $agency_address
			|| strlen( $agency_id ) > 64
			|| 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9._:-]*$/', $agency_id )
			|| strlen( $agency_name ) > 160
			|| strlen( $agency_address ) > 240
			|| 1 === preg_match( '/[\x00-\x1F\x7F]/', $agency_name . $agency_address )
			|| null === $shipping_address
		) {
			return null;
		}

		return array(
			'agency_id'        => $agency_id,
			'agency_name'      => $agency_name,
			'agency_address'   => $agency_address,
			'shipping_address' => $shipping_address,
		);
	}

	/**
	 * Validates the complete order-only shipping address confirmed by Business.
	 *
	 * @param array<string,mixed> $address Canonical provider address.
	 * @return array{
	 *   address_1:string,
	 *   address_2:string,
	 *   city:string,
	 *   state:string,
	 *   country:string,
	 *   postcode:string
	 * }|null
	 */
	private static function canonical_shipping_address( array $address ): ?array {
		$fields = array( 'address_1', 'address_2', 'city', 'state', 'country', 'postcode' );
		if ( array_diff( $fields, array_keys( $address ) ) || array_diff( array_keys( $address ), $fields ) ) {
			return null;
		}

		$canonical = array();
		foreach ( $fields as $field ) {
			if ( ! is_string( $address[ $field ] ) ) {
				return null;
			}
			$canonical[ $field ] = trim( $address[ $field ] );
		}

		if (
			'' === $canonical['address_1']
			|| '' === $canonical['city']
			|| 200 < strlen( $canonical['address_1'] )
			|| 160 < strlen( $canonical['address_2'] )
			|| 120 < strlen( $canonical['city'] )
			|| 20 < strlen( $canonical['postcode'] )
			|| 1 !== preg_match( '/\ACL-[A-Z]{2}\z/', $canonical['state'] )
			|| 'CL' !== $canonical['country']
			|| 1 === preg_match( '/[\x00-\x1F\x7F]/', implode( '', $canonical ) )
		) {
			return null;
		}

		return $canonical;
	}

	/**
	 * Returns whether an order currently contains a PUDO shipping line.
	 *
	 * @param object $order Checkout order.
	 */
	public static function order_uses_pudo( object $order ): bool {
		if ( ! method_exists( $order, 'get_items' ) ) {
			return false;
		}

		$items = $order->get_items( 'shipping' );
		if ( ! is_iterable( $items ) ) {
			return false;
		}

		foreach ( $items as $item ) {
			if ( ! is_object( $item ) || ! method_exists( $item, 'get_method_id' ) ) {
				continue;
			}

			$method_id = (string) $item->get_method_id();
			if ( self::is_pudo_method( $method_id ) ) {
				return true;
			}

			if (
				self::DELIVERY_METHOD_ID === $method_id
				&& method_exists( $item, 'get_meta' )
				&& self::METHOD_ID === (string) $item->get_meta( self::ORIGINAL_METHOD_META, true )
			) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns whether a shipping method identifier belongs to PUDO.
	 *
	 * @param string $method_id Shipping method or rate ID.
	 */
	public static function is_pudo_method( string $method_id ): bool {
		return self::METHOD_ID === $method_id || 0 === strpos( $method_id, self::METHOD_ID . ':' );
	}

	/**
	 * Removes only BlueX PUDO rates when the canonical service cannot validate.
	 *
	 * @param array<string|int,mixed> $rates Package rates.
	 * @return array<string|int,mixed>
	 */
	public static function without_pudo_rates( array $rates ): array {
		foreach ( $rates as $key => $rate ) {
			$method_id = '';
			if ( is_object( $rate ) && method_exists( $rate, 'get_method_id' ) ) {
				$method_id = (string) $rate->get_method_id();
			} elseif ( is_array( $rate ) ) {
				$method_id = (string) ( $rate['method_id'] ?? '' );
			}

			if ( self::is_pudo_method( $method_id ) ) {
				unset( $rates[ $key ] );
			}
		}

		return $rates;
	}

	/**
	 * Throws a Store API route error without leaking service details.
	 *
	 * @param string $code    Stable error code.
	 * @param string $message Shopper-facing recovery message.
	 * @param int    $status  HTTP status.
	 * @return never
	 */
	public static function throw_route_error( string $code, string $message, int $status = 400 ): void {
		if ( class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( esc_html( $code ), esc_html( $message ), absint( $status ) );
		}

		throw new \RuntimeException( esc_html( $message ), absint( $status ) );
	}
}
