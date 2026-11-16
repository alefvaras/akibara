<?php
/**
 * El caso del coleccionista: a alguien le faltan seis tomos de una serie y quiere
 * que le avisemos de todos.
 *
 * Con el tope de 3/hora que compartía con el formulario de contacto, el cuarto
 * clic rebotaba. Ocurrió de verdad: un cliente se anotó a Jujutsu Kaisen 26, 28 y
 * 29 en el mismo segundo (2026-07-03 22:16:29) y ahí paró; el 27 existe, está
 * agotado, y nunca lo pidió.
 *
 *   cp tools/test-bis-coleccionista.php public_html/_t.php
 *   docker compose --profile tools run --rm -T cli wp eval-file _t.php
 *   rm public_html/_t.php
 */

use Akibara\Business\Utilities\RateLimit;

$GLOBALS['akb_fallos']   = 0;
$GLOBALS['akb_corridas'] = 0;

function comprobar( string $caso, bool $ok, string $detalle = '' ): void {
	++$GLOBALS['akb_corridas'];
	if ( ! $ok ) {
		++$GLOBALS['akb_fallos'];
	}
	printf( "  %s %s%s\n", $ok ? 'OK  ' : 'FALLA', $caso, $detalle ? " — {$detalle}" : '' );
}

// El límite exime a quien puede administrar la tienda: hay que probar como
// visitante anónimo, que es quien se anota de verdad.
wp_set_current_user( 0 );
$_SERVER['REMOTE_ADDR'] = '198.51.100.77'; // IP de documentación (RFC 5737).

echo "── un coleccionista pide aviso de 6 tomos seguidos ──\n";
$rechazado_en = 0;
for ( $i = 1; $i <= 6; $i++ ) {
	if ( RateLimit::exceeded( 'bis' ) ) {
		$rechazado_en = $i;
		break;
	}
}
comprobar( 'puede anotarse a los 6 tomos', 0 === $rechazado_en, $rechazado_en ? "le cortó en el tomo {$rechazado_en}" : 'ninguno rechazado' );

echo "\n── pero el tope sigue existiendo ──\n";
$_SERVER['REMOTE_ADDR'] = '198.51.100.88';
$rechazado_en = 0;
for ( $i = 1; $i <= 20; $i++ ) {
	if ( RateLimit::exceeded( 'bis' ) ) {
		$rechazado_en = $i;
		break;
	}
}
comprobar( 'a las 13 altas corta', 13 === $rechazado_en, $rechazado_en ? "cortó en la {$rechazado_en}" : 'NO cortó nunca' );

echo "\n── el formulario de contacto NO se relaja ──\n";
$_SERVER['REMOTE_ADDR'] = '198.51.100.99';
$rechazado_en = 0;
for ( $i = 1; $i <= 10; $i++ ) {
	if ( RateLimit::exceeded( 'contacto' ) ) {
		$rechazado_en = $i;
		break;
	}
}
comprobar( 'contacto sigue en 3 por hora', 4 === $rechazado_en, $rechazado_en ? "cortó en la {$rechazado_en}" : 'NO cortó' );

echo "\n── los dos límites son independientes ──\n";
$_SERVER['REMOTE_ADDR'] = '198.51.100.111';
for ( $i = 1; $i <= 3; $i++ ) {
	RateLimit::exceeded( 'contacto' );
}
comprobar( 'agotar contacto no bloquea los avisos', ! RateLimit::exceeded( 'bis' ) );

$fallos   = (int) $GLOBALS['akb_fallos'];
$corridas = (int) $GLOBALS['akb_corridas'];
printf( "\n%s  %d/%d comprobaciones\n", 0 === $fallos ? 'TODO EN VERDE' : "{$fallos} FALLOS", $corridas - $fallos, $corridas );
