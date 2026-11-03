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
 */
final class PudoIntegration {

	public const WRITE_NAMESPACE = 'bluex_pudo';

	private const METHOD_ID = 'bluex-pudo';

	private const DELIVERY_METHOD_ID = 'bluex-ex';

	private const ORIGINAL_METHOD_META = '_bluex_original_method';

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
		add_action( 'woocommerce_store_api_cart_select_shipping_rate', array( $this, 'handle_shipping_rate_selected' ), 100, 3 );
		add_filter( 'woocommerce_cart_shipping_packages', array( $this, 'prepare_shipping_packages' ), PHP_INT_MAX );
		add_filter( 'woocommerce_package_rates', array( $this, 'filter_package_rates' ), 999, 2 );
		add_action( 'wp_enqueue_scripts', array( $this, 'dequeue_vendor_pudo_assets' ), 999 );
		add_action( 'woocommerce_blocks_enqueue_checkout_block_scripts_after', array( $this, 'enqueue_assets' ) );

		if ( did_action( 'woocommerce_blocks_loaded' ) ) {
			$this->register_store_api_update_callback();
			return;
		}

		add_action( 'woocommerce_blocks_loaded', array( $this, 'register_store_api_update_callback' ), 1000 );
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
	 * Validates and persists a pickup-point selection without mutating WC_Customer.
	 *
	 * @internal
	 *
	 * @param mixed $data Store API extension data.
	 */
	public function handle_store_api_update( $data ): void {
		$runtime = $this->runtime();
		if ( null === $runtime ) {
			$this->throw_route_error( 'akibara_pudo_unavailable', 'No pudimos validar el punto de retiro. Elige otro método de envío o inténtalo nuevamente.' );
		}

		$session  = $runtime['session'];
		$packages = $runtime['packages'];
		$request  = $this->normalize_client_request( $data );

		if ( '' === $request['agency_id'] ) {
			try {
				$this->service->clear_selection( $session, $request['attempt_id'] );
			} catch ( \Throwable $throwable ) {
				if ( $this->service->is_mutation_conflict( $throwable ) ) {
					$this->throw_route_error(
						'akibara_pudo_mutation_conflict',
						'El punto de retiro está siendo confirmado en otra solicitud. Espera el resultado antes de cambiarlo.',
						409
					);
				}
				// Un conflicto de mutación es esperable y ya se le explica al cliente.
				// Lo que sigue NO lo es: se registra para que un incidente del
				// proveedor se pueda leer en vez de deducirlo de las ventas.
				PudoLog::failure( 'clear_selection', $throwable );
				$this->throw_route_error( 'akibara_pudo_validation_failed', 'No pudimos actualizar el punto de retiro. Inténtalo nuevamente.' );
			}
			$this->invalidate_shipping_cache( $session, $packages );
			return;
		}

		$this->assert_single_package( $packages, $session );
		$this->assert_pudo_is_selected( $session );

		$destination = $this->package_destination( $packages[0] );
		if ( null === $destination ) {
			$this->best_effort( 'invalid_destination.invalidate_selection', array( $this->service, 'invalidate_selection' ), $session );
			$this->throw_route_error( 'akibara_pudo_invalid_destination', 'Completa una dirección chilena válida antes de seleccionar el punto de retiro.' );
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
				$this->throw_route_error(
					'akibara_pudo_stale_attempt',
					'Otra selección de punto de retiro reemplazó esta solicitud. Revisa el punto vigente.',
					409
				);
			}
			// Un conflicto de mutación es esperable y ya se le explica al cliente.
			// Lo que sigue NO lo es: se registra para que un incidente del
			// proveedor se pueda leer en vez de deducirlo de las ventas.
			PudoLog::failure( 'select_agency', $throwable );
			$this->throw_route_error( 'akibara_pudo_validation_failed', 'No pudimos comprobar ese punto con Blue Express. Inténtalo nuevamente o elige otro método de envío.' );
		}

		$canonical = is_array( $selection ) ? $this->canonical_selection( $selection ) : null;
		if ( null === $canonical || $canonical['agency_id'] !== $request['agency_id'] ) {
			$this->best_effort( 'mismatched_selection.invalidate_selection', array( $this->service, 'invalidate_selection' ), $session );
			$this->throw_route_error( 'akibara_pudo_mismatched_selection', 'Blue Express no confirmó el punto seleccionado. Selecciónalo nuevamente.' );
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
	 * @param mixed $request    Store API request.
	 */
	public function handle_shipping_rate_selected( $package_id, $rate_id, $request ): void {
		unset( $package_id, $request );
		if ( ! is_string( $rate_id ) || $this->is_pudo_method( $rate_id ) ) {
			return;
		}

		$runtime = $this->runtime();
		if ( null !== $runtime ) {
			try {
				$this->service->invalidate_selection( $runtime['session'] );
			} catch ( \Throwable $throwable ) {
				if ( $this->service->is_mutation_conflict( $throwable ) ) {
					$this->throw_route_error(
						'akibara_pudo_mutation_conflict',
						'El pedido ya está confirmando el punto de retiro. Espera el resultado antes de cambiar el envío.',
						409
					);
				}
				// Un conflicto de mutación es esperable y ya se le explica al cliente.
				// Lo que sigue NO lo es: se registra para que un incidente del
				// proveedor se pueda leer en vez de deducirlo de las ventas.
				PudoLog::failure( 'invalidate_selection', $throwable );
				$this->throw_route_error( 'akibara_pudo_validation_failed', 'No pudimos actualizar el método de envío. Inténtalo nuevamente.' );
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
				$session     = is_object( $woocommerce ) ? ( $woocommerce->session ?? null ) : null;
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
			return $this->without_pudo_rates( $rates );
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
			return $this->without_pudo_rates( $rates );
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

		if ( ! $this->order_uses_pudo( $order ) ) {
			$this->set_request_extensions( $request, $extensions );
			return;
		}

		$runtime = $this->runtime();
		if ( null === $runtime ) {
			$this->throw_route_error( 'akibara_pudo_unavailable', 'No pudimos validar el punto de retiro. Elige otro método de envío.' );
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
			$this->throw_route_error( 'akibara_pudo_checkout_invalid', 'El punto de retiro o su tarifa ya no están disponibles. Selecciona el punto nuevamente.' );
		}

		$selection = is_array( $validation['selection'] ?? null )
			? $validation['selection']
			: array();
		$claim = is_array( $validation['checkout_claim'] ?? null )
			? $validation['checkout_claim']
			: array();
		try {
			$canonical = $this->canonical_selection( $selection );
			if ( null === $canonical || array() === $claim ) {
				$this->best_effort( 'checkout_invalid.clear_selection', array( $this->service, 'clear_selection' ), $session );
				$this->throw_route_error( 'akibara_pudo_checkout_invalid', 'El punto de retiro o su tarifa ya no están disponibles. Selecciona el punto nuevamente.' );
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
		if (
			! is_object( $woocommerce )
			|| ! is_object( $woocommerce->cart ?? null )
			|| ! is_object( $woocommerce->session ?? null )
			|| ! method_exists( $woocommerce->cart, 'get_shipping_packages' )
		) {
			return null;
		}

		$packages = $woocommerce->cart->get_shipping_packages();
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
			'cart'     => $woocommerce->cart,
			'session'  => $woocommerce->session,
			'packages' => array_values( $packages ),
		);
	}

	/**
	 * Accepts only the agency ID and optional minimal location hints.
	 *
	 * @param mixed $data Raw extension data.
	 * @return array{agency_id:string,address:string,region_code:string,attempt_id:string}
	 */
	private function normalize_client_request( $data ): array {
		if ( ! is_array( $data ) ) {
			$this->throw_route_error( 'akibara_pudo_invalid_payload', 'La selección del punto de retiro no tiene un formato válido.' );
		}

		$allowed = array( 'agency_id', 'address', 'region_code', 'attempt_id' );
		if ( array_diff( array_keys( $data ), $allowed ) ) {
			$this->throw_route_error( 'akibara_pudo_untrusted_payload', 'La selección contiene datos no permitidos. Selecciona el punto nuevamente.' );
		}
		foreach ( $allowed as $key ) {
			if ( array_key_exists( $key, $data ) && ! is_string( $data[ $key ] ) ) {
				$this->throw_route_error( 'akibara_pudo_invalid_payload', 'La selección del punto de retiro no tiene un formato válido.' );
			}
		}

		$agency_id = trim( (string) ( $data['agency_id'] ?? '' ) );
		$address   = trim( (string) ( $data['address'] ?? '' ) );
		$region    = strtoupper( trim( (string) ( $data['region_code'] ?? '' ) ) );
		$attempt_id = strtolower( trim( (string) ( $data['attempt_id'] ?? '' ) ) );

		if (
			strlen( $agency_id ) > 64
			|| ( '' !== $agency_id && 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9._:-]*$/', $agency_id ) )
			|| strlen( $address ) > 200
			|| ( '' !== $region && 1 !== preg_match( '/^CL-[A-Z]{2}$/', $region ) )
			|| 1 !== preg_match( '/\A[a-f0-9]{32}\z/', $attempt_id )
		) {
			$this->throw_route_error( 'akibara_pudo_invalid_payload', 'La selección del punto de retiro no tiene un formato válido.' );
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
	private function package_destination( array $package ): ?array {
		$destination = $package['destination'] ?? null;
		if ( ! is_array( $destination ) || 'CL' !== strtoupper( (string) ( $destination['country'] ?? '' ) ) ) {
			return null;
		}

		$region = strtoupper( trim( (string) ( $destination['state'] ?? '' ) ) );
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
	private function canonical_selection( array $selection ): ?array {
		if (
			! is_string( $selection['agency_id'] ?? null )
			|| ! is_string( $selection['agency_name'] ?? null )
			|| ! is_string( $selection['agency_address'] ?? null )
			|| ! is_array( $selection['shipping_address'] ?? null )
		) {
			return null;
		}

		$agency_id       = trim( $selection['agency_id'] );
		$agency_name     = trim( $selection['agency_name'] );
		$agency_address  = trim( $selection['agency_address'] );
		$shipping_address = $this->canonical_shipping_address( $selection['shipping_address'] );

		// `agency_address` no vacío requerido para validar la selección y persistir
		// la dirección canónica en los metadatos del pedido.
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
	private function canonical_shipping_address( array $address ): ?array {
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
		$this->throw_route_error( 'akibara_pudo_multiple_packages', 'El retiro en Punto Blue Express no admite pedidos divididos en más de un envío.' );
	}

	/**
	 * Requires PUDO to be the sole selected shipping method.
	 *
	 * @param object $session WooCommerce session.
	 */
	private function assert_pudo_is_selected( object $session ): void {
		$chosen = $this->chosen_shipping_methods( $session );
		if ( 1 === count( $chosen ) && $this->is_pudo_method( $chosen[0] ) ) {
			return;
		}

		$this->best_effort( 'method_not_selected.invalidate_selection', array( $this->service, 'invalidate_selection' ), $session );
		$this->throw_route_error( 'akibara_pudo_method_not_selected', 'Selecciona Retiro en Punto Blue Express antes de elegir una agencia.' );
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
	 * Returns whether an order currently contains a PUDO shipping line.
	 *
	 * @param object $order Checkout order.
	 * @return bool
	 */
	private function order_uses_pudo( object $order ): bool {
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
			if ( $this->is_pudo_method( $method_id ) ) {
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
	 * Persists the canonical selection using BlueX's public order-meta contract.
	 *
	 * BlueX may normalize the shipping line from `bluex-pudo` to `bluex-ex`
	 * before this Store API hook runs. Its later persistence hook only recognizes
	 * the pre-normalized method, so the first-party adapter must preserve the
	 * already server-validated agency data on either compatible sequence.
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
			$this->throw_route_error( 'akibara_pudo_order_unavailable', 'No pudimos guardar el punto de retiro. Selecciónalo nuevamente.' );
		}
		foreach ( $setters as $setter ) {
			if ( ! method_exists( $order, $setter ) ) {
				$this->throw_route_error( 'akibara_pudo_order_unavailable', 'No pudimos guardar la dirección del punto de retiro. Selecciónalo nuevamente.' );
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
	 * Returns whether a shipping method identifier belongs to PUDO.
	 *
	 * @param string $method_id Shipping method or rate ID.
	 * @return bool
	 */
	private function is_pudo_method( string $method_id ): bool {
		return self::METHOD_ID === $method_id || 0 === strpos( $method_id, self::METHOD_ID . ':' );
	}

	/**
	 * Removes only BlueX PUDO rates when the canonical service cannot validate.
	 *
	 * @param array<string|int,mixed> $rates Package rates.
	 * @return array<string|int,mixed>
	 */
	private function without_pudo_rates( array $rates ): array {
		foreach ( $rates as $key => $rate ) {
			$method_id = '';
			if ( is_object( $rate ) && method_exists( $rate, 'get_method_id' ) ) {
				$method_id = (string) $rate->get_method_id();
			} elseif ( is_array( $rate ) ) {
				$method_id = (string) ( $rate['method_id'] ?? '' );
			}

			if ( $this->is_pudo_method( $method_id ) ) {
				unset( $rates[ $key ] );
			}
		}

		return $rates;
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

	/**
	 * Throws a Store API route error without leaking service details.
	 *
	 * @param string $code    Stable error code.
	 * @param string $message Shopper-facing recovery message.
	 * @param int    $status  HTTP status.
	 * @return never
	 */
	private function throw_route_error( string $code, string $message, int $status = 400 ): void {
		$route_exception = '\\Automattic\\WooCommerce\\StoreApi\\Exceptions\\RouteException';
		if ( class_exists( $route_exception ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Store API: el mensaje es un literal de esta clase y WooCommerce lo serializa a JSON; escaparlo metería entidades HTML en lo que lee el cliente.
			throw new $route_exception( $code, $message, $status );
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- mismo motivo: literal propio, nunca entrada del cliente.
		throw new \RuntimeException( $message, $status );
	}
}
