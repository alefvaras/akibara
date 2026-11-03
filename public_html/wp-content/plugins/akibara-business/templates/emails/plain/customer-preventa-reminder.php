<?php
/**
 * Email "preventa recordatorio/cambio de fecha" — versión plain text.
 *
 * Texto plano sin `esc_html()` ni `esc_url()`: ver el porqué en
 * `plain/customer-preventa-ready.php`.
 *
 * @package Akibara\Business
 *
 * @var \WC_Order                                                  $order
 * @var string                                                     $mode
 * @var array<int, \WC_Order_Item_Product>                         $preventa_items  Líneas en espera vigentes (sin las reembolsadas del todo).
 * @var string                                                     $item_max_ymd
 * @var string                                                     $summary_max_ymd
 * @var array{product_id:int,product_name:string,old_ymd:string,new_ymd:string} $date_change_ctx
 * @var bool                                                       $bought_without_date
 * @var string                                                     $email_heading
 * @var string                                                     $additional_content
 */

use Akibara\Business\Modules\EmailBrand\EmailBrand;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- N-phpcs-plain (2026-09-19): TEXTO PLANO, sin HTML que proteger; el porqué completo está en el docblock de `plain/customer-preventa-ready.php`.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Locales del render: wc_get_template() incluye la plantilla DENTRO de una función, no en el ámbito global. Una sola exención para el fichero: con un `phpcs:ignore` por línea, las de los bloques anidados no surtían efecto y el lint quedaba en rojo (2026-09-19).

$is_date_changed   = ( 'date_changed' === $mode );
$is_date_announced = ( 'date_announced' === $mode );

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

echo EmailBrand::plano( $email_heading ) . "\n";
echo "==========\n\n";

echo EmailBrand::plano( EmailBrand::saludo( $order ) );
echo "\n\n";

if ( $is_date_changed ) {
	printf(
		'Te escribimos por un cambio en tu pedido #%1$s: la editorial movió la fecha de salida de %2$s.',
		EmailBrand::plano( (string) $order->get_order_number() ),
		'' !== (string) ( $date_change_ctx['product_name'] ?? '' ) ? EmailBrand::plano( (string) $date_change_ctx['product_name'] ) : 'un tomo'
	);
	echo "\n\n";

	// La fecha de la EDITORIAL (cuándo publica) y la de ARRIBO (cuándo llega
	// aquí y se puede despachar) son distintas, y sólo la segunda es la que el
	// cliente ha visto en el resto del carril. Ver el gemelo en HTML.
	$old_human  = $fmt_es( $date_change_ctx['old_ymd'] );
	$new_human  = $fmt_es( $date_change_ctx['new_ymd'] );
	$max_human  = $fmt_es( $summary_max_ymd );
	$item_human = $fmt_es( (string) ( $item_max_ymd ?? '' ) );

	echo 'Salía el';
	echo ' ' . EmailBrand::plano( '' === $old_human ? '—' : $old_human ) . '; ahora sale el';
	echo ' ' . EmailBrand::plano( '' === $new_human ? '—' : $new_human ) . ".\n";
	// Ver el gemelo HTML: el arribo no puede ser anterior a la salida anunciada.
	if ( '' !== $item_human && (string) ( $item_max_ymd ?? '' ) >= (string) ( $date_change_ctx['new_ymd'] ?? '' ) ) {
		echo 'Te llegaría alrededor del';
		echo ' ' . EmailBrand::plano( $item_human ) . " (contando el envío desde la editorial).\n";
	}
	if ( '' !== $item_human && '' !== $max_human && $max_human !== $item_human && (string) $summary_max_ymd >= (string) ( $date_change_ctx['new_ymd'] ?? '' ) ) {
		echo 'Tu pedido completo sale cuando llegue todo: alrededor del ' . EmailBrand::plano( $max_human ) . ".\n";
	}
	echo "\n";

	echo 'El cambio responde a un ajuste de la editorial. Tu pedido sigue reservado — no necesitas hacer nada.';
	echo "\n";
	echo 'Si prefieres cancelar y recibir reembolso, respóndenos a este correo o escríbenos por WhatsApp (el enlace está al pie de este correo).';
	echo "\n\n";
} elseif ( $is_date_announced ) {
	// Ver el gemelo HTML: «sin fecha confirmada» sólo si se compró como `tba`.
	printf(
		! empty( $bought_without_date )
			? 'Buenas noticias: tu pedido #%1$s, que compraste sin fecha confirmada, ya tiene fecha de salida.'
			: 'Te escribimos porque la editorial anunció la fecha de salida de %2$s de tu pedido #%1$s, y con ella cambia la fecha en que te llegaría.',
		EmailBrand::plano( (string) $order->get_order_number() ),
		'' !== (string) ( $date_change_ctx['product_name'] ?? '' ) ? EmailBrand::plano( (string) $date_change_ctx['product_name'] ) : 'un tomo'
	);
	echo "\n\n";

	$new_human  = $fmt_es( $date_change_ctx['new_ymd'] );
	$max_human  = $fmt_es( $summary_max_ymd );
	$item_human = $fmt_es( (string) ( $item_max_ymd ?? '' ) );

	echo 'Sale de la editorial el';
	echo ' ' . EmailBrand::plano( '' === $new_human ? '—' : $new_human ) . ".\n";
	if ( '' !== $item_human && (string) ( $item_max_ymd ?? '' ) >= (string) ( $date_change_ctx['new_ymd'] ?? '' ) ) {
		echo 'Te llegaría alrededor del';
		echo ' ' . EmailBrand::plano( $item_human ) . " (contando el envío).\n";
	}
	if ( '' !== $item_human && '' !== $max_human && $max_human !== $item_human && (string) $summary_max_ymd >= (string) ( $date_change_ctx['new_ymd'] ?? '' ) ) {
		echo 'Tu pedido completo sale cuando llegue todo: alrededor del ' . EmailBrand::plano( $max_human ) . ".\n";
	}
	echo "\n";

	echo 'Tu pedido sigue reservado — no necesitas hacer nada. Te avisaremos cuando llegue a nuestra bodega y esté listo para despachar.';
	echo "\n";
	if ( empty( $bought_without_date ) ) {
		echo 'Si la nueva fecha no te acomoda y prefieres cancelar y recibir reembolso, respóndenos a este correo o escríbenos por WhatsApp (el enlace está al pie de este correo).';
		echo "\n";
	}
	echo "\n";
} else {
	printf(
		'Te escribimos para recordarte que los productos que esperas de tu pedido #%s están por llegar.',
		EmailBrand::plano( (string) $order->get_order_number() )
	);
	echo "\n\n";

	$max_human = $fmt_es( $summary_max_ymd );
	if ( '' !== $max_human ) {
		echo 'Fecha estimada de arribo a bodega:';
		echo ' ' . EmailBrand::plano( $max_human ) . "\n\n";
	}

	echo EmailBrand::plano( sprintf( 'Cuando los tomos lleguen a nuestra bodega te enviaremos un correo confirmando que tu pedido está listo. Después despachamos en %s.', \Akibara\Business\Modules\OrderPreventa\ShipPromise::window() ) );
	echo "\n\n";
}

if ( ! empty( $preventa_items ) ) {
	echo 'Lo que esperabas:';
	echo "\n";
	foreach ( $preventa_items as $item ) {
		printf( '  - %s × %s', EmailBrand::plano( $item->get_name() ), EmailBrand::plano( (string) \Akibara\Business\Modules\OrderPreventa\PreventaItems::outstanding_qty( $order, $item ) ) );
		echo "\n";
	}
	echo "\n";
}

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
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Versión en texto plano: wp_strip_all_tags( wptexturize() ) ES el tratamiento correcto; esc_html() metería entidades.
	echo wp_strip_all_tags( wptexturize( $additional_content ) );
	echo "\n";
}
