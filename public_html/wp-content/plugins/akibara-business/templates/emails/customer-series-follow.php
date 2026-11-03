<?php
/**
 * Email «salió el siguiente tomo de tu serie» — versión HTML.
 *
 * Un bloque por serie. El enlace de baja es POR SERIE a propósito: dar de baja
 * de una no puede callar las otras. El `List-Unsubscribe` de la cabecera sí es
 * el global.
 *
 * La línea del corte llega ya resuelta en `cutoff` y puede venir vacía — es el
 * caso normal para cualquier tomo Disponible o Agotado. Cuando viene vacía se
 * imprime SOLO el estado comercial: ninguna fecha, ningún «pronto», ningún
 * «consulta».
 *
 * Override desde theme:
 *   `wp-content/themes/<theme>/woocommerce/emails/customer-series-follow.php`
 *
 * @package Akibara\Business
 *
 * @var array<int, array<string, mixed>> $groups             Bloques del digest.
 * @var string                           $collection_url     /mi-cuenta/coleccion/.
 * @var string                           $unsubscribe_url    Baja global.
 * @var string                           $email_heading      Encabezado configurable.
 * @var string                           $additional_content Contenido opcional del admin.
 * @var bool                             $sent_to_admin
 * @var bool                             $plain_text
 * @var \WC_Email                        $email
 */

defined( 'ABSPATH' ) || exit;

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Hook de WooCommerce core: el override debe emitirlo con su nombre original o los plugins que lo escuchan dejan de recibirlo.
do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<?php
// TRES casos, no dos: `count( $groups )` cuenta SERIES. Con una serie y cuatro
// tomos, «Salió un tomo nuevo» se contradice con el bloque de abajo, que dice
// «tomos 71 al 74», y un correo que se desmiente a sí mismo es peor que el
// original. Las claves nuevas se leen con `??`: `template_args()` reenvía
// cualquier array que le pasen, y un warning dentro de `wc_get_template_html()`
// ensucia el cuerpo del correo ya enviado.
$akibara_tomos        = 0;
$akibara_todos_huecos = array() !== $groups;
foreach ( $groups as $akibara_conteo ) {
	$akibara_tomos += max( 1, (int) ( $akibara_conteo['volumes_count'] ?? 1 ) );
	if ( empty( $akibara_conteo['is_gap'] ) ) {
		$akibara_todos_huecos = false;
	}
}

if ( $akibara_todos_huecos && 1 === count( $groups ) && 1 === $akibara_tomos ) {
	$akibara_intro = 'Cierra el hueco de tu colección: se habilitó un tomo que te faltaba de una serie que estás siguiendo.';
} elseif ( 1 < count( $groups ) ) {
	$akibara_intro = 'Salieron tomos nuevos de series que estás siguiendo.';
} elseif ( 1 === $akibara_tomos ) {
	$akibara_intro = 'Salió un tomo nuevo de una serie que estás siguiendo.';
} else {
	// str_replace y jamás sprintf, aunque el literal sea de código: el día que
	// esta frase pase al panel el marcador ya es el bueno y nadie tiene que
	// acordarse de cambiar el mecanismo.
	$akibara_intro = str_replace(
		'{n_tomos}',
		(string) $akibara_tomos,
		'Salieron {n_tomos} tomos nuevos de una serie que estás siguiendo.'
	);
}
?>
<p><?php echo esc_html( $akibara_intro ); ?></p>

<?php foreach ( $groups as $akibara_group ) : ?>
	<?php
	$akibara_tanda = max( 1, (int) ( $akibara_group['volumes_count'] ?? 1 ) ) > 1;
	$akibara_frase = (string) ( $akibara_group['volumes_phrase'] ?? '' );
	$akibara_tomo  = (string) $akibara_group['volume'];
	$akibara_lista = $akibara_tanda && '' !== $akibara_frase;
	$akibara_plazo = '' !== (string) ( $akibara_group['cutoff'] ?? '' )
		? (string) $akibara_group['cutoff']
		: (string) ( $akibara_group['timeframe_copy'] ?? '' );
	?>
	<table class="akb-product-card" cellspacing="0" cellpadding="0" border="0" style="width:100%;margin:20px 0;border:1px solid #141416;border-left:4px solid #E60012;background-color:#fffdf7;">
		<tr>
			<?php if ( '' !== (string) $akibara_group['image_url'] ) : ?>
				<td style="width:96px;padding:14px 0 14px 14px;vertical-align:top;">
					<a href="<?php echo esc_url( (string) $akibara_group['url'] ); ?>">
						<img src="<?php echo esc_url( (string) $akibara_group['image_url'] ); ?>" alt="<?php echo esc_attr( (string) $akibara_group['title'] ); ?>" width="80" style="display:block;width:80px;height:auto;border:1px solid #141416;">
					</a>
				</td>
			<?php endif; ?>
			<td style="padding:14px;vertical-align:top;">
				<?php if ( ! empty( $akibara_group['is_gap'] ) ) : ?>
					<p style="margin:0 0 4px 0;color:#b45309;font-size:12px;font-weight:600;"><?php echo esc_html( 'Cierra el hueco de tu colección' ); ?></p>
				<?php endif; ?>
				<p style="margin:0 0 6px 0;font-weight:600;">
					<a href="<?php echo esc_url( (string) $akibara_group['url'] ); ?>" style="color:#1a1a1a;-webkit-text-fill-color:#1a1a1a;text-decoration:none;">
						<?php echo esc_html( (string) $akibara_group['series_name'] ); ?> — <?php echo esc_html( $akibara_lista ? 'tomos ' . $akibara_frase : 'tomo ' . $akibara_tomo ); ?>
					</a>
				</p>
				<?php if ( $akibara_tanda ) : ?>
					<?php
					// «Tomo 74:» delante, y todo en UNA línea, es lo único que
					// impide leer «$8.990» como el precio de los cuatro.
					// `build_group()` carga UN producto: el precio, el estado y el
					// corte son del tomo que se muestra y de ninguno más, y nada
					// del bloque puede sugerir lo contrario.
					?>
					<p style="margin:0 0 12px 0;color:#4a4a4a;font-size:13px;">
						<?php echo esc_html( 'Tomo ' . $akibara_tomo . ':' ); ?>
						<?php if ( '' !== (string) $akibara_group['price_html'] ) : ?>
							<?php echo wp_kses_post( (string) $akibara_group['price_html'] ); ?> ·
						<?php endif; ?>
						<?php echo esc_html( (string) $akibara_group['availability'] ); ?><?php echo '' !== $akibara_plazo ? ' · ' . esc_html( $akibara_plazo ) : ''; ?>
					</p>
				<?php else : ?>
					<?php // Un solo tomo no se mueve: dos párrafos, exactamente como hoy. ?>
					<?php if ( '' !== (string) $akibara_group['price_html'] ) : ?>
						<p style="margin:0 0 6px 0;"><?php echo wp_kses_post( (string) $akibara_group['price_html'] ); ?></p>
					<?php endif; ?>
					<p style="margin:0 0 12px 0;color:#4a4a4a;font-size:13px;">
						<?php echo esc_html( (string) $akibara_group['availability'] ); ?><?php echo '' !== $akibara_plazo ? ' · ' . esc_html( $akibara_plazo ) : ''; ?>
					</p>
				<?php endif; ?>
				<?php
				// El `-webkit-text-fill-color` NO es decorativo: la hoja del
				// wrapper pinta CUALQUIER `<a>` del cuerpo de rojo, y ese relleno
				// no lo desactiva el `color:#ffffff`. Sin esta declaración el
				// botón salía rojo-sobre-rojo (1,23:1) en Apple Mail, la app de
				// Gmail y Outlook móvil. La clase `.akb-email-btn` lo repite
				// desde el CSS por si el inline se pierde.
				?>
				<a class="akb-email-btn" href="<?php echo esc_url( (string) $akibara_group['url'] ); ?>" style="display:inline-block;background-color:#E60012;background-image:linear-gradient(#E60012,#E60012);color:#ffffff;-webkit-text-fill-color:#ffffff;padding:15px 28px;font-weight:bold;line-height:1;text-decoration:none;border-radius:0;text-transform:uppercase;letter-spacing:0.5px;"><?php // Con cuatro tomos nombrados encima, «Ver el tomo» ya no sabe a cuál se refiere. ?><?php echo esc_html( $akibara_tanda ? 'Ver el tomo ' . $akibara_tomo : 'Ver el tomo' ); ?></a>
				<p style="margin:12px 0 0 0;color:#9a9a9a;font-size:12px;">
					¿No quieres avisos de <?php echo esc_html( (string) $akibara_group['series_name'] ); ?>?
					<?php // La baja SUSURRA: sin color+relleno+peso propios, el wrapper la sube a rojo y negrita — la misma jerarquía visual que el CTA. ?>
					<a href="<?php echo esc_url( (string) $akibara_group['unfollow_url'] ); ?>" style="color:#9a9a9a;-webkit-text-fill-color:#9a9a9a;font-weight:normal;text-decoration:underline;">Darme de baja de esta serie</a>.
				</p>
			</td>
		</tr>
	</table>
<?php endforeach; ?>

<p style="color:#6b6b6b;font-size:13px;">
	<a href="<?php echo esc_url( $collection_url ); ?>">Ver mi colección completa</a>
</p>

<?php
if ( '' !== $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}
?>

<p style="color:#9a9a9a;font-size:12px;margin-top:24px;">
	<?php echo esc_html( 'Recibiste este aviso porque ya compraste tomos anteriores de estas series.' ); ?>
	<a href="<?php echo esc_url( $unsubscribe_url ); ?>" style="color:#9a9a9a;-webkit-text-fill-color:#9a9a9a;font-weight:normal;text-decoration:underline;"><?php echo esc_html( 'No avisarme de ninguna serie' ); ?></a>.
</p>

<?php /* phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Hook de WooCommerce core: el override debe emitirlo con su nombre original o los plugins que lo escuchan dejan de recibirlo. */ do_action( 'woocommerce_email_footer', $email ); ?>
