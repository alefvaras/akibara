<?php
/**
 * DemandGaps — títulos BUSCADOS SIN NINGÚN RESULTADO, ya descontados los typos.
 *
 * Es una lista de COMPRA, no un log: sólo entra lo que el dueño podría querer
 * traer. Por eso se descuentan dos cosas antes de recortar:
 *   - los typos que el corrector ya rescata (un «rannma» no es un tomo que
 *     falte), veredicto difuso que se resuelve en PHP con el rescatador cacheado;
 *   - las búsquedas que el dueño DESCARTÓ (GapDismissals), veredicto exacto que
 *     viaja al SQL.
 *
 * El orden importa y era el defecto: con el LIMIT delante de los filtros, un
 * puñado de rescates o de descartes en la cabeza del ranking borraba huecos
 * REALES que venían más abajo — la lista se vaciaba sola a medida que el dueño
 * la usaba. Ahora el SQL trae un POOL con holgura, se filtra, y recién ahí se
 * recorta.
 *
 * Fuente única de las DOS superficies que muestran el radar: el panel
 * «Buscador → Índice» y el ranking «Lo más solicitado» del Centro de Mando de
 * akibara-business (vía la función puente `akibara_search_demand_gaps`).
 *
 * @package Akibara\Search
 */

declare( strict_types = 1 );

namespace Akibara\Search;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.1.41
 */
final class DemandGaps {

	/** Ventana por defecto: la cola de demanda es larga y necesita juntar señal. */
	public const DEFAULT_DAYS = 90;

	/**
	 * Holgura del pool sobre el límite pedido. El filtro de rescates es difuso
	 * y sólo se puede aplicar en PHP, así que el SQL tiene que traer de más.
	 */
	private const POOL_FACTOR = 4;

	/** Piso y techo del pool: ni una lectura inútil, ni un rescatador desbocado. */
	private const POOL_MIN = 20;
	private const POOL_MAX = 100;

	/**
	 * Huecos reales, ordenados por demanda.
	 *
	 * @param \wpdb $db    Conexión.
	 * @param int   $days  Ventana en días.
	 * @param int   $limit Máximo de filas devueltas.
	 * @return array<int, object> Filas `{query_norm, s, s30}`.
	 */
	public static function rows( $db, int $days = self::DEFAULT_DAYS, int $limit = 10 ): array {
		$qlog    = Install::qlog_table();
		$days    = max( 1, min( 365, $days ) );
		$limit   = max( 1, $limit );
		$pool    = min( self::POOL_MAX, max( self::POOL_MIN, $limit * self::POOL_FACTOR ) );
		$since   = QueryLogger::day( $days );
		$since30 = QueryLogger::day( 30 );

		$dismissed = GapDismissals::all();
		$params    = array( $since30, $since );
		$exclude   = '';
		if ( array() !== $dismissed ) {
			// La lista está acotada a GapDismissals::CAP, así que el IN es finito.
			$names   = array_map( 'strval', array_keys( $dismissed ) );
			$exclude = ' AND query_norm NOT IN (' . implode( ',', array_fill( 0, count( $names ), '%s' ) ) . ')';
			$params  = array_merge( $params, $names );
		}
		$params[] = $pool;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- Lectura directa del ranking preparado para visualización en panel administrativo.
		$rows = $db->get_results(
			$db->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Nombre de tabla e identificadores seguros construidos vía marcadores %s acotados por GapDismissals::CAP.
				"SELECT query_norm, SUM(searches) AS s,
					SUM(CASE WHEN day >= %s THEN searches ELSE 0 END) AS s30
				FROM {$qlog} WHERE day >= %s AND hits = 0{$exclude}
				GROUP BY query_norm ORDER BY s DESC LIMIT %d",
				$params
			)
		);
		if ( ! is_array( $rows ) || array() === $rows ) {
			return array();
		}

		$rescues = SearchAdminSection::zero_result_rescues(
			array_map( static fn( $row ): string => (string) $row->query_norm, $rows )
		);

		$out = array();
		foreach ( $rows as $row ) {
			$rescue = $rescues[ (string) $row->query_norm ] ?? null;
			// Fail-closed ante falta de veredicto: si el índice no responde o
			// zero_result_rescues() corta prematuramente, no se clasifica como
			// hueco para evitar falsos positivos de compra ante hipos del índice.
			if ( ! is_array( $rescue ) ) {
				continue;
			}
			if ( (int) $rescue['hits'] > 0 ) {
				continue; // El corrector ya lo resuelve: no es un hueco.
			}
			$out[] = $row;
			if ( count( $out ) >= $limit ) {
				break;
			}
		}

		return $out;
	}
}
