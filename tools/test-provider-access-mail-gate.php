<?php
/**
 * Candado del gate de despliegue contra el transporte SMTP real; no carga
 * WordPress ni abre red.
 *
 * INVARIANTE QUE FIJA: el catálogo de accesos y el transporte de correo tienen
 * que responder LO MISMO a «¿está habilitado el correo?». Hasta hoy no lo
 * hacían: el catálogo validaba `AKIBARA_HOSTINGER_MAIL_ENABLED` con `/^1$/`
 * sobre un valor ya aplanado a texto, así que `define( ..., 1 )` y
 * `define( ..., '1' )` pasaban el gate `wp akibara access-check` —y con él el
 * despliegue— mientras `Transport::is_enabled()`, que exige `true ===`, cortaba
 * TODOS los envíos. Es el modo de fallo del incidente del 18-21 ago 2026 (tres
 * días sin correo, pedido 27720 incluido).
 *
 * El 2026-09-12 volvió a aparecer el mismo modo de fallo por el único valor que
 * este candado no cubría: la CADENA 'true'. `raw_constant()` rinde el booleano
 * como el texto `true`, idéntico carácter por carácter, así que `/^true$/` no
 * los distinguía. Se cerró en el catálogo con `expect_bool`, que mira el TIPO
 * antes de aplanar; aquí se cierra la otra mitad, que es que el candado deje de
 * fijar valores sueltos y cubra también 'TRUE' y 'yes'.
 *
 * No se compara código ni se reimplementa el criterio: cada caso define la
 * constante y pregunta a las TRES piezas de verdad: la del catálogo
 * (`Access::is_configured()`), la del transporte (`guard_configuration()`, el
 * `pre_wp_mail` que decide si el correo sale) y la que lee una PERSONA
 * (`Access::inventory()['malformed']`, de donde salen el aviso del admin, la
 * tabla del panel y el texto de `wp akibara access-check`).
 *
 * La tercera entró el 2026-09-12 con su propia regresión detrás: al rechazar
 * por tipo devolviendo `null`, el inventario dejó de distinguir «definida con
 * un valor que no vale» de «no está», y el gate mandaba a escribir en el
 * wp-config un `define` que ya estaba escrito tres líneas más arriba. Poner el
 * gate rojo es la mitad del trabajo; la otra mitad es que el rojo diga dónde
 * mirar.
 *
 * Una constante PHP no se puede redefinir, así que cada caso corre en su propio
 * proceso: el padre lanza un hijo por valor y compara las dos respuestas.
 *
 * Uso: php tools/test-provider-access-mail-gate.php
 */

declare( strict_types = 1 );

$akibara_mu_dir = dirname( __DIR__ ) . '/public_html/wp-content/mu-plugins';

/**
 * Valores de la constante que se prueban, y si el correo debe salir con cada uno.
 *
 * @var array<string, bool>
 */
const AKIBARA_GATE_CASES = array(
	'ausente'     => false,
	'bool_true'   => true,
	'bool_false'  => false,
	'int_1'       => false,
	'string_1'    => false,
	// Los tres que faltaban, y el primero es el que seguía divergiendo: la
	// CADENA 'true' se aplana al MISMO texto que el booleano, así que el patrón
	// del catálogo no las distingue y `define( ..., 'true' )` —un tecleo con
	// comillas en el wp-config— daba verde en el gate con el correo cortado al
	// 100 %. Van los tres porque quien escribe `'true'` también escribe `'TRUE'`
	// o `'yes'`, y un candado que fija un valor suelto en vez de la invariante
	// vuelve a caducar al siguiente tecleo.
	'string_true' => false,
	'string_TRUE' => false,
	'string_yes'  => false,
);

// ─── Hijo: un solo valor de la constante, las dos respuestas ────────────────
$akibara_case = null;
foreach ( array_slice( $argv, 1 ) as $akibara_arg ) {
	if ( 0 === strpos( $akibara_arg, '--caso=' ) ) {
		$akibara_case = substr( $akibara_arg, 7 );
	}
}

if ( null !== $akibara_case ) {
	if ( ! isset( AKIBARA_GATE_CASES[ $akibara_case ] ) ) {
		fwrite( STDERR, 'caso desconocido: ' . $akibara_case . PHP_EOL );
		exit( 2 );
	}

	define( 'ABSPATH', __DIR__ . '/' );

	// El perfil SMTP aprobado de producción, para que lo único que cambie entre
	// casos sea el interruptor.
	define( 'AKIBARA_HOSTINGER_SMTP_USERNAME', 'contacto@akibara.cl' );
	define( 'AKIBARA_HOSTINGER_SMTP_PASSWORD', 'clave-de-prueba-larga' );

	switch ( $akibara_case ) {
		case 'bool_true':
			define( 'AKIBARA_HOSTINGER_MAIL_ENABLED', true );
			break;
		case 'bool_false':
			define( 'AKIBARA_HOSTINGER_MAIL_ENABLED', false );
			break;
		case 'int_1':
			define( 'AKIBARA_HOSTINGER_MAIL_ENABLED', 1 );
			break;
		case 'string_1':
			define( 'AKIBARA_HOSTINGER_MAIL_ENABLED', '1' );
			break;
		case 'string_true':
			define( 'AKIBARA_HOSTINGER_MAIL_ENABLED', 'true' );
			break;
		case 'string_TRUE':
			define( 'AKIBARA_HOSTINGER_MAIL_ENABLED', 'TRUE' );
			break;
		case 'string_yes':
			define( 'AKIBARA_HOSTINGER_MAIL_ENABLED', 'yes' );
			break;
		case 'ausente':
		default:
			break;
	}

	/** @param callable|string|array<int, mixed> $callback */
	function add_filter( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): void {
		unset( $hook, $callback, $priority, $accepted_args );
	}

	/** @param callable|string|array<int, mixed> $callback */
	function add_action( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): void {
		unset( $hook, $callback, $priority, $accepted_args );
	}

	function wp_get_environment_type(): string {
		return 'production';
	}

	require $akibara_mu_dir . '/akibara-provider-access.php';
	require $akibara_mu_dir . '/akibara-correo-smtp.php';

	$catalogo = \Akibara\ProviderAccess\Access::is_configured( 'hostinger_mail_enabled' );

	// `pre_wp_mail` devolviendo false es EXACTAMENTE «este correo no sale».
	$transporte = false !== \Akibara\HostingerMail\Transport::guard_configuration( null, array() );

	// Lo que el operador VE. Es la misma bandera que redacta el aviso del admin,
	// la tabla del panel y el mensaje de `wp akibara access-check`.
	$malformada = false;
	foreach ( \Akibara\ProviderAccess\Access::inventory() as $fila ) {
		if ( 'hostinger_mail_enabled' === $fila['id'] ) {
			$malformada = (bool) $fila['malformed'];
		}
	}

	fwrite( STDOUT, ( $catalogo ? '1' : '0' ) . ( $transporte ? '1' : '0' ) . ( $malformada ? '1' : '0' ) );
	exit( 0 );
}

// ─── Padre: un proceso por valor, y las dos respuestas tienen que coincidir ──

/** @param mixed $actual */
function akibara_assert( bool $condition, string $message, $actual = null ): void {
	if ( $condition ) {
		return;
	}

	fwrite( STDERR, 'FAIL: ' . $message . ': ' . var_export( $actual, true ) . PHP_EOL );
	exit( 1 );
}

foreach ( AKIBARA_GATE_CASES as $caso => $debe_salir ) {
	$salida = shell_exec(
		escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' --caso=' . escapeshellarg( $caso ) . ' 2>&1'
	);
	$salida = is_string( $salida ) ? trim( $salida ) : '';

	akibara_assert(
		1 === preg_match( '/^[01]{3}$/', $salida ),
		sprintf( 'el caso «%s» no devolvió las tres respuestas', $caso ),
		$salida
	);

	$catalogo   = '1' === $salida[0];
	$transporte = '1' === $salida[1];
	$malformada = '1' === $salida[2];

	// Una constante ESCRITA en el wp-config no puede informarse nunca como
	// ausente: ése es el único caso donde el operador va a buscar donde no es.
	// Y al revés, sin constante no puede decirse «formato inválido».
	if ( 'ausente' === $caso ) {
		akibara_assert(
			! $malformada,
			'sin constante definida, el panel no puede decir «formato inválido»',
			$salida
		);
	} else {
		akibara_assert(
			$malformada !== $catalogo,
			sprintf(
				'el caso «%s» está escrito en el wp-config y el panel dirá «%s»',
				$caso,
				$catalogo ? 'ok' : 'falta'
			),
			$salida
		);
	}

	akibara_assert(
		$transporte === $debe_salir,
		sprintf( 'el transporte cambió de criterio en el caso «%s»', $caso ),
		$salida
	);
	akibara_assert(
		$catalogo === $transporte,
		sprintf(
			'el catálogo dice «%s» y el transporte «%s» en el caso «%s»: el gate puede dar verde con el correo caído',
			$catalogo ? 'configurada' : 'sin configurar',
			$transporte ? 'sale' : 'no sale',
			$caso
		),
		$salida
	);
}

fwrite( STDOUT, sprintf( 'PASS: el gate de accesos y el transporte SMTP responden lo mismo en los %d valores', count( AKIBARA_GATE_CASES ) ) . PHP_EOL );
