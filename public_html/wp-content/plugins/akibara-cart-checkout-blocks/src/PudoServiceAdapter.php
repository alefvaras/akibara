<?php
/**
 * Runtime adapter for the Akibara Business PUDO service.
 *
 * @package Akibara\CartCheckoutBlocks
 */

declare( strict_types = 1 );

namespace Akibara\CartCheckoutBlocks;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the Blocks transport decoupled from the Business implementation class.
 *
 * @since 2.1.0
 */
final class PudoServiceAdapter {

	private object $service;

	/**
	 * Initializes the feature-detected Business service.
	 *
	 * @param object $service Business PUDO service.
	 *
	 * @since 2.1.0
	 */
	public function __construct( object $service ) {
		$this->service = $service;
	}

	/**
	 * Returns whether the Business service exposes the PUDO contract this
	 * transport actually calls.
	 *
	 * La lista son EXACTAMENTE los métodos que `PudoIntegration` invoca. Antes
	 * exigía tres más que ningún llamador usaba (`select_agency`,
	 * `get_selection`, `cart_fingerprint`): que Business renombrara cualquiera
	 * de ellos devolvía false, `runtime()` daba null y `filter_package_rates`
	 * retiraba TODAS las tarifas PUDO — el retiro en punto desaparecía del
	 * checkout por un método que nadie llamaba. Añadir aquí un nombre que el
	 * transporte no invoque vuelve a comprar ese riesgo.
	 *
	 * @return bool
	 *
	 * @since 2.1.0
	 */
	public function is_available(): bool {
		foreach (
			array(
				'canonicalize_request',
				'clear_selection',
				'invalidate_selection',
				'prepare_shipping_packages',
				'filter_package_rates',
				'validate_checkout',
				'release_checkout_claim',
			) as $method
		) {
			if ( ! method_exists( $this->service, $method ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Canonicalizes a Store API request without trusting client presentation data.
	 *
	 * @param array<string,string> $request       Whitelisted request values.
	 * @param string               $server_address Server-side package address.
	 * @param string               $server_region Server-side package region.
	 * @param array<int,array>     $packages      WooCommerce shipping packages.
	 * @param object               $session       WooCommerce session.
	 * @return array<string,mixed>|null
	 *
	 * @since 2.1.0
	 */
	public function canonicalize_request(
		array $request,
		string $server_address,
		string $server_region,
		array $packages,
		object $session
	): ?array {
		$result = $this->service->canonicalize_request(
			$request,
			$server_address,
			$server_region,
			$packages,
			$session
		);

		return is_array( $result ) ? $result : null;
	}

	/**
	 * Clears the canonical and BlueX-compatible session selection.
	 *
	 * @param object      $session    WooCommerce session.
	 * @param string|null $attempt_id Explicit mutation correlation ID.
	 *
	 * @since 2.1.0
	 */
	public function clear_selection( object $session, ?string $attempt_id = null ): void {
		$this->service->clear_selection( $session, $attempt_id );
	}

	/**
	 * Creates an authoritative server-side tombstone.
	 *
	 * @param object $session WooCommerce session.
	 *
	 * @since 2.1.0
	 */
	public function invalidate_selection( object $session ): void {
		$this->service->invalidate_selection( $session );
	}

	/**
	 * Returns whether a newer mutation or active final checkout owns the state.
	 *
	 * @param \Throwable $throwable Business exception.
	 * @return bool
	 */
	public function is_mutation_conflict( \Throwable $throwable ): bool {
		if ( ! method_exists( $throwable, 'error_code' ) ) {
			return false;
		}

		return in_array(
			(string) $throwable->error_code(),
			array( 'pudo_stale_attempt', 'pudo_checkout_in_progress' ),
			true
		);
	}

	/**
	 * Reconciles PUDO authority before WooCommerce reads its package-rate cache.
	 *
	 * @param array<int,array> $packages Shipping packages.
	 * @param object           $session  WooCommerce session.
	 * @return array<int,array>
	 *
	 * @since 2.1.0
	 */
	public function prepare_shipping_packages( array $packages, object $session ): array {
		$result = $this->service->prepare_shipping_packages( $packages, $session );

		return is_array( $result ) ? $result : array();
	}

	/**
	 * Delegates PUDO rate eligibility to the Business domain service.
	 *
	 * @param array<string|int,mixed> $rates    Current package rates.
	 * @param array<int,array>        $packages Complete calculated packages.
	 * @param object                  $session  WooCommerce session.
	 * @return array<string|int,mixed>
	 *
	 * @since 2.1.0
	 */
	public function filter_package_rates( array $rates, array $packages, object $session ): array {
		$result = $this->service->filter_package_rates( $rates, $packages, $session );

		return is_array( $result ) ? $result : array();
	}

	/**
	 * Validates the selected rate, quote and canonical pickup point.
	 *
	 * @param array<int,array>  $packages       WooCommerce shipping packages.
	 * @param array<int,string> $chosen_methods Chosen shipping method IDs.
	 * @param object            $session        WooCommerce session.
	 * @return array<string,mixed>
	 *
	 * @since 2.1.0
	 */
	public function validate_checkout( array $packages, array $chosen_methods, object $session ): array {
		$result = $this->service->validate_checkout( $packages, $chosen_methods, $session );
		if (
			! is_array( $result )
			|| true !== ( $result['is_pudo'] ?? null )
			|| ! is_array( $result['selection'] ?? null )
			|| ! is_array( $result['checkout_claim'] ?? null )
		) {
			return array();
		}

		return array(
			'selection'      => $result['selection'],
			'checkout_claim' => $result['checkout_claim'],
		);
	}

	/**
	 * Releases the exact checkout claim after canonical order data was copied.
	 *
	 * @param array<string,mixed> $claim Checkout claim.
	 */
	public function release_checkout_claim( array $claim ): void {
		$this->service->release_checkout_claim( $claim );
	}
}
