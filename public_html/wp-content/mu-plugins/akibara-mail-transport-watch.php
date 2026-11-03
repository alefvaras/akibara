<?php
/**
 * Plugin Name: Akibara — Centinela del transporte de correo
 * Description: Vigila que el transporte SMTP siga instalado, COMPRUEBA la copia antes de reponerla, deja constancia de que pasó —un aviso que se puede cerrar y que vuelve si vuelve a ocurrir— y avisa si la cola de fondo dejó de correr. No contiene credenciales.
 * Version: 1.2.1
 * Author: Akibara
 *
 * @package Akibara\MuPlugins
 */

declare( strict_types = 1 );

namespace Akibara\MailTransportWatch;

defined( 'ABSPATH' ) || exit;

/**
 * Vigila el transporte desde un archivo independiente. Usa admin_init y el cron
 * existente para comprobarlo y reponerlo desde una copia validada fuera del
 * docroot. Conserva historial acotado de reposiciones, acuse del aviso y latido
 * de la cola de fondo. No contiene credenciales.
 */
final class Watch {

	/** Option NO autoload con el estado del centinela. */
	public const OPTION_STATE = 'akibara_mail_transport_watch';

	/** Archivo vigilado, dentro de mu-plugins. */
	private const TARGET = 'akibara-correo-smtp.php';

	/** Clase que ese archivo debe declarar. */
	private const TRANSPORT_CLASS = 'Akibara\\HostingerMail\\Transport';

	/** Directorio hermano del docroot donde vive la copia de rescate. */
	private const BACKUP_DIR = 'akb-runtime';

	/** Cuántas reposiciones se conservan en el historial. */
	private const HISTORY_LIMIT = 10;

	/** Cuántos contextos de detección se conservan. */
	private const CONTEXT_LIMIT = 10;

	/**
	 * Archivos vecinos cuyas marcas de tiempo se incluyen en el contexto de
	 * detección para diagnosticar cambios en mu-plugins.
	 *
	 * @var array<int, string>
	 */
	private const NEIGHBOURS = array( 'hostinger-auto-updates.php', 'hostinger-preview-domain.php' );

	/**
	 * Marcas que la copia de rescate debe llevar para que valga reponerla.
	 *
	 * No es una firma criptográfica y no pretende serlo: es la diferencia entre
	 * «esto es el transporte» y «esto es un archivo truncado, vacío, o el
	 * contenido equivocado». Las tres juntas no aparecen por casualidad.
	 */
	private const BACKUP_MARKERS = array(
		'namespace Akibara\\HostingerMail;',
		'class Transport',
		'phpmailer_init',
	);

	/**
	 * Cuánto puede pasar sin que corra el cron antes de considerarlo caído.
	 *
	 * El cron de hPanel dispara `wp-cron.php` cada 5 minutos. Media hora son seis
	 * disparos perdidos: ya no es un retraso, es que no está corriendo.
	 */
	private const CRON_STALE_SECONDS = 1800;

	/** Parámetro con que el aviso del historial se da por visto. */
	private const DISMISS_ARG = 'akibara_mail_watch_ack';

	/** Ya se inspeccionó en esta petición. */
	private static bool $inspected = false;

	/**
	 * Cuelga la vigilancia de latidos que YA existen.
	 */
	public static function register(): void {
		// El escritorio: barato (dos comprobaciones en memoria) y es donde el
		// dueño puede ver el aviso.
		add_action( 'admin_init', array( self::class, 'inspect' ), 1 );

		// Acusar recibo del aviso del historial. Antes que `inspect()` a
		// propósito: así el aviso que se acaba de cerrar no se vuelve a pintar en
		// la misma carga.
		add_action( 'admin_init', array( self::class, 'handle_dismiss' ), 0 );

		// El wp-cron: DISABLE_WP_CRON está en true y un cron de hPanel dispara
		// wp-cron.php cada 5 minutos. Engancharse ahí NO crea un cron nuevo, y es
		// lo único que se entera si el dueño pasa tres días sin abrir el admin.
		//
		// Se mira la CONSTANTE y no `wp_doing_cron()` a propósito. Esto corre al
		// cargar un mu-plugin, el momento más frágil del arranque, y un
		// `function_exists()` no es la salvaguarda que parece: bajo Brain Monkey
		// da true si otro test mockeó la función antes, y la llamada revienta.
		// Pasó con `is_admin()` en el transporte y volvió a pasar acá. `wp-cron.php`
		// define DOING_CRON antes de cargar WordPress, así que la constante ya
		// está puesta cuando esto se ejecuta.
		if ( self::is_cron() ) {
			add_action( 'init', array( self::class, 'inspect' ), 1 );
		}

		add_action( 'admin_notices', array( self::class, 'render_notice' ) );
	}

	/**
	 * ¿Esta petición es la del cron?
	 */
	private static function is_cron(): bool {
		return defined( 'DOING_CRON' ) && (bool) constant( 'DOING_CRON' );
	}

	/**
	 * ¿Quien hace esta petición puede administrar la tienda?
	 *
	 * La misma capacidad con la que se pinta el aviso: quien puede enterarse del
	 * corte es quien puede dispararle la reparación.
	 */
	private static function can_repair(): bool {
		return function_exists( 'current_user_can' ) && current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Una inspección por petición conserva el desenlace de la reposición. La clase
	 * del transporte sólo podrá cargarse en la siguiente petición; inspeccionarlo
	 * otra vez clasificaría como inerte el archivo que se acaba de reponer.
	 */
	public static function inspect(): void {
		if ( self::$inspected ) {
			return;
		}
		self::$inspected = true;

		// `admin_init` NO significa «el dueño entró al escritorio»: se dispara
		// para CUALQUIER usuario autenticado que toque /wp-admin/ —una clienta
		// que va a sus pedidos y acaba en profile.php— y también en
		// admin-post.php. Hasta 1.0.0 eso bastaba para que su petición copiara un
		// archivo dentro de mu-plugins. Reponer sigue siendo automático, pero lo
		// hace el cron —que no tiene usuario y corre cada 5 minutos— o alguien que
		// de verdad administra la tienda. Como mucho, la reparación espera un
		// ciclo de cron; a cambio, la escritura en disco deja de colgar de la
		// visita de un cliente.
		if ( ! self::is_cron() && ! self::can_repair() ) {
			return;
		}

		// El latido se anota SIEMPRE que la petición venga del cron, esté el
		// transporte vivo o no: es la prueba de que la cola de fondo corrió, y sin
		// ella este centinela puede llevar días dormido sin que nadie lo note.
		if ( self::is_cron() ) {
			self::record_cron_heartbeat();
		}

		if ( self::transport_is_live() ) {
			self::clear_outage();

			return;
		}

		self::record_outage( self::restore() );
	}

	/**
	 * Consulta Transport::is_live() como autoridad de transporte cargado y
	 * enganchado. Si la clase no existe, informa ausencia; conserva un respaldo
	 * para versiones antiguas que no publican el predicado.
	 */
	private static function transport_is_live(): bool {
		if ( ! class_exists( self::TRANSPORT_CLASS, false ) ) {
			return false;
		}

		if ( method_exists( self::TRANSPORT_CLASS, 'is_live' ) ) {
			return (bool) call_user_func( array( self::TRANSPORT_CLASS, 'is_live' ) );
		}

		// `false !== has_action()` no es cosmética: has_action() devuelve la
		// PRIORIDAD del callback, y una prioridad 0 es falsy — con una
		// comprobación por verdad simple el centinela daría por muerto un
		// transporte sano.
		return false !== has_action(
			'phpmailer_init',
			array( self::TRANSPORT_CLASS, 'configure_phpmailer' )
		);
	}

	/**
	 * Decide qué hacer, sin tocar el disco.
	 *
	 * La decisión va separada de la copia a propósito: es la parte con reglas
	 * —y por tanto la que puede equivocarse— y así se prueba entera sin
	 * simular un borrado real en producción ni escribir nada en las pruebas.
	 *
	 * @param bool $target_exists   ¿Está el archivo en mu-plugins?
	 * @param bool $backup_readable ¿Hay copia de rescate legible?
	 * @return string 'presente-pero-inerte' | 'sin-copia' | 'copiar'
	 */
	public static function restore_plan( bool $target_exists, bool $backup_readable ): string {
		if ( $target_exists ) {
			// El archivo está pero la clase no cargó. Copiar encima no arregla la
			// causa —un fatal dentro del archivo, permisos, opcache— y podría
			// pisar una versión MÁS NUEVA que la copia. Justo así nació el
			// downgrade silencioso del cron viejo, que reponía la 1.0.0 sobre la
			// 1.1.0 sin decírselo a nadie. Mejor gritar y que lo mire una persona.
			return 'presente-pero-inerte';
		}

		return $backup_readable ? 'copiar' : 'sin-copia';
	}

	/**
	 * ¿El contenido de la copia de rescate es de verdad el transporte?
	 *
	 * Pura a propósito: el defecto que cierra —reponer a ciegas y prometer que el
	 * correo volverá a salir— se prueba entero sin escribir un archivo.
	 *
	 * @param string $contents Contenido de la copia.
	 */
	public static function backup_is_valid( string $contents ): bool {
		foreach ( self::BACKUP_MARKERS as $marca ) {
			if ( ! str_contains( $contents, $marca ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Ejecuta el plan.
	 *
	 * @return string Desenlace, para contarlo en el aviso.
	 */
	private static function restore(): string {
		$target = self::target_path();
		$source = self::backup_path();

		$plan = self::restore_plan(
			'' !== $target && file_exists( $target ),
			'' !== $source && is_readable( $source )
		);

		if ( 'copiar' !== $plan ) {
			return $plan;
		}

		// Valida la copia antes de reponerla; una copia inválida conserva la alarma.
		$contents = @file_get_contents( $source );
		if ( ! is_string( $contents ) || ! self::backup_is_valid( $contents ) ) {
			return 'copia-invalida';
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- reponer es best effort: si falla, el aviso lo dice.
		if ( ! @copy( $source, $target ) ) {
			return 'fallo-al-reponer';
		}

		// Y comprobar que lo que quedó escrito es lo que se quiso escribir: con el
		// disco lleno, `copy()` puede devolver true dejando el archivo a medias, y
		// un transporte a medias es un fatal en cada correo.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- misma razón que arriba.
		$escrito = @file_get_contents( $target );

		return is_string( $escrito ) && $escrito === $contents ? 'repuesto' : 'fallo-al-reponer';
	}

	/**
	 * Ruta del archivo vigilado.
	 */
	private static function target_path(): string {
		if ( ! defined( 'WPMU_PLUGIN_DIR' ) ) {
			return '';
		}

		return rtrim( (string) constant( 'WPMU_PLUGIN_DIR' ), '/' ) . '/' . self::TARGET;
	}

	/**
	 * Ruta de la copia de rescate: un hermano del docroot, nunca servible por web.
	 *
	 * Se deriva de ABSPATH a propósito, para no depender de $HOME ni de una
	 * constante que alguien tenga que acordarse de definir.
	 */
	private static function backup_path(): string {
		if ( ! defined( 'ABSPATH' ) ) {
			return '';
		}

		$parent = dirname( rtrim( (string) constant( 'ABSPATH' ), '/' ) );
		if ( '' === $parent || '.' === $parent ) {
			return '';
		}

		return $parent . '/' . self::BACKUP_DIR . '/' . self::TARGET;
	}

	/**
	 * Anota que el transporte no está, y cómo fue el intento de reponerlo.
	 *
	 * @param string $outcome Desenlace devuelto por restore().
	 */
	private static function record_outage( string $outcome ): void {
		$state = self::state();
		$now   = gmdate( 'Y-m-d H:i:s' );

		// Una foto por EPISODIO, no una por comprobación: mientras el transporte
		// siga faltando, esto corre en cada tick del cron y en cada página del
		// admin, y una lista con una fila cada cinco minutos no es evidencia, es
		// ruido. Se guarda al detectarlo y si el desenlace cambia.
		$nuevo = ! isset( $state['missing_since'] ) || $outcome !== ( $state['outcome'] ?? '' );
		if ( $nuevo ) {
			$state['contextos'] = self::append_context(
				isset( $state['contextos'] ) && is_array( $state['contextos'] ) ? $state['contextos'] : array(),
				self::detection_context( $outcome, $now )
			);
		}

		$state['missing_since'] = (string) ( $state['missing_since'] ?? $now );
		$state['last_seen_gmt'] = $now;
		$state['outcome']       = $outcome;

		if ( 'repuesto' === $outcome ) {
			// El historial es el punto entero de esta pieza: sobrevive a que el
			// problema se cure, que es justo lo que el cron viejo no hacía.
			$state['restores'] = self::append_restore(
				isset( $state['restores'] ) && is_array( $state['restores'] ) ? $state['restores'] : array(),
				$now
			);
		}

		self::save( $state );
	}

	/**
	 * Registra la última ejecución de la cola de fondo para detectar un cron detenido.
	 */
	private static function record_cron_heartbeat(): void {
		$state                  = self::state();
		$state['last_cron_gmt'] = gmdate( 'Y-m-d H:i:s' );

		self::save( $state );
	}

	/**
	 * Añade una reposición al historial, acotado para que la option no crezca sin fin.
	 *
	 * @param array<int, string> $history Historial previo.
	 * @param string             $when    Momento de la reposición, en GMT.
	 * @return array<int, string>
	 */
	public static function append_restore( array $history, string $when ): array {
		$history[] = $when;

		if ( count( $history ) > self::HISTORY_LIMIT ) {
			// Se conservan las ÚLTIMAS: si esto se dispara a diario, lo que
			// importa es el ritmo reciente, no la primera vez de hace meses.
			$history = array_values( array_slice( $history, -self::HISTORY_LIMIT ) );
		}

		return $history;
	}

	/**
	 * Qué se sabía en el instante en que se detectó la ausencia.
	 *
	 * Es información que **sólo existe en ese instante** y que el incidente
	 * abierto de los domingos necesita: hasta 1.0.0 el centinela no anotaba ni la
	 * hora del directorio, ni el tamaño de lo que repuso, ni desde dónde lo vio,
	 * así que cada episodio obligaba a entrar por SSH a reconstruirlo —y lo que
	 * se mira son mtimes, que el episodio siguiente pisa—.
	 *
	 * No identifica a nadie ni lo pretende: son mecanismo y cronología, que es lo
	 * que se puede afirmar con esto.
	 *
	 * @param string $outcome Desenlace del intento de reposición.
	 * @param string $now     Momento de la detección, en GMT.
	 * @return array<string, mixed>
	 */
	private static function detection_context( string $outcome, string $now ): array {
		$contexto = array(
			'gmt'       => $now,
			'origen'    => self::is_cron() ? 'cron' : 'admin',
			'desenlace' => $outcome,
		);

		$dir = defined( 'WPMU_PLUGIN_DIR' ) ? rtrim( (string) constant( 'WPMU_PLUGIN_DIR' ), '/' ) : '';
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return $contexto;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- leer un mtime es best effort: si falla, el contexto sale más pobre y ya.
		$mtime = @filemtime( $dir );
		if ( is_int( $mtime ) ) {
			$contexto['dir_mtime_gmt'] = gmdate( 'Y-m-d H:i:s', $mtime );
		}

		foreach ( self::NEIGHBOURS as $vecino ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- ídem.
			$stamp = @filemtime( $dir . '/' . $vecino );
			if ( is_int( $stamp ) ) {
				$contexto['vecinos'][ $vecino ] = gmdate( 'Y-m-d H:i:s', $stamp );
			}
		}

		$target = self::target_path();
		if ( '' !== $target && file_exists( $target ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- ídem.
			$bytes = @filesize( $target );
			if ( is_int( $bytes ) ) {
				$contexto['bytes'] = $bytes;
			}
		}

		return $contexto;
	}

	/**
	 * Añade un contexto de detección, acotado.
	 *
	 * @param array<int, array<string, mixed>> $lista    Contextos previos.
	 * @param array<string, mixed>             $contexto Contexto nuevo.
	 * @return array<int, array<string, mixed>>
	 */
	public static function append_context( array $lista, array $contexto ): array {
		$lista[] = $contexto;

		if ( count( $lista ) > self::CONTEXT_LIMIT ) {
			$lista = array_values( array_slice( $lista, -self::CONTEXT_LIMIT ) );
		}

		return $lista;
	}

	/**
	 * Funde dos listas de contextos sin repetir ninguno.
	 *
	 * Misma razón que el historial: dos inspecciones simultáneas no pueden
	 * borrarse la evidencia entre ellas. La identidad de un contexto es su hora.
	 *
	 * @param array<int, array<string, mixed>> $vivo   Contextos que hay en la base.
	 * @param array<int, array<string, mixed>> $propio Contextos de esta petición.
	 * @return array<int, array<string, mixed>>
	 */
	public static function merge_contexts( array $vivo, array $propio ): array {
		$por_hora = array();
		foreach ( array_merge( $vivo, $propio ) as $contexto ) {
			if ( ! is_array( $contexto ) || ! isset( $contexto['gmt'] ) ) {
				continue;
			}
			$por_hora[ (string) $contexto['gmt'] ] = $contexto;
		}

		ksort( $por_hora );
		$fundido = array_values( $por_hora );

		if ( count( $fundido ) > self::CONTEXT_LIMIT ) {
			$fundido = array_values( array_slice( $fundido, -self::CONTEXT_LIMIT ) );
		}

		return $fundido;
	}

	/**
	 * Une, ordena y acota las fechas de reposición de ambas lecturas.
	 * La persistencia debe proteger la secuencia de lectura, unión y escritura.
	 *
	 * @param array<int, string> $vivo    Historial que hay en la base ahora.
	 * @param array<int, string> $propio  Historial de esta petición.
	 * @return array<int, string>
	 */
	public static function merge_restores( array $vivo, array $propio ): array {
		$fechas = array_values(
			array_unique(
				array_map( 'strval', array_merge( $vivo, $propio ) )
			)
		);
		sort( $fechas );

		if ( count( $fechas ) > self::HISTORY_LIMIT ) {
			$fechas = array_values( array_slice( $fechas, -self::HISTORY_LIMIT ) );
		}

		return $fechas;
	}

	/**
	 * El transporte volvió: se borra el corte, se CONSERVA el historial.
	 */
	private static function clear_outage(): void {
		$state = self::state();
		if ( array() === $state ) {
			return;
		}
		if ( ! isset( $state['missing_since'] ) ) {
			return;
		}

		unset( $state['missing_since'], $state['last_seen_gmt'], $state['outcome'] );
		self::save( $state );
	}

	/**
	 * Lee el estado del centinela.
	 *
	 * @return array<string, mixed>
	 */
	private static function state(): array {
		if ( ! function_exists( 'get_option' ) ) {
			return array();
		}
		$state = get_option( self::OPTION_STATE, array() );

		return is_array( $state ) ? $state : array();
	}

	/**
	 * Serializa la lectura, unión y escritura del estado entre peticiones.
	 *
	 * Un bloqueo ocupado o un fallo de persistencia deja el estado previo y se
	 * registra como fallo; el acuse del aviso no se presenta como guardado.
	 *
	 * @param array<string, mixed> $state Estado a persistir.
	 * @return bool Si el estado solicitado quedó persistido.
	 */
	private static function save( array $state ): bool {
		global $wpdb;

		if ( ! is_object( $wpdb ) || ! isset( $wpdb->options )
			|| ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return self::save_failure( 'base de datos no disponible' );
		}

		$database = defined( 'DB_NAME' ) ? (string) DB_NAME : '';
		$lock = 'akb_mail_watch_' . md5( $database . '|' . (string) $wpdb->options );
		$acquired = false;
		try {
			$acquired = 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, 2 ) );
			if ( ! $acquired ) {
				return self::save_failure( 'candado ocupado o no disponible' );
			}

			self::clear_state_cache();
			return self::save_locked( $state );
		} catch ( \Throwable $error ) {
			return self::save_failure( 'error al persistir el estado' );
		} finally {
			if ( $acquired ) {
				try {
					if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ) ) {
						// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- no modifica el resultado de la escritura ya verificada.
						error_log( '[akibara-mail-watch] No se pudo liberar el candado de estado.' );
					}
				} catch ( \Throwable $error ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- no modifica el resultado de la escritura ya verificada.
					error_log( '[akibara-mail-watch] Error al liberar el candado de estado.' );
				}
			}
		}
	}

	/** Invalida también las cachés de existencia y autoload de WordPress. */
	private static function clear_state_cache(): void {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( self::OPTION_STATE, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			wp_cache_delete( 'alloptions', 'options' );
		}
	}

	/** Registra el fallo sin destinatarios, credenciales ni detalles de SQL. */
	private static function save_failure( string $reason ): bool {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- la persistencia del vigía no puede fallar en silencio.
		error_log( '[akibara-mail-watch] Estado no guardado: ' . $reason . '.' );
		return false;
	}

	/**
	 * Guarda sin autoload bajo el candado y verifica la lectura persistida.
	 *
	 * @param array<string, mixed> $state Estado a persistir.
	 * @return bool Si el estado quedó guardado o ya era idéntico.
	 */
	private static function save_locked( array $state ): bool {
		if ( ! function_exists( 'update_option' ) || ! function_exists( 'delete_option' ) ) {
			return self::save_failure( 'API de opciones no disponible' );
		}

		// Releer y fundir JUSTO antes de escribir: entre la lectura de arriba y
		// esta línea pudo correr otra petición. Lo que se funde es lo que no se
		// puede perder —el historial, el acuse del aviso y el latido del cron—;
		// el corte vigente sí lo manda esta petición, que es la que acaba de
		// mirar el archivo.
		$vivo = self::state();
		if ( array() !== $vivo ) {
			$state['restores'] = self::merge_restores(
				isset( $vivo['restores'] ) && is_array( $vivo['restores'] ) ? $vivo['restores'] : array(),
				isset( $state['restores'] ) && is_array( $state['restores'] ) ? $state['restores'] : array()
			);
			if ( array() === $state['restores'] ) {
				unset( $state['restores'] );
			}

			$state['contextos'] = self::merge_contexts(
				isset( $vivo['contextos'] ) && is_array( $vivo['contextos'] ) ? $vivo['contextos'] : array(),
				isset( $state['contextos'] ) && is_array( $state['contextos'] ) ? $state['contextos'] : array()
			);
			if ( array() === $state['contextos'] ) {
				unset( $state['contextos'] );
			}

			foreach ( array( 'history_ack', 'last_cron_gmt' ) as $conservable ) {
				if ( isset( $vivo[ $conservable ] )
					&& ( ! isset( $state[ $conservable ] ) || (string) $vivo[ $conservable ] > (string) $state[ $conservable ] ) ) {
					$state[ $conservable ] = $vivo[ $conservable ];
				}
			}
		}

		if ( array() === $state ) {
			delete_option( self::OPTION_STATE );
			self::clear_state_cache();
			return null === get_option( self::OPTION_STATE, null )
				? true : self::save_failure( 'el borrado no persistió' );
		}

		update_option( self::OPTION_STATE, $state, false );
		self::clear_state_cache();
		return $state === self::state()
			? true : self::save_failure( 'la escritura no persistió' );
	}

	/**
	 * Persiste la fecha de la última reposición vista. El historial tiene tamaño
	 * acotado; un conteo no detecta reposiciones nuevas cuando alcanza el tope.
	 */
	public static function handle_dismiss(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- el nonce se verifica tres líneas más abajo.
		if ( ! isset( $_GET[ self::DISMISS_ARG ] ) ) {
			return;
		}
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		if ( ! function_exists( 'wp_verify_nonce' ) || ! function_exists( 'sanitize_text_field' ) || ! function_exists( 'wp_unslash' ) ) {
			return;
		}

		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::DISMISS_ARG ) ) {
			return;
		}

		$state = self::state();
		if ( array() === $state ) {
			return;
		}

		$history = isset( $state['restores'] ) && is_array( $state['restores'] ) ? $state['restores'] : array();
		if ( array() !== $history ) {
			// max() en vez de end(): $history ya viene ordenado por append_restore()/
			// merge_restores(), pero no dependemos de ese orden para el acuse.
			$state['history_ack'] = (string) max( $history );
		}
		if ( ! self::save( $state ) ) {
			return;
		}

		if ( function_exists( 'wp_safe_redirect' ) && function_exists( 'remove_query_arg' ) ) {
			wp_safe_redirect( (string) remove_query_arg( array( self::DISMISS_ARG, '_wpnonce' ) ) );
			exit;
		}
	}

	/**
	 * Avisos del centinela.
	 *
	 * Son tres y distintos a propósito: uno mientras el transporte no está (rojo,
	 * no descartable, el correo está caído AHORA), otro que recuerda que el
	 * archivo ya desapareció alguna vez aunque hoy esté todo bien, y un tercero
	 * cuando la cola de fondo —de la que depende esta vigilancia— lleva media
	 * hora sin dar señales.
	 */
	public static function render_notice(): void {
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$state = self::state();
		if ( array() === $state ) {
			return;
		}

		self::render_cron_notice( $state );

		if ( ! empty( $state['missing_since'] ) ) {
			self::render_missing_notice( $state );

			return;
		}

		self::render_history_notice( $state );
	}

	/**
	 * Aviso mientras el transporte no está cargado.
	 *
	 * @param array<string, mixed> $state Estado del centinela.
	 */
	private static function render_missing_notice( array $state ): void {
		$outcome = (string) ( $state['outcome'] ?? '' );

		$explicacion = array(
			'repuesto'             => __( 'Lo repuse desde la copia de rescate —comprobando antes que la copia fuera de verdad el transporte— y el correo debería volver a salir en la próxima petición. Aun así, algo lo borró: revisa hPanel → Seguridad → Malware scanner.', 'akibara' ),
			'sin-copia'            => __( 'No hay copia de rescate para reponerlo. Hay que subirlo a mano por SSH desde el repositorio.', 'akibara' ),
			'copia-invalida'       => __( 'Hay copia de rescate, pero NO es el transporte: está truncada, vacía o es otro archivo. No la copié, porque dejaría el correo igual de caído con la apariencia de arreglado. Hay que subir el archivo a mano por SSH y volver a generar la copia con el despliegue de mu-plugins.', 'akibara' ),
			'fallo-al-reponer'     => __( 'Intenté reponerlo desde la copia de rescate y no quedó escrito completo (permisos de mu-plugins, o disco lleno). Hay que subirlo a mano por SSH.', 'akibara' ),
			'presente-pero-inerte' => __( 'El archivo está en su sitio pero no llegó a cargarse: puede ser un error de PHP dentro del archivo o un problema de permisos. No lo pisé para no tapar la causa.', 'akibara' ),
		);

		printf(
			'<div class="notice notice-error"><p><strong>%s</strong></p><p>%s</p><p>%s</p></div>',
			esc_html__( 'Falta el transporte de correo de Akibara', 'akibara' ),
			esc_html(
				sprintf(
					/* translators: %s: fecha en que se detectó la ausencia. */
					__( 'El mu-plugin que hace salir todos los correos de la tienda no está cargado desde el %s. Sin él no salen confirmaciones de pedido, altas de cuenta ni recuperaciones de contraseña.', 'akibara' ),
					self::local_date( (string) $state['missing_since'] )
				)
			),
			esc_html( $explicacion[ $outcome ] ?? __( 'Estado desconocido.', 'akibara' ) )
		);
	}

	/**
	 * Aviso de que el archivo ya desapareció antes, aunque hoy esté bien.
	 *
	 * @param array<string, mixed> $state Estado del centinela.
	 */
	private static function render_history_notice( array $state ): void {
		$history = isset( $state['restores'] ) && is_array( $state['restores'] ) ? $state['restores'] : array();
		if ( array() === $history ) {
			return;
		}

		$total = count( $history );
		$last  = (string) max( $history );
		$visto = isset( $state['history_ack'] ) ? (string) $state['history_ack'] : '';

		// Compara la última fecha de reposición con el acuse; el historial tiene tamaño acotado.
		if ( '' !== $visto && ! ctype_digit( $visto ) && $last <= $visto ) {
			// Ya se dio por visto y no ha vuelto a pasar desde esa fecha.
			return;
		}

		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong></p><p>%s</p>%s</div>',
			esc_html__( 'El transporte de correo desapareció y se repuso solo', 'akibara' ),
			esc_html(
				sprintf(
					/* translators: 1: cantidad de reposiciones. 2: fecha de la última. */
					_n(
						'Ocurrió %1$s vez, la última el %2$s. El correo está saliendo ahora, pero algo está borrando el archivo: míralo en hPanel → Seguridad → Malware scanner antes de que vuelva a pasar.',
						'Ocurrió %1$s veces, la última el %2$s. El correo está saliendo ahora, pero algo está borrando el archivo: míralo en hPanel → Seguridad → Malware scanner antes de que vuelva a pasar.',
						$total,
						'akibara'
					),
					number_format_i18n( $total ),
					self::local_date( $last )
				)
			),
			self::dismiss_link() // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ya sale escapado de dismiss_link().
		);
	}

	/**
	 * Enlace para dar por visto el aviso del historial.
	 *
	 * Devuelve cadena vacía si falta cualquiera de las funciones que necesita: el
	 * aviso sin enlace sigue siendo útil, y este archivo se carga en el momento
	 * más frágil del arranque.
	 */
	private static function dismiss_link(): string {
		if ( ! function_exists( 'wp_create_nonce' ) || ! function_exists( 'add_query_arg' ) || ! function_exists( 'esc_url' ) ) {
			return '';
		}

		$url = add_query_arg(
			array(
				self::DISMISS_ARG => '1',
				'_wpnonce'        => wp_create_nonce( self::DISMISS_ARG ),
			)
		);

		return sprintf(
			'<p><a href="%s">%s</a></p>',
			esc_url( (string) $url ),
			esc_html__( 'Ya lo vi, no me lo muestres más (volverá si ocurre otra vez)', 'akibara' )
		);
	}

	/**
	 * Avisa si la cola de fondo lleva media hora sin señales. Sólo se muestra en
	 * producción; el espejo puede mantener el cron detenido deliberadamente.
	 *
	 * @param array<string, mixed> $state Estado del centinela.
	 */
	private static function render_cron_notice( array $state ): void {
		if ( ! function_exists( 'wp_get_environment_type' ) || 'production' !== wp_get_environment_type() ) {
			return;
		}

		$ultimo = (string) ( $state['last_cron_gmt'] ?? '' );
		if ( '' === $ultimo ) {
			// Sin un latido previo no hay nada que comparar: puede ser un sitio
			// recién desplegado. El primer cron que corra deja la referencia.
			return;
		}

		$stamp = strtotime( $ultimo . ' UTC' );
		if ( false === $stamp || ( time() - $stamp ) < self::CRON_STALE_SECONDS ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p><strong>%s</strong></p><p>%s</p></div>',
			esc_html__( 'Las tareas de fondo de Akibara no están corriendo', 'akibara' ),
			esc_html(
				sprintf(
					/* translators: %s: fecha del último latido de la cola de fondo. */
					__( 'La última vez que corrió la cola de fondo fue el %s, y debería correr cada 5 minutos. Mientras siga así no se vigila el correo, no vencen los puntos, no salen los avisos de stock ni se reindexa el buscador: revisa el cron de hPanel.', 'akibara' ),
					self::local_date( $ultimo )
				)
			)
		);
	}

	/**
	 * Formatea una fecha GMT en el idioma y la zona del sitio.
	 *
	 * `get_date_from_gmt()` formatea con gmdate() y devolvería «23 de August» en
	 * un sitio en español; `wp_date()` sí pasa por el catálogo de idioma y por
	 * America/Santiago.
	 *
	 * @param string $gmt Fecha en 'Y-m-d H:i:s' UTC.
	 */
	private static function local_date( string $gmt ): string {
		$stamp = strtotime( $gmt . ' UTC' );
		if ( false === $stamp || ! function_exists( 'wp_date' ) ) {
			return $gmt;
		}

		return (string) wp_date( 'j \d\e F, H:i', $stamp );
	}
}

Watch::register();
