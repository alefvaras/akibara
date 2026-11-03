<?php
/**
 * HealthReport — veredicto de salud accionable del buscador.
 *
 * Dos mitades, patrón Scorer:
 *   - collect(): junta los insumos (WP/DB) en un array plano.
 *   - verdict(): lógica pura insumos → veredicto, cubierta con tests unitarios.
 *
 * @package Akibara\Search
 */

declare( strict_types = 1 );

namespace Akibara\Search;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.59.0
 */
final class HealthReport {

	public const SANO           = 'sano';
	public const ATENCION       = 'atencion';
	public const RECONSTRUYENDO = 'reconstruyendo';

	/** Reconciler sin latido hace más de esto = atención. */
	public const HEARTBEAT_SECONDS = 172800; // 48 h.

	/** Rebuild "en curso" sin avance hace más de esto = la cola no avanza. */
	public const QUEUE_DEAD_SECONDS = 1800; // 30 min (gate 2ª pasada, nota 3).

	/**
	 * Catálogo servible (docs visibles) a partir del cual el motor requiere vigilancia.
	 * Umbral preventivo: conjuntos mayores a este número incrementan la latencia
	 * en ordenamiento PHP y consultas complejas de facetas.
	 */
	public const VISIBLE_DOCS_WARN = 5000;

	/**
	 * Junta los insumos del veredicto. Único método con acceso a WP/DB.
	 *
	 * @return array<string, mixed>
	 */
	public static function collect(): array {
		global $wpdb;

		$docs_table = Install::docs_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- docs_table() retorna identificador sanitizado interno; conteo sin parámetros.
		$docs = self::safe_count( "SELECT COUNT(*) FROM {$docs_table}" );

		// Conteo de documentos visibles servibles por el buscador.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- docs_table() retorna identificador sanitizado interno; constante visible = 1.
		$docs_visible = self::safe_count( "SELECT COUNT(*) FROM {$docs_table} WHERE visible = 1" );
		$eligible     = self::eligible_product_count();
		$variables    = self::variable_product_count();
		$read_errors  = array();
		if ( null === $docs ) {
			$read_errors[] = 'docs_count_failed';
		}
		if ( null === $docs_visible ) {
			$read_errors[] = 'visible_docs_count_failed';
		}
		if ( null === $eligible ) {
			$read_errors[] = 'eligible_source_failed';
		}
		if ( null === $variables ) {
			$read_errors[] = 'variable_count_failed';
		}

		$last         = get_option( RebuildJob::OPTION_LAST, array() );
		$reconcile    = get_option( Reconciler::OPTION_LAST, array() );
		$reconcile_error = get_option( Reconciler::OPTION_LAST_ERROR, array() );
		$work_repo    = new WorkRepository( $wpdb );
		$work_summary = $work_repo->summary();
		$work         = self::work_status(
			is_array( $work_summary ) ? $work_summary : array(),
			false === $work_summary ? $work_repo->last_error() : ''
		);

		return array(
			'now'             => time(),
			'docs'            => $docs,
			'docs_visible'    => $docs_visible,
			'eligible'        => $eligible,
			'variables'       => $variables,
			'read_errors'     => $read_errors,
			'editorial'       => EditorialDiscountPublication::status(),
			'rebuild'         => RebuildJob::status(),
			'work'            => $work,
			'topology'        => Install::classify_topology(),
			'last_rebuild'    => is_array( $last ) ? $last : array(),
			'reconcile_last'  => is_array( $reconcile ) ? $reconcile : array(),
			'reconcile_error' => is_array( $reconcile_error ) ? $reconcile_error : array(),
			'missing_indexes' => Install::missing_indexes(),
			'token_collation' => Install::token_collation(),
			// Desglose de tres estados por funcionalidad:
			//   configured: valor en options de WordPress;
			//   published:  valor expuesto en la publicación semántica;
			//   effective:  disponibilidad real considerando compuertas fail-closed.
			'flags'           => array(
				'indexing' => array(
					'configured' => Search::configured_toggle( Search::OPTION_INDEXING ),
					'published'  => Search::indexing_enabled(),
					'effective'  => Search::indexing_enabled(),
				),
				'suggest'  => array(
					'configured' => Search::configured_toggle( Search::OPTION_SUGGEST ),
					'published'  => Search::suggest_configured(),
					'effective'  => Search::suggest_enabled(),
				),
				'facets'   => array(
					'configured' => Search::configured_toggle( Search::OPTION_FACETS ),
					'published'  => Search::facets_configured(),
					'effective'  => Search::facets_enabled(),
				),
			),
			// Después de los flags a propósito: son sus lecturas las que dejan
			// cargada (o caída) la autoridad de la foto semántica.
			'semantic'        => array(
				'available' => SemanticPublication::authoritative_available(),
				'pending'   => SemanticPublication::publication_pending(),
			),
		);
	}

	/**
	 * Normaliza la foto global del work durable para REST, UI y veredicto.
	 * Incluye live aunque no exista una reconstrucción activa: Action Scheduler
	 * sólo despierta al worker y nunca es la fuente de verdad de esa deuda.
	 *
	 * @param array<string, array<string, int>> $summary Conteos por scope/status.
	 * @param string                            $error   Fallo de lectura, si existe.
	 * @return array{summary: array<string, array<string, int>>, pending: int, leased: int, blocked: int, error: string}
	 */
	public static function work_status( array $summary, string $error = '' ): array {
		$totals = array(
			'pending' => 0,
			'leased'  => 0,
			'blocked' => 0,
		);

		foreach ( $summary as $statuses ) {
			if ( ! is_array( $statuses ) ) {
				continue;
			}
			$totals['pending'] += (int) ( $statuses[ WorkRepository::STATUS_PENDING ] ?? 0 );
			$totals['leased']  += (int) ( $statuses[ WorkRepository::STATUS_LEASED ] ?? 0 );
			$totals['blocked'] += (int) ( $statuses[ WorkRepository::STATUS_BLOCKED ] ?? 0 );
		}

		return array(
			'summary' => $summary,
			'pending' => $totals['pending'],
			'leased'  => $totals['leased'],
			'blocked' => $totals['blocked'],
			'error'   => trim( $error ),
		);
	}

	/**
	 * Conteo de productos elegibles para el índice (`product` publicados).
	 *
	 * Ejecuta una consulta directa de agregación `COUNT(*)` compartiendo
	 * el predicado de elegibilidad de `RebuildJob::eligible_products_where()`.
	 * Conserva semántica fail-closed retornando null si la consulta falla.
	 *
	 * @return int|null
	 */
	private static function eligible_product_count(): ?int {
		global $wpdb;

		list( $where, $params ) = RebuildJob::eligible_products_where();

		$wpdb->last_error = '';
		$value = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- predicado compartido: los placeholders viajan DENTRO de $where y el sniff no los ve.
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE {$where}",
				...$params
			)
		);

		return self::normalise_count( $value, (string) ( $wpdb->last_error ?? '' ) );
	}

	/**
	 * Conteo de productos variables publicados en WooCommerce.
	 *
	 * El índice cataloga productos simples; este conteo permite advertir
	 * en el panel si existen variaciones en catálogo que requieran atención.
	 *
	 * @return int|null
	 */
	private static function variable_product_count(): ?int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- inicialización de propiedad para detección de fallos.
		$wpdb->last_error = '';
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT p.ID)
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
				INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = %s
				INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id AND t.slug = %s
				WHERE p.post_type = %s AND p.post_status = %s",
				'product_type',
				'variable',
				'product',
				'publish'
			)
		);
		return self::normalise_count( $value, (string) ( $wpdb->last_error ?? '' ) );
	}

	/** @return int|null */
	private static function safe_count( string $sql ): ?int {
		global $wpdb;
		$wpdb->last_error = '';
		$value = $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql no es entrada: los dos únicos llamadores de este helper privado le pasan literales armados con Install::docs_table(), sin ningún valor que preparar.
		return self::normalise_count( $value, (string) ( $wpdb->last_error ?? '' ) );
	}

	/** @param mixed $value @return int|null */
	private static function normalise_count( $value, string $error ): ?int {
		if ( '' !== trim( $error ) ) {
			return null;
		}
		if ( is_int( $value ) && $value >= 0 ) {
			return $value;
		}
		return is_string( $value ) && 1 === preg_match( '/^[0-9]+$/D', $value ) ? (int) $value : null;
	}

	/**
	 * Tabla de veredictos (PURA — sin WP, sin time(): todo entra por $data).
	 *
	 * @param array<string, mixed> $data Insumos de collect().
	 * @return array{verdict: string, reasons: array<int, array{code: string, message: string, cta: string}>}
	 */
	public static function verdict( array $data ): array {
		$now     = (int) ( $data['now'] ?? 0 );
		$rebuild = $data['rebuild'] ?? null;
		$work    = is_array( $data['work'] ?? null ) ? $data['work'] : array();
		$reasons = array();

		$read_errors = is_array( $data['read_errors'] ?? null ) ? $data['read_errors'] : array();
		if ( ! empty( $read_errors ) ) {
			return array(
				'verdict' => self::ATENCION,
				'reasons' => array(
					array(
						'code'    => 'lectura_no_verificable',
						'message' => 'No se pudieron verificar los conteos fuente/índice (' . implode( ', ', array_map( 'strval', $read_errors ) ) . '). No se interpreta el error como catálogo vacío.',
						'cta'     => '',
					),
				),
			);
		}

		$editorial = is_array( $data['editorial'] ?? null ) ? $data['editorial'] : array();
		if ( array_key_exists( 'editorial', $data ) && empty( $editorial['ready'] ) && ! is_array( $rebuild ) ) {
			return array(
				'verdict' => self::ATENCION,
				'reasons' => array(
					array(
						'code'    => 'materializacion_editorial_cerrada',
						'message' => 'La publicación materializada de precios/facetas está cerrada hasta completar un rebuild con receipt exacto.',
						'cta'     => 'rebuild',
					),
				),
			);
		}

		// --- Rebuild en curso -------------------------------------------------
		if ( is_array( $rebuild ) ) {
			$phase         = (string) ( $rebuild['phase'] ?? '' );
			$rebuild_error = trim( (string) ( $rebuild['error'] ?? '' ) );
			if ( in_array( $phase, array( RebuildJob::PHASE_BLOCKED, RebuildJob::PHASE_ABORTED ), true ) || '' !== $rebuild_error ) {
				$reasons[] = array(
					'code'    => 'protocolo_bloqueado',
					'message' => sprintf(
						'El protocolo durable quedó en fase %s%s (topología %s). No se hará una mutación destructiva automática; requiere diagnóstico.',
						'' !== $phase ? $phase : 'desconocida',
						'' !== $rebuild_error ? ' · ' . $rebuild_error : '',
						(string) ( $rebuild['topology'] ?? ( $data['topology'] ?? 'desconocida' ) )
					),
					'cta'     => '',
				);
				return array(
					'verdict' => self::ATENCION,
					'reasons' => $reasons,
				);
			}

			if ( '' !== trim( (string) ( $work['error'] ?? '' ) ) ) {
				$reasons[] = array(
					'code'    => 'work_no_observable',
					'message' => 'No se pudo leer la cola durable del buscador; su deuda no es verificable y no se autoriza una reconstrucción a ciegas.',
					'cta'     => '',
				);
				return array(
					'verdict' => self::ATENCION,
					'reasons' => $reasons,
				);
			}

			if ( 0 < (int) ( $work['blocked'] ?? 0 ) ) {
				$reasons[] = array(
					'code'    => 'work_bloqueado',
					'message' => sprintf(
						'La cola durable tiene %d trabajo(s) bloqueado(s). La deuda se conserva para diagnóstico y no se descarta automáticamente.',
						(int) $work['blocked']
					),
					'cta'     => '',
				);
				return array(
					'verdict' => self::ATENCION,
					'reasons' => $reasons,
				);
			}

			if ( ! empty( $rebuild['stalled'] ) ) {
				// Distingue detención durante la fase de construcción de catálogo o durante la puesta al día de reparaciones.
				$por_poner_al_dia = (int) ( $rebuild['repair_pending'] ?? 0 );
				$reasons[]        = array(
					'code'    => 'rebuild_detenido',
					'message' => 0 < $por_poner_al_dia
						? sprintf(
							'La reconstrucción terminó de armar el catálogo (%d de %d productos) pero se detuvo con %d productos por poner al día.',
							(int) $rebuild['done'],
							(int) $rebuild['total'],
							$por_poner_al_dia
						)
						: sprintf(
							'La reconstrucción se detuvo en %d de %d productos (sin avance y sin trabajos encolados).',
							(int) $rebuild['done'],
							(int) $rebuild['total']
						),
					'cta'     => 'retry',
				);
				return array(
					'verdict' => self::ATENCION,
					'reasons' => $reasons,
				);
			}

			if ( $now - (int) $rebuild['updated_at'] >= self::QUEUE_DEAD_SECONDS ) {
				$reasons[] = array(
					'code'    => 'cola_no_avanza',
					'message' => 'Hay una reconstrucción encolada pero la cola no avanza hace más de 30 minutos.',
					'cta'     => 'retry',
				);
				return array(
					'verdict' => self::ATENCION,
					'reasons' => $reasons,
				);
			}

			return array(
				'verdict' => self::RECONSTRUYENDO,
				'reasons' => array(),
			);
		}

		// --- Deuda durable global (también sin rebuild activo) ----------------
		if ( '' !== trim( (string) ( $work['error'] ?? '' ) ) ) {
			$reasons[] = array(
				'code'    => 'work_no_observable',
				'message' => 'No se pudo leer la cola durable del buscador; las escrituras pendientes no son verificables.',
				'cta'     => '',
			);
		} elseif ( 0 < (int) ( $work['blocked'] ?? 0 ) ) {
			$reasons[] = array(
				'code'    => 'work_bloqueado',
				'message' => sprintf(
					'La cola durable tiene %d trabajo(s) bloqueado(s). La deuda sigue persistida y requiere diagnóstico.',
					(int) $work['blocked']
				),
				'cta'     => '',
			);
		}

		// --- Último rebuild con error ----------------------------------------
		$last = is_array( $data['last_rebuild'] ?? null ) ? $data['last_rebuild'] : array();
		if ( ! empty( $last['error'] ) ) {
			$reasons[] = array(
				'code'    => 'rebuild_fallido',
				'message' => 'La última reconstrucción terminó con error; la tienda sigue sirviendo el índice anterior.',
				'cta'     => 'retry',
			);
		}

		// --- Cobertura (SIN rebuild en curso: el drain post-swap es tolerancia) --
		$docs     = (int) ( $data['docs'] ?? 0 );
		$eligible = (int) ( $data['eligible'] ?? 0 );
		if ( $eligible > 0 && $docs < $eligible ) {
			// Índice VACÍO tiene severidad narrativa distinta: el fail-open del
			// front está activo. Va en la MISMA entrada 'cobertura' — un write a
			// $reasons[0] pisaría la razón equivocada si 'rebuild_fallido' ya entró.
			$reasons[] = array(
				'code'    => 'cobertura',
				'message' => 0 === $docs
					? 'El índice está VACÍO: la tienda sirve el catálogo sin filtrar (fail-open). Reconstruye el índice.'
					: sprintf( 'Índice incompleto: %d de %d productos elegibles.', $docs, $eligible ),
				'cta'     => 'rebuild',
			);
		} elseif ( $docs > $eligible ) {
			// Documentos huérfanos: productos despublicados pendientes de depuración por el reconciliador.
			$reasons[] = array(
				'code'    => 'huerfanos',
				'message' => sprintf(
					'El índice tiene %d documentos de más (%d indexados vs %d productos elegibles): productos despublicados que quedaron sin drenar.',
					$docs - $eligible,
					$docs,
					$eligible
				),
				'cta'     => 'reconcile',
			);
		}

		// --- Productos variables (el índice representa SOLO al padre) ---------
		// No hay corrección automática posible acá: el pipeline entero indexa
		// `product` y nunca `product_variation` a propósito. Lo que sí se puede
		// es no dejar que el primero entre en SILENCIO — con un variable en el
		// catálogo el buscador no encuentra por SKU de variación, ordena por el
		// precio mínimo del rango y jamás lo marca en Oferta.
		$variables = (int) ( $data['variables'] ?? 0 );
		if ( $variables > 0 ) {
			$reasons[] = array(
				'code'    => 'variables',
				'message' => sprintf(
					1 === $variables
						? 'Hay %d producto variable publicado: el índice solo representa al producto padre (no busca por SKU de variación, ordena por el precio más bajo del rango y no lo marca en Oferta). Revisa si conviene publicarlo como producto simple.'
						: 'Hay %d productos variables publicados: el índice solo representa al producto padre (no busca por SKU de variación, ordena por el precio más bajo del rango y no los marca en Oferta). Revisa si conviene publicarlos como productos simples.',
					$variables
				),
				'cta'     => '',
			);
		}

		// --- Divergencias del reconciler --------------------------------------
		// Incluye productos desalineados y facetas huérfanas (términos eliminados).
		$reconcile = is_array( $data['reconcile_last'] ?? null ) ? $data['reconcile_last'] : array();
		$reconcile_error = is_array( $data['reconcile_error'] ?? null ) ? $data['reconcile_error'] : array();
		if ( ! empty( $reconcile_error['error'] )
			&& (int) ( $reconcile_error['ran_at'] ?? 0 ) > (int) ( $reconcile['ran_at'] ?? 0 ) ) {
			$reasons[] = array(
				'code'    => 'reconciler_fallido',
				'message' => sprintf(
					'La última verificación de consistencia falló (%s); no se selló un resumen parcial como éxito.',
					(string) $reconcile_error['error']
				),
				'cta'     => 'reconcile',
			);
		}
		$diverged  = (int) ( $reconcile['missing'] ?? 0 ) + (int) ( $reconcile['orphaned'] ?? 0 )
			+ (int) ( $reconcile['divergent'] ?? 0 ) + (int) ( $reconcile['stale'] ?? 0 )
			+ (int) ( $reconcile['orphan_facets'] ?? 0 );
		if ( $diverged > 0 ) {
			// El reconciliador repara lo que encuentra; la advertencia persiste hasta confirmar convergencia limpia.
			$reasons[] = array(
				'code'    => 'divergencias',
				'message' => sprintf(
					1 === $diverged
						? 'La última verificación encontró y corrigió %d diferencia. Vuelve a verificar para confirmar que quedó limpio.'
						: 'La última verificación encontró y corrigió %d diferencias. Vuelve a verificar para confirmar que quedó limpio.',
					$diverged
				),
				'cta'     => 'reconcile',
			);
		}

		// --- Latido del reconciler --------------------------------------------
		$ran_at   = (int) ( $reconcile['ran_at'] ?? 0 );
		$baseline = max( $ran_at, (int) ( $last['finished_at'] ?? 0 ) );
		if ( $baseline > 0 && ( $now - $baseline ) > self::HEARTBEAT_SECONDS ) {
			$reasons[] = array(
				'code'    => 'reconciler_mudo',
				'message' => 'La verificación diaria de consistencia no corre hace más de 48 horas.',
				'cta'     => 'reconcile',
			);
		}

		// --- Schema incompleto ------------------------------------------------
		// Verifica la presencia efectiva de todos los índices del schema vigente.
		$schema_raw     = $data['missing_indexes'] ?? null;
		$schema_missing = is_array( $schema_raw ) ? $schema_raw : array();
		if ( null === $schema_raw ) {
			$reasons[] = array(
				'code'    => 'schema_desconocido',
				'message' => 'No se pudo leer la metadata de índices del esquema (SHOW INDEX falló): el estado del schema queda sin comprobar en este informe.',
				'cta'     => '',
			);
		}
		if ( ! empty( $schema_missing ) ) {
			$reasons[] = array(
				'code'    => 'schema',
				'message' => sprintf(
					'Al índice le faltan estructuras del schema vigente (%s): la versión de DB dice estar al día pero la migración no corrió completa. Reparación: borrar la option akb_search_db_version y recargar (re-dispara la migración).',
					implode( ', ', array_map( 'strval', $schema_missing ) )
				),
				'cta'     => '',
			);
		}

		// --- Collation de tokens ----------------------------------------------
		// La tabla de tokens exige collation utf8mb4_bin para evitar colisiones en clave primaria.
		$collation = (string) ( $data['token_collation'] ?? '' );
		if ( '' !== $collation && 'utf8mb4_bin' !== $collation ) {
			$reasons[] = array(
				'code'    => 'schema_collation',
				'message' => sprintf(
					'La tabla de tokens quedó con collation %s en vez de utf8mb4_bin: las variantes con tilde o ñ colisionan en la clave primaria y las escrituras del índice fallan en silencio (productos que dejan de aparecer sin error visible). Reparación: borrar la option akb_search_db_version y recargar (re-dispara la migración).',
					$collation
				),
				'cta'     => '',
			);
		}

		// --- Tamaño del catálogo (aviso de capacidad) -------------------------
		$docs_visible = (int) ( $data['docs_visible'] ?? 0 );
		if ( $docs_visible >= self::VISIBLE_DOCS_WARN ) {
			$reasons[] = array(
				'code'    => 'catalogo_grande',
				'message' => sprintf(
					'El catálogo servible llegó a %1$d productos visibles (umbral de vigilancia: %2$d). El buscador puntúa y ordena el conjunto completo en PHP y arma un IN con un identificador por producto en cada consulta de filtros, y esos conteos no se cachean: por encima de este umbral una búsqueda amplia deja de ser imperceptible. El índice está SANO; es un aviso de capacidad para planificar (acotar el conjunto antes de puntuar sí requiere rediseño, no es un ajuste).',
					$docs_visible,
					self::VISIBLE_DOCS_WARN
				),
				'cta'     => '',
			);
		}

		// --- Flags inconsistentes ---------------------------------------------
		$flags = is_array( $data['flags'] ?? null ) ? $data['flags'] : array();
		$facets_effective = self::flag_effective( $flags['facets'] ?? false );
		$suggest_effective = self::flag_effective( $flags['suggest'] ?? false );
		$indexing_effective = self::flag_effective( $flags['indexing'] ?? false );
		if ( ( $facets_effective || $suggest_effective ) && ! $indexing_effective ) {
			$reasons[] = array(
				'code'    => 'flags',
				'message' => 'Filtros o sugerencias están activos pero la indexación automática está apagada: el índice va a envejecer.',
				'cta'     => 'flags',
			);
		}

		return array(
			'verdict' => empty( $reasons ) ? self::SANO : self::ATENCION,
			'reasons' => $reasons,
		);
	}

	/**
	 * Lee el estado que realmente sirve tráfico.
	 *
	 * @param mixed $flag Fila {configured, published, effective} de collect().
	 */
	private static function flag_effective( $flag ): bool {
		return is_array( $flag ) && ! empty( $flag['effective'] );
	}
}
