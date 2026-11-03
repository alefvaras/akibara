<?php
/**
 * Walker para el menú móvil.
 *
 * Convierte wp_nav_menu del location 'akibara-primary' en markup BEM
 * compatible con el accordion JS del mobile drawer (main.js).
 *
 * Niveles:
 *   0 → sin hijos: <a class="mobile-drawer__link">
 *   0 → con hijos: <div.mobile-drawer__group> + <button.mobile-drawer__toggle>
 *                  + <div.mobile-drawer__sub id="mob-N">
 *   1 → sin hijos: <a class="mobile-drawer__sublink [clases WP]">
 *   1 → con hijos: <div.mobile-drawer__subgroup> + <button.mobile-drawer__subtoggle>
 *                  + <div.mobile-drawer__subsub id="mob-sub-N">
 *   2 → <a class="mobile-drawer__subsublink">
 *
 * IDs de accordion generados con $toggle_counter (único por instancia).
 * El JS existente (.mobile-drawer__toggle + data-target) los maneja sin cambios.
 *
 * @package akibara-gp
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

class Akibara_GP_Mobile_Walker extends Walker_Nav_Menu {

	/** Contador para IDs de accordion únicos por instancia. */
	private int $toggle_counter = 0;

	/**
	 * Parent archive links queued for the submenu currently being opened.
	 *
	 * @var list<array{title:string,url:string}>
	 */
	private array $parent_link_stack = array();

	public function start_lvl( &$output, $depth = 0, $args = null ) {
		if ( 0 === $depth ) {
			$output .= '<div class="mobile-drawer__sub" id="mob-' . $this->toggle_counter . '">';
			if ( ! empty( $this->parent_link_stack ) ) {
				$parent = end( $this->parent_link_stack );
				if ( ! empty( $parent['url'] ) && ! empty( $parent['title'] ) ) {
					$output .= '<a href="' . esc_url( $parent['url'] ) . '" class="mobile-drawer__sublink mobile-drawer__sublink--parent">Ver todo en ' . esc_html( $parent['title'] ) . '</a>';
				}
			}
		} else {
			$output .= '<div class="mobile-drawer__subsub" id="mob-sub-' . $this->toggle_counter . '">';
		}
	}

	public function end_lvl( &$output, $depth = 0, $args = null ) {
		if ( 0 === $depth && ! empty( $this->parent_link_stack ) ) {
			array_pop( $this->parent_link_stack );
		}
		$output .= '</div>';
	}

	public function start_el( &$output, $item, $depth = 0, $args = null, $id = 0 ) {
		$classes      = (array) $item->classes;
		$has_children = in_array( 'menu-item-has-children', $classes, true );
		$url          = $item->url ? esc_url( $item->url ) : '#';
		$title        = esc_html( $item->title );
		$raw_title    = (string) $item->title;
		$is_current   = in_array( 'current-menu-item', $classes, true );
		$is_active    = $is_current
			|| in_array( 'current-menu-parent', $classes, true )
			|| in_array( 'current-menu-ancestor', $classes, true );
		$current_attr = in_array( 'current-menu-item', $classes, true ) ? ' aria-current="page"' : '';
		$active_class = $is_active ? ' mobile-drawer__link--active' : '';

		if ( 0 === $depth ) {
			if ( $has_children ) {
				++$this->toggle_counter;
				$target  = 'mob-' . $this->toggle_counter;
				$this->parent_link_stack[] = $this->should_inject_parent_link( $classes, $url )
					? array(
						'title' => $raw_title,
						'url'   => $url,
					)
					: array();
				$output .= '<div class="mobile-drawer__group">';
				$output .= '<button class="mobile-drawer__link mobile-drawer__toggle' . $active_class . '"'
					. ' data-target="' . esc_attr( $target ) . '"'
					. ' aria-expanded="false"'
					. ' aria-controls="' . esc_attr( $target ) . '"'
					. ' type="button">'
					. '<span>' . $title . '</span>'
					. '</button>';
			} else {
				$output .= '<a href="' . $url . '" class="mobile-drawer__link' . $active_class . '"' . $current_attr . '>' . $title . '</a>';
			}
		} elseif ( 1 === $depth ) {
			// Permite clases custom desde wp-admin (ej. mobile-drawer__sublink--accent).
			$extra    = $this->extra_classes( $classes );
			$is_label = in_array( 'mainnav__sub-label', $classes, true );

			if ( $has_children ) {
				++$this->toggle_counter;
				$target          = 'mob-sub-' . $this->toggle_counter;
				$subtoggle_class = $is_active ? ' mobile-drawer__subtoggle--active' : '';
				$output .= '<div class="mobile-drawer__subgroup">';
				$output .= '<button class="mobile-drawer__subtoggle mobile-drawer__toggle' . $subtoggle_class . '"'
					. ' data-target="' . esc_attr( $target ) . '"'
					. ' aria-expanded="false"'
					. ' aria-controls="' . esc_attr( $target ) . '"'
					. ' type="button">'
					. '<span>' . $title . '</span>'
					. '</button>';
			} elseif ( $is_label ) {
				// Section header no navegable — espejo del walker desktop, evita
				// renderizar `<a href="#">` clickeable y mantiene el contexto
				// regional disponible para tecnologías de asistencia.
				$output .= '<span class="mobile-drawer__sublabel" role="heading" aria-level="3">' . $title . '</span>';
			} else {
				$cls     = 'mobile-drawer__sublink'
					. ( $is_current ? ' mobile-drawer__sublink--active' : '' )
					. ( $extra ? ' ' . $extra : '' );
				$output .= '<a href="' . $url . '" class="' . esc_attr( $cls ) . '"' . $current_attr . '>' . $title . '</a>';
			}
		} else {
			$active = $is_current ? ' mobile-drawer__subsublink--active' : '';
			$output .= '<a href="' . $url . '" class="mobile-drawer__subsublink' . $active . '"' . $current_attr . '>' . $title . '</a>';
		}
	}

	public function end_el( &$output, $item, $depth = 0, $args = null ) {
		$has_children = in_array( 'menu-item-has-children', (array) $item->classes, true );

		if ( $has_children && ( 0 === $depth || 1 === $depth ) ) {
			$output .= '</div>';
		}
	}

	/**
	 * Extrae clases custom del item (las que no son clases WP internas).
	 *
	 * @param string[] $classes Clases del menu item.
	 * @return string Clases extra separadas por espacio, o string vacío.
	 */
	private function extra_classes( array $classes ): string {
		$wp_classes = array(
			'menu-item', 'menu-item-type-post_type', 'menu-item-type-taxonomy',
			'menu-item-type-custom', 'menu-item-object-page', 'menu-item-object-category',
			'menu-item-object-custom', 'menu-item-has-children', 'current-menu-item',
			'current-menu-ancestor', 'current-menu-parent', 'current_page_item',
			'current_page_ancestor', 'current_page_parent',
		);

		$custom = array_filter( $classes, function ( string $c ) use ( $wp_classes ): bool {
			return '' !== $c && ! in_array( $c, $wp_classes, true ) && 0 !== strpos( $c, 'menu-item-' );
		} );

		return implode( ' ', $custom );
	}

	private function should_inject_parent_link( array $classes, string $url ): bool {
		if ( '#' === $url || '' === $url || in_array( 'akb-brands-mega', $classes, true ) ) {
			return false;
		}

		return true;
	}

}
