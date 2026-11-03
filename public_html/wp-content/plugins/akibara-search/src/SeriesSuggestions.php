<?php
/**
 * SeriesSuggestions — sugerencias de SERIE (`pa_serie`) para el autocomplete.
 *
 * Enriquece las respuestas del endpoint de autocompletado (`/suggest`) sugiriendo
 * landings de serie directamente cuando la intención de búsqueda apunta a una colección.
 *
 * Decisiones de diseño y rendimiento:
 *   - Opera en memoria sobre un catálogo cacheado en transient (`CACHE_TTL = 6 horas`),
 *     invalidado de forma reactiva ante hooks de términos (`created_pa_serie`, `edited_pa_serie`, etc.).
 *   - No se indexa como documento en la tabla principal de productos para preservar
 *     el modelo fuertemente tipado de productos y evitar overhead de esquema.
 *
 * Filtros de elegibilidad para sugerencias de serie:
 *   1. Términos con publicaciones activas (`hide_empty => true`).
 *   2. Términos sin directiva `noindex` en SEO (`rank_math_robots`).
 *   3. Exclusión de slugs técnicos o de pruebas (`QA_SLUG_PREFIXES`).
 *
 * Tolerancia a fallos:
 *   - Bloque aditivo no bloqueante: una degradación en la lectura de series no impide
 *     la entrega de productos en el autocompletado.
 *
 * @package Akibara\Search
 */

declare( strict_types = 1 );

namespace Akibara\Search;

use Throwable;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.1.15
 */
final class SeriesSuggestions {

	use Support\FailsClosed;

	/** Taxonomía de serie del catálogo (atributo WooCommerce). */
	public const TAXONOMY = 'pa_serie';

	/**
	 * Tope de series sugeridas: DOS, no tres.
	 *
	 * El presupuesto del panel es fijo (`max-height` en `search-suggest.css`) y el
	 * grupo de series se lo come al grupo de productos, que son los que venden.
	 * Medido a 375px con tres series: `scrollHeight` 704 contra `clientHeight`
	 * 488 — quedaban ocultas tras scroll interno tres tarjetas Y el pie «Ver los N
	 * resultados», que es la salida del panel. La justificación original del tope
	 * decía «sin desplazar las 7 tarjetas»; la medición decía lo contrario.
	 *
	 * Dos ranuras siguen cubriendo el caso real: con `MIN_VOLUMES` fuera las
	 * series de un tomo, la mediana de colisiones para consultas de 4+ caracteres
	 * es 1. El `max-height` subió en la misma pasada (480 → 560 px) para dar aire,
	 * pero NO está verificado que con dos series el pie entre siempre sin scroll
	 * interno a 375px: depende de cuántas tarjetas devuelva la consulta. Lo que sí
	 * está medido es que con TRES no entraba nunca.
	 */
	public const SUGGEST_LIMIT = 2;

	/**
	 * Mínimo de tomos para que una serie se sugiera.
	 *
	 * Una serie de UN tomo no agrupa nada: en el desplegable produce DOS filas
	 * para la misma obra —la serie y su único producto, con el mismo nombre— y
	 * su etiqueta «1 tomo» dice justo lo contrario de lo que el grupo viene a
	 * comunicar. Medido en producción: 8 series elegibles tienen `count = 1`, y
	 * con `q=ghost` salían 6 filas para 3 obras, consumiendo las tres ranuras del
	 * grupo y empujando fuera del panel las tarjetas que venden.
	 *
	 * El producto sigue apareciendo en SU grupo, que es donde se compra, y la
	 * landing sigue alcanzándose por los demás caminos.
	 */
	private const MIN_VOLUMES = 2;

	/** Espejo público del mínimo, sólo para que las pruebas lo puedan fijar. */
	public const MIN_VOLUMES_FOR_TESTS = self::MIN_VOLUMES;

	/** Meta de Rank Math que marca la landing fuera del índice. */
	private const ROBOTS_META = 'rank_math_robots';

	/** Transient del catálogo elegible (lista completa, no por query). */
	private const CACHE_KEY = 'akb_search_series_catalog';

	/**
	 * Backstop del transient: 6 h. La invalidación REAL son los hooks de
	 * término de `register()` (alta, edición, baja, recuento y termmeta); el TTL
	 * sólo cubre la edición fuera de banda (SQL directo, import) que no dispara
	 * ningún hook. Sin el TTL una serie borrada por SQL seguiría sugerida para
	 * siempre.
	 */
	private const CACHE_TTL = 21600;

	/**
	 * Prefijos de slug técnicos o de pruebas excluidos de las sugerencias públicas.
	 *
	 * @var array<int, string>
	 */
	private const QA_SLUG_PREFIXES = array(
		'qahardening',
		'qapreventa',
		'qadisponible',
		'qamanualslots',
		'productoqaakibara',
	);

	/**
	 * Catálogo elegible memoizado por request.
	 *
	 * @var array<int, array{term_id:int, name:string, name_norm:string, url:string, count:int}>|null
	 */
	private ?array $catalog = null;

	/**
	 * Invalidación del catálogo. Se registra SIEMPRE (no detrás del flag del
	 * autocomplete): si el operador renombra o borra una serie con el suggest
	 * apagado y lo enciende después, el transient viejo serviría series que ya
	 * no existen.
	 *
	 * @return void
	 */
	public function register(): void {
		// Altas/ediciones/bajas del término (hooks dinámicos por taxonomía).
		add_action( 'created_' . self::TAXONOMY, array( __CLASS__, 'flush' ) );
		add_action( 'edited_' . self::TAXONOMY, array( __CLASS__, 'flush' ) );
		add_action( 'delete_' . self::TAXONOMY, array( __CLASS__, 'flush' ) );

		// Recuento: publicar/despublicar un producto o cambiarle la serie pasa
		// por `wp_update_term_count_now()`, que dispara este hook. Es el único
		// camino que mueve el `count` del que depende la elegibilidad.
		add_action( 'edited_term_taxonomy', array( __CLASS__, 'flush_taxonomy' ), 10, 2 );

		// El noindex vive en termmeta de Rank Math: sin esto, quitarle el
		// noindex a una landing tardaba hasta 6 h en verse en el panel.
		add_action( 'added_term_meta', array( __CLASS__, 'flush_term_meta' ), 10, 3 );
		add_action( 'updated_term_meta', array( __CLASS__, 'flush_term_meta' ), 10, 3 );
		add_action( 'deleted_term_meta', array( __CLASS__, 'flush_term_meta' ), 10, 3 );
	}

	/**
	 * Series que matchean la consulta, ya ordenadas y acotadas.
	 *
	 * @param string $query Consulta cruda (o la corregida por el fuzzy).
	 * @param int    $limit Tope de sugerencias.
	 * @return array<int, array{id:int, name:string, name_html:string, url:string, count:int, meta:string}>
	 */
	public function suggest( string $query, int $limit = self::SUGGEST_LIMIT ): array {
		if ( ! $this->available || $limit <= 0 ) {
			return array();
		}

		// La MISMA normalización que el índice: minúsculas, fold de diacríticos
		// y todo lo no alfanumérico a espacio. De ahí sale gratis el requisito
		// de acentos y signos — "Ranma 1/2" → "ranma 1 2", "NieR: Automata" →
		// "nier automata", "Haikyuu!!" → "haikyuu", "Ghost in the Shell 1.5" →
		// "ghost in the shell 1 5".
		$normalized = Normalizer::normalize( $query );
		if ( strlen( $normalized ) < QueryEngine::MIN_TOKEN_LENGTH ) {
			return array();
		}

		$catalog = $this->catalog();
		if ( ! $this->available ) {
			return array();
		}

		$matches = array();
		foreach ( $catalog as $entry ) {
			$rank = self::match_rank( $entry['name_norm'], $normalized );
			if ( null === $rank ) {
				continue;
			}
			$matches[] = array( 'rank' => $rank, 'entry' => $entry );
		}
		if ( empty( $matches ) ) {
			return array();
		}

		// Orden: primero el tier de match (mismo criterio que el Scorer de
		// producto — el prefijo del nombre vale más que un match interior),
		// después la serie con más tomos publicados (más catálogo = intención
		// más probable) y por último el nombre, para que el orden sea estable
		// entre requests y el cache no dependa del orden que devolvió MySQL.
		usort(
			$matches,
			static function ( array $a, array $b ): int {
				return array( $a['rank'], -$a['entry']['count'], $a['entry']['name_norm'] )
					<=> array( $b['rank'], -$b['entry']['count'], $b['entry']['name_norm'] );
			}
		);

		$tokens = Normalizer::tokenize( $query );
		$out    = array();
		foreach ( array_slice( $matches, 0, $limit ) as $match ) {
			$entry = $match['entry'];
			$out[] = array(
				'id'        => $entry['term_id'],
				'name'      => $entry['name'],
				// Mismo contrato que `title_html` de las tarjetas: el nombre va
				// escapado por Highlighter y el cliente no vuelve a escaparlo.
				'name_html' => Highlighter::mark( $entry['name'], $tokens ),
				'url'       => $entry['url'],
				'count'     => $entry['count'],
				'meta'      => $this->volume_label( $entry['count'] ),
			);
		}

		return $this->available ? $out : array();
	}

	/**
	 * Tier de coincidencia, o null si no matchea.
	 *
	 * La regla es "la consulta empieza un token del nombre": el prefijo se
	 * evalúa contra el inicio del nombre o contra el inicio de cualquier
	 * palabra interior. Así «clim» encuentra "The Climber" (el requisito de
	 * matchear a mitad de nombre) pero «lim» no encuentra nada — un substring
	 * suelto ensancharía el match hasta volverlo ruido.
	 *
	 * Efecto lateral deseado: «one piece 3» no matchea ninguna serie (ningún
	 * nombre contiene el "3"), así que teclear un número de tomo devuelve
	 * producto, que es lo que el usuario pidió.
	 *
	 * @param string $name_norm  Nombre normalizado del término.
	 * @param string $normalized Consulta normalizada.
	 * @return int|null 0 exacto, 1 prefijo del nombre, 2 prefijo de palabra interior.
	 */
	private static function match_rank( string $name_norm, string $normalized ): ?int {
		if ( $name_norm === $normalized ) {
			return 0;
		}
		// Mismo nombre al quitar los espacios: «dandadan» para «Dan Da Dan»,
		// «bluelock» para «Blue Lock». Es la misma intención que ya cubre la
		// normalización de signos —la gente teclea el título como lo recuerda,
		// no como lo escribe la editorial— y por eso vale tier 0: no es un
		// parecido, es el mismo nombre.
		//
		// Se compara por IGUALDAD y nunca por prefijo: con prefijo, «man»
		// pasaría a casar con «chainsawman» y el tier 2 ya cubre —y acota— ese
		// caso. Medido: «dandadan» devuelve 22 tomos de la serie en el buscador
		// y sin esto no ofrecía su landing.
		if ( str_replace( ' ', '', $name_norm ) === str_replace( ' ', '', $normalized ) ) {
			return 0;
		}
		if ( 0 === strpos( $name_norm, $normalized ) ) {
			return 1;
		}
		if ( false !== strpos( $name_norm, ' ' . $normalized ) ) {
			return 2;
		}
		return null;
	}

	/**
	 * Catálogo elegible (cacheado). Cada entrada ya trae el nombre normalizado
	 * y la URL resuelta: el match por tecleo no vuelve a tocar la DB.
	 *
	 * @return array<int, array{term_id:int, name:string, name_norm:string, url:string, count:int}>
	 */
	private function catalog(): array {
		if ( null !== $this->catalog ) {
			return $this->catalog;
		}

		$cached = $this->guarded( static fn() => get_transient( self::CACHE_KEY ), 'Could not read the series catalog cache.' );
		if ( ! $this->available ) {
			return array();
		}
		if ( is_array( $cached ) && self::valid_catalog( $cached ) ) {
			$this->catalog = $cached;
			return $this->catalog;
		}

		$built = $this->build_catalog();
		if ( ! $this->available ) {
			return array();
		}

		$this->catalog = $built;

		// UN CATÁLOGO VACÍO NO SE PERSISTE.
		//
		// `build_catalog()` devuelve `array()` tanto cuando la tienda de verdad no
		// tiene series elegibles como cuando `taxonomy_exists('pa_serie')` todavía
		// es false — WooCommerce a medio arrancar durante un update, o cualquier
		// request que llegue antes de que se registren los atributos. Los dos
		// casos son indistinguibles desde acá, y el segundo dura milisegundos.
		//
		// Si ese vacío se guarda, queda 6 h: ninguno de los hooks de invalidación
		// lo levanta, porque todos disparan por EVENTOS DE TÉRMINO y en esa
		// ventana no cambió ningún término. La respuesta seguiría afirmando «no
		// hay series» —con `available` en true— en vez de degradar. Es el fail-open
		// que este plugin rechaza en todos sus otros lectores.
		//
		// El costo de no cachearlo es una consulta por request mientras de verdad
		// no haya series elegibles, que es un estado que no debería existir en
		// esta tienda (hay 104) y que se cura solo en cuanto haya una.
		if ( array() !== $built ) {
			// El cache es una optimización, no una autoridad: si el transient no
			// se puede escribir, el catálogo recién leído sigue siendo válido.
			try {
				set_transient( self::CACHE_KEY, $built, self::CACHE_TTL );
			} catch ( Throwable $throwable ) {
				unset( $throwable );
			}
		}

		return $this->catalog;
	}

	/**
	 * Lee la taxonomía y aplica los tres filtros de elegibilidad.
	 *
	 * @return array<int, array{term_id:int, name:string, name_norm:string, url:string, count:int}>
	 */
	private function build_catalog(): array {
		// Sin la taxonomía registrada no hay series: es ausencia real (WooCommerce
		// todavía no registró los atributos), no una lectura fallida.
		$exists = $this->guarded(
			static fn(): bool => function_exists( 'taxonomy_exists' ) && taxonomy_exists( self::TAXONOMY ),
			'Could not verify the series taxonomy.'
		);
		if ( ! $this->available || true !== $exists ) {
			return array();
		}

		$terms = $this->guarded(
			static fn() => get_terms(
				array(
					'taxonomy'               => self::TAXONOMY,
					// Filtro 1: `count` de la taxonomía = productos PUBLICADOS.
					'hide_empty'             => true,
					'number'                 => 0,
					'orderby'                => 'name',
					'order'                  => 'ASC',
					// La termmeta se prima acá: sin esto, el noindex costaba una
					// query por término (104 SELECT por cada rebuild del cache).
					'update_term_meta_cache' => true,
				)
			),
			'Could not read the series taxonomy.'
		);
		if ( ! $this->available ) {
			return array();
		}
		if ( ! is_array( $terms ) || ( function_exists( 'is_wp_error' ) && is_wp_error( $terms ) ) ) {
			$this->mark_unavailable( 'The series taxonomy returned an invalid shape.' );
			return array();
		}

		$catalog = array();
		foreach ( $terms as $term ) {
			if ( ! $term instanceof WP_Term ) {
				$this->mark_unavailable( 'The series taxonomy returned an invalid term.' );
				return array();
			}

			$term_id = (int) $term->term_id;
			$count   = (int) $term->count;
			$name    = trim( (string) $term->name );
			$slug    = (string) $term->slug;
			// Filtro 0: una serie de UN tomo no agrupa nada. Ver MIN_VOLUMES.
			if ( $term_id <= 0 || $count < self::MIN_VOLUMES || '' === $name ) {
				continue;
			}
			if ( self::is_qa_slug( $slug ) ) {
				continue;
			}

			$indexable = $this->is_indexable( $term_id, $slug );
			if ( ! $this->available ) {
				return array();
			}
			if ( ! $indexable ) {
				continue;
			}

			$url = $this->guarded( static fn() => get_term_link( $term ), 'Could not resolve the series landing URL.' );
			if ( ! $this->available ) {
				return array();
			}
			if ( ! is_string( $url ) || '' === trim( $url ) ) {
				// Sin permalink no hay a dónde mandar al usuario. Es un término
				// concreto que no se puede presentar, no un fallo del lote.
				continue;
			}

			$name_norm = Normalizer::normalize( $name );
			if ( '' === $name_norm ) {
				continue;
			}

			$catalog[] = array(
				'term_id'   => $term_id,
				'name'      => $name,
				'name_norm' => $name_norm,
				'url'       => $url,
				'count'     => $count,
			);
		}

		return $catalog;
	}

	/**
	 * Filtro 2: la landing no debe estar en noindex.
	 *
	 * Se LEE la termmeta de Rank Math (dato, no código de terceros) y se expone
	 * un filtro propio para que el día que el SEO cambie de plugin esta clase no
	 * tenga que enterarse.
	 *
	 * @param int    $term_id Término.
	 * @param string $slug    Slug del término (contexto del filtro).
	 * @return bool
	 */
	private function is_indexable( int $term_id, string $slug ): bool {
		$robots = $this->guarded(
			static fn() => get_term_meta( $term_id, self::ROBOTS_META, true ),
			'Could not read the series robots directive.'
		);
		if ( ! $this->available ) {
			return false;
		}

		$indexable = true;
		if ( is_array( $robots ) ) {
			$indexable = ! in_array( 'noindex', array_map( 'strval', $robots ), true );
		} elseif ( is_string( $robots ) && '' !== $robots ) {
			$indexable = false === strpos( $robots, 'noindex' );
		}

		/**
		 * Permite decidir por fuera si una landing de serie es sugerible.
		 *
		 * @since 0.1.15
		 *
		 * @param bool   $indexable Veredicto leído de la termmeta de SEO.
		 * @param int    $term_id   Término de `pa_serie`.
		 * @param string $slug      Slug del término.
		 */
		$filtered = $this->guarded(
			static fn() => apply_filters( 'akb_search_series_indexable', $indexable, $term_id, $slug ),
			'The series indexability filter is not authoritative.'
		);
		if ( ! $this->available ) {
			return false;
		}

		// Un retorno no booleano es un hook mal escrito: falla apagado (misma
		// política que `Search::filtered_boolean_option()`).
		return is_bool( $filtered ) ? $filtered : false;
	}

	/**
	 * Filtro 3: verifica si un slug corresponde a términos técnicos o de prueba.
	 *
	 * @param string $slug Slug del término.
	 * @return bool
	 */
	private static function is_qa_slug( string $slug ): bool {
		$slug = strtolower( trim( $slug ) );
		foreach ( self::QA_SLUG_PREFIXES as $prefix ) {
			if ( 0 === strpos( $slug, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * "17 tomos" — lo que diferencia una serie de un producto sin precio. Misma
	 * palabra que ya usa la ficha de la landing (`SeriesFacts`), para que el
	 * panel y el destino no se contradigan.
	 *
	 * @param int $count Productos publicados de la serie.
	 * @return string
	 */
	private function volume_label( int $count ): string {
		$formatted = $this->guarded(
			static fn() => function_exists( 'number_format_i18n' ) ? number_format_i18n( $count ) : (string) $count,
			'Could not format the series volume count.'
		);
		if ( ! $this->available || ! is_string( $formatted ) || '' === $formatted ) {
			$formatted = (string) $count;
		}

		return $formatted . ( 1 === $count ? ' tomo' : ' tomos' );
	}

	/**
	 * Un catálogo cacheado con forma inesperada (deploy viejo, transient
	 * corrupto) se descarta y se reconstruye: nunca se sirve.
	 *
	 * @param array<mixed> $catalog Valor leído del transient.
	 * @return bool
	 */
	private static function valid_catalog( array $catalog ): bool {
		$keys = array( 'count', 'name', 'name_norm', 'term_id', 'url' );
		foreach ( $catalog as $entry ) {
			if ( ! is_array( $entry ) ) {
				return false;
			}
			$entry_keys = array_keys( $entry );
			sort( $entry_keys, SORT_STRING );
			if ( $entry_keys !== $keys
				|| ! is_int( $entry['term_id'] ) || $entry['term_id'] <= 0
				|| ! is_int( $entry['count'] ) || $entry['count'] <= 0
				|| ! is_string( $entry['name'] ) || '' === $entry['name']
				|| ! is_string( $entry['name_norm'] ) || '' === $entry['name_norm']
				|| ! is_string( $entry['url'] ) || '' === $entry['url'] ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Borra el catálogo cacheado.
	 *
	 * @return void
	 */
	public static function flush(): void {
		try {
			delete_transient( self::CACHE_KEY );
		} catch ( Throwable $throwable ) {
			unset( $throwable );
		}
	}

	/**
	 * Invalida sólo si el recuento movido es de `pa_serie`.
	 *
	 * @param int    $term_taxonomy_id Relación término-taxonomía (sin uso).
	 * @param string $taxonomy         Taxonomía tocada.
	 * @return void
	 */
	public static function flush_taxonomy( $term_taxonomy_id = 0, $taxonomy = '' ): void {
		unset( $term_taxonomy_id );
		if ( self::TAXONOMY === (string) $taxonomy ) {
			self::flush();
		}
	}

	/**
	 * Invalida sólo si la termmeta tocada es la directiva de robots.
	 *
	 * @param mixed  $meta_id   Id(s) de meta (sin uso).
	 * @param mixed  $object_id Término (sin uso).
	 * @param string $meta_key  Clave escrita.
	 * @return void
	 */
	public static function flush_term_meta( $meta_id = 0, $object_id = 0, $meta_key = '' ): void {
		unset( $meta_id, $object_id );
		if ( self::ROBOTS_META === (string) $meta_key ) {
			self::flush();
		}
	}

	/**
	 * Ejecuta una lectura viva capturando su fallo.
	 *
	 * @param callable $reader   Lectura puntual.
	 * @param string   $fallback Diagnóstico si la fuente no entrega uno.
	 * @return mixed Resultado de la fuente; null tras un fallo.
	 */
	private function guarded( callable $reader, string $fallback ) {
		if ( ! $this->available ) {
			return null;
		}
		try {
			return $reader();
		} catch ( Throwable $throwable ) {
			$this->mark_unavailable( $throwable->getMessage() ?: $fallback );
			return null;
		}
	}

	/**
	 * El catálogo elegible memoizado sale del mismo conjunto de lecturas que
	 * acaba de cerrarse.
	 *
	 * @return void
	 */
	private function forget_derived_state(): void {
		$this->catalog = null;
	}
}
