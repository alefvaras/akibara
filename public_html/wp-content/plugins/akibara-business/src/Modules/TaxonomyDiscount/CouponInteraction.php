<?php
/**
 * CouponInteraction — política de stacking con cupones.
 *
 * Default `stacks_with_coupons=no` → cart con item descontado por taxonomía
 * invalida los cupones con mensaje. Toggle desde el panel para habilitar stack.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\TaxonomyDiscount;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.16.1
 */
final class CouponInteraction {

	private Settings $settings;
	private DiscountResolver $resolver;

	public function __construct( Settings $settings, DiscountResolver $resolver ) {
		$this->settings = $settings;
		$this->resolver = $resolver;
	}

	public function register(): void {
		add_filter( 'woocommerce_coupon_is_valid', array( $this, 'maybe_invalidate' ), 10, 3 );
	}

	/**
	 * @throws \Exception Cuando se bloquea el cupón.
	 */
	public function maybe_invalidate( bool $valid, \WC_Coupon $coupon, $discounts = null ): bool {
		if ( ! $valid ) {
			return $valid;
		}
		if ( ! $this->settings->is_enabled() ) {
			return $valid;
		}
		if ( $this->settings->stacks_with_coupons() ) {
			return $valid;
		}

		$discounted = $this->get_discounted_item_names();
		if ( empty( $discounted ) ) {
			return $valid;
		}

		$mode = function_exists( 'get_option' ) ? (string) get_option( 'akibara_taxonomy_discount_coupon_mode', 'best_wins' ) : 'best_wins';
		$code = strtolower( (string) $coupon->get_code() );

		if ( 'best_wins' === $mode ) {
			// En modo best_wins, los cupones hola (bienvenida), akb_sb_ (packs), ref (referidos) y prefijos ignorados pasan
			if ( 0 === strpos( $code, 'hola' ) || 0 === strpos( $code, 'akb_sb_' ) || 0 === strpos( $code, 'ref' ) ) {
				return $valid;
			}
			$ignored_prefixes = apply_filters( 'akibara_taxonomy_discount_ignored_coupon_prefixes', array() );
			if ( is_array( $ignored_prefixes ) ) {
				foreach ( $ignored_prefixes as $prefix ) {
					if ( 0 === strpos( (string) $coupon->get_code(), (string) $prefix ) ) {
						return $valid;
					}
				}
			}
		} elseif ( 'whitelist_only' === $mode ) {
			// En modo whitelist_only, solo pasan hola (bienvenida) y cupones virtuales internos de pack (akb_sb_)
			if ( 0 === strpos( $code, 'hola' ) || 0 === strpos( $code, 'akb_sb_' ) ) {
				return $valid;
			}
			$ignored_prefixes = apply_filters( 'akibara_taxonomy_discount_ignored_coupon_prefixes', array() );
			if ( is_array( $ignored_prefixes ) ) {
				foreach ( $ignored_prefixes as $prefix ) {
					if ( 0 === strpos( (string) $coupon->get_code(), (string) $prefix ) ) {
						return $valid;
					}
				}
			}
		}
		// En modo strict_block (o si el cupón no calificó en los modos anteriores), se bloquea.

		// UX: nombrar el producto bloqueante + dar dos caminos claros (quitar
		// el item o quedarse con el descuento editorial sin cupón). Mostramos
		// hasta 3 nombres para no saturar el notice.
		$shown = array_slice( $discounted, 0, 3 );
		$more  = count( $discounted ) > 3 ? sprintf( ' y %d más', count( $discounted ) - 3 ) : '';
		$list  = implode( ', ', $shown ) . $more;
		$plural = count( $discounted ) > 1;

		throw new \Exception( sprintf(
			'Tu cupón no se acumula con descuentos por editorial. %s ya %s un descuento aplicado — quita%s del carrito para usar el cupón, o aprovecha el descuento editorial sin cupón.',
			esc_html( $list ),
			$plural ? 'tienen' : 'tiene',
			$plural ? 'los' : 'lo'
		) );
	}

	/**
	 * @return array<int, string> Nombres de productos con descuento editorial activo.
	 */
	private function get_discounted_item_names(): array {
		$names = array();
		if ( ! function_exists( 'WC' ) || null === WC()->cart ) {
			return $names;
		}

		foreach ( WC()->cart->get_cart() as $item ) {
			if ( ! isset( $item['data'] ) || ! $item['data'] instanceof \WC_Product ) {
				continue;
			}
			// Misma fuente de verdad que el precio real: resolve() devuelve null
			// exactamente cuando NO se aplica descuento (p.ej. producto en rebaja
			// manual bajo la policy skip_if_on_sale, o regular_price inválido).
			// Usar get_applied_pct() > 0 contaba como "descontado" items que en
			// realidad conservan su precio, y rechazaba cupones válidos.
			if ( null !== $this->resolver->resolve( $item['data'] ) ) {
				$names[] = (string) $item['data']->get_name();
			}
		}
		return $names;
	}
}
