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
 * @api
 * @psalm-suppress HookInvalidArgs
 */
final class StockWatcher {

	/** Hook async que consume NotificationDispatcher. */
	public const ASYNC_HOOK = 'akb_bis_notify_product';

	/** Grupo Action Scheduler del módulo. */
	public const AS_GROUP = 'akibara-bis';

	/** Segundos de separación defensiva entre productos distintos en reposición masiva. */
	public const STAGGER_SECONDS = 10;

	/** @var int Último timestamp programado en este proceso. */
	private static int $last_scheduled_timestamp = 0;

	/** @var SubscriberRepository */
	private SubscriberRepository $repository;

	/**
	 * @param SubscriberRepository $repository Persistencia.
	 */
	public function __construct( SubscriberRepository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Reinicia el estado del escalonador en memoria (pruebas).
	 *
	 * @return void
	 */
	public static function reset_stagger(): void {
		self::$last_scheduled_timestamp = 0;
	}

	/**
	 * @return void
	 */
	public function register(): void {
		// Prio 11: orden determinístico en el hook compartido.
		add_action( 'woocommerce_product_set_stock_status', array( $this, 'on_stock_status_change' ), 11, 2 );
		add_action( 'woocommerce_variation_set_stock_status', array( $this, 'on_stock_status_change' ), 11, 2 );
	}

	/**
	 * Guard + enqueue. El id que se encola es EXACTAMENTE el que transicionó
	 * (variación incluida): la suscripción se hizo sobre ese id.
	 *
	 * @param int    $product_id ID del producto/variación.
	 * @param string $new_status Estado nuevo ('instock'|'outofstock').
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
	 * Ante reposiciones masivas, escalona las citas entre productos distintos
	 * (5 a 10 s) para evitar ráfagas masivas en el transporte SMTP de Hostinger.
	 *
	 * @param int $product_id Producto a notificar.
	 * @return void
	 */
	private function enqueue( int $product_id ): void {
		$args = array( 'product_id' => $product_id );

		if ( function_exists( 'as_has_scheduled_action' )
			&& as_has_scheduled_action( self::ASYNC_HOOK, $args, self::AS_GROUP ) ) {
			return; // Ya hay job pendiente para ESTE producto.
		}

		$scheduled = $this->next_schedule_timestamp();
		$now       = time();

		if ( $scheduled > $now && function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( $scheduled, self::ASYNC_HOOK, $args, self::AS_GROUP );
			return;
		}

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::ASYNC_HOOK, $args, self::AS_GROUP );
			return;
		}

		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( $scheduled, self::ASYNC_HOOK, $args, self::AS_GROUP );
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

	/**
	 * Calcula el timestamp de ejecución escalonado para evitar ráfagas masivas en SMTP.
	 *
	 * @return int Timestamp Unix programado.
	 */
	private function next_schedule_timestamp(): int { // @phpstan-ignore complexity.functionLike
		$now    = time();
		$latest = self::$last_scheduled_timestamp;

		if ( function_exists( 'as_get_scheduled_actions' ) ) {
			try {
				$pending = as_get_scheduled_actions(
					array(
						'hook'     => self::ASYNC_HOOK,
						'group'    => self::AS_GROUP,
						'status'   => 'pending',
						'per_page' => 1,
						'orderby'  => 'schedule',
						'order'    => 'DESC',
					),
					'objects'
				);
			} catch ( \Throwable $e ) {
				$pending = array();
			}

			if ( is_array( $pending ) && ! empty( $pending ) ) {
				$action    = reset( $pending );
				$timestamp = 0;
				if ( is_object( $action ) && method_exists( $action, 'get_schedule' ) ) {
					$schedule = $action->get_schedule();
					if ( is_object( $schedule ) && method_exists( $schedule, 'get_date' ) ) {
						$date = $schedule->get_date();
						if ( is_object( $date ) && method_exists( $date, 'getTimestamp' ) ) {
							$timestamp = (int) $date->getTimestamp();
						}
					}
				}

				if ( $timestamp > 0 ) {
					$latest = max( $latest, $timestamp );
				} else {
					// NullSchedule (as_enqueue_async_action) o acción pending sin fecha futura:
					// lista para ejecutarse en $now. Se asigna $now para que peticiones
					// HTTP concurrentes respeten el escalonamiento defensivo (+10s).
					$latest = max( $latest, $now );
				}
			}
		} elseif ( function_exists( 'as_next_scheduled_action' ) ) {
			try {
				$next = as_next_scheduled_action( self::ASYNC_HOOK, null, self::AS_GROUP );
				if ( is_int( $next ) && $next > 0 ) {
					$latest = max( $latest, $next );
				}
			} catch ( \Throwable $e ) {
				// Fallback seguro si la función no está mockeada en tests.
				unset( $e );
			}
		}

		$stagger = (int) apply_filters( 'akb_bis_stagger_seconds', self::STAGGER_SECONDS );
		$stagger = max( 1, $stagger );

		if ( $latest >= $now ) {
			$scheduled = $latest + $stagger;
		} else {
			$scheduled = $now;
		}

		self::$last_scheduled_timestamp = $scheduled;

		return $scheduled;
	}
}
