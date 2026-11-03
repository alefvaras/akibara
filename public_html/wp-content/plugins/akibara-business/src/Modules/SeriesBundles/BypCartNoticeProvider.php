<?php
/**
 * Renderer-independent Build Your Pack cart notices.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\SeriesBundles;

use Akibara\Business\Modules\Stock\Availability;

defined( 'ABSPATH' ) || exit;

/**
 * Produces semantic notice data for first-party presentation adapters.
 */
final class BypCartNoticeProvider {

	public function __construct(
		private readonly Settings $settings,
		private readonly VolumeTierResolver $tiers,
		private readonly CartSeriesCollector $collector,
		private readonly PromotionPolicy $promotion_policy
	) {
	}

	/**
	 * Returns semantic bundle notices without HTML, hooks or transactional URLs.
	 *
	 * @return array<int,array{
	 *   id:string,
	 *   kind:string,
	 *   tone:string,
	 *   badge:string,
	 *   series_name:string,
	 *   title:string,
	 *   message:string,
	 *   missing_count:int,
	 *   discount_percent:int
	 * }>
	 */
	public function notices(): array {
		$cart = function_exists( 'WC' ) ? WC()->cart : null;
		if ( ! $cart instanceof \WC_Cart || $cart->is_empty() || ! $this->settings->is_enabled() ) {
			return array();
		}

		$series = $this->collector->collect( $cart );
		foreach ( $series as $hash => $data ) {
			$series[ $hash ]['tiers'] = $this->tiers->coalesce_equal_pct_tiers(
				$this->promotion_policy->reachable_tiers(
					(int) $data['term_id'],
					$this->tiers->sort_tiers( $data['tiers'] )
				)
			);
		}

		$notices = array();
		foreach ( $series as $hash => $data ) {
			if ( empty( $data['has_reservable'] ) || empty( $data['has_instock'] ) ) {
				continue;
			}

			// El `kind` no cambia: su enum vive en akibara-cart-checkout-blocks.
			// La palabra es la MISMA que la etiqueta por tomo (`Availability`): el
			// cliente leía «por encargo» en el aviso y «A pedido» en la línea de
			// al lado, dos nombres para lo mismo.
			$es_preventa = ! empty( $data['has_preventa'] );
			$a_pedido    = mb_strtolower( Availability::label_for_state( Availability::A_PEDIDO ) );
			$preventa    = mb_strtolower( Availability::label_for_state( Availability::PREVENTA ) );
			$name        = self::plain_text( (string) $data['name'] );
			$active_pct  = $this->tiers->resolve_pct( count( $data['items'] ), $data['tiers'] );
			$show_combo  = ! function_exists( 'get_option' ) || 'yes' === (string) get_option( 'akb_sb_show_combined_savings_ui', 'yes' );
			$combo_copy  = ( $show_combo && $active_pct > 0 )
				? sprintf( ' (Ahorro combinado activo: Reserva + Pack %d%% OFF).', (int) $active_pct )
				: '';
			$notices[]   = array(
				'id'               => 'mixed-preventa-' . $hash,
				'kind'             => 'mixed_preventa',
				'tone'             => 'warning',
				'badge'            => $es_preventa ? 'PRE' : '',
				'series_name'      => $name,
				'title'            => 'Pack mixto: ' . $name,
				'message'          => sprintf(
					'Este pack combina tomos disponibles y tomos %s. Despacho único al reunirse todos los tomos de tu pedido (revisa la fecha estimada de llegada).%s',
					$es_preventa ? 'en ' . $preventa : $a_pedido,
					$combo_copy
				),
				'missing_count'    => 0,
				'discount_percent' => (int) $active_pct,
			);
		}

		$has_external_coupon = false;
		foreach ( $cart->get_applied_coupons() as $applied ) {
			if ( 0 !== strpos( (string) $applied, BundleCouponEngine::COUPON_PREFIX ) ) {
				$has_external_coupon = true;
				break;
			}
		}

		if ( $has_external_coupon ) {
			foreach ( $series as $hash => $data ) {
				$pct = $this->tiers->resolve_pct( count( $data['items'] ), $data['tiers'] );
				if ( $pct <= 0 ) {
					continue;
				}

				$name      = self::plain_text( (string) $data['name'] );
				$notices[] = array(
					'id'               => 'external-coupon-' . $hash,
					'kind'             => 'external_coupon',
					'tone'             => 'warning',
					'badge'            => '%',
					'series_name'      => $name,
					'title'            => 'Descuento de pack no acumulable',
					'message'          => sprintf(
						'Tienes un cupón aplicado, por lo que el %d%% en los tomos elegibles del pack de %s no se acumula. Puedes quitar el cupón si el descuento del pack te conviene más.',
						(int) $pct,
						$name
					),
					'missing_count'    => 0,
					'discount_percent' => (int) $pct,
				);

			}

			// Un cupón externo desactiva todos los BYP alcanzados. Devolver tras
			// recorrerlos evita que el carrito nombre sólo la primera serie y deje
			// implícito, de forma engañosa, que las demás conservaron su descuento.
			return $notices;
		}

		foreach ( $series as $hash => $data ) {
			$distinct_qty = count( $data['items'] );
			$next_tier    = null;

			foreach ( $data['tiers'] as $tier ) {
				if ( $distinct_qty < (int) $tier['min'] ) {
					$next_tier = $tier;
					break;
				}
			}

			if ( null === $next_tier ) {
				continue;
			}

			$missing = (int) $next_tier['min'] - $distinct_qty;
			if ( $missing < 1 || $missing > 2 ) {
				continue;
			}

			$name      = self::plain_text( (string) $data['name'] );
			$pct       = (int) $next_tier['pct'];
			$tomo_text = 1 === $missing ? '1 tomo más' : '2 tomos más';
			$notices[] = array(
				'id'               => 'next-tier-' . $hash . '-' . (int) $next_tier['min'],
				'kind'             => 'next_tier',
				'tone'             => 'info',
				'badge'            => '+' . $missing,
				'series_name'      => $name,
				'title'            => 'Completa tu pack de ' . $name,
				'message'          => sprintf(
					'Suma %s de %s para desbloquear %d%% en los tomos elegibles del pack.',
					$tomo_text,
					$name,
					$pct
				),
				'missing_count'    => $missing,
				'discount_percent' => $pct,
			);
		}

		return $notices;
	}

	/**
	 * Normalizes dynamic copy to a single plain-text line.
	 */
	private static function plain_text( string $text ): string {
		$decoded = html_entity_decode( strip_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$plain   = preg_replace( '/\s+/u', ' ', str_replace( "\xc2\xa0", ' ', $decoded ) );

		return trim( is_string( $plain ) ? $plain : '' );
	}
}
