<?php
/**
 * BrandLandingCallout — el conmutador de ediciones en el archivo de una
 * editorial (`/marca/panini-argentina/`).
 *
 * Quien está en la edición argentina de Panini quiere, en este orden: saltar
 * a la española (¿está mi serie?, ¿a qué precio?), comparar, ver todo. Hasta
 * 1.114.16 el único botón llevaba a la landing de la familia, cuyo primer
 * botón devolvía a donde estaba: dos clics para lo que es uno. Ahora la
 * edición hermana es la acción principal (con conteo, para decidir sin
 * navegar), la actual va marcada con `aria-current`, y la landing queda como
 * enlace terciario «Comparar»: sigue enlazada porque existe por SEO
 * (consolidar «ivrea chile», ver BrandLandings) y este bloque es uno de sus
 * pocos enlaces internos.
 *
 * NO repite el hero: el theme ya pinta el `<h1>` con el nombre de la edición
 * (`akb-archive-hero--brand`), por eso no hay «Estás viendo X».
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\BrandsDirectory;

use Akibara\Business\Utilities\CatalogQueryGrammar;

use Akibara\Business\Utilities\PluginAssetVersion;
use WP_Term;

defined( 'ABSPATH' ) || exit;

final class BrandLandingCallout {

	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'handle_enqueue_assets' ) );
		add_action( 'woocommerce_before_shop_loop', array( $this, 'handle_render' ), 3 );
		add_filter( 'litespeed_ucss_whitelist', array( $this, 'allowlist_ucss_selectors' ), 20 );
	}

	/**
	 * Encola el estilo sólo cuando el bloque realmente se va a imprimir.
	 *
	 * @internal
	 */
	public function handle_enqueue_assets(): void {
		if ( null === $this->resolve_view_model() ) {
			return;
		}

		wp_enqueue_style(
			'akibara-brand-landing',
			AKIBARA_BUSINESS_URL . 'assets/css/brand-landing.css',
			array(),
			PluginAssetVersion::for_path( 'assets/css/brand-landing.css' )
		);
	}

	/**
	 * UCSS de LiteSpeed poda lo que no ve al generar: estas clases sólo
	 * existen en archivos de editorial con dos ediciones.
	 *
	 * @param mixed $selectors Lista acumulada.
	 * @return array<int, string>
	 */
	public function allowlist_ucss_selectors( $selectors ): array {
		$selectors   = is_array( $selectors ) ? $selectors : array();
		$selectors[] = '.akb-edition-switch';
		$selectors[] = '.akb-edition-switch__label';
		$selectors[] = '.akb-edition-switch__list';
		$selectors[] = '.akb-edition-switch__item';
		$selectors[] = '.akb-edition-switch__badge';
		$selectors[] = '.akb-edition-switch__go';
		$selectors[] = '.akb-edition-switch__name';
		$selectors[] = '.akb-edition-switch__count';
		$selectors[] = '.akb-edition-switch__all';
		return array_values( array_unique( $selectors ) );
	}

	/**
	 * Imprime el conmutador antes de los controles y la grilla.
	 *
	 * @internal
	 */
	public function handle_render(): void {
		$view = $this->resolve_view_model();
		if ( null === $view ) {
			return;
		}
		?>
		<nav class="akb-edition-switch" aria-label="<?php echo esc_attr( sprintf( '%s en Akibara', EditionCopy::text( EditionCopy::OPTION_SWITCH_LABEL, $view['label'] ) ) ); ?>">
			<p class="akb-edition-switch__label"><?php echo wp_kses( EditionCopy::text( EditionCopy::OPTION_SWITCH_LABEL, '<strong>' . esc_html( $view['label'] ) . '</strong>' ), array( 'strong' => array() ) ); ?></p>
			<ul class="akb-edition-switch__list">
				<?php foreach ( $view['editions'] as $edition ) : ?>
					<li>
						<a class="akb-edition-switch__item" href="<?php echo esc_url( $edition['url'] ); ?>"<?php echo $edition['is_current'] ? ' aria-current="page"' : ''; ?>>
							<?php if ( '' !== $edition['badge'] ) : ?>
								<span class="akb-edition-switch__badge" aria-hidden="true"><?php echo esc_html( $edition['badge'] ); ?></span>
							<?php endif; ?>
							<span class="akb-edition-switch__name"><?php echo esc_html( $edition['region'] ); ?></span>
							<span class="akb-edition-switch__count"><?php echo esc_html( number_format_i18n( $edition['count'] ) ); ?><span class="screen-reader-text"><?php echo esc_html( 1 === (int) $edition['count'] ? ' producto' : ' productos' ); ?></span></span>
							<?php if ( ! $edition['is_current'] ) : ?>
								<span class="akb-edition-switch__go" aria-hidden="true">→</span>
							<?php endif; ?>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
			<?php if ( '' !== $view['url'] ) : ?>
				<a class="akb-edition-switch__all" href="<?php echo esc_url( $view['url'] ); ?>"><?php echo esc_html( EditionCopy::text( EditionCopy::OPTION_SWITCH_COMPARE ) ); ?> <span aria-hidden="true">→</span></a>
			<?php endif; ?>
		</nav>
		<?php
	}

	/**
	 * ¿La URL trae filtros de catálogo (faceta, orden, precio, búsqueda)?
	 * Los parámetros de campaña (utm_*, fbclid, gclid…) no cuentan: con ellos
	 * el conmutador desaparecía justo para quien llega desde un anuncio.
	 *
	 * @return bool
	 */
	private static function has_filtering_query(): bool {
		// La lista de lo que ES una faceta vive en `CatalogQueryGrammar`, que es
		// la gramática compartida del catálogo. Aquí había una copia propia que
		// enumeraba lo CONTRARIO —los parámetros de campaña conocidos—, y esa
		// lista caduca sola: el día que nazca un `?li_fat_id=` de LinkedIn, este
		// bloque desaparece para quien llegue desde ahí.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- sólo se miran las CLAVES; no se lee ningún valor.
		return CatalogQueryGrammar::has_filter_parameter( (array) $_GET );
	}

	/**
	 * Datos del bloque, o null si este request no es el archivo de una
	 * edición que pertenece a una familia con al menos otra edición.
	 *
	 * Se imprime también en las páginas 2, 3… (antes desaparecía al paginar);
	 * con filtros (`$_GET`) no, para no multiplicar variantes de caché.
	 *
	 * @return array{label:string,url:string,editions:list<array{name:string,region:string,badge:string,url:string,count:int,is_current:bool}>}|null
	 */
	private function resolve_view_model(): ?array {
		if ( is_admin() || ! is_tax( Shortcode::TAXONOMY ) || self::has_filtering_query() ) {
			return null;
		}

		$term = get_queried_object();
		if ( ! $term instanceof WP_Term ) {
			return null;
		}

		$landing = BrandLandings::for_term( $term );
		if ( null === $landing ) {
			return null;
		}

		$editions = array();
		foreach ( (array) ( $landing['brands'] ?? array() ) as $brand_slug ) {
			$edition = get_term_by( 'slug', (string) $brand_slug, Shortcode::TAXONOMY );
			if ( ! $edition instanceof WP_Term ) {
				continue;
			}
			$link = get_term_link( $edition );
			if ( ! is_string( $link ) || '' === $link ) {
				continue;
			}
			$region     = BrandRegion::region( BrandRegion::for_term( $edition ) );
			$editions[] = array(
				'name'       => (string) $edition->name,
				'region'     => (string) ( $region['label'] ?? $edition->name ),
				'badge'      => (string) ( $region['badge_text'] ?? '' ),
				'url'        => $link,
				'count'      => (int) $edition->count,
				'is_current' => (int) $edition->term_id === (int) $term->term_id,
			);
		}

		if ( count( $editions ) < 2 ) {
			return null; // Con una sola edición no hay a dónde cambiar.
		}

		return array(
			'label'    => (string) $landing['callout_label'],
			'url'      => BrandLandings::url( (string) $landing['key'] ),
			'editions' => $editions,
		);
	}
}
