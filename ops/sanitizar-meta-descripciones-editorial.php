<?php
/**
 * Sanitización de meta descripciones de Rank Math que mencionan editoriales (Regla 13).
 *
 * Elimina menciones a editoriales (Devir, Ovni Press, Panini, Planeta, Ivrea, etc.)
 * en rank_math_description y ajusta la longitud al rango estricto de 140-155 caracteres.
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

echo "============================================================\n";
echo "  SANITIZACIÓN DE META DESCRIPCIONES SEO (REGLA 13 AKIBARA)\n";
echo "============================================================\n\n";

$publishers = array(
	'Ivrea España',
	'Ivrea Argentina',
	'Ivrea',
	'Panini España',
	'Panini Argentina',
	'Panini',
	'Planeta Cómic',
	'Planeta Comic',
	'Planeta',
	'Milky Way Ediciones',
	'Milky Way',
	'Distrito Manga',
	'Devir',
	'Ovni Press',
	'Ovni',
	'Arechi Manga',
	'Arechi',
);

$sql = "SELECT p.ID, p.post_title, pm.meta_value as meta_desc
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'rank_math_description'
        WHERE p.post_type = 'product' AND p.post_status = 'publish'
          AND pm.meta_value REGEXP 'Devir|Ovni Press|Panini|Planeta|Ivrea|Milky Way|Distrito Manga|Arechi'";

$rows = $wpdb->get_results( $sql );
echo "Productos encontrados con mención de editorial: " . count( $rows ) . "\n\n";

$updated_count = 0;

foreach ( $rows as $r ) {
	$pid     = (int) $r->ID;
	$title   = (string) $r->post_title;
	$old_desc = (string) $r->meta_desc;
	$new_desc = $old_desc;

	// Caso específico 1: Attack on Titan Deluxe 05
	if ( false !== strpos( $title, 'Attack On Titan Edición Deluxe 05' ) ) {
		$new_desc = 'Compra Attack On Titan Edición Deluxe 05: la Legión es perseguida, Levi y Erwin investigan una conspiración y sale a la luz el pasado de Historia.';
	}
	// Caso específico 2: Boticaria (Devir)
	elseif ( preg_match( '/Los diarios de la boticaria (\d{2})/i', $title, $m ) ) {
		$vol_num = $m[1];
		$new_desc = "Consigue Los diarios de la boticaria {$vol_num} en Chile. Edición oficial en español con reserva del 5% de descuento y despacho rápido a todo el país.";
	}
	// Caso general
	else {
		// Reemplazos genéricos
		$replacements = array(
			' de Ovni Press:'               => ':',
			' de Ovni Press'                => ' oficial',
			' Ovni Press'                   => ' edición oficial',
			' (Devir)'                      => '',
			' Devir'                        => '',
			' de Panini España'             => ' en edición oficial',
			' Panini España y envío'        => ' Edición oficial y envío',
			' Panini España'                => ' edición oficial',
			' Edición Planeta Cómic España' => ' Edición oficial en español',
			' de Planeta Cómic'             => ' en edición oficial',
			' Edición Planeta Cómic'        => ' Edición oficial',
			' Planeta Cómic'                => ' edición oficial',
			' de Ivrea España'              => ' en edición oficial',
			' de Ivrea Argentina'           => ' en edición oficial',
			' Ivrea España'                 => ' edición oficial',
			' Ivrea Argentina'              => ' edición oficial',
			' de Milky Way Ediciones'       => ' en edición oficial',
			' de Milky Way'                 => ' en edición oficial',
			' Milky Way'                    => ' edición oficial',
			' de Distrito Manga'            => ' en edición oficial',
			' Distrito Manga'               => ' edición oficial',
		);
		$new_desc = str_replace( array_keys( $replacements ), array_values( $replacements ), $old_desc );
		$new_desc = preg_replace( '/\s+/', ' ', trim( $new_desc ) );
	}

	$len = mb_strlen( $new_desc );

	// Si difiere, actualizar postmeta
	if ( $new_desc !== $old_desc ) {
		update_post_meta( $pid, 'rank_math_description', $new_desc );
		clean_post_cache( $pid );
		++$updated_count;
		echo "• ID {$pid} [{$title}]:\n";
		echo "   Antes : \"{$old_desc}\"\n";
		echo "   Ahora : \"{$new_desc}\" (Len: {$len} chars)\n\n";
	}
}

echo "Total meta descripciones sanitizadas: {$updated_count}\n";
do_action( 'litespeed_purge_all' );
echo "[RESULTADO]: Sanitización de descripciones completada.\n";
