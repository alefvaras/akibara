<?php
/**
 * Tab «Variante del tomo» en la pantalla de edición de producto de WooCommerce.
 *
 * Es la palanca del dueño para el único caso que el título no puede declarar:
 * dos productos distintos que son EL MISMO número de tomo de la misma serie
 * («Demon Slayer 23» y «Demon Slayer 23 (Edición Especial)»). El porqué de que
 * sea una marca por ficha y no un regex está en {@see VolumeVariant}.
 *
 * Hooks oficiales de WooCommerce, los mismos que usa el panel «Disponibilidad»:
 *   - `woocommerce_product_data_tabs`   → registra el tab.
 *   - `woocommerce_product_data_panels` → pinta el panel.
 *   - `woocommerce_process_product_meta`→ guarda (WC ya verificó el nonce).
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\VolumeVariant;

use Akibara\Business\Services\VolumeVariant;

defined( 'ABSPATH' ) || exit;

/**
 * Product edit screen — marca de «otra versión del mismo tomo».
 *
 * @since 1.116.0
 */
final class ProductDataPanel {

	/**
	 * Campo oculto que confirma que el panel viajó en el envío del formulario.
	 *
	 * `woocommerce_process_product_meta` corre en CADA guardado de producto,
	 * venga de donde venga (edición rápida, actualización masiva, un
	 * `wp post update`). Sin este testigo, «el dueño lo dejó en tomo normal» y
	 * «este POST nunca incluyó el panel» se leerían igual, y el segundo caso
	 * borraría la marca en silencio: exactamente el defecto que documenta
	 * `EstimatedDelivery\ProductDataPanel::PANEL_MARKER` y que ya se pagó ahí.
	 */
	public const PANEL_MARKER = '_akb_variante_panel';

	/** Registra los hooks. Llamado desde `Plugin`. */
	public function register(): void {
		add_filter( 'woocommerce_product_data_tabs', array( $this, 'add_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( $this, 'render_panel' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_product_meta' ) );
	}

	/**
	 * Agrega el tab al meta box «Datos del producto».
	 *
	 * @param array<string, array<string, mixed>> $tabs Tabs registrados por WC + plugins.
	 * @return array<string, array<string, mixed>>
	 */
	public function add_tab( array $tabs ): array {
		$tabs['akb_variante'] = array(
			'label'    => 'Variante del tomo',
			'target'   => 'akb_variante_data',
			'class'    => array( 'show_if_simple', 'show_if_variable', 'show_if_external' ),
			'priority' => 71,
		);

		return $tabs;
	}

	/** Pinta el panel. */
	public function render_panel(): void {
		global $post;

		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		$current = VolumeVariant::kind_of( (int) $post->ID );
		?>
		<div id="akb_variante_data" class="panel woocommerce_options_panel hidden">
			<div class="options_group">
				<?php // Testigo del panel: ver PANEL_MARKER. ?>
				<input type="hidden" name="<?php echo esc_attr( self::PANEL_MARKER ); ?>" value="1" />

				<p class="form-field">
					<label for="<?php echo esc_attr( VolumeVariant::META_KEY ); ?>"><strong>¿Es otra versión de un tomo que ya está en la serie?</strong></label>
					<select
						id="<?php echo esc_attr( VolumeVariant::META_KEY ); ?>"
						name="<?php echo esc_attr( VolumeVariant::META_KEY ); ?>"
						class="select short"
						style="width:100%;max-width:38em;"
					>
						<?php foreach ( VolumeVariant::kinds() as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current, $value ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</p>

				<p class="form-field">
					<span class="description">
						Marca esto <strong>sólo</strong> si el mismo número de tomo ya existe en esta serie como producto aparte. Si es el único producto con ese número —aunque el título lleve «portada alternativa» o «edición especial»— déjalo en <em>tomo normal</em>: marcarlo lo borraría del recuento de la serie y sus compradores verían un tomo faltante que no falta.
					</span>
				</p>

				<p class="form-field">
					<span class="description">
						Marcado como variante, el tomo <strong>se sigue vendiendo igual</strong>. Lo que cambia es que deja de contarse dos veces: no dispara el aviso de «salió un tomo nuevo», no aparece como faltante en Mi colección de quien ya tiene ese número, y la ficha del tomo normal no lo ofrece como «siguiente».
					</span>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Persiste la marca. WooCommerce ya verificó nonce y permisos en su flujo.
	 *
	 * @param int $product_id ID del producto.
	 */
	public function save_product_meta( int $product_id ): void {
		if ( ! current_user_can( 'edit_product', $product_id ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WC verifica el nonce antes de llamar a process_product_meta.
		if ( empty( $_POST[ self::PANEL_MARKER ] ) ) {
			return;
		}

		$kind = VolumeVariant::sanitize_kind(
			isset( $_POST[ VolumeVariant::META_KEY ] ) ? wp_unslash( $_POST[ VolumeVariant::META_KEY ] ) : '' // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_kind() es una allowlist cerrada.
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( VolumeVariant::KIND_NONE === $kind ) {
			// El vacío es el default: se BORRA en vez de guardarse. Así la ausencia
			// de la meta y «tomo normal» son el mismo estado y ninguna consulta
			// tiene que distinguirlos.
			delete_post_meta( $product_id, VolumeVariant::META_KEY );
			return;
		}

		update_post_meta( $product_id, VolumeVariant::META_KEY, $kind );
	}
}
