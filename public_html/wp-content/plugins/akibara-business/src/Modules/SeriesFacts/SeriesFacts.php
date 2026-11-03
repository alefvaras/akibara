<?php
/**
 * Publica hechos de obra, edición y catálogo en las landings de serie.
 *
 * Los metadatos editoriales proceden del dueño; los agregados de tomos, estado,
 * precios, autor, editorial y fecha proceden del catálogo vivo. No genera prosa
 * editorial, valores supuestos ni placeholders. Sin productos publicados no
 * imprime bloque. Los ceros verdaderos de disponible y reservable se muestran;
 * Agotado se omite cuando vale cero. El vocabulario comercial es compartido.
 *
 * render_summary() presenta una línea sobre la rejilla en prioridad2 con el
 * estado de edición u obra y un enlace a la ficha completa. result_count_extra()
 * aporta el enlace estable al último tomo dentro de la línea del total. render()
 * presenta la ficha después de paginación, en prioridad20, y antes de la guía,
 * en prioridad25. El mismo orden del servidor sirve móvil y escritorio para
 * conservar foco y secuencia de lectura bajo la caché compartida.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\SeriesFacts;

use Akibara\Business\Modules\EstimatedDelivery\PedidoPolicy;
use Akibara\Business\Modules\SeriesCatalog\SeriesCatalogEligibility;
use Akibara\Business\Modules\Stock\StockStatus;
use Akibara\Business\Services\VolumeTitle;
use Akibara\Business\Services\VolumeVariant;
use Akibara\Business\Utilities\PluginAssetVersion;
use Akibara\Business\Utilities\Text;

defined( 'ABSPATH' ) || exit;

/**
 * Ficha de datos del catálogo de una serie.
 *
 * @since 1.100.0
 */
final class SeriesFacts {

	/** Taxonomía de serie. Las landings viven en sus archives. */
	private const TAXONOMY = 'pa_serie';

	/**
	 * Taxonomía de EDITORIAL — verificada, no inferida.
	 *
	 * `pa_editorial` NO EXISTE: un intento previo contra ella devolvió Array
	 * vacío y el diagnóstico natural ("faltan términos") habría sido falso. La
	 * editorial vive en `product_brand`, la taxonomía nativa de WooCommerce 9.4+
	 * (URL /marca/<slug>/). Tres evidencias independientes, todas en este repo:
	 *   - `SchemaProductRankMath\BookSchemaEnricher` construye `publisher` desde
	 *     `get_the_terms( $id, 'product_brand')`.
	 *   - `PdpEnhancer::GRID_TAXONOMIES` rotula `'product_brand' => 'Editorial'`.
	 *   - `TaxonomyDiscount\Settings` la etiqueta 'Editoriales'.
	 * Confirmado además contra la base local: `wp_term_taxonomy` tiene
	 * `product_brand` (8 términos) y ninguna fila `pa_editorial`.
	 */
	private const BRAND_TAXONOMY = 'product_brand';

	/**
	 * Taxonomía de AUTOR — verificada igual que la de editorial, no inferida.
	 *
	 * Tres evidencias independientes en este mismo repo:
	 *   - `SchemaProductRankMath\BookSchemaEnricher` construye el `author` del
	 *     schema desde `get_the_terms( $id, 'pa_autor')`.
	 *   - `PdpEnhancer::GRID_TAXONOMIES` la rotula `'pa_autor' => 'Autor'` y la
	 *     enlaza en la ficha de producto.
	 *   - `SeoEnhancements` la trata como archive de catálogo indexable.
	 * Confirmado contra la base local: 105 términos `pa_autor`, y el agregado de
	 * The Climber resuelve «Shinichi Sakamoto» (term 478). Su archive es
	 * `/autor/<slug>/`, que ya existe y ya está indexado.
	 *
	 * Sólo autor en esta pasada: cada taxonomía añadida es OTRO `LEFT JOIN` que
	 * multiplica filas (ver `query_row()`), y de las cuatro que la PDP enlaza
	 * ésta es la única con intención de lectura fuerte — quien terminó una obra
	 * busca al mismo autor, no "otra cosa del mismo formato".
	 */
	private const AUTHOR_TAXONOMY = 'pa_autor';

	/**
	 * Visibilidad nativa de WooCommerce.
	 *
	 * La grilla de abajo la sirve el main query de WooCommerce, que excluye los
	 * productos marcados `exclude-from-catalog`. Si esta consulta no la excluyera
	 * también, el primer tomo que se oculte del catálogo dejaría el desglose de
	 * arriba sumando un tomo más que el contador de resultados de abajo. Hoy no
	 * divergen (0 términos asignados en la base), pero un bloque cuyo contrato es
	 * "todo lo que dice es verificable" no puede depender de que eso siga así.
	 */
	/**
	 * Prefijo versionado del transient; el sufijo identifica term_taxonomy_id.
	 *
	 * La versión debe cambiar cuando cambia la forma de shape() o la semántica de
	 * sus claves, para no mezclar agregados incompatibles durante su TTL de seis
	 * horas. Incluye identidades de términos, conteos de tomos frente a productos,
	 * variantes, autores, agotados y último tomo. Los metadatos de obra/edición que
	 * se leen frescos del término no forman parte del agregado cacheado y no
	 * requieren por sí solos cambiar esta versión.
	 */
	private const TRANSIENT_PREFIX = 'akb_series_facts_v5_';

	/** Ancla de la ficha completa: el resumen de arriba enlaza aquí. */
	public const SHEET_ID = 'ficha-de-la-serie';

	/** Texto del enlace del resumen a la ficha (Akibara → Textos). */
	public const OPTION_SUMMARY_LINK = 'akb_copy_series_summary_link';

	/** El de fábrica. */
	public const DEFAULT_SUMMARY_LINK = 'Ver ficha completa';

	/**
	 * Texto del atajo a la ficha del tomo más nuevo (Akibara → Textos). Va junto
	 * al total de productos, sobre la rejilla; ver `result_count_extra()`.
	 */
	public const OPTION_LATEST_VOLUME = 'akb_copy_series_latest_volume';

	/** El de fábrica. */
	public const DEFAULT_LATEST_VOLUME = 'Último tomo: {tomo}';

	/**
	 * Hueco del número del tomo en el atajo. NUNCA `sprintf`: el texto lo edita
	 * el dueño y un «%» suelto sería un fatal en todas las landings (mismo
	 * criterio que `SeriesBundles\LandingOffer::PCT_MARKER`).
	 */
	public const VOLUME_MARKER = '{tomo}';

	/** Generación global excepcional para invalidar facts sin conocer sus TTID. */
	private const CACHE_GENERATION_OPTION = 'akb_series_facts_generation';

	/** Prefijo de la generación durable y no autoload de cada serie. */
	private const SERIES_CACHE_GENERATION_OPTION_PREFIX = 'akb_series_facts_generation_';

	/** Generación leída o rotada en este request. */
	private ?string $cache_generation = null;

	/** @var array<int,string> Generación por term_taxonomy_id leída en el request. */
	private array $series_cache_generations = array();

	/** @var array<int,int> Series que deben recibir una segunda rotación al cierre. */
	private array $pending_final_series_invalidations = array();

	/** @var array<int,true> Primera rotación ya hecha en este request. */
	private array $initial_series_invalidations = array();

	/** Evita registrar más de un finalizador de generaciones. */
	private bool $generation_finalizer_registered = false;

	/** Una falla sin identidad exige otra rotación global al cierre. */
	private bool $pending_global_generation_finalize = false;

	/** Coalesce el fallback global cuando varias consultas fallan juntas. */
	private bool $fallback_invalidation_triggered = false;

	/**
	 * Memoiza hechos verificados por serie, petición y conexión de base de datos.
	 *
	 * La primera lectura verifica la generación durable antes y después del dato.
	 * Las siguientes sólo reutilizan el memo si esa generación sigue vigente: una
	 * venta o reposición desde otra petición puede cambiarla durante el render.
	 * Esta comprobación protege también el contenido que irá a page cache.
	 *
	 * WeakMap comparte datos entre instancias efímeras sin cruzar conexiones wpdb.
	 * La invalidación local descarta la serie o el conjunto global para que un
	 * guardado seguido de render en la misma petición lea el estado nuevo.
	 *
	 * @var \WeakMap<object, array<int, array{generation: string, facts: array<string, mixed>}>>|null
	 */
	private static ?\WeakMap $verified_facts = null;

	/**
	 * Separador ENTRE elementos de una lista del `GROUP_CONCAT` (Record
	 * Separator, 0x1E). Lo comparten las tres listas del agregado: editoriales,
	 * autores y títulos de los tomos agotados.
	 *
	 * Un carácter de control y no `|`: los nombres de editorial y de autor —y
	 * los títulos de los tomos— los escribe el dueño desde el admin, y un
	 * separador imprimible es un nombre válido esperando a partirse en dos.
	 */
	private const LIST_SEPARATOR = "\x1e";

	/**
	 * Separador DENTRO de cada par nombre/`term_id` (Unit Separator, 0x1F). Lo
	 * usan editoriales y autores; la lista de títulos agotados no es de pares.
	 *
	 * El id viaja pegado al nombre en el MISMO `GROUP_CONCAT` y no en una
	 * segunda columna: dos listas paralelas ordenadas por separado pueden
	 * desalinearse en cuanto una serie tenga más de una editorial —o más de un
	 * autor, que acá es lo habitual: 12 series del catálogo local tienen entre 2
	 * y 10—, y ahí el renglón enlazaría el nombre de uno a la landing de otro:
	 * un error que ninguna prueba de conteo detecta y que un lector sí.
	 *
	 * El nombre va PRIMERO en el par a propósito: MySQL exige que el `ORDER BY`
	 * de un `GROUP_CONCAT(DISTINCT …)` sea la misma expresión que se concatena,
	 * así que ordenar el par ordena por nombre — el criterio que ya tenía este
	 * renglón — sin necesitar una expresión de orden aparte.
	 */
	private const PAIR_SEPARATOR = "\x1f";

	/**
	 * Gramática del número de tomo, COPIADA del theme — no importada.
	 *
	 * El parser vive en `akibara-gp/inc/title-parser.php:52`
	 * (`akb_loop_title_markup()`), que es quien pinta el número amarillo de las
	 * tarjetas de la rejilla. No se puede llamar desde acá: este plugin debe seguir
	 * funcionando con otro theme, y una dependencia en ese sentido convertiría
	 * un cambio de theme en un fatal. Lo que se copia es la GRAMÁTICA —"todo lo
	 * que precede + espacio + dígitos finales"— para que el número que este
	 * renglón nombra sea EXACTAMENTE el que el lector ve estampado en la portada:
	 * si las dos divergieran, la ficha diría "03" y la card mostraría otra cosa.
	 *
	 * Los dígitos se publican TAL CUAL vienen del título ("03", no "3"): el
	 * catálogo los escribe con padding —de eso vive el orden alfabético del
	 * archive, ver `akibara-business.php:366-368`— y la card los estampa con
	 * padding. Normalizarlos acá obligaría al lector a traducir.
	 */
	private const VOLUME_PATTERN = '/^(.*\S)\s+(\d+)\s*$/u';

	/**
	 * Cuántos números de tomo agotado se nombran antes de resumir.
	 *
	 * Claymore tiene 19 agotados de 27 tomos: listarlos todos convierte un dato
	 * en un párrafo dentro de un `<dd>` de ~140 px (la rejilla de la ficha es de
	 * 2 columnas en móvil, donde está el 72,5% del tráfico). Se nombran los
	 * PRIMEROS —el orden es numérico ascendente— porque la pregunta que decide la
	 * compra es "¿puedo empezar?", y el resto se resume en "y N más", que sigue
	 * siendo un hecho exacto.
	 */
	private const MAX_LISTED_VOLUMES = 6;

	/**
	 * Cuántos autores se enlazan antes de resumir.
	 *
	 * Mucho más bajo que el de tomos porque acá cada elemento es un nombre
	 * completo dentro de un enlace, no dos dígitos. El catálogo local tiene una
	 * serie con 10 autores (Hellblazer) y otra con 5 (La Cosa del Pantano): sin
	 * tope, esa fila sola mediría más que las otras cinco juntas.
	 */
	private const MAX_LISTED_AUTHORS = 3;

	/**
	 * Umbral de sospecha de truncamiento del `GROUP_CONCAT` de títulos, en bytes.
	 *
	 * `group_concat_max_len` recorta el resultado SIN error y sin aviso. El
	 * MariaDB local reporta 1 MB, pero el default histórico de MySQL es 1024 y el
	 * valor de producción no es verificable desde acá, así que el guard se aplica
	 * igual. Importa sólo en esta lista: las de editoriales y autores tienen
	 * cardinalidad de una decena, mientras la de títulos puede llegar a los 107
	 * tomos de One Piece.
	 *
	 * Por qué el conteo no basta como única defensa: si el corte cae DENTRO del
	 * último título sin eliminar ninguno, quedan tantos elementos como agotados
	 * hay y el último podría seguir matcheando la gramática con un número
	 * mutilado ("The Climber 1" por "The Climber 11"). Publicar el tomo
	 * equivocado es peor que no publicar ninguno: al cortar por longitud, esa
	 * rama no existe.
	 */
	private const CONCAT_SAFE_BYTES = 1000;

	/**
	 * Predicado inyectado: ¿hay un override de precio en runtime?
	 *
	 * `wc_product_meta_lookup` guarda el precio que WooCommerce materializa al
	 * guardar el producto, leído en contexto `edit` — o sea, SIN los filtros de
	 * `woocommerce_product_get_price`. El descuento editorial de
	 * `TaxonomyDiscount` es filter-only y a propósito no persiste `_sale_price`
	 * (ver `TaxonomyDiscount\PriceFilters`), así que mientras una campaña esté
	 * vigente el rango de la lookup es el precio SIN descuento y las cards de
	 * abajo muestran otro número. El mismo problema obligó a Search a publicar
	 * su propio `akb_search_effective_price` en vez de leer la lookup.
	 *
	 * Anunciar "desde $9.500" sobre una grilla que dice $8.075 no es un dato
	 * inventado, pero es un dato falso para quien lo lee. Cuando el predicado
	 * dice que hay override, el rango simplemente no se imprime: el resto de la
	 * ficha (conteos, editorial, fecha) es indiferente al descuento y sigue.
	 *
	 * Se inyecta en vez de consultarse acá para no duplicar la semántica de
	 * vigencia (switch + ventana de fechas) que ya vive en
	 * `TaxonomyDiscount\Settings::is_enabled()`; `Plugin.php` cablea la MISMA
	 * instancia que usan los filtros de precio. `null` = nadie declaró override
	 * → la lookup manda.
	 *
	 * @var callable|null
	 */
	private $price_is_overridden;

	/**
	 * @param callable|null $price_is_overridden Devuelve true si algún filtro de
	 *                                           runtime está alterando el precio
	 *                                           del catálogo. Ver la propiedad.
	 */
	public function __construct( ?callable $price_is_overridden = null ) {
		$this->price_is_overridden = $price_is_overridden;
	}

	/**
	 * option → de fábrica, para CopyManager (exposición y saneado obligatorio).
	 *
	 * @return array<string, string>
	 */
	public static function copy_defaults(): array {
		return array(
			self::OPTION_SUMMARY_LINK  => self::DEFAULT_SUMMARY_LINK,
			self::OPTION_LATEST_VOLUME => self::DEFAULT_LATEST_VOLUME,
		);
	}

	/**
	 * Registra resumen, ficha completa, hoja propia e invalidación de sus hechos.
	 *
	 * series-facts.css se encola sólo en landings y no depende de la hoja de otro
	 * módulo. El transient de seis horas puede sobrevivir a una purga de página;
	 * por ello una mutación de catálogo debe invalidar el agregado además del HTML,
	 * para que los conteos concuerden con las tarjetas tras una venta o reposición.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'woocommerce_before_shop_loop', array( $this, 'render_summary' ), 2 );
		add_action( 'woocommerce_after_shop_loop', array( $this, 'render' ), 20 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// El atajo al último tomo viaja en la línea del total, que imprime el
		// buscador (ver `result_count_extra()`). Sin el buscador, o con uno que
		// aún no ofrece el filtro, simplemente no sale: no hay clase del otro
		// plugin de la que depender.
		add_filter( 'akb_search_result_count_extra', array( $this, 'result_count_extra' ) );

		// Ver `allowlist_ucss_selectors()`. Mismo idioma y misma prioridad que
		// Wishlist, LoopEnhancer, BackInStock y PreventaUX: cada módulo protege
		// SUS clases condicionales, en vez de una lista central que hay que
		// acordarse de tocar desde otro archivo.
		add_filter( 'litespeed_ucss_whitelist', array( $this, 'allowlist_ucss_selectors' ), 20 );

		// Stock: las dos vías por las que WooCommerce mueve un producto de
		// estado (una venta, una reposición, un cambio manual).
		add_action( 'woocommerce_product_set_stock_status', array( $this, 'invalidate_product' ), 10, 1 );
		add_action( 'woocommerce_product_set_stock', array( $this, 'invalidate_product_object' ), 10, 1 );

		// Contenido: alta, edición, precio, papelera y borrado definitivo.
		add_action( 'save_post_product', array( $this, 'invalidate_product' ), 10, 1 );
		add_action( 'before_delete_post', array( $this, 'invalidate_product' ), 10, 1 );
		add_action( 'added_post_meta', array( $this, 'invalidate_on_product_meta_changed' ), 10, 3 );
		add_action( 'updated_post_meta', array( $this, 'invalidate_on_product_meta_changed' ), 10, 3 );
		add_action( 'deleted_post_meta', array( $this, 'invalidate_on_product_meta_changed' ), 10, 3 );

		// Reasignación de serie: se invalidan la nueva Y la vieja, porque un
		// tomo que cambia de serie altera el conteo de las dos.
		add_action( 'set_object_terms', array( $this, 'invalidate_on_term_change' ), 10, 6 );
		add_action( 'deleted_term_relationships', array( $this, 'invalidate_on_deleted_term_relationships' ), 10, 3 );

		// Nombre y slug de autor/editorial también forman parte de la ficha y del
		// ComicSeries. Editar el término no guarda ningún producto, por lo que los
		// hooks anteriores no alcanzan esta ruta administrativa/API.
		add_action( 'edited_' . self::BRAND_TAXONOMY, array( $this, 'invalidate_on_catalog_term_edited' ), 10, 2 );
		add_action( 'edited_' . self::AUTHOR_TAXONOMY, array( $this, 'invalidate_on_catalog_term_edited' ), 10, 2 );
	}

	/**
	 * Clases de la ficha que el UCSS de LiteSpeed NO debe podar.
	 *
	 * UCSS elimina lo que no ve en el HTML que MUESTREA, y las clases de acá
	 * son condicionales: `__vols` sólo existe en las series con tomos agotados
	 * (75 de las 101 con catálogo no tienen ninguno), `__link` sólo cuando el
	 * término resolvió enlace y `__note` sólo cuando una frase de obra o edición
	 * trae detalle. Un muestreo hecho antes de encontrar una de esas series poda
	 * la regla para todas. Podadas, los números del renglón "Agotado" y detalles
	 * como "8 tomos previstos" salen en Bebas a 1,15 rem pegados al titular, y el
	 * enlace de autor/editorial recupera el rojo de marca del `a` global —el
	 * mismo rojo del precio y de los CTA—, que es justo lo que su regla evita.
	 *
	 * Hoy es inerte (`litespeed.conf.optm-ucss` está vacío); se declara para que
	 * activar UCSS no reintroduzca el defecto en silencio.
	 *
	 * @internal Callback del filtro `litespeed_ucss_whitelist`.
	 *
	 * @param array<int, string> $selectors Lista acumulada.
	 * @return array<int, string>
	 */
	public function allowlist_ucss_selectors( array $selectors ): array {
		$selectors[] = '.akb-series-facts__vols';
		$selectors[] = '.akb-series-facts__link';
		$selectors[] = '.akb-series-facts__note';
		// El atajo sólo existe en las series con dos tomos numerados o más, y
		// con él la clase de la línea del total que lo separa de «N productos».
		$selectors[] = '.akb-series-latest';
		$selectors[] = '.akb-result-count--extra';
		// El resumen de arriba: su dato existe sólo cuando el dueño cargó el
		// estado de publicación o cuando el rango de precios es publicable.
		$selectors[] = '.akb-series-summary__item';
		$selectors[] = '.akb-series-summary__note';

		return array_values( array_unique( $selectors ) );
	}

	/**
	 * Borra la ficha cacheada de todas las series de un producto.
	 *
	 * @internal Callback de `woocommerce_product_set_stock_status`,
	 *           `save_post_product` y `before_delete_post`.
	 *
	 * @param int|string $product_id ID del producto.
	 * @return void
	 */
	public function invalidate_product( $product_id ): void {
		$this->invalidate_series_identifiers( $this->series_identifiers_of( (int) $product_id ) );
	}

	/**
	 * Variante para los hooks que entregan el objeto en vez del ID.
	 *
	 * @internal Callback de `woocommerce_product_set_stock`.
	 *
	 * @param mixed $product Instancia de `WC_Product`.
	 * @return void
	 */
	public function invalidate_product_object( $product ): void {
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
			return;
		}
		$this->invalidate_product( (int) $product->get_id() );
	}

	/**
	 * Metas que cambian los hechos públicos sin pasar por save_post_product.
	 *
	 * Incluye _thumbnail_id y VolumeVariant::META_KEY. La portada, los conteos y
	 * la lista de agotados deben invalidarse también cuando esas metas cambian
	 * desde biblioteca, importación o CLI.
	 *
	 * @var array<int, string>
	 */
	private const WATCHED_PRODUCT_META = array( '_thumbnail_id', VolumeVariant::META_KEY );

	/**
	 * Cubre cambios directos de meta que no pasan por `save_post_product`.
	 *
	 * @param mixed  $meta_id    Meta ID, no usado.
	 * @param int    $object_id  Producto afectado.
	 * @param string $meta_key   Clave modificada.
	 * @return void
	 */
	public function invalidate_on_product_meta_changed( $meta_id, int $object_id, string $meta_key ): void {
		unset( $meta_id );
		if ( ! in_array( $meta_key, self::WATCHED_PRODUCT_META, true ) || 'product' !== get_post_type( $object_id ) ) {
			return;
		}

		$this->invalidate_product( $object_id );
	}

	/**
	 * Invalida al reasignar la serie de un producto.
	 *
	 * Los `term_taxonomy_id` llegan en los propios argumentos del hook, así que
	 * acá no hace falta consultar nada: se borran los nuevos y los viejos.
	 *
	 * @internal Callback de `set_object_terms`.
	 *
	 * @param int                 $object_id  Producto.
	 * @param mixed               $terms      Términos, no usado.
	 * @param array<int,int>|mixed $tt_ids    Nuevos term_taxonomy_id.
	 * @param string              $taxonomy   Taxonomía afectada.
	 * @param mixed               $append     No usado.
	 * @param array<int,int>|mixed $old_tt_ids Anteriores term_taxonomy_id.
	 * @return void
	 */
	public function invalidate_on_term_change( $object_id, $terms, $tt_ids, $taxonomy, $append = false, $old_tt_ids = array() ): void {
		unset( $terms, $append );

		if (
			in_array(
				(string) $taxonomy,
				array( SeriesCatalogEligibility::VISIBILITY_TAXONOMY, self::BRAND_TAXONOMY, self::AUTHOR_TAXONOMY ),
				true
			)
		) {
			$this->invalidate_product( (int) $object_id );
			return;
		}

		if ( self::TAXONOMY !== (string) $taxonomy ) {
			return;
		}

		$ids = array_merge(
			is_array( $tt_ids ) ? $tt_ids : array(),
			is_array( $old_tt_ids ) ? $old_tt_ids : array()
		);

		$this->invalidate_series_term_taxonomy_ids( $ids );
	}

	/**
	 * Invalida relaciones eliminadas por `wp_remove_object_terms()`.
	 *
	 * @param int|string           $object_id Producto.
	 * @param array<int,int>|mixed $tt_ids    Relaciones eliminadas.
	 * @param string               $taxonomy  Taxonomía afectada.
	 */
	public function invalidate_on_deleted_term_relationships( $object_id, $tt_ids, string $taxonomy ): void {
		if ( in_array( $taxonomy, array( SeriesCatalogEligibility::VISIBILITY_TAXONOMY, self::BRAND_TAXONOMY, self::AUTHOR_TAXONOMY ), true ) ) {
			$this->invalidate_product( (int) $object_id );
			return;
		}

		if ( self::TAXONOMY !== $taxonomy ) {
			return;
		}

		$this->invalidate_series_term_taxonomy_ids( is_array( $tt_ids ) ? $tt_ids : array() );
	}

	/**
	 * Invalida todas las series que publican un autor o editorial renombrado.
	 *
	 * El join parte del TTID ya entregado por Core y resuelve el conjunto entero
	 * en una consulta, aunque el término esté usado por cientos de productos.
	 *
	 * @internal Callback de `edited_product_brand` y `edited_pa_autor`.
	 *
	 * @param int|string $term_id          ID del término, no usado.
	 * @param int|string $term_taxonomy_id TTID editado.
	 * @return void
	 */
	public function invalidate_on_catalog_term_edited( $term_id, $term_taxonomy_id ): void {
		unset( $term_id );
		$term_taxonomy_id = (int) $term_taxonomy_id;
		if ( $term_taxonomy_id < 1 ) {
			return;
		}

		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_col' ) || ! method_exists( $wpdb, 'prepare' ) ) {
			$this->handle_identifier_lookup_failure();
			return;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nombres de tabla desde $wpdb; los valores viajan por placeholders.
		$queries_before = isset( $wpdb->num_queries ) ? (int) $wpdb->num_queries : null;
		$raw_series    = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT CONCAT(series_taxonomy.term_taxonomy_id, ':', series_taxonomy.term_id)
				FROM {$wpdb->term_relationships} changed_relationship
				INNER JOIN {$wpdb->term_relationships} series_relationship
					ON series_relationship.object_id = changed_relationship.object_id
				INNER JOIN {$wpdb->term_taxonomy} series_taxonomy
					ON series_taxonomy.term_taxonomy_id = series_relationship.term_taxonomy_id
					AND series_taxonomy.taxonomy = %s
				WHERE changed_relationship.term_taxonomy_id = %d",
				self::TAXONOMY,
				$term_taxonomy_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $this->identifier_lookup_is_reliable( $raw_series, $queries_before ) ) {
			$this->handle_identifier_lookup_failure();
			return;
		}

		$this->invalidate_series_identifiers( self::normalize_series_identifiers( $raw_series ) );
	}

	/**
	 * Borra los datos derivados y el HTML de cada serie indicada por TTID.
	 *
	 * `set_object_terms` entrega tanto las relaciones nuevas como las antiguas,
	 * pero LiteSpeed purga páginas por `term_id`, no por
	 * `term_taxonomy_id`. La resolución se hace en un solo lote para que una
	 * importación no convierta la invalidación en una consulta por término.
	 *
	 * @param array<int,int|string> $term_taxonomy_ids Identificadores de relaciones.
	 * @return void
	 */
	private function invalidate_series_term_taxonomy_ids( array $term_taxonomy_ids ): void {
		$term_taxonomy_ids = array_values(
			array_unique(
				array_filter(
					array_map( 'intval', $term_taxonomy_ids ),
					static fn( int $term_taxonomy_id ): bool => $term_taxonomy_id > 0
				)
			)
		);

		if ( array() === $term_taxonomy_ids ) {
			return;
		}

		// La rotación sucede ANTES de resolver term_id. Aunque esa consulta
		// falle, una respuesta concurrente ya no puede volver a publicar el
		// transient anterior bajo una clave que siga vigente.
		$this->begin_series_invalidations(
			array_map(
				static fn( int $term_taxonomy_id ): array => array(
					'term_taxonomy_id' => $term_taxonomy_id,
					'term_id'          => 0,
				),
				$term_taxonomy_ids
			)
		);

		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_col' ) || ! method_exists( $wpdb, 'prepare' ) ) {
			$this->handle_identifier_lookup_failure();
			return;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $term_taxonomy_ids ), '%d' ) );
		$args         = array_merge( array( self::TAXONOMY ), $term_taxonomy_ids );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nombres de tabla desde $wpdb; cantidad de placeholders construida desde IDs enteros normalizados.
		$queries_before = isset( $wpdb->num_queries ) ? (int) $wpdb->num_queries : null;
		$raw_series    = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT CONCAT(tt.term_taxonomy_id, ':', tt.term_id)
				FROM {$wpdb->term_taxonomy} tt
				WHERE tt.taxonomy = %s
					AND tt.term_taxonomy_id IN ({$placeholders})",
				...$args
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $this->identifier_lookup_is_reliable( $raw_series, $queries_before ) ) {
			$this->handle_identifier_lookup_failure();
			return;
		}

		$resolved_series = self::normalize_series_identifiers( $raw_series );
		// La primera apertura sólo conocía TTID. Reinyectar los pares resueltos
		// no rota otra vez (el guard ya está marcado), pero completa el term_id
		// que la barrera de shutdown necesita para repetir la purga exacta.
		$this->begin_series_invalidations( $resolved_series );
		foreach ( $resolved_series as $series ) {
			if ( $series['term_id'] > 0 ) {
				do_action( 'litespeed_purge', 'T.' . $series['term_id'] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook público oficial de LiteSpeed Cache.
			}
		}
	}

	/**
	 * Borra el transient y la página completa de una lista ya normalizada.
	 *
	 * @param list<array{term_taxonomy_id:int,term_id:int}> $series Series afectadas.
	 * @return void
	 */
	private function invalidate_series_identifiers( array $series ): void {
		$this->begin_series_invalidations( $series );

		foreach ( $series as $identifier ) {
			if ( $identifier['term_id'] > 0 ) {
				do_action( 'litespeed_purge', 'T.' . $identifier['term_id'] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook público oficial de LiteSpeed Cache.
			}
		}
	}

	/**
	 * Identificadores de las series de un producto.
	 *
	 * Consulta directa y no `get_the_terms()` a propósito: este módulo tiene
	 * prohibido —por contrato y por prueba— cualquier camino de lectura por
	 * producto, y además `get_the_terms()` devuelve `term_id`, no la columna con
	 * la que se arma la clave del transient.
	 *
	 * @param int $product_id Producto.
	 * @return list<array{term_taxonomy_id:int,term_id:int}>
	 */
	private function series_identifiers_of( int $product_id ): array {
		global $wpdb;

		if ( $product_id < 1 ) {
			return array();
		}
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_col' ) || ! method_exists( $wpdb, 'prepare' ) ) {
			$this->handle_identifier_lookup_failure();
			return array();
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nombres de tabla desde $wpdb; los valores viajan por placeholders.
		$queries_before = isset( $wpdb->num_queries ) ? (int) $wpdb->num_queries : null;
		$ids            = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT CONCAT(tt.term_taxonomy_id, ':', tt.term_id)
				FROM {$wpdb->term_relationships} tr
				INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				WHERE tr.object_id = %d AND tt.taxonomy = %s",
				$product_id,
				self::TAXONOMY
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $this->identifier_lookup_is_reliable( $ids, $queries_before ) ) {
			$this->handle_identifier_lookup_failure();
			return array();
		}

		return self::normalize_series_identifiers( $ids );
	}

	/**
	 * Distingue un conjunto vacío real del `[]` que wpdb devuelve al fallar.
	 *
	 * @param mixed    $rows           Resultado de `get_col()`.
	 * @param int|null $queries_before Contador anterior, si está disponible.
	 */
	private function identifier_lookup_is_reliable( $rows, ?int $queries_before ): bool {
		if ( ! is_array( $rows ) ) {
			return false;
		}

		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return false;
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
	 * Abre una invalidación con una generación nueva y agenda su cierre.
	 *
	 * La primera rotación vuelve inalcanzable cualquier snapshot anterior. La
	 * segunda, en `shutdown` y antes de que LiteSpeed selle sus headers, cubre
	 * los cambios posteriores del mismo save/import. Dos productos de la misma
	 * serie dentro de un lote siguen costando como máximo dos escrituras.
	 *
	 * @param list<array{term_taxonomy_id:int,term_id:int}> $series Series afectadas.
	 */
	private function begin_series_invalidations( array $series ): void {
		foreach ( $series as $identifier ) {
			$term_taxonomy_id = max( 0, (int) ( $identifier['term_taxonomy_id'] ?? 0 ) );
			$term_id          = max( 0, (int) ( $identifier['term_id'] ?? 0 ) );
			if ( $term_taxonomy_id < 1 ) {
				continue;
			}

			// Se borra la clave que estaba vigente antes de rotarla. No es la
			// barrera de corrección (esa es la generación), pero recupera espacio y
			// conserva la invalidación inmediata esperada por otros consumidores.
			// Y lo verificado en esta petición deja de valer, también cuando la
			// serie ya había rotado antes en ella y no vuelve a rotar hasta el
			// cierre.
			self::forget_verified( $term_taxonomy_id );
			delete_transient( $this->cache_key( $term_taxonomy_id ) );
			delete_transient( self::TRANSIENT_PREFIX . $term_taxonomy_id );

			if ( $term_id > 0 ) {
				$this->pending_final_series_invalidations[ $term_taxonomy_id ] = $term_id;
			} elseif ( ! isset( $this->pending_final_series_invalidations[ $term_taxonomy_id ] ) ) {
				$this->pending_final_series_invalidations[ $term_taxonomy_id ] = 0;
			}

			if ( isset( $this->initial_series_invalidations[ $term_taxonomy_id ] ) ) {
				continue;
			}

			$this->initial_series_invalidations[ $term_taxonomy_id ] = true;
			if ( ! $this->rotate_series_cache_generation( $term_taxonomy_id ) ) {
				$this->handle_identifier_lookup_failure();
			}
		}

		if ( array() !== $this->pending_final_series_invalidations ) {
			$this->schedule_generation_finalizer();
		}
	}

	/**
	 * Cierra las generaciones y repite la purga exacta antes de LiteSpeed.
	 *
	 * @internal Callback de `shutdown` a prioridad -100.
	 */
	public function finalize_cache_invalidations(): void {
		$series = $this->pending_final_series_invalidations;
		$this->pending_final_series_invalidations = array();

		foreach ( $series as $term_taxonomy_id => $term_id ) {
			self::forget_verified( (int) $term_taxonomy_id );
			delete_transient( $this->cache_key( (int) $term_taxonomy_id ) );
			if ( ! $this->rotate_series_cache_generation( (int) $term_taxonomy_id ) ) {
				$this->rotate_global_cache_generation();
			}
			if ( $term_id > 0 ) {
				do_action( 'litespeed_purge', 'T.' . $term_id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook público oficial de LiteSpeed Cache.
			}
		}

		if ( $this->pending_global_generation_finalize ) {
			$this->pending_global_generation_finalize = false;
			self::forget_verified( null );
			$this->rotate_global_cache_generation();
			do_action( 'litespeed_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- fallback global sólo cuando no existe un TTID resoluble.
		}
	}

	/** Registra una sola barrera tardía, antes del header final de LiteSpeed. */
	private function schedule_generation_finalizer(): void {
		if ( $this->generation_finalizer_registered ) {
			return;
		}
		$this->generation_finalizer_registered = true;
		add_action( 'shutdown', array( $this, 'finalize_cache_invalidations' ), -100 );
	}

	/** Rota la generación durable de un único term_taxonomy_id. */
	private function rotate_series_cache_generation( int $term_taxonomy_id ): bool {
		if ( $term_taxonomy_id < 1 ) {
			return false;
		}

		$option_name = self::SERIES_CACHE_GENERATION_OPTION_PREFIX . $term_taxonomy_id;
		$generation  = $this->persist_cache_generation( $option_name, $this->next_cache_generation() );
		if ( null === $generation ) {
			return false;
		}

		$this->series_cache_generations[ $term_taxonomy_id ] = $generation;
		return true;
	}

	/** Rota la generación excepcional que vuelve inalcanzable TODO el conjunto. */
	private function rotate_global_cache_generation(): bool {
		$generation = $this->persist_cache_generation( self::CACHE_GENERATION_OPTION, $this->next_cache_generation() );
		if ( null === $generation ) {
			return false;
		}

		$this->cache_generation = $generation;
		return true;
	}

	/** Token opaco; una colisión no puede reciclar una generación vieja. */
	private function next_cache_generation(): string {
		$token = function_exists( 'wp_generate_uuid4' )
			? (string) wp_generate_uuid4()
			: str_replace( '.', '-', uniqid( 'facts-', true ) );

		return self::normalize_cache_generation( $token );
	}

	/**
	 * Persiste y verifica una generación, incluso si `update_option()` falla.
	 *
	 * El fallback directo no es una ruta normal de escritura: existe para el
	 * caso concreto donde la consulta de identidad ya falló y WordPress tampoco
	 * pudo rotar la opción. La fila es escalar, no autoload y exclusiva de este
	 * módulo; dos writers concurrentes pueden dejar cualquiera de sus tokens y
	 * ambos son una barrera válida contra la generación anterior.
	 */
	private function persist_cache_generation( string $option_name, string $token ): ?string {
		if ( '' === $token ) {
			return null;
		}

		if ( update_option( $option_name, $token, false ) ) {
			return $token;
		}

		$before = self::normalize_cache_generation( get_option( $option_name, '' ) );
		if ( hash_equals( $token, $before ) ) {
			return $token;
		}

		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! isset( $wpdb->options ) ) {
			return null;
		}

		$result = false;
		if ( method_exists( $wpdb, 'update' ) ) {
			$result = $wpdb->update(
				(string) $wpdb->options,
				array(
					'option_value' => $token,
					'autoload'     => 'no',
				),
				array( 'option_name' => $option_name ),
				array( '%s', '%s' ),
				array( '%s' )
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- recuperación durable tras fallo de la API de opciones.
		}
		if ( 1 !== $result && method_exists( $wpdb, 'insert' ) ) {
			$result = $wpdb->insert(
				(string) $wpdb->options,
				array(
					'option_name'  => $option_name,
					'option_value' => $token,
					'autoload'     => 'no',
				),
				array( '%s', '%s', '%s' )
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- el índice único de option_name arbitra la carrera de alta.
		}

		$this->invalidate_generation_option_cache( $option_name );
		$after = self::normalize_cache_generation( get_option( $option_name, '' ) );
		if ( '' !== $after && ( hash_equals( $token, $after ) || ! hash_equals( $before, $after ) ) ) {
			return $after;
		}

		return 1 === $result ? $token : null;
	}

	/**
	 * Invalida las dos entradas que pueden ocultar una escritura directa.
	 *
	 * `alloptions` queda fuera a propósito: la fila de generación se escribe con
	 * `autoload = 'no'`, así que el blob autocargado no puede contenerla.
	 * Evictarlo obligaba a recomponer TODAS las options autocargadas del sitio
	 * para invalidar una fila que no estaba ahí.
	 */
	private function invalidate_generation_option_cache( string $option_name ): void {
		if ( ! function_exists( 'wp_cache_delete' ) ) {
			return;
		}

		wp_cache_delete( $option_name, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/**
	 * Fallback excepcional: rota la generación y purga página global una vez.
	 *
	 * Sin los TTID no existe una purga exacta segura. Una generación nueva evita
	 * reutilizar transients viejos incluso con object cache externo; `purge_all`
	 * se reserva exclusivamente para este error de infraestructura infrecuente.
	 */
	private function handle_identifier_lookup_failure(): void {
		// Sin saber qué serie cambió, nada de lo verificado vale: también en la
		// segunda falla, que ya no vuelve a rotar.
		self::forget_verified( null );
		if ( $this->fallback_invalidation_triggered ) {
			return;
		}
		$this->fallback_invalidation_triggered = true;

		// Aun si toda persistencia está temporalmente caída, el token local
		// impide que ESTE request vuelva a leer el snapshot viejo. El segundo
		// intento en shutdown hace durable la barrera cuando la conexión vuelve.
		if ( ! $this->rotate_global_cache_generation() ) {
			$this->cache_generation = $this->next_cache_generation();
		}
		$this->pending_global_generation_finalize = true;
		$this->schedule_generation_finalizer();

		$this->mark_page_uncacheable( 'series facts invalidation unavailable' );
		do_action( 'litespeed_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- fallback global sólo cuando no existe un TTID resoluble.
	}

	/** Genera la clave actual; sin generaciones conserva compatibilidad con v3. */
	private function cache_key( int $term_taxonomy_id ): string {
		$generation = $this->cache_generation_snapshot( $term_taxonomy_id );
		return $this->cache_key_for_generation(
			$term_taxonomy_id,
			null === $generation ? $this->request_local_cache_generation( $term_taxonomy_id ) : $generation
		);
	}

	/** @return string|null Generación global + local, o null si la lectura falló. */
	private function cache_generation_snapshot( int $term_taxonomy_id, bool $refresh = false ): ?string {
		if ( $refresh ) {
			// Sólo se descarta el memo del propio objeto. La evicción del object
			// cache se hace ahora dentro de la rama que de verdad lee por
			// `get_option()`: en WordPress real el snapshot sale de un SELECT
			// crudo que jamás consulta el object cache, así que borrarlo aquí no
			// aportaba nada y sí cobraba caro. Cada evicción arrastraba
			// `wp_cache_delete( 'notoptions', 'options')`, que tira el índice de
			// opciones ausentes de TODA la petición: a partir de ahí cualquier
			// `get_option()` de una opción que no existe —las hay a decenas entre
			// el core, WooCommerce y los plugins— vuelve a golpear la base. Y no
			// pasaba una vez: `facts()` refresca hasta cuatro veces por serie.
			$this->cache_generation = null;
			unset( $this->series_cache_generations[ $term_taxonomy_id ] );
		}

		if ( null === $this->cache_generation || ! array_key_exists( $term_taxonomy_id, $this->series_cache_generations ) ) {
			$values = $this->read_cache_generation_options( $term_taxonomy_id );
			if ( null === $values ) {
				return null;
			}
			$this->cache_generation = $values['global'];
			$this->series_cache_generations[ $term_taxonomy_id ] = $values['series'];
		}

		return $this->request_local_cache_generation( $term_taxonomy_id );
	}

	/**
	 * Lee ambas generaciones en un solo snapshot autoritativo.
	 *
	 * En WordPress real se evita `get_option()`: cuando su SELECT falla, Core
	 * devuelve el mismo valor que para una opción ausente y hasta llena
	 * `notoptions`. La consulta directa permite distinguir `[]` real de
	 * `[] + last_error`. El fallback existe sólo para dobles de prueba o runtimes
	 * incompletos y aplica el mismo guard cuando exponen las señales de wpdb.
	 *
	 * @return array{global:string,series:string}|null
	 */
	private function read_cache_generation_options( int $term_taxonomy_id ): ?array {
		$series_option = self::SERIES_CACHE_GENERATION_OPTION_PREFIX . $term_taxonomy_id;
		global $wpdb;

		if (
			isset( $wpdb )
			&& is_object( $wpdb )
			&& isset( $wpdb->options )
			&& method_exists( $wpdb, 'get_results' )
			&& method_exists( $wpdb, 'prepare' )
		) {
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- snapshot de epoch que deliberadamente salta el object cache local.
				$wpdb->prepare(
					"SELECT option_name, option_value
					FROM {$wpdb->options}
					WHERE option_name IN (%s, %s)",
					self::CACHE_GENERATION_OPTION,
					$series_option
				),
				ARRAY_A
			);
			if (
				! is_array( $rows )
				|| ( isset( $wpdb->ready ) && ! (bool) $wpdb->ready )
				|| ( isset( $wpdb->last_error ) && '' !== trim( (string) $wpdb->last_error ) )
			) {
				return null;
			}

			$values = array();
			foreach ( $rows as $row ) {
				if ( is_array( $row ) && isset( $row['option_name'] ) ) {
					$values[ (string) $row['option_name'] ] = $row['option_value'] ?? '';
				}
			}

			return array(
				'global' => self::normalize_cache_generation( $values[ self::CACHE_GENERATION_OPTION ] ?? '' ),
				'series' => self::normalize_cache_generation( $values[ $series_option ] ?? '' ),
			);
		}

		// Rama de respaldo: aquí sí se lee por `get_option()`, así que el object
		// cache tiene que quedar fuera del camino o devolvería una foto vieja.
		$this->evict_generation_option_read_cache( self::CACHE_GENERATION_OPTION );
		$this->evict_generation_option_read_cache( $series_option );

		$queries_before = isset( $wpdb->num_queries ) ? (int) $wpdb->num_queries : null;
		$global         = get_option( self::CACHE_GENERATION_OPTION, '' );
		$series         = get_option( $series_option, '' );
		$query_ran      = null === $queries_before
			|| ! isset( $wpdb->num_queries )
			|| (int) $wpdb->num_queries > $queries_before;
		if (
			( isset( $wpdb->ready ) && ! (bool) $wpdb->ready )
			|| ( $query_ran && isset( $wpdb->last_error ) && '' !== trim( (string) $wpdb->last_error ) )
		) {
			return null;
		}

		return array(
			'global' => self::normalize_cache_generation( $global ),
			'series' => self::normalize_cache_generation( $series ),
		);
	}

	/** Generación ya observada en este objeto, sin tocar almacenamiento. */
	private function request_local_cache_generation( int $term_taxonomy_id ): string {
		return trim(
			( $this->cache_generation ?? '' ) . '_' . ( $this->series_cache_generations[ $term_taxonomy_id ] ?? '' ),
			'_'
		);
	}

	/**
	 * Fuerza una lectura durable en el mismo proceso que está renderizando.
	 *
	 * `get_option()` mantiene una caché PHP por request incluso sin Redis o
	 * Memcached. Otro request puede rotar la fila en MySQL y no tiene cómo borrar
	 * esa copia privada. Expulsarla antes de cada recheck es lo que convierte la
	 * comparación de epoch en una barrera entre procesos y no sólo entre objetos.
	 */
	private function evict_generation_option_read_cache( string $option_name ): void {
		if ( ! function_exists( 'wp_cache_delete' ) ) {
			return;
		}

		wp_cache_delete( $option_name, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/** Construye una clave desde una generación ya capturada. */
	private function cache_key_for_generation( int $term_taxonomy_id, string $generation ): string {
		$suffix = '' === $generation ? '' : $generation . '_';
		return self::TRANSIENT_PREFIX . $suffix . $term_taxonomy_id;
	}

	/** Normaliza datos externos antes de incorporarlos a una clave de cache. */
	private static function normalize_cache_generation( $value ): string {
		$value = is_scalar( $value ) ? (string) $value : '';
		return substr( (string) preg_replace( '/[^A-Za-z0-9-]/', '', $value ), 0, 64 );
	}

	/**
	 * Convierte los pares SQL `term_taxonomy_id:term_id` en una lista segura.
	 *
	 * @param mixed $raw_ids Resultado de `wpdb::get_col()`.
	 * @return list<array{term_taxonomy_id:int,term_id:int}>
	 */
	private static function normalize_series_identifiers( $raw_ids ): array {
		$series = array();
		foreach ( is_array( $raw_ids ) ? $raw_ids : array() as $raw ) {
			$parts = explode( ':', (string) $raw, 2 );
			$term_taxonomy_id = (int) ( $parts[0] ?? 0 );
			$term_id          = (int) ( $parts[1] ?? 0 );
			if ( $term_taxonomy_id > 0 ) {
				$series[] = array(
					'term_taxonomy_id' => $term_taxonomy_id,
					'term_id'          => max( 0, $term_id ),
				);
			}
		}

		return $series;
	}

	/**
	 * Resumen de serie estable bajo paginación, orden y facetas.
	 *
	 * La línea superior presenta el estado de la edición vendida o el de la obra,
	 * con el mismo rótulo de la ficha. Sin un estado verificable usa el rango de
	 * precios; sin catálogo publicado no imprime resumen. No repite los conteos de
	 * los chips de disponibilidad. Su contenido no depende de la página actual,
	 * por lo que PJAX puede conservarlo al reemplazar la rejilla.
	 *
	 * @internal Callback del hook `woocommerce_before_shop_loop`.
	 *
	 * @return void
	 */
	public function render_summary(): void {
		$facts = $this->renderable_facts();
		if ( null === $facts ) {
			return;
		}

		$label = '';
		$value = '';
		$pairs = $this->publication_phrases( $facts );
		if ( array() !== $pairs ) {
			$label = $pairs[0]['label'];
			$value = esc_html( $pairs[0]['phrase']['titular'] );
			if ( '' !== $pairs[0]['phrase']['detalle'] ) {
				// Coma y no punto medio: si la línea no alcanza, el detalle baja
				// entero («38 tomos previstos») y la línea nueva no empieza con un
				// separador suelto; y el lector de pantalla oye la pausa.
				$value .= ', ' . sprintf(
					'<span class="akb-series-summary__note">%s</span>',
					esc_html( $pairs[0]['phrase']['detalle'] )
				);
			}
		} else {
			$price = $this->price_pair( $facts );
			if ( null !== $price ) {
				$label = $price['label'];
				$value = $price['value'];
			}
		}

		$link = Text::one_line_option( self::OPTION_SUMMARY_LINK, self::DEFAULT_SUMMARY_LINK );
		?>
		<div class="akb-series-summary">
			<?php if ( '' !== $value ) : ?>
				<dl class="akb-series-summary__facts">
					<div class="akb-series-summary__item">
						<dt class="akb-series-summary__label"><?php echo esc_html( $label ); ?></dt>
						<dd class="akb-series-summary__value"><?php echo $value; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapado en el punto de construcción. ?></dd>
					</div>
				</dl>
			<?php endif; ?>
			<?php // Espacio duro antes de la flecha: se queda con la última palabra sin `nowrap` en el enlace (B3-R6). ?>
			<a class="akb-series-summary__more" href="#<?php echo esc_attr( self::SHEET_ID ); ?>"><?php echo esc_html( $link ); ?>&nbsp;<span aria-hidden="true">↓</span></a>
		</div>
		<?php
	}

	/**
	 * Añade a la línea del total un enlace a la ficha del último tomo de la serie.
	 *
	 * El tomo más nuevo es un hecho de la serie, independiente del orden, facetas
	 * y página actual. No se enlaza a la última página de la rejilla porque podría
	 * contener los tomos más antiguos bajo otro orden. Se presenta en la línea del
	 * conteo, dentro de la toolbar reemplazada por PJAX, sin aumentar la altura del
	 * resumen. Sólo se ofrece cuando hay dos o más tomos.
	 *
	 * @internal Callback del filtro `akb_search_result_count_extra` (akibara-search).
	 *
	 * @param mixed $html Lo que ya aportaron otros módulos.
	 * @return string HTML escapado.
	 */
	public function result_count_extra( $html ): string {
		$html  = is_string( $html ) ? $html : '';
		$facts = $this->renderable_facts();
		if ( null === $facts ) {
			return $html;
		}

		return $html . $this->latest_volume_link( $facts );
	}

	/**
	 * `<a>` a la ficha del tomo más nuevo, o '' si no hay uno que nombrar.
	 *
	 * @param array<string,mixed> $facts Hechos normalizados.
	 */
	private function latest_volume_link( array $facts ): string {
		$latest = $facts['ultimo_tomo'] ?? null;
		if ( (int) ( $facts['tomos'] ?? 0 ) < 2 || ! is_array( $latest ) ) {
			return '';
		}

		$url = get_permalink( (int) ( $latest['id'] ?? 0 ) );
		if ( ! is_string( $url ) || '' === $url ) {
			return '';
		}

		// El número entra por `str_replace` sobre el copy YA escapado: el texto es
		// del dueño y nunca pasa por `sprintf`. Sin el marcador sale tal cual.
		$text = str_replace(
			self::VOLUME_MARKER,
			(string) (int) $latest['numero'],
			esc_html( Text::one_line_option( self::OPTION_LATEST_VOLUME, self::DEFAULT_LATEST_VOLUME ) )
		);

		// Espacio duro antes de la flecha, como en el resumen (B3-R6).
		return '<a class="akb-series-latest" href="' . esc_url( $url ) . '">' . $text . '&nbsp;<span aria-hidden="true">→</span></a>';
	}

	/**
	 * Imprime la ficha completa de una serie con productos publicados, bajo la
	 * rejilla. Lleva el ancla a la que apunta el resumen de arriba.
	 *
	 * @internal Callback del hook `woocommerce_after_shop_loop`.
	 *
	 * @return void
	 */
	public function render(): void {
		$facts = $this->renderable_facts();
		if ( null === $facts ) {
			return;
		}
		?>
		<?php
		/*
		 * «Datos de esta edición y de la obra original» y no «Datos del catálogo»,
		 * que se usaba antes. La etiqueta se escribió cuando TODO lo de adentro
		 * salía del catálogo; hoy la región puede abrir con «Edición Planeta Cómic» y «Obra
		 * original», dos alcances explícitos que por diseño NO se derivan del stock.
		 *
		 * Quien navega por regiones —rotor de VoiceOver, tecla D de NVDA— entra por
		 * el nombre accesible y lo primero que oye dentro puede ser «Edición Planeta
		 * Cómic: En publicación, 8 tomos previstos». Con la palabra «catálogo» en la
		 * etiqueta, ese total anunciado se leería como stock de Akibara, aunque el
		 * desglose siguiente hable sólo de lo comprable hoy.
		 *
		 * La palabra «catálogo» no se pierde: sigue donde es cierta, en el renglón
		 * de la fecha («Catálogo actualizado»).
		 */
		?>
		<section class="akb-series-facts" aria-label="Datos de esta edición y de la obra original" id="<?php echo esc_attr( self::SHEET_ID ); ?>">
			<dl class="akb-series-facts__list">
				<?php
				// PRIMERO la edición exacta y después la obra original cuando
				// aporta información distinta. Los conteos por estado contestan
				// "qué puedo comprar"; estas filas contestan "qué edición es" y
				// "si la obra terminó", sin llamar "completa" al stock de Akibara.
				$this->render_publication_rows( $facts );
				?>
				<?php
				// SIN un renglón "Tomos en catálogo": ese total ya lo publica la
				// barra de herramientas SOBRE la rejilla
				// (`<p class="akb-result-count">107 tomos</p>`). Repetirlo acá
				// reintroduce, con otro nombre, el mismo defecto por el que el
				// theme RETIRÓ el "%s títulos" del hero (inc/woocommerce.php):
				// dos números idénticos en unidad y significado, uno estático y
				// otro vivo, que sólo pueden desincronizarse. El desglose por
				// estado suma ese total sin afirmarlo, y es el dato que hoy no
				// existe en ninguna parte de la página.
				$this->render_stock_rows( $facts );
				?>
				<?php $this->render_price_row( $facts ); ?>
				<?php
				// Autor ANTES que editorial: los dos responden "quién hizo esto",
				// pero el autor es la salida lateral que esta landing no tenía y
				// la intención de lectura más fuerte. La fecha sigue cerrando el
				// bloque porque es señal de frescura, no un dato de la obra.
				$this->render_author_row( $facts );
				?>
				<?php $this->render_brand_row( $facts ); ?>
				<?php $this->render_updated_row( $facts ); ?>
			</dl>
		</section>
		<?php
	}

	/**
	 * Encola la hoja del resumen, el atajo y la ficha en las landings de serie.
	 *
	 * Con la misma puerta que decide si hay algo que pintar
	 * (`queried_series_tt_id()`: sin consultas), no con `renderable_facts()`: una
	 * landing sin catálogo publicado sólo se ahorraría una hoja de ~3,9 KB
	 * minificada y pagaría la lectura del agregado antes del `<head>`.
	 *
	 * @internal Callback de `wp_enqueue_scripts`.
	 */
	public function enqueue_assets(): void {
		if ( 0 === $this->queried_series_tt_id() ) {
			return;
		}

		wp_enqueue_style(
			'akb-series-facts',
			AKIBARA_BUSINESS_URL . 'assets/css/series-facts.css',
			array(),
			PluginAssetVersion::for_path( 'assets/css/series-facts.css' )
		);
	}

	/**
	 * Hechos de la serie consultada, o null si este request no debe pintar ni el
	 * resumen ni la ficha.
	 *
	 * @return array<string,mixed>|null
	 */
	private function renderable_facts(): ?array {
		$term_taxonomy_id = $this->queried_series_tt_id();
		if ( 0 === $term_taxonomy_id ) {
			return null;
		}

		$facts = $this->facts( $term_taxonomy_id );
		if ( null === $facts || $facts['tomos'] < 1 ) {
			// Serie sin catálogo publicado: no hay ningún hecho que declarar y
			// un bloque con la estructura vacía sólo diría "no sabemos".
			return null;
		}

		return $facts;
	}

	/**
	 * `term_taxonomy_id` de la serie consultada, o 0 si este request no debe
	 * imprimir la ficha.
	 *
	 * ── Por qué NO se restringe a la URL canónica ───────────────────────────
	 * La primera versión sólo imprimía en la URL limpia (sin query string, sin
	 * paginar), copiando el predicado de `RelatedGuides\SeriesRelatedGuide`. En
	 * este sitio eso produce una incoherencia visible: el buscador propio
	 * (`akibara-search`) navega el archive por PJAX y reemplaza una lista FIJA
	 * de contenedores —`ul.products`, `.woocommerce-pagination`, `.akb-facets`,
	 * `.akb-facet-chips`, `.akb-shop-toolbar`, `.akb-empty-state`,
	 * `.page-title`— entre los que este bloque no está. O sea: al hacer click en
	 * la paginación el bloque SOBREVIVE en pantalla y la URL pasa a /page/2/,
	 * pero un F5 en esa misma URL lo hace desaparecer. La misma dirección
	 * sirviendo dos DOM distintos según cómo se llegó es peor que cualquiera de
	 * los dos comportamientos por separado.
	 *
	 * La salida no es sincronizar dos capas (habría que tocar el JS de otro
	 * plugin y mantener esa lista alineada para siempre), sino que no haya nada
	 * que sincronizar: TODO lo que este bloque declara es un hecho de la SERIE
	 * —cuántos tomos hay en cada estado, a qué precios, de qué editorial—, no
	 * del subconjunto que la página está mostrando. Esos hechos son idénticos en
	 * la página 1, en la 4 y bajo cualquier faceta, así que el bloque es válido
	 * en todas y el PJAX puede dejarlo intacto sin mentir nunca. El único dato
	 * que sí dependía del subconjunto era el total "Tomos en catálogo", y por
	 * eso se eliminó (ver `render()`).
	 *
	 * Devuelve el `term_taxonomy_id` y no el `term_id` porque es la columna con
	 * la que se une `wp_term_relationships`; en términos compartidos entre
	 * taxonomías (legacy de WP) los dos números difieren.
	 */
	private function queried_series_tt_id(): int {
		if ( is_admin() || ! is_tax( self::TAXONOMY ) ) {
			return 0;
		}

		$term = get_queried_object();
		if ( ! is_object( $term )
			|| ! isset( $term->taxonomy, $term->term_taxonomy_id )
			|| self::TAXONOMY !== (string) $term->taxonomy ) {
			return 0;
		}

		return max( 0, (int) $term->term_taxonomy_id );
	}

	/**
	 * Los MISMOS hechos que pinta el bloque, para quien deba declararlos en otro
	 * lenguaje.
	 *
	 * Existe por `SeriesSchema`, que publica la entidad `ComicSeries` de la
	 * landing en el `@graph` de Rank Math. La alternativa —que el schema armara
	 * su propia consulta— es exactamente el defecto que este módulo ya evita
	 * puertas adentro: dos fuentes del mismo agregado divergen en cuanto una se
	 * invalida y la otra no, y el resultado sería una página cuyo bloque visible
	 * dice "31 disponibles" mientras su JSON-LD declara otro número. Un lector
	 * humano vería una cosa y un asistente citaría otra, que es la peor forma
	 * posible de fallar para un dato estructurado.
	 *
	 * Compartir el agregado además comparte el transient: el JSON-LD se rinde en
	 * `wp_head`, el resumen en `woocommerce_before_shop_loop` y la ficha en
	 * `woocommerce_after_shop_loop`, así que el primero calienta la caché y los
	 * demás la reusan — una sola consulta por pageload, no tres.
	 *
	 * @param int $term_taxonomy_id Término de la serie.
	 * @return array{tomos:int,instock:int,preventa:int,agotados:int,productos:int,productos_instock:int,productos_preventa:int,productos_agotados:int,agotados_tomos:array<int,string>,price_min:?float,price_max:?float,updated:string,autores:array<int,array{name:string,term_id:int}>,editoriales:array<int,array{name:string,term_id:int}>,ultimo_tomo:array{id:int,numero:int}|null}|null
	 */
	public function catalog_facts( int $term_taxonomy_id ): ?array {
		return $this->facts( $term_taxonomy_id );
	}

	/**
	 * ¿El rango de precios de este agregado se puede publicar?
	 *
	 * Es el criterio de `render_price_row()` extraído para que exista UNA sola
	 * definición de "este precio es publicable", compartida por el renglón
	 * visible y por el `AggregateOffer` del JSON-LD. Dos copias del criterio
	 * dejarían al schema publicando un precio estructurado durante una campaña
	 * de descuento — precisamente el caso que el bloque visible ya se niega a
	 * mostrar, y que en un dato estructurado es peor: no lo lee una persona que
	 * puede comparar con la grilla, lo lee un motor que lo repite como verdad.
	 *
	 * No incluye el guard de `wc_price()`: eso es una condición de FORMATO del
	 * HTML, no de veracidad del dato. El JSON-LD emite el número crudo.
	 *
	 * @param array{price_min:?float,price_max:?float} $facts Hechos normalizados.
	 * @return bool
	 */
	public function price_range_is_publishable( array $facts ): bool {
		if ( null === ( $facts['price_min'] ?? null ) || null === ( $facts['price_max'] ?? null ) ) {
			return false;
		}

		return ! ( null !== $this->price_is_overridden && (bool) ( $this->price_is_overridden )() );
	}

	/**
	 * Lee hechos cacheados por término con verificación de generación durable.
	 *
	 * La generación debe coincidir antes y después de la lectura y, al llenar,
	 * después de escribir el transient. Una invalidación intermedia provoca un
	 * único reintento sobre la generación nueva. La reutilización del memo también
	 * comprueba esa generación. Un dato verificado puede ir a page cache aunque
	 * haya llenado el transient; generación ilegible, consulta fallida o segundo
	 * intento sin verificar producen una respuesta no cacheable.
	 *
	 * @param int $term_taxonomy_id Término de la serie.
	 * @return array{tomos:int,instock:int,preventa:int,agotados:int,productos:int,productos_instock:int,productos_preventa:int,productos_agotados:int,agotados_tomos:array<int,string>,price_min:?float,price_max:?float,updated:string,autores:array<int,array{name:string,term_id:int}>,editoriales:array<int,array{name:string,term_id:int}>,ultimo_tomo:array{id:int,numero:int}|null}|null
	 */
	private function facts( int $term_taxonomy_id ): ?array {
		$verified = self::verified( $term_taxonomy_id );
		if ( null !== $verified ) {
			// Lo verificado vale mientras la generación durable siga siendo la
			// misma: la memoria ahorra el transient y el segundo SELECT, no la
			// barrera entre procesos (REV-QFB-2, ver `$verified_facts`).
			$now = $this->cache_generation_snapshot( $term_taxonomy_id, true );
			if ( null !== $now && hash_equals( $verified['generation'], $now ) ) {
				return $verified['facts'];
			}
			self::forget_verified( $term_taxonomy_id );
		}

		for ( $attempt = 0; $attempt < 2; ++$attempt ) {
			$generation = $this->cache_generation_snapshot( $term_taxonomy_id, true );
			if ( null === $generation ) {
				$this->mark_page_uncacheable( 'series facts generation unavailable' );
				$row = $this->query_row( $term_taxonomy_id );
				return null === $row ? null : $this->shape( $row );
			}
			$key        = $this->cache_key_for_generation( $term_taxonomy_id, $generation );
			$cached     = get_transient( $key );
			if ( is_array( $cached ) && isset( $cached['tomos'] ) ) {
				$latest = $this->cache_generation_snapshot( $term_taxonomy_id, true );
				if ( null !== $latest && hash_equals( $generation, $latest ) ) {
					self::remember_verified( $term_taxonomy_id, $generation, $cached );
					return $cached;
				}
				continue;
			}

			$row = $this->query_row( $term_taxonomy_id );
			if ( null === $row ) {
				$this->mark_page_uncacheable( 'series facts unavailable' );
				return null; // Sin base de datos utilizable no se cachea nada.
			}

			$facts  = $this->shape( $row );
			$latest = $this->cache_generation_snapshot( $term_taxonomy_id, true );
			if ( null === $latest || ! hash_equals( $generation, $latest ) ) {
				if ( 0 === $attempt ) {
					continue;
				}
				$this->mark_page_uncacheable( 'series facts cache fill unverified' );
				return $facts;
			}

			// Se cachea incluso el resultado vacío (`tomos === 0`): una serie sin
			// catálogo publicado seguiría pagando la consulta en cada pageload.
			set_transient( $key, $facts, 6 * HOUR_IN_SECONDS );

			// Invalidation pudo intercalarse exactamente entre la comprobación y
			// `set_transient()`. Se vuelve a leer el epoch: si cambió, esta clave se
			// borra y se reintenta una vez sobre el estado nuevo.
			$latest = $this->cache_generation_snapshot( $term_taxonomy_id, true );
			if ( null === $latest || ! hash_equals( $generation, $latest ) ) {
				delete_transient( $key );
				if ( 0 === $attempt ) {
					continue;
				}
				$this->mark_page_uncacheable( 'series facts cache fill unverified' );
				return $facts;
			}

			self::remember_verified( $term_taxonomy_id, $generation, $facts );
			return $facts;
		}

		// Dos lecturas del transient invalidadas en medio: no hay hechos
		// verificados que pintar, y una página sin ficha no puede quedar sellada.
		$this->mark_page_uncacheable( 'series facts unavailable' );

		return null;
	}

	/**
	 * Hechos ya verificados de esta serie en esta petición, con la generación
	 * contra la que se verificaron, o null.
	 *
	 * @param int $term_taxonomy_id Término de la serie.
	 * @return array{generation: string, facts: array<string, mixed>}|null
	 */
	private static function verified( int $term_taxonomy_id ): ?array {
		global $wpdb;

		if ( null === self::$verified_facts || ! is_object( $wpdb ) || ! isset( self::$verified_facts[ $wpdb ] ) ) {
			return null;
		}

		return self::$verified_facts[ $wpdb ][ $term_taxonomy_id ] ?? null;
	}

	/**
	 * Guarda lo que acaba de quedar verificado contra la generación durable.
	 *
	 * @param int                  $term_taxonomy_id Término de la serie.
	 * @param string               $generation       Generación contra la que se verificó.
	 * @param array<string, mixed> $facts            Hechos verificados.
	 */
	private static function remember_verified( int $term_taxonomy_id, string $generation, array $facts ): void {
		global $wpdb;

		if ( ! is_object( $wpdb ) ) {
			return;
		}
		self::$verified_facts ??= new \WeakMap();
		$por_serie                      = self::$verified_facts[ $wpdb ] ?? array();
		$por_serie[ $term_taxonomy_id ] = array(
			'generation' => $generation,
			'facts'      => $facts,
		);
		self::$verified_facts[ $wpdb ]  = $por_serie;
	}

	/**
	 * Descarta lo verificado de una serie, o de todas con null.
	 *
	 * @param int|null $term_taxonomy_id Término de la serie; null = todas.
	 */
	private static function forget_verified( ?int $term_taxonomy_id ): void {
		global $wpdb;

		if ( null === self::$verified_facts || ! is_object( $wpdb ) || ! isset( self::$verified_facts[ $wpdb ] ) ) {
			return;
		}
		if ( null === $term_taxonomy_id ) {
			unset( self::$verified_facts[ $wpdb ] );
			return;
		}
		$por_serie = self::$verified_facts[ $wpdb ];
		unset( $por_serie[ $term_taxonomy_id ] );
		self::$verified_facts[ $wpdb ] = $por_serie;
	}

	/** Impide que una respuesta de relleno o degradada llegue al page cache. */
	private function mark_page_uncacheable( string $reason ): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- constante pública oficial para impedir page cache.
		}
		do_action( 'litespeed_control_set_nocache', $reason ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook público oficial de LiteSpeed Cache.
	}

	/**
	 * Construye los hechos de serie en una consulta agregada.
	 *
	 * La relación de término es el filtro de entrada y conduce por clave a posts
	 * y wc_product_meta_lookup. La lookup resuelve stock sin filtrar postmeta por
	 * un meta_value no indexado. Las uniones de autores y marcas multiplican filas;
	 * los conteos usan DISTINCT y los agregados numéricos MIN/MAX, no sumas que
	 * contarían un tomo por cada relación.
	 *
	 * El filtro de taxonomía debe acotar term_relationships al hacer el JOIN, no
	 * sólo convertir en NULL las filas ajenas al unir term_taxonomy. Así la
	 * cardinalidad depende de tomos × marcas × autores, sin sumar géneros u otras
	 * relaciones que esta ficha no requiere.
	 *
	 * agotados_titulos aporta títulos para extraer y ordenar sus números en PHP;
	 * ORDER BY post_title no sustituye el orden numérico de esa lista. ultimo_tomo
	 * elige con MAX el título normal más alto y su ID unido por US, con el mismo
	 * padding y colación que la rejilla. No utiliza un GROUP_CONCAT truncable.
	 *
	 * @param int $term_taxonomy_id Término de la serie.
	 * @return array<string, mixed>|null Fila cruda, o null si no hay `$wpdb`.
	 */
	private function query_row( int $term_taxonomy_id ): ?array {
		global $wpdb;

		// Defensivo a propósito: esta ficha es informativa y jamás debe tumbar
		// el render del archive si el global no está listo (mismo criterio que
		// `DisponiblesRoute::last_updated_date()`).
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_row' ) ) {
			return null;
		}

		$lookup = $wpdb->prefix . 'wc_product_meta_lookup';

		// «Esta fila es un tomo normal», con la MISMA regla que `sanitize_kind()`.
		// Antes acá se comparaba el meta crudo con `<> ''`, y eso convertía en
		// variante cualquier valor que el saneo de PHP descarta (`edicion-especial`,
		// `Edicion Especial`, `1`, `yes`): un tipeo por WP-CLI escondía un tomo de
		// los conteos y de la lista «Agotado: …» durante seis horas mientras el
		// resto de las superficies lo seguía tratando como tomo normal.
		$tomo_normal   = VolumeVariant::sql_normal_volume_condition( 'vm.meta_value' );
		$variant_kinds = VolumeVariant::variant_kinds();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- los nombres de tabla salen de $wpdb (prefix y propiedades), nunca de entrada; TODOS los valores viajan por placeholders. El sniff no sabe contar los que llegan con el operador de propagación (`...array_merge()`) y por eso ve «1 parámetro» donde hay 22.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(DISTINCT p.ID) AS productos,
					COUNT(
						DISTINCT CASE WHEN {$tomo_normal} THEN p.ID END
					) AS tomos,
					COUNT(DISTINCT CASE WHEN l.stock_status = %s THEN p.ID END) AS productos_instock,
					COUNT(
						DISTINCT CASE
							WHEN l.stock_status = %s AND {$tomo_normal} THEN p.ID
						END
					) AS instock,
					COUNT(DISTINCT CASE WHEN l.stock_status = %s THEN p.ID END) AS productos_preventa,
					COUNT(
						DISTINCT CASE
							WHEN l.stock_status = %s AND {$tomo_normal} THEN p.ID
						END
					) AS preventa,
					COUNT(DISTINCT CASE WHEN l.stock_status = %s THEN p.ID END) AS productos_agotados,
					COUNT(
						DISTINCT CASE
							WHEN l.stock_status = %s AND {$tomo_normal} THEN p.ID
						END
					) AS agotados,
					GROUP_CONCAT(
						DISTINCT CASE
							WHEN l.stock_status = %s AND {$tomo_normal} THEN p.post_title
						END
						SEPARATOR %s
					) AS agotados_titulos,
					MIN(NULLIF(CASE WHEN l.stock_status <> %s THEN l.min_price END, 0)) AS price_min,
					MAX(NULLIF(CASE WHEN l.stock_status <> %s THEN l.max_price END, 0)) AS price_max,
					MAX(p.post_modified) AS updated,
					GROUP_CONCAT(
						DISTINCT CONCAT_WS(%s, aut.name, aut.term_id)
						ORDER BY CONCAT_WS(%s, aut.name, aut.term_id)
						SEPARATOR %s
					) AS autores,
					GROUP_CONCAT(
						DISTINCT CONCAT_WS(%s, bt.name, bt.term_id)
						ORDER BY CONCAT_WS(%s, bt.name, bt.term_id)
						SEPARATOR %s
					) AS editoriales,
					MAX(
						CASE WHEN {$tomo_normal} THEN CONCAT(p.post_title, %s, p.ID) END
					) AS ultimo_tomo
				FROM {$wpdb->term_relationships} tr
				INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id
				INNER JOIN {$lookup} l ON l.product_id = p.ID
				LEFT JOIN {$wpdb->postmeta} vm ON vm.post_id = p.ID AND vm.meta_key = %s
				LEFT JOIN {$wpdb->term_relationships} btr ON btr.object_id = p.ID
					AND btr.term_taxonomy_id IN (
						SELECT btt2.term_taxonomy_id FROM {$wpdb->term_taxonomy} btt2 WHERE btt2.taxonomy = %s
					)
				LEFT JOIN {$wpdb->term_taxonomy} btt ON btt.term_taxonomy_id = btr.term_taxonomy_id
				LEFT JOIN {$wpdb->terms} bt ON bt.term_id = btt.term_id
				LEFT JOIN {$wpdb->term_relationships} autr ON autr.object_id = p.ID
					AND autr.term_taxonomy_id IN (
						SELECT autt2.term_taxonomy_id FROM {$wpdb->term_taxonomy} autt2 WHERE autt2.taxonomy = %s
					)
				LEFT JOIN {$wpdb->term_taxonomy} autt ON autt.term_taxonomy_id = autr.term_taxonomy_id
				LEFT JOIN {$wpdb->terms} aut ON aut.term_id = autt.term_id
				WHERE tr.term_taxonomy_id = %d
					AND p.post_type = 'product'
					AND p.post_status = 'publish'
					AND NOT EXISTS (
						SELECT 1
						FROM {$wpdb->term_relationships} vtr
						INNER JOIN {$wpdb->term_taxonomy} vtt ON vtt.term_taxonomy_id = vtr.term_taxonomy_id
						INNER JOIN {$wpdb->terms} vt ON vt.term_id = vtt.term_id
						WHERE vtr.object_id = p.ID
							AND vtt.taxonomy = %s
							AND vt.slug = %s
					)",
				// El orden de estos valores es el orden TEXTUAL de los `%s`/`%d` en
				// la consulta de arriba, NO el de las columnas del resultado:
				// `prepare()` sustituye por posición. Al mover una línea del SELECT
				// hay que mover la suya acá, y el síntoma de no hacerlo no es un
				// error: es una ficha que concatena por el nombre de una taxonomía
				// y sigue saliendo verde.
				//
				// `$variant_kinds` aparece una vez por cada `{$tomo_normal}` del
				// SELECT y aporta tantos valores como tipos tenga el enum, así que
				// se arma con `array_merge()` en vez de a mano: añadir un tipo de
				// variante no vuelve a repartir posiciones.
				...array_merge(
					$variant_kinds,                        // Tomos (sin variantes).
					array( StockStatus::IN_STOCK ),        // Productos disponibles.
					array( StockStatus::IN_STOCK ),        // Tomos disponibles…
					$variant_kinds,                        // …sin variantes.
					array( StockStatus::ON_BACKORDER ),    // Productos reservables.
					array( StockStatus::ON_BACKORDER ),    // Tomos reservables…
					$variant_kinds,                        // …sin variantes.
					array( StockStatus::OUT_OF_STOCK ),    // Productos agotados.
					array( StockStatus::OUT_OF_STOCK ),    // Tomos agotados…
					$variant_kinds,                        // …sin variantes.
					array( StockStatus::OUT_OF_STOCK ),    // Títulos de esos mismos tomos…
					$variant_kinds,                        // …con el MISMO filtro que el conteo.
					array(
						self::LIST_SEPARATOR,
						StockStatus::OUT_OF_STOCK,  // Extremos del rango de precios.
						StockStatus::OUT_OF_STOCK,
						self::PAIR_SEPARATOR,       // Autores: par, orden y separador.
						self::PAIR_SEPARATOR,
						self::LIST_SEPARATOR,
						self::PAIR_SEPARATOR,       // Editoriales: par, orden y separador.
						self::PAIR_SEPARATOR,
						self::LIST_SEPARATOR,
					),
					$variant_kinds,                        // Último tomo: sin variantes…
					array(
						self::PAIR_SEPARATOR,       // …y con su ID detrás del título.
						VolumeVariant::META_KEY,    // Marca de variante del mismo tomo.
						self::BRAND_TAXONOMY,
						self::AUTHOR_TAXONOMY,
						$term_taxonomy_id,
						SeriesCatalogEligibility::VISIBILITY_TAXONOMY,
						SeriesCatalogEligibility::HIDDEN_FROM_CATALOG,
					)
				)
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		// `null` NO significa "esta serie no tiene catálogo": con `COUNT()` y sin
		// `GROUP BY`, MySQL devuelve SIEMPRE una fila, así que la única lectura
		// posible es un error (lookup ausente tras un update de WooCommerce,
		// deadlock, conexión caída). Devolverlo como fila vacía lo convertiría en
		// `tomos = 0` y `facts()` lo dejaría cacheado 6 h: un hipo de un solo
		// request borraría la ficha de esa serie por seis horas.
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Normaliza la fila cruda descartando todo lo que no sea un dato válido.
	 *
	 * @param array<string, mixed> $row Fila de `query_row()`.
	 * @return array{tomos:int,instock:int,preventa:int,agotados:int,productos:int,productos_instock:int,productos_preventa:int,productos_agotados:int,agotados_tomos:array<int,string>,price_min:?float,price_max:?float,updated:string,autores:array<int,array{name:string,term_id:int}>,editoriales:array<int,array{name:string,term_id:int}>,ultimo_tomo:array{id:int,numero:int}|null}
	 */
	private function shape( array $row ): array {
		$min = isset( $row['price_min'] ) ? (float) $row['price_min'] : 0.0;
		$max = isset( $row['price_max'] ) ? (float) $row['price_max'] : 0.0;

		// Tomos agotados, sin variantes: es el número que `out_of_stock_volumes()`
		// tiene que igualar con su lista, y por eso los dos salen del mismo filtro.
		$agotados = max( 0, (int) ( $row['agotados'] ?? 0 ) );

		// Un precio 0 o negativo no es "gratis": es un producto sin precio
		// cargado. El `NULLIF` de la consulta ya los descarta uno por uno —antes
		// bastaba UN tomo sin precio para tumbar el rango de la serie entera— y
		// acá se comprueba lo que sobrevivió. Si cualquiera de los dos extremos
		// falta, el rango se descarta: la mitad de un rango es peor que ninguno.
		$prices_ok = $min > 0 && $max > 0 && $max >= $min;

		// DOS familias de conteos, y la distinción no es cosmética. Las claves sin
		// prefijo cuentan TOMOS —una edición especial del 23 no es un tomo más— y
		// son las que publica este bloque, que habla en tomos. Las `productos_*`
		// cuentan PRODUCTOS y existen para quien etiqueta la rejilla: los chips de
		// «Arma tu pack» dicen cuántas cards hay detrás de cada filtro, y un chip
		// que promete 23 sobre 24 cards es el defecto de siempre —el número que se
		// contradice a un scroll de distancia—. Mientras ninguna serie tenga dos
		// productos con el mismo número, las dos familias dan lo mismo.
		return array(
			'tomos'              => max( 0, (int) ( $row['tomos'] ?? 0 ) ),
			'instock'            => max( 0, (int) ( $row['instock'] ?? 0 ) ),
			'preventa'           => max( 0, (int) ( $row['preventa'] ?? 0 ) ),
			'agotados'           => $agotados,
			'productos'          => max( 0, (int) ( $row['productos'] ?? 0 ) ),
			'productos_instock'  => max( 0, (int) ( $row['productos_instock'] ?? 0 ) ),
			'productos_preventa' => max( 0, (int) ( $row['productos_preventa'] ?? 0 ) ),
			'productos_agotados' => max( 0, (int) ( $row['productos_agotados'] ?? 0 ) ),
			// Lista vacía = el conteo se publica pelado, como hasta hoy. Es un
			// degradado normal, no un error: ver `out_of_stock_volumes()`.
			'agotados_tomos'     => $this->out_of_stock_volumes( (string) ( $row['agotados_titulos'] ?? '' ), $agotados ),
			'price_min'          => $prices_ok ? $min : null,
			'price_max'          => $prices_ok ? $max : null,
			'updated'            => $this->iso_date( (string) ( $row['updated'] ?? '' ) ),
			'autores'            => $this->term_pairs( (string) ( $row['autores'] ?? '' ) ),
			'editoriales'        => $this->term_pairs( (string) ( $row['editoriales'] ?? '' ) ),
			'ultimo_tomo'        => $this->latest_volume( (string) ( $row['ultimo_tomo'] ?? '' ) ),
		);
	}

	/**
	 * ID y número del tomo más nuevo, o null si no se puede afirmar.
	 *
	 * Un título sin número de tomo inequívoco («Uzumaki (Integral)», «Ghost in
	 * the Shell 1.5») no se convierte en un «Último tomo: 1»: no hay atajo.
	 *
	 * @param string $pair `título<US>ID` de `query_row()`.
	 * @return array{id:int,numero:int}|null
	 */
	private function latest_volume( string $pair ): ?array {
		$cut = strrpos( $pair, self::PAIR_SEPARATOR );
		if ( false === $cut ) {
			return null;
		}

		$id     = (int) substr( $pair, $cut + strlen( self::PAIR_SEPARATOR ) );
		$number = VolumeTitle::extract_number( substr( $pair, 0, $cut ) );
		if ( $id < 1 || null === $number ) {
			return null;
		}

		return array(
			'id'     => $id,
			'numero' => $number,
		);
	}

	/**
	 * Números de los tomos agotados, en orden numérico, o lista vacía si no se
	 * pueden afirmar TODOS.
	 *
	 * ── Por qué "todos o ninguno" ───────────────────────────────────────────
	 * Este renglón responde "¿cuáles me van a faltar?". Una lista incompleta
	 * responde algo distinto y peor: el lector que lee "Agotado: 4 tomos: 03 y
	 * 06" concluye que el 08 y el 11 están disponibles, y compra creyendo que
	 * puede terminar la serie. Un conteo pelado no le dice cuáles, pero tampoco
	 * le miente. Por eso cualquier duda —un título que no matchea la gramática,
	 * menos números que agotados, dos tomos con el mismo número, un
	 * `GROUP_CONCAT` sospechoso de truncamiento— devuelve la lista vacía y el
	 * renglón vuelve a ser exactamente el que había antes de este cambio. Es la
	 * regla 1 de honestidad del módulo aplicada a una lista.
	 *
	 * El orden es numérico y se hace acá, no en SQL: `ORDER BY post_title` es
	 * lexicográfico y publicaría "11, 3, 6, 8" — el orden equivocado justo en el
	 * dato cuya utilidad es saber por dónde se corta la serie.
	 *
	 * Las variantes del mismo tomo (una edición especial marcada en su ficha) no
	 * llegan hasta acá: el agregado ya las deja fuera del `GROUP_CONCAT` Y del
	 * conteo que se compara con la lista. Filtrarlas después, con el conteo hecho
	 * sobre todos los productos, habría dado el peor resultado posible —una lista
	 * de cuatro números bajo el rótulo «5 tomos»—; y no filtrarlas tumbaba la
	 * lista entera de la serie, porque «Demon Slayer 23 (Edición Especial)» no
	 * matchea `VOLUME_PATTERN` y una sola línea sin número la vacía toda.
	 *
	 * @param string $concat   Títulos separados por `LIST_SEPARATOR`.
	 * @param int    $agotados Tomos agotados del agregado, que es la verdad a igualar.
	 * @return array<int, string> Números tal como los escribe el título ("03").
	 */
	private function out_of_stock_volumes( string $concat, int $agotados ): array {
		if ( $agotados < 1 || '' === $concat ) {
			return array();
		}

		// Ver `CONCAT_SAFE_BYTES`: un corte de `group_concat_max_len` no avisa, y
		// si cae dentro del último título el conteo cuadra igual con un número
		// mutilado. Se descarta por longitud antes de mirar el contenido.
		if ( strlen( $concat ) >= self::CONCAT_SAFE_BYTES ) {
			return array();
		}

		$volumes = array();
		foreach ( explode( self::LIST_SEPARATOR, $concat ) as $title ) {
			if ( 1 !== preg_match( self::VOLUME_PATTERN, $title, $matches ) ) {
				// Un tomo único ("Akira. Edición Original"), un pack o un título
				// que el dueño escribió distinto. No hay número que nombrar y la
				// lista entera pierde sentido.
				return array();
			}

			// Keyed por el valor numérico: "3" y "03" son el MISMO tomo escrito de
			// dos formas, y publicarlos como dos entradas sería un dato falso.
			$volumes[ (int) $matches[2] ] = $matches[2];
		}

		// El agregado ya contó los agotados con `COUNT(DISTINCT p.ID)`. Si acá
		// quedan menos números, algo se perdió por el camino (un `DISTINCT` que
		// colapsó dos títulos idénticos, dos tomos con el mismo número, un corte)
		// y la lista dejaría fuera un tomo sin decirlo.
		if ( count( $volumes ) !== $agotados ) {
			return array();
		}

		ksort( $volumes, SORT_NUMERIC );

		return array_values( $volumes );
	}

	/**
	 * Los 10 primeros caracteres de `post_modified`, que YA viene en la zona del
	 * sitio. Cadena vacía si no tiene la forma exacta `YYYY-MM-DD`.
	 */
	private function iso_date( string $modified ): string {
		$iso = substr( $modified, 0, 10 );

		return 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $iso ) ? $iso : '';
	}

	/**
	 * Parsea los pares nombre/term_id de un `GROUP_CONCAT`. Sirve a las dos
	 * listas de términos del agregado: editoriales y autores.
	 *
	 * Los productos sin el término asignado aportan un par VACÍO y no `NULL`:
	 * `CONCAT_WS` ignora los argumentos nulos y devuelve cadena vacía, que
	 * `GROUP_CONCAT` sí concatena (a diferencia de un `NULL`, que descartaba).
	 * De ahí que el filtro por nombre no vacío siga siendo obligatorio —
	 * verificado contra la base local, donde One Piece devuelve un elemento
	 * vacío delante de «Ivrea Argentina» y The Climber uno delante de «Shinichi
	 * Sakamoto».
	 *
	 * Un par sin id utilizable conserva el nombre con `term_id` 0: el dato que
	 * este bloque promete es el nombre, no su enlace, así que la falta del id
	 * degrada a texto plano en vez de borrar el renglón.
	 *
	 * @return array<int, array{name:string,term_id:int}>
	 */
	private function term_pairs( string $concat ): array {
		if ( '' === $concat ) {
			return array();
		}

		$terms = array();
		foreach ( explode( self::LIST_SEPARATOR, $concat ) as $entry ) {
			$parts = explode( self::PAIR_SEPARATOR, $entry, 2 );
			$name  = trim( (string) ( $parts[0] ?? '' ) );
			if ( '' === $name || isset( $terms[ $name ] ) ) {
				continue;
			}

			$terms[ $name ] = array(
				'name'    => $name,
				'term_id' => max( 0, (int) ( $parts[1] ?? 0 ) ),
			);
		}

		return array_values( $terms );
	}

	/**
	 * Enumeración en español, resumida cuando pasa del tope: «a, b y c» o
	 * «a, b, c y N más».
	 *
	 * El "y" antes del último elemento no es cosmético: sin él, "01, 03, 06, 08"
	 * se lee como una lista que podría seguir, y este renglón afirma justamente
	 * que NO sigue.
	 *
	 * Sólo se resume cuando el sobrante es de 2 o más: con uno solo, "y 1 más"
	 * ocupa más que el propio elemento y esconde un dato por nada.
	 *
	 * @param array<int, string> $parts Elementos YA escapados (pueden ser enlaces).
	 * @param int                $max   Cuántos se nombran antes de resumir.
	 * @return string HTML ya escapado.
	 */
	private function summarized_list( array $parts, int $max ): string {
		$total = count( $parts );

		if ( $total > $max + 1 ) {
			$shown = array_slice( $parts, 0, $max );

			return implode( ', ', $shown ) . esc_html(
				sprintf( ' y %s más', number_format_i18n( $total - $max ) )
			);
		}

		if ( $total < 2 ) {
			return (string) ( $parts[0] ?? '' );
		}

		$last = array_pop( $parts );

		return implode( ', ', $parts ) . ' y ' . $last;
	}

	/**
	 * Estados de la edición exacta y de la obra original, con alcance explícito.
	 *
	 * La edición sólo se atribuye cuando el catálogo deriva UNA editorial. Con
	 * cero o varias no se adivina cuál edición describen las metas: se omite esa
	 * fila y la obra original sigue siendo publicable. Si ambas frases repetirían
	 * el estado y la obra no aporta un total distinto, se omite esa repetición;
	 * cuando difieren se muestran las dos para que «obra finalizada» nunca se lea
	 * como «edición completa».
	 *
	 * El term meta se lee fresco, fuera del transient de seis horas del catálogo.
	 * `get_queried_object()` se vuelve a pedir porque `queried_series_tt_id()`
	 * devuelve `term_taxonomy_id`, mientras `wp_termmeta` se indexa por `term_id`.
	 *
	 * @param array{tomos:int,editoriales?:array<int,array{name:string,term_id:int}>} $facts Hechos normalizados del catálogo.
	 * @return void
	 */
	private function render_publication_rows( array $facts ): void {
		foreach ( $this->publication_phrases( $facts ) as $pair ) {
			$this->render_publication_phrase( $pair['label'], $pair['phrase'] );
		}
	}

	/**
	 * Frases de publicación con su rótulo, en el orden en que se publican: la
	 * edición exacta primero y la obra original sólo si aporta algo distinto.
	 *
	 * Una sola fuente para la ficha completa y para el resumen de arriba: si el
	 * resumen compusiera su propia frase, las dos podrían decir cosas distintas
	 * del mismo término a unos miles de píxeles de distancia.
	 *
	 * @param array{tomos:int,editoriales?:array<int,array{name:string,term_id:int}>} $facts Hechos normalizados del catálogo.
	 * @return list<array{label:string,phrase:array{titular:string,detalle:string}}>
	 */
	private function publication_phrases( array $facts ): array {
		$term    = get_queried_object();
		$term_id = ( is_object( $term ) && isset( $term->term_id ) ) ? (int) $term->term_id : 0;
		$scoped  = PublicationStatus::scoped_for_term( $term_id );
		$work    = $scoped['obra'];
		$edition = $scoped['edicion'];
		$work_phrase = PublicationStatus::work_phrase(
			(string) $work['estado'],
			(int) $work['tomos'],
			(int) ( $facts['tomos'] ?? 0 )
		);

		$pairs          = array();
		$edition_phrase = array();
		$publishers     = is_array( $facts['editoriales'] ?? null ) ? $facts['editoriales'] : array();
		if ( 1 === count( $publishers ) && is_array( $publishers[0] ) ) {
			$publisher_name = trim( (string) ( $publishers[0]['name'] ?? '' ) );
			if ( '' !== $publisher_name ) {
				$edition_phrase = PublicationStatus::edition_phrase(
					(string) $edition['estado'],
					(int) $edition['tomos']
				);
				if ( array() !== $edition_phrase ) {
					$pairs[] = array(
						'label'  => 'Edición ' . $publisher_name,
						'phrase' => $edition_phrase,
					);
				}
			}
		}

		$work_adds_information = array() === $edition_phrase
			|| (string) $work['estado'] !== (string) $edition['estado']
			|| ( '' !== (string) ( $work_phrase['detalle'] ?? '' )
				&& (string) ( $work_phrase['detalle'] ?? '' ) !== (string) ( $edition_phrase['detalle'] ?? '' ) );

		if ( $work_adds_information && array() !== $work_phrase ) {
			$pairs[] = array(
				'label'  => 'Obra original',
				'phrase' => $work_phrase,
			);
		}

		return $pairs;
	}

	/**
	 * Imprime una frase de publicación ya compuesta, si existe.
	 *
	 * @param string                                           $label  Alcance visible del dato.
	 * @param array{titular:string,detalle:string}|array{} $phrase Frase sin escapar, o array vacío.
	 * @return void
	 */
	private function render_publication_phrase( string $label, array $phrase ): void {
		if ( array() === $phrase ) {
			return;
		}

		$value = esc_html( $phrase['titular'] );
		if ( '' !== $phrase['detalle'] ) {
			// El espacio fuera del span conserva una sola frase en accesibilidad y
			// cuando la hoja no carga; el CSS puede llevar el detalle a otra línea.
			$value .= ' ' . sprintf(
				'<span class="akb-series-facts__note">%s</span>',
				esc_html( $phrase['detalle'] )
			);
		}

		$this->render_item( $label, $value );
	}

	/**
	 * Conteos por estado de stock.
	 *
	 * "Disponible" y "Preventa" se imprimen siempre (un 0 ahí es el dato: la
	 * serie está completa en preventa, o completa en bodega). "Agotado" sólo
	 * cuando existe, porque su única función es que la suma cuadre con el total:
	 * sin él, una serie con tomos descatalogados publicaría 107 arriba y
	 * 31 + 64 = 95 abajo, y el lector concluiría —con razón— que el bloque miente.
	 * Un cuarto renglón fijo en 0 no aporta eso y sí ruido: 75 de las 101 series
	 * con catálogo no tienen ni un agotado.
	 *
	 * ── Por qué "Agotado" además NOMBRA los tomos ───────────────────────────
	 * El conteo pelado deja al lector con la peor mitad del dato. En The Climber
	 * los agotados son el 03, 06, 08 y 11 mientras el 01 y el 02 están
	 * disponibles: quien llega desde Google compra los dos primeros y choca con
	 * el tercero. Y el dato que lo habría evitado sí está en la página, pero
	 * repartido en 17 cards a lo largo de 6.432 px de scroll — o sea, en móvil
	 * (72,5% del tráfico) no está. Nombrarlos acá no publica nada nuevo: es el
	 * MISMO hecho que ya pintan los badges, dicho una vez y arriba.
	 *
	 * Los otros dos estados no lo necesitan: "Disponible" y "Preventa" son lo que
	 * el lector puede comprar hoy y la rejilla los muestra primero; el que se
	 * esconde al final del orden por defecto es justamente el agotado.
	 *
	 * @param array{instock:int,preventa:int,agotados:int,agotados_tomos?:array<int,string>} $facts Hechos normalizados.
	 * @return void
	 */
	private function render_stock_rows( array $facts ): void {
		foreach ( self::stock_rows( $facts ) as $row ) {
			$status = $row['status'];
			$value  = $row['texto'];

			// `??` y no acceso directo: `facts()` acepta cualquier array cacheado
			// que traiga `tomos`, así que una ficha del formato anterior servida
			// desde un transient superviviente debe degradar al conteo, no morir
			// con `Undefined array key` en 106 landings (ver `TRANSIENT_PREFIX`).
			$volumes = StockStatus::OUT_OF_STOCK === $status
				? ( $facts['agotados_tomos'] ?? array() )
				: array();

			$this->render_item(
				// La palabra visible sale de la fuente única del vocabulario, no
				// de un literal: el badge de cada card de abajo dice exactamente
				// lo mismo porque deriva del mismo sitio.
				$row['label'],
				array() === $volumes
					? esc_html( $value )
					// Los dos puntos y el espacio van en el conteo, no delante de
					// la lista: el CSS pone los números en su propio renglón, pero
					// en el árbol de accesibilidad —y si la hoja no carga— son lo
					// único que separa "4 tomos" de "03, 06, 08 y 11", que leídos
					// de corrido suenan a una sola cifra.
					: esc_html( $value . ': ' ) . sprintf(
						'<span class="akb-series-facts__vols">%s</span>',
						// Se escapa ANTES de enumerar: `summarized_list()` recibe
						// partes ya escapadas (las del autor son enlaces) y devuelve
						// HTML, así que escaparlo de nuevo acá sería doble escape.
						$this->summarized_list( array_map( 'esc_html', $volumes ), self::MAX_LISTED_VOLUMES )
					)
			);
		}
	}

	/**
	 * Comparte las filas de disponibilidad de la ficha y la tarjeta de guía.
	 *
	 * Disponible y A pedido se muestran incluso en cero; Agotado sólo si hay algún
	 * tomo. La etiqueta procede del vocabulario canónico y el texto conserva
	 * singular/plural, para que las dos superficies no recalculen reglas distintas.
	 *
	 * @param array{instock:int,preventa:int,agotados:int} $facts Hechos normalizados.
	 * @return array<int,array{status:string,label:string,count:int,texto:string}> Texto plano.
	 */
	public static function stock_rows( array $facts ): array {
		$counts = array(
			StockStatus::IN_STOCK     => (int) ( $facts['instock'] ?? 0 ),
			StockStatus::ON_BACKORDER => (int) ( $facts['preventa'] ?? 0 ),
		);
		$agotados = (int) ( $facts['agotados'] ?? 0 );
		if ( $agotados > 0 ) {
			$counts[ StockStatus::OUT_OF_STOCK ] = $agotados;
		}

		$rows = array();
		foreach ( $counts as $status => $count ) {
			$rows[] = array(
				'status' => (string) $status,
				'label'  => StockStatus::label( (string) $status ),
				'count'  => $count,
				'texto'  => number_format_i18n( $count ) . ( 1 === $count ? ' tomo' : ' tomos' ),
			);
		}

		return $rows;
	}

	/**
	 * Rango de precios con el formato del sitio.
	 *
	 * Se omite cuando falta cualquiera de los extremos, cuando WooCommerce no
	 * está cargado (`wc_price` ausente) o cuando hay un override de precio en
	 * runtime — ver la propiedad `$price_is_overridden` para el porqué de este
	 * último caso.
	 *
	 * @param array{price_min:?float,price_max:?float} $facts Hechos normalizados.
	 * @return void
	 */
	private function render_price_row( array $facts ): void {
		$price = $this->price_pair( $facts );
		if ( null === $price ) {
			return;
		}

		$this->render_item( $price['label'], $price['value'] );
	}

	/**
	 * Rótulo y valor del rango de precios, o null si no se puede publicar.
	 *
	 * Lo comparten el renglón de la ficha y el resumen de arriba, con el MISMO
	 * criterio de veracidad (ver `price_range_is_publishable()`).
	 *
	 * @param array{price_min:?float,price_max:?float} $facts Hechos normalizados.
	 * @return array{label:string,value:string}|null Valor ya escapado.
	 */
	private function price_pair( array $facts ): ?array {
		// El criterio de veracidad vive en UN solo sitio, compartido con el
		// `AggregateOffer` del JSON-LD (ver `price_range_is_publishable()`).
		if ( ! $this->price_range_is_publishable( $facts ) ) {
			return null;
		}
		if ( ! function_exists( 'wc_price' ) ) {
			return null;
		}

		$min = (float) $facts['price_min'];
		$max = (float) $facts['price_max'];

		// `wc_format_price_range()` es el formateador de rangos del propio
		// WooCommerce (el mismo que rinde los productos variables), así que el
		// separador y el símbolo salen de la configuración del sitio.
		$value = ( $min < $max && function_exists( 'wc_format_price_range' ) )
			? wc_format_price_range( $min, $max )
			: wc_price( $min );

		return array(
			'label' => $min < $max ? 'Precios' : 'Precio',
			'value' => wp_kses_post( (string) $value ),
		);
	}

	/**
	 * Editorial(es) de la serie, enlazadas a su archive `/marca/<slug>/`.
	 *
	 * ── Por qué esto cambió ─────────────────────────────────────────────────
	 * Este renglón imprimía el nombre PELADO. La razón declarada era que
	 * `get_term_link()` puede devolver `WP_Error` y que un enlace roto le sale
	 * caro a un bloque cuyo contrato es "todo lo que dice es verificable". La
	 * premisa sigue siendo cierta; la conclusión no, porque el costo del otro
	 * lado resultó mayor: la landing de serie no enlaza a NINGUNA otra serie ni
	 * a la editorial, así que era un callejón sin salida — 106 páginas que
	 * reciben 4.044 impresiones cada 90 días y de las que sólo se sale hacia
	 * arriba o hacia un producto. Enlazar la marca son 106 enlaces internos
	 * hacia un archive que ya existe y ya está indexado, sin escribir una línea
	 * de contenido nuevo.
	 *
	 * El riesgo original se atiende donde corresponde y no cerrando el enlace:
	 * `get_term_link()` se llama, se comprueba que devolvió un string, y si no,
	 * se imprime el nombre pelado exactamente como antes. El caso degradado es
	 * el comportamiento anterior, no un enlace roto — que es la diferencia que
	 * hace admisible la reversión.
	 *
	 * @param array{editoriales:array<int,array{name:string,term_id:int}>} $facts Hechos normalizados.
	 * @return void
	 */
	private function render_brand_row( array $facts ): void {
		if ( empty( $facts['editoriales'] ) ) {
			return; // Sin marca asignada no hay editorial que declarar.
		}

		$rendered = array();
		foreach ( $facts['editoriales'] as $brand ) {
			$rendered[] = $this->term_markup( (string) $brand['name'], (int) $brand['term_id'], self::BRAND_TAXONOMY );
		}

		$this->render_item(
			1 === count( $facts['editoriales'] ) ? 'Editorial' : 'Editoriales',
			// Las editoriales del catálogo son 8: no hay caso en que esta lista
			// crezca lo suficiente como para necesitar el resumen de los autores.
			implode( ', ', $rendered )
		);
	}

	/**
	 * Enlaza los autores de la serie con sus archivos de autor.
	 *
	 * Los términos se muestran en orden alfabético y, tras MAX_LISTED_AUTHORS, se
	 * resume el resto como y N más. No se infiere un autor principal ni una jerarquía
	 * guion/dibujo a partir del orden de relaciones: el catálogo no la declara.
	 * El límite acota la altura de una fila con múltiples autores en móvil.
	 *
	 * @param array{autores:array<int,array{name:string,term_id:int}>} $facts Hechos normalizados.
	 * @return void
	 */
	private function render_author_row( array $facts ): void {
		if ( empty( $facts['autores'] ) ) {
			return; // Sin autor asignado no hay nadie que declarar.
		}

		$rendered = array();
		foreach ( $facts['autores'] as $author ) {
			$rendered[] = $this->term_markup( (string) $author['name'], (int) $author['term_id'], self::AUTHOR_TAXONOMY );
		}

		$this->render_item(
			1 === count( $facts['autores'] ) ? 'Autor' : 'Autores',
			$this->summarized_list( $rendered, self::MAX_LISTED_AUTHORS )
		);
	}

	/**
	 * Nombre de término enlazado, o el nombre pelado si no hay enlace válido.
	 *
	 * @param string $name     Nombre del término.
	 * @param int    $term_id  `term_id` que llegó pegado al nombre en el agregado.
	 * @param string $taxonomy `BRAND_TAXONOMY` o `AUTHOR_TAXONOMY`.
	 * @return string HTML ya escapado.
	 */
	private function term_markup( string $name, int $term_id, string $taxonomy ): string {
		if ( $term_id < 1 || ! function_exists( 'get_term_link' ) ) {
			return esc_html( $name );
		}

		$link = get_term_link( $term_id, $taxonomy );
		if ( ! is_string( $link ) || '' === $link ) {
			// `WP_Error` (término borrado entre la consulta y el render, o
			// taxonomía aún sin registrar) degrada al texto de siempre.
			return esc_html( $name );
		}

		return sprintf(
			'<a class="akb-series-facts__link" href="%s">%s</a>',
			esc_url( $link ),
			esc_html( $name )
		);
	}

	/**
	 * Señal de frescura: `<time>` legible por máquina y texto legible por
	 * persona, construidos ambos desde los mismos tres substrings del ISO.
	 *
	 * @param array{updated:string} $facts Hechos normalizados.
	 * @return void
	 */
	private function render_updated_row( array $facts ): void {
		$iso = $facts['updated'];
		if ( '' === $iso ) {
			return; // Mejor ninguna fecha que una inventada.
		}

		// Tabla de meses y no `wp_date()`: la regla dura de este bloque es que el
		// `datetime` de la máquina y el texto de la persona salgan del MISMO dato.
		// `post_modified` ya viene en la zona del sitio, así que la fecha son sus
		// 10 primeros caracteres; pasarla por `strtotime()` + `wp_date()` la
		// interpreta en UTC al leer y la reconvierte al formatear, y eso ya
		// produjo en `/disponibles/` un datetime corrido un día respecto del texto
		// visible — justo la incoherencia que Google pide evitar. Formateando
		// desde los tres substrings no hay ninguna zona horaria de por medio.
		$year  = (int) substr( $iso, 0, 4 );
		$month = (int) substr( $iso, 5, 2 );
		$day   = (int) substr( $iso, 8, 2 );
		$name  = PedidoPolicy::mes( $month );
		if ( '' === $name ) {
			return;
		}

		$human = sprintf( '%d de %s de %d', $day, $name, $year );

		$this->render_item(
			// "Catálogo actualizado" y no "Actualizado" a secas: el dato es
			// `MAX(post_modified)` de los productos de la serie, que sube con
			// cualquier guardado —incluida una baja de stock por una venta—. Es
			// exactamente la señal de frescura que se busca publicar, pero
			// "Actualizado" bajo una ficha se lee como "esta ficha se reescribió",
			// que no es lo que ocurrió.
			'Catálogo actualizado',
			sprintf(
				'<time datetime="%s">%s</time>',
				esc_attr( $iso ),
				esc_html( $human )
			)
		);
	}

	/**
	 * Un par etiqueta/valor del `<dl>`.
	 *
	 * Lista de definición y no tabla: son pares término→dato sin ejes, que es
	 * exactamente lo que un `<dl>` significa. Los `<div>` intermedios son HTML5
	 * válido dentro de `<dl>` y permiten maquetar cada par sin envolver el
	 * contenido en marcado que un lector de pantalla tendría que ignorar.
	 *
	 * @param string $label Etiqueta visible.
	 * @param string $value HTML del valor, YA escapado por quien llama.
	 * @return void
	 */
	private function render_item( string $label, string $value ): void {
		?>
		<div class="akb-series-facts__item">
			<dt class="akb-series-facts__label"><?php echo esc_html( $label ); ?></dt>
			<dd class="akb-series-facts__value"><?php echo $value; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapado en el punto de construcción (esc_html/esc_attr/wp_kses_post). ?></dd>
		</div>
		<?php
	}
}
