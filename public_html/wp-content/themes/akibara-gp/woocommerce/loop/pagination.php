<?php
/**
 * Pagination — pager nativo crawleable que preserva los params de faceta `_*`.
 *
 * El buscador propio (plugin akibara-search) filtra server-side; su pjax
 * (akb-search.js) intercepta estos `<a>` para el swap sin recarga, y sin JS
 * navegan igual. Emitimos links `<a href>` reales (no un pager AJAX) para que
 * `/tienda/page/2/?_categoria=shonen` conserve el filtro y sea crawleable +
 * cacheable.
 *
 * En archives sin filtros de catálogo (`pa_serie`, `pa_autor`) es la
 * pagination nativa intacta — relevante para el crawl de `/page/N/`.
 *
 * @package akibara-gp
 * @version 9.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Con el filtrado propio activo preservamos los params de faceta `_*` en los
// links de página (menos `_paged`) para que /tienda/page/2/?_categoria=shonen
// conserve el filtro.
$akb_active = function_exists( 'akibara_search_facets_active' ) && akibara_search_facets_active();

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- $total, $current, $base y $format son las variables del contrato de wc_get_template( 'loop/pagination.php', ... ): las inyecta WooCommerce. Prefijarlas dejaría la plantilla sin los valores que recibe.
$total   = isset( $total ) ? $total : wc_get_loop_prop( 'total_pages' );
$current = isset( $current ) ? $current : wc_get_loop_prop( 'current_page' );
$base    = isset( $base ) ? $base : esc_url_raw( str_replace( 999999999, '%#%', remove_query_arg( 'add-to-cart', get_pagenum_link( 999999999, false ) ) ) );
$format  = isset( $format ) ? $format : '';
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

if ( $total <= 1 ) {
	return;
}

// Preservar los params `_*` de faceta (menos `_paged`) en los links de página.
$akb_add_args = array();
if ( $akb_active ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- barrido de sólo lectura de los parámetros de faceta `_*` para reconstruir los enlaces de página: no procesa ningún formulario ni altera estado. La clave se sanea con sanitize_key() y el valor con sanitize_text_field() unas líneas más abajo.
	foreach ( (array) wp_unslash( $_GET ) as $akb_k => $akb_v ) {
		// La CLAVE también es input del request: sanitize_key() la restringe a
		// [a-z0-9_-] antes de usarla como nombre de query arg en los links.
		$akb_key = is_string( $akb_k ) ? sanitize_key( $akb_k ) : '';
		if ( '' !== $akb_key && $akb_key === $akb_k && '_' === substr( $akb_key, 0, 1 ) && '_paged' !== $akb_key ) {
			$akb_add_args[ $akb_key ] = is_array( $akb_v ) ? array_map( 'sanitize_text_field', $akb_v ) : sanitize_text_field( (string) $akb_v );
		}
	}
}
?>
<nav class="woocommerce-pagination" aria-label="<?php esc_attr_e( 'Product Pagination', 'woocommerce' ); ?>">
	<?php
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- paginate_links() devuelve el marcado ya escapado que arma el core de WordPress; la plantilla original de WooCommerce lo marca igual («WPCS: XSS ok»).
	echo paginate_links(
		apply_filters(
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- filtro público de WooCommerce: el nombre es el contrato con el que se enganchan terceros, no un hook propio.
			'woocommerce_pagination_args',
			array(
				'base'      => $base,
				'format'    => $format,
				'add_args'  => empty( $akb_add_args ) ? false : $akb_add_args,
				'current'   => max( 1, $current ),
				'total'     => $total,
				'prev_text' => is_rtl() ? '&rarr;' : '&larr;',
				'next_text' => is_rtl() ? '&larr;' : '&rarr;',
				'type'      => 'list',
				'end_size'  => 3,
				'mid_size'  => 3,
			)
		)
	);
	?>
</nav>
