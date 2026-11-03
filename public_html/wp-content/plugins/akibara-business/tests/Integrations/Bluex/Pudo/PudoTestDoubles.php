<?php
/**
 * Dobles compartidos por las pruebas de PUDO.
 *
 * Vivían al pie de `PickupPointProviderTest.php`, y `PudoCheckoutServiceTest`
 * los usaba sin cargarlos: pasaba sólo porque PHPUnit carga todos los ficheros
 * de prueba antes de correr ninguno, y caía con «Class not found» al correrla
 * sola (2026-09-19, `tools/test-clases-aisladas.sh`).
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Integrations\Bluex\Pudo;

final class PudoApiClientStub {

	/**
	 * @var array<int, array<int, mixed>>
	 */
	public array $geolocation_calls = array();

	/**
	 * @var array<int, array<int, mixed>>
	 */
	public array $pricing_calls = array();

	/**
	 * @param mixed $geolocation Geolocation response.
	 * @param mixed $pricing     Pricing response.
	 */
	public function __construct( private $geolocation, private $pricing ) {
	}

	/**
	 * @return mixed
	 */
	public function get_geolocation( string $address, string $region, ?string $agency_id, bool $is_pudo ) {
		$this->geolocation_calls[] = array( $address, $region, $agency_id, $is_pudo );
		return $this->geolocation;
	}

	/**
	 * @param array<string, mixed>              $from
	 * @param array<string, mixed>              $to
	 * @param array<int, array<string, mixed>> $bultos
	 * @param array<string, mixed>|null         $user_data
	 * @return mixed
	 */
	public function get_pricing(
		array $from,
		array $to,
		string $service,
		array $bultos,
		float $declared_value,
		string $family,
		?array $user_data = null
	) {
		$this->pricing_calls[] = array(
			$from,
			$to,
			$service,
			$bultos,
			$declared_value,
			$family,
			$user_data,
		);
		return $this->pricing;
	}
}

final class PudoSettingsStub {

	/**
	 * @return array<string, mixed>
	 */
	public function get_settings(): array {
		return array(
			'districtCode' => 'SCL',
		);
	}

	/**
	 * @return mixed
	 */
	public function get_setting( string $key ) {
		return 'districtCode' === $key ? 'SCL' : null;
	}
}

final class PudoVirtualProductStub extends \WC_Product {

	public function needs_shipping(): bool {
		return false;
	}

	public function get_price( string $context = 'view' ): string {
		return '1000';
	}
}

final class PudoProductStub extends \WC_Product {

	/** @var array<int, string> */
	public array $price_contexts = array();

	public function __construct(
		private string $width = '18',
		private string $length = '25',
		private string $height = '4',
		private string $weight = '0.5',
		private string $price = '55000'
	) {
		parent::__construct();
	}

	public function get_width(): string {
		return $this->width;
	}

	public function get_length(): string {
		return $this->length;
	}

	public function get_height(): string {
		return $this->height;
	}

	public function get_weight(): string {
		return $this->weight;
	}

	public function get_price( string $context = 'view' ): string {
		$this->price_contexts[] = $context;
		return $this->price;
	}
}

final class PudoSessionStub {

	/**
	 * @var array<string, mixed>
	 */
	private array $data = array();

	public function __construct( private string $customer_id = 'pudo-session-1' ) {}

	/**
	 * @return mixed
	 */
	public function get( string $key, $default = null ) {
		return $this->data[ $key ] ?? $default;
	}

	/**
	 * @param mixed $value Value.
	 */
	public function set( string $key, $value ): void {
		$this->data[ $key ] = $value;
	}

	public function __unset( string $key ): void {
		unset( $this->data[ $key ] );
	}

	public function get_customer_id(): string {
		return $this->customer_id;
	}
}
