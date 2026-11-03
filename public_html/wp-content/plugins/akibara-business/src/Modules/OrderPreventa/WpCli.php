<?php
/**
 * Comandos WP-CLI para back-fill de órdenes históricas y trigger manual del
 * cron.
 *
 * Uso:
 *   wp akibara order-preventa-backfill [--dry-run] [--limit=N]
 *   wp akibara order-preventa-recompute-ready [--order-id=N]
 *   wp akibara order-preventa-cumplimiento [--format=table|json|csv]
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\OrderPreventa;

use Akibara\Business\Modules\Stock\StockStatus;
use WC_Order;
use WC_Order_Item_Product;
use WC_Product;
use WP_CLI;
use Akibara\Business\Contracts\EtaProvider;

defined( 'ABSPATH' ) || exit;

/**
 * Handlers WP-CLI registrados solo cuando WP_CLI está activo.
 *
 * @since 1.14.0
 */
final class WpCli {

	private const BACKFILL_PAGE_SIZE = 100;

	private EtaProvider $calculator;
	private ReadyToShipCron $cron;
	private SummaryRecalculator $recalculator;

	/** Dueño único de qué metas lleva una línea de preventa. */
	private LineItemSnapshot $snapshot;

	public function __construct( EtaProvider $calculator, ReadyToShipCron $cron, SummaryRecalculator $recalculator, LineItemSnapshot $snapshot ) {
		$this->calculator   = $calculator;
		$this->cron         = $cron;
		$this->recalculator = $recalculator;
		$this->snapshot     = $snapshot;
	}

	public function register(): void {
		if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		WP_CLI::add_command( 'akibara order-preventa-backfill', array( $this, 'cmd_backfill' ) );
		WP_CLI::add_command( 'akibara order-preventa-recompute-ready', array( $this, 'cmd_recompute_ready' ) );
		WP_CLI::add_command( 'akibara order-preventa-cumplimiento', array( $this, 'cmd_cumplimiento' ) );
	}

	/**
	 * Pone la fecha prometida al lado de la que ocurrió, pedido por pedido.
	 *
	 * Existe porque los plazos por país se fijaron a ojo y nunca hubo cómo saber
	 * si se cumplen: la promesa vive en el snapshot del pedido y la realidad en
	 * otra meta. Ver {@see PromiseAudit} para el criterio.
	 *
	 * QUÉ SIGNIFICA «listo» acá, que no es obvio: se prefiere
	 * `ORDER_SHIPPABLE_AT` —el momento en que el pedido entró de hecho en un
	 * estado despachable— y se cae a `ORDER_READY_DATE`, que es cuando el cron
	 * vio todos los items de vuelta en stock. Los dos pueden separarse mucho: en
	 * el pedido 24483 el dueño lo despachó 39 días antes de que el cron lo
	 * detectara, y usando sólo la señal del cron el informe lo llamaba «32 días
	 * tarde» cuando el cliente lo recibió en plazo. Los pedidos anteriores al
	 * 1.108.22 sólo tienen la señal del cron y salen marcados en la columna
	 * `fuente`.
	 *
	 * Sólo lee: no escribe ninguna meta ni cambia el estado de un pedido.
	 *
	 * @param array<int, string>   $args       Positional.
	 * @param array<string, mixed> $assoc_args Flags.
	 * @return void
	 */
	public function cmd_cumplimiento( array $args, array $assoc_args ): void {
		unset( $args );

		$audit  = new PromiseAudit();
		$ahora  = time();
		$filas  = array();
		$por_pais = array();

		// Paginado, no `limit => -1`. El módulo ya tiene `OrderQueryPaginator`
		// precisamente porque hidratar todos los pedidos de la historia —con sus
		// line items y su itemmeta— agota la memoria antes de imprimir una fila.
		// Hoy son decenas de pedidos y no se nota; el día que sean miles, el
		// informe moriría justo cuando por fin tendría muestra suficiente.
		OrderQueryPaginator::process_all(
			array(
				'limit'      => 100,
				'type'       => 'shop_order',
				'status'     => 'any',
				'meta_key'   => MetaKeys::ORDER_HAS_PREVENTA,
				'meta_value' => 'yes',
				'orderby'    => 'date',
				'order'      => 'ASC',
			),
			function ( WC_Order $order ) use ( $audit, $ahora, &$filas, &$por_pais ): void {
				// Excluir cancelados, reembolsados y fallidos del cumplimiento de llegada.
				// Sus marcas de listo no representan una entrega anticipada al cliente.

				if ( ! PromiseWindow::auditable( $order ) ) {
					return;
				}

				// La MISMA regla que la pantalla «Por llegar» (PromiseWindow): si
				// el comando y la pantalla divergen, uno de los dos miente.
				$promesa = PromiseWindow::vigente( $order );
				if ( null === $promesa['pais'] ) {
					// Sin país en el snapshot no hay plazo que auditar: es un pedido
					// anterior a que existieran estas metas, no un incumplimiento.
					return;
				}

				$created = $order->get_date_created();
				if ( null === $created ) {
					return;
				}

				$despachable = (int) $order->get_meta( MetaKeys::ORDER_SHIPPABLE_AT );
				$del_cron    = (int) $order->get_meta( MetaKeys::ORDER_READY_DATE );
				$listo       = $despachable > 0 ? $despachable : $del_cron;
				$fuente      = $despachable > 0 ? 'estado' : ( $del_cron > 0 ? 'cron' : '—' );

				$v = $audit->veredicto(
					$created->getTimestamp(),
					$promesa['min'],
					$promesa['max'],
					$listo > 0 ? $listo : null,
					$ahora
				);

				$filas[] = array(
					'pedido'     => $order->get_id(),
					'pais'       => $promesa['pais'],
					'politica'   => $promesa['marca'] ?? 'país',
					'estado'     => $order->get_status(),
					'comprado'   => $created->date( 'Y-m-d' ),
					'prometido'  => null === $promesa['max'] ? '—' : wp_date( 'Y-m-d', $promesa['max'] ),
					'listo'      => $listo > 0 ? wp_date( 'Y-m-d', $listo ) : '—',
					'dias_reales'=> $v['dias_reales'] ?? '—',
					'veredicto'  => $v['veredicto'],
					'desvio'     => $v['desvio_dias'] ?? '—',
					'fuente'     => $fuente,
					'promesa'    => empty( $promesa['recalcada'] ) ? 'compra' : 'recalculada',
				);

				// Agrupar por la editorial cuando tiene política propia, por el país
				// cuando hereda. Es la única forma de que cada línea del resumen
				// describa UNA promesa y no la media de dos.
				$grupo = null !== ( $promesa['marca'] ?? null )
					? $promesa['marca'] . ' (editorial)'
					: $promesa['pais'];

				$por_pais[ $grupo ][] = $v;
			}
		);

		// El paginador recorre de la última página a la primera; el informe se lee
		// mejor en orden cronológico.
		usort( $filas, static fn( array $a, array $b ): int => strcmp( (string) $a['comprado'], (string) $b['comprado'] ) );

		if ( array() === $filas ) {
			WP_CLI::warning( 'Ningún pedido con preventa tiene snapshot de promesa: no hay nada que contrastar.' );
			return;
		}

		$formato = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';
		\WP_CLI\Utils\format_items( $formato, $filas, array( 'pedido', 'pais', 'estado', 'comprado', 'prometido', 'listo', 'dias_reales', 'veredicto', 'desvio', 'fuente', 'promesa', 'politica' ) );

		WP_CLI::log( '' );
		foreach ( $por_pais as $grupo => $veredictos ) {
			$r = $audit->resumen( $veredictos );
			WP_CLI::log(
				sprintf(
					'%s — %d cerrados (%d dentro, %d tarde, %d antes), %d abiertos, %d vencidos. Peor atraso: %s. Espera real máxima: %s.',
					$grupo,
					$r['cerrados'],
					$r['dentro'],
					$r['tarde'],
					$r['antes'],
					$r['abiertos'],
					$r['vencidos'],
					null === $r['peor_atraso'] ? 'sin datos' : $r['peor_atraso'] . ' días',
					null === $r['dias_reales_max'] ? 'sin datos' : $r['dias_reales_max'] . ' días'
				)
			);
		}

		// Decir el tamaño de la muestra es parte del informe, no una nota al pie:
		// con un puñado de pedidos ningún porcentaje es reportable, y ese es
		// justo el error que este comando existe para evitar.
		WP_CLI::log( '' );
		WP_CLI::log( sprintf( 'Muestra: %d pedidos con promesa guardada. Con menos de ~20 cerrados por país, léelo como anécdota, no como tasa.', count( $filas ) ) );
	}

	/**
	 * Itera órdenes históricas y pinta los metas de snapshot a posteriori.
	 *
	 * Productos en estado actual onbackorder → marcamos was_preventa=yes y
	 * computamos ETA con DateCalculator (será el ETA "como si comprara hoy",
	 * no el ETA real al momento de compra — pero es lo mejor que podemos hacer
	 * sin meta histórico).
	 *
	 * Productos en estado actual instock → was_preventa=unknown (no podemos
	 * saber con certeza si era onbackorder al momento de compra).
	 *
	 * @param array<int, string>   $args       Positional.
	 * @param array<string, mixed> $assoc_args Flags.
	 * @return void
	 */
	public function cmd_backfill( array $args, array $assoc_args ): void {
		unset( $args );
		$dry_run = isset( $assoc_args['dry-run'] );
		$limit   = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 200;
		$limit   = -1 === $limit ? -1 : max( 1, $limit );

		$stats = array(
			'has_preventa' => 0,
			'unknown'      => 0,
			'no_preventa'  => 0,
		);

		$processed = 0;
		$this->for_each_untagged_order(
			$limit,
			function ( WC_Order $order ) use ( $dry_run, &$stats, &$processed ): void {
				$result = $this->backfill_one( $order, $dry_run );
				++$stats[ $result ];
				++$processed;
			}
		);

		WP_CLI::success(
			sprintf(
				'Procesadas %d órdenes — con preventa: %d · unknown: %d · sin preventa: %d',
				$processed,
				$stats['has_preventa'],
				$stats['unknown'],
				$stats['no_preventa']
			)
		);
	}

	/**
	 * Recorre órdenes históricas sin snapshot usando páginas de WooCommerce.
	 *
	 * La consulta no filtra por meta porque `meta_query` no es portable al data
	 * store legacy. El orden por fecha permanece estable mientras el backfill
	 * escribe solamente metas, por lo que paginar ascendentemente no salta filas.
	 *
	 * @param int      $limit    Máximo de órdenes sin tag a entregar (-1 = todas).
	 * @param callable $callback Recibe una WC_Order no taggeada.
	 * @return void
	 */
	private function for_each_untagged_order( int $limit, callable $callback ): void {
		$page      = 1;
		$delivered = 0;
		$max_pages = 1;

		while ( $page <= $max_pages && ( -1 === $limit || $delivered < $limit ) ) {
			$result = wc_get_orders(
				array(
					'limit'    => self::BACKFILL_PAGE_SIZE,
					'page'     => $page,
					'paginate' => true,
					'status'   => array( 'processing', 'on-hold', 'completed' ),
					'return'   => 'objects',
					'orderby'  => 'date',
					'order'    => 'DESC',
				)
			);
			if ( ! is_object( $result ) || ! isset( $result->orders ) || ! is_array( $result->orders ) ) {
				return;
			}

			$max_pages = max( 1, (int) ( $result->max_num_pages ?? 1 ) );
			foreach ( $result->orders as $order ) {
				if ( ! $order instanceof WC_Order ) {
					continue;
				}
				if ( '' !== (string) $order->get_meta( MetaKeys::ORDER_HAS_PREVENTA, true ) ) {
					continue;
				}

				$callback( $order );
				++$delivered;
				if ( -1 !== $limit && $delivered >= $limit ) {
					return;
				}
			}

			++$page;
		}
	}

	/**
	 * Back-fill best-effort de una sola orden.
	 *
	 * `ORDER_SUMMARY_MAX` delegado a `SummaryRecalculator::recompute_for_order`
	 * (): los ítems mutados arriba son los mismos objetos en memoria de
	 * `$order->get_items()`, así que el recompute lee el snapshot recién
	 * escrito; la semántica delete-vs-zero es la misma del checkout.
	 *
	 * @param WC_Order $order   Orden.
	 * @param bool     $dry_run Si true, no persiste cambios.
	 * @return string Resultado: has_preventa | unknown | no_preventa.
	 */
	private function backfill_one( WC_Order $order, bool $dry_run ): string {
		$pending_count = 0;
		$any_unknown   = false;

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$product = $item->get_product();
			if ( ! $product instanceof WC_Product ) {
				if ( ! $dry_run ) {
					$item->update_meta_data( MetaKeys::ITEM_WAS_PREVENTA, 'unknown' );
					$item->save();
				}
				$any_unknown = true;
				continue;
			}

			$stock_now          = StockStatus::of( $product );
			$was_preventa_value = StockStatus::ON_BACKORDER === $stock_now ? 'yes' : ( StockStatus::IN_STOCK === $stock_now ? 'unknown' : 'no' );

			if ( $dry_run ) {
				if ( 'yes' === $was_preventa_value ) {
					++$pending_count;
				} elseif ( 'unknown' === $was_preventa_value ) {
					$any_unknown = true;
				}
				continue;
			}

			$item->update_meta_data( MetaKeys::ITEM_WAS_PREVENTA, $was_preventa_value );

			if ( 'yes' === $was_preventa_value ) {
				// LineItemSnapshot es la fuente compartida de metas de una línea reservable.

				$this->snapshot->stamp_preventa_details( $item, $product );

				++$pending_count;
			} elseif ( 'unknown' === $was_preventa_value ) {
				$any_unknown = true;
			}

			$item->save();
		}

		if ( $pending_count > 0 ) {
			if ( ! $dry_run ) {
				$order->update_meta_data( MetaKeys::ORDER_HAS_PREVENTA, 'yes' );
				$order->update_meta_data( MetaKeys::ORDER_READY_TO_SHIP, 'no' );
				$order->update_meta_data( MetaKeys::ORDER_PENDING_COUNT, $pending_count );
				$this->recalculator->recompute_for_order( $order );
				$order->add_order_note( sprintf( 'Back-fill: orden marcada con %d item(s) en preventa.', $pending_count ) );
				$order->save();
			}
			return 'has_preventa';
		}

		if ( $any_unknown ) {
			if ( ! $dry_run ) {
				$order->add_order_note( 'Back-fill: estado de preventa al momento de compra no determinable (productos hoy instock). Revisar manualmente si aplica.' );
				$order->save();
			}
			return 'unknown';
		}

		if ( ! $dry_run ) {
			$order->update_meta_data( MetaKeys::ORDER_HAS_PREVENTA, 'no' );
			$order->save();
		}
		return 'no_preventa';
	}

	/**
	 * Dispara una pasada del cron manualmente, o procesa una sola orden.
	 *
	 * @param array<int, string>   $args       Positional.
	 * @param array<string, mixed> $assoc_args Flags.
	 * @return void
	 */
	public function cmd_recompute_ready( array $args, array $assoc_args ): void {
		unset( $args );

		if ( isset( $assoc_args['order-id'] ) ) {
			$order_id = (int) $assoc_args['order-id'];
			$order    = wc_get_order( $order_id );
			if ( ! $order instanceof WC_Order ) {
				WP_CLI::error( sprintf( 'Orden %d no encontrada.', $order_id ) );
				return;
			}
			$this->cron->process_order( $order );
			WP_CLI::success( sprintf( 'Orden %d recomputada.', $order_id ) );
			return;
		}

		$this->cron->run();
		WP_CLI::success( 'Cron recompute-ready ejecutado.' );
	}
}
