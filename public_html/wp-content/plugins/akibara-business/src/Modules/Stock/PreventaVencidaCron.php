<?php
/**
 * PreventaVencidaCron — barrido diario de preventas cuya fecha ya pasó.
 *
 * La degradación Preventa → A pedido es AUTOMÁTICA en la lectura (Availability
 * compara la fecha de lanzamiento contra hoy), así que badge, CTA, schema y
 * panel de la ficha cambian solos. Pero dos superficies quedan HORNEADAS con el
 * estado del momento en que se escribieron y nadie las toca al vencer la fecha:
 *
 *   1. La fila de faceta del buscador ('preventa' en idx_facet_value) — el
 *      Indexer solo reindexa por save/stock, y aquí no cambió ninguno.
 *   2. El HTML cacheado de la ficha (LiteSpeed origen + Cloudflare).
 *
 * Este cron encuentra los reservables con `release_date` vencida y les hace un
 * `wp_update_post` vacío: eso dispara `save_post_product` (reindex del
 * buscador) y la purga de página de LiteSpeed, sin mutar ninguna meta — el
 * estado se DERIVA, nunca se escribe. Idempotente: la corrida siguiente los
 * encuentra de nuevo pero el contenido regenerado ya es idéntico; el costo es
 * un touch por producto vencido al día, capado.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\Stock;

use Akibara\Business\Utilities\StoreTime;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.109.0
 */
final class PreventaVencidaCron {

	public const HOOK = 'akb_preventa_vencida_sweep';

	private const SCHEDULE = 'daily';

	/**
	 * Tope por corrida. Las preventas se cargan a mano y caducan de a pocas;
	 * 50 por día cubre cualquier semana de lanzamientos con holgura.
	 */
	private const SWEEP_CAP = 50;

	/**
	 * Testigo de «a esta ficha ya se le refrescaron las superficies horneadas
	 * después de que su lanzamiento venciera».
	 *
	 * Sin él, el barrido no avanzaba NUNCA: los mismos productos vencidos
	 * calzaban la consulta todos los días para siempre —el refresco ya estaba
	 * hecho desde el primer día— y, peor, los que caían fuera del tope de 50 no
	 * llegaban jamás a ser tocados, porque la lista se repetía idéntica. Con el
	 * testigo, lo procesado sale de la consulta y el cursor avanza solo.
	 *
	 * Se BORRA cuando cambia la fecha de lanzamiento (`akb_eta_release_date_changed`):
	 * una fecha nueva vuelve a vencer algún día y ese vencimiento sí necesita su
	 * refresco.
	 */
	public const META_SWEPT = '_akb_preventa_vencida_swept';

	public function register(): void {
		add_action( self::HOOK, array( $this, 'handle' ) );
		add_action( 'init', array( $this, 'maybe_schedule' ) );
		add_action( 'akb_eta_release_date_changed', array( $this, 'forget_sweep' ) );
	}

	/**
	 * Olvida el barrido de una ficha cuya fecha de lanzamiento se movió.
	 *
	 * @param int $product_id Producto cuya fecha cambió.
	 * @return void
	 */
	public function forget_sweep( $product_id ): void {
		$product_id = (int) $product_id;
		if ( $product_id > 0 && function_exists( 'delete_post_meta' ) ) {
			delete_post_meta( $product_id, self::META_SWEPT );
		}
	}

	/**
	 * Repone en init el evento ausente en la siguiente petición.
	 *
	 * wp_next_scheduled() consulta la opción cron cargada por WordPress. La
	 * recuperación no depende de otra activación o actualización del plugin.
	 */
	public function maybe_schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time(), self::SCHEDULE, self::HOOK );
		}
	}

	/**
	 * @return int Productos tocados en esta corrida.
	 */
	public function handle(): int {
		if ( ! function_exists( 'get_posts' ) || ! function_exists( 'wp_update_post' ) ) {
			return 0;
		}

		// «Ayer» en la zona de la tienda: el día del lanzamiento TODAVÍA es
		// preventa (misma semántica que Availability::pending_launch), así que
		// solo se barren fechas estrictamente anteriores a hoy.
		$tz  = StoreTime::zone();
		$hoy = ( new \DateTimeImmutable( 'now', $tz ) )->format( 'Y-m-d' );

		$ids = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => self::SWEEP_CAP,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- corrida diaria async, capada (patrón Arrival::seed_pending).
				'meta_query'     => array(
					array(
						'key'   => '_stock_status',
						'value' => StockStatus::ON_BACKORDER,
					),
					array(
						'key'   => Availability::META_TYPE,
						'value' => Availability::TYPE_RELEASE_DATE,
					),
					array(
						'key'     => Availability::META_RELEASE_DATE,
						'value'   => $hoy,
						'compare' => '<',
					),
					array(
						'key'     => self::META_SWEPT,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		$touched = 0;
		foreach ( $ids as $id ) {
			// Update vacío a propósito: no muta metas ni contenido — solo
			// dispara save_post_product (reindex del buscador) y las purgas de
			// caché de página. El estado nuevo lo deriva Availability al leer.
			$result = $this->touch( (int) $id );
			if ( ! is_wp_error( $result ) ) {
				++$touched;
				update_post_meta( (int) $id, self::META_SWEPT, '1' );
			}
		}

		return $touched;
	}

	/**
	 * Toca el producto SIN moverle la fecha de modificación.
	 *
	 * `wp_update_post` reescribe `post_modified` siempre, y ese campo no es
	 * decorativo acá: alimenta el `lastmod` del sitemap y el `dateModified` de
	 * las landings de serie. Tal como estaba, el barrido le juraba a Google que
	 * hasta 50 fichas se habían editado cada día —para siempre— cuando lo único
	 * que había cambiado era la caché. La fecha se repone por
	 * `wp_insert_post_data`, que corre justo antes del UPDATE, así que todos los
	 * oyentes del save (reindex del buscador, purgas) siguen disparando.
	 *
	 * @param int $id Producto a tocar.
	 * @return int|\WP_Error Lo que devuelve wp_update_post.
	 */
	private function touch( int $id ) {
		$post = function_exists( 'get_post' ) ? get_post( $id ) : null;

		$modified     = is_object( $post ) && isset( $post->post_modified ) ? (string) $post->post_modified : '';
		$modified_gmt = is_object( $post ) && isset( $post->post_modified_gmt ) ? (string) $post->post_modified_gmt : '';

		if ( '' === $modified || ! function_exists( 'add_filter' ) ) {
			return wp_update_post( array( 'ID' => $id ), true );
		}

		$preservar = static function ( $data, $postarr ) use ( $id, $modified, $modified_gmt ) {
			if ( is_array( $data ) && (int) ( $postarr['ID'] ?? 0 ) === $id ) {
				$data['post_modified']     = $modified;
				$data['post_modified_gmt'] = $modified_gmt;
			}
			return $data;
		};

		add_filter( 'wp_insert_post_data', $preservar, 10, 2 );
		$result = wp_update_post( array( 'ID' => $id ), true );
		remove_filter( 'wp_insert_post_data', $preservar, 10 );

		return $result;
	}
}
