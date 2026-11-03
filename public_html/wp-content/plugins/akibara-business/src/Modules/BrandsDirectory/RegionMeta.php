<?php
/**
 * RegionMeta — admin field "Región" en Edit Brand (taxonomy `product_brand`).
 *
 * Permite que el owner override la auto-clasificación por slug. Cuando el
 * field queda vacío (default "— Auto —"), `BrandRegion::for_term()` cae
 * al heurístico de slug, y de ahí al map.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\BrandsDirectory;

use WP_Term;

defined( 'ABSPATH' ) || exit;

final class RegionMeta {

	private const TAXONOMY = 'product_brand';
	private const NONCE    = 'akibara_region_meta_nonce';

	public function register(): void {
		add_action( self::TAXONOMY . '_add_form_fields',  array( $this, 'render_add_field' ) );
		add_action( self::TAXONOMY . '_edit_form_fields', array( $this, 'render_edit_field' ) );
		add_action( 'created_' . self::TAXONOMY,          array( $this, 'save_meta' ) );
		add_action( 'edited_' . self::TAXONOMY,           array( $this, 'save_meta' ) );

		add_filter( 'manage_edit-' . self::TAXONOMY . '_columns',         array( $this, 'admin_column' ) );
		add_filter( 'manage_' . self::TAXONOMY . '_custom_column',        array( $this, 'render_admin_column' ), 10, 3 );
	}

	public function render_add_field(): void {
		wp_nonce_field( 'save_' . BrandRegion::META_KEY, self::NONCE );
		?>
		<div class="form-field">
			<label for="akibara_region">Región (Akibara)</label>
			<select id="akibara_region" name="akibara_region">
				<?php foreach ( BrandRegion::admin_choices() as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<p>Agrupa la marca en el directorio de editoriales. "Auto" detecta por slug (sufijo `-argentina` / `-espana`).</p>
		</div>
		<?php
	}

	public function render_edit_field( WP_Term $term ): void {
		$current = (string) get_term_meta( $term->term_id, BrandRegion::META_KEY, true );
		wp_nonce_field( 'save_' . BrandRegion::META_KEY, self::NONCE );
		?>
		<tr class="form-field">
			<th scope="row"><label for="akibara_region">Región (Akibara)</label></th>
			<td>
				<select id="akibara_region" name="akibara_region">
					<?php foreach ( BrandRegion::admin_choices() as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current, $value ); ?>>
							<?php echo esc_html( $label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<p class="description">
					Agrupa la marca en el directorio de editoriales. <strong>Auto</strong> detecta por slug
					(sufijo <code>-argentina</code> / <code>-espana</code>). Detección actual: <code><?php echo esc_html( BrandRegion::for_slug( $term->slug ) ); ?></code>.
				</p>
			</td>
		</tr>
		<?php
	}

	public function save_meta( int $term_id ): void {
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ), 'save_' . BrandRegion::META_KEY ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_categories' ) ) {
			return;
		}

		$value = isset( $_POST['akibara_region'] ) ? sanitize_key( wp_unslash( $_POST['akibara_region'] ) ) : '';

		if ( '' === $value ) {
			delete_term_meta( $term_id, BrandRegion::META_KEY );
			return;
		}

		if ( in_array( $value, BrandRegion::valid_regions(), true ) ) {
			update_term_meta( $term_id, BrandRegion::META_KEY, $value );
		}
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
				$new['akb_region'] = 'Región Akibara';
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
		if ( 'akb_region' !== $column ) {
			return (string) $content;
		}
		$term = get_term( $term_id, self::TAXONOMY );
		if ( ! $term instanceof WP_Term ) {
			return '—';
		}
		$resolved = BrandRegion::for_term( $term );
		$has_meta = '' !== (string) get_term_meta( $term_id, BrandRegion::META_KEY, true );
		$suffix   = $has_meta ? ' <small style="color:#888">(manual)</small>' : ' <small style="color:#888">(auto)</small>';
		return esc_html( BrandRegion::label( $resolved ) ) . $suffix;
	}
}
