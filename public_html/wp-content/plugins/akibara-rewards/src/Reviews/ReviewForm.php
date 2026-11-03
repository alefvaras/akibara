<?php
/**
 * ReviewForm — el formulario de reseña por TOKEN (Fase 2b) + ruta legacy.
 *
 * El cliente llega desde el correo de invitación con /resena/{token}: una
 * página propia, liviana (HTML del servidor, cero JS, 44px, es-CL), que
 * lista los productos de su pedido y solicita calificación más texto. El enlace abre la
 * oferta de puntos con foto requerida. La reseña voluntaria con foto opcional vive en la ficha.
 * La reseña nace PENDIENTE (moderación previa antes de publicar) y vinculada al pedido.
 *
 * Ruta legacy /cusrev/{formId}: los 3 enlaces del tercero ya enviados por
 * correo caen en una página amable que lleva a Mi cuenta (la tabla del
 * tercero muere en el cleanup; el enlace no puede validar, pero el cliente
 * no ve un 404).
 *
 * Los núcleos render_page()/process() son testeables (devuelven string /
 * resultado); el `exit` vive solo en el wrapper.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Reviews;

use Akibara\Rewards\Support\Options;
use Akibara\Rewards\Support\PrivatePage;
use Akibara\Rewards\Support\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.3.0
 */
final class ReviewForm {

	public const QUERY_VAR        = 'akb_review_token';

	/** Regla de /resena/{token}; AccountRewards comprueba que siga en las reglas guardadas. */
	public const RULE = '^resena/([A-Za-z0-9]{20,64})/?$';
	public const LEGACY_QUERY_VAR = 'akb_review_legacy';
	public const META_ORDER       = '_akb_rewards_review_order';
	private bool $points_requested = false;
	/** @var array<string,mixed> Campos del último POST con nonce válido. */
	private array $submitted = array();

	/**
	 * URL pública del formulario para un token.
	 *
	 * @param string $token Token emitido.
	 * @return string
	 */
	public static function url_for( string $token ): string {
		// Bajo la URL de Mi cuenta para asegurar que el borde CDN respete el no-store
		// y no sirva respuestas cacheadas a invitados (ver Support\PrivatePage).
		// La ruta antigua sigue viva para retrocompatibilidad mediante redirección.
		return add_query_arg( self::QUERY_VAR, rawurlencode( $token ), PrivatePage::base_url() );
	}

	/**
	 * ¿La petición llegó por la ruta antigua `/resena/<token>/`?
	 *
	 * @return bool
	 */
	private static function es_ruta_antigua(): bool {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sólo se compara contra un prefijo literal.

		return 1 === preg_match( '#^/resena/#', (string) wp_parse_url( $uri, PHP_URL_PATH ) );
	}

	/**
	 * Cuelga rutas y render.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'add_routes' ) );
		add_filter( 'query_vars', array( $this, 'register_query_vars' ) );
		add_filter( 'woocommerce_product_review_comment_form_args', array( $this, 'native_form_notice' ) );
		( new VoluntaryReviewForm() )->register();
		( new AccountReviewLink() )->register();
		// Prioridad 1: antes de que la página de Mi cuenta pinte su acceso.
		add_action( 'template_redirect', array( $this, 'maybe_render' ), 1 );
	}

	/**
	 * Explica la reseña voluntaria de la ficha sin prometer un premio.
	 *
	 * @param array<string,mixed> $args Formulario de WooCommerce.
	 * @return array<string,mixed>
	 */
	public function native_form_notice( array $args ): array {
		$notice = 'Esta reseña es voluntaria: puedes añadir una foto y no solicita puntos.';
		if ( 'yes' === (string) get_option( 'akb_rewards_moderate_reviews', 'yes' ) ) {
			$notice .= ' Tu reseña quedará pendiente de revisión.';
		}
		if ( Options::reviews_lane_on() ) {
			$notice .= ' Para solicitar puntos con una foto real de tu compra, usa el enlace de reseña de tu pedido.';
		}
		$args['comment_field'] = '<p class="akb-review-photo-notice">' . esc_html( $notice ) . '</p>'
			. (string) ( $args['comment_field'] ?? '' );
		return $args;
	}

	/**
	 * Reglas de rewrite propias + legacy.
	 *
	 * @return void
	 */
	public function add_routes(): void {
		add_rewrite_rule( self::RULE, 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );
		add_rewrite_rule( '^cusrev/([^/]+)/?$', 'index.php?' . self::LEGACY_QUERY_VAR . '=$matches[1]', 'top' );
	}

	/**
	 * @param array<int,string> $vars Query vars públicas.
	 * @return array<int,string>
	 */
	public function register_query_vars( $vars ): array {
		$vars   = is_array( $vars ) ? $vars : array();
		$vars[] = self::QUERY_VAR;
		$vars[] = self::LEGACY_QUERY_VAR;
		return $vars;
	}

	/**
	 * Wrapper con exit: decide y pinta. El trabajo vive en los núcleos.
	 *
	 * @return void
	 */
	public function maybe_render(): void {
		$token  = (string) get_query_var( self::QUERY_VAR );
		$legacy = (string) get_query_var( self::LEGACY_QUERY_VAR );

		if ( '' === $token && '' === $legacy ) {
			return;
		}

		$metodo = isset( $_SERVER['REQUEST_METHOD'] )
			? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) )
			: 'GET';

		// Un enlace de un correo ya enviado: se lleva a la URL que el borde no
		// cachea. Sólo el GET —un POST en vuelo se atiende donde llegó— y con
		// 302, porque el destino depende de dónde viva Mi cuenta.
		if ( '' !== $token && 'GET' === $metodo && self::es_ruta_antigua() ) {
			wp_safe_redirect( self::url_for( $token ), 302, 'Akibara Rewards' );
			exit;
		}

		PrivatePage::no_cache( 'Akibara Rewards: formulario de reseña' );

		if ( '' !== $legacy ) {
			echo self::shell( $this->legacy_body() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML propio, escapado por partes.
			exit;
		}

		$notice = '';
		if ( 'POST' === $metodo ) {
			$resultado = $this->process( $token );
			if ( true === $resultado ) {
				echo self::shell( $this->thanks_body() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				exit;
			}
			$notice = (string) $resultado;
		}

		echo self::shell( $this->render_page( $token, $notice ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Valida un token contra la tabla.
	 *
	 * @param string $token Token del enlace.
	 * @return array{order_id:int,expires_at:string}|null Null si no vale (inexistente/caducado/usado).
	 */
	public static function validate_token( string $token ) {
		global $wpdb;

		if ( ! preg_match( '/^[A-Za-z0-9]{20,64}$/', $token ) ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- el único interpolado es el nombre de nuestra tabla.
				'SELECT order_id, expires_at, used_at FROM ' . Schema::review_forms_table() . ' WHERE token = %s',
				$token
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) || null !== $row['used_at'] ) {
			return null;
		}

		if ( gmdate( 'Y-m-d H:i:s' ) > (string) $row['expires_at'] ) {
			return null;
		}

		return array( 'order_id' => (int) $row['order_id'], 'expires_at' => (string) $row['expires_at'] );
	}

	/**
	 * Núcleo del POST: valida todo y crea la reseña PENDIENTE.
	 *
	 * @param string $token Token del enlace.
	 * @return true|string True si quedó creada; si no, el aviso a mostrar.
	 */
	public function process( string $token ) {
		$valid = self::validate_token( $token );
		if ( null === $valid ) {
			return 'Este enlace ya se usó o caducó.';
		}

		if ( ! isset( $_POST['_akbnonce'] ) || ! is_string( $_POST['_akbnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_akbnonce'] ) ), 'akb_review_' . $token ) ) {
			return 'La sesión del formulario caducó: vuelve a intentarlo.';
		}

		$order = wc_get_order( $valid['order_id'] );
		if ( ! $order instanceof \WC_Order ) {
			return 'No encontramos el pedido de este enlace.';
		}

		$this->submitted = array(
			'mode'      => isset( $_POST['akb_rf_mode'] ) && 'voluntary' === $_POST['akb_rf_mode'] ? 'voluntary' : 'reward',
			'product'   => isset( $_POST['akb_rf_product'] ) && is_scalar( $_POST['akb_rf_product'] ) ? (int) $_POST['akb_rf_product'] : 0,
			'rating'    => isset( $_POST['akb_rf_rating'] ) && is_scalar( $_POST['akb_rf_rating'] ) ? (int) $_POST['akb_rf_rating'] : 0,
			'content'   => isset( $_POST['akb_rf_content'] ) && is_string( $_POST['akb_rf_content'] ) ? trim( sanitize_textarea_field( wp_unslash( $_POST['akb_rf_content'] ) ) ) : '',
			'requested' => isset( $_POST['akb_rf_points_requested'] ) && is_string( $_POST['akb_rf_points_requested'] ) && '1' === sanitize_key( wp_unslash( $_POST['akb_rf_points_requested'] ) ),
		);

		$product_id = (int) $this->submitted['product'];
		if ( ! self::order_has_product( $order, $product_id ) ) {
			return 'Elige uno de los productos de tu pedido.';
		}

		if ( ! VerifiedPurchase::order_confirms( $order, $product_id, (string) $order->get_billing_email(), (int) $order->get_customer_id() ) ) {
			return 'Este producto ya no corresponde a una compra válida para reseñar.';
		}

		$rating = (int) $this->submitted['rating'];
		if ( $rating < 1 || $rating > 5 ) {
			return 'Elige una calificación de 1 a 5 estrellas.';
		}

		$content = (string) $this->submitted['content'];
		if ( mb_strlen( $content ) < 10 ) {
			return 'Cuéntanos un poco más: al menos 10 caracteres.';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- el nonce se verificó al entrar en process().
		$requested = Options::reviews_lane_on();
		if ( $requested && ( 'voluntary' === $this->submitted['mode'] || ! $this->submitted['requested'] ) ) {
			return 'Este enlace solicita puntos y requiere una foto de tu compra. Vuelve a abrir el formulario y confirma el envío.';
		}
		$has_photo = self::photo_is_present( isset( $_FILES ) && is_array( $_FILES ) ? $_FILES : array() );
		if ( $requested && ! $has_photo ) {
			return 'Para solicitar puntos necesitas una foto de tu compra. Adjunta una foto real de tu compra y vuelve a enviarla.';
		}

		$attachment_id = 0;
		$file = $_FILES['akb_rf_photo'] ?? array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- estructura validada por upload_photo(), después del nonce.
		$upload_attempted = $has_photo || ! is_array( $file ) || ( isset( $file['error'] ) && UPLOAD_ERR_NO_FILE !== $file['error'] ) || ! empty( $file['name'] );
		if ( $upload_attempted ) {
			$subida = $this->handle_upload( $product_id );
			if ( is_wp_error( $subida ) ) {
				return 'La foto no se pudo subir: ' . $subida->get_error_message();
			}
			$attachment_id = (int) $subida;
		}

		$commentdata = array(
			'comment_post_ID'      => $product_id,
			'comment_author'       => trim( (string) $order->get_billing_first_name() . ' ' . (string) $order->get_billing_last_name() ),
			'comment_author_email' => (string) $order->get_billing_email(),
			'comment_author_url'   => '',
			'comment_content'      => $content,
			'comment_type'         => 'review',
			'comment_parent'       => 0,
			'comment_author_IP'    => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
			'comment_agent'        => isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 254 ) : '',
			'user_id'              => (int) $order->get_customer_id(),
			'comment_approved'     => 0,
			'comment_meta'         => array(
				'rating' => $rating,
				ReviewPoints::META_POINTS_REQUESTED => (int) $requested,
			),
		);

		// El token se quema ANTES de crear, con escritura CONDICIONAL: dos
		// envíos casi simultáneos pasaban las dos validaciones y creaban dos
		// reseñas (y dos premios). El UPDATE ... WHERE used_at IS NULL deja
		// pasar a UNO solo — la carrera la resuelve la base, no el orden de
		// llegada. Marcar usado después era la ventana.
		if ( ! self::claim_token( $token ) ) {
			// La foto ya está en disco: se subió arriba, antes de saber quién
			// ganaba la carrera. Ese orden es deliberado —si la subida falla, el
			// token sigue vivo y el cliente reintenta—, pero deja al perdedor con
			// un adjunto sin reseña, sin `post_parent` y con URL pública. El caso
			// corriente no es un atacante sino el doble clic en «Enviar mi
			// reseña» con foto. `AdminReviews::handle_form()` ya limpia en el
			// caso equivalente («el residuo sin GC que le reprochamos a CusRev»):
			// esta ruta es la que se saltaba la convención.
			self::discard_orphan_photo( $attachment_id );
			return 'Este enlace ya se usó o caducó.';
		}

		// El orden del núcleo es escapar, filtrar e insertar. wp_insert_comment()
		// espera datos escapados para conservar las barras escritas por el cliente.
		$comment_id = wp_insert_comment( wp_filter_comment( wp_slash( $commentdata ) ) );
		if ( ! $comment_id ) {
			// Nada que premiar: el enlace vuelve a servir para reintentar, y la
			// foto del intento fallido tampoco se queda (el reintento sube la
			// suya). Sin esto, un INSERT que falle de forma repetible —un cuerpo
			// que MySQL rechaza, por ejemplo— fuga un adjunto por vuelta, porque
			// `release_token()` devuelve el enlace al ruedo.
			self::release_token( $token );
			self::discard_orphan_photo( $attachment_id );
			return 'No pudimos guardar tu reseña; inténtalo otra vez.';
		}

		// Vínculo auditable reseña↔pedido (spec 2b: «guarda el vínculo al
		// pedido»); Woo escribe 'verified' en el comment_post de abajo.
		update_comment_meta( $comment_id, self::META_ORDER, (int) $order->get_id() );
		// Woo sólo reconoce processing/completed al verificar compras. El
		// pedido del token permite verificar también Listo para enviar pagado.
		// Se escribe antes de comment_post: el add_comment_meta unique de Woo
		// conserva esta prueba y la moderación muestra la insignia correcta.
		update_comment_meta(
			$comment_id,
			'verified',
			(int) VerifiedPurchase::order_confirms( $order, $product_id, (string) $order->get_billing_email(), (int) $order->get_customer_id() )
		);

		if ( $attachment_id > 0 ) {
			Media::attach_photo( (int) $comment_id, $attachment_id, $product_id );
		}

		/** Woo escucha comment_post para 'verified'; nuestro motor, para el premio (que espera la APROBACIÓN). */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- no es un hook nuestro: es el de WP core, y el nombre exacto es lo que hace que Woo y nuestro motor escuchen. Prefijarlo los dejaría sordos.
		do_action( 'comment_post', (int) $comment_id, 0, $commentdata );

		$this->points_requested = $requested;
		return true;
	}

	/**
	 * ¿El producto pertenece al pedido?
	 *
	 * @param \WC_Order $order      Pedido.
	 * @param int       $product_id Producto elegido.
	 * @return bool
	 */
	private static function order_has_product( \WC_Order $order, int $product_id ): bool {
		if ( $product_id <= 0 ) {
			return false;
		}

		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! method_exists( $item, 'get_product_id' ) || (int) $item->get_product_id() !== $product_id ) {
				continue;
			}
			if ( self::fue_devuelto( $order, (int) $item_id, $item ) ) {
				continue;
			}
			return true;
		}

		return false;
	}

	/**
	 * ¿Esta línea se devolvió entera?
	 *
	 * Sin esto, un tomo reembolsado sigue contando como comprado: el formulario lo
	 * ofrecía para reseñar y WooCommerce marcaba esa reseña como «compra
	 * verificada». Pasó de verdad — pedido #27782, Frieren 06: la editorial no
	 * tenía el tomo, se devolvió el dinero, y el formulario iba a pedirle al
	 * cliente que opinara de un libro que nunca tuvo en las manos.
	 *
	 * `get_qty_refunded_for_item()` devuelve la cantidad en NEGATIVO (-1 = una
	 * unidad devuelta), de ahí el valor absoluto.
	 *
	 * @since 0.3.6
	 *
	 * @param \WC_Order $order   Pedido.
	 * @param int       $item_id Id de la línea.
	 * @param mixed     $item    La línea.
	 * @return bool
	 */
	private static function fue_devuelto( \WC_Order $order, int $item_id, $item ): bool {
		if ( ! method_exists( $item, 'get_quantity' ) ) {
			return false;
		}

		$compradas  = (int) $item->get_quantity();
		$devueltas  = abs( (int) $order->get_qty_refunded_for_item( $item_id ) );

		return $compradas > 0 && $devueltas >= $compradas;
	}

	/**
	 * ¿Viene la foto en el envío?
	 *
	 * Separado de `handle_upload()` a propósito: esa carga
	 * `wp-admin/includes/media.php` y no se puede ejercer en la suite, mientras
	 * que esta comprobación —la que decide si la reseña se acepta— sí tiene que
	 * poder probarse.
	 *
	 * @param array<string, mixed> $files El `$_FILES` del envío.
	 */
	private static function photo_is_present( array $files ): bool {
		$foto = $files['akb_rf_photo'] ?? null;

		return is_array( $foto ) && is_string( $foto['name'] ?? null ) && '' !== trim( $foto['name'] );
	}

	/**
	 * Sube la foto del formulario (5 MB máx, solo imagen).
	 *
	 * Los `require_once` van guardados por `function_exists`: cargar de nuevo lo
	 * que ya está cargado no aporta nada en producción, y en la suite permite
	 * que los tests que prueban OTRA cosa —la carrera del doble clic, la
	 * devolución del token— sustituyan `media_handle_upload()` para llegar a lo
	 * suyo. Desde que la foto es obligatoria, TODO envío pasa por aquí.
	 *
	 * @param int $product_id Producto para el reparentado.
	 * @return int|\WP_Error ID del adjunto.
	 */
	private function handle_upload( int $product_id ) {
		unset( $product_id ); // Media::attach_photo() enlaza la imagen tras crear la reseña.
		return VoluntaryReviewForm::upload_photo( 'akb_rf_photo' );
	}

	/**
	 * Toma el token para ESTE envío: lo marca usado sólo si seguía libre.
	 *
	 * @param string $token Token.
	 * @return bool True si este envío se quedó con el token.
	 */
	private static function claim_token( string $token ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- tabla propia; el WHERE es el candado.
		$claimed = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- el único interpolado es el nombre de nuestra tabla.
				'UPDATE ' . Schema::review_forms_table() . ' SET used_at = %s WHERE token = %s AND used_at IS NULL',
				gmdate( 'Y-m-d H:i:s' ),
				$token
			)
		);

		return (int) $claimed > 0;
	}

	/**
	 * Borra una foto que se subió para una reseña que al final no nació.
	 *
	 * Se llama sólo en las salidas posteriores a la subida y anteriores a
	 * `Media::attach_photo()`, o sea cuando el adjunto es con certeza huérfano:
	 * todavía no tiene reseña ni `post_parent`. El `true` fuerza el borrado sin
	 * pasar por la papelera — los adjuntos no la usan de todos modos, y el punto
	 * es no dejar el fichero en `uploads/`.
	 *
	 * @param int $attachment_id Adjunto recién creado, o 0 si no hubo foto.
	 * @return void
	 */
	private static function discard_orphan_photo( int $attachment_id ): void {
		if ( $attachment_id > 0 && function_exists( 'wp_delete_attachment' ) ) {
			wp_delete_attachment( $attachment_id, true );
		}
	}

	/**
	 * Devuelve el token al ruedo: sólo cuando la reseña NO llegó a nacer.
	 *
	 * @param string $token Token.
	 * @return void
	 */
	private static function release_token( string $token ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- tabla propia.
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- el único interpolado es el nombre de nuestra tabla.
				'UPDATE ' . Schema::review_forms_table() . ' SET used_at = NULL WHERE token = %s',
				$token
			)
		);
	}

	/**
	 * Núcleo del GET: el formulario (o el aviso de enlace inválido).
	 *
	 * @param string $token  Token del enlace.
	 * @param string $notice Aviso de un POST fallido.
	 * @return string
	 */
	public function render_page( string $token, string $notice = '' ): string {
		$valid = self::validate_token( $token );
		if ( null === $valid ) {
			return $this->invalid_body();
		}

		$order = wc_get_order( $valid['order_id'] );
		if ( ! $order instanceof \WC_Order ) {
			return $this->invalid_body();
		}

		$productos = '';
		$n         = 0;
		foreach ( $order->get_items() as $item ) {
			if ( ! method_exists( $item, 'get_product_id' ) ) {
				continue;
			}
			// Un tomo devuelto no se ofrece para reseñar: el cliente nunca lo tuvo.
			if ( ! VerifiedPurchase::order_confirms( $order, (int) $item->get_product_id(), (string) $order->get_billing_email(), (int) $order->get_customer_id() ) ) {
				continue;
			}
			++$n;
			$productos .= sprintf(
				'<label class="akb-rf-prod"><input type="radio" name="akb_rf_product" value="%d" required%s> <span>%s</span></label>',
				(int) $item->get_product_id(),
				( isset( $this->submitted['product'] ) ? (int) $item->get_product_id() === (int) $this->submitted['product'] : 1 === $n ) ? ' checked' : '',
				esc_html( (string) $item->get_name() )
			);
		}

		// Pedido entero devuelto: no queda nada que reseñar. Sin esto el
		// formulario se pintaba vacío, con un radio `required` que era imposible
		// de marcar — el cliente veía un formulario que no se puede enviar.
		if ( 0 === $n ) {
			return $this->invalid_body();
		}

		$estrellas = '';
		for ( $i = 5; $i >= 1; $i-- ) {
			$estrellas .= sprintf(
				'<input type="radio" id="akb-rf-star%1$d" name="akb_rf_rating" value="%1$d" required%2$s><label for="akb-rf-star%1$d" aria-label="%1$d estrellas">★</label>',
				$i,
				$i === (int) ( $this->submitted['rating'] ?? 0 ) ? ' checked' : ''
			);
		}

		$reward = Options::reviews_lane_on();
		$premio = $reward
			? '<div class="akb-rf-premio"><strong>Reseña con puntos</strong><p>' . esc_html( RewardMessage::text() ) . '</p></div>'
			: '<p>Reseña voluntaria sin puntos. La foto es opcional; adjuntarla no solicita un premio.</p>';
		return sprintf(
			'<h1>Tu reseña del pedido #%d</h1>
			%s
			%s
			<details class="akb-rf-campo"><summary>Todavía no lo recibí</summary><p>Espera a recibir tu compra para contar tu experiencia. Guarda este enlace y vuelve cuando la tengas; este enlace vence el %s (UTC). Abrir este aviso no envía una reseña ni usa tu enlace.</p></details>
			<form method="post" enctype="multipart/form-data">
				%s
				<input type="hidden" name="akb_rf_mode" value="%s">
				<input type="hidden" name="akb_rf_points_requested" value="%d">
				<fieldset class="akb-rf-campo"><legend>¿Qué producto reseñas?</legend>%s</fieldset>
				<fieldset class="akb-rf-campo"><legend>Tu calificación</legend><div class="akb-rf-stars">%s</div></fieldset>
				<label class="akb-rf-campo" for="akb_rf_content">Tu reseña<textarea id="akb_rf_content" name="akb_rf_content" rows="5" required minlength="10" placeholder="¿Qué te pareció? Edición, papel, traducción, envío…">%s</textarea></label>
				<label class="akb-rf-campo" for="akb_rf_photo">Foto de tu compra (%s)<input type="file" id="akb_rf_photo" name="akb_rf_photo" accept="image/jpeg,image/png,image/webp,image/heic"%s aria-describedby="akb-rf-photo-help"><span id="akb-rf-photo-help" class="akb-rf-ayuda">%s Máximo 5 MB; JPG, PNG, WebP o HEIC.</span></label>
				<button type="submit">Enviar mi reseña</button>
				<p class="akb-rf-nota">Tu reseña se publica cuando la revisemos. ¡Gracias por ayudar a otros coleccionistas!</p>
			</form>',
			(int) $order->get_id(),
			$premio,
			'' !== $notice ? sprintf( '<p class="akb-rf-aviso" role="alert">%s</p>', esc_html( $notice ) ) : '',
			esc_html( $valid['expires_at'] ),
			wp_nonce_field( 'akb_review_' . $token, '_akbnonce', true, false ),
			$reward ? 'reward' : 'voluntary',
			(int) $reward,
			$productos,
			$estrellas,
			esc_html( (string) ( $this->submitted['content'] ?? '' ) ),
			$reward ? 'obligatoria para solicitar puntos' : 'opcional',
			$reward ? ' required' : '',
			$reward ? 'Adjunta una foto real de tu compra para solicitar puntos.' : 'Puedes enviarla sin foto. Esta reseña no solicita puntos.'
		);
	}

	/**
	 * Cuerpo del agradecimiento post-envío.
	 *
	 * @return string
	 */
	private function thanks_body(): string {
		return sprintf(
			'<h1>¡Gracias! 🎉</h1>
			<p>Tu reseña quedó en revisión: la publicamos apenas la aprobemos.</p>
			%s
			<p><a class="akb-rf-btn" href="%s">Volver a la tienda</a></p>',
			$this->points_requested
				? '<p>Recompensa pendiente de revisión: solicitaste puntos por esta reseña. ' . esc_html( RewardMessage::text( false ) ) . '</p>'
				: '<p>No solicitaste puntos. Tu reseña se revisará con normalidad.</p>',
			esc_url( home_url( '/' ) )
		);
	}

	/**
	 * Cuerpo del enlace inválido.
	 *
	 * @return string
	 */
	private function invalid_body(): string {
		return sprintf(
			'<h1>Este enlace ya no sirve</h1>
			<p>El enlace de reseña ya se usó o venció. Puedes escribir tu reseña desde la ficha del producto cuando quieras.</p>
			<p><a class="akb-rf-btn" href="%s">Ir a mis pedidos</a></p>',
			esc_url( function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( 'orders' ) : home_url( '/mi-cuenta/' ) )
		);
	}

	/**
	 * Cuerpo de la ruta legacy /cusrev/{formId}.
	 *
	 * @return string
	 */
	private function legacy_body(): string {
		return sprintf(
			'<h1>Cambiamos de sistema de reseñas</h1>
			<p>Este enlace venía de nuestro sistema anterior y ya no funciona, pero tu opinión nos sigue importando: puedes reseñar desde la ficha del producto o desde tus pedidos.</p>
			<p><a class="akb-rf-btn" href="%s">Ir a mis pedidos</a></p>',
			esc_url( function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( 'orders' ) : home_url( '/mi-cuenta/' ) )
		);
	}

	/**
	 * Cáscara HTML propia: liviana, brand, mobile-first, cero JS.
	 *
	 * Pública desde la 0.4.9 porque la página de preferencias de correo
	 * (`InviteOptOut`) es la misma pantalla para el cliente: dos carcasas
	 * distintas para dos enlaces del mismo correo se ven como dos sitios.
	 *
	 * @param string $body  Cuerpo ya escapado por partes.
	 * @param string $title Título de la pestaña.
	 * @return string
	 */
	public static function shell( string $body, string $title = 'Tu reseña — Akibara' ): string {
		return '<!doctype html><html lang="es-CL"><head><meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width, initial-scale=1">'
			. '<meta name="robots" content="noindex,nofollow">'
			. '<title>' . esc_html( $title ) . '</title><style>'
			. ':root{color-scheme:dark}'
			. 'body{margin:0;background:#0d0d0d;color:#f5f5f5;font:16px/1.6 system-ui,-apple-system,"Segoe UI",sans-serif;-webkit-font-smoothing:antialiased}'
			. '.akb-rf{max-width:560px;margin:0 auto;padding:24px 16px 64px}'
			. '.akb-rf-logo{display:block;margin:0 0 24px;font-weight:800;font-size:22px;color:#E60012;text-decoration:none}'
			. 'h1{font-size:26px;margin:0 0 16px}'
			. '.akb-rf-campo{display:block;border:1px solid #2b2b2b;border-radius:12px;padding:16px;margin:0 0 16px;background:#171717}'
			. 'legend{padding:0 6px;font-weight:700}'
			. 'textarea,input[type=file]{width:100%;box-sizing:border-box;margin-top:8px;background:#0d0d0d;color:#f5f5f5;border:1px solid #2b2b2b;border-radius:8px;padding:12px;font:inherit}'
			. '.akb-rf-prod{display:flex;gap:10px;align-items:center;min-height:44px}'
			. '.akb-rf-stars{display:flex;flex-direction:row-reverse;justify-content:flex-end;gap:4px}'
			. '.akb-rf-stars input{position:absolute;opacity:0;width:1px;height:1px}'
			. '.akb-rf-stars label{font-size:34px;line-height:44px;min-width:44px;text-align:center;color:#3a3a3a;cursor:pointer}'
			. '.akb-rf-stars input:checked ~ label,.akb-rf-stars label:hover,.akb-rf-stars label:hover ~ label{color:#FFD700}'
			. '.akb-rf-stars input:focus-visible + label{outline:2px solid #fff;outline-offset:2px;border-radius:6px}'
			. 'button,.akb-rf-btn{display:inline-flex;align-items:center;justify-content:center;min-height:48px;padding:12px 28px;border:0;border-radius:8px;background:#E60012;color:#fff;font-weight:700;font-size:16px;cursor:pointer;text-decoration:none}'
			. '.akb-rf-premio{border-left:3px solid #E60012;background:rgba(230,0,18,.08);padding:10px 12px;border-radius:0 8px 8px 0}'
			. '.akb-rf-aviso{border-left:3px solid #E60012;background:rgba(230,0,18,.1);padding:10px 12px;border-radius:0 8px 8px 0}'
			. '.akb-rf-nota{font-size:13px;opacity:.7}'
			. '</style></head><body><main class="akb-rf">'
			. '<a class="akb-rf-logo" href="' . esc_url( home_url( '/' ) ) . '">AKIBARA</a>'
			. $body
			. '</main></body></html>';
	}
}
