<?php
/**
 * Snapshot de cobertura, universo de productos elegibles y verificación del índice.
 *
 * @package Akibara\Search
 */

declare( strict_types = 1 );

namespace Akibara\Search\Rebuild;

use Akibara\Search\Install;

defined( 'ABSPATH' ) || exit;

/**
 * Cobertura y universo de productos para rebuild.
 *
 * @since 0.1.85
 */
final class RebuildCoverage {

	/**
	 * Filtro canónico del universo fuente de productos publicables.
	 *
	 * @return array{0: string, 1: array<int, string>} Fragmento WHERE y sus parámetros.
	 */
	public static function eligible_products_where(): array {
		return array( 'post_type = %s AND post_status = %s', array( 'product', 'publish' ) );
	}

	/**
	 * Total del universo fuente exacto.
	 *
	 * @return int
	 */
	public static function eligible_product_count(): int {
		$ids = self::eligible_product_ids_snapshot();
		return null === $ids ? 0 : count( $ids );
	}

	/**
	 * Expone el mismo universo fuente del baseline para barreras globales.
	 *
	 * @return array<int, int>|null IDs exactos o null ante cualquier error SQL.
	 */
	public static function eligible_product_ids_snapshot(): ?array {
		return self::query_eligible_product_ids();
	}

	/**
	 * Captura la foto de cobertura entre los productos publicables y la tabla shadow de docs.
	 *
	 * @return array{valid: bool, eligible: array<int, int>, docs: array<int, int>, missing: array<int, int>, extra: array<int, int>, orphans: array<int, int>}
	 */
	public static function coverage_snapshot(): array {
		global $wpdb;

		$eligible = self::query_eligible_product_ids();
		$docs     = self::safe_get_col( 'SELECT post_id FROM ' . Install::docs_table() . Install::SHADOW_SUFFIX . ' ORDER BY post_id ASC' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL compuesto de identificadores canónicos del plugin.
		$tokens   = self::safe_get_col(
			'SELECT DISTINCT child.post_id FROM ' . Install::tokens_table() . Install::SHADOW_SUFFIX . ' child LEFT JOIN '
			. Install::docs_table() . Install::SHADOW_SUFFIX . ' parent ON parent.post_id = child.post_id WHERE parent.post_id IS NULL'
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$facets   = self::safe_get_col(
			'SELECT DISTINCT child.post_id FROM ' . Install::facets_table() . Install::SHADOW_SUFFIX . ' child LEFT JOIN '
			. Install::docs_table() . Install::SHADOW_SUFFIX . ' parent ON parent.post_id = child.post_id WHERE parent.post_id IS NULL'
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( null === $eligible || ! is_array( $docs ) || ! is_array( $tokens ) || ! is_array( $facets ) ) {
			return array(
				'valid'    => false,
				'eligible' => array(),
				'docs'     => array(),
				'missing'  => array(),
				'extra'    => array(),
				'orphans'  => array(),
			);
		}

		return self::coverage_from_sets( $eligible, $docs, array_merge( $tokens, $facets ) );
	}

	/**
	 * Diferencia exacta reusable por pruebas adversariales.
	 *
	 * @param array<int, mixed> $eligible IDs fuente.
	 * @param array<int, mixed> $docs     IDs sombra.
	 * @param array<int, mixed> $orphans  IDs token/faceta sin doc.
	 * @return array{valid: bool, eligible: array<int, int>, docs: array<int, int>, missing: array<int, int>, extra: array<int, int>, orphans: array<int, int>}
	 */
	public static function coverage_from_sets( array $eligible, array $docs, array $orphans ): array {
		$eligible = self::normalize_ids( $eligible );
		$docs     = self::normalize_ids( $docs );
		$orphans  = self::normalize_ids( $orphans );

		return array(
			'valid'    => true,
			'eligible' => $eligible,
			'docs'     => $docs,
			'missing'  => array_values( array_diff( $eligible, $docs ) ),
			'extra'    => array_values( array_diff( $docs, $eligible ) ),
			'orphans'  => $orphans,
		);
	}

	/**
	 * IDs que deben materializar repair antes de reconsultar cobertura.
	 *
	 * @param array<string, mixed> $coverage Foto de cobertura.
	 * @return array<int, int>
	 */
	public static function repair_ids_from_coverage( array $coverage ): array {
		return self::normalize_ids(
			array_merge(
				(array) ( $coverage['missing'] ?? array() ),
				(array) ( $coverage['extra'] ?? array() ),
				(array) ( $coverage['orphans'] ?? array() )
			)
		);
	}

	/**
	 * Receipt compacto de la última comprobación exacta.
	 *
	 * @param array<string, mixed> $coverage Foto de cobertura.
	 * @return array<string, int>
	 */
	public static function coverage_receipt( array $coverage ): array {
		return array(
			'eligible' => count( (array) ( $coverage['eligible'] ?? array() ) ),
			'docs'     => count( (array) ( $coverage['docs'] ?? array() ) ),
			'missing'  => count( (array) ( $coverage['missing'] ?? array() ) ),
			'extra'    => count( (array) ( $coverage['extra'] ?? array() ) ),
			'orphans'  => count( (array) ( $coverage['orphans'] ?? array() ) ),
		);
	}

	/**
	 * Universo fuente estable y ordenado.
	 *
	 * @return array<int, int>|null
	 */
	public static function query_eligible_product_ids(): ?array {
		global $wpdb;

		list( $where, $params ) = self::eligible_products_where();

		$ids = self::safe_get_col(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- predicado compartido.
				"SELECT ID FROM {$wpdb->posts} WHERE {$where} ORDER BY ID ASC",
				...$params
			)
		);

		return is_array( $ids ) ? self::normalize_ids( $ids ) : null;
	}

	/**
	 * get_col fail-closed: distingue vacío de fallo de lectura SQL.
	 *
	 * @param string $sql Consulta interna ya preparada.
	 * @return array<int, mixed>|null
	 */
	public static function safe_get_col( string $sql ): ?array {
		global $wpdb;

		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_col( $sql );

		return is_array( $rows ) && '' === (string) $wpdb->last_error ? $rows : null;
	}

	/**
	 * Normaliza IDs positivos únicos y ordenados.
	 *
	 * @param array<int, mixed> $ids IDs.
	 * @return array<int, int>
	 */
	public static function normalize_ids( array $ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ), static fn( int $id ): bool => $id > 0 ) ) );
		sort( $ids, SORT_NUMERIC );
		return $ids;
	}
}
