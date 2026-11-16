<?php
// QA · prueba de «salió el tomo N de tu serie» EN EL ESPEJO.
// AKB_PASO = preparar | publicar | mirar | digerir | limpiar
//
// LECCIÓN CARA (06-sep): la primera versión de este script sembraba mal y la
// prueba no discriminaba nada. Dos fallos, los dos en la semilla:
//   1. set_name( ... . ' 999 ' . $marca )  → el título terminaba en «BORRAR».
//      VolumeTitle::extract_number() exige el número AL FINAL:
//      /(?:^|[\s#º°-])0*(\d{1,4})\s*$/u  → devolvía null → descarte.
//   2. set_catalog_visibility('hidden')   → WooCommerce escribe el término
//      'exclude-from-catalog', que es justo lo que product_is_announceable()
//      rechaza.
// Condición completa para que un producto se anuncie (DigestRunner:583 + SeriesResolver):
//   publish · NO exclude-from-catalog · post_date_gmt dentro de NOVELTY_DAYS=60
//   · exactamente un pa_serie · número de tomo inequívoco al final del título.
global $wpdb;
$paso  = getenv( 'AKB_PASO' ) ?: 'mirar';
$serie = (int) ( getenv( 'AKB_SERIE' ) ?: 400 );   // Jujutsu Kaisen
$cola  = $wpdb->prefix . 'akb_series_follow_queue';
$marca = 'QA-SERIES-FOLLOW-BORRAR';
$tomo  = (int) ( getenv( 'AKB_TOMO' ) ?: 999 );   // permite sembrar VARIOS tomos de la misma serie

$mirar = static function () use ( $wpdb, $cola, $marca ) {
	echo 'sf_enabled = ' . get_option( 'akb_sf_enabled' ) . "\n";
	echo 'cola = ' . (int) $wpdb->get_var( "SELECT COUNT(*) FROM $cola" ) . " filas\n";
	foreach ( $wpdb->get_results( "SELECT * FROM $cola ORDER BY id DESC LIMIT 8" ) as $r ) {
		echo '  ' . wp_json_encode( $r ) . "\n";
	}
	$q = new WP_Query( array( 'post_type' => 'product', 'post_status' => 'any', 's' => $marca, 'fields' => 'ids', 'posts_per_page' => 5 ) );
	echo 'productos de QA: ' . implode( ', ', $q->posts ) . "\n";
	$as = $wpdb->get_results( "SELECT action_id, hook, status, scheduled_date_gmt FROM {$wpdb->prefix}actionscheduler_actions WHERE hook LIKE '%sf_%' ORDER BY action_id DESC LIMIT 6" );
	foreach ( $as as $a ) { echo "  accion {$a->action_id} {$a->hook} {$a->status} {$a->scheduled_date_gmt}\n"; }
};

if ( 'preparar' === $paso ) {
	update_option( 'akb_sf_enabled', 'yes' );
	update_option( 'akb_sf_digest_delay_hours', 0 );  // no esperar 6 h en la prueba
	echo "interruptor encendido, retraso del resumen a 0 h\n"; $mirar(); return;
}

if ( 'publicar' === $paso ) {
	$modelo = $wpdb->get_var( $wpdb->prepare(
		"SELECT tr.object_id FROM {$wpdb->term_relationships} tr
		 JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
		 JOIN {$wpdb->posts} p ON p.ID = tr.object_id AND p.post_type='product' AND p.post_status='publish'
		 WHERE tt.term_id = %d LIMIT 1", $serie ) );
	if ( ! $modelo ) { echo "no encuentro un producto modelo de la serie $serie\n"; return; }
	$m = wc_get_product( (int) $modelo );
	echo 'modelo: ' . $modelo . ' · ' . $m->get_name() . "\n";

	// El número VA AL FINAL. 999 no colisiona con ningún tomo real.
	$titulo = 'Jujutsu Kaisen ' . $marca . ' ' . $tomo;

	$nuevo = new WC_Product_Simple();
	$nuevo->set_name( $titulo );
	$nuevo->set_status( 'publish' );
	$nuevo->set_regular_price( (string) ( $m->get_regular_price() ?: 9990 ) );
	$nuevo->set_manage_stock( true );
	$nuevo->set_stock_quantity( 3 );
	$nuevo->set_stock_status( 'instock' );
	$nuevo->set_catalog_visibility( 'visible' );  // exclude-from-catalog descarta el aviso
	$id = $nuevo->save();
	wp_set_object_terms( $id, array( $serie ), 'pa_serie' );
	// re-publica para que el listener vea el término ya puesto
	wp_update_post( array( 'ID' => $id, 'post_status' => 'publish' ) );
	echo "publicado el producto de prueba $id · [$titulo]\n";

	// Verificación de la semilla ANTES de culpar al módulo (regla 2: sospecha del instrumento).
	$res = \Akibara\Business\Modules\SeriesFollow\SeriesResolver::resolve( (int) $id );
	echo 'SeriesResolver::resolve → ' . ( null === $res ? 'NULL (semilla inválida)' : wp_json_encode( $res ) ) . "\n";
	$ref = new ReflectionMethod( \Akibara\Business\Modules\SeriesFollow\DigestRunner::class, 'product_is_announceable' );
	$ref->setAccessible( true );
	$runner = null;
	foreach ( $GLOBALS['wp_filter']['akb_sf_scan_product']->callbacks[10] ?? array() as $cb ) {
		if ( is_array( $cb['function'] ) && $cb['function'][0] instanceof \Akibara\Business\Modules\SeriesFollow\DigestRunner ) { $runner = $cb['function'][0]; }
	}
	echo 'product_is_announceable → ' . ( $runner ? var_export( $ref->invoke( $runner, (int) $id ), true ) : 'no encuentro el runner' ) . "\n";
	$mirar(); return;
}

if ( 'destopar' === $paso ) {
	// SOLO ESPEJO. El tope de un correo por semana bloquearía la prueba si el
	// cliente ya recibió un aviso hoy. Borro la marca de los que tienen fila
	// pendiente para que la corrida los considere.
	$ids = $wpdb->get_col( "SELECT DISTINCT user_id FROM $cola WHERE status = 'pending'" );
	foreach ( $ids as $u ) {
		delete_user_meta( (int) $u, '_akb_sf_last_sent' );
		echo "tope soltado para el cliente $u\n";
	}
	$mirar(); return;
}

if ( 'digerir' === $paso ) {
	do_action( 'akb_sf_digest_run', 0 );
	echo "resumen ejecutado\n"; $mirar(); return;
}

if ( 'limpiar' === $paso ) {
	$q = new WP_Query( array( 'post_type' => 'product', 'post_status' => 'any', 's' => $marca, 'fields' => 'ids', 'posts_per_page' => 20 ) );
	foreach ( $q->posts as $id ) { wp_delete_post( (int) $id, true ); echo "borrado $id\n"; }
	$wpdb->query( "DELETE FROM $cola" );
	update_option( 'akb_sf_enabled', 'no' );
	delete_option( 'akb_sf_digest_delay_hours' );
	echo "cola vaciada e interruptor de vuelta en 'no'\n";
	$mirar(); return;
}

$mirar();
