<?php
/** Account-specific points management linked from WooCommerce Customers.
 *
 * @package Akibara\Rewards
 */
declare( strict_types = 1 );

namespace Akibara\Rewards\Points;

use Akibara\Rewards\Support\Schema;

defined( 'ABSPATH' ) || exit;

/** A customer detail, not another customer directory. */
final class CustomerPointsPage {

	public const SLUG = 'akb-customer-points';
	private const PAGE_SIZE = 20;

	/** Register the detail screen in the WooCommerce permission context. */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ), 99 );
	}

	/** Register a hidden detail page reached from the Customers report. */
	public function add_page(): void {
		// An empty parent registers admin_page_{slug}; adding then removing a
		// WooCommerce submenu leaves a hook WordPress can no longer resolve.
		add_submenu_page( '', 'Gestionar puntos', 'Gestionar puntos', 'manage_woocommerce', self::SLUG, array( $this, 'render' ) );
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
		echo '<div class="wrap"><h1>Gestionar puntos</h1><p><a href="' . esc_url( admin_url( 'admin.php?page=wc-admin&path=%2Fcustomers' ) ) . '">← Volver a Clientes</a></p>';
		if ( ! $user instanceof \WP_User ) {
			echo '<div class="notice notice-warning inline"><p>Este cliente no tiene una cuenta disponible. No se puede ajustar un saldo sin cuenta.</p></div></div>';
			return;
		}
		$balance = Ledger::balance( $user_id );
		$held    = Redemption::held_in_unpaid_orders( $user_id );
		echo '<h2>' . esc_html( (string) $user->display_name ) . '</h2><p>' . esc_html( (string) $user->user_email ) . ' · Cuenta #' . esc_html( (string) $user_id ) . '</p>';
		echo '<p><strong>Saldo disponible: ' . esc_html( number_format_i18n( $balance ) ) . ' puntos</strong>.</p>';
		if ( $held > 0 ) {
			echo '<p class="description">Además hay ' . esc_html( number_format_i18n( $held ) ) . ' puntos apartados en pedidos pendientes, ya descontados de este saldo.</p>';
		}
		( new AdminAdjust() )->render_box( $user_id );
		$history = $this->history( $user_id, $page );
		echo '<h2>Historial de puntos</h2><p>' . esc_html( number_format_i18n( $history['total'] ) ) . ' movimientos · Página ' . esc_html( (string) $history['page'] ) . ' de ' . esc_html( (string) $history['pages'] ) . '.</p>';
		if ( ! $history['rows'] ) {
			echo '<p>Esta cuenta aún no tiene movimientos de puntos.</p></div>';
			return;
		}
		echo '<div style="overflow:auto" tabindex="0" role="region" aria-label="Historial de puntos"><table class="widefat striped"><thead><tr><th scope="col">Fecha</th><th scope="col">Movimiento</th><th scope="col">Saldo tras movimiento</th><th scope="col">Motivo</th></tr></thead><tbody>';
		foreach ( $history['rows'] as $row ) {
			$delta = (int) $row['delta'];
			echo '<tr><td>' . esc_html( (string) $row['created_at'] ) . '</td><td>' . esc_html( ( $delta > 0 ? '+' : '' ) . number_format_i18n( $delta ) ) . '</td><td>' . esc_html( number_format_i18n( (int) $row['balance_after'] ) ) . '</td><td>' . esc_html( (string) $row['note'] ) . '</td></tr>';
		}
		echo '</tbody></table></div><p>';
		if ( $history['page'] > 1 ) {
			echo '<a class="button" href="' . esc_url( self::url( $user_id, $history['page'] - 1 ) ) . '">← Movimientos más recientes</a> ';
		}
		if ( $history['page'] < $history['pages'] ) {
			echo '<a class="button" href="' . esc_url( self::url( $user_id, $history['page'] + 1 ) ) . '">Movimientos anteriores →</a>';
		}
		echo '</p></div>';
	}
}
