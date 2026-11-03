<?php
/**
 * Novedad — faceta "Novedades" por fecha de llegada al catálogo.
 *
 * Utiliza el epoch UTC de `post_date_gmt` (`WC_Product::get_date_created()`).
 *
 * Tramos relativos anidados (7/30/90 días) resueltos en tiempo de consulta: el índice
 * materializa el epoch en `value_num` y el corte se calcula por petición sin requerir reindexado.
 *
 * @package Akibara\Search
 */

declare( strict_types = 1 );

namespace Akibara\Search;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.1.0
 */
final class Novedad {

	public const OPTION = 'akb_search_novedad';

	/**
	 * Buckets anidados (días hacia atrás desde ahora, orden = orden de la UI).
	 * Semántica OR entre rangos anidados = el más ancho, correcta incluso si
	 * el operador cambia el `ui` a checkboxes desde el admin [gate DISEÑO].
	 *
	 * @var array<int, array{days: int, label: string}>
	 */
	public const BUCKETS = array(
		array(
			'days'  => 7,
			'label' => 'Últimos 7 días',
		),
		array(
			'days'  => 30,
			'label' => 'Último mes',
		),
		array(
			'days'  => 90,
			'label' => 'Últimos 3 meses',
		),
	);

	/** @var bool|null Memo por-request del flag (get_option no cambia mid-request). */
	private static ?bool $enabled = null;

	/**
	 * ¿Está habilitada la faceta? Flag `akb_search_novedad`, default OFF.
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		if ( null === self::$enabled ) {
			self::$enabled = function_exists( 'get_option' )
				&& 'yes' === SemanticPublication::authoritative_value( self::OPTION, 'no' );
		}
		return self::$enabled;
	}

	/** La option query-time fue leída desde una foto autoritativa. */
	public static function is_available(): bool {
		return SemanticPublication::authoritative_available();
	}

	/**
	 * Buckets vigentes (v1: la const; indirection para un editor futuro).
	 *
	 * @return array<int, array{days: int, label: string}>
	 */
	public static function buckets(): array {
		return self::BUCKETS;
	}

	/**
	 * Epoch UTC de corte para un bucket de N días. `time()` y el `value_num`
	 * materializado son ambos epoch UTC (get_date_created lee post_date_gmt
	 * con TZ UTC forzada en WC core) — sin drift de zona Chile.
	 *
	 * @param int      $days Días hacia atrás.
	 * @param int|null $now  Epoch de referencia (tests); null = time().
	 * @return int
	 */
	public static function cutoff( int $days, ?int $now = null ): int {
		$now ??= time();
		return $now - $days * DAY_IN_SECONDS;
	}

	/**
	 * Reset del memo (tests, o tras togglear el flag en el admin).
	 *
	 * @return void
	 */
	public static function reset_cache(): void {
		self::$enabled = null;
	}

	/**
	 * Hooks del ciclo de vida del flag: asegura la invalidación del memo local
	 * ante cualquier modificación o eliminación de la opción `akb_search_novedad`
	 * (desde el panel administrativo, WP-CLI o procesos automáticos).
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'update_option_' . self::OPTION, array( self::class, 'on_flag_change' ) );
		add_action( 'add_option_' . self::OPTION, array( self::class, 'on_flag_change' ) );
		add_action( 'delete_option_' . self::OPTION, array( self::class, 'on_flag_change' ) );
	}

	/**
	 * El flag cambió de verdad: invalida el memo por-request. La rotación de
	 * cache vive en SemanticPublication para conservar deuda ante crash.
	 *
	 * @return void
	 */
	public static function on_flag_change(): void {
		self::reset_cache();
	}
}
