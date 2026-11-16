<?php
/**
 * Sincronizador transaccional de precios y estados por escala, serie y rescate de agotados.
 *
 * Cumple las Reglas de Oro:
 * - Usa métodos CRUD de WooCommerce ($product->set_regular_price(), set_sale_price(), save())
 *   y actualiza wp_wc_product_meta_lookup + limpia transients/object cache.
 * - Ejecuta dentro de BatchGuard (0 tormenta de hooks AS, 1 sync de akibara-search al final).
 * - WooCommerce gobierna el inventario; actualizar precios nunca altera pedidos creados.
 * - Garantiza cero filas duplicadas en wp_postmeta para metadatos propios.
 *
 * @package Akibara\Business\Modules\PricingMatrix
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\PricingMatrix;

use Akibara\Business\Modules\Stock\StockStatus;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * @api
 * @psalm-suppress InvalidArgument
 */
final class PricingSynchronizer {

	/**
	 * Claves de postmeta cuyas filas duplicadas deben prevenirse y podarse siempre.
	 *
	 * @var list<string>
	 */
	public const DEDUP_META_KEYS = array(
		'_akb_out_of_stock_at',
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
	 * Aplica el cambio de precio a un producto individual sin bloqueos de órdenes.
	 * El dueño de la tienda puede actualizar el precio regular o de oferta de cualquier producto.
	 *
	 * @param int                                     $product_id            ID del producto.
	 * @param int                                     $target_regular        Nuevo precio regular CLP.
	 * @param int                                     $target_backorder_sale Precio oferta opcional CLP.
	 * @param bool                                    $protect_active_orders Obsoleto (mantenido por compatibilidad de firma).
	 * @param array<int, true>|null                   $locked_set            Obsoleto (mantenido por compatibilidad de firma).
	 * @param array<int, list<array<string,mixed>>>|null $locked_orders_map  Obsoleto (mantenido por compatibilidad de firma).
	 * @param bool                                    $dry_run               Si true, solo simula sin escribir.
	 * @return array<string, mixed>
	 */
	public static function apply_product_price(
		int $product_id,
		int $target_regular,
		?int $target_backorder_sale = null,
		bool $protect_active_orders = false,
		?array $locked_set = null,
		?array $locked_orders_map = null,
		bool $dry_run = false
	): array {
		unset( $protect_active_orders, $locked_set, $locked_orders_map );

		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
		if ( ! $product instanceof WC_Product ) {
			return array(
				'status'     => 'not_found',
				'product_id' => $product_id,
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
		$changed      = ( $new_regular !== $old_regular ) || ( $new_sale_str !== $old_sale_raw );

		$serie_meta = $dry_run
			? self::resolve_dry_run_serie( $product_id )
			: array(
				'serie_name' => '',
				'serie_id'   => 0,
			);
		$serie_name = $serie_meta['serie_name'];
		$serie_id   = $serie_meta['serie_id'];

		if ( $dry_run || ! $changed ) {
			return array(
				'status'       => $changed ? 'would_update' : 'unchanged',
				'product_id'   => $product_id,
				'title'        => $product->get_name(),
				'serie_id'     => $serie_id,
				'serie_name'   => $serie_name,
				'stock_status' => $stock_status,
				'old_regular'  => $old_regular,
				'new_regular'  => $new_regular,
				'old_sale'     => $old_sale,
				'new_sale'     => $new_sale_int,
			);
		}

		$use_batch_fast_path = BatchGuard::is_active() && $product->is_type( 'simple' );

		$exec_fn = static function () use ( $product, $product_id, $new_regular, $new_sale_str, $use_batch_fast_path ): void {
			if ( $use_batch_fast_path ) {
				self::persist_simple_price_in_batch( $product_id, $new_regular, $new_sale_str );
			} else {
				// Regla Inquebrantable 2: cuando se actualiza precio, SOLO se toca _regular_price y _sale_price.
				$product->set_regular_price( (string) $new_regular );
				$product->set_sale_price( $new_sale_str );
				$product->save();
			}

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
			'serie_id'     => $serie_id,
			'serie_name'   => $serie_name,
			'stock_status' => $stock_status,
			'old_regular'  => $old_regular,
			'new_regular'  => $new_regular,
			'old_sale'     => $old_sale,
			'new_sale'     => $new_sale_int,
		);
	}

	/**
	 * Resuelve nombre e ID de la primera serie del producto únicamente durante simulaciones.
	 *
	 * @param int $product_id ID del producto.
	 * @return array{serie_name:string, serie_id:int}
	 */
	private static function resolve_dry_run_serie( int $product_id ): array {
		$raw_terms = function_exists( 'get_the_terms' ) ? get_the_terms( $product_id, 'pa_serie' ) : array();
		$first     = ( is_array( $raw_terms ) && array() !== $raw_terms ) ? reset( $raw_terms ) : null;
		if ( ! $first instanceof \WP_Term ) {
			return array(
				'serie_name' => '',
				'serie_id'   => 0,
			);
		}

		return array(
			'serie_name' => (string) $first->name,
			'serie_id'   => (int) $first->term_id,
		);
	}

	/**
	 * Persiste el precio de un producto simple dentro de un lote activo sin el overhead de WC_Product::save().
	 *
	 * La invalidación consolidada de transients (`wc_delete_product_transients()`), caché de post (`clean_post_cache()`),
	 * etiquetas LiteSpeed y purga Cloudflare (`SeriesCacheTags` + `EdgePurge`) corre al cerrar `BatchGuard::run()`
	 * una vez confirmada la transacción SQL.
	 *
	 * @param int    $product_id   ID del producto simple.
	 * @param int    $new_regular  Nuevo precio regular en CLP.
	 * @param string $new_sale_str Nuevo precio oferta o '' si no hay oferta.
	 */
	private static function persist_simple_price_in_batch( int $product_id, int $new_regular, string $new_sale_str ): void {
		$effective_price = '' !== $new_sale_str ? (int) $new_sale_str : $new_regular;
		$onsale          = $effective_price < $new_regular ? 1 : 0;

		update_post_meta( $product_id, '_regular_price', (string) $new_regular );
		update_post_meta( $product_id, '_sale_price', $new_sale_str );
		update_post_meta( $product_id, '_price', (string) $effective_price );
		foreach ( array( '_sale_price_dates_from', '_sale_price_dates_to' ) as $sched_key ) {
			if ( '' !== get_post_meta( $product_id, $sched_key, true ) ) {
				delete_post_meta( $product_id, $sched_key );
			}
		}

		global $wpdb;
		$lookup_table = $wpdb->prefix . 'wc_product_meta_lookup';
		$wpdb->update(
			$lookup_table,
			array(
				'min_price' => (float) $effective_price,
				'max_price' => (float) $effective_price,
				'onsale'    => $onsale,
			),
			array( 'product_id' => $product_id ),
			array( '%f', '%f', '%d' ),
			array( '%d' )
		);
	}

	/**
	 * Determina el `_sale_price` objetivo de WooCommerce:
	 * - 0 o >= $new_regular: el producto queda SIN OFERTA ('').
	 * - > 0 y < $new_regular: fija la oferta en ese monto exacto.
	 * - null o negativo: conserva el valor previo $old_sale_raw.
	 *
	 * @param int      $product_id            ID del producto.
	 * @param string   $stock_status          Estado de stock actual.
	 * @param int      $new_regular           Nuevo precio regular.
	 * @param int|null $target_backorder_sale Precio oferta objetivo (0 = sin oferta).
	 * @param string   $old_sale_raw          Precio oferta previo crudo.
	 * @return string
	 */
	public static function compute_target_sale_string(
		int $product_id,
		string $stock_status,
		int $new_regular,
		?int $target_backorder_sale,
		string $old_sale_raw
	): string {
		unset( $product_id, $stock_status );

		if ( null === $target_backorder_sale || $target_backorder_sale < 0 ) {
			return $old_sale_raw;
		}

		if ( 0 === $target_backorder_sale || $target_backorder_sale >= $new_regular ) {
			return '';
		}

		return (string) $target_backorder_sale;
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
	 * @param int|null $manual_sale_price     Precio manual opcional (0 = sin oferta).
	 * @param bool     $protect_active_orders Si true, omite productos con órdenes activas.
	 * @param bool     $dry_run               Si true, ejecuta simulación sin persistir.
	 * @param int      $serie_id              ID opcional de término pa_serie para acotar a una sola serie.
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
		bool $dry_run = false,
		int $serie_id = 0
	): array {
		if ( $brand_id <= 0 && '' !== $editorial_slug && function_exists( 'get_term_by' ) ) {
			$term     = get_term_by( 'slug', $editorial_slug, 'product_brand' );
			$brand_id = ( $term instanceof \WP_Term ) ? (int) $term->term_id : 0;
		}
		if ( '' === $editorial_slug && $brand_id > 0 && function_exists( 'get_term' ) ) {
			$term           = get_term( $brand_id, 'product_brand' );
			$editorial_slug = ( $term instanceof \WP_Term ) ? (string) $term->slug : '';
		}

		$effective_reg = $new_regular > 0 ? $new_regular : $scale_regular;
		$target_sale   = ( null !== $manual_sale_price && $manual_sale_price >= $effective_reg )
			? 0
			: PricingMath::calculate_sale_price( $effective_reg, $discount_pct, $rounding_mode, $manual_sale_price );

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

		$product_ids = ScaleScanner::get_product_ids_for_scale( $brand_id, $scale_regular, $serie_id );
		$batch       = self::run_price_batch( $product_ids, $effective_reg, $target_sale, $dry_run );

		return array(
			'brand_id'              => $brand_id,
			'editorial_slug'        => $editorial_slug,
			'serie_id'              => $serie_id,
			'scale_regular'         => $scale_regular,
			'new_regular'           => $effective_reg,
			'target_sale'           => $target_sale,
			'discount_pct'          => $discount_pct,
			'rounding_mode'         => $rounding_mode,
			'protect_active_orders' => $protect_active_orders,
			'dry_run'               => $dry_run,
			'total_products'        => count( $product_ids ),
			'updated_count'         => $batch['updated_count'],
			'unchanged_count'       => $batch['unchanged_count'],
			'items'                 => $batch['items'],
		);
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
	 * @psalm-suppress UnusedParam
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

		$editorial_slug = (string) ( $rows[0]['editorial_slug'] ?? '' );
		$target_sale    = self::resolve_level_series_sale( $editorial_slug, $target_regular );
		$product_ids    = array();
		foreach ( $rows as $row ) {
			if ( ! self::is_excluded_from_series_leveling( $row ) ) {
				$product_ids[] = (int) ( $row['product_id'] ?? 0 );
			}
		}

		$batch = self::run_price_batch( $product_ids, $target_regular, $target_sale, $dry_run );

		return array(
			'ok'             => true,
			'serie_id'       => $serie_id,
			'brand_id'       => $brand_id,
			'target_regular' => $target_regular,
			'target_sale'    => $target_sale,
			'updated_count'  => $batch['updated_count'],
			'dry_run'        => $dry_run,
			'items'          => $batch['items'],
		);
	}

	/**
	 * Ejecuta un lote de precios bajo BatchGuard (o directo en modo simulación).
	 *
	 * @param list<int> $product_ids    IDs de productos.
	 * @param int       $target_regular Nuevo precio regular CLP.
	 * @param int       $target_sale    Precio oferta objetivo CLP.
	 * @param bool      $dry_run        Si true, simula sin persistir.
	 * @return array{items:list<array<string,mixed>>, updated_count:int, unchanged_count:int}
	 */
	private static function run_price_batch( array $product_ids, int $target_regular, int $target_sale, bool $dry_run ): array {
		$batch = array(
			'items'           => array(),
			'updated_count'   => 0,
			'unchanged_count' => 0,
		);

		$runner = static function () use ( $product_ids, $target_regular, $target_sale, $dry_run, &$batch ): void {
			if ( array() !== $product_ids && function_exists( '_prime_post_caches' ) ) {
				_prime_post_caches( $product_ids, true, true );
			}

			foreach ( $product_ids as $pid ) {
				$res              = self::apply_product_price( $pid, $target_regular, $target_sale, false, null, null, $dry_run );
				$batch['items'][] = $res;
				$counter_key      = in_array( $res['status'], array( 'updated', 'would_update' ), true ) ? 'updated_count' : 'unchanged_count';
				++$batch[ $counter_key ];
			}
		};

		if ( $dry_run ) {
			$runner();
		} else {
			BatchGuard::run( $runner, false );
		}

		return $batch;
	}

	/**
	 * Resuelve el precio oferta al nivelar una serie (0 salvo regla manual explícita).
	 *
	 * @param string $editorial_slug Slug de editorial.
	 * @param int    $target_regular Precio regular objetivo.
	 * @return int
	 */
	private static function resolve_level_series_sale( string $editorial_slug, int $target_regular ): int {
		$rule        = PricingRulesRepository::resolve_rule_for_scale( $editorial_slug, $target_regular );
		$manual_sale = $rule['manual_sale_price'];
		return ( null !== $manual_sale && $manual_sale > 0 && $manual_sale < $target_regular )
			? $manual_sale
			: 0;
	}

	/**
	 * Determina si un tomo debe excluirse de la nivelación estándar por ser edición especial o pack.
	 *
	 * @param array<string, mixed> $row Fila SQL del producto.
	 * @return bool
	 */
	private static function is_excluded_from_series_leveling( array $row ): bool {
		if ( 'edicion_especial' === (string) ( $row['variante_tomo'] ?? '' ) ) {
			return true;
		}
		$title = (string) ( $row['post_title'] ?? '' );
		return 1 === preg_match( '/\b(pack|box\s*set|cofre|starter\s*pack|artbook|el\s*arte\s*de\b|libro\s*de\s*arte|fanbook|character\s*book|gu[ií]a\s*oficial|edici[oó]n\s*especial|edici[oó]n\s*coleccionista|portada\s*alternativa|variant\s*cover)\b/iu', $title );
	}
}
