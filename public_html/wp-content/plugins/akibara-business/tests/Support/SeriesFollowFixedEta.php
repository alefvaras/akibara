<?php
/**
 * Proveedor de ETA con respuesta fija, para las suites de «Sigue esta serie».
 *
 * El default es `null` porque ÉSE es el caso normal en producción:
 * `DateCalculator::calculate()` devuelve null para todo producto que no sea
 * `onbackorder`, o sea para cualquier tomo Disponible o Agotado.
 *
 * `$revienta` modela la otra mitad: un fallo real dentro del cálculo (una
 * lectura de term-meta caída, por ejemplo) tiene que dejar las filas en
 * `pending`, nunca en `sent` sin correo.
 *
 * Vive en `Support` y no dentro de un fichero de test porque lo consumen tres
 * suites: declararlo en una de ellas haría que las otras dos pasaran sólo por
 * contagio de orden.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Support;

use Akibara\Business\Contracts\EtaProvider;
use Akibara\Business\Modules\EstimatedDelivery\EtaResult;
use WC_Product;

/**
 * @since 1.110.2
 */
final class SeriesFollowFixedEta implements EtaProvider {

	private ?EtaResult $result;

	private ?string $revienta;

	/**
	 * @param EtaResult|null $result   Resultado a devolver.
	 * @param string|null    $revienta Mensaje de la excepción a lanzar, o null.
	 */
	public function __construct( ?EtaResult $result = null, ?string $revienta = null ) {
		$this->result   = $result;
		$this->revienta = $revienta;
	}

	/**
	 * @param WC_Product $product Producto.
	 * @return EtaResult|null
	 */
	public function calculate( WC_Product $product ): ?EtaResult {
		unset( $product );

		if ( null !== $this->revienta ) {
			throw new \RuntimeException( $this->revienta );
		}

		return $this->result;
	}

	/**
	 * @param WC_Product $product Producto.
	 * @return string|null
	 */
	public function resolve_country( WC_Product $product ): ?string {
		unset( $product );
		return 'argentina';
	}

	/**
	 * @param string $slug Slug del término.
	 * @return string|null
	 */
	public function get_country_name( string $slug ): ?string {
		unset( $slug );
		return 'Argentina';
	}
}
