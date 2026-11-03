<?php
/**
 * Detecta reposiciones mediante los hooks de stock de producto y variación.
 * Corre después del listener de pedidos reservables y sólo encola si el nuevo
 * estado es instock y quedan suscriptores del producto. La detección permanece
 * activa aunque el despacho esté apagado; el dispatcher gobierna el envío.
 * BIS avisa al suscriptor de ese tomo; SeriesFollow sigue publicaciones nuevas
 * de series compradas y no comparte este disparador.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\BackInStock;

use Akibara\Business\Modules\Stock\StockStatus;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.53.0
 */
final class StockWatcher {

	/** Hook async que consume NotificationDispatcher. */
	public const ASYNC_HOOK = 'akb_bis_notify_product';

	/** Grupo Action Scheduler del módulo. */
	public const AS_GROUP = 'akibara-bis';

	/** @var SubscriberRepository */
	private SubscriberRepository $repository;

	/**
	 * @param SubscriberRepository $repository Persistencia.
	 */
	public function __construct( SubscriberRepository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * @return void
	 */
	public function register(): void {
		// Prio 11: después de OrderPreventa\StockChangeListener (10) — orden
		// determinístico en el hook compartido.
		add_action( 'woocommerce_product_set_stock_status', array( $this, 'on_stock_status_change' ), 11, 2 );
		add_action( 'woocommerce_variation_set_stock_status', array( $this, 'on_stock_status_change' ), 11, 2 );
	}

	/**
	 * Guard + enqueue. El id que se encola es EXACTAMENTE el que transicionó
	 * (variación incluida): la suscripción se hizo sobre ese id.
	 *
	 * @param int    $product_id ID del producto/variación.
	 * @param string $new_status Estado nuevo ('instock'|'outofstock'|'onbackorder').
	 * @return void
	 */
	public function on_stock_status_change( $product_id, $new_status ): void {
		$product_id = (int) $product_id;

		if ( $product_id <= 0 || ! BackInStock::is_notifiable_status( (string) $new_status ) ) {
			return;
		}

		if ( $this->repository->count_waiting( $product_id ) < 1 ) {
			return; // No-op barato: nadie espera este producto.
		}

		$this->enqueue( $product_id );
	}

	/**
	 * Deduplica trabajos por producto con as_has_scheduled_action y sus args.
	 * No usa el unique global de enqueue para agrupar reposiciones de productos
	 * distintos. El claim del repositorio conserva la garantía de envío único
	 * aunque llegue un job redundante.
	 *
	 * @param int $product_id Producto a notificar.
	 * @return void
	 */
	private function enqueue( int $product_id ): void {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			$args = array( 'product_id' => $product_id );

			if ( function_exists( 'as_has_scheduled_action' )
				&& as_has_scheduled_action( self::ASYNC_HOOK, $args, self::AS_GROUP ) ) {
				return; // Ya hay job pendiente para ESTE producto.
			}

			as_enqueue_async_action( self::ASYNC_HOOK, $args, self::AS_GROUP );
			return;
		}

		// Fallback sin Action Scheduler (entornos de test/CLI pelados):
		// despacho inline en shutdown para no alargar el request del admin.
		add_action(
			'shutdown',
			static function () use ( $product_id ): void {
				do_action( self::ASYNC_HOOK, $product_id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- self::ASYNC_HOOK vale «akb_bis_notify_product»: sí lleva prefijo, el sniff no resuelve la constante.
			}
		);
	}
}
