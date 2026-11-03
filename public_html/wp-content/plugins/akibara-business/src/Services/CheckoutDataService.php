<?php
/**
 * Stable checkout data service for first-party adapters.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Services;

use Akibara\Business\Contracts\CheckoutDataProvider;
use Akibara\Business\Modules\AddressAutocomplete\AddressAutocomplete;
use Akibara\Business\Modules\EstimatedDelivery\BannerRenderer;
use Akibara\Business\Modules\FreeShippingProgress\FreeShippingProgress;
use Akibara\Business\Modules\OrderPreventa\PreventaUX;
use Akibara\Business\Contracts\EtaProvider;
use Akibara\Business\Modules\SeriesBundles\SeriesBundles;
use Akibara\Business\Modules\Stock\Availability;
use Akibara\Business\Modules\Stock\StockStatus;
use Akibara\Business\Utilities\ChileanPhone;
use Akibara\Business\Utilities\RutValidator;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Composes domain modules behind the public checkout contract.
 *
 * @since 1.95.0
 */
final class CheckoutDataService implements CheckoutDataProvider {

	private EtaProvider $eta_provider;

	private BannerRenderer $eta_renderer;

	private SeriesBundles $series_bundles;

	private FreeShippingProgress $free_shipping_progress;

	private AddressAutocomplete $address_autocomplete;

	/**
	 * Initializes the public service with the already-booted module instances.
	 *
	 * @param EtaProvider          $eta_provider           Canonical ETA provider.
	 * @param BannerRenderer       $eta_renderer           Canonical ETA formatter.
	 * @param SeriesBundles        $series_bundles         Bundle domain module.
	 * @param FreeShippingProgress $free_shipping_progress Free-shipping domain module.
	 * @param AddressAutocomplete  $address_autocomplete   Address configuration module.
	 *
	 * @since 1.95.0
	 */
	public function __construct(
		EtaProvider $eta_provider,
		BannerRenderer $eta_renderer,
		SeriesBundles $series_bundles,
		FreeShippingProgress $free_shipping_progress,
		AddressAutocomplete $address_autocomplete
	) {
		$this->eta_provider           = $eta_provider;
		$this->eta_renderer           = $eta_renderer;
		$this->series_bundles         = $series_bundles;
		$this->free_shipping_progress = $free_shipping_progress;
		$this->address_autocomplete   = $address_autocomplete;
	}

	/**
	 * Returns the renderer-agnostic cart and checkout presentation contract.
	 *
	 * @return array<string,mixed>
	 *
	 * @since 1.96.0
	 */
	public function structured_cart_data(): array {
		return array(
			'schema_version' => 2,
			'bundle'         => $this->series_bundles->structured_cart_presentation(),
			'free_shipping'  => $this->free_shipping_progress->structured_state(),
			'preventa'       => $this->structured_preventa_summary(),
			'trust_signals'  => CheckoutTrustSignals::all(),
		);
	}

	/**
	 * Returns canonical availability and ETA data for a product.
	 *
	 * @param WC_Product $product Product being presented.
	 * @return array<string,bool|int|string|null>
	 *
	 * @since 1.95.0
	 */
	public function product_availability( WC_Product $product ): array {
		$status      = StockStatus::of( $product );
		$is_preventa = StockStatus::is_reservable( $product );
		$eta         = $is_preventa ? $this->eta_provider->calculate( $product ) : null;
		$reservation = PreventaUX::reservation_prices( $product );
		$price_label = '';
		$price_note  = '';
		if ( null !== $reservation ) {
			$price_label = Availability::is_preventa( $product ) ? 'Precio de preventa' : 'Precio de reserva';
			$price_note  = (string) apply_filters(
				'akibara_preventa_price_note',
				sprintf( 'Al llegar el stock sube a %s', wp_strip_all_tags( wc_price( $reservation['regular'] ) ) ),
				$reservation['regular'],
				$reservation['sale'],
				$product
			);
			// El contrato es texto, no HTML: React debe recibir el símbolo de moneda.
			$price_note = html_entity_decode( wp_strip_all_tags( $price_note ), ENT_QUOTES, 'UTF-8' );
		}

		return array(
			'stock_status' => $status,
			// Palabra por ITEM → estado comercial (1.109.0): la línea del carrito
			// puede decir «A pedido» o «Preventa» según el caso concreto.
			'stock_label'  => Availability::label_of( $product ),
			// La clave conserva su nombre por contrato con el JS de blocks; su
			// semántica siempre fue «reservable» (onbackorder), no preventa real.
			'is_preventa'  => $is_preventa,
			'eta'           => null !== $eta ? $this->eta_renderer->format_eta( $eta ) : null,
			'eta_timestamp' => null !== $eta && ! $eta->is_tba() ? (int) $eta->max_ts() : null,
			'reservation_price_label' => $price_label,
			'reservation_price_note'  => $price_note,
		);
	}

	/**
	 * Validates a Chilean RUT.
	 *
	 * @param string $rut Raw RUT.
	 * @return bool
	 *
	 * @since 1.95.0
	 */
	public function is_valid_rut( string $rut ): bool {
		return RutValidator::is_valid( $rut );
	}

	/**
	 * Formats a Chilean RUT.
	 *
	 * @param string $rut Raw RUT.
	 * @return string
	 *
	 * @since 1.95.0
	 */
	public function format_rut( string $rut ): string {
		return RutValidator::format( $rut );
	}

	/**
	 * Returns the first validation error for a non-empty Chilean phone.
	 *
	 * @param string $phone Raw phone.
	 * @return array{code:string,message:string}|null
	 *
	 * @since 1.95.0
	 */
	public function phone_validation_error( string $phone ): ?array {
		return ChileanPhone::validation_error( $phone, false );
	}

	/**
	 * Returns the enabled Google Places configuration.
	 *
	 * @return array{enabled:bool,api_key:string,region_map:array<string,string>}
	 *
	 * @since 1.95.0
	 */
	public function address_autocomplete_config(): array {
		$enabled = $this->address_autocomplete->is_ready();

		return array(
			'enabled'    => $enabled,
			'api_key'    => $enabled ? $this->address_autocomplete->get_api_key() : '',
			'region_map' => $enabled ? $this->address_autocomplete->region_map() : array(),
		);
	}

	/**
	 * Builds a conservative cart-level preventa summary.
	 *
	 * @return array{
	 *   preventa_count:int,
	 *   preventa_is_mixed:bool,
	 *   preventa_summary_label:string,
	 *   preventa_summary_value:string,
	 *   preventa_has_tba:bool,
	 *   preventa_summary_nota:string,
	 *   preventa_all_real_preventa:bool
	 * }
	 */
	private function preventa_cart_summary(): array {
		$defaults = array(
			'preventa_count'         => 0,
			'preventa_is_mixed'      => false,
			'preventa_summary_label' => '',
			'preventa_summary_value' => '',
			'preventa_summary_nota'  => '',
			'preventa_has_tba'       => false,
			// Todas las líneas que se esperan son PREVENTAS (tomos que aún no
			// salen de la editorial), no «a pedido» (ya salió, sin stock nuestro).
			'preventa_all_real_preventa' => false,
		);

		if ( ! function_exists( 'WC' ) ) {
			return $defaults;
		}

		$cart = WC()->cart ?? null;
		if ( ! is_object( $cart ) || ! method_exists( $cart, 'get_cart' ) || ! method_exists( $cart, 'is_empty' ) || $cart->is_empty() ) {
			return $defaults;
		}

		$items             = $cart->get_cart();
		$preventa_count    = 0;
		$has_non_preventa  = false;
		$all_real_preventa = true;

		foreach ( is_array( $items ) ? $items : array() as $item ) {
			$product = $item['data'] ?? null;
			if ( ! $product instanceof WC_Product ) {
				continue;
			}

			if ( StockStatus::is_reservable( $product ) ) {
				$preventa_count += max( 1, (int) ( $item['quantity'] ?? 1 ) );
				if ( ! Availability::is_preventa( $product ) ) {
					$all_real_preventa = false;
				}
			} else {
				$has_non_preventa = true;
			}
		}

		if ( 0 === $preventa_count ) {
			return $defaults;
		}

		$summary = $this->eta_renderer->summarize_cart( is_array( $items ) ? $items : array() );
		if ( null === $summary ) {
			return array_merge(
				$defaults,
				array(
					'preventa_count'         => $preventa_count,
					'preventa_is_mixed'      => $has_non_preventa,
					'preventa_summary_label' => 'Tu pedido incluye reservas',
					'preventa_summary_value' => 'Te avisamos en cuanto tengamos fechas',
					'preventa_has_tba'       => true,
					'preventa_summary_nota'  => '',
					'preventa_all_real_preventa' => $all_real_preventa,
				)
			);
		}

		return array(
			'preventa_count'         => $preventa_count,
			'preventa_is_mixed'      => $has_non_preventa,
			'preventa_summary_label' => $summary['label'],
			'preventa_summary_value' => $summary['value'],
			'preventa_has_tba'       => $summary['is_tba'],
			'preventa_summary_nota'  => (string) ( $summary['nota'] ?? '' ),
			'preventa_all_real_preventa' => $all_real_preventa,
		);
	}

	/**
	 * Builds the structured preventa summary without presentation markup.
	 *
	 * @return array{
	 *   count:int,
	 *   is_mixed:bool,
	 *   summary_label:string,
	 *   summary_value:string,
	 *   has_tba:bool,
	 *   explanation:string
	 * }
	 */
	private function structured_preventa_summary(): array {
		$summary = $this->preventa_cart_summary();
		$count   = max( 0, (int) $summary['preventa_count'] );
		$mixed   = (bool) $summary['preventa_is_mixed'];

		$explanation = '';
		// La palabra sigue al contenido de líneas: Preventa para lanzamiento pendiente
		// y A pedido para obra publicada sin stock físico. Usar Availability, igual que
		// la etiqueta de cada línea, sin reducir un carrito mixto a un único supuesto.

		$a_pedido = mb_strtolower( Availability::label_for_state( Availability::A_PEDIDO ) );
		$preventa = mb_strtolower( Availability::label_for_state( Availability::PREVENTA ) );
		if ( $count > 0 ) {
			$solo_preventa = (bool) ( $summary['preventa_all_real_preventa'] ?? false );
			// La etiqueta la edita el dueño: se pluraliza sólo si no termina ya en «s».
			$preventas     = str_ends_with( $preventa, 's' ) ? $preventa : $preventa . 's';
			$espera        = $solo_preventa
				? ( 1 === $count ? sprintf( 'tu %s', $preventa ) : sprintf( 'tus %s', $preventas ) )
				: ( 1 === $count ? sprintf( 'el producto %s', $a_pedido ) : sprintf( 'los productos %s', $a_pedido ) );
			$explanation   = $mixed
				? sprintf(
					'Tu pedido combina productos disponibles y %s. Te lo despachamos completo cuando llegue todo.',
					$solo_preventa
						? ( 1 === $count ? 'una ' . $preventa : $preventas )
						: ( 1 === $count ? 'uno ' . $a_pedido : 'otros ' . $a_pedido )
				)
				: sprintf( 'Te lo despachamos cuando %s %s.', 1 === $count ? 'llegue' : 'lleguen', $espera );

			// La nota del corte se concatena a la explicación en vez de viajar en
			// su propia clave, y es una decisión, no una economía: el esquema de
			// esta carga lo declara el adaptador de bloques (`StoreApiIntegration`),
			// que enumera las propiedades permitidas. Una clave nueva aquí llega al
			// checkout como un campo que el esquema no conoce y que ningún
			// componente pinta — o sea, el dato se pierde exactamente igual que
			// antes, pero ahora con la apariencia de estar resuelto. `explanation`
			// ya está declarada y ya se pinta, así que la frase LLEGA.
			$nota = self::plain_text( (string) $summary['preventa_summary_nota'] );
			if ( '' !== $nota ) {
				$explanation .= ' ' . $nota;
			}
		}

		return array(
			'count'         => $count,
			'is_mixed'      => $mixed,
			'summary_label' => self::plain_text( (string) $summary['preventa_summary_label'] ),
			'summary_value' => self::plain_text( (string) $summary['preventa_summary_value'] ),
			'has_tba'       => (bool) $summary['preventa_has_tba'],
			'explanation'   => $explanation,
		);
	}

	/**
	 * Normalizes dynamic copy to a single plain-text line.
	 *
	 * @param string $text Raw copy.
	 * @return string
	 */
	private static function plain_text( string $text ): string {
		$decoded = html_entity_decode( strip_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$plain   = preg_replace( '/\s+/u', ' ', str_replace( "\xc2\xa0", ' ', $decoded ) );

		return trim( is_string( $plain ) ? $plain : '' );
	}
}
