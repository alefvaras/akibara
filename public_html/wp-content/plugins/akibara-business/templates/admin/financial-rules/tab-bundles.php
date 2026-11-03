<?php
/**
 * Tab 2: Arma tu Pack (SeriesBundles) — Lista Blanca.
 *
 * @package Akibara\Business
 *
 * @var array<int|string, array<string, mixed>> $byp_map
 */

declare( strict_types = 1 );

use Akibara\Business\Support\BusinessOptions;

defined( 'ABSPATH' ) || exit;
?>
<div class="card" style="max-width:1050px; padding:20px;">
	<h2>2. «Arma tu Pack» (SeriesBundles) — Regla Inquebrantable de Lista Blanca</h2>
	<div class="notice notice-warning inline" style="margin:10px 0 20px;">
		<p><strong>ATENCIÓN:</strong> Por orden expresa del dueño, está estrictamente prohibido activar packs por lotes globales. Series de margen estrecho (ej. Editorial Planeta) <strong>NO</strong> participan. Solo reciben descuento de pack las series agregadas manualmente a esta Lista Blanca.</p>
	</div>

	<table class="form-table">
		<tr>
			<th scope="row">Estado Maestro del Módulo</th>
			<td>
				<label>
					<input type="checkbox" name="<?php echo esc_attr( BusinessOptions::OPT_SERIES_BUNDLES_ENABLED ); ?>" value="yes" <?php checked( BusinessOptions::series_bundles_enabled() ); ?>>
					Habilitar módulo «Arma tu Pack» en la tienda
				</label>
			</td>
		</tr>
		<tr>
			<th scope="row">Política Global para Tomos "A Pedido"</th>
			<td>
				<select name="<?php echo esc_attr( BusinessOptions::OPT_SERIES_BUNDLES_BACKORDER_MODE ); ?>">
					<option value="best_wins_topup" <?php selected( BusinessOptions::series_bundles_backorder_mode(), 'best_wins_topup' ); ?>>Política 1: Best-Wins Actual (Aporta diferencial +3% en pack de 5, +5% en pack de 7)</option>
					<option value="count_only_bridge" <?php selected( BusinessOptions::series_bundles_backorder_mode(), 'count_only_bridge' ); ?>>Política 3: Puente de Conteo (Suma unidades para el tramo, pero mantiene 5% fijo de reserva)</option>
					<option value="exclude_backorder" <?php selected( BusinessOptions::series_bundles_backorder_mode(), 'exclude_backorder' ); ?>>Política 2: Exclusión Estricta (Solo cuentan tomos en stock físico)</option>
				</select>
				<p class="description">Define cómo participan los tomos en reserva dentro de los packs de series autorizadas.</p>
			</td>
		</tr>
		<tr>
			<th scope="row">Plantilla Estándar (Mangas Normales)</th>
			<td>
				Tramo 0 (Opcional): <input type="number" name="<?php echo esc_attr( BusinessOptions::OPT_SB_TIER0_MIN_QTY ); ?>" value="<?php echo esc_attr( (string) BusinessOptions::sb_tier0_min_qty() ); ?>" class="tiny-text"> tomos &rarr; <input type="number" name="<?php echo esc_attr( BusinessOptions::OPT_SB_TIER0_PCT ); ?>" value="<?php echo esc_attr( (string) BusinessOptions::sb_tier0_pct() ); ?>" class="tiny-text">% (0 = apagado)<br><br>
				Tramo 1 (Base): <input type="number" name="<?php echo esc_attr( BusinessOptions::OPT_SB_TIER1_MIN_QTY ); ?>" value="<?php echo esc_attr( (string) BusinessOptions::sb_tier1_min_qty() ); ?>" class="tiny-text"> tomos &rarr; <input type="number" name="<?php echo esc_attr( BusinessOptions::OPT_SB_TIER1_PCT ); ?>" value="<?php echo esc_attr( (string) BusinessOptions::sb_tier1_pct() ); ?>" class="tiny-text">% (Defecto: 8%)<br><br>
				Tramo 2 (Completo): <input type="number" name="<?php echo esc_attr( BusinessOptions::OPT_SB_TIER2_MIN_QTY ); ?>" value="<?php echo esc_attr( (string) BusinessOptions::sb_tier2_min_qty() ); ?>" class="tiny-text"> tomos &rarr; <input type="number" name="<?php echo esc_attr( BusinessOptions::OPT_SB_TIER2_PCT ); ?>" value="<?php echo esc_attr( (string) BusinessOptions::sb_tier2_pct() ); ?>" class="tiny-text">% (Defecto: 10%)
			</td>
		</tr>
		<tr>
			<th scope="row">Plantilla Especial / Novelas</th>
			<td>
				Mínimo: <input type="number" name="<?php echo esc_attr( BusinessOptions::OPT_SB_SPECIAL_MIN_QTY ); ?>" value="<?php echo esc_attr( (string) BusinessOptions::sb_special_min_qty() ); ?>" class="tiny-text"> tomos &rarr; Tope Máximo: <input type="number" name="<?php echo esc_attr( BusinessOptions::OPT_SB_SPECIAL_MAX_PCT ); ?>" value="<?php echo esc_attr( (string) BusinessOptions::sb_special_max_pct() ); ?>" class="tiny-text">% (Tope 8% para ediciones de alto valor)
			</td>
		</tr>
		<tr>
			<th scope="row">Desglose de Ahorro en Carrito</th>
			<td>
				<label>
					<input type="checkbox" name="<?php echo esc_attr( BusinessOptions::OPT_SB_SHOW_COMBINED_SAVINGS_UI ); ?>" value="yes" <?php checked( BusinessOptions::sb_show_combined_savings_ui() ); ?>>
					Mostrar leyenda de ahorro total combinado (Reserva + Pack) en resumen del carrito
				</label>
			</td>
		</tr>
	</table>

	<hr style="margin:25px 0;">

	<h3>+ Agregar Serie a la Lista Blanca de «Arma tu Pack»</h3>
	<div style="background:#f9f9f9; padding:15px; border:1px solid #ddd; border-radius:4px; margin-bottom:20px;">
		<label for="akb_series_search_input"><strong>Buscar Serie (pa_serie):</strong></label><br>
		<input type="text" id="akb_series_search_input" placeholder="Escribe el nombre de la serie (ej. Chainsaw Man, Berserk)..." style="width:45%; max-width:340px;">
		<select id="akb_add_profile">
			<option value="template_standard">Plantilla Estándar (8% en 5, 10% en 7)</option>
			<option value="template_special">Plantilla Especial / Novela (Tope 8%)</option>
		</select>
		<select id="akb_add_backorder_mode">
			<option value="inherit">A Pedido: Heredar global</option>
			<option value="best_wins_topup">A Pedido: Best-Wins (+3% / +5%)</option>
			<option value="count_only_bridge">A Pedido: Puente de Conteo (5% fijo)</option>
			<option value="exclude_backorder">A Pedido: Excluir reservas</option>
		</select>
		<button type="button" class="button" id="akb_btn_search_series">Buscar</button>
		<div id="akb_search_results_container" style="margin-top:12px;"></div>
	</div>

	<h3>Series Activas en Lista Blanca (<?php echo count( $byp_map ); ?> series registradas)</h3>
	<table class="widefat striped" id="akb_whitelist_table">
		<thead>
			<tr>
				<th>ID</th>
				<th>Serie</th>
				<th>Perfil Asignado</th>
				<th>Política A Pedido</th>
				<th>Estado</th>
				<th>Acciones</th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $byp_map ) ) : ?>
				<tr><td colspan="6">No hay series autorizadas en la Lista Blanca. Agrega una arriba.</td></tr>
			<?php else : ?>
				<?php foreach ( $byp_map as $akb_term_id => $akb_byp_entry ) :
					$akb_term = get_term( (int) $akb_term_id, 'pa_serie' );
					$akb_name = $akb_term instanceof \WP_Term ? $akb_term->name : 'ID ' . $akb_term_id;
					$akb_enabled = ! empty( $akb_byp_entry['enabled'] );
					$akb_profile = $akb_byp_entry['profile'] ?? ( ( isset( $akb_byp_entry['tiers'] ) && is_array( $akb_byp_entry['tiers'] ) && 1 === count( $akb_byp_entry['tiers'] ) ) ? 'template_special' : 'template_standard' );
					$akb_bo_m    = $akb_byp_entry['backorder_mode'] ?? 'inherit';
				?>
					<tr data-term-id="<?php echo esc_attr( (string) $akb_term_id ); ?>">
						<td><strong>#<?php echo esc_html( (string) $akb_term_id ); ?></strong></td>
						<td><strong><?php echo esc_html( $akb_name ); ?></strong></td>
						<td>
							<?php
							if ( 'template_special' === $akb_profile ) {
								echo '<span class="badge" style="background:#f0ad4e; color:#fff; padding:2px 6px; border-radius:3px;">Especial (Tope 8%)</span>';
							} else {
								echo '<span class="badge" style="background:#0073aa; color:#fff; padding:2px 6px; border-radius:3px;">Estándar (8% / 10%)</span>';
							}
							?>
						</td>
						<td><code><?php echo esc_html( $akb_bo_m ); ?></code></td>
						<td>
							<span class="status-indicator <?php echo $akb_enabled ? 'status-active' : 'status-paused'; ?>" style="color:<?php echo $akb_enabled ? '#46b450' : '#dc3232'; ?>; font-weight:bold;">
								<?php echo $akb_enabled ? '● Activa' : '○ Pausada'; ?>
							</span>
						</td>
						<td>
							<button type="button" class="button button-small akb-toggle-series" data-term-id="<?php echo esc_attr( (string) $akb_term_id ); ?>">
								<?php echo $akb_enabled ? 'Pausar' : 'Activar'; ?>
							</button>
							<button type="button" class="button button-small button-link-delete akb-remove-series" data-term-id="<?php echo esc_attr( (string) $akb_term_id ); ?>">
								Quitar del Pack
							</button>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>
</div>
