<?php
/**
 * BusinessOptions — Repositorio central tipado de opciones del negocio Akibara (v1.1).
 *
 * Centraliza la lectura y guardado tipado de las opciones de wp_options con sanitización
 * rigurosa, valores por defecto seguros y validación de techos comerciales (DiscountCeiling).
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Support;

use Akibara\Business\Utilities\DiscountCeiling;

defined( 'ABSPATH' ) || exit;

final class BusinessOptions {

	// Pestaña 1: Techo Comercial y Políticas Generales
	public const OPT_DISCOUNT_MAX_PCT                   = 'akb_discount_max_pct';
	public const OPT_BACKORDER_DISCOUNT_ENABLED         = 'akb_backorder_discount_enabled';
	public const OPT_BACKORDER_DISCOUNT_PCT             = 'akb_backorder_discount_pct';
	public const OPT_TAXONOMY_DISCOUNT_ENABLED          = 'akibara_taxonomy_discount_enabled';
	public const OPT_TAXONOMY_DISCOUNT_COUPON_MODE      = 'akibara_taxonomy_discount_coupon_mode';

	// Pestaña 2: «Arma tu Pack» (SeriesBundles)
	public const OPT_SERIES_BUNDLES_ENABLED             = 'akb_series_bundles_enabled';
	public const OPT_SERIES_BUNDLES_BYP                 = 'akb_series_bundles_byp';
	public const OPT_SERIES_BUNDLES_ALLOW_BACKORDER     = 'akb_series_bundles_allow_backorder';
	public const OPT_SERIES_BUNDLES_BACKORDER_MODE      = 'akb_series_bundles_backorder_mode';
	public const OPT_SB_TIER0_MIN_QTY                   = 'akb_sb_tier0_min_qty';
	public const OPT_SB_TIER0_PCT                       = 'akb_sb_tier0_pct';
	public const OPT_SB_TIER1_MIN_QTY                   = 'akb_sb_tier1_min_qty';
	public const OPT_SB_TIER1_PCT                       = 'akb_sb_tier1_pct';
	public const OPT_SB_TIER2_MIN_QTY                   = 'akb_sb_tier2_min_qty';
	public const OPT_SB_TIER2_PCT                       = 'akb_sb_tier2_pct';
	public const OPT_SB_SPECIAL_MIN_QTY                 = 'akb_sb_special_min_qty';
	public const OPT_SB_SPECIAL_MAX_PCT                 = 'akb_sb_special_max_pct';
	public const OPT_SB_SHOW_COMBINED_SAVINGS_UI        = 'akb_sb_show_combined_savings_ui';

	// Pestaña 3: Puntos & Lealtad (Sincronizado con Akibara Rewards)
	public const OPT_REWARDS_EARN_ENABLED               = 'akb_rewards_earn_enabled';
	public const OPT_REWARDS_EARN_POINTS                = 'akb_rewards_earn_points';
	public const OPT_REWARDS_EARN_AMOUNT                = 'akb_rewards_earn_amount';
	public const OPT_REWARDS_REDEEM_MIN_CART            = 'akb_rewards_redeem_min_cart';
	public const OPT_REWARDS_REDEEM_TIER1_MAX_AMOUNT    = 'akb_rewards_redeem_tier1_max_amount';
	public const OPT_REWARDS_REDEEM_TIER2_MIN_CART      = 'akb_rewards_redeem_tier2_min_cart';
	public const OPT_REWARDS_REDEEM_MAX_AMOUNT          = 'akb_rewards_redeem_max_amount';
	public const OPT_REWARDS_AWARD_STATUS               = 'akb_rewards_award_status';
	public const OPT_REWARDS_MANUAL_SWITCH_BELOW_BUNDLE = 'akb_rewards_allow_manual_switch_below_bundle';

	// Pestaña 4: Bienvenida & Referidos
	public const OPT_REFERRAL_GUEST_DISCOUNT_AMOUNT     = 'akb_referral_guest_discount_amount';
	public const OPT_REFERRAL_MIN_CART_AMOUNT           = 'akb_referral_min_cart_amount';
	public const OPT_REWARDS_REFERRAL_POINTS            = 'akb_rewards_referral_points';
	public const OPT_REFERRAL_ALLOW_BACKORDER_ITEMS     = 'akb_referral_allow_backorder_items';
	public const OPT_REFERRAL_AUTO_APPLY_PRIORITY       = 'akb_referral_auto_apply_priority';

	// Pestaña 5: Transferencias (BACS) y Logística
	public const OPT_FSP_ENABLED                        = 'akibara_fsp_enabled';
	public const OPT_FSP_THRESHOLD                      = 'akibara_fsp_carrier_threshold';
	public const OPT_FSP_IGNORE_BUNDLE_DISCOUNTS        = 'akb_fsp_ignore_bundle_discounts';
	public const OPT_MIXED_CART_SINGLE_SHIPMENT_POLICY  = 'akb_mixed_cart_single_shipment_policy';
	public const OPT_SB_CLAWBACK_ON_PARTIAL_REFUND      = 'akb_sb_clawback_on_partial_refund';
	public const OPT_BACS_AUTO_CANCEL_ENABLED           = 'akb_bacs_auto_cancel_enabled';
	public const OPT_BACS_REMINDER_HOURS                = 'akb_bacs_reminder_hours';
	public const OPT_BACS_EXPIRY_HOURS                  = 'akb_bacs_expiry_hours';
	public const OPT_BACS_RECEIPT_GRACE_HOURS           = 'akb_bacs_receipt_grace_hours';

	// -------------------------------------------------------------------------
	// Pestaña 1 Getters & Setters
	// -------------------------------------------------------------------------

	public static function discount_max_pct(): int {
		return (int) get_option( self::OPT_DISCOUNT_MAX_PCT, 15 );
	}

	public static function backorder_discount_enabled(): bool {
		return 'yes' === get_option( self::OPT_BACKORDER_DISCOUNT_ENABLED, 'yes' );
	}

	public static function backorder_discount_pct(): int {
		$val = (int) get_option( self::OPT_BACKORDER_DISCOUNT_PCT, 5 );
		return min( max( 0, $val ), self::discount_max_pct() );
	}

	public static function taxonomy_discount_enabled(): bool {
		return 'yes' === get_option( self::OPT_TAXONOMY_DISCOUNT_ENABLED, 'no' );
	}

	public static function taxonomy_discount_coupon_mode(): string {
		$mode = (string) get_option( self::OPT_TAXONOMY_DISCOUNT_COUPON_MODE, 'best_wins' );
		return in_array( $mode, array( 'best_wins', 'whitelist_only', 'strict_block' ), true ) ? $mode : 'best_wins';
	}

	// -------------------------------------------------------------------------
	// Pestaña 2 Getters & Setters («Arma tu Pack»)
	// -------------------------------------------------------------------------

	public static function series_bundles_enabled(): bool {
		return 'yes' === get_option( self::OPT_SERIES_BUNDLES_ENABLED, 'yes' );
	}

	public static function series_bundles_byp(): array {
		$raw = (string) get_option( self::OPT_SERIES_BUNDLES_BYP, '{}' );
		if ( '' === $raw ) {
			return array();
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	public static function save_series_bundles_byp( array $byp_map ): void {
		update_option( self::OPT_SERIES_BUNDLES_BYP, wp_json_encode( $byp_map ), false );
	}

	public static function series_bundles_backorder_mode(): string {
		$mode = (string) get_option( self::OPT_SERIES_BUNDLES_BACKORDER_MODE, 'best_wins_topup' );
		return in_array( $mode, array( 'best_wins_topup', 'count_only_bridge', 'exclude_backorder' ), true ) ? $mode : 'best_wins_topup';
	}

	public static function sb_tier0_min_qty(): int {
		return max( 3, (int) get_option( self::OPT_SB_TIER0_MIN_QTY, 3 ) );
	}

	public static function sb_tier0_pct(): int {
		$val = (int) get_option( self::OPT_SB_TIER0_PCT, 0 );
		return min( max( 0, $val ), self::discount_max_pct() );
	}

	public static function sb_tier1_min_qty(): int {
		return max( 3, (int) get_option( self::OPT_SB_TIER1_MIN_QTY, 5 ) );
	}

	public static function sb_tier1_pct(): int {
		$val = (int) get_option( self::OPT_SB_TIER1_PCT, 8 );
		return min( max( 1, $val ), self::discount_max_pct() );
	}

	public static function sb_tier2_min_qty(): int {
		return max( self::sb_tier1_min_qty() + 1, (int) get_option( self::OPT_SB_TIER2_MIN_QTY, 7 ) );
	}

	public static function sb_tier2_pct(): int {
		$val = (int) get_option( self::OPT_SB_TIER2_PCT, 10 );
		return min( max( self::sb_tier1_pct(), $val ), self::discount_max_pct() );
	}

	public static function sb_special_min_qty(): int {
		return max( 3, (int) get_option( self::OPT_SB_SPECIAL_MIN_QTY, 5 ) );
	}

	public static function sb_special_max_pct(): int {
		$val = (int) get_option( self::OPT_SB_SPECIAL_MAX_PCT, 8 );
		return min( max( 1, $val ), self::discount_max_pct() );
	}

	public static function sb_show_combined_savings_ui(): bool {
		return 'yes' === get_option( self::OPT_SB_SHOW_COMBINED_SAVINGS_UI, 'yes' );
	}

	// -------------------------------------------------------------------------
	// Pestaña 3 Getters & Setters (Puntos & Lealtad)
	// -------------------------------------------------------------------------

	public static function rewards_redeem_min_cart(): int {
		return max( 0, (int) get_option( self::OPT_REWARDS_REDEEM_MIN_CART, 25000 ) );
	}

	public static function rewards_redeem_tier1_max_amount(): int {
		return max( 0, (int) get_option( self::OPT_REWARDS_REDEEM_TIER1_MAX_AMOUNT, 2000 ) );
	}

	public static function rewards_redeem_tier2_min_cart(): int {
		return max( self::rewards_redeem_min_cart(), (int) get_option( self::OPT_REWARDS_REDEEM_TIER2_MIN_CART, 50000 ) );
	}

	public static function rewards_redeem_max_amount(): int {
		return max( self::rewards_redeem_tier1_max_amount(), (int) get_option( self::OPT_REWARDS_REDEEM_MAX_AMOUNT, 5000 ) );
	}

	public static function rewards_award_status(): string {
		return (string) get_option( self::OPT_REWARDS_AWARD_STATUS, 'processing' );
	}

	public static function rewards_allow_manual_switch_below_bundle(): bool {
		return 'yes' === get_option( self::OPT_REWARDS_MANUAL_SWITCH_BELOW_BUNDLE, 'yes' );
	}

	// -------------------------------------------------------------------------
	// Pestaña 4 Getters & Setters (Bienvenida & Referidos)
	// -------------------------------------------------------------------------

	public static function referral_guest_discount_amount(): int {
		return max( 0, (int) get_option( self::OPT_REFERRAL_GUEST_DISCOUNT_AMOUNT, 2000 ) );
	}

	public static function referral_min_cart_amount(): int {
		return max( 0, (int) get_option( self::OPT_REFERRAL_MIN_CART_AMOUNT, 30000 ) );
	}

	public static function referral_points(): int {
		return max( 0, (int) get_option( self::OPT_REWARDS_REFERRAL_POINTS, 1000 ) );
	}

	public static function referral_allow_backorder_items(): bool {
		return 'yes' === get_option( self::OPT_REFERRAL_ALLOW_BACKORDER_ITEMS, 'yes' );
	}

	public static function referral_auto_apply_priority(): int {
		return (int) get_option( self::OPT_REFERRAL_AUTO_APPLY_PRIORITY, 1100 );
	}

	// -------------------------------------------------------------------------
	// Pestaña 5 Getters & Setters (BACS y Logística)
	// -------------------------------------------------------------------------

	public static function fsp_ignore_bundle_discounts(): bool {
		return 'yes' === get_option( self::OPT_FSP_IGNORE_BUNDLE_DISCOUNTS, 'no' );
	}

	public static function mixed_cart_single_shipment_policy(): bool {
		return 'yes' === get_option( self::OPT_MIXED_CART_SINGLE_SHIPMENT_POLICY, 'yes' );
	}

	public static function sb_clawback_on_partial_refund(): bool {
		return 'yes' === get_option( self::OPT_SB_CLAWBACK_ON_PARTIAL_REFUND, 'yes' );
	}

	public static function bacs_auto_cancel_enabled(): bool {
		return 'yes' === get_option( self::OPT_BACS_AUTO_CANCEL_ENABLED, 'yes' );
	}

	public static function bacs_reminder_hours(): int {
		return max( 1, (int) get_option( self::OPT_BACS_REMINDER_HOURS, 12 ) );
	}

	public static function bacs_expiry_hours(): int {
		return max( 2, (int) get_option( self::OPT_BACS_EXPIRY_HOURS, 24 ) );
	}

	public static function bacs_receipt_grace_hours(): int {
		return max( 1, (int) get_option( self::OPT_BACS_RECEIPT_GRACE_HOURS, 48 ) );
	}
}
