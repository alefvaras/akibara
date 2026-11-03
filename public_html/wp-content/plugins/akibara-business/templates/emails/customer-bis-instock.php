<?php
/**
 * Email "volvió a stock" (lista de espera) — versión HTML.
 *
 * Override desde theme:
 *   `wp-content/themes/<theme>/woocommerce/emails/customer-bis-instock.php`
 *
 * @package Akibara\Business
 *
 * @var \WC_Product|null $product            Producto que volvió a habilitarse.
 * @var string           $product_name       Nombre del producto.
 * @var string           $product_url        Permalink del producto.
 * @var string           $product_price_html Precio formateado (HTML de WC).
 * @var string           $product_image_url  URL de la portada (thumbnail) o ''.
 * @var string           $availability_state Estado comercial ('disponible'|'a_pedido'|'preventa').
 * @var string           $availability_label Etiqueta visible del estado ('Disponible'|'A pedido'|'Preventa').
 * @var string           $cutoff_phrase      Frase de corte/viaje cuando aplica.
 * @var string           $timeframe_copy     Plazo claro de despacho o llegada si se pide hoy.
 * @var string           $cta_label          Texto del botón ('Comprar ahora'|'Pedir ahora'|'Reservar preventa').
 * @var string           $footnote_copy      Nota al pie según el estado.
 * @var string           $unsubscribe_url    Baja one-click por token.
 * @var string           $email_heading      Heading configurable.
 * @var string           $additional_content Contenido opcional del admin.
 * @var bool             $sent_to_admin
 * @var bool             $plain_text
 * @var \WC_Email        $email
 */

use Akibara\Business\Modules\Stock\Availability;

defined( 'ABSPATH' ) || exit;

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Hook de WooCommerce core: el override debe emitirlo con su nombre original o los plugins que lo escuchan dejan de recibirlo.
do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<p>¡Buenas noticias!</p>

<p>
	<?php
	if ( Availability::PREVENTA === ( $availability_state ?? '' ) ) {
		printf(
			'%s ya se puede reservar en preventa. Pediste que te avisáramos cuando se habilitara — este es tu aviso.',
			'<strong>' . esc_html( $product_name ) . '</strong>'
		);
	} elseif ( Availability::A_PEDIDO === ( $availability_state ?? '' ) ) {
		printf(
			'%s ya se puede encargar a pedido. Pediste que te avisáramos cuando volviera a habilitarse — este es tu aviso.',
			'<strong>' . esc_html( $product_name ) . '</strong>'
		);
	} else {
		printf(
			'%s está de vuelta en stock. Pediste que te avisáramos — este es tu aviso.',
			'<strong>' . esc_html( $product_name ) . '</strong>'
		);
	}
	?>
</p>

<table class="akb-product-card" cellspacing="0" cellpadding="0" border="0" style="width:100%;margin:20px 0;border:1px solid #141416;border-left:4px solid #E60012;background-color:#fffdf7;">
	<tr>
		<?php if ( '' !== $product_image_url ) : ?>
			<td style="width:96px;padding:14px 0 14px 14px;vertical-align:top;">
				<a href="<?php echo esc_url( $product_url ); ?>">
					<img src="<?php echo esc_url( $product_image_url ); ?>" alt="<?php echo esc_attr( $product_name ); ?>" width="80" style="display:block;width:80px;height:auto;border:1px solid #141416;">
				</a>
			</td>
		<?php endif; ?>
		<td style="padding:14px;vertical-align:top;">
			<p style="margin:0 0 6px 0;font-weight:600;">
				<a href="<?php echo esc_url( $product_url ); ?>" style="color:#1a1a1a;-webkit-text-fill-color:#1a1a1a;text-decoration:none;"><?php echo esc_html( $product_name ); ?></a>
			</p>
			<?php if ( '' !== ( $availability_label ?? '' ) ) : ?>
				<p style="margin:0 0 8px 0;font-size:13px;line-height:1.45;color:#4a4a4a;">
					<span style="display:inline-block;padding:2px 8px;border-radius:0;font-weight:bold;font-size:11px;text-transform:uppercase;letter-spacing:0.5px;<?php echo Availability::DISPONIBLE === ( $availability_state ?? '' ) ? 'background-color:#eef8ee;color:#006505;' : 'background-color:#fef6e6;color:#8a5300;'; ?>"><?php echo esc_html( $availability_label ); ?></span>
					<?php if ( '' !== ( $timeframe_copy ?? '' ) ) : ?>
						<span> — <?php echo esc_html( $timeframe_copy ); ?></span>
					<?php endif; ?>
				</p>
			<?php endif; ?>
			<?php if ( '' !== $product_price_html ) : ?>
				<p style="margin:0 0 12px 0;"><?php echo wp_kses_post( $product_price_html ); ?></p>
			<?php endif; ?>
			<?php
			// El `-webkit-text-fill-color` NO es decorativo: la hoja del wrapper
			// pinta CUALQUIER `<a>` del cuerpo de rojo, y ese relleno no lo
			// desactiva el `color:#ffffff`. Sin esta declaración el botón salía
			// rojo-sobre-rojo (1,23:1) en Apple Mail, la app de Gmail y Outlook
			// móvil. La clase `.akb-email-btn` lo repite desde el CSS por si el
			// inline se pierde.
			?>
			<a class="akb-email-btn" href="<?php echo esc_url( $product_url ); ?>" style="display:inline-block;background-color:#E60012;background-image:linear-gradient(#E60012,#E60012);color:#ffffff;-webkit-text-fill-color:#ffffff;padding:15px 28px;font-weight:bold;line-height:1;text-decoration:none;border-radius:0;text-transform:uppercase;letter-spacing:0.5px;"><?php echo esc_html( '' !== ( $cta_label ?? '' ) ? $cta_label : 'Comprar ahora' ); ?></a>
		</td>
	</tr>
</table>

<p style="color:#6b6b6b;font-size:13px;">
	<?php echo esc_html( '' !== ( $footnote_copy ?? '' ) ? $footnote_copy : 'El stock puede ser limitado: la mayoría de nuestros tomos tienen pocas unidades por edición.' ); ?>
</p>

<?php
if ( '' !== $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}
?>

<p style="color:#9a9a9a;font-size:12px;margin-top:24px;">
	<?php echo esc_html( 'Recibiste este aviso porque te anotaste en la lista de espera de este producto.' ); ?>
	<?php // La baja SUSURRA: sin color+relleno+peso propios, el wrapper la sube a rojo y negrita — la misma jerarquía visual que el CTA. ?>
	<a href="<?php echo esc_url( $unsubscribe_url ); ?>" style="color:#9a9a9a;-webkit-text-fill-color:#9a9a9a;font-weight:normal;text-decoration:underline;"><?php echo esc_html( 'No avisarme más sobre este producto' ); ?></a>.
</p>

<?php /* phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Hook de WooCommerce core: el override debe emitirlo con su nombre original o los plugins que lo escuchan dejan de recibirlo. */ do_action( 'woocommerce_email_footer', $email ); ?>
