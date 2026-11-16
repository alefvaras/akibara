<?php
/**
 * Completa Product/Offer de Rank Math con la política de devoluciones.
 *
 * La política aparece en la entidad global y en cada Offer con el mismo @id,
 * para que una ficha individual contenga una referencia resoluble y coherente.
 * Rank Math conserva la propiedad de los nodos comerciales.
 *
 * No publica tarifas generales ni shippingRate cero: BlueX cotiza según destino
 * y compra, y el envío gratis sobre $60.000 usa el umbral condicional de la opción
 * akibara_fsp_carrier_threshold. Una cifra promedio o una tarifa gratuita
 * incondicional falsearía compras bajo ese umbral. Las condiciones efectivas se
 * administran en WooCommerce y Merchant Center.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\ProductSchemaShopping;

use Akibara\Business\Modules\Stock\Availability;
use Akibara\Business\Utilities\SchemaGraph;
use Akibara\Business\Utilities\StoreTime;

defined( 'ABSPATH' ) || exit;

/**
 * @api
 * @psalm-suppress HookNotFound
 */
final class ProductSchemaShopping {

	private const DEFAULT_RETURN_DAYS = 10;
	private const COUNTRY = 'CL';

	public function register(): void {
		// Rank Math es la fuente única de Organization/OnlineStore. La política
		// estándar se declara una sola vez en la portada, donde vive esa entidad.
		add_filter( 'rank_math/json_ld', array( $this, 'handle_rank_math_json_ld' ), SchemaGraph::PRODUCT_OFFERS, 2 );
	}

	/**
	 * Adjunta la política estándar a la entidad global de Rank Math.
	 *
	 * Se limita a la portada, donde Rank Math publica Organization/OnlineStore.
	 * La política enlaza a /devoluciones/ y no se repite dentro de cada Offer.
	 *
	 * @internal Callback del hook `rank_math/json_ld`.
	 *
	 * @param array<string,mixed> $data   Nodos JSON-LD de Rank Math.
	 * @param mixed               $jsonld Instancia interna de Rank Math, no usada.
	 * @return array<string,mixed>
	 */
	public function handle_rank_math_json_ld( array $data, $jsonld = null ): array {
		unset( $jsonld );

		if ( is_front_page() ) {
			return $this->attach_policy_to_organization( $data );
		}

		if ( function_exists( 'is_product' ) && is_product() ) {
			return $this->attach_policy_to_product_offers( $data );
		}

		return $data;
	}

	/**
	 * Portada: la política cuelga de la entidad comercial global.
	 *
	 * @param array<string,mixed> $data Nodos JSON-LD de Rank Math.
	 * @return array<string,mixed>
	 */
	private function attach_policy_to_organization( array $data ): array {
		$policy = $this->build_merchant_return_policy();

		foreach ( $data as &$node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}

			if ( SchemaGraph::node_has_type( $node, 'OnlineStore' ) || SchemaGraph::node_has_type( $node, 'Organization' ) ) {
				$node['hasMerchantReturnPolicy'] = $policy;
				unset( $node );
				return $data;
			}
		}
		unset( $node );

		// Rank Math conserva la propiedad exclusiva de la entidad comercial.
		// Si su Organization/OnlineStore no existe, la configuración se corrige
		// desde su UI; este módulo nunca crea un emisor paralelo.
		return $data;
	}

	/**
	 * Ficha de producto: la MISMA política, dentro del Offer.
	 *
	 * Se respeta la propiedad de Rank Math sobre el nodo: sólo se añade la
	 * clave si el Offer existe y aún no la trae (nunca se pisa un valor ajeno).
	 * `offers` puede ser un objeto único o una lista (productos variables).
	 *
	 * @param array<string,mixed> $data Nodos JSON-LD de Rank Math.
	 * @return array<string,mixed>
	 */
	private function attach_policy_to_product_offers( array $data ): array { // @phpstan-ignore complexity.functionLike
		$policy = $this->build_merchant_return_policy();

		foreach ( $data as &$node ) {
			if ( ! is_array( $node ) || ! SchemaGraph::node_has_type( $node, 'Product' ) ) {
				continue;
			}

			$node = $this->humanize_additional_properties( $node );

			$offers = $node['offers'] ?? null;
			if ( ! is_array( $offers ) ) {
				continue;
			}

			if ( isset( $offers['@type'] ) ) {
				$offers = $this->sanitize_shipping_details( $this->fix_price_specification( $offers ) );
			} else {
				foreach ( $offers as &$each ) {
					if ( is_array( $each ) ) {
						$each = $this->sanitize_shipping_details( $this->fix_price_specification( $each ) );
					}
				}
				unset( $each );
			}
			$node['offers'] = $offers;

			// Objeto único (tiene '@type') vs lista de ofertas.
			if ( isset( $offers['@type'] ) ) {
				if ( ! isset( $offers['hasMerchantReturnPolicy'] ) ) {
					$offers['hasMerchantReturnPolicy'] = $policy;
				}
				$node['offers'] = $offers;
				continue;
			}

			foreach ( $offers as &$offer ) {
				if ( is_array( $offer ) && ! isset( $offer['hasMerchantReturnPolicy'] ) ) {
					$offer['hasMerchantReturnPolicy'] = $policy;
				}
			}
			unset( $offer );
			$node['offers'] = $offers;
		}
		unset( $node );

		return $data;
	}

	/**
	 * Política estándar publicada en /devoluciones/.
	 *
	 * Los costos distinguen arrepentimiento del cliente (a su cargo) de error o
	 * defecto atribuible a Akibara (sin costo), de acuerdo con la política visible.
	 *
	 * @return array<string,mixed>
	 */
	private function build_merchant_return_policy(): array {
		$days = (int) apply_filters( 'akibara/schema_shopping/return_days', self::DEFAULT_RETURN_DAYS ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores

		return array(
			'@type'                             => 'MerchantReturnPolicy',
			'@id'                               => home_url( '/devoluciones/#merchant-return-policy' ),
			'applicableCountry'                 => self::COUNTRY,
			'returnPolicyCountry'               => self::COUNTRY,
			'returnPolicyCategory'              => 'https://schema.org/MerchantReturnFiniteReturnWindow',
			'merchantReturnDays'                => $days,
			'merchantReturnLink'                => home_url( '/devoluciones/' ),
			'itemCondition'                     => array(
				'https://schema.org/NewCondition',
				'https://schema.org/DamagedCondition',
			),
			'returnMethod'                      => 'https://schema.org/ReturnByMail',
			'refundType'                        => 'https://schema.org/FullRefund',
			'returnFees'                        => 'https://schema.org/ReturnFeesCustomerResponsibility',
			'customerRemorseReturnFees'         => 'https://schema.org/ReturnFeesCustomerResponsibility',
			'itemDefectReturnFees'              => 'https://schema.org/FreeReturn',
		);
	}

	/**
	 * Elimina el precio activo duplicado o declara el precio de lista real.
	 *
	 * Si Offer.price ya contiene el precio activo, otro priceSpecification idéntico
	 * no aporta información. Sin rebaja se elimina ese duplicado. Con rebaja se usa
	 * UnitPriceSpecification y priceType ListPrice con el precio regular leído de
	 * WooCommerce, únicamente si supera el precio activo. No se infieren importes.
	 *
	 * @param array<string,mixed> $offer Nodo Offer de Rank Math.
	 * @return array<string,mixed>
	 */
	private function fix_price_specification( array $offer ): array {
		// priceValidUntil usa una ventana móvil de30días recalculada al renderizar,
		// para no emitir una fecha vencida ni prometer un precio estable durante años.

		if ( ! isset( $offer['priceValidUntil'] ) ) {
			$offer['priceValidUntil'] = StoreTime::format( time() + ( 30 * DAY_IN_SECONDS ), 'Y-m-d' );
		}

		if ( ! isset( $offer['priceSpecification'] ) ) {
			return $offer;
		}

		unset( $offer['priceSpecification'] );

		if ( ! function_exists( 'wc_get_product' ) ) {
			return $offer;
		}

		$product = wc_get_product( get_the_ID() );
		if ( ! $product || ! $product->is_on_sale() ) {
			return $offer;
		}

		$regular = (float) $product->get_regular_price();
		$active  = (float) ( $offer['price'] ?? 0 );
		if ( $regular <= 0.0 || $active <= 0.0 || $regular <= $active ) {
			return $offer;
		}

		$offer['priceSpecification'] = array(
			'@type'                 => 'UnitPriceSpecification',
			'priceType'             => 'https://schema.org/ListPrice',
			'price'                 => (string) $regular,
			'priceCurrency'         => (string) ( $offer['priceCurrency'] ?? get_woocommerce_currency() ),
			'valueAddedTaxIncluded' => true,
		);

		return $offer;
	}

	/**
	 * Defensivo contra tarifas de envío cero inventadas en productos bajo el umbral de envío gratis.
	 *
	 * @param array<string,mixed> $offer Nodo Offer.
	 * @return array<string,mixed>
	 */
	private function sanitize_shipping_details( array $offer ): array {
		if ( ! isset( $offer['shippingDetails'] ) ) {
			return $offer;
		}

		$price = (float) ( $offer['price'] ?? 0 );
		$free_shipping_threshold = (float) (int) get_option( 'akibara_fsp_carrier_threshold', 60000 );
		if ( $free_shipping_threshold <= 0.0 ) {
			$free_shipping_threshold = 60000.0;
		}

		if ( $price < $free_shipping_threshold ) {
			$details = $offer['shippingDetails'];
			if ( is_array( $details ) ) {
				$rate_value = $details['shippingRate']['value'] ?? $details['shippingRate']['price'] ?? null;
				if ( '0' === (string) $rate_value || 0.0 === (float) $rate_value ) {
					unset( $offer['shippingDetails'] );
				}
			}
		}

		return $offer;
	}

	/**
	 * Traduce los slugs internos de WooCommerce a etiquetas legibles.
	 *
	 * Rank Math publica los atributos como `name: "pa_pais"`, `"pa_autor"`,
	 * `"pa_genero"`… Eso es el nombre de la taxonomía, no el de la propiedad: un
	 * humano y un modelo leen «pa_pais» y no aprenden nada. WooCommerce ya guarda
	 * la etiqueta real («País», «Autor», «Género») y `wc_attribute_label()` la
	 * devuelve, así que esto es «falta marcarlo», no «falta el dato».
	 *
	 * @param array<string,mixed> $node Nodo Product.
	 * @return array<string,mixed>
	 */
	private function humanize_additional_properties( array $node ): array {
		if ( ! isset( $node['additionalProperty'] ) || ! is_array( $node['additionalProperty'] ) ) {
			return $node;
		}
		if ( ! function_exists( 'wc_attribute_label' ) ) {
			return $node;
		}

		foreach ( $node['additionalProperty'] as &$prop ) {
			if ( ! is_array( $prop ) || ! isset( $prop['name'] ) || ! is_string( $prop['name'] ) ) {
				continue;
			}
			if ( 0 !== strpos( $prop['name'], 'pa_' ) ) {
				continue; // Ya es legible: no se toca.
			}

			$label = wc_attribute_label( $prop['name'] );
			if ( is_string( $label ) && '' !== $label && 0 !== strpos( $label, 'pa_' ) ) {
				$prop['name'] = $label;
			}
		}
		unset( $prop );

		return $node;
	}

	/**
	 * @param array<string,mixed> $node Nodo JSON-LD.
	 */
}
