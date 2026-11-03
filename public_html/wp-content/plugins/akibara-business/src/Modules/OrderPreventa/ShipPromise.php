<?php
/**
 * ShipPromise — la ventana de despacho que la tienda promete, en UN solo lugar.
 *
 * «1-3 días hábiles» estaba escrito a mano en SEIS superficies: el banner del
 * detalle del pedido (plain y HTML) y los cuatro correos de preventa (listo y
 * recordatorio, cada uno en HTML y en texto plano). Cambiar el SLA obligaba a
 * encontrar las seis; encontrar cinco dejaba al cliente con dos promesas
 * distintas en el mismo pedido —y la que se olvidaba era justo la de texto
 * plano, la que nadie mira.
 *
 * Se guarda sólo la VENTANA («1-3 días hábiles»), no la frase entera: cada
 * superficie tiene su propia redacción alrededor («Despachamos en…», «Sale en
 * los próximos…»), y meterlas todas en un campo obligaría al dueño a repetir
 * seis veces el mismo dato para cambiarlo una vez.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\OrderPreventa;

use Akibara\Business\Utilities\Text;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.110.1
 */
final class ShipPromise {

	/** Option editable en Akibara → Textos → «Promesas de la tienda». */
	public const OPTION_WINDOW = 'akb_copy_promise_ready_window';

	/** El literal que las seis superficies tenían clavado. */
	public const DEFAULT_WINDOW = '1-3 días hábiles';

	/**
	 * Ventana vigente. Vacía o ilegible cae al literal de siempre: un pedido
	 * listo NO puede quedarse sin promesa de despacho («Sale en los próximos.»).
	 */
	public static function window(): string {
		return Text::one_line_option( self::OPTION_WINDOW, self::DEFAULT_WINDOW );
	}
}
