<?php
/**
 * Snapshot inmutable de "este line item era preventa" al momento del checkout.
 *
 * Captura la información que produce EstimatedDelivery::calculator() y la persiste
 * en order_itemmeta. Esto sobrevive al cambio posterior de stock_status del
 * producto — clave para que la orden mantenga su marca aunque el producto pase
 * de onbackorder a instock entre la compra y el despacho.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\OrderPreventa;

use Akibara\Business\Modules\EstimatedDelivery\DateCalculator;
use Akibara\Business\Modules\EstimatedDelivery\EtaResult;
use Akibara\Business\Modules\Stock\Availability;
use Akibara\Business\Modules\Stock\StockStatus;
use WC_Order;
use WC_Order_Item_Product;
use WC_Product;
use Akibara\Business\Contracts\EtaProvider;

defined( 'ABSPATH' ) || exit;

/**
 * Escribe el snapshot de preventa en cada line item al crear la orden.
 *
 * @since 1.14.0
 */
final class LineItemSnapshot {

	private EtaProvider $calculator;

	public function __construct( EtaProvider $calculator ) {
		$this->calculator = $calculator;
	}

	public function register(): void {
		add_action(
			'woocommerce_checkout_create_order_line_item',
			array( $this, 'on_create_line_item' ),
			10,
			4
		);
	}

	/**
	 * Pinta los metas de snapshot sobre el line item recién creado.
	 *
	 * El hook se dispara después de que WC armó el item pero antes de
	 * persistirlo. update_meta_data() queda pendiente y se guarda cuando el
	 * order se persiste — no requerimos save() explícito acá.
	 *
	 * @param WC_Order_Item_Product $item         Line item recién creado.
	 * @param string                $cart_item_key Clave del cart item original.
	 * @param array<string, mixed>  $values       Cart item values.
	 * @param WC_Order              $order        Orden contenedora.
	 * @return void
	 */
	public function on_create_line_item( $item, $cart_item_key, $values, $order ): void {
		unset( $cart_item_key, $values, $order );

		if ( ! $item instanceof WC_Order_Item_Product ) {
			return;
		}

		$product = $item->get_product();
		if ( ! $product instanceof WC_Product ) {
			$this->clear_preventa_snapshot( $item );
			return;
		}

		$is_preventa = StockStatus::is_reservable( $product );

		if ( ! $is_preventa ) {
			$this->clear_preventa_snapshot( $item );
			return;
		}

		$item->update_meta_data( MetaKeys::ITEM_WAS_PREVENTA, 'yes' );
		$this->stamp_preventa_details( $item, $product );
	}

	/**
	 * Escribe el snapshot de preventa de UNA línea: qué prometía la ficha en el
	 * momento en que se compró.
	 *
	 * REGLA ÚNICA de qué metas lleva una línea de preventa. El back-fill
	 * (`WpCli::backfill_one`) la reimplementaba línea a línea, y las dos copias
	 * ya habían divergido: la del CLI nunca capturó la fecha de lanzamiento.
	 * Cualquier meta nueva se agrega acá y las dos rutas la ganan juntas.
	 *
	 * NO escribe `ITEM_WAS_PREVENTA`: ese flag es del llamador, porque el
	 * checkout sabe que la línea ES preventa ('yes') y el back-fill sólo puede
	 * inferirlo del stock de HOY (yes|unknown|no).
	 *
	 * @param WC_Order_Item_Product $item    Línea a estampar.
	 * @param WC_Product            $product Producto de la línea.
	 * @return void
	 */
	public function stamp_preventa_details( WC_Order_Item_Product $item, WC_Product $product ): void {
		$item->update_meta_data(
			MetaKeys::ITEM_TYPE_AT_PURCHASE,
			Availability::sanitize_type( (string) $product->get_meta( DateCalculator::META_TYPE ) )
		);

		$country_slug = $this->calculator->resolve_country( $product );
		if ( null !== $country_slug ) {
			$item->update_meta_data( MetaKeys::ITEM_COUNTRY_AT_PURCHASE, $country_slug );

			// Snapshot inmutable del display name. Sobrevive a rename del término.
			$country_name = $this->calculator->get_country_name( $country_slug );
			if ( null !== $country_name ) {
				$item->update_meta_data( MetaKeys::ITEM_COUNTRY_NAME_AT_PURCHASE, $country_name );
			}
		}

		$eta = $this->calculator->calculate( $product );
		if ( ! $eta instanceof EtaResult ) {
			// type=disabled o sin brand mapeado → was_preventa sigue yes pero no
			// hay ETA capturable. Marcamos is_tba=no para diferenciar de TBA real.
			$item->update_meta_data( MetaKeys::ITEM_IS_TBA_AT_PURCHASE, 'no' );
			return;
		}

		$item->update_meta_data( MetaKeys::ITEM_IS_TBA_AT_PURCHASE, $eta->is_tba() ? 'yes' : 'no' );

		$min_ts = $eta->min_ts();
		$max_ts = $eta->max_ts();
		if ( null !== $min_ts && null !== $max_ts ) {
			$item->update_meta_data( MetaKeys::ITEM_MIN_AT_PURCHASE, $min_ts );
			$item->update_meta_data( MetaKeys::ITEM_MAX_AT_PURCHASE, $max_ts );
		}
	}

	/**
	 * Borra cualquier snapshot preventa de un item que dejó de pertenecer a ese
	 * flujo antes de persistirse (por ejemplo, un draft Store API actualizado).
	 *
	 * @param WC_Order_Item_Product $item Item a normalizar.
	 * @return void
	 */
	private function clear_preventa_snapshot( WC_Order_Item_Product $item ): void {
		foreach (
			array(
				MetaKeys::ITEM_WAS_PREVENTA,
				MetaKeys::ITEM_TYPE_AT_PURCHASE,
				MetaKeys::ITEM_MIN_AT_PURCHASE,
				MetaKeys::ITEM_MAX_AT_PURCHASE,
				MetaKeys::ITEM_COUNTRY_AT_PURCHASE,
				MetaKeys::ITEM_COUNTRY_NAME_AT_PURCHASE,
				MetaKeys::ITEM_IS_TBA_AT_PURCHASE,
			) as $meta_key
		) {
			$item->delete_meta_data( $meta_key );
		}
	}
}
