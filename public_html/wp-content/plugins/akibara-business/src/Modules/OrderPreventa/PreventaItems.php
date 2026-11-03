<?php
/**
 * Fuente canónica de los artículos que un cliente está esperando.
 *
 * A pedido significa que la editorial dispone de la obra y el plazo depende
 * del envío (snapshot auto). Preventa significa que aún no está publicada y
 * el plazo combina lanzamiento y envío (snapshot release_date o tba).
 *
 * El término comercial procede de cómo se compró la línea, no del estado actual
 * del producto. Las superficies de pedido, correos, seguimiento y recálculo de
 * arribo consultan esta misma proyección para conservar esa distinción.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\OrderPreventa;

use Akibara\Business\Modules\Stock\Availability;
use Akibara\Business\Modules\Stock\StockStatus;
use Akibara\Business\Utilities\Text;
use WC_Order;
use WC_Order_Item_Product;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Consulta canónica de las líneas en espera de un pedido.
 *
 * @since 1.87.0
 * @since 1.114.12 Absorbe la palabra («Preventa»/«A pedido»), la cantidad neta
 *                 de reembolsos y el predicado «sigue pendiente de llegar».
 */
final class PreventaItems {

	// ───────────────────────────────────────────────────── qué líneas siguen vigentes

	/**
	 * Líneas que se compraron en espera y no se han reembolsado del todo.
	 *
	 * @param WC_Order $order Pedido.
	 * @return array<int, WC_Order_Item_Product>
	 */
	public static function outstanding_items( WC_Order $order ): array {
		$out = array();
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof WC_Order_Item_Product && self::is_outstanding_item( $order, $item ) ) {
				$out[] = $item;
			}
		}
		return $out;
	}

	/**
	 * @param WC_Order $order Pedido.
	 * @return bool
	 */
	public static function has_outstanding_items( WC_Order $order ): bool {
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof WC_Order_Item_Product && self::is_outstanding_item( $order, $item ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param WC_Order              $order Pedido.
	 * @param WC_Order_Item_Product $item  Línea.
	 * @return bool
	 */
	public static function is_outstanding_item( WC_Order $order, WC_Order_Item_Product $item ): bool {
		return 'yes' === (string) $item->get_meta( MetaKeys::ITEM_WAS_PREVENTA )
			&& self::outstanding_qty( $order, $item ) > 0;
	}

	/**
	 * Unidades que el cliente sigue esperando: las compradas menos las devueltas.
	 *
	 * La única resta de reembolso de la casa. Los correos imprimían
	 * `get_quantity()` en crudo, así que un cliente al que se le devolvió 1 de 3
	 * ejemplares seguía leyendo «× 3».
	 *
	 * @since 1.114.12
	 *
	 * @param WC_Order              $order Pedido.
	 * @param WC_Order_Item_Product $item  Línea.
	 * @return int Nunca negativo.
	 */
	public static function outstanding_qty( WC_Order $order, WC_Order_Item_Product $item ): int {
		$quantity = abs( (int) $item->get_quantity() );
		$refunded = abs( (int) $order->get_qty_refunded_for_item( $item->get_id() ) );

		return max( 0, $quantity - $refunded );
	}

	/**
	 * Obtiene el ID canónico del producto asociado a una línea de pedido.
	 *
	 * Resuelve la discrepancia entre el método tipado de WooCommerce y el metadato
	 * crudo `_product_id` en pedidos históricos o entornos de prueba.
	 *
	 * @since 1.117.0
	 *
	 * @param WC_Order_Item_Product $item Línea de pedido.
	 * @return int ID del producto o 0 si no se pudo determinar.
	 */
	public static function product_id_of( WC_Order_Item_Product $item ): int {
		$pid = (int) $item->get_product_id();
		if ( $pid <= 0 && function_exists( 'wc_get_order_item_meta' ) ) {
			$pid = (int) wc_get_order_item_meta( $item->get_id(), '_product_id', true );
		}

		return max( 0, $pid );
	}

	// ───────────────────────────────────────────────────── qué líneas siguen sin llegar

	/**
	 * Líneas vigentes cuyo tomo todavía no está en bodega.
	 *
	 * @since 1.114.12
	 *
	 * @param WC_Order $order Pedido.
	 * @return array<int, WC_Order_Item_Product>
	 */
	public static function pending_items( WC_Order $order ): array {
		$out = array();
		foreach ( self::outstanding_items( $order ) as $item ) {
			if ( ! self::has_arrived( $order, $item ) ) {
				$out[] = $item;
			}
		}
		return $out;
	}

	/**
	 * ¿Esta línea sigue pendiente de llegar?
	 *
	 * Vigente (no reembolsada del todo) y con el tomo aún fuera de la bodega.
	 * Es el predicado que antes vivía escondido en el recuento del cron, y por
	 * no compartirlo el estimado de arribo seguía contando tomos que ya estaban
	 * en la estantería y tomos que se habían devuelto.
	 *
	 * @since 1.114.12
	 *
	 * @param WC_Order              $order Pedido.
	 * @param WC_Order_Item_Product $item  Línea.
	 * @return bool
	 */
	public static function is_pending_item( WC_Order $order, WC_Order_Item_Product $item ): bool {
		return self::is_outstanding_item( $order, $item ) && ! self::has_arrived( $order, $item );
	}

	/**
	 * ¿Esta línea ya fue recibida físicamente y sellada en el casillero del pedido?
	 *
	 * Sella la llegada independientemente del estado del producto en el catálogo.
	 * Protege contra sobreventas y quiebres posteriores de stock en la web.
	 *
	 * @since 1.117.0
	 *
	 * @param WC_Order              $order Pedido.
	 * @param WC_Order_Item_Product $item  Línea.
	 * @return bool
	 */
	public static function is_item_sealed( WC_Order $order, WC_Order_Item_Product $item ): bool {
		if ( 'yes' === (string) $item->get_meta( MetaKeys::ITEM_ARRIVED ) ) {
			return true;
		}

		$outstanding = self::outstanding_qty( $order, $item );
		if ( $outstanding > 0 && (int) $item->get_meta( MetaKeys::ITEM_ARRIVED_QTY ) >= $outstanding ) {
			return true;
		}

		return false;
	}

	/**
	 * Unidades que siguen pendientes de llegar físicamente para esta línea.
	 *
	 * Considera las unidades pedidas netas de reembolso menos las que ya fueron
	 * recibidas y apartadas en el casillero.
	 *
	 * @since 1.117.0
	 *
	 * @param WC_Order              $order Pedido.
	 * @param WC_Order_Item_Product $item  Línea.
	 * @return int
	 */
	public static function pending_qty( WC_Order $order, WC_Order_Item_Product $item ): int {
		if ( ! self::is_outstanding_item( $order, $item ) || self::is_item_sealed( $order, $item ) ) {
			return 0;
		}

		$needed  = self::outstanding_qty( $order, $item );
		$already = max( 0, (int) $item->get_meta( MetaKeys::ITEM_ARRIVED_QTY ) );

		return max( 0, $needed - $already );
	}

	/**
	 * Sella la llegada de unidades físicas para una línea de pedido.
	 *
	 * Permite apartar mercadería en el casillero físico de la orden, dejando la
	 * línea sellada para que no dependa de que el producto siga en stock en la web.
	 * Soporta arribos parciales y totales.
	 *
	 * @since 1.117.0
	 *
	 * @param WC_Order              $order Pedido.
	 * @param WC_Order_Item_Product $item  Línea a sellar.
	 * @param int|null              $qty   Unidades a asignar (null = todas las pendientes de esta línea).
	 * @param int|null              $now   Reloj inyectable (tests). Default: time().
	 * @return int Unidades efectivamente asignadas a esta línea.
	 */
	public static function seal_item_arrived( WC_Order $order, WC_Order_Item_Product $item, ?int $qty = null, ?int $now = null ): int {
		$needed = self::outstanding_qty( $order, $item );
		if ( $needed <= 0 ) {
			return 0;
		}

		$already = max( 0, (int) $item->get_meta( MetaKeys::ITEM_ARRIVED_QTY ) );
		if ( 'yes' === (string) $item->get_meta( MetaKeys::ITEM_ARRIVED ) ) {
			$already = $needed;
		}

		$remaining = max( 0, $needed - $already );
		if ( $remaining <= 0 ) {
			return 0;
		}

		$to_add = null === $qty ? $remaining : min( $remaining, max( 0, $qty ) );
		if ( $to_add <= 0 ) {
			return 0;
		}

		$new_total = $already + $to_add;
		$item->update_meta_data( MetaKeys::ITEM_ARRIVED_QTY, $new_total );

		if ( $new_total >= $needed ) {
			$item->update_meta_data( MetaKeys::ITEM_ARRIVED, 'yes' );
			$item->update_meta_data( MetaKeys::ITEM_ARRIVED_AT, $now ?? time() );
		}

		if ( method_exists( $item, 'save' ) ) {
			$item->save();
		}

		return $to_add;
	}

	/**
	 * ¿El tomo de esta línea ya está en bodega?
	 *
	 * Tres señales, en este orden:
	 *   1. La marca del pedido (`ready_to_ship = yes`), que es inmutable y la
	 *      pone el cron cuando TODO llegó. Manda sobre el stock de hoy.
	 *   2. El sellado físico por línea (`is_item_sealed = true`), que registra
	 *      que el tomo ya llegó y fue apartado en el casillero de este pedido,
	 *      incluso si el producto en la web sigue «A pedido» o se agotó después.
	 *   3. El stock del producto en catálogo, para el pedido mixto en que un
	 *      tomo ya llegó y otro no.
	 *
	 * Un producto borrado o en la papelera NO ha llegado si no está sellado: el
	 * dueño tiene que resolverlo a mano (restaurarlo, cambiar la línea o reembolsar).
	 *
	 * @since 1.114.12
	 * @since 1.117.0 Incorpora sellado físico por línea (is_item_sealed).
	 *
	 * @param WC_Order              $order Pedido.
	 * @param WC_Order_Item_Product $item  Línea.
	 * @return bool
	 */
	public static function has_arrived( WC_Order $order, WC_Order_Item_Product $item ): bool {
		if ( 'yes' === (string) $order->get_meta( MetaKeys::ORDER_READY_TO_SHIP ) ) {
			return true;
		}

		if ( self::is_item_sealed( $order, $item ) ) {
			return true;
		}

		$product = $item->get_product();
		if ( ! $product instanceof WC_Product ) {
			return false;
		}
		// `wc_get_product()` devuelve objeto válido para un producto en la
		// papelera; su stock se conserva y diría «llegó» sobre algo que no se
		// puede vender.
		if ( 'trash' === (string) $product->get_status() ) {
			return false;
		}

		return StockStatus::is_instock( $product );
	}

	/**
	 * Todo lo que se esperaba ya está en bodega, aunque el cron todavía no
	 * haya marcado el pedido como listo.
	 *
	 * Es la señal del cartel, la columna y el rastreador: la línea decide
	 * «ya llegó» por el stock del producto, y el resto de la página decidía por
	 * la bandera del pedido. Con stock cargado por CSV o directo en la base
	 * (sin pasar por el hook que marca el pedido), la misma página decía «Ya
	 * llegó a nuestra bodega» en la línea y «estimamos que llega el X» en el
	 * cartel hasta la siguiente pasada del cron.
	 *
	 * @since 1.114.16
	 *
	 * @param WC_Order $order Pedido.
	 * @return bool
	 */
	public static function all_arrived( WC_Order $order ): bool {
		return self::has_outstanding_items( $order ) && array() === self::pending_items( $order );
	}

	// ───────────────────────────────────────────────────── qué fecha se le prometió

	/**
	 * La ventana de llegada que rige HOY para esta línea: la última calculada
	 * (postergación de la editorial) o, si nunca se recalculó, la de compra.
	 *
	 * Es la única fecha que la línea puede enseñarle al cliente: antes imprimía
	 * siempre el rango de compra, y tras una postergación la misma página
	 * decía «Llega entre el 2 y el 5 de junio» en la línea y «llega el 30 de
	 * junio» en el cartel.
	 *
	 * Tres estados, no dos: nunca recalculada (→ la de compra), recalculada
	 * (→ la vigente) y RETIRADA (la editorial quitó la fecha: `0` explícito en
	 * `ITEM_MAX_CURRENT`, → sin fecha). Sin el tercero, tras vaciar la fecha la
	 * línea caía a la promesa de compra y decía «Llega entre el 8 y el 14 de
	 * octubre» mientras la columna del mismo pedido decía «sin fecha».
	 *
	 * @since 1.114.16
	 *
	 * @param WC_Order_Item_Product $item Línea.
	 * @return array{min:int|null, max:int|null}
	 */
	public static function promised_window( WC_Order_Item_Product $item ): array {
		$vigente = (string) $item->get_meta( MetaKeys::ITEM_MAX_CURRENT );
		if ( '' !== $vigente ) {
			$max = (int) $vigente;
			$min = (int) $item->get_meta( MetaKeys::ITEM_MIN_CURRENT );
		} else {
			$max = (int) $item->get_meta( MetaKeys::ITEM_MAX_AT_PURCHASE );
			$min = (int) $item->get_meta( MetaKeys::ITEM_MIN_AT_PURCHASE );
		}

		return array(
			'min' => $min > 0 ? $min : null,
			'max' => $max > 0 ? $max : null,
		);
	}

	// ───────────────────────────────────────────────────── la palabra

	/**
	 * ¿Esta línea se compró como PREVENTA (y no «a pedido»)?
	 *
	 * Se lee del snapshot de la compra (`_akb_eta_type_at_purchase`): lo que el
	 * cliente compró no cambia porque el catálogo cambie. `release_date` y `tba`
	 * son preventa (con fecha y sin ella); `auto` y la ausencia de la meta son
	 * «a pedido», que es el caso normal de esta tienda.
	 *
	 * @since 1.114.10 En CustomerVisibility.
	 * @since 1.114.12 Aquí, para que el correo, el rastreador y el admin usen el mismo criterio.
	 *
	 * @param WC_Order_Item_Product $item Línea del pedido.
	 * @return bool
	 */
	public static function era_preventa( WC_Order_Item_Product $item ): bool {
		$tipo = (string) $item->get_meta( MetaKeys::ITEM_TYPE_AT_PURCHASE );

		// Sin snapshot (pedidos anteriores a 1.109.0) eran todos a pedido en la
		// práctica: mirar el estado de HOY del producto inventaba una preventa
		// donde nunca la hubo.
		return in_array( $tipo, Availability::TYPES_PREVENTA, true );
	}

	/**
	 * ¿Hay al menos un pre-lanzamiento de verdad entre lo que falta por llegar?
	 *
	 * @since 1.114.4 En CustomerVisibility.
	 * @since 1.114.12 Aquí.
	 *
	 * @param WC_Order $order Pedido.
	 * @return bool
	 */
	public static function has_real_preventa( WC_Order $order ): bool {
		foreach ( self::outstanding_items( $order ) as $item ) {
			if ( self::era_preventa( $item ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * «Preventa» o «A pedido» para una línea, con el texto que el dueño edita en
	 * Akibara → Textos. Nadie debe comparar este texto contra un literal para
	 * decidir nada: si hace falta la decisión, es `era_preventa()`.
	 *
	 * @since 1.114.12
	 *
	 * @param WC_Order_Item_Product $item Línea del pedido.
	 * @return string
	 */
	public static function item_label( WC_Order_Item_Product $item ): string {
		return self::label( self::era_preventa( $item ) );
	}

	/**
	 * La misma palabra, para el pedido entero: «Preventa» si alguna de sus líneas
	 * en espera lo es; «A pedido» si no.
	 *
	 * @since 1.114.12
	 *
	 * @param WC_Order $order Pedido.
	 * @return string
	 */
	public static function order_label( WC_Order $order ): string {
		return self::label( self::has_real_preventa( $order ) );
	}

	/**
	 * El sustantivo para meter en una frase: «Tu pedido incluye preventa» /
	 * «Tu pedido incluye productos a pedido».
	 *
	 * Existe aparte de `order_label()` porque las etiquetas están escritas como
	 * rótulo suelto («A pedido»), y dentro de una frase darían «Tu pedido
	 * incluye A pedido». Tiene sus propias options en Akibara → Textos.
	 *
	 * @since 1.114.4 En CustomerVisibility.
	 * @since 1.114.12 Aquí.
	 *
	 * @param WC_Order $order Pedido.
	 * @return string
	 */
	public static function order_noun( WC_Order $order ): string {
		// Text::one_line_option() aplica el mismo saneamiento y fallback que el panel,
		// incluso si una fila existente quedó vacía por CLI o importación.

		return self::has_real_preventa( $order )
			? Text::one_line_option( 'akb_copy_eta_noun_preventa', 'preventa' )
			: Text::one_line_option( 'akb_copy_eta_noun_pedido', 'productos a pedido' );
	}

	/**
	 * Los dos textos son los MISMOS que usa el banner de la ficha
	 * (`BannerRenderer`), así que la palabra queda igual en la ficha, en el
	 * carrito, en el pedido y en el correo.
	 *
	 * @param bool $preventa Si la respuesta es «Preventa».
	 * @return string
	 */
	private static function label( bool $preventa ): string {
		// Por la fuente única (`Availability::palabra`), que además defiende del
		// vacío y del texto larguísimo: leer la option a pelo dejaba la línea
		// del pedido en blanco si alguien vaciaba el texto por WP-CLI.
		return Availability::palabra( $preventa ? Availability::PREVENTA : Availability::A_PEDIDO );
	}

	private function __construct() {}
}
