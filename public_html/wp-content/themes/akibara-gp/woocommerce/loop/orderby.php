<?php
/**
 * Show options for ordering
 *
 * OVERRIDE DE AKIBARA — copia literal de WooCommerce 9.7.0 más UNA línea.
 *
 * Existe por una sola razón, verificada en wc-template-functions.php:1680 y
 * :1726 (WooCommerce 11.0.0): `woocommerce_catalog_ordering()` decide si
 * conserva la opción `menu_order` ANTES de disparar el filtro
 * `woocommerce_catalog_orderby`, y la BORRA cuando el orden por defecto no es
 * `menu_order`. Como las landings de serie mueven ese defecto a `title`
 * (inc/series-order.php), WooCommerce se llevaba por delante "Novedades
 * primero" en las 106 páginas — o sea, arreglar el orden por defecto costaba
 * perder la única salida hacia el orden anterior. Este archivo es el primer
 * punto del render posterior a ese `unset()`, así que es donde se repone.
 *
 * Fuera de `pa_serie` la llamada es un no-op y esta plantilla se comporta
 * exactamente como la de WooCommerce.
 *
 * AL ACTUALIZAR WOOCOMMERCE: comparar contra
 * `plugins/woocommerce/templates/loop/orderby.php`. Si sube la @version, hay
 * que rehacer la copia y volver a insertar la línea de reposición.
 *
 * @see         https://woocommerce.com/document/template-structure/
 * @package     WooCommerce\Templates
 * @version     9.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Única desviación respecto del original de WooCommerce. Ver el docblock. La
// segunda línea es tan necesaria como la primera: WooCommerce descarta el
// `?orderby` del usuario cuando esa opción ya no está en la lista (:1735), así
// que reponer la opción sin re-marcarla dejaba el selector anunciando un orden
// distinto del que muestra la rejilla.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- copia literal de la plantilla de WooCommerce 9.7.0 (ver el docblock): $catalog_orderby_options, $orderby e $id_suffix son variables del contrato de wc_get_template() y del código upstream. Renombrarlas añadiría desviaciones que el docblock y tests/series-order.test.js prohíben, y $catalog_orderby_options está además sellado por el candado de fuente de ese test.
if ( function_exists( 'akibara_gp_series_orderby_restore_menu_order' ) ) {
	$catalog_orderby_options = akibara_gp_series_orderby_restore_menu_order( $catalog_orderby_options );
	$orderby                 = akibara_gp_series_orderby_selected( $orderby, $catalog_orderby_options );
}

$id_suffix = wp_unique_id();
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited

?>
<form class="woocommerce-ordering" method="get">
	<?php if ( $use_label ) : ?>
		<label for="woocommerce-orderby-<?php echo esc_attr( $id_suffix ); ?>"><?php echo esc_html__( 'Sort by', 'woocommerce' ); ?></label>
	<?php endif; ?>
	<select
		name="orderby"
		class="orderby"
		<?php if ( $use_label ) : ?>
			id="woocommerce-orderby-<?php echo esc_attr( $id_suffix ); ?>"
		<?php else : ?>
			<?php
			/*
			 * SEGUNDA desviación respecto del original: el `aria-label`.
			 *
			 * WooCommerce emite `esc_attr_e('Shop order','woocommerce')`, que en
			 * es_CL se traduce como "Pedido de la tienda" — y en Chile "pedido"
			 * es una orden de compra, no un criterio de ordenamiento. Un lector
			 * de pantalla anuncia el único control de la página como si fuera
			 * algo del historial de compras.
			 *
			 * No se puede arreglar por filtro: la cadena sale SIN contexto de
			 * traducción, así que `gettext_with_context` no dispara nunca, y un
			 * `gettext` a secas pisaría la cadena en toda la instalación. Por eso
			 * se resuelve acá, que es donde el override ya existe por el `unset`
			 * de `menu_order`.
			 */
			?>
			aria-label="Ordenar resultados"
		<?php endif; ?>
	>
		<?php foreach ( $catalog_orderby_options as $id => $name ) : // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- bucle upstream de WooCommerce 9.7.0; $id y $name son sus nombres, sellados por tests/series-order.test.js («El resto de la plantilla es la de WooCommerce, sin desviaciones extra»). ?>
			<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $orderby, $id ); ?>><?php echo esc_html( $name ); ?></option>
		<?php endforeach; ?>
	</select>
	<input type="hidden" name="paged" value="1" />
	<?php wc_query_string_form_fields( null, array( 'orderby', 'submit', 'paged', 'product-page' ) ); ?>
</form>
