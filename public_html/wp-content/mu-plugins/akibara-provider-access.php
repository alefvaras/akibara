<?php
/**
 * Plugin Name: Akibara — Accesos a proveedores
 * Description: Catálogo declarativo de las claves de API que Akibara espera, con lectura única, inventario en el admin y comandos WP-CLI. No contiene ningún valor. Para el correo no se conforma con que el archivo exista: le pregunta al transporte si está vivo.
 * Version: 1.3.0
 * Author: Akibara
 *
 * @package Akibara\MuPlugins
 */

declare( strict_types = 1 );

namespace Akibara\ProviderAccess;

defined( 'ABSPATH' ) || exit;

/**
 * Se pidió una clave obligatoria que no está configurada.
 */
/*
 * Los consumidores con degradación permitida consultan Access::get(). Si un
 * flujo requiere abortar por falta de acceso, el consumidor define su propia
 * excepción y contrato; el registro no lanza por una clave opcional.
 */

/**
 * Catálogo de las claves de proveedores que Akibara espera.
 *
 * NO contiene valores, y por eso puede vivir en el repositorio. Declara QUÉ
 * claves existen, de qué ámbito son, qué forma tienen y quién las consume.
 *
 * ── La regla, en una línea ────────────────────────────────────────────────
 * Si la clave viaja al navegador del cliente aunque sea una sola vez, es
 * PÚBLICA: puede vivir en una option y editarse desde el admin. Si no viaja,
 * es SECRETO DE SERVIDOR: vive ÚNICAMENTE como define() en el wp-config.php de
 * producción, jamás en la base de datos ni en el repositorio.
 *
 * La prueba práctica cuando la respuesta no es obvia: «¿puedo pegar esta clave
 * en el HTML de la portada sin que pase nada malo?». Si sí, es pública, y su
 * protección real es la restricción por dominio en la consola del proveedor,
 * no el secreto. Si no, es de servidor.
 *
 * Y no es una convención de buenas intenciones: en `Access::resolve()` la rama
 * que llega a `get_option()` está dentro de un `if` sobre el ámbito, así que a
 * un secreto de servidor no hay camino desde la base de datos.
 */
final class Registry {

	/** La clave termina en el navegador del cliente pase lo que pase. */
	public const SCOPE_PUBLIC = 'public';

	/** La clave no sale del servidor. Nunca cae a la base de datos. */
	public const SCOPE_SERVER = 'server';

	/**
	 * Forma exacta de una entrada.
	 *
	 * El test de contrato compara `array_keys()` de cada entrada contra esta
	 * lista, lo que hace imposible colar un campo `value` sin ponerse rojo.
	 *
	 * @var array<int, string>
	 */
	public const ENTRY_FIELDS = array(
		'const',
		'scope',
		'option',
		'pattern',
		'expect_bool',
		'label',
		'owner',
		'owner_file',
		'required',
		'console_url',
	);

	/**
	 * El catálogo.
	 *
	 * Día uno declara EXACTAMENTE lo que la tienda ya tiene: ni una clave
	 * imaginaria, porque una entrada `required` sin valor haría fallar el gate
	 * del despliegue desde el primer minuto.
	 *
	 * - `option`     : cadena vacía OBLIGATORIA cuando el ámbito es servidor.
	 * - `expect_bool`: exige que la constante sea un booleano DE VERDAD. No es
	 *                  «acepta true/false»: rechaza cualquier otro tipo antes de
	 *                  aplanar —`1`, `'1'`, `'true'`, `'yes'`—, porque el patrón
	 *                  mira el valor ya convertido a texto y ahí `true` y la
	 *                  CADENA `'true'` son el mismo carácter por carácter. Va en
	 *                  `true` sólo donde el consumidor compara con identidad
	 *                  estricta (`true ===`); en el resto de entradas, `false`.
	 * - `owner_file` : ruta relativa a wp-content del archivo que la lee. Si no
	 *                  está vacía, la clave la consume OTRO —hoy, el mu-plugin
	 *                  del correo—: este registro la inventaría con `defined()`
	 *                  y su longitud, y `Access::get()` se NIEGA a devolverla.
	 *                  Es la frontera que impide que el inventario se convierta
	 *                  en una puerta trasera a las credenciales del correo.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private const CATALOG = array(

		'welcome_turnstile_secret' => array(
			'const'       => 'AKB_WELCOME_TURNSTILE_SECRET',
			'scope'       => self::SCOPE_SERVER,
			'option'      => '',
			'pattern'     => '/^\\S{20,}$/',
			'expect_bool' => false,
			'label'       => 'Turnstile — verificación de bienvenida',
			'owner'       => 'Akibara Business · WelcomeCoupon',
			'owner_file'  => '',
			'required'    => false,
			'console_url' => 'https://dash.cloudflare.com/',
		),

		'google_maps'             => array(
			'const'       => 'AKB_GOOGLE_MAPS_API_KEY',
			'scope'       => self::SCOPE_PUBLIC,
			'option'      => 'akibara_google_maps_api_key',
			'pattern'     => '/^AIza[0-9A-Za-z_-]{35}$/',
			'expect_bool' => false,
			'label'       => 'Google Maps — autocompletar de dirección',
			'owner'       => 'Akibara Business · módulo Autocompletar dirección',
			'owner_file'  => '',
			'required'    => false,
			'console_url' => 'https://console.cloud.google.com/google/maps-apis/credentials',
		),

		// `true` y no `1`: el transporte exige identidad estricta con el booleano
		// (`Transport::is_enabled()`, akibara-correo-smtp.php:630-632). Con
		// `/^1$/` el gate daba verde a `define( ..., 1 )` y a `define( ..., '1' )`
		// mientras el transporte los rechazaba y el correo quedaba caído — el modo
		// de fallo del incidente del 18-21 ago 2026. Lo hace posible que
		// `raw_constant()` ya no aplane el booleano al mismo texto que el entero.
		//
		// Y por eso mismo NO basta el patrón: `raw_constant()` rinde el booleano
		// como el texto `true`, que es carácter por carácter lo que rinde la
		// CADENA 'true'. `define( ..., 'true' )` —un tecleo con comillas— daba
		// verde en el gate del despliegue mientras `pre_wp_mail` cortaba el 100 %
		// de los envíos: el mismo incidente, con el único valor que el candado
		// escrito para cerrarlo no cubría. `expect_bool` es lo que distingue el
		// booleano de su sombra de texto, y se comprueba ANTES de aplanar.
		'hostinger_mail_enabled'  => array(
			'const'       => 'AKIBARA_HOSTINGER_MAIL_ENABLED',
			'scope'       => self::SCOPE_SERVER,
			'option'      => '',
			'pattern'     => '/^true$/',
			'expect_bool' => true,
			'label'       => 'Transporte de correo habilitado',
			'owner'       => 'mu-plugin del transporte SMTP',
			'owner_file'  => 'mu-plugins/akibara-correo-smtp.php',
			'required'    => true,
			'console_url' => '',
		),

		'hostinger_smtp_username' => array(
			'const'       => 'AKIBARA_HOSTINGER_SMTP_USERNAME',
			'scope'       => self::SCOPE_SERVER,
			'option'      => '',
			// El remitente productivo debe coincidir con Transport::REQUIRED_SENDER,
			// sin distinguir mayúsculas, como hace el transporte al validar.
			'pattern'     => '/^contacto@akibara\.cl$/i',
			'expect_bool' => false,
			'label'       => 'Buzón remitente de la tienda',
			'owner'       => 'mu-plugin del transporte SMTP',
			'owner_file'  => 'mu-plugins/akibara-correo-smtp.php',
			'required'    => true,
			'console_url' => '',
		),

		'hostinger_smtp_password' => array(
			'const'       => 'AKIBARA_HOSTINGER_SMTP_PASSWORD',
			'scope'       => self::SCOPE_SERVER,
			'option'      => '',
			'pattern'     => '/^\S{8,}$/',
			'expect_bool' => false,
			'label'       => 'Contraseña del buzón remitente',
			'owner'       => 'mu-plugin del transporte SMTP',
			'owner_file'  => 'mu-plugins/akibara-correo-smtp.php',
			'required'    => true,
			'console_url' => '',
		),

		// Las tres siguientes son OPCIONALES: el transporte trae sus valores por
		// defecto (smtp.hostinger.com:465/ssl) y sólo mira estas constantes si
		// alguien quiere apartarse. Se declaran porque `access-scan` las encontró
		// leídas fuera del catálogo, que es exactamente para lo que sirve: un
		// inventario que no las nombra miente por omisión, y el día que alguien
		// las ponga mal nadie sabría dónde mirar.
		'hostinger_smtp_host'     => array(
			'const'       => 'AKIBARA_HOSTINGER_SMTP_HOST',
			'scope'       => self::SCOPE_SERVER,
			'option'      => '',
			// Mismo criterio: el transporte sólo aprueba `smtp.hostinger.com`, en
			// minúsculas tras `strtolower()`, así que el patrón no distingue.
			'pattern'     => '/^smtp\.hostinger\.com$/i',
			'expect_bool' => false,
			'label'       => 'Servidor SMTP (opcional, por defecto smtp.hostinger.com)',
			'owner'       => 'mu-plugin del transporte SMTP',
			'owner_file'  => 'mu-plugins/akibara-correo-smtp.php',
			'required'    => false,
			'console_url' => '',
		),

		'hostinger_smtp_port'     => array(
			'const'       => 'AKIBARA_HOSTINGER_SMTP_PORT',
			'scope'       => self::SCOPE_SERVER,
			'option'      => '',
			// 465 con ssl o 587 con tls: cualquier otro puerto lo rechaza el
			// transporte y el correo se cae entero.
			'pattern'     => '/^(?:465|587)$/',
			'expect_bool' => false,
			'label'       => 'Puerto SMTP (opcional, por defecto 465)',
			'owner'       => 'mu-plugin del transporte SMTP',
			'owner_file'  => 'mu-plugins/akibara-correo-smtp.php',
			'required'    => false,
			'console_url' => '',
		),

		'hostinger_smtp_encryption' => array(
			'const'       => 'AKIBARA_HOSTINGER_SMTP_ENCRYPTION',
			'scope'       => self::SCOPE_SERVER,
			'option'      => '',
			'pattern'     => '/^(ssl|tls)$/i',
			'expect_bool' => false,
			'label'       => 'Cifrado SMTP (opcional, por defecto ssl)',
			'owner'       => 'mu-plugin del transporte SMTP',
			'owner_file'  => 'mu-plugins/akibara-correo-smtp.php',
			'required'    => false,
			'console_url' => '',
		),
	);

	/**
	 * Todo el catálogo.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function all(): array {
		return self::CATALOG;
	}

	/**
	 * Una entrada.
	 *
	 * @param string $id Identificador declarado.
	 * @return array<string, mixed>
	 * @throws \InvalidArgumentException Si el identificador no está declarado.
	 */
	public static function entry( string $id ): array {
		if ( ! isset( self::CATALOG[ $id ] ) ) {
			throw new \InvalidArgumentException(
				sprintf( 'Acceso de proveedor no declarado: %s', esc_html( $id ) )
			);
		}

		return self::CATALOG[ $id ];
	}

	/**
	 * Nombres de constante declarados, para cazar huérfanas.
	 *
	 * @return array<int, string>
	 */
	public static function declared_constants(): array {
		return array_values( array_column( self::CATALOG, 'const' ) );
	}

	/**
	 * Constantes de plataforma que no contienen claves de proveedor. access-scan
	 * las excluye del barrido por prefijo. Si alguna pasa a contener un secreto,
	 * debe trasladarse al catálogo para que vuelva a estar bajo control.
	 *
	 * @var array<int, string>
	 */
	public const NOT_A_PROVIDER_KEY = array(
		// Rutas, versión y ficheros que cada pieza propia define al arrancar.
		'AKIBARA_BUSINESS_DIR',
		'AKIBARA_BUSINESS_URL',
		'AKIBARA_BUSINESS_VERSION',
		'AKIBARA_SEARCH_DIR',
		'AKIBARA_SEARCH_URL',
		'AKIBARA_SEARCH_VERSION',
		'AKIBARA_REWARDS_DIR',
		'AKIBARA_REWARDS_FILE',
		'AKIBARA_REWARDS_URL',
		'AKIBARA_REWARDS_VERSION',
		'AKIBARA_CART_CHECKOUT_BLOCKS_DIR',
		'AKIBARA_CART_CHECKOUT_BLOCKS_URL',
		'AKIBARA_CART_CHECKOUT_BLOCKS_VERSION',
		// Palancas de prueba: sólo se definen en la suite y en el espejo.
		'AKIBARA_SEARCH_TESTING',
		'AKIBARA_REWARDS_ALLOW_WIPE',
		// Buzón de captura del espejo: una dirección de correo en un dominio
		// reservado, no una credencial. La lee `akibara-correo-no-sale-a-clientes.php`.
		'AKIBARA_CORREO_CAPTURA',
	);

	/**
	 * Los hosts que SON la tienda.
	 *
	 * Duplicado a propósito en `akibara-correo-smtp.php`
	 * (`Transport::PRODUCTION_HOSTS`): los mu-plugins cargan en orden alfabético y
	 * ninguno puede depender del otro. `DominioDeProduccionTest` fija que los dos
	 * predicados respondan lo mismo.
	 */
	public const PRODUCTION_HOSTS = array( 'akibara.cl', 'www.akibara.cl' );

	/**
	 * ¿El host dado es el de la tienda?
	 *
	 * @param string $home_url URL de inicio del sitio.
	 * @return bool
	 */
	public static function is_production_host( string $home_url ): bool {
		$host = parse_url( trim( $home_url ), PHP_URL_HOST ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- función pura sin WordPress; el host de home_url() siempre trae esquema.

		return is_string( $host ) && in_array( rtrim( strtolower( $host ), '.' ), self::PRODUCTION_HOSTS, true );
	}

	/**
	 * ¿Este sitio se juzga con las reglas de producción?
	 *
	 * Por el entorno O por el dominio: el entorno solo lo decide una constante
	 * que viaja con cualquier copia de `wp-config.php`.
	 *
	 * @param string $environment Entorno de WordPress.
	 * @param string $home_url    URL de inicio del sitio.
	 * @return bool
	 */
	public static function judged_as_production( string $environment, string $home_url ): bool {
		return 'production' === $environment || self::is_production_host( $home_url );
	}

	/**
	 * El problema de un dominio de tienda con entorno de clon, o ''.
	 *
	 * @param string $environment Entorno de WordPress.
	 * @param string $home_url    URL de inicio del sitio.
	 * @return string
	 */
	public static function environment_mismatch( string $environment, string $home_url ): string {
		// Un entorno production fuera del dominio oficial es un clon mal
		// configurado; exige corregir el entorno antes de permitir correo real.
		if ( 'production' === $environment && ! self::is_production_host( $home_url ) ) {
			return 'entorno: el sitio responde como ' . (string) parse_url( trim( $home_url ), PHP_URL_HOST ) // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- función pura sin WordPress, como su gemela de arriba.
				. ' pero WP_ENVIRONMENT_TYPE vale «production». Esto NO es la tienda: pon WP_ENVIRONMENT_TYPE=staging en wp-config.php antes de que salga un correo a un cliente real.';
		}

		if ( 'production' === $environment || ! self::is_production_host( $home_url ) ) {
			return '';
		}

		return 'entorno: el sitio responde como ' . (string) parse_url( trim( $home_url ), PHP_URL_HOST ) // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- mismo host que acaba de validar is_production_host().
			. ' pero WP_ENVIRONMENT_TYPE vale «' . $environment . '». Fuera de producción el correo de clientes se desvía a la bandeja de captura y el transporte lo corta. Corrige WP_ENVIRONMENT_TYPE en wp-config.php.';
	}
}

/**
 * La única forma de leer una clave de proveedor.
 *
 * Nadie debería volver a escribir `defined( 'AKB_LO_QUE_SEA' ) ? ... : get_option( ... )`
 * a mano: ese patrón es el que dejó a la tienda sin inventario y sin nadie que
 * supiera qué claves existían.
 */
final class Access {

	/**
	 * Memo por petición.
	 *
	 * @var array<string, string>
	 */
	private static array $memo = array();

	/**
	 * Memo de existencia de archivos consumidores, por petición.
	 *
	 * @var array<string, bool>
	 */
	private static array $files = array();

	/**
	 * Decide el valor efectivo a partir de los dos candidatos. Función PURA.
	 *
	 * Va separada de la lectura real para poder probar la matriz completa sin
	 * definir una sola constante: en PHP las constantes son globales e
	 * inmutables, así que un test que las define contamina a los siguientes y
	 * el verde acaba dependiendo del orden.
	 *
	 * @param string      $id             Identificador declarado.
	 * @param string|null $from_constant  Valor de la constante, si está definida.
	 * @param string|null $from_option    Valor de la option, si la hay.
	 * @return string Cadena vacía cuando no hay valor utilizable.
	 * @throws \InvalidArgumentException Si el identificador no está declarado.
	 */
	public static function resolve( string $id, ?string $from_constant, ?string $from_option ): string {
		$entry = Registry::entry( $id );

		$candidate = trim( (string) $from_constant );

		// La rama de la base de datos existe SÓLO para claves públicas. Un
		// secreto de servidor no tiene camino desde `wp_options`, ni aunque
		// alguien haga `wp option update` a mano.
		if ( '' === $candidate && Registry::SCOPE_PUBLIC === $entry['scope'] ) {
			$candidate = trim( (string) $from_option );
		}

		if ( '' === $candidate ) {
			return '';
		}

		// El formato se revalida en LECTURA, no sólo al guardar. Hoy el módulo
		// de Google Maps valida en el `pre_update_option`, que un
		// `wp option update` esquiva entero; una clave truncada al pegarla es
		// el fallo silencioso número uno de este flujo.
		if ( 1 !== preg_match( (string) $entry['pattern'], $candidate ) ) {
			return '';
		}

		return $candidate;
	}

	/**
	 * Lee una clave. Cadena vacía si no está configurada o no valida.
	 *
	 * @param string $id Identificador declarado.
	 * @throws \DomainException Si la clave pertenece a otro consumidor.
	 */
	public static function get( string $id ): string {
		$entry = Registry::entry( $id );

		// La frontera del correo. Esta excepción NO es un obstáculo que haya
		// que rodear: las credenciales SMTP las lee el mu-plugin del transporte
		// y nadie más. Si alguien la «arregla» para poder mostrar el estado del
		// SMTP, el catálogo se convierte en una puerta a las credenciales. Para
		// saber si están puestas está `is_configured()`, que no las devuelve.
		if ( '' !== $entry['owner_file'] ) {
			throw new \DomainException(
				sprintf(
					'«%s» la lee %s; este registro sólo la inventaría. Usa is_configured().',
					esc_html( $id ),
					esc_html( (string) $entry['owner'] )
				)
			);
		}

		if ( isset( self::$memo[ $id ] ) ) {
			return self::$memo[ $id ];
		}

		self::$memo[ $id ] = self::resolve( $id, self::raw_constant( $entry ), self::raw_option( $entry ) );

		return self::$memo[ $id ];
	}

	/**
	 * ¿Está configurada y con formato válido? No devuelve el valor.
	 *
	 * Es lo que permite inventariar un secreto de servidor sin cruzar la
	 * frontera de `get()`.
	 *
	 * @param string $id Identificador declarado.
	 */
	public static function is_configured( string $id ): bool {
		$entry = Registry::entry( $id );

		return '' !== self::resolve( $id, self::raw_constant( $entry ), self::raw_option( $entry ) );
	}

	/**
	 * Estado de todas las claves, SIN valores.
	 *
	 * Cada fila: id, label, owner, scope, const, required, configured, source,
	 * length, owner_file_ok, console_url.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function inventory(): array {
		$rows = array();

		foreach ( Registry::all() as $id => $entry ) {
			$from_constant = self::raw_constant( $entry );
			$from_option   = self::raw_option( $entry );
			$value         = self::resolve( $id, $from_constant, $from_option );

			$source = '';
			if ( '' !== $value ) {
				$source = ( '' !== trim( (string) $from_constant ) ) ? 'wp-config.php' : 'base de datos';
			}

			$rows[] = array(
				'id'            => $id,
				'label'         => (string) $entry['label'],
				'owner'         => (string) $entry['owner'],
				'scope'         => (string) $entry['scope'],
				'const'         => (string) $entry['const'],
				'required'      => (bool) $entry['required'],
				'configured'    => '' !== $value,
				// Definida pero rechazada: el caso que de otro modo se ve idéntico
				// a «no está» y manda a buscar donde no es.
				//
				// La pregunta es si la constante EXISTE, no si su valor rendido
				// llegó hasta aquí. `raw_constant()` devuelve `null` en tres
				// situaciones distintas —no está, es del tipo equivocado
				// (`expect_bool`), o no es escalar— y las dos últimas SÍ están
				// escritas en el `wp-config`. Mirar el valor rendido las contaba
				// como ausentes: el aviso del admin mandaba a añadir un `define`
				// que ya estaba puesto tres líneas más arriba, que es justo el
				// fallo que esta bandera existe para evitar. Una constante en
				// cadena vacía entra aquí a propósito: está escrita y no sirve.
				'malformed'     => '' === $value && ( defined( (string) $entry['const'] ) || '' !== trim( (string) $from_option ) ),
				'source'        => $source,
				'length'        => strlen( $value ),
				'owner_file'    => (string) $entry['owner_file'],
				'owner_file_ok' => self::owner_file_ok( (string) $entry['owner_file'] ),
				// Presente NO es lo mismo que vivo, y confundirlos hacía que esta
				// pantalla contradijera al centinela con el correo caído.
				'owner_live'    => self::owner_live( (string) $entry['owner_file'] ),
				'console_url'   => (string) $entry['console_url'],
			);
		}

		return $rows;
	}

	/**
	 * El único consumidor externo que publica su propio predicado de «vivo».
	 *
	 * Se nombra por su ruta relativa, igual que en el catálogo, para no arrastrar
	 * una dependencia de carga: los mu-plugins se cargan en orden alfabético y el
	 * inventario no puede exigir que el transporte ya exista.
	 */
	private const TRANSPORT_FILE = 'mu-plugins/akibara-correo-smtp.php';

	/**
	 * ¿Sigue existiendo el archivo que consume la clave?
	 *
	 * Es lo que convierte «el mu-plugin del correo desapareció» en una fila roja
	 * en el admin, y no en tres días de silencio.
	 *
	 * @param string $relative Ruta relativa a wp-content, o cadena vacía.
	 */
	private static function owner_file_ok( string $relative ): bool {
		if ( '' === $relative ) {
			return true;
		}
		if ( ! defined( 'WP_CONTENT_DIR' ) ) {
			return true;
		}

		// Memo por petición: `render_notice()` recorre el inventario en CADA
		// página del admin, y hoy seis entradas apuntan al mismo archivo. Sin
		// esto son seis stat() por pantalla para responder la misma pregunta.
		if ( ! isset( self::$files[ $relative ] ) ) {
			self::$files[ $relative ] = is_readable(
				rtrim( (string) constant( 'WP_CONTENT_DIR' ), '/' ) . '/' . $relative
			);
		}

		return self::$files[ $relative ];
	}

	/**
	 * Consulta Transport::is_live() para distinguir un archivo legible de un
	 * transporte cargado y enganchado. Devuelve null si el consumidor no existe
	 * o no publica un predicado de actividad propio.
	 *
	 * @param string $relative Ruta relativa a wp-content, o cadena vacía.
	 */
	private static function owner_live( string $relative ): ?bool {
		if ( self::TRANSPORT_FILE !== $relative ) {
			return null;
		}

		if ( ! class_exists( '\Akibara\HostingerMail\Transport' )
			|| ! method_exists( '\Akibara\HostingerMail\Transport', 'is_live' ) ) {
			return false;
		}

		return \Akibara\HostingerMail\Transport::is_live();
	}

	/**
	 * Valor bruto de la constante.
	 *
	 * Un booleano se rinde como `true`/`false`, NO como `1`/``: aplanarlo al
	 * mismo texto que el entero `1` hacía que el catálogo aceptara valores que
	 * su consumidor rechaza. Si un patrón exige un booleano de verdad, ésta es
	 * la línea que se lo permite.
	 *
	 * Pero aplanar es una puerta de ida: en cuanto el valor es texto, el patrón
	 * ya no puede saber si vino de `true` o de `'true'`, y el consumidor que
	 * exige identidad estricta (`true ===`) rechaza la cadena que el catálogo
	 * acaba de dar por buena. Por eso una entrada con `expect_bool` se juzga
	 * AQUÍ, mirando el tipo real, y no más abajo con una expresión regular.
	 *
	 * `null` significa «no hay valor utilizable», y NO significa «no está
	 * definida»: eso lo decide `defined()` en `inventory()`. La diferencia no es
	 * cosmética —es la que separa el aviso «está definida pero con un formato
	 * que no valida» del aviso «falta»—, y mientras esta rama devolvió `null`
	 * sin que nadie más preguntara por la constante, el panel mandaba a escribir
	 * lo que ya estaba escrito.
	 *
	 * @param array<string, mixed> $entry Entrada del catálogo.
	 */
	private static function raw_constant( array $entry ): ?string {
		$name = (string) $entry['const'];
		if ( ! defined( $name ) ) {
			return null;
		}

		$value = constant( $name );

		if ( ! empty( $entry['expect_bool'] ) && ! is_bool( $value ) ) {
			return null;
		}

		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}

		return is_scalar( $value ) ? (string) $value : null;
	}

	/**
	 * Valor bruto de la option, sólo si la entrada declara una.
	 *
	 * @param array<string, mixed> $entry Entrada del catálogo.
	 */
	private static function raw_option( array $entry ): ?string {
		$option = (string) $entry['option'];
		if ( '' === $option || ! function_exists( 'get_option' ) ) {
			return null;
		}

		$value = get_option( $option, '' );

		return is_scalar( $value ) ? (string) $value : null;
	}

	/**
	 * Delega en el transporte la validez de perfiles de prueba fuera de producción.
	 * Los patrones del catálogo describen el perfil productivo; el interruptor
	 * expect_bool sigue exigiendo true en todos los entornos.
	 *
	 * @param array<string, mixed> $row           Fila de `inventory()`.
	 * @param bool                 $es_produccion Si el sitio se juzga como producción.
	 * @return bool
	 */
	public static function accepted_outside_production( array $row, bool $es_produccion ): bool {
		if ( $es_produccion || empty( $row['malformed'] ) ) {
			return false;
		}

		$catalogo = Registry::all();
		$entry    = $catalogo[ (string) $row['id'] ] ?? null;
		$correo   = $catalogo['hostinger_mail_enabled'] ?? null;
		// Fuera del dominio oficial se trata como clon aunque herede production.
		if ( null === $entry || null === $correo
			|| ! empty( $entry['expect_bool'] )
			|| ( $correo['owner_file'] ?? '' ) !== ( $entry['owner_file'] ?? '' ) ) {
			return false;
		}

		return class_exists( '\Akibara\HostingerMail\Transport' ) && \Akibara\HostingerMail\Transport::profile_is_valid();
	}

	/**
	 * Vacía el memo. Para las pruebas y para después de rotar una clave.
	 */
	public static function flush(): void {
		self::$memo  = array();
		self::$files = array();
	}
}

/**
 * Lo que el dueño ve sin entrar por SSH.
 */
final class Screen {

	/** Capacidad exigida para ver el inventario. */
	private const CAPABILITY = 'manage_options';

	/**
	 * Engancha la pantalla y el aviso.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_page' ), 99 );
		add_action( 'admin_notices', array( self::class, 'render_notice' ) );
	}

	/**
	 * Submenú dentro del panel de Akibara.
	 */
	public static function add_page(): void {
		add_submenu_page(
			'akibara-panel',
			__( 'Claves y accesos', 'akibara' ),
			__( 'Claves y accesos', 'akibara' ),
			self::CAPABILITY,
			'akibara-provider-access',
			array( self::class, 'render_page' )
		);
	}

	/**
	 * Aviso cuando falta una clave obligatoria o el archivo que la usa.
	 */
	public static function render_notice(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		// No hay acceso genérico a secretos cuyo consumidor es externo.
		// El inventario comprueba que el archivo consumidor exista.
		$es_produccion = Registry::judged_as_production( wp_get_environment_type(), (string) home_url() );

		$problemas = array();
		$inerte    = false;
		foreach ( Access::inventory() as $row ) {
			if ( ! $row['owner_file_ok'] ) {
				$problemas[] = sprintf(
					/* translators: 1: nombre de la clave. 2: ruta del archivo que la consume. */
					__( '«%1$s»: falta el archivo que la usa (%2$s).', 'akibara' ),
					$row['label'],
					$row['owner_file']
				);
				continue;
			}
			// El archivo está y no carga: el caso que esta pantalla daba por bueno
			// mientras el centinela gritaba. Se nombra UNA vez, no seis: seis
			// entradas comparten el mismo consumidor.
			if ( false === $row['owner_live'] && ! $inerte ) {
				$inerte      = true;
				$problemas[] = sprintf(
					/* translators: %s: ruta del archivo que la consume. */
					__( 'El transporte de correo está en su sitio pero NO se cargó (%s): el correo no sale. Mira el aviso del centinela, que dice qué pasó.', 'akibara' ),
					$row['owner_file']
				);
			}
			if ( $es_produccion && $row['required'] && ! $row['configured'] ) {
				$problemas[] = $row['malformed']
					/* translators: 1: nombre de la clave. 2: nombre de la constante. */
					? sprintf( __( '«%1$s»: está definida pero con un formato que no valida (%2$s).', 'akibara' ), $row['label'], $row['const'] )
					/* translators: 1: nombre de la clave. 2: nombre de la constante. */
					: sprintf( __( '«%1$s»: falta (%2$s).', 'akibara' ), $row['label'], $row['const'] );
			}
		}

		if ( array() === $problemas ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p><strong>%s</strong></p><ul style="list-style:disc;margin-left:1.5em">%s</ul></div>',
			esc_html__( 'Akibara: faltan claves de proveedores', 'akibara' ),
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- cada elemento ya sale por esc_html() dentro del array_map; lo único sin escapar son los <li> literales de este fichero, que son el marcado que queremos servir. El sniff no puede mirar dentro del implode().
			implode( '', array_map( static fn( $p ) => '<li>' . esc_html( (string) $p ) . '</li>', $problemas ) )
		);
	}

	/**
	 * El inventario. Sólo lectura: no hay formulario que acepte credenciales.
	 */
	public static function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'Claves y accesos', 'akibara' ) . '</h1>';
		echo '<p>' . esc_html__( 'Qué claves de proveedores espera Akibara y cuáles están puestas. Esta pantalla NUNCA muestra el valor de una clave: sólo si está, de dónde sale y cuántos caracteres tiene.', 'akibara' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr>'
			. '<th>' . esc_html__( 'Clave', 'akibara' ) . '</th>'
			. '<th>' . esc_html__( 'Estado', 'akibara' ) . '</th>'
			. '<th>' . esc_html__( 'Ámbito', 'akibara' ) . '</th>'
			. '<th>' . esc_html__( 'Origen', 'akibara' ) . '</th>'
			. '<th>' . esc_html__( 'Quién la usa', 'akibara' ) . '</th>'
			. '</tr></thead><tbody>';

		// Una clave de servidor opcional ausente no bloquea el inventario.
		$es_produccion = Registry::judged_as_production( wp_get_environment_type(), (string) home_url() );

		foreach ( Access::inventory() as $row ) {
			if ( $row['configured'] ) {
				$estado = sprintf(
					/* translators: %s: cantidad de caracteres. */
					esc_html__( '✓ puesta (%s caracteres)', 'akibara' ),
					number_format_i18n( (int) $row['length'] )
				);
			} elseif ( Access::accepted_outside_production( $row, $es_produccion ) ) {
				$estado = esc_html__( '— valor de pruebas: el transporte lo acepta fuera de producción', 'akibara' );
			} elseif ( $row['malformed'] ) {
				$estado = esc_html__( '✗ formato inválido', 'akibara' );
			} elseif ( $row['required'] && $es_produccion ) {
				$estado = esc_html__( '✗ falta (obligatoria)', 'akibara' );
			} elseif ( $row['required'] ) {
				$estado = esc_html__( '— sin configurar (obligatoria sólo en producción)', 'akibara' );
			} else {
				$estado = esc_html__( '— sin configurar', 'akibara' );
			}

			$ambito = Registry::SCOPE_SERVER === $row['scope']
				? esc_html__( 'servidor · sólo wp-config.php', 'akibara' )
				: esc_html__( 'pública · viaja al navegador', 'akibara' );

			$quien = esc_html( (string) $row['owner'] );
			if ( '' !== $row['owner_file'] && ! $row['owner_file_ok'] ) {
				$quien .= ' <strong style="color:#b32d2e">'
					. esc_html__( '(¡falta el archivo!)', 'akibara' ) . '</strong>';
			} elseif ( false === $row['owner_live'] ) {
				$quien .= ' <strong style="color:#b32d2e">'
					. esc_html__( '(el archivo está, pero no se cargó)', 'akibara' ) . '</strong>';
			}

			printf(
				'<tr><td><strong>%s</strong><br><code>%s</code></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( (string) $row['label'] ),
				esc_html( (string) $row['const'] ),
				$estado, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ya escapado arriba.
				$ambito, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ya escapado arriba.
				esc_html( '' !== $row['source'] ? (string) $row['source'] : '—' ),
				$quien   // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ya escapado arriba.
			);
		}

		echo '</tbody></table>';
		echo '<h2>' . esc_html__( '¿Dónde va una clave nueva?', 'akibara' ) . '</h2>';
		echo '<p>' . esc_html__( 'Si la clave viaja al navegador del cliente aunque sea una sola vez (mapas, analítica, captchas), es pública y puede editarse desde el admin. Si no viaja (contraseñas, tokens de pago, tokens de infraestructura), es un secreto de servidor y va únicamente al wp-config.php de producción, nunca a la base de datos.', 'akibara' ) . '</p>';
		echo '</div>';
	}
}

/**
 * Comandos WP-CLI: el inventario y el gate del despliegue.
 */
final class Cli {

	/**
	 * Registra los comandos.
	 */
	public static function register(): void {
		\WP_CLI::add_command( 'akibara access-list', array( self::class, 'listado' ) );
		\WP_CLI::add_command( 'akibara access-check', array( self::class, 'check' ) );
		\WP_CLI::add_command( 'akibara access-scan', array( self::class, 'scan' ) );
	}

	/**
	 * Inventario completo, sin valores.
	 */
	public static function listado(): void {
		$es_produccion = Registry::judged_as_production( wp_get_environment_type(), (string) home_url() );
		$filas         = array();
		foreach ( Access::inventory() as $row ) {
			$estado  = self::estado_de( $row, $es_produccion );
			$filas[] = array(
				'clave'    => $row['id'],
				'constante'=> $row['const'],
				'ambito'   => $row['scope'],
				'estado'   => $estado,
				'origen'   => '' !== $row['source'] ? $row['source'] : '—',
				'longitud' => $row['configured'] ? (string) $row['length'] : '—',
			);
		}

		\WP_CLI\Utils\format_items( 'table', $filas, array( 'clave', 'constante', 'ambito', 'estado', 'origen', 'longitud' ) );
	}

	/**
	 * Evalúa los problemas del inventario como contrato ejecutable compartido
	 * por el gate de despliegue y sus pruebas.
	 *
	 * @param array<int, array<string, mixed>> $filas         Filas de `Access::inventory()`.
	 * @param bool                             $es_produccion Si el sitio se juzga como producción.
	 * @param string                           $desajuste     Problema de entorno, o ''.
	 * @param bool|null                        $perfil_valido Veredicto del transporte sobre el perfil, o null si no aplica.
	 * @return array<int, string>
	 */
	public static function problemas( array $filas, bool $es_produccion, string $desajuste, ?bool $perfil_valido ): array {
		$problemas = array();
		if ( '' !== $desajuste ) {
			$problemas[] = $desajuste;
		}

		$inerte = false;
		foreach ( $filas as $row ) {
			if ( empty( $row['owner_file_ok'] ) ) {
				$problemas[] = sprintf( '%s: falta el archivo que la consume (%s)', $row['id'], $row['owner_file'] );
				continue;
			}

			// Una vez, no seis: seis entradas comparten el mismo consumidor.
			if ( false === ( $row['owner_live'] ?? null ) && ! $inerte ) {
				$inerte      = true;
				$problemas[] = sprintf(
					'%s: el archivo que la consume está en su sitio pero NO se cargó (%s). El correo no sale.',
					$row['id'],
					$row['owner_file']
				);
			}

			if ( $es_produccion && ! empty( $row['required'] ) && empty( $row['configured'] ) ) {
				$problemas[] = sprintf(
					'%s: %s (%s)',
					$row['id'],
					! empty( $row['malformed'] ) ? 'definida con formato inválido' : 'sin configurar',
					$row['const']
				);
			}
		}

		if ( false === $perfil_valido ) {
			$problemas[] = 'perfil_smtp: las constantes están puestas pero el transporte rechaza la combinación (servidor, puerto, cifrado y remitente). El correo NO sale.';
		}

		return $problemas;
	}

	/**
	 * Etiqueta coherente del inventario para CLI. Las claves opcionales fuera de
	 * producción no se presentan como ausentes si el transporte admite el perfil.
	 *
	 * @param array<string, mixed> $row           Fila de `Access::inventory()`.
	 * @param bool                 $es_produccion Si el sitio se juzga como producción.
	 */
	public static function estado_de( array $row, bool $es_produccion ): string {
		if ( empty( $row['owner_file_ok'] ) ) {
			return 'SIN CONSUMIDOR';
		}
		// Un consumidor presente pero inerte no puede presentarse como operativo.
		if ( false === ( $row['owner_live'] ?? null ) ) {
			return 'NO CARGADO';
		}
		if ( ! empty( $row['configured'] ) ) {
			return 'ok';
		}
		if ( Access::accepted_outside_production( $row, $es_produccion ) ) {
			return 'PRUEBAS';
		}
		if ( ! empty( $row['malformed'] ) ) {
			return 'FORMATO';
		}
		if ( empty( $row['required'] ) ) {
			return '—';
		}

		return $es_produccion ? 'FALTA' : 'SÓLO EN PROD';
	}

	/**
	 * Sale con 1 si falta algo obligatorio. Es el gate del despliegue.
	 */
	public static function check(): void {
		// No hay acceso genérico a secretos cuyo consumidor es externo.
		// El inventario comprueba que el archivo consumidor exista.
		$entorno       = wp_get_environment_type();
		$inicio        = (string) home_url();
		$es_produccion = Registry::judged_as_production( $entorno, $inicio );

		// Consulta la validez del perfil completo en el transporte, que conoce las
		// combinaciones admitidas y mantiene privados los secretos.
		$perfil_valido = null;
		if ( $es_produccion && class_exists( '\Akibara\HostingerMail\Transport' ) ) {
			$perfil_valido = \Akibara\HostingerMail\Transport::profile_is_valid();
		}

		$problemas = self::problemas(
			Access::inventory(),
			$es_produccion,
			Registry::environment_mismatch( $entorno, $inicio ),
			$perfil_valido
		);

		if ( array() !== $problemas ) {
			foreach ( $problemas as $p ) {
				\WP_CLI::warning( $p );
			}
			\WP_CLI::error( sprintf( '%d acceso(s) obligatorio(s) sin resolver.', count( $problemas ) ) );
		}

		\WP_CLI::success( 'Todos los accesos obligatorios están configurados.' );
	}

	/**
	 * Devuelve el código sin comentarios.
	 *
	 * Un `grep` sobre el archivo entero delata literales escritos DENTRO de un
	 * comentario: la primera pasada de `access-scan` en producción se quejó de
	 * una constante de ejemplo que sólo existía en un PHPDoc de este mismo
	 * archivo. Con `token_get_all()` la distinción es exacta y gratis.
	 *
	 * @param string $codigo Contenido del archivo.
	 */
	public static function code_only( string $codigo ): string {
		$limpio = '';
		foreach ( token_get_all( $codigo ) as $token ) {
			if ( is_array( $token ) ) {
				if ( T_COMMENT === $token[0] || T_DOC_COMMENT === $token[0] ) {
					continue;
				}
				$limpio .= $token[1];
				continue;
			}
			$limpio .= $token;
		}

		return $limpio;
	}

	/**
	 * Dónde busca el barrido de constantes.
	 *
	 * **Todo** el código propio, no tres carpetas: hasta 1.2.2 miraba sólo
	 * `mu-plugins`, el plugin principal y el tema, así que una clave leída a mano
	 * en el buscador, en los puntos o en la caja de pago era invisible para el
	 * gate — y son tres de los cinco sitios donde alguien escribiría una.
	 *
	 * Lo que sigue quedando fuera, dicho para que nadie lo dé por cubierto: una
	 * constante usada **desnuda**, sin `defined()` ni `constant()`. Esa forma no la
	 * caza esta expresión, y cazarla pide mirar tokens en vez de texto.
	 *
	 * @var array<int, string>
	 */
	public const SCAN_DIRS = array(
		'/mu-plugins',
		'/plugins/akibara-business',
		'/plugins/akibara-search',
		'/plugins/akibara-rewards',
		'/plugins/akibara-cart-checkout-blocks',
		'/themes/akibara-gp',
	);

	/**
	 * Constantes AKB_/AKIBARA_ que el código lee sin estar declaradas.
	 *
	 * El inventario sólo dice la verdad si nadie lee claves por su cuenta.
	 */
	public static function scan(): void {
		$declaradas = Registry::declared_constants();
		$raiz       = rtrim( (string) constant( 'WP_CONTENT_DIR' ), '/' );
		$huerfanas  = array();

		foreach ( self::SCAN_DIRS as $sub ) {
			$dir = $raiz . $sub;
			if ( ! is_dir( $dir ) ) {
				continue;
			}
			$archivos = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $archivos as $archivo ) {
				if ( ! $archivo->isFile() || 'php' !== strtolower( (string) $archivo->getExtension() ) ) {
					continue;
				}
				// Las pruebas escriben constantes de mentira a propósito —el propio
				// test de este archivo usa tres— y además no viajan a producción.
				if ( false !== strpos( (string) $archivo->getPathname(), '/tests/' ) ) {
					continue;
				}
				$codigo = self::code_only( (string) file_get_contents( (string) $archivo->getPathname() ) );
				if ( ! preg_match_all( '/(?:defined|constant)\(\s*[\'"]((?:AKB|AKIBARA)_[A-Z0-9_]+)[\'"]/', $codigo, $m ) ) {
					continue;
				}
				foreach ( array_unique( $m[1] ) as $nombre ) {
					if ( in_array( $nombre, $declaradas, true )
						|| in_array( $nombre, Registry::NOT_A_PROVIDER_KEY, true ) ) {
						continue;
					}
					$huerfanas[ $nombre ][] = str_replace( $raiz . '/', '', (string) $archivo->getPathname() );
				}
			}
		}

		if ( array() === $huerfanas ) {
			\WP_CLI::success( 'Ninguna constante de proveedor se lee fuera del catálogo.' );

			return;
		}

		foreach ( $huerfanas as $nombre => $archivos ) {
			\WP_CLI::warning( sprintf( '%s se lee en: %s', $nombre, implode( ', ', array_unique( $archivos ) ) ) );
		}
		\WP_CLI::log( 'Declárala en Registry::CATALOG o deja de leerla a mano.' );
	}
}

Screen::register();

if ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) ) {
	Cli::register();
}
