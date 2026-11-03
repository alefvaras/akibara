<?php
/**
 * SearchAvailabilityBridge — le presta al buscador el estado comercial y la
 * palabra que lo nombra.
 *
 * akibara-search es self-contained a propósito (jamás hace `use` de business):
 * su DocumentBuilder factea todo `onbackorder` como 'a_pedido' por defecto y
 * pregunta por este filtro si alguien sabe distinguir. Este módulo es ese
 * alguien: responde 'preventa' cuando Availability detecta un lanzamiento
 * pendiente. Sin business activo (cuarentena, deploy a medias), el buscador
 * sigue facetando correcto con su default — nunca un fatal.
 *
 * Lo mismo con el VOCABULARIO. Los dos plugins documentaban
 * `akb_stock_status_label` como «el ÚNICO punto de override» de las palabras
 * Disponible / A pedido / Agotado, y NADIE lo enganchaba: eran dos mapas
 * espejados a mano, en dos rieles de despliegue distintos, que sólo coincidían
 * mientras alguien se acordara de tocar los dos. Business, que es el dueño del
 * vocabulario, lo PROVEE de verdad desde acá; el mapa local del buscador queda
 * como el fallback que lo mantiene self-contained.
 *
 * Corre en DOS momentos: al indexar (DocumentBuilder, vía Action Scheduler /
 * WP-CLI / save_post) y, desde search 0.1.45, en el autocomplete vivo
 * (ProductPresenter::live_card, ≤14 tarjetas por petición del cliente, con
 * respuesta `no-store`). El costo de `resolve()` es de caché, no de SQL:
 * `wc_get_product` + `get_post_meta` sobre filas que ProductSourceAuthority ya
 * primó. No añadir consultas propias aquí sin volver a medir el suggest.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\Stock;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.109.0
 */
final class SearchAvailabilityBridge {

	/**
	 * Las dos options de Akibara → Textos que nombran un estado comercial.
	 *
	 * @var array<int, string>
	 */
	private const COPY_OPTIONS = array(
		'akb_copy_eta_single_pedido',
		'akb_copy_eta_single_preventa',
	);

	public function register(): void {
		add_filter( 'akb_search_availability_value', array( $this, 'resolve' ), 10, 2 );
		add_filter( 'akb_stock_status_label', array( $this, 'provide_label' ), 10, 2 );

		// La etiqueta se materializa al indexar las facetas. Un cambio de vocabulario
		// requiere reconstrucción para que buscador y tienda compartan la palabra vigente.

		foreach ( self::COPY_OPTIONS as $option ) {
			add_action( 'update_option_' . $option, array( $this, 'reindexar_vocabulario' ), 10, 0 );
		}
	}

	/**
	 * Solicita al buscador reconstruir las etiquetas de disponibilidad.
	 *
	 * La reconstrucción se prepara en tablas sombra para conservar la generación
	 * pública mientras se genera el índice nuevo.
	 *
	 * @internal Callback de `update_option_{$option}`.
	 * @return void
	 */
	public function reindexar_vocabulario(): void {
		if ( class_exists( '\Akibara\Search\RebuildJob' ) ) {
			\Akibara\Search\RebuildJob::schedule( true );
		}
	}

	/**
	 * Provee la palabra del estado de stock desde `StockStatus::LABELS`.
	 *
	 * Un estado que business no conoce devuelve el default de quien preguntó:
	 * el fail-closed de cada lado es suyo y no se le pisa.
	 *
	 * @param mixed $default_label Palabra que trae el llamador.
	 * @param mixed $status        Estado de stock crudo consultado.
	 * @return string
	 */
	public function provide_label( $default_label, $status = '' ): string {
		$status = is_string( $status ) ? $status : '';

		if ( isset( StockStatus::LABELS[ $status ] ) ) {
			return StockStatus::palabra( $status );
		}

		// «preventa» no es un `_stock_status` de WooCommerce: es la mitad de
		// `onbackorder` que este mismo puente separa, y el buscador la pregunta
		// por el mismo hook desde search 0.1.67.
		if ( Availability::PREVENTA === $status ) {
			return Availability::palabra( Availability::PREVENTA );
		}

		return (string) $default_label;
	}

	/**
	 * Resuelve el valor de la faceta de disponibilidad para un producto.
	 *
	 * @param mixed $default_value Valor por defecto del buscador ('a_pedido').
	 * @param mixed $post_id       ID del producto que se está indexando.
	 * @return string 'preventa' | 'a_pedido' (o el default si no hay producto).
	 */
	public function resolve( $default_value, $post_id ): string {
		$fallback = is_string( $default_value ) && '' !== $default_value ? $default_value : 'a_pedido';

		$product = function_exists( 'wc_get_product' ) ? wc_get_product( (int) $post_id ) : null;
		if ( ! $product instanceof \WC_Product ) {
			return $fallback;
		}

		return Availability::is_preventa( $product ) ? 'preventa' : 'a_pedido';
	}
}
