<?php
/**
 * Acceso a la configuración de EstimatedDelivery. Lee _akb_min_days y
 * _akb_max_days en el término pa_pais del producto, con DEFAULT_LEAD_TIMES
 * como respaldo. El dueño configura plazos y nombre del país desde sus
 * pantallas nativas; no se infiere el país desde la editorial.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\EstimatedDelivery;

defined( 'ABSPATH' ) || exit;

/**
 * Settings facade — term-meta + fallback options + ultimate constant.
 *
 * @since 1.7.0
 */
final class Settings {

	/**
	 * Defaults de lead time por slug pa_pais. Ultimate fallback cuando ni term-meta
	 * ni option global tienen valor válido. Solo cubre los slugs históricos
	 * (argentina, espana) — países nuevos agregados por owner requieren config
	 * explícita en term-meta (o el banner cae a TBA sin lead).
	 *
	 * @var array<string, array{min:int, max:int}>
	 */
	public const DEFAULT_LEAD_TIMES = array(
		'argentina' => array( 'min' => 12, 'max' => 15 ),
		'espana'    => array( 'min' => 17, 'max' => 20 ),
	);

	/**
	 * Display name de fallback si `get_term_by` no encuentra el término. Cubre
	 * el caso "snapshot histórico tiene slug `argentina` pero el término se
	 * eliminó del taxonomy" — defensive.
	 *
	 * @var array<string, string>
	 */
	private const FALLBACK_DISPLAY_NAMES = array(
		'argentina' => 'Argentina',
		'espana'    => 'España',
	);

	/**
	 * Term-meta keys para lead times. Públicos porque PaisTermFields los lee/escribe.
	 */
	public const TERM_META_MIN_DAYS = '_akb_min_days';
	public const TERM_META_MAX_DAYS = '_akb_max_days';

	private array $country_days_cache = array();
	private array $country_name_cache = array();

	/**
	 * Resuelve el rango del slug pa_pais mediante term-meta y después
	 * DEFAULT_LEAD_TIMES. En ambos pasos exige min>=1, max>=min y max<=365;
	 * si no puede resolverlo devuelve null.
	 *
	 * @param string $slug Slug del término pa_pais (`argentina`, `espana`, ...).
	 * @return array{min:int, max:int}|null
	 */
	public function get_country_days( string $slug ): ?array {
		$slug = strtolower( trim( $slug ) );
		if ( '' === $slug ) {
			return null;
		}

		if ( array_key_exists( $slug, $this->country_days_cache ) ) {
			return $this->country_days_cache[ $slug ];
		}

		$result = $this->resolve_country_days( $slug );
		$this->country_days_cache[ $slug ] = $result;
		return $result;
	}

	private function resolve_country_days( string $slug ): ?array {

		// 1) term-meta del taxonomy pa_pais.
		if ( function_exists( 'get_term_by' ) && function_exists( 'get_term_meta' ) ) {
			$term = get_term_by( 'slug', $slug, 'pa_pais' );
			if ( $term && ! ( function_exists( 'is_wp_error' ) && is_wp_error( $term ) ) ) {
				$min = (int) get_term_meta( $term->term_id, self::TERM_META_MIN_DAYS, true );
				$max = (int) get_term_meta( $term->term_id, self::TERM_META_MAX_DAYS, true );
				if ( $this->is_valid_range( $min, $max ) ) {
					return array( 'min' => $min, 'max' => $max );
				}
			}
		}

		// 2) Ultimate constant fallback.
		//
		// El paso intermedio que leía las options globales pre-1.14.0
		// (`akibara_eda_lt_{ar|es}_min/max`) era INALCANZABLE y se retiró: la
		// migración de 1.14.0 vuelca esas options al term-meta del término, así
		// que cuando existían ganaba el paso 1 y cuando no existían el paso 2 no
		// resolvía nada. Ese camino sigue vivo donde corresponde, en `Migration`.
		return self::DEFAULT_LEAD_TIMES[ $slug ] ?? null;
	}

	/**
	 * Devuelve el display name del país (e.g. "Argentina") para un slug.
	 *
	 * Resolución:
	 *   1) `$term->name` del taxonomy pa_pais (fuente owner-controlled).
	 *   2) `FALLBACK_DISPLAY_NAMES` constant (defensive si el término fue
	 *      eliminado pero el snapshot persiste el slug histórico).
	 *
	 * Único getter que el módulo expone para que consumers (BannerRenderer,
	 * OrderPreventa\LineItemSnapshot vía EtaProvider) no necesiten conocer
	 * el taxonomy.
	 *
	 * @param string $slug Slug del término.
	 * @return string|null Display name o null si no hay manera de resolverlo.
	 */
	public function get_country_name( string $slug ): ?string {
		$slug = strtolower( trim( $slug ) );
		if ( '' === $slug ) {
			return null;
		}

		if ( array_key_exists( $slug, $this->country_name_cache ) ) {
			return $this->country_name_cache[ $slug ];
		}

		$result = $this->resolve_country_name( $slug );
		$this->country_name_cache[ $slug ] = $result;
		return $result;
	}

	private function resolve_country_name( string $slug ): ?string {

		if ( function_exists( 'get_term_by' ) ) {
			$term = get_term_by( 'slug', $slug, 'pa_pais' );
			if ( $term && ! ( function_exists( 'is_wp_error' ) && is_wp_error( $term ) ) ) {
				return (string) $term->name;
			}
		}

		return self::FALLBACK_DISPLAY_NAMES[ $slug ] ?? null;
	}

	/**
	 * True si las fechas deben saltar fines de semana. Default 'yes'. Setting
	 * único que sobrevive al refactor — el resto migró a term-meta.
	 *
	 * @return bool
	 */
	public function business_days_only(): bool {
		return 'yes' === get_option( 'akibara_eda_business_days_only', 'yes' );
	}

	/** Option: qué se le dice a Google de un tomo «a pedido» sin promesa de fecha. */
	public const GOOGLE_SIN_PROMESA_OPTION = 'akibara_eda_google_sin_promesa';

	/** Los tres modos válidos, en el orden del desplegable. */
	public const GOOGLE_SIN_PROMESA_MODOS = array( 'out_of_stock', 'next_cycle', 'leave' );

	/**
	 * Política Google Merchant para A pedido sin promesa de fecha. out_of_stock
	 * es el valor inicial; next_cycle envía la fecha del próximo pedido y leave
	 * conserva el mapeo sin corregir un posible error availabilityDate.
	 * No cambia la promesa visible de la ficha.
	 *
	 * @return string
	 */
	public function google_sin_promesa(): string {
		$raw = get_option( self::GOOGLE_SIN_PROMESA_OPTION, 'out_of_stock' );

		return is_string( $raw ) && in_array( $raw, self::GOOGLE_SIN_PROMESA_MODOS, true ) ? $raw : 'out_of_stock';
	}

	/**
	 * @param int $min
	 * @param int $max
	 * @return bool
	 */
	private function is_valid_range( int $min, int $max ): bool {
		return $min >= 1 && $max >= $min && $max <= 365;
	}
}
