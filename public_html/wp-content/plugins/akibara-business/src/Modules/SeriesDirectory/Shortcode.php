<?php
/**
 * Shortcode `[akibara_series_directory]` — el directorio de series.
 *
 * ── Por qué existe ──────────────────────────────────────────────────────────
 * Las 104 landings indexables de `pa_serie` suman 4.044 impresiones y 128 clics
 * cada 90 días en Search Console, y desde hoy publican ficha de datos y meta
 * propia (ver `SeriesFacts`). No tienen NI UNA ruta humana: no están en el menú,
 * ni en la home, ni en el footer. Un rastreador llega a ellas por el sitemap y
 * por los productos; una persona, sólo si adivina la URL. Además `/series/` es
 * hoy una redirección 301 hacia `/tienda/` con 69 impactos y último acceso de
 * hoy: la demanda de esta página está medida, y lo que había en su lugar era un
 * desvío.
 *
 * ── Sin atributos, a propósito ──────────────────────────────────────────────
 * `BrandsDirectory\Shortcode` admite `show_empty="yes"`. Acá no hay ningún
 * atributo, y es una decisión: las dos reglas del conjunto (con productos, sin
 * `noindex`) son el contrato SEO de la página, no una preferencia de maquetado.
 * Un `show_empty="yes"` escrito por descuido en el editor publicaría enlaces a
 * 24 landings vacías y a 2 que le pedimos a Google que no indexe, sin que
 * ninguna prueba lo notara. Lo que no se puede configurar no se puede
 * desconfigurar. Como efecto secundario, el cache tiene UNA clave y no una
 * familia de claves por combinación de atributos.
 *
 * ── Consultas ──────────────────────────────────────────────────────────────────────
 * En cache frío hay una lectura de términos y un agregado de elegibilidad
 * para todo el conjunto. El costo sigue siendo constante respecto de la cantidad
 * de series: nunca existe una consulta por entrada. En cache caliente se devuelve
 * el HTML ya construido sin tocar términos ni productos.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\SeriesDirectory;

use Akibara\Business\Modules\SeriesCatalog\SeriesCatalogEligibility;
use Akibara\Business\Modules\SeriesEditions\SeriesEditions;
use Akibara\Business\Modules\SeriesFacts\PublicationStatus;
use Akibara\Business\Modules\SeriesSchema\SeriesSchemaType;
use Akibara\Business\Services\SeriesHubUrl;
use Akibara\Business\Services\VolumeVariant;
use Akibara\Business\Utilities\ProductVisibility;

defined( 'ABSPATH' ) || exit;

/**
 * Render y cacheo del directorio de series.
 *
 * @since 1.100.0
 */
final class Shortcode {

	/** Etiqueta del shortcode. */
	public const TAG = 'akibara_series_directory';

	/**
	 * Clave ÚNICA del transient. El `v1` se sube cuando cambia el HTML emitido.
	 *
	 * Mismo razonamiento que `SeriesFacts::TRANSIENT_PREFIX`: lo cacheado es el
	 * resultado de un render, así que un cambio de marcado deja hasta 6 h de
	 * páginas servidas con la estructura vieja y el CSS/JS nuevos. Subir el
	 * número invalida todo de una vez.
	 */
	private const TRANSIENT_KEY = 'akb_series_directory_v4';

	/** Misma ventana que `SeriesFacts`: el dato es el mismo catálogo. */
	private const TTL = 6 * HOUR_IN_SECONDS;

	/** Ancla del grupo de dígitos y signos; `#` no sobrevive a un `id`. */
	private const OTHER_ANCHOR = 'num';

	private SeriesIndex $index;

	/** Evita agendar la misma purga de página varias veces en un request masivo. */
	private bool $hub_purge_scheduled = false;

	public function __construct( ?SeriesIndex $index = null ) {
		$this->index = $index ?? new SeriesIndex();
	}

	/**
	 * Un hook de render y los de invalidación.
	 *
	 * ── Por qué la invalidación es la que es ────────────────────────────────
	 * El transient dura 6 h y sobrevive a cualquier purga de LiteSpeed, así que
	 * es la única capa que puede publicar un dato viejo. Se invalida en los
	 * eventos que pueden cambiar LO QUE LA PÁGINA AFIRMA, que son tres cosas:
	 *
	 *   1. El conjunto de series y sus nombres → alta, edición y baja de
	 *      términos `pa_serie`.
	 *   2. La pertenencia, visibilidad de catálogo y estado publicado de cada tomo
	 *      → `set_object_terms`, `deleted_term_relationships` y
	 *      `transition_post_status`. La remoción directa por API/CLI también queda
	 *      cubierta aunque no termine en `set_object_terms`.
	 *   3. La directiva `noindex` o la clasificación schema de un término →
	 *      `added/updated/deleted_term_meta`, filtrando por un allowlist. Es el
	 *      hook que sostiene la regla
	 *      «el directorio jamás enlaza una landing noindex»: sin él, poner una
	 *      serie en noindex la dejaría enlazada hasta 6 h, que es justo la
	 *      ventana en la que a alguien se le ocurre revisar si funcionó. La
	 *      clasificación no cambia el HTML, pero sí el ItemList JSON-LD de esta
	 *      misma página, así que también exige purgar su caché de página.
	 *
	 * El stock no forma parte de la elegibilidad: un tomo agotado sigue siendo
	 * parte verificable del catálogo. Por eso una venta no purga este directorio.
	 *
	 * @return void
	 */
	public function register(): void {
		add_shortcode( self::TAG, array( $this, 'render' ) );

		add_action( 'created_' . SeriesIndex::TAXONOMY, array( $this, 'flush_cache' ) );
		add_action( 'edited_' . SeriesIndex::TAXONOMY, array( $this, 'flush_cache' ) );
		add_action( 'delete_' . SeriesIndex::TAXONOMY, array( $this, 'flush_cache' ) );

		add_action( 'set_object_terms', array( $this, 'flush_on_term_change' ), 10, 4 );
		add_action( 'deleted_term_relationships', array( $this, 'flush_on_deleted_term_relationships' ), 10, 3 );
		add_action( 'transition_post_status', array( $this, 'flush_on_status_change' ), 10, 3 );

		add_action( 'added_term_meta', array( $this, 'flush_on_public_term_meta' ), 10, 3 );
		add_action( 'updated_term_meta', array( $this, 'flush_on_public_term_meta' ), 10, 3 );
		add_action( 'deleted_term_meta', array( $this, 'flush_on_public_term_meta' ), 10, 3 );

		// Marcar un tomo como otra versión de un número que la serie ya tiene
		// cambia el «N tomos en catálogo» de esa fila sin tocar ni el término ni
		// el estado del post, y se hace por WP-CLI o por importación.
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $meta_hook ) {
			add_action( $meta_hook, array( $this, 'flush_on_variant_meta' ), 10, 3 );
		}
	}

	/**
	 * Invalida cuando cambia la marca de variante de un producto.
	 *
	 * @internal Callback de `added_post_meta`, `updated_post_meta` y `deleted_post_meta`.
	 *
	 * @param mixed $meta_id   Meta ID (o lista, en el borrado). No se usa.
	 * @param mixed $object_id Producto, no usado: hay una sola clave de caché.
	 * @param mixed $meta_key  Clave modificada.
	 * @return void
	 */
	public function flush_on_variant_meta( $meta_id, $object_id, $meta_key ): void {
		unset( $meta_id, $object_id );

		if ( VolumeVariant::META_KEY === $meta_key ) {
			$this->flush_cache();
		}
	}

	/**
	 * Borra el HTML cacheado.
	 *
	 * Acepta y descarta cualquier argumento: se cuelga de hooks con firmas
	 * distintas y ninguno aporta información que cambie la decisión — hay una
	 * sola clave.
	 *
	 * @internal Callback de varios hooks.
	 *
	 * @param mixed $unused Argumento del hook, ignorado.
	 * @return void
	 */
	public function flush_cache( $unused = null ): void {
		unset( $unused );
		$this->index->reset_eligibility_cache();
		delete_transient( self::TRANSIENT_KEY );

		// El transient no es la única capa: LiteSpeed puede seguir sirviendo el
		// HTML completo de la Page que contiene el shortcode. La purga se ejecuta
		// al final, después de todos los recuentos y relaciones del request; hacerla
		// aquí permitiría que otro request recalentara HTML viejo entre dos cambios
		// que luego quedarían coalescidos.
		if ( ! $this->hub_purge_scheduled ) {
			add_action( 'shutdown', array( $this, 'purge_hub_page_cache' ), PHP_INT_MAX );
			$this->hub_purge_scheduled = true;
		}
	}

	/**
	 * Purga una sola vez la página canónica que contiene el directorio.
	 *
	 * SeriesHubUrl resuelve la URL real del shortcode. No se presupone /series/:
	 * si el dueño mueve la página, la invalidación debe seguir su ubicación efectiva.
	 */
	public function purge_hub_page_cache(): void {
		// Segunda barrera: un request concurrente no puede dejar el transient
		// recalentado entre la mutación inicial y el estado final del import/save.
		delete_transient( self::TRANSIENT_KEY );
		do_action( 'litespeed_purge_url', SeriesHubUrl::url() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook público oficial de LiteSpeed Cache.
	}

	/**
	 * Invalida cuando un producto entra o sale de una serie, editorial o país.
	 *
	 * @internal Callback de `set_object_terms`.
	 *
	 * @param mixed  $object_id Producto, no usado.
	 * @param mixed  $terms     Términos, no usado.
	 * @param mixed  $tt_ids    term_taxonomy_id, no usado.
	 * @param string $taxonomy  Taxonomía afectada.
	 * @return void
	 */
	public function flush_on_term_change( $object_id, $terms, $tt_ids, $taxonomy = '' ): void {
		unset( $object_id, $terms, $tt_ids );

		$watched = array(
			SeriesIndex::TAXONOMY,
			SeriesCatalogEligibility::VISIBILITY_TAXONOMY,
			SeriesCatalogEligibility::BRAND_TAXONOMY,
			SeriesCatalogEligibility::COUNTRY_TAXONOMY,
		);

		if ( in_array( (string) $taxonomy, $watched, true ) ) {
			$this->flush_cache();
		}
	}

	/**
	 * Cubre `wp_remove_object_terms()`, que no termina en `set_object_terms`.
	 *
	 * @param mixed  $object_id Producto, no usado.
	 * @param mixed  $tt_ids    Relaciones borradas, no usadas.
	 * @param string $taxonomy  Taxonomía afectada.
	 */
	public function flush_on_deleted_term_relationships( $object_id, $tt_ids, $taxonomy = '' ): void {
		$this->flush_on_term_change( $object_id, array(), $tt_ids, $taxonomy );
	}

	/**
	 * Invalida cuando un producto cambia de estado de publicación.
	 *
	 * Cubre el hueco de `save_post_product`, que NO se dispara al mandar un
	 * producto a la papelera ni al restaurarlo — y las dos cosas mueven el
	 * conteo de su serie.
	 *
	 * @internal Callback de `transition_post_status`.
	 *
	 * @param string $new_status Estado nuevo.
	 * @param string $old_status Estado anterior.
	 * @param mixed  $post       Objeto del post.
	 * @return void
	 */
	public function flush_on_status_change( $new_status, $old_status, $post ): void {
		if ( ProductVisibility::changed( $new_status, $old_status, $post ) ) {
			$this->flush_cache();
		}
	}

	/**
	 * Invalida cuando cambia una meta que altera el HTML o schema del hub.
	 *
	 * @internal Callback de `added_term_meta`, `updated_term_meta` y
	 *           `deleted_term_meta`. Las tres comparten firma salvo que la de
	 *           borrado entrega una lista de meta_id; el parámetro no se usa.
	 *
	 * @param mixed  $meta_id  Id de la meta, no usado.
	 * @param mixed  $term_id  Término, no usado.
	 * @param string $meta_key Clave de la meta.
	 * @return void
	 */
	public function flush_on_public_term_meta( $meta_id, $term_id, $meta_key = '' ): void {
		unset( $meta_id, $term_id );

		$watched = array(
			'rank_math_robots',
			SeriesSchemaType::META_KEY,
			PublicationStatus::META_EDITION_STATE,
			SeriesEditions::META_KEY,
		);

		// El estado de la edición y las ediciones relacionadas alteran las filas
		// y el buscador cruzado del directorio: cambiarlos tiene que verse de inmediato.
		if ( in_array( (string) $meta_key, $watched, true ) ) {
			$this->flush_cache();
		}
	}

	/**
	 * Devuelve el directorio, del cache o recién construido.
	 *
	 * @internal Callback del shortcode.
	 *
	 * @param array<string,string>|string $atts Atributos; el shortcode no define
	 *                                          ninguno (ver el docblock de la clase).
	 * @return string
	 */
	public function render( $atts = array() ): string {
		unset( $atts );

		// Carga defensiva de assets: el shortcode puede ejecutarse desde una
		// plantilla, un widget o un bloque sincronizado, donde no es detectable
		// en `post_content`. Mismo patrón que `BrandsDirectory`.
		do_action( 'akibara_series_directory_assets' );

		$cached = get_transient( self::TRANSIENT_KEY );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$grouped = $this->index->grouped();
		if ( ! $this->index->is_reliable() ) {
			$this->mark_request_uncacheable();

			// Un lote parcial no es una versión publicable del directorio: podría
			// omitir una serie válida durante seis horas. Se reintenta completo en
			// el siguiente request, igual que ante una ausencia total.
			return '';
		}

		if ( array() === $grouped ) {
			// Sin series publicables no se imprime un contenedor vacío ni se
			// cachea la ausencia: es un estado anómalo (taxonomía sin términos,
			// catálogo despublicado) que debe corregirse solo en cuanto vuelva
			// a haber datos.
			return '';
		}

		$html = $this->markup( $grouped );
		set_transient( self::TRANSIENT_KEY, $html, self::TTL );

		return $html;
	}

	/** Evita congelar una respuesta vacía o parcial causada por una lectura fallida. */
	private function mark_request_uncacheable(): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Constante estándar de facto de los plugins de caché (LiteSpeed, W3TC, WP Super Cache): prefijarla la dejaría inerte.
		}
		do_action( 'litespeed_control_set_nocache', 'series catalogue eligibility unavailable' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook público oficial de LiteSpeed Cache.
	}

	/**
	 * Construye el HTML completo.
	 *
	 * @param array<string, list<array<string,mixed>>> $grouped Series por letra.
	 */
	private function markup( array $grouped ): string {
		$total = 0;
		foreach ( $grouped as $entries ) {
			$total += count( $entries );
		}

		ob_start();
		?>
		<div class="akb-series-directory" data-akb-series-directory>
			<?php
			$this->render_filter( $grouped );
			$this->render_letter_index( $grouped );
			?>
			<p
				class="akb-series-directory__count"
				id="akb-series-directory-count"
				role="status"
				aria-live="polite"
				aria-atomic="true"
				data-akb-series-count-all="<?php echo esc_attr( $this->series_label( $total ) ); ?>"
				data-akb-series-count-filtered="<?php echo esc_attr( $this->filtered_series_label( $total ) ); ?>"
				data-akb-series-locale="<?php echo esc_attr( self::bcp47_locale() ); ?>"
			>
				<?php echo esc_html( $this->series_label( $total ) ); ?>
			</p>
			<p class="akb-series-directory__empty" data-akb-series-empty hidden>
				Ninguna serie o edición coincide con tu búsqueda o filtros activos. Prueba con otros criterios.
			</p>
			<div id="akb-series-directory-results">
				<?php foreach ( $grouped as $letter => $entries ) : ?>
					<section class="akb-series-directory__group" id="<?php echo esc_attr( $this->anchor( (string) $letter ) ); ?>" data-akb-series-group>
						<h2 class="akb-series-directory__heading"><?php echo esc_html( (string) $letter ); ?></h2>
						<ul class="akb-series-directory__list">
							<?php foreach ( $entries as $entry ) : ?>
								<?php $this->render_entry( $entry ); ?>
							<?php endforeach; ?>
						</ul>
					</section>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Buscador y filtros rápidos en cliente (País, Editorial, Estado).
	 *
	 * Sale con `hidden` y lo revela el JS. Un input de búsqueda visible que no
	 * hace nada porque el script no cargó es peor que no tener buscador: promete
	 * una función y no la cumple. Con JS desactivado el índice alfabético y las
	 * secciones siguen siendo HTML navegable.
	 *
	 * @param array<string, list<array<string,mixed>>> $grouped Series agrupadas.
	 * @return void
	 */
	private function render_filter( array $grouped = array() ): void {
		$facets = $this->collect_facets( $grouped );
		?>
		<div class="akb-series-directory__filter" data-akb-series-filter hidden>
			<label class="akb-series-directory__filter-label" for="akb-series-directory-input">Buscar una serie o edición</label>
			<input
				type="search"
				id="akb-series-directory-input"
				class="akb-series-directory__input"
				placeholder="Escribe el nombre de la serie o edición"
				autocomplete="off"
				spellcheck="false"
				aria-controls="akb-series-directory-results"
				aria-describedby="akb-series-directory-count"
			>
			<?php if ( array() !== $facets['countries'] || array() !== $facets['brands'] || array() !== $facets['states'] ) : ?>
				<div class="akb-series-directory__facets">
					<?php if ( array() !== $facets['countries'] ) : ?>
						<div class="akb-series-directory__facet" role="group" aria-label="Filtrar por país de la edición">
							<span class="akb-series-directory__facet-label">País</span>
							<div class="akb-series-directory__chips">
								<button type="button" class="akb-series-directory__chip is-active" data-akb-filter-pais="" aria-pressed="true">Todos</button>
								<?php foreach ( $facets['countries'] as $slug => $label ) : ?>
									<button type="button" class="akb-series-directory__chip" data-akb-filter-pais="<?php echo esc_attr( $slug ); ?>" aria-pressed="false"><?php echo esc_html( $label ); ?></button>
								<?php endforeach; ?>
							</div>
						</div>
					<?php endif; ?>

					<?php if ( array() !== $facets['brands'] ) : ?>
						<div class="akb-series-directory__facet" role="group" aria-label="Filtrar por editorial">
							<span class="akb-series-directory__facet-label">Editorial</span>
							<div class="akb-series-directory__chips">
								<button type="button" class="akb-series-directory__chip is-active" data-akb-filter-editorial="" aria-pressed="true">Todas</button>
								<?php foreach ( $facets['brands'] as $slug => $label ) : ?>
									<button type="button" class="akb-series-directory__chip" data-akb-filter-editorial="<?php echo esc_attr( $slug ); ?>" aria-pressed="false"><?php echo esc_html( $label ); ?></button>
								<?php endforeach; ?>
							</div>
						</div>
					<?php endif; ?>

					<?php if ( array() !== $facets['states'] ) : ?>
						<div class="akb-series-directory__facet" role="group" aria-label="Filtrar por estado o formato de la edición">
							<span class="akb-series-directory__facet-label">Estado</span>
							<div class="akb-series-directory__chips">
								<button type="button" class="akb-series-directory__chip is-active" data-akb-filter-estado="" aria-pressed="true">Todas</button>
								<?php foreach ( $facets['states'] as $slug => $label ) : ?>
									<button type="button" class="akb-series-directory__chip" data-akb-filter-estado="<?php echo esc_attr( $slug ); ?>" aria-pressed="false"><?php echo esc_html( $label ); ?></button>
								<?php endforeach; ?>
							</div>
						</div>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Extrae los valores únicos de País, Editorial y Estado presentes en el directorio.
	 *
	 * @param array<string, list<array<string,mixed>>> $grouped Series agrupadas.
	 * @return array{countries:array<string,string>,brands:array<string,string>,states:array<string,string>}
	 */
	private function collect_facets( array $grouped ): array {
		$countries = array();
		$brands    = array();
		$has_open  = false;
		$has_comp  = false;
		$has_one   = false;

		foreach ( $grouped as $entries ) {
			foreach ( $entries as $entry ) {
				$c_slug = (string) ( $entry['country_slug'] ?? '' );
				$c_name = (string) ( $entry['country_name'] ?? '' );
				if ( '' !== $c_slug && '' !== $c_name ) {
					$countries[ $c_slug ] = $c_name;
				}

				$b_slug = (string) ( $entry['brand_slug'] ?? '' );
				$b_name = (string) ( $entry['brand_name'] ?? '' );
				if ( '' !== $b_slug && '' !== $b_name ) {
					$brands[ $b_slug ] = $b_name;
				}

				$estado = (string) ( $entry['estado'] ?? '' );
				$tomos  = (int) ( $entry['tomos'] ?? 0 );
				if ( PublicationStatus::ONGOING === $estado ) {
					$has_open = true;
				} elseif ( PublicationStatus::COMPLETE === $estado ) {
					if ( 1 === $tomos ) {
						$has_one = true;
					} else {
						$has_comp = true;
					}
				}
			}
		}

		asort( $countries, SORT_NATURAL | SORT_FLAG_CASE );
		asort( $brands, SORT_NATURAL | SORT_FLAG_CASE );

		$states = array();
		if ( $has_open ) {
			$states[ PublicationStatus::ONGOING ] = 'En publicación';
		}
		if ( $has_comp ) {
			$states[ PublicationStatus::COMPLETE ] = 'Completas';
		}
		if ( $has_one ) {
			$states['tomo_unico'] = 'Tomo único';
		}

		return array(
			'countries' => $countries,
			'brands'    => $brands,
			'states'    => $states,
		);
	}

	/**
	 * Barra del índice alfabético.
	 *
	 * Las letras sin series se imprimen como `<span>` inerte y no como enlace:
	 * un ancla a una sección que no existe deja al lector donde estaba y a un
	 * rastreador siguiendo un fragmento muerto. Se imprimen igual —en vez de
	 * omitirse— para que la barra conserve la forma del alfabeto; ver
	 * `SeriesIndex::letters()`.
	 *
	 * @param array<string, list<array<string,mixed>>> $grouped Series por letra.
	 * @return void
	 */
	private function render_letter_index( array $grouped ): void {
		?>
		<nav class="akb-series-directory__index" aria-label="Índice alfabético de series y ediciones">
			<ul class="akb-series-directory__letters">
				<?php foreach ( SeriesIndex::letters() as $letter ) : ?>
					<li class="akb-series-directory__letter-item">
						<?php if ( isset( $grouped[ $letter ] ) ) : ?>
							<a class="akb-series-directory__letter" href="#<?php echo esc_attr( $this->anchor( $letter ) ); ?>" data-akb-series-letter><?php echo esc_html( $letter ); ?></a>
						<?php else : ?>
							<span class="akb-series-directory__letter akb-series-directory__letter--empty" aria-hidden="true"><?php echo esc_html( $letter ); ?></span>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</nav>
		<?php
	}

	/**
	 * Una entrada del directorio.
	 *
	 * ── De dónde sale el número de tomos, y por qué de ahí ──────────────────
	 * El número ya llega resuelto por `SeriesCatalogEligibility`: productos
	 * publicados y no `exclude-from-catalog`, el mismo universo de SeriesFacts y
	 * SeriesSchema. Todas las series se cuentan en un solo agregado.
	 *
	 * ── El estado de la edición (UC-14 + Tomo único) ────────────────────────
	 * Una lista de 104 nombres no decía cuáles se pueden completar: el hub no
	 * mostraba «Completa / En publicación / Tomo único», un dato que el dueño ya
	 * carga en cada serie y que la ficha de la landing publica. Va como una marca
	 * pequeña junto al nombre, más el sello de origen (`Editorial · País`) y,
	 * cuando la obra tiene otra edición vinculada, una nota cruzada.
	 *
	 * @param array<string,mixed> $entry Entrada normalizada.
	 * @return void
	 */
	private function render_entry( array $entry ): void {
		$estado      = (string) ( $entry['estado'] ?? '' );
		$tomos       = (int) ( $entry['tomos'] ?? 0 );
		$is_one_shot = ( PublicationStatus::COMPLETE === $estado && 1 === $tomos );
		$rotulo      = $is_one_shot
			? 'Tomo único'
			: ( PublicationStatus::edition_states()[ $estado ] ?? '' );
		$estado_mod  = $is_one_shot
			? 'akb-series-directory__estado--completa akb-series-directory__estado--tomo_unico'
			: 'akb-series-directory__estado--' . $estado;
		$filter_estado = $is_one_shot ? 'tomo_unico' : ( '' !== $rotulo ? $estado : '' );
		$brand_slug    = (string) ( $entry['brand_slug'] ?? '' );
		$country_slug  = (string) ( $entry['country_slug'] ?? '' );
		$origin        = SeriesEditions::format_edition_origin(
			(string) ( $entry['brand_name'] ?? '' ),
			(string) ( $entry['country_name'] ?? '' )
		);
		$related_names = isset( $entry['related_names'] ) && is_array( $entry['related_names'] )
			? $entry['related_names']
			: array();
		?>
		<li
			class="akb-series-directory__item"
			data-akb-serie="<?php echo esc_attr( (string) $entry['haystack'] ); ?>"
			<?php if ( '' !== $country_slug ) : ?>data-akb-pais="<?php echo esc_attr( $country_slug ); ?>"<?php endif; ?>
			<?php if ( '' !== $brand_slug ) : ?>data-akb-editorial="<?php echo esc_attr( $brand_slug ); ?>"<?php endif; ?>
			<?php if ( '' !== $filter_estado ) : ?>data-akb-estado="<?php echo esc_attr( $filter_estado ); ?>"<?php endif; ?>
		>
			<a class="akb-series-directory__link" href="<?php echo esc_url( (string) $entry['url'] ); ?>">
				<span class="akb-series-directory__name"><?php echo esc_html( (string) $entry['name'] ); ?><?php if ( '' !== $rotulo ) : ?><span class="screen-reader-text">, </span><span class="akb-series-directory__estado <?php echo esc_attr( $estado_mod ); ?>"><?php echo esc_html( $rotulo ); ?></span><?php endif; ?><?php if ( '' !== $origin ) : ?><span class="screen-reader-text">, </span><span class="akb-series-directory__origin"><?php echo esc_html( $origin ); ?></span><?php endif; ?><?php if ( array() !== $related_names ) : ?><span class="screen-reader-text"> — </span><span class="akb-series-directory__related-hint">También como: <?php echo esc_html( implode( ', ', $related_names ) ); ?></span><?php endif; ?></span>
				<span class="akb-series-directory__tomos"><?php echo esc_html( $this->tomos_label( $tomos ) ); ?></span>
			</a>
		</li>
		<?php
	}

	/**
	 * Ancla de una sección. `#` no es un `id` válido para un fragmento usable.
	 */
	private function anchor( string $letter ): string {
		$suffix = ( SeriesIndex::OTHER_BUCKET === $letter )
			? self::OTHER_ANCHOR
			: strtolower( $letter );

		return 'akb-series-' . $suffix;
	}

	/** «104 ediciones con tomos en catálogo» / «1 edición con tomos en catálogo». */
	private function series_label( int $total ): string {
		return sprintf(
			'%s %s con tomos en catálogo',
			number_format_i18n( $total ),
			1 === $total ? 'edición' : 'ediciones'
		);
	}

	/**
	 * Plantilla del contador filtrado: «%s de 104 ediciones con tomos en catálogo».
	 *
	 * El `%s` lo sustituye el filtro del navegador con el número de entradas
	 * visibles. La frase entera —incluida la concordancia de «edición/ediciones»,
	 * que depende del TOTAL y no de lo mostrado— se compone acá porque el JS ya
	 * tenía su propia copia del texto y la pisaba en cada carga: el separador de
	 * miles que `number_format_i18n()` pone en el servidor no llegaba nunca a
	 * sobrevivir al primer `apply()`.
	 */
	private function filtered_series_label( int $total ): string {
		return sprintf(
			'%%s de %s %s con tomos en catálogo',
			number_format_i18n( $total ),
			1 === $total ? 'edición' : 'ediciones'
		);
	}

	/**
	 * Locale del sitio en la forma que entiende `Intl.NumberFormat`.
	 *
	 * WordPress guarda `es_CL`; BCP 47 pide `es-CL`. Se emite para que el
	 * navegador agrupe los miles con el MISMO criterio que
	 * `number_format_i18n()` en el servidor, en vez de con el idioma del
	 * navegador del visitante.
	 */
	private static function bcp47_locale(): string {
		$locale = function_exists( 'get_locale' ) ? (string) get_locale() : '';

		return '' === $locale ? 'es-CL' : str_replace( '_', '-', $locale );
	}

	/** «6 tomos en catálogo» / «1 tomo en catálogo». */
	private function tomos_label( int $tomos ): string {
		return sprintf(
			'%s %s en catálogo',
			number_format_i18n( $tomos ),
			1 === $tomos ? 'tomo' : 'tomos'
		);
	}
}
