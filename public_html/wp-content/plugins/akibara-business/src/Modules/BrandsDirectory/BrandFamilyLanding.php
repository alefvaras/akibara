<?php
/**
 * BrandFamilyLanding — las tarjetas de edición de la landing de una familia
 * de editorial (`/panini/`, `/ivrea/`), por shortcode.
 *
 * La landing era una página de prosa: dos títulos, dos frases con enlaces a
 * series escritos a mano y dos botones. Un lector no entendía al primer
 * vistazo cuál es cuál ni en qué se diferencian. Ahora cada edición es una
 * tarjeta: logo, país, nº de títulos, los hechos que escribió el dueño
 * (idioma, formato, numeración y precio), las series con más tomos —con su
 * portada— y el botón a su archivo. Debajo, la comparación lado a lado.
 *
 * La prosa (respuesta rápida, «¿cuál elegir?») sigue en el editor de la
 * página: el shortcode sólo pinta lo que sale de datos. El schema de la
 * landing (BrandLandingSchema) no cambia: describe las ediciones, que es lo
 * que se ve.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\BrandsDirectory;

use Akibara\Business\Utilities\PluginAssetVersion;
use WP_Post;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.114.18
 */
final class BrandFamilyLanding {

	public const SHORTCODE = 'akibara_brand_family';

	public function register(): void {
		add_shortcode( self::SHORTCODE, array( $this, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'handle_enqueue_assets' ) );
		add_filter( 'litespeed_ucss_whitelist', array( $this, 'allowlist_ucss_selectors' ), 20 );
	}

	/**
	 * El estilo sólo en las landings de familia.
	 *
	 * @internal
	 */
	public function handle_enqueue_assets(): void {
		if ( null === $this->current_landing() ) {
			return;
		}
		$this->enqueue_style();
	}

	/**
	 * Idempotente: también la llama render(), para que `[akibara_brand_family
	 * key="…"]` en cualquier otra página lleve su hoja (WordPress la imprime
	 * en el pie si ya pasó wp_head).
	 *
	 * @return void
	 */
	private function enqueue_style(): void {
		wp_enqueue_style(
			'akibara-brand-family',
			AKIBARA_BUSINESS_URL . 'assets/css/brand-family.css',
			array(),
			PluginAssetVersion::for_path( 'assets/css/brand-family.css' )
		);
	}

	/**
	 * @param mixed $selectors Lista acumulada.
	 * @return array<int, string>
	 */
	public function allowlist_ucss_selectors( $selectors ): array {
		$selectors = is_array( $selectors ) ? $selectors : array();
		foreach ( array( '.akb-family', '.akb-family__answer', '.akb-family__grid', '.akb-family__compare', '.akb-family__table', '.akb-edition-card', '.akb-edition-card--argentina', '.akb-edition-card--espana', '.akb-edition-card__head', '.akb-edition-card__logo', '.akb-edition-card__id', '.akb-edition-card__kicker', '.akb-edition-card__badge', '.akb-edition-card__title', '.akb-edition-card__count', '.akb-edition-card__facts', '.akb-edition-card__covers', '.akb-edition-card__cover--text', '.akb-edition-card__cta' ) as $sel ) {
			$selectors[] = $sel;
		}
		return array_values( array_unique( $selectors ) );
	}

	/**
	 * `[akibara_brand_family key="panini"]`; sin `key`, la landing de la página actual.
	 *
	 * @param mixed $atts Atributos del shortcode.
	 * @return string
	 */
	public function render( $atts ): string {
		$atts = shortcode_atts( array( 'key' => '' ), is_array( $atts ) ? $atts : array(), self::SHORTCODE );
		$key  = sanitize_key( (string) $atts['key'] );

		$landing = '' !== $key ? ( BrandLandings::all()[ $key ] ?? null ) : $this->current_landing();
		if ( null === $landing ) {
			return '';
		}
		if ( '' !== $key ) {
			$landing['key'] = $key;
		}

		$editions = array();
		foreach ( (array) ( $landing['brands'] ?? array() ) as $brand_slug ) {
			$edition = $this->edition( (string) $brand_slug );
			if ( null !== $edition ) {
				$editions[] = $edition;
			}
		}
		if ( array() === $editions ) {
			return '';
		}
		$this->enqueue_style();

		ob_start();
		?>
		<div class="akb-family">
			<div class="akb-family__grid">
				<?php foreach ( $editions as $edition ) : ?>
					<?php $this->render_card( $edition ); ?>
				<?php endforeach; ?>
			</div>
			<?php $this->render_compare( $editions ); ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Una tarjeta de edición.
	 *
	 * @param array<string, mixed> $e Edición resuelta.
	 * @return void
	 */
	private function render_card( array $e ): void {
		$id = 'akb-edition-' . $e['slug'];
		?>
		<article class="akb-edition-card akb-edition-card--<?php echo esc_attr( $e['region_code'] ); ?>" aria-labelledby="<?php echo esc_attr( $id ); ?>">
			<header class="akb-edition-card__head">
				<?php if ( '' !== $e['logo'] ) : ?>
					<span class="akb-edition-card__logo"><img src="<?php echo esc_url( $e['logo'] ); ?>" alt="" width="<?php echo (int) $e['logo_w']; ?>" height="<?php echo (int) $e['logo_h']; ?>" loading="lazy" decoding="async"></span>
				<?php endif; ?>
				<div class="akb-edition-card__id">
					<p class="akb-edition-card__kicker"><?php if ( '' !== $e['badge'] ) : ?><span class="akb-edition-card__badge" aria-hidden="true"><?php echo esc_html( $e['badge'] ); ?></span> <?php endif; ?><?php echo esc_html( 'Edición ' . $e['region'] ); ?></p>
					<h2 class="akb-edition-card__title" id="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $e['name'] ); ?></h2>
					<p class="akb-edition-card__count"><?php echo esc_html( EditionCopy::count( (int) $e['count'] ) ); ?><?php if ( $e['price_from'] > 0 ) : ?> · <?php echo esc_html( EditionCopy::text( EditionCopy::OPTION_PRICE_FROM, $this->price( $e['price_from'] ) ) ); ?><?php endif; ?></p>
				</div>
			</header>
			<?php if ( array() !== $e['facts'] ) : ?>
				<dl class="akb-edition-card__facts">
					<?php foreach ( $e['facts'] as $label => $value ) : ?>
						<div><dt><?php echo esc_html( $label ); ?></dt><dd><?php echo esc_html( $value ); ?></dd></div>
					<?php endforeach; ?>
				</dl>
			<?php endif; ?>
			<?php if ( array() !== $e['series'] ) : ?>
				<ul class="akb-edition-card__covers" aria-label="<?php echo esc_attr( 'Series destacadas de ' . $e['name'] ); ?>">
					<?php foreach ( $e['series'] as $serie ) : ?>
						<li><a href="<?php echo esc_url( $serie['url'] ); ?>"<?php echo '' === $serie['cover'] ? ' class="akb-edition-card__cover--text"' : ''; ?>><?php if ( '' !== $serie['cover'] ) : ?><img src="<?php echo esc_url( $serie['cover'] ); ?>" alt="<?php echo esc_attr( $serie['name'] ); ?>" width="160" height="240" loading="lazy" decoding="async"><?php else : ?><?php echo esc_html( $serie['name'] ); ?><?php endif; ?></a></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<a class="akb-edition-card__cta wp-block-button__link wp-element-button" href="<?php echo esc_url( $e['url'] ); ?>"><?php echo esc_html( EditionCopy::text( EditionCopy::OPTION_CARD_CTA, $e['name'] ) ); ?> <span aria-hidden="true">→</span></a>
		</article>
		<?php
	}

	/**
	 * La comparación lado a lado. El título sale siempre (es el ancla del
	 * párrafo de cierre que sigue en el editor); la tabla, sólo cuando alguna
	 * edición tiene hechos escritos.
	 *
	 * @param array<int, array<string, mixed>> $editions Ediciones.
	 * @return void
	 */
	private function render_compare( array $editions ): void {
		$labels = array();
		foreach ( $editions as $e ) {
			foreach ( array_keys( $e['facts'] ) as $label ) {
				$labels[ $label ] = true;
			}
		}
		?>
		<section class="akb-family__compare" aria-labelledby="akb-family-compare-title">
			<h2 id="akb-family-compare-title">¿Cuál edición elegir?</h2>
			<?php if ( array() !== $labels ) : ?>
				<table class="akb-family__table">
					<thead><tr><th scope="col"><span class="screen-reader-text">Criterio</span></th><?php foreach ( $editions as $e ) : ?><th scope="col"><?php echo esc_html( $e['name'] ); ?></th><?php endforeach; ?></tr></thead>
					<tbody>
						<?php foreach ( array_keys( $labels ) as $label ) : ?>
							<tr><th scope="row"><?php echo esc_html( $label ); ?></th><?php foreach ( $editions as $e ) : ?><td><?php echo esc_html( (string) ( $e['facts'][ $label ] ?? '—' ) ); ?></td><?php endforeach; ?></tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Una edición con todo lo que la tarjeta necesita, o null si el término no existe.
	 *
	 * @param string $slug Slug de `product_brand`.
	 * @return array<string, mixed>|null
	 */
	private function edition( string $slug ): ?array {
		$term = get_term_by( 'slug', $slug, Shortcode::TAXONOMY );
		if ( ! $term instanceof WP_Term ) {
			return null;
		}
		$url = get_term_link( $term );
		if ( ! is_string( $url ) || '' === $url ) {
			return null;
		}
		$region_code = BrandRegion::for_term( $term );
		$region      = BrandRegion::region( $region_code );
		$logo        = $this->logo( $term );

		return array(
			'slug'        => (string) $term->slug,
			'name'        => (string) $term->name,
			'url'         => $url,
			'count'       => (int) $term->count,
			'region_code' => $region_code,
			'region'      => (string) ( $region['label'] ?? $term->name ),
			'badge'       => (string) ( $region['badge_text'] ?? '' ),
			'logo'        => $logo['url'],
			'logo_w'      => $logo['w'],
			'logo_h'      => $logo['h'],
			'facts'       => EditionFacts::facts( (int) $term->term_id ),
			'series'      => EditionFacts::featured_series( $term ),
			'price_from'  => EditionFacts::price_from( $term ),
		);
	}

	/**
	 * El logo por la misma fuente que el hero y el directorio (helper del
	 * tema); sin tema, la imagen del término.
	 *
	 * @param WP_Term $term Edición.
	 * @return array{url:string,w:int,h:int}
	 */
	private function logo( WP_Term $term ): array {
		$thumbnail_id = (int) get_term_meta( (int) $term->term_id, 'thumbnail_id', true );
		if ( function_exists( 'akibara_gp_brand_logo' ) ) {
			$logo = akibara_gp_brand_logo( (string) $term->slug, $thumbnail_id );
			if ( is_array( $logo ) && '' !== (string) ( $logo['url'] ?? '' ) ) {
				return array( 'url' => (string) $logo['url'], 'w' => (int) ( $logo['w'] ?? 200 ), 'h' => (int) ( $logo['h'] ?? 100 ) );
			}
		}
		if ( $thumbnail_id > 0 ) {
			$src = wp_get_attachment_image_src( $thumbnail_id, 'full' );
			if ( is_array( $src ) && ! empty( $src[0] ) ) {
				return array( 'url' => (string) $src[0], 'w' => (int) ( $src[1] ?? 200 ) ?: 200, 'h' => (int) ( $src[2] ?? 100 ) ?: 100 );
			}
		}
		return array( 'url' => '', 'w' => 0, 'h' => 0 );
	}

	/**
	 * Precio en CLP como lo escribe WooCommerce, sin HTML.
	 *
	 * @param float $price Precio.
	 * @return string
	 */
	private function price( float $price ): string {
		if ( function_exists( 'wc_price' ) ) {
			return trim( wp_strip_all_tags( html_entity_decode( (string) wc_price( $price ), ENT_QUOTES, 'UTF-8' ) ) );
		}
		return '$' . number_format( $price, 0, ',', '.' );
	}

	/**
	 * La landing de la página que se está mostrando, o null.
	 *
	 * @return array<string, mixed>|null
	 */
	private function current_landing(): ?array {
		if ( is_admin() || ! is_singular( 'page' ) ) {
			return null;
		}
		$post = get_queried_object();
		if ( ! $post instanceof WP_Post ) {
			return null;
		}
		return BrandLandings::for_page_slug( (string) $post->post_name );
	}
}
