<?php
/**
 * Plazo de entrega de Blue Express en el checkout de bloques.
 *
 * @package Akibara\CartCheckoutBlocks
 */

declare( strict_types = 1 );

namespace Akibara\CartCheckoutBlocks;

use Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema;
use WC_Product;
use WC_Shipping_Rate;

defined( 'ABSPATH' ) || exit;

/**
 * Lleva al checkout de bloques la promesa que Blue Express calcula por comuna.
 *
 * Entrega de compromisos y plazos de despacho en checkout Blocks.
 *
 * Publica en el Store API los plazos de entrega calculados para tarifas de Blue Express,
 * integrando los días hábiles habituales por comuna cuando son inferiores al compromiso máximo declarado.
 *
 * Casos mixtos con reserva/preventa:
 * Si el carrito contiene productos en preventa o a pedido, el plazo se señala condicionado
 * a la llegada del stock a bodega (`pending_stock = true`).
 *
 * @since 2.2.27
 */
final class ShippingPromise {

	public const NAMESPACE = 'akibara_shipping_promise';

	public const VERSION = 1;

	/** Largo máximo del texto que se acepta de Blue. */
	private const MAX_LENGTH = 80;

	/**
	 * Días hábiles habituales por comuna validados desde cotizador oficial,
	 * aplicados únicamente cuando son inferiores a la plantilla base.
	 * Clave: `ComunaDirectory::normalize()` del nombre del catálogo de Blue.
	 *
	 * @var array<string,int>
	 */
	// phpcs:disable WordPress.Arrays.ArrayDeclarationSpacing, WordPress.Arrays.MultipleStatementAlignment -- tabla de datos compacta.
	private const TYPICAL_DAYS = array(
		'alhue' => 2, 'alto del carmen' => 3, 'ancud' => 2, 'aysen' => 11,
		'buin' => 1, 'calera de tango' => 1, 'camina' => 5, 'castro' => 2,
		'cerrillos' => 1, 'cerro navia' => 1, 'cholchol' => 3, 'chonchi' => 3,
		'cochamo' => 4, 'conchali' => 1, 'corral' => 3, 'curacavi' => 1,
		'curaco de velez' => 4, 'dalcahue' => 3, 'el bosque' => 1, 'el monte' => 1,
		'estacion central' => 1, 'hualaihue' => 4, 'huechuraba' => 1, 'independencia' => 1,
		'isla de maipo' => 1, 'la cisterna' => 1, 'la estrella' => 2, 'la florida' => 1,
		'la granja' => 1, 'la pintana' => 1, 'la reina' => 1, 'lampa' => 1,
		'las condes' => 1, 'lo barnechea' => 1, 'lo espejo' => 1, 'lo prado' => 1,
		'macul' => 1, 'maipu' => 1, 'maria pinto' => 2, 'nunoa' => 1,
		'padre hurtado' => 1, 'paiguano' => 4, 'paine' => 1, 'panguipulli' => 4,
		'pedro aguirre cerda' => 1, 'penaflor' => 1, 'penalolen' => 1, 'pirque' => 1,
		'providencia' => 1, 'pudahuel' => 1, 'puente alto' => 1, 'puqueldon' => 4,
		'queilen' => 4, 'quellon' => 4, 'quemchi' => 3, 'quilicura' => 1,
		'quinchao' => 4, 'quinta normal' => 1, 'recoleta' => 1, 'renca' => 1,
		'retiro' => 3, 'san bernardo' => 1, 'san joaquin' => 1, 'san jose de maipo' => 1,
		'san miguel' => 1, 'san pedro de atacama' => 3, 'san ramon' => 1, 'santiago' => 1,
		'talagante' => 1, 'tiltil' => 2, 'vitacura' => 1,
	);
	// phpcs:enable WordPress.Arrays.ArrayDeclarationSpacing, WordPress.Arrays.MultipleStatementAlignment

	private BlocksPage $blocks_page;

	private bool $registered = false;

	/**
	 * @param BlocksPage $blocks_page Detector de páginas de bloques.
	 */
	public function __construct( BlocksPage $blocks_page ) {
		$this->blocks_page = $blocks_page;
	}

	/**
	 * Engancha el Store API y el bundle.
	 */
	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ), 20 );

		if ( did_action( 'woocommerce_blocks_loaded' ) ) {
			$this->register_store_api_data();
			return;
		}

		add_action( 'woocommerce_blocks_loaded', array( $this, 'register_store_api_data' ) );
	}

	/**
	 * Registra la extensión del carrito una sola vez.
	 *
	 * @internal
	 */
	public function register_store_api_data(): void {
		if (
			$this->registered
			|| ! class_exists( CartSchema::class )
			|| ! function_exists( 'woocommerce_store_api_register_endpoint_data' )
		) {
			return;
		}

		woocommerce_store_api_register_endpoint_data(
			array(
				'endpoint'        => CartSchema::IDENTIFIER,
				'namespace'       => self::NAMESPACE,
				'data_callback'   => array( $this, 'cart_data' ),
				'schema_callback' => array( $this, 'cart_schema' ),
				'schema_type'     => ARRAY_A,
			)
		);

		$this->registered = true;
	}

	/**
	 * Esquema de la extensión.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function cart_schema(): array {
		return array(
			'version'       => array(
				'description' => 'Versión del contrato del plazo de entrega.',
				'type'        => 'integer',
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
			),
			'rates'         => array(
				'description'          => 'Plazo de Blue Express por rate_id.',
				'type'                 => 'object',
				'additionalProperties' => array( 'type' => 'string' ),
				'context'              => array( 'view', 'edit' ),
				'readonly'             => true,
			),
			'pending_stock' => array(
				'description' => 'El carrito lleva productos que aún no están en bodega.',
				'type'        => 'boolean',
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
			),
			'typical'       => array(
				'description'          => 'Días hábiles habituales por rate_id, sólo cuando son menos que el máximo de Blue.',
				'type'                 => 'object',
				'additionalProperties' => array( 'type' => 'integer' ),
				'context'              => array( 'view', 'edit' ),
				'readonly'             => true,
			),
		);
	}

	/**
	 * Datos de la extensión para la respuesta actual del carrito.
	 *
	 * El Store API arma `shipping_rates` antes que `extensions` en la misma
	 * respuesta, así que los paquetes de `WC()->shipping()` ya traen las
	 * tarifas de esta petición (también cuando salieron de la caché de sesión,
	 * que es cuando el filtro `woocommerce_package_rates` NO corre: por eso no
	 * se recolecta ahí).
	 *
	 * @return array{version:int,rates:object,pending_stock:bool,typical:object}
	 */
	public function cart_data(): array {
		$packages = array();
		if ( function_exists( 'WC' ) && WC() && WC()->shipping() ) {
			$packages = (array) WC()->shipping()->get_packages();
		}

		return array(
			'version'       => self::VERSION,
			'rates'         => (object) self::rates_from_packages( $packages ),
			'pending_stock' => self::cart_has_pending_stock(),
			'typical'       => (object) self::typical_from_packages( $packages ),
		);
	}

	/**
	 * `rate_id → días habituales` para las tarifas Blue de comunas donde lo
	 * habitual es menos que el máximo que Blue publica.
	 *
	 * @param array<int|string,mixed> $packages Paquetes de WC_Shipping.
	 * @return array<string,int>
	 */
	public static function typical_from_packages( array $packages ): array {
		$map = array();

		foreach ( $packages as $package ) {
			if ( ! is_array( $package ) ) {
				continue;
			}
			$city    = $package['destination']['city'] ?? '';
			$typical = self::typical_days_for( is_string( $city ) ? $city : '' );
			if ( null === $typical ) {
				continue;
			}

			foreach ( self::rates_from_packages( array( $package ) ) as $rate_id => $text ) {
				// Si Blue ya da un rango («Desde 5 Hasta 7…»), no se añade lo habitual.
				if ( false !== stripos( $text, 'desde' ) ) {
					continue;
				}
				$max = self::max_days( $text );
				if ( null !== $max && $typical < $max ) {
					$map[ $rate_id ] = $typical;
				}
			}
		}

		return $map;
	}

	/**
	 * Días habituales de una comuna, o null si no hay dato.
	 *
	 * @param string $city Comuna tal como la guardó el checkout.
	 */
	public static function typical_days_for( string $city ): ?int {
		$key = class_exists( ComunaDirectory::class )
			? ComunaDirectory::normalize( $city )
			: self::fallback_normalize( $city );

		return self::TYPICAL_DAYS[ $key ] ?? null;
	}

	/**
	 * Máximo de días que dice el texto de Blue: el número tras «hasta»
	 * («Desde 5 Hasta 7 días hábiles» → 7) o, si no lo hay, el último número.
	 *
	 * @param string $text Texto ya limpio.
	 */
	public static function max_days( string $text ): ?int {
		if ( preg_match( '/hasta\s+(\d+)/iu', $text, $m ) ) {
			$days = (int) $m[1];
		} elseif ( preg_match_all( '/\d+/', $text, $all ) && ! empty( $all[0] ) ) {
			$days = (int) end( $all[0] );
		} else {
			return null;
		}

		return $days > 0 && $days <= 60 ? $days : null;
	}

	/**
	 * Respaldo si el directorio de comunas no estuviera cargado: misma regla
	 * (sin tildes, minúsculas, espacios colapsados).
	 *
	 * @param string $value Comuna.
	 */
	private static function fallback_normalize( string $value ): string {
		// phpcs:disable WordPress.Arrays.ArrayDeclarationSpacing, WordPress.Arrays.MultipleStatementAlignment -- mapa compacto.
		$value = strtr(
			$value,
			array(
				'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'Ü' => 'u', 'Ñ' => 'n',
				'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
			)
		);
		// phpcs:enable WordPress.Arrays.ArrayDeclarationSpacing, WordPress.Arrays.MultipleStatementAlignment
		$value = (string) preg_replace( '/\s+/', ' ', $value );

		return trim( strtolower( trim( $value ) ), " .,;'\"" );
	}

	/**
	 * Extrae `rate_id → plazo` de los paquetes ya calculados.
	 *
	 * @param array<int|string,mixed> $packages Paquetes de WC_Shipping.
	 * @return array<string,string>
	 */
	public static function rates_from_packages( array $packages ): array {
		$map = array();

		foreach ( $packages as $package ) {
			$rates = is_array( $package ) && is_array( $package['rates'] ?? null ) ? $package['rates'] : array();
			foreach ( $rates as $rate_id => $rate ) {
				if ( ! $rate instanceof WC_Shipping_Rate ) {
					continue;
				}
				if ( 0 !== strpos( (string) $rate->get_method_id(), 'bluex-' ) ) {
					continue;
				}
				$meta = $rate->get_meta_data();
				$text = self::clean( $meta['_delivery_forecast'] ?? '' );
				if ( '' !== $text ) {
					$map[ (string) $rate_id ] = $text;
				}
			}
		}

		return $map;
	}

	/**
	 * Normaliza el texto que devuelve Blue.
	 *
	 * Blue devuelve el plazo ya redactado. Cuando su cotizador falla, el plugin
	 * rellena `PrazoEntrega = '0'`: eso NO es «llega hoy», es «no sé», y se
	 * descarta. Si algún día llega un número a secas, se redacta igual que Blue.
	 *
	 * @param mixed $raw Valor de `_delivery_forecast`.
	 */
	public static function clean( $raw ): string {
		if ( ! is_scalar( $raw ) ) {
			return '';
		}

		$text = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $raw ) ) ?? '' );
		if ( '' === $text ) {
			return '';
		}

		if ( preg_match( '/^\d+$/', $text ) ) {
			$days = (int) $text;
			if ( $days <= 0 || $days > 60 ) {
				return '';
			}
			return 1 === $days ? 'Hasta 1 día hábil' : sprintf( 'Hasta %d días hábiles', $days );
		}

		if ( ! preg_match( '/\d/', $text ) ) {
			return '';
		}

		if ( function_exists( 'mb_strlen' ) ? mb_strlen( $text ) > self::MAX_LENGTH : strlen( $text ) > self::MAX_LENGTH ) {
			return '';
		}

		return $text;
	}

	/**
	 * ¿Lleva el carrito algo que aún no está en bodega?
	 *
	 * «A pedido» y «preventa» se venden como `onbackorder` (ver
	 * `StockStatus::is_reservable` en Business): el plazo de Blue empezará
	 * cuando llegue ese producto, no hoy.
	 */
	public static function cart_has_pending_stock(): bool {
		if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->cart ) {
			return false;
		}

		foreach ( (array) WC()->cart->get_cart() as $item ) {
			$product = $item['data'] ?? null;
			if ( $product instanceof WC_Product && 'onbackorder' === $product->get_stock_status() ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Encola el bundle sólo en el carrito y el checkout de bloques.
	 *
	 * @internal
	 */
	public function enqueue(): void {
		if ( ! $this->blocks_page->is_checkout() && ! $this->blocks_page->is_cart() ) {
			return;
		}

		$relative    = 'assets/js/shipping-promise.js';
		$script_path = AKIBARA_CART_CHECKOUT_BLOCKS_DIR . $relative;

		wp_enqueue_script(
			'akibara-shipping-promise',
			AKIBARA_CART_CHECKOUT_BLOCKS_URL . $relative,
			array( 'wc-blocks-data-store', 'wp-data' ),
			AKIBARA_CART_CHECKOUT_BLOCKS_VERSION . ( file_exists( $script_path ) ? '.' . filemtime( $script_path ) : '' ),
			true
		);
	}
}
