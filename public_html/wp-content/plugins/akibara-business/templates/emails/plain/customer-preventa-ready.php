<?php
/**
 * Email "preventa lista" — versión plain text.
 *
 * TEXTO PLANO: nada de `esc_html()` ni `esc_url()`. Codifican `'` como `&#039;`,
 * y `WC_Email` convierte las entidades conocidas de la parte plana pero BORRA
 * las que no reconoce (`$plain_search`, «Unknown/unhandled entities»): «Hola
 * D'Angelo» o «JoJo's Bizarre Adventure» llegaban como «DAngelo» y «JoJos». Aquí
 * no hay contexto HTML que proteger —WooCommerce le quita las etiquetas a toda
 * la parte plana—, así que el texto variable sale por `EmailBrand::plano( … )`
 * y los enlaces por `esc_url_raw()`.
 *
 * Hasta el 2026-09-18 ese texto salía por `wp_kses( …, array() )`, que quita
 * las etiquetas pero DEJA las entidades tal cual — y el borrado de WooCommerce
 * se las llevaba igual, con el mismo «JoJos» de arriba, esta vez para un
 * nombre que ya venía guardado con la entidad. `EmailBrand::plano()` las
 * devuelve a su carácter antes de imprimir, que es lo único que sobrevive al
 * paso de WooCommerce (hallazgo S-0dda49e6).
 *
 * @package Akibara\Business
 *
 * @var \WC_Order                              $order
 * @var array<int, \WC_Order_Item_Product>     $preventa_items  Líneas en espera vigentes (sin las reembolsadas del todo).
 * @var bool                                   $is_local_pickup
 * @var string                                 $email_heading
 * @var string                                 $additional_content
 */

use Akibara\Business\Modules\EmailBrand\EmailBrand;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- N-phpcs-plain (2026-09-19): TEXTO PLANO, sin HTML que proteger; el porqué completo está en el docblock de este fichero, arriba.

echo EmailBrand::plano( $email_heading ) . "\n";
echo "==========\n\n";

echo EmailBrand::plano( EmailBrand::saludo( $order ) );
echo "\n\n";

printf(
	'¡Buenas noticias! Los productos que esperabas de tu pedido #%s ya llegaron a nuestra bodega.',
	EmailBrand::plano( (string) $order->get_order_number() )
);
echo "\n\n";

if ( ! empty( $preventa_items ) ) {
	echo 'Lo que esperabas:';
	echo "\n";
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Locales del render: wc_get_template() incluye la plantilla DENTRO de una función, no en el ámbito global.
	foreach ( $preventa_items as $item ) {
		printf( '  - %s × %s', EmailBrand::plano( $item->get_name() ), EmailBrand::plano( (string) \Akibara\Business\Modules\OrderPreventa\PreventaItems::outstanding_qty( $order, $item ) ) );
		echo "\n";
	}
	echo "\n";
}

if ( ! empty( $is_local_pickup ) ) {
	echo 'Tu pedido es para retiro.';
	echo "\n";
	echo 'Te avisaremos por correo cuando esté preparado para que pases a retirarlo. Si tienes dudas, escríbenos y te ayudamos.';
} else {
	echo EmailBrand::plano( sprintf( 'Despachamos en %s.', \Akibara\Business\Modules\OrderPreventa\ShipPromise::window() ) );
	echo "\n";
	echo 'Te enviaremos el seguimiento por correo cuando el despacho esté listo.';
}
echo "\n\n";

echo 'Ver tu pedido:';
echo "\n";
// `EmailBrand::order_url()` y no `get_view_order_url()`: esa ruta exige sesión
// y el comprador INVITADO recibe este mismo correo (hallazgo S-7fc2defd).
echo esc_url_raw( \Akibara\Business\Modules\EmailBrand\EmailBrand::order_url( $order ) );
echo "\n\n";

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Cuerpo del correo en TEXTO PLANO: escaparlo metería entidades HTML en lo que lee el cliente.
echo \Akibara\Business\Modules\OrderPreventa\ContactFooter::render_plain( $order );

if ( '' !== trim( (string) $additional_content ) ) {
	echo "\n";
	echo EmailBrand::plano( wptexturize( $additional_content ) );
	echo "\n";
}
