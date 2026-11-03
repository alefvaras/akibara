<?php
/**
 * Calienta las fichas en LiteSpeed mediante una pasada nocturna acotada.
 *
 * Empieza a las00:20 de la tienda, después del reordenado de vitrina, y termina
 * su ventana a las06:00. Las corridas trabajan con presupuesto y continuación,
 * priorizando productos vendibles por ventas y dejando agotados al final.
 * Móvil y escritorio comparten la copia; Accept admite los formatos de portada.
 *
 * Las peticiones van al origen127.0.0.1 con hostname y verificación TLS del sitio,
 * sin pasar por Cloudflare. Si cURL no puede usar esa ruta, se falla en lugar
 * de convertir el calentamiento en tráfico al borde. Una respuesta lenta,
 * un5xx o falta de respuesta detiene la corrida para dejar recursos a clientes.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\SiteSpeed;

use Akibara\Business\Modules\Stock\StockStatus;
use Akibara\Business\Utilities\OptionRow;
use Akibara\Business\Utilities\StoreTime;
use DateTimeImmutable;
use Throwable;

defined( 'ABSPATH' ) || exit;

final class CacheWarmer {

	public const HOOK = 'akibara_business_cache_warm';

	public const GROUP = 'akb-cache-warm';

	/** `no` apaga el recalentado; la fila ausente lo deja encendido. */
	public const OPTION_ENABLED = 'akb_cache_warm_enabled';

	/** Estado de la última pasada (sin autoload: sólo lo leen el panel y la cola). */
	public const OPTION_STATE = 'akb_cache_warm_state';

	/** Ids de la pasada abierta, en el orden en que se piden. */
	public const OPTION_QUEUE = 'akb_cache_warm_queue';

	/** Candado: `marca de tiempo|token`, escrito sólo con SQL atómico. */
	public const LOCK_OPTION = 'akb_cache_warm_lock';

	/** Ventana de la pasada nocturna, en hora de la tienda. */
	public const START_HOUR   = 0;
	public const START_MINUTE = 20;
	public const END_HOUR     = 6;

	/** Segundos de trabajo por corrida de la cola. */
	private const BUDGET_SECONDS = 20;

	/** Espera entre corridas de una misma pasada. */
	private const CONTINUE_DELAY = 60;

	/** Un candado sin soltar en este tiempo es de una corrida muerta. */
	private const LOCK_TTL = 300;

	/**
	 * Tope por petición.
	 *
	 * El presupuesto se mira al EMPEZAR cada tanda, así que el peor caso de una
	 * corrida es BUDGET_SECONDS + TIMEOUT = 30 s, dentro del límite de 30 s por
	 * lote de Action Scheduler. Una ficha fría tarda 0,5–1,3 s en el origen.
	 */
	private const TIMEOUT = 10;

	/** Una petición más lenta que esto corta la corrida: el servidor está ocupado. */
	private const SLOW_SECONDS = 8.0;

	/** Prioridad en Action Scheduler: detrás del buscador y de la preventa (10). */
	private const PRIORITY = 20;

	/** Fichas pedidas a la vez. */
	private const CONCURRENCY = 2;

	/** Navegador con el que se pide: se reconoce en los registros por `AkibaraCacheWarm`. */
	public const USER_AGENT = 'Mozilla/5.0 (Linux; Android 11) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Mobile Safari/537.36 AkibaraCacheWarm';

	/** Lo que manda un navegador real al abrir una página, formatos de imagen incluidos. */
	public const ACCEPT = 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8';

	/** @var array<string, int|string> */
	private const EMPTY_STATE = array(
		'started'      => 0,
		'finished'     => 0,
		'day'          => '',
		'manual'       => 0,
		'cursor'       => 0,
		'total'        => 0,
		'calentadas'   => 0,
		'ya_calientes' => 0,
		'sin_cache'    => 0,
		'errores'      => 0,
		'cortada'      => 0,
		'ultimo_error' => '',
	);

	/** @var callable(array<int, array{url: string, headers: array<string, string>}>, string): array<int, array<string, mixed>> */
	private $fetch;

	/** @var callable(): float */
	private $clock;

	private string $candado = '';

	/**
	 * @param callable|null $fetch Hace las peticiones (inyectable para las pruebas).
	 * @param callable|null $clock Reloj en segundos (inyectable para las pruebas).
	 */
	public function __construct( ?callable $fetch = null, ?callable $clock = null ) {
		$this->fetch = $fetch ?? array( self::class, 'fetch_from_origin' );
		$this->clock = $clock ?? static function (): float {
			return microtime( true );
		};
	}

	public function register(): void {
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( 'init', array( $this, 'maybe_schedule' ) );
		add_action( 'update_option_' . self::OPTION_ENABLED, array( self::class, 'on_toggle' ), 10, 2 );
		// La primera vez que se guarda la pestaña la fila no existe: WordPress la
		// crea con `add_option` y `update_option_*` no corre.
		add_action( 'add_option_' . self::OPTION_ENABLED, array( self::class, 'on_first_save' ), 10, 2 );
	}

	/** ¿Está encendido? La fila ausente cuenta como encendido. */
	public static function enabled(): bool {
		return 'no' !== get_option( self::OPTION_ENABLED, 'yes' );
	}

	/**
	 * Estado normalizado: siempre trae todas las claves con su tipo.
	 *
	 * @return array<string, int|string>
	 */
	public static function state(): array {
		$guardado = get_option( self::OPTION_STATE, array() );
		$estado   = self::EMPTY_STATE;
		if ( is_array( $guardado ) ) {
			foreach ( self::EMPTY_STATE as $clave => $vacio ) {
				if ( isset( $guardado[ $clave ] ) ) {
					$estado[ $clave ] = is_int( $vacio ) ? (int) $guardado[ $clave ] : (string) $guardado[ $clave ];
				}
			}
		}

		return $estado;
	}

	/**
	 * ¿Cae este instante dentro de la ventana nocturna de la tienda?
	 *
	 * @param int $timestamp Marca de tiempo.
	 */
	public static function in_window( int $timestamp ): bool {
		$local   = ( new DateTimeImmutable( '@' . $timestamp ) )->setTimezone( StoreTime::zone() );
		$minutos = (int) $local->format( 'G' ) * 60 + (int) $local->format( 'i' );

		return $minutos >= self::START_HOUR * 60 + self::START_MINUTE && $minutos < self::END_HOUR * 60;
	}

	/**
	 * Próximo comienzo de la ventana, estrictamente después de `$now`.
	 *
	 * Con el calendario de la zona y no con aritmética de desfases: Chile cambia la
	 * hora en septiembre y abril, y el desfase de hoy no vale para mañana.
	 *
	 * @param int $now Marca de tiempo actual.
	 */
	public static function next_window_start( int $now ): int {
		$local = ( new DateTimeImmutable( '@' . $now ) )->setTimezone( StoreTime::zone() );
		$hoy   = $local->setTime( self::START_HOUR, self::START_MINUTE );
		if ( $hoy->getTimestamp() > $now ) {
			return $hoy->getTimestamp();
		}

		return $local->modify( '+1 day' )->setTime( self::START_HOUR, self::START_MINUTE )->getTimestamp();
	}

	/**
	 * Deja agendada la próxima corrida si no hay ninguna.
	 *
	 * La consulta a la cola no se paga en el front: la hace cualquier petición de
	 * admin, de la cola o de WP-CLI, y ocurren varias veces al día.
	 *
	 * @internal Callback de `init`.
	 */
	public function maybe_schedule(): void {
		$backend = ( function_exists( 'is_admin' ) && is_admin() )
			|| ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() )
			|| ( defined( 'WP_CLI' ) && WP_CLI );
		if ( ! $backend || ! self::enabled() || ! function_exists( 'as_schedule_single_action' ) || self::has_pending() ) {
			return;
		}
		$ahora  = (int) ( $this->clock )();
		$estado = self::state();
		$abierta = (int) $estado['started'] > 0 && 0 === (int) $estado['finished'];
		self::schedule( $abierta ? $ahora + self::CONTINUE_DELAY : self::next_window_start( $ahora ) );
	}

	/**
	 * Apagar quita lo agendado; encender lo vuelve a agendar la próxima petición de admin.
	 *
	 * @internal Callback de `update_option_akb_cache_warm_enabled`.
	 *
	 * @param mixed $old_value Valor anterior.
	 * @param mixed $value     Valor nuevo.
	 */
	public static function on_toggle( $old_value, $value ): void {
		unset( $old_value );
		if ( 'no' === $value && function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, array(), self::GROUP );
		}
	}

	/**
	 * @internal Callback de `add_option_akb_cache_warm_enabled`.
	 *
	 * @param mixed $option Nombre de la option.
	 * @param mixed $value  Valor escrito.
	 */
	public static function on_first_save( $option, $value ): void {
		unset( $option );
		self::on_toggle( null, $value );
	}

	/**
	 * Empieza una pasada ahora, fuera de la ventana («Recalentar ahora» del panel).
	 *
	 * @return string `encolada`, `ya-en-curso`, `apagado`, `sin-cola` o `sin-fichas`.
	 */
	public function start_manual(): string {
		if ( ! self::enabled() ) {
			return 'apagado';
		}
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return 'sin-cola';
		}
		if ( ! $this->claim_lock() ) {
			return 'ya-en-curso';
		}
		try {
			$estado = self::fresh_state();
			if ( (int) $estado['started'] > 0 && 0 === (int) $estado['finished'] ) {
				return 'ya-en-curso';
			}
			$ahora = (int) ( $this->clock )();
			if ( null === $this->open_pass( $ahora, true ) ) {
				return 'sin-fichas';
			}
			self::schedule( $ahora );

			return 'encolada';
		} finally {
			$this->release_lock();
		}
	}

	/**
	 * Una corrida de la cola: abre la pasada de la noche o sigue la abierta.
	 *
	 * @internal Callback de `akibara_business_cache_warm`.
	 */
	public function run(): void {
		if ( ! self::enabled() || ! $this->claim_lock() ) {
			return;
		}

		try {
			$ahora   = (int) ( $this->clock )();
			$estado  = self::fresh_state();
			$abierta = (int) $estado['started'] > 0 && 0 === (int) $estado['finished'];

			if ( ! $abierta ) {
				if ( ! self::in_window( $ahora ) || StoreTime::today_ymd( $ahora ) === (string) $estado['day'] ) {
					self::schedule( self::next_window_start( $ahora ) );
					return;
				}
				$estado = $this->open_pass( $ahora, false );
				if ( null === $estado ) {
					self::schedule( self::next_window_start( $ahora ) );
					return;
				}
			}

			if ( 0 === (int) $estado['manual'] && ! self::in_window( $ahora ) ) {
				$estado['finished'] = $ahora;
				$estado['cortada']  = 1;
				self::close_pass( $estado );
				self::schedule( self::next_window_start( $ahora ) );
				return;
			}

			if ( $this->process_batch( $estado ) ) {
				update_option( self::OPTION_STATE, $estado, false );
				self::schedule( $ahora + self::CONTINUE_DELAY );
				return;
			}

			$cierre             = (int) ( $this->clock )();
			$estado['finished'] = $cierre;
			self::close_pass( $estado );
			self::schedule( self::next_run_after_close( $estado, $cierre ) );
		} finally {
			$this->release_lock();
		}
	}

	/**
	 * Tras cerrar una pasada: si ya es de noche y la pasada de hoy todavía no corrió
	 * (una manual que empezó antes de medianoche), la nocturna empieza ahora. Lo
	 * calentado antes de medianoche ya venció.
	 *
	 * @param array<string, int|string> $estado Estado de la pasada cerrada.
	 * @param int                       $ahora  Marca de tiempo del cierre.
	 */
	private static function next_run_after_close( array $estado, int $ahora ): int {
		if ( self::in_window( $ahora ) && StoreTime::today_ymd( $ahora ) !== (string) $estado['day'] ) {
			return $ahora + self::CONTINUE_DELAY;
		}

		return self::next_window_start( $ahora );
	}

	/**
	 * Pide las fichas desde el cursor, de a dos, hasta agotar el presupuesto.
	 *
	 * @param array<string, int|string> $estado Estado de la pasada; se actualiza.
	 * @return bool Si quedan fichas por pedir.
	 */
	private function process_batch( array &$estado ): bool {
		$cola = get_option( self::OPTION_QUEUE, array() );
		if ( ! is_array( $cola ) ) {
			return false;
		}
		$cola   = array_values( $cola );
		$limite = ( $this->clock )() + self::BUDGET_SECONDS;

		while ( ( $this->clock )() < $limite && (int) $estado['cursor'] < count( $cola ) ) {
			$peticiones = array();
			$ruta       = null;
			while ( count( $peticiones ) < self::CONCURRENCY && (int) $estado['cursor'] < count( $cola ) ) {
				$id               = (int) $cola[ (int) $estado['cursor'] ];
				$url              = $id > 0 ? get_permalink( $id ) : false;
				$estado['cursor'] = (int) $estado['cursor'] + 1;

				if ( ! is_string( $url ) || '' === $url ) {
					$estado['sin_cache'] = (int) $estado['sin_cache'] + 1;
					continue;
				}
				$ruta_ficha = self::connect_to( $url );
				if ( null === $ruta_ficha ) {
					$estado['errores']      = (int) $estado['errores'] + 1;
					$estado['ultimo_error'] = 'No hay ruta directa al servidor para ' . $url;
					continue;
				}
				// Todas las fichas son del mismo sitio: la ruta es la misma para la tanda.
				$ruta              = $ruta_ficha;
				$peticiones[ $id ] = self::request_for( $url );
			}
			if ( array() === $peticiones || null === $ruta ) {
				continue;
			}

			$inicio     = ( $this->clock )();
			$respuestas = ( $this->fetch )( $peticiones, $ruta );
			$duracion   = ( $this->clock )() - $inicio;
			$frenar     = $duracion > self::SLOW_SECONDS;

			foreach ( $peticiones as $id => $peticion ) {
				$respuesta = is_array( $respuestas[ $id ] ?? null ) ? $respuestas[ $id ] : array( 'error' => 'sin respuesta' );
				switch ( self::classify( $respuesta ) ) {
					case 'hit':
						$estado['ya_calientes'] = (int) $estado['ya_calientes'] + 1;
						break;
					case 'miss':
						$estado['calentadas'] = (int) $estado['calentadas'] + 1;
						break;
					case 'sin_cache':
						$estado['sin_cache'] = (int) $estado['sin_cache'] + 1;
						break;
					default:
						$estado['errores']      = (int) $estado['errores'] + 1;
						$estado['ultimo_error'] = self::error_text( $respuesta, $peticion['url'] );
						// Un 5xx o una petición sin respuesta es el servidor pidiendo aire.
						if ( ! isset( $respuesta['status'] ) || (int) $respuesta['status'] >= 500 ) {
							$frenar = true;
						}
				}
			}

			update_option( self::OPTION_STATE, $estado, false );
			if ( $frenar ) {
				break;
			}
		}

		return (int) $estado['cursor'] < count( $cola );
	}

	/**
	 * Qué pasó con una petición.
	 *
	 * - `hit`: LiteSpeed ya la tenía guardada.
	 * - `miss`: la construyó y la guardó ahora.
	 * - `sin_cache`: respondió, pero esa página no se guarda (redirección, no-cache).
	 * - `error`: no respondió o respondió con error.
	 *
	 * @param array<string, mixed> $respuesta `status`, `headers` (claves en minúscula) o `error`.
	 */
	public static function classify( array $respuesta ): string {
		if ( isset( $respuesta['error'] ) || ! isset( $respuesta['status'] ) ) {
			return 'error';
		}
		$status = (int) $respuesta['status'];
		if ( $status >= 400 || $status < 200 ) {
			return 'error';
		}
		if ( 200 !== $status ) {
			return 'sin_cache';
		}
		$cabeceras = is_array( $respuesta['headers'] ?? null ) ? $respuesta['headers'] : array();
		$estado    = strtolower( (string) ( $cabeceras['x-litespeed-cache'] ?? '' ) );
		if ( str_contains( $estado, 'hit' ) ) {
			return 'hit';
		}
		$control = strtolower( (string) ( $cabeceras['x-litespeed-cache-control'] ?? '' ) );
		if ( str_contains( $estado, 'miss' ) && ! str_contains( $control, 'no-cache' ) ) {
			return 'miss';
		}

		return 'sin_cache';
	}

	/**
	 * Ruta directa al servidor de origen, en formato `CURLOPT_CONNECT_TO`.
	 *
	 * `https://akibara.cl/x/` → `akibara.cl:443:127.0.0.1:443`: el nombre del sitio y
	 * su certificado se conservan; sólo cambia a qué máquina se conecta.
	 *
	 * @param string $url Dirección de la ficha.
	 */
	public static function connect_to( string $url ): ?string {
		$partes = wp_parse_url( $url );
		if ( ! is_array( $partes ) || empty( $partes['host'] ) || empty( $partes['scheme'] ) ) {
			return null;
		}
		$sitio = wp_parse_url( home_url( '/' ) );
		if ( ! is_array( $sitio ) || strtolower( (string) ( $sitio['host'] ?? '' ) ) !== strtolower( (string) $partes['host'] ) ) {
			return null;
		}
		$puerto = isset( $partes['port'] ) ? (int) $partes['port'] : ( 'https' === $partes['scheme'] ? 443 : 80 );
		$ruta   = sprintf( '%s:%d:127.0.0.1:%d', $partes['host'], $puerto, $puerto );

		/**
		 * Ruta directa al origen; `null` salta la ficha. El espejo local la cambia:
		 * ahí WordPress escucha en el 80 dentro del contenedor, no en el 8080.
		 *
		 * @param string|null $ruta `host:puerto:ip:puerto`.
		 * @param string      $url  Dirección de la ficha.
		 */
		$filtrada = apply_filters( 'akibara_cache_warm_connect_to', $ruta, $url );

		return is_string( $filtrada ) && 1 === preg_match( '/^[^:\s]+:\d+:[^:\s]+:\d+$/', $filtrada ) ? $filtrada : null;
	}

	/**
	 * La petición de una ficha.
	 *
	 * @param string $url Dirección de la ficha.
	 * @return array{url: string, headers: array<string, string>}
	 */
	public static function request_for( string $url ): array {
		return array(
			'url'     => $url,
			'headers' => array(
				'Accept'          => self::ACCEPT,
				'Accept-Language' => 'es-CL,es;q=0.9',
				'User-Agent'      => self::USER_AGENT,
			),
		);
	}

	/**
	 * Transporte real: las peticiones de la tanda a la vez, directo al origen.
	 *
	 * @param array<int, array{url: string, headers: array<string, string>}> $peticiones Peticiones por ficha.
	 * @param string                                                         $ruta       Ruta `CURLOPT_CONNECT_TO`.
	 * @return array<int, array<string, mixed>>
	 */
	public static function fetch_from_origin( array $peticiones, string $ruta ): array {
		if ( ! class_exists( '\WpOrg\Requests\Requests' ) || ! defined( 'CURLOPT_CONNECT_TO' ) ) {
			return array_map( static fn(): array => array( 'error' => 'Falta cURL con CONNECT_TO' ), $peticiones );
		}
		$lista = array();
		foreach ( $peticiones as $clave => $peticion ) {
			// Crear un objeto de hooks por petición de Requests. Compartirlo permitiría
			// que el parseo registrado por una respuesta afectara al de otra petición.

			$hooks = new \WpOrg\Requests\Hooks();
			$hooks->register(
				'curl.before_multi_add',
				static function ( $handle ) use ( $ruta ): void {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- el transporte de WordPress no expone CONNECT_TO.
					if ( ! curl_setopt( $handle, CURLOPT_CONNECT_TO, array( $ruta ) ) ) {
						// Sin la ruta directa la petición saldría por Cloudflare: mejor fallar.
						throw new \RuntimeException( 'cURL no aceptó la ruta directa al servidor (CONNECT_TO).' );
					}
				}
			);
			$lista[ $clave ] = array(
				'url'     => $peticion['url'],
				'headers' => $peticion['headers'],
				'type'    => 'GET',
				'options' => array(
					'timeout'          => self::TIMEOUT,
					'connect_timeout'  => 5,
					'follow_redirects' => false,
					'hooks'            => $hooks,
					'verify'           => ABSPATH . WPINC . '/certificates/ca-bundle.crt',
				),
			);
		}

		$salida = array();
		try {
			$respuestas = \WpOrg\Requests\Requests::request_multiple( $lista, array( 'transport' => \WpOrg\Requests\Transport\Curl::class ) );
		} catch ( Throwable $throwable ) {
			return array_map( static fn(): array => array( 'error' => $throwable->getMessage() ), $peticiones );
		}
		foreach ( $respuestas as $clave => $respuesta ) {
			if ( ! $respuesta instanceof \WpOrg\Requests\Response ) {
				$salida[ $clave ] = array( 'error' => $respuesta instanceof Throwable ? $respuesta->getMessage() : 'sin respuesta' );
				continue;
			}
			$salida[ $clave ] = array(
				'status'  => (int) $respuesta->status_code,
				'headers' => array(
					'x-litespeed-cache'         => (string) ( $respuesta->headers['x-litespeed-cache'] ?? '' ),
					'x-litespeed-cache-control' => (string) ( $respuesta->headers['x-litespeed-cache-control'] ?? '' ),
				),
			);
		}

		return $salida;
	}

	/**
	 * Fichas publicadas en el orden en que conviene pedirlas: primero lo que se
	 * puede comprar, por ventas; al final lo agotado.
	 *
	 * @return array<int, int>
	 */
	public static function build_queue(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- una consulta por noche, sin datos del usuario; WP_Query no ordena por dos metas a la vez.
		// Preparar el estado agotado desde StockStatus, sin duplicar el literal SQL.
		
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_stock_status'
				LEFT JOIN {$wpdb->postmeta} v ON v.post_id = p.ID AND v.meta_key = 'total_sales'
				WHERE p.post_type = 'product' AND p.post_status = 'publish' AND p.post_password = ''
				ORDER BY ( s.meta_value = %s ) ASC, CAST( v.meta_value AS UNSIGNED ) DESC, p.post_date DESC, p.ID DESC",
				StockStatus::OUT_OF_STOCK
			)
		);

		return is_array( $ids ) ? array_values( array_map( 'intval', $ids ) ) : array();
	}

	/**
	 * Abre una pasada con la cola de esta noche.
	 *
	 * @param int  $ahora  Marca de tiempo.
	 * @param bool $manual Si la pidió el panel (no respeta la ventana).
	 * @return array<string, int|string>|null
	 */
	private function open_pass( int $ahora, bool $manual ): ?array {
		$cola = self::build_queue();
		if ( array() === $cola ) {
			return null;
		}
		update_option( self::OPTION_QUEUE, $cola, false );
		$estado            = self::EMPTY_STATE;
		$estado['started'] = $ahora;
		$estado['day']     = StoreTime::today_ymd( $ahora );
		$estado['manual']  = $manual ? 1 : 0;
		$estado['total']   = count( $cola );
		update_option( self::OPTION_STATE, $estado, false );

		return $estado;
	}

	/**
	 * Cierra la pasada y suelta la cola, que ya no sirve.
	 *
	 * @param array<string, int|string> $estado Estado final.
	 */
	private static function close_pass( array $estado ): void {
		update_option( self::OPTION_STATE, $estado, false );
		delete_option( self::OPTION_QUEUE );
	}

	/**
	 * @param array<string, mixed> $respuesta Respuesta fallida.
	 * @param string               $url       Ficha.
	 */
	private static function error_text( array $respuesta, string $url ): string {
		$motivo = isset( $respuesta['error'] ) ? (string) $respuesta['error'] : 'respondió ' . (int) ( $respuesta['status'] ?? 0 );

		return $url . ': ' . substr( $motivo, 0, 200 );
	}

	/**
	 * Una sola corrida pendiente: la nueva reemplaza a la que hubiera.
	 *
	 * @param int $timestamp Cuándo.
	 */
	private static function schedule( int $timestamp ): void {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, array(), self::GROUP );
		}
		as_schedule_single_action( $timestamp, self::HOOK, array(), self::GROUP, false, self::PRIORITY );
	}

	private static function has_pending(): bool {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return false;
		}
		$acciones = as_get_scheduled_actions(
			array(
				'hook'     => self::HOOK,
				'group'    => self::GROUP,
				'status'   => 'pending',
				'per_page' => 1,
			),
			'ids'
		);

		return ! empty( $acciones );
	}

	/**
	 * El estado leído de la base, no de la caché del proceso.
	 *
	 * @return array<string, int|string>
	 */
	private static function fresh_state(): array {
		wp_cache_delete( self::OPTION_STATE, 'options' );

		return self::state();
	}

	private function claim_lock(): bool {
		$valor = time() . '|' . bin2hex( random_bytes( 8 ) );
		if ( ! OptionRow::insert_if_absent( self::LOCK_OPTION, $valor ) ) {
			$actual = OptionRow::read_raw( self::LOCK_OPTION );
			if ( null === $actual || (int) strtok( $actual, '|' ) > time() - self::LOCK_TTL || ! OptionRow::compare_and_swap( self::LOCK_OPTION, $actual, $valor ) ) {
				return false;
			}
		}
		$this->candado = $valor;

		return true;
	}

	private function release_lock(): void {
		if ( '' === $this->candado ) {
			return;
		}
		OptionRow::delete_if_equals( self::LOCK_OPTION, $this->candado );
		$this->candado = '';
	}
}
