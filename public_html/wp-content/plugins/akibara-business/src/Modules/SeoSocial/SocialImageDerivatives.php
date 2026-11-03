<?php
/**
 * Genera variantes JPG de portadas para correo y previews sociales.
 *
 * Cada original slug.avif puede disponer de una hermana slug-og.jpg. Los meta
 * sociales y consumidores de correo usan esa variante; las demás superficies
 * conservan el formato de imagen de la tienda.
 *
 * La subida del adjunto genera la variante antes de publicar la ficha. El cron
 * completa faltantes o fuentes modificadas en lotes MAX_PER_RUN con candado y
 * cursor, y agenda continuación mientras quede trabajo. ensure_for_slug() en
 * frontend sólo consulta o encola: no transcodifica dentro del render.
 * ensure_now() permite conversión inmediata acotada a quien captura la URL en
 * esa petición, como un evento de carrito o un worker de correo.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\SeoSocial;

use Akibara\Business\Utilities\OptionRow;

defined( 'ABSPATH' ) || exit;

final class SocialImageDerivatives {

	public const HOOK = 'akibara_business_social_image_derivatives';

	/**
	 * Hook propio para continuar un barrido troceado.
	 *
	 * La continuación se distingue del evento diario para que la deduplicación de
	 * agenda no confunda una tarea recurrente con la siguiente tanda pendiente.
	 */
	public const HOOK_CONTINUE = 'akibara_business_social_image_derivatives_continue';

	/** Evento de UNA portada, encolado por `ensure_for_slug()`. */
	public const HOOK_SLUG = 'akibara_business_social_image_for_slug';

	/** Sufijo de la variante social; también lo conoce `SeoSocial`. */
	public const SUFFIX = '-og.jpg';

	/** Calidad JPEG: portadas de libro comprimen bien; ~100-150 KB por pieza. */
	private const JPEG_QUALITY = 82;

	/**
	 * Lado mayor máximo de la hermana social. 1.200 px es lo que Open Graph
	 * recomienda como ancho; una portada vertical de 1.200 de alto sobra para
	 * cualquier tarjeta de WhatsApp, X o Gmail.
	 */
	public const MAX_SIDE = 1200;

	/**
	 * Tope de conversiones intentadas por corrida, incluidas las fallidas.
	 *
	 * Un archivo corrupto o un directorio sin escritura también consumen trabajo
	 * de decodificación. El presupuesto limita CPU por tick y conserva cursor
	 * para reanudar el resto del catálogo en corridas posteriores.
	 */
	private const MAX_PER_RUN = 100;

	/** Candado de corrida; su valor es `marca de tiempo|token` de quien lo tomó (1.116.10 guardaba sólo la marca). */
	private const LOCK_OPTION = 'akb_social_derivatives_lock';

	/** Valor exacto del candado que tiene este objeto ('' = ninguno). */
	private string $candado = '';

	/** Un candado abandonado (fatal a mitad de corrida) caduca solo. */
	private const LOCK_TTL = 900;

	/** Último archivo procesado: por donde retoma la corrida siguiente. */
	private const CURSOR_OPTION = 'akb_social_derivatives_cursor';

	/** Transient por slug cuya conversión falló; evita reencolar en bucle. */
	private const FAIL_PREFIX = 'akb_og_fail_';

	/** Un AVIF corrupto no se arregla solo; se reintenta al cabo de unas horas. */
	private const FAIL_TTL = 21600;

	/**
	 * Tope de conversiones inmediatas por petición mediante ensure_now().
	 *
	 * Los workers de correo pueden reunir muchas portadas en un digest. Las que
	 * exceden este presupuesto se encolan para cron y el correo mantiene la URL
	 * utilizable de origen, en lugar de transcodificar todo el lote dentro del envío.
	 */
	private const MAX_NOW_PER_REQUEST = 20;

	/** Conversiones en el acto ya gastadas en este trabajo. */
	private static int $now_conversions = 0;

	/**
	 * Devuelve el presupuesto de conversiones al empezar cada acción de la cola.
	 *
	 * @internal Callback de `action_scheduler_before_execute` y seam de pruebas.
	 * @return void
	 */
	public static function reset_now_budget(): void {
		self::$now_conversions = 0;
	}

	public function register(): void {
		// Reiniciar el presupuesto por trabajo de Action Scheduler, no por proceso.
		// action_scheduler_before_execute es el hook que emite el runner antes de cada
		// acción, aunque un mismo proceso atienda muchos mensajes del lote.

		add_action( 'action_scheduler_before_execute', array( __CLASS__, 'reset_now_budget' ) );
		add_action( self::HOOK, array( $this, 'generate_missing' ) );
		add_action( self::HOOK_CONTINUE, array( $this, 'generate_missing' ) );
		add_action( self::HOOK_SLUG, array( $this, 'generate_for_slug' ), 10, 1 );

		// La vía normal: la variante nace con la portada, no con la primera
		// visita. WordPress ya tiene la imagen decodificada en este punto.
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'generate_on_upload' ), 10, 2 );

		if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 2 * HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/**
	 * Genera la hermana social en cuanto se sube una portada.
	 *
	 * Se engancha como filtro porque es donde WordPress publica la metadata del
	 * adjunto recién procesado; la metadata se devuelve intacta.
	 *
	 * @param mixed $metadata      Metadata del adjunto.
	 * @param mixed $attachment_id ID del adjunto.
	 * @return mixed
	 */
	public function generate_on_upload( $metadata, $attachment_id ) {
		$dir = self::portadas_dir();
		if ( '' === $dir || ! is_array( $metadata ) || ! isset( $metadata['file'] ) ) {
			return $metadata;
		}

		$archivo = basename( (string) $metadata['file'] );
		if ( 1 !== preg_match( '/^([a-z0-9-]+)\.avif$/', $archivo, $matches )
			|| 1 === preg_match( '/-\d+x\d+\.avif$/', $archivo ) ) {
			return $metadata;
		}

		$source = $dir . '/' . $archivo;
		if ( is_readable( $source ) ) {
			$this->generate_for_slug( $matches[1] );
		}

		unset( $attachment_id );

		return $metadata;
	}

	/**
	 * Genera (o refresca) la variante de UNA portada. Es el trabajo que encola
	 * `ensure_for_slug()` y el que dispara la subida.
	 *
	 * @param string $slug Nombre de archivo sin extensión (== slug del producto).
	 */
	public function generate_for_slug( $slug ): void {
		$slug = (string) $slug;
		if ( 1 !== preg_match( '/^[a-z0-9-]+$/', $slug ) ) {
			return;
		}

		$dir = self::portadas_dir();
		if ( '' === $dir ) {
			return;
		}

		$source = $dir . '/' . $slug . '.avif';
		if ( ! is_readable( $source ) ) {
			return;
		}

		// La caché negativa se escribe ANTES de convertir y el éxito la borra. Al
		// revés sólo cubría los fallos que devuelven: una memoria agotada o un
		// tiempo máximo dentro de `imagecreatefromavif()` son fatales que
		// ningún `catch` ve, el proceso moría sin escribirla y cada reintento
		// —el siguiente correo, la siguiente visita que encola— volvía a
		// decodificar el mismo AVIF y a morir.
		set_transient( self::FAIL_PREFIX . $slug, 1, self::FAIL_TTL );

		if ( self::convert( $source, $dir . '/' . $slug . self::SUFFIX ) ) {
			delete_transient( self::FAIL_PREFIX . $slug );
		}

		// Si no se pudo, la caché negativa ya está puesta: un AVIF corrupto o un
		// directorio sin permiso de escritura no se arreglan reintentando en
		// cada request.
	}

	/**
	 * Directorio físico de las portadas, o '' si no existe.
	 */
	public static function portadas_dir(): string {
		if ( ! defined( 'WP_CONTENT_DIR' ) ) {
			return '';
		}
		$dir = WP_CONTENT_DIR . '/uploads/portadas';

		return is_dir( $dir ) ? $dir : '';
	}

	/**
	 * Devuelve una portada utilizable por el consumidor de correo.
	 *
	 * Para originales de uploads/portadas usa su hermana JPG y, si falta, intenta
	 * generarla inmediatamente dentro del presupuesto. Un trabajo encolado no
	 * cambiaría la URL ya capturada por el mensaje. Sin JPG utilizable conserva la
	 * URL original en vez de introducir un enlace roto; otras imágenes pasan intactas.
	 *
	 * @param string $url URL de la imagen.
	 * @return string
	 */
	public static function email_url( string $url ): string {
		$patron = '#/uploads/portadas/([a-z0-9-]+?)(?:-\d+x\d+)?\.avif$#';
		if ( 1 !== preg_match( $patron, $url, $matches ) || ! self::ensure_now( $matches[1] ) ) {
			return $url;
		}

		return (string) preg_replace( $patron, '/uploads/portadas/' . $matches[1] . self::SUFFIX, $url );
	}

	/**
	 * Genera las variantes `-og.jpg` que faltan (o cuya fuente es más nueva).
	 *
	 * Solo toca originales `slug.avif` sin sufijo de tamaño: las variantes
	 * `-200x300.avif` de WordPress no necesitan hermana social.
	 *
	 * Trocea de a `MAX_PER_RUN` conversiones y guarda por dónde iba, así que una
	 * corrida que se muere a la mitad no obliga a empezar de cero. El candado
	 * impide que dos ticks solapados hagan el mismo trabajo dos veces; como la
	 * conversión es idempotente, es una optimización, no una condición de
	 * corrección.
	 *
	 * @return int Variantes generadas en esta corrida.
	 */
	public function generate_missing(): int {
		$dir = self::portadas_dir();
		if ( '' === $dir || ! function_exists( 'imagecreatefromavif' ) ) {
			return 0; // Sin GD-AVIF no hay pipeline; el filtro social degrada solo.
		}

		if ( ! $this->claim_lock() ) {
			return 0;
		}

		try {
			$archivos = scandir( $dir );
			if ( false === $archivos ) {
				return 0;
			}
			// El cursor compara con strcmp(): el recorrido tiene que seguir ese
			// mismo orden aunque la intercalación del sistema sea otra.
			sort( $archivos, SORT_STRING );

			$cursor     = (string) get_option( self::CURSOR_OPTION, '' );
			$generated  = 0;
			$intentos   = 0;
			$ultimo     = $cursor;
			$pendientes = false;

			foreach ( $archivos as $file ) {
				// Temporales huérfanos ANTES del cursor, que si no los saltaría.
				if ( self::is_orphan_temporary( $dir, $file ) ) {
					unlink( $dir . '/' . $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- temporal propio en uploads.
					continue;
				}

				// Orden ascendente por bytes: el cursor es el último
				// archivo ya resuelto, y se retoma justo después.
				if ( '' !== $cursor && strcmp( $file, $cursor ) <= 0 ) {
					continue;
				}

				if ( 1 !== preg_match( '/^([a-z0-9-]+)\.avif$/', $file, $matches )
					|| 1 === preg_match( '/-\d+x\d+\.avif$/', $file ) ) {
					$ultimo = $file;
					continue;
				}

				$source = $dir . '/' . $file;
				$target = $dir . '/' . $matches[1] . self::SUFFIX;
				if ( self::sibling_is_fresh( $source, $target ) ) {
					$ultimo = $file;
					continue;
				}

				// El tope cuenta intentos, no éxitos: con conversiones que fallan
				// contar sólo las buenas decodificaba el directorio entero en una
				// corrida, lo que el tope existe para impedir.
				if ( $intentos >= self::MAX_PER_RUN ) {
					$pendientes = true;
					break;
				}

				++$intentos;
				if ( self::convert( $source, $target ) ) {
					++$generated;
				}
				$ultimo = $file;
			}

			if ( $pendientes ) {
				update_option( self::CURSOR_OPTION, $ultimo, false );
				// WP-Cron desagenda un evento suelto antes de correrlo: desde la
				// propia continuación esta pregunta ya da «no hay» y encadena.
				if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( self::HOOK_CONTINUE ) ) {
					wp_schedule_single_event( time() + 60, self::HOOK_CONTINUE );
				}
			} else {
				delete_option( self::CURSOR_OPTION );
			}

			return $generated;
		} finally {
			$this->release_lock();
		}
	}

	/**
	 * ¿Es un `*-og.jpg.tmp-PID` que ya nadie va a renombrar?
	 *
	 * `convert()` escribe a un temporal y lo renombra; si el proceso muere entre
	 * `imagejpeg()` y `rename()` —el mismo fatal por memoria o tiempo que motivó
	 * la caché negativa previa— el temporal queda para siempre, porque el barrido
	 * sólo miraba `.avif`. Se exige que tenga más de `LOCK_TTL` (15 min): una
	 * conversión dura ~38 ms, así que uno más joven puede ser de otro proceso que
	 * sigue vivo (un correo o una subida en paralelo).
	 *
	 * @param string $dir  Directorio de portadas.
	 * @param string $file Nombre de archivo.
	 */
	private static function is_orphan_temporary( string $dir, string $file ): bool {
		if ( 1 !== preg_match( '/^[a-z0-9-]+' . preg_quote( self::SUFFIX, '/' ) . '\.tmp-\d+$/', $file ) ) {
			return false;
		}

		$mtime = filemtime( $dir . '/' . $file );

		return false !== $mtime && ( time() - $mtime ) > self::LOCK_TTL;
	}

	/**
	 * Reclama el candado de corrida mediante SQL atómico y token propio.
	 *
	 * OptionRow comprueba la propiedad del candado en la base. add_option() no
	 * proporciona exclusión mutua: su INSERT ON DUPLICATE KEY UPDATE puede reemplazar
	 * la fila si la caché persistente anuncia erróneamente que no existe.
	 */
	private function claim_lock(): bool {
		$valor = time() . '|' . bin2hex( random_bytes( 8 ) );

		if ( ! OptionRow::insert_if_absent( self::LOCK_OPTION, $valor ) ) {
			$actual = OptionRow::read_raw( self::LOCK_OPTION );
			$tomado = null === $actual ? 0 : (int) explode( '|', $actual )[0];
			if ( null === $actual || ( $tomado > 0 && ( time() - $tomado ) < self::LOCK_TTL ) ) {
				return false;
			}
			// Candado huérfano: la corrida anterior murió sin soltarlo. Sólo lo roba
			// quien gane el cambio contra el valor exacto que leyó.
			if ( ! OptionRow::compare_and_swap( self::LOCK_OPTION, $actual, $valor ) ) {
				return false;
			}
		}
		$this->candado = $valor;

		return true;
	}

	/** Suelta el candado sólo si sigue siendo el suyo. */
	private function release_lock(): void {
		if ( '' === $this->candado ) {
			return;
		}
		OptionRow::delete_if_equals( self::LOCK_OPTION, $this->candado );
		$this->candado = '';
	}

	/**
	 * ¿Hay variante social utilizable para esta portada? Si no, la encola.
	 *
	 * Esto corre dentro del `wp_head` de una petición de FRONT, así que no
	 * transcodifica: antes lo hacía, y un decode AVIF completo entraba en el
	 * camino de render de un visitante; peor, si la escritura fallaba (AVIF
	 * corrupto, directorio sin permiso) no había caché negativa y el decode se
	 * repetía en cada request para siempre. Ahora la generación vive en la
	 * subida y en el cron, y desde acá sólo se encola el trabajo que falte.
	 *
	 * @param string $slug Nombre de archivo sin extensión (== slug del producto).
	 * @return bool Si HOY existe una variante utilizable.
	 */
	public static function ensure_for_slug( string $slug ): bool {
		if ( 1 !== preg_match( '/^[a-z0-9-]+$/', $slug ) ) {
			return false;
		}

		$dir = self::portadas_dir();
		if ( '' === $dir ) {
			return false;
		}

		$source = $dir . '/' . $slug . '.avif';
		$target = $dir . '/' . $slug . self::SUFFIX;
		$exists = file_exists( $target );

		// La variante manda. Si el AVIF original ya no está —se reemplazó la
		// portada, se limpió el directorio— la JPG que sí existe sigue siendo
		// una imagen social perfectamente utilizable, y devolver `false` aquí
		// haría que la ficha volviera a compartirse sin portada.
		if ( ! is_readable( $source ) ) {
			return $exists;
		}
		if ( self::sibling_is_fresh( $source, $target ) ) {
			return true;
		}

		self::enqueue_for_slug( $slug );

		// Una JPG vieja sigue mostrando la portada correcta salvo que la hayan
		// reemplazado; se prefiere a devolver un AVIF que WhatsApp no despliega.
		return $exists;
	}

	/**
	 * Genera la variante en el acto para consumidores que capturan esta URL una vez.
	 *
	 * Los eventos de carrito y workers de correo necesitan la imagen disponible al
	 * construir su contenido; una conversión posterior no actualiza esa referencia.
	 * La operación ocurre fuera del render de páginas, una vez por portada, con
	 * caché negativa para fallos y presupuesto MAX_NOW_PER_REQUEST. Los restantes
	 * trabajos pueden continuar por la cola.
	 *
	 * @param string $slug Nombre de archivo sin extensión (== slug del producto).
	 * @return bool Si HOY existe una variante utilizable.
	 */
	public static function ensure_now( string $slug ): bool {
		if ( 1 !== preg_match( '/^[a-z0-9-]+$/', $slug ) ) {
			return false;
		}

		$dir = self::portadas_dir();
		if ( '' === $dir ) {
			return false;
		}

		$source = $dir . '/' . $slug . '.avif';
		$target = $dir . '/' . $slug . self::SUFFIX;
		$exists = file_exists( $target );

		if ( ! is_readable( $source ) ) {
			return $exists;
		}
		if ( self::sibling_is_fresh( $source, $target ) ) {
			return true;
		}
		if ( get_transient( self::FAIL_PREFIX . $slug ) ) {
			return $exists;
		}
		if ( self::$now_conversions >= self::MAX_NOW_PER_REQUEST ) {
			// Presupuesto agotado: la conversión queda para el cron y quien
			// pregunta se lleva lo que hay hoy, igual que tras un fallo.
			self::enqueue_for_slug( $slug );

			return $exists;
		}

		++self::$now_conversions;
		( new self() )->generate_for_slug( $slug );
		clearstatcache( true, $target );

		return file_exists( $target );
	}

	/**
	 * Encola la generación de una portada, sin duplicar ni insistir sobre las
	 * que ya fallaron.
	 */
	private static function enqueue_for_slug( string $slug ): void {
		if ( ! function_exists( 'wp_schedule_single_event' ) || ! function_exists( 'wp_next_scheduled' ) ) {
			return;
		}
		if ( get_transient( self::FAIL_PREFIX . $slug ) ) {
			return;
		}
		if ( wp_next_scheduled( self::HOOK_SLUG, array( $slug ) ) ) {
			return;
		}

		wp_schedule_single_event( time(), self::HOOK_SLUG, array( $slug ) );
	}

	/**
	 * ¿La hermana existente vale tal cual, o hay que rehacerla?
	 *
	 * Vale si es posterior al AVIF Y cabe en el tope. La segunda condición es
	 * la que rehace las 367 hermanas de producción que salieron sin tope (hasta
	 * 5.596×8.394 px): con sólo el mtime ninguna se volvía a escribir, porque
	 * la fuente no cambia (revisión R8, H5). `getimagesize()` lee la cabecera,
	 * no el fichero: ~0,1 ms por hermana en el barrido diario. Pública porque
	 * el panel (`LlmsManifest::social_cover_status()`) cuenta pendientes con
	 * este MISMO criterio.
	 */
	public static function sibling_is_fresh( string $source, string $target ): bool {
		if ( ! file_exists( $target ) || filemtime( $target ) < filemtime( $source ) ) {
			return false;
		}
		$size = @getimagesize( $target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- una JPG ilegible se rehace, no se reporta.
		if ( ! is_array( $size ) ) {
			return false;
		}

		return max( (int) ( $size[0] ?? 0 ), (int) ( $size[1] ?? 0 ) ) <= self::MAX_SIDE;
	}

	/**
	 * AVIF → JPEG. Devuelve si el destino quedó escrito.
	 */
	private static function convert( string $source, string $target ): bool {
		if ( ! function_exists( 'imagecreatefromavif' ) ) {
			return false;
		}

		try {
			$image = imagecreatefromavif( $source );
			if ( false === $image ) {
				return false; // AVIF corrupto: se salta, no se aborta la corrida.
			}
			// La hermana social es una preview, no una copia del original. Acotar su
			// lado mayor para limitar tamaño y coste de transferencia en redes y correo.

			$width  = imagesx( $image );
			$height = imagesy( $image );
			$lado   = max( $width, $height );
			if ( $lado > self::MAX_SIDE ) {
				// Las medidas nuevas sólo mandan si el escalado se hizo: con el
				// original intacto, un lienzo de 1.200 px sería un RECORTE de la
				// portada, escrito con mtime fresco (revisión R8, H4).
				$factor = self::MAX_SIDE / $lado;
				$ancho  = max( 1, (int) round( $width * $factor ) );
				$alto   = max( 1, (int) round( $height * $factor ) );
				// Interpolación por defecto (bilineal fija): `IMG_BICUBIC`
				// devuelve `false` sobre un AVIF decodificado en el GD de la
				// suite, y antes del H4 ese `false` se servía como recorte.
				$scaled = imagescale( $image, $ancho, $alto );
				if ( false === $scaled ) {
					// Sin escalar NO se escribe: una hermana fuera de tope con
					// mtime fresco nunca sería «fresca» para `sibling_is_fresh()`
					// y se reconvertiría en cada petición; devolver false deja
					// la caché negativa puesta (revisión R8, 3.1).
					imagedestroy( $image );
					return false;
				}
				imagedestroy( $image );
				$image  = $scaled;
				$width  = $ancho;
				$height = $alto;
			}
			// JPEG no tiene alfa: fondo blanco explícito por si la portada
			// trae transparencia (los escaneos reales no, pero el formato lo admite).
			$canvas = imagecreatetruecolor( $width, $height );
			imagefill( $canvas, 0, 0, imagecolorallocate( $canvas, 255, 255, 255 ) );
			imagecopy( $canvas, $image, 0, 0, 0, 0, $width, $height );
			// A un temporal y después renombrar: una JPG truncada por un proceso
			// que muere a mitad tendría mtime nuevo y ningún barrido la rehacía.
			$temporal = $target . '.tmp-' . getmypid();
			$written  = imagejpeg( $canvas, $temporal, self::JPEG_QUALITY );
			imagedestroy( $canvas );
			imagedestroy( $image );

			if ( ! $written || ! rename( $temporal, $target ) ) {
				if ( file_exists( $temporal ) ) {
					unlink( $temporal ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- temporal propio en uploads.
				}
				return false;
			}

			return true;
		} catch ( \Throwable $throwable ) {
			return false;
		}
	}
}
