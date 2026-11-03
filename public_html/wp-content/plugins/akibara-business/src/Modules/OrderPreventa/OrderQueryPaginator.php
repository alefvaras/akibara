<?php
/**
 * Paginación segura de órdenes WooCommerce para procesos de preventa.
 *
 * Procesa desde la última página hacia la primera: si una orden deja de
 * cumplir el filtro durante el procesamiento, las páginas inferiores no se
 * desplazan y no quedan órdenes sin evaluar por el cambio de conjunto.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\OrderPreventa;

use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * Iterador paginado para consultas HPOS-compatible vía wc_get_orders().
 *
 * @since 1.87.0
 */
final class OrderQueryPaginator {

	/**
	 * Procesa con callback el conjunto de pedidos capturado al iniciar la pasada.
	 *
	 * Pagina desde el final para que sacar candidatos del conjunto no desplace los
	 * pendientes y omita páginas. Los candidatos incorporados durante la ejecución
	 * quedan para la siguiente pasada. Cada pedido se aísla mediante try/catch: un
	 * fallo se registra sin impedir procesar los demás pedidos ni páginas.
	 *
	 * limit es el tamaño de página; max_total es el tope opcional de trabajo.
	 *
	 * @param array<string, mixed> $query_args Args compatibles con wc_get_orders.
	 * @param callable             $processor  Recibe una instancia WC_Order.
	 * @param int                  $max_total  Tope de órdenes procesadas en esta pasada.
	 *                                         0 (default) = sin tope. Cuidado al fijarlo:
	 *                                         el recorrido va de la ÚLTIMA página a la
	 *                                         primera, así que un tope bajo deja
	 *                                         permanentemente fuera al extremo opuesto
	 *                                         del `orderby` (con `date ASC`, las órdenes
	 *                                         más antiguas — justo las que el stale-note
	 *                                         debe cazar). Usar solo valores holgados.
	 * @return void
	 */
	public static function process_all( array $query_args, callable $processor, int $max_total = 0 ): void {
		$query_args['paginate'] = true;
		$query_args['page']     = 1;
		$query_args['return']   = 'objects';

		$first_page = wc_get_orders( $query_args );
		if ( ! is_object( $first_page ) || ! isset( $first_page->orders ) || ! is_array( $first_page->orders ) ) {
			return;
		}

		$processed = 0;
		$max_pages = max( 1, (int) ( $first_page->max_num_pages ?? 1 ) );
		for ( $page = $max_pages; $page >= 1; --$page ) {
			$result = 1 === $page ? $first_page : self::fetch_page( $query_args, $page );
			if ( ! is_object( $result ) || ! isset( $result->orders ) || ! is_array( $result->orders ) ) {
				continue;
			}

			foreach ( $result->orders as $order ) {
				if ( ! $order instanceof WC_Order ) {
					continue;
				}

				try {
					$processor( $order );
				} catch ( \Throwable $error ) {
					// Mismo canal que el resto de las rutas async del módulo:
					// la orden queda identificada en WooCommerce → Estado →
					// Registros en vez de desaparecer con la pasada entera.
					\Akibara\Business\Utilities\Log::warn(
						'preventa',
						sprintf(
							'process_all: la orden %d falló (%s) — se omite y la pasada continúa.',
							$order->get_id(),
							$error->getMessage()
						)
					);
				}

				++$processed;
				if ( $max_total > 0 && $processed >= $max_total ) {
					return;
				}
			}
		}
	}

	/**
	 * @param array<string, mixed> $query_args Args base.
	 * @param int                  $page       Página solicitada.
	 * @return mixed
	 */
	private static function fetch_page( array $query_args, int $page ) {
		$query_args['page'] = $page;

		return wc_get_orders( $query_args );
	}

	private function __construct() {}
}