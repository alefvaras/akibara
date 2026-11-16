<?php
// Sonda 3: confirmar que el bloqueo es reprove_physical_receipt() devolviendo
// null cuando el RECIBO NO EXISTE (ausencia leída como "transitorio").
use Akibara\Search\EditorialDiscountPublication;

global $wpdb;
$out = static function ( string $l ): void { fwrite( STDOUT, $l . "\n" ); };
$ref = new ReflectionClass( EditorialDiscountPublication::class );
$call = static function ( string $n, array $a = array() ) use ( $ref ) {
	$m = $ref->getMethod( $n ); $m->setAccessible( true ); return $m->invoke( null, ...$a );
};
$state_now = static function () use ( $call ) { return $call( 'read_state' ); };
$OPT_STATE   = $ref->getConstant( 'OPTION_STATE' );
$OPT_RECEIPT = $ref->getConstant( 'OPTION_RECEIPT' );

// --- reset del estado al punto exacto que deja el gate ---
$s = $state_now();
$s['purge_post_seq'] = 0;
unset( $s['purge_post_at'] );
$s['updated_at'] = time();
$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s", wp_json_encode( $s ), $OPT_STATE ) );
wp_cache_flush();
$out( 'ESTADO DE PARTIDA: ' . (string) wp_json_encode( $state_now() ) );
$out( 'RECIBO presente: ' . var_export( null !== $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $OPT_RECEIPT ) ), true ) );

// --- ESCENARIO A: recibo AUSENTE (como lo deja el gate) ---
$inst = ( $ref->getProperty( 'runtime' ) );
$inst->setAccessible( true );
$runtime = $inst->getValue();
$m_reprove = $ref->getMethod( 'reprove_physical_receipt' );
$m_reprove->setAccessible( true );
$source = $call( 'stable_source_snapshot' );
$out( '' );
$out( '--- A) recibo AUSENTE ---' );
$out( '  source es array: ' . var_export( is_array( $source ), true ) );
$r = $m_reprove->invoke( $runtime, $source, $state_now() );
$out( '  reprove_physical_receipt() = ' . var_export( $r, true ) . '   (null => el caller hace return false y NO llega al POST)' );
$out( '  reconcile_now() = ' . var_export( EditorialDiscountPublication::reconcile_now(), true ) );
$out( '  estado: ' . (string) wp_json_encode( $state_now() ) );

// --- ESCENARIO B: mismo estado, pero con una fila de recibo NO vacía e inválida ---
$out( '' );
$out( '--- B) misma situación, pero con fila de recibo presente e ILEGIBLE ---' );
$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $OPT_RECEIPT, 'basura-no-serializada' ) );
wp_cache_flush();
$call( 'invalidate_runtime_memo' );
$r2 = $m_reprove->invoke( $runtime, $source, $state_now() );
$out( '  reprove_physical_receipt() = ' . var_export( $r2, true ) . '   (false => el caller SÍ sigue al PRE/POST)' );
$out( '  reconcile_now() = ' . var_export( EditorialDiscountPublication::reconcile_now(), true ) );
$out( '  estado: ' . (string) wp_json_encode( $state_now() ) );
$fin = $state_now();
$out( '' );
$out( 'VEREDICTO: purge_post_seq=' . (int) $fin['purge_post_seq'] . ' / wanted_seq=' . (int) $fin['wanted_seq'] );
