<?php
/**
 * FacetService — filtrado y counts sobre el espejo de facetas.
 *
 * Semántica de facetas:
 *   - OR dentro de una misma faceta: `?_categoria=shonen,seinen` = shonen O seinen.
 *   - AND entre distintas facetas: `?_categoria=shonen&_editorial=ivrea` = ambas.
 *   - Counts de la faceta X calculados contra la selección de todas menos X.
 *
 * Implementación SQL:
 *   - El conjunto base opera mediante JOINs indexados sobre el espejo relacional.
 *   - Los rangos de precio y novedad se resuelven dinámicamente en tiempo de consulta.
 *
 * @package Akibara\Search
 */

declare( strict_types = 1 );

namespace Akibara\Search;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.55.0
 */
final class FacetService {

	use Support\FailsClosed;

	/** @var \wpdb */
	private $db;

	/** @var int|null Memo por request del total de docs visibles (cota de search_constraint). */
	private ?int $visible_docs_memo = null;

	/**
	 * @param \wpdb $db WordPress DB.
	 */
	public function __construct( $db ) {
		$this->db = $db;
	}

	/**
	 * post_ids que satisfacen TODAS las facetas activas (∩) y, si hay búsqueda,
	 * la restricción de ids del motor. Un solo SELECT con JOINs.
	 *
	 * @param FilterSet            $filters    Selecciones activas.
	 * @param array<int, int>|null $search_ids Ids del QueryEngine (null = sin búsqueda).
	 * @return array<int, int> post_ids (sin ordenar).
	 */
	public function filter( FilterSet $filters, ?array $search_ids = null ): array {
		if ( ! $this->available ) {
			return array();
		}
		if ( ! $this->filter_set_is_valid( $filters ) ) {
			return array();
		}
		$search_ids = $this->validated_search_ids( $search_ids );
		if ( ! $this->available ) {
			return array();
		}
		if ( null !== $search_ids && empty( $search_ids ) ) {
			return array();
		}

		$docs = Install::docs_table();

		list( $joins, $params ) = $this->facet_joins( $filters );
		list( $search_sql, $search_params ) = $this->search_constraint( $search_ids );
		if ( ! $this->available ) {
			return array();
		}

		// JOIN fail-closed contra wp_posts:
		// Garantiza que solo productos publicados sean incluidos en los filtros,
		// independientemente del desfase eventual de la cola asíncrona de indexación.
		$sql = "SELECT DISTINCT d.post_id FROM {$docs} d "
			. "INNER JOIN {$this->db->posts} p ON p.ID = d.post_id AND p.post_status = 'publish' "
			. implode( ' ', $joins )
			. ' WHERE d.visible = 1' . $search_sql;

		$all_params = array_merge( $params, $search_params );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- joins preparados abajo.
		$prepared = $sql;
		if ( ! empty( $all_params ) ) {
			list( $prepared_ok, $prepared ) = $this->guarded_call(
				fn() => $this->db->prepare( $sql, ...$all_params ),
				'Could not prepare facet filter read.',
				false
			);
			if ( ! $prepared_ok ) {
				return array();
			}
		}
		if ( ! is_string( $prepared ) ) {
			$this->mark_unavailable( 'Could not prepare facet filter read.' );
			return array();
		}

		list( $read_ok, $ids ) = $this->guarded_call(
			fn() => $this->db->get_col( $prepared ),
			'Could not read facet filter.'
		);
		if ( ! $read_ok || ! is_array( $ids ) ) {
			if ( $read_ok ) {
				$this->mark_unavailable( 'Facet filter returned an invalid result shape.' );
			}
			return array();
		}

		$validated = $this->canonical_positive_id_list( $ids );
		if ( null === $validated ) {
			$this->mark_unavailable( 'Facet filter returned invalid product identifiers.' );
			return array();
		}

		return $validated;
	}

	/**
	 * Counts por faceta del registry (except-self).
	 *
	 * @param FilterSet            $filters    Selecciones activas.
	 * @param array<int, int>|null $search_ids Ids del motor (null = sin búsqueda).
	 * @return array<string, array<string, array{value: string, label: string, count: int, selected: bool}>>
	 */
	public function counts( FilterSet $filters, ?array $search_ids = null ): array {
		if ( ! $this->available ) {
			return array();
		}
		if ( ! $this->filter_set_is_valid( $filters ) ) {
			return array();
		}
		$search_ids = $this->validated_search_ids( $search_ids );
		if ( ! $this->available ) {
			return array();
		}
		// Cacheable = sin búsqueda y sin facetas EXPLÍCITAS (landing y archives
		// con contexto implícito), O selección UNIVARIADA (a lo sumo 1 faceta
		// explícita con 1 valor): acelera el speculative hover prefetch sin riesgo
		// de combinatoria descontrolada (censo acotado a 281 valores).
		//
		// El sello del estado se captura una sola vez y se conserva hasta el guardado
		// para evitar carreras temporales (TOCTOU) durante reindexaciones concurrentes.
		$cacheable   = null === $search_ids && ( ! $filters->has_facets() || $this->is_univariate_selection( $filters ) );
		$cache_key   = '';
		$cache_stamp = '';
		if ( $cacheable ) {
			$cache_stamp = $this->counts_cache_stamp();
			if ( ! $this->available ) {
				return array();
			}
			$cache_key = $this->counts_cache_key( $filters );
			$cached    = $this->cached_counts( $cache_key, $cache_stamp );
			if ( null !== $cached ) {
				return $cached;
			}
		}

		$out        = array();
		$unselected = array();

		$definitions = $this->facet_definitions();
		if ( ! $this->available ) {
			return array();
		}
		foreach ( $definitions as $facet => $def ) {
			$is_price = 'precio' === ( $def['type'] ?? '' );
			$selected = $filters->values( $facet );

			if ( $is_price ) {
				// Sin buckets utilizables (option ausente/pre-primer-reindex) la
				// faceta se OCULTA: ni counts sobre la const [gate v3.1 nit 2].
				if ( ! $this->has_price_buckets() ) {
					if ( ! $this->available ) {
						return array();
					}
					continue;
				}
				$out[ $facet ] = $this->price_counts( $filters->without( $facet ), $selected, $search_ids );
				if ( ! $this->available ) {
					return array();
				}
				continue;
			}

			if ( 'novedad' === ( $def['type'] ?? '' ) ) {
				// Flag OFF: sin counts → FacetsView auto-oculta (el gate es
				// query-time; el índice materializa siempre). El bump de gen al
				// togglear rota el transient del landing al instante.
				if ( ! $this->novelty_enabled() ) {
					if ( ! $this->available ) {
						return array();
					}
					continue;
				}
				$out[ $facet ] = $this->novedad_counts( $filters->without( $facet ), $selected, $search_ids );
				if ( ! $this->available ) {
					return array();
				}
				continue;
			}

			if ( empty( $selected ) ) {
				// Base = TODAS las activas (X no está entre ellas): se batchean
				// todas las no-seleccionadas en UNA query (el caso landing).
				$unselected[] = $facet;
				continue;
			}

			// Seleccionada: base propia (todas menos X) — query individual.
			$out[ $facet ] = $this->count_facets( array( $facet ), $filters->without( $facet ), $search_ids )[ $facet ] ?? array();
			if ( ! $this->available ) {
				return array();
			}
			$out[ $facet ] = $this->mark_selected( $out[ $facet ], $selected, (string) $facet );
		}

		if ( ! empty( $unselected ) ) {
			$batched = $this->count_facets( $unselected, $filters, $search_ids );
			if ( ! $this->available ) {
				return array();
			}
			foreach ( $unselected as $facet ) {
				$out[ $facet ] = $batched[ $facet ] ?? array();
			}
		}

		if ( $cacheable ) {
			$this->store_counts( $cache_key, $cache_stamp, $out );
			if ( ! $this->available ) {
				return array();
			}
		}

		return $out;
	}

	/**
	 * True si el espejo no tiene NI UNA fila de docs — índice muerto o jamás
	 * construido, distinto de "0 matches". Solo se consulta cuando un filtrado
	 * dio vacío (barato y raro).
	 *
	 * @return bool
	 */
	public function index_is_empty(): bool {
		if ( ! $this->available ) {
			return false;
		}
		$docs = Install::docs_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- docs_table() retorna identificador sanitizado interno; consulta de sondeo sin parámetros.
		list( $read_ok, $id ) = $this->guarded_call(
			fn() => $this->db->get_var( "SELECT post_id FROM {$docs} LIMIT 1" ),
			'Could not inspect whether the facet index is empty.'
		);
		if ( ! $read_ok ) {
			return false;
		}
		if ( null === $id ) {
			return true;
		}
		if ( null === $this->canonical_positive_integer( $id ) ) {
			$this->mark_unavailable( 'Facet index probe returned an invalid product identifier.' );
			return false;
		}

		return false;
	}

	/**
	 * Clave de cache de counts: firma del contexto implícito y/o selección
	 * univariada. Es ESTABLE — una fila por contexto, que se pisa a sí misma.
	 *
	 * El estado del índice viaja en el STAMP, dentro del valor, no en la clave.
	 * Con el estado en la clave cada reindex (y cada hora, con Novedad ON)
	 * estrenaba fila y no borraba la anterior: N filas del mismo contexto vivas a
	 * la vez, sin techo ni poda. Y con el object cache persistente encendido el
	 * GC de core (`delete_expired_transients`) no corre, así que esas filas
	 * dejaban de ser temporales y pasaban a ser permanentes.
	 *
	 * @param FilterSet $filters Filtros del request (contexto y/o univariada).
	 * @return string
	 */
	private function counts_cache_key( FilterSet $filters ): string {
		$joined = $filters->joined();
		ksort( $joined );
		$ctx = '';
		foreach ( $joined as $facet => $values ) {
			sort( $values );
			$ctx .= '|' . $facet . ':' . implode( ',', $values );
		}

		return 'akb_lc_' . hash( 'sha256', $ctx );
	}

	/**
	 * Determina si el FilterSet representa una selección univariada legítima
	 * (a lo sumo 1 faceta explícita con a lo sumo 1 valor seleccionado) sobre
	 * una faceta conocida y con valor válido.
	 *
	 * @param FilterSet $filters Filtros del request.
	 * @return bool
	 */
	private function is_univariate_selection( FilterSet $filters ): bool {
		$all = $filters->all();
		if ( 1 !== count( $all ) ) {
			return false;
		}
		$facet = array_key_first( $all );
		if ( ! is_string( $facet ) || '' === $facet ) {
			return false;
		}
		$definitions = $this->facet_definitions();
		if ( ! isset( $definitions[ $facet ] ) ) {
			return false;
		}
		$values = $all[ $facet ];
		if ( 1 !== count( $values ) ) {
			return false;
		}
		$value = reset( $values );

		return $this->valid_facet_value( $value );
	}

	/**
	 * Sello del estado del índice: nº de docs + máximo indexed_at + generación
	 * (+ la hora cuantizada con Novedad ON). Cambia con cualquier reindex, así
	 * que la entrada cacheada se auto-invalida sin tocar el Indexer. La query es
	 * 1 lectura barata (~1ms) sobre la tabla docs.
	 *
	 * Devuelve '' si el estado no se puede leer; el caller ya no puede cachear.
	 *
	 * @return string
	 */
	private function counts_cache_stamp(): string {
		$docs = Install::docs_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- docs_table() retorna identificador sanitizado interno; consulta agregada sin parámetros.
		list( $read_ok, $stamp ) = $this->guarded_call(
			fn() => $this->db->get_row( "SELECT COUNT(*) c, COALESCE(MAX(indexed_at),'0') m FROM {$docs}" ),
			'Could not read facet counts cache stamp.'
		);
		if ( ! $read_ok ) {
			return '';
		}
		$stamp_values = $this->validated_cache_stamp( $stamp );
		if ( null === $stamp_values ) {
			$this->mark_unavailable( 'Facet counts cache stamp returned an invalid shape.' );
			return '';
		}
		// La generación (microtime, bumpeada por Indexer en cada escritura live)
		// distingue dos reindex del mismo segundo con COUNT igual — que el stamp
		// COUNT|MAX(indexed_at), de granularidad segundo, no separaba (m4).
		$gen = '0';
		if ( function_exists( 'get_option' ) ) {
			list( $option_ok, $gen_value ) = $this->guarded_call(
				fn() => SemanticPublication::authoritative_value( Install::OPTION_INDEX_GEN, '0' ),
				'Could not read the authoritative facet index generation.',
				false
			);
			if ( ! $option_ok ) {
				return '';
			}
			$gen = is_string( $gen_value ) ? $gen_value : '';
		}
		if ( ! SemanticPublication::authoritative_available() ) {
			$this->mark_unavailable( SemanticPublication::authoritative_error() );
			return '';
		}
		if ( '' === $gen || strlen( $gen ) > 191 || preg_match( '/[\x00-\x1F\x7F]/', $gen ) ) {
			$this->mark_unavailable( 'Facet index generation returned an invalid shape.' );
			return '';
		}

		// Novedad activa: los rangos temporales usan umbrales rodantes (now - Nd).
		// La hora cuantizada viaja dentro del sello (stamp) y no en la clave de
		// caché: invalida automáticamente el valor cada hora (desfase máximo 1h)
		// reutilizando el mismo slot sin multiplicar filas en la tabla de opciones.
		$hour = $this->novelty_enabled() ? '|h' . (string) intdiv( time(), HOUR_IN_SECONDS ) : '';
		if ( ! $this->available ) {
			return '';
		}

		return (string) $stamp_values['count'] . '|' . $stamp_values['max'] . '|' . $gen . $hour;
	}

	/**
	 * Counts cacheados del estado (landing o archive con contexto), o null.
	 * Recibe la clave y el sello YA calculados: quien los pide es el mismo que
	 * guardará al final, y ambas puntas deben hablar del MISMO estado del índice.
	 *
	 * Un sello que no calza es un miss: la fila es de un estado anterior y la
	 * escritura de esta request la va a pisar.
	 *
	 * @param string $key   Clave de cache calculada en counts().
	 * @param string $stamp Sello del estado del índice leído en counts().
	 * @return array<string, array<string, array<string, mixed>>>|null
	 */
	private function cached_counts( string $key, string $stamp ): ?array {
		if ( ! function_exists( 'get_transient' ) || '' === $key || '' === $stamp ) {
			return null;
		}
		try {
			$cached = get_transient( $key );
		} catch ( \Throwable $exception ) {
			return null;
		}
		if ( ! is_array( $cached )
			|| ! isset( $cached['stamp'], $cached['data'] )
			|| ! is_string( $cached['stamp'] )
			|| ! hash_equals( $stamp, $cached['stamp'] )
			|| ! is_array( $cached['data'] ) ) {
			return null;
		}

		return $this->validated_cached_counts( $cached['data'] );
	}

	/**
	 * Persiste los counts bajo la clave de la LECTURA, y solo si el estado del
	 * índice no se movió mientras se contaba: un reindex live a mitad del bucle
	 * deja un resultado desgarrado (mitad S0, mitad S1) que, archivado bajo la
	 * clave de S1, envenena el landing hasta el próximo bump. Ante cambio se
	 * descarta y la próxima request recalcula.
	 *
	 * El costo es el mismo de antes (2 lecturas de stamp por miss); la diferencia
	 * es que la segunda ahora VERIFICA en vez de archivar bajo un estado ajeno.
	 *
	 * @param string                                            $key    Clave (estable) de la lectura.
	 * @param string                                            $stamp  Sello del estado leído al empezar a contar.
	 * @param array<string, array<string, array<string, mixed>>> $counts Counts calculados.
	 * @return void
	 */
	private function store_counts( string $key, string $stamp, array $counts ): void {
		if ( ! function_exists( 'set_transient' ) || '' === $key || '' === $stamp ) {
			return;
		}
		$current_stamp = $this->counts_cache_stamp();
		if ( ! $this->available || $stamp !== $current_stamp ) {
			return;
		}
		// TTL de 1 día como backstop; el sello por-estado ya invalida al reindexar.
		// La clave es estable, así que esta escritura PISA la fila anterior de
		// este contexto en vez de dejarla viva hasta el TTL.
		try {
			set_transient( $key, array( 'stamp' => $stamp, 'data' => $counts ), DAY_IN_SECONDS );
		} catch ( \Throwable $exception ) {
			// El transient es una optimización. La respuesta calculada sigue siendo
			// autoritativa aunque el backend de object cache no pueda escribir.
		}
	}

	/**
	 * Cuenta VARIAS facetas que comparten el mismo set base en UNA query
	 * (`GROUP BY facet, value`). El set base son las facetas de $base_filters
	 * (ninguna puede estar entre las contadas — se garantiza en counts()).
	 *
	 * @param array<int, string>   $facet_names  Facetas a contar.
	 * @param FilterSet            $base_filters Facetas activas del set base.
	 * @param array<int, int>|null $search_ids   Ids del motor.
	 * @return array<string, array<string, array{value: string, label: string, count: int, selected: bool}>>
	 */
	private function count_facets( array $facet_names, FilterSet $base_filters, ?array $search_ids ): array {
		$out = array();
		if ( empty( $facet_names ) || ( null !== $search_ids && empty( $search_ids ) ) ) {
			return $out;
		}
		$definitions = $this->facet_definitions();
		if ( ! $this->available ) {
			return $out;
		}
		$seen_names = array();
		foreach ( $facet_names as $name ) {
			if ( ! is_string( $name ) || '' === $name || isset( $seen_names[ $name ] )
				|| ! isset( $definitions[ $name ] )
				|| in_array( (string) ( $definitions[ $name ]['type'] ?? '' ), array( 'precio', 'novedad' ), true ) ) {
				$this->mark_unavailable( 'Facet counts requested an invalid facet definition.' );
				return array();
			}
			$seen_names[ $name ] = true;
			$out[ $name ]        = array();
		}

		$facets = Install::facets_table();
		$docs   = Install::docs_table();

		list( $joins, $join_params ) = $this->facet_joins( $base_filters );
		list( $search_sql, $search_params ) = $this->search_constraint( $search_ids, 'fx' );
		if ( ! $this->available ) {
			return $out;
		}

		$in = implode( ', ', array_fill( 0, count( $facet_names ), '%s' ) );

		// Sin JOINs de faceta, DISTINCT es un no-op DEMOSTRABLE: la PK de la
		// tabla espejo es (facet, post_id, value), así que dentro de un grupo
		// (facet, value) el post_id no puede repetirse — verificado además sobre
		// la tabla entera (cero grupos con COUNT(DISTINCT) <> COUNT(*)). La
		// ÚNICA fuente de duplicación es `facet_joins()`, que con multi-selección
		// multiplica filas por producto; ahí el DISTINCT se conserva.
		// Éste es el camino del landing y del lote batcheado, o sea el más
		// golpeado y el que se cachea: medido 39,02 → 22,14 ms (−43 %).
		$count = array() === $joins ? 'COUNT(*)' : 'COUNT(DISTINCT fx.post_id)';

		// JOIN fail-closed contra posts publicados para que los conteos solo reflejen productos activos.
		$sql = "SELECT fx.facet, fx.value, MAX(fx.label) AS label, {$count} AS c
			FROM {$facets} fx
			JOIN {$docs} d ON d.post_id = fx.post_id AND d.visible = 1
			JOIN {$this->db->posts} p ON p.ID = d.post_id AND p.post_status = 'publish' "
			. implode( ' ', $joins )
			. " WHERE fx.facet IN ({$in})" . $search_sql
			. ' GROUP BY fx.facet, fx.value ORDER BY c DESC, fx.value ASC';

		$all_params = array_merge( $join_params, array_values( $facet_names ), $search_params );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- preparado abajo.
		list( $prepared_ok, $prepared ) = $this->guarded_call(
			fn() => $this->db->prepare( $sql, ...$all_params ),
			'Could not prepare facet counts read.',
			false
		);
		if ( ! $prepared_ok ) {
			return $out;
		}
		if ( ! is_string( $prepared ) ) {
			$this->mark_unavailable( 'Could not prepare facet counts read.' );
			return $out;
		}
		list( $read_ok, $rows ) = $this->guarded_call(
			fn() => $this->db->get_results( $prepared ),
			'Could not read facet counts.'
		);
		if ( ! $read_ok || ! is_array( $rows ) ) {
			if ( $read_ok ) {
				$this->mark_unavailable( 'Facet counts returned an invalid result shape.' );
			}
			return $out;
		}

		foreach ( $rows as $row ) {
			if ( ! is_object( $row ) || ! $this->has_exact_keys( get_object_vars( $row ), array( 'facet', 'value', 'label', 'c' ) )
				|| ! is_string( $row->facet ) || ! isset( $seen_names[ $row->facet ] )
				|| ! $this->valid_facet_value( $row->value ) || ! $this->valid_facet_label( $row->label ) ) {
				$this->mark_unavailable( 'Facet counts returned an invalid row shape.' );
				return array();
			}
			$count = $this->canonical_nonnegative_integer( $row->c );
			if ( null === $count || isset( $out[ $row->facet ][ $row->value ] ) ) {
				$this->mark_unavailable( 'Facet counts returned an invalid or duplicate row.' );
				return array();
			}
			$out[ $row->facet ][ $row->value ] = array(
				'value'    => $row->value,
				'label'    => $row->label,
				'count'    => $count,
				'selected' => false,
			);
		}

		return $out;
	}

	/**
	 * Marca las opciones seleccionadas y agrega las que quedaron en count 0
	 * (para que el usuario pueda des-seleccionarlas).
	 *
	 * @param array<string, array<string, mixed>> $options  Opciones contadas.
	 * @param array<int, string>                   $selected Valores seleccionados.
	 * @return array<string, array<string, mixed>>
	 */
	private function mark_selected( array $options, array $selected, string $facet = '' ): array {
		$selected_map = array_flip( $selected );
		foreach ( $options as $value => &$opt ) {
			$opt['selected'] = isset( $selected_map[ $value ] );
		}
		unset( $opt );

		$missing = array();
		foreach ( $selected as $value ) {
			if ( ! isset( $options[ $value ] ) ) {
				$missing[] = (string) $value;
			}
		}
		if ( empty( $missing ) ) {
			return $options;
		}

		// Resuelve la etiqueta legible para valores seleccionados que quedaron sin
		// conteo en la combinación actual, garantizando que los chips de deselección
		// muestren el nombre legible y no el slug crudo.
		$labels = $this->labels_for_values( $facet, $missing );
		foreach ( $missing as $value ) {
			$options[ $value ] = array(
				'value'    => $value,
				'label'    => $labels[ $value ] ?? $value,
				'count'    => 0,
				'selected' => true,
			);
		}

		return $options;
	}

	/**
	 * Etiquetas legibles de valores concretos de una faceta, leídas del índice.
	 *
	 * Sólo se invoca para valores SELECCIONADOS que quedaron sin conteo, así que
	 * el tamaño está acotado por la cantidad de filtros activos. Usa
	 * `idx_facet_value (facet, value)`.
	 *
	 * FAIL-OPEN DELIBERADO, y por eso NO usa `guarded_call()`: ese helper llama a
	 * `mark_unavailable()` ante cualquier fallo, o sea que un tropiezo en esta
	 * consulta —puramente cosmética— apagaría TODAS las facetas del catálogo. Se
	 * aísla a mano: `begin_read()` ya limpia `last_error` en cada lectura, así que
	 * un error propio no contamina las lecturas siguientes, y acá se traga sin
	 * tocar `$this->available`. El llamador cae al slug, que es exactamente el
	 * comportamiento anterior a este método.
	 *
	 * @param string            $facet  Nombre de la faceta.
	 * @param array<int,string> $values Valores sin conteo.
	 * @return array<string,string> Mapa valor => etiqueta.
	 */
	private function labels_for_values( string $facet, array $values ): array {
		if ( '' === $facet || empty( $values ) || ! $this->available || ! $this->valid_facet_value( $facet ) ) {
			return array();
		}
		foreach ( $values as $value ) {
			if ( ! $this->valid_facet_value( $value ) ) {
				return array();
			}
		}

		$facets       = Install::facets_table();
		$placeholders = implode( ', ', array_fill( 0, count( $values ), '%s' ) );

		try {
			$this->begin_read();
			$prepared = $this->db->prepare(
				"SELECT value, MIN(label) AS label FROM {$facets} WHERE facet = %s AND value IN ({$placeholders}) GROUP BY value", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- tabla del propio plugin y placeholders generados.
				array_merge( array( $facet ), array_values( $values ) )
			);
			if ( ! is_string( $prepared ) || '' === $prepared ) {
				return array();
			}
			$rows = $this->db->get_results( $prepared );
			if ( $this->read_has_error() || ! is_array( $rows ) ) {
				return array();
			}
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return array();
		} finally {
			// No dejar un error propio a la vista de la próxima comprobación.
			$this->begin_read();
		}

		$out = array();
		foreach ( $rows as $row ) {
			if ( ! is_object( $row ) || ! isset( $row->value, $row->label )
				|| ! $this->valid_facet_value( $row->value )
				|| ! $this->valid_facet_label( $row->label ) ) {
				continue;
			}
			$out[ (string) $row->value ] = (string) $row->label;
		}

		return $out;
	}

	/**
	 * Counts de los buckets de precio, query-time sobre value_num, con el set
	 * base como JOINs.
	 *
	 * @param FilterSet            $others     Facetas activas MENOS precio.
	 * @param array<int, string>   $selected   Índices de bucket seleccionados.
	 * @param array<int, int>|null $search_ids Ids del motor.
	 * @return array<string, array{value: string, label: string, count: int, selected: bool}>
	 */
	private function price_counts( FilterSet $others, array $selected, ?array $search_ids ): array {
		$buckets = $this->price_buckets();
		if ( ! $this->available ) {
			return array();
		}
		$selected_map = $this->selected_value_map( $selected );
		if ( null === $selected_map ) {
			$this->mark_unavailable( 'Price facet selections returned an invalid shape.' );
			return array();
		}
		if ( null !== $search_ids && empty( $search_ids ) ) {
			$out = array();
			foreach ( $buckets as $i => $bucket ) {
				$out[ (string) $i ] = array(
					'value'    => (string) $i,
					'label'    => (string) $bucket['label'],
					'count'    => 0,
					'selected' => isset( $selected_map[ (string) $i ] ),
				);
			}
			return $out;
		}

		$facets = Install::facets_table();
		$docs   = Install::docs_table();

		list( $joins, $join_params ) = $this->facet_joins( $others );
		list( $search_sql, $search_params ) = $this->search_constraint( $search_ids, 'fx' );
		if ( ! $this->available ) {
			return array();
		}

		// Cómputo agregado mediante un único COUNT(DISTINCT CASE) por bucket.
		// Los parámetros del SELECT (CASE) preceden en la consulta a los de JOIN y búsqueda.
		$selects       = array();
		$select_params = array();
		foreach ( $buckets as $i => $bucket ) {
			$cond = array();
			if ( null !== $bucket['min'] ) {
				$cond[]          = 'fx.value_num >= %f';
				$select_params[] = (float) $bucket['min'];
			}
			if ( null !== $bucket['max'] ) {
				$cond[]          = 'fx.value_num < %f';
				$select_params[] = (float) $bucket['max'];
			}
			$test      = empty( $cond ) ? '1=1' : implode( ' AND ', $cond );
			$selects[] = "COUNT(DISTINCT CASE WHEN {$test} THEN fx.post_id END) AS b{$i}";
		}

		// JOIN fail-closed contra posts publicados.
		$sql = 'SELECT ' . implode( ', ', $selects ) . "
			FROM {$facets} fx
			JOIN {$docs} d ON d.post_id = fx.post_id AND d.visible = 1
			JOIN {$this->db->posts} p ON p.ID = d.post_id AND p.post_status = 'publish' "
			. implode( ' ', $joins )
			. " WHERE fx.facet = 'precio'" . $search_sql;

		$all_params = array_merge( $select_params, $join_params, $search_params );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- preparado abajo.
		list( $prepared_ok, $prepared ) = $this->guarded_call(
			fn() => $this->db->prepare( $sql, ...$all_params ),
			'Could not prepare price facet counts read.',
			false
		);
		if ( ! $prepared_ok ) {
			return array();
		}
		if ( ! is_string( $prepared ) ) {
			$this->mark_unavailable( 'Could not prepare price facet counts read.' );
			return array();
		}
		list( $read_ok, $row ) = $this->guarded_call(
			fn() => $this->db->get_row( $prepared, ARRAY_A ),
			'Could not read price facet counts.'
		);
		if ( ! $read_ok ) {
			return array();
		}
		$bucket_counts = $this->validated_bucket_count_row( $row, count( $buckets ) );
		if ( null === $bucket_counts ) {
			$this->mark_unavailable( 'Price facet counts returned an invalid row shape.' );
			return array();
		}

		$out = array();
		foreach ( $buckets as $i => $bucket ) {
			$out[ (string) $i ] = array(
				'value'    => (string) $i,
				'label'    => (string) $bucket['label'],
				'count'    => $bucket_counts[ $i ],
				'selected' => isset( $selected_map[ (string) $i ] ),
			);
		}

		return $out;
	}

	/**
	 * Construye los JOINs al espejo para cada faceta activa (AND entre facetas;
	 * OR dentro por `value IN (...)` o rango de precio). Devuelve [sql[], params].
	 *
	 * @param FilterSet $filters Facetas activas.
	 * @return array{0: array<int, string>, 1: array<int, mixed>}
	 */
	private function facet_joins( FilterSet $filters ): array {
		$facets = Install::facets_table();
		$joins  = array();
		$params = array();
		$i      = 0;
		$definitions = $this->facet_definitions();
		if ( ! $this->available ) {
			return array( array(), array() );
		}

		foreach ( $filters->joined() as $facet => $values ) {
			if ( ! is_string( $facet ) || ! isset( $definitions[ $facet ] ) || ! $this->valid_filter_values( $values ) ) {
				$this->mark_unavailable( 'Facet filters returned an invalid shape.' );
				return array( array(), array() );
			}
			$alias = 'f' . $i;
			++$i;

			$def = $definitions[ $facet ];

			if ( 'novedad' === ( $def['type'] ?? '' ) ) {
				// Flag OFF: un `_novedad` en la URL se ignora limpio (sin
				// backdoor) — mismo drop del JOIN que el índice muerto de precio.
				if ( ! $this->novelty_enabled() ) {
					if ( ! $this->available ) {
						return array( array(), array() );
					}
					--$i;
					continue;
				}
				list( $cond, $p ) = $this->novedad_join_condition( $alias, $values );
				// Ningún índice de bucket válido (URL manipulada/bookmarkeada):
				// sin JOIN = "sin filtro de novedad", no `1=0` [gate DISEÑO v2 §4].
				if ( '' === $cond ) {
					--$i;
					continue;
				}
				$joins[] = "JOIN {$facets} {$alias} ON {$alias}.post_id = d.post_id AND {$cond}";
				$params  = array_merge( $params, $p );
				continue;
			}

			if ( 'precio' === ( $def['type'] ?? '' ) ) {
				// Faceta oculta (sin buckets utilizables) = un `_precio` en la URL
				// no filtra por la const [gate v3.1 nit 2] — mismo drop del JOIN
				// que el caso all-invalid.
				if ( ! $this->has_price_buckets() ) {
					if ( ! $this->available ) {
						return array( array(), array() );
					}
					--$i;
					continue;
				}
				list( $cond, $p ) = $this->price_join_condition( $alias, $values );
				// Sin ningún bucket VÁLIDO seleccionado (todos los índices de la URL
				// cayeron fuera del set vigente — típico tras editar/achicar buckets
				// con un `_precio=<índice muerto>` bookmarkeado): NO agregamos el JOIN.
				// Emitir `1=0` daría cero productos por el AND entre facetas (callejón
				// de cero-resultados SIN chip visible); omitirlo = "sin filtro de precio"
				// = el índice muerto se ignora limpio [gate DISEÑO v2 §4]. `--$i`: este
				// alias no se usó, reciclarlo mantiene los alias contiguos.
				if ( '' === $cond ) {
					--$i;
					continue;
				}
				$joins[] = "JOIN {$facets} {$alias} ON {$alias}.post_id = d.post_id AND {$cond}";
				$params  = array_merge( $params, $p );
				continue;
			}

			$placeholders = implode( ', ', array_fill( 0, count( $values ), '%s' ) );
			$joins[]      = "JOIN {$facets} {$alias} ON {$alias}.post_id = d.post_id AND {$alias}.facet = %s AND {$alias}.value IN ({$placeholders})";
			$params[]     = $facet;
			$params       = array_merge( $params, array_values( $values ) );
		}

		return array( $joins, $params );
	}

	/**
	 * Condición JOIN de un filtro de precio (OR entre buckets seleccionados).
	 * Devuelve `['', []]` cuando NINGÚN índice seleccionado resuelve a un bucket
	 * vigente — el caller (facet_joins) omite el JOIN en ese caso (no filtra por
	 * precio) en vez de emitir `1=0` [gate DISEÑO v2 §4].
	 *
	 * @param string             $alias          Alias de la tabla.
	 * @param array<int, string> $bucket_indices Índices de bucket.
	 * @return array{0: string, 1: array<int, float>}
	 */
	private function price_join_condition( string $alias, array $bucket_indices ): array {
		$buckets = $this->price_buckets();
		if ( ! $this->available ) {
			return array( '', array() );
		}
		$ranges  = array();
		$params  = array();
		foreach ( $bucket_indices as $idx ) {
			if ( ! is_string( $idx ) || ! preg_match( '/^(?:0|[1-9][0-9]*)$/D', $idx ) ) {
				continue;
			}
			$bucket = $buckets[ (int) $idx ] ?? null;
			if ( null === $bucket ) {
				continue;
			}
			$cond = array();
			if ( null !== $bucket['min'] ) {
				$cond[]   = "{$alias}.value_num >= %f";
				$params[] = (float) $bucket['min'];
			}
			if ( null !== $bucket['max'] ) {
				$cond[]   = "{$alias}.value_num < %f"; // max EXCLUSIVO (buckets contiguos, m6).
				$params[] = (float) $bucket['max'];
			}
			$ranges[] = '(' . ( empty( $cond ) ? '1=1' : implode( ' AND ', $cond ) ) . ')';
		}

		// Ningún bucket válido → sin condición: el caller no agrega el JOIN.
		if ( empty( $ranges ) ) {
			return array( '', array() );
		}

		$cond = "{$alias}.facet = 'precio' AND ( " . implode( ' OR ', $ranges ) . ' )';
		return array( $cond, $params );
	}

	/**
	 * Counts de los buckets de novedad, query-time sobre value_num (epoch UTC),
	 * con el set base como JOINs. Clon de price_counts(); counts CUMULATIVOS
	 * por diseño (rangos anidados: "Último mes" incluye los de "7 días" — el
	 * label dice exactamente lo que cuenta).
	 *
	 * @param FilterSet            $others     Facetas activas MENOS novedad.
	 * @param array<int, string>   $selected   Índices de bucket seleccionados.
	 * @param array<int, int>|null $search_ids Ids del motor.
	 * @return array<string, array{value: string, label: string, count: int, selected: bool}>
	 */
	private function novedad_counts( FilterSet $others, array $selected, ?array $search_ids ): array {
		$selected_map = $this->selected_value_map( $selected );
		if ( null === $selected_map ) {
			$this->mark_unavailable( 'Novelty facet selections returned an invalid shape.' );
			return array();
		}
		$buckets = $this->novelty_buckets();
		if ( ! $this->available ) {
			return array();
		}

		if ( null !== $search_ids && empty( $search_ids ) ) {
			$out = array();
			foreach ( $buckets as $i => $bucket ) {
				$out[ (string) $i ] = array(
					'value'    => (string) $i,
					'label'    => (string) $bucket['label'],
					'count'    => 0,
					'selected' => isset( $selected_map[ (string) $i ] ),
				);
			}
			return $out;
		}

		$facets = Install::facets_table();
		$docs   = Install::docs_table();

		list( $joins, $join_params ) = $this->facet_joins( $others );
		list( $search_sql, $search_params ) = $this->search_constraint( $search_ids, 'fx' );
		if ( ! $this->available ) {
			return array();
		}

		// Cómputo agregado mediante un único COUNT(DISTINCT CASE) por bucket.
		// Los parámetros del SELECT (CASE) preceden en la consulta a los de JOIN y búsqueda.
		$selects       = array();
		$select_params = array();
		foreach ( $buckets as $i => $bucket ) {
			$cutoff = $this->novelty_cutoff( (int) $bucket['days'] );
			if ( ! $this->available ) {
				return array();
			}
			$selects[]       = "COUNT(DISTINCT CASE WHEN fx.value_num >= %f THEN fx.post_id END) AS b{$i}";
			$select_params[] = (float) $cutoff;
		}

		// JOIN fail-closed contra posts publicados.
		$sql = 'SELECT ' . implode( ', ', $selects ) . "
			FROM {$facets} fx
			JOIN {$docs} d ON d.post_id = fx.post_id AND d.visible = 1
			JOIN {$this->db->posts} p ON p.ID = d.post_id AND p.post_status = 'publish' "
			. implode( ' ', $joins )
			. " WHERE fx.facet = 'novedad'" . $search_sql;

		$all_params = array_merge( $select_params, $join_params, $search_params );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- preparado abajo.
		list( $prepared_ok, $prepared ) = $this->guarded_call(
			fn() => $this->db->prepare( $sql, ...$all_params ),
			'Could not prepare novelty facet counts read.',
			false
		);
		if ( ! $prepared_ok ) {
			return array();
		}
		if ( ! is_string( $prepared ) ) {
			$this->mark_unavailable( 'Could not prepare novelty facet counts read.' );
			return array();
		}
		list( $read_ok, $row ) = $this->guarded_call(
			fn() => $this->db->get_row( $prepared, ARRAY_A ),
			'Could not read novelty facet counts.'
		);
		if ( ! $read_ok ) {
			return array();
		}
		$bucket_counts = $this->validated_bucket_count_row( $row, count( $buckets ) );
		if ( null === $bucket_counts ) {
			$this->mark_unavailable( 'Novelty facet counts returned an invalid row shape.' );
			return array();
		}

		$out = array();
		foreach ( $buckets as $i => $bucket ) {
			$out[ (string) $i ] = array(
				'value'    => (string) $i,
				'label'    => (string) $bucket['label'],
				'count'    => $bucket_counts[ $i ],
				'selected' => isset( $selected_map[ (string) $i ] ),
			);
		}

		return $out;
	}

	/**
	 * Condición JOIN de un filtro de novedad (OR entre buckets seleccionados —
	 * anidados, así que OR colapsa al rango más ancho; correcto igual).
	 * Devuelve `['', []]` cuando NINGÚN índice seleccionado resuelve a un
	 * bucket vigente — el caller omite el JOIN (el índice muerto se ignora
	 * limpio, sin `1=0`) [mismo criterio que price_join_condition].
	 *
	 * @param string             $alias          Alias de la tabla.
	 * @param array<int, string> $bucket_indices Índices de bucket.
	 * @return array{0: string, 1: array<int, float>}
	 */
	private function novedad_join_condition( string $alias, array $bucket_indices ): array {
		$buckets = $this->novelty_buckets();
		if ( ! $this->available ) {
			return array( '', array() );
		}
		$ranges  = array();
		$params  = array();
		foreach ( $bucket_indices as $idx ) {
			if ( ! is_string( $idx ) || ! preg_match( '/^(?:0|[1-9][0-9]*)$/D', $idx ) ) {
				continue;
			}
			$bucket = $buckets[ (int) $idx ] ?? null;
			if ( null === $bucket ) {
				continue;
			}
			$cutoff = $this->novelty_cutoff( (int) $bucket['days'] );
			if ( ! $this->available ) {
				return array( '', array() );
			}
			$ranges[] = "({$alias}.value_num >= %f)";
			$params[] = (float) $cutoff;
		}

		if ( empty( $ranges ) ) {
			return array( '', array() );
		}

		$cond = "{$alias}.facet = 'novedad' AND ( " . implode( ' OR ', $ranges ) . ' )';
		return array( $cond, $params );
	}

	/**
	 * Restricción `post_id IN (search_ids)` si hay búsqueda. El alias por
	 * defecto es `d`; en las queries de count la columna vive en `fx`.
	 *
	 * @param array<int, int>|null $search_ids Ids del motor.
	 * @param string               $alias      Alias de la tabla con post_id.
	 * @return array{0: string, 1: array<int, int>}
	 */
	private function search_constraint( ?array $search_ids, string $alias = 'd' ): array {
		$search_ids = $this->validated_search_ids( $search_ids );
		if ( ! $this->available ) {
			return array( ' AND 1=0', array() );
		}
		if ( null === $search_ids ) {
			return array( '', array() );
		}
		if ( empty( $search_ids ) ) {
			// Sin resultados de búsqueda: cláusula imposible.
			return array( ' AND 1=0', array() );
		}

		// El motor devolvió el catálogo visible ENTERO (pasa con queries amplias
		// tipo "de", que matchean los ~1.5k docs): el IN no restringe nada — todas
		// las queries que lo usan ya traen `d.visible = 1` — y sólo cuesta parsear
		// y bindear ~1.5k placeholders en CADA una de las ~12 queries de counts.
		// `array_flip` es el cinturón: sólo un set sin repetidos que cubre el total
		// es equivalente a "sin filtro" (candidates() ya emite DISTINCT, así que
		// el flip es una formalidad barata frente a la query que ahorra).
		$total = $this->visible_docs_count();
		if ( $total > 0 && count( $search_ids ) >= $total && count( array_flip( $search_ids ) ) >= $total ) {
			return array( '', array() );
		}

		$placeholders = implode( ', ', array_fill( 0, count( $search_ids ), '%d' ) );
		return array( " AND {$alias}.post_id IN ({$placeholders})", $search_ids );
	}

	/**
	 * Docs VISIBLES del espejo, memoizado por request. Sirve de cota superior
	 * para detectar el caso "la búsqueda devolvió todo" en search_constraint();
	 * es 1 COUNT(*) indexado, que se amortiza contra las ~13 queries que evita
	 * bindear con la lista completa de ids.
	 *
	 * @return int
	 */
	private function visible_docs_count(): int {
		if ( null !== $this->visible_docs_memo ) {
			return $this->visible_docs_memo;
		}
		$docs = Install::docs_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- docs_table() retorna identificador sanitizado interno; consulta agregada sin parámetros.
		list( $read_ok, $count ) = $this->guarded_call(
			fn() => $this->db->get_var( "SELECT COUNT(*) FROM {$docs} WHERE visible = 1" ),
			'Could not read visible document count.'
		);
		if ( ! $read_ok ) {
			return 0;
		}
		$count = $this->canonical_nonnegative_integer( $count );
		if ( null === $count ) {
			$this->mark_unavailable( 'Visible document count returned an invalid shape.' );
			return 0;
		}
		$this->visible_docs_memo = $count;

		return $this->visible_docs_memo;
	}

	/**
	 * Ejecuta una dependencia autoritativa sin permitir que un Throwable escape
	 * por la superficie pública. Las lecturas SQL limpian y verifican last_error;
	 * prepare() y fuentes estáticas usan `$read=false`.
	 *
	 * @param callable $callback Operación a ejecutar.
	 * @param string   $fallback Diagnóstico estable.
	 * @param bool     $read     Si la operación es una lectura wpdb.
	 * @return array{0: bool, 1: mixed}
	 */
	private function guarded_call( callable $callback, string $fallback, bool $read = true ): array {
		if ( ! $this->available ) {
			return array( false, null );
		}

		try {
			if ( $read ) {
				$this->begin_read();
			}
			$result = $callback();
		} catch ( \Throwable $exception ) {
			$this->mark_unavailable( '' !== trim( $exception->getMessage() ) ? $exception->getMessage() : $fallback );
			return array( false, null );
		}

		if ( $read && $this->read_has_error() ) {
			$this->mark_unavailable( $fallback );
			return array( false, null );
		}

		return array( true, $result );
	}

	/** @return array<string, array<string, mixed>> */
	private function facet_definitions(): array {
		list( $read_ok, $definitions ) = $this->guarded_call(
			fn() => FacetRegistry::definitions(),
			'Could not read the authoritative facet registry.',
			false
		);
		if ( ! $read_ok || ! $this->require_facet_registry_authority() ) {
			return array();
		}
		if ( ! is_array( $definitions ) || empty( $definitions ) || count( $definitions ) > 50 ) {
			$this->mark_unavailable( 'Facet registry returned an invalid definition set.' );
			return array();
		}
		foreach ( $definitions as $name => $definition ) {
			if ( ! is_string( $name ) || ! preg_match( '/^[a-z0-9_-]{1,64}$/D', $name ) || ! is_array( $definition )
				|| ! isset( $definition['label'], $definition['type'], $definition['ui'] )
				|| ! $this->valid_facet_label( $definition['label'] )
				|| ! is_string( $definition['type'] )
				|| ! in_array( $definition['type'], array( 'taxonomy', 'stock', 'oferta', 'precio', 'novedad' ), true )
				|| ! is_string( $definition['ui'] )
				|| ! in_array( $definition['ui'], array( 'checkboxes', 'radio', 'alpha' ), true ) ) {
				$this->mark_unavailable( 'Facet registry returned an invalid definition.' );
				return array();
			}
			if ( 'taxonomy' === $definition['type']
				&& ( ! isset( $definition['taxonomy'] ) || ! is_string( $definition['taxonomy'] )
					|| ! preg_match( '/^[a-z0-9_-]{1,64}$/D', $definition['taxonomy'] ) ) ) {
				$this->mark_unavailable( 'Facet registry returned an invalid taxonomy definition.' );
				return array();
			}
		}

		return $definitions;
	}

	/** Confirma inmediatamente la autoridad sticky del registry. */
	private function require_facet_registry_authority(): bool {
		list( $check_ok, $registry_available ) = $this->guarded_call(
			fn() => FacetRegistry::is_available(),
			'Could not verify the authoritative facet registry.',
			false
		);
		if ( ! $check_ok ) {
			return false;
		}
		if ( true !== $registry_available ) {
			$error = 'Facet registry source is not authoritative.';
			try {
				$candidate = FacetRegistry::last_error();
				if ( is_string( $candidate ) && '' !== trim( $candidate ) ) {
					$error = $candidate;
				}
			} catch ( \Throwable $exception ) {
				if ( '' !== trim( $exception->getMessage() ) ) {
					$error = $exception->getMessage();
				}
			}
			$this->mark_unavailable( $error );
			return false;
		}

		return true;
	}

	/** Fuente autoritativa de la visibilidad de buckets de precio. */
	private function has_price_buckets(): bool {
		list( $read_ok, $has_buckets ) = $this->guarded_call(
			fn() => FacetRegistry::has_precio_buckets(),
			'Could not read price facet availability.',
			false
		);
		if ( ! $read_ok || ! $this->require_facet_registry_authority() ) {
			return false;
		}

		return true === $has_buckets;
	}

	/** @return array<int, array{min: int|null, max: int|null, label: string}> */
	private function price_buckets(): array {
		list( $read_ok, $buckets ) = $this->guarded_call(
			fn() => FacetRegistry::precio_buckets(),
			'Could not read authoritative price facet buckets.',
			false
		);
		if ( ! $read_ok || ! $this->require_facet_registry_authority() ) {
			return array();
		}
		if ( ! is_array( $buckets ) || ! $this->is_list( $buckets )
			|| null !== FacetRegistry::validate_precio_buckets( $buckets ) ) {
			$this->mark_unavailable( 'Price facet buckets returned an invalid shape.' );
			return array();
		}
		foreach ( $buckets as $bucket ) {
			if ( ! $this->has_exact_keys( $bucket, array( 'min', 'max', 'label' ) ) || ! $this->valid_facet_label( $bucket['label'] ) ) {
				$this->mark_unavailable( 'Price facet bucket returned an invalid shape.' );
				return array();
			}
		}

		return $buckets;
	}

	/** Fuente autoritativa del flag de novedad. */
	private function novelty_enabled(): bool {
		list( $read_ok, $enabled ) = $this->guarded_call(
			fn() => Novedad::enabled(),
			'Could not read novelty facet availability.',
			false
		);
		if ( ! $read_ok || ! $this->require_novelty_authority() ) {
			return false;
		}

		return true === $enabled;
	}

	/** @return array<int, array{days: int, label: string}> */
	private function novelty_buckets(): array {
		list( $read_ok, $buckets ) = $this->guarded_call(
			fn() => Novedad::buckets(),
			'Could not read authoritative novelty facet buckets.',
			false
		);
		if ( ! $read_ok || ! $this->require_novelty_authority() ) {
			return array();
		}
		if ( ! is_array( $buckets ) || ! $this->is_list( $buckets ) || empty( $buckets ) || count( $buckets ) > 8 ) {
			$this->mark_unavailable( 'Novelty facet buckets returned an invalid shape.' );
			return array();
		}
		$previous_days = 0;
		foreach ( $buckets as $bucket ) {
			if ( ! is_array( $bucket ) || ! $this->has_exact_keys( $bucket, array( 'days', 'label' ) )
				|| ! is_int( $bucket['days'] ) || $bucket['days'] <= $previous_days || $bucket['days'] > 3650
				|| ! $this->valid_facet_label( $bucket['label'] ) ) {
				$this->mark_unavailable( 'Novelty facet bucket returned an invalid shape.' );
				return array();
			}
			$previous_days = $bucket['days'];
		}

		return $buckets;
	}

	/** Corte de novedad verificado contra la autoridad sticky. */
	private function novelty_cutoff( int $days ): int {
		list( $read_ok, $cutoff ) = $this->guarded_call(
			fn() => Novedad::cutoff( $days ),
			'Could not calculate the novelty facet cutoff.',
			false
		);
		if ( ! $read_ok || ! $this->require_novelty_authority() ) {
			return 0;
		}
		if ( ! is_int( $cutoff ) || $cutoff < 0 ) {
			$this->mark_unavailable( 'Novelty facet cutoff returned an invalid shape.' );
			return 0;
		}

		return $cutoff;
	}

	/** Confirma inmediatamente la autoridad sticky de Novedad/SemanticPublication. */
	private function require_novelty_authority(): bool {
		list( $check_ok, $novelty_available ) = $this->guarded_call(
			fn() => Novedad::is_available(),
			'Could not verify the authoritative novelty configuration.',
			false
		);
		if ( ! $check_ok ) {
			return false;
		}
		if ( true !== $novelty_available ) {
			$error = SemanticPublication::authoritative_error();
			$this->mark_unavailable( is_string( $error ) && '' !== trim( $error ) ? $error : 'Novelty configuration is not authoritative.' );
			return false;
		}

		return true;
	}

	/** Valida que un FilterSet manual conserve la gramática del parser público. */
	private function filter_set_is_valid( FilterSet $filters ): bool {
		$definitions = $this->facet_definitions();
		if ( ! $this->available ) {
			return false;
		}
		$joined = $filters->joined();
		if ( count( $joined ) > count( $definitions ) ) {
			$this->mark_unavailable( 'Facet filters exceeded the authoritative registry.' );
			return false;
		}
		foreach ( $joined as $facet => $values ) {
			if ( ! is_string( $facet ) || ! isset( $definitions[ $facet ] ) || ! $this->valid_filter_values( $values ) ) {
				$this->mark_unavailable( 'Facet filters returned an invalid shape.' );
				return false;
			}
		}

		return true;
	}

	/** @param mixed $values */
	private function valid_filter_values( $values ): bool {
		if ( ! is_array( $values ) || ! $this->is_list( $values ) || empty( $values ) || count( $values ) > 200 ) {
			return false;
		}
		$seen = array();
		foreach ( $values as $value ) {
			if ( ! $this->valid_facet_value( $value ) || isset( $seen[ $value ] ) ) {
				return false;
			}
			$seen[ $value ] = true;
		}

		return true;
	}

	/** @param array<int, mixed>|null $ids @return array<int, int>|null */
	private function validated_search_ids( ?array $ids ): ?array {
		if ( null === $ids ) {
			return null;
		}
		$validated = $this->canonical_positive_id_list( $ids );
		if ( null === $validated ) {
			$this->mark_unavailable( 'Search constraint returned invalid product identifiers.' );
			return array();
		}

		return $validated;
	}

	/** @param array<int|string, mixed> $ids @return array<int, int>|null */
	private function canonical_positive_id_list( array $ids ): ?array {
		if ( ! $this->is_list( $ids ) ) {
			return null;
		}
		$out  = array();
		$seen = array();
		foreach ( $ids as $candidate ) {
			$id = $this->canonical_positive_integer( $candidate );
			if ( null === $id || isset( $seen[ $id ] ) ) {
				return null;
			}
			$seen[ $id ] = true;
			$out[]       = $id;
		}

		return $out;
	}

	/** @param mixed $value */
	private function canonical_positive_integer( $value ): ?int {
		$integer = $this->canonical_nonnegative_integer( $value );
		return null !== $integer && $integer > 0 ? $integer : null;
	}

	/** @param mixed $value */
	private function canonical_nonnegative_integer( $value ): ?int {
		if ( is_int( $value ) ) {
			return $value >= 0 ? $value : null;
		}
		if ( ! is_string( $value ) || ! preg_match( '/^(?:0|[1-9][0-9]*)$/D', $value ) ) {
			return null;
		}
		$integer = (int) $value;
		return (string) $integer === $value ? $integer : null;
	}

	/** @param mixed $stamp @return array{count: int, max: string}|null */
	private function validated_cache_stamp( $stamp ): ?array {
		if ( ! is_object( $stamp ) ) {
			return null;
		}
		$values = get_object_vars( $stamp );
		if ( ! $this->has_exact_keys( $values, array( 'c', 'm' ) ) ) {
			return null;
		}
		$count = $this->canonical_nonnegative_integer( $values['c'] );
		$max   = $values['m'];
		if ( null === $count || ! is_string( $max ) || ! $this->valid_database_datetime( $max ) ) {
			return null;
		}

		return array( 'count' => $count, 'max' => $max );
	}

	private function valid_database_datetime( string $value ): bool {
		if ( '0' === $value ) {
			return true;
		}
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2}) ([01]\d|2[0-3]):([0-5]\d):([0-5]\d)$/D', $value, $matches ) ) {
			return false;
		}

		return checkdate( (int) $matches[2], (int) $matches[3], (int) $matches[1] );
	}

	/**
	 * Un transient no es autoridad: una forma inválida es cache miss, no 503.
	 * Las fuentes que definen la forma esperada sí conservan su gate sticky.
	 *
	 * @param array<string|int, mixed> $cached
	 * @return array<string, array<string, array<string, mixed>>>|null
	 */
	private function validated_cached_counts( array $cached ): ?array {
		$definitions = $this->facet_definitions();
		if ( ! $this->available ) {
			return null;
		}
		$expected = array();
		foreach ( $definitions as $facet => $definition ) {
			$type = (string) $definition['type'];
			if ( 'precio' === $type && ! $this->has_price_buckets() ) {
				if ( ! $this->available ) {
					return null;
				}
				continue;
			}
			if ( 'novedad' === $type && ! $this->novelty_enabled() ) {
				if ( ! $this->available ) {
					return null;
				}
				continue;
			}
			$expected[ $facet ] = $type;
		}
		if ( ! $this->has_exact_keys( $cached, array_keys( $expected ) ) ) {
			return null;
		}

		foreach ( $expected as $facet => $type ) {
			$options = $cached[ $facet ];
			if ( ! is_array( $options ) || count( $options ) > 10000 ) {
				return null;
			}
			$expected_options = null;
			if ( 'precio' === $type ) {
				$expected_options = $this->price_buckets();
			} elseif ( 'novedad' === $type ) {
				$expected_options = $this->novelty_buckets();
			}
			if ( ! $this->available ) {
				return null;
			}
			if ( null !== $expected_options && count( $options ) !== count( $expected_options ) ) {
				return null;
			}

			foreach ( $options as $key => $option ) {
				if ( ! is_array( $option ) || ! $this->has_exact_keys( $option, array( 'value', 'label', 'count', 'selected' ) )
					|| ! $this->valid_facet_value( $option['value'] ) || (string) $key !== $option['value']
					|| ! $this->valid_facet_label( $option['label'] )
					|| null === $this->canonical_nonnegative_integer( $option['count'] )
					|| false !== $option['selected'] ) {
					return null;
				}
				if ( null !== $expected_options ) {
					$index = $this->canonical_nonnegative_integer( $option['value'] );
					if ( null === $index || ! isset( $expected_options[ $index ] )
						|| (string) $expected_options[ $index ]['label'] !== $option['label'] ) {
						return null;
					}
				}
			}
		}

		return $cached;
	}

	/** @param mixed $row @return array<int, int>|null */
	private function validated_bucket_count_row( $row, int $bucket_count ): ?array {
		if ( ! is_array( $row ) || $bucket_count <= 0 ) {
			return null;
		}
		$keys = array();
		for ( $i = 0; $i < $bucket_count; ++$i ) {
			$keys[] = 'b' . $i;
		}
		if ( ! $this->has_exact_keys( $row, $keys ) ) {
			return null;
		}
		$out = array();
		foreach ( $keys as $key ) {
			$count = $this->canonical_nonnegative_integer( $row[ $key ] );
			if ( null === $count ) {
				return null;
			}
			$out[] = $count;
		}

		return $out;
	}

	/** @param array<int, mixed> $selected @return array<string, int>|null */
	private function selected_value_map( array $selected ): ?array {
		if ( ! $this->is_list( $selected ) || count( $selected ) > 200 ) {
			return null;
		}
		$out = array();
		foreach ( $selected as $index => $value ) {
			if ( ! $this->valid_facet_value( $value ) || isset( $out[ $value ] ) ) {
				return null;
			}
			$out[ $value ] = $index;
		}

		return $out;
	}

	/** @param mixed $value */
	private function valid_facet_value( $value ): bool {
		return is_string( $value ) && '' !== $value && $value === trim( $value ) && strlen( $value ) <= 200
			&& ! preg_match( '/[\x00-\x1F\x7F]/', $value );
	}

	/** @param mixed $label */
	private function valid_facet_label( $label ): bool {
		return is_string( $label ) && '' !== trim( $label ) && strlen( $label ) <= 500
			&& ! preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $label );
	}

	/** @param array<int|string, mixed> $value @param array<int, string> $expected */
	private function has_exact_keys( array $value, array $expected ): bool {
		$actual = array_keys( $value );
		sort( $actual );
		sort( $expected );

		return $actual === $expected;
	}

	/** @param array<int|string, mixed> $value */
	private function is_list( array $value ): bool {
		return array_keys( $value ) === ( empty( $value ) ? array() : range( 0, count( $value ) - 1 ) );
	}

	/** Limpia last_error inmediatamente antes de cada lectura SQL. */
	private function begin_read(): void {
		if ( property_exists( $this->db, 'last_error' ) ) {
			$this->db->last_error = '';
		}
	}

	/** Indica si la última lectura dejó error explícito en wpdb. */
	private function read_has_error(): bool {
		if ( ! property_exists( $this->db, 'last_error' ) ) {
			return false;
		}
		$value = $this->db->last_error;
		if ( null === $value || false === $value || '' === $value ) {
			return false;
		}

		return ! is_string( $value ) || '' !== trim( $value );
	}

	/**
	 * Un conteo de docs visibles memoizado antes del cierre ya no es
	 * autoritativo: se olvida con el resto del estado derivado.
	 *
	 * @return void
	 */
	private function forget_derived_state(): void {
		$this->visible_docs_memo = null;
	}
}
