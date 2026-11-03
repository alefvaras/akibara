<?php
/**
 * FinancialRulesAdminPage — Panel de Administración Unificado de Reglas Financieras & Promociones (v1.1).
 *
 * Expone las 5 pestañas de configuración financiera de Akibara:
 * 1. Reglas Generales & Techos
 * 2. Arma tu Pack (Lista Blanca de Series del Dueño)
 * 3. Puntos & Lealtad
 * 4. Bienvenida & Referidos (con Calculadora de Subsidio en Vivo)
 * 5. Transferencias (BACS) & Logística
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Admin;

use Akibara\Business\Support\BusinessOptions;
use Akibara\Business\Modules\SeriesBundles\PromotionPolicy;

defined( 'ABSPATH' ) || exit;

final class FinancialRulesAdminPage {

	public const MENU_SLUG   = 'akibara-financial-rules';
	public const ACTION_SAVE = 'akb_save_financial_rules';
	public const NONCE_SAVE  = 'akb_financial_rules_save_nonce';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 60 );
		add_action( 'admin_post_' . self::ACTION_SAVE, array( $this, 'handle_save' ) );
		add_action( 'wp_ajax_akb_search_series_for_bundle', array( $this, 'ajax_search_series' ) );
		add_action( 'wp_ajax_akb_add_series_to_bundle', array( $this, 'ajax_add_series' ) );
		add_action( 'wp_ajax_akb_remove_series_from_bundle', array( $this, 'ajax_remove_series' ) );
		add_action( 'wp_ajax_akb_toggle_series_in_bundle', array( $this, 'ajax_toggle_series' ) );
	}

	public function register_menu(): void {
		add_submenu_page(
			'akibara-panel',
			'Reglas Financieras & Promociones',
			'Reglas Financieras',
			'manage_woocommerce',
			self::MENU_SLUG,
			array( $this, 'render' )
		);
	}

	public function handle_save(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'No autorizado.' );
		}

		check_admin_referer( self::NONCE_SAVE );

		$tab = sanitize_key( (string) wp_unslash( $_POST['current_tab'] ?? $_POST['tab'] ?? 'general' ) );

		// Techo Global y Pestaña 1
		if ( 'general' === $tab || isset( $_POST['save_all'] ) ) {
			if ( isset( $_POST[ BusinessOptions::OPT_DISCOUNT_MAX_PCT ] ) ) {
				$ceiling = absint( $_POST[ BusinessOptions::OPT_DISCOUNT_MAX_PCT ] );
				$ceiling = max( 5, min( 50, $ceiling ) );
				update_option( BusinessOptions::OPT_DISCOUNT_MAX_PCT, $ceiling );
			} else {
				$ceiling = BusinessOptions::discount_max_pct();
			}

			update_option( BusinessOptions::OPT_BACKORDER_DISCOUNT_ENABLED, ! empty( $_POST[ BusinessOptions::OPT_BACKORDER_DISCOUNT_ENABLED ] ) ? 'yes' : 'no' );
			$bo_pct = isset( $_POST[ BusinessOptions::OPT_BACKORDER_DISCOUNT_PCT ] ) ? absint( $_POST[ BusinessOptions::OPT_BACKORDER_DISCOUNT_PCT ] ) : BusinessOptions::backorder_discount_pct();
			update_option( BusinessOptions::OPT_BACKORDER_DISCOUNT_PCT, min( $bo_pct, $ceiling ) );

			update_option( BusinessOptions::OPT_TAXONOMY_DISCOUNT_ENABLED, ! empty( $_POST[ BusinessOptions::OPT_TAXONOMY_DISCOUNT_ENABLED ] ) ? 'yes' : 'no' );
			$coupon_mode = isset( $_POST[ BusinessOptions::OPT_TAXONOMY_DISCOUNT_COUPON_MODE ] ) ? sanitize_key( (string) wp_unslash( $_POST[ BusinessOptions::OPT_TAXONOMY_DISCOUNT_COUPON_MODE ] ) ) : BusinessOptions::taxonomy_discount_coupon_mode();
			update_option( BusinessOptions::OPT_TAXONOMY_DISCOUNT_COUPON_MODE, in_array( $coupon_mode, array( 'best_wins', 'whitelist_only', 'strict_block' ), true ) ? $coupon_mode : 'best_wins' );
		} else {
			$ceiling = max( 5, min( 50, BusinessOptions::discount_max_pct() ) );
		}

		// Pestaña 2
		if ( 'bundles' === $tab || isset( $_POST['save_all'] ) ) {
			update_option( BusinessOptions::OPT_SERIES_BUNDLES_ENABLED, ! empty( $_POST[ BusinessOptions::OPT_SERIES_BUNDLES_ENABLED ] ) ? 'yes' : 'no' );
			$bo_mode = isset( $_POST[ BusinessOptions::OPT_SERIES_BUNDLES_BACKORDER_MODE ] ) ? sanitize_key( (string) wp_unslash( $_POST[ BusinessOptions::OPT_SERIES_BUNDLES_BACKORDER_MODE ] ) ) : BusinessOptions::series_bundles_backorder_mode();
			update_option( BusinessOptions::OPT_SERIES_BUNDLES_BACKORDER_MODE, in_array( $bo_mode, array( 'best_wins_topup', 'count_only_bridge', 'exclude_backorder' ), true ) ? $bo_mode : 'best_wins_topup' );
			update_option( BusinessOptions::OPT_SERIES_BUNDLES_ALLOW_BACKORDER, 'exclude_backorder' === $bo_mode ? 'no' : 'yes' );

			$t0_min = max( 3, absint( $_POST[ BusinessOptions::OPT_SB_TIER0_MIN_QTY ] ?? BusinessOptions::sb_tier0_min_qty() ) );
			$t0_pct = min( $ceiling, max( 0, absint( $_POST[ BusinessOptions::OPT_SB_TIER0_PCT ] ?? BusinessOptions::sb_tier0_pct() ) ) );
			update_option( BusinessOptions::OPT_SB_TIER0_MIN_QTY, $t0_min );
			update_option( BusinessOptions::OPT_SB_TIER0_PCT, $t0_pct );

			$t1_min = max( 3, absint( $_POST[ BusinessOptions::OPT_SB_TIER1_MIN_QTY ] ?? BusinessOptions::sb_tier1_min_qty() ) );
			$t1_pct = min( $ceiling, max( 1, absint( $_POST[ BusinessOptions::OPT_SB_TIER1_PCT ] ?? BusinessOptions::sb_tier1_pct() ) ) );
			update_option( BusinessOptions::OPT_SB_TIER1_MIN_QTY, $t1_min );
			update_option( BusinessOptions::OPT_SB_TIER1_PCT, $t1_pct );

			$t2_min = max( $t1_min + 1, absint( $_POST[ BusinessOptions::OPT_SB_TIER2_MIN_QTY ] ?? BusinessOptions::sb_tier2_min_qty() ) );
			$t2_pct = min( $ceiling, max( $t1_pct, absint( $_POST[ BusinessOptions::OPT_SB_TIER2_PCT ] ?? BusinessOptions::sb_tier2_pct() ) ) );
			update_option( BusinessOptions::OPT_SB_TIER2_MIN_QTY, $t2_min );
			update_option( BusinessOptions::OPT_SB_TIER2_PCT, $t2_pct );

			$sp_min = max( 3, absint( $_POST[ BusinessOptions::OPT_SB_SPECIAL_MIN_QTY ] ?? BusinessOptions::sb_special_min_qty() ) );
			$sp_pct = min( $ceiling, max( 1, absint( $_POST[ BusinessOptions::OPT_SB_SPECIAL_MAX_PCT ] ?? BusinessOptions::sb_special_max_pct() ) ) );
			update_option( BusinessOptions::OPT_SB_SPECIAL_MIN_QTY, $sp_min );
			update_option( BusinessOptions::OPT_SB_SPECIAL_MAX_PCT, $sp_pct );

			update_option( BusinessOptions::OPT_SB_SHOW_COMBINED_SAVINGS_UI, ! empty( $_POST[ BusinessOptions::OPT_SB_SHOW_COMBINED_SAVINGS_UI ] ) ? 'yes' : 'no' );

			// Sincronizar los tramos persistidos de las series que usan plantillas
			$byp_map        = BusinessOptions::series_bundles_byp();
			$standard_tiers = PromotionPolicy::get_standard_template_tiers();
			$special_tiers  = PromotionPolicy::get_special_template_tiers();
			$byp_dirty      = false;
			foreach ( $byp_map as $byp_id => $byp_entry ) {
				if ( ! is_array( $byp_entry ) || empty( $byp_entry['enabled'] ) ) {
					continue;
				}
				$entry_profile = $byp_entry['profile'] ?? ( ( isset( $byp_entry['tiers'] ) && is_array( $byp_entry['tiers'] ) && 1 === count( $byp_entry['tiers'] ) ) ? 'template_special' : 'template_standard' );
				if ( 'template_standard' === $entry_profile ) {
					$byp_map[ $byp_id ]['profile'] = 'template_standard';
					$byp_map[ $byp_id ]['tiers']   = $standard_tiers;
					$byp_dirty                     = true;
				} elseif ( 'template_special' === $entry_profile ) {
					$byp_map[ $byp_id ]['profile'] = 'template_special';
					$byp_map[ $byp_id ]['tiers']   = $special_tiers;
					$byp_dirty                     = true;
				}
			}
			if ( $byp_dirty ) {
				BusinessOptions::save_series_bundles_byp( $byp_map );
			}
		}

		// Pestaña 3
		if ( 'rewards' === $tab || isset( $_POST['save_all'] ) ) {
			update_option( BusinessOptions::OPT_REWARDS_EARN_ENABLED, ! empty( $_POST[ BusinessOptions::OPT_REWARDS_EARN_ENABLED ] ) ? 'yes' : 'no' );
			update_option( BusinessOptions::OPT_REWARDS_EARN_POINTS, max( 1, absint( $_POST[ BusinessOptions::OPT_REWARDS_EARN_POINTS ] ?? 2 ) ) );
			update_option( BusinessOptions::OPT_REWARDS_EARN_AMOUNT, max( 10, absint( $_POST[ BusinessOptions::OPT_REWARDS_EARN_AMOUNT ] ?? 100 ) ) );
			update_option( BusinessOptions::OPT_REWARDS_REDEEM_MIN_CART, max( 0, absint( $_POST[ BusinessOptions::OPT_REWARDS_REDEEM_MIN_CART ] ?? 25000 ) ) );
			update_option( BusinessOptions::OPT_REWARDS_REDEEM_TIER1_MAX_AMOUNT, max( 0, absint( $_POST[ BusinessOptions::OPT_REWARDS_REDEEM_TIER1_MAX_AMOUNT ] ?? 2000 ) ) );
			update_option( BusinessOptions::OPT_REWARDS_REDEEM_TIER2_MIN_CART, max( 25000, absint( $_POST[ BusinessOptions::OPT_REWARDS_REDEEM_TIER2_MIN_CART ] ?? 50000 ) ) );
			update_option( BusinessOptions::OPT_REWARDS_REDEEM_MAX_AMOUNT, max( 2000, absint( $_POST[ BusinessOptions::OPT_REWARDS_REDEEM_MAX_AMOUNT ] ?? 5000 ) ) );
			update_option( BusinessOptions::OPT_REWARDS_AWARD_STATUS, sanitize_key( (string) ( $_POST[ BusinessOptions::OPT_REWARDS_AWARD_STATUS ] ?? 'processing' ) ) );
			update_option( BusinessOptions::OPT_REWARDS_MANUAL_SWITCH_BELOW_BUNDLE, ! empty( $_POST[ BusinessOptions::OPT_REWARDS_MANUAL_SWITCH_BELOW_BUNDLE ] ) ? 'yes' : 'no' );
		}

		// Pestaña 4
		if ( 'referrals' === $tab || isset( $_POST['save_all'] ) ) {
			update_option( BusinessOptions::OPT_REFERRAL_GUEST_DISCOUNT_AMOUNT, max( 500, absint( $_POST[ BusinessOptions::OPT_REFERRAL_GUEST_DISCOUNT_AMOUNT ] ?? BusinessOptions::referral_guest_discount_amount() ) ) );
			update_option( BusinessOptions::OPT_REFERRAL_MIN_CART_AMOUNT, max( 10000, absint( $_POST[ BusinessOptions::OPT_REFERRAL_MIN_CART_AMOUNT ] ?? BusinessOptions::referral_min_cart_amount() ) ) );
			update_option( BusinessOptions::OPT_REWARDS_REFERRAL_POINTS, max( 0, absint( $_POST[ BusinessOptions::OPT_REWARDS_REFERRAL_POINTS ] ?? BusinessOptions::referral_points() ) ) );
			update_option( BusinessOptions::OPT_REFERRAL_ALLOW_BACKORDER_ITEMS, ! empty( $_POST[ BusinessOptions::OPT_REFERRAL_ALLOW_BACKORDER_ITEMS ] ) ? 'yes' : 'no' );
			update_option( BusinessOptions::OPT_REFERRAL_AUTO_APPLY_PRIORITY, absint( $_POST[ BusinessOptions::OPT_REFERRAL_AUTO_APPLY_PRIORITY ] ?? BusinessOptions::referral_auto_apply_priority() ) );
		}

		// Pestaña 5
		if ( 'logistics' === $tab || isset( $_POST['save_all'] ) ) {
			update_option( BusinessOptions::OPT_FSP_ENABLED, ! empty( $_POST[ BusinessOptions::OPT_FSP_ENABLED ] ) ? 'yes' : 'no' );
			update_option( BusinessOptions::OPT_FSP_THRESHOLD, max( 10000, absint( $_POST[ BusinessOptions::OPT_FSP_THRESHOLD ] ?? 60000 ) ) );
			update_option( BusinessOptions::OPT_FSP_IGNORE_BUNDLE_DISCOUNTS, ! empty( $_POST[ BusinessOptions::OPT_FSP_IGNORE_BUNDLE_DISCOUNTS ] ) ? 'yes' : 'no' );
			update_option( BusinessOptions::OPT_MIXED_CART_SINGLE_SHIPMENT_POLICY, ! empty( $_POST[ BusinessOptions::OPT_MIXED_CART_SINGLE_SHIPMENT_POLICY ] ) ? 'yes' : 'no' );
			update_option( BusinessOptions::OPT_SB_CLAWBACK_ON_PARTIAL_REFUND, ! empty( $_POST[ BusinessOptions::OPT_SB_CLAWBACK_ON_PARTIAL_REFUND ] ) ? 'yes' : 'no' );
			update_option( BusinessOptions::OPT_BACS_AUTO_CANCEL_ENABLED, ! empty( $_POST[ BusinessOptions::OPT_BACS_AUTO_CANCEL_ENABLED ] ) ? 'yes' : 'no' );
			update_option( BusinessOptions::OPT_BACS_REMINDER_HOURS, max( 1, absint( $_POST[ BusinessOptions::OPT_BACS_REMINDER_HOURS ] ?? BusinessOptions::bacs_reminder_hours() ) ) );
			update_option( BusinessOptions::OPT_BACS_EXPIRY_HOURS, max( 2, absint( $_POST[ BusinessOptions::OPT_BACS_EXPIRY_HOURS ] ?? BusinessOptions::bacs_expiry_hours() ) ) );
			update_option( BusinessOptions::OPT_BACS_RECEIPT_GRACE_HOURS, max( 1, absint( $_POST[ BusinessOptions::OPT_BACS_RECEIPT_GRACE_HOURS ] ?? BusinessOptions::bacs_receipt_grace_hours() ) ) );
		}

		wp_safe_redirect( add_query_arg( array( 'page' => self::MENU_SLUG, 'tab' => $tab, 'saved' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function ajax_search_series(): void {
		check_ajax_referer( 'akb_financial_rules_ajax' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'No autorizado' ) );
		}

		$query = isset( $_GET['q'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['q'] ) ) : '';
		if ( strlen( $query ) < 2 ) {
			wp_send_json_success( array() );
		}

		$terms = get_terms( array(
			'taxonomy'   => 'pa_serie',
			'name__like' => $query,
			'hide_empty' => false,
			'number'     => 15,
		) );

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			wp_send_json_success( array() );
		}

		$results                = array();
		$byp_map                = BusinessOptions::series_bundles_byp();
		$sample_product_by_term = array();

		foreach ( $terms as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			$term_id  = (int) $term->term_id;
			$products = get_posts( array(
				'post_type'              => 'product',
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'tax_query'              => array(
					array(
						'taxonomy' => 'pa_serie',
						'field'    => 'term_id',
						'terms'    => $term_id,
					),
				),
			) );
			if ( ! empty( $products ) ) {
				$first_product = $products[0];
				$sample_id     = is_object( $first_product ) && isset( $first_product->ID ) ? (int) $first_product->ID : (int) $first_product;
				if ( $sample_id > 0 ) {
					$sample_product_by_term[ $term_id ] = $sample_id;
				}
			}
		}

		if ( array() !== $sample_product_by_term && function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( array_values( array_unique( $sample_product_by_term ) ), true, true );
		}

		foreach ( $terms as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			$term_id = (int) $term->term_id;
			$in_list = isset( $byp_map[ $term_id ] ) || isset( $byp_map[ (string) $term_id ] );

			// Buscar editorial asociada
			$editorial = 'Desconocida / Sin asignar';
			$price_ref = 9990;

			if ( isset( $sample_product_by_term[ $term_id ] ) ) {
				$p_id     = $sample_product_by_term[ $term_id ];
				$ed_terms = get_the_terms( $p_id, 'product_brand' );
				if ( ! is_array( $ed_terms ) || empty( $ed_terms ) ) {
					$ed_terms = get_the_terms( $p_id, 'pa_editorial' );
				}
				if ( is_array( $ed_terms ) && ! empty( $ed_terms ) ) {
					$editorial = (string) $ed_terms[0]->name;
				}
				$prod = wc_get_product( $p_id );
				if ( $prod instanceof \WC_Product && (float) $prod->get_regular_price() > 0 ) {
					$price_ref = (int) round( (float) $prod->get_regular_price() );
				}
			}

			$results[] = array(
				'term_id'    => $term_id,
				'name'       => (string) $term->name,
				'count'      => (int) $term->count,
				'editorial'  => $editorial,
				'price_ref'  => $price_ref,
				'in_list'    => $in_list,
				'is_planeta' => (bool) ( false !== stripos( $editorial, 'planeta' ) ),
			);
		}

		wp_send_json_success( $results );
	}

	public function ajax_add_series(): void {
		check_ajax_referer( 'akb_financial_rules_ajax' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'No autorizado' ) );
		}

		$term_id = isset( $_POST['term_id'] ) ? absint( $_POST['term_id'] ) : 0;
		$profile = isset( $_POST['profile'] ) ? sanitize_key( (string) wp_unslash( $_POST['profile'] ) ) : 'template_standard';
		$bo_mode = isset( $_POST['backorder_mode'] ) ? sanitize_key( (string) wp_unslash( $_POST['backorder_mode'] ) ) : 'inherit';

		if ( $term_id <= 0 ) {
			wp_send_json_error( array( 'message' => 'Término inválido' ) );
		}

		$byp_map = BusinessOptions::series_bundles_byp();

		if ( 'template_special' === $profile ) {
			$tiers = PromotionPolicy::get_special_template_tiers();
		} else {
			$tiers = PromotionPolicy::get_standard_template_tiers();
		}

		$byp_map[ $term_id ] = array(
			'enabled'        => true,
			'profile'        => $profile,
			'backorder_mode' => $bo_mode,
			'tiers'          => $tiers,
		);

		BusinessOptions::save_series_bundles_byp( $byp_map );

		wp_send_json_success( array( 'message' => 'Serie agregada a la Lista Blanca con éxito' ) );
	}

	public function ajax_remove_series(): void {
		check_ajax_referer( 'akb_financial_rules_ajax' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'No autorizado' ) );
		}

		$term_id = isset( $_POST['term_id'] ) ? absint( $_POST['term_id'] ) : 0;
		if ( $term_id <= 0 ) {
			wp_send_json_error( array( 'message' => 'Término inválido' ) );
		}

		$byp_map = BusinessOptions::series_bundles_byp();
		if ( isset( $byp_map[ $term_id ] ) ) {
			unset( $byp_map[ $term_id ] );
		}
		if ( isset( $byp_map[ (string) $term_id ] ) ) {
			unset( $byp_map[ (string) $term_id ] );
		}

		BusinessOptions::save_series_bundles_byp( $byp_map );

		wp_send_json_success( array( 'message' => 'Serie removida de la Lista Blanca' ) );
	}

	public function ajax_toggle_series(): void {
		check_ajax_referer( 'akb_financial_rules_ajax' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'No autorizado' ) );
		}

		$term_id = isset( $_POST['term_id'] ) ? absint( $_POST['term_id'] ) : 0;
		if ( $term_id <= 0 ) {
			wp_send_json_error( array( 'message' => 'Término inválido' ) );
		}

		$byp_map = BusinessOptions::series_bundles_byp();
		$key = isset( $byp_map[ $term_id ] ) ? $term_id : ( isset( $byp_map[ (string) $term_id ] ) ? (string) $term_id : null );

		if ( null !== $key ) {
			$current = ! empty( $byp_map[ $key ]['enabled'] );
			$byp_map[ $key ]['enabled'] = ! $current;
			BusinessOptions::save_series_bundles_byp( $byp_map );
			wp_send_json_success( array( 'enabled' => ! $current ) );
		}

		wp_send_json_error( array( 'message' => 'Serie no encontrada en la Lista Blanca' ) );
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Acceso restringido.' );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Lectura de pestaña en navegación GET.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( (string) wp_unslash( $_GET['tab'] ) ) : 'general';
		$tabs = array(
			'general'   => '1. Reglas Generales & Techos',
			'bundles'   => '2. Arma tu Pack (Lista Blanca)',
			'rewards'   => '3. Puntos & Lealtad',
			'referrals' => '4. Bienvenida & Referidos',
			'logistics' => '5. Transferencias (BACS) & Logística',
		);
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'general';
		}

		$byp_map     = BusinessOptions::series_bundles_byp();
		$ajax_nonce  = wp_create_nonce( 'akb_financial_rules_ajax' );
		$menu_slug   = self::MENU_SLUG;
		$action_save = self::ACTION_SAVE;
		$nonce_save  = self::NONCE_SAVE;

		$template = dirname( __DIR__, 2 ) . '/templates/admin/financial-rules.php';
		if ( file_exists( $template ) ) {
			include $template;
		}
	}
}
