<?php
/**
 * FreeShippingThreshold — resolución segura del progreso de envío gratis.
 *
 * La barra no puede derivar su promesa del menor umbral global: WooCommerce
 * decide la disponibilidad por paquete, zona, requisito (`requires`) y política
 * de descuentos (`ignore_discounts`). Este servicio replica esa semántica para
 * calcular el progreso y usa los métodos habilitados de la zona que realmente
 * corresponde a cada paquete del carrito.
 *
 * La barra usa `for_current_cart()`, que nunca mezcla zonas.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Services;

defined( 'ABSPATH' ) || exit;

final class FreeShippingThreshold {

	public const STATE_UNAVAILABLE = 'unavailable';
	public const STATE_PROGRESS    = 'progress';
	public const STATE_QUALIFIED   = 'qualified';

	/**
	 * Métodos que entregan en un punto/mostrador, no en el domicilio del cliente.
	 *
	 * El umbral del transportista (ver `for_current_cart()`) sólo describe la
	 * entrega a domicilio: en Akibara el retiro en Punto Blue se cotiza aparte y
	 * no queda gratis por monto. Una zona cuyos únicos métodos habilitados sean
	 * de retiro no puede sostener la promesa y no recibe el fallback.
	 *
	 * @since 1.97.0
	 */
	private const PICKUP_METHOD_IDS = array( 'local_pickup', 'pickup_location', 'bluex-pudo' );

	/**
	 * Resultados ya resueltos en ESTA petición, indexados por firma de entradas.
	 *
	 * @var array<string, array{state:string,reason:string,threshold:float,total:float,remaining:float}>
	 */
	private static array $request_cache = array();

	/**
	 * Estado seguro del envío gratis para el carrito y sus zonas actuales.
	 *
	 * El override visual nunca puede rebajar el requisito real de WooCommerce:
	 * si está configurado, actúa como piso conservador (`max(real, override)`).
	 * No inventa envío gratis cuando la zona no ofrece el método.
	 *
	 * `$carrier_threshold` cubre el caso en que el envío gratis NO lo otorga una
	 * instancia core `free_shipping` sino el propio transportista dentro de su
	 * tarifa (Blue Express devuelve `isShipmentFree` sobre el monto pactado, y
	 * por eso las instancias `free_shipping` de las zonas están deshabilitadas a
	 * propósito: habilitarlas publicaría una tarifa paralela sin servicio Blue
	 * asociado). Sin este parámetro la barra se quedaba permanentemente en
	 * `unavailable` y el cliente nunca veía cuánto le faltaba. Se aplica sólo
	 * como fallback —una instancia core habilitada siempre manda— y sólo en
	 * zonas con al menos un método de entrega a domicilio habilitado.
	 *
	 * @param float $override          Piso visual del operador.
	 * @param float $carrier_threshold Umbral que el transportista aplica en su tarifa; 0 = sin fallback.
	 *
	 * @return array{
	 *   state: string,
	 *   reason: string,
	 *   threshold: float,
	 *   total: float,
	 *   remaining: float
	 * }
	 */
	public static function for_current_cart( float $override = 0.0, float $carrier_threshold = 0.0 ): array {
		if ( ! function_exists( 'WC' ) || ! class_exists( 'WC_Shipping_Zones' ) ) {
			return self::unavailable_state();
		}

		$woocommerce = WC();
		$cart        = is_object( $woocommerce ) ? ( $woocommerce->cart ?? null ) : null;

		if ( ! is_object( $cart ) || ! method_exists( $cart, 'get_shipping_packages' ) ) {
			return self::unavailable_state();
		}

		/*
             * Memoiza por petición la resolución de paquetes, zonas y métodos.
             * La clave incluye contenido del carrito, cupones, destino, subtotal,
             * descuentos y parámetros del cálculo. Si cambia una entrada se vuelve
             * a resolver. Sin una clave fiable, como en un doble sin get_cart_hash(),
             * se omite el memo y se ejecuta el cálculo completo.
             */
		$cache_key = self::request_cache_key( $woocommerce, $cart, $override, $carrier_threshold );
		if ( '' !== $cache_key && array_key_exists( $cache_key, self::$request_cache ) ) {
			return self::$request_cache[ $cache_key ];
		}

		$state = self::resolve_for_cart( $cart, $override, $carrier_threshold );

		if ( '' !== $cache_key ) {
			// Cota para procesos largos (WP-CLI recorriendo carritos): una
			// petición web genera 1 o 2 claves, así que rebasar esto significa
			// que no estamos en una petición. Vaciar y seguir es preferible a
			// crecer sin límite; lo peor que pasa es recalcular.
			if ( count( self::$request_cache ) >= 32 ) {
				self::$request_cache = array();
			}
			self::$request_cache[ $cache_key ] = $state;
		}

		return $state;
	}

	/**
	 * Vacía la memoización por petición.
	 *
	 * Existe para las pruebas: un estático que sobrevive entre tests es una
	 * fuente clásica de verde dependiente del orden.
	 */
	public static function reset_request_cache(): void {
		self::$request_cache = array();
	}

	/**
	 * Firma de las entradas del cálculo dentro de la petición.
	 *
	 * Devuelve '' cuando falta cualquier pieza que haga la clave confiable; el
	 * llamador interpreta '' como «no memoizar».
	 *
	 * @param object $woocommerce Instancia de WooCommerce (para el cliente).
	 * @param object $cart        WC_Cart-like.
	 */
	private static function request_cache_key(
		object $woocommerce,
		object $cart,
		float $override,
		float $carrier_threshold
	): string {
		if ( ! method_exists( $cart, 'get_cart_hash' ) ) {
			return '';
		}

		$cart_hash = (string) $cart->get_cart_hash();
		if ( '' === $cart_hash ) {
			return '';
		}

		// El hash de Woo cubre contenido y total, pero NO el destino ni los
		// cupones sin descuento (un cupón de sólo envío gratis deja el total
		// igual). Ambos cambian el resultado, así que entran en la clave.
		$destination = array();
		$customer    = $woocommerce->customer ?? null;
		if ( is_object( $customer ) ) {
			foreach ( array( 'get_shipping_country', 'get_shipping_state', 'get_shipping_postcode', 'get_shipping_city' ) as $getter ) {
				$destination[] = method_exists( $customer, $getter ) ? (string) $customer->{$getter}() : '';
			}
		}

		$coupons = method_exists( $cart, 'get_applied_coupons' ) ? (array) $cart->get_applied_coupons() : array();
		sort( $coupons );

		$totals = array(
			method_exists( $cart, 'get_displayed_subtotal' ) ? (float) $cart->get_displayed_subtotal() : 0.0,
			method_exists( $cart, 'get_discount_total' ) ? (float) $cart->get_discount_total() : 0.0,
			method_exists( $cart, 'get_discount_tax' ) ? (float) $cart->get_discount_tax() : 0.0,
			method_exists( $cart, 'display_prices_including_tax' ) && (bool) $cart->display_prices_including_tax(),
		);

		$ignore_bundle = function_exists( 'get_option' ) && 'yes' === (string) get_option( 'akb_fsp_ignore_bundle_discounts', 'no' );

		return md5(
			(string) wp_json_encode(
				array(
					$cart_hash,
					$destination,
					array_map( 'strval', $coupons ),
					$totals,
					$override,
					$carrier_threshold,
					$ignore_bundle,
				)
			)
		);
	}

	/**
	 * Resolución real: paquetes, zonas y métodos. Sin memoización.
	 *
	 * @param object $cart WC_Cart-like.
	 * @return array{
	 *   state: string,
	 *   reason: string,
	 *   threshold: float,
	 *   total: float,
	 *   remaining: float
	 * }
	 */
	private static function resolve_for_cart( object $cart, float $override, float $carrier_threshold ): array {
		$unavailable = self::unavailable_state();

		$packages = $cart->get_shipping_packages();
		if ( ! is_array( $packages ) || empty( $packages ) ) {
			return $unavailable;
		}

		$package_methods = array();
		foreach ( $packages as $package ) {
			if ( ! is_array( $package ) || ! self::has_resolvable_destination( $package ) ) {
				return $unavailable;
			}

			try {
				$zone = \WC_Shipping_Zones::get_zone_matching_package( $package );
			} catch ( \Throwable $error ) {
				return $unavailable;
			}

			if ( ! is_object( $zone ) || ! method_exists( $zone, 'get_shipping_methods' ) ) {
				return $unavailable;
			}

			$methods = array();
			foreach ( (array) $zone->get_shipping_methods( true ) as $method ) {
				if ( ! is_object( $method ) || 'free_shipping' !== (string) ( $method->id ?? '' ) ) {
					continue;
				}

				$is_available = null;
				if ( method_exists( $method, 'is_available' ) ) {
					try {
						$is_available = (bool) $method->is_available( $package );
					} catch ( \Throwable $error ) {
						// Falla cerrada: no convertir un método defectuoso en promesa.
						continue;
					}
				}

				$methods[] = array(
					'requires'          => (string) ( $method->requires ?? '' ),
					'min_amount'        => (float) ( $method->min_amount ?? 0 ),
					'ignore_discounts'  => (string) ( $method->ignore_discounts ?? 'no' ),
					'current_available' => $is_available,
				);
			}

			// Sin instancia core habilitada, el único camino real a envío gratis
			// es el del transportista. Se sintetiza sólo si el operador lo declaró
			// y la zona entrega a domicilio.
			if ( empty( $methods ) ) {
				$methods = self::carrier_fallback_methods( $zone, $carrier_threshold );
			}

			// Si cualquier paquete carece de un camino real a envío gratis, una
			// barra única para todo el pedido sería una promesa falsa.
			if ( empty( $methods ) ) {
				return $unavailable;
			}
			$package_methods[] = $methods;
		}

		$subtotal = method_exists( $cart, 'get_displayed_subtotal' )
			? (float) $cart->get_displayed_subtotal()
			: 0.0;
		$discount_total = method_exists( $cart, 'get_discount_total' )
			? (float) $cart->get_discount_total()
			: 0.0;
		$discount_tax = method_exists( $cart, 'get_discount_tax' )
			? (float) $cart->get_discount_tax()
			: 0.0;

		if ( function_exists( 'get_option' ) && 'yes' === (string) get_option( 'akb_fsp_ignore_bundle_discounts', 'no' ) && method_exists( $cart, 'get_applied_coupons' ) ) {
			foreach ( (array) $cart->get_applied_coupons() as $applied_code ) {
				if ( 0 === strpos( (string) $applied_code, 'akb_sb_' ) ) {
					if ( method_exists( $cart, 'get_coupon_discount_amount' ) ) {
						$discount_total = max( 0.0, $discount_total - (float) $cart->get_coupon_discount_amount( $applied_code ) );
					}
					if ( method_exists( $cart, 'get_coupon_discount_tax_amount' ) ) {
						$discount_tax = max( 0.0, $discount_tax - (float) $cart->get_coupon_discount_tax_amount( $applied_code ) );
					}
				}
			}
		}
		$prices_include_tax = method_exists( $cart, 'display_prices_including_tax' )
			&& (bool) $cart->display_prices_including_tax();
		$decimals = function_exists( 'wc_get_price_decimals' )
			? max( 0, (int) wc_get_price_decimals() )
			: 0;

		return self::evaluate_packages(
			$package_methods,
			$subtotal,
			$discount_total,
			$discount_tax,
			$prices_include_tax,
			self::has_valid_free_shipping_coupon( $cart ),
			$override,
			$decimals
		);
	}

	/**
	 * Núcleo puro y testeable de la semántica de WooCommerce.
	 *
	 * Cada elemento de `$package_methods` representa un paquete y contiene sus
	 * instancias habilitadas de `free_shipping` en la zona resuelta.
	 *
	 * @param array<int,array<int,array{
	 *   requires?: string,
	 *   min_amount?: float|int|string,
	 *   ignore_discounts?: string,
	 *   current_available?: bool|null
	 * }>> $package_methods
	 * @return array{
	 *   state: string,
	 *   reason: string,
	 *   threshold: float,
	 *   total: float,
	 *   remaining: float
	 * }
	 */
	public static function evaluate_packages(
		array $package_methods,
		float $subtotal,
		float $discount_total,
		float $discount_tax,
		bool $prices_include_tax,
		bool $has_free_shipping_coupon,
		float $override = 0.0,
		int $decimals = 0
	): array {
		if ( empty( $package_methods ) ) {
			return self::unavailable_state();
		}

		$package_states = array();
		foreach ( $package_methods as $methods ) {
			if ( ! is_array( $methods ) || empty( $methods ) ) {
				return self::unavailable_state();
			}

			$qualified = null;
			$progress  = array();

			foreach ( $methods as $method ) {
				if ( ! is_array( $method ) ) {
					continue;
				}

				$state = self::evaluate_method(
					$method,
					$subtotal,
					$discount_total,
					$discount_tax,
					$prices_include_tax,
					$has_free_shipping_coupon,
					$override,
					$decimals
				);

				if ( self::STATE_QUALIFIED === $state['state'] ) {
					$qualified = $state;
					break;
				}
				if ( self::STATE_PROGRESS === $state['state'] ) {
					$progress[] = $state;
				}
			}

			if ( null !== $qualified ) {
				$package_states[] = $qualified;
				continue;
			}

			if ( empty( $progress ) ) {
				return self::unavailable_state();
			}

			// Dentro de una zona basta el camino alcanzable más cercano.
			usort(
				$progress,
				static fn( array $a, array $b ): int => $a['remaining'] <=> $b['remaining']
			);
			$package_states[] = $progress[0];
		}

		$pending = array_values(
			array_filter(
				$package_states,
				static fn( array $state ): bool => self::STATE_PROGRESS === $state['state']
			)
		);

		if ( empty( $pending ) ) {
			$reasons    = array_column( $package_states, 'reason' );
			$reason     = in_array( 'order_amount', $reasons, true ) ? 'order_amount' : 'coupon';
			$thresholds = array_map(
				'floatval',
				array_column( $package_states, 'threshold' )
			);

			return array(
				'state'     => self::STATE_QUALIFIED,
				'reason'    => $reason,
				// Conservar el mayor umbral realmente satisfecho mantiene correcto
				// `{free_shipping_amount}` también en el estado calificado.
				'threshold' => empty( $thresholds ) ? 0.0 : max( $thresholds ),
				'total'     => 0.0,
				'remaining' => 0.0,
			);
		}

		// Con varios paquetes, el cuello de botella (mayor restante) es el único
		// progreso que garantiza envío gratis para el pedido completo.
		usort(
			$pending,
			static fn( array $a, array $b ): int => $b['remaining'] <=> $a['remaining']
		);

		return $pending[0];
	}

	/**
	 * @param array{
	 *   requires?: string,
	 *   min_amount?: float|int|string,
	 *   ignore_discounts?: string,
	 *   current_available?: bool|null
	 * } $method
	 * @return array{
	 *   state: string,
	 *   reason: string,
	 *   threshold: float,
	 *   total: float,
	 *   remaining: float
	 * }
	 */
	private static function evaluate_method(
		array $method,
		float $subtotal,
		float $discount_total,
		float $discount_tax,
		bool $prices_include_tax,
		bool $has_free_shipping_coupon,
		float $override,
		int $decimals
	): array {
		$requires = (string) ( $method['requires'] ?? '' );
		if ( ! in_array( $requires, array( '', 'coupon', 'min_amount', 'either', 'both' ), true ) ) {
			return self::unavailable_state();
		}

		$actual_available = array_key_exists( 'current_available', $method )
			&& is_bool( $method['current_available'] )
			? $method['current_available']
			: null;

		$needs_coupon = in_array( $requires, array( 'coupon', 'either', 'both' ), true );
		$needs_amount = in_array( $requires, array( 'min_amount', 'either', 'both' ), true );

		$real_threshold = $needs_amount
			? max( 0.0, (float) ( $method['min_amount'] ?? 0 ) )
			: 0.0;

		// La disponibilidad del propio método (incluidos filtros de terceros) es
		// la autoridad final cuando ya está disponible.
		if ( true === $actual_available ) {
			return array(
				'state'     => self::STATE_QUALIFIED,
				'reason'    => $needs_coupon && $has_free_shipping_coupon ? 'coupon' : 'order_amount',
				'threshold' => $real_threshold,
				'total'     => 0.0,
				'remaining' => 0.0,
			);
		}

		if ( ! $needs_amount ) {
			// Llegar acá deja sólo DOS configuraciones posibles: sin requisito
			// (califica siempre) o «con cupón» (califica si el carrito lo trae).
			// El resto de `requires` implica monto, y sale por el camino de abajo.
			$core_qualified = '' === $requires || $has_free_shipping_coupon;

			if ( $core_qualified && false !== $actual_available ) {
				return array(
					'state'     => self::STATE_QUALIFIED,
					'reason'    => $needs_coupon ? 'coupon' : 'order_amount',
					'threshold' => 0.0,
					'total'     => 0.0,
					'remaining' => 0.0,
				);
			}
			return self::unavailable_state();
		}

		// `both`: gastar más nunca basta sin el cupón requerido.
		if ( 'both' === $requires && ! $has_free_shipping_coupon ) {
			return self::unavailable_state();
		}

		// Un override inferior nunca rebaja el requisito real de WooCommerce.
		$threshold = max( $real_threshold, max( 0.0, $override ) );
		$total     = $subtotal;

		if ( 'yes' !== (string) ( $method['ignore_discounts'] ?? 'no' ) ) {
			$total -= $discount_total;
			if ( $prices_include_tax ) {
				$total -= $discount_tax;
			}
		}

		$total = round( max( 0.0, $total ), max( 0, $decimals ) );

		$amount_met = $total >= $real_threshold;
		$core_qualified = match ( $requires ) {
			'min_amount' => $amount_met,
			'either'     => $amount_met || $has_free_shipping_coupon,
			'both'       => $amount_met && $has_free_shipping_coupon,
			default      => false,
		};

		if ( $core_qualified && false !== $actual_available ) {
			return array(
				'state'     => self::STATE_QUALIFIED,
				'reason'    => $has_free_shipping_coupon && in_array( $requires, array( 'either', 'both' ), true )
					? 'coupon'
					: 'order_amount',
				'threshold' => $real_threshold,
				'total'     => $total,
				'remaining' => 0.0,
			);
		}

		// Si el núcleo de Woo ya califica pero un filtro externo vetó el método,
		// no mostrar progreso: añadir dinero no quitará ese veto.
		if ( $core_qualified && false === $actual_available ) {
			return self::unavailable_state();
		}

		// Un requisito de monto 0 ya estaría calificado en WooCommerce. Llegar
		// aquí significa que un filtro externo lo vetó o la config es incoherente.
		if ( $threshold <= 0 ) {
			return self::unavailable_state();
		}

		return array(
			'state'     => self::STATE_PROGRESS,
			'reason'    => 'order_amount',
			'threshold' => $threshold,
			'total'     => $total,
			'remaining' => max( 0.0, $threshold - $total ),
		);
	}

	/**
	 * Traduce el umbral del transportista a un método evaluable por el núcleo.
	 *
	 * Se apoya en la misma semántica `min_amount` de WooCommerce para que
	 * progreso, calificado, descuentos y override se resuelvan por el camino ya
	 * probado, sin una segunda rama de reglas. `current_available` queda en null
	 * (desconocido): la tarifa real la cotiza el transportista en el checkout, y
	 * afirmar disponibilidad aquí sería inventar autoridad que no tenemos.
	 *
	 * @since 1.97.0
	 *
	 * @param object $zone              WC_Shipping_Zone-like ya resuelta para el paquete.
	 * @param float  $carrier_threshold Umbral declarado por el operador.
	 * @return array<int,array{requires:string,min_amount:float,ignore_discounts:string,current_available:bool|null}>
	 */
	private static function carrier_fallback_methods( object $zone, float $carrier_threshold ): array {
		if ( ! method_exists( $zone, 'get_shipping_methods' ) ) {
			return array();
		}

		$enabled_ids = array();
		foreach ( (array) $zone->get_shipping_methods( true ) as $method ) {
			if ( is_object( $method ) ) {
				$enabled_ids[] = (string) ( $method->id ?? '' );
			}
		}

		return self::carrier_fallback_for_enabled_methods( $enabled_ids, $carrier_threshold );
	}

	/**
	 * Núcleo puro del fallback del transportista.
	 *
	 * @since 1.97.0
	 *
	 * @param array<int,string> $enabled_method_ids Ids de los métodos habilitados en la zona.
	 * @param float             $carrier_threshold  Umbral declarado por el operador.
	 * @return array<int,array{requires:string,min_amount:float,ignore_discounts:string,current_available:bool|null}>
	 */
	public static function carrier_fallback_for_enabled_methods(
		array $enabled_method_ids,
		float $carrier_threshold
	): array {
		if ( $carrier_threshold <= 0 ) {
			return array();
		}

		$delivers_to_address = false;
		foreach ( $enabled_method_ids as $method_id ) {
			if ( '' !== $method_id && ! in_array( $method_id, self::PICKUP_METHOD_IDS, true ) ) {
				$delivers_to_address = true;
				break;
			}
		}

		if ( ! $delivers_to_address ) {
			return array();
		}

		return array(
			array(
				'requires'          => 'min_amount',
				'min_amount'        => $carrier_threshold,
				'ignore_discounts'  => 'no',
				'current_available' => null,
			),
		);
	}

	/**
	 * Un país vacío hace que Woo pueda caer en la zona por defecto. Para una
	 * promesa comercial preferimos ocultar la barra hasta tener al menos país.
	 *
	 * @param array<string,mixed> $package
	 */
	private static function has_resolvable_destination( array $package ): bool {
		$destination = $package['destination'] ?? null;
		return is_array( $destination ) && '' !== trim( (string) ( $destination['country'] ?? '' ) );
	}

	/**
	 * @param object $cart WC_Cart-like.
	 */
	private static function has_valid_free_shipping_coupon( object $cart ): bool {
		if ( ! method_exists( $cart, 'get_coupons' ) ) {
			return false;
		}

		foreach ( (array) $cart->get_coupons() as $coupon ) {
			if ( ! is_object( $coupon ) || ! method_exists( $coupon, 'get_free_shipping' ) ) {
				continue;
			}
			try {
				$is_valid = ! method_exists( $coupon, 'is_valid' ) || (bool) $coupon->is_valid();
				if ( $is_valid && (bool) $coupon->get_free_shipping() ) {
					return true;
				}
			} catch ( \Throwable $error ) {
				// Cupón inválido/defectuoso: falla cerrada.
				continue;
			}
		}

		return false;
	}

	/**
	 * @return array{
	 *   state: string,
	 *   reason: string,
	 *   threshold: float,
	 *   total: float,
	 *   remaining: float
	 * }
	 */
	private static function unavailable_state(): array {
		return array(
			'state'     => self::STATE_UNAVAILABLE,
			'reason'    => '',
			'threshold' => 0.0,
			'total'     => 0.0,
			'remaining' => 0.0,
		);
	}

}
