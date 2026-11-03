<?php
/**
 * No products found — Akibara branded override.
 *
 * Override del template default `woocommerce/templates/loop/no-products-found.php`.
 * Reemplaza el banner amarillo "no se han encontrado productos" + nada más por:
 *   - Heading "NO ENCONTRAMOS LO QUE BUSCAS"
 *   - Subtitle con sugerencia
 *   - Chips de categorías más buscadas (Manga, Cómics, Preventas, Shonen, Seinen, Shojo)
 *   - Cross-sell con 4 productos populares
 *   - Hint final con link a /contacto/ para títulos no listados
 *
 * Aplica a:
 *   - Search results vacío (?s=...)
 *   - Filter combinations sin matches
 *   - Categoría/tag vacíos
 *
 * @package akibara-gp
 * @see WooCommerce/templates/loop/no-products-found.php (template original)
 * @version 7.8.0
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

use Akibara\Business\Services\NavigationLinks;

$akb_contact_url = class_exists( NavigationLinks::class ) ? NavigationLinks::contact_url() : home_url( '/contacto/' );

/**
 * Categorías sugeridas — slugs que existen en el menú principal.
 * Si alguna no existe en BD simplemente no se pinta su chip.
 */
$akb_suggested_cats = array(
	'manga'  => 'Manga',
	'comics' => 'Cómics',
	'shonen' => 'Shonen',
	'seinen' => 'Seinen',
	'shojo'  => 'Shojo',
	'manhwa' => 'Manhwa',
);

/*
 * Los seis términos se resuelven en UNA consulta y se indexan por slug.
 * Antes había un `get_term_by()` por chip dentro del bucle: seis consultas —doce
 * en frío— en la página que ya está diciendo que no encontró nada, o sea en el
 * peor momento para hacer esperar. El orden lo sigue mandando `$akb_suggested_cats`,
 * que es el del menú.
 */
$akb_terms    = get_terms(
	array(
		'taxonomy'   => 'product_cat',
		'slug'       => array_keys( $akb_suggested_cats ),
		'hide_empty' => false,
	)
);
$akb_by_slug  = array();
if ( is_array( $akb_terms ) ) {
	foreach ( $akb_terms as $akb_term ) {
		if ( $akb_term instanceof WP_Term ) {
			$akb_by_slug[ $akb_term->slug ] = $akb_term;
		}
	}
	unset( $akb_term );
}
?>

<section class="akb-empty akb-no-results" aria-labelledby="akb-noresults-title">
	<div class="akb-empty__hero">
		<h2 id="akb-noresults-title" class="akb-empty__title">
			No encontramos lo que buscas
		</h2>

		<p class="akb-empty__subtitle">
			Prueba con menos palabras o explora nuestras categorías más populares:
		</p>

		<?php // Semántica accesible nativa: contenedor sin role="list" explícito para asegurar que los lectores de pantalla anuncien cada enlace correctamente como "enlace". ?>
		<div class="akb-empty__chips">
			<?php
			foreach ( $akb_suggested_cats as $akb_slug => $akb_label ) :
				if ( ! isset( $akb_by_slug[ $akb_slug ] ) ) {
					continue;
				}
				$akb_link = get_term_link( $akb_by_slug[ $akb_slug ] );
				if ( is_wp_error( $akb_link ) ) {
					continue;
				}
				?>
				<a class="akb-empty__chip" href="<?php echo esc_url( $akb_link ); ?>">
					<?php echo esc_html( $akb_label ); ?>
				</a>
			<?php endforeach; ?>
		</div>
	</div>

	<?php
	// Cross-sell "más vendidos": parte compartida por los empty-states.
	get_template_part(
		'template-parts/empty/cross-sell',
		null,
		array(
			'heading_id'   => 'akb-noresults-popular-title',
			'heading_tag'  => 'h2', // h2: las tarjetas de debajo también son h2 en un archivo (revisión R12, 4).
			'heading_text' => akibara_gp_empty_popular_title(), // Editable en Akibara → Textos (inc/site-copy.php).
		)
	);
	?>

	<p class="akb-empty__hint">
		¿No encuentras un título específico?
		<a href="<?php echo esc_url( $akb_contact_url ); ?>">Escríbenos</a>
		y lo traemos por ti.
	</p>
</section>
