<?php
/**
 * Walker custom para el menú principal.
 *
 * Renderea wp_nav_menu con clases BEM legacy (.mainnav__link,
 * .mainnav__item--has-sub, .mainnav__sub, .mainnav__sublink) en vez
 * de las clases default WP (.menu-item, .sub-menu).
 *
 * Resultado: `header.css` matchea exactamente sin reglas duplicadas
 * para selectores WP default. CSS file pesa 50% menos.
 *
 * UI puro — solo formatea el output del menú. NO lee data adicional,
 * NO hace queries, NO llamada a WC ni a otros plugins.
 *
 * @package akibara-gp
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

class Akibara_GP_Mainnav_Walker extends Walker_Nav_Menu {

	/**
	 * Stack de clases `mainnav__sub--*` capturadas del item padre depth=0,
	 * propagadas al `<div class="mainnav__sub">` que abre `start_lvl`.
	 *
	 * Permite que el admin agregue (Apariencia → Menús → CSS Classes) modifiers
	 * tipo `mainnav__sub--cols-2`, `--cols-3`, `--cols-4` en el item top-level
	 * y el walker los reenvía al contenedor del dropdown — habilita layout
	 * multi-columna desde wp-admin sin tocar código.
	 *
	 * @var list<string>
	 */
	private array $sub_classes_stack = array();

	/**
	 * IDs for submenus queued by parent items with children.
	 *
	 * @var list<string>
	 */
	private array $sub_id_stack = array();

	/**
	 * Parent links queued for submenus that should expose their archive link.
	 *
	 * @var list<array{title:string,url:string}>
	 */
	private array $parent_link_stack = array();

	public function start_lvl( &$output, $depth = 0, $args = null ) {
		$sub_id  = ! empty( $this->sub_id_stack ) ? end( $this->sub_id_stack ) : '';
		$id_attr = '' !== $sub_id ? ' id="' . esc_attr( $sub_id ) . '"' : '';

		if ( 0 === $depth ) {
			$extra   = ! empty( $this->sub_classes_stack ) ? ' ' . end( $this->sub_classes_stack ) : '';
			$output .= '<div class="mainnav__sub' . $extra . '"' . $id_attr . '>';
			if ( ! empty( $this->parent_link_stack ) ) {
				$parent = end( $this->parent_link_stack );
				if ( ! empty( $parent['url'] ) && ! empty( $parent['title'] ) ) {
					/* "Ver todo en <Categoría>" — "Ver todo Manga" era agramatical;
					   "en" da una preposición válida para cualquier nombre de categoría. */
					$output .= '<a href="' . esc_url( $parent['url'] ) . '" class="mainnav__sublink mainnav__sublink--parent">Ver todo en ' . esc_html( $parent['title'] ) . '</a>';
				}
			}
		} else {
			$output .= '<div class="mainnav__subsub"' . $id_attr . '>';
		}
	}

	public function end_lvl( &$output, $depth = 0, $args = null ) {
		if ( 0 === $depth && ! empty( $this->sub_classes_stack ) ) {
			array_pop( $this->sub_classes_stack );
		}
		if ( 0 === $depth && ! empty( $this->parent_link_stack ) ) {
			array_pop( $this->parent_link_stack );
		}
		if ( ! empty( $this->sub_id_stack ) ) {
			array_pop( $this->sub_id_stack );
		}
		$output .= '</div>';
	}

	public function start_el( &$output, $item, $depth = 0, $args = null, $id = 0 ) {
		$classes         = (array) $item->classes;
		$has_children    = in_array( 'menu-item-has-children', $classes, true );
		$is_active       = in_array( 'current-menu-item', $classes, true )
			|| in_array( 'current-menu-parent', $classes, true )
			|| in_array( 'current-menu-ancestor', $classes, true );
		$url             = $item->url ? esc_url( $item->url ) : '#';
		$title           = esc_html( $item->title );
		$raw_title       = (string) $item->title;
		$active_class    = $is_active ? ' mainnav__link--active' : '';
		$sub_active_class = $is_active ? ' mainnav__sublink--active' : '';
		$current_attr    = in_array( 'current-menu-item', $classes, true ) ? ' aria-current="page"' : '';

		if ( 0 === $depth ) {
			if ( $has_children ) {
				$submenu_id = $this->submenu_id( $item );
				$this->sub_id_stack[] = $submenu_id;
				// Capturar clases `mainnav__sub--*` del padre para que start_lvl
				// las pueda forwardear al contenedor del dropdown.
				$sub_modifiers = array_filter(
					$classes,
					static fn( $c ) => is_string( $c ) && str_starts_with( $c, 'mainnav__sub--' )
				);
				$sub_modifiers = array_map( 'sanitize_html_class', $sub_modifiers );
				$this->sub_classes_stack[] = $sub_modifiers ? implode( ' ', $sub_modifiers ) : '';
				$this->parent_link_stack[] = $this->should_inject_parent_link( $classes, $url )
					? array(
						'title' => $raw_title,
						'url'   => $url,
					)
					: array();

				$output .= '<div class="mainnav__item mainnav__item--has-sub">';
				$output .= '<a href="' . $url . '" class="mainnav__link' . $active_class . '"' . $current_attr . '>' . $title . '</a>';
				$output .= $this->toggle_html( $raw_title, $submenu_id, '' );
			} else {
				$output .= '<a href="' . $url . '" class="mainnav__link' . $active_class . '"' . $current_attr . '>' . $title . '</a>';
			}
		} elseif ( 1 === $depth ) {
			// Forward custom classes (ej. `mainnav__sub-label`, `mainnav__sublink--all`,
			// `akb-brands-mega-brand`) al elemento renderizado. Filtra las internas
			// de WP (`menu-item-*`, `current-*`).
			$custom_classes = array_filter(
				$classes,
				static fn( $c ) => is_string( $c ) && '' !== $c && ! preg_match( '/^(menu-item|current-)/', $c )
			);
			$extra_class = $custom_classes ? ' ' . implode( ' ', array_map( 'sanitize_html_class', $custom_classes ) ) : '';

			$is_label = in_array( 'mainnav__sub-label', $classes, true );

			if ( $has_children ) {
				$submenu_id = $this->submenu_id( $item, 'mainnav-subsub-' );
				$this->sub_id_stack[] = $submenu_id;
				$output .= '<div class="mainnav__sub-item mainnav__sub-item--has-sub' . $extra_class . '">';
				$output .= '<a href="' . $url . '" class="mainnav__sublink' . $sub_active_class . '"' . $current_attr . '>' . $title . '</a>';
				$output .= $this->toggle_html( $raw_title, $submenu_id, ' mainnav__toggle--sub' );
			} elseif ( $is_label ) {
				// Section header no navegable y anunciado por tecnologías de
				// asistencia para conservar el contexto regional de las marcas.
				$output .= '<span class="mainnav__sublink' . $extra_class . '" role="heading" aria-level="3">' . $title . '</span>';
			} else {
				$output .= '<a href="' . $url . '" class="mainnav__sublink' . $sub_active_class . $extra_class . '"' . $current_attr . '>' . $title . '</a>';
			}
		} else {
			$subsub_active_class = $is_active ? ' mainnav__subsublink--active' : '';
			$output .= '<a href="' . $url . '" class="mainnav__subsublink' . $subsub_active_class . '"' . $current_attr . '>' . $title . '</a>';
		}
	}

	public function end_el( &$output, $item, $depth = 0, $args = null ) {
		$has_children = in_array( 'menu-item-has-children', (array) $item->classes, true );
		if ( $has_children && ( 0 === $depth || 1 === $depth ) ) {
			$output .= '</div>';
		}
	}

	private function should_inject_parent_link( array $classes, string $url ): bool {
		if ( '#' === $url || '' === $url || in_array( 'akb-brands-mega', $classes, true ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Botón que abre y cierra el panel de un padre (patrón «disclosure», AI-8).
	 *
	 * El padre sigue siendo un enlace que navega; el panel lo gobierna este botón
	 * con aria-expanded/aria-controls. Antes esos atributos (y aria-haspopup) iban
	 * en el <a>: el lector anunciaba «menú emergente» en algo que al pulsar Enter
	 * navegaba, y el panel se abría solo al recibir el foco. main.js escucha el
	 * clic; sin JS, header.css abre el panel con :focus-within como respaldo.
	 *
	 * La flecha es un ::before en CSS (header.css), igual que antes lo era del
	 * enlace.
	 *
	 * @param string $title      Título del ítem, sin escapar.
	 * @param string $submenu_id id del panel que controla.
	 * @param string $modifier   Clase extra (con espacio inicial) o ''.
	 */
	private function toggle_html( string $title, string $submenu_id, string $modifier ): string {
		return '<button type="button" class="mainnav__toggle' . $modifier . '" aria-expanded="false" aria-controls="' . esc_attr( $submenu_id ) . '" aria-label="Submenú de ' . esc_attr( $title ) . '"></button>';
	}

	private function submenu_id( $item, string $prefix = 'mainnav-sub-' ): string {
		return $prefix . (int) $item->ID;
	}

}
