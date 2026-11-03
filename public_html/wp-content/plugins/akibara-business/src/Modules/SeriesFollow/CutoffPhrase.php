<?php
/**
 * CutoffPhrase — la línea del corte dentro del correo, SIN aritmética propia y
 * SIN formateo de fechas.
 *
 * Este módulo no formatea ni una fecha a propósito: no tiene `dia_y_mes()`, ni
 * tabla de meses, ni cuenta de semanas. Todo sale de `PedidoPolicy`, que es
 * donde vive la aritmética de cortes de la casa. Una segunda cuenta
 * «equivalente» es lo que deja el correo nombrando un cierre distinto del que
 * la ficha imprime, y las dos mitades se leen correctas por separado.
 *
 * Tres guards, y el primero es el caso NORMAL
 * -------------------------------------------
 *   1. `null === $eta`: `EtaProvider::calculate()` devuelve null para todo
 *      producto que no sea `onbackorder`. Un tomo Disponible o Agotado —la
 *      mayoría del catálogo— llega aquí con null, y pasarlo a algo que tipa
 *      `EtaResult` sería un TypeError en mitad de la corrida del digest.
 *   2. `is_tba()`: el mismo guard, en el mismo orden, que usa el banner de la
 *      ficha. «Te avisamos en cuanto tengamos fecha» seguido de una fecha es el
 *      mensaje más contradictorio posible.
 *   3. `null === $ancla`: sin fecha calculada no hay cierre que nombrar.
 *
 * Con `''` el bloque de esa serie imprime SOLO el estado comercial y ninguna
 * fecha: ningún «pronto», ningún «consulta». El correo sale igual, porque la
 * noticia es que el tomo salió.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\SeriesFollow;

use Akibara\Business\Contracts\EtaProvider;
use Akibara\Business\Modules\EstimatedDelivery\PedidoPolicy;
use Akibara\Business\Utilities\StoreTime;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.110.2
 */
final class CutoffPhrase {

	/** @var EtaProvider */
	private EtaProvider $eta;

	/** @var PedidoPolicy */
	private PedidoPolicy $policy;

	/**
	 * @param EtaProvider  $eta    Calculador de fechas.
	 * @param PedidoPolicy $policy Resolución de la política de corte.
	 */
	public function __construct( EtaProvider $eta, PedidoPolicy $policy ) {
		$this->eta    = $eta;
		$this->policy = $policy;
	}

	/**
	 * «Sale en el pedido que cierra el 5 de octubre; desde ahí viaja 2–3
	 * semanas.», o '' cuando la política no sostiene ninguna fecha.
	 *
	 * @param WC_Product $product Tomo publicado.
	 * @return string
	 */
	public function for_product( WC_Product $product ): string {
		$eta = $this->eta->calculate( $product );
		if ( null === $eta ) {
			return '';
		}

		if ( $eta->is_tba() ) {
			return '';
		}

		$policy = $this->policy->for_product( $product );
		$ancla  = PedidoPolicy::ancla_de_tanda( $eta, $policy );
		if ( null === $ancla ) {
			return '';
		}

		return $this->policy->frase_de_tanda( $policy, $ancla, StoreTime::zone() );
	}
}
