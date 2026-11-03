<?php
/**
 * Accrual — acreditación y reversas por pedido.
 *
 * Reglas (spec 1-5, config real de prod):
 *   - Acredita al pasar al estado configurado en el panel («Procesando»,
 *     o sea con el pago confirmado) o a cualquier estado pagado posterior.
 *   - Solo pedidos creados a partir de la fecha de inicio de vigencia del programa: sin esta
 *     compuerta, mover un pedido viejo regalaría 2% + bono retroactivo.
 *   - Idempotencia en TRES capas: meta de pedido re-leída FRESCA bajo candado
 *     de pedido (`read_meta_data(true)` — la caché de pedidos es persistente
 *     en prod y wc_get_order puede servir una foto vieja), conciliación
 *     contra el LEDGER (si hay una acreditación viva sin sellar, se sella en
 *     vez de re-acreditar — ventana de caída), y `event_key` UNIQUE por
 *     ocurrencia en la tabla.
 *   - Cancelación/reembolso total/fallo deduce TODO lo acreditado (bono
 *     incluido — viaja dentro del earned a propósito). Un pedido REVIVIDO
 *     (cancelado → de nuevo listo) vuelve a acreditar: las claves de evento
 *     van por ocurrencia (award_100_2), no por pedido.
 *   - Reembolso parcial recalcula sobre lo conservado y retira la diferencia,
 *     preservando el bono. La clasificación parcial-vs-total mira la PLATA
 *     conservada (total − reembolsado): ni la base puntuable (el canje la
 *     tumba a 0 con un reembolso chico) ni los ítems conservados (un
 *     reembolso íntegro por importe con canje nunca los deja en 0) sirven.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Points;

use Akibara\Rewards\Support\Log;
use Akibara\Rewards\Support\Options;
use Akibara\Rewards\Support\OrderStates;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.1.0
 */
final class Accrual {

	/** El programa rige hoy: se acredita y se puede prometer. */
	public const PROGRAMA_VIGENTE = 'vigente';

	/** La fecha de inicio es legible y está en el futuro: todavía no acredita nadie. */
	public const PROGRAMA_AGENDADO = 'agendado';

	/** La fecha de inicio guardada no se puede leer: no acredita nadie hasta corregirla. */
	public const PROGRAMA_ILEGIBLE = 'ilegible';

	public const META_EARNED            = '_akb_rewards_points_earned';
	public const META_WELCOME_BONUS     = '_akb_rewards_welcome_bonus';
	public const META_PRE_PROGRAM_NOTED = '_akb_rewards_pre_program_noted';
	public const META_RATE              = '_akb_rewards_rate';

	private const LOCK_TIMEOUT = 3;

	/**
	 * Cuelga los hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_status_changed' ), 20, 4 );
		add_action( 'woocommerce_order_refunded', array( $this, 'on_order_refunded' ), 20, 2 );
		add_action( 'woocommerce_refund_deleted', array( $this, 'on_refund_deleted' ), 20, 2 );

		// El barrido recupera pedidos elegibles pendientes de acreditación.
		add_action( self::HOOK_SWEEP, array( $this, 'sweep_unawarded' ) );
		add_action( 'init', array( $this, 'ensure_sweep_scheduled' ), 20 );
	}

	/** Acción diaria del barrido. */
	public const HOOK_SWEEP = 'akb_rewards_accrual_sweep_daily';

	/**
	 * Sello del barrido sobre un pedido que NO acredita y no volverá a entrar:
	 * su valor es el motivo (`invitado`, `vinculado_despues`, `sin_acreditar`).
	 * Evita que pedidos excluidos de forma permanente consuman capacidad de
	 * cada lote y retrasen la acreditación de pedidos nuevos.
	 */
	public const META_SWEEP_SKIPPED = '_akb_rewards_sweep_skipped';

	/** Pedidos por barrido; el resto espera al día siguiente. */
	private const SWEEP_BATCH = 100;

	/**
	 * Misma puerta de backend que `Expiry`, `Birthday` y `TokenCleanup`:
	 * `as_next_scheduled_action()` son dos consultas y el front no las paga.
	 *
	 * @return void
	 */
	public function ensure_sweep_scheduled(): void {
		if ( ! is_admin() && ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		if ( ! function_exists( 'as_next_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}
		if ( false === as_next_scheduled_action( self::HOOK_SWEEP ) ) {
			as_schedule_recurring_action( time() + 3 * HOUR_IN_SECONDS, DAY_IN_SECONDS, self::HOOK_SWEEP );
		}
	}

	/**
	 * Acredita los pedidos pagados que se quedaron sin puntos.
	 *
	 * La acreditación se ejecuta en `woocommerce_order_status_changed`. Para garantizar
	 * consistencia ante fallos transitorios o transiciones manuales de pedidos, este proceso
	 * concilia pedidos pagados elegibles que no tengan `META_EARNED` ni marca previa.
	 * Los pedidos de invitado o cuentas creadas con posterioridad al pedido no acumulan puntos.
	 *
	 * @return void
	 */
	public function sweep_unawarded(): void {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return;
		}

		$ids = wc_get_orders(
			array(
				'status'       => OrderStates::PAID,
				'date_created' => '>=' . substr( Options::program_start(), 0, 10 ),
				'limit'        => self::SWEEP_BATCH,
				'orderby'      => 'ID',
				'order'        => 'ASC',
				'return'       => 'ids',
				'meta_query'   => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- barrido diario acotado a 100 pedidos, fuera de cualquier petición del cliente.
					'relation' => 'AND',
					array(
						'key'     => self::META_EARNED,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => self::META_PRE_PROGRAM_NOTED,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => self::META_SWEEP_SKIPPED,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		foreach ( (array) $ids as $order_id ) {
			$order = wc_get_order( (int) $order_id );
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}
			if ( (int) $order->get_customer_id() <= 0 ) {
				$this->seal_sweep( $order, 'invitado' );
				continue;
			}
			if ( ! OrderStates::is_at_or_after( (string) $order->get_status(), Options::award_status() ) ) {
				continue; // Todavía no acredita: mañana se vuelve a mirar.
			}
			if ( $this->account_created_after( $order ) ) {
				$this->seal_sweep( $order, 'vinculado_despues' );
				continue;
			}

			$this->maybe_award( (int) $order_id );

			$order = $this->fresh_order( (int) $order_id );
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}
			if ( '' !== (string) $order->get_meta( self::META_EARNED ) ) {
				$order->add_order_note( 'Puntos Akibara: acreditación recuperada por el barrido diario (el pedido estaba en un estado que acredita y no tenía puntos).' );
				continue;
			}
			// `maybe_award()` decidió no acreditar (sin líneas, o el ledger no
			// respondió): se sella para no entrar a diario, y se dice. El
			// siguiente cambio de estado del pedido vuelve a intentarlo por la
			// vía normal, que no mira este sello.
			$this->seal_sweep( $order, 'sin_acreditar' );
			$order->add_order_note( 'Puntos Akibara: el barrido diario no pudo acreditar este pedido; lo reintentará el próximo cambio de estado.' );
		}
	}

	/**
	 * @param \WC_Order $order  Pedido.
	 * @param string    $motivo Por qué no acredita.
	 * @return void
	 */
	private function seal_sweep( \WC_Order $order, string $motivo ): void {
		$order->update_meta_data( self::META_SWEEP_SKIPPED, $motivo );
		$order->save_meta_data();
	}

	/**
	 * ¿La cuenta del pedido se creó DESPUÉS del pedido? Es la huella de un
	 * pedido de invitado vinculado a posteriori (la cuenta creada en la caja
	 * nace en el mismo segundo que el pedido; se dejan 60 s de margen).
	 *
	 * @param \WC_Order $order Pedido con cuenta.
	 * @return bool
	 */
	private function account_created_after( \WC_Order $order ): bool {
		if ( ! function_exists( 'get_userdata' ) ) {
			return false;
		}
		$user    = get_userdata( (int) $order->get_customer_id() );
		$creado  = $order->get_date_created();
		$alta    = is_object( $user ) && isset( $user->user_registered ) ? strtotime( (string) $user->user_registered . ' UTC' ) : false;
		$pedido  = $creado instanceof \DateTimeInterface ? $creado->getTimestamp() : false;

		return false !== $alta && false !== $pedido && $alta > $pedido + MINUTE_IN_SECONDS;
	}

	/**
	 * Transición de estado: acreditar o revertir según el destino.
	 *
	 * @param int       $order_id ID del pedido.
	 * @param string    $from     Estado anterior (sin wc-).
	 * @param string    $to       Estado nuevo (sin wc-).
	 * @param \WC_Order $order    Pedido.
	 * @return void
	 */
	public function on_status_changed( $order_id, $from, $to, $order ): void {
		unset( $from );

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		if ( OrderStates::is_at_or_after( (string) $to, Options::award_status() ) ) {
			$this->maybe_award( (int) $order_id );
			return;
		}

		// Estado terminal: la venta no existe y la acreditación se revierte entera.
		if ( OrderStates::is_down( (string) $to ) ) {
			$this->deduct_all( (int) $order_id );
		}
	}

	/**
	 * Acredita el pedido si corresponde. Idempotente bajo candado de pedido.
	 *
	 * @param int $order_id ID del pedido.
	 * @return void
	 */
	public function maybe_award( int $order_id ): void {
		$this->with_order_lock(
			$order_id,
			function () use ( $order_id ): void {
				$order = $this->fresh_order( $order_id );
				if ( ! $order instanceof \WC_Order ) {
					return;
				}

				$user_id = (int) $order->get_customer_id();
				if ( $user_id <= 0 ) {
					return; // Invitado: los puntos requieren cuenta.
				}

				// Solo el sello POSITIVO corta: tras una reversa la meta queda
				// en 0 y un pedido REVIVIDO debe volver a acreditar (la
				// conciliación con el ledger de abajo decide si hay algo
				// pendiente de verdad).
				// Evalúa la compra descontando el bono de bienvenida para permitir
				// acumulación posterior si se habilita la asignación por compra.
				$bonus_sellado = max( 0, (int) $order->get_meta( self::META_WELCOME_BONUS ) );
				if ( (int) $order->get_meta( self::META_EARNED ) - $bonus_sellado > 0 ) {
					// Ya acreditado. Si el correo quedó pendiente, reintentar
					// el aviso es legítimo (el pedido es «reintentable»).
					do_action( 'akb_rewards_award_already_done', $order );
					return;
				}

				// Conciliación contra el ledger por suma de deltas para determinar
				// el saldo neto pendiente del pedido y sellar el valor real.
				$outstanding = Ledger::sum_movements(
					'order',
					$order_id,
					array( 'order', 'welcome_bonus', 'order_reversal', 'order_refund' )
				);
				// Igual que arriba: un pendiente que es sólo el bono no es una
				// acreditación de compra caída a medio sellar.
				if ( $outstanding - $bonus_sellado > 0 ) {
					$order->update_meta_data( self::META_EARNED, $outstanding );
					$order->save_meta_data();
					$order->add_order_note(
						sprintf( 'Puntos Akibara: acreditación reconciliada desde el ledger (%d puntos pendientes ya movidos).', $outstanding )
					);
					do_action( 'akb_rewards_award_already_done', $order );
					return;
				}

				if ( ! OrderStates::is_at_or_after( (string) $order->get_status(), Options::award_status() ) ) {
					return;
				}

				if ( ! $this->program_allows( $order ) ) {
					return;
				}

				$base   = Calculator::award_base_for_order( $order );
				$points = Calculator::points_for( $base );

				if ( $points <= 0 ) {
					// Pedidos sin ítems de línea se preservan sin sellar para
					// permitir re-evaluación cuando se incorporen productos.
					if ( array() === $order->get_items( 'line_item' ) ) {
						return;
					}
					// Sellar el cero UNA vez (meta aún virgen): transiciones
					// posteriores no re-escriben nada.
					if ( '' === (string) $order->get_meta( self::META_EARNED ) ) {
						$order->update_meta_data( self::META_EARNED, 0 );
					}
					// La tasa se sella también en cero: si el bono de bienvenida
					// entra a este pedido, un reembolso parcial posterior tiene
					// que recalcular con la tasa de ENTONCES (0), no con la
					// vigente, o el ajuste sale mudo y el earned inflado.
					if ( '' === (string) $order->get_meta( self::META_RATE ) ) {
						$order->update_meta_data( self::META_RATE, sprintf( '%d/%d', Options::earn_points(), Options::earn_amount() ) );
					}
					$order->save_meta_data();

					/**
					 * Notificación de evento para flujos posteriores (bonos y correos).
					 *
					 * @param \WC_Order $order   Pedido.
					 * @param int       $user_id Cliente.
					 * @param int       $points  Siempre 0 en esta rama.
					 */
					do_action( 'akb_rewards_after_order_award', $order, $user_id, 0 );
					return;
				}

				$awards = Ledger::count_movements( 'order', $order_id, 'order' );

				$result = Ledger::credit(
					$user_id,
					$points,
					'order',
					'order',
					$order_id,
					sprintf( 'Compra #%d', $order_id ),
					true,
					sprintf( 'award_%d_%d', $order_id, $awards + 1 )
				);

				if ( ! is_int( $result ) ) {
					return; // Infraestructura: la próxima transición reintenta.
				}

				// Se SUMA a lo que ya hubiera: si este pedido cobró antes sólo el
				// bono (compra en cero), el earned lo lleva dentro y pisarlo lo
				// borraría de la cuenta de la reversa.
				$order->update_meta_data( self::META_EARNED, $points + max( 0, (int) $order->get_meta( self::META_EARNED ) ) );
				// La tasa aplicada viaja sellada en el pedido para permitir que
				// ajustes posteriores de reembolso utilicen la tasa histórica exacta.
				$order->update_meta_data( self::META_RATE, sprintf( '%d/%d', Options::earn_points(), Options::earn_amount() ) );
				$order->delete_meta_data( '_akb_rewards_pending_escrow_points' );
				$order->delete_meta_data( '_akb_rewards_escrow_noted' );
				$order->save_meta_data();
				$order->add_order_note(
					sprintf( 'Puntos Akibara: %d puntos acreditados por esta compra.', $points )
				);

				/**
				 * Acreditación de compra completada. El bono de bienvenida
				 * cuelga a prioridad 10 y el correo consolidado a 100.
				 *
				 * @param \WC_Order $order   Pedido acreditado.
				 * @param int       $user_id Cliente.
				 * @param int       $points  Puntos de la compra (sin bono).
				 */
				do_action( 'akb_rewards_after_order_award', $order, $user_id, $points );
			}
		);
	}

	/**
	 * Deduce TODO lo acreditado al pedido (anulación). Idempotente: la meta
	 * queda en 0 tras deducir.
	 *
	 * @param int $order_id ID del pedido.
	 * @return void
	 */
	public function deduct_all( int $order_id ): void {
		$this->with_order_lock(
			$order_id,
			function () use ( $order_id ): void {
				$order = $this->fresh_order( $order_id );
				if ( $order instanceof \WC_Order ) {
					$this->deduct_all_locked( $order );
				}
			}
		);
	}

	/**
	 * Cuerpo de la reversa completa. Requiere el candado de pedido tomado y
	 * el pedido re-leído fresco.
	 *
	 * @param \WC_Order $order Pedido fresco bajo candado.
	 * @return void
	 */
	private function deduct_all_locked( \WC_Order $order ): void {
		$order_id = (int) $order->get_id();
		// Reconcilia el monto a descontar utilizando el mayor entre la meta del pedido
		// y los movimientos registrados en el ledger.
		$earned = max(
			(int) $order->get_meta( self::META_EARNED ),
			Ledger::sum_movements( 'order', $order_id, array( 'order', 'welcome_bonus', 'order_reversal', 'order_refund' ) )
		);
		if ( $earned <= 0 ) {
			return;
		}

		$user_id = (int) $order->get_customer_id();
		if ( $user_id <= 0 ) {
			return;
		}

		$reversals = Ledger::count_movements( 'order', $order_id, 'order_reversal' );

		$deducted = Ledger::debit(
			$user_id,
			$earned,
			'order_reversal',
			'order',
			$order_id,
			sprintf( 'Anulación del pedido #%d', $order_id ),
			false,
			sprintf( 'reversal_%d_%d', $order_id, $reversals + 1 )
		);

		if ( ! is_int( $deducted ) ) {
			// Sin candado de usuario no hay reversa — y una reversa no tiene
			// «próxima transición»: dejar huella y alarma, no silencio.
			$order->add_order_note(
				'Puntos Akibara: la reversa de puntos NO pudo ejecutarse (candado ocupado). Reintentar guardando el pedido de nuevo.'
			);
			do_action( 'akb_rewards_reversal_failed', $order, $earned );
			return;
		}

		$order->update_meta_data( self::META_EARNED, 0 );
		$order->save_meta_data();
		$order->add_order_note(
			sprintf( 'Puntos Akibara: %d puntos retirados por la anulación del pedido.', $deducted )
		);

		/**
		 * Reversa completa aplicada. El bono de bienvenida escucha acá para
		 * decidir si devuelve la elegibilidad (solo si recuperó el bono).
		 *
		 * @param \WC_Order $order    Pedido revertido.
		 * @param int       $user_id  Cliente.
		 * @param int       $deducted Puntos efectivamente retirados (0 posible).
		 */
		do_action( 'akb_rewards_after_order_deduct', $order, $user_id, $deducted );
	}

	/**
	 * Reembolso (parcial o total encubierto): recalcular sobre lo conservado
	 * y retirar la diferencia, preservando el bono de bienvenida.
	 *
	 * @param int $order_id  ID del pedido.
	 * @param int $refund_id ID del reembolso (clave de idempotencia del ajuste).
	 * @return void
	 */
	public function on_order_refunded( $order_id, $refund_id ): void {
		$this->with_order_lock(
			(int) $order_id,
			function () use ( $order_id, $refund_id ): void {
				$order = $this->fresh_order( (int) $order_id );
				if ( ! $order instanceof \WC_Order ) {
					return;
				}

				// Obtiene el saldo acreditado neto para determinar el margen de ajuste.
				$earned = max(
					(int) $order->get_meta( self::META_EARNED ),
					Ledger::sum_movements( 'order', (int) $order_id, array( 'order', 'welcome_bonus', 'order_reversal', 'order_refund' ) )
				);
				if ( $earned <= 0 ) {
					return; // Nada acreditado (o ya revertido): nada que ajustar.
				}

				$user_id = (int) $order->get_customer_id();
				if ( $user_id <= 0 ) {
					return;
				}

				// Identifica si el reembolso es total considerando tanto el importe monetario
				// remanente como el valor neto de puntos canjeados.
				$money_left = max( 0.0, (float) $order->get_total() - (float) $order->get_total_refunded() );
				if ( $money_left <= 0.01 && Calculator::net_redeemed_value( $order ) <= 0.01 ) {
					$this->deduct_all_locked( $order );
					return;
				}

				$bonus  = max( 0, (int) $order->get_meta( self::META_WELCOME_BONUS ) );
				$target = $this->points_at_sealed_rate( $order, Calculator::award_base_for_order( $order ) ) + $bonus;

				// Compara contra la base íntegra redondeada para retirar únicamente la diferencia de líneas devueltas.
				$target_full = $this->points_at_sealed_rate(
					$order,
					max( 0.0, Calculator::items_base( $order ) - Calculator::net_redeemed_value( $order ) )
				) + $bonus;
				$diff        = min( $earned, $target_full ) - $target;

				if ( $diff <= 0 ) {
					return; // Nunca re-acreditar por un ajuste de reembolso.
				}

				$deducted = Ledger::debit(
					$user_id,
					$diff,
					'order_refund',
					'order',
					(int) $order_id,
					sprintf( 'Reembolso parcial del pedido #%d', (int) $order_id ),
					false,
					sprintf( 'refund_%d_%d', (int) $order_id, (int) $refund_id )
				);

				// Idempotencia: si este reembolso ya se aplicó, el event_key previene
				// movimientos duplicados y el sellado actualiza el estado meta.
				if ( ! is_int( $deducted ) ) {
					$order->add_order_note(
						'Puntos Akibara: el ajuste por reembolso NO pudo ejecutarse (candado ocupado). Reintentar guardando el pedido.'
					);
					do_action( 'akb_rewards_reversal_failed', $order, $diff );
					return;
				}

				$order->update_meta_data( self::META_EARNED, $target );
				$order->save_meta_data();
				$order->add_order_note(
					sprintf( 'Puntos Akibara: %d puntos retirados por reembolso parcial (quedan %d).', $deducted, $target )
				);
			}
		);
	}

	/**
	 * Puntos para una base con la tasa SELLADA en el pedido; si el pedido es
	 * anterior al sellado de tasa, cae a la configuración vigente (lo que
	 * hacía siempre).
	 *
	 * @param \WC_Order $order Pedido.
	 * @param float     $base  Base puntuable en pesos.
	 * @return int
	 */
	private function points_at_sealed_rate( \WC_Order $order, float $base ): int {
		$sealed = (string) $order->get_meta( self::META_RATE );

		if ( 1 === preg_match( '#^(\d+)/(\d+)$#', $sealed, $m ) ) {
			return Calculator::points_for_base( $base, (int) $m[1], (int) $m[2] );
		}

		return Calculator::points_for( $base );
	}

	/**
	 * Registra nota administrativa si se elimina un reembolso previo sin revertir
	 * débitos automáticamente, preservando la trazabilidad del ledger.
	 *
	 * @param int $refund_id Reembolso borrado.
	 * @param int $order_id  Pedido.
	 * @return void
	 */
	public function on_refund_deleted( $refund_id, $order_id ): void {
		$order = $this->fresh_order( (int) $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		if ( 0 === Ledger::count_movements( 'order', (int) $order_id, 'order_refund' ) ) {
			return; // Ningún reembolso descontó puntos: nada que revisar.
		}

		$order->add_order_note(
			sprintf(
				'Puntos Akibara: se borró el reembolso #%d. Los puntos descontados por reembolsos NO se devuelven solos: revisar el historial del cliente y corregir desde WooCommerce → Puntos y Reseñas → «Otorgar o quitar puntos a mano».',
				(int) $refund_id
			)
		);

		/**
		 * Un reembolso borrado dejó los puntos del pedido necesitando revisión
		 * humana.
		 *
		 * @param \WC_Order $order     Pedido afectado.
		 * @param int       $refund_id Reembolso borrado.
		 */
		do_action( 'akb_rewards_manual_review_needed', $order, (int) $refund_id );
	}

	/**
	 * Re-lee el pedido FRESCO: wc_get_order puede servir la caché persistente
	 * de pedidos, y la idempotencia entera cuelga de estas metas.
	 * `read_meta_data(true)` fuerza la vuelta a la base (mismo mecanismo que
	 * usa SPAR bajo su candado de canje).
	 *
	 * @param int $order_id ID del pedido.
	 * @return \WC_Order|false
	 */
	private function fresh_order( int $order_id ) {
		$order = wc_get_order( $order_id );
		if ( $order instanceof \WC_Order ) {
			$order->read_meta_data( true );
		}

		return $order instanceof \WC_Order ? $order : false;
	}

	/**
	 * Compuerta de vigencia del programa. Falla CERRADA: si la fecha de
	 * inicio configurada no parsea, NO se acredita (y se grita) — fallar
	 * abierta acreditaría justo los pedidos retroactivos que la compuerta
	 * existe para bloquear.
	 *
	 * @param \WC_Order $order Pedido.
	 * @return bool
	 */
	private function program_allows( \WC_Order $order ): bool {
		$allowed = self::in_program( $order );

		if ( ! $allowed && '' === (string) $order->get_meta( self::META_PRE_PROGRAM_NOTED ) ) {
			// Nota ÚNICA: sin el sello, cada transición repetiría el aviso.
			$order->update_meta_data( self::META_PRE_PROGRAM_NOTED, 1 );
			$order->save_meta_data();
			$order->add_order_note(
				sprintf(
					'Puntos Akibara: pedido anterior al inicio del programa (%s); no acredita puntos.',
					substr( Options::program_start(), 0, 10 )
				)
			);
		}

		return $allowed;
	}

	/**
	 * ¿El pedido es del programa? La misma vara para acreditar la compra y para
	 * pagar al padrino, que cuelga de su propio estado de pedido: sin ella, un
	 * pedido anterior al programa que se completara hoy pagaría el premio.
	 *
	 * Sin efectos: la nota de «pedido anterior al programa» la deja la
	 * acreditación, que es la que el dueño ve en el pedido.
	 *
	 * @param \WC_Order $order Pedido.
	 * @return bool
	 */
	public static function in_program( \WC_Order $order ): bool {
		$created = $order->get_date_created();
		$start   = self::parse_program_start( Options::program_start() );

		if ( ! $start instanceof \DateTimeImmutable ) {
			Log::error( 'akb_rewards_program_start malformada; acreditación BLOQUEADA hasta corregirla.' );
			$allowed = false;
		} elseif ( $created instanceof \WC_DateTime ) {
			$allowed = $created->getTimestamp() >= $start->getTimestamp();
		} else {
			$allowed = true;
		}

		/**
		 * Permite forzar una acreditación manual de un pedido pre-programa.
		 *
		 * OJO: desde la 0.4.0 este filtro gobierna DOS pagos, no uno. El premio
		 * a quien invitó consulta la misma compuerta, así que forzar un pedido
		 * viejo también paga ese premio.
		 *
		 * @param bool      $allowed Si el programa permite acreditar.
		 * @param \WC_Order $order   Pedido evaluado.
		 */
		return (bool) apply_filters( 'akb_rewards_award_allowed', $allowed, $order );
	}

	/**
	 * Valida la vigencia activa del programa para cotización y presentación en carrito.
	 *
	 * @return bool
	 */
	public static function program_open_now(): bool {
		return self::PROGRAMA_VIGENTE === self::program_state();
	}

	/**
	 * Clasifica el estado del programa según la fecha de inicio configurada.
	 *
	 * @return string PROGRAMA_VIGENTE, PROGRAMA_AGENDADO o PROGRAMA_ILEGIBLE.
	 */
	public static function program_state(): string {
		$start = self::parse_program_start( Options::program_start() );

		if ( ! $start instanceof \DateTimeImmutable ) {
			return self::PROGRAMA_ILEGIBLE;
		}

		return time() >= $start->getTimestamp() ? self::PROGRAMA_VIGENTE : self::PROGRAMA_AGENDADO;
	}

	/**
	 * Validación canónica de la fecha de inicio de vigencia del programa.
	 *
	 * Exige formato estricto `Y-m-d H:i:s` en la zona horaria del sitio para prevenir
	 * desbordamientos de fecha en valores malformados.
	 *
	 * @param string $valor Texto guardado o tecleado, «AAAA-MM-DD HH:MM:SS».
	 * @return \DateTimeImmutable|null Null si no es una fecha que el motor entienda.
	 */
	public static function parse_program_start( string $valor ): ?\DateTimeImmutable {
		$fecha = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $valor, wp_timezone() );

		return $fecha instanceof \DateTimeImmutable && $fecha->format( 'Y-m-d H:i:s' ) === $valor ? $fecha : null;
	}

	/**
	 * Ejecuta $fn bajo el candado del pedido. El candado de pedido protege la
	 * idempotencia de la acreditación; el de usuario (dentro del Ledger, y
	 * reentrante) protege el saldo. Orden fijo pedido→usuario: sin abrazos
	 * mortales.
	 *
	 * Público a propósito: la captura de canje (Redemption) mueve dinero por
	 * pedido y usa EXACTAMENTE este candado.
	 *
	 * @param int      $order_id ID del pedido.
	 * @param callable $fn       Cuerpo a ejecutar.
	 * @return void
	 */
	public function with_order_lock( int $order_id, callable $fn ): void {
		global $wpdb;

		$lock = 'akb_rewards_order_' . $order_id;

		$acquired = (string) $wpdb->get_var(
			$wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, self::LOCK_TIMEOUT )
		);

		if ( '1' !== $acquired ) {
			Log::error( sprintf( 'Candado %s no disponible; operación de pedido pospuesta.', $lock ) );
			return;
		}

		try {
			$fn();
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}
}
