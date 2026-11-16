<?php
/**
 * Candado del cepo de correo del espejo: las cabeceras que entrega WooCommerce
 * tienen que llegar a `wp_mail()` como UNA cabecera por elemento. No carga
 * WordPress ni abre red.
 *
 * INVARIANTE QUE FIJA: lo que el cepo devuelve en `$akibara_args['headers']` tiene que
 * ser digerible por `wp_mail()`. Y `wp_mail()` sólo parte por saltos de línea
 * cuando `$headers` llega como CADENA: si llega como array, itera y hace
 * `explode( ':', $header, 2 )` sobre cada elemento. Hasta el 2026-09-12 el cepo
 * metía la cadena multilínea entera de `WC_Email::get_headers()` en UN solo
 * elemento, así que `Content-Type` acababa valiendo
 * «text/html\r\nReply-to: Akibara <contacto@akibara.cl>»: el correo se evaporaba
 * —`wp_mail()` devolvía true, `wp_mail_failed` no saltaba y el mensaje no
 * aparecía en Mailpit— o llegaba sin Reply-To. QA no podía cumplir la
 * obligación de AGENTS.md de verificar el correo EN LA BANDEJA, que es
 * exactamente para lo que existe el cepo.
 *
 * Y fija la otra mitad, que se abre con el arreglo: al partir la cadena,
 * `wp_mail()` empieza a honrar de verdad el `Bcc:` que arma WooCommerce. Una
 * copia oculta a una dirección real saldría por la puerta de atrás del
 * guardarraíl, así que las copias se retiran y quedan a la vista en
 * `X-Akibara-Copias-Originales`.
 *
 * La puerta de atrás tiene DOS hojas y la segunda se cerró el 2026-09-12: con
 * el `to` ya de pruebas, el cepo devolvía los argumentos intactos antes de
 * mirar las cabeceras, así que el `Bcc` a una persona real salía entero
 * (entregado en Mailpit durante la revisión). Por eso el caso 5 existe y por
 * eso las copias se calculan ANTES de decidir si hay que intervenir.
 *
 * Uso: php tools/test-correo-no-sale-cabeceras.php
 */

declare( strict_types = 1 );

define( 'ABSPATH', __DIR__ . '/' );

/** @param callable|string|array<int, mixed> $callback */
function add_filter( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): void {
	unset( $hook, $callback, $priority, $accepted_args );
}

/** El cepo sólo actúa fuera de producción; el espejo es «local». */
function wp_get_environment_type(): string {
	return 'local';
}

require dirname( __DIR__ ) . '/public_html/wp-content/mu-plugins/akibara-correo-no-sale-a-clientes.php';

$akibara_fallos = 0;

/** @param mixed $actual */
function akibara_assert( bool $condicion, string $mensaje, $actual = null ): void {
	global $akibara_fallos;

	if ( $condicion ) {
		fwrite( STDOUT, '  ok  ' . $mensaje . PHP_EOL );
		return;
	}

	++$akibara_fallos;
	fwrite( STDERR, 'FAIL: ' . $mensaje . ': ' . var_export( $actual, true ) . PHP_EOL );
}

/**
 * Lo que `wp_mail()` haría con ESO: una cabecera por elemento, partida por el
 * primer «:». No se reimplementa el criterio, se copia el de core.
 *
 * Acepta cadena Y array a propósito: `wp_mail()` parte la CADENA por saltos de
 * línea antes de mirarla (`pluggable.php:303`), así que un doble que reciba
 * sólo arrays daría por «no llega» un `Bcc:` que en producción sí sale. Ese es
 * el modo de falso verde que dejó la copia oculta viva durante la revisión.
 *
 * @param mixed $cabeceras Cabeceras tal como quedan en `$args['headers']`.
 * @return array<string, string>
 */
function akibara_como_las_lee_wp_mail( $cabeceras ): array {
	if ( ! is_array( $cabeceras ) ) {
		$cabeceras = explode( "\n", str_replace( "\r\n", "\n", (string) $cabeceras ) );
	}

	$akibara_leidas = array();
	foreach ( $cabeceras as $cabecera ) {
		$cabecera = (string) $cabecera;
		if ( ! str_contains( $cabecera, ':' ) ) {
			continue;
		}
		list( $nombre, $valor ) = explode( ':', trim( $cabecera ), 2 );
		$akibara_leidas[ strtolower( trim( $nombre ) ) ] = trim( $valor );
	}

	return $akibara_leidas;
}

// ── Caso 1: la cadena multilínea real de WC_Email ──────────────────────────
// Copiada de `WC_Email::get_headers()` con `email_improvements` activo, que es
// lo que corre hoy en el espejo: 19 de los 22 correos registrados la entregan
// con estas dos líneas —todos los del cliente—, y los 3 restantes son los
// avisos al admin, que traen sólo Content-Type.
fwrite( STDOUT, 'Caso 1 · cadena multilínea de WC_Email → dos cabeceras' . PHP_EOL );

$akibara_args = akibara_correo_no_sale_a_clientes(
	array(
		'to'      => 'cliente.real@gmail.com',
		'subject' => 'Tu pedido de Akibara',
		'message' => '<p>hola</p>',
		'headers' => "Content-Type: text/html\r\nReply-to: Akibara <contacto@akibara.cl>\r\n",
	)
);

akibara_assert( is_array( $akibara_args['headers'] ), 'las cabeceras salen en array', $akibara_args['headers'] );
akibara_assert( 4 === count( $akibara_args['headers'] ), 'salen 4 cabeceras (2 de WC + 2 del cepo)', $akibara_args['headers'] );

$akibara_leidas = akibara_como_las_lee_wp_mail( $akibara_args['headers'] );

akibara_assert( 'text/html' === ( $akibara_leidas['content-type'] ?? '' ), 'Content-Type queda limpio', $akibara_leidas['content-type'] ?? null );
akibara_assert( 'Akibara <contacto@akibara.cl>' === ( $akibara_leidas['reply-to'] ?? '' ), 'Reply-to sobrevive como cabecera propia', $akibara_leidas['reply-to'] ?? null );
akibara_assert( 'capturado@akibara.test' === $akibara_args['to'], 'el destinatario real se redirige', $akibara_args['to'] );
akibara_assert( 'cliente.real@gmail.com' === ( $akibara_leidas['x-akibara-destinatario-original'] ?? '' ), 'el destinatario original queda a la vista', $akibara_leidas['x-akibara-destinatario-original'] ?? null );

// ── Caso 2: las copias no salen por la puerta de atrás ─────────────────────
// Este caso sólo es posible DESPUÉS de partir la cadena: mientras iba fundida
// dentro de Content-Type, `wp_mail()` ni veía el Bcc. Partirla sin retirar las
// copias abriría un agujero nuevo, así que las dos mitades van juntas.
fwrite( STDOUT, 'Caso 2 · Cc y Bcc se retiran y quedan a la vista' . PHP_EOL );

$akibara_args = akibara_correo_no_sale_a_clientes(
	array(
		'to'      => 'cliente.real@gmail.com',
		'subject' => 'Tu pedido de Akibara',
		'message' => '<p>hola</p>',
		'headers' => "Content-Type: text/html\nReply-to: Akibara <contacto@akibara.cl>\nBcc: dueno.real@gmail.com\nCc: otro.real@gmail.com\n",
	)
);

$akibara_leidas = akibara_como_las_lee_wp_mail( $akibara_args['headers'] );

akibara_assert( ! isset( $akibara_leidas['bcc'] ), 'el Bcc no llega a wp_mail', $akibara_leidas );
akibara_assert( ! isset( $akibara_leidas['cc'] ), 'el Cc no llega a wp_mail', $akibara_leidas );
akibara_assert(
	'dueno.real@gmail.com, otro.real@gmail.com' === ( $akibara_leidas['x-akibara-copias-originales'] ?? '' ),
	'las copias quedan anotadas en vez de bloquearse en silencio',
	$akibara_leidas['x-akibara-copias-originales'] ?? null
);
akibara_assert( 'text/html' === ( $akibara_leidas['content-type'] ?? '' ), 'Content-Type sigue limpio', $akibara_leidas['content-type'] ?? null );

// ── Caso 3: un array bien formado no se toca ───────────────────────────────
fwrite( STDOUT, 'Caso 3 · array ya correcto: no se rompe' . PHP_EOL );

$akibara_args = akibara_correo_no_sale_a_clientes(
	array(
		'to'      => 'cliente.real@gmail.com',
		'subject' => 'Aviso',
		'message' => 'texto',
		'headers' => array( 'Content-Type: text/plain', 'Reply-to: contacto@akibara.cl' ),
	)
);

$akibara_leidas = akibara_como_las_lee_wp_mail( $akibara_args['headers'] );
akibara_assert( 'text/plain' === ( $akibara_leidas['content-type'] ?? '' ), 'Content-Type del array intacto', $akibara_leidas['content-type'] ?? null );
akibara_assert( 'contacto@akibara.cl' === ( $akibara_leidas['reply-to'] ?? '' ), 'Reply-to del array intacto', $akibara_leidas['reply-to'] ?? null );

// ── Caso 4: destinatario ya de pruebas, el cepo no interviene ──────────────
// Es el A/B del espejo: el mismo correo a un `.test` sí llegaba a Mailpit, y
// por eso el defecto parecía «sólo pasa con algunos correos».
fwrite( STDOUT, 'Caso 4 · destinatario .test sin copias: pasa tal cual' . PHP_EOL );

$akibara_sin_tocar = array(
	'to'      => 'qa@akibara.test',
	'subject' => 'Aviso',
	'message' => 'texto',
	'headers' => "Content-Type: text/html\r\nReply-to: Akibara <contacto@akibara.cl>\r\n",
);
$akibara_args      = akibara_correo_no_sale_a_clientes( $akibara_sin_tocar );

akibara_assert( $akibara_sin_tocar === $akibara_args, 'un destinatario de pruebas sale intacto', $akibara_args );

// Y con copias que TAMBIÉN son de pruebas sigue sin intervenir: si el cepo
// tocara aquí, se estaría cobrando trabajo donde no hay a quién proteger.
$akibara_sin_tocar = array(
	'to'      => 'qa@akibara.test',
	'subject' => 'Aviso',
	'message' => 'texto',
	'headers' => "Content-Type: text/html\r\nBcc: otro.qa@akibara.test\r\n",
);
$akibara_args      = akibara_correo_no_sale_a_clientes( $akibara_sin_tocar );

akibara_assert( $akibara_sin_tocar === $akibara_args, 'copias de pruebas sobre destinatario de pruebas: intacto', $akibara_args );

// ── Caso 5: destinatario de pruebas + copia REAL ───────────────────────────
// La segunda hoja de la puerta de atrás. El retorno temprano por «todos eran
// de prueba» se ejecutaba ANTES de mirar las cabeceras, así que este `Bcc`
// llegaba entero a `wp_mail()`: medido en Mailpit el 2026-09-12, con la copia
// oculta a una dirección real en la bandeja. Es la forma exacta en que
// WooCommerce arma el aviso al dueño (`$this->get_option( 'bcc' )`).
fwrite( STDOUT, 'Caso 5 · destinatario .test + Bcc real: la copia NO sale' . PHP_EOL );

$akibara_args = akibara_correo_no_sale_a_clientes(
	array(
		'to'      => 'qa@akibara.test',
		'subject' => 'Tu pedido de Akibara',
		'message' => '<p>hola</p>',
		'headers' => "Content-Type: text/html\r\nBcc: dueno.real@gmail.com\r\nReply-to: Akibara <contacto@akibara.cl>\r\n",
	)
);

$akibara_leidas = akibara_como_las_lee_wp_mail( $akibara_args['headers'] );

akibara_assert( ! isset( $akibara_leidas['bcc'] ), 'el Bcc real no llega a wp_mail aunque el to sea de pruebas', $akibara_leidas );
akibara_assert(
	'dueno.real@gmail.com' === ( $akibara_leidas['x-akibara-copias-originales'] ?? '' ),
	'la copia retirada queda anotada',
	$akibara_leidas['x-akibara-copias-originales'] ?? null
);
akibara_assert( 'qa@akibara.test' === $akibara_args['to'], 'el destinatario de pruebas se respeta: no hay a quién proteger ahí', $akibara_args['to'] );
akibara_assert( str_starts_with( (string) $akibara_args['subject'], '[copia retirada para dueno.real@gmail.com] ' ), 'el asunto lo dice sin abrir el correo', $akibara_args['subject'] );
akibara_assert( 'text/html' === ( $akibara_leidas['content-type'] ?? '' ), 'Content-Type sigue limpio', $akibara_leidas['content-type'] ?? null );
akibara_assert( 'Akibara <contacto@akibara.cl>' === ( $akibara_leidas['reply-to'] ?? '' ), 'Reply-to sobrevive', $akibara_leidas['reply-to'] ?? null );

// ── Caso 6: una cabecera de copia VACÍA no es una copia ────────────────────
// `Cc:` a secas entraba como cadena vacía y se anotaba igual: quien revisa la
// bandeja leía «había copias» donde no las había.
fwrite( STDOUT, 'Caso 6 · Cc vacío: no se anota copia ninguna' . PHP_EOL );

$akibara_args = akibara_correo_no_sale_a_clientes(
	array(
		'to'      => 'cliente.real@gmail.com',
		'subject' => 'Aviso',
		'message' => 'texto',
		'headers' => "Content-Type: text/html\r\nCc:\r\n",
	)
);

$akibara_leidas = akibara_como_las_lee_wp_mail( $akibara_args['headers'] );

akibara_assert( ! isset( $akibara_leidas['cc'] ), 'la cabecera Cc vacía se retira igual', $akibara_leidas );
akibara_assert( ! isset( $akibara_leidas['x-akibara-copias-originales'] ), 'no se inventa una copia que no existía', $akibara_leidas );

if ( 0 !== $akibara_fallos ) {
	fwrite( STDERR, sprintf( 'FAIL: %d aserción(es) rotas', $akibara_fallos ) . PHP_EOL );
	exit( 1 );
}

fwrite( STDOUT, 'PASS: el cepo entrega una cabecera por elemento, conserva Content-Type y Reply-to, y retira las copias' . PHP_EOL );
