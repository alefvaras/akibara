<?php
/**
 * QueryEngine — resuelve una búsqueda contra el índice propio (fase motor).
 *
 * Pipeline:
 *   1. Normalizar → tokens (Normalizer).
 *   2. SKU/ISBN fast-path: si la query cruda es un código, match exacto directo.
 *   3. Sinónimos: reescrituras de términos completos (OR entre frases, AND
 *      dentro de cada frase) con un tope combinatorio estricto.
 *   4. Candidatos: AND de tokens. Cada token JOIN a la tabla de tokens —
 *      alfabético = prefijo (`LIKE 'tok%'` sobre el PK utf8mb4_bin), numérico
 *      = igualdad con variantes canónicas. En modo `suggest`, el ÚLTIMO token
 *      matchea por prefijo aunque sea numérico (permite tipeo incremental
 *      sin colapso prematuro al volumen parcial).
 *   5. Cargar los docs de los candidatos y puntuarlos contra la mejor
 *      alternativa en PHP (Scorer).
 *   6. Ordenar por Scorer::compare_scored y aplicar el límite pedido por el
 *      caller. La búsqueda de catálogo no se capea: WooCommerce necesita el
 *      universo completo para que found_posts y la paginación sean honestos.
 *
 * Sin FULLTEXT: LIKE de prefijo sobre el PK de tokens usa índice (verificado
 * por EXPLAIN: type=range/ref, sin full scan). `esc_like` obligatorio.
 *
 * @package Akibara\Search
 */

declare( strict_types = 1 );

namespace Akibara\Search;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.55.0
 */
final class QueryEngine {

	use Support\FailsClosed;

	/** `0` significa todos los resultados; se conserva el nombre por compatibilidad. */
	public const MAX_RESULTS = 0;

	/** Tope de longitud de la consulta en cualquier superficie, no solo REST. */
	public const MAX_QUERY_LENGTH = 128;

	/** Tope de JOINs de tokens para proteger MariaDB y mantener planes acotados. */
	public const MAX_QUERY_TOKENS = 10;

	/** Largo mínimo de token para disparar búsqueda (paridad live search). */
	public const MIN_TOKEN_LENGTH = 2;

	/**
	 * Fila única del vocabulario de títulos del fuzzy. El prefijo `akb_vocab_`
	 * se conserva porque uninstall.php barre por prefijo.
	 */
	public const VOCAB_TRANSIENT = 'akb_vocab_titles';

	/** @var \wpdb */
	private $db;

	/** @var array<int, string>|null Vocabulario de tokens de título (memo por request), para el fuzzy. */
	private ?array $title_vocab = null;

	/**
	 * Términos de editorial omitidos para relajar la búsqueda y evitar resultados vacíos.
	 * El suggest lo retorna atómicamente y el catálogo lo expone vía BrandRelax.
	 *
	 * @var array{ignored: array<int, string>, kept: string}|null
	 */
	private ?array $last_suggest_relaxed = null;

	/**
	 * Corrección capturada por suggest() en la MISMA llamada (spec v5 D3): el
	 * retorno de suggest() la trae atómicamente, sin leer el side-channel
	 * estático de Fuzzy (que sigue siendo SOLO del catálogo).
	 *
	 * @var array{typo: string, fix: string}|null
	 */
	private ?array $last_suggest_correction = null;

	/** @var string|null Query normalizada del último suggest exacto reutilizable. */
	private ?string $last_suggest_exact_query = null;

	/** @var array<int, array<string, mixed>>|null Docs ordenados completos del último suggest exacto. */
	private ?array $last_suggest_exact_docs = null;

	/**
	 * @param \wpdb $db WordPress DB.
	 */
	public function __construct( $db ) {
		$this->db = $db;
	}

	/**
	 * Ejecuta la búsqueda y devuelve post_ids rankeados.
	 *
	 * @param string $raw         Query cruda del usuario.
	 * @param bool   $suggest     Modo autocomplete (último token por prefijo).
	 * @param int    $limit       Tope de resultados.
	 * @param bool   $allow_fuzzy Si el fallback fuzzy puede correr (default true). @since 0.2.0
	 * @return array<int, int> post_ids en orden de relevancia.
	 */
	public function search( string $raw, bool $suggest = false, int $limit = self::MAX_RESULTS, bool $allow_fuzzy = true ): array {
		$scored = $this->search_scored( $raw, $suggest, $limit, $allow_fuzzy );
		return array_map( static fn( array $r ): int => (int) $r['post_id'], $scored );
	}

	/**
	 * Autocomplete ATÓMICO (spec v5 D3): busca en modo suggest y devuelve la
	 * corrección fuzzy (política ESTRICTA) COMO PARTE del retorno — sin leer
	 * un side-channel estático que un caller intermedio podría pisar. `fix` es
	 * la query corregida COMPLETA (todos los tokens corregidos), `typo` la
	 * query cruda tal como se recibió. `corrected` es null sin corrección.
	 *
	 * @since 0.2.0
	 *
	 * @param string $raw   Query cruda del usuario.
	 * @param int    $limit Tope de resultados.
	 * @return array{ids: array<int, int>, corrected: array{typo: string, fix: string}|null}
	 */
	public function suggest( string $raw, int $limit = self::MAX_RESULTS ): array {
		$this->last_suggest_correction  = null;
		$this->last_suggest_relaxed     = null;
		$this->last_suggest_exact_query = null;
		$this->last_suggest_exact_docs  = null;
		$ids                            = $this->search( $raw, true, $limit );
		return array(
			'ids'       => $ids,
			'corrected' => $this->last_suggest_correction,
			'relaxed'   => $this->last_suggest_relaxed,
		);
	}

	/**
	 * Genera los intentos de relajación de términos de editorial, del más conservador al más amplio:
	 * primero términos individuales en orden inverso y finalmente todos en conjunto.
	 *
	 * @param array<int, string> $marcas Palabras de editorial de la consulta.
	 * @return array<int, array<int, string>> Intentos, del más conservador al más amplio.
	 */
	private function relax_attempts( array $marcas ): array {
		if ( array() === $marcas ) {
			return array();
		}
		if ( 1 === count( $marcas ) ) {
			return array( $marcas );
		}

		$intentos = array();
		foreach ( array_reverse( $marcas ) as $marca ) {
			$intentos[] = array( $marca );
		}
		$intentos[] = $marcas;

		return $intentos;
	}

	/**
	 * Query corregida COMPLETA para mostrarle al usuario: recorre el stream
	 * normalizado ORIGINAL y reemplaza SOLO los tokens que el map corrigió,
	 * dejando en su lugar los que se teclearon bien — incluidas las stopwords,
	 * que `query_tokens()` filtra para el matching pero que el usuario SÍ
	 * escribió. Sin esto, "code geass leloush el de la rebelion" + {leloush:
	 * lelouch} perdía "el/de/la" y el copy/footer/H1 mostraban una query
	 * truncada, preservando la redacción completa para el cliente.
	 *
	 * @since 0.2.0
	 *
	 * @param string                $raw Query cruda (ya trim/cap).
	 * @param array<string, string> $map Correcciones token→token (typo→fix).
	 * @return string
	 */
	private function full_corrected_query( string $raw, array $map ): string {
		$out = array();
		foreach ( explode( ' ', Normalizer::normalize( $raw ) ) as $token ) {
			if ( '' === $token ) {
				continue;
			}
			$out[] = $map[ $token ] ?? $token;
		}
		return implode( ' ', $out );
	}

	/**
	 * Igual que search() pero devuelve las filas con su `_score` (para tests,
	 * debugging y el shadow-diff contra Relevanssi).
	 *
	 * @param string $raw         Query cruda.
	 * @param bool   $suggest     Modo autocomplete.
	 * @param int    $limit       Tope.
	 * @param bool   $allow_fuzzy Si el fallback fuzzy puede correr (default true).
	 *                            `false` fuerza cero fuzzy aunque el flag global
	 *                            esté ON — usado para el total sobre una query
	 *                            YA corregida, donde re-disparar la laxa sobre
	 *                            un token residual sería un segundo fuzzing
	 *                            silencioso [spec v5 D3, contrato §5.9].
	 * @return array<int, array<string, mixed>> Docs con `_score`, ordenados.
	 */
	public function search_scored( string $raw, bool $suggest = false, int $limit = self::MAX_RESULTS, bool $allow_fuzzy = true ): array {
		if ( ! $this->available ) {
			return array();
		}

		$raw        = mb_substr( trim( $raw ), 0, self::MAX_QUERY_LENGTH );
		$query_norm = Normalizer::normalize( $raw );
		if ( '' === $query_norm ) {
			return array();
		}

		$tokens = array_slice( Normalizer::tokenize( $raw ), 0, self::MAX_QUERY_TOKENS );
		if ( empty( $tokens ) ) {
			return array();
		}

		// Fast-path SKU/ISBN: si la query (sin separadores) es un código de
		// ≥6 dígitos o calza un SKU exacto, resolvemos directo — devolvemos la
		// fila del doc (misma forma que el resto) con score tope.
		$sku_enabled = 'yes' === SemanticPublication::authoritative_value( 'akb_search_sku_fastpath', 'yes' );
		if ( ! SemanticPublication::authoritative_available() ) {
			$this->mark_unavailable( SemanticPublication::authoritative_error() );
			return array();
		}
		$sku_hit = $sku_enabled ? $this->sku_fast_path( $raw ) : null;
		if ( ! $this->available ) {
			return array();
		}
		if ( null !== $sku_hit ) {
			$doc = $this->load_docs( array( $sku_hit ) );
			if ( ! $this->available ) {
				return array();
			}
			if ( ! empty( $doc ) ) {
				$doc[0]['_score'] = Scorer::TIER_TITLE_PREFIX + self::MAX_RESULTS;
				return $doc;
			}
		}

		// El LIMIT pertenece al resultado RANKEADO, no al SELECT de candidatos.
		// Aplicarlo antes del score hacía que una búsqueda amplia devolviera un
		// subconjunto arbitrario y truncara found_posts/paginación a 500.
		if ( ! $suggest ) {
			Fuzzy::flush();
			BrandRelax::flush();
			if ( ! $allow_fuzzy && null !== $this->last_suggest_exact_docs && $this->last_suggest_exact_query === $query_norm ) {
				$cached                         = $this->last_suggest_exact_docs;
				$this->last_suggest_exact_docs  = null;
				$this->last_suggest_exact_query = null;
				return $limit > 0 ? array_slice( $cached, 0, $limit ) : $cached;
			}
		}

		$query_tokens       = $this->query_tokens( $raw );
		$query_sequence     = $this->query_token_sequence( $raw );
		$query_segments     = Synonyms::query_segments( $query_sequence );
		$query_alternatives = $this->scoring_alternatives( $query_norm, $tokens, $query_sequence );
		if ( ! SemanticPublication::authoritative_available() ) {
			$this->mark_unavailable( SemanticPublication::authoritative_error() );
			return array();
		}
		$candidate_ids      = $this->segment_candidates( $query_segments, $suggest );
		if ( ! $this->available ) {
			return array();
		}

		// Relajación de editorial: ante cero candidatos, descarta términos de marca/editorial
		// antes de evaluar fuzzy, priorizando encontrar la obra si existe en otra editorial.
		if ( empty( $candidate_ids ) && $allow_fuzzy && count( $query_tokens ) >= 2 ) {
			$marcas      = BrandTokens::found_in( $query_tokens, $this->db );
			$mejor_ids   = array();
			$mejor_deja  = array();
			$mejor_sobra = array();
			foreach ( $this->relax_attempts( $marcas ) as $sobran ) {
				$quedan = array_values( array_diff( $query_tokens, $sobran ) );
				if ( array() === $quedan ) {
					continue;
				}
				$relax_segments = Synonyms::query_segments( $quedan );
				$relax_ids      = $this->segment_candidates( $relax_segments, $suggest );
				if ( ! $this->available ) {
					return array();
				}
				// Gana el intento con MÁS resultados; en empate, el que soltó
				// menos palabras (los intentos vienen del más conservador al más
				// amplio, así que basta con exigir estrictamente más).
				if ( count( $relax_ids ) > count( $mejor_ids ) ) {
					$mejor_ids   = $relax_ids;
					$mejor_deja  = $quedan;
					$mejor_sobra = $sobran;
				}
			}
			if ( array() !== $mejor_ids ) {
				$candidate_ids = $mejor_ids;
				// Rescoring contra lo que queda: el ranking tiene que mirar el
				// título pedido, no el nombre de la editorial soltada.
				$relax_query        = implode( ' ', $mejor_deja );
				$query_norm         = Normalizer::normalize( $relax_query );
				$tokens             = array_slice( Normalizer::tokenize( $relax_query ), 0, self::MAX_QUERY_TOKENS );
				$query_alternatives = $this->scoring_alternatives( $query_norm, $tokens, $mejor_deja );
				$aviso              = array( 'ignored' => $mejor_sobra, 'kept' => $relax_query );
				if ( $suggest ) {
					$this->last_suggest_relaxed = $aviso;
				} else {
					BrandRelax::note( $aviso );
				}
			}
		}

		// Fuzzy fallback: con CERO candidatos, corrige typos contra el
		// vocabulario de títulos y reintenta UNA vez ("jujustu"→"jujutsu").
		// Detrás del flag akb_search_fuzzy y fuera del hot path (solo corre sin
		// candidatos). Política por modo (spec v5): catálogo = LAXA (el vecino
		// más cercano dentro del umbral); suggest = ESTRICTA (nearest_strict —
		// ante ambigüedad calla, no elige) [D1/D2]. Desde 0.1.73 las dos miden
		// con la misma distancia OSA: la laxa medía con levenshtein(), que cobra
		// 2 por dos letras adyacentes cambiadas, y era MENOS capaz que la
		// estricta («balck clover»: desplegable sí, página no —
		// R9-corrector-asimetrico). `$allow_fuzzy=false` apaga esto entero
		// (usado para el total sobre una query ya corregida, §5.9).
		//
		// Salida temprana si no hay tokens procesables, evitando consultar el vocabulario innecesariamente.
		$fuzzy_enabled = Fuzzy::enabled();
		if ( ! SemanticPublication::authoritative_available() ) {
			$this->mark_unavailable( SemanticPublication::authoritative_error() );
			return array();
		}
		if ( empty( $candidate_ids ) && ! empty( $query_tokens ) && $allow_fuzzy && $fuzzy_enabled ) {
			$vocabulary = $this->title_vocabulary();
			if ( ! $this->available ) {
				return array();
			}
			$fix = Fuzzy::correct( $query_tokens, $vocabulary, $suggest );
			if ( $fix['changed'] ) {
				$corrected          = implode( ' ', $fix['tokens'] );
				$corrected_norm     = Normalizer::normalize( $corrected );
				$corrected_tokens   = array_slice( Normalizer::tokenize( $corrected ), 0, self::MAX_QUERY_TOKENS );
				$retry_segments     = Synonyms::query_segments( $fix['tokens'] );
				$retry_alternatives = $this->scoring_alternatives( $corrected_norm, $corrected_tokens, $fix['tokens'] );
				$retry_ids          = $this->segment_candidates( $retry_segments, $suggest );
				if ( ! $this->available ) {
					return array();
				}
				if ( ! empty( $retry_ids ) ) {
					$candidate_ids      = $retry_ids;
					$query_alternatives = $retry_alternatives;
					// Rescoring con la query CORREGIDA: el Scorer debe rankear
					// contra el término real, no contra el typo (que no calza en
					// el título y dejaría el resultado correcto en tier residual).
					// `$corrected` va SIN stopwords a propósito (query_tokens las
					// filtra) — es correcto para el matching, NO para mostrar.
					$query_norm = Normalizer::normalize( $corrected );
					$tokens     = array_slice( Normalizer::tokenize( $corrected ), 0, self::MAX_QUERY_TOKENS );

					// Side-channel estático SOLO para el catálogo (D3); el
					// suggest devuelve su corrección atómicamente en suggest().
					// `fix` = query COMPLETA (con stopwords), no el subconjunto
					// filtrado del rescoring interno.
					if ( $suggest ) {
						$this->last_suggest_correction = array(
							'typo' => $raw,
							'fix'  => $this->full_corrected_query( $raw, $fix['map'] ),
						);
					} else {
						Fuzzy::note_correction( $fix['map'] );
					}
				}
			}
		}

		if ( empty( $candidate_ids ) ) {
			return array();
		}

		$docs = $this->load_docs( $candidate_ids );
		if ( ! $this->available ) {
			return array();
		}

		foreach ( $docs as &$doc ) {
			$best_score = null;
			foreach ( $query_alternatives as $alternative ) {
				$score      = Scorer::score( $doc, $alternative['query'], $alternative['score_tokens'] );
				$best_score = null === $best_score ? $score : max( $best_score, $score );
			}
			$doc['_score'] = $best_score ?? Scorer::TIER_RESIDUAL;
		}
		unset( $doc );

		usort( $docs, array( Scorer::class, 'compare_scored' ) );

		if (
			$suggest
			&& null === $this->last_suggest_correction
			&& null === $this->last_suggest_relaxed
			&& ! empty( $query_sequence )
			&& ! ctype_digit( (string) end( $query_sequence ) )
		) {
			$this->last_suggest_exact_query = $query_norm;
			$this->last_suggest_exact_docs  = $docs;
		}

		return $limit > 0 ? array_slice( $docs, 0, $limit ) : $docs;
	}

	/**
	 * Tokens "de query" — SIN la expansión de variantes numéricas del índice.
	 * Para el WHERE, cada token numérico se expande a sus variantes vía IN;
	 * los alfabéticos van por prefijo. Devolvemos el token tal cual lo tipeó
	 * el usuario (normalizado), preservando el ORDEN (importa el "último
	 * token" del modo suggest).
	 *
	 * @param string $raw Query cruda.
	 * @return array<int, string>
	 */
	private function query_tokens( string $raw ): array {
		return array_values( array_unique( $this->query_token_sequence( $raw ) ) );
	}

	/**
	 * Stream significativo de la query, preservando orden y duplicados. Los
	 * límites de frase dependen de distinguir "hunter x hunter" de "hunter";
	 * candidates() deduplica después para no crear JOINs redundantes.
	 *
	 * @param string $raw Query cruda.
	 * @return array<int, string>
	 */
	private function query_token_sequence( string $raw ): array {
		$out      = array();
		$fallback = array();
		foreach ( explode( ' ', Normalizer::normalize( $raw ) ) as $token ) {
			if ( '' === $token ) {
				continue;
			}
			// Descartar tokens de 1 char SALVO que sean dígitos (tomo "1", "3").
			if ( strlen( $token ) < self::MIN_TOKEN_LENGTH && ! ctype_digit( $token ) ) {
				continue;
			}
			$fallback[] = $token;
			// Stopwords: se ignoran (no aportan señal y ensanchan el match). Los
			// dígitos nunca son stopword (el número de tomo es señal fuerte).
			if ( ! ctype_digit( $token ) && Stopwords::is_stopword( $token ) ) {
				continue;
			}
			$out[] = $token;
			if ( count( $out ) >= self::MAX_QUERY_TOKENS ) {
				break;
			}
		}
		// Si TODA la query eran stopwords, no la dejamos vacía: buscar algo > nada.
		$tokens = empty( $out ) ? $fallback : $out;
		return array_slice( $tokens, 0, self::MAX_QUERY_TOKENS );
	}

	/**
	 * Alternativas completas para matching y scoring. La primera conserva la
	 * query exacta del usuario; los rewrites usan la frase configurada.
	 *
	 * @param string             $query_norm Query normalizada original/corregida.
	 * @param array<int, string> $score_tokens Tokens de scoring originales.
	 * @param array<int, string> $query_sequence Stream significativo.
	 * @return array<int, array{tokens: array<int, string>, query: string, score_tokens: array<int, string>}>
	 */
	private function scoring_alternatives( string $query_norm, array $score_tokens, array $query_sequence ): array {
		$rewrites = Synonyms::query_alternatives( $query_sequence );
		if ( empty( $rewrites ) ) {
			$rewrites[] = array(
				'tokens' => $query_sequence,
				'query'  => $query_norm,
			);
		}

		$out = array();
		foreach ( $rewrites as $i => $rewrite ) {
			$query = 0 === $i ? $query_norm : Normalizer::normalize( $rewrite['query'] );
			$out[] = array(
				'tokens'       => $rewrite['tokens'],
				'query'        => $query,
				'score_tokens' => 0 === $i
					? $score_tokens
					: array_slice( Normalizer::tokenize( $query ), 0, self::MAX_QUERY_TOKENS ),
			);
		}

		return $out;
	}

	/**
	 * Candidatos de segmentos phrase-aware. Sin sinónimos conserva el JOIN
	 * original; con alternativas usa una sola consulta factorizada.
	 *
	 * @param array<int, array<int, array{tokens: array<int, string>}>> $segments Cláusulas AND con términos OR.
	 * @param bool                                                        $suggest Modo autocomplete.
	 * @return array<int, int>
	 */
	private function segment_candidates( array $segments, bool $suggest ): array {
		if ( empty( $segments ) ) {
			return array();
		}

		$tokens       = array();
		$has_synonyms = false;
		foreach ( $segments as $choices ) {
			if ( count( $choices ) > 1 ) {
				$has_synonyms = true;
			}
			$tokens = array_merge( $tokens, $choices[0]['tokens'] ?? array() );
		}

		return $has_synonyms
			? $this->synonym_candidates( $segments, $suggest )
			: $this->candidates( $tokens, $suggest );
	}

	/**
	 * Evalúa `(frase A OR frase B) AND token externo` como un derived set por
	 * segmento. Cada alternativa expone un rango selectivo de la tabla de tokens
	 * y los segmentos se intersectan por post_id; así se conserva la
	 * factorización sin ejecutar el producto cartesiano como consultas
	 * independientes. El optimizador puede reordenar los derived sets y docs; el
	 * gate de rendimiento cubre el plan efectivo del catálogo.
	 *
	 * @param array<int, array<int, array{tokens: array<int, string>}>> $segments Cláusulas.
	 * @param bool                                                        $suggest Modo autocomplete.
	 * @return array<int, int>
	 */
	private function synonym_candidates( array $segments, bool $suggest ): array {
		if ( ! $this->available ) {
			return array();
		}
		$docs_table   = Install::docs_table();
		$tokens_table = Install::tokens_table();
		$derived_sql  = array();
		$args         = array();
		$last_segment = count( $segments ) - 1;

		foreach ( $segments as $segment_i => $choices ) {
			$choice_queries = array();
			foreach ( $choices as $choice_i => $choice ) {
				$choice_tokens = array_values( array_unique( array_map( 'strval', $choice['tokens'] ?? array() ) ) );
				if ( empty( $choice_tokens ) ) {
					continue;
				}

				$joins      = array();
				$where      = '';
				$join_args  = array();
				$where_args = array();
				$last_token = count( $choice_tokens ) - 1;
				foreach ( $choice_tokens as $token_i => $token ) {
					$alias     = 's' . $segment_i . 'c' . $choice_i . 't' . $token_i;
					$is_last   = $segment_i === $last_segment && $token_i === $last_token;
					$as_prefix = ! ctype_digit( $token ) || ( $suggest && $is_last );

					if ( $as_prefix ) {
						$predicate     = "{$alias}.token LIKE %s";
						$escaped = $this->guarded_call( fn() => $this->db->esc_like( $token ), 'Could not escape synonym candidate.' );
						if ( ! $this->available || ! is_string( $escaped ) ) {
							return array();
						}
						$predicate_args = array( $escaped . '%' );
					} else {
						$variants     = array_values( array_unique( array_merge( array( $token ), Normalizer::numeric_variants( $token ) ) ) );
						$placeholders = implode( ', ', array_fill( 0, count( $variants ), '%s' ) );
						$predicate    = "{$alias}.token IN ({$placeholders})";
						$predicate_args = $variants;
					}

					if ( 0 === $token_i ) {
						$where      = $predicate;
						$where_args = $predicate_args;
					} else {
						$joins[] = "JOIN {$tokens_table} {$alias}
							ON {$alias}.post_id = s{$segment_i}c{$choice_i}t0.post_id
							AND {$predicate}";
						$join_args = array_merge( $join_args, $predicate_args );
					}
				}

				$base_alias       = 's' . $segment_i . 'c' . $choice_i . 't0';
				$choice_queries[] = "SELECT DISTINCT {$base_alias}.post_id
					FROM {$tokens_table} {$base_alias}
					" . implode( ' ', $joins ) . "
					WHERE {$where}";
				$args             = array_merge( $args, $join_args, $where_args );
			}

			if ( empty( $choice_queries ) ) {
				return array();
			}
			$derived_sql[] = '( ' . implode( ' UNION ', $choice_queries ) . ' ) q' . $segment_i;
		}

		$base = array_shift( $derived_sql );
		$sql  = "SELECT DISTINCT d.post_id
			FROM {$base}
			JOIN {$docs_table} d ON d.post_id = q0.post_id AND d.visible = 1";
		foreach ( $derived_sql as $segment_i => $derived ) {
			$alias = 'q' . ( $segment_i + 1 );
			$sql  .= " JOIN {$derived} ON {$alias}.post_id = q0.post_id";
		}

		$prepared = empty( $args )
			? $sql
			: $this->guarded_call( fn() => $this->db->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- tablas propias y placeholders construidos.
				$sql,
				...$args
			), 'Could not prepare synonym candidate read.' );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- preparado arriba.
		if ( ! is_string( $prepared ) ) {
			$this->mark_unavailable( 'Could not prepare synonym candidate read.' );
			return array();
		}

		$ids = $this->guarded_call( fn() => $this->db->get_col( $prepared ), 'Could not read synonym candidates.' );
		if ( ! $this->array_read_succeeded( $ids, 'Could not read synonym candidates.' ) ) {
			return array();
		}
		$normalized = $this->positive_ids( $ids, 'Synonym candidates have an invalid shape.' );
		return null === $normalized ? array() : $normalized;
	}

	/**
	 * AND de tokens sobre la tabla de tokens. Devuelve post_ids visibles.
	 *
	 * @param array<int, string> $tokens  Tokens de query (en orden).
	 * @param bool                $suggest Modo autocomplete.
	 * @return array<int, int>
	 */
	private function candidates( array $tokens, bool $suggest ): array {
		if ( ! $this->available ) {
			return array();
		}
		$tokens = array_slice(
			array_values( array_unique( array_map( 'strval', $tokens ) ) ),
			0,
			self::MAX_QUERY_TOKENS
		);
		if ( empty( $tokens ) ) {
			return array();
		}

		$docs_table   = Install::docs_table();
		$tokens_table = Install::tokens_table();

		$joins  = array();
		$last_i = count( $tokens ) - 1;

		foreach ( $tokens as $i => $token ) {
			$alias      = 't' . $i;
			$is_last    = $i === $last_i;
			$as_prefix  = ! ctype_digit( $token ) || ( $suggest && $is_last );

			if ( $as_prefix ) {
				$escaped = $this->guarded_call( fn() => $this->db->esc_like( $token ), 'Could not escape candidate token.' );
				if ( ! $this->available || ! is_string( $escaped ) ) {
					return array();
				}
				$prepared_join = $this->guarded_call( fn() => $this->db->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- tokens_table() es identificador interno; token preparado con %s.
					"JOIN {$tokens_table} {$alias} ON {$alias}.post_id = d.post_id AND {$alias}.token LIKE %s",
					$escaped . '%'
				), 'Could not prepare candidate read.' );
			} else {
				$variants     = array_merge( array( $token ), Normalizer::numeric_variants( $token ) );
				$placeholders = implode( ', ', array_fill( 0, count( $variants ), '%s' ) );
				$prepared_join = $this->guarded_call( fn() => $this->db->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- tokens_table() es identificador interno; variantes preparadas como argumentos.
					"JOIN {$tokens_table} {$alias} ON {$alias}.post_id = d.post_id AND {$alias}.token IN ({$placeholders})",
					...$variants
				), 'Could not prepare candidate read.' );
			}

			if ( ! is_string( $prepared_join ) ) {
				$this->mark_unavailable( 'Could not prepare candidate read.' );
				return array();
			}
			$joins[] = $prepared_join;
		}

		$sql = "SELECT DISTINCT d.post_id FROM {$docs_table} d "
			. implode( ' ', $joins )
			. ' WHERE d.visible = 1';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- joins ya preparados individualmente.
		$ids = $this->guarded_call( fn() => $this->db->get_col( $sql ), 'Could not read candidates.' );
		if ( ! $this->array_read_succeeded( $ids, 'Could not read candidates.' ) ) {
			return array();
		}
		$normalized = $this->positive_ids( $ids, 'Candidates have an invalid shape.' );
		return null === $normalized ? array() : $normalized;
	}

	/**
	 * Vocabulario de corrección fuzzy: tokens únicos del campo título.
	 * Memoizado por request y cacheado entre peticiones con clave fija e invalidación
	 * por sello de generación de índice (akb_search_index_gen).
	 *
	 * @return array<int, string>
	 */
	private function title_vocabulary(): array {
		if ( ! $this->available ) {
			return array();
		}
		if ( null !== $this->title_vocab ) {
			return $this->title_vocab;
		}

		$generation = SemanticPublication::authoritative_value( Install::OPTION_INDEX_GEN, '0' );
		if ( ! SemanticPublication::authoritative_available() || ! is_scalar( $generation ) ) {
			$this->mark_unavailable( SemanticPublication::authoritative_error() ?: 'Index generation is not authoritative.' );
			return array();
		}
		$stamp = hash( 'sha256', (string) $generation );
		try {
			$cached = get_transient( self::VOCAB_TRANSIENT );
		} catch ( \Throwable $throwable ) {
			$cached = false; // Optimización no autoritativa: continuar al SQL.
		}
		if ( is_array( $cached )
			&& isset( $cached['stamp'], $cached['data'] )
			&& is_string( $cached['stamp'] )
			&& hash_equals( $stamp, $cached['stamp'] )
			&& is_array( $cached['data'] )
			&& $this->valid_vocabulary( $cached['data'] ) ) {
			$this->title_vocab = array_values( $cached['data'] );
			return $this->title_vocab;
		}

		$tokens_table = Install::tokens_table();
		$prepared     = $this->guarded_call( fn() => $this->db->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Install, no user input.
				"SELECT DISTINCT token FROM {$tokens_table} WHERE field = %d",
				DocumentBuilder::FIELD_TITLE
		), 'Could not prepare title vocabulary read.' );
		if ( ! is_string( $prepared ) ) {
			$this->mark_unavailable( 'Could not prepare title vocabulary read.' );
			return array();
		}

		$rows = $this->guarded_call( fn() => $this->db->get_col( $prepared ), 'Could not read title vocabulary.' );
		if ( ! $this->array_read_succeeded( $rows, 'Could not read title vocabulary.' ) ) {
			return array();
		}
		if ( ! $this->valid_vocabulary( $rows ) ) {
			$this->mark_unavailable( 'Title vocabulary has an invalid shape.' );
			return array();
		}
		$this->title_vocab = array_values( $rows );
		try {
			set_transient(
				self::VOCAB_TRANSIENT,
				array( 'stamp' => $stamp, 'data' => $this->title_vocab ),
				DAY_IN_SECONDS
			);
		} catch ( \Throwable $throwable ) {
			// Cache de vocabulario best-effort: el resultado SQL sigue autorizado.
		}
		return $this->title_vocab;
	}

	/**
	 * SKU/ISBN fast-path. Un código exacto (o su forma solo-dígitos) devuelve
	 * el producto directo, sin pasar por el ranking.
	 *
	 * @param string $raw Query cruda.
	 * @return int|null post_id, o null si no es un código o no calza.
	 */
	private function sku_fast_path( string $raw ): ?int {
		if ( ! $this->available ) {
			return null;
		}
		$candidate = strtolower( trim( $raw ) );
		$digits    = preg_replace( '/[^0-9]/', '', $candidate );

		// Solo intentamos fast-path si parece código: o es alfanumérico sin
		// espacios, o son ≥6 dígitos (ISBN/EAN).
		$looks_code = ( is_string( $digits ) && strlen( $digits ) >= 6 )
			|| ( '' !== $candidate && false === strpos( $candidate, ' ' ) && preg_match( '/\d/', $candidate ) );

		if ( ! $looks_code ) {
			return null;
		}

		$docs_table = Install::docs_table();

		$needles = array( $candidate );
		if ( is_string( $digits ) && '' !== $digits && $digits !== $candidate ) {
			$needles[] = $digits;
		}
		$placeholders = implode( ', ', array_fill( 0, count( $needles ), '%s' ) );

		$prepared = $this->guarded_call( fn() => $this->db->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- docs_table() es identificador seguro interno; valores preparados por %s.
				// sku_norm permite coincidencia exacta por ISBN/SKU sin guiones vía idx_sku_norm.
				"SELECT post_id FROM {$docs_table}
				WHERE visible = 1 AND ( sku IN ({$placeholders}) OR sku_norm = %s )
				LIMIT 1",
				...array_merge( $needles, array( is_string( $digits ) ? $digits : '' ) )
		), 'Could not prepare SKU read.' );
		if ( ! is_string( $prepared ) ) {
			$this->mark_unavailable( 'Could not prepare SKU read.' );
			return null;
		}

		$id = $this->guarded_call( fn() => $this->db->get_var( $prepared ), 'Could not read SKU.' );
		if ( $this->read_has_error() || false === $id ) {
			$this->mark_unavailable( 'Could not read SKU.' );
			return null;
		}

		if ( null === $id ) {
			return null;
		}
		if ( ! is_int( $id ) && ( ! is_string( $id ) || 1 !== preg_match( '/^[1-9]\d*$/D', $id ) ) ) {
			$this->mark_unavailable( 'SKU result has an invalid identifier.' );
			return null;
		}
		$id = (int) $id;
		if ( $id <= 0 ) {
			$this->mark_unavailable( 'SKU result has an invalid identifier.' );
			return null;
		}
		return $id;
	}

	/**
	 * Carga los campos de scoring de un set de post_ids.
	 *
	 * @param array<int, int> $ids post_ids.
	 * @return array<int, array<string, mixed>>
	 */
	private function load_docs( array $ids ): array {
		if ( ! $this->available ) {
			return array();
		}
		if ( empty( $ids ) ) {
			return array();
		}

		$docs_table   = Install::docs_table();
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		$prepared = $this->guarded_call( fn() => $this->db->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- docs_table() es identificador seguro interno; placeholders preparados con %d.
				"SELECT post_id, title_norm, serie_norm, autores_norm, tomo, stock_rank, menu_order
				FROM {$docs_table} WHERE post_id IN ({$placeholders})",
				...$ids
		), 'Could not prepare document read.' );
		if ( ! is_string( $prepared ) ) {
			$this->mark_unavailable( 'Could not prepare document read.' );
			return array();
		}

		$rows = $this->guarded_call( fn() => $this->db->get_results( $prepared, ARRAY_A ), 'Could not read documents.' );
		if ( ! $this->array_read_succeeded( $rows, 'Could not read documents.' ) ) {
			return array();
		}
		$expected = array_values( array_unique( array_map( 'intval', $ids ), SORT_NUMERIC ) );
		$seen     = array();
		$keys     = array( 'post_id', 'title_norm', 'serie_norm', 'autores_norm', 'tomo', 'stock_rank', 'menu_order' );
		sort( $keys, SORT_STRING );
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				$this->mark_unavailable( 'Document row has an invalid shape.' );
				return array();
			}
			$row_keys = array_keys( $row );
			sort( $row_keys, SORT_STRING );
			if ( $row_keys !== $keys || ! $this->positive_integer_scalar( $row['post_id'] )
				|| ! is_string( $row['title_norm'] ) || ! is_string( $row['serie_norm'] ) || ! is_string( $row['autores_norm'] )
				|| ( null !== $row['tomo'] && ! $this->nonnegative_integer_scalar( $row['tomo'] ) )
				|| ! $this->nonnegative_integer_scalar( $row['stock_rank'] ) || (int) $row['stock_rank'] > 2
				|| ! $this->integer_scalar( $row['menu_order'] ) ) {
				$this->mark_unavailable( 'Document row contains invalid values.' );
				return array();
			}
			$post_id = (int) $row['post_id'];
			if ( isset( $seen[ $post_id ] ) || ! in_array( $post_id, $expected, true ) ) {
				$this->mark_unavailable( 'Document row identity is duplicated or unexpected.' );
				return array();
			}
			$seen[ $post_id ] = true;
		}
		if ( count( $seen ) !== count( $expected ) ) {
			$this->mark_unavailable( 'Document row coverage is incomplete.' );
			return array();
		}

		return $rows;
	}

	/** Limpia last_error inmediatamente antes de cada lectura SQL. */
	private function begin_read(): void {
		if ( property_exists( $this->db, 'last_error' ) ) {
			$this->db->last_error = '';
		}
	}

	/** @return mixed */
	private function guarded_call( callable $reader, string $fallback ) {
		if ( ! $this->available ) {
			return null;
		}
		$this->begin_read();
		try {
			$value = $reader();
		} catch ( \Throwable $throwable ) {
			$this->mark_unavailable( $throwable->getMessage() ?: $fallback );
			return null;
		}
		if ( $this->read_has_error() ) {
			$this->mark_unavailable( $fallback );
			return null;
		}
		return $value;
	}

	/** @param array<int, mixed> $values @return array<int, int>|null */
	private function positive_ids( array $values, string $fallback ): ?array {
		$out = array();
		foreach ( $values as $value ) {
			if ( ! $this->positive_integer_scalar( $value ) ) {
				$this->mark_unavailable( $fallback );
				return null;
			}
			$out[] = (int) $value;
		}
		return array_values( array_unique( $out, SORT_NUMERIC ) );
	}

	/** @param array<int, mixed> $values */
	private function valid_vocabulary( array $values ): bool {
		$seen = array();
		foreach ( $values as $value ) {
			if ( ! is_string( $value ) || '' === $value || strlen( $value ) > Normalizer::MAX_TOKEN_LENGTH
				|| Normalizer::normalize( $value ) !== $value || false !== strpos( $value, ' ' ) || isset( $seen[ $value ] ) ) {
				return false;
			}
			$seen[ $value ] = true;
		}
		return true;
	}

	/** @param mixed $value */
	private function integer_scalar( $value ): bool {
		return is_int( $value ) || ( is_string( $value ) && 1 === preg_match( '/^-?\d+$/D', $value ) );
	}

	/** @param mixed $value */
	private function nonnegative_integer_scalar( $value ): bool {
		return $this->integer_scalar( $value ) && (int) $value >= 0;
	}

	/** @param mixed $value */
	private function positive_integer_scalar( $value ): bool {
		return $this->integer_scalar( $value ) && (int) $value > 0 && 0 !== preg_match( '/^[1-9]\d*$/D', (string) $value );
	}

	/**
	 * Valida una lectura cuyo contrato exitoso es un array, incluido el vacío real.
	 *
	 * @param mixed  $result   Resultado de wpdb.
	 * @param string $fallback Diagnóstico si wpdb no entregó uno.
	 * @return bool
	 */
	private function array_read_succeeded( $result, string $fallback ): bool {
		if ( is_array( $result ) && ! $this->read_has_error() ) {
			return true;
		}

		$this->mark_unavailable( $fallback );
		return false;
	}

	/** Indica si la última lectura dejó error explícito en wpdb. */
	private function read_has_error(): bool {
		return property_exists( $this->db, 'last_error' )
			&& '' !== trim( (string) $this->db->last_error );
	}

	/**
	 * Vocabulario, corrección de la llamada y estado del fuzzy: todo derivado de
	 * lecturas que acaban de dejar de ser autoritativas.
	 *
	 * @return void
	 */
	private function forget_derived_state(): void {
		$this->title_vocab              = null;
		$this->last_suggest_correction  = null;
		$this->last_suggest_exact_query = null;
		$this->last_suggest_exact_docs  = null;
		Fuzzy::flush();
	}
}
