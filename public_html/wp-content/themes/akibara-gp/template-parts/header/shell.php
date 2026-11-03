<?php
/**
 * Header shell — wrapper Persona-dark con logo + search + iconos.
 *
 * Atomic component cargado vía get_template_part('template-parts/header/shell').
 * Estructura: .header-main > .header-main__inner > [hamburger | logo | search | iconos]
 *
 * Search: form HTML que submitea a /tienda/?_buscar=X — la gramática de URL
 * la sirve el buscador propio (plugin akibara-search, FilterSet; heredada de
 * FacetWP a propósito para no romper URLs). El autocomplete lo provee el
 * plugin vía [data-akb-suggest] (search-suggest.js + REST /suggest),
 * enlazando directo a productos sin pasar por /tienda/.
 *
 * @package akibara-gp
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;
?>
<div class="header-main">
	<div class="header-main__inner">

		<button class="hamburger" id="menu-toggle" aria-label="Abrir menú" aria-expanded="false" aria-controls="mobile-drawer" type="button">
			<span></span><span></span><span></span>
		</button>

		<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="logo" aria-label="Akibara, inicio">
			<?php
				$akb_logo_id = get_theme_mod( 'custom_logo' );
				if ( $akb_logo_id ) {
					echo wp_get_attachment_image( $akb_logo_id, 'medium', false, array(
						// skip-lazy + data-no-lazy: la convención con que los lazyload por
						// JS de terceros (LiteSpeed, Cloudinary) dejan una imagen fuera de
						// su reescritura a `data-src`. Hoy ninguno reescribe: el de
						// LiteSpeed está fijado en apagado por
						// mu-plugins/akibara-litespeed-guardrails.php y Cloudinary no está
						// instalado. Se conservan porque el logo pinta en el header de toda
						// página y basta con que alguien quite la baranda para dejarlo en
						// un placeholder hasta que corra su JS. El lazy NATIVO no lo toca:
						// va antes del loop con `fetchpriority` explícito.
						'class'         => 'logo__img skip-lazy',
						'alt'           => 'Akibara',
						'fetchpriority' => 'auto',
						'sizes'         => '(max-width: 991px) 94px, 140px',
						'decoding'      => 'async',
						'data-no-lazy'  => '1',
					) );
				} else {
					echo '<img class="logo__img" src="' . esc_url( get_stylesheet_directory_uri() . '/assets/img/logo-akibara.webp' ) . '" alt="Akibara" width="201" height="80" fetchpriority="auto" decoding="async">';
				}
			?>
		</a>

		<?php
		// Pre-fill el input si veniste de /<archive>/?_buscar=X (FacetWP URL state).
		$akb_search_q = isset( $_GET['_buscar'] ) ? sanitize_text_field( wp_unslash( $_GET['_buscar'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- `?_buscar` es un parámetro de navegación pública que sólo repinta el valor del input; no procesa ningún formulario ni altera estado, así que no hay nonce que verificar. El valor se sanea en la misma línea y se escapa al pintarlo.
		// El buscador principal abarca siempre la totalidad del catálogo (`/tienda/`).
		// La acotación contextual por categoría, serie o autor opera mediante
		// los filtros de facetas del archivo correspondiente.
		$akb_search_action   = home_url( '/tienda/' );
		?>
		<form class="akibara-search" role="search" method="get" action="<?php echo esc_url( $akb_search_action ); ?>" aria-label="Buscar productos">
			<?php // Nombre = la pista visible sin «…» (QA-ESC-TECLADO-6, WCAG 2.5.3): ver inc/site-copy.php. ?>
			<label class="screen-reader-text" for="akb-search-input"><?php echo esc_html( akibara_gp_search_label() ); ?></label>
			<?php
			// Lupa = submit (Fase 1 §3): reemplaza al botón "Buscar" del
			// desktop. HTML puro — funciona igual con el flag de suggest OFF.
			// El drawer mobile conserva su botón propio (Fase 2).
			?>
			<button type="submit" class="akibara-search__lens" aria-label="Buscar">
				<svg class="akibara-search__icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
					<circle cx="11" cy="11" r="8"/>
					<path d="m21 21-4.35-4.35"/>
				</svg>
			</button>
			<?php
			// Atributos ARIA de combobox se inicializan progresivamente vía JS
			// (`search-suggest.js`) siguiendo el patrón W3C APG, preservando un
			// formulario GET estándar accesible si JavaScript no se ejecuta.
			//
			// El texto del placeholder se obtiene configurablemente mediante
			// `akibara_gp_search_placeholder()` (inc/site-copy.php).
			?>
			<input
				id="akb-search-input"
				class="akibara-search__input"
				type="search"
				name="_buscar"
				placeholder="<?php echo esc_attr( akibara_gp_search_placeholder() ); ?>"
				value="<?php echo esc_attr( $akb_search_q ); ?>"
					autocomplete="off"
					enterkeyhint="search"
					inputmode="search"
					autocapitalize="off"
					autocorrect="off"
					spellcheck="false"
					<?php if ( function_exists( 'akibara_search_suggest_active' ) && akibara_search_suggest_active() ) : ?>
					data-akb-suggest="true"
					<?php endif; ?>
				/>
				<button type="button" class="akibara-search__clear" aria-label="Limpiar búsqueda" hidden>
				<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
					<line x1="18" y1="6" x2="6" y2="18"/>
					<line x1="6" y1="6" x2="18" y2="18"/>
				</svg>
			</button>
			</form>

		<?php
		// URLs canónicas resueltas por el plugin akibara-business (dependencia
		// hard del theme — ver functions.php header). No usamos fallback con
		// slugs hardcoded: el plugin es requerido. Si falla, fail loud.
		$akb_account_url  = \Akibara\Business\Services\NavigationLinks::account_url();
		$akb_wishlist_url = \Akibara\Business\Services\NavigationLinks::wishlist_url();

		// Initial count del módulo Wishlist propio (Akibara\Business). Retorna
		// 0 si el user no está logueado. El badge se actualiza después en
		// client-side via evento custom `akb:wishlist:change` que dispara el
		// JS del módulo en cada toggle (main.js bind listener).
		$akb_wishlist_count = class_exists( '\\Akibara\\Business\\Modules\\Wishlist\\Storage' )
			? (int) \Akibara\Business\Modules\Wishlist\Storage::count()
			: 0;
		?>
		<div class="header-icons">
			<a href="<?php echo esc_url( $akb_account_url ); ?>" class="header-icon header-icon--account" aria-label="Mi cuenta">
				<?php echo akibara_gp_icon( 'user', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- akibara_gp_icon() devuelve un SVG de la lista blanca de nombres de inc/icons.php; el tamaño entra como %d en sprintf. No interpola nada del usuario. ?>
			</a>
			<a href="<?php echo esc_url( $akb_wishlist_url ); ?>" class="header-icon header-icon--wishlist" aria-label="Mis favoritos" data-akb-wishlist-count="<?php echo esc_attr( (string) $akb_wishlist_count ); ?>">
				<?php echo akibara_gp_icon( 'heart', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- akibara_gp_icon() devuelve un SVG de la lista blanca de nombres de inc/icons.php; el tamaño entra como %d en sprintf. No interpola nada del usuario. ?>
			</a>
			<?php
			// Cart icon — render via helper que comparte código con el WC
			// fragment filter (woocommerce_add_to_cart_fragments). Cada
			// add/remove AJAX reemplaza este `<a>` con el count nuevo.
			echo akibara_gp_render_cart_icon(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- akibara_gp_render_cart_icon() (inc/woocommerce.php) escapa por dentro: esc_url() en el enlace, esc_attr() en el contador y akibara_gp_icon() con su lista blanca.
			?>
		</div>

	</div>
</div>
