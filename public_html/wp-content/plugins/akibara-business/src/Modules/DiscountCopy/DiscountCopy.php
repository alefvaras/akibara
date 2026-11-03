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

use Akibara\Business\Modules\TaxonomyDiscount\Settings;
use Akibara\Business\Utilities\DiscountCeiling;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.115.0
 */
final class DiscountCopy {

	/** Shortcodes públicos. */
	public const SHORTCODE_PCT       = 'akb_descuento_pct';
	public const SHORTCODE_EDITORIAL = 'akb_descuento_editorial';

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
		'pct'       => 0,
		'term_name' => '',
	);

	/** Memo del request. `false` = todavía no se resolvió. */
	private static $rule_memo = false;

	/**
	 * @return void
	 */
	public function register(): void {
		add_shortcode( self::SHORTCODE_PCT, array( $this, 'render_pct' ) );
		add_shortcode( self::SHORTCODE_EDITORIAL, array( $this, 'render_editorial' ) );

		// Invalida la foto por las options publicadas en Settings::OPTIONS, el
		// term-meta de porcentaje y el techo de descuento de la tienda. Ese techo
		// también limita el porcentaje anunciado; no se escucha todo updated_option.
		$fronteras = array_merge( Settings::OPTIONS, array( DiscountCeiling::OPTION ) );

		foreach ( $fronteras as $option ) {
			add_action( 'update_option_' . $option, array( self::class, 'forget' ) );
			add_action( 'add_option_' . $option, array( self::class, 'forget' ) );
			add_action( 'delete_option_' . $option, array( self::class, 'forget' ) );
		}

		foreach ( array( 'added_term_meta', 'updated_term_meta', 'deleted_term_meta' ) as $hook ) {
			add_action( $hook, array( self::class, 'forget_on_term_meta' ), 10, 3 );
		}
	}

	/**
	 * ¿Hay campaña vigente ahora mismo? Switch maestro + ventana de vigencia +
	 * al menos una regla con porcentaje.
	 *
	 * La semántica de vigencia NO se reimplementa acá: la decide
	 * `Settings::is_enabled()`, que es la misma que gobierna el precio. Si se
	 * copiara, un cambio en la ventana dejaría el banner y el precio discrepando
	 * — que es exactamente el defecto que este módulo existe para cerrar.
	 */
	public static function campaign_active(): bool {
		return null !== self::top_rule();
	}

	/**
	 * Regla activa de mayor porcentaje, o null.
	 *
	 * @return array{pct: int, term_name: string}|null
	 */
	public static function top_rule(): ?array {
		if ( false !== self::$rule_memo ) {
			return self::$rule_memo;
		}

		$settings = self::settings();
		if ( ! $settings->is_enabled() ) {
			// Fuera de vigencia no hay regla que nombrar, y tampoco se toca la
			// foto: la campaña sigue configurada y volverá sola el día que
			// entre en ventana.
			self::$rule_memo = null;
			return null;
		}

		$cached = function_exists( 'get_transient' ) ? get_transient( self::TRANSIENT_TOP_RULE ) : false;
		if ( is_array( $cached ) && isset( $cached['pct'], $cached['term_name'] ) ) {
			// `pct` en cero es la foto del «no hay ninguna regla», no un dato
			// corrupto: {@see self::NO_RULE}.
			self::$rule_memo = (int) $cached['pct'] > 0
				? array(
					'pct'       => (int) $cached['pct'],
					'term_name' => (string) $cached['term_name'],
				)
				: null;

			return self::$rule_memo;
		}

		$rule = self::compute_top_rule( $settings );

		if ( function_exists( 'set_transient' ) ) {
			// El «no hay regla» TAMBIÉN se fotografía. Sin esto, una campaña
			// encendida y en ventana pero con todos los porcentajes en cero
			// —el estado exacto en que queda la tienda mientras el dueño arma
			// la próxima promo— repetiría el barrido de términos en CADA
			// página del sitio, que es justo lo que la foto existe para evitar.
			set_transient( self::TRANSIENT_TOP_RULE, $rule ?? self::NO_RULE, self::TTL );
		}

		self::$rule_memo = $rule;
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

		return (string) $rule['pct'] . ( $con_signo ? '%' : '' );
	}

	/** `[akb_descuento_editorial]` → «Ivrea Argentina». Vacío si no hay campaña. */
	public static function editorial_name(): string {
		$rule = self::top_rule();

		return null === $rule ? '' : $rule['term_name'];
	}

	/**
	 * Resuelve los dos shortcodes dentro de un texto que NO pasa por
	 * `do_shortcode()`.
	 *
	 * Gemelo de `ShippingCopy::resolve_in()` y por el mismo motivo: la barra de
	 * anuncios imprime con `wp_kses()` y sin procesar shortcodes a propósito,
	 * porque ese texto se pinta en todas las páginas del sitio. Acá se resuelven
	 * SÓLO los dos nuestros.
	 *
	 * @param string $texto Texto con los shortcodes escritos.
	 */
	public static function resolve_in( string $texto ): string {
		if ( false === strpos( $texto, '[akb_descuento_' ) ) {
			return $texto;
		}

		$texto = (string) preg_replace_callback(
			'/\[' . preg_quote( self::SHORTCODE_PCT, '/' ) . '(\s[^\]]*)?\]/',
			static function ( array $m ): string {
				$sin_signo = isset( $m[1] ) && false !== strpos( $m[1], 'signo="no"' );
				return self::pct_formatted( ! $sin_signo );
			},
			$texto
		);

		return (string) preg_replace(
			'/\[' . preg_quote( self::SHORTCODE_EDITORIAL, '/' ) . '(\s[^\]]*)?\]/',
			self::editorial_name(),
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
	 * Barre los términos con porcentaje y devuelve el mayor.
	 *
	 * @param Settings $settings Configuración viva.
	 * @return array{pct: int, term_name: string}|null
	 */
	private static function compute_top_rule( Settings $settings ): ?array {
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
			array(
				'taxonomy'     => $slugs,
				'hide_empty'   => false,
				'meta_key'     => Settings::TERM_META_PCT,
				'meta_value'   => 0,
				'meta_compare' => '>',
				'meta_type'    => 'NUMERIC',
				'number'       => 200,
			)
		);

		if ( ! is_array( $terms ) || array() === $terms ) {
			return null;
		}

		$mejor = null;
		foreach ( $terms as $term ) {
			if ( ! is_object( $term ) || ! isset( $term->term_id ) ) {
				continue;
			}

			// El % se relee por `Settings` y no del meta crudo: ahí vive el
			// clamp contra el techo de la tienda. Un 40 escrito a mano en la
			// base tiene que nombrarse como el 15 que de verdad se cobra.
			$pct = $settings->get_term_pct( (int) $term->term_id );
			if ( $pct <= 0 ) {
				continue;
			}

			if ( null === $mejor || $pct > $mejor['pct'] ) {
				$mejor = array(
					'pct'       => $pct,
					'term_name' => (string) ( $term->name ?? '' ),
				);
			}
		}

		return $mejor;
	}
}
