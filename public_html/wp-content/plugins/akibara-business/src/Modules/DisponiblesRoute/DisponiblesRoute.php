<?php
/**
 * DisponiblesRoute — alias /disponibles/ → archive shop con facet `disponibilidad`
 * preseleccionado en "Disponible".
 *
 * Gemelo exacto de PreventasRoute: misma mecánica, la otra mitad del catálogo.
 * `/preventas/` publica lo que todavía no llegó (`onbackorder`); esta ruta
 * publica lo que está disponible para comprar ahora (`instock`).
 *
 * Racional arquitectónico:
 *   Provee una URL canónica y rastreable (`/disponibles/`) para el segmento de productos
 *   con existencias inmediatas (`instock`), equiparando la estructura de `/a-pedido/`.
 *   Conecta con la infraestructura existente de archivo de tienda de WooCommerce,
 *   reutilizando facetas, paginación y ordenamiento sin duplicar templates.
 *
 * Mecánica:
 *   1. `add_rewrite_rule` transforma `/disponibles/` → shop archive (WC carga
 *      `archive-product.php`). El custom query var opera de forma independiente
 *      de `WC()->query` para evitar colisiones con endpoints nativos de WooCommerce.
 *   2. `filter_by_stock_lookup` filtra el listing server-side por
 *      stock_status=instock; `preload_akb_filter` (`akb_search_query_vars`)
 *      deja el checkbox "Disponible" del sidebar tildado como hint visual,
 *      coherente con el grid.
 *
 * Criterio de stock:
 *   Filtra estrictamente por `instock` en `wc_product_meta_lookup.stock_status`,
 *   excluyendo preventas (`onbackorder`) para garantizar que sólo se listen
 *   productos con disponibilidad inmediata.
 *
 * Prerequisitos operacionales:
 *   - No debe existir una página estática publicada con el slug `disponibles`.
 *   - Las reglas se registran al activar y se flushean en actualizaciones mediante
 *     `Plugin::run_upgrade_tasks()`.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\DisponiblesRoute;

use Akibara\Business\Modules\Stock\StockStatus;
use Akibara\Business\Utilities\SchemaGraph;
use Akibara\Business\Utilities\Text;

defined( 'ABSPATH' ) || exit;

final class DisponiblesRoute {

	private const QUERY_VAR   = 'akb_disponibles_route';
	private const FACET_NAME  = 'disponibilidad';

	/**
	 * Valor de la faceta `disponibilidad` para "en stock" — VERIFICADO contra el
	 * buscador propio, no inferido:
	 *   - `Akibara\Search\FacetRegistry::definitions()['disponibilidad']` declara
	 *     `type => 'stock'`.
	 *   - `Akibara\Search\DocumentBuilder::build_facets()`, en `case 'stock'`,
	 *     indexa la fila con `$add( $name, $status, $label )` donde `$status` es
	 *     el `WC_Product::get_stock_status()` CRUDO (instock|onbackorder|outofstock).
	 *   - `Akibara\Search\StockLabels::DEFAULT_LABELS['instock'] === 'Disponible'`
	 *     es solo la palabra visible; el valor almacenado y filtrable es el crudo.
	 * Es decir: el buscador filtra `?_disponibilidad=instock`, y el mismo literal
	 * sirve para la lookup table de WooCommerce (misma columna, mismo vocabulario).
	 */
	private const FACET_VALUE = StockStatus::IN_STOCK;

	/** Cache de la fecha de última actualización del listado. */
	private const UPDATED_TRANSIENT = 'akb_disponibles_updated';

	private const ROUTE_PATH  = '/disponibles';

	/**
	 * Ruta gemela enlazada desde el intro. Espeja `PreventasRoute::ROUTE_PATH`
	 * (privada allá) en lugar de acoplar los dos módulos: el enlace es contenido
	 * de esta página, no una dependencia de comportamiento. Un test compara ambas
	 * constantes por reflexión para que el enlace no se pudra en silencio si
	 * alguien renombra la otra ruta.
	 */
	private const SIBLING_ROUTE_PATH = '/a-pedido';

	/** Opción editable desde Akibara > Textos. */
	public const OPTION_INTRO = 'akb_copy_disponibles_intro';

	/**
	 * Copy inicial sin wrapper; admite únicamente énfasis editorial básico.
	 *
	 * Escrito para un cliente real, no para un motor: responde las dos preguntas
	 * que trae quien llega buscando "stock disponible" — ¿el tomo está de verdad
	 * en Chile? y ¿en qué se diferencia de una preventa? Deliberadamente NO
	 * repite el SLA en horas ("Despacha en 24–48 h" vive en la option
	 * `akb_copy_promise_dispatch` y se muestra en la ficha del producto): dos
	 * copias del mismo número se desincronizan, y la que quede vieja es una
	 * promesa incumplida.
	 */
	/**
	 * Texto descriptivo de la cabecera del archivo de disponibles.
	 *
	 * Comunica claramente la disponibilidad inmediata sin tiempos de importación
	 * y opciones de entrega (despacho o retiro coordinado en Metro San Miguel).
	 */
	public const DEFAULT_INTRO_CONTENT = 'Estos tomos <strong>no esperan importación</strong>: están disponibles para comprar ahora. Al pagar eliges despacho —con las opciones que haya para tu dirección— o retiro coordinado en Metro San Miguel. Es lo contrario de una preventa, donde el tomo todavía viene en camino.';

	/**
	 * Apertura breve del CTA hacia la ruta gemela.
	 *
	 * El DESTINO sigue siendo fijo por diseño —es el enlace estructural entre
	 * las dos rutas del clúster, no una entrada elegible como la guía de
	 * /a-pedido/—, pero el TEXTO sí es una decisión editorial: es la frase que
	 * recupera a quien no encontró su tomo. Esta constante pasa a ser el default
	 * del campo de Akibara → Textos.
	 */
	public const SIBLING_PREFIX = '¿No encuentras el tomo que buscas?';

	/** Texto enlazado a /preventas/, compartido con la UI y las pruebas. */
	public const SIBLING_LINK_COPY = 'Búscalo en los tomos a pedido: los traemos de la editorial con fecha estimada de llegada.';

	/** Options de los dos copys del CTA hacia /a-pedido/. */
	public const OPTION_SIBLING_PREFIX = 'akb_copy_disponibles_sibling_prefix';
	public const OPTION_SIBLING_LINK   = 'akb_copy_disponibles_sibling_link';

	/** Apertura vigente del CTA hacia /a-pedido/. */
	public static function sibling_prefix(): string {
		return Text::one_line_option( self::OPTION_SIBLING_PREFIX, self::SIBLING_PREFIX );
	}

	/** Texto vigente del enlace a /a-pedido/. */
	public static function sibling_link_copy(): string {
		return Text::one_line_option( self::OPTION_SIBLING_LINK, self::SIBLING_LINK_COPY );
	}

	public function register(): void {
		// Las reglas se registran acá y las flushea `Plugin::run_upgrade_tasks()`
		// una vez por bump, en `init` 999 y con las de todos los módulos ya
		// puestas. La guarda propia que vivía aquí (`akb_disponibles_rewrite_version`,
		// autoloaded) comparaba una constante del código contra una option: sólo
		// podía dispararse dentro de un release, que es justo cuando el flush
		// global ya corrió. No podía volver a hacer nada y se cobraba en cada
		// petición del sitio.
		add_action( 'init',                      array( $this, 'add_rewrite' ) );
		add_filter( 'query_vars',                array( $this, 'register_query_var' ) );
		// Prio 1: antes del 404 de FilteredUrlsPolicy (ver el método).
		add_action( 'template_redirect', array( $this, 'redirect_public_query_var' ), 0 );
		add_action( 'template_redirect', array( $this, 'redirect_conflicting_facet' ), 1 );
		add_filter( 'posts_clauses',             array( $this, 'filter_by_stock_lookup' ), 20, 2 );
		add_filter( 'akb_search_query_vars',     array( $this, 'preload_akb_filter' ) );
		add_filter( 'woocommerce_page_title',    array( $this, 'filter_page_title' ) );
		add_filter( 'pre_get_document_title',    array( $this, 'filter_document_title' ) );
		add_filter( 'woocommerce_get_breadcrumb', array( $this, 'filter_breadcrumb' ) );
		// Intro que explica qué significa "disponible" (prio 3, antes del toolbar
		// en prio 4) — mismo punto de inserción que /preventas/.
		add_action( 'woocommerce_before_shop_loop', array( $this, 'render_intro' ), 3 );

		// Rank Math SEO bypassea WP nativos `pre_get_document_title` y genera su
		// propio <title> + meta + canonical + JSON-LD desde wp_head. Sin estos
		// filters, Google ve `<title>Tienda - Akibara</title>` + `canonical=/tienda/`
		// en `/disponibles/` → desindexa la ruta por duplicate content, que en esta
		// ruta en particular sería el fracaso completo del módulo: la razón de
		// existir es ser rastreable y citable.
		// Detección lazy: solo registramos si Rank Math está activo (filter no
		// existe → add_filter es inerte; pero el chequeo evita ruido).
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			add_filter( 'rank_math/frontend/title',       array( $this, 'rm_title' ),       10, 1 );
			add_filter( 'rank_math/frontend/description', array( $this, 'rm_description' ), 10, 1 );
			add_filter( 'rank_math/frontend/canonical',   array( $this, 'rm_canonical' ),   10, 1 );
			add_filter( 'rank_math/frontend/prev_rel_link', array( $this, 'rm_prev_rel_link' ), 10, 1 );
			add_filter( 'rank_math/frontend/next_rel_link', array( $this, 'rm_next_rel_link' ), 10, 1 );
			add_filter( 'rank_math/sitemap/product_content', array( $this, 'rm_product_sitemap_content' ), 10, 1 );
			add_filter( 'rank_math/json_ld',              array( $this, 'rm_jsonld' ),      SchemaGraph::ROUTE_COLLECTIONS, 2 );
		}
	}

	public function add_rewrite(): void {
		// Regla paginada (más específica) primero: `/disponibles/page/N/` mapea al
		// query var público nativo `paged`, que WP_Query honra para calcular el
		// offset del archive. Sin esta regla, `^disponibles/?$` (anclado con `$`)
		// solo matchea la página 1; `/disponibles/page/2/`… caerían en
		// redirect_canonical → 301 a `/disponibles/` (el crawl-trap que se pagó
		// en `/preventas/`: con cientos de productos a 24/página, casi todo el
		// catálogo quedaba inalcanzable para el crawler). `paged` es query var
		// pública nativa: no requiere `query_vars` filter ni tocar `WC()->query`.
		add_rewrite_rule(
			'^disponibles/page/([0-9]{1,})/?$',
			'index.php?post_type=product&' . self::QUERY_VAR . '=1&paged=$matches[1]',
			'top'
		);
		add_rewrite_rule(
			'^disponibles/?$',
			'index.php?post_type=product&' . self::QUERY_VAR . '=1',
			'top'
		);
	}

	/**
	 * @param array<int|string, mixed> $vars
	 * @return array<int|string, mixed>
	 */
	public function register_query_var( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Filtra el archive `/disponibles/` por `stock_status=instock` usando la
	 * lookup table indexada `wc_product_meta_lookup` (KEY `stock_status`) en lugar
	 * de un meta_query sobre `_stock_status` (postmeta cuyo meta_value NO está
	 * indexado → scan de ~1 fila por producto en cada pageload + cada refresh
	 * del buscador). La lookup mantiene stock_status en sync con el postmeta, así
	 * que el resultado es idéntico, por índice.
	 *
	 * Por qué filtrar el query (no solo la faceta): el hint del sidebar solo marca
	 * el checkbox; en los refresh subsequents (orderby/pager) el archive query
	 * quedaría sin filtro → devolvería el catálogo completo en vez de solo los
	 * productos `instock`. Y en ESTA ruta un fallo así no es cosmético: la página
	 * mostraría preventas y agotados bajo un título que promete stock. El checkbox
	 * "Disponible" del sidebar queda como UX hint vía `preload_akb_filter`; el
	 * filtro real es server-side acá.
	 *
	 * Se compone con AND sobre el post__in del buscador propio, sin reemplazarlo.
	 *
	 * @param array<string, string> $clauses Cláusulas SQL del WP_Query.
	 * @param \WP_Query              $query   Query bajo construcción.
	 * @return array<string, string>
	 */
	public function filter_by_stock_lookup( array $clauses, \WP_Query $query ): array {
		if ( '1' !== (string) $query->get( self::QUERY_VAR ) ) {
			return $clauses;
		}

		// Limitar estrictamente a la consulta principal del frontend, evitando
		// interferir con listados administrativos o sub-consultas. Solicitudes directas
		// con query string en otros contextos son procesadas por redirect_public_query_var().
		if ( ! $query->is_main_query() || is_admin() ) {
			return $clauses;
		}

		global $wpdb;
		$lookup = $wpdb->prefix . 'wc_product_meta_lookup';

		// JOIN propio (alias akb_dl); idempotente si el filtro corre dos veces.
		// Alias DISTINTO del `akb_sl` de PreventasRoute: los dos filtros están
		// colgados del mismo hook y, aunque sus guards son excluyentes, un alias
		// compartido convertiría cualquier futuro solape en un error SQL opaco.
		if ( false === strpos( (string) ( $clauses['join'] ?? '' ), 'akb_dl' ) ) {
			$clauses['join'] = ( $clauses['join'] ?? '' )
				. " LEFT JOIN {$lookup} AS akb_dl ON {$wpdb->posts}.ID = akb_dl.product_id";
		}

		$clauses['where'] = ( $clauses['where'] ?? '' )
			. $wpdb->prepare( ' AND akb_dl.stock_status = %s', StockStatus::IN_STOCK );

		return $clauses;
	}

	/**
	 * Hint visual para el buscador PROPIO: en /disponibles/ el checkbox
	 * "Disponible" de disponibilidad aparece tildado y chips/counts quedan
	 * coherentes con el grid. El filtro real sigue siendo server-side
	 * (filter_by_stock_lookup).
	 *
	 * @param array<string, mixed> $vars Query vars crudas del buscador.
	 * @return array<string, mixed>
	 */
	public function preload_akb_filter( array $vars ): array {
		$param = '_' . self::FACET_NAME;
		if ( ! $this->is_disponibles_route() || isset( $vars[ $param ] ) ) {
			return $vars;
		}

		$vars[ $param ] = self::FACET_VALUE;
		return $vars;
	}

	/**
	 * H1 del archive. "Disponibles" y no "En stock" para hablar el MISMO
	 * vocabulario que el resto del sitio: `StockStatus::LABELS['instock']` es
	 * 'Disponible' y así se etiqueta cada card y cada checkbox de la faceta.
	 *
	 * @param string $title
	 */
	public function filter_page_title( $title ): string {
		return $this->is_disponibles_route() ? 'Disponibles' : (string) $title;
	}

	/**
	 * @param string $title
	 */
	public function filter_document_title( $title ): string {
		if ( ! $this->is_disponibles_route() ) {
			return (string) $title;
		}
		return 'Manga en stock disponible en Chile | Akibara';
	}

	/**
	 * Reemplaza el crumb del shop ("Tienda") por "Disponibles" cuando estamos en
	 * el route. Mantiene los crumbs previos (Inicio) y los posteriores
	 * (típicamente "Página N" que WC añade desde `WC_Breadcrumb::paged_trail()`).
	 *
	 * Se identifica el crumb del shop mediante su URL canónica y se renombra in-place,
	 * lo cual preserva la integridad de la jerarquía completa ante paginaciones o facetas activas.
	 *
	 * Detección por URL del shop (no por texto "Tienda") para resistir variaciones
	 * de etiquetas; fallback al último crumb si no coincide la ruta.
	 *
	 * Type hint relajado: WC pasa array pero no garantizado en todos los
	 * code paths (themes/plugins que filtran antes).
	 *
	 * @param mixed $crumbs
	 * @return mixed
	 */
	public function filter_breadcrumb( $crumbs ) {
		if ( ! is_array( $crumbs ) || ! $this->is_disponibles_route() ) {
			return $crumbs;
		}

		$shop_url  = (string) get_permalink( wc_get_page_id( 'shop' ) );
		$shop_path = '' !== $shop_url
			? rtrim( (string) wp_parse_url( $shop_url, PHP_URL_PATH ), '/' )
			: '';

		if ( '' !== $shop_path ) {
			foreach ( $crumbs as $i => $crumb ) {
				if ( ! is_array( $crumb ) || empty( $crumb[1] ) ) {
					continue;
				}
				$crumb_path = rtrim( (string) wp_parse_url( (string) $crumb[1], PHP_URL_PATH ), '/' );
				if ( $crumb_path === $shop_path ) {
					$crumbs[ $i ][0] = 'Disponibles';
					return $crumbs;
				}
			}
		}

		// Fallback: ningún crumb apunta al shop (caso edge) — cambiar último.
		$last = array_key_last( $crumbs );
		if ( null !== $last && isset( $crumbs[ $last ][0] ) ) {
			$crumbs[ $last ][0] = 'Disponibles';
		}
		return $crumbs;
	}

	/**
	 * Reconoce /disponibles/ y /disponibles/page/N/ para mantener título,
	 * canonical, breadcrumb y SEO de la ruta en todas sus páginas.
	 */
	private function is_disponibles_route(): bool {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( (string) $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- el URI no se emite, no se guarda y no entra en SQL: sólo se le extrae el path con wp_parse_url() para compararlo contra literales y contra la regex de /page/N.
		$path = rtrim( (string) wp_parse_url( $uri, PHP_URL_PATH ), '/' );
		if ( self::ROUTE_PATH === $path ) {
			return true;
		}
		return 1 === preg_match( '#^' . preg_quote( self::ROUTE_PATH, '#' ) . '/page/[0-9]+$#', $path );
	}

	/**
	 * Canonical de la ruta, self-referencing por página: `/disponibles/` en la
	 * página 1 y `/disponibles/page/N/` en las paginadas. Evita que todas las
	 * páginas canonicalicen a `/disponibles/` (que ocultaría del índice todos los
	 * productos de las páginas 2+).
	 */
	private function route_canonical(): string {
		$base  = home_url( self::ROUTE_PATH . '/' );
		$paged = (int) get_query_var( 'paged' );
		return $paged > 1 ? $base . 'page/' . $paged . '/' : $base;
	}

	/**
	 * Intro de la ruta (antes del grid): qué significa "disponible" acá y en qué
	 * se diferencia de una preventa, más el enlace a la ruta gemela.
	 *
	 * Solo en la ruta /disponibles/ y solo en la página 1 sin filtros: en páginas
	 * paginadas o filtradas se omite para no repetir contenido editorial. El texto
	 * es editable desde la UI Akibara > Textos.
	 *
	 * @return void
	 */
	public function render_intro(): void {
		if ( ! $this->is_clean_intro_request() ) {
			return;
		}

		$intro   = self::sanitize_intro_content( get_option( self::OPTION_INTRO, self::DEFAULT_INTRO_CONTENT ) );
		$sibling = home_url( self::SIBLING_ROUTE_PATH . '/' );

		if ( '' === trim( wp_strip_all_tags( $intro ) ) ) {
			// El enlace a /preventas/ es contexto del intro, no un bloque propio:
			// sin texto que contextualizar, un CTA suelto sobre el grid es ruido.
			return;
		}
		?>
		<section class="akb-disponibles-intro" aria-label="Qué significa que un tomo esté disponible">
			<p class="akb-disponibles-intro__lead"><?php echo $intro; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- allowlist local en sanitize_intro_content(). ?></p>
			<p class="akb-disponibles-intro__sibling">
				<?php echo esc_html( self::sibling_prefix() ); ?>
				<a href="<?php echo esc_url( $sibling ); ?>"><?php echo esc_html( self::sibling_link_copy() ); ?></a>
			</p>
			<?php $this->render_updated_line(); ?>
		</section>
		<?php
	}

	/**
	 * Indica si corresponde mostrar contenido editorial en la ruta.
	 *
	 * @internal
	 */
	public function is_clean_intro_request(): bool {
		return $this->is_disponibles_route()
			&& (int) get_query_var( 'paged' ) <= 1
			&& empty( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- empty() sólo mira si el array de la query está vacío; no lee ningún valor ni muta nada.
	}

	/**
	 * Sanitiza el copy administrable sin permitir wrappers, scripts ni headings.
	 *
	 * Misma allowlist que el intro de preventas: el copy es un párrafo, no un
	 * bloque de layout. Se deja como método propio (y no un `use` del otro
	 * módulo) para que cada ruta pueda endurecer su allowlist sin arrastrar a la
	 * otra; el contrato compartido es la forma, no la implementación.
	 *
	 * @param mixed $value Valor recibido desde WooCommerce o la option.
	 */
	public static function sanitize_intro_content( $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		return trim(
			(string) wp_kses(
				(string) $value,
				array(
					'strong' => array(),
					'em'     => array(),
					'br'     => array(),
				)
			)
		);
	}

	// -----------------------------------------------------------------------
	// Rank Math SEO bridge — registrados condicionalmente en register() si
	// Rank Math está activo. Sin estos, `/disponibles/` se serviría con
	// `<title>`, canonical, og y JSON-LD del archive `/tienda/` → duplicate
	// content y desindexación de la única ruta que responde la consulta C06.
	// -----------------------------------------------------------------------

	/**
	 * @param string $title Title actual Rank Math.
	 */
	public function rm_title( $title ): string {
		if ( ! $this->is_disponibles_route() ) {
			return (string) $title;
		}
		return 'Manga en stock disponible en Chile | Akibara';
	}

	/**
	 * Description honesta: describe lo que la página ES (una lista de productos
	 * `instock`, disponibles para comprar sin espera de importación) sin convertir
	 * ese flag de vendibilidad en una afirmación de posesión física.
	 *
	 * El cierre habla de «las opciones disponibles para tu destino» porque esta
	 * página ofrece DOS salidas —despacho y retiro coordinado— y cuál aplica lo
	 * decide el checkout por comuna.
	 *
	 * Este docblock afirmaba además una disciplina de copy de toda la tienda
	 * («NUNCA envío a todo Chile»). No es cierta y se retira: la ruta gemela
	 * `/a-pedido/` promete «con despacho a todo Chile» en su propia meta, el
	 * generador de meta de serie cierra con «Cómpralo en Akibara con despacho a
	 * todo Chile», y otras trece superficies dicen lo mismo — una de ellas fijada
	 * por un test del tema en sentido contrario. Una regla que sólo cumple el
	 * sitio que la escribe no es una regla: es una nota que manda al siguiente
	 * lector a «arreglar» catorce textos que el dueño aprobó. Unificar el copy de
	 * despacho en toda la tienda es una decisión suya, no de este módulo.
	 *
	 * @param string $description Meta description actual Rank Math.
	 */
	public function rm_description( $description ): string {
		if ( ! $this->is_disponibles_route() ) {
			return (string) $description;
		}
		return 'Catálogo de manga y cómics disponibles para comprar ahora, sin espera de importación. Despacho o retiro según las opciones disponibles para tu destino.';
	}

	/**
	 * Canonical correcto: la ruta es `/disponibles/`, no `/tienda/`. Sin esto,
	 * Rank Math genera `canonical=/tienda/` y Google desindexa la ruta.
	 *
	 * @param string $canonical Canonical actual Rank Math.
	 */
	public function rm_canonical( $canonical ): string {
		if ( ! $this->is_disponibles_route() ) {
			return (string) $canonical;
		}
		return $this->route_canonical();
	}

	/**
	 * Corrige el rel=prev que Rank Math hereda del archive `/tienda/`.
	 *
	 * @param string $link Elemento `<link>` generado por Rank Math.
	 */
	public function rm_prev_rel_link( $link ): string {
		if ( ! $this->is_disponibles_route() ) {
			return (string) $link;
		}

		$paged = (int) get_query_var( 'paged' );
		if ( $paged < 2 ) {
			return (string) $link;
		}

		$base = home_url( self::ROUTE_PATH . '/' );
		$url  = 2 === $paged ? $base : $base . 'page/' . ( $paged - 1 ) . '/';

		return sprintf( '<link rel="prev" href="%s" />', esc_url( $url ) );
	}

	/**
	 * Corrige el rel=next que Rank Math hereda del archive `/tienda/`.
	 *
	 * Rank Math solo ejecuta el filtro cuando existe una página siguiente, por
	 * lo que conservamos esa decisión y sustituimos únicamente la URL base.
	 *
	 * @param string $link Elemento `<link>` generado por Rank Math.
	 */
	public function rm_next_rel_link( $link ): string {
		if ( ! $this->is_disponibles_route() ) {
			return (string) $link;
		}

		$paged = max( 1, (int) get_query_var( 'paged' ) );
		$url   = home_url( self::ROUTE_PATH . '/page/' . ( $paged + 1 ) . '/' );

		return sprintf( '<link rel="next" href="%s" />', esc_url( $url ) );
	}

	/**
	 * Añade la raíz virtual de disponibles al primer sitemap de productos.
	 *
	 * Rank Math no puede descubrirla porque no existe como post/término, y una
	 * ruta que nadie puede descubrir no resuelve el problema que motivó el
	 * módulo. No se agregan páginas 2+: igual que en el resto de archives, los
	 * enlaces del listing se encargan de descubrir la paginación.
	 *
	 * Convive con el mismo filtro de PreventasRoute (los dos concatenan y los dos
	 * son idempotentes por `<loc>`), así que el orden de registro no importa.
	 *
	 * @param string $xml Contenido adicional del sitemap de productos.
	 */
	public function rm_product_sitemap_content( $xml ): string {
		$xml = (string) $xml;
		$url = home_url( self::ROUTE_PATH . '/' );
		if ( false !== strpos( $xml, '<loc>' . $url . '</loc>' ) ) {
			return $xml;
		}

		return $xml . "\n\t<url>\n\t\t<loc>" . esc_url( $url ) . "</loc>\n\t</url>";
	}

	/**
	 * Ajusta el JSON-LD que Rank Math escupe en el head:
	 *   - BreadcrumbList: último item → nombre de la ruta / `/disponibles/`.
	 *   - CollectionPage: name → "… - Akibara", url → `/disponibles/`.
	 *
	 * @param array<string, mixed> $data
	 * @param mixed                $jsonld_obj
	 * @return array<string, mixed>
	 */
	public function rm_jsonld( $data, $jsonld_obj ): array {
		unset( $jsonld_obj );
		if ( ! is_array( $data ) || ! $this->is_disponibles_route() ) {
			return is_array( $data ) ? $data : array();
		}

		$canonical = $this->route_canonical();
		$paged     = (int) get_query_var( 'paged' );
		$page_name = $paged > 1
			? sprintf( 'Manga en stock disponible – Página %d', $paged )
			: 'Manga en stock disponible';

		foreach ( $data as $key => $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$type = $node['@type'] ?? '';

			if ( 'BreadcrumbList' === $type && isset( $node['itemListElement'] ) && is_array( $node['itemListElement'] ) ) {
				$last_key = array_key_last( $node['itemListElement'] );
				if ( null !== $last_key && isset( $node['itemListElement'][ $last_key ]['item'] ) ) {
					$node['itemListElement'][ $last_key ]['item']['name'] = $page_name;
					$node['itemListElement'][ $last_key ]['item']['@id']  = $canonical;
				}
				$data[ $key ] = $node;
				continue;
			}

			if ( 'CollectionPage' === $type ) {
				$node['name'] = $page_name . ' - Akibara';
				$node['url']  = $canonical;
				if ( isset( $node['@id'] ) ) {
					$node['@id'] = $canonical . '#webpage';
				}
				$data[ $key ] = $node;
			}
		}

		return $data;
	}

	/**
	 * Rescata selecciones de disponibilidad contradictorias con la ruta.
	 *
	 * Cuando el usuario selecciona una faceta distinta a disponible (por ejemplo, "Preventa"),
	 * se redirige mediante 302 hacia el archivo general de tienda conservando los filtros elegidos,
	 * evitando estados vacíos de 0 resultados y garantizando una experiencia coherente.
	 *
	 * Redirección temporal 302 para preservar la sesión y prioridad 1 para anticipar
	 * validaciones de rutas filtradas.
	 *
	 * @internal Callback del hook `template_redirect`.
	 */
	/**
	 * Redirige consultas directas por query var hacia la ruta canónica.
	 *
	 * Evita que solicitudes directas con `?akb_disponibles_route=1` desvíen la resolución
	 * estándar de la plantilla de inicio, asegurando un redireccionamiento 301 canónico.
	 *
	 * @return void
	 */
	public function redirect_public_query_var(): void {
		// Primero la comprobación barata: esto corre en CADA petición del sitio y
		// el caso normal es que la variable no venga por la URL.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lectura de una URL pública, sin efectos.
		if ( ! isset( $_GET[ self::QUERY_VAR ] ) ) {
			return;
		}

		// En la ruta de verdad el query var lo puso la regla de reescritura.
		if ( $this->is_disponibles_route() ) {
			return;
		}

		wp_safe_redirect( home_url( self::ROUTE_PATH . '/' ), 301 );
		exit;
	}

	public function redirect_conflicting_facet(): void {
		if ( ! $this->is_disponibles_route() ) {
			return;
		}

		$param = '_' . self::FACET_NAME;
		if ( ! isset( $_GET[ $param ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- isset() sobre un parámetro de faceta en un GET público que sólo decide si hay redirect que hacer; no hay estado que proteger con nonce.
			return;
		}

		$raw = wp_unslash( $_GET[ $param ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- GET público que sólo redirige, sin formulario que proteger; y el valor se aplana por comas y pasa por sanitize_key() unas líneas abajo, porque sanearlo aquí borraría la coma que separa los valores de la faceta.

		// Las facetas pueden recibirse en formato de array (_x[]=a) o separadas por
		// comas (_x=a,b). Se aplana primero por comas y luego se sanea cada elemento
		// individual con sanitize_key(), preservando la integridad de los valores múltiples.
		$parts = array();
		foreach ( (array) $raw as $value ) {
			if ( is_array( $value ) ) {
				continue;
			}
			foreach ( explode( ',', (string) $value ) as $piece ) {
				$parts[] = $piece;
			}
		}

		$selected = array_values( array_unique( array_filter( array_map( 'sanitize_key', $parts ) ) ) );
		if ( empty( $selected ) || array( self::FACET_VALUE ) === array_values( $selected ) ) {
			return; // Coincide con la ruta: nada que rescatar.
		}

		$shop = get_permalink( wc_get_page_id( 'shop' ) );
		if ( ! is_string( $shop ) || '' === $shop ) {
			return; // Sin destino conocido, mejor la página actual que un redirect roto.
		}

		wp_safe_redirect( add_query_arg( array( $param => array_values( $selected ) ), $shop ), 302 );
		exit;
	}


	/**
	 * Fecha de última actualización del catálogo en este estado.
	 *
	 * Obtiene el `post_modified` más reciente de los productos disponibles para entrega inmediata
	 * leídos directamente del índice, proveyendo frescura verificable para motores
	 * de búsqueda e indexación estructurada.
	 *
	 * Se almacena en transient durante 6 horas para minimizar consultas repetitivas.
	 *
	 * @return string Fecha ISO (Y-m-d) o '' si no se pudo determinar.
	 */
	private function last_updated_date(): string {
		$cached = get_transient( self::UPDATED_TRANSIENT );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		global $wpdb;
		// Defensivo a propósito: esta línea es decorativa-informativa, así que
		// jamás debe tumbar el render del archive si el global no está listo.
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return '';
		}

		$lookup = $wpdb->prefix . 'wc_product_meta_lookup';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$lookup} es $wpdb->prefix . 'wc_product_meta_lookup' (tabla propia de WooCommerce, sin entrada de usuario); el único valor viaja por el marcador %s.
		$date = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MAX(p.post_modified) FROM {$wpdb->posts} p
				 INNER JOIN {$lookup} l ON l.product_id = p.ID
				 WHERE p.post_type = 'product' AND p.post_status = 'publish' AND l.stock_status = %s",
				// Valor de ESQUEMA, igual que en `filter_by_stock_lookup()`. En esta
				// ruta `FACET_VALUE` coincide por casualidad con el estado crudo
				// (`instock` es a la vez faceta y columna); en la gemela `/a-pedido/`
				// no coincidía y la fecha no se imprimió nunca. Nombrar la columna
				// por su constante cierra el mismo agujero acá antes de que exista.
				StockStatus::IN_STOCK
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! is_string( $date ) || '' === $date ) {
			return '';
		}

		// post_modified ya usa la zona del sitio; toma sus diez primeros caracteres
		// para la fecha, sin reinterpretarla como UTC ni aplicar otra conversión.
		$iso = substr( (string) $date, 0, 10 );
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $iso ) ) {
			return '';
		}
		set_transient( self::UPDATED_TRANSIENT, $iso, 6 * HOUR_IN_SECONDS );

		return $iso;
	}

	/**
	 * Línea visible de frescura. Vacía si no hay fecha: mejor nada que un dato
	 * inventado.
	 */
	private function render_updated_line(): void {
		$iso = $this->last_updated_date();
		if ( '' === $iso ) {
			return;
		}

		// Mediodía a propósito: fija el timestamp lejos de los bordes del día,
		// así ningún ajuste de zona en wp_date() puede correr la fecha visible
		// respecto del ISO que ya se calculó.
		$ts = (int) strtotime( $iso . ' 12:00:00' );
		?>
		<p class="akb-route-updated">
			Catálogo actualizado el
			<time datetime="<?php echo esc_attr( $iso ); ?>"><?php echo esc_html( wp_date( 'j \d\e F \d\e Y', $ts ) ); ?></time>
		</p>
		<?php
	}

}
