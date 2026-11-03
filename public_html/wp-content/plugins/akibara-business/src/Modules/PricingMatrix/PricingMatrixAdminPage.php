<?php
/**
 * Panel de Administración de Akibara Pricing & Backorder Matrix.
 *
 * Incluye:
 * 1. Pestaña Matriz de Escalas por Editorial (con ordenamiento dinámico, calculadora CLP en vivo,
 *    checkbox de blindaje de órdenes activas por defecto en true, Dry-Run y Aplicación).
 * 2. Pestaña Auditoría de Series (`pa_serie`) para nivelar discrepancias en 1 clic.
 * 3. Pestaña Rescate Seguro de Agotados (`outofstock` -> `onbackorder` excluyendo bloqueados).
 * 4. Pestaña Exportar / Importar Reglas JSON (Local <-> Producción) & Auditoría de Órdenes (🔒).
 *
 * @package Akibara\Business\Modules\PricingMatrix
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\PricingMatrix;

defined( 'ABSPATH' ) || exit;

final class PricingMatrixAdminPage {

	public const MENU_SLUG          = 'akibara-pricing-matrix';
	public const ACTION_APPLY_SCALE = 'akb_pricing_matrix_apply_scale';
	public const ACTION_SYNC_BO     = 'akb_pricing_matrix_sync_backorders';
	public const ACTION_LEVEL_SERIE = 'akb_pricing_matrix_level_series';
	public const ACTION_RESCUE_OOS  = 'akb_pricing_matrix_rescue_oos';
	public const ACTION_IMPORT_JSON = 'akb_pricing_matrix_import_json';
	public const ACTION_EXPORT_JSON = 'akb_pricing_matrix_export_json';
	public const NONCE_ACTION       = 'akb_pricing_matrix_nonce';

	/**
	 * Registra el submenú en el panel de Akibara y los handlers admin-post.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 62 );
		add_action( 'admin_post_' . self::ACTION_APPLY_SCALE, array( $this, 'handle_apply_scale' ) );
		add_action( 'admin_post_' . self::ACTION_SYNC_BO, array( $this, 'handle_sync_backorders' ) );
		add_action( 'admin_post_' . self::ACTION_LEVEL_SERIE, array( $this, 'handle_level_series' ) );
		add_action( 'admin_post_' . self::ACTION_RESCUE_OOS, array( $this, 'handle_rescue_oos' ) );
		add_action( 'admin_post_' . self::ACTION_IMPORT_JSON, array( $this, 'handle_import_json' ) );
		add_action( 'admin_post_' . self::ACTION_EXPORT_JSON, array( $this, 'handle_export_json' ) );
	}

	/**
	 * Registra la página dentro del menú de Akibara.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		add_submenu_page(
			'akibara-panel',
			'Matriz de Precios & Escalas A Pedido',
			'Matriz de Precios',
			'manage_woocommerce',
			self::MENU_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Procesa la simulación (Dry-Run) o aplicación de una escala editorial.
	 *
	 * @return void
	 */
	public function handle_apply_scale(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'No autorizado.' );
		}
		check_admin_referer( self::NONCE_ACTION );

		$brand_id       = isset( $_POST['brand_id'] ) ? absint( $_POST['brand_id'] ) : 0;
		$editorial_slug = isset( $_POST['editorial_slug'] ) ? sanitize_title( (string) wp_unslash( $_POST['editorial_slug'] ) ) : '';
		$scale_regular  = isset( $_POST['scale_regular'] ) ? absint( $_POST['scale_regular'] ) : 0;
		$new_regular    = isset( $_POST['new_regular'] ) ? absint( $_POST['new_regular'] ) : $scale_regular;
		$discount_pct   = isset( $_POST['discount_pct'] ) ? (float) sanitize_text_field( (string) wp_unslash( $_POST['discount_pct'] ) ) : PricingMath::DEFAULT_DISCOUNT_PCT;
		$rounding_mode  = isset( $_POST['rounding_mode'] ) ? sanitize_key( (string) wp_unslash( $_POST['rounding_mode'] ) ) : PricingMath::DEFAULT_MODE;
		$manual_raw     = isset( $_POST['manual_sale'] ) ? trim( sanitize_text_field( (string) wp_unslash( $_POST['manual_sale'] ) ) ) : '';
		$manual_sale    = '' !== $manual_raw ? absint( $manual_raw ) : null;

		$protect_orders = ! empty( $_POST['protect_active_orders'] );
		$dry_run        = ! empty( $_POST['dry_run'] );

		$summary = PricingSynchronizer::sync_scale(
			$brand_id,
			$editorial_slug,
			$scale_regular,
			$new_regular,
			$discount_pct,
			$rounding_mode,
			$manual_sale,
			$protect_orders,
			$dry_run
		);

		set_transient( 'akb_pricing_matrix_last_result_' . get_current_user_id(), $summary, 300 );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'   => self::MENU_SLUG,
					'tab'    => 'scales',
					'notice' => $dry_run ? 'dry_run_done' : 'scale_applied',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Procesa la sincronización global de descuentos A Pedido (`round_100`).
	 *
	 * @return void
	 */
	public function handle_sync_backorders(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'No autorizado.' );
		}
		check_admin_referer( self::NONCE_ACTION );

		$protect_orders = ! empty( $_POST['protect_active_orders'] );
		$dry_run        = ! empty( $_POST['dry_run'] );
		$brand_id       = isset( $_POST['brand_id'] ) && absint( $_POST['brand_id'] ) > 0 ? absint( $_POST['brand_id'] ) : null;

		$summary = PricingSynchronizer::sync_all_backorders( $protect_orders, $dry_run, $brand_id );
		set_transient( 'akb_pricing_matrix_last_result_' . get_current_user_id(), $summary, 300 );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'   => self::MENU_SLUG,
					'tab'    => 'scales',
					'notice' => $dry_run ? 'dry_run_done' : 'backorders_synced',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Nivela los tomos de una serie con discrepancia de precios.
	 *
	 * @return void
	 */
	public function handle_level_series(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'No autorizado.' );
		}
		check_admin_referer( self::NONCE_ACTION );

		$serie_id       = isset( $_POST['serie_id'] ) ? absint( $_POST['serie_id'] ) : 0;
		$brand_id       = isset( $_POST['brand_id'] ) ? absint( $_POST['brand_id'] ) : 0;
		$target_regular = isset( $_POST['target_regular'] ) ? absint( $_POST['target_regular'] ) : 0;
		$protect_orders = ! empty( $_POST['protect_active_orders'] );
		$dry_run        = ! empty( $_POST['dry_run'] );

		$summary = PricingSynchronizer::level_series_scale( $serie_id, $brand_id, $target_regular, $protect_orders, $dry_run );
		set_transient( 'akb_pricing_matrix_last_result_' . get_current_user_id(), $summary, 300 );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'   => self::MENU_SLUG,
					'tab'    => 'series',
					'notice' => $dry_run ? 'dry_run_done' : 'series_leveled',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Rescata productos seleccionados de `outofstock` a `onbackorder`.
	 *
	 * @return void
	 */
	public function handle_rescue_oos(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'No autorizado.' );
		}
		check_admin_referer( self::NONCE_ACTION );

		$raw_ids = isset( $_POST['product_ids'] ) && is_array( $_POST['product_ids'] )
			? array_map( 'absint', wp_unslash( $_POST['product_ids'] ) )
			: array();
		$dry_run = ! empty( $_POST['dry_run'] );

		$summary = PricingSynchronizer::rescue_outofstock_products( $raw_ids, $dry_run );
		set_transient( 'akb_pricing_matrix_last_result_' . get_current_user_id(), $summary, 300 );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'   => self::MENU_SLUG,
					'tab'    => 'rescue',
					'notice' => $dry_run ? 'dry_run_done' : 'oos_rescued',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Importa reglas JSON desde el formulario de administración.
	 *
	 * @return void
	 */
	public function handle_import_json(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'No autorizado.' );
		}
		check_admin_referer( self::NONCE_ACTION );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON validado y saneado por PricingRulesRepository::import_json().
		$json = isset( $_POST['rules_json'] ) ? (string) wp_unslash( $_POST['rules_json'] ) : '';
		$res  = PricingRulesRepository::import_json( $json );

		set_transient( 'akb_pricing_matrix_last_result_' . get_current_user_id(), $res, 300 );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'   => self::MENU_SLUG,
					'tab'    => 'rules',
					'notice' => $res['ok'] ? 'rules_imported' : 'rules_import_error',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Descarga las reglas actuales como archivo `.json`.
	 *
	 * @return void
	 */
	public function handle_export_json(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'No autorizado.' );
		}
		check_admin_referer( self::NONCE_ACTION );

		$json     = PricingRulesRepository::export_json();
		$filename = 'akb-pricing-matrix-rules-' . gmdate( 'Ymd-His' ) . '.json';

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON de descarga administrativa.
		exit;
	}

	/**
	 * Renderiza la interfaz administrativa de la Matriz de Precios y Backorders.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'No autorizado.' );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lectura de pestaña activa.
		$active_tab  = isset( $_GET['tab'] ) ? sanitize_key( (string) wp_unslash( $_GET['tab'] ) ) : 'scales';
		$last_result = get_transient( 'akb_pricing_matrix_last_result_' . get_current_user_id() );
		if ( false !== $last_result ) {
			delete_transient( 'akb_pricing_matrix_last_result_' . get_current_user_id() );
		}

		?>
		<div class="wrap akb-pricing-matrix-wrap">
			<h1>Akibara Pricing &amp; Backorder Matrix</h1>
			<p class="description">
				Gestión centralizada de escalas de precio por editorial, redondeo CLP sin decimales (<code>round_100</code> al 5% por defecto),
				auditoría de series y blindaje estricto de productos comprometidos en órdenes activas.
			</p>

			<?php
			if ( is_array( $last_result ) ) {
				$this->render_last_result_notice( $last_result );
			}
			$this->render_nav_tabs( $active_tab );

			if ( 'series' === $active_tab ) {
				$this->render_tab_series( ScaleScanner::scan_series_discrepancies() );
			} elseif ( 'rescue' === $active_tab ) {
				$this->render_tab_rescue( ScaleScanner::scan_rescuable_outofstock() );
			} elseif ( 'orders' === $active_tab ) {
				$this->render_tab_orders( OrderLockGuard::get_locked_orders_audit() );
			} elseif ( 'rules' === $active_tab ) {
				$this->render_tab_rules( PricingRulesRepository::export_json() );
			} else {
				$this->render_tab_scales( ScaleScanner::scan_editorial_scales() );
			}
			?>
		</div>
		<?php
		$this->render_inline_scripts();
	}

	/**
	 * Renderiza el aviso del resultado de la última operación.
	 *
	 * @param array<string, mixed> $last_result Resultado almacenado en transient.
	 * @return void
	 */
	private function render_last_result_notice( array $last_result ): void {
		?>
		<div class="notice notice-info is-dismissible" style="padding:12px 16px;margin:15px 0;">
			<strong>Resultado de la última operación:</strong>
			<?php if ( isset( $last_result['message'] ) ) : ?>
				<span><?php echo esc_html( (string) $last_result['message'] ); ?></span>
			<?php else : ?>
				<span>
					<?php echo ! empty( $last_result['dry_run'] ) ? '[SIMULACIÓN DRY-RUN] ' : '[APLICADO] '; ?>
					Actualizados/Afectados: <strong><?php echo (int) ( $last_result['updated_count'] ?? $last_result['rescued_count'] ?? 0 ); ?></strong> |
					Omitidos por blindaje de órdenes activas (🔒): <strong><?php echo (int) ( $last_result['skipped_locked_count'] ?? 0 ); ?></strong>
				</span>
			<?php endif; ?>

			<?php if ( ! empty( $last_result['skipped_locked_items'] ) && is_array( $last_result['skipped_locked_items'] ) ) : ?>
				<details style="margin-top:8px;">
					<summary style="cursor:pointer;font-weight:600;">Ver detalle de productos protegidos en órdenes abiertas (<?php echo count( $last_result['skipped_locked_items'] ); ?>)</summary>
					<ul style="margin:6px 0 0 18px;list-style:disc;">
						<?php foreach ( $last_result['skipped_locked_items'] as $locked_item ) : ?>
							<?php
							$pid        = (int) ( $locked_item['product_id'] ?? 0 );
							$order_list = array();
							if ( ! empty( $locked_item['orders'] ) && is_array( $locked_item['orders'] ) ) {
								foreach ( $locked_item['orders'] as $o ) {
									$order_list[] = '#' . (int) ( $o['order_id'] ?? 0 ) . ' (' . (string) ( $o['order_status'] ?? '' ) . ')';
								}
							}
							?>
							<li>
								Producto <code>#<?php echo (int) $pid; ?></code> — Comprometido en:
								<strong><?php echo esc_html( ! empty( $order_list ) ? implode( ', ', array_unique( $order_list ) ) : 'Stock < 0' ); ?></strong>
							</li>
						<?php endforeach; ?>
					</ul>
				</details>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Renderiza la barra de pestañas.
	 *
	 * @param string $active_tab Pestaña activa.
	 * @return void
	 */
	private function render_nav_tabs( string $active_tab ): void {
		?>
		<nav class="nav-tab-wrapper" style="margin-bottom:16px;">
			<a href="<?php echo esc_url( add_query_arg( array( 'page' => self::MENU_SLUG, 'tab' => 'scales' ), admin_url( 'admin.php' ) ) ); ?>" class="nav-tab <?php echo 'scales' === $active_tab ? 'nav-tab-active' : ''; ?>">1. Matriz de Escalas por Editorial</a>
			<a href="<?php echo esc_url( add_query_arg( array( 'page' => self::MENU_SLUG, 'tab' => 'series' ), admin_url( 'admin.php' ) ) ); ?>" class="nav-tab <?php echo 'series' === $active_tab ? 'nav-tab-active' : ''; ?>">2. Auditoría de Series (pa_serie)</a>
			<a href="<?php echo esc_url( add_query_arg( array( 'page' => self::MENU_SLUG, 'tab' => 'rescue' ), admin_url( 'admin.php' ) ) ); ?>" class="nav-tab <?php echo 'rescue' === $active_tab ? 'nav-tab-active' : ''; ?>">3. Rescate Seguro de Agotados</a>
			<a href="<?php echo esc_url( add_query_arg( array( 'page' => self::MENU_SLUG, 'tab' => 'orders' ), admin_url( 'admin.php' ) ) ); ?>" class="nav-tab <?php echo 'orders' === $active_tab ? 'nav-tab-active' : ''; ?>">4. Productos Comprometidos (🔒)</a>
			<a href="<?php echo esc_url( add_query_arg( array( 'page' => self::MENU_SLUG, 'tab' => 'rules' ), admin_url( 'admin.php' ) ) ); ?>" class="nav-tab <?php echo 'rules' === $active_tab ? 'nav-tab-active' : ''; ?>">5. Exportar / Importar Reglas JSON</a>
		</nav>
		<?php
	}

	/**
	 * Renderiza la pestaña 1: Matriz de Escalas por Editorial.
	 *
	 * @param list<array<string, mixed>> $scales Escalas detectadas.
	 * @return void
	 */
	private function render_tab_scales( array $scales ): void {
		?>
		<div class="card" style="max-width:100%;margin-bottom:16px;padding:14px 18px;">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SYNC_BO ); ?>" />
				<strong>Sincronización Global de Precios A Pedido (5% + Redondeo CLP):</strong>
				<label>
					<input type="checkbox" name="protect_active_orders" value="1" checked="checked" />
					Proteger productos con reservas activas en pedidos abiertos (<code>wc-processing</code> / <code>wc-on-hold</code>) 🔒
				</label>
				<button type="submit" name="dry_run" value="1" class="button">Simular Dry-Run Global</button>
				<button type="submit" class="button button-primary">Sincronizar Todos los A Pedido</button>
			</form>
		</div>

		<table class="wp-list-table widefat fixed striped" id="akb-pricing-scales-table">
			<thead>
				<tr>
					<th class="akb-sortable" data-sort="string" style="cursor:pointer;">Editorial ↕</th>
					<th class="akb-sortable" data-sort="string" style="cursor:pointer;width:65px;">País ↕</th>
					<th class="akb-sortable" data-sort="number" style="cursor:pointer;">Precio Regular Actual ↕</th>
					<th class="akb-sortable" data-sort="number" style="cursor:pointer;width:75px;">Total Tomos ↕</th>
					<th class="akb-sortable" data-sort="number" style="cursor:pointer;width:75px;">En Stock (instock) ↕</th>
					<th class="akb-sortable" data-sort="number" style="cursor:pointer;width:85px;">A Pedido (onbackorder) ↕</th>
					<th class="akb-sortable" data-sort="number" style="cursor:pointer;width:75px;">Agotados (outofstock) ↕</th>
					<th class="akb-sortable" data-sort="number" style="cursor:pointer;width:95px;">Comprometidos en Pedidos (🔒) ↕</th>
					<th class="akb-sortable" data-sort="number" style="cursor:pointer;">Series en esta escala ↕</th>
					<th style="width:390px;">Nuevo Precio Regular / Calculadora CLP A Pedido</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $scales as $row ) : ?>
					<?php $this->render_scale_row( $row ); ?>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Renderiza una fila individual de la tabla de escalas.
	 *
	 * @param array<string, mixed> $row Fila de escala.
	 * @return void
	 */
	private function render_scale_row( array $row ): void {
		?>
		<tr>
			<td data-value="<?php echo esc_attr( $row['editorial'] ); ?>">
				<strong><?php echo esc_html( $row['editorial'] ); ?></strong><br />
				<small><code><?php echo esc_html( $row['editorial_slug'] ); ?></code></small>
			</td>
			<td data-value="<?php echo esc_attr( $row['pais_codigo'] ); ?>">
				<?php echo esc_html( strtoupper( $row['pais_codigo'] ?: '—' ) ); ?>
			</td>
			<td data-value="<?php echo (int) $row['escala_precio_regular']; ?>">
				<strong>$<?php echo esc_html( number_format( (int) $row['escala_precio_regular'], 0, ',', '.' ) ); ?></strong>
				<?php if ( null !== $row['min_sale_actual'] ) : ?>
					<br /><small>Oferta actual: $<?php echo esc_html( number_format( (int) $row['min_sale_actual'], 0, ',', '.' ) ); ?></small>
				<?php endif; ?>
			</td>
			<td data-value="<?php echo (int) $row['total_tomos']; ?>"><?php echo (int) $row['total_tomos']; ?></td>
			<td data-value="<?php echo (int) $row['en_stock']; ?>"><?php echo (int) $row['en_stock']; ?></td>
			<td data-value="<?php echo (int) $row['a_pedido']; ?>"><?php echo (int) $row['a_pedido']; ?></td>
			<td data-value="<?php echo (int) $row['agotados']; ?>"><?php echo (int) $row['agotados']; ?></td>
			<td data-value="<?php echo (int) $row['comprometidos_count']; ?>">
				<?php if ( $row['comprometidos_count'] > 0 ) : ?>
					<span style="color:#b32d2e;font-weight:700;" title="<?php echo esc_attr( 'Órdenes: #' . implode( ', #', $row['comprometidos_orders'] ) ); ?>">
						🔒 <?php echo (int) $row['comprometidos_count']; ?>
					</span>
				<?php else : ?>
					<span>0</span>
				<?php endif; ?>
			</td>
			<td data-value="<?php echo (int) $row['series_count']; ?>">
				<strong><?php echo (int) $row['series_count']; ?> series</strong>
				<?php if ( ! empty( $row['series_names'] ) ) : ?>
					<div style="font-size:11px;color:#50575e;max-height:44px;overflow:auto;">
						<?php echo esc_html( implode( ', ', array_slice( $row['series_names'], 0, 8 ) ) ); ?>
						<?php if ( count( $row['series_names'] ) > 8 ) : ?>
							… (+<?php echo count( $row['series_names'] ) - 8; ?>)
						<?php endif; ?>
					</div>
				<?php endif; ?>
			</td>
			<td>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="akb-scale-row-form" style="display:flex;flex-direction:column;gap:6px;">
					<?php wp_nonce_field( self::NONCE_ACTION ); ?>
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_APPLY_SCALE ); ?>" />
					<input type="hidden" name="brand_id" value="<?php echo (int) $row['brand_id']; ?>" />
					<input type="hidden" name="editorial_slug" value="<?php echo esc_attr( $row['editorial_slug'] ); ?>" />
					<input type="hidden" name="scale_regular" value="<?php echo (int) $row['escala_precio_regular']; ?>" />

					<div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
						<label style="font-size:11px;">
							Nuevo Reg ($):<br />
							<input type="number" name="new_regular" class="akb-calc-reg" value="<?php echo (int) $row['nuevo_precio_regular']; ?>" step="100" min="100" style="width:90px;" />
						</label>
						<label style="font-size:11px;">
							% Desc:<br />
							<input type="number" name="discount_pct" class="akb-calc-pct" value="<?php echo esc_attr( (string) $row['discount_pct'] ); ?>" step="0.5" min="0" max="90" style="width:62px;" />
						</label>
						<label style="font-size:11px;">
							Redondeo:<br />
							<select name="rounding_mode" class="akb-calc-mode" style="width:105px;">
								<option value="round_100" <?php selected( $row['rounding_mode'], 'round_100' ); ?>>round_100</option>
								<option value="floor_100" <?php selected( $row['rounding_mode'], 'floor_100' ); ?>>floor_100</option>
								<option value="round_50" <?php selected( $row['rounding_mode'], 'round_50' ); ?>>round_50</option>
								<option value="manual" <?php selected( $row['rounding_mode'], 'manual' ); ?>>manual</option>
							</select>
						</label>
						<label style="font-size:11px;">
							Nuevo A Pedido ($):<br />
							<input type="number" name="manual_sale" class="akb-calc-sale" value="<?php echo (int) $row['nuevo_precio_a_pedido']; ?>" step="50" style="width:95px;font-weight:700;" />
						</label>
					</div>

					<div style="display:flex;justify-content:space-between;align-items:center;gap:6px;">
						<label style="font-size:11px;">
							<input type="checkbox" name="protect_active_orders" value="1" checked="checked" />
							Proteger reservas activas 🔒
						</label>
						<div>
							<button type="submit" name="dry_run" value="1" class="button button-small">Dry-Run</button>
							<button type="submit" class="button button-primary button-small">Aplicar</button>
						</div>
					</div>
				</form>
			</td>
		</tr>
		<?php
	}

	/**
	 * Renderiza la pestaña 2: Auditoría de Series (pa_serie).
	 *
	 * @param list<array<string, mixed>> $discrepancies Series con discrepancia.
	 * @return void
	 */
	private function render_tab_series( array $discrepancies ): void {
		?>
		<div class="card" style="max-width:100%;padding:14px 18px;">
			<h2>Auditoría de Series (<code>pa_serie</code>) con Escalas Discrepantes</h2>
			<p>Detecta series cuyos tomos estándar tienen precios regulares distintos dentro de la misma editorial (ignorando <code>_akb_variante_tomo = 'edicion_especial'</code> y packs).</p>
			<?php if ( empty( $discrepancies ) ) : ?>
				<p><strong>No se detectaron discrepancias de escala en series estándar.</strong></p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th>Editorial</th>
							<th>Serie</th>
							<th>Escalas Detectadas</th>
							<th>Tomos</th>
							<th>Protegidos (🔒)</th>
							<th>Nivelar en 1 Clic</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $discrepancies as $d ) : ?>
							<tr>
								<td><strong><?php echo esc_html( $d['editorial'] ); ?></strong></td>
								<td><strong><?php echo esc_html( $d['serie_name'] ); ?></strong> (<code><?php echo esc_html( $d['serie_slug'] ); ?></code>)</td>
								<td>
									<?php
									$parts = array();
									foreach ( $d['scales'] as $sc => $cnt ) {
										$parts[] = '$' . number_format( (int) $sc, 0, ',', '.' ) . ' (' . (int) $cnt . ' tomos)';
									}
									echo esc_html( implode( ' | ', $parts ) );
									?>
								</td>
								<td><?php echo (int) $d['total_tomos']; ?></td>
								<td><?php echo (int) $d['locked_count']; ?></td>
								<td>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:8px;align-items:center;">
										<?php wp_nonce_field( self::NONCE_ACTION ); ?>
										<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_LEVEL_SERIE ); ?>" />
										<input type="hidden" name="serie_id" value="<?php echo (int) $d['serie_id']; ?>" />
										<input type="hidden" name="brand_id" value="<?php echo (int) $d['brand_id']; ?>" />
										<input type="number" name="target_regular" value="<?php echo (int) $d['dominant_scale']; ?>" step="100" style="width:95px;" />
										<label style="font-size:11px;">
											<input type="checkbox" name="protect_active_orders" value="1" checked="checked" /> 🔒
										</label>
										<button type="submit" name="dry_run" value="1" class="button button-small">Dry-Run</button>
										<button type="submit" class="button button-primary button-small">Nivelar Serie</button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Renderiza la pestaña 3: Rescate Manual y Selectivo de Agotados.
	 *
	 * @param list<array<string, mixed>> $rescuables Productos agotados elegibles.
	 * @return void
	 */
	private function render_tab_rescue( array $rescuables ): void {
		?>
		<div class="card" style="max-width:100%;padding:14px 18px;">
			<h2>Rescate Manual y Selectivo de Agotados (<code>outofstock</code> ➔ <code>onbackorder</code>)</h2>
			<div class="notice notice-warning inline" style="margin:10px 0 14px;padding:10px 14px;">
				<strong>⚠️ Regla 15 y Regla 18 (Prohibición de Traspaso Automático):</strong>
				Cuando un tomo agota su stock físico en Santiago (<code>outofstock</code>), <strong>jamás</strong> pasa automáticamente a «A Pedido» porque también puede estar agotado o esperando reimpresión en la editorial.
				Selecciona y rescata aquí <strong>únicamente</strong> los tomos cuya disponibilidad haya sido confirmada en el catálogo/planilla vigente de la distribuidora oficial (Pujol &amp; Amadó, Candela, Milky Way, etc.). Al rescatarlos se aplicará el contrato nativo (<code>_stock_status = 'onbackorder'</code>, <code>_manage_stock = 'no'</code>, <code>_stock = null</code>, <code>_backorders = 'no'</code> y descuento del 5% <code>round_100</code>).
			</div>
			<?php if ( empty( $rescuables ) ) : ?>
				<p><strong>No hay productos agotados elegibles para rescate en este momento.</strong></p>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( self::NONCE_ACTION ); ?>
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_RESCUE_OOS ); ?>" />
					<p>
						<button type="submit" name="dry_run" value="1" class="button">Simular Rescate de Seleccionados (Dry-Run)</button>
						<button type="submit" class="button button-primary">Rescatar Seleccionados a «A Pedido» (5% round_100)</button>
					</p>
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th style="width:35px;"><input type="checkbox" onclick="document.querySelectorAll('.akb-rescue-cb').forEach(cb => cb.checked = this.checked);" /></th>
								<th>ID</th>
								<th>Producto</th>
								<th>Editorial</th>
								<th>Precio Regular</th>
								<th>Precio Reserva Propuesto (round_100)</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( array_slice( $rescuables, 0, 200 ) as $item ) : ?>
								<tr>
									<td><input type="checkbox" class="akb-rescue-cb" name="product_ids[]" value="<?php echo (int) $item['product_id']; ?>" /></td>
									<td><code>#<?php echo (int) $item['product_id']; ?></code></td>
									<td><strong><?php echo esc_html( $item['title'] ); ?></strong></td>
									<td><?php echo esc_html( $item['editorial'] ); ?></td>
									<td>$<?php echo esc_html( number_format( (int) $item['regular_price'], 0, ',', '.' ) ); ?></td>
									<td><strong>$<?php echo esc_html( number_format( (int) $item['proposed_sale_price'], 0, ',', '.' ) ); ?></strong></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Renderiza la pestaña 4: Auditoría de Productos Comprometidos en Órdenes Activas.
	 *
	 * @param list<array<string, mixed>> $locked_orders Filas de auditoría.
	 * @return void
	 */
	private function render_tab_orders( array $locked_orders ): void {
		?>
		<div class="card" style="max-width:100%;padding:14px 18px;">
			<h2>Auditoría en Tiempo Real de Productos Comprometidos en Órdenes Activas (🔒)</h2>
			<p>Estos productos tienen unidades compradas en órdenes activas y sus metadatos de stock y fechas están 100% blindados.</p>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th>Orden</th>
						<th>Estado Orden</th>
						<th>Producto ID</th>
						<th>Título</th>
						<th>Cant. Comprada</th>
						<th>Precio Pagado c/IVA</th>
						<th>Era Preventa</th>
						<th>Estado Catálogo</th>
						<th>Stock Catálogo</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $locked_orders as $lo ) : ?>
						<tr>
							<td><strong>#<?php echo (int) $lo['order_id']; ?></strong></td>
							<td><code><?php echo esc_html( (string) $lo['order_status'] ); ?></code></td>
							<td><code>#<?php echo (int) $lo['product_id']; ?></code></td>
							<td><?php echo esc_html( (string) $lo['order_item_name'] ); ?></td>
							<td><?php echo (int) $lo['qty_comprada']; ?></td>
							<td>$<?php echo esc_html( number_format( (float) $lo['precio_unitario_pagado_iva'], 0, ',', '.' ) ); ?></td>
							<td><?php echo esc_html( (string) $lo['era_preventa_al_comprar'] ); ?></td>
							<td><code><?php echo esc_html( (string) $lo['estado_actual_catalogo'] ); ?></code></td>
							<td><?php echo esc_html( '' !== (string) $lo['stock_actual_catalogo'] ? (string) $lo['stock_actual_catalogo'] : 'NULL' ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Renderiza la pestaña 5: Exportar / Importar Reglas JSON.
	 *
	 * @param string $exported_json JSON actual de reglas.
	 * @return void
	 */
	private function render_tab_rules( string $exported_json ): void {
		?>
		<div class="card" style="max-width:900px;padding:14px 18px;">
			<h2>Exportación / Importación JSON de Reglas (<code>akb_pricing_matrix_rules</code>)</h2>
			<p>Permite sincronizar las reglas configuradas en Local hacia Producción en 1 clic o vía WP-CLI (<code>wp akb-pricing rules export / import</code>).</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:18px;">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_EXPORT_JSON ); ?>" />
				<button type="submit" class="button button-secondary">Descargar Archivo .json de Reglas</button>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_IMPORT_JSON ); ?>" />
				<label for="akb-rules-json"><strong>JSON de Reglas (Editable para Importar):</strong></label><br />
				<textarea id="akb-rules-json" name="rules_json" rows="14" style="width:100%;font-family:monospace;margin-top:6px;"><?php echo esc_textarea( $exported_json ); ?></textarea>
				<p>
					<button type="submit" class="button button-primary">Importar y Guardar Reglas JSON</button>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Renderiza el script JS para la calculadora CLP en vivo y el ordenamiento de columnas.
	 *
	 * @return void
	 */
	private function render_inline_scripts(): void {
		?>
		<script>
		(function() {
			// Calculadora CLP en vivo por fila de escala
			function calcSale(reg, pct, mode, currentManual) {
				if (reg <= 0) return 0;
				if (mode === 'manual') return currentManual > 0 ? currentManual : reg;
				var clamped = Math.max(0, Math.min(95, pct));
				var raw = reg * ((100 - clamped) / 100);
				if (mode === 'floor_100') return Math.floor(raw / 100) * 100;
				if (mode === 'round_50') return Math.round(raw / 50) * 50;
				return Math.round(raw / 100) * 100;
			}

			document.querySelectorAll('.akb-scale-row-form').forEach(function(form) {
				var regInput  = form.querySelector('.akb-calc-reg');
				var pctInput  = form.querySelector('.akb-calc-pct');
				var modeInput = form.querySelector('.akb-calc-mode');
				var saleInput = form.querySelector('.akb-calc-sale');
				if (!regInput || !pctInput || !modeInput || !saleInput) return;

				function recalc() {
					var mode = modeInput.value;
					if (mode === 'manual') return;
					var reg = parseInt(regInput.value || '0', 10);
					var pct = parseFloat(pctInput.value || '5');
					saleInput.value = calcSale(reg, pct, mode, parseInt(saleInput.value || '0', 10));
				}

				regInput.addEventListener('input', recalc);
				pctInput.addEventListener('input', recalc);
				modeInput.addEventListener('change', recalc);
			});

			// Ordenamiento dinámico de columnas en la tabla de escalas
			var table = document.getElementById('akb-pricing-scales-table');
			if (table) {
				var headers = table.querySelectorAll('th.akb-sortable');
				headers.forEach(function(th, colIndex) {
					var asc = true;
					th.addEventListener('click', function() {
						var tbody = table.querySelector('tbody');
						var rows  = Array.from(tbody.querySelectorAll('tr'));
						var type  = th.getAttribute('data-sort') || 'string';
						rows.sort(function(a, b) {
							var cellA = a.children[colIndex];
							var cellB = b.children[colIndex];
							var valA  = cellA ? (cellA.getAttribute('data-value') || cellA.textContent.trim()) : '';
							var valB  = cellB ? (cellB.getAttribute('data-value') || cellB.textContent.trim()) : '';
							if (type === 'number') {
								var numA = parseFloat(valA) || 0;
								var numB = parseFloat(valB) || 0;
								return asc ? (numA - numB) : (numB - numA);
							}
							return asc ? valA.localeCompare(valB, 'es') : valB.localeCompare(valA, 'es');
						});
						asc = !asc;
						rows.forEach(function(r) { tbody.appendChild(r); });
					});
				});
			}
		})();
		</script>
		<?php
	}
}
