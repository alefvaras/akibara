<?php
/**
 * Indexer — persistencia del índice + escucha de cambios (incremental).
 *
 * Escritura transaccional por producto protegida por trabajo durable y un
 * fence MariaDB. Action Scheduler despierta workers, pero nunca es la fuente
 * de verdad de una mutación pendiente.
 *
 * Los eventos por producto y por término materializan primero work durable.
 * El wake-up se deduplica sólo contra acciones pendientes; una acción en curso
 * no puede ocultar la revisión siguiente.
 *
 * Cobertura exhaustiva de eventos de catálogo:
 *   - save_post_product, stock status (producto y variación), set_object_terms
 *     (taxonomías indexadas + product_visibility), edited_term/delete_term
 *     (+ `pre_delete_term`: los hijos directos hay que capturarlos ANTES de
 *     que core los reparentee, ver on_pre_delete_term), deleted/trashed post.
 *   - `wc_after_products_starting_sales` / `wc_after_products_ending_sales`:
 *     las ofertas programadas de WC cambian precio SIN pasar por save_post —
 *     sin esto el facet `oferta` mentiría el día del lanzamiento de promos.
 *   - `akibara_taxonomy_discount_pct_saved`: un % editorial cambia el precio
 *     efectivo de todos los productos del término.
 *   - Toggle de `woocommerce_hide_out_of_stock_items` ⇒ rebuild completo
 *     (afecta `visible` de todo el catálogo).
 *
 * @package Akibara\Search
 */

declare( strict_types = 1 );

namespace Akibara\Search;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.55.0
 */
final class Indexer {

	/** Hook async de reindex de UN producto. */
	public const HOOK_REINDEX = 'akb_search_reindex_product';

	/** Hook async sin argumentos que despierta el pump durable live. */
	public const HOOK_PUMP = 'akb_search_pump_live';

	/** Hook async de reindex por término (batched). */
	public const HOOK_REINDEX_TERM = 'akb_search_reindex_term';

	/** Grupo Action Scheduler del módulo. */
	public const AS_GROUP = 'akibara-search';

	/** Resultado transitorio: existe deuda pero este caller no la completó. */
	public const RESULT_PENDING = 'pending';

	/** Resultado transitorio: el intento falló y quedó para retry. */
	public const RESULT_FAILED = 'failed';

	/** Tamaño de cada lote durable derivado del snapshot de un término. */
	private const TERM_BATCH_SIZE = 100;

	/** Trabajo live máximo drenado por cada wake-up. */
	private const LIVE_PUMP_LIMIT = 25;

	/** Duración de una lease de indexación. */
	private const LEASE_SECONDS = 60;

	/**
	 * Reloj máximo que el BUCLE de un lote pasa reclamando y comprometiendo TX1.
	 *
	 * ACOTA EL BUCLE, NO LA COLA — y la diferencia importa. Cuando el reloj se
	 * pasa, el claim que ya está en vuelo termina sin cota (una lectura de fuente
	 * puede tardar lo que quiera), y después vienen la publicación y hasta
	 * `LIVE_PUMP_LIMIT` TX2. Por eso el techo de verdad no es este número: es el
	 * arriendo del PRIMER claim del lote, del que se deriva el deadline real en
	 * `live_batch_deadline()` (con el reloj de PHP, sin leer fechas de MySQL).
	 * Este presupuesto es sólo el más estricto de los dos en el caso normal.
	 *
	 * Por qué hace falta un techo: el lote conserva sus claims LEASED entre el
	 * COMMIT de filas y el ACK (ésa es la deuda durable del protocolo). Si un
	 * arriendo venciera antes de su ACK, otro worker podría reclamar la fila y el
	 * ACK del primero moriría stale — correcto, pero trabajo tirado.
	 *
	 * `LEASE_SECONDS / 6`, el mismo margen que la hermana del rebuild
	 * (`RebuildJob::PUMP_BUDGET_SECONDS` = 20 sobre un arriendo de 120), que
	 * además ACKea dentro de su bucle y por eso tolera más. Medido en el espejo:
	 * un claim vivo cuesta 50-60 ms con publicación incluida, así que 25 caben en
	 * ~1,5 s y este techo sólo actúa ante un producto patológico.
	 */
	private const LIVE_BATCH_SECONDS = 10;

	/**
	 * Margen que el lote reserva DENTRO del arriendo del primer claim para todo
	 * lo que ocurre después del bucle: el claim que cruza la línea, la
	 * publicación (fence + cuatro escrituras de option) y la cola de TX2.
	 */
	private const LIVE_BATCH_CLOSE_MARGIN = 15;

	/** Barrera durable para hooks que ocurren antes del write de relaciones. */
	private const TERM_RELATIONSHIP_DELAY = 10;

	/** Máximo exacto representable por DECIMAL(12,2). */
	private const DECIMAL_12_2_MAX = 9999999999.99;

	/** Límites byte conservadores de las columnas string del documento. */
	private const DOC_STRING_BYTE_LIMITS = array(
		'title'        => 65535,
		'title_norm'   => 255,
		'serie_norm'   => 255,
		'autores_norm' => 255,
		'sku'          => 64,
		'sku_norm'     => 64,
		'url'          => 255,
		'thumb_url'    => 255,
		'price_html'   => 1000,
	);

	/** @var DocumentBuilder */
	private DocumentBuilder $builder;

	/** @var WorkRepository */
	private WorkRepository $work;

	/** @var WriteFence */
	private WriteFence $fence;

	/** Fuente compartida y observable previa a wc_get_product(). */
	private ProductSourceAuthority $source_authority;

	/**
	 * Hijos DIRECTOS de cada término por borrarse, capturados en
	 * `pre_delete_term` (en `delete_term` core YA los reparentó y son
	 * irrecuperables). Una sola tax_query posterior incluye sus subárboles.
	 *
	 * @var array<int, array{taxonomy: string, children: array<int, int>, complete: bool}>
	 */
	private array $pending_term_children = array();

	/**
	 * Términos cuya edición en curso no toca nada que el índice use, según lo
	 * decidió `on_pre_edit_term()` antes del UPDATE de Core.
	 *
	 * @var array<int, true>
	 */
	private array $inert_term_edits = array();

	/**
	 * @param DocumentBuilder $builder Constructor de documentos.
	 * @param WorkRepository  $work    Outbox/manifest durable.
	 * @param WriteFence                  $fence            Fence de escrituras live y swap.
	 * @param ProductSourceAuthority|null $source_authority Autoridad compartida del request.
	 */
	public function __construct( DocumentBuilder $builder, WorkRepository $work, WriteFence $fence, ?ProductSourceAuthority $source_authority = null ) {
		global $wpdb;
		$this->builder          = $builder;
		$this->work             = $work;
		$this->fence            = $fence;
		$this->source_authority = $source_authority ?? new ProductSourceAuthority( $wpdb );
		$this->builder->set_source_authority( $this->source_authority );
	}

	/**
	 * Constructor de documentos del índice live, ya ligado a la autoridad de
	 * fuente de este request.
	 *
	 * Público para que `EditorialDiscountPublication` pueda re-probar una deuda
	 * editorial con EL MISMO builder que escribe el índice, en vez de fabricar
	 * un segundo cálculo de «precio esperado».
	 */
	public function document_builder(): DocumentBuilder {
		return $this->builder;
	}

	/** Autoridad de fuente compartida (la que el builder tiene instalada). */
	public function source_authority(): ProductSourceAuthority {
		return $this->source_authority;
	}

	/**
	 * Handlers de AS — SIEMPRE registrados (los jobs pendientes deben poder
	 * correr aunque el flag de escucha se apague a mitad de camino).
	 *
	 * @return void
	 */
	public function register_handlers(): void {
		add_action( self::HOOK_REINDEX, array( $this, 'handle_reindex' ) );
		add_action( self::HOOK_PUMP, array( $this, 'handle_live_pump' ), 10, 1 );
		add_action( self::HOOK_REINDEX_TERM, array( $this, 'handle_reindex_term' ), 10, 2 );
	}

	/**
	 * Listeners de cambios — solo con el flag de indexación activo.
	 *
	 * @return void
	 */
	public function register_listeners(): void {
		add_action( 'save_post_product', array( $this, 'on_save_post' ), 20, 3 );
		// Frontera post-persistencia de WC CRUD. save_post puede ocurrir antes de
		// meta/términos o no ocurrir cuando el data store usa $wpdb directamente.
		add_action( 'woocommerce_new_product', array( $this, 'on_product_persisted' ), 10, 2 );
		add_action( 'woocommerce_update_product', array( $this, 'on_product_persisted' ), 10, 2 );
		add_action( 'woocommerce_new_product_variation', array( $this, 'on_variation_persisted' ), 10, 2 );
		add_action( 'woocommerce_update_product_variation', array( $this, 'on_variation_persisted' ), 10, 2 );
		add_action( 'woocommerce_before_product_object_save', array( $this, 'on_before_product_persisted' ), 10, 2 );
		add_action( 'woocommerce_product_set_stock_status', array( $this, 'on_stock_status' ), 12, 1 );
		add_action( 'woocommerce_variation_set_stock_status', array( $this, 'on_variation_stock_status' ), 12, 1 );
		add_action( 'add_term_relationship', array( $this, 'on_pre_term_relationship_change' ), 10, 3 );
		add_action( 'delete_term_relationships', array( $this, 'on_pre_term_relationship_change' ), 10, 3 );
		add_action( 'set_object_terms', array( $this, 'on_set_object_terms' ), 10, 4 );
		add_action( 'edit_terms', array( $this, 'on_pre_edit_term' ), 10, 3 );
		add_action( 'edited_term', array( $this, 'on_edited_term' ), 10, 3 );
		add_action( 'pre_delete_term', array( $this, 'on_pre_delete_term' ), 10, 2 );
		add_action( 'delete_term', array( $this, 'on_delete_term' ), 10, 5 );
		add_action( 'before_delete_post', array( $this, 'on_before_post_state_change' ), 10, 2 );
		add_action( 'wp_trash_post', array( $this, 'on_before_post_state_change' ), 10, 2 );
		add_action( 'untrash_post', array( $this, 'on_before_post_state_change' ), 10, 2 );
		add_action( 'deleted_post', array( $this, 'on_deleted_post' ), 10, 2 );
		add_action( 'trashed_post', array( $this, 'on_deleted_post' ), 10, 2 );
		add_action( 'untrashed_post', array( $this, 'on_untrashed_post' ), 10, 2 );
		add_action( 'wc_after_products_starting_sales', array( $this, 'on_scheduled_sales' ), 10, 1 );
		add_action( 'wc_after_products_ending_sales', array( $this, 'on_scheduled_sales' ), 10, 1 );
		add_action( 'akibara_taxonomy_discount_pct_saved', array( $this, 'on_discount_pct_saved' ), 10, 1 );
		add_action( 'update_option_woocommerce_hide_out_of_stock_items', array( $this, 'on_visibility_option_toggle' ), 10, 2 );
	}

	/**
	 * Marca y procesa un producto conservando el resultado explícito para CLI.
	 *
	 * `$forzar_publicacion` es la palanca del OPERADOR: `wp akb-search reindex`
	 * se usa justo cuando el índice está raro (un recibo de purga perdido, una
	 * entrada REST rancia), y ahí «las filas ya coinciden, no publico» sería la
	 * respuesta inútil. Con el flag, el claim reescribe y publica igual.
	 *
	 * @param int  $product_id         Producto.
	 * @param bool $forzar_publicacion Reescribir y publicar aunque nada cambie.
	 * @return string indexed|absent|pending|failed.
	 */
	public function index_product_outcome( int $product_id, bool $forzar_publicacion = false ): string {
		if ( ! Install::runtime_schema_ready() ) {
			Install::request_rebuild( 'runtime_schema_unready' );
			return self::RESULT_FAILED;
		}
		if ( $product_id <= 0 || ! $this->work->mark_live_dirty( $product_id ) ) {
			if ( 0 < $product_id ) {
				Install::request_rebuild( 'live_debt_persist_failed' );
			}
			return self::RESULT_FAILED;
		}

		$outcome = $this->process_live_product( $product_id, false, $forzar_publicacion );
		if ( in_array( $outcome, array( self::RESULT_PENDING, self::RESULT_FAILED ), true ) ) {
			$this->wake_live_work();
		}

		return $outcome;
	}

	/**
	 * Marca deuda durable y procesa la baja por el mismo pipeline live.
	 *
	 * @param int $product_id Producto.
	 * @return bool True sólo si la ausencia quedó aplicada.
	 */
	public function delete_product( int $product_id ): bool {
		if ( ! Install::runtime_schema_ready() ) {
			Install::request_rebuild( 'runtime_schema_unready' );
			return false;
		}
		if ( $product_id <= 0 || ! $this->work->mark_live_dirty( $product_id ) ) {
			if ( 0 < $product_id ) {
				Install::request_rebuild( 'live_debt_persist_failed' );
			}
			return false;
		}

		$outcome = $this->process_live_product( $product_id );
		if ( in_array( $outcome, array( self::RESULT_PENDING, self::RESULT_FAILED ), true ) ) {
			$this->wake_live_work();
		}

		return WorkRepository::OUTCOME_ABSENT === $outcome;
	}

	/**
	 * Confirma una baja cuyo DELETE fuente ya ocurrió. `deleted_post` se dispara
	 * antes de que Core limpie el object cache: releer ahí puede devolver el
	 * WP_Post/WC_Product publicado que acaba de borrarse y resucitarlo en el
	 * índice. La ausencia conocida conserva el mismo work, fence, repair y ACK;
	 * sólo evita esa relectura insegura.
	 *
	 * @param int $product_id Producto eliminado definitivamente.
	 * @return bool True sólo si la ausencia quedó aplicada.
	 */
	private function delete_product_known_absent( int $product_id ): bool {
		if ( ! Install::runtime_schema_ready() ) {
			Install::request_rebuild( 'runtime_schema_unready' );
			return false;
		}
		if ( $product_id <= 0 || ! $this->work->mark_live_dirty( $product_id ) ) {
			if ( 0 < $product_id ) {
				Install::request_rebuild( 'live_debt_persist_failed' );
			}
			return false;
		}

		$outcome = $this->process_live_product( $product_id, true );
		if ( in_array( $outcome, array( self::RESULT_PENDING, self::RESULT_FAILED ), true ) ) {
			$this->wake_live_work();
		}

		return WorkRepository::OUTCOME_ABSENT === $outcome;
	}

	/**
	 * Persiste primero la deuda y sólo después intenta despertar un worker.
	 *
	 * @param int $product_id Producto.
	 * @return void
	 */
	public function enqueue_product( int $product_id ): void {
		$this->enqueue_products( array( $product_id ) );
	}

	/**
	 * Materializa un conjunto de productos y emite un único wake-up.
	 *
	 * @param array<int, int> $product_ids Productos fuente.
	 * @return bool True si todos los lotes quedaron durables.
	 */
	public function enqueue_products( array $product_ids ): bool {
		if ( ! Install::runtime_schema_ready() ) {
			Install::request_rebuild( 'runtime_schema_unready' );
			return false;
		}
		$ids = array_values(
			array_unique(
				array_filter( array_map( 'intval', $product_ids ), static fn( int $id ): bool => 0 < $id ),
				SORT_NUMERIC
			)
		);
		sort( $ids, SORT_NUMERIC );
		if ( array() === $ids ) {
			return false;
		}

		$persisted_all = true;
		$persisted_any = false;
		foreach ( array_chunk( $ids, self::TERM_BATCH_SIZE ) as $chunk ) {
			if ( ! $this->work->mark_live_dirty_many( $chunk ) ) {
				$persisted_all = false;
				continue;
			}
			$persisted_any = true;
		}

		if ( $persisted_any ) {
			$this->wake_live_work();
		}
		if ( ! $persisted_all ) {
			Install::request_rebuild( 'live_batch_persist_failed' );
		}

		return $persisted_all && $persisted_any;
	}

	/**
	 * Intenta despertar el pump; Action Scheduler nunca representa la deuda.
	 *
	 * @return bool True si ya existe un pending o AS aceptó el wake-up.
	 */
	public function wake_live_work(): bool {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return false;
		}
		if ( $this->has_pending_action( self::HOOK_PUMP, array() ) ) {
			return true;
		}

		$action_id = as_enqueue_async_action( self::HOOK_PUMP, array(), self::AS_GROUP );

		return is_int( $action_id ) && 0 !== $action_id;
	}

	/**
	 * Garantiza un wake posterior a una barrera pre-write.
	 *
	 * El dedup pregunta a la BASE DE DATOS y su predicado NO lleva fecha, igual
	 * que las dos hermanas (`wake_live_work()` aquí abajo y
	 * `RebuildJob::enqueue_wakeup()`): «¿hay ALGUNA pendiente con este hook y
	 * estos args?».
	 *
	 * Antes memoizaba en un array de INSTANCIA comparando contra
	 * `time() + $delay + 2`, y las dos mitades fallaban a la vez: el array nace
	 * vacío en cada petición PHP (nunca veía lo que dejó la petición anterior) y
	 * el listón sube con el reloj, así que ninguna acción anterior lo cumplía
	 * jamás. Resultado: una fila nueva en Action Scheduler por cada petición.
	 * Medido: la cola de `akb_search_pump_live` pasó de 25 a 1.268 pendientes en
	 * una hora sin que nadie tocara nada, y el retraso de `akb_search_rebuild_batch`
	 * respecto de su hora programada subió de 8-29 s a 433-450 s. La deuda no se
	 * pierde —vive en la tabla de trabajo, no en la cola—, pero el turno para
	 * drenarla sí.
	 *
	 * Las barreras son un conjunto CERRADO de literales, así que el techo pasa de
	 * ilimitado a una acción pendiente por barrera. Y no hay pendientes lejanas
	 * que puedan tapar un re-armado: todas se programan a `+delay+2` con demoras
	 * de 10 s.
	 *
	 * @param int    $delay_seconds Demora mínima.
	 * @param string $barrier       Barrera que lo pide (conjunto cerrado).
	 * @return bool
	 */
	public function wake_live_work_after( int $delay_seconds, string $barrier = 'source_write' ): bool {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return false;
		}
		$args = array( 'barrier' => sanitize_key( $barrier ) );
		if ( $this->has_pending_action( self::HOOK_PUMP, $args ) ) {
			return true;
		}

		$action_id = as_schedule_single_action(
			time() + $delay_seconds + 2,
			self::HOOK_PUMP,
			$args,
			self::AS_GROUP,
			false
		);

		return is_int( $action_id ) && 0 !== $action_id;
	}

	/**
	 * ¿Hay un job PENDIENTE (encolado, aún sin correr) para este hook + args?
	 *
	 * `as_has_scheduled_action()` es rápido pero matchea pending Y en-curso sin
	 * distinguir; para decidir el dedup necesitamos SOLO el pending (el job que
	 * todavía tomará el estado fresco de este cambio). Un job en-curso ya leyó el
	 * producto (o los productos del término), así que NO debe deduplicar el
	 * cambio nuevo.
	 *
	 * Sin `as_get_scheduled_actions` (Action Scheduler muy viejo) devolvemos
	 * false: encolar de más es idempotente (el reindex es DELETE+INSERT); perder
	 * la escritura, no. Fail-open hacia la corrección.
	 *
	 * @param string                   $hook Hook del job.
	 * @param array<int|string, mixed> $args Args del job.
	 * @return bool True si hay al menos un job pendiente con esos args.
	 */
	private function has_pending_action( string $hook, array $args ): bool {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return false;
		}

		$pending = as_get_scheduled_actions(
			array(
				'hook'     => $hook,
				'args'     => $args,
				'group'    => self::AS_GROUP,
				'status'   => 'pending', // ActionScheduler_Store::STATUS_PENDING.
				'per_page' => 1,
			),
			'ids'
		);

		return ! empty( $pending );
	}

	// -----------------------------------------------------------------------
	// Handlers AS.
	// -----------------------------------------------------------------------

	/**
	 * Adaptador de acciones pre-upgrade: vuelve a crear deuda durable.
	 *
	 * @param int $product_id Producto encolado por una versión anterior.
	 * @return void
	 */
	public function handle_reindex( $product_id ): void {
		$this->enqueue_product( (int) $product_id );
	}

	/**
	 * Drena una cantidad acotada de claims live y reaviva el remanente.
	 *
	 * UNA publicación por LOTE, no por producto [0.1.64]. Hasta 0.1.63 cada
	 * claim cerraba su propio ciclo `request/consume/satisfied` y con él una
	 * generación nueva de índice, un recibo de purga, una huella semántica y un
	 * recibo editorial. Medido en el espejo tras un cambio editorial —que ensucia
	 * el catálogo entero, ~1.630 productos—: un solo `wp-cron.php` hizo 2.304
	 * `updated_option` en 27 s, un ciclo cada 50-60 ms, con generaciones
	 * consecutivas. Y el gate público lee ese bundle DOS veces por petición y
	 * exige que cuadre byte a byte (`fresh_publication_identity()` →
	 * `bundle_is_ready()`), así que la petición que caía entre medias respondía
	 * 503: la sonda a 4 Hz vio entre 30 % y 60 % de 503 durante el drenaje.
	 *
	 * El lote conserva sus claims LEASED entre el COMMIT de filas y la
	 * publicación —la misma deuda durable de siempre, ahora compartida— y sólo
	 * ACKea después del recibo físico. Ver `close_live_batch()`.
	 *
	 * LO QUE ESTO EMPEORA, dicho sin adornos: una muerte del proceso entre TX1 y
	 * la publicación deja ahora hasta `LIVE_PUMP_LIMIT` productos con filas
	 * comprometidas y sin publicar, donde antes dejaba uno. El atenuante es real
	 * y está probado: esas filas no se pierden ni se dan por buenas, porque al
	 * vencer el arriendo el re-claim entra con `attempts >= 2` y
	 * `live_claim_is_a_noop()` obliga a publicarlas. La ventana se acota con el
	 * deadline derivado del arriendo (`live_batch_deadline()`).
	 *
	 * @return void
	 */
	public function handle_live_pump( $context = null ): void {
		unset( $context );
		if ( ! Install::runtime_schema_ready() ) {
			Install::request_rebuild( 'runtime_schema_unready' );
			// `request_rebuild()` sólo escribe la señal que consume `init`: no
			// encola NADA en Action Scheduler. Sin re-armar aquí, la cadena de
			// pumps se corta y la deuda live espera al watchdog
			// (`RebuildJob::WATCHDOG_INTERVAL` = 900 s).
			$this->rearm_if_live_debt_pending( 'runtime_schema_unready' );
			return;
		}
		$lote     = array();
		$deadline = null;
		for ( $processed = 0; $processed < self::LIVE_PUMP_LIMIT; $processed++ ) {
			$antes_del_claim = microtime( true );
			$claim           = $this->work->claim_one( WorkRepository::SCOPE_LIVE, '', self::LEASE_SECONDS );
			if ( null === $claim ) {
				// `claim_one_for_update()` filtra `available_at <= UTC_TIMESTAMP()`,
				// así que `null` significa indistintamente «no hay deuda» y «la
				// hay, y aún no ha madurado». Toda escritura de precio estampa
				// una barrera de PREWRITE_DELAY sobre las filas que ensucia: si
				// el pump cae dentro de esa ventana, el `return` pelado dejaba el
				// carril mudo hasta el watchdog.
				$this->close_live_batch( $lote );
				$this->rearm_if_live_debt_pending( 'live_debt_immature' );
				return;
			}

			$staged = $this->stage_live_claim( $claim );
			if ( is_array( $staged ) ) {
				$lote[]   = $staged;
				$deadline = $deadline ?? self::live_batch_deadline( $antes_del_claim );
			}
			if ( null !== $deadline && microtime( true ) >= $deadline ) {
				// El deadline protege los arriendos del lote, no la CPU: ningún
				// claim debe esperar su ACK más allá de su propia lease. El
				// remanente se drena en la vuelta siguiente.
				break;
			}
		}

		$this->close_live_batch( $lote );
		$this->wake_live_work();
	}

	/**
	 * Instante en que el lote deja de reclamar: lo ANTES entre el presupuesto de
	 * reloj y el fin del arriendo del PRIMER claim menos el margen de cierre.
	 *
	 * Lo que hay que proteger es el ACK del claim más viejo del lote, así que la
	 * cuenta arranca en el arriendo de ESE claim y no en la entrada del pump (el
	 * primer claim reclamado puede fallar su TX1 y soltarse; entonces el más
	 * viejo del lote es el siguiente).
	 *
	 * UN SOLO RELOJ, el de PHP, en los dos extremos. `lease_until` lo escribe
	 * MySQL con su propio reloj; compararlo con `microtime()` convertía cualquier
	 * desfase entre servidores en un deadline falso — con PHP adelantado más de
	 * 45 s, cada lote se cortaba en un claim y el drenaje volvía a publicar una
	 * generación por producto, como en 0.1.63. En vez de leer la fecha, se toma
	 * el instante justo ANTES del `claim_one()`: el arriendo empieza después, así
	 * que dura por lo menos hasta `$antes_del_claim + LEASE_SECONDS`. Es una cota
	 * conservadora y no interpreta ninguna fecha ni zona horaria.
	 *
	 * @param float $antes_del_claim `microtime(true)` tomado justo antes del `claim_one()` del primer claim staged.
	 * @return float Instante (microtime) en que hay que dejar de reclamar.
	 */
	private static function live_batch_deadline( float $antes_del_claim ): float {
		return min(
			$antes_del_claim + self::LIVE_BATCH_SECONDS,
			$antes_del_claim + self::LEASE_SECONDS - self::LIVE_BATCH_CLOSE_MARGIN
		);
	}

	/**
	 * Re-arma el pump sólo si queda deuda live que VAYA a madurar.
	 *
	 * `blocked` queda fuera a propósito: no madura nunca, y re-armar por una fila
	 * bloqueada sería un bucle de wakes cada 12 s — el mismo defecto que esto
	 * viene a cerrar. Por eso NO sirve `outstanding_count()`, que cuenta todo lo
	 * que no está ackeado, bloqueadas incluidas.
	 *
	 * Ante un fallo de lectura degrada ABIERTO, igual que `handle_watchdog()`: un
	 * wake de más no cuesta nada (el dedup lo topa en uno por barrera); uno de
	 * menos congela el índice un cuarto de hora.
	 *
	 * @param string $barrier Barrera con la que se re-arma.
	 * @return void
	 */
	private function rearm_if_live_debt_pending( string $barrier ): void {
		$summary = $this->work->summary();
		if ( ! is_array( $summary ) ) {
			$this->wake_live_work_after( self::TERM_RELATIONSHIP_DELAY, $barrier );
			return;
		}

		$live  = $summary[ WorkRepository::SCOPE_LIVE ] ?? array();
		$vivas = (int) ( $live[ WorkRepository::STATUS_PENDING ] ?? 0 )
			+ (int) ( $live[ WorkRepository::STATUS_LEASED ] ?? 0 );

		if ( 0 < $vivas ) {
			$this->wake_live_work_after( self::TERM_RELATIONSHIP_DELAY, $barrier );
		}
	}

	/**
	 * Reclama y procesa la deuda live de un producto concreto.
	 *
	 * @param int  $product_id         Producto.
	 * @param bool $known_absent       Baja cuyo DELETE fuente ya ocurrió.
	 * @param bool $forzar_publicacion Reescribir y publicar aunque nada cambie.
	 * @return string indexed|absent|pending|failed.
	 */
	public function process_live_product( int $product_id, bool $known_absent = false, bool $forzar_publicacion = false ): string {
		if ( $product_id <= 0 || ! Install::runtime_schema_ready() ) {
			if ( 0 < $product_id ) {
				Install::request_rebuild( 'runtime_schema_unready' );
			}
			return self::RESULT_FAILED;
		}

		$claim = $this->work->claim_product(
			WorkRepository::SCOPE_LIVE,
			'',
			$product_id,
			self::LEASE_SECONDS
		);
		if ( null === $claim ) {
			return self::RESULT_PENDING;
		}

		// El camino síncrono es un lote de UNO: mismas TX1, misma publicación y
		// mismo ACK que el pump, sin una segunda implementación que mantener. Es
		// además el único consumidor del orden de `close_live_batch()`.
		$staged = $this->stage_live_claim( $claim, $known_absent, $forzar_publicacion );

		return is_array( $staged )
			? ( $this->close_live_batch( array( $staged ) )[0] ?? self::RESULT_FAILED )
			: $staged;
	}

	/**
	 * Procesa un claim baseline/repair sin mantener el fence durante el build.
	 *
	 * @param array<string, mixed> $claim Claim shadow vigente.
	 * @return string indexed|absent|failed.
	 */
	public function process_shadow_claim( array $claim ): string {
		global $wpdb;
		if ( ! Install::runtime_schema_ready() ) {
			Install::request_rebuild( 'runtime_schema_unready' );
			$this->retry_claim( $claim, new \RuntimeException( 'Search runtime schema is not ready.' ) );
			return self::RESULT_FAILED;
		}

		// La gracia de un follow-up bloquea el próximo run, no los claims que ya
		// pertenecen a un run durable; fase, sello y lease se revalidan en su TX.

		try {
			$price_capture = EditorialDiscountPublication::capture_price_authority();
			if ( ! is_array( $price_capture ) ) {
				throw new \RuntimeException( 'Could not adopt the shadow price authority.' );
			}
			$source  = $this->build_source_state( (int) ( $claim['product_id'] ?? 0 ) );
			$outcome = (string) $source['outcome'];
		} catch ( \Throwable $throwable ) {
			$this->retry_claim( $claim, $throwable );
			return self::RESULT_FAILED;
		}

		$transaction_open = false;
		try {
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
				throw new \RuntimeException( 'Could not start shadow index transaction.' );
			}
			$transaction_open = true;

			if ( ! RebuildJob::validate_shadow_claim_phase_for_update( $claim )
				|| ! $this->work->validate_claim_for_update( $claim ) ) {
				throw new \RuntimeException( 'Shadow phase or lease is stale.' );
			}

			$written = WorkRepository::OUTCOME_INDEXED === $outcome
				? $this->persist_rows( (int) $claim['product_id'], (array) $source['built'], true )
				: $this->delete_rows( (int) $claim['product_id'], true );

			if ( ! $written || ! $this->work->ack_claim( $claim, $outcome ) ) {
				throw new \RuntimeException( 'Could not persist or acknowledge shadow work.' );
			}
			if ( ! EditorialDiscountPublication::price_authority_matches( $price_capture ) ) {
				throw new \RuntimeException( 'Shadow price authority changed before commit.' );
			}

			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new \RuntimeException( 'Could not commit shadow index transaction.' );
			}
			$transaction_open = false;

			return $outcome;
		} catch ( \Throwable $throwable ) {
			if ( $transaction_open ) {
				$wpdb->query( 'ROLLBACK' );
			}
			$this->retry_claim( $claim, $throwable );
			return self::RESULT_FAILED;
		}
	}

	/**
	 * TX1 de un claim live ya leased: deja las filas comprometidas y el claim
	 * LEASED, sin publicar nada.
	 *
	 * Devuelve el claim staged para que `close_live_batch()` publique UNA vez por
	 * lote y sólo entonces ACKee; un fallo devuelve el resultado transitorio con
	 * el claim ya devuelto a la cola.
	 *
	 * @param array<string, mixed> $claim              Claim live.
	 * @param bool                 $known_absent       Baja cuyo DELETE fuente ya ocurrió.
	 * @param bool                 $forzar_publicacion Reescribir y publicar aunque nada cambie.
	 * @return array{claim: array<string, mixed>, outcome: string, publica: bool}|string
	 */
	private function stage_live_claim( array $claim, bool $known_absent = false, bool $forzar_publicacion = false ) {
		global $wpdb;

		if ( ! $this->fence->acquire() ) {
			$this->work->retry_claim( $claim, 'write_fence_unavailable', 1 );
			return self::RESULT_PENDING;
		}

		$transaction_open = false;
		try {
			$price_capture = EditorialDiscountPublication::capture_price_authority();
			if ( ! is_array( $price_capture ) ) {
				throw new \RuntimeException( 'Could not adopt the live price authority.' );
			}
			$source     = $known_absent
				? array(
					'outcome' => WorkRepository::OUTCOME_ABSENT,
					'built'   => null,
				)
				: $this->build_source_state( (int) $claim['product_id'] );
			$outcome    = (string) $source['outcome'];
			$product_id = (int) $claim['product_id'];
			$built      = WorkRepository::OUTCOME_INDEXED === $outcome ? (array) $source['built'] : null;

			if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
				throw new \RuntimeException( 'Could not start live index transaction.' );
			}
			$transaction_open = true;
			$run_id           = RebuildJob::active_pre_swap_run_id();
			if ( null === $run_id ) {
				throw new \RuntimeException( 'Live rebuild state or topology is unverifiable.' );
			}

			if ( ! $this->work->validate_claim_for_update( $claim ) ) {
				throw new \RuntimeException( 'Live lease is stale.' );
			}

			$sin_cambio = $this->live_claim_is_a_noop( $claim, $product_id, $built, $forzar_publicacion );
			$written    = $sin_cambio
				? $this->refresh_indexed_at( $product_id, $built )
				: ( null !== $built
					? $this->persist_rows( $product_id, $built, false )
					: $this->delete_rows( $product_id, false ) );
			if ( ! $written ) {
				throw new \RuntimeException( 'Could not persist live index rows.' );
			}

			if ( '' !== $run_id
				&& ! $this->work->mark_repair_dirty( $run_id, $product_id ) ) {
				throw new \RuntimeException( 'Could not persist repair debt.' );
			}

			$this->fence->assert_owned();
			if ( ! EditorialDiscountPublication::price_authority_matches( $price_capture ) ) {
				throw new \RuntimeException( 'Live price authority changed before commit.' );
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new \RuntimeException( 'Could not commit live index rows and repair debt.' );
			}
			$transaction_open = false;

			// El claim permanece LEASED después de TX1: ésa es la deuda durable que
			// permite reintentar si el proceso muere entre filas y publicación. Los
			// side effects de option/object-cache/LiteSpeed ocurren sólo post-COMMIT.
			if ( ! $this->fence->release() ) {
				throw new \RuntimeException( 'Could not release write fence before publication.' );
			}

			return array(
				'claim'   => $claim,
				'outcome' => $outcome,
				'publica' => ! $sin_cambio,
			);
		} catch ( \Throwable $throwable ) {
			if ( $transaction_open ) {
				$wpdb->query( 'ROLLBACK' );
			}
			$this->retry_claim( $claim, $throwable );
			return self::RESULT_FAILED;
		} finally {
			$this->fence->release();
		}
	}

	/**
	 * Publica UNA generación para todo el lote y ACKea cada claim.
	 *
	 * Orden invariante: TX1 de todos → publicación → ACKs. Ningún claim se ACKea
	 * antes del recibo físico de la generación que publica SUS filas, y un claim
	 * cuya publicación falló vuelve a la cola con sus filas ya comprometidas
	 * (deuda durable: el reintento las volverá a publicar, ver
	 * `live_claim_is_a_noop()`).
	 *
	 * Los claims que no cambiaron nada no esperan publicación: no hay ningún
	 * hecho físico nuevo que acreditar, porque el índice ya servía exactamente
	 * esas filas.
	 *
	 * El retorno conserva el orden del lote, pero hoy sólo lo consume el lote de
	 * UNO de `process_live_product()`, que necesita devolver su resultado al
	 * llamador síncrono (CLI por `index_product_outcome()`, bajas). El pump lo ignora a
	 * propósito: su contrato es la tabla de trabajo, no un valor de retorno.
	 *
	 * @param array<int, array{claim: array<string, mixed>, outcome: string, publica: bool}> $lote Claims staged.
	 * @return array<int, string> Resultado por claim, en el mismo orden.
	 */
	private function close_live_batch( array $lote ): array {
		if ( array() === $lote ) {
			return array();
		}

		$publicado = true;
		foreach ( $lote as $staged ) {
			if ( true === $staged['publica'] ) {
				$publication = Install::request_generation_publication( 'live_index_commit' );
				$publicado   = false !== $publication
					&& Install::consume_generation_publications()
					&& Install::generation_publication_satisfied( $publication );
				break;
			}
		}

		$resultados = array();
		$ackeado    = false;
		foreach ( $lote as $staged ) {
			if ( true === $staged['publica'] && ! $publicado ) {
				$this->retry_claim(
					$staged['claim'],
					new \RuntimeException( 'Could not publish committed live index generation.' )
				);
				$resultados[] = self::RESULT_FAILED;
				continue;
			}

			$resultado    = $this->ack_live_claim( $staged['claim'], (string) $staged['outcome'] );
			$resultados[] = $resultado;
			$ackeado      = $ackeado || self::RESULT_FAILED !== $resultado;
		}

		if ( $ackeado ) {
			// El commit pudo mover el COUNT de docs y el receipt editorial quedó
			// con la cobertura del último swap: sin esto el gate público queda
			// cerrado hasta el próximo reconcile (≤60 s de 503 por cada
			// alta/baja). Antes corría por claim —1.630 veces en un cambio
			// editorial—; una vez por lote conserva el mismo instante (después
			// del ACK) al 4 % del coste. El fence ya se liberó, así que el
			// reconcile puede tomarlo; su throttle no aplica aquí.
			EditorialDiscountPublication::reconcile_now();
		}

		return $resultados;
	}

	/**
	 * TX2: ACKea un claim ya publicado y reaviva su revisión siguiente.
	 *
	 * Una revisión n+1 conserva status=pending y su available_at (incluida una
	 * barrera pre-write), por eso el wake se decide releyendo la fila.
	 *
	 * @param array<string, mixed> $claim   Claim staged y publicado.
	 * @param string               $outcome indexed|absent.
	 * @return string indexed|absent|failed.
	 */
	private function ack_live_claim( array $claim, string $outcome ): string {
		global $wpdb;

		$transaction_open = false;
		try {
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
				throw new \RuntimeException( 'Could not start live acknowledgement transaction.' );
			}
			$transaction_open = true;
			if ( ! $this->work->validate_claim_for_update( $claim )
				|| ! $this->work->ack_claim( $claim, $outcome ) ) {
				throw new \RuntimeException( 'Live publication acknowledgement is stale or failed.' );
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new \RuntimeException( 'Could not commit live publication acknowledgement.' );
			}
			$transaction_open = false;

			$remaining = $this->work->find( WorkRepository::SCOPE_LIVE, '', (int) $claim['product_id'] );
			if ( WorkRepository::STATUS_PENDING === (string) ( $remaining['status'] ?? '' ) ) {
				$this->wake_live_work();
			}

			return $outcome;
		} catch ( \Throwable $throwable ) {
			if ( $transaction_open ) {
				$wpdb->query( 'ROLLBACK' );
			}
			$this->retry_claim( $claim, $throwable );
			return self::RESULT_FAILED;
		}
	}

	/**
	 * ¿Este claim reescribiría EXACTAMENTE lo que la tabla viva ya tiene?
	 *
	 * El caso típico de un cambio editorial: el propio AuditLog lo sella
	 * («1.632 elegibles con precio y precio_html idénticos a los ya indexados,
	 * cero rebuild»). Reescribir esas filas y publicar una generación por cada
	 * una era trabajo que no cambiaba un solo byte servido y sí cerraba el
	 * suggest mientras drenaba.
	 *
	 * EL CANDADO DE DURABILIDAD ES `attempts`. Un proceso que muere entre TX1 y
	 * la publicación deja filas COMPROMETIDAS y NO PUBLICADAS: si el reintento
	 * las viera «iguales» y ACKeara sin publicar, esa escritura no purgaría
	 * nunca la caché REST ni movería la generación, y el cliente seguiría viendo
	 * lo viejo sin que nada quedara pendiente. `attempts` es el único contador
	 * durable que distingue los dos mundos: `claim_one_for_update()` lo
	 * incrementa en cada arriendo y SÓLO `ack_claim()` lo devuelve a 0 (ni
	 * `retry_claim()`, ni `retry_with_backoff()`, ni `block_claim()`, ni
	 * `recover_expired()`, ni `mark_live_dirty_many()` lo tocan). Por eso el
	 * atajo exige `attempts === 1`: primer arriendo desde el último ACK, o sea
	 * ninguna escritura anterior pudo quedarse sin publicar. Cualquier otro
	 * valor publica, aunque las filas coincidan.
	 *
	 * LA OTRA PUERTA ES EL OPERADOR. `wp akb-search reindex <id>` existe para
	 * reparar un índice raro, así que pasa `$forzar_publicacion = true` y el
	 * atajo se apaga: reescribe y publica aunque todo coincida. Es el único
	 * llamador humano; los hooks nunca fuerzan.
	 *
	 * @param array<string, mixed>      $claim              Claim live vigente.
	 * @param int                       $product_id         Producto.
	 * @param array<string, mixed>|null $built              Documento construido, o null para una baja.
	 * @param bool                      $forzar_publicacion Apaga el atajo (palanca de reparación).
	 * @return bool
	 */
	private function live_claim_is_a_noop( array $claim, int $product_id, ?array $built, bool $forzar_publicacion = false ): bool {
		return ! $forzar_publicacion
			&& 1 === (int) ( $claim['attempts'] ?? 0 )
			&& $this->live_rows_match( $product_id, $built );
	}

	/**
	 * Compara contra las filas VIVAS lo que este claim escribiría.
	 *
	 * Se lee dentro de la TX del caller y con `FOR UPDATE`, o sea con los mismos
	 * bloqueos que tomaría el DELETE al que sustituye: lo comparado es lo que
	 * habría al escribir, no una foto anterior. Cualquier duda —lectura fallida,
	 * forma inesperada, un byte distinto— devuelve false, que es el camino de
	 * siempre (escribir y publicar).
	 *
	 * `indexed_at` queda FUERA de la comparación a propósito: es el sello de
	 * cuándo se indexó, no contenido servido, y el camino sin cambios lo refresca
	 * igual (`refresh_indexed_at()`).
	 *
	 * @param int                       $product_id Producto.
	 * @param array<string, mixed>|null $built      Documento construido, o null para una baja.
	 * @return bool True sólo si la tabla viva ya contiene exactamente esto.
	 */
	private function live_rows_match( int $product_id, ?array $built ): bool {
		$docs   = $this->live_rows( Install::docs_table(), 'post_id, title, title_norm, serie_norm, autores_norm, sku, sku_norm, tomo, stock_rank, menu_order, price_effective, visible, url, thumb_url, price_html', $product_id );
		$tokens = $this->live_rows( Install::tokens_table(), 'token, field', $product_id );
		$facets = $this->live_rows( Install::facets_table(), 'facet, value, label, value_num, term_id, parent_id', $product_id );
		if ( null === $docs || null === $tokens || null === $facets ) {
			return false;
		}

		if ( null === $built ) {
			// Una baja sólo es un no-op si el producto YA está ausente de las tres
			// tablas: si quedara una sola fila, el DELETE la retira y eso sí cambia
			// lo servido (y el COUNT de docs que el gate público compara).
			return array() === $docs && array() === $tokens && array() === $facets;
		}

		if ( 1 !== count( $docs ) ) {
			return false;
		}
		// Asimetría deliberada en lo que sigue: el lado CONSTRUIDO se lee directo
		// porque `valid_built_document()` ya exigió sus claves exactas y sus
		// tipos antes de abrir la transacción; el lado VIVO se lee con `??`
		// porque es una proyección SQL y una columna ausente tiene que salir por
		// «no coinciden», nunca por un aviso de PHP.
		$doc = (array) $built['doc'];
		$columnas = array( 'title', 'title_norm', 'serie_norm', 'autores_norm', 'sku', 'sku_norm', 'url', 'thumb_url', 'price_html' );
		foreach ( $columnas as $columna ) {
			if ( ! is_string( $docs[0][ $columna ] ?? null ) || (string) $doc[ $columna ] !== $docs[0][ $columna ] ) {
				return false;
			}
		}
		if ( self::entero_o_nulo( $docs[0]['tomo'] ?? null ) !== self::entero_o_nulo( $doc['tomo'] )
			|| self::entero_o_nulo( $docs[0]['stock_rank'] ?? null ) !== self::entero_o_nulo( $doc['stock_rank'] )
			|| self::entero_o_nulo( $docs[0]['menu_order'] ?? null ) !== self::entero_o_nulo( $doc['menu_order'] )
			|| self::entero_o_nulo( $docs[0]['visible'] ?? null ) !== self::entero_o_nulo( $doc['visible'] )
			|| self::decimal_o_nulo( $docs[0]['price_effective'] ?? null ) !== self::decimal_o_nulo( $doc['price_effective'] )
			|| self::entero_o_nulo( $docs[0]['post_id'] ?? null ) !== (string) $product_id ) {
			return false;
		}

		$tokens_esperados = array();
		foreach ( (array) $built['tokens'] as $fila ) {
			$tokens_esperados[] = self::firma( array( (string) $fila['token'], self::entero_o_nulo( $fila['field'] ) ) );
		}
		$tokens_vivos = array();
		foreach ( $tokens as $fila ) {
			$tokens_vivos[] = self::firma( array( (string) ( $fila['token'] ?? '' ), self::entero_o_nulo( $fila['field'] ?? null ) ) );
		}

		$facets_esperadas = array();
		foreach ( (array) $built['facets'] as $fila ) {
			$facets_esperadas[] = self::firma(
				array(
					(string) $fila['facet'],
					(string) $fila['value'],
					(string) $fila['label'],
					self::decimal_o_nulo( $fila['value_num'] ),
					self::entero_o_nulo( $fila['term_id'] ),
					self::entero_o_nulo( $fila['parent_id'] ),
				)
			);
		}
		$facets_vivas = array();
		foreach ( $facets as $fila ) {
			$facets_vivas[] = self::firma(
				array(
					(string) ( $fila['facet'] ?? '' ),
					(string) ( $fila['value'] ?? '' ),
					(string) ( $fila['label'] ?? '' ),
					self::decimal_o_nulo( $fila['value_num'] ?? null ),
					self::entero_o_nulo( $fila['term_id'] ?? null ),
					self::entero_o_nulo( $fila['parent_id'] ?? null ),
				)
			);
		}

		return self::mismo_conjunto( $tokens_esperados, $tokens_vivos )
			&& self::mismo_conjunto( $facets_esperadas, $facets_vivas );
	}

	/**
	 * Filas vivas de un producto, o null si la lectura no es autoritativa.
	 *
	 * @param string $table      Tabla viva del índice.
	 * @param string $columns    Lista de columnas, literal de este mismo fichero.
	 * @param int    $product_id Producto.
	 * @return array<int, array<string, string|null>>|null
	 */
	private function live_rows( string $table, string $columns, int $product_id ): ?array {
		global $wpdb;

		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table} sale de Install::docs_table()/tokens_table()/facets_table() y {$columns} es uno de los tres literales de columnas escritos en live_rows_match(); el único valor, $product_id, viaja por %d en este mismo prepare().
		$sql = $wpdb->prepare( "SELECT {$columns} FROM {$table} WHERE post_id = %d FOR UPDATE", $product_id );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql ES el resultado de $wpdb->prepare() de la línea anterior; se guarda en una variable para comprobar is_string() antes de ejecutar.
		$rows = is_string( $sql ) ? $wpdb->get_results( $sql, ARRAY_A ) : null;
		if ( ! is_array( $rows ) || '' !== trim( (string) ( $wpdb->last_error ?? '' ) ) ) {
			return null;
		}

		return $rows;
	}

	/**
	 * Refresca el sello temporal de un documento que no cambió de contenido.
	 *
	 * No es cosmético: `Reconciler` detecta documentos rancios con
	 * `post_modified_gmt > indexed_at`, así que un doc que no se re-escribe pero
	 * cuyo post sí se guardó quedaría marcado como rancio para siempre y el
	 * reconciliador lo re-encolaría en cada corrida.
	 *
	 * LO QUE CUESTA, para que no se lea como gratis: mover el sello mueve
	 * `MAX(indexed_at)`, que es parte del sello de la caché de conteos de facetas
	 * (`FacetService::counts_cache_stamp()`), así que esa caché se invalida una
	 * vez por lote — del orden de 65 veces en el drenaje completo de un catálogo
	 * de 1.630. Es exactamente la misma invalidación que provocaría la
	 * reescritura a la que sustituye, y a cambio el conteo nunca queda viejo. Lo
	 * que NO cuesta es una generación nueva: el sello no es contenido servido.
	 *
	 * @param int                       $product_id Producto.
	 * @param array<string, mixed>|null $built      Documento construido, o null en una baja.
	 * @return bool
	 */
	private function refresh_indexed_at( int $product_id, ?array $built ): bool {
		global $wpdb;

		if ( null === $built ) {
			return true; // Una baja sin filas no tiene sello que refrescar.
		}

		$docs = Install::docs_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$docs} sale de Install::docs_table(); el sello y el id del producto viajan por %s/%d en este mismo prepare().
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$docs} SET indexed_at = %s WHERE post_id = %d", (string) $built['doc']['indexed_at'], $product_id ) );

		return false !== $updated;
	}

	/**
	 * Canoniza un entero de la DB (siempre string) o del builder (siempre int).
	 *
	 * @param mixed $value Valor.
	 * @return string|null null si es NULL o no es un entero reconocible.
	 */
	private static function entero_o_nulo( $value ): ?string {
		if ( null === $value ) {
			return null;
		}
		if ( is_int( $value ) ) {
			return (string) $value;
		}

		return is_string( $value ) && 1 === preg_match( '/^-?\d+$/D', trim( $value ) )
			? (string) (int) trim( $value )
			: null;
	}

	/**
	 * Canoniza un DECIMAL(12,2) venga como string de MySQL o como número del
	 * builder. Dos valores distintos de la columna difieren al menos en 0,01, así
	 * que la ida y vuelta por float no puede confundirlos.
	 *
	 * @param mixed $value Valor.
	 * @return string|null null si es NULL o no es numérico.
	 */
	private static function decimal_o_nulo( $value ): ?string {
		if ( null === $value ) {
			return null;
		}
		if ( ! is_int( $value ) && ! is_float( $value ) && ! ( is_string( $value ) && is_numeric( trim( $value ) ) ) ) {
			return null;
		}
		$numero = (float) $value;

		return is_finite( $numero ) ? sprintf( '%.2F', $numero ) : null;
	}

	/**
	 * Firma byte-exacta de una fila; "\x00" marca el NULL real para que no se
	 * confunda con la cadena vacía (misma convención que `bulk_insert()`).
	 *
	 * @param array<int, string|null> $values Valores ya canonizados.
	 * @return string
	 */
	private static function firma( array $values ): string {
		return implode(
			"\x1f",
			array_map( static fn( $value ): string => null === $value ? "\x00" : (string) $value, $values )
		);
	}

	/**
	 * Compara dos multiconjuntos de filas sin depender del orden. Las filas
	 * exactamente repetidas se colapsan en los dos lados, igual que hace
	 * `bulk_insert()` antes de escribir (la PK de la tabla ya impide duplicados
	 * vivos).
	 *
	 * @param array<int, string> $esperadas Firmas de lo que se escribiría.
	 * @param array<int, string> $vivas     Firmas de lo que ya está.
	 * @return bool
	 */
	private static function mismo_conjunto( array $esperadas, array $vivas ): bool {
		$esperadas = array_values( array_unique( $esperadas ) );
		$vivas     = array_values( array_unique( $vivas ) );
		sort( $esperadas, SORT_STRING );
		sort( $vivas, SORT_STRING );

		return $esperadas === $vivas;
	}

	/**
	 * Lee y construye el estado fuente; ausencia y fallo nunca se confunden.
	 *
	 * @param int $product_id Producto.
	 * @return array{outcome: string, built: array<string, mixed>|null}
	 */
	private function build_source_state( int $product_id ): array {
		global $wpdb;

		if ( $product_id <= 0 ) {
			throw new \InvalidArgumentException( 'Invalid product id.' );
		}

		$eligible = $this->source_authority->published_product_ids( array( $product_id ) );
		if ( ! $this->source_authority->is_available() ) {
			throw new \RuntimeException( $this->source_authority->last_error() ?: 'Product sources are not authoritative.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- no es salida: el mensaje viaja como dato al last_error de la fila de trabajo (retry_with_backoff) y lo escapa quien lo pinta; escaparlo aquí ensuciaría el log con entidades.
		}
		if ( array( $product_id ) !== $eligible ) {
			return array(
				'outcome' => WorkRepository::OUTCOME_ABSENT,
				'built'   => null,
			);
		}

		$wpdb->last_error = '';
		try {
			$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
		} catch ( \Throwable $throwable ) {
			throw new \RuntimeException( 'Could not load eligible product.', 0, $throwable ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- no es salida: es la excepción previa encadenada como tercer argumento de RuntimeException.
		}
		if ( '' !== trim( (string) $wpdb->last_error ) ) {
			throw new \RuntimeException( 'Could not load eligible product: ' . (string) $wpdb->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- no es salida: el mensaje viaja como dato al last_error de la fila de trabajo (retry_with_backoff) y lo escapa quien lo pinta; escaparlo aquí ensuciaría el log con entidades.
		}
		if ( ! $product instanceof \WC_Product ) {
			throw new \RuntimeException( 'Eligible product could not be loaded.' );
		}
		$wpdb->last_error = '';
		try {
			$observed_id = $product->get_id();
		} catch ( \Throwable $throwable ) {
			throw new \RuntimeException( 'Could not read eligible product identity.', 0, $throwable ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- no es salida: es la excepción previa encadenada como tercer argumento de RuntimeException.
		}
		if ( '' !== trim( (string) $wpdb->last_error ) ) {
			throw new \RuntimeException( 'Could not read eligible product identity: ' . (string) $wpdb->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- no es salida: el mensaje viaja como dato al last_error de la fila de trabajo (retry_with_backoff) y lo escapa quien lo pinta; escaparlo aquí ensuciaría el log con entidades.
		}
		if ( ! is_int( $observed_id ) || $observed_id !== $product_id ) {
			throw new \RuntimeException( 'Eligible product identity disagrees with the authoritative source.' );
		}
		$wpdb->last_error = '';
		try {
			$product_status = $product->get_status();
		} catch ( \Throwable $throwable ) {
			throw new \RuntimeException( 'Could not read eligible product status.', 0, $throwable ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- no es salida: es la excepción previa encadenada como tercer argumento de RuntimeException.
		}
		if ( '' !== trim( (string) $wpdb->last_error ) ) {
			throw new \RuntimeException( 'Could not read eligible product status: ' . (string) $wpdb->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- no es salida: el mensaje viaja como dato al last_error de la fila de trabajo (retry_with_backoff) y lo escapa quien lo pinta; escaparlo aquí ensuciaría el log con entidades.
		}
		if ( ! is_string( $product_status ) || 'publish' !== $product_status ) {
			// La autoridad cruda acaba de observar un post publicado. Un objeto WC
			// que todavía expone otro estado es una segunda foto incompatible (cache
			// A o carrera), no evidencia de ausencia. Conservar la deuda para retry;
			// borrar y ACKear aquí haría autoritativo precisamente el objeto stale.
			throw new \RuntimeException( 'Eligible product status disagrees with the authoritative source.' );
		}

		$wpdb->last_error = '';
		$built            = $this->builder->build( $product );
		if ( '' !== trim( (string) $wpdb->last_error ) ) {
			throw new \RuntimeException( 'Could not build product source document: ' . (string) $wpdb->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- no es salida: el mensaje viaja como dato al last_error de la fila de trabajo (retry_with_backoff) y lo escapa quien lo pinta; escaparlo aquí ensuciaría el log con entidades.
		}
		if ( ! $this->valid_built_document( $product_id, $built ) ) {
			throw new \RuntimeException( 'DocumentBuilder returned an invalid document.' );
		}

		return array(
			'outcome' => WorkRepository::OUTCOME_INDEXED,
			'built'   => $built,
		);
	}

	/**
	 * Verifica forma, tipos y límites físicos antes de abrir una transacción.
	 *
	 * @param int   $product_id Producto esperado.
	 * @param mixed $built      Resultado del builder.
	 * @return bool
	 */
	private function valid_built_document( int $product_id, $built ): bool {
		if ( ! is_array( $built )
			|| ! self::has_exact_keys( $built, array( 'doc', 'tokens', 'facets' ) )
			|| ! is_array( $built['doc'] )
			|| ! is_array( $built['tokens'] )
			|| ! is_array( $built['facets'] ) ) {
			return false;
		}

		$doc_fields = array(
			'post_id', 'title', 'title_norm', 'serie_norm', 'autores_norm', 'sku',
			'sku_norm', 'tomo', 'stock_rank', 'menu_order', 'price_effective',
			'visible', 'url', 'thumb_url', 'price_html', 'indexed_at',
		);
		$doc = $built['doc'];
		if ( ! self::has_exact_keys( $doc, $doc_fields )
			|| ! is_int( $doc['post_id'] )
			|| $product_id !== $doc['post_id']
			|| 0 >= $doc['post_id'] ) {
			return false;
		}
		foreach ( self::DOC_STRING_BYTE_LIMITS as $field => $byte_limit ) {
			if ( ! self::valid_utf8_string( $doc[ $field ], $byte_limit ) ) {
				return false;
			}
		}
		if ( ( null !== $doc['tomo'] && ( ! is_int( $doc['tomo'] ) || 0 > $doc['tomo'] || 65535 < $doc['tomo'] ) )
			|| ! is_int( $doc['stock_rank'] )
			|| ! in_array( $doc['stock_rank'], array( 0, 1, 2 ), true )
			|| ! is_int( $doc['menu_order'] )
			|| -2147483648 > $doc['menu_order']
			|| 2147483647 < $doc['menu_order']
			|| ! self::valid_decimal_12_2( $doc['price_effective'] )
			|| ! is_int( $doc['visible'] )
			|| ! in_array( $doc['visible'], array( 0, 1 ), true )
			|| ! self::valid_mysql_datetime( $doc['indexed_at'] ) ) {
			return false;
		}

		foreach ( $built['tokens'] as $row ) {
			if ( ! is_array( $row )
				|| ! self::has_exact_keys( $row, array( 'token', 'field' ) )
				|| ! self::valid_utf8_string( $row['token'], Normalizer::MAX_TOKEN_LENGTH, false )
				|| ! is_int( $row['field'] )
				|| ! in_array(
					$row['field'],
					array(
						DocumentBuilder::FIELD_TITLE,
						DocumentBuilder::FIELD_SERIE,
						DocumentBuilder::FIELD_AUTOR,
						DocumentBuilder::FIELD_SKU,
						DocumentBuilder::FIELD_TAXONOMY,
						DocumentBuilder::FIELD_DESCRIPTION,
					),
					true
				) ) {
				return false;
			}
		}

		$facet_fields = array( 'facet', 'post_id', 'value', 'label', 'value_num', 'term_id', 'parent_id' );
		foreach ( $built['facets'] as $row ) {
			if ( ! is_array( $row )
				|| ! self::has_exact_keys( $row, $facet_fields )
				|| ! is_int( $row['post_id'] )
				|| $product_id !== $row['post_id']
				|| ! self::valid_utf8_string( $row['facet'], 32, false )
				|| ! self::valid_utf8_string( $row['value'], 191, false )
				|| ! self::valid_utf8_string( $row['label'], 191 )
				|| ( null !== $row['value_num'] && ! self::valid_decimal_12_2( $row['value_num'] ) )
				|| ! self::valid_nullable_unsigned_id( $row['term_id'], false )
				|| ! self::valid_nullable_unsigned_id( $row['parent_id'], true )
				|| ( null === $row['term_id'] ) !== ( null === $row['parent_id'] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Exige exactamente las claves declaradas, sin aceptar datos huérfanos.
	 *
	 * @param array<mixed>       $row      Fila.
	 * @param array<int, string> $expected Claves esperadas.
	 * @return bool
	 */
	private static function has_exact_keys( array $row, array $expected ): bool {
		return count( $row ) === count( $expected )
			&& array() === array_diff( $expected, array_keys( $row ) );
	}

	/**
	 * Valida UTF-8 y el límite byte conservador usado por el builder.
	 *
	 * @param mixed $value       Valor.
	 * @param int   $byte_limit  Límite físico conservador.
	 * @param bool  $allow_empty Permitir cadena vacía.
	 * @return bool
	 */
	private static function valid_utf8_string( $value, int $byte_limit, bool $allow_empty = true ): bool {
		return is_string( $value )
			&& ( $allow_empty || '' !== $value )
			&& strlen( $value ) <= $byte_limit
			&& 1 === preg_match( '//u', $value );
	}

	/**
	 * Valida un decimal no negativo sin aceptar redondeo silencioso de MariaDB.
	 *
	 * @param mixed $value Valor.
	 * @return bool
	 */
	private static function valid_decimal_12_2( $value ): bool {
		if ( ! is_int( $value ) && ! is_float( $value ) ) {
			return false;
		}

		$value = (float) $value;
		return is_finite( $value )
			&& 0.0 <= $value
			&& self::DECIMAL_12_2_MAX >= $value
			&& $value === round( $value, 2 );
	}

	/**
	 * Valida IDs BIGINT UNSIGNED representables por PHP.
	 *
	 * @param mixed $value      Valor.
	 * @param bool  $allow_zero Permitir el cero estructural de parent_id.
	 * @return bool
	 */
	private static function valid_nullable_unsigned_id( $value, bool $allow_zero ): bool {
		if ( null === $value ) {
			return true;
		}
		return is_int( $value ) && ( $allow_zero ? 0 <= $value : 0 < $value );
	}

	/**
	 * Valida DATETIME sin depender de coerciones de SQL mode.
	 *
	 * @param mixed $value Valor.
	 * @return bool
	 */
	private static function valid_mysql_datetime( $value ): bool {
		if ( ! is_string( $value )
			|| 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})$/D', $value, $parts ) ) {
			return false;
		}

		$year   = (int) $parts[1];
		$month  = (int) $parts[2];
		$day    = (int) $parts[3];
		$hour   = (int) $parts[4];
		$minute = (int) $parts[5];
		$second = (int) $parts[6];

		return 1000 <= $year && 9999 >= $year
			&& checkdate( $month, $day, $year )
			&& 0 <= $hour && 23 >= $hour
			&& 0 <= $minute && 59 >= $minute
			&& 0 <= $second && 59 >= $second;
	}

	/**
	 * Suelta un claim fallido con backoff, sin convertirlo en ausencia.
	 *
	 * @param array<string, mixed> $claim     Claim.
	 * @param \Throwable          $throwable Fallo observado.
	 * @return void
	 */
	private function retry_claim( array $claim, \Throwable $throwable ): void {
		$this->work->retry_with_backoff( $claim, $throwable->getMessage() );
	}

	/**
	 * Adaptador de acciones legacy: materializa deuda durable para todo el término.
	 *
	 * @param int $term_id Término.
	 * @param int $offset  Offset legacy, ignorado por el protocolo durable.
	 * @return void
	 */
	public function handle_reindex_term( $term_id, $offset = 0 ): void {
		unset( $offset );
		$this->enqueue_term( (int) $term_id );
	}

	// -----------------------------------------------------------------------
	// Listeners.
	// -----------------------------------------------------------------------

	/**
	 * @param int      $post_id Post.
	 * @param \WP_Post $post    Post object.
	 * @param bool     $update  Es update.
	 * @return void
	 */
	public function on_save_post( $post_id, $post = null, $update = true ): void {
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( (int) $post_id ) ) {
			return;
		}
		$this->enqueue_product_deferred( (int) $post_id, 'save_post' );
	}

	/**
	 * WC avisa antes de cualquier escritura de su data store. La barrera cubre
	 * precio/SKU/meta aunque la ruta no dispare save_post; los hooks WC finales
	 * agregan la revisión inmediata una vez persistido todo.
	 *
	 * @param mixed $product    Producto WC.
	 * @param mixed $data_store Data store (sin uso).
	 * @return void
	 */
	public function on_before_product_persisted( $product, $data_store = null ): void {
		unset( $data_store );
		if ( ! is_object( $product ) || ! is_callable( array( $product, 'get_id' ) ) ) {
			return;
		}

		try {
			$product_id   = $product->get_id();
			$is_variation = is_callable( array( $product, 'is_type' ) ) && $product->is_type( 'variation' );
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
			Install::request_rebuild( 'product_save_source_unreadable' );
			return;
		}
		if ( ! is_int( $product_id ) || $product_id < 0 || ! is_bool( $is_variation ) ) {
			Install::request_rebuild( 'product_save_source_unreadable' );
			return;
		}
		if ( $is_variation && ! is_callable( array( $product, 'get_parent_id' ) ) ) {
			Install::request_rebuild( 'product_save_source_unreadable' );
			return;
		}
		if ( $is_variation ) {
			try {
				$new_parent = $product->get_parent_id();
			} catch ( \Throwable $throwable ) {
				unset( $throwable );
				$new_parent = null;
			}
			$old_parent          = 0;
			$old_parent_readable = 0 === $product_id;
			if ( 0 < $product_id ) {
				$identity            = $this->authoritative_post_identity( $product_id );
				$old_parent_readable = null !== $identity
					&& 'product_variation' === $identity['post_type']
					&& 0 < $identity['post_parent'];
				$old_parent = $old_parent_readable ? $identity['post_parent'] : 0;
			}
			if ( ! is_int( $new_parent ) || $new_parent <= 0 ) {
				$old_parent_readable = false;
				$new_parent = 0;
			}
			if ( ! $old_parent_readable ) {
				$old_parent = 0;
			}
			$this->enqueue_products_deferred( array( $old_parent, $new_parent ), 'woocommerce_product_save' );
			if ( ! $old_parent_readable ) {
				Install::request_rebuild( 'variation_old_parent_unreadable' );
			}
			return;
		}
		if ( 0 < $product_id ) {
			$this->enqueue_product_deferred( $product_id, 'woocommerce_product_save' );
		}
	}

	/**
	 * Persiste una barrera pre-write y garantiza un wake posterior.
	 *
	 * @param int    $product_id Producto/documento padre.
	 * @param string $barrier    Causa estable para el wake.
	 * @return bool
	 */
	private function enqueue_product_deferred( int $product_id, string $barrier ): bool {
		return $this->enqueue_products_deferred( array( $product_id ), $barrier );
	}

	/**
	 * Versión batch de la barrera pre-write (p. ej. padre viejo+nuevo).
	 *
	 * @param array<int, int> $product_ids Productos/documentos afectados.
	 * @param string          $barrier     Causa estable.
	 * @return bool
	 */
	private function enqueue_products_deferred( array $product_ids, string $barrier ): bool {
		$product_ids = array_values(
			array_unique(
				array_filter( array_map( 'intval', $product_ids ), static fn( int $id ): bool => 0 < $id ),
				SORT_NUMERIC
			)
		);
		if ( array() === $product_ids ) {
			return false;
		}
		if ( ! $this->work->mark_live_dirty_many( $product_ids, self::TERM_RELATIONSHIP_DELAY ) ) {
			Install::request_rebuild( 'source_barrier_debt_failed' );
			return false;
		}

		if ( ! $this->wake_live_work_after( self::TERM_RELATIONSHIP_DELAY, $barrier ) ) {
			Install::request_rebuild( 'source_barrier_wake_failed' );
		}

		return true;
	}

	/**
	 * WC CRUD terminó de persistir post, meta, términos y caches del producto.
	 *
	 * @param int               $product_id Producto.
	 * @param \WC_Product|null $product    Instancia ya persistida (sin uso).
	 * @return void
	 */
	public function on_product_persisted( $product_id, $product = null ): void {
		unset( $product );
		$this->enqueue_product( (int) $product_id );
	}

	/**
	 * Una variación persistida cambia precio/stock del documento padre.
	 *
	 * @param int               $variation_id Variación.
	 * @param \WC_Product|null $variation    Instancia persistida.
	 * @return void
	 */
	public function on_variation_persisted( $variation_id, $variation = null ): void {
		unset( $variation );
		$variation_id = is_int( $variation_id ) ? $variation_id : 0;
		$identity     = $this->authoritative_post_identity( $variation_id );
		if ( null !== $identity && 'product_variation' === $identity['post_type'] && 0 < $identity['post_parent'] ) {
			$this->enqueue_product( $identity['post_parent'] );
			return;
		}
		Install::request_rebuild( 'variation_parent_unreadable' );
	}

	/**
	 * @param int $product_id Producto.
	 * @return void
	 */
	public function on_stock_status( $product_id ): void {
		$this->enqueue_product( (int) $product_id );
	}

	/**
	 * La variación transiciona; el documento indexado es el PADRE.
	 *
	 * @param int $variation_id Variación.
	 * @return void
	 */
	public function on_variation_stock_status( $variation_id ): void {
		$variation_id = is_int( $variation_id ) ? $variation_id : 0;
		$identity     = $this->authoritative_post_identity( $variation_id );
		if ( null !== $identity && 'product_variation' === $identity['post_type'] && 0 < $identity['post_parent'] ) {
			$this->enqueue_product( $identity['post_parent'] );
			return;
		}
		Install::request_rebuild( 'variation_parent_unreadable' );
	}

	/**
	 * Hook pre-write de relaciones. La deuda queda disponible después de una
	 * barrera corta; el hook post-write `set_object_terms` agrega otra revisión
	 * inmediata. Si el proceso muere entre ambos, sobrevive la primera.
	 *
	 * @param int          $object_id Producto.
	 * @param int|int[]    $tt_ids    Relaciones por agregar/eliminar (sin uso).
	 * @param string       $taxonomy  Taxonomía.
	 * @return void
	 */
	public function on_pre_term_relationship_change( $object_id, $tt_ids, $taxonomy ): void {
		unset( $tt_ids );
		$object_id = (int) $object_id;
		if ( 0 >= $object_id || ! $this->is_relevant_taxonomy( (string) $taxonomy ) ) {
			return;
		}

		$identity = $this->authoritative_post_identity( $object_id );
		if ( null === $identity ) {
			Install::request_rebuild( 'term_relationship_source_unreadable' );
			return;
		}
		if ( 'product' === $identity['post_type'] ) {
			$this->enqueue_product_deferred( $object_id, 'term_relationship' );
			return;
		}
		if ( 'product_variation' === $identity['post_type'] ) {
			if ( 0 < $identity['post_parent'] ) {
				$this->enqueue_product_deferred( $identity['post_parent'], 'term_relationship' );
			} else {
				Install::request_rebuild( 'variation_parent_unreadable' );
			}
		}
	}

	/**
	 * @param int    $object_id Post.
	 * @param array  $terms     Términos (sin uso).
	 * @param array  $tt_ids    TT ids (sin uso).
	 * @param string $taxonomy  Taxonomía.
	 * @return void
	 */
	public function on_set_object_terms( $object_id, $terms, $tt_ids, $taxonomy ): void {
		unset( $terms, $tt_ids );
		if ( ! $this->is_relevant_taxonomy( (string) $taxonomy ) ) {
			return;
		}
		$object_id = (int) $object_id;
		$identity  = $this->authoritative_post_identity( $object_id );
		if ( null === $identity ) {
			Install::request_rebuild( 'term_relationship_source_unreadable' );
			return;
		}
		if ( 'product' === $identity['post_type'] ) {
			$this->enqueue_product( $object_id );
			return;
		}
		if ( 'product_variation' === $identity['post_type'] ) {
			if ( 0 < $identity['post_parent'] ) {
				$this->enqueue_product( $identity['post_parent'] );
			} else {
				Install::request_rebuild( 'variation_parent_unreadable' );
			}
		}
	}

	/**
	 * Renombrar un término deja labels/tokens stale en todos sus productos
	 * (gap detectado por el jurado en la propuesta que no lo cubría).
	 *
	 * @param int    $term_id  Término.
	 * @param int    $tt_id    TT id (sin uso).
	 * @param string $taxonomy Taxonomía.
	 * @return void
	 */
	public function on_edited_term( $term_id, $tt_id, $taxonomy ): void {
		if ( ! $this->is_relevant_taxonomy( (string) $taxonomy ) ) {
			return;
		}
		// Si `edit_terms` ya comprobó que no cambian nombre, slug ni padre —las
		// tres cosas del término que el índice usa—, repartir deuda a todos sus
		// productos es trabajo sin nada que corregir: pulsar «Actualizar» en una
		// editorial sin tocar esos campos marcaba todos sus productos y los
		// reindexaba idénticos. Sin esa decisión previa (hook ausente, edición
		// por otra vía) se reparte como siempre.
		if ( isset( $this->inert_term_edits[ (int) $term_id ] ) ) {
			unset( $this->inert_term_edits[ (int) $term_id ] );
			return;
		}
		$this->enqueue_term( (int) $term_id );
	}

	/**
	 * Persiste la red de seguridad ANTES del UPDATE de Core. `edited_term`
	 * conserva el fan-out rápido post-write, pero no puede cubrir un crash entre
	 * el UPDATE y ese hook.
	 *
	 * @param int    $term_id  Término por editar.
	 * @param string $taxonomy Taxonomía.
	 * @param array  $args     Datos de edición que Core va a escribir.
	 * @return void
	 */
	public function on_pre_edit_term( $term_id, $taxonomy, $args = array() ): void {
		if ( ! $this->is_relevant_taxonomy( (string) $taxonomy ) ) {
			return;
		}

		if ( ! self::term_edit_touches_index( (int) $term_id, (string) $taxonomy, is_array( $args ) ? $args : array() ) ) {
			$this->inert_term_edits[ (int) $term_id ] = true;
			return;
		}

		unset( $this->inert_term_edits[ (int) $term_id ] );
		Install::request_rebuild( 'term_edit' );
	}

	/**
	 * ¿Este `wp_update_term()` cambia algo que el índice use?
	 *
	 * `edit_terms` lo dispara CUALQUIER `wp_update_term()`, y el formulario de
	 * edición de término reenvía siempre nombre y slug aunque no los toques. El
	 * resultado es que abrir una editorial y pulsar «Actualizar» —para cambiar una
	 * term-meta, o sin cambiar nada— reconstruía el índice ENTERO: 1.568 documentos
	 * y el endpoint `suggest` respondiendo 503 durante unos seis minutos. El coste
	 * lo paga el cliente que está buscando en ese momento.
	 *
	 * Del término, el índice sólo usa tres cosas: el NOMBRE (etiqueta de faceta),
	 * el SLUG (URL de la colección) y el PADRE (`facet_index` materializa
	 * ancestros, ver `DocumentBuilder::build_facets`). Nada más. Si ninguno cambia,
	 * el espejo ya es correcto.
	 *
	 * Fail-SAFE: si no se puede leer el término actual, se reconstruye. Un rebuild
	 * de más cuesta seis minutos de degradación; uno de menos deja el buscador
	 * mintiendo hasta que alguien lo note.
	 *
	 * @param int                  $term_id  Término por actualizarse.
	 * @param string               $taxonomy Taxonomía.
	 * @param array<string, mixed> $args     Argumentos saneados del update.
	 * @return bool
	 */
	private static function term_edit_touches_index( int $term_id, string $taxonomy, array $args ): bool {
		if ( 0 >= $term_id || ! function_exists( 'get_term' ) ) {
			return true;
		}

		$actual = get_term( $term_id, $taxonomy );
		if ( ! $actual instanceof \WP_Term ) {
			return true;
		}

		foreach ( array( 'name', 'slug' ) as $campo ) {
			if ( ! array_key_exists( $campo, $args ) ) {
				continue;
			}
			if ( (string) $args[ $campo ] !== (string) $actual->{$campo} ) {
				return true;
			}
		}

		if ( array_key_exists( 'parent', $args ) && (int) $args['parent'] !== (int) $actual->parent ) {
			return true;
		}

		return false;
	}

	/**
	 * Captura los hijos directos ANTES del borrado: wp_delete_term los reparentea
	 * con un UPDATE crudo sobre term_taxonomy y lo avisa por
	 * `edited_term_taxonomies`, hook que no escuchamos — en `delete_term` la
	 * jerarquía vieja ya no existe. Sus subárboles se resuelven juntos después.
	 *
	 * Al eliminar un término, se encolan los productos de toda su jerarquía de
	 * descendientes para actualizar el índice de facetas hereditarias
	 * (`DocumentBuilder::build_facets`), ya que las modificaciones en términos
	 * no alteran el `post_modified_gmt` del producto.
	 *
	 * @param int    $term_id  Término por borrarse.
	 * @param string $taxonomy Taxonomía.
	 * @return void
	 */
	public function on_pre_delete_term( $term_id, $taxonomy ): void {
		global $wpdb;

		if ( ! $this->is_relevant_taxonomy( (string) $taxonomy ) ) {
			return;
		}
		$term_id  = (int) $term_id;
		$taxonomy = (string) $taxonomy;
		if ( ! Install::request_rebuild( 'term_delete' ) ) {
			$this->record_term_failure( $term_id, array(), 'rebuild_guard_failed' );
		}
		if ( 0 >= $term_id ) {
			$this->record_term_failure( $term_id, array(), 'term_source_unreadable' );
			return;
		}
		if ( ! is_taxonomy_hierarchical( (string) $taxonomy ) ) {
			return;
		}

		try {
			$wpdb->last_error = '';
			$children         = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'parent'     => $term_id,
					'hide_empty' => false,
					'fields'     => 'ids',
				)
			);
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
			$children = null;
		}
		$complete        = is_array( $children ) && '' === trim( (string) ( $wpdb->last_error ?? '' ) );
		if ( function_exists( 'is_wp_error' ) && is_wp_error( $children ) ) {
			$complete = false;
		}

		$child_ids = array();
		if ( $complete ) {
			foreach ( $children as $child_id ) {
				$child_id = is_scalar( $child_id ) ? (int) $child_id : 0;
				if ( 0 >= $child_id ) {
					$complete = false;
					continue;
				}
				$child_ids[] = $child_id;
			}
		}
		$child_ids = array_values( array_unique( $child_ids, SORT_NUMERIC ) );
		sort( $child_ids, SORT_NUMERIC );

		$this->pending_term_children[ $term_id ] = array(
			'taxonomy' => $taxonomy,
			'children' => $child_ids,
			'complete' => $complete,
		);
		if ( ! $complete ) {
			Install::request_rebuild( 'term_delete_children_unreadable' );
			$this->record_term_failure( $term_id, array(), 'child_terms_query_failed' );
		}
	}

	/**
	 * delete_term entrega los object_ids ANTES de que la relación desaparezca
	 * — un term job posterior ya no los encontraría.
	 *
	 * @param int    $term_id      Término.
	 * @param int    $tt_id        TT id (sin uso).
	 * @param string $taxonomy     Taxonomía.
	 * @param mixed  $deleted_term Término borrado (sin uso).
	 * @param array  $object_ids   Posts que lo tenían (SOLO relaciones directas).
	 * @return void
	 */
	public function on_delete_term( $term_id, $tt_id, $taxonomy, $deleted_term = null, $object_ids = array() ): void {
		unset( $tt_id, $deleted_term );
		if ( ! $this->is_relevant_taxonomy( (string) $taxonomy ) ) {
			return;
		}

		$term_id  = (int) $term_id;
		$taxonomy = (string) $taxonomy;
		$snapshot = $this->pending_term_children[ $term_id ] ?? null;
		unset( $this->pending_term_children[ $term_id ] );

		$children = array();
		$complete = true;
		if ( is_taxonomy_hierarchical( $taxonomy ) ) {
			$complete = is_array( $snapshot )
				&& $taxonomy === (string) ( $snapshot['taxonomy'] ?? '' )
				&& true === ( $snapshot['complete'] ?? false );
			$children = is_array( $snapshot ) && is_array( $snapshot['children'] ?? null )
				? $snapshot['children']
				: array();
			if ( ! $complete ) {
				Install::request_rebuild( 'term_delete_snapshot_unreadable' );
				$this->record_term_failure( $term_id, array(), 'delete_snapshot_unreadable' );
			}
		}

		$this->enqueue_term_product_sources(
			$term_id,
			$taxonomy,
			$children,
			is_array( $object_ids ) ? $object_ids : array(),
			$complete
		);
	}

	/**
	 * Barrera durable antes de DELETE/trash/untrash de Core. Los hooks post
	 * aplican la revisión inmediata; si el proceso muere entre el write y ellos,
	 * esta revisión diferida rederiva producto o padre de variación.
	 *
	 * @param int                   $post_id Producto/variación.
	 * @param \WP_Post|string|null $post    Snapshot (delete) o status previo.
	 * @return void
	 */
	public function on_before_post_state_change( $post_id, $post = null ): void {
		$post_id  = (int) $post_id;
		$identity = $this->authoritative_post_identity( $post_id, $post instanceof \WP_Post ? $post : null );
		if ( null === $identity ) {
			Install::request_rebuild( 'post_state_source_unreadable' );
			return;
		}
		if ( 'product' === $identity['post_type'] ) {
			$this->enqueue_product_deferred( $post_id, 'post_state_change' );
			return;
		}
		if ( 'product_variation' !== $identity['post_type'] ) {
			return;
		}

		$parent_id = $identity['post_parent'];
		if ( 0 < $parent_id ) {
			$this->enqueue_product_deferred( $parent_id, 'post_state_change' );
		} else {
			Install::request_rebuild( 'variation_parent_unreadable' );
		}
	}

	/**
	 * @param int                  $post_id Post borrado/trasheado.
	 * @param \WP_Post|string|null $post    Snapshot de deleted_post o estado previo de trashed_post.
	 * @return void
	 */
	public function on_deleted_post( $post_id, $post = null ): void {
		$post_id  = (int) $post_id;
		$identity = $this->authoritative_post_identity( $post_id, $post instanceof \WP_Post ? $post : null );
		if ( null === $identity ) {
			Install::request_rebuild( 'post_state_source_unreadable' );
			return;
		}
		if ( 'product_variation' === $identity['post_type'] ) {
			$parent_id = $identity['post_parent'];
			if ( 0 < $parent_id ) {
				$this->enqueue_product( $parent_id );
			} else {
				Install::request_rebuild( 'variation_parent_unreadable' );
			}
			return;
		}
		if ( 'product' !== $identity['post_type'] ) {
			return;
		}
		// La baja conserva deuda durable aun cuando deleted_post ya no pueda leer el post.
		if ( $post instanceof \WP_Post ) {
			$this->delete_product_known_absent( $post_id );
			return;
		}

		// `trashed_post` corre después de persistir status=trash; aquí sí conviene
		// releer para no forzar una ausencia contra una republicación concurrente.
		$this->delete_product( $post_id );
	}

	/**
	 * Restaura deuda después de que Core ya persistió el estado recuperado.
	 * Las variaciones no tienen documento propio: se rederiva el padre.
	 *
	 * @param int    $post_id         Post restaurado.
	 * @param string $previous_status Estado previo (sin uso).
	 * @return void
	 */
	public function on_untrashed_post( $post_id, $previous_status = 'trash' ): void {
		unset( $previous_status );
		$post_id  = (int) $post_id;
		$identity = $this->authoritative_post_identity( $post_id );
		if ( null === $identity ) {
			Install::request_rebuild( 'post_state_source_unreadable' );
			return;
		}
		if ( 'product' === $identity['post_type'] ) {
			$this->enqueue_product( $post_id );
			return;
		}
		if ( 'product_variation' !== $identity['post_type'] ) {
			return;
		}

		$parent_id = $identity['post_parent'];
		if ( 0 < $parent_id ) {
			$this->enqueue_product( $parent_id );
		} else {
			Install::request_rebuild( 'variation_parent_unreadable' );
		}
	}

	/**
	 * Identidad mínima desde la tabla posts, sin aceptar una foto de object cache.
	 *
	 * Los listeners son la única oportunidad de crear deuda durable. Un
	 * `get_post_type()` stale que diga `post` no puede convertir silenciosamente
	 * un producto en evento irrelevante. Los hooks que entregan WP_Post ya traen
	 * la foto pre-delete y no necesitan una lectura posterior imposible.
	 *
	 * @param int           $post_id  Identidad esperada.
	 * @param \WP_Post|null $snapshot Foto confiable provista por Core.
	 * @return array{post_type: string, post_parent: int}|null
	 */
	private function authoritative_post_identity( int $post_id, ?\WP_Post $snapshot = null ): ?array {
		global $wpdb;

		if ( $post_id <= 0 ) {
			return null;
		}
		if ( null !== $snapshot ) {
			$snapshot_id = $snapshot->ID ?? null;
			$post_type   = $snapshot->post_type ?? null;
			$post_parent = $snapshot->post_parent ?? null;
			if ( ! is_int( $snapshot_id ) || $snapshot_id <= 0 || $snapshot_id !== $post_id
				|| ! is_string( $post_type ) || '' === trim( $post_type )
				|| ! is_int( $post_parent ) || $post_parent < 0 ) {
				return null;
			}
			return array(
				'post_type'   => $post_type,
				'post_parent' => $post_parent,
			);
		}

		if ( ! is_object( $wpdb ) || ! isset( $wpdb->posts )
			|| ! is_callable( array( $wpdb, 'prepare' ) ) || ! is_callable( array( $wpdb, 'get_results' ) ) ) {
			return null;
		}
		try {
			$wpdb->last_error = '';
			$sql = $wpdb->prepare(
				"SELECT ID, post_type, post_parent FROM {$wpdb->posts} WHERE ID = %d LIMIT 2",
				$post_id
			);
			if ( '' !== trim( (string) ( $wpdb->last_error ?? '' ) ) || ! is_string( $sql ) || '' === trim( $sql ) ) {
				return null;
			}
			$wpdb->last_error = '';
			$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql viene de $wpdb->prepare() ocho líneas arriba; se guarda en variable justo para comprobar is_string() y que prepare() no dejó last_error.
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
			return null;
		}
		if ( '' !== trim( (string) ( $wpdb->last_error ?? '' ) )
			|| ! is_array( $rows ) || 1 !== count( $rows ) || ! is_array( $rows[0] ) ) {
			return null;
		}
		$row = $rows[0];
		if ( ! isset( $row['ID'], $row['post_type'], $row['post_parent'] )
			|| ! is_scalar( $row['ID'] ) || ! preg_match( '/^[0-9]+$/D', (string) $row['ID'] )
			|| (string) (int) $row['ID'] !== (string) $row['ID'] || (int) $row['ID'] !== $post_id
			|| ! is_string( $row['post_type'] ) || '' === trim( $row['post_type'] )
			|| ! is_scalar( $row['post_parent'] ) || ! preg_match( '/^[0-9]+$/D', (string) $row['post_parent'] )
			|| (string) (int) $row['post_parent'] !== (string) $row['post_parent'] ) {
			return null;
		}
		return array(
			'post_type'   => $row['post_type'],
			'post_parent' => (int) $row['post_parent'],
		);
	}

	/**
	 * @param array $product_ids Productos cuya oferta programada partió/venció.
	 * @return void
	 */
	public function on_scheduled_sales( $product_ids ): void {
		if ( ! is_array( $product_ids ) ) {
			return;
		}
		$this->enqueue_products( array_map( 'intval', $product_ids ) );
	}

	/**
	 * @param int $term_id Término cuyo % editorial cambió.
	 * @return void
	 */
	public function on_discount_pct_saved( $term_id ): void {
		$this->enqueue_term( (int) $term_id );
	}

	/**
	 * Snapshot exacto del fan-out de un porcentaje editorial de término.
	 *
	 * Este método sólo resuelve fuente; no escribe deuda ni despierta workers.
	 * EditorialDiscountPublication lo invoca mientras posee WriteFence y deja
	 * LIVE + REPAIR durables antes de permitir el write de termmeta.
	 *
	 * @param int $term_id Término con `_akb_taxonomy_discount_pct`.
	 * @return array<int, int>|null IDs únicos ordenados; null si la fuente no fue verificable.
	 */
	public function editorial_term_product_ids( int $term_id ): ?array {
		global $wpdb;

		if ( 0 >= $term_id ) {
			return null;
		}

		try {
			$wpdb->last_error = '';
			$term             = get_term( $term_id );
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
			return null;
		}
		if ( '' !== trim( (string) ( $wpdb->last_error ?? '' ) )
			|| ( function_exists( 'is_wp_error' ) && is_wp_error( $term ) )
			|| ! $term instanceof \WP_Term
			|| '' === (string) $term->taxonomy ) {
			return null;
		}

		try {
			$wpdb->last_error = '';
			$ids              = get_posts(
				array(
					'post_type'              => 'product',
					'post_status'            => 'publish',
					'fields'                 => 'ids',
					'posts_per_page'         => -1,
					'orderby'                => 'ID',
					'order'                  => 'ASC',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
					'tax_query'              => array(
						array(
							'taxonomy'         => (string) $term->taxonomy,
							'terms'            => array( $term_id ),
							'include_children' => true,
						),
					),
				)
			);
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
			return null;
		}
		if ( '' !== trim( (string) ( $wpdb->last_error ?? '' ) ) || ! is_array( $ids ) ) {
			return null;
		}

		$normalised = array();
		foreach ( $ids as $id ) {
			if ( ! is_scalar( $id ) || 0 >= (int) $id ) {
				return null;
			}
			$normalised[] = (int) $id;
		}
		$normalised = array_values( array_unique( $normalised, SORT_NUMERIC ) );
		sort( $normalised, SORT_NUMERIC );

		return $normalised;
	}

	/**
	 * Toggle de "ocultar agotados" ⇒ cambia `visible` de todo el catálogo:
	 * solicita reconstrucción completa asíncrona mediante Action Scheduler.
	 *
	 * @param mixed $old_value Valor anterior.
	 * @param mixed $new_value Valor nuevo.
	 * @return void
	 */
	public function on_visibility_option_toggle( $old_value, $new_value ): void {
		if ( $old_value === $new_value ) {
			return;
		}
		Install::request_rebuild( 'visibility_option_changed' );
	}

	// -----------------------------------------------------------------------
	// Persistencia.
	// -----------------------------------------------------------------------

	/**
	 * DELETE+INSERT de las filas de un producto dentro de la TX del caller.
	 *
	 * @param int   $product_id Producto.
	 * @param array $built      Salida de DocumentBuilder::build().
	 * @param bool  $shadow     Escribir en tablas sombra.
	 * @return bool True si todas las escrituras fueron aceptadas por la DB.
	 */
	private function persist_rows( int $product_id, array $built, bool $shadow ): bool {
		global $wpdb;

		$suffix = $shadow ? Install::SHADOW_SUFFIX : '';
		$docs   = Install::docs_table() . $suffix;
		$tokens = Install::tokens_table() . $suffix;
		$facets = Install::facets_table() . $suffix;

		$doc = $built['doc'];

		// El DELETE es parte del contrato de la escritura: si falla, el INSERT
		// chocaría con la PK de docs y lo único correcto es el ROLLBACK.
		$ok = $this->delete_rows( $product_id, $shadow );

		if ( $ok ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- escritura atómica directa en la tabla propia del índice.
			$inserted = $wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$docs} sale de Install::docs_table() más el sufijo de sombra, ambos literales del plugin; los dieciséis valores del documento viajan por sus %d/%s/%f en este mismo prepare().
					"INSERT INTO {$docs}
						(post_id, title, title_norm, serie_norm, autores_norm, sku, sku_norm, tomo, stock_rank, menu_order, price_effective, visible, url, thumb_url, price_html, indexed_at)
					VALUES (%d, %s, %s, %s, %s, %s, %s, %s, %d, %d, %f, %d, %s, %s, %s, %s)",
					$doc['post_id'],
					$doc['title'],
					$doc['title_norm'],
					$doc['serie_norm'],
					$doc['autores_norm'],
					$doc['sku'],
					$doc['sku_norm'],
					$doc['tomo'],
					$doc['stock_rank'],
					$doc['menu_order'],
					$doc['price_effective'],
					$doc['visible'],
					$doc['url'],
					$doc['thumb_url'],
					$doc['price_html'],
					$doc['indexed_at']
				)
			);
			$ok = false !== $inserted;
			// prepare() con %s y valor null serializa 'NULL' literal — tomo null
			// se corrige acá con un UPDATE barato en vez de duplicar el INSERT.
			if ( $ok && null === $doc['tomo'] ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$docs} sale de Install::docs_table() más el sufijo de sombra; el único dato, el id del producto, viaja por %d en este mismo prepare().
				$ok = false !== $wpdb->query( $wpdb->prepare( "UPDATE {$docs} SET tomo = NULL WHERE post_id = %d", $product_id ) );
			}
		}

		if ( $ok && ! empty( $built['tokens'] ) ) {
			$ok = false !== $this->bulk_insert(
				$tokens,
				array( 'token', 'field', 'post_id' ),
				array( '%s', '%d', '%d' ),
				array_map(
					static fn( array $row ): array => array( $row['token'], $row['field'], $product_id ),
					$built['tokens']
				)
			);
		}

		if ( $ok && ! empty( $built['facets'] ) ) {
			$ok = false !== $this->bulk_insert(
				$facets,
				array( 'facet', 'post_id', 'value', 'label', 'value_num', 'term_id', 'parent_id' ),
				array( '%s', '%d', '%s', '%s', '%s', '%s', '%s' ),
				array_map(
					static fn( array $row ): array => array(
						$row['facet'],
						$product_id,
						$row['value'],
						$row['label'],
						null === $row['value_num'] ? null : (string) $row['value_num'],
						null === $row['term_id'] ? null : (string) $row['term_id'],
						null === $row['parent_id'] ? null : (string) $row['parent_id'],
					),
					$built['facets']
				)
			);
		}

		return $ok;
	}

	/**
	 * Devuelve bool: el resultado de estos DELETE se descartaba, y es el único
	 * control de éxito que tiene delete_product() (allí no hay INSERT que
	 * delate el fallo de rebote).
	 *
	 * Se recorren las 3 tablas SIEMPRE (no se corta en el primer fallo): dentro
	 * de la transacción, dejar filas a medio borrar da igual porque el ROLLBACK
	 * las revierte, y así el diagnóstico de $wpdb->last_error no queda sesgado.
	 *
	 * @param int  $product_id Producto.
	 * @param bool $shadow     Tablas sombra.
	 * @return bool True si los 3 DELETE se ejecutaron sin error.
	 */
	private function delete_rows( int $product_id, bool $shadow ): bool {
		global $wpdb;

		$ok     = true;
		$suffix = $shadow ? Install::SHADOW_SUFFIX : '';
		foreach ( Install::swappable_tables() as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table sale de Install::swappable_tables() y $suffix de Install::SHADOW_SUFFIX: son nombres de tabla propios, que no admiten marcador; el único valor, $product_id, sí va por prepare().
			$deleted = $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . $table . $suffix . ' WHERE post_id = %d', $product_id ) );
			$ok      = $ok && false !== $deleted;
		}

		return $ok;
	}

	/**
	 * INSERT multi-VALUES con NULLs reales (prepare de WP no soporta null:
	 * los placeholders de valores null se emiten como NULL literal).
	 *
	 * Utiliza `INSERT IGNORE` para evitar abortos catastróficos no diagnosticados,
	 * pero verifica estrictamente que la cantidad de filas insertadas coincida con
	 * el lote enviado tras dedup en memoria. Si alguna fila es descartada por colisión
	 * o truncamiento inesperado, retorna false forzando un ROLLBACK seguro para
	 * evitar indexaciones incompletas o corruptas.
	 *
	 * Por qué la comparación no da falsos positivos: los llamadores mandan un
	 * solo post_id y persist() ya borró sus filas DENTRO de la transacción, así
	 * que la única colisión posible es entre filas de esta misma llamada. El
	 * duplicado LEGÍTIMO —la misma fila mandada dos veces, que la DB debe
	 * colapsar a una— se colapsa acá antes de contar. Lo que quede sin insertar
	 * es entonces siempre pérdida real: dos filas DISTINTAS que la PK ve iguales
	 * (collation degradada — el modo de fallo M7 que assert_token_collation
	 * intenta prevenir — o valores recortados por una columna corta).
	 *
	 * @param string   $table   Tabla destino.
	 * @param string[] $columns Columnas.
	 * @param string[] $formats Formatos por columna (%s/%d/%f).
	 * @param array[]  $rows    Filas (arrays posicionales).
	 * @return int|false Filas insertadas, o false si la DB descartó alguna.
	 */
	private function bulk_insert( string $table, array $columns, array $formats, array $rows ) {
		global $wpdb;

		$values       = array();
		$placeholders = array();
		$seen         = array();

		foreach ( $rows as $row ) {
			// Firma byte-exacta de la fila; "\x00" marca el NULL real para que no
			// se confunda con la cadena vacía. Dedup defensivo: los dos
			// llamadores ya dedupean en DocumentBuilder, pero sin esto una fila
			// repetida haría fallar la comparación de abajo y tiraría abajo la
			// escritura completa del producto por un duplicado inofensivo.
			$key = implode( "\x1f", array_map( static fn( $value ): string => null === $value ? "\x00" : (string) $value, $row ) );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;

			$row_placeholders = array();
			foreach ( $row as $i => $value ) {
				if ( null === $value ) {
					$row_placeholders[] = 'NULL';
				} else {
					$row_placeholders[] = $formats[ $i ];
					$values[]           = $value;
				}
			}
			$placeholders[] = '(' . implode( ', ', $row_placeholders ) . ')';
		}

		$sql = 'INSERT IGNORE INTO ' . $table . ' (' . implode( ', ', $columns ) . ') VALUES ' . implode( ', ', $placeholders );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders construidos arriba, valores via prepare.
		$inserted = $wpdb->query( empty( $values ) ? $sql : $wpdb->prepare( $sql, $values ) );

		// Menos filas insertadas que enviadas = la DB descartó datos en silencio.
		if ( false !== $inserted && (int) $inserted < count( $placeholders ) ) {
			return false;
		}

		return $inserted;
	}

	/**
	 * @param string $taxonomy Taxonomía del evento.
	 * @return bool
	 */
	private function is_relevant_taxonomy( string $taxonomy ): bool {
		if ( 'product_visibility' === $taxonomy ) {
			return true; // Controla visibilidad en catálogo ('exclude-from-catalog', 'exclude-from-search').
		}
		return in_array( $taxonomy, FacetRegistry::indexed_taxonomies(), true );
	}

	/**
	 * Convierte un evento de término en deuda granular por producto en la tabla de trabajo.
	 *
	 * Solicita reconstrucción completa únicamente si el esquema no está listo,
	 * si la lectura de fuentes falla o si no se logran encolar todos los productos afectados.
	 *
	 * @param int $term_id Término.
	 * @return bool True si todos los IDs descubiertos quedaron persistidos.
	 */
	private function enqueue_term( int $term_id ): bool {
		global $wpdb;

		if ( $term_id <= 0 ) {
			return false;
		}
		if ( ! Install::runtime_schema_ready() ) {
			$this->request_term_rebuild( $term_id );
			return false;
		}

		try {
			$wpdb->last_error = '';
			$term             = get_term( $term_id );
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
			$term = null;
		}
		if ( ( function_exists( 'is_wp_error' ) && is_wp_error( $term ) )
			|| '' !== trim( (string) ( $wpdb->last_error ?? '' ) )
			|| ! $term instanceof \WP_Term
			|| '' === (string) $term->taxonomy ) {
			Install::request_rebuild( 'term_fanout_source_unreadable' );
			$this->record_term_failure( $term_id, array(), 'term_source_unreadable' );
			return false;
		}

		$completa = $this->enqueue_term_product_sources(
			$term_id,
			(string) $term->taxonomy,
			array( $term_id ),
			array(),
			true
		);
		if ( ! $completa ) {
			$this->request_term_rebuild( $term_id );
		}

		return $completa;
	}

	/**
	 * Mecanismo de contingencia ante fanout incompleto de términos: solicita
	 * reconstrucción completa y registra el evento si la solicitud no puede persistirse.
	 *
	 * @param int $term_id Término.
	 * @return void
	 */
	private function request_term_rebuild( int $term_id ): void {
		if ( ! Install::request_rebuild( 'term_fanout' ) ) {
			$this->record_term_failure( $term_id, array(), 'rebuild_guard_failed' );
		}
	}

	/**
	 * Resuelve uno o varios subárboles en una sola query y materializa su deuda.
	 *
	 * @param int               $source_term_id Término que originó el evento.
	 * @param string            $taxonomy       Taxonomía fuente.
	 * @param array<int, int>   $term_ids       Raíces cuyos subárboles incluir.
	 * @param array<int, mixed> $object_ids     Relaciones directas conocidas.
	 * @param bool              $source_complete La captura pre-write fue íntegra.
	 * @return bool True sólo si la fuente fue completa y toda la deuda persistió.
	 */
	private function enqueue_term_product_sources(
		int $source_term_id,
		string $taxonomy,
		array $term_ids,
		array $object_ids,
		bool $source_complete
	): bool {
		global $wpdb;

		if ( ! Install::runtime_schema_ready() ) {
			Install::request_rebuild( 'runtime_schema_unready' );
			return false;
		}

		$term_roots = array();
		foreach ( $term_ids as $term_id ) {
			$term_id = is_scalar( $term_id ) ? (int) $term_id : 0;
			if ( 0 >= $term_id ) {
				$source_complete = false;
				continue;
			}
			$term_roots[] = $term_id;
		}
		$term_roots = array_values( array_unique( $term_roots, SORT_NUMERIC ) );
		sort( $term_roots, SORT_NUMERIC );

		$product_ids           = array();
		$unreadable_object_ids = array();
		foreach ( $object_ids as $object_id ) {
			$object_id = is_scalar( $object_id ) ? (int) $object_id : 0;
			if ( 0 >= $object_id ) {
				$source_complete         = false;
				$unreadable_object_ids[] = $object_id;
				continue;
			}

			$identity = $this->authoritative_post_identity( $object_id );
			if ( null === $identity ) {
				$source_complete         = false;
				$unreadable_object_ids[] = $object_id;
				continue;
			}
			if ( 'product' === $identity['post_type'] ) {
				$product_ids[] = $object_id;
				continue;
			}
			if ( 'product_variation' === $identity['post_type'] ) {
				$parent_id = $identity['post_parent'];
				if ( 0 >= $parent_id ) {
					$source_complete         = false;
					$unreadable_object_ids[] = $object_id;
					continue;
				}
				$product_ids[] = $parent_id;
			}
		}

		if ( array() !== $term_roots ) {
			try {
				$wpdb->last_error = '';
				$ids              = get_posts(
					array(
						'post_type'              => 'product',
						'post_status'            => 'publish',
						'fields'                 => 'ids',
						'posts_per_page'         => -1,
						'orderby'                => 'ID',
						'order'                  => 'ASC',
						'no_found_rows'          => true,
						'update_post_meta_cache' => false,
						'update_post_term_cache' => false,
						'tax_query'              => array(
							array(
								'taxonomy'         => $taxonomy,
								'terms'            => $term_roots,
								'include_children' => true,
							),
						),
					),
				);
			} catch ( \Throwable $throwable ) {
				unset( $throwable );
				$ids = null;
			}

			if ( '' !== trim( (string) ( $wpdb->last_error ?? '' ) ) || ! is_array( $ids ) ) {
				$source_complete = false;
				$this->record_term_failure( $source_term_id, array(), 'source_query_failed' );
			} else {
				foreach ( $ids as $id ) {
					$id = is_scalar( $id ) ? (int) $id : 0;
					if ( 0 >= $id ) {
						$source_complete = false;
						continue;
					}
					$product_ids[] = $id;
				}
			}
		}

		if ( ! $source_complete ) {
			Install::request_rebuild( 'term_fanout_source_unreadable' );
			if ( array() !== $unreadable_object_ids ) {
				$this->record_term_failure( $source_term_id, $unreadable_object_ids, 'object_source_unreadable' );
			}
		}

		$product_ids = array_values( array_unique( $product_ids, SORT_NUMERIC ) );
		sort( $product_ids, SORT_NUMERIC );

		$failed_ids = array();
		$marked_any = false;
		foreach ( array_chunk( $product_ids, self::TERM_BATCH_SIZE ) as $chunk ) {
			if ( ! $this->work->mark_live_dirty_many( $chunk ) ) {
				array_push( $failed_ids, ...$chunk );
				continue;
			}
			$marked_any = true;
		}

		if ( $marked_any ) {
			$this->wake_live_work();
		}
		if ( array() !== $failed_ids ) {
			$this->record_term_failure( $source_term_id, $failed_ids, 'work_persist_failed' );
		}

		return $source_complete && array() === $failed_ids;
	}

	/**
	 * Deja observable una materialización parcial de deuda por término.
	 *
	 * @param int             $term_id Término.
	 * @param array<int, int> $ids     IDs que no pudieron persistirse.
	 * @param string          $reason  Causa estable.
	 * @return void
	 */
	private function record_term_failure( int $term_id, array $ids, string $reason ): void {
		if ( ! class_exists( AuditLog::class ) ) {
			return;
		}

		AuditLog::record(
			'index_term_debt_failed',
			sprintf(
				'term_id=%d reason=%s ids=%s',
				$term_id,
				$reason,
				array() === $ids ? '-' : implode( ',', array_slice( $ids, 0, 25 ) )
			)
		);
	}
}
