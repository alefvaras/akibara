<?php
/**
 * Foto opcional para la reseña voluntaria nativa de WooCommerce.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Reviews;

defined( 'ABSPATH' ) || exit;

/** Mantiene la reseña de la ficha voluntaria, verificada y pendiente. */
final class VoluntaryReviewForm {

	private const PHOTO_FIELD = 'akb_vr_photo';
	private const NONCE_FIELD = '_akb_vr_nonce';
	private const MAX_UPLOAD  = 5242880;
	/** @var int Foto recién subida que se elimina si Core no crea la reseña. */
	private int $attachment_id = 0;
	/** @var int Producto del envío cuya foto espera insertarse. */
	private int $product_id = 0;
	/** @var int Nivel del buffer propio para no cerrar un buffer ajeno. */
	private int $buffer_level = 0;

	/** Conecta la presentación y la validación antes de insertar o premiar. */
	public function register(): void {
		add_filter( 'woocommerce_product_review_comment_form_args', array( $this, 'handle_form_args' ), 20 );
		add_action( 'comment_form_before', array( $this, 'handle_form_before' ), 0 );
		add_action( 'comment_form_after', array( $this, 'handle_form_after' ), PHP_INT_MAX );
		add_filter( 'preprocess_comment', array( $this, 'handle_preprocess_comment' ), 20 );
		add_filter( 'pre_comment_approved', array( $this, 'handle_pre_comment_approved' ), PHP_INT_MAX, 2 );
		add_action( 'wp_insert_comment', array( $this, 'handle_inserted_comment' ), 1, 2 );
		add_action( 'shutdown', array( $this, 'discard_orphan_photo' ) );
	}

	/**
	 * Añade foto y nonce al formulario real de Woo sin JavaScript.
	 *
	 * @param array<string,mixed> $args Argumentos del formulario.
	 * @return array<string,mixed>
	 */
	public function handle_form_args( array $args ): array {
		$product_id = (int) get_the_ID();
		if ( get_current_user_id() <= 0 || 'product' !== get_post_type( $product_id ) ) {
			return $args;
		}
		$args['comment_field'] = (string) ( $args['comment_field'] ?? '' )
			. wp_nonce_field( 'akb_voluntary_review_' . $product_id, self::NONCE_FIELD, true, false )
			. '<input type="hidden" name="akb_rf_points_requested" value="0">'
			. '<p class="comment-form-photo"><label for="akb_vr_photo">Foto de tu compra (opcional)</label><input type="file" id="akb_vr_photo" name="akb_vr_photo" accept="image/jpeg,image/png,image/webp,image/heic" aria-describedby="akb-vr-photo-help"><span id="akb-vr-photo-help">Máximo 5 MB; JPG, PNG, WebP o HEIC. Adjuntar una foto no solicita puntos.</span></p>';
		return $args;
	}

	/** Captura sólo el formulario del producto para añadir el enctype que Core no expone. */
	public function handle_form_before(): void {
		if ( ! is_product() || get_current_user_id() <= 0 || $this->buffer_level > 0 ) {
			return;
		}
		ob_start();
		$this->buffer_level = ob_get_level();
	}

	/** Devuelve el formulario nativo con una codificación multipart real. */
	public function handle_form_after(): void {
		if ( 0 === $this->buffer_level || ob_get_level() !== $this->buffer_level ) {
			return;
		}
		$html               = (string) ob_get_clean();
		$this->buffer_level = 0;
		$html               = (string) preg_replace( '/(<form\b[^>]*)(>)/i', '$1 enctype="multipart/form-data"$2', $html, 1 );
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML generado y escapado por comment_form().
	}

	/**
	 * Valida compra, nonce y foto; toda reseña nativa es voluntaria.
	 *
	 * @param array<string,mixed> $data Datos sin insertar.
	 * @return array<string,mixed>
	 */
	public function handle_preprocess_comment( array $data ): array {
		$path = (string) wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ), PHP_URL_PATH );
		if ( 1 !== preg_match( '#(?:^|/)wp-comments-post\.php$#', $path ) ) {
			return $data;
		}
		$product_id = (int) ( $data['comment_post_ID'] ?? 0 );
		if ( 'product' !== get_post_type( $product_id ) || (int) ( $data['comment_parent'] ?? 0 ) > 0 ) {
			return $data;
		}
		$user_id = get_current_user_id();
		$user    = $user_id > 0 ? get_user_by( 'id', $user_id ) : false;
		if ( ! $user instanceof \WP_User || (int) ( $data['user_id'] ?? $data['user_ID'] ?? 0 ) !== $user_id ) {
			wp_die(
				'Inicia sesión con la cuenta de tu compra para enviar esta reseña. Si compraste como invitado, usa el enlace de reseña de tu pedido.',
				'',
				array(
					'response'  => 403,
					'back_link' => true,
				)
			);
		}
		$nonce = isset( $_POST[ self::NONCE_FIELD ] ) && is_string( $_POST[ self::NONCE_FIELD ] ) ? sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- se verifica justo debajo.
		if ( ! wp_verify_nonce( $nonce, 'akb_voluntary_review_' . $product_id ) ) {
			wp_die(
				'La sesión del formulario caducó. Vuelve a la ficha y reinténtalo.',
				'',
				array(
					'response'  => 403,
					'back_link' => true,
				)
			);
		}
		$order_id = self::verified_order_id( $product_id, $user_id, (string) $user->user_email );
		if ( 0 === $order_id ) {
			wp_die(
				'Solo puedes reseñar un producto de una compra verificada que no hayas devuelto.',
				'',
				array(
					'response'  => 403,
					'back_link' => true,
				)
			);
		}

		$file = $_FILES[ self::PHOTO_FIELD ] ?? array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- estructura validada por upload_photo(), después del nonce.
		if ( ! is_array( $file ) || ( isset( $file['error'] ) && UPLOAD_ERR_NO_FILE !== $file['error'] ) || ! empty( $file['name'] ) ) {
			$upload = self::upload_photo( self::PHOTO_FIELD );
			if ( is_wp_error( $upload ) ) {
				wp_die(
					esc_html( 'La foto no se pudo subir: ' . $upload->get_error_message() ),
					'',
					array(
						'response'  => 400,
						'back_link' => true,
					)
				);
			}
			$this->attachment_id = $upload;
			$this->product_id    = $product_id;
		}
		$data['comment_type']                                        = 'review';
		$data['comment_meta']                                        = is_array( $data['comment_meta'] ?? null ) ? $data['comment_meta'] : array();
		$data['comment_meta'][ ReviewPoints::META_POINTS_REQUESTED ] = 0;
		$data['comment_meta'][ ReviewForm::META_ORDER ]              = $order_id;
		$data['comment_meta']['verified']                            = 1;
		return $data;
	}

	/**
	 * Respeta el interruptor nativo y conserva spam, papelera y errores del núcleo.
	 *
	 * @param int|string|\WP_Error $approved Veredicto previo.
	 * @param array<string,mixed>  $data Datos de reseña.
	 * @return int|string|\WP_Error
	 */
	public function handle_pre_comment_approved( $approved, array $data ) {
		if ( 'yes' !== (string) get_option( 'akb_rewards_moderate_reviews', 'yes' ) ) {
			return $approved;
		}
		if ( isset( $data['comment_meta'][ ReviewPoints::META_POINTS_REQUESTED ] )
			&& 0 === $data['comment_meta'][ ReviewPoints::META_POINTS_REQUESTED ]
			&& 'product' === get_post_type( (int) ( $data['comment_post_ID'] ?? 0 ) )
			&& ! is_wp_error( $approved ) && ! in_array( $approved, array( 'spam', 'trash' ), true ) ) {
			return 0;
		}
		return $approved;
	}

	/**
	 * Enlaza la foto antes del motor; Core ya guardó la meta explícita.
	 *
	 * @param int         $comment_id Reseña creada.
	 * @param \WP_Comment $comment Reseña creada por Core.
	 */
	public function handle_inserted_comment( int $comment_id, \WP_Comment $comment ): void {
		if ( $this->attachment_id <= 0 || (int) $comment->comment_post_ID !== $this->product_id
			|| '0' !== (string) get_comment_meta( $comment_id, ReviewPoints::META_POINTS_REQUESTED, true ) ) {
			return;
		}
		if ( ! Media::attach_photo( $comment_id, $this->attachment_id, $this->product_id ) ) {
			$this->discard_orphan_photo();
		}
		$this->attachment_id = 0;
		$this->product_id    = 0;
	}

	/** Limpia la subida si WordPress rechazó la reseña por duplicado, flood o INSERT fallido. */
	public function discard_orphan_photo(): void {
		if ( $this->attachment_id > 0 ) {
			wp_delete_attachment( $this->attachment_id, true );
			$this->attachment_id = 0;
			$this->product_id    = 0;
		}
	}

	/**
	 * Busca compras de la cuenta y del mismo correo en páginas de 50 pedidos.
	 *
	 * @param int    $product_id Producto reseñado.
	 * @param int    $user_id Cuenta del comprador.
	 * @param string $email Correo de la cuenta.
	 * @return int Pedido que prueba una unidad conservada, o cero.
	 */
	private static function verified_order_id( int $product_id, int $user_id, string $email ): int {
		foreach ( array( array( 'customer_id' => $user_id ), array( 'billing_email' => $email ) ) as $identity ) {
			$page = 1;
			do {
				$orders = wc_get_orders(
					$identity + array(
						'limit'   => 50,
						'page'    => $page,
						'orderby' => 'ID',
						'order'   => 'DESC',
						'return'  => 'objects',
					)
				);
				foreach ( $orders as $order ) {
					if ( $order instanceof \WC_Order && VerifiedPurchase::order_confirms( $order, $product_id, $email, $user_id ) ) {
						return (int) $order->get_id();
					}
				}
				$count = count( $orders );
				++$page;
			} while ( 50 === $count );
		}
		return 0;
	}

	/**
	 * Valida error, tamaño y MIME real antes de subir mediante Core.
	 *
	 * @param string $field Campo de archivo del formulario con nonce validado.
	 * @return int|\WP_Error Adjunto creado o error de validación/subida.
	 */
	public static function upload_photo( string $field ) {
		$file = $_FILES[ $field ] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- se validan cada tipo, error, tamaño y MIME antes de usar la estructura.
		if ( ! is_array( $file ) || ! is_string( $file['name'] ?? null ) || ! is_string( $file['tmp_name'] ?? null )
			|| ! is_scalar( $file['size'] ?? null ) || ! is_scalar( $file['error'] ?? null )
			|| UPLOAD_ERR_OK !== (int) $file['error'] || '' === $file['name'] || '' === $file['tmp_name'] ) {
			return new \WP_Error( 'akb_review_upload', 'elige una imagen válida y vuelve a adjuntarla.' );
		}
		if ( (int) $file['size'] > self::MAX_UPLOAD || (int) $file['size'] <= 0 ) {
			return new \WP_Error( 'akb_review_size', 'la imagen debe pesar más de 0 bytes y un máximo de 5 MB.' );
		}
		if ( ! in_array( wp_get_image_mime( $file['tmp_name'] ), array( 'image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif' ), true ) ) {
			return new \WP_Error( 'akb_review_type', 'usa una foto JPG, PNG, WebP o HEIC.' );
		}
		if ( ! function_exists( 'media_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		return media_handle_upload(
			$field,
			0,
			array(),
			array(
				'test_form' => false,
				'mimes'     => array(
					'jpg|jpeg|jpe' => 'image/jpeg',
					'png'          => 'image/png',
					'webp'         => 'image/webp',
					'heic'         => 'image/heic',
				),
			)
		);
	}
}
