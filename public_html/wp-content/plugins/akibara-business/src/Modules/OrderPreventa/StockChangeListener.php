<?php
/**
 * Recalcula pedidos cuando un producto reservable vuelve a tener stock.
 *
 * woocommerce_product_set_stock_status entrega id, estado y producto. Al pasar
 * a instock se agenda una acción corta por product_id. El worker reclama
 * atómicamente los trabajos libres del lote y hace una pasada paginada común
 * antes de invocar ReadyToShipCron::process_order().
 *
 * process_order() sólo marca listo cuando todas las líneas pendientes llegaron;
 * no cambia stock del producto y no reemite este hook. La deduplicación por
 * producto usa as_has_scheduled_action(hook, args, group) antes de encolar.
 * No se usa el flag global unique, cuyo alcance hook/grupo podría excluir
 * productos distintos. Los guardas finales hacen inocuos los jobs redundantes.
 * El trabajo asíncrono permite agrupar reposiciones cercanas y evita recorrer
 * pedidos dentro de cada guardado de producto.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\OrderPreventa;

use Akibara\Business\Modules\Stock\StockStatus;
use WC_Order;
use WC_Order_Item_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Listener de cambio de stock para recomputo inmediato.
 *
 * @since 1.15.0
 */
final class StockChangeListener {

	public const ASYNC_HOOK     = 'akb_order_preventa_recompute_for_product';
	private const GROUP         = 'akibara-order-preventa';
	private const FETCH_LIMIT   = 100;
	private const DEBOUNCE_SECONDS = 5;
	private const QUEUE_BATCH_LIMIT = 100;

	private ReadyToShipCron $cron;

	public function __construct( ReadyToShipCron $cron ) {
		$this->cron = $cron;
	}

	public function register(): void {
		add_action( 'woocommerce_product_set_stock_status', array( $this, 'on_stock_status_change' ), 10, 3 );
		add_action( self::ASYNC_HOOK, array( $this, 'process_product_change' ) );
	}

	/**
	 * Hook handler de `woocommerce_product_set_stock_status`. Filtra solo
	 * transiciones a `instock` y agenda un worker corto por producto.
	 *
	 * WC dispara este hook cuando el `stock_status` cambia al guardar (no en cada
	 * save). El handler async es idempotente igual — recalcula pending y no flippea
	 * si nada cambió. Costo: a lo sumo 1 acción async extra por transición.
	 *
	 * @param int    $product_id ID del producto cuyo stock cambió.
	 * @param string $new_status Nuevo stock_status (instock|outofstock|onbackorder).
	 * @param mixed  $product    Instancia WC_Product (no usado — solo necesitamos el ID).
	 * @return void
	 */
	public function on_stock_status_change( $product_id, $new_status, $product = null ): void {
		unset( $product );

		if ( StockStatus::IN_STOCK !== (string) $new_status ) {
			return;
		}
		$product_id = (int) $product_id;
		if ( $product_id <= 0 ) {
			return;
		}

		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			// Action Scheduler debería estar disponible vía WC core. Si no, fallback
			// sincrónico — único caso en environments dev sin AS instalado.
			$this->process_product_change( $product_id );
			return;
		}

		$args = array( 'product_id' => $product_id );

		// Dedup POR ARGS (no el $unique global, que ignora args y colapsaría un
		// restock masivo a un solo producto): si ya hay un job pendiente para
		// ESTE product_id, no encolar otro.
		if ( function_exists( 'as_has_scheduled_action' )
			&& \as_has_scheduled_action( self::ASYNC_HOOK, $args, self::GROUP ) ) {
			return;
		}

		\as_schedule_single_action(
			time() + self::DEBOUNCE_SECONDS,
			self::ASYNC_HOOK,
			$args,
			self::GROUP
		);
	}

	/**
	 * Handler async: reclama siblings libres desde un store DB aislado y procesa
	 * sus IDs junto al producto actual. Las acciones ya reclamadas por otro runner
	 * no se tocan. Si el store de tablas no está disponible, cae al scan seguro de
	 * siblings sin consumirlos.
	 *
	 * @param int $product_id ID del producto que pasó a instock.
	 * @return void
	 */
	public function process_product_change( $product_id ): void {
		$product_id = (int) $product_id;
		if ( $product_id <= 0 ) {
			return;
		}

		$claimed = $this->claim_pending_product_actions();
		$pending_product_ids = null !== $claimed['store']
			? $claimed['product_ids']
			: $this->pending_product_ids();
		$product_map = array_fill_keys(
			array_values( array_unique( array_merge( array( $product_id ), $pending_product_ids ) ) ),
			true
		);

		try {
			OrderQueryPaginator::process_all(
				$this->candidate_order_query_args(),
				function ( WC_Order $order ) use ( $product_map ): void {
					if ( $this->order_has_any_product( $order, $product_map ) ) {
						$this->cron->process_order( $order );
					}
				}
			);

			foreach ( $claimed['action_ids'] as $action_id ) {
				$claimed['store']->mark_complete( $action_id );
			}
		} finally {
			if ( null !== $claimed['claim'] && null !== $claimed['store'] ) {
				$claimed['store']->release_claim( $claimed['claim'] );
			}
		}
	}

	/**
	 * Reclama siblings libres usando un store DB fresco. Evita los filtros mutables
	 * del store global y el TOCTOU de consultar y cancelar una acción que otro
	 * runner puede reclamar entre ambas operaciones.
	 *
	 * @return array{action_ids: array<int, int>, claim: \ActionScheduler_ActionClaim|null, product_ids: array<int, int>, store: \ActionScheduler_DBStore|null}
	 */
	private function claim_pending_product_actions(): array {
		$claimed = array(
			'action_ids'  => array(),
			'claim'       => null,
			'product_ids' => array(),
			'store'       => null,
		);

		if ( ! class_exists( '\ActionScheduler_DBStore' ) || ! function_exists( 'as_get_datetime_object' ) ) {
			return $claimed;
		}

		try {
			$store = new \ActionScheduler_DBStore();
			$claim = $store->stake_claim(
				self::QUEUE_BATCH_LIMIT,
				// Los siblings del mismo burst pueden seguir dentro de su debounce
				// cuando un test/manual dispatch procesa el primer producto.
				\as_get_datetime_object( time() + self::DEBOUNCE_SECONDS ),
				array( self::ASYNC_HOOK ),
				self::GROUP
			);
		} catch ( \Throwable $error ) {
			// Si no se reclaman siblings, continuar con el producto actual y registrar
			// la causa. Los demás productos conservan su propio worker pendiente.

			\Akibara\Business\Utilities\Log::warn(
				'preventa',
				sprintf( 'stake_claim de siblings falló en el restock batch: %s — se procesa solo el producto actual.', $error->getMessage() )
			);
			return $claimed;
		}

		$claimed['claim'] = $claim;
		$claimed['store'] = $store;

		try {
			foreach ( $claim->get_actions() as $action_id ) {
				$action = $store->fetch_action( $action_id );
				$args   = is_object( $action ) && is_callable( array( $action, 'get_args' ) ) ? (array) $action->get_args() : array();

				$claimed['action_ids'][] = (int) $action_id;
				if ( isset( $args['product_id'] ) && (int) $args['product_id'] > 0 ) {
					$claimed['product_ids'][] = (int) $args['product_id'];
				}
			}
		} catch ( \Throwable $error ) {
			\Akibara\Business\Utilities\Log::warn(
				'preventa',
				sprintf( 'fetch_action de siblings reclamados falló: %s — se libera el claim y el batch continúa sin ellos.', $error->getMessage() )
			);
			$store->release_claim( $claim );
			return array(
				'action_ids'  => array(),
				'claim'       => null,
				'product_ids' => array(),
				'store'       => null,
			);
		}

		$claimed['product_ids'] = array_values( array_unique( $claimed['product_ids'] ) );

		return $claimed;
	}

	/**
	 * Fallback cuando el store de tablas no está disponible. Devuelve IDs de
	 * acciones pendientes no reclamadas sin consumirlas.
	 *
	 * @return array<int, int>
	 */
	private function pending_product_ids(): array {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return array();
		}

		$actions = \as_get_scheduled_actions(
			array(
				'hook'     => self::ASYNC_HOOK,
				'group'    => self::GROUP,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'claimed'  => false,
				'per_page' => self::QUEUE_BATCH_LIMIT,
				'orderby'  => 'date',
				'order'    => 'ASC',
			)
		);

		$product_ids = array();
		foreach ( $actions as $action ) {
			if ( ! is_object( $action ) || ! is_callable( array( $action, 'get_args' ) ) ) {
				continue;
			}

			$args = (array) $action->get_args();
			if ( isset( $args['product_id'] ) && (int) $args['product_id'] > 0 ) {
				$product_ids[] = (int) $args['product_id'];
			}
		}

		return array_values( array_unique( $product_ids ) );
	}

	/**
	 * Consulta pedidos con reserva y estado de listo distinto de yes o ausente.
	 *
	 * El paginador inverso conserva candidatos al cambiar su estado. Se excluye
	 * on-hold salvo que un adaptador declare otro flujo operacional. Los candidatos
	 * proceden de pedidos y líneas autoritativas, no de la lookup de Analytics,
	 * que puede retrasarse respecto de los ítems reales. El filtro has_preventa=yes
	 * acota el trabajo a reservas y FETCH_LIMIT limita cada página, no el conjunto.
	 * La ausencia de la marca de listo sigue siendo un candidato legítimo.
	 *
	 * @return array<int, WC_Order>
	 */
	private function candidate_order_query_args(): array {
		return array(
				'limit'      => self::FETCH_LIMIT,
				'status'     => FulfillmentCapabilities::active_order_statuses(),
				'meta_query' => array(
					'relation' => 'AND',
					array(
						'key'   => MetaKeys::ORDER_HAS_PREVENTA,
						'value' => 'yes',
					),
					array(
						'relation' => 'OR',
						array(
							'key'     => MetaKeys::ORDER_READY_TO_SHIP,
							'value'   => 'yes',
							'compare' => '!=',
						),
						array(
							'key'     => MetaKeys::ORDER_READY_TO_SHIP,
							'compare' => 'NOT EXISTS',
						),
					),
				),
				'return'     => 'objects',
				'orderby'    => 'date',
				'order'      => 'ASC',
		);
	}

	/**
	 * Verifica si la orden contiene cualquier producto del lote, considerando
	 * tanto product como variation parent.
	 *
	 * @param WC_Order        $order       Orden a inspeccionar.
	 * @param array<int, bool> $product_map IDs de producto del lote.
	 * @return bool
	 */
	private function order_has_any_product( WC_Order $order, array $product_map ): bool {
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			if ( isset( $product_map[ (int) $item->get_product_id() ] ) ) {
				return true;
			}
			if ( isset( $product_map[ (int) $item->get_variation_id() ] ) ) {
				return true;
			}
		}
		return false;
	}
}
