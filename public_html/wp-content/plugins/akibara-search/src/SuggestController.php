<?php
/**
 * SuggestController — endpoints REST del autocomplete y el logging.
 *
 *   GET  /wp-json/akibara/v1/search/suggest?q=...   → tarjetas de producto.
 *   POST /wp-json/akibara/v1/search/track  { q, hits } → registra la búsqueda.
 *
 * LiteSpeed-safe: público SIN nonce (la home/PDP se sirven cacheadas y un
 * nonce embebido nace muerto para invitados — gotcha del proyecto). El
 * endpoint bootea WP igual que admin-ajax; el ahorro real vs el live-ajax de
 * Relevanssi es cacheabilidad + matar jQuery, no CPU (honesto).
 *
 * La palanca de cachear se encendió en 0.1.9 y se apagó en 0.1.14, esta vez de
 * forma permanente: Support\RestCache marca TODA respuesta con `no-store`
 * (origen y borde). El motivo está en la cabecera de esa clase — un purge
 * best-effort no cierra la carrera final-check→store.
 *
 * Rate-limit por IP con soporte Cloudflare (60/min lectura; `/track` posee bucket propio).
 * `/track` es un registro analítico agregado que no altera el ranking de productos.
 *
 * @package Akibara\Search
 */

declare( strict_types = 1 );

namespace Akibara\Search;

use Akibara\Search\Support\RateLimit;
use Akibara\Search\Support\RestCache;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.55.0
 */
final class SuggestController {

	private const REST_NAMESPACE = 'akibara/v1';
	private const ROUTE_SUGGEST  = '/search/suggest';
	private const ROUTE_TRACK    = '/search/track';
	private const ROUTE_POPULAR  = '/search/popular';
	private const ROUTE_REQUEST  = '/search/request';

	/** Resultados devueltos por el autocomplete (paridad live-ajax: 7). */
	private const SUGGEST_LIMIT = 7;

	/**
	 * Identificadores solicitados al motor antes de presentar (over-fetch defensivo).
	 * Compensa posibles filtrados posteriores en ProductPresenter (productos en borrador o privados),
	 * garantizando que el panel complete el límite visual configurado.
	 */
	private const SUGGEST_FETCH = self::SUGGEST_LIMIT * 2;

	/**
	 * Bajo contexto de archive se solicita el conjunto completo de resultados al motor
	 * antes de aplicar el filtro por faceta, preservando el ranking relativo de la serie o categoría.
	 */

	/** Rate-limit best-effort: requests/minuto/IP para invitados. */
	private const RL_PER_MINUTE = 60;

	/**
	 * Techo propio del POST `/track`: es ESCRITURA, y su flood no solo bloatea
	 * disco — la poda del Reconciler borra por `day ASC`, así que sacrifica el
	 * histórico legítimo antes que la basura del día. Un cliente real emite una
	 * query asentada cada varios segundos; 20/min sobra.
	 */
	private const RL_TRACK_PER_MINUTE = 20;

	/**
	 * Límite máximo de caracteres en la consulta antes de tokenizar para evitar sobrecoste en joins.
	 */
	private const MAX_QUERY_LENGTH = 128;

	/** @var QueryEngine */
	private QueryEngine $engine;

	/** @var ProductPresenter */
	private ProductPresenter $presenter;

	/** @var QueryLogger */
	private QueryLogger $logger;

	/** @var FacetService */
	private FacetService $facets;

	/** @var PopularQueries */
	private PopularQueries $popular;

	/** @var SeriesSuggestions */
	private SeriesSuggestions $series;

	/**
	 * @param QueryEngine        $engine    Motor de búsqueda.
	 * @param ProductPresenter   $presenter Presentador de tarjetas.
	 * @param QueryLogger        $logger    Logger agregado.
	 * @param FacetService       $facets    Intersección con el universo facetado
	 *                                      (total real del footer). @since 1.60.0
	 * @param PopularQueries     $popular   Top del vacío-enfocado. @since 1.61.0
	 * @param SeriesSuggestions|null $series Series de `pa_serie` que matchean.
	 *                                      Opcional para no romper a los callers
	 *                                      existentes; el default es el real.
	 *                                      @since 0.1.15
	 */
	public function __construct( QueryEngine $engine, ProductPresenter $presenter, QueryLogger $logger, FacetService $facets, PopularQueries $popular, ?SeriesSuggestions $series = null ) {
		$this->engine    = $engine;
		$this->presenter = $presenter;
		$this->logger    = $logger;
		$this->facets    = $facets;
		$this->popular   = $popular;
		$this->series    = $series ?? new SeriesSuggestions();
	}

	/**
	 * @return void
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_SUGGEST,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_suggest' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'q' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// Ruta HERMANA de suggest (no `?popular=1` sobre la misma: `q` es
		// required allí y daría 400 — gate DISEÑO Fase 1, reproducido).
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_POPULAR,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_popular' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_TRACK,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_track' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_REQUEST,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_request' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * POST /search/request — registra solicitud de título no disponible o encargo.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_request( WP_REST_Request $request ): WP_REST_Response {
		$response = new WP_REST_Response();
		RestCache::no_store( $response );

		if ( $this->rate_limited( 'request', self::RL_TRACK_PER_MINUTE ) ) {
			$response->set_status( 429 );
			$response->set_data(
				array(
					'success' => false,
					'message' => 'Demasiadas solicitudes. Por favor espera un momento.',
				)
			);
			return $response;
		}

		// Honeypot check antispam
		$hp = (string) $request->get_param( 'akb_hp_url' );
		if ( '' !== trim( $hp ) ) {
			$response->set_status( 200 );
			$response->set_data(
				array(
					'success' => true,
					'message' => '¡Solicitud recibida con éxito!',
				)
			);
			return $response;
		}

		$params = (array) $request->get_json_params();
		if ( empty( $params ) ) {
			$params = (array) $request->get_params();
		}

		if ( ! isset( $params['product_id'] ) && $request->get_param( 'product_id' ) ) {
			$params['product_id'] = (int) $request->get_param( 'product_id' );
		}

		$result = TitleRequests::record( $params, RateLimit::client_ip() );

		$response->set_status( $result['success'] ? 200 : 400 );
		$response->set_data( $result );
		return $response;
	}

	/**
	 * GET /suggest — tarjetas de producto rankeadas.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_suggest( WP_REST_Request $request ): WP_REST_Response {
		$response = new WP_REST_Response();
		$publication_token = self::publication_token();

		if ( null === $publication_token || ! Search::suggest_configured() || ! FacetRegistry::is_available() ) {
			return $this->suggest_unavailable( $response );
		}
		if ( $this->rate_limited() ) {
			RestCache::no_store( $response );
			$response->set_status( 429 );
			$response->set_data( array( 'items' => array(), 'code' => 'rate_limited' ) );
			return $response;
		}
		$query = mb_substr( trim( (string) $request->get_param( 'q' ) ), 0, self::MAX_QUERY_LENGTH );
		if ( mb_strlen( $query ) < QueryEngine::MIN_TOKEN_LENGTH ) {
			if ( ! self::publication_token_matches( $publication_token ) ) {
				return $this->suggest_unavailable( $response );
			}
			$response->set_data( array( 'items' => array(), 'query' => $query ) );
			// La query corta no leyó SQL, pero el receipt se verificó arriba.
			RestCache::apply( $response );
			return $response;
		}

		// Contexto del archive (`_serie=<slug>` en /serie/<slug>/, la misma
		// receta de data-attrs que el /count del drawer). Se resuelve ANTES de
		// buscar porque ahora condiciona el fetch, no sólo el `total`.
		try {
			$query_params = $request->get_query_params();
		} catch ( \Throwable $exception ) {
			return $this->suggest_unavailable( $response );
		}
		$filters = is_array( $query_params )
			? FacetQueryIntegration::filters_from_query_vars( $query_params )
			: null;
		if ( null === $filters ) {
			return $this->suggest_unavailable( $response );
		}
		$scoped = $filters->has_facets() || $filters->has_context();

		// Micro-caché server-side en memoria (60s) ligada a index_gen + token de publicación + filtros
		$cache_key = self::microcache_key( $query, $publication_token, $query_params );
		if ( '' !== $cache_key ) {
			$cached_data = function_exists( 'wp_cache_get' ) ? wp_cache_get( $cache_key, 'akb_search' ) : false;
			if ( false === $cached_data && function_exists( 'get_transient' ) ) {
				$cached_data = get_transient( $cache_key );
			}
			if ( is_array( $cached_data ) && isset( $cached_data['items'], $cached_data['total'] ) ) {
				if ( ! self::publication_token_matches( $publication_token ) ) {
					return $this->suggest_unavailable( $response );
				}
				$response->set_data( $cached_data );
				RestCache::apply( $response );
				return $response;
			}
		}

		// suggest() es ATÓMICO (spec v5 D3): la corrección fuzzy (política
		// ESTRICTA — ante ambigüedad calla) viaja EN el retorno, no por un
		// side-channel estático. `corrected` es null sin corrección.
		//
		// Bajo contexto se pide el ranking completo en vez de 14: el recorte del
		// motor ocurre antes de conocer la faceta, así que filtrar después
		// dejaba el panel casi vacío mientras el footer prometía decenas.
		$result    = $this->engine->suggest( $query, $scoped ? QueryEngine::MAX_RESULTS : self::SUGGEST_FETCH );
		if ( ! $this->engine->is_available() ) {
			return $this->suggest_unavailable( $response );
		}
		$ids       = $result['ids'];
		$corrected = $result['corrected'];
		$relaxed   = $result['relaxed'] ?? null;

		// Bajo contexto de archive, filtra las tarjetas por el mismo conjunto de facetas
		// para mantener concordancia exacta con el total del footer y la página de destino.
		$truncated = self::SUGGEST_FETCH === count( $ids );
		if ( $scoped ) {
			// `FacetService::filter()` documenta su retorno como «post_ids (sin
			// ordenar)» — su SELECT no lleva ORDER BY. Quedarse con su salida
			// tal cual y recortarla daría 7 tomos en orden de tabla en vez de
			// los 7 mejor rankeados: en /serie/naruto/ el panel abriría por un
			// tomo cualquiera. Se usa SOLO como conjunto de permitidos y se
			// recorre `$ids` en el orden del motor, que es el que rankea.
			$allowed = $this->facets->filter( $filters, $ids );
			if ( ! $this->facets->is_available() ) {
				return $this->suggest_unavailable( $response );
			}
			$allowed = array_flip( $allowed );
			$ids     = array_values(
				array_filter(
					$ids,
					static function ( $id ) use ( $allowed ) {
						return isset( $allowed[ $id ] );
					}
				)
			);

			// Combina la señal de truncamiento con la del motor para recalcular
			// el total exacto cuando el conjunto excede la capacidad del over-fetch.
			$truncated = $truncated || count( $ids ) > self::SUGGEST_FETCH;
			$ids       = array_slice( $ids, 0, self::SUGGEST_FETCH );
		}
		// Recorte DESPUÉS de presentar: los ids que el presenter descarta por
		// fail-closed (post_status ≠ publish) se reemplazan con los del
		// over-fetch en vez de dejar el hueco (ver SUGGEST_FETCH).
		$cards     = $this->presenter->cards( $ids );
		if ( ! $this->presenter->is_available() ) {
			return $this->suggest_unavailable( $response );
		}
		$items     = array_slice( $cards, 0, self::SUGGEST_LIMIT );

		// Contrato §5.7: `corrected` NUNCA viaja sobre panel vacío (staleness
		// del presenter — un id corregido que ya no resuelve a tarjeta no debe
		// afirmar una corrección que no se ve).
		if ( empty( $items ) ) {
			$corrected = null;
			// Mismo contrato para el aviso de editorial: sin tarjetas a la vista,
			// «esto es lo que tenemos» no señalaría nada.
			$relaxed = null;
		}

		// Excerpts/highlights: resaltar los tokens del término en el título con
		// <mark> (server-side, escapado por Highlighter). El cliente usa
		// title_html. Con corrección, las tarjetas matchean el término REAL:
		// resaltamos contra `fix`, no contra el typo (que no aparece en el
		// título y no resaltaría nada).
		$hl_tokens = Normalizer::tokenize( $corrected['fix'] ?? ( is_array( $relaxed ) ? (string) $relaxed['kept'] : $query ) );
		foreach ( $items as &$hl_item ) {
			$hl_item['title_html'] = Highlighter::mark( (string) $hl_item['title'], $hl_tokens );
		}
		unset( $hl_item );

		// Series que matchean (`pa_serie` → /serie/<slug>/). Van en su PROPIA
		// clave, no mezcladas en `items`: son otra cosa (agrupan tomos, no
		// tienen precio ni stock) y `total`/"Ver los N resultados" siguen
		// contando SOLO productos, que es lo que la página destino muestra.
		//
		// Contra la query CORREGIDA por el mismo motivo que el highlight: si el
		// fuzzy resolvió "jujustu"→"jujutsu", la serie que corresponde es la del
		// término real, no la del typo.
		//
		// DEGRADACIÓN ACOTADA, a diferencia de todo lo de arriba: un fallo acá
		// apaga sólo el grupo de series. Devolver 503 borraría también las
		// tarjetas de producto —lo único que vende— por un bloque que hasta hoy
		// ni existía. La respuesta nunca se almacena públicamente
		// (`RestCache::apply()` fuerza no-store), así que la degradación no
		// queda cacheada para nadie más.
		$series = $this->series->suggest( $corrected['fix'] ?? $query );
		if ( ! $this->series->is_available() ) {
			$series = array();
		}

		// `total` alimenta el footer de resultados: cuando se supera el límite de tarjetas o hubo truncamiento,
		// se evalúa el total exacto aplicando los mismos filtros de faceta sobre la búsqueda completa.
		$total = count( $items );
		if ( count( $cards ) > self::SUGGEST_LIMIT || $truncated ) {
			// Con relajación de editorial, el total se calcula sobre la consulta efectivamente conservada.
			$total_query = $corrected['fix'] ?? ( is_array( $relaxed ) ? (string) $relaxed['kept'] : $query );
			$exact = $this->engine->search( $total_query, false, QueryEngine::MAX_RESULTS, false );
			if ( ! $this->engine->is_available() ) {
				return $this->suggest_unavailable( $response );
			}
			$filtered = $this->facets->filter( $filters, $exact );
			if ( ! $this->facets->is_available() ) {
				return $this->suggest_unavailable( $response );
			}
			$total = count( $filtered );
		}

		if ( ! self::publication_token_matches( $publication_token ) ) {
			return $this->suggest_unavailable( $response );
		}

		$data = array(
			'query'  => $query,
			'items'  => $items,
			'total'  => $total,
			'series' => $series,
		);
		if ( null !== $corrected ) {
			$data['corrected'] = $corrected;
		}
		if ( is_array( $relaxed ) ) {
			$frase = BrandRelax::sentence( (array) $relaxed['ignored'], (string) $relaxed['kept'] );
			if ( '' !== $frase ) {
				$data['relaxed'] = array(
					'ignored'     => array_values( (array) $relaxed['ignored'] ),
					'kept'        => (string) $relaxed['kept'],
					'notice'      => $frase,
					// Ya escapado en el servidor, con las dos piezas en negrita:
					// mismo trato que `title_html` (Highlighter) y por el mismo
					// motivo — el énfasis lo decide quien conoce las piezas.
					'notice_html' => BrandRelax::sentence_html( (array) $relaxed['ignored'], (string) $relaxed['kept'] ),
				);
			}
		}

		if ( '' !== $cache_key ) {
			if ( function_exists( 'set_transient' ) ) {
				set_transient( $cache_key, $data, 60 );
			}
			if ( function_exists( 'wp_cache_set' ) ) {
				wp_cache_set( $cache_key, $data, 'akb_search', 60 );
			}
		}

		$response->set_data( $data );
		// Cachear solo tras completar y revalidar todas las lecturas autoritativas.
		RestCache::apply( $response );
		return $response;
	}

	/**
	 * GET /popular — top de búsquedas para el estado vacío-enfocado.
	 *
	 * Cacheable por RestCache (misma palanca que el suggest): el transient
	 * server-side de 12 h ya absorbía el costo; el TTL edge evita además el
	 * boot de WP. Vacío con el kill-switch `akb_search_popular_enabled` off.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_popular( WP_REST_Request $request ): WP_REST_Response {
		$response = new WP_REST_Response();
		$publication_token = self::publication_token();

		if ( null === $publication_token || ! Search::suggest_configured() ) {
			return $this->suggest_unavailable( $response );
		}

		if ( $this->rate_limited() ) {
			RestCache::no_store( $response );
			$response->set_status( 429 );
			$response->set_data( array( 'items' => array(), 'code' => 'rate_limited' ) );
			return $response;
		}

		// Saneamiento en lectura como defensa en profundidad: qlog se sanea al
		// persistir y el cliente escapa en render, pero este paso garantiza
		// total inocuidad del payload REST servido desde la caché transitoria.
		$top = $this->popular->top();
		if ( ! $this->popular->is_available() ) {
			return $this->suggest_unavailable( $response );
		}
		if ( ! self::publication_token_matches( $publication_token ) ) {
			return $this->suggest_unavailable( $response );
		}
		$response->set_data(
			array(
				'items' => array_map(
					static fn( string $q ): array => array( 'q' => sanitize_text_field( $q ) ),
					$top
				),
			)
		);
		// Populares nace del qlog agregado, no del índice materializado, pero
		// sólo se hace público después de validar kill-switch y SQL/transient.
		RestCache::apply( $response );
		return $response;
	}

	/**
	 * POST /track — registra la búsqueda asentada. Best-effort: siempre 204,
	 * jamás bloquea al cliente ni revela nada.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_track( WP_REST_Request $request ): WP_REST_Response {
		$response = new WP_REST_Response( null, 204 );
		RestCache::no_store( $response ); // POST de escritura: jamás cacheable.
		$publication_token = self::publication_token();
		if ( null === $publication_token || ! Search::suggest_configured() ) {
			return $response;
		}

		// Endpoint público de ESCRITURA: sin rate-limit, un invitado podía
		// generar filas ilimitadas en qlog (PK por query_norm) → bloat de disco
		// [m1]. Bucket PROPIO y más bajo que el de lectura: compartir el de
		// suggest le regalaba a la escritura el presupuesto entero del panel.
		if ( $this->rate_limited( 'track', self::RL_TRACK_PER_MINUTE ) ) {
			return $response; // best-effort: 204 igual, sin registrar.
		}

		$query = mb_substr( trim( (string) $request->get_param( 'q' ) ), 0, self::MAX_QUERY_LENGTH );

		// Gate de largo ANTES del motor. QueryLogger::log() descarta las queries
		// de <2 chars igual, así que ejecutar la búsqueda completa para tirar el
		// resultado era gasto puro: con 1 char no hay ni tokens que corregir,
		// pero el fallback fuzzy leía igual el vocabulario del índice
		// (SELECT DISTINCT sobre la tabla de tokens) en cada POST.
		if ( mb_strlen( $query ) < QueryLogger::MIN_LENGTH ) {
			return $response;
		}
		// Por omisión se asigna SOURCE_SUGGEST: el pool SOURCE_SEARCH nutre
		// la sección de consultas populares y requiere opt-in explícito del
		// submit para evitar que interacciones parciales inflen el ranking.
		$src = QueryLogger::SOURCE_SEARCH === (string) $request->get_param( 'source' )
			? QueryLogger::SOURCE_SEARCH
			: QueryLogger::SOURCE_SUGGEST;

		// `hits` se DERIVA del motor server-side, NO del request: el valor del
		// cliente es falsificable y alimenta el panel admin (MAX(hits), y
		// "oportunidades" = hits=0). Un invitado podía reportar hits=0 para una
		// serie con stock y envenenar el panel [m3]. El motor es autoritativo.
		//
		// `allow_fuzzy: false` explícito: además de ser el camino barato (el
		// fuzzy solo corre con CERO candidatos, que es justo el caso que este
		// endpoint registra), es MÁS correcto para el panel — la métrica de
		// "oportunidades" quiere el cero REAL de lo que el usuario tipeó, no el
		// conteo de la query autocorregida.
		$hits = count( $this->engine->search( $query, false, QueryEngine::MAX_RESULTS, false ) );
		if ( ! $this->engine->is_available()
			|| ! self::publication_token_matches( $publication_token ) ) {
			return $response;
		}

		try {
			$this->logger->log( $query, $hits, $src, $this->user_agent() );
		} catch ( \Throwable $exception ) {
			// /track es analytics best-effort: conserva 204/no-store.
		}

		return $response;
	}

	/**
	 * Control de frecuencia (rate-limit) por IP con ventana de 1 minuto.
	 * Exentos operadores administrativos con capacidad 'manage_woocommerce'.
	 *
	 * @param string $bucket Namespace del límite ('suggest' lectura, 'track' escritura).
	 * @param int    $max    Techo de requests/minuto de ese bucket.
	 * @return bool
	 */
	private function rate_limited( string $bucket = 'suggest', int $max = self::RL_PER_MINUTE ): bool {
		try {
			return RateLimit::per_minute( $bucket, $max );
		} catch ( \Throwable $exception ) {
			return true; // falla segura: GET 429, POST 204 sin escritura.
		}
	}

	/**
	 * @return string
	 */
	private function user_agent(): string {
		try {
			return isset( $_SERVER['HTTP_USER_AGENT'] ) && is_scalar( $_SERVER['HTTP_USER_AGENT'] )
				? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_USER_AGENT'] ) )
				: '';
		} catch ( \Throwable $exception ) {
			return '';
		}
	}

	/**
	 * Clave única para la micro-caché en memoria del suggest (60s).
	 *
	 * Combina el término normalizado, la generación viva del índice, el token de
	 * publicación de descuentos editoriales y los parámetros de filtrado/contexto.
	 *
	 * @param string               $query             Término buscado.
	 * @param string               $publication_token Token de descuentos.
	 * @param array<string, mixed> $query_params      Parámetros de facetas/contexto.
	 * @return string
	 */
	private static function microcache_key( string $query, string $publication_token, array $query_params ): string {
		if ( defined( 'AKIBARA_SEARCH_TESTING' ) && AKIBARA_SEARCH_TESTING ) {
			return '';
		}
		$index_gen = function_exists( 'get_option' ) ? (string) get_option( Install::OPTION_INDEX_GEN, '' ) : '';
		$sorted    = $query_params;
		ksort( $sorted );
		$sig       = http_build_query( $sorted );
		return 'akb_sug_' . md5( $query . '|' . $index_gen . '|' . $publication_token . '|' . $sig );
	}

	/** Token fresco capturado antes de cualquier lectura del endpoint. */
	private static function publication_token(): ?string {
		if ( ! class_exists( EditorialDiscountPublication::class ) ) {
			return null;
		}
		try {
			$token = EditorialDiscountPublication::capture_serving_token();
		} catch ( \Throwable $exception ) {
			return null;
		}

		return is_string( $token ) && '' !== $token ? $token : null;
	}

	/** Revalidación final protegida de cada endpoint. */
	private static function publication_token_matches( string $token ): bool {
		try {
			return EditorialDiscountPublication::serving_token_matches( $token );
		} catch ( \Throwable $exception ) {
			return false;
		}
	}

	/**
	 * Respuesta uniforme ante índice no autoritativo o lectura SQL fallida.
	 *
	 * @param WP_REST_Response $response Respuesta en construcción.
	 * @return WP_REST_Response
	 */
	private function suggest_unavailable( WP_REST_Response $response ): WP_REST_Response {
		Fuzzy::flush();
		RestCache::no_store( $response );
		// Transitorio: el cliente reintenta con backoff desde este valor en vez
		// de pintar «No pudimos cargar sugerencias» (0.1.64, ventana de guardado).
		RestCache::retry_after( $response );
		$response->set_status( 503 );
		$response->set_data(
			array(
				'items' => array(),
				'code'  => 'index_unavailable',
			)
		);
		return $response;
	}
}
