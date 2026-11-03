<?php
/**
 * Search — módulo central del motor de búsqueda e indexación de Akibara.
 *
 * Coordina la inicialización de indexadores, oyentes de cambios en catálogo,
 * endpoints REST de autocompletado y facetas de navegación.
 *
 * Control de operación mediante flags semánticos:
 *   - `akb_search_indexing`: escucha de mutaciones de catálogo y reconciliación de índice.
 *   - `akb_search_suggest`: endpoint REST de autocompletado en vivo.
 *   - `akb_search_facets`: filtros de navegación facetada en archivo de productos.
 *
 * @package Akibara\Search
 */

declare( strict_types = 1 );

namespace Akibara\Search;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.55.0
 */
final class Search {

	/** Flag de indexación (escucha de cambios + reconciler). */
	public const OPTION_INDEXING = 'akb_search_indexing';

	/** Flag del autocomplete REST (endpoint + assets del cliente). */
	public const OPTION_SUGGEST = 'akb_search_suggest';

	/** Flag de facetas en el main query del shop. */
	public const OPTION_FACETS = 'akb_search_facets';

	/** @var Indexer */
	private Indexer $indexer;

	/** @var RebuildJob */
	private RebuildJob $rebuild;

	/** @var Reconciler */
	private Reconciler $reconciler;

	/** @var SuggestController */
	private SuggestController $suggest;

	/** @var FacetService */
	private FacetService $facet_service;

	/** @var SeriesSuggestions */
	private SeriesSuggestions $series;

	/** @var FacetQueryIntegration */
	private FacetQueryIntegration $facet_query;

	/** @var SearchAdminSection */
	private SearchAdminSection $admin_section;

	/** @var SemanticPublication */
	private SemanticPublication $semantic_publication;

	/** @var EditorialDiscountPublication */
	private EditorialDiscountPublication $editorial_publication;

	/**
	 * Sin dependencias inyectadas. El precio con descuento editorial llega al
	 * índice por el filtro `akb_search_effective_price` (lo llena
	 * akibara-business si está activo) — decoupling total de akibara-business.
	 */
	public function __construct() {
		global $wpdb;

		$builder          = new DocumentBuilder();
		$work             = new WorkRepository( $wpdb );
		$fence            = new WriteFence( $wpdb );
		$product_sources  = new ProductSourceAuthority( $wpdb );
		$this->indexer    = new Indexer( $builder, $work, $fence, $product_sources );
		$this->rebuild    = new RebuildJob( $this->indexer, $work, $fence );
		$this->reconciler = new Reconciler( $this->indexer, $builder, $product_sources );

		$engine              = new QueryEngine( $wpdb );
		$this->facet_service = new FacetService( $wpdb );
		$this->series        = new SeriesSuggestions();
		$this->suggest       = new SuggestController( $engine, new ProductPresenter( $wpdb, $product_sources ), new QueryLogger( $wpdb ), $this->facet_service, new PopularQueries( $wpdb ), $this->series );
		$this->facet_query   = new FacetQueryIntegration( $engine, $this->facet_service );

		$this->admin_section        = new SearchAdminSection();
		$this->semantic_publication = new SemanticPublication();
		$this->editorial_publication = new EditorialDiscountPublication( $work, $this->indexer, $fence );
	}

	/**
	 * @return void
	 */
	public function register(): void {
		Install::maybe_install();

		// Handlers AS + CLI: SIEMPRE — jobs pendientes deben poder drenar y
		// el operador necesita rebuild/status aunque la escucha esté OFF.
		$this->indexer->register_handlers();
		$this->rebuild->register_handlers();
		$this->reconciler->register_handlers();
		( new Cli( $this->indexer, $this->rebuild, $this->reconciler ) )->register();

		// Bump de DB_VERSION → señal pending_rebuild dejada por maybe_install;
		// se consume en init 20 (AS inicializa en init 1 — encolar en
		// plugins_loaded es _doing_it_wrong / mudo). SIEMPRE, como los
		// handlers: una migración debe re-derivar aunque la escucha esté OFF.
		add_action( 'init', array( Install::class, 'consume_pending_rebuild' ), 20 );

		// Ciclo de vida del flag de Novedades: limpia su memo ante cualquier
		// escritor. La publicación durable la centraliza SemanticPublication.
		Novedad::register();

		// Invalidación del catálogo de series: SIEMPRE, como Novedad. Si el
		// operador borra o renombra una serie con el autocomplete apagado, el
		// transient viejo sobreviviría hasta 6 h después de encenderlo y el
		// panel ofrecería landings que ya no existen.
		$this->series->register();

		// Config query-time y facetas: intención durable PRE-write, publicación
		// POST-write y reconciliación por fingerprint para SQL directo/crashes.
		// Se registra antes del gate de schema porque el panel y escritores CLI
		// siguen disponibles durante recovery.
		$this->semantic_publication->register();

		// Business conecta el provider de precio en plugins_loaded:20. Registrar
		// los PRE hooks editoriales después evita bloquear su adopción inicial;
		// cualquier SQL temprano queda cubierto por la reconciliación raw en init:23.
		add_action( 'plugins_loaded', array( $this->editorial_publication, 'register' ), 30 );

		// Captura de llegadas (flip onbackorder→instock): opera incondicionalmente
		// para poblar datos de novedades independientemente de los flags de front.
		Arrival::register();

		// Solicitudes de títulos y radar de encargos locales: SIEMPRE
		TitleRequests::init();

		// Panel admin: SIEMPRE (el operador necesita rebuild/estado/log aunque
		// los flags de front estén OFF). Solo wp-admin, cero superficie front.
		$this->admin_section->register();

		// REST de estado para el panel: INCONDICIONAL como la sección admin —
		// NUNCA detrás de los flags (patrón vecino de suggest NO aplica: el
		// panel existe justamente para operar con flags OFF — gate DISEÑO h4).
		// permission_callback exige manage_woocommerce; el poll manda X-WP-Nonce.
		add_action( 'rest_api_init', array( $this, 'register_admin_status_route' ) );

		// Las rutas siguen presentes si el dueño las configuró: con materializado
		// cerrado responden 503/no-store (no 404 ambiguo). Assets/helpers/main
		// query usan en cambio el predicado efectivo dinámico.
		if ( self::suggest_configured() ) {
			$this->suggest->register();
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		}

		// Facetas en el main query del shop. Independiente del resto: se puede
		// tener el índice y el suggest sin conmutar aún el filtrado del shop
		// (que hoy da FacetWP) — cutover uno por vez.
		if ( self::facets_configured() ) {
			$this->facet_query->register();
		}

		// Si la migración no pudo sellar el contrato físico, no se cablean writers
		// de tablas. Las rutas ya registradas degradan a 503 mediante el gate.
		if ( ! Install::runtime_schema_ready() ) {
			return;
		}

		add_action( 'update_option_' . Install::OPTION_INDEX_GEN, array( Support\RestCache::class, 'purge' ), 10, 0 );
		add_action( 'add_option_' . Install::OPTION_INDEX_GEN, array( Support\RestCache::class, 'purge' ), 10, 0 );

		// La revisión periódica se agenda incondicionalmente para garantizar la poda
		// del log de búsquedas incluso cuando la indexación de productos esté pausada.
		add_action( 'init', array( $this->reconciler, 'ensure_scheduled' ), 20 );

		if ( ! self::indexing_listeners_wanted() ) {
			return;
		}

		$this->indexer->register_listeners();
	}

	/**
	 * Route de estado del panel (milestone Operable): salud + progreso del
	 * rebuild para el poll de la UI. Solo operadores; no-store.
	 *
	 * @return void
	 */
	public function register_admin_status_route(): void {
		register_rest_route(
			'akibara/v1',
			'/search/admin-status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_admin_status' ),
				'permission_callback' => static fn(): bool => current_user_can( 'manage_woocommerce' ),
			)
		);
	}

	/**
	 * GET /search/admin-status → insumos + veredicto de HealthReport.
	 *
	 * @return \WP_REST_Response
	 */
	public function handle_admin_status() {
		$data     = HealthReport::collect();
		$verdict  = HealthReport::verdict( $data );
		$response = new \WP_REST_Response(
			array(
				'verdict'  => $verdict['verdict'],
				'reasons'  => $verdict['reasons'],
				'rebuild'  => $data['rebuild'],
				'work'     => $data['work'],
				'topology' => $data['topology'],
				'docs'     => $data['docs'],
				'eligible' => $data['eligible'],
				'editorial'=> $data['editorial'],
				'flags'    => $data['flags'],
				// Sin esto el poll veía «configurado sí · publicado no» sin poder
				// distinguir un ajuste pendiente de publicar de uno ilegible.
				'semantic' => $data['semantic'],
				'read_errors' => $data['read_errors'],
			)
		);
		$response->header( 'Cache-Control', 'no-store, max-age=0' );
		return $response;
	}

	/**
	 * Cache-bust de un asset del plugin: VERSIÓN + fecha del fichero.
	 *
	 * Con la constante pelada, editar un asset sin subir la versión del plugin
	 * deja la URL byte-idéntica a la que el navegador ya tiene y el cambio no
	 * llega nunca — y `.htaccess` cachea estos ficheros un año. Fuente única
	 * para los tres encolados del plugin (autocomplete, facetas y panel).
	 *
	 * @param string $relative Ruta dentro del plugin, con o sin barra inicial.
	 * @return string
	 */
	public static function asset_version( string $relative ): string {
		$path = AKIBARA_SEARCH_DIR . ltrim( $relative, '/' );

		return file_exists( $path )
			? AKIBARA_SEARCH_VERSION . '.' . (string) filemtime( $path )
			: AKIBARA_SEARCH_VERSION;
	}

	/**
	 * Encola el cliente vanilla del autocomplete (sin jQuery). Registrado solo
	 * con el flag suggest activo.
	 *
	 * @return void
	 */
	public function enqueue_assets(): void {
		if ( ! self::suggest_enabled() ) {
			return;
		}
		// Generar los cuatro .min desde la raíz del repo con `npm ci` y `npm run build`.
		// build-search-assets.mjs fija esbuild y los flags de cada bundle; agrega
		// el SHA256 del fuente al banner. search-runtime-contract.test.js comprueba
		// el banner siempre y compara los bytes cuando está instalado el compilador.
		// SCRIPT_DEBUG sirve el fuente legible; fallback al fuente si el .min
		// no llegó al deploy (mismo guard file_exists que facets-render).
		$min = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';

		$js = 'assets/js/search-suggest' . $min . '.js';
		if ( '' !== $min && ! file_exists( AKIBARA_SEARCH_DIR . $js ) ) {
			$js = 'assets/js/search-suggest.js';
		}
		$css = 'assets/css/search-suggest' . $min . '.css';
		if ( '' !== $min && ! file_exists( AKIBARA_SEARCH_DIR . $css ) ) {
			$css = 'assets/css/search-suggest.css';
		}

		$handle = 'akb-search-suggest';
		wp_enqueue_script(
			$handle,
			AKIBARA_SEARCH_URL . $js,
			array(),
			self::asset_version( $js ),
			true
		);
		wp_localize_script(
			$handle,
			'AKB_SEARCH',
			array(
				'rest'     => esc_url_raw( rest_url( 'akibara/v1/search/' ) ),
				'minChars' => QueryEngine::MIN_TOKEN_LENGTH,
				'debounce' => 220,
				// La pista de «sin resultados» viaja por acá y NO dentro del
				// bundle: producción sirve el `.min`, así que un texto escrito en
				// el fuente exige regenerar y desplegar el minificado para
				// cambiar una frase. Se edita en Buscador → Ajustes.
				'emptyHint' => EmptyStateCopy::suggest(),
			)
		);
		wp_enqueue_style(
			$handle,
			AKIBARA_SEARCH_URL . $css,
			array(),
			self::asset_version( $css )
		);
	}

	/**
	 * Lee el valor crudo del interruptor en base de datos sin gates semánticos.
	 *
	 * Permite a la interfaz administrativa distinguir entre un servicio explícitamente
	 * desactivado por el operador y un servicio degradado por indisponibilidad de lectura.
	 *
	 * @param string $option Una de las tres options de servicio.
	 * @return bool
	 */
	public static function configured_toggle( string $option ): bool {
		if ( ! in_array( $option, array( self::OPTION_INDEXING, self::OPTION_SUGGEST, self::OPTION_FACETS ), true ) ) {
			return false;
		}

		return 'yes' === (string) get_option( $option, 'no' );
	}

	/**
	 * Flag de indexación, con el override `akb_search_indexing_enabled`.
	 *
	 * @return bool
	 */
	public static function indexing_enabled(): bool {
		return self::filtered_boolean_option( self::OPTION_INDEXING, 'akb_search_indexing_enabled' );
	}

	/**
	 * Determina si se deben registrar los oyentes de mutaciones del catálogo.
	 *
	 * A diferencia de `indexing_enabled()` (que opera bajo fail-closed estricto para serving),
	 * la captura de cambios escucha el catálogo a menos que el servicio haya sido apagado
	 * deliberadamente por configuración. Esto previene la pérdida de eventos de guardado
	 * de productos ante errores transitorios de lectura de configuración.
	 *
	 * @return bool
	 */
	private static function indexing_listeners_wanted(): bool {
		return ! self::indexing_switched_off();
	}

	/**
	 * Determina si la indexación fue desactivada deliberadamente por configuración.
	 *
	 * Retorna true únicamente cuando la configuración se leyó exitosamente y está
	 * establecida en 'no'. Ante errores transitorios de lectura retorna false para
	 * mantener activos los listeners y reparaciones automáticas.
	 *
	 * @return bool
	 */
	public static function indexing_switched_off(): bool {
		if ( self::indexing_enabled() ) {
			return false;
		}

		try {
			return SemanticPublication::authoritative_available();
		} catch ( \Throwable $exception ) {
			return false;
		}
	}

	/**
	 * Gate efectivo del autocomplete: configuración explícita Y lease de
	 * servido vigente.
	 *
	 * @return bool
	 */
	public static function suggest_enabled(): bool {
		if ( ! self::suggest_configured() ) {
			return false;
		}
		try {
			return EditorialDiscountPublication::serving_ready();
		} catch ( \Throwable $exception ) {
			return false;
		}
	}

	/**
	 * Configuración explícita, separada del gate efectivo de serving. Override:
	 * `akb_search_suggest_enabled`.
	 */
	public static function suggest_configured(): bool {
		return self::filtered_boolean_option( self::OPTION_SUGGEST, 'akb_search_suggest_enabled' );
	}

	/**
	 * Gate efectivo de las facetas en el main query: configuración explícita Y
	 * lease de servido vigente.
	 *
	 * @return bool
	 */
	public static function facets_enabled(): bool {
		if ( ! self::facets_configured() ) {
			return false;
		}
		try {
			return EditorialDiscountPublication::serving_ready();
		} catch ( \Throwable $exception ) {
			return false;
		}
	}

	/**
	 * Configuración explícita, separada del gate efectivo de serving. Override:
	 * `akb_search_facets_enabled`.
	 */
	public static function facets_configured(): bool {
		return self::filtered_boolean_option( self::OPTION_FACETS, 'akb_search_facets_enabled' );
	}

	/**
	 * Lee un flag desde la foto semántica y exige un bool exacto del hook.
	 * Throwable o retorno truthy no booleano fallan apagado.
	 */
	private static function filtered_boolean_option( string $option, string $hook ): bool {
		try {
			$enabled = 'yes' === SemanticPublication::authoritative_value( $option, 'no' );
			if ( ! SemanticPublication::authoritative_available() ) {
				return false;
			}
			/**
			 * Permite sobrescribir el estado de un flag del buscador en pruebas de integración y e2e.
			 * El nombre del hook dinámico corresponde a `akb_search_indexing_enabled`,
			 * `akb_search_suggest_enabled` o `akb_search_facets_enabled`.
			 *
			 * @since 1.55.0
			 *
			 * @param bool $enabled Estado del flag en la foto semántica.
			 */
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- $hook sólo puede valer akb_search_indexing_enabled, akb_search_suggest_enabled o akb_search_facets_enabled, los tres con el prefijo declarado en el ruleset.
			$filtered = apply_filters( $hook, $enabled );
		} catch ( \Throwable $exception ) {
			return false;
		}

		return is_bool( $filtered ) ? $filtered : false;
	}
}
