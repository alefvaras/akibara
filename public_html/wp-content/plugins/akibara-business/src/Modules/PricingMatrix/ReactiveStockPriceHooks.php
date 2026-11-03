<?php
/**
 * Hooks reactivos para transiciones de stock (`instock` <-> `onbackorder`) y
 * prevención raíz de filas duplicadas al clonar/guardar productos en WooCommerce.
 *
 * @package Akibara\Business\Modules\PricingMatrix
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\PricingMatrix;

use Akibara\Business\Modules\Stock\StockStatus;
use WC_Product;

defined( 'ABSPATH' ) || exit;

final class ReactiveStockPriceHooks {

	/**
	 * Claves de postmeta excluidas al duplicar un producto en WooCommerce
	 * para erradicar la acumulación N+1 de filas por `add_post_meta(..., false)`.
	 *
	 * @var list<string>
	 */
	public const EXCLUDED_DUPLICATE_META_KEYS = array(
		'_akb_onbackorder_at',
		'_akb_first_instock_at',
		'_akb_pdp_specs_generation',
		'_akb_sf_restocked_at',
		'_akb_tramo_prev_regular',
		'_akb_tramo_prev_sale',
		'rank_math_internal_links_processed',
	);

	/**
	 * Guardia contra recursión por product_id.
	 *
	 * @var array<int, true>
	 */
	private static array $in_transition = array();

	/**
	 * Registra los hooks reactivos y los filtros de clonación y prevención de duplicados.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'woocommerce_duplicate_product_exclude_meta', array( $this, 'exclude_duplicate_meta' ), 10, 1 );
		add_action( 'woocommerce_product_duplicate_before_save', array( $this, 'strip_duplicate_meta_before_save' ), 10, 1 );
		add_filter( 'add_post_metadata', array( $this, 'prevent_singleton_meta_duplication' ), 10, 5 );
		add_action( 'woocommerce_product_set_stock_status', array( $this, 'on_stock_status_transition' ), 9, 3 );
		add_action( 'woocommerce_update_product', array( $this, 'ensure_no_duplicate_meta_on_save' ), 99, 1 );
	}

	/**
	 * Cortocircuita cualquier `add_metadata('post', ..., $unique = false)` sobre claves singleton
	 * (como hace `WC_Data_Store_WP::add_meta()` al duplicar/guardar un producto) cuando la fila
	 * ya fue creada por un hook previo durante `wp_insert_post`, convirtiendo el INSERT duplicado
	 * en un `update_post_meta()` in-place.
	 *
	 * @param null|bool $check      Valor de cortocircuito (null permite continuar).
	 * @param int       $object_id  ID del post/producto.
	 * @param string    $meta_key   Clave de metadato.
	 * @param mixed     $meta_value Valor de metadato.
	 * @param bool      $unique     Flag unique original.
	 * @return null|bool
	 */
	public function prevent_singleton_meta_duplication( $check, $object_id, $meta_key, $meta_value, $unique ) {
		unset( $unique );
		$object_id = (int) $object_id;
		$meta_key  = (string) $meta_key;

		if ( null !== $check || $object_id <= 0 || ! in_array( $meta_key, self::EXCLUDED_DUPLICATE_META_KEYS, true ) ) {
			return $check;
		}

		if ( metadata_exists( 'post', $object_id, $meta_key ) ) {
			update_post_meta( $object_id, $meta_key, $meta_value );
			return true;
		}

		return null;
	}

	/**
	 * Excluye metadatos internos del clonador de productos de WooCommerce.
	 *
	 * @param array<int, string> $exclude_meta Lista de meta_keys excluidas.
	 * @return array<int, string>
	 */
	public function exclude_duplicate_meta( $exclude_meta ): array {
		$list = is_array( $exclude_meta ) ? $exclude_meta : array();
		return array_values( array_unique( array_merge( $list, self::EXCLUDED_DUPLICATE_META_KEYS ) ) );
	}

	/**
	 * Limpia metadatos internos del objeto clonado antes de persistirlo.
	 *
	 * @param mixed $duplicate Objeto WC_Product clonado.
	 * @return void
	 */
	public function strip_duplicate_meta_before_save( $duplicate ): void {
		if ( ! $duplicate instanceof WC_Product ) {
			return;
		}
		foreach ( self::EXCLUDED_DUPLICATE_META_KEYS as $key ) {
			$duplicate->delete_meta_data( $key );
		}
	}

	/**
	 * Ajusta reactivamente `_sale_price` y `_akb_onbackorder_at` cuando un producto
	 * transiciona entre `onbackorder` e `instock`.
	 *
	 * @param int|string      $product_id ID del producto.
	 * @param string          $new_status Nuevo estado de stock (`instock`|`onbackorder`|`outofstock`).
	 * @param WC_Product|null $product    Instancia opcional del producto.
	 * @return void
	 */
	public function on_stock_status_transition( $product_id, $new_status, $product = null ): void {
		$product_id = (int) $product_id;
		$new_status = (string) $new_status;

		if ( $product_id <= 0 || isset( self::$in_transition[ $product_id ] ) || BatchGuard::is_active() ) {
			return;
		}

		self::$in_transition[ $product_id ] = true;

		try {
			if ( ! $product instanceof WC_Product && function_exists( 'wc_get_product' ) ) {
				$product = wc_get_product( $product_id );
			}
			if ( ! $product instanceof WC_Product ) {
				return;
			}

			$regular = (int) round( (float) $product->get_regular_price( 'edit' ) );

			if ( StockStatus::ON_BACKORDER === $new_status ) {
				$existing_ts = (string) get_post_meta( $product_id, '_akb_onbackorder_at', true );
				if ( '' === $existing_ts ) {
					PricingSynchronizer::update_unique_post_meta( $product_id, '_akb_onbackorder_at', (string) time() );
				}

				$eta_type = (string) get_post_meta( $product_id, '_akb_eta_type', true );
				if ( 'disabled' !== $eta_type && $regular > 0 ) {
					$target_sale = PricingRulesRepository::resolve_sale_price_for_product( $product, $regular );
					$current_sale = (int) round( (float) $product->get_sale_price( 'edit' ) );
					if ( $target_sale > 0 && $target_sale < $regular && $current_sale !== $target_sale ) {
						$product->set_sale_price( (string) $target_sale );
						$product->save();
					}
				}
			} elseif ( StockStatus::IN_STOCK === $new_status ) {
				$stock_qty   = $product->get_stock_quantity( 'edit' );
				$has_stock   = null === $stock_qty || (int) $stock_qty > 0;
				$manual_lock = 'yes' === (string) get_post_meta( $product_id, '_akb_manual_sale_lock', true );
				$tax_disc_on = 'yes' === (string) get_option( 'akibara_taxonomy_discount_enabled', 'no' );

				if ( $has_stock && ! $manual_lock && ! $tax_disc_on ) {
					$current_sale = (string) $product->get_sale_price( 'edit' );
					if ( '' !== $current_sale ) {
						$product->set_sale_price( '' );
						$product->save();
					}
				}
			} elseif ( StockStatus::OUT_OF_STOCK === $new_status ) {
				$manual_lock = 'yes' === (string) get_post_meta( $product_id, '_akb_manual_sale_lock', true );
				if ( ! $manual_lock ) {
					$current_sale = (string) $product->get_sale_price( 'edit' );
					if ( '' !== $current_sale ) {
						$product->set_sale_price( '' );
						$product->save();
					}
				}
			}

			PricingSynchronizer::dedup_post_meta_keys( $product_id );
		} finally {
			unset( self::$in_transition[ $product_id ] );
		}
	}

	/**
	 * Garantiza que ningún guardado de producto deje filas duplicadas en wp_postmeta.
	 *
	 * @param int|string $product_id ID del producto.
	 * @return void
	 */
	public function ensure_no_duplicate_meta_on_save( $product_id ): void {
		$product_id = (int) $product_id;
		if ( $product_id > 0 ) {
			PricingSynchronizer::dedup_post_meta_keys( $product_id );
		}
	}
}
