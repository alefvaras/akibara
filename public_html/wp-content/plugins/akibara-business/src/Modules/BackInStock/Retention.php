<?php
/**
 * Retention — minimización de datos de la lista de espera.
 *
 * Política:
 * - `subscribed` se conserva mientras exista demanda pendiente.
 * - estados terminales se purgan a los 180 días (filtrable, mínimo 30).
 * - al eliminar un producto o variación, sus filas se borran de inmediato.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\BackInStock;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.89.0
 */
final class Retention {

	public const HOOK = 'akb_bis_cleanup_terminal';

	private const DEFAULT_DAYS = 180;

	/** @var SubscriberRepository */
	private SubscriberRepository $repository;

	public function __construct( SubscriberRepository $repository ) {
		$this->repository = $repository;
	}

	public function register(): void {
		add_action( 'init', array( $this, 'maybe_schedule' ) );
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( 'before_delete_post', array( $this, 'erase_product_subscriptions' ) );
	}

	/**
	 * Garantiza un evento diario sin duplicarlo.
	 */
	public function maybe_schedule(): void {
		if ( wp_next_scheduled( self::HOOK ) ) {
			return;
		}

		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
	}

	/**
	 * Ejecuta la purga de estados terminales.
	 *
	 * @return int Filas borradas.
	 */
	public function run(): int {
		/**
		 * Días de retención para estados terminales de BackInStock.
		 *
		 * @since 1.89.0
		 *
		 * @param int $days Default 180; mínimo 30.
		 */
		$days   = max( 30, (int) apply_filters( 'akb_bis_retention_days', self::DEFAULT_DAYS ) );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		return $this->repository->purge_terminal_before( $cutoff );
	}

	/**
	 * Evita filas huérfanas cuando se elimina definitivamente un producto.
	 *
	 * @param int $post_id Post eliminado.
	 */
	public function erase_product_subscriptions( int $post_id ): void {
		if ( ! in_array( get_post_type( $post_id ), array( 'product', 'product_variation' ), true ) ) {
			return;
		}

		$this->repository->erase_product( $post_id );
	}
}
