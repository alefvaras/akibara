<?php
/**
 * Blog index template — `/blog/` (page_for_posts).
 *
 * Renderiza hero + grid de blog cards con estilos del design system
 * (.akb-page__hero + .akb-blog-*). El theme base (GeneratePress) servía
 * un layout genérico que se veía mal sobre el dark theme: títulos casi
 * invisibles (gray oscuro), fechas duplicadas, sin featured image,
 * spacing roto. Este template lo reemplaza.
 *
 * @package akibara-gp
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

get_header();
?>

<main id="main" class="site-main akb-blog">

	<header class="akb-page__hero akb-blog__hero">
		<h1>Blog</h1>
		<p>Guías, reseñas y novedades del mundo manga y cómics.</p>
	</header>

	<div class="akb-blog__wrap">
		<?php if ( have_posts() ) : ?>
			<div class="akb-blog__grid">
				<?php
				$akb_card_index = 0;
				while ( have_posts() ) :
					the_post();
					$akb_thumb_id = get_post_thumbnail_id();
					$akb_loading  = 0 === $akb_card_index ? 'eager' : 'lazy';
					?>
					<article <?php post_class( 'akb-blog-card' ); ?>>
						<?php if ( $akb_thumb_id ) : ?>
							<a class="akb-blog-card__media" href="<?php the_permalink(); ?>" aria-label="<?php echo esc_attr( get_the_title() ); ?>">
								<?php
								echo wp_get_attachment_image(
									$akb_thumb_id,
									'medium_large',
									false,
									array(
										'loading'  => $akb_loading,
										'decoding' => 'async',
										'sizes'    => akibara_blog_card_image_sizes(),
										'alt'      => get_the_title(),
										'class'    => 'akb-blog-card__img',
									)
								);
								?>
							</a>
						<?php else : ?>
							<a class="akb-blog-card__media akb-blog-card__media--placeholder" href="<?php the_permalink(); ?>" aria-hidden="true" tabindex="-1">
								<span aria-hidden="true">📚</span>
							</a>
						<?php endif; ?>

						<div class="akb-blog-card__body">
							<div class="akb-blog-card__meta">
								<time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>"><?php echo esc_html( get_the_date() ); ?></time>
								<?php
								$akb_cats = get_the_category();
								if ( $akb_cats ) {
									foreach ( $akb_cats as $akb_cat ) {
										if ( 1 === (int) $akb_cat->term_id ) {
											continue; // Skip "Uncategorized".
										}
										echo '<span class="akb-blog-card__cat">' . esc_html( $akb_cat->name ) . '</span>';
										break;
									}
								}
								?>
							</div>
							<h2 class="akb-blog-card__title">
								<a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
							</h2>
							<?php
							$akb_excerpt = get_the_excerpt();
							if ( $akb_excerpt ) :
								?>
								<p class="akb-blog-card__excerpt"><?php echo esc_html( wp_trim_words( $akb_excerpt, 22 ) ); ?></p>
							<?php endif; ?>
							<a href="<?php the_permalink(); ?>" class="akb-blog-card__cta" aria-label="<?php echo esc_attr( sprintf( 'Leer más sobre %s', get_the_title() ) ); ?>">Leer más &rarr;</a>
						</div>
					</article>
					<?php
					++$akb_card_index;
				endwhile;
				?>
			</div>

			<nav class="akb-blog__nav" aria-label="Paginación del blog">
				<?php
				the_posts_pagination(
					array(
						'mid_size'  => 1,
						'prev_text' => '← Anteriores',
						'next_text' => 'Siguientes →',
					)
				);
				?>
			</nav>
		<?php else : ?>
			<p class="akb-blog__empty">Aún no hay entradas publicadas.</p>
		<?php endif; ?>
	</div>

</main>

<?php
get_footer();
