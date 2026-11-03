<?php
/**
 * Template: Tab Encargos de SearchAdminSection.
 *
 * @package Akibara\Search
 * @var array<string, mixed> $view_data
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

use Akibara\Search\SearchAdminSection;
use Akibara\Search\TitleRequests;

$filter_status = (string) ( $view_data['filter_status'] ?? 'pending' );
$counts        = (array) ( $view_data['counts'] ?? array() );
$rows          = (array) ( $view_data['rows'] ?? array() );
$updated       = ! empty( $view_data['updated'] );

$filter_labels = array(
	TitleRequests::STATUS_PENDING   => 'Pendientes',
	TitleRequests::STATUS_QUOTED    => 'Cotizados',
	TitleRequests::STATUS_ORDERED   => 'Pedidos a editorial',
	TitleRequests::STATUS_FULFILLED => 'Completados',
	TitleRequests::STATUS_DISMISSED => 'Descartados',
	'all'                           => 'Todos',
);
?>
<?php if ( $updated ) : ?>
	<div class="notice notice-success is-dismissible" style="margin-left:0">
		<p>✅ Solicitud actualizada con éxito.</p>
	</div>
<?php endif; ?>

<h2>Solicitudes de Tomos a Pedido</h2>
<p class="description" style="font-size:14px;margin-bottom:16px">Títulos y tomos solicitados por clientes a través del buscador que no están en bodega física (a pedido, preventa, agotados o sin catálogo). Cada solicitud se conserva en local en MariaDB y suma urgencia en el radar de «Lo más solicitado».</p>

<ul class="subsubsub" style="margin-bottom:16px">
	<?php
	$filter_links = array();
	foreach ( $filter_labels as $key => $label ) {
		$c          = (int) ( $counts[ $key ] ?? 0 );
		$is_current = $key === $filter_status;
		$url        = add_query_arg(
			array(
				'page'   => SearchAdminSection::PAGE_SLUG,
				'sub'    => 'encargos',
				'status' => $key,
			),
			admin_url( 'admin.php' )
		);
		$filter_links[] = sprintf(
			'<li><a href="%s"%s>%s <span class="count">(%d)</span></a></li>',
			esc_url( $url ),
			$is_current ? ' class="current" aria-current="page"' : '',
			esc_html( $label ),
			$c
		);
	}
	echo implode( ' | ', $filter_links ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	?>
</ul>
<div class="clear"></div>

<?php if ( empty( $rows ) ) : ?>
	<div class="akb-empty-card" style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:32px;text-align:center;margin-top:16px">
		<p style="font-size:15px;color:#50575e;margin:0">No hay solicitudes registradas con el estado seleccionado (<strong><?php echo esc_html( $filter_labels[ $filter_status ] ?? $filter_status ); ?></strong>).</p>
	</div>
<?php else : ?>
	<div class="akb-admin-scroll" style="margin-top:12px">
		<table class="widefat striped akb-encargos-table" style="border:1px solid #c3c4c7;min-width:760px">
			<thead>
				<tr>
					<th scope="col" style="width:70px">ID</th>
					<th scope="col" style="width:130px">Fecha</th>
					<th scope="col">Tomo Solicitado</th>
					<th scope="col" style="width:120px">Modalidad</th>
					<th scope="col">Cliente</th>
					<th scope="col">Contacto Rápido</th>
					<th scope="col" style="width:220px">Estado de Gestión</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<?php
					$req_id    = (int) $row->id;
					$req_title = (string) $row->title;
					$editor    = (string) $row->editorial;
					$intent    = (string) $row->status_intended;
					$name      = (string) $row->customer_name;
					$contact   = (string) $row->customer_contact;
					$ctype     = (string) $row->contact_type;
					$cur_stat  = (string) $row->status;
					$notes     = (string) ( $row->notes ?? '' );
					$date_str  = (string) $row->created_at;

					$intent_bg    = '#7c3aed';
					$intent_label = 'A pedido';
					if ( 'preventa' === $intent ) {
						$intent_bg    = '#2563eb';
						$intent_label = 'Preventa';
					} elseif ( 'agotado' === $intent ) {
						$intent_bg    = '#d97706';
						$intent_label = 'Agotado';
					}
					$intent_html = sprintf(
						'<span style="background:%s;color:#fff;font-size:11px;font-weight:700;padding:2px 8px;border-radius:12px;text-transform:uppercase">%s</span>',
						esc_attr( $intent_bg ),
						esc_html( $intent_label )
					);

					$contact_btn = '';
					if ( 'whatsapp' === $ctype ) {
						$clean_phone = (string) preg_replace( '/[^0-9]/', '', $contact );
						if ( strlen( $clean_phone ) === 9 && str_starts_with( $clean_phone, '9' ) ) {
							$clean_phone = '56' . $clean_phone;
						}
						$wa_msg = sprintf(
							'¡Hola %s! Te escribimos de Akibara Manga. Recibimos tu solicitud a pedido de «%s». Queríamos comentarte la disponibilidad y tiempos de entrega estimados:',
							'' !== $name ? $name : 'hola',
							$req_title
						);
						$wa_url = sprintf( 'https://wa.me/%s?text=%s', $clean_phone, rawurlencode( $wa_msg ) );
						$contact_btn = sprintf(
							'<a class="button button-small" style="background:#075E54;color:#fff;border-color:#05423c;font-weight:700;text-decoration:none" href="%s" target="_blank" rel="noopener noreferrer">💬 WhatsApp (%s)</a>',
							esc_url( $wa_url ),
							esc_html( $contact )
						);
					} else {
						$mail_subject = sprintf( 'Tu solicitud de tomo a pedido: %s — Akibara Manga', $req_title );
						$mail_body    = sprintf(
							"Hola %s,\n\nTe escribimos de Akibara Manga respecto a tu solicitud a pedido de «%s».\n\nTenemos la siguiente información sobre disponibilidad y tiempos:",
							'' !== $name ? $name : 'cliente',
							$req_title
						);
						$mail_url     = sprintf( 'mailto:%s?subject=%s&body=%s', rawurlencode( $contact ), rawurlencode( $mail_subject ), rawurlencode( $mail_body ) );
						$contact_btn  = sprintf(
							'<a class="button button-small" href="%s">✉️ Enviar correo (%s)</a>',
							esc_url( $mail_url ),
							esc_html( $contact )
						);
					}

					$status_select = sprintf(
						'<form method="post" action="%s" style="margin:0;display:flex;flex-direction:column;gap:4px">
							<input type="hidden" name="action" value="akb_update_request_status">
							<input type="hidden" name="request_id" value="%d">
							<input type="hidden" name="current_filter" value="%s">
							%s
							<div style="display:flex;gap:4px;align-items:center">
								<select name="new_status" style="font-size:12px;height:28px" aria-label="%s">
									<option value="%s"%s>⏳ Pendiente</option>
									<option value="%s"%s>💬 Cotizado</option>
									<option value="%s"%s>📦 Pedido a editorial</option>
									<option value="%s"%s>✅ Completado</option>
									<option value="%s"%s>❌ Descartado</option>
								</select>
								<button type="submit" class="button button-small">OK</button>
							</div>
							%s
						</form>',
						esc_url( admin_url( 'admin-post.php' ) ),
						$req_id,
						esc_attr( $filter_status ),
						wp_nonce_field( 'akb_update_request_status', '_wpnonce', true, false ),
						esc_attr( sprintf( 'Estado de gestión de la solicitud #%d', $req_id ) ),
						esc_attr( TitleRequests::STATUS_PENDING ),
						selected( $cur_stat, TitleRequests::STATUS_PENDING, false ),
						esc_attr( TitleRequests::STATUS_QUOTED ),
						selected( $cur_stat, TitleRequests::STATUS_QUOTED, false ),
						esc_attr( TitleRequests::STATUS_ORDERED ),
						selected( $cur_stat, TitleRequests::STATUS_ORDERED, false ),
						esc_attr( TitleRequests::STATUS_FULFILLED ),
						selected( $cur_stat, TitleRequests::STATUS_FULFILLED, false ),
						esc_attr( TitleRequests::STATUS_DISMISSED ),
						selected( $cur_stat, TitleRequests::STATUS_DISMISSED, false ),
						'' !== $notes ? '<span style="font-size:11px;color:#646970;font-style:italic">' . esc_html( $notes ) . '</span>' : ''
					);

					$time_label = human_time_diff( (int) strtotime( $date_str ), (int) current_time( 'timestamp' ) );

					$product_link = '';
					if ( isset( $row->product_id ) && (int) $row->product_id > 0 ) {
						$pid          = (int) $row->product_id;
						$product_link = sprintf( '<br><a href="%s" target="_blank" rel="noopener noreferrer" style="font-size:11px;color:#2271b1;text-decoration:none">🔗 Ficha WC #%d ↗</a>', esc_url( admin_url( 'post.php?post=' . $pid . '&action=edit' ) ), $pid );
					}
					?>
					<tr>
						<td data-label="ID" style="font-weight:700;color:#646970">#<?php echo (int) $req_id; ?></td>
						<td data-label="Fecha"><span title="<?php echo esc_attr( $date_str ); ?>">hace <?php echo esc_html( $time_label ); ?></span><br><small style="color:#8c8f94"><?php echo esc_html( substr( $date_str, 0, 10 ) ); ?></small></td>
						<td data-label="Tomo Solicitado"><strong><?php echo esc_html( $req_title ); ?></strong><?php echo '' !== $editor ? '<br><small style="color:#646970">Editorial: ' . esc_html( $editor ) . '</small>' : ''; ?><?php echo $product_link; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						<td data-label="Modalidad"><?php echo $intent_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						<td data-label="Cliente"><?php echo esc_html( '' !== $name ? $name : '—' ); ?></td>
						<td data-label="Contacto Rápido"><?php echo $contact_btn; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						<td data-label="Estado de Gestión"><?php echo $status_select; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<p class="description" style="margin-top:16px">💡 <strong>Flujo sugerido:</strong> Cuando recibas una solicitud, pulsa el botón directo de WhatsApp o Correo para coordinar con el cliente y cambia su estado a <em>Cotizado</em>. Al agregar el tomo a tu pedido editorial márcalo como <em>Pedido a editorial</em>, y cuando se concrete o despache la venta, márcalo como <em>Completado</em>.</p>
<?php endif; ?>
