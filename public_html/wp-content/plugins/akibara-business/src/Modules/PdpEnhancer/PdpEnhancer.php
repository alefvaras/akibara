<?php
/**
 * PdpEnhancer — estética editorial/brutalista + UX improvements para la PDP.
 *
 * Mejoras implementadas (Baymard PDP best-practices 2025-2026):
 *   1. Star-rating inline en el summary (debajo del título, antes del precio).
 *   2. Grilla de specs completa: taxonomías + peso/dimensiones/país + stock.
 *   3. Tab "Información adicional" filtrada para no duplicar datos de la grilla.
 *   4. Layout stacked en mobile: todas las secciones visibles sin tabs ocultas.
 *   5. Estimación de envío más específica ("Despacha en 24-48h").
 *
 * @package Akibara\Business\Modules\PdpEnhancer
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\PdpEnhancer;

use Akibara\Business\Modules\Stock\Availability;
use Akibara\Business\Modules\Stock\StockStatus;
use Akibara\Business\Utilities\PluginAssetVersion;
use Akibara\Business\Utilities\Text;

defined( 'ABSPATH' ) || exit;

final class PdpEnhancer {

	/**
	 * Testigo por producto que versiona el panel de especificaciones cacheado.
	 */
	private const SPECS_GENERATION_META = '_akb_pdp_specs_generation';

	/**
	 * Meta key para identificar el tomo de continuación tras el anime.
	 */
	public const ANIME_CONTINUATION_META = '_akb_anime_continuation';

	/**
	 * Taxonomías que el panel muestra: sólo un cambio en una de ellas obliga a
	 * repintarlo. Enumerarlas evita repintar por cada `set_object_terms` del
	 * sitio, incluidos los internos de WooCommerce.
	 *
	 * @var list<string>
	 */
	private const SPECS_TAXONOMIES = array(
		'pa_autor',
		'product_brand',
		'pa_serie',
		'pa_formato',
		'pa_genero',
		'pa_demografia',
		'pa_idioma',
		'pa_pais',
		'product_cat',
		'product_visibility',
	);

	/** Taxonomías que ya mostramos en la grilla brutalista → filtrar de la tab adicional. */
	private const GRID_TAXONOMIES = array( 'pa_autor', 'product_brand', 'pa_serie', 'pa_formato', 'pa_genero' );

	public function register(): void {
		// WooCommerce no expone una opción de administración para nombrar el
		// enlace que abre la imagen principal. Usamos su filtro de markup para
		// mantener la plantilla core actualizable y mejorar solo ese control.
		add_filter( 'woocommerce_single_product_image_thumbnail_html', array( $this, 'add_main_gallery_link_accessible_name' ), 10, 2 );

		// Modificar tabs de WC.
		add_filter( 'woocommerce_product_tabs', array( $this, 'modify_product_tabs' ), 98 );

		// Inyectar star-rating propio en el summary (priority 7 = después del título en 5, antes del precio en 10).
		add_action( 'woocommerce_single_product_summary', array( $this, 'render_star_rating' ), 7 );
		add_action( 'woocommerce_single_product_summary', array( $this, 'render_anime_badge' ), 8 );
		add_action( 'woocommerce_single_product_summary', array( $this, 'render_edition_summary' ), 9 );

		if ( is_admin() ) {
			add_action( 'woocommerce_product_options_general_product_data', array( $this, 'render_anime_admin_field' ) );
			add_action( 'woocommerce_process_product_meta', array( $this, 'save_anime_admin_field' ) );
		}

		// El rating propio REEMPLAZA al nativo de WC: sin este remove, toda PDP con
		// reseñas muestra dos filas de estrellas (la nuestra +.woocommerce-product-rating).
		// Con 0 reseñas el nativo no imprime nada, por eso el duplicado pasó inadvertido.
		remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_rating', 10 );

		// Filtrar los atributos de la tab "Información adicional" para no duplicar los de la grilla.
		add_filter( 'woocommerce_display_product_attributes', array( $this, 'filter_additional_attributes' ), 10, 2 );

		// Remover el heading nativo de Descripción para evitar texto duplicado ("Descripción Descripción")
		add_filter( 'woocommerce_product_description_heading', '__return_false' );

		// JS para stacked tabs en mobile: inyecta data-section-label y force-show all panels.
		add_action( 'wp_footer', array( $this, 'render_stacked_tabs_js' ) );

		// Los estilos de ficha viven en un asset cacheable compartido por las PDP.

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ), 20 );

		// Invalidación del panel de especificaciones.
		add_action( 'woocommerce_update_product', array( $this, 'bump_specs_generation' ) );
		add_action( 'woocommerce_product_set_stock_status', array( $this, 'bump_specs_generation' ) );
		add_action( 'set_object_terms', array( $this, 'bump_specs_generation_on_terms' ), 10, 4 );
	}

	/**
	 * Encola el CSS de la ficha solo donde se usa.
	 *
	 * @since 1.98.0
	 *
	 * @return void
	 */
	public function enqueue_assets(): void {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}

		wp_enqueue_style(
			'akb-pdp-enhancer',
			AKIBARA_BUSINESS_URL . 'assets/css/pdp-enhancer.css',
			array(),
			PluginAssetVersion::for_path( 'assets/css/pdp-enhancer.css' )
		);
	}

	/**
	 * Detecta si el producto es manga o cómic según su categoría y ancestros.
	 *
	 * Sigue la regla canónica de ProductFaq para garantizar que nunca se confundan
	 * mangas con cómics ni se asignen iconos japoneses al cómic occidental.
	 *
	 * @param int $product_id ID del producto.
	 * @return 'manga'|'comic'|null
	 */
	public static function detect_publication_type( int $product_id ): ?string {
		if ( $product_id < 1 ) {
			return null;
		}

		$categories = get_the_terms( $product_id, 'product_cat' );
		if ( ! is_array( $categories ) ) {
			return null;
		}

		$slugs = array();
		foreach ( $categories as $category ) {
			if ( ! is_object( $category ) ) {
				continue;
			}
			if ( isset( $category->slug ) && is_string( $category->slug ) ) {
				$slugs[] = $category->slug;
			}
			$term_id = isset( $category->term_id ) ? (int) $category->term_id : 0;
			if ( $term_id < 1 ) {
				continue;
			}
			if ( function_exists( 'get_ancestors' ) ) {
				foreach ( get_ancestors( $term_id, 'product_cat', 'taxonomy' ) as $ancestor_id ) {
					$ancestor = get_term( (int) $ancestor_id, 'product_cat' );
					if ( is_object( $ancestor ) && ! is_wp_error( $ancestor ) && isset( $ancestor->slug ) && is_string( $ancestor->slug ) ) {
						$slugs[] = $ancestor->slug;
					}
				}
			}
		}

		$is_manga = in_array( 'manga', $slugs, true );
		$is_comic = in_array( 'comics', $slugs, true );

		if ( $is_manga === $is_comic ) {
			return null;
		}

		return $is_manga ? 'manga' : 'comic';
	}

	/**
	 * Datos de edición junto al precio; se omiten los campos no informados.
	 *
	 * La editorial y el tomo ya los muestran el título y su encabezado. Este
	 * resumen usa los mismos atributos/dimensiones que las especificaciones,
	 * agregando el sentido de lectura con estricta distinción Manga vs Cómic.
	 */
	public function render_edition_summary(): void {
		global $product;
		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		$details  = array();
		$pub_type = self::detect_publication_type( $product->get_id() );
		if ( 'manga' === $pub_type ) {
			$details['Lectura'] = 'Sentido oriental (derecha a izquierda)';
		} elseif ( 'comic' === $pub_type ) {
			$details['Lectura'] = 'Sentido occidental (izquierda a derecha)';
		}

		foreach ( array( 'pa_pais' => 'País de edición', 'pa_formato' => 'Formato' ) as $attribute => $label ) {
			$attr_obj = $product->get_attributes()[ $attribute ] ?? null;
			if ( $attr_obj instanceof \WC_Product_Attribute && ! $attr_obj->get_visible() ) {
				continue;
			}
			$value = trim( wp_strip_all_tags( $product->get_attribute( $attribute ) ) );
			if ( '' !== $value ) {
				$details[ $label ] = $value;
			}
		}
		if ( $product->has_dimensions() ) {
			$details['Dimensiones'] = wc_format_dimensions( $product->get_dimensions( false ) );
		}
		if ( array() === $details ) {
			return;
		}
		?>
		<dl class="akb-pdp-edition-summary" aria-label="Datos de esta edición">
			<?php foreach ( $details as $label => $value ) : ?>
				<?php
				$field_class = 'akb-edition-field';
				if ( 'Lectura' === $label ) {
					$field_class .= ' akb-edition-field--reading';
					if ( 'manga' === $pub_type ) {
						$field_class .= ' akb-reading--manga';
					} elseif ( 'comic' === $pub_type ) {
						$field_class .= ' akb-reading--comic';
					}
				}
				?>
				<div class="<?php echo esc_attr( $field_class ); ?>"><dt><?php echo esc_html( $label ); ?></dt><dd><?php echo esc_html( $value ); ?></dd></div>
			<?php endforeach; ?>
		</dl>
		<?php
	}

	/**
	 * Añade un nombre accesible explícito al enlace de ampliación de la imagen principal.
	 *
	 * WooCommerce envuelve la imagen en un enlace a tamaño completo. Aunque un `alt`
	 * no vacío puede nombrarlo de forma indirecta, ese nombre desaparece si el medio
	 * tiene `alt=""`. El `aria-label` mantiene el control comprensible sin agregar
	 * texto visible ni alterar las miniaturas de la galería.
	 *
	 * @param string     $html          Markup de una imagen de la galería.
	 * @param int|string $attachment_id ID de adjunto; get_image_id() puede devolver cadena vacía sin portada.
	 * @return string
	 */
	public function add_main_gallery_link_accessible_name( string $html, $attachment_id ): string {
		unset( $attachment_id );

		if ( ! class_exists( '\WP_HTML_Tag_Processor' ) ) {
			return $html;
		}

		$processor = new \WP_HTML_Tag_Processor( $html );
		if ( ! $processor->next_tag( 'a' ) ) {
			return $html;
		}

		$existing_label    = $processor->get_attribute( 'aria-label' );
		$existing_labelled = $processor->get_attribute( 'aria-labelledby' );
		if (
			( is_string( $existing_label ) && '' !== trim( $existing_label ) )
			|| ( is_string( $existing_labelled ) && '' !== trim( $existing_labelled ) )
		) {
			return $html;
		}

		if ( ! $processor->set_bookmark( 'akibara-gallery-link' ) ) {
			return $html;
		}

		// Solo la imagen principal recibe `wp-post-image`; las miniaturas no.
		if (
			! $processor->next_tag(
				array(
					'tag_name'  => 'img',
					'class_name' => 'wp-post-image',
				)
			)
			|| ! $processor->seek( 'akibara-gallery-link' )
		) {
			return $html;
		}

		global $product;

		$product_name = '';
		if ( $product instanceof \WC_Product && is_callable( array( $product, 'get_name' ) ) ) {
			$product_name = trim( wp_strip_all_tags( (string) $product->get_name() ) );
		}

		if ( '' !== $product_name ) {
			$label = sprintf( 'Ampliar imagen principal de %s', $product_name );
		} else {
			$label = 'Ampliar imagen principal del producto';
		}

		$processor->set_attribute( 'aria-label', $label );

		return $processor->get_updated_html();
	}

	/**
	 * Modificamos las pestañas de WooCommerce:
	 * - Interceptamos la tab de descripción para inyectar nuestra grilla de specs.
	 * - Mantenemos la tab de información adicional nativa de WC para peso/dimensiones/país.
	 */
	public function modify_product_tabs( array $tabs ): array {
		// Interceptar la tab de descripción para inyectar nuestra grilla de especificaciones principal.
		if ( isset( $tabs['description'] ) ) {
			$tabs['description']['callback'] = array( $this, 'render_editorial_description_tab' );
		}

		// Asegurar que la pestaña de "Información adicional" esté disponible para los datos técnicos.
		// WooCommerce la muestra automáticamente si el producto tiene peso, dimensiones o atributos no ocultos.
		if ( isset( $tabs['additional_information'] ) ) {
			// Renombrarla a "Especificaciones" si se desea, aunque el JS en el footer ya hace esto para Mobile.
			$tabs['additional_information']['title'] = 'Especificaciones';
		}

		return $tabs;
	}

	/**
	 * Filtra los atributos de la tab "Información adicional" para excluir los que
	 * ya se muestran en la grilla brutalista. Solo quedan: peso, dimensiones, país.
	 *
	 * @param array       $attributes Atributos a mostrar.
	 * @param \WC_Product $product    Producto.
	 * @return array
	 */
	public function filter_additional_attributes( array $attributes, $product ): array {
		foreach ( self::GRID_TAXONOMIES as $tax ) {
			$key = 'attribute_' . $tax;
			unset( $attributes[ $key ] );
		}

		return $attributes;
	}

	/**
	 * Renderiza star-rating + link a reviews en el summary del producto.
	 * Usa review_count en vez de rating_count para soportar reviews sin rating meta.
	 */
	public function render_star_rating(): void {
		global $product;

		if ( ! is_object( $product ) || ! is_a( $product, 'WC_Product' ) ) {
			return;
		}

		$review_count = (int) $product->get_review_count();
		$average      = (float) $product->get_average_rating();

		/**
		 * Por DEFECTO no se muestra nada cuando no hay reseñas: cinco estrellas
		 * vacías leen como "0/5" (prueba social negativa) en casi todo el catálogo.
		 * El filtro permite OPT-IN por producto para recuperar el placeholder + CTA.
		 * Se evalúa ACÁ, antes del <style>, para no emitir CSS muerto en las PDPs
		 * sin reseñas (la mayoría del catálogo).
		 *
		 * @since 1.49.9
		 * @param bool       $show    Mostrar el placeholder de rating vacío + CTA.
		 * @param WC_Product $product Producto actual de la PDP.
		 */
		$show_empty_rating = (bool) apply_filters( 'akibara_pdp_show_empty_rating', false, $product );
		if ( $review_count < 1 && ! $show_empty_rating ) {
			return;
		}

		if ( $review_count < 1 ) {
			// $show_empty_rating ya se resolvió arriba: si llegamos acá con
			// review_count < 1 es porque está en true. Renderizamos el CTA.
			?>
			<div class="akb-pdp-rating akb-pdp-rating--empty">
				<div class="akb-pdp-rating__stars" role="img" aria-label="Sin valoraciones aún">
					<span class="akb-pdp-rating__empty" aria-hidden="true">☆☆☆☆☆</span>
				</div>
				<a href="#reviews" class="akb-pdp-rating__link" rel="nofollow">
					ESCRIBIR REVIEW
				</a>
			</div>
			<?php
			return;
		}

		// Mostrar estrellas llenas según el promedio.
		$width = ( $average / 5 ) * 100;
		?>
		<div class="akb-pdp-rating">
			<?php // role="img" hace computable el aria-label (sobre div genérico los AT lo ignoran); los glifos van aria-hidden para que VoiceOver/NVDA no lean hasta 10 estrellas literales. Mismo patrón que wc_get_rating_html(). ?>
			<div class="akb-pdp-rating__stars" role="img" aria-label="<?php echo esc_attr( sprintf( 'Valorado con %.1f de 5', $average ) ); ?>">
				<span class="akb-pdp-rating__filled" aria-hidden="true" style="width:<?php echo esc_attr( $width ); ?>%">★★★★★</span>
				<span class="akb-pdp-rating__empty" aria-hidden="true">☆☆☆☆☆</span>
			</div>
			<a href="#reviews" class="akb-pdp-rating__link" rel="nofollow">
				<?php echo esc_html( $review_count ); ?> <?php echo $review_count === 1 ? 'valoración' : 'valoraciones'; ?>
			</a>
		</div>
		<?php
	}

	/**
	 * Callback para la pestaña Descripción.
	 * Renderiza primero los paneles brutalistas y luego llama al render default de WC.
	 */
	public function render_editorial_description_tab( string $key, array $tab ): void {
		$this->render_specs_grid();

		// Llamamos al render default de WC que carga el template de la descripción y ejecuta the_content.
		if ( function_exists( 'woocommerce_product_description_tab' ) ) {
			woocommerce_product_description_tab();
		}
	}

	/**
	 * Extrae y renderiza las taxonomías clave + peso/dimensiones/país del producto
	 * en un grid brutalista completo.
	 */
	private function render_specs_grid(): void {
		global $product;

		if ( ! is_object( $product ) || ! is_a( $product, 'WC_Product' ) ) {
			return;
		}

		$product_id = $product->get_id();
		// Incorporar al hash de caché las dos promesas editables: Preventa y A pedido.
		// Usar Text::one_line_option() para conservar saneamiento y fallback del panel.
		// Cambiar el copy debe invalidar la representación sin esperar al TTL.

		$promises   = md5(
			Text::one_line_option( 'akb_copy_promise_preventa_transit', 'En camino a Akibara' )
			. '|' . Text::one_line_option( 'akb_copy_promise_pedido_transit', 'Lo pedimos a la editorial por ti' )
		);
		// El testigo por producto es lo que faltaba. La clave sólo variaba con la
		// versión del plugin y el copy de las promesas, así que corregir el autor,
		// la serie, el formato o los géneros de una ficha no se veía en la web
		// durante hasta doce horas —y el sitio tiene object cache persistente, o
		// sea que ese TTL es real, no teórico—. Asignar términos ni siquiera
		// toca `post_modified`, de modo que ninguna marca de tiempo del propio
		// post servía como sustituto.
		$generation = (string) get_post_meta( $product_id, self::SPECS_GENERATION_META, true );
		// El estado COMERCIAL entra a la clave porque cambia sin disparar ningún
		// bump: una preventa cuya fecha de lanzamiento venció degrada a «A pedido»
		// en la lectura (Availability), y con un TTL de 12 h sobre object cache
		// persistente el panel seguiría diciendo «Preventa» medio día más.
		$cache_key  = 'akb_pdp_specs_' . $product_id . '_' . AKIBARA_BUSINESS_VERSION . '_v7_'
			. Availability::of( $product ) . '_'
			. substr( $promises, 0, 8 ) . '_' . substr( (string) preg_replace( '/[^0-9]/', '', $generation ), 0, 12 );

		// 1. Obtener de caché si existe
		$cached_html = wp_cache_get( $cache_key, 'akb_pdp' );
		if ( false !== $cached_html ) {
			echo $cached_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML ya compuesto y escapado pieza a pieza por este mismo método antes de cachearse.
			return;
		}

		ob_start();

		// 2. Extracción de Especificaciones (taxonomías)
		$taxonomies = array(
			'pa_autor'     => 'Autor',
			'product_brand' => 'Editorial',
			'pa_serie'     => 'Serie',
			'pa_formato'   => 'Formato',
			'pa_genero'    => 'Géneros',
		);

		$specs = array();

		foreach ( $taxonomies as $tax => $label ) {
			$terms = get_the_terms( $product_id, $tax );
			if ( $terms && ! is_wp_error( $terms ) ) {
				$term_names = array();
				foreach ( $terms as $term ) {
					$term_link = get_term_link( $term );
					if ( ! is_wp_error( $term_link ) ) {
						$term_names[] = sprintf( '<a href="%s">%s</a>', esc_url( $term_link ), esc_html( $term->name ) );
					} else {
						$term_names[] = esc_html( $term->name );
					}
				}
				$specs[ $label ] = implode( ', ', $term_names );
			}
		}

		// Los datos técnicos (peso, dimensiones, país) ahora residen en la pestaña "Información adicional" nativa
		// gestionada por WooCommerce, para evitar sobrecargar la grilla principal.

		if ( empty( $specs ) ) {
			// Sin especificaciones no se emite grilla. Los estilos generales de las
			// pestañas llegan por el asset encolado en toda PDP, sin depender de esta rama.

			ob_end_clean();
			return;
		}

		// 4. Panel del estado comercial, SÓLO para lo que se reserva.
		//
		// El tomo disponible llevaba aquí un panel verde «Disponibilidad ·
		// Despacha en 24–48 h»: con la insignia «Disponible» de arriba y la línea
		// de despacho junto al botón (BannerRenderer::render_despacho(), UC-2), la
		// ficha decía lo mismo tres veces con tres tratamientos (). Queda la
		// línea junto al botón, que es donde se decide. A pedido y preventa
		// conservan su panel: dicen algo que no está en otra parte («En camino al
		// distrito», «Lo pedimos a la editorial por ti»).
		$stock_status = $product->get_stock_status();

		?>
		<div class="akb-pdp-specs-grid">
			<?php foreach ( $specs as $label => $values ) : ?>
				<div class="akb-spec-panel">
					<div class="akb-spec-label"><?php echo esc_html( $label ); ?></div>
					<div class="akb-spec-value"><?php echo wp_kses_post( $values ); ?></div>
				</div>
			<?php endforeach; ?>

			<?php if ( StockStatus::ON_BACKORDER === $stock_status ) : ?>
				<?php
				// La palabra y el copy siguen al estado comercial (1.109.0): un
				// backlist a pedido no está «en camino» — se pide a la editorial
				// en el próximo corte. La clase CSS --preventa se conserva para
				// ambos (slug de presentación estable, como el variant `preorder`).
				$akb_es_preventa = Availability::is_preventa( $product );
				?>
				<div class="akb-spec-panel akb-badge-panel--preventa">
					<div class="akb-spec-label"><?php echo esc_html( Availability::label_of( $product ) ); ?></div>
					<div class="akb-spec-value"><?php
					echo esc_html(
						$akb_es_preventa
							? Text::one_line_option( 'akb_copy_promise_preventa_transit', 'En camino a Akibara' )
							: Text::one_line_option( 'akb_copy_promise_pedido_transit', 'Lo pedimos a la editorial por ti' )
					);
					?></div>
				</div>
			<?php endif; ?>
		</div>
		<?php

		// 5. Guardar en Caché
		$final_html = ob_get_clean();
		wp_cache_set( $cache_key, $final_html, 'akb_pdp', 12 * HOUR_IN_SECONDS );

		echo $final_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML ya compuesto y escapado pieza a pieza en el buffer de arriba.
	}

	/**
	 * JS inline en el footer para el stacked tabs en mobile.
	 * Inyecta data-section-label en cada panel para que el CSS::before muestre el título.
	 */
	public function render_stacked_tabs_js(): void {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}
		?>
		<script>
		(function(){
			var labels = {
				'description': 'Descripción',
				'additional_information': 'Especificaciones',
				'reviews': 'Valoraciones'
			};
			document.querySelectorAll('.woocommerce-Tabs-panel').forEach(function(panel) {
				var id = panel.id || '';
				var key = id.replace('tab-', '');
				if (labels[key]) {
					panel.setAttribute('data-section-label', labels[key]);
				}
			});
		})();
		</script>
		<?php
	}
	/**
	 * Marca el panel de un producto como caducado.
	 *
	 * @param int $product_id ID del producto.
	 * @return void
	 */
	public function bump_specs_generation( $product_id ): void {
		$product_id = (int) $product_id;
		if ( $product_id > 0 ) {
			update_post_meta( $product_id, self::SPECS_GENERATION_META, (string) time() );
		}
	}

	/**
	 * Lo mismo, cuando cambian los términos que el panel publica.
	 *
	 * Asignar términos no actualiza `post_modified` ni dispara
	 * `woocommerce_update_product`, así que sin este enganche corregir el autor o
	 * la serie de una ficha seguiría sin verse.
	 *
	 * @param int    $object_id Objeto al que se asignaron términos.
	 * @param mixed  $terms     Términos (sin usar).
	 * @param mixed  $tt_ids    IDs de term_taxonomy (sin usar).
	 * @param string $taxonomy  Taxonomía afectada.
	 * @return void
	 */
	public function bump_specs_generation_on_terms( $object_id, $terms, $tt_ids, $taxonomy ): void {
		unset( $terms, $tt_ids );

		if ( ! in_array( (string) $taxonomy, self::SPECS_TAXONOMIES, true ) ) {
			return;
		}
		if ( 'product' !== get_post_type( (int) $object_id ) ) {
			return;
		}

		$this->bump_specs_generation( $object_id );
	}

	/**
	 * Renderiza el distintivo "¿Vienes del anime?" si el producto tiene meta de continuación.
	 *
	 * @internal
	 */
	public function render_anime_badge(): void {
		global $product;
		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		$continuation = get_post_meta( $product->get_id(), self::ANIME_CONTINUATION_META, true );
		if ( ! is_string( $continuation ) || '' === trim( $continuation ) ) {
			return;
		}

		?>
		<div class="akb-anime-badge">
			<span class="akb-anime-badge__icon" aria-hidden="true">
				<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="5 3 19 12 5 21 5 3"></polygon></svg>
			</span>
			<span class="akb-anime-badge__label">¿Vienes del anime?</span>
			<span class="akb-anime-badge__text"><?php echo esc_html( trim( $continuation ) ); ?></span>
		</div>
		<?php
	}

	/**
	 * Campo en el panel general de edición del producto en WooCommerce.
	 *
	 * @internal
	 */
	public function render_anime_admin_field(): void {
		if ( ! function_exists( 'woocommerce_wp_text_input' ) ) {
			return;
		}

		woocommerce_wp_text_input(
			array(
				'id'          => self::ANIME_CONTINUATION_META,
				'label'       => 'Continuación del anime',
				'placeholder' => 'Ej: Continúa tras la T1 emitida por P.A.WORKS (Cap. 24+)',
				'desc_tip'    => true,
				'description' => 'Muestra el distintivo "¿Vienes del anime?" en la ficha de este tomo clave.',
			)
		);
	}

	/**
	 * Guarda el valor de continuación del anime desde la edición de producto.
	 *
	 * @internal
	 * @param int $post_id ID del producto.
	 */
	public function save_anime_admin_field( int $post_id ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce valida su propio nonce en save_product.
		if ( isset( $_POST[ self::ANIME_CONTINUATION_META ] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$value = sanitize_text_field( wp_unslash( (string) $_POST[ self::ANIME_CONTINUATION_META ] ) );
			if ( '' === $value ) {
				delete_post_meta( $post_id, self::ANIME_CONTINUATION_META );
			} else {
				update_post_meta( $post_id, self::ANIME_CONTINUATION_META, $value );
			}
		}
	}
}
