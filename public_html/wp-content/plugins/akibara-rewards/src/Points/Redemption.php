<?php
/**
 * Redemption — Canje de puntos en carrito y checkout.
 *
 * Reglas operativas y contratos contables:
 *   - 1 punto = $1 CLP y el descuento aplicado es exactamente el valor facial
 *     IVA incluido. WooCommerce reparte impuesto sobre los fees negativos
 *     aunque se declaren no imponibles, por lo que el fee se calcula en valor neto.
 *   - La captura cobra lo que el fee del pedido concedió, bajo candado de
 *     pedido, metadatos actualizados y event_key UNIQUE en el ledger. Si el candado
 *     está ocupado, se reintenta vía cola asíncrona.
 *   - Contabilidad de reposición en dos cuentas:
 *       RESTORED: acumulado devuelto al cliente.
 *       REVIVAL_DEBT: porción devuelta por anulación de pedido, re-cobrable al revivir.
 *     La reposición proporcional de un reembolso parcial es definitiva.
 *   - Anulación (cancelled/refunded/failed/trash) repone el saldo pendiente.
 *     (redeemed − RESTORED); el revival re-cobra la deuda y DESCUENTA de
 *     RESTORED solo lo COBRADO — un re-cobro clampado en 0 no re-arma la
 *     reposición (el bucle failed→pending ya no acuña puntos).
 *   - La proporción del reembolso parcial se mide sobre los ÍTEMS: un
 *     reembolso solo de envío no devuelve canje.
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
final class Redemption {

	public const SESSION_KEY          = 'akb_rewards_redemption';
	public const FEE_NAME             = 'Puntos Akibara';
	public const META_REDEEMED_POINTS = '_akb_rewards_redeemed_points';
	public const META_REDEEMED_VALUE  = '_akb_rewards_redeemed_value';
	public const META_RESTORED_POINTS = '_akb_rewards_redeemed_restored';
	public const META_REVIVAL_DEBT    = '_akb_rewards_redeemed_revival_debt';
	public const META_UNSETTLED       = '_akb_rewards_redeemed_unsettled';

	/**
	 * Devolución de puntos que se le DEBE al cliente y todavía no se pudo asentar.
	 *
	 * JSON `{points, key}`: cuántos, y la clave de idempotencia con la que se
	 * intentó. Existe porque el ajuste a la baja de un canje reutilizado
	 * (`reconcile_reused()`), cuando el ledger no respondía, dejaba una nota «se
	 * reintenta solo» y agendaba un reintento que NO podía rehacer la devolución:
	/**
	 * Registra puntos pendientes de reintegro en el ledger para conciliación posterior.
	 */
	public const META_PENDING_GIVEBACK = '_akb_rewards_redeemed_pending_giveback';

	/**
	 * Pedidos impagos de la misma compra que este pedido reemplaza y que aún
	 * retenían canje al crearlo. Vive en el pedido, no en memoria: si la caja
	 * falla después de crear el pedido nuevo, el siguiente intento reutiliza
	 * ese borrador sin volver a avisar que se creó.
	 */
	public const META_SUPERSEDES = '_akb_rewards_supersedes';

	/**
	 * Marca de rechazo de pago por la pasarela para pedidos en estado pendiente.
	 * Permite reasignar los puntos en un nuevo intento del cliente sin duplicar cobros.
	 */
	public const META_PAYMENT_REJECTED = '_akb_rewards_payment_rejected';

	/**
	 * Hasta cuándo un pedido fallido re-cobrado en «pagar pedido» puede seguir
	 * en la pasarela. Mientras tanto, sólo la revisión agendada para eso
	 * devuelve sus puntos: un reintento de 5 minutos que ya estaba en la cola
	 * los soltaba con el cliente pagando.
	 */
	public const META_PAY_WINDOW = '_akb_rewards_pay_window_until';

	/**
	 * La intención de canje (su `set_at`) que la caja horneó en este pedido.
	 * Una intención con otro `set_at` se fijó después y es para la compra
	 * siguiente.
	 */
	public const META_INTENT_SET_AT = '_akb_rewards_intent_set_at';

	/** Acción de la cola que reintenta una operación de canje: (pedido). */
	public const HOOK_RETRY = 'akb_rewards_redemption_retry';

	/** Reintentos ya agendados para el pedido. */
	public const META_RETRIES = '_akb_rewards_redemption_retries';

	private const MAX_RETRIES = 5;

	/** Motivo de la revisión que espera la ventana de pago de un pedido impago. */
	private const RETRY_IMPAGO = 'impago';

	/**
	 * Errores de la Store API que significan «la pasarela no cobró»: el
	 * rechazo con mensaje, la excepción de la pasarela y un resultado inválido.
	 * Los demás (dirección, stock, el propio saldo) ocurren antes de pagar.
	 */
	private const PAYMENT_ERROR_CODES = array(
		'woocommerce_rest_payment_error',
		'woocommerce_rest_checkout_process_payment_error',
		'woocommerce_rest_checkout_invalid_payment_result',
	);

	/** Resultados de un cobro de canje hecho donde todavía se puede detener el pago. */
	private const COBRO_OK        = 'ok';
	private const COBRO_SIN_SALDO = 'sin_saldo';
	private const COBRO_OCUPADO   = 'ocupado';

	/**
	 * Mientras corre un reintento: Action Scheduler cuenta la acción EN CURSO
	 * como agendada, así que desde dentro «¿ya hay uno en la cola?» siempre
	 * diría que sí y el siguiente no se agendaría nunca.
	 */
	private static bool $in_retry = false;

	/** Si la corrida de reintento en curso volvió a fallar (y ya agendó o agotó). */
	private static bool $retry_failed = false;

	/**
	 * Pedidos que salieron del borrador de la caja de bloques en ESTA petición.
	 *
	 * @var array<int,bool>
	 */
	private static array $left_draft = array();

	/**
	 * Pedidos cuya marca de rechazo borró ESTA petición para pagarlos y cuyo
	 * desenlace todavía no se conoce (ver await_outcome()).
	 *
	 * @var array<int,bool>
	 */
	private static array $awaiting_outcome = array();

	/** @var bool Si el cierre de la petición ya vigila esos pedidos. */
	private static bool $shutdown_hooked = false;

	/** Lo que lee quien compra cuando la caja se detiene. */
	private const MSG_CAJA = 'Tu saldo de Puntos Akibara cambió mientras comprabas y ya no alcanza para el descuento que elegiste. Revisa el total y vuelve a confirmar tu compra.';

	/** Lo que lee quien intenta pagar un pedido ya creado que el saldo no cubre. */
	private const MSG_PEDIDO = 'Tu saldo de Puntos Akibara ya no cubre el descuento de este pedido, así que no se puede pagar. Vuelve a armar tu compra desde el carrito.';

	/** Lo que lee quien compra cuando otra operación tenía tomados sus puntos. */
	private const MSG_OCUPADO = 'No pudimos confirmar tus Puntos Akibara en este momento. Espera unos segundos y vuelve a confirmar tu compra.';

	/**
	 * Veredicto de clamp_requested() cuando lo pedido no llega al mínimo: no
	 * es «0 puntos» (que borra el canje), es «no se toca nada».
	 */
	public const BELOW_MIN = -1;

	private const LOCK_TIMEOUT = 3;

	/**
	 * Cuelga los hooks.
	 *
	 * Prioridad 20 en el cálculo de fees: después de cualquier fee de
	 * terceros y antes de que WC_Cart_Totals lea la lista. La reposición va a
	 * prioridad 10, ANTES que el recálculo de Accrual (20): la base puntuable
	 * resta el canje NETO y necesita RESTORED al día.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'woocommerce_cart_calculate_fees', array( $this, 'apply_fee' ), 20 );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'capture' ), 20, 1 );
		add_action( 'woocommerce_store_api_checkout_order_created', array( $this, 'remember_superseded' ), 10, 1 );
		// La caja clásica comparte `available_points()`, registrando pedidos reemplazados.
		add_action( 'woocommerce_checkout_order_created', array( $this, 'remember_superseded' ), 10, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'capture_from_order' ), 20, 1 );
		add_action( 'woocommerce_cart_emptied', array( $this, 'clear_intent' ) );
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_status_changed' ), 10, 4 );
		add_action( 'woocommerce_order_refunded', array( $this, 'on_order_refunded' ), 10, 2 );
		add_action( self::HOOK_RETRY, array( $this, 'run_retry' ), 10, 2 );
		add_action( 'woocommerce_before_pay_action', array( $this, 'guard_order_pay' ), 10, 1 );
		add_action( 'woocommerce_after_pay_action', array( $this, 'after_order_pay' ), 10, 1 );
		add_filter( 'rest_post_dispatch', array( $this, 'watch_checkout_response' ), 10, 3 );
		add_filter( 'woocommerce_payment_successful_result', array( $this, 'forget_rejection_on_success' ), 10, 2 );
		add_filter( 'woocommerce_coupon_is_valid', array( $this, 'disallow_coupon_with_points' ), 10, 2 );
		add_action( 'template_redirect', array( $this, 'handle_points_bundle_switch' ) );
	}

	/**
	 * Fija la intención de canje en sesión. 0 la borra.
	 *
	 * @param int $points Puntos a canjear.
	 * @return void
	 */
	public static function set_intent( int $points ): void {
		$session = function_exists( 'WC' ) && WC()->session ? WC()->session : null;
		if ( null === $session ) {
			return;
		}

		if ( $points <= 0 ) {
			$session->set( self::SESSION_KEY, null );
			return;
		}

		$session->set(
			self::SESSION_KEY,
			array(
				'points' => $points,
				'set_at' => time(),
			)
		);
	}

	/**
	 * Cuándo se fijó la intención vigente (0 si no hay, o si viene de una
	 * sesión anterior a que se anotara).
	 *
	 * @return int
	 */
	private static function intent_set_at(): int {
		$session = function_exists( 'WC' ) && WC()->session ? WC()->session : null;
		$data    = null === $session ? null : $session->get( self::SESSION_KEY );

		return is_array( $data ) ? max( 0, (int) ( $data['set_at'] ?? 0 ) ) : 0;
	}

	/**
	 * Puntos de la intención vigente en sesión (sin validar contra saldo).
	 *
	 * @return int
	 */
	public static function intent_points(): int {
		$session = function_exists( 'WC' ) && WC()->session ? WC()->session : null;
		if ( null === $session ) {
			return 0;
		}

		$data = $session->get( self::SESSION_KEY );
		if ( ! is_array( $data ) || empty( $data['points'] ) ) {
			return 0;
		}

		return max( 0, (int) $data['points'] );
	}

	/**
	 * Borra la intención (carrito vaciado, canje retirado).
	 *
	 * @return void
	 */
	public function clear_intent(): void {
		self::set_intent( 0 );
		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( 'akb_prefer_points_over_bundle', false );
		}
	}

	/**
	 * Conmuta entre el descuento automático de pack (akb_sb_) y el canje de Puntos Akibara
	 * cuando los puntos disponibles del cliente superan al ahorro del pack.
	 */
	public function handle_points_bundle_switch(): void {
		if ( ! isset( $_GET['akb_prefer_points_switch'] ) && ! isset( $_GET['akb_prefer_bundle_switch'] ) ) {
			return;
		}

		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}

		if ( isset( $_GET['akb_prefer_points_switch'] ) ) {
			WC()->session->set( 'akb_prefer_points_over_bundle', true );
			if ( WC()->cart instanceof \WC_Cart ) {
				foreach ( WC()->cart->get_applied_coupons() as $coupon_code ) {
					if ( 0 === strpos( (string) $coupon_code, 'akb_sb_' ) ) {
						WC()->cart->remove_coupon( $coupon_code );
					}
				}
				WC()->cart->calculate_totals();
				$limits = self::redemption_limits( WC()->cart, get_current_user_id() );
				if ( ( $limits['max_points'] ?? 0 ) > 0 ) {
					self::set_intent( (int) $limits['max_points'] );
					WC()->cart->calculate_totals();
				} else {
					WC()->session->set( 'akb_prefer_points_over_bundle', false );
					WC()->cart->calculate_totals();
				}
			}
			wp_safe_redirect( remove_query_arg( 'akb_prefer_points_switch' ) );
			exit;
		}

		if ( isset( $_GET['akb_prefer_bundle_switch'] ) ) {
			WC()->session->set( 'akb_prefer_points_over_bundle', false );
			self::set_intent( 0 );
			if ( WC()->cart instanceof \WC_Cart ) {
				WC()->cart->calculate_totals();
			}
			wp_safe_redirect( remove_query_arg( 'akb_prefer_bundle_switch' ) );
			exit;
		}
	}

	/**
	 * Clamp ÚNICO de lo que el cliente PIDE canjear: mínimo configurado y
	 * saldo real. Lo consumen las dos superficies que fijan la intención (Mi
	 * cuenta y la caja del checkout de bloques); el tope por carrito lo pone
	 * después effective_points(). Tres copias del mismo clamp fue donde nació
	 * la divergencia entre pantallas.
	 *
	 * @param int $user_id   Usuario que canjea.
	 * @param int $requested Puntos pedidos.
	 * @return int Puntos a fijar, o BELOW_MIN si no llega al mínimo (la
	 *             intención previa NO se toca).
	 */
	public static function clamp_requested( int $user_id, int $requested ): int {
		if ( $user_id <= 0 ) {
			return 0; // El canje exige cuenta.
		}

		$min = Options::redeem_min_points();
		if ( $requested > 0 && $min > 0 && $requested < $min ) {
			return self::BELOW_MIN;
		}

		return max( 0, min( $requested, self::available_points( $user_id ) ) );
	}

	/**
	 * Puntos disponibles para esta compra: saldo actual más los retenidos
	 * en pedidos de la misma sesión cuyo pago fue rechazado.
	 *
	 * Permite reutilizar el canje en un reintento de compra sin duplicar deducciones.
	 *
	 * @param int $user_id Cliente.
	 * @return int
	 */
	public static function available_points( int $user_id ): int {
		if ( $user_id <= 0 ) {
			return 0;
		}

		return max( 0, Ledger::balance( $user_id ) ) + self::releasable_for_session( $user_id );
	}

	/**
	 * Canje cobrado y no devuelto en TODOS los pedidos pendientes del cliente,
	 * estén o no en la compra en curso. Es lo que Mi cuenta muestra como
	 * «apartado»: un pedido pagado con otra compra sale de la sesión y sus
	 * puntos desaparecían de la vista hasta que WooCommerce lo anulaba.
	 *
	 * @param int $user_id Cliente.
	 * @return int
	 */
	public static function held_in_unpaid_orders( int $user_id ): int {
		if ( $user_id <= 0 || ! function_exists( 'wc_get_orders' ) ) {
			return 0;
		}

		$pedidos = wc_get_orders(
			array(
				'customer_id' => $user_id,
				'status'      => array( 'pending' ),
				'limit'       => 50,
				'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- acotada por cliente y estado; la meta sólo existe en pedidos con canje.
					array(
						'key'     => self::META_REDEEMED_POINTS,
						'compare' => 'EXISTS',
					),
				),
			)
		);

		$retenidos = 0;
		foreach ( (array) $pedidos as $pedido ) {
			if ( $pedido instanceof \WC_Order && (int) $pedido->get_customer_id() === $user_id ) {
				$retenidos += self::held_by( $pedido );
			}
		}

		return $retenidos;
	}

	/**
	 * Minutos que WooCommerce espera a un pedido impago antes de anularlo; 0 si
	 * no lo anula nunca (sin gestión de inventario o sin «Mantener en
	 * inventario»). Es la promesa de «vuelven solos a tu saldo».
	 *
	 * @return int
	 */
	public static function unpaid_cancel_minutes(): int {
		if ( 'yes' !== (string) get_option( 'woocommerce_manage_stock', 'yes' ) ) {
			return 0;
		}

		return max( 0, (int) get_option( 'woocommerce_hold_stock_minutes', 60 ) );
	}

	/**
	 * El pedido que WooCommerce tiene anotado para la compra en curso, si es de
	 * este cliente.
	 *
	 * @param int $user_id Cliente.
	 * @return \WC_Order|null
	 */
	private static function session_order( int $user_id ): ?\WC_Order {
		$session = function_exists( 'WC' ) && WC()->session ? WC()->session : null;
		if ( null === $session || $user_id <= 0 ) {
			return null;
		}

		// El mismo orden que WooCommerce al revisar el stock reservado.
		$order_id = absint( $session->get( 'order_awaiting_payment' ) );
		if ( $order_id <= 0 ) {
			$order_id = absint( $session->get( 'store_api_draft_order' ) );
		}
		if ( $order_id <= 0 ) {
			return null;
		}

		$order = wc_get_order( $order_id );

		return $order instanceof \WC_Order && (int) $order->get_customer_id() === $user_id ? $order : null;
	}

	/**
	 * El borrador que la caja de bloques anotó en la sesión, si es de este
	 * cliente.
	 *
	 * @param int $user_id Cliente.
	 * @return \WC_Order|null
	 */
	private static function draft_order_of_session( int $user_id ): ?\WC_Order {
		$session  = function_exists( 'WC' ) && WC()->session ? WC()->session : null;
		$order_id = null === $session || $user_id <= 0 ? 0 : absint( $session->get( 'store_api_draft_order' ) );
		$order    = $order_id > 0 ? wc_get_order( $order_id ) : false;

		return $order instanceof \WC_Order && (int) $order->get_customer_id() === $user_id ? $order : null;
	}

	/**
	 * Canje que la compra en curso puede volver a usar: el del pedido de la
	 * sesión y el de los que reemplazó, si su pago fue rechazado. Un pedido
	 * fallido ya los devolvió al saldo.
	 *
	 * @param int $user_id Cliente.
	 * @return int
	 */
	private static function releasable_for_session( int $user_id ): int {
		$order = self::session_order( $user_id );
		if ( null === $order ) {
			return 0;
		}

		$held = self::releasable( $order );
		foreach ( self::superseded_orders( $order ) as $anterior ) {
			$held += self::releasable( $anterior );
		}

		return $held;
	}

	/**
	 * Lo retenido por un pedido que otra compra puede usar: sólo si su último
	 * intento de pago fue rechazado.
	 *
	 * @param \WC_Order $order Pedido.
	 * @return int
	 */
	private static function releasable( \WC_Order $order ): int {
		return (int) $order->get_meta( self::META_PAYMENT_REJECTED ) > 0 ? self::held_by( $order ) : 0;
	}

	/**
	 * Canje cobrado y no devuelto de un pedido que sigue esperando pago.
	 *
	 * @param \WC_Order $order Pedido.
	 * @return int
	 */
	private static function held_by( \WC_Order $order ): int {
		if ( ! $order->has_status( 'pending' ) || ! $order->needs_payment() ) {
			return 0;
		}

		return max( 0, (int) $order->get_meta( self::META_REDEEMED_POINTS ) - (int) $order->get_meta( self::META_RESTORED_POINTS ) );
	}

	/**
	 * Los pedidos impagos de la misma compra que este pedido reemplaza, del
	 * mismo cliente, en el orden en que se crearon.
	 *
	 * @param \WC_Order $order Pedido.
	 * @return array<int,\WC_Order>
	 */
	private static function superseded_orders( \WC_Order $order ): array {
		$ids = $order->get_meta( self::META_SUPERSEDES );
		if ( ! is_array( $ids ) ) {
			return array();
		}

		$pedidos = array();
		foreach ( $ids as $id ) {
			$anterior = (int) $id > 0 && (int) $id !== (int) $order->get_id() ? wc_get_order( (int) $id ) : false;
			if ( $anterior instanceof \WC_Order && (int) $anterior->get_customer_id() === (int) $order->get_customer_id() ) {
				$pedidos[] = $anterior;
			}
		}

		return $pedidos;
	}

	/**
	 * La caja de bloques creó un pedido nuevo: se anota a qué pedido impago de
	 * la misma compra reemplaza, porque WooCommerce pisa la sesión enseguida.
	 *
	 * @param \WC_Order $order Pedido recién creado.
	 * @return void
	 */
	public function remember_superseded( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$anterior = self::session_order( (int) $order->get_customer_id() );
		if ( null === $anterior || $anterior->get_id() === $order->get_id() ) {
			return;
		}

		$vigentes = array();
		foreach ( array_merge( self::superseded_orders( $anterior ), array( $anterior ) ) as $pedido ) {
			if ( self::releasable( $pedido ) > 0 ) {
				$vigentes[] = (int) $pedido->get_id();
			}
		}

		if ( array() !== $vigentes ) {
			$order->update_meta_data( self::META_SUPERSEDES, array_values( array_unique( $vigentes ) ) );
			$order->save_meta_data();
		}
	}

	/**
	 * Si el pedido de la compra en curso ya se pagó, la intención de canje se
	 * gastó en él.
	 *
	 * Con una pasarela que redirige, quien paga y cierra la pestaña no pasa por
	 * «pedido recibido» y WooCommerce no vacía el carrito: sin esto, la compra
	 * siguiente heredaba el descuento sin que el cliente lo pidiera.
	 *
	 * @return void
	 */
	private static function forget_spent_intent(): void {
		if ( self::intent_points() <= 0 ) {
			return;
		}

		$order = self::session_order( get_current_user_id() );
		if (
			null === $order
			|| (int) $order->get_meta( self::META_REDEEMED_POINTS ) <= 0
			|| ! OrderStates::is_alive( (string) $order->get_status() )
			|| $order->needs_payment()
		) {
			return;
		}

		// Verifica si la intención almacenada corresponde al pedido procesado.
		$horneada = (int) $order->get_meta( self::META_INTENT_SET_AT );
		if ( $horneada > 0 && self::intent_set_at() !== $horneada ) {
			return;
		}

		self::set_intent( 0 );
	}

	/**
	 * Anota en el pedido qué intención de canje se horneó en su descuento.
	 *
	 * @param \WC_Order $order Pedido que sale por la caja.
	 * @return void
	 */
	private static function remember_intent( \WC_Order $order ): void {
		$set_at = self::intent_set_at();
		if ( $set_at <= 0 || self::granted_gross( $order ) <= 0 || (int) $order->get_meta( self::META_INTENT_SET_AT ) === $set_at ) {
			return;
		}

		$order->update_meta_data( self::META_INTENT_SET_AT, $set_at );
		$order->save_meta_data();
	}

	/**
	 * Puntos EFECTIVOS a canjear para un carrito: intención topada por el
	 * saldo real y por el total de las líneas (jamás descontar más que el
	 * carrito). Función de lectura, sin efectos.
	 *
	 * @param \WC_Cart $cart Carrito calculado.
	 * @return int
	 */
	public static function effective_points( \WC_Cart $cart ): int {
		$intent = self::intent_points();
		if ( $intent <= 0 ) {
			return 0;
		}
		$limits = self::redemption_limits( $cart, get_current_user_id() );
		return self::effective_points_for_limits( $intent, $limits );
	}

	/**
	 * Máximo aplicable al carrito actual, sin depender de una intención ni
	 * modificar sesión, pedidos o saldo. Mi cuenta y el motor usan esta misma
	 * base tras cupones, con IVA, sin despacho ni el fee del propio canje.
	 *
	 * Un máximo inferior al mínimo de puntos no es un canje válido.
	 *
	 * @param \WC_Cart|null $cart    Carrito calculado, o null si no existe.
	 * @param int           $user_id Dueño del saldo y de la reserva reutilizable.
	 * @return array{cart_present:bool,base:float,missing:int,available:int,min_points:int,max_points:int,value:int}
	 */
	public static function redemption_limits( ?\WC_Cart $cart, int $user_id ): array {
		$present           = null !== $cart && array() !== $cart->get_cart();
		$base              = $present ? Calculator::award_base_for_cart( $cart ) : 0.0;
		$has_coupons       = $present && $cart instanceof \WC_Cart && method_exists( $cart, 'get_applied_coupons' ) && count( $cart->get_applied_coupons() ) > 0;
		$has_bundle_coupon   = false;
		$bundle_discount_clp = 0.0;
		if ( $has_coupons ) {
			foreach ( $cart->get_applied_coupons() as $coupon_code ) {
				if ( 0 === strpos( (string) $coupon_code, 'akb_sb_' ) ) {
					$has_bundle_coupon = true;
					if ( method_exists( $cart, 'get_coupon_discount_amount' ) ) {
						$bundle_discount_clp += (float) $cart->get_coupon_discount_amount( $coupon_code ) + (float) $cart->get_coupon_discount_tax_amount( $coupon_code );
					}
				}
			}
		}
		$missing           = self::missing_for_minimum( $base );
		$available         = $user_id > 0 ? self::available_points( $user_id ) : 0;
		$minimum           = max( 1, Options::redeem_min_points() );
		$value             = Options::redeem_value();

		// Tramos oficiales de canje escalonado sobre productos elegibles (sin envío):
		// - Subtotal < $25.000 CLP: Canje bloqueado (0 puntos).
		// - Tramo 1 ($25.000 a $49.999 CLP): Tope de 2.000 puntos ($2.000 CLP), sin superar 50%.
		// - Tramo 2 (desde $50.000 CLP): Tope según Options::redeem_max_amount() ($5.000 CLP), sin superar 50%.
		$tier2_min = function_exists( 'get_option' ) ? (float) get_option( 'akb_rewards_redeem_tier2_min_cart', 50000 ) : 50000.0;
		$tier1_cap = function_exists( 'get_option' ) ? (int) get_option( 'akb_rewards_redeem_tier1_max_amount', 2000 ) : 2000;
		$tier2_cap = function_exists( 'get_option' ) ? (int) get_option( 'akb_rewards_redeem_max_amount', Options::DEFAULT_REDEEM_MAX_AMOUNT ) : Options::DEFAULT_REDEEM_MAX_AMOUNT;

		$gross_max = (int) floor( self::redeemable_gross( $base ) / $value );
		$tramo_cap = Options::redeem_max_amount() > 0
			? ( ( $base < $tier2_min && Options::redeem_min_cart() < $tier2_min ) ? $tier1_cap : $tier2_cap )
			: $gross_max;
		$capped    = min( $gross_max, $tramo_cap );

		// El umbral de Envío Gratis ($60.000 CLP) se evalúa en WooCommerce y Blue Express
		// sobre el subtotal de productos antes de fees (los Puntos Akibara son un fee, no un cupón),
		// por lo que canjear puntos en un carrito >= $60.000 conserva el Envío Gratis sin recortar el tramo.
		$free_shipping_threshold = 60000.0;
		if ( class_exists( '\Akibara\Business\Modules\ShippingCopy\ShippingCopy' ) ) {
			$dyn_threshold = (float) \Akibara\Business\Modules\ShippingCopy\ShippingCopy::threshold();
			if ( $dyn_threshold > 0.0 ) {
				$free_shipping_threshold = $dyn_threshold;
			}
		}

		$maximum = $present && 0 === $missing && ! $has_coupons
			? max( 0, min( $available, $capped ) )
			: 0;

		// Al comparar Puntos vs. Pack (akb_sb_), evaluar la base real de productos SIN restar
		// el descuento del pack, ya que al conmutar a puntos se retira el cupón akb_sb_.
		$base_without_bundle      = $has_bundle_coupon ? ( $base + $bundle_discount_clp ) : $base;
		$missing_without_bundle   = self::missing_for_minimum( $base_without_bundle );
		$gross_max_without_bundle = (int) floor( self::redeemable_gross( $base_without_bundle ) / $value );
		$tramo_cap_without_bundle = Options::redeem_max_amount() > 0
			? ( ( $base_without_bundle < $tier2_min && Options::redeem_min_cart() < $tier2_min ) ? $tier1_cap : $tier2_cap )
			: $gross_max_without_bundle;
		$capped_without_bundle    = min( $gross_max_without_bundle, $tramo_cap_without_bundle );

		$potential_max             = ( $present && 0 === $missing_without_bundle ) ? max( 0, min( $available, $capped_without_bundle ) ) : 0;
		$potential_max             = $potential_max >= $minimum ? $potential_max : 0;
		$max_redeemable_points_clp = (float) ( $potential_max * $value );
		$points_exceed_bundle      = $has_bundle_coupon && ( $max_redeemable_points_clp > $bundle_discount_clp );
		$allow_manual_switch       = ! function_exists( 'get_option' ) || 'yes' === (string) get_option( 'akb_rewards_allow_manual_switch_below_bundle', 'yes' );
		$can_switch_from_bundle    = $has_bundle_coupon && $potential_max > 0 && ( $points_exceed_bundle || $allow_manual_switch );

		return array(
			'cart_present'              => $present,
			'base'                      => $base,
			'missing'                   => $missing,
			'available'                 => $available,
			'min_points'                => $minimum,
			'max_points'                => $maximum >= $minimum ? $maximum : 0,
			'value'                     => $value,
			'has_coupons'               => $has_coupons,
			'has_bundle_coupon'         => $has_bundle_coupon,
			'bundle_discount_clp'       => $bundle_discount_clp,
			'max_redeemable_points'     => $potential_max,
			'max_redeemable_points_clp' => $max_redeemable_points_clp,
			'points_exceed_bundle'      => $points_exceed_bundle,
			'can_switch_from_bundle'    => $can_switch_from_bundle,
			'free_shipping_threshold'   => $free_shipping_threshold,
		);
	}

	/**
	 * Aplica los límites ya calculados sin otra lectura del carrito o saldo.
	 *
	 * @param int   $intent Puntos confirmados anteriormente.
	 * @param array $limits Resultado de redemption_limits().
	 * @return int
	 */
	public static function effective_points_for_limits( int $intent, array $limits ): int {
		$points = max( 0, min( $intent, $limits['max_points'] ) );
		return $points >= $limits['min_points'] ? $points : 0;
	}

	/**
	 * Valida una nueva solicitud; nunca la reduce silenciosamente.
	 *
	 * @param int   $requested Puntos solicitados.
	 * @param array $limits    Resultado de redemption_limits().
	 * @return string Vacío cuando se puede confirmar.
	 */
	public static function requested_error( int $requested, array $limits ): string {
		$error = self::unavailable_message( $limits );
		if ( '' !== $error ) {
			return $error;
		}
		if ( $requested < $limits['min_points'] ) {
			return sprintf( 'El canje parte desde %s puntos.', number_format_i18n( $limits['min_points'] ) );
		}
		if ( $requested > $limits['max_points'] ) {
			return sprintf( 'El máximo aplicable en tu carrito actual es %s puntos. Revisa el importe y confirma de nuevo.', number_format_i18n( $limits['max_points'] ) );
		}
		return '';
	}

	/**
	 * Explica por qué el máximo canónico no permite confirmar un canje.
	 *
	 * @param array{cart_present:bool,base:float,missing:int,available:int,min_points:int,max_points:int,value:int} $limits Límites del servidor.
	 * @return string Vacío cuando se puede canjear.
	 */
	public static function unavailable_message( array $limits ): string {
		if ( ! $limits['cart_present'] ) {
			return 'Añade productos al carrito para calcular cuánto puedes canjear.';
		}
		if ( ! empty( $limits['has_bundle_coupon'] ) ) {
			if ( ! empty( $limits['points_exceed_bundle'] ) || ! empty( $limits['can_switch_from_bundle'] ) ) {
				$val            = (int) ( $limits['value'] ?? 1 );
				$val            = $val > 0 ? $val : 1;
				$pts_formatted  = number_format_i18n( (int) ( ( $limits['max_redeemable_points_clp'] ?? 0 ) / $val ) );
				$pts_clp_fmt    = function_exists( 'wc_price' )
					? html_entity_decode( wp_strip_all_tags( wc_price( $limits['max_redeemable_points_clp'] ?? 0 ) ), ENT_QUOTES, 'UTF-8' )
					: ( '$' . number_format( $limits['max_redeemable_points_clp'] ?? 0, 0, ',', '.' ) );
				$bundle_clp_fmt = function_exists( 'wc_price' )
					? html_entity_decode( wp_strip_all_tags( wc_price( $limits['bundle_discount_clp'] ?? 0 ) ), ENT_QUOTES, 'UTF-8' )
					: ( '$' . number_format( $limits['bundle_discount_clp'] ?? 0, 0, ',', '.' ) );

				if ( ! empty( $limits['points_exceed_bundle'] ) ) {
					return sprintf(
						'💡 Tienes %s Puntos Akibara (%s) disponibles (superan los %s de tu descuento de pack actual). Puedes usar tus %s en puntos en lugar del descuento de pack.',
						$pts_formatted,
						$pts_clp_fmt,
						$bundle_clp_fmt,
						$pts_clp_fmt
					);
				}

				return sprintf(
					'⚡ ¡Tu pedido ya incluye un descuento directo por pack de tu serie (%s)! Con esta compra además acumularás Puntos Akibara para canjear en tus próximos pedidos (o puedes usar tus %s puntos disponibles en esta compra).',
					$bundle_clp_fmt,
					$pts_formatted
				);
			}

			return '⚡ ¡Tu pedido ya incluye un descuento directo por pack de tu serie! Con esta compra además acumularás Puntos Akibara para canjear en tus próximos pedidos.';
		}
		if ( ! empty( $limits['has_coupons'] ) ) {
			return 'Puedes elegir entre usar un cupón de descuento o canjear tus Puntos Akibara (no se pueden combinar en un mismo pedido).';
		}
		if ( $limits['missing'] > 0 ) {
			return sprintf( 'Te faltan %s en productos para poder canjear; el despacho no cuenta.', html_entity_decode( wp_strip_all_tags( wc_price( $limits['missing'] ) ), ENT_QUOTES, 'UTF-8' ) );
		}
		if ( $limits['available'] < $limits['min_points'] ) {
			return sprintf( 'Te faltan %s puntos para poder canjear: el mínimo por canje es %s.', number_format_i18n( $limits['min_points'] - $limits['available'] ), number_format_i18n( $limits['min_points'] ) );
		}
		if ( ! empty( $limits['free_shipping_warning'] ) && 0 === $limits['max_points'] ) {
			return (string) $limits['free_shipping_warning'];
		}
		if ( 0 === $limits['max_points'] ) {
			return sprintf( 'Los topes de esta compra no permiten alcanzar el mínimo de %s puntos por canje.', number_format_i18n( $limits['min_points'] ) );
		}
		return '';
	}

	/**
	 * Cuánto le falta al carrito, en pesos de productos, para poder canjear.
	 *
	 * Se mide sobre la misma base que acredita puntos (líneas con IVA y tras
	 * cupones, sin despacho). La devuelve entera y hacia arriba: si falta medio
	 * peso, falta un peso.
	 *
	 * @param float $cart_gross Productos del carrito.
	 * @return int 0 si ya puede canjear o no hay mínimo.
	 */
	public static function missing_for_minimum( float $cart_gross ): int {
		$min = Options::redeem_min_cart();
		if ( $min <= 0 ) {
			return 0;
		}

		return max( 0, (int) ceil( $min - $cart_gross ) );
	}

	/**
	 * Parte del pedido que se puede pagar con puntos, en pesos.
	 *
	 * El tope lo fija el dueño en WooCommerce → Puntos y Reseñas; 0 significa
	 * sin tope y devuelve el bruto entero (comportamiento anterior). Se aplica
	 * sobre la MISMA base con la que se acreditan los puntos —líneas tras
	 * cupones y con IVA, `Calculator::award_base_for_cart()`— para que lo que
	 * el cliente puede canjear y lo que gana hablen del mismo número; el
	 * despacho queda fuera de esa base y por eso nunca se paga con puntos.
	 *
	 * @param float $cart_gross Bruto de las líneas del carrito.
	 * @return float
	 */
	public static function redeemable_gross( float $cart_gross ): float {
		$cart_gross = max( 0.0, $cart_gross );
		$techo      = $cart_gross;

		$pct = Options::redeem_max_pct();
		if ( $pct > 0 && $pct < 100 ) {
			$techo = min( $techo, floor( $cart_gross * $pct / 100 ) );
		}

		// El tope en pesos manda si es el más estricto: los dos son palancas
		// del panel y ninguna puede saltarse a la otra.
		$monto = Options::redeem_max_amount();
		if ( $monto > 0 ) {
			$techo = min( $techo, (float) $monto );
		}

		return max( 0.0, $techo );
	}

	/**
	 * Los MISMOS topes de arriba, dichos en palabras.
	 *
	 * Fuente común para la página del programa y Mi cuenta. Incluye el límite
	 * porcentual y el límite en pesos; el motor aplica el más estricto.
	 *
	 * @since 0.4.9
	 *
	 * @return string P. ej. «el 20% de los productos, y como máximo $5.000»; '' si no hay tope.
	 */
	public static function techo_humano(): string {
		$limites = array();

		$pct = Options::redeem_max_pct();
		if ( $pct > 0 && $pct < 100 ) {
			$limites[] = sprintf( 'el %s%% de los productos', number_format_i18n( $pct ) );
		}

		$monto = Options::redeem_max_amount();
		if ( $monto > 0 ) {
			$limites[] = sprintf( '$%s', number_format_i18n( $monto ) );
		}

		return array() === $limites ? '' : implode( ', y como máximo ', $limites );
	}

	/**
	 * Añade el fee del canje al carrito.
	 *
	 * @param \WC_Cart $cart Carrito en cálculo.
	 * @return void
	 */
	public function apply_fee( $cart ): void {
		if ( ! $cart instanceof \WC_Cart ) {
			return;
		}

		if ( method_exists( $cart, 'get_applied_coupons' ) && count( $cart->get_applied_coupons() ) > 0 ) {
			return;
		}

		self::forget_spent_intent();

		$points = self::effective_points( $cart );
		if ( $points <= 0 ) {
			return;
		}

		$gross = $points * Options::redeem_value();
		$rate  = Calculator::cart_tax_rate( $cart );
		$fee   = Calculator::redemption_fee_amount( $gross, $rate );

		if ( $fee >= 0 ) {
			return;
		}

		$cart->add_fee( self::FEE_NAME, $fee, false );
	}

	/**
	 * Bloquea la aplicación de cupones si el cliente tiene un canje de Puntos Akibara aplicado.
	 *
	 * @param bool       $is_valid Estado de validez del cupón.
	 * @param \WC_Coupon $coupon   Cupón que se intenta aplicar.
	 * @return bool
	 * @throws \Exception Si hay canje de puntos activo.
	 */
	public function disallow_coupon_with_points( $is_valid, $coupon ): bool {
		if ( ! $is_valid ) {
			return false;
		}

		if ( self::intent_points() > 0 ) {
			throw new \Exception( 'Ya tienes un descuento de Puntos Akibara en tu pedido. Puedes quitar los puntos si prefieres usar este cupón.' );
		}

		return true;
	}

	/**
	 * Descuento bruto que el fee de canje CONCEDIÓ en un pedido: |total +
	 * IVA| del fee nuestro. Es la única verdad de lo que hay que cobrar.
	 *
	 * El fee se localiza por el nombre-constante (jamás traducido — candado
	 * en GuardrailsTest).
	 *
	 * @param \WC_Order $order Pedido.
	 * @return float Bruto concedido (≥ 0).
	 */
	public static function granted_gross( \WC_Order $order ): float {
		foreach ( $order->get_items( 'fee' ) as $item ) {
			if ( $item->get_name() !== self::FEE_NAME ) {
				continue;
			}
			$total = (float) $item->get_total() + (float) $item->get_total_tax();
			if ( $total < 0 ) {
				return -$total;
			}
		}

		return 0.0;
	}

	/**
	 * Captura clásica (checkout por shortcode): recibe el ID del pedido.
	 *
	 * @param int $order_id Pedido recién creado.
	 * @return void
	 */
	public function capture( $order_id ): void {
		$order = wc_get_order( (int) $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		self::remember_intent( $order );
		$this->release_superseded( $order );
		// La caja clásica también reutiliza un pedido fallido: sin saldar su
		// deuda antes, la conciliación cobraba la diferencia y el paso a pagado
		// la volvía a cobrar.
		if ( $this->settle_debt_at_checkout( $order, 'classic' ) ) {
			$this->capture_order( $order, 'classic' );
		}
	}

	/**
	 * Captura de la caja de bloques (`woocommerce_store_api_checkout_order_processed`).
	 *
	 * @param \WC_Order $order Pedido recién creado.
	 * @return void
	 */
	public function capture_from_order( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		try {
			self::remember_intent( $order );
			$this->release_superseded( $order );
			if ( $this->settle_debt_at_checkout( $order, 'store_api' ) ) {
				$this->capture_order( $order, 'store_api' );
			}

			// Limpia la marca de rechazo antes del procesamiento de pago para evitar
			// condiciones de carrera concurrentes.
			$estaba_marcado = '' !== (string) $order->get_meta( self::META_PAYMENT_REJECTED );
			self::forget_rejection( $order );
			if ( $estaba_marcado ) {
				$this->await_outcome( (int) $order->get_id() );
			}
		} finally {
			// La marca vale para ESTA caja: un proceso largo no puede confundir
			// después un pedido existente con uno recién salido del borrador.
			unset( self::$left_draft[ (int) $order->get_id() ] );
		}
	}

	/**
	 * El cliente armó otra compra tras un pago rechazado: los puntos que
	 * retiene el pedido rechazado vuelven enteros al saldo, justo antes de
	 * cobrar el nuevo.
	 *
	 * Enteros, y no sólo lo que al nuevo le falta, porque un pago rechazado no
	 * se completa solo en la pasarela: si después el cliente paga ese pedido
	 * desde Mi cuenta, «pagar pedido» vuelve a cobrar su deuda completa o no
	 * deja pagarlo (settle_debt_at_checkout()). Así sus puntos no quedan
	 * invisibles hasta que WooCommerce lo anula. Un pedido que redirigió a la
	 * pasarela no se libera nunca desde aquí (restore()).
	 *
	 * @param \WC_Order $order Pedido nuevo.
	 * @return void
	 */
	private function release_superseded( \WC_Order $order ): void {
		foreach ( self::superseded_orders( $order ) as $anterior ) {
			$this->restore( (int) $anterior->get_id(), true, (int) $order->get_id() );
		}
	}

	/**
	 * Valida y cobra la deuda de reactivación antes de autorizar el pago del pedido.
	 *
	 * Asegura que el pedido cuente con respaldo de saldo antes de remitir a la pasarela.
	 *
	 * @param \WC_Order $order    Pedido que se va a pagar.
	 * @param string    $checkout 'store_api' u 'order_pay'.
	 * @return bool False si el pago se detuvo (en 'order_pay'; en la caja lanza).
	 * @throws \Exception En la caja, si el saldo no cubre la deuda.
	 */
	private function settle_debt_at_checkout( \WC_Order $order, string $checkout ): bool {
		if ( (int) $order->get_meta( self::META_REVIVAL_DEBT ) <= 0 ) {
			return true;
		}

		$resultado = $this->recapture_after_revival( (int) $order->get_id(), true );
		if ( self::COBRO_OK === $resultado ) {
			return true;
		}

		// Si el cobro no pudo completarse por candado ocupado o falta de saldo, se detiene el flujo.
		$this->stop_without_balance( $order, $checkout, self::COBRO_OCUPADO === $resultado );

		return false;
	}

	/**
	 * Pagar un pedido ya creado (Mi cuenta -> Pedidos -> Pagar).
	 *
	 * Valida que los pedidos pendientes mantengan saldo suficiente para respaldar
	 * el descuento aplicado. En caso contrario, se detiene el pago.
	 *
	 * @param \WC_Order $order Pedido que se va a pagar.
	 * @return void
	 */
	public function guard_order_pay( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		// Un pedido fallido con deuda de revival: sus puntos volvieron al saldo
		// al fallar. Se re-cobran completos antes de cobrar el pago.
		if ( (int) $order->get_meta( self::META_REVIVAL_DEBT ) > 0 ) {
			if ( ! $this->settle_debt_at_checkout( $order, 'order_pay' ) ) {
				return;
			}

			// Si la pasarela vuelve a rechazar sin cambiar de estado, se agenda
			// revisión para cuando cierre la ventana de pago.
			if ( $order->has_status( 'failed' ) ) {
				$order->update_meta_data( self::META_PAY_WINDOW, time() + self::unpaid_window() );
				$order->save_meta_data();
				self::schedule_retry( (int) $order->get_id(), self::unpaid_window(), self::RETRY_IMPAGO );
			}
		}

		// Limpia la marca de rechazo previa para prevenir reutilización concurrente.
		self::forget_rejection( $order );

		if ( self::granted_gross( $order ) <= 0 ) {
			return;
		}

		// También con la captura hecha: la caja pudo dejar el pedido con un fee
		// distinto del canje cobrado (una conciliación que se detuvo), y aquí no
		// se miraba, así que se pagaba con la diferencia sin cobrar.
		$this->capture_order( $order, 'order_pay' );
	}

	/**
	 * «Pagar pedido» terminó sin llegar a la pasarela: WooCommerce sólo sigue
	 * hasta aquí si no redirigió (`WC_Form_Handler::pay_action`).
	 *
	 * Un pedido fallido cuyo pago se rechazó otra vez devuelve ya lo que se le
	 * re-cobró: esperar a la revisión de una hora dejaba esos puntos fuera de
	 * la vista del cliente. Uno pendiente queda marcado como rechazado.
	 *
	 * @param \WC_Order $order Pedido.
	 * @return void
	 */
	public function after_order_pay( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		if ( $order->has_status( 'failed' ) ) {
			if ( '' !== (string) $order->get_meta( self::META_PAY_WINDOW ) ) {
				$order->delete_meta_data( self::META_PAY_WINDOW );
				$order->save_meta_data();
			}
			$this->restore( (int) $order->get_id(), true );
			return;
		}

		if ( $order->has_status( 'pending' ) ) {
			self::record_rejection( $order );
		}
	}

	/**
	 * Lee la respuesta de la caja de bloques: si la pasarela rechazó el pago,
	 * marca el pedido; si lo aceptó o redirigió, borra la marca.
	 *
	 * La Store API no avisa por ningún gancho de cómo terminó el pago: lo dice
	 * sólo su respuesta (`payment_result`, o el error cuando la pasarela
	 * rechaza con mensaje).
	 *
	 * @param mixed $response Respuesta REST.
	 * @param mixed $server   Servidor REST (no usado).
	 * @param mixed $request  Petición REST.
	 * @return mixed La respuesta, intacta.
	 */
	public function watch_checkout_response( $response, $server, $request ) {
		unset( $server );

		if (
			! $response instanceof \WP_REST_Response
			|| ! $request instanceof \WP_REST_Request
			|| 'POST' !== $request->get_method()
			|| 1 !== preg_match( '#^/wc/store(?:/v\d+)?/checkout/?$#', (string) $request->get_route() )
		) {
			return $response;
		}

		$data = $response->get_data();
		if ( ! is_array( $data ) ) {
			return $response;
		}

		$pago = is_array( $data['payment_result'] ?? null ) ? (string) ( $data['payment_result']['payment_status'] ?? '' ) : '';

		// Sin `order_id` (las respuestas de error no lo traen) manda el borrador
		// que ESTA ruta acaba de fijar en la sesión, no `order_awaiting_payment`,
		// que puede ser un pedido viejo de la caja clásica: marcarlo dejaba
		// prestables los puntos del pedido equivocado (segunda revisión).
		$order = (int) ( $data['order_id'] ?? 0 ) > 0
			? wc_get_order( (int) $data['order_id'] )
			: self::draft_order_of_session( get_current_user_id() );
		if ( ! $order instanceof \WC_Order ) {
			// Sin pedido que leer tampoco hay desenlace: lo desmarcado vuelve.
			$this->restore_unsettled_marks();
			return $response;
		}

		if ( in_array( $pago, array( 'failure', 'error' ), true ) || in_array( (string) ( $data['code'] ?? '' ), self::PAYMENT_ERROR_CODES, true ) ) {
			unset( self::$awaiting_outcome[ (int) $order->get_id() ] );
			self::record_rejection( $order );
		} elseif ( in_array( $pago, array( 'success', 'pending' ), true ) ) {
			unset( self::$awaiting_outcome[ (int) $order->get_id() ] );
			self::forget_rejection( $order );
		} else {
			// Ni aceptado ni rechazado por la pasarela: la caja se detuvo por otra
			// cosa. Lo que esta petición borró vuelve a su sitio ya, sin esperar
			// al cierre.
			$this->restore_unsettled_marks();
		}

		return $response;
	}

	/**
	 * Anota que ESTA petición borró la marca de rechazo de un pedido para
	 * pagarlo, y que todavía no se sabe cómo terminó.
	 *
	 * La marca se borra antes de llamar a la pasarela y la respuesta de la caja
	 * la repone si rechaza. Pero entre las dos cosas la caja puede detenerse sin
	 * que la pasarela llegue a contestar: otra extensión lanza en
	 * `woocommerce_store_api_checkout_order_processed`, la Store API responde
	 * `woocommerce_rest_unknown_server_error`, o PHP muere. Ninguno de esos
	 * códigos es de pago; la restauración permite recuperar los puntos del
	 * pedido pendiente al reintentar después de ese fallo.
	 *
	 * No vale marcar ante CUALQUIER error de la caja: una respuesta de error
	 * puede venir de otra pestaña mientras el pedido de la sesión está en la
	 * pasarela con redirección, y marcarlo prestaría sus puntos con el cliente
	 * pagando. Sólo se repone lo que esta misma petición borró: es volver al
	 * estado de antes, no decidir uno nuevo.
	 *
	 * @param int $order_id Pedido cuya marca se acaba de borrar.
	 * @return void
	 */
	private function await_outcome( int $order_id ): void {
		self::$awaiting_outcome[ $order_id ] = true;

		if ( ! self::$shutdown_hooked ) {
			self::$shutdown_hooked = true;
			// `shutdown` también corre tras un fatal o un tiempo agotado.
			add_action( 'shutdown', array( $this, 'restore_unsettled_marks' ) );
		}
	}

	/**
	 * Repone la marca de rechazo de los pedidos que esta petición desmarcó y
	 * cuyo pago no llegó a ningún desenlace. `record_rejection()` sólo marca un
	 * pedido que sigue pendiente y reteniendo canje: si entretanto se pagó, no
	 * hace nada.
	 *
	 * @return void
	 */
	public function restore_unsettled_marks(): void {
		foreach ( array_keys( self::$awaiting_outcome ) as $order_id ) {
			unset( self::$awaiting_outcome[ $order_id ] );
			$order = wc_get_order( (int) $order_id );
			if ( $order instanceof \WC_Order ) {
				self::record_rejection( $order );
			}
		}
	}

	/**
	 * Un pago que llegó a la pasarela (caja clásica y «pagar pedido») borra la
	 * marca de rechazo.
	 *
	 * @param mixed $result   Resultado de la pasarela.
	 * @param mixed $order_id Pedido.
	 * @return mixed El resultado, intacto.
	 */
	public function forget_rejection_on_success( $result, $order_id ) {
		$order = wc_get_order( (int) $order_id );
		if ( $order instanceof \WC_Order ) {
			self::forget_rejection( $order );
		}

		return $result;
	}

	/**
	 * Marca un pedido pendiente que retiene canje como rechazado por la pasarela.
	 *
	 * @param \WC_Order $order Pedido.
	 * @return void
	 */
	private static function record_rejection( \WC_Order $order ): void {
		if ( self::held_by( $order ) <= 0 ) {
			return;
		}

		$order->update_meta_data( self::META_PAYMENT_REJECTED, time() );
		$order->save_meta_data();
	}

	/**
	 * Borra la marca de rechazo: el último intento llegó a la pasarela.
	 *
	 * @param \WC_Order $order Pedido.
	 * @return void
	 */
	private static function forget_rejection( \WC_Order $order ): void {
		if ( '' === (string) $order->get_meta( self::META_PAYMENT_REJECTED ) ) {
			return;
		}

		$order->delete_meta_data( self::META_PAYMENT_REJECTED );
		$order->save_meta_data();
	}

	/**
	 * Captura del canje: en la caja (bloques y clásica) y en los reintentos (un
	 * candado ocupado al crear no puede dejar el descuento regalado).
	 *
	 * Cobra los puntos que respaldan el descuento HORNEADO en el pedido, bajo
	 * candado de pedido, con meta releída fresca y event_key único.
	 *
	 * Si el saldo ya no alcanza —dos compras a la vez con el mismo saldo, o
	 * Si el saldo no cubre el descuento del pedido al momento del pago, se detiene la compra.
	 * En la caja o en orden_pay, previene que la pasarela autorice una venta sin respaldo de saldo.
	 * En reintentos de fondo sobre pedidos existentes, cobra lo disponible y genera registro.
	 *
	 * Un candado ocupado no es saldo insuficiente. Donde todavía se puede
	 * detener el pago, lo detiene con otro aviso («vuelve a intentar»): seguir
	 * dejaba ir a la pasarela un descuento sin cobrar. En los reintentos deja
	 * nota y agenda otro.
	 *
	 * @param \WC_Order $order    Pedido (el MISMO objeto que maneja WooCommerce).
	 * @param string    $checkout 'store_api' o 'classic' desde la caja, 'order_pay' al pagar un pedido ya creado; '' en reintentos.
	 * @return void
	 * @throws \Exception En la caja, si el saldo ya no respalda el descuento.
	 */
	private function capture_order( \WC_Order $order, string $checkout ): void {
		$resultado = $this->lock_and_capture( (int) $order->get_id(), $checkout );

		if ( self::COBRO_OK !== $resultado ) {
			$this->stop_without_balance( $order, $checkout, self::COBRO_OCUPADO === $resultado );
		}
	}

	/**
	 * El cobro bajo el candado del pedido, con la meta releída fresca.
	 *
	 * @param int    $order_id Pedido.
	 * @param string $checkout Contexto (ver capture_order()).
	 * @return string COBRO_*: en los reintentos ('' ) siempre COBRO_OK, porque
	 *                no hay pago que detener.
	 */
	private function lock_and_capture( int $order_id, string $checkout ): string {
		// Si el candado del pedido no se consigue, el cuerpo no corre: en la caja
		// eso es «no se pudo cobrar», no «no había nada que cobrar».
		$resultado = '' === $checkout ? self::COBRO_OK : self::COBRO_OCUPADO;

		$this->with_order_lock(
			$order_id,
			function () use ( $order_id, $checkout, &$resultado ): void {
				$resultado = self::COBRO_OK;

				$order = wc_get_order( $order_id );
				if ( ! $order instanceof \WC_Order ) {
					return;
				}
				$order->read_meta_data( true );

				// La intención se preserva hasta que el carrito se vacía o se paga el pedido.
				if ( '' !== (string) $order->get_meta( self::META_REDEEMED_POINTS ) ) {
					// Ya capturado previamente. Reconciliar si el pedido reutilizado modificó su monto.
					if ( '' !== $checkout && $order->needs_payment() ) {
						$resultado = $this->reconcile_reused( $order );
					}
					return;
				}

				$gross = self::granted_gross( $order );
				if ( $gross <= 0 ) {
					return; // Sin fee nuestro no hay nada que cobrar.
				}

				$user_id = (int) $order->get_customer_id();
				if ( $user_id <= 0 ) {
					return;
				}

				$points = (int) round( $gross / Options::redeem_value() );
				if ( $points <= 0 ) {
					return;
				}

				$debited = Ledger::debit(
					$user_id,
					$points,
					'redemption',
					'order',
					$order_id,
					sprintf( 'Canje en el pedido #%d', $order_id ),
					true,
					sprintf( 'redeem_%d', $order_id )
				);

				if ( null === $debited && '' !== $checkout ) {
					// Infraestructura (candado de usuario/insert) con el pago aún
					// por hacer: se detiene sin cobrar y el cliente reintenta.
					$resultado = self::COBRO_OCUPADO;
					return;
				}

				if ( null === $debited ) {
					// Infraestructura en un reintento. El pedido queda SIN
					// capturar a propósito: el siguiente reintento de la cola
					// vuelve a intentar esta misma captura.
					$order->add_order_note(
						'Puntos Akibara: la captura del canje no pudo ejecutarse (candado ocupado); se reintentará en 5 minutos.'
					);
					self::schedule_retry( $order_id );
					return;
				}

				if ( false === $debited && '' !== $checkout ) {
					$resultado = self::COBRO_SIN_SALDO;
					return; // Se detiene fuera del candado, sobre el pedido de WooCommerce.
				}

				if ( false === $debited ) {
					// Saldo insuficiente: cobrar lo disponible y alarmar.
					$partial = Ledger::debit(
						$user_id,
						$points,
						'redemption_short',
						'order',
						$order_id,
						sprintf( 'Canje con saldo insuficiente en el pedido #%d', $order_id ),
						false,
						sprintf( 'redeem_%d', $order_id )
					);
					$debited   = is_int( $partial ) ? $partial : 0;
					$shortfall = $points - $debited;

					$order->add_order_note(
						sprintf(
							'Puntos Akibara: el canje pedía %d puntos pero el saldo solo cubrió %d (faltaron %d). Revisar con el cliente.',
							$points,
							$debited,
							$shortfall
						)
					);

					/**
					 * Canje capturado con saldo insuficiente. Las alertas del
					 * panel escuchan acá.
					 *
					 * @param \WC_Order $order     Pedido afectado.
					 * @param int       $points    Puntos pedidos.
					 * @param int       $debited   Puntos realmente cobrados.
					 * @param int       $shortfall Puntos no respaldados.
					 */
					do_action( 'akb_rewards_redemption_shortfall', $order, $points, $debited, $shortfall );
				} else {
					$order->add_order_note(
						sprintf( 'Puntos Akibara: canje de %d puntos (descuento de %s).', (int) $debited, wc_price( $gross ) )
					);
				}

				// Un 0 con fee vivo puede corresponder a un asiento ya aplicado cuya
				// meta no se guardó. Recupera el débito del ledger para no duplicarlo.
				if ( 0 === (int) $debited ) {
					$debited = abs( Ledger::sum_movements( 'order', $order_id, array( 'redemption', 'redemption_short' ) ) );
				}

				// REDEEMED_VALUE guarda el descuento CONCEDIDO (el fee que
				// redujo el pedido): la base puntuable debe restar lo que el
				// cliente no pagó con plata, hubiera o no saldo suficiente.
				$order->update_meta_data( self::META_REDEEMED_POINTS, (int) $debited );
				$order->update_meta_data( self::META_REDEEMED_VALUE, (float) $gross );
				$order->save_meta_data();
			}
		);

		return $resultado;
	}

	/**
	 * Concilia los puntos cobrados con el descuento que el pedido lleva AHORA.
	 * Corre bajo el candado del pedido, con las metas releídas.
	 *
	 * @param \WC_Order $order Pedido releído.
	 * @return string COBRO_*: distinto de COBRO_OK, la caja se detiene.
	 */
	private function reconcile_reused( \WC_Order $order ): string {
		$user_id = (int) $order->get_customer_id();
		if ( $user_id <= 0 ) {
			return self::COBRO_OK;
		}

		// La deuda de revival se salda en la caja ANTES de conciliar
		// (settle_debt_at_checkout()). Si sigue viva, conciliar cobraría como
		// diferencia lo que el paso a pagado vuelve a cobrar como deuda.
		if ( (int) $order->get_meta( self::META_REVIVAL_DEBT ) > 0 ) {
			return self::COBRO_OCUPADO;
		}

		$order_id = (int) $order->get_id();
		$gross    = self::granted_gross( $order );
		$pide     = $gross > 0 ? (int) round( $gross / Options::redeem_value() ) : 0;
		$redeemed = (int) $order->get_meta( self::META_REDEEMED_POINTS );
		$cobrado  = max( 0, $redeemed - (int) $order->get_meta( self::META_RESTORED_POINTS ) );

		if ( $pide === $cobrado ) {
			return self::COBRO_OK;
		}

		$nota  = sprintf( 'Ajuste del canje en el pedido #%d', $order_id );
		$clave = sprintf( 'redeem_adjust_%d_%d', $order_id, Ledger::count_movements( 'order', $order_id, 'redemption_adjust' ) + 1 );

		if ( $pide > $cobrado ) {
			$diferencia = $pide - $cobrado;
			$debitado   = Ledger::debit( $user_id, $diferencia, 'redemption_adjust', 'order', $order_id, $nota, true, $clave );
			if ( null === $debitado ) {
				return self::COBRO_OCUPADO; // Sin candado: la caja se detiene y el cliente reintenta.
			}
			if ( ! is_int( $debitado ) ) {
				return self::COBRO_SIN_SALDO;
			}
			$order->update_meta_data( self::META_REDEEMED_POINTS, $redeemed + $diferencia );
			$order->add_order_note( sprintf( 'Puntos Akibara: el canje de este pedido cambió de %d a %d puntos al volver a pagarlo; se cobraron %d más.', $cobrado, $pide, $diferencia ) );
		} else {
			$diferencia = $cobrado - $pide;
			if ( ! is_int( Ledger::credit( $user_id, $diferencia, 'redemption_adjust', 'order', $order_id, $nota, false, $clave ) ) ) {
				// La compra continúa y la devolución pendiente se reintenta; si se
				// agotan los intentos, avisa al dueño.
				$order->add_order_note( sprintf( 'Puntos Akibara: el canje de este pedido bajó de %d a %d puntos y los %d de diferencia no se pudieron devolver todavía; se reintenta solo.', $cobrado, $pide, $diferencia ) );
				// La deuda queda ESCRITA, con la misma clave del intento: es lo que
				// hace que «se reintenta solo» sea verdad (`drain_pending_giveback()`)
				// y que, si el crédito sí llegó a asentarse, no se pague dos veces.
				// META_REDEEMED_POINTS no se toca: sigue diciendo lo cobrado de
				// verdad, para que una anulación reponga todo.
				$order->update_meta_data(
					self::META_PENDING_GIVEBACK,
					(string) wp_json_encode(
						array(
							'points' => $diferencia,
							'key'    => $clave,
						)
					)
				);
				$order->save_meta_data();
				self::schedule_retry( $order_id );
				return self::COBRO_OK;
			}
			$order->update_meta_data( self::META_REDEEMED_POINTS, $redeemed - $diferencia );
			$order->add_order_note( sprintf( 'Puntos Akibara: el canje de este pedido cambió de %d a %d puntos al volver a pagarlo; se devolvieron %d.', $cobrado, $pide, $diferencia ) );
		}

		$order->update_meta_data( self::META_REDEEMED_VALUE, (float) $gross );
		$order->save_meta_data();

		return self::COBRO_OK;
	}

	/**
	 * Transiciones de estado: reponer en anulaciones; en estados vivos,
	 * reintentar capturas pendientes y re-cobrar la deuda de revival.
	 *
	 * @param int       $order_id ID del pedido.
	 * @param string    $from     Estado anterior.
	 * @param string    $to       Estado nuevo.
	 * @param \WC_Order $order    Pedido.
	 * @return void
	 */
	public function on_status_changed( $order_id, $from, $to, $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		// Muerte del pedido: se repone TODO el canje restante.
		if ( OrderStates::is_down( (string) $to ) ) {
			$this->restore( (int) $order_id, true );
			return;
		}

		// Pedido vivo: se reintenta la captura pendiente y se re-cobra la deuda.
		if ( OrderStates::is_alive( (string) $to ) ) {
			// Primero el cobro pendiente de la creación (candado ocupado);
			// después la deuda de revival, si la hay. Ambos con pre-filtro en
			// memoria: la inmensa mayoría de las transiciones de la tienda no
			// deben pagar candado + relectura para descubrir que no hay nada.
			//
			// Salvo cuando el borrador de la caja de bloques pasa a «pendiente»:
			// WooCommerce lo hace justo antes de avisar que el pedido está listo
			// para el pago, y es ESE aviso el que puede detener la compra si el
			// saldo ya no alcanza. Se anota que el pedido salió del borrador en
			// esta petición para poder devolverlo ahí (stop_without_balance()).
			if ( 'checkout-draft' === (string) $from && 'pending' === (string) $to ) {
				self::$left_draft[ (int) $order_id ] = true;
			} elseif ( 'pending' !== (string) $to && '' === (string) $order->get_meta( self::META_REDEEMED_POINTS ) && self::granted_gross( $order ) > 0 ) {
				// Volver a «pendiente» no es pagar: cobrar recortado ahí dejaba
				// pasar la caja con el canje ya dado por cobrado.
				$this->capture_order( $order, '' );
			}
			// La deuda de revival se re-cobra cuando el pedido avanza hacia el
			// pago o el despacho, no al volver a «pendiente»: ahí todavía no se
			// pagó, y re-cobrar recortado dejaba seguir un pago con el descuento
			// sin respaldo. Mientras está pendiente, la caja y «pagar pedido» la
			// re-cobran completa o detienen el pago (settle_debt_at_checkout()).
			// Con fee nuestro se relee bajo candado aunque la copia en memoria no
			// muestre deuda: quien cambia el estado (un webhook) pudo cargar el
			// pedido antes de que otra caja le liberara los puntos.
			if ( 'pending' !== (string) $to && ( (int) $order->get_meta( self::META_REVIVAL_DEBT ) > 0 || self::granted_gross( $order ) > 0 ) ) {
				$this->recapture_after_revival( (int) $order_id );
			}
		}
	}

	/**
	 * Reembolso parcial: reposición proporcional (semántica SPAR) medida
	 * sobre los ÍTEMS.
	 *
	 * @param int $order_id  ID del pedido.
	 * @param int $refund_id ID del reembolso (no usado).
	 * @return void
	 */
	public function on_order_refunded( $order_id, $refund_id ): void {
		unset( $refund_id );
		$this->restore( (int) $order_id, false );
	}

	/**
	 * Repone puntos canjeados. Idempotente por la meta acumulada RESTORED.
	 *
	 * Solo la reposición por MUERTE alimenta la deuda de revival: la
	 * proporcional de un reembolso parcial es definitiva (el pedido sigue
	 * vivo y su próximo avance de fulfillment NO debe robarla).
	 *
	 * @param int  $order_id    ID del pedido.
	 * @param bool $full        True = anulación (todo lo restante); false =
	 *                          evento de reembolso (proporcional por ítems).
	 * @param int  $replaced_by Pedido nuevo de la misma compra que reemplaza a
	 *                          este rechazado (release_superseded()); 0 si no.
	 * @return void
	 */
	private function restore( int $order_id, bool $full, int $replaced_by = 0 ): void {
		$this->with_order_lock(
			$order_id,
			function () use ( $order_id, $full, $replaced_by ): void {
				$order = wc_get_order( $order_id );
				if ( ! $order instanceof \WC_Order ) {
					return;
				}
				$order->read_meta_data( true );

				if ( $replaced_by > 0 && ( ! $order->has_status( 'pending' ) || (int) $order->get_meta( self::META_PAYMENT_REJECTED ) <= 0 ) ) {
					// Se pagó, se anuló o volvió a la pasarela entretanto: nada que
					// liberar. Releído bajo el candado, porque «pagar pedido» en
					// otra pestaña pudo borrar la marca de rechazo.
					return;
				}
				if ( $full && 0 === $replaced_by && ! OrderStates::is_down( (string) $order->get_status() ) ) {
					return; // Revivió entretanto (un reintento que llega tarde): no se devuelve.
				}

				$redeemed = (int) $order->get_meta( self::META_REDEEMED_POINTS );
				if ( $redeemed <= 0 ) {
					return;
				}

				$restored  = (int) $order->get_meta( self::META_RESTORED_POINTS );
				$remaining = max( 0, $redeemed - $restored );
				if ( $remaining <= 0 ) {
					return;
				}

				if ( $full ) {
					$to_restore = $remaining;
				} else {
					// Proporción por ÍTEMS: un reembolso solo de envío no
					// devuelve canje (el descuento sigue íntegro sobre la
					// mercadería).
					// Misma regla de redondeo que la base conservada, o la resta
					// inventa décimas devueltas.
					$items_total = Calculator::items_base( $order );
					if ( $items_total <= 0 ) {
						return;
					}

					$refunded_items = max( 0.0, $items_total - Calculator::conserved_items_base( $order ) );
					$ratio          = max( 0.0, min( 1.0, $refunded_items / $items_total ) );
					$target         = (int) floor( $redeemed * $ratio );
					$to_restore     = max( 0, min( $remaining, $target - $restored ) );
					if ( $to_restore <= 0 ) {
						return;
					}
				}

				$user_id = (int) $order->get_customer_id();
				if ( $user_id <= 0 ) {
					return;
				}

				$restores = Ledger::count_movements( 'order', $order_id, 'redeem_refund' );

				$result = Ledger::credit(
					$user_id,
					$to_restore,
					'redeem_refund',
					'order',
					$order_id,
					sprintf( 'Devolución de puntos canjeados (pedido #%d)', $order_id ),
					false, // No es ganancia nueva: no infla el total histórico.
					sprintf( 'redeem_refund_%d_%d', $order_id, $restores + 1 )
				);

				if ( ! is_int( $result ) ) {
					if ( $replaced_by > 0 ) {
						// Sin liberar, el pedido nuevo no tiene saldo y la caja se
						// detiene: el siguiente intento del cliente vuelve a probar.
						Log::error( sprintf( 'No se pudieron liberar los puntos del pedido #%d para el #%d.', $order_id, $replaced_by ) );
						return;
					}
					// Un pedido anulado no vuelve a cambiar de estado: esperar
					// «la próxima transición» dejaba al cliente sin sus puntos.
					$order->add_order_note(
						'Puntos Akibara: la devolución del canje no pudo ejecutarse (candado ocupado); se reintentará en 5 minutos.'
					);
					self::schedule_retry( $order_id );
					return;
				}

				$order->update_meta_data( self::META_RESTORED_POINTS, $restored + $to_restore );
				if ( $full ) {
					$debt = (int) $order->get_meta( self::META_REVIVAL_DEBT );
					$order->update_meta_data( self::META_REVIVAL_DEBT, $debt + $to_restore );
					// La reposición total ya devolvió TODO lo cobrado, también lo
					// que se debía de un ajuste a la baja: esa deuda queda saldada
					// por aquí, y un reintento que llegue después no puede
					// acreditarla otra vez.
					$order->delete_meta_data( self::META_PENDING_GIVEBACK );
				}
				$order->save_meta_data();
				$order->add_order_note(
					$replaced_by > 0
						? sprintf( 'Puntos Akibara: el pago de este pedido fue rechazado y el cliente armó otra compra (pedido #%d); %d puntos de su canje vuelven a su saldo. Si este pedido se paga igual, se vuelven a cobrar antes del pago.', $replaced_by, $to_restore )
						: sprintf( 'Puntos Akibara: %d puntos del canje devueltos al saldo.', $to_restore )
				);
			}
		);
	}

	/**
	 * Re-cobra la DEUDA DE REVIVAL: solo lo repuesto por muerte del pedido,
	 * nunca lo devuelto por reembolsos parciales (esa parte es del cliente) —
	 * y nunca en pedidos históricos migrados (entran sin deuda).
	 *
	 * RESTORED decrementa únicamente por lo efectivamente cobrado, manteniendo la integridad contable.
	 *
	 * @param int  $order_id ID del pedido.
	 * @param bool $strict   Exigir la deuda completa (en la caja): si no
	 *                       alcanza, o si no se puede cobrar, no se cobra nada.
	 * @return string COBRO_*; fuera del modo estricto, siempre COBRO_OK.
	 */
	private function recapture_after_revival( int $order_id, bool $strict = false ): string {
		// En la caja, un candado de pedido que no se consigue es «no se pudo».
		$resultado = $strict ? self::COBRO_OCUPADO : self::COBRO_OK;

		$this->with_order_lock(
			$order_id,
			function () use ( $order_id, $strict, &$resultado ): void {
				$resultado = self::COBRO_OK;

				$order = wc_get_order( $order_id );
				if ( ! $order instanceof \WC_Order ) {
					return;
				}
				$order->read_meta_data( true );

				$debt = (int) $order->get_meta( self::META_REVIVAL_DEBT );
				if ( $debt <= 0 ) {
					return;
				}

				$user_id = (int) $order->get_customer_id();
				if ( $user_id <= 0 ) {
					return;
				}

				$debited = Ledger::debit(
					$user_id,
					$debt,
					'redemption_recapture',
					'order',
					$order_id,
					sprintf( 'Re-cobro del canje al revivir el pedido #%d', $order_id ),
					$strict
				);

				if ( $strict && false === $debited ) {
					$resultado = self::COBRO_SIN_SALDO;
					return; // Se detiene fuera del candado.
				}

				if ( $strict && ! is_int( $debited ) ) {
					// Nada quedó cobrado y la caja se detiene: el siguiente
					// intento del cliente vuelve a probar.
					$resultado = self::COBRO_OCUPADO;
					return;
				}

				if ( ! is_int( $debited ) ) {
					$order->add_order_note(
						'Puntos Akibara: el re-cobro del canje no pudo ejecutarse (candado ocupado); se reintentará en 5 minutos.'
					);
					self::schedule_retry( $order_id );
					return;
				}

				$restored = (int) $order->get_meta( self::META_RESTORED_POINTS );
				$order->update_meta_data( self::META_RESTORED_POINTS, max( 0, $restored - $debited ) );
				$order->update_meta_data( self::META_REVIVAL_DEBT, 0 );
				if ( $debited < $debt ) {
					// La deuda no recuperada conserva puntos y descuento. UNSETTLED
					// excluye esa porción de la base puntuable del pedido reactivado.
					$unsettled = (int) $order->get_meta( self::META_UNSETTLED );
					$order->update_meta_data( self::META_UNSETTLED, $unsettled + ( $debt - $debited ) );
				}
				$order->save_meta_data();

				if ( $debited < $debt ) {
					$order->add_order_note(
						sprintf(
							'Puntos Akibara: el pedido revivió con canje de %d puntos pero el saldo solo cubrió %d. Revisar con el cliente.',
							$debt,
							$debited
						)
					);
					do_action( 'akb_rewards_redemption_shortfall', $order, $debt, $debited, $debt - $debited );
				} else {
					$order->add_order_note(
						sprintf( 'Puntos Akibara: canje de %d puntos re-cobrado al revivir el pedido.', $debited )
					);
				}
			}
		);

		return $resultado;
	}

	/**
	 * Candado de pedido — MISMO nombre que el de Accrual a propósito: la
	 * acreditación, la reversa y el canje del mismo pedido se serializan
	 * entre sí (GET_LOCK es reentrante dentro de una misma conexión). El
	 * fallo se grita por hook: un cobro de canje saltado no puede quedar mudo.
	 *
	 * @param int      $order_id ID del pedido.
	 * @param callable $fn       Cuerpo a ejecutar.
	 * @return void
	 */
	private function with_order_lock( int $order_id, callable $fn ): void {
		global $wpdb;

		$lock = 'akb_rewards_order_' . $order_id;

		$acquired = (string) $wpdb->get_var(
			$wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, self::LOCK_TIMEOUT )
		);

		if ( '1' !== $acquired ) {
			Log::error( sprintf( 'Candado %s no disponible; operación de canje reagendada.', $lock ) );
			self::schedule_retry( $order_id );
			return;
		}

		try {
			$fn();
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	/**
	 * Reintento agendado de una operación de canje que no pudo tomar su
	 * candado o escribir en el libro.
	 *
	 * Decide por el estado ACTUAL del pedido, no por el que tenía al fallar: si
	 * entretanto se anuló, devuelve; si sigue vivo, captura y re-cobra lo
	 * pendiente. Cada operación es idempotente por sus metas, así que un
	 * reintento de más no mueve nada.
	 *
	 * @param int    $order_id Pedido.
	 * @param string $motivo   RETRY_IMPAGO para la revisión de un pedido que
	 *                         pudo seguir en la pasarela; '' para el resto.
	 * @return void
	 */
	public function run_retry( $order_id, $motivo = '' ): void {
		$order = wc_get_order( (int) $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		self::$in_retry     = true;
		self::$retry_failed = false;
		try {
			$status = (string) $order->get_status();

			if ( OrderStates::is_down( $status ) ) {
				if ( self::RETRY_IMPAGO === (string) $motivo ) {
					// La ventana pudo renovarse: un segundo intento de pago la
					// Respeta la ventana de pago activa para evitar liberar puntos mientras el cliente opera en la pasarela.
					$ventana = (int) $order->get_meta( self::META_PAY_WINDOW );
					if ( $ventana > time() ) {
						self::schedule_retry( (int) $order_id, max( 60, $ventana - time() ), self::RETRY_IMPAGO );
						return;
					}
					$order->delete_meta_data( self::META_PAY_WINDOW );
					$order->save_meta_data();
				} elseif ( 'failed' === $status && (int) $order->get_meta( self::META_PAY_WINDOW ) > time() ) {
					// El cliente puede seguir pagando este pedido en la pasarela; se evalúa al cierre de ventana.
					return;
				}
				$this->restore( (int) $order_id, true );
				return;
			}

			if ( ! OrderStates::is_alive( $status ) ) {
				return;
			}

			// La devolución pendiente se procesa en cualquier estado activo.
			$this->drain_pending_giveback( (int) $order_id );

			// Pedidos no completados aún no cobran en firme; el cobro completo ocurre al pasar a pagado.
			if ( 'pending' !== $status ) {
				if ( '' === (string) $order->get_meta( self::META_REDEEMED_POINTS ) && self::granted_gross( $order ) > 0 ) {
					$this->capture_order( $order, '' );
				}
				if ( (int) $order->get_meta( self::META_REVIVAL_DEBT ) > 0 ) {
					$this->recapture_after_revival( (int) $order_id );
				}
			}
			if ( (float) $order->get_total_refunded() > 0 ) {
				$this->restore( (int) $order_id, false );
			}
		} finally {
			// Una corrida que sale bien devuelve el cupo: el tope es para un
			// problema que no se arregla, no para toda la vida del pedido.
			if ( ! self::$retry_failed && (int) $order->get_meta( self::META_RETRIES ) > 0 ) {
				$order->update_meta_data( self::META_RETRIES, 0 );
				$order->save_meta_data();
			}
			self::$in_retry = false;
		}
	}

	/**
	 * Paga la devolución pendiente de un ajuste a la baja, si la hay.
	 *
	 * Bajo el candado del pedido y releyendo sus metas: la caja, «pagar pedido»
	 * u otro reintento pueden estar tocándolo. El crédito viaja con la clave
	 * guardada, así que si el primer intento sí llegó a asentarse —respuesta
	 * perdida, no crédito perdido— el ledger contesta «ya aplicado» y aquí sólo
	 * se cierra la cuenta. Si vuelve a fallar, `schedule_retry()` gasta un cupo
	 * de verdad: a los MAX_RETRIES suena `akb_rewards_redemption_stuck`.
	 *
	 * @param int $order_id Pedido.
	 * @return void
	 */
	private function drain_pending_giveback( int $order_id ): void {
		$this->with_order_lock(
			$order_id,
			function () use ( $order_id ): void {
				$order = wc_get_order( $order_id );
				if ( ! $order instanceof \WC_Order ) {
					return;
				}
				$order->read_meta_data( true );

				$cruda = (string) $order->get_meta( self::META_PENDING_GIVEBACK );
				if ( '' === $cruda ) {
					return; // Lo normal: no hay nada pendiente.
				}

				$deuda   = json_decode( $cruda, true );
				$puntos  = is_array( $deuda ) ? (int) ( $deuda['points'] ?? 0 ) : 0;
				$clave   = is_array( $deuda ) ? (string) ( $deuda['key'] ?? '' ) : '';
				$user_id = (int) $order->get_customer_id();

				// Una deuda que no se puede leer, o un pedido que ya no tiene
				// cliente, no se va a saldar sola por más que se reintente: callar
				// dejaba una promesa («se devuelve sola») que nadie cumplía. Se
				// retira la meta y el caso queda escrito en el pedido, con la cifra,
				// para que lo resuelva una persona.
				if ( $puntos <= 0 || '' === $clave || $user_id <= 0 ) {
					$order->delete_meta_data( self::META_PENDING_GIVEBACK );
					$order->add_order_note(
						sprintf(
							'Puntos Akibara: había una devolución de puntos pendiente en este pedido y no se pudo completar sola (%s). Revísala a mano en Akibara Puntos → Clientes. Dato guardado: %s',
							$user_id <= 0 ? 'el pedido ya no tiene cliente' : 'el dato guardado no se puede leer',
							$cruda
						)
					);
					$order->save_meta_data();
					return;
				}

				$acreditado = Ledger::credit(
					$user_id,
					$puntos,
					'redemption_adjust',
					'order',
					$order_id,
					sprintf( 'Ajuste del canje en el pedido #%d', $order_id ),
					false,
					$clave
				);
				if ( ! is_int( $acreditado ) ) {
					self::schedule_retry( $order_id );
					return;
				}

				$redeemed = (int) $order->get_meta( self::META_REDEEMED_POINTS );
				$order->delete_meta_data( self::META_PENDING_GIVEBACK );
				$order->update_meta_data( self::META_REDEEMED_POINTS, max( 0, $redeemed - $puntos ) );
				$order->update_meta_data( self::META_REDEEMED_VALUE, (float) self::granted_gross( $order ) );
				$order->add_order_note( sprintf( 'Puntos Akibara: se devolvieron los %d puntos que habían quedado pendientes del ajuste del canje.', $puntos ) );
				$order->save_meta_data();
			}
		);
	}

	/**
	 * Agenda un reintento en 5 minutos, uno a la vez y hasta MAX_RETRIES por
	 * pedido; agotados, deja nota y avisa al dueño.
	 *
	 * @param int    $order_id Pedido.
	 * @param int    $delay    Segundos hasta el reintento.
	 * @param string $motivo   '' o RETRY_IMPAGO. Van en los argumentos de la
	 *                         acción: con los mismos, un reintento de 5 minutos
	 *                         ya en la cola impedía agendar la revisión de la
	 *                         ventana de pago.
	 * @return void
	 */
	private static function schedule_retry( int $order_id, int $delay = 5 * MINUTE_IN_SECONDS, string $motivo = '' ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		$args = '' === $motivo ? array( $order_id ) : array( $order_id, $motivo );

		if ( self::$in_retry ) {
			// Una corrida puede fallar en captura, re-cobro y devolución a la
			// vez: agenda UN reintento y gasta UN cupo.
			if ( self::$retry_failed ) {
				return;
			}
			self::$retry_failed = true;
		} elseif ( false !== as_next_scheduled_action( self::HOOK_RETRY, $args ) ) {
			return; // Ya hay uno en la cola.
		}

		$tries = (int) $order->get_meta( self::META_RETRIES );
		if ( $tries >= self::MAX_RETRIES ) {
			$order->add_order_note(
				sprintf( 'Puntos Akibara: tras %d reintentos no se pudo completar el movimiento de puntos canjeados de este pedido. Revisa el historial de puntos del cliente.', self::MAX_RETRIES )
			);

			/**
			 * Una operación de canje agotó sus reintentos. Las alertas al dueño
			 * escuchan acá.
			 *
			 * @param \WC_Order $order Pedido afectado.
			 */
			do_action( 'akb_rewards_redemption_stuck', $order );

			// Avisado el dueño, un fallo NUEVO y futuro vuelve a tener cupo: el
			// tope es para no insistir sobre el mismo problema, no para dejar el
			// pedido sin reintentos para siempre.
			$order->update_meta_data( self::META_RETRIES, 0 );
			$order->save_meta_data();
			return;
		}

		$order->update_meta_data( self::META_RETRIES, $tries + 1 );
		$order->save_meta_data();
		as_schedule_single_action( time() + $delay, self::HOOK_RETRY, $args );
	}

	/**
	 * Lo que WooCommerce espera a un pedido impago antes de anularlo
	 * (WooCommerce → Ajustes → Productos → Inventario → «Mantener en
	 * inventario»), con un piso de una hora.
	 *
	 * @return int Segundos.
	 */
	private static function unpaid_window(): int {
		return max( 60, (int) get_option( 'woocommerce_hold_stock_minutes', 60 ) ) * MINUTE_IN_SECONDS;
	}

	/**
	 * Detiene la compra de un pedido cuyo descuento el saldo ya no cubre.
	 *
	 * Actúa sobre el MISMO objeto de pedido que maneja WooCommerce, porque tras
	 * la excepción la caja de bloques guarda ese objeto tal como está:
	 *   - Si el pedido salió de borrador en esta petición, retorna a borrador.
	 *   - En pedidos existentes se preserva el estado y se detiene el procesamiento de pago.
	 *
	 * @param \WC_Order $order    Pedido de WooCommerce.
	 * @param string    $checkout Contexto (ver capture_order()).
	 * @param bool      $ocupado  No faltó saldo: otra operación tenía tomados
	 *                            los puntos y el cliente sólo debe reintentar.
	 * @return void
	 * @throws \Exception Salvo en 'order_pay', donde el aviso de error basta.
	 */
	private function stop_without_balance( \WC_Order $order, string $checkout, bool $ocupado = false ): void {
		$borrador = 'store_api' === $checkout && isset( self::$left_draft[ (int) $order->get_id() ] );

		if ( $borrador ) {
			$order->update_status( 'checkout-draft' );
		} else {
			$order->add_order_note(
				$ocupado
					? 'Puntos Akibara: se detuvo un pago porque no se pudo confirmar el canje de puntos (otra operación los tenía tomados); el cliente puede volver a intentarlo.'
					: 'Puntos Akibara: se detuvo un pago porque el saldo del cliente ya no cubre el descuento de puntos de este pedido.'
			);
		}

		if ( $ocupado ) {
			$mensaje = self::MSG_OCUPADO;
		} else {
			$mensaje = $borrador || 'classic' === $checkout ? self::MSG_CAJA : self::MSG_PEDIDO;
		}

		if ( 'order_pay' === $checkout ) {
			wc_add_notice( esc_html( $mensaje ), 'error' );
			return;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- literal fijo de la clase, escapado dentro de balance_changed().
		throw self::balance_changed( $checkout, $mensaje, $borrador, $ocupado ? 'akb_rewards_busy' : 'akb_rewards_balance_changed' );
	}

	/**
	 * El error que ve el cliente cuando su saldo ya no respalda el canje.
	 *
	 * En la caja de bloques viaja como error de la Store API y, si el cliente
	 * vuelve a la caja, con el carrito recalculado: el bloque lo recibe y pinta
	 * el total nuevo sin recargar.
	 *
	 * @param string $checkout    'store_api' o 'classic'.
	 * @param string $mensaje     Aviso para el cliente (literal de la clase).
	 * @param bool   $con_carrito Adjuntar el carrito recalculado.
	 * @param string $codigo      Código del error de la Store API.
	 * @return \Exception
	 */
	private static function balance_changed( string $checkout, string $mensaje, bool $con_carrito, string $codigo ): \Exception {
		$mensaje         = esc_html( $mensaje );
		$route_exception = '\\Automattic\\WooCommerce\\StoreApi\\Exceptions\\RouteException';
		if ( 'store_api' !== $checkout || ! class_exists( $route_exception ) ) {
			return new \Exception( $mensaje );
		}

		return new $route_exception( $codigo, $mensaje, 409, $con_carrito ? self::fresh_cart_response() : array() );
	}

	/**
	 * El carrito recalculado con el saldo real, para la respuesta de error.
	 *
	 * @return array<string,mixed> ['cart' => …], o vacío si no se pudo armar.
	 */
	private static function fresh_cart_response(): array {
		try {
			if ( ! function_exists( 'WC' ) || ! isset( WC()->cart ) ) {
				return array();
			}

			// El débito releyó el saldo bajo candado, pero el carrito lo lee
			// por la caché de usermeta de ESTA petición, que aún tiene el viejo.
			wp_cache_delete( get_current_user_id(), 'user_meta' );
			WC()->cart->calculate_totals();

			$schema = \Automattic\WooCommerce\StoreApi\StoreApi::container()
				->get( \Automattic\WooCommerce\StoreApi\SchemaController::class )
				->get( 'cart' );

			return array( 'cart' => $schema->get_item_response( WC()->cart ) );
		} catch ( \Throwable $e ) {
			Log::error( 'No se pudo recalcular el carrito para el aviso de saldo: ' . $e->getMessage() );
			return array();
		}
	}
}
