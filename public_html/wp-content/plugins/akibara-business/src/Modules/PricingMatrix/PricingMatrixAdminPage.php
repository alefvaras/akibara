<?php
/**
 * Panel de Administración de Akibara Pricing & Backorder Matrix.
 *
 * Incluye:
 * 1. Pestaña Matriz de Escalas por Editorial (con ordenamiento dinámico, calculadora CLP en vivo,
 *    selector de alcance por serie individual, detección de discrepancias y aplicación ágil).
 * 2. Pestaña Rescate Seguro de Agotados (`outofstock` -> `onbackorder` bajo validación de distribuidor).
 *
 * @package Akibara\Business\Modules\PricingMatrix
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\PricingMatrix;

use Akibara\Business\Utilities\PluginAssetVersion;

defined( 'ABSPATH' ) || exit;

final class PricingMatrixAdminPage {

	public const MENU_SLUG               = 'akibara-pricing-matrix';
	public const ACTION_APPLY_SCALE      = 'akb_pricing_matrix_apply_scale';
	public const ACTION_APPLY_SCALE_AJAX = 'akb_pricing_matrix_apply_scale_ajax';
	public const ACTION_DISCARD_PROPOSAL = 'akb_pricing_matrix_discard_proposal';
	public const ACTION_SYNC_BO          = 'akb_pricing_matrix_sync_backorders';
	public const ACTION_LEVEL_SERIE      = 'akb_pricing_matrix_level_series';
	public const ACTION_RESCUE_OOS       = 'akb_pricing_matrix_rescue_oos';
	public const NONCE_ACTION            = 'akb_pricing_matrix_nonce';

	/**
	 * Registra el submenú en el panel de Akibara, assets y los handlers admin-post.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 62 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'admin_post_' . self::ACTION_APPLY_SCALE, array( $this, 'handle_apply_scale' ) );
		add_action( 'wp_ajax_' . self::ACTION_APPLY_SCALE_AJAX, array( $this, 'handle_apply_scale_ajax' ) );
		add_action( 'admin_post_' . self::ACTION_DISCARD_PROPOSAL, array( $this, 'handle_discard_proposal' ) );
		add_action( 'admin_post_' . self::ACTION_SYNC_BO, array( $this, 'handle_sync_backorders' ) );
		add_action( 'admin_post_' . self::ACTION_LEVEL_SERIE, array( $this, 'handle_level_series' ) );
		add_action( 'admin_post_' . self::ACTION_RESCUE_OOS, array( $this, 'handle_rescue_oos' ) );
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
	 * Descarta la propuesta simulada activa y limpia el transient de sesión del dueño.
	 *
	 * @return void
	 */
	public function handle_discard_proposal(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'No autorizado.' );
		}
		check_admin_referer( self::NONCE_ACTION );

		delete_transient( 'akb_pricing_matrix_last_result_' . get_current_user_id() );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => self::MENU_SLUG,
					'tab'  => 'scales',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
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
		$serie_id       = isset( $_POST['serie_id'] ) ? absint( $_POST['serie_id'] ) : 0;

		$protect_orders = ! empty( $_POST['protect_active_orders'] ) && '1' === (string) $_POST['protect_active_orders'];
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
			$dry_run,
			$serie_id
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
	 * Procesa la aplicación rápida de una escala o serie vía AJAX (patrón ATUM Stock Central).
	 *
	 * @return void
	 */
	public function handle_apply_scale_ajax(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'No autorizado.' ) );
		}
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$brand_id       = isset( $_POST['brand_id'] ) ? absint( $_POST['brand_id'] ) : 0;
		$editorial_slug = isset( $_POST['editorial_slug'] ) ? sanitize_title( (string) wp_unslash( $_POST['editorial_slug'] ) ) : '';
		$scale_regular  = isset( $_POST['scale_regular'] ) ? absint( $_POST['scale_regular'] ) : 0;
		$new_regular    = isset( $_POST['new_regular'] ) ? absint( $_POST['new_regular'] ) : $scale_regular;
		$discount_pct   = isset( $_POST['discount_pct'] ) ? (float) $_POST['discount_pct'] : PricingMath::DEFAULT_DISCOUNT_PCT;
		$rounding_mode  = isset( $_POST['rounding_mode'] ) ? sanitize_key( (string) wp_unslash( $_POST['rounding_mode'] ) ) : PricingMath::DEFAULT_MODE;
		$serie_id       = isset( $_POST['serie_id'] ) ? absint( $_POST['serie_id'] ) : 0;

		if ( $new_regular <= 0 || $scale_regular <= 0 || ( 0 === $brand_id && '' === $editorial_slug ) ) {
			wp_send_json_error( array( 'message' => 'Parámetros de escala inválidos.' ) );
		}

		$protect_orders = ! empty( $_POST['protect_active_orders'] ) && '1' === (string) $_POST['protect_active_orders'];
		$manual_raw     = isset( $_POST['manual_sale'] ) ? trim( sanitize_text_field( (string) wp_unslash( $_POST['manual_sale'] ) ) ) : '';
		$manual_sale    = '' !== $manual_raw ? absint( $manual_raw ) : null;

		$res = PricingSynchronizer::sync_scale(
			$brand_id,
			$editorial_slug,
			$scale_regular,
			$new_regular,
			$discount_pct,
			$rounding_mode,
			$manual_sale,
			$protect_orders,
			false, // dry_run = false
			$serie_id
		);

		$target_sale = (int) ( $res['target_sale'] ?? PricingMath::calculate_sale_price( $new_regular, $discount_pct, $rounding_mode, $manual_sale ) );

		delete_transient( 'akb_pricing_matrix_last_result_' . get_current_user_id() );

		wp_send_json_success( array(
			'message'         => sprintf( 'Escala aplicada con éxito: %d tomos actualizados.', $res['updated_count'] ),
			'updated_count'   => $res['updated_count'],
			'unchanged_count' => $res['unchanged_count'],
			'new_regular'     => $new_regular,
			'new_sale'        => $target_sale,
			'new_regular_fmt' => '$' . number_format( $new_regular, 0, ',', '.' ),
			'new_sale_fmt'    => '$' . number_format( $target_sale, 0, ',', '.' ),
			'scale_regular'   => $scale_regular,
			'serie_id'        => $serie_id,
		) );
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

		$protect_orders = ! empty( $_POST['protect_active_orders'] ) && '1' === (string) $_POST['protect_active_orders'];
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
		$protect_orders = ! empty( $_POST['protect_active_orders'] ) && '1' === (string) $_POST['protect_active_orders'];
		$dry_run        = ! empty( $_POST['dry_run'] );

		$summary = PricingSynchronizer::level_series_scale( $serie_id, $brand_id, $target_regular, $protect_orders, $dry_run );
		set_transient( 'akb_pricing_matrix_last_result_' . get_current_user_id(), $summary, 300 );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'   => self::MENU_SLUG,
					'tab'    => 'scales',
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

		$rescue_discount_pct = isset( $_POST['rescue_discount_pct'] ) && is_numeric( $_POST['rescue_discount_pct'] )
			? (float) $_POST['rescue_discount_pct']
			: PricingMath::DEFAULT_DISCOUNT_PCT;

		$rescue_rounding_mode = isset( $_POST['rescue_rounding_mode'] )
			? PricingMath::normalize_mode( sanitize_key( (string) wp_unslash( $_POST['rescue_rounding_mode'] ) ) )
			: PricingMath::DEFAULT_MODE;

		$individual_overrides = array();
		if ( isset( $_POST['individual_sale'] ) && is_array( $_POST['individual_sale'] ) ) {
			foreach ( $_POST['individual_sale'] as $pid => $man_val ) {
				$pid_clean = absint( $pid );
				$val_str   = trim( (string) wp_unslash( $man_val ) );
				if ( $pid_clean > 0 && '' !== $val_str && is_numeric( $val_str ) ) {
					$individual_overrides[ $pid_clean ] = array(
						'sale_price' => absint( $val_str ),
					);
				}
			}
		}

		try {
			$summary = PricingSynchronizer::rescue_outofstock_products(
				$raw_ids,
				$dry_run,
				$distributor_auth,
				$rescue_discount_pct,
				$rescue_rounding_mode,
				$individual_overrides
			);
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
		if ( 'rescue' !== $active_tab ) {
			$active_tab = 'scales';
		}
		$last_result = get_transient( 'akb_pricing_matrix_last_result_' . get_current_user_id() );
		if ( false !== $last_result ) {
			delete_transient( 'akb_pricing_matrix_last_result_' . get_current_user_id() );
		}

		$scales        = ScaleScanner::scan_editorial_scales();
		$discrepancies = ScaleScanner::scan_series_discrepancies();
		$rescuables    = ScaleScanner::scan_rescuable_outofstock();

		?>
		<div class="wrap akb-pm-wrap">
			<div class="akb-pm-header">
				<div class="akb-pm-header__left">
					<h1 class="akb-pm-title">
						Akibara Pricing &amp; Backorder Matrix
						<span class="akb-pm-version-badge">v<?php echo defined( 'AKIBARA_BUSINESS_VERSION' ) ? esc_html( AKIBARA_BUSINESS_VERSION ) : '1.120.20'; ?></span>
					</h1>
				</div>
				<p class="akb-pm-subtitle">
					Gestión de escalas de precio por editorial, cálculo automático de reservas con 5% de descuento
					(<code>round_100</code>) y nivelación ágil de series.
				</p>
			</div>

			<?php
			if ( is_array( $last_result ) ) {
				$this->render_last_result_notice( $last_result );
			}

			$this->render_cockpit( count( $scales ), count( $discrepancies ), count( $rescuables ) );
			$this->render_nav_tabs( $active_tab, count( $scales ), count( $rescuables ) );

			if ( 'rescue' === $active_tab ) {
				$this->render_tab_rescue( $rescuables );
			} else {
				$this->render_tab_scales( $scales, is_array( $last_result ) ? $last_result : null, $discrepancies );
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
	 * @return void
	 */
	private function render_cockpit( int $scales_count, int $discrepancies_count, int $rescuables_count ): void {
		?>
		<div class="akb-pm-cockpit">
			<div class="akb-pm-metric-card">
				<div class="akb-pm-metric-card__label">Escalas Editoriales</div>
				<div class="akb-pm-metric-card__val"><?php echo (int) $scales_count; ?></div>
				<div class="akb-pm-metric-card__hint">Tramos de precio por distribuidor</div>
			</div>
			<div class="akb-pm-metric-card <?php echo $discrepancies_count > 0 ? 'akb-pm-metric-card--alert' : 'akb-pm-metric-card--success'; ?>" <?php echo $discrepancies_count > 0 ? 'onclick="var el=document.getElementById(\'akb-discrepancies-section\');if(el){el.scrollIntoView({behavior:\'smooth\'});}" style="cursor:pointer;"' : ''; ?>>
				<div class="akb-pm-metric-card__label">Series con Discrepancia</div>
				<div class="akb-pm-metric-card__val">
					<?php echo (int) $discrepancies_count; ?>
					<?php if ( $discrepancies_count > 0 ) : ?>
						<span style="font-size:12px;font-weight:600;color:#d63638;">(clic para resolver)</span>
					<?php else : ?>
						<span style="font-size:12px;font-weight:600;color:#0d6832;">✓ Todas consistentes</span>
					<?php endif; ?>
				</div>
				<div class="akb-pm-metric-card__hint">Tomos con precios distintos en misma serie</div>
			</div>
			<div class="akb-pm-metric-card" onclick="window.location.href='<?php echo esc_url( add_query_arg( array( 'page' => self::MENU_SLUG, 'tab' => 'rescue' ), admin_url( 'admin.php' ) ) ); ?>';" style="cursor:pointer;">
				<div class="akb-pm-metric-card__label">Agotados Rescatables</div>
				<div class="akb-pm-metric-card__val"><?php echo (int) $rescuables_count; ?></div>
				<div class="akb-pm-metric-card__hint">Sin stock local, elegibles para reserva ➔</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Renderiza el aviso o panel de revisión persistente del resultado de la última operación.
	 *
	 * @param array<string, mixed> $last_result Resultado almacenado en transient.
	 * @return void
	 */
	private function render_last_result_notice( array $last_result ): void {
		$is_dry_run = ! empty( $last_result['dry_run'] );
		$is_scale   = isset( $last_result['scale_regular'], $last_result['new_regular'] );

		if ( $is_dry_run && $is_scale ) :
			$affected_count = (int) ( $last_result['updated_count'] ?? 0 );
			$unchanged      = (int) ( $last_result['unchanged_count'] ?? 0 );
			$serie_id       = (int) ( $last_result['serie_id'] ?? 0 );
			$items          = (array) ( $last_result['items'] ?? array() );

			// Detectar series únicas en los items simulados
			$series_in_items = array();
			foreach ( $items as $it ) {
				$s_id   = (int) ( $it['serie_id'] ?? 0 );
				$s_name = (string) ( $it['serie_name'] ?? '' );
				if ( $s_id > 0 && '' !== $s_name ) {
					if ( ! isset( $series_in_items[ $s_id ] ) ) {
						$series_in_items[ $s_id ] = array(
							'id'    => $s_id,
							'name'  => $s_name,
							'count' => 0,
						);
					}
					$series_in_items[ $s_id ]['count']++;
				}
			}
			uasort(
				$series_in_items,
				static function( array $a, array $b ): int {
					return strcasecmp( (string) $a['name'], (string) $b['name'] );
				}
			);

			$scoped_serie_name = '';
			if ( $serie_id > 0 && isset( $series_in_items[ $serie_id ] ) ) {
				$scoped_serie_name = $series_in_items[ $serie_id ]['name'];
			}
			?>
			<div class="akb-pm-proposal-review" style="background:#f0f6fc;border:2px solid #2271b1;border-radius:8px;padding:16px 20px;margin:18px 0 24px;box-shadow:0 2px 6px rgba(0,0,0,0.06);">
				<div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:16px;">
					<div>
						<div style="display:inline-block;background:#2271b1;color:#fff;font-size:11px;font-weight:700;padding:2px 8px;border-radius:10px;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:6px;">
							Propuesta Simulada (Listo para Aplicar)
						</div>
						<h3 style="margin:2px 0 6px 0;font-size:17px;color:#1d2327;">
							Revisión de Escala para <strong><?php echo esc_html( strtoupper( (string) ( $last_result['editorial_slug'] ?? '' ) ) ); ?></strong>
							<?php if ( '' !== $scoped_serie_name ) : ?>
								— Serie: <span style="color:#2271b1;">«<?php echo esc_html( $scoped_serie_name ); ?>»</span>
							<?php endif; ?>
						</h3>
						<p style="margin:0;font-size:13px;color:#2c3338;line-height:1.5;">
							Precio Regular Base: <strong>$<?php echo number_format( (int) $last_result['scale_regular'], 0, ',', '.' ); ?></strong> ➔
							Nuevo Regular: <strong style="color:#2271b1;font-size:15px;">$<?php echo number_format( (int) $last_result['new_regular'], 0, ',', '.' ); ?></strong> |
							Nuevo A Pedido (5% desc. redondeado a $100): <strong style="color:#008a20;font-size:15px;">$<?php echo number_format( (int) ( $last_result['target_sale'] ?? 0 ), 0, ',', '.' ); ?></strong>
						</p>
						<p style="margin:6px 0 0;font-size:12px;color:#50575e;">
							Tomos que cambiarán en catálogo: <strong style="color:#2271b1;font-size:13px;" id="akb-proposal-apply-count"><?php echo (int) $affected_count; ?></strong> |
							Sin cambios: <strong><?php echo (int) $unchanged; ?></strong>
						</p>
					</div>

					<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0;">
							<?php wp_nonce_field( self::NONCE_ACTION ); ?>
							<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_DISCARD_PROPOSAL ); ?>" />
							<button type="submit" class="button button-secondary" style="font-size:13px;padding:7px 14px;height:auto;" title="Descarta esta propuesta simulada y limpia la vista">
								✕ Descartar propuesta
							</button>
						</form>

						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="akb-pm-apply-form" style="margin:0;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
							<?php wp_nonce_field( self::NONCE_ACTION ); ?>
							<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_APPLY_SCALE ); ?>" />
							<input type="hidden" name="brand_id" value="<?php echo (int) ( $last_result['brand_id'] ?? 0 ); ?>" />
							<input type="hidden" name="editorial_slug" value="<?php echo esc_attr( (string) ( $last_result['editorial_slug'] ?? '' ) ); ?>" />
							<input type="hidden" name="scale_regular" value="<?php echo (int) $last_result['scale_regular']; ?>" />
							<input type="hidden" name="new_regular" value="<?php echo (int) $last_result['new_regular']; ?>" />
							<input type="hidden" name="discount_pct" value="<?php echo esc_attr( (string) ( $last_result['discount_pct'] ?? PricingMath::DEFAULT_DISCOUNT_PCT ) ); ?>" />
							<input type="hidden" name="rounding_mode" value="<?php echo esc_attr( (string) ( $last_result['rounding_mode'] ?? PricingMath::DEFAULT_MODE ) ); ?>" />
							<input type="hidden" name="manual_sale" value="<?php echo (int) ( $last_result['target_sale'] ?? 0 ); ?>" />
							<input type="hidden" name="serie_id" id="akb-proposal-form-serie-id" value="<?php echo (int) $serie_id; ?>" />
							<input type="hidden" name="protect_active_orders" value="0" />
							<button type="submit" class="button button-primary button-hero akb-btn-apply-submit" style="font-size:14px;padding:8px 20px;height:auto;font-weight:700;">
								Confirmar y Aplicar a los <span id="akb-proposal-btn-count"><?php echo (int) $affected_count; ?></span> tomos revisados
							</button>
						</form>
					</div>
				</div>

				<?php if ( ! empty( $items ) ) : ?>
					<details class="akb-proposal-details" style="margin-top:14px;background:#ffffff;border:1px solid #c3c4c7;border-radius:6px;padding:12px 16px;" open>
						<summary style="cursor:pointer;font-weight:600;color:#2271b1;font-size:13px;display:flex;justify-content:space-between;align-items:center;">
							<span>📋 Lista de tomos afectados (con precios antes y después)</span>
							<span style="font-size:11px;color:#646970;font-weight:normal;">Clic para contraer/expandir</span>
						</summary>
						<div style="margin-top:12px;padding-top:10px;border-top:1px solid #f0f0f1;">
							<div style="display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:10px;flex-wrap:wrap;">
								<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
									<input type="search" id="akb-proposal-search" placeholder="🔍 Filtrar tomos por nombre o ID..." style="min-width:280px;font-size:12px;padding:5px 8px;border-radius:4px;border:1px solid #8c8f94;" />
									<?php if ( count( $series_in_items ) > 1 && 0 === $serie_id ) : ?>
										<select id="akb-proposal-serie-filter" style="font-size:12px;padding:5px 8px;border-radius:4px;border:1px solid #2271b1;color:#1d2327;font-weight:600;">
											<option value="0">Todas las series (<?php echo (int) $affected_count; ?> tomos)</option>
											<?php foreach ( $series_in_items as $s_info ) : ?>
												<option value="<?php echo (int) $s_info['id']; ?>">Solo <?php echo esc_html( $s_info['name'] ); ?> (<?php echo (int) $s_info['count']; ?> tomos)</option>
											<?php endforeach; ?>
										</select>
									<?php endif; ?>
								</div>
								<span id="akb-proposal-counter" style="font-size:12px;font-weight:600;color:#50575e;">
									Mostrando <strong><?php echo (int) $affected_count; ?></strong> de <?php echo (int) $affected_count; ?> tomos
								</span>
							</div>
							<div style="max-height:360px;overflow-y:auto;border:1px solid #dcdcde;border-radius:4px;">
								<table class="widefat striped" id="akb-proposal-table" style="font-size:12px;border:none;">
									<thead style="position:sticky;top:0;background:#f6f7f7;z-index:2;box-shadow:0 1px 2px rgba(0,0,0,0.06);">
										<tr>
											<th style="width:70px;">ID</th>
											<th>Título del Tomo</th>
											<th style="width:160px;">Serie</th>
											<th style="width:110px;">Estado Stock</th>
											<th style="width:180px;">Precio Regular</th>
											<th style="width:180px;">Precio A Pedido</th>
										</tr>
									</thead>
									<tbody>
										<?php foreach ( $items as $it ) : ?>
											<?php if ( in_array( $it['status'] ?? '', array( 'would_update', 'updated' ), true ) ) : ?>
												<tr class="akb-proposal-row" data-serie-id="<?php echo (int) ( $it['serie_id'] ?? 0 ); ?>" data-search="<?php echo esc_attr( strtolower( (string) ( $it['title'] ?? '' ) . ' ' . (string) ( $it['serie_name'] ?? '' ) . ' ' . (string) ( $it['product_id'] ?? '' ) ) ); ?>">
													<td><code>#<?php echo (int) ( $it['product_id'] ?? 0 ); ?></code></td>
													<td><strong><?php echo esc_html( (string) ( $it['title'] ?? '' ) ); ?></strong></td>
													<td><?php echo esc_html( (string) ( $it['serie_name'] ?? '—' ) ); ?></td>
													<td><code><?php echo esc_html( (string) ( $it['stock_status'] ?? '' ) ); ?></code></td>
													<td>
														<del style="color:#8c8f94;">$<?php echo number_format( (int) ( $it['old_regular'] ?? 0 ), 0, ',', '.' ); ?></del> ➔
														<strong style="color:#2271b1;">$<?php echo number_format( (int) ( $it['new_regular'] ?? 0 ), 0, ',', '.' ); ?></strong>
													</td>
													<td>
														<del style="color:#8c8f94;">$<?php echo number_format( (int) ( $it['old_sale'] ?? 0 ), 0, ',', '.' ); ?></del> ➔
														<strong style="color:#008a20;">$<?php echo number_format( (int) ( $it['new_sale'] ?? 0 ), 0, ',', '.' ); ?></strong>
													</td>
												</tr>
											<?php endif; ?>
										<?php endforeach; ?>
									</tbody>
								</table>
							</div>
						</div>
					</details>
				<?php endif; ?>
			</div>
		<?php else : ?>
			<div class="notice <?php echo $is_dry_run ? 'notice-info' : 'notice-success'; ?> is-dismissible" style="padding:12px 16px;margin:15px 0;">
				<strong>Resultado de la última operación:</strong>
				<?php if ( isset( $last_result['message'] ) ) : ?>
					<span><?php echo esc_html( (string) $last_result['message'] ); ?></span>
				<?php else : ?>
					<span>
						<?php echo $is_dry_run ? '[SIMULACIÓN DRY-RUN] ' : '[APLICADO CON ÉXITO] '; ?>
						Actualizados en catálogo: <strong><?php echo (int) ( $last_result['updated_count'] ?? $last_result['rescued_count'] ?? 0 ); ?> tomos</strong>.
					</span>
				<?php endif; ?>
			</div>
		<?php endif;
	}

	/**
	 * Renderiza la barra de pestañas con contadores en tiempo real (solo 2 pestañas operativas).
	 *
	 * @param string $active_tab      Pestaña activa.
	 * @param int    $scales_count    Total escalas detectadas.
	 * @param int    $rescuables_count Agotados rescatables.
	 * @return void
	 */
	private function render_nav_tabs( string $active_tab, int $scales_count, int $rescuables_count ): void {
		?>
		<nav class="akb-pm-nav-tabs">
			<a href="<?php echo esc_url( add_query_arg( array( 'page' => self::MENU_SLUG, 'tab' => 'scales' ), admin_url( 'admin.php' ) ) ); ?>" class="akb-pm-tab-link <?php echo 'scales' === $active_tab ? 'is-active' : ''; ?>">
				1. Matriz de Escalas por Editorial
				<span class="akb-pm-badge-count"><?php echo (int) $scales_count; ?></span>
			</a>
			<a href="<?php echo esc_url( add_query_arg( array( 'page' => self::MENU_SLUG, 'tab' => 'rescue' ), admin_url( 'admin.php' ) ) ); ?>" class="akb-pm-tab-link <?php echo 'rescue' === $active_tab ? 'is-active' : ''; ?>">
				2. Rescate Seguro de Agotados
				<span class="akb-pm-badge-count"><?php echo (int) $rescuables_count; ?></span>
			</a>
		</nav>
		<?php
	}

	/**
	 * Renderiza la pestaña 1: Matriz de Escalas por Editorial.
	 *
	 * @param list<array<string, mixed>> $scales        Escalas detectadas.
	 * @param array<string, mixed>|null  $last_result   Resultado de última simulación/operación.
	 * @param list<array<string, mixed>> $discrepancies Series con discrepancia detectadas.
	 * @return void
	 */
	private function render_tab_scales( array $scales, ?array $last_result = null, array $discrepancies = array() ): void {
		?>
		<div class="akb-pm-card">
			<?php if ( ! empty( $discrepancies ) ) : ?>
				<div id="akb-discrepancies-section" class="akb-pm-discrepancies-banner" style="background:#fff8e5;border:2px solid #dba617;border-radius:8px;padding:16px 20px;margin-bottom:20px;box-shadow:0 2px 6px rgba(0,0,0,0.05);">
					<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:12px;">
						<div>
							<h3 style="margin:0 0 4px 0;font-size:16px;color:#856404;display:flex;align-items:center;gap:8px;">
								<span>⚠️ Auditoría de Series: <?php echo count( $discrepancies ); ?> series con precios discrepantes</span>
							</h3>
							<p style="margin:0;font-size:12px;color:#664d03;">
								Los tomos estándar de estas series tienen precios regulares distintos. Haz clic en la escala dominante (★) o promedio y presiona <strong>Nivelar Serie</strong> para unificarla.
							</p>
						</div>
					</div>

					<div class="akb-pm-table-responsive" style="max-height:280px;overflow-y:auto;background:#fff;border-radius:4px;border:1px solid #e0c878;">
						<table class="akb-pm-table" style="font-size:12px;margin:0;">
							<thead>
								<tr>
									<th style="width:130px;">Editorial</th>
									<th style="width:200px;">Serie</th>
									<th>Escalas Detectadas (Clic para elegir)</th>
									<th style="width:70px;text-align:center;">Tomos</th>
									<th style="width:310px;">Nivelar en 1 Clic</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $discrepancies as $d ) : ?>
									<?php $row_key = (int) $d['serie_id'] . '-' . (int) $d['brand_id']; ?>
									<tr>
										<td><strong><?php echo esc_html( $d['editorial'] ); ?></strong></td>
										<td>
											<span class="akb-pm-serie-title" style="font-weight:700;"><?php echo esc_html( $d['serie_name'] ); ?></span>
											<br /><span class="akb-pm-slug-badge" style="font-size:10px;"><?php echo esc_html( $d['serie_slug'] ); ?></span>
										</td>
										<td>
											<div class="akb-pm-scales-wrap">
												<?php if ( ! empty( $d['average_price'] ) ) : ?>
													<button type="button" class="akb-pm-scale-chip akb-pm-scale-chip--average" data-input-id="banner-target-reg-<?php echo esc_attr( $row_key ); ?>" data-price="<?php echo (int) $d['average_price']; ?>" title="Promedio ponderado: $<?php echo number_format( (int) $d['average_price'], 0, ',', '.' ); ?>">
														<span>⚖ Promedio: $<?php echo esc_html( number_format( (int) $d['average_price'], 0, ',', '.' ) ); ?></span>
													</button>
												<?php endif; ?>
												<?php foreach ( $d['scales'] as $sc => $cnt ) : ?>
													<?php $is_dom = ( (int) $sc === (int) $d['dominant_scale'] ); ?>
													<button type="button" class="akb-pm-scale-chip <?php echo $is_dom ? 'akb-pm-scale-chip--dominant' : 'akb-pm-scale-chip--divergent'; ?>" data-input-id="banner-target-reg-<?php echo esc_attr( $row_key ); ?>" data-price="<?php echo (int) $sc; ?>" title="<?php echo $is_dom ? 'Escala dominante (' . (int) $cnt . ' tomos)' : 'Escala discrepante (' . (int) $cnt . ' tomos)'; ?>">
														<span>$<?php echo esc_html( number_format( (int) $sc, 0, ',', '.' ) ); ?></span>
														<span class="akb-pm-chip-count">(<?php echo (int) $cnt; ?><?php echo $is_dom ? ' ★' : ''; ?>)</span>
													</button>
												<?php endforeach; ?>
											</div>
										</td>
										<td style="text-align:center;"><span class="akb-pm-number-badge"><?php echo (int) $d['total_tomos']; ?></span></td>
										<td>
											<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="akb-pm-level-form" style="display:flex;align-items:center;gap:6px;">
												<?php wp_nonce_field( self::NONCE_ACTION ); ?>
												<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_LEVEL_SERIE ); ?>" />
												<input type="hidden" name="serie_id" value="<?php echo (int) $d['serie_id']; ?>" />
												<input type="hidden" name="brand_id" value="<?php echo (int) $d['brand_id']; ?>" />
												<input type="hidden" name="protect_active_orders" value="0" />
												<div class="akb-pm-input-price-wrap" style="width:110px;">
													<span class="akb-pm-price-currency">$</span>
													<input type="number" id="banner-target-reg-<?php echo esc_attr( $row_key ); ?>" name="target_regular" class="akb-pm-input-price" value="<?php echo (int) $d['dominant_scale']; ?>" step="1" min="100" style="width:100%;font-size:12px;padding:3px 6px;" required />
												</div>
												<button type="submit" class="button button-primary akb-pm-btn-level" style="font-size:11px;padding:3px 10px;height:auto;" title="Nivelar todos los tomos estándar al precio seleccionado">Nivelar Serie</button>
											</form>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</div>
			<?php endif; ?>

			<div class="akb-pm-global-action-bar">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="akb-pm-global-action-bar__left">
					<?php wp_nonce_field( self::NONCE_ACTION ); ?>
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SYNC_BO ); ?>" />
					<input type="hidden" name="protect_active_orders" value="0" />
					<strong>Sincronización Global de Precios A Pedido:</strong>
					<span style="font-size:12px;color:#50575e;">(5% desc. con redondeo a $100 CLP para todos los tomos A Pedido)</span>
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
							<th style="min-width:160px;">Series en esta escala</th>
							<th style="width:390px;min-width:380px;">Precios de Venta (Regular Vitrina / Oferta A Pedido)</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $scales as $row ) : ?>
							<?php $this->render_scale_row( $row, $last_result ); ?>
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
	 * @param array<string, mixed>      $row         Fila de escala.
	 * @param array<string, mixed>|null $last_result Resultado de última simulación (para conservar borrador).
	 * @return void
	 */
	private function render_scale_row( array $row, ?array $last_result = null ): void {
		$is_draft_active = $last_result && ! empty( $last_result['dry_run'] )
			&& ( $row['editorial_slug'] === ( $last_result['editorial_slug'] ?? '' ) )
			&& ( (int) $row['escala_precio_regular'] === (int) ( $last_result['scale_regular'] ?? 0 ) );

		if ( $is_draft_active ) {
			$row['nuevo_precio_regular']  = (int) $last_result['new_regular'];
			$row['nuevo_precio_a_pedido'] = (int) ( $last_result['target_sale'] ?? $row['nuevo_precio_a_pedido'] );
			$row['discount_pct']          = (float) ( $last_result['discount_pct'] ?? $row['discount_pct'] );
			$row['rounding_mode']         = (string) ( $last_result['rounding_mode'] ?? $row['rounding_mode'] );
		}

		$uid     = sanitize_html_class( $row['editorial_slug'] . '-' . $row['escala_precio_regular'] );
		$id_reg  = 'reg-' . $uid;
		$id_pct  = 'pct-' . $uid;
		$id_mode = 'mode-' . $uid;
		$id_sale = 'sale-' . $uid;
		?>
		<tr class="akb-scale-searchable-row <?php echo $is_draft_active ? 'akb-row-draft-active' : ''; ?>" data-search="<?php echo esc_attr( strtolower( $row['editorial'] . ' ' . $row['editorial_slug'] . ' ' . $row['pais_codigo'] . ' ' . implode( ' ', $row['series_names'] ?? array() ) ) ); ?>" <?php echo $is_draft_active ? 'style="background:#f0f6fc;"' : ''; ?>>
			<td data-value="<?php echo esc_attr( $row['editorial'] ); ?>" class="akb-pm-col-editorial" data-label="Editorial">
				<div class="akb-pm-editorial-cell">
					<span class="akb-pm-editorial-name"><?php echo esc_html( $row['editorial'] ); ?></span>
					<span class="akb-pm-slug-badge"><?php echo esc_html( $row['editorial_slug'] ); ?></span>
					<?php if ( $is_draft_active ) : ?>
						<span style="display:inline-block;margin-top:4px;background:#2271b1;color:#fff;font-size:10px;font-weight:700;padding:2px 6px;border-radius:4px;">
							Propuesta simulada: $<?php echo number_format( (int) $row['nuevo_precio_regular'], 0, ',', '.' ); ?>
						</span>
					<?php endif; ?>
				</div>
			</td>
			<td data-value="<?php echo esc_attr( $row['pais_codigo'] ); ?>" class="akb-pm-col-country" data-label="País" style="text-align:center;">
				<span class="akb-pm-country-pill"><?php echo esc_html( strtoupper( $row['pais_codigo'] ?: '—' ) ); ?></span>
			</td>
			<td data-value="<?php echo (int) $row['escala_precio_regular']; ?>" class="akb-pm-col-price" data-label="Precio Actual">
				<strong style="font-size:14px;color:#1d2327;">$<?php echo esc_html( number_format( (int) $row['escala_precio_regular'], 0, ',', '.' ) ); ?></strong>
				<?php if ( null !== $row['min_sale_actual'] ) : ?>
					<br /><span style="font-size:11px;color:#0d6832;font-weight:600;">Oferta: $<?php echo esc_html( number_format( (int) $row['min_sale_actual'], 0, ',', '.' ) ); ?></span>
				<?php endif; ?>
			</td>
			<td data-value="<?php echo (int) $row['total_tomos']; ?>" class="akb-pm-col-total" data-label="Total" style="text-align:center;">
				<span class="akb-pm-number-badge"><?php echo (int) $row['total_tomos']; ?></span>
			</td>
			<td data-value="<?php echo (int) $row['en_stock']; ?>" class="akb-pm-col-stock" data-label="Stock" style="text-align:center;">
				<span style="font-weight:700;color:#0d6832;"><?php echo (int) $row['en_stock']; ?></span>
			</td>
			<td data-value="<?php echo (int) $row['a_pedido']; ?>" class="akb-pm-col-backorder" data-label="A Pedido" style="text-align:center;">
				<span style="font-weight:700;color:#2271b1;"><?php echo (int) $row['a_pedido']; ?></span>
			</td>
			<td data-value="<?php echo (int) $row['agotados']; ?>" class="akb-pm-col-outofstock" data-label="Agotado" style="text-align:center;">
				<span style="color:#646970;"><?php echo (int) $row['agotados']; ?></span>
			</td>
			<td data-value="<?php echo (int) $row['series_count']; ?>" class="akb-pm-col-series" data-label="Series">
				<div class="akb-pm-series-summary-cell">
					<div style="display:flex;align-items:center;justify-content:space-between;gap:6px;">
						<strong><?php echo (int) $row['series_count']; ?> <?php echo 1 === (int) $row['series_count'] ? 'serie' : 'series'; ?></strong>
						<?php if ( ! empty( $row['series_items'] ) && count( $row['series_items'] ) > 1 ) : ?>
							<button type="button" class="button button-small akb-pm-toggle-series-btn" data-target="series-box-<?php echo esc_attr( $uid ); ?>" style="font-size:10px;padding:0 6px;height:22px;line-height:20px;" title="Ver y seleccionar series individuales">
								Ver series ▾
							</button>
						<?php endif; ?>
					</div>
					<?php if ( ! empty( $row['series_names'] ) ) : ?>
						<div class="akb-pm-series-names-preview" style="font-size:11px;color:#50575e;max-height:46px;overflow-y:auto;margin-top:2px;line-height:1.4;">
							<?php echo esc_html( implode( ', ', array_slice( $row['series_names'], 0, 8 ) ) ); ?>
							<?php if ( count( $row['series_names'] ) > 8 ) : ?>
								… (+<?php echo count( $row['series_names'] ) - 8; ?>)
							<?php endif; ?>
						</div>
					<?php endif; ?>

					<?php if ( ! empty( $row['series_items'] ) && count( $row['series_items'] ) > 1 ) : ?>
						<div id="series-box-<?php echo esc_attr( $uid ); ?>" class="akb-pm-series-drawer" style="display:none;margin-top:8px;padding:8px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;">
							<div style="font-size:10px;font-weight:700;color:#50575e;text-transform:uppercase;margin-bottom:6px;">
								Fijar alcance exclusivo:
							</div>
							<div style="display:flex;flex-wrap:wrap;gap:4px;">
								<button type="button" class="akb-pm-series-pill akb-pm-series-pill--all is-active" data-scope-id="scope-<?php echo esc_attr( $uid ); ?>" data-serie-id="0" style="cursor:pointer;font-size:11px;padding:2px 8px;border-radius:12px;border:1px solid #2271b1;background:#2271b1;color:#fff;font-weight:600;">
									Toda la escala (<?php echo (int) $row['total_tomos']; ?>)
								</button>
								<?php foreach ( $row['series_items'] as $sitem ) : ?>
									<button type="button" class="akb-pm-series-pill" data-scope-id="scope-<?php echo esc_attr( $uid ); ?>" data-serie-id="<?php echo (int) $sitem['id']; ?>" style="cursor:pointer;font-size:11px;padding:2px 8px;border-radius:12px;border:1px solid #c3c4c7;background:#fff;color:#2c3338;">
										<?php echo esc_html( $sitem['name'] ); ?> (<?php echo (int) $sitem['count']; ?>)
									</button>
								<?php endforeach; ?>
							</div>
						</div>
					<?php endif; ?>
				</div>
			</td>
			<td class="akb-pm-col-actions" data-label="Precios de Venta">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="akb-scale-row-form" style="display:flex;flex-direction:column;gap:6px;">
					<?php wp_nonce_field( self::NONCE_ACTION ); ?>
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_APPLY_SCALE ); ?>" />
					<input type="hidden" name="brand_id" value="<?php echo (int) $row['brand_id']; ?>" />
					<input type="hidden" name="editorial_slug" value="<?php echo esc_attr( $row['editorial_slug'] ); ?>" />
					<input type="hidden" name="scale_regular" value="<?php echo (int) $row['escala_precio_regular']; ?>" />
					<input type="hidden" name="protect_active_orders" value="0" />

					<?php if ( ! empty( $row['series_items'] ) && count( $row['series_items'] ) > 1 ) : ?>
						<div class="akb-pm-serie-scope-field">
							<label for="scope-<?php echo esc_attr( $uid ); ?>">
								🎯 Alcance:
							</label>
							<select id="scope-<?php echo esc_attr( $uid ); ?>" name="serie_id" class="akb-calc-serie-select" aria-label="Alcance: toda la escala o serie individual">
								<option value="0">Toda la escala (<?php echo (int) $row['series_count']; ?> series — <?php echo (int) $row['total_tomos']; ?> tomos)</option>
								<?php foreach ( $row['series_items'] as $sitem ) : ?>
									<option value="<?php echo (int) $sitem['id']; ?>">Solo <?php echo esc_html( $sitem['name'] ); ?> (<?php echo (int) $sitem['count']; ?> tomos)</option>
								<?php endforeach; ?>
							</select>
						</div>
					<?php else : ?>
						<input type="hidden" name="serie_id" value="0" />
					<?php endif; ?>

					<div class="akb-pm-scale-calc-grid">
						<div class="akb-pm-calc-field">
							<label for="<?php echo esc_attr( $id_reg ); ?>" title="Precio regular base de catálogo (vitrina y stock físico)">Regular Vitrina ($)</label>
							<input type="number" id="<?php echo esc_attr( $id_reg ); ?>" name="new_regular" class="akb-calc-reg" value="<?php echo (int) $row['nuevo_precio_regular']; ?>" step="1" min="100" aria-label="Precio regular de vitrina para <?php echo esc_attr( $row['editorial'] ); ?>" required />
						</div>
						<div class="akb-pm-calc-field">
							<label for="<?php echo esc_attr( $id_pct ); ?>" title="Descuento aplicado exclusivamente a tomos en estado A Pedido / Reserva (por defecto 5%)">% Desc. A Pedido</label>
							<input type="number" id="<?php echo esc_attr( $id_pct ); ?>" name="discount_pct" class="akb-calc-pct" value="<?php echo esc_attr( (string) $row['discount_pct'] ); ?>" step="0.5" min="0" max="90" aria-label="Porcentaje de descuento para tomos a pedido" required />
						</div>
						<div class="akb-pm-calc-field">
							<label for="<?php echo esc_attr( $id_mode ); ?>" title="Modo oficial de redondeo a la centena en pesos chilenos">Redondeo CLP</label>
							<select id="<?php echo esc_attr( $id_mode ); ?>" name="rounding_mode" class="akb-calc-mode" aria-label="Modo de redondeo CLP">
								<option value="round_100" <?php selected( $row['rounding_mode'], 'round_100' ); ?>>$100 CLP</option>
								<option value="floor_100" <?php selected( $row['rounding_mode'], 'floor_100' ); ?>>$100 abajo</option>
								<option value="round_50" <?php selected( $row['rounding_mode'], 'round_50' ); ?>>$50 CLP</option>
								<option value="manual" <?php selected( $row['rounding_mode'], 'manual' ); ?>>Manual</option>
							</select>
						</div>
						<div class="akb-pm-calc-field">
							<label for="<?php echo esc_attr( $id_sale ); ?>" title="Precio final de venta resultante para tomos en estado A Pedido / Reserva">Oferta A Pedido ($)</label>
							<input type="number" id="<?php echo esc_attr( $id_sale ); ?>" name="manual_sale" class="akb-calc-sale" value="<?php echo (int) $row['nuevo_precio_a_pedido']; ?>" step="50" aria-label="Precio de oferta para tomos a pedido" style="font-weight:700;color:#0d6832;" required />
						</div>
					</div>

					<div class="akb-pm-calc-footer" style="justify-content:flex-end;">
						<div class="akb-pm-btn-group">
							<button type="submit" name="dry_run" value="1" class="button akb-pm-btn-dry" title="Simular y revisar qué tomos cambiarán antes de aplicar">Simular</button>
							<button type="submit" class="button button-primary akb-pm-btn-level" title="Aplicar cambios de precio">Aplicar</button>
						</div>
					</div>
				</form>
			</td>
		</tr>
		<?php
	}

	/**
	 * Renderiza la pestaña 2: Rescate Manual y Selectivo de Agotados.
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
							<button type="submit" id="akb-rescue-btn-apply" class="button button-primary akb-pm-btn-level">Rescatar Seleccionados a «A Pedido» (5% round_100)</button>
						</div>
					</div>

					<div class="akb-pm-rescue-discount-toolbar" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:12px 16px;margin:12px 0;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
						<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
							<span style="font-size:13px;font-weight:700;color:#1e293b;">🎁 % Descuento Reserva:</span>
							<div class="akb-pm-rescue-pills" style="display:inline-flex;gap:6px;flex-wrap:wrap;">
								<button type="button" class="button button-secondary akb-rescue-pill is-active" data-pct="5">5% (Estándar)</button>
								<button type="button" class="button button-secondary akb-rescue-pill" data-pct="8">8%</button>
								<button type="button" class="button button-secondary akb-rescue-pill" data-pct="10">10% (Promo)</button>
								<button type="button" class="button button-secondary akb-rescue-pill" data-pct="12">12%</button>
								<button type="button" class="button button-secondary akb-rescue-pill" data-pct="15">15%</button>
							</div>
							<div style="display:inline-flex;align-items:center;gap:4px;margin-left:6px;">
								<input type="number" id="akb-rescue-custom-pct" name="rescue_discount_pct" value="5" step="0.5" min="0" max="90" style="width:65px;height:30px;font-size:12px;text-align:center;font-weight:700;" />
								<span style="font-weight:700;font-size:13px;color:#475569;">%</span>
							</div>
						</div>
						<div style="display:flex;align-items:center;gap:10px;">
							<label style="font-size:12px;font-weight:600;color:#334155;">
								Modo Redondeo:
								<select id="akb-rescue-rounding" name="rescue_rounding_mode" style="margin-left:6px;height:30px;font-size:12px;">
									<option value="round_100" selected>Centena (round_100)</option>
									<option value="round_50">Cincuenta (round_50)</option>
									<option value="floor_100">Piso Centena (floor_100)</option>
								</select>
							</label>
						</div>
					</div>

					<div class="akb-pm-rescue-auth-panel" style="background:#f0f6fc;border:1px solid #cce5ff;border-radius:6px;padding:12px 16px;margin:12px 0 16px;">
						<div style="font-weight:700;color:#0c4a6e;margin-bottom:8px;font-size:13px;">📋 Autorización Obligatoria de Distribuidor (Reglas 15 y 18 - Control de Agotados)</div>
						<div style="display:flex;gap:16px;flex-wrap:wrap;align-items:center;margin-bottom:8px;">
							<label style="font-size:12px;font-weight:600;color:#1e293b;">
								Distribuidor Oficial:
								<select name="distributor_supplier" style="margin-left:6px;font-size:12px;">
									<option value="">-- Seleccionar distribuidor confirmado --</option>
									<?php foreach ( PricingSynchronizer::APPROVED_SUPPLIERS as $s_key => $s_label ) : ?>
										<option value="<?php echo esc_attr( $s_key ); ?>"><?php echo esc_html( $s_label ); ?></option>
									<?php endforeach; ?>
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
									<th class="akb-sortable" data-sort="number" style="width:190px;cursor:pointer;">Precio Reserva (<span id="akb-rescue-header-pct">5%</span>) ↕</th>
									<th class="akb-sortable" data-sort="number" style="width:130px;cursor:pointer;">Días Agotado ↕</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( array_slice( $rescuables, 0, 300 ) as $item ) : ?>
									<tr class="akb-rescue-searchable-row" data-regular="<?php echo (int) $item['regular_price']; ?>" data-search="<?php echo esc_attr( strtolower( $item['title'] . ' ' . $item['editorial'] . ' ' . $item['product_id'] . ' #' . $item['product_id'] ) ); ?>">
										<td class="akb-rescue-col-cb" data-label="Seleccionar" style="text-align:center;">
											<input type="checkbox" class="akb-rescue-cb" name="product_ids[]" value="<?php echo (int) $item['product_id']; ?>" />
										</td>
										<td class="akb-rescue-col-id" data-label="ID" data-value="<?php echo (int) $item['product_id']; ?>">
											<a href="<?php echo esc_url( admin_url( 'post.php?post=' . (int) $item['product_id'] . '&action=edit' ) ); ?>" target="_blank" style="text-decoration:none;">
												<code>#<?php echo (int) $item['product_id']; ?></code>
											</a>
										</td>
										<td class="akb-rescue-col-title" data-label="Producto" data-value="<?php echo esc_attr( $item['title'] ); ?>">
											<strong><?php echo esc_html( $item['title'] ); ?></strong>
										</td>
										<td class="akb-rescue-col-editorial" data-label="Editorial" data-value="<?php echo esc_attr( $item['editorial'] ); ?>">
											<?php echo esc_html( $item['editorial'] ); ?>
										</td>
										<td class="akb-rescue-col-reg" data-label="Precio Regular" data-value="<?php echo (int) $item['regular_price']; ?>">
											$<?php echo esc_html( number_format( (int) $item['regular_price'], 0, ',', '.' ) ); ?>
										</td>
										<td class="akb-rescue-col-sale akb-rescue-sale-cell" data-label="Precio Reserva" data-value="<?php echo (int) $item['proposed_sale_price']; ?>">
											<div style="display:flex;align-items:center;justify-content:space-between;gap:6px;">
												<strong class="akb-rescue-sale-price" style="color:#0d6832;font-size:14px;">$<?php echo esc_html( number_format( (int) $item['proposed_sale_price'], 0, ',', '.' ) ); ?></strong>
												<div class="akb-rescue-manual-wrap" style="display:inline-flex;align-items:center;">
													<input type="number" step="100" min="100" name="individual_sale[<?php echo (int) $item['product_id']; ?>]" class="akb-rescue-individual-input" placeholder="Manual $" style="display:none;width:86px;font-size:11px;padding:2px 4px;height:24px;border:1px solid #c3c4c7;border-radius:3px;" />
													<button type="button" class="button button-small akb-rescue-toggle-manual-btn" title="Definir precio individual específico para este tomo" style="padding:0 5px;height:22px;line-height:20px;font-size:11px;margin-left:3px;">✏️</button>
												</div>
											</div>
										</td>
										<td class="akb-rescue-col-days" data-label="Días Agotado" data-value="<?php echo null !== $item['dias_agotado'] ? (int) $item['dias_agotado'] : -1; ?>">
											<?php if ( null !== $item['dias_agotado'] ) : ?>
												<span class="akb-pm-badge-days" style="display:inline-block;padding:2px 8px;border-radius:12px;font-size:11px;font-weight:700;<?php echo $item['dias_agotado'] > 30 ? 'background:#fff1f0;color:#cf1322;border:1px solid #ffa39e;' : 'background:#f6ffed;color:#389e0d;border:1px solid #b7eb8f;'; ?>">
													⏱️ <?php echo (int) $item['dias_agotado']; ?> <?php echo 1 === (int) $item['dias_agotado'] ? 'día' : 'días'; ?>
												</span>
											<?php else : ?>
												<span style="color:#8c8f94;font-size:11px;">Reciente / No reg.</span>
											<?php endif; ?>
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
	 * Renderiza los scripts interactivos del panel (filtros en vivo, selector de series, calculadora y ordenamiento).
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

			// 2. Control de alcance por serie individual y actualización dinámica de botones
			function updateScopeButtons(selectEl) {
				var form = selectEl.closest('form');
				if (!form) return;
				var btnApply = form.querySelector('.akb-pm-btn-level');
				var btnDry   = form.querySelector('.akb-pm-btn-dry');
				var container = selectEl.closest('.akb-pm-serie-scope-field');
				var val = String(selectEl.value || '0');

				if (val === '0') {
					if (container) container.classList.remove('is-scoped');
					if (btnApply) btnApply.textContent = 'Aplicar';
					if (btnDry)   btnDry.textContent = 'Simular';
				} else {
					if (container) container.classList.add('is-scoped');
					var opt = selectEl.options[selectEl.selectedIndex];
					var optText = opt ? opt.textContent.trim().replace(/^Solo\s+/, '') : '';
					if (btnApply) btnApply.textContent = 'Aplicar a ' + optText;
					if (btnDry)   btnDry.textContent = 'Simular ' + optText;
				}
			}

			document.querySelectorAll('.akb-calc-serie-select').forEach(function(sel) {
				sel.addEventListener('change', function() {
					updateScopeButtons(sel);
				});
			});

			// 2b. Desglose interactivo de series (Acordeón y Pills inspirados en ATUM Stock Central)
			document.querySelectorAll('.akb-pm-toggle-series-btn').forEach(function(btn) {
				btn.addEventListener('click', function(e) {
					e.preventDefault();
					var targetId = btn.getAttribute('data-target');
					var drawer   = document.getElementById(targetId);
					if (drawer) {
						var isHidden = drawer.style.display === 'none';
						drawer.style.display = isHidden ? 'block' : 'none';
						btn.textContent = isHidden ? 'Ocultar ▴' : 'Ver series ▾';
					}
				});
			});

			document.querySelectorAll('.akb-pm-series-pill').forEach(function(pill) {
				pill.addEventListener('click', function(e) {
					e.preventDefault();
					var scopeId  = pill.getAttribute('data-scope-id');
					var serieId  = String(pill.getAttribute('data-serie-id') || '0');
					var selectEl = document.getElementById(scopeId);
					if (!selectEl) return;

					selectEl.value = serieId;
					updateScopeButtons(selectEl);

					var drawer = pill.closest('.akb-pm-series-drawer');
					if (drawer) {
						drawer.querySelectorAll('.akb-pm-series-pill').forEach(function(p) {
							p.classList.remove('is-active');
							p.style.background = '#fff';
							p.style.color = '#2c3338';
							p.style.borderColor = '#c3c4c7';
							p.style.fontWeight = 'normal';
						});
						pill.classList.add('is-active');
						pill.style.background = '#2271b1';
						pill.style.color = '#fff';
						pill.style.borderColor = '#2271b1';
						pill.style.fontWeight = '600';
					}
				});
			});

			// 2c. Guardado rápido AJAX por fila (Patrón ATUM sin recarga de página)
			document.querySelectorAll('.akb-scale-row-form').forEach(function(form) {
				form.addEventListener('submit', function(e) {
					var submitter = e.submitter;
					// Interceptar exclusivamente el botón de Aplicar (el de Simular sigue el flujo POST de revisión completa)
					if (!submitter || !submitter.classList.contains('akb-pm-btn-level')) {
						return;
					}

					e.preventDefault();
					var origText = submitter.textContent;
					submitter.disabled = true;
					submitter.textContent = '⏳ Guardando...';

					var formData = new FormData(form);
					formData.append('action', '<?php echo esc_js( self::ACTION_APPLY_SCALE_AJAX ); ?>');
					formData.append('nonce', '<?php echo esc_js( wp_create_nonce( self::NONCE_ACTION ) ); ?>');

					fetch(ajaxurl, {
						method: 'POST',
						body: formData,
						credentials: 'same-origin'
					})
					.then(function(res) { return res.json(); })
					.then(function(json) {
						submitter.disabled = false;
						submitter.textContent = origText;

						if (json && json.success) {
							var row = form.closest('tr');
							if (row) {
								var serieIdVal = form.querySelector('[name="serie_id"]') ? form.querySelector('[name="serie_id"]').value : '0';
								if (serieIdVal === '0') {
									var priceCell = row.querySelector('td:nth-child(3) strong');
									if (priceCell && json.data && json.data.new_regular_fmt) {
										priceCell.textContent = json.data.new_regular_fmt;
									}
									var saleCell = row.querySelector('td:nth-child(3) span');
									if (saleCell && json.data && json.data.new_sale_fmt) {
										saleCell.textContent = 'Oferta: ' + json.data.new_sale_fmt;
									}
								}
								row.style.transition = 'background 0.3s ease';
								row.style.background = '#e6ffed';
								setTimeout(function() { row.style.background = ''; }, 2000);
							}

							var toast = document.createElement('div');
							toast.className = 'akb-pm-ajax-toast';
							toast.textContent = '✓ ' + (json.data ? json.data.message : 'Guardado con éxito');
							document.body.appendChild(toast);
							setTimeout(function() { toast.classList.add('is-visible'); }, 10);
							setTimeout(function() {
								toast.classList.remove('is-visible');
								setTimeout(function() { toast.remove(); }, 300);
							}, 3500);
						} else {
							alert('Aviso: ' + (json && json.data && json.data.message ? json.data.message : 'No se pudo guardar'));
						}
					})
					.catch(function(err) {
						submitter.disabled = false;
						submitter.textContent = origText;
						console.error('AJAX Fallback:', err);
						form.submit();
					});
				});
			});

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

							var serieSelect = row.querySelector('.akb-calc-serie-select');
							if (serieSelect && query.length >= 3) {
								var matched = false;
								for (var i = 1; i < serieSelect.options.length; i++) {
									var optText = serieSelect.options[i].textContent.toLowerCase();
									if (optText.indexOf(query) !== -1) {
										serieSelect.selectedIndex = i;
										updateScopeButtons(serieSelect);
										matched = true;
										break;
									}
								}
								if (!matched && serieSelect.selectedIndex !== 0) {
									serieSelect.selectedIndex = 0;
									updateScopeButtons(serieSelect);
								}
							} else if (serieSelect && !query) {
								serieSelect.selectedIndex = 0;
								updateScopeButtons(serieSelect);
							}
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

			// 4. Calculadora CLP compartida
			function calcSale(reg, pct, mode, currentManual) {
				if (reg <= 0) return 0;
				if (mode === 'manual') return currentManual > 0 ? currentManual : reg;
				var clamped = Math.max(0, Math.min(95, pct));
				var raw = reg * ((100 - clamped) / 100);
				if (mode === 'floor_100') return Math.floor(raw / 100) * 100;
				if (mode === 'round_50') return Math.round(raw / 50) * 50;
				return Math.round(raw / 100) * 100;
			}

			// 4.1 Descuento interactivo en vivo para la pestaña de Rescate (Grupal e Individual)
			var rescuePills      = document.querySelectorAll('.akb-rescue-pill');
			var rescueCustomPct  = document.getElementById('akb-rescue-custom-pct');
			var rescueRounding   = document.getElementById('akb-rescue-rounding');
			var rescueHeaderPct  = document.getElementById('akb-rescue-header-pct');
			var rescueBtnApply   = document.getElementById('akb-rescue-btn-apply');

			function recalcRescuePrices() {
				var pct = rescueCustomPct ? (parseFloat(rescueCustomPct.value) || 0) : 5;
				var mode = rescueRounding ? rescueRounding.value : 'round_100';

				if (rescueHeaderPct) {
					rescueHeaderPct.textContent = pct + '%';
				}
				if (rescueBtnApply) {
					rescueBtnApply.textContent = 'Rescatar Seleccionados a «A Pedido» (' + pct + '% ' + mode + ')';
				}

				if (!rescueTable) return;

				var rows = rescueTable.querySelectorAll('tbody tr.akb-rescue-searchable-row');
				rows.forEach(function(row) {
					var reg = parseInt(row.getAttribute('data-regular') || '0', 10);
					var manualInput = row.querySelector('.akb-rescue-individual-input');
					var priceStrong = row.querySelector('.akb-rescue-sale-price');
					var cell        = row.querySelector('.akb-rescue-sale-cell');

					if (!priceStrong || reg <= 0) return;

					var manualVal = manualInput && manualInput.value.trim() !== '' ? parseInt(manualInput.value, 10) : 0;
					var salePrice = 0;
					if (manualVal > 0) {
						salePrice = manualVal;
						priceStrong.style.color = '#b45309';
					} else {
						salePrice = calcSale(reg, pct, mode, 0);
						priceStrong.style.color = '#0d6832';
					}

					priceStrong.textContent = '$' + salePrice.toLocaleString('es-CL');
					if (cell) {
						cell.setAttribute('data-value', String(salePrice));
					}
				});
			}

			rescuePills.forEach(function(pill) {
				pill.addEventListener('click', function(e) {
					e.preventDefault();
					rescuePills.forEach(function(p) { p.classList.remove('is-active'); });
					pill.classList.add('is-active');
					var pVal = pill.getAttribute('data-pct');
					if (rescueCustomPct) {
						rescueCustomPct.value = pVal;
					}
					recalcRescuePrices();
				});
			});

			if (rescueCustomPct) {
				rescueCustomPct.addEventListener('input', function() {
					var val = parseFloat(rescueCustomPct.value);
					rescuePills.forEach(function(p) {
						if (parseFloat(p.getAttribute('data-pct')) === val) {
							p.classList.add('is-active');
						} else {
							p.classList.remove('is-active');
						}
					});
					recalcRescuePrices();
				});
			}

			if (rescueRounding) {
				rescueRounding.addEventListener('change', recalcRescuePrices);
			}

			document.querySelectorAll('.akb-rescue-toggle-manual-btn').forEach(function(btn) {
				btn.addEventListener('click', function(e) {
					e.preventDefault();
					var row = btn.closest('tr');
					if (!row) return;
					var input = row.querySelector('.akb-rescue-individual-input');
					if (input) {
						if (input.style.display === 'none') {
							input.style.display = 'inline-block';
							input.focus();
						} else {
							input.style.display = 'none';
							input.value = '';
							recalcRescuePrices();
						}
					}
				});
			});

			document.querySelectorAll('.akb-rescue-individual-input').forEach(function(input) {
				input.addEventListener('input', recalcRescuePrices);
			});

			// 4.2 Calculadora CLP en vivo por fila de escala

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

			// 5. Ordenamiento dinámico de columnas en tablas
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

			// 6. Filtro en vivo y selector de series para la tabla de Propuesta (.akb-pm-proposal-review)
			var propSearch     = document.getElementById('akb-proposal-search');
			var propTable      = document.getElementById('akb-proposal-table');
			var propCount      = document.getElementById('akb-proposal-counter');
			var propSerieFilter = document.getElementById('akb-proposal-serie-filter');
			var propFormSerieId = document.getElementById('akb-proposal-form-serie-id');
			var propBtnCount   = document.getElementById('akb-proposal-btn-count');
			var propApplyCount = document.getElementById('akb-proposal-apply-count');

			if (propTable) {
				var propRows = Array.from(propTable.querySelectorAll('tbody tr.akb-proposal-row'));

				function filterProposalRows() {
					var query = propSearch ? propSearch.value.toLowerCase().trim() : '';
					var selectedSerie = propSerieFilter ? String(propSerieFilter.value) : '0';
					var visible = 0;

					propRows.forEach(function(row) {
						var rowSerie = String(row.getAttribute('data-serie-id') || '0');
						var text     = row.getAttribute('data-search') || row.textContent.toLowerCase();
						var matchSerie = (selectedSerie === '0' || rowSerie === selectedSerie);
						var matchText  = (!query || text.indexOf(query) !== -1);

						if (matchSerie && matchText) {
							row.style.display = '';
							visible++;
						} else {
							row.style.display = 'none';
						}
					});

					if (propCount) {
						propCount.innerHTML = 'Mostrando <strong>' + visible + '</strong> de ' + propRows.length + ' tomos';
					}
					if (propBtnCount) {
						propBtnCount.textContent = String(visible);
					}
					if (propApplyCount) {
						propApplyCount.textContent = String(visible);
					}
				}

				if (propSearch) {
					propSearch.addEventListener('input', filterProposalRows);
				}
				if (propSerieFilter) {
					propSerieFilter.addEventListener('change', function() {
						if (propFormSerieId) {
							propFormSerieId.value = propSerieFilter.value;
						}
						filterProposalRows();
					});
				}
			}

			// 7. Prevención de doble clic y feedback de carga en operaciones masivas
			document.querySelectorAll('form.akb-pm-apply-form, form.akb-pm-level-form').forEach(function(f) {
				f.addEventListener('submit', function() {
					var btn = f.querySelector('button[type="submit"]:not(.button-secondary)');
					if (btn && !btn.disabled) {
						btn.disabled = true;
						btn.style.opacity = '0.75';
						btn.innerHTML = '⏳ Aplicando cambios...';
					}
				});
			});
		})();
		</script>
		<?php
	}
}
