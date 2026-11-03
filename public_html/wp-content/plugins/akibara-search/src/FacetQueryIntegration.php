<?php
/**
 * FacetQueryIntegration — aplica facetas + ranking al main query del shop.
 *
 * Hook `woocommerce_product_query` (NO `pre_get_posts` crudo — pelea de
 * prioridades con WC_Query, informe §4.3). El main query de WP/WC sigue
 * paginando y RENDERIZANDO: solo le inyectamos `post__in` con los ids que
 * pasan las facetas y, si hay búsqueda, el orden de relevancia del motor.
 * Visibilidad, LoopEnhancer, Interleaver quedan intactos.
 *
 * Matriz de precedencia (ordenación × búsqueda × filtros):
 *   - Con búsqueda y SIN orderby explícito del usuario ⇒ relevancia
 *     (`post__in`, respetando el orden del motor).
 *   - Con orderby explícito (precio/título) ⇒ ese orden pisa la relevancia
 *     (el usuario mandó). OJO: WC siempre setea menu_order por defecto, así que
 *     detectamos el orderby por el GET real, no por el query var.
 *   - Solo facetas (sin búsqueda) ⇒ `post__in` + el orden normal de WC.
 *   - Sin nada ⇒ no tocamos el query (shop normal).
 *
 * Todo detrás del flag `akb_search_facets` (default OFF).
 *
 * @package Akibara\Search
 */

declare( strict_types = 1 );

namespace Akibara\Search;

use Akibara\Search\Support\RateLimit;
use Akibara\Search\Support\RestCache;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.55.0
 */
final class FacetQueryIntegration {

	/** Sentinela para forzar 0 resultados sin pasar un post__in vacío (que WC ignora). */
	private const NO_MATCH_ID = -1;

	/** @var QueryEngine */
	private QueryEngine $engine;

	/** @var FacetService */
	private FacetService $facets;

	/** @var array<int, int>|null Resultado memoizado del request (para el renderer de facetas). */
	private ?array $last_result = null;

	/** @var array<int, int>|null Ids de búsqueda del request (null = sin término). */
	private ?array $last_search_ids = null;

	/** Lo que trae quitar los chips (0 sin texto o sin chips): lo que el texto encuentra donde está el cliente. */
	private int $search_total_memo = 0;

	/** @var array<string, mixed>|null Counts memoizados del request (view() se llama ~5 veces por render). */
	private ?array $counts_memo = null;

	/** Token de publicación capturado una sola vez en init:24. */
	private ?string $request_publication_token = null;

	/** La lectura de query vars se ejecuta una sola vez, incluso si falla. */
	private bool $filters_resolved = false;

	/** @var FilterSet|null Foto normalizada e inmutable de los filtros del request. */
	private ?FilterSet $filters_memo = null;

	/** Original normalized filters survive deactivation for the native fallback. */
	private ?FilterSet $native_filters_memo = null;

	/** Raw query vars are read once, including route overrides. */
	private bool $raw_query_vars_resolved = false;

	/** @var array<string,mixed>|null */
	private ?array $raw_query_vars_memo = null;

	/** El contexto del archive se resuelve una sola vez antes del check final. */
	private bool $archive_context_resolved = false;

	/** @var array{facet: string, value: string}|null */
	private ?array $archive_context_memo = null;

	/** La petición es un archivo de taxonomía de producto, tenga o no faceta dueña. */
	private bool $product_archive = false;

	/** Distingue counts vacíos válidos de counts aún no calculados. */
	private bool $counts_prepared = false;

	/** FilterSet exacto (incluido contexto) usado por counts y view. */
	private ?FilterSet $view_filters_memo = null;

	/** El check final de la lease se consume una sola vez tras TODAS las lecturas. */
	private bool $lease_finalized = false;

	/** Resultado sticky del único check final request-local. */
	private bool $lease_accepted = false;

	/** @var FacetsView */
	private FacetsView $view;

	/** @var self|null Instancia del request (para el helper global del theme). */
	private static ?self $current = null;

	/**
	 * Instancia armada en init:24 y todavía sin resolver el serving.
	 *
	 * Separa "hay integración para este request" de "ya pagamos la captura del
	 * token": `current()` y los tres consumidores reales la resuelven a demanda.
	 *
	 * @var self|null
	 */
	private static ?self $armed = null;

	/** La captura del token se paga una sola vez, incluso si devuelve null. */
	private bool $serving_resolved = false;

	/**
	 * Instancia activa del request, o null si el filtrado propio está OFF.
	 *
	 * @return self|null
	 */
	public static function current(): ?self {
		if ( null !== self::$armed ) {
			self::$armed->resolve_serving();
		}

		return self::$current;
	}

	/**
	 * @param QueryEngine  $engine Motor de búsqueda.
	 * @param FacetService $facets Servicio de facetas.
	 */
	public function __construct( QueryEngine $engine, FacetService $facets ) {
		$this->engine = $engine;
		$this->facets = $facets;
		$this->view   = new FacetsView();
	}

	/**
	 * @return void
	 */
	public function register(): void {
		$this->reset_request_state();
		add_action( 'init', array( $this, 'handle_init' ), 24 );
		add_action( 'woocommerce_product_query', array( $this, 'apply' ), 20 );
		add_action( 'rest_api_init', array( $this, 'register_rest' ) );
	}

	/**
	 * Activa los helpers después de que el proveedor Business y taxonomías carguen.
	 *
	 * @internal
	 */
	public function handle_init(): void {
		$this->reset_request_state();
		// init:24 arma la integración de forma diferida. La captura del token de serving
		// se ejecuta bajo demanda únicamente cuando un consumidor del front lo requiere,
		// evitando queries directas innecesarias en wp-admin, WP-Cron y endpoints ajenos.
		self::$armed = $this;
	}

	/**
	 * Captura diferida del token de serving: una sola vez por request.
	 *
	 * @return void
	 */
	/**
	 * El catálogo de ESTE request se sirve en fail-open porque el gate de
	 * publicación está cerrado [revisión de 0.1.64].
	 *
	 * Sin facetas hay dos casos que el HTML no distinguía: el archivo vacío sin
	 * filtros ni búsqueda, que omite el form A PROPÓSITO
	 * (`akibara_gp_akb_render_sidebar()`), y el catálogo sin filtrar que se sirve
	 * mientras la publicación no casa —la ventana de un guardado editorial, o un
	 * cierre largo—. El cliente reintentaba los dos: tres renders completos de
	 * más y siete segundos de página atenuada en un archivo que nunca iba a traer
	 * facetas. Esta bandera es la que el HTML marca y la única que el cliente
	 * reintenta.
	 */
	private static bool $fail_open_por_gate = false;

	/** @return bool True si este request sirve fail-open por el gate de publicación. */
	public static function fail_open_por_gate(): bool {
		return self::$fail_open_por_gate;
	}

	private function resolve_serving(): void {
		if ( $this->serving_resolved ) {
			return;
		}
		$this->serving_resolved = true;
		if ( self::$armed !== $this ) {
			// Sin `handle_init` (o con otra instancia dueña del request) no hay
			// nada que resolver: se conserva el fail-closed que daba el token
			// null cuando la captura vivía en init:24.
			$this->request_publication_token = null;
			return;
		}
		self::$armed                     = null;
		$this->request_publication_token = self::capture_publication_token();
		self::$current = null !== $this->request_publication_token && Search::facets_configured() ? $this : null;
		// Facetas configuradas y la publicación no sirve: el catálogo sale en
		// fail-open POR EL GATE. Sin configurar no hay nada que reintentar.
		self::$fail_open_por_gate = null === $this->request_publication_token && Search::facets_configured();
		if ( null === self::$current ) {
			$this->request_publication_token = null;
		}
	}

	/**
	 * Endpoint de conteo liviano para el footer "Ver N productos" del drawer
	 * mobile (batch): total del set filtrado SIN renderizar el grid. Público
	 * sin nonce (LiteSpeed-safe); cacheable por RestCache desde 0.1.9 (misma
	 * palanca y tag que el suggest — era el endpoint más caro del módulo).
	 *
	 * @return void
	 */
	public function register_rest(): void {
		register_rest_route(
			'akibara/v1',
			'/search/count',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_count' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * GET /search/count?_facet=valor&... → { total: N }.
	 *
	 * @param \WP_REST_Request $request Request con los mismos params `_*` de la tienda.
	 * @return \WP_REST_Response
	 */
	public function handle_count( $request ) {
		$this->resolve_serving();
		$publication_token = $this->request_publication_token;
		if ( self::$current !== $this || null === $publication_token ) {
			return $this->count_unavailable();
		}
		// Es el endpoint más caro del módulo (search + filter) y era el ÚNICO
		// público sin rate-limit. Límite generoso (el drawer mobile lo llama
		// debounced por tap): 120/min/IP. Al exceder, 429 → el JS conserva el
		// número anterior (updateCount ignora !r.ok) [m2].
		try {
			$limited = RateLimit::per_minute( 'count', 120 );
		} catch ( \Throwable $exception ) {
			$limited = true;
		}
		if ( $limited ) {
			$response = new \WP_REST_Response( array( 'total' => null, 'code' => 'rate_limited' ), 429 );
			RestCache::no_store( $response );
			return $response;
		}
		try {
			$params = $request->get_query_params();
		} catch ( \Throwable $exception ) {
			return $this->count_unavailable();
		}
		$filters = is_array( $params ) ? self::filters_from_query_vars( $params ) : null;
		if ( null === $filters ) {
			return $this->count_unavailable();
		}

		$search_ids = $filters->has_search()
			? $this->engine->search( $filters->search(), false, QueryEngine::MAX_RESULTS )
			: null;
		if ( ! $this->engine->is_available() ) {
			return $this->count_unavailable();
		}

		$filtered = $this->facets->filter( $filters, $search_ids );
		if ( ! $this->facets->is_available()
			|| ! $this->finalize_request_lease( $publication_token ) ) {
			return $this->count_unavailable();
		}
		$total = count( $filtered );

		$response = new \WP_REST_Response( array( 'total' => $total ) );
		RestCache::apply( $response );
		return $response;
	}

	/**
	 * Aplica facetas + ranking al main query del shop/archives/búsqueda.
	 *
	 * @param \WP_Query $query El product query de WC.
	 * @return void
	 */
	public function apply( $query ): void {
		if ( ! $query instanceof \WP_Query ) {
			return;
		}
		try {
			if ( ! $query->is_main_query() || is_admin() || $query->is_feed() ) {
				return; // [AUDIT] is_feed: woocommerce_product_query dispara en /shop/?feed=rss.
			}
			$taxonomies = get_object_taxonomies( 'product' );
			if ( ! is_array( $taxonomies )
				|| ! ( $query->is_post_type_archive( 'product' ) || $query->is_tax( $taxonomies ) || $query->is_search() ) ) {
				return;
			}
		} catch ( \Throwable $exception ) {
			$this->deactivate( $query );
			return;
		}

		// Después de los guards baratos: sólo un main query de producto/búsqueda
		// justifica pagar la captura del token.
		$this->resolve_serving();
		$publication_token = $this->request_publication_token;
		if ( self::$current !== $this || null === $publication_token ) {
			$this->deactivate( $query );
			return;
		}

		$filters = $this->current_filters();
		if ( null === $filters ) {
			$this->deactivate( $query );
			return;
		}
		$has_constraint = $filters->has_facets() || $filters->has_search();

		// Ids de búsqueda (rankeados) si hay término; null si no.
		$search_ids = $has_constraint && $filters->has_search()
			? $this->engine->search( $filters->search(), false, QueryEngine::MAX_RESULTS )
			: null;
		if ( ! $this->engine->is_available() ) {
			$this->deactivate( $query );
			return;
		}

		$this->last_search_ids = $search_ids;
		if ( ! $has_constraint ) {
			// El landing no muta WP_Query, pero su panel sí consume counts. Deben
			// quedar leídos antes del único check final igual que el grid filtrado.
			if ( ! $this->prepare_counts( $filters ) || ! $this->finalize_request_lease( $publication_token ) ) {
				$this->deactivate( $query );
			}
			$price_order = $this->requested_price_orderby();
			if ( null !== $price_order && function_exists( 'add_filter' ) ) {
				add_filter( 'posts_clauses', array( $this, 'filter_price_orderby_clauses' ), 30, 2 );
			}
			return;
		}

		$result = $this->facets->filter( $filters, $search_ids );
		if ( ! $this->facets->is_available() ) {
			$this->deactivate( $query );
			return;
		}

		// Si hay búsqueda, preservar el ORDEN de relevancia del motor: el
		// facet filter intersecta pero desordena; re-proyectamos sobre el orden
		// del motor.
		if ( null !== $search_ids ) {
			$keep   = array_flip( $result );
			$result = array_values( array_filter( $search_ids, static fn( $id ) => isset( $keep[ $id ] ) ) );
		}

		$this->last_result = $result;

		if ( empty( $result ) ) {
			// Fail-open: espejo VACÍO ≠ "0 matches reales". Sin este guard, un
			// índice muerto entre rebuilds convertía toda búsqueda/filtrado en
			// 0 productos EN SILENCIO — el modo-muerte que este módulo combate.
			// Se degrada al catálogo sin filtrar y se avisa fuerte.
			if ( $this->facets->index_is_empty() ) {
				$this->report_empty_index();
				$this->deactivate( $query ); // catálogo fail-open; helpers Search quedan mudos.
				return;
			}
			if ( ! $this->facets->is_available() || ! $this->prepare_counts( $filters )
				|| ! $this->finalize_request_lease( $publication_token ) ) {
				$this->deactivate( $query );
				return;
			}
			$query->set( 'post__in', array( self::NO_MATCH_ID ) );
			return;
		}

		// Counts/context/FilterSet se congelan ANTES del único final-check. Así
		// view() nunca puede mezclar grid S0 con counts S1 si un writer entra
		// después de apply(); ambos se sirven desde la misma foto request-local.
		if ( ! $this->prepare_counts( $filters ) ) {
			$this->deactivate( $query );
			return;
		}
		$set_relevance = null !== $search_ids && ! $this->user_chose_orderby();
		if ( ! $this->finalize_request_lease( $publication_token ) ) {
			$this->deactivate( $query );
			return;
		}

		$query->set( 'post__in', $result );

		// Orden de relevancia SOLO si hay búsqueda y el usuario no pidió otro
		// orderby explícito por GET.
		if ( $set_relevance ) {
			$query->set( 'orderby', 'post__in' );
		} else {
			$price_order = $this->requested_price_orderby();
			if ( null !== $price_order && function_exists( 'add_filter' ) ) {
				add_filter( 'posts_clauses', array( $this, 'filter_price_orderby_clauses' ), 30, 2 );
			}
		}
	}

	/**
	 * View-model de facetas para el request actual — la estructura que el theme
	 * imprime. Si el shop no está filtrado ni buscando, recomputa los filtros
	 * desde $_GET (apply() puede no haber corrido si no había nada que tocar):
	 * en ese caso el total lo pasa el theme (wp_query->found_posts).
	 *
	 * @param int|null $total_override Total del main query (si el renderer lo tiene).
	 * @return array<string, mixed>|null
	 */
	public function view( ?int $total_override = null ): ?array {
		$this->resolve_serving();
		$publication_token = $this->request_publication_token;
		if ( self::$current !== $this || null === $publication_token || ! $this->engine->is_available() || ! $this->facets->is_available() ) {
			$this->deactivate();
			return null;
		}
		$filters = $this->current_filters();
		if ( null === $filters ) {
			$this->deactivate();
			return null;
		}

		if ( ( $filters->has_facets() || $filters->has_search() ) && null === $this->last_result ) {
			// Un view filtrado antes de woocommerce_product_query no puede prometer
			// paridad con un grid que todavía no fue calculado.
			$this->deactivate();
			return null;
		}
		if ( ! $this->prepare_counts( $filters ) || ! $this->finalize_request_lease( $publication_token )
			|| null === $this->view_filters_memo || null === $this->counts_memo ) {
			$this->deactivate();
			return null;
		}
		$filters = $this->view_filters_memo;

		if ( null !== $total_override ) {
			$total = $total_override;
		} elseif ( null !== $this->last_result ) {
			// apply() corrió (había filtros/búsqueda): total = ids filtrados.
			$total = count( $this->last_result );
		} else {
			// Catálogo sin filtros aplicados: el total real corresponde a `found_posts`
			// de la consulta principal de WooCommerce (`$GLOBALS['wp_query']`).
			$total = isset( $GLOBALS['wp_query'] ) && $GLOBALS['wp_query'] instanceof \WP_Query
				? (int) $GLOBALS['wp_query']->found_posts
				: 0;
		}

		if ( ! $this->facets->is_available() ) {
			$this->deactivate();
			return null;
		}

		try {
			$view = $this->view->build( $this->counts_memo, $filters, $total );
		} catch ( \Throwable $exception ) {
			$this->deactivate();
			return null;
		}
		// Total de resultados de la búsqueda pura (sin facetas): permite distinguir
		// entre una búsqueda sin coincidencias y una búsqueda vaciada por filtros acumulados.
		$view['search_total'] = $this->search_total_memo;
		if ( ! FacetRegistry::is_available() ) {
			$this->deactivate();
			return null;
		}

		return $view;
	}

	/**
	 * Contexto implícito del archive actual: término consultado → faceta dueña.
	 * Público: el theme lo usa para inyectar el contexto en el REST /count del
	 * drawer (data-attrs del form) — el endpoint lo recibe como param normal.
	 *
	 * @return array{facet: string, value: string}|null
	 */
	public function archive_context(): ?array {
		if ( $this->archive_context_resolved ) {
			return $this->archive_context_memo;
		}
		$this->archive_context_resolved = true;
		$this->resolve_serving();
		if ( self::$current !== $this || ! function_exists( 'is_tax' ) ) {
			return null;
		}

		try {
			$taxonomies = get_object_taxonomies( 'product' );
			if ( ! is_array( $taxonomies ) || ! is_tax( $taxonomies ) ) {
				return null;
			}
			$this->product_archive = true;
			$term                  = get_queried_object();
		} catch ( \Throwable $exception ) {
			$this->deactivate();
			return null;
		}
		if ( ! $term instanceof \WP_Term || '' === (string) $term->slug ) {
			return null;
		}

		$facet = $this->facet_for_term( $term );
		if ( self::$current !== $this || null === $facet ) {
			return null;
		}
		$this->archive_context_memo = array(
			'facet' => $facet,
			'value' => (string) $term->slug,
		);

		return $this->archive_context_memo;
	}

	/**
	 * Faceta dueña de un término: matchea taxonomía y, si la definición scopea
	 * por parent_term (demografia = hijos de Manga, sello = hijos de Cómics),
	 * exige el parent exacto — ese match específico le gana a la faceta
	 * genérica de la misma taxonomía (categoria).
	 *
	 * @param \WP_Term $term Término del archive.
	 * @return string|null
	 */
	private function facet_for_term( \WP_Term $term ): ?string {
		$fallback = null;
		try {
			$definitions = FacetRegistry::definitions();
		} catch ( \Throwable $exception ) {
			$this->deactivate();
			return null;
		}
		if ( ! FacetRegistry::is_available() ) {
			$this->deactivate();
			return null;
		}
		foreach ( $definitions as $name => $def ) {
			if ( 'taxonomy' !== ( $def['type'] ?? '' ) || ( $def['taxonomy'] ?? '' ) !== $term->taxonomy ) {
				continue;
			}
			if ( isset( $def['parent_term'] ) && '' !== (string) $def['parent_term'] ) {
				if ( (int) $term->parent === (int) $def['parent_term'] ) {
					return (string) $name;
				}
				continue;
			}
			// root_only (Tipo): dueña SOLO de términos raíz no excluidos — los
			// hijos son de demografia/sello (match parent_term arriba); nietos
			// u otros huérfanos quedan sin dueña (null → sin pre-selección).
			if ( ! empty( $def['root_only'] ) ) {
				try {
					$excluded = FacetRegistry::is_excluded_root_term( (int) $term->term_id );
				} catch ( \Throwable $exception ) {
					$this->deactivate();
					return null;
				}
				if ( ! FacetRegistry::is_available() ) {
					$this->deactivate();
					return null;
				}
				if ( 0 !== (int) $term->parent || $excluded ) {
					continue;
				}
			}
			if ( null === $fallback ) {
				$fallback = (string) $name;
			}
		}
		return $fallback;
	}

	/**
	 * FilterSet del request actual, desde $_GET (whitelist del registry).
	 *
	 * @return FilterSet|null Null si una fuente de normalización no fue autoritativa.
	 */
	public function current_filters(): ?FilterSet {
		if ( $this->filters_resolved ) {
			return $this->filters_memo;
		}
		$this->filters_resolved = true;
		$get = $this->raw_query_vars();
		if ( null === $get ) {
			return null;
		}
		$this->filters_memo = self::filters_from_query_vars( $get );
		$this->native_filters_memo = $this->filters_memo;
		return $this->filters_memo;
	}

	/** @return array<string,mixed>|null Query vars including route overrides, read once. */
	private function raw_query_vars(): ?array {
		if ( $this->raw_query_vars_resolved ) {
			return $this->raw_query_vars_memo;
		}
		$this->raw_query_vars_resolved = true;
		try {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public catalogue filters; validated by the registry and prepared by their query consumer.
			$get = wp_unslash( $_GET );
			$get = apply_filters( 'akb_search_query_vars', is_array( $get ) ? $get : array() );
			$this->raw_query_vars_memo = is_array( $get ) ? $get : null;
		} catch ( \Throwable $exception ) {
			$this->raw_query_vars_memo = null;
		}
		return $this->raw_query_vars_memo;
	}

	/**
	 * Único parser protegido para page, /count y suggest total.
	 *
	 * @param array<string, mixed> $query_vars Query vars crudas.
	 * @return FilterSet|null
	 */
	public static function filters_from_query_vars( array $query_vars ): ?FilterSet {
		try {
			$definitions = FacetRegistry::definitions();
			if ( ! is_array( $definitions ) || empty( $definitions ) || ! FacetRegistry::is_available() ) {
				return null;
			}
			$single = FacetRegistry::single_select_facets();
			if ( ! is_array( $single ) || ! FacetRegistry::is_available() ) {
				return null;
			}
			$known = array_keys( $definitions );
			foreach ( $known as $name ) {
				if ( ! is_string( $name ) || '' === $name ) {
					return null;
				}
			}
			foreach ( $single as $name ) {
				if ( ! is_string( $name ) || ! isset( $definitions[ $name ] ) ) {
					return null;
				}
			}

			return self::normalize_filters( FilterSet::from_query( $query_vars, $known, $single ) );
		} catch ( \Throwable $exception ) {
			return null;
		}
	}

	/**
	 * Normaliza un FilterSet recién parseado: para cada faceta `root_only`
	 * (Tipo), un valor que no es término raíz se REMAPEA a su faceta dueña
	 * (shonen → demografia, sellos análogo) o se descarta si no resuelve.
	 *
	 * Por qué: la faceta listaba el árbol completo, así que hay bookmarks y
	 * páginas cacheadas (LiteSpeed/CF) vivas con `?_categoria=shonen`. Tras el
	 * recorte a raíces ese valor ya no está indexado bajo `categoria`: sin
	 * remapeo, el JOIN AND no matchea nada → 0 resultados + chip fantasma con
	 * slug crudo (gate DISEÑO, hallazgo #2). Remapear preserva la intención
	 * (search-ux: cero-resultados es falla de UX); lo irresoluble cae a drop
	 * silencioso — mismo espíritu que el guard all-invalid de `precio`.
	 *
	 * DEBE aplicarse en LOS TRES sitios que construyen FilterSet desde params
	 * (current_filters, handle_count, SuggestController): un remapeo parcial
	 * haría que el contador del drawer o el total del suggest digan 0 frente a
	 * una página que sí remapea. `FilterSet` sigue puro: el lookup vive acá.
	 * Corre ANTES de with_context (el contexto de archive no se normaliza) y
	 * re-colapsa single-select en el destino por si algún día una faceta radio
	 * recibe un remapeo (hoy demografia/sello son checkboxes).
	 *
	 * @param FilterSet $filters FilterSet recién construido (sin contexto).
	 * @return FilterSet|null El mismo objeto si no hubo cambios; null ante error de lectura.
	 */
	public static function normalize_filters( FilterSet $filters ): ?FilterSet {
		if ( ! function_exists( 'get_terms' ) ) {
			return $filters;
		}

		$selections = $filters->all();
		$changed    = false;
		try {
			$definitions = FacetRegistry::definitions();
		} catch ( \Throwable $exception ) {
			return null;
		}
		if ( ! FacetRegistry::is_available() ) {
			return null;
		}

		foreach ( $definitions as $name => $def ) {
			if ( empty( $def['root_only'] ) || empty( $selections[ $name ] ) ) {
				continue;
			}
			$taxonomy = (string) ( $def['taxonomy'] ?? '' );
			$keep     = array();

			foreach ( $selections[ $name ] as $value ) {
				$term = self::read_term_by_slug( $value, $taxonomy );
				if ( false === $term['available'] ) {
					return null;
				}
				$term = $term['term'];
				if ( is_array( $term ) && 0 === (int) $term['parent'] ) {
					$excluded = FacetRegistry::is_excluded_root_term( (int) $term['term_id'] );
					if ( ! FacetRegistry::is_available() ) {
						return null;
					}
					if ( ! $excluded ) {
						$keep[] = $value;
						continue;
					}
				}
				$changed = true;
				if ( ! is_array( $term ) || 0 === (int) $term['parent'] ) {
					continue; // Inexistente o raíz excluida: drop silencioso.
				}
				$owner = self::facet_owning_parent( (int) $term['parent'], $taxonomy );
				if ( ! FacetRegistry::is_available() ) {
					return null;
				}
				if ( null === $owner ) {
					continue; // Nieto/huérfano sin faceta dueña: drop.
				}
				if ( ! in_array( $value, $selections[ $owner ] ?? array(), true ) ) {
					$selections[ $owner ][] = $value;
				}
			}

			if ( empty( $keep ) ) {
				unset( $selections[ $name ] );
			} else {
				$selections[ $name ] = $keep;
			}
		}

		if ( ! $changed ) {
			return $filters;
		}

		// Re-colapso single-select post-remap (guard: hoy los destinos son
		// checkboxes, pero una radio no puede quedar con dos valores).
		try {
			$single_select = FacetRegistry::single_select_facets();
		} catch ( \Throwable $exception ) {
			return null;
		}
		if ( ! FacetRegistry::is_available() ) {
			return null;
		}
		foreach ( $single_select as $single ) {
			if ( isset( $selections[ $single ] ) && count( $selections[ $single ] ) > 1 ) {
				$selections[ $single ] = array( $selections[ $single ][0] );
			}
		}

		return new FilterSet( $selections, $filters->search(), $filters->paged() );
	}

	/**
	 * Lee un término y captura inmediatamente error SQL/Throwable/WP_Error.
	 * No usa `get_term_by()`: Core convierte el WP_Error de `get_terms()` en
	 * false y vuelve indistinguible una ausencia real de un fallo de fuente.
	 *
	 * @param string $slug     Slug solicitado.
	 * @param string $taxonomy Taxonomía solicitada.
	 * @return array{available: bool, term: mixed}
	 */
	private static function read_term_by_slug( string $slug, string $taxonomy ): array {
		try {
			$term = FacetRegistry::authoritative_term_by_slug( $slug, $taxonomy );
		} catch ( \Throwable $exception ) {
			return array( 'available' => false, 'term' => null );
		}
		if ( ! FacetRegistry::is_available() ) {
			return array( 'available' => false, 'term' => null );
		}
		if ( false === $term ) {
			return array( 'available' => true, 'term' => false );
		}
		if ( ! is_array( $term ) ) {
			return array( 'available' => false, 'term' => null );
		}

		return array(
			'available' => true,
			'term'      => $term,
		);
	}

	/**
	 * Faceta cuya definición scopea por `parent_term` exactamente a este parent
	 * (demografia = hijos de Manga, sello = hijos de Cómics); null si ninguna.
	 *
	 * @param int    $parent   term_id del padre del término remapeado.
	 * @param string $taxonomy Taxonomía del término.
	 * @return string|null
	 */
	private static function facet_owning_parent( int $parent, string $taxonomy ): ?string {
		try {
			$definitions = FacetRegistry::definitions();
		} catch ( \Throwable $exception ) {
			return null;
		}
		if ( ! FacetRegistry::is_available() ) {
			return null;
		}
		foreach ( $definitions as $name => $def ) {
			if ( 'taxonomy' === ( $def['type'] ?? '' )
				&& ( $def['taxonomy'] ?? '' ) === $taxonomy
				&& isset( $def['parent_term'] ) && (int) $def['parent_term'] === $parent ) {
				return (string) $name;
			}
		}
		return null;
	}

	/**
	 * True si el usuario eligió un orderby explícito (dropdown de la tienda).
	 * WC siempre setea menu_order por defecto, así que el query var no sirve:
	 * miramos el GET real.
	 *
	 * @return bool
	 */
	private function user_chose_orderby(): bool {
		try {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- sólo se mide SI el cliente eligió un orden; el valor lo resuelve WooCommerce después.
			$value = isset( $_GET['orderby'] ) && is_scalar( $_GET['orderby'] ) ? sanitize_text_field( wp_unslash( $_GET['orderby'] ) ) : '';
			$value = trim( $value );

			// `menu_order` en el contexto de una búsqueda corresponde al orden por relevancia
			// por defecto (primera opción del selector de ordenación en Akibara). No se considera
			// una elección explícita de orden alternativo para preservar el score del motor.
			if ( 'menu_order' === $value ) {
				return false;
			}

			return '' !== $value;
		} catch ( \Throwable $exception ) {
			// Entrada no representable: conservar el orderby de WooCommerce.
			return true;
		}
	}

	/**
	 * Intercepta posts_clauses para ordenar por el precio efectivo de Akibara
	 * (docs.price_effective) en lugar de wc_product_meta_lookup.min_price cuando
	 * el usuario seleccionó orderby=price o orderby=price-desc.
	 *
	 * @param array<string, string> $clauses Cláusulas SQL de WP_Query.
	 * @param \WP_Query             $query   Instancia actual de WP_Query.
	 * @return array<string, string>
	 */
	public function filter_price_orderby_clauses( array $clauses, \WP_Query $query ): array {
		if ( ! $query->is_main_query() ) {
			return $clauses;
		}

		$orderby_choice = $this->requested_price_orderby();
		if ( null === $orderby_choice ) {
			return $clauses;
		}

		global $wpdb;
		$docs_table = Install::docs_table();

		if ( false === strpos( $clauses['join'] ?? '', $docs_table ) ) {
			$clauses['join'] = ( $clauses['join'] ?? '' ) . " LEFT JOIN {$docs_table} akb_d ON akb_d.post_id = {$wpdb->posts}.ID ";
		}

		$direction          = 'price-desc' === $orderby_choice ? 'DESC' : 'ASC';
		$clauses['orderby'] = " akb_d.price_effective {$direction}, {$wpdb->posts}.ID {$direction} ";

		if ( function_exists( 'remove_filter' ) ) {
			remove_filter( 'posts_clauses', array( $this, 'filter_price_orderby_clauses' ), 30 );
		}

		return $clauses;
	}

	/**
	 * Retorna 'price' o 'price-desc' si el usuario pidió ordenar por precio por GET, o null.
	 *
	 * @return string|null
	 */
	private function requested_price_orderby(): ?string {
		try {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- solo se mide si el cliente pidió orden de precio por GET.
			$value = isset( $_GET['orderby'] ) && is_scalar( $_GET['orderby'] ) ? sanitize_text_field( wp_unslash( $_GET['orderby'] ) ) : '';
			$value = trim( $value );
			if ( 'price' === $value || 'price-desc' === $value ) {
				return $value;
			}
			return null;
		} catch ( \Throwable $exception ) {
			return null;
		}
	}

	/**
	 * Materializa exactamente una vez todos los insumos que view() expondrá.
	 * Debe ejecutarse antes del único check final de la publicación.
	 */
	private function prepare_counts( FilterSet $filters ): bool {
		if ( $this->counts_prepared ) {
			return null !== $this->counts_memo && null !== $this->view_filters_memo
				&& $this->facets->is_available();
		}
		if ( $this->lease_finalized ) {
			return false; // nunca abrir lecturas nuevas después del final-check.
		}
		$this->counts_prepared = true;
		$view_filters = $filters;
		$context      = $this->archive_context();
		if ( self::$current !== $this ) {
			return false;
		}
		if ( null !== $context ) {
			try {
				$view_filters = $filters->with_context( $context['facet'], $context['value'] );
			} catch ( \Throwable $exception ) {
				return false;
			}
		}

		try {
			$counts = $this->facets->counts( $view_filters, $this->last_search_ids );
		} catch ( \Throwable $exception ) {
			return false;
		}
		if ( ! is_array( $counts ) || ! $this->facets->is_available() ) {
			return false;
		}

		// Conteo de productos al remover chips activos: evalúa coincidencias de texto
		// dentro del contexto del archivo actual (categoría, serie, sello), permitiendo
		// que el aviso de cero resultados oriente al usuario a limpiar filtros específicos.
		$search_total = 0;
		if ( null !== $this->last_search_ids && $filters->has_facets()
			&& ( null !== $context || ! $this->product_archive ) ) {
			$search_total = count( $this->last_search_ids );
			if ( null !== $context && $search_total > 0 ) {
				try {
					$en_el_archivo = $this->facets->filter(
						( new FilterSet( array() ) )->with_context( $context['facet'], $context['value'] ),
						$this->last_search_ids
					);
				} catch ( \Throwable $exception ) {
					return false;
				}
				if ( ! $this->facets->is_available() ) {
					return false;
				}
				$search_total = count( $en_el_archivo );
			}
		}

		$this->counts_memo        = $counts;
		$this->view_filters_memo  = $view_filters;
		$this->search_total_memo  = $search_total;

		return true;
	}

	/** Consume una sola revalidación final después de engine/facets/counts/context. */
	private function finalize_request_lease( string $token ): bool {
		if ( $this->lease_finalized ) {
			return $this->lease_accepted && null !== $this->request_publication_token
				&& hash_equals( $this->request_publication_token, $token );
		}
		$this->lease_finalized = true;
		if ( null === $this->request_publication_token || ! hash_equals( $this->request_publication_token, $token ) ) {
			return false;
		}
		try {
			$this->lease_accepted = EditorialDiscountPublication::serving_token_matches( $token );
		} catch ( \Throwable $exception ) {
			$this->lease_accepted = false;
		}
		if ( ! $this->lease_accepted ) {
			// La publicación cambió mientras se atendía el request: la misma ventana.
			self::$fail_open_por_gate = true;
		}

		return $this->lease_accepted;
	}

	/** Captura inicial única; serving_ready() no se vuelve a abrir desde helpers. */
	private static function capture_publication_token(): ?string {
		if ( ! class_exists( EditorialDiscountPublication::class ) ) {
			return null;
		}
		try {
			$token = EditorialDiscountPublication::capture_serving_token();
		} catch ( \Throwable $exception ) {
			return null;
		}

		return is_string( $token ) && '' !== $token && strlen( $token ) <= 128
			&& ! preg_match( '/[\x00-\x20\x7F]/', $token ) ? $token : null;
	}

	/** Logging/hook del fail-open: ningún callback de terceros puede fatalizarlo. */
	private function report_empty_index(): void {
		try {
			if ( function_exists( 'wc_get_logger' ) ) {
				$logger = wc_get_logger();
				if ( is_object( $logger ) && is_callable( array( $logger, 'error' ) ) ) {
					$logger->error(
						'Índice de búsqueda VACÍO con filtrado activo — fail-open al catálogo sin filtrar. Reconstruir el índice (wp akb-search rebuild).',
						array( 'source' => 'akibara-search' )
					);
				}
			}
		} catch ( \Throwable $exception ) {
			// El diagnóstico nunca gobierna la respuesta.
		}
		try {
			do_action( 'akb_search_index_empty' );
		} catch ( \Throwable $exception ) {
			// Hook observacional best-effort.
		}
	}

	/** Limpia todos los memos al iniciar la única request de este objeto. */
	private function reset_request_state(): void {
		self::$fail_open_por_gate        = false;
		self::$current                    = null;
		self::$armed                      = null;
		$this->serving_resolved            = false;
		$this->last_result                = null;
		$this->last_search_ids            = null;
		$this->search_total_memo          = 0;
		$this->counts_memo                = null;
		$this->request_publication_token  = null;
		$this->filters_resolved            = false;
		$this->filters_memo                = null;
		$this->native_filters_memo         = null;
		$this->raw_query_vars_resolved      = false;
		$this->raw_query_vars_memo          = null;
		$this->archive_context_resolved    = false;
		$this->archive_context_memo        = null;
		$this->product_archive             = false;
		$this->counts_prepared             = false;
		$this->view_filters_memo           = null;
		$this->lease_finalized             = false;
		$this->lease_accepted              = false;
	}

	/** Preserve availability instead of silently widening a filtered request. */
	private function preserve_native_availability( \WP_Query $query ): void {
		try {
			$raw = $this->raw_query_vars();
			$filters = $this->native_filters_memo;
			if ( null === $filters && is_array( $raw ) ) {
				$filters = self::filters_from_query_vars( $raw );
			}
			$requested = is_array( $raw ) && ! empty( $raw['_disponibilidad'] );
			if ( $filters instanceof FilterSet && empty( $filters->values( 'disponibilidad' ) ) ) {
				return;
			}
			if ( ! $requested && ! ( $filters instanceof FilterSet ) ) {
				return;
			}
			$ok = $filters instanceof FilterSet && NativeAvailabilityFallback::apply( $query, $filters, FacetRegistry::definitions() );
		} catch ( \Throwable $exception ) {
			$ok = false;
		}
		if ( function_exists( 'nocache_headers' ) ) {
			nocache_headers();
		}
		do_action( 'litespeed_control_set_nocache', 'Native availability fallback' );
		if ( ! $ok ) {
			wp_die( 'Estamos actualizando los filtros. Vuelve a intentarlo en unos instantes.', 'Filtros en actualización', array( 'response' => 503 ) );
		}
	}

	/** Cierra helpers y memos solo para este request. */
	private function deactivate( ?\WP_Query $query = null ): void {
		if ( null !== $query && Search::facets_configured() ) {
			$this->preserve_native_availability( $query );
		}
		if ( self::$current === $this ) {
			self::$current = null;
		}
		// Un request desactivado no vuelve a capturar: `current()` no puede
		// resucitar la integración a través del puntero armado.
		if ( self::$armed === $this ) {
			self::$armed = null;
		}
		$this->serving_resolved = true;
		$this->last_result     = null;
		$this->last_search_ids = null;
		$this->search_total_memo = 0;
		$this->counts_memo     = null;
		$this->request_publication_token = null;
		$this->filters_resolved         = true;
		$this->filters_memo             = null;
		$this->archive_context_resolved = true;
		$this->archive_context_memo     = null;
		$this->product_archive          = false;
		$this->counts_prepared          = true;
		$this->view_filters_memo        = null;
		$this->lease_finalized          = true;
		$this->lease_accepted           = false;
		try {
			Fuzzy::flush();
		} catch ( \Throwable $exception ) {
			// Limpieza request-local best-effort.
		}
	}

	/**
	 * Respuesta uniforme ante índice no autoritativo o lectura SQL fallida.
	 *
	 * @return \WP_REST_Response
	 */
	private function count_unavailable(): \WP_REST_Response {
		$this->deactivate();
		$response = new \WP_REST_Response(
			array(
				'total' => null,
				'code'  => 'index_unavailable',
			),
			503
		);
		RestCache::no_store( $response );
		// Transitorio: el botón «Ver N productos» reintenta con backoff desde
		// este valor en vez de quedarse con un número viejo (0.1.64).
		RestCache::retry_after( $response );
		return $response;
	}
}
