<?php
/**
 * PopularQueries — términos de búsqueda populares para el estado vacío o enfocado del buscador.
 *
 * Fuente: agregados de `akb_search_qlog` con `source='search'` (envíos confirmados de búsqueda).
 * Protecciones de calidad:
 *   - Requiere `hits > 0` con coincidencia efectiva en catálogo.
 *   - Colapso de prefijos y umbral mínimo de búsquedas (`MIN_SEARCHES = 3`).
 *   - Interruptor general en panel administrativo (`OPTION_ENABLED`).
 *
 * @package Akibara\Search
 */

declare( strict_types = 1 );

namespace Akibara\Search;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.61.0
 */
final class PopularQueries {

	use Support\FailsClosed;

	/** Kill-switch granular: apagar Populares sin matar el autocomplete. */
	public const OPTION_ENABLED = 'akb_search_popular_enabled';

	/** Transient del top calculado. Dato blando: solo expira, no se purga. */
	public const TRANSIENT = 'akb_search_popular';

	/** TTL del transient (12 h). */
	private const TTL = 12 * 3600;

	/** Ventana de agregación en días. */
	private const WINDOW_DAYS = 30;

	/** Items expuestos al front. */
	private const LIMIT = 5;

	/** Señales mínimas: SUM(searches) en la ventana. */
	private const MIN_SEARCHES = 2;

	/** Pool pre-colapso (el colapso necesita ver a las familias completas). */
	private const POOL_SIZE = 50;

	/** @var \wpdb */
	private $db;

	/**
	 * @param \wpdb $db WordPress DB.
	 */
	public function __construct( $db ) {
		$this->db = $db;
	}

	/**
	 * @return bool
	 */
	public function enabled(): bool {
		try {
			$enabled = 'yes' === SemanticPublication::authoritative_value( self::OPTION_ENABLED, 'yes' );
		} catch ( \Throwable $exception ) {
			$this->mark_unavailable( $exception->getMessage() ?: 'Could not read popular queries configuration.' );
			return false;
		}
		if ( ! SemanticPublication::authoritative_available() ) {
			$this->mark_unavailable( SemanticPublication::authoritative_error() );
		}
		return $enabled;
	}

	/**
	 * Top de búsquedas populares, cacheado. Vacío si el kill-switch está off.
	 *
	 * @return array<int, string>
	 */
	public function top(): array {
		if ( ! $this->available ) {
			return array();
		}
		if ( ! $this->enabled() ) {
			return array();
		}
		if ( ! $this->available ) {
			return array();
		}

		try {
			$cached = get_transient( self::TRANSIENT );
		} catch ( \Throwable $exception ) {
			$cached = false; // transient best-effort: continuar a SQL.
		}
		$cached = $this->validated_cached_top( $cached );
		if ( null !== $cached ) {
			return $cached;
		}

		$table = Install::qlog_table();
		try {
			if ( property_exists( $this->db, 'last_error' ) ) {
				$this->db->last_error = '';
			}
			$prepared = $this->db->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table} sale de Install::qlog_table(); los parámetros viajan tipados.
				"SELECT query_norm, SUM(searches) AS s FROM {$table}
				WHERE source = %s AND day >= %s
				GROUP BY query_norm
				HAVING SUM(searches) >= %d AND MAX(hits) > 0
				ORDER BY s DESC, query_norm ASC
				LIMIT %d",
				QueryLogger::SOURCE_SEARCH,
				QueryLogger::day( self::WINDOW_DAYS ),
				self::MIN_SEARCHES,
				self::POOL_SIZE
			);
			$rows = is_string( $prepared ) ? $this->db->get_results( $prepared, ARRAY_A ) : null;
		} catch ( \Throwable $exception ) {
			$this->mark_unavailable( $exception->getMessage() ?: 'Could not read popular search queries.' );
			return array();
		}
		if ( ! is_array( $rows ) || $this->database_has_error() ) {
			$this->mark_unavailable( 'Could not read popular search queries.' );
			return array();
		}
		$rows = $this->validated_rows( $rows );
		if ( null === $rows ) {
			$this->mark_unavailable( 'Popular search query rows returned an invalid shape.' );
			return array();
		}

		$top = array_slice( self::collapse_prefixes( $rows ), 0, self::LIMIT );
		try {
			set_transient( self::TRANSIENT, $top, self::TTL );
		} catch ( \Throwable $exception ) {
			// Cache blando: la lectura SQL autoritativa sigue siendo servible.
		}

		return $top;
	}

	/**
	 * Colapsa familias de prefijos: la candidata corta cae cuando es prefijo
	 * de otra Y sus señales son <= las de la larga. Los empates colapsan hacia
	 * la forma completa y descriptiva ("juju"(2) ante "jujutsu kaisen"(2)).
	 * Si la variante corta domina en volumen de señales a su extensión, sobrevive.
	 *
	 * @param array<int, array<string, mixed>> $rows Filas {query_norm, s} ya
	 *                                               ordenadas por señales desc.
	 * @return array<int, string> Queries sobrevivientes, mismo orden.
	 */
	public static function collapse_prefixes( array $rows ): array {
		$out = array();
		foreach ( $rows as $row ) {
			$q = (string) ( $row['query_norm'] ?? '' );
			$s = (int) ( $row['s'] ?? 0 );
			if ( '' === $q ) {
				continue;
			}
			$dominated = false;
			foreach ( $rows as $other ) {
				$oq = (string) ( $other['query_norm'] ?? '' );
				$os = (int) ( $other['s'] ?? 0 );
				if ( $oq !== $q && strlen( $oq ) > strlen( $q ) && str_starts_with( $oq, $q ) && $s <= $os ) {
					$dominated = true;
					break;
				}
			}
			if ( ! $dominated ) {
				$out[] = $q;
			}
		}
		return $out;
	}

	/** @param mixed $cached @return array<int, string>|null */
	private function validated_cached_top( $cached ): ?array {
		if ( ! is_array( $cached ) || array_keys( $cached ) !== ( empty( $cached ) ? array() : range( 0, count( $cached ) - 1 ) )
			|| count( $cached ) > self::LIMIT ) {
			return null;
		}
		$seen = array();
		foreach ( $cached as $query ) {
			if ( ! self::valid_query( $query ) || isset( $seen[ $query ] ) ) {
				return null;
			}
			$seen[ $query ] = true;
		}

		return $cached;
	}

	/** @param array<int|string, mixed> $rows @return array<int, array{query_norm: string, s: int}>|null */
	private function validated_rows( array $rows ): ?array {
		if ( array_keys( $rows ) !== ( empty( $rows ) ? array() : range( 0, count( $rows ) - 1 ) )
			|| count( $rows ) > self::POOL_SIZE ) {
			return null;
		}
		$out  = array();
		$seen = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || count( $row ) !== 2 || ! array_key_exists( 'query_norm', $row ) || ! array_key_exists( 's', $row )
				|| ! self::valid_query( $row['query_norm'] ) || isset( $seen[ $row['query_norm'] ] ) ) {
				return null;
			}
			$signals = self::canonical_positive_integer( $row['s'] );
			if ( null === $signals || $signals < self::MIN_SEARCHES ) {
				return null;
			}
			$seen[ $row['query_norm'] ] = true;
			$out[] = array( 'query_norm' => $row['query_norm'], 's' => $signals );
		}

		return $out;
	}

	/** @param mixed $query */
	private static function valid_query( $query ): bool {
		return is_string( $query ) && '' !== $query && $query === trim( $query ) && strlen( $query ) <= 128
			&& 1 === preg_match( '//u', $query ) && ! preg_match( '/[\x00-\x1F\x7F]/', $query );
	}

	/** @param mixed $value */
	private static function canonical_positive_integer( $value ): ?int {
		if ( is_int( $value ) ) {
			return $value > 0 ? $value : null;
		}
		if ( ! is_string( $value ) || ! preg_match( '/^[1-9][0-9]*$/D', $value ) ) {
			return null;
		}
		$integer = (int) $value;
		return (string) $integer === $value ? $integer : null;
	}

	private function database_has_error(): bool {
		if ( ! property_exists( $this->db, 'last_error' ) ) {
			return false;
		}
		$error = $this->db->last_error;
		return ! ( null === $error || false === $error || '' === $error )
			&& ( ! is_string( $error ) || '' !== trim( $error ) );
	}
}
