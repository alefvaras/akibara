<?php
/**
 * PreventasRoute — alias /preventas/ → archive shop con facet `disponibilidad`
 * preseleccionado en "Preventa".
 *
 * El URL pretty `/preventas/` se preserva en la barra de direcciones mientras
 * el servidor renderea el shop archive de WC (page /tienda/) filtrando por
 * stock_status=onbackorder.
 *
 * Por qué este patrón:
 *   `/tienda/?_disponibilidad=onbackorder` ya tiene las facetas + sort + pager
 *   + breadcrumbs del buscador propio. Duplicar esa infraestructura sería deuda.
 *   Este módulo conecta `/preventas/` con esa arquitectura existente, sin
 *   shortcode/template nuevos.
 *
 * Mecánica:
 *   1. `add_rewrite_rule` transforma `/preventas/` → shop archive (WC carga
 *      `archive-product.php`). El custom query var opera de forma independiente
 *      de `WC()->query` para evitar colisiones con endpoints nativos de WooCommerce.
 *   2. `filter_by_stock_lookup` filtra el listing server-side por
 *      stock_status=onbackorder; `preload_akb_filter` (`akb_search_query_vars`)
 *      deja el checkbox "Preventa" del sidebar tildado como hint visual,
 *      coherente con el grid.
 *
 * Prerequisitos operacionales:
 *   - El post con slug `preventas` NO debe existir publicado. WP resuelve la
 *     page antes que la rewrite rule (page-blocker).
 *   - Flush rewrite rules tras activar (`wp rewrite flush --hard`).
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\PreventasRoute;

use Akibara\Business\Modules\Stock\StockStatus;
use Akibara\Business\Utilities\SchemaGraph;
use Akibara\Business\Utilities\Text;

defined( 'ABSPATH' ) || exit;

final class PreventasRoute {

	private const QUERY_VAR    = 'akb_preventas_route';
	private const FACET_NAME   = 'disponibilidad';

	/**
	 * Valor público de la faceta comercial (chip y preload del sidebar).
	 *
	 * Corresponde al slug público `a_pedido`. Nótese que las consultas SQL en
	 * `filter_by_stock_lookup()` consultan directamente el valor interno de
	 * WooCommerce en `wc_product_meta_lookup.stock_status` (`StockStatus::ON_BACKORDER`),
	 * manteniendo desacoplada la presentación facetada de la persistencia de stock.
	 */
	private const FACET_VALUE  = 'a_pedido';
	/** Cache de la fecha de última actualización del listado. */
	private const UPDATED_TRANSIENT = 'akb_preventas_updated';

	private const ROUTE_PATH   = '/a-pedido';

	/** Opción editable desde Akibara > Textos. */
	public const OPTION_INTRO = 'akb_copy_preventas_intro';

	/** Entrada publicada vinculada como guía editorial de preventas. */
	public const OPTION_GUIDE = 'akb_preventas_guide_post_id';

	/** Copy inicial sin wrapper; admite únicamente énfasis editorial básico. */
	public const DEFAULT_INTRO_CONTENT = 'Un tomo a pedido <strong>ya está publicado y lo traemos por encargo</strong>: entra en el próximo pedido a la editorial y te avisamos apenas llega a Akibara. La <strong>fecha estimada de llegada</strong> está en cada título.';

	/** Apertura breve del CTA editorial. Default del campo homónimo en Textos. */
	public const GUIDE_PREFIX = '¿Es tu primera reserva?';

	/** Texto enlazado de la guía, compartido con la UI y las pruebas. */
	public const GUIDE_LINK_COPY = 'Lee nuestra guía sobre cómo funcionan los pedidos y preventas de manga, los plazos y qué ocurre cuando combinas productos en stock con un pedido.';

	/**
	 * Options de los cuatro copys del bloque editorial de /a-pedido/.
	 *
	 * Las constantes de arriba pasan a ser el DEFAULT de cada campo: la frase que
	 * decide si alguien entiende el modelo de encargo —y la que enlaza la única
	 * vía interna hacia /disponibles/— dejó de exigir un despliegue para cambiar
	 * una coma. Vacías vuelven al literal: un enlace sin texto no es una decisión
	 * editorial, es un hueco (y en el caso del gemelo, una ruta huérfana).
	 */
	public const OPTION_GUIDE_PREFIX    = 'akb_copy_preventas_guide_prefix';
	public const OPTION_GUIDE_LINK      = 'akb_copy_preventas_guide_link';
	public const OPTION_SIBLING_PREFIX  = 'akb_copy_preventas_sibling_prefix';
	public const OPTION_SIBLING_LINK    = 'akb_copy_preventas_sibling_link';

	/** Apertura vigente del CTA de la guía. */
	public static function guide_prefix(): string {
		return Text::one_line_option( self::OPTION_GUIDE_PREFIX, self::GUIDE_PREFIX );
	}

	/** Texto vigente del enlace a la guía. */
	public static function guide_link_copy(): string {
		return Text::one_line_option( self::OPTION_GUIDE_LINK, self::GUIDE_LINK_COPY );
	}

	/** Apertura vigente del CTA hacia /disponibles/. */
	public static function sibling_prefix(): string {
		return Text::one_line_option( self::OPTION_SIBLING_PREFIX, self::SIBLING_PREFIX );
	}

	/** Texto vigente del enlace a /disponibles/. */
	public static function sibling_link_copy(): string {
		return Text::one_line_option( self::OPTION_SIBLING_LINK, self::SIBLING_LINK_COPY );
	}

	/**
	 * Ruta gemela: el otro estado del catálogo.
	 *
	 * Espeja `DisponiblesRoute::ROUTE_PATH` (privada allá) igual que ella espeja
	 * a ésta — el enlace es contenido de la página, no una dependencia entre
	 * módulos. Un test compara ambas constantes por reflexión para que renombrar
	 * una ruta no deje el enlace de la otra en 404 silencioso.
	 */
	private const SIBLING_ROUTE_PATH = '/disponibles';

	/** Apertura breve del CTA hacia la ruta gemela. */
	public const SIBLING_PREFIX = '¿Prefieres algo que llegue ya?';

	/**
	 * Texto de enlace interno bidireccional hacia /disponibles/.
	 *
	 * Proporciona descubrimiento interno orgánico entre las dos vistas de disponibilidad
	 * del catálogo, facilitando la navegación del usuario y el rastreo estructurado.
	 */
	public const SIBLING_LINK_COPY = 'Mira los tomos disponibles: los que ya están para comprar sin esperar importación.';

	public function register(): void {
		// Las reglas se registran acá y las flushea `Plugin::run_upgrade_tasks()`
		// una vez por bump, en `init` 999 y con las de todos los módulos ya
		// puestas. La guarda propia que vivía aquí (`akb_preventas_rewrite_version`,
		// autoloaded) comparaba una constante del código contra una option: sólo
		// podía dispararse dentro de un release, que es justo cuando el flush
		// global ya corrió. No podía volver a hacer nada y se cobraba en cada
		// petición del sitio.
		add_action( 'init',                      array( $this, 'add_rewrite' ) );
		add_filter( 'query_vars',                array( $this, 'register_query_var' ) );
		// Prio 1: antes del 404 de FilteredUrlsPolicy (ver el método).
		add_action( 'template_redirect', array( $this, 'redirect_public_query_var' ), 0 );
		add_action( 'template_redirect', array( $this, 'redirect_legacy_path' ), 0 );
		add_action( 'template_redirect', array( $this, 'redirect_conflicting_facet' ), 1 );
		add_filter( 'posts_clauses',             array( $this, 'filter_by_stock_lookup' ), 20, 2 );
		add_filter( 'akb_search_query_vars',     array( $this, 'preload_akb_filter' ) );
		add_filter( 'woocommerce_page_title',    array( $this, 'filter_page_title' ) );
		add_filter( 'pre_get_document_title',    array( $this, 'filter_document_title' ) );
		add_filter( 'woocommerce_get_breadcrumb', array( $this, 'filter_breadcrumb' ) );
		// Intro educativo del modelo de preventa (prio 3, antes del toolbar en prio 4).
		add_action( 'woocommerce_before_shop_loop', array( $this, 'render_intro' ), 3 );

		// Rank Math SEO bypassea WP nativos `pre_get_document_title` y genera su
		// propio <title> + meta + canonical + JSON-LD desde wp_head. Sin estos
		// filters, Google ve `<title>Tienda - Akibara</title>` + `canonical=/tienda/`
		// en `/preventas/` → desindexa la ruta por duplicate content.
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
		// Regla paginada (más específica) primero: `/preventas/page/N/` mapea al
		// query var público nativo `paged`, que WP_Query honra para calcular el
		// offset del archive. Sin esta regla, `^preventas/?$` (anclado con `$`)
		// solo matchea la página 1; `/preventas/page/2/`… caían en
		// redirect_canonical → 301 a `/preventas/` (crawl-trap: con ~483
		// productos onbackorder a 24/página, ~20 páginas y ~459 productos
		// quedaban inalcanzables). `paged` es query var pública nativa: no
		// requiere `query_vars` filter ni tocar `WC()->query`.
		add_rewrite_rule(
			'^a-pedido/page/([0-9]{1,})/?$',
			'index.php?post_type=product&' . self::QUERY_VAR . '=1&paged=$matches[1]',
			'top'
		);
		add_rewrite_rule(
			'^a-pedido/?$',
			'index.php?post_type=product&' . self::QUERY_VAR . '=1',
			'top'
		);

		// Legacy `/preventas/*` (v3): las reglas se CONSERVAN mapeando al mismo
		// query var para que la URL vieja siga resolviendo el archive — y
		// redirect_legacy_path() la manda con 301 a la canónica `/a-pedido/*`.
		// Sin estas reglas el path viejo caería en un 404 (o en el slug de un
		// post homónimo) y el 301 nunca llegaría a ejecutarse.
		add_rewrite_rule(
			'^preventas/page/([0-9]{1,})/?$',
			'index.php?post_type=product&' . self::QUERY_VAR . '=1&paged=$matches[1]',
			'top'
		);
		add_rewrite_rule(
			'^preventas/?$',
			'index.php?post_type=product&' . self::QUERY_VAR . '=1',
			'top'
		);
	}

	/**
	 * Redirección permanente 301 de la ruta heredada `/preventas/*` hacia `/a-pedido/*`.
	 *
	 * Preserva la paginación relativa (`/preventas/page/N/` → `/a-pedido/page/N/`).
	 *
	 * @return void
	 */
	public function redirect_legacy_path(): void {
		if ( ! $this->is_preventas_route() ) {
			return;
		}

		$request_path = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- solo se compara el path parseado; el destino se construye desde cero.
		if ( 1 !== preg_match( '#^/preventas(?:/page/([0-9]{1,}))?/?$#', $request_path, $matches ) ) {
			return;
		}

		$destino = home_url( self::ROUTE_PATH . ( isset( $matches[1] ) ? '/page/' . $matches[1] : '' ) . '/' );
		wp_safe_redirect( $destino, 301 );
		exit;
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
	 * Filtra el archive `/preventas/` por `stock_status=onbackorder` usando la
	 * lookup table indexada `wc_product_meta_lookup` (KEY `stock_status`) en lugar
	 * de un meta_query sobre `_stock_status` (postmeta cuyo meta_value NO está
	 * indexado → scan de ~1 fila por producto en cada pageload + cada refresh
	 * del buscador). La lookup mantiene stock_status en sync con el postmeta, así
	 * que el resultado es idéntico, por índice.
	 *
	 * Por qué filtrar el query (no solo la faceta): el hint del sidebar solo marca
	 * el checkbox; en los refresh subsequents (orderby/pager) el archive query
	 * quedaría sin filtro → devolvería los ~1368 productos en vez de los ~679
	 * onbackorder. El checkbox "Preventa" del sidebar queda como UX hint vía
	 * `preload_akb_filter`; el filtro real es server-side acá.
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

		// JOIN propio (alias akb_sl); idempotente si el filtro corre dos veces.
		if ( false === strpos( (string) ( $clauses['join'] ?? '' ), 'akb_sl' ) ) {
			$clauses['join'] = ( $clauses['join'] ?? '' )
				. " LEFT JOIN {$lookup} AS akb_sl ON {$wpdb->posts}.ID = akb_sl.product_id";
		}

		$clauses['where'] = ( $clauses['where'] ?? '' )
			. $wpdb->prepare( ' AND akb_sl.stock_status = %s', StockStatus::ON_BACKORDER );

		return $clauses;
	}

	/**
	 * Hint visual para el buscador PROPIO: en /preventas/ el checkbox "Preventa"
	 * de disponibilidad aparece tildado y chips/counts quedan coherentes con el
	 * grid. El filtro real sigue siendo server-side (filter_by_stock_lookup).
	 *
	 * @param array<string, mixed> $vars Query vars crudas del buscador.
	 * @return array<string, mixed>
	 */
	public function preload_akb_filter( array $vars ): array {
		$param = '_' . self::FACET_NAME;
		if ( ! $this->is_preventas_route() || isset( $vars[ $param ] ) ) {
			return $vars;
		}

		$vars[ $param ] = self::FACET_VALUE;
		return $vars;
	}

	/**
	 * @param string $title
	 */
	public function filter_page_title( $title ): string {
		return $this->is_preventas_route() ? 'A pedido' : (string) $title;
	}

	/**
	 * @param string $title
	 */
	public function filter_document_title( $title ): string {
		if ( ! $this->is_preventas_route() ) {
			return (string) $title;
		}
		return 'Manga y cómics a pedido en Chile | Akibara';
	}

	/**
	 * Reemplaza el crumb del shop ("Tienda") por "Preventas" cuando estamos en
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
		if ( ! is_array( $crumbs ) || ! $this->is_preventas_route() ) {
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
					$crumbs[ $i ][0] = 'A pedido';
					return $crumbs;
				}
			}
		}

		// Fallback: ningún crumb apunta al shop (caso edge) — cambiar último.
		$last = array_key_last( $crumbs );
		if ( null !== $last && isset( $crumbs[ $last ][0] ) ) {
			$crumbs[ $last ][0] = 'A pedido';
		}
		return $crumbs;
	}

	/**
	 * True en `/preventas/` y en sus variantes paginadas `/preventas/page/N/`.
	 *
	 * El match estricto `=== ROUTE_PATH` solo reconocía la página 1, así que en
	 * `/preventas/page/2/`… el título/canonical/breadcrumb/SEO revertían al
	 * archive `/tienda/` (duplicate content). Se reconoce también el sufijo
	 * `/page/N` para mantener coherencia en toda la paginación.
	 */
	private function is_preventas_route(): bool {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( (string) $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- el URI no se emite, no se guarda y no entra en SQL: sólo se le extrae el path con wp_parse_url() para compararlo contra literales y contra la regex de /page/N.
		$path = rtrim( (string) wp_parse_url( $uri, PHP_URL_PATH ), '/' );

		// El path legacy `/preventas` cuenta como la ruta (v3): sus reglas de
		// reescritura mapean al mismo query var y redirect_legacy_path() depende
		// de este gate para poder emitir el 301 hacia la canónica. Sin el alias,
		// la petición legacy se serviría con el chrome del shop («Tienda») y sin
		// redirect — el peor de los dos mundos.
		foreach ( array( self::ROUTE_PATH, '/preventas' ) as $ruta ) {
			if ( $ruta === $path
				|| 1 === preg_match( '#^' . preg_quote( $ruta, '#' ) . '/page/[0-9]+$#', $path ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Canonical de la ruta, self-referencing por página: `/preventas/` en la
	 * página 1 y `/preventas/page/N/` en las paginadas. Evita que todas las
	 * páginas canonicalicen a `/preventas/` (que ocultaría del índice los ~459
	 * productos de las páginas 2+).
	 */
	private function route_canonical(): string {
		$base  = home_url( self::ROUTE_PATH . '/' );
		$paged = (int) get_query_var( 'paged' );
		return $paged > 1 ? $base . 'page/' . $paged . '/' : $base;
	}

	/**
	 * Intro educativo del modelo de preventa en /preventas/ (antes del grid).
	 *
	 * Explica el flujo (reservas ahora, despachamos al llegar) y referencia la
	 * fecha estimada que cada card ya muestra (ver EstimatedDelivery). Solo en la
	 * ruta /preventas/; editable desde la UI Akibara > Textos. En páginas
	 * paginadas o filtradas se omite para no repetir contenido editorial.
	 *
	 * @since 1.49.9
	 * @return void
	 */
	public function render_intro(): void {
		if ( ! $this->is_clean_intro_request() ) {
			return;
		}

		$intro    = self::sanitize_intro_content( get_option( self::OPTION_INTRO, self::DEFAULT_INTRO_CONTENT ) );
		$guide_id = self::published_post_id( get_option( self::OPTION_GUIDE, 0 ) );
		$guide_url = $guide_id > 0 ? get_permalink( $guide_id ) : '';
		if ( ! is_string( $guide_url ) ) {
			$guide_url = '';
		}

		// Antes había aquí un early-return cuando intro y guía estaban vacíos. Ya
		// no: el enlace a la ruta gemela es la ÚNICA vía de descubrimiento
		// interno de /disponibles/ (el dueño la trata como estado, no como
		// sección de navegación), así que la sección se renderea igual aunque se
		// vacíe el copy editable. El bloque nunca queda hueco: el párrafo del
		// enlace siempre está.
		?>
		<section class="akb-preventas-intro" aria-label="Cómo funcionan los pedidos">
			<?php if ( '' !== trim( wp_strip_all_tags( $intro ) ) ) : ?>
				<p class="akb-preventas-intro__lead"><?php echo $intro; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- allowlist local en sanitize_intro_content(). ?></p>
			<?php endif; ?>
			<?php if ( '' !== $guide_url ) : ?>
				<p class="akb-preventas-intro__guide">
					<?php echo esc_html( self::guide_prefix() ); ?>
					<a href="<?php echo esc_url( $guide_url ); ?>"><?php echo esc_html( self::guide_link_copy() ); ?></a>
				</p>
			<?php endif; ?>
			<p class="akb-preventas-intro__sibling">
				<?php echo esc_html( self::sibling_prefix() ); ?>
				<a href="<?php echo esc_url( home_url( self::SIBLING_ROUTE_PATH . '/' ) ); ?>"><?php echo esc_html( self::sibling_link_copy() ); ?></a>
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
		return $this->is_preventas_route()
			&& (int) get_query_var( 'paged' ) <= 1
			&& empty( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- empty() sólo mira si el array de la query está vacío; no lee ningún valor ni muta nada.
	}

	/**
	 * Sanitiza el copy administrable sin permitir wrappers, scripts ni headings.
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

	/**
	 * Normaliza un destino y rechaza borradores, páginas y adjuntos.
	 *
	 * @param mixed $value ID recibido desde options/admin.
	 */
	public static function published_post_id( $value ): int {
		if ( ! is_scalar( $value ) ) {
			return 0;
		}

		$post_id = absint( $value );
		return $post_id > 0
			&& 'post' === get_post_type( $post_id )
			&& 'publish' === get_post_status( $post_id )
				? $post_id
				: 0;
	}

	// -----------------------------------------------------------------------
	// Rank Math SEO bridge — registrados condicionalmente en register() si
	// Rank Math está activo. Sin estos, `/preventas/` se servía con `<title>`,
	// canonical, og y JSON-LD del archive `/tienda/` → duplicate content.
	// -----------------------------------------------------------------------

	/**
	 * @param string $title Title actual Rank Math.
	 */
	public function rm_title( $title ): string {
		if ( ! $this->is_preventas_route() ) {
			return (string) $title;
		}
		return 'Manga y cómics a pedido en Chile | Akibara';
	}

	/**
	 * @param string $description Meta description actual Rank Math.
	 */
	public function rm_description( $description ): string {
		if ( ! $this->is_preventas_route() ) {
			return (string) $description;
		}
		return 'Manga y cómics a pedido, con despacho a todo Chile: los pedimos a la editorial por ti y te avisamos por correo apenas llegan a nuestra bodega.';
	}

	/**
	 * Canonical correcto: la ruta es `/preventas/`, no `/tienda/`. Sin esto,
	 * Rank Math genera `canonical=/tienda/` y Google desindexa la ruta.
	 *
	 * @param string $canonical Canonical actual Rank Math.
	 */
	public function rm_canonical( $canonical ): string {
		if ( ! $this->is_preventas_route() ) {
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
		if ( ! $this->is_preventas_route() ) {
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
		if ( ! $this->is_preventas_route() ) {
			return (string) $link;
		}

		$paged = max( 1, (int) get_query_var( 'paged' ) );
		$url   = home_url( self::ROUTE_PATH . '/page/' . ( $paged + 1 ) . '/' );

		return sprintf( '<link rel="next" href="%s" />', esc_url( $url ) );
	}

	/**
	 * Añade la raíz virtual de preventas al primer sitemap de productos.
	 *
	 * Rank Math no puede descubrirla porque no existe como post/término. No se
	 * agregan páginas 2+: igual que en el resto de archives, los enlaces del
	 * listing se encargan de descubrir la paginación.
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
	 *   - BreadcrumbList: último item → "Preventas" / `/preventas/`.
	 *   - CollectionPage: name → "Preventas - Akibara", url → `/preventas/`.
	 *
	 * @param array<string, mixed> $data
	 * @param mixed                $jsonld_obj
	 * @return array<string, mixed>
	 */
	public function rm_jsonld( $data, $jsonld_obj ): array {
		unset( $jsonld_obj );
		if ( ! is_array( $data ) || ! $this->is_preventas_route() ) {
			return is_array( $data ) ? $data : array();
		}

		$canonical = $this->route_canonical();
		$paged     = (int) get_query_var( 'paged' );
		$page_name = $paged > 1
			? sprintf( 'Manga y cómics a pedido – Página %d', $paged )
			: 'Manga y cómics a pedido';

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
	 * Cuando el usuario selecciona una faceta distinta a pedidos/reserva (por ejemplo, "Disponible"),
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
	 * Evita que solicitudes directas con `?akb_preventas_route=1` desvíen la resolución
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
		if ( $this->is_preventas_route() ) {
			return;
		}

		$destino = home_url( self::ROUTE_PATH . '/' );
		wp_safe_redirect( $destino, 301 );
		exit;
	}

	public function redirect_conflicting_facet(): void {
		if ( ! $this->is_preventas_route() ) {
			return;
		}

		$param = '_' . self::FACET_NAME;
		if ( ! isset( $_GET[ $param ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- isset() sobre un parámetro de faceta en un GET público que sólo decide si hay redirect que hacer; no hay estado que proteger con nonce.
			return;
		}

		$raw      = wp_unslash( $_GET[ $param ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- GET público que sólo redirige, sin formulario que proteger; y el valor se sanea con sanitize_key() sobre cada elemento en la línea siguiente, que el sniff no sigue a través de array_map().
		$selected = array_filter( array_map( 'sanitize_key', (array) $raw ) );
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
	 * Obtiene el `post_modified` más reciente de los productos en preventa/reserva
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

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$lookup} es $wpdb->prefix. 'wc_product_meta_lookup' (tabla propia de WooCommerce, sin entrada de usuario); el único valor viaja por el marcador %s.
		$date = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MAX(p.post_modified) FROM {$wpdb->posts} p
				 INNER JOIN {$lookup} l ON l.product_id = p.ID
				 WHERE p.post_type = 'product' AND p.post_status = 'publish' AND l.stock_status = %s",
				// SEGUNDA COSECHA DEL BUG DE 1.109.3/1.109.4: `l.stock_status` es la
				// columna de ESQUEMA de la lookup, así que se filtra por
				// `onbackorder`, no por el valor comercial `a_pedido` de la faceta.
				// Con el valor comercial la consulta devolvía siempre NULL: la línea
				// de frescura no se imprimía nunca y, como el fallo salía antes del
				// `set_transient()`, se repetía en CADA pageload del archive.
				StockStatus::ON_BACKORDER
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! is_string( $date ) || '' === $date ) {
			return '';
		}

		// post_modified ya está expresado en la zona del sitio. Su fecha se toma
		// sin otra conversión strtotime/wp_date que pudiera desplazar el día del
		// atributo datetime respecto al texto visible.

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
