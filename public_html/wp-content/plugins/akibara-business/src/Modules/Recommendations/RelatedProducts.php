<?php
/**
 * «Productos relacionados» de la ficha.
 *
 * Dos modos, elegibles en la pestaña «Recomendaciones» ({@see Settings}):
 *
 * - `otra_serie` (de fábrica): una serie distinta por hueco, cada una con
 *   su tomo de entrada, sin repetir la serie de la ficha ni otra edición de la
 *   misma obra.
 * - `comprables`: el pool de WooCommerce priorizando productos comprables en primer término.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\Recommendations;

use Akibara\Business\Modules\SeriesEditions\SeriesEditions;
use Akibara\Business\Services\VolumeTitle;

defined( 'ABSPATH' ) || exit;

final class RelatedProducts {

	/**
	 * Primeros tomos que se miran por serie para encontrar su entrada.
	 *
	 * Tres cubre el caso real de «el 01 está agotado, el 02 no» sin primar la
	 * serie entera (One Piece son 110 tomos). Si los tres están agotados, la
	 * serie entra con su tomo comprable más bajo del pool, o no entra.
	 */
	private const ESTANTE = 3;

	/**
	 * Series cuya estantería se consulta, por hueco a llenar. Con cuatro
	 * huecos, ocho series: sobra para llenar aunque la mitad no tenga entrada.
	 */
	private const SERIES_POR_HUECO = 2;

	/**
	 * Prefijo del transient donde WooCommerce guarda el pool de cada ficha
	 * (`wc_get_related_products()`, un día de vida).
	 */
	private const POOL_TRANSIENT = 'wc_related_';

	public function register(): void {
		// Desactivar el shuffle nativo de WooCommerce es esencial para que `choose()`
		// establezca el orden determinista de recomendación sin que sea sobrescrito.
		add_filter( 'woocommerce_product_related_posts_shuffle', '__return_false' );
		add_filter( 'woocommerce_related_products', array( $this, 'choose' ), 20, 3 );
		add_filter( 'woocommerce_product_related_posts_query', array( $this, 'stable_pool' ), 10, 2 );
		// Sólo el orden: cuántas tarjetas y en cuántas columnas quedan como las
		// dejen WooCommerce (4 y 4) o cualquier filtro anterior.
		add_filter( 'woocommerce_output_related_products_args', array( self::class, 'keep_display_order' ), 20 );

		// Los pools guardados se tiran cuando puede haber cambiado la consulta
		// que los armó: al actualizar este plugin y al cambiar el modo en el
		// panel. Ver `forget_pools()`. Borrar la fila (sólo por WP-CLI: el panel
		// no borra) devuelve el modo al de fábrica sin dejar ver el anterior, así
		// que ahí se tiran sin comparar.
		add_action( 'akibara_business_upgraded', array( self::class, 'forget_pools' ), 10, 0 );
		add_action( 'update_option_' . Settings::OPTION_RELATED, array( self::class, 'on_mode_updated' ), 10, 2 );
		add_action( 'add_option_' . Settings::OPTION_RELATED, array( self::class, 'on_mode_added' ), 10, 2 );
		add_action( 'delete_option_' . Settings::OPTION_RELATED, array( self::class, 'forget_pools' ), 10, 0 );
	}

	/**
	 * Pool de candidatos repartido por la categoría, en un orden fijo por ficha.
	 *
	 * Agrupa el pool por serie (`pa_serie`) y aplica un ordenamiento determinista
	 * basado en `MD5( «serie:ficha»)`. Esto garantiza que cada serie tenga la misma
	 * probabilidad de figurar en el escaparate sin sobrerrepresentar a series extensas,
	 * manteniendo enlaces internos estables para indexación y caché.
	 *
	 * El pool se almacena en el transient `wc_related_{id}` con TTL de 24 horas.
	 * Dado que la página servida en LiteSpeed se conserva hasta la medianoche de
	 * la tienda (`EditorialDiscountPublication::PRODUCT_PAGE_MIDNIGHT_MARGIN`),
	 * {@see self::forget_pools()} invalida los pools tras actualizaciones para
	 * reflejar de inmediato cualquier cambio de configuración.
	 *
	 * Solo en el modo `otra_serie`: el modo anterior se preserva intacto.
	 *
	 * @internal Callback de `woocommerce_product_related_posts_query`.
	 *
	 * @param mixed $query      Partes de la consulta (`fields`, `join`, `where`, `limits`).
	 * @param mixed $product_id Ficha cuyo pool se arma.
	 * @return mixed
	 */
	public function stable_pool( $query, $product_id = 0 ) {
		if ( ! is_array( $query ) || ! isset( $query['limits'] ) || isset( $query['orderby'] )
			|| Settings::RELATED_OTRA_SERIE !== Settings::current( Settings::OPTION_RELATED ) ) {
			return $query;
		}

		// WooCommerce une las partes con implode() en el orden del array: el
		// GROUP BY y el ORDER BY tienen que quedar ANTES del LIMIT. El id de la
		// ficha es un entero saneado, no un dato del cliente. El id desempata las
		// dos filas que pudieran compartir hash.
		$ficha  = absint( $product_id );
		$limits = $query['limits'];
		unset( $query['limits'] );

		$series = isset( $query['fields'], $query['join'] ) && 1 === preg_match( '/^\s*SELECT DISTINCT ID FROM (\S+) p\s*$/', (string) $query['fields'], $tabla )
			? self::series_tt_ids()
			: array();
		if ( array() !== $series ) {
			global $wpdb;

			$query['fields']  = ' SELECT MIN( p.ID ) AS ID, COALESCE( akb_serie.term_taxonomy_id, -p.ID ) AS akb_grupo FROM ' . $tabla[1] . ' p ';
			$query['join']   .= " LEFT JOIN {$wpdb->term_relationships} akb_serie ON akb_serie.object_id = p.ID AND akb_serie.term_taxonomy_id IN ( " . implode( ', ', $series ) . ' ) ';
			$query['groupby'] = ' GROUP BY akb_grupo ';
			$query['orderby'] = sprintf( " ORDER BY MD5( CONCAT( akb_grupo, ':', %d ) ), ID ", $ficha );
		} else {
			$query['orderby'] = sprintf( " ORDER BY MD5( CONCAT( p.ID, ':', %d ) ), p.ID ", $ficha );
		}
		$query['limits'] = $limits;

		return $query;
	}

	/**
	 * Conserva el orden que Picker::related() eligió para las tarjetas.
	 *
	 * En el modo Empezar otra serie se usa orderby none y order asc: WooCommerce
	 * no debe barajar ni invertir la prioridad de series que abren. Los demás modos
	 * conservan su presentación configurada.
	 *
	 * @internal Callback de `woocommerce_output_related_products_args`.
	 *
	 * @param mixed $args Argumentos del bloque (`posts_per_page`, `columns`, `orderby`, `order`).
	 * @return mixed
	 */
	public static function keep_display_order( $args ) {
		if ( ! is_array( $args ) || Settings::RELATED_OTRA_SERIE !== Settings::current( Settings::OPTION_RELATED ) ) {
			return $args;
		}

		$args['orderby'] = 'none';
		$args['order']   = 'asc';

		return $args;
	}

	/**
	 * Obtiene term_taxonomy_id de series para unir candidatos con su serie.
	 *
	 * get_terms() entrega una lista cacheada e invalidada por cambios de términos,
	 * sin añadir por cada candidato un JOIN a term_taxonomy.
	 *
	 * @return array<int, int>
	 */
	private static function series_tt_ids(): array {
		$tt_ids = get_terms(
			array(
				'taxonomy'   => SeriesShelf::TAXONOMY,
				'fields'     => 'tt_ids',
				'hide_empty' => false,
			)
		);

		return is_array( $tt_ids ) ? array_values( array_filter( array_map( 'intval', $tt_ids ) ) ) : array();
	}

	/**
	 * El pool en el orden fijo de la ficha: el mismo hash que {@see self::stable_pool()}
	 * (`md5()` de PHP y `MD5()` de MySQL dan el mismo hexadecimal en minúsculas),
	 * sobre la SERIE de cada producto —o `-ID` si no tiene—, comparado como
	 * texto —`strcmp`, no `<=>`, que trataría un hash sólo de cifras como
	 * número— y con el id de desempate. Los tomos de una misma serie quedan
	 * juntos, en el lugar que le toca a su serie.
	 *
	 * Lee la serie del term cache: quien llama lo primó con `SeriesShelf::prime()`.
	 *
	 * @param array<int, int> $ids        Pool.
	 * @param int             $product_id Ficha.
	 * @return array<int, int>
	 */
	private static function stable_order( array $ids, int $product_id ): array {
		$hash = array();
		foreach ( $ids as $id ) {
			$hash[ $id ] = md5( ( SeriesShelf::serie_of( $id ) ?? -$id ) . ':' . $product_id );
		}
		usort(
			$ids,
			static fn( int $a, int $b ): int => strcmp( $hash[ $a ], $hash[ $b ] ) ?: $a <=> $b
		);

		return $ids;
	}

	/**
	 * Invalida el pool almacenado en transient de todas las fichas de producto.
	 *
	 * Asegura que cualquier cambio en la consulta o configuración de recomendaciones
	 * se refleje de inmediato en la siguiente carga de página.
	 *
	 * Se ejecuta tras la actualización del plugin o ante cambios en la opción de modo.
	 * Utiliza `delete_transient()` para garantizar compatibilidad con drop-ins de object cache (LiteSpeed).
	 *
	 * @internal Callback de `akibara_business_upgraded` (idempotente, como exige
	 *           ese hook) y de los guardados y el borrado del modo.
	 *
	 * @return int Productos cuyo pool se tiró.
	 */
	public static function forget_pools(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- una vez por despliegue; sin `pre_get_posts` de por medio (el catálogo filtra y ordena ahí) para no saltarse ningún producto.
		$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product'" );

		$tirados = 0;
		foreach ( (array) $ids as $id ) {
			delete_transient( self::POOL_TRANSIENT . (int) $id );
			++$tirados;
		}

		return $tirados;
	}

	/**
	 * El dueño cambió el modo: si de verdad cambió la regla, se tiran los pools.
	 *
	 * @internal Callback de `update_option_{akb_reco_relacionados}`.
	 *
	 * @param mixed $old_value Valor anterior.
	 * @param mixed $value     Valor nuevo.
	 */
	public static function on_mode_updated( $old_value, $value ): void {
		if ( self::effective_mode( $old_value ) !== self::effective_mode( $value ) ) {
			self::forget_pools();
		}
	}

	/**
	 * Primer guardado del modo: antes regía el de fábrica.
	 *
	 * @internal Callback de `add_option_{akb_reco_relacionados}`.
	 *
	 * @param mixed $option Nombre de la option.
	 * @param mixed $value  Valor guardado.
	 */
	public static function on_mode_added( $option, $value ): void {
		unset( $option );
		self::on_mode_updated( Settings::DEFAULTS[ Settings::OPTION_RELATED ], $value );
	}

	/**
	 * El modo que rige con ese valor guardado: el mismo criterio que
	 * {@see Settings::current()} (un valor desconocido = el de fábrica).
	 *
	 * @param mixed $value Valor de la option.
	 */
	private static function effective_mode( $value ): string {
		return Settings::parse( Settings::OPTION_RELATED, $value ) ?? Settings::DEFAULTS[ Settings::OPTION_RELATED ];
	}

	/**
	 * Elige y ordena los relacionados según el modo vigente.
	 *
	 * @internal Callback de `woocommerce_related_products`.
	 *
	 * @param mixed        $related_ids Ids elegidos por WooCommerce (el pool).
	 * @param int          $product_id  Producto mostrado.
	 * @param array<mixed> $args        `limit` y `excluded_ids`.
	 * @return mixed
	 */
	public function choose( $related_ids, $product_id = 0, $args = array() ) {
		if ( ! is_array( $related_ids ) || array() === $related_ids ) {
			return $related_ids;
		}

		try {
			$ids = array_values( array_unique( array_map( 'intval', $related_ids ) ) );

			if ( Settings::RELATED_COMPRABLES === Settings::current( Settings::OPTION_RELATED ) ) {
				// El modo anterior, exactamente como era: WooCommerce sobre-pide
				// (límite + 10, y otra vez + 10 en su consulta) para variar el
				// escaparate y, como le apagamos su shuffle, se baraja aquí.
				shuffle( $ids );

				return self::purchasable_first( $ids );
			}

			// «Empezar otra serie»: el orden fijo de la ficha, no un barajado por
			// visita. Qué series salen depende de este orden, y tiene que ser el
			// mismo en cada render para que los enlaces no roten (ver
			// `stable_pool()`).
			SeriesShelf::prime( $ids );
			$ids = self::stable_order( $ids, (int) $product_id );

			$args      = is_array( $args ) ? $args : array();
			$limit     = max( 1, (int) ( $args['limit'] ?? 4 ) );
			$excluidos = array_map( 'intval', (array) ( $args['excluded_ids'] ?? array() ) );

			return $this->one_series_per_slot( $ids, (int) $product_id, $excluidos, $limit );
		} catch ( \Throwable $throwable ) {
			return $related_ids;
		}
	}

	/**
	 * Modo `comprables`: los que se pueden comprar delante, el resto de cola.
	 *
	 * Se reordena priorizando disponibilidad sin excluir agotados por completo,
	 * garantizando que series con catálogo acotado no queden con la vitrina vacía.
	 *
	 * @param array<int, int> $ids Pool ya barajado.
	 * @return array<int, int>
	 */
	private static function purchasable_first( array $ids ): array {
		SeriesShelf::prime( $ids );

		$comprables = array();
		$resto      = array();
		foreach ( $ids as $id ) {
			if ( SeriesShelf::comprable( $id ) ) {
				$comprables[] = $id;
				continue;
			}
			$resto[] = $id;
		}

		return array_merge( $comprables, $resto );
	}

	/**
	 * Modo `otra_serie` (UC-6). El criterio vive en {@see Picker::related()}.
	 *
	 * Consultas, con la caché de objetos vacía: el lote del pool (posts, meta y
	 * términos, que `choose()` ya primó para ordenarlo por serie), UNA consulta
	 * para los tomos de las series candidatas y el lote de sus primeros tomos.
	 * Nada crece con el tamaño del pool ni de la serie.
	 *
	 * @param array<int, int> $ids        Pool en el orden fijo de la ficha, ya primado.
	 * @param int             $product_id Ficha.
	 * @param array<int, int> $excluidos  La ficha y sus upsells.
	 * @param int             $limit      Huecos que pinta WooCommerce.
	 * @return array<int, int>
	 */
	private function one_series_per_slot( array $ids, int $product_id, array $excluidos, int $limit ): array {
		$candidatos = array();
		foreach ( $ids as $id ) {
			$candidatos[] = array(
				'id'        => $id,
				'serie'     => SeriesShelf::serie_of( $id ),
				'numero'    => VolumeTitle::extract_number( (string) get_post_field( 'post_title', $id, 'raw' ) ),
				'comprable' => SeriesShelf::comprable( $id ),
			);
		}

		// «No repite la serie de la ficha»: tampoco otra edición de la misma obra
		// (campo «Ediciones relacionadas» de cada serie). Sus tomos caen en el
		// grupo de la misma serie, detrás de todas las series que abren
		// (revisión B2-2-R2).
		$actuales = array_values(
			array_unique( array_merge( SeriesShelf::series_of( $product_id ), SeriesEditions::same_work_series_tt_ids( $product_id ) ) )
		);

		$pedir = array();
		foreach ( $candidatos as $candidato ) {
			$serie = $candidato['serie'];
			if ( null === $serie || in_array( $serie, $actuales, true ) || in_array( $serie, $pedir, true ) ) {
				continue;
			}
			$pedir[] = $serie;
			if ( count( $pedir ) >= $limit * self::SERIES_POR_HUECO ) {
				break;
			}
		}

		$estantes = array();
		$mirar    = array();
		foreach ( SeriesShelf::volumes( $pedir ) as $serie => $tomos ) {
			$estantes[ $serie ] = array_slice( $tomos, 0, self::ESTANTE );
			foreach ( $estantes[ $serie ] as $tomo ) {
				$mirar[] = $tomo['id'];
			}
		}
		SeriesShelf::prime( $mirar );

		$estanterias = array();
		foreach ( $estantes as $serie => $tomos ) {
			foreach ( $tomos as $tomo ) {
				$estanterias[ $serie ][] = array(
					'id'        => $tomo['id'],
					'numero'    => $tomo['numero'],
					'comprable' => SeriesShelf::comprable( $tomo['id'] ),
				);
			}
		}

		return Picker::related( $candidatos, $actuales, $estanterias, array_merge( $excluidos, array( $product_id ) ) );
	}
}
