<?php
/**
 * PriceFilters — registra los filtros runtime de precio.
 *
 * Misma estrategia que la propuesta original: filter-only, no persiste
 * `_sale_price`. Soporta variations + variation_prices + strikethrough +
 * cinturón cart-side.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\TaxonomyDiscount;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.16.1
 */
final class PriceFilters {

	private DiscountResolver $resolver;

	public function __construct( DiscountResolver $resolver ) {
		$this->resolver = $resolver;
	}

	public function register(): void {
		add_filter( 'woocommerce_product_get_price',      array( $this, 'filter_price' ), 10, 2 );
		add_filter( 'woocommerce_product_get_sale_price', array( $this, 'filter_sale_price' ), 10, 2 );
		add_filter( 'woocommerce_product_is_on_sale',     array( $this, 'filter_is_on_sale' ), 10, 2 );

		add_filter( 'woocommerce_product_variation_get_price',      array( $this, 'filter_price' ), 10, 2 );
		add_filter( 'woocommerce_product_variation_get_sale_price', array( $this, 'filter_sale_price' ), 10, 2 );

		add_filter( 'woocommerce_variation_prices_price',      array( $this, 'filter_variation_prices' ), 10, 3 );
		add_filter( 'woocommerce_variation_prices_sale_price', array( $this, 'filter_variation_prices' ), 10, 3 );

		add_filter( 'woocommerce_get_variation_prices_hash', array( $this, 'filter_variation_prices_hash' ), 10, 3 );

		add_filter( 'woocommerce_get_price_html', array( $this, 'filter_price_html' ), 20, 2 );

		add_action( 'woocommerce_before_calculate_totals', array( $this, 'apply_to_cart' ), 20, 1 );
	}

	public function filter_price( $price, $product ) {
		if ( ! $product instanceof \WC_Product ) {
			return $price;
		}
		$final = $this->resolver->resolve( $product );
		return null === $final ? $price : (string) $final;
	}

	public function filter_sale_price( $sale, $product ) {
		if ( ! $product instanceof \WC_Product ) {
			return $sale;
		}
		$final = $this->resolver->resolve( $product );
		return null === $final ? $sale : (string) $final;
	}

	public function filter_is_on_sale( $is_on_sale, $product ) {
		if ( ! $product instanceof \WC_Product ) {
			return $is_on_sale;
		}
		$final = $this->resolver->resolve( $product );
		return null === $final ? $is_on_sale : true;
	}

	public function filter_variation_prices( $price, $variation, $parent ) {
		if ( ! $variation instanceof \WC_Product ) {
			return $price;
		}
		$final = $this->resolver->resolve( $variation );
		return null === $final ? $price : (string) $final;
	}

	public function filter_variation_prices_hash( $hash, $product, $for_display ) {
		if ( $product instanceof \WC_Product ) {
			$pct = $this->resolver->get_applied_pct( $product );
			if ( $pct > 0 ) {
				// Incluir la firma de config (enabled + policy), no solo el %:
				// si el admin cambia la policy o desactiva el módulo, el precio
				// computado cambia aunque el % siga igual y WC serviría el precio
				// de variación cacheado stale.
				$hash[] = 'akb_taxonomy_discount_' . $pct . '_' . $this->resolver->price_config_signature();
			}
		}
		return $hash;
	}

	public function filter_price_html( $html, $product ) {
		if ( ! $product instanceof \WC_Product ) {
			return $html;
		}
		$final = $this->resolver->resolve( $product );
		if ( null === $final ) {
			return $html;
		}

		$regular = $product->get_regular_price( 'edit' );
		if ( '' === $regular || ! is_numeric( $regular ) ) {
			return $html;
		}
		$regular = (float) $regular;
		if ( $regular <= 0 || $final >= $regular ) {
			return $html;
		}

		$args       = array( 'price' => $regular );
		$args_final = array( 'price' => $final );

		return wc_format_sale_price(
			wc_get_price_to_display( $product, $args ),
			wc_get_price_to_display( $product, $args_final )
		) . $product->get_price_suffix();
	}

	/**
	 * Cinturón cart-side: re-sella el precio en el objeto de cada línea.
	 *
	 * La fuente de verdad es `woocommerce_product_get_price` (arriba), que
	 * corre en CADA lectura de `get_price()`; esto solo cubre a los lectores
	 * que se quedan con el objeto del carrito.
	 *
	 * NO se guarda estado para "correr una sola vez": el guard anterior usaba
	 * `did_action( 'woocommerce_before_calculate_totals') >= 2`, un contador
	 * GLOBAL del request que incrementa aunque el disparo venga de otro actor
	 * (Store API, un plugin que recalcule totales antes). Con dos recálculos
	 * en el mismo request los ítems agregados en la segunda pasada quedaban
	 * fuera del cinturón. Re-aplicar es idempotente y barato: el resolver
	 * computa desde `get_regular_price( 'edit')` y memoiza por producto, así
	 * que no hay acumulación posible por pasar de nuevo.
	 *
	 * @param mixed $cart Carrito que WooCommerce está por recalcular.
	 * @return void
	 */
	public function apply_to_cart( $cart ): void {
		if ( ! $cart instanceof \WC_Cart ) {
			return;
		}
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		foreach ( $cart->get_cart() as $item ) {
			if ( ! isset( $item['data'] ) || ! $item['data'] instanceof \WC_Product ) {
				continue;
			}
			$product = $item['data'];
			$final   = $this->resolver->resolve( $product );
			if ( null === $final ) {
				continue;
			}
			$product->set_price( $final );
		}
	}
}
