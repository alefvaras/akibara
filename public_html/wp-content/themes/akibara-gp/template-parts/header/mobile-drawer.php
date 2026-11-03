<?php
/**
 * Mobile drawer — slide-from-left navigation (≤1024px).
 *
 * Toggleado por #menu-toggle (hamburger en shell.php) vía main.js.
 * Items owner-editable: renderiza wp_nav_menu del location `akibara-primary`
 * (Apariencia → Menús) con Akibara_GP_Mobile_Walker para las clases
 * .mobile-drawer__* — misma fuente que la navegación desktop.
 *
 * El contenedor principal actúa como diálogo modal (`role="dialog"`) y la
 * navegación vive contenida en su propio `<nav>` interno para preservar el
 * landmark de navegación accesible y cumplir las directrices ARIA.
 *
 * @package akibara-gp
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;
?>
<aside class="mobile-overlay" id="mobile-overlay" aria-hidden="true"></aside>

<div class="mobile-drawer" id="mobile-drawer" role="dialog" aria-modal="true" aria-labelledby="mobile-drawer-title" aria-hidden="true">
	<div class="mobile-drawer__head">
		<span class="mobile-drawer__title" id="mobile-drawer-title">Menú</span>
		<button class="mobile-drawer__close" id="mobile-close" aria-label="Cerrar menú" type="button">
			<?php echo akibara_gp_icon( 'close', 24 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- akibara_gp_icon() devuelve un SVG de la lista blanca de nombres de inc/icons.php; el tamaño entra como %d en sprintf. No interpola nada del usuario. ?>
		</button>
	</div>

	<?php
	$akb_mobile_search_q = isset( $_GET['_buscar'] ) ? sanitize_text_field( wp_unslash( $_GET['_buscar'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- `?_buscar` es un parámetro de navegación pública que sólo repinta el valor del input; no procesa ningún formulario ni altera estado, así que no hay nonce que verificar. El valor se sanea en la misma línea y se escapa al pintarlo.
	// El buscador busca SIEMPRE en toda la tienda — decisión del dueño, el
	// porqué medido está documentado en shell.php y las dos copias deben
	// decidir igual.
	$akb_mobile_action   = home_url( '/tienda/' );
	?>
	<form class="mobile-drawer__search" role="search" method="get" action="<?php echo esc_url( $akb_mobile_action ); ?>" aria-label="Buscar productos">
		<label class="screen-reader-text" for="akb-mobile-search-input">Buscar productos</label>
		<svg class="akibara-search__icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
			<circle cx="11" cy="11" r="8"/>
			<path d="m21 21-4.35-4.35"/>
		</svg>
		<?php
		// Autocomplete del buscador PROPIO: search-suggest.js setea TODO el
		// patrón APG en init() — sin role/aria estáticos acá (eran un combobox
		// mentiroso sin JS, D8). Flag OFF = input PLANO (rollback documentado,
		// Fase 0 §5).
		?>
		<input
			id="akb-mobile-search-input"
			class="akibara-search__input"
			type="search"
			name="_buscar"
			<?php // Placeholder compacto optimizado para anchos móviles estrechos (<= 340px)
			// donde el espacio horizontal convive con el icono y el botón de búsqueda. ?>
			placeholder="Buscar…"
			value="<?php echo esc_attr( $akb_mobile_search_q ); ?>"
			autocomplete="off"
			enterkeyhint="search"
			inputmode="search"
			autocapitalize="off"
			autocorrect="off"
			spellcheck="false"
			<?php if ( function_exists( 'akibara_search_suggest_active' ) && akibara_search_suggest_active() ) : ?>
			data-akb-suggest
			<?php endif; ?>
		/>
		<button type="button" class="akibara-search__clear" aria-label="Limpiar búsqueda" hidden>
			<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
				<line x1="18" y1="6" x2="6" y2="18"/>
				<line x1="6" y1="6" x2="18" y2="18"/>
			</svg>
		</button>
		<button type="submit" class="akibara-search__submit" aria-label="Buscar">
			Buscar
		</button>
		<?php
		// «Cancelar» solo existe visualmente en el search sheet (tab «Buscar» de la
		// barra inferior); en el menú lateral queda display:none. Cierra el drawer
		// y devuelve el foco al tab, como el patrón nativo iOS/Android.
		?>
		<button type="button" class="akb-search-cancel" id="akb-search-cancel">Cancelar</button>
	</form>

	<nav class="mobile-drawer__nav" aria-label="Menú principal">
		<?php
		if ( has_nav_menu( 'akibara-primary' ) ) {
			wp_nav_menu( array(
				'theme_location' => 'akibara-primary',
				'container'      => false,
				'items_wrap'     => '%3$s',
				'fallback_cb'    => '__return_empty_string',
				'depth'          => 3,
				'walker'         => new Akibara_GP_Mobile_Walker(),
			) );
		}
		?>

		<div class="mobile-drawer__foot">
			<?php
			// URLs canónicas vía plugin akibara-business (dependencia del theme).
			$akb_account_url = \Akibara\Business\Services\NavigationLinks::account_url();
			$akb_contact_url = \Akibara\Business\Services\NavigationLinks::contact_url();
			?>
			<a href="<?php echo esc_url( $akb_account_url ); ?>" class="mobile-drawer__link">Mi cuenta</a>
			<a href="<?php echo esc_url( $akb_contact_url ); ?>" class="mobile-drawer__link">Contacto</a>
		</div>
	</nav>
</div>
