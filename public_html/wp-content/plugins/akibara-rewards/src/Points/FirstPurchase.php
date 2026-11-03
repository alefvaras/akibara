<?php
/**
 * FirstPurchase — Validación de primera compra por identidad real de la persona.
 *
 * El bono de bienvenida y el beneficio de referido se otorgan en la primera
 * compra con al menos el monto mínimo en dinero pagado, y aplican una sola vez
 * por persona natural: si el RUT, teléfono o correo ya existen en una compra
 * pagada previa, se omite la acreditación duplicada.
 *
 * La identidad se contrasta entre cuentas y pedidos de invitado; crear otra
 * cuenta no renueva el derecho al beneficio.
 *
 * Qué cuenta como «compra anterior»: un pedido PAGADO (procesando, listo para
 * enviar o completado) creado antes que éste y desde el inicio del programa.
 * Un pedido anulado o reembolsado no cuenta —la venta no existió—, y uno
 * anterior al programa tampoco, que es lo que la página del programa promete:
 * «un pedido anterior no consume tu bono de bienvenida».
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Points;

use Akibara\Rewards\Support\Options;
use Akibara\Rewards\Support\OrderStates;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.4.0
 */
final class FirstPurchase {

	/** Meta del RUT en el pedido, tal como la escribe el checkout de bloques. */
	public const META_RUT = '_billing_rut';

	/**
	 * ¿La compra paga el mínimo con DINERO?
	 *
	 * Se mide sobre la misma base con la que se acreditan los puntos: productos
	 * con IVA y tras cupones, sin despacho, menos lo reembolsado y menos lo
	 * pagado con puntos. Con el mínimo en 0 basta con que haya algo de dinero:
	 * una compra pagada entera con puntos no es una compra nueva.
	 *
	 * @param \WC_Order $order Pedido.
	 * @return bool
	 */
	public static function meets_minimum( \WC_Order $order ): bool {
		$paid = Calculator::award_base_for_order( $order );
		$min  = Options::first_purchase_min();

		return $min > 0 ? $paid >= $min : $paid > 0;
	}

	/**
	 * ¿La misma persona ya compró antes, con este u otro nombre de cuenta?
	 *
	 * Una consulta por señal y se corta en la primera que encuentra algo. Cada
	 * una pide un solo ID: aquí sólo importa si existe.
	 *
	 * @param \WC_Order $order Pedido que se está evaluando.
	 * @return bool
	 */
	public static function has_prior_purchase( \WC_Order $order ): bool {
		$base = array(
			'type'    => 'shop_order',
			'status'  => OrderStates::PAID,
			'limit'   => 1,
			'return'  => 'ids',
			'exclude' => array( (int) $order->get_id() ),
		);

		$rango = self::date_range_before( $order );
		if ( '' !== $rango ) {
			$base['date_created'] = $rango;
		}

		$consultas = array();

		if ( $order->get_customer_id() > 0 ) {
			$consultas[] = array( 'customer_id' => (int) $order->get_customer_id() );
		}

		$senales = Options::first_purchase_checks();

		$email = strtolower( trim( (string) $order->get_billing_email() ) );
		if ( '' !== $email && in_array( 'email', $senales, true ) ) {
			$consultas[] = array( 'billing_email' => $email );
		}

		if ( in_array( 'phone', $senales, true ) ) {
			foreach ( self::phone_variants( (string) $order->get_billing_phone() ) as $telefono ) {
				$consultas[] = array( 'billing_phone' => $telefono );
			}
		}

		$ruts = in_array( 'rut', $senales, true ) ? self::rut_variants( (string) $order->get_meta( self::META_RUT ) ) : array();
		if ( array() !== $ruts ) {
			$consultas[] = array(
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- una fila como máximo, sólo en la primera compra de una cuenta.
					array(
						'key'     => self::META_RUT,
						'value'   => $ruts,
						'compare' => 'IN',
					),
				),
			);
		}

		foreach ( $consultas as $consulta ) {
			if ( array() !== (array) wc_get_orders( $base + $consulta ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * ¿El pedido es de la misma persona que la cuenta indicada?
	 *
	 * Lo usa el premio al padrino: invitarse a uno mismo con otra cuenta y otro
	 * correo, pero con el mismo RUT o el mismo teléfono, no es invitar a nadie.
	 *
	 * @param \WC_Order $order   Pedido del invitado.
	 * @param int       $user_id Cuenta con la que se compara (el padrino).
	 * @return bool
	 */
	public static function same_person( \WC_Order $order, int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		$senales = Options::first_purchase_checks();

		$email_pedido = strtolower( trim( (string) $order->get_billing_email() ) );
		if ( '' !== $email_pedido && in_array( 'email', $senales, true ) ) {
			$usuario = get_user_by( 'id', $user_id );
			$correos = array(
				$usuario instanceof \WP_User ? strtolower( trim( (string) $usuario->user_email ) ) : '',
				strtolower( trim( (string) get_user_meta( $user_id, 'billing_email', true ) ) ),
			);
			if ( in_array( $email_pedido, $correos, true ) ) {
				return true;
			}
		}

		$telefono = in_array( 'phone', $senales, true ) ? self::normalize_phone( (string) $order->get_billing_phone() ) : '';
		if ( '' !== $telefono && self::normalize_phone( (string) get_user_meta( $user_id, 'billing_phone', true ) ) === $telefono ) {
			return true;
		}

		$rut = in_array( 'rut', $senales, true ) ? self::normalize_rut( (string) $order->get_meta( self::META_RUT ) ) : '';

		return '' !== $rut && self::normalize_rut( (string) get_user_meta( $user_id, 'billing_rut', true ) ) === $rut;
	}

	/**
	 * Normalización de teléfono chileno a dígitos nacionales (sin código de país +56 ni separadores).
	 *
	 * Requiere un mínimo de 8 dígitos para identificación confiable.
	 *
	 * @param string $raw Teléfono tal como vino.
	 * @return string '' si no sirve para identificar.
	 */
	public static function normalize_phone( string $raw ): string {
		$digitos = (string) preg_replace( '/\D+/', '', $raw );

		if ( strlen( $digitos ) >= 11 && 0 === strpos( $digitos, '56' ) ) {
			$digitos = substr( $digitos, 2 );
		}

		return strlen( $digitos ) >= 8 ? $digitos : '';
	}

	/**
	 * Las formas en que ese teléfono puede estar guardado en otro pedido.
	 *
	 * @param string $raw Teléfono.
	 * @return array<int,string>
	 */
	public static function phone_variants( string $raw ): array {
		$nacional = self::normalize_phone( $raw );
		if ( '' === $nacional ) {
			return array();
		}

		return array_values( array_unique( array( '+56' . $nacional, $nacional, '56' . $nacional ) ) );
	}

	/**
	 * RUT reducido a cuerpo + dígito verificador, sin puntos ni guion.
	 *
	 * @param string $raw RUT tal como vino.
	 * @return string '' si no tiene forma de RUT.
	 */
	public static function normalize_rut( string $raw ): string {
		$limpio = strtoupper( (string) preg_replace( '/[^0-9kK]/', '', $raw ) );
		if ( strlen( $limpio ) < 2 ) {
			return '';
		}

		$cuerpo = ltrim( substr( $limpio, 0, -1 ), '0' );
		if ( '' === $cuerpo || ! ctype_digit( $cuerpo ) ) {
			return '';
		}

		return $cuerpo . substr( $limpio, -1 );
	}

	/**
	 * Las formas en que ese RUT puede estar guardado. En producción siempre va
	 * con puntos y guion (`12.345.678-5`), que es lo que escribe el checkout;
	 * las otras dos cubren pedidos creados a mano o por la API.
	 *
	 * @param string $raw RUT.
	 * @return array<int,string>
	 */
	public static function rut_variants( string $raw ): array {
		$rut = self::normalize_rut( $raw );
		if ( '' === $rut ) {
			return array();
		}

		$cuerpo = substr( $rut, 0, -1 );
		$dv     = substr( $rut, -1 );
		$puntos = number_format( (float) $cuerpo, 0, '', '.' );

		$formas = array( $puntos . '-' . $dv, $cuerpo . '-' . $dv, $cuerpo . $dv );

		// Con un cero a la izquierda («012.345.678-5») el validador del checkout
		// acepta y GUARDA el cero —el módulo 11 lo ignora—, así que sin estas
		// formas esa persona cobraría un segundo bono con otra cuenta.
		// El cero va DELANTE de la forma con puntos: pasarlo por number_format
		// lo perdería (es un float) y la variante no existiría.
		$con_cero = '0' . $cuerpo;
		$formas[] = '0' . $puntos . '-' . $dv;
		$formas[] = $con_cero . '-' . $dv;
		$formas[] = $con_cero . $dv;

		if ( 'K' === $dv ) {
			$formas[] = $puntos . '-k';
			$formas[] = $cuerpo . '-k';
			$formas[] = '0' . $puntos . '-k';
			$formas[] = $con_cero . '-k';
		}

		return array_values( array_unique( $formas ) );
	}

	/**
	 * Rango de fechas de «compra anterior»: desde el inicio del programa hasta
	 * un segundo antes de este pedido.
	 *
	 * Sin fecha de creación se conserva el piso del programa: barrer toda la
	 * historia dejaría sin bono a quien compró como invitado antes de que el
	 * programa existiera, que es justo lo que la página promete que no pasa.
	 *
	 * @param \WC_Order $order Pedido.
	 * @return string Rango en el formato de `wc_get_orders`.
	 */
	private static function date_range_before( \WC_Order $order ): string {
		$inicio = Accrual::parse_program_start( Options::program_start() );
		$desde  = $inicio instanceof \DateTimeImmutable ? $inicio->getTimestamp() : 0;

		$creado = $order->get_date_created();
		if ( ! $creado instanceof \WC_DateTime ) {
			return '>' . $desde;
		}

		return sprintf( '%d...%d', $desde, $creado->getTimestamp() - 1 );
	}
}
