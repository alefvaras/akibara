<?php
/**
 * Enriquecimiento y estructuración de fichas técnicas en descripciones de pa_serie (Regla 17).
 *
 * Añade la ficha técnica canónica estructurada (Autor, Demografía, Editorial oficial,
 * Estado en Japón y Estado de la edición) al cierre de la descripción de cada serie
 * que actualmente posea sólo sinopsis plana.
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

echo "============================================================\n";
echo "  ESTRUCTURACIÓN DE FICHAS TÉCNICAS EN pa_serie (REGLA 17) \n";
echo "============================================================\n\n";

$terms = get_terms(
	array(
		'taxonomy'   => 'pa_serie',
		'hide_empty' => false,
	)
);

echo "Total series evaluadas: " . count( $terms ) . "\n\n";

$updated_count = 0;
$already_ok    = 0;

$demografias_validas = array(
	'Shonen',
	'Seinen',
	'Shojo',
	'Josei',
	'Kodomo',
	'Spokon',
	'Yaoi',
	'Yuri',
	'Cómics',
);

foreach ( $terms as $t ) {
	$tid  = (int) $t->term_id;
	$desc = trim( (string) $t->description );

	// Si ya contiene la ficha estructurada con Estado en Japón o Ficha técnica, omitir
	if ( false !== strpos( $desc, 'Estado en Japón:' ) || false !== strpos( $desc, '<strong>Autor original:</strong>' ) ) {
		++$already_ok;
		continue;
	}

	// Obtener productos asociados a la serie para extraer metadatos consolidados
	$posts = get_posts(
		array(
			'post_type'      => 'product',
			'posts_per_page' => 20,
			'tax_query'      => array(
				array(
					'taxonomy' => 'pa_serie',
					'field'    => 'term_id',
					'terms'    => $tid,
				),
			),
		)
	);

	$autores    = array();
	$demografia = array();
	$editorial  = array();
	$pais       = array();

	foreach ( $posts as $p ) {
		foreach ( wp_get_post_terms( $p->ID, 'pa_autor', array( 'fields' => 'names' ) ) as $a ) {
			$autores[ $a ] = true;
		}
		foreach ( wp_get_post_terms( $p->ID, 'product_cat', array( 'fields' => 'names' ) ) as $c ) {
			if ( in_array( $c, $demografias_validas, true ) ) {
				$demografia[ $c ] = true;
			}
		}
		foreach ( wp_get_post_terms( $p->ID, 'product_brand', array( 'fields' => 'names' ) ) as $b ) {
			$editorial[ $b ] = true;
		}
		foreach ( wp_get_post_terms( $p->ID, 'pa_pais', array( 'fields' => 'names' ) ) as $pa ) {
			$pais[ $pa ] = true;
		}
	}

	$estado_jp  = (string) get_term_meta( $tid, '_akb_serie_estado', true );
	$tomos_jp   = (string) get_term_meta( $tid, '_akb_serie_tomos_obra', true );
	$estado_ed  = (string) get_term_meta( $tid, '_akb_serie_estado_edicion', true );
	$tomos_ed   = (string) get_term_meta( $tid, '_akb_serie_tomos_edicion', true );

	// Formatear Estado JP
	if ( 'completa' === $estado_jp ) {
		$estado_jp_str = 'Finalizada' . ( ! empty( $tomos_jp ) ? " ({$tomos_jp} tomos)" : '' );
	} elseif ( 'en_pausa' === $estado_jp ) {
		$estado_jp_str = 'En pausa' . ( ! empty( $tomos_jp ) ? " ({$tomos_jp} tomos publicados)" : '' );
	} elseif ( 'cancelada' === $estado_jp ) {
		$estado_jp_str = 'Cancelada' . ( ! empty( $tomos_jp ) ? " ({$tomos_jp} tomos)" : '' );
	} else {
		$estado_jp_str = 'En curso' . ( ! empty( $tomos_jp ) ? " ({$tomos_jp} tomos publicados)" : '' );
	}

	// Formatear Estado Edición
	$pais_str = ! empty( $pais ) ? implode( ', ', array_keys( $pais ) ) : 'España';
	if ( 'completa' === $estado_ed ) {
		$estado_ed_str = 'Completa' . ( ! empty( $tomos_ed ) ? " ({$tomos_ed} tomos)" : '' );
	} elseif ( 'al_dia' === $estado_ed ) {
		$estado_ed_str = 'Al día con Japón' . ( ! empty( $tomos_ed ) ? " ({$tomos_ed} tomos)" : '' );
	} elseif ( 'en_pausa' === $estado_ed ) {
		$estado_ed_str = 'En pausa' . ( ! empty( $tomos_ed ) ? " ({$tomos_ed} tomos)" : '' );
	} else {
		$estado_ed_str = 'En curso' . ( ! empty( $tomos_ed ) ? " ({$tomos_ed} tomos publicados)" : '' );
	}

	$autores_str    = ! empty( $autores ) ? implode( ', ', array_keys( $autores ) ) : 'Varios autores';
	$demografia_str = ! empty( $demografia ) ? implode( ', ', array_keys( $demografia ) ) : 'Manga';
	$editorial_str  = ! empty( $editorial ) ? implode( ', ', array_keys( $editorial ) ) : 'Editorial oficial';

	// Construir bloque estructurado HTML limpio
	$ficha_lines = array();
	$ficha_lines[] = "<strong>Autor:</strong> {$autores_str}";
	$ficha_lines[] = "<strong>Demografía:</strong> {$demografia_str}";
	$ficha_lines[] = "<strong>Editorial oficial:</strong> {$editorial_str} ({$pais_str})";
	$ficha_lines[] = "<strong>Estado en Japón:</strong> {$estado_jp_str}";
	$ficha_lines[] = "<strong>Estado de la edición:</strong> {$estado_ed_str}";

	$specs_html = "<p>" . implode( "<br />\n", $ficha_lines ) . "</p>";

	$new_desc = ! empty( $desc ) ? $desc . "\n\n" . $specs_html : $specs_html;

	wp_update_term( $tid, 'pa_serie', array( 'description' => $new_desc ) );
	clean_term_cache( $tid, 'pa_serie' );
	++$updated_count;

	if ( $updated_count <= 5 || 0 === $updated_count % 25 ) {
		echo "• Serie #{$tid} [{$t->name}]:\n";
		echo "   Autor: {$autores_str} | Demo: {$demografia_str} | Edit: {$editorial_str} ({$pais_str})\n";
		echo "   JP: {$estado_jp_str} | Edición: {$estado_ed_str}\n\n";
	}
}

echo "============================================================\n";
echo "Resumen de estructuración de series:\n";
echo "  - Series actualizadas con ficha técnica: {$updated_count}\n";
echo "  - Series que ya tenían ficha técnica:   {$already_ok}\n";
echo "============================================================\n";

do_action( 'litespeed_purge_all' );
echo "[RESULTADO]: Estructuración de fichas técnicas en series completada.\n";
