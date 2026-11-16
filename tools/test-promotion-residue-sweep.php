<?php
/**
 * Candado del barrido de higiene del riel de promoción; no abre red ni SSH.
 *
 * INVARIANTE QUE FIJA: el barrido tiene que casar el residuo que el despliegue
 * produce DE VERDAD. El swap directo deja respaldos con la forma
 * `.akibara-business-old-1788174146` y `.akibara-provider-access.php.old-1788174146`
 * —punto delante, epoch detrás de «old»— mientras el patrón pedía el prefijo
 * sin punto y `.old` como sufijo: con los tres residuos puestos el gate daba
 * limpio, y el de mu-plugins terminó sirviéndose en texto plano desde el
 * docroot de producción (24.838 B de código fuente).
 *
 * Los patrones se LEEN del riel, no se copian aquí: si alguien los recorta, este
 * candado se entera. `fnmatch()` aplica las mismas reglas de glob que
 * `find -name`.
 *
 * Uso: php tools/test-promotion-residue-sweep.php
 */

declare( strict_types = 1 );

/** @param mixed $actual */
function akibara_assert( bool $condition, string $message, $actual = null ): void {
	if ( $condition ) {
		return;
	}

	fwrite( STDERR, 'FAIL: ' . $message . ': ' . var_export( $actual, true ) . PHP_EOL );
	exit( 1 );
}

$riel = dirname( __DIR__ ) . '/ops/akibara-code-promotion.sh';
akibara_assert( is_readable( $riel ), 'no se encuentra el riel de promoción', $riel );

$fuente = (string) file_get_contents( $riel );

preg_match_all(
	'/find "\$\{AKB_WEBROOT\}\/wp-content\/plugins".*?\\\\\) -print/s',
	$fuente,
	$bloques
);

// Dos gates barren lo mismo: el previo al respaldo y el posterior al swap. Si
// uno se queda corto, el residuo pasa por la mitad del ciclo.
akibara_assert(
	2 === count( $bloques[0] ),
	'el riel debe barrer el webroot en sus dos gates',
	count( $bloques[0] )
);

$patrones_por_bloque = array();
foreach ( $bloques[0] as $bloque ) {
	akibara_assert(
		str_contains( $bloque, 'wp-content/mu-plugins' ),
		'el barrido debe incluir mu-plugins: ahí apareció el residuo servido en texto plano',
		$bloque
	);

	preg_match_all( '/-name "([^"]+)"/', $bloque, $nombres );
	$patrones_por_bloque[] = $nombres[1];
}

akibara_assert(
	$patrones_por_bloque[0] === $patrones_por_bloque[1],
	'los dos gates deben barrer exactamente los mismos patrones',
	$patrones_por_bloque
);

$patrones = $patrones_por_bloque[0];
akibara_assert( array() !== $patrones, 'el barrido se quedó sin patrones', $patrones );

/**
 * ¿Algún patrón del riel casa este nombre?
 *
 * @param array<int, string> $patrones Patrones leídos del riel.
 */
function akibara_barrido_casa( array $patrones, string $nombre ): bool {
	foreach ( $patrones as $patron ) {
		if ( fnmatch( $patron, $nombre ) ) {
			return true;
		}
	}

	return false;
}

// Los tres residuos que dejó el despliegue 1.110.0 en producción.
$residuos = array(
	'.akibara-business-old-1788174146',
	'.akibara-search-old-1788174146',
	'.akibara-provider-access.php.old-1788174146',
	// Y las formas históricas, que tampoco pueden dejar de casar.
	'akibara-business.old',
	'akibara-gp.bak',
	'akibara-search.tmp',
);

foreach ( $residuos as $residuo ) {
	akibara_assert(
		akibara_barrido_casa( $patrones, $residuo ),
		sprintf( 'el barrido no ve el residuo «%s»', $residuo ),
		$patrones
	);
}

// Y nada de lo que vive ahí legítimamente puede disparar el gate: un falso
// positivo aquí bloquea el despliegue entero.
$legitimos = array(
	'akibara-business',
	'akibara-search',
	'akibara-cart-checkout-blocks',
	'akibara-rewards',
	'akibara-gp',
	'woocommerce',
	'bluex-for-woocommerce',
	'akibara-provider-access.php',
	'akibara-correo-smtp.php',
	'akibara-bluex-suite.php',
	'akibara-local-external-indexing-guard.php',
	'assets',
	'index.php',
);

foreach ( $legitimos as $legitimo ) {
	akibara_assert(
		! akibara_barrido_casa( $patrones, $legitimo ),
		sprintf( 'el barrido bloquearía el despliegue por «%s»', $legitimo ),
		$patrones
	);
}

fwrite( STDOUT, 'PASS: barrido de higiene — casa el residuo real y ningún fichero legítimo' . PHP_EOL );
