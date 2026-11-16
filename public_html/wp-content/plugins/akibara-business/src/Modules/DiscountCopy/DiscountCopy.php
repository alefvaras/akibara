<?php
/**
 * Publica [akb_descuento_pct] y [akb_descuento_editorial] desde la campaña
 * vigente de mayor porcentaje para que el banner comparta la regla del precio.
 * Si se quiere nombrar otra campaña, el texto se escribe explícitamente.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\DiscountCopy;

use Akibara\Business\Modules\Stock\StockStatus;
use Akibara\Business\Modules\TaxonomyDiscount\Settings;
use Akibara\Business\Utilities\DiscountCeiling;
use Akibara\Business\Utilities\StoreTime;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.115.0
 * @api
 * @psalm-suppress HookNotFound
 * @psalm-suppress InvalidArgument
 * @psalm-suppress TypeDoesNotContainType
 */
// @phpstan-ignore complexity.classLike
final class DiscountCopy {

	/** Shortcodes públicos. */
	public const SHORTCODE_PCT       = 'akb_descuento_pct';
	public const SHORTCODE_EDITORIAL = 'akb_descuento_editorial';
	public const SHORTCODE_URL       = 'akb_descuento_url';
	public const SHORTCODE_VIGENCIA  = 'akb_descuento_vigencia';

	/**
	 * Versión de payload del transient `akb_discount_top_rule`.
	 */
	private const TRANSIENT_VERSION = 2;

	/**
	 * Foto de la regla dominante.
	 *
	 * Existe porque el consumidor natural es la barra de anuncios, que se pinta
	 * en TODAS las páginas del sitio: resolver la regla ahí significaría barrer
	 * los términos con `_akb_taxonomy_discount_pct` en cada visita. Con el
	 * catálogo actual son ~277 términos repartidos en seis taxonomías, y
	 * `get_terms()` gasta tres consultas por taxonomía. La foto lo deja en cero
	 * consultas mientras la campaña no cambie.
	 */
	private const TRANSIENT_TOP_RULE = 'akb_discount_top_rule';

	/**
	 * La foto no caduca por tiempo sino por evento: se tira cuando cambia una
	 * option del módulo de descuentos o el % de un término. El TTL es sólo la
	 * red de seguridad para el caso que ningún hook cubre —una fila editada por
	 * SQL directo, una restauración de base— y por eso es largo: si venciera
	 * seguido, volveríamos a pagar el barrido que la foto existe para evitar.
	 */
	private const TTL = 12 * HOUR_IN_SECONDS;

	/** Foto del «campaña vigente, pero ninguna regla con porcentaje». */
	private const NO_RULE = array(
		'v'           => self::TRANSIENT_VERSION,
		'pct'         => 0,
		'term_name'   => '',
		'term_id'     => 0,
		'taxonomy'    => '',
		'term_path'   => '',
		'rules_count' => 0,
	);

	/** Prioridad de taxonomía en empate exacto de % y productos elegibles. */
	private const TAXONOMY_PRIORITY = array(
		'product_brand' => 6,
		'product_cat'   => 5,
		'pa_serie'      => 4,
		'pa_genero'     => 3,
		'pa_autor'      => 2,
		'product_tag'   => 1,
	);

	/** Memo del request. `false` = todavía no se resolvió. */
	private static $rule_memo = false; // @phpstan-ignore missingType.property

	/**
	 * @return void
	 */
	public function register(): void {
		add_shortcode( self::SHORTCODE_PCT, array( $this, 'render_pct' ) );
		add_shortcode( self::SHORTCODE_EDITORIAL, array( $this, 'render_editorial' ) );
		add_shortcode( self::SHORTCODE_URL, array( $this, 'render_url' ) );
		add_shortcode( self::SHORTCODE_VIGENCIA, array( $this, 'render_vigencia' ) );

		// Invalida la foto por las options publicadas en Settings::OPTIONS, el
		// techo de descuento de la tienda y cambios estructurales de permalinks.
		$fronteras = array_merge(
			Settings::OPTIONS,
			array(
				DiscountCeiling::OPTION,
				'permalink_structure',
				'woocommerce_permalinks',
				'category_base',
				'tag_base',
			)
		);

		foreach ( $fronteras as $option ) {
			add_action( 'update_option_' . $option, array( self::class, 'forget' ) );
			add_action( 'add_option_' . $option, array( self::class, 'forget' ) );
			add_action( 'delete_option_' . $option, array( self::class, 'forget' ) );
		}

		foreach ( array( 'added_term_meta', 'updated_term_meta', 'deleted_term_meta' ) as $hook ) {
			add_action( $hook, array( self::class, 'forget_on_term_meta' ), 10, 3 );
		}

		add_action( 'edited_term', array( self::class, 'forget_on_term_change' ), 10, 3 );
		add_action( 'delete_term', array( self::class, 'forget_on_term_change' ), 10, 3 );
		add_action( 'woocommerce_attribute_updated', array( self::class, 'forget' ) );
		add_action( 'woocommerce_attribute_deleted', array( self::class, 'forget' ) );
	}

	/**
	 * ¿Hay campaña vigente ahora mismo? Switch maestro + ventana de vigencia +
	 * al menos una regla elegible con porcentaje > 0.
	 */
	public static function campaign_active(): bool {
		return null !== self::top_rule();
	}

	/**
	 * Estado completo y fechas declaradas de la campaña en Descuentos por taxonomía.
	 *
	 * Con `$fresh = true`, lee directamente de base de datos para evitar cachés
	 * de opciones recién guardadas en el panel admin.
	 *
	 * @param bool $fresh True en vistas de administración tras guardar.
	 * @return array{enabled: bool, active: bool, reason: string, from: string, to: string, apply_to: string, rule: ?array<string, mixed>}
	 */
	public static function campaign_window( bool $fresh = false ): array {
		$settings = self::settings();

		if ( $fresh ) {
			$db_state  = $settings->window_state_from_database();
			$master_on = 'yes' === $db_state['enabled'];
			$from      = trim( $db_state['from'] );
			$to        = trim( $db_state['to'] );
			$apply_to  = $db_state['apply_to'];
			$today     = StoreTime::today_ymd();
		} else {
			$master_on = $settings->is_master_enabled();
			$from      = trim( $settings->get_from_date() );
			$to        = trim( $settings->get_to_date() );
			$apply_to  = $settings->get_apply_to();
			$today     = function_exists( 'current_time' ) ? (string) current_time( 'Y-m-d' ) : StoreTime::today_ymd();
		}

		$window_open = $master_on && Settings::window_covers( $from, $to, $today );
		$rule        = $window_open ? self::top_rule( $fresh ) : null;

		if ( ! $master_on || '' === $today ) {
			$reason = 'disabled';
		} elseif ( '' !== $from && $today < $from ) {
			$reason = 'scheduled';
		} elseif ( '' !== $to && $today > $to ) {
			$reason = 'expired';
		} elseif ( null === $rule ) {
			$reason = 'no_rules';
		} else {
			$reason = 'active';
		}

		return array(
			'enabled'  => $window_open,
			'active'   => $window_open && null !== $rule,
			'reason'   => $reason,
			'from'     => $from,
			'to'       => $to,
			'apply_to' => $apply_to,
			'rule'     => $rule,
		);
	}

	/**
	 * Fecha límite `Y-m-d` cuando la campaña está realmente activa (o '' si no aplica).
	 */
	public static function campaign_end_date(): string {
		$window = self::campaign_window();
		if ( ! $window['active'] ) {
			return '';
		}

		return $window['to'];
	}

	/**
	 * Evalúa una ventana inclusiva `[from, to]` delegando en `Settings::window_covers()`
	 * para preservar una jerarquía acíclica entre módulos.
	 */
	public static function window_covers( string $from, string $to, string $today ): bool {
		return Settings::window_covers( $from, $to, $today );
	}

	/**
	 * Regla activa de mayor porcentaje, o null.
	 *
	 * @param bool $bypass_window Si ya se verificó la ventana fresca desde base.
	 * @return array{pct: int, term_name: string, term_id: int, taxonomy: string, term_path: string, rules_count: int}|null
	 */
	public static function top_rule( bool $bypass_window = false ): ?array { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh
		$settings = self::settings();
		if ( ! $bypass_window && ! $settings->is_enabled() ) {
			self::$rule_memo = null;
			return null;
		}

		if ( ! $bypass_window && false !== self::$rule_memo ) {
			return self::$rule_memo;
		}

		$cached = function_exists( 'get_transient' ) ? get_transient( self::TRANSIENT_TOP_RULE ) : false;
		if ( is_array( $cached ) && isset( $cached['pct'], $cached['term_name'] ) ) {
			$has_v2     = self::TRANSIENT_VERSION === (int) ( $cached['v'] ?? 0 );
			$can_recalc = function_exists( 'get_terms' )
				&& function_exists( 'taxonomy_exists' )
				&& function_exists( 'get_term_meta' )
				&& isset( $GLOBALS['wpdb'] )
				&& is_object( $GLOBALS['wpdb'] );
			if ( $has_v2 || ! $can_recalc ) {
				$pct        = (int) $cached['pct'];
				$normalized = $pct > 0
					? array(
						'pct'         => $pct,
						'term_name'   => (string) $cached['term_name'],
						'term_id'     => (int) ( $cached['term_id'] ?? 0 ),
						'taxonomy'    => (string) ( $cached['taxonomy'] ?? '' ),
						'term_path'   => (string) ( $cached['term_path'] ?? '' ),
						'rules_count' => max( 1, (int) ( $cached['rules_count'] ?? 1 ) ),
					)
					: null;

				if ( ! $bypass_window ) {
					self::$rule_memo = $normalized;
				}
				return $normalized;
			}
		}

		$rule = self::compute_top_rule( $settings );

		if ( function_exists( 'set_transient' ) ) {
			set_transient(
				self::TRANSIENT_TOP_RULE,
				null !== $rule
					? array_merge( array( 'v' => self::TRANSIENT_VERSION ), $rule )
					: self::NO_RULE,
				self::TTL
			);
		}

		if ( ! $bypass_window ) {
			self::$rule_memo = $rule;
		}
		return $rule;
	}

	/**
	 * `[akb_descuento_pct]` → «10%». Cadena vacía si no hay campaña.
	 *
	 * Vacío y no «0%» por la misma razón que `ShippingCopy::formatted()`
	 * devuelve vacío sin umbral: sin campaña no hay promesa que hacer, y una
	 * frase incompleta es mejor que una frase falsa.
	 *
	 * @param bool $con_signo Anteponer el símbolo de porcentaje.
	 */
	public static function pct_formatted( bool $con_signo = true ): string {
		$rule = self::top_rule();
		if ( null === $rule || $rule['pct'] <= 0 ) {
			return '';
		}

		return $rule['pct'] . ( $con_signo ? '%' : '' );
	}

	/** `[akb_descuento_editorial]` → «Ivrea Argentina». Vacío si no hay campaña. */
	public static function editorial_name(): string {
		$rule = self::top_rule();

		return null === $rule ? '' : $rule['term_name'];
	}

	/**
	 * Enlace dinámico de la campaña activa para `[akb_descuento_url]`, o '' sin campaña.
	 *
	 * @param string $scope 'term' (archivo de la taxonomía ganadora) o 'tienda'.
	 * @param string $stock 'auto' (según `apply_to`), 'si' (`_disponibilidad=instock`) o 'no'.
	 */
	// phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh
	public static function target_url( string $scope = 'term', string $stock = 'auto' ): string { // @phpstan-ignore complexity.functionLike
		$rule = self::top_rule();
		if ( null === $rule ) {
			return '';
		}

		$shop_url = '';
		if ( function_exists( 'wc_get_page_permalink' ) ) {
			$shop_permalink = wc_get_page_permalink( 'shop' );
			if ( is_string( $shop_permalink ) && '' !== $shop_permalink && '-1' !== $shop_permalink ) {
				$shop_url = $shop_permalink;
			}
		}
		if ( '' === $shop_url ) {
			$shop_url = function_exists( 'home_url' ) ? home_url( '/tienda/' ) : '/tienda/';
		}

		$base = $shop_url;
		if ( 'tienda' !== strtolower( trim( $scope ) ) ) {
			$resolved = '';
			if ( '' !== $rule['term_path'] ) {
				$resolved = function_exists( 'home_url' ) ? home_url( $rule['term_path'] ) : $rule['term_path'];
			} elseif (
				$rule['term_id'] > 0
				&& '' !== $rule['taxonomy']
				&& function_exists( 'get_term_link' )
				&& ( ! function_exists( 'is_taxonomy_viewable' ) || is_taxonomy_viewable( $rule['taxonomy'] ) )
			) {
				$term_link = get_term_link( $rule['term_id'], $rule['taxonomy'] );
				if ( is_string( $term_link ) && '' !== $term_link && ! is_wp_error( $term_link ) ) { // @phpstan-ignore function.impossibleType
					$resolved = $term_link;
				}
			}

			if ( '' !== $resolved ) {
				$base = $resolved;
			}
		}

		$stock_mode = strtolower( trim( $stock ) );
		if ( '' === $stock_mode ) {
			$stock_mode = 'auto';
		}

		$add_instock = 'si' === $stock_mode
			|| ( 'auto' === $stock_mode && Settings::APPLY_INSTOCK_ONLY === self::settings()->get_apply_to() );

		if ( $add_instock ) {
			$base = function_exists( 'add_query_arg' )
				? (string) add_query_arg( '_disponibilidad', StockStatus::IN_STOCK, $base )
				: $base . ( false === strpos( $base, '?' ) ? '?' : '&' ) . '_disponibilidad=' . StockStatus::IN_STOCK;
		}

		return $base;
	}

	/**
	 * Etiqueta legible en español chileno del rango vigente para `[akb_descuento_vigencia]`.
	 */
	public static function vigencia_label(): string {
		$window = self::campaign_window();
		if ( ! $window['active'] ) {
			return '';
		}

		$from_cl = self::format_ymd_cl( $window['from'] );
		$to_cl   = self::format_ymd_cl( $window['to'] );

		if ( '' === $from_cl && '' === $to_cl ) {
			return '';
		}
		if ( '' !== $from_cl && $from_cl === $to_cl ) {
			return 'solo hoy ' . $from_cl;
		}
		if ( '' !== $from_cl && '' !== $to_cl ) {
			return 'del ' . $from_cl . ' al ' . $to_cl;
		}

		return '' !== $to_cl ? 'hasta el ' . $to_cl : 'desde el ' . $from_cl;
	}

	/**
	 * Resuelve los shortcodes de campaña dentro de un texto que NO pasa por `do_shortcode()`.
	 *
	 * @param string $texto Texto con los shortcodes escritos.
	 */
	public static function resolve_in( string $texto ): string {
		if ( false === strpos( $texto, '[akb_descuento_' ) ) {
			return $texto;
		}

		return (string) preg_replace_callback(
			'/\[(akb_descuento_(?:pct|editorial|url|vigencia))(?:\s+([^\]]*))?\]/',
			static function ( array $m ): string {
				$tag  = $m[1];
				$atts = self::parse_shortcode_atts( $m[2] ?? '' );

				if ( self::SHORTCODE_PCT === $tag ) {
					$sin_signo = 'no' === strtolower( (string) ( $atts['signo'] ?? '' ) );
					return self::pct_formatted( ! $sin_signo );
				}
				if ( self::SHORTCODE_EDITORIAL === $tag ) {
					return self::editorial_name();
				}
				if ( self::SHORTCODE_URL === $tag ) {
					$scope = (string) ( $atts['alcance'] ?? 'term' );
					$stock = (string) ( $atts['stock'] ?? ( $atts['disponible'] ?? 'auto' ) );
					return self::target_url( $scope, $stock );
				}
				if ( self::SHORTCODE_VIGENCIA === $tag ) {
					return self::vigencia_label();
				}

				return '';
			},
			$texto
		);
	}

	/**
	 * `[akb_descuento_pct]`, `[akb_descuento_pct signo="no"]`.
	 *
	 * @param array<string,string>|string $atts Atributos.
	 */
	public function render_pct( $atts = array() ): string {
		$atts = shortcode_atts( array( 'signo' => 'si' ), is_array( $atts ) ? $atts : array() );

		return esc_html( self::pct_formatted( 'no' !== $atts['signo'] ) );
	}

	/**
	 * `[akb_descuento_editorial]`.
	 *
	 * @param array<string,string>|string $atts Atributos (ninguno).
	 */
	public function render_editorial( $atts = array() ): string {
		unset( $atts );

		return esc_html( self::editorial_name() );
	}

	/**
	 * `[akb_descuento_url]`, `[akb_descuento_url alcance="tienda" stock="si|no|auto"]`.
	 *
	 * @param array<string,string>|string $atts Atributos opcionales.
	 */
	public function render_url( $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'alcance'    => 'term',
				'stock'      => 'auto',
				'disponible' => '',
			),
			is_array( $atts ) ? $atts : array()
		);

		$stock = '' !== $atts['disponible'] ? (string) $atts['disponible'] : (string) $atts['stock'];

		return esc_url( self::target_url( (string) $atts['alcance'], $stock ) );
	}

	/**
	 * `[akb_descuento_vigencia]`.
	 *
	 * @param array<string,string>|string $atts Atributos (ninguno).
	 */
	public function render_vigencia( $atts = array() ): string {
		unset( $atts );

		return esc_html( self::vigencia_label() );
	}

	/** Tira la foto y el memo del request. */
	public static function forget(): void {
		self::$rule_memo = false;
		if ( function_exists( 'delete_transient' ) ) {
			delete_transient( self::TRANSIENT_TOP_RULE );
		}
	}

	/**
	 * Invalida sólo cuando el meta que cambió es el porcentaje del descuento.
	 *
	 * @param int|array $meta_id   ID del meta (array en los hooks de borrado).
	 * @param int       $object_id Término.
	 * @param string    $meta_key  Clave.
	 * @return void
	 */
	public static function forget_on_term_meta( $meta_id, $object_id, $meta_key ): void {
		unset( $meta_id, $object_id );
		if ( Settings::TERM_META_PCT === $meta_key ) {
			self::forget();
		}
	}

	/**
	 * Invalida la foto si cambia o se elimina un término en taxonomía soportada.
	 *
	 * @param int    $term_id  ID del término.
	 * @param int    $tt_id    ID de taxonomía de término.
	 * @param string $taxonomy Slug de taxonomía.
	 */
	public static function forget_on_term_change( $term_id, $tt_id, $taxonomy ): void {
		unset( $term_id, $tt_id );
		if ( in_array( (string) $taxonomy, self::settings()->get_supported_taxonomy_slugs(), true ) ) {
			self::forget();
		}
	}

	/**
	 * Instancia tolerante y request-local de la configuración de descuentos.
	 *
	 * No se inyecta la del módulo porque `TaxonomyDiscount` se instancia MUCHO
	 * después que la barra de anuncios en `Plugin::register_modules()`, y
	 * adelantarlo para este uso movería el orden de un módulo que decide
	 * precios. Esta instancia sólo lee options autocargadas en modo tolerante:
	 * no registra hooks, no entra en modo estricto y no toca la autoridad de
	 * precio.
	 */
	private static function settings(): Settings {
		static $settings = null;

		if ( null === $settings ) {
			$settings = new Settings();
		}

		return $settings;
	}

	/**
	 * Barre los términos con porcentaje y devuelve el mayor con criterios determinísticos
	 * y elegibilidad de stock alineados con `TaxonomyDiscount`.
	 *
	 * @param Settings $settings Configuración viva.
	 * @return array{pct: int, term_name: string, term_id: int, taxonomy: string, term_path: string, rules_count: int}|null
	 */
	// phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh
	private static function compute_top_rule( Settings $settings ): ?array { // @phpstan-ignore complexity.functionLike
		if ( ! function_exists( 'get_terms' ) ) {
			return null;
		}

		$slugs = $settings->get_supported_taxonomy_slugs();
		if ( array() === $slugs ) {
			return null;
		}

		// UNA consulta para todas las taxonomías soportadas, filtrando por el
		// meta en SQL: traer los ~277 términos y descartarlos en PHP costaba lo
		// mismo que el barrido que la foto evita.
		$terms = get_terms(
			array( // @phpstan-ignore argument.type
				'taxonomy'     => $slugs,
				'hide_empty'   => false,
				'meta_key'     => Settings::TERM_META_PCT, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'   => 0, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'meta_compare' => '>',
				'meta_type'    => 'NUMERIC',
				'number'       => 200,
			)
		);

		if ( ! is_array( $terms ) || array() === $terms ) {
			return null;
		}

		$candidates = array();
		foreach ( $terms as $term ) {
			if ( ! is_object( $term ) || ! isset( $term->term_id ) ) { // @phpstan-ignore isset.property
				continue;
			}

			$pct = $settings->get_term_pct( (int) $term->term_id );
			if ( $pct <= 0 ) {
				continue;
			}

			$term_count = isset( $term->count ) ? (int) $term->count : 1; // @phpstan-ignore isset.property
			if ( $term_count <= 0 ) {
				continue;
			}

			$candidates[] = array(
				'term'     => $term,
				'pct'      => $pct,
				'term_id'  => (int) $term->term_id,
				'tt_id'    => isset( $term->term_taxonomy_id ) ? (int) $term->term_taxonomy_id : 0, // @phpstan-ignore isset.property
				'taxonomy' => (string) ( $term->taxonomy ?? '' ), // @phpstan-ignore nullCoalesce.property
				'count'    => $term_count,
			);
		}

		if ( array() === $candidates ) {
			return null;
		}

		$tt_ids    = array_values( array_filter( array_column( $candidates, 'tt_id' ) ) );
		$tt_counts = self::eligible_counts_by_ttid( $tt_ids, $settings->get_apply_to() );

		$eligible = array();
		foreach ( $candidates as $cand ) {
			if ( null !== $tt_counts && $cand['tt_id'] > 0 ) {
				$real_count = $tt_counts[ $cand['tt_id'] ] ?? 0;
				if ( $real_count <= 0 ) {
					continue;
				}
				$cand['count'] = $real_count;
			}
			$eligible[] = $cand;
		}

		if ( array() === $eligible ) {
			return null;
		}

		usort(
			$eligible,
			static function ( array $a, array $b ): int {
				if ( $a['pct'] !== $b['pct'] ) {
					return $b['pct'] <=> $a['pct'];
				}
				if ( $a['count'] !== $b['count'] ) {
					return $b['count'] <=> $a['count'];
				}
				$prio_a = self::TAXONOMY_PRIORITY[ $a['taxonomy'] ] ?? 0;
				$prio_b = self::TAXONOMY_PRIORITY[ $b['taxonomy'] ] ?? 0;
				if ( $prio_a !== $prio_b ) {
					return $prio_b <=> $prio_a;
				}

				return $a['term_id'] <=> $b['term_id'];
			}
		);

		$winner = $eligible[0];
		$term   = $winner['term'];

		return array(
			'pct'         => $winner['pct'],
			'term_name'   => (string) ( $term->name ?? '' ), // @phpstan-ignore nullCoalesce.property
			'term_id'     => $winner['term_id'],
			'taxonomy'    => $winner['taxonomy'],
			'term_path'   => self::resolve_winner_term_path( $term, $winner['taxonomy'] ),
			'rules_count' => count( $eligible ),
		);
	}

	/**
	 * Resuelve el path relativo del término ganador cuando su taxonomía es pública.
	 *
	 * @param object $term     Término ganador.
	 * @param string $taxonomy Slug de taxonomía.
	 * @return string
	 */
	private static function resolve_winner_term_path( object $term, string $taxonomy ): string {
		if (
			'' === $taxonomy
			|| ! function_exists( 'get_term_link' )
			|| ( function_exists( 'is_taxonomy_viewable' ) && ! is_taxonomy_viewable( $taxonomy ) )
		) {
			return '';
		}

		$link = get_term_link( $term );
		if ( ! is_string( $link ) || '' === $link || is_wp_error( $link ) ) { // @phpstan-ignore function.impossibleType
			return '';
		}

		if ( function_exists( 'wp_make_link_relative' ) ) {
			$term_path = (string) wp_make_link_relative( $link );
		} else {
			$parsed_path  = (string) wp_parse_url( $link, PHP_URL_PATH );
			$parsed_query = (string) wp_parse_url( $link, PHP_URL_QUERY );
			$term_path    = '' !== $parsed_path ? $parsed_path . ( '' !== $parsed_query ? '?' . $parsed_query : '' ) : '';
		}

		if ( '' !== $term_path && '/' !== $term_path[0] ) {
			$term_path = '/' . $term_path;
		}

		return $term_path;
	}

	/**
	 * Cuenta en una única SQL productos publicados elegibles por `term_taxonomy_id`
	 * según `apply_to`, evitando anunciar en el banner una taxonomía 100 % agotada.
	 *
	 * @param array<int, int> $tt_ids   IDs de `wp_term_taxonomy`.
	 * @param string          $apply_to Alcance (`instock_only`, `instock_and_onbackorder`, `all`).
	 * @return array<int, int>|null Null cuando no hay conexión WPDB real (tests unitarios).
	 */
	private static function eligible_counts_by_ttid( array $tt_ids, string $apply_to ): ?array {
		if ( array() === $tt_ids ) {
			return null;
		}

		$wpdb = $GLOBALS['wpdb'] ?? null;
		if (
			! is_object( $wpdb )
			|| ! isset( $wpdb->term_relationships, $wpdb->posts, $wpdb->prefix )
			|| ! method_exists( $wpdb, 'get_var' )
			|| ! method_exists( $wpdb, 'prepare' )
			|| ! method_exists( $wpdb, 'get_results' )
		) {
			return null;
		}

		$lookup_table = $wpdb->prefix . 'wc_product_meta_lookup';
		if ( $lookup_table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $lookup_table ) ) ) {
			return null;
		}

		if ( Settings::APPLY_ALL === $apply_to ) {
			$sql = $wpdb->prepare(
				"SELECT tr.term_taxonomy_id, COUNT(DISTINCT p.ID) AS cnt
				FROM `{$wpdb->term_relationships}` tr
				INNER JOIN `{$wpdb->posts}` p ON p.ID = tr.object_id
				WHERE tr.term_taxonomy_id IN (" . implode( ', ', array_fill( 0, count( $tt_ids ), '%d' ) ) . ")
					AND p.post_type = 'product'
					AND p.post_status = 'publish'
				GROUP BY tr.term_taxonomy_id",
				...$tt_ids
			);
		} else {
			$status_list = Settings::APPLY_INSTOCK_AND_ONBACKORDER === $apply_to
				? array( StockStatus::IN_STOCK, StockStatus::ON_BACKORDER )
				: array( StockStatus::IN_STOCK );
			$sql         = $wpdb->prepare(
				"SELECT tr.term_taxonomy_id, COUNT(DISTINCT p.ID) AS cnt
				FROM `{$wpdb->term_relationships}` tr
				INNER JOIN `{$wpdb->posts}` p ON p.ID = tr.object_id
				INNER JOIN %i l ON l.product_id = p.ID
				WHERE tr.term_taxonomy_id IN (" . implode( ', ', array_fill( 0, count( $tt_ids ), '%d' ) ) . ")
					AND p.post_type = 'product'
					AND p.post_status = 'publish'
					AND l.stock_status IN (" . implode( ', ', array_fill( 0, count( $status_list ), '%s' ) ) . ')
				GROUP BY tr.term_taxonomy_id',
				...array_merge( array( $lookup_table ), $tt_ids, $status_list )
			);
		}

		$rows   = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- preparada arriba.
		$counts = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				if ( isset( $row['term_taxonomy_id'], $row['cnt'] ) ) {
					$counts[ (int) $row['term_taxonomy_id'] ] = (int) $row['cnt'];
				}
			}
		}

		return $counts;
	}

	/**
	 * Parsea atributos tipo `clave="valor"` dentro de `resolve_in()`.
	 *
	 * @return array<string, string>
	 */
	private static function parse_shortcode_atts( string $raw ): array {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return array();
		}

		if ( function_exists( 'shortcode_parse_atts' ) ) {
			$parsed = shortcode_parse_atts( $raw );
			if ( is_array( $parsed ) ) {
				return array_map( 'strval', $parsed );
			}
		}

		$atts = array();
		if ( preg_match_all( '/([a-z_][a-z0-9_]*)\s*=\s*"([^"]*)"/i', $raw, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $pair ) {
				$atts[ strtolower( $pair[1] ) ] = $pair[2];
			}
		}

		return $atts;
	}

	private static function format_ymd_cl( string $ymd ): string {
		if ( 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/D', trim( $ymd ), $m ) ) {
			return '';
		}
		if ( ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return '';
		}

		return $m[3] . '/' . $m[2] . '/' . $m[1];
	}
}
