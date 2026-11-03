<?php
/**
 * Minimal PUDO runtime fixtures for isolated adapter tests.
 *
 * @package Akibara\CartCheckoutBlocks\Tests
 */

declare( strict_types = 1 );

require_once __DIR__ . '/woocommerce-functions.php';

if ( ! function_exists( 'woocommerce_store_api_register_update_callback' ) ) {
	/**
	 * Captures the active Store API callback registration.
	 *
	 * @param array<string,mixed> $args Callback registration.
	 */
	function woocommerce_store_api_register_update_callback( array $args ): void {
		$GLOBALS['akibara_test_pudo_update_callback'] = $args;
	}
}

if ( ! class_exists( 'Akibara_Test_Pudo_Session' ) ) {
	/**
	 * Minimal WooCommerce session.
	 */
	final class Akibara_Test_Pudo_Session {

		/** @var array<string,mixed> */
		public array $values;

		/** @var array<int,string> */
		public array $unset_keys = array();

		/**
		 * @param array<string,mixed> $values Initial values.
		 */
		public function __construct( array $values = array() ) {
			$this->values = $values;
		}

		/**
		 * @param mixed $default Default value.
		 * @return mixed
		 */
		public function get( string $key, $default = null ) {
			return $this->values[ $key ] ?? $default;
		}

		/**
		 * @param mixed $value Session value.
		 */
		public function set( string $key, $value ): void {
			$this->values[ $key ] = $value;
		}

		public function __unset( string $key ): void {
			$this->unset_keys[] = $key;
			unset( $this->values[ $key ] );
		}
	}
}

if ( ! class_exists( 'Akibara_Test_Pudo_Cart' ) ) {
	/**
	 * Minimal shipping-package source.
	 */
	final class Akibara_Test_Pudo_Cart {

		/** @var array<int,array> */
		private array $packages;

		/**
		 * @param array<int,array> $packages Shipping packages.
		 */
		public function __construct( array $packages ) {
			$this->packages = $packages;
		}

		/**
		 * @return array<int,array>
		 */
		public function get_shipping_packages(): array {
			return $this->packages;
		}
	}
}

if ( ! class_exists( 'Akibara_Test_Pudo_Runtime' ) ) {
	/**
	 * Minimal calculated shipping-package source.
	 */
	final class Akibara_Test_Pudo_Shipping {

		/** @var array<int,array> */
		private array $packages;

		/**
		 * @param array<int,array> $packages Calculated shipping packages.
		 */
		public function __construct( array $packages ) {
			$this->packages = $packages;
		}

		/**
		 * @return array<int,array>
		 */
		public function get_packages(): array {
			return $this->packages;
		}
	}

	/**
	 * Minimal WooCommerce runtime.
	 */
	final class Akibara_Test_Pudo_Runtime {

		public Akibara_Test_Pudo_Cart $cart;

		public Akibara_Test_Pudo_Session $session;

		public object $customer;

		private Akibara_Test_Pudo_Shipping $shipping_service;

		/**
		 * @param array<int,array> $calculated_packages Calculated packages with rates.
		 */
		public function __construct(
			Akibara_Test_Pudo_Cart $cart,
			Akibara_Test_Pudo_Session $session,
			array $calculated_packages = array()
		) {
			$this->cart     = $cart;
			$this->session  = $session;
			$this->shipping_service = new Akibara_Test_Pudo_Shipping( $calculated_packages );
			$this->customer = new class() {
				public function __call( string $method, array $arguments ): void {
					unset( $method, $arguments );
					throw new RuntimeException( 'PUDO Blocks must never mutate WC_Customer.' );
				}
			};
		}

		public function shipping(): Akibara_Test_Pudo_Shipping {
			return $this->shipping_service;
		}
	}
}

if ( ! class_exists( 'Akibara_Test_Pudo_Request' ) ) {
	/**
	 * Minimal Store API request.
	 */
	final class Akibara_Test_Pudo_Request {

		/** @var array<string,mixed> */
		public array $params;

		/**
		 * @param array<string,mixed> $extensions Initial extension map.
		 */
		public function __construct( array $extensions = array() ) {
			$this->params = array( 'extensions' => $extensions );
		}

		/**
		 * @return mixed
		 */
		public function get_param( string $key ) {
			return $this->params[ $key ] ?? null;
		}

		/**
		 * @param mixed $value Request value.
		 */
		public function set_param( string $key, $value ): void {
			$this->params[ $key ] = $value;
		}
	}
}

if ( ! class_exists( 'Akibara_Test_Pudo_Shipping_Item' ) ) {
	/**
	 * Minimal order shipping item.
	 */
	final class Akibara_Test_Pudo_Shipping_Item {

		private string $method_id;

		/** @var array<string,mixed> */
		private array $meta;

		/**
		 * @param array<string,mixed> $meta Shipping item metadata.
		 */
		public function __construct( string $method_id, array $meta = array() ) {
			$this->method_id = $method_id;
			$this->meta      = $meta;
		}

		public function get_method_id(): string {
			return $this->method_id;
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

if ( ! class_exists( 'Akibara_Test_Pudo_Order' ) ) {
	/**
	 * Minimal checkout order.
	 */
	final class Akibara_Test_Pudo_Order {

		/** @var array<int,Akibara_Test_Pudo_Shipping_Item> */
		private array $items;

			/** @var array<string,mixed> */
			public array $meta = array();

			/** @var array<string,string> */
			public array $shipping_address = array( 'address_1' => 'Dirección residencial original' );

			/** @var array<string,string> */
			public array $billing_address = array( 'address_1' => 'Facturación original' );

		/**
		 * @param array<int,Akibara_Test_Pudo_Shipping_Item> $items Shipping items.
		 */
		public function __construct( array $items ) {
			$this->items = $items;
		}

		/**
		 * @return array<int,Akibara_Test_Pudo_Shipping_Item>
		 */
		public function get_items( string $type ): array {
			return 'shipping' === $type ? $this->items : array();
		}

		/**
		 * @param mixed $value Order metadata value.
		 */
			public function update_meta_data( string $key, $value ): void {
				$this->meta[ $key ] = $value;
			}

			public function set_shipping_address_1( string $value ): void {
				$this->shipping_address['address_1'] = $value;
			}

			public function set_shipping_address_2( string $value ): void {
				$this->shipping_address['address_2'] = $value;
			}

			public function set_shipping_city( string $value ): void {
				$this->shipping_address['city'] = $value;
			}

			public function set_shipping_state( string $value ): void {
				$this->shipping_address['state'] = $value;
			}

			public function set_shipping_country( string $value ): void {
				$this->shipping_address['country'] = $value;
			}

			public function set_shipping_postcode( string $value ): void {
				$this->shipping_address['postcode'] = $value;
			}
		}
	}
