<?php
/**
 * tools/update_steins_excerpts_and_stock.php
 *
 * Actualiza:
 * 1. post_excerpt (descripción corta obligatoria - Regla 14) en los 9 tomos de Steins;Gate y Steins;Gate 0.
 * 2. Steins;Gate 03 a estado OUTOFSTOCK (agotado por reimpresión en editorial - Regla 15).
 * 3. Actualización de lookup tables, flushing de caché y reindexación en akibara-search.
 */

if ( file_exists( '/var/www/html/wp-load.php' ) ) {
	require_once '/var/www/html/wp-load.php';
} elseif ( file_exists( '/home/u888022333/domains/akibara.cl/public_html/wp-load.php' ) ) {
	require_once '/home/u888022333/domains/akibara.cl/public_html/wp-load.php';
} else {
	die( "Error: wp-load.php no encontrado.\n" );
}

$excerpts = array(
	'steins-gate-1-ivrea-espana'   => '<strong>Steins;Gate 01</strong> da inicio a la aclamada saga de ciencia ficción. Rintaro Okabe y los miembros del Laboratorio de Artefactos del Futuro descubren accidentalmente cómo enviar mensajes al pasado, desatando una peligrosa alteración en la línea temporal. Edición oficial con sobrecubierta y despacho a todo Chile.',
	'steins-gate-2-ivrea-espana'   => '<strong>Steins;Gate 02</strong> intensifica la conspiración. Los experimentos con los D-Mails comienzan a cobrar un alto precio y la sombra de SERN se cierne sobre Akihabara mientras Okabe lucha por evitar una tragedia inminente. Edición oficial con sobrecubierta y despacho a todo Chile.',
	'steins-gate-3-ivrea-espana'   => '<strong>Steins;Gate 03</strong> concluye el clásico del viaje en el tiempo. Desesperado por romper el bucle temporal del destino y salvar a sus seres más queridos, Okabe deberá tomar la decisión más dolorosa de su vida para alcanzar la esquiva línea Steins Gate. Tomo final con despacho a todo Chile.',
	'steins-gate-0-1-ivrea-espana' => '<strong>Steins;Gate 0 01</strong> explora la sombría línea temporal Beta. Tras fracasar en el intento de salvar a Kurisu, un abatido Okabe renuncia a su alter ego científico hasta que conoce a Maho Hiyajo y al revolucionario sistema de inteligencia artificial Amadeus. Inicio de la secuela con despacho a todo Chile.',
	'steins-gate-0-2-ivrea-espana' => '<strong>Steins;Gate 0 02</strong> profundiza en los secretos de la memoria artificial. Las interacciones de Okabe con el avatar de Kurisu en Amadeus reabren viejas heridas, al tiempo que extrañas interferencias y misteriosos asaltantes amenazan la tranquilidad del laboratorio. Edición oficial con despacho a todo Chile.',
	'steins-gate-0-3-ivrea-espana' => '<strong>Steins;Gate 0 03</strong> desata el conflicto a escala global. Espías internacionales, agentes de inteligencia y corporaciones tecnológicas se disputan las investigaciones de Kurisu sobre el salto temporal, arrastrando a Okabe al borde de una nueva crisis mundial. Edición oficial con despacho a todo Chile.',
	'steins-gate-0-4-ivrea-espana' => '<strong>Steins;Gate 0 04</strong> pone a prueba los límites de la tecnología. Okabe y Maho descubren una grave brecha de seguridad en Amadeus, mientras los recuerdos reprimidos de la Tercera Guerra Mundial amenazan con materializarse en el presente. Edición oficial con despacho a todo Chile.',
	'steins-gate-0-5-ivrea-espana' => '<strong>Steins;Gate 0 05</strong> acerca el clímax de la línea Beta. Con el destino del mundo pendiendo de un hilo, Okabe debe reunir el coraje que creía perdido para liderar a sus compañeros frente al colapso temporal y la ofensiva militar enemiga. Edición oficial con despacho a todo Chile.',
	'steins-gate-0-6-ivrea-espana' => '<strong>Steins;Gate 0 06</strong> marca el gran desenlace de la saga cero. En una carrera contra el reloj y bajo fuego cruzado en la azotea de Radio Kaikan, se sella el sacrificio que conecta directamente con la salvación del futuro y el verdadero final de Steins;Gate. Gran tomo de cierre con despacho a todo Chile.',
);

echo "=================================================================\n";
echo " ACTUALIZANDO EXCERPTS Y ESTADOS DE STOCK\n";
echo "=================================================================\n\n";

$reindex_ids = array();

foreach ( $excerpts as $slug => $short_desc ) {
	$p = get_page_by_path( $slug, OBJECT, 'product' );
	if ( ! $p ) {
		echo "⚠️ No encontrado: $slug\n";
		continue;
	}

	$pid = $p->ID;
	$reindex_ids[] = $pid;

	// Actualizar post_excerpt
	wp_update_post( array(
		'ID'           => $pid,
		'post_excerpt' => $short_desc,
	) );
	echo "✓ [{$pid}] {$p->post_title}: post_excerpt actualizado (" . mb_strlen( $short_desc ) . " caracteres).\n";

	// Si es Steins;Gate 03, aplicar estrictamente Regla 15 (Agotado por reimpresión)
	if ( 'steins-gate-3-ivrea-espana' === $slug ) {
		update_post_meta( $pid, '_stock_status', 'outofstock' );
		update_post_meta( $pid, '_backorders', 'no' );
		update_post_meta( $pid, '_manage_stock', 'no' );
		update_post_meta( $pid, '_stock', null );
		delete_post_meta( $pid, '_sale_price' );
		update_post_meta( $pid, '_price', '13990' );
		echo "  -> Estado fijado en OUTOFSTOCK (Agotado por reimpresión en editorial). Precio: $13.990\n";
	}

	if ( function_exists( 'wc_update_product_lookup_tables' ) ) {
		wc_update_product_lookup_tables( $pid );
	}
}

if ( function_exists( 'wp_cache_flush' ) ) {
	wp_cache_flush();
}

// Reindexar en akibara-search
foreach ( $reindex_ids as $id ) {
	if ( class_exists( '\\Akibara_Search\\Indexer' ) && method_exists( '\\Akibara_Search\\Indexer', 'reindex_post' ) ) {
		\Akibara_Search\Indexer::reindex_post( $id, true );
	}
}
echo "✓ " . count( $reindex_ids ) . " productos reindexados en el buscador.\n";
echo "\n¡PROCESO COMPLETADO EXITOSAMENTE!\n";
