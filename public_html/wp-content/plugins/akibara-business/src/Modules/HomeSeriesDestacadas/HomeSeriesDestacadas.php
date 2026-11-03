<?php
/**
 * Vitrina Series más buscadas de la portada. La lista de slugs pa_serie,
 * el título y el interruptor se operan desde Series en la portada.
 * Sin lista no renderiza un bloque vacío ni genera una selección automática.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\HomeSeriesDestacadas;

use Akibara\Business\Modules\SeriesCatalog\SeriesCatalogEligibility;
use Akibara\Business\Services\SeriesCover;
use Akibara\Business\Services\SeriesHubUrl;
use Akibara\Business\Utilities\PluginAssetVersion;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.113.0
 */
final class HomeSeriesDestacadas {

	public const OPTION_ENABLED = 'akibara_home_series_enabled';
	public const OPTION_TITLE   = 'akibara_home_series_title';
	public const OPTION_SLUGS   = 'akibara_home_series_slugs';

	public const DEFAULT_TITLE = 'Series más buscadas';
	public const MAX_SLUGS     = 24;

	/** Cuántas pinta el shortcode si no trae `count`. */
	public const DEFAULT_COUNT = 8;

	/**
	 * Etiqueta de LiteSpeed de toda página que lleve el shortcode: guardar la
	 * vitrina purga sólo esas páginas (hoy, la portada), no el sitio.
	 */
	public const CACHE_TAG = 'akb_home_series';

	/** Etiqueta que LiteSpeed pone sola a la portada (`Tag::TYPE_FRONTPAGE`). */
	private const FRONT_PAGE_TAG = 'F';

	private bool $purged = false;

	private const TRANSIENT_PREFIX = 'akb_home_series_v1_';
	private const CACHE_TTL        = 6 * HOUR_IN_SECONDS;

	public function register(): void {
		add_shortcode( 'akb_series_destacadas', array( $this, 'render' ) );

		// La clave de datos incorpora lista, count y versión de catálogo WooCommerce.
		// Cambiar cualquiera reconstruye la selección sin enumerar transients.
		// La página se invalida mediante CACHE_TAG sólo cuando cambia la configuración
		// de la vitrina, sin purgar todo el catálogo.
		foreach ( self::options() as $option ) {
			add_action( 'add_option_' . $option, array( $this, 'purge_showcase' ), 10, 0 );
			add_action( 'update_option_' . $option, array( $this, 'purge_showcase' ), 10, 0 );
		}
		add_action( 'deleted_option', array( $this, 'purge_on_deleted_option' ) );
	}

	/** @return array<int, string> Las options que cambian lo que pinta la vitrina. */
	private static function options(): array {
		return array( self::OPTION_ENABLED, self::OPTION_TITLE, self::OPTION_SLUGS );
	}

	/**
	 * Purga las páginas de la vitrina, una vez por petición aunque cambien las
	 * tres options en el mismo guardado.
	 *
	 * Además de la etiqueta propia se purga `F`, la que LiteSpeed pone sola a la
	 * portada: una portada cacheada ANTES de que existiera `CACHE_TAG` (un
	 * despliegue sin la purga total del riel) no la lleva, y sin esto el primer
	 * guardado no la limpiaría aunque el panel dijera que sí.
	 *
	 * Sólo LiteSpeed: el borde de Cloudflare se purga por PREFIJO de URL
	 * (`EdgePurge`), y el prefijo de la portada es el sitio entero. La portada
	 * del borde se renueva sola en 10 minutos como mucho.
	 *
	 * @internal Callback de `add_option_{…}` y `update_option_{…}`.
	 */
	public function purge_showcase(): void {
		if ( $this->purged ) {
			return;
		}
		$this->purged = true;
		foreach ( array( self::CACHE_TAG, self::FRONT_PAGE_TAG ) as $tag ) {
			do_action( 'litespeed_purge', $tag ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook público oficial de LiteSpeed Cache.
		}
	}

	/**
	 * Borrar una de las options (WP-CLI) también cambia lo que se pinta.
	 *
	 * @internal Callback de `deleted_option`.
	 *
	 * @param mixed $option Option borrada.
	 */
	public function purge_on_deleted_option( $option ): void {
		if ( in_array( $option, self::options(), true ) ) {
			$this->purge_showcase();
		}
	}

	/**
	 * Devuelve count del shortcode de series en la portada, o null si falta.
	 * El panel usa ese valor para describir cuántos elementos se mostrarán.
	 */
	public static function front_page_count(): ?int {
		$front_id = (int) get_option( 'page_on_front', 0 );
		$content  = $front_id > 0 ? (string) get_post_field( 'post_content', $front_id ) : '';
		if ( ! preg_match( '/\[akb_series_destacadas(\s[^\]]*)?\]/', $content, $match ) ) {
			return null;
		}

		$atts  = shortcode_parse_atts( trim( $match[1] ?? '' ) );
		$count = is_array( $atts ) && isset( $atts['count'] ) ? (int) $atts['count'] : self::DEFAULT_COUNT;

		return max( 1, min( self::MAX_SLUGS, $count ) );
	}

	/**
	 * Campos de la sección del hub (formato de la Settings API de WooCommerce).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_settings(): array {
		$visibles = self::front_page_count();
		$cuantas  = null === $visibles
			? ' <strong>Ojo: la página de inicio no tiene el bloque <code>[akb_series_destacadas]</code>, así que esta lista hoy no se ve en ningún lado.</strong>'
			: ' La portada muestra las primeras <strong>' . $visibles . '</strong> que tengan tomos publicados (ese número lo fija el bloque de la página de inicio). Las que siguen quedan de reserva: entran solas si una de arriba se queda sin tomos publicados.';

		return array(
			array(
				'title' => 'Series en la portada',
				'type'  => 'title',
				'desc'  => 'Vitrina «Series más buscadas» del inicio. Cada serie enlaza a su landing (la página que Google posiciona). El bloque se coloca en la página de inicio con el shortcode <code>[akb_series_destacadas]</code>.',
				'id'    => 'akibara_home_series_section',
			),
			array(
				'title'   => 'Activar',
				'id'      => self::OPTION_ENABLED,
				'type'    => 'checkbox',
				'default' => 'yes',
				'desc'    => 'Muestra la vitrina en la portada. Al desactivarla la lista se conserva.',
			),
			array(
				'title'   => 'Título del bloque',
				'id'      => self::OPTION_TITLE,
				'type'    => 'text',
				'default' => self::DEFAULT_TITLE,
				'css'     => 'width:420px;',
			),
			array(
				'title'   => 'Series (un slug por línea)',
				'desc'    => 'En el orden en que se muestran; máximo ' . self::MAX_SLUGS . '. El slug es la parte final de la URL de la landing: de <code>/serie/blue-lock/</code> se pone <code>blue-lock</code>. Una serie sin tomos publicados se salta sola.' . $cuantas,
				'id'      => self::OPTION_SLUGS,
				'type'    => 'textarea',
				'default' => '',
				'css'     => 'width:420px;height:220px;font-size:13px;font-family:monospace;',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'akibara_home_series_section',
			),
		);
	}

	/**
	 * Slugs limpios, únicos, en orden y acotados; tolera mayúsculas, espacios y
	 * la URL entera pegada («/serie/blue-lock/» → «blue-lock»).
	 *
	 * @param string $raw Contenido de la textarea.
	 * @return array<int, string>
	 */
	public static function parse_slugs( string $raw ): array {
		$out = array();
		foreach ( preg_split( '/[\r\n,]+/', $raw ) ?: array() as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line ) {
				continue;
			}
			$line = (string) preg_replace( '#^https?://[^/]+#i', '', $line );
			$line = trim( $line, "/ \t" );
			if ( str_contains( $line, '/' ) ) {
				$line = (string) substr( $line, (int) strrpos( $line, '/' ) + 1 );
			}
			$slug = sanitize_title( $line );
			if ( '' !== $slug && ! in_array( $slug, $out, true ) ) {
				$out[] = $slug;
			}
			if ( count( $out ) >= self::MAX_SLUGS ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Shortcode `[akb_series_destacadas count=8 columns=4]`.
	 *
	 * @param array<string, mixed>|string $atts Atributos.
	 * @return string HTML; '' si está apagado, sin lista o sin series resolubles.
	 */
	public function render( $atts = array() ): string {
		// Etiqueta ANTES de cualquier salida vacía: una portada con la vitrina
		// apagada también tiene que purgarse cuando el dueño la enciende.
		do_action( 'litespeed_tag_add', self::CACHE_TAG ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook público oficial de LiteSpeed Cache.

		if ( 'yes' !== (string) get_option( self::OPTION_ENABLED, 'yes' ) ) {
			return '';
		}
		$atts = shortcode_atts(
			array(
				'count'   => self::DEFAULT_COUNT,
				'columns' => 4,
			),
			is_array( $atts ) ? $atts : array(),
			'akb_series_destacadas'
		);
		$count   = max( 1, min( self::MAX_SLUGS, (int) $atts['count'] ) );
		$columns = max( 2, min( 6, (int) $atts['columns'] ) );

		$slugs = self::parse_slugs( (string) get_option( self::OPTION_SLUGS, '' ) );
		if ( array() === $slugs ) {
			return '';
		}

		$items = $this->items( $slugs, $count );
		if ( array() === $items ) {
			return '';
		}

		$title = trim( (string) get_option( self::OPTION_TITLE, self::DEFAULT_TITLE ) );
		if ( '' === $title ) {
			$title = self::DEFAULT_TITLE;
		}

		wp_enqueue_style(
			'akb-home-series',
			AKIBARA_BUSINESS_URL . 'assets/css/home-series-destacadas.css',
			array(),
			PluginAssetVersion::for_path( 'assets/css/home-series-destacadas.css' )
		);

		// SeriesHubUrl mantiene Ver todas enlazado al directorio aunque cambie de slug.
		return self::render_items( $items, $title, $columns, SeriesHubUrl::url() );
	}

	/**
	 * HTML del bloque a partir de datos ya resueltos. Pura: testeable sin WP.
	 *
	 * @param array<int, array{name:string,url:string,cover:string,tomos:int}> $items         Series.
	 * @param string                                                          $title         Título visible.
	 * @param int                                                             $columns       Columnas en escritorio.
	 * @param string                                                          $directory_url Enlace «Ver todas» (directorio de series); '' lo omite.
	 * @return string
	 */
	public static function render_items( array $items, string $title, int $columns, string $directory_url = '' ): string {
		if ( array() === $items ) {
			return '';
		}
		$columns = max( 2, min( 6, $columns ) );

		// Mismo encabezado que las demás secciones de la portada
		// (`.akb-section__header` del bloque HTML de la home): así el título
		// vive en la option y no en el editor de bloques.
		$html  = '<section class="akb-home-series" aria-labelledby="akb-home-series-title">';
		$html .= '<header class="akb-section__header"><h2 id="akb-home-series-title">' . esc_html( $title ) . '</h2>';
		if ( '' !== $directory_url ) {
			$html .= '<a class="akb-section__link" href="' . esc_url( $directory_url ) . '">Ver todas →</a>';
		}
		$html .= '</header>';
		$html .= '<ul class="akb-home-series__grid akb-home-series__grid--cols-' . (int) $columns . '">';
		foreach ( $items as $item ) {
			$name  = (string) ( $item['name'] ?? '' );
			$url   = (string) ( $item['url'] ?? '' );
			$cover = (string) ( $item['cover'] ?? '' );
			$tomos = (int) ( $item['tomos'] ?? 0 );
			if ( '' === $name || '' === $url ) {
				continue;
			}
			$html .= '<li class="akb-home-series__item">';
			$html .= '<a class="akb-home-series__link" href="' . esc_url( $url ) . '">';
			// El nombre de la serie va en el alt de la portada y el texto visible
			// se oculta al lector (UM-14). Con `alt=""` —lo correcto en teoría,
			// el nombre ya iba en el enlace— el shortcode vive en the_content y
			// Rank Math (Image SEO, the_content 11) rellena todo alt vacío: las 8
			// portadas salían «Portada Inicio - Comprar en Akibara Chile», el
			// lector lo anunciaba y Clarity etiquetaba los clics con esa frase.
			// Un alt no vacío Rank Math lo respeta. Sin portada, el nombre
			// visible sigue siendo el nombre del enlace.
			$html .= '<span class="akb-home-series__cover akb-cover-cage">';
			if ( '' !== $cover ) {
				$html .= '<img src="' . esc_url( $cover ) . '" alt="' . esc_attr( $name ) . '" width="300" height="450" loading="lazy" decoding="async">';
			}
			$html .= '</span>';
			$html .= '<span class="akb-home-series__name"' . ( '' !== $cover ? ' aria-hidden="true"' : '' ) . '>' . esc_html( $name ) . '</span>';
			if ( $tomos > 0 ) {
				$html .= '<span class="akb-home-series__meta">' . esc_html( 1 === $tomos ? '1 tomo' : sprintf( '%d tomos', $tomos ) ) . '</span>';
			}
			$html .= '</a></li>';
		}
		$html .= '</ul></section>';

		return $html;
	}

	/**
	 * Las primeras `$count` series resolubles de la lista (nombre, landing,
	 * portada del tomo más bajo en stock, tomos publicados), cacheadas 6 h.
	 *
	 * Se recorre la lista ENTERA hasta juntar `$count`, no sus `$count`
	 * primeros slugs: con el recorte antes de filtrar, una serie sin tomos
	 * dejaba un hueco en la portada aunque hubiera reservas guardadas debajo.
	 *
	 * @param array<int, string> $slugs Slugs en orden.
	 * @param int                $count Cuántas se pintan.
	 * @return array<int, array{name:string,url:string,cover:string,tomos:int}>
	 */
	private function items( array $slugs, int $count ): array {
		// La clave incluye count porque almacena la selección ya recortada;
		// instancias con límites distintos no deben compartir ese resultado.
		$key    = self::TRANSIENT_PREFIX . $this->cache_suffix( $count );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$eligibility = new SeriesCatalogEligibility();

		$items = array();
		foreach ( $slugs as $slug ) {
			if ( count( $items ) >= $count ) {
				break;
			}
			$term = get_term_by( 'slug', $slug, 'pa_serie' );
			if ( ! $term instanceof \WP_Term || (int) $term->count < 1 ) {
				continue;
			}
			$url = get_term_link( $term );
			if ( ! is_string( $url ) || '' === $url ) {
				continue;
			}

			// La tarjeta dice «N tomos», así que cuenta TOMOS. `$term->count`
			// cuenta PRODUCTOS: con una edición especial dentro de la serie, la
			// portada diría 24 donde la landing de esa misma serie dice «Completa
			// en 23 tomos». `volume_count()` es la misma cifra que publica el
			// directorio de series, para que las tres superficies no se
			// contradigan. Si el agregado no se puede leer, se cae a `count` —la
			// cifra de hoy— en vez de publicar un cero: la tarjeta oculta la línea
			// cuando el número es 0 y perderla por un hipo de la base sería peor.
			$volumes = $eligibility->volume_count( (int) $term->term_taxonomy_id );
			if ( ! $eligibility->is_reliable() ) {
				$volumes = (int) $term->count;
			}

			$items[] = array(
				'name'  => (string) $term->name,
				'url'   => $url,
				'cover' => $this->cover_url( (int) $term->term_id ),
				'tomos' => $volumes,
			);
		}

		set_transient( $key, $items, self::CACHE_TTL );

		return $items;
	}

	/**
	 * Portada de la serie: la del tomo más bajo con stock, o el más bajo a
	 * secas si la serie entera está agotada (SeriesCover, compartido con las
	 * landings de editorial).
	 *
	 * @param int $term_id Término de la serie.
	 * @return string URL o ''.
	 */
	private function cover_url( int $term_id ): string {
		return SeriesCover::for_tax_query(
			array(
				array(
					'taxonomy' => 'pa_serie',
					'field'    => 'term_id',
					'terms'    => array( $term_id ),
				),
			)
		);
	}

	/**
	 * Clave de caché del resultado.
	 *
	 * @param int $count Cuántas se piden.
	 * @return string
	 */
	private function cache_suffix( int $count ): string {
		$base = (string) get_option( self::OPTION_SLUGS, '' );

		return md5( self::catalog_version() . '|' . $base . '|' . $count );
	}

	/**
	 * Versión de catálogo de WooCommerce: cambia con CUALQUIER escritura de
	 * producto (`wc_delete_product_transients`).
	 *
	 * Es la misma marca que usa WooCommerce para sus propios transients, así que
	 * viene del object cache caliente y no cuesta una consulta. Llevarla en la
	 * clave sustituye a los hooks de invalidación: la foto vieja no se borra, se
	 * vuelve inalcanzable y caduca sola con su TTL.
	 *
	 * @return string
	 */
	private static function catalog_version(): string {
		return class_exists( '\WC_Cache_Helper' )
			? (string) \WC_Cache_Helper::get_transient_version( 'product' )
			: '0';
	}
}
