<?php
/**
 * Email «salió el siguiente tomo de tu serie» — versión plain text.
 *
 * Texto plano sin `esc_html()` ni `esc_url()`: ver el porqué en
 * `plain/customer-preventa-ready.php`.
 *
 * @package Akibara\Business
 *
 * @var array<int, array<string, mixed>> $groups
 * @var string                           $collection_url
 * @var string                           $unsubscribe_url
 * @var string                           $email_heading
 * @var string                           $additional_content
 * @var bool                             $plain_text
 * @var \WC_Email                        $email
 */

use Akibara\Business\Modules\EmailBrand\EmailBrand;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- N-phpcs-plain (2026-09-19): TEXTO PLANO, sin HTML que proteger; el porqué completo está en el docblock de `plain/customer-preventa-ready.php`.

echo EmailBrand::plano( $email_heading ) . "\n";
echo "==========\n\n";

// La MISMA entradilla de tres ramas que la parte HTML. `email_type` es
// `multipart`, así que las dos partes salen SIEMPRE: una que mienta es media
// promesa rota, y la lee justo quien tiene el cliente en modo texto.
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
	$akibara_intro = str_replace( '{n_tomos}', (string) $akibara_tomos, 'Salieron {n_tomos} tomos nuevos de una serie que estás siguiendo.' );
}

echo EmailBrand::plano( $akibara_intro ) . "\n\n";

foreach ( $groups as $akibara_group ) {
	$akibara_tanda = max( 1, (int) ( $akibara_group['volumes_count'] ?? 1 ) ) > 1;
	$akibara_frase = (string) ( $akibara_group['volumes_phrase'] ?? '' );
	$akibara_tomo  = (string) $akibara_group['volume'];
	$akibara_plazo = '' !== (string) ( $akibara_group['cutoff'] ?? '' )
		? (string) $akibara_group['cutoff']
		: (string) ( $akibara_group['timeframe_copy'] ?? '' );

	if ( ! empty( $akibara_group['is_gap'] ) ) {
		echo EmailBrand::plano( 'Cierra el hueco de tu colección' ) . "\n";
	}

	// Los DOS formatos son literales de código con los datos por argumento: eso
	// es seguro y se queda. Lo que NUNCA puede entrar aquí es un texto del dueño
	// como primer argumento.
	if ( $akibara_tanda && '' !== $akibara_frase ) {
		printf( "%s — tomos %s\n", EmailBrand::plano( (string) $akibara_group['series_name'] ), EmailBrand::plano( $akibara_frase ) );
	} else {
		printf( "%s — tomo %s\n", EmailBrand::plano( (string) $akibara_group['series_name'] ), EmailBrand::plano( $akibara_tomo ) );
	}

	// La parte plana no imprime precio, hoy tampoco. El prefijo va igual: sin él,
	// «Disponible» debajo de «tomos 71 al 74» se lee como el estado de los cuatro.
	$akibara_estado = (string) $akibara_group['availability'];
	if ( '' !== $akibara_plazo ) {
		$akibara_estado .= ' · ' . $akibara_plazo;
	}
	if ( $akibara_tanda ) {
		$akibara_estado = 'Tomo ' . $akibara_tomo . ': ' . $akibara_estado;
	}
	echo EmailBrand::plano( $akibara_estado ) . "\n";

	echo esc_url_raw( (string) $akibara_group['url'] ) . "\n";
	echo 'Darme de baja de esta serie: ' . esc_url_raw( (string) $akibara_group['unfollow_url'] ) . "\n\n";
}

echo "Ver mi colección completa:\n";
echo esc_url_raw( $collection_url ) . "\n\n";

if ( '' !== $additional_content ) {
	echo EmailBrand::plano( wptexturize( $additional_content ) ) . "\n\n";
}

echo "----------\n";
echo "Recibiste este aviso porque ya compraste tomos anteriores de estas series.\n";
echo 'No avisarme de ninguna serie: ' . esc_url_raw( $unsubscribe_url ) . "\n";
