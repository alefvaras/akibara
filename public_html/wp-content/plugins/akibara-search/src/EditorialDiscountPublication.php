<?php
/**
 * Publicación durable del materializado de descuentos editoriales.
 *
 * @package Akibara\Search
 */

declare( strict_types = 1 );

namespace Akibara\Search;

use Akibara\Search\EditorialDiscount\OptionsWriteGuard;
use Akibara\Search\EditorialDiscount\PageCacheGate;
use Akibara\Search\EditorialDiscount\PhysicalReceiptAuditor;
use Akibara\Search\EditorialDiscount\SourceSnapshotReader;
use Akibara\Search\EditorialDiscount\SwapReceiptLedger;
use Akibara\Search\Support\RestCache;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/EditorialDiscount/PageCacheGate.php';
require_once __DIR__ . '/EditorialDiscount/SwapReceiptLedger.php';
require_once __DIR__ . '/EditorialDiscount/PhysicalReceiptAuditor.php';
require_once __DIR__ . '/EditorialDiscount/OptionsWriteGuard.php';
require_once __DIR__ . '/EditorialDiscount/SourceSnapshotReader.php';

/**
 * Cierra el gap entre la fuente editorial y price_effective/facets/offers.
 *
 * Action Scheduler sólo despierta. La intención monotónica, el fan-out LIVE y
 * REPAIR y el receipt posterior al RENAME son la verdad durable.
 *
 * @since 0.1.14
 */
final class EditorialDiscountPublication {

	public const OPTION_STATE   = 'akb_search_editorial_discount_state';
	public const OPTION_RECEIPT = 'akb_search_editorial_discount_receipt';
	public const HOOK_BOUNDARY  = 'akb_search_editorial_discount_boundary';
	public const META_KEY       = '_akb_taxonomy_discount_pct';

	public const PROTOCOL       = 1;
	public const CONTRACT       = 'akb-editorial-materialization-v1';
	public const REQUIRED_FEED_CONTRACT = '2';
	public const PREWRITE_DELAY = 10;

	/**
	 * Vida máxima que este plugin autoriza para una respuesta de página.
	 *
	 * Es el TTL que se le impone a LiteSpeed cuando la lease autoriza caché; no
	 * se lee de su configuración a propósito, porque la garantía de abajo se
	 * apoya en que ESTE número sea el techo conocido y no en la config ajena.
	 *
	 * Vale para todo MENOS la ficha de producto: ver `PRODUCT_PAGE_MIDNIGHT_MARGIN`.
	 */
	public const PAGE_CACHE_TTL = 3600;

	/**
	 * Margen de seguridad antes de la medianoche para el TTL de la ficha de producto.
	 *
	 * Las fichas de producto calculan dependencias temporales basadas en días calendario
	 * (fechas de llegada, preventas y bordes de campaña a medianoche). Este margen asegura
	 * que ninguna respuesta se almacene con un TTL que venza después de la medianoche local.
	 */
	public const PRODUCT_PAGE_MIDNIGHT_MARGIN = 60;

	/**
	 * Franja preventiva antes de un borde de ventana editorial.
	 *
	 * Invariante: PAGE_CACHE_BOUNDARY_HORIZON > PAGE_CACHE_TTL.
	 * Asegura que ninguna respuesta cacheada en origen continúe viva cuando se alcance
	 * un cambio de ventana de campaña comercial (inicio o fin a medianoche).
	 * La purga en el borde de CDN se coordina mediante litespeed_purge_all al publicar.
	 */
	public const PAGE_CACHE_BOUNDARY_HORIZON = 43200;
	private const RUN_RECEIPT_PREFIX = 'akb_search_editorial_run_receipt_';

	/**
	 * Cantidad máxima de comprobantes inmutables de ejecución conservados en options.
	 * La poda mantiene las revisiones más recientes para trazabilidad sin saturar la tabla wp_options.
	 */
	private const RUN_RECEIPT_KEEP = 20;

	/** Techo de filas que la poda examina en una pasada. */
	private const RUN_RECEIPT_SCAN_LIMIT = 500;

	public const OPTION_ENABLED     = 'akibara_taxonomy_discount_enabled';
	// Espejo del tope configurable de business (Utilities\DiscountCeiling::OPTION).
	// CONTRATO CRUZADO documentado: search solo LEE este dominio; el dueño de la
	// option, su pantalla y su sanitizado viven en akibara-business.
	public const OPTION_MAX_PCT     = 'akb_discount_max_pct';
	public const OPTION_FROM        = 'akibara_taxonomy_discount_from';
	public const OPTION_TO          = 'akibara_taxonomy_discount_to';
	public const OPTION_APPLY_TO    = 'akibara_taxonomy_discount_apply_to';
	public const OPTION_SALE_POLICY = 'akibara_taxonomy_discount_sale_policy';
	public const OPTION_FEED        = 'akibara_business_search_feed_contract_version';
	public const OPTION_DECIMALS    = 'woocommerce_price_num_decimals';
	public const OPTION_TIMEZONE    = 'timezone_string';
	public const OPTION_GMT_OFFSET  = 'gmt_offset';

	/** @var WorkRepository */
	private WorkRepository $work;

	/** @var Indexer */
	private Indexer $indexer;

	/** @var WriteFence */
	private WriteFence $fence;

	/** @var self|null */
	private static ?self $runtime = null;

	private static bool $registered = false;
	private static bool $state_read_failed = false;
	private static ?bool $test_serving_override = null;

	/** @var array<int, bool>|null Secuencia focal para probar ventanas TOCTOU. */
	private static ?array $test_price_match_sequence = null;
	private static bool $term_meta_row_failed = false;
	private static bool $source_memo_set = false;
	private static bool $read_authority_available = true;
	private static string $read_authority_error = '';
	private static bool $serving_capture_done = false;
	private static ?string $serving_capture_token = null;
	/**
	 * Memo de la revalidación final particionado por consumidor (facetas, page cache, etc.).
	 *
	 * El ciclo de vida de WordPress ejecuta los hooks en fases desacopladas
	 * (`pre_get_posts` → `woocommerce_product_query:20` → `template_redirect:1` → `shutdown`).
	 * Para evitar interferencias o consumo prematuro del veredicto de coherencia
	 * entre facetas de catálogo y el gate de caché de página, cada consumidor
	 * mantiene su propio estado de revalidación independiente asegurando que la
	 * decisión de serving y caché coincida con la ventana vigente en su fase de ejecución.
	 *
	 * Clave = identificador del consumidor. Cada superficie revalida de forma independiente.
	 *
	 * @var array<string, bool>
	 */
	private static array $serving_final_checked = array();

	/** @var array<string, bool> Veredicto memoizado por consumidor. */
	private static array $serving_final_matches_by = array();
	private static bool $serving_final_matches = false;

	/**
	 * Ventana editorial de la MISMA foto que produjo el token de serving.
	 *
	 * No se relee por `stable_source_snapshot()`: ese memo puede venir de una
	 * foto anterior del request. La decisión de caché tiene que salir de los
	 * bytes exactos que autorizaron la respuesta, o no vale nada.
	 *
	 * @var array{active: bool, boundary_at: int|null, timezone?: string}|null
	 */
	private static ?array $serving_capture_window = null;

	/** @var object|null Objeto exacto que ya ocupa el callback efectivo. */
	private static $adopted_price_provider = null;

	private static ?string $adopted_price_token = null;

	/** @var array<string, mixed>|null */
	private static ?array $adopted_price_snapshot = null;

	private static bool $adopted_provider_absent = false;

	/** @var array<string, mixed>|null */
	private static ?array $source_memo = null;

	/** @var array<string, array<int, array<string, mixed>>> */
	private array $option_frames = array();

	/** @var array<int, array<string, mixed>> */
	private array $meta_frames = array();

	/**
	 * Options writes blocked by write guard.
	 *
	 * Kept as an instance property for test fixture reflection (MarcosEditorialesVigilados).
	 *
	 * @var array<string, string>
	 */
	public array $blocked_writes = array();

	/**
	 * Deletes vetoed by write guard.
	 *
	 * Kept as an instance property for test fixture reflection (MarcosEditorialesVigilados).
	 *
	 * @var array<string, true>
	 */
	public array $vetoed_deletes = array();

	private int $fence_depth = 0;
	private bool $shutdown_registered = false;
	private bool $reconciling = false;
	private ?string $page_publication_token = null;
	private bool $page_finalize_registered = false;
	private OptionsWriteGuard $write_guard;

	public function __construct( WorkRepository $work, Indexer $indexer, WriteFence $fence ) {
		$this->work    = $work;
		$this->indexer = $indexer;
		$this->fence   = $fence;
		$this->write_guard = new OptionsWriteGuard( $this );
		self::$runtime = $this;
	}

	/** @return array<int, string> */
	public static function options(): array {
		return array(
			self::OPTION_MAX_PCT,
			self::OPTION_ENABLED,
			self::OPTION_FROM,
			self::OPTION_TO,
			self::OPTION_APPLY_TO,
			self::OPTION_SALE_POLICY,
			self::OPTION_FEED,
			self::OPTION_DECIMALS,
			self::OPTION_TIMEZONE,
			self::OPTION_GMT_OFFSET,
		);
	}

	/** @return void */
	public function register(): void {
		self::invalidate_runtime_memo();
		self::$read_authority_available = true;
		self::$read_authority_error     = '';
		self::invalidate_serving_capture();
		self::$adopted_price_provider   = null;
		self::$adopted_price_token      = null;
		self::$adopted_price_snapshot   = null;
		self::$adopted_provider_absent  = false;
		self::$registered = true;

		add_filter( 'pre_update_option', array( $this, 'filter_pre_update_option' ), PHP_INT_MAX, 3 );
		add_action( 'admin_notices', array( self::class, 'render_rejected_write_notice' ) );
		add_filter( 'akibara_panel_options_not_saved', array( self::class, 'filter_business_options_not_saved' ), 10, 1 );
		add_action( 'add_option', array( $this, 'before_add_option' ), 1, 2 );
		add_action( 'delete_option', array( $this, 'before_delete_option' ), 1, 1 );
		add_action( 'updated_option', array( $this, 'after_update_option' ), PHP_INT_MAX, 3 );
		add_action( 'added_option', array( $this, 'after_add_option' ), PHP_INT_MAX, 2 );
		add_action( 'deleted_option', array( $this, 'after_delete_option' ), PHP_INT_MAX, 1 );

		add_filter( 'update_term_metadata', array( $this, 'filter_update_term_metadata' ), PHP_INT_MAX, 5 );
		add_filter( 'add_term_metadata', array( $this, 'filter_add_term_metadata' ), PHP_INT_MAX, 5 );
		add_filter( 'delete_term_metadata', array( $this, 'filter_delete_term_metadata' ), PHP_INT_MAX, 5 );
		add_filter( 'update_term_metadata_by_mid', array( $this, 'filter_update_term_metadata_by_mid' ), PHP_INT_MAX, 4 );
		add_filter( 'delete_term_metadata_by_mid', array( $this, 'filter_delete_term_metadata_by_mid' ), PHP_INT_MAX, 2 );
		add_action( 'updated_term_meta', array( $this, 'after_updated_term_meta' ), PHP_INT_MAX, 4 );
		add_action( 'added_term_meta', array( $this, 'after_added_term_meta' ), PHP_INT_MAX, 4 );
		add_action( 'deleted_term_meta', array( $this, 'after_deleted_term_meta' ), PHP_INT_MAX, 4 );

		// Business registra el provider en plugins_loaded:20 y WC termina sus
		// taxonomías en init. La primera lectura canónica se difiere hasta init:23.
		add_action( 'init', array( $this, 'reconcile_on_init' ), 23 );
		add_action( self::HOOK_BOUNDARY, array( $this, 'handle_boundary' ), 10, 3 );
		add_action( 'template_redirect', array( $this, 'cap_page_cache_ttl' ), 1 );
	}

	public static function is_registered(): bool {
		return self::$registered;
	}

	/**
	 * Marca de cuándo se reconcilió por última vez desde `init`.
	 *
	 * Vive en el OBJECT CACHE cuando hay drop-in persistente (el sitio ya tiene
	 * el de LiteSpeed) y sólo cae a una option —con autoload OFF— sin él. Estaba
	 * al revés: se reescribía como option AUTOLOADED cada 60 s desde peticiones
	 * GET del front, y Core invalida `alloptions` en cada escritura de una option
	 * autoloaded, así que el blob entero del sitio se reconstruía una vez por
	 * minuto para ahorrar 21 consultas. El ahorro era real; el precio, mayor.
	 */
	private const RECONCILE_THROTTLE_OPTION = 'akb_search_editorial_reconcile_at';

	/** Grupo de object cache de la marca de throttle. */
	private const RECONCILE_THROTTLE_GROUP = 'akb-search';

	/**
	 * Ventana de silencio de la reconciliación periódica, en segundos.
	 *
	 * Lo que `reconcile_fingerprint()` detecta es DERIVA: alguien editó los
	 * porcentajes por SQL directo, o una transición se cortó a medias. Ninguna de
	 * las dos cosas ocurre en la operación normal — los caminos normales avisan
	 * por su cuenta a través de los hooks de termmeta y de option, que siguen
	 * enganchados y NO pasan por aquí.
	 */
	private const RECONCILE_THROTTLE_SECONDS = 60;

	/**
	 * Ventana corta de reintento cuando el buscador está CAÍDO y el intento de
	 * reconciliar no pudo repararlo (casi siempre: el fence lo tiene el rebuild
	 * que disparó el propio cambio). Con la ventana normal, cada intento fallido
	 * alargaba el 503 un minuto entero; con ésta se reintenta enseguida sin caer
	 * en un reconcile por petición, que en hosting compartido no es gratis.
	 */
	private const RECONCILE_RETRY_SECONDS = 5;

	/**
	 * Reconciliación periódica de huella editorial amortiguada por ventana temporal.
	 *
	 * Amortigua la verificación en peticiones públicas del frontal mediante una marca
	 * temporal para evitar consultas repetitivas innecesarias.
	 * Las acciones críticas (cambios editoriales directos, guardado de ajustes, cron de borde)
	 * ejecutan reconcile_fingerprint() de forma directa e inmediata.
	 *
	 * @internal Callback de `init` con prioridad 23.
	 * @return void
	 */
	public function reconcile_on_init(): void {
		/**
		 * Filtra la ventana de silencio de la reconciliación periódica.
		 *
		 * Devolver 0 restaura el comportamiento anterior: reconciliar en cada
		 * petición.
		 *
		 * @since 0.1.25
		 *
		 * @param int $seconds Segundos entre reconciliaciones desde `init`.
		 */
		$ventana = (int) apply_filters( 'akb_search_editorial_reconcile_throttle', self::RECONCILE_THROTTLE_SECONDS );

		$ahora = time();

		if ( $ventana > 0 ) {
			$ultima = self::read_reconcile_mark();

			// Una marca futura sólo puede venir de un reloj movido: se ignora.
			if ( $ultima > 0 && $ultima <= $ahora && ( $ahora - $ultima ) < $ventana ) {
				return;
			}

			self::write_reconcile_mark( $ahora, $ventana );
		}

		$reconciliado = $this->reconcile_fingerprint();

		// Si la reconciliación no tuvo éxito y el servicio se encuentra no disponible (503),
		// se acorta la ventana de silencio para reintentar a la brevedad sin generar estampidas.
		if ( $ventana > 0 && ! $reconciliado && ! self::serving_ready() ) {
			self::write_reconcile_mark( $ahora - $ventana + self::RECONCILE_RETRY_SECONDS, $ventana );
		}
	}

	/**
	 * Marca de la última reconciliación, o 0 si no se puede leer.
	 *
	 * Degradación ABIERTA por contrato: 0 significa «reconcilia», nunca «espera».
	 *
	 * @return int
	 */
	private static function read_reconcile_mark(): int {
		if ( self::throttle_uses_object_cache() ) {
			$cached = wp_cache_get( self::RECONCILE_THROTTLE_OPTION, self::RECONCILE_THROTTLE_GROUP );
			return is_numeric( $cached ) ? (int) $cached : 0;
		}

		return function_exists( 'get_option' ) ? (int) get_option( self::RECONCILE_THROTTLE_OPTION, 0 ) : 0;
	}

	/**
	 * Sella la marca. Con object cache persistente la entrada CADUCA sola en una
	 * ventana, así que no deja residuo; sin él, la option va con autoload OFF.
	 *
	 * @param int $ahora   Sello.
	 * @param int $ventana Ventana de silencio en segundos.
	 * @return void
	 */
	private static function write_reconcile_mark( int $ahora, int $ventana ): void {
		if ( self::throttle_uses_object_cache() ) {
			wp_cache_set( self::RECONCILE_THROTTLE_OPTION, $ahora, self::RECONCILE_THROTTLE_GROUP, $ventana );
			return;
		}

		if ( function_exists( 'update_option' ) ) {
			update_option( self::RECONCILE_THROTTLE_OPTION, $ahora, false );
		}
	}

	/** @return bool */
	private static function throttle_uses_object_cache(): bool {
		return function_exists( 'wp_using_ext_object_cache' )
			&& wp_using_ext_object_cache()
			&& function_exists( 'wp_cache_get' )
			&& function_exists( 'wp_cache_set' );
	}

	/** @return bool */
	public static function reconcile_now(): bool {
		return null !== self::$runtime && self::$runtime->reconcile_fingerprint();
	}

	/**
	 * Predicado para superficies. Tests/clases aisladas anteriores al bootstrap
	 * conservan compatibilidad; el runtime real siempre llama register().
	 */
	public static function serving_ready(): bool {
		if ( defined( 'AKIBARA_SEARCH_TESTING' ) && AKIBARA_SEARCH_TESTING && null !== self::$test_serving_override ) {
			return self::$test_serving_override;
		}
		// `is_ready()` reabría state/receipt en cada helper del theme. La captura
		// autoritativa ya bracket-ea options+termmeta+attrs y queda memoizada por
		// request; por eso es la única foto inicial que pueden consultar helpers.
		return self::$registered && null !== self::capture_serving_token();
	}

	/**
	 * Verifica el estado actual del servicio forzando la relectura de la base.
	 *
	 * A diferencia de serving_ready(), que memoiza el token por petición para coherencia
	 * durante el renderizado, este método invalida la captura previa para que procesos
	 * de mantenimiento (como el watchdog o tareas CLI) verifiquen si una acción recién
	 * ejecutada restableció con éxito la disponibilidad del servicio.
	 *
	 * @return bool
	 */
	public static function serving_ready_fresh(): bool {
		self::invalidate_serving_capture();
		return self::serving_ready();
	}

	/**
	 * Olvida la foto de serving de esta petición y sus revalidaciones finales.
	 *
	 * Fuente única de «qué compone la foto»: la usan el arranque
	 * ({@see self::register()}) y la medición fresca
	 * ({@see self::serving_ready_fresh()}). Antes la lista vivía escrita a mano en
	 * `register()`, y una séptima pieza añadida allí no se habría olvidado aquí.
	 *
	 * @return void
	 */
	private static function invalidate_serving_capture(): void {
		self::$serving_capture_done     = false;
		self::$serving_capture_token    = null;
		self::$serving_capture_window   = null;
		self::$serving_final_checked    = array();
		self::$serving_final_matches_by = array();
		self::$serving_final_matches    = false;
	}

	/**
	 * Token compuesto de los bytes que autorizan una respuesta pública.
	 * La foto query-time y una relectura fresca deben coincidir antes de iniciar
	 * cualquier lectura; así una option cambiada entre bootstrap y endpoint no
	 * mezcla configuraciones dentro del mismo payload.
	 */
	public static function capture_serving_token(): ?string {
		if ( defined( 'AKIBARA_SEARCH_TESTING' ) && AKIBARA_SEARCH_TESTING && null !== self::$test_serving_override ) {
			return self::$test_serving_override ? 'akb-search-test-publication-token' : null;
		}
		if ( ! self::$registered || ! self::$read_authority_available ) {
			return null;
		}
		if ( self::$serving_capture_done ) {
			return self::$serving_capture_token;
		}
		self::$serving_capture_done = true;
		$identity = self::fresh_publication_identity( true );
		// No abrir otra lectura semántica si la primera fuente autoritativa ya
		// falló. Además de ahorrar SQL, preserva el primer fallo como diagnóstico
		// único y evita que una segunda ruta parezca recuperarlo a mitad de request.
		$request  = is_array( $identity ) ? SemanticPublication::request_runtime_fingerprint() : null;
		$adopted  = is_string( $request ) && is_array( $identity )
			&& hash_equals( $request, (string) $identity['runtime_fingerprint'] );
		self::$serving_capture_token  = $adopted ? (string) $identity['token'] : null;
		self::$serving_capture_window = $adopted ? array(
			'active'      => (bool) $identity['window_active'],
			'boundary_at' => $identity['boundary_at'],
			'timezone'    => $identity['timezone'],
		) : null;
		return self::$serving_capture_token;
	}

	/** Revalida contra bytes frescos justo antes de cache/headers/mutación. */
	public static function serving_token_matches( ?string $token, string $consumer = 'default' ): bool {
		if ( ! is_string( $token ) || '' === $token ) {
			return false;
		}
		if ( defined( 'AKIBARA_SEARCH_TESTING' ) && AKIBARA_SEARCH_TESTING && null !== self::$test_serving_override ) {
			return self::$test_serving_override && 'akb-search-test-publication-token' === $token;
		}
		if ( ! self::$read_authority_available ) {
			return false;
		}
		if ( isset( self::$serving_final_checked[ $consumer ] ) ) {
			return self::$serving_final_matches_by[ $consumer ]
				&& hash_equals( (string) self::$serving_capture_token, $token );
		}
		self::$serving_final_checked[ $consumer ] = true;
		$identity = self::fresh_publication_identity();
		$matches  = is_array( $identity ) && hash_equals( $token, (string) $identity['token'] );
		self::$serving_final_matches_by[ $consumer ] = $matches;
		self::$serving_final_matches                 = $matches;
		return $matches;
	}

	/** Seam exclusivo de PHPUnit; no existe bypass habilitable en producción. */
	public static function set_test_serving_override( ?bool $ready ): void {
		if ( ! defined( 'AKIBARA_SEARCH_TESTING' ) || ! AKIBARA_SEARCH_TESTING ) {
			throw new \LogicException( 'Editorial serving override is test-only.' );
		}
		self::$test_serving_override = $ready;
	}

	/** @param array<int, bool>|null $matches Seam exclusivo de PHPUnit. */
	public static function set_test_price_match_sequence( ?array $matches ): void {
		if ( ! defined( 'AKIBARA_SEARCH_TESTING' ) || ! AKIBARA_SEARCH_TESTING ) {
			throw new \LogicException( 'Editorial price-match sequence is test-only.' );
		}
		if ( is_array( $matches ) ) {
			foreach ( $matches as $match ) {
				if ( ! is_bool( $match ) ) {
					throw new \InvalidArgumentException( 'Editorial price-match sequence must contain booleans.' );
				}
			}
		}
		self::$test_price_match_sequence = null === $matches ? null : array_values( $matches );
	}

	/** @return bool */
	public static function is_ready(): bool {
		$source = self::stable_source_snapshot();
		$state  = self::read_state();
		$receipt = self::read_option_uncached( self::OPTION_RECEIPT );

		return self::is_ready_from( $source, $state, $receipt );
	}

	/** @return array<string, mixed> */
	public static function status(): array {
		$source  = self::stable_source_snapshot();
		$state   = self::read_state();
		$receipt = self::read_option_uncached( self::OPTION_RECEIPT );

		return array(
			'protocol'               => self::PROTOCOL,
			'contract'               => self::CONTRACT,
			'registered'             => self::$registered,
			'ready'                  => self::is_ready_from( $source, $state, $receipt ),
			'source_valid'           => is_array( $source ),
			'state_valid'            => self::valid_state( $state ),
			'receipt_valid'          => self::valid_receipt( $receipt ),
			'wanted_seq'             => is_array( $state ) ? (int) ( $state['wanted_seq'] ?? 0 ) : 0,
			'receipt_seq'            => is_array( $receipt ) ? (int) ( $receipt['wanted_seq'] ?? 0 ) : 0,
			'wanted_fingerprint'     => is_array( $state ) ? (string) ( $state['wanted_fingerprint'] ?? '' ) : '',
			'current_fingerprint'    => is_array( $source ) ? (string) $source['fingerprint'] : '',
			'receipt_fingerprint'    => is_array( $receipt ) ? (string) ( $receipt['fingerprint'] ?? '' ) : '',
			'window_active'          => is_array( $source ) ? (bool) $source['window_active'] : null,
			'next_boundary_at'       => is_array( $source ) ? $source['boundary_at'] : null,
			'provider'               => is_array( $source ) ? $source['provider'] : null,
			'effective_taxonomies'   => is_array( $source ) ? $source['taxonomies'] : array(),
			'effective_max_pct'      => is_array( $source ) ? (int) $source['max_pct'] : null,
			// Sólo con la foto ilegible: es la única vez que hay algo que nombrar,
			// y así el poll del panel no paga las lecturas extra con todo sano.
			'broken_options'         => is_array( $source ) ? array() : self::broken_options(),
			'error'                  => ! is_array( $source ) ? 'source_snapshot_unreadable'
				: ( ! self::valid_state( $state ) ? 'state_missing_or_invalid'
					: ( ! self::valid_receipt( $receipt ) ? 'receipt_missing_or_invalid'
						: ( self::is_ready_from( $source, $state, $receipt ) ? '' : 'materialization_stale' ) ) ),
		);
	}

	/** @return string|null */
	public static function current_fingerprint(): ?string {
		$snapshot = self::stable_source_snapshot();
		return is_array( $snapshot ) ? (string) $snapshot['fingerprint'] : null;
	}

	public function cap_page_cache_ttl(): void {
		PageCacheGate::cap_page_cache_ttl( $this );
	}

	/** @param mixed $esi */
	public function finalize_page_cache( $esi = false ): void {
		PageCacheGate::finalize_page_cache( $this, $esi );
	}

	public static function seconds_before_store_midnight( int $now, string $zone ): ?int {
		return PageCacheGate::seconds_before_store_midnight( $now, $zone );
	}

	/**
	 * Authorized TTL for the current response, or null if no-store is enforced.
	 *
	 * Kept as an internal static method for PageCacheGate delegation and test fixtures.
	 *
	 * @return int|null
	 */
	private static function authorized_page_cache_ttl(): ?int {
		return PageCacheGate::authorized_page_cache_ttl( self::$serving_capture_window );
	}

	private static function mark_page_no_store( string $reason ): void {
		PageCacheGate::mark_page_no_store( $reason );
	}

	public const WRITE_UNCHANGED = OptionsWriteGuard::WRITE_UNCHANGED;
	public const WRITE_FENCED = OptionsWriteGuard::WRITE_FENCED;
	public const WRITE_REPAIRS = OptionsWriteGuard::WRITE_REPAIRS;
	public const WRITE_BREAKS = OptionsWriteGuard::WRITE_BREAKS;
	public const WRITE_STILL_BROKEN = OptionsWriteGuard::WRITE_STILL_BROKEN;
	public const REJECTED_BUSY = OptionsWriteGuard::REJECTED_BUSY;
	public const REJECTED_TRANSIENT_PREFIX = OptionsWriteGuard::REJECTED_TRANSIENT_PREFIX;

	/** @var array<string, true> */
	public static array $precise_panel_rejections = array();

	/** @param mixed $value @param mixed $old_value @return mixed */
	public function filter_pre_update_option( $value, string $option, $old_value ) {
		return $this->write_guard->filter_pre_update_option( $value, $option, $old_value );
	}

	/**
	 * @param string                                  $option
	 * @param array{present: bool, raw: string}|null $row
	 * @return string
	 */
	private static function write_verdict( string $option, ?array $row ): string {
		return OptionsWriteGuard::write_verdict( $option, $row );
	}

	/**
	 * @param mixed $value
	 * @return array{present: bool, raw: string}|null
	 */
	private static function value_row( $value ): ?array {
		return OptionsWriteGuard::value_row( $value );
	}

	/** @param mixed $value */
	private static function stored_option_bytes( $value ): ?string {
		return OptionsWriteGuard::stored_option_bytes( $value );
	}

	/**
	 * @param array<string, array{present: bool, raw: string}> $options
	 * @param array<string, mixed>                             $database
	 */
	private static function snapshot_readable( array $options, array $database ): bool {
		return OptionsWriteGuard::snapshot_readable( $options, $database );
	}

	/** @return array<int, string> */
	private static function option_group( string $option ): array {
		return OptionsWriteGuard::option_group( $option );
	}

	/** @return array<string, array{present: bool, raw: string}>|null */
	private static function neutral_source_options(): ?array {
		return OptionsWriteGuard::neutral_source_options();
	}

	/**
	 * @param string                                            $option
	 * @param array<string, array{present: bool, raw: string}> $from
	 * @param array<string, array{present: bool, raw: string}> $base
	 * @param array<string, mixed>                             $database
	 */
	private static function group_readable( string $option, array $from, array $base, array $database ): bool {
		return OptionsWriteGuard::group_readable( $option, $from, $base, $database );
	}

	/**
	 * @param string                                            $option
	 * @param array<string, array{present: bool, raw: string}> $before
	 * @param array<string, array{present: bool, raw: string}> $after
	 * @param array<string, mixed>                             $database
	 */
	private static function repairs_own_group( string $option, array $before, array $after, array $database ): bool {
		return OptionsWriteGuard::repairs_own_group( $option, $before, $after, $database );
	}

	/** @return array<int, array{label: string, where: string}> */
	private static function broken_options(): array {
		return OptionsWriteGuard::broken_options();
	}

	/** @return array<int, string> */
	private static function broken_option_labels(): array {
		return OptionsWriteGuard::broken_option_labels();
	}

	/** @return array<string, string> */
	private static function repair_locations(): array {
		return OptionsWriteGuard::repair_locations();
	}

	/** @param mixed $value */
	private static function reject_write( string $write, $value, string $cause ): void {
		OptionsWriteGuard::reject_write( $write, $value, $cause );
	}

	/** @param mixed $value */
	private static function describe_value( $value ): string {
		return OptionsWriteGuard::describe_value( $value );
	}

	/** @return array<string, string> */
	private static function write_labels(): array {
		return OptionsWriteGuard::write_labels();
	}

	/** @return array<string, string> */
	private static function rejection_texts(): array {
		return OptionsWriteGuard::rejection_texts();
	}

	/**
	 * @param string             $write
	 * @param string             $cause
	 * @param array<int, string> $broken
	 */
	private static function record_rejected_write( string $write, string $cause, array $broken = array() ): void {
		OptionsWriteGuard::record_rejected_write( $write, $cause, $broken );
	}

	/** @param mixed $options @return mixed */
	public static function filter_business_options_not_saved( $options ) {
		return OptionsWriteGuard::filter_business_options_not_saved( $options );
	}

	public static function render_rejected_write_notice(): void {
		OptionsWriteGuard::render_rejected_write_notice();
	}

	/** @param mixed $query @return mixed */
	public function filter_blocked_option_write( $query ) {
		return $this->write_guard->filter_blocked_option_write( $query );
	}

	/** @param mixed $value */
	public function before_add_option( string $option, $value ): void {
		$this->write_guard->before_add_option( $option, $value );
	}

	public function before_delete_option( string $option ): void {
		$this->write_guard->before_delete_option( $option );
	}

	/** @param mixed $old @param mixed $value */
	public function after_update_option( string $option, $old, $value ): void {
		$this->write_guard->after_update_option( $option, $old, $value );
	}

	/** @param mixed $value */
	public function after_add_option( string $option, $value ): void {
		$this->write_guard->after_add_option( $option, $value );
	}

	public function after_delete_option( string $option ): void {
		$this->write_guard->after_delete_option( $option );
	}

	/** @param mixed $check @param mixed $value @param mixed $previous @return mixed */
	public function filter_update_term_metadata( $check, int $term_id, string $key, $value, $previous ) {
		return $this->write_guard->filter_update_term_metadata( $check, $term_id, $key, $value, $previous );
	}

	/** @param mixed $check @param mixed $value @return mixed */
	public function filter_add_term_metadata( $check, int $term_id, string $key, $value, bool $unique ) {
		return $this->write_guard->filter_add_term_metadata( $check, $term_id, $key, $value, $unique );
	}

	/** @param mixed $check @param mixed $value @return mixed */
	public function filter_delete_term_metadata( $check, int $term_id, string $key, $value, bool $delete_all ) {
		return $this->write_guard->filter_delete_term_metadata( $check, $term_id, $key, $value, $delete_all );
	}

	/** @param mixed $check @param mixed $value @param mixed $key @return mixed */
	public function filter_update_term_metadata_by_mid( $check, int $meta_id, $value, $key ) {
		return $this->write_guard->filter_update_term_metadata_by_mid( $check, $meta_id, $value, $key );
	}

	/** @param mixed $check @return mixed */
	public function filter_delete_term_metadata_by_mid( $check, int $meta_id ) {
		return $this->write_guard->filter_delete_term_metadata_by_mid( $check, $meta_id );
	}

	public function after_updated_term_meta( int $meta_id, int $term_id, string $key, $value ): void {
		$this->write_guard->after_updated_term_meta( $meta_id, $term_id, $key, $value );
	}

	public function after_added_term_meta( int $meta_id, int $term_id, string $key, $value ): void {
		$this->write_guard->after_added_term_meta( $meta_id, $term_id, $key, $value );
	}

	/** @param mixed $meta_ids @param mixed $value */
	public function after_deleted_term_meta( $meta_ids, int $term_id, string $key, $value ): void {
		$this->write_guard->after_deleted_term_meta( $meta_ids, $term_id, $key, $value );
	}

	/**
	 * Repara SQL directo, crash PRE/POST y missed boundary sin adoptar LIVE.
	 */
	public function reconcile_fingerprint(): bool {
		if ( $this->reconciling ) {
			return false;
		}
		$this->reconciling = true;
		$frame             = null;
		try {
			if ( ! $this->enter_fence() ) {
				return false;
			}
			self::invalidate_runtime_memo();
			// El flush NO va aquí. La foto se arma con SQL crudo
			// (`raw_source_options()`, `raw_term_meta_rows()`), que no consulta el
			// object cache: adelantarlo no cambiaba un solo byte de lo que se lee.
			// Lo que sí hacía era cobrarlo en TODAS las peticiones del sitio, y
			// caro: `wp_cache_delete( 'alloptions', 'options' )` obliga a
			// WordPress a releer de MySQL todas las opciones autoload en el
			// siguiente `get_option()`, y `notoptions` deja sin memoria las
			// opciones ausentes del resto de la petición. Este método corre en
			// `init` con prioridad 23, o sea en cada portada, ficha y búsqueda,
			// aunque no hubiera nada que reconciliar —que es el caso normal—.
			//
			// El flush existe para que Business rederive desde los mismos bytes
			// que Search va a sellar, así que su sitio es justo antes de actuar
			// sobre una deriva. Ahí abajo está.
			$source = self::stable_source_snapshot();
			if ( ! is_array( $source ) ) {
				return false;
			}

			$state = self::read_state();
			if ( self::valid_state( $state )
				&& (bool) ( $state['debt_staged'] ?? false )
				&& hash_equals( (string) $source['fingerprint'], (string) $state['wanted_fingerprint'] ) ) {
				$receipt = self::read_option_uncached( self::OPTION_RECEIPT );
				// is_ready_from() NO mira el conteo físico de docs; el gate
				// público (bundle_is_ready) SÍ. Sin el segundo chequeo, este
				// reconcile declaraba «sano» un receipt cuyo coverage ya no
				// casaba con la tabla viva y el serving quedaba en 503 sin que
				// nadie lo reparara.
				if ( self::is_ready_from( $source, $state, $receipt )
					&& is_array( $receipt ) && self::physical_docs_match_receipt( $receipt ) ) {
					return true;
				}
				if ( ! self::$read_authority_available ) {
					return false; // El COUNT falló: sin autoridad de lectura no se decide nada.
				}
				// Re-prueba física de comprobante antes de disparar una reconstrucción completa.
				// Las mutaciones en vivo alteran el conteo de documentos respecto al último swap;
				// esta verificación valida la integridad física para evitar falsos positivos de indisponibilidad.
				$reproof = $this->reprove_physical_receipt( $source, $state );
				if ( true === $reproof ) {
					return true;
				}
				if ( null === $reproof ) {
					return false;
				}
				// Se va a materializar deuda: aquí sí hay que dejar a Business
				// releyendo los mismos bytes.
				$this->flush_source_caches();
				if ( ! Install::request_rebuild( 'editorial_materialization_pending', time() + self::PREWRITE_DELAY ) ) {
					return false;
				}
				if ( ! $this->ensure_purge_for_state( $state, 'pre' ) ) {
					return false;
				}
				$state = self::read_state();
				if ( ! self::valid_state( $state ) || ! $this->ensure_purge_for_state( $state, 'post' ) ) {
					return false;
				}
				$frame = array(
					'run_id' => (string) ( $state['active_run_id'] ?? '' ),
					'seq'    => (int) $state['wanted_seq'],
				);
				// El rebuild quedó pedido y el gate público sigue cerrado hasta
				// que publique: devolver «listo» aquí mentía (is_ready_from no
				// mira el conteo físico que acaba de fallar).
				return false;
			}

			// Hay deriva y se va a montar deuda: mismo motivo que arriba.
			$this->flush_source_caches();
			$frame = $this->stage_locked(
				RebuildJob::eligible_product_ids_snapshot(),
				'editorial_source_drift',
				$source
			);
			return false;
		} finally {
			if ( $this->fence_depth > 0 ) {
				$this->leave_fence();
			}
			$this->reconciling = false;
			if ( is_array( $frame ) ) {
				$this->wake_frame( $frame );
			}
			$this->schedule_boundary();
		}
	}

	/** @return void */
	public function handle_boundary( $seq, $fingerprint, $boundary_at ): void {
		$state = self::read_state();
		if ( ! self::valid_state( $state )
			|| (int) $seq !== (int) $state['wanted_seq']
			|| (string) $fingerprint !== (string) $state['wanted_fingerprint']
			|| (int) $boundary_at !== (int) ( $state['next_boundary_at'] ?? 0 )
			|| time() < (int) $boundary_at ) {
			return;
		}
		$this->reconcile_fingerprint();
	}

	/**
	 * Snapshot que RebuildJob fija al comenzar bajo WriteFence.
	 *
	 * @return array{wanted_seq: int, fingerprint: string}|null
	 */
	public static function snapshot_for_rebuild(): ?array {
		$source = self::stable_source_snapshot();
		$state  = self::read_state();
		if ( ! is_array( $source ) || ! self::valid_state( $state )
			|| ! (bool) ( $state['debt_staged'] ?? false )
			|| (int) ( $state['purge_post_seq'] ?? 0 ) !== (int) ( $state['wanted_seq'] ?? 0 )
			|| ! hash_equals( (string) $source['fingerprint'], (string) $state['wanted_fingerprint'] ) ) {
			return null;
		}
		return array(
			'wanted_seq' => (int) $state['wanted_seq'],
			'fingerprint' => (string) $state['wanted_fingerprint'],
		);
	}

	/**
	 * Captura y adopta la única foto de precio que puede consumir una operación.
	 * Son dos fotos completas (options + termmeta/atributos) y state: 5 SQL.
	 *
	 * @return array{wanted_seq:int,fingerprint:string,provider_token:string,price_snapshot:array<string,mixed>}|null
	 */
	public static function capture_price_authority(): ?array {
		if ( defined( 'AKIBARA_SEARCH_TESTING' ) && AKIBARA_SEARCH_TESTING
			&& ( null !== self::$test_serving_override || ( ! self::$registered && null === self::$runtime ) ) ) {
			return false !== self::$test_serving_override ? array(
				'wanted_seq'     => 1,
				'fingerprint'    => str_repeat( '0', 64 ),
				'provider_token' => '',
				'price_snapshot' => array(),
			) : null;
		}
		$source = self::stable_source_snapshot();
		$state  = self::read_state();
		if ( ! is_array( $source ) || ! self::valid_state( $state )
			|| true !== ( $state['debt_staged'] ?? null )
			|| (int) $state['purge_post_seq'] !== (int) $state['wanted_seq']
			|| ! hash_equals( (string) $source['fingerprint'], (string) $state['wanted_fingerprint'] ) ) {
			return null;
		}
		$adopted = self::adopt_price_snapshot( $source );
		if ( ! is_array( $adopted ) ) {
			return null;
		}
		return array(
			'wanted_seq'    => (int) $state['wanted_seq'],
			'fingerprint'   => (string) $source['fingerprint'],
			'provider_token'=> $adopted['token'],
			'price_snapshot'=> $adopted['snapshot'],
		);
	}

	/** Relectura final inmediatamente antes del efecto durable o respuesta. */
	public static function price_authority_matches( array $capture ): bool {
		if ( defined( 'AKIBARA_SEARCH_TESTING' ) && AKIBARA_SEARCH_TESTING
			&& is_array( self::$test_price_match_sequence ) ) {
			return array() !== self::$test_price_match_sequence
				? (bool) array_shift( self::$test_price_match_sequence )
				: false;
		}
		if ( defined( 'AKIBARA_SEARCH_TESTING' ) && AKIBARA_SEARCH_TESTING
			&& ( null !== self::$test_serving_override || ( ! self::$registered && null === self::$runtime ) ) ) {
			return false !== self::$test_serving_override
				&& ( $capture['fingerprint'] ?? null ) === str_repeat( '0', 64 );
		}
		if ( array( 'wanted_seq', 'fingerprint', 'provider_token', 'price_snapshot' ) !== array_keys( $capture )
			|| ! is_int( $capture['wanted_seq'] ) || $capture['wanted_seq'] <= 0
			|| ! is_string( $capture['fingerprint'] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $capture['fingerprint'] )
			|| ! is_string( $capture['provider_token'] ) || ! is_array( $capture['price_snapshot'] ) ) {
			return false;
		}
		$source = self::fresh_source_snapshot();
		$state  = self::read_state();
		return is_array( $source ) && self::valid_state( $state )
			&& true === ( $state['debt_staged'] ?? null )
			&& (int) $state['purge_post_seq'] === (int) $state['wanted_seq']
			&& $capture['wanted_seq'] === (int) $state['wanted_seq']
			&& hash_equals( $capture['fingerprint'], (string) $state['wanted_fingerprint'] )
			&& hash_equals( $capture['fingerprint'], (string) $source['fingerprint'] )
			&& self::price_snapshot_from_source( $source ) === $capture['price_snapshot']
			&& self::verify_adopted_price_snapshot( $source, $capture['provider_token'] );
	}

	/** Adopta si el caller público aún no pasó por la captura de serving. */
	public static function ensure_price_authority_adopted(): bool {
		if ( self::$adopted_provider_absent || null !== self::$adopted_price_provider ) {
			return true;
		}
		return is_array( self::capture_price_authority() );
	}

	/**
	 * Revalida seq+fingerprint dos veces junto al RENAME (ABA-safe).
	 */
	public static function rebuild_snapshot_matches( int $seq, string $fingerprint ): bool {
		if ( $seq <= 0 || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $fingerprint ) ) {
			return false;
		}
		$capture = self::capture_price_authority();
		return is_array( $capture )
			&& $seq === $capture['wanted_seq']
			&& hash_equals( $fingerprint, $capture['fingerprint'] )
			&& self::price_authority_matches( $capture );
	}

	/** @param array<string, mixed> $proof */
	public static function record_swap_receipt( string $run_id, int $seq, string $fingerprint, array $proof ): bool {
		return SwapReceiptLedger::record_swap_receipt( $run_id, $seq, $fingerprint, $proof );
	}

	/** @param array<string, mixed> $proof */
	private static function record_superseded_swap_receipt( string $run_id, int $seq, string $fingerprint, array $proof ): bool {
		return SwapReceiptLedger::record_superseded_swap_receipt( $run_id, $seq, $fingerprint, $proof );
	}

	public static function dispatch_receipt_purge(): bool {
		try {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed action.
			do_action( 'litespeed_purge', RestCache::TAG );
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed action.
			do_action( 'litespeed_purge_all' );
			return true;
		} catch ( Throwable $throwable ) {
			return false;
		}
	}

	/** @param array<string, mixed> $proof */
	private static function swap_proof_is_complete( array $proof ): bool {
		return SwapReceiptLedger::swap_proof_is_complete( $proof );
	}

	/**
	 * @param array<string, mixed> $proof
	 * @return array<string, mixed>
	 */
	private static function swap_receipt_entry( string $run_id, int $seq, string $fingerprint, array $proof ): array {
		return SwapReceiptLedger::swap_receipt_entry( $run_id, $seq, $fingerprint, $proof );
	}

	/**
	 * @param array<string, mixed> $capture
	 * @param array<string, mixed> $proof
	 */
	private static function swap_authority_matches( array $capture, int $seq, string $fingerprint, array $proof, string $generation, string $semantic_fingerprint ): bool {
		return SwapReceiptLedger::swap_authority_matches( $capture, $seq, $fingerprint, $proof, $generation, $semantic_fingerprint );
	}

	public static function swap_receipt_exists( string $run_id, int $seq, string $fingerprint, bool $allow_superseded ): bool {
		return SwapReceiptLedger::swap_receipt_exists( $run_id, $seq, $fingerprint, $allow_superseded );
	}

	public static function advance_generation_receipt( string $generation ): bool {
		return SwapReceiptLedger::advance_generation_receipt( $generation );
	}

	public static function live_index_generation(): ?string {
		return SwapReceiptLedger::live_index_generation();
	}

	private function receipt_auditor(): PhysicalReceiptAuditor {
		return new PhysicalReceiptAuditor( $this->work, $this->indexer, $this->fence );
	}

	/**
	 * @param array<string, mixed> $source
	 * @param array<string, mixed> $state
	 */
	private function reprove_physical_receipt( array $source, array $state ): ?bool {
		return $this->receipt_auditor()->reprove_physical_receipt( $source, $state );
	}

	/**
	 * @param array<string, mixed> $state
	 * @param array<string, mixed> $proof
	 */
	private static function editorial_materialization_is_unchanged( array $state, array $proof ): bool {
		return PhysicalReceiptAuditor::editorial_materialization_is_unchanged( $state, $proof );
	}

	private function live_work_defers_rebuild( string $reason ): bool {
		return $this->receipt_auditor()->live_work_defers_rebuild( $reason );
	}

	private function clear_defer_clock(): void {
		$this->receipt_auditor()->clear_defer_clock();
	}

	/** @param array<string, mixed> $claim */
	private static function lease_expired( array $claim ): bool {
		return PhysicalReceiptAuditor::lease_expired( $claim );
	}

	/** @param array<string, mixed> $state */
	private static function withdraw_option_rebuild_request( array $state ): void {
		PhysicalReceiptAuditor::withdraw_option_rebuild_request( $state );
	}

	/** @return array<string, mixed>|null */
	public function stage_global( string $reason ): ?array {
		self::invalidate_runtime_memo();
		if ( ! $this->enter_fence() ) {
			return null;
		}
		$source = self::stable_source_snapshot();
		$ids    = RebuildJob::eligible_product_ids_snapshot();
		if ( ! is_array( $source ) || ! is_array( $ids ) ) {
			$this->leave_fence();
			return null;
		}
		$frame = $this->stage_locked( $ids, $reason, $source );
		if ( ! is_array( $frame ) ) {
			$this->leave_fence();
		}
		return $frame;
	}

	/** @return array<string, mixed>|null */
	public function stage_term( int $term_id, string $operation, string $value_hash, int $meta_id = 0, string $post_key = self::META_KEY ): ?array {
		self::invalidate_runtime_memo();
		if ( ! $this->enter_fence() ) {
			return null;
		}
		$source = self::stable_source_snapshot();
		$ids    = $this->indexer->editorial_term_product_ids( $term_id );
		if ( ! is_array( $source ) || ! is_array( $ids ) ) {
			$this->leave_fence();
			return null;
		}
		$frame = $this->stage_locked( $ids, 'term_meta_' . $operation, $source );
		if ( ! is_array( $frame ) ) {
			$this->leave_fence();
			return null;
		}
		$frame['operation']  = $operation;
		$frame['term_id']    = $term_id;
		$frame['meta_id']    = $meta_id;
		$frame['post_key']   = $post_key;
		$frame['value_hash'] = $value_hash;
		$this->meta_frames[] = $frame;
		return $frame;
	}

	/** @param array<int, int>|null $ids @param array<string, mixed> $source @return array<string, mixed>|null */
	private function stage_locked( ?array $ids, string $reason, array $source ): ?array {
		if ( ! is_array( $ids ) || ! $this->fence->is_owned() ) {
			return null;
		}
		$raw   = self::read_state_raw();
		$prior = self::decode_state( $raw );
		if ( self::$state_read_failed || ( null !== $raw && ! self::valid_state( $prior ) ) ) {
			return null;
		}

		$state = array(
			'protocol'             => self::PROTOCOL,
			'contract'             => self::CONTRACT,
			'wanted_seq'           => (int) ( is_array( $prior ) ? $prior['wanted_seq'] : 0 ) + 1,
			'wanted_fingerprint'   => (string) $source['fingerprint'],
			'window_active'        => (bool) $source['window_active'],
			'next_boundary_at'     => $source['boundary_at'],
			'source_term_ids'      => self::source_term_ids( $source ),
			'debt_staged'          => false,
			'active_run_id'        => '',
			'purge_pre_seq'        => 0,
			'purge_post_seq'       => 0,
			'reason'                => $reason,
			'updated_at'            => time(),
		);
		if ( ! self::replace_state_cas( $raw, $state ) ) {
			return null;
		}

		// Desde aquí la revisión YA subió: cualquier fallo deja el servido cerrado
		// (ver abandon_staged_revision()).
		$run_id = RebuildJob::active_pre_swap_run_id();
		if ( null === $run_id ) {
			return $this->abandon_staged_revision( $state, 'comprobar el run vigente' );
		}
		if ( ! $this->work->mark_live_dirty_many( $ids, self::PREWRITE_DELAY ) ) {
			return $this->abandon_staged_revision( $state, 'marcar el trabajo live' );
		}
		if ( '' !== $run_id && ! $this->work->mark_repair_dirty_many( $run_id, $ids, self::PREWRITE_DELAY ) ) {
			return $this->abandon_staged_revision( $state, 'marcar el trabajo de reparación' );
		}
		if ( ! Install::request_rebuild( $reason, time() + self::PREWRITE_DELAY ) ) {
			return $this->abandon_staged_revision( $state, 'pedir la reconstrucción' );
		}

		$state['debt_staged']   = true;
		$state['active_run_id'] = $run_id;
		$raw                    = self::read_state_raw();
		if ( ! self::replace_state_cas( $raw, $state ) ) {
			return $this->abandon_staged_revision( $state, 'confirmar la deuda' );
		}
		if ( ! $this->ensure_purge_for_state( $state, 'pre' ) ) {
			return $this->abandon_staged_revision( $state, 'la purga previa' );
		}

		return array(
			'seq'       => (int) $state['wanted_seq'],
			'run_id'    => $run_id,
			'reason'    => $reason,
			'operation' => '',
		);
	}

	/**
	 * Una revisión editorial que subió pero no llegó a tener su deuda completa.
	 *
	 * La misma familia que el marco colgado de `release_on_shutdown()`, por otra
	 * entrada: el primer CAS de `stage_locked()` sube `wanted_seq` —y con eso el
	 * servido deja de casar— y DESPUÉS puede fallar marcar el trabajo, pedir la
	 * reconstrucción, el segundo CAS o la purga previa. Hasta 0.1.63 se devolvía
	 * null a secas: la escritura se vetaba como «ocupado», la revisión quedaba
	 * subida con `debt_staged = false` y nadie soltaba el estrangulador, así que
	 * el reconcile que retoma la deriva esperaba la ventana entera con el
	 * buscador cerrado. Se deja rastro del paso que falló y se suelta la marca.
	 *
	 * @param array<string, mixed> $state Estado ya escrito por el primer CAS.
	 * @param string               $paso  Paso que falló, en cristiano.
	 * @return null Siempre: no hay marco.
	 */
	private function abandon_staged_revision( array $state, string $paso ): ?array {
		AuditLog::record(
			'deuda_editorial_a_medias',
			sprintf(
				'La revisión editorial %d subió pero falló «%s» antes de cerrar su deuda: la escritura se vetó y se libera el estrangulador para que el reconcile la retome.',
				(int) ( $state['wanted_seq'] ?? 0 ),
				$paso
			)
		);
		self::clear_reconcile_mark();

		return null;
	}

	/** @param array<string, mixed> $frame @return bool */
	private function confirm_frame( array $frame ): bool {
		self::invalidate_runtime_memo();
		$source = self::stable_source_snapshot();
		$raw    = self::read_state_raw();
		$state  = self::decode_state( $raw );
		if ( ! is_array( $source ) || ! self::valid_state( $state )
			|| (int) $frame['seq'] !== (int) $state['wanted_seq'] ) {
			return false;
		}

		$state['wanted_fingerprint'] = (string) $source['fingerprint'];
		$state['window_active']      = (bool) $source['window_active'];
		$state['next_boundary_at']   = $source['boundary_at'];
		$state['source_term_ids']    = self::source_term_ids( $source );
		$state['source_confirmed_at'] = time();
		$state['updated_at']         = time();
		if ( ! self::replace_state_cas( $raw, $state ) ) {
			return false;
		}
		$latest = self::read_state();
		return self::valid_state( $latest ) && $this->ensure_purge_for_state( $latest, 'post' );
	}

	public function complete_option( string $option, string $operation ): void {
		$this->write_guard->clear_repair_write( $option );
		if ( ! isset( $this->option_frames[ $option ] ) ) {
			return;
		}
		$frame = array_pop( $this->option_frames[ $option ] );
		if ( array() === $this->option_frames[ $option ] ) {
			unset( $this->option_frames[ $option ] );
		}
		if ( ! is_array( $frame ) || $operation !== (string) $frame['operation'] ) {
			return;
		}
		$this->confirm_frame( $frame );
		$this->leave_fence();
		$this->wake_frame( $frame );
		$this->schedule_boundary();
		self::clear_reconcile_mark();
	}

	/**
	 * Borra la marca del estrangulador para que la SIGUIENTE petición reconcilie
	 * sin esperar la ventana de 60 s.
	 *
	 * El reproof NO puede correr en esta misma petición: el proveedor de precio
	 * de Business (`SearchPriceProvider`) es request-local y STICKY —adoptar una
	 * foto distinta después de la primera adopción falla a propósito—, así que
	 * quien acaba de escribir la opción ya está ligado a la configuración VIEJA y
	 * no puede calcular los precios de la nueva. Medido: llamar a
	 * `reconcile_fingerprint()` desde aquí devuelve siempre «transitorio».
	 *
	 * Lo que sí decide esta línea es la CARRERA de la petición siguiente:
	 * `consume_pending_rebuild` corre en `init` prioridad 20 pero sólo cuando la
	 * señal cumple su `not_before` (escritura + 10 s), y `reconcile_on_init` corre
	 * en la 23. Sin borrar la marca, el reconcile de la petición inmediata queda
	 * estrangulado, y para cuando le toca ya se lanzó el rebuild que el reproof
	 * iba a demostrar innecesario. Con la marca borrada, el redirect que sigue a
	 * cualquier guardado del panel re-sella y retira la señal dentro de esos 10 s.
	 *
	 * @return void
	 */
	public static function clear_reconcile_mark(): void {
		if ( self::throttle_uses_object_cache() && function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( self::RECONCILE_THROTTLE_OPTION, self::RECONCILE_THROTTLE_GROUP );
			return;
		}
		if ( function_exists( 'update_option' ) ) {
			update_option( self::RECONCILE_THROTTLE_OPTION, 0, false );
		}
	}

	public function complete_meta( string $operation, int $meta_id, int $term_id, string $key ): void {
		for ( $i = count( $this->meta_frames ) - 1; $i >= 0; --$i ) {
			$frame = $this->meta_frames[ $i ];
			if ( $operation !== (string) $frame['operation'] || $term_id !== (int) $frame['term_id']
				|| $key !== (string) ( $frame['post_key'] ?? self::META_KEY ) ) {
				continue;
			}
			if ( 0 < (int) ( $frame['meta_id'] ?? 0 ) && 0 < $meta_id && $meta_id !== (int) $frame['meta_id'] ) {
				continue;
			}
			array_splice( $this->meta_frames, $i, 1 );
			$this->confirm_frame( $frame );
			$this->leave_fence();
			$this->wake_frame( $frame );
			$this->schedule_boundary();
			return;
		}
	}

	/** @param array<string, mixed> $frame */
	private function wake_frame( array $frame ): void {
		$this->indexer->wake_live_work_after( self::PREWRITE_DELAY, 'editorial_discount' );
		$run_id = (string) ( $frame['run_id'] ?? '' );
		if ( '' !== $run_id ) {
			RebuildJob::wake_existing_run( $run_id, WorkRepository::SCOPE_REPAIR, self::PREWRITE_DELAY );
		}
	}

	private function enter_fence(): bool {
		if ( 0 === $this->fence_depth && ! $this->fence->acquire( 0 ) ) {
			return false;
		}
		if ( ! $this->fence->is_owned() ) {
			return false;
		}
		++$this->fence_depth;
		$this->ensure_shutdown_registered();
		return true;
	}

	public function ensure_shutdown_registered(): void {
		if ( ! $this->shutdown_registered ) {
			register_shutdown_function( array( $this, 'release_on_shutdown' ) );
			$this->shutdown_registered = true;
		}
	}

	private function leave_fence(): void {
		if ( $this->fence_depth <= 0 ) {
			return;
		}
		--$this->fence_depth;
		if ( 0 === $this->fence_depth ) {
			$this->fence->release();
		}
	}

	/**
	 * Cierre de la petición: suelta el cerco y lo que quedó a medias.
	 *
	 * Un marco que llega aquí montó su deuda en `stage_locked()` —revisión
	 * subida, productos marcados, purga PRE— pero Core nunca confirmó la
	 * escritura: un UPDATE que no tocó filas (`update_option( 'gmt_offset', '' )`
	 * con la fila ausente, medido en el espejo por la revisión de 0.1.63), un
	 * `add_option()` que se negó o un fatal en otro listener. Sin
	 * `updated_option` no hay `complete_option()`, así que el servido quedaba
	 * cerrado y el reconcile que lo reabre, estrangulado hasta 60 s. Se borra la
	 * marca para que la petición siguiente cierre el marco (re-sella si no
	 * cambió ningún precio) y queda rastro de qué escritura lo dejó así.
	 *
	 * @return void
	 */
	public function release_on_shutdown(): void {
		$colgados = array_keys( $this->option_frames );
		if ( array() !== $this->meta_frames ) {
			$colgados[] = self::META_KEY;
		}
		$this->option_frames = array();
		$this->meta_frames   = array();
		$this->fence_depth   = 0;
		if ( $this->fence->is_owned() ) {
			$this->fence->release();
		}
		$this->write_guard->forget_vetoed_deletes();
		if ( array() !== $colgados ) {
			AuditLog::record(
				'marco_editorial_sin_cerrar',
				sprintf(
					'%s montó su deuda pero WordPress no llegó a confirmar la escritura: se libera el estrangulador para que la petición siguiente la cierre.',
					implode( ', ', $colgados )
				)
			);
			self::clear_reconcile_mark();
		}
	}

	/** @param array<string, mixed> $state */
	private function ensure_purge_for_state( array $state, string $phase ): bool {
		if ( ! in_array( $phase, array( 'pre', 'post' ), true ) ) {
			return false;
		}
		$seq = (int) ( $state['wanted_seq'] ?? 0 );
		$field = 'purge_' . $phase . '_seq';
		if ( $seq > 0 && $seq === (int) ( $state[ $field ] ?? 0 ) ) {
			return true;
		}
		try {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook de LiteSpeed: es SU purga, no una nuestra.
			do_action( 'litespeed_purge', RestCache::TAG );
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook de LiteSpeed: es SU purga.
			do_action( 'litespeed_purge_all' );
		} catch ( Throwable $throwable ) {
			return false;
		}

		$raw    = self::read_state_raw();
		$latest = self::decode_state( $raw );
		if ( ! self::valid_state( $latest ) || $seq !== (int) $latest['wanted_seq'] ) {
			return false;
		}
		$latest[ $field ]                    = $seq;
		$latest[ 'purge_' . $phase . '_at' ] = time();
		$latest['updated_at']                 = time();
		return self::replace_state_cas( $raw, $latest );
	}

	private function schedule_boundary(): bool {
		$state = self::read_state();
		if ( ! self::valid_state( $state ) || null === ( $state['next_boundary_at'] ?? null ) ) {
			return true;
		}
		$at = (int) $state['next_boundary_at'];
		if ( $at <= time() || ! function_exists( 'as_schedule_single_action' ) ) {
			return false;
		}
		$args = array( (int) $state['wanted_seq'], (string) $state['wanted_fingerprint'], $at );
		if ( function_exists( 'as_has_scheduled_action' )
			&& as_has_scheduled_action( self::HOOK_BOUNDARY, $args, Indexer::AS_GROUP ) ) {
			return true;
		}
		// Al cambiar el límite de la campaña (secuencia, huella o timestamp), se purgan
		// todas las acciones pendientes de este gancho específico para evitar acumulación
		// de ejecuciones obsoletas en Action Scheduler antes de programar la acción vigente.
		// Se invoca sin argumentos para descartar límites previos no coincidentes.
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK_BOUNDARY );
		}
		return 0 !== (int) as_schedule_single_action( $at, self::HOOK_BOUNDARY, $args, Indexer::AS_GROUP, true );
	}

	/**
	 * SQL directo bypasea options/term-meta caches. Antes de crear deuda desde
	 * una deriva, limpia ambos lados de la foto para que Business rederive desde
	 * los mismos bytes que sellará Search.
	 */
	private function flush_source_caches(): void {
		foreach ( self::options() as $option ) {
			wp_cache_delete( $option, 'options' );
		}
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );

		$ids   = array();
		$state = self::read_state();
		if ( is_array( $state['source_term_ids'] ?? null ) ) {
			$ids = array_map( 'intval', $state['source_term_ids'] );
		}
		$rows = self::raw_term_meta_rows();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$ids[] = (int) $row['term_id'];
			}
		}
		foreach ( array_unique( array_filter( $ids ) ) as $term_id ) {
			wp_cache_delete( (int) $term_id, 'term_meta' );
		}
	}

	/** @param array<string, mixed> $source @return array<int, int> */
	private static function source_term_ids( array $source ): array {
		$ids = array();
		foreach ( (array) ( $source['term_meta'] ?? array() ) as $row ) {
			if ( is_array( $row ) && (int) ( $row['term_id'] ?? 0 ) > 0 ) {
				$ids[] = (int) $row['term_id'];
			}
		}
		$ids = array_values( array_unique( $ids, SORT_NUMERIC ) );
		sort( $ids, SORT_NUMERIC );
		return $ids;
	}

	/** @return array<string, mixed>|null */
	public static function stable_source_snapshot(): ?array {
		if ( self::$source_memo_set ) {
			return self::$source_memo;
		}
		$one = self::source_snapshot_once();
		$two = self::source_snapshot_once();
		self::$source_memo     = is_array( $one ) && $one === $two ? $one : null;
		self::$source_memo_set = true;
		return self::$source_memo;
	}

	/** Foto fuente fresca y estable; deliberadamente no consulta el memo. */
	public static function fresh_source_snapshot(): ?array {
		$one = self::source_snapshot_once();
		$two = self::source_snapshot_once();
		return is_array( $one ) && $one === $two ? $one : null;
	}

	/**
	 * Identidad byte-exacta de la publicación vigente. Cada llamada relee
	 * options/state/termmeta; no puede adoptar una generación que apareció a mitad
	 * de un request cuyo motor consumió la foto anterior.
	 *
	 * @return array{token: string, runtime_fingerprint: string, window_active: bool, boundary_at: int|null, timezone: string}|null
	 */
	private static function fresh_publication_identity( bool $adopt_snapshot = false ): ?array {
		if ( ! self::$read_authority_available ) {
			return null;
		}
		$names = self::serving_option_names();
		$pre   = self::read_raw_option_bundle( $names );
		if ( ! is_array( $pre ) ) {
			return null;
		}

		$semantic = SemanticPublication::fingerprints_from_raw_bundle( $pre );
		$options  = self::source_options_from_bundle( $pre );
		$db_source_pre = self::raw_editorial_database_source();
		$source   = is_array( $options ) && is_array( $db_source_pre )
			? self::source_snapshot_from_options( $options, $db_source_pre )
			: null;
		$post     = self::read_raw_option_bundle( $names );
		$db_source_post = self::raw_editorial_database_source();
		$source_post = is_array( $options ) && is_array( $db_source_post )
			? self::source_snapshot_from_options( $options, $db_source_post )
			: null;
		if ( ! is_array( $semantic ) || ! is_array( $source ) || ! is_array( $post ) || ! is_array( $source_post )
			|| $pre !== $post || $db_source_pre !== $db_source_post || $source !== $source_post ) {
			self::mark_read_unavailable( 'Serving publication snapshot changed while it was captured.' );
			return null;
		}
		if ( ! self::bundle_is_ready( $pre, $source, $semantic['semantic'] ) ) {
			return null;
		}
		if ( $adopt_snapshot ) {
			if ( ! SemanticPublication::adopt_authoritative_snapshot( $semantic['raw'] ) ) {
				self::mark_read_unavailable( SemanticPublication::authoritative_error() ?: 'Serving semantic snapshot could not be adopted.' );
				return null;
			}
			if ( ! is_array( self::adopt_price_snapshot( $source ) ) ) {
				return null;
			}
		} elseif ( ! self::verify_adopted_price_snapshot( $source ) ) {
			self::mark_read_unavailable( 'Serving price snapshot changed while the response was built.' );
			return null;
		}

		$payload = self::hash_raw_bundle( $names, $pre ) . (string) $source['fingerprint'];
		return array(
			'token'               => hash( 'sha256', $payload ),
			'runtime_fingerprint' => $semantic['runtime'],
			// Salen de `$source`, la misma foto doble ya validada arriba: cero SQL
			// extra y byte-exactas respecto del token que autoriza la respuesta.
			'window_active'       => (bool) $source['window_active'],
			'boundary_at'         => $source['boundary_at'],
			// La zona de la MISMA foto: la medianoche de la ficha es la de la tienda.
			'timezone'            => (string) $source['timezone'],
		);
	}

	/** @return array<int, string> */
	private static function serving_option_names(): array {
		$names = array_merge(
			SemanticPublication::authoritative_names_for_snapshot(),
			self::options(),
			array(
				self::OPTION_STATE,
				self::OPTION_RECEIPT,
				SemanticPublication::OPTION_FINGERPRINT,
				RestCache::OPTION_PURGE_RECEIPT,
				Install::OPTION_KEY,
				Install::OPTION_SCHEMA_SEAL,
				RebuildJob::OPTION_STATE,
			)
		);
		$names = array_values( array_unique( $names, SORT_STRING ) );
		sort( $names, SORT_STRING );
		return $names;
	}

	/** @param array<string, string> $bundle @return array<string, array{present: bool, raw: string}>|null */
	private static function source_options_from_bundle( array $bundle ): ?array {
		$out = array();
		foreach ( self::options() as $name ) {
			$out[ $name ] = array(
				'present' => array_key_exists( $name, $bundle ),
				'raw'     => array_key_exists( $name, $bundle ) ? $bundle[ $name ] : '',
			);
		}
		return self::canonical_source_options( $out );
	}

	/** @param array<string, mixed> $options @return array<string, array{present:bool,raw:string}>|null */
	private static function canonical_source_options( array $options ): ?array {
		return SourceSnapshotReader::canonical_source_options( $options );
	}

	/** @param array<int, string> $names @return array<string, string>|null */
	private static function read_raw_option_bundle( array $names ): ?array {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! isset( $wpdb->options )
			|| ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_results' ) ) {
			self::mark_read_unavailable( 'Serving option bundle source is unavailable.' );
			return null;
		}
		$marks = implode( ', ', array_fill( 0, count( $names ), '%s' ) );
		try {
			$wpdb->last_error = '';
			// `{$marks}` son los `%s` que genera este mismo método, uno por option,
			// y `...$names` los rellena: es un prepare con número variable de
			// placeholders, no interpolación de datos.
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- {$marks} son los %s que genera este mismo método con array_fill(), uno por option, y ...$names los rellena; {$wpdb->options} es la propiedad de tabla del propio $wpdb. Es un prepare() con número variable de marcadores, que es justo lo que UnfinishedPrepare no sabe leer.
			$sql = $wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name IN ({$marks}) ORDER BY option_name ASC",
				...$names
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- `$sql` ES el resultado de `$wpdb->prepare()` de las líneas de arriba; se guarda en una variable a propósito para comprobar `is_string()` antes de ejecutar, porque `prepare()` puede devolver null.
			$rows = is_string( $sql ) ? $wpdb->get_results( $sql ) : null;
		} catch ( Throwable $throwable ) {
			self::mark_read_unavailable( $throwable->getMessage() ?: 'Serving option bundle read threw.' );
			return null;
		}
		if ( ! is_array( $rows ) || '' !== trim( (string) ( $wpdb->last_error ?? '' ) ) ) {
			self::mark_read_unavailable( 'Serving option bundle read failed.' );
			return null;
		}
		$out = array();
		foreach ( $rows as $row ) {
			$name = is_object( $row ) ? ( $row->option_name ?? null ) : null;
			$raw  = is_object( $row ) ? ( $row->option_value ?? null ) : null;
			if ( ! is_string( $name ) || ! in_array( $name, $names, true ) || ! is_string( $raw ) || isset( $out[ $name ] ) ) {
				self::mark_read_unavailable( 'Serving option bundle rows have an invalid shape.' );
				return null;
			}
			$out[ $name ] = $raw;
		}
		ksort( $out, SORT_STRING );
		return $out;
	}

	/** @param array<int, string> $names @param array<string, string> $raw */
	private static function hash_raw_bundle( array $names, array $raw ): string {
		$payload = '';
		foreach ( $names as $name ) {
			$payload .= pack( 'N', strlen( $name ) ) . $name;
			$payload .= array_key_exists( $name, $raw )
				? "\x01" . pack( 'N', strlen( $raw[ $name ] ) ) . $raw[ $name ]
				: "\x00";
		}
		return hash( 'sha256', $payload );
	}

	/** @param array<string, string> $bundle @param array<string, mixed> $source */
	private static function bundle_is_ready( array $bundle, array $source, string $semantic_fingerprint ): bool {
		$required = array(
			self::OPTION_STATE,
			self::OPTION_RECEIPT,
			SemanticPublication::OPTION_FINGERPRINT,
			RestCache::OPTION_PURGE_RECEIPT,
			Install::OPTION_INDEX_GEN,
			Install::OPTION_KEY,
			Install::OPTION_SCHEMA_SEAL,
		);
		foreach ( $required as $name ) {
			if ( ! array_key_exists( $name, $bundle ) ) {
				return false;
			}
		}

		$state    = self::decode_state( $bundle[ self::OPTION_STATE ] );
		$receipt  = self::decode_raw_value( $bundle[ self::OPTION_RECEIPT ] );
		$semantic = self::decode_raw_value( $bundle[ SemanticPublication::OPTION_FINGERPRINT ] );
		$purge    = self::decode_raw_value( $bundle[ RestCache::OPTION_PURGE_RECEIPT ] );
		$version  = self::decode_raw_scalar( $bundle[ Install::OPTION_KEY ] );
		$seal     = self::decode_raw_scalar( $bundle[ Install::OPTION_SCHEMA_SEAL ] );
		$gen      = self::decode_raw_scalar( $bundle[ Install::OPTION_INDEX_GEN ] );
		if ( ! $receipt['valid'] || ! $semantic['valid'] || ! $purge['valid']
			|| ! $version['valid'] || ! $seal['valid'] || ! $gen['valid'] ) {
			return false;
		}
		$receipt = $receipt['value'];
		$semantic = $semantic['value'];
		$purge = $purge['value'];
		$generation = (string) $gen['value'];

		if ( ! self::valid_state( $state ) || ! self::valid_receipt( $receipt )
			|| Install::DB_VERSION !== (string) $version['value'] || Install::schema_seal() !== (string) $seal['value']
			|| ! self::rebuild_barrier_open_from_bundle( $bundle )
			|| true !== $state['debt_staged']
			|| $state['purge_post_seq'] !== $state['wanted_seq']
			|| $state['wanted_seq'] !== $receipt['wanted_seq']
			|| ! hash_equals( (string) $source['fingerprint'], $state['wanted_fingerprint'] )
			|| ! hash_equals( (string) $source['fingerprint'], $receipt['fingerprint'] )
			|| $generation !== $receipt['generation_target']
			|| ! self::valid_semantic_receipt( $semantic, $generation, $semantic_fingerprint )
			|| ! self::valid_purge_receipt( $purge, $generation )
			|| ! self::physical_docs_match_receipt( $receipt ) ) {
			return false;
		}
		return true;
	}

	/** @param array<string, string> $bundle */
	private static function rebuild_barrier_open_from_bundle( array $bundle ): bool {
		if ( ! array_key_exists( RebuildJob::OPTION_STATE, $bundle ) ) {
			return true;
		}
		$state = json_decode( $bundle[ RebuildJob::OPTION_STATE ], true );
		if ( ! is_array( $state ) || ! isset( $state['phase'] ) || ! is_string( $state['phase'] )
			|| ! array_key_exists( 'generation_target', $state ) || ! is_string( $state['generation_target'] )
			|| '' !== $state['generation_target'] ) {
			return false;
		}
		if ( in_array( $state['phase'], array( RebuildJob::PHASE_PREPARING, RebuildJob::PHASE_BUILDING, RebuildJob::PHASE_CATCHING_UP ), true ) ) {
			return true;
		}
		return in_array( $state['phase'], array( RebuildJob::PHASE_BLOCKED, RebuildJob::PHASE_ABORTED ), true )
			&& isset( $state['topology'] ) && is_string( $state['topology'] )
			&& in_array( $state['topology'], array( Install::TOPOLOGY_CLEAN_PRE, Install::TOPOLOGY_READY_SWAP ), true );
	}

	/** @param mixed $receipt */
	private static function valid_semantic_receipt( $receipt, string $generation, string $fingerprint ): bool {
		return is_array( $receipt )
			&& 1 === ( $receipt['protocol'] ?? null )
			&& isset( $receipt['generation'], $receipt['fingerprint'], $receipt['published_at'] )
			&& is_string( $receipt['generation'] ) && hash_equals( $generation, $receipt['generation'] )
			&& is_string( $receipt['fingerprint'] ) && hash_equals( $fingerprint, $receipt['fingerprint'] )
			&& is_int( $receipt['published_at'] ) && $receipt['published_at'] > 0;
	}

	/** @param mixed $receipt */
	private static function valid_purge_receipt( $receipt, string $generation ): bool {
		return is_array( $receipt )
			&& 1 === ( $receipt['protocol'] ?? null )
			&& isset( $receipt['generation_target'], $receipt['tag'], $receipt['ack_scope'], $receipt['dispatched_at'] )
			&& is_string( $receipt['generation_target'] ) && hash_equals( $generation, $receipt['generation_target'] )
			&& RestCache::TAG === $receipt['tag']
			&& 'local_dispatch_only' === $receipt['ack_scope']
			&& is_int( $receipt['dispatched_at'] ) && $receipt['dispatched_at'] > 0;
	}

	/** @param array<string, mixed> $receipt */
	private static function physical_docs_match_receipt( array $receipt ): bool {
		global $wpdb;
		try {
			$wpdb->last_error = '';
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- nombre de tabla propio resuelto por `Install::docs_table()`; no entra nada de fuera.
			$raw = $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Install::docs_table() );
		} catch ( Throwable $throwable ) {
			self::mark_read_unavailable( $throwable->getMessage() ?: 'Physical document count threw.' );
			return false;
		}
		if ( '' !== trim( (string) ( $wpdb->last_error ?? '' ) )
			|| ( ! is_int( $raw ) && ( ! is_string( $raw ) || 1 !== preg_match( '/^\d+$/D', $raw ) ) ) ) {
			self::mark_read_unavailable( 'Physical document count is not authoritative.' );
			return false;
		}
		return (int) $raw === $receipt['coverage']['docs'];
	}

	/** @return array<string, mixed>|null */
	private static function source_snapshot_once(): ?array {
		if ( ! self::$read_authority_available ) {
			return null;
		}
		$options  = self::raw_source_options();
		$database = self::raw_editorial_database_source();
		return is_array( $options ) && is_array( $database )
			? self::source_snapshot_from_options( $options, $database )
			: null;
	}

	/** @param array<string, array{present: bool, raw: string}> $options @return array<string, mixed>|null */
	public static function source_snapshot_from_options( array $options, ?array $database_source = null ): ?array {
		return SourceSnapshotReader::source_snapshot_from_options( $options, $database_source );
	}

	public static function raw_editorial_database_source(): ?array {
		return SourceSnapshotReader::raw_editorial_database_source();
	}

	public static function raw_source_options(): ?array {
		return SourceSnapshotReader::raw_source_options();
	}

	public static function raw_term_meta_rows(): ?array {
		return SourceSnapshotReader::raw_term_meta_rows();
	}

	public static function effective_taxonomies(): ?array {
		return SourceSnapshotReader::effective_taxonomies();
	}

	public static function effective_taxonomies_from_attribute_rows( $attributes ): ?array {
		return SourceSnapshotReader::effective_taxonomies_from_attribute_rows( $attributes );
	}

	public static function provider_descriptor(): ?array {
		return SourceSnapshotReader::provider_descriptor();
	}

	public static function resolve_price_provider() {
		return SourceSnapshotReader::resolve_price_provider();
	}

	public static function price_snapshot_from_source( array $source ): ?array {
		return SourceSnapshotReader::price_snapshot_from_source( $source );
	}

	/** @param array<string,mixed> $source @return array{token:string,snapshot:array<string,mixed>}|null */
	private static function adopt_price_snapshot( array $source ): ?array {
		$snapshot = self::price_snapshot_from_source( $source );
		$provider = self::resolve_price_provider();
		if ( ! is_array( $snapshot ) || false === $provider ) {
			self::mark_read_unavailable( 'Price provider or adoption snapshot is invalid.' );
			return null;
		}
		if ( null === $provider ) {
			if ( null !== self::$adopted_price_provider || null !== self::$adopted_price_token
				|| null !== self::$adopted_price_snapshot ) {
				self::mark_read_unavailable( 'Price provider disappeared after adoption.' );
				return null;
			}
			self::$adopted_provider_absent = true;
			return array( 'token' => '', 'snapshot' => $snapshot );
		}
		if ( self::$adopted_provider_absent
			|| ( null !== self::$adopted_price_provider && self::$adopted_price_provider !== $provider ) ) {
			self::mark_read_unavailable( 'Effective price provider changed during the request.' );
			return null;
		}
		try {
			$token       = $provider->adopt_search_snapshot( $snapshot );
			$authoritative = $provider->authoritative_snapshot();
			$fresh       = is_string( $token ) && $provider->snapshot_is_fresh( $token );
		} catch ( Throwable $throwable ) {
			self::mark_read_unavailable( $throwable->getMessage() ?: 'Price snapshot adoption threw.' );
			return null;
		}
		if ( ! is_string( $token ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $token )
			|| ! is_array( $authoritative ) || $snapshot !== $authoritative || true !== $fresh ) {
			self::mark_read_unavailable( 'Price snapshot adoption could not be verified.' );
			return null;
		}
		self::$adopted_price_provider = $provider;
		self::$adopted_price_token    = $token;
		self::$adopted_price_snapshot = $snapshot;
		return array( 'token' => $token, 'snapshot' => $snapshot );
	}

	/** @param array<string,mixed> $source */
	private static function verify_adopted_price_snapshot( array $source, ?string $expected_token = null ): bool {
		$snapshot = self::price_snapshot_from_source( $source );
		$provider = self::resolve_price_provider();
		if ( ! is_array( $snapshot ) || false === $provider ) {
			return false;
		}
		if ( null === $provider ) {
			return self::$adopted_provider_absent && null === self::$adopted_price_provider
				&& ( null === $expected_token || '' === $expected_token );
		}
		$token = null === $expected_token ? self::$adopted_price_token : $expected_token;
		if ( self::$adopted_provider_absent || self::$adopted_price_provider !== $provider
			|| ! is_string( $token ) || ! is_array( self::$adopted_price_snapshot )
			|| self::$adopted_price_snapshot !== $snapshot ) {
			return false;
		}
		try {
			return $snapshot === $provider->authoritative_snapshot()
				&& true === $provider->snapshot_is_fresh( $token );
		} catch ( Throwable $throwable ) {
			return false;
		}
	}

	public static function window_snapshot( string $enabled, string $from, string $to, DateTimeZone $timezone ): ?array {
		return SourceSnapshotReader::window_snapshot( $enabled, $from, $to, $timezone );
	}

	public static function effective_timezone( array $options ): ?array {
		return SourceSnapshotReader::effective_timezone( $options );
	}

	public static function valid_date( string $date, DateTimeZone $timezone ): bool {
		return SourceSnapshotReader::valid_date( $date, $timezone );
	}

	public static function option_effective( array $options, string $name, string $default ): string {
		return SourceSnapshotReader::option_effective( $options, $name, $default );
	}

	public static function decoded_option_string( string $raw ): string {
		return SourceSnapshotReader::decoded_option_string( $raw );
	}

	public static function source_options_are_scalar( array $options ): bool {
		return SourceSnapshotReader::source_options_are_scalar( $options );
	}

	public static function decode_raw_scalar( string $raw ): array {
		return SourceSnapshotReader::decode_raw_scalar( $raw );
	}

	public static function decode_raw_value( string $raw ): array {
		return SourceSnapshotReader::decode_raw_value( $raw );
	}

	public static function valid_source_option_value( string $name, string $value ): bool {
		return SourceSnapshotReader::valid_source_option_value( $name, $value );
	}

	public static function term_meta_row( int $meta_id ): ?array {
		return SourceSnapshotReader::term_meta_row( $meta_id );
	}

	/** @return string|null */
	private static function read_state_raw(): ?string {
		global $wpdb;
		self::$state_read_failed = false;
		try {
			$wpdb->last_error = '';
			$sql = $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::OPTION_STATE );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- `$sql` ES el resultado de `$wpdb->prepare()` de las líneas de arriba; se guarda en una variable a propósito para comprobar `is_string()` antes de ejecutar, porque `prepare()` puede devolver null.
			$raw = is_string( $sql ) ? $wpdb->get_var( $sql ) : null;
		} catch ( Throwable $throwable ) {
			self::$state_read_failed = true;
			self::mark_read_unavailable( $throwable->getMessage() ?: 'Editorial state read threw.' );
			return null;
		}
		if ( '' !== trim( (string) ( $wpdb->last_error ?? '' ) ) || ( null !== $raw && ! is_string( $raw ) ) ) {
			self::$state_read_failed = true;
			self::mark_read_unavailable( 'Editorial state read failed.' );
			return null;
		}
		return $raw;
	}

	/** @return array<string, mixed>|null */
	public static function read_state(): ?array {
		return self::decode_state( self::read_state_raw() );
	}

	/** @return array<string, mixed>|null */
	private static function decode_state( ?string $raw ): ?array {
		if ( null === $raw ) {
			return null;
		}
		$state = json_decode( $raw, true );
		return is_array( $state ) ? $state : null;
	}

	/** @param array<string, mixed> $state */
	private static function replace_state_cas( ?string $expected_raw, array $state ): bool {
		global $wpdb;
		if ( self::$state_read_failed ) {
			return false;
		}
		$encoded = wp_json_encode( $state );
		if ( ! is_string( $encoded ) || '' === $encoded ) {
			return false;
		}
		$wpdb->last_error = '';
		if ( null === $expected_raw ) {
			$result = $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
					self::OPTION_STATE,
					$encoded,
					'no'
				)
			);
		} else {
			$result = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->options} SET option_value = %s, autoload = %s WHERE option_name = %s AND BINARY option_value = BINARY %s",
					$encoded,
					'no',
					self::OPTION_STATE,
					$expected_raw
				)
			);
		}
		wp_cache_delete( self::OPTION_STATE, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		self::invalidate_runtime_memo();
		return '' === trim( (string) ( $wpdb->last_error ?? '' ) )
			&& 1 === (int) $result
			&& $encoded === self::read_state_raw();
	}

	/** @param mixed $state */
	public static function valid_state( $state ): bool {
		if ( ! is_array( $state ) ) {
			return false;
		}
		$required = array( 'protocol', 'contract', 'wanted_seq', 'wanted_fingerprint', 'window_active', 'next_boundary_at', 'source_term_ids', 'debt_staged', 'active_run_id', 'purge_pre_seq', 'purge_post_seq', 'reason', 'updated_at' );
		$optional = array( 'purge_pre_at', 'purge_post_at', 'source_confirmed_at' );
		if ( array() !== array_diff( $required, array_keys( $state ) )
			|| array() !== array_diff( array_keys( $state ), array_merge( $required, $optional ) )
			|| self::PROTOCOL !== $state['protocol'] || self::CONTRACT !== $state['contract']
			|| ! is_int( $state['wanted_seq'] ) || $state['wanted_seq'] <= 0
			|| ! is_string( $state['wanted_fingerprint'] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $state['wanted_fingerprint'] )
			|| ! is_bool( $state['window_active'] )
			|| ( null !== $state['next_boundary_at'] && ( ! is_int( $state['next_boundary_at'] ) || $state['next_boundary_at'] <= 0 ) )
			|| ! is_array( $state['source_term_ids'] ) || ! is_bool( $state['debt_staged'] )
			|| ! is_string( $state['active_run_id'] ) || ! is_int( $state['purge_pre_seq'] ) || $state['purge_pre_seq'] < 0
			|| ! is_int( $state['purge_post_seq'] ) || $state['purge_post_seq'] < 0
			|| ! is_string( $state['reason'] ) || ! is_int( $state['updated_at'] ) || $state['updated_at'] <= 0 ) {
			return false;
		}
		foreach ( $optional as $timestamp ) {
			if ( array_key_exists( $timestamp, $state )
				&& ( ! is_int( $state[ $timestamp ] ) || $state[ $timestamp ] <= 0 ) ) {
				return false;
			}
		}
		foreach ( $state['source_term_ids'] as $term_id ) {
			if ( ! is_int( $term_id ) || $term_id <= 0 ) {
				return false;
			}
		}
		return true;
	}

	/** @param mixed $receipt */
	public static function valid_receipt( $receipt ): bool {
		if ( ! is_array( $receipt ) || ! is_array( $receipt['coverage'] ?? null ) ) {
			return false;
		}
		$required = array( 'protocol', 'contract', 'run_id', 'wanted_seq', 'fingerprint', 'swapped_at', 'published_at', 'db_version', 'schema_seal', 'coverage', 'generation_target', 'semantic_fingerprint', 'publication_acked', 'purge_all_dispatched_at' );
		$allowed  = array_merge( $required, array( 'generation_advanced_at' ) );
		if ( array() !== array_diff( $required, array_keys( $receipt ) )
			|| array() !== array_diff( array_keys( $receipt ), $allowed )
			|| ( array_key_exists( 'generation_advanced_at', $receipt )
				&& ( ! is_int( $receipt['generation_advanced_at'] ) || $receipt['generation_advanced_at'] <= 0 ) ) ) {
			return false;
		}
		$coverage = $receipt['coverage'];
		if ( ! self::has_exact_keys( $coverage, array( 'eligible', 'docs', 'missing', 'extra', 'orphans' ) ) ) {
			return false;
		}
		foreach ( array( 'eligible', 'docs', 'missing', 'extra', 'orphans' ) as $field ) {
			if ( ! array_key_exists( $field, $coverage ) || ! is_int( $coverage[ $field ] ) || $coverage[ $field ] < 0 ) {
				return false;
			}
		}
		return self::PROTOCOL === ( $receipt['protocol'] ?? null )
			&& self::CONTRACT === ( $receipt['contract'] ?? null )
			&& isset( $receipt['run_id'] ) && is_string( $receipt['run_id'] ) && '' !== $receipt['run_id']
			&& isset( $receipt['wanted_seq'] ) && is_int( $receipt['wanted_seq'] ) && $receipt['wanted_seq'] > 0
			&& isset( $receipt['fingerprint'] ) && is_string( $receipt['fingerprint'] ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $receipt['fingerprint'] )
			&& isset( $receipt['swapped_at'], $receipt['published_at'] ) && is_int( $receipt['swapped_at'] ) && $receipt['swapped_at'] > 0
			&& is_int( $receipt['published_at'] ) && $receipt['published_at'] > 0
			&& Install::DB_VERSION === ( $receipt['db_version'] ?? null )
			&& isset( $receipt['schema_seal'] ) && is_string( $receipt['schema_seal'] ) && '' !== $receipt['schema_seal']
			&& isset( $receipt['generation_target'] ) && is_string( $receipt['generation_target'] ) && '' !== $receipt['generation_target']
			&& isset( $receipt['semantic_fingerprint'] ) && is_string( $receipt['semantic_fingerprint'] )
			&& 1 === preg_match( '/^[a-f0-9]{64}$/D', $receipt['semantic_fingerprint'] )
			&& true === ( $receipt['publication_acked'] ?? null )
			&& $coverage['eligible'] === $coverage['docs']
			&& 0 === $coverage['missing'] && 0 === $coverage['extra'] && 0 === $coverage['orphans']
			&& isset( $receipt['purge_all_dispatched_at'] ) && is_int( $receipt['purge_all_dispatched_at'] )
			&& $receipt['purge_all_dispatched_at'] > 0;
	}

	/** @param array<mixed> $value @param array<int, string> $keys */
	private static function has_exact_keys( array $value, array $keys ): bool {
		$actual = array_keys( $value );
		sort( $actual, SORT_STRING );
		sort( $keys, SORT_STRING );
		return $actual === $keys;
	}

	/** @param mixed $state @param mixed $receipt */
	public static function is_ready_from( ?array $source, $state, $receipt ): bool {
		if ( ! self::materialization_matches( $source, $state, $receipt )
			|| ! RebuildJob::publication_barrier_open() ) {
			return false;
		}

		return self::physical_publication_matches(
			(string) $receipt['generation_target'],
			(string) $receipt['semantic_fingerprint']
		);
	}

	/** @param mixed $state @param mixed $receipt */
	public static function materialization_matches( ?array $source, $state, $receipt ): bool {
		return self::publication_ready_except_editorial_revision( $source, $state, $receipt )
			&& (int) $state['wanted_seq'] === (int) $receipt['wanted_seq']
			&& hash_equals( (string) $source['fingerprint'], (string) $receipt['fingerprint'] );
	}

	/**
	 * Todo lo que `materialization_matches()` exige MENOS la revisión editorial
	 * (el par seq+fingerprint del receipt).
	 *
	 * Existe para poder afirmar «lo ÚNICO que cierra el gate es que el receipt
	 * quedó en la revisión anterior» sin duplicar la definición de
	 * materialización: `materialization_matches()` se define como esto más las
	 * dos comparaciones del receipt.
	 *
	 * @param mixed $state
	 * @param mixed $receipt
	 */
	public static function publication_ready_except_editorial_revision( ?array $source, $state, $receipt ): bool {
		return is_array( $source ) && self::valid_state( $state ) && self::valid_receipt( $receipt )
			&& Install::runtime_schema_ready()
			&& Install::schema_seal() === (string) $receipt['schema_seal']
			&& (bool) ( $state['debt_staged'] ?? false )
			&& (int) ( $state['purge_post_seq'] ?? 0 ) === (int) $state['wanted_seq']
			&& hash_equals( (string) $source['fingerprint'], (string) $state['wanted_fingerprint'] );
	}

	public static function physical_publication_matches( string $generation, string $semantic_fingerprint ): bool {
		if ( '' === $generation || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $semantic_fingerprint ) ) {
			return false;
		}
		$request_semantic = SemanticPublication::request_semantic_fingerprint();
		if ( ! is_string( $request_semantic ) || ! hash_equals( $semantic_fingerprint, $request_semantic ) ) {
			return false;
		}

		$index_generation = self::read_option_uncached( Install::OPTION_INDEX_GEN );
		$purge            = self::read_option_uncached( RestCache::OPTION_PURGE_RECEIPT );
		$semantic         = self::read_option_uncached( SemanticPublication::OPTION_FINGERPRINT );

		return is_string( $index_generation ) && hash_equals( $generation, $index_generation )
			&& is_array( $purge )
			&& 1 === (int) ( $purge['protocol'] ?? 0 )
			&& hash_equals( $generation, (string) ( $purge['generation_target'] ?? '' ) )
			&& RestCache::TAG === (string) ( $purge['tag'] ?? '' )
			&& 'local_dispatch_only' === (string) ( $purge['ack_scope'] ?? '' )
			&& (int) ( $purge['dispatched_at'] ?? 0 ) > 0
			&& is_array( $semantic )
			&& 1 === (int) ( $semantic['protocol'] ?? 0 )
			&& hash_equals( $generation, (string) ( $semantic['generation'] ?? '' ) )
			&& hash_equals( $semantic_fingerprint, (string) ( $semantic['fingerprint'] ?? '' ) )
			&& (int) ( $semantic['published_at'] ?? 0 ) > 0;
	}

	/** @param array<string, mixed> $entry */
	private static function persist_run_receipt( array $entry ): bool {
		return SwapReceiptLedger::persist_run_receipt( $entry );
	}

	private static function prune_run_receipts( string $keep_key ): void {
		SwapReceiptLedger::prune_run_receipts( $keep_key );
	}

	/** @return array<string, mixed>|null */
	private static function run_receipt( string $run_id, int $seq, string $fingerprint ): ?array {
		return SwapReceiptLedger::run_receipt( $run_id, $seq, $fingerprint );
	}

	private static function run_receipt_option( string $run_id, int $seq, string $fingerprint ): string {
		return SwapReceiptLedger::run_receipt_option( $run_id, $seq, $fingerprint );
	}

	/**
	 * Lee wp_options sin object cache y falla cerrado ante errores SQL o bytes
	 * que no puedan representar el valor persistido.
	 *
	 * @return mixed null significa ausente o no verificable.
	 */
	public static function read_option_uncached( string $option ) {
		$ok  = false;
		$raw = self::read_option_raw_uncached( $option, $ok );
		if ( ! $ok || null === $raw ) {
			return null;
		}
		$decoded = self::decode_raw_value( $raw );
		if ( ! $decoded['valid'] ) {
			self::mark_read_unavailable( 'Editorial option has invalid serialized bytes: ' . $option );
			return null;
		}
		return $decoded['value'];
	}

	/**
	 * Bytes crudos de UNA option, sin object cache.
	 *
	 * NO SIRVE PARA DECIDIR PRESENCIA de options que pueden guardar ''. Usa
	 * `wpdb::get_var()`, que devuelve null tanto para una fila ausente como para
	 * una columna vacía. Aquí sólo lo leen recibos, huellas y estados serializados,
	 * que nunca son ''. Para las options vigiladas manda `raw_source_options()`
	 * (el defecto de `gmt_offset` presente y vacío, 0.1.64).
	 *
	 * @param string $option Nombre de la option.
	 * @param bool   $ok     Salida: true si la lectura fue autoritativa.
	 * @return string|null
	 */
	private static function read_option_raw_uncached( string $option, bool &$ok ): ?string {
		global $wpdb;
		$ok = false;
		if ( '' === $option || ! isset( $wpdb->options ) ) {
			return null;
		}
		try {
			$wpdb->last_error = '';
			$sql = $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- `$sql` ES el resultado de `$wpdb->prepare()` de las líneas de arriba; se guarda en una variable a propósito para comprobar `is_string()` antes de ejecutar, porque `prepare()` puede devolver null.
			$raw = is_string( $sql ) ? $wpdb->get_var( $sql ) : null;
		} catch ( Throwable $throwable ) {
			self::mark_read_unavailable( $throwable->getMessage() ?: 'Editorial publication option read threw.' );
			return null;
		}
		if ( '' !== trim( (string) ( $wpdb->last_error ?? '' ) ) || ( null !== $raw && ! is_string( $raw ) ) ) {
			self::mark_read_unavailable( 'Editorial publication option read failed.' );
			return null;
		}
		$ok = true;
		return $raw;
	}

	public static function invalidate_runtime_memo(): void {
		self::$source_memo_set = false;
		self::$source_memo     = null;
	}

	public static function mark_read_unavailable( string $error ): void {
		if ( self::$read_authority_available ) {
			self::$read_authority_error = $error;
		}
		self::$read_authority_available = false;
		self::$source_memo_set           = true;
		self::$source_memo               = null;
	}

	/** @param mixed $value */
	private static function canonical_positive_int( $value ): bool {
		return SourceSnapshotReader::canonical_positive_int( $value );
	}

	/** @param mixed $value */
	private static function canonical_nonnegative_int( $value ): bool {
		return SourceSnapshotReader::canonical_nonnegative_int( $value );
	}

	/** @param mixed $value Entero de hook antes de cualquier cast. */
	private static function canonical_filter_integer( $value ): ?int {
		return SourceSnapshotReader::canonical_filter_integer( $value );
	}

	/** @return array<string, mixed>|null */
	public function top_option_frame( string $option ): ?array {
		if ( empty( $this->option_frames[ $option ] ) ) {
			return null;
		}
		$top = end( $this->option_frames[ $option ] );
		return is_array( $top ) ? $top : null;
	}

	/** @param mixed $one @param mixed $two */
	public static function same_value( $one, $two ): bool {
		return $one === $two || maybe_serialize( $one ) === maybe_serialize( $two );
	}

	/**
	 * Huella determinista de un valor de termmeta o configuración.
	 *
	 * `maybe_serialize()` no serializa valores escalares (`int`, `bool`, `null`).
	 * Dado que este archivo opera con `strict_types=1`, los escalares se normalizan
	 * mediante `serialize()` para garantizar tipos estrictos en `hash('sha256', ...)`.
	 * Esto preserva la distinción inequívoca entre tipos falsy (`null`, `false`, `''`)
	 * y mantiene estabilidad de huella para comparaciones concurrentes.
	 *
	 * @param mixed $value
	 */
	public static function value_hash( $value ): string {
		$serialized = maybe_serialize( $value );
		if ( ! is_string( $serialized ) ) {
			$serialized = serialize( $value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- huella interna, nunca se deserializa ni se persiste.
		}

		return hash( 'sha256', $serialized );
	}

	public static function is_term_meta_row_failed(): bool {
		return self::$term_meta_row_failed;
	}

	public static function set_term_meta_row_failed( bool $failed ): void {
		self::$term_meta_row_failed = $failed;
	}

	public static function is_read_authority_available(): bool {
		return self::$read_authority_available;
	}

	/** @return array{active: bool, boundary_at: int|null, timezone?: string}|null */
	public static function get_serving_capture_window(): ?array {
		return self::$serving_capture_window;
	}

	public function get_page_publication_token(): ?string {
		return $this->page_publication_token;
	}

	public function set_page_publication_token( ?string $token ): void {
		$this->page_publication_token = $token;
	}

	public static function is_fence_owned(): bool {
		return null !== self::$runtime && self::$runtime->fence->is_owned();
	}

	public function is_page_finalize_registered(): bool {
		return $this->page_finalize_registered;
	}

	public function set_page_finalize_registered( bool $registered ): void {
		$this->page_finalize_registered = $registered;
	}

	public function block_option_write( string $option, string $operation ): void {
		$this->write_guard->block_option_write( $option, $operation );
	}

	public function unblock_option_write( string $option ): void {
		$this->write_guard->unblock_option_write( $option );
	}

	/** @return array<int, mixed> */
	public static function get_read_authority_latch(): array {
		return array(
			self::$read_authority_available,
			self::$read_authority_error,
			self::$source_memo_set,
			self::$source_memo,
		);
	}

	/** @param array<int, mixed> $latch */
	public static function set_read_authority_latch( array $latch ): void {
		list(
			self::$read_authority_available,
			self::$read_authority_error,
			self::$source_memo_set,
			self::$source_memo
		) = $latch;
	}

	public function push_option_frame( string $option, array $frame ): void {
		$this->option_frames[ $option ][] = $frame;
	}

	public function update_top_option_frame_operation( string $option, string $operation ): void {
		if ( ! empty( $this->option_frames[ $option ] ) ) {
			$last = count( $this->option_frames[ $option ] ) - 1;
			$this->option_frames[ $option ][ $last ]['operation'] = $operation;
		}
	}

	public function push_meta_frame( array $frame ): void {
		$this->meta_frames[] = $frame;
	}

	public function top_meta_frame(): ?array {
		if ( empty( $this->meta_frames ) ) {
			return null;
		}
		$top = end( $this->meta_frames );
		return is_array( $top ) ? $top : null;
	}

	public function update_top_meta_frame_operation( string $operation ): void {
		if ( ! empty( $this->meta_frames ) ) {
			$last = count( $this->meta_frames ) - 1;
			$this->meta_frames[ $last ]['operation'] = $operation;
		}
	}
}
