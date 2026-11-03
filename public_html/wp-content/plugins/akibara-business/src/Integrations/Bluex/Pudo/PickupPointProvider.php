<?php
/**
 * Validated adapter over the public API surface of Bluex for WooCommerce.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Integrations\Bluex\Pudo;

use Closure;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves pickup points and quotes PUDO without trusting browser metadata.
 *
 * @since 1.96.0
 */
final class PickupPointProvider {

	/**
	 * BlueX numeric region IDs mapped to WooCommerce ISO 3166-2 codes.
	 *
	 * @var array<int,string>
	 */
	private const BLUEX_REGION_IDS = array(
		1  => 'CL-TA',
		2  => 'CL-AN',
		3  => 'CL-AT',
		4  => 'CL-CO',
		5  => 'CL-VS',
		6  => 'CL-LI',
		7  => 'CL-ML',
		8  => 'CL-BI',
		9  => 'CL-AR',
		10 => 'CL-LL',
		11 => 'CL-AI',
		12 => 'CL-MA',
		13 => 'CL-RM',
		14 => 'CL-LR',
		15 => 'CL-AP',
		16 => 'CL-NB',
	);

	/**
	 * Provider region-name aliases normalized by normalize_region_name().
	 *
	 * @var array<string,string>
	 */
	private const REGION_NAME_CODES = array(
		'TARAPACA'                                      => 'CL-TA',
		'ANTOFAGASTA'                                   => 'CL-AN',
		'ATACAMA'                                       => 'CL-AT',
		'COQUIMBO'                                      => 'CL-CO',
		'VALPARAISO'                                    => 'CL-VS',
		'LIBERTADOR GENERAL BERNARDO O HIGGINS'         => 'CL-LI',
		'REGION DEL LIBERTADOR GENERAL BERNARDO O HIGGINS' => 'CL-LI',
		'MAULE'                                         => 'CL-ML',
		'BIO BIO'                                       => 'CL-BI',
		'BIOBIO'                                        => 'CL-BI',
		'ARAUCANIA'                                     => 'CL-AR',
		'LA ARAUCANIA'                                  => 'CL-AR',
		'LOS LAGOS'                                     => 'CL-LL',
		'AISEN DEL GENERAL CARLOS IBANEZ DEL CAMPO'     => 'CL-AI',
		'AYSEN DEL GENERAL CARLOS IBANEZ DEL CAMPO'     => 'CL-AI',
		'AYSEN'                                         => 'CL-AI',
		'MAGALLANES Y LA ANTARTICA CHILENA'             => 'CL-MA',
		'MAGALLANES'                                    => 'CL-MA',
		'METROPOLITANA DE SANTIAGO'                     => 'CL-RM',
		'REGION METROPOLITANA DE SANTIAGO'              => 'CL-RM',
		'LOS RIOS'                                      => 'CL-LR',
		'ARICA Y PARINACOTA'                            => 'CL-AP',
		'NUBLE'                                         => 'CL-NB',
	);

	/**
	 * @var Closure(): object
	 */
	private Closure $client_factory;

	/**
	 * @var Closure(): object
	 */
	private Closure $settings_factory;

	/**
	 * @var Closure(): mixed
	 */
	private Closure $discount_total_resolver;

	/**
	 * Creates the BlueX public-API adapter.
	 *
	 * @param callable|null $client_factory          Optional API client factory.
	 * @param callable|null $settings_factory        Optional settings factory.
	 * @param callable|null $discount_total_resolver Optional WooCommerce discount resolver.
	 *
	 * @since 1.96.0
	 */
	public function __construct(
		?callable $client_factory = null,
		?callable $settings_factory = null,
		?callable $discount_total_resolver = null
	) {
		$this->client_factory = null === $client_factory
			? static function (): object {
				if ( ! class_exists( '\BlueX_API_Client' ) ) {
					throw new PudoValidationException(
						'bluex_unavailable',
						'El retiro en Punto Blue Express no está disponible en este momento.'
					);
				}

				return new \BlueX_API_Client();
			}
			: Closure::fromCallable( $client_factory );

		$this->settings_factory = null === $settings_factory
			? static function (): object {
				if ( ! class_exists( '\WC_Correios_Settings' ) ) {
					throw new PudoValidationException(
						'bluex_unavailable',
						'El retiro en Punto Blue Express no está disponible en este momento.'
					);
				}

				return \WC_Correios_Settings::get_instance();
			}
			: Closure::fromCallable( $settings_factory );

		$this->discount_total_resolver = null === $discount_total_resolver
			? static function () {
				if ( ! function_exists( 'WC' ) ) {
					throw new PudoValidationException(
						'bluex_contract_missing',
						'No pudimos cotizar el punto de retiro seleccionado.'
					);
				}

				$woocommerce = WC();
				$cart        = is_object( $woocommerce ) ? ( $woocommerce->cart ?? null ) : null;
				if ( ! is_object( $cart ) || ! method_exists( $cart, 'get_discount_total' ) ) {
					throw new PudoValidationException(
						'bluex_contract_missing',
						'No pudimos cotizar el punto de retiro seleccionado.'
					);
				}

				return $cart->get_discount_total();
			}
			: Closure::fromCallable( $discount_total_resolver );
	}

	/**
	 * Resolves a server-verified pickup point from its agency ID.
	 *
	 * @param string $agency_id  Browser-provided agency identifier.
	 * @param string $address    Server-side customer city/address context.
	 * @param string $region_code Server-side Chilean region code.
	 * @return array<string, mixed>
	 *
	 * @throws PudoValidationException When the provider response cannot be trusted.
	 *
	 * @since 1.96.0
	 */
	public function resolve( string $agency_id, string $address, string $region_code ): array {
		$agency_id  = self::assert_agency_id( $agency_id );
		$address    = self::assert_address_context( $address );
		$region     = self::normalize_region_code( $region_code );
		$api_client = ( $this->client_factory )();

		if ( ! method_exists( $api_client, 'get_geolocation' ) ) {
			throw new PudoValidationException(
				'bluex_contract_missing',
				'No pudimos validar el punto de retiro seleccionado.'
			);
		}

		try {
			$response = $api_client->get_geolocation( $address, substr( $region, 3 ), $agency_id, true );
		} catch ( Throwable $exception ) {
			throw new PudoValidationException(
				'bluex_geolocation_failed',
				'No pudimos validar el punto de retiro seleccionado.',
				$exception // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- No es salida: es el argumento $previous del encadenado de excepciones; el mensaje al cliente es el literal de arriba.
			);
		}

		if ( self::is_error_response( $response ) || ! is_array( $response ) ) {
			throw new PudoValidationException(
				'bluex_geolocation_failed',
				'No pudimos validar el punto de retiro seleccionado.'
			);
		}

		return $this->canonicalize_geolocation( $response, $agency_id, $region );
	}

	/**
	 * Obtains a fail-closed PUDO quote for the canonical pickup point.
	 *
	 * @param array<string, mixed>              $pickup   Canonical pickup point.
	 * @param array<int|string, array<string,mixed>> $packages Shipping packages.
	 * @return array{total: float, is_free: bool, promise_day: int}
	 *
	 * @throws PudoValidationException When pricing is unavailable or malformed.
	 *
	 * @since 1.96.0
	 */
	public function quote( array $pickup, array $packages ): array {
		if ( 1 !== count( $packages ) ) {
			throw new PudoValidationException(
				'pudo_multiple_packages',
				'El retiro en Punto Blue Express no está disponible para pedidos divididos en varios envíos.'
			);
		}

		$api_client = ( $this->client_factory )();
		if ( ! method_exists( $api_client, 'get_pricing' ) ) {
			throw new PudoValidationException(
				'bluex_contract_missing',
				'No pudimos cotizar el punto de retiro seleccionado.'
			);
		}

		$settings        = ( $this->settings_factory )();
		$user_data       = $this->settings_data( $settings );
		$origin_district = $this->origin_district( $settings, $user_data );
		$package         = reset( $packages );

		if ( ! is_array( $package ) ) {
			throw new PudoValidationException(
				'pudo_invalid_package',
				'No pudimos cotizar el punto de retiro seleccionado.'
			);
		}

		$bultos        = $this->package_items( $package );
		$declared_value = $this->declared_value( $package );
		$from           = array(
			'country'  => 'CL',
			'district' => $origin_district,
		);
		$provider_region_id = self::strict_bluex_region_id( $pickup['provider_region_id'] ?? null );
		if ( null === $provider_region_id ) {
			throw new PudoValidationException(
				'bluex_region_missing',
				'No pudimos cotizar el punto de retiro seleccionado.'
			);
		}

		$to = array(
			'country'  => 'CL',
			'state'    => $provider_region_id,
			'district' => (string) $pickup['district_code'],
		);

		try {
			$response = $api_client->get_pricing(
				$from,
				$to,
				'EX',
				$bultos,
				$declared_value,
				'PUDO',
				$user_data
			);
		} catch ( Throwable $exception ) {
			throw new PudoValidationException(
				'bluex_pricing_failed',
				'No pudimos cotizar el punto de retiro seleccionado.',
				$exception // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- No es salida: es el argumento $previous del encadenado de excepciones; el mensaje al cliente es el literal de arriba.
			);
		}

		if ( self::is_error_response( $response ) || ! is_array( $response ) ) {
			throw new PudoValidationException(
				'bluex_pricing_failed',
				'No pudimos cotizar el punto de retiro seleccionado.'
			);
		}

		$code = (string) ( $response['code'] ?? '' );
		$data = $response['data'] ?? null;
		if ( ! in_array( $code, array( '00', '01' ), true ) || ! is_array( $data ) ) {
			throw new PudoValidationException(
				'bluex_pricing_rejected',
				'No pudimos cotizar el punto de retiro seleccionado.'
			);
		}

		$total       = self::strict_non_negative_float( $data['total'] ?? null );
		$is_free     = $data['isShipmentFree'] ?? null;
		$promise_day = self::strict_promise_day( $data['promiseDay'] ?? null );
		if ( null === $total || ! is_bool( $is_free ) || null === $promise_day ) {
			throw new PudoValidationException(
				'bluex_pricing_schema',
				'No pudimos cotizar el punto de retiro seleccionado.'
			);
		}

		if ( ( 0.0 === $total ) !== $is_free ) {
			throw new PudoValidationException(
				'bluex_pricing_inconsistent',
				'No pudimos confirmar el costo del punto de retiro seleccionado.'
			);
		}

		return array(
			'total'       => $total,
			'is_free'     => $is_free,
			'promise_day' => $promise_day,
		);
	}

	/**
	 * Validates and canonicalizes a geolocation response.
	 *
	 * @param array<string, mixed> $response        Provider response.
	 * @param string               $expected_id     Expected agency ID.
	 * @param string               $expected_region Expected canonical region.
	 * @return array<string, mixed>
	 */
	private function canonicalize_geolocation( array $response, string $expected_id, string $expected_region ): array {
		$confidence    = self::confidence_percentage( $response['porcentageDeExito'] ?? null );
		$provider_region_id = self::strict_bluex_region_id( $response['regionCode'] ?? null );
		$region_code   = null === $provider_region_id
			? null
			: ( self::BLUEX_REGION_IDS[ $provider_region_id ] ?? null );
		$named_region  = self::provider_region_name_to_code( $response['nameRegion'] ?? null );
		$district_code = self::strict_code( $response['districtCode'] ?? null );
		$city          = self::strict_text( $response['cidadeName'] ?? null, 120 );
		$pickup_info   = $response['pickupInfo'] ?? null;
		$location      = is_array( $pickup_info['location'] ?? null ) ? $pickup_info['location'] : null;
		$location_country = is_array( $location )
			? self::provider_country_code( $location['country_code'] ?? $location['country_name'] ?? null )
			: null;

		// BlueX cotiza con comuna y región del cliente más el ID de agencia; no exige
		// que la agencia pertenezca a esa región. region_code y named_region validan
		// la coherencia de nuestra geolocalización, que determina provider_region_id
		// y district_code de quote(). canonical_shipping_address() falla cerrado y
		// devuelve null si no puede formar una dirección coherente, sin mezclar regiones.
		// Cada guarda aporta una causa al registro; el mensaje público permanece seguro.

		$fallos = array_keys(
			array_filter(
				array(
					'confianza_baja'    => null === $confidence || 80.0 > $confidence,
					'region_codigo'     => null === $region_code || $expected_region !== $region_code,
					'region_nombre'     => $expected_region !== $named_region,
					'pais'              => 'CL' !== $location_country,
					'comuna_sin_codigo' => null === $district_code,
					'ciudad_vacia'      => null === $city,
					'pickup_info'       => ! is_array( $pickup_info ),
					'location'          => ! is_array( $location ),
				)
			)
		);
		if ( array() !== $fallos ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- el mensaje para el cliente es un literal; el detalle va al registro de WooCommerce por `PudoLog`, nunca a una salida HTML.
			throw new PudoValidationException(
				'bluex_geolocation_schema',
				'No pudimos confirmar el punto de retiro seleccionado.',
				null,
				// Sólo las banderas y la confianza: la región «esperada» es la
				// que el cliente escribió en la caja, y aquí no viaja nada suyo.
				array(
					'fallos'    => $fallos,
					'confianza' => $confidence,
				)
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$response_id = $pickup_info['agency_id']
			?? $pickup_info['agencyId']
			?? $pickup_info['id']
			?? null;
		$response_id = is_scalar( $response_id ) ? trim( (string) $response_id ) : '';
		if ( $expected_id !== $response_id ) {
			throw new PudoValidationException(
				'bluex_agency_mismatch',
				'El punto de retiro recibido no coincide con tu selección.'
			);
		}

		$agency_name = self::strict_text( $pickup_info['agency_name'] ?? null, 120 );
		if ( null === $agency_name ) {
			throw new PudoValidationException(
				'bluex_agency_name_missing',
				'No pudimos confirmar el punto de retiro seleccionado.'
			);
		}

		$shipping_address = $this->canonical_shipping_address( $location, $agency_name, $region_code );
		$agency_address   = self::strict_text( $pickup_info['agency_address'] ?? null, 240 );
		if ( null === $agency_address && null !== $shipping_address ) {
			$agency_address = trim(
				$shipping_address['address_1'] . ', ' . $shipping_address['city']
			);
		}

		return array(
			'agency_id'        => $expected_id,
			'agency_name'      => $agency_name,
			'agency_address'   => $agency_address,
			'region_code'      => $region_code,
			'provider_region_id' => $provider_region_id,
			'district_code'    => $district_code,
			'city'             => $city,
			'shipping_address' => $shipping_address,
		);
	}

	/**
	 * Builds an order-only address when BlueX returned a complete location.
	 *
	 * @param array<string, mixed>|null $location    Provider location.
	 * @param string                    $agency_name Agency name.
	 * @param string                    $region_code Canonical region code.
	 * @return array<string, string>|null
	 */
	private function canonical_shipping_address( ?array $location, string $agency_name, string $region_code ): ?array {
		if ( null === $location ) {
			return null;
		}

		$street   = self::strict_text( $location['street_name'] ?? null, 160 );
		$number   = self::strict_text( $location['street_number'] ?? null, 30, true );
		$city     = self::strict_text( $location['city_name'] ?? null, 120 );
		$country  = self::provider_country_code( $location['country_code'] ?? $location['country_name'] ?? null );
		$postcode = self::strict_text( $location['postcode'] ?? null, 20, true );
		$state    = self::provider_region_name_to_code( $location['state_name'] ?? $location['state_code'] ?? null );

		if ( null === $street || null === $city || 'CL' !== $country || $region_code !== $state ) {
			return null;
		}

		return array(
			'address_1' => self::street_address( $street, $number ),
			'address_2' => $agency_name,
			'city'      => $city,
			'state'     => $state,
			'country'   => 'CL',
			'postcode'  => $postcode ?? '',
		);
	}

	/**
	 * Converts one WooCommerce package into BlueX parcel rows.
	 *
	 * @param array<string, mixed> $package Shipping package.
	 * @return array<int, array<string, int|float>>
	 */
	private function package_items( array $package ): array {
		$contents = $package['contents'] ?? null;
		if ( ! is_array( $contents ) || array() === $contents ) {
			throw new PudoValidationException(
				'pudo_empty_package',
				'No pudimos cotizar el punto de retiro seleccionado.'
			);
		}

		$rows = array();
		foreach ( $contents as $item ) {
			if ( ! is_array( $item ) ) {
				throw new PudoValidationException(
					'pudo_invalid_package',
					'No pudimos cotizar el punto de retiro seleccionado.'
				);
			}

			$product  = $item['data'] ?? null;
			$quantity = self::strict_positive_integer( $item['quantity'] ?? null );
			if ( ! is_object( $product ) || null === $quantity ) {
				throw new PudoValidationException(
					'pudo_invalid_package',
					'No pudimos cotizar el punto de retiro seleccionado.'
				);
			}
			// Blue 3.3.1 (`WC_Correios_Package::get_bultos()`) no manda lo que no se despacha.
			if ( method_exists( $product, 'needs_shipping' ) && ! $product->needs_shipping() ) {
				continue;
			}

			$rows[] = array(
				'ancho'      => $this->product_measurement( $product, 'get_width', 10 ),
				'largo'      => $this->product_measurement( $product, 'get_length', 10 ),
				'alto'       => $this->product_measurement( $product, 'get_height', 10 ),
				'pesoFisico' => $this->product_weight( $product ),
				'cantidad'   => $quantity,
			);
		}

		return $rows;
	}

	/**
	 * Returns the integer measurement used by the BlueX parcel quote.
	 *
	 * Las cotizaciones comparan importes exactos y deben construir bultos idénticos.
	 * WC_Correios_Package::get_bultos() convierte a centímetros y redondea hacia
	 * arriba; una medida ausente o cero usa el fallback. Por ejemplo, 17 × 11,5 ×
	 * 1,5 cm se envía como 17/12/2, sin truncar decimales ni cambiar de tramo.
	 *
	 * @param object $product  Product.
	 * @param string $method   Getter method.
	 * @param int    $fallback Fallback value.
	 * @return int
	 */
	private function product_measurement( object $product, string $method, int $fallback ): int {
		if ( ! method_exists( $product, $method ) ) {
			return $fallback;
		}

		$value = (float) $product->{$method}();
		if ( function_exists( 'wc_get_dimension' ) ) {
			$value = (float) wc_get_dimension( $value, 'cm' );
		}

		return 0.0 < $value ? max( 1, (int) ceil( $value ) ) : $fallback;
	}

	/**
	 * Returns a positive product weight in kilograms, as BlueX 3.3.1 sends it.
	 *
	 * @param object $product Product.
	 * @return float
	 */
	private function product_weight( object $product ): float {
		if ( ! method_exists( $product, 'get_weight' ) ) {
			return 0.01;
		}

		$value = (float) $product->get_weight();
		if ( function_exists( 'wc_get_weight' ) ) {
			$value = (float) wc_get_weight( $value, 'kg' );
		}

		return 0.0 < $value ? $value : 0.01;
	}

	/**
	 * Reproduces the declared value sent by BlueX 3.2.2.
	 *
	 * @param array<string, mixed> $package Shipping package.
	 * @return float
	 */
	private function declared_value( array $package ): float {
		$contents = $package['contents'] ?? null;
		if ( ! is_array( $contents ) || array() === $contents ) {
			throw new PudoValidationException(
				'pudo_invalid_package_value',
				'No pudimos cotizar el punto de retiro seleccionado.'
			);
		}

		$value = 0.0;
		foreach ( $contents as $item ) {
			if ( ! is_array( $item ) ) {
				throw new PudoValidationException(
					'pudo_invalid_package_value',
					'No pudimos cotizar el punto de retiro seleccionado.'
				);
			}

			$product  = $item['data'] ?? null;
			$quantity = self::strict_positive_integer( $item['quantity'] ?? null );
			if (
				! $product instanceof \WC_Product
				|| ! method_exists( $product, 'get_price' )
				|| null === $quantity
			) {
				throw new PudoValidationException(
					'pudo_invalid_package_value',
					'No pudimos cotizar el punto de retiro seleccionado.'
				);
			}

			try {
				$price = self::strict_non_negative_float( $product->get_price( 'edit' ) );
			} catch ( Throwable $exception ) {
				throw new PudoValidationException(
					'bluex_contract_missing',
					'No pudimos cotizar el punto de retiro seleccionado.',
					$exception // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- No es salida: es el argumento $previous del encadenado de excepciones; el mensaje al cliente es el literal de arriba.
				);
			}

			if ( null === $price ) {
				throw new PudoValidationException(
					'pudo_invalid_package_value',
					'No pudimos cotizar el punto de retiro seleccionado.'
				);
			}

			$value += $price * $quantity;
			if ( ! is_finite( $value ) ) {
				throw new PudoValidationException(
					'pudo_invalid_package_value',
					'No pudimos cotizar el punto de retiro seleccionado.'
				);
			}
		}

		try {
			$discount = self::strict_non_negative_float( ( $this->discount_total_resolver )() );
		} catch ( PudoValidationException $exception ) {
			throw $exception;
		} catch ( Throwable $exception ) {
			throw new PudoValidationException(
				'bluex_contract_missing',
				'No pudimos cotizar el punto de retiro seleccionado.',
				$exception // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- No es salida: es el argumento $previous del encadenado de excepciones; el mensaje al cliente es el literal de arriba.
			);
		}

		if ( null === $discount ) {
			throw new PudoValidationException(
				'pudo_invalid_package_value',
				'No pudimos cotizar el punto de retiro seleccionado.'
			);
		}

		$value -= $discount;
		if ( ! is_finite( $value ) || 0.0 > $value ) {
			throw new PudoValidationException(
				'pudo_invalid_package_value',
				'No pudimos cotizar el punto de retiro seleccionado.'
			);
		}

		return $value;
	}

	/**
	 * Returns BlueX settings without credentials leaving the adapter.
	 *
	 * @param object $settings Settings object.
	 * @return array<string, mixed>
	 */
	private function settings_data( object $settings ): array {
		if ( method_exists( $settings, 'get_settings' ) ) {
			$value = $settings->get_settings();
			return is_array( $value ) ? $value : array();
		}

		return array();
	}

	/**
	 * Resolves the configured origin district.
	 *
	 * @param object               $settings  Settings object.
	 * @param array<string, mixed> $user_data Settings data.
	 * @return string
	 */
	private function origin_district( object $settings, array $user_data ): string {
		$value = null;
		if ( method_exists( $settings, 'get_setting' ) ) {
			$value = $settings->get_setting( 'districtCode' );
		}
		$value = $value ?? ( $user_data['districtCode'] ?? null );
		$code  = self::strict_code( $value );
		if ( null === $code ) {
			throw new PudoValidationException(
				'bluex_origin_missing',
				'El origen de Blue Express no está configurado.'
			);
		}

		return $code;
	}

	/**
	 * Validates an agency ID without lossy sanitization.
	 *
	 * @param string $agency_id Agency ID.
	 * @return string
	 */
	private static function assert_agency_id( string $agency_id ): string {
		$value = trim( $agency_id );
		if ( 1 !== preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,63}\z/', $value ) ) {
			throw new PudoValidationException(
				'pudo_invalid_agency',
				'El punto de retiro seleccionado no es válido.'
			);
		}

		return $value;
	}

	/**
	 * Validates a server-side geolocation context.
	 *
	 * @param string $address Address/city.
	 * @return string
	 */
	private static function assert_address_context( string $address ): string {
		$value = trim( $address );
		if (
			2 > strlen( $value )
			|| 160 < strlen( $value )
			|| 1 === preg_match( '/[\x00-\x1F\x7F]/', $value )
		) {
			throw new PudoValidationException(
				'pudo_invalid_address_context',
				'Completa una comuna válida antes de seleccionar tu punto de retiro.'
			);
		}

		return $value;
	}

	/**
	 * Canonicalizes a Chilean region code.
	 *
	 * @param string $region_code Region code.
	 * @return string
	 */
	private static function normalize_region_code( string $region_code ): string {
		$value = strtoupper( trim( $region_code ) );
		$value = str_starts_with( $value, 'CL-' ) ? $value : 'CL-' . $value;
		if ( 1 !== preg_match( '/\ACL-[A-Z]{2}\z/', $value ) ) {
			throw new PudoValidationException(
				'pudo_invalid_region',
				'Completa una región válida antes de seleccionar tu punto de retiro.'
			);
		}

		return $value;
	}

	/**
	 * Maps an exact BlueX numeric region identifier to WooCommerce ISO.
	 *
	 * @param mixed $value Provider region ID.
	 * @return string|null
	 */
	private static function strict_bluex_region_id( $value ): ?int {
		if ( is_string( $value ) && 1 !== preg_match( '/\A(?:[1-9]|1[0-6])\z/', trim( $value ) ) ) {
			return null;
		}
		if ( ! is_int( $value ) && ! is_string( $value ) ) {
			return null;
		}

		$id = (int) $value;
		return isset( self::BLUEX_REGION_IDS[ $id ] ) ? $id : null;
	}

	/**
	 * Maps a bounded provider region name (or ISO code) to WooCommerce ISO.
	 *
	 * @param mixed $value Provider region name.
	 * @return string|null
	 */
	private static function provider_region_name_to_code( $value ): ?string {
		$text = self::strict_text( $value, 120 );
		if ( null === $text ) {
			return null;
		}

		try {
			return self::normalize_region_code( $text );
		} catch ( PudoValidationException $exception ) {
			unset( $exception );
		}

		$name = self::normalize_region_name( $text );
		return self::REGION_NAME_CODES[ $name ] ?? null;
	}

	/**
	 * Normalizes accents and punctuation in a Chilean provider region name.
	 */
	private static function normalize_region_name( string $value ): string {
		$value = strtr(
			$value,
			array(
				'á' => 'a',
				'é' => 'e',
				'í' => 'i',
				'ó' => 'o',
				'ú' => 'u',
				'ü' => 'u',
				'ñ' => 'n',
				'Á' => 'A',
				'É' => 'E',
				'Í' => 'I',
				'Ó' => 'O',
				'Ú' => 'U',
				'Ü' => 'U',
				'Ñ' => 'N',
				'`' => "'",
				'’' => "'",
			)
		);
		$value = strtoupper( $value );
		$value = preg_replace( '/[^A-Z0-9]+/', ' ', $value );

		return trim( preg_replace( '/\s+/', ' ', (string) $value ) );
	}

	/**
	 * Returns CL only for the explicit Chile values emitted by BlueX.
	 *
	 * @param mixed $value Provider country value.
	 */
	private static function provider_country_code( $value ): ?string {
		$text = self::strict_text( $value, 40 );
		if ( null === $text ) {
			return null;
		}

		$normalized = self::normalize_region_name( $text );
		return in_array( $normalized, array( 'CL', 'CHILE' ), true ) ? 'CL' : null;
	}

	/**
	 * Avoids duplicating a street number already present in BlueX street_name.
	 */
	private static function street_address( string $street, ?string $number ): string {
		if ( null === $number || '' === $number ) {
			return $street;
		}

		$quoted = preg_quote( $number, '/' );
		return 1 === preg_match( '/(?:^|\s)' . $quoted . '\z/u', $street )
			? $street
			: trim( $street . ' ' . $number );
	}

	/**
	 * Parses BlueX confidence.
	 *
	 * @param mixed $value Raw confidence.
	 * @return float|null
	 */
	private static function confidence_percentage( $value ): ?float {
		if ( ! is_int( $value ) && ! is_float( $value ) && ! is_string( $value ) ) {
			return null;
		}

		$string = trim( (string) $value );
		if ( 1 !== preg_match( '/\A(?:100(?:\.0+)?|\d{1,2}(?:\.\d+)?)%?\z/', $string ) ) {
			return null;
		}

		return (float) rtrim( $string, '%' );
	}

	/**
	 * Returns a bounded plain text value.
	 *
	 * @param mixed $value       Raw value.
	 * @param int   $max_length  Maximum bytes.
	 * @param bool  $allow_empty Whether an empty value is allowed.
	 * @return string|null
	 */
	private static function strict_text( $value, int $max_length, bool $allow_empty = false ): ?string {
		if ( ! is_string( $value ) && ! is_int( $value ) && ! is_float( $value ) ) {
			return null;
		}

		$text = trim( (string) $value );
		if (
			$max_length < strlen( $text )
			|| 1 === preg_match( '/[\x00-\x1F\x7F]/', $text )
		) {
			return null;
		}

		// Los datos de la API se muestran en checkout y se guardan en el pedido.
		// Se limpian para impedir markup incluso si el proveedor devuelve datos sucios.
		// El helper permanece estático, en PHP puro y sin dependencia de WordPress.

		$text = trim( str_replace( array( '<', '>' ), '', strip_tags( $text ) ) );

		if ( ! $allow_empty && '' === $text ) {
			return null;
		}

		return $text;
	}

	/**
	 * Returns a strict provider code.
	 *
	 * @param mixed $value Raw code.
	 * @return string|null
	 */
	private static function strict_code( $value ): ?string {
		if ( ! is_string( $value ) && ! is_int( $value ) ) {
			return null;
		}

		$code = strtoupper( trim( (string) $value ) );
		return 1 === preg_match( '/\A[A-Z0-9][A-Z0-9_-]{0,15}\z/', $code ) ? $code : null;
	}

	/**
	 * Returns a strict non-negative float.
	 *
	 * @param mixed $value Raw value.
	 * @return float|null
	 */
	private static function strict_non_negative_float( $value ): ?float {
		if ( ! is_int( $value ) && ! is_float( $value ) && ! is_string( $value ) ) {
			return null;
		}
		if ( is_string( $value ) && 1 !== preg_match( '/\A\d+(?:\.\d+)?\z/', trim( $value ) ) ) {
			return null;
		}

		$number = (float) $value;
		return is_finite( $number ) && 0.0 <= $number ? $number : null;
	}

	/**
	 * Returns a strict non-negative integer.
	 *
	 * @param mixed $value Raw value.
	 * @return int|null
	 */
	/**
	 * Returns the BlueX promise day as a non-negative integer.
	 *
	 * `/eplin/pricing/v1` does not answer with a number: production returns the
	 * human sentence it shows to shoppers ("Hasta 3 días hábiles"). Demanding a
	 * strict integer here rejected every real quote, so PUDO could never be
	 * selected. Read the first integer in the sentence, keep accepting a plain
	 * integer, and stay null for anything without a number so the caller still
	 * fails closed.
	 *
	 * @param mixed $value Raw value.
	 * @return int|null
	 *
	 * @since 1.96.1
	 */
	private static function strict_promise_day( $value ): ?int {
		$number = self::strict_non_negative_integer( $value );
		if ( null !== $number ) {
			return $number;
		}

		if ( ! is_string( $value ) || 1 !== preg_match( '/\d+/', $value, $matches ) ) {
			return null;
		}

		$parsed = (int) $matches[0];

		// A delivery promise beyond a year is not a promise; treat it as malformed.
		return 365 >= $parsed ? $parsed : null;
	}

	private static function strict_non_negative_integer( $value ): ?int {
		if ( is_int( $value ) ) {
			return 0 <= $value ? $value : null;
		}
		if ( ! is_string( $value ) || 1 !== preg_match( '/\A\d+\z/', trim( $value ) ) ) {
			return null;
		}

		return (int) $value;
	}

	/**
	 * Returns a strict positive integer.
	 *
	 * @param mixed $value Raw value.
	 * @return int|null
	 */
	private static function strict_positive_integer( $value ): ?int {
		$number = self::strict_non_negative_integer( $value );
		return null !== $number && 0 < $number ? $number : null;
	}

	/**
	 * Detects WordPress error responses without requiring WordPress in tests.
	 *
	 * @param mixed $response Response.
	 * @return bool
	 */
	private static function is_error_response( $response ): bool {
		if ( function_exists( 'is_wp_error' ) && is_wp_error( $response ) ) {
			return true;
		}

		return class_exists( '\WP_Error' ) && $response instanceof \WP_Error;
	}
}
