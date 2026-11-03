<?php
/**
 * La serie de una guía del blog, con los datos de su tarjeta ya decididos.
 *
 * El dueño liga cada serie a su guía en el término `pa_serie` («Guía
 * relacionada», `SeriesRelatedGuide`), y la landing de la serie la muestra. La
 * guía, a su vez, muestra la tarjeta de su serie cerca del inicio y cierra con un
 * botón a sus tomos (UC-12 =, tema `inc/guide-series.php`). Hasta la
 * revisión T7-1 (D6) el TEMA resolvía el vínculo al revés, decidía el desempate
 * entre ediciones, cacheaba la portada y contaba los tomos con su propia regla:
 * lógica de negocio en la capa de presentación. Aquí vive ahora, y el tema sólo
 * pinta lo que recibe por el filtro `akb_guide_series_card`.
 *
 * Además etiqueta la página de la guía con la etiqueta de caché de LiteSpeed de
 * su serie (`T.<id>`): cuando la landing se purga (un tomo cambia de stock), la
 * guía también, y su tarjeta no cuenta tomos viejos.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\RelatedGuides;

use Akibara\Business\Modules\SeriesFacts\SeriesFacts;
use Akibara\Business\Services\SeriesCover;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.116.41
 */
final class GuideSeriesCard {

	/** Filtro que el tema aplica en la entrada: devuelve los datos de la tarjeta o null. */
	public const FILTER = 'akb_guide_series_card';

	/** Taxonomía de las series. */
	private const TAXONOMY = 'pa_serie';

	/** Cuánto vive la portada cacheada (la clave ya cambia con cualquier producto). */
	private const COVER_TTL = 6 * HOUR_IN_SECONDS;

	/**
	 * Devuelve el módulo de hechos de serie cuando se pinta (se crea después).
	 *
	 * @var callable(): ?object
	 */
	private $series_facts;

	/**
	 * Serie por entrada, dentro de la petición.
	 *
	 * @var array<int, WP_Term|null>
	 */
	private static array $memo = array();

	/**
	 * @param callable(): ?object $series_facts Módulo `series_facts` (o un doble con `catalog_facts()`).
	 */
	public function __construct( callable $series_facts ) {
		$this->series_facts = $series_facts;
	}

	public function register(): void {
		add_filter( self::FILTER, array( $this, 'card' ), 10, 2 );
		add_action( 'wp', array( $this, 'tag_guide_with_series' ) );
	}

	/**
	 * Serie ligada a una entrada, o null.
	 *
	 * Si el dueño ligó la misma guía a varias ediciones (dos ediciones de la
	 * misma obra), gana la que tiene más productos: es la que más tomos ofrece.
	 * Empate: el nombre, para que la elección no dependa del orden de la base.
	 * Hoy ninguna guía está ligada a dos series, así que la regla es latente.
	 *
	 * @param int $post_id ID de la entrada.
	 * @return WP_Term|null
	 */
	public static function series_for_guide( int $post_id ): ?WP_Term {
		if ( $post_id < 1 ) {
			return null;
		}
		if ( array_key_exists( $post_id, self::$memo ) ) {
			return self::$memo[ $post_id ];
		}

		$terms = get_terms(
			array(
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => true,
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- consulta inversa acotada por un único valor; get_terms() la cachea por last_changed.
					array(
						'key'   => SeriesRelatedGuide::META_KEY,
						'value' => (string) $post_id,
					),
				),
			)
		);

		$elegido = null;
		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				if ( ! $term instanceof WP_Term || (int) $term->count < 1 ) {
					continue;
				}
				if (
					null === $elegido
					|| (int) $term->count > (int) $elegido->count
					|| ( (int) $term->count === (int) $elegido->count && strcmp( (string) $term->name, (string) $elegido->name ) < 0 )
				) {
					$elegido = $term;
				}
			}
		}

		self::$memo[ $post_id ] = $elegido;

		return $elegido;
	}

	/**
	 * Datos de la tarjeta de la serie de una guía.
	 *
	 * @internal Callback del filtro `akb_guide_series_card`.
	 *
	 * @param mixed $card    Lo que traiga el filtro (null por defecto).
	 * @param mixed $post_id Entrada.
	 * @return array{name:string,url:string,cover:string,estados:array<int,array{label:string,texto:string}>}|null
	 */
	public function card( $card, $post_id = 0 ): ?array {
		$term = self::series_for_guide( (int) $post_id );
		if ( null === $term ) {
			return is_array( $card ) ? $card : null;
		}

		$url = get_term_link( $term );
		if ( ! is_string( $url ) || '' === $url ) {
			return null;
		}

		return array(
			'name'    => (string) $term->name,
			'url'     => $url,
			'cover'   => self::cover( (int) $term->term_id ),
			'estados' => $this->estados( $term ),
		);
	}

	/**
	 * Etiqueta de caché de LiteSpeed de la serie en la página de su guía.
	 *
	 * En `wp` (antes de imprimir nada): LiteSpeed manda las etiquetas como
	 * cabecera.
	 *
	 * @internal Callback de `wp`.
	 */
	public function tag_guide_with_series(): void {
		if ( ! is_singular( 'post' ) ) {
			return;
		}

		$term = self::series_for_guide( (int) get_queried_object_id() );
		if ( null === $term ) {
			return;
		}

		do_action( 'litespeed_tag_add', 'T.' . (int) $term->term_id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- API pública de LiteSpeed Cache.
	}

	/**
	 * Portada de la serie (la regla de «Series más buscadas»: el tomo más bajo
	 * con stock, o el más bajo), cacheada.
	 *
	 * `SeriesCover` hace dos o tres consultas y no guarda nada: la clave lleva la
	 * versión de productos de WooCommerce, que cambia con cualquier escritura de
	 * producto, así que la portada guardada nunca sobrevive a un cambio de
	 * catálogo.
	 *
	 * @param int $term_id Serie.
	 */
	private static function cover( int $term_id ): string {
		$version = class_exists( 'WC_Cache_Helper' ) ? (string) \WC_Cache_Helper::get_transient_version( 'product' ) : '';
		$clave   = 'akb_guide_cover_' . $term_id . '_' . md5( $version );
		$cached  = get_transient( $clave );
		if ( is_string( $cached ) ) {
			return $cached;
		}

		$cover = SeriesCover::for_tax_query(
			array(
				array(
					'taxonomy' => self::TAXONOMY,
					'field'    => 'term_id',
					'terms'    => array( $term_id ),
				),
			)
		);
		set_transient( $clave, $cover, self::COVER_TTL );

		return $cover;
	}

	/**
	 * Tomos por estado con la regla y las palabras de la ficha de la landing
	 * (`SeriesFacts::stock_rows()`). Vacío si no hay agregado: la tarjeta pierde
	 * la línea, no inventa un cero.
	 *
	 * También vacío con la otra condición con que la landing decide si pinta su
	 * ficha (`SeriesFacts::renderable_facts()`): ningún tomo contado. El `count`
	 * del término que deja pasar la tarjeta es el de WordPress e incluye
	 * productos que SeriesFacts no cuenta como tomo —variantes de un tomo y
	 * productos fuera del catálogo—; con sólo esos, la guía afirmaba «Disponible
	 * 0 tomos · A pedido 0 tomos» mientras la landing callaba (REV-T7-3-D2).
	 *
	 * Y sin las filas en cero. La regla y las
	 * palabras siguen siendo las de la ficha (la revisión T7-2 D3 unió las dos
	 * fuentes), pero la ficha de la landing es una hoja de datos, donde «A pedido
	 * 0 tomos» responde a una pregunta del que compara; en la guía es un resumen
	 * de lectura junto a «Ver los tomos», y un cero no informa y resta: en la de
	 * Berserk el lector veía «Disponible 1 tomo · A pedido 0 tomos». Como aquí ya
	 * hay al menos un tomo contado, siempre queda al menos una fila.
	 *
	 * @param WP_Term $term Serie.
	 * @return array<int,array{label:string,texto:string}>
	 */
	private function estados( WP_Term $term ): array {
		$modulo = ( $this->series_facts )();
		if ( ! is_object( $modulo ) || ! method_exists( $modulo, 'catalog_facts' ) || ! isset( $term->term_taxonomy_id ) ) {
			return array();
		}

		$facts = $modulo->catalog_facts( (int) $term->term_taxonomy_id );
		if ( ! is_array( $facts ) || (int) ( $facts['tomos'] ?? 0 ) < 1 ) {
			return array();
		}

		$filas = array_filter(
			SeriesFacts::stock_rows( $facts ),
			static fn( array $fila ): bool => (int) $fila['count'] > 0
		);

		return array_values(
			array_map(
				static fn( array $fila ): array => array(
					'label' => $fila['label'],
					'texto' => $fila['texto'],
				),
				$filas
			)
		);
	}
}
