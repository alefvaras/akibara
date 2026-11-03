<?php
/**
 * Template: Tarjeta de Casillero Individual de Pedido en Bodega.
 *
 * @package Akibara\Business
 * @var array<string, mixed> $view_data
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

use Akibara\Business\Modules\OrderPreventa\PreventaItems;
use Akibara\Business\Modules\OrderPreventa\PreventasScreen;

/** @var \WC_Order $wc_order */
$wc_order                = $view_data['order'];
$oid                     = (int) ( $view_data['oid'] ?? $wc_order->get_id() );
$creado                  = $view_data['creado'] ?? $wc_order->get_date_created();
$dias                    = (int) ( $view_data['dias'] ?? 0 );
$cliente                 = (string) ( $view_data['cliente'] ?? '' );
$url                     = (string) ( $view_data['url'] ?? $wc_order->get_edit_order_url() );
$wa_url                  = (string) ( $view_data['wa_url'] ?? PreventasScreen::url_whatsapp_pedido( $wc_order ) );
$listos                  = (array) ( $view_data['listos'] ?? array() );
$pendientes              = (array) ( $view_data['pendientes'] ?? array() );
$listos_count            = (int) ( $view_data['listos_count'] ?? 0 );
$total_count             = (int) ( $view_data['total_count'] ?? 0 );
$faltan_count            = (int) ( $view_data['faltan_count'] ?? 0 );
$hay_en_stock_sin_sellar = ! empty( $view_data['hay_en_stock_sin_sellar'] );
$promesa_txt             = (string) ( $view_data['promesa_txt'] ?? 'Sin fecha fija' );
?>

<div style="background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:16px 20px;margin-bottom:16px;box-shadow:0 1px 1px rgba(0,0,0,.04);">
	<div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;border-bottom:1px solid #eee;padding-bottom:10px;margin-bottom:12px;">
		<h3 style="margin:0;">
			<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer"><strong>Pedido #<?php echo (int) $oid; ?></strong></a> — <?php echo esc_html( $cliente ); ?>
			<?php if ( '' !== $wa_url ) : ?>
				<a class="button button-small" href="<?php echo esc_url( $wa_url ); ?>" target="_blank" rel="noopener noreferrer" style="color:#075E54;font-weight:600;margin-left:6px;vertical-align:middle;">💬 WhatsApp</a>
			<?php endif; ?>
			<span style="font-size:13px;font-weight:normal;color:#666;">(hace <?php echo (int) $dias; ?> días · <?php echo esc_html( $creado instanceof \DateTimeInterface ? $creado->format( 'Y-m-d' ) : '' ); ?>)</span>
		</h3>
		<div style="font-size:13px;"><strong>Promesa de entrega:</strong> ~<?php echo esc_html( $promesa_txt ); ?></div>
	</div>

	<!-- Barra de progreso -->
	<div style="margin-bottom:14px;">
		<span style="font-weight:600;color:<?php echo 0 === $faltan_count ? '#006505' : '#856404'; ?>;">
			📦 <?php echo (int) $listos_count; ?> de <?php echo (int) $total_count; ?> tomos listos en bodega
		</span> 
		<span style="color:#666;">(Faltan <?php echo (int) $faltan_count; ?>)</span>
	</div>

	<div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(280px, 1fr));gap:16px;">
		<!-- Columna 1: En bodega / Apartados -->
		<div style="background:#f9fbf9;border:1px solid #c3e6cb;border-radius:3px;padding:10px 14px;">
			<h4 style="margin:0 0 8px;color:#155724;">✅ En bodega / Apartados (<?php echo (int) $listos_count; ?>):</h4>
			<?php if ( empty( $listos ) ) : ?>
				<p style="color:#777;font-size:12px;margin:0;">Ningún tomo ha llegado aún.</p>
			<?php else : ?>
				<ul style="margin:0;padding-left:16px;font-size:13px;line-height:1.6;">
					<?php foreach ( $listos as $item ) : ?>
						<?php $sealed = PreventaItems::is_item_sealed( $wc_order, $item ); ?>
						<li style="margin-bottom:4px;">
							<?php echo esc_html( $item->get_name() ); ?>
							<?php if ( $sealed ) : ?>
								<span style="color:#006505;font-weight:600;">🔒 Apartado en casillero</span>
								&nbsp; 
								<a class="button button-small" href="<?php echo esc_url( PreventasScreen::url_desapartar_item( $oid, $item->get_id() ) ); ?>" onclick="return confirm('¿Deshacer el apartado de este tomo en casillero?');" title="Deshacer apartado físico">↩️ Deshacer</a>
							<?php else : ?>
								<span style="color:#1e7e34;">📦 En stock hoy (sin sellar)</span>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<?php if ( $hay_en_stock_sin_sellar ) : ?>
				<p style="margin:10px 0 0;">
					<a class="button button-small button-primary" href="<?php echo esc_url( PreventasScreen::url_apartar_pedido( $oid ) ); ?>">🔒 Sellar apartado de estos tomos en casillero</a>
				</p>
			<?php endif; ?>
		</div>

		<!-- Columna 2: Faltan por llegar -->
		<div style="background:#fffdf7;border:1px solid #ffeeba;border-radius:3px;padding:10px 14px;">
			<h4 style="margin:0 0 8px;color:#856404;">⏳ Faltan por llegar (<?php echo (int) $faltan_count; ?>):</h4>
			<?php if ( empty( $pendientes ) ) : ?>
				<p style="color:#006505;font-size:12px;margin:0;"><strong>¡Pedido completo! Listo para empaque y despacho.</strong></p>
			<?php else : ?>
				<ul style="margin:0;padding-left:16px;font-size:13px;line-height:1.6;">
					<?php foreach ( $pendientes as $item ) : ?>
						<?php
						$pid   = PreventaItems::product_id_of( $item );
						$qty_p = PreventaItems::pending_qty( $wc_order, $item );
						?>
						<li style="margin-bottom:4px;">
							<?php echo esc_html( $item->get_name() ); ?>
							<?php if ( $qty_p > 1 ) : ?>
								<strong>(×<?php echo (int) $qty_p; ?>)</strong>
							<?php endif; ?>
							<?php if ( $pid > 0 ) : ?>
								&nbsp; 
								<a class="button button-small" href="<?php echo esc_url( PreventasScreen::url_recibir( $pid ) ); ?>">📦 Recibir</a>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
	</div>
</div>
