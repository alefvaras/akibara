<?php
/**
 * Email Footer — Akibara branded override.
 *
 * Overrides WooCommerce default at:
 * woocommerce/templates/emails/email-footer.php
 *
 * @package Akibara
 * @version 10.4.0
 */

defined( 'ABSPATH' ) || exit;

$akb_store_name   = get_bloginfo( 'name', 'display' );
$akb_current_year = wp_date( 'Y' );

// No se consume el texto configurable de WooCommerce: puede contener una
// dirección literal ya expandida. El footer del theme es deliberadamente fijo
// para no publicar datos operativos privados.
//
// Fuente única autoritativa para la línea pública en HTML y texto plano:
// `akibara_gp_email_footer_public_line()` (inc/woocommerce.php), garantizando
// paridad total sin duplicaciones literales entre formatos.
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- nombre sellado por el candado de fuente de tests/online-store-truth.test.js, que exige literalmente `$footer_text = $store_online_line` para probar que el pie NO sale de `woocommerce_email_footer_text` (podría traer la dirección privada). Prefijarlo desactivaría el candado.
$store_online_line = akibara_gp_email_footer_public_line();
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- nombre sellado por el candado de fuente de tests/online-store-truth.test.js, que exige literalmente `$footer_text = $store_online_line` para probar que el pie NO sale de `woocommerce_email_footer_text` (podría traer la dirección privada). Prefijarlo desactivaría el candado.
$footer_text = $store_online_line;

$akb_footer_text_plain = trim( wp_strip_all_tags( $footer_text ) );
$akb_show_footer_text  = '' !== $akb_footer_text_plain && 0 !== strcasecmp( $akb_footer_text_plain, $akb_store_name );

// Defensa contra plantillas plain de WooCommerce core (ej. plain/customer-cancelled-order.php:81)
// que disparan `woocommerce_email_footer` sin haber disparado `woocommerce_email_header`:
// emite sólo la línea pública en texto plano en vez de volcar el cierre HTML en AltBody.
if ( function_exists( 'did_action' ) && did_action( 'woocommerce_email_footer' ) > did_action( 'woocommerce_email_header' ) ) {
	echo esc_html( $akb_footer_text_plain ) . "\n";
	return;
}

?>
											</div>
										</td>
									</tr>
								</table>
							</td>
						</tr>
					</table>
				</td>
			</tr>
		</table>
	</td>
</tr>
<!-- End Body -->

<!-- Footer -->
<tr>
	<td style="padding: 0 !important; background-color: #0d0d0d; background-image: linear-gradient(#0d0d0d, #0d0d0d);">
		<table border="0" cellpadding="0" cellspacing="0" width="100%" id="template_footer" role="presentation" style="background-color: #0d0d0d; background-image: linear-gradient(#0d0d0d, #0d0d0d);">
			<!-- Filete superior rojo Akibara (#E60012) de 3px que Gmail no invierte -->
			<tr>
				<td colspan="2" height="3" style="height: 3px; line-height: 3px; font-size: 3px; padding: 0; background-color: #E60012; background-image: linear-gradient(#E60012, #E60012);">&nbsp;</td>
			</tr>
			<tr>
				<td colspan="2" valign="middle" id="credit" style="padding: 24px 32px; text-align: center; border: 0 !important; border-top: 0 !important; background-color: #0d0d0d; background-image: linear-gradient(#0d0d0d, #0d0d0d);">
					<p style="margin: 0 0 12px; color: #ffffff; font-size: 13px;">
						<a href="<?php echo esc_url( home_url() ); ?>" style="color: #ffffff; -webkit-text-fill-color: #ffffff; margin: 0 8px; font-weight: bold; text-decoration: none;">Inicio</a>
						<span style="color: #E60012; font-weight: bold;">&middot;</span>
						<a href="<?php echo esc_url( home_url( '/tienda/' ) ); ?>" style="color: #ffffff; -webkit-text-fill-color: #ffffff; margin: 0 8px; font-weight: bold; text-decoration: none;">Catálogo</a>
						<span style="color: #E60012; font-weight: bold;">&middot;</span>
						<a href="<?php echo esc_url( home_url( '/mi-cuenta/' ) ); ?>" style="color: #ffffff; -webkit-text-fill-color: #ffffff; margin: 0 8px; font-weight: bold; text-decoration: none;">Mi cuenta</a>
					</p>
					<?php if ( $akb_show_footer_text ) : ?>
					<p style="margin: 0 0 10px; color: #b5afa6; font-size: 12px; line-height: 1.5;">
						<?php echo wp_kses_post( $footer_text ); ?>
					</p>
					<?php endif; ?>
					<p style="margin: 0; color: #8a8378; font-size: 11px;">
						&copy; <?php echo esc_html( $akb_current_year ); ?> <?php echo esc_html( $akb_store_name ); ?> &mdash; Tu rincón de Akihabara en Chile.
					</p>
				</td>
			</tr>
		</table>
	</td>
</tr>
<!-- End Footer -->

</table>
</div>
</td>
</tr>
</table>
</body>
</html>
