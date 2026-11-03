<?php
/**
 * Consume akb_bis_notify_product. El handler siempre está registrado y el
 * flag de despacho gobierna el envío. Revalida instock y el WC_Email habilitado
 * antes del lote. Cada suscriptor se reclama atómicamente, se envía y, ante
 * fallo, vuelve a reintento acotado con log.
 * Procesa lotes y reagenda con separación temporal mientras queden pendientes.
 * La pausa también tiene techo; guardar el ajuste de reactivación rearma
 * el trabajo. akb_bis_subscribers_ready expone sólo product_id y count,
 * nunca objetos que contengan direcciones o tokens.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\BackInStock;

use Akibara\Business\Modules\Stock\StockStatus;
use Akibara\Business\Utilities\Log;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.53.0
 */
final class NotificationDispatcher {

	/** Tamaño de lote por corrida (filtrable akb_bis_batch_size). */
	private const DEFAULT_BATCH_SIZE = 20;

	/** Gap entre lotes en segundos (filtrable akb_bis_throttle_gap_seconds). */
	private const DEFAULT_GAP_SECONDS = 60;

	/** Reintento mientras el despacho está pausado (filtrable). */
	private const DEFAULT_PAUSE_RETRY_SECONDS = 900;

	/** Reintento cuando `WC_Email` todavía no es cargable (carrera de arranque). */
	private const MAILER_RETRY_SECONDS = 60;

	/**
	 * Cuántas veces se reintenta una cola pausada antes de soltarla.
	 *
	 * Cuatro por 15 minutos ≈ una hora de gracia: cubre la pausa corta (alguien
	 * apagó el envío para revisar algo y lo vuelve a encender) sin dejar una
	 * acción rebotando indefinidamente. Pasado el techo, la cola queda esperando
	 * el guardado del ajuste o la próxima transición de stock; los suscriptores
	 * NO se pierden — siguen en 'subscribed'.
	 */
	private const MAX_PAUSE_RETRIES = 4;

	/** @var SubscriberRepository */
	private SubscriberRepository $repository;

	/**
	 * Factory del email (inyectable en tests). En runtime resuelve la
	 * instancia registrada en el mailer de WC — instanciarlo fuera del
	 * mailer duplicaría los settings del form.
	 *
	 * @var callable|null
	 */
	private $email_factory;

	/**
	 * Sonda de «¿es resoluble ya un WC_Email?» (inyectable en pruebas, igual
	 * que la factory de arriba). En runtime es
	 * `Services\MailerBoot::ready()`; una prueba no puede DES-cargar
	 * una clase, así que sin esta costura la rama de mailer caído sería
	 * inalcanzable — que es justo la que se perdía avisos en producción.
	 *
	 * @var callable|null
	 */
	private $mailer_ready;

	/**
	 * @param SubscriberRepository $repository    Persistencia.
	 * @param callable|null        $email_factory Factory del InstockEmail (tests).
	 * @param callable|null        $mailer_ready  Sonda de mailer cargado (tests).
	 */
	public function __construct( SubscriberRepository $repository, ?callable $email_factory = null, ?callable $mailer_ready = null ) {
		$this->repository    = $repository;
		$this->email_factory = $email_factory;
		$this->mailer_ready  = $mailer_ready;
	}

	/**
	 * @return bool
	 */
	private function mailer_ready(): bool {
		return null !== $this->mailer_ready
			? (bool) call_user_func( $this->mailer_ready )
			: \Akibara\Business\Services\MailerBoot::ready();
	}

	/**
	 * @return void
	 */
	public function register(): void {
		add_action( self::hook(), array( $this, 'dispatch' ), 10, 2 );

		// Guardar el ajuste de reactivación rearma la cola pausada.
		add_action( 'update_option_' . BackInStock::OPTION_DISPATCH, array( $this, 'on_dispatch_setting_saved' ), 10, 2 );
		add_action( 'update_option_' . BackInStock::OPTION_NOTIFY_BACKORDER, array( $this, 'on_dispatch_setting_saved' ), 10, 2 );
	}

	/**
	 * Retoma las colas en espera cuando el dueño enciende el envío.
	 *
	 * @param mixed $old_value Valor anterior de la option.
	 * @param mixed $value     Valor nuevo.
	 * @return void
	 */
	public function on_dispatch_setting_saved( $old_value, $value ): void {
		unset( $old_value );

		if ( 'yes' !== $value || ! BackInStock::dispatch_enabled() ) {
			return;
		}
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}

		// products_with_waiting() y NO demand_summary(): esta última lleva
		// LIMIT 50 por defecto (existe para pintar la tabla del panel), así que
		// reencolaba sólo los 50 productos de más demanda y abandonaba en
		// silencio al resto. Aquí se reencola a todos los que tienen a alguien
		// esperando, que es justo lo que promete el ajuste al reactivarse.
		foreach ( $this->repository->products_with_waiting() as $product_id ) {
			as_enqueue_async_action(
				self::hook(),
				array( 'product_id' => $product_id ),
				StockWatcher::AS_GROUP
			);
		}
	}

	/**
	 * @return string
	 */
	public static function hook(): string {
		return StockWatcher::ASYNC_HOOK;
	}

	/**
	 * Corre un lote de notificación para el producto.
	 *
	 * @param int|array<string, mixed> $product_id ID del producto. AS entrega
	 *                                             args posicionales (int); el
	 *                                             unwrap de array es guardia
	 *                                             defensiva para invocaciones
	 *                                             manuales/do_action directas.
	 * @param int|mixed                $pause_try  Reintentos de pausa ya gastados
	 *                                             por ESTA cadena (viaja en los
	 *                                             args de Action Scheduler, así
	 *                                             que no necesita persistencia
	 *                                             propia y muere con la cadena).
	 * @return void
	 */
	public function dispatch( $product_id, $pause_try = 0 ): void {
		if ( is_array( $product_id ) ) {
			$pause_try  = $product_id['pause_try'] ?? $pause_try;
			$product_id = $product_id['product_id'] ?? 0;
		}
		$product_id = (int) $product_id;
		$pause_try  = max( 0, (int) $pause_try );
		if ( $product_id <= 0 ) {
			return;
		}

		$product = wc_get_product( $product_id );
		if ( ! $product || ! BackInStock::is_notifiable_status( StockStatus::of( $product ) ) ) {
			return; // El stock volvió a caer entre el enqueue y el worker.
		}

		$waiting = $this->repository->count_waiting( $product_id );
		if ( $waiting < 1 ) {
			return;
		}

		/**
		 * Observabilidad: hay suscriptores listos para notificar. Payload
		 * mínimo a propósito (sin emails/tokens).
		 *
		 * @since 1.53.0
		 *
		 * @param int $product_id Producto que volvió a stock.
		 * @param int $waiting    Suscriptores en espera.
		 */
		do_action( 'akb_bis_subscribers_ready', $product_id, $waiting );

		if ( ! BackInStock::dispatch_enabled() ) {
			// Corte seguro: no marcar ni enviar, pero conservar un job futuro.
			// Sin esto, volver a activar el ajuste no retomaba las filas hasta
			// que ocurriera otra transición de stock.
			$this->reschedule_paused( $product_id, $pause_try );
			return;
		}

		// El mailer ANTES de resolver el correo. Sin esto, 1 de cada 4 corridas
		// de WP-Cron moría con `Class "WC_Email" not found` (el autoloader de
		// WooCommerce no sabe resolver esa clase; ver
		// `Services\MailerBoot::ready()`), y Action Scheduler NO
		// reintenta un fatal: el aviso se perdía. Ahora se RE-ENCOLA con el
		// mismo contador acotado que la pausa, así que tampoco puede rebotar
		// para siempre.
		if ( ! $this->mailer_ready() ) {
			if ( 0 === $pause_try ) {
				$this->log( sprintf( 'Envío BIS diferido para producto %d: WC_Email todavía no cargable.', $product_id ) );
			}
			$this->reschedule_unready_mailer( $product_id, $pause_try );
			return;
		}

		$email = $this->email();
		if ( ! $email || ! $email->is_enabled() ) {
			// Comprueba WC_Email antes de reclamar suscriptores: apagarlo no marca
			// como notified un lote sin enviar. Registra un aviso por cadena pausada,
			// sin repetir el mismo warning en cada reintento del estado sin cambios.
			if ( 0 === $pause_try ) {
				$this->log( sprintf( 'Envío BIS omitido para producto %d: email deshabilitado.', $product_id ) );
			}
			$this->reschedule_paused( $product_id, $pause_try );
			return;
		}

		/**
		 * Tamaño del lote por corrida.
		 *
		 * @since 1.53.0
		 *
		 * @param int $size Default 20.
		 */
		$batch_size = max( 1, (int) apply_filters( 'akb_bis_batch_size', self::DEFAULT_BATCH_SIZE ) );

		foreach ( $this->repository->for_product( $product_id, $batch_size ) as $subscriber ) {
			// CLAIM atómico: si otro worker ya tomó esta fila, saltear.
			if ( ! $this->repository->mark_notified( $subscriber->id ) ) {
				continue;
			}

			$sent = false;
			try {
				$sent = $email->trigger( $subscriber, $product );
			} catch ( \Throwable $e ) {
				$this->log( sprintf( 'Excepción enviando BIS #%d (producto %d): %s', $subscriber->id, $product_id, $e->getMessage() ) );
			}

			if ( ! $sent ) {
				$status = $this->repository->record_failed_attempt( $subscriber->id );
				$this->log(
					sprintf(
						'Fallo de envío BIS #%d (producto %d) → estado %s.',
						$subscriber->id,
						$product_id,
						$status
					)
				);
			}
		}

		$this->maybe_reschedule( $product_id );
	}

	/**
	 * Si quedan suscriptores, agenda el próximo lote a +gap segundos.
	 *
	 * @param int $product_id Producto en curso.
	 * @return void
	 */
	private function maybe_reschedule( int $product_id ): void {
		/**
		 * Segundos entre lotes de envío (throttle para no reventar el límite
		 * del proveedor transaccional).
		 *
		 * @since 1.53.0
		 *
		 * @param int $gap Default 60.
		 */
		$gap = max( 1, (int) apply_filters( 'akb_bis_throttle_gap_seconds', self::DEFAULT_GAP_SECONDS ) );

		$this->schedule_next( $product_id, $gap, 0 );
	}

	/**
	 * Reintento acotado cuando el mailer todavía no es resoluble.
	 *
	 * A +60s y no a los 15 minutos de la pausa: esto no es «el dueño apagó el
	 * envío» sino una carrera de arranque que la siguiente petición ya suele
	 * tener resuelta. Comparte el contador `pause_try` —que viaja en los args
	 * de Action Scheduler— para no inventar una segunda persistencia y para que
	 * el techo siga existiendo.
	 *
	 * @param int $product_id Producto en curso.
	 * @param int $pause_try  Reintentos ya gastados por esta cadena.
	 * @return void
	 */
	private function reschedule_unready_mailer( int $product_id, int $pause_try ): void {
		if ( $pause_try >= self::MAX_PAUSE_RETRIES ) {
			return; // Techo: la cola espera el próximo restock o el guardado del ajuste.
		}

		$this->schedule_next( $product_id, self::MAILER_RETRY_SECONDS, $pause_try + 1 );
	}

	/**
	 * Reintento acotado mientras el despacho está pausado.
	 *
	 * @param int $product_id Producto en curso.
	 * @param int $pause_try  Reintentos ya gastados por esta cadena.
	 * @return void
	 */
	private function reschedule_paused( int $product_id, int $pause_try ): void {
		if ( $pause_try >= self::MAX_PAUSE_RETRIES ) {
			return; // Techo: la cola espera el guardado del ajuste o el próximo restock.
		}

		/**
		 * Segundos entre reintentos mientras el despacho está pausado.
		 *
		 * @since 1.53.0
		 *
		 * @param int $gap Default 900.
		 */
		$gap = max( 60, (int) apply_filters( 'akb_bis_pause_retry_seconds', self::DEFAULT_PAUSE_RETRY_SECONDS ) );

		$this->schedule_next( $product_id, $gap, $pause_try + 1 );
	}

	/**
	 * @param int $product_id Producto en curso.
	 * @param int $gap        Segundos hasta el próximo intento.
	 * @param int $pause_try  Contador de pausa que viaja al próximo job.
	 * @return void
	 */
	private function schedule_next( int $product_id, int $gap, int $pause_try ): void {
		if ( $this->repository->count_waiting( $product_id ) < 1 ) {
			return;
		}

		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return; // Sin AS no hay throttle posible; el próximo restock retoma.
		}

		as_schedule_single_action(
			time() + $gap,
			self::hook(),
			array(
				'product_id' => $product_id,
				'pause_try'  => $pause_try,
			),
			StockWatcher::AS_GROUP
		);
	}

	/**
	 * Resuelve la instancia del email desde el mailer de WC (fuerza la carga
	 * de las clases WC_Email en contexto Action Scheduler/CLI).
	 *
	 * @return InstockEmail|null
	 */
	private function email(): ?InstockEmail {
		$email = \Akibara\Business\Services\MailerBoot::from_mailer( InstockEmail::class, $this->email_factory );
		return $email instanceof InstockEmail ? $email : null;
	}

	/**
	 * @param string $message Mensaje.
	 * @return void
	 */
	private function log( string $message ): void {
		Log::warn( 'bis', $message );
	}
}
