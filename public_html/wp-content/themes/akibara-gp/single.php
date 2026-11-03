<?php
/**
 * Single post template — `/<slug>/` para entradas del blog.
 *
 * Por qué existe: el default de GeneratePress sobre el dark theme rompía
 * la legibilidad (título casi invisible, fechas duplicadas, sin imagen
 * destacada). Este template usa los tokens del design system y agrega
 * un hero con featured image + meta + content + back link.
 *
 * @package akibara-gp
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

get_header();

while ( have_posts() ) :
	the_post();
	$akb_thumb_id = get_post_thumbnail_id();

	// La serie de la que trata la guía (UC-12 = UM-17, inc/guide-series.php):
	// tarjeta antes del primer H2 y CTA final a sus tomos. Sin serie ligada, la
	// entrada queda como estaba: sin tarjeta y con el CTA a la tienda.
	$akb_copy        = akibara_gp_guide_copy();
	$akb_serie_datos = akibara_gp_guide_series_data( (int) get_the_ID() );
	$akb_serie_card  = null !== $akb_serie_datos
		? akibara_gp_render_guide_series_card( $akb_serie_datos, $akb_copy['kicker'], $akb_copy['boton'] )
		: '';
	$akb_blog_url = get_post_type_archive_link( 'post' );
	if ( ! $akb_blog_url ) {
		$akb_page_for_posts = (int) get_option( 'page_for_posts' );
		$akb_blog_url       = $akb_page_for_posts ? get_permalink( $akb_page_for_posts ) : home_url( '/blog/' );
	}
	?>
	<main id="main" class="site-main akb-post">

		<article <?php post_class( 'akb-post__article' ); ?>>

			<header class="akb-post__hero">
				<nav class="akb-post__crumb" aria-label="Ruta de navegación">
					<a href="<?php echo esc_url( home_url( '/' ) ); ?>">Inicio</a>
					<span aria-hidden="true">/</span>
					<a href="<?php echo esc_url( $akb_blog_url ); ?>">Blog</a>
					<?php
					$akb_cats = get_the_category();
					if ( $akb_cats ) {
						foreach ( $akb_cats as $akb_cat ) {
							if ( 1 === (int) $akb_cat->term_id ) {
								continue;
							}
							echo '<span aria-hidden="true">/</span>';
							echo '<a href="' . esc_url( get_category_link( $akb_cat ) ) . '">' . esc_html( $akb_cat->name ) . '</a>';
							break;
						}
					}
					?>
				</nav>

				<h1 class="akb-post__title"><?php the_title(); ?></h1>

				<div class="akb-post__meta">
					<?php
					/*
					 * Visualización de fecha de actualización editorial: cuando la fecha
					 * modificada difiere por día de la fecha de publicación original,
					 * se informa explícitamente la fecha de actualización para coherencia
					 * con el schema dateModified y transparencia de vigencia ante el lector.
					 */
					$akb_published = get_the_date( 'Y-m-d' );
					$akb_modified  = get_the_modified_date( 'Y-m-d' );
					if ( $akb_modified > $akb_published ) :
						?>
						<time datetime="<?php echo esc_attr( get_the_modified_date( 'c' ) ); ?>">Actualizado el <?php echo esc_html( get_the_modified_date() ); ?></time>
					<?php else : ?>
						<time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>"><?php echo esc_html( get_the_date() ); ?></time>
					<?php endif; ?>
					<?php if ( get_the_author() ) : ?>
						<span class="akb-post__author">por <?php the_author(); ?></span>
					<?php endif; ?>
				</div>
			</header>

			<?php if ( $akb_thumb_id ) : ?>
				<figure class="akb-post__media">
					<?php
					echo wp_get_attachment_image(
						$akb_thumb_id,
						'large',
						false,
						array(
							'loading'       => 'eager',
							'fetchpriority' => 'high',
							'decoding'      => 'async',
							'sizes'         => akibara_blog_post_image_sizes(),
							'alt'           => akibara_blog_image_alt( $akb_thumb_id, get_the_title() ),
							'class'         => 'akb-post__img',
						)
					);
					?>
				</figure>
			<?php endif; ?>

			<div class="akb-post__content">
				<?php
				// Lo mismo que `the_content()` —el filtro y el escape de `]]>`—, con la
				// tarjeta de la serie intercalada. No es un filtro de `the_content`
				// porque ése también corre fuera de esta plantilla (extractos,
				// descripciones de SEO, feeds) y la tarjeta sólo vale aquí.
				$akb_content = str_replace( ']]>', ']]&gt;', (string) apply_filters( 'the_content', get_the_content() ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- filtro de core: lo mismo que hace the_content().
				echo akibara_gp_insert_guide_series_card( $akb_content, $akb_serie_card ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- contenido filtrado por the_content, igual que the_content(); la tarjeta se escapa al construirla.
				?>
			</div>

			<aside class="akb-post__cta">
				<p class="akb-post__cta-text"><?php echo esc_html( $akb_copy['texto'] ); ?></p>
				<?php if ( null !== $akb_serie_datos ) : ?>
					<a href="<?php echo esc_url( $akb_serie_datos['url'] ); ?>" class="aki-btn"><?php echo akibara_gp_guide_series_copy( $akb_copy['serie'], $akb_serie_datos['name'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapado dentro del helper. ?></a>
				<?php else : ?>
					<a href="<?php echo esc_url( home_url( '/tienda/' ) ); ?>" class="aki-btn"><?php echo esc_html( $akb_copy['tienda'] ); ?></a>
				<?php endif; ?>
			</aside>

			<?php
			$akb_tags = get_the_tags();
			if ( $akb_tags ) :
				?>
				<div class="akb-post__tags" aria-label="Etiquetas">
					<?php foreach ( $akb_tags as $akb_tag ) : ?>
						<a href="<?php echo esc_url( get_tag_link( $akb_tag ) ); ?>" class="akb-post__tag">#<?php echo esc_html( $akb_tag->name ); ?></a>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<footer class="akb-post__footer">
				<a href="<?php echo esc_url( $akb_blog_url ); ?>" class="akb-post__back">← Volver al blog</a>
			</footer>

		</article>

		<nav class="akb-post__siblings" aria-label="Navegación entre entradas">
			<?php
			$akb_prev = get_previous_post();
			$akb_next = get_next_post();
			?>
			<?php if ( $akb_prev ) : ?>
				<a class="akb-post__sibling akb-post__sibling--prev" href="<?php echo esc_url( get_permalink( $akb_prev ) ); ?>">
					<span class="akb-post__sibling-label">← Anterior</span>
					<span class="akb-post__sibling-title"><?php echo esc_html( get_the_title( $akb_prev ) ); ?></span>
				</a>
			<?php endif; ?>
			<?php if ( $akb_next ) : ?>
				<a class="akb-post__sibling akb-post__sibling--next" href="<?php echo esc_url( get_permalink( $akb_next ) ); ?>">
					<span class="akb-post__sibling-label">Siguiente →</span>
					<span class="akb-post__sibling-title"><?php echo esc_html( get_the_title( $akb_next ) ); ?></span>
				</a>
			<?php endif; ?>
		</nav>

		<?php if ( comments_open() || get_comments_number() ) : ?>
			<div class="akb-post__comments">
				<?php comments_template(); ?>
			</div>
		<?php endif; ?>

	</main>
	<?php
endwhile;

get_footer();
