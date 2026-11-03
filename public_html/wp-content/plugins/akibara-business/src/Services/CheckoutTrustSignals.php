<?php
/**
 * Canonical checkout trust signals for first-party presentation adapters.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Provides semantic trust content without coupling Business to a renderer.
 *
 * @since 1.96.0
 */
final class CheckoutTrustSignals {

	/** Option editable en Akibara → Textos → «Promesas de la tienda». */
	public const OPTION_INSTALLMENTS = 'akb_copy_promise_installments';

	/** El literal de siempre; también el default del campo del panel. */
	public const DEFAULT_INSTALLMENTS = '3 cuotas sin interés';

	/**
	 * Utility class.
	 */
	private function __construct() {}

	/**
	 * Returns the canonical trust signals in display order.
	 *
	 * @return array<int,array{id:string,label:string,icon:string}>
	 *
	 * @since 1.96.0
	 */
	public static function all(): array {
		return array(
			array(
				'id'    => 'shipping',
				'label' => 'Envíos a todo Chile',
				'icon'  => 'shipping',
			),
			array(
				'id'    => 'installments',
				'label' => self::installments_copy(),
				'icon'  => 'card',
			),
			array(
				'id'    => 'support',
				'label' => 'Atención personalizada',
				'icon'  => 'support',
			),
		);
	}

	/**
	 * La promesa de cuotas, editable en Akibara → Textos → «Promesas de la
	 * tienda». Es una promesa FINANCIERA que cambia con el medio de pago, no un
	 * literal de código.
	 *
	 * Se expone como método propio porque el checkout dejó de ser su único
	 * lector: el FAQ de la ficha la escribía a mano, y con dos fuentes bastaba
	 * pasar a 6 cuotas para que la tienda se contradijera a sí misma.
	 *
	 * @since 1.110.1
	 */
	public static function installments_copy(): string {
		$copy = get_option( self::OPTION_INSTALLMENTS, self::DEFAULT_INSTALLMENTS );
		$copy = is_string( $copy ) ? trim( $copy ) : '';

		return '' !== $copy ? $copy : self::DEFAULT_INSTALLMENTS;
	}
}
