<?php
/** Shared purchase hints. WooCommerce and Rewards retain the final commercial decision. */

declare( strict_types = 1 );

namespace Akibara\Business\Services;

use Akibara\Business\Modules\FreeShippingProgress\FreeShippingProgress;
use Akibara\Business\Modules\ShippingCopy\ShippingCopy;
use Akibara\Business\Utilities\PluginAssetVersion;
use Akibara\Rewards\Points\ProgramPage;
use Akibara\Rewards\Points\Promise;
use Akibara\Rewards\Points\Tiers;

defined( 'ABSPATH' ) || exit;

/** @api Public integration contract for the collection, series selection and native cart. */
final class PurchaseIncentives {

	/** One renderer and stylesheet for both selection interfaces. */
	public static function enqueue_client(): void {
		$css = 'assets/css/purchase-incentives.css';
		$js = 'assets/js/purchase-incentives.js';
		wp_enqueue_style( 'akb-purchase-incentives', AKIBARA_BUSINESS_URL . $css, array(), PluginAssetVersion::for_path( $css ) );
		wp_enqueue_script( 'akb-purchase-incentives', AKIBARA_BUSINESS_URL . $js, array(), PluginAssetVersion::for_path( $js ), true );
	}

	/** Personal estimates on series pages must never enter a shared page cache. */
	public static function private_series_headers(): void {
		if ( get_current_user_id() < 1 || ! is_tax( 'pa_serie' ) ) {
			return;
		}
		nocache_headers();
		do_action( 'litespeed_control_set_nocache', 'Estimación personal de compra Akibara' );
	}

	/**
	 * Cacheable callers MUST leave $user_id at zero. A personal rate requires private headers.
	 *
	 * @return array{rewards:array{points:int,amount:int,multiplier:float,program_url:string},shipping:array{enabled:bool,threshold:int}}
	 */
	public static function configuration( int $user_id = 0 ): array {
		$rate = class_exists( Promise::class ) ? Promise::rate() : array( 'points' => 0, 'amount' => 0 );
		return array(
			'rewards' => array(
				'points' => $rate['points'],
				'amount' => $rate['amount'],
				'multiplier' => $user_id > 0 && class_exists( Tiers::class ) ? Tiers::multiplier_for_user( $user_id ) : 1.0,
				'program_url' => class_exists( ProgramPage::class ) ? ProgramPage::url() : '',
			),
			'shipping' => array(
				'enabled' => 'yes' === get_option( FreeShippingProgress::OPTION_ENABLED, 'yes' ),
				'threshold' => ShippingCopy::threshold(),
			),
		);
	}

	/**
	 * Private state only: never embed it in a cacheable public page.
	 *
	 * @return array{product_ids:list<int>,rewards:array{points:int},shipping:array<string,mixed>}|null
	 */
	public static function current_cart_state(): ?array {
		if ( ! function_exists( 'WC' ) ) {
			return null;
		}
		$woocommerce = WC();
		$cart = is_object( $woocommerce ) ? $woocommerce->cart : null;
		return $cart instanceof \WC_Cart ? self::cart_state( $cart ) : null;
	}

	/**
	 * A calculated cart, using the authorities already consumed by checkout and drawer.
	 *
	 * @return array{product_ids:list<int>,rewards:array{points:int},shipping:array<string,mixed>}
	 */
	public static function cart_state( \WC_Cart $cart ): array {
		$ids = array();
		foreach ( $cart->get_cart() as $item ) {
			$id = (int) ( $item['product_id'] ?? 0 );
			if ( $id > 0 ) {
				$ids[ $id ] = $id;
			}
		}
		return array(
			'product_ids' => array_values( $ids ),
			'rewards' => array( 'points' => class_exists( Promise::class ) ? Promise::for_cart( $cart, (int) get_current_user_id() ) : 0 ),
			'shipping' => ( new FreeShippingProgress() )->structured_state(),
		);
	}
}
