<?php
/**
 * Tab 4: Bienvenida & Programa de Referidos.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

use Akibara\Business\Support\BusinessOptions;

defined( 'ABSPATH' ) || exit;
?>
<div class="card" style="max-width:900px; padding:20px;">
	<h2>4. Bienvenida & Programa de Referidos</h2>
	<table class="form-table">
		<tr>
			<th scope="row">Parámetros del Cupón de Invitado</th>
			<td>
				Descuento Invitado: $<input type="number" id="akb_ref_guest_disc" name="<?php echo esc_attr( BusinessOptions::OPT_REFERRAL_GUEST_DISCOUNT_AMOUNT ); ?>" value="<?php echo esc_attr( (string) BusinessOptions::referral_guest_discount_amount() ); ?>" class="small-text"> CLP<br><br>
				Compra Mínima Invitado: $<input type="number" id="akb_ref_min_cart" name="<?php echo esc_attr( BusinessOptions::OPT_REFERRAL_MIN_CART_AMOUNT ); ?>" value="<?php echo esc_attr( (string) BusinessOptions::referral_min_cart_amount() ); ?>" class="small-text"> CLP<br><br>
				Puntos Padrino al Completar: <input type="number" id="akb_ref_sponsor_pts" name="<?php echo esc_attr( BusinessOptions::OPT_REWARDS_REFERRAL_POINTS ); ?>" value="<?php echo esc_attr( (string) BusinessOptions::referral_points() ); ?>" class="small-text"> pts ($1.000 CLP)<br><br>
				Prioridad del Hook: <input type="number" name="<?php echo esc_attr( BusinessOptions::OPT_REFERRAL_AUTO_APPLY_PRIORITY ); ?>" value="<?php echo esc_attr( (string) BusinessOptions::referral_auto_apply_priority() ); ?>" class="small-text"> (Defecto: 1100, para no canibalizar Packs).<br><br>
				<label>
					<input type="checkbox" name="<?php echo esc_attr( BusinessOptions::OPT_REFERRAL_ALLOW_BACKORDER_ITEMS ); ?>" value="yes" <?php checked( BusinessOptions::referral_allow_backorder_items() ); ?>>
					Permitir canjear en carritos con tomos "A Pedido" (5% reserva)
				</label>
			</td>
		</tr>
		<tr>
			<th scope="row">Calculadora de Subsidio en Vivo (Auditoría Techo 15%)</th>
			<td>
				<div id="akb_subsidy_calculator_box" style="padding:15px; border:1px solid #c3c4c7; background:#fff; border-radius:4px;">
					<p style="margin-top:0;"><strong>Auditoría de Subsidio Total por Primera Compra:</strong></p>
					<div id="akb_subsidy_badge" style="display:inline-block; padding:6px 12px; border-radius:4px; font-weight:bold; font-size:14px; background:#46b450; color:#fff;">
						Subsidio Total: 13,53% (Bajo el Techo del 15,0%)
					</div>
					<p class="description" style="margin-top:8px;" id="akb_subsidy_details">
						Suma: $2.000 (descuento) + $500 (bono 1.ª compra) + $1.000 (padrino) + $560 (2% cashback en $28.000) = $4.060 sobre $30.000.
					</p>
				</div>
			</td>
		</tr>
	</table>
</div>
