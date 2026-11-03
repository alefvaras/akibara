<?php
/**
 * Genera llms.txt y llms-full.txt con información canónica, secciones,
 * series y editoriales para asistentes de IA. No incluye precios ni
 * disponibilidad, que pueden cambiar antes de la regeneración diaria.
 * Publica archivos físicos validados en el docroot; WordPress sirve archivos
 * existentes antes del rewrite, por lo que tienen precedencia sobre el
 * handler de Rank Math. Retirarlos devuelve esa responsabilidad al handler.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\LlmsManifest;

use Akibara\Business\Utilities\OptionRow;

use Akibara\Business\Modules\AdminPanel\AdminPanel;
use Akibara\Business\Modules\SeoEnhancements\SeoEnhancements;
use Akibara\Business\Modules\SeoSocial\SocialImageDerivatives;
use Akibara\Business\Modules\SeriesCatalog\SeriesCatalogEligibility;
use Akibara\Business\Services\BrandsHubUrl;
use Akibara\Business\Services\SeriesHubUrl;
use Akibara\Business\Utilities\RobotsMeta;

defined( 'ABSPATH' ) || exit;

final class LlmsManifest {

	public const HOOK = 'akibara_business_llms_manifest_refresh';

	/** Última generación completa, almacenada sin autoload. */
	public const OPTION_LAST_SUCCESS = 'akibara_business_llms_manifest_last_success';

	/** Último fallo observado, almacenado sin autoload. */
	public const OPTION_LAST_ERROR = 'akibara_business_llms_manifest_last_error';

	/** Lease que evita dos fotografías concurrentes del catálogo. */
	public const OPTION_LOCK = 'akibara_business_llms_manifest_lock';

	/** Hashes de archivos que el módulo puede retirar con seguridad. */
	public const OPTION_MANAGED_FILES = 'akibara_business_llms_manifest_managed_files';

	/**
	 * Cómo se presenta la tienda a los agentes de IA (el `>` de llms.txt).
	 *
	 * Es la frase que un modelo cita cuando alguien le pregunta «¿qué es
	 * Akibara?»: dice el rubro, la cobertura, el retiro y los medios de pago. Era
	 * un literal en medio de 500 líneas de generación, así que cambiar de pasarela
	 * o de comuna de retiro obligaba a tocar código.
	 */
	public const OPTION_IDENTITY = 'akibara_llms_identity';

	/**
	 * Declaración de identidad canónica de fábrica.
	 *
	 * Utiliza «a pedido» para reflejar con precisión el catálogo de tomos por encargo,
	 * alineado con el vocabulario oficial de la tienda.
	 */
	public const DEFAULT_IDENTITY = 'Tienda online chilena especializada en manga y cómics originales en español, con tomos disponibles, a pedido e importación. Despacho a todo Chile y retiro coordinado en Metro San Miguel, Santiago. Pagos con Mercado Pago y Flow (Webpay).';

	/**
	 * Identidades de fábrica anteriores para migración automática.
	 *
	 * Permite que {@see self::migrate_factory_identity()} actualice instalaciones
	 * que conservan la identidad por defecto en base de datos.
	 *
	 * @var array<int, string>
	 */
	private const PREVIOUS_DEFAULT_IDENTITIES = array(
		'Tienda online chilena especializada en manga y cómics originales en español, con productos disponibles, preventas e importación. Despacho a todo Chile y retiro coordinado en Metro San Miguel, Santiago. Pagos con Mercado Pago y Flow (Webpay).',
	);

	/** Tope de la identidad: un resumen que no cabe en una línea deja de serlo. */
	private const IDENTITY_MAX_LENGTH = 600;

	/** Pestaña propia dentro del panel Akibara. */
	public const PANEL_TAB_ID = 'salud_seo';

	/** Acción de `admin-post.php` que encola una regeneración. */
	private const REGENERATE_ACTION = 'akibara_salud_seo_regenerar';

	/** Duración máxima de una generación antes de permitir recuperación. */
	private const LOCK_TTL = 30 * MINUTE_IN_SECONDS;

	/** Archivos físicos propiedad del módulo. */
	private const MANIFEST_FILENAMES = array( 'llms.txt', 'llms-full.txt' );

	/** Taxonomía de las landings de serie. */
	private const SERIES_TAXONOMY = 'pa_serie';

	/** Taxonomía nativa que oculta productos del catálogo público. */
	private const PRODUCT_VISIBILITY_TAXONOMY = 'product_visibility';

	/** Término nativo de productos excluidos del catálogo. */
	private const HIDDEN_FROM_CATALOG = 'exclude-from-catalog';

	/** Meta donde Rank Math guarda las directivas robots de un post. */
	private const ROBOTS_META_KEY = 'rank_math_robots';

	private SeriesCatalogEligibility $series_catalog_eligibility;

	private SeoEnhancements $seo_enhancements;

	private string $document_root;

	/** @var \Closure():int */
	private \Closure $clock;

	/** @var \Closure():string */
	private \Closure $token_factory;

	/** @var \Closure(string,mixed,mixed):bool */
	private \Closure $option_compare_and_swap;

	/**
	 * Inserta el lease sólo si la fila no existe (INSERT IGNORE, ver {@see OptionRow}).
	 *
	 * @var \Closure(string,mixed):bool
	 */
	private \Closure $option_insert_if_absent;

	/** @var \Closure(string,string):bool */
	private \Closure $file_replacer;

	/** @var array<int,true> Productos ya representados bajo una serie. */
	private array $represented_product_ids = array();

	/**
	 * Tomos de TODAS las series, resueltos de una vez y agrupados por término.
	 *
	 * @var array<int,array<int,object>>|null
	 */
	private ?array $volume_index = null;

	/**
	 * `rank_math_robots` por producto, cebado en UNA consulta por lote.
	 *
	 * @var array<int,mixed>
	 */
	private array $robots_index = array();

	/** Código del último fallo de lectura que obliga a preservar la foto previa. */
	private string $read_error_code = '';

	public function __construct(
		?SeriesCatalogEligibility $series_catalog_eligibility = null,
		?SeoEnhancements $seo_enhancements = null,
		?string $document_root = null,
		?callable $clock = null,
		?callable $token_factory = null,
		?callable $option_compare_and_swap = null,
		?callable $file_replacer = null,
		?callable $option_insert_if_absent = null
	) {
		$this->series_catalog_eligibility = $series_catalog_eligibility ?? new SeriesCatalogEligibility();
		$this->seo_enhancements           = $seo_enhancements ?? new SeoEnhancements(
			series_catalog_eligibility: $this->series_catalog_eligibility
		);
		$document_root                    = rtrim( $document_root ?? ABSPATH, '/\\' );
		$this->document_root              = '' === $document_root ? DIRECTORY_SEPARATOR : $document_root;
		$this->clock                      = null === $clock
			? static fn(): int => time()
			: \Closure::fromCallable( $clock );
		$this->token_factory              = null === $token_factory
			? static fn(): string => bin2hex( random_bytes( 16 ) )
			: \Closure::fromCallable( $token_factory );
		$this->option_compare_and_swap    = null === $option_compare_and_swap
			? \Closure::fromCallable( array( $this, 'compare_and_swap_option' ) )
			: \Closure::fromCallable( $option_compare_and_swap );
		$this->option_insert_if_absent    = null === $option_insert_if_absent
			? static fn( string $option, $value ): bool => OptionRow::insert_if_absent( $option, $value )
			: \Closure::fromCallable( $option_insert_if_absent );
		$this->file_replacer              = null === $file_replacer
			? static fn( string $target, string $contents ): bool => self::replace_file_atomically( $target, $contents )
			: \Closure::fromCallable( $file_replacer );
	}

	public function register(): void {
		add_action( self::HOOK, array( $this, 'write_manifests' ) );

		// Telemetría administrativa: expone la última generación exitosa o error
		// en el panel administrativo mediante la API pública de secciones.
		add_action( 'akibara_business_booted', array( $this, 'register_admin_section' ) );
		add_action( 'admin_post_' . self::REGENERATE_ACTION, array( $this, 'handle_regenerate_request' ) );
		add_action( 'admin_notices', array( $this, 'render_regenerate_notice' ) );
		add_filter(
			'woocommerce_admin_settings_sanitize_option_' . self::OPTION_IDENTITY,
			array( $this, 'sanitize_identity' ),
			10,
			3
		);
		// Cambiar la identidad encola la misma regeneración del botón del panel.
		// Se escuchan add_option_{option} y update_option_{option}, ambos emitidos
		// después de persistir: la primera alta puede venir de update_option.
		// Las variantes added_option y updated_option no llevan sufijo de option.
		// El catálogo se recorre fuera de la petición administrativa.
		add_action( 'add_option_' . self::OPTION_IDENTITY, array( $this, 'queue_refresh_after_identity_change' ) );
		add_action( 'update_option_' . self::OPTION_IDENTITY, array( $this, 'queue_refresh_after_identity_change' ) );
		add_action( 'akibara_business_upgraded', array( self::class, 'migrate_factory_identity' ), 10, 0 );
		add_action( 'akibara_business_upgraded', array( self::class, 'drop_identity_autoload' ), 10, 0 );

		if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/**
	 * Cuelga la pestaña «Salud SEO» del panel Akibara.
	 *
	 * @internal Callback de `akibara_business_booted`.
	 *
	 * @param mixed $plugin Instancia del plugin.
	 * @return void
	 */
	public function register_admin_section( $plugin = null ): void {
		if ( ! is_object( $plugin ) || ! method_exists( $plugin, 'module' ) ) {
			return;
		}
		$panel = $plugin->module( 'admin_panel' );
		if ( ! is_object( $panel ) || ! method_exists( $panel, 'add_section' ) ) {
			return;
		}

		// Sección CON formulario, no «action section». Nació sin él —sólo estado y
		// botones— y colgarle un campo de un textarea a una sección declarada
		// `is_action = true` lo pinta pero NO lo guarda: `AdminPanel::handle_save()`
		// vuelve temprano para esas secciones. El patrón es el de «Pedidos y
		// preventa»: `get_settings()` aporta el form y el render de estado pasa a
		// ser el header, encima del formulario y de sus botones.
		$panel->add_section(
			self::PANEL_TAB_ID,
			'Salud SEO',
			array( $this, 'get_settings' ),
			array( $this, 'render_admin_section' ),
			false,
			'',
			'sistema'
		);
	}

	/**
	 * Estado de las dos superficies SEO que se generan solas, y su botón.
	 *
	 * @internal Callback de `AdminPanel::add_section($header_fn)`.
	 *
	 * @return void
	 */
	public function render_admin_section(): void {
		$success = get_option( self::OPTION_LAST_SUCCESS );
		$error   = get_option( self::OPTION_LAST_ERROR );
		$covers  = self::social_cover_status();
		?>
		<h3>llms.txt y llms-full.txt</h3>
		<p>
			<?php if ( is_array( $success ) && isset( $success['timestamp'] ) ) : ?>
				Última generación correcta: <strong><?php echo esc_html( self::format_moment( (int) $success['timestamp'] ) ); ?></strong>
				— <?php echo esc_html( sprintf( '%d series, %d guías, %d productos sueltos', (int) ( $success['series'] ?? 0 ), (int) ( $success['guides'] ?? 0 ), (int) ( $success['products'] ?? 0 ) ) ); ?>.
			<?php else : ?>
				<strong>Nunca se ha generado.</strong> El sitio sigue sirviendo el llms.txt de Rank Math.
			<?php endif; ?>
		</p>
		<?php if ( is_array( $error ) && isset( $error['code'] ) ) : ?>
			<div class="notice notice-warning inline"><p>
				<?php // `record_success()` borra esta opción al terminar bien: si está, la ÚLTIMA corrida falló. ?>
				<strong>La última corrida falló</strong> y el sitio sigue sirviendo el archivo anterior.
				Motivo: <code><?php echo esc_html( (string) $error['code'] ); ?></code>
				(<?php echo esc_html( self::format_moment( (int) ( $error['timestamp'] ?? 0 ) ) ); ?>).
				<?php if ( '' !== (string) ( $error['message'] ?? '' ) ) : ?>
					<br><?php echo esc_html( (string) $error['message'] ); ?>
				<?php endif; ?>
			</p></div>
		<?php else : ?>
			<p class="description">Sin fallos registrados.</p>
		<?php endif; ?>
		<p>Próxima corrida automática: <strong><?php echo esc_html( self::next_run_label( self::HOOK ) ); ?></strong>.</p>
		<p>
			<a class="button" href="<?php echo esc_url( self::regenerate_url( 'llms' ) ); ?>">Regenerar llms.txt ahora</a>
		</p>

		<h3>Portadas para previsualizaciones sociales</h3>
		<?php if ( ! $covers['available'] ) : ?>
			<p class="description">No se puede leer <code>/uploads/portadas/</code> desde aquí.</p>
		<?php else : ?>
			<p>
				<?php echo esc_html( sprintf( '%d portadas originales; %d sin su variante -og.jpg.', $covers['total'], $covers['missing'] ) ); ?>
			</p>
			<p class="description">
				WhatsApp y X no despliegan AVIF en las previsualizaciones de enlace:
				una portada sin variante JPG se comparte sin imagen.
			</p>
			<p>Próxima corrida automática: <strong><?php echo esc_html( self::next_run_label( SocialImageDerivatives::HOOK ) ); ?></strong>.</p>
			<p>
				<a class="button" href="<?php echo esc_url( self::regenerate_url( 'portadas' ) ); ?>">Generar las portadas que faltan</a>
			</p>
		<?php endif; ?>
		<p class="description">
			Los dos botones ENCOLAN el trabajo (no lo ejecutan en esta pantalla):
			el cron de WordPress lo toma en la siguiente visita al sitio.
		</p>
		<?php
	}

	/**
	 * Cuántas portadas originales existen y cuántas no tienen hermana social.
	 *
	 * Un `scandir` del directorio de portadas, con el MISMO criterio de
	 * `SocialImageDerivatives::generate_missing()`: sólo originales `slug.avif`,
	 * nunca las variantes `-200x300.avif` de WordPress, y «pendiente» es lo que
	 * el barrido va a rehacer —sin hermana, más vieja que el AVIF o fuera del
	 * tope de `MAX_SIDE` (`sibling_is_fresh()`; revisión R8, 3.2)—. Corre
	 * únicamente al renderizar esta pestaña.
	 *
	 * @return array{available:bool,total:int,missing:int}
	 */
	private static function social_cover_status(): array {
		$directory = SocialImageDerivatives::portadas_dir();
		if ( '' === $directory ) {
			return array(
				'available' => false,
				'total'     => 0,
				'missing'   => 0,
			);
		}

		$files = scandir( $directory );
		if ( ! is_array( $files ) ) {
			return array(
				'available' => false,
				'total'     => 0,
				'missing'   => 0,
			);
		}

		$total   = 0;
		$missing = 0;
		foreach ( $files as $file ) {
			if ( 1 !== preg_match( '/^([a-z0-9-]+)\.avif$/', (string) $file, $matches )
				|| 1 === preg_match( '/-\d+x\d+\.avif$/', (string) $file ) ) {
				continue;
			}
			++$total;
			if ( ! SocialImageDerivatives::sibling_is_fresh( $directory . '/' . $file, $directory . '/' . $matches[1] . SocialImageDerivatives::SUFFIX ) ) {
				++$missing;
			}
		}

		return array(
			'available' => true,
			'total'     => $total,
			'missing'   => $missing,
		);
	}

	/** Fecha local legible de un instante UNIX, o «desconocida». */
	private static function format_moment( int $timestamp ): string {
		if ( $timestamp < 1 ) {
			return 'fecha desconocida';
		}

		return (string) wp_date( 'j \d\e F \d\e Y, H:i', $timestamp );
	}

	/** Cuándo corre el próximo evento de un hook agendado. */
	private static function next_run_label( string $hook ): string {
		$next = function_exists( 'wp_next_scheduled' ) ? wp_next_scheduled( $hook ) : false;

		return is_int( $next ) && $next > 0 ? self::format_moment( $next ) : 'sin agendar';
	}

	/** URL firmada que encola una regeneración. */
	private static function regenerate_url( string $target ): string {
		$url = add_query_arg(
			array(
				'action' => self::REGENERATE_ACTION,
				'target' => $target,
			),
			admin_url( 'admin-post.php' )
		);

		return wp_nonce_url( $url, self::REGENERATE_ACTION . '_' . $target );
	}

	/**
	 * Campo de la pestaña: la identidad que leen los agentes de IA.
	 *
	 * @internal Callback de `AdminPanel::add_section($settings_fn)`.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_settings(): array {
		return array(
			// Encabezado inicial de pestaña para estructura estándar de WooCommerce Admin Settings.
			array(
				'title' => 'Salud SEO',
				'type'  => 'title',
				'id'    => 'akb_llms_tab_head_sacrificable',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'akb_llms_tab_head_sacrificable',
			),
			array(
				'title' => 'Cómo se presenta la tienda a los agentes de IA',
				'type'  => 'title',
				'id'    => 'akb_llms_identity_section',
				'desc'  => 'Es la primera frase de <code>llms.txt</code>: lo que ChatGPT, Claude o Perplexity citan cuando alguien pregunta qué es Akibara. '
					. 'Al guardar se encola la regeneración del archivo.',
			),
			array(
				'title'    => 'Descripción de la tienda',
				'desc'     => 'Una sola frase, sin saltos de línea (se guardan en una línea igual). Di el rubro, la cobertura de despacho, el retiro y los medios de pago: son las cuatro cosas que un agente necesita para recomendarte con precisión. Vacío vuelve a la de fábrica.',
				'desc_tip' => false,
				'id'       => self::OPTION_IDENTITY,
				'type'     => 'textarea',
				'default'  => self::DEFAULT_IDENTITY,
				// Sólo la leen la generación de llms.txt (cola) y esta pantalla:
				// guardada sin esto, el panel la devolvía a `alloptions` (SRV-4).
				'autoload' => false,
				'css'      => 'width: min(100%, 680px); min-height: 110px;',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'akb_llms_identity_section',
			),
		);
	}

	/**
	 * Identidad vigente, ya normalizada a una línea.
	 *
	 * Vacía cae al literal de siempre: un `llms.txt` sin resumen deja de cumplir
	 * el formato y el agente se queda sin la única frase que lo describe.
	 */
	public static function identity(): string {
		$stored = get_option( self::OPTION_IDENTITY, self::DEFAULT_IDENTITY );
		$stored = is_string( $stored ) ? self::normalize_identity_text( $stored ) : '';

		return '' !== $stored ? $stored : self::DEFAULT_IDENTITY;
	}

	/**
	 * Lleva a la identidad de fábrica vigente la que quedó guardada con una de
	 * fábrica anterior. Lo que el dueño escribió él mismo no se toca.
	 *
	 * El `update_option()` dispara `update_option_{OPTION_IDENTITY}`, que encola
	 * la regeneración de llms.txt: el archivo servido cambia en el siguiente
	 * cron, sin esperar al refresco diario.
	 *
	 * @internal Callback de `akibara_business_upgraded` (idempotente: con la
	 *           frase ya al día no hace nada).
	 *
	 * @return bool Si reemplazó la guardada.
	 */
	public static function migrate_factory_identity(): bool {
		$stored = get_option( self::OPTION_IDENTITY, null );
		if ( ! is_string( $stored ) ) {
			return false;
		}

		$stored = self::normalize_identity_text( $stored );
		foreach ( self::PREVIOUS_DEFAULT_IDENTITIES as $previous ) {
			if ( $stored === $previous ) {
				return (bool) update_option( self::OPTION_IDENTITY, self::DEFAULT_IDENTITY, false );
			}
		}

		return false;
	}

	/**
	 * Remueve la opción de identidad de `alloptions` (`autoload = 'no'`).
	 *
	 * Evita sobrecargar la memoria en cada petición del frontend al limitar
	 * su lectura a procesos en segundo plano y paneles administrativos.
	 *
	 * @internal Callback de `akibara_business_upgraded` (idempotente).
	 *
	 * @return bool Si cambió algo.
	 */
	public static function drop_identity_autoload(): bool {
		return (bool) wp_set_option_autoload( self::OPTION_IDENTITY, false );
	}

	/**
	 * Normaliza la identidad al guardarla, con la MISMA función que la sirve.
	 *
	 * @internal Callback de `woocommerce_admin_settings_sanitize_option_*`.
	 *
	 * @param mixed               $value     Valor preprocesado por WooCommerce.
	 * @param array<string,mixed> $option    Definición del ajuste.
	 * @param mixed               $raw_value Valor crudo del formulario.
	 */
	public function sanitize_identity( $value, array $option = array(), $raw_value = null ): string {
		unset( $option );
		$candidate = null !== $raw_value ? $raw_value : $value;
		if ( ! is_string( $candidate ) ) {
			return self::DEFAULT_IDENTITY;
		}

		$clean = self::normalize_identity_text( $candidate );

		return '' !== $clean ? $clean : self::DEFAULT_IDENTITY;
	}

	/**
	 * Deja el texto en UNA línea de Markdown seguro para el bloque `>`.
	 *
	 * No reusa `normalize_markdown_text()` a propósito: aquélla escapa corchetes
	 * y paréntesis para que un título no rompa un enlace, y acá convertiría
	 * «Flow (Webpay)» en «Flow \(Webpay\)» — o sea, cambiaría el archivo servido
	 * el día que se estrenó la pantalla. Esto es prosa, no una etiqueta de enlace.
	 */
	private static function normalize_identity_text( string $text ): string {
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = strip_tags( $text );
		$text = preg_replace( '/[\p{Cc}\p{Cf}\p{Z}]+/u', ' ', $text ) ?? '';
		$text = trim( preg_replace( '/\s+/u', ' ', $text ) ?? '' );

		if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
			if ( mb_strlen( $text, 'UTF-8' ) > self::IDENTITY_MAX_LENGTH ) {
				$text = rtrim( mb_substr( $text, 0, self::IDENTITY_MAX_LENGTH - 1, 'UTF-8' ) ) . '…';
			}
		} elseif ( strlen( $text ) > self::IDENTITY_MAX_LENGTH ) {
			$text = rtrim( substr( $text, 0, self::IDENTITY_MAX_LENGTH - 3 ) ) . '...';
		}

		return $text;
	}

	/**
	 * Encola la regeneración cuando cambia la identidad.
	 *
	 * @internal Callback de `add_option_{$option}` / `update_option_{$option}`.
	 *
	 * @return void
	 */
	public function queue_refresh_after_identity_change(): void {
		wp_schedule_single_event( ( $this->clock )(), self::HOOK );
	}

	/**
	 * Encola la regeneración pedida y vuelve a la pestaña.
	 *
	 * ENCOLA, no ejecuta: `write_manifests()` recorre el catálogo entero y
	 * `generate_missing()` puede convertir miles de imágenes. Hacerlo dentro del
	 * request del admin sería una pantalla en blanco hasta el timeout de PHP.
	 *
	 * @internal Callback de `admin_post_akibara_salud_seo_regenerar`.
	 *
	 * @return void
	 */
	public function handle_regenerate_request(): void {
		$target = isset( $_GET['target'] ) ? sanitize_key( wp_unslash( (string) $_GET['target'] ) ) : '';
		if ( ! in_array( $target, array( 'llms', 'portadas' ), true ) ) {
			wp_die( 'Regeneración no reconocida.' );
		}
		check_admin_referer( self::REGENERATE_ACTION . '_' . $target );
		if ( ! current_user_can( AdminPanel::PANEL_CAP ) ) {
			wp_die( 'Sin permisos.' );
		}

		$hook   = 'llms' === $target ? self::HOOK : SocialImageDerivatives::HOOK;
		$queued = (bool) wp_schedule_single_event( ( $this->clock )(), $hook );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => 'akibara-panel',
					'tab'       => self::PANEL_TAB_ID,
					'akb_salud' => $queued ? 'encolado' : 'ya-encolado',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Aviso del resultado tras pulsar un botón de regeneración.
	 *
	 * @internal Callback de `admin_notices`.
	 *
	 * @return void
	 */
	public function render_regenerate_notice(): void {
		$result = isset( $_GET['akb_salud'] ) ? sanitize_key( wp_unslash( (string) $_GET['akb_salud'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- bandera de sólo lectura puesta por nuestro propio wp_safe_redirect; no cambia estado.
		$message = match ( $result ) {
			// NO «en la siguiente visita al sitio»: producción corre con
			// `DISABLE_WP_CRON`, así que el tráfico no empuja nada. Quien dispara
			// el cron es la tarea del panel de Hostinger, cada 5 minutos.
			'encolado'    => array( 'success', 'Regeneración encolada. La ejecuta el cron del servidor en los próximos minutos; vuelve a esta pantalla para ver la fecha nueva.' ),
			'ya-encolado' => array( 'info', 'Ya había una regeneración encolada para ese trabajo; no se duplicó.' ),
			default       => null,
		};
		if ( null === $message ) {
			return;
		}

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $message[0] ),
			esc_html( $message[1] )
		);
	}

	/**
	 * Escribe /llms.txt y /llms-full.txt en el docroot.
	 *
	 * Cualquier fallo deja el archivo anterior en pie: peor un manifiesto de
	 * ayer que ninguno, y el handler de Rank Math sigue debajo como red.
	 */
	public function write_manifests(): void {
		$lock_token = null;
		try {
			$lock_token = $this->acquire_lock();
			if ( null === $lock_token ) {
				return;
			}

			$this->read_error_code = '';
			$series = $this->series_entries();
			if ( null === $series ) {
				$this->record_error( '' !== $this->read_error_code ? $this->read_error_code : 'series_catalog_unreadable' );
				return;
			}
			$guides = $this->guide_entries();
			if ( null === $guides ) {
				$this->record_error( '' !== $this->read_error_code ? $this->read_error_code : 'guide_query_unreadable' );
				return;
			}
			$products = $this->standalone_product_entries();
			if ( null === $products ) {
				$this->record_error( '' !== $this->read_error_code ? $this->read_error_code : 'product_query_unreadable' );
				return;
			}
			$brands = $this->brand_entries();
			if ( null === $brands ) {
				$this->record_error( '' !== $this->read_error_code ? $this->read_error_code : 'brand_query_unreadable' );
				return;
			}

			$hubs             = self::hub_urls();
			$full_contents    = self::render( $series, $guides, true, $products, $brands, $hubs );
			$compact_contents = self::render( $series, $guides, false, $products, $brands, $hubs );
			$full_target      = $this->document_root . '/llms-full.txt';
			$full_preimage    = self::file_preimage( $full_target );
			if ( null === $full_preimage ) {
				$this->record_error( 'llms_full_preimage_unreadable' );
				return;
			}

			// El extendido se reemplaza primero: el compacto nunca debe enlazar una
			// versión nueva mientras el destino todavía sea la foto anterior.
			if ( ! $this->authorize_managed_file( 'llms-full.txt', $full_contents ) ) {
				$this->record_error( 'managed_file_registry_unwritable' );
				return;
			}
			if ( ! ( $this->file_replacer )( $full_target, $full_contents ) ) {
				$this->record_error( 'llms_full_write_failed' );
				return;
			}
			if ( ! $this->authorize_managed_file( 'llms.txt', $compact_contents ) ) {
				$this->record_error(
					self::rollback_file( $full_target, $full_contents, $full_preimage, $this->file_replacer )
						? 'managed_file_registry_unwritable_rolled_back'
						: 'managed_file_registry_unwritable_rollback_failed'
				);
				return;
			}
			if ( ! ( $this->file_replacer )( $this->document_root . '/llms.txt', $compact_contents ) ) {
				$this->record_error(
					self::rollback_file( $full_target, $full_contents, $full_preimage, $this->file_replacer )
						? 'llms_compact_write_failed_rolled_back'
						: 'llms_compact_write_failed_rollback_failed'
				);
				return;
			}

			$this->seal_managed_files( $full_contents, $compact_contents );
			$this->record_success( $series, $guides, $products, $full_contents, $compact_contents );
		} catch ( \Throwable $throwable ) {
			$this->record_error( 'unexpected_exception', $throwable );
		} finally {
			if ( null !== $lock_token && ! $this->release_lock( $lock_token ) ) {
				$this->record_error( 'lock_release_failed' );
			}
		}
	}

	/**
	 * Editoriales (marcas) con productos: nombre, landing canónica y nota descriptiva.
	 * Ordenadas por volumen de catálogo publicado.
	 *
	 * @return array<int,array{name:string,url:string,note:string}>|null Null cuando la lectura no es confiable.
	 */
	private function brand_entries(): ?array {
		$terms = $this->query_terms_reliably(
			array(
				'taxonomy'   => 'product_brand',
				'hide_empty' => true,
				'orderby'    => 'count',
				'order'      => 'DESC',
			)
		);
		if ( null === $terms ) {
			return null;
		}

		$entries = array();
		foreach ( $terms as $term ) {
			if ( ! is_object( $term ) || ! isset( $term->term_id, $term->name ) || (int) ( $term->count ?? 0 ) < 1 ) {
				continue;
			}
			if ( ! RobotsMeta::is_indexable( get_term_meta( (int) $term->term_id, 'rank_math_robots', true ) ) ) {
				continue;
			}
			$url = get_term_link( $term );
			if ( ! is_string( $url ) || '' === $url ) {
				continue;
			}

			$count = (int) $term->count;
			$note  = 1 === $count ? '1 título catalogado en Akibara Chile.' : sprintf( '%d títulos catalogados en Akibara Chile.', $count );
			$intro = trim( wp_strip_all_tags( (string) ( $term->description ?? '' ) ) );
			if ( '' !== $intro ) {
				if ( function_exists( 'mb_substr' ) && function_exists( 'mb_strlen' ) && mb_strlen( $intro, 'UTF-8' ) > 155 ) {
					$intro = rtrim( mb_substr( $intro, 0, 154, 'UTF-8' ) ) . '…';
				}
				$note = $intro . ' ' . $note;
			}

			$entries[] = array(
				'name' => (string) $term->name,
				'url'  => $url,
				'note' => $note,
			);
		}

		return $entries;
	}

	/**
	 * Series con productos: nombre, URL, nota corta y (en full) sus tomos.
	 *
	 * La nota utiliza la meta descripción canónica del término de serie,
	 * garantizando un resumen estructurado y conciso de ≤155 caracteres.
	 *
	 * @return array<int,array{name:string,url:string,note:string,volumes:array<int,array{title:string,url:string}>}>|null Null cuando la lectura no es confiable.
	 */
	private function series_entries(): ?array {
		$this->represented_product_ids = array();
		$this->robots_index            = array();
		$terms = $this->query_terms_reliably(
			array(
				'taxonomy'   => self::SERIES_TAXONOMY,
				'hide_empty' => true,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);
		if ( null === $terms ) {
			return null;
		}
		if ( array() === $terms ) {
			return array();
		}

		$term_taxonomy_ids = array();
		foreach ( $terms as $term ) {
			$term_taxonomy_id = is_object( $term ) && isset( $term->term_taxonomy_id )
				? (int) $term->term_taxonomy_id
				: 0;
			if ( $term_taxonomy_id > 0 ) {
				$term_taxonomy_ids[] = $term_taxonomy_id;
			}
		}
		if ( array() === $term_taxonomy_ids ) {
			$this->read_error_code = 'series_terms_unreadable';
			return null;
		}

		$counts = $this->series_catalog_eligibility->counts( $term_taxonomy_ids );
		if ( ! $this->series_catalog_eligibility->is_reliable() ) {
			$this->read_error_code = 'series_counts_unreliable';
			return null;
		}

		// Primer barrido: sólo el filtro barato (conteo publicable + robots).
		// Sirve para conocer el conjunto EXACTO de series antes de pedir sus
		// tomos, y así pedirlos todos juntos en vez de una consulta por serie.
		$eligible = array();
		foreach ( $terms as $term ) {
			if ( ! is_object( $term ) || ! isset( $term->term_id, $term->term_taxonomy_id, $term->name ) ) {
				continue;
			}
			$term_taxonomy_id = (int) $term->term_taxonomy_id;
			if ( ( $counts[ $term_taxonomy_id ] ?? 0 ) < 1 || ! RobotsMeta::is_indexable( get_term_meta( (int) $term->term_id, 'rank_math_robots', true ) ) ) {
				continue;
			}
			$eligible[] = $term;
		}

		$this->volume_index = $this->prime_volume_index(
			array_map( static fn( object $term ): int => (int) $term->term_id, $eligible )
		);
		if ( null === $this->volume_index ) {
			return null;
		}

		$entries = array();
		foreach ( $eligible as $term ) {
			$url = get_term_link( $term );
			if ( ! is_string( $url ) || '' === $url ) {
				continue;
			}

			$note = trim( $this->seo_enhancements->series_manifest_description( $term ) );
			if ( '' === $note ) {
				$note = trim( wp_strip_all_tags( (string) ( $term->description ?? '' ) ) );
				if ( function_exists( 'mb_substr' ) && function_exists( 'mb_strlen' ) && mb_strlen( $note, 'UTF-8' ) > 155 ) {
					$note = rtrim( mb_substr( $note, 0, 154, 'UTF-8' ) ) . '…';
				}
			}

			$entries[] = array(
				'name'    => (string) $term->name,
				'url'     => $url,
				'note'    => $note,
				'volumes' => $this->volume_entries( (int) $term->term_id ),
			);
		}

		usort( $entries, static fn( array $a, array $b ): int => strcasecmp( $a['name'], $b['name'] ) );

		return $entries;
	}

	/**
	 * Ejecuta get_terms distinguiendo cero términos de un fallo silenciado por WP_Term_Query.
	 *
	 * @param array<string,mixed> $args Argumentos de get_terms.
	 * @return array<int,object>|null Términos o null cuando la lectura no es confiable.
	 */
	private function query_terms_reliably( array $args ): ?array {
		global $wpdb;

		$observes_database = is_object( $wpdb ) && property_exists( $wpdb, 'last_error' );
		if ( $observes_database ) {
			$wpdb->last_error = '';
		}

		$terms          = get_terms( $args );
		$database_error = $observes_database ? trim( (string) $wpdb->last_error ) : '';
		$database_ready = ! is_object( $wpdb ) || ! property_exists( $wpdb, 'ready' ) || (bool) $wpdb->ready;
		if ( ! is_array( $terms ) || '' !== $database_error || ! $database_ready ) {
			$this->read_error_code = 'series_terms_query_unreadable';
			return null;
		}

		return $terms;
	}

	/**
	 * Resuelve los tomos de todas las series de forma consolidada.
	 *
	 * Agrupa las obras en consultas optimizadas por lotes para evitar problemas N+1
	 * durante la generación de los manifiestos, ordenando por título ascendente.
	 *
	 * @param array<int,int> $term_ids Series ya filtradas por conteo y robots.
	 * @return array<int,array<int,object>>|null Null cuando la lectura no es confiable.
	 */
	private function prime_volume_index( array $term_ids ): ?array {
		if ( array() === $term_ids ) {
			return array();
		}

		$posts = $this->query_posts_reliably(
			array(
				'post_type'     => 'product',
				'post_status'   => 'publish',
				'numberposts'   => -1,
				'orderby'       => 'title',
				'order'         => 'ASC',
				'no_found_rows' => true,
				// El manifiesto lee UNA clave por producto (`rank_math_robots`) y
				// el cebado por defecto trae el postmeta COMPLETO de los ~1.566
				// productos: +85 MB y +545 ms en la corrida diaria, para descartar
				// todo salvo esa clave. `prime_robots_meta()` la resuelve en una
				// sola consulta.
				'update_post_meta_cache' => false,
				'tax_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Corre solo en el cron diario, nunca en un request de visitante.
					'relation' => 'AND',
					array(
						'taxonomy' => self::SERIES_TAXONOMY,
						'field'    => 'term_id',
						'terms'    => $term_ids,
					),
					array(
						'taxonomy' => self::PRODUCT_VISIBILITY_TAXONOMY,
						'field'    => 'slug',
						'terms'    => self::HIDDEN_FROM_CATALOG,
						'operator' => 'NOT IN',
					),
				),
			),
			'series_volume_query_unreadable'
		);
		if ( null === $posts ) {
			return null;
		}

		$product_ids = array();
		foreach ( $posts as $post ) {
			if ( is_object( $post ) && isset( $post->ID ) && (int) $post->ID > 0 ) {
				$product_ids[] = (int) $post->ID;
			}
		}

		$index = array_fill_keys( $term_ids, array() );
		if ( array() === $product_ids ) {
			return $index;
		}

		$relations = $this->query_object_terms_reliably( $product_ids );
		if ( null === $relations ) {
			return null;
		}

		// Después de las relaciones a propósito: si la agrupación ya falló, no
		// hay manifiesto que publicar y esta consulta sería trabajo tirado.
		if ( ! $this->prime_robots_meta( $product_ids ) ) {
			$this->read_error_code = 'series_volume_robots_unreadable';
			return null;
		}

		// Un producto puede pertenecer a varias series: el mapa es 1→N y el
		// desempate (qué serie lo lista) lo sigue decidiendo
		// `represented_product_ids` en el orden alfabético del bucle, igual que antes.
		$series_of_product = array();
		foreach ( $relations as $relation ) {
			if ( ! is_object( $relation ) || ! isset( $relation->object_id, $relation->term_id ) ) {
				continue;
			}
			$series_of_product[ (int) $relation->object_id ][] = (int) $relation->term_id;
		}

		foreach ( $posts as $post ) {
			$product_id = is_object( $post ) && isset( $post->ID ) ? (int) $post->ID : 0;
			foreach ( $series_of_product[ $product_id ] ?? array() as $term_id ) {
				if ( array_key_exists( $term_id, $index ) ) {
					$index[ $term_id ][] = $post;
				}
			}
		}

		return $index;
	}

	/**
	 * `wp_get_object_terms` distinguiendo «sin relaciones» de «la base falló».
	 *
	 * Misma disciplina fail-closed que `query_posts_reliably()`: una lectura
	 * degradada tiene que abortar la generación y conservar el manifiesto de
	 * ayer, nunca publicar uno con series vacías.
	 *
	 * @param array<int,int> $product_ids Productos ya resueltos.
	 * @return array<int,object>|null Relaciones o null si la lectura no es confiable.
	 */
	private function query_object_terms_reliably( array $product_ids ): ?array {
		global $wpdb;

		$observes_database = is_object( $wpdb ) && property_exists( $wpdb, 'last_error' );
		if ( $observes_database ) {
			$wpdb->last_error = '';
		}

		$relations      = wp_get_object_terms( $product_ids, self::SERIES_TAXONOMY, array( 'fields' => 'all_with_object_id' ) );
		$database_error = $observes_database ? trim( (string) $wpdb->last_error ) : '';
		$database_ready = ! is_object( $wpdb ) || ! property_exists( $wpdb, 'ready' ) || (bool) $wpdb->ready;
		if ( ! is_array( $relations ) || '' !== $database_error || ! $database_ready
			|| ( function_exists( 'is_wp_error' ) && is_wp_error( $relations ) ) ) {
			$this->read_error_code = 'series_volume_relations_unreadable';
			return null;
		}

		return $relations;
	}

	/**
	 * Tomos publicados de una serie (solo para el manifiesto full).
	 *
	 * Lee del índice ya resuelto por `prime_volume_index()`; no consulta nada.
	 *
	 * @param int $term_id Término de la serie.
	 * @return array<int,array{title:string,url:string}>
	 */
	private function volume_entries( int $term_id ): array {
		$posts = $this->volume_index[ $term_id ] ?? array();

		$volumes = array();
		foreach ( $posts as $post ) {
			$product_id = is_object( $post ) && isset( $post->ID ) ? (int) $post->ID : 0;
			if ( $product_id < 1 || isset( $this->represented_product_ids[ $product_id ] )
				|| ! isset( $post->post_title )
				|| ! $this->product_is_indexable( $product_id )
				|| self::is_password_protected( $post ) ) {
				continue;
			}
			$permalink = get_permalink( $post );
			if ( is_string( $permalink ) && '' !== $permalink ) {
				$this->represented_product_ids[ $product_id ] = true;
				$volumes[] = array(
					'title' => (string) $post->post_title,
					'url'   => $permalink,
				);
			}
		}

		return $volumes;
	}

	/**
	 * Productos indexables que todavía no fueron representados bajo una serie.
	 *
	 * La diferencia se calcula contra IDs realmente renderizados, no contra la
	 * taxonomía inferida. Así también cubre una consulta de volúmenes que falle,
	 * productos sin `pa_serie` y productos cuya serie sea noindex.
	 *
	 * @return array<int,array{title:string,url:string}>|null Null si la consulta falla.
	 */
	private function standalone_product_entries(): ?array {
		$tax_query = array(
			array(
				'taxonomy' => self::PRODUCT_VISIBILITY_TAXONOMY,
				'field'    => 'slug',
				'terms'    => self::HIDDEN_FROM_CATALOG,
				'operator' => 'NOT IN',
			),
		);
		$posts = $this->query_posts_reliably(
			array(
				'post_type'     => 'product',
				'post_status'   => 'publish',
				'numberposts'   => -1,
				'orderby'       => 'title',
				'order'         => 'ASC',
				'no_found_rows' => true,
				// Mismo motivo que en `prime_volume_index()`: una clave, no el
				// postmeta entero del catálogo.
				'update_post_meta_cache' => false,
				'tax_query'     => $tax_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Foto completa, solo en el cron diario y nunca en un request de visitante.
			),
			'product_query_unreadable'
		);
		if ( null === $posts ) {
			return null;
		}

		$pendientes = array();
		foreach ( $posts as $post ) {
			if ( is_object( $post ) && isset( $post->ID ) && (int) $post->ID > 0 ) {
				$pendientes[] = (int) $post->ID;
			}
		}
		if ( ! $this->prime_robots_meta( $pendientes ) ) {
			$this->read_error_code = 'product_robots_unreadable';
			return null;
		}

		$entries = array();
		foreach ( $posts as $post ) {
			$product_id = is_object( $post ) && isset( $post->ID ) ? (int) $post->ID : 0;
			if ( $product_id < 1 || isset( $this->represented_product_ids[ $product_id ] )
				|| ! isset( $post->post_title )
				|| ! $this->product_is_indexable( $product_id )
				|| self::is_password_protected( $post ) ) {
				continue;
			}
			$permalink = get_permalink( $post );
			if ( is_string( $permalink ) && '' !== $permalink ) {
				$entries[] = array(
					'title' => (string) $post->post_title,
					'url'   => $permalink,
				);
			}
		}

		return $entries;
	}

	/**
	 * Guías publicadas del blog con su nota corta.
	 *
	 * @return array<int,array{title:string,url:string,note:string}>|null Null si la consulta falla.
	 */
	private function guide_entries(): ?array {
		$posts = $this->query_posts_reliably(
			array(
				'post_type'     => 'post',
				'post_status'   => 'publish',
				'numberposts'   => -1,
				'orderby'       => 'date',
				'order'         => 'DESC',
				'no_found_rows' => true,
			),
			'guide_query_unreadable'
		);
		if ( null === $posts ) {
			return null;
		}

		$entries = array();
		foreach ( $posts as $post ) {
			if ( ! is_object( $post ) || ! isset( $post->ID, $post->post_title, $post->post_excerpt )
				|| ! RobotsMeta::is_indexable( get_post_meta( (int) $post->ID, 'rank_math_robots', true ) )
				|| self::is_password_protected( $post ) ) {
				continue;
			}
			$permalink = get_permalink( $post );
			if ( ! is_string( $permalink ) || '' === $permalink ) {
				continue;
			}
			$note = trim( (string) get_post_meta( (int) $post->ID, 'rank_math_description', true ) );
			if ( '' === $note ) {
				$note = trim( wp_strip_all_tags( (string) $post->post_excerpt ) );
			}
			$entries[] = array(
				'title' => (string) $post->post_title,
				'url'   => $permalink,
				'note'  => $note,
			);
		}

		return $entries;
	}

	/**
	 * Ejecuta get_posts distinguiendo un conjunto vacío de un fallo de wpdb.
	 *
	 * @param array<string,mixed> $args         Argumentos de get_posts.
	 * @param string              $failure_code Código observable ante fallo.
	 * @return array<int,object>|null Posts o null cuando la lectura no es confiable.
	 */
	/**
	 * Ceba `rank_math_robots` de un lote de productos con UNA consulta.
	 *
	 * Existe porque las dos consultas de producto del manifiesto corren con
	 * `update_post_meta_cache => false`: cebar el postmeta COMPLETO de los
	 * ~1.566 productos —que en una tienda WooCommerce son decenas de filas por
	 * producto— para leer una sola clave costaba +85 MB y +545 ms en el cron
	 * diario. Ese es todo el trabajo que esta función reemplaza.
	 *
	 * El valor se guarda ya deserializado, exactamente como lo devolvería
	 * `get_post_meta( …, true )`: `RobotsMeta` es el único parser de la
	 * directiva y no debe recibir una forma distinta según quién la leyó.
	 *
	 * Misma disciplina fail-closed que sus hermanas: una lectura degradada
	 * aborta la generación y conserva el manifiesto de ayer, porque el error
	 * silencioso sería publicar como indexable una URL que pide `noindex`.
	 *
	 * @param array<int,int> $post_ids Productos ya resueltos.
	 * @return bool `false` cuando la lectura no es confiable.
	 */
	private function prime_robots_meta( array $post_ids ): bool {
		$pending = array();
		foreach ( $post_ids as $post_id ) {
			$post_id = (int) $post_id;
			if ( $post_id > 0 && ! array_key_exists( $post_id, $this->robots_index ) ) {
				$pending[ $post_id ] = $post_id;
			}
		}
		if ( array() === $pending ) {
			return true;
		}

		global $wpdb;
		$can_batch = is_object( $wpdb )
			&& isset( $wpdb->postmeta )
			&& method_exists( $wpdb, 'prepare' )
			&& method_exists( $wpdb, 'get_results' );
		if ( ! $can_batch ) {
			// Sin un `wpdb` utilizable no hay lote posible. Se lee clave por clave,
			// que es exactamente lo que hacía el módulo antes de esta consulta.
			foreach ( $pending as $post_id ) {
				$this->robots_index[ $post_id ] = get_post_meta( $post_id, self::ROBOTS_META_KEY, true );
			}

			return true;
		}

		$wpdb->last_error = '';
		$placeholders     = implode( ', ', array_fill( 0, count( $pending ), '%d' ) );
		$parameters       = array_values( $pending );
		array_unshift( $parameters, self::ROBOTS_META_KEY );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- marcadores %d generados por conteo.
				$parameters
			)
		);

		$database_ready = ! property_exists( $wpdb, 'ready' ) || (bool) $wpdb->ready;
		if ( ! is_array( $rows ) || '' !== trim( (string) $wpdb->last_error ) || ! $database_ready ) {
			return false;
		}

		// Ausente en `wp_postmeta` es un valor legítimo: RobotsMeta lo considera
		// indexable. Se siembra '' para que el producto no vuelva a consultarse.
		foreach ( $pending as $post_id ) {
			$this->robots_index[ $post_id ] = '';
		}
		foreach ( $rows as $row ) {
			if ( is_object( $row ) && isset( $row->post_id ) ) {
				$this->robots_index[ (int) $row->post_id ] = maybe_unserialize( $row->meta_value ?? '' );
			}
		}

		return true;
	}

	/** ¿El producto acepta ser indexado, según la foto ya cebada? */
	private function product_is_indexable( int $post_id ): bool {
		return RobotsMeta::is_indexable( $this->robots_index[ $post_id ] ?? '' );
	}

	/**
	 * Determina si una publicación está protegida por contraseña.
	 *
	 * Comprueba directamente el atributo post_password del objeto para excluir
	 * entradas protegidas en ejecuciones fuera del ciclo principal de consulta,
	 * garantizando que contenidos restringidos no se expongan en los manifiestos estáticos.
	 *
	 * @param object $post Objeto de publicación.
	 */
	private static function is_password_protected( object $post ): bool {
		return isset( $post->post_password ) && '' !== (string) $post->post_password;
	}

	private function query_posts_reliably( array $args, string $failure_code ): ?array {
		global $wpdb;

		$observes_database = is_object( $wpdb ) && property_exists( $wpdb, 'last_error' );
		if ( $observes_database ) {
			$wpdb->last_error = '';
		}

		$posts          = get_posts( $args );
		$database_error = $observes_database ? trim( (string) $wpdb->last_error ) : '';
		$database_ready = ! is_object( $wpdb ) || ! property_exists( $wpdb, 'ready' ) || (bool) $wpdb->ready;
		if ( ! is_array( $posts ) || '' !== $database_error || ! $database_ready ) {
			$this->read_error_code = $failure_code;
			return null;
		}

		return $posts;
	}

	/**
	 * URLs vigentes de los directorios de series y de editoriales.
	 *
	 * De MEJOR ESFUERZO: buscar la página que contiene cada shortcode es un
	 * detalle del manifiesto, no una condición para publicarlo. Si la búsqueda
	 * falla, `render()` usa el slug de fábrica y el manifiesto sale igual.
	 *
	 * @return array{series?:string,brands?:string}
	 */
	private static function hub_urls(): array {
		// Un intento por directorio: que falle uno no le quita al otro la URL que
		// sí resolvió.
		$hubs = array();
		try {
			$hubs['series'] = SeriesHubUrl::url();
		} catch ( \Throwable $error ) {
			unset( $error );
		}
		try {
			$hubs['brands'] = BrandsHubUrl::url();
		} catch ( \Throwable $error ) {
			unset( $error );
		}

		return $hubs;
	}

	/**
	 * Compone el manifiesto según la spec de llmstxt.org.
	 *
	 * H1 con el nombre, blockquote con el resumen, secciones H2 con listas de
	 * enlaces anotados `- [título](url): nota`. El texto operativo (despacho,
	 * pagos, retiro) sale de lo publicado en /faq/ y /nosotros/.
	 *
	 * @internal Función pura: testeable sin WordPress.
	 *
	 * @param array<int,array{name:string,url:string,note:string,volumes:array<int,array{title:string,url:string}>}> $series Series del catálogo.
	 * @param array<int,array{title:string,url:string,note:string}>                                                  $guides Guías del blog.
	 * @param bool                                                                                                   $full     Incluir los tomos por serie.
	 * @param array<int,array{title:string,url:string}>                                                              $products Productos fuera de una serie indexable.
	 * @param array{series?:string,brands?:string}                                                                   $hubs     URLs ya resueltas de los directorios de series y de editoriales; sin ellas, el slug de fábrica.
	 */
	public static function render( array $series, array $guides, bool $full, array $products = array(), array $brands = array(), array $hubs = array() ): string {
		$safe_brands   = self::sanitize_manifest_brands( $brands );
		$safe_series   = self::sanitize_manifest_series( $series );
		$safe_guides   = self::sanitize_manifest_guides( $guides );
		$safe_products = self::sanitize_manifest_products( $products );

		$home       = 'https://akibara.cl';
		$series_hub = self::normalize_markdown_url( (string) ( $hubs['series'] ?? '' ) );
		$brands_hub = self::normalize_markdown_url( (string) ( $hubs['brands'] ?? '' ) );
		$series_hub = '' !== $series_hub ? $series_hub : $home . '/series/';
		$brands_hub = '' !== $brands_hub ? $brands_hub : $home . '/editoriales/';
		$lines = array(
			'# Akibara',
			'',
			'> ' . self::identity(),
			'',
			'Akibara publica una landing por serie con los tomos catalogados, su estado de publicación, autor y editorial. Los precios y el stock viven en cada ficha de producto, no en este archivo.',
			'',
			'## Cuándo usar Akibara',
			'',
			'Akibara sirve para responder preguntas sobre **manga y cómic publicado en español y vendido en Chile**. Es útil cuando alguien:',
			'',
			'- Busca dónde comprar un tomo concreto en Chile, con precio en pesos chilenos y stock real.',
			'- Pregunta cuántos tomos tiene una serie, cuáles están publicados en español y qué editorial la edita (Ivrea, Panini, Ovni Press, Milky Way, Planeta Cómic).',
			'- Quiere saber si un tomo está disponible hoy, agotado, o en preventa con fecha estimada de llegada.',
			'- Compara ediciones del mismo título entre editoriales de Argentina y España.',
			'',
			'No sirve para: leer manga en línea, descargas, ni catálogos de otros países. Akibara vende y despacha **solo dentro de Chile**.',
			'',
			'### Cómo consultarlo',
			'',
			'- Cualquier URL del sitio devuelve Markdown si la pides con `Accept: text/markdown`. Es la vía recomendada: mismo enlace, sin HTML de por medio.',
			'- Este archivo lista las series con su landing. El precio y el stock NO están aquí: viven en cada ficha de producto y cambian a diario.',
			'- Mapa completo del sitio: https://akibara.cl/sitemap_index.xml',
			'',
			'## Series (' . count( $safe_series ) . ')',
			'',
		);

		foreach ( self::render_series_lines( $safe_series, $full ) as $line ) {
			$lines[] = $line;
		}

		if ( array() !== $safe_products ) {
			$lines[] = '';
			$lines[] = '## Otros productos';
			$lines[] = '';
			foreach ( $safe_products as $product ) {
				$lines[] = '- [' . $product['title'] . '](' . $product['url'] . ')';
			}
		}

		if ( array() !== $safe_brands ) {
			$lines[] = '';
			$lines[] = '## Editoriales (' . count( $safe_brands ) . ')';
			$lines[] = '';
			foreach ( $safe_brands as $brand ) {
				$note    = '' !== $brand['note'] ? ': ' . $brand['note'] : '';
				$lines[] = '- [' . $brand['name'] . '](' . $brand['url'] . ')' . $note;
			}
		}

		$lines[] = '';
		$lines[] = '## Guías';
		$lines[] = '';
		$lines[] = '- [Blog y guías](' . $home . '/blog/): guías de lectura y comparativas editoriales';
		foreach ( $safe_guides as $guide ) {
			$note    = '' !== $guide['note'] ? ': ' . $guide['note'] : '';
			$lines[] = '- [' . $guide['title'] . '](' . $guide['url'] . ')' . $note;
		}

		$lines[] = '';
		$lines[] = '## Tienda';
		$lines[] = '';
		$lines[] = '- [Catálogo completo](' . $home . '/tienda/): fichas de producto con su estado y precio vigentes';
		$lines[] = '- [Manga](' . $home . '/manga/): catálogo de manga en español';
		$lines[] = '- [Cómics](' . $home . '/comics/): cómic occidental en español';
		$lines[] = '- [Manhwa](' . $home . '/manhwa/): manhwa en español';
		$lines[] = '- [A pedido](' . $home . '/a-pedido/): importaciones a pedido y preventas reservables';
		$lines[] = '- [Disponibles](' . $home . '/disponibles/): productos disponibles para comprar ahora, sin espera de importación';
		// Las URLs de directorios se inyectan mediante el parámetro $hubs, preservando la pureza referencial de render().
		$lines[] = '- [Series](' . $series_hub . '): directorio de todas las series';
		$lines[] = '- [Editoriales](' . $brands_hub . '): catálogo por editorial';

		$lines[] = '';
		$lines[] = '## Ayuda y políticas';
		$lines[] = '';
		$lines[] = '- [Preguntas frecuentes](' . $home . '/faq/): envíos, pagos, preventas y retiro';
		$lines[] = '- [Quiénes somos](' . $home . '/nosotros/): la tienda y su dueño';
		$lines[] = '- [Contacto](' . $home . '/contacto/)';
		$lines[] = '- [Devoluciones](' . $home . '/devoluciones/)';
		$lines[] = '- [Embalaje reforzado](' . $home . '/embalaje-seguro/)';
		$lines[] = '- [Política de privacidad](' . $home . '/politica-de-privacidad/)';

		$lines[] = '';
		$lines[] = '## Optional';
		$lines[] = '';
		$lines[] = '- [Sitemap](' . $home . '/sitemap_index.xml)';
		if ( ! $full ) {
			$lines[] = '- [Manifiesto extendido con los tomos por serie](' . $home . '/llms-full.txt)';
		}

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * @param array<int,array{name?:string,url?:string,note?:string}> $brands
	 * @return array<int,array{name:string,url:string,note:string}>
	 */
	private static function sanitize_manifest_brands( array $brands ): array {
		$safe_brands = array();
		foreach ( $brands as $brand ) {
			$name = self::normalize_markdown_text( (string) ( $brand['name'] ?? '' ), 200 );
			$url  = self::normalize_markdown_url( (string) ( $brand['url'] ?? '' ) );
			if ( '' !== $name && '' !== $url ) {
				$safe_brands[] = array(
					'name' => $name,
					'url'  => $url,
					'note' => self::normalize_markdown_text( (string) ( $brand['note'] ?? '' ), 300 ),
				);
			}
		}

		return $safe_brands;
	}

	/**
	 * @param array<int,array{name?:string,url?:string,note?:string,volumes?:array<int,array{title?:string,url?:string}>}> $series
	 * @return array<int,array{name:string,url:string,note:string,volumes:array<int,array{title:string,url:string}>}>
	 */
	private static function sanitize_manifest_series( array $series ): array {
		$safe_series = array();
		foreach ( $series as $serie ) {
			$name = self::normalize_markdown_text( (string) ( $serie['name'] ?? '' ), 200 );
			$url  = self::normalize_markdown_url( (string) ( $serie['url'] ?? '' ) );
			if ( '' === $name || '' === $url ) {
				continue;
			}

			$volumes = array();
			foreach ( is_array( $serie['volumes'] ?? null ) ? $serie['volumes'] : array() as $volume ) {
				$title      = self::normalize_markdown_text( (string) ( $volume['title'] ?? '' ), 200 );
				$volume_url = self::normalize_markdown_url( (string) ( $volume['url'] ?? '' ) );
				if ( '' !== $title && '' !== $volume_url ) {
					$volumes[] = array(
						'title' => $title,
						'url'   => $volume_url,
					);
				}
			}

			$safe_series[] = array(
				'name'    => $name,
				'url'     => $url,
				'note'    => self::normalize_markdown_text( (string) ( $serie['note'] ?? '' ), 300 ),
				'volumes' => $volumes,
			);
		}

		return $safe_series;
	}

	/**
	 * @param array<int,array{title?:string,url?:string,note?:string}> $guides
	 * @return array<int,array{title:string,url:string,note:string}>
	 */
	private static function sanitize_manifest_guides( array $guides ): array {
		$safe_guides = array();
		foreach ( $guides as $guide ) {
			$title = self::normalize_markdown_text( (string) ( $guide['title'] ?? '' ), 200 );
			$url   = self::normalize_markdown_url( (string) ( $guide['url'] ?? '' ) );
			if ( '' !== $title && '' !== $url ) {
				$safe_guides[] = array(
					'title' => $title,
					'url'   => $url,
					'note'  => self::normalize_markdown_text( (string) ( $guide['note'] ?? '' ), 300 ),
				);
			}
		}

		return $safe_guides;
	}

	/**
	 * @param array<int,array{title?:string,url?:string}> $products
	 * @return array<int,array{title:string,url:string}>
	 */
	private static function sanitize_manifest_products( array $products ): array {
		$safe_products = array();
		foreach ( $products as $product ) {
			$title = self::normalize_markdown_text( (string) ( $product['title'] ?? '' ), 200 );
			$url   = self::normalize_markdown_url( (string) ( $product['url'] ?? '' ) );
			if ( '' !== $title && '' !== $url ) {
				$safe_products[] = array(
					'title' => $title,
					'url'   => $url,
				);
			}
		}

		return $safe_products;
	}

	/**
	 * @param array<int,array{name:string,url:string,note:string,volumes:array<int,array{title:string,url:string}>}> $safe_series
	 * @return array<int,string>
	 */
	private static function render_series_lines( array $safe_series, bool $full ): array {
		$lines = array();
		foreach ( $safe_series as $serie ) {
			$note    = '' !== $serie['note'] ? ': ' . $serie['note'] : '';
			$lines[] = '- [' . $serie['name'] . '](' . $serie['url'] . ')' . $note;
			if ( $full && array() !== $serie['volumes'] ) {
				foreach ( $serie['volumes'] as $volume ) {
					$lines[] = '  - [' . $volume['title'] . '](' . $volume['url'] . ')';
				}
			}
		}

		return $lines;
	}

	/** Normaliza contenido editorial a una sola línea y escapa delimitadores Markdown. */
	private static function normalize_markdown_text( string $text, int $max_length ): string {
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = strip_tags( $text );
		$text = preg_replace( '/[\p{Cc}\p{Cf}\p{Z}]+/u', ' ', $text ) ?? '';
		$text = trim( preg_replace( '/\s+/u', ' ', $text ) ?? '' );

		if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
			if ( mb_strlen( $text, 'UTF-8' ) > $max_length ) {
				$text = rtrim( mb_substr( $text, 0, $max_length - 1, 'UTF-8' ) ) . '…';
			}
		} elseif ( strlen( $text ) > $max_length ) {
			$text = rtrim( substr( $text, 0, $max_length - 3 ) ) . '...';
		}

		return str_replace(
			array( '\\', '[', ']', '(', ')' ),
			array( '\\\\', '\\[', '\\]', '\\(', '\\)' ),
			$text
		);
	}

	/** Acepta sólo destinos HTTP(S) de una línea y codifica paréntesis delimitadores. */
	private static function normalize_markdown_url( string $url ): string {
		$url    = trim( html_entity_decode( strip_tags( $url ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		$scheme = strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || false === filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return '';
		}

		return str_replace( array( '\\', '(', ')' ), array( '%5C', '%28', '%29' ), $url );
	}

	/**
	 * Captura la preimagen exacta del full antes de comenzar la publicación.
	 *
	 * @return array{exists:bool,contents:string}|null Null si el destino no es legible o es un symlink.
	 */
	private static function file_preimage( string $target ): ?array {
		if ( is_link( $target ) ) {
			return null;
		}
		if ( ! file_exists( $target ) ) {
			return array(
				'exists'   => false,
				'contents' => '',
			);
		}
		if ( ! is_file( $target ) || ! is_readable( $target ) ) {
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Snapshot local exacto necesario para rollback byte a byte.
		$contents = file_get_contents( $target );
		if ( false === $contents ) {
			return null;
		}

		return array(
			'exists'   => true,
			'contents' => $contents,
		);
	}

	/**
	 * Restaura el full sólo si todavía contiene exactamente la nueva fotografía.
	 *
	 * @param array{exists:bool,contents:string} $preimage Foto anterior.
	 * @param callable(string,string):bool       $replacer Reemplazo atómico.
	 */
	private static function rollback_file( string $target, string $published_contents, array $preimage, callable $replacer ): bool {
		if ( is_link( $target ) || ! is_file( $target ) || ! is_readable( $target ) ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Verificación exacta antes de restaurar o borrar.
		$current = file_get_contents( $target );
		if ( false === $current || ! hash_equals( hash( 'sha256', $published_contents ), hash( 'sha256', $current ) ) ) {
			return false;
		}

		if ( $preimage['exists'] ) {
			return $replacer( $target, $preimage['contents'] );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Destino exacto creado por esta ejecución y verificado por hash.
		return unlink( $target );
	}

	/** Reclama el lease o devuelve null si otra generación todavía lo posee. */
	private function acquire_lock(): ?string {
		$now   = ( $this->clock )();
		$token = ( $this->token_factory )();
		if ( $now < 0 || 1 !== preg_match( '/^[A-Za-z0-9_-]{16,128}$/D', $token ) ) {
			throw new \RuntimeException( 'Invalid manifest lock identity.' );
		}

		$lease = array(
			'token'      => $token,
			'expires_at' => $now + self::LOCK_TTL,
		);
		// Adquisición atómica de lock mediante inserción condicional (INSERT IGNORE),
		// evitando condiciones de carrera entre ejecuciones concurrentes de la tarea.
		if ( ( $this->option_insert_if_absent )( self::OPTION_LOCK, $lease ) ) {
			return $token;
		}

		$current = get_option( self::OPTION_LOCK, null );
		if ( self::is_live_lease( $current, $now ) ) {
			return null;
		}

		return ( $this->option_compare_and_swap )( self::OPTION_LOCK, $current, $lease ) ? $token : null;
	}

	/** Libera solamente el lease que todavía pertenece a esta ejecución. */
	private function release_lock( string $token ): bool {
		$current = get_option( self::OPTION_LOCK, null );
		if ( ! is_array( $current ) || ! hash_equals( $token, (string) ( $current['token'] ?? '' ) ) ) {
			return false;
		}

		return ( $this->option_compare_and_swap )( self::OPTION_LOCK, $current, '' );
	}

	/** Valida que un lease siga vigente. */
	private static function is_live_lease( $lease, int $now ): bool {
		return is_array( $lease )
			&& is_string( $lease['token'] ?? null )
			&& 1 === preg_match( '/^[A-Za-z0-9_-]{16,128}$/D', $lease['token'] )
			&& (int) ( $lease['expires_at'] ?? 0 ) > $now;
	}

	/** CAS directo sobre wp_options para reclamar o liberar un lease sin carreras. */
	private function compare_and_swap_option( string $option_name, $expected, $replacement ): bool {
		global $wpdb;

		if (
			! is_object( $wpdb )
			|| ! isset( $wpdb->options )
			|| ! method_exists( $wpdb, 'prepare' )
			|| ! method_exists( $wpdb, 'query' )
		) {
			return false;
		}

		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options}
				 SET option_value = %s, autoload = 'off'
				 WHERE option_name = %s AND option_value = %s",
				maybe_serialize( $replacement ),
				$option_name,
				maybe_serialize( $expected )
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- CAS preparado sobre una option propia; la API de options no ofrece compare-and-swap.

		if ( 1 !== (int) $result ) {
			return false;
		}

		self::purge_option_cache( $option_name );
		return true;
	}

	/**
	 * Purga las dos entradas que pueden ocultar una escritura directa.
	 *
	 * `alloptions` NO está entre ellas y su ausencia es deliberada: el CAS
	 * escribe `autoload = 'off'` en la misma sentencia, así que esta option no
	 * puede estar en ese blob. Evictarlo obligaba a recomponer TODAS las options
	 * autocargadas del sitio —en producción, con object cache persistente— para
	 * invalidar una fila que ese blob no contenía.
	 */
	private static function purge_option_cache( string $option_name ): void {
		wp_cache_delete( $option_name, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/** Autoriza por hash un contenido antes de publicarlo en el docroot. */
	private function authorize_managed_file( string $filename, string $contents ): bool {
		if ( ! in_array( $filename, self::MANIFEST_FILENAMES, true ) ) {
			return false;
		}

		$hashes             = self::managed_file_hashes( get_option( self::OPTION_MANAGED_FILES, array() ) );
		$authorized         = $hashes[ $filename ] ?? array();
		$authorized[]       = hash( 'sha256', $contents );
		$hashes[ $filename ] = array_slice( array_values( array_unique( $authorized ) ), -4 );

		update_option( self::OPTION_MANAGED_FILES, $hashes, false );
		$stored = self::managed_file_hashes( get_option( self::OPTION_MANAGED_FILES, array() ) );

		return in_array( hash( 'sha256', $contents ), $stored[ $filename ] ?? array(), true );
	}

	/** Reduce el registro de propiedad a la pareja publicada con éxito. */
	private function seal_managed_files( string $full_contents, string $compact_contents ): void {
		update_option(
			self::OPTION_MANAGED_FILES,
			array(
				'llms.txt'      => array( hash( 'sha256', $compact_contents ) ),
				'llms-full.txt' => array( hash( 'sha256', $full_contents ) ),
			),
			false
		);
	}

	/** Normaliza el registro de hashes y descarta nombres o algoritmos ajenos. */
	private static function managed_file_hashes( $stored ): array {
		if ( ! is_array( $stored ) ) {
			return array();
		}

		$normalized = array();
		foreach ( self::MANIFEST_FILENAMES as $filename ) {
			$hashes = is_array( $stored[ $filename ] ?? null ) ? $stored[ $filename ] : array();
			foreach ( $hashes as $hash ) {
				if ( is_string( $hash ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $hash ) ) {
					$normalized[ $filename ][] = $hash;
				}
			}
			if ( isset( $normalized[ $filename ] ) ) {
				$normalized[ $filename ] = array_values( array_unique( $normalized[ $filename ] ) );
			}
		}

		return $normalized;
	}

	/** Persiste una fotografía exitosa sin cargarla en cada request. */
	private function record_success(
		array $series,
		array $guides,
		array $products,
		string $full_contents,
		string $compact_contents
	): void {
		try {
			$success = array(
				'timestamp'      => $this->observation_time(),
				'series'         => count( $series ),
				'guides'         => count( $guides ),
				'products'       => count( $products ),
				'full_sha256'    => hash( 'sha256', $full_contents ),
				'compact_sha256' => hash( 'sha256', $compact_contents ),
			);
			update_option(
				self::OPTION_LAST_SUCCESS,
				$success,
				false
			);
			if ( $success === get_option( self::OPTION_LAST_SUCCESS, null ) ) {
				delete_option( self::OPTION_LAST_ERROR );
			}
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
		}
	}

	/** Persiste un código de fallo acotado, sin traza ni datos del catálogo. */
	private function record_error( string $code, ?\Throwable $throwable = null ): void {
		try {
			$message = null === $throwable
				? ''
				: get_class( $throwable ) . ': ' . $throwable->getMessage();
			update_option(
				self::OPTION_LAST_ERROR,
				array(
					'timestamp' => $this->observation_time(),
					'code'      => $code,
					'message'   => self::normalize_markdown_text( $message, 500 ),
				),
				false
			);
		} catch ( \Throwable $observation_error ) {
			unset( $observation_error );
		}
	}

	/** Obtiene un timestamp incluso si el reloj inyectado falla. */
	private function observation_time(): int {
		try {
			return max( 0, (int) ( $this->clock )() );
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
			return time();
		}
	}

	/**
	 * Retira únicamente archivos cuyo hash fue registrado antes de publicarlos.
	 *
	 * Un archivo editado, un symlink o un contenido sin registro se preserva.
	 *
	 * @internal Lifecycle deactivation/uninstall y pruebas.
	 *
	 * @param string|null $document_root Docroot alternativo para pruebas.
	 */
	public static function remove_manifests( ?string $document_root = null ): void {
		$document_root = rtrim( $document_root ?? ABSPATH, '/\\' );
		$document_root = '' === $document_root ? DIRECTORY_SEPARATOR : $document_root;
		$root_real     = realpath( $document_root );
		$managed       = self::managed_file_hashes( get_option( self::OPTION_MANAGED_FILES, array() ) );
		$remaining     = array();

		foreach ( self::MANIFEST_FILENAMES as $filename ) {
			$hashes = $managed[ $filename ] ?? array();
			if ( array() === $hashes ) {
				continue;
			}

			$target = $document_root . '/' . $filename;
			if ( ! is_file( $target ) ) {
				continue;
			}
			if ( is_link( $target ) ) {
				continue;
			}

			$target_real = realpath( $target );
			if ( false === $root_real || false === $target_real || dirname( $target_real ) !== $root_real || ! is_readable( $target_real ) ) {
				$remaining[ $filename ] = $hashes;
				continue;
			}

			$actual_hash = hash_file( 'sha256', $target_real );
			$owned       = is_string( $actual_hash ) && array_reduce(
				$hashes,
				static fn( bool $matched, string $hash ): bool => $matched || hash_equals( $hash, $actual_hash ),
				false
			);
			if ( ! $owned ) {
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Archivo exacto, regular, dentro del docroot y verificado por hash de propiedad.
			if ( ! unlink( $target_real ) ) {
				$remaining[ $filename ] = $hashes;
			}
		}

		if ( array() === $remaining ) {
			delete_option( self::OPTION_MANAGED_FILES );
		} else {
			update_option( self::OPTION_MANAGED_FILES, $remaining, false );
		}
		delete_option( self::OPTION_LOCK );
	}

	/**
	 * Reemplaza un manifiesto completo mediante un temporal en el mismo directorio.
	 *
	 * Un write parcial nunca toca el destino. `rename()` es atómico en el mismo
	 * filesystem y el temporal se elimina ante cualquier fallo previo.
	 *
	 * @param string        $target   Archivo final.
	 * @param string        $contents Contenido completo.
	 * @param callable|null $renamer  Seam de pruebas; null usa rename nativo.
	 * @return bool True solo tras reemplazar el destino completo.
	 *
	 * @internal Expuesta para el gate de escritura real.
	 */
	public static function replace_file_atomically( string $target, string $contents, ?callable $renamer = null ): bool {
		$directory = dirname( $target );
		if ( ! is_dir( $directory ) || ! is_writable( $directory ) ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_tempnam -- Debe vivir en el mismo directorio para que rename sea atómico.
		$temporary = tempnam( $directory, '.akibara-llms-' );
		if ( false === $temporary ) {
			return false;
		}
		if ( realpath( dirname( $temporary ) ) !== realpath( $directory ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Limpieza del temporal propio y exacto.
			unlink( $temporary );
			return false;
		}

		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Temporal local validado; se comprueban todos los bytes antes del rename.
			$written = file_put_contents( $temporary, $contents, LOCK_EX );
			if ( false === $written || strlen( $contents ) !== $written || ! chmod( $temporary, 0644 ) ) {
				return false;
			}

			$renamer = $renamer ?? static fn( string $source, string $destination ): bool => rename( $source, $destination ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Reemplazo atómico local; WP_Filesystem no ofrece esta garantía.
			return $renamer( $temporary, $target );
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
			return false;
		} finally {
			if ( is_file( $temporary ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Limpieza del temporal propio y exacto, incluso si el renamer lanza.
				unlink( $temporary );
			}
		}
	}
}
