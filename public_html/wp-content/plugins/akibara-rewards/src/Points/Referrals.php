<?php
/**
 * Referrals — Sistema de referidos («invita a un amigo»).
 *
 * Flujo:
 *   1. Cada cliente tiene un CÓDIGO estable (usermeta, generado una vez) y
 *     su enlace vive en Mi cuenta → Puntos Akibara.
 *   2. El invitado llega con ?ref=CODIGO → su navegador guarda la cookie
 *     (días configurables). La escribe un script, no PHP: ver enqueue_capture().
 *   3. Cuando el invitado crea cuenta, la cookie se sella en SU usermeta
 *     (la cookie muere, el vínculo queda — resistente a cambios de equipo).
 *   4. Cuando la PRIMERA compra del invitado ACREDITA (el mismo instante del
 *     bono de bienvenida: venta real, no registro), el que invitó cobra N
 *     puntos (configurable).
 *
 * Anti-abuso: no acredita por registrarse; impide
 * auto-referirse (código propio y mismo email se rechazan), UN premio por
 * invitado en la historia (event_key `referral_{invitado}` contra el UNIQUE
 * del ledger) y tope opcional de premios por padrino.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Points;

use Akibara\Rewards\Support\Options;
use Akibara\Rewards\Support\OrderStates;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.2.0
 */
final class Referrals {

	public const META_CODE               = '_akb_rewards_ref_code';
	public const META_REFERRED_BY        = '_akb_rewards_referred_by';
	public const META_REFERRAL_ORDER     = '_akb_rewards_referral_order';
	public const META_REF_CODE           = '_akb_ref_code';
	public const COOKIE                  = 'akb_ref';

	public const AKB_REF_AMOUNT          = 2000;
	public const COUPON_PREFIX           = 'refreferido-';
	public const COUPON_PREFIX_SHORT     = 'ref-';

	/** Usermeta del invitado: motivos de premio no pagado que ya se anotaron. */
	private const META_NOT_PAID_NOTED = '_akb_rewards_referral_not_paid_noted';

	/**
	 * Cuelga captura, sellado y pago.
	 *
	 * @return void
	 */
	public function register(): void {
		// Pago y REVERSA cuelgan SIEMPRE, y el interruptor se consulta DENTRO
		// de maybe_pay_referrer: apagar el carril con premios ya pagados
		// desenganchaba también la reversa y dejaba al padrino con los puntos
		// de una venta anulada, para siempre. El pago apagado es un no-op
		// (reward <= 0); la reversa devuelve lo que este mismo carril pagó.
		//
		// El premio se asocia al estado del pedido pagado y se revierte en caso de anulación.
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_status_changed' ), 30, 4 );
		add_action( 'woocommerce_order_refunded', array( $this, 'on_order_refunded' ), 30, 2 );

		// Cupón virtual bilateral de referido ($2.000 desc. en compra >= $30.000)
		add_filter( 'woocommerce_get_shop_coupon_data', array( $this, 'fabricate_referral_coupon' ), 10, 2 );
		add_filter( 'woocommerce_coupon_is_valid', array( $this, 'validate_referral_coupon' ), 10, 2 );
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate_referral_checkout_antifraud' ), 10, 2 );

		$priority = function_exists( 'get_option' ) ? (int) get_option( 'akb_referral_auto_apply_priority', 1100 ) : 1100;
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'auto_apply_referral_coupon' ), $priority );

		if ( Options::referral_points() <= 0 ) {
			return; // Apagado: no se capta la visita, no se sella, no se promete.
		}

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_capture' ) );
		add_action( 'woocommerce_created_customer', array( $this, 'seal_referred' ), 10, 1 );
		add_action( 'user_register', array( $this, 'seal_referred' ), 10, 1 );
		add_action( 'wp_login', array( $this, 'maybe_seal_on_login' ), 10, 2 );
		add_action( 'woocommerce_checkout_order_created', array( $this, 'seal_order_referral' ), 10, 1 );
		add_action( 'akb_rewards_account_extra', array( $this, 'render_invite_block' ) );
	}

	/**
	 * Código estable del cliente (se genera la primera vez que se pide).
	 *
	 * @param int $user_id Usuario.
	 * @return string
	 */
	public static function code_for( int $user_id ): string {
		$code = (string) get_user_meta( $user_id, self::META_CODE, true );
		if ( '' !== $code ) {
			return $code;
		}

		$code = strtolower( substr( wp_hash( 'akb-ref-' . $user_id . wp_salt( 'nonce' ) ), 0, 10 ) );
		update_user_meta( $user_id, self::META_CODE, $code );

		return $code;
	}

	/**
	 * ¿De quién es este código?
	 *
	 * @param string $code Código.
	 * @return int 0 si no existe.
	 */
	public static function owner_of( string $code ): int {
		if ( '' === $code ) {
			return 0;
		}

		$users = get_users(
			array(
				'meta_key'   => self::META_CODE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $code, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'fields'     => 'ids',
				'number'     => 1,
			)
		);

		return isset( $users[0] ) ? (int) $users[0] : 0;
	}

	/**
	 * Visita con ?ref= -> cookie, escrita en el navegador mediante JavaScript.
	 *
	 * Se ejecuta del lado del cliente para asegurar persistencia efectiva ante
	 * cachés de borde o CDN en la primera visita.
	 *
	 * La cookie no almacena secretos y se valida en el servidor en el momento
	 * de la creación de la cuenta del usuario referido.
	 *
	 * @return void
	 */
	public function enqueue_capture(): void {
		wp_register_script( 'akb-rewards-ref', false, array(), AKIBARA_REWARDS_VERSION, false );
		wp_enqueue_script( 'akb-rewards-ref' );
		wp_add_inline_script( 'akb-rewards-ref', self::capture_script( Options::referral_cookie_days() ) );
	}

	/**
	 * El script de captura: si la dirección trae `ref=` con un código posible,
	 * lo guarda en la cookie que lee el sellado.
	 *
	 * El patrón es el mismo filtro que aplicaba PHP (`sanitize_key` y hasta
	 * 20 caracteres): lo que no podría ser un código no se escribe.
	 *
	 * @param int $days Días que se recuerda la visita.
	 * @return string JavaScript autocontenido.
	 */
	public static function capture_script( int $days ): string {
		$min_cart = (int) get_option( 'akb_referral_min_cart_amount', get_option( 'akb_rewards_referral_min_order', 30000 ) );
		$min_fmt  = number_format_i18n( $min_cart );

		return '(function(){try{'
			. 'var m=/[?&]ref=([a-z0-9_-]{1,20})(?:&|$)/i.exec(window.location.search);'
			. 'if(!m){return;}'
			. 'document.cookie="' . self::COOKIE . '="+m[1].toLowerCase()+"; max-age=' . ( max( 1, $days ) * DAY_IN_SECONDS ) . '; path=/; SameSite=Lax"+("https:"===window.location.protocol?"; Secure":"");'
			. 'function toast(){'
			. 'if(typeof document.createElement!=="function"||!document.body||typeof window.sessionStorage==="undefined"||sessionStorage.getItem("akb_ref_toast")){return;}'
			. 'var t=document.createElement("div");t.className="akb-ref-toast";'
			. 't.innerHTML="<span>\u{1F91D} <strong>\u00A1Bienvenido a Akibara!</strong> Tu amigo te invit\u00F3 con $2.000 de regalo en tu primera compra desde $' . $min_fmt . '.</span> <a href=\"/mi-cuenta/\" style=\"margin-left:8px;color:#ffffff;text-decoration:underline;font-weight:700\">Crear cuenta</a> <button type=\"button\" style=\"background:none;border:none;color:#fff;font-size:16px;cursor:pointer;margin-left:10px;line-height:1\" aria-label=\"Cerrar\">\u00D7</button>";'
			. 't.style.cssText="position:fixed;bottom:20px;right:20px;z-index:999999;background:#181818;border:1px solid #333;border-left:4px solid #E60012;color:#fff;padding:12px 16px;border-radius:0;box-shadow:4px 4px 0 #000;font-size:13px;display:flex;align-items:center;line-height:1.4;max-width:calc(100% - 40px);font-family:inherit;";'
			. 't.querySelector("button").onclick=function(){t.remove();try{sessionStorage.setItem("akb_ref_toast","1");}catch(e){}};'
			. 'document.body.appendChild(t);'
			. '}'
			. 'if(typeof document!=="undefined"){'
			. 'if(document.readyState==="loading"){'
			. 'if(typeof document.addEventListener==="function"){document.addEventListener("DOMContentLoaded",toast);}'
			. '}else{toast();}'
			. '}'
			. '}catch(e){}})();';
	}


	/**
	 * Al crear la cuenta, la cookie se sella en la usermeta del invitado.
	 * La cookie es efímera; el vínculo, permanente.
	 *
	 * @param int $user_id Invitado recién creado.
	 * @return void
	 */
	public function seal_referred( $user_id ): void {
		$user_id = (int) $user_id;

		if ( '' !== (string) get_user_meta( $user_id, self::META_REFERRED_BY, true ) ) {
			return; // Ya sellado (created_customer y user_register pueden co-disparar).
		}

		$code = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_key( (string) wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
		if ( '' === $code ) {
			return;
		}

		$referrer = self::owner_of( $code );
		if ( $referrer <= 0 || $referrer === $user_id ) {
			return; // Código inexistente o auto-referencia.
		}

		$invitado = get_user_by( 'id', $user_id );
		$padrino  = get_user_by( 'id', $referrer );
		if ( $invitado instanceof \WP_User && $padrino instanceof \WP_User
			&& '' !== (string) $invitado->user_email
			&& strtolower( (string) $invitado->user_email ) === strtolower( (string) $padrino->user_email ) ) {
			return; // Mismo email: auto-referencia disfrazada.
		}

		update_user_meta( $user_id, self::META_REFERRED_BY, $referrer );
	}

	/**
	 * Si un usuario que ya tenía cuenta pero NUNCA ha comprado llega con cookie de referido y se loguea,
	 * queda sellado con su padrino para no perder la atribución.
	 *
	 * @param string   $user_login Login.
	 * @param \WP_User $user       Usuario autenticado.
	 * @return void
	 */
	public function maybe_seal_on_login( $user_login, $user ): void {
		unset( $user_login );
		if ( ! $user instanceof \WP_User || (int) $user->ID <= 0 ) {
			return;
		}

		$user_id = (int) $user->ID;
		if ( '' !== (string) get_user_meta( $user_id, self::META_REFERRED_BY, true ) ) {
			return; // Ya sellado anteriormente.
		}

		if ( ! isset( $_COOKIE[ self::COOKIE ] ) ) {
			return; // Sin cookie de referido activa.
		}

		// Si ya compró antes, no es un invitado nuevo
		if ( function_exists( 'wc_get_orders' ) ) {
			$orders = wc_get_orders(
				array(
					'customer_id' => $user_id,
					'limit'       => 1,
					'return'      => 'ids',
				)
			);
			if ( ! empty( $orders ) ) {
				return;
			}
		}

		$this->seal_referred( $user_id );
	}

	/**
	 * Sella el padrino en los metadatos del pedido al momento del checkout si la cookie está activa.
	 *
	 * @param \WC_Order $order Pedido recién creado.
	 * @return void
	 */
	public function seal_order_referral( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$code = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_key( (string) wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
		if ( '' === $code ) {
			return;
		}

		$referrer = self::owner_of( $code );
		if ( $referrer <= 0 ) {
			return;
		}

		$customer_id = (int) $order->get_customer_id();
		if ( $customer_id === $referrer ) {
			return; // Auto-referencia.
		}

		if ( $customer_id > 0 && '' === (string) get_user_meta( $customer_id, self::META_REFERRED_BY, true ) ) {
			$this->seal_referred( $customer_id );
		}

		$order->update_meta_data( self::META_REFERRED_BY, $referrer );
		$order->update_meta_data( self::META_REF_CODE, $code );
		if ( method_exists( $order, 'save' ) ) {
			$order->save();
		}
	}

	/**
	 * Fabrica el cupón virtual de referido ($2.000 CLP, compra mínima $25.000).
	 *
	 * @param array|false $data        Datos de cupón existentes o false.
	 * @param string      $coupon_code Código de cupón ingresado.
	 * @return array|false
	 */
	public function fabricate_referral_coupon( $data, $coupon_code ) {
		if ( ! is_string( $coupon_code ) || '' === trim( $coupon_code ) ) {
			return $data;
		}

		$raw_code = strtolower( trim( $coupon_code ) );
		$ref_code = '';

		if ( 0 === strpos( $raw_code, self::COUPON_PREFIX ) ) {
			$ref_code = substr( $raw_code, strlen( self::COUPON_PREFIX ) );
		} elseif ( 0 === strpos( $raw_code, self::COUPON_PREFIX_SHORT ) ) {
			$ref_code = substr( $raw_code, strlen( self::COUPON_PREFIX_SHORT ) );
		} else {
			$owner = self::owner_of( $raw_code );
			if ( $owner > 0 ) {
				$ref_code = $raw_code;
			}
		}

		if ( '' === $ref_code ) {
			return $data;
		}

		$referrer_id = self::owner_of( $ref_code );
		if ( $referrer_id <= 0 ) {
			return $data;
		}

		$amount    = function_exists( 'get_option' ) ? (float) get_option( 'akb_referral_guest_discount_amount', self::AKB_REF_AMOUNT ) : (float) self::AKB_REF_AMOUNT;
		$min_order = function_exists( 'get_option' ) ? (float) get_option( 'akb_referral_min_cart_amount', 30000 ) : 30000.0;

		return array(
			'id'                         => -1,
			'code'                       => $raw_code,
			'amount'                     => $amount,
			'discount_type'              => 'fixed_cart',
			'description'                => 'Descuento de bienvenida por recomendación de amigo en Akibara',
			'free_shipping'              => false,
			'minimum_amount'             => $min_order,
			'maximum_amount'             => 0,
			'individual_use'             => true,
			'exclude_sale_items'         => false,
			'product_ids'                => array(),
			'exclude_product_ids'        => array(),
			'product_categories'         => array(),
			'exclude_product_categories' => array(),
			'usage_limit'                => 0,
			'usage_limit_per_user'       => 1,
			'limit_usage_to_x_items'     => null,
			'usage_count'                => 0,
			'expiry_date'                => null,
		);
	}

	/**
	 * Valida que el cupón de referido no se combine con puntos, no sea auto-referencia y sea primera compra.
	 *
	 * @param bool       $is_valid Cupón válido hasta ahora.
	 * @param \WC_Coupon $coupon   Cupón.
	 * @return bool
	 * @throws \Exception Si incumple alguna condición.
	 */
	public function validate_referral_coupon( $is_valid, $coupon ): bool {
		if ( ! $is_valid || ! $coupon instanceof \WC_Coupon ) {
			return (bool) $is_valid;
		}

		$raw_code = strtolower( $coupon->get_code() );
		$ref_code = '';
		if ( 0 === strpos( $raw_code, self::COUPON_PREFIX ) ) {
			$ref_code = substr( $raw_code, strlen( self::COUPON_PREFIX ) );
		} elseif ( 0 === strpos( $raw_code, self::COUPON_PREFIX_SHORT ) ) {
			$ref_code = substr( $raw_code, strlen( self::COUPON_PREFIX_SHORT ) );
		} else {
			$owner = self::owner_of( $raw_code );
			if ( $owner > 0 ) {
				$ref_code = $raw_code;
			}
		}

		if ( '' === $ref_code ) {
			return true;
		}

		$referrer_id = self::owner_of( $ref_code );
		if ( $referrer_id <= 0 ) {
			return true;
		}

		// 1. Incompatibilidad con canje de puntos
		if ( Redemption::intent_points() > 0 ) {
			throw new \Exception( 'Puedes elegir entre usar tu cupón de referido o canjear tus Puntos Akibara (no se pueden combinar en un mismo pedido).' );
		}

		$user_id = get_current_user_id();

		// 2. Auto-referencia
		if ( $user_id > 0 && $user_id === $referrer_id ) {
			throw new \Exception( '¡Ese es tu propio código de invitación! No puedes usar tu propio código de referido. Compártelo con tus amigos para que ellos reciban su descuento.' );
		}

		// 3. Solo usuarios sin pedidos previos
		if ( $user_id > 0 && function_exists( 'wc_get_customer_order_count' ) ) {
			if ( wc_get_customer_order_count( $user_id ) > 0 ) {
				throw new \Exception( 'Este cupón de invitación es exclusivo para nuevos lectores en su primera compra.' );
			}
		}

		// 4. Validar suma de líneas elegibles antes de descuento del propio cupón (sin ofertas manuales mayores al 5% de reserva):
		$min_order       = function_exists( 'get_option' ) ? (float) get_option( 'akb_referral_min_cart_amount', 30000 ) : 30000.0;
		$allow_backorder = function_exists( 'get_option' ) ? ( 'yes' === get_option( 'akb_referral_allow_backorder_items', 'yes' ) ) : true;

		if ( function_exists( 'WC' ) && WC()->cart && method_exists( WC()->cart, 'get_cart' ) ) {
			$eligible_sum = self::eligible_cart_subtotal( WC()->cart, $allow_backorder );

			if ( $eligible_sum < $min_order ) {
				$diff = $min_order - $eligible_sum;
				throw new \Exception( sprintf( 'El cupón de invitación requiere un mínimo de $%s CLP en productos elegibles (te faltan $%s CLP).', number_format( $min_order, 0, ',', '.' ), number_format( $diff, 0, ',', '.' ) ) );
			}
		}

		return true;
	}

	/**
	 * Calcula el subtotal elegible del carrito (pre-cupón) para evaluar el piso del cupón de referido.
	 *
	 * Usa `line_subtotal` (+ impuesto) o `precio * cantidad` para que aplicar el propio cupón de $2.000
	 * no reduzca la base por debajo de $30.000 en el segundo pase de `calculate_totals()`.
	 *
	 * @param object $cart            Carrito (WC_Cart).
	 * @param bool   $allow_backorder Si se permiten ítems en reserva (5%).
	 * @return float
	 */
	public static function eligible_cart_subtotal( object $cart, bool $allow_backorder = true ): float {
		if ( ! method_exists( $cart, 'get_cart' ) ) {
			return 0.0;
		}

		$eligible_sum = 0.0;
		foreach ( $cart->get_cart() as $cart_item ) {
			$p = $cart_item['data'] ?? null;
			if ( ! $p instanceof \WC_Product ) {
				continue;
			}

			$qty = max( 1, (int) ( $cart_item['quantity'] ?? 1 ) );
			if ( isset( $cart_item['line_subtotal'] ) ) {
				$line_sub = (float) $cart_item['line_subtotal'] + (float) ( $cart_item['line_subtotal_tax'] ?? 0.0 );
			} elseif ( isset( $cart_item['line_total'] ) ) {
				$line_sub = (float) $cart_item['line_total'] + (float) ( $cart_item['line_tax'] ?? 0.0 );
			} else {
				$line_sub = (float) $p->get_price() * $qty;
			}

			$is_sale      = method_exists( $p, 'is_on_sale' ) && $p->is_on_sale();
			$is_backorder = ( method_exists( $p, 'is_on_backorder' ) && $p->is_on_backorder( 1 ) )
				|| ( method_exists( $p, 'get_stock_status' ) && 'onbackorder' === (string) $p->get_stock_status() )
				|| ( class_exists( '\Akibara\Business\Utilities\StockStatus' ) && \Akibara\Business\Utilities\StockStatus::is_reservable( $p ) );

			if ( ! $is_sale || ( $is_backorder && $allow_backorder ) ) {
				$eligible_sum += $line_sub;
			}
		}

		return $eligible_sum;
	}

	/**
	 * Validación antifraude estricta en el checkout (RUT, teléfono, correo contra el padrino y pedidos previos).
	 *
	 * @param array     $data   Datos del checkout.
	 * @param \WP_Error $errors Objeto de errores.
	 * @return void
	 */
	public function validate_referral_checkout_antifraud( array $data, $errors ): void {
		if ( ! function_exists( 'WC' ) || ! WC()->cart || ! $errors instanceof \WP_Error ) {
			return;
		}

		$has_ref_coupon = false;
		$referrer_id    = 0;
		if ( method_exists( WC()->cart, 'get_applied_coupons' ) ) {
			foreach ( WC()->cart->get_applied_coupons() as $coupon_code ) {
				$raw_code = strtolower( $coupon_code );
				$ref_code = '';
				if ( 0 === strpos( $raw_code, self::COUPON_PREFIX ) ) {
					$ref_code = substr( $raw_code, strlen( self::COUPON_PREFIX ) );
				} elseif ( 0 === strpos( $raw_code, self::COUPON_PREFIX_SHORT ) ) {
					$ref_code = substr( $raw_code, strlen( self::COUPON_PREFIX_SHORT ) );
				} else {
					$owner = self::owner_of( $raw_code );
					if ( $owner > 0 ) {
						$ref_code = $raw_code;
					}
				}
				if ( '' !== $ref_code ) {
					$owner = self::owner_of( $ref_code );
					if ( $owner > 0 ) {
						$has_ref_coupon = true;
						$referrer_id    = $owner;
						break;
					}
				}
			}
		}

		if ( ! $has_ref_coupon || $referrer_id <= 0 ) {
			return;
		}

		$padrino = get_user_by( 'id', $referrer_id );
		if ( ! $padrino instanceof \WP_User ) {
			return;
		}

		$email = strtolower( trim( (string) ( $data['billing_email'] ?? '' ) ) );
		$phone = preg_replace( '/\D+/', '', (string) ( $data['billing_phone'] ?? '' ) );
		$rut   = preg_replace( '/[^0-9kK]/', '', (string) ( $data['billing_rut'] ?? ( $data['_billing_rut'] ?? '' ) ) );

		// Comprobar cruce con el padrino
		$padrino_email = strtolower( trim( (string) $padrino->user_email ) );
		$padrino_phone = preg_replace( '/\D+/', '', (string) get_user_meta( $referrer_id, 'billing_phone', true ) );
		$padrino_rut   = preg_replace( '/[^0-9kK]/', '', (string) ( get_user_meta( $referrer_id, 'billing_rut', true ) ?: get_user_meta( $referrer_id, '_billing_rut', true ) ) );

		if ( '' !== $email && $email === $padrino_email ) {
			$errors->add( 'akb_ref_self_email', 'El cupón de invitación no se puede aplicar con el correo de quien invita. ¡Compártelo con un amigo!' );
			return;
		}
		if ( '' !== $phone && '' !== $padrino_phone && $phone === $padrino_phone ) {
			$errors->add( 'akb_ref_self_phone', 'El cupón de invitación no se puede aplicar con el teléfono de quien invita. ¡Compártelo con un amigo!' );
			return;
		}
		if ( '' !== $rut && '' !== $padrino_rut && strtolower( $rut ) === strtolower( $padrino_rut ) ) {
			$errors->add( 'akb_ref_self_rut', 'El cupón de invitación no se puede aplicar con el RUT de quien invita. ¡Compártelo con un amigo!' );
			return;
		}

		// Comprobar si esta persona ya registra compras previas en la base de datos (por email, teléfono o RUT)
		if ( function_exists( 'wc_get_orders' ) ) {
			if ( '' !== $email ) {
				$orders = wc_get_orders( array( 'billing_email' => $email, 'limit' => 1, 'return' => 'ids' ) );
				if ( ! empty( $orders ) ) {
					$errors->add( 'akb_ref_prior_purchase', 'Este cupón de invitación es exclusivo para nuevos lectores en su primera compra.' );
					return;
				}
			}
			if ( '' !== $phone && strlen( $phone ) >= 8 ) {
				$orders = wc_get_orders( array( 'billing_phone' => (string) ( $data['billing_phone'] ?? '' ), 'limit' => 1, 'return' => 'ids' ) );
				if ( ! empty( $orders ) ) {
					$errors->add( 'akb_ref_prior_purchase_phone', 'Este cupón de invitación es exclusivo para nuevos lectores en su primera compra.' );
					return;
				}
			}
			if ( '' !== $rut ) {
				$raw_rut = trim( (string) ( $data['billing_rut'] ?? ( $data['_billing_rut'] ?? '' ) ) );
				$orders  = wc_get_orders(
					array(
						'meta_key'   => '_billing_rut',
						'meta_value' => $raw_rut,
						'limit'      => 1,
						'return'     => 'ids',
					)
				);
				if ( ! empty( $orders ) ) {
					$errors->add( 'akb_ref_prior_purchase_rut', 'Este cupón de invitación es exclusivo para nuevos lectores en su primera compra.' );
					return;
				}
			}
		}
	}

	/**
	 * Auto-aplica el cupón de referido si el cliente tiene la cookie akb_ref (o un cupón de referido desplazado temporalmente por un pack),
	 * cumple el mínimo en productos elegibles y no tiene otros cupones ni canje.
	 *
	 * @param \WC_Cart $cart Carrito.
	 * @return void
	 */
	public function auto_apply_referral_coupon( $cart ): void {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}
		if ( ! $cart instanceof \WC_Cart ) {
			return;
		}

		if ( class_exists( '\Akibara\Business\Modules\SeriesBundles\BundleCouponEngine' ) && \Akibara\Business\Modules\SeriesBundles\BundleCouponEngine::is_running() ) {
			return;
		}

		$superseded = ( function_exists( 'WC' ) && WC()->session ) ? (string) WC()->session->get( 'akb_sb_superseded_ref_coupon', '' ) : '';
		$cookie_val = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_key( (string) wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';

		if ( '' === $cookie_val && '' === $superseded ) {
			return;
		}

		$code = $cookie_val;
		if ( '' === $code && '' !== $superseded ) {
			$raw = strtolower( $superseded );
			if ( 0 === strpos( $raw, self::COUPON_PREFIX ) ) {
				$code = substr( $raw, strlen( self::COUPON_PREFIX ) );
			} elseif ( 0 === strpos( $raw, self::COUPON_PREFIX_SHORT ) ) {
				$code = substr( $raw, strlen( self::COUPON_PREFIX_SHORT ) );
			} else {
				$code = $raw;
			}
		}

		if ( '' === $code ) {
			return;
		}

		$referrer_id = self::owner_of( $code );
		if ( $referrer_id <= 0 ) {
			return;
		}

		$user_id = get_current_user_id();
		if ( $user_id > 0 && $user_id === $referrer_id ) {
			return;
		}

		if ( $user_id > 0 && function_exists( 'wc_get_customer_order_count' ) && wc_get_customer_order_count( $user_id ) > 0 ) {
			return;
		}

		if ( method_exists( $cart, 'get_applied_coupons' ) && ! empty( $cart->get_applied_coupons() ) ) {
			return;
		}

		if ( Redemption::intent_points() > 0 ) {
			return;
		}

		$coupon_code = '' !== $superseded ? $superseded : ( self::COUPON_PREFIX . $code );
		if ( method_exists( $cart, 'has_discount' ) && ( $cart->has_discount( $coupon_code ) || $cart->has_discount( self::COUPON_PREFIX_SHORT . $code ) ) ) {
			return;
		}

		$min_order       = function_exists( 'get_option' ) ? (float) get_option( 'akb_referral_min_cart_amount', 30000 ) : 30000.0;
		$allow_backorder = function_exists( 'get_option' ) ? ( 'yes' === get_option( 'akb_referral_allow_backorder_items', 'yes' ) ) : true;
		$eligible_sum    = self::eligible_cart_subtotal( $cart, $allow_backorder );

		if ( $eligible_sum >= $min_order && method_exists( $cart, 'apply_coupon' ) ) {
			if ( '' !== $superseded && function_exists( 'WC' ) && WC()->session ) {
				WC()->session->set( 'akb_sb_superseded_ref_coupon', null );
			}
			$cart->apply_coupon( $coupon_code );
		}
	}

	/**
	 * Transición de estado de un pedido: pagar al padrino o revertirle.
	 *
	 * @param int       $order_id ID del pedido.
	 * @param string    $from     Estado anterior (sin wc-).
	 * @param string    $to       Estado nuevo (sin wc-).
	 * @param \WC_Order $order    Pedido.
	 * @return void
	 */
	public function on_status_changed( $order_id, $from, $to, $order ): void {
		unset( $order_id, $from );

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		// Aplica al estado configurado o a cualquier estado pagado posterior.
		if ( OrderStates::is_at_or_after( (string) $to, Options::referral_status() ) ) {
			$this->maybe_pay_referrer( $order, (int) $order->get_customer_id() );
			return;
		}

		if ( OrderStates::is_down( (string) $to ) ) {
			$this->maybe_reverse_referrer( $order, (int) $order->get_customer_id() );
		}
	}

	/**
	 * Un reembolso que devuelve TODO el dinero anula la compra que pagó el
	 * premio: se revierte igual que una cancelación. Uno parcial no toca el
	 * premio, como tampoco toca el bono de bienvenida.
	 *
	 * @param int $order_id  ID del pedido.
	 * @param int $refund_id ID del reembolso (no usado).
	 * @return void
	 */
	public function on_order_refunded( $order_id, $refund_id ): void {
		unset( $refund_id );

		$order = wc_get_order( (int) $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		if ( (float) $order->get_total() - (float) $order->get_total_refunded() > 0.01 ) {
			return;
		}

		$this->maybe_reverse_referrer( $order, (int) $order->get_customer_id() );
	}

	/**
	 * La primera compra del invitado paga al padrino cuando entra al estado configurado.
	 *
	 * Aplica una sola vez por persona: si el RUT, teléfono o correo del invitado
	 * coinciden con compras previas o con los del padrino, no se acredita.
	 *
	 * @param \WC_Order $order   Pedido del invitado.
	 * @param int       $user_id Invitado.
	 * @return void
	 */
	public function maybe_pay_referrer( $order, $user_id ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$user_id  = (int) $user_id;
		$referrer = $user_id > 0 ? (int) get_user_meta( $user_id, self::META_REFERRED_BY, true ) : 0;
		if ( $referrer <= 0 ) {
			return;
		}

		$reward = Options::referral_points();
		if ( $reward <= 0 ) {
			return; // Carril apagado (o monto en 0): nada que pagar.
		}

		// Premio VIVO: este invitado ya pagó uno y no se ha revertido (o la
		// reversa quedó corta y la marca se conservó a propósito). Sin esta
		// puerta, la clave por ocurrencia de más abajo pagaría otro premio
		// sobre la misma relación.
		if ( (int) get_user_meta( $user_id, self::META_REFERRAL_ORDER, true ) > 0 ) {
			return;
		}

		$motivo = self::reason_not_to_pay( $order, $referrer );
		if ( null !== $motivo ) {
			self::note_not_paid( $order, $referrer, $motivo[0], $motivo[1] );
			return;
		}

		$max          = Options::referral_max_per_user();
		$moved_before = Ledger::count_movements( 'user', $user_id, 'referral' );

		// Clave por OCURRENCIA: si el pedido que pagó el premio se cayó y la
		// reversa lo retiró, la SIGUIENTE compra acreditada del invitado
		// vuelve a ser una «primera compra real» y re-paga (mismo patrón que
		// award_{order}_{n} en Accrual).
		$reversals = Ledger::count_movements( 'user', $user_id, 'referral_reversal' );
		$event_key = $reversals > 0
			? sprintf( 'referral_%d_r%d', $user_id, $reversals )
			: sprintf( 'referral_%d', $user_id );

		// El tope se cuenta BAJO EL CANDADO del padrino, junto al crédito: dos
		// invitados suyos completando a la vez leían los dos «9 de 10» y pagaban
		// los dos (en producción el tope vale 10). Cuenta los premios VIGENTES:
		// uno que se le descontó porque el invitado anuló la compra no ocupa cupo.
		$result = null;
		$topado = false;
		Ledger::with_user_lock(
			$referrer,
			static function () use ( $referrer, $reward, $user_id, $order, $event_key, $max, &$result, &$topado ): void {
				if ( $max > 0 && Ledger::count_live_referrals( $referrer ) >= $max ) {
					$topado = true;
					return; // El padrino alcanzó su tope de premios por invitar.
				}

				$result = Ledger::credit(
					$referrer,
					$reward,
					'referral',
					'user',
					$user_id,
					sprintf( 'Invitado que compró (pedido #%d)', (int) $order->get_id() ),
					true,
					$event_key
				);
			}
		);

		if ( $topado ) {
			self::note_not_paid( $order, $referrer, 'tope', sprintf( 'ya tiene %d premios por invitar, el tope del programa', $max ) );
			return;
		}

		if ( ! is_int( $result ) ) {
			/**
			 * El premio no se pudo acreditar (candado ocupado o fallo del libro).
			 * Un pedido Completado casi nunca vuelve a cambiar de estado: sin
			 * este aviso el premio se perdía en silencio. Las alertas escuchan acá.
			 *
			 * @param \WC_Order $order    Pedido del invitado.
			 * @param int       $referrer Padrino.
			 * @param int       $reward   Puntos que debía cobrar.
			 */
			do_action( 'akb_rewards_referral_pay_failed', $order, $referrer, $reward );
			return;
		}

		if ( Ledger::count_movements( 'user', $user_id, 'referral' ) <= $moved_before ) {
			return; // Duplicado (revival/re-disparo): sin movimiento no hay hook.
		}

		// Vinculación del pedido que originó el beneficio para trazabilidad y eventuales reversas.
		update_user_meta( $user_id, self::META_REFERRAL_ORDER, (int) $order->get_id() );
		$order->add_order_note(
			sprintf( 'Puntos Akibara: se pagaron %d Puntos Akibara a quien invitó a este cliente (cuenta #%d).', $reward, $referrer )
		);

		/**
		 * Referido pagado. El correo al padrino escucha acá.
		 *
		 * @param int       $referrer Padrino premiado.
		 * @param int       $user_id  Invitado que compró.
		 * @param int       $reward   Puntos pagados.
		 * @param \WC_Order $order    Pedido que justificó el premio.
		 */
		do_action( 'akb_rewards_referral_paid', $referrer, $user_id, $reward, $order );
	}

	/**
	 * Por qué esta compra no paga el premio al padrino, en palabras del dueño.
	 *
	 * @param \WC_Order $order    Pedido del invitado.
	 * @param int       $referrer Padrino.
	 * @return array{0:string,1:string}|null [clave, motivo], o null si nada lo impide.
	 */
	private static function reason_not_to_pay( \WC_Order $order, int $referrer ): ?array {
		if ( ! Accrual::in_program( $order ) ) {
			return array( 'programa', 'el pedido es anterior al inicio del programa de puntos' );
		}

		if ( ! FirstPurchase::meets_minimum( $order ) ) {
			$minimo = Options::first_purchase_min();
			return array(
				'minimo',
				$minimo > 0
					? sprintf( 'la compra no llega al mínimo de $%s en productos', number_format_i18n( $minimo ) )
					: 'el pedido no tuvo pago con dinero en productos',
			);
		}

		if ( FirstPurchase::same_person( $order, $referrer ) ) {
			return array( 'misma_persona', 'el cliente comparte RUT, teléfono o correo con quien lo invitó' );
		}

		if ( FirstPurchase::has_prior_purchase( $order ) ) {
			return array( 'no_primera', 'no es la primera compra de esta persona: su RUT, teléfono o correo ya aparece en una compra anterior' );
		}

		return null;
	}

	/**
	 * Registra nota explicativa en el pedido ante rechazo de acreditación,
	 * deduplicando por motivo e invitado para evitar redundancia en compras posteriores.
	 *
	 * @param \WC_Order $order    Pedido del invitado.
	 * @param int       $referrer Padrino.
	 * @param string    $clave    Motivo, como clave estable.
	 * @param string    $motivo   Motivo legible, en minúscula y sin punto final.
	 * @return void
	 */
	private static function note_not_paid( \WC_Order $order, int $referrer, string $clave, string $motivo ): void {
		$invitado = (int) $order->get_customer_id();
		$anotados = get_user_meta( $invitado, self::META_NOT_PAID_NOTED, true );
		$anotados = is_array( $anotados ) ? $anotados : array();
		if ( in_array( $clave, $anotados, true ) ) {
			return;
		}

		$order->add_order_note(
			sprintf( 'Puntos Akibara: no se pagó el premio a quien invitó a este cliente (cuenta #%d) porque %s.', $referrer, $motivo )
		);
		$anotados[] = $clave;
		update_user_meta( $invitado, self::META_NOT_PAID_NOTED, $anotados );
	}

	/**
	 * Si el pedido que PAGÓ el premio de referido se anula por completo, el
	 * premio del padrino se revierte (débito clampado) y el invitado vuelve a
	 * ser elegible: venta real o nada, también hacia atrás.
	 *
	 * @param \WC_Order $order   Pedido anulado del invitado.
	 * @param int       $user_id Invitado.
	 * @return void
	 */
	public function maybe_reverse_referrer( $order, $user_id ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$user_id = (int) $user_id;
		if ( (int) get_user_meta( $user_id, self::META_REFERRAL_ORDER, true ) !== (int) $order->get_id() ) {
			return; // Este pedido no fue el que pagó el premio.
		}

		$referrer = (int) get_user_meta( $user_id, self::META_REFERRED_BY, true );
		if ( $referrer <= 0 ) {
			return;
		}

		// Neto vivo del premio (créditos − reversas anteriores): nunca se
		// revierte dos veces ni más de lo pagado.
		$net = (int) Ledger::sum_movements( 'user', $user_id, array( 'referral', 'referral_reversal' ) );
		if ( $net <= 0 ) {
			return;
		}

		$reversals = Ledger::count_movements( 'user', $user_id, 'referral_reversal' );
		$result    = Ledger::debit(
			$referrer,
			$net,
			'referral_reversal',
			'user',
			$user_id,
			sprintf( 'Reversa de referido: el pedido #%d del invitado se anuló', (int) $order->get_id() ),
			false,
			sprintf( 'referral_reversal_%d_%d', $user_id, $reversals )
		);

		if ( ! is_int( $result ) ) {
			return; // Infraestructura: la próxima transición del pedido reintenta.
		}

		if ( $result < $net ) {
			// Débito CLAMPADO: el padrino ya se gastó los puntos y sólo se
			// recuperó parte. Devolver la elegibilidad aquí pagaría el premio
			// otra vez con una clave de ocurrencia nueva, y la tienda acabaría
			// regalando más de lo que recuperó por una venta que no existió
			// (mismo cierre que WelcomeBonus::maybe_restore_eligibility).
			$order->add_order_note(
				sprintf(
					'Puntos Akibara: el premio al padrino (%d) no se recuperó completo en la reversa (sólo %d); el invitado NO vuelve a ser elegible.',
					$net,
					$result
				)
			);

			/**
			 * Una reversa de referido quedó corta. Las alertas del panel escuchan acá.
			 *
			 * @param \WC_Order $order    Pedido anulado del invitado.
			 * @param int       $faltante Puntos que no se pudieron recuperar.
			 */
			do_action( 'akb_rewards_reversal_failed', $order, $net - $result );
			return;
		}

		// El invitado queda elegible otra vez: su próxima compra acreditada
		// vuelve a pagar (clave de ocurrencia nueva).
		delete_user_meta( $user_id, self::META_REFERRAL_ORDER );
	}

	/**
	 * Bloque «Invita a un amigo» en el endpoint de Mi cuenta.
	 *
	 * @param int $user_id Usuario dueño de la pantalla.
	 * @return void
	 */
	public function render_invite_block( $user_id ): void {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return;
		}

		// Fuera de vigencia la compra del invitado no paga al padrino
		// (`reason_not_to_pay()`): la invitación no se ofrece hasta que rija.
		$premio = Promise::referral_points();
		if ( $premio <= 0 ) {
			return;
		}

		$code    = self::code_for( $user_id );
		$enlace  = add_query_arg( 'ref', $code, home_url( '/' ) );
		$pagados = Ledger::count_live_referrals( $user_id );

		$min_cart   = (int) get_option( 'akb_rewards_referral_min_order', 30000 );
		$min_fmt    = number_format_i18n( $min_cart );
		$premio_fmt = number_format_i18n( $premio );
		$premio_clp = number_format_i18n( $premio * Options::redeem_value() );

		$wa_text = sprintf(
			'¡Hola! Te comparto mi invitación a Akibara Manga. Tienes $2.000 de regalo en tu primera compra desde $%s en mangas y cómics con este enlace: %s',
			$min_fmt,
			$enlace
		);
		$wa_url = 'https://api.whatsapp.com/send?text=' . rawurlencode( $wa_text );

		printf(
			'<div class="akb-rw-invite-card" style="margin:28px 0;padding:20px;border:1px solid #333;border-radius:4px;background:#181818;color:#f0f0f0">
				<h3 style="margin:0 0 8px;font-size:18px;color:#fff">Invita a tus amigos lectores 🤝</h3>
				<p style="margin:0 0 12px;font-size:14px;line-height:1.5;color:#ccc">Comparte tu enlace: tu amigo recibe <strong>$2.000 de regalo</strong> en su primera compra (desde $%s en mangas a precio normal o reserva) y tú ganas <strong>%s Puntos Akibara ($%s CLP)</strong> cuando reciba su pedido%s. Válido para amigos nuevos (no aplica para la misma persona, RUT, teléfono o correo).</p>
				<div class="akb-rw-ref-box" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:12px">
					<input type="text" class="akb-rw-ref-input" readonly value="%s" style="flex:1 1 280px;min-height:40px;font-family:monospace;font-size:13px;background:#101010;color:#fff;padding:0 12px;border:1px solid #444;border-radius:3px" aria-label="Tu enlace de referido" onfocus="this.select()">
					<button type="button" class="button button-primary akb-rw-copy-btn" data-akb-copy-url="%s" style="background:var(--aki-buy-bg,#CC0010);border-color:var(--aki-buy-bg,#CC0010);color:#fff;min-height:40px;display:inline-flex;align-items:center;gap:6px;font-weight:700">📋 Copiar enlace</button>
					<a class="button button-primary akb-rw-wa-btn" style="background:#075E54;border-color:#128C7E;color:#FFFFFF;min-height:40px;display:inline-flex;align-items:center;gap:6px;font-weight:700;text-decoration:none" href="%s" target="_blank" rel="noopener noreferrer">💬 Compartir por WhatsApp</a>
					<button type="button" class="button button-secondary akb-rw-share-btn" data-akb-share-url="%s" data-akb-share-title="Akibara Manga" data-akb-share-text="%s" style="display:none;min-height:40px">🔗 Más opciones...</button>
				</div>
				<p style="margin:0;font-size:12px;color:#888"><code style="user-select:all;display:none">%s</code>Tu código de invitación: <strong style="color:#fff">%s</strong>%s</p>
			</div>',
			esc_html( $min_fmt ),
			esc_html( $premio_fmt ),
			esc_html( $premio_clp ),
			$pagados > 0 ? esc_html( sprintf( ' (ya llevas %d)', $pagados ) ) : '',
			esc_attr( $enlace ),
			esc_attr( $enlace ),
			esc_url( $wa_url ),
			esc_attr( $enlace ),
			esc_attr( $wa_text ),
			esc_url( $enlace ),
			esc_html( $code ),
			$pagados > 0 ? sprintf( ' · <strong style="color:#fff">%d</strong> amigos ya compraron con tu enlace', (int) $pagados ) : ''
		);

	}
}
