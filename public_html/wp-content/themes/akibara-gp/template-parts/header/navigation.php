<?php
/**
 * Mainnav — desktop navigation con submenu dropdowns.
 *
 * Renderea wp_nav_menu del menu location 'akibara-primary' con walker
 * Akibara_GP_Mainnav_Walker (output BEM matching header.css).
 *
 * Owner edita ítems en wp-admin → Apariencia → Menús → "Akibara Principal".
 * Si no hay menú asignado, fallback_cb evita output (no rompe layout).
 *
 * @package akibara-gp
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

if ( ! has_nav_menu( 'akibara-primary' ) ) {
	return;
}
?>
<nav class="mainnav" aria-label="Navegación principal">
	<div class="mainnav__inner">
		<?php
		wp_nav_menu( array(
			'theme_location' => 'akibara-primary',
			'container'      => false,
			'items_wrap'     => '%3$s',
			'fallback_cb'    => '__return_empty_string',
			'depth'          => 3,
			'walker'         => new Akibara_GP_Mainnav_Walker(),
		) );
		?>
	</div>
</nav>
