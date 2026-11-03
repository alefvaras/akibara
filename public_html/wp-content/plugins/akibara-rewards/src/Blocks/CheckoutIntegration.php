<?php
/**
 * CheckoutIntegration — integración de caja de canje y fidelización en el checkout de bloques.
 *
 * Tres piezas del contrato de Woo Blocks, sin build ni framework (JS plano
 * con los globals de wc-blocks — mismo patrón que akibara-cart-checkout-
 * blocks, regla de UI liviana):
 *
 *  1. endpoint_data en CartSchema (namespace `akibara-rewards`): saldo, tope
 *     por carrito, mínimo, intención vigente y los datos del gancho de
 *     invitado — TODO desde Options, nada hardcodeado, carriles apagados no
 *     prometen.
 *  2. update callback: recibe {points} de extensionCartUpdate, aplica los
 *     MISMOS clamps que Mi cuenta (mínimo configurable, saldo) y fija la
 *     intención en sesión; el fee existente de Redemption hace los totales.
 *  3. Encolado del JS en el checkout de bloques con las dependencias que
 *     exponen wc.blocksCheckout / wp.plugins / wp.element.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Blocks;

use Akibara\Rewards\Points\Accrual;
use Akibara\Rewards\Points\Calculator;
use Akibara\Rewards\Points\Redemption;
use Akibara\Rewards\Support\Assets;
use Akibara\Rewards\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.3.0
 */
final class CheckoutIntegration {

	public const IDENTIFIER = 'akibara-rewards';

	/**
	 * Cuelga los datos del Store API y el JS de los bloques Carrito y Checkout.
	 *
	 * El JS se registra con `scope: 'woocommerce-checkout'`, montado por los bloques
	 * de Carrito y Checkout para renderizar los slots de canje de puntos y avisos.
	 *
	 * @return void
	 */
	public function register(): void {
		// woocommerce_blocks_loaded dispara en plugins_loaded y puede ganarle
		// a nuestro boot: si ya pasó, registrar DIRECTO (mismo timing seguro
		// que el plugin hermano de bloques).
		if ( did_action( 'woocommerce_blocks_loaded' ) ) {
			$this->register_store_api();
		} else {
			add_action( 'woocommerce_blocks_loaded', array( $this, 'register_store_api' ) );
		}
		add_action( 'woocommerce_blocks_enqueue_checkout_block_scripts_after', array( $this, 'enqueue_assets' ) );
		add_action( 'woocommerce_blocks_enqueue_cart_block_scripts_after', array( $this, 'enqueue_assets' ) );
		add_action( 'woocommerce_before_checkout_form', array( $this, 'render_classic_referral_notice' ), 5 );
	}

	/**
	 * Datos y callback del Store API.
	 *
	 * @return void
	 */
	public function register_store_api(): void {
		if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' )
			|| ! function_exists( 'woocommerce_store_api_register_update_callback' ) ) {
			return;
		}

		woocommerce_store_api_register_endpoint_data(
			array(
				'endpoint'        => \Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema::IDENTIFIER,
				'namespace'       => self::IDENTIFIER,
				'data_callback'   => array( $this, 'cart_data' ),
				'schema_callback' => array( $this, 'cart_schema' ),
				'schema_type'     => ARRAY_A,
			)
		);

		woocommerce_store_api_register_update_callback(
			array(
				'namespace' => self::IDENTIFIER,
				'callback'  => array( $this, 'handle_update' ),
			)
		);
	}

	/**
	 * Lo que el checkout necesita saber, desde la config viva.
	 *
	 * @return array<string,mixed>
	 */
	public function cart_data(): array {
		if ( function_exists( 'wp_prime_option_caches' ) ) {
			// Optimización de lecturas en Store API: precarga options requeridas en una sola consulta.
			wp_prime_option_caches(
				array(
					'akb_rewards_redeem_min_points',
					'akb_rewards_redeem_max_pct',
					'akb_rewards_redeem_max_amount',
					'akb_rewards_redeem_value',
					'akb_rewards_earn_enabled',
					'akb_rewards_earn_points',
					'akb_rewards_earn_amount',
					'akb_rewards_welcome_enabled',
					'akb_rewards_welcome_bonus',
					'akb_rewards_welcome_teaser_enabled',
					'akb_rewards_redeem_min_cart',
					'akb_rewards_first_purchase_min',
					'akb_rewards_program_start',
				)
			);
		}

		$user_id = get_current_user_id();
		$cart    = self::cart();
		// Store API ya calculó el carrito. Compartir estos límites evita otra
		// interpretación de mínimos, saldo y reservas en el bloque de compra.
		$limits  = Redemption::redemption_limits( $cart, $user_id );
		$balance = $limits['available'];
		$intent  = Redemption::intent_points();

		// Y para los PUNTOS hay que restar lo que ya se está pagando con puntos,
		// que es lo que hace `award_base_for_order()` al acreditar. Ojo: el canje
		// entra como FEE, no como línea, así que la base de arriba NO baja sola
		// al aplicarlo —recalcular el carrito no cambia esa base— y sin esta
		// resta el checkout prometería puntos sobre dinero que se paga con
		// puntos. Hoy no se ve (el aviso es para invitados y canjear exige
		// cuenta), pero la promesa viajaba inflada en el payload.
		$canje_bruto = self::cart_redeemed_gross();

		// ¿El programa rige HOY? Es la compuerta que aplica la acreditación
		// (`Accrual::in_program()`): con la fecha de inicio en el futuro —o
		// malformada, que es un campo de texto del panel— no acredita nadie, y
		// el aviso del checkout no puede prometer lo contrario.
		$vigente = Accrual::program_open_now();

		$ref_name = '';
		$code     = isset( $_COOKIE[ \Akibara\Rewards\Points\Referrals::COOKIE ] )
			? sanitize_key( (string) wp_unslash( $_COOKIE[ \Akibara\Rewards\Points\Referrals::COOKIE ] ) )
			: '';
		if ( '' !== $code ) {
			$ref_id = \Akibara\Rewards\Points\Referrals::owner_of( $code );
			if ( $ref_id > 0 && $ref_id !== $user_id ) {
				$padrino = get_user_by( 'id', $ref_id );
				if ( $padrino instanceof \WP_User ) {
					$fname    = trim( (string) $padrino->first_name );
					$ref_name = '' !== $fname ? $fname : (string) $padrino->display_name;
				}
			}
		}

		return array(
			'logged_in'     => $user_id > 0,
			'balance'       => $balance,
			'intent'        => $intent,
			'effective'     => Redemption::effective_points_for_limits( $intent, $limits ),
			'unavailable'   => Redemption::unavailable_message( $limits ),
			'min'           => $limits['min_points'],
			// Carrito mínimo para canjear y cuánto le falta a ESTE carrito: la
			// caja no puede calcularlo en el navegador sin duplicar la regla.
			'min_cart'      => Options::redeem_min_cart(),
			'missing'       => $limits['missing'],
			'first_min'     => Options::first_purchase_min(),
			// Tope del canje YA resuelto en puntos para ESTE carrito: la caja
			// no puede recalcularlo en el navegador sin duplicar la regla (y
			// el servidor vuelve a topar en `Redemption::effective_points()`,
			// así que esto es UX, no seguridad). 0 = no se puede canjear.
			'max'           => $limits['max_points'],
			// La regla general («acumulas 2 puntos por cada $100») es la línea de
			// reserva del aviso cuando todavía no hay carrito. Calla con el
			// programa fuera de vigencia por la misma razón que `earn_now`: ahí
			// no acredita nadie, y prometerlo sería mentir.
			'earn_points'   => $vigente ? Options::earn_points() : 0,
			'earn_amount'   => $vigente ? Options::earn_amount() : 0,
			// El bono también calla fuera de vigencia: cuelga del mismo abono que
			// `Accrual::award()` corta, y aquí se promete justo para ESTE pedido.
			'welcome_bonus' => $vigente ? Options::welcome_bonus() : 0,
			// Lo que ESTE carrito acumula, con la MISMA función que después
			// acredita: `points_for()` sobre `award_base_for_cart()`, al que se le
			// pasa el canje para que reste igual que `award_base_for_order()`. El
			// número no se recalcula en el navegador a propósito —una fórmula
			// paralela se desincroniza el día que el dueño cambie el tramo en el
			// panel, y el checkout prometería puntos que luego no se abonan—.
			// Cero sin carrito, y cero también si el programa todavía no rige o su
			// fecha de inicio quedó malformada: ahí no acredita nadie.
			'earn_now'      => $vigente ? Calculator::points_for( self::cart_award_base( $canje_bruto ) ) : 0,
			// Pesos por punto, para poder decir «500 puntos = $500» sin que la
			// caja se invente el cambio. Es el mismo valor con que se canjean.
			'redeem_value'  => Options::redeem_value(),
			// Dónde se crea la cuenta. En /carrito/ el gancho promete puntos y no
			// tenía NADA que pulsar: la casilla «Crear una cuenta» sólo existe en
			// el checkout. Sólo viaja para invitados —el registrado nunca lo lee—
			// y sólo si de verdad se puede crear una cuenta ahí.
			'account_url'   => $user_id > 0 ? '' : self::account_url(),
			'teaser'        => 'yes' === (string) get_option( 'akb_rewards_welcome_teaser_enabled', 'yes' ),
			'referrer_name' => $ref_name,
			'switch_points' => ! empty( $limits['can_switch_from_bundle'] ) ? (int) ( $limits['max_redeemable_points'] ?? 0 ) : 0,
		);
	}

	/**
	 * Base puntuable del carrito vivo, con el canje ya restado.
	 *
	 * Delega el cálculo de base puntuable a `Calculator::award_base_for_cart()`.
	 *
	 * @param float $canje_bruto Pesos que este carrito paga con puntos.
	 * @return float
	 */
	private static function cart_award_base( float $canje_bruto ): float {
		$cart = self::cart();

		return $cart instanceof \WC_Cart ? Calculator::award_base_for_cart( $cart, $canje_bruto ) : 0.0;
	}

	/**
	 * Página donde el invitado puede CREAR su cuenta, o '' si no la hay.
	 *
	 * `wc_get_page_permalink()` cae a la portada cuando la página de Mi cuenta
	 * no existe, así que preguntar sólo por la URL nunca da vacío y el enlace
	 * del carrito llevaría al home. Y con el registro apagado en WooCommerce la
	 * página existe pero sólo deja entrar: prometer «Crear mi cuenta» ahí es
	 * mandar al cliente a una puerta cerrada.
	 *
	 * @return string
	 */
	private static function account_url(): string {
		if ( ! function_exists( 'wc_get_page_id' ) || ! function_exists( 'wc_get_page_permalink' ) ) {
			return '';
		}

		if ( wc_get_page_id( 'myaccount' ) <= 0
			|| 'yes' !== (string) get_option( 'woocommerce_enable_myaccount_registration', 'no' ) ) {
			return '';
		}

		return (string) wc_get_page_permalink( 'myaccount' );
	}

	/**
	 * El carrito vivo, o null si todavía no hay (WooCommerce a medio arrancar).
	 *
	 * @return \WC_Cart|null
	 */
	private static function cart(): ?\WC_Cart {
		if ( ! function_exists( 'WC' ) ) {
			return null;
		}

		$wc   = WC();
		$cart = is_object( $wc ) ? ( $wc->cart ?? null ) : null;

		return $cart instanceof \WC_Cart ? $cart : null;
	}

	/**
	 * Lo que este carrito ya está pagando con puntos, en pesos.
	 *
	 * Es la resta que `award_base_for_order()` aplica al acreditar: los puntos no
	 * se ganan sobre dinero pagado con puntos.
	 *
	 * @return float 0.0 si no hay carrito o no hay canje aplicado.
	 */
	private static function cart_redeemed_gross(): float {
		$cart = self::cart();
		if ( ! $cart instanceof \WC_Cart ) {
			return 0.0;
		}

		// Espejo exacto de `Redemption::granted_gross()`, que hace lo mismo sobre
		// el pedido: el fee del canje es el único negativo con ese nombre.
		foreach ( $cart->get_fees() as $fee ) {
			if ( ! is_object( $fee ) || Redemption::FEE_NAME !== ( $fee->name ?? '' ) ) {
				continue;
			}

			// Sólo `total` + `tax`: `amount` es el importe SIN IVA y restaría un
			// 19 % de menos, prometiendo puntos de más.
			$total = (float) ( $fee->total ?? 0 ) + (float) ( $fee->tax ?? 0 );
			if ( $total < 0 ) {
				return -$total;
			}
		}

		return 0.0;
	}


	/**
	 * Schema del bloque de datos.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function cart_schema(): array {
		$entero = array( 'type' => 'integer', 'readonly' => true );
		return array(
			'logged_in'     => array( 'type' => 'boolean', 'readonly' => true ),
			'balance'       => $entero,
			'intent'        => $entero,
			'effective'     => $entero,
			'unavailable'   => array( 'type' => 'string', 'readonly' => true ),
			'min'           => $entero,
			'max'           => $entero,
			'earn_points'   => $entero,
			'earn_amount'   => $entero,
			'welcome_bonus' => $entero,
			'earn_now'      => $entero,
			'redeem_value'  => $entero,
			'min_cart'      => $entero,
			'missing'       => $entero,
			'first_min'     => $entero,
			'account_url'   => array( 'type' => 'string', 'readonly' => true ),
			'teaser'        => array( 'type' => 'boolean', 'readonly' => true ),
			'referrer_name' => array( 'type' => 'string', 'readonly' => true ),
			'switch_points' => $entero,
		);
	}

	/**
	 * Aviso discreto para checkout clásico si el cliente llega con cookie de referido.
	 *
	 * @return void
	 */
	public function render_classic_referral_notice(): void {
		$code = isset( $_COOKIE[ \Akibara\Rewards\Points\Referrals::COOKIE ] )
			? sanitize_key( (string) wp_unslash( $_COOKIE[ \Akibara\Rewards\Points\Referrals::COOKIE ] ) )
			: '';
		if ( '' === $code ) {
			return;
		}

		$user_id = get_current_user_id();
		$ref_id  = \Akibara\Rewards\Points\Referrals::owner_of( $code );
		if ( $ref_id <= 0 || $ref_id === $user_id ) {
			return;
		}

		$padrino = get_user_by( 'id', $ref_id );
		if ( ! $padrino instanceof \WP_User ) {
			return;
		}

		$fname = trim( (string) $padrino->first_name );
		$name  = '' !== $fname ? $fname : (string) $padrino->display_name;

		printf(
			'<div class="akb-rw-referral-checkout-notice" style="border:1px solid #333333;border-left:4px solid #E60012;background:#181818;color:#f0f0f0;padding:12px 16px;margin:12px 0;font-size:14px;line-height:1.4;">'
			. '🤝 Estás comprando con la recomendación de <strong>%s</strong>. ¡Ambos recibirán Puntos Akibara al completar tu pedido!'
			. '</div>',
			esc_html( $name )
		);
	}

	/**
	 * extensionCartUpdate({points}): misma validación explícita que Mi cuenta.
	 *
	 * @param array<string,mixed> $data Datos del cliente.
	 * @return void
	 */
	public function handle_update( array $data ): void {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return; // El canje exige cuenta; un invitado no fija intención.
		}

		$points = filter_var( $data['points'] ?? null, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 0 ) ) );
		if ( false === $points ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'akb_rewards_invalid_points', 'Ingresa un número entero de puntos.', 400 );
		}
		if ( 0 === $points ) {
			( new Redemption() )->clear_intent();
			$cart = self::cart();
			if ( null !== $cart ) {
				$cart->calculate_totals();
			}
			return;
		}
		$cart = self::cart();
		if ( null !== $cart ) {
			$cart->calculate_totals();
		}
		$limits            = Redemption::redemption_limits( $cart, $user_id );
		$switched_from_byp = false;
		$allow_manual_switch = function_exists( 'get_option' ) ? ( 'yes' === get_option( 'akb_rewards_allow_manual_switch_below_bundle', 'yes' ) ) : true;

		if ( $points > 0 && ( ! empty( $limits['points_exceed_bundle'] ) || $allow_manual_switch ) && $cart instanceof \WC_Cart && method_exists( $cart, 'remove_coupon' ) ) {
			$switched_from_byp = true;
			if ( function_exists( 'WC' ) && WC()->session ) {
				WC()->session->set( 'akb_prefer_points_over_bundle', true );
			}
			foreach ( $cart->get_applied_coupons() as $coupon_code ) {
				if ( 0 === strpos( (string) $coupon_code, 'akb_sb_' ) ) {
					$cart->remove_coupon( $coupon_code );
				}
			}
			$cart->calculate_totals();
			$limits = Redemption::redemption_limits( $cart, $user_id );
		}
		$error = Redemption::requested_error( $points, $limits );
		if ( '' !== $error ) {
			if ( $switched_from_byp ) {
				if ( function_exists( 'WC' ) && WC()->session ) {
					WC()->session->set( 'akb_prefer_points_over_bundle', false );
				}
				if ( null !== $cart ) {
					$cart->calculate_totals();
				}
			}
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'akb_rewards_unavailable', $error, 400 );
		}
		Redemption::set_intent( $points );
	}

	/**
	 * JS plano de la caja (sin build): mismas dependencias que expone el
	 * plugin hermano de bloques.
	 *
	 * @return void
	 */
	public function enqueue_assets(): void {
		$rel = 'assets/js/checkout-blocks.js';

		wp_enqueue_script(
			'akb-rewards-checkout-blocks',
			Assets::url( $rel ),
			array( 'wc-blocks-checkout', 'wc-blocks-data-store', 'wc-settings', 'wp-data', 'wp-element', 'wp-plugins' ),
			Assets::version( $rel ),
			true
		);
	}
}
