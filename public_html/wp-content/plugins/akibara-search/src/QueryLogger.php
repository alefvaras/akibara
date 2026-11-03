<?php
/**
 * QueryLogger — registro agregado de búsquedas en akb_search_qlog.
 *
 * Agregación eficiente por tupla (day, query_norm, source) con contador acumulativo,
 * evitando registros individuales por evento para prevenir bloat de base de datos.
 * El UPSERT (ON DUPLICATE KEY) consolida múltiples consultas idénticas en una sola fila.
 *
 * Anti-bot + PII:
 *   - Filtro de user-agent bot (los bots no deben envenenar el ranking futuro
 *     ni las métricas de 0-hits).
 *   - Scrub de PII: emails y secuencias largas de dígitos (teléfonos) se
 *     quitan de la query antes de persistir — nadie tipea su email en el
 *     buscador a propósito, pero pasa, y el qlog no es lugar para PII.
 *   - Solo la query "asentada" (la que el cliente decide loggear tras el
 *     debounce), no cada keystroke.
 *
 * @package Akibara\Search
 */

declare( strict_types = 1 );

namespace Akibara\Search;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.55.0
 */
final class QueryLogger {

	public const SOURCE_SEARCH  = 'search';
	public const SOURCE_SUGGEST = 'suggest';

	/**
	 * Largo mínimo de query a loggear (evita ruido de 1-2 chars). PÚBLICA
	 * porque `/track` la usa como gate PREVIO al motor: filtrar recién acá
	 * significaba pagar la búsqueda completa para tirar el resultado.
	 */
	public const MIN_LENGTH = 2;

	/** @var \wpdb */
	private $db;

	/**
	 * El «día» del registro de búsquedas en la zona horaria local de la tienda.
	 *
	 * Fuente única de cómputo temporal: sincroniza tanto la escritura como la lectura
	 * de métricas con la zona horaria de Chile/WordPress (leyendo `timezone_string` y
	 * `gmt_offset`), evitando desfases de franja horaria respecto a UTC.
	 *
	 * La zona se lee de los dos ajustes de WordPress (`timezone_string` y, si
	 * falta, `gmt_offset`), que es lo que hace `wp_timezone()` por dentro. No se
	 * llama a `wp_date()` a propósito: este método corre en el camino caliente
	 * del registro y detrás de un `function_exists()` la respuesta dependería
	 * de qué se cargó antes — en la suite, del orden de las pruebas.
	 *
	 * @param int      $days_ago Días hacia atrás (0 = hoy).
	 * @param int|null $now      Instante de referencia; null = ahora. Existe para poder probarlo.
	 * @return string `Y-m-d`.
	 */
	public static function day( int $days_ago = 0, ?int $now = null ): string {
		$cuando = ( $now ?? time() ) - max( 0, $days_ago ) * DAY_IN_SECONDS;

		return ( new \DateTimeImmutable( '@' . $cuando ) )->setTimezone( self::store_timezone() )->format( 'Y-m-d' );
	}

	/**
	 * Zona horaria de la tienda; UTC si no hay ninguna configurada o es inválida.
	 *
	 * @return \DateTimeZone
	 */
	private static function store_timezone(): \DateTimeZone {
		try {
			$nombre = (string) get_option( 'timezone_string', '' );
			if ( '' !== $nombre ) {
				return new \DateTimeZone( $nombre );
			}

			$horas = (float) get_option( 'gmt_offset', 0 );
			$signo = $horas < 0 ? '-' : '+';
			$abs   = abs( $horas );

			return new \DateTimeZone( sprintf( '%s%02d:%02d', $signo, (int) floor( $abs ), (int) round( ( $abs - floor( $abs ) ) * 60 ) ) );
		} catch ( \Throwable $throwable ) {
			return new \DateTimeZone( 'UTC' );
		}
	}

	/**
	 * @param \wpdb $db WordPress DB.
	 */
	public function __construct( $db ) {
		$this->db = $db;
	}

	/**
	 * Registra una búsqueda asentada. Idempotente-por-día vía UPSERT.
	 *
	 * @param string $raw_query  Query cruda del usuario.
	 * @param int    $hits       Nº de resultados que devolvió.
	 * @param string $source     SOURCE_SEARCH | SOURCE_SUGGEST.
	 * @param string $user_agent User-agent (para filtro bot); '' = saltear filtro.
	 * @return bool True si se registró.
	 */
	public function log( string $raw_query, int $hits, string $source = self::SOURCE_SUGGEST, string $user_agent = '' ): bool {
		if ( '' !== $user_agent && self::looks_like_bot( $user_agent ) ) {
			return false;
		}

		$query = self::scrub( $raw_query );
		$query = Normalizer::normalize_query_for_log( $query );

		if ( strlen( $query ) < self::MIN_LENGTH ) {
			return false;
		}

		// Fail-safe: solo asigna SOURCE_SEARCH ante valor explícito coincidente;
		// de lo contrario, clasifica como SOURCE_SUGGEST para proteger el ranking de populares.
		$source = self::SOURCE_SEARCH === $source
			? self::SOURCE_SEARCH
			: self::SOURCE_SUGGEST;

		$table = Install::qlog_table();

		// UPSERT: primera búsqueda del día crea la fila; las siguientes suman.
		// hits = VALUES(hits) refleja el conteo más reciente (útil para 0-hits).
		$result = $this->db->query(
			$this->db->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table} sale de Install::qlog_table() ($wpdb->prefix + literal fijo); día, consulta, fuente y hits viajan parametrizados.
				"INSERT INTO {$table} (day, query_norm, source, hits, searches)
				VALUES (%s, %s, %s, %d, 1)
				ON DUPLICATE KEY UPDATE searches = searches + 1, hits = VALUES(hits)",
				self::day(),
				$query,
				$source,
				max( 0, $hits )
			)
		);

		return false !== $result;
	}

	/**
	 * Quita PII de la query: emails, RUT chileno formateado ("12.345.678-9" o
	 * con espacios "12 345 678-9", cuyas corridas de dígitos son de 2/3/3/1
	 * que un patrón simple de dígitos continuos no capturaría), celulares
	 * +56 9 con separadores, referencias de pedido y secuencias de >= 7 dígitos.
	 * Los ISBN de 13 dígitos también caen por diseño de privacidad estricta.
	 *
	 * @param string $query Query cruda.
	 * @return string
	 */
	public static function scrub( string $query ): string {
		$query = (string) preg_replace( '/[^\s@]+@[^\s@]+\.[^\s@]+/u', ' ', $query );
		// Celular ANTES que RUT: el patrón de RUT con espacios (2/3/3/1) también
		// matchea "1234 5678" (1/234 567/8) y dejaría el "+56 9" huérfano.
		$query = (string) preg_replace( '/(?:\+?56)?\s*9[\s\-.]?\d{4}[\s\-.]?\d{4}/u', ' ', $query );
		$query = (string) preg_replace( '/\b\d{1,2}[.\s]?\d{3}[.\s]?\d{3}[\s-]?[\dkK]\b/u', ' ', $query );
		$query = (string) preg_replace( '/\b(?:pedido|orden|order|compra)\s*#?\s*\d+/iu', ' ', $query );
		$query = (string) preg_replace( '/\d{7,}/', ' ', $query );
		return trim( (string) preg_replace( '/\s+/', ' ', $query ) );
	}

	/**
	 * Heurística preventiva de bots por user-agent para evitar que rastreadores
	 * comunes contaminen las métricas de consultas y populares.
	 *
	 * @param string $user_agent User-agent.
	 * @return bool
	 */
	public static function looks_like_bot( string $user_agent ): bool {
		if ( '' === $user_agent ) {
			return false;
		}
		// Exclusión de crawlers conocidos y sondas de QA (`akibara-qa`) para
		// evitar que el tráfico sintético contamine las métricas de demanda real.
		return (bool) preg_match(
			'/bot|crawl|spider|slurp|bing|google|yandex|baidu|duckduck|semrush|ahrefs|mj12|python-requests|curl|wget|headless|phantom|akibara[-_ ]?qa/i',
			$user_agent
		);
	}
}
