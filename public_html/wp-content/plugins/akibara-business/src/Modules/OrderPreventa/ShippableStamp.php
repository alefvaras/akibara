<?php
/**
 * Sella el instante de la transición operacional a pedido despachable.
 *
 * Esta señal permite comparar la promesa con la posibilidad efectiva de envío,
 * distinta del instante en que el cron detecta el stock de todos los reservables.
 * La fecha se captura al producirse la transición; no se reconstruye leyendo
 * notas traducidas. PromiseAudit puede contrastar ambas señales y su divergencia.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\OrderPreventa;

use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.108.22
 */
final class ShippableStamp {

	/**
	 * Estado de cierre directo, siempre despachable.
	 *
	 * `processing` NO cuenta: ahí el pedido está pagado pero la mercadería de
	 * preventa todavía puede no existir, que es justo el tramo que se mide.
	 */
	private const ESTADO_COMPLETADO = 'completed';

	/** Estado de entrega a BlueExpress cuando no hay nada configurado. */
	private const ESTADO_HANDOFF_POR_DEFECTO = 'shipping-progress';

	/** @var callable():int */
	private $now;

	/**
	 * @param callable|null $now Reloj inyectable (pruebas).
	 */
	public function __construct( ?callable $now = null ) {
		$this->now = $now ?? 'time';
	}

	public function register(): void {
		add_action( 'woocommerce_order_status_changed', array( $this, 'stamp' ), 10, 4 );
	}

	/**
	 * @param int    $order_id Pedido.
	 * @param string $anterior Estado previo.
	 * @param string $nuevo    Estado nuevo.
	 * @param mixed  $order    Pedido, tal como lo pasa WooCommerce.
	 */
	public function stamp( $order_id, $anterior = '', $nuevo = '', $order = null ): void {
		unset( $anterior );

		if ( ! in_array( (string) $nuevo, $this->estados_despachables(), true ) ) {
			return;
		}

		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( (int) $order_id );
		}
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		// Sólo pedidos con preventa: sellar los demás sería escribir una meta en
		// cada pedido de la tienda para un informe que ni siquiera los mira.
		if ( 'yes' !== (string) $order->get_meta( MetaKeys::ORDER_HAS_PREVENTA ) ) {
			return;
		}

		// Primer cruce y nada más. Un pedido que vuelve a «procesando» y avanza
		// otra vez no puede reescribir cuándo estuvo listo la primera vez: eso
		// borraría justamente el atraso que se quiere medir.
		if ( (int) $order->get_meta( MetaKeys::ORDER_SHIPPABLE_AT ) > 0 ) {
			return;
		}

		// La salida al transportista no se bloquea por tener líneas pendientes,
		// pero se registra: cambia lo que ve el cliente y el informe de cumplimiento
		// considera ese instante como despacho.

		$sin_llegar = 0;
		foreach ( PreventaItems::pending_items( $order ) as $item ) {
			$sin_llegar += PreventaItems::outstanding_qty( $order, $item );
		}
		if ( $sin_llegar > 0 ) {
			// Sin `function_exists()`: ver el docblock de `estados_despachables()`.
			$order->add_order_note(
				sprintf(
					'Pasó a «%s» con %d %s de preventa sin llegar: el cliente deja de ver la espera y de recibir avisos de fecha, y el informe de cumplimiento contará este momento como despacho.',
					wc_get_order_status_name( (string) $nuevo ),
					$sin_llegar,
					1 === $sin_llegar ? 'tomo' : 'tomos'
				)
			);
		}

		$order->update_meta_data( MetaKeys::ORDER_SHIPPABLE_AT, ( $this->now )() );

		// `save_meta_data()` y no `save()`: esto corre DENTRO de
		// `woocommerce_order_status_changed`, o sea dentro del guardado que lo
		// disparó. Un `save()` completo re-emitiría `woocommerce_update_order` en
		// medio de la transición sin ninguna razón — sólo cambió una meta.
		$order->save_meta_data();
	}

	/**
	 * Estados que cuentan como despachables, leyendo la configuración real.
	 *
	 * El estado de entrega a BlueExpress NO es una constante: sale de
	 * `woocommerce_correios_settings['noBlueStatus']` y es un campo editable en su
	 * pantalla de integración. Fijarlo a mano aquí significaba que, el día que el
	 * dueño lo cambiara, este sello dejaría de escribirse en silencio y el informe
	 * de cumplimiento volvería a la señal mala del cron sin que nada avisara.
	 *
	 * @return array<int, string>
	 */
	private function estados_despachables(): array {
		// Sin `function_exists()`: en producción WordPress siempre las tiene, y
		// dentro de la suite esa comprobación MIENTE por contagio entre pruebas
		// (devuelve true para una función que al llamarse lanza). Un método
		// guardado así es código que las pruebas nunca ejecutan de verdad.
		$settings = get_option( 'woocommerce_correios_settings', array() );
		$handoff  = is_array( $settings ) ? (string) ( $settings['noBlueStatus'] ?? '' ) : '';
		$handoff  = sanitize_key( $handoff );
		$handoff  = str_starts_with( $handoff, 'wc-' ) ? substr( $handoff, 3 ) : $handoff;

		if ( '' === $handoff ) {
			$handoff = self::ESTADO_HANDOFF_POR_DEFECTO;
		}

		$estados = array( self::ESTADO_COMPLETADO, $handoff );

		// El default sigue contando aunque se haya configurado otro: los pedidos
		// que ya pasaron por él no dejan de haber sido despachados.
		if ( self::ESTADO_HANDOFF_POR_DEFECTO !== $handoff ) {
			$estados[] = self::ESTADO_HANDOFF_POR_DEFECTO;
		}

		return $estados;
	}
}
