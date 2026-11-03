<?php
/**
 * 404 page — Akibara branded.
 *
 * Override del default GeneratePress (template-part('404') = WP minimal).
 * Renderiza:
 *   - SVG inline custom (manga panel + balloon "?") sin copyright issues
 *   - "404" big Bebas Neue centered
 *   - title + subtitle + 2 CTAs (Inicio + Catálogo)
 *   - search input inline
 *   - cross-sell con 4 productos más vendidos (cross-sell mantiene engagement)
 *
 * Cross-sell viene de WC_Query orderby=popularity meta_key=total_sales.
 * Si no hay sales aún, fallback a recientes.
 *
 * Dependencias:
 *   - akibara-business plugin (Services\NavigationLinks para URLs canónicas)
 *   - empty-states.css (enqueued condicionalmente en is_404)
 *
 * @package akibara-gp
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

use Akibara\Business\Services\NavigationLinks;

get_header();

$akb_home_url      = home_url( '/' );
$akb_shop_url      = class_exists( NavigationLinks::class ) ? NavigationLinks::shop_url() : home_url( '/tienda/' );
// Destino unificado con el buscador principal: `/tienda/?_buscar=...`,
// canalizando la búsqueda a través del motor propio con sinónimos, facetas
// y ponderación de relevancia en catálogo.
?>

<main id="main" class="site-main akb-404-main woocommerce">
	<?php /* id="main" = target del skip-link (#main); landmark <main> único
	 * de la página (el wrapper externo #main-content es <div>, ver header.php).
	 * `role="main"` es redundante en <main>, por eso se omite.
	 * class woocommerce sobre <main> habilita los selectores
	 * `.woocommerce ul.products li.product .price` de loop.css que
	 * styliza el cross-sell. Sin esto, precios y botones renderean
	 * con defaults WP (cyan links). */ ?>
	<section class="akb-empty akb-404" aria-labelledby="akb-404-title">
		<div class="akb-empty__hero">
			<div class="akb-404__visual" aria-hidden="true">
				<svg
					class="akb-404__svg"
					viewBox="0 0 220 160"
					xmlns="http://www.w3.org/2000/svg"
					fill="none"
					stroke="currentColor"
					stroke-width="3"
					stroke-linecap="round"
					stroke-linejoin="round">
					<rect x="20" y="30" width="180" height="110" rx="6" />
					<line x1="110" y1="30" x2="110" y2="140" />
					<line x1="40" y1="50" x2="90" y2="50" />
					<line x1="40" y1="62" x2="80" y2="62" />
					<line x1="40" y1="74" x2="86" y2="74" />
					<line x1="130" y1="55" x2="180" y2="55" />
					<line x1="130" y1="67" x2="170" y2="67" />
					<path d="M150 95 q12 -18 24 0 q-2 14 -12 18 l 0 6" />
					<circle cx="162" cy="124" r="2" fill="currentColor" />
					<path d="M40 110 q15 -8 30 0 t30 0" stroke-dasharray="3 4" />
				</svg>
			</div>

			<div class="akb-404__code" aria-hidden="true">404</div>

			<h1 id="akb-404-title" class="akb-empty__title">¡TE HAS PERDIDO EN EL MAPA!</h1>

			<p class="akb-empty__subtitle">
				Esta zona del mapa no existe o ha sido corrompida.<br>Regresa a la base antes de que los enemigos te alcancen.
			</p>

			<div class="akb-empty__cta-row">
				<a class="akb-empty__btn akb-empty__btn--primary" href="<?php echo esc_url( $akb_home_url ); ?>">
					Volver al inicio
				</a>
				<a class="akb-empty__btn akb-empty__btn--secondary" href="<?php echo esc_url( $akb_shop_url ); ?>">
					Ver catálogo
				</a>
			</div>

			<form class="akb-empty__search" role="search" method="get" action="<?php echo esc_url( $akb_shop_url ); ?>">
				<label for="akb-404-search" class="screen-reader-text">Buscar productos</label>
				<input type="search" id="akb-404-search" class="akb-empty__search-input" name="_buscar" placeholder="Buscar un tomo o serie…" />
				<button type="submit" class="akb-empty__search-btn">Buscar</button>
			</form>
		</div>

		<?php
		// Cross-sell "más vendidos": parte compartida por los empty-states.
		get_template_part(
			'template-parts/empty/cross-sell',
			null,
			array(
				'heading_id'   => 'akb-404-popular-title',
				'heading_tag'  => 'h2',
				'heading_text' => 'Más vendidos',
			)
		);
		?>
	</section>
</main>

<?php
get_footer();
