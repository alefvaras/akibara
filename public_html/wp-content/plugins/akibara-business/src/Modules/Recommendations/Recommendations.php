<?php
/**
 * Recomendaciones de producto: relacionados, upsells de la serie y los ajustes
 * de la pestaña «Recomendaciones» que los gobiernan.
 *
 * El relleno diario de upsells sigue en {@see \Akibara\Business\Modules\UpsellsAutofill\UpsellsAutofill}
 * (qué tomos se GUARDAN); este módulo decide qué se MUESTRA y en qué orden.
 * Los «Más vendidos» de los estados vacíos (PopularProducts) leen su ajuste de
 * aquí mismo. Los títulos de los dos bloques ({@see Headings}) se editan en
 * Textos, con el resto de los copys.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\Recommendations;

defined( 'ABSPATH' ) || exit;

final class Recommendations {

	public function register(): void {
		( new Settings() )->register();
		( new RelatedProducts() )->register();
		( new UpsellsDisplay() )->register();
		( new Headings() )->register();
	}
}
