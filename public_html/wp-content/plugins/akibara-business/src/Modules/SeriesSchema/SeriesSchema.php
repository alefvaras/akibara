<?php
/**
 * Publica la entidad de la serie en el grafo existente de Rank Math.
 *
 * La landing resuelve el isPartOf que los libros apuntan a /serie/<slug>/ con
 * los mismos hechos visibles de SeriesFacts. rank_math/json_ld en prioridad91
 * recibe el grafo armado por Rank Math en10 y permite enlazar CollectionPage
 * con la serie antes de sus conexiones en99. No emite un segundo script JSON-LD
 * ni duplica Organization, WebSite o CollectionPage.
 *
 * La entidad debe conservar sus identificadores estables y omitir una serie
 * marcada noindex explícitamente. La configuración de snippets de Rank Math
 * no debe confundirse con la ausencia de su grafo base.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\SeriesSchema;

use Akibara\Business\Modules\SeriesFacts\SeriesFacts;
use Akibara\Business\Utilities\RobotsMeta;
use Akibara\Business\Utilities\SchemaGraph;

defined( 'ABSPATH' ) || exit;

/**
 * Entidad `ComicSeries` de la landing `pa_serie` dentro del grafo de Rank Math.
 */
final class SeriesSchema {

	/** Taxonomía de serie. Las landings viven en sus archives. */
	private const TAXONOMY = 'pa_serie';

	/**
	 * Taxonomía de editorial. `pa_editorial` NO EXISTE — ver el porqué extendido
	 * en `SeriesFacts::BRAND_TAXONOMY`. Acá se usa sólo para resolver el enlace
	 * de cada marca, cuyo `term_id` ya viene en el agregado.
	 */
	private const BRAND_TAXONOMY = 'product_brand';

	/**
	 * Taxonomía de AUTOR — el mismo dato que `SeriesFacts` rinde como «Autor:».
	 */
	private const AUTHOR_TAXONOMY = 'pa_autor';

	/**
	 * Clave del nodo dentro del array de Rank Math.
	 *
	 * Rank Math entrega un array ASOCIATIVO (`Organization`, `WebSite`,
	 * `BreadcrumbList`, `WebPage`…) y hace `array_values()` recién al serializar,
	 * así que la clave sólo tiene que ser única. Deliberadamente NO empieza por
	 * `schema-`: ese prefijo es el que `Frontend::connect_schema_entities`
	 * inspecciona en prio 99 para reescribir `isPartOf`/`publisher`, y este nodo
	 * no quiere que se lo toquen.
	 */
	private const GRAPH_KEY = 'ComicSeries';

	/**
	 * Meta de Rank Math que declara noindex explícito de un término.
	 *
	 * rank_math_robots contiene la lista serializada de directivas. Sólo la
	 * presencia efectiva de noindex activa esta exclusión editorial.
	 */
	private const ROBOTS_META = 'rank_math_robots';

	/**
	 * Fuente ÚNICA de los hechos del catálogo.
	 *
	 * Es la MISMA instancia que rinde el bloque visible, cableada en
	 * `Plugin.php`. No es un detalle de eficiencia: es lo que garantiza que el
	 * JSON-LD y el `<dl>` de la página no puedan contradecirse, incluido el
	 * criterio de qué datos son publicables.
	 */
	private SeriesFacts $facts;

	/**
	 * @param SeriesFacts $facts La misma instancia que rinde el bloque visible.
	 */
	public function __construct( SeriesFacts $facts ) {
		$this->facts = $facts;
	}

	/**
	 * Un solo filtro. Ver el docblock del archivo para el porqué de la prioridad.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'rank_math/json_ld', array( $this, 'handle_rank_math_json_ld' ), SchemaGraph::SERIES_COLLECTION, 2 );
	}

	/**
	 * Adjunta la entidad de la serie al `@graph` existente.
	 *
	 * @internal Callback del hook `rank_math/json_ld`.
	 *
	 * @param array<string,mixed> $data   Nodos JSON-LD de Rank Math.
	 * @param mixed               $jsonld Instancia interna de Rank Math, no usada.
	 * @return array<string,mixed>
	 */
	public function handle_rank_math_json_ld( array $data, $jsonld = null ): array {
		unset( $jsonld );

		$term = $this->publishable_series_term();
		if ( null === $term ) {
			return $data;
		}

		// El `@id` ES el enlace del término. Sin enlace utilizable no hay entidad
		// que declarar: un nodo sin identificador no lo puede referenciar ninguna
		// ficha, que es la única razón por la que este módulo existe.
		$url = $this->series_url( $term );
		if ( '' === $url ) {
			return $data;
		}

		$facts = $this->facts->catalog_facts( (int) $term->term_taxonomy_id );
		if ( null === $facts || (int) ( $facts['tomos'] ?? 0 ) < 1 ) {
			// 24 series no tienen catálogo publicado. Una entidad sin un solo
			// hecho derivable sólo diría "esto existe", y `SeriesFacts` tampoco
			// imprime su bloque: las dos superficies callan por el mismo motivo.
			return $data;
		}

		$data[ self::GRAPH_KEY ] = $this->build_entity( $term, $facts, $url );

		return $this->point_collection_page_at( $data, $url );
	}

	/**
	 * El término de la serie cuando este request debe declarar la entidad.
	 *
	 * Cuatro guards, ninguno redundante:
	 *   - Archive de `pa_serie` y no admin: fuera de ahí no hay serie que declarar.
	 *   - Página 1: en `/serie/<slug>/page/2/` el `@id` sería IDÉNTICO al de la
	 *     página 1, o sea dos URLs afirmando definir el mismo nodo con distinta
	 *     `CollectionPage` alrededor. Las páginas paginadas son documentos
	 *     indexables con canonical propio, pero la entidad `ComicSeries` tiene un
	 *     solo hogar: la URL base de la serie. Cada página 2+ conserva su schema de
	 *     página y productos sin volver a declararla.
	 *   - `noindex` explícito: si el dueño sacó una serie del índice, publicarle
	 *     una entidad estructurada la reintroduce por la puerta de atrás.
	 *   - Tipo explícito: un término marcado `non_comic` conserva su archive y el
	 *     `CollectionPage` de Rank Math, pero nunca se identifica como ComicSeries.
	 *
	 * @return object|null Término consultado, o null si no corresponde declarar.
	 */
	private function publishable_series_term(): ?object {
		if ( ! function_exists( 'is_tax' ) || is_admin() || ! is_tax( self::TAXONOMY ) ) {
			return null;
		}

		if ( $this->current_page() > 1 ) {
			return null;
		}

		$term = get_queried_object();
		if ( ! is_object( $term )
			|| ! isset( $term->taxonomy, $term->term_taxonomy_id, $term->term_id, $term->name )
			|| self::TAXONOMY !== (string) $term->taxonomy
			|| (int) $term->term_taxonomy_id < 1 ) {
			return null;
		}

		$term_id = (int) $term->term_id;

		return $this->is_noindex( $term_id ) || ! SeriesSchemaType::supports_comic_series( $term_id )
			? null
			: $term;
	}

	/**
	 * Número de página del archive. 1 cuando no hay paginación.
	 *
	 * Mismo predicado que `SeoIndexing\SeriesPaginationPolicy::current_page()`:
	 * las dos políticas hablan de la misma frontera y tienen que trazarla igual.
	 */
	private function current_page(): int {
		if ( ! function_exists( 'get_query_var' ) ) {
			return 1;
		}

		return max( 1, (int) get_query_var( 'paged' ) );
	}

	/**
	 * ¿El dueño puso esta serie en `noindex` desde Rank Math?
	 *
	 * @param int $term_id Término de la serie.
	 */
	private function is_noindex( int $term_id ): bool {
		if ( $term_id < 1 || ! function_exists( 'get_term_meta' ) ) {
			return false;
		}

		return ! RobotsMeta::is_indexable( get_term_meta( $term_id, self::ROBOTS_META, true ) );
	}

	/**
	 * URL canónica de la landing, que es también el `@id` de la entidad.
	 *
	 * `get_term_link()` sobre el término y nada más: es LA MISMA llamada que hace
	 * `SchemaProductRankMath\BookSchemaEnricher::restore_series_relationship()`
	 * para el `isPartOf` de cada ficha. Que sean la misma función sobre el mismo
	 * término es lo que hace imposible que los dos strings difieran — y si
	 * difirieran, las ~1.566 referencias quedarían apuntando a un nodo que no es
	 * éste y todo el módulo no serviría de nada.
	 *
	 * En un archive paginado `get_term_link()` devuelve igualmente la URL base
	 * del término (sin `/page/N/`), así que el identificador es estable.
	 *
	 * @param object $term Término consultado.
	 */
	private function series_url( object $term ): string {
		if ( ! function_exists( 'get_term_link' ) ) {
			return '';
		}

		$link = get_term_link( $term );

		return ( is_string( $link ) && '' !== $link ) ? $link : '';
	}

	/**
	 * La entidad, con una propiedad por cada dato REAL disponible.
	 *
	 * Qué se declara y de dónde sale cada cosa:
	 *   - `@id`/`url`  → `get_term_link()`, el mismo string del `isPartOf`.
	 *   - `name`       → `$term->name`, que es literalmente el H1 de la página
	 *                     (`akibara_gp_render_archive_hero()` del theme).
	 *   - `description`→ la descripción CURADA del término, la que el hero ya
	 *                     imprime. Sin prosa generada: la escribe el dueño y
	 *                     /nosotros/ lo afirma en público.
	 *   - `inLanguage` → 'es'. El mismo valor que `BookSchemaEnricher` declara en
	 *                     cada tomo: son ediciones en español, no una suposición.
	 *   - `publisher`  → editoriales del agregado, con el enlace de su archive.
	 *
	 * @param object                                                                                                                        $term  Término consultado.
	 * @param array{tomos:int,instock:int,preventa:int,agotados:int,agotados_tomos:array<int,string>,price_min:?float,price_max:?float,updated:string,autores:array<int,array{name:string,term_id:int}>,editoriales:array<int,array{name:string,term_id:int}>} $facts Agregado de `SeriesFacts`.
	 * @param string                                                                                                                        $url   `@id` de la entidad.
	 * @return array<string,mixed>
	 */
	private function build_entity( object $term, array $facts, string $url ): array {
		$entity = array(
			'@type'      => 'ComicSeries',
			// La entidad de serie usa un @id con fragmento #serie y conserva url sin él.
			// Esto evita fusionarla con CollectionPage o con el item del breadcrumb, que
			// representan la página y no la obra.

			'@id'        => self::entity_id( $url ),
			'url'        => $url,
			'name'       => (string) $term->name,
			'inLanguage' => 'es',
		);

		$description = $this->term_description( $term );
		if ( '' !== $description ) {
			$entity['description'] = $description;
		}

		$publisher = $this->build_publishers( $facts['editoriales'] ?? array() );
		if ( array() !== $publisher ) {
			// Una sola editorial va como objeto y varias como lista: es la misma
			// distinción que hace el renglón visible entre "Editorial" y
			// "Editoriales", y schema.org admite las dos formas para `publisher`.
			$entity['publisher'] = 1 === count( $publisher ) ? $publisher[0] : $publisher;
		}

		$authors = $this->build_authors( $facts['autores'] ?? array() );
		if ( array() !== $authors ) {
			// Es la respuesta a «¿quién escribió X?» que un asistente busca acá.
			// Series de cómic occidental traen listas largas (Hellblazer: 10);
			// es dato real y el agregado ya las entrega en orden estable.
			$entity['author'] = 1 === count( $authors ) ? $authors[0] : $authors;
		}

		// ── Tres propiedades que este nodo NO emite, y por qué ─────────────────
		//
		// `offers` / `AggregateOffer` — RETIRADA. El vocabulario la define para el
		// caso inverso al nuestro: schema.org dice literalmente «When a SINGLE
		// product is associated with multiple offers (for example, the same pair
		// of shoes is offered by different merchants)». Acá hay 107 productos
		// distintos y UN solo comerciante, y Google además pide expresamente que
		// no se use para variantes de producto. Colgada de la `ComicSeries`,
		// `lowPrice: 9500` afirma que ESTA entidad —la serie— cuesta $9.500,
		// cuando comprarla entera son ~$1.037.000. El escenario que lo condena es
		// justo el que este módulo busca provocar: un asistente lee el marcado y
		// responde «One Piece cuesta entre $9.500 y $9.990 en Akibara». El precio
		// por tomo ya lo declara cada ficha en su propio `Offer`, que es donde el
		// dato es cierto, y el rango sigue visible en el bloque de datos.
		//
		// `dateModified` — RETIRADA. El dato es `MAX(post_modified)` de los
		// productos, que sube con cualquier venta. `SeriesFacts` ya rechazó esa
		// lectura POR ESCRITO al elegir el rótulo «Catálogo actualizado» en vez de
		// «Actualizado» (ver `render_updated_row()`): lo que cambió es el
		// inventario, no la obra. `dateModified` sobre una `ComicSeries` afirma
		// que la OBRA se modificó, que es exactamente lo que allí se descartó por
		// falso. Publicar en el marcado lo que el texto visible evita decir sería
		// contradecirnos en la capa que nadie mira.
		//
		// El ESTADO DE PUBLICACIÓN («Completa en 23 tomos») — NO SE EMITE, y no
		// por falta de dato: desde `PublicationStatus` es un dato real, escrito
		// por el dueño y ya visible en la ficha. Se buscó la propiedad y NO
		// EXISTE. El recorrido, para que nadie lo repita:
		//   - `numberOfItems` es de `ItemList`, no de `CreativeWorkSeries` ni de
		//     ninguno de sus ancestros. Colgarla de esta entidad sería inventar
		//     vocabulario, y además reabriría por la puerta de atrás el `ItemList`
		//     que el docblock del archivo descarta con tres razones.
		//   - `numberOfEpisodes` ya estaba descartada arriba: es de `TVSeries` y
		//     `CreativeWorkSeason`. No hay un `numberOfVolumes` en schema.org.
		//   - `endDate` (sí existe en `CreativeWorkSeries`) SIGNIFICARÍA que la
		//     serie terminó, pero pide una FECHA y lo que el dueño carga es un
		//     estado. Rellenarla con una fecha aproximada es exactamente el
		//     placeholder que la regla 1 de `SeriesFacts` prohíbe.
		//   - `creativeWorkStatus` acepta texto libre, y ahí está su problema: sus
		//     términos de ejemplo son «Incomplete, Draft, Published, Obsolete», o
		//     sea el ciclo de vida EDITORIAL de un documento, no la serialización
		//     de una obra. Escribir «Completa» ahí es acuñar vocabulario propio en
		//     un campo que ningún consumidor interpreta: coste de mantenimiento a
		//     cambio de cero lectores.
		// Mientras no aparezca una propiedad que signifique esto, el dato vive
		// donde es cierto y donde alguien lo lee: el bloque visible.

		return $entity;
	}

	/**
	 * Identificador de la entidad: la URL de la landing más `#serie`.
	 *
	 * Vive acá y en `BookSchemaEnricher::SERIES_ID_FRAGMENT`, y las dos tienen que
	 * decir lo mismo o el grafo se desconecta — el `isPartOf` de ~1.566 fichas
	 * apuntaría a un identificador que ninguna página declara. Hay una prueba que
	 * compara ambas superficies.
	 */
	public static function entity_id( string $url ): string {
		return rtrim( $url, '#' ) . '#serie';
	}

	/**
	 * Descripción curada del término, en texto plano.
	 *
	 * El hero la parte en dos (pitch arriba, resto al pie del archive) pero
	 * imprime las DOS mitades en la misma página, así que declarar el texto
	 * completo describe lo que el lector ve. Se limpia el HTML porque
	 * `description` espera texto, no marcado.
	 *
	 * @param object $term Término consultado.
	 */
	private function term_description( object $term ): string {
		$raw = isset( $term->description ) ? (string) $term->description : '';
		if ( '' === $raw ) {
			return '';
		}

		$text = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $raw ) : strip_tags( $raw ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- fallback sólo si WP no está cargado.

		// Los saltos internos son maquetación de la descripción, no del dato.
		$text = (string) preg_replace( '/\s+/u', ' ', $text );

		return trim( $text );
	}

	/**
	 * Editoriales de la serie como entidades `Organization`.
	 *
	 * Nombre y `term_id` salen del agregado; el enlace se resuelve igual que en
	 * el renglón visible (`SeriesFacts::term_markup()`), y si `get_term_link()`
	 * no devuelve un string se declara la organización sin `url` — el dato que se
	 * promete es la editorial, no su enlace.
	 *
	 * @param array<int,array{name:string,term_id:int}> $brands Editoriales del agregado.
	 * @return array<int,array<string,string>>
	 */
	private function build_publishers( array $brands ): array {
		$publishers = array();

		foreach ( $brands as $brand ) {
			$name = trim( (string) ( $brand['name'] ?? '' ) );
			if ( '' === $name ) {
				continue;
			}

			$organization = array(
				'@type' => 'Organization',
				'name'  => $name,
			);

			$term_id = (int) ( $brand['term_id'] ?? 0 );
			if ( $term_id > 0 && function_exists( 'get_term_link' ) ) {
				$link = get_term_link( $term_id, self::BRAND_TAXONOMY );
				if ( is_string( $link ) && '' !== $link ) {
					$organization['url'] = $link;
				}
			}

			$publishers[] = $organization;
		}

		return $publishers;
	}

	/**
	 * Autores del agregado como nodos `Person`, espejo de `build_publishers()`.
	 *
	 * Mismo contrato: el dato prometido es el autor; el enlace a su archive
	 * `pa_autor` sólo se añade si `get_term_link()` devuelve un string.
	 *
	 * @param array<int,array{name:string,term_id:int}> $authors Autores del agregado.
	 * @return array<int,array<string,string>>
	 *
	 * @since 1.105.0
	 */
	private function build_authors( array $authors ): array {
		$people = array();

		foreach ( $authors as $author ) {
			$name = trim( (string) ( $author['name'] ?? '' ) );
			if ( '' === $name ) {
				continue;
			}

			$person = array(
				'@type' => 'Person',
				'name'  => $name,
			);

			$term_id = (int) ( $author['term_id'] ?? 0 );
			if ( $term_id > 0 && function_exists( 'get_term_link' ) ) {
				$link = get_term_link( $term_id, self::AUTHOR_TAXONOMY );
				if ( is_string( $link ) && '' !== $link ) {
					$person['url'] = $link;
				}
			}

			$people[] = $person;
		}

		return $people;
	}

	/**
	 * Cuelga la entidad de la `CollectionPage` que Rank Math ya emite.
	 *
	 * Sin esto el nodo quedaría flotando en el `@graph` sin que nada diga de qué
	 * trata la página. `mainEntity` es la propiedad documentada de `WebPage` para
	 * exactamente eso ("Indicates the primary entity described in some page") y
	 * es cierta: la landing `/serie/<slug>/` trata, primariamente, de esa serie.
	 *
	 * Se busca por `@type` y no por la clave del array (`WebPage` en el código de
	 * Rank Math) por el mismo criterio que usa `ProductSchemaShopping`: la clave
	 * es un detalle interno de otro plugin y el tipo es el contrato público. Y no
	 * se pisa un `mainEntity` ajeno: Rank Math conserva la propiedad de su nodo.
	 *
	 * @param array<string,mixed> $data Nodos JSON-LD de Rank Math.
	 * @param string              $url  `@id` de la entidad de serie.
	 * @return array<string,mixed>
	 */
	private function point_collection_page_at( array $data, string $url ): array {
		foreach ( $data as &$node ) {
			if ( ! is_array( $node ) || isset( $node['mainEntity'] ) ) {
				continue;
			}

			if ( SchemaGraph::node_has_type( $node, 'CollectionPage' ) || SchemaGraph::node_has_type( $node, 'WebPage' ) ) {
				$node['mainEntity'] = array( '@id' => self::entity_id( $url ) );
				unset( $node );
				return $data;
			}
		}
		unset( $node );

		return $data;
	}

	/**
	 * @param array<string,mixed> $node Nodo JSON-LD.
	 */
}
