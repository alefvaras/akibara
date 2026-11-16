<?php
/**
 * Public checkout data contract for first-party presentation adapters.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Contracts;

use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Exposes stable business data without leaking module implementations.
 *
 * @since 1.95.0
 * @api
 */
interface CheckoutDataProvider {

	/**
	 * Returns the renderer-agnostic cart and checkout presentation contract.
	 *
	 * @return array{
	 *   schema_version:int,
	 *   free_shipping:array{
	 *     state:string,
	 *     reason:string,
	 *     visible_on_cart:bool,
	 *     visible_on_checkout:bool,
	 *     show_qualified:bool,
	 *     title:string,
	 *     message:string,
	 *     threshold:float,
	 *     total:float,
	 *     remaining:float,
	 *     progress_percent:float,
	 *     almost_there:bool
	 *   },
	 *   trust_signals:array<int,array{id:string,label:string,icon:string}>
	 * }
	 *
	 * The structured contract contains no HTML and is safe for native Blocks,
	 * headless clients and future presentation adapters.
	 *
	 * @since 1.96.0
	 */
	public function structured_cart_data(): array;

	/**
	 * Returns canonical availability data for a product.
	 *
	 * @param WC_Product $product Product being presented.
	 * @return array{
	 *   stock_status:string,
	 *   stock_label:string
	 * }
	 *
	 * @since 1.95.0
	 */
	public function product_availability( WC_Product $product ): array;

	/**
	 * Validates a Chilean RUT.
	 *
	 * @param string $rut Raw RUT.
	 * @return bool
	 *
	 * @since 1.95.0
	 */
	public function is_valid_rut( string $rut ): bool;

	/**
	 * Formats a Chilean RUT.
	 *
	 * @param string $rut Raw RUT.
	 * @return string
	 *
	 * @since 1.95.0
	 */
	public function format_rut( string $rut ): string;

	/**
	 * Returns the first validation error for a non-empty Chilean phone.
	 *
	 * @param string $phone Raw phone.
	 * @return array{code:string,message:string}|null
	 *
	 * @since 1.95.0
	 */
	public function phone_validation_error( string $phone ): ?array;

	/**
	 * Returns the enabled Google Places configuration.
	 *
	 * @return array{enabled:bool,api_key:string,region_map:array<string,string>}
	 *
	 * @since 1.95.0
	 */
	public function address_autocomplete_config(): array;
}
