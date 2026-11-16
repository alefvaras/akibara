<?php
/** Account-specific points management linked from WooCommerce Customers.
 *
 * @package Akibara\Rewards
 */
declare( strict_types = 1 );

namespace Akibara\Rewards\Points;

use Akibara\Rewards\Support\Dates;
use Akibara\Rewards\Support\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * A customer detail, not another customer directory. @api 
 * @psalm-suppress UnusedVariable
 */
final class CustomerPointsPage {

	public const SLUG = 'akb-customer-points';
	private const PAGE_SIZE = 20;

	/** Register the detail screen in the WooCommerce permission context. */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ), 99 );
	}

	/** Register a hidden detail page reached from the Customers report. */
	public function add_page(): void {
		global $admin_page_hooks;
		// Register under Akibara when active so get_admin_page_parent() keeps Akibara highlighted (flyout hidden via CSS);
		// otherwise fall back to an empty parent so the hook resolves without adding a WooCommerce submenu.
		$parent = is_array( $admin_page_hooks ) && isset( $admin_page_hooks['akibara-panel'] ) ? 'akibara-panel' : '';
		add_submenu_page( $parent, 'Gestionar puntos', 'Gestionar puntos', 'manage_woocommerce', self::SLUG, array( $this, 'render' ) );
	}

	/** Generate a detail URL using a WordPress account ID. */
	public static function url( int $user_id, int $page = 1 ): string {
		return admin_url( 'admin.php?page=' . self::SLUG . '&user_id=' . max( 0, $user_id ) . '&points_page=' . max( 1, $page ) );
	}

	/** Read one bounded ledger page; counts use the existing user_created index. */
	public function history( int $user_id, int $page ): array {
		if ( ! current_user_can( 'manage_woocommerce' ) || $user_id <= 0 ) {
			return array( 'rows' => array(), 'total' => 0, 'page' => 1, 'pages' => 1 );
		}
		global $wpdb;
		$table = Schema::ledger_table();
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d", $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
		$pages = max( 1, (int) ceil( $total / self::PAGE_SIZE ) );
		$page  = max( 1, min( $pages, $page ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT id, delta, balance_after, action, ref_type, ref_id, note, created_at FROM {$table} WHERE user_id = %d ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d", $user_id, self::PAGE_SIZE, ( $page - 1 ) * self::PAGE_SIZE ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
			ARRAY_A
		);
		return array( 'rows' => $rows ?? array(), 'total' => $total, 'page' => $page, 'pages' => $pages );
	}

	/** Show the selected account, adjustment form and paginated ledger. */
	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only account selection.
		$page    = isset( $_GET['points_page'] ) ? absint( $_GET['points_page'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination.
		$user    = $user_id > 0 ? get_user_by( 'id', $user_id ) : false;
		echo '<div class="wrap akb-rewards-wrap"><div class="akb-rewards-panel">';
		echo '<div class="akb-panel-top-bar"><a href="' . esc_url( admin_url( 'admin.php?page=wc-admin&path=%2Fcustomers' ) ) . '" class="akb-panel-back-btn">← Volver a Clientes</a></div>';
		echo '<header class="akb-rewards-head"><div class="akb-rewards-brand"><span class="akb-rewards-gem" aria-hidden="true">&#9670;</span> <h1>Gestionar Puntos</h1> <span class="akb-rewards-ver">Cuenta</span></div></header>';
		echo '<div class="akb-rewards-body">';
		if ( ! $user instanceof \WP_User ) {
			echo '<div class="notice notice-warning inline"><p>Este cliente no tiene una cuenta disponible. No se puede ajustar un saldo sin cuenta.</p></div></div></div></div>';
			return;
		}
		$balance = Ledger::balance( $user_id );
		$held    = Redemption::held_in_unpaid_orders( $user_id );
		echo '<div class="akb-rewards-card"><div class="akb-rewards-card__header"><h2 class="akb-rewards-card__title">' . esc_html( (string) $user->display_name ) . '</h2></div><div style="padding:20px 24px;">';
		echo '<p style="margin:0 0 10px; font-size:14px; color:#555;">' . esc_html( (string) $user->user_email ) . ' · Cuenta #' . esc_html( (string) $user_id ) . '</p>';
		echo '<p style="margin:0; font-size:18px;"><strong>Saldo disponible: <span style="color:var(--akb-admin-red); font-variant-numeric:tabular-nums;">' . esc_html( number_format_i18n( $balance ) ) . ' puntos</span></strong>.</p>';
		if ( $held > 0 ) {
			echo '<p class="description" style="margin-top:8px;">Además hay ' . esc_html( number_format_i18n( $held ) ) . ' puntos apartados en pedidos pendientes, ya descontados de este saldo.</p>';
		}
		echo '</div></div>';

		( new AdminAdjust() )->render_box( $user_id );
		$history = $this->history( $user_id, $page );
		echo '<div class="akb-rewards-card"><div class="akb-rewards-card__header"><h2 class="akb-rewards-card__title">Historial de puntos</h2></div><div style="padding:20px 24px;">';
		echo '<p style="margin:0 0 16px; color:#555;">' . esc_html( number_format_i18n( $history['total'] ) ) . ' movimientos · Página ' . esc_html( (string) $history['page'] ) . ' de ' . esc_html( (string) $history['pages'] ) . '.</p>';
		if ( ! $history['rows'] ) {
			echo '<p>Esta cuenta aún no tiene movimientos de puntos.</p></div></div></div></div></div>';
			return;
		}
		echo '<div style="overflow:auto; border:2px solid var(--akb-admin-border); border-radius:6px; box-shadow:2px 2px 0 var(--akb-admin-border);" tabindex="0" role="region" aria-label="Historial de puntos"><table class="widefat striped" style="border:0; margin:0;"><thead><tr><th scope="col" style="background:#FAFAFB; font-weight:800;">Fecha</th><th scope="col" style="background:#FAFAFB; font-weight:800;">Movimiento</th><th scope="col" style="background:#FAFAFB; font-weight:800;">Saldo tras movimiento</th><th scope="col" style="background:#FAFAFB; font-weight:800;">Motivo</th></tr></thead><tbody>';
		foreach ( $history['rows'] as $row ) {
			$delta = (int) $row['delta'];
			echo '<tr><td>' . esc_html( Dates::admin_datetime( (string) $row['created_at'] ) ) . '</td><td style="font-weight:700;font-variant-numeric:tabular-nums;' . ( $delta > 0 ? 'color:#0d6832;' : 'color:#b32d2e;' ) . '">' . esc_html( ( $delta > 0 ? '+' : '' ) . number_format_i18n( $delta ) ) . '</td><td style="font-variant-numeric:tabular-nums;">' . esc_html( number_format_i18n( (int) $row['balance_after'] ) ) . '</td><td>' . esc_html( (string) $row['note'] ) . '</td></tr>';
		}
		echo '</tbody></table></div><p style="margin-top:16px;">';
		if ( $history['page'] > 1 ) {
			echo '<a class="button button-secondary" href="' . esc_url( self::url( $user_id, $history['page'] - 1 ) ) . '">← Movimientos más recientes</a> ';
		}
		if ( $history['page'] < $history['pages'] ) {
			echo '<a class="button button-secondary" href="' . esc_url( self::url( $user_id, $history['page'] + 1 ) ) . '">Movimientos anteriores →</a>';
		}
		echo '</p></div></div></div></div></div>';
	}
}
