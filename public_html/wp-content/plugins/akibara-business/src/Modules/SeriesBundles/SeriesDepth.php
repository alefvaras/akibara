<?php
/**
 * Series Bundles — SeriesDepth: cuántos tomos VENDIBLES tiene una serie.
 *
 * ── Por qué es una clase y no un método privado del BuilderWidget ────────────
 * El conteo nació dentro de `BuilderWidget::count_sellable_in_series()` porque
 * ahí sólo hacía falta para una pregunta: "¿esta serie llega al tramo más chico,
 * o el widget promete un descuento inalcanzable?". La landing de serie
 * (`LandingOffer`) parte del MISMO universo para la misma decisión, y copiar el
 * SQL habría reeditado el defecto que este módulo ya pagó una vez:
 * `CartSeriesCollector` existe porque la rutina "carrito → serie → config" vivía
 * copiada en cuatro archivos y las copias DIVERGIERON de verdad (B2: el aviso del
 * carrito prometía −$10.493 y el motor otorgaba −$7.195). Dos superficies que
 * publican el mismo umbral tienen que partir del mismo SQL o un día la ficha
 * ofrecerá el pack y la landing no, sin que nadie sepa por qué.
 *
 * ── Un universo, dos exigencias ─────────────────────────────────────────────
 * Lo que las dos superficies comparten es la CONSULTA, no el número final, y la
 * diferencia no es un detalle: `sellable_count()` cuenta de más a propósito
 * —sólo mira `post_status` y `_stock_status`— y eso es correcto para el guard de
 * encolado de la PDP, que quiere fallar hacia arriba porque el builder vuelve a
 * chequear en vivo. La landing NO tiene segundo chequeo y publica el umbral en
 * una página cacheable e indexada, así que necesita el universo que el carrito
 * acepta de verdad: por eso pide `sellable_ids()` y aplica encima los cuatro
 * guards del motor. Tratar los dos números como uno solo fue el defecto: bastaba
 * un tomo en una categoría excluida para que la landing anunciara un tramo que
 * `CartSeriesCollector::collect()` nunca puede alcanzar.
 *
 * ── La diferencia que justifica la extracción: PRODUCTO vs TÉRMINO ───────────
 * Todo lo demás del módulo entra por el PRODUCTO: `resolve_enabled_series()`
 * parte de un `product_id` y busca a qué serie pertenece. En la landing no hay
 * producto actual — el contexto es el TÉRMINO `pa_serie` de
 * `get_queried_object()`. Esta consulta ya era term-driven (recibe `term_id`),
 * así que es la pieza que las dos superficies pueden compartir tal cual; lo que
 * NO se comparte es el camino para llegar al término, y por eso `LandingOffer` no
 * toca `resolve_enabled_series()` para decidir la serie.
 *
 * Lógica sin hooks: la registra quien la use.
 *
 * Sin funciones i18n (Req 11.4).
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\SeriesBundles;

use Akibara\Business\Modules\Stock\StockStatus;

defined( 'ABSPATH' ) || exit;

final class SeriesDepth {

	/** Taxonomía de serie. Fuente única del literal para todo el módulo. */
	public const TAXONOMY = 'pa_serie';

	private Settings $settings;

	/**
	 * Memo por-request del conteo, por `term_id`.
	 *
	 * Estaba en el BuilderWidget memoizando la DECISIÓN (`series_has_reachable_tier`)
	 * y baja acá a memoizar el DATO, que es lo caro: en una landing el conteo lo
	 * piden dos consumidores (el guard de profundidad y el filtro de tramos que
	 * se muestran) y sin memo serían dos COUNT idénticos por pageload.
	 *
	 * @var array<int, int|null>
	 */
	private array $memo = array();

	/**
	 * Memo por-request de la LISTA de ids, por `term_id`. Mismo argumento que el
	 * memo del conteo: en una landing la piden dos consumidores (el universo
	 * elegible y la puerta al selector) y sin memo serían dos consultas idénticas.
	 *
	 * @var array<int, array<int, int>|null>
	 */
	private array $ids_memo = array();

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Cuenta los productos publicados y VENDIBLES (`_stock_status` instock/backorder)
	 * de la serie, con un único COUNT indexado. Cuenta de más a propósito (no filtra
	 * por elegibilidad/comprabilidad fina) para nunca suprimir de menos: es un techo
	 * del universo comprable real. Null si no hay `$wpdb`.
	 *
	 * Es UNA consulta por término y por request, no una por tomo: la landing ya
	 * carga 17–24 productos y el costo de decidir si hay pack no puede escalar con
	 * el largo de la serie.
	 */
	public function sellable_count( int $term_id ): ?int {
		if ( array_key_exists( $term_id, $this->memo ) ) {
			return $this->memo[ $term_id ];
		}

		return $this->memo[ $term_id ] = $this->query_sellable_count( $term_id );
	}

	/**
	 * Los ids de esos mismos productos, en orden estable. Null si no hay `$wpdb`.
	 *
	 * ── Por qué existe además del COUNT ─────────────────────────────────────────
	 * `sellable_count()` cuenta DE MÁS a propósito (ver su docblock): es un techo
	 * del universo comprable, correcto para el guard de encolado de la PDP, que
	 * quiere fallar hacia arriba porque el builder vuelve a chequear en vivo. La
	 * landing no tiene ese segundo chequeo y publica un umbral en una página
	 * cacheable, así que necesita el universo REAL — el que el carrito va a
	 * aceptar—, y eso exige mirar producto por producto los guards que sólo viven
	 * en WooCommerce (`is_purchasable`, `is_type('simple')`) y en el módulo
	 * (`EligibilityGuard`, categorías excluidas). Esta lista es la materia prima de
	 * ese filtro, y quien lo aplica es `LandingOffer`, que es donde ya están
	 * inyectados los cuatro guards del motor.
	 *
	 * Se devuelven TODOS los ids y no un `LIMIT`: quien consume corta cuando le
	 * alcanza (la landing sólo necesita saber si llega al tramo más alto, ~3 a 6
	 * tomos), y un `LIMIT` acá obligaría a adivinar cuántos van a caer por los
	 * guards. Es una columna de enteros: 107 filas en la serie más larga del
	 * catálogo.
	 *
	 * `ORDER BY p.ID` y no por título ni por `menu_order`: lo único que se le pide
	 * es ser ESTABLE entre `/page/1/` y `/page/3/` y bajo cualquier faceta —de eso
	 * depende que el bloque diga lo mismo en todas las URLs de la serie— y la
	 * PRIMARY KEY lo da sin ordenación extra. El tomo concreto que quede primero es
	 * indiferente: el builder de cualquiera lista la serie entera.
	 *
	 * @param int $term_id Término `pa_serie`.
	 * @return array<int, int>|null
	 */
	public function sellable_ids( int $term_id ): ?array {
		if ( array_key_exists( $term_id, $this->ids_memo ) ) {
			return $this->ids_memo[ $term_id ];
		}

		return $this->ids_memo[ $term_id ] = $this->query_sellable_ids( $term_id );
	}

	/**
	 * El COUNT propiamente dicho, sin memo.
	 *
	 * No se cachea en un transient a propósito: el dato depende del STOCK, y las
	 * dos opciones de invalidación disponibles fallan de la peor manera. La
	 * versión que ya existe (`akb_sb_vols_cache_ver`) sólo se sube en alta/edición/
	 * baja de producto — una venta que agota el último tomo no la mueve—, así que
	 * un transient colgado de ella publicaría hasta 1 h un umbral que el carrito ya
	 * no puede alcanzar; y una invalidación propia sobre los hooks de stock
	 * agregaría escrituras a cada venta para ahorrar un COUNT indexado sobre
	 * `term_relationships` + `postmeta`. La caché correcta acá es el memo de arriba.
	 */
	private function query_sellable_count( int $term_id ): ?int {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- la consulta llega ya preparada desde prepared_query(), que pasa término y taxonomía por marcadores de $wpdb->prepare(); el sniff sólo reconoce el prepare() escrito en la misma llamada.
		$count = $wpdb->get_var( $this->prepared_query( $wpdb, 'COUNT( DISTINCT p.ID )', $term_id, '' ) );

		return null === $count ? null : (int) $count;
	}

	/**
	 * La lista de ids propiamente dicha, sin memo.
	 *
	 * @param int $term_id Término `pa_serie`.
	 * @return array<int, int>|null
	 */
	private function query_sellable_ids( int $term_id ): ?array {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_col' ) ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- la consulta llega ya preparada desde prepared_query(), que pasa término y taxonomía por marcadores de $wpdb->prepare(); el sniff sólo reconoce el prepare() escrito en la misma llamada.
		$ids = $wpdb->get_col( $this->prepared_query( $wpdb, 'DISTINCT p.ID', $term_id, ' ORDER BY p.ID ASC' ) );

		if ( ! is_array( $ids ) ) {
			// `null` es error de base de datos, no "serie vacía": devolverlo como
			// lista vacía haría que la landing concluyera que no hay tomos y callara
			// el bloque por un hipo de un solo request.
			return null;
		}

		return array_values( array_filter( array_map( 'intval', $ids ) ) );
	}

	/**
	 * El MISMO FROM/WHERE para el conteo y para la lista.
	 *
	 * Dos superficies que tienen que hablar del mismo universo no pueden tener dos
	 * copias del SQL: es literalmente el defecto (B2) que hizo nacer esta clase, y
	 * acá la distancia entre las dos copias sería de veinte líneas — la que menos
	 * se nota cuando una cambia.
	 *
	 * @param object $wpdb      Conexión (se recibe para no repetir el `global`).
	 * @param string $select    Lista de selección, literal fijo del propio código.
	 * @param int    $term_id   Término `pa_serie`.
	 * @param string $order_by  Cláusula de orden, literal fijo del propio código.
	 * @return string SQL ya preparado.
	 */
	private function prepared_query( object $wpdb, string $select, int $term_id, string $order_by ): string {
		$stock_clause = $this->settings->allows_backorder()
			? "IN ( '" . StockStatus::IN_STOCK . "', '" . StockStatus::ON_BACKORDER . "' )"
			: "= '" . StockStatus::IN_STOCK . "'";

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- los nombres de tabla salen de $wpdb, `$select`/`$order_by` son literales de este archivo y `$stock_clause` es uno de dos literales fijos elegidos por un booleano, nunca entrada; el término y la taxonomía viajan por placeholders.
		return (string) $wpdb->prepare(
			"SELECT {$select}
			 FROM {$wpdb->posts} p
			 JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
			 JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
			   AND tt.term_id = %d AND tt.taxonomy = %s
			 JOIN {$wpdb->postmeta} sm ON sm.post_id = p.ID
			   AND sm.meta_key = '_stock_status' AND sm.meta_value {$stock_clause}
			 WHERE p.post_type = 'product' AND p.post_status = 'publish'{$order_by}",
			$term_id,
			self::TAXONOMY
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
