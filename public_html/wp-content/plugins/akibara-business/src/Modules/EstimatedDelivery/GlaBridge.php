<?php
/**
 * Puente ETA al feed Google Listings & Ads para productos onbackorder sin
 * fecha manual. Usa woocommerce_gla_product_attribute_values, que permite
 * añadir atributos ausentes; el filtro singular sólo procesa claves existentes.
 * Content API y Merchant API reciben el resultado mediante el array de
 * atributos, sin mutar métodos específicos del adaptador.
 * Respeta la fecha manual y las claves ya inyectadas. Para Sin promesa de
 * fecha aplica Settings::google_sin_promesa(): out_of_stock, próximo pedido
 * o no modificar. Si falta ETA o es TBA no inventa fecha. Con rango, publica
 * max_ts como límite conservador de disponibilidad.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\EstimatedDelivery;

use Akibara\Business\Modules\Stock\StockStatus;
use Akibara\Business\Utilities\StoreTime;

defined( 'ABSPATH' ) || exit;

/**
 * Inyecta availabilityDate al feed GLA desde el cálculo ETA Akibara.
 *
 * @since 1.13.0
 */
final class GlaBridge {

	private DateCalculator $calculator;

	private Settings $settings;

	/**
	 * @param DateCalculator $calculator Calculadora de fechas.
	 * @param Settings|null  $settings   Ajustes de Entregas (inyectable en pruebas).
	 */
	public function __construct( DateCalculator $calculator, ?Settings $settings = null ) {
		$this->calculator = $calculator;
		$this->settings   = $settings ?? new Settings();
	}

	public function register(): void {
		add_filter(
			'woocommerce_gla_product_attribute_values',
			array( $this, 'inject_availability_date' ),
			10,
			3
		);
	}

	/**
	 * Filter callback. Agrega `availabilityDate` (o `availability`, para los
	 * «sin promesa» en modo agotado) al array de overrides cuando el producto
	 * cumple condiciones y no hay valor manual previo.
	 *
	 * @param mixed $attributes Array de attributes (puede venir vacío).
	 * @param mixed $product    WC_Product.
	 * @param mixed $adapter    WCProductAdapter o WCProductInputAdapter (firma
	 *                          laxa porque el tipo upstream no está bajo
	 *                          nuestro control; sólo se consulta, no se muta).
	 * @return mixed Array de attributes (con o sin clave agregada).
	 */
	public function inject_availability_date( $attributes, $product, $adapter ) {
		if ( ! is_array( $attributes ) ) {
			$attributes = array();
		}

		if ( ! $product instanceof \WC_Product ) {
			return $attributes;
		}

		if ( ! StockStatus::is_reservable( $product ) ) {
			return $attributes;
		}

		if ( ! empty( $attributes['availabilityDate'] ) ) {
			return $attributes;
		}

		if ( $this->manual_date_present( $adapter ) ) {
			return $attributes;
		}

		// Para Sin promesa de fecha, el panel Entregas decide entre out_of_stock,
		// fecha del próximo pedido o no modificar el feed. El array devuelto
		// sobrescribe el mapeo previo de GLA en ambos adaptadores.
		$sin_promesa = $this->calculator->is_sin_promesa( $product );
		if ( $sin_promesa ) {
			$modo = $this->settings->google_sin_promesa();
			if ( 'leave' === $modo ) {
				return $attributes;
			}
			if ( 'out_of_stock' === $modo ) {
				$attributes['availability'] = 'out_of_stock';
				return $attributes;
			}
		}
		$eta = $this->calculator->calculate( $product, $sin_promesa );
		if ( null === $eta || $eta->is_tba() ) {
			return $attributes;
		}

		// max_ts() está tipado ?int; el path !is_tba() de arriba garantiza no-null
		// (solo EtaResult::range() deja tba=false y exige un int), pero mantenemos el
		// guard como narrowing defensivo del analizador y por si el invariante cambia.
		$max_ts = $eta->max_ts();
		if ( null === $max_ts ) {
			return $attributes;
		}

		$tz = StoreTime::zone();

		$attributes['availabilityDate'] = ( new \DateTimeImmutable( '@' . $max_ts ) )
			->setTimezone( $tz )
			->format( 'c' );

		return $attributes;
	}

	/**
	 * Detecta una fecha manual de disponibilidad ya mapeada por GLA. Content API
	 * la expone mediante getAvailabilityDate(); Merchant API mediante los
	 * atributos del ProductInput. Ambos canales deben respetarse.
	 *
	 * @param mixed $adapter Tercer argumento del filtro (firma laxa).
	 */
	private function manual_date_present( $adapter ): bool {
		if ( ! is_object( $adapter ) ) {
			return false;
		}

		if ( method_exists( $adapter, 'getAvailabilityDate' ) && ! empty( $adapter->getAvailabilityDate() ) ) {
			return true;
		}

		if ( ! method_exists( $adapter, 'get_product_input' ) ) {
			return false;
		}

		$input = $adapter->get_product_input();
		if ( ! is_object( $input ) || ! method_exists( $input, 'get_attributes' ) ) {
			return false;
		}

		$mapped = $input->get_attributes();

		return is_array( $mapped ) && ! empty( $mapped['availabilityDate'] );
	}
}
