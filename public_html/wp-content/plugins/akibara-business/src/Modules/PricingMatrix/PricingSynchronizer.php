<?php
/**
 * Sincronizador transaccional de precios y estados por escala, serie y rescate de agotados.
 *
 * Cumple las Reglas de Oro:
 * - Usa métodos CRUD de WooCommerce ($product->set_regular_price(), set_sale_price(), save())
 *   y actualiza wp_wc_product_meta_lookup + limpia transients/object cache.
 * - Ejecuta dentro de BatchGuard (0 tormenta de hooks AS, 1 sync de akibara-search al final).
 * - Respeta estrictamente OrderLockGuard (tablas de órdenes 100% solo lectura, stock/timestamps
 *   de productos comprometidos 100% intocables).
 * - Garantiza cero filas duplicadas en wp_postmeta para metadatos propios.
 *
 * @package Akibara\Business\Modules\PricingMatrix
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\PricingMatrix;

use Akibara\Business\Modules\Stock\StockStatus;
use WC_Product;

defined( 'ABSPATH' ) || exit;

final class PricingSynchronizer {

	/**
	 * Claves de postmeta cuyas filas duplicadas deben prevenirse y podarse siempre.
	 *
	 * @var list<string>
	 */
	public const DEDUP_META_KEYS = array(
		'_akb_onbackorder_at',
		'_akb_pdp_specs_generation',
		'_akb_tramo_prev_regular',
		'_akb_tramo_prev_sale',
		'rank_math_internal_links_processed',
	);

	/**
	 * Actualiza un metadato garantizando que jamás exista más de 1 fila en wp_postmeta.
	 *
	 * @param int    $post_id    ID del producto.
	 * @param string $meta_key   Clave de meta.
	 * @param string $meta_value Valor a guardar.
	 * @return void
	 */
	public static function update_unique_post_meta( int $post_id, string $meta_key, string $meta_value ): void {
		if ( $post_id <= 0 || '' === $meta_key ) {
			return;
		}

		update_post_meta( $post_id, $meta_key, $meta_value );
		self::dedup_post_meta_keys( $post_id, array( $meta_key ) );
	}

	/**
	 * Elimina cualquier fila duplicada en wp_postmeta conservando únicamente MAX(meta_id).
	 *
	 * @param int          $post_id   ID del producto.
	 * @param list<string> $meta_keys Claves a desduplicar.
	 * @return void
	 */
	public static function dedup_post_meta_keys( int $post_id, array $meta_keys = self::DEDUP_META_KEYS ): void {
		global $wpdb;

		if ( $post_id <= 0 || empty( $meta_keys ) ) {
			return;
		}

		$escaped_keys = "'" . implode( "','", array_map( 'esc_sql', $meta_keys ) ) . "'";
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $escaped_keys proviene de array_map('esc_sql').
		$dups = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) - COUNT(DISTINCT meta_key)
				FROM {$wpdb->postmeta}
				WHERE post_id = %d AND meta_key IN ({$escaped_keys})",
				$post_id
			)
		);

		if ( $dups > 0 ) {
			$wpdb->query(
				$wpdb->prepare(
					"DELETE pm1 FROM {$wpdb->postmeta} pm1
					INNER JOIN {$wpdb->postmeta} pm2
						ON pm1.post_id = pm2.post_id
						AND pm1.meta_key = pm2.meta_key
						AND pm1.meta_id < pm2.meta_id
					WHERE pm1.post_id = %d
					  AND pm1.meta_key IN ({$escaped_keys})",
					$post_id
				)
			);
			wp_cache_delete( $post_id, 'post_meta' );
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Elimina en una sola consulta cualquier fila duplicada en wp_postmeta para un lote de productos.
	 *
	 * @param list<int>    $post_ids  IDs de productos.
	 * @param list<string> $meta_keys Claves a desduplicar.
	 * @return void
	 */
	public static function dedup_post_meta_keys_batch( array $post_ids, array $meta_keys = self::DEDUP_META_KEYS ): void {
		global $wpdb;

		$post_ids = array_values( array_unique( array_filter( array_map( 'intval', $post_ids ), static fn( int $id ): bool => $id > 0 ) ) );
		if ( empty( $post_ids ) || empty( $meta_keys ) ) {
			return;
		}

		$in_ids       = implode( ',', $post_ids );
		$escaped_keys = "'" . implode( "','", array_map( 'esc_sql', $meta_keys ) ) . "'";

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $in_ids son enteros y $escaped_keys usa esc_sql.
		$dups = (int) $wpdb->get_var(
			"SELECT COUNT(*) - COUNT(DISTINCT CONCAT(post_id, ':', meta_key))
			FROM {$wpdb->postmeta}
			WHERE post_id IN ({$in_ids})
			  AND meta_key IN ({$escaped_keys})"
		);

		if ( $dups > 0 ) {
			$wpdb->query(
				"DELETE pm1 FROM {$wpdb->postmeta} pm1
				INNER JOIN {$wpdb->postmeta} pm2
					ON pm1.post_id = pm2.post_id
					AND pm1.meta_key = pm2.meta_key
					AND pm1.meta_id < pm2.meta_id
				WHERE pm1.post_id IN ({$in_ids})
				  AND pm1.meta_key IN ({$escaped_keys})"
			);
			foreach ( $post_ids as $pid ) {
				wp_cache_delete( $pid, 'post_meta' );
			}
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Aplica el cambio de precio a un producto individual respetando el blindaje de órdenes.
	 *
	 * @param int                                     $product_id            ID del producto.
	 * @param int                                     $target_regular        Nuevo precio regular CLP.
	 * @param int                                     $target_backorder_sale Nuevo precio oferta A Pedido CLP.
	 * @param bool                                    $protect_active_orders Si true, omite productos en get_locked_product_ids().
	 * @param array<int, true>|null                   $locked_set            Mapa precalculado de IDs bloqueados.
	 * @param array<int, list<array<string,mixed>>>|null $locked_orders_map  Mapa precalculado de órdenes por producto.
	 * @param bool                                    $dry_run               Si true, solo simula sin escribir.
	 * @return array<string, mixed>
	 */
	public static function apply_product_price(
		int $product_id,
		int $target_regular,
		int $target_backorder_sale,
		bool $protect_active_orders = true,
		?array $locked_set = null,
		?array $locked_orders_map = null,
		bool $dry_run = false
	): array {
		if ( null === $locked_set ) {
			$locked_set = array_fill_keys( OrderLockGuard::get_locked_product_ids(), true );
		}
		if ( null === $locked_orders_map ) {
			$locked_orders_map = OrderLockGuard::get_locked_map_by_product();
		}

		$is_locked = isset( $locked_set[ $product_id ] );
		$orders    = $locked_orders_map[ $product_id ] ?? array();

		if ( $is_locked && $protect_active_orders ) {
			return array(
				'status'     => 'skipped_locked',
				'product_id' => $product_id,
				'locked'     => true,
				'orders'     => $orders,
			);
		}

		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
		if ( ! $product instanceof WC_Product ) {
			return array(
				'status'     => 'not_found',
				'product_id' => $product_id,
				'locked'     => $is_locked,
				'orders'     => $orders,
			);
		}

		$old_regular  = (int) round( (float) $product->get_regular_price( 'edit' ) );
		$old_sale_raw = (string) $product->get_sale_price( 'edit' );
		$old_sale     = '' !== $old_sale_raw ? (int) round( (float) $old_sale_raw ) : null;
		$stock_status = (string) $product->get_stock_status( 'edit' );
		$new_regular  = $target_regular > 0 ? $target_regular : $old_regular;
		$new_sale_str = self::compute_target_sale_string(
			$product_id,
			$stock_status,
			$new_regular,
			$target_backorder_sale,
			$old_sale_raw
		);

		$new_sale_int = '' !== $new_sale_str ? (int) $new_sale_str : null;
		$changed      = ( $new_regular !== $old_regular ) || ( $new_sale_int !== $old_sale );

		if ( $dry_run || ! $changed ) {
			return array(
				'status'       => $changed ? 'would_update' : 'unchanged',
				'product_id'   => $product_id,
				'title'        => $product->get_name(),
				'stock_status' => $stock_status,
				'old_regular'  => $old_regular,
				'new_regular'  => $new_regular,
				'old_sale'     => $old_sale,
				'new_sale'     => $new_sale_int,
				'locked'       => $is_locked,
				'orders'       => $orders,
			);
		}

		$exec_fn = static function () use ( $product, $product_id, $old_regular, $old_sale_raw, $new_regular, $new_sale_str ): void {
			$product->update_meta_data( '_akb_tramo_prev_regular', (string) $old_regular );
			$product->update_meta_data( '_akb_tramo_prev_sale', $old_sale_raw );

			// Regla Inquebrantable 2: cuando se actualiza precio, SOLO se toca _regular_price y _sale_price.
			$product->set_regular_price( (string) $new_regular );
			$product->set_sale_price( $new_sale_str );
			$product->save();

			BatchGuard::mark_product_modified( $product_id, false );
		};

		if ( BatchGuard::is_active() ) {
			$exec_fn();
		} else {
			BatchGuard::run( $exec_fn, false );
		}

		return array(
			'status'       => 'updated',
			'product_id'   => $product_id,
			'title'        => $product->get_name(),
			'stock_status' => $stock_status,
			'old_regular'  => $old_regular,
			'new_regular'  => $new_regular,
			'old_sale'     => $old_sale,
			'new_sale'     => $new_sale_int,
			'locked'       => $is_locked,
			'orders'       => $orders,
		);
	}

	/**
	 * Determina el `_sale_price` objetivo según estado de stock y candados de campaña/manuales.
	 *
	 * @param int    $product_id            ID del producto.
	 * @param string $stock_status          Estado de stock actual.
	 * @param int    $new_regular           Nuevo precio regular.
	 * @param int    $target_backorder_sale Precio oferta objetivo A Pedido.
	 * @param string $old_sale_raw          Precio oferta previo crudo.
	 * @return string
	 */
	private static function compute_target_sale_string(
		int $product_id,
		string $stock_status,
		int $new_regular,
		int $target_backorder_sale,
		string $old_sale_raw
	): string {
		$eta_type = (string) get_post_meta( $product_id, '_akb_eta_type', true );
		if ( StockStatus::ON_BACKORDER === $stock_status && 'disabled' !== $eta_type ) {
			return ( $target_backorder_sale > 0 && $target_backorder_sale < $new_regular )
				? (string) $target_backorder_sale
				: '';
		}

		if ( StockStatus::IN_STOCK === $stock_status ) {
			$manual_lock = 'yes' === (string) get_post_meta( $product_id, '_akb_manual_sale_lock', true );
			$tax_disc_on = 'yes' === (string) get_option( 'akibara_taxonomy_discount_enabled', 'no' );
			return ( $manual_lock || $tax_disc_on ) ? $old_sale_raw : '';
		}

		return '';
	}

	/**
	 * Sincroniza todos los productos de una escala de precio de una editorial.
	 *
	 * @param int      $brand_id              ID de término product_brand.
	 * @param string   $editorial_slug        Slug de la editorial.
	 * @param int      $scale_regular         Escala regular actual.
	 * @param int      $new_regular           Nuevo precio regular.
	 * @param float    $discount_pct          % descuento A Pedido.
	 * @param string   $rounding_mode         Modo de redondeo CLP.
	 * @param int|null $manual_sale_price     Precio manual opcional.
	 * @param bool     $protect_active_orders Si true (default), omite productos con órdenes activas.
	 * @param bool     $dry_run               Si true, ejecuta simulación sin persistir.
	 * @return array<string, mixed>
	 */
	public static function sync_scale(
		int $brand_id,
		string $editorial_slug,
		int $scale_regular,
		int $new_regular,
		float $discount_pct = PricingMath::DEFAULT_DISCOUNT_PCT,
		string $rounding_mode = PricingMath::DEFAULT_MODE,
		?int $manual_sale_price = null,
		bool $protect_active_orders = true,
		bool $dry_run = false
	): array {
		$effective_reg = $new_regular > 0 ? $new_regular : $scale_regular;
		$target_sale   = PricingMath::calculate_sale_price( $effective_reg, $discount_pct, $rounding_mode, $manual_sale_price );

		if ( ! $dry_run && '' !== $editorial_slug ) {
			PricingRulesRepository::set_scale_rule(
				$editorial_slug,
				$scale_regular,
				$effective_reg,
				$discount_pct,
				$rounding_mode,
				$manual_sale_price
			);
		}

		$product_ids       = ScaleScanner::get_product_ids_for_scale( $brand_id, $scale_regular );
		$locked_set        = array_fill_keys( OrderLockGuard::get_locked_product_ids(), true );
		$locked_orders_map = OrderLockGuard::get_locked_map_by_product();

		$summary = array(
			'brand_id'              => $brand_id,
			'editorial_slug'        => $editorial_slug,
			'scale_regular'         => $scale_regular,
			'new_regular'           => $effective_reg,
			'target_sale'           => $target_sale,
			'discount_pct'          => $discount_pct,
			'rounding_mode'         => $rounding_mode,
			'protect_active_orders' => $protect_active_orders,
			'dry_run'               => $dry_run,
			'total_products'        => count( $product_ids ),
			'updated_count'         => 0,
			'unchanged_count'       => 0,
			'skipped_locked_count'  => 0,
			'skipped_locked_items'  => array(),
			'items'                 => array(),
		);

		$runner = static function () use (
			$product_ids,
			$effective_reg,
			$target_sale,
			$protect_active_orders,
			$locked_set,
			$locked_orders_map,
			$dry_run,
			&$summary
		): void {
			foreach ( $product_ids as $pid ) {
				$res = self::apply_product_price(
					$pid,
					$effective_reg,
					$target_sale,
					$protect_active_orders,
					$locked_set,
					$locked_orders_map,
					$dry_run
				);
				$summary['items'][] = $res;

				if ( 'skipped_locked' === $res['status'] ) {
					++$summary['skipped_locked_count'];
					$summary['skipped_locked_items'][] = $res;
				} elseif ( in_array( $res['status'], array( 'updated', 'would_update' ), true ) ) {
					++$summary['updated_count'];
				} else {
					++$summary['unchanged_count'];
				}
			}
		};

		if ( $dry_run ) {
			$runner();
		} else {
			BatchGuard::run( $runner, false );
		}

		return $summary;
	}

	/**
	 * Sincroniza el precio de oferta A Pedido (`_sale_price`) de todos los productos en `onbackorder`
	 * según las reglas de redondeo CLP vigentes (`round_100` al 5% por defecto).
	 *
	 * @param bool     $protect_active_orders Si true (default), omite productos en órdenes activas.
	 * @param bool     $dry_run               Si true, solo simula.
	 * @param int|null $brand_id              ID de editorial opcional para filtrar.
	 * @return array<string, mixed>
	 */
	public static function sync_all_backorders(
		bool $protect_active_orders = true,
		bool $dry_run = false,
		?int $brand_id = null
	): array {
		global $wpdb;

		$brand_join = '';
		if ( null !== $brand_id && $brand_id > 0 ) {
			$brand_join = $wpdb->prepare(
				" INNER JOIN {$wpdb->term_relationships} tr_b ON p.ID = tr_b.object_id
				  INNER JOIN {$wpdb->term_taxonomy} tt_b ON tr_b.term_taxonomy_id = tt_b.term_taxonomy_id
				    AND tt_b.taxonomy = 'product_brand' AND tt_b.term_id = %d ",
				$brand_id
			);
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $brand_join proviene de $wpdb->prepare() o vacío.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT p.ID
				FROM {$wpdb->posts} p
				{$brand_join}
				INNER JOIN {$wpdb->postmeta} pm_st
					ON p.ID = pm_st.post_id AND pm_st.meta_key = %s AND pm_st.meta_value = %s
				WHERE p.post_type = 'product'
				  AND p.post_status = 'publish'
				ORDER BY p.ID ASC",
				'_stock_status',
				StockStatus::ON_BACKORDER
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$product_ids       = is_array( $ids ) ? array_values( array_map( 'intval', $ids ) ) : array();
		$locked_set        = array_fill_keys( OrderLockGuard::get_locked_product_ids(), true );
		$locked_orders_map = OrderLockGuard::get_locked_map_by_product();

		$summary = array(
			'protect_active_orders' => $protect_active_orders,
			'dry_run'               => $dry_run,
			'total_scanned'         => count( $product_ids ),
			'updated_count'         => 0,
			'unchanged_count'       => 0,
			'skipped_locked_count'  => 0,
			'skipped_locked_items'  => array(),
			'updated_items'         => array(),
		);

		$runner = static function () use (
			$product_ids,
			$protect_active_orders,
			$locked_set,
			$locked_orders_map,
			$dry_run,
			&$summary
		): void {
			foreach ( $product_ids as $pid ) {
				if ( isset( $locked_set[ $pid ] ) && $protect_active_orders ) {
					++$summary['skipped_locked_count'];
					$summary['skipped_locked_items'][] = array(
						'product_id' => $pid,
						'orders'     => $locked_orders_map[ $pid ] ?? array(),
					);
					continue;
				}

				$product = function_exists( 'wc_get_product' ) ? wc_get_product( $pid ) : null;
				if ( ! $product instanceof WC_Product ) {
					continue;
				}

				$reg = (int) round( (float) $product->get_regular_price( 'edit' ) );
				if ( $reg <= 0 ) {
					continue;
				}

				$target_sale = PricingRulesRepository::resolve_sale_price_for_product( $product, $reg );
				$res         = self::apply_product_price(
					$pid,
					$reg,
					$target_sale,
					$protect_active_orders,
					$locked_set,
					$locked_orders_map,
					$dry_run
				);

				if ( in_array( $res['status'], array( 'updated', 'would_update' ), true ) ) {
					++$summary['updated_count'];
					$summary['updated_items'][] = $res;
				} else {
					++$summary['unchanged_count'];
				}
			}
		};

		if ( $dry_run ) {
			$runner();
		} else {
			BatchGuard::run( $runner, false );
		}

		return $summary;
	}

	/**
	 * Nivela todos los tomos estándar de una serie (`pa_serie`) a una única escala de precio regular
	 * dentro de su editorial, ignorando ediciones especiales (`_akb_variante_tomo = 'edicion_especial'`) y packs.
	 *
	 * @param int  $serie_id              ID del término pa_serie.
	 * @param int  $brand_id              ID del término product_brand.
	 * @param int  $target_regular        Precio regular objetivo en CLP.
	 * @param bool $protect_active_orders Si true, omite tomos comprometidos en órdenes activas.
	 * @param bool $dry_run               Si true, solo simula.
	 * @return array<string, mixed>
	 */
	public static function level_series_scale(
		int $serie_id,
		int $brand_id,
		int $target_regular,
		bool $protect_active_orders = true,
		bool $dry_run = false
	): array {
		global $wpdb;

		if ( $serie_id <= 0 || $brand_id <= 0 || $target_regular <= 0 ) {
			return array(
				'ok'            => false,
				'updated_count' => 0,
				'message'       => 'Parámetros de serie, editorial o precio inválidos.',
			);
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT p.ID AS product_id, p.post_title, COALESCE(pm_var.meta_value, '') AS variante_tomo, t_brand.slug AS editorial_slug
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->term_relationships} tr_s ON p.ID = tr_s.object_id
				INNER JOIN {$wpdb->term_taxonomy} tt_s
					ON tr_s.term_taxonomy_id = tt_s.term_taxonomy_id AND tt_s.taxonomy = 'pa_serie' AND tt_s.term_id = %d
				INNER JOIN {$wpdb->term_relationships} tr_b ON p.ID = tr_b.object_id
				INNER JOIN {$wpdb->term_taxonomy} tt_b
					ON tr_b.term_taxonomy_id = tt_b.term_taxonomy_id AND tt_b.taxonomy = 'product_brand' AND tt_b.term_id = %d
				INNER JOIN {$wpdb->terms} t_brand ON tt_b.term_id = t_brand.term_id
				LEFT JOIN {$wpdb->postmeta} pm_var
					ON p.ID = pm_var.post_id AND pm_var.meta_key = '_akb_variante_tomo'
				WHERE p.post_type = 'product'
				  AND p.post_status = 'publish'
				ORDER BY p.ID ASC",
				$serie_id,
				$brand_id
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return array(
				'ok'            => false,
				'updated_count' => 0,
				'message'       => 'No se encontraron tomos para la serie y editorial indicadas.',
			);
		}

		$editorial_slug    = (string) ( $rows[0]['editorial_slug'] ?? '' );
		$rule              = PricingRulesRepository::resolve_rule_for_scale( $editorial_slug, $target_regular );
		$target_sale       = (int) $rule['sale_price'];
		$locked_set        = array_fill_keys( OrderLockGuard::get_locked_product_ids(), true );
		$locked_orders_map = OrderLockGuard::get_locked_map_by_product();

		$summary = array(
			'ok'                   => true,
			'serie_id'             => $serie_id,
			'brand_id'             => $brand_id,
			'target_regular'       => $target_regular,
			'target_sale'          => $target_sale,
			'updated_count'        => 0,
			'skipped_locked_count' => 0,
			'items'                => array(),
		);

		$runner = static function () use (
			$rows,
			$target_regular,
			$target_sale,
			$protect_active_orders,
			$locked_set,
			$locked_orders_map,
			$dry_run,
			&$summary
		): void {
			foreach ( $rows as $row ) {
				if ( 'edicion_especial' === (string) ( $row['variante_tomo'] ?? '' ) ) {
					continue;
				}
				$title = (string) ( $row['post_title'] ?? '' );
				if ( 1 === preg_match( '/\b(pack|box\s*set|cofre|starter\s*pack)\b/iu', $title ) ) {
					continue;
				}

				$pid = (int) ( $row['product_id'] ?? 0 );
				$res = self::apply_product_price(
					$pid,
					$target_regular,
					$target_sale,
					$protect_active_orders,
					$locked_set,
					$locked_orders_map,
					$dry_run
				);
				$summary['items'][] = $res;

				if ( 'skipped_locked' === $res['status'] ) {
					++$summary['skipped_locked_count'];
				} elseif ( in_array( $res['status'], array( 'updated', 'would_update' ), true ) ) {
					++$summary['updated_count'];
				}
			}
		};

		if ( $dry_run ) {
			$runner();
		} else {
			BatchGuard::run( $runner, false );
		}

		return $summary;
	}

	/**
	 * Rescata productos de `outofstock` a `onbackorder` bajo el contrato nativo de la Regla 18:
	 * - Excluye SIEMPRE `OrderLockGuard::get_locked_product_ids()`
	 * - Excluye `_akb_eta_type = 'disabled'`
	 * - Configura `_stock_status = 'onbackorder'`, `_manage_stock = 'no'`, `_stock = null`,
	 *   `_backorders = 'no'` y `_sale_price` redondeado al 5% (`round_100`).
	 *
	 * @param list<int> $product_ids IDs de productos candidatos.
	 * @param bool      $dry_run     Si true, solo simula.
	 * @return array<string, mixed>
	 */
	public static function rescue_outofstock_products( array $product_ids, bool $dry_run = false ): array {
		$product_ids = array_values( array_unique( array_filter( array_map( 'intval', $product_ids ), static fn( int $id ): bool => $id > 0 ) ) );
		$locked_set  = array_fill_keys( OrderLockGuard::get_locked_product_ids(), true );

		$summary = array(
			'dry_run'              => $dry_run,
			'rescued_count'        => 0,
			'skipped_locked_count' => 0,
			'skipped_other_count'  => 0,
			'items'                => array(),
		);

		$runner = static function () use ( $product_ids, $locked_set, $dry_run, &$summary ): void {
			foreach ( $product_ids as $pid ) {
				// Regla Inquebrantable 2: prohibido tocar stock/estado en productos bloqueados.
				if ( isset( $locked_set[ $pid ] ) ) {
					++$summary['skipped_locked_count'];
					$summary['items'][] = array(
						'product_id' => $pid,
						'status'     => 'skipped_locked',
					);
					continue;
				}

				$eta_type = (string) get_post_meta( $pid, '_akb_eta_type', true );
				if ( '' !== $eta_type && 'auto' !== $eta_type ) {
					++$summary['skipped_other_count'];
					continue;
				}

				$product = function_exists( 'wc_get_product' ) ? wc_get_product( $pid ) : null;
				if ( ! $product instanceof WC_Product || StockStatus::OUT_OF_STOCK !== $product->get_stock_status( 'edit' ) ) {
					++$summary['skipped_other_count'];
					continue;
				}

				$reg = (int) round( (float) $product->get_regular_price( 'edit' ) );
				if ( $reg <= 0 ) {
					++$summary['skipped_other_count'];
					continue;
				}

				$sale = PricingRulesRepository::resolve_sale_price_for_product( $product, $reg );

				if ( $dry_run ) {
					++$summary['rescued_count'];
					$summary['items'][] = array(
						'product_id'    => $pid,
						'title'         => $product->get_name(),
						'status'        => 'would_rescue',
						'regular_price' => $reg,
						'sale_price'    => $sale,
					);
					continue;
				}

				$product->set_manage_stock( false );
				$product->set_stock_quantity( null );
				$product->set_backorders( 'no' );
				$product->set_stock_status( StockStatus::ON_BACKORDER );
				$product->set_regular_price( (string) $reg );
				$product->set_sale_price( (string) $sale );
				$product->save();

				delete_post_meta( $pid, '_stock' );
				wp_remove_object_terms( $pid, StockStatus::OUT_OF_STOCK, 'product_visibility' );

				$existing_ts = (string) get_post_meta( $pid, '_akb_onbackorder_at', true );
				if ( '' === $existing_ts ) {
					self::update_unique_post_meta( $pid, '_akb_onbackorder_at', (string) time() );
				} else {
					self::dedup_post_meta_keys( $pid );
				}

				if ( function_exists( 'wc_delete_product_transients' ) ) {
					wc_delete_product_transients( $pid );
				}
				clean_post_cache( $pid );
				BatchGuard::mark_product_modified( $pid, true );

				++$summary['rescued_count'];
				$summary['items'][] = array(
					'product_id'    => $pid,
					'title'         => $product->get_name(),
					'status'        => 'rescued',
					'regular_price' => $reg,
					'sale_price'    => $sale,
				);
			}
		};

		if ( $dry_run ) {
			$runner();
		} else {
			BatchGuard::run( $runner, true );
		}

		return $summary;
	}
}
