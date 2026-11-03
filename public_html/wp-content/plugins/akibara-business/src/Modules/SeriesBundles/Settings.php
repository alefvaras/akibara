<?php
/**
 * Series Bundles Settings — read/write de opciones del módulo sobre wp_options.
 *
 * Getters tipados con prefijo `akb_` (Req 11.3). Sin funciones i18n (Req 11.4).
 *
 * Mapa de opciones (fuente de verdad: design.md, sección Settings):
 *   - `akb_series_bundles_enabled`              (yes/no)       default no    — maestro
 *   - `akb_series_bundles_byp`                  (JSON map)     default {}    — Req 2.1
 *   - `akb_series_bundles_exclude_cats`         (CSV term_ids) default ''    — Req 7.5
 *   - `akb_series_bundles_allow_backorder`      (yes/no)       default no    — Req 6.5
 *
 * Esta clase es la capa de almacenamiento. La config de Build_Your_Pack
 * (`get_byp_config()`) se mantiene como mapa asociativo crudo.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\SeriesBundles;

use Akibara\Business\Modules\Stock\StockStatus;
use Akibara\Business\Utilities\OptionsFromDatabase;
use WC_Product;

defined( 'ABSPATH' ) || exit;

final class Settings {

	public const OPTION_ENABLED         = 'akb_series_bundles_enabled';
	public const OPTION_BYP             = 'akb_series_bundles_byp';
	public const OPTION_EXCLUDE_CATS    = 'akb_series_bundles_exclude_cats';
	public const OPTION_ALLOW_BACKORDER = 'akb_series_bundles_allow_backorder';

	/**
	 * Memo del config BYP decodificado (1 json_decode por request). Se invalida
	 * en save_byp_config(). El config se lee por-ítem en loops calientes
	 * (BundleCouponEngine, BypCartNoticeProvider, BuilderWidget) → memoizar evita
	 * re-decodificar el blob ~4KB en cada llamada.
	 *
	 * @var array<string|int, mixed>|null
	 */
	private ?array $byp_cache = null;

	/**
	 * Master switch del módulo (Req 11.3, maestro).
	 */
	public function is_enabled(): bool {
		return 'yes' === get_option( self::OPTION_ENABLED, 'no' );
	}

	/**
	 * Configuración de Build_Your_Pack: mapa `term_id => {enabled, tiers[]}`.
	 *
	 * @return array<string|int, mixed>
	 */
	public function get_byp_config(): array {
		if ( null === $this->byp_cache ) {
			$this->byp_cache = $this->decode_json_collection( (string) get_option( self::OPTION_BYP, '{}' ) );
		}
		return $this->byp_cache;
	}

	/**
	 * Verifica si el término de serie tiene el módulo BYP habilitado (enabled === true).
	 */
	public function is_byp_enabled_for_term( int $term_id ): bool {
		$config = $this->get_byp_config();
		return ! empty( $config[ (string) $term_id ]['enabled'] );
	}

	/**
	 * Persiste la configuración de Build_Your_Pack como JSON (Req 2.1).
	 *
	 * @param array<string|int, mixed> $cfg
	 */
	public function save_byp_config( array $cfg ): void {
		// El blob BYP usa autoload false: sólo se consulta en superficies de series
		// habilitadas. No debe aumentar alloptions en cada petición del sitio.

		update_option( self::OPTION_BYP, wp_json_encode( $cfg ), false );
		$this->byp_cache = null; // invalidar memo
	}

	/**
	 * Reemplaza el JSON sólo si nadie lo modificó desde la lectura inicial.
	 *
	 * La migración corre en un request de upgrade y puede coincidir con un save
	 * administrativo. El compare-and-swap evita perder ese guardado humano.
	 */
	public function compare_and_swap_byp_config( string $expected_raw, array $cfg ): bool {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! isset( $wpdb->options ) ) {
			return false;
		}

		$replacement = wp_json_encode( $cfg );
		if ( ! is_string( $replacement ) ) {
			return false;
		}

		$changed = $wpdb->update(
			$wpdb->options,
			array( 'option_value' => $replacement ),
			array( 'option_name' => self::OPTION_BYP, 'option_value' => $expected_raw ),
			array( '%s' ),
			array( '%s', '%s' )
		);
		if ( 1 !== $changed ) {
			return false;
		}

		// `alloptions` NO se evicta: `save_byp_config()` guarda esta option con
		// autoload=false, así que el blob autocargado no puede contenerla y
		// tirarlo sólo obligaba a recomponer todas las options del sitio.
		wp_cache_delete( self::OPTION_BYP, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		$this->byp_cache = null;

		return true;
	}

	/** Lectura cruda para migraciones compare-and-swap, sin poblar el memo. */
	public function raw_byp_config(): string {
		return (string) get_option( self::OPTION_BYP, '{}' );
	}

	/**
	 * Categorías excluidas (term_ids). Maneja tanto el guardado como array (nuevo multiselect)
	 * como CSV (legacy).
	 *
	 * @return array<int, int>
	 */
	public function get_excluded_categories(): array {
		return self::parse_excluded_categories( get_option( self::OPTION_EXCLUDE_CATS, array() ) );
	}

	/**
	 * Modo global "permitir reserva": trata `onbackorder` como comprable (Req 6.5/6.6).
	 */
	public function allows_backorder(): bool {
		return 'yes' === get_option( self::OPTION_ALLOW_BACKORDER, 'no' );
	}

	/**
	 * Lee el estado completo de la tarjeta directamente desde la base.
	 *
	 * La lectura posterior al redirect no debe depender de un blob de opciones
	 * anterior al guardado en caché persistente. OptionsFromDatabase comparte la
	 * fuente de lectura autoritativa con el panel de descuentos editoriales.
	 *
	 * @return array{enabled: bool, enabled_count: int, excluded_count: int, allows_backorder: bool}
	 */
	public function status_snapshot_from_database(): array {
		$fallback = array(
			'enabled'          => $this->is_enabled(),
			'enabled_count'    => self::count_enabled_byp_entries( $this->get_byp_config() ),
			'excluded_count'   => count( $this->get_excluded_categories() ),
			'allows_backorder' => $this->allows_backorder(),
		);

		$filas = OptionsFromDatabase::read(
			array(
				self::OPTION_ENABLED,
				self::OPTION_BYP,
				self::OPTION_EXCLUDE_CATS,
				self::OPTION_ALLOW_BACKORDER,
			)
		);

		if ( null === $filas ) {
			return $fallback;
		}

		$byp_config = $this->decode_json_collection( $filas[ self::OPTION_BYP ] ?? '{}' );
		// La fila cruda de un array/CSV llega tal como la serializó `update_option()`
		// (p. ej. `a:2:{i:0;s:2:"12";i:1;s:2:"45";}`): `get_option()` la pasa por
		// `maybe_unserialize()` antes de que `get_excluded_categories()` la vea, y
		// esta lectura tiene que hacer lo mismo a mano.
		$excluded_raw = maybe_unserialize( $filas[ self::OPTION_EXCLUDE_CATS ] ?? '' );

		return array(
			'enabled'          => 'yes' === ( $filas[ self::OPTION_ENABLED ] ?? 'no' ),
			'enabled_count'    => self::count_enabled_byp_entries( $byp_config ),
			'excluded_count'   => count( self::parse_excluded_categories( $excluded_raw ) ),
			'allows_backorder' => 'yes' === ( $filas[ self::OPTION_ALLOW_BACKORDER ] ?? 'no' ),
		);
	}

	/**
	 * Cuenta las series con Build_Your_Pack activo dentro de un config YA decodificado.
	 * Fuente única: la usan tanto la lectura tolerante ({@see self::get_byp_config()})
	 * como la lectura cruda de {@see self::status_snapshot_from_database()}.
	 *
	 * @param array<string|int, mixed> $config Mapa `term_id => {enabled, tiers[]}`.
	 */
	private static function count_enabled_byp_entries( array $config ): int {
		$count = 0;
		foreach ( $config as $entry ) {
			if ( is_array( $entry ) && ! empty( $entry['enabled'] ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Normaliza el valor crudo de `OPTION_EXCLUDE_CATS`: array (multiselect nuevo)
	 * o CSV (legacy). Fuente única entre el getter tolerante y la lectura cruda.
	 *
	 * @param mixed $data Lo que devolvió `get_option()` o `maybe_unserialize()`.
	 * @return array<int, int>
	 */
	private static function parse_excluded_categories( $data ): array {
		if ( is_string( $data ) ) {
			if ( '' === trim( $data ) ) {
				return array();
			}
			$data = explode( ',', $data );
		}

		if ( ! is_array( $data ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'absint', $data ) ) );
	}

	/**
	 * Decide si el estado de stock de un producto puede contar en un pack.
	 *
	 * WooCommerce considera `onbackorder` como `is_in_stock() = true`; por eso el
	 * switch de preventas debe evaluarse contra el estado canónico y no contra ese
	 * método genérico.
	 */
	public function is_stock_eligible( WC_Product $product ): bool {
		if ( StockStatus::is_instock( $product ) ) {
			return true;
		}

		$mode = function_exists( 'get_option' ) ? (string) get_option( 'akb_series_bundles_backorder_mode', 'best_wins_topup' ) : 'best_wins_topup';
		if ( 'exclude_backorder' === $mode ) {
			return false;
		}

		return $this->allows_backorder() && StockStatus::is_reservable( $product );
	}

	/**
	 * Decodifica una opción JSON a array asociativo. Fail-safe a array vacío.
	 *
	 * @return array<string|int, mixed>
	 */
	private function decode_json_collection( string $raw ): array {
		if ( '' === $raw ) {
			return array();
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}
}
