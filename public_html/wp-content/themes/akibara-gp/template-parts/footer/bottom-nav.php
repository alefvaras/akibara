<?php
/**
 * Bottom-nav — sticky bottom bar (mobile ≤1024px).
 *
 * 4 ítems: Inicio, Catálogo, Buscar (button → abre mobile-drawer FiboSearch),
 * Carrito.
 *
 * Cart count: `data-akb-cart-count` en el tab Carrito (badge via ::after en
 * footer.css, mismo patrón del header-icon). El refresh AJAX lo sincroniza
 * main.js copiando el attr desde `.header-icon--cart` (que SÍ es WC fragment)
 * en added_to_cart / removed_from_cart / wc_fragments_refreshed.
 *
 * @package akibara-gp
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;
?>
<?php
// URLs canónicas vía plugin akibara-business (dependencia del theme).
$akb_home_url = \Akibara\Business\Services\NavigationLinks::home_url();
$akb_shop_url = \Akibara\Business\Services\NavigationLinks::shop_url();
$akb_cart_url = \Akibara\Business\Services\NavigationLinks::cart_url();

// Active state: highlight el tab del page actual en rojo. Tienda incluye
// shop, archives de category, single-product y search results — todo el
// "browsing" del catálogo. Buscar es un button (no link a URL), no marca
// estado.
$akb_is_home_active = is_front_page();
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- nombre sellado por el candado de fuente de tests/serie-landing-ux.test.js («el flag visual no puede decidir el aria-current»); prefijarlo desactivaría el candado. La plantilla la carga load_template(), así que la variable es local a esa función, no global.
$is_shop_active = ( function_exists( 'is_shop' ) && (
	is_shop() ||
	is_product_taxonomy() ||
	is_product() ||
	is_post_type_archive( 'product' ) ||
	// Restringido estrictamente a búsquedas de catálogo: búsquedas generales de
	// entradas del blog no deben activar visualmente el ítem de Catálogo.
	( is_search() && in_array( 'product', (array) get_query_var( 'post_type' ), true ) )
) );
$akb_is_cart_active = ( function_exists( 'is_cart' ) && is_cart() );

// Count server-side para el badge; en páginas cacheadas LSC parte con el valor
// cacheado y main.js lo corrige tras el refresh de WC cart-fragments.
$akb_cart_count = ( function_exists( 'WC' ) && WC()->cart ) ? (int) WC()->cart->get_cart_contents_count() : 0;

$akb_cls = static fn( bool $active ): string => $active
	? 'bottom-nav__item bottom-nav__item--active'
	: 'bottom-nav__item';

/*
 * El resaltado VISUAL y el `aria-current` no son el mismo dato, y hasta ahora
 * compartían la misma variable. Consecuencia medida: en `/serie/the-climber/`,
 * `/manga/`, `/marca/*`, `/autor/*` y en la ficha de producto la barra declaraba
 * `aria-current="page"` sobre el enlace a `/tienda/` — o sea, le decía al lector
 * de pantalla que el usuario YA está en esa URL cuando no lo está.
 *
 * `page` significa literalmente «esta es la página actual» y sólo lo es cuando
 * el destino del enlace coincide con la URL servida. Para «estás dentro de esta
 * sección» el valor correcto es `true`, que marca el ítem actual de un conjunto
 * sin afirmar que la URL calce. El resaltado rojo se queda como está: pintar
 * toda la rama de catálogo es una decisión de diseño correcta y distinta.
 */
$akb_aria_current = static function ( bool $active, bool $is_exact ): string {
	if ( ! $active ) {
		return '';
	}
	return $is_exact ? ' aria-current="page"' : ' aria-current="true"';
};

// `! is_paged()`: en `/tienda/page/3/` tampoco estás en la URL del enlace.
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- nombre sellado por el candado de fuente de tests/serie-landing-ux.test.js («page» en Catálogo sólo vale en /tienda/ sin paginar); prefijarlo desactivaría el candado.
$shop_is_exact = function_exists( 'is_shop' ) && is_shop() && ! is_paged();
?>
<nav class="bottom-nav" aria-label="Navegación móvil">

	<a href="<?php echo esc_url( $akb_home_url ); ?>" class="<?php echo esc_attr( $akb_cls( $akb_is_home_active ) ); ?>"<?php echo $akb_aria_current( $akb_is_home_active, $akb_is_home_active ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $akb_aria_current() sólo devuelve una de tres cadenas literales fijas: vacía, ' aria-current="page"' o ' aria-current="true"'. No interpola nada. ?>>
		<?php echo akibara_gp_icon( 'home', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- akibara_gp_icon() devuelve un SVG de la lista blanca de nombres de inc/icons.php; el tamaño entra como %d en sprintf. No interpola nada del usuario. ?>
		<span class="bottom-nav__label">Inicio</span>
	</a>

	<a href="<?php echo esc_url( $akb_shop_url ); ?>" class="<?php echo esc_attr( $akb_cls( $is_shop_active ) ); ?>"<?php echo $akb_aria_current( $is_shop_active, $shop_is_exact ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $akb_aria_current() sólo devuelve una de tres cadenas literales fijas: vacía, ' aria-current="page"' o ' aria-current="true"'. No interpola nada. ?>>
		<?php echo akibara_gp_icon( 'shop', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- akibara_gp_icon() devuelve un SVG de la lista blanca de nombres de inc/icons.php; el tamaño entra como %d en sprintf. No interpola nada del usuario. ?>
		<span class="bottom-nav__label">Catálogo</span>
	</a>

	<button class="bottom-nav__item" id="bottom-nav-search" type="button">
		<?php echo akibara_gp_icon( 'search', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- akibara_gp_icon() devuelve un SVG de la lista blanca de nombres de inc/icons.php; el tamaño entra como %d en sprintf. No interpola nada del usuario. ?>
		<span class="bottom-nav__label">Buscar</span>
	</button>

	<a href="<?php echo esc_url( $akb_cart_url ); ?>" class="<?php echo esc_attr( $akb_cls( $akb_is_cart_active ) ); ?> bottom-nav__item--cart akb-cart-trigger" data-akb-cart-count="<?php echo esc_attr( (string) $akb_cart_count ); ?>"<?php echo $akb_aria_current( $akb_is_cart_active, $akb_is_cart_active ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $akb_aria_current() sólo devuelve una de tres cadenas literales fijas: vacía, ' aria-current="page"' o ' aria-current="true"'. No interpola nada. ?><?php echo akibara_gp_cart_trigger_aria(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- akibara_gp_cart_trigger_aria() (inc/woocommerce.php) sólo devuelve una de dos cadenas literales fijas (o vacío); misma función que usa el icono del header para no duplicar la regla de cuándo el trigger abre el cajón. ?>>
		<?php echo akibara_gp_icon( 'cart', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- akibara_gp_icon() devuelve un SVG de la lista blanca de nombres de inc/icons.php; el tamaño entra como %d en sprintf. No interpola nada del usuario. ?>
		<span class="bottom-nav__label">Carrito</span>
	</a>

</nav>
