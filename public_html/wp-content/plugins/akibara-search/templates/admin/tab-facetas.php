<?php
/**
 * Template: Tab Facetas de SearchAdminSection.
 *
 * @package Akibara\Search
 * @var array<string, mixed> $view_data
 * @var \Akibara\Search\SearchAdminSection $this
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

use Akibara\Search\FacetRegistry;
use Akibara\Search\SearchAdminSection;

$defs           = (array) ( $view_data['defs'] ?? array() );
$seed_names     = (array) ( $view_data['seed_names'] ?? array() );
$eligible_taxes = (array) ( $view_data['eligible_taxes'] ?? array() );
$capped         = (array) ( $view_data['capped'] ?? array() );
?>
<h2 style="margin-top:8px">Facetas del catálogo</h2>
<p class="description">Activa o desactiva facetas, cambia su etiqueta y ordénalas con los botones ↑/↓. También puedes registrar una faceta nueva sobre cualquier taxonomía del catálogo — todo sin tocar código.</p>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<input type="hidden" name="action" value="<?php echo esc_attr( 'akb_search_save_facets' ); ?>">
	<?php wp_nonce_field( 'akb_search_facets_nonce' ); ?>

	<div id="akb-facets-live" class="screen-reader-text" role="status" aria-live="polite"></div>

	<div class="akb-facets-scroll">
		<table class="widefat striped akb-facets-table">
			<thead>
				<tr>
					<th scope="col" style="width:88px">Orden</th>
					<th scope="col">Faceta</th>
					<th scope="col">Etiqueta visible</th>
					<th scope="col">Tipo / fuente / UI</th>
					<th scope="col" style="width:170px">Contexto (padre)</th>
					<th scope="col" style="width:70px">Visible</th>
					<th scope="col" style="width:80px"><span class="screen-reader-text">Quitar</span></th>
				</tr>
			</thead>
			<tbody data-akb-facet-rows>
				<?php foreach ( $defs as $name => $def ) : ?>
					<?php
					$name        = (string) $name;
					$fid         = 'akb-facet-' . $name;
					$label_txt   = (string) ( $def['label'] ?? $name );
					$visible     = ! isset( $def['visible'] ) || $def['visible'];
					$is_taxonomy = 'taxonomy' === ( $def['type'] ?? '' );
					$is_seed     = in_array( $name, $seed_names, true );
					$source      = $is_taxonomy ? (string) ( $def['taxonomy'] ?? '' ) : (string) ( $def['type'] ?? '' );
					?>
					<tr data-akb-facet-row="<?php echo esc_attr( $name ); ?>">
						<td class="akb-facet-order" data-label="Orden">
							<button type="button" class="button-link" data-akb-move="up" aria-label="<?php echo esc_attr( 'Subir ' . $label_txt ); ?>"><span aria-hidden="true">▲</span></button>
							<button type="button" class="button-link" data-akb-move="down" aria-label="<?php echo esc_attr( 'Bajar ' . $label_txt ); ?>"><span aria-hidden="true">▼</span></button>
						</td>
						<th scope="row"><code><?php echo esc_html( $name ); ?></code></th>
						<td data-label="Etiqueta visible">
							<label class="screen-reader-text" for="<?php echo esc_attr( $fid ); ?>-label">Etiqueta visible de <?php echo esc_html( $name ); ?></label>
							<input type="text" id="<?php echo esc_attr( $fid ); ?>-label" name="facet[<?php echo esc_attr( $name ); ?>][label]" value="<?php echo esc_attr( $label_txt ); ?>" class="regular-text" style="width:100%">
						</td>
						<td data-label="Tipo / fuente / UI">
							<span class="description"><?php echo esc_html( (string) ( $def['type'] ?? '' ) ); ?> · <?php echo esc_html( $source ); ?></span><br>
							<label class="screen-reader-text" for="<?php echo esc_attr( $fid ); ?>-ui">Tipo de control de <?php echo esc_html( $name ); ?></label>
							<select id="<?php echo esc_attr( $fid ); ?>-ui" name="facet[<?php echo esc_attr( $name ); ?>][ui]" class="akb-facet-ui">
								<?php foreach ( SearchAdminSection::facet_ui_choices( $def ) as $ui_value => $ui_label ) : ?>
									<option value="<?php echo esc_attr( $ui_value ); ?>"<?php selected( (string) ( $def['ui'] ?? 'checkboxes' ), $ui_value ); ?>><?php echo esc_html( $ui_label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
						<?php if ( $is_taxonomy ) : ?>
							<?php
							$parent       = (int) ( $def['parent_term'] ?? 0 );
							$parent_value = '';
							$hint         = '';
							if ( $parent > 0 ) {
								$parent_term_obj = get_term( $parent, (string) ( $def['taxonomy'] ?? '' ) );
								if ( $parent_term_obj instanceof \WP_Term ) {
									$parent_value = $parent_term_obj->name;
									$hint         = '<span class="description">' . esc_html( $parent_term_obj->slug . ' · id ' . $parent ) . '</span>';
								} else {
									$parent_value = (string) $parent;
									$hint         = '<span class="description" style="color:#b32d2e">término inexistente</span>';
								}
							}
							?>
							<td data-label="Contexto (padre)">
								<label class="screen-reader-text" for="<?php echo esc_attr( $fid ); ?>-parent">Contexto (término padre) de <?php echo esc_html( $name ); ?></label>
								<input type="text" list="akb-parents-<?php echo esc_attr( (string) ( $def['taxonomy'] ?? '' ) ); ?>" id="<?php echo esc_attr( $fid ); ?>-parent" name="facet[<?php echo esc_attr( $name ); ?>][parent_term]" value="<?php echo esc_attr( $parent_value ); ?>" style="width:140px" autocomplete="off" placeholder="nombre o ID">
								<?php echo $hint; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							</td>
						<?php else : ?>
							<td data-label="Contexto (padre)"><span class="description">—</span></td>
						<?php endif; ?>
						<td data-label="Visible" style="text-align:center">
							<label class="screen-reader-text" for="<?php echo esc_attr( $fid ); ?>-visible">Mostrar <?php echo esc_html( $name ); ?></label>
							<input type="checkbox" id="<?php echo esc_attr( $fid ); ?>-visible" name="facet[<?php echo esc_attr( $name ); ?>][visible]" value="1"<?php checked( $visible, true ); ?>>
						</td>
						<?php if ( $is_seed ) : ?>
							<td data-label="Quitar"><span class="description" title="Faceta base: se puede ocultar, no eliminar">—</span></td>
						<?php else : ?>
							<td data-label="Quitar">
								<button type="button" class="button-link-delete button-link" data-akb-remove-facet aria-label="<?php echo esc_attr( 'Quitar la faceta ' . $label_txt ); ?>">Quitar</button>
							</td>
						<?php endif; ?>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>

	<?php if ( ! empty( $capped ) ) : ?>
		<p class="description">Algunas listas de contexto muestran solo los primeros 100 términos con hijos; si el que buscas no aparece, escribe su nombre exacto o su ID.</p>
	<?php endif; ?>

	<p class="description"><strong>Contexto (padre):</strong> el término cuyos HIJOS lista la faceta (Demografía = hijos de Manga, Sello = hijos de Cómics). En el archive de ese contexto la faceta aparece; fuera de él se oculta sola. Escribe el nombre exacto del término (el campo sugiere candidatos) o su ID numérico. <strong>Cambiarlo requiere reconstruir el índice</strong> (pestaña Índice).</p>

	<fieldset class="akb-facets-new" style="margin-top:20px;padding:12px 16px;border:1px solid #c3c4c7;max-width:840px">
		<legend><strong>Agregar faceta nueva</strong></legend>
		<div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
			<p style="margin:0">
				<label for="akb-nf-name">Nombre (slug del parámetro)</label><br>
				<input type="text" id="akb-nf-name" name="new_facet[name]" class="regular-text" style="width:180px" placeholder="p.ej. formato" maxlength="<?php echo (int) FacetRegistry::MAX_NAME_LENGTH; ?>">
			</p>
			<p style="margin:0">
				<label for="akb-nf-label">Etiqueta visible</label><br>
				<input type="text" id="akb-nf-label" name="new_facet[label]" style="width:180px" placeholder="p.ej. Formato">
			</p>
			<p style="margin:0">
				<label for="akb-nf-tax">Taxonomía</label><br>
				<select id="akb-nf-tax" name="new_facet[taxonomy]">
					<option value="">— elegir —</option>
					<?php foreach ( $eligible_taxes as $tax_obj ) : ?>
						<option value="<?php echo esc_attr( $tax_obj->name ); ?>"><?php echo esc_html( $tax_obj->label ); ?> (<?php echo esc_html( $tax_obj->name ); ?>)</option>
					<?php endforeach; ?>
				</select>
			</p>
			<p style="margin:0">
				<label for="akb-nf-ui">UI</label><br>
				<select id="akb-nf-ui" name="new_facet[ui]">
					<option value="checkboxes">Casillas (combinables)</option>
					<option value="radio">Radio (excluyente)</option>
					<option value="alpha">Lista con buscador (muchos términos)</option>
				</select>
			</p>
			<p style="margin:0">
				<label for="akb-nf-parent">Contexto padre (opcional)</label><br>
				<input type="text" id="akb-nf-parent" name="new_facet[parent_term]" data-akb-parent-picker style="width:160px" placeholder="nombre o ID" autocomplete="off">
			</p>
		</div>
		<p class="description" style="margin-top:8px">La faceta nueva necesita <strong>reconstruir el índice</strong> para tener datos (pestaña Índice). Hasta entonces no se muestra en la tienda.</p>
	</fieldset>

	<?php submit_button( 'Guardar facetas' ); ?>
</form>
