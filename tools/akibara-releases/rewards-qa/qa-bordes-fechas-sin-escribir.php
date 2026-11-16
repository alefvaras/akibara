<?php
/**
 * QA · bordes de FECHAS **sin escribir ni una option**: llama al cálculo real
 * de la ventana por Reflection, con opciones sintéticas.
 *
 * Nace de haberle pisado la configuración del espejo a dev: la misma pregunta
 * se responde sin tocar estado compartido.
 */
$clase = '\Akibara\Business\Modules\TaxonomyDiscount\AdoptedSnapshot';
if ( ! class_exists( $clase ) ) {
	foreach ( get_declared_classes() as $c ) {
		if ( false !== strpos( $c, 'AdoptedSnapshot' ) ) { $clase = '\\' . $c; break; }
	}
}
if ( ! class_exists( $clase ) ) { echo "no encuentro AdoptedSnapshot\n"; return; }

$ref = new ReflectionClass( $clase );
if ( ! $ref->hasMethod( 'compute_window' ) ) { echo "no existe compute_window\n"; return; }
$m = $ref->getMethod( 'compute_window' );
$m->setAccessible( true );

$nombres = $ref->getConstant( 'OPTIONS' );
if ( ! is_array( $nombres ) ) { echo "no puedo leer OPTIONS\n"; return; }

$opciones = static function ( array $vals ) use ( $nombres ): array {
	$base = array(
		'akibara_taxonomy_discount_enabled'   => 'yes',
		'akibara_taxonomy_discount_from'      => '',
		'akibara_taxonomy_discount_to'        => '',
		'akibara_taxonomy_discount_apply_to'  => 'instock_only',
		'akibara_taxonomy_discount_sale_policy' => 'greatest_wins',
		'akibara_taxonomy_discount_stacks_coupons' => 'no',
		'akb_discount_max_pct'                => '15',
		'woocommerce_price_num_decimals'      => '0',
		'gmt_offset'                          => '-4',
		'timezone_string'                     => 'America/Santiago',
	);
	$base = array_merge( $base, $vals );
	$out = array();
	foreach ( $nombres as $n ) {
		$out[ $n ] = array( 'present' => array_key_exists( $n, $base ), 'raw' => (string) ( $base[ $n ] ?? '' ) );
	}
	ksort( $out );
	return $out;
};

$ahora = (int) ( getenv( 'AKB_AHORA' ) ?: time() );
echo 'reloj de la prueba: ' . gmdate( 'Y-m-d H:i', $ahora ) . " UTC\n\n";
printf( "%-46s %-8s %s\n", 'caso', 'activa', 'boundary' );

$casos = array(
	'ventana normal (04→06)'            => array( 'from' => '2026-09-04', 'to' => '2026-09-06' ),
	'un solo día (06→06)'               => array( 'from' => '2026-09-06', 'to' => '2026-09-06' ),
	'invertida (10→02)'                 => array( 'from' => '2026-09-10', 'to' => '2026-09-02' ),
	'ambas vacías'                      => array( 'from' => '', 'to' => '' ),
	'sólo desde'                        => array( 'from' => '2026-09-04', 'to' => '' ),
	'sólo hasta'                        => array( 'from' => '', 'to' => '2026-09-30' ),
	'29-feb de año NO bisiesto'         => array( 'from' => '2027-02-01', 'to' => '2027-02-29' ),
	'mes 13'                            => array( 'from' => '2026-13-01', 'to' => '2026-13-05' ),
	'texto que no es fecha'             => array( 'from' => 'ayer', 'to' => 'mañana' ),
	'empieza el día del salto (06)'     => array( 'from' => '2026-09-06', 'to' => '2026-09-20' ),
	'termina víspera del salto otoñal'  => array( 'from' => '2027-03-01', 'to' => '2027-04-03' ),
	'termina el día del salto otoñal'   => array( 'from' => '2027-03-01', 'to' => '2027-04-04' ),
	'APAGADO con ventana válida'        => array( 'enabled' => 'no', 'from' => '2026-09-04', 'to' => '2026-09-06' ),
);

foreach ( $casos as $et => $vals ) {
	$mapa = array();
	foreach ( $vals as $k => $v ) {
		$mapa[ 'enabled' === $k ? 'akibara_taxonomy_discount_enabled' : "akibara_taxonomy_discount_$k" ] = $v;
	}
	try {
		$r = $m->invoke( null, $opciones( $mapa ), 'America/Santiago', $ahora );
	} catch ( Throwable $t ) {
		printf( "%-46s EXCEPCIÓN %s\n", $et, substr( $t->getMessage(), 0, 50 ) );
		continue;
	}
	if ( null === $r ) { printf( "%-46s %-8s %s\n", $et, 'null', '(la foto se rechazaría)' ); continue; }
	$b = $r['boundary'];
	printf( "%-46s %-8s %s\n", $et, $r['active'] ? 'sí' : 'no', $b ? gmdate( 'Y-m-d H:i', $b ) . ' UTC' : 'null' );
}
