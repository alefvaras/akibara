<?php
/**
 * Template — directorio de editoriales agrupado por región.
 *
 * Renderizado por el shortcode `[akibara_brands_grid]` (akibara-business
 * plugin, módulo BrandsDirectory). Recibe en scope:
 *   - $grouped           : array<string region_code, list<WP_Term>>
 *   - $atts              : array<string, string> shortcode attributes
 *   - $regions_meta      : array<region_code, [label, count]> para chips
 *
 * Variables provistas también desde el caller:
 *   - BrandRegion::label() para etiquetar regiones
 *
 * Markup tokenizado (.akb-brands-*) — CSS en assets/css/brands.css.
 * El filtro (assets/js/akibara-brands-filter.js) lee las tarjetas del DOM:
 * cada `.akb-brand-card` ya trae slug, región y nombre normalizado en
 * `data-akb-brand-*`. Aquí se publica sólo el TOTAL.
 *
 * @package akibara-gp
 */

defined( 'ABSPATH' ) || exit;

use Akibara\Business\Modules\BrandsDirectory\BrandRegion;

if ( empty( $grouped ) || ! is_array( $grouped ) ) {
	return;
}

if ( function_exists( 'get_template_part' ) ) {
	get_template_part( 'template-parts/brands/sprite' );
}

/*
 * El total sale de `$grouped`, que es lo que esta plantilla pinta.
 *
 * Antes se contaba `$searchable_brands` —la lista plana que el plugin arma
 * aplanando `$grouped`— y además se publicaba entera como JSON en
 * `data-akb-brands`: 2.233 B por render de /editoriales/ que ningún JS parseaba
 * nunca (el filtro sólo usaba el atributo como selector de presencia). Contar
 * aquí desacopla la plantilla de esa lista, que el plugin va a retirar.
 */
$akb_total_brands  = 0;
$akb_brand_term_ids = array();
foreach ( $grouped as $akb_region_brands ) {
	if ( ! is_array( $akb_region_brands ) ) {
		continue;
	}
	$akb_total_brands += count( $akb_region_brands );
	foreach ( $akb_region_brands as $akb_brand_term ) {
		if ( $akb_brand_term instanceof WP_Term && (int) $akb_brand_term->term_id > 0 ) {
			$akb_brand_term_ids[] = (int) $akb_brand_term->term_id;
		}
	}
}
if ( array() !== $akb_brand_term_ids && function_exists( 'update_termmeta_cache' ) ) {
	update_termmeta_cache( $akb_brand_term_ids );
}
unset( $akb_region_brands, $akb_brand_term, $akb_brand_term_ids );

$akb_regions_count = isset( $regions_meta ) && is_array( $regions_meta ) ? count( $regions_meta ) : 0;
$akb_show_filter   = $akb_total_brands >= 6; // chips/buscador agregan ruido si hay pocas
$akb_search_id     = wp_unique_id( 'akb-brands-search-' );
$akb_results_id    = wp_unique_id( 'akb-brands-results-' );
$akb_status_id     = wp_unique_id( 'akb-brands-status-' );
?>
<div
	class="akb-brands-directory"
	role="region"
	aria-label="Directorio de editoriales"
	data-akb-total="<?php echo esc_attr( (string) $akb_total_brands ); ?>"
>
	<div class="akb-brands-directory__summary" aria-label="Resumen del directorio">
		<span class="akb-brands-directory__summary-label">Editoriales oficiales</span>
		<span class="akb-brands-directory__summary-value">
			<?php
			// Pluralización independiente para cada contador numérico.
			echo esc_html(
				sprintf(
					'%d %s · %d %s',
					(int) $akb_total_brands,
					1 === (int) $akb_total_brands ? 'editorial' : 'editoriales',
					(int) $akb_regions_count,
					1 === (int) $akb_regions_count ? 'región' : 'regiones'
				)
			);
			?>
		</span>
	</div>

	<?php if ( $akb_show_filter && ! empty( $regions_meta ) ) : ?>
		<div class="akb-brands-filterbar" role="search" aria-label="Filtrar editoriales">
			<label class="akb-brands-filterbar__search" for="<?php echo esc_attr( $akb_search_id ); ?>">
				<span class="akb-brands-filterbar__label">Buscar por nombre</span>
				<span class="akb-brands-filterbar__search-field">
					<svg class="akb-brands-filterbar__search-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
						<circle cx="11" cy="11" r="7"></circle>
						<path d="m20 20-4-4"></path>
					</svg>
					<input
						id="<?php echo esc_attr( $akb_search_id ); ?>"
						type="search"
						class="akb-brands-filterbar__input"
						placeholder="Ej. Ivrea, Panini…"
						autocomplete="off"
						aria-controls="<?php echo esc_attr( $akb_results_id ); ?>"
						aria-describedby="<?php echo esc_attr( $akb_status_id ); ?>"
					>
				</span>
			</label>

			<fieldset class="akb-brands-filterbar__filters">
				<legend class="akb-brands-filterbar__label">Filtrar por origen</legend>
				<div class="akb-brands-filterbar__chips">
					<button
						type="button"
						class="akb-brands-chip akb-brands-chip--active"
						aria-pressed="true"
						aria-controls="<?php echo esc_attr( $akb_results_id ); ?>"
						data-akb-region="all"
					><span class="akb-brands-chip__label">Todas</span> <span class="akb-brands-chip__count"><?php echo esc_html( (string) $akb_total_brands ); ?></span></button>

					<?php foreach ( $regions_meta as $akb_region_code => $akb_meta ) : ?>
						<button
							type="button"
							class="akb-brands-chip"
							aria-pressed="false"
							aria-controls="<?php echo esc_attr( $akb_results_id ); ?>"
							data-akb-region="<?php echo esc_attr( $akb_region_code ); ?>"
						><span class="akb-brands-chip__label"><?php
							$akb_short_label = str_ireplace( 'editoriales ', '', $akb_meta['label'] );
							echo esc_html( ucfirst( $akb_short_label ) );
						?></span> <span class="akb-brands-chip__count"><?php echo esc_html( (string) $akb_meta['count'] ); ?></span></button>
					<?php endforeach; ?>
				</div>
			</fieldset>

			<div
				class="akb-brands-filterbar__status"
				id="<?php echo esc_attr( $akb_status_id ); ?>"
				aria-live="polite"
				role="status"
			>
				<span class="akb-brands-filterbar__status-text" data-akb-status-text>Busca por editorial o filtra por región.</span>
				<button
					type="button"
					class="akb-brands-filterbar__clear"
					data-akb-clear-filters
					hidden
				>Limpiar filtros</button>
			</div>
		</div>
	<?php endif; ?>

	<div class="akb-brands-directory__sections" id="<?php echo esc_attr( $akb_results_id ); ?>">
		<?php foreach ( $grouped as $akb_region => $akb_brands ) : ?>
			<?php if ( empty( $akb_brands ) ) { continue; } ?>
			<section class="akb-brands-section" data-akb-region="<?php echo esc_attr( $akb_region ); ?>">
				<h2 class="akb-brands-section__title">
					<span class="akb-brands-section__accent" aria-hidden="true"></span>
					<?php echo esc_html( BrandRegion::label( $akb_region ) ); ?>
				</h2>

				<ul class="akb-brands-grid">
					<?php foreach ( $akb_brands as $akb_brand ) :
						$akb_link    = get_term_link( $akb_brand );
						$akb_count   = (int) $akb_brand->count;
						$akb_desc    = trim( wp_strip_all_tags( (string) $akb_brand->description ) );
						// Opción C: el directorio muestra el nombre COMPLETO del término
						// (con país). Las marcas bi-regionales (Panini AR/ES, Ivrea AR/ES)
						// comparten logo, así que el país en el nombre distingue las dos
						// tarjetas; las mono-regionales (Milky Way, Arechi, Planeta Cómic)
						// no llevan país en su término, así que se muestran tal cual. El
						// header de sección ("EDITORIALES …") refuerza la región sin
						// duplicarla. (El carrusel del home sí stripea país + usa badge.)
						$akb_display    = (string) $akb_brand->name;
						$akb_initials   = strtoupper( mb_substr( $akb_display, 0, 1 ) );
						$akb_matrix_key = function_exists( 'akibara_gp_publisher_matrix_key' )
							? akibara_gp_publisher_matrix_key( (string) $akb_brand->slug )
							: '';
						$akb_logo       = ( '' === $akb_matrix_key && function_exists( 'akibara_gp_brand_logo' ) )
							? akibara_gp_brand_logo(
								(string) $akb_brand->slug,
								(int) get_term_meta( $akb_brand->term_id, 'thumbnail_id', true )
							)
							: array( 'url' => '', 'w' => 0, 'h' => 0 );
					?>
						<li
							class="akb-brand-card"
							data-akb-brand-slug="<?php echo esc_attr( $akb_brand->slug ); ?>"
							data-akb-brand-region="<?php echo esc_attr( $akb_region ); ?>"
							data-akb-brand-name="<?php echo esc_attr( mb_strtolower( $akb_brand->name ) ); ?>"
							>
							<a class="akb-brand-card__link" href="<?php echo esc_url( $akb_link ); ?>">
								<?php if ( '' !== $akb_matrix_key && function_exists( 'akibara_gp_render_publisher_logo' ) ) : ?>
									<span class="akb-brand-card__logo" aria-hidden="true">
										<?php echo akibara_gp_render_publisher_logo( (string) $akb_brand->slug ); ?>
									</span>
								<?php elseif ( '' !== $akb_logo['url'] ) : ?>
									<span class="akb-brand-card__logo" aria-hidden="true">
										<img
											src="<?php echo esc_url( $akb_logo['url'] ); ?>"
											alt=""
											width="<?php echo (int) $akb_logo['w']; ?>"
											height="<?php echo (int) $akb_logo['h']; ?>"
											decoding="async"
											loading="lazy"
										>
									</span>
								<?php else : ?>
									<span class="akb-brand-card__monogram" aria-hidden="true"><?php echo esc_html( $akb_initials ); ?></span>
								<?php endif; ?>
								<span class="akb-brand-card__body">
									<span class="akb-brand-card__name"><?php echo esc_html( $akb_display ); ?></span>
									<?php if ( $akb_desc !== '' ) : ?>
										<span class="akb-brand-card__desc"><?php echo esc_html( wp_trim_words( $akb_desc, 18, '…' ) ); ?></span>
									<?php endif; ?>
									<span class="akb-brand-card__count">
										<?php
										/* translators: %d: número de productos asociados a la editorial. */
										printf( esc_html( _n( '%d producto en catálogo', '%d productos en catálogo', $akb_count, 'akibara-gp' ) ), (int) $akb_count );
										?>
									</span>
								</span>
								<span class="akb-brand-card__arrow" aria-hidden="true">→</span>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			</section>
		<?php endforeach; ?>
	</div>

	<?php if ( $akb_show_filter ) :
		$akb_contact_url = \Akibara\Business\Services\NavigationLinks::contact_url();
	?>
		<p class="akb-brands-empty" hidden>
			No encontramos editoriales que coincidan.
			<a href="<?php echo esc_url( $akb_contact_url ); ?>">¿La pides por contacto?</a>
		</p>
	<?php endif; ?>
</div>
