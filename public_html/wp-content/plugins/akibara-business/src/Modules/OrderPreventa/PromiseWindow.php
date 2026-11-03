<?php
/**
 * La ventana que se le PROMETIÓ a un pedido al comprar, y la que rige hoy.
 *
 * Un pedido viaja entero, así que la promesa que el cliente recuerda es la del
 * tomo que más demora: el borde más tardío de sus líneas. Tomar el mínimo de
 * los mínimos y el máximo de los máximos describiría una ventana que nunca se
 * le mostró a nadie.
 *
 * NO descuenta reembolsos a propósito: audita lo prometido en el checkout, no
 * lo que sigue vigente. Por eso no vive en `PreventaItems`, que responde a la
 * otra pregunta («¿qué sigue esperando el cliente?»).
 *
 * Hasta 1.114.12 esto era privado del comando de consola, y la pantalla
 * «¿Estás cumpliendo los plazos?» —que existe para no necesitar la consola—
 * pasaba `null` como mínimo, así que todo caía en «sin promesa» y el bloque le
 * decía al dueño que no había nada que medir teniendo diez pedidos cerrados.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\OrderPreventa;

use Akibara\Business\Modules\EstimatedDelivery\PedidoPolicy;
use WC_Order;
use WC_Order_Item_Product;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.114.13
 */
final class PromiseWindow {

	/**
	 * Estados donde no hay promesa que cumplir. Contarlos arruina el informe
	 * de la peor manera: la rama de reembolso de ReadyToShipCron marca «listo»
	 * en el acto, así que cada cancelación entraría como «llegó 16 días ANTES»
	 * y haría ver el plazo mucho mejor de lo que es. Sin prefijo `wc-`.
	 *
	 * @var array<int, string>
	 */
	public const ESTADOS_SIN_PROMESA = array( 'cancelled', 'refunded', 'failed', 'trash' );

	/**
	 * ¿Este pedido cuenta para medir el cumplimiento?
	 *
	 * Ni los cancelados/reembolsados/fallidos, ni un pedido vivo al que se le
	 * reembolsaron TODAS sus líneas en espera: ése queda «listo» al instante
	 * por la rama de reembolso del cron, y entraba en el bloque «¿Estás
	 * cumpliendo los plazos?» como entregado en plazo. Una devolución no es una
	 * promesa cumplida.
	 *
	 * @param WC_Order $order Pedido.
	 * @return bool
	 */
	public static function auditable( WC_Order $order ): bool {
		return ! in_array( $order->get_status(), self::ESTADOS_SIN_PROMESA, true )
			&& PreventaItems::has_outstanding_items( $order );
	}

	/**
	 * La promesa de COMPRA: min/max del tomo que más demora, con su país y, si
	 * la editorial tiene política propia, su marca.
	 *
	 * El país y la marca salen del MISMO tomo que fija la ventana, no del
	 * primero que aparezca: en un pedido con tomos de Argentina y de España,
	 * etiquetarlo con el primero le atribuiría a un país el atraso del otro — y
	 * el informe existe justamente para decidir plazos POR país.
	 *
	 * @param WC_Order $order Pedido.
	 * @return array{min:int|null, max:int|null, pais:string|null, marca:string|null}
	 */
	public static function of( WC_Order $order ): array {
		$min   = null;
		$max   = null;
		$pais  = null;
		$marca = null;

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			if ( 'yes' !== (string) $item->get_meta( MetaKeys::ITEM_WAS_PREVENTA ) ) {
				continue;
			}

			$item_min = (int) $item->get_meta( MetaKeys::ITEM_MIN_AT_PURCHASE );
			$item_max = (int) $item->get_meta( MetaKeys::ITEM_MAX_AT_PURCHASE );

			if ( $item_max > 0 && ( null === $max || $item_max > $max ) ) {
				$max    = $item_max;
				$min    = $item_min > 0 ? $item_min : null;
				$marca  = self::marca_con_politica( $item );
				$nombre = (string) $item->get_meta( MetaKeys::ITEM_COUNTRY_NAME_AT_PURCHASE );
				$pais   = '' !== $nombre ? $nombre : $pais;
			}

			// Un tomo sin ventana todavía sirve para saber de qué país es el
			// pedido cuando NINGUNO la tiene.
			if ( null === $pais ) {
				$nombre = (string) $item->get_meta( MetaKeys::ITEM_COUNTRY_NAME_AT_PURCHASE );
				$pais   = '' !== $nombre ? $nombre : null;
			}
		}

		return array(
			'min'   => $min,
			'max'   => $max,
			'pais'  => $pais,
			'marca' => $marca,
		);
	}

	/**
	 * La promesa que RIGE: la de compra, salvo que se haya recalculado.
	 *
	 * `SummaryRecalculator` reescribe `ORDER_SUMMARY_MAX` cuando la editorial
	 * posterga un tomo (o llega uno, o se reembolsa) y el cliente recibe esa
	 * fecha por correo. Juzgar contra la del checkout acusaría de «45 días
	 * tarde» a un pedido que cumplió la única fecha que el cliente tiene en su
	 * bandeja de entrada. Es la MISMA regla para el comando y para la pantalla:
	 * si divergen, uno de los dos miente.
	 *
	 * @param WC_Order $order Pedido.
	 * @return array{min:int|null, max:int|null, pais:string|null, marca:string|null, recalcada:bool}
	 */
	public static function vigente( WC_Order $order ): array {
		$promesa              = self::of( $order );
		$promesa['recalcada'] = false;

		$vigente = (int) $order->get_meta( MetaKeys::ORDER_SUMMARY_MAX );
		if ( $vigente > 0 && null !== $promesa['max'] && $vigente !== $promesa['max'] ) {
			$promesa['max']       = $vigente;
			$promesa['recalcada'] = true;
		}

		return $promesa;
	}

	/**
	 * Sólo se llena si la editorial tiene política PROPIA. Es la clave de
	 * agrupación del informe: sumar bajo «Argentina» una promesa que corre
	 * desde la compra junto a otra que corre desde un cierre mensual mezcla dos
	 * negocios distintos bajo la misma bandera.
	 *
	 * @param WC_Order_Item_Product $item Línea.
	 * @return string|null
	 */
	private static function marca_con_politica( WC_Order_Item_Product $item ): ?string {
		$product = $item->get_product();
		if ( ! $product instanceof WC_Product ) {
			return null;
		}

		$policy = ( new PedidoPolicy() )->for_product( $product );

		return PedidoPolicy::MODO_HEREDADO === $policy['modo'] || '' === $policy['marca']
			? null
			: $policy['marca'];
	}

	private function __construct() {}
}
