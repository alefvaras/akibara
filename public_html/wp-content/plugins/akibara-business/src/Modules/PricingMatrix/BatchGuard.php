<?php
/**
 * Guardián de lotes masivos de precios y disponibilidad (AKB_Batch_Guard).
 *
 * Suspende la tormenta de hooks individuales por producto durante operaciones
 * por lote y ejecuta una única sincronización consolidada al finalizar:
 * - 0 ejecuciones de akb_order_preventa_recompute_for_product
 * - 0 ejecuciones de akb_sf_scan_product
 * - 1 sola sincronización de akibara-search para los product_id modificados
 * - 1 sola ejecución de akibara_catalog_order_recompute si cambió disponibilidad
 * - 1 sola purga consolidada de caché (LiteSpeed / Edge / Redis / Transients)
 *
 * @package Akibara\Business\Modules\PricingMatrix
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\PricingMatrix;

use Akibara\Business\Modules\CatalogOrder\CatalogOrder;
use Akibara\Business\Modules\HomeDestacados\HomeDestacados;
use Akibara\Business\Modules\SiteSpeed\EdgePurge;
use Akibara\Business\Modules\SiteSpeed\SeriesCacheTags;

defined( 'ABSPATH' ) || exit;

/**
 * @api
 * @psalm-suppress InvalidArgument
 */
final class BatchGuard {

	/**
	 * Hooks de Action Scheduler suprimidos durante el lote.
	 *
	 * @var list<string>
	 */
	public const SUPPRESSED_AS_HOOKS = array(
		'akb_order_preventa_recompute_for_product',
		'akb_sf_scan_product',
		'akibara_catalog_order_recompute',
		'akibara_edge_purge_run',
		'akb_search_pump_live',
		'akb_search_reindex_product',
		'akb_search_reindex_term',
	);

	/**
	 * Profundidad de anidamiento activa.
	 *
	 * @var int
	 */
	private static int $active_depth = 0;

	/**
	 * IDs de productos modificados en el lote actual.
	 *
	 * @var array<int, true>
	 */
	private static array $modified_product_ids = array();

	/**
	 * Indica si algún producto del lote cambió su estado de disponibilidad.
	 *
	 * @var bool
	 */
	private static bool $availability_changed = false;

	/**
	 * Hooks de WordPress temporalmente suspendidos durante el lote.
	 *
	 * @var array<string, mixed>
	 */
	private static array $suspended_wp_filters = array();

	/**
	 * ¿Está activo el guardián de lotes?
	 *
	 * @return bool
	 */
	public static function is_active(): bool {
		return self::$active_depth > 0;
	}

	/**
	 * Registra un producto modificado dentro del lote activo.
	 *
	 * @param int  $product_id           ID del producto.
	 * @param bool $availability_changed True si cambió _stock_status.
	 * @return void
	 */
	public static function mark_product_modified( int $product_id, bool $availability_changed = false ): void {
		if ( $product_id > 0 ) {
			self::$modified_product_ids[ $product_id ] = true;
		}
		if ( $availability_changed ) {
			self::$availability_changed = true;
		}
	}

	/**
	 * Ejecuta un bloque dentro del guardián de lotes y consolida efectos al cerrar.
	 *
	 * @template T
	 * @param callable(): T $callback                Operación por lote.
	 * @param bool          $recompute_catalog_order Forzar recálculo de menu_order al finalizar.
	 * @return T
	 */
	public static function run( callable $callback, bool $recompute_catalog_order = false ) {
		$is_outermost = ( 0 === self::$active_depth );
		$tx_started   = false;
		if ( $is_outermost ) {
			self::$modified_product_ids = array();
			self::$availability_changed = $recompute_catalog_order;
			self::engage_guards();
			$tx_started = self::begin_batch_transaction( true );
		}

		++self::$active_depth;
		$batch_ok = false;

		try {
			$result   = $callback();
			$batch_ok = true;
		} finally {
			--self::$active_depth;
			if ( 0 === self::$active_depth ) {
				$modified_ids = array_keys( self::$modified_product_ids );
				$recompute    = self::$availability_changed || $recompute_catalog_order;

				self::$modified_product_ids = array();
				self::$availability_changed = false;

				if ( $batch_ok ) {
					self::commit_batch_transaction( $tx_started );
					self::release_guards();
					self::flush_consolidated_effects( $modified_ids, $recompute );
				} else {
					self::rollback_batch_transaction( $tx_started );
					self::release_guards();
					foreach ( $modified_ids as $pid ) {
						if ( function_exists( 'wp_cache_delete' ) ) {
							wp_cache_delete( $pid, 'post_meta' );
						}
						if ( function_exists( 'clean_post_cache' ) ) {
							clean_post_cache( $pid );
						}
					}
				}
			}
		}

		return $result;
	}

	/**
	 * Abre una transacción SQL única para consolidar los fsync de InnoDB durante el lote.
	 *
	 * @param bool $is_outermost True si es el nivel externo del lote.
	 * @return bool True si se abrió transacción.
	 */
	private static function begin_batch_transaction( bool $is_outermost ): bool {
		global $wpdb;

		if ( ! $is_outermost || ! $wpdb instanceof \wpdb ) {
			return false;
		}
		$wpdb->query( 'START TRANSACTION' );
		return true;
	}

	/**
	 * Confirma la transacción SQL abierta por el lote antes de indexar en Search.
	 *
	 * @param bool $tx_started True si begin_batch_transaction abrió transacción.
	 * @return void
	 */
	private static function commit_batch_transaction( bool $tx_started ): void {
		global $wpdb;

		if ( ! $tx_started || ! $wpdb instanceof \wpdb ) {
			return;
		}
		$wpdb->query( 'COMMIT' );
	}

	/**
	 * Revierte la transacción SQL abierta por el lote si ocurrió una excepción.
	 *
	 * @param bool $tx_started True si begin_batch_transaction abrió transacción.
	 * @return void
	 */
	private static function rollback_batch_transaction( bool $tx_started ): void {
		global $wpdb;

		if ( ! $tx_started || ! $wpdb instanceof \wpdb ) {
			return;
		}
		$wpdb->query( 'ROLLBACK' );
	}

	/**
	 * Intercepta `as_schedule_single_action` para suprimir tormenta de hooks.
	 *
	 * @param int|null               $pre       Valor de cortocircuito.
	 * @param int                    $timestamp Timestamp programado.
	 * @param string                 $hook      Nombre del hook AS.
	 * @param array<int|string,mixed> $args     Argumentos.
	 * @param string                 $group     Grupo AS.
	 * @return int|null
	 */
	public static function filter_pre_schedule_single_action( $pre, $timestamp, $hook, $args = array(), $group = '' ) {
		unset( $timestamp, $args, $group );
		if ( self::$active_depth > 0 && in_array( (string) $hook, self::SUPPRESSED_AS_HOOKS, true ) ) {
			return 0;
		}
		return $pre;
	}

	/**
	 * Intercepta `as_enqueue_async_action` para suprimir tormenta de hooks.
	 *
	 * @param int|null               $pre   Valor de cortocircuito.
	 * @param string                 $hook  Nombre del hook AS.
	 * @param array<int|string,mixed> $args Argumentos.
	 * @param string                 $group Grupo AS.
	 * @return int|null
	 */
	public static function filter_pre_enqueue_async_action( $pre, $hook, $args = array(), $group = '' ) {
		unset( $args, $group );
		if ( self::$active_depth > 0 && in_array( (string) $hook, self::SUPPRESSED_AS_HOOKS, true ) ) {
			return 0;
		}
		return $pre;
	}

	/**
	 * Activa los filtros de supresión de Action Scheduler y desacopla listeners pesados por producto.
	 *
	 * @return void
	 */
	private static function engage_guards(): void {
		global $wp_filter;

		add_filter( 'pre_as_schedule_single_action', array( self::class, 'filter_pre_schedule_single_action' ), PHP_INT_MAX, 5 );
		add_filter( 'pre_as_enqueue_async_action', array( self::class, 'filter_pre_enqueue_async_action' ), PHP_INT_MAX, 4 );

		$hooks_to_suspend = array(
			'woocommerce_before_product_object_save',
			'woocommerce_update_product',
			'save_post_product',
			'save_post',
			'post_updated',
			'woocommerce_product_set_stock_status',
			'updated_post_meta',
			'added_post_meta',
		);

		self::$suspended_wp_filters = array();
		foreach ( $hooks_to_suspend as $hook_name ) {
			if ( isset( $wp_filter[ $hook_name ] ) ) {
				self::$suspended_wp_filters[ $hook_name ] = $wp_filter[ $hook_name ];
				unset( $wp_filter[ $hook_name ] );
			}
		}
	}

	/**
	 * Restaura los hooks suspendidos al finalizar el lote.
	 *
	 * @return void
	 */
	private static function release_guards(): void {
		remove_filter( 'pre_as_schedule_single_action', array( self::class, 'filter_pre_schedule_single_action' ), PHP_INT_MAX );
		remove_filter( 'pre_as_enqueue_async_action', array( self::class, 'filter_pre_enqueue_async_action' ), PHP_INT_MAX );

		foreach ( self::$suspended_wp_filters as $hook_name => $hook_obj ) {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restauración deliberada de WP_Hook suspendido tras el lote.
			$GLOBALS['wp_filter'][ $hook_name ] = $hook_obj;
		}
		self::$suspended_wp_filters = array();
	}

	/**
	 * Ejecuta una única vez la sincronización de Search, recálculo de vitrina y purga de cachés.
	 *
	 * @param list<int> $modified_ids            IDs de productos modificados.
	 * @param bool      $recompute_catalog_order True si debe recalcularse menu_order.
	 * @return void
	 */
	private static function flush_consolidated_effects( array $modified_ids, bool $recompute_catalog_order ): void {
		$modified_ids = array_values( array_unique( array_filter( array_map( 'intval', $modified_ids ), static fn( int $id ): bool => $id > 0 ) ) );
		if ( array() === $modified_ids && ! $recompute_catalog_order ) {
			return;
		}

		// 1. Saneamiento único por lote de claves de postmeta para garantizar 0 duplicados.
		if ( array() !== $modified_ids ) {
			PricingSynchronizer::dedup_post_meta_keys_batch( $modified_ids );
		}

		// 2. Si hubo cambio de disponibilidad, recalcular menu_order 1 sola vez antes de indexar en Search.
		if ( $recompute_catalog_order && class_exists( CatalogOrder::class ) ) {
			( new CatalogOrder() )->recompute();
		}

		// 3. Limpiar transients/post cache, sincronizar akibara-search y purgar caché de producto/serie/borde.
		if ( array() !== $modified_ids ) {
			self::sync_search_for_products( $modified_ids, true );
			self::purge_modified_products_caches( $modified_ids );
		}

		// 4. Purga consolidada de cachés 1 sola vez.
		if ( class_exists( HomeDestacados::class ) ) {
			( new HomeDestacados() )->bump_cache_generation();
		}
		if ( class_exists( EdgePurge::class ) ) {
			EdgePurge::despachar();
		}
	}

	/**
	 * Purga en LiteSpeed y anota en EdgePurge los productos modificados y sus series tras un lote
	 * (durante el lote los hooks por objeto están suspendidos y el fast-path escribe sin `WC_Product::save()`).
	 *
	 * @param list<int> $product_ids IDs de productos modificados.
	 * @return void
	 */
	private static function purge_modified_products_caches( array $product_ids ): void {
		if (
			! class_exists( SeriesCacheTags::class )
			|| ! function_exists( 'do_action' )
			|| ! function_exists( 'wp_get_post_parent_id' )
			|| ! function_exists( 'get_the_terms' )
			|| ! function_exists( 'get_term_link' )
			|| ! function_exists( 'get_permalink' )
			|| ! function_exists( 'home_url' )
		) {
			return;
		}

		$tags = new SeriesCacheTags();
		foreach ( $product_ids as $pid ) {
			$tags->purge_for_product_id( $pid );
		}
	}

	/**
	 * Sincroniza de forma inmediata y limpia los productos modificados en akibara-search
	 * usando sus métodos internos para preservar semantic_fingerprint y WriteFence.
	 *
	 * @param list<int> $product_ids  IDs de productos a sincronizar.
	 * @param bool      $clean_caches Si true, limpia transients y post cache antes de indexar.
	 * @return void
	 */
	public static function sync_search_for_products( array $product_ids, bool $clean_caches = true ): void {
		$product_ids = array_values( array_unique( array_filter( array_map( 'intval', $product_ids ), static fn( int $id ): bool => $id > 0 ) ) );
		if ( array() === $product_ids ) {
			return;
		}

		if ( $clean_caches ) {
			self::clear_product_caches( $product_ids );
		}

		if ( function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( $product_ids, true, true );
		}

		$indexer = self::resolve_active_search_indexer();

		// Suprimir el encolado asíncrono de akb_search_pump_live mientras drenamos inline.
		add_filter( 'pre_as_schedule_single_action', array( self::class, 'suppress_search_pump_as' ), PHP_INT_MAX, 3 );
		add_filter( 'pre_as_enqueue_async_action', array( self::class, 'suppress_search_pump_as_async' ), PHP_INT_MAX, 2 );

		try {
			if ( is_object( $indexer ) ) {
				self::run_indexer_batch( $indexer, $product_ids );
			} else {
				foreach ( $product_ids as $pid ) {
					do_action( 'akb_search_reindex_product', $pid );
				}
				do_action( 'akb_search_pump_live' );
			}
		} finally {
			remove_filter( 'pre_as_schedule_single_action', array( self::class, 'suppress_search_pump_as' ), PHP_INT_MAX );
			remove_filter( 'pre_as_enqueue_async_action', array( self::class, 'suppress_search_pump_as_async' ), PHP_INT_MAX );
		}
	}

	/**
	 * Limpia transients y caché de posts para una lista de productos.
	 *
	 * @param list<int> $product_ids IDs de productos.
	 * @return void
	 */
	private static function clear_product_caches( array $product_ids ): void {
		foreach ( $product_ids as $pid ) {
			if ( function_exists( 'wc_delete_product_transients' ) ) {
				wc_delete_product_transients( $pid );
			}
			if ( function_exists( 'clean_post_cache' ) ) {
				clean_post_cache( $pid );
			}
		}
	}

	/**
	 * Localiza la instancia activa de Indexer registrada en akb_search_pump_live.
	 *
	 * @return object|null
	 */
	private static function resolve_active_search_indexer(): ?object {
		global $wp_filter;

		if ( ! isset( $wp_filter['akb_search_pump_live'] ) || ! is_object( $wp_filter['akb_search_pump_live'] ) ) {
			return null;
		}

		foreach ( $wp_filter['akb_search_pump_live']->callbacks as $callbacks ) {
			foreach ( $callbacks as $cb ) {
				$fn   = $cb['function'] ?? null;
				$cand = is_array( $fn ) ? ( $fn[0] ?? null ) : null;
				if ( is_object( $cand ) && method_exists( $cand, 'enqueue_products' ) && method_exists( $cand, 'handle_live_pump' ) ) {
					return $cand;
				}
			}
		}

		return null;
	}

	/**
	 * Ejecuta la indexación y cierre consolidado de un lote en akibara-search.
	 *
	 * Utiliza la API pública `sync_products_batch_immediate()` de Indexer para
	 * desacoplar módulos y respetar el encapsulamiento. Mantiene fallback a
	 * `enqueue_products()` + `handle_live_pump()` para resiliencia.
	 *
	 * @param object    $indexer     Instancia de Indexer.
	 * @param list<int> $product_ids IDs de productos.
	 * @return void
	 */
	private static function run_indexer_batch( object $indexer, array $product_ids ): void {
		if ( method_exists( $indexer, 'sync_products_batch_immediate' ) ) {
			$indexer->sync_products_batch_immediate( $product_ids );
			return;
		}

		$indexer->enqueue_products( $product_ids );
		$indexer->handle_live_pump();
	}

	/**
	 * Cortocircuita scheduling de akb_search_pump_live durante el drenado síncrono.
	 *
	 * @param int|null $pre       Valor previo.
	 * @param int      $timestamp Timestamp.
	 * @param string   $hook      Hook.
	 * @return int|null
	 */
	public static function suppress_search_pump_as( $pre, $timestamp, $hook ) {
		unset( $timestamp );
		if ( 'akb_search_pump_live' === (string) $hook ) {
			return 0;
		}
		return $pre;
	}

	/**
	 * Cortocircuita async enqueue de akb_search_pump_live durante el drenado síncrono.
	 *
	 * @param int|null $pre  Valor previo.
	 * @param string   $hook Hook.
	 * @return int|null
	 */
	public static function suppress_search_pump_as_async( $pre, $hook ) {
		if ( 'akb_search_pump_live' === (string) $hook ) {
			return 0;
		}
		return $pre;
	}
}

if ( ! class_exists( '\\AKB_Batch_Guard', false ) ) {
	class_alias( BatchGuard::class, '\\AKB_Batch_Guard' );
}
