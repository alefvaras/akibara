<?php
/**
 * Soporte del e2e de consentimiento: lee la prueba de un pedido o lo borra.
 *
 *   wp eval-file akb-e2e-consentimiento.php leer <id>    → una línea JSON
 *   wp eval-file akb-e2e-consentimiento.php borrar <id>  → devuelve el stock y borra
 *
 * Lo copia al docroot y lo borra tools/akibara-e2e/specs/consentimiento-de-ofertas.spec.mjs.
 * Sin `declare(strict_types)`: `wp eval-file` evalúa el fichero dentro de otro
 * y la declaración dejaría de ser la primera sentencia (fatal).
 *
 * @package Akibara\E2E
 */

defined( 'ABSPATH' ) || exit;

( static function ( array $argumentos ): void {
	$accion = (string) ( $argumentos[0] ?? '' );
	$pedido = wc_get_order( (int) ( $argumentos[1] ?? 0 ) );

	if ( ! $pedido instanceof WC_Order ) {
		echo wp_json_encode( array( 'error' => 'sin pedido' ) ), PHP_EOL;
		return;
	}

	if ( 'borrar' === $accion ) {
		// Lo sembrado se devuelve a su estado: el stock que la compra restó, y el pedido.
		wc_maybe_increase_stock_levels( $pedido->get_id() );
		$pedido->delete( true );
		echo wp_json_encode( array( 'borrado' => $pedido->get_id() ) ), PHP_EOL;
		return;
	}

	$prueba = $pedido->get_meta( '_akb_marketing_consent', true );
	$notas  = array();
	foreach ( wc_get_order_notes( array( 'order_id' => $pedido->get_id() ) ) as $nota ) {
		if ( str_contains( (string) $nota->content, 'ofertas y novedades' ) ) {
			$notas[] = (string) $nota->content;
		}
	}

	echo wp_json_encode(
		array(
			'prueba'   => is_string( $prueba ) && '' !== $prueba ? json_decode( $prueba, true ) : null,
			'notas'    => $notas,
			'meta_woo' => $pedido->get_meta( '_wc_other/SendinblueWoocommerce/newsletter_opt_in', true ),
		),
		JSON_UNESCAPED_UNICODE
	), PHP_EOL;
} )( $args ?? array() );
