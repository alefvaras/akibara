<?php
/**
 * Seguimiento del envío en el detalle del pedido (Mi cuenta).
 *
 * Sobrescribe la plantilla de seguimiento de envíos enlazando directamente
 * a `https://www.blue.cl/enviar/seguimiento` con el parámetro `n_seguimiento`,
 * permitiendo al cliente consultar el estado sin transcribir el código manualmente.
 *
 * @package Akibara\Theme
 */

defined( 'ABSPATH' ) || exit;

if ( empty( $codes ) ) {
	return;
}
?>

<p class="wc-correios-tracking__description">
	<?php echo esc_html( _n( 'Código de seguimiento:', 'Códigos de seguimiento:', count( $codes ), 'akibara-gp' ) ); ?>
</p>

<table class="wc-correios-tracking__table woocommerce-table shop_table shop_table_responsive">
	<tbody>
		<?php foreach ( $codes as $code ) : // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Variable local a la iteración del include. ?>
			<tr>
				<th><?php echo esc_html( $code ); ?></th>
				<td>
					<a
						class="wc-correios-tracking__button button"
						href="<?php echo esc_url( add_query_arg( 'n_seguimiento', $code, 'https://www.blue.cl/enviar/seguimiento' ) ); ?>"
						target="_blank"
						rel="nofollow noopener noreferrer"
					>
						<?php esc_html_e( 'Ver el estado en Blue Express', 'akibara-gp' ); ?>
					</a>
				</td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>

<p class="wc-correios-tracking__note" style="font-size:13px;color:var(--aki-gray-400,#9e9e9e);margin:8px 0 0">
	<?php esc_html_e( 'Blue Express puede tardar unas horas en publicar el seguimiento desde que retira el paquete. Si todavía no aparece, escríbenos y lo revisamos contigo.', 'akibara-gp' ); ?>
</p>
