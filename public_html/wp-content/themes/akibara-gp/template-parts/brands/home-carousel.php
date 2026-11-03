<?php
/**
 * Home — Editoriales (grid responsive: 4-col desktop / 3-col tablet / 2-col mobile).
 *
 * Vars expuestas por HomeCarousel::render():
 *   @var list<array{name:string,slug:string,url:string,img:string,img_w:int,img_h:int,count:int,region:string,badge_text:string,badge_color:string,badge_label:string}> $brands
 *   @var string $more_url   URL del directorio completo (/editoriales/ o /tienda/)
 *   @var string $more_label Label del CTA (cambia según destino)
 *
 * Markup principles:
 *   - <ul> con grid 4-col en desktop, 3-col tablet, 2-col mobile (vertical).
 *     Sin JS, sin carrusel/flechas — vitrina capada (HOME_MAX_BRANDS), escaneable.
 *   - <li> simple (sin scroll-snap). El crecimiento del catálogo completo
 *     se canaliza hacia el directorio `/editoriales/` mediante el enlace CTA.
 *   - Badge país: el plugin (BrandRegion) provee badge_text + badge_color via
 *     `$akb_brand`. El template solo aplica `--badge-color` como CSS variable
 *     inline — sin clases por país hardcoded. Añadir país nuevo (ej. México)
 *     no requiere editar este template.
 *
 * @package akibara-gp
 */

defined( 'ABSPATH' ) || exit;

if ( empty( $brands ) || ! is_array( $brands ) ) {
	return;
}

if ( function_exists( 'get_template_part' ) ) {
	get_template_part( 'template-parts/brands/sprite' );
}
?>
<section class="akb-brands-home" aria-labelledby="akb-brands-home-title">
	<div class="akb-brands-home__container">
		<header class="akb-brands-home__header">
			<div class="akb-brands-home__intro">
				<h2 id="akb-brands-home-title" class="akb-brands-home__title">De dónde vienen tus tomos</h2>
				<p class="akb-brands-home__desc">Importamos ediciones oficiales en español desde España y Argentina</p>
			</div>
			<a class="akb-brands-home__more" href="<?php echo esc_url( $more_url ); ?>"><?php echo esc_html( $more_label ); ?></a>
		</header>

		<?php // La ventana recorta y difumina SÓLO la pista del carrusel (brands-home.css); la cabecera queda fuera. ?>
		<div class="akb-brands-home__viewport">
		<ul class="akb-brands-home__track" role="list" aria-label="Editoriales con las que trabajamos">
			<?php foreach ( $brands as $akb_i => $akb_brand ) :
				$akb_badge_text  = (string) ( $akb_brand['badge_text']  ?? '' );
				$akb_badge_color = (string) ( $akb_brand['badge_color'] ?? '' );
				$akb_badge_label = (string) ( $akb_brand['badge_label'] ?? '' );
				$akb_count       = max( 0, (int) ( $akb_brand['count'] ?? 0 ) );
				$akb_slug        = (string) ( $akb_brand['slug'] ?? '' );
				?>
			<li class="akb-brands-home__slide" style="--akb-slide-index: <?php echo (int) $akb_i; ?>;">
				<a
					class="akb-brands-home__item"
					href="<?php echo esc_url( $akb_brand['url'] ); ?>"
					data-brand="<?php echo esc_attr( $akb_slug ); ?>"
				>
					<span class="akb-brands-home__logo" aria-hidden="true">
						<?php
						if ( function_exists( 'akibara_gp_render_publisher_logo' ) ) {
							echo akibara_gp_render_publisher_logo( $akb_slug );
						} elseif ( ! empty( $akb_brand['img'] ) ) {
							printf(
								'<img src="%s" alt="" width="%d" height="%d" decoding="async">',
								esc_url( $akb_brand['img'] ),
								(int) ( $akb_brand['img_w'] ?? 200 ),
								(int) ( $akb_brand['img_h'] ?? 100 )
							);
						}
						?>
					</span>
					<span class="akb-brands-home__name">
						<?php echo esc_html( $akb_brand['name'] ); ?>
						<?php if ( '' !== $akb_badge_text ) : ?>
							<span
								class="akb-brands-home__country"
								<?php if ( '' !== $akb_badge_color ) : ?>style="--akb-badge-color: <?php echo esc_attr( $akb_badge_color ); ?>;"<?php endif; ?>
								aria-label="<?php echo esc_attr( $akb_badge_label ); ?>"
							> • <?php echo esc_html( $akb_badge_text ); ?></span>
						<?php endif; ?>
					</span>
					<span class="akb-brands-home__count">
						<?php
						/* translators: %d: número de productos asociados a la editorial. */
						printf( esc_html( _n( '%d producto en catálogo', '%d productos en catálogo', $akb_count, 'akibara-gp' ) ), (int) $akb_count );
						?>
					</span>
				</a>
			</li>
			<?php endforeach; ?>
		</ul>
		</div>
	</div>
</section>
