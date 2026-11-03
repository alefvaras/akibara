<?php
/**
 * Template: Formulario de Recepción de Mercadería en Bodega y Apartado en Casillero.
 *
 * @package Akibara\Business
 * @var array<string, mixed> $view_data
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

use Akibara\Business\Modules\OrderPreventa\PreventasScreen;

/** @var \WC_Product $product */
$product        = $view_data['product'];
$pid            = (int) ( $view_data['pid'] ?? $product->get_id() );
$volver         = (string) ( $view_data['volver'] ?? admin_url( 'admin.php?page=akibara-panel&tab=preventas' ) );
$filas_pedidos  = (array) ( $view_data['filas_pedidos'] ?? array() );
$total_unidades = (int) ( $view_data['total_unidades'] ?? 0 );
$action_recibir = (string) ( $view_data['action_recibir'] ?? 'akb_preventa_recibir' );
?>

<div class="akb-preventas">
	<h2>📦 Recepción de mercadería en bodega</h2>
	<h3 style="font-weight:600;margin-top:0;"><?php echo esc_html( $product->get_name() ); ?></h3>

	<?php if ( empty( $filas_pedidos ) ) : ?>
		<div class="notice notice-info inline">
			<p>No hay ningún pedido activo esperando unidades de este tomo ahora mismo.</p>
		</div>
		<p><a class="button" href="<?php echo esc_url( $volver ); ?>">Volver al panel</a></p>
	</div>
		<?php return; ?>
	<?php endif; ?>

	<p>
		Hay <strong><?php echo (int) count( $filas_pedidos ); ?> <?php echo 1 === count( $filas_pedidos ) ? 'pedido' : 'pedidos'; ?></strong> esperando un total de <strong><?php echo (int) $total_unidades; ?> <?php echo 1 === $total_unidades ? 'unidad física' : 'unidades físicas'; ?></strong> de este tomo.
	</p>

	<div style="background:#f8f9fa;border-left:4px solid #0073aa;padding:12px 16px;margin-bottom:20px;border-radius:2px;">
		<h4 style="margin:0 0 8px;">¿Cómo funciona la asignación inteligente?</h4>
		<ul style="margin:0;padding-left:18px;color:#444;font-size:13px;line-height:1.5;">
			<li><strong>Prioridad por antigüedad (FIFO):</strong> Las unidades que indiques se apartan primero para los pedidos que llevan más días esperando.</li>
			<li><strong>Cero sobreventas:</strong> Si llegaron las unidades justas para tus pedidos, se apartan en su casillero sin abrir stock en la tienda web (siguen a pedido para futuros compradores).</li>
			<li><strong>Aviso oportuno al cliente:</strong> El correo «Tu pedido ya llegó» se enviará automáticamente SOLO si este tomo completa el 100% de lo que ese pedido estaba esperando. Si el pedido aún espera otros tomos, quedará como consolidación en progreso sin mandar correos prematuros.</li>
		</ul>
	</div>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="<?php echo esc_attr( $action_recibir ); ?>">
		<input type="hidden" name="product_id" value="<?php echo (int) $pid; ?>">
		<?php wp_nonce_field( $action_recibir . '-' . $pid ); ?>

		<h4 style="margin:16px 0 8px;">1. Pedidos en espera (orden de prioridad por llegada):</h4>
		<table class="widefat striped" style="max-width:800px;margin-bottom:20px;">
			<thead>
				<tr>
					<th scope="col" style="width:40px;">
						<input type="checkbox" id="akb-select-all" checked aria-label="Seleccionar todos los pedidos" onclick="document.querySelectorAll('.akb-order-check').forEach(function(c){c.checked=this.checked;},this)">
					</th>
					<th scope="col">Pedido</th>
					<th scope="col">Cliente</th>
					<th scope="col">Espera</th>
					<th scope="col">Requiere</th>
					<th scope="col">Estado del pedido</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $filas_pedidos as $f ) : ?>
					<?php
					$wa_url = isset( $f['order'] ) && $f['order'] instanceof \WC_Order ? PreventasScreen::url_whatsapp_pedido( $f['order'] ) : '';
					?>
					<tr>
						<td>
							<input type="checkbox" class="akb-order-check" name="order_ids[]" value="<?php echo (int) $f['id']; ?>" checked aria-label="Apartar para pedido #<?php echo (int) $f['id']; ?>">
						</td>
						<td>
							<a href="<?php echo esc_url( (string) $f['url'] ); ?>" target="_blank" rel="noopener noreferrer"><strong>#<?php echo (int) $f['id']; ?></strong></a>
						</td>
						<td>
							<?php echo esc_html( (string) $f['cliente'] ); ?>
							<?php if ( '' !== $wa_url ) : ?>
								<br>
								<a href="<?php echo esc_url( $wa_url ); ?>" target="_blank" rel="noopener noreferrer" style="color:#075E54;font-size:11px;font-weight:600;text-decoration:none;">💬 WhatsApp</a>
							<?php endif; ?>
						</td>
						<td><?php echo (int) $f['dias']; ?> días</td>
						<td><strong><?php echo (int) $f['unidades']; ?> <?php echo 1 === $f['unidades'] ? 'ud' : 'uds'; ?></strong></td>
						<td><span style="font-size:12px;color:#666;"><?php echo (int) $f['listos']; ?> de <?php echo (int) $f['totales']; ?> tomos listos</span></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<h4 style="margin:16px 0 8px;">2. Unidades recibidas físicamente:</h4>
		<p>
			<label>
				<strong>¿Cuántos ejemplares llegaron en la caja?</strong><br>
				<input type="number" name="unidades_recibidas" min="1" step="1" value="<?php echo (int) $total_unidades; ?>" style="width:120px;font-size:16px;padding:4px 8px;margin-top:4px;">
			</label>
		</p>

		<p>
			<label>
				<input type="checkbox" name="publicar_excedente" value="yes" checked> 
				<strong>Si llegaron más unidades que las requeridas por los pedidos, publicar el excedente como «En stock» en la tienda web</strong>
			</label><br>
			<span style="color:#666;font-size:12px;margin-left:22px;">(El sistema configurará el excedente en el catálogo, quitará el descuento de reserva del 5% y purgará la caché para venta inmediata).</span>
		</p>

		<p style="margin-top:24px;">
			<button type="submit" class="button button-primary button-large">📦 Registrar recepción y apartar en casillero</button> 
			<a class="button button-large" href="<?php echo esc_url( $volver ); ?>">Cancelar</a>
		</p>
	</form>
</div>
