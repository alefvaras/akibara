<?php
/**
 * UnpaidOrdersSweeper — Barrido automático de pedidos pendientes sin pago tras 24 horas.
 *
 * En WooCommerce, las órdenes abandonadas en estado 'pending' ('wc-pending') retienen stock
 * o quedan estancadas si la acción programada de WooCommerce no se ejecuta o si la pasarela
 * de pago (ej. Mercado Pago) intercepta la cancelación automática.
 *
 * Este módulo garantiza:
 * 1. Cancelación forzosa tras 24 horas vía cron diario (akb_unpaid_orders_sweep), liberando stock.
 * 2. Filtro prioritario en woocommerce_cancel_unpaid_order para permitir la cancelación si la orden
 *    supera las 24 horas sin pago.
 * 3. Comando WP-CLI: `wp akb-orders sweep-unpaid [--dry-run] [--hours=24] [--limit=50]`.
 *
 * @package Akibara\Business\Modules\Stock
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\Stock;

use WC_Order;

defined( 'ABSPATH' ) || exit;

final class UnpaidOrdersSweeper {

	public const HOOK = 'akb_unpaid_orders_sweep';

	private const SCHEDULE = 'daily';

	public const DEFAULT_EXPIRY_HOURS = 24;

	public const SWEEP_CAP = 50;

	public function register(): void {
		add_filter( 'woocommerce_cancel_unpaid_order', array( $this, 'filter_cancel_unpaid_order' ), 25, 2 );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'akb-orders sweep-unpaid', array( $this, 'cli_sweep_unpaid' ) );
		}
	}

	/**
	 * Permite la cancelación de órdenes pendientes que hayan superado las 24 horas,
	 * evitando que pasarelas de pago bloqueen la cancelación de órdenes abandonadas.
	 *
	 * Solo aplica a órdenes de clientes creadas vía checkout o Store API; preserva
	 * pedidos manuales de administración.
	 *
	 * @param bool     $should_cancel Decisión previa de cancelación.
	 * @param WC_Order $order         Orden evaluada.
	 * @return bool
	 */
	public function filter_cancel_unpaid_order( bool $should_cancel, $order ): bool {
		if ( ! $order instanceof WC_Order ) {
			return $should_cancel;
		}

		// Solo cancelar órdenes originadas en checkout público (clásico o Store API), jamás pedidos manuales de administración.
		if ( method_exists( $order, 'get_created_via' ) && ! in_array( $order->get_created_via(), array( 'checkout', 'store-api' ), true ) ) {
			return false;
		}

		if ( $order->has_status( array( 'pending', 'wc-pending' ) ) ) {
			$created = $order->get_date_created();
			if ( $created && ( time() - $created->getTimestamp() ) >= ( self::DEFAULT_EXPIRY_HOURS * HOUR_IN_SECONDS ) ) {
				return true;
			}
		}

		return $should_cancel;
	}

	/**
	 * Ejecuta el barrido y cancelación de órdenes pendientes sin pago.
	 *
	 * @param bool $dry_run Si es true, solo simula sin cancelar.
	 * @param int  $hours   Antigüedad mínima en horas (default 24).
	 * @param int  $limit   Límite de órdenes a procesar (default 50).
	 * @return array{cancelled_count: int, order_ids: list<int>, dry_run: bool}
	 */
	public function sweep( bool $dry_run = false, int $hours = self::DEFAULT_EXPIRY_HOURS, int $limit = self::SWEEP_CAP ): array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array(
				'cancelled_count' => 0,
				'order_ids'       => array(),
				'dry_run'         => $dry_run,
			);
		}

		$cutoff_time = time() - ( $hours * HOUR_IN_SECONDS );

		$orders = wc_get_orders(
			array(
				'status'       => array( 'pending', 'wc-pending' ),
				'date_created' => '<' . $cutoff_time,
				'limit'        => $limit,
				'return'       => 'objects',
				'orderby'      => 'date',
				'order'        => 'ASC',
			)
		);

		$cancelled_ids = array();

		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			if ( ! $order->has_status( array( 'pending', 'wc-pending' ) ) ) {
				continue;
			}

			$order_id = $order->get_id();

			if ( ! $dry_run ) {
				$note    = 'Cancelada automáticamente: pedido pendiente sin pago por más de 24 horas.';
				$success = $order->update_status( 'cancelled', $note );
				if ( $success ) {
					$cancelled_ids[] = $order_id;
				}
			} else {
				$cancelled_ids[] = $order_id;
			}
		}

		return array(
			'cancelled_count' => count( $cancelled_ids ),
			'order_ids'       => $cancelled_ids,
			'dry_run'         => $dry_run,
		);
	}

	/**
	 * Handler de WP-Cron.
	 *
	 * @return int Cantidad de órdenes canceladas.
	 */
	public function handle(): int {
		$result = $this->sweep( false, self::DEFAULT_EXPIRY_HOURS, self::SWEEP_CAP );
		return $result['cancelled_count'];
	}

	/**
	 * Comando WP-CLI: `wp akb-orders sweep-unpaid [--dry-run] [--hours=<n>] [--limit=<n>]`
	 *
	 * @param list<string>         $args       Argumentos posicionales.
	 * @param array<string, mixed> $assoc_args Argumentos asociativos.
	 */
	public function cli_sweep_unpaid( array $args, array $assoc_args ): void {
		$dry_run = isset( $assoc_args['dry-run'] );
		$hours   = isset( $assoc_args['hours'] ) ? max( 1, (int) $assoc_args['hours'] ) : self::DEFAULT_EXPIRY_HOURS;
		$limit   = isset( $assoc_args['limit'] ) ? max( 1, (int) $assoc_args['limit'] ) : self::SWEEP_CAP;

		\WP_CLI::log( sprintf( 'Iniciando barrido de órdenes pendientes sin pago (> %d horas, límite %d, dry-run: %s)...', $hours, $limit, $dry_run ? 'sí' : 'no' ) );

		$res = $this->sweep( $dry_run, $hours, $limit );

		if ( 0 === $res['cancelled_count'] ) {
			\WP_CLI::success( 'No se encontraron órdenes pendientes sin pago que superen el límite.' );
			return;
		}

		foreach ( $res['order_ids'] as $oid ) {
			\WP_CLI::log( sprintf( '  - Orden #%d: %s', $oid, $dry_run ? '[DRY-RUN] Se cancelaría' : 'Cancelada exitosamente' ) );
		}

		\WP_CLI::success( sprintf( 'Total órdenes procesadas: %d.', $res['cancelled_count'] ) );
	}
}
