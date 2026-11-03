<?php
/**
 * Recomprime sólo tamaños AVIF de adjuntos bajo portadas/, sin tocar logos,
 * favicon ni banners. Cada temporal reemplaza al archivo sólo si conserva
 * dimensiones y pesa como máximo 90% del existente.
 * Nunca reemplaza el original: obtiene la fuente mediante
 * wp_get_original_image_path y omite las que exceden MAX_SOURCE_PIXELS.
 * Un token de candado gobierna estado y sustituciones. Quien pierde el
 * candado deja de escribir y no elimina el de otra corrida.
 * Agenda continuación antes de trabajar y vuelve a comprobarla al salir.
 * Anota la portada en curso, permite un reintento y después omite la que
 * vuelve a interrumpir el proceso. Los 20 segundos de presupuesto se revisan
 * entre portadas, no a mitad de una. Usa baja prioridad, un hilo Imagick,
 * memoria acotada y cursor por ID.
 * La purga se agenda una vez a las 04:30 de Chile; la deuda de purga persiste
 * si se reinicia la pasada. Evita vaciar assets, object cache y borde en hora punta.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\ImageQuality;

use Akibara\Business\Utilities\OptionRow;
use Akibara\Business\Utilities\StoreTime;

defined( 'ABSPATH' ) || exit;

final class Recompressor {

	public const HOOK = 'akibara_business_image_recompress';

	/** Purga del borde, agendada para la madrugada tras una pasada que cambió algo. */
	public const HOOK_PURGE = 'akibara_business_image_recompress_purge';

	public const GROUP = 'akb-image-recompress';

	/** Estado de la recompresión (sin autoload: sólo lo leen el panel y la cola). */
	public const OPTION_STATE = 'akb_image_recompress_state';

	/** Calidad con que ya se revisó una portada: una segunda pasada la salta. */
	public const META_DONE = '_akb_avif_quality';

	/** El archivo nuevo sólo entra si pesa como mucho esta fracción del actual. */
	public const REPLACE_RATIO = 0.9;

	/** Prefijo de los temporales, junto al archivo que podrían reemplazar. */
	public const TMP_PREFIX = '.akb-recomprimir-';

	/**
	 * Omite fuentes que excedan el límite de píxeles. Ese límite reduce presión
	 * de memoria, pero los topes Imagick cubren su caché de píxeles y no todos
	 * los búferes del decodificador libheif.
	 */
	public const MAX_SOURCE_PIXELS = 16000000;

	/** Candado: `marca de tiempo|token|renovación`, escrito sólo con SQL atómico. */
	public const LOCK_OPTION = 'akb_image_recompress_lock';

	/** Un candado sin renovar en este tiempo es de una corrida muerta. */
	private const LOCK_TTL = 900;

	/** Segundos de trabajo por corrida de la cola. */
	private const BUDGET_SECONDS = 20;

	/** Espera antes de la corrida siguiente. */
	private const CONTINUE_DELAY = 60;

	/** Si una corrida tiene el candado a la hora de la purga, se reintenta a los diez minutos. */
	private const PURGE_RETRY_DELAY = 600;

	/** Portadas que se piden a la base por consulta. */
	private const PAGE = 20;

	/** Sólo se recomprimen adjuntos guardados bajo esta carpeta de uploads. */
	private const COVERS_DIR = 'portadas/';

	/** Prioridad en Action Scheduler: detrás del buscador y de la preventa (10). */
	private const PRIORITY = 20;

	/** Hora local de la purga del borde. */
	private const PURGE_HOUR   = 4;
	private const PURGE_MINUTE = 30;

	/** Topes de Imagick: por encima usa disco en vez de agotar la memoria de la cuenta. */
	private const IMAGICK_MEMORY = 268435456;
	private const IMAGICK_MAP    = 536870912;

	/** @var array<string, int|string> */
	private const EMPTY_STATE = array(
		'quality'         => 0,
		'started'         => 0,
		'finished'        => 0,
		'cursor'          => 0,
		'en_proceso'      => 0,
		'reintentada'     => 0,
		'total'           => 0,
		'revisadas'       => 0,
		'reemplazados'    => 0,
		'conservados'     => 0,
		'omitidas'        => 0,
		'errores'         => 0,
		'bytes_antes'     => 0,
		'bytes_despues'   => 0,
		'purga_pendiente' => 0,
		'purga_agendada'  => 0,
		'purgada'         => 0,
		'ultimo_error'    => '',
	);

	/** Lo que vale la purga: pasa intacto a una pasada que se vuelve a empezar. */
	private const PURGE_KEYS = array( 'purga_pendiente', 'purga_agendada', 'purgada' );

	/**
	 * Codificador: (fuente, ancho de caja, alto de caja, recorte, calidad, destino)
	 * → `array{width:int, height:int, path:string, mime:string}` o `\WP_Error`.
	 *
	 * @var callable
	 */
	private $encode;

	/** @var callable(): float */
	private $clock;

	/** Directorios cuyos temporales huérfanos ya se barrieron en esta corrida. @var array<string, true> */
	private array $barridos = array();

	/** Valor exacto del candado que tiene este objeto ('' = ninguno). */
	private string $candado = '';

	private string $token = '';

	private int $renovaciones = 0;

	/** Límites de Imagick a devolver si el proceso muere sin pasar por el `finally`. @var array<int, int> */
	private array $limites_vivos = array();

	private bool $apagado_registrado = false;

	public function __construct( ?callable $encode = null, ?callable $clock = null ) {
		$this->encode = $encode ?? array( self::class, 'encode_with_wordpress' );
		$this->clock  = $clock ?? static function (): float {
			return microtime( true );
		};
	}

	public function register(): void {
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( self::HOOK_PURGE, array( $this, 'purge_edge' ) );
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
	 * ¿La pasada quedó a medias sin nada que la mueva? (sin acción pendiente ni en
	 * curso y con el candado vencido).
	 *
	 * @param array<string, int|string> $estado Estado normalizado.
	 */
	public static function is_stalled( array $estado ): bool {
		return (int) $estado['started'] > 0
			&& 0 === (int) $estado['finished']
			&& ! self::has_action( 'pending' )
			&& ! self::has_action( 'in-progress' )
			&& ! self::lock_is_fresh();
	}

	/**
	 * Empieza una pasada con esta calidad, o retoma la detenida si es la misma.
	 *
	 * @param int $quality Calidad AVIF vigente.
	 * @return string `encolada`, `ya-en-curso` o `sin-cola`.
	 */
	public function start( int $quality ): string {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return 'sin-cola';
		}
		if ( ! $this->claim_lock() ) {
			return 'ya-en-curso';
		}

		try {
			$estado  = self::fresh_state();
			$abierta = (int) $estado['started'] > 0 && 0 === (int) $estado['finished'];
			if ( $abierta && ( self::has_action( 'pending' ) || self::has_action( 'in-progress' ) ) ) {
				return 'ya-en-curso';
			}

			if ( $abierta && (int) $estado['quality'] === $quality ) {
				// Retomar: mismo cursor y mismas cuentas; la marca por portada evita
				// rehacer lo que ya se revisó. La portada que quedó en vuelo la resuelve
				// la corrida (un reintento, y a la segunda se salta).
				$nuevo = $estado;
			} else {
				$nuevo            = self::EMPTY_STATE;
				$nuevo['quality'] = $quality;
				$nuevo['started'] = time();
				$nuevo['total']   = self::count_candidates();
				foreach ( self::PURGE_KEYS as $clave ) {
					$nuevo[ $clave ] = $estado[ $clave ];
				}
				// Con otra calidad la portada que se cortó no se marca a ciegas: nunca se
				// probó con ESTA calidad, así que entra con su reintento limpio.
				$nuevo['en_proceso'] = $abierta ? (int) $estado['en_proceso'] : 0;
			}

			if ( ! $this->save_state( $nuevo ) ) {
				return 'ya-en-curso';
			}
			self::schedule( time() );

			return 'encolada';
		} finally {
			$this->release_lock();
		}
	}

	/**
	 * Una corrida de la cola: trabaja hasta agotar el presupuesto.
	 *
	 * @internal Callback de `akibara_business_image_recompress`.
	 */
	public function run(): void {
		$estado = self::state();
		if ( 0 === (int) $estado['started'] || 0 !== (int) $estado['finished'] ) {
			return;
		}

		if ( ! $this->claim_lock() ) {
			// Si otra corrida tiene el candado, conserva una continuación programada.
			// Esta acción puede ser la única continuación pendiente y no debe consumirla
			// sin dejar otra que mantenga el trabajo en movimiento.
			self::ensure_continuation();
			return;
		}

		// La corrida siguiente, ANTES de trabajar: si PHP muere a mitad, la pasada sigue.
		self::ensure_continuation();

		// Un fatal no pasa por `finally`: lsphp reutiliza el proceso (habría que
		// devolverle los límites de Imagick) y el candado se quedaría tomado 15 minutos
		// sin proteger nada —de la portada que mató a PHP ya se encarga `en_proceso`.
		$this->registrar_apagado();

		try {
			$this->limites_vivos = self::limit_imagick();

			// Releída con el candado: la leída de arriba pudo quedar vieja mientras se tomaba.
			$estado = self::fresh_state();
			if ( 0 === (int) $estado['started'] || 0 !== (int) $estado['finished'] ) {
				return;
			}
			if ( (int) $estado['quality'] < 1 ) {
				// Una calidad sin valor daría por revisada toda portada sin marca
				// (`(int) '' === 0`) y terminaría la pasada sin tocar un archivo.
				return;
			}

			if ( (int) $estado['en_proceso'] > 0 && ! $this->handle_interrupted_cover( $estado ) ) {
				return;
			}

			$this->barridos = array();
			$limite         = ( $this->clock )() + self::BUDGET_SECONDS;
			$quedan         = true;

			while ( ( $this->clock )() < $limite ) {
				$ids = self::next_ids( (int) $estado['cursor'], self::PAGE );
				if ( array() === $ids ) {
					$quedan = false;
					break;
				}
				foreach ( $ids as $id ) {
					if ( ( $this->clock )() >= $limite ) {
						break 2;
					}
					$estado['en_proceso'] = $id;
					if ( ! $this->save_state( $estado ) ) {
						return;
					}

					if ( ! $this->process_attachment( $id, (int) $estado['quality'], $estado ) ) {
						// Perdió el candado a mitad de la portada: la corrida que lo tiene manda.
						return;
					}

					$estado['en_proceso'] = 0;
					$estado['cursor']     = $id;
					++$estado['revisadas'];
					if ( (int) $estado['reintentada'] === $id ) {
						// El reintento salió bien: el corte anterior no era culpa de esta portada.
						$estado['reintentada'] = 0;
					}
					if ( ! $this->save_state( $estado ) ) {
						return;
					}
				}
			}

			if ( $quedan ) {
				// La continuación que se agendó al empezar pudo consumirla otra corrida
				// que no consiguió el candado: se comprueba otra vez al salir.
				self::ensure_continuation();
				return;
			}

			$estado['finished'] = time();
			if ( (int) $estado['purga_pendiente'] > 0 ) {
				$estado['purga_agendada'] = self::schedule_purge();
			}
			if ( ! $this->save_state( $estado ) ) {
				return;
			}
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( self::HOOK, array(), self::GROUP );
			}
		} finally {
			self::restore_imagick( $this->limites_vivos );
			$this->limites_vivos = array();
			$this->release_lock();
		}
	}

	/**
	 * La portada que estaba en vuelo cuando se cortó la corrida anterior.
	 *
	 * Primera vez: se reintenta, porque una corrida se corta también por cosas
	 * ajenas a la portada (tope de tiempo, reciclado del proceso, presión de memoria
	 * de otra cuenta del hosting compartido). Segunda vez seguida en la MISMA
	 * portada: ésa sí se marca y se salta.
	 *
	 * @param array<string, int|string> $estado Estado de la pasada; se actualiza.
	 * @return bool false si se perdió el candado al escribir.
	 */
	private function handle_interrupted_cover( array &$estado ): bool {
		$muerta = (int) $estado['en_proceso'];

		if ( (int) $estado['reintentada'] === $muerta ) {
			self::skip_dead_cover( $estado, $muerta, (int) $estado['quality'] );
			// Pasa el cursor por encima: ya se contó en «omitidas» y no se cuenta dos veces.
			$estado['cursor']      = max( (int) $estado['cursor'], $muerta );
			$estado['reintentada'] = 0;
		} else {
			$estado['reintentada'] = $muerta;
		}
		$estado['en_proceso'] = 0;

		return $this->save_state( $estado );
	}

	/**
	 * Devuelve los límites de Imagick y suelta el candado si el proceso muere sin
	 * pasar por el `finally`. Se registra una sola vez por objeto.
	 */
	private function registrar_apagado(): void {
		if ( $this->apagado_registrado ) {
			return;
		}
		$this->apagado_registrado = true;

		register_shutdown_function(
			function (): void {
				self::restore_imagick( $this->limites_vivos );
				$this->limites_vivos = array();
				$this->release_lock();
			}
		);
	}

	/**
	 * Revisa los tamaños AVIF de una portada y reemplaza los que bajan de peso.
	 *
	 * Dentro de una corrida, cada archivo codificado renueva el candado antes de
	 * reemplazar nada. Fuera de una corrida (pruebas, QA) no hay candado que vigilar.
	 *
	 * @param int                       $id      Adjunto.
	 * @param int                       $quality Calidad de la pasada.
	 * @param array<string, int|string> $estado  Estado de la pasada; se actualiza.
	 * @return bool false si la corrida perdió el candado a mitad de la portada.
	 */
	public function process_attachment( int $id, int $quality, array &$estado ): bool {
		if ( $quality < 1 ) {
			// Sin marca de calidad no se considera revisada la portada: convertir
			// una cadena vacía a cero no demuestra revisión previa.
			return true;
		}

		if ( (int) get_post_meta( $id, self::META_DONE, true ) === $quality ) {
			return true;
		}

		$meta = wp_get_attachment_metadata( $id );
		if ( ! is_array( $meta ) || empty( $meta['sizes'] ) || ! is_array( $meta['sizes'] ) ) {
			update_post_meta( $id, self::META_DONE, $quality );
			return true;
		}

		// La fuente PRIMERO: sin archivo adjunto, `dirname('')` es '.' y el barrido de
		// temporales miraría el directorio de trabajo del proceso (la raíz web).
		$fuente = self::source_for( $id );
		if ( '' === $fuente ) {
			// Sin marca: un disco o un permiso pasajero no la deja fuera para siempre.
			++$estado['omitidas'];
			$estado['ultimo_error'] = sprintf( 'Portada %d: no se encontró el original en disco; se dejó como estaba y se reintenta en la próxima recompresión.', $id );
			return true;
		}

		$medidas = wp_getimagesize( $fuente );
		if ( is_array( $medidas ) && (int) $medidas[0] * (int) $medidas[1] > self::MAX_SOURCE_PIXELS ) {
			++$estado['omitidas'];
			$estado['ultimo_error'] = sprintf( 'Portada %d: el original mide %d × %d px; se dejó como estaba para no agotar la memoria del servidor.', $id, (int) $medidas[0], (int) $medidas[1] );
			update_post_meta( $id, self::META_DONE, $quality );
			return true;
		}

		// Los tamaños viven junto al archivo adjunto (el original `-original` está en la
		// misma carpeta); con fuente legible, `get_attached_file()` nunca es vacío aquí.
		$adjunto = (string) get_attached_file( $id );
		$dir     = dirname( '' !== $adjunto ? $adjunto : $fuente );
		$this->sweep_temporaries( $dir );

		// Un mismo archivo puede figurar con dos nombres de tamaño: se recodifica una vez.
		$por_archivo = array();
		foreach ( $meta['sizes'] as $nombre => $tamano ) {
			if ( is_array( $tamano ) && 'image/avif' === ( $tamano['mime-type'] ?? '' ) && ! empty( $tamano['file'] ) ) {
				$por_archivo[ basename( (string) $tamano['file'] ) ][] = (string) $nombre;
			}
		}

		$registrados = function_exists( 'wp_get_registered_image_subsizes' ) ? wp_get_registered_image_subsizes() : array();
		$reemplazos  = array();
		$fallo       = false;

		foreach ( $por_archivo as $archivo => $nombres ) {
			$destino = $dir . '/' . $archivo;
			if ( ! is_file( $destino ) ) {
				continue;
			}

			$nombre   = $nombres[0];
			$tamano   = $meta['sizes'][ $nombre ];
			$caja     = isset( $registrados[ $nombre ] ) && is_array( $registrados[ $nombre ] )
				? $registrados[ $nombre ]
				: array(
					'width'  => (int) $tamano['width'],
					'height' => 0,
					'crop'   => false,
				);
			$temporal = $dir . '/' . self::TMP_PREFIX . $id . '-' . getmypid() . '-' . substr( md5( $archivo . microtime() ), 0, 10 ) . '.avif';
			$antes    = (int) filesize( $destino );
			$hecho    = ( $this->encode )( $fuente, (int) $caja['width'], (int) $caja['height'], $caja['crop'] ?? false, $quality, $temporal );

			if ( ! $this->still_owner() ) {
				self::remove( $temporal );
				if ( is_array( $hecho ) && '' !== (string) ( $hecho['path'] ?? '' ) && dirname( (string) $hecho['path'] ) === $dir && 0 === strpos( basename( (string) $hecho['path'] ), self::TMP_PREFIX ) ) {
					self::remove( (string) $hecho['path'] );
				}
				if ( array() !== $reemplazos ) {
					// Lo ya reemplazado se anota y se purga; el estado es de la otra corrida.
					self::record_filesizes( $id, $reemplazos );
					self::schedule_purge();
				}
				return false;
			}

			if ( is_wp_error( $hecho ) ) {
				self::remove( $temporal );
				// La caja no cabe en la fuente: es permanente y no es un fallo (core tampoco lo haría).
				if ( 'error_getting_dimensions' === $hecho->get_error_code() ) {
					++$estado['conservados'];
					continue;
				}
				$fallo = true;
				++$estado['errores'];
				$estado['ultimo_error'] = sprintf( 'Portada %d, tamaño %s: %s', $id, $nombre, $hecho->get_error_message() );
				continue;
			}

			if ( ! is_array( $hecho ) || $temporal !== ( $hecho['path'] ?? '' ) || 'image/avif' !== ( $hecho['mime'] ?? '' ) || ! is_file( $temporal ) ) {
				self::remove( $temporal );
				$otro = is_array( $hecho ) ? (string) ( $hecho['path'] ?? '' ) : '';
				if ( '' !== $otro && dirname( $otro ) === $dir && 0 === strpos( basename( $otro ), self::TMP_PREFIX ) ) {
					self::remove( $otro );
				}
				$fallo = true;
				++$estado['errores'];
				$estado['ultimo_error'] = sprintf( 'Portada %d, tamaño %s: el codificador no dejó un AVIF en el temporal; no se tocó nada.', $id, $nombre );
				continue;
			}

			if ( (int) $hecho['width'] !== (int) $tamano['width'] || (int) $hecho['height'] !== (int) $tamano['height'] ) {
				self::remove( $temporal );
				++$estado['conservados'];
				continue;
			}

			clearstatcache( true, $temporal );
			$despues = (int) filesize( $temporal );
			if ( $despues > 0 && $antes > 0 && $despues <= self::REPLACE_RATIO * $antes && rename( $temporal, $destino ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- reemplazo atómico en el mismo directorio.
				++$estado['reemplazados'];
				$estado['bytes_antes']    += $antes;
				$estado['bytes_despues']  += $despues;
				$estado['purga_pendiente'] = 1;
				$reemplazos[ $archivo ]    = $despues;
				continue;
			}

			self::remove( $temporal );
			++$estado['conservados'];
		}

		if ( array() !== $reemplazos ) {
			self::record_filesizes( $id, $reemplazos );
		}

		// Con algún tamaño fallido la portada queda sin marca: la próxima pasada lo reintenta.
		if ( ! $fallo ) {
			update_post_meta( $id, self::META_DONE, $quality );
		}

		return true;
	}

	/**
	 * Anota el peso nuevo en la metadata, releída en crudo justo antes de escribir.
	 *
	 * Sólo toca `filesize`, así que no pisa una edición hecha mientras la portada se
	 * recodificaba; y no pasa por `wp_update_attachment_metadata`, que LiteSpeed
	 * engancha (en producción `img_optm-auto` está encendido).
	 *
	 * @param int                $id         Adjunto.
	 * @param array<string, int> $reemplazos Nombre de archivo => bytes nuevos.
	 */
	private static function record_filesizes( int $id, array $reemplazos ): void {
		$cruda = get_post_meta( $id, '_wp_attachment_metadata', true );
		if ( ! is_array( $cruda ) || ! isset( $cruda['sizes'] ) || ! is_array( $cruda['sizes'] ) ) {
			return;
		}
		foreach ( $cruda['sizes'] as $nombre => $tamano ) {
			$archivo = is_array( $tamano ) ? basename( (string) ( $tamano['file'] ?? '' ) ) : '';
			if ( '' !== $archivo && isset( $reemplazos[ $archivo ] ) ) {
				$cruda['sizes'][ $nombre ]['filesize'] = $reemplazos[ $archivo ];
			}
		}
		update_post_meta( $id, '_wp_attachment_metadata', $cruda );
	}

	/**
	 * La portada en la que murió PHP: se marca con la calidad de la pasada y se
	 * cuenta como dejada como estaba. La corrida la pasa de largo por la marca.
	 *
	 * @param array<string, int|string> $estado  Estado que se actualiza.
	 * @param int                       $id      Adjunto en el que murió la corrida.
	 * @param int                       $quality Calidad de la pasada.
	 */
	private static function skip_dead_cover( array &$estado, int $id, int $quality ): void {
		update_post_meta( $id, self::META_DONE, $quality );
		++$estado['omitidas'];
		$estado['ultimo_error'] = sprintf( 'Portada %d: el proceso se cortó dos veces seguidas en ella; se dejó como estaba. Para reintentarla, recomprime con otra calidad.', $id );
		$estado['en_proceso']   = 0;
	}

	/**
	 * El archivo del que core saca los tamaños.
	 *
	 * @param int $id Adjunto.
	 * @return string Ruta legible, o '' si no hay ninguna.
	 */
	public static function source_for( int $id ): string {
		$fuente = function_exists( 'wp_get_original_image_path' ) ? (string) wp_get_original_image_path( $id ) : '';
		if ( '' === $fuente || ! is_readable( $fuente ) ) {
			$fuente = (string) get_attached_file( $id );
		}

		return ( '' !== $fuente && is_readable( $fuente ) ) ? $fuente : '';
	}

	/**
	 * Codifica con el editor de WordPress, a la calidad pedida.
	 *
	 * La calidad entra por el filtro con prioridad máxima: es la única vía que
	 * llega al codificador (ver la cabecera de {@see ImageQuality}), y así la
	 * pasada usa SU calidad aunque el dueño guarde otra mientras corre.
	 *
	 * @param string     $source  Archivo fuente.
	 * @param int        $box_w   Ancho de la caja (0 = libre).
	 * @param int        $box_h   Alto de la caja (0 = libre).
	 * @param bool|array $crop    Recorte del tamaño registrado.
	 * @param int        $quality Calidad AVIF.
	 * @param string     $destino Temporal donde escribir.
	 * @return array{width:int, height:int, path:string, mime:string}|\WP_Error
	 */
	public static function encode_with_wordpress( string $source, int $box_w, int $box_h, $crop, int $quality, string $destino ) {
		$fijar = static function () use ( $quality ): int {
			return $quality;
		};
		add_filter( 'wp_editor_set_quality', $fijar, PHP_INT_MAX );

		try {
			$editor = wp_get_image_editor( $source );
			if ( is_wp_error( $editor ) ) {
				return $editor;
			}
			$redimensionado = $editor->resize( $box_w > 0 ? $box_w : null, $box_h > 0 ? $box_h : null, $crop );
			if ( is_wp_error( $redimensionado ) ) {
				return $redimensionado;
			}
			$guardado = $editor->save( $destino, 'image/avif' );
			if ( is_wp_error( $guardado ) ) {
				return $guardado;
			}

			return array(
				'width'  => (int) $guardado['width'],
				'height' => (int) $guardado['height'],
				'path'   => (string) $guardado['path'],
				'mime'   => (string) ( $guardado['mime-type'] ?? '' ),
			);
		} finally {
			remove_filter( 'wp_editor_set_quality', $fijar, PHP_INT_MAX );
		}
	}

	/**
	 * Vacía el borde una vez, a la hora agendada.
	 *
	 * Con el candado, como cualquier escritura del estado: si una corrida lo tiene,
	 * se reintenta a los diez minutos (sigue siendo de madrugada).
	 *
	 * @internal Callback de `akibara_business_image_recompress_purge`.
	 */
	public function purge_edge(): void {
		if ( ! $this->claim_lock() ) {
			if ( function_exists( 'as_schedule_single_action' ) ) {
				// Reintenta en diez minutos dentro de la ventana de madrugada; los
				// reintentos encadenados no deben trasladar purge_all al horario de compra.
				$cuando = time() + self::PURGE_RETRY_DELAY;
				if ( ! self::is_quiet( $cuando ) ) {
					$cuando = self::next_quiet_timestamp();
				}
				as_schedule_single_action( $cuando, self::HOOK_PURGE, array(), self::GROUP, false, self::PRIORITY );
			}
			return;
		}

		try {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook de LiteSpeed: es SU purga.
			do_action( 'litespeed_purge_all' );
			$estado                    = self::fresh_state();
			$estado['purga_pendiente'] = 0;
			$estado['purga_agendada']  = 0;
			$estado['purgada']         = time();
			$this->save_state( $estado );
		} finally {
			$this->release_lock();
		}
	}

	/**
	 * Próximo momento de purga: las 04:30 de la tienda, con el calendario de la zona
	 * (no con el desfase de hoy, que no vale en el cambio de horario).
	 *
	 * @param int|null $now Marca de tiempo de referencia.
	 */
	public static function next_quiet_timestamp( ?int $now = null ): int {
		$zona  = StoreTime::zone();
		$ahora = ( new \DateTimeImmutable( '@' . ( $now ?? time() ) ) )->setTimezone( $zona );
		$next  = $ahora->setTime( self::PURGE_HOUR, self::PURGE_MINUTE );
		if ( $next->getTimestamp() <= $ahora->getTimestamp() ) {
			$next = $ahora->modify( '+1 day' )->setTime( self::PURGE_HOUR, self::PURGE_MINUTE );
		}

		return $next->getTimestamp();
	}

	/**
	 * Conserva una purga ya pendiente, incluidos sus reintentos, o agenda la
	 * próxima ventana de madrugada. No desplaza una purga existente al día siguiente.
	 *
	 * @return int Momento de la purga que queda agendada, o 0 si no hay ninguna.
	 */
	private static function schedule_purge(): int {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return 0;
		}
		if ( function_exists( 'as_next_scheduled_action' ) ) {
			$existente = as_next_scheduled_action( self::HOOK_PURGE, array(), self::GROUP );
			if ( true === $existente ) {
				// Corriendo ahora mismo: esa purga ya vacía lo que hay hasta este instante.
				return 0;
			}
			if ( is_int( $existente ) && $existente > 0 ) {
				return $existente;
			}
		}
		$cuando = self::next_quiet_timestamp();
		$id     = as_schedule_single_action( $cuando, self::HOOK_PURGE, array(), self::GROUP, false, self::PRIORITY );

		// Sin acción no hay purga: el panel no puede prometer una fecha que no existe.
		return $id ? $cuando : 0;
	}

	/**
	 * ¿Cae ese momento en la madrugada de la tienda (04:00-06:00)?
	 *
	 * @param int $timestamp Momento a comprobar.
	 */
	private static function is_quiet( int $timestamp ): bool {
		$hora = (int) ( new \DateTimeImmutable( '@' . $timestamp ) )->setTimezone( StoreTime::zone() )->format( 'G' );

		return $hora >= self::PURGE_HOUR && $hora < self::PURGE_HOUR + 2;
	}

	/**
	 * Deja una corrida pendiente si no hay ninguna.
	 */
	private static function ensure_continuation(): void {
		if ( ! self::has_action( 'pending' ) ) {
			self::schedule( time() + self::CONTINUE_DELAY );
		}
	}

	private static function schedule( int $timestamp ): void {
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( $timestamp, self::HOOK, array(), self::GROUP, false, self::PRIORITY );
		}
	}

	/**
	 * Un hilo y memoria acotada para Imagick; devuelve los límites previos.
	 *
	 * El límite vive en el proceso (lsphp se reutiliza): hay que devolverlo o las
	 * subidas siguientes de ese proceso irían a un hilo.
	 *
	 * @return array<int, int>
	 */
	private static function limit_imagick(): array {
		if ( ! class_exists( '\Imagick' ) ) {
			return array();
		}
		$previos = array();
		foreach ( array( 'RESOURCETYPE_THREAD' => 1, 'RESOURCETYPE_MEMORY' => self::IMAGICK_MEMORY, 'RESOURCETYPE_MAP' => self::IMAGICK_MAP ) as $constante => $valor ) {
			if ( ! defined( '\Imagick::' . $constante ) ) {
				continue;
			}
			$tipo             = (int) constant( '\Imagick::' . $constante );
			$previos[ $tipo ] = (int) \Imagick::getResourceLimit( $tipo );
			\Imagick::setResourceLimit( $tipo, $valor );
		}

		return $previos;
	}

	/**
	 * @param array<int, int> $previos Límites que devolvió {@see limit_imagick()}.
	 */
	private static function restore_imagick( array $previos ): void {
		foreach ( $previos as $tipo => $valor ) {
			\Imagick::setResourceLimit( $tipo, $valor );
		}
	}

	/**
	 * Borra temporales de corridas que murieron entre codificar y reemplazar.
	 *
	 * Sólo los de más de `LOCK_TTL`: uno más joven puede ser de otro proceso vivo.
	 *
	 * @param string $dir Directorio de la portada.
	 */
	private function sweep_temporaries( string $dir ): void {
		if ( isset( $this->barridos[ $dir ] ) ) {
			return;
		}
		$this->barridos[ $dir ] = true;

		foreach ( (array) glob( $dir . '/' . self::TMP_PREFIX . '*.avif' ) as $huerfano ) {
			$mtime = filemtime( (string) $huerfano );
			if ( false !== $mtime && ( time() - $mtime ) > self::LOCK_TTL ) {
				self::remove( (string) $huerfano );
			}
		}
	}

	/**
	 * @param string $path Archivo a borrar si existe.
	 */
	private static function remove( string $path ): void {
		if ( '' !== $path && is_file( $path ) ) {
			unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- temporal propio.
		}
	}

	/**
	 * @return array<int, int> IDs de portadas AVIF después del cursor.
	 */
	private static function next_ids( int $cursor, int $limit ): array {
		global $wpdb;

		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT DISTINCT p.ID FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_wp_attached_file' AND pm.meta_value LIKE %s
				 WHERE p.post_type = 'attachment' AND p.post_mime_type = 'image/avif' AND p.ID > %d ORDER BY p.ID ASC LIMIT %d",
				$wpdb->esc_like( self::COVERS_DIR ) . '%',
				$cursor,
				$limit
			)
		);

		return array_map( 'intval', (array) $ids );
	}

	private static function count_candidates(): int {
		global $wpdb;

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_wp_attached_file' AND pm.meta_value LIKE %s
				 WHERE p.post_type = 'attachment' AND p.post_mime_type = 'image/avif'",
				$wpdb->esc_like( self::COVERS_DIR ) . '%'
			)
		);
	}

	/**
	 * @param string $status `pending` o `in-progress` (estados de Action Scheduler).
	 */
	private static function has_action( string $status ): bool {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return false;
		}

		$acciones = as_get_scheduled_actions(
			array(
				'hook'     => self::HOOK,
				'group'    => self::GROUP,
				'status'   => $status,
				'per_page' => 1,
			),
			'ids'
		);

		return ! empty( $acciones );
	}

	/**
	 * El estado leído de la base, no de la caché del proceso: otro proceso pudo
	 * escribirlo desde que éste lo leyó.
	 *
	 * @return array<string, int|string>
	 */
	private static function fresh_state(): array {
		wp_cache_delete( self::OPTION_STATE, 'options' );

		return self::state();
	}

	/**
	 * Escribe el estado sólo si este objeto sigue teniendo el candado (y lo renueva).
	 *
	 * @param array<string, int|string> $estado Estado completo.
	 */
	private function save_state( array $estado ): bool {
		if ( ! $this->still_owner() ) {
			return false;
		}
		update_option( self::OPTION_STATE, $estado, false );

		return true;
	}

	/**
	 * ¿Sigue siendo suyo el candado? Lo renueva de paso. Sin candado tomado (una
	 * llamada directa fuera de la cola) no hay nada que perder.
	 */
	private function still_owner(): bool {
		if ( '' === $this->candado ) {
			return true;
		}
		$nuevo = $this->lock_value();
		if ( ! self::swap_lock( $this->candado, $nuevo ) ) {
			$this->candado = '';
			return false;
		}
		$this->candado = $nuevo;

		return true;
	}

	/**
	 * Toma el candado de corrida, o falla si otro lo tiene vivo.
	 *
	 * Con SQL sobre la fila ({@see OptionRow}) y no con `add_option()`: ése hace
	 * `INSERT … ON DUPLICATE KEY UPDATE` y, si la caché dice que la option no existe,
	 * PISA el candado de otra corrida en vez de fallar. El índice único de
	 * `option_name` deja entrar a uno solo, y un candado vencido se roba con un
	 * UPDATE condicionado al valor que se leyó.
	 */
	private function claim_lock(): bool {
		$this->token        = bin2hex( random_bytes( 8 ) );
		$this->renovaciones = 0;
		$valor              = $this->lock_value();

		if ( ! OptionRow::insert_if_absent( self::LOCK_OPTION, $valor ) ) {
			$actual = OptionRow::read_raw( self::LOCK_OPTION );
			if ( null === $actual || self::lock_time( $actual ) > time() - self::LOCK_TTL || ! OptionRow::compare_and_swap( self::LOCK_OPTION, $actual, $valor ) ) {
				return false;
			}
		}
		$this->candado = $valor;

		return true;
	}

	/**
	 * Suelta el candado sólo si sigue siendo el suyo.
	 */
	private function release_lock(): void {
		if ( '' === $this->candado ) {
			return;
		}
		OptionRow::delete_if_equals( self::LOCK_OPTION, $this->candado );
		$this->candado = '';
	}

	/**
	 * El valor cambia en cada renovación: MySQL cuenta 0 filas en un UPDATE que no
	 * cambia nada, y dos renovaciones en el mismo segundo parecerían un robo.
	 */
	private function lock_value(): string {
		++$this->renovaciones;

		return time() . '|' . $this->token . '|' . $this->renovaciones;
	}

	private static function swap_lock( string $de, string $a ): bool {
		return OptionRow::compare_and_swap( self::LOCK_OPTION, $de, $a );
	}

	private static function read_lock(): ?string {
		return OptionRow::read_raw( self::LOCK_OPTION );
	}

	private static function lock_time( string $valor ): int {
		return (int) explode( '|', $valor )[0];
	}

	private static function lock_is_fresh(): bool {
		$valor = self::read_lock();

		return null !== $valor && self::lock_time( $valor ) > time() - self::LOCK_TTL;
	}
}
