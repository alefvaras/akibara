<?php
/**
 * Controles nativos de Apariencia → Menús para las clases del megamenú.
 * Leen y escriben las mismas clases que el campo CSS existente, sin añadir
 * una fuente de datos ni exigir cambios al walker o a MenuInjector.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\BrandsDirectory;

use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.98.0
 */
final class MenuItemFields {

	/**
	 * Modificadores de layout del dropdown gestionados por el select.
	 * '' = dropdown simple (sin modificador).
	 *
	 * @var array<string, string>
	 */
	private const LAYOUTS = array(
		''                    => 'Lista simple (por defecto)',
		'mainnav__sub--auto'  => 'Grilla automática (multi-columna)',
		'mainnav__sub--cols-2' => 'Grilla fija de 2 columnas',
		'mainnav__sub--cols-3' => 'Grilla fija de 3 columnas',
		'mainnav__sub--cols-4' => 'Grilla fija de 4 columnas',
	);

	private const CLASS_LABEL = 'mainnav__sub-label';

	public function register(): void {
		add_action( 'wp_nav_menu_item_custom_fields', array( $this, 'render_fields' ), 10, 2 );
		add_action( 'wp_update_nav_menu_item', array( $this, 'save_fields' ), 10, 2 );
	}

	/**
	 * Controles bajo cada item en Apariencia → Menús.
	 *
	 * @param int|string $item_id ID del item de menú.
	 * @param WP_Post    $item    Post del item (clases en $item->classes).
	 * @return void
	 */
	public function render_fields( $item_id, $item ): void {
		if ( ! $item instanceof WP_Post ) {
			return;
		}

		$item_id = (int) $item_id;
		$classes = is_array( $item->classes ) ? array_map( 'strval', $item->classes ) : array();

		$is_mega  = in_array( MenuInjector::TRIGGER_CLASS, $classes, true );
		$is_label = in_array( self::CLASS_LABEL, $classes, true );

		$current_layout = '';
		foreach ( array_keys( self::LAYOUTS ) as $layout_class ) {
			if ( '' !== $layout_class && in_array( $layout_class, $classes, true ) ) {
				$current_layout = $layout_class;
				break;
			}
		}
		?>
		<div class="akb-menu-fields description-wide" style="margin: 8px 0 0; padding: 8px 10px; background: #f6f7f7; border: 1px solid #dcdcde; border-radius: 3px;">
			<p style="margin: 0 0 6px; font-weight: 600;">Megamenú Akibara</p>
			<input type="hidden" name="akb-menu-fields-present[<?php echo esc_attr( (string) $item_id ); ?>]" value="1">
			<p class="description" style="margin: 4px 0;">
				<label>
					<input type="checkbox" name="akb-menu-mega[<?php echo esc_attr( (string) $item_id ); ?>]" value="1" <?php checked( $is_mega ); ?>>
					Mega de editoriales — inyecta automáticamente todas las marcas con productos (solo items de primer nivel)
				</label>
			</p>
			<p class="description" style="margin: 4px 0;">
				<label>
					Layout del dropdown:
					<select name="akb-menu-layout[<?php echo esc_attr( (string) $item_id ); ?>]">
						<?php foreach ( self::LAYOUTS as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current_layout, $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
			</p>
			<p class="description" style="margin: 4px 0;">
				<label>
					<input type="checkbox" name="akb-menu-label[<?php echo esc_attr( (string) $item_id ); ?>]" value="1" <?php checked( $is_label ); ?>>
					Título de sección — texto no clickeable que agrupa los items siguientes (solo items dentro de un dropdown)
				</label>
			</p>
		</div>
		<?php
	}

	/**
	 * Sincroniza los checkboxes/select con las clases del item recién guardado.
	 *
	 * Corre en `wp_update_nav_menu_item`, DESPUÉS de que WP persistió
	 * `_menu_item_classes` desde el campo "Clases CSS": leemos ese estado,
	 * quitamos solo las clases gestionadas y re-agregamos las elegidas. Las
	 * clases ajenas (de estilo, de terceros) pasan intactas.
	 *
	 * Sin nonce propio: corre dentro del save de la pantalla de Menús, que ya
	 * verificó `update-nav_menu` + capability; el marcador `present` evita
	 * borrar clases en saves programáticos (wp_update_nav_menu_item vía código
	 * no postea nuestros campos).
	 *
	 * @param int $menu_id         ID del menú (no usado).
	 * @param int $menu_item_db_id ID del item guardado.
	 * @return void
	 */
	public function save_fields( $menu_id, $menu_item_db_id ): void {
		unset( $menu_id );

		$item_id = (int) $menu_item_db_id;

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- la pantalla de Menús ya verificó update-nav_menu; ver docblock.
		$present = isset( $_POST['akb-menu-fields-present'][ $item_id ] );
		if ( ! $present || ! current_user_can( 'edit_theme_options' ) ) {
			return;
		}

		$want_mega  = isset( $_POST['akb-menu-mega'][ $item_id ] );
		$want_label = isset( $_POST['akb-menu-label'][ $item_id ] );

		$want_layout = isset( $_POST['akb-menu-layout'][ $item_id ] )
			? sanitize_text_field( wp_unslash( (string) $_POST['akb-menu-layout'][ $item_id ] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( ! array_key_exists( $want_layout, self::LAYOUTS ) ) {
			$want_layout = '';
		}

		$classes = get_post_meta( $item_id, '_menu_item_classes', true );
		$classes = is_array( $classes ) ? array_map( 'strval', $classes ) : array();

		$managed = array_merge(
			array( MenuInjector::TRIGGER_CLASS, self::CLASS_LABEL ),
			array_values( array_filter( array_keys( self::LAYOUTS ) ) )
		);
		$classes = array_values( array_diff( $classes, $managed ) );

		if ( $want_mega ) {
			$classes[] = MenuInjector::TRIGGER_CLASS;
		}
		if ( '' !== $want_layout ) {
			$classes[] = $want_layout;
		}
		if ( $want_label ) {
			$classes[] = self::CLASS_LABEL;
		}

		update_post_meta( $item_id, '_menu_item_classes', $classes );
	}
}
