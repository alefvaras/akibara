<?php
/**
 * SeriesDirectory — bootstrap del directorio de series.
 *
 * Componentes:
 *   - SeriesIndex  Qué series se publican y en qué orden.
 *   - Shortcode    `[akibara_series_directory]`, render + cache + invalidación.
 *
 * ── Página + shortcode, y NO una ruta virtual ───────────────────────────────
 * `/preventas/` y `/disponibles/` son rutas virtuales (`add_rewrite_rule` +
 * `posts_clauses`) y este directorio no lo es. La diferencia no es de gusto:
 * aquellas listan PRODUCTOS, así que reutilizan entero el archive de
 * WooCommerce —consulta principal, facetas, orden, paginación, migas— y lo único
 * que aportan es un `WHERE` sobre la lookup de stock. Este lista TÉRMINOS: no
 * hay consulta principal que filtrar, y montarlo sobre un archive de producto
 * significaría inventar un archive cuya consulta hay que ANULAR para después
 * imprimir otra cosa en su lugar, arrastrando la barra de herramientas, el
 * contador de resultados y el paginador de un listado que no existe.
 *
 * Lo que cuesta cada opción, dicho entero:
 *
 *   Ruta virtual: reglas de reescritura con su flush idempotente (`PreventasRoute`
 *   necesitó una option de versión sólo para eso), y sobre todo TODO el puente a
 *   Rank Math reescrito a mano —`rank_math/frontend/title`, `description`,
 *   `canonical`, `prev_rel_link`, `next_rel_link`, `sitemap/product_content` y
 *   `json_ld`—, porque una URL que no corresponde a ningún objeto de WordPress
 *   no tiene metadatos propios ni entra sola en el sitemap. Son siete enganches
 *   a un plugin de terceros para publicar una lista estática.
 *
 *   Página + shortcode: la página tiene que EXISTIR como contenido. Ese es el
 *   costo real y no se disimula: si nadie la crea, el shortcode no se ejecuta.
 *   A cambio, el H1, el `<title>`, la meta descripción, el canónico, las migas y
 *   la entrada en el sitemap salen de la maquinaria normal de WordPress y Rank
 *   Math, y el dueño los edita desde la UI sin tocar código. Es exactamente lo
 *   que ya se hizo con `/editoriales/` (página 24356, con su H1 en el contenido,
 *   `[akibara_brands_grid]` debajo y `rank_math_title`/`rank_math_description`
 *   escritos a mano), así que además es la convención de la casa.
 *
 * Este módulo NO crea la página. Ningún módulo de este plugin inserta contenido,
 * y no se empieza acá: una página publicada desde código en producción es un
 * efecto que el dueño no pidió y que después hay que explicar.
 *
 * ── Convivencia con la redirección de Rank Math ─────────────────────────────
 * El slug `series` lo ocupa hoy una regla 301 hacia `/tienda/` (69 impactos,
 * último acceso hoy) que el dueño quitará cuando el directorio exista. Mientras
 * esté, la redirección gana: Rank Math resuelve en `wp` y desvía la petición
 * antes de que se renderice nada, así que la página existirá y no será
 * alcanzable — que es el escenario que el encargo declara aceptable. Lo que NO
 * ocurre es que algo se rompa: no hay regla de reescritura nuestra que pueda
 * entrar en conflicto con la suya, ni código que asuma que la redirección ya no
 * está. El día que el dueño la borre, la página empieza a servirse sin tocar
 * este plugin. Y si prefiere otro slug, el shortcode funciona igual en cualquier
 * página: no hay ni una ruta escrita en el código.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\SeriesDirectory;

use Akibara\Business\Utilities\PluginAssetVersion;

defined( 'ABSPATH' ) || exit;

/**
 * Arranque del directorio de series.
 *
 * @since 1.100.0
 */
final class SeriesDirectory {

	/**
	 * Meta descripción de reserva para la página que aloja el directorio.
	 *
	 * Sólo se usa cuando Rank Math no pudo generar NINGUNA descripción. No pisa
	 * la del dueño ni el resumen que Rank Math deriva del contenido: es la red
	 * que evita que la única página nueva de navegación del sitio salga a
	 * producción con la meta vacía porque alguien no rellenó el campo.
	 */
	public const DEFAULT_DESCRIPTION = 'Directorio alfabético de series de manga y cómics en Akibara. Busca una serie o edición y revisa sus tomos en catálogo.';

	private Shortcode $shortcode;

	public function __construct( ?Shortcode $shortcode = null ) {
		$this->shortcode = $shortcode ?? new Shortcode();
	}

	public function register(): void {
		$this->shortcode->register();

		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_assets' ) );
		add_action( 'akibara_series_directory_assets', array( $this, 'enqueue_assets' ) );

		// Se registra sólo si Rank Math está activo: sin él el filtro no existe
		// y el `add_filter` sería inerte. Mismo criterio que `PreventasRoute`.
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			add_filter( 'rank_math/frontend/description', array( $this, 'default_description' ) );
		}
	}

	/**
	 * Encola CSS y JS cuando la página actual contiene el shortcode.
	 *
	 * @internal Callback de `wp_enqueue_scripts`.
	 * @return void
	 */
	public function maybe_enqueue_assets(): void {
		if ( ! is_singular() ) {
			return;
		}

		$post = get_post();
		if ( ! is_object( $post ) || ! is_string( $post->post_content ?? null ) ) {
			return;
		}

		if ( ! has_shortcode( $post->post_content, Shortcode::TAG ) ) {
			return;
		}

		$this->enqueue_assets();
	}

	/**
	 * Encola los assets del directorio.
	 *
	 * Viven en el PLUGIN y no en el theme —a diferencia de `BrandsDirectory`,
	 * que carga `assets/css/brands.css` del stylesheet— porque el directorio es
	 * funcionalidad de este plugin y su JS no tiene ningún vínculo con la
	 * apariencia del theme. Además evita el modo de falla de aquel: si el theme
	 * cambia, su directorio se queda sin estilos en silencio.
	 *
	 * @internal Callback de `akibara_series_directory_assets`.
	 * @return void
	 */
	public function enqueue_assets(): void {
		// Versión del plugin DELANTE del filemtime: el empaquetador congela los
		// mtimes a 1980 para que el ZIP sea reproducible, así que un `?ver=`
		// construido sólo con filemtime es una constante en producción y deja de
		// invalidar nada. Contrato verificado por `CacheBustContractTest`.
		wp_enqueue_style(
			'akb-series-directory',
			AKIBARA_BUSINESS_URL . 'assets/css/series-directory.css',
			array(),
			PluginAssetVersion::for_path( 'assets/css/series-directory.css' )
		);
		wp_enqueue_script(
			'akb-series-directory',
			AKIBARA_BUSINESS_URL . 'assets/js/series-directory.js',
			array(),
			PluginAssetVersion::for_path( 'assets/js/series-directory.js' ),
			true
		);
	}

	/**
	 * Rellena la meta descripción SÓLO si quedó vacía.
	 *
	 * El encargo exige que la página no salga con título ni meta de plantilla
	 * vacía. El `<title>` no corre ese riesgo —Rank Math lo compone del título de
	 * la página y el nombre del sitio—, pero la descripción sí: si el dueño no
	 * escribe el campo y el generador no encuentra extracto, se sirve sin
	 * `meta description`. Esto la cubre y se aparta en cuanto haya cualquier otra.
	 *
	 * @internal Callback de `rank_math/frontend/description`.
	 *
	 * @param mixed $description Descripción generada por Rank Math.
	 * @return string
	 */
	public function default_description( $description ): string {
		$description = is_string( $description ) ? $description : '';
		if ( '' !== trim( $description ) ) {
			return $description;
		}

		return $this->current_post_has_directory() ? self::DEFAULT_DESCRIPTION : $description;
	}

	/**
	 * ¿La entrada que se está sirviendo contiene el directorio?
	 */
	private function current_post_has_directory(): bool {
		if ( ! is_singular() ) {
			return false;
		}

		$post = get_post();
		if ( ! is_object( $post ) || ! is_string( $post->post_content ?? null ) ) {
			return false;
		}

		return has_shortcode( $post->post_content, Shortcode::TAG );
	}
}
