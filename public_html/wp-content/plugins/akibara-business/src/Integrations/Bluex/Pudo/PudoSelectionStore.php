<?php
/**
 * Non-authoritative PUDO session projection.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Integrations\Bluex\Pudo;

defined( 'ABSPATH' ) || exit;

/**
 * Validates canonical selection shape and mirrors authoritative state into the
 * Woo/BlueX session keys required by the provider compatibility contract.
 *
 * @since 1.96.0
 */
final class PudoSelectionStore {

	private const SCHEMA_VERSION = 1;
	private const SESSION_KEY = 'akb_pudo_selection';

	private int $ttl;

	/**
	 * Creates the session store with a bounded selection lifetime.
	 *
	 * El TTL predeterminado es 3600 segundos para conservar la selección durante
	 * la inactividad del checkout. La huella del carrito, el CAS del guard y la
	 * comparación tarifa/cotización siguen vigentes: una selección no autoriza
	 * cobrar una cotización sobre otro carrito. El estado akb_pudo_state_* se
	 * almacena sin autoload y expira independientemente de la sesión WooCommerce.
	 *
	 * @param int $ttl Selection lifetime in seconds.
	 *
	 * @since 1.96.0
	 */
	public function __construct( int $ttl = 3600 ) {
		if ( 60 > $ttl || 3600 < $ttl ) {
			throw new \InvalidArgumentException( 'PUDO selection TTL must be between 60 and 3600 seconds.' );
		}

		$this->ttl = $ttl;
	}

	/**
	 * Prepares canonical state without mutating WooCommerce's request snapshot.
	 *
	 * @param array<string, mixed> $selection        Canonical selection.
	 * @param string               $cart_fingerprint Server-side cart fingerprint.
	 * @param int                  $now              Current timestamp.
	 * @return array<string,mixed>
	 */
	public function prepare( array $selection, string $cart_fingerprint, int $now ): array {
		$this->assert_fingerprint( $cart_fingerprint );

		return array_merge(
			$this->assert_selection( $selection ),
			array(
				'schema_version'   => self::SCHEMA_VERSION,
				'cart_fingerprint' => $cart_fingerprint,
				'selected_at'      => $now,
				'expires_at'       => $now + $this->ttl,
			)
		);
	}

	/**
	 * Projects authoritative state into Woo/BlueX compatibility keys.
	 *
	 * Blue valida la selección cuando bluex_agency_validated_id coincide con
	 * bluex_agency_id; el método de envío, la cotización y la validación del pedido
	 * consumen esa identidad. La proyección respeta el contrato de su validador:
	 * - bluex_pudo_selected_quote_valid pasa a false sólo al cambiar la agencia;
	 *   el cálculo de Blue lo pone en true después de cotizar. Recalcular no debe
	 *   invalidar una cotización que sigue siendo válida.
	 * - bluex_agency_city y bluex_agency_state se eliminan para que la cotización
	 *   use la comuna y región del cliente, igual que PickupPointProvider::quote().
	 *
	 * @param array<string,mixed> $stored  Prepared canonical state.
	 * @param object              $session WooCommerce session.
	 */
	public function hydrate( array $stored, object $session ): void {
		$this->assert_session( $session );
		if ( ! $this->is_valid_stored_selection( $stored ) ) {
			throw new \InvalidArgumentException( 'Invalid canonical PUDO selection.' );
		}

		$agencia_anterior = $session->get( 'bluex_agency_validated_id', '' );

		$session->set( self::SESSION_KEY, $stored );
		$session->set( 'bluex_agency_id', $stored['agency_id'] );
		$session->set( 'bluex_agency_validated_id', $stored['agency_id'] );
		$session->set( 'bluex_agency_name', $stored['agency_name'] );
		$session->set( 'bluex_agency_address', $stored['agency_address'] ?? '' );
		$session->set( 'bluex_pudo_selected', true );
		if ( $agencia_anterior !== $stored['agency_id'] ) {
			$session->set( 'bluex_pudo_selected_quote_valid', false );
		}
		foreach ( array( 'bluex_agency_city', 'bluex_agency_state' ) as $key ) {
			if ( method_exists( $session, '__unset' ) ) {
				$session->__unset( $key );
			} else {
				$session->set( $key, null );
			}
		}
	}

	/**
	 * Validates authoritative state for an exact cart and timestamp.
	 *
	 * @param array<string,mixed> $stored           Prepared canonical state.
	 * @param string              $cart_fingerprint Server-side cart fingerprint.
	 * @param int                 $now              Current timestamp.
	 * @return array<string,mixed>|null
	 */
	public function validate( array $stored, string $cart_fingerprint, int $now ): ?array {
		$this->assert_fingerprint( $cart_fingerprint );
		if (
			! $this->is_valid_stored_selection( $stored )
			|| $cart_fingerprint !== $stored['cart_fingerprint']
			|| $now >= $stored['expires_at']
			|| $now < $stored['selected_at']
		) {
			return null;
		}

		return $stored;
	}

	/**
	 * Clears both first-party and compatibility session keys.
	 *
	 * @param object $session WooCommerce session.
	 * @return void
	 *
	 * @since 1.96.0
	 */
	public function clear( object $session ): void {
		$this->assert_session( $session );

		foreach (
			array(
				self::SESSION_KEY,
				'bluex_agency_id',
				'bluex_agency_validated_id',
				'bluex_agency_name',
				'bluex_agency_address',
				'bluex_agency_city',
				'bluex_agency_state',
				'bluex_pudo_selected',
				'bluex_pudo_selected_quote_valid',
			) as $key
		) {
			if ( method_exists( $session, '__unset' ) ) {
				$session->__unset( $key );
			} else {
				$session->set( $key, null );
			}
		}
	}

	/**
	 * Validates the session interface used by WooCommerce.
	 *
	 * @param object $session Session object.
	 * @return void
	 */
	private function assert_session( object $session ): void {
		if ( ! method_exists( $session, 'get' ) || ! method_exists( $session, 'set' ) ) {
			throw new \InvalidArgumentException( 'A WooCommerce-compatible session is required.' );
		}
	}

	/**
	 * Validates the SHA-256 cart fingerprint.
	 *
	 * @param string $fingerprint Fingerprint.
	 * @return void
	 */
	private function assert_fingerprint( string $fingerprint ): void {
		if ( 1 !== preg_match( '/\A[a-f0-9]{64}\z/', $fingerprint ) ) {
			throw new \InvalidArgumentException( 'A valid cart fingerprint is required.' );
		}
	}

	/**
	 * Returns only the trusted selection shape.
	 *
	 * @param array<string, mixed> $selection Selection.
	 * @return array<string, mixed>
	 */
	private function assert_selection( array $selection ): array {
		$required_strings = array(
			'agency_id',
			'agency_name',
			'region_code',
			'district_code',
			'city',
		);
		foreach ( $required_strings as $key ) {
			if ( ! is_string( $selection[ $key ] ?? null ) || '' === trim( $selection[ $key ] ) ) {
				throw new \InvalidArgumentException( 'Invalid canonical PUDO selection.' );
			}
		}

		if (
			! array_key_exists( 'agency_address', $selection )
			|| ( null !== $selection['agency_address'] && ! is_string( $selection['agency_address'] ) )
			|| ! array_key_exists( 'shipping_address', $selection )
			|| ( null !== $selection['shipping_address'] && ! is_array( $selection['shipping_address'] ) )
			|| ! is_array( $selection['quote'] ?? null )
			|| ! is_float( $selection['quote']['total'] ?? null )
			|| ! is_bool( $selection['quote']['is_free'] ?? null )
			|| ! is_int( $selection['quote']['promise_day'] ?? null )
		) {
			throw new \InvalidArgumentException( 'Invalid canonical PUDO selection.' );
		}

		return array(
			'agency_id'        => $selection['agency_id'],
			'agency_name'      => $selection['agency_name'],
			'agency_address'   => $selection['agency_address'],
			'region_code'      => $selection['region_code'],
			'district_code'    => $selection['district_code'],
			'city'             => $selection['city'],
			'shipping_address' => $selection['shipping_address'],
			'quote'            => $selection['quote'],
		);
	}

	/**
	 * Validates an untrusted session value before returning it.
	 *
	 * @param array<string, mixed> $value Stored value.
	 * @return bool
	 */
	private function is_valid_stored_selection( array $value ): bool {
		try {
			$this->assert_selection( $value );
		} catch ( \InvalidArgumentException $exception ) {
			return false;
		}

		return self::SCHEMA_VERSION === ( $value['schema_version'] ?? null )
			&& is_string( $value['cart_fingerprint'] ?? null )
			&& 1 === preg_match( '/\A[a-f0-9]{64}\z/', $value['cart_fingerprint'] )
			&& is_int( $value['selected_at'] ?? null )
			&& is_int( $value['expires_at'] ?? null )
			&& $value['expires_at'] > $value['selected_at'];
	}
}
