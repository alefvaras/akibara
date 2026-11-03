<?php
/**
 * SeriesCacheTags — la ficha guardada en caché se refresca cuando cambia un
 * producto que ella muestra.
 *
 * POR QUÉ. Desde search 0.1.65 la ficha de producto vive en la caché de LiteSpeed
 * hasta la medianoche de la tienda (antes, una hora). La ficha muestra datos de
 * OTROS productos: los tomos de su serie («Arma tu pack», la navegación de la
 * serie) y las tarjetas de relacionados, que WooCommerce elige por categoría o
 * etiqueta y pueden ser de otra serie. Sin esto, que se agote uno de ellos dejaba
 * la ficha ofreciéndolo como disponible hasta la noche.
 *
 * CÓMO. Cada ficha lleva dos clases de etiqueta de LiteSpeed:
 * - `akb_serie.<term_taxonomy_id>` por cada serie del tomo;
 * - `akb_muestra.<id>` por cada producto que pinta en una tarjeta.
 * Cuando un producto cambia algo que se ve desde otra ficha, se purgan las
 * etiquetas de sus series, la suya de «muestra» y su propia ficha. Nunca el sitio
 * entero: una venta de One Piece no vacía la caché de Berserk.
 *
 * QUÉ CUENTA COMO CAMBIO, y por qué se mira `get_changes()` ANTES de guardar:
 * - `woocommerce_product_object_updated_props` nunca trae `name`, `status`,
 *   `catalog_visibility` ni `price`: son campos del post o se escriben aparte.
 * - `woocommerce_update_product` corre en CADA venta, porque
 *   `wc_update_product_stock()` vuelve a guardar el producto.
 * - Los cambios pendientes antes de `save()` llegan igual desde el formulario, la
 *   REST, WP-CLI, las importaciones y las rebajas programadas
 *   (`wc_scheduled_sales()` guarda con `set_price()`). Fuera del formulario
 *   LiteSpeed no purga la ficha propia, así que también se purga aquí.
 * - La cantidad purga al CRUZAR el umbral de escasez (1) en cualquier sentido:
 *   «Arma tu pack» (`BuilderWidget`) cuenta tomos de copia única con
 *   `stock_qty === 1`, así que reponer un tomo de 1 a 5 deja el aviso de
 *   escasez tan obsoleto como agotarlo. El paso a agotado lo trae aparte
 *   `stock_status` (: antes sólo se purgaba al BAJAR a 1,
 *   nunca al subir por encima).
 * - Cambiar la serie de un tomo (o publicarlo guardando la serie en el mismo
 *   envío) llega por `set_object_terms`, con la serie nueva y la vieja.
 *
 * Límites que se aceptan: Cloudflare guarda la ficha hasta 10 minutos y la purga
 * por etiqueta no llega al borde; y desde WP-CLI o la cola LiteSpeed encola la
 * purga hasta la siguiente petición web.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\SiteSpeed;

use Akibara\Business\Utilities\ProductVisibility;

defined( 'ABSPATH' ) || exit;

final class SeriesCacheTags {

	/** Etiqueta por serie (LiteSpeed le antepone el prefijo del sitio). */
	public const TAG_SERIE = 'akb_serie.';

	/** Etiqueta por producto pintado en una tarjeta de la ficha. */
	public const TAG_MUESTRA = 'akb_muestra.';

	/** Atributo que define la serie de un tomo. */
	private const TAXONOMY = 'pa_serie';

	/**
	 * Cambios de un producto que se ven desde la ficha de OTRO.
	 *
	 * @var array<int, string>
	 */
	private const CAMBIOS_VISIBLES = array(
		'name',
		'slug',
		'status',
		'catalog_visibility',
		'post_password',
		'price',
		'regular_price',
		'sale_price',
		'date_on_sale_from',
		'date_on_sale_to',
		'stock_status',
		'manage_stock',
		'backorders',
		'image_id',
	);

	/** @var array<string, true> Etiquetas ya purgadas en esta petición. */
	private array $purgadas = array();

	/** @var array<string, true> Etiquetas ya puestas en esta respuesta. */
	private array $puestas = array();

	/** @var array<int, int|null> Cantidad de cada producto ANTES de `wc_update_product_stock()`. */
	private array $stock_antes = array();

	public function register(): void {
		add_action( 'wp', array( $this, 'tag_product_page' ) );
		add_action( 'woocommerce_before_shop_loop_item', array( $this, 'tag_card' ) );
		add_action( 'woocommerce_before_product_object_save', array( $this, 'purge_on_save' ) );
		add_action( 'woocommerce_before_product_variation_object_save', array( $this, 'purge_on_save' ) );
		add_action( 'woocommerce_product_set_stock_status', array( $this, 'purge_for_product_id' ) );
		add_action( 'woocommerce_variation_set_stock_status', array( $this, 'purge_for_product_id' ) );
		add_action( 'woocommerce_product_before_set_stock', array( $this, 'remember_stock' ) );
		add_action( 'woocommerce_variation_before_set_stock', array( $this, 'remember_stock' ) );
		add_action( 'woocommerce_product_set_stock', array( $this, 'purge_on_stock' ) );
		add_action( 'woocommerce_variation_set_stock', array( $this, 'purge_on_stock' ) );
		add_action( 'set_object_terms', array( $this, 'purge_on_terms' ), 10, 6 );
		add_action( 'transition_post_status', array( $this, 'purge_on_status' ), 10, 3 );
	}

	/**
	 * Etiqueta la ficha con cada serie del tomo.
	 *
	 * @internal Callback de `wp`.
	 */
	public function tag_product_page(): void {
		if ( ! function_exists( 'is_singular' ) || ! is_singular( 'product' ) ) {
			return;
		}
		foreach ( self::series_ids( (int) get_queried_object_id() ) as $serie ) {
			$this->tag( self::TAG_SERIE . $serie );
		}
	}

	/**
	 * Etiqueta la ficha con cada producto que pinta en una tarjeta (relacionados,
	 * ventas cruzadas, cualquier bucle de WooCommerce dentro de la ficha).
	 *
	 * @internal Callback de `woocommerce_before_shop_loop_item`.
	 */
	public function tag_card(): void {
		if ( ! function_exists( 'is_singular' ) || ! is_singular( 'product' ) ) {
			return;
		}
		$id = (int) get_the_ID();
		if ( $id > 0 ) {
			$this->tag( self::TAG_MUESTRA . $id );
		}
	}

	/**
	 * Guardado de un producto: purga sólo si cambió algo que se ve desde otra ficha.
	 *
	 * @internal Callback de `woocommerce_before_(product|product_variation)_object_save`.
	 *
	 * @param mixed $product Producto a punto de guardarse.
	 */
	public function purge_on_save( $product ): void {
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_changes' ) || ! method_exists( $product, 'get_id' ) ) {
			return;
		}
		$id = (int) $product->get_id();
		if ( $id <= 0 ) {
			return; // Alta nueva: todavía no sale en ninguna ficha.
		}
		$cambios = $product->get_changes();
		if ( ! is_array( $cambios ) ) {
			return;
		}
		if ( array() !== array_intersect( self::CAMBIOS_VISIBLES, array_keys( $cambios ) ) ) {
			$this->purge_product( $id );
			return;
		}
		// La cantidad invalida otras fichas sólo al cruzar el umbral de escasez1 en
		// cualquier sentido. Este hook corre antes de guardar: _stock aún es el valor
		// anterior y cambios.stock_quantity contiene el nuevo.

		if ( array_key_exists( 'stock_quantity', $cambios ) && $this->crosses_scarcity_boundary( $id, $cambios['stock_quantity'] ) ) {
			$this->purge_product( $id );
		}
	}

	/**
	 * Compara la cantidad ya persistida contra la pendiente: true si exactamente
	 * una de las dos es escasa (≤1) y la otra no — es decir, si la reposición o
	 * la venta mueve al producto a través del umbral que usa «Arma tu pack».
	 *
	 * @param mixed $nueva Cantidad pendiente de guardar (`$cambios['stock_quantity']`).
	 */
	private function crosses_scarcity_boundary( int $product_id, $nueva ): bool {
		if ( ! is_numeric( $nueva ) ) {
			return false;
		}
		return self::es_escasa( get_post_meta( $product_id, '_stock', true ) ) !== self::es_escasa( $nueva );
	}

	/**
	 * La ÚNICA definición de «escasa» de esta clase: la misma que usa «Arma tu
	 * pack» para su aviso de copia única. Sin cantidad (stock sin gestionar) no
	 * es escasa.
	 *
	 * @param mixed $cantidad Cantidad, o null/'' si el stock no se gestiona.
	 */
	private static function es_escasa( $cantidad ): bool {
		return is_numeric( $cantidad ) && (int) $cantidad <= 1;
	}

	/**
	 * Guarda la cantidad que el producto tiene ANTES de `wc_update_product_stock()`.
	 *
	 * Esa función —la que usan la venta, la anulación que devuelve stock y las
	 * sincronizaciones de terceros— escribe la cantidad nueva DIRECTO en los
	 * datos del objeto (`read_stock_quantity()` apaga `object_read`), así que el
	 * guardado posterior no trae `stock_quantity` entre sus cambios y
	 * `purge_on_save()` no la ve. Sin esta foto, `purge_on_stock()` sólo sabe la
	 * cantidad NUEVA y no puede distinguir «5 → 6» de «1 → 2».
	 *
	 * @internal Callback de `woocommerce_(product|variation)_before_set_stock`.
	 *
	 * @param mixed $product Producto o variación, todavía con la cantidad anterior.
	 */
	public function remember_stock( $product ): void {
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) || ! method_exists( $product, 'get_stock_quantity' ) ) {
			return;
		}
		$this->stock_antes[ (int) $product->get_id() ] = $product->get_stock_quantity();
	}

	/**
	 * @internal Callback de `woocommerce_(product|variation)_set_stock_status`.
	 *
	 * @param mixed $product_id Producto o variación.
	 */
	public function purge_for_product_id( $product_id ): void {
		$this->purge_product( (int) $product_id );
	}

	/**
	 * Venta o reposición por `wc_update_product_stock()`: purga si la cantidad
	 * quedó escasa, o si DEJÓ de serlo (la anulación de un pedido que devuelve
	 * el stock de 1 a 2 borra el aviso de copia única igual que una reposición
	 * a mano).
	 *
	 * @internal Callback de `woocommerce_(product|variation)_set_stock`.
	 *
	 * @param mixed $product Producto o variación, con la cantidad nueva.
	 */
	public function purge_on_stock( $product ): void {
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) || ! method_exists( $product, 'get_stock_quantity' ) ) {
			return;
		}
		$id       = (int) $product->get_id();
		$anterior = $this->stock_antes[ $id ] ?? null;
		unset( $this->stock_antes[ $id ] );

		if ( ! self::es_escasa( $product->get_stock_quantity() ) && ! self::es_escasa( $anterior ) ) {
			return;
		}
		$this->purge_product( $id );
	}

	/**
	 * Cambio de serie: purga la serie nueva y la vieja.
	 *
	 * @internal Callback de `set_object_terms`.
	 *
	 * @param mixed $object_id  Objeto.
	 * @param mixed $terms      Términos pedidos (sin uso).
	 * @param mixed $tt_ids     Series que quedan (term_taxonomy_id).
	 * @param mixed $taxonomy   Taxonomía.
	 * @param mixed $append     Si se añadieron (sin uso).
	 * @param mixed $old_tt_ids Series que tenía (term_taxonomy_id).
	 */
	public function purge_on_terms( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ): void {
		unset( $terms, $append );
		if ( self::TAXONOMY !== $taxonomy ) {
			return;
		}
		$nuevas = self::ids( $tt_ids );
		$viejas = self::ids( $old_tt_ids );
		sort( $nuevas );
		sort( $viejas );
		if ( $nuevas === $viejas ) {
			return;
		}
		// Compartir anotar_borde_de_serie() y anotar_borde_de_ficha() entre rutas.
		// Una reasignación de términos debe invalidar también la serie anterior;
		// las relaciones actuales por sí solas ya no permiten descubrirla.

		foreach ( array_unique( array_merge( $nuevas, $viejas ) ) as $serie ) {
			$this->anotar_borde_de_serie( $serie );
		}
		$this->purge_tag( self::TAG_MUESTRA . (int) $object_id );
		// La ficha propia también cambia (su franja «Serie») y por la misma razón
		// necesita el aviso al borde, no sólo la purga por tag de origen.
		$this->anotar_borde_de_ficha( (int) $object_id );
	}

	/**
	 * Un tomo que se publica, se retira o va a la papelera cambia la lista de sus hermanos.
	 *
	 * @internal Callback de `transition_post_status`.
	 *
	 * @param mixed $new_status Estado nuevo.
	 * @param mixed $old_status Estado anterior.
	 * @param mixed $post       Entrada.
	 */
	public function purge_on_status( $new_status, $old_status, $post ): void {
		if ( ! ProductVisibility::changed( $new_status, $old_status, $post ) || ! isset( $post->ID ) ) {
			return;
		}
		$this->purge_product( (int) $post->ID );
	}

	/**
	 * Series de un producto (`term_taxonomy_id`); una variación usa las de su padre.
	 *
	 * Se usa `term_taxonomy_id` y no `term_id` porque es lo que entrega
	 * `set_object_terms`, y así las dos puntas hablan del mismo número.
	 *
	 * @return array<int, int>
	 */
	public static function series_ids( int $product_id ): array {
		$padre = self::parent_or_self( $product_id );
		if ( $padre <= 0 ) {
			return array();
		}
		$terms = get_the_terms( $padre, self::TAXONOMY );
		if ( ! is_array( $terms ) ) {
			return array();
		}
		$ids = array();
		foreach ( $terms as $term ) {
			if ( is_object( $term ) && isset( $term->term_taxonomy_id ) && (int) $term->term_taxonomy_id > 0 ) {
				$ids[ (int) $term->term_taxonomy_id ] = (int) $term->term_taxonomy_id;
			}
		}

		return array_values( $ids );
	}

	private static function parent_or_self( int $product_id ): int {
		if ( $product_id <= 0 ) {
			return 0;
		}
		$padre = (int) wp_get_post_parent_id( $product_id );

		return $padre > 0 ? $padre : $product_id;
	}

	/**
	 * @param mixed $valores Lista de ids.
	 * @return array<int, int>
	 */
	private static function ids( $valores ): array {
		if ( ! is_array( $valores ) ) {
			return array();
		}

		return array_values( array_unique( array_filter( array_map( 'intval', $valores ), static fn( int $id ): bool => $id > 0 ) ) );
	}

	/** Purga todo lo que muestra a este producto: sus series, sus tarjetas y su ficha. */
	private function purge_product( int $product_id ): void {
		$id = self::parent_or_self( $product_id );
		if ( $id <= 0 ) {
			return;
		}
		foreach ( self::series_ids( $id ) as $serie ) {
			$this->anotar_borde_de_serie( $serie );
		}
		$this->purge_tag( self::TAG_MUESTRA . $id );
		if ( ! isset( $this->purgadas[ 'ficha.' . $id ] ) ) {
			$this->purgadas[ 'ficha.' . $id ] = true;
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook público oficial de LiteSpeed Cache.
			do_action( 'litespeed_purge_post', $id );
			$this->anotar_borde_de_ficha( $id );
		}
	}

	/**
	 * Purga la etiqueta de serie en origen y su landing en el borde.
	 *
	 * Comparte una única construcción de URLs entre purge_product() y purge_on_terms(),
	 * incluidas series actuales, nuevas y anteriores. La purga de etiqueta de
	 * LiteSpeed no invalida por sí sola el HTML que retiene Cloudflare.
	 */
	private function anotar_borde_de_serie( int $serie ): void {
		$this->purge_tag( self::TAG_SERIE . $serie );
		EdgePurge::anotar( (string) get_term_link( $serie ) );
	}

	/**
	 * Purga la etiqueta de «muestra» de la ficha propia y avisa su URL al
	 * borde — usada por `purge_on_terms()` cuando cambia la serie del tomo
	 * (su franja «Serie» cambia) además de por la purga de ficha en
	 * `purge_product()`.
	 */
	private function anotar_borde_de_ficha( int $product_id ): void {
		EdgePurge::anotar( (string) get_permalink( $product_id ) );
	}

	private function purge_tag( string $tag ): void {
		if ( isset( $this->purgadas[ $tag ] ) ) {
			return;
		}
		$this->purgadas[ $tag ] = true;
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook público oficial de LiteSpeed Cache.
		do_action( 'litespeed_purge', $tag );
	}

	private function tag( string $tag ): void {
		if ( isset( $this->puestas[ $tag ] ) ) {
			return;
		}
		$this->puestas[ $tag ] = true;
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook público oficial de LiteSpeed Cache.
		do_action( 'litespeed_tag_add', $tag );
	}
}
