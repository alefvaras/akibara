<?php
/**
 * Native WooCommerce Blocks adapter for BlueX pickup points.
 *
 * @package Akibara\CartCheckoutBlocks
 */

declare( strict_types = 1 );

namespace Akibara\CartCheckoutBlocks;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the PUDO Store API transport and Blocks presentation layer.
 *
 * @since 2.1.0
 * @api
 */
final class PudoIntegration {

	public const WRITE_NAMESPACE = 'bluex_pudo';

	private const VENDOR_SCRIPT = 'bluex-blocks-pudo-integration';

	private const VENDOR_STYLE = 'bluex-blocks-pudo-css';

	private const CACHE_GENERATION_KEY = '_akb_pudo_generation';

	private PudoServiceAdapter $service;

	private BlocksPage $blocks_page;

	private bool $callback_registered = false;

	private bool $assets_enqueued = false;

	/**
	 * Initializes the PUDO adapter.
	 *
	 * @param object     $service     Akibara Business PUDO service.
	 * @param BlocksPage $blocks_page Blocks page resolver.
	 *
	 * @since 2.1.0
	 */
	public function __construct( object $service, BlocksPage $blocks_page ) {
		$this->service     = new PudoServiceAdapter( $service );
		$this->blocks_page = $blocks_page;
	}

	/**
	 * Registers Store API, checkout and asset hooks.
	 *
	 * @since 2.1.0
	 */
	public function register(): void {
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'rewrite_checkout_request' ), 1, 2 );
		add_action( 'woocommerce_store_api_cart_select_shipping_rate', array( $this, 'handle_shipping_rate_selected' ), 100, 2 );
		add_filter( 'woocommerce_cart_shipping_packages', array( $this, 'prepare_shipping_packages' ), PHP_INT_MAX );
		add_filter( 'woocommerce_package_rates', array( $this, 'filter_package_rates' ), 999, 2 );
		add_action( 'wp_enqueue_scripts', array( $this, 'dequeue_vendor_pudo_assets' ), 999, 0 );
		add_action( 'woocommerce_blocks_enqueue_checkout_block_scripts_after', array( $this, 'enqueue_assets' ), 10, 0 );

		if ( did_action( 'woocommerce_blocks_loaded' ) ) {
			$this->register_store_api_update_callback();
			return;
		}

		add_action( 'woocommerce_blocks_loaded', array( $this, 'register_store_api_update_callback' ), 1000, 0 );
	}

	/**
	 * Replaces BlueX's permissive callback under the same Store API namespace.
	 *
	 * @internal
	 */
	public function register_store_api_update_callback(): void {
		if ( $this->callback_registered || ! function_exists( 'woocommerce_store_api_register_update_callback' ) ) {
			return;
		}

		woocommerce_store_api_register_update_callback(
			array(
				'namespace' => self::WRITE_NAMESPACE,
				'callback'  => array( $this, 'handle_store_api_update' ),
			)
		);

		$this->callback_registered = true;
	}

	/**
	 * Validates and persists a pickup-point selection without mutating checkout account fields.
	 *
	 * @internal
	 *
	 * @param mixed $data Store API extension data.
	 */
	public function handle_store_api_update( $data ): void {
		$runtime = $this->runtime();
		if ( null === $runtime ) {
			PudoPayloadValidator::throw_route_error( 'akibara_pudo_unavailable', 'No pudimos validar el punto de retiro. Elige otro método de envío o inténtalo nuevamente.' );
		}

		$session  = $runtime['session'];
		$packages = $runtime['packages'];
		$request  = PudoPayloadValidator::normalize_client_request( $data );

		if ( '' === $request['agency_id'] ) {
			$this->handle_clear_selection( $session, $packages, $request['attempt_id'] );
			return;
		}

		$this->assert_single_package( $packages, $session );
		$this->assert_pudo_is_selected( $session );

		$destination = PudoPayloadValidator::package_destination( $packages[0] );
		if ( null === $destination ) {
			$this->best_effort( 'invalid_destination.invalidate_selection', array( $this->service, 'invalidate_selection' ), $session );
			PudoPayloadValidator::throw_route_error( 'akibara_pudo_invalid_destination', 'Completa una dirección chilena válida antes de seleccionar el punto de retiro.' );
		}

		try {
			$selection = $this->service->canonicalize_request(
				$request,
				$destination['address'],
				$destination['region'],
				$packages,
				$session
			);
		} catch ( \Throwable $throwable ) {
			if ( $this->service->is_mutation_conflict( $throwable ) ) {
				PudoPayloadValidator::throw_route_error(
					'akibara_pudo_stale_attempt',
					'Otra selección de punto de retiro reemplazó esta solicitud. Revisa el punto vigente.',
					409
				);
			}
			// Un conflicto de mutación es esperable y ya se le explica al cliente.
			// Lo que sigue NO lo es: se registra para que un incidente del
			// proveedor se pueda leer en vez de deducirlo de las ventas.
			PudoLog::failure( 'select_agency', $throwable );
			PudoPayloadValidator::throw_route_error( 'akibara_pudo_validation_failed', 'No pudimos comprobar ese punto con Blue Express. Inténtalo nuevamente o elige otro método de envío.' );
		}

		$canonical = is_array( $selection ) ? PudoPayloadValidator::canonical_selection( $selection ) : null;
		if ( null === $canonical || $canonical['agency_id'] !== $request['agency_id'] ) {
			$this->best_effort( 'mismatched_selection.invalidate_selection', array( $this->service, 'invalidate_selection' ), $session );
			PudoPayloadValidator::throw_route_error( 'akibara_pudo_mismatched_selection', 'Blue Express no confirmó el punto seleccionado. Selecciónalo nuevamente.' );
		}

		$this->invalidate_shipping_cache( $session, $packages );
	}

	/**
	 * Clears the selected pickup point handling mutation conflicts cleanly.
	 *
	 * @param object           $session    WooCommerce session.
	 * @param array<int,array> $packages   Shipping packages.
	 * @param string           $attempt_id Attempt identifier.
	 */
	private function handle_clear_selection( object $session, array $packages, string $attempt_id ): void {
		try {
			$this->service->clear_selection( $session, $attempt_id );
		} catch ( \Throwable $throwable ) {
			if ( $this->service->is_mutation_conflict( $throwable ) ) {
				PudoPayloadValidator::throw_route_error(
					'akibara_pudo_mutation_conflict',
					'El punto de retiro está siendo confirmado en otra solicitud. Espera el resultado antes de cambiarlo.',
					409
				);
			}
			PudoLog::failure( 'clear_selection', $throwable );
			PudoPayloadValidator::throw_route_error( 'akibara_pudo_validation_failed', 'No pudimos actualizar el punto de retiro. Inténtalo nuevamente.' );
		}

		$this->invalidate_shipping_cache( $session, $packages );
	}

	/**
	 * Tombstones PUDO before totals when the official Store API selects another method.
	 *
	 * @internal
	 *
	 * @param mixed $package_id Package ID.
	 * @param mixed $rate_id    Selected rate ID.
	 */
	public function handle_shipping_rate_selected( $package_id, $rate_id ): void {
		unset( $package_id );
		if ( ! is_string( $rate_id ) || PudoPayloadValidator::is_pudo_method( $rate_id ) ) {
			return;
		}

		$runtime = $this->runtime();
		if ( null !== $runtime ) {
			try {
				$this->service->invalidate_selection( $runtime['session'] );
			} catch ( \Throwable $throwable ) {
				if ( $this->service->is_mutation_conflict( $throwable ) ) {
					PudoPayloadValidator::throw_route_error(
						'akibara_pudo_mutation_conflict',
						'El pedido ya está confirmando el punto de retiro. Espera el resultado antes de cambiar el envío.',
						409
					);
				}
				// Un conflicto de mutación es esperable y ya se le explica al cliente.
				// Lo que sigue NO lo es: se registra para que un incidente del
				// proveedor se pueda leer en vez de deducirlo de las ventas.
				PudoLog::failure( 'invalidate_selection', $throwable );
				PudoPayloadValidator::throw_route_error( 'akibara_pudo_validation_failed', 'No pudimos actualizar el método de envío. Inténtalo nuevamente.' );
			}
		}
	}

	/**
	 * Reconciles PUDO authority before WooCommerce can reuse package-rate cache.
	 *
	 * @internal
	 *
	 * @param array<int,array> $packages Shipping packages before cache hashing.
	 * @return array<int,array>
	 */
	public function prepare_shipping_packages( array $packages ): array {
		try {
			if ( $this->service->is_available() && function_exists( 'WC' ) ) {
				$woocommerce = WC();
				$session     = $woocommerce ? $woocommerce->session : null;
				if ( is_object( $session ) ) {
					return $this->service->prepare_shipping_packages( $packages, $session );
				}
			}
		} catch ( \Throwable $throwable ) {
			PudoLog::failure( 'prepare_shipping_packages', $throwable );
		}

		$generation = hash( 'sha256', 'akibara-pudo-unavailable-v1' );
		foreach ( $packages as $key => $package ) {
			if ( is_array( $package ) ) {
				$packages[ $key ][ self::CACHE_GENERATION_KEY ] = $generation;
			}
		}

		return $packages;
	}

	/**
	 * Removes unsafe PUDO rates through the canonical Business contract.
	 *
	 * The initial PUDO wrapper remains available until the shopper chooses an
	 * agency. Once a canonical selection exists, Business verifies the quote,
	 * agency and package fingerprint before allowing the rate.
	 *
	 * @internal
	 *
	 * @param array<string|int,mixed> $rates   Current package rates.
	 * @param array<string,mixed>     $package Current package.
	 * @return array<string|int,mixed>
	 */
	public function filter_package_rates( array $rates, array $package ): array {
		unset( $package );

		$runtime = $this->runtime();
		if ( null === $runtime ) {
			return PudoPayloadValidator::without_pudo_rates( $rates );
		}

		try {
			return $this->service->filter_package_rates(
				$rates,
				$runtime['packages'],
				$runtime['session']
			);
		} catch ( \Throwable $throwable ) {
			PudoLog::failure( 'filter_package_rates', $throwable );
			$this->best_effort( 'filter_package_rates.clear_selection', array( $this->service, 'clear_selection' ), $runtime['session'] );
			return PudoPayloadValidator::without_pudo_rates( $rates );
		}
	}

	/**
	 * Rewrites request extension data from the canonical session before BlueX.
	 *
	 * @internal
	 *
	 * @param object $order   Checkout order.
	 * @param object $request Store API checkout request.
	 */
	public function rewrite_checkout_request( object $order, object $request ): void {
		$extensions = method_exists( $request, 'get_param' ) ? $request->get_param( 'extensions' ) : array();
		$extensions = is_array( $extensions ) ? $extensions : array();
		unset( $extensions[ self::WRITE_NAMESPACE ] );

		if ( ! PudoPayloadValidator::order_uses_pudo( $order ) ) {
			$this->set_request_extensions( $request, $extensions );
			return;
		}

		$runtime = $this->runtime();
		if ( null === $runtime ) {
			PudoPayloadValidator::throw_route_error( 'akibara_pudo_unavailable', 'No pudimos validar el punto de retiro. Elige otro método de envío.' );
		}

		$packages       = $runtime['packages'];
		$session        = $runtime['session'];
		$chosen_methods = $this->chosen_shipping_methods( $session );

		$this->assert_single_package( $packages, $session );

		try {
			$validation = $this->service->validate_checkout( $packages, $chosen_methods, $session );
		} catch ( \Throwable $throwable ) {
			PudoLog::failure( 'validate_checkout', $throwable );
			$this->best_effort( 'validate_checkout.clear_selection', array( $this->service, 'clear_selection' ), $session );
			PudoPayloadValidator::throw_route_error( 'akibara_pudo_checkout_invalid', 'El punto de retiro o su tarifa ya no están disponibles. Selecciona el punto nuevamente.' );
		}

		$selection = is_array( $validation['selection'] ?? null ) ? $validation['selection'] : array();
		$claim     = is_array( $validation['checkout_claim'] ?? null ) ? $validation['checkout_claim'] : array();
		try {
			$canonical = PudoPayloadValidator::canonical_selection( $selection );
			if ( null === $canonical || array() === $claim ) {
				$this->best_effort( 'checkout_invalid.clear_selection', array( $this->service, 'clear_selection' ), $session );
				PudoPayloadValidator::throw_route_error( 'akibara_pudo_checkout_invalid', 'El punto de retiro o su tarifa ya no están disponibles. Selecciona el punto nuevamente.' );
			}

			$extensions[ self::WRITE_NAMESPACE ] = array(
				'agency_id'      => $canonical['agency_id'],
				'agency_name'    => $canonical['agency_name'],
				'agency_address' => $canonical['agency_address'],
			);
			$this->persist_canonical_order_selection( $order, $canonical );
			$this->set_request_extensions( $request, $extensions );
		} finally {
			if ( array() !== $claim ) {
				$this->best_effort( 'rewrite_checkout_request.release_checkout_claim', array( $this->service, 'release_checkout_claim' ), $claim );
			}
		}
	}

	/**
	 * Dequeues only BlueX's PUDO presentation assets on the Blocks checkout.
	 *
	 * @internal
	 */
	public function dequeue_vendor_pudo_assets(): void {
		if ( ! $this->blocks_page->is_checkout() ) {
			return;
		}

		wp_dequeue_script( self::VENDOR_SCRIPT );
		wp_dequeue_style( self::VENDOR_STYLE );
	}

	/**
	 * Enqueues the first-party native PUDO SlotFill.
	 *
	 * @internal
	 */
	public function enqueue_assets(): void {
		if ( $this->assets_enqueued || ! $this->blocks_page->is_checkout() ) {
			return;
		}

		$this->dequeue_vendor_pudo_assets();

		$script_path = AKIBARA_CART_CHECKOUT_BLOCKS_DIR . 'assets/js/pudo-blocks.js';
		wp_enqueue_script(
			'akibara-blocks-pudo',
			AKIBARA_CART_CHECKOUT_BLOCKS_URL . 'assets/js/pudo-blocks.js',
			array( 'wc-blocks-checkout', 'wc-blocks-data-store', 'wp-data', 'wp-element', 'wp-plugins' ),
			AKIBARA_CART_CHECKOUT_BLOCKS_VERSION . ( file_exists( $script_path ) ? '.' . filemtime( $script_path ) : '' ),
			true
		);

		$style_path = AKIBARA_CART_CHECKOUT_BLOCKS_DIR . 'assets/css/pudo-blocks.css';
		wp_enqueue_style(
			'akibara-blocks-pudo',
			AKIBARA_CART_CHECKOUT_BLOCKS_URL . 'assets/css/pudo-blocks.css',
			array(),
			AKIBARA_CART_CHECKOUT_BLOCKS_VERSION . ( file_exists( $style_path ) ? '.' . filemtime( $style_path ) : '' )
		);

		$this->assets_enqueued = true;
	}

	/**
	 * Returns the available WooCommerce cart/session runtime.
	 *
	 * @return array{cart:object,session:object,packages:array<int,array>}|null
	 */
	private function runtime(): ?array {
		if ( ! $this->service->is_available() || ! function_exists( 'WC' ) ) {
			return null;
		}

		$woocommerce = WC();
		if ( ! $woocommerce ) {
			return null;
		}
		$cart    = $woocommerce->cart;
		$session = $woocommerce->session;
		if ( ! $cart || ! $session || ! method_exists( $cart, 'get_shipping_packages' ) ) {
			return null;
		}

		$packages = $cart->get_shipping_packages();
		if ( ! is_array( $packages ) ) {
			return null;
		}

		if ( method_exists( $woocommerce, 'shipping' ) ) {
			$shipping = $woocommerce->shipping();
			if ( is_object( $shipping ) && method_exists( $shipping, 'get_packages' ) ) {
				$calculated_packages = $shipping->get_packages();
				if (
					is_array( $calculated_packages )
					&& array() !== $calculated_packages
					&& count( $calculated_packages ) === count( $packages )
				) {
					$packages = $calculated_packages;
				}
			}
		}

		return array(
			'cart'     => $cart,
			'session'  => $session,
			'packages' => array_values( $packages ),
		);
	}

	/**
	 * Rejects PUDO whenever WooCommerce splits the cart into multiple packages.
	 *
	 * @param array<int,array> $packages Shipping packages.
	 * @param object           $session  WooCommerce session.
	 */
	private function assert_single_package( array $packages, object $session ): void {
		if ( 1 === count( $packages ) ) {
			return;
		}

		$this->best_effort( 'multiple_packages.invalidate_selection', array( $this->service, 'invalidate_selection' ), $session );
		PudoPayloadValidator::throw_route_error( 'akibara_pudo_multiple_packages', 'El retiro en Punto Blue Express no admite pedidos divididos en más de un envío.' );
	}

	/**
	 * Requires PUDO to be the sole selected shipping method.
	 *
	 * @param object $session WooCommerce session.
	 */
	private function assert_pudo_is_selected( object $session ): void {
		$chosen = $this->chosen_shipping_methods( $session );
		if ( 1 === count( $chosen ) && PudoPayloadValidator::is_pudo_method( $chosen[0] ) ) {
			return;
		}

		$this->best_effort( 'method_not_selected.invalidate_selection', array( $this->service, 'invalidate_selection' ), $session );
		PudoPayloadValidator::throw_route_error( 'akibara_pudo_method_not_selected', 'Selecciona Retiro en Punto Blue Express antes de elegir una agencia.' );
	}

	/**
	 * Returns the normalized chosen shipping method IDs.
	 *
	 * @param object $session WooCommerce session.
	 * @return array<int,string>
	 */
	private function chosen_shipping_methods( object $session ): array {
		if ( ! method_exists( $session, 'get' ) ) {
			return array();
		}

		$chosen = $session->get( 'chosen_shipping_methods', array() );
		if ( ! is_array( $chosen ) ) {
			return array();
		}

		return array_values(
			array_filter(
				array_map( 'strval', $chosen )
			)
		);
	}

	/**
	 * Persists the canonical selection using BlueX's public order-meta contract.
	 *
	 * @param object $order Checkout order.
	 * @param array{
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
	 * } $canonical Canonical selection.
	 */
	private function persist_canonical_order_selection( object $order, array $canonical ): void {
		$setters = array(
			'address_1' => 'set_shipping_address_1',
			'address_2' => 'set_shipping_address_2',
			'city'      => 'set_shipping_city',
			'state'     => 'set_shipping_state',
			'country'   => 'set_shipping_country',
			'postcode'  => 'set_shipping_postcode',
		);
		if ( ! method_exists( $order, 'update_meta_data' ) ) {
			PudoPayloadValidator::throw_route_error( 'akibara_pudo_order_unavailable', 'No pudimos guardar el punto de retiro. Selecciónalo nuevamente.' );
		}
		foreach ( $setters as $setter ) {
			if ( ! method_exists( $order, $setter ) ) {
				PudoPayloadValidator::throw_route_error( 'akibara_pudo_order_unavailable', 'No pudimos guardar la dirección del punto de retiro. Selecciónalo nuevamente.' );
			}
		}

		foreach ( $setters as $field => $setter ) {
			$order->{$setter}( $canonical['shipping_address'][ $field ] );
		}

		$order->update_meta_data( 'agencyId', $canonical['agency_id'] );
		$order->update_meta_data( 'isPudoSelected', 'pudoShipping' );

		if ( '' !== $canonical['agency_name'] ) {
			$order->update_meta_data( 'agencyName', $canonical['agency_name'] );
		}
		if ( '' !== $canonical['agency_address'] ) {
			$order->update_meta_data( 'agencyAddress', $canonical['agency_address'] );
		}
	}

	/**
	 * Limpieza de mejor esfuerzo: captura excepciones para evitar interrupciones de flujo en checkout.
	 *
	 * Aislamiento de fallos durante la liberación de reservas o claims para preservar
	 * la presentación de errores limpios al usuario sin propagar excepciones 500 no controladas.
	 *
	 * @param string   $context Operación, para el registro.
	 * @param callable $cleanup Limpieza.
	 * @param mixed    ...$args Argumentos de la limpieza.
	 */
	private function best_effort( string $context, callable $cleanup, ...$args ): void {
		try {
			$cleanup( ...$args );
		} catch ( \Throwable $throwable ) {
			PudoLog::failure( $context, $throwable );
		}
	}

	/**
	 * Invalidates rate caches after a canonical PUDO state transition.
	 *
	 * @param object           $session  WooCommerce session.
	 * @param array<int,array> $packages Shipping packages.
	 */
	private function invalidate_shipping_cache( object $session, array $packages ): void {
		if ( ! method_exists( $session, '__unset' ) ) {
			return;
		}

		foreach ( array_keys( $packages ) as $package_key ) {
			$session->__unset( 'shipping_for_package_' . $package_key );
		}
	}

	/**
	 * Replaces request extension data before the vendor persistence hook.
	 *
	 * @param object              $request    Store API request.
	 * @param array<string,mixed> $extensions Canonical extension map.
	 */
	private function set_request_extensions( object $request, array $extensions ): void {
		if ( method_exists( $request, 'set_param' ) ) {
			$request->set_param( 'extensions', $extensions );
		}
	}
}
