<?php
/**
 * Public first-party domain service for Blue Express PUDO checkout.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Integrations\Bluex\Pudo;

defined( 'ABSPATH' ) || exit;

/**
 * Owns canonical selection, quote validation and checkout eligibility.
 *
 * @since 1.96.0
 */
final class PudoCheckoutService {

	private const SHIPPING_GENERATION_KEY = '_akb_pudo_generation';

	private PickupPointProvider $provider;
	private PudoSelectionStore $store;
	private PudoMutationGuard $guard;
	private \Closure $clock;
	private \Closure $rate_fee;

	/**
	 * Creates the PUDO domain service.
	 *
	 * @param PickupPointProvider|null $provider Pickup point API adapter.
	 * @param PudoSelectionStore|null  $store    Canonical session projector.
	 * @param PudoMutationGuard|null   $guard    Authoritative concurrency guard.
	 * @param callable|null            $clock    Unix timestamp source.
	 * @param callable|null            $rate_fee Recargo que Blue suma a la tarifa: `( string $rate_id, float $total): float`.
	 *
	 * @since 1.96.0
	 */
	public function __construct(
		?PickupPointProvider $provider = null,
		?PudoSelectionStore $store = null,
		?PudoMutationGuard $guard = null,
		?callable $clock = null,
		?callable $rate_fee = null
	) {
		$this->provider = $provider ?? new PickupPointProvider();
		$this->store    = $store ?? new PudoSelectionStore();
		$this->guard    = $guard ?? new PudoMutationGuard();
		$this->clock    = \Closure::fromCallable( $clock ?? static fn(): int => time() );
		$this->rate_fee = \Closure::fromCallable( $rate_fee ?? array( self::class, 'shipping_method_fee' ) );
	}

	/**
	 * Recargo de la instancia de envío aplicado a la tarifa de Blue.
	 *
	 * WC_BlueX_Pudo::calculate_shipping() cobra Valor + get_fee(fee, Valor).
	 * La cotización independiente debe aplicar el mismo recargo de la instancia
	 * para comparar importes equivalentes, incluido su tratamiento de IVA.
	 * Sin una instancia legible se devuelve cero y la comparación falla cerrada.
	 *
	 * @param string $rate_id Rate ID (`bluex-pudo:112`).
	 * @param float  $total   Quoted total.
	 */
	public static function shipping_method_fee( string $rate_id, float $total ): float {
		$separador = strrpos( $rate_id, ':' );
		$instancia = false === $separador ? 0 : (int) substr( $rate_id, $separador + 1 );
		if ( $instancia <= 0 || ! class_exists( '\WC_Shipping_Zones' ) ) {
			return 0.0;
		}

		try {
			$metodo = \WC_Shipping_Zones::get_shipping_method( $instancia );
			if ( ! is_object( $metodo ) || ! method_exists( $metodo, 'get_fee' ) || ! isset( $metodo->fee ) ) {
				return 0.0;
			}
			$recargo = (float) $metodo->get_fee( (string) $metodo->fee, $total );
		} catch ( \Throwable $throwable ) {
			return 0.0;
		}

		return is_finite( $recargo ) && 0.0 <= $recargo ? $recargo : 0.0;
	}

	/**
	 * Canonicalizes a Store API request, trusting only its agency ID.
	 *
	 * @param array<string, mixed>                   $request       Extension request.
	 * @param string                                 $server_address Server-side address/city.
	 * @param string                                 $server_region Server-side region.
	 * @param array<int|string, array<string,mixed>> $packages      Shipping packages.
	 * @param object                                 $session       WooCommerce session.
	 * @param int|null                               $now           Current timestamp.
	 * @return array<string, mixed>|null
	 *
	 * @since 1.96.0
	 */
	public function canonicalize_request(
		array $request,
		string $server_address,
		string $server_region,
		array $packages,
		object $session,
		?int $now = null
	): ?array {
		$agency_id = $request['agency_id'] ?? '';
		$attempt_id = $request['attempt_id'] ?? '';
		if ( ! is_string( $attempt_id ) ) {
			throw new PudoValidationException(
				'pudo_invalid_attempt',
				'La solicitud del punto de retiro no es válida. Inténtalo nuevamente.'
			);
		}
		if ( null === $agency_id || '' === $agency_id ) {
			$this->clear_selection( $session, $attempt_id, $now );
			return null;
		}
		if ( ! is_string( $agency_id ) && ! is_int( $agency_id ) ) {
			throw new PudoValidationException(
				'pudo_invalid_agency',
				'El punto de retiro seleccionado no es válido.'
			);
		}

		return $this->select_agency(
			(string) $agency_id,
			$server_address,
			$server_region,
			$packages,
			$session,
			$now,
			$attempt_id
		);
	}

	/**
	 * Resolves, quotes and stores a pickup point from its agency ID.
	 *
	 * @param string                                 $agency_id      Browser-provided agency ID.
	 * @param string                                 $server_address Server-side address/city.
	 * @param string                                 $server_region  Server-side region.
	 * @param array<int|string, array<string,mixed>> $packages       Shipping packages.
	 * @param object                                 $session        WooCommerce session.
	 * @param int|null                               $now            Current timestamp.
	 * @param string                                 $attempt_id     Browser correlation ID.
	 * @return array<string, mixed>
	 *
	 * @since 1.96.0
	 */
	public function select_agency(
		string $agency_id,
		string $server_address,
		string $server_region,
		array $packages,
		object $session,
		?int $now = null,
		string $attempt_id = ''
	): array {
		if ( '' === $attempt_id ) {
			$attempt_id = $this->guard->server_attempt_id();
		}

		$timestamp   = $now ?? (int) ( $this->clock )();
		$fingerprint = $this->cart_fingerprint( $packages );
		$lease       = $this->guard->begin_selection(
			$session,
			$attempt_id,
			$fingerprint,
			$timestamp
		);

		try {
			$pickup          = $this->provider->resolve( $agency_id, $server_address, $server_region );
			$pickup['quote'] = $this->provider->quote( $pickup, $packages );
			$completion_time = $now ?? (int) ( $this->clock )();
			$prepared        = $this->store->prepare( $pickup, $fingerprint, $completion_time );
		} catch ( \Throwable $throwable ) {
			$failure_time = $now ?? (int) ( $this->clock )();
			if ( ! $this->guard->fail_selection( $lease, $failure_time ) ) {
				throw $this->stale_attempt_exception( $throwable ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- No es salida: se relanza un PudoValidationException cuyo mensaje es un literal seguro.
			}

			$this->store->clear( $session );
			throw $throwable;
		}

		if ( ! $this->guard->commit_selection( $lease, $prepared, $completion_time ) ) {
			throw $this->stale_attempt_exception();
		}

		$this->store->hydrate( $prepared, $session );

		return $prepared;
	}

	/**
	 * Returns the current canonical selection for the exact cart.
	 *
	 * @param array<int|string, array<string,mixed>> $packages Shipping packages.
	 * @param object                                 $session  WooCommerce session.
	 * @param int|null                               $now      Current timestamp.
	 * @return array<string, mixed>|null
	 *
	 * @since 1.96.0
	 */
	public function get_selection( array $packages, object $session, ?int $now = null ): ?array {
		$timestamp   = $now ?? (int) ( $this->clock )();
		$fingerprint = $this->cart_fingerprint( $packages );
		$selection   = $this->guard->current_selection(
			$session,
			$fingerprint,
			$timestamp
		);
		if ( null === $selection ) {
			$this->store->clear( $session );
			return null;
		}

		$selection = $this->store->validate( $selection, $fingerprint, $timestamp );
		if ( null === $selection ) {
			$this->store->clear( $session );
			return null;
		}

		$this->store->hydrate( $selection, $session );
		return $selection;
	}

	/**
	 * Clears the current selection and BlueX compatibility keys.
	 *
	 * An explicit attempt ID creates an authoritative tombstone. Calls without
	 * an attempt ID only discard the non-authoritative Woo session projection;
	 * this prevents an obsolete request's error handler from clearing a newer
	 * committed selection.
	 *
	 * @param object      $session    WooCommerce session.
	 * @param string|null $attempt_id Explicit client/server correlation ID.
	 * @param int|null    $now        Current timestamp.
	 * @return void
	 *
	 * @since 1.96.0
	 */
	public function clear_selection(
		object $session,
		?string $attempt_id = null,
		?int $now = null
	): void {
		if ( null !== $attempt_id ) {
			$this->guard->clear( $session, $attempt_id, $now ?? (int) ( $this->clock )() );
		}
		$this->store->clear( $session );
	}

	/**
	 * Creates a server-owned tombstone for an official shipping transition.
	 *
	 * @param object   $session WooCommerce session.
	 * @param int|null $now     Current timestamp.
	 */
	public function invalidate_selection( object $session, ?int $now = null ): void {
		$this->clear_selection(
			$session,
			$this->guard->server_attempt_id(),
			$now
		);
	}

	/**
	 * Computes the sole canonical fingerprint for PUDO cart state.
	 *
	 * @param array<int|string, array<string,mixed>> $packages Shipping packages.
	 * @return string
	 *
	 * @since 1.96.0
	 */
	public function cart_fingerprint( array $packages ): string {
		$normalized = array();
		foreach ( array_values( $packages ) as $package_index => $package ) {
			if ( ! is_array( $package ) ) {
				throw new \InvalidArgumentException( 'Invalid WooCommerce shipping package.' );
			}

			$contents = array();
			foreach ( (array) ( $package['contents'] ?? array() ) as $cart_key => $item ) {
				if ( ! is_array( $item ) ) {
					throw new \InvalidArgumentException( 'Invalid WooCommerce cart item.' );
				}

				$contents[] = array(
					'cart_key'     => (string) $cart_key,
					'product_id'   => (int) ( $item['product_id'] ?? 0 ),
					'variation_id' => (int) ( $item['variation_id'] ?? 0 ),
					'quantity'     => (int) ( $item['quantity'] ?? 0 ),
					'line_total'   => self::fingerprint_number( $item['line_total'] ?? null ),
				);
			}
			usort(
				$contents,
				static fn( array $left, array $right ): int => strcmp( $left['cart_key'], $right['cart_key'] )
			);

			$destination = is_array( $package['destination'] ?? null )
				? $package['destination']
				: array();
			$normalized[] = array(
				'package_index' => $package_index,
				'contents'      => $contents,
				'contents_cost' => self::fingerprint_number( $package['contents_cost'] ?? null ),
				'destination'   => array(
					'country'  => (string) ( $destination['country'] ?? '' ),
					'state'    => (string) ( $destination['state'] ?? '' ),
					'city'     => (string) ( $destination['city'] ?? '' ),
					'postcode' => (string) ( $destination['postcode'] ?? '' ),
				),
			);
		}

		$json = json_encode(
			array(
				'schema_version' => 1,
				'packages'       => $normalized,
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
		);
		if ( false === $json ) {
			throw new \RuntimeException( 'Unable to fingerprint WooCommerce cart packages.' );
		}

		return hash( 'sha256', $json );
	}

	/**
	 * Reconciles the session projection before WooCommerce hashes shipping packages.
	 *
	 * @param array<int|string, array<string,mixed>> $packages Shipping packages.
	 * @param object                                 $session  WooCommerce session.
	 * @param int|null                               $now      Current timestamp.
	 * @return array<int|string, array<string,mixed>>
	 *
	 * @since 1.96.0
	 */
	public function prepare_shipping_packages(
		array $packages,
		object $session,
		?int $now = null
	): array {
		$timestamp   = $now ?? (int) ( $this->clock )();
		$fingerprint = $this->cart_fingerprint( $packages );
		$snapshot    = $this->guard->shipping_snapshot( $session, $fingerprint, $timestamp );
		$generation  = $snapshot['generation'];
		$selection   = $snapshot['selection'];

		if ( is_array( $selection ) ) {
			$selection = $this->store->validate( $selection, $fingerprint, $timestamp );
		}

		if ( is_array( $selection ) ) {
			$this->store->hydrate( $selection, $session );
		} else {
			$this->store->clear( $session );
			$generation = hash( 'sha256', $generation . '|no-selection' );
		}

		foreach ( $packages as $key => $package ) {
			if ( ! is_array( $package ) ) {
				throw new \InvalidArgumentException( 'Invalid WooCommerce shipping package.' );
			}

			$packages[ $key ][ self::SHIPPING_GENERATION_KEY ] = $generation;
		}

		return $packages;
	}

	/**
	 * Removes unsafe PUDO rates while preserving the initial selector wrapper.
	 *
	 * @param array<string|int, mixed>               $rates    Package rates.
	 * @param array<int|string, array<string,mixed>> $packages Shipping packages.
	 * @param object                                 $session  WooCommerce session.
	 * @param int|null                               $now      Current timestamp.
	 * @return array<string|int, mixed>
	 *
	 * @since 1.96.0
	 */
	public function filter_package_rates(
		array $rates,
		array $packages,
		object $session,
		?int $now = null
	): array {
		$selection = 1 === count( $packages )
			? $this->get_selection( $packages, $session, $now )
			: null;

		foreach ( $rates as $key => $rate ) {
			if ( 'bluex-pudo' !== $this->rate_method_id( $rate ) ) {
				continue;
			}

			if ( 1 !== count( $packages ) ) {
				unset( $rates[ $key ] );
				continue;
			}

			if ( null !== $selection && ! $this->is_valid_quoted_rate( $rate, $selection ) ) {
				unset( $rates[ $key ] );
			}
		}

		return $rates;
	}

	/**
	 * Validates the final selected shipping method before order creation.
	 *
	 * @param array<int|string, array<string,mixed>> $packages       Shipping packages.
	 * @param array<int|string, string>              $chosen_methods Chosen rate IDs.
	 * @param object                                 $session        WooCommerce session.
	 * @param int|null                               $now            Current timestamp.
	 * @return array{
	 *   is_pudo: bool,
	 *   selection: array<string,mixed>|null,
	 *   shipping_method: string|null,
	 *   checkout_claim: array<string,mixed>|null
	 * }
	 *
	 * @throws PudoValidationException When a PUDO checkout cannot be trusted.
	 *
	 * @since 1.96.0
	 */
	public function validate_checkout(
		array $packages,
		array $chosen_methods,
		object $session,
		?int $now = null
	): array {
		$pudo_rate_id = null;
		foreach ( $chosen_methods as $rate_id ) {
			if ( is_string( $rate_id ) && str_starts_with( $rate_id, 'bluex-pudo' ) ) {
				$pudo_rate_id = $rate_id;
				break;
			}
		}

		if ( null === $pudo_rate_id ) {
			return array(
				'is_pudo'        => false,
				'selection'      => null,
				'shipping_method' => null,
				'checkout_claim'  => null,
			);
		}

		if ( 1 !== count( $packages ) ) {
			throw new PudoValidationException(
				'pudo_multiple_packages',
				'El retiro en Punto Blue Express no está disponible para pedidos divididos en varios envíos.'
			);
		}

		$selection = $this->get_selection( $packages, $session, $now );
		if ( null === $selection ) {
			throw new PudoValidationException(
				'pudo_selection_missing',
				'Selecciona nuevamente un punto de retiro Blue Express.'
			);
		}

		$package = reset( $packages );
		$rates   = is_array( $package ) && is_array( $package['rates'] ?? null )
			? $package['rates']
			: array();
		$rate    = $rates[ $pudo_rate_id ] ?? null;
		if ( null === $rate ) {
			foreach ( $rates as $candidate ) {
				if ( $pudo_rate_id === $this->rate_id( $candidate ) ) {
					$rate = $candidate;
					break;
				}
			}
		}

		if ( null === $rate || ! $this->is_valid_quoted_rate( $rate, $selection ) ) {
			throw new PudoValidationException(
				'pudo_quote_invalid',
				'No pudimos confirmar la tarifa del punto de retiro. Selecciónalo nuevamente.'
			);
		}

		$timestamp = $now ?? (int) ( $this->clock )();
		$claim     = $this->guard->claim_for_checkout(
			$session,
			$this->cart_fingerprint( $packages ),
			$timestamp
		);
		$claimed_selection = null === $claim
			? null
			: $this->store->validate( $claim['selection'], $this->cart_fingerprint( $packages ), $timestamp );
		if (
			null === $claim
			|| null === $claimed_selection
			|| ! $this->is_valid_quoted_rate( $rate, $claimed_selection )
		) {
			if ( null !== $claim ) {
				$this->guard->release_checkout_claim( $claim, $timestamp );
			}
			throw new PudoValidationException(
				'pudo_checkout_changed',
				'El punto de retiro cambió mientras confirmábamos el pedido. Revísalo e inténtalo nuevamente.'
			);
		}
		$this->store->hydrate( $claimed_selection, $session );

		return array(
			'is_pudo'         => true,
			'selection'       => $claimed_selection,
			'shipping_method' => 'bluex-pudo',
			'checkout_claim'  => $claim,
		);
	}

	/**
	 * Releases an exact final-checkout claim after order data was copied.
	 *
	 * @param array<string,mixed> $claim Checkout claim returned by validate_checkout().
	 * @param int|null            $now   Current timestamp.
	 */
	public function release_checkout_claim( array $claim, ?int $now = null ): void {
		if ( ! $this->guard->release_checkout_claim( $claim, $now ?? (int) ( $this->clock )() ) ) {
			throw new PudoValidationException(
				'pudo_checkout_changed',
				'El punto de retiro cambió mientras confirmábamos el pedido. Revísalo e inténtalo nuevamente.'
			);
		}
	}

	/**
	 * Validates provider quote metadata against the independently stored quote.
	 *
	 * @param mixed                $rate      Shipping rate.
	 * @param array<string, mixed> $selection Canonical selection.
	 * @return bool
	 */
	private function is_valid_quoted_rate( $rate, array $selection ): bool {
		$meta  = $this->rate_meta( $rate );
		$quote = $selection['quote'] ?? null;
		$cost  = $this->rate_cost( $rate );
		if (
			'yes' !== (string) ( $meta['_bluex_pudo_quoted'] ?? '' )
			|| $selection['agency_id'] !== (string) ( $meta['_bluex_agency_id'] ?? '' )
			|| ! is_array( $quote )
			|| ! is_float( $quote['total'] ?? null )
			|| ! is_bool( $quote['is_free'] ?? null )
			|| null === $cost
		) {
			return false;
		}

		$recargo = ( $this->rate_fee )( $this->rate_id( $rate ), $quote['total'] );
		if ( ! is_float( $recargo ) || ! is_finite( $recargo ) || 0.0 > $recargo || 0.01 < abs( $cost - ( $quote['total'] + $recargo ) ) ) {
			return false;
		}

		return $quote['is_free'] ? 0.0 === $quote['total'] : 0.0 < $cost;
	}

	/**
	 * Returns the shipping method ID.
	 *
	 * @param mixed $rate Shipping rate.
	 * @return string
	 */
	private function rate_method_id( $rate ): string {
		if ( is_object( $rate ) && method_exists( $rate, 'get_method_id' ) ) {
			return (string) $rate->get_method_id();
		}

		return is_array( $rate ) ? (string) ( $rate['method_id'] ?? '' ) : '';
	}

	/**
	 * Returns the full shipping rate ID.
	 *
	 * @param mixed $rate Shipping rate.
	 * @return string
	 */
	private function rate_id( $rate ): string {
		if ( is_object( $rate ) && method_exists( $rate, 'get_id' ) ) {
			return (string) $rate->get_id();
		}

		return is_array( $rate ) ? (string) ( $rate['id'] ?? '' ) : '';
	}

	/**
	 * Returns shipping rate metadata.
	 *
	 * @param mixed $rate Shipping rate.
	 * @return array<string, mixed>
	 */
	private function rate_meta( $rate ): array {
		if ( is_object( $rate ) && method_exists( $rate, 'get_meta_data' ) ) {
			$meta = $rate->get_meta_data();
			return is_array( $meta ) ? $meta : array();
		}

		return is_array( $rate ) && is_array( $rate['meta_data'] ?? null )
			? $rate['meta_data']
			: array();
	}

	/**
	 * Returns a non-negative shipping rate cost.
	 *
	 * @param mixed $rate Shipping rate.
	 * @return float|null
	 */
	private function rate_cost( $rate ): ?float {
		$value = is_object( $rate ) && method_exists( $rate, 'get_cost' )
			? $rate->get_cost()
			: ( is_array( $rate ) ? ( $rate['cost'] ?? null ) : null );
		if ( ! is_int( $value ) && ! is_float( $value ) && ! is_string( $value ) ) {
			return null;
		}
		if ( is_string( $value ) && 1 !== preg_match( '/\A\d+(?:\.\d+)?\z/', trim( $value ) ) ) {
			return null;
		}

		$cost = (float) $value;
		return is_finite( $cost ) && 0.0 <= $cost ? $cost : null;
	}

	/**
	 * Normalizes a number for a stable fingerprint.
	 *
	 * @param mixed $value Number.
	 * @return string
	 */
	private static function fingerprint_number( $value ): string {
		if ( ! is_int( $value ) && ! is_float( $value ) && ! is_string( $value ) ) {
			return '';
		}
		if ( is_string( $value ) && 1 !== preg_match( '/\A-?\d+(?:\.\d+)?\z/', trim( $value ) ) ) {
			return '';
		}

		return number_format( (float) $value, 6, '.', '' );
	}

	/**
	 * Returns a stable conflict that cannot clear a newer request.
	 *
	 * @param \Throwable|null $previous Previous provider failure.
	 * @return PudoValidationException
	 */
	private function stale_attempt_exception( ?\Throwable $previous = null ): PudoValidationException {
		return new PudoValidationException(
			'pudo_stale_attempt',
			'Otra selección de punto de retiro reemplazó esta solicitud. Revisa el punto vigente.',
			$previous
		);
	}
}
