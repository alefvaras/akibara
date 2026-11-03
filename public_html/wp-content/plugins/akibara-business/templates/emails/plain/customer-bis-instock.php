<?php
/**
 * Email "volvió a stock" (lista de espera) — versión plain text.
 *
 * Texto plano sin `esc_html()` ni `esc_url()`: ver el porqué en
 * `plain/customer-preventa-ready.php`.
 *
 * @package Akibara\Business
 *
 * @var \WC_Product|null $product
 * @var string           $product_name
 * @var string           $product_price_html
 * @var string           $product_url
 * @var string           $availability_state
 * @var string           $availability_label
 * @var string           $cutoff_phrase
 * @var string           $timeframe_copy
 * @var string           $cta_label
 * @var string           $footnote_copy
 * @var string           $unsubscribe_url
 * @var string           $email_heading
 * @var string           $additional_content
 * @var bool             $plain_text
 * @var \WC_Email        $email
 */

use Akibara\Business\Modules\EmailBrand\EmailBrand;
use Akibara\Business\Modules\Stock\Availability;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- N-phpcs-plain (2026-09-19): TEXTO PLANO, sin HTML que proteger; el porqué completo está en el docblock de `plain/customer-preventa-ready.php`.

echo EmailBrand::plano( $email_heading ) . "\n";
echo "==========\n\n";

echo "¡Buenas noticias!\n\n";

if ( Availability::PREVENTA === ( $availability_state ?? '' ) ) {
	printf(
		"%s ya se puede reservar en preventa. Pediste que te avisáramos cuando se habilitara — este es tu aviso.\n\n",
		EmailBrand::plano( $product_name )
	);
} elseif ( Availability::A_PEDIDO === ( $availability_state ?? '' ) ) {
	printf(
		"%s ya se puede encargar a pedido. Pediste que te avisáramos cuando volviera a habilitarse — este es tu aviso.\n\n",
		EmailBrand::plano( $product_name )
	);
} else {
	printf(
		"%s está de vuelta en stock. Pediste que te avisáramos — este es tu aviso.\n\n",
		EmailBrand::plano( $product_name )
	);
}

if ( '' !== EmailBrand::plano( (string) ( $availability_label ?? '' ) ) ) {
	if ( '' !== EmailBrand::plano( (string) ( $timeframe_copy ?? '' ) ) ) {
		printf(
			"Estado: %s — %s\n\n",
			EmailBrand::plano( (string) $availability_label ),
			EmailBrand::plano( (string) $timeframe_copy )
		);
	} else {
		printf(
			"Estado: %s\n\n",
			EmailBrand::plano( (string) $availability_label )
		);
	}
}

// El precio va también aquí: la mitad HTML lo muestra y ésta se lo dejaba, así
// que quien lee el correo en texto plano —clientes antiguos, modo sin imágenes,
// algunos filtros corporativos— tenía que abrir la ficha para saber cuánto vale.
// `$product_price_html` viene formateado por WooCommerce (trae <span> y &nbsp;),
// de ahí que pase por `EmailBrand::plano()` antes de imprimirse.
// Sin variable intermedia: la plantilla se incluye en ámbito global y cualquier
// local quedaría como variable global sin prefijo (WPCS PrefixAllGlobals).
if ( '' !== EmailBrand::plano( $product_price_html ) ) {
	printf(
		"Precio: %s\n\n",
		EmailBrand::plano( $product_price_html )
	);
}

echo EmailBrand::plano( '' !== ( $cta_label ?? '' ) ? (string) $cta_label : 'Comprar ahora' ) . ":\n";
echo esc_url_raw( $product_url ) . "\n\n";

echo EmailBrand::plano( '' !== ( $footnote_copy ?? '' ) ? (string) $footnote_copy : 'El stock puede ser limitado: la mayoría de nuestros tomos tienen pocas unidades por edición.' ) . "\n\n";

if ( '' !== $additional_content ) {
	echo EmailBrand::plano( wptexturize( $additional_content ) ) . "\n\n";
}

echo "----------\n";
echo "Recibiste este aviso porque te anotaste en la lista de espera de este producto.\n";
echo 'No avisarme más: ' . esc_url_raw( $unsubscribe_url ) . "\n";
