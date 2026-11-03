<?php
/**
 * Tab 5: Transferencias Bancarias (BACS) & Blindaje Logístico.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

use Akibara\Business\Support\BusinessOptions;

defined( 'ABSPATH' ) || exit;
?>
<div class="card" style="max-width:900px; padding:20px;">
	<h2>5. Transferencias Bancarias (BACS) & Blindaje Logístico</h2>
	<table class="form-table">
		<tr>
			<th scope="row">Ciclo de Vida de Transferencias (BACS)</th>
			<td>
				<label>
					<input type="checkbox" name="<?php echo esc_attr( BusinessOptions::OPT_BACS_AUTO_CANCEL_ENABLED ); ?>" value="yes" <?php checked( BusinessOptions::bacs_auto_cancel_enabled() ); ?>>
					Cancelar automáticamente pedidos en <code>on-hold</code> impagos para liberar stock y puntos
				</label>
				<br><br>
				Aviso / Recordatorio de Pago: <input type="number" name="<?php echo esc_attr( BusinessOptions::OPT_BACS_REMINDER_HOURS ); ?>" value="<?php echo esc_attr( (string) BusinessOptions::bacs_reminder_hours() ); ?>" class="tiny-text"> horas tras la compra.<br><br>
				Plazo de Expiración Máximo: <input type="number" name="<?php echo esc_attr( BusinessOptions::OPT_BACS_EXPIRY_HOURS ); ?>" value="<?php echo esc_attr( (string) BusinessOptions::bacs_expiry_hours() ); ?>" class="tiny-text"> horas (pasa a <code>cancelled</code> si no hay comprobante).<br><br>
				Prórroga por Subir Comprobante («Ya Transferí»): +<input type="number" name="<?php echo esc_attr( BusinessOptions::OPT_BACS_RECEIPT_GRACE_HOURS ); ?>" value="<?php echo esc_attr( (string) BusinessOptions::bacs_receipt_grace_hours() ); ?>" class="tiny-text"> horas de gracia para revisión administrativa.
			</td>
		</tr>
		<tr>
			<th scope="row">Envío Gratis y Cupones de Pack</th>
			<td>
				<label>
					<input type="checkbox" name="<?php echo esc_attr( BusinessOptions::OPT_FSP_ENABLED ); ?>" value="yes" <?php checked( 'yes', get_option( BusinessOptions::OPT_FSP_ENABLED, 'yes' ) ); ?>>
					Activar barra de progreso de Envío Gratis
				</label>
				<br><br>
				Umbral Blue Express Domicilio: $<input type="number" name="<?php echo esc_attr( BusinessOptions::OPT_FSP_THRESHOLD ); ?>" value="<?php echo esc_attr( (string) get_option( BusinessOptions::OPT_FSP_THRESHOLD, 60000 ) ); ?>" class="small-text"> CLP<br><br>
				<label>
					<input type="checkbox" name="<?php echo esc_attr( BusinessOptions::OPT_FSP_IGNORE_BUNDLE_DISCOUNTS ); ?>" value="yes" <?php checked( BusinessOptions::fsp_ignore_bundle_discounts() ); ?>>
					Ignorar descuento del Pack al evaluar el umbral de Envío Gratis (Defecto: NO, WooCommerce evalúa neto).
				</label>
			</td>
		</tr>
		<tr>
			<th scope="row">Blindaje de Envíos Mixtos y Reembolsos</th>
			<td>
				<label>
					<input type="checkbox" name="<?php echo esc_attr( BusinessOptions::OPT_MIXED_CART_SINGLE_SHIPMENT_POLICY ); ?>" value="yes" <?php checked( BusinessOptions::mixed_cart_single_shipment_policy() ); ?>>
					Exigir checkbox obligatorio de <strong>Despacho Único Consolidado</strong> en pedidos con stock + reserva
				</label>
				<br><br>
				<label>
					<input type="checkbox" name="<?php echo esc_attr( BusinessOptions::OPT_SB_CLAWBACK_ON_PARTIAL_REFUND ); ?>" value="yes" <?php checked( BusinessOptions::sb_clawback_on_partial_refund() ); ?>>
					Re-liquidar y descontar beneficio de pack si un reembolso voluntario baja el pedido de 5 tomos
				</label>
			</td>
		</tr>
	</table>
</div>
