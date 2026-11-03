<?php
/**
 * Email "preventa lista" — versión HTML.
 *
 * Override desde theme:
 *   `wp-content/themes/<theme>/woocommerce/emails/customer-preventa-ready.php`
 *
 * @package Akibara\Business
 *
 * @var \WC_Order                              $order              Orden cliente.
 * @var array<int, \WC_Order_Item_Product>     $preventa_items  Líneas en espera vigentes (sin las reembolsadas del todo).
 * @var bool                                   $is_local_pickup    true si la orden es retiro (sin transportista).
 * @var string                                 $email_heading      Heading (configurable).
 * @var string                                 $additional_content Contenido opcional del admin.
 * @var bool                                   $sent_to_admin
 * @var bool                                   $plain_text
 * @var \WC_Email                              $email
 */

defined( 'ABSPATH' ) || exit;

/*
 * @hooked WC_Emails::email_header()
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Hook de WooCommerce core: el override debe emitirlo con su nombre original o los plugins que lo escuchan dejan de recibirlo.
do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<p>
	<?php echo esc_html( \Akibara\Business\Modules\EmailBrand\EmailBrand::saludo( $order ) ); ?>
</p>

<p>
	<?php
	printf(
		esc_html( '¡Buenas noticias! Los productos que esperabas de tu pedido %s ya llegaron a nuestra bodega.' ),
		'<strong>#' . esc_html( (string) $order->get_order_number() ) . '</strong>'
	);
	?>
</p>

<?php if ( ! empty( $preventa_items ) ) : ?>
	<table cellspacing="0" cellpadding="6" style="width:100%;border:1px solid #ece6da;margin-bottom:24px;" border="0">
		<thead>
			<tr>
				<th scope="col" style="text-align:left;background-color:#f8f8f8;color:#1a1a1a;padding:10px 12px;border-bottom:2px solid #E60012;">
					<?php echo esc_html( 'Lo que esperabas' ); ?>
				</th>
				<th scope="col" style="text-align:center;background-color:#f8f8f8;color:#1a1a1a;padding:10px 12px;width:80px;border-bottom:2px solid #E60012;">
					<?php echo esc_html( 'Cantidad' ); ?>
				</th>
			</tr>
		</thead>
		<tbody>
			<?php /* phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Locales del render: wc_get_template() incluye la plantilla DENTRO de una función, no en el ámbito global. */ foreach ( $preventa_items as $item ) : ?>
				<tr>
					<td style="padding:10px 12px;border-top:1px solid #ece6da;">
						<?php echo esc_html( $item->get_name() ); ?>
					</td>
					<td style="padding:10px 12px;border-top:1px solid #ece6da;text-align:center;">
						<?php echo esc_html( (string) \Akibara\Business\Modules\OrderPreventa\PreventaItems::outstanding_qty( $order, $item ) ); ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
<?php endif; ?>

<div class="akb-email-callout" style="margin:20px 0;padding:16px 20px;background:#fffaf3;border:1px solid #141416;border-left:4px solid #E60012;border-radius:0;">
	<?php if ( ! empty( $is_local_pickup ) ) : ?>
		<p style="margin:0 0 6px 0;font-weight:600;">
			<?php echo esc_html( 'Tu pedido es para retiro.' ); ?>
		</p>
		<p style="margin:0;font-size:0.95em;line-height:1.5;color:#555;">
			<?php echo esc_html( 'Te avisaremos por correo cuando esté preparado para que pases a retirarlo. Si tienes dudas, escríbenos y te ayudamos.' ); ?>
		</p>
	<?php else : ?>
		<p style="margin:0 0 6px 0;font-weight:600;">
			<?php echo esc_html( sprintf( 'Despachamos en %s.', \Akibara\Business\Modules\OrderPreventa\ShipPromise::window() ) ); ?>
		</p>
		<p style="margin:0;font-size:0.95em;line-height:1.5;color:#555;">
			<?php echo esc_html( 'Te enviaremos el seguimiento por correo cuando el despacho esté listo.' ); ?>
		</p>
	<?php endif; ?>
</div>

<?php /* El destino sale de `EmailBrand::order_url()`: `view-order` exige sesión y mandaría a un muro de login al comprador INVITADO, que recibe este mismo correo (hallazgo S-7fc2defd). */ ?>
<p style="margin:20px 0;">
	<a class="link akb-email-btn" href="<?php echo esc_url( \Akibara\Business\Modules\EmailBrand\EmailBrand::order_url( $order ) ); ?>" style="display:inline-block;padding:15px 28px;background-color:#E60012;color:#ffffff;-webkit-text-fill-color:#ffffff;text-decoration:none;border-radius:0;text-transform:uppercase;letter-spacing:0.5px;font-weight:bold;line-height:1;">
		<?php echo esc_html( 'Ver mi pedido' ); ?>
	</a>
</p>

<?php /* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ContactFooter::render_html() escapa por dentro: esc_url()/esc_html() en cada enlace. */ echo \Akibara\Business\Modules\OrderPreventa\ContactFooter::render_html( $order ); ?>

<?php if ( '' !== trim( (string) $additional_content ) ) : ?>
	<div style="margin-top:30px;padding-top:20px;border-top:1px solid #e5e5e5;">
		<?php echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) ); ?>
	</div>
<?php endif; ?>

<?php
/*
 * @hooked WC_Emails::email_footer()
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Hook de WooCommerce core: el override debe emitirlo con su nombre original o los plugins que lo escuchan dejan de recibirlo.
do_action( 'woocommerce_email_footer', $email );
