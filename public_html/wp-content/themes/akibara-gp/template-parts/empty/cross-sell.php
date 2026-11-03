<?php
/**
 * Cross-sell "más vendidos" — parte reutilizable de los empty-states.
 *
 * Extraído del bloque idéntico que estaba triplicado en:
 *   - 404.php
 *   - woocommerce/cart/cart-empty.php
 *   - woocommerce/loop/no-products-found.php
 *
 * Renderiza los 4 productos más vendidos (IDs cacheados por
 * akibara_get_popular_cross_sell_ids() en inc/performance.php) dentro de la
 * grilla estándar `.products.columns-4` que styliza loop.css.
 *
 * Args esperados (vía get_template_part( $slug, $name, $args ), WP 5.5+):
 *   @var string $akb_heading_id   ID único del heading (target del aria-labelledby).
 *   @var string $akb_heading_tag  Nivel del heading: 'h2' o 'h3' según jerarquía de la página.
 *   @var string $akb_heading_text Texto visible del heading.
 *
 * El query, los IDs, la grilla y las clases son IDÉNTICOS a las 3 copias
 * originales; sólo el heading (nivel/id/texto) varía por página, por eso se
 * parametriza. El loop lleva nombre (`AKIBARA_GP_CROSS_SELL_LOOP`) para que el
 * `sizes` de sus portadas sea el de su celda (inc/performance.php).
 *
 * @package akibara-gp
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- `$args` es el nombre que fija WordPress para el tercer parámetro de get_template_part(); renombrarlo dejaría la plantilla sin sus argumentos.
$args = isset( $args ) && is_array( $args ) ? $args : array();

$akb_heading_id   = isset( $args['heading_id'] ) ? (string) $args['heading_id'] : 'akb-empty-popular-title';
$akb_heading_tag  = isset( $args['heading_tag'] ) ? (string) $args['heading_tag'] : 'h2';
$akb_heading_text = isset( $args['heading_text'] ) ? (string) $args['heading_text'] : 'Más vendidos';

// Whitelist del nivel de heading: sólo h2/h3 (los únicos usados por los
// empty-states). Cualquier otro valor cae a h2 para no romper la jerarquía.
if ( ! in_array( $akb_heading_tag, array( 'h2', 'h3' ), true ) ) {
	$akb_heading_tag = 'h2';
}

// Cross-sell: 4 productos populares (cacheados para evitar filesort en postmeta).
$akb_popular_ids = function_exists( 'akibara_get_popular_cross_sell_ids' ) ? akibara_get_popular_cross_sell_ids() : array();
if ( empty( $akb_popular_ids ) ) {
	$akb_popular_ids = array( 0 ); // fallback to prevent showing all
}

$akb_cross_sell_args = array(
	'post_type'           => 'product',
	'post__in'            => $akb_popular_ids,
	'orderby'             => 'post__in',
	'posts_per_page'      => 4,
	'no_found_rows'       => true,
	'ignore_sticky_posts' => true,
);
$akb_cross_sell = new WP_Query( $akb_cross_sell_args );
if ( $akb_cross_sell->have_posts() ) :
	?>
		<section class="akb-empty__cross-sell woocommerce" aria-labelledby="<?php echo esc_attr( $akb_heading_id ); ?>">
		<<?php echo esc_html( $akb_heading_tag ); // lista blanca h2|h3 ?> id="<?php echo esc_attr( $akb_heading_id ); ?>" class="akb-empty__cross-sell-heading">
			<?php echo esc_html( $akb_heading_text ); ?>
		</<?php echo esc_html( $akb_heading_tag ); // lista blanca h2|h3 ?>>
		<ul class="akb-empty__cross-sell-grid products columns-4">
			<?php
			// Loop con nombre, como hace WooCommerce con `related` o `cross-sells`:
			// así `akibara_gp_catalog_image_sizes()` da a estas portadas el ancho
			// de su celda (152 px en un móvil) en vez del de 350 que declaraba
			// core, con el que bajaba el original. Se devuelve el nombre que había.
			$akb_loop_name = wc_get_loop_prop( 'name' );
			wc_set_loop_prop( 'name', AKIBARA_GP_CROSS_SELL_LOOP );
			while ( $akb_cross_sell->have_posts() ) :
				$akb_cross_sell->the_post();
				wc_get_template_part( 'content', 'product' );
			endwhile;
			wc_set_loop_prop( 'name', $akb_loop_name );
			wp_reset_postdata();
			?>
		</ul>
	</section>
	<?php
endif;
