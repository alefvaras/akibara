<?php
/**
 * Minimal checkout runtime fixtures for isolated adapter tests.
 *
 * @package Akibara\CartCheckoutBlocks\Tests
 */

declare( strict_types = 1 );

if ( ! class_exists( 'WC_Order' ) ) {
	/**
	 * Minimal order exposing the final billing phone and addresses.
	 */
	class WC_Order {

		private string $billing_phone;

		/** @var array<string,string> */
		private array $address = array();

		/** @var array<string,mixed> */
		private array $meta = array();

		/**
		 * @param array<string,string> $address Address parts keyed `billing_city`, `shipping_state`…
		 */
		public function __construct( string $billing_phone = '', array $address = array() ) {
			$this->billing_phone = $billing_phone;
			$this->address       = $address;
		}

		public function get_billing_phone(): string {
			return $this->billing_phone;
		}

		public function get_billing_country(): string {
			return $this->address['billing_country'] ?? '';
		}

		public function get_billing_state(): string {
			return $this->address['billing_state'] ?? '';
		}

		public function get_billing_city(): string {
			return $this->address['billing_city'] ?? '';
		}

		public function set_billing_city( string $value ): void {
			$this->address['billing_city'] = $value;
		}

		public function get_shipping_country(): string {
			return $this->address['shipping_country'] ?? '';
		}

		public function get_shipping_state(): string {
			return $this->address['shipping_state'] ?? '';
		}

		public function get_shipping_city(): string {
			return $this->address['shipping_city'] ?? '';
		}

		public function set_shipping_city( string $value ): void {
			$this->address['shipping_city'] = $value;
		}

		/**
		 * @param mixed $value Meta value.
		 */
		public function update_meta_data( string $key, $value ): void {
			$this->meta[ $key ] = $value;
		}

		/**
		 * @return mixed
		 */
		public function get_meta( string $key, bool $single = true ) {
			unset( $single );

			return $this->meta[ $key ] ?? '';
		}
	}
}

if ( ! class_exists( 'WC_Customer' ) ) {
	/**
	 * Minimal customer metadata and address fixture.
	 */
	class WC_Customer {

		/** @var array<string,string> */
		private array $address = array();

		/** @var array<string,mixed> */
		private array $meta = array();

		/**
		 * @param array<string,string> $address Address parts keyed `billing_city`, `shipping_state`…
		 */
		public function __construct( array $address = array() ) {
			$this->address = $address;
		}

		public function get_billing_country(): string {
			return $this->address['billing_country'] ?? '';
		}

		public function get_billing_state(): string {
			return $this->address['billing_state'] ?? '';
		}

		public function get_billing_city(): string {
			return $this->address['billing_city'] ?? '';
		}

		public function set_billing_city( string $value ): void {
			$this->address['billing_city'] = $value;
		}

		public function get_shipping_country(): string {
			return $this->address['shipping_country'] ?? '';
		}

		public function get_shipping_state(): string {
			return $this->address['shipping_state'] ?? '';
		}

		public function get_shipping_city(): string {
			return $this->address['shipping_city'] ?? '';
		}

		public function set_shipping_city( string $value ): void {
			$this->address['shipping_city'] = $value;
		}

		/**
		 * @param mixed $value Meta value.
		 */
		public function update_meta_data( string $key, $value ): void {
			$this->meta[ $key ] = $value;
		}

		/**
		 * @return mixed
		 */
		public function get_meta( string $key, bool $single = true ) {
			unset( $single );

			return $this->meta[ $key ] ?? '';
		}
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Minimal error bag matching the adapter's usage.
	 */
	class WP_Error {

		/** @var array<string,array<int,string>> */
		private array $errors = array();

		public function add( string $code, string $message ): void {
			$this->errors[ $code ][] = $message;
		}

		/**
		 * @return array<int,string>
		 */
		public function get_error_messages( string $code ): array {
			return $this->errors[ $code ] ?? array();
		}
	}
}

if ( ! class_exists( 'WC_Address_Provider' ) ) {
	/**
	 * Minimal WooCommerce address-provider contract.
	 */
	abstract class WC_Address_Provider {

		/** @var string */
		public $id = '';

		/** @var string */
		public $name = '';

		/** @var string */
		public $branding_html = '';
	}
}

if ( ! class_exists( 'Akibara_Test_Address_Provider' ) ) {
	/**
	 * Third-party provider fixture used to preserve object entries.
	 */
	final class Akibara_Test_Address_Provider extends WC_Address_Provider {

		public function __construct( string $id = 'third-party' ) {
			$this->id   = $id;
			$this->name = 'Third party';
		}
	}
}
