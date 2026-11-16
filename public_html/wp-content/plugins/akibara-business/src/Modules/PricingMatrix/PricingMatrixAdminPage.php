<?php
/**
 * Panel de Administración de la Matriz de Precios de Akibara.
 *
 * Permite gestionar las escalas de precios regulares por editorial,
 * nivelar series con discrepancias y aplicar cambios masivos o selectivos.
 *
 * @package Akibara\Business\Modules\PricingMatrix
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\PricingMatrix;

use Akibara\Business\Utilities\PluginAssetVersion;

defined( 'ABSPATH' ) || exit;

/**
 * @api
 * @psalm-suppress HookInvalidArgs
 * @psalm-suppress RedundantCast
 */
// @phpstan-ignore complexity.classLike
final class PricingMatrixAdminPage {

	public const MENU_SLUG               = 'akibara-pricing-matrix';
	public const ACTION_APPLY_SCALE      = 'akb_pricing_matrix_apply_scale';
	public const ACTION_APPLY_SCALE_AJAX = 'akb_pricing_matrix_apply_scale_ajax';
	public const ACTION_DISCARD_PROPOSAL = 'akb_pricing_matrix_discard_proposal';
	public const ACTION_LEVEL_SERIE      = 'akb_pricing_matrix_level_series';
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
		add_action( 'admin_post_' . self::ACTION_LEVEL_SERIE, array( $this, 'handle_level_series' ) );
	}

	/**
	 * Registra la página dentro del menú de Akibara.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		add_submenu_page(
			'akibara-panel',
			'Matriz de Precios',
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
		$manual_raw     = isset( $_POST['manual_sale'] ) ? trim( sanitize_text_field( (string) wp_unslash( $_POST['manual_sale'] ) ) ) : ( isset( $_POST['new_sale'] ) ? trim( sanitize_text_field( (string) wp_unslash( $_POST['new_sale'] ) ) ) : '' );
		$manual_sale    = '' !== $manual_raw ? absint( $manual_raw ) : null;
		$serie_id       = isset( $_POST['serie_id'] ) ? absint( $_POST['serie_id'] ) : 0;

		$protect_orders = ! empty( $_POST['protect_active_orders'] ) && '1' === (string) sanitize_text_field( wp_unslash( $_POST['protect_active_orders'] ) );
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

		$protect_orders = ! empty( $_POST['protect_active_orders'] ) && '1' === (string) sanitize_text_field( wp_unslash( $_POST['protect_active_orders'] ) );
		$manual_raw     = isset( $_POST['manual_sale'] ) ? trim( sanitize_text_field( (string) wp_unslash( $_POST['manual_sale'] ) ) ) : ( isset( $_POST['new_sale'] ) ? trim( sanitize_text_field( (string) wp_unslash( $_POST['new_sale'] ) ) ) : '' );
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

		$target_sale = isset( $res['target_sale'] ) ? (int) $res['target_sale'] : PricingMath::calculate_sale_price( $new_regular, $discount_pct, $rounding_mode, $manual_sale );

		delete_transient( 'akb_pricing_matrix_last_result_' . get_current_user_id() );

		wp_send_json_success( array(
			'message'         => sprintf( 'Escala aplicada con éxito: %d tomos actualizados.', $res['updated_count'] ),
			'updated_count'   => $res['updated_count'],
			'unchanged_count' => $res['unchanged_count'],
			'new_regular'     => $new_regular,
			'new_sale'        => $target_sale,
			'has_sale'        => $target_sale > 0,
			'new_regular_fmt' => '$' . number_format( $new_regular, 0, ',', '.' ),
			'new_sale_fmt'    => $target_sale > 0 ? '$' . number_format( $target_sale, 0, ',', '.' ) : 'Sin oferta',
			'scale_regular'   => $scale_regular,
			'serie_id'        => $serie_id,
		) );
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
		$protect_orders = ! empty( $_POST['protect_active_orders'] ) && '1' === (string) sanitize_text_field( wp_unslash( $_POST['protect_active_orders'] ) );
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
	 * Renderiza la interfaz administrativa de la Matriz de Precios.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'No autorizado.' );
		}

		$last_result = get_transient( 'akb_pricing_matrix_last_result_' . get_current_user_id() );
		if ( false !== $last_result ) {
			delete_transient( 'akb_pricing_matrix_last_result_' . get_current_user_id() );
		}

		$scales        = ScaleScanner::scan_editorial_scales();
		$discrepancies = ScaleScanner::scan_series_discrepancies();

		?>
		<div class="wrap akb-pm-wrap">
			<div class="akb-pm-panel">
				<div class="akb-panel-top-bar"><a href="<?php echo esc_url( admin_url( 'admin.php?page=akibara-panel' ) ); ?>" class="akb-panel-back-btn">← Volver al Centro de Mando</a></div>
				<header class="akb-pm-head">
					<div class="akb-pm-brand">
						<span class="akb-pm-gem" aria-hidden="true">&#9670;</span>
						<h1>Matriz de Precios</h1>
						<span class="akb-pm-version-badge">v<?php echo defined( 'AKIBARA_BUSINESS_VERSION' ) ? esc_html( AKIBARA_BUSINESS_VERSION ) : '1.120.20'; ?></span>
					</div>
					<div class="akb-pm-head-subtitle">
						Gestión de escalas de precio regular y ofertas por editorial
					</div>
				</header>

				<div class="akb-pm-body">
					<?php
					if ( is_array( $last_result ) ) {
						$this->render_last_result_notice( $last_result );
					}

					$this->render_cockpit( count( $scales ), count( $discrepancies ) );
					$this->render_tab_scales( $scales, is_array( $last_result ) ? $last_result : null, $discrepancies );
					?>
				</div>
			</div>
		</div>
		<?php
		$this->render_inline_scripts();
	}

	/**
	 * Renderiza la franja de métricas ejecutivas (Cockpit).
	 *
	 * @param int $scales_count        Total escalas detectadas.
	 * @param int $discrepancies_count Series con discrepancia.
	 * @return void
	 */
	private function render_cockpit( int $scales_count, int $discrepancies_count ): void {
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
		</div>
		<?php
	}

	/**
	 * Renderiza el aviso o panel de revisión persistente del resultado de la última operación.
	 *
	 * @param array<string, mixed> $last_result Resultado almacenado en transient.
	 * @return void
	 */
	private function render_last_result_notice( array $last_result ): void { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh
		$is_dry_run = ! empty( $last_result['dry_run'] );
		$is_scale   = isset( $last_result['scale_regular'], $last_result['new_regular'] );

		if ( $is_dry_run && $is_scale ) :
			$affected_count    = (int) ( $last_result['updated_count'] ?? 0 );
			$unchanged         = (int) ( $last_result['unchanged_count'] ?? 0 );
			$serie_id          = (int) ( $last_result['serie_id'] ?? 0 );
			$items             = (array) ( $last_result['items'] ?? array() );
			$series_in_items   = $this->collect_series_in_items( $items );
			$scoped_serie_name = ( $serie_id > 0 && isset( $series_in_items[ $serie_id ] ) )
				? (string) $series_in_items[ $serie_id ]['name']
				: '';
			?>
			<div class="akb-pm-proposal-review akb-proposal-banner" style="background:#f0f6fc;border:2px solid #2271b1;border-radius:8px;padding:16px 20px;margin:18px 0 24px;box-shadow:0 2px 6px rgba(0,0,0,0.06);">
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
							Nuevo Regular: <strong style="color:#2271b1;font-size:15px;">$<?php echo number_format( (int) $last_result['new_regular'], 0, ',', '.' ); ?></strong>
							<?php
							$prop_sale = isset( $last_result['target_sale'] ) ? (int) $last_result['target_sale'] : 0;
							if ( $prop_sale > 0 ) : ?>
								| Nuevo Oferta: <strong style="color:#b32d2e;font-size:15px;">$<?php echo number_format( $prop_sale, 0, ',', '.' ); ?></strong>
							<?php else : ?>
								| Oferta: <strong style="color:#50575e;font-size:14px;">Sin oferta (0)</strong>
							<?php endif; ?>
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
							<input type="hidden" name="manual_sale" value="<?php echo isset( $last_result['target_sale'] ) ? (int) $last_result['target_sale'] : 0; ?>" />
							<input type="hidden" name="serie_id" id="akb-proposal-form-serie-id" value="<?php echo (int) $serie_id; ?>" />
							<input type="hidden" name="protect_active_orders" value="0" />
							<button type="submit" class="button button-primary button-hero akb-btn-apply-submit" style="font-size:14px;padding:8px 20px;height:auto;font-weight:700;">
								Confirmar y Aplicar a los <span id="akb-proposal-btn-count"><?php echo (int) $affected_count; ?></span> tomos revisados
							</button>
						</form>
					</div>
				</div>

				<?php if ( ! empty( $items ) ) : ?>
					<?php $this->render_proposal_items_table( $items, $series_in_items, $serie_id, $affected_count ); ?>
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
						Actualizados en catálogo: <strong><?php echo (int) ( $last_result['updated_count'] ?? 0 ); ?> tomos</strong>.
					</span>
				<?php endif; ?>
			</div>
		<?php endif;
	}

	/**
	 * Agrupa y ordena las series únicas presentes en los tomos simulados.
	 *
	 * @param array<int, mixed> $items Lista de tomos simulados.
	 * @return array<int, array{id: int, name: string, count: int}>
	 */
	private function collect_series_in_items( array $items ): array {
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
				++$series_in_items[ $s_id ]['count'];
			}
		}
		uasort(
			$series_in_items,
			static function ( array $a, array $b ): int {
				return strcasecmp( (string) $a['name'], (string) $b['name'] );
			}
		);
		return $series_in_items;
	}

	/**
	 * Renderiza la tabla desplegable de tomos afectados dentro del banner de propuesta.
	 *
	 * @param array<int, mixed>                  $items           Lista de tomos evaluados.
	 * @param array<int, array<string, mixed>>   $series_in_items Series detectadas.
	 * @param int                                $serie_id        ID de serie filtrada.
	 * @param int                                $affected_count  Cantidad de tomos afectados.
	 * @return void
	 */
	private function render_proposal_items_table( array $items, array $series_in_items, int $serie_id, int $affected_count ): void { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh
		?>
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
								<th style="width:160px;">Precio Regular</th>
								<th style="width:160px;">Precio Oferta</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $items as $it ) : ?>
								<?php if ( in_array( $it['status'] ?? '', array( 'would_update', 'updated' ), true ) ) : ?>
									<tr class="akb-proposal-row" data-serie-id="<?php echo (int) ( $it['serie_id'] ?? 0 ); ?>" data-search="<?php echo esc_attr( strtolower( ($it['title'] ?? '') . ' ' . ($it['serie_name'] ?? '') . ' ' . ($it['product_id'] ?? '') ) ); ?>">
										<td><code>#<?php echo (int) ( $it['product_id'] ?? 0 ); ?></code></td>
										<td><strong><?php echo esc_html( (string) ( $it['title'] ?? '' ) ); ?></strong></td>
										<td><?php echo esc_html( (string) ( $it['serie_name'] ?? '—' ) ); ?></td>
										<td><code><?php echo esc_html( (string) ( $it['stock_status'] ?? '' ) ); ?></code></td>
										<td>
											<del style="color:#8c8f94;">$<?php echo number_format( (int) ( $it['old_regular'] ?? 0 ), 0, ',', '.' ); ?></del> ➔
											<strong style="color:#2271b1;">$<?php echo number_format( (int) ( $it['new_regular'] ?? 0 ), 0, ',', '.' ); ?></strong>
										</td>
										<td>
											<?php
											$old_s = isset( $it['old_sale'] ) && $it['old_sale'] > 0 ? '$' . number_format( (int) $it['old_sale'], 0, ',', '.' ) : 'Sin oferta';
											$new_s = isset( $it['new_sale'] ) && $it['new_sale'] > 0 ? '$' . number_format( (int) $it['new_sale'], 0, ',', '.' ) : 'Sin oferta';
											?>
											<span style="color:#8c8f94;"><?php echo esc_html( $old_s ); ?></span> ➔
											<strong style="<?php echo ( isset( $it['new_sale'] ) && $it['new_sale'] > 0 ) ? 'color:#b32d2e;' : 'color:#50575e;'; ?>">
												<?php echo esc_html( $new_s ); ?>
											</strong>
										</td>
									</tr>
								<?php endif; ?>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>
		</details>
		<?php
	}

	/**
	 * Renderiza la Matriz de Escalas por Editorial.
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

					<div class="akb-pm-table-responsive" tabindex="0" role="region" aria-label="Auditoría de series con precios discrepantes" style="max-height:280px;overflow:auto;background:#fff;border-radius:4px;border:1px solid #e0c878;">
						<table class="akb-pm-table" style="font-size:12px;margin:0;">
							<thead>
								<tr>
									<th scope="col" style="width:130px;">Editorial</th>
									<th scope="col" style="width:200px;">Serie</th>
									<th scope="col">Escalas Detectadas (Clic para elegir)</th>
									<th scope="col" style="width:70px;text-align:center;">Tomos</th>
									<th scope="col" style="width:310px;">Nivelar en 1 Clic</th>
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
													<input type="number" id="banner-target-reg-<?php echo esc_attr( $row_key ); ?>" name="target_regular" class="akb-pm-input-price" value="<?php echo (int) $d['dominant_scale']; ?>" step="1" min="100" aria-label="<?php echo esc_attr( sprintf( 'Precio normal para %s', (string) $d['serie_name'] ) ); ?>" style="width:100%;font-size:12px;padding:3px 6px;" required />
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

			<div class="akb-pm-filter-toolbar">
				<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
					<input type="search" id="akb-scales-search" class="akb-pm-search-input" placeholder="🔍 Filtrar por editorial, país o serie..." aria-label="Filtrar escalas de precios por editorial, país o serie" autocomplete="off" />
					<label class="akb-pm-filter-pending-label" style="display:inline-flex;align-items:center;gap:6px;font-size:13px;font-weight:600;color:#2c3338;cursor:pointer;user-select:none;">
						<input type="checkbox" id="akb-scale-filter-pending" name="filter_pending" value="1" />
						<span>Solo con diferencias / pendientes</span>
					</label>
					<button type="button" id="akb-scale-filter-reset" class="button button-secondary" style="font-size:12px;">
						Limpiar filtros
					</button>
				</div>
				<span id="akb-scale-filter-count" class="akb-scales-counter" aria-live="polite" style="font-size:12px;font-weight:600;color:#50575e;">
					Mostrando <strong><?php echo count( $scales ); ?></strong> de <?php echo count( $scales ); ?> escalas
				</span>
			</div>

			<div class="akb-pm-table-responsive">
				<table class="akb-pm-table" id="akb-pricing-scales-table">
					<thead>
						<tr>
							<th scope="col" class="akb-sortable" data-sort="string" style="width:150px;cursor:pointer;">Editorial ↕</th>
							<th scope="col" class="akb-sortable" data-sort="string" style="width:55px;text-align:center;cursor:pointer;">País ↕</th>
							<th scope="col" class="akb-sortable" data-sort="number" style="width:115px;cursor:pointer;">Precio Actual ↕</th>
							<th scope="col" class="akb-sortable" data-sort="number" style="width:65px;text-align:center;cursor:pointer;">Total ↕</th>
							<th scope="col" class="akb-sortable" data-sort="number" style="width:65px;text-align:center;cursor:pointer;">Stock ↕</th>
							<th scope="col" class="akb-sortable" data-sort="number" style="width:70px;text-align:center;cursor:pointer;">Agotado ↕</th>
							<th scope="col" style="min-width:160px;">Series en esta escala</th>
							<th scope="col" style="width:280px;">Modificar Precios (Normal y Oferta)</th>
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
	// phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh
	private function render_scale_row( array $row, ?array $last_result = null ): void { // @phpstan-ignore complexity.functionLike
		$is_draft_active = $last_result && ! empty( $last_result['dry_run'] )
			&& ( $row['editorial_slug'] === ( $last_result['editorial_slug'] ?? '' ) )
			&& ( (int) $row['escala_precio_regular'] === (int) ( $last_result['scale_regular'] ?? 0 ) );

		if ( $is_draft_active ) {
			$row['nuevo_precio_regular'] = (int) $last_result['new_regular'];
		}

		$has_difference = ( (int) $row['escala_precio_regular'] !== (int) $row['nuevo_precio_regular'] ) || $is_draft_active;

		$uid     = sanitize_html_class( $row['editorial_slug'] . '-' . $row['escala_precio_regular'] );
		$id_reg  = 'reg-' . $uid;
		$id_sale = 'sale-' . $uid;

		$initial_sale = 0;
		if ( $is_draft_active && isset( $last_result['target_sale'] ) ) {
			$initial_sale = (int) $last_result['target_sale'];
		} elseif ( null !== $row['min_sale_actual'] ) {
			$initial_sale = (int) $row['min_sale_actual'];
		}
		?>
		<tr class="akb-scale-searchable-row <?php echo $is_draft_active ? 'akb-row-draft-active' : ''; ?>" data-search="<?php echo esc_attr( strtolower( $row['editorial'] . ' ' . $row['editorial_slug'] . ' ' . $row['pais_codigo'] . ' ' . implode( ' ', $row['series_names'] ?? array() ) ) ); ?>" data-has-difference="<?php echo $has_difference ? '1' : '0'; ?>" data-pending="<?php echo $has_difference ? '1' : '0'; ?>" <?php echo $is_draft_active ? 'style="background:#f0f6fc;"' : ''; ?>>
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
				<br />
				<?php if ( null !== $row['min_sale_actual'] && $row['min_sale_actual'] > 0 ) : ?>
					<span class="akb-pm-sale-indicator" style="font-size:11px;color:#0d6832;font-weight:600;">Oferta: $<?php echo esc_html( number_format( (int) $row['min_sale_actual'], 0, ',', '.' ) ); ?></span>
				<?php else : ?>
					<span class="akb-pm-sale-indicator" style="font-size:11px;color:#8c8f94;">Sin oferta</span>
				<?php endif; ?>
			</td>
			<td data-value="<?php echo (int) $row['total_tomos']; ?>" class="akb-pm-col-total" data-label="Total" style="text-align:center;">
				<span class="akb-pm-number-badge"><?php echo (int) $row['total_tomos']; ?></span>
			</td>
			<td data-value="<?php echo (int) $row['en_stock']; ?>" class="akb-pm-col-stock" data-label="Stock" style="text-align:center;">
				<span style="font-weight:700;color:#0d6832;"><?php echo (int) $row['en_stock']; ?></span>
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

					<div class="akb-pm-scale-calc-grid" style="display:grid;grid-template-columns:repeat(2, 1fr);gap:8px;">
						<div class="akb-pm-calc-field">
							<label for="<?php echo esc_attr( $id_reg ); ?>" title="Nuevo precio regular para esta escala">Precio Normal ($)</label>
							<input type="number" id="<?php echo esc_attr( $id_reg ); ?>" name="new_regular" class="akb-calc-reg" value="<?php echo (int) $row['nuevo_precio_regular']; ?>" step="1" min="100" aria-label="Precio regular para <?php echo esc_attr( $row['editorial'] ); ?>" required />
						</div>
						<div class="akb-pm-calc-field">
							<label for="<?php echo esc_attr( $id_sale ); ?>" title="Precio de oferta para esta escala (0 = Sin oferta)">Precio Oferta ($)</label>
							<input type="number" id="<?php echo esc_attr( $id_sale ); ?>" name="manual_sale" class="akb-calc-sale" value="<?php echo (int) $initial_sale; ?>" step="1" min="0" placeholder="0 = Sin oferta" aria-label="Precio oferta para <?php echo esc_attr( $row['editorial'] ); ?>" />
							<span style="font-size:10px;color:#646970;display:block;margin-top:2px;">0 = Sin oferta</span>
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
									var priceCell = row.querySelector('.akb-pm-col-price strong');
									if (priceCell && json.data && json.data.new_regular_fmt) {
										priceCell.textContent = json.data.new_regular_fmt;
									}
									var saleCell = row.querySelector('.akb-pm-sale-indicator');
									if (saleCell && json.data) {
										if (json.data.has_sale) {
											saleCell.textContent = 'Oferta: ' + json.data.new_sale_fmt;
											saleCell.style.color = '#0d6832';
											saleCell.style.fontWeight = '600';
										} else {
											saleCell.textContent = 'Sin oferta';
											saleCell.style.color = '#8c8f94';
											saleCell.style.fontWeight = 'normal';
										}
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
			var scalesInput   = document.getElementById('akb-scales-search');
			var scalesTable   = document.getElementById('akb-pricing-scales-table');
			var pendingCheck  = document.getElementById('akb-scale-filter-pending');
			var resetBtn      = document.getElementById('akb-scale-filter-reset');
			var scalesCounter = document.getElementById('akb-scale-filter-count') || document.getElementById('akb-scales-counter');

			if (scalesTable) {
				var scalesRows = Array.from(scalesTable.querySelectorAll('tbody tr.akb-scale-searchable-row'));

				function filterScalesRows() {
					var query       = scalesInput ? scalesInput.value.toLowerCase().trim() : '';
					var onlyPending = pendingCheck ? pendingCheck.checked : false;
					var visible     = 0;

					scalesRows.forEach(function(row) {
						var text      = row.getAttribute('data-search') || row.textContent.toLowerCase();
						var isPending = row.getAttribute('data-has-difference') === '1' || row.getAttribute('data-pending') === '1';

						var matchQuery   = (!query || text.indexOf(query) !== -1);
						var matchPending = (!onlyPending || isPending);

						if (matchQuery && matchPending) {
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
				}

				if (scalesInput) {
					scalesInput.addEventListener('input', filterScalesRows);
				}
				if (pendingCheck) {
					pendingCheck.addEventListener('change', filterScalesRows);
				}
				if (resetBtn) {
					resetBtn.addEventListener('click', function() {
						if (scalesInput) {
							scalesInput.value = '';
						}
						if (pendingCheck) {
							pendingCheck.checked = false;
						}
						scalesRows.forEach(function(row) {
							var serieSelect = row.querySelector('.akb-calc-serie-select');
							if (serieSelect) {
								serieSelect.selectedIndex = 0;
								updateScopeButtons(serieSelect);
							}
						});
						filterScalesRows();
						if (scalesInput) {
							scalesInput.focus();
						}
					});
				}
			}

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
