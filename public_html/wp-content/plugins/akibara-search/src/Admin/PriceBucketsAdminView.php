<?php
/**
 * PriceBucketsAdminView — renderizado y procesamiento de rangos de precio en el panel de búsqueda.
 *
 * @package Akibara\Search\Admin
 */

declare( strict_types = 1 );

namespace Akibara\Search\Admin;

defined( 'ABSPATH' ) || exit;

use Akibara\Search\FacetRegistry;
use Akibara\Search\FacetService;
use Akibara\Search\Install;
use Akibara\Search\PriceBuckets;
use Akibara\Search\SearchAdminSection;

/**
 * Vista y helpers para la configuración de tramos de precio.
 */
final class PriceBucketsAdminView {

	public static function render_precios( SearchAdminSection $admin ): void {
		$has     = FacetRegistry::has_precio_buckets();
		$buckets = $has ? FacetRegistry::precio_buckets() : array();
		$counts  = $has ? self::precio_row_counts( $buckets ) : array();
		$auto    = PriceBuckets::is_auto();

		$max_rows = 8;
		$rows     = $buckets;
		for ( $i = count( $buckets ); $i < $max_rows; $i++ ) {
			$rows[] = array(
				'min'   => null,
				'max'   => null,
				'label' => '',
			);
		}

		$admin->render_template(
			'tab-precios.php',
			array(
				'has'      => $has,
				'buckets'  => $buckets,
				'counts'   => $counts,
				'auto'     => $auto,
				'rows'     => $rows,
				'real'     => count( $buckets ),
				'max_rows' => $max_rows,
			)
		);
	}

	/**
	 * Counts por bucket para el preview del editor — la MISMA base que ve el
	 * comprador (espejo de facetas + visible=1 + publish), así el preview no
	 * miente respecto del front.
	 *
	 * @param array<int, array{min: int|null, max: int|null, label: string}> $buckets Buckets vigentes.
	 * @return array<int, int> Índice de bucket → count.
	 */
	public static function precio_row_counts( array $buckets ): array {
		global $wpdb;
		if ( empty( $buckets ) || ! isset( $wpdb ) ) {
			return array();
		}
		$facets = Install::facets_table();
		$docs   = Install::docs_table();

		$selects = array();
		$params  = array();
		foreach ( $buckets as $i => $bucket ) {
			$cond = array();
			if ( null !== $bucket['min'] ) {
				$cond[]   = 'fx.value_num >= %f';
				$params[] = (float) $bucket['min'];
			}
			if ( null !== $bucket['max'] ) {
				$cond[]   = 'fx.value_num < %f';
				$params[] = (float) $bucket['max'];
			}
			$test      = empty( $cond ) ? '1=1' : implode( ' AND ', $cond );
			$selects[] = "COUNT(DISTINCT CASE WHEN {$test} THEN fx.post_id END) AS b{$i}";
		}

		$sql = 'SELECT ' . implode( ', ', $selects ) . "
			FROM {$facets} fx
			JOIN {$docs} d ON d.post_id = fx.post_id AND d.visible = 1
			JOIN {$wpdb->posts} p ON p.ID = d.post_id AND p.post_status = 'publish'
			WHERE fx.facet = 'precio'";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- preparado acá.
		$row = $wpdb->get_row( empty( $params ) ? $sql : $wpdb->prepare( $sql, ...$params ), ARRAY_A );

		$out = array();
		foreach ( $buckets as $i => $bucket ) {
			$out[ $i ] = (int) ( $row[ 'b' . $i ] ?? 0 );
		}
		return $out;
	}

	/**
	 * Guarda los rangos de precio (option `akb_search_precio_buckets`). Valida
	 * con `FacetRegistry::validate_precio_buckets()` — si falla NO persiste (la
	 * faceta nunca queda en estado inválido). La etiqueta SIEMPRE se deriva del
	 * rango (el `precio_label` del POST se ignora), así no puede mentir. Si
	 * cambió un mínimo/máximo respecto de lo persistido, el modo pasa a MANUAL
	 * (el reindex ya no lo pisa); «Recalcular» persiste el modo AUTO y re-deriva
	 * del catálogo. Todo camino que persiste pasa por el outbox pre/post de
	 * SemanticPublication (los counts no incluyen los buckets en su clave).
	 *
	 * @return void
	 */
	public static function build_precio_rows( array $mins, array $maxs ): array {
		$count = max( count( $mins ), count( $maxs ) );
		$rows  = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$raw_min = isset( $mins[ $i ] ) ? trim( (string) $mins[ $i ] ) : '';
			$raw_max = isset( $maxs[ $i ] ) ? trim( (string) $maxs[ $i ] ) : '';

			if ( '' === $raw_min && '' === $raw_max ) {
				continue;
			}

			$min   = self::parse_price_bound( $raw_min );
			$max   = self::parse_price_bound( $raw_max );
			$label = ( ! is_string( $min ) && ! is_string( $max ) )
				? PriceBuckets::derive_label( $min, $max )
				: '';

			$rows[] = array(
				'min'   => $min,
				'max'   => $max,
				'label' => $label,
			);
		}
		return $rows;
	}

	/**
	 * @param string $raw Valor crudo, ya trimmeado.
	 * @return int|string|null
	 */
	public static function parse_price_bound( string $raw ) {
		if ( '' === $raw ) {
			return null;
		}
		// Entero no-negativo con techo de sanidad: el máximo real del catálogo
		// ronda los $82.000, así que $10.000.000 es holgado. El límite de 8
		// dígitos garantiza que el cast a int no desborde antes de comparar
		// (sin esto, un string > PHP_INT_MAX clampeaba en silencio a un bucket
		// degenerado con label absurdo). Fuera de rango (decimal, negativo,
		// texto o absurdamente grande) → se devuelve el string crudo para que
		// el validador rechace el set; nunca coercer basura a int en silencio.
		if ( 1 === preg_match( '/^\d+$/', $raw ) && strlen( $raw ) <= 8 && (int) $raw <= SearchAdminSection::PRICE_BOUND_MAX ) {
			return (int) $raw;
		}
		return $raw;
	}

	/**
	 * Persiste un grupo y relee cada valor exacto. `update_option()` devuelve
	 * false tanto en no-op como cuando un pre-hook fail-closed conserva el valor
	 * anterior; por eso el booleano solo no puede autorizar un notice de éxito.
	 * Ante un fallo se intenta restaurar todo el grupo en orden inverso.
	 *
	 * `$releases` son options cuya fila se borra para que su lector caiga al
	 * valor de fábrica. Van en el mismo grupo: la misma foto antes, la misma
	 * relectura después y la misma restauración si algo falla —también de lo
	 * escrito antes—. Una fila que ya no existe es un no-op legítimo.
	 *
	 * @param array<string, mixed> $values   Valores esperados por option.
	 * @param array<int, string>   $releases Options que tienen que quedar sin fila.
	 * @return bool
	 */

}
