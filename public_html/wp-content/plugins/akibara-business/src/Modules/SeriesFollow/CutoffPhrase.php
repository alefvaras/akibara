<?php
/**
 * Frase de corte para correos de seguimiento de series.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\SeriesFollow;

use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.110.2
 * @api
 */
class CutoffPhrase {

	/**
	 * @param mixed $eta
	 * @param mixed $policy
	 */
	public function __construct( $eta = null, $policy = null ) {
		unset( $eta, $policy );
	}

	/**
	 * Retorna cadena vacía ya que todo el catálogo se comercializa con stock inmediato o seguimiento.
	 *
	 * @param WC_Product $product Tomo publicado.
	 * @return string
	 */
	public function for_product( WC_Product $product ): string {
		unset( $product );
		return '';
	}
}
