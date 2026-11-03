<?php
/**
 * PdpSeriesEnhancer — navegación de serie bajo el H1 de la ficha de producto.
 *
 * ── Por qué el nav lleva un tercer segmento ─────────────────────────────────
 * Desde la ficha, el ÚNICO enlace a la landing de la serie era una fila de la
 * tabla de especificaciones, abajo del fold y con la misma jerarquía visual que
 * «Formato» o «Géneros». El nav prev/siguiente ya vive justo debajo del título
 * —el sitio de máxima intención de la página— y su celda central sólo contenía
 * un punto decorativo. Ese hueco pasa a ser el enlace a la serie.
 *
 * La fila «Serie» de la tabla de specs NO se toca: sigue siendo el dato de la
 * ficha técnica. Esto es un enlace de intención, no su reemplazo.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\PdpEnhancer;

use Akibara\Business\Modules\CopyManager\CopyManager;
use Akibara\Business\Services\VolumeTitle;
use Akibara\Business\Services\VolumeVariant;
use Akibara\Business\Utilities\PluginAssetVersion;
use Akibara\Business\Utilities\RobotsMeta;
use Akibara\Business\Utilities\Text;
use WC_Product;

defined( 'ABSPATH' ) || exit;
use Akibara\Business\Modules\SeriesFacts\PublicationStatus;

final class PdpSeriesEnhancer {

	private const CACHE_GROUP = 'akb_pdp';
	private const CACHE_TTL   = 12 * HOUR_IN_SECONDS;

	/** Texto del enlace a la landing de la serie (Akibara → Textos). */
	public const OPTION_SERIES_LINK = 'akb_copy_pdp_series_link';

	/**
	 * Incluye el nombre de la serie en el texto visible del enlace a sus tomos.
	 *
	 * La navegación y el ancla indexable comunican la misma serie. El copy usa el
	 * mismo contrato de marcadores que CopyManager::OPTION_GUIDE_CTA_SERIES.
	 */
	public const DEFAULT_SERIES_LINK = 'Ver los tomos de ' . CopyManager::SERIES_MARKER;

	/**
	 * Copys de una línea de este módulo, con su literal de fábrica: los que
	 * `CopyManager` no deja guardar vacíos.
	 *
	 * @return array<string, string>
	 */
	public static function copy_defaults(): array {
		return array( self::OPTION_SERIES_LINK => self::DEFAULT_SERIES_LINK );
	}

	/**
	 * Construye el texto escapado del enlace a la serie.
	 *
	 * El nombre se sustituye con str_replace sobre el copy ya escapado: los signos
	 * % del texto del dueño no se interpretan como formato. Sin marcador se conserva
	 * el texto y el panel informa la omisión. El nombre va en un span inline-block
	 * para pasar completo de línea en lugar de dividirse por un guion.
	 *
	 * @param string $series_name Nombre de la serie, sin escapar.
	 * @param bool   $is_one_shot Si la edición está marcada como completa en 1 tomo (Tomo único).
	 */
	public static function series_link_label( string $series_name, bool $is_one_shot = false ): string {
		$template = Text::one_line_option( self::OPTION_SERIES_LINK, self::DEFAULT_SERIES_LINK );
		if ( $is_one_shot && self::DEFAULT_SERIES_LINK === $template ) {
			$template = 'Ver la edición de ' . CopyManager::SERIES_MARKER;
		}

		return str_replace(
			CopyManager::SERIES_MARKER,
			'<span class="akb-series-nav__series-name">' . esc_html( $series_name ) . '</span>',
			esc_html( $template )
		);
	}

	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// Entre título (5) y rating (7): mantiene el nombre del producto como
		// punto de entrada antes de ofrecer navegación entre tomos.
		add_action( 'woocommerce_single_product_summary', array( $this, 'render_series_nav' ), 6 );

		// La miga de la ficha sube a su serie: Inicio / Manga / Seinen /
		// The Climber / The Climber 03. Prioridad 30, después del filtro del
		// tema (20, sólo actúa en `is_tax`). El mismo filtro alimenta la miga
		// visible, el BreadcrumbList de WooCommerce y la alineación del de
		// Rank Math (`SeoEnhancements::align_product_breadcrumb_schema`).
		add_filter( 'woocommerce_get_breadcrumb', array( $this, 'add_series_crumb' ), 30 );

		// Invalidar la lista de navegación cuando cambia el catálogo de la serie,
		// también con caché de objetos persistente, para incluir los tomos publicados.

		add_action( 'save_post_product', array( $this, 'flush_series_cache_for_product' ) );
		add_action( 'before_delete_post', array( $this, 'flush_series_cache_for_product' ) );
		add_action( 'set_object_terms', array( $this, 'flush_series_cache_on_terms_change' ), 10, 6 );

		// La marca de variante viaja DENTRO del valor cacheado, así que marcarla
		// o quitarla tiene que borrar la lista igual que publicar un tomo. Los
		// tres hooks de arriba NO la cubren: `wp post meta update <id>
		// _akb_variante_tomo …` —la vía del runbook y la única forma de probar
		// el interruptor encendido y apagado sobre un producto que ya está en su
		// serie— no dispara ninguno, y purgar LiteSpeed o Cloudflare no toca la
		// object cache. Sin esto, la ficha del tomo gemelo sigue ofreciendo
		// «Tomo siguiente: … (Edición Especial)» hasta 12 horas después.
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $meta_hook ) {
			add_action( $meta_hook, array( $this, 'flush_series_cache_on_variant_meta' ), 10, 3 );
		}
	}

	/**
	 * Invalida cuando cambia la marca de variante de un producto.
	 *
	 * Sólo esa clave: cualquier otro postmeta —y en un producto de WooCommerce
	 * hay decenas que se reescriben en cada guardado de stock o de precio— haría
	 * de este hook un purgador de la lista de la serie entera varias veces por
	 * request.
	 *
	 * @internal Callback de `added_post_meta`, `updated_post_meta` y `deleted_post_meta`.
	 *
	 * @param mixed      $meta_id   Meta ID (o lista, en el hook de borrado). No se usa.
	 * @param int|string $object_id Producto afectado.
	 * @param mixed      $meta_key  Clave modificada.
	 */
	public function flush_series_cache_on_variant_meta( $meta_id, $object_id, $meta_key ): void {
		unset( $meta_id );

		if ( VolumeVariant::META_KEY !== $meta_key ) {
			return;
		}

		$this->flush_series_cache_for_product( $object_id );
	}

	/**
	 * Borra la lista cacheada de cada serie a la que pertenece el producto.
	 * Cubre creación, edición de título, cambios de estado y trash (todas
	 * pasan por save_post_product) y el hard-delete (before_delete_post, con
	 * los términos aún presentes).
	 *
	 * @since 1.99.8
	 *
	 * @param int|string $post_id ID del post tocado.
	 */
	public function flush_series_cache_for_product( $post_id ): void {
		$post_id = (int) $post_id;
		if ( 'product' !== get_post_type( $post_id ) ) {
			return;
		}

		$terms = get_the_terms( $post_id, 'pa_serie' );
		if ( ! is_array( $terms ) ) {
			return;
		}

		foreach ( $terms as $term ) {
			wp_cache_delete( $this->series_cache_key( (int) $term->term_id ), self::CACHE_GROUP );
		}
	}

	/**
	 * Invalida al cambiar la membresía `pa_serie` de un objeto: borra las keys
	 * de los términos nuevos Y de los anteriores (mudanzas de serie). Los
	 * términos llegan como ids o slugs según el caller de wp_set_object_terms;
	 * los anteriores llegan como term_taxonomy_ids.
	 *
	 * @since 1.99.8
	 *
	 * @param int|string        $object_id  Objeto tocado.
	 * @param array<int, mixed> $terms      Términos solicitados (ids o slugs).
	 * @param array<int, mixed> $tt_ids     term_taxonomy_ids resultantes.
	 * @param string            $taxonomy   Taxonomía del cambio.
	 * @param bool              $append     Si fue append.
	 * @param array<int, mixed> $old_tt_ids term_taxonomy_ids previos.
	 */
	public function flush_series_cache_on_terms_change(
		$object_id,
		$terms,
		$tt_ids,
		$taxonomy,
		$append,
		$old_tt_ids
	): void {
		if ( 'pa_serie' !== $taxonomy ) {
			return;
		}

		$term_ids = array();
		foreach ( (array) $terms as $term ) {
			if ( is_numeric( $term ) ) {
				$term_ids[] = (int) $term;
				continue;
			}
			if ( is_string( $term ) && '' !== $term ) {
				$resolved = get_term_by( 'slug', $term, 'pa_serie' );
				if ( $resolved ) {
					$term_ids[] = (int) $resolved->term_id;
				}
			}
		}

		foreach ( array_map( 'intval', (array) $old_tt_ids ) as $tt_id ) {
			$resolved = get_term_by( 'term_taxonomy_id', $tt_id, 'pa_serie' );
			if ( $resolved ) {
				$term_ids[] = (int) $resolved->term_id;
			}
		}

		foreach ( array_unique( $term_ids ) as $term_id ) {
			wp_cache_delete( $this->series_cache_key( $term_id ), self::CACHE_GROUP );
		}
	}

	/**
	 * Clave de la lista cacheada.
	 *
	 * `_v3` y no `_v2`: el valor pasó de `id => título` a
	 * `id => array{title, variant}`. Producción corre object cache PERSISTENTE
	 * con TTL de 12 h, así que sin subir la clave las fichas servirían medio día
	 * la forma vieja —cadenas donde el código espera arrays— y el nav prev/
	 * siguiente seguiría repitiendo el mismo número con el arreglo ya desplegado.
	 * Es la trampa que documenta el comentario de `register()`.
	 */
	private function series_cache_key( int $serie_term_id ): string {
		return 'akb_series_products_' . $serie_term_id . '_v3';
	}

	/**
	 * Carga el estilo estructural solo en fichas de producto.
	 */
	public function enqueue_assets(): void {
		if ( ! is_product() ) {
			return;
		}

		$relative = 'assets/css/pdp-series-nav.css';

		wp_enqueue_style(
			'akb-pdp-series-nav',
			AKIBARA_BUSINESS_URL . $relative,
			array(),
			PluginAssetVersion::for_path( $relative )
		);
	}

	/**
	 * Inserta la serie inmediatamente antes del peldaño de la ficha.
	 *
	 * La miga y BreadcrumbList declaran /serie/<slug>/ como nivel superior del tomo.
	 * La posición se localiza por la URL de la propia ficha, no por el último índice:
	 * paged_trail() puede añadir paginación y otras migas pueden omitir Inicio.
	 * Si no aparece la URL de la ficha no se adivina dónde insertar.
	 *
	 * El noindex explícito de Rank Math en el término impide insertar ese peldaño.
	 * No se consulta el noindex dinámico por falta de catálogo público; el enlace de
	 * navegación para el cliente conserva su función aunque la serie tenga noindex.
	 * El nombre se limpia con wp_strip_all_tags() y la plantilla escapa al imprimir.
	 *
	 * @internal Callback de `woocommerce_get_breadcrumb`.
	 *
	 * @param mixed $crumbs Peldaños `[ [nombre, url], … ]`.
	 * @return mixed
	 */
	public function add_series_crumb( $crumbs ) {
		if ( ! is_array( $crumbs ) || ! is_singular( 'product' ) ) {
			return $crumbs;
		}

		$product_id = (int) get_queried_object_id();
		$term       = $product_id > 0 ? $this->get_series_term( $product_id ) : null;
		if ( null === $term || ! isset( $term->name ) ) {
			return $crumbs;
		}
		if ( ! RobotsMeta::is_indexable( get_term_meta( (int) $term->term_id, 'rank_math_robots', true ) ) ) {
			return $crumbs;
		}

		$series_url = get_term_link( $term );
		if ( is_wp_error( $series_url ) || ! is_string( $series_url ) || '' === $series_url ) {
			return $crumbs;
		}

		$crumbs     = array_values( $crumbs );
		$own_url    = untrailingslashit( (string) get_permalink( $product_id ) );
		$series_key = untrailingslashit( $series_url );
		$offset     = null;
		foreach ( $crumbs as $index => $crumb ) {
			$href = is_array( $crumb ) && is_string( $crumb[1] ?? null ) ? untrailingslashit( $crumb[1] ) : '';
			if ( $series_key === $href ) {
				return $crumbs;
			}
			if ( null === $offset && '' !== $own_url && $own_url === $href ) {
				$offset = $index;
			}
		}
		if ( null === $offset ) {
			return $crumbs;
		}

		array_splice( $crumbs, $offset, 0, array( array( wp_strip_all_tags( (string) $term->name ), $series_url ) ) );

		return $crumbs;
	}

	/**
	 * Primer término `pa_serie` del producto, o null si no tiene ninguno.
	 *
	 * Un único punto de lectura para las dos cosas que el nav necesita del
	 * término —la lista de tomos y el enlace a la landing—: si cada una lo
	 * resolviera por su cuenta, un producto con más de una serie podría acabar
	 * paginando por una y enlazando a la otra.
	 *
	 * @return object|null Término de `pa_serie`.
	 */
	private function get_series_term( int $product_id ): ?object {
		$terms = get_the_terms( $product_id, 'pa_serie' );
		if ( ! is_array( $terms ) || array() === $terms || is_wp_error( $terms ) ) {
			return null;
		}

		$term = reset( $terms );

		return is_object( $term ) && isset( $term->term_id ) ? $term : null;
	}

	/**
	 * Productos de la misma serie, en orden natural por título.
	 *
	 * La marca de variante viaja EN LA MISMA CONSULTA. Leerla después con
	 * `get_post_meta()` costaría una consulta por tomo en cada ficha (hasta 500
	 * en la serie más larga) para un dato que el `LEFT JOIN` trae gratis, y el
	 * valor cacheado la conserva para que el nav no vuelva a preguntar.
	 *
	 * @param object|null $serie_term Término ya resuelto por get_series_term().
	 * @return array<int, array{title:string, variant:bool}> Mapa por product_id.
	 */
	private function get_series_products( ?object $serie_term ): array {
		if ( null === $serie_term ) {
			return array();
		}

		$serie_term_id = $serie_term->term_id;
		$cache_key     = $this->series_cache_key( (int) $serie_term_id );
		$cached        = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return $cached;
		}

		$args = array(
			'post_type'              => 'product',
			'post_status'            => 'publish',
			// Tope holgado: ninguna serie del catálogo se acerca a esta cifra.
			'posts_per_page'         => 500,
			'tax_query'              => array(
				array(
					'taxonomy' => 'pa_serie',
					'field'    => 'term_id',
					'terms'    => $serie_term_id,
				),
			),
			// Sólo los IDs: los títulos se leen abajo en UNA consulta.
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		);

		$query    = new \WP_Query( $args );
		$products = array();

		if ( ! empty( $query->posts ) ) {
			global $wpdb;
			$post_ids     = array_map( 'intval', $query->posts );
			$placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );

			// IDs ya son intval (no inyectable), pero usamos prepare() con
			// placeholders por consistencia con el resto del plugin. El bloque
			// está dentro de `! empty( $query->posts)`, así que $post_ids nunca
			// es vacío (no se genera un `IN ()` inválido).
			$results = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT p.ID, p.post_title, vm.meta_value AS akb_variante
					 FROM {$wpdb->posts} p
					 LEFT JOIN {$wpdb->postmeta} vm ON vm.post_id = p.ID AND vm.meta_key = %s
					 WHERE p.ID IN ($placeholders)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $placeholders son %d generados, no input; el sniff no los ve dentro de la interpolación.
					array_merge( array( VolumeVariant::META_KEY ), $post_ids )
				)
			);

			foreach ( $results as $row ) {
				$products[ (int) $row->ID ] = array(
					'title'   => (string) $row->post_title,
					'variant' => VolumeVariant::is_variant_kind( $row->akb_variante ),
				);
			}

			// Orden natural por título, para que «Tomo 2» venga antes que «Tomo 10».
			uasort(
				$products,
				static fn( array $left, array $right ): int => strnatcasecmp( $left['title'], $right['title'] )
			);
		}

		wp_cache_set( $cache_key, $products, self::CACHE_GROUP, self::CACHE_TTL );
		return $products;
	}

	/**
	 * Enlace a la landing de la serie, o null si no se puede construir.
	 *
	 * `get_term_link()` devuelve `WP_Error` cuando el término no existe o la
	 * taxonomía no está registrada todavía; en ese caso el segmento central
	 * simplemente no sale y el nav vuelve a su forma anterior. Mismo patrón que
	 * la grilla de specs de `PdpEnhancer::render_specs_grid()`.
	 *
	 * @return array{url:string,name:string}|null
	 */
	private function series_link( ?object $serie_term ): ?array {
		if ( null === $serie_term || ! isset( $serie_term->name ) ) {
			return null;
		}

		$link = get_term_link( $serie_term );
		if ( is_wp_error( $link ) || ! is_string( $link ) || '' === $link ) {
			return null;
		}

		return array(
			'url'    => $link,
			'name'   => (string) $serie_term->name,
			'estado' => $this->estado_de_edicion( (int) $serie_term->term_id ),
		);
	}

	/**
	 * Frase de estado de la EDICIÓN, compuesta por la MISMA clase que usa la
	 * landing de serie.
	 *
	 * Por qué no un conteo en vivo aquí: el comentario de `render_series_nav()`
	 * lleva razón. Un `count()` sobre la lista de esta ficha NO excluye los
	 * productos `exclude-from-catalog` y puede contradecir a la landing a un
	 * click de distancia. `PublicationStatus` lee termmeta —verdad editorial,
	 * estática— y compone la frase con las mismas palabras en las dos
	 * superficies: «Completa en 30 tomos», «En publicación · 8 tomos previstos».
	 *
	 * Se usa la clase y no un switch propio a propósito: duplicar el mapeo de
	 * estados aquí garantizaba que las dos redacciones divergieran en el primer
	 * estado nuevo. De hecho, la primera versión de este método inventó
	 * 'en_curso' y 'pausada', que NO existen: los valores reales son
	 * 'completa' (55 series), 'en_publicacion' (46) y 'al_dia' (3).
	 *
	 * Devuelve '' cuando falta el dato. Sólo 67 de las series lo tienen relleno,
	 * así que el vacío es el caso normal, no un error.
	 *
	 * @param int $term_id Término de `pa_serie`.
	 * @return string
	 */
	private function estado_de_edicion( int $term_id ): string {
		if ( $term_id < 1 || ! class_exists( PublicationStatus::class ) ) {
			return '';
		}

		$scoped  = PublicationStatus::scoped_for_term( $term_id );
		$edicion = $scoped['edicion'] ?? array();

		$phrase = PublicationStatus::edition_phrase(
			(string) ( $edicion['estado'] ?? '' ),
			(int) ( $edicion['tomos'] ?? 0 )
		);

		if ( array() === $phrase ) {
			return '';
		}

		$titular = trim( (string) ( $phrase['titular'] ?? '' ) );
		$detalle = trim( (string) ( $phrase['detalle'] ?? '' ) );

		if ( '' === $titular ) {
			return '';
		}

		if ( '' === $detalle ) {
			return $titular; // p. ej. «Al día», que no lleva total.
		}

		if ( PublicationStatus::COMPLETE === ( $edicion['estado'] ?? '' ) && 1 === (int) ( $edicion['tomos'] ?? 0 ) ) {
			return 'Tomo único · Obra autoconclusiva';
		}

		// El detalle de «completa» YA trae la preposición («en 30 tomos»), así
		// que se une con espacio y forma una sola frase: «Completa en 30 tomos».
		// Los demás estados traen el detalle como dato aparte («15 tomos
		// previstos») y se separan con punto medio. Comprobado contra la clase:
		// devolver sólo el detalle dejaba la ficha diciendo «en 30 tomos».
		return PublicationStatus::COMPLETE === ( $edicion['estado'] ?? '' )
			? $titular . ' ' . $detalle
			: $titular . ' · ' . $detalle;
	}

	/**
	 * Tomo anterior y siguiente, saltándose las variantes del mismo número.
	 *
	 * La cadena de lectura la forman los tomos normales. Una edición especial
	 * del 23 no es «el siguiente» del 23: sin este corte, desde el tomo 23 el
	 * botón «Siguiente» llevaba a otro producto del MISMO número y el
	 * `aria-label` lo anunciaba en voz alta como «Tomo siguiente».
	 *
	 * Desde la ficha de la variante, la navegación se resuelve como si fuera su
	 * tomo: el anterior es el 22 y el siguiente el 24, nunca el gemelo. Una
	 * variante marcada que NO tiene gemelo en la serie (un producto mal marcado)
	 * no se queda sin salida: cae a su posición por orden natural, que es
	 * exactamente el comportamiento de antes de esta marca.
	 *
	 * Pura y estática: decide dos enlaces y se prueba sin base de datos.
	 *
	 * @param array<int, array{title:string, variant:bool}> $products Serie ordenada.
	 * @param int                                           $current_id Ficha actual.
	 * @return array{prev:int|null, next:int|null}
	 */
	public static function neighbors( array $products, int $current_id ): array {
		$none  = array( 'prev' => null, 'next' => null );
		$chain = array();
		foreach ( $products as $id => $row ) {
			if ( empty( $row['variant'] ) ) {
				$chain[] = (int) $id;
			}
		}

		if ( array() === $chain ) {
			return $none;
		}

		$position = array_search( $current_id, $chain, true );
		if ( false !== $position ) {
			return self::around( $chain, (int) $position );
		}

		if ( ! isset( $products[ $current_id ] ) ) {
			return $none;
		}

		// Es una variante: se ubica en el hueco de su gemelo.
		$number = VolumeTitle::extract_number( (string) $products[ $current_id ]['title'] );
		if ( null !== $number ) {
			foreach ( $chain as $index => $id ) {
				if ( $number === VolumeTitle::extract_number( (string) $products[ $id ]['title'] ) ) {
					return self::around( $chain, (int) $index );
				}
			}
		}

		// Sin gemelo: por orden natural, como cualquier otro tomo.
		$order         = array_keys( $products );
		$current_order = (int) array_search( $current_id, $order, true );
		$previous      = null;
		foreach ( $chain as $id ) {
			if ( (int) array_search( $id, $order, true ) > $current_order ) {
				break;
			}
			$previous = $id;
		}

		$next_index = null === $previous ? 0 : (int) array_search( $previous, $chain, true ) + 1;

		return array(
			'prev' => $previous,
			'next' => $chain[ $next_index ] ?? null,
		);
	}

	/**
	 * Vecinos de una posición de la cadena.
	 *
	 * @param list<int> $chain    Tomos normales en orden.
	 * @param int       $position Posición del tomo actual (o de su gemelo).
	 * @return array{prev:int|null, next:int|null}
	 */
	private static function around( array $chain, int $position ): array {
		return array(
			'prev' => $chain[ $position - 1 ] ?? null,
			'next' => $chain[ $position + 1 ] ?? null,
		);
	}

	public function render_series_nav(): void {
		global $product;
		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$product_id      = $product->get_id();
		$serie_term      = $this->get_series_term( $product_id );
		$series_products = $this->get_series_products( $serie_term );
		$series_link     = $this->series_link( $serie_term );

		// El guard `count() <= 1` ya NO cierra el nav completo: sólo apaga
		// prev/siguiente. Las 10 series de un solo tomo no tienen tomo anterior
		// ni siguiente, pero SÍ tienen landing, y esa landing es justamente
		// donde el visitante ve el resto del catálogo de la editorial y las
		// series relacionadas. Cerrar la ficha entera por no tener vecinos era
		// negarle la salida a los productos que menos contexto ofrecen.
		$neighbors = self::neighbors( $series_products, $product_id );
		$prev_id   = $neighbors['prev'];
		$next_id   = $neighbors['next'];

		if ( ! $prev_id && ! $next_id && null === $series_link ) {
			return;
		}

		?>
		<nav class="akb-series-nav" aria-label="Navegación de la serie">
			<div class="akb-series-nav__inner">
				<?php if ( $prev_id ) : ?>
					<a
						href="<?php echo esc_url( get_permalink( $prev_id ) ); ?>"
						class="akb-series-nav__link akb-series-nav__link--prev"
						aria-label="<?php echo esc_attr( sprintf( 'Tomo anterior: %s', $series_products[ $prev_id ]['title'] ) ); ?>"
					>
						<span class="akb-series-nav__arrow" aria-hidden="true">←</span>
						<span class="akb-series-nav__title">
							<span class="akb-series-nav__title-desktop"><?php echo esc_html( $series_products[ $prev_id ]['title'] ); ?></span>
							<span class="akb-series-nav__title-mobile">Anterior</span>
						</span>
					</a>
				<?php else: ?>
					<span class="akb-series-nav__empty" aria-hidden="true"></span>
				<?php endif; ?>

				<?php if ( null !== $series_link ) : ?>
					<?php
					/*
					 * ── El texto NO cambia entre breakpoints, y es deliberado ──
					 * Prev/siguiente sí cambian: en desktop muestran el título del
					 * tomo y en móvil lo cambian por «Anterior»/«Siguiente», o sea
					 * cambian una palabra específica por una genérica porque no
					 * entra. Heredar ese patrón acá daría un enlace que en móvil
					 * diría «La serie», que no le dice nada a nadie.
					 *
					 * Lo que cambia es el LAYOUT (ver pdp-series-nav.css): en
					 * desktop el enlace es la celda central de una fila de tres; en
					 * ≤1024px baja a una segunda fila propia a todo el ancho, porque
					 * ahí prev y siguiente ya ocupan el 48% cada uno y el centro se
					 * quedaría con cuatro puntos porcentuales. Con su propia fila
					 * el texto completo cabe en las dos anchuras; un nombre de
					 * serie largo pasa a una segunda línea en vez de recortarse.
					 *
					 * Sin `aria-label`: el texto visible ya nombra la serie, y un
					 * nombre accesible distinto del visible incumple «label in
					 * name» (WCAG 2.5.3).
					 *
					 * SIN número de tomos, y no por pereza: el conteo honesto de la
					 * serie lo produce la agregación de `SeriesFacts`, que excluye
					 * los productos `exclude-from-catalog` y se cachea por término.
					 * Esta lista es un `WP_Query` que NO los excluye, así que un
					 * `count()` acá puede diferir del de la landing a la que el
					 * enlace lleva. Un número que se contradice a un click de
					 * distancia es peor que ningún número, y la landing publica el
					 * total en su propio contador de resultados.
					 */
					?>
					<a
						href="<?php echo esc_url( $series_link['url'] ); ?>"
						class="akb-series-nav__link akb-series-nav__link--series<?php echo ( ! $prev_id && ! $next_id ) ? ' akb-series-nav__link--series-alone' : ''; ?>"
					>
						<span class="akb-series-nav__series-label"><?php echo self::series_link_label( $series_link['name'], 'Tomo único · Obra autoconclusiva' === ( $series_link['estado'] ?? '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapado en series_link_label(). ?></span>
						<?php if ( '' !== ( $series_link['estado'] ?? '' ) ) : ?>
							<span class="akb-series-nav__series-estado"><?php echo esc_html( $series_link['estado'] ); ?></span>
						<?php endif; ?>
					</a>
				<?php elseif ( $prev_id && $next_id ) : ?>
					<span class="akb-series-nav__separator" aria-hidden="true">·</span>
				<?php else : ?>
					<?php
					// La celda central se emite SIEMPRE, aunque quede vacía: el
					// layout es un grid de tres columnas y un hueco omitido
					// correría «siguiente» a la celda del centro, dejando la
					// flecha derecha en mitad de la barra.
					?>
					<span class="akb-series-nav__empty akb-series-nav__empty--center" aria-hidden="true"></span>
				<?php endif; ?>

				<?php if ( $next_id ) : ?>
					<a
						href="<?php echo esc_url( get_permalink( $next_id ) ); ?>"
						class="akb-series-nav__link akb-series-nav__link--next"
						aria-label="<?php echo esc_attr( sprintf( 'Tomo siguiente: %s', $series_products[ $next_id ]['title'] ) ); ?>"
					>
						<span class="akb-series-nav__title">
							<span class="akb-series-nav__title-desktop"><?php echo esc_html( $series_products[ $next_id ]['title'] ); ?></span>
							<span class="akb-series-nav__title-mobile">Siguiente</span>
						</span>
						<span class="akb-series-nav__arrow" aria-hidden="true">→</span>
					</a>
				<?php else: ?>
					<span class="akb-series-nav__empty" aria-hidden="true"></span>
				<?php endif; ?>
			</div>
		</nav>
		<?php
	}
}
