<?php
/**
 * Negociación de contenido: sirve Markdown en la MISMA URL cuando quien pide es
 * un agente. Implementa el contrato de acceptmarkdown.com, que son cuatro cosas
 * y no una:
 *
 *   1. Servir `text/markdown` cuando el cliente lo prefiere.
 *   2. `Vary: Accept` en TODAS las respuestas negociadas, no sólo en las de
 *      markdown. Sin esto un CDN puede cachear la variante HTML y devolvérsela
 *      al agente (o al revés), según cuál entró primero.
 *   3. `406` cuando el cliente rechaza todo lo que sabemos producir.
 *   4. Honrar los q-values. El parser vive aparte en AcceptParser.
 *
 * Por qué no el plugin de Roots
 * -----------------------------
 * `post-content-to-markdown` es la receta oficial y es buena, pero convierte
 * el HTML YA RENDERIZADO del post. En una tienda eso es la peor fuente posible:
 * el precio, el stock y la fecha de llegada no viven en `post_content`, viven en
 * el objeto WC_Product y en meta. Un markdown derivado del HTML de la ficha
 * llegaría sin precio o con el precio pegado a un `<del>` de oferta.
 *
 * Aquí el markdown se genera desde los DATOS para producto y serie, y sólo se
 * convierte HTML para páginas y entradas, que sí son prosa.
 *
 * Caché
 * -----
 * LiteSpeed cachea HTML por URL. Una respuesta markdown en la misma URL
 * envenenaría esa entrada, así que se marca no-cacheable por su propio hook
 * (`litespeed_control_set_nocache`) — sin tocar el plugin de terceros. El borde
 * (Cloudflare) es la segunda capa y NO se resuelve desde acá: ver
 * {@see habilitado()}.
 *
 * @package Akibara\Business
 */

declare(strict_types=1);

namespace Akibara\Business\Modules\AgentReadiness;

use Akibara\Business\Modules\Stock\Availability;
use Akibara\Business\Services\SeriesHubUrl;
use WC_Product;
use WP_Post;
use WP_Term;

final class MarkdownNegotiation {

	public const TIPO_MARKDOWN = 'text/markdown';
	public const TIPO_HTML     = 'text/html';

	/**
	 * Veredicto «el cliente no acepta nada que sepamos producir» (406).
	 *
	 * Es un valor de decisión, no un tipo MIME: viaja por el mismo canal que
	 * `TIPO_HTML`/`TIPO_MARKDOWN` para que {@see representacion_negociada()}
	 * pueda describir las TRES salidas de la negociación con un solo retorno.
	 */
	public const SIN_REPRESENTACION = '406';

	/**
	 * HTML primero: es la preferencia del servidor y la que gana los empates.
	 * Invertir este orden le serviría markdown a cualquier cliente con `*​/*`.
	 */
	private const OFRECIDOS = array( self::TIPO_HTML, self::TIPO_MARKDOWN );

	/**
	 * Redirige aliases en inglés a las páginas canónicas en español mediante
	 * 301, sin crear representaciones duplicadas ni rutas competidoras.
	 */
	private const ALIAS = array(
		'about'          => '/nosotros/',
		'about-us'       => '/nosotros/',
		'privacy'        => '/politica-de-privacidad/',
		'privacy-policy' => '/politica-de-privacidad/',
		'contact'        => '/contacto/',
		'shipping'       => '/envios/',
		'returns'        => '/devoluciones/',
		'terms'          => '/terminos-y-condiciones/',
	);

	public function register(): void {
		add_action( 'template_redirect', array( $this, 'redirigir_alias' ), 0 );
		add_action( 'template_redirect', array( $this, 'negociar' ), 1 );
	}

	/**
	 * @return void
	 */
	public function redirigir_alias(): void {
		if ( ! is_404() ) {
			return;
		}

		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sólo se usa el componente de ruta y se compara contra el mapa fijo de ALIAS; sanitize_text_field() borraría las secuencias %XX y rompería rutas codificadas.
		$ruta = trim( (string) wp_parse_url( $uri, PHP_URL_PATH ), '/' );

		if ( ! isset( self::ALIAS[ $ruta ] ) ) {
			return;
		}

		wp_safe_redirect( home_url( self::ALIAS[ $ruta ] ), 301 );
		exit;
	}

	/**
	 * @return void
	 */
	public function negociar(): void {
		$elegido = $this->representacion_negociada();

		if ( null === $elegido ) {
			return;
		}

		if ( self::SIN_REPRESENTACION === $elegido ) {
			$this->responder_406();
			return;
		}

		if ( self::TIPO_MARKDOWN !== $elegido ) {
			// Rama HTML: el Vary es OBLIGATORIO también aquí. Es el punto 2 del
			// contrato y el que más se olvida, porque la página «se ve bien».
			$this->cabeceras_vary();
			$this->anunciar_alternativa();
			return;
		}

		$this->responder_markdown();
	}

	/**
	 * Representación elegida para esta petición.
	 *
	 * Está separada de `negociar()` —y es pública— porque las tres ramas de esa
	 * función terminan en `header()` + `exit`, y ninguna de las dos se puede
	 * observar ni interceptar dentro de PHPUnit. Sin este seam, el contrato de
	 * negociación sólo se podía «probar» leyendo el texto del fuente, que es
	 * exactamente lo que no prueba nada.
	 *
	 * @internal Seam de pruebas del contrato de negociación.
	 *
	 * @return string|null `TIPO_HTML`, `TIPO_MARKDOWN`, `SIN_REPRESENTACION`, o
	 *                     `null` cuando esta petición no se negocia.
	 */
	public function representacion_negociada(): ?string {
		if ( ! $this->es_negociable() ) {
			return null;
		}

		$cabecera = isset( $_SERVER['HTTP_ACCEPT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT'] ) ) : '';
		$elegido  = AcceptParser::mejor( $cabecera, self::OFRECIDOS );

		return null === $elegido ? self::SIN_REPRESENTACION : $elegido;
	}

	/**
	 * Hereda el estado HTTP de la consulta para que la representación markdown
	 * conserve también los 404 y no anuncie rutas inexistentes como respuestas 200.
	 *
	 * @internal Seam de pruebas: `responder_markdown()` termina en `exit`.
	 */
	public function estado_de_la_variante(): int {
		return is_404() ? 404 : 200;
	}

	/**
	 * La negociación markdown está apagada por defecto. Sólo debe habilitarse
	 * cuando el borde hace bypass de caché para Accept: text/markdown. Vary: Accept
	 * y no-store por sí solos no vencen una regla de borde que fuerce caché.
	 * Antes de habilitarla, comprobar secuencialmente que la misma URL sirve
	 * markdown al agente y HTML al navegador, sin mezclar representaciones.
	 */
	private function habilitado(): bool {
		return 'yes' === (string) get_option( 'akb_markdown_negotiation_enabled', 'no' );
	}

	private function es_negociable(): bool {
		if ( ! $this->habilitado() ) {
			return false;
		}
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return false;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}
		if ( is_feed() || is_robots() || is_trackback() ) {
			return false;
		}
		if ( is_user_logged_in() ) {
			// La barra de admin y los precios por rol hacen que la salida no sea
			// la pública; mejor no negociar que servir algo que nadie más ve.
			return false;
		}

		// Los productos, entradas y páginas con contraseña conservan la respuesta
		// HTML de WordPress; no exponen contenido crudo mediante negociación markdown.
		// Las taxonomías no tienen contraseña de publicación.
		if ( is_singular() && post_password_required() ) {
			return false;
		}

		$metodo = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';

		return in_array( $metodo, array( 'GET', 'HEAD' ), true );
	}

	private function cabeceras_vary(): void {
		if ( headers_sent() ) {
			return;
		}

		header( 'Vary: ' . self::componer_vary( headers_list() ), true );
	}

	/**
	 * Compone el `Vary` AÑADIENDO `Accept` a lo que la respuesta ya declara.
	 *
	 * Simplificar esto a `header( 'Vary: Accept' )` pisaría el `Accept-Encoding`
	 * que ponen WordPress y el servidor, y con él la compresión en cualquier CDN
	 * intermedio. Los valores se deduplican sin distinguir mayúsculas porque el
	 * nombre de una cabecera no las distingue.
	 *
	 * @internal Función pura; expuesta para probar la composición sin `header()`.
	 *
	 * @param array<int,string> $cabeceras_actuales Salida de `headers_list()`.
	 */
	public static function componer_vary( array $cabeceras_actuales ): string {
		$previo = array();
		foreach ( $cabeceras_actuales as $cabecera ) {
			if ( is_string( $cabecera ) && 0 === stripos( $cabecera, 'vary:' ) ) {
				$previo = array_merge( $previo, array_map( 'trim', explode( ',', substr( $cabecera, 5 ) ) ) );
			}
		}
		$previo[] = 'Accept';

		$normalizado = array();
		foreach ( $previo as $valor ) {
			$valor = trim( $valor );
			if ( '' === $valor ) {
				continue;
			}
			// Gana la PRIMERA caja vista: el nombre de una cabecera no distingue
			// mayúsculas, pero un `Vary` cuya caja depende del orden de llegada
			// de los duplicados es un diff inútil en cualquier comparación.
			$normalizado[ strtolower( $valor ) ] ??= $valor;
		}

		return implode( ', ', $normalizado );
	}

	/**
	 * Anuncia la variante markdown en la respuesta HTML (RFC 8288), para los
	 * clientes que descubren en vez de negociar.
	 */
	private function anunciar_alternativa(): void {
		if ( headers_sent() ) {
			return;
		}
		$url = $this->url_actual();
		if ( '' === $url ) {
			return;
		}
		header( sprintf( 'Link: <%s>; rel="alternate"; type="text/markdown"', $url ), false );
	}

	private function responder_406(): void {
		if ( headers_sent() ) {
			return;
		}
		status_header( 406 );
		$this->cabeceras_vary();
		header( 'Content-Type: text/plain; charset=utf-8' );
		nocache_headers();
		$this->sin_cache_de_borde();
		echo "406 Not Acceptable\n\nEsta URL sirve text/html y text/markdown.\n";
		exit;
	}

	private function responder_markdown(): void {
		$cuerpo = $this->markdown();

		if ( ! headers_sent() ) {
			status_header( $this->estado_de_la_variante() );
			header( 'Content-Type: text/markdown; charset=utf-8' );
			$this->cabeceras_vary();
			$this->sin_cache_de_borde();
		}

		echo $cuerpo; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markdown plano, no HTML.
		exit;
	}

	/**
	 * La variante markdown no se cachea y declara Vary: Accept. El borde debe
	 * respetar esa separación mediante bypass explícito, ya que Cloudflare no
	 * varía sus objetos por Accept. El HTML mantiene su política de caché propia.
	 */
	private function sin_cache_de_borde(): void {
		if ( ! headers_sent() ) {
			header( 'Cache-Control: private, no-store, max-age=0', true );
			// Cloudflare y otros CDN respetan este par por encima del anterior.
			header( 'CDN-Cache-Control: no-store', true );
		}
		// Hook público del propio LiteSpeed: no se toca su código.
		do_action( 'litespeed_control_set_nocache', 'variante markdown en la misma URL' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Hook público oficial de LiteSpeed Cache (tercero): el nombre lo fija ese plugin.
	}

	private function url_actual(): string {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- El valor sale re-escapado por esc_url_raw() en la línea 348; sanitize_text_field() borraría las secuencias %XX de los slugs acentuados.
		if ( '' === $host || '' === $uri ) {
			return '';
		}

		return esc_url_raw( ( is_ssl() ? 'https://' : 'http://' ) . $host . $uri );
	}

	// ─── Renderizado ────────────────────────────────────────────────────

	/**
	 * Cuerpo markdown de la petición actual.
	 *
	 * @internal Seam de pruebas: `responder_markdown()` lo imprime y termina en
	 *           `exit`, así que es el único punto donde el cuerpo se puede leer.
	 */
	public function markdown(): string {
		if ( function_exists( 'is_product' ) && is_product() ) {
			return $this->markdown_producto();
		}
		if ( is_tax( 'pa_serie' ) ) {
			return $this->markdown_serie();
		}
		if ( is_singular() ) {
			return $this->markdown_entrada();
		}
		if ( is_404() ) {
			return $this->markdown_404();
		}

		return $this->markdown_generico();
	}

	private function pie(): string {
		return "\n---\n\n"
			. "Akibara — tienda de manga y cómics en Chile.\n"
			. 'Catálogo para agentes: ' . home_url( '/llms.txt' ) . "\n"
			. 'Mapa del sitio: ' . home_url( '/sitemap_index.xml' ) . "\n";
	}

	private function markdown_producto(): string {
		$producto = wc_get_product( get_the_ID() );
		if ( ! $producto instanceof WC_Product ) {
			return $this->markdown_generico();
		}

		$lineas   = array();
		$lineas[] = '# ' . $producto->get_name();
		$lineas[] = '';

		$editoriales = wp_get_post_terms( $producto->get_id(), 'product_brand', array( 'fields' => 'names' ) );
		if ( is_array( $editoriales ) && array() !== $editoriales ) {
			$lineas[] = '- **Editorial:** ' . implode( ', ', $editoriales );
		}

		$precio = wc_get_price_to_display( $producto );
		if ( is_numeric( $precio ) ) {
			$lineas[] = '- **Precio:** ' . html_entity_decode( wp_strip_all_tags( wc_price( (float) $precio ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}

		$lineas[] = '- **Disponibilidad:** ' . $this->disponibilidad( $producto );

		$sku = $producto->get_sku();
		if ( '' !== $sku ) {
			$lineas[] = '- **SKU:** ' . $sku;
		}

		$lineas[] = '- **URL:** ' . get_permalink( $producto->get_id() );
		$lineas[] = '';

		$descripcion = $producto->get_short_description();
		if ( '' === $descripcion ) {
			$descripcion = $producto->get_description();
		}
		if ( '' !== $descripcion ) {
			$lineas[] = $this->html_a_markdown( $descripcion );
		}

		return implode( "\n", $lineas ) . $this->pie();
	}

	private function disponibilidad( WC_Product $producto ): string {
		// Estado COMERCIAL, no stock crudo (1.109.0): «Preventa (aún no está en
		// bodega)» era literalmente falso para un backlist publicado hace años.
		switch ( Availability::of( $producto ) ) {
			case Availability::DISPONIBLE:
				return 'Disponible';
			case Availability::A_PEDIDO:
				return 'A pedido (se pide a la editorial; el plazo está en la ficha)';
			case Availability::PREVENTA:
				return 'Preventa (aún no sale a la venta)';
			default:
				return 'Agotado';
		}
	}

	private function markdown_serie(): string {
		$termino = get_queried_object();
		if ( ! $termino instanceof WP_Term ) {
			return $this->markdown_generico();
		}

		$lineas   = array();
		$lineas[] = '# ' . $termino->name;
		$lineas[] = '';
		$lineas[] = '- **URL:** ' . get_term_link( $termino );
		$lineas[] = '- **Tomos catalogados:** ' . (int) $termino->count;
		$lineas[] = '';

		if ( '' !== $termino->description ) {
			$lineas[] = $this->html_a_markdown( $termino->description );
			$lineas[] = '';
		}

		return implode( "\n", $lineas ) . $this->pie();
	}

	private function markdown_entrada(): string {
		$entrada = get_queried_object();
		if ( ! $entrada instanceof WP_Post ) {
			return $this->markdown_generico();
		}

		$contenido = apply_filters( 'the_content', $entrada->post_content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- `the_content` es el filtro de WordPress core; aplicarlo es justo el objetivo.

		return '# ' . get_the_title( $entrada ) . "\n\n"
			. $this->html_a_markdown( (string) $contenido )
			. $this->pie();
	}

	private function markdown_404(): string {
		// Un 404 que sólo dice «no existe» obliga al agente a adivinar. Éste le
		// entrega los tres puntos de entrada que sí puede recorrer.
		return "# 404 — No encontrado\n\n"
			. "Esta URL no existe en akibara.cl.\n\n"
			. "## Dónde seguir\n\n"
			// Resuelve las URLs con los helpers del sitio. El directorio puede cambiar
			// de slug y un clon no debe enlazar a la tienda de producción.
			. '- Catálogo por series: ' . SeriesHubUrl::url() . "\n"
			. '- Todos los productos: ' . home_url( '/tienda/' ) . "\n"
			. '- Buscador: ' . home_url( '/?s=TERMINO' ) . "\n"
			. $this->pie();
	}

	private function markdown_generico(): string {
		$titulo = wp_get_document_title();

		return '# ' . $titulo . "\n" . $this->pie();
	}

	/**
	 * Conversor mínimo para el subconjunto de HTML que emite WordPress.
	 *
	 * No pretende ser completo: es deliberadamente conservador y lo que no
	 * reconoce lo degrada a texto plano, que es exactamente lo que un agente
	 * necesita. Nada de dependencias — el plugin no tiene `vendor/` en runtime.
	 */
	private function html_a_markdown( string $html ): string {
		$html = preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#is', '', $html ) ?? $html;

		$reemplazos = array(
			// Una imagen vale por su alt (las portadas enlazadas de las tarjetas): antes que los enlaces.
			'#<img\b[^>]*\balt=(["\'])(.*?)\1[^>]*>#i' => '$2',
			'#<h1\b[^>]*>(.*?)</h1>#is'         => "\n\n# $1\n\n",
			'#<h2\b[^>]*>(.*?)</h2>#is'         => "\n\n## $1\n\n",
			'#<h3\b[^>]*>(.*?)</h3>#is'         => "\n\n### $1\n\n",
			'#<h4\b[^>]*>(.*?)</h4>#is'         => "\n\n#### $1\n\n",
			'#<h5\b[^>]*>(.*?)</h5>#is'         => "\n\n##### $1\n\n",
			'#<h6\b[^>]*>(.*?)</h6>#is'         => "\n\n###### $1\n\n",
			'#<(strong|b)\b[^>]*>(.*?)</\1>#is' => '**$2**',
			'#<(em|i)\b[^>]*>(.*?)</\1>#is'     => '_$2_',
			'#<li\b[^>]*>(.*?)</li>#is'         => "- $1\n",
			// Pares clave/valor (las tarjetas de edición): «- **Idioma**: …».
			'#<dt\b[^>]*>(.*?)</dt>\s*<dd\b[^>]*>(.*?)</dd>#is' => "- **$1**: $2\n",
			// Tablas: una fila por línea, celdas separadas por «|».
			'#<t[hd]\b[^>]*>(.*?)</t[hd]>#is'  => '| $1 ',
			'#</tr\s*>#i'                       => "|\n",
			'#<thead\b[^>]*>(.*?)</thead>#is'   => "$1\n",
			'#<blockquote\b[^>]*>(.*?)</blockquote>#is' => "\n> $1\n",
			'#<hr\b[^>]*/?>#i'                  => "\n\n---\n\n",
			'#<br\s*/?>#i'                      => "\n",
			'#</p\s*>#i'                        => "\n\n",
		);

		foreach ( $reemplazos as $patron => $reemplazo ) {
			$html = preg_replace( $patron, $reemplazo, $html ) ?? $html;
		}

		// Los enlaces se convierten aparte porque el destino va en un atributo.
		$html = preg_replace_callback(
			'#<a\b[^>]*href=(["\'])(.*?)\1[^>]*>(.*?)</a>#is',
			static function ( array $m ): string {
				$texto = trim( wp_strip_all_tags( $m[3] ) );
				$url   = trim( $m[2] );
				if ( '' === $texto ) {
					return $url;
				}

				return sprintf( '[%s](%s)', $texto, $url );
			},
			$html
		) ?? $html;

		$texto = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$texto = preg_replace( "#[ \t]+#", ' ', $texto ) ?? $texto;
		$texto = preg_replace( "#\n{3,}#", "\n\n", $texto ) ?? $texto;

		return trim( $texto );
	}
}
