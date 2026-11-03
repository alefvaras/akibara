<?php
/**
 * AdminReviews — Creación y transcripción de reseñas con foto desde el panel de administración.
 *
 * El motor (`create_review`) clona el commentdata de la ruta de reseñas
 * (ingeniería inversa) para que TODAS las superficies existentes — schema,
 * carrusel, contadores de WooCommerce, mu-plugin del home — la vean como una
 * reseña más:
 *   - comment_type 'review', meta rating INT vía commentdata, verified 0/1.
 *   - `do_action('comment_post')` a mano tras wp_insert_comment (así lo hace
 *     CusRev; sin eso WooCommerce no escribe su meta 'verified').
 *   - `wp_update_comment_count()` explícito: es el ÚNICO disparador del
 *     recálculo de _wc_average_rating/_wc_review_count (ingeniería inversa
 *     de WC_Comments) y wp_insert_comment no lo llama por sí solo.
 *   - Marca interna `_akb_rewards_admin_created`: estas reseñas JAMÁS pagan
 *     puntos (las escribe la tienda) y el badge de autoría queda auditable.
 *
 * A diferencia del tercero, acá el rating SÍ se valida 1..5 (CusRev acepta
 * cualquier entero — trampa documentada).
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Reviews;

use Akibara\Rewards\Points\Ledger;
use Akibara\Rewards\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.2.0
 */
final class AdminReviews {

	public const META_ADMIN_CREATED = '_akb_rewards_admin_created';

	/** La divulgación de que la tienda escribió la reseña a nombre del cliente. */
	public const TEXTO_TRANSCRITA = 'Reseña compartida con la tienda y publicada por Akibara a nombre del cliente.';
	public const CAPABILITY         = 'manage_woocommerce';
	public const ACTION             = 'akb_rewards_create_review';
	public const ACTION_RECHECK     = 'akb_rewards_recheck_review_points';
	private const NOTICE_KEY        = 'akb_rw_review_recheck_';

	/**
	 * Cuelga la pantalla y el receptor del formulario.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_form' ) );
		add_action( 'admin_post_' . self::ACTION_RECHECK, array( $this, 'handle_recheck' ) );
		add_action( 'admin_notices', array( $this, 'recheck_notice' ) );
		add_filter( 'comment_row_actions', array( $this, 'points_row_action' ), 20, 2 );

		// Divulgación pública obligatoria de transcripción para cumplimiento normativo
		// sobre transparencia comercial y procedencia de reseñas.
		add_filter( 'comment_text', array( $this, 'transcription_badge' ), 20, 2 );
		add_filter( 'comment_text', array( $this, 'cadence_badge' ), 22, 2 );
		add_filter( 'comment_text', array( $this, 'points_badge' ), 23, 2 );
	}

	/**
	 * Acción disponible en la tabla nativa de reseñas de WooCommerce.
	 *
	 * @param array<string,string> $actions Acciones existentes.
	 * @param \WP_Comment         $comment Reseña de la fila.
	 * @return array<string,string>
	 */
	public function points_row_action( array $actions, $comment ): array {
		if ( ! current_user_can( self::CAPABILITY ) || ! self::can_recheck( $comment ) ) {
			return $actions;
		}
		$id = (int) $comment->comment_ID;
		$url = wp_nonce_url(
			add_query_arg( array( 'action' => self::ACTION_RECHECK, 'comment_id' => $id ), admin_url( 'admin-post.php' ) ),
			self::ACTION_RECHECK . '_' . $id
		);
		$actions['akb_review_points'] = '<a href="' . esc_url( $url ) . '">Revisar puntos</a>';
		return $actions;
	}

	/** @param mixed $comment Comentario a comprobar. @return bool */
	private static function can_recheck( $comment ): bool {
		return $comment instanceof \WP_Comment
			&& 'review' === (string) $comment->comment_type
			&& 0 === (int) $comment->comment_parent
			&& '1' === (string) $comment->comment_approved
			&& 'product' === get_post_type( (int) $comment->comment_post_ID )
			&& Options::reviews_lane_on();
	}

	/**
	 * Reevalúa con el motor normal, sin cambiar publicación ni saltar reglas.
	 *
	 * @param int $comment_id Reseña elegida por el administrador.
	 * @return array{type:string,message:string} Resultado verificable.
	 */
	public function recheck_points( int $comment_id ): array {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return array( 'type' => 'error', 'message' => 'No tienes permisos para revisar puntos.' );
		}
		$comment = get_comment( $comment_id );
		if ( ! self::can_recheck( $comment ) ) {
			return array( 'type' => 'error', 'message' => 'Sólo se revisan reseñas de producto aprobadas con el programa de reseñas activo.' );
		}
		$user_id = (int) $comment->user_id;
		if ( $user_id <= 0 ) {
			$user = get_user_by( 'email', (string) $comment->comment_author_email );
			$user_id = $user instanceof \WP_User ? (int) $user->ID : 0;
		}
		$product_id = (int) $comment->comment_post_ID;
		$before = $user_id > 0 ? Ledger::count_movements_for_user( $user_id, 'review', $product_id, 'review' ) : 0;
		$public_before = self::public_reward_state( $comment_id );
		( new ReviewPoints() )->maybe_pay( $comment );
		if ( $public_before !== self::public_reward_state( $comment_id ) ) {
			// La reseña sigue aprobada: no hay transición que retire del caché
			// su antigua insignia de compra o el criterio de reseña incentivada.
			delete_transient( Carousel::TRANSIENT );
			do_action( 'litespeed_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- mismo hook de la acreditación tardía por cuenta verificada.
		}
		$after = $user_id > 0 ? Ledger::count_movements_for_user( $user_id, 'review', $product_id, 'review' ) : 0;
		$paid = (int) get_comment_meta( $comment_id, ReviewPoints::META_POINTS_PAID, true );
		if ( $after > $before && $paid > 0 ) {
			return array( 'type' => 'success', 'message' => sprintf( 'Reseña #%d: se acreditaron %s Puntos Akibara. La reseña sigue publicada.', $comment_id, number_format_i18n( $paid ) ) );
		}
		return array( 'type' => 'info', 'message' => sprintf( 'Reseña #%d: no se acreditaron puntos nuevos. %s', $comment_id, self::points_status( $comment ) ) );
	}

	/** @param int $comment_id Reseña. @return array<int,string> Metas que cambian la presentación pública. */
	private static function public_reward_state( int $comment_id ): array {
		return array(
			(string) get_comment_meta( $comment_id, 'verified', true ),
			(string) get_comment_meta( $comment_id, ReviewPoints::META_POINTS_PAID, true ),
			(string) get_comment_meta( $comment_id, ReviewPoints::META_COUNTED, true ),
		);
	}

	/** Procesa la acción autenticada y vuelve siempre a la pantalla oficial. */
	public function handle_recheck(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( 'Sin permisos.' );
		}
		$comment_id = isset( $_GET['comment_id'] ) ? absint( $_GET['comment_id'] ) : 0;
		check_admin_referer( self::ACTION_RECHECK . '_' . $comment_id );
		$result = $this->recheck_points( $comment_id );
		set_transient( self::NOTICE_KEY . get_current_user_id(), $result, MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'edit.php?post_type=product&page=product-reviews' ) );
		exit;
	}

	/** Muestra una vez el resultado real, guardado sólo para este administrador. */
	public function recheck_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- sólo elige dónde pintar un aviso guardado en servidor.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( ! current_user_can( self::CAPABILITY ) || 'product-reviews' !== $page ) {
			return;
		}
		$key = self::NOTICE_KEY . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) || ! isset( $notice['type'], $notice['message'] ) ) {
			return;
		}
		delete_transient( $key );
		$type = in_array( $notice['type'], array( 'success', 'info', 'error' ), true ) ? $notice['type'] : 'info';
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( $notice['message'] ) );
	}

	/**
	 * Estado del premio en la lista de moderación, sin recalcular ni acreditar.
	 *
	 * @param string           $text Texto de la reseña.
	 * @param \WP_Comment|null $comment Reseña.
	 * @return string
	 */
	public function points_badge( $text, $comment = null ): string {
		if ( ! is_admin() || ! current_user_can( self::CAPABILITY ) || ! $comment instanceof \WP_Comment
			|| 'review' !== (string) $comment->comment_type || (int) $comment->comment_parent > 0
			|| 'product' !== get_post_type( (int) $comment->comment_post_ID ) ) {
			return (string) $text;
		}
		// El avance ya lo muestra cadence_badge en esta misma superficie.
		if ( '' !== (string) get_comment_meta( (int) $comment->comment_ID, ReviewPoints::META_PROGRESS, true ) ) {
			return (string) $text;
		}
		return (string) $text . '<p class="akb-rw-puntos-estado" style="margin:8px 0 0;font-size:12px">' . esc_html( self::points_status( $comment ) ) . '</p>';
	}

	/** @param \WP_Comment $comment Reseña. @return string Explicación del estado guardado. */
	private static function points_status( \WP_Comment $comment ): string {
		$id = (int) $comment->comment_ID;
		$seal = (string) get_comment_meta( $id, ReviewPoints::META_POINTS_PAID, true );
		if ( (int) $seal > 0 ) {
			return sprintf( '%s Puntos Akibara ya acreditados.', number_format_i18n( (int) $seal ) );
		}
		if ( '0' === (string) get_comment_meta( $id, ReviewPoints::META_POINTS_REQUESTED, true ) ) {
			return 'El cliente no solicitó puntos por esta reseña.';
		}
		if ( ReviewPoints::SEAL_PENDING_USER === $seal ) {
			return 'Premio pendiente de crear y verificar la cuenta del cliente.';
		}
		if ( '' !== (string) get_comment_meta( $id, self::META_ADMIN_CREATED, true ) ) {
			return 'La reseña transcrita por la tienda no recibe puntos.';
		}
		if ( '1' !== (string) $comment->comment_approved ) {
			return 'Los puntos se revisan cuando apruebes la reseña.';
		}
		$progress = (string) get_comment_meta( $id, ReviewPoints::META_PROGRESS, true );
		if ( 1 === preg_match( '/^(\d+)\/(\d+)$/', $progress, $parts ) ) {
			return sprintf( 'Avance: %d de %d reseñas para el próximo premio.', (int) $parts[1], (int) $parts[2] );
		}
		if ( ! Options::reviews_lane_on() ) {
			return 'El programa de puntos por reseñas está apagado.';
		}
		if ( '0' === $seal ) {
			return 'Evaluada sin premio adicional: revisa el límite por cliente, el producto ya premiado o un incentivo previo.';
		}
		return 'Sin premio acreditado: revisa foto, compra verificada, cuenta y requisitos del programa.';
	}

	/**
	 * Submenú bajo Comentarios.
	 *
	 * Ojo: desde WooCommerce 8 las reseñas de producto YA NO se listan en
	 * Comentarios (`ReviewsCommentsOverrides` las excluye); se moderan en
	 * Productos › Reseñas. Este submenú sólo aloja el formulario de
	 * transcripción; los enlaces «ver la reseña» apuntan a la pantalla de
	 * WooCommerce, no a Comentarios.
	 *
	 * @return void
	 */
	public function add_menu(): void {
		add_comments_page(
			'Crear reseña (Akibara)',
			'Crear reseña',
			self::CAPABILITY,
			'akb-rewards-new-review',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Insignia visible en la reseña transcrita: el lector sabe que la
	 * publicó la tienda a nombre del cliente.
	 *
	 * @param string           $text    Texto del comentario.
	 * @param \WP_Comment|null $comment Comentario.
	 * @return string
	 */
	/**
	 * Indicador de avance de cadencia visible exclusivamente en la moderación del panel de administración.
	 *
	 * Cuando opera la política de puntos por cadencia de reseñas, visualiza el progreso
	 * hacia el siguiente umbral de recompensa.
	 *
	 * @internal Callback de `comment_text`.
	 *
	 * @param mixed            $text    Texto ya filtrado.
	 * @param \WP_Comment|null $comment Comentario, cuando el llamador lo pasa.
	 * @return string
	 */
	public function cadence_badge( $text, $comment = null ): string {
		$text = (string) $text;

		if ( ! is_admin() || ! $comment instanceof \WP_Comment ) {
			return $text;
		}

		$avance = (string) get_comment_meta( (int) $comment->comment_ID, ReviewPoints::META_PROGRESS, true );
		if ( '' === $avance || 1 !== preg_match( '/^(\d+)\/(\d+)$/', $avance, $partes ) ) {
			return $text;
		}

		return $text . '<p class="akb-rw-cadencia" style="margin:8px 0 0;font-size:12px;opacity:.75">'
			. esc_html( sprintf( 'Va %d de %d para el próximo premio de Puntos Akibara.', (int) $partes[1], (int) $partes[2] ) )
			. '</p>';
	}

	public function transcription_badge( $text, $comment = null ): string {
		if ( ! $comment instanceof \WP_Comment ) {
			return (string) $text;
		}

		$aviso = self::transcription_notice( (int) $comment->comment_ID );
		if ( '' === $aviso ) {
			return (string) $text;
		}

		return (string) $text
			. '<p class="akb-rw-transcrita" style="margin:8px 0 0;font-size:12px;opacity:.75">📩 ' . esc_html( $aviso ) . '</p>';
	}

	/**
	 * El aviso de transcripción de una reseña; '' si la escribió su autor.
	 *
	 * Fuente común de texto sin HTML para la ficha, el carrusel y el JSON-LD.
	 * Las dos últimas superficies no pasan por el filtro `comment_text`.
	 *
	 * @since 0.4.9
	 *
	 * @param int $comment_id Reseña.
	 * @return string
	 */
	public static function transcription_notice( int $comment_id ): string {
		return 1 === (int) get_comment_meta( $comment_id, self::META_ADMIN_CREATED, true )
			? self::TEXTO_TRANSCRITA
			: '';
	}

	/**
	 * Crea una reseña con la forma de CusRev. Motor puro de la pantalla y de
	 * cualquier import futuro.
	 *
	 * @param array{product_id:int, author:string, rating:int, content:string, attachment_id:int, title?:string, email?:string, source?:string, approved?:bool} $args Datos.
	 * @return int|\WP_Error ID del comentario creado.
	 */
	public static function create_review( array $args ) {
		$product_id = (int) ( $args['product_id'] ?? 0 );
		if ( 'product' !== get_post_type( $product_id ) ) {
			return new \WP_Error( 'akb_rw_bad_product', 'El producto no existe.' );
		}

		$rating = (int) ( $args['rating'] ?? 0 );
		if ( $rating < 1 || $rating > 5 ) {
			return new \WP_Error( 'akb_rw_bad_rating', 'La valoración debe estar entre 1 y 5.' );
		}

		$author = trim( (string) ( $args['author'] ?? '' ) );
		if ( '' === $author ) {
			return new \WP_Error( 'akb_rw_bad_author', 'La reseña necesita un nombre.' );
		}

		$content = trim( (string) ( $args['content'] ?? '' ) );
		if ( '' === $content ) {
			return new \WP_Error( 'akb_rw_bad_content', 'La reseña necesita un texto.' );
		}

		// FOTO OBLIGATORIA: sin imagen real no hay reseña de admin.
		$attachment_id = (int) ( $args['attachment_id'] ?? 0 );
		if ( $attachment_id <= 0 || ! wp_attachment_is( 'image', $attachment_id ) ) {
			return new \WP_Error( 'akb_rw_photo_required', 'La reseña del admin exige una foto (imagen real).' );
		}

		// Email opcional del cliente: enlaza a una cuenta existente si coincide,
		// o registra como reseña directa de tienda si se omite.
		$email   = sanitize_email( (string) ( $args['email'] ?? '' ) );
		$user    = '' !== $email ? get_user_by( 'email', $email ) : false;
		$user_id = $user instanceof \WP_User ? (int) $user->ID : 0;

		$commentdata = array(
			'comment_author'       => $author,
			'comment_author_email' => $email,
			'comment_author_url'   => '',
			'user_id'              => $user_id,
			'comment_content'      => $content,
			'comment_post_ID'      => $product_id,
			'comment_type'         => 'review',
			'comment_approved'     => ! empty( $args['approved'] ?? true ) ? 1 : 0,
			'comment_meta'         => array(
				'rating'                  => $rating,
				// La marca debe existir antes de que wp_insert_comment dispare ReviewPoints.
				self::META_ADMIN_CREATED  => 1,
			),
		);

		$comment_id = wp_insert_comment( wp_slash( $commentdata ) );
		if ( ! $comment_id ) {
			return new \WP_Error( 'akb_rw_insert_failed', 'WordPress rechazó la reseña.' );
		}

		// Completa los metadatos y la foto antes de notificar a comment_post.
		$title = trim( (string) ( $args['title'] ?? '' ) );
		if ( '' !== $title ) {
			update_comment_meta( $comment_id, 'cr_rev_title', $title );
		}
		Media::attach_photo( $comment_id, $attachment_id, $product_id );

		// comment_post a mano, clonado de CusRev — pero SIN los correos de
		// core («nuevo comentario» al dueño por su propia reseña): esos dos
		// oyentes se retiran solo para esta request de admin.
		remove_action( 'comment_post', 'wp_new_comment_notify_moderator' );
		remove_action( 'comment_post', 'wp_new_comment_notify_postauthor' );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- no es un hook nuestro: es el de WP core, y lo disparamos a mano porque insertamos el comentario sin pasar por wp_new_comment(). Con otro nombre, Woo no estamparía «compra verificada» y nuestro motor no vería la reseña.
		do_action( 'comment_post', $comment_id, $commentdata['comment_approved'], $commentdata );
		// Restablece las notificaciones para los demás comentarios de la petición.
		add_action( 'comment_post', 'wp_new_comment_notify_moderator' );
		add_action( 'comment_post', 'wp_new_comment_notify_postauthor' );

		// La insignia de compra verificada se calcula programáticamente contra el
		// historial de compras del cliente y producto en WooCommerce para asegurar veracidad.
		$verified = ( '' !== $email || $user_id > 0 ) && function_exists( 'wc_customer_bought_product' )
			? (bool) wc_customer_bought_product( $email, $user_id, $product_id )
			: false;
		update_comment_meta( $comment_id, 'verified', (int) $verified );

		// Registro de procedencia y trazabilidad de consentimiento.
		$source = trim( (string) ( $args['source'] ?? '' ) );
		if ( '' !== $source ) {
			update_comment_meta( $comment_id, '_akb_rewards_source_note', $source );
		}

		// El único disparador del recálculo de ratings de WooCommerce.
		wp_update_comment_count( $product_id );

		return (int) $comment_id;
	}

	/**
	 * Receptor del formulario (admin-post, nonce + capability + subida).
	 *
	 * @return void
	 */
	public function handle_form(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( self::ACTION );

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachment_id = 0;
		if ( ! empty( $_FILES['akb_rw_photo']['name'] ) ) {
			$uploaded = media_handle_upload( 'akb_rw_photo', 0 );
			if ( is_wp_error( $uploaded ) ) {
				// Conserva el motivo específico del rechazo de la foto.
				wp_safe_redirect(
					add_query_arg(
						array( 'akb_rw_error' => rawurlencode( 'Foto rechazada: ' . $uploaded->get_error_message() ) ),
						admin_url( 'edit-comments.php?page=akb-rewards-new-review' )
					)
				);
				exit;
			}
			$attachment_id = (int) $uploaded;
		}

		$result = self::create_review(
			array(
				'product_id'    => isset( $_POST['akb_rw_product'] ) ? (int) $_POST['akb_rw_product'] : 0,
				'author'        => isset( $_POST['akb_rw_author'] ) ? sanitize_text_field( wp_unslash( $_POST['akb_rw_author'] ) ) : '',
				'rating'        => isset( $_POST['akb_rw_rating'] ) ? (int) $_POST['akb_rw_rating'] : 0,
				'title'         => isset( $_POST['akb_rw_title'] ) ? sanitize_text_field( wp_unslash( $_POST['akb_rw_title'] ) ) : '',
				'content'       => isset( $_POST['akb_rw_content'] ) ? sanitize_textarea_field( wp_unslash( $_POST['akb_rw_content'] ) ) : '',
				'attachment_id' => $attachment_id,
				'email'         => isset( $_POST['akb_rw_email'] ) ? sanitize_email( wp_unslash( $_POST['akb_rw_email'] ) ) : '',
				'source'        => isset( $_POST['akb_rw_source'] ) ? sanitize_text_field( wp_unslash( $_POST['akb_rw_source'] ) ) : '',
			)
		);

		if ( is_wp_error( $result ) && $attachment_id > 0 ) {
			// Sin reseña no queda adjunto huérfano en la Media Library (el
			// residuo sin GC que le reprochamos a CusRev).
			wp_delete_attachment( $attachment_id, true );
		}

		$arg = is_wp_error( $result )
			? array( 'akb_rw_error' => rawurlencode( $result->get_error_message() ) )
			: array( 'akb_rw_created' => (int) $result );

		wp_safe_redirect( add_query_arg( $arg, admin_url( 'edit-comments.php?page=akb-rewards-new-review' ) ) );
		exit;
	}

	/**
	 * Pantalla mínima y liviana (HTML plano del admin, sin JS propio).
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		echo '<div class="wrap"><h1>Crear reseña</h1>';

		if ( isset( $_GET['akb_rw_created'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- solo pinta un aviso.
			printf(
				'<div class="notice notice-success"><p>Reseña #%d creada. <a href="%s">Ver en Productos › Reseñas →</a></p></div>',
				(int) $_GET['akb_rw_created'], // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				// Enlace directo a la vista de reseñas de productos de WooCommerce.
				esc_url( admin_url( 'edit.php?post_type=product&page=product-reviews' ) )
			);
		}
		if ( isset( $_GET['akb_rw_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- aviso de interfaz, sin efecto.
				esc_html( rawurldecode( sanitize_text_field( wp_unslash( $_GET['akb_rw_error'] ) ) ) )
			);
		}

		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th scope="row"><label for="akb_rw_product">ID del producto</label></th><td><input name="akb_rw_product" id="akb_rw_product" type="number" required class="regular-text"><p class="description">El ID de la ficha (visible en Productos → pasar el mouse).</p></td></tr>';
		echo '<tr><th scope="row"><label for="akb_rw_author">Nombre a mostrar</label></th><td><input name="akb_rw_author" id="akb_rw_author" type="text" required class="regular-text"></td></tr>';
		echo '<tr><th scope="row"><label for="akb_rw_rating">Valoración</label></th><td><select name="akb_rw_rating" id="akb_rw_rating">';
		for ( $i = 5; $i >= 1; $i-- ) {
			printf( '<option value="%1$d">%1$d ★</option>', (int) $i );
		}
		echo '</select></td></tr>';
		echo '<tr><th scope="row"><label for="akb_rw_title">Título (opcional)</label></th><td><input name="akb_rw_title" id="akb_rw_title" type="text" class="regular-text"></td></tr>';
		echo '<tr><th scope="row"><label for="akb_rw_content">Reseña</label></th><td><textarea name="akb_rw_content" id="akb_rw_content" rows="5" required class="large-text"></textarea></td></tr>';
		echo '<tr><th scope="row"><label for="akb_rw_photo">Foto (obligatoria)</label></th><td><input name="akb_rw_photo" id="akb_rw_photo" type="file" accept="image/jpeg,image/png,image/webp" required></td></tr>';
		echo '<tr><th scope="row"><label for="akb_rw_email">Email del cliente (opcional)</label></th><td><input name="akb_rw_email" id="akb_rw_email" type="email" class="regular-text"><p class="description">Si el cliente existe y compró el producto, la insignia «compra verificada» se calcula sola contra sus pedidos reales. Nunca se marca a mano.</p></td></tr>';
		echo '<tr><th scope="row"><label for="akb_rw_source">¿Dónde te la compartió?</label></th><td><input name="akb_rw_source" id="akb_rw_source" type="text" class="regular-text" placeholder="Instagram DM 30-08, WhatsApp…"><p class="description">Procedencia y consentimiento del cliente (queda en la reseña como registro interno).</p></td></tr>';
		echo '</table>';
		submit_button( 'Crear reseña' );
		echo '</form></div>';
	}
}
