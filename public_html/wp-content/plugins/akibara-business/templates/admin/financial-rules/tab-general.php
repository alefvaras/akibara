<?php
/**
 * Tab 1: Reglas Generales & Techos.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

use Akibara\Business\Support\BusinessOptions;

defined( 'ABSPATH' ) || exit;
?>
<div class="card" style="max-width:900px; padding:20px;">
	<h2>1. Techo Comercial Máximo Global & Políticas Generales</h2>
	<p>Define los límites infranqueables para proteger el margen de contribución frente a cualquier motor de descuento automatizado.</p>
	<table class="form-table">
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( BusinessOptions::OPT_DISCOUNT_MAX_PCT ); ?>">Techo Comercial Global (% Máximo)</label></th>
			<td>
				<input type="number" id="<?php echo esc_attr( BusinessOptions::OPT_DISCOUNT_MAX_PCT ); ?>" name="<?php echo esc_attr( BusinessOptions::OPT_DISCOUNT_MAX_PCT ); ?>" value="<?php echo esc_attr( (string) BusinessOptions::discount_max_pct() ); ?>" min="5" max="30" class="small-text"> %
				<p class="description">Tope absoluto que restringe a todos los motores automáticos (Arma tu Pack, Descuentos Editoriales y Referidos). Defecto: 15%.</p>
			</td>
		</tr>
		<tr>
			<th scope="row">Descuento Catálogo A Pedido (Reserva)</th>
			<td>
				<label>
					<input type="checkbox" name="<?php echo esc_attr( BusinessOptions::OPT_BACKORDER_DISCOUNT_ENABLED ); ?>" value="yes" <?php checked( BusinessOptions::backorder_discount_enabled() ); ?>>
					Habilitar descuento automático para tomos en estado "A pedido" (<code>onbackorder</code>)
				</label>
				<br><br>
				<input type="number" name="<?php echo esc_attr( BusinessOptions::OPT_BACKORDER_DISCOUNT_PCT ); ?>" value="<?php echo esc_attr( (string) BusinessOptions::backorder_discount_pct() ); ?>" min="0" max="15" class="small-text"> %
				<p class="description">Descuento reflejado automáticamente en <code>_sale_price</code> para productos a pedido. Defecto: 5%.</p>
			</td>
		</tr>
		<tr>
			<th scope="row">Descuentos Editoriales vs Cupones</th>
			<td>
				<label>
					<input type="checkbox" name="<?php echo esc_attr( BusinessOptions::OPT_TAXONOMY_DISCOUNT_ENABLED ); ?>" value="yes" <?php checked( BusinessOptions::taxonomy_discount_enabled() ); ?>>
					Habilitar campañas de descuento editorial por taxonomía
				</label>
				<br><br>
				<label for="<?php echo esc_attr( BusinessOptions::OPT_TAXONOMY_DISCOUNT_COUPON_MODE ); ?>">Modo frente a cupones:</label>
				<select name="<?php echo esc_attr( BusinessOptions::OPT_TAXONOMY_DISCOUNT_COUPON_MODE ); ?>" id="<?php echo esc_attr( BusinessOptions::OPT_TAXONOMY_DISCOUNT_COUPON_MODE ); ?>">
					<option value="best_wins" <?php selected( BusinessOptions::taxonomy_discount_coupon_mode(), 'best_wins' ); ?>>Best-Wins Diferencial (Recomendado: cupones pasan y completan el diferencial)</option>
					<option value="whitelist_only" <?php selected( BusinessOptions::taxonomy_discount_coupon_mode(), 'whitelist_only' ); ?>>Solo Lista Blanca (Pasan HOLA y akb_sb_)</option>
					<option value="strict_block" <?php selected( BusinessOptions::taxonomy_discount_coupon_mode(), 'strict_block' ); ?>>Bloqueo Estricto (Cualquier cupón se rechaza si hay descuento editorial)</option>
				</select>
			</td>
		</tr>
	</table>
</div>
