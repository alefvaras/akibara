<?php
/**
 * Loop Enhancer module — inyecta badges en la card del shop loop vía hooks WC.
 *
 * Markup theme-agnostic con clases BEM `.akb-badge*`. El theme estiliza, este
 * módulo solo emite HTML. Sobrevive cambios de theme y de versiones de WC
 * (los hooks `woocommerce_before_shop_loop_item_title` y siblings son API
 * pública estable desde WC 2.x).
 *
 * Variants y reglas de coexistencia:
 *   1) out_of_stock   → "Agotado"  (gris, imagen grayscale via CSS) — DOMINA
 *   2) preorder       → "Preventa"   (texto blanco + borde rojo sobre chip negro; stock_status == onbackorder)
 *   3) sale           → "−NN%"       (red + white; % ahorro dinámico)
 *   4) available      → "Disponible" (solo single product; en loop se muestra
 *                        solo si es la única señal del card)
 *
 * Casos múltiples:
 *   - preventa + sale     → ['preorder', 'sale']    → chip preventa arriba + fill rojo de oferta debajo
 *   - disponible + sale   → loop: ['sale']; single: ['available', 'sale']
 *
 * Patrón visual loop: solo señales que cambian decisión como badges. El estado
 * normal "Disponible" se muestra como badge secundario solo cuando no hay una
 * señal más fuerte; descuentos y preventas se apilan top-left para dejar libre
 * el corazón/wishlist.
 *
 * Fuente de verdad del estado comercial: `Stock\Availability` (desde 1.109.0).
 * `onbackorder` ya NO significa «Preventa»: es «reservable», y Availability lo
 * separa en «A pedido» (default; ya publicado, se trae en el próximo corte) y
 * «Preventa» (lanzamiento pendiente). El variant CSS sigue siendo `preorder`
 * para ambos — los slugs no cambian, la PALABRA sí.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\LoopEnhancer;

use Akibara\Business\Modules\BackInStock\CaptureForm;
use Akibara\Business\Modules\Stock\Availability;
use Akibara\Business\Modules\Stock\StockStatus;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Inyecta badges en cards de productos en shop loop, archive, [products] shortcode + single.
 *
 * @since 1.1.0
 */
final class LoopEnhancer {

	/**
	 * Label estático del único variant NO derivado de un estado de stock: 'sale'.
	 *
	 * Su texto real lo computa `SaleDiscount::label()` ('−NN%'); 'Oferta' queda como
	 * fallback semántico. NO entra a la fuente StockStatus: el % de descuento es
	 * dinámico, no un estado del vocabulario Disponible/Preventa/Agotado.
	 *
	 * Las palabras de los 3 estados de stock (available/preorder/out_of_stock) YA
	 * NO viven acá: derivan de la fuente única `StockStatus::label()` vía
	 * `STOCK_VARIANT_STATUS` (un estado, una palabra, una sola fuente).
	 *
	 * @var array<string, string>
	 */
	private const LABELS = array(
		'sale' => 'Oferta',
	);

	/**
	 * Mapea cada variant de ESTADO del loop a su `stock_status` WC canónico. La
	 * palabra sale de la fuente única `Availability::label_of()`:
	 *   available    ↔ instock     → "Disponible"
	 *   preorder     ↔ onbackorder → "A pedido" o "Preventa" (lo separa Availability)
	 *   out_of_stock ↔ outofstock  → "Agotado"
	 *
	 * 'sale' no está: no es un estado de stock (ver LABELS). Los slugs de variant
	 * ('available'/'preorder'/'out_of_stock') y las clases `.akb-badge--{variant}`
	 * NO cambian — el theme (loop.css, `:has()`) y el allowlist UCSS dependen de ellos.
	 *
	 * @var array<string, string>
	 */
	private const STOCK_VARIANT_STATUS = array(
		'available'    => StockStatus::IN_STOCK,
		'preorder'     => StockStatus::ON_BACKORDER,
		'out_of_stock' => StockStatus::OUT_OF_STOCK,
	);

	private const CONTEXT_LOOP   = 'loop';
	private const CONTEXT_SINGLE = 'single';

	/**
	 * Conecta los hooks del módulo. Llamado por Plugin::register_modules().
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'woocommerce_before_shop_loop_item_title', array( $this, 'render_badge_loop' ), 9 );
		// Priority 4 → antes del title (priority 5 en WC core).
		add_action( 'woocommerce_single_product_summary',      array( $this, 'render_badge_single' ), 4 );
		add_filter( 'woocommerce_product_add_to_cart_text',         array( $this, 'cart_button_text' ), 10, 2 );
		add_filter( 'woocommerce_product_single_add_to_cart_text',  array( $this, 'cart_button_text' ), 10, 2 );
		// Los add-to-cart AJAX son acciones, no navegación: usar <button> evita
		// publicar una URL ?add-to-cart= por cada card sin cambiar el flujo AJAX.
		add_filter( 'woocommerce_loop_add_to_cart_link',            array( $this, 'convert_ajax_add_to_cart_link_to_button' ), 15, 2 );
		// Card agotada: el botón muerto ("Agotado", pointer-events none) se
		// reemplaza por un CTA outline "Avísame cuando vuelva" → el form de lista de espera del PDP (módulo BackInStock propio).
		// Prio 20: después de cualquier reescritura de texto a prio 10.
		add_filter( 'woocommerce_loop_add_to_cart_link',            array( $this, 'notify_link_loop' ), 20, 2 );
		// UCSS: el selector del CTA notify puede no estar en el HTML cuando el
		// UCSS de una URL se genera sin cards agotadas a la vista (prod suele
		// tener pocas) — al agotarse un producto después, el estilo ya fue
		// purgado y el CTA rendería como botón primario rojo. Mismo patrón que
		// Wishlist::allowlist_ucss_selectors.
		add_filter( 'litespeed_ucss_whitelist',                     array( $this, 'allowlist_ucss_selectors' ), 20 );
		// WCAG 2.5.3 label-in-name: el nombre accesible debe contener el label
		// visible. WC core describe "Lee más sobre «X»" donde el botón dice "Agotado".
		add_filter( 'woocommerce_product_add_to_cart_description',  array( $this, 'cart_button_description' ), 10, 2 );
		// Los «Productos relacionados» de la ficha los centraliza el módulo
		// Recommendations (RelatedProducts).

		// Hide stock-html nativo en single product — el badge ya comunica el estado.
		// En loop NO aplica: WC no llama get_stock_html() ahí. Filter scoped a single con is_singular check.
		add_filter( 'woocommerce_get_stock_html',              array( $this, 'hide_stock_html_single' ), 10, 2 );

		// Precio de un producto AGOTADO: un solo importe, el vigente (ver el
		// docblock de simplify_out_of_stock_price_html para el contrato de precio).
		// Prio 30 a propósito: los tres filtros de la casa sobre este hook son
		// disjuntos por stock_status, pero el ORDEN importa igual —
		//   20 TaxonomyDiscount\PriceFilters  (reescribe el html con el precio de campaña)
		//   25 OrderPreventa\PreventaUX       (limpia la lectura SR de la preventa)
		//   30 este                           (última palabra para el agotado)
		// Correr antes del 20 permitiría que una campaña reescribiera el markup con
		// <del>/<ins> en productos agotados con descuento por taxonomía.
		add_filter( 'woocommerce_get_price_html',              array( $this, 'simplify_out_of_stock_price_html' ), 30, 2 );

		// Rating en el loop con compuerta de volumen para evitar ratings artificiales.
		// WC core rinde estrellas con cualquier reseña (prio 5); con datos escasos
		// (5/5 de 1 opinión) eso sesga la percepción y rompe el ritmo de la grilla.
		// Se reemplaza por un render propio con umbral de volumen.
		remove_action( 'woocommerce_after_shop_loop_item_title', 'woocommerce_template_loop_rating', 5 );
		add_action(    'woocommerce_after_shop_loop_item_title', array( $this, 'render_rating_loop' ), 5 );

		// Marcador sutil "Disponible" junto al precio (prio 11 → después del precio,
		// que WC renderiza a prio 10). Solo instock; las demás cards reciben un
		// placeholder invisible para no romper la alineación de la grilla.
		add_action( 'woocommerce_after_shop_loop_item_title',  array( $this, 'render_availability_hint_loop' ), 11 );
	}

	/**
	 * Rating del loop con compuerta de volumen (hide-until-volume).
	 *
	 * No renderiza estrellas si el producto no supera el umbral de reseñas:
	 * con pocas opiniones los datos pueden inducir a error y romper el ritmo de la grilla.
	 * Por encima del umbral: estrellas nativas accesibles + conteo de opiniones.
	 *
	 * Umbral configurable con `akibara_loop_rating_min_reviews` (default 5);
	 * opt-in por producto bajo umbral con `akibara_loop_show_rating`.
	 */
	public function render_rating_loop(): void {
		global $product;
		if ( ! $product instanceof WC_Product || ! wc_review_ratings_enabled() ) {
			return;
		}

		$count = (int) $product->get_review_count();
		if ( $count < 1 ) {
			return; // sin reseñas → nada.
		}

		$threshold = (int) apply_filters( 'akibara_loop_rating_min_reviews', 5, $product );
		if ( $count < $threshold && ! apply_filters( 'akibara_loop_show_rating', false, $product ) ) {
			return; // datos escasos → ocultar hasta tener volumen real.
		}

		// Sobre umbral: estrellas (markup nativo que el theme estiliza) + conteo.
		echo wp_kses_post( wc_get_rating_html( (float) $product->get_average_rating(), $count ) );
		printf( '<span class="aki-rating-count">(%d)</span>', (int) $count );
	}

	/**
	 * Marcador sutil "Disponible" junto al precio, SOLO para productos instock.
	 *
	 * Señal liviana de stock real: no compite con los badges principales (OFERTA / PREVENTA / AGOTADO)
	 * que residen en la esquina superior izquierda. Se renderiza bajo el precio como microcopy verde.
	 * Utiliza StockStatus::label(IN_STOCK) como única fuente de verdad.
	 *
	 * Estricto instock: preventa (onbackorder) y agotado (outofstock) no lo llevan.
	 * Para preservar el ritmo vertical y la alineación de la grilla, las tarjetas que no están en
	 * stock emiten un placeholder vacío (aria-hidden).
	 *
	 * Sin ícono decorativo: instock es el estado modal del catálogo y la base de navegación.
	 * El texto directo cumple WCAG 1.4.1 sin sobrecargar visualmente el elemento.
	 *
	 * @since 1.84.0
	 *
	 * @return void
	 */
	public function render_availability_hint_loop(): void {
		global $product;
		if ( ! $product instanceof WC_Product ) {
			return;
		}

		if ( ! StockStatus::is_instock( $product ) ) {
			// Placeholder de igual altura → la fila no se desalinea. Sin texto ni
			// ícono; fuera del árbol de accesibilidad. Cuando detrás viene la píldora
			// de ETA (solo preventa) el CSS lo colapsa, porque ahí el hueco sumaba
			// 20,2px y desalineaba justo lo que venía a alinear.
			echo '<span class="akb-avail-hint akb-avail-hint--empty" aria-hidden="true"></span>';
			return;
		}

		printf(
			'<span class="akb-avail-hint">%s</span>',
			esc_html( StockStatus::label( StockStatus::IN_STOCK ) )
		);
	}

	/**
	 * Suprime el stock html nativo en single product page… salvo cuando queda poco.
	 *
	 * El badge principal ya comunica el estado comercial general. Sin embargo, cuando
	 * el inventario disponible es reducido, se informa la cantidad restante para dar
	 * transparencia sobre la disponibilidad real y evitar confusiones al operar el selector.
	 *
	 * @param string     $html    HTML nativo del stock.
	 * @param WC_Product $product Producto.
	 * @return string
	 */
	public function hide_stock_html_single( string $html, $product ): string {
		if ( ! is_singular( 'product' ) ) {
			return $html;
		}
		if ( ! $product instanceof WC_Product ) {
			return '';
		}

		try {
			if ( ! $product->managing_stock() || ! $product->is_in_stock() ) {
				return '';
			}
			$quedan = $product->get_stock_quantity();
			if ( ! is_numeric( $quedan ) ) {
				return '';
			}
			$quedan = (int) $quedan;

			/**
			 * Umbral por debajo del cual la ficha avisa de la escasez.
			 *
			 * @since 1.107.3
			 * @param int        $umbral  Unidades.
			 * @param WC_Product $product Producto.
			 */
			$umbral = (int) apply_filters( 'akibara_business_low_stock_threshold', 3, $product );

			/*
			 * El aviso de pocas unidades comienza en dos, no en una. La unidad única
			 * es habitual en coleccionismo y el selector ya explica su límite de
			 * cantidad. El intervalo 2..umbral evita presentar urgencia genérica en
			 * cada ficha con un solo ejemplar.
			 */
			if ( $quedan < 2 || $quedan > $umbral ) {
				return '';
			}

			$texto = sprintf( 'Quedan %d unidades', $quedan );

			return sprintf(
				'<p class="stock akb-stock-low" role="status">%s</p>',
				esc_html( $texto )
			);
		} catch ( \Throwable $throwable ) {
			return '';
		}
	}

	/**
	 * Producto AGOTADO: un solo importe, el vigente — sin bifurcación `<del>`/`<ins>`.
	 *
	 * Contrato semántico de precio para producto agotado:
	 * Un producto sin existencias (outofstock) no admite compra ni oferta activa;
	 * la semántica comercial de rebaja (precio regular tachado con precio de oferta)
	 * no aplica. Se estandariza a nivel global sirviendo un único importe vigente
	 * sin bifurcación <del>/<ins>, unificando la experiencia visual y la lectura de
	 * tecnologías de asistencia (a11y) tanto en loop, PDP y Quick View.
	 *
	 * Se emite la rama estándar de WooCommerce (`wc_price( wc_get_price_to_display() )`
	 * + `get_price_suffix()`) sin envoltorios adicionales para mantener la consistencia
	 * estructural de las tarjetas y evitar selectores divergentes.
	 *
	 * @since 1.100.0
	 *
	 * @param mixed      $html    HTML del precio construido por WooCommerce (y ya
	 *                            reescrito por TaxonomyDiscount si hay campaña).
	 * @param WC_Product $product Producto.
	 * @return string
	 */
	public function simplify_out_of_stock_price_html( $html, $product ): string {
		$html = (string) $html;

		// `StockStatus::is_out_of_stock()` y no `! is_in_stock()`: la preventa
		// (onbackorder) pasa por is_in_stock()=true hoy, pero el predicado
		// estricto es el contrato de la casa y no depende de esa sutileza de WC.
		if ( ! $product instanceof WC_Product || ! StockStatus::is_out_of_stock( $product ) ) {
			return $html;
		}

		// Sin oferta el html YA es el importe simple: devolverlo intacto en vez de
		// reconstruirlo evita reimplementar WC core para no cambiar nada.
		if ( ! $product->is_on_sale() ) {
			return $html;
		}

		// En un producto variable el price_html es un RANGO ("$X – $Y"): reducirlo
		// a un solo importe borraría el mínimo o el máximo. Hoy el catálogo es
		// 100% simple; el guard es para cuando no lo sea (mismo criterio que
		// PreventaUX).
		if ( ! $product->is_type( 'simple' ) ) {
			return $html;
		}

		// Sin precio, WC devuelve lo que filtre `woocommerce_empty_price_html`
		// (por defecto ''): pasar eso por wc_price() imprimiría un «$0» inventado
		// sobre un producto que no tiene precio publicado.
		$price = $product->get_price();
		if ( ! is_numeric( $price ) ) {
			return $html;
		}

		// Reconstruir desde cero es también lo que barre los dos
		// `screen-reader-text` de «precio original / precio actual»: no quedan por
		// construcción, sin depender de un preg_replace sobre texto traducido.
		return wc_price( wc_get_price_to_display( $product ) ) . $product->get_price_suffix();
	}

	/**
	 * Reescribe el texto del botón add-to-cart según el estado del producto.
	 *
	 * Hookeado tanto a `woocommerce_product_add_to_cart_text` (loop/grid) como a
	 * `woocommerce_product_single_add_to_cart_text` (single product):
	 *
	 *   - sin stock (outofstock)  → "Agotado"
	 *   - disponible (instock)    → $text original WC ("Añadir al carrito")
	 *
	 * El copy de preventa (onbackorder → "Reservar en Preventa") es dominio
	 * EXCLUSIVO de OrderPreventa\PreventaUX, que hookea estos mismos filters.
	 * Acá onbackorder pasa por is_in_stock()=true y devuelve $text sin tocar —
	 * antes ambos módulos competían a prio 10 y el ganador dependía del orden
	 * de instanciación en Plugin.php.
	 *
	 * @param string     $text
	 * @param WC_Product $product
	 * @return string
	 */
	public function cart_button_text( string $text, WC_Product $product ): string {
		return $product->is_in_stock() ? $text : 'Agotado';
	}

	/**
	 * Convierte el add-to-cart AJAX del loop en un botón sin URL rastreable.
	 *
	 * @internal
	 *
	 * @param string     $html    Markup generado por WooCommerce.
	 * @param WC_Product $product Producto de la card.
	 * @return string
	 *
	 * @since 1.88.0
	 */
	public function convert_ajax_add_to_cart_link_to_button( string $html, WC_Product $product ): string {
		if (
			! $product->supports( 'ajax_add_to_cart' )
			|| ! $product->is_purchasable()
			|| ! $product->is_in_stock()
			|| ! str_starts_with( ltrim( $html ), '<a ' )
		) {
			return $html;
		}

		$button = preg_replace(
			'/^(\s*)<a\s+href=(["\'])[^"\']*\2\s*/i',
			'$1<button type="button" ',
			$html,
			1,
			$opening_replacements
		);

		if ( ! is_string( $button ) || 1 !== $opening_replacements ) {
			return $html;
		}

		$button = preg_replace( '/<\/a>(\s*)$/i', '</button>$1', $button, 1, $closing_replacements );

		return is_string( $button ) && 1 === $closing_replacements ? $button : $html;
	}

	/**
	 * Card agotada: reemplaza el anchor completo del botón del loop por un CTA
	 * secundario "Avísame cuando vuelva" que lleva al form de waitlist del PDP.
	 *
	 * El botón muerto anterior ("Agotado" + pointer-events:none) era un callejón
	 * sin salida: el único camino a la lista de espera era descubrir el form
	 * scrolleando el PDP. Gateado a la waitlist propia activa (mismo criterio que el
	 * notify-link del builder): sin waitlist no se promete aviso y queda el
	 * botón muerto de siempre. El estado del stock ya lo comunica el badge
	 * "Agotado"; el botón pasa a ofrecer la acción disponible.
	 *
	 * @param string     $html    Anchor del botón que armó WC.
	 * @param WC_Product $product Producto de la card.
	 * @return string
	 */
	public function notify_link_loop( string $html, WC_Product $product ): string {
		if ( $product->is_in_stock() || ! $this->waitlist_active() ) {
			return $html;
		}
		// Blindaje: el mismo filter lo aplica el bloque ProductButton de WC
		// Blocks, donde $html es un <div class="wp-block-button"> con directivas
		// de interactividad — reemplazarlo rompería la card. Solo se reescribe
		// el anchor clásico del loop (hoy no hay bloques Product Collection en
		// el sitio; guard para cuando los haya).
		if ( ! str_starts_with( ltrim( $html ), '<a' ) ) {
			return $html;
		}
		// El ancla la resuelve su dueño (`CaptureForm`), que aplica el filtro
		// `akibara_bis_form_anchor` sobre el id real del form. Acá vivía el
		// literal '#akb-bis-form' escrito a mano.
		$anchor = CaptureForm::anchor();
		$url    = get_permalink( $product->get_id() ) . $anchor;
		return sprintf(
			'<a href="%s" class="button akb-notify-link" aria-label="%s">%s</a>',
			esc_url( $url ),
			esc_attr( sprintf( 'Avísame cuando vuelva — «%s»', $product->get_name() ) ),
			esc_html( 'Avísame cuando vuelva' )
		);
	}

	/**
	 * Protege los selectores del CTA notify (y del estado agotado que lo
	 * scopea) del tree-shaking del UCSS de LiteSpeed.
	 *
	 * @param array<int, string> $selectors Whitelist acumulada.
	 * @return array<int, string>
	 */
	public function allowlist_ucss_selectors( array $selectors ): array {
		$selectors[] = '.akb-notify-link';
		$selectors[] = '.akb-badge--out_of_stock';

		/*
		 * El slot de estado bajo el precio se incluye en la lista permitida para garantizar
		 * que la optimización UCSS de LiteSpeed preserve las clases dinámicas (.akb-avail-hint--empty
		 * y .akb-eta-loop), asegurando una alineación vertical perfecta en toda la grilla.
		 */
		$selectors[] = '.akb-avail-hint--empty';
		$selectors[] = '.akb-eta-loop';

		return array_values( array_unique( $selectors ) );
	}

	/**
	 * ¿Hay sistema de waitlist operativo? (módulo BackInStock propio).
	 *
	 * Espeja el criterio de SeriesBundles\BuilderWidget::waitlist_active(). El
	 * filter permite forzarlo en tests y apagar el CTA si la captura se apaga.
	 *
	 * @return bool
	 */
	private function waitlist_active(): bool {
		// Post-cutover el default es false: el gate vivo lo enciende el módulo
		// BackInStock vía este mismo filter cuando su captura está activa.
		$active = false;

		/**
		 * Filtra si el CTA "Avísame cuando vuelva" del loop está operativo.
		 *
		 * @since 1.52.1
		 *
		 * @param bool $active True si la captura de lista de espera propia está activa.
		 */
		return (bool) apply_filters( 'akibara_loop_waitlist_active', $active );
	}

	/**
	 * Alinea la descripción accesible del botón con su label visible (WCAG 2.5.3).
	 *
	 * Mismo reparto de dominios que `cart_button_text`: acá solo el caso agotado;
	 * el de preventa lo cubre OrderPreventa\PreventaUX en este mismo filter.
	 *
	 * @param string     $description Descripción de WC core ("Lee más sobre «X»").
	 * @param WC_Product $product     Producto.
	 * @return string
	 */
	public function cart_button_description( string $description, WC_Product $product ): string {
		if ( ! $product->is_in_stock() ) {
			return sprintf( 'Agotado — «%s»', $product->get_name() );
		}
		return $description;
	}

	/**
	 * Hook entry point para el shop loop.
	 *
	 * @return void
	 */
	public function render_badge_loop(): void {
		$this->render_badges_for_context( self::CONTEXT_LOOP );
	}

	/**
	 * Hook entry point para single product page.
	 *
	 * @return void
	 */
	public function render_badge_single(): void {
		$this->render_badges_for_context( self::CONTEXT_SINGLE );
	}

	/**
	 * Punto único de render. Resuelve variants y emite HTML.
	 *
	 * Para SINGLE envuelve los badges en `<div class="akb-badge-single" aria-label>`
	 * (provee el container accesible). Para LOOP los emite sueltos: el `li.product`
	 * ya provee el container y el CSS posiciona absolute.
	 *
	 * @param string $context Self::CONTEXT_LOOP o self::CONTEXT_SINGLE.
	 * @return void
	 */
	private function render_badges_for_context( string $context ): void {
		global $product;

		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$variants = $this->resolve_variants( $product );

		if ( self::CONTEXT_LOOP === $context ) {
			// En el loop nunca se muestra "Disponible": la portada limpia es la señal
			// de inventario disponible; el espacio superior queda reservado exclusivamente
			// para estados que impactan la decisión de compra (OFERTA / PREVENTA / AGOTADO).
			$variants = array_values(
				array_filter(
					$variants,
					static function( string $variant ): bool {
						return 'available' !== $variant;
					}
				)
			);
		}

		/**
		 * Permite a otros módulos suprimir/ajustar los badges de un producto en un
		 * contexto (ej. SeriesBundles quita "Disponible" en las cards de packs, donde
		 * es redundante porque los packs agotados ya se ocultan del listado).
		 *
		 * @param list<string> $variants Variants resueltos (out_of_stock, preorder, sale, available).
		 * @param WC_Product   $product  Producto del loop/single.
		 * @param string       $context  CONTEXT_LOOP o CONTEXT_SINGLE.
		 */
		$variants = array_values( (array) apply_filters( 'akibara_loop_badge_variants', $variants, $product, $context ) );

		if ( empty( $variants ) ) {
			return;
		}

		$is_single = self::CONTEXT_SINGLE === $context;
		if ( $is_single ) {
			// role="group" hace computable el aria-label: sobre un div genérico
			// los lectores de pantalla lo ignoran (ARIA inerte).
			echo '<div class="akb-badge-single" role="group" aria-label="Estado del producto">';
		}

		foreach ( $variants as $variant ) {
			// Palabra del badge:
			//   - Estados (available/preorder/out_of_stock) → fuente única
			//     Availability::label_of() (un estado COMERCIAL, una palabra, una
			//     sola fuente). Para el variant `preorder` esto separa la palabra:
			//     «A pedido» (ya publicado) o «Preventa» (lanzamiento pendiente).
			//   - 'sale' → LABELS['Oferta'] como placeholder; se sobreescribe abajo
			//     con el '−NN%' dinámico de SaleDiscount::label().
			// Guarda defensiva: `akibara_loop_badge_variants` es un filtro público, así
			// que un tercero (o un typo futuro) puede inyectar un variant desconocido.
			// Sin el fallback a '' eso dispararía un warning de índice indefinido en
			// PHP 8 y un badge roto en el loop/single.
			$label = isset( self::STOCK_VARIANT_STATUS[ $variant ] )
				? Availability::label_of( $product )
				: ( self::LABELS[ $variant ] ?? '' );

			if ( 'sale' === $variant ) {
				// El umbral anti-trivial solo aplica cuando la oferta se APILA sobre
				// "Preventa": ahí un "−2%" lee como contradicción (una preventa no es
				// una oferta). Una oferta standalone —liquidación real— muestra
				// cualquier rebaja ≥1% (no la silenciamos en todo el catálogo).
				$stacked_on_preorder = in_array( 'preorder', $variants, true );
				$discount            = SaleDiscount::label( $product, $stacked_on_preorder );
				if ( null === $discount ) {
					continue;
				}
				$label = $discount;
			}

			/**
			 * Permite override del texto de un badge por variant (ej. i18n o copy de
			 * campaña). Devolver '' suprime el badge de ese variant.
			 *
			 * @param string     $label   Texto resuelto del badge.
			 * @param string     $variant Variant (out_of_stock, preorder, sale, available, …).
			 * @param WC_Product $product Producto del loop/single.
			 * @param string     $context CONTEXT_LOOP o CONTEXT_SINGLE.
			 */
			$label = (string) apply_filters( 'akibara_loop_badge_label', $label, $variant, $product, $context );

			if ( '' === $label ) {
				continue;
			}

			printf(
				'<span class="akb-badge akb-badge--%1$s" data-akb-badge="%1$s">%2$s</span>',
				esc_attr( $variant ),
				esc_html( $label )
			);
		}

		if ( $is_single ) {
			echo '</div>';
		}
	}

	/**
	 * Resuelve los variants aplicables. Devuelve array porque sale puede coexistir
	 * con preorder o con available; out_of_stock siempre domina y suprime los demás.
	 *
	 * Orden del array = orden de render = orden de stacking visual.
	 * En loop el primer elemento queda top-left y el segundo se apila debajo
	 * (regla genérica `.akb-badge + .akb-badge` en loop.css).
	 *
	 * Casos:
	 *   - sin stock           → ['out_of_stock']
	 *   - preorder + sale     → ['preorder', 'sale']
	 *   - solo preorder       → ['preorder']
	 *   - available + sale    → ['available', 'sale'] (loop suprime available)
	 *   - solo available      → ['available'] (loop no renderiza badge)
	 *
	 * @param WC_Product $product Producto a evaluar.
	 * @return string[] Lista de variant slugs (puede ser 1 o 2 elementos).
	 */
	private function resolve_variants( WC_Product $product ): array {
		if ( ! $product->is_in_stock() ) {
			return array( 'out_of_stock' );
		}

		$is_preorder = $this->is_preorder( $product );
		$is_on_sale  = $product->is_on_sale();

		if ( $is_preorder && $is_on_sale ) {
			return array( 'preorder', 'sale' );
		}

		if ( $is_preorder ) {
			return array( 'preorder' );
		}

		// Para productos con existencias sin preventa, el estado base es "available" (Disponible),
		// asegurando consistencia visual sin alternancias artificiales.
		$base = 'available';

		if ( $is_on_sale ) {
			return array( $base, 'sale' );
		}

		return array( $base );
	}

	/**
	 * Detecta si el producto es RESERVABLE (se compra hoy, se trae después).
	 *
	 * `onbackorder` agrupa «A pedido» y «Preventa» (los separa Availability); el
	 * variant visual `preorder` cubre ambos y la palabra la pone label_of().
	 * WC considera onbackorder como `is_in_stock() = true` y `is_purchasable() = true`,
	 * por lo que el cliente sí puede agregar al carrito y completar la compra.
	 *
	 * No se usa la categoría WC `preventas`: esa taxonomía es semántica/SEO (alimenta
	 * landing y shortcode `[akb_preventas_destacadas]`) pero NO se mantiene en sync
	 * automáticamente con stock_status, por lo que es fuente de verdad poco fiable
	 * (ej. producto cambia a `instock` pero la categoría queda colgada → badge erróneo).
	 *
	 * @param WC_Product $product Producto a evaluar.
	 * @return bool
	 */
	private function is_preorder( WC_Product $product ): bool {
		return StockStatus::is_reservable( $product );
	}
}
