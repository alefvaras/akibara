<?php
/**
 * Carousel — «Voces del Distrito» en la portada, first-party y LIVIANO.
 *
 * Reemplaza al mu-plugin akibara-home-reviews (que envolvía el slider Slick
 * de CusRev: ~188 KB de CSS+JS muertos en la home). Este carrusel es CSS
 * scroll-snap PURO: cero JavaScript, imágenes lazy, y cada perilla en una
 * option (regla del dueño: UI configurable y liviana).
 *
 * Compuertas de calidad:
 *   - Solo aparece cuando las reseñas elegibles sobrepasan el mínimo configurado
 *     (option `akb_rewards_carousel_min`, por defecto 8).
 *   - Elegible = aprobada, de producto publicado, ≥ 40 caracteres, y no
 *     incentivada (meta cr_coupon_code NOT EXISTS, la clave heredada).
 *
 * El shortcode se llama IGUAL que el del mu-plugin a propósito: los
 * mu-plugins cargan antes y este add_shortcode lo pisa — el post 210 de la
 * portada no se toca en el cutover.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Reviews;

use Akibara\Rewards\Support\Assets;
use Akibara\Rewards\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.2.0
 */
final class Carousel {

	public const SHORTCODE = 'akibara_home_reviews';
	public const TRANSIENT = 'akb_rewards_carousel_v7';

	/** El <style> sale una vez por petición (una instancia registra el shortcode). */
	private bool $style_printed = false;

	/**
	 * Cuelga shortcode y purgas.
	 *
	 * @return void
	 */
	public function register(): void {
		add_shortcode( self::SHORTCODE, array( $this, 'render' ) );
		HomeCache::register();

		// Invalida el transient y el HTML de portada al cambiar las reseñas.
		add_action( 'transition_comment_status', array( $this, 'flush_on_transition' ), 10, 3 );
		add_action( 'edit_comment', array( $this, 'flush_on_comment' ), 10, 1 );
		add_action( 'wp_insert_comment', array( $this, 'flush_on_insert' ), 10, 2 );
		add_action( 'deleted_comment', array( $this, 'flush_on_insert' ), 10, 2 );
		add_action( 'transition_post_status', array( $this, 'flush_for_product' ), 10, 3 );
		// Borrar EN FIRME un producto publicado no pasa por
		// transition_post_status: sin esto la portada servía hasta una hora
		// una tarjeta que enlazaba a la nada.
		add_action( 'before_delete_post', array( $this, 'flush_on_delete' ), 10, 2 );
		foreach ( array( 'added_comment_meta', 'updated_comment_meta', 'deleted_comment_meta' ) as $hook ) {
			add_action( $hook, array( $this, 'flush_on_media' ), 10, 3 );
		}
	}

	/**
	 * Cambiar una foto también puede cambiar la elegibilidad del carrusel.
	 *
	 * @param int|int[] $meta_id    Meta modificada.
	 * @param int       $comment_id Reseña.
	 * @param string    $meta_key   Clave modificada.
	 * @return void
	 */
	public function flush_on_media( $meta_id, $comment_id, $meta_key ): void {
		unset( $meta_id );
		if ( in_array( (string) $meta_key, array( Media::META_LOCAL_IMAGE, Media::META_REMOTE_IMAGE ), true ) ) {
			$this->flush_if_review( null, (int) $comment_id );
		}
	}

	/**
	 * Tira el caché de tarjetas y purga la portada servida.
	 *
	 * @return void
	 */
	public function flush(): void {
		delete_transient( self::TRANSIENT );

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- no es un hook nuestro: es el de LiteSpeed, y el nombre exacto es la única forma de que su plugin escuche y purgue la portada. Prefijarlo lo dejaría sin oyente.
		do_action( 'litespeed_purge_url', home_url( '/' ) );
		HomeCache::queue_purge();
	}

	/**
	 * ¿Este comentario toca al carrusel? Solo las RESEÑAS de PRODUCTO: un
	 * comentario del blog o un spam de Akismet no invalidan la portada.
	 *
	 * @param \WP_Comment|null $comment Comentario (o null para resolver por id).
	 * @param int              $id      ID de respaldo.
	 * @return void
	 */
	private function flush_if_review( $comment, int $id = 0 ): void {
		if ( ! $comment instanceof \WP_Comment && $id > 0 ) {
			$comment = get_comment( $id );
		}
		if ( ! $comment instanceof \WP_Comment ) {
			return;
		}
		if ( 'review' !== (string) $comment->comment_type ) {
			return;
		}
		if ( 'product' !== get_post_type( (int) $comment->comment_post_ID ) ) {
			return;
		}

		$this->flush();
	}

	/**
	 * Transición de estado de comentario.
	 *
	 * @param string           $new_status Estado nuevo.
	 * @param string           $old_status Estado anterior.
	 * @param \WP_Comment      $comment    Comentario.
	 * @return void
	 */
	public function flush_on_transition( $new_status, $old_status, $comment ): void {
		unset( $new_status, $old_status );
		$this->flush_if_review( $comment instanceof \WP_Comment ? $comment : null );
	}

	/**
	 * Edición de comentario (llega solo el id).
	 *
	 * @param int $comment_id ID.
	 * @return void
	 */
	public function flush_on_comment( $comment_id ): void {
		$this->flush_if_review( null, (int) $comment_id );
	}

	/**
	 * Inserción o borrado (id + objeto).
	 *
	 * @param int              $comment_id ID.
	 * @param \WP_Comment|null $comment    Comentario.
	 * @return void
	 */
	public function flush_on_insert( $comment_id, $comment = null ): void {
		$this->flush_if_review( $comment instanceof \WP_Comment ? $comment : null, (int) $comment_id );
	}

	/**
	 * Invalida ante un cambio efectivo de estado de producto o adjunto.
	 * transition_post_status también se dispara al guardar sin cambiar estado.
	 *
	 * @param string   $new_status Estado nuevo.
	 * @param string   $old_status Estado anterior.
	 * @param \WP_Post $post       Post.
	 * @return void
	 */
	public function flush_for_product( $new_status, $old_status, $post ): void {
		if ( (string) $new_status === (string) $old_status ) {
			return;
		}

		if ( is_object( $post ) && in_array( (string) ( $post->post_type ?? '' ), array( 'product', 'attachment' ), true ) ) {
			$this->flush();
		}
	}

	/**
	 * Borrado en firme: si el producto estaba PUBLICADO, sus tarjetas quedan
	 * enlazando a la nada.
	 *
	 * @param int           $post_id ID.
	 * @param \WP_Post|null $post    Post que se va (WP 6.6+ lo pasa).
	 * @return void
	 */
	public function flush_on_delete( $post_id, $post = null ): void {
		if ( ! is_object( $post ) ) {
			$post = get_post( (int) $post_id );
		}

		if ( is_object( $post ) && in_array( (string) ( $post->post_type ?? '' ), array( 'product', 'attachment' ), true ) ) {
			$this->flush();
		}
	}

	/**
	 * Render del shortcode. Devuelve '' bajo el umbral: la portada no muestra
	 * una sección a medio llenar.
	 *
	 * @return string
	 */
	public function render(): string {
		if ( ! Options::carousel_on() ) {
			return '';
		}

		$data = $this->data();

		if ( (int) $data['eligible'] < Options::carousel_min() ) {
			return '';
		}

		$this->enqueue_animation();

		return $this->markup( $data['cards'] );
	}

	/**
	 * Reseñas elegibles que ve HOY el carrusel, del MISMO caché con que pinta
	 * la portada. Topadas en el umbral (ver build_data): responde «¿llega al
	 * mínimo?» y, bajo el mínimo, es el número exacto. Lo lee el panel para
	 * decir si la portada muestra el título que el dueño está editando.
	 *
	 * @return int
	 */
	public function eligible_seen(): int {
		return (int) $this->data()['eligible'];
	}

	/**
	 * Tarjetas y umbral: del transient, o armados y guardados una hora.
	 *
	 * @return array{eligible:int, cards:array<int,array<string,mixed>>}
	 */
	private function data(): array {
		$data = get_transient( self::TRANSIENT );
		if ( ! is_array( $data ) ) {
			$data = $this->build_data();
			set_transient( self::TRANSIENT, $data, HOUR_IN_SECONDS );
		}

		return $data;
	}

	/**
	 * Reveal GSAP alineado con el sistema de animaciones del tema.
	 * Solo si el tema ya registró gsap/gsap-scrolltrigger en esta página: el
	 * carrusel jamás carga la librería por su cuenta, y sin ella las tarjetas
	 * se presentan con CSS nativo.
	 *
	 * @return void
	 */
	private function enqueue_animation(): void {
		if ( ! function_exists( 'wp_script_is' ) || ! wp_script_is( 'gsap', 'registered' ) || ! wp_script_is( 'gsap-scrolltrigger', 'registered' ) ) {
			return;
		}

		$rel = 'assets/js/carousel.js';

		wp_enqueue_script(
			'akb-rewards-carousel',
			Assets::url( $rel ),
			array( 'gsap', 'gsap-scrolltrigger' ),
			Assets::version( $rel ),
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}

	/**
	 * Arma las tarjetas y resuelve el umbral con UNA sola consulta.
	 *
	 * Antes eran dos con el mismo WHERE de 12 líneas copiado, una de ellas
	 * sólo para contar. El umbral no necesita un total exacto —es un «¿llega
	 * a N?»—, así que se piden tantas filas como la mayor de las dos perillas
	 * y se cuenta sobre el resultado: si vuelven menos que el mínimo, la
	 * portada no pinta la sección.
	 *
	 * @return array{eligible:int, cards:array<int,array<string,mixed>>}
	 *         `eligible` son las filas elegibles VISTAS, topadas en ese
	 *         límite: sirve para el umbral, no como total del catálogo.
	 */
	private function build_data(): array {
		global $wpdb;

		// El dueño puede excluir reseñas que cobraron puntos o contaron para
		// un premio. Disclosure resuelve el aviso incluido en cada tarjeta.
		$excluir_premiadas = '';
		if ( 'yes' === (string) get_option( 'akb_rewards_carousel_exclude_rewarded', 'no' ) ) {
			$excluir_premiadas = "AND NOT EXISTS (
				SELECT 1 FROM {$wpdb->commentmeta} pr
				WHERE pr.comment_id = c.comment_ID AND pr.meta_key IN ('_akb_rewards_review_points', '_akb_rewards_review_counted')
					AND CAST(pr.meta_value AS UNSIGNED) > 0
			)";
		}

		// El filtro de foto comprueba el adjunto igual que los premios: un adjunto
		// borrado, un video o una portada de producto no son una foto de reseña.
		// Las fotos remotas heredadas sólo aceptan URLs HTTP(S) seguras.
		// Se filtra ANTES del mínimo/límite; una página sin fotos no puede
		// ocultar fotos válidas que existan más atrás.
		$mode   = Options::carousel_media();
		$needed = max( Options::carousel_cards(), Options::carousel_min() );
		$batch  = 'mixed' === $mode ? $needed : max( 50, $needed );
		$offset = 0;
		$rows   = array();
		do {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fragmento fijo, sin entrada de usuario.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$page = (array) $wpdb->get_results(
				$wpdb->prepare(
					"SELECT c.comment_ID, c.comment_post_ID, c.comment_author, c.comment_content, c.comment_date
					FROM {$wpdb->comments} c
					INNER JOIN {$wpdb->posts} p ON p.ID = c.comment_post_ID AND p.post_type = 'product' AND p.post_status = 'publish'
					WHERE c.comment_approved = '1' AND c.comment_type = 'review' AND c.comment_parent = 0
						AND CHAR_LENGTH(c.comment_content) >= %d
						AND EXISTS (
							SELECT 1 FROM {$wpdb->commentmeta} r
							WHERE r.comment_id = c.comment_ID AND r.meta_key = 'rating'
								AND CAST(r.meta_value AS UNSIGNED) BETWEEN 1 AND 5
						)
						AND NOT EXISTS (
							SELECT 1 FROM {$wpdb->commentmeta} m
							WHERE m.comment_id = c.comment_ID AND m.meta_key = 'cr_coupon_code'
						)
						{$excluir_premiadas}
					ORDER BY c.comment_date_gmt DESC, c.comment_ID DESC
					LIMIT %d OFFSET %d",
					Options::carousel_min_chars(),
					$batch,
					$offset
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( array() !== $page && function_exists( 'update_meta_cache' ) ) {
				update_meta_cache( 'comment', array_map( static fn( $row ): int => (int) $row->comment_ID, $page ) );
			}
			foreach ( $page as $row ) {
				$image = self::review_photo( (int) $row->comment_ID );
				$has_photo = $image['id'] > 0 || '' !== $image['url'];
				if ( 'mixed' !== $mode && ( 'photos' === $mode ) !== $has_photo ) {
					continue;
				}
				$rows[] = $row;
				if ( count( $rows ) >= $needed ) {
					break;
				}
			}
			$offset += $batch;
		} while ( 'mixed' !== $mode && count( $page ) === $batch && count( $rows ) < $needed );

		$eligible = count( $rows );
		$cards    = array();

		if ( $eligible > 0 ) {
			// Del lote pedido para el umbral sólo se pintan las tarjetas
			// configuradas.
			$rows = array_slice( $rows, 0, Options::carousel_cards() );


			foreach ( $rows as $row ) {
				$comment_id = (int) $row->comment_ID;
				$rating     = (int) get_comment_meta( $comment_id, 'rating', true );
				$title      = (string) get_comment_meta( $comment_id, 'cr_rev_title', true );

				$image = self::review_photo( $comment_id );

				$cards[] = array(
					'author'    => (string) $row->comment_author,
					'text'      => (string) $row->comment_content,
					'rating'    => $rating,
					'title'     => $title,
					'photo_id'  => $image['id'],
					'photo_url' => $image['url'],
					'comment'   => $comment_id,
					'cover_id'  => (int) get_post_thumbnail_id( (int) $row->comment_post_ID ),
					'product'   => (int) $row->comment_post_ID,
					'aviso'     => Disclosure::visible_notice_for( $comment_id ),
					// El aviso de transcripción es independiente del de incentivo.
					'origen'    => AdminReviews::transcription_notice( $comment_id ),
				);
			}
		}

		return array(
			'eligible' => $eligible,
			'cards'    => $cards,
		);
	}

	/**
	 * Foto que se puede mostrar; nunca confunde una portada con foto del cliente.
	 *
	 * @param int $comment_id Reseña.
	 * @return array{id:int,url:string}
	 */
	private static function review_photo( int $comment_id ): array {
		foreach ( (array) get_comment_meta( $comment_id, Media::META_LOCAL_IMAGE ) as $attachment_id ) {
			if ( (int) $attachment_id > 0 && wp_attachment_is( 'image', (int) $attachment_id ) ) {
				return array( 'id' => (int) $attachment_id, 'url' => '' );
			}
		}
		foreach ( (array) get_comment_meta( $comment_id, Media::META_REMOTE_IMAGE ) as $remote ) {
			if ( is_array( $remote ) && ! empty( $remote['url'] ) ) {
				$url = esc_url( (string) $remote['url'], array( 'https', 'http' ) );
				if ( '' !== $url ) {
					return array( 'id' => 0, 'url' => $url );
				}
			}
		}
		return array( 'id' => 0, 'url' => '' );
	}

	/**
	 * HTML del carrusel: scroll-snap puro, sin JS, imágenes lazy, tokens de
	 * marca con fallback.
	 *
	 * @param array<int,array<string,mixed>> $cards Tarjetas.
	 * @return string
	 */
	private function markup( array $cards ): string {
		if ( array() === $cards ) {
			return '';
		}

		// Precalentamiento de caché para los productos referenciados por las tarjetas,
		// evitando consultas SQL N+1 en get_permalink() y get_the_title().
		$product_ids = array_values( array_unique( array_map( static fn( array $c ): int => (int) $c['product'], $cards ) ) );
		if ( function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( $product_ids, false, false );
		}

		// Tokens del tema; fotos y portadas tienen tratamientos distintos.
		// Las tarjetas se estiran a la misma altura y el pie permanece alineado.
		$css = '.akb-rw-carousel{--akb-rw-bg:var(--aki-surface-2,#1c1c1c);--akb-rw-fg:var(--aki-text,#f5f5f5);--akb-rw-accent:var(--aki-red-readable,#FF4756);box-sizing:border-box;width:100%;max-width:1280px;margin:clamp(32px,6vw,72px) auto;padding:0 16px}'
			. '.akb-rw-carousel .akb-rw-header{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;margin-bottom:8px}'
			. '.akb-rw-carousel h2{margin:0 0 4px;width:fit-content;font-family:var(--font-heading,inherit);font-size:clamp(26px,3.5vw,40px);line-height:1.15;letter-spacing:.04em;text-transform:uppercase;color:var(--aki-white,#fff);overflow-wrap:anywhere}'
			. '.akb-rw-carousel .akb-rw-nav{display:flex;align-items:center;gap:8px}'
			. '.akb-rw-carousel .akb-rw-nav[hidden],.akb-rw-carousel .akb-rw-nav[data-hidden="true"]{display:none!important}'
			. '.akb-rw-carousel .akb-rw-nav-btn{display:inline-flex;align-items:center;justify-content:center;width:44px;height:44px;background:var(--akb-rw-bg);color:var(--aki-white,#fff);border:1px solid var(--aki-border,rgba(255,255,255,.18));cursor:pointer;transition:background-color 150ms ease,border-color 150ms ease,transform 150ms ease,opacity 150ms ease;padding:0}'
			. '.akb-rw-carousel .akb-rw-nav-btn:hover:not(:disabled){background:var(--akb-rw-accent);border-color:var(--aki-white,#fff);transform:translateY(-2px)}'
			. '.akb-rw-carousel .akb-rw-nav-btn:active:not(:disabled){transform:translateY(0)}'
			. '.akb-rw-carousel .akb-rw-nav-btn:focus-visible{outline:2px solid var(--akb-rw-accent);outline-offset:2px}'
			. '.akb-rw-carousel .akb-rw-nav-btn:disabled,.akb-rw-carousel .akb-rw-nav-btn[aria-disabled="true"]{opacity:.3!important;cursor:not-allowed!important;pointer-events:none!important;transform:none!important;border-color:var(--aki-border,rgba(255,255,255,.12))!important;background:var(--akb-rw-bg)!important}'
			. '.akb-rw-carousel .akb-rw-track{display:flex;align-items:stretch;gap:20px;overflow-x:auto;scroll-snap-type:x mandatory;scroll-behavior:smooth;padding:8px 4px 20px;-webkit-overflow-scrolling:touch;list-style:none;margin:0}'
			. '.akb-rw-carousel .akb-rw-track:focus-visible{outline:2px solid var(--akb-rw-accent);outline-offset:-2px}'
			. '.akb-rw-carousel .akb-rw-track::-webkit-scrollbar{height:4px}'
			. '.akb-rw-carousel .akb-rw-track::-webkit-scrollbar-track{background:rgba(255,255,255,.06)}'
			. '.akb-rw-carousel .akb-rw-track::-webkit-scrollbar-thumb{background:var(--akb-rw-accent)}'
			. '.akb-rw-carousel .akb-rw-card{box-sizing:border-box;position:relative;display:flex;flex-direction:column;scroll-snap-align:start;flex:0 0 min(360px,86vw);max-width:min(360px,86vw);min-height:410px;background:var(--akb-rw-bg);color:var(--akb-rw-fg);border:1px solid var(--aki-border,rgba(255,255,255,.16));border-top:3px solid var(--akb-rw-accent);border-radius:0;padding:22px;margin:0;overflow:hidden;transition:transform .22s cubic-bezier(.16,1,.3,1),box-shadow .22s ease,border-color .22s ease}'
			. '.akb-rw-carousel .akb-rw-card:hover{transform:translateY(-4px);box-shadow:0 14px 34px rgba(0,0,0,.75),4px 4px 0 var(--akb-rw-accent);border-color:rgba(255,255,255,.28)}'
			. '.akb-rw-carousel .akb-rw-stars{color:var(--akb-rw-fg);letter-spacing:3px;font-size:18px;line-height:1.5}'
			. '.akb-rw-carousel .akb-rw-title{display:block;margin:0 0 6px}'
			. '.akb-rw-carousel .akb-rw-text{margin:10px 0 14px;font-size:15.5px;line-height:1.6;overflow-wrap:anywhere;display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:5;overflow:hidden}'
			. '.akb-rw-carousel .akb-rw-card:not(:has(.akb-rw-media)) .akb-rw-text{font-size:16px;line-height:1.65;-webkit-line-clamp:7}'
			. '.akb-rw-carousel .akb-rw-meta{font-size:14px;line-height:1.5;color:var(--akb-rw-fg);margin:0 0 12px}'
			. '.akb-rw-carousel .akb-rw-author{font-weight:600;margin:0 0 10px;overflow-wrap:anywhere}'
			. '.akb-rw-carousel .akb-rw-verified-pill{display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:var(--aki-green-bright,#86efac);background:rgba(20,83,45,.35);border:1px solid rgba(134,239,172,.3);padding:3px 8px;margin-bottom:12px;width:fit-content}'
			. '.akb-rw-carousel .akb-rw-media{position:relative;display:block;width:100%;height:180px;margin:6px 0 14px;background:#0d0d10;border:1px solid var(--aki-border,rgba(255,255,255,.14));box-shadow:2px 2px 0 #000;overflow:hidden;text-decoration:none}'
			. '.akb-rw-carousel .akb-rw-photo{display:block;width:100%!important;height:100%!important;object-fit:cover!important;object-position:center;margin:0;transition:transform .35s cubic-bezier(.16,1,.3,1)}'
			. '.akb-rw-carousel .akb-rw-card:hover .akb-rw-photo{transform:scale(1.04)}'
			. '.akb-rw-carousel .akb-rw-photo-tag{position:absolute;top:8px;left:8px;z-index:2;display:inline-flex;align-items:center;gap:5px;padding:3px 8px;background:rgba(10,10,12,.88);backdrop-filter:blur(4px);border:1px solid rgba(255,255,255,.2);color:var(--aki-white,#fff);font-size:10px;font-weight:700;letter-spacing:.05em;text-transform:uppercase}'
			. '.akb-rw-carousel .akb-rw-disclosure{margin:0 0 12px;padding:8px 10px;border-left:2px solid var(--akb-rw-accent);background:var(--aki-surface,rgba(255,255,255,.06));font-size:12px;line-height:1.5}'
			. '.akb-rw-carousel .akb-rw-product{display:flex;align-items:center;gap:14px;margin-top:auto;padding-top:16px;min-height:64px;border-top:1px solid var(--aki-border,rgba(255,255,255,.16));font-size:14px;line-height:1.45;color:var(--akb-rw-fg);text-decoration:none;overflow-wrap:anywhere}'
			. '.akb-rw-carousel .akb-rw-product:hover .akb-rw-product-name{text-decoration:underline}'
			. '.akb-rw-carousel .akb-rw-product:focus-visible{outline:2px solid var(--akb-rw-accent);outline-offset:3px;border-radius:4px}'
			. '.akb-rw-carousel .akb-rw-cover{width:44px!important;height:66px!important;object-fit:contain;flex:none;margin:0}'
			. '.akb-rw-carousel .akb-rw-product-label{display:block;font-size:12px;font-weight:400;margin-bottom:3px}'
			. '.akb-rw-carousel .akb-rw-product-name{display:block;font-weight:600}'
			. '.akb-rw-carousel .akb-rw-read{display:block;margin-top:5px;font-size:13px;text-decoration:underline;text-underline-offset:3px}'
			. '@media(min-width:768px){.akb-rw-carousel{padding-inline:24px}.akb-rw-carousel .akb-rw-track[data-count="1"],.akb-rw-carousel .akb-rw-track[data-count="2"],.akb-rw-carousel .akb-rw-track[data-count="3"]{display:grid;grid-template-columns:repeat(var(--akb-rw-count),minmax(0,1fr))}.akb-rw-carousel:has(.akb-rw-track[data-count="1"]) .akb-rw-nav,.akb-rw-carousel:has(.akb-rw-track[data-count="2"]) .akb-rw-nav,.akb-rw-carousel:has(.akb-rw-track[data-count="3"]) .akb-rw-nav{display:none!important}.akb-rw-carousel .akb-rw-track[data-count="1"]{max-width:640px}.akb-rw-carousel .akb-rw-track[data-count="1"] .akb-rw-card,.akb-rw-carousel .akb-rw-track[data-count="2"] .akb-rw-card,.akb-rw-carousel .akb-rw-track[data-count="3"] .akb-rw-card{max-width:none}}'
			. '@media(min-width:1024px){.akb-rw-carousel .akb-rw-track[data-count="4"]{display:grid;grid-template-columns:repeat(4,minmax(0,1fr))}.akb-rw-carousel:has(.akb-rw-track[data-count="4"]) .akb-rw-nav{display:none!important}.akb-rw-carousel .akb-rw-track[data-count="4"] .akb-rw-card{max-width:none}}'
			. '@media(prefers-reduced-motion:reduce){.akb-rw-carousel .akb-rw-card,.akb-rw-carousel .akb-rw-photo,.akb-rw-carousel .akb-rw-nav-btn{transition:none!important;transform:none!important}}';

		$items = '';
		foreach ( $cards as $card ) {
			$stars = str_repeat( '★', max( 0, min( 5, (int) $card['rating'] ) ) ) . str_repeat( '☆', 5 - max( 0, min( 5, (int) $card['rating'] ) ) );

			$photo = '';
			$photo_alt = sprintf( 'Foto de la reseña de %s', (string) $card['author'] );
			if ( (int) $card['photo_id'] > 0 ) {
				$photo = wp_get_attachment_image( (int) $card['photo_id'], 'medium', false, array( 'class' => 'akb-rw-photo', 'loading' => 'lazy', 'alt' => $photo_alt ) );
			} elseif ( '' !== (string) ( $card['photo_url'] ?? '' ) ) {
				$photo = '<img class="akb-rw-photo" src="' . esc_url( (string) $card['photo_url'] ) . '" alt="' . esc_attr( $photo_alt ) . '" loading="lazy" width="80" height="96">';
			}
			$has_photo = '' !== $photo;
			$comment_link = (string) get_permalink( (int) $card['product'] ) . '#comment-' . (int) ( $card['comment'] ?? 0 );
			$photo_html = $has_photo
				? '<figure class="akb-rw-media">'
					. '<a class="akb-rw-photo-link" href="' . esc_url( $comment_link ) . '" title="Ver reseña y foto completa en el tomo">'
					. $photo
					. '<span class="akb-rw-photo-tag" aria-hidden="true">📷 Foto del lector</span>'
					. '</a>'
				  . '</figure>'
				: '';

			$product_name = (string) get_the_title( (int) $card['product'] );
			$cover = (int) ( $card['cover_id'] ?? 0 ) > 0
				? wp_get_attachment_image( (int) $card['cover_id'], 'thumbnail', false, array( 'class' => 'akb-rw-cover', 'loading' => 'lazy', 'alt' => sprintf( 'Portada de %s', $product_name ) ) )
				: '';

			$title_html = '' !== (string) $card['title']
				? '<strong class="akb-rw-title">' . esc_html( (string) $card['title'] ) . '</strong>'
				: '';

			$verified_html = ! $has_photo
				? '<span class="akb-rw-verified-pill" aria-hidden="true">✓ Opinión verificada</span>'
				: '';

			$items .= '<li class="akb-rw-card">'
				. '<span class="akb-rw-stars" aria-hidden="true">' . esc_html( $stars ) . '</span>'
				. '<span class="screen-reader-text">' . esc_html( sprintf( '%d de 5 estrellas', (int) $card['rating'] ) ) . '</span>'
				. '<p class="akb-rw-text">' . $title_html . esc_html( self::excerpt( (string) $card['text'] ) ) . '</p>'
				. '<p class="akb-rw-meta akb-rw-author">' . esc_html( (string) $card['author'] ) . '</p>'
				. $verified_html
				. $photo_html
				. ( Options::carousel_disclosure_on() && '' !== (string) ( $card['aviso'] ?? '' ) ? '<p class="akb-rw-disclosure">' . esc_html( (string) $card['aviso'] ) . '</p>' : '' )
				. ( '' !== (string) ( $card['origen'] ?? '' ) ? '<p class="akb-rw-disclosure">' . esc_html( (string) $card['origen'] ) . '</p>' : '' )
				. '<a class="akb-rw-product" href="' . esc_url( $comment_link ) . '">'
				. $cover . '<span><span class="akb-rw-product-label">Sobre este producto</span><span class="akb-rw-product-name">' . esc_html( $product_name ) . '</span><span class="akb-rw-read">Leer la reseña completa →</span></span></a>'
				. '</li>';
		}

		// La pista con overflow es alcanzable por teclado (WCAG 2.1.1) y cuenta
		// con flechas de desplazamiento suave para escritorio.
		return '<section class="akb-rw-carousel" aria-label="Reseñas de la comunidad">'
			. $this->style_once( $css )
			. '<div class="akb-rw-header">'
				. '<div class="akb-rw-header-text">'
					. '<h2>' . esc_html( Options::carousel_title() ) . '</h2>'
					. '<p class="akb-rw-meta">La comunidad Akibara opina</p>'
				. '</div>'
				. '<div class="akb-rw-nav" aria-label="Navegación del carrusel">'
					. '<button type="button" class="akb-rw-nav-btn akb-rw-prev" data-akb-rw-prev aria-label="Ver reseñas anteriores" disabled aria-disabled="true">'
						. '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"></polyline></svg>'
					. '</button>'
					. '<button type="button" class="akb-rw-nav-btn akb-rw-next" data-akb-rw-next aria-label="Ver reseñas siguientes"' . ( count( $cards ) <= 1 ? ' disabled aria-disabled="true"' : '' ) . '>'
						. '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"></polyline></svg>'
					. '</button>'
				. '</div>'
			. '</div>'
			. '<ul class="akb-rw-track" data-count="' . count( $cards ) . '" style="--akb-rw-count:' . count( $cards ) . '" tabindex="0" aria-label="Reseñas, lista desplazable">' . $items . '</ul>'
			. '</section>';
	}

	/**
	 * Recorta a ~220 caracteres respetando límites de palabras con fallback seguro
	 * si mbstring no estuviese disponible.
	 *
	 * @param string $text Texto completo de la reseña.
	 * @return string
	 */
	public static function excerpt( string $text ): string {
		$limit = 220;
		$mb    = function_exists( 'mb_substr' ) && function_exists( 'mb_strlen' );

		$len = $mb ? mb_strlen( $text, 'UTF-8' ) : strlen( $text );
		if ( $len <= $limit ) {
			return $text;
		}

		$cut   = $mb ? mb_substr( $text, 0, $limit, 'UTF-8' ) : substr( $text, 0, $limit );
		$space = $mb && function_exists( 'mb_strrpos' ) ? mb_strrpos( $cut, ' ', 0, 'UTF-8' ) : strrpos( $cut, ' ' );

		// Cortar en el último espacio solo si no sacrifica media card: una
		// cadena sin espacios se recorta dura (mejor que desbordar).
		if ( false !== $space && $space > 120 ) {
			$cut = $mb ? mb_substr( $cut, 0, (int) $space, 'UTF-8' ) : substr( $cut, 0, (int) $space );
		}

		return rtrim( $cut, " \t\n\r.,;:" ) . '…';
	}

	/**
	 * El bloque <style> sale UNA vez por petición aunque el shortcode se
	 * pegue dos veces en la misma página.
	 *
	 * @param string $css CSS del carrusel.
	 * @return string
	 */
	private function style_once( string $css ): string {
		if ( $this->style_printed ) {
			return '';
		}
		$this->style_printed = true;

		return '<style>' . $css . '</style>';
	}
}
