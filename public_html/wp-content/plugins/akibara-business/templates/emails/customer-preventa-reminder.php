<?php
/**
 * Email "preventa recordatorio/cambio de fecha" — versión HTML.
 *
 * 3 modos via `$mode`:
 *   - `t7`             Recordatorio milestone "se acerca".
 *   - `date_changed`   Cambio de fecha por edición admin.
 *   - `date_announced` Primera fecha de salida del tomo (buena noticia sólo si se compró sin fecha).
 *
 * Override desde theme:
 *   `wp-content/themes/<theme>/woocommerce/emails/customer-preventa-reminder.php`
 *
 * @package Akibara\Business
 *
 * @var \WC_Order                                                  $order              Orden cliente.
 * @var string                                                     $mode               't7' | 'date_changed' | 'date_announced'.
 * @var array<int, \WC_Order_Item_Product>                         $preventa_items     Líneas en espera vigentes (sin las reembolsadas del todo; en los avisos de fecha, sólo las que siguen sin llegar).
 * @var string                                                     $summary_max_ymd    Arribo estimado del PEDIDO (el último tomo) Y-m-d, '' si N/A.
 * @var string                                                     $item_max_ymd       Arribo estimado del TOMO que cambió de fecha Y-m-d, '' si N/A ('date_changed' y 'date_announced').
 * @var array{product_id:int,product_name:string,old_ymd:string,new_ymd:string} $date_change_ctx Poblado en 'date_changed' y 'date_announced'.
 * @var bool                                                       $bought_without_date La línea anunciada se compró sin fecha (tba).
 * @var string                                                     $email_heading      Heading (configurable).
 * @var string                                                     $additional_content Contenido opcional del admin.
 * @var bool                                                       $sent_to_admin
 * @var bool                                                       $plain_text
 * @var \WC_Email                                                  $email
 */

defined( 'ABSPATH' ) || exit;

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Hook de WooCommerce core: el override debe emitirlo con su nombre original o los plugins que lo escuchan dejan de recibirlo.
do_action( 'woocommerce_email_header', $email_heading, $email );

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Locales del render: wc_get_template() incluye la plantilla DENTRO de una función, no en el ámbito global.
$is_date_changed   = ( 'date_changed' === $mode );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Locales del render: wc_get_template() incluye la plantilla DENTRO de una función, no en el ámbito global.
$is_date_announced = ( 'date_announced' === $mode );

// Formatea Y-m-d a "5 de junio de 2026" según locale es_CL.
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Locales del render: wc_get_template() incluye la plantilla DENTRO de una función, no en el ámbito global.
$fmt_es = static function ( string $ymd ): string {
	if ( '' === $ymd ) {
		return '';
	}
	$ts = strtotime( $ymd . ' 12:00:00' );
	if ( false === $ts ) {
		return $ymd;
	}
	return wp_date( 'j \d\e F \d\e Y', $ts );
};

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Locales del render: wc_get_template() incluye la plantilla DENTRO de una función, no en el ámbito global.
$old_human = $is_date_changed ? $fmt_es( $date_change_ctx['old_ymd'] ) : '';
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Locales del render: wc_get_template() incluye la plantilla DENTRO de una función, no en el ámbito global.
$new_human = ( $is_date_changed || $is_date_announced ) ? $fmt_es( $date_change_ctx['new_ymd'] ) : '';
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Locales del render: wc_get_template() incluye la plantilla DENTRO de una función, no en el ámbito global.
$max_human = $fmt_es( $summary_max_ymd );
// El arribo del tomo que cambió; si el pedido espera OTRO tomo que llega más
// tarde, se dice aparte, para que el cliente no lea la fecha del otro como
// si fuera la de éste.
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Locales del render: wc_get_template() incluye la plantilla DENTRO de una función, no en el ámbito global.
$item_human = $fmt_es( (string) ( $item_max_ymd ?? '' ) );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Locales del render: wc_get_template() incluye la plantilla DENTRO de una función, no en el ámbito global.
$whole_late = '' !== $item_human && '' !== $max_human && $max_human !== $item_human;
// Coherencia: el arribo no puede ser ANTERIOR a la salida de la editorial que
// este mismo correo anuncia. Si el estimado del pedido quedó viejo (sin país
// o lead time con que recalcular), se calla la línea antes que contradecirse.
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Locales del render: wc_get_template() incluye la plantilla DENTRO de una función, no en el ámbito global.
$item_coherente = '' === (string) ( $date_change_ctx['new_ymd'] ?? '' ) || (string) ( $item_max_ymd ?? '' ) >= (string) $date_change_ctx['new_ymd'];
// La misma guarda para «Tu pedido completo sale… alrededor del»: tampoco puede ir antes de la salida.
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Locales del render: wc_get_template() incluye la plantilla DENTRO de una función, no en el ámbito global.
$whole_late = $whole_late && ( '' === (string) ( $date_change_ctx['new_ymd'] ?? '' ) || (string) $summary_max_ymd >= (string) $date_change_ctx['new_ymd'] );
?>

<p>
	<?php echo esc_html( \Akibara\Business\Modules\EmailBrand\EmailBrand::saludo( $order ) ); ?>
</p>

<?php if ( $is_date_changed ) : ?>

	<p>
		<?php
		printf(
			esc_html( 'Te escribimos por un cambio en tu pedido %1$s: la editorial movió la fecha de salida de %2$s.' ),
			'<strong>#' . esc_html( (string) $order->get_order_number() ) . '</strong>',
			'' !== (string) ( $date_change_ctx['product_name'] ?? '' ) ? '<strong>' . esc_html( (string) $date_change_ctx['product_name'] ) . '</strong>' : 'un tomo'
		);
		?>
	</p>

	<div class="akb-email-callout" style="margin:24px 0;padding:16px 20px;background:#fff8e1;border:1px solid #141416;border-left:4px solid #f0ad4e;border-radius:0;">
		<?php
		/*
		 * DOS fechas distintas, y hay que llamarlas por su nombre:
		 *   · la de la EDITORIAL: cuándo publica el tomo.
		 *   · la de ARRIBO: cuándo llega aquí y se puede despachar. Es la única
		 *     que el cliente ha visto en el resto del carril (compra, T-7, su
		 *     pedido), así que es la que le importa.
		 * Antes se imprimía la de la editorial bajo una etiqueta de «estimada»,
		 * y como el envío tarda semanas, una postergación de 21 días salía como
		 * una fecha ANTERIOR a la ya prometida: un aviso de retraso que se leía
		 * como un adelanto. El arribo (`$max_human`) llegaba al template y se
		 * descartaba.
		 */
		?>
		<p style="margin:0 0 6px 0;font-size:0.95em;">
			<?php echo esc_html( 'Salía el' ); ?>
			<strong><?php echo esc_html( '' === $old_human ? '—' : $old_human ); ?></strong><?php echo esc_html( '; ahora sale el' ); ?>
			<strong><?php echo esc_html( '' === $new_human ? '—' : $new_human ); ?></strong><?php echo esc_html( '.' ); ?>
		</p>
		<?php if ( '' !== $item_human && $item_coherente ) : ?>
			<p style="margin:0;font-size:0.95em;">
				<?php echo esc_html( 'Te llegaría alrededor del' ); ?>
				<strong><?php echo esc_html( $item_human ); ?></strong>
				<?php echo esc_html( '(contando el envío desde la editorial).' ); ?>
			</p>
		<?php endif; ?>
		<?php if ( $whole_late ) : ?>
			<p style="margin:6px 0 0 0;font-size:0.95em;">
				<?php echo esc_html( 'Tu pedido completo sale cuando llegue todo: alrededor del' ); ?>
				<strong><?php echo esc_html( $max_human ); ?></strong><?php echo esc_html( '.' ); ?>
			</p>
		<?php endif; ?>
	</div>

	<p style="margin:18px 0;line-height:1.55;">
		<?php echo esc_html( 'El cambio responde a un ajuste de la editorial. Tu pedido sigue reservado — no necesitas hacer nada.' ); ?>
		<?php echo esc_html( 'Si prefieres cancelar y recibir reembolso, respóndenos a este correo o escríbenos por WhatsApp (el enlace está al pie de este correo).' ); ?>
	</p>

<?php elseif ( $is_date_announced ) : ?>

	<p>
		<?php
		// «Sin fecha confirmada» sólo es verdad para una preventa comprada como
		// `tba`. Un tomo a pedido ya tenía un rango de llegada prometido: para ese
		// cliente esto es un cambio, no una buena noticia, y se le da la misma
		// salida que en una postergación.
		printf(
			esc_html(
				! empty( $bought_without_date )
					? 'Buenas noticias: tu pedido %1$s, que compraste sin fecha confirmada, ya tiene fecha de salida.'
					: 'Te escribimos porque la editorial anunció la fecha de salida de %2$s de tu pedido %1$s, y con ella cambia la fecha en que te llegaría.'
			),
			'<strong>#' . esc_html( (string) $order->get_order_number() ) . '</strong>',
			'' !== (string) ( $date_change_ctx['product_name'] ?? '' ) ? '<strong>' . esc_html( (string) $date_change_ctx['product_name'] ) . '</strong>' : 'un tomo'
		);
		?>
	</p>

	<?php
	// Verde sólo si es buena noticia (se compró sin fecha); si la línea tenía
	// fecha y la editorial la retiró y volvió a anunciar, es un cambio: ámbar,
	// como la postergación.
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Locales del render: wc_get_template() incluye la plantilla DENTRO de una función, no en el ámbito global.
	$callout_style = ! empty( $bought_without_date )
		? 'background:#f0f7f1;border:1px solid #141416;border-left:4px solid #28a745;'
		: 'background:#fff8e1;border:1px solid #141416;border-left:4px solid #f0ad4e;';
	?>
	<div class="akb-email-callout" style="margin:24px 0;padding:16px 20px;<?php echo esc_attr( $callout_style ); ?>border-radius:0;">
		<p style="margin:0 0 6px 0;font-size:0.95em;">
			<?php echo esc_html( 'Sale de la editorial el' ); ?>
			<strong><?php echo esc_html( '' === $new_human ? '—' : $new_human ); ?></strong><?php echo esc_html( '.' ); ?>
		</p>
		<?php if ( '' !== $item_human && $item_coherente ) : ?>
			<p style="margin:0;font-size:0.95em;">
				<?php echo esc_html( 'Te llegaría alrededor del' ); ?>
				<strong><?php echo esc_html( $item_human ); ?></strong>
				<?php echo esc_html( '(contando el envío).' ); ?>
			</p>
		<?php endif; ?>
		<?php if ( $whole_late ) : ?>
			<p style="margin:6px 0 0 0;font-size:0.95em;">
				<?php echo esc_html( 'Tu pedido completo sale cuando llegue todo: alrededor del' ); ?>
				<strong><?php echo esc_html( $max_human ); ?></strong><?php echo esc_html( '.' ); ?>
			</p>
		<?php endif; ?>
	</div>

	<p style="margin:18px 0;line-height:1.55;">
		<?php echo esc_html( 'Tu pedido sigue reservado — no necesitas hacer nada. Te avisaremos cuando llegue a nuestra bodega y esté listo para despachar.' ); ?>
		<?php if ( empty( $bought_without_date ) ) : ?>
			<?php echo esc_html( 'Si la nueva fecha no te acomoda y prefieres cancelar y recibir reembolso, respóndenos a este correo o escríbenos por WhatsApp (el enlace está al pie de este correo).' ); ?>
		<?php endif; ?>
	</p>

<?php else : ?>

	<p>
		<?php
		printf(
			esc_html( 'Te escribimos para recordarte que los productos que esperas de tu pedido %s están por llegar.' ),
			'<strong>#' . esc_html( (string) $order->get_order_number() ) . '</strong>'
		);
		?>
	</p>

	<?php if ( '' !== $max_human ) : ?>
		<div class="akb-email-callout" style="margin:24px 0;padding:16px 20px;background:#fffaf3;border:1px solid #141416;border-left:4px solid #E60012;border-radius:0;">
			<p style="margin:0;font-size:0.95em;">
				<?php echo esc_html( 'Fecha estimada de arribo a bodega:' ); ?>
				<strong><?php echo esc_html( $max_human ); ?></strong>
			</p>
		</div>
	<?php endif; ?>

	<p style="margin:18px 0;line-height:1.55;">
		<?php echo esc_html( sprintf( 'Cuando los tomos lleguen a nuestra bodega te enviaremos un correo confirmando que tu pedido está listo. Después despachamos en %s.', \Akibara\Business\Modules\OrderPreventa\ShipPromise::window() ) ); ?>
	</p>

<?php endif; ?>

<?php if ( ! empty( $preventa_items ) ) : ?>
	<p style="margin:18px 0 6px 0;font-weight:600;">
		<?php echo esc_html( 'Lo que esperabas:' ); ?>
	</p>
	<table cellspacing="0" cellpadding="6" style="width:100%;border:1px solid #e5e5e5;margin-bottom:24px;" border="0">
		<thead>
			<tr>
				<th scope="col" style="text-align:left;background-color:#f8f8f8;color:#1a1a1a;padding:10px 12px;border-bottom:2px solid #E60012;">
					<?php echo esc_html( 'Producto' ); ?>
				</th>
				<th scope="col" style="text-align:center;background-color:#f8f8f8;color:#1a1a1a;padding:10px 12px;width:80px;border-bottom:2px solid #E60012;">
					<?php echo esc_html( 'Cantidad' ); ?>
				</th>
			</tr>
		</thead>
		<tbody>
			<?php /* phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Locales del render: wc_get_template() incluye la plantilla DENTRO de una función, no en el ámbito global. */ foreach ( $preventa_items as $item ) : ?>
				<tr>
					<td style="padding:10px 12px;border-top:1px solid #e5e5e5;">
						<?php echo esc_html( $item->get_name() ); ?>
					</td>
					<td style="padding:10px 12px;border-top:1px solid #e5e5e5;text-align:center;">
						<?php echo esc_html( (string) \Akibara\Business\Modules\OrderPreventa\PreventaItems::outstanding_qty( $order, $item ) ); ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
<?php endif; ?>

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
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Hook de WooCommerce core: el override debe emitirlo con su nombre original o los plugins que lo escuchan dejan de recibirlo.
do_action( 'woocommerce_email_footer', $email );
