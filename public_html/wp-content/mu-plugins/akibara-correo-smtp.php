<?php
/**
 * Plugin Name: Akibara — Correo SMTP de Hostinger
 * Description: Transporte único de wp_mail() por el buzón autenticado de Akibara, sin interfaz ni credenciales en la base de datos. Cuando corta un envío deja anotado CUÁL, para saber a quién hay que reescribirle.
 * Version: 1.3.0
 * Author: Akibara
 *
 * @package Akibara\MuPlugins
 */

declare( strict_types = 1 );

namespace Akibara\HostingerMail;

defined( 'ABSPATH' ) || exit;

/**
 * Configura PHPMailer únicamente cuando el transporte fue habilitado de forma
 * explícita mediante constantes privadas de wp-config.php.
 */
final class Transport {

	private const DEFAULT_HOST       = 'smtp.hostinger.com';
	private const DEFAULT_PORT       = 465;
	private const DEFAULT_ENCRYPTION = 'ssl';
	private const REQUIRED_SENDER    = 'contacto@akibara.cl';

	/**
	 * Los hosts que SON la tienda.
	 *
	 * Duplicado a propósito en `akibara-provider-access.php`
	 * (`Registry::PRODUCTION_HOSTS`): los mu-plugins cargan en orden alfabético y
	 * ninguno puede depender del otro. `DominioDeProduccionTest` fija que los dos
	 * predicados respondan lo mismo.
	 */
	public const PRODUCTION_HOSTS = array( 'akibara.cl', 'www.akibara.cl' );

	/** Option NO autoload con el rastro del corte vigente. */
	public const OPTION_OUTAGE = 'akibara_mail_transport_outage';

	/** El guard cortó el envío: falta configuración. Estructural, afecta a TODO. */
	public const KIND_BLOCKED = 'blocked';

	/** El envío salió del guard pero el servidor SMTP lo rechazó. */
	public const KIND_SEND_FAILED = 'send_failed';

	/**
	 * A partir de cuántos rechazos SEGUIDOS del SMTP se enciende el aviso.
	 *
	 * Un rechazo suelto suele ser una dirección de cliente mal escrita, no una
	 * caída: gritar por eso enseña a ignorar la alarma. Como cualquier envío
	 * correcto borra el rastro (`clear_outage`), llegar a tres seguidos ya
	 * significa que no está saliendo nada.
	 */
	private const SEND_FAILURE_THRESHOLD = 3;

	/**
	 * Conserva los últimos veinte envíos fallidos, con destinatario y asunto,
	 * para que el dueño pueda identificar a quién debe reenviar el aviso.
	 */
	private const BLOCKED_LIMIT = 20;

	/**
	 * Cuántos días se conservan esos correos antes de caducar.
	 *
	 * Son datos de clientes —destinatario y asunto—: se guardan para poder
	 * reescribirles, y ese uso caduca. Un mes es de sobra para atender un corte;
	 * más allá, sólo queda el dato.
	 */
	private const BLOCKED_MAX_DAYS = 30;

	/**
	 * Candado de reentrada de record_outage().
	 *
	 * Anotar el corte pasa por `update_option()`, y cualquier oyente de ese
	 * guardado que intente avisar por correo vuelve a entrar por `wp_mail()`,
	 * choca otra vez con el guard y vuelve a anotar: recursión sin fondo dentro
	 * de la petición de un cliente. El candado corta eso sin depender de quién
	 * sea el oyente.
	 */
	private static bool $recording = false;

	/**
	 * Registra el guard y la configuración del transporte.
	 */
	public static function register(): void {
		add_filter( 'pre_wp_mail', array( self::class, 'guard_configuration' ), PHP_INT_MAX, 2 );
		add_action( 'phpmailer_init', array( self::class, 'configure_phpmailer' ), PHP_INT_MAX, 1 );

		// El rastro se borra cuando un correo SALE, no cuando PHPMailer se
		// construye. Hasta 1.1.0 `clear_outage` colgaba de `phpmailer_init`, que en
		// wp-includes/pluggable.php se dispara en la línea 622 — SEIS líneas ANTES
		// del `$phpmailer->send()` de la 628. Eso rompía el aviso por dos caminos
		// medidos: (a) `ops/lib/akibara-mail-probe.php:27` dispara `phpmailer_init`
		// para sondear el transporte, así que el chequeo de salud que corre al
		// cierre de CADA deploy —y el que se corre a mano para diagnosticar— borraba
		// la evidencia del corte sin haber enviado nada; (b) con la contraseña
		// rotada o mal escrita el perfil sigue validando (sólo se exige que no esté
		// vacía), el guard deja pasar, se borraba el aviso y recién después `send()`
		// fallaba con error de autenticación: el dueño veía apagarse la alarma roja
		// justo cuando el correo seguía sin salir. `wp_mail_succeeded` (línea 651)
		// es el único hook que prueba que el envío se procesó.
		add_action( 'wp_mail_succeeded', array( self::class, 'clear_outage' ), PHP_INT_MAX, 0 );

		// Y el reverso: un `send()` que lanza (SMTP caído, credenciales rechazadas,
		// buzón lleno) nunca pasaba por el guard, así que no dejaba rastro ninguno.
		add_action( 'wp_mail_failed', array( self::class, 'record_send_failure' ), PHP_INT_MAX, 1 );

		// admin_notices sólo corre en el escritorio; se registra sin consultar el
		// contexto durante la carga temprana de los mu-plugins.
		add_action( 'admin_notices', array( self::class, 'render_outage_notice' ) );
	}

	/**
	 * Autoridad compartida por el vigía y el inventario de claves. Exige que la
	 * clase esté cargada y el callback enganchado a PHPMailer. Una prioridad 0
	 * es válida y se distingue de false de forma estricta.
	 */
	public static function is_live(): bool {
		if ( ! function_exists( 'has_action' ) ) {
			return false;
		}

		return false !== has_action( 'phpmailer_init', array( self::class, 'configure_phpmailer' ) );
	}

	/**
	 * Impide caer silenciosamente a PHP mail() si el transporte fue habilitado
	 * con una configuración incompleta o inválida.
	 *
	 * @param mixed                $short_circuit Valor previo del filtro.
	 * @param array<string, mixed> $attributes    Atributos de wp_mail().
	 * @return mixed
	 */
	public static function guard_configuration( $short_circuit, array $attributes ) {
		$environment = self::environment();

		// Antes que la rama permisiva: en el dominio de la tienda NO hay espejo
		// que valga. Con 1.2.1 esa rama se abría para cualquier entorno distinto
		// de `production`, así que restaurar en akibara.cl una copia de staging
		// (con su `WP_ENVIRONMENT_TYPE`) dejaba el transporte tranquilo, el
		// guardarraíl de captura desviando TODO el correo de clientes a la
		// bandeja de pruebas y ninguna alarma. Con 1.2.0 el guard cortaba y el
		// aviso rojo salía; aquí se recupera eso, con la causa verdadera.
		$desajuste = self::environment_mismatch( $environment );
		if ( '' !== $desajuste ) {
			self::record_outage( $desajuste, self::KIND_BLOCKED, $attributes );

			return false;
		}

		if ( 'production' !== $environment && ! self::is_enabled() ) {
			// Fuera de producción, un transporte apagado no es un corte: es el
			// estado normal del espejo, donde el correo lo recoge Mailpit o el
			// `mail` del contenedor y lo redirige el guardarraíl de captura.
			return $short_circuit;
		}

		if ( ! self::is_enabled() || null === self::validated_configuration() ) {
			// Cortar wp_mail() acá es correcto (mejor no enviar que filtrar por
			// el mail() de PHP), pero hasta 1.1.0 era 100 % SILENCIOSO: en agosto
			// el sitio pasó TRES días sin mandar un solo correo —pedido 27720
			// incluido— y se descubrió porque un cliente reclamó. `pre_wp_mail`
			// devolviendo false no dispara `wp_mail_failed`, así que ningún
			// diagnóstico existente se enteraba. Ahora queda rastro con la causa
			// y un contador con la hora del último corte, que es lo que permite
			// verlo desde el admin sin entrar por SSH.
			self::record_outage(
				self::is_enabled() ? 'configuración inválida o incompleta' : 'transporte deshabilitado',
				self::KIND_BLOCKED,
				$attributes
			);

			return false;
		}

		return $short_circuit;
	}

	/**
	 * Anota un envío que el servidor SMTP rechazó o no pudo procesar.
	 *
	 * `pre_wp_mail` sólo ve los cortes por configuración; un `send()` que lanza
	 * —credenciales rotadas, SMTP caído, cuota del buzón agotada— no pasa por el
	 * guard y hasta 1.1.0 no dejaba más rastro que un WARNING en wc-logs, que
	 * nadie mira.
	 *
	 * @param mixed $error WP_Error que el core adjunta a `wp_mail_failed`.
	 */
	public static function record_send_failure( $error ): void {
		$message = '';
		if ( is_object( $error ) && method_exists( $error, 'get_error_message' ) ) {
			$message = (string) $error->get_error_message();
		}

		$message = trim( (string) preg_replace( '/\s+/', ' ', $message ) );
		$message = '' === $message ? 'el servidor de correo rechazó el envío' : self::shorten( $message, 140 );

		// El core adjunta a `wp_mail_failed` los datos del envío que no salió
		// (`to`, `subject`, `message`, `headers`, `attachments`). Es la única vía
		// para saber A QUIÉN hay que reescribirle cuando el buzón rechaza.
		$attributes = array();
		if ( is_object( $error ) && method_exists( $error, 'get_error_data' ) ) {
			$data       = $error->get_error_data();
			$attributes = is_array( $data ) ? $data : array();
		}

		self::record_outage( $message, self::KIND_SEND_FAILED, $attributes );
	}

	/**
	 * Recorta un texto sin cortar un carácter multibyte por la mitad.
	 *
	 * @param string $texto Texto de entrada.
	 * @param int    $tope  Largo máximo en caracteres.
	 */
	private static function shorten( string $texto, int $tope ): string {
		return function_exists( 'mb_substr' ) ? mb_substr( $texto, 0, $tope ) : substr( $texto, 0, $tope );
	}

	/**
	 * Conserva destinatario y asunto, sin cuerpo ni cabeceras. La lista se mantiene
	 * al cambiar la causa del corte; el contador de la causa se reinicia por separado.
	 *
	 * @param array<int, array<string, string>> $blocked    Lista previa.
	 * @param array<string, mixed>              $attributes Atributos de `wp_mail()`.
	 * @param string                            $now        Momento, en GMT.
	 * @return array<int, array<string, string>>
	 */
	public static function remember_blocked( array $blocked, array $attributes, string $now ): array {
		$to = $attributes['to'] ?? '';
		$to = is_array( $to ) ? implode( ', ', array_map( 'strval', $to ) ) : (string) $to;

		$subject = isset( $attributes['subject'] ) && is_scalar( $attributes['subject'] )
			? (string) $attributes['subject']
			: '';

		// Sin destinatario ni asunto no hay nada que anotar: pasa con un rechazo
		// del SMTP que llega sin datos del envío. Mejor una lista corta y fiable
		// que una llena de filas vacías.
		if ( '' === trim( $to ) && '' === trim( $subject ) ) {
			return $blocked;
		}

		// Caducidad: esto guarda correos de clientes, así que no puede quedarse
		// para siempre. Un corte se atiende en días, no en meses; pasado el mes,
		// reescribir ese correo ya no es lo que toca y el dato deja de tener uso.
		// El tope de veinte no basta como caducidad: con un corte antiguo y poco
		// tráfico, veinte filas pueden ser de hace medio año.
		$ahora = strtotime( $now . ' UTC' );
		if ( false !== $ahora ) {
			$limite  = gmdate( 'Y-m-d H:i:s', $ahora - ( self::BLOCKED_MAX_DAYS * 86400 ) );
			$blocked = array_values(
				array_filter(
					$blocked,
					static fn( $envio ): bool => is_array( $envio ) && (string) ( $envio['gmt'] ?? '' ) >= $limite
				)
			);
		}

		$blocked[] = array(
			'gmt'     => $now,
			'to'      => self::shorten( trim( $to ), 120 ),
			'subject' => self::shorten( trim( $subject ), 120 ),
		);

		if ( count( $blocked ) > self::BLOCKED_LIMIT ) {
			$blocked = array_values( array_slice( $blocked, -self::BLOCKED_LIMIT ) );
		}

		return $blocked;
	}

	/**
	 * Persiste causa, hora y conteo sin autoload. Si la base no está disponible
	 * durante el arranque no interrumpe el envío. El escritorio muestra el aviso
	 * sin depender de que funcione el propio transporte de correo.
	 *
	 * @param string               $reason     Causa del corte.
	 * @param string               $kind       KIND_BLOCKED (el guard cortó) o KIND_SEND_FAILED (el SMTP falló).
	 * @param array<string, mixed> $attributes Atributos del envío que no salió, si se conocen.
	 */
	private static function record_outage( string $reason, string $kind = self::KIND_BLOCKED, array $attributes = array() ): void {
		if ( ! function_exists( 'update_option' ) || ! function_exists( 'get_option' ) ) {
			return;
		}

		if ( self::$recording ) {
			return;
		}
		self::$recording = true;

		try {
			$state = get_option( self::OPTION_OUTAGE, array() );
			$state = is_array( $state ) ? $state : array();
			$now   = gmdate( 'Y-m-d H:i:s' );

			// Un cambio de naturaleza reinicia el conteo: mezclar «el guard cortó 4»
			// con «el SMTP rechazó 2» daría un número que no describe nada.
			$same  = isset( $state['kind'] ) && $kind === $state['kind'];
			$count = ( $same && isset( $state['count'] ) ) ? (int) $state['count'] : 0;

			update_option(
				self::OPTION_OUTAGE,
				array(
					'kind'      => $kind,
					'reason'    => $reason,
					'last_gmt'  => $now,
					'first_gmt' => $same ? (string) ( $state['first_gmt'] ?? $now ) : $now,
					'count'     => $count + 1,
					'blocked'   => self::remember_blocked(
						isset( $state['blocked'] ) && is_array( $state['blocked'] ) ? $state['blocked'] : array(),
						$attributes,
						$now
					),
				),
				false
			);
		} catch ( \Throwable $exception ) {
			unset( $exception );
		} finally {
			self::$recording = false;
		}
	}

	/**
	 * Limpia el rastro de corte cuando un envío vuelve a salir.
	 *
	 * Cuelga de `wp_mail_succeeded`, o sea de un `send()` que ya se procesó sin
	 * lanzar. Sin esto el aviso quedaría encendido para siempre después del
	 * primer incidente y el dueño aprendería a ignorarlo.
	 */
	public static function clear_outage(): void {
		if ( ! function_exists( 'get_option' ) || ! function_exists( 'delete_option' ) ) {
			return;
		}
		if ( array() === (array) get_option( self::OPTION_OUTAGE, array() ) ) {
			return;
		}
		delete_option( self::OPTION_OUTAGE );
	}

	/**
	 * Aviso en el admin cuando el transporte está cortando envíos.
	 *
	 * NO se puede descartar a propósito: mientras el rastro exista, el correo
	 * sigue sin salir. Desaparece solo cuando un envío vuelve a SALIR
	 * (`wp_mail_succeeded` → `clear_outage()`), o sea cuando el problema está
	 * resuelto de verdad. Es la única señal que el dueño tiene sin entrar por SSH.
	 */
	public static function render_outage_notice(): void {
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$state = get_option( self::OPTION_OUTAGE, array() );
		if ( ! is_array( $state ) || empty( $state['last_gmt'] ) ) {
			return;
		}

		$count = isset( $state['count'] ) ? (int) $state['count'] : 0;
		$kind  = (string) ( $state['kind'] ?? self::KIND_BLOCKED );

		// Un rechazo aislado del SMTP casi siempre es una dirección mal escrita:
		// se anota, pero no se grita hasta que van tres seguidos sin un solo
		// envío correcto de por medio. Un corte del guard, en cambio, es
		// estructural desde el primero.
		if ( self::KIND_SEND_FAILED === $kind && $count < self::SEND_FAILURE_THRESHOLD ) {
			return;
		}

		$when = (string) $state['last_gmt'];
		// `get_date_from_gmt()` formatea con gmdate(): devolvería «23 de August»
		// en un sitio en español. `wp_date()` sí pasa por el catálogo de idioma
		// y por la zona horaria del sitio (America/Santiago).
		$stamp = strtotime( $when . ' UTC' );
		if ( false !== $stamp && function_exists( 'wp_date' ) ) {
			$when = (string) wp_date( 'j \d\e F, H:i', $stamp );
		}

		$detalle = self::KIND_SEND_FAILED === $kind
			? sprintf(
				/* translators: 1: cantidad de envíos rechazados. 2: fecha del último. 3: error del servidor. */
				_n(
					'El servidor de correo rechazó %1$s envío, el %2$s. Dijo: «%3$s».',
					'El servidor de correo rechazó %1$s envíos seguidos, el último el %2$s. Dijo: «%3$s».',
					$count,
					'akibara'
				),
				number_format_i18n( $count ),
				$when,
				(string) ( $state['reason'] ?? '' )
			)
			: sprintf(
				/* translators: 1: cantidad de envíos bloqueados. 2: fecha del último. 3: causa. */
				_n(
					'Se bloqueó %1$s envío, el %2$s. Causa: %3$s.',
					'Se bloquearon %1$s envíos, el último el %2$s. Causa: %3$s.',
					$count,
					'akibara'
				),
				number_format_i18n( $count ),
				$when,
				(string) ( $state['reason'] ?? 'desconocida' )
			);

		$que_hacer = self::KIND_SEND_FAILED === $kind
			? __( 'Afecta confirmaciones de pedido, avisos de puntos y solicitudes de reseña. El transporte está configurado, así que el problema está del lado del buzón: contraseña rotada, cuota llena o smtp.hostinger.com caído.', 'akibara' )
			: __( 'Afecta confirmaciones de pedido, avisos de puntos y solicitudes de reseña. Revisa las constantes SMTP de wp-config.php.', 'akibara' );

		printf(
			'<div class="notice notice-error"><p><strong>%s</strong></p><p>%s</p><p>%s</p><p>%s %s</p></div>',
			esc_html__( 'El correo de Akibara NO está saliendo', 'akibara' ),
			esc_html( $detalle ),
			esc_html( self::describe_blocked( $state ) ),
			esc_html( $que_hacer ),
			esc_html__( 'Este aviso desaparece solo cuando un correo vuelva a salir de verdad.', 'akibara' )
		);
	}

	/**
	 * Qué envíos concretos se quedaron sin salir, para poder reescribirles.
	 *
	 * Se nombran los tres últimos y se dice cuántos hay en total: el aviso tiene
	 * que caber en una pantalla. La lista completa vive en la option
	 * `akibara_mail_transport_outage`, clave `blocked`.
	 *
	 * @param array<string, mixed> $state Rastro del corte.
	 */
	private static function describe_blocked( array $state ): string {
		$blocked = isset( $state['blocked'] ) && is_array( $state['blocked'] ) ? $state['blocked'] : array();
		if ( array() === $blocked ) {
			return __( 'De los envíos cortados no quedó anotado el destinatario: pasa cuando el rechazo llega sin los datos del correo.', 'akibara' );
		}

		$ultimos = array_slice( $blocked, -3 );
		$lineas  = array();
		foreach ( $ultimos as $envio ) {
			if ( ! is_array( $envio ) ) {
				continue;
			}
			$lineas[] = sprintf(
				/* translators: 1: asunto del correo. 2: destinatario. */
				__( '«%1$s» → %2$s', 'akibara' ),
				(string) ( $envio['subject'] ?? '' ),
				(string) ( $envio['to'] ?? '' )
			);
		}

		return sprintf(
			/* translators: 1: cantidad de envíos anotados. 2: los últimos, con asunto y destinatario. */
			_n(
				'Se quedó sin salir %1$s correo, y hay que reescribirlo a mano: %2$s',
				'Se quedaron sin salir %1$s correos, y hay que reescribirlos a mano. Los últimos: %2$s',
				count( $blocked ),
				'akibara'
			),
			number_format_i18n( count( $blocked ) ),
			implode( ' · ', $lineas )
		);
	}

	/**
	 * Aplica el perfil SMTP validado al PHPMailer de WordPress.
	 *
	 * @param object $phpmailer Instancia de PHPMailer creada por WordPress.
	 */
	public static function configure_phpmailer( object $phpmailer ): void {
		if ( ! self::is_enabled() || ! method_exists( $phpmailer, 'isSMTP' ) ) {
			return;
		}

		$config = self::validated_configuration();
		if ( null === $config ) {
			return;
		}

		self::apply_configuration( $phpmailer, $config );
	}

	/**
	 * Valida el perfil sin leer ni registrar el secreto.
	 *
	 * En producción solo acepta los dos perfiles publicados por Hostinger. En
	 * local se permite un servidor SMTP de pruebas para validar el flujo Docker.
	 *
	 * @param array<string, mixed> $config      Configuración candidata.
	 * @param string               $environment Entorno WordPress.
	 * @return array{host:string,port:int,encryption:string,username:string,password:string}|null
	 */
	public static function validate_configuration( array $config, string $environment ): ?array {
		$host       = isset( $config['host'] ) && is_string( $config['host'] ) ? strtolower( trim( $config['host'] ) ) : '';
		$port       = isset( $config['port'] ) && is_numeric( $config['port'] ) ? (int) $config['port'] : 0;
		$encryption = isset( $config['encryption'] ) && is_string( $config['encryption'] ) ? strtolower( trim( $config['encryption'] ) ) : '';
		$username   = isset( $config['username'] ) && is_string( $config['username'] ) ? strtolower( trim( $config['username'] ) ) : '';
		$password   = isset( $config['password'] ) && is_string( $config['password'] ) ? $config['password'] : '';

		if ( '' === $host || $port < 1 || $port > 65535 ) {
			return null;
		}

		if ( ! in_array( $encryption, array( '', 'ssl', 'tls' ), true ) ) {
			return null;
		}

		// Dos ramas y ninguna más: o es producción o no lo es. Hasta 1.2.2 había
		// un `else { return null; }` detrás, con el docblock de `environment()`
		// prometiendo que «cualquier entorno desconocido falla la validación»;
		// ninguna de las dos cosas era cierta —esa rama no la alcanza ninguna
		// cadena— y la promesa hacía pensar que había una defensa que no existía.
		if ( 'production' !== $environment ) {
			/*
			 * Fuera de producción, usuario y contraseña deben venir juntos o faltar ambos.
			 * El guardarraíl de captura redirige a la bandeja de QA. environment_mismatch()
			 * impide usar este perfil en el dominio de producción.
			 */
			if ( ( '' === $username ) !== ( '' === $password ) ) {
				return null;
			}
		} elseif ( 'production' === $environment ) {
			$approved_profile = self::DEFAULT_HOST === $host
				&& ( ( self::DEFAULT_PORT === $port && self::DEFAULT_ENCRYPTION === $encryption )
					|| ( 587 === $port && 'tls' === $encryption ) );

			if ( ! $approved_profile || self::REQUIRED_SENDER !== $username || '' === $password ) {
				return null;
			}
		}

		return array(
			'host'       => $host,
			'port'       => $port,
			'encryption' => $encryption,
			'username'   => $username,
			'password'   => $password,
		);
	}

	/**
	 * Aplica una configuración ya validada. Método público para el gate unitario.
	 *
	 * @param object                                                                      $phpmailer PHPMailer o doble compatible.
	 * @param array{host:string,port:int,encryption:string,username:string,password:string} $config     Perfil validado.
	 */
	public static function apply_configuration( object $phpmailer, array $config ): void {
		$phpmailer->isSMTP();
		$phpmailer->Host        = $config['host'];
		$phpmailer->Port        = $config['port'];
		$phpmailer->SMTPSecure  = $config['encryption'];
		$phpmailer->SMTPAuth    = '' !== $config['username'];
		$phpmailer->Username    = $config['username'];
		$phpmailer->Password    = $config['password'];
		$phpmailer->SMTPAutoTLS = 'tls' === $config['encryption'];
		$phpmailer->SMTPDebug   = 0;
		$phpmailer->Timeout     = 20;
		$phpmailer->SMTPOptions = array();
		$phpmailer->AuthType    = '';
		$phpmailer->SMTPKeepAlive = false;

		if ( method_exists( $phpmailer, 'setFrom' ) ) {
			$phpmailer->setFrom( self::REQUIRED_SENDER, 'Akibara', false );
		} else {
			$phpmailer->From     = self::REQUIRED_SENDER;
			$phpmailer->FromName = 'Akibara';
		}
		$phpmailer->Sender = self::REQUIRED_SENDER;

		if ( method_exists( $phpmailer, 'getSMTPInstance' ) ) {
			$smtp = $phpmailer->getSMTPInstance();
			if ( is_object( $smtp ) ) {
				$smtp->Timelimit = 20;
			}
		}
	}

	/**
	 * Determina si el transporte está habilitado de forma explícita.
	 */
	private static function is_enabled(): bool {
		return defined( 'AKIBARA_HOSTINGER_MAIL_ENABLED' )
			&& true === constant( 'AKIBARA_HOSTINGER_MAIL_ENABLED' );
	}

	/**
	 * Valida el perfil completo de constantes, incluida la combinación de host,
	 * puerto, cifrado y remitente. El gate recibe un booleano sin exponer secretos.
	 *
	 * @return bool
	 */
	public static function profile_is_valid(): bool {
		return null !== self::validated_configuration();
	}

	/**
	 * ¿El host dado es el de la tienda?
	 *
	 * Pura a propósito, para poder fijarla contra su gemela de
	 * `akibara-provider-access.php` sin montar WordPress.
	 *
	 * @param string $home_url URL de inicio del sitio.
	 * @return bool
	 */
	public static function is_production_host( string $home_url ): bool {
		$host = parse_url( trim( $home_url ), PHP_URL_HOST ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- función pura sin WordPress; el host de home_url() siempre trae esquema.

		return is_string( $host ) && in_array( rtrim( strtolower( $host ), '.' ), self::PRODUCTION_HOSTS, true );
	}

	/**
	 * Por qué este sitio no puede tratarse como un clon, o '' si puede.
	 *
	 * @param string $environment Entorno de WordPress.
	 * @return string Causa legible para el rastro del corte.
	 */
	private static function environment_mismatch( string $environment ): string {
		if ( 'production' === $environment || ! function_exists( 'home_url' ) ) {
			return '';
		}

		$home = (string) home_url();
		if ( ! self::is_production_host( $home ) ) {
			return '';
		}

		return 'el sitio responde como ' . (string) parse_url( $home, PHP_URL_HOST ) // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- mismo host que acaba de validar is_production_host().
			. ' pero WP_ENVIRONMENT_TYPE vale «' . $environment . '»: fuera de producción el correo de clientes se desvía a la bandeja de captura. Corrige WP_ENVIRONMENT_TYPE en wp-config.php';
	}

	/**
	 * Lee las constantes privadas y devuelve únicamente un perfil validado.
	 *
	 * @return array{host:string,port:int,encryption:string,username:string,password:string}|null
	 */
	private static function validated_configuration(): ?array {
		$config = array(
			'host'       => defined( 'AKIBARA_HOSTINGER_SMTP_HOST' ) ? constant( 'AKIBARA_HOSTINGER_SMTP_HOST' ) : self::DEFAULT_HOST,
			'port'       => defined( 'AKIBARA_HOSTINGER_SMTP_PORT' ) ? constant( 'AKIBARA_HOSTINGER_SMTP_PORT' ) : self::DEFAULT_PORT,
			'encryption' => defined( 'AKIBARA_HOSTINGER_SMTP_ENCRYPTION' ) ? constant( 'AKIBARA_HOSTINGER_SMTP_ENCRYPTION' ) : self::DEFAULT_ENCRYPTION,
			'username'   => defined( 'AKIBARA_HOSTINGER_SMTP_USERNAME' ) ? constant( 'AKIBARA_HOSTINGER_SMTP_USERNAME' ) : '',
			'password'   => defined( 'AKIBARA_HOSTINGER_SMTP_PASSWORD' ) ? constant( 'AKIBARA_HOSTINGER_SMTP_PASSWORD' ) : '',
		);
		$environment = self::environment();

		// La rama permisiva de `validate_configuration()` es para clones; en el
		// dominio de la tienda con otro entorno no hay perfil válido, y así el
		// gate del despliegue (`profile_is_valid()`) tampoco da verde.
		if ( '' !== self::environment_mismatch( $environment ) ) {
			return null;
		}

		return self::validate_configuration( $config, $environment );
	}

	/**
	 * Entorno efectivo. Cualquier entorno desconocido falla la validación.
	 */
	private static function environment(): string {
		return function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';
	}
}

Transport::register();
