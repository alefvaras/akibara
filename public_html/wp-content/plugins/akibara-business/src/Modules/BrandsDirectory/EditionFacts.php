<?php
/**
 * EditionFacts — lo que la tienda sabe de cada EDICIÓN de una editorial.
 *
 * Una editorial con dos ediciones (Panini Argentina y Panini España) tiene
 * dos términos de `product_brand` y, hasta 1.114.17, ningún dato que dijera
 * en qué se diferencian: la landing de la familia era prosa escrita a mano y
 * las series «destacadas» eran enlaces sueltos en el editor.
 *
 * Aquí viven los tres hechos que el dueño escribe (idioma, formato,
 * numeración) como term-meta —editables desde el panel Akibara, sin pasar por
 * `wp_update_term` para no disparar el reindexado del buscador— y los dos que
 * se calculan solos: las series con más tomos dentro de la edición y el precio
 * más bajo con stock. Lo manual gana a lo automático; lo automático evita que
 * una edición recién creada salga vacía.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\BrandsDirectory;

use Akibara\Business\Modules\Stock\StockStatus;
use Akibara\Business\Services\SeriesCover;
use Akibara\Business\Services\VolumeVariant;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.114.18
 */
final class EditionFacts {

	public const META_IDIOMA     = 'akibara_edition_idioma';
	public const META_FORMATO    = 'akibara_edition_formato';
	public const META_NUMERACION = 'akibara_edition_numeracion';
	/** Lista de term_ids de `pa_serie` elegidos a mano (CSV). */
	public const META_SERIES = 'akibara_edition_series';

	public const MAX_SERIES = 6;

	/** Series y precio se recalculan como mucho cada 12 h; guardar hechos los borra. */
	private const TRANSIENT_TTL = 12 * HOUR_IN_SECONDS;

	/** Largo máximo de un hecho: una línea que cabe en la tarjeta. */
	public const MAX_LEN = 140;

	/** @var array<string, true> URLs pendientes de purgar en este request. */
	private array $urls_to_purge = array();

	/** @var bool Ya hay un `shutdown` agendado para vaciar la cola. */
	private bool $purge_scheduled = false;

	/**
	 * Cuelga la invalidación: al cambiar el stock, guardar, mandar a la papelera,
	 * borrar o restaurar un producto, el precio «desde», el conteo y las portadas
	 * de sus ediciones se recalculan en la siguiente visita (si no, 12 h con un
	 * precio que ya no existe).
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'woocommerce_product_set_stock_status', array( $this, 'invalidate_product' ), 10, 1 );
		add_action( 'woocommerce_update_product', array( $this, 'invalidate_product' ), 10, 1 );

		// La marca de variante cambia el orden de «series con más tomos» que
		// rellena las tarjetas, y se pone por WP-CLI o por importación: ninguno
		// de los dos hooks de arriba se entera.
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $meta_hook ) {
			add_action( $meta_hook, array( $this, 'invalidate_on_variant_meta' ), 10, 3 );
		}

		// Papelera y borrado se observan antes de soltar relaciones de términos,
		// para identificar la edición cuya caché se invalida. Restaurar también
		// invalida; estos caminos no dependen de woocommerce_update_product.
		add_action( 'wp_trash_post', array( $this, 'invalidate_on_post_change' ), 10, 1 );
		add_action( 'before_delete_post', array( $this, 'invalidate_on_post_change' ), 10, 1 );
		add_action( 'untrashed_post', array( $this, 'invalidate_on_post_change' ), 10, 1 );
	}

	/**
	 * Invalida cuando un tomo entra o sale del catálogo por la vía de WordPress.
	 *
	 * Los tres hooks son genéricos —los dispara cualquier entrada, no sólo un
	 * producto—, así que el guard de tipo es lo que evita una consulta de
	 * términos por cada borrado de cualquier cosa.
	 *
	 * @internal Callback de `wp_trash_post`, `before_delete_post` y `untrashed_post`.
	 *
	 * @param int|string $post_id Entrada afectada.
	 * @return void
	 */
	public function invalidate_on_post_change( $post_id ): void {
		$id = (int) $post_id;
		if ( $id <= 0 || 'product' !== get_post_type( $id ) ) {
			return;
		}

		$this->invalidate_product( $id );
	}

	/**
	 * Invalida cuando cambia la marca de variante de un producto.
	 *
	 * @internal Callback de `added_post_meta`, `updated_post_meta` y `deleted_post_meta`.
	 *
	 * @param mixed      $meta_id   Meta ID (o lista, en el borrado). No se usa.
	 * @param int|string $object_id Producto afectado.
	 * @param mixed      $meta_key  Clave modificada.
	 * @return void
	 */
	public function invalidate_on_variant_meta( $meta_id, $object_id, $meta_key ): void {
		unset( $meta_id );

		if ( VolumeVariant::META_KEY !== $meta_key ) {
			return;
		}

		$this->invalidate_product( (int) $object_id );
	}

	/**
	 * Olvida lo calculado de las ediciones a las que pertenece un producto.
	 *
	 * Los dos hooks entregan el ID como primer argumento
	 * (`do_action( 'woocommerce_update_product', $product->get_id(), $product )`),
	 * así que aquí nunca llega un objeto: nada de ramas que no se ejecutan.
	 *
	 * @param int $product_id Producto.
	 * @return void
	 */
	public function invalidate_product( int $product_id ): void {
		$id = $product_id;
		if ( $id <= 0 ) {
			return;
		}
		$terms = get_the_terms( $id, Shortcode::TAXONOMY );
		if ( ! is_array( $terms ) ) {
			return;
		}
		foreach ( $terms as $term ) {
			if ( $term instanceof WP_Term ) {
				delete_transient( self::transient_key( (int) $term->term_id ) );
				$this->queue_purge( $term );
			}
		}
	}

	/**
	 * Borrar el transient no basta: LiteSpeed sirve /panini/ y el archivo de la
	 * edición desde su caché de página. Se purgan por URL una vez por request,
	 * al final (una importación de 400 tomos son 400 hooks y dos URLs).
	 *
	 * @param WP_Term $term Edición.
	 * @return void
	 */
	private function queue_purge( WP_Term $term ): void {
		$link = get_term_link( $term );
		if ( is_string( $link ) && '' !== $link ) {
			$this->urls_to_purge[ $link ] = true;
		}
		$landing = BrandLandings::for_term( $term );
		$url     = null !== $landing ? BrandLandings::url( (string) $landing['key'] ) : '';
		if ( '' !== $url ) {
			$this->urls_to_purge[ $url ] = true;
		}
		if ( ! $this->purge_scheduled && array() !== $this->urls_to_purge ) {
			add_action( 'shutdown', array( $this, 'purge_queued_urls' ), PHP_INT_MAX );
			$this->purge_scheduled = true;
		}
	}

	/**
	 * @return void
	 */
	public function purge_queued_urls(): void {
		foreach ( array_keys( $this->urls_to_purge ) as $url ) {
			do_action( 'litespeed_purge_url', $url ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook público oficial de LiteSpeed Cache.
		}
		$this->urls_to_purge   = array();
		$this->purge_scheduled = false;
	}

	/**
	 * Los tres hechos escritos por el dueño, con su etiqueta visible.
	 *
	 * @return array<string, string> meta_key → etiqueta.
	 */
	public static function fields(): array {
		return array(
			self::META_IDIOMA     => 'Idioma',
			self::META_FORMATO    => 'Formato',
			self::META_NUMERACION => 'Numeración y precio',
		);
	}

	/**
	 * Hechos escritos de una edición (sólo los que tienen texto).
	 *
	 * @param int $term_id Término de `product_brand`.
	 * @return array<string, string> etiqueta → texto.
	 */
	public static function facts( int $term_id ): array {
		$out = array();
		foreach ( self::fields() as $key => $label ) {
			$value = self::sanitize_fact( get_term_meta( $term_id, $key, true ) );
			if ( '' !== $value ) {
				$out[ $label ] = $value;
			}
		}
		return $out;
	}

	/**
	 * Un hecho tal como se guarda: una línea, sin etiquetas, acotada.
	 *
	 * @param mixed $raw Lo que llegó.
	 * @return string
	 */
	public static function sanitize_fact( $raw ): string {
		$value = is_scalar( $raw ) ? trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $raw ) ) ) : '';
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, self::MAX_LEN );
		}
		return substr( $value, 0, self::MAX_LEN );
	}

	/**
	 * Series elegidas a mano para una edición (ids de `pa_serie`, hasta MAX_SERIES).
	 *
	 * @param int $term_id Término de `product_brand`.
	 * @return array<int, int>
	 */
	public static function chosen_series( int $term_id ): array {
		return self::sanitize_series( get_term_meta( $term_id, self::META_SERIES, true ) );
	}

	/**
	 * Normaliza una lista de ids de serie: enteros positivos, únicos, acotada.
	 *
	 * @param mixed $raw CSV o array.
	 * @return array<int, int>
	 */
	public static function sanitize_series( $raw ): array {
		$list = is_array( $raw ) ? $raw : explode( ',', (string) $raw );
		$ids  = array();
		foreach ( $list as $id ) {
			$id = (int) $id;
			if ( $id > 0 && ! in_array( $id, $ids, true ) ) {
				$ids[] = $id;
			}
			if ( count( $ids ) >= self::MAX_SERIES ) {
				break;
			}
		}
		return $ids;
	}

	/**
	 * Escribe los hechos de una edición. Term-meta DIRECTO a propósito: nunca
	 * `wp_update_term`, que dispararía la reindexación de todos los productos
	 * de la editorial (856 en Ivrea Argentina).
	 *
	 * @param int                   $term_id Término.
	 * @param array<string, string> $facts   meta_key → texto (ya saneado por el llamador o no: se sanea igual).
	 * @param array<int, int>       $series  Ids de serie elegidos.
	 * @return void
	 */
	public static function save( int $term_id, array $facts, array $series ): void {
		foreach ( array_keys( self::fields() ) as $key ) {
			$value = self::sanitize_fact( $facts[ $key ] ?? '' );
			if ( '' === $value ) {
				delete_term_meta( $term_id, $key );
			} else {
				update_term_meta( $term_id, $key, $value );
			}
		}
		$series = self::sanitize_series( $series );
		if ( array() === $series ) {
			delete_term_meta( $term_id, self::META_SERIES );
		} else {
			update_term_meta( $term_id, self::META_SERIES, implode( ',', $series ) );
		}
		delete_transient( self::transient_key( $term_id ) );
	}

	/**
	 * Lo que hoy tiene una edición, tal como se guarda (para diff y respaldo).
	 *
	 * @param int $term_id Término.
	 * @return array<string, string>
	 */
	public static function snapshot( int $term_id ): array {
		$row = array();
		foreach ( array_keys( self::fields() ) as $key ) {
			$row[ $key ] = self::sanitize_fact( get_term_meta( $term_id, $key, true ) );
		}
		$row[ self::META_SERIES ] = implode( ',', self::chosen_series( $term_id ) );
		return $row;
	}

	/**
	 * Series destacadas de la edición con su portada: las elegidas a mano y,
	 * si faltan, las que más tomos tienen dentro de la edición.
	 *
	 * @param WP_Term $brand Término de `product_brand`.
	 * @return array<int, array{name:string,url:string,cover:string}>
	 */
	public static function featured_series( WP_Term $brand ): array {
		$computed = self::computed( $brand );
		return $computed['series'];
	}

	/**
	 * Devuelve el menor precio publicable de la edición en CLP, o cero.
	 * La tabla lookup contiene precios persistidos en contexto edit y no incluye
	 * los filtros de descuento editorial. Mientras exista un override vigente
	 * se omite la cifra, igual que en SeriesFacts, para no discrepar de las tarjetas.
	 *
	 * @param WP_Term $brand Término de `product_brand`.
	 * @return float
	 */
	public static function price_from( WP_Term $brand ): float {
		return self::price_is_overridden() ? 0.0 : self::price_from_lookup( $brand );
	}

	/**
	 * El precio más bajo TAL COMO lo guarda la tabla de WooCommerce, aunque haya
	 * una campaña encima. Es para el panel del dueño, que necesita ver el dato
	 * aunque hoy no se publique.
	 *
	 * @param WP_Term $brand Término de `product_brand`.
	 * @return float
	 */
	public static function price_from_lookup( WP_Term $brand ): float {
		return self::computed( $brand )['price_from'];
	}

	/**
	 * Predicado inyectado: ¿hay una campaña de descuento alterando el precio en
	 * tiempo de ejecución?
	 *
	 * Se inyecta —y no se consulta aquí— para no duplicar la semántica de
	 * vigencia (interruptor + ventana de fechas) que ya vive en
	 * `TaxonomyDiscount\Settings::is_enabled()`. `Plugin.php` cablea la MISMA
	 * instancia que usan los filtros de precio y que ya recibe `SeriesFacts`.
	 *
	 * @var callable|null
	 */
	private static $price_override_probe = null;

	/**
	 * Cablea el predicado de override. `null` lo retira (la tabla manda).
	 *
	 * @param callable|null $probe Devuelve true si hay campaña vigente.
	 * @return void
	 */
	public static function set_price_override_probe( ?callable $probe ): void {
		self::$price_override_probe = $probe;
	}

	/**
	 * ¿Hay una campaña alterando los precios ahora mismo?
	 *
	 * @return bool
	 */
	public static function price_is_overridden(): bool {
		return null !== self::$price_override_probe && true === ( self::$price_override_probe )();
	}

	/**
	 * Series con más tomos publicados dentro de la edición: id de `pa_serie` →
	 * nº de tomos, de mayor a menor. Es lo que se ofrece en el selector del
	 * panel y lo que rellena las tarjetas cuando el dueño no eligió nada.
	 *
	 * @param int $brand_tt_id term_taxonomy_id de la edición.
	 * @param int $limit       Cuántas.
	 * @return array<int, int>
	 */
	public static function series_by_volume( int $brand_tt_id, int $limit ): array {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_results' ) || $brand_tt_id <= 0 ) {
			return array();
		}
		// «Las series de más TOMOS»: una edición especial del mismo número no
		// suma una posición, y este orden es el que rellena las tarjetas de la
		// landing de editorial cuando el dueño no elige a mano.
		$tomo_normal = VolumeVariant::sql_normal_volume_condition( 'vm.meta_value' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- `$tomo_normal` lo compone `VolumeVariant` con `%s` puros (nunca entrada) y sus valores viajan como argumentos; el sniff tampoco sabe contar los que llegan con el operador de propagación.
		$sql = $wpdb->prepare(
			"SELECT tts.term_id AS term_id, COUNT(*) AS n
			   FROM {$wpdb->term_relationships} trb
			   JOIN {$wpdb->posts} p ON p.ID = trb.object_id AND p.post_type = 'product' AND p.post_status = 'publish'
			   JOIN {$wpdb->term_relationships} trs ON trs.object_id = p.ID
			   JOIN {$wpdb->term_taxonomy} tts ON tts.term_taxonomy_id = trs.term_taxonomy_id AND tts.taxonomy = 'pa_serie'
			   LEFT JOIN {$wpdb->postmeta} vm ON vm.post_id = p.ID AND vm.meta_key = %s
			  WHERE trb.term_taxonomy_id = %d
			    AND {$tomo_normal}
			    AND NOT EXISTS (
			        SELECT 1 FROM {$wpdb->term_relationships} trv
			          JOIN {$wpdb->term_taxonomy} ttv ON ttv.term_taxonomy_id = trv.term_taxonomy_id AND ttv.taxonomy = 'product_visibility'
			          JOIN {$wpdb->terms} tv ON tv.term_id = ttv.term_id AND tv.name = 'exclude-from-catalog'
			         WHERE trv.object_id = p.ID
			    )
			  GROUP BY tts.term_id
			  ORDER BY n DESC, tts.term_id ASC
			  LIMIT %d",
			...array_merge(
				array( VolumeVariant::META_KEY, $brand_tt_id ),
				VolumeVariant::variant_kinds(),
				array( max( 1, $limit ) )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$out = array();
		foreach ( (array) $wpdb->get_results( $sql, ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- preparada arriba.
			$out[ (int) ( $row['term_id'] ?? 0 ) ] = (int) ( $row['n'] ?? 0 );
		}
		unset( $out[0] );
		return $out;
	}

	/**
	 * Precio mínimo con stock o reservable entre los productos publicados de la edición.
	 *
	 * @param int $brand_tt_id term_taxonomy_id de la edición.
	 * @return float
	 */
	private static function lowest_price( int $brand_tt_id ): float {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return 0.0;
		}
		$sql = $wpdb->prepare(
			"SELECT MIN(l.min_price)
			   FROM {$wpdb->prefix}wc_product_meta_lookup l
			   JOIN {$wpdb->term_relationships} trb ON trb.object_id = l.product_id
			   JOIN {$wpdb->posts} p ON p.ID = l.product_id AND p.post_status = 'publish'
			  WHERE trb.term_taxonomy_id = %d
			    AND l.stock_status IN (%s, %s)
			    AND l.min_price > 0
			    AND NOT EXISTS (
			        SELECT 1 FROM {$wpdb->term_relationships} trv
			          JOIN {$wpdb->term_taxonomy} ttv ON ttv.term_taxonomy_id = trv.term_taxonomy_id AND ttv.taxonomy = 'product_visibility'
			          JOIN {$wpdb->terms} tv ON tv.term_id = ttv.term_id AND tv.name = 'exclude-from-catalog'
			         WHERE trv.object_id = l.product_id
			    )",
			$brand_tt_id,
			StockStatus::IN_STOCK,
			StockStatus::ON_BACKORDER
		);
		return (float) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- preparada arriba.
	}

	/**
	 * Series destacadas resueltas (con portada) y precio «desde», con caché.
	 *
	 * @param WP_Term $brand Edición.
	 * @return array{series: array<int, array{name:string,url:string,cover:string}>, price_from: float}
	 */
	private static function computed( WP_Term $brand ): array {
		$key    = self::transient_key( (int) $brand->term_id );
		$cached = get_transient( $key );
		if ( is_array( $cached ) && isset( $cached['series'], $cached['price_from'] ) ) {
			return $cached;
		}

		$ids = self::chosen_series( (int) $brand->term_id );
		if ( count( $ids ) < self::MAX_SERIES ) {
			foreach ( array_keys( self::series_by_volume( (int) $brand->term_taxonomy_id, self::MAX_SERIES ) ) as $id ) {
				if ( ! in_array( $id, $ids, true ) ) {
					$ids[] = $id;
				}
				if ( count( $ids ) >= self::MAX_SERIES ) {
					break;
				}
			}
		}

		$series = array();
		foreach ( $ids as $serie_id ) {
			$term = get_term( $serie_id, 'pa_serie' );
			if ( ! $term instanceof WP_Term ) {
				continue;
			}
			$url = get_term_link( $term );
			if ( ! is_string( $url ) ) {
				continue;
			}
			$series[] = array(
				'name'  => (string) $term->name,
				'url'   => $url,
				'cover' => SeriesCover::for_tax_query(
					array(
						array( 'taxonomy' => 'pa_serie', 'field' => 'term_id', 'terms' => array( (int) $term->term_id ) ),
						array( 'taxonomy' => Shortcode::TAXONOMY, 'field' => 'term_id', 'terms' => array( (int) $brand->term_id ) ),
					)
				),
			);
		}

		$out = array(
			'series'     => $series,
			'price_from' => self::lowest_price( (int) $brand->term_taxonomy_id ),
		);
		set_transient( $key, $out, self::TRANSIENT_TTL );

		return $out;
	}

	private static function transient_key( int $term_id ): string {
		return 'akb_edition_facts_' . $term_id;
	}
}
