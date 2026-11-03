<?php
/**
 * BrandFeatured — flag "Destacada en home" por editorial (taxonomy `product_brand`).
 *
 * Permite al owner forzar que una editorial aparezca en la vitrina capada del
 * home aunque tenga poco catálogo (que de otro modo quedaría fuera del top-N por
 * `count`). {@see HomeCarousel::fetch_brands()} ordena las destacadas primero.
 *
 * Espeja el patrón de {@see RegionMeta}: campo en add/edit del término + columna
 * admin. El cache del carrusel se invalida solo: HomeCarousel engancha su
 * flush_cache a `edited_product_brand`, que dispara al guardar el término.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\BrandsDirectory;

use WP_Term;

defined( 'ABSPATH' ) || exit;

final class BrandFeatured {

	private const TAXONOMY = 'product_brand';
	private const NONCE    = 'akibara_featured_meta_nonce';

	/** Term meta: '1' = destacada en la vitrina del home. */
	public const META_KEY = 'akb_brand_featured';

	public function register(): void {
		add_action( self::TAXONOMY . '_add_form_fields',  array( $this, 'render_add_field' ) );
		add_action( self::TAXONOMY . '_edit_form_fields', array( $this, 'render_edit_field' ) );
		add_action( 'created_' . self::TAXONOMY,          array( $this, 'save_meta' ) );
		add_action( 'edited_' . self::TAXONOMY,           array( $this, 'save_meta' ) );

		add_filter( 'manage_edit-' . self::TAXONOMY . '_columns',  array( $this, 'admin_column' ) );
		add_filter( 'manage_' . self::TAXONOMY . '_custom_column', array( $this, 'render_admin_column' ), 10, 3 );
	}

	/**
	 * True si la editorial está marcada como destacada en el home.
	 */
	public static function is_featured( int $term_id ): bool {
		return '1' === (string) get_term_meta( $term_id, self::META_KEY, true );
	}

	public function render_add_field(): void {
		wp_nonce_field( 'save_' . self::META_KEY, self::NONCE );
		?>
		<div class="form-field">
			<label for="akibara_featured">
				<input type="checkbox" id="akibara_featured" name="akibara_featured" value="1">
				Destacada en la home
			</label>
			<p>Fuerza que la editorial aparezca en la vitrina del home aunque tenga poco catálogo. Si no, el home muestra las de mayor catálogo.</p>
		</div>
		<?php
	}

	public function render_edit_field( WP_Term $term ): void {
		$current = self::is_featured( $term->term_id );
		wp_nonce_field( 'save_' . self::META_KEY, self::NONCE );
		?>
		<tr class="form-field">
			<th scope="row"><label for="akibara_featured">Destacada en home (Akibara)</label></th>
			<td>
				<label for="akibara_featured">
					<input type="checkbox" id="akibara_featured" name="akibara_featured" value="1" <?php checked( $current ); ?>>
					Mostrar en la vitrina del home
				</label>
				<p class="description">
					Fuerza la aparición en la vitrina capada del home aunque la editorial tenga poco catálogo.
					Sin marcar, el home muestra las de mayor cantidad de títulos.
				</p>
			</td>
		</tr>
		<?php
	}

	public function save_meta( int $term_id ): void {
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ), 'save_' . self::META_KEY ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_categories' ) ) {
			return;
		}

		if ( isset( $_POST['akibara_featured'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['akibara_featured'] ) ) ) {
			update_term_meta( $term_id, self::META_KEY, '1' );
			return;
		}

		delete_term_meta( $term_id, self::META_KEY );
	}

	/**
	 * @param array<string, string> $columns
	 * @return array<string, string>
	 */
	public function admin_column( array $columns ): array {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'name' === $key ) {
				$new['akb_featured'] = 'Home';
			}
		}
		return $new;
	}

	/**
	 * @param string $content
	 * @param string $column
	 * @param int    $term_id
	 * @return string
	 */
	public function render_admin_column( $content, $column, $term_id ): string {
		if ( 'akb_featured' !== $column ) {
			return (string) $content;
		}
		return self::is_featured( (int) $term_id )
			? '<span title="Destacada en home" style="color:#e60012;font-size:16px">★</span>'
			: '<span style="color:#ccc">—</span>';
	}
}
