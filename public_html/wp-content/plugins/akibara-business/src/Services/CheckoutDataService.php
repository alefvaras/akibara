<?php
/**
 * Stable checkout data service for first-party adapters.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Services;

use Akibara\Business\Contracts\CheckoutDataProvider;
use Akibara\Business\Modules\AddressAutocomplete\AddressAutocomplete;
use Akibara\Business\Modules\FreeShippingProgress\FreeShippingProgress;
use Akibara\Business\Modules\Stock\Availability;
use Akibara\Business\Modules\Stock\StockStatus;
use Akibara\Business\Utilities\ChileanPhone;
use Akibara\Business\Utilities\RutValidator;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Composes domain modules behind the public checkout contract.
 *
 * @since 1.95.0
 * @api
 * @psalm-suppress ImplementedReturnTypeMismatch
 * @psalm-suppress LessSpecificImplementedReturnType
 * @psalm-suppress MissingOverrideAttribute
 * @psalm-suppress MissingOverrideAttribute
 */
final class CheckoutDataService implements CheckoutDataProvider {

	private FreeShippingProgress $free_shipping_progress;

	private AddressAutocomplete $address_autocomplete;

	/**
	 * Initializes the public service with the already-booted module instances.
	 *
	 * @param FreeShippingProgress $free_shipping_progress Free-shipping domain module.
	 * @param AddressAutocomplete  $address_autocomplete   Address configuration module.
	 *
	 * @since 1.95.0
	 */
	public function __construct(
		FreeShippingProgress $free_shipping_progress,
		AddressAutocomplete $address_autocomplete
	) {
		$this->free_shipping_progress = $free_shipping_progress;
		$this->address_autocomplete   = $address_autocomplete;
	}

	/**
	 * Returns the renderer-agnostic cart and checkout presentation contract.
	 *
	 * @return array<string,mixed>
	 *
	 * @since 1.96.0
	 * @psalm-suppress MissingOverrideAttribute
	 */
	#[\Override]
	public function structured_cart_data(): array {
		return array(
			'schema_version' => 2,
			'free_shipping'  => $this->free_shipping_progress->structured_state(),
			'trust_signals'  => CheckoutTrustSignals::all(),
		);
	}

	/**
	 * Returns canonical availability data for a product.
	 *
	 * @param WC_Product $product Product being presented.
	 * @return array<string,bool|int|string|null>
	 *
	 * @since 1.95.0
	 * @psalm-suppress MissingOverrideAttribute
	 */
	#[\Override]
	public function product_availability( WC_Product $product ): array {
		$status = StockStatus::of( $product );

		return array(
			'stock_status' => $status,
			'stock_label'  => Availability::label_of( $product ),
		);
	}

	/**
	 * Validates a Chilean RUT.
	 *
	 * @param string $rut Raw RUT.
	 * @return bool
	 *
	 * @since 1.95.0
	 * @psalm-suppress MissingOverrideAttribute
	 */
	#[\Override]
	public function is_valid_rut( string $rut ): bool {
		return RutValidator::is_valid( $rut );
	}

	/**
	 * Formats a Chilean RUT.
	 *
	 * @param string $rut Raw RUT.
	 * @return string
	 *
	 * @since 1.95.0
	 * @psalm-suppress MissingOverrideAttribute
	 */
	#[\Override]
	public function format_rut( string $rut ): string {
		return RutValidator::format( $rut );
	}

	/**
	 * Returns the first validation error for a non-empty Chilean phone.
	 *
	 * @param string $phone Raw phone.
	 * @return array{code:string,message:string}|null
	 *
	 * @since 1.95.0
	 * @psalm-suppress MissingOverrideAttribute
	 */
	#[\Override]
	public function phone_validation_error( string $phone ): ?array {
		return ChileanPhone::validation_error( $phone, false );
	}

	/**
	 * Returns the enabled Google Places configuration.
	 *
	 * @return array{enabled:bool,api_key:string,region_map:array<string,string>}
	 *
	 * @since 1.95.0
	 * @psalm-suppress MissingOverrideAttribute
	 */
	#[\Override]
	public function address_autocomplete_config(): array {
		$enabled = $this->address_autocomplete->is_ready();

		return array(
			'enabled'    => $enabled,
			'api_key'    => $enabled ? $this->address_autocomplete->get_api_key() : '',
			'region_map' => $enabled ? $this->address_autocomplete->region_map() : array(),
		);
	}
}
