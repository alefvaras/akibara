<?php
/**
 * Comparte el contrato de los tres estados crudos de stock de WooCommerce.
 *
 * Sus predicados estáticos no tienen estado ni registran hooks. onbackorder
 * identifica un reservable comprable sin unidades físicas; no distingue por sí
 * solo A pedido y Preventa. Esa clasificación y sus etiquetas corresponden a
 * Availability. StockStatus se consume como helper de dominio compartido.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\Stock;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.25.0
 */
final class StockStatus {

	public const IN_STOCK     = 'instock';
	public const OUT_OF_STOCK = 'outofstock';
	public const ON_BACKORDER = 'onbackorder';

	/**
	 * Vocabulario del estado de stock CRUDO — la palabra del GRUPO.
	 *
	 * SOLO la palabra (label): nada de color/tono/ícono. El color es presentación
	 * y vive en cada CSS (loop.css del theme, etc.).
	 *
	 * Desde 1.109.0 este mapa nombra GRUPOS agregados por `stock_status` (chips
	 * del selector de series, filas de conteo de SeriesFacts, facetas SQL) — por
	 * eso `onbackorder` dice «A pedido»: es el default y la realidad de todo el
	 * grupo reservable. La palabra por PRODUCTO individual sale de
	 * `Availability::label_of()`, que sí distingue «A pedido» de «Preventa».
	 *
	 * Keyed por las constantes de estado de esta misma clase (label() solo la
	 * traduce en runtime, ya que un `const` no puede llamar funciones).
	 *
	 * @var array<string, string>
	 */
	public const LABELS = array(
		self::IN_STOCK     => 'Disponible',
		self::ON_BACKORDER => 'A pedido',
		self::OUT_OF_STOCK => 'Agotado',
	);

	/**
	 * Palabra VIGENTE del grupo, sin pasar por el filtro.
	 *
	 * Existe por dos razones. Una: `onbackorder` se nombra con la palabra que el
	 * dueño escribe en Akibara → Textos para «A pedido» (es el default y la
	 * realidad de todo el grupo reservable), y el `const` de arriba pasó a ser
	 * sólo el valor de fábrica. Otra: quien RESPONDE `akb_stock_status_label`
	 * —el puente con el buscador— necesita la palabra sin volver a aplicar el
	 * filtro, que sería recursión infinita.
	 *
	 * @since 1.116.20
	 *
	 * @param string $status Estado de stock crudo (otro → fail-closed a 'Agotado').
	 * @return string
	 */
	public static function palabra( string $status ): string {
		if ( self::ON_BACKORDER === $status ) {
			return Availability::palabra( Availability::A_PEDIDO );
		}

		return self::LABELS[ $status ] ?? self::LABELS[ self::OUT_OF_STOCK ];
	}

	/**
	 * Palabra (label) del estado de stock para presentación — la ÚNICA vía.
	 *
	 * Fail-closed: un `$status` desconocido cae a la palabra de OUT_OF_STOCK
	 * ('Agotado'), el estado más conservador (nunca promete disponibilidad que no
	 * se pudo verificar). El buscador (otro plugin, first-party aparte) consumirá
	 * el filtro con su propio default sin acoplarse a esta clase.
	 *
	 * El filtro `akb_stock_status_label` es el ÚNICO punto de override del
	 * vocabulario: recibe la palabra ya resuelta y el status crudo.
	 *
	 * @since 1.84.0
	 *
	 * @param string $status Uno de IN_STOCK|OUT_OF_STOCK|ON_BACKORDER (otro → fail-closed).
	 * @return string Palabra visible del estado.
	 */
	public static function label( string $status ): string {
		// LABELS es la fuente única del vocabulario es-CL. El filtro permite ajustes
		// sin introducir una segunda definición en cada superficie.

		$label = self::palabra( $status );

		/**
		 * Filtra la palabra visible de un estado de stock. ÚNICO punto de override
		 * del vocabulario Disponible/Preventa/Agotado (lo consume el buscador con su
		 * propio default). Devolvé la palabra a mostrar para ese estado.
		 *
		 * @since 1.84.0
		 *
		 * @param string $label  Palabra resuelta (fail-closed a 'Agotado' si el status es desconocido).
		 * @param string $status Estado de stock crudo consultado.
		 */
		return (string) apply_filters(
			'akb_stock_status_label',
			$label,
			$status
		);
	}

	/**
	 * Estado de stock crudo del producto.
	 *
	 * @param \WC_Product $product
	 * @return string Uno de IN_STOCK|OUT_OF_STOCK|ON_BACKORDER.
	 */
	public static function of( \WC_Product $product ): string {
		return (string) $product->get_stock_status();
	}

	/**
	 * Indica si el producto es reservable mediante onbackorder.
	 *
	 * El predicado responde si puede comprarse sin stock físico. Para distinguir
	 * obra publicada A pedido y lanzamiento Preventa se usa Availability::of(),
	 * is_preventa() o is_a_pedido().
	 *
	 * @since 1.109.0 (renombrado desde is_preventa)
	 *
	 * @param \WC_Product $product
	 * @return bool
	 */
	public static function is_reservable( \WC_Product $product ): bool {
		return self::ON_BACKORDER === self::of( $product );
	}

	/**
	 * ¿El producto está agotado? (`outofstock` estricto — NO incluye preventa).
	 *
	 * @param \WC_Product $product
	 * @return bool
	 */
	public static function is_out_of_stock( \WC_Product $product ): bool {
		return self::OUT_OF_STOCK === self::of( $product );
	}

	/**
	 * ¿El producto está en stock real? (`instock` estricto — NO incluye preventa).
	 * Distinto de WC_Product::is_in_stock(), que considera onbackorder como true.
	 *
	 * @param \WC_Product $product
	 * @return bool
	 */
	public static function is_instock( \WC_Product $product ): bool {
		return self::IN_STOCK === self::of( $product );
	}
}
