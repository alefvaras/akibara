<?php
/**
 * WooCommerce Store API adapter.
 *
 * @package Akibara\CartCheckoutBlocks
 */

declare( strict_types = 1 );

namespace Akibara\CartCheckoutBlocks;

use Akibara\Business\Contracts\CheckoutDataProvider;
use Automattic\WooCommerce\StoreApi\Schemas\V1\CartItemSchema;
use Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Owns every Akibara Cart and Checkout Blocks Store API extension.
 *
 * @since 2.0.0
 */
final class StoreApiIntegration {

	public const NAMESPACE = 'akibara_cart_checkout_blocks';

	/**
	 * Versión del contrato de presentación que viaja en el Store API.
	 *
	 * Dueño único del número: los dos bundles del cliente
	 * (`cart-checkout-blocks.js` y `checkout-inner-blocks-frontend.js`) lo
	 * comparan y descartan el payload si no coincide, así que subirlo aquí sin
	 * subirlo allá deja las superficies mudas sin un solo error.
	 * `PluginContractTest` exige que los tres números sigan siendo el mismo.
	 */
	public const SCHEMA_VERSION = 2;

	private CheckoutDataProvider $data_provider;

	private BlocksPage $blocks_page;

	private bool $registered = false;

	/**
	 * Caché intra-request de disponibilidad por ID de producto y estado de stock.
	 *
	 * Deduplica cálculos entre los callbacks de Store API y filtros de WooCommerce
	 * en la misma petición HTTP.
	 *
	 * La clave incluye el estado de stock, no solo el ID: si algo lo cambia
	 * dentro de la misma petición (reducción de stock al confirmar el pedido)
	 * la clave cambia y el memo falla en vez de servir un dato viejo.
	 *
	 * @var array<string,array<string,bool|int|string|null>>
	 */
	private array $availability_memo = array();

	/**
	 * Initializes the Store API adapter.
	 *
	 * @param CheckoutDataProvider $data_provider Stable Business contract.
	 * @param BlocksPage           $blocks_page   Blocks page resolver.
	 *
	 * @since 2.0.0
	 */
	public function __construct( CheckoutDataProvider $data_provider, BlocksPage $blocks_page ) {
		$this->data_provider = $data_provider;
		$this->blocks_page   = $blocks_page;
	}

	/**
	 * Registers Store API data and native item metadata.
	 *
	 * @since 2.0.0
	 */
	public function register(): void {
		add_filter( 'woocommerce_get_item_data', array( $this, 'append_stock_item_data' ), 20, 2 );

		if ( did_action( 'woocommerce_blocks_loaded' ) ) {
			$this->register_store_api_data();
			return;
		}

		add_action( 'woocommerce_blocks_loaded', array( $this, 'register_store_api_data' ) );
	}

	/**
	 * Registers the Cart and Cart Item extensions exactly once.
	 *
	 * @internal
	 */
	public function register_store_api_data(): void {
		if (
			$this->registered
			|| ! class_exists( CartSchema::class )
			|| ! class_exists( CartItemSchema::class )
			|| ! function_exists( 'woocommerce_store_api_register_endpoint_data' )
		) {
			return;
		}

		woocommerce_store_api_register_endpoint_data(
			array(
				'endpoint'        => CartSchema::IDENTIFIER,
				'namespace'       => self::NAMESPACE,
				'data_callback'   => array( $this, 'extend_cart_data' ),
				'schema_callback' => array( $this, 'extend_cart_schema' ),
				'schema_type'     => ARRAY_A,
			)
		);

		woocommerce_store_api_register_endpoint_data(
			array(
				'endpoint'        => CartItemSchema::IDENTIFIER,
				'namespace'       => self::NAMESPACE,
				'data_callback'   => array( $this, 'extend_cart_item_data' ),
				'schema_callback' => array( $this, 'extend_cart_item_schema' ),
				'schema_type'     => ARRAY_A,
			)
		);

		$this->registered = true;
	}

	/**
	 * Returns the Cart extension schema.
	 *
	 * @return array<string,array<string,mixed>>
	 *
	 * @since 2.0.0
	 */
	public function extend_cart_schema(): array {
		return array(
			'schema_version' => $this->readonly_schema( 'Versión del contrato de presentación del carrito.', 'integer' ),
			'bundle'         => $this->readonly_object_schema(
				'Estado estructurado de Build Your Pack.',
				array(
					'coupon_prefix'        => array( 'type' => 'string' ),
					'coupon_labels'        => array(
						'type'                 => 'object',
						'additionalProperties' => array( 'type' => 'string' ),
					),
					'generic_coupon_label' => array( 'type' => 'string' ),
					'notices'              => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'id'               => array( 'type' => 'string' ),
								'kind'             => array(
									'type' => 'string',
									'enum' => array( 'mixed_preventa', 'external_coupon', 'next_tier' ),
								),
								'tone'             => array(
									'type' => 'string',
									'enum' => array( 'info', 'warning' ),
								),
								'badge'            => array( 'type' => 'string' ),
								'series_name'      => array( 'type' => 'string' ),
								'title'            => array( 'type' => 'string' ),
								'message'          => array( 'type' => 'string' ),
								'missing_count'    => array( 'type' => 'integer' ),
								'discount_percent' => array( 'type' => 'integer' ),
							),
						),
					),
				)
			),
			'free_shipping'  => $this->readonly_object_schema(
				'Estado estructurado del beneficio de envío.',
				array(
					'state'               => array(
						'type' => 'string',
						'enum' => array( 'hidden', 'progress', 'qualified' ),
					),
					'reason'              => array(
						'type' => 'string',
						'enum' => array( 'unavailable', 'local_pickup', 'order_amount', 'coupon', 'zero_fee' ),
					),
					'visible_on_cart'     => array( 'type' => 'boolean' ),
					'visible_on_checkout' => array( 'type' => 'boolean' ),
					'show_qualified'      => array( 'type' => 'boolean' ),
					'title'               => array( 'type' => 'string' ),
					'message'             => array( 'type' => 'string' ),
					'threshold'           => array( 'type' => 'number' ),
					'total'               => array( 'type' => 'number' ),
					'remaining'           => array( 'type' => 'number' ),
					'progress_percent'    => array( 'type' => 'number' ),
					'almost_there'        => array( 'type' => 'boolean' ),
				)
			),
			'preventa'       => $this->readonly_object_schema(
				'Resumen estructurado de preventas.',
				array(
					'count'         => array( 'type' => 'integer' ),
					'is_mixed'      => array( 'type' => 'boolean' ),
					'summary_label' => array( 'type' => 'string' ),
					'summary_value' => array( 'type' => 'string' ),
					'has_tba'       => array( 'type' => 'boolean' ),
					'explanation'   => array( 'type' => 'string' ),
				)
			),
			'trust_signals'  => array_merge(
				$this->readonly_schema( 'Señales de confianza del checkout.', 'array' ),
				array(
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'id'    => array(
								'type' => 'string',
								'enum' => array( 'shipping', 'installments', 'support' ),
							),
							'label' => array( 'type' => 'string' ),
							'icon'  => array( 'type' => 'string' ),
						),
					),
				)
			),
		);
	}

	/**
	 * Returns the current Cart extension data.
	 *
	 * @return array<string,mixed>
	 *
	 * @since 2.0.0
	 */
	public function extend_cart_data(): array {
		if ( ! method_exists( $this->data_provider, 'structured_cart_data' ) ) {
			return array( 'schema_version' => 0 );
		}

		$data = $this->data_provider->structured_cart_data();
		if ( self::SCHEMA_VERSION !== (int) ( $data['schema_version'] ?? 0 ) ) {
			return array( 'schema_version' => 0 );
		}

		$bundle                  = is_array( $data['bundle'] ?? null ) ? $data['bundle'] : array();
		$bundle['coupon_labels'] = (object) (array) ( $bundle['coupon_labels'] ?? array() );
		$data['bundle']          = $bundle;

		return $data;
	}

	/**
	 * Returns the Cart Item extension schema.
	 *
	 * @return array<string,array<string,mixed>>
	 *
	 * @since 2.0.0
	 */
	public function extend_cart_item_schema(): array {
		return array(
			'stock_status' => array_merge(
				$this->readonly_schema( 'Estado de stock canónico de Akibara.', 'string' ),
				array( 'enum' => array( 'instock', 'onbackorder', 'outofstock' ) )
			),
			'reservation_price_label' => $this->readonly_schema( 'Etiqueta del precio vigente de reserva, vacía en ofertas reales.', 'string' ),
			'reservation_price_note' => $this->readonly_schema( 'Explicación del precio futuro, sin modificar importes.', 'string' ),
			'stock_label' => $this->readonly_schema( 'Etiqueta visible canónica del estado de stock.', 'string' ),
			'is_preventa' => $this->readonly_schema( 'Indica si el producto se vende en preventa.', 'boolean' ),
			'eta' => array(
				'description' => 'Fecha o estado estimado de llegada de la preventa.',
				'type'        => array( 'string', 'null' ),
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
			),
			'eta_timestamp' => array(
				'description' => 'Timestamp máximo de llegada; null cuando la fecha está por confirmar.',
				'type'        => array( 'integer', 'null' ),
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
			),
		);
	}

	/**
	 * Returns canonical product availability for a Store API cart item.
	 *
	 * @param array<string,mixed> $cart_item Store API cart item source.
	 * @return array<string,bool|int|string|null>
	 *
	 * @since 2.0.0
	 */
	public function extend_cart_item_data( array $cart_item ): array {
		$product = $cart_item['data'] ?? null;
		if ( ! $product instanceof WC_Product ) {
			return array(
				'stock_status' => 'outofstock',
				'stock_label'  => 'Agotado',
				'is_preventa'  => false,
				'eta'           => null,
				'eta_timestamp' => null,
			);
		}

		return $this->availability( $product );
	}

	/**
	 * Appends canonical stock and preventa ETA labels to native item metadata.
	 *
	 * @internal
	 *
	 * @param array<int,array<string,mixed>> $item_data Existing item metadata.
	 * @param array<string,mixed>            $cart_item Cart item.
	 * @return array<int,array<string,mixed>>
	 */
	public function append_stock_item_data( array $item_data, array $cart_item ): array {
		$woocommerce = function_exists( 'WC' ) ? WC() : null;
		$is_store_api_request = is_object( $woocommerce )
			&& method_exists( $woocommerce, 'is_store_api_request' )
			&& $woocommerce->is_store_api_request();
		$is_blocks_page       = $this->blocks_page->is_cart() || $this->blocks_page->is_checkout();

		/*
		 * Woo also builds the Cart Item schema while preloading Block data during
		 * the initial HTML request. That request is not marked as Store API, so a
		 * REST-only gate omitted "Estado" until the next client refresh. Limit the
		 * fallback to pages that really contain the Cart/Checkout Block so classic
		 * templates keep their existing metadata untouched.
		 */
		if ( ! $is_store_api_request && ! $is_blocks_page ) {
			return $item_data;
		}

		$product = $cart_item['data'] ?? null;
		if ( ! $product instanceof WC_Product ) {
			return $item_data;
		}

		$availability = $this->availability( $product );
		$item_data[]  = array(
			'key'     => 'Estado',
			'display' => $availability['stock_label'],
		);
		if (
			! empty( $availability['is_preventa'] )
			&& is_string( $availability['eta'] ?? null )
			&& '' !== trim( $availability['eta'] )
		) {
			$item_data[] = array(
				'key'     => 'Llegada estimada a Akibara',
				'display' => trim( $availability['eta'] ),
			);
		}

		if ( ! empty( $availability['reservation_price_note'] ) ) {
			$item_data[] = array(
				'key'     => 'Precio futuro',
				'display' => $availability['reservation_price_note'],
			);
		}

		return $item_data;
	}

	/**
	 * Devuelve la disponibilidad del producto reutilizando la de esta petición.
	 *
	 * Las dos superficies de un mismo ítem (schema del Store API y metadatos
	 * nativos) preguntan lo mismo; el contrato devuelto es idéntico, así que
	 * memoizar no cambia ninguna respuesta, solo deja de recalcularla.
	 *
	 * Sin ID (producto no persistido) no hay clave fiable: se recalcula, que es
	 * el comportamiento previo.
	 *
	 * @param WC_Product $product Producto del ítem del carrito.
	 * @return array<string,bool|int|string|null>
	 */
	private function availability( WC_Product $product ): array {
		$id = (int) $product->get_id();
		if ( $id <= 0 ) {
			return $this->data_provider->product_availability( $product );
		}

		$key = $id . '|' . (string) $product->get_stock_status();
		if ( ! array_key_exists( $key, $this->availability_memo ) ) {
			$this->availability_memo[ $key ] = $this->data_provider->product_availability( $product );
		}

		return $this->availability_memo[ $key ];
	}

	/**
	 * Builds a standard readonly schema property.
	 *
	 * @param string       $description Human-readable description.
	 * @param string|array $type        JSON schema type.
	 * @return array<string,mixed>
	 */
	private function readonly_schema( string $description, $type ): array {
		return array(
			'description' => $description,
			'type'        => $type,
			'context'     => array( 'view', 'edit' ),
			'readonly'    => true,
		);
	}

	/**
	 * Builds a readonly object schema without accepting arbitrary properties.
	 *
	 * @param string                            $description Human-readable description.
	 * @param array<string,array<string,mixed>> $properties  Object properties.
	 * @return array<string,mixed>
	 */
	private function readonly_object_schema( string $description, array $properties ): array {
		return array_merge(
			$this->readonly_schema( $description, 'object' ),
			array(
				'properties'           => $properties,
				'additionalProperties' => false,
			)
		);
	}
}
