<?php
/**
 * SaleDiscount — la aritmética del descuento de una oferta.
 *
 * Vivía dentro de `LoopEnhancer`, que es un RENDERIZADOR: 952 líneas que pintan
 * badges, precios y botones. Que el cálculo del porcentaje viviera ahí adentro
 * significaba que la única forma de preguntarle a la tienda «¿cuánto se rebaja
 * este producto?» era renderizar una tarjeta, y que la regla del umbral —la
 * decisión de negocio de no gritar un −3% encima de una preventa— no se podía
 * leer ni probar sin el HTML alrededor.
 *
 * Puro y estático: sin hooks, sin estado, con el producto por parámetro. Las
 * entradas externas son el umbral configurado (una option, editable en
 * Akibara → Descuentos) y el filtro que sigue pudiendo pisarlo, ambos
 * documentados abajo.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\LoopEnhancer;

use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.110.1
 */
final class SaleDiscount {

	/** Option del umbral, editable en Akibara → Descuentos. */
	public const OPTION_MIN_PCT = 'akb_sale_badge_min_pct';

	/** El literal que decidía el sello antes de existir la pantalla. */
	public const DEFAULT_MIN_PCT = 10;

	/** Umbral 0 = el sello se pinta siempre que haya rebaja. */
	public const MIN_PCT_FLOOR = 0;

	/** Por encima de 90 el sello no se pintaría nunca: sería apagarlo a ciegas. */
	public const MIN_PCT_CEILING = 90;

	/**
	 * Umbral configurado, ya acotado a su dominio.
	 *
	 * Es el DEFAULT que las dos lecturas le pasan al filtro
	 * `akibara_loop_sale_badge_min_pct`: quien tenga un add_filter sigue mandando.
	 * Un valor ilegible cae al literal de siempre, no a 0 (un 0 accidental
	 * llenaría el catálogo de sellos «−1%»).
	 *
	 * @param mixed $raw Candidato crudo; por defecto se lee de la option.
	 */
	public static function configured_min_pct( $raw = null ): int {
		if ( null === $raw ) {
			$raw = get_option( self::OPTION_MIN_PCT, self::DEFAULT_MIN_PCT );
		}

		if ( is_int( $raw ) ) {
			return max( self::MIN_PCT_FLOOR, min( self::MIN_PCT_CEILING, $raw ) );
		}

		if ( ! is_string( $raw ) && ! is_float( $raw ) ) {
			return self::DEFAULT_MIN_PCT;
		}

		$candidate = trim( (string) $raw );
		if ( 1 !== preg_match( '/^-?(0|[1-9][0-9]*)$/D', $candidate ) ) {
			return self::DEFAULT_MIN_PCT;
		}

		return max( self::MIN_PCT_FLOOR, min( self::MIN_PCT_CEILING, (int) $candidate ) );
	}

	/**
	 * Calcula el descuento % del producto en oferta. Soporta simple + variable.
	 *
	 * Para variable: usa el descuento máximo entre variations en oferta.
	 * Devuelve null si no hay descuento computable (regular_price faltante,
	 * sale_price >= regular, sin variations en oferta) o —solo si
	 * `$suppress_trivial` es true (oferta apilada sobre preventa)— si el descuento
	 * queda por debajo del umbral mínimo (ver `akibara_loop_sale_badge_min_pct`).
	 *
	 * @param WC_Product $product          Producto a evaluar.
	 * @param bool       $suppress_trivial Aplicar el umbral anti-trivial (solo cuando
	 *                                     la oferta se apila sobre "Preventa"). Default false.
	 * @return string|null Label "−NN%" o null si no aplica.
	 */
	public static function label( WC_Product $product, bool $suppress_trivial = false ): ?string {
		/**
		 * Umbral mínimo de % de descuento para mostrar el badge de oferta CUANDO se
		 * apila sobre "Preventa". Un −1%…−9% sobre una preventa lee como contradicción
		 * (abarata la percepción de marca por implicar "casi sin rebaja"). El default
		 * es el umbral configurado en Akibara → Descuentos (10% de fábrica); el filtro
		 * sigue existiendo para campañas puntuales por código. Para ofertas standalone
		 * el piso es 1% (cualquier liquidación real muestra su badge).
		 *
		 * @param int        $min_pct Umbral mínimo en puntos porcentuales.
		 * @param WC_Product $product Producto evaluado.
		 */
		$min_pct = $suppress_trivial
			? (int) apply_filters( 'akibara_loop_sale_badge_min_pct', self::configured_min_pct(), $product )
			: 1;

		if ( $product->is_type( 'variable' ) ) {
			$max_pct  = 0;
			$children = (array) $product->get_children();
			if ( ! empty( $children ) && function_exists( '_prime_post_caches' ) ) {
				_prime_post_caches( array_map( 'intval', $children ), true, true );
			}
			foreach ( $children as $child_id ) {
				$child = wc_get_product( $child_id );
				if ( ! $child instanceof WC_Product || ! $child->is_on_sale() ) {
					continue;
				}
				$pct = self::pct( $child );
				if ( $pct > $max_pct ) {
					$max_pct = $pct;
				}
			}
			return $max_pct >= $min_pct ? sprintf( '−%d%%', $max_pct ) : null;
		}

		$pct = self::pct( $product );
		return $pct >= $min_pct ? sprintf( '−%d%%', $pct ) : null;
	}

	/**
	 * % de descuento de un producto simple o de una variation.
	 *
	 * @param WC_Product $product Producto/variation.
	 * @return int Porcentaje (0 si no calculable).
	 */
	public static function pct( WC_Product $product ): int {
		$regular = (float) $product->get_regular_price();
		$sale    = (float) $product->get_sale_price();

		if ( $regular <= 0.0 || $sale <= 0.0 || $sale >= $regular ) {
			return 0;
		}

		return (int) round( ( ( $regular - $sale ) / $regular ) * 100 );
	}
}
