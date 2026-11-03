<?php
/**
 * Repositorio de reglas de la matriz de precios y descuentos A Pedido por editorial y escala.
 *
 * Persiste en la opción `akb_pricing_matrix_rules` indexada por `editorial_slug`
 * y `escala_precio_regular`, permitiendo exportar e importar JSON entre Local y Producción.
 *
 * @package Akibara\Business\Modules\PricingMatrix
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\PricingMatrix;

use WC_Product;

defined( 'ABSPATH' ) || exit;

final class PricingRulesRepository {

	public const OPTION_RULES = 'akb_pricing_matrix_rules';

	/**
	 * Devuelve la estructura completa y normalizada de reglas guardadas.
	 *
	 * @return array{version: int, updated_at: string, brands: array<string, array<string, mixed>>}
	 */
	public static function get_rules(): array {
		$raw = get_option( self::OPTION_RULES, array() );
		return self::normalize_rules_payload( is_array( $raw ) ? $raw : array() );
	}

	/**
	 * Guarda la estructura de reglas en `akb_pricing_matrix_rules` (autoload = no).
	 *
	 * @param array<string, mixed> $rules Reglas a guardar.
	 * @return bool
	 */
	public static function save_rules( array $rules ): bool {
		$normalized               = self::normalize_rules_payload( $rules );
		$normalized['updated_at'] = gmdate( 'c' );
		update_option( self::OPTION_RULES, $normalized, false );
		return true;
	}

	/**
	 * Actualiza o inserta una regla para una editorial y escala específica.
	 *
	 * @param string   $editorial_slug    Slug de la editorial (product_brand).
	 * @param int      $scale_regular     Escala de precio regular actual.
	 * @param int      $new_regular       Nuevo precio regular objetivo.
	 * @param float    $discount_pct      Porcentaje de descuento A Pedido.
	 * @param string   $rounding_mode     Modo de redondeo CLP.
	 * @param int|null $manual_sale_price Precio manual si rounding_mode === 'manual'.
	 * @return array{new_regular_price: int, sale_price: int, discount_pct: float, rounding_mode: string, manual_sale_price: int|null}
	 */
	public static function set_scale_rule(
		string $editorial_slug,
		int $scale_regular,
		int $new_regular,
		float $discount_pct = PricingMath::DEFAULT_DISCOUNT_PCT,
		string $rounding_mode = PricingMath::DEFAULT_MODE,
		?int $manual_sale_price = null
	): array {
		$editorial_slug = sanitize_title( $editorial_slug );
		$rules          = self::get_rules();

		if ( ! isset( $rules['brands'][ $editorial_slug ] ) || ! is_array( $rules['brands'][ $editorial_slug ] ) ) {
			$rules['brands'][ $editorial_slug ] = array(
				'discount_pct'       => $discount_pct,
				'rounding_mode'      => $rounding_mode,
				'backorders_enabled' => true,
				'scales'             => array(),
			);
		}

		$effective_reg = $new_regular > 0 ? $new_regular : $scale_regular;
		$sale_price    = PricingMath::calculate_sale_price( $effective_reg, $discount_pct, $rounding_mode, $manual_sale_price );

		$scale_entry = array(
			'regular_price'     => $scale_regular,
			'new_regular_price' => $effective_reg,
			'discount_pct'      => $discount_pct,
			'rounding_mode'     => $rounding_mode,
			'manual_sale_price' => $manual_sale_price,
			'sale_price'        => $sale_price,
		);

		$rules['brands'][ $editorial_slug ]['scales'][ (string) $scale_regular ] = $scale_entry;
		if ( $effective_reg !== $scale_regular ) {
			$rules['brands'][ $editorial_slug ]['scales'][ (string) $effective_reg ] = array_merge(
				$scale_entry,
				array( 'regular_price' => $effective_reg )
			);
		}

		self::save_rules( $rules );

		return array(
			'new_regular_price' => $effective_reg,
			'sale_price'        => $sale_price,
			'discount_pct'      => $discount_pct,
			'rounding_mode'     => $rounding_mode,
			'manual_sale_price' => $manual_sale_price,
		);
	}

	/**
	 * Resuelve la regla vigente para una editorial y escala de precio regular.
	 *
	 * @param string $editorial_slug Slug de la editorial.
	 * @param int    $scale_regular  Escala de precio regular actual.
	 * @return array{new_regular_price: int, sale_price: int, discount_pct: float, rounding_mode: string, manual_sale_price: int|null}
	 */
	public static function resolve_rule_for_scale( string $editorial_slug, int $scale_regular ): array {
		$editorial_slug = sanitize_title( $editorial_slug );
		$rules          = self::get_rules();
		$brand_cfg      = $rules['brands'][ $editorial_slug ] ?? array();

		$brand_pct  = isset( $brand_cfg['discount_pct'] ) ? (float) $brand_cfg['discount_pct'] : PricingMath::DEFAULT_DISCOUNT_PCT;
		$brand_mode = isset( $brand_cfg['rounding_mode'] ) && in_array( (string) $brand_cfg['rounding_mode'], PricingMath::supported_modes(), true )
			? (string) $brand_cfg['rounding_mode']
			: PricingMath::DEFAULT_MODE;

		$scale_cfg = $brand_cfg['scales'][ (string) $scale_regular ] ?? array();

		$new_reg = isset( $scale_cfg['new_regular_price'] ) && (int) $scale_cfg['new_regular_price'] > 0
			? (int) $scale_cfg['new_regular_price']
			: $scale_regular;

		$pct = isset( $scale_cfg['discount_pct'] ) ? (float) $scale_cfg['discount_pct'] : $brand_pct;
		$mode = isset( $scale_cfg['rounding_mode'] ) && in_array( (string) $scale_cfg['rounding_mode'], PricingMath::supported_modes(), true )
			? (string) $scale_cfg['rounding_mode']
			: $brand_mode;

		$manual = isset( $scale_cfg['manual_sale_price'] ) && null !== $scale_cfg['manual_sale_price'] && '' !== (string) $scale_cfg['manual_sale_price']
			? (int) $scale_cfg['manual_sale_price']
			: null;

		$sale = PricingMath::calculate_sale_price( $new_reg, $pct, $mode, $manual );

		return array(
			'new_regular_price' => $new_reg,
			'sale_price'        => $sale,
			'discount_pct'      => $pct,
			'rounding_mode'     => $mode,
			'manual_sale_price' => $manual,
		);
	}

	/**
	 * Resuelve el precio de oferta A Pedido redondeado para un producto concreto.
	 *
	 * @param WC_Product $product          Producto WooCommerce.
	 * @param int|null   $regular_override Precio regular override opcional.
	 * @return int
	 */
	public static function resolve_sale_price_for_product( WC_Product $product, ?int $regular_override = null ): int {
		$regular = null !== $regular_override ? $regular_override : (int) round( (float) $product->get_regular_price( 'edit' ) );
		if ( $regular <= 0 ) {
			return 0;
		}

		$brand_slug = self::primary_brand_slug_for_product( (int) $product->get_id() );
		$rule       = self::resolve_rule_for_scale( $brand_slug, $regular );

		return PricingMath::calculate_sale_price(
			$regular,
			(float) $rule['discount_pct'],
			(string) $rule['rounding_mode'],
			$rule['manual_sale_price']
		);
	}

	/**
	 * Obtiene el slug de la editorial (`product_brand`) principal de un producto.
	 *
	 * @param int $product_id ID del producto.
	 * @return string
	 */
	public static function primary_brand_slug_for_product( int $product_id ): string {
		if ( $product_id <= 0 ) {
			return '';
		}
		$terms = get_the_terms( $product_id, 'product_brand' );
		if ( is_array( $terms ) && ! empty( $terms ) ) {
			$first = reset( $terms );
			if ( is_object( $first ) && isset( $first->slug ) ) {
				return (string) $first->slug;
			}
		}
		return '';
	}

	/**
	 * Exporta las reglas actuales en formato JSON legible.
	 *
	 * @return string
	 */
	public static function export_json(): string {
		$rules = self::get_rules();
		return (string) wp_json_encode( $rules, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * Importa y valida un JSON de reglas en `akb_pricing_matrix_rules`.
	 *
	 * @param string $json Contenido JSON.
	 * @return array{ok: bool, message: string, brands_count: int, scales_count: int}
	 */
	public static function import_json( string $json ): array {
		$decoded = json_decode( trim( $json ), true );
		if ( ! is_array( $decoded ) ) {
			return array(
				'ok'           => false,
				'message'      => 'El archivo JSON no tiene un formato válido.',
				'brands_count' => 0,
				'scales_count' => 0,
			);
		}

		$normalized = self::normalize_rules_payload( $decoded );
		self::save_rules( $normalized );

		$brands_count = count( $normalized['brands'] );
		$scales_count = 0;
		foreach ( $normalized['brands'] as $b ) {
			$scales_count += isset( $b['scales'] ) && is_array( $b['scales'] ) ? count( $b['scales'] ) : 0;
		}

		return array(
			'ok'           => true,
			'message'      => sprintf( 'Reglas importadas correctamente (%d editoriales, %d escalas).', $brands_count, $scales_count ),
			'brands_count' => $brands_count,
			'scales_count' => $scales_count,
		);
	}

	/**
	 * Normaliza cualquier estructura de reglas entrante (anidada o plana `slug:escala`).
	 *
	 * @param array<string, mixed> $payload Datos crudos.
	 * @return array{version: int, updated_at: string, brands: array<string, array<string, mixed>>}
	 */
	public static function normalize_rules_payload( array $payload ): array {
		$brands = array();

		$source_brands = isset( $payload['brands'] ) && is_array( $payload['brands'] )
			? $payload['brands']
			: $payload;

		foreach ( $source_brands as $key => $val ) {
			if ( in_array( (string) $key, array( 'version', 'updated_at', 'brands' ), true ) || ! is_array( $val ) ) {
				continue;
			}

			// Soporte para clave compuesta "editorial_slug:10600".
			if ( false !== strpos( (string) $key, ':' ) ) {
				list( $raw_slug, $raw_scale ) = explode( ':', (string) $key, 2 );
				$slug  = sanitize_title( $raw_slug );
				$scale = max( 0, (int) $raw_scale );
				if ( '' === $slug || $scale <= 0 ) {
					continue;
				}
				if ( ! isset( $brands[ $slug ] ) ) {
					$brands[ $slug ] = array(
						'discount_pct'       => PricingMath::DEFAULT_DISCOUNT_PCT,
						'rounding_mode'      => PricingMath::DEFAULT_MODE,
						'backorders_enabled' => true,
						'scales'             => array(),
					);
				}
				$brands[ $slug ]['scales'][ (string) $scale ] = self::normalize_scale_entry( $scale, $val, PricingMath::DEFAULT_DISCOUNT_PCT, PricingMath::DEFAULT_MODE );
				continue;
			}

			$slug = sanitize_title( (string) $key );
			if ( '' === $slug ) {
				continue;
			}

			$brand_pct  = isset( $val['discount_pct'] ) ? max( 0.0, min( 95.0, (float) $val['discount_pct'] ) ) : PricingMath::DEFAULT_DISCOUNT_PCT;
			$brand_mode = isset( $val['rounding_mode'] ) && in_array( (string) $val['rounding_mode'], PricingMath::supported_modes(), true )
				? (string) $val['rounding_mode']
				: PricingMath::DEFAULT_MODE;
			$bo_enabled = ! isset( $val['backorders_enabled'] ) || (bool) $val['backorders_enabled'];

			$scales     = array();
			$raw_scales = isset( $val['scales'] ) && is_array( $val['scales'] ) ? $val['scales'] : array();
			foreach ( $raw_scales as $s_key => $s_val ) {
				$scale_int = max( 0, (int) $s_key );
				if ( $scale_int <= 0 || ! is_array( $s_val ) ) {
					continue;
				}
				$scales[ (string) $scale_int ] = self::normalize_scale_entry( $scale_int, $s_val, $brand_pct, $brand_mode );
			}

			$brands[ $slug ] = array(
				'discount_pct'       => $brand_pct,
				'rounding_mode'      => $brand_mode,
				'backorders_enabled' => $bo_enabled,
				'scales'             => $scales,
			);
		}

		return array(
			'version'    => 1,
			'updated_at' => isset( $payload['updated_at'] ) && is_string( $payload['updated_at'] ) ? $payload['updated_at'] : gmdate( 'c' ),
			'brands'     => $brands,
		);
	}

	/**
	 * Normaliza una entrada individual de escala.
	 *
	 * @param int                  $scale_regular Escala regular base.
	 * @param array<string, mixed> $entry         Datos de la escala.
	 * @param float                $default_pct   % por defecto de la editorial.
	 * @param string               $default_mode  Modo por defecto de la editorial.
	 * @return array{regular_price: int, new_regular_price: int, discount_pct: float, rounding_mode: string, manual_sale_price: int|null, sale_price: int}
	 */
	private static function normalize_scale_entry( int $scale_regular, array $entry, float $default_pct, string $default_mode ): array {
		$new_reg = isset( $entry['new_regular_price'] ) && (int) $entry['new_regular_price'] > 0
			? (int) $entry['new_regular_price']
			: $scale_regular;

		$pct = isset( $entry['discount_pct'] )
			? max( 0.0, min( 95.0, (float) $entry['discount_pct'] ) )
			: $default_pct;

		$mode = isset( $entry['rounding_mode'] ) && in_array( (string) $entry['rounding_mode'], PricingMath::supported_modes(), true )
			? (string) $entry['rounding_mode']
			: $default_mode;

		$manual = isset( $entry['manual_sale_price'] ) && null !== $entry['manual_sale_price'] && '' !== (string) $entry['manual_sale_price']
			? max( 0, (int) $entry['manual_sale_price'] )
			: null;

		$sale = PricingMath::calculate_sale_price( $new_reg, $pct, $mode, $manual );

		return array(
			'regular_price'     => $scale_regular,
			'new_regular_price' => $new_reg,
			'discount_pct'      => $pct,
			'rounding_mode'     => $mode,
			'manual_sale_price' => $manual,
			'sale_price'        => $sale,
		);
	}
}
