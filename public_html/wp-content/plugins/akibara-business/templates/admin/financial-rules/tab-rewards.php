<?php
/**
 * Tab 3: Puntos & Lealtad (Programa de Fidelización).
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

use Akibara\Business\Support\BusinessOptions;

defined( 'ABSPATH' ) || exit;
?>
<div class="card" style="max-width:900px; padding:20px;">
	<h2>3. Puntos & Lealtad (Programa de Fidelización)</h2>
	<table class="form-table">
		<tr>
			<th scope="row">Acumulación de Puntos</th>
			<td>
				<label><input type="checkbox" name="<?php echo esc_attr( BusinessOptions::OPT_REWARDS_EARN_ENABLED ); ?>" value="yes" <?php checked( 'yes', get_option( BusinessOptions::OPT_REWARDS_EARN_ENABLED, 'yes' ) ); ?>> Activar acumulación de puntos por compra</label><br><br>
				Tasa: <input type="number" name="<?php echo esc_attr( BusinessOptions::OPT_REWARDS_EARN_POINTS ); ?>" value="<?php echo esc_attr( (string) get_option( BusinessOptions::OPT_REWARDS_EARN_POINTS, 2 ) ); ?>" class="tiny-text"> puntos por cada $<input type="number" name="<?php echo esc_attr( BusinessOptions::OPT_REWARDS_EARN_AMOUNT ); ?>" value="<?php echo esc_attr( (string) get_option( BusinessOptions::OPT_REWARDS_EARN_AMOUNT, 100 ) ); ?>" class="small-text"> CLP (Equivale a 2.0% Cashback).
			</td>
		</tr>
		<tr>
			<th scope="row">Tramos Escalonados de Canje</th>
			<td>
				Piso Mínimo de Carrito (Tramo 1): $<input type="number" name="<?php echo esc_attr( BusinessOptions::OPT_REWARDS_REDEEM_MIN_CART ); ?>" value="<?php echo esc_attr( (string) BusinessOptions::rewards_redeem_min_cart() ); ?>" class="small-text"> CLP<br>
				Tope de Canje Tramo 1 ($25k a $49.999): $<input type="number" name="<?php echo esc_attr( BusinessOptions::OPT_REWARDS_REDEEM_TIER1_MAX_AMOUNT ); ?>" value="<?php echo esc_attr( (string) BusinessOptions::rewards_redeem_tier1_max_amount() ); ?>" class="small-text"> CLP<br><br>
				Piso Entrada Tramo 2: $<input type="number" name="<?php echo esc_attr( BusinessOptions::OPT_REWARDS_REDEEM_TIER2_MIN_CART ); ?>" value="<?php echo esc_attr( (string) BusinessOptions::rewards_redeem_tier2_min_cart() ); ?>" class="small-text"> CLP<br>
				Tope de Canje Tramo 2 ($50.000+): $<input type="number" name="<?php echo esc_attr( BusinessOptions::OPT_REWARDS_REDEEM_MAX_AMOUNT ); ?>" value="<?php echo esc_attr( (string) BusinessOptions::rewards_redeem_max_amount() ); ?>" class="small-text"> CLP
			</td>
		</tr>
		<tr>
			<th scope="row">Estado de Acreditación y Conmutación en Checkout</th>
			<td>
				Estado que acredita puntos al pagar (Stock, Mixtos y A Pedido):
				<select name="<?php echo esc_attr( BusinessOptions::OPT_REWARDS_AWARD_STATUS ); ?>">
					<option value="processing" <?php selected( BusinessOptions::rewards_award_status(), 'processing' ); ?>>Procesando (processing — Al confirmar el pago)</option>
					<option value="completed" <?php selected( BusinessOptions::rewards_award_status(), 'completed' ); ?>>Completado (completed)</option>
				</select>
				<p class="description">Si el pedido se cancela o reembolsa antes o después del despacho, el motor deduce automáticamente los puntos acreditados.</p>
				<br>
				<label>
					<input type="checkbox" name="<?php echo esc_attr( BusinessOptions::OPT_REWARDS_MANUAL_SWITCH_BELOW_BUNDLE ); ?>" value="yes" <?php checked( BusinessOptions::rewards_allow_manual_switch_below_bundle() ); ?>>
					Permitir en Checkout de Bloques cambiar a Puntos aunque el descuento del pack sea mayor (para conservar Envío Gratis)
				</label>
			</td>
		</tr>
	</table>
</div>
