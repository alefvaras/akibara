<?php
/**
 * Auto-escáner de escalas por editorial, discrepancias de series (pa_serie)
 * y candidatos agotados rescatables.
 *
 * @package Akibara\Business\Modules\PricingMatrix
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\PricingMatrix;

use Akibara\Business\Modules\Stock\StockStatus;

defined( 'ABSPATH' ) || exit;

final class ScaleScanner {

	/**
	 * Ejecuta la consulta 1.4 del Auto-Escáner de Escalas por Editorial y la enriquece
	 * con el conteo de productos comprometidos en órdenes activas, series asociadas y reglas.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function scan_editorial_scales(): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					t_brand.term_id AS brand_id,
					t_brand.name AS editorial,
					t_brand.slug AS editorial_slug,
					COALESCE(tm_country.meta_value, '') AS pais_codigo,
					CAST(pm_reg.meta_value AS UNSIGNED) AS escala_precio_regular,
					COUNT(DISTINCT p.ID) AS total_tomos,
					SUM(CASE WHEN pm_st.meta_value = %s THEN 1 ELSE 0 END) AS en_stock,
					SUM(CASE WHEN pm_st.meta_value = %s THEN 1 ELSE 0 END) AS a_pedido,
					SUM(CASE WHEN pm_st.meta_value = %s THEN 1 ELSE 0 END) AS agotados,
					MIN(CAST(NULLIF(pm_sale.meta_value, '') AS UNSIGNED)) AS min_sale_actual,
					MAX(CAST(NULLIF(pm_sale.meta_value, '') AS UNSIGNED)) AS max_sale_actual
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->term_relationships} tr_brand ON p.ID = tr_brand.object_id
				INNER JOIN {$wpdb->term_taxonomy} tt_brand
					ON tr_brand.term_taxonomy_id = tt_brand.term_taxonomy_id AND tt_brand.taxonomy = 'product_brand'
				INNER JOIN {$wpdb->terms} t_brand ON tt_brand.term_id = t_brand.term_id
				LEFT JOIN {$wpdb->termmeta} tm_country
					ON t_brand.term_id = tm_country.term_id AND tm_country.meta_key = 'country'
				INNER JOIN {$wpdb->postmeta} pm_reg
					ON p.ID = pm_reg.post_id AND pm_reg.meta_key = '_regular_price' AND pm_reg.meta_value != ''
				LEFT JOIN {$wpdb->postmeta} pm_sale
					ON p.ID = pm_sale.post_id AND pm_sale.meta_key = '_sale_price'
				LEFT JOIN {$wpdb->postmeta} pm_st
					ON p.ID = pm_st.post_id AND pm_st.meta_key = %s
				WHERE p.post_type = 'product'
				  AND p.post_status = 'publish'
				GROUP BY t_brand.term_id, t_brand.name, t_brand.slug, tm_country.meta_value, CAST(pm_reg.meta_value AS UNSIGNED)
				ORDER BY t_brand.name ASC, escala_precio_regular ASC",
				StockStatus::IN_STOCK,
				StockStatus::ON_BACKORDER,
				StockStatus::OUT_OF_STOCK,
				'_stock_status'
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return array();
		}

		$scale_meta = self::build_scale_meta_map();
		$result     = array();

		foreach ( $rows as $row ) {
			$brand_id       = (int) ( $row['brand_id'] ?? 0 );
			$editorial_slug = (string) ( $row['editorial_slug'] ?? '' );
			$scale_reg      = (int) ( $row['escala_precio_regular'] ?? 0 );
			$key            = $brand_id . ':' . $scale_reg;

			$meta        = $scale_meta[ $key ] ?? array(
				'locked_pids'   => array(),
				'locked_orders' => array(),
				'series'        => array(),
			);
			$series_list = array_keys( $meta['series'] );
			sort( $series_list, SORT_NATURAL | SORT_FLAG_CASE );

			$rule = PricingRulesRepository::resolve_rule_for_scale( $editorial_slug, $scale_reg );

			$result[] = array(
				'brand_id'              => $brand_id,
				'editorial'             => (string) ( $row['editorial'] ?? '' ),
				'editorial_slug'        => $editorial_slug,
				'pais_codigo'           => (string) ( $row['pais_codigo'] ?? '' ),
				'escala_precio_regular' => $scale_reg,
				'total_tomos'           => (int) ( $row['total_tomos'] ?? 0 ),
				'en_stock'              => (int) ( $row['en_stock'] ?? 0 ),
				'a_pedido'              => (int) ( $row['a_pedido'] ?? 0 ),
				'agotados'              => (int) ( $row['agotados'] ?? 0 ),
				'min_sale_actual'       => null !== $row['min_sale_actual'] ? (int) $row['min_sale_actual'] : null,
				'max_sale_actual'       => null !== $row['max_sale_actual'] ? (int) $row['max_sale_actual'] : null,
				'comprometidos_count'   => count( $meta['locked_pids'] ),
				'comprometidos_pids'    => array_keys( $meta['locked_pids'] ),
				'comprometidos_orders'  => array_keys( $meta['locked_orders'] ),
				'series_count'          => count( $series_list ),
				'series_names'          => $series_list,
				'nuevo_precio_regular'  => (int) $rule['new_regular_price'],
				'nuevo_precio_a_pedido' => (int) $rule['sale_price'],
				'discount_pct'          => (float) $rule['discount_pct'],
				'rounding_mode'         => (string) $rule['rounding_mode'],
				'manual_sale_price'     => $rule['manual_sale_price'],
			);
		}

		return $result;
	}

	/**
	 * Construye en una sola consulta el mapa de series y productos bloqueados por (brand_id:escala).
	 *
	 * @return array<string, array{locked_pids: array<int, true>, locked_orders: array<int, true>, series: array<string, true>}>
	 */
	private static function build_scale_meta_map(): array {
		global $wpdb;

		$product_brand_scale_sql = "
		SELECT
			p.ID AS product_id,
			tt_brand.term_id AS brand_id,
			CAST(pm_reg.meta_value AS UNSIGNED) AS escala_precio_regular,
			t_serie.name AS serie_name
		FROM {$wpdb->posts} p
		INNER JOIN {$wpdb->term_relationships} tr_brand ON p.ID = tr_brand.object_id
		INNER JOIN {$wpdb->term_taxonomy} tt_brand
			ON tr_brand.term_taxonomy_id = tt_brand.term_taxonomy_id AND tt_brand.taxonomy = 'product_brand'
		INNER JOIN {$wpdb->postmeta} pm_reg
			ON p.ID = pm_reg.post_id AND pm_reg.meta_key = '_regular_price' AND pm_reg.meta_value != ''
		LEFT JOIN {$wpdb->term_relationships} tr_serie ON p.ID = tr_serie.object_id
		LEFT JOIN {$wpdb->term_taxonomy} tt_serie
			ON tr_serie.term_taxonomy_id = tt_serie.term_taxonomy_id AND tt_serie.taxonomy = 'pa_serie'
		LEFT JOIN {$wpdb->terms} t_serie ON tt_serie.term_id = t_serie.term_id
		WHERE p.post_type = 'product'
		  AND p.post_status = 'publish'
		";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Consulta interna solo usa prefijos de $wpdb.
		$prod_rows = $wpdb->get_results( $product_brand_scale_sql, ARRAY_A );
		if ( ! is_array( $prod_rows ) ) {
			return array();
		}

		$locked_ids = array_fill_keys( OrderLockGuard::get_locked_product_ids(), true );
		$locked_map = OrderLockGuard::get_locked_map_by_product();
		$scale_meta = array();

		foreach ( $prod_rows as $pr ) {
			$pid   = (int) ( $pr['product_id'] ?? 0 );
			$bid   = (int) ( $pr['brand_id'] ?? 0 );
			$scale = (int) ( $pr['escala_precio_regular'] ?? 0 );
			$sname = trim( (string) ( $pr['serie_name'] ?? '' ) );
			$key   = $bid . ':' . $scale;

			if ( ! isset( $scale_meta[ $key ] ) ) {
				$scale_meta[ $key ] = array(
					'locked_pids'   => array(),
					'locked_orders' => array(),
					'series'        => array(),
				);
			}

			if ( '' !== $sname ) {
				$scale_meta[ $key ]['series'][ $sname ] = true;
			}

			if ( isset( $locked_ids[ $pid ] ) ) {
				$scale_meta[ $key ]['locked_pids'][ $pid ] = true;
				self::append_locked_order_ids( $scale_meta[ $key ]['locked_orders'], $locked_map[ $pid ] ?? array() );
			}
		}

		return $scale_meta;
	}

	/**
	 * Agrega los order_id asociados a un producto bloqueado.
	 *
	 * @param array<int, true>            $target     Mapa acumulador de order_ids.
	 * @param list<array<string, mixed>>  $order_rows Filas de órdenes del producto.
	 * @return void
	 */
	private static function append_locked_order_ids( array &$target, array $order_rows ): void {
		foreach ( $order_rows as $order_row ) {
			$oid = (int) ( $order_row['order_id'] ?? 0 );
			if ( $oid > 0 ) {
				$target[ $oid ] = true;
			}
		}
	}

	/**
	 * Obtiene los IDs de productos publicados de una editorial y escala de precio regular.
	 *
	 * @param int $brand_id      ID del término product_brand.
	 * @param int $scale_regular Escala de precio regular.
	 * @return list<int>
	 */
	public static function get_product_ids_for_scale( int $brand_id, int $scale_regular ): array {
		global $wpdb;

		if ( $brand_id <= 0 || $scale_regular <= 0 ) {
			return array();
		}

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT p.ID
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->term_relationships} tr_brand ON p.ID = tr_brand.object_id
				INNER JOIN {$wpdb->term_taxonomy} tt_brand
					ON tr_brand.term_taxonomy_id = tt_brand.term_taxonomy_id
					AND tt_brand.taxonomy = 'product_brand'
					AND tt_brand.term_id = %d
				INNER JOIN {$wpdb->postmeta} pm_reg
					ON p.ID = pm_reg.post_id
					AND pm_reg.meta_key = '_regular_price'
					AND CAST(pm_reg.meta_value AS UNSIGNED) = %d
				WHERE p.post_type = 'product'
				  AND p.post_status = 'publish'
				ORDER BY p.ID ASC",
				$brand_id,
				$scale_regular
			)
		);

		return is_array( $ids ) ? array_values( array_map( 'intval', $ids ) ) : array();
	}

	/**
	 * Detecta series (`pa_serie`) que tienen tomos en distintas escalas de precio
	 * dentro de la misma editorial, ignorando `_akb_variante_tomo = 'edicion_especial'` y packs.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function scan_series_discrepancies(): array {
		global $wpdb;

		$sql = "
		SELECT
			p.ID AS product_id,
			p.post_title,
			t_serie.term_id AS serie_id,
			t_serie.name AS serie_name,
			t_serie.slug AS serie_slug,
			t_brand.term_id AS brand_id,
			t_brand.name AS editorial,
			t_brand.slug AS editorial_slug,
			CAST(pm_reg.meta_value AS UNSIGNED) AS regular_price,
			COALESCE(pm_var.meta_value, '') AS variante_tomo
		FROM {$wpdb->posts} p
		INNER JOIN {$wpdb->term_relationships} tr_serie ON p.ID = tr_serie.object_id
		INNER JOIN {$wpdb->term_taxonomy} tt_serie
			ON tr_serie.term_taxonomy_id = tt_serie.term_taxonomy_id AND tt_serie.taxonomy = 'pa_serie'
		INNER JOIN {$wpdb->terms} t_serie ON tt_serie.term_id = t_serie.term_id
		INNER JOIN {$wpdb->term_relationships} tr_brand ON p.ID = tr_brand.object_id
		INNER JOIN {$wpdb->term_taxonomy} tt_brand
			ON tr_brand.term_taxonomy_id = tt_brand.term_taxonomy_id AND tt_brand.taxonomy = 'product_brand'
		INNER JOIN {$wpdb->terms} t_brand ON tt_brand.term_id = t_brand.term_id
		INNER JOIN {$wpdb->postmeta} pm_reg
			ON p.ID = pm_reg.post_id AND pm_reg.meta_key = '_regular_price' AND pm_reg.meta_value != ''
		LEFT JOIN {$wpdb->postmeta} pm_var
			ON p.ID = pm_var.post_id AND pm_var.meta_key = '_akb_variante_tomo'
		WHERE p.post_type = 'product'
		  AND p.post_status = 'publish'
		";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Consulta solo usa prefijos de $wpdb.
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$locked_set = array_fill_keys( OrderLockGuard::get_locked_product_ids(), true );
		$grouped    = array();

		foreach ( $rows as $row ) {
			if ( ! self::is_standard_volume_row( $row ) ) {
				continue;
			}

			$serie_id = (int) $row['serie_id'];
			$brand_id = (int) $row['brand_id'];
			$reg      = (int) $row['regular_price'];
			$pid      = (int) $row['product_id'];
			$key      = $serie_id . ':' . $brand_id;

			if ( ! isset( $grouped[ $key ] ) ) {
				$grouped[ $key ] = array(
					'serie_id'       => $serie_id,
					'serie_name'     => (string) ( $row['serie_name'] ?? '' ),
					'serie_slug'     => (string) ( $row['serie_slug'] ?? '' ),
					'brand_id'       => $brand_id,
					'editorial'      => (string) ( $row['editorial'] ?? '' ),
					'editorial_slug' => (string) ( $row['editorial_slug'] ?? '' ),
					'scales'         => array(),
					'products'       => array(),
					'locked_pids'    => array(),
				);
			}

			$grouped[ $key ]['scales'][ $reg ]   = ( $grouped[ $key ]['scales'][ $reg ] ?? 0 ) + 1;
			$grouped[ $key ]['products'][ $pid ] = array(
				'product_id'    => $pid,
				'title'         => (string) ( $row['post_title'] ?? '' ),
				'regular_price' => $reg,
				'locked'        => isset( $locked_set[ $pid ] ),
			);
			if ( isset( $locked_set[ $pid ] ) ) {
				$grouped[ $key ]['locked_pids'][ $pid ] = true;
			}
		}

		return self::format_series_discrepancies( $grouped );
	}

	/**
	 * Determina si una fila de producto corresponde a un tomo regular auditable por escala de serie.
	 *
	 * @param array<string, mixed> $row Fila SQL.
	 * @return bool
	 */
	private static function is_standard_volume_row( array $row ): bool {
		if ( 'edicion_especial' === (string) ( $row['variante_tomo'] ?? '' ) ) {
			return false;
		}
		$title = (string) ( $row['post_title'] ?? '' );
		if ( 1 === preg_match( '/\b(pack|box\s*set|cofre|starter\s*pack|artbook|el\s*arte\s*de\b|libro\s*de\s*arte|fanbook|character\s*book|gu[ií]a\s*oficial|edici[oó]n\s*especial|edici[oó]n\s*coleccionista|portada\s*alternativa|variant\s*cover)\b/iu', $title ) ) {
			return false;
		}

		return (int) ( $row['serie_id'] ?? 0 ) > 0
			&& (int) ( $row['brand_id'] ?? 0 ) > 0
			&& (int) ( $row['regular_price'] ?? 0 ) > 0;
	}

	/**
	 * Filtra y ordena las series con más de una escala de precio regular.
	 *
	 * @param array<string, array<string, mixed>> $grouped Series agrupadas.
	 * @return list<array<string, mixed>>
	 */
	private static function format_series_discrepancies( array $grouped ): array {
		$discrepancies = array();
		foreach ( $grouped as $entry ) {
			if ( count( $entry['scales'] ) <= 1 ) {
				continue;
			}

			$scales = $entry['scales'];
			uksort(
				$scales,
				static function ( int $a, int $b ) use ( $scales ): int {
					return $scales[ $a ] !== $scales[ $b ]
						? ( $scales[ $b ] <=> $scales[ $a ] )
						: ( $b <=> $a );
				}
			);
			$dominant_scale = (int) array_key_first( $scales );
			ksort( $entry['scales'], SORT_NUMERIC );

			$sum           = array_sum( array_map( static fn( array $p ): int => (int) $p['regular_price'], $entry['products'] ) );
			$count         = count( $entry['products'] );
			$average_price = (int) ( round( ( $sum / max( $count, 1 ) ) / 100 ) * 100 );

			$discrepancies[] = array(
				'serie_id'       => $entry['serie_id'],
				'serie_name'     => $entry['serie_name'],
				'serie_slug'     => $entry['serie_slug'],
				'brand_id'       => $entry['brand_id'],
				'editorial'      => $entry['editorial'],
				'editorial_slug' => $entry['editorial_slug'],
				'scales'         => $entry['scales'],
				'dominant_scale' => $dominant_scale,
				'average_price'  => $average_price,
				'total_tomos'    => count( $entry['products'] ),
				'locked_count'   => count( $entry['locked_pids'] ),
				'product_ids'    => array_keys( $entry['products'] ),
			);
		}

		usort(
			$discrepancies,
			static fn( array $a, array $b ): int => strcasecmp( $a['editorial'] . ' ' . $a['serie_name'], $b['editorial'] . ' ' . $b['serie_name'] )
		);

		return $discrepancies;
	}

	/**
	 * Escanea productos en `outofstock` elegibles para rescate a `onbackorder`
	 * (`_akb_eta_type = 'auto'` o vacío, excluyendo siempre `OrderLockGuard::get_locked_product_ids()`).
	 *
	 * @param int|null $brand_id Filtrar por ID de editorial opcional.
	 * @return list<array<string, mixed>>
	 */
	public static function scan_rescuable_outofstock( ?int $brand_id = null ): array {
		global $wpdb;

		$locked_ids  = OrderLockGuard::get_locked_product_ids();
		$locked_map  = array_fill_keys( $locked_ids, true );
		$brand_where = ( null !== $brand_id && $brand_id > 0 )
			? $wpdb->prepare( ' AND tt_brand.term_id = %d', $brand_id )
			: '';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $brand_where viene de $wpdb->prepare() o cadena vacía.
		$sql = $wpdb->prepare(
			"SELECT
				p.ID AS product_id,
				p.post_title,
				t_brand.term_id AS brand_id,
				t_brand.name AS editorial,
				t_brand.slug AS editorial_slug,
				CAST(pm_reg.meta_value AS UNSIGNED) AS regular_price,
				COALESCE(pm_eta.meta_value, 'auto') AS eta_type
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->term_relationships} tr_brand ON p.ID = tr_brand.object_id
			INNER JOIN {$wpdb->term_taxonomy} tt_brand
				ON tr_brand.term_taxonomy_id = tt_brand.term_taxonomy_id AND tt_brand.taxonomy = 'product_brand'{$brand_where}
			INNER JOIN {$wpdb->terms} t_brand ON tt_brand.term_id = t_brand.term_id
			INNER JOIN {$wpdb->postmeta} pm_st
				ON p.ID = pm_st.post_id AND pm_st.meta_key = %s AND pm_st.meta_value = %s
			INNER JOIN {$wpdb->postmeta} pm_reg
				ON p.ID = pm_reg.post_id AND pm_reg.meta_key = '_regular_price' AND pm_reg.meta_value != ''
			LEFT JOIN {$wpdb->postmeta} pm_eta
				ON p.ID = pm_eta.post_id AND pm_eta.meta_key = '_akb_eta_type'
			WHERE p.post_type = 'product'
			  AND p.post_status = 'publish'
			ORDER BY t_brand.name ASC, p.post_title ASC",
			'_stock_status',
			StockStatus::OUT_OF_STOCK
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql contiene únicamente tablas de $wpdb y $brand_where ya preparado.
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$candidates = array();
		foreach ( $rows as $row ) {
			$pid = (int) ( $row['product_id'] ?? 0 );
			if ( $pid <= 0 || isset( $locked_map[ $pid ] ) ) {
				continue;
			}
			$eta_type = trim( (string) ( $row['eta_type'] ?? '' ) );
			if ( '' !== $eta_type && 'auto' !== $eta_type ) {
				continue;
			}
			$reg  = (int) ( $row['regular_price'] ?? 0 );
			$slug = (string) ( $row['editorial_slug'] ?? '' );
			if ( $reg <= 0 ) {
				continue;
			}

			$rule = PricingRulesRepository::resolve_rule_for_scale( $slug, $reg );
			$candidates[] = array(
				'product_id'           => $pid,
				'title'                => (string) ( $row['post_title'] ?? '' ),
				'brand_id'             => (int) ( $row['brand_id'] ?? 0 ),
				'editorial'            => (string) ( $row['editorial'] ?? '' ),
				'editorial_slug'       => $slug,
				'regular_price'        => $reg,
				'proposed_sale_price'  => (int) $rule['sale_price'],
				'eta_type'             => '' === $eta_type ? 'auto' : $eta_type,
			);
		}

		return $candidates;
	}
}
