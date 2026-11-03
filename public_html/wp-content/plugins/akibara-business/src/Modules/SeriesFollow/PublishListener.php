<?php
/**
 * PublishListener — el único hook de ciclo de vida del módulo. ENCOLA Y SALE.
 *
 * Por qué NO cuelga de `is_admin()`
 * ---------------------------------
 * El import del dueño y el importer de WordPress corren por CLI/cron, donde
 * `is_admin()` es false. Un listener registrado sólo en el admin no vería
 * ninguna de las publicaciones que importan.
 *
 * Por qué no puede hacer NI UNA consulta
 * --------------------------------------
 * `set_object_terms` es el evento más caro de la casa: guardar cualquier
 * término dispara el rebuild del buscador y deja `suggest` en 503 unos seis
 * minutos. Este listener viaja encima de ese evento, así que el camino de
 * `transition_post_status` se limita al `get_option` cacheado del flag, y el de
 * `set_object_terms` a una comparación de string más —sólo cuando coincide— dos
 * lecturas de post ya cacheadas. Cero `get_the_terms`, cero `wc_get_product`,
 * cero SQL.
 *
 * Por qué el scan va a +120 s y no en el mismo tick
 * ------------------------------------------------
 * `wp_insert_post()` y el importer escriben el post ANTES de asignarle los
 * términos. Escanear en el mismo tick encontraría un producto sin `pa_serie` y
 * lo descartaría en silencio — y el descarte también es idempotente, o sea que
 * ese tomo no se avisaría NUNCA. El hook `set_object_terms` es la segunda mitad
 * de esa red.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\SeriesFollow;

use Akibara\Business\Modules\SeriesCatalog\SeriesCatalogEligibility;
use Akibara\Business\Modules\Stock\StockStatus;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.110.2
 */
final class PublishListener {

	/**
	 * Colchón entre la publicación y el scan. Ver el docblock de la clase: el
	 * importer asigna los términos DESPUÉS de insertar el post.
	 */
	public const SCAN_DELAY_SECONDS = 120;

	/**
	 * Sello temporal (timestamp UTC) de cuándo un tomo volvió a habilitarse como
	 * comprable (`instock` u `onbackorder`). Permite a `DigestRunner` anunciar
	 * un tomo de catálogo histórico que el dueño acaba de habilitar o reponer.
	 */
	public const META_RESTOCKED_AT = '_akb_sf_restocked_at';

	/**
	 * @return void
	 */
	public function register(): void {
		add_action( 'transition_post_status', array( $this, 'on_transition' ), 10, 3 );
		add_action( 'set_object_terms', array( $this, 'on_terms' ), 10, 6 );
		add_action( 'woocommerce_product_set_stock_status', array( $this, 'on_stock_status_change' ), 12, 2 );
		add_action( 'woocommerce_variation_set_stock_status', array( $this, 'on_stock_status_change' ), 12, 2 );
	}

	/**
	 * Guards en orden de coste creciente: ninguno es más caro que el anterior.
	 *
	 * @param string $new_status Estado nuevo.
	 * @param string $old_status Estado anterior.
	 * @param mixed  $post       Post que transiciona.
	 * @return void
	 */
	public function on_transition( $new_status, $old_status, $post ): void {
		if ( ! $post instanceof WP_Post || 'product' !== $post->post_type ) {
			return;
		}

		if ( 'publish' !== $new_status || 'publish' === $old_status ) {
			return;
		}

		if ( ! SeriesFollow::enabled() ) {
			return;
		}

		$this->enqueue_scan( (int) $post->ID );
	}

	/**
	 * Red de seguridad para el importer, que escribe el post antes que los
	 * términos. La comparación de `$taxonomy` es lo primero a propósito: para
	 * cualquier otra taxonomía este handler cuesta una comparación de string.
	 *
	 * @param int              $object_id  Objeto al que se asignaron términos.
	 * @param mixed            $terms      Términos entrantes (sin usar).
	 * @param mixed            $tt_ids     term_taxonomy_ids (sin usar).
	 * @param string           $taxonomy   Taxonomía afectada.
	 * @param mixed            $append     Modo append (sin usar).
	 * @param mixed            $old_tt_ids term_taxonomy_ids previos (sin usar).
	 * @return void
	 */
	public function on_terms( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ): void {
		unset( $terms, $tt_ids, $append, $old_tt_ids );

		if ( SeriesCatalogEligibility::TAXONOMY !== $taxonomy ) {
			return;
		}

		if ( ! SeriesFollow::enabled() ) {
			return;
		}

		$object_id = (int) $object_id;
		if ( $object_id < 1 ) {
			return;
		}

		if ( 'product' !== get_post_type( $object_id ) || 'publish' !== get_post_status( $object_id ) ) {
			return;
		}

		$this->enqueue_scan( $object_id );
	}

	/**
	 * Despierta el escaneo cuando un tomo pasa a estado comprable (`instock` u
	 * `onbackorder`), por ejemplo cuando se publica inicialmente agotado y días
	 * después se habilita a pedido o entra en stock.
	 *
	 * @param int    $product_id ID del producto.
	 * @param string $new_status Estado nuevo ('instock'|'onbackorder'|'outofstock').
	 * @return void
	 */
	public function on_stock_status_change( $product_id, $new_status ): void {
		$product_id = (int) $product_id;
		$new_status = (string) $new_status;

		if ( $product_id < 1 ) {
			return;
		}

		if ( StockStatus::IN_STOCK !== $new_status && StockStatus::ON_BACKORDER !== $new_status ) {
			return;
		}

		if ( ! SeriesFollow::enabled() || ! SeriesFollow::trigger_on_restock_enabled() ) {
			return;
		}

		if ( 'product' !== get_post_type( $product_id ) || 'publish' !== get_post_status( $product_id ) ) {
			return;
		}

		if ( function_exists( 'update_post_meta' ) ) {
			update_post_meta( $product_id, self::META_RESTOCKED_AT, (string) time() );
		}

		$this->enqueue_scan( $product_id );
	}

	/**
	 * Encola el scan con args COMPLETOS siempre (`product_id` + `cursor`).
	 *
	 * El dedupe va por args y NO por el `$unique` de `as_enqueue_async_action`,
	 * que dedupea por hook+group SIN mirar args: con él, un import de 50 tomos
	 * encolaría A y descartaría B..Z en silencio (la misma trampa documentada en
	 * `BackInStock\StockWatcher`). El límite honesto de `as_has_scheduled_action`
	 * es que matchea args EXACTOS, así que un job con `cursor > 0` en vuelo no
	 * dedupea contra uno de `cursor = 0`. Es inofensivo: la garantía real de no
	 * duplicar avisos es `uniq_event` + `INSERT IGNORE`, no este dedupe.
	 *
	 * @param int $product_id Producto recién publicado.
	 * @return void
	 */
	private function enqueue_scan( int $product_id ): void {
		if ( $product_id < 1 ) {
			return;
		}

		$args = array(
			'product_id' => $product_id,
			'cursor'     => 0,
		);

		if ( function_exists( 'as_schedule_single_action' ) ) {
			if ( function_exists( 'as_has_scheduled_action' )
				&& as_has_scheduled_action( SeriesFollow::HOOK_SCAN, $args, SeriesFollow::AS_GROUP ) ) {
				return;
			}

			as_schedule_single_action(
				time() + self::SCAN_DELAY_SECONDS,
				SeriesFollow::HOOK_SCAN,
				$args,
				SeriesFollow::AS_GROUP
			);
			return;
		}

		// Fallback sin Action Scheduler (entornos de test/CLI pelados).
		add_action(
			'shutdown',
			static function () use ( $product_id ): void {
				do_action( SeriesFollow::HOOK_SCAN, $product_id, 0 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- SeriesFollow::HOOK_SCAN vale «akb_sf_scan_product»: sí lleva prefijo, el sniff no resuelve la constante.
			}
		);
	}
}
