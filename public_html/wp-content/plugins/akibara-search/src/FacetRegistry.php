<?php
/**
 * FacetRegistry — definición canónica de las facetas del catálogo.
 *
 * Define el conjunto base de facetas soportadas para filtrado de catálogo.
 * La interfaz administrativa persiste personalizaciones en la option `akb_search_facets_config`
 * con la misma estructura, fusionándose por nombre de faceta.
 *
 * Los componentes de indexación (`DocumentBuilder`) y cómputo de conteos (`FacetService`)
 * consumen esta clase de forma centralizada.
 *
 * Rangos de precio: se resuelven en tiempo de consulta mediante cláusulas BETWEEN
 * sobre `price_effective`/`value_num` sin requerir reindexado de productos.
 *
 * @package Akibara\Search
 */

declare( strict_types = 1 );

namespace Akibara\Search;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.55.0
 */
final class FacetRegistry {

	/**
	 * Option de overrides del panel administrativo.
	 *
	 * Nota: se utiliza `akb_search_facets_config` para evitar colisión con el flag
	 * booleano general de activación del facetado (`Search::OPTION_FACETS`).
	 */
	public const OPTION_KEY = 'akb_search_facets_config';

	/**
	 * Longitud máxima permitida para la etiqueta visible de un filtro (caracteres).
	 * Valida tanto en la persistencia del panel como en la lectura en runtime.
	 */
	public const MAX_LABEL_LENGTH = 100;

	/**
	 * Longitud máxima del nombre canónico de un filtro (slug y parámetro de URL pública).
	 * Se aplica de forma estricta tanto al guardar en administración como al leer en runtime
	 * para garantizar consistencia estructural en la configuración de facetas.
	 */
	public const MAX_NAME_LENGTH = 64;
	public const MAX_FACETS      = 32;

	/**
	 * ¿Este nombre de filtro es válido? La ÚNICA definición: la usan el
	 * guardado del panel y el lector de la configuración.
	 *
	 * @param mixed $name Clave candidata.
	 * @return bool
	 */
	public static function name_is_valid( $name ): bool {
		return is_string( $name ) && 1 === preg_match( '/^[a-z0-9_\-]{1,' . self::MAX_NAME_LENGTH . '}$/D', $name );
	}

	/**
	 * Etiqueta de filtro lista para guardar: limpia y recortada al máximo.
	 *
	 * Se RECORTA en vez de descartar: es un texto de pantalla, y perder la
	 * etiqueta entera por una letra de más sería peor que cortarla.
	 *
	 * @param string $raw Texto del panel.
	 * @return string Etiqueta, o '' si no queda nada.
	 */
	public static function sanitize_label( string $raw ): string {
		return trim( mb_substr( trim( sanitize_text_field( $raw ) ), 0, self::MAX_LABEL_LENGTH ) );
	}

	/**
	 * Override editable de los buckets de precio (editor del admin, bloque 2).
	 * Se resuelve query-time con `precio_buckets()`; NO exige reindex porque los
	 * buckets son BETWEEN sobre `value_num` (ya materializado). Ausente = usa la
	 * const `PRECIO_BUCKETS`.
	 */
	public const OPTION_PRECIO_BUCKETS = 'akb_search_precio_buckets';

	/**
	 * Ancho mínimo (en pesos) de un tramo con techo. Igual a la grilla de la
	 * derivación automática (`PriceBuckets::STEP` = 1000) y estrictamente mayor
	 * que el epsilon de display del label (`PriceBuckets::LABEL_EPS` = 10), de
	 * modo que `derive_label()` (que muestra `max − LABEL_EPS`) NUNCA pueda
	 * renderizar un rango reversado o degenerado. Aplica a los tramos acotados
	 * (`[10000,10005)` daría «$10.000 – $9.995») Y al primero sin piso
	 * (`[null,5)` daría «Hasta $-5» — su ancho efectivo arranca en 0, así que
	 * el guard exige `max` ≥ grilla). AUTO siempre produce cortes ≥ STEP; esto
	 * solo puede violarse con curaduría manual absurda, y el validador la
	 * rechaza en ambos caminos.
	 */
	private const PRECIO_MIN_WIDTH = 1000;

	/** @var array<string, array<string, mixed>>|null Memo por request de definitions(). */
	private static ?array $definitions_cache = null;

	/** @var array<int, array{min: int|null, max: int|null, label: string}>|null Memo por request de precio_buckets(). */
	private static ?array $precio_buckets_cache = null;

	/** @var array<string, int> Memo por request de la resolución slug → term_id estructural. */
	private static array $structural_terms_cache = array();

	/** Autoridad request-local de las lecturas de términos estructurales. */
	private static bool $available = true;

	/** Primer error de término estructural observado en el request. */
	private static string $read_error = '';

	/**
	 * Resetea los memos. La admin UI lo llama al guardar facetas o buckets de
	 * precio; los tests, en setUp para aislarse entre sí.
	 *
	 * @return void
	 */
	public static function reset_cache(): void {
		self::invalidate_runtime_memo();
		self::$available              = true;
		self::$read_error             = '';
	}

	/**
	 * Invalida sólo valores derivados, preservando el latch de autoridad. La
	 * adopción de ServingLease la usa para descartar memos A antes de instalar
	 * la foto B sin revivir un fallo estructural ya observado.
	 */
	public static function invalidate_runtime_memo(): void {
		self::$definitions_cache      = null;
		self::$precio_buckets_cache   = null;
		self::$structural_terms_cache = array();
	}

	/** Indica si las fuentes dinámicas del registry fueron autoritativas. */
	public static function is_available(): bool {
		return self::$available && SemanticPublication::authoritative_available();
	}

	/** Devuelve el primer error de lectura del registry en este request. */
	public static function last_error(): string {
		return '' !== self::$read_error ? self::$read_error : SemanticPublication::authoritative_error();
	}

	/**
	 * Buckets de precio VIGENTES: override del admin si existe y es válido, si no
	 * el default de la const. Memo por request (se llama desde price_counts y
	 * price_join_condition, varias veces por render). Invalidado por reset_cache().
	 *
	 * La option ya se guarda validada (handle_save_precios), pero se re-valida al
	 * leer como cinturón-y-tiradores: una option corrupta (edición directa de DB,
	 * downgrade) cae al default en vez de romper la faceta.
	 *
	 * El LABEL se re-deriva del par min/max en la lectura para garantizar consistencia
	 * estricta con los umbrales numéricos de filtrado (`derive_label()` es una función pura).
	 *
	 * @return array<int, array{min: int|null, max: int|null, label: string}>
	 */
	public static function precio_buckets(): array {
		if ( null !== self::$precio_buckets_cache ) {
			return self::$precio_buckets_cache;
		}

		if ( ! function_exists( 'get_option' ) ) {
			return self::$precio_buckets_cache = self::PRECIO_BUCKETS;
		}

		$stored = SemanticPublication::authoritative_value( self::OPTION_PRECIO_BUCKETS, null );
		if ( ! SemanticPublication::authoritative_available() ) {
			self::mark_unavailable( SemanticPublication::authoritative_error() );
			return self::$precio_buckets_cache = self::PRECIO_BUCKETS;
		}
		if ( is_array( $stored ) && null === self::validate_precio_buckets( $stored ) ) {
			$rows = array();
			foreach ( array_values( $stored ) as $row ) {
				$min    = $row['min'] ?? null;
				$max    = $row['max'] ?? null;
				$rows[] = array(
					'min'   => $min,
					'max'   => $max,
					'label' => PriceBuckets::derive_label( $min, $max ),
				);
			}
			return self::$precio_buckets_cache = $rows;
		}

		return self::$precio_buckets_cache = self::PRECIO_BUCKETS;
	}

	/**
	 * ¿Hay buckets de precio UTILIZABLES (derivados del catálogo o curados)?
	 * True solo si la option existe y es válida. Gobierna la visibilidad de la
	 * faceta Y su cómputo (counts/join) [gate DISEÑO v3.1 §5 + nit 2]: sin
	 * buckets reales la faceta se OCULTA — la const es red de NO-CRASH para
	 * quien llame `precio_buckets()` igual, nunca fuente visible ni filtrante.
	 *
	 * Sin WP (harness de reflexión) devuelve true: ahí la const ES el dato de
	 * trabajo de los tests.
	 *
	 * @return bool
	 */
	public static function has_precio_buckets(): bool {
		if ( ! function_exists( 'get_option' ) ) {
			return true;
		}
		$stored = SemanticPublication::authoritative_value( self::OPTION_PRECIO_BUCKETS, null );
		if ( ! SemanticPublication::authoritative_available() ) {
			self::mark_unavailable( SemanticPublication::authoritative_error() );
			return false;
		}
		return is_array( $stored ) && null === self::validate_precio_buckets( $stored );
	}

	/**
	 * Valida un set de buckets de precio (PURA — sin WP). Contrato del motor:
	 * rangos SEMI-ABIERTOS `[min, max)` contiguos que cubren todo el continuo sin
	 * huecos ni solapes. Devuelve null si es válido, o un código de error.
	 *
	 * Reglas (gate DISEÑO v2):
	 *   - 2 a 8 filas.
	 *   - Cada fila: label no vacío; min/max enteros ≥ 0 o null.
	 *   - Nulls POSICIONALES: min=null solo en la 1ª fila; max=null solo en la última.
	 *   - min < max dentro de cada fila (rechaza min==max = bucket vacío).
	 *   - Contigüidad EXCLUSIVA: max_i == min_{i+1} (rechaza el patrón inclusivo
	 *     9990/10000 que deja huérfanos 9991-9999 — bug m6).
	 *
	 * @param array<int, mixed> $rows Filas candidatas.
	 * @return string|null Código de error, o null si es válido.
	 */
	public static function validate_precio_buckets( array $rows ): ?string {
		$rows  = array_values( $rows );
		$count = count( $rows );
		if ( $count < 2 || $count > 8 ) {
			return 'count';
		}

		$last = $count - 1;
		foreach ( $rows as $i => $row ) {
			if ( ! is_array( $row ) ) {
				return 'shape';
			}
			$min   = $row['min'] ?? null;
			$max   = $row['max'] ?? null;
			$label = isset( $row['label'] ) ? trim( (string) $row['label'] ) : '';

			if ( '' === $label ) {
				return 'label';
			}
			// Nulls posicionales.
			if ( null === $min && 0 !== $i ) {
				return 'min_null';
			}
			if ( null === $max && $last !== $i ) {
				return 'max_null';
			}
			// Tipos: enteros ≥ 0 cuando no son null.
			if ( null !== $min && ( ! is_int( $min ) || $min < 0 ) ) {
				return 'min_type';
			}
			if ( null !== $max && ( ! is_int( $max ) || $max < 0 ) ) {
				return 'max_type';
			}
			// min < max (rechaza min==max).
			if ( null !== $min && null !== $max && $min >= $max ) {
				return 'order';
			}
			// Ancho mínimo: un tramo acotado más angosto que la grilla haría que
			// `derive_label` (max − LABEL_EPS) muestre un rango reversado o
			// degenerado. AUTO nunca lo produce; bloquea la curaduría manual
			// absurda para que la etiqueta jamás mienta sobre el corte.
			if ( null !== $min && null !== $max && ( $max - $min ) < self::PRECIO_MIN_WIDTH ) {
				return 'width';
			}
			// Primer tramo [null, max): su label también resta LABEL_EPS del max
			// («Hasta $X»), así que un max minúsculo rendería «Hasta $-5» /
			// «Hasta $0». El ancho efectivo va desde 0: exigir max ≥ grilla.
			// (El último tramo [min, null) no resta EPS — «$X o más» — no reversa.)
			if ( null === $min && null !== $max && $max < self::PRECIO_MIN_WIDTH ) {
				return 'width';
			}
			// Contigüidad exclusiva con la fila previa.
			if ( $i > 0 ) {
				$prev_max = $rows[ $i - 1 ]['max'] ?? null;
				if ( $prev_max !== $min ) {
					return 'gap';
				}
			}
		}

		return null;
	}

	/**
	 * Term IDs estructurales de product_cat (censo FacetWP: `parent_term`).
	 * Docker es réplica de prod ⇒ mismos IDs. Si el árbol cambia, el
	 * Reconciler acusa divergencia de counts en su spot-check.
	 *
	 * Ya NO se consumen crudos: `structural_term()` resuelve por SLUG (estable
	 * frente a un restore/migración que renumere) y usa estas consts sólo como
	 * fallback y como contrato de tests.
	 */
	public const TERM_MANGA  = 28;
	public const TERM_COMICS = 81;

	/** Slugs de los términos estructurales — la clave de resolución real. */
	private const SLUG_MANGA  = 'manga';
	private const SLUG_COMICS = 'comics';

	/**
	 * term_id estructural resuelto por slug, con la const como red. Memoizado
	 * por request: `seed()` corre dentro de `definitions()` (ya memoizado) y
	 * `is_excluded_root_term()` se llama por VALOR en normalize_filters, así que
	 * sin memo serían N lecturas de término por request.
	 *
	 * El fallback NO se memoiza a propósito: si la resolución corre antes de que
	 * WooCommerce registre `product_cat` (bootstrap en plugins_loaded 15, las
	 * taxonomías llegan en `init`), congelarlo dejaría el request entero con el
	 * literal aunque la taxonomía apareciera después.
	 *
	 * @param string $slug     Slug del término estructural.
	 * @param int    $fallback term_id literal del censo.
	 * @return int
	 */
	private static function structural_term( string $slug, int $fallback ): int {
		if ( isset( self::$structural_terms_cache[ $slug ] ) ) {
			return self::$structural_terms_cache[ $slug ];
		}
		if ( ! self::$available ) {
			return $fallback;
		}
		if ( ! function_exists( 'taxonomy_exists' ) || ! taxonomy_exists( 'product_cat' ) ) {
			return $fallback;
		}
		$term = self::authoritative_term_by_slug( $slug, 'product_cat' );
		if ( ! self::$available ) {
			return $fallback;
		}
		if ( ! is_array( $term ) ) {
			self::mark_unavailable( 'Structural product category is missing.' );
			return $fallback;
		}

		return self::$structural_terms_cache[ $slug ] = (int) $term['term_id'];
	}

	/**
	 * Lector uncached y sticky para slug → término. `false` es ausencia real;
	 * `null` significa que la autoridad quedó cerrada para todo el request.
	 *
	 * @return array{term_id:int,taxonomy:string,parent:int,slug:string,name:string}|false|null
	 */
	public static function authoritative_term_by_slug( string $slug, string $taxonomy ) {
		if ( ! self::$available ) {
			return null;
		}
		if ( '' === $slug || '' === $taxonomy ) {
			self::mark_unavailable( 'Term slug reader is unavailable.' );
			return null;
		}

		global $wpdb;
		if ( ! is_object( $wpdb ) || ! isset( $wpdb->terms, $wpdb->term_taxonomy )
			|| ! is_callable( array( $wpdb, 'prepare' ) ) || ! is_callable( array( $wpdb, 'get_results' ) ) ) {
			self::mark_unavailable( 'Term slug tables are unavailable.' );
			return null;
		}
		if ( is_object( $wpdb ) && property_exists( $wpdb, 'last_error' ) ) {
			$wpdb->last_error = '';
		}
		try {
			$sql = $wpdb->prepare(
				"SELECT t.term_id, t.name, t.slug, tt.taxonomy, tt.parent
				FROM {$wpdb->terms} t
				INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
				WHERE tt.taxonomy = %s AND t.slug = %s
				ORDER BY t.term_id ASC
				LIMIT 2",
				$taxonomy,
				$slug
			);
			$terms = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql viene de $wpdb->prepare() diez líneas arriba; taxonomía y slug viajan por marcador.
		} catch ( \Throwable $exception ) {
			self::mark_unavailable( $exception->getMessage() ?: 'Could not read term by slug.' );
			return null;
		}
		$database_error = is_object( $wpdb ) && property_exists( $wpdb, 'last_error' )
			? trim( (string) $wpdb->last_error )
			: '';
		if ( '' !== $database_error || ! is_array( $terms ) ) {
			self::mark_unavailable( '' !== $database_error ? $database_error : 'Could not read term by slug.' );
			return null;
		}
		if ( array() === $terms ) {
			return false;
		}
		if ( 1 !== count( $terms ) ) {
			self::mark_unavailable( 'Term slug lookup returned a non-unique result.' );
			return null;
		}
		$term = reset( $terms );
		$term_id = is_array( $term ) && ( is_int( $term['term_id'] ?? null )
			|| ( is_string( $term['term_id'] ?? null ) && 1 === preg_match( '/^[1-9]\d*$/D', $term['term_id'] ) ) )
			? (int) $term['term_id']
			: 0;
		$parent = is_array( $term ) && ( is_int( $term['parent'] ?? null )
			|| ( is_string( $term['parent'] ?? null ) && 1 === preg_match( '/^(?:0|[1-9]\d*)$/D', $term['parent'] ) ) )
			? (int) $term['parent']
			: -1;
		if ( ! is_array( $term ) || $taxonomy !== ( $term['taxonomy'] ?? null )
			|| $slug !== ( $term['slug'] ?? null ) || $term_id <= 0 || $parent < 0
			|| ! is_string( $term['name'] ?? null ) ) {
			self::mark_unavailable( 'Term slug lookup returned an invalid shape.' );
			return null;
		}

		return array(
			'term_id'  => $term_id,
			'taxonomy' => $taxonomy,
			'parent'   => $parent,
			'slug'     => $slug,
			'name'     => $term['name'],
		);
	}

	/** Cierra el registry solo para este request; nunca persiste estado. */
	private static function mark_unavailable( string $error ): void {
		if ( self::$available ) {
			self::$read_error = $error;
		}
		self::$available = false;
	}

	/**
	 * Tiers de descuento del facet `oferta` — semántica del módulo
	 * FacetWP\Oferta que este registry reemplaza: multi-tier, un producto con
	 * 35% califica en 10/20/30. Skip 40% intencional (decisión merchandising).
	 *
	 * @var array<int, string>
	 */
	public const OFERTA_TIERS = array(
		50 => '50% o más',
		30 => '30% o más',
		20 => '20% o más',
		10 => '10% o más',
	);

	/**
	 * Buckets de precio por defecto (paridad censo facet `precio` range_list).
	 *
	 * Intervalos SEMI-ABIERTOS `[min, max)`: `min` inclusivo, `max` EXCLUSIVO e
	 * igual al `min` del bucket siguiente. Así cubren el rango continuo SIN huecos
	 * (antes `>= min AND <= max` con max=9990/14990/19990 dejaba (9990,10000) etc.
	 * fuera → un producto con precio no-990 por descuento no caía en ningún bucket,
	 * m6). El `label` es display fijo; `max` es límite técnico, no el texto.
	 * `min`/`max` null = sin tope.
	 *
	 * @var array<int, array{min: int|null, max: int|null, label: string}>
	 */
	public const PRECIO_BUCKETS = array(
		array(
			'min'   => null,
			'max'   => 10000,
			'label' => 'Hasta $9.990',
		),
		array(
			'min'   => 10000,
			'max'   => 15000,
			'label' => '$10.000 – $14.990',
		),
		array(
			'min'   => 15000,
			'max'   => 20000,
			'label' => '$15.000 – $19.990',
		),
		array(
			'min'   => 20000,
			'max'   => null,
			'label' => '$20.000 o más',
		),
	);

	/**
	 * Definición de facetas. Claves por entrada:
	 *   - label:      etiqueta UI.
	 *   - type:       taxonomy | stock | oferta | precio.
	 *   - taxonomy:   solo type=taxonomy.
	 *   - hierarchical: materializa ancestros en el índice de facetas —
	 *     un producto en "shonen" genera fila también para su ancestro "manga".
	 *   - parent_term: la faceta solo lista términos HIJOS de este term_id
	 *     (demografia = hijos de Manga, sello = hijos de Cómics).
	 *   - ui:         checkboxes | radio | alpha (render, fase UI).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function definitions(): array {
		// Memo por request: definitions() se llama muchas veces (facet_joins,
		// count_facets, apply…) y cada llamada leía la option [AUDIT perf].
		// La option no cambia a mitad de request; reset_cache() lo invalida.
		if ( null !== self::$definitions_cache ) {
			return self::$definitions_cache;
		}

		$seed = self::seed();

		if ( ! function_exists( 'get_option' ) ) {
			return self::$definitions_cache = $seed;
		}

		$overrides = SemanticPublication::authoritative_value( self::OPTION_KEY, array() );
		if ( ! SemanticPublication::authoritative_available() ) {
			self::mark_unavailable( SemanticPublication::authoritative_error() );
			return self::$definitions_cache = $seed;
		}
		if ( ! is_array( $overrides ) || empty( $overrides ) ) {
			return self::$definitions_cache = $seed;
		}

		// Merge superficial por nombre: la option puede ajustar labels/ui o
		// agregar facetas de taxonomía nuevas; no puede romper los types.
		foreach ( $overrides as $name => $def ) {
			if ( ! is_string( $name ) || ! is_array( $def ) ) {
				continue;
			}
			// Las configuraciones guardadas antes de este cambio pueden conservar
			// la etiqueta seed histórica y tapar el copy nuevo. Solo retiramos ese
			// valor legado; una etiqueta realmente personalizada sigue mandando.
			if ( 'serie' === $name && 'Serie' === ( $def['label'] ?? null ) ) {
				unset( $def['label'] );
			}
			$seed[ $name ] = array_merge( $seed[ $name ] ?? array(), $def );
		}

		return self::$definitions_cache = $seed;
	}

	/**
	 * Nombres de las facetas SEED (definidas en código). Las que no están acá
	 * son facetas registradas por el operador desde el admin (viven solo en la
	 * option de overrides y pueden eliminarse desde ahí).
	 *
	 * @since 1.58.1
	 *
	 * @return array<int, string>
	 */
	public static function seed_names(): array {
		return array_keys( self::seed() );
	}

	/**
	 * Facetas de selección única (`ui=radio`): el usuario elige UN valor. Se pasa
	 * a `FilterSet::from_query()` para colapsar su selección a un solo valor, de
	 * modo que render, chips y conteo sean coherentes con un control excluyente
	 * (sin dos radios `checked` en un `radiogroup`) [gate adversario P1].
	 *
	 * @since 1.60.0
	 *
	 * @return array<int, string>
	 */
	public static function single_select_facets(): array {
		$out = array();
		foreach ( self::definitions() as $facet => $def ) {
			if ( 'radio' === ( $def['ui'] ?? 'checkboxes' ) ) {
				$out[] = $facet;
			}
		}
		return $out;
	}

	/**
	 * True si el término raíz debe EXCLUIRSE de una faceta `root_only`: es la
	 * categoría default de WooCommerce (`default_product_cat` — OJO, no
	 * `default_category`, que es la de posts), donde caen los productos sin
	 * categorizar ("Uncategorized"). Guard anti-footgun: si el dueño repuntara
	 * la default a Manga/Cómics (consts estructurales), NO se excluye — un tipo
	 * real no puede desaparecer de la faceta en silencio.
	 *
	 * @param int $term_id term_id raíz candidato.
	 * @return bool
	 */
	public static function is_excluded_root_term( int $term_id ): bool {
		if ( ! function_exists( 'get_option' ) ) {
			return false;
		}
		$default = (int) SemanticPublication::authoritative_value( 'default_product_cat', 0 );
		if ( ! SemanticPublication::authoritative_available() ) {
			self::mark_unavailable( SemanticPublication::authoritative_error() );
			return false;
		}
		if ( $default <= 0
			|| self::structural_term( self::SLUG_MANGA, self::TERM_MANGA ) === $default
			|| self::structural_term( self::SLUG_COMICS, self::TERM_COMICS ) === $default ) {
			return false;
		}
		return $term_id === $default;
	}

	/**
	 * Seed de facetas por defecto (fuente única para definitions/seed_names).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function seed(): array {
		return array(
			'categoria'      => array(
				'label'        => 'Tipo',
				'type'         => 'taxonomy',
				'taxonomy'     => 'product_cat',
				'hierarchical' => true,
				'root_only'    => true,
				'ui'           => 'checkboxes',
			),
			'demografia'     => array(
				'label'       => 'Demografía',
				'type'        => 'taxonomy',
				'taxonomy'    => 'product_cat',
				'parent_term' => self::structural_term( self::SLUG_MANGA, self::TERM_MANGA ),
				'ui'          => 'checkboxes',
			),
			'sello'          => array(
				'label'       => 'Sello',
				'type'        => 'taxonomy',
				'taxonomy'    => 'product_cat',
				'parent_term' => self::structural_term( self::SLUG_COMICS, self::TERM_COMICS ),
				'ui'          => 'checkboxes',
			),
			'editorial'      => array(
				'label'    => 'Editorial',
				'type'     => 'taxonomy',
				'taxonomy' => 'product_brand',
				'ui'       => 'checkboxes',
			),
			'pais'           => array(
				'label'    => 'País',
				'type'     => 'taxonomy',
				'taxonomy' => 'pa_pais',
				'ui'       => 'radio',
			),
			'genero'         => array(
				'label'    => 'Género',
				'type'     => 'taxonomy',
				'taxonomy' => 'pa_genero',
				'ui'       => 'checkboxes',
			),
			'autor'          => array(
				'label'    => 'Autor',
				'type'     => 'taxonomy',
				'taxonomy' => 'pa_autor',
				'ui'       => 'alpha',
			),
			'serie'          => array(
				'label'    => 'Serie o edición',
				'type'     => 'taxonomy',
				'taxonomy' => 'pa_serie',
				'ui'       => 'alpha',
			),
			'disponibilidad' => array(
				'label' => 'Disponibilidad',
				'type'  => 'stock',
				'ui'    => 'checkboxes',
			),
			'oferta'         => array(
				'label' => 'Promociones',
				'type'  => 'oferta',
				'ui'    => 'checkboxes',
			),
			// Radio: los rangos son ANIDADOS (7 ⊂ 30 ⊂ 90 días) — combinar dos
			// es la unión = el más ancho; multi-select sería ruido semántico.
			'novedad'        => array(
				'label' => 'Novedades',
				'type'  => 'novedad',
				'ui'    => 'radio',
			),
			'precio'         => array(
				'label' => 'Precio',
				'type'  => 'precio',
				'ui'    => 'checkboxes',
			),
		);
	}

	/**
	 * Taxonomías que alimentan el índice de facetas — derivadas de las definiciones.
	 * `product_visibility` se evalúa de forma independiente en el Indexer.
	 *
	 * @return array<int, string>
	 */
	public static function indexed_taxonomies(): array {
		$taxonomies = array();
		foreach ( self::definitions() as $def ) {
			if ( 'taxonomy' === ( $def['type'] ?? '' ) && ! empty( $def['taxonomy'] ) ) {
				$taxonomies[ (string) $def['taxonomy'] ] = true;
			}
		}
		return array_keys( $taxonomies );
	}
}
