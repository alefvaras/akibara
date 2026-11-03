<?php
/**
 * Footer main — brand block + 3 columnas owner-managed + bottom bar.
 *
 * Las 3 columnas (Catálogo / Explorar / Ayuda) y los atajos mobile se editan
 * desde wp-admin → Apariencia → Menús, asignados a las locations:
 *
 *   - akibara-footer-catalog
 *   - akibara-footer-explore
 *   - akibara-footer-help
 *   - akibara-footer-mobile
 *
 * Si una location no tiene menú asignado, su columna no se renderiza
 * (`has_nav_menu()` check). Walker dedicado: Akibara_GP_Footer_Walker.
 *
 * Logo via wp_get_attachment_image (template tag). URLs de cabecera (logo /
 * social fijos) no requieren NavigationLinks porque son URLs externas o
 * literales del home.
 *
 * @package akibara-gp
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

$akb_home_url = \Akibara\Business\Services\NavigationLinks::home_url();

/**
 * Renderiza una columna del footer si la location tiene menú asignado.
 * Si no hay menú, retorna sin emitir markup.
 */
$akb_render_footer_column = static function ( string $location, string $title, string $panel_id ): void {
	if ( ! has_nav_menu( $location ) ) {
		return;
	}
	?>
	<div class="footer-column">
		<?php
		/*
		 * Mejora progresiva de accesibilidad: `aria-expanded="true"` por defecto en
		 * HTML inicial, pues sin JavaScript la columna permanece visible y expandida.
		 * `main.js` actualiza a "false" únicamente tras inicializar el comportamiento
		 * colapsable del acordeón en dispositivos móviles (`syncFooterAccordionMode()`).
		 */
		?>
		<button type="button" class="footer-column__title footer-column__toggle" aria-expanded="true" aria-controls="<?php echo esc_attr( $panel_id ); ?>"><?php echo esc_html( $title ); ?></button>
		<?php
		wp_nav_menu( array(
			'theme_location' => $location,
			'container'      => false,
			'menu_class'     => 'footer-column__list',
			'menu_id'        => $panel_id,
			'depth'          => 1,
			'walker'         => new Akibara_GP_Footer_Walker(),
			'fallback_cb'    => '__return_empty_string',
		) );
		?>
	</div>
	<?php
};
?>
<footer class="site-footer" role="contentinfo">

	<div class="footer-main">

		<div class="footer-brand">
			<a href="<?php echo esc_url( $akb_home_url ); ?>" class="footer-brand__logo" aria-label="Akibara, inicio">
				<?php
				$akb_footer_logo_id = get_theme_mod( 'custom_logo' );
				if ( $akb_footer_logo_id ) {
					// `akibara-logo-footer` (560w) es el escalón que evita bajarse el de 768w
					// en pantallas de alta densidad; ver inc/woocommerce.php.
					echo wp_get_attachment_image( $akb_footer_logo_id, 'akibara-logo-footer', false, array(
						'alt'      => 'Akibara',
						'loading'  => 'lazy',
						'decoding' => 'async',
					) );
				} else {
					echo '<img src="' . esc_url( get_stylesheet_directory_uri() . '/assets/img/logo-akibara.webp' ) . '" alt="Akibara" width="201" height="80" loading="lazy" decoding="async">';
				}
				?>
				</a>
				<p class="footer-brand__desc">
					<strong>Tu rincón de Akihabara en Chile.</strong> Librería online de manga y cómics, disponibles y a pedido. Envíos a todo Chile.
				</p>
			<?php
			// Información pública coherente con una operación 100% online. San Miguel
			// identifica la comuna de retiro coordinado, no un local abierto ni una
			// dirección a la que se pueda acudir sin confirmación previa.
			?>
			<div class="footer-nap" role="group" aria-label="Tienda online, retiro y soporte">
				<span class="footer-nap__item">
					<?php echo akibara_gp_icon( 'cart', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- akibara_gp_icon() devuelve un SVG de la lista blanca de nombres de inc/icons.php; el tamaño entra como %d en sprintf. No interpola nada del usuario. ?>
					<span>Tienda 100% online · Sin local abierto al público</span>
				</span>
				<span class="footer-nap__item">
					<?php echo akibara_gp_icon( 'package', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- akibara_gp_icon() devuelve un SVG de la lista blanca de nombres de inc/icons.php; el tamaño entra como %d en sprintf. No interpola nada del usuario. ?>
					<span>Retiro de pedidos con coordinación previa en San Miguel</span>
				</span>
				<?php
				// Horario y teléfono se editan en Akibara → Textos → «Pie del
				// sitio». Vacíos NO se pintan: un reloj sin horario o un enlace
				// `tel:` sin número prometen atención que nadie puede dar.
				$akibara_gp_support_hours = akibara_gp_footer_support_hours();
				$akibara_gp_phone         = akibara_gp_footer_phone();
				$akibara_gp_phone_digits  = akibara_gp_footer_phone_digits();
				?>
				<?php if ( '' !== $akibara_gp_support_hours ) : ?>
					<span class="footer-nap__item">
						<?php echo akibara_gp_icon( 'clock', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- akibara_gp_icon() devuelve un SVG de la lista blanca de nombres de inc/icons.php; el tamaño entra como %d en sprintf. No interpola nada del usuario. ?>
						<span><?php echo esc_html( $akibara_gp_support_hours ); ?></span>
					</span>
				<?php endif; ?>
				<?php if ( '' !== $akibara_gp_phone_digits ) : ?>
					<a class="footer-nap__item" href="<?php echo esc_url( 'tel:+' . $akibara_gp_phone_digits ); ?>">
						<?php echo akibara_gp_icon( 'phone', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- akibara_gp_icon() devuelve un SVG de la lista blanca de nombres de inc/icons.php; el tamaño entra como %d en sprintf. No interpola nada del usuario. ?>
						<span><?php echo esc_html( $akibara_gp_phone ); ?></span>
					</a>
				<?php endif; ?>
			</div>
			<div class="footer-social">
				<a href="https://www.instagram.com/akibara.cl" class="footer-social__link" target="_blank" rel="noopener noreferrer" aria-label="Instagram">
					<?php echo akibara_gp_icon( 'instagram', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- akibara_gp_icon() devuelve un SVG de la lista blanca de nombres de inc/icons.php; el tamaño entra como %d en sprintf. No interpola nada del usuario. ?>
				</a>
				<a href="https://www.facebook.com/akibara.cl" class="footer-social__link" target="_blank" rel="noopener noreferrer" aria-label="Facebook">
					<?php echo akibara_gp_icon( 'facebook', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- akibara_gp_icon() devuelve un SVG de la lista blanca de nombres de inc/icons.php; el tamaño entra como %d en sprintf. No interpola nada del usuario. ?>
				</a>
				<?php if ( '' !== $akibara_gp_phone_digits ) : ?>
					<a href="<?php echo esc_url( 'https://wa.me/' . $akibara_gp_phone_digits ); ?>" class="footer-social__link" target="_blank" rel="noopener noreferrer" aria-label="WhatsApp">
						<?php echo akibara_gp_icon( 'whatsapp', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- akibara_gp_icon() devuelve un SVG de la lista blanca de nombres de inc/icons.php; el tamaño entra como %d en sprintf. No interpola nada del usuario. ?>
					</a>
				<?php endif; ?>
			</div>

			<?php if ( has_nav_menu( 'akibara-footer-mobile' ) ) : ?>
				<nav class="footer-mobile-shortcuts" aria-label="Accesos rápidos">
					<?php
					wp_nav_menu( array(
						'theme_location' => 'akibara-footer-mobile',
						'container'      => false,
						'items_wrap'     => '%3$s',
						'depth'          => 1,
						'walker'         => new Akibara_GP_Footer_Walker(),
						'fallback_cb'    => '__return_empty_string',
						// Custom args consumidos por el walker.
						'link_class'     => 'footer-mobile-shortcuts__item',
						'list_wrap'      => false,
					) );
					?>
				</nav>
			<?php endif; ?>
		</div>

		<?php
		$akb_render_footer_column( 'akibara-footer-catalog', 'Catálogo', 'footer-panel-catalogo' );
		$akb_render_footer_column( 'akibara-footer-explore', 'Novedades', 'footer-panel-explorar' );
		$akb_render_footer_column( 'akibara-footer-help',    'Ayuda',    'footer-panel-ayuda'    );
		?>

	</div>

	<div class="footer-bottom">
		<span>© <?php echo esc_html( wp_date( 'Y' ) ); ?> Akibara · Todos los derechos reservados.</span>
	</div>

</footer>
