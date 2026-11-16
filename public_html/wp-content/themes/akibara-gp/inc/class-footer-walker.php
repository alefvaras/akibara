<?php
/**
 * Walker para columnas del footer.
 *
 * Convierte wp_nav_menu plano (depth=1) en `<li><a class="footer-column__link">`,
 * que es el markup que footer.css ya estiliza. Sin dropdowns, sin niveles.
 * Si owner agrega children al menú, este walker los ignora (son menús de footer
 * planos por convención).
 *
 * Variante mobile usa la misma clase pero con un argumento `link_class` distinto
 * (ver footer/main.php) — el walker acepta el override via $args->link_class.
 *
 * UI puro — solo formatea output. NO lee data adicional.
 *
 * @package akibara-gp
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * @api
 * @psalm-suppress InvalidReturnType
 * @psalm-suppress MissingOverrideAttribute
 * @psalm-suppress ParamNameMismatch
 * @psalm-suppress MissingOverrideAttribute
 */
class Akibara_GP_Footer_Walker extends Walker_Nav_Menu {

	/**
	 * @return mixed
	 * @psalm-suppress MissingOverrideAttribute
	 */
	#[\Override]
	public function start_lvl( &$output, $depth = 0, $args = null ) {
		// Footer columns son planos — ignoramos niveles anidados.
	}

	/**
	 * @return mixed
	 * @psalm-suppress MissingOverrideAttribute
	 */
	#[\Override]
	public function end_lvl( &$output, $depth = 0, $args = null ) {}

	/**
	 * @return mixed
	 * @psalm-suppress MissingOverrideAttribute
	 */
	#[\Override]
	public function start_el( &$output, $item, $depth = 0, $args = null, $id = 0 ) {
		if ( 0 !== $depth ) {
			return;
		}

		$url   = $item->url ? esc_url( $item->url ) : '#'; // @phpstan-ignore property.notFound
		$title = esc_html( $item->title ); // @phpstan-ignore property.notFound
		// `link_class` es un arg custom — wp_nav_menu lo ignora pero llega al walker.
		// Default `footer-column__link`; mobile shortcuts usa `footer-mobile-shortcuts__item`.
		$link_class = isset( $args->link_class ) && is_string( $args->link_class )
			? $args->link_class
			: 'footer-column__link';

		// Wrap en <li> solo cuando el caller pide list_wrap (default true).
		$wrap_li = ! ( isset( $args->list_wrap ) && false === $args->list_wrap );

		if ( $wrap_li ) {
			$output .= '<li>';
		}
		$output .= '<a href="' . $url . '" class="' . esc_attr( $link_class ) . '">' . $title . '</a>';
		if ( $wrap_li ) {
			$output .= '</li>';
		}
	}

	/**
	 * @return mixed
	 * @psalm-suppress MissingOverrideAttribute
	 */
	#[\Override]
	public function end_el( &$output, $item, $depth = 0, $args = null ) {}
}
