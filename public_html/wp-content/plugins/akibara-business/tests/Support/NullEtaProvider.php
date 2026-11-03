<?php
/**
 * Proveedor de ETA que nunca responde: para pruebas donde el cálculo no debe
 * consultarse (tipos `auto`) o donde da igual. No es final a propósito: un test
 * que necesita UNA respuesta lo extiende en línea. Vive aquí, y no dentro de un
 * test, para que ningún fichero dependa del ORDEN en que PHPUnit los carga.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Support;

use Akibara\Business\Contracts\EtaProvider;
use Akibara\Business\Modules\EstimatedDelivery\EtaResult;
use WC_Product;

class NullEtaProvider implements EtaProvider {

	public function calculate( WC_Product $product ): ?EtaResult {
		return null;
	}

	public function resolve_country( WC_Product $product ): ?string {
		return null;
	}

	public function get_country_name( string $slug ): ?string {
		return null;
	}
}
