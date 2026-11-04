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

use Akibara\Business\Utilities\PluginAssetVersion;

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
	 * Registra el submenú en el panel de Akibara, assets y los handlers admin-post.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 62 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
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
	 * Encola los estilos propios del panel administrativo de la Matriz de Precios.
	 *
	 * @param string $hook_suffix Hook actual de la pantalla de administración.
	 * @return void
	 */
	public function enqueue_admin_assets( string $hook_suffix ): void {
		if ( false === strpos( $hook_suffix, self::MENU_SLUG ) ) {
			return;
		}

		wp_enqueue_style(
			'akb-pricing-matrix-admin',
			AKIBARA_BUSINESS_URL . 'assets/css/pricing-matrix-admin.css',
			array(),
			PluginAssetVersion::for_path( 'assets/css/pricing-matrix-admin.css' )
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

		$protect_orders = true; // Blindaje obligatorio e inviolable (Regla 18 y Auditoría 2026-11-04)
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

		$protect_orders = true; // Blindaje obligatorio e inviolable (Regla 18 y Auditoría 2026-11-04)
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
		$protect_orders = true; // Blindaje obligatorio e inviolable (Regla 18 y Auditoría 2026-11-04)
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

		$supplier  = isset( $_POST['distributor_supplier'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['distributor_supplier'] ) ) : '';
		$reference = isset( $_POST['distributor_reference'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['distributor_reference'] ) ) : '';
		$confirmed = ! empty( $_POST['confirm_distributor_stock'] );

		if ( ! $dry_run && ( '' === $supplier || '' === $reference || ! $confirmed ) ) {
			set_transient(
				'akb_pricing_matrix_last_result_' . get_current_user_id(),
				array(
					'ok'      => false,
					'message' => 'Rescate en vivo rechazado: según las Reglas 15 y 18 es obligatorio registrar la distribuidora oficial confirmada, el documento/planilla de respaldo y la confirmación expresa de stock disponible.',
				),
				300
			);

			wp_safe_redirect(
				add_query_arg(
					array(
						'page'   => self::MENU_SLUG,
						'tab'    => 'rescue',
						'notice' => 'rescue_auth_required',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		$distributor_auth = array(
			'supplier'  => $supplier,
			'reference' => $reference,
			'confirmed' => $confirmed,
		);

		try {
			$summary = PricingSynchronizer::rescue_outofstock_products( $raw_ids, $dry_run, $distributor_auth );
			set_transient( 'akb_pricing_matrix_last_result_' . get_current_user_id(), $summary, 300 );
			$notice = $dry_run ? 'dry_run_done' : 'oos_rescued';
		} catch ( \Throwable $e ) {
			set_transient(
				'akb_pricing_matrix_last_result_' . get_current_user_id(),
				array(
					'ok'      => false,
					'message' => 'Error al rescatar productos: ' . $e->getMessage(),
				),
				300
			);
			$notice = 'rescue_error';
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'   => self::MENU_SLUG,
					'tab'    => 'rescue',
					'notice' => $notice,
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

		$scales        = ScaleScanner::scan_editorial_scales();
		$discrepancies = ScaleScanner::scan_series_discrepancies();
		$rescuables    = ScaleScanner::scan_rescuable_outofstock();
		$locked_pids   = OrderLockGuard::get_locked_product_ids();
		$locked_count  = count( $locked_pids );

		?>
		<div class="wrap akb-pm-wrap">
			<div class="akb-pm-header">
				<div class="akb-pm-header__left">
					<h1 class="akb-pm-title">
						Akibara Pricing &amp; Backorder Matrix
						<span class="akb-pm-version-badge">v<?php echo defined( 'AKIBARA_BUSINESS_VERSION' ) ? esc_html( AKIBARA_BUSINESS_VERSION ) : '1.120.8'; ?></span>
					</h1>
				</div>
				<p class="akb-pm-subtitle">
					Gestión de escalas de precio por editorial, cálculo automático de reservas con 5% de descuento
					(<code>round_100</code>), nivelación de series discrepantes y blindaje estricto de productos comprometidos en pedidos activos.
				</p>
			</div>

			<?php
			if ( is_array( $last_result ) ) {
				$this->render_last_result_notice( $last_result );
			}

			$this->render_cockpit( count( $scales ), count( $discrepancies ), count( $rescuables ), $locked_count );
			$this->render_nav_tabs( $active_tab, count( $scales ), count( $discrepancies ), count( $rescuables ), $locked_count );

			if ( 'series' === $active_tab ) {
				$this->render_tab_series( $discrepancies );
			} elseif ( 'rescue' === $active_tab ) {
				$this->render_tab_rescue( $rescuables );
			} elseif ( 'orders' === $active_tab ) {
				$this->render_tab_orders( OrderLockGuard::get_locked_orders_audit() );
			} elseif ( 'rules' === $active_tab ) {
				$this->render_tab_rules( PricingRulesRepository::export_json() );
			} else {
				$this->render_tab_scales( $scales );
			}
			?>
		</div>
		<?php
		$this->render_inline_scripts();
	}

	/**
	 * Renderiza la franja de métricas ejecutivas (Cockpit).
	 *
	 * @param int $scales_count        Total escalas detectadas.
	 * @param int $discrepancies_count Series con discrepancia.
	 * @param int $rescuables_count    Agotados rescatables.
	 * @param int $locked_count        Productos protegidos en órdenes activas.
	 * @return void
	 */
	private function render_cockpit( int $scales_count, int $discrepancies_count, int $rescuables_count, int $locked_count ): void {
		?>
		<div class="akb-pm-cockpit">
			<div class="akb-pm-metric-card">
				<div class="akb-pm-metric-card__label">Escalas Editoriales</div>
				<div class="akb-pm-metric-card__val"><?php echo (int) $scales_count; ?></div>
				<div class="akb-pm-metric-card__hint">Tramos de precio por distribuidor</div>
			</div>
			<div class="akb-pm-metric-card <?php echo $discrepancies_count > 0 ? 'akb-pm-metric-card--alert' : 'akb-pm-metric-card--success'; ?>">
				<div class="akb-pm-metric-card__label">Series con Discrepancia</div>
				<div class="akb-pm-metric-card__val">
					<?php echo (int) $discrepancies_count; ?>
					<?php if ( $discrepancies_count > 0 ) : ?>
						<span style="font-size:12px;font-weight:600;color:#d63638;">(requieren nivelar)</span>
					<?php endif; ?>
				</div>
				<div class="akb-pm-metric-card__hint">Tomos con precios distintos en misma serie</div>
			</div>
			<div class="akb-pm-metric-card">
				<div class="akb-pm-metric-card__label">Agotados Rescatables</div>
				<div class="akb-pm-metric-card__val"><?php echo (int) $rescuables_count; ?></div>
				<div class="akb-pm-metric-card__hint">Sin stock local, elegibles para reserva</div>
			</div>
			<div class="akb-pm-metric-card akb-pm-metric-card--lock">
				<div class="akb-pm-metric-card__label">Productos Blindados (🔒)</div>
				<div class="akb-pm-metric-card__val"><?php echo (int) $locked_count; ?></div>
				<div class="akb-pm-metric-card__hint">Comprometidos en pedidos abiertos</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Renderiza el aviso del resultado de la última operación.
	 *
	 * @param array<string, mixed> $last_result Resultado almacenado en transient.
	 * @return void
	 */
	private function render_last_result_notice( array $last_result ): void {
		?>
		<div class="notice notice-info is-dismissible" style="padding:12px 16px;margin:15px 0;border-left-color:#2271b1;">
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
	 * Renderiza la barra de pestañas con contadores en tiempo real.
	 *
	 * @param string $active_tab         Pestaña activa.
	 * @param int    $scales_count        Total escalas detectadas.
	 * @param int    $discrepancies_count Series con discrepancia.
	 * @param int    $rescuables_count    Agotados rescatables.
	 * @param int    $locked_count        Productos protegidos en órdenes activas.
	 * @return void
	 */
	private function render_nav_tabs( string $active_tab, int $scales_count, int $discrepancies_count, int $rescuables_count, int $locked_count ): void {
		?>
		<nav class="akb-pm-nav-tabs">
			<a href="<?php echo esc_url( add_query_arg( array( 'page' => self::MENU_SLUG, 'tab' => 'scales' ), admin_url( 'admin.php' ) ) ); ?>" class="akb-pm-tab-link <?php echo 'scales' === $active_tab ? 'is-active' : ''; ?>">
				1. Matriz de Escalas por Editorial
				<span class="akb-pm-badge-count"><?php echo (int) $scales_count; ?></span>
			</a>
			<a href="<?php echo esc_url( add_query_arg( array( 'page' => self::MENU_SLUG, 'tab' => 'series' ), admin_url( 'admin.php' ) ) ); ?>" class="akb-pm-tab-link <?php echo 'series' === $active_tab ? 'is-active' : ''; ?>">
				2. Auditoría de Series (pa_serie)
				<span class="akb-pm-badge-count <?php echo $discrepancies_count > 0 ? 'akb-pm-badge-count--alert' : ''; ?>"><?php echo (int) $discrepancies_count; ?></span>
			</a>
			<a href="<?php echo esc_url( add_query_arg( array( 'page' => self::MENU_SLUG, 'tab' => 'rescue' ), admin_url( 'admin.php' ) ) ); ?>" class="akb-pm-tab-link <?php echo 'rescue' === $active_tab ? 'is-active' : ''; ?>">
				3. Rescate Seguro de Agotados
				<span class="akb-pm-badge-count"><?php echo (int) $rescuables_count; ?></span>
			</a>
			<a href="<?php echo esc_url( add_query_arg( array( 'page' => self::MENU_SLUG, 'tab' => 'orders' ), admin_url( 'admin.php' ) ) ); ?>" class="akb-pm-tab-link <?php echo 'orders' === $active_tab ? 'is-active' : ''; ?>">
				4. Productos Comprometidos (🔒)
				<span class="akb-pm-badge-count akb-pm-badge-count--lock"><?php echo (int) $locked_count; ?></span>
			</a>
			<a href="<?php echo esc_url( add_query_arg( array( 'page' => self::MENU_SLUG, 'tab' => 'rules' ), admin_url( 'admin.php' ) ) ); ?>" class="akb-pm-tab-link <?php echo 'rules' === $active_tab ? 'is-active' : ''; ?>">
				5. Exportar / Importar Reglas JSON
			</a>
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
		<div class="akb-pm-card">
			<div class="akb-pm-global-action-bar">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="akb-pm-global-action-bar__left">
					<?php wp_nonce_field( self::NONCE_ACTION ); ?>
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SYNC_BO ); ?>" />
					<strong>Sincronización Global de Precios A Pedido (5% + Redondeo CLP):</strong>
					<label class="akb-pm-lock-toggle-label" style="padding:4px 8px;">
						<input type="checkbox" name="protect_active_orders" value="1" checked="checked" />
						<span>Proteger reservas en órdenes abiertas (wc-processing / wc-on-hold) 🔒</span>
					</label>
					<button type="submit" name="dry_run" value="1" class="button akb-pm-btn-dry">Simular Dry-Run Global</button>
					<button type="submit" class="button button-primary akb-pm-btn-level">Sincronizar Todos los A Pedido</button>
				</form>
			</div>

			<div class="akb-pm-filter-toolbar">
				<input type="search" id="akb-scales-search" class="akb-pm-search-input" placeholder="🔍 Filtrar por editorial, país o serie..." autocomplete="off" />
				<span id="akb-scales-counter" style="font-size:12px;font-weight:600;color:#50575e;">
					Mostrando <strong><?php echo count( $scales ); ?></strong> escalas editoriales
				</span>
			</div>

			<div class="akb-pm-table-responsive">
				<table class="akb-pm-table" id="akb-pricing-scales-table">
					<thead>
						<tr>
							<th class="akb-sortable" data-sort="string" style="width:150px;cursor:pointer;">Editorial ↕</th>
							<th class="akb-sortable" data-sort="string" style="width:55px;text-align:center;cursor:pointer;">País ↕</th>
							<th class="akb-sortable" data-sort="number" style="width:115px;cursor:pointer;">Precio Actual ↕</th>
							<th class="akb-sortable" data-sort="number" style="width:65px;text-align:center;cursor:pointer;">Total ↕</th>
							<th class="akb-sortable" data-sort="number" style="width:65px;text-align:center;cursor:pointer;">Stock ↕</th>
							<th class="akb-sortable" data-sort="number" style="width:75px;text-align:center;cursor:pointer;">A Pedido ↕</th>
							<th class="akb-sortable" data-sort="number" style="width:70px;text-align:center;cursor:pointer;">Agotado ↕</th>
							<th class="akb-sortable" data-sort="number" style="width:90px;text-align:center;cursor:pointer;">Blindados ↕</th>
							<th style="min-width:140px;">Series en esta escala</th>
							<th style="width:380px;min-width:370px;">Nuevo Regular / Calculadora CLP A Pedido</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $scales as $row ) : ?>
							<?php $this->render_scale_row( $row ); ?>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
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
		<tr class="akb-scale-searchable-row" data-search="<?php echo esc_attr( strtolower( $row['editorial'] . ' ' . $row['editorial_slug'] . ' ' . $row['pais_codigo'] . ' ' . implode( ' ', $row['series_names'] ?? array() ) ) ); ?>">
			<td data-value="<?php echo esc_attr( $row['editorial'] ); ?>">
				<div class="akb-pm-editorial-cell">
					<span class="akb-pm-editorial-name"><?php echo esc_html( $row['editorial'] ); ?></span>
					<span class="akb-pm-slug-badge"><?php echo esc_html( $row['editorial_slug'] ); ?></span>
				</div>
			</td>
			<td data-value="<?php echo esc_attr( $row['pais_codigo'] ); ?>" style="text-align:center;">
				<span class="akb-pm-country-pill"><?php echo esc_html( strtoupper( $row['pais_codigo'] ?: '—' ) ); ?></span>
			</td>
			<td data-value="<?php echo (int) $row['escala_precio_regular']; ?>">
				<strong style="font-size:14px;color:#1d2327;">$<?php echo esc_html( number_format( (int) $row['escala_precio_regular'], 0, ',', '.' ) ); ?></strong>
				<?php if ( null !== $row['min_sale_actual'] ) : ?>
					<br /><span style="font-size:11px;color:#0d6832;font-weight:600;">Oferta: $<?php echo esc_html( number_format( (int) $row['min_sale_actual'], 0, ',', '.' ) ); ?></span>
				<?php endif; ?>
			</td>
			<td data-value="<?php echo (int) $row['total_tomos']; ?>" style="text-align:center;">
				<span class="akb-pm-number-badge"><?php echo (int) $row['total_tomos']; ?></span>
			</td>
			<td data-value="<?php echo (int) $row['en_stock']; ?>" style="text-align:center;">
				<span style="font-weight:700;color:#0d6832;"><?php echo (int) $row['en_stock']; ?></span>
			</td>
			<td data-value="<?php echo (int) $row['a_pedido']; ?>" style="text-align:center;">
				<span style="font-weight:700;color:#2271b1;"><?php echo (int) $row['a_pedido']; ?></span>
			</td>
			<td data-value="<?php echo (int) $row['agotados']; ?>" style="text-align:center;">
				<span style="color:#646970;"><?php echo (int) $row['agotados']; ?></span>
			</td>
			<td data-value="<?php echo (int) $row['comprometidos_count']; ?>" style="text-align:center;">
				<?php if ( $row['comprometidos_count'] > 0 ) : ?>
					<span class="akb-pm-lock-badge" title="<?php echo esc_attr( 'Órdenes: #' . implode( ', #', $row['comprometidos_orders'] ) ); ?>">
						🔒 <?php echo (int) $row['comprometidos_count']; ?>
					</span>
				<?php else : ?>
					<span class="akb-pm-lock-badge akb-pm-lock-badge--zero">0</span>
				<?php endif; ?>
			</td>
			<td data-value="<?php echo (int) $row['series_count']; ?>">
				<strong><?php echo (int) $row['series_count']; ?> series</strong>
				<?php if ( ! empty( $row['series_names'] ) ) : ?>
					<div style="font-size:11px;color:#50575e;max-height:42px;overflow-y:auto;margin-top:2px;">
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

					<div class="akb-pm-scale-calc-grid">
						<div class="akb-pm-calc-field">
							<label>Nuevo Reg ($)</label>
							<input type="number" name="new_regular" class="akb-calc-reg" value="<?php echo (int) $row['nuevo_precio_regular']; ?>" step="1" min="100" required />
						</div>
						<div class="akb-pm-calc-field">
							<label>% Desc</label>
							<input type="number" name="discount_pct" class="akb-calc-pct" value="<?php echo esc_attr( (string) $row['discount_pct'] ); ?>" step="0.5" min="0" max="90" required />
						</div>
						<div class="akb-pm-calc-field">
							<label>Redondeo</label>
							<select name="rounding_mode" class="akb-calc-mode">
								<option value="round_100" <?php selected( $row['rounding_mode'], 'round_100' ); ?>>round_100</option>
								<option value="floor_100" <?php selected( $row['rounding_mode'], 'floor_100' ); ?>>floor_100</option>
								<option value="round_50" <?php selected( $row['rounding_mode'], 'round_50' ); ?>>round_50</option>
								<option value="manual" <?php selected( $row['rounding_mode'], 'manual' ); ?>>manual</option>
							</select>
						</div>
						<div class="akb-pm-calc-field">
							<label>A Pedido ($)</label>
							<input type="number" name="manual_sale" class="akb-calc-sale" value="<?php echo (int) $row['nuevo_precio_a_pedido']; ?>" step="50" style="font-weight:700;color:#0d6832;" required />
						</div>
					</div>

					<div class="akb-pm-calc-footer">
						<label class="akb-pm-lock-toggle-label" title="Protege tomos comprometidos en órdenes activas">
							<input type="checkbox" name="protect_active_orders" value="1" checked="checked" />
							<span>🔒 Proteger reservas</span>
						</label>
						<div class="akb-pm-btn-group">
							<button type="submit" name="dry_run" value="1" class="button akb-pm-btn-dry" title="Simular sin modificar base de datos">Dry-Run</button>
							<button type="submit" class="button button-primary akb-pm-btn-level" title="Aplicar escala a todos los tomos">Aplicar</button>
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
		<div class="akb-pm-card">
			<h2 class="akb-pm-card__title">
				Auditoría de Series (<code>pa_serie</code>) con Escalas Discrepantes
				<span class="akb-pm-badge-count <?php echo ! empty( $discrepancies ) ? 'akb-pm-badge-count--alert' : ''; ?>">
					<?php echo count( $discrepancies ); ?>
				</span>
			</h2>
			<p class="akb-pm-card__desc">
				Detecta series cuyos tomos estándar tienen precios regulares distintos dentro de la misma editorial
				(los artbooks, packs y tomos con <code>_akb_variante_tomo = 'edicion_especial'</code> se omiten automáticamente para proteger los márgenes).
				<strong>Criterio sugerido:</strong> Nivelar al promedio ponderado o a la escala dominante para garantizar uniformidad comercial. Clic en cualquier chip para fijar el precio objetivo antes de nivelar.
			</p>

			<?php if ( empty( $discrepancies ) ) : ?>
				<div class="notice notice-success inline" style="margin:16px 0;padding:12px 16px;">
					<strong>✓ ¡Excelente! Todas las series estándar tienen precios regulares 100% consistentes.</strong>
				</div>
			<?php else : ?>
				<div class="akb-pm-filter-toolbar">
					<input type="search" id="akb-series-search" class="akb-pm-search-input" placeholder="🔍 Filtrar por serie, editorial o slug..." autocomplete="off" />
					<span id="akb-series-counter" style="font-size:12px;font-weight:600;color:#50575e;">
						Mostrando <strong><?php echo count( $discrepancies ); ?></strong> series con discrepancia
					</span>
				</div>

				<div class="akb-pm-table-responsive">
					<table class="akb-pm-table" id="akb-series-table">
						<thead>
							<tr>
								<th class="akb-sortable" data-sort="string" style="width:140px;cursor:pointer;">Editorial ↕</th>
								<th class="akb-sortable" data-sort="string" style="width:230px;cursor:pointer;">Serie ↕</th>
								<th style="min-width:280px;">Escalas Detectadas (Clic para elegir)</th>
								<th class="akb-sortable" data-sort="number" style="width:75px;text-align:center;cursor:pointer;">Tomos ↕</th>
								<th class="akb-sortable" data-sort="number" style="width:85px;text-align:center;cursor:pointer;">Protegidos ↕</th>
								<th style="width:360px;min-width:350px;">Nivelar en 1 Clic</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $discrepancies as $d ) : ?>
								<?php $row_key = (int) $d['serie_id'] . '-' . (int) $d['brand_id']; ?>
								<tr class="akb-series-searchable-row" data-search="<?php echo esc_attr( strtolower( $d['editorial'] . ' ' . $d['serie_name'] . ' ' . $d['serie_slug'] ) ); ?>">
									<td data-value="<?php echo esc_attr( $d['editorial'] ); ?>">
										<div class="akb-pm-editorial-cell">
											<span class="akb-pm-editorial-name"><?php echo esc_html( $d['editorial'] ); ?></span>
										</div>
									</td>
									<td data-value="<?php echo esc_attr( $d['serie_name'] ); ?>">
										<div class="akb-pm-serie-cell">
											<span class="akb-pm-serie-title"><?php echo esc_html( $d['serie_name'] ); ?></span>
											<span class="akb-pm-slug-badge" title="pa_serie: <?php echo esc_attr( $d['serie_slug'] ); ?>"><?php echo esc_html( $d['serie_slug'] ); ?></span>
										</div>
									</td>
									<td>
										<div class="akb-pm-scales-wrap">
											<?php if ( ! empty( $d['average_price'] ) ) : ?>
												<button type="button" class="akb-pm-scale-chip akb-pm-scale-chip--average" data-input-id="target-reg-<?php echo esc_attr( $row_key ); ?>" data-price="<?php echo (int) $d['average_price']; ?>" title="Promedio ponderado ($<?php echo number_format( (int) $d['average_price'], 0, ',', '.' ); ?>). Clic para fijar como objetivo.">
													<span>⚖ Promedio: $<?php echo esc_html( number_format( (int) $d['average_price'], 0, ',', '.' ) ); ?></span>
												</button>
											<?php endif; ?>
											<?php foreach ( $d['scales'] as $sc => $cnt ) : ?>
												<?php $is_dom = ( (int) $sc === (int) $d['dominant_scale'] ); ?>
												<button type="button" class="akb-pm-scale-chip <?php echo $is_dom ? 'akb-pm-scale-chip--dominant' : 'akb-pm-scale-chip--divergent'; ?>" data-input-id="target-reg-<?php echo esc_attr( $row_key ); ?>" data-price="<?php echo (int) $sc; ?>" title="<?php echo $is_dom ? 'Escala dominante (' . (int) $cnt . ' tomos). Clic para fijar como objetivo.' : 'Escala discrepante (' . (int) $cnt . ' tomos). Clic para fijar como objetivo.'; ?>">
													<span>$<?php echo esc_html( number_format( (int) $sc, 0, ',', '.' ) ); ?></span>
													<span class="akb-pm-chip-count">(<?php echo (int) $cnt; ?><?php echo $is_dom ? ' ★' : ''; ?>)</span>
												</button>
											<?php endforeach; ?>
										</div>
									</td>
									<td data-value="<?php echo (int) $d['total_tomos']; ?>" style="text-align:center;">
										<span class="akb-pm-number-badge"><?php echo (int) $d['total_tomos']; ?></span>
									</td>
									<td data-value="<?php echo (int) $d['locked_count']; ?>" style="text-align:center;">
										<?php if ( $d['locked_count'] > 0 ) : ?>
											<span class="akb-pm-lock-badge" title="<?php echo esc_attr( $d['locked_count'] . ' tomo(s) protegidos en órdenes activas' ); ?>">🔒 <?php echo (int) $d['locked_count']; ?></span>
										<?php else : ?>
											<span class="akb-pm-lock-badge akb-pm-lock-badge--zero">0</span>
										<?php endif; ?>
									</td>
									<td>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="akb-pm-level-form">
											<?php wp_nonce_field( self::NONCE_ACTION ); ?>
											<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_LEVEL_SERIE ); ?>" />
											<input type="hidden" name="serie_id" value="<?php echo (int) $d['serie_id']; ?>" />
											<input type="hidden" name="brand_id" value="<?php echo (int) $d['brand_id']; ?>" />
											<div class="akb-pm-input-price-wrap">
												<span class="akb-pm-price-currency">$</span>
												<input type="number" id="target-reg-<?php echo esc_attr( $row_key ); ?>" name="target_regular" class="akb-pm-input-price" value="<?php echo (int) $d['dominant_scale']; ?>" step="1" min="100" required />
											</div>
											<label class="akb-pm-lock-toggle-label" title="Proteger productos comprometidos en órdenes activas (🔒)">
												<input type="checkbox" name="protect_active_orders" value="1" checked="checked" />
												<span>🔒</span>
											</label>
											<div class="akb-pm-btn-group">
												<button type="submit" name="dry_run" value="1" class="button akb-pm-btn-dry" title="Simular sin modificar la base de datos">Dry-Run</button>
												<button type="submit" class="button button-primary akb-pm-btn-level" title="Nivelar todos los tomos estándar al precio seleccionado">Nivelar Serie</button>
											</div>
										</form>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
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
		<div class="akb-pm-card">
			<h2 class="akb-pm-card__title">Rescate Manual y Selectivo de Agotados (<code>outofstock</code> ➔ <code>onbackorder</code>)</h2>
			<div class="notice notice-warning inline" style="margin:10px 0 16px;padding:12px 16px;border-left-color:#ffd401;">
				<strong>⚠️ Regla 15 y Regla 18 (Prohibición de Traspaso Automático):</strong>
				Cuando un tomo agota su stock físico en Santiago (<code>outofstock</code>), <strong>jamás</strong> pasa automáticamente a «A Pedido» porque también puede estar agotado o esperando reimpresión en la editorial.
				Selecciona y rescata aquí <strong>únicamente</strong> los tomos cuya disponibilidad haya sido confirmada en el catálogo o planilla vigente de la distribuidora oficial (Pujol &amp; Amadó, Candela, Milky Way, etc.). Al rescatarlos se aplicará el contrato nativo (<code>_stock_status = 'onbackorder'</code>, <code>_manage_stock = 'no'</code>, <code>_stock = null</code>, <code>_backorders = 'no'</code> y descuento del 5% <code>round_100</code>).
			</div>

			<?php if ( empty( $rescuables ) ) : ?>
				<p><strong>No hay productos agotados elegibles para rescate en este momento.</strong></p>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( self::NONCE_ACTION ); ?>
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_RESCUE_OOS ); ?>" />

					<div class="akb-pm-global-action-bar">
						<div class="akb-pm-global-action-bar__left">
							<span>Tomos seleccionados: <strong id="akb-rescue-selected-count" style="font-size:15px;color:#2271b1;">0</strong></span>
						</div>
						<div class="akb-pm-global-action-bar__right">
							<button type="submit" name="dry_run" value="1" class="button akb-pm-btn-dry">Simular Rescate (Dry-Run)</button>
							<button type="submit" class="button button-primary akb-pm-btn-level">Rescatar Seleccionados a «A Pedido» (5% round_100)</button>
						</div>
					</div>

					<div class="akb-pm-rescue-auth-panel" style="background:#f0f6fc;border:1px solid #cce5ff;border-radius:6px;padding:12px 16px;margin:12px 0 16px;">
						<div style="font-weight:700;color:#0c4a6e;margin-bottom:8px;font-size:13px;">📋 Autorización Obligatoria de Distribuidor (Reglas 15 y 18 - Control de Agotados)</div>
						<div style="display:flex;gap:16px;flex-wrap:wrap;align-items:center;margin-bottom:8px;">
							<label style="font-size:12px;font-weight:600;color:#1e293b;">
								Distribuidor Oficial:
								<select name="distributor_supplier" style="margin-left:6px;font-size:12px;">
									<option value="">-- Seleccionar distribuidor confirmado --</option>
									<option value="Pujol & Amadó (Panini / Planeta)">Pujol &amp; Amadó (Panini / Planeta)</option>
									<option value="Candela (Ivrea Arg / Ovni Press)">Candela (Ivrea Arg / Ovni Press)</option>
									<option value="Milky Way Ediciones">Milky Way Ediciones</option>
									<option value="Ivrea España">Ivrea España</option>
									<option value="Otro distribuidor oficial">Otro distribuidor oficial</option>
								</select>
							</label>
							<label style="font-size:12px;font-weight:600;color:#1e293b;">
								Referencia / Planilla Oficial:
								<input type="text" name="distributor_reference" placeholder="Ej: Planilla reposición 2026-11-04" style="margin-left:6px;min-width:240px;font-size:12px;" />
							</label>
						</div>
						<label style="display:flex;align-items:center;gap:6px;font-size:12px;color:#334155;">
							<input type="checkbox" name="confirm_distributor_stock" value="1" />
							<span><strong>Confirmo disponibilidad oficial:</strong> Verifiqué que los tomos seleccionados figuran con stock disponible en la planilla del proveedor y NO están catalogados como «Agotado por reimpresión» ni descatalogados (Regla 15).</span>
						</label>
					</div>

					<div class="akb-pm-filter-toolbar">
						<input type="search" id="akb-rescue-search" class="akb-pm-search-input" placeholder="🔍 Filtrar por título, editorial o ID..." autocomplete="off" />
						<span id="akb-rescue-counter" style="font-size:12px;font-weight:600;color:#50575e;">
							Mostrando <?php echo count( $rescuables ); ?> productos agotados elegibles
						</span>
					</div>

					<div class="akb-pm-table-responsive">
						<table class="akb-pm-table" id="akb-rescue-table">
							<thead>
								<tr>
									<th style="width:36px;text-align:center;"><input type="checkbox" id="akb-rescue-select-all" title="Seleccionar todos los visibles" /></th>
									<th class="akb-sortable" data-sort="number" style="width:75px;cursor:pointer;">ID ↕</th>
									<th class="akb-sortable" data-sort="string" style="cursor:pointer;">Producto ↕</th>
									<th class="akb-sortable" data-sort="string" style="width:160px;cursor:pointer;">Editorial ↕</th>
									<th class="akb-sortable" data-sort="number" style="width:120px;cursor:pointer;">Precio Regular ↕</th>
									<th class="akb-sortable" data-sort="number" style="width:170px;cursor:pointer;">Precio Reserva (round_100) ↕</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( array_slice( $rescuables, 0, 300 ) as $item ) : ?>
									<tr class="akb-rescue-searchable-row" data-search="<?php echo esc_attr( strtolower( $item['title'] . ' ' . $item['editorial'] . ' ' . $item['product_id'] ) ); ?>">
										<td style="text-align:center;">
											<input type="checkbox" class="akb-rescue-cb" name="product_ids[]" value="<?php echo (int) $item['product_id']; ?>" />
										</td>
										<td data-value="<?php echo (int) $item['product_id']; ?>">
											<a href="<?php echo esc_url( admin_url( 'post.php?post=' . (int) $item['product_id'] . '&action=edit' ) ); ?>" target="_blank" style="text-decoration:none;">
												<code>#<?php echo (int) $item['product_id']; ?></code>
											</a>
										</td>
										<td data-value="<?php echo esc_attr( $item['title'] ); ?>">
											<strong><?php echo esc_html( $item['title'] ); ?></strong>
										</td>
										<td data-value="<?php echo esc_attr( $item['editorial'] ); ?>">
											<?php echo esc_html( $item['editorial'] ); ?>
										</td>
										<td data-value="<?php echo (int) $item['regular_price']; ?>">
											$<?php echo esc_html( number_format( (int) $item['regular_price'], 0, ',', '.' ) ); ?>
										</td>
										<td data-value="<?php echo (int) $item['proposed_sale_price']; ?>">
											<strong style="color:#0d6832;font-size:14px;">$<?php echo esc_html( number_format( (int) $item['proposed_sale_price'], 0, ',', '.' ) ); ?></strong>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
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
		<div class="akb-pm-card">
			<h2 class="akb-pm-card__title">
				Auditoría en Tiempo Real de Productos Comprometidos en Órdenes Activas (🔒)
				<span class="akb-pm-badge-count akb-pm-badge-count--lock"><?php echo count( $locked_orders ); ?></span>
			</h2>
			<p class="akb-pm-card__desc">
				Estos productos tienen unidades compradas en órdenes abiertas (<code>wc-processing</code>, <code>wc-on-hold</code>) y sus
				metadatos de stock, precios congelados y fechas están 100% blindados contra mutaciones automáticas de catálogo.
			</p>

			<div class="akb-pm-filter-toolbar">
				<input type="search" id="akb-orders-search" class="akb-pm-search-input" placeholder="🔍 Filtrar por orden, ID o título..." autocomplete="off" />
				<span id="akb-orders-counter" style="font-size:12px;font-weight:600;color:#50575e;">
					Mostrando <?php echo count( $locked_orders ); ?> productos comprometidos
				</span>
			</div>

			<div class="akb-pm-table-responsive">
				<table class="akb-pm-table" id="akb-orders-table">
					<thead>
						<tr>
							<th class="akb-sortable" data-sort="number" style="width:85px;cursor:pointer;">Orden ↕</th>
							<th class="akb-sortable" data-sort="string" style="width:125px;cursor:pointer;">Estado Orden ↕</th>
							<th class="akb-sortable" data-sort="number" style="width:80px;cursor:pointer;">Prod ID ↕</th>
							<th class="akb-sortable" data-sort="string" style="cursor:pointer;">Título Tomo ↕</th>
							<th class="akb-sortable" data-sort="number" style="width:65px;text-align:center;cursor:pointer;">Cant. ↕</th>
							<th class="akb-sortable" data-sort="number" style="width:110px;cursor:pointer;">Precio Pagado ↕</th>
							<th class="akb-sortable" data-sort="string" style="width:95px;cursor:pointer;">Era Preventa ↕</th>
							<th class="akb-sortable" data-sort="string" style="width:120px;cursor:pointer;">Estado Catálogo ↕</th>
							<th class="akb-sortable" data-sort="string" style="width:90px;text-align:center;cursor:pointer;">Stock Actual ↕</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $locked_orders as $lo ) : ?>
							<tr class="akb-orders-searchable-row" data-search="<?php echo esc_attr( strtolower( (string) $lo['order_id'] . ' ' . (string) $lo['product_id'] . ' ' . (string) $lo['order_item_name'] . ' ' . (string) $lo['order_status'] ) ); ?>">
								<td data-value="<?php echo (int) $lo['order_id']; ?>">
									<a href="<?php echo esc_url( admin_url( 'post.php?post=' . (int) $lo['order_id'] . '&action=edit' ) ); ?>" target="_blank" style="text-decoration:none;">
										<strong>#<?php echo (int) $lo['order_id']; ?></strong>
									</a>
								</td>
								<td data-value="<?php echo esc_attr( (string) $lo['order_status'] ); ?>">
									<span class="akb-pm-country-pill" style="<?php echo 'wc-processing' === (string) $lo['order_status'] ? 'background:#e7f5ea;color:#0d6832;' : 'background:#fff3cd;color:#856404;'; ?>">
										<?php echo esc_html( (string) $lo['order_status'] ); ?>
									</span>
								</td>
								<td data-value="<?php echo (int) $lo['product_id']; ?>">
									<a href="<?php echo esc_url( admin_url( 'post.php?post=' . (int) $lo['product_id'] . '&action=edit' ) ); ?>" target="_blank" style="text-decoration:none;">
										<code>#<?php echo (int) $lo['product_id']; ?></code>
									</a>
								</td>
								<td data-value="<?php echo esc_attr( (string) $lo['order_item_name'] ); ?>">
									<?php echo esc_html( (string) $lo['order_item_name'] ); ?>
								</td>
								<td data-value="<?php echo (int) $lo['qty_comprada']; ?>" style="text-align:center;">
									<span class="akb-pm-number-badge"><?php echo (int) $lo['qty_comprada']; ?></span>
								</td>
								<td data-value="<?php echo (float) $lo['precio_unitario_pagado_iva']; ?>">
									$<?php echo esc_html( number_format( (float) $lo['precio_unitario_pagado_iva'], 0, ',', '.' ) ); ?>
								</td>
								<td data-value="<?php echo esc_attr( (string) $lo['era_preventa_al_comprar'] ); ?>">
									<?php echo esc_html( (string) $lo['era_preventa_al_comprar'] ); ?>
								</td>
								<td data-value="<?php echo esc_attr( (string) $lo['estado_actual_catalogo'] ); ?>">
									<code><?php echo esc_html( (string) $lo['estado_actual_catalogo'] ); ?></code>
								</td>
								<td data-value="<?php echo esc_attr( (string) $lo['stock_actual_catalogo'] ); ?>" style="text-align:center;">
									<?php echo esc_html( '' !== (string) $lo['stock_actual_catalogo'] ? (string) $lo['stock_actual_catalogo'] : 'NULL' ); ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
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
		<div class="akb-pm-card" style="max-width:960px;">
			<h2 class="akb-pm-card__title">Exportación / Importación JSON de Reglas (<code>akb_pricing_matrix_rules</code>)</h2>
			<p class="akb-pm-card__desc">
				Permite sincronizar las reglas maestras de precios configuradas entre entornos (Local <-> Staging <-> Producción)
				en 1 clic desde el navegador o vía WP-CLI (<code>wp akb-pricing rules export / import</code>).
			</p>

			<div style="display:flex;gap:10px;align-items:center;margin-bottom:18px;">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0;">
					<?php wp_nonce_field( self::NONCE_ACTION ); ?>
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_EXPORT_JSON ); ?>" />
					<button type="submit" class="button button-secondary">📥 Descargar Archivo .json de Reglas</button>
				</form>
				<button type="button" id="akb-copy-rules-btn" class="button">📋 Copiar al Portapapeles</button>
				<span id="akb-copy-feedback" style="display:none;color:#0d6832;font-weight:700;font-size:12px;">¡Copiado con éxito!</span>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_IMPORT_JSON ); ?>" />
				<label for="akb-rules-json"><strong>JSON de Reglas (Editable para Importar):</strong></label><br />
				<textarea id="akb-rules-json" name="rules_json" rows="14" style="width:100%;font-family:Consolas, Monaco, monospace;font-size:12px;margin:8px 0;padding:12px;background:#f8f9fa;border:1px solid #ccd0d4;border-radius:4px;"><?php echo esc_textarea( $exported_json ); ?></textarea>
				<p style="margin-top:12px;">
					<button type="submit" class="button button-primary">💾 Importar y Guardar Reglas JSON</button>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Renderiza los scripts interactivos del panel (filtros en vivo, chips click-to-fill, calculadora y ordenamiento).
	 *
	 * @return void
	 */
	private function render_inline_scripts(): void {
		?>
		<script>
		(function() {
			// 1. Clic en Chips de Escalas Detectadas para fijar el precio objetivo de nivelación
			document.querySelectorAll('.akb-pm-scale-chip').forEach(function(chip) {
				chip.addEventListener('click', function(e) {
					e.preventDefault();
					var inputId = chip.getAttribute('data-input-id');
					var price   = chip.getAttribute('data-price');
					if (inputId && price) {
						var targetInput = document.getElementById(inputId);
						if (targetInput) {
							targetInput.value = price;
							targetInput.focus();
							targetInput.style.outline = '2px solid #2271b1';
							setTimeout(function() { targetInput.style.outline = ''; }, 600);
						}
					}
				});
			});

			// 2. Filtro en vivo para la tabla de Series Discrepantes
			var seriesInput = document.getElementById('akb-series-search');
			var seriesTable = document.getElementById('akb-series-table');
			if (seriesInput && seriesTable) {
				var seriesRows    = Array.from(seriesTable.querySelectorAll('tbody tr.akb-series-searchable-row'));
				var seriesCounter = document.getElementById('akb-series-counter');
				seriesInput.addEventListener('input', function() {
					var query = seriesInput.value.toLowerCase().trim();
					var visible = 0;
					seriesRows.forEach(function(row) {
						var text = row.getAttribute('data-search') || row.textContent.toLowerCase();
						if (!query || text.indexOf(query) !== -1) {
							row.style.display = '';
							visible++;
						} else {
							row.style.display = 'none';
						}
					});
					if (seriesCounter) {
						seriesCounter.innerHTML = 'Mostrando <strong>' + visible + '</strong> de ' + seriesRows.length + ' series';
					}
				});
			}

			// 3. Filtro en vivo para la tabla de Escalas Editoriales
			var scalesInput = document.getElementById('akb-scales-search');
			var scalesTable = document.getElementById('akb-pricing-scales-table');
			if (scalesInput && scalesTable) {
				var scalesRows    = Array.from(scalesTable.querySelectorAll('tbody tr.akb-scale-searchable-row'));
				var scalesCounter = document.getElementById('akb-scales-counter');
				scalesInput.addEventListener('input', function() {
					var query = scalesInput.value.toLowerCase().trim();
					var visible = 0;
					scalesRows.forEach(function(row) {
						var text = row.getAttribute('data-search') || row.textContent.toLowerCase();
						if (!query || text.indexOf(query) !== -1) {
							row.style.display = '';
							visible++;
						} else {
							row.style.display = 'none';
						}
					});
					if (scalesCounter) {
						scalesCounter.innerHTML = 'Mostrando <strong>' + visible + '</strong> de ' + scalesRows.length + ' escalas';
					}
				});
			}

			// 4. Filtro en vivo y contador de selección para la tabla de Rescate
			var rescueInput = document.getElementById('akb-rescue-search');
			var rescueTable = document.getElementById('akb-rescue-table');
			if (rescueInput && rescueTable) {
				var rescueRows    = Array.from(rescueTable.querySelectorAll('tbody tr.akb-rescue-searchable-row'));
				var rescueCounter = document.getElementById('akb-rescue-counter');
				rescueInput.addEventListener('input', function() {
					var query = rescueInput.value.toLowerCase().trim();
					var visible = 0;
					rescueRows.forEach(function(row) {
						var text = row.getAttribute('data-search') || row.textContent.toLowerCase();
						if (!query || text.indexOf(query) !== -1) {
							row.style.display = '';
							visible++;
						} else {
							row.style.display = 'none';
						}
					});
					if (rescueCounter) {
						rescueCounter.innerHTML = 'Mostrando <strong>' + visible + '</strong> de ' + rescueRows.length + ' productos';
					}
				});
			}

			var selectAllCb   = document.getElementById('akb-rescue-select-all');
			var rescueCbs     = document.querySelectorAll('.akb-rescue-cb');
			var countSelected = document.getElementById('akb-rescue-selected-count');
			function updateRescueSelected() {
				var count = 0;
				rescueCbs.forEach(function(cb) { if (cb.checked) count++; });
				if (countSelected) countSelected.textContent = String(count);
			}
			if (selectAllCb) {
				selectAllCb.addEventListener('change', function() {
					var check = selectAllCb.checked;
					rescueCbs.forEach(function(cb) {
						var tr = cb.closest('tr');
						if (!tr || tr.style.display !== 'none') {
							cb.checked = check;
						}
					});
					updateRescueSelected();
				});
			}
			rescueCbs.forEach(function(cb) {
				cb.addEventListener('change', updateRescueSelected);
			});

			// 5. Filtro en vivo para la tabla de Órdenes Comprometidas
			var ordersInput = document.getElementById('akb-orders-search');
			var ordersTable = document.getElementById('akb-orders-table');
			if (ordersInput && ordersTable) {
				var ordersRows    = Array.from(ordersTable.querySelectorAll('tbody tr.akb-orders-searchable-row'));
				var ordersCounter = document.getElementById('akb-orders-counter');
				ordersInput.addEventListener('input', function() {
					var query = ordersInput.value.toLowerCase().trim();
					var visible = 0;
					ordersRows.forEach(function(row) {
						var text = row.getAttribute('data-search') || row.textContent.toLowerCase();
						if (!query || text.indexOf(query) !== -1) {
							row.style.display = '';
							visible++;
						} else {
							row.style.display = 'none';
						}
					});
					if (ordersCounter) {
						ordersCounter.innerHTML = 'Mostrando <strong>' + visible + '</strong> de ' + ordersRows.length + ' productos';
					}
				});
			}

			// 6. Copiar Reglas JSON al Portapapeles
			var copyBtn      = document.getElementById('akb-copy-rules-btn');
			var copyFeedback = document.getElementById('akb-copy-feedback');
			var rulesText    = document.getElementById('akb-rules-json');
			if (copyBtn && rulesText) {
				copyBtn.addEventListener('click', function() {
					navigator.clipboard.writeText(rulesText.value).then(function() {
						if (copyFeedback) {
							copyFeedback.style.display = 'inline';
							setTimeout(function() { copyFeedback.style.display = 'none'; }, 2000);
						}
					});
				});
			}

			// 7. Calculadora CLP en vivo por fila de escala
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

			// 8. Ordenamiento dinámico de columnas en tablas
			document.querySelectorAll('.akb-sortable').forEach(function(th) {
				var table = th.closest('table');
				if (!table) return;
				var asc = true;
				th.addEventListener('click', function() {
					var colIndex = Array.from(th.parentNode.children).indexOf(th);
					var tbody = table.querySelector('tbody');
					if (!tbody) return;
					var rows = Array.from(tbody.querySelectorAll('tr'));
					var type = th.getAttribute('data-sort') || 'string';
					rows.sort(function(a, b) {
						var cellA = a.children[colIndex];
						var cellB = b.children[colIndex];
						var valA  = cellA ? (cellA.getAttribute('data-value') || cellA.textContent.trim()) : '';
						var valB  = cellB ? (cellB.getAttribute('data-value') || cellB.textContent.trim()) : '';
						if (type === 'number') {
							var numA = parseFloat(valA.replace(/[^0-9.-]/g, '')) || 0;
							var numB = parseFloat(valB.replace(/[^0-9.-]/g, '')) || 0;
							return asc ? (numA - numB) : (numB - numA);
						}
						return asc ? valA.localeCompare(valB, 'es') : valB.localeCompare(valA, 'es');
					});
					asc = !asc;
					rows.forEach(function(r) { tbody.appendChild(r); });
				});
			});
		})();
		</script>
		<?php
	}
}
