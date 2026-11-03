<?php
/**
 * WelcomeBonus — bono de bienvenida pagado sobre la PRIMERA compra acreditada.
 *
 * Diseño heredado de akibara-points-welcome.php (probado en prod):
 *   - NUNCA por registro: el signup bonus gratuito lo reclamaban
 *     retroactivamente las 58 cuentas existentes ($29.000 sin venta).
 *   - Pagado al acreditar la primera compra: el costo aparece tras venta real
 *     y el saldo empuja la SEGUNDA compra.
 *   - Idempotencia: usermeta con el pedido pagador, RE-LEÍDA bajo el candado
 *     de usuario (dos pedidos concurrentes del mismo cliente) + event_key
 *     único por usuario-pedido en el ledger.
 *   - El bono SE SUMA a la meta earned del pedido a propósito: así las
 *     anulaciones lo arrastran sin lógica extra.
 *   - Si el pedido pagador se anula, el cliente recupera la elegibilidad
 *     únicamente si la reversa recuperó efectivamente el monto del bono acreditado,
 *     previniendo doble beneficio si el saldo ya había sido consumido previamente.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Points;

use Akibara\Rewards\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.1.0
 */
final class WelcomeBonus {

	public const META_WELCOME_PAID = '_akb_rewards_welcome_paid';

	/** Sello de «ya se dejó dicho en el pedido por qué no hubo bono». */
	public const META_SKIP_NOTED = '_akb_rewards_welcome_skip_noted';

	/**
	 * Cuelga los hooks alrededor de la acreditación.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'akb_rewards_after_order_award', array( $this, 'maybe_pay' ), 10, 3 );
		add_action( 'akb_rewards_after_order_deduct', array( $this, 'maybe_restore_eligibility' ), 10, 3 );
		// La ventana de caída del bono (crédito insertado, marca sin escribir)
		// solo puede sanarse si la conciliación VUELVE a correr — y
		// after_order_award no se re-dispara en pedidos ya acreditados. El
		// camino already_done sí (cada re-transición al estado de
		// acreditación). Prio 5: antes del reenvío de correo (10), que lee la
		// meta earned que esta conciliación repara.
		add_action( 'akb_rewards_award_already_done', array( $this, 'reconcile_from_already_done' ), 5, 1 );
		add_action( 'woocommerce_register_form', array( $this, 'render_register_teaser' ), 25 );
	}

	/**
	 * Reintento de conciliación para pedidos ya acreditados: si el crédito
	 * del bono quedó huérfano en el ledger (fatal antes de sellar la marca),
	 * maybe_pay lo detecta y sella sin re-crédito.
	 *
	 * @param \WC_Order $order Pedido ya acreditado.
	 * @return void
	 */
	public function reconcile_from_already_done( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$this->maybe_pay( $order, (int) $order->get_customer_id(), 0 );
	}

	/**
	 * Paga el bono si esta es la primera compra acreditada del cliente.
	 *
	 * @param \WC_Order $order   Pedido recién acreditado.
	 * @param int       $user_id Cliente.
	 * @param int       $points  Puntos de la compra (no usados).
	 * @return void
	 */
	public function maybe_pay( $order, $user_id, $points ): void {
		unset( $points );

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$bonus = Options::welcome_bonus();
		if ( $bonus <= 0 ) {
			return;
		}

		$user_id  = (int) $user_id;
		$order_id = (int) $order->get_id();

		Ledger::with_user_lock(
			$user_id,
			static function () use ( $order, $user_id, $order_id, $bonus ): void {
				// Re-lectura BAJO candado: dos pedidos concurrentes del mismo
				// cliente no pueden pagar el bono dos veces.
				if ( Ledger::raw_user_meta_int( $user_id, self::META_WELCOME_PAID ) > 0 ) {
					return;
				}

				// Conciliación de estados en el ledger: si el crédito del bono de
				// este pedido ya existe y no ha sido revertido, se sincronizan las metas.
				// Para pedidos re-activados, la clave incorpora el índice de ocurrencia.
				$welcome_credits = Ledger::count_movements( 'order', $order_id, 'welcome_bonus' );
				$reversal_rows   = Ledger::count_movements( 'order', $order_id, 'order_reversal' );

				if ( $welcome_credits > $reversal_rows ) {
					update_user_meta( $user_id, self::META_WELCOME_PAID, $order_id );
					$earned = (int) $order->get_meta( Accrual::META_EARNED );
					$order->update_meta_data( Accrual::META_EARNED, $earned + $bonus );
					$order->update_meta_data( Accrual::META_WELCOME_BONUS, $bonus );
					$order->save_meta_data();
					$order->add_order_note(
						'Puntos Akibara: bono de bienvenida reconciliado desde el ledger (ya estaba acreditado).'
					);
					return;
				}

				// Validación de elegibilidad de primera compra por persona y monto en dinero.
				// Se evalúa después de la conciliación para permitir re-sello si ya existía crédito.
				$motivo = '';
				if ( ! FirstPurchase::meets_minimum( $order ) ) {
					$motivo = Options::first_purchase_min() > 0
						? sprintf( 'la compra no llega al mínimo de $%s pagados con dinero en productos', number_format_i18n( Options::first_purchase_min() ) )
						: 'la compra se pagó entera con puntos';
				} elseif ( FirstPurchase::has_prior_purchase( $order ) ) {
					$motivo = 'la cuenta, el RUT, el teléfono o el correo de este pedido ya aparecen en una compra anterior';
				} elseif ( self::has_referral_benefit( $order ) ) {
					$motivo = 'el pedido utilizó cupón o beneficio de referido (exclusión de doble bono)';
				} elseif ( self::has_welcome_coupon( $order ) ) {
					$motivo = 'el pedido utilizó cupón de bienvenida (exclusión de doble bono)';
				}

				if ( '' !== $motivo ) {
					// Una sola nota por pedido: cada re-transición volvería a
					// escribirla.
					if ( '' === (string) $order->get_meta( self::META_SKIP_NOTED ) ) {
						$order->update_meta_data( self::META_SKIP_NOTED, 1 );
						$order->save_meta_data();
						$order->add_order_note( sprintf( 'Puntos Akibara: sin bono de bienvenida — %s.', $motivo ) );
					}
					return;
				}

				$result = Ledger::credit(
					$user_id,
					$bonus,
					'welcome_bonus',
					'order',
					$order_id,
					sprintf( 'Bono de bienvenida (pedido #%d)', $order_id ),
					true,
					sprintf( 'welcome_%d_%d_%d', $user_id, $order_id, $welcome_credits + 1 )
				);

				if ( ! is_int( $result ) ) {
					return;
				}

				update_user_meta( $user_id, self::META_WELCOME_PAID, $order_id );

				// El bono viaja DENTRO del earned del pedido: la anulación lo
				// arrastra sin lógica extra (ver Accrual::deduct_all).
				$earned = (int) $order->get_meta( Accrual::META_EARNED );
				$order->update_meta_data( Accrual::META_EARNED, $earned + $bonus );
				$order->update_meta_data( Accrual::META_WELCOME_BONUS, $bonus );
				$order->save_meta_data();
				$order->add_order_note(
					sprintf( 'Puntos Akibara: bono de bienvenida de %d puntos por la primera compra.', $bonus )
				);
			}
		);
	}

	/**
	 * Teaser informativo en el formulario de registro: comunica el beneficio
	 * que se activa tras la primera compra elegible.
	 *
	 * @return void
	 */
	public function render_register_teaser(): void {
		// Por `Promise`: fuera de vigencia la primera compra no paga el bono, y
		// lo que no se va a pagar no se promete.
		$bonus = Promise::welcome_bonus();
		if ( $bonus <= 0 || 'yes' !== (string) get_option( 'akb_rewards_welcome_teaser_enabled', 'yes' ) ) {
			return;
		}

		printf(
			'<p style="margin:12px 0;padding:10px 12px;border-left:3px solid #E60012;background:rgba(230,0,18,.08)">🎁 Al crear tu cuenta te esperan <strong>%s Puntos Akibara</strong>: se activan con tu primera compra%s. Son uno por persona.</p>',
			esc_html( number_format_i18n( $bonus ) ),
			Options::first_purchase_min() > 0
				? esc_html( sprintf( ' desde $%s en productos', number_format_i18n( Options::first_purchase_min() ) ) )
				: ''
		);
	}

	/**
	 * ¿Le debemos aún el bono a este usuario? Lo usa Mi cuenta para el
	 * banner «esperándote» (solo lectura, sin candado: es informativo).
	 *
	 * @param int $user_id Usuario.
	 * @return bool
	 */
	public static function is_pending_for( int $user_id ): bool {
		// Lector CACHEADO a propósito: la re-lectura cruda del Ledger existe
		// para decidir bajo candado, y de paso VACÍA la caché de usermeta del
		// cliente — un precio que un banner informativo no tiene por qué
		// pagar (ni obligar a re-leer todo lo demás de esa página).
		return Promise::welcome_bonus() > 0
			&& 'yes' === (string) get_option( 'akb_rewards_welcome_teaser_enabled', 'yes' )
			&& (int) get_user_meta( $user_id, self::META_WELCOME_PAID, true ) <= 0;
	}

	/**
	 * Decide la elegibilidad tras la anulación del pedido pagador.
	 *
	 * Solo devuelve la elegibilidad si la reversa recuperó al menos el bono:
	 * un débito clampado (saldo ya gastado) que la devolviera crearía 500
	 * puntos de la nada, en bucle.
	 *
	 * @param \WC_Order $order    Pedido anulado.
	 * @param int       $user_id  Cliente.
	 * @param int       $deducted Puntos que la reversa recuperó de verdad.
	 * @return void
	 */
	public function maybe_restore_eligibility( $order, $user_id, $deducted = 0 ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$user_id = (int) $user_id;
		$paid_by = (int) get_user_meta( $user_id, self::META_WELCOME_PAID, true );

		if ( $paid_by !== (int) $order->get_id() || $paid_by <= 0 ) {
			return;
		}

		$bonus = (int) $order->get_meta( Accrual::META_WELCOME_BONUS );

		if ( $bonus <= 0 ) {
			// Meta ausente o en 0 con la marca puesta: anomalía. Sin saber
			// cuánto era el bono, devolver la elegibilidad reabriría el ciclo
			// farmeable — la marca se queda y se deja huella.
			$order->add_order_note(
				'Puntos Akibara: la marca del bono apunta a este pedido pero la meta del bono falta; la elegibilidad NO se devuelve (revisar).'
			);
			return;
		}

		if ( (int) $deducted < $bonus ) {
			// El bono no se recuperó: la marca se queda y el cliente no
			// vuelve a ser elegible. Huella para soporte.
			$order->add_order_note(
				sprintf(
					'Puntos Akibara: el bono de bienvenida (%d) no se recuperó completo en la reversa (solo %d); la elegibilidad NO se devuelve.',
					$bonus,
					(int) $deducted
				)
			);
			return;
		}

		delete_user_meta( $user_id, self::META_WELCOME_PAID );
		$order->update_meta_data( Accrual::META_WELCOME_BONUS, 0 );
		$order->save_meta_data();
	}

	/**
	 * ¿El pedido utilizó cupón de referido o tiene sellada la atribución de un amigo?
	 *
	 * @param \WC_Order $order Pedido.
	 * @return bool
	 */
	public static function has_referral_benefit( \WC_Order $order ): bool {
		if ( '' !== (string) $order->get_meta( '_akb_ref_code' ) ) {
			return true;
		}
		if ( '' !== (string) $order->get_meta( Referrals::META_REFERRED_BY ) ) {
			return true;
		}
		if ( method_exists( $order, 'get_coupon_codes' ) ) {
			foreach ( $order->get_coupon_codes() as $code ) {
				$code_lower = strtolower( trim( (string) $code ) );
				if ( 0 === strpos( $code_lower, 'ref-' ) || 0 === strpos( $code_lower, 'refreferido-' ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * ¿El pedido utilizó cupón de bienvenida del popup (código hola* o meta _akb_welcome_coupon)?
	 *
	 * @param \WC_Order $order Pedido.
	 * @return bool
	 */
	public static function has_welcome_coupon( \WC_Order $order ): bool {
		if ( '1' === (string) $order->get_meta( '_akb_welcome_coupon' ) ) {
			return true;
		}
		if ( method_exists( $order, 'get_coupon_codes' ) ) {
			foreach ( $order->get_coupon_codes() as $code ) {
				$code_lower = strtolower( trim( (string) $code ) );
				if ( 0 === strpos( $code_lower, 'hola' ) ) {
					return true;
				}
				if ( function_exists( 'wc_get_coupon_id_by_code' ) ) {
					$coupon_id = wc_get_coupon_id_by_code( $code );
					if ( $coupon_id > 0 && '1' === (string) get_post_meta( $coupon_id, '_akb_welcome_coupon', true ) ) {
						return true;
					}
				}
			}
		}
		return false;
	}
}
