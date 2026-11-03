<?php
/**
 * Series Bundles — Admin\AdminSection.
 *
 * Estructura de la sección "Arma tu Pack" del Admin_Panel: provee los
 * campos de ajustes GLOBALES del módulo (`get_settings()`, en formato WC
 * settings, igual que `FreeShippingProgress::get_settings`) y renderiza la
 * status card del header (`render_header()`, estilo `TaxonomyDiscount`).
 *
 * La configuración por serie vive en BypConfigController; esta clase se limita
 * a los toggles globales y al resumen de estado.
 *
 * Campos globales expuestos (Req 1.1, 7.5):
 *   - Activar módulo            → Settings::OPTION_ENABLED         (checkbox)
 *   - Categorías excluidas      → Settings::OPTION_EXCLUDE_CATS    (multiselect de term_ids)
 *   - Permitir reserva global   → Settings::OPTION_ALLOW_BACKORDER (checkbox)
 *
 * Sin funciones i18n (Req 11.4): todos los textos en español literal. Toda la
 * salida del header se escapa (`esc_html`, `esc_attr`, `esc_url`).
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\SeriesBundles\Admin;

use Akibara\Business\Modules\SeriesBundles\Settings;

defined( 'ABSPATH' ) || exit;

final class AdminSection {

	private Settings $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Settings fields globales del módulo para el Admin_Panel.
	 *
	 * Formato WC settings (title + fields + sectionend), idéntico al patrón de
	 * `FreeShippingProgress::get_settings`. Solo expone los toggles globales;
	 * la configuración detallada por serie se renderiza aparte.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_settings(): array {
		$cat_options = array();
		if ( function_exists( 'get_terms' ) ) {
			$categories = get_terms( array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			) );
			if ( ! is_wp_error( $categories ) && is_array( $categories ) ) {
				foreach ( $categories as $category ) {
					$cat_options[ $category->term_id ] = $category->name;
				}
			}
		}

		return array(
				array(
					'title' => 'Arma tu Pack (packs por serie)',
					'type'  => 'title',
					'desc'  => 'Permite que el cliente elija varios tomos de una serie y obtenga un descuento según la cantidad. Revisa las categorías excluidas antes de activar una campaña.',
					'id'    => 'akb_series_bundles_section',
				),
				array(
					'title'   => 'Activar Arma tu Pack',
					'desc'    => 'Al desactivarlo, se oculta el selector de packs y no se aplican sus descuentos.',
					'id'      => Settings::OPTION_ENABLED,
				'type'    => 'checkbox',
				'default' => 'no',
			),
			array(
					'title'             => 'Categorías sin descuento',
					'desc'              => 'Los productos de estas categorías no cuentan para el pack ni reciben su descuento. Si no seleccionas ninguna, todas las categorías elegibles podrán participar.',
				'desc_tip'          => true,
				'id'                => Settings::OPTION_EXCLUDE_CATS,
				'type'              => 'multiselect',
				'class'             => 'wc-enhanced-select',
				'css'               => 'min-width:320px;',
				'default'           => array(),
				'options'           => $cat_options,
				'custom_attributes' => array(
						'data-placeholder' => 'Seleccionar categorías…',
				),
			),
			array(
					'title'   => 'Los tomos a pedido suman para el descuento',
					'desc'    => 'Los tomos que aún no están en bodega (a pedido o en preventa) siempre se pueden ver, elegir y agregar junto con los demás, y el cliente ve su fecha estimada antes de hacerlo. Lo que decide este interruptor es si CUENTAN para el tramo de descuento por cantidad.',
				'id'      => Settings::OPTION_ALLOW_BACKORDER,
				'type'    => 'checkbox',
				'default' => 'no',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'akb_series_bundles_section',
			),
		);
	}

	/**
	 * Header del Admin_Panel: status card con el estado del módulo (Activo/Apagado).
	 * Toda la salida se escapa.
	 *
	 * @return void
	 */
		public function render_header(): void {
			// La tarjeta de estado confirma las opciones directamente desde la base.
			// No mezclar el guardado nuevo con valores de una caché persistente anterior.

			$estado         = $this->settings->status_snapshot_from_database();
			$is_enabled     = $estado['enabled'];
			$enabled_count  = $estado['enabled_count'];
			$excluded_count = $estado['excluded_count'];

			$status_text = $is_enabled ? 'Activo' : 'Apagado';
			$dot_class   = $is_enabled ? 'akb-sb-status__dot--on' : 'akb-sb-status__dot--off';
			?>
		<section class="akb-sb-status-card" aria-labelledby="akb-sb-status-heading">
			<h2 id="akb-sb-status-heading" class="akb-sb-status-card__heading">Estado</h2>
			<div class="akb-sb-status">
					<span class="akb-sb-status__badge">
						<span class="akb-sb-status__dot <?php echo esc_attr( $dot_class ); ?>" aria-hidden="true"></span>
						<?php echo esc_html( $status_text ); ?>
					</span>
					<div class="akb-sb-status__meta">
						<span><strong>Series activas:</strong> <?php echo (int) $enabled_count; ?></span>
						<span><strong>Categorías excluidas:</strong> <?php echo (int) $excluded_count; ?></span>
						<span><strong>Pedidos y preventa:</strong> <?php echo $estado['allows_backorder'] ? 'incluidas' : 'excluidas'; ?></span>
					</div>
				</div>
		</section>
		<?php
	}
}
