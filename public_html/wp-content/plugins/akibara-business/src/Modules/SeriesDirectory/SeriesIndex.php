<?php
/**
 * SeriesIndex — el CONJUNTO de series que el directorio publica, y su orden.
 *
 * Esta clase no imprime nada: decide qué series existen para un lector y un
 * rastreador, y en qué orden. Se separó del shortcode porque las tres reglas
 * duras del directorio (qué entra, qué no, y dónde queda cada entrada) son
 * comportamiento verificable, y un método que además emite HTML sólo se puede
 * probar por `assertStringContainsString`.
 *
 * ── Qué entra: dos condiciones, ninguna lista de slugs ──────────────────────
 *   1. Al menos un producto publicado y no excluido del catálogo.
 *   2. Sin `noindex` en `rank_math_robots`.
 *
 * Ambas se evalúan contra el catálogo en cada cache miss. Una lista fija de
 * slugs quedaría vieja apenas se publique el primer tomo de una serie nueva:
 *   - De los 130 términos `pa_serie`, 24 tienen CERO productos y 10 de ellos son
 *     basura de pruebas automatizadas (`qahardening*`, `qapreventacodex*`,
 *     `productoqaakibara*`). Ninguno necesita estar en una lista negra: la
 *     elegibilidad de catálogo los deja fuera sola, igual que a los que la
 *     próxima campaña de QA cree.
 *   - Otros 2 están en `noindex` a propósito (`pack-vinland-saga-123`,
 *     `shangri-la-frontier-1-expansion-pass`). Enlazarlos sería mandar al lector
 *     a una página que le pedimos a Google que no indexe, y gastar rastreo en
 *     ella.
 * En la adopción las dos reglas dieron las 104 series productivas esperadas. El
 * número no está codificado: el siguiente término elegible entra solo.
 *
 * ── El orden: la etiqueta visible ES la clave de orden ──────────────────────
 * El catálogo tiene títulos que empiezan por dígito («20th Century Boys»), por
 * artículo español («El Verano que Hikaru Murió», «La nobleza de las flores»,
 * «Los diarios de la boticaria») y con signos en medio («Ranma 1/2»,
 * «Re:Zero», «Haikyuu!!»). La tentación bibliotecaria es archivar «El Verano…»
 * bajo la V ignorando el artículo. Acá NO se hace, y es una decisión, no un
 * olvido: en un índice alfabético que el lector recorre con los ojos, la única
 * propiedad que importa es que pueda PREDECIR dónde está una entrada, y lo único
 * que puede predecir es la letra que ve escrita. Mover «El Verano…» a la V
 * convierte el índice en un acertijo sobre nuestras reglas internas.
 *
 * Lo que sí resuelve el caso «me acuerdo de "verano", no de "El verano"» es el
 * filtro de texto del shortcode, que busca por SUBCADENA sobre el mismo nombre
 * plegado que se calcula acá (`haystack()`), así que las dos capas nunca pueden
 * discrepar.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\SeriesDirectory;

use Akibara\Business\Modules\SeriesCatalog\SeriesCatalogEligibility;
use Akibara\Business\Modules\SeriesEditions\SeriesEditions;
use Akibara\Business\Modules\SeriesFacts\PublicationStatus;
use Akibara\Business\Utilities\RobotsMeta;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * Conjunto y orden de las series publicables.
 *
 * @since 1.100.0
 */
final class SeriesIndex {

	/** Taxonomía de serie. Las landings viven en sus archives `/serie/<slug>/`. */
	public const TAXONOMY = 'pa_serie';

	/**
	 * Meta de término donde Rank Math guarda las directivas de robots.
	 *
	 * Se lee con `get_term_meta()` y no con una consulta propia porque
	 * `get_terms()` ceba el cache de term meta de todo el conjunto en UNA
	 * consulta (`update_term_meta_cache`, activo por defecto en `WP_Term_Query`).
	 * O sea: leerlo término a término cuesta 0 consultas adicionales, y a cambio
	 * la regla queda escrita en PHP —donde una prueba de comportamiento la puede
	 * observar— en vez de escondida en un `meta_query`.
	 */
	private const ROBOTS_META_KEY = 'rank_math_robots';

	/**
	 * Grupo para todo lo que no empieza por A–Z: dígitos y signos.
	 *
	 * Existe porque el catálogo real lo necesita: «20th Century Boys» no tiene
	 * letra. Un índice que sólo ofrece A–Z simplemente pierde esa serie.
	 */
	public const OTHER_BUCKET = '#';

	private SeriesCatalogEligibility $eligibility;

	/** La consulta de términos del último `entries()` terminó autoritativamente. */
	private bool $term_query_reliable = false;

	/** El agregado visible del último `entries()` terminó autoritativamente. */
	private bool $catalog_query_reliable = false;

	public function __construct( ?SeriesCatalogEligibility $eligibility = null ) {
		$this->eligibility = $eligibility ?? new SeriesCatalogEligibility();
	}

	/** Descarta el snapshot por request después de una mutación de catálogo. */
	public function reset_eligibility_cache(): void {
		$this->eligibility->reset_cache();
		$this->term_query_reliable    = false;
		$this->catalog_query_reliable = false;
	}

	/** Indica si el último agregado pudo distinguir ceros reales de un fallo. */
	public function is_reliable(): bool {
		return $this->term_query_reliable && $this->catalog_query_reliable;
	}

	/**
	 * Las series publicables, ya ordenadas.
	 *
	 * Los conteos de todas las candidatas se resuelven en un solo agregado; no
	 * existe una consulta por serie ni se confía en `WP_Term::count`, que incluye
	 * productos `exclude-from-catalog`.
	 *
	 * @return list<array{term_id:int,name:string,slug:string,tomos:int,url:string,bucket:string,haystack:string,estado:string,brand_name:string,brand_slug:string,country_name:string,country_slug:string,related_names:list<string>}>
	 */
	public function entries(): array {
		global $wpdb;
		$queries_before = isset( $wpdb ) && is_object( $wpdb ) && isset( $wpdb->num_queries )
			? (int) $wpdb->num_queries
			: null;
		$terms = get_terms(
			array(
				'taxonomy'   => self::TAXONOMY,
				// La autoridad real vive en `SeriesCatalogEligibility`: `count` no
				// distingue un producto público de uno `exclude-from-catalog`.
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);

		$this->term_query_reliable = self::term_query_is_reliable( $terms, $queries_before );
		if ( ! $this->term_query_reliable ) {
			$this->catalog_query_reliable = false;
			// `WP_Error` (taxonomía aún no registrada en un request temprano). Un
			// directorio vacío es preferible a un fatal en la página.
			return array();
		}

		$term_taxonomy_ids = array();
		$terms_by_id       = array();
		foreach ( $terms as $term ) {
			if ( $term instanceof WP_Term && self::TAXONOMY === (string) $term->taxonomy ) {
				$term_taxonomy_ids[]                 = (int) $term->term_taxonomy_id;
				$terms_by_id[ (int) $term->term_id ] = $term;
			}
		}
		// DOS cifras del MISMO agregado. La de productos decide si la serie
		// existe para el directorio; la de tomos es la que se publica, porque el
		// rótulo dice «N tomos en catálogo». Con una edición especial dentro de
		// su serie, contar productos hacía que esta línea dijera 24 mientras la
		// landing de esa misma serie —a un clic— dice «Completa en 23 tomos».
		$catalog_counts               = $this->eligibility->counts( $term_taxonomy_ids );
		$volume_counts                = $this->eligibility->volume_counts( $term_taxonomy_ids );
		$origins                      = $this->eligibility->origins( $term_taxonomy_ids );
		$this->catalog_query_reliable = array() === $term_taxonomy_ids || $this->eligibility->is_reliable();

		$eligible_names = array();
		foreach ( $terms_by_id as $tid => $t ) {
			$tt_id = (int) $t->term_taxonomy_id;
			if ( ( $catalog_counts[ $tt_id ] ?? 0 ) > 0 && ! $this->is_noindex( $tid ) ) {
				$eligible_names[ $tid ] = (string) $t->name;
			}
		}

		$entries = array();
		foreach ( $terms as $term ) {
			if ( ! $term instanceof WP_Term ) {
				continue;
			}
			$term_taxonomy_id = (int) $term->term_taxonomy_id;
			$count            = $catalog_counts[ $term_taxonomy_id ] ?? 0;
			$entry            = $this->entry(
				$term,
				$count,
				$volume_counts[ $term_taxonomy_id ] ?? $count,
				$origins[ $term_taxonomy_id ] ?? array(),
				$eligible_names
			);
			if ( null !== $entry ) {
				$entries[] = $entry;
			}
		}

		usort( $entries, array( self::class, 'compare' ) );

		return $entries;
	}

	/**
	 * Distingue un conjunto vacío real de `get_terms()` y un fallo SQL normalizado.
	 *
	 * Un `last_error` antiguo no invalida un resultado proveniente de object
	 * cache; sólo cuenta si este llamado incrementó `wpdb::num_queries`.
	 *
	 * @param mixed    $terms          Resultado de WordPress.
	 * @param int|null $queries_before Contador anterior, si está disponible.
	 */
	private static function term_query_is_reliable( $terms, ?int $queries_before ): bool {
		if ( ! is_array( $terms ) ) {
			return false;
		}

		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return true;
		}
		if ( isset( $wpdb->ready ) && ! (bool) $wpdb->ready ) {
			return false;
		}

		$query_ran = null === $queries_before
			|| ! isset( $wpdb->num_queries )
			|| (int) $wpdb->num_queries > $queries_before;

		return ! ( $query_ran && isset( $wpdb->last_error ) && '' !== trim( (string) $wpdb->last_error ) );
	}

	/**
	 * Las series agrupadas por letra, en el orden del índice.
	 *
	 * Sólo devuelve los grupos con contenido: una sección «Q» vacía es ruido en
	 * el documento y un ancla rota en el índice.
	 *
	 * @return array<string, list<array{term_id:int,name:string,slug:string,tomos:int,url:string,bucket:string,haystack:string,estado:string,brand_name:string,brand_slug:string,country_name:string,country_slug:string,related_names:list<string>}>>
	 */
	public function grouped(): array {
		$grouped = array();
		foreach ( self::letters() as $letter ) {
			$grouped[ $letter ] = array();
		}

		foreach ( $this->entries() as $entry ) {
			$grouped[ $entry['bucket'] ][] = $entry;
		}

		return array_filter( $grouped, static fn( array $group ): bool => array() !== $group );
	}

	/**
	 * Las etiquetas del índice, en orden: `#` y luego A–Z.
	 *
	 * Se publica el alfabeto COMPLETO —incluidas las letras sin series— para que
	 * la barra del índice no cambie de forma cada vez que entra o sale un título:
	 * una barra que se reordena sola obliga a releerla. Las letras vacías las
	 * renderiza el shortcode como texto inerte, no como enlace a ninguna parte.
	 *
	 * @return list<string>
	 */
	public static function letters(): array {
		return array_merge( array( self::OTHER_BUCKET ), range( 'A', 'Z' ) );
	}

	/**
	 * Grupo de un nombre: su primera letra plegada, o `#`.
	 */
	public static function bucket_of( string $name ): string {
		$first = mb_substr( self::fold( $name ), 0, 1, 'UTF-8' );

		return ( '' !== $first && 1 === preg_match( '/^[A-Z]$/', $first ) )
			? $first
			: self::OTHER_BUCKET;
	}

	/**
	 * Texto sobre el que busca el filtro del navegador: el nombre plegado en
	 * minúsculas.
	 *
	 * Se calcula acá y viaja al DOM en un atributo para que el JS compare dos
	 * cadenas ya normalizadas por el MISMO criterio que ordena el índice. La
	 * alternativa —plegar en JS— deja dos implementaciones del mismo plegado que
	 * se pueden separar sin que ninguna prueba lo note.
	 */
	public static function haystack( string $name ): string {
		return mb_strtolower( self::fold( $name ), 'UTF-8' );
	}

	/**
	 * Normaliza un título a mayúsculas sin acentos, para ordenar y agrupar.
	 *
	 * `remove_accents()` es la función de WordPress y no una tabla propia: ya
	 * cubre el latín-1, el latín extendido y los casos que un manga en español
	 * trae de verdad (Murió → MURIO, Ñ → N). El `function_exists` es para las
	 * pruebas unitarias, que corren sin `wp-includes/formatting.php`.
	 */
	private static function fold( string $name ): string {
		$name = trim( $name );
		if ( function_exists( 'remove_accents' ) ) {
			$name = (string) remove_accents( $name );
		}

		return mb_strtoupper( $name, 'UTF-8' );
	}

	/**
	 * Orden total y ESTABLE entre dos entradas.
	 *
	 * `strcmp` sobre el nombre plegado y no `strcoll`: `strcoll` depende del
	 * locale del proceso, que en este sitio lo fijan WordPress y el sistema
	 * operativo del hosting por separado. Un orden que cambia según dónde corra
	 * el código no es un orden — y el directorio lo sirve cacheado, así que la
	 * discrepancia quedaría congelada seis horas sin que nadie sepa por qué.
	 *
	 * El desempate por `term_id` no es decorativo: dos series pueden compartir
	 * nombre plegado (hoy no ocurre, pero «Re:Zero» y «ReZero» plegarían
	 * distinto por poco). Sin desempate explícito, el orden entre ellas lo
	 * decidiría el orden de llegada desde la base.
	 *
	 * @param array{name:string,term_id:int} $a Primera entrada.
	 * @param array{name:string,term_id:int} $b Segunda entrada.
	 */
	private static function compare( array $a, array $b ): int {
		$cmp = strcmp( self::fold( $a['name'] ), self::fold( $b['name'] ) );

		return 0 !== $cmp ? $cmp : ( $a['term_id'] <=> $b['term_id'] );
	}

	/**
	 * Normaliza un término, o `null` si no debe publicarse.
	 *
	 * @param WP_Term            $term           Término de serie.
	 * @param int                $catalog_count  Productos publicados y visibles: decide si sale.
	 * @param int                $volume_count   Tomos (los mismos, sin variantes): es lo que se publica.
	 * @param array<string,mixed> $origin        Editorial y país resueltos en el agregado.
	 * @param array<int,string>   $eligible_names Mapa de `term_id => nombre` de las series elegibles.
	 * @return array{term_id:int,name:string,slug:string,tomos:int,url:string,bucket:string,haystack:string,estado:string,brand_name:string,brand_slug:string,country_name:string,country_slug:string,related_names:list<string>}|null
	 */
	private function entry(
		WP_Term $term,
		int $catalog_count,
		int $volume_count,
		array $origin = array(),
		array $eligible_names = array()
	): ?array {
		if ( self::TAXONOMY !== (string) $term->taxonomy ) {
			return null;
		}

		if ( $catalog_count < 1 ) {
			return null;
		}

		$term_id = (int) $term->term_id;
		if ( $this->is_noindex( $term_id ) ) {
			return null;
		}

		$url = get_term_link( $term );
		if ( ! is_string( $url ) || '' === $url ) {
			// Sin URL no hay entrada de directorio: un nombre sin enlace no lleva
			// a ninguna parte ni aporta rastreo. Degrada silencioso, como
			// `SeriesFacts::term_markup()`.
			return null;
		}

		$name         = (string) $term->name;
		$brand_name   = isset( $origin['brand_name'] ) && is_string( $origin['brand_name'] ) ? trim( $origin['brand_name'] ) : '';
		$brand_slug   = isset( $origin['brand_slug'] ) && is_string( $origin['brand_slug'] ) ? trim( $origin['brand_slug'] ) : '';
		$country_name = isset( $origin['country_name'] ) && is_string( $origin['country_name'] ) ? trim( $origin['country_name'] ) : '';
		$country_slug = isset( $origin['country_slug'] ) && is_string( $origin['country_slug'] ) ? trim( $origin['country_slug'] ) : '';

		// Puente de ediciones relacionadas: lee del termmeta ya cebado por get_terms().
		$related_raw   = get_term_meta( $term_id, SeriesEditions::META_KEY, true );
		$related_ids   = is_array( $related_raw ) ? $related_raw : ( is_numeric( $related_raw ) ? array( $related_raw ) : array() );
		$related_names = array();
		foreach ( $related_ids as $rel_candidate ) {
			$rel_id = is_scalar( $rel_candidate ) ? (int) $rel_candidate : 0;
			if ( $rel_id > 0 && $rel_id !== $term_id && isset( $eligible_names[ $rel_id ] ) ) {
				$related_names[] = $eligible_names[ $rel_id ];
			}
		}
		$related_names = array_values( array_unique( $related_names ) );

		$haystack_parts = array( self::haystack( $name ) );
		if ( '' !== $brand_name ) {
			$haystack_parts[] = self::haystack( $brand_name );
		}
		if ( '' !== $country_name && false === strpos( self::haystack( $brand_name ), self::haystack( $country_name ) ) ) {
			$haystack_parts[] = self::haystack( $country_name );
		}
		foreach ( $related_names as $rel_name ) {
			$haystack_parts[] = self::haystack( $rel_name );
		}

		return array(
			'term_id'       => $term_id,
			'name'          => $name,
			'slug'          => (string) $term->slug,
			// El rótulo habla en TOMOS; la elegibilidad de arriba, en productos.
			'tomos'         => max( 0, $volume_count ),
			'url'           => $url,
			'bucket'        => self::bucket_of( $name ),
			'haystack'      => implode( ' ', array_filter( $haystack_parts ) ),
			// Estado de la EDICIÓN que se vende (Completa, En publicación, Al
			// día…), el mismo dato y la misma palabra que la ficha de la landing
			// (UC-14). Sale del cache de term meta que `get_terms()` ya cebó para
			// todo el conjunto: cero consultas por serie. '' = sin dato, sin chip.
			'estado'        => PublicationStatus::sanitize_edition_state( get_term_meta( $term_id, PublicationStatus::META_EDITION_STATE, true ) ),
			'brand_name'    => $brand_name,
			'brand_slug'    => $brand_slug,
			'country_name'  => $country_name,
			'country_slug'  => $country_slug,
			'related_names' => $related_names,
		);
	}

	/**
	 * ¿El término pide `noindex` a los buscadores?
	 *
	 * Rank Math guarda un array serializado (`a:1:{i:0;s:7:"noindex";}`), pero se
	 * aceptan también las formas string y lista suelta: el valor lo escribe un
	 * plugin de terceros desde su UI y este módulo no controla su forma. Ante
	 * cualquier valor ilegible se falla cerrado para no publicar un enlace que
	 * las demás superficies SEO consideran incierto.
	 */
	private function is_noindex( int $term_id ): bool {
		return ! RobotsMeta::is_indexable( get_term_meta( $term_id, self::ROBOTS_META_KEY, true ) );
	}
}
