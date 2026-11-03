<?php
/**
 * Emails — UN correo de puntos por pedido, sellado solo si el transporte
 * confirmó el envío.
 *
 * Contrato de envío:
 *   - Sale a prioridad 100, después del bono (prioridad 10), con el total de
 *     puntos del pedido y el bono explicado en un único aviso.
 *   - Sólo sella el envío cuando el transporte devuelve true. Un fallo deja
 *     nota en el pedido y permite reintentar; al agotar los intentos emite alerta.
 *   - Sin `<style>`/`<title>` como texto: el cuerpo va con estilos inline y
 *     se envuelve con la plantilla de WooCommerce (que ya hereda la cabecera
 *     de marca del tema).
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Points;

use Akibara\Rewards\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.1.0
 */
final class Emails {

	public const META_EMAIL_SENT = '_akb_rewards_email_sent';

	/** Reintento del correo de puntos de un pedido: (pedido, intento). */
	public const HOOK_RETRY_ORDER = 'akb_rewards_order_email_retry';

	/** Reintento del correo de reseña premiada: (reseña, cliente, puntos, intento). */
	public const HOOK_RETRY_REVIEW = 'akb_rewards_review_email_retry';

	/** Reintento del correo al padrino: (padrino, invitado, puntos, pedido, intento). */
	public const HOOK_RETRY_REFERRAL = 'akb_rewards_referral_email_retry';

	/** Usermeta del INVITADO: pedido cuyo premio ya se le avisó al padrino. */
	public const META_REFERRAL_EMAIL_SENT = '_akb_rewards_referral_email_sent';

	/** Intentos de un correo al cliente antes de avisar al dueño. */
	private const MAX_ATTEMPTS = 3;

	/** Espera entre intentos: el transporte caído suele volver en horas. */
	private const RETRY_DELAY = 6 * HOUR_IN_SECONDS;

	/**
	 * URL ABSOLUTA del panel de puntos del cliente.
	 *
	 * Absoluta y no relativa porque en un correo no hay documento base contra
	 * el que resolver una ruta.
	 *
	 * @return string
	 */
	public static function rewards_url(): string {
		return (string) ( function_exists( 'wc_get_account_endpoint_url' )
			? wc_get_account_endpoint_url( 'rewards' )
			: home_url( '/mi-cuenta/rewards/' ) );
	}

	/**
	 * Botón de marca para los correos del carril de puntos.
	 *
	 * Diseñado como botón accesible con área táctil mínima de 44px.
	 *
	 * Dos detalles que no son cosméticos:
	 *
	 *   - La clase `akb-email-btn` es OBLIGATORIA. Estos correos pasan por
	 *     `WC_Emails::wrap_message()`, que aplica `wp_kses_post()` y DESNUDA el
	 *     `-webkit-text-fill-color` inline (no está en la lista blanca de
	 *     `safecss_filter_attr()`). Sin la clase, la hoja del wrapper —que
	 *     rellena de rojo todo `<a>` del cuerpo— dejaba el texto rojo sobre el
	 *     botón rojo: 1,23:1 en Apple Mail, la app de Gmail y Outlook móvil.
	 *   - 15px de padding vertical + 15px de línea = 45px de alto real, por
	 *     encima del mínimo táctil de 44px.
	 *
	 * @param string $label Texto del botón.
	 * @param string $url   Destino absoluto.
	 * @return string HTML del botón, '' si no hay destino.
	 */
	public static function cta( string $label, string $url ): string {
		if ( '' === trim( $url ) ) {
			return '';
		}

		return sprintf(
			'<p style="margin:24px 0 0"><a class="akb-email-btn" href="%s" style="display:inline-block;background-color:#E60012;background-image:linear-gradient(#E60012,#E60012);color:#ffffff;-webkit-text-fill-color:#ffffff;padding:15px 28px;font-weight:bold;font-size:15px;line-height:1;text-decoration:none;border-radius:0;text-transform:uppercase;letter-spacing:0.5px;">%s</a></p>',
			esc_url( $url ),
			esc_html( $label )
		);
	}

	/**
	 * Contexto UNIFORME del filtro del cuerpo.
	 *
	 * El segundo argumento del filtro llegaba con cuatro tipos distintos según
	 * el correo (WC_Order, int, WP_Comment) mientras el docblock documentaba
	 * uno solo: un consumidor tenía que adivinar con qué le tocaba. Ahora
	 * siempre es este array, con las mismas claves.
	 *
	 * @param string           $email   Identificador del correo.
	 * @param int              $user_id Cliente destinatario (0 si no aplica).
	 * @param \WC_Order|null    $order   Pedido, si el correo cuelga de uno.
	 * @param \WP_Comment|null  $comment Reseña, si el correo cuelga de una.
	 * @return array{email:string,user_id:int,order:\WC_Order|null,comment:\WP_Comment|null}
	 */
	public static function context( string $email, int $user_id, $order = null, $comment = null ): array {
		return array(
			'email'   => $email,
			'user_id' => $user_id,
			'order'   => $order instanceof \WC_Order ? $order : null,
			'comment' => $comment instanceof \WP_Comment ? $comment : null,
		);
	}

	/**
	 * Cuerpo plano del correo en vuelo, o null si no hay ninguno en vuelo.
	 *
	 * Vive en una estática porque el punto donde PHPMailer acepta la parte
	 * alternativa (`phpmailer_init`) está fuera de la pila del emisor.
	 */
	private static ?string $alt_body = null;

	/**
	 * Envía un correo de puntos con sus dos partes: HTML de marca y texto plano (`AltBody`).
	 *
	 * Garantiza compatibilidad con clientes de correo en modo texto y optimiza entregabilidad.
	 *
	 * Estos correos replican la mecánica multipart de WooCommerce vía `phpmailer_init`.
	 * La parte plana se deriva automáticamente del HTML (`html_to_plain()`) para evitar discrepancias.
	 * es el defecto clásico del multipart escrito por duplicado.
	 *
	 * @param string $to      Destinatario.
	 * @param string $subject Asunto.
	 * @param string $heading Encabezado de marca.
	 * @param string $body    Cuerpo HTML sin envolver.
	 * @param array{email:string,user_id:int,order:\WC_Order|null,comment:\WP_Comment|null} $context Contexto del filtro.
	 * @param array<int,string>                                                              $extra   Cabeceras adicionales (p. ej. List-Unsubscribe).
	 * @return bool Si el transporte confirmó.
	 */
	public static function send_branded( string $to, string $subject, string $heading, string $body, array $context, array $extra = array() ): bool {
		$mailer  = WC()->mailer();
		$wrapped = $mailer->wrap_message( $heading, $body );

		/**
		 * Cuerpo final de CUALQUIER correo de puntos, ya envuelto con la
		 * plantilla WC. Lo disparan los seis emisores del programa; el
		 * contexto dice cuál y con qué.
		 *
		 * @param string $wrapped Cuerpo HTML completo.
		 * @param array{email:string,user_id:int,order:\WC_Order|null,comment:\WP_Comment|null} $context
		 *        `email` es el identificador del correo: 'order', 'expiry',
		 *        'birthday', 'review', 'review_invite', 'referral',
		 *        'admin_adjust' o 'admin_alert'.
		 */
		$wrapped = (string) apply_filters( 'akb_rewards_points_email_body', $wrapped, $context );

		self::$alt_body = self::html_to_plain( $wrapped );
		add_action( 'phpmailer_init', array( self::class, 'attach_plain_part' ) );

		try {
			// Las cabeceras extra van DESPUÉS del content-type y cada una con su
			// propio CRLF: WC las concatena tal cual en el header del mensaje.
			$headers = "Content-Type: multipart/alternative\r\n";
			foreach ( $extra as $linea ) {
				$linea = trim( (string) $linea );
				if ( '' !== $linea ) {
					$headers .= $linea . "\r\n";
				}
			}

			// La identidad WC_Email identifica el aviso en el registro de correo.
			// La parte plana depende del hook propio: send() no llama get_content(),
			// por lo que WC_Email::handle_multipart() no activa su contenido alternativo.
			$sent = (bool) BrandedEmail::for( (string) ( $context['email'] ?? 'puntos' ), (string) self::$alt_body )
				->send( $to, $subject, $wrapped, $headers, array() );
		} finally {
			// En `finally`: si el transporte lanza, el hook NO puede quedarse
			// vivo contaminando el siguiente correo del sitio.
			remove_action( 'phpmailer_init', array( self::class, 'attach_plain_part' ) );
			self::$alt_body = null;
		}

		return $sent;
	}

	/**
	 * Rellena la parte plana del correo en vuelo.
	 *
	 * @internal Callback de `phpmailer_init`.
	 *
	 * @param object $phpmailer Instancia de PHPMailer.
	 * @return void
	 */
	public static function attach_plain_part( $phpmailer ): void {
		if ( null === self::$alt_body || ! is_object( $phpmailer ) ) {
			return;
		}

		$phpmailer->AltBody = self::$alt_body; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- propiedad de PHPMailer.
	}

	/**
	 * Texto plano legible a partir del HTML del correo.
	 *
	 * Los ENLACES se conservan con su URL: un `wp_strip_all_tags()` a secas
	 * dejaría «Canjear mis puntos» sin decir a dónde, que es justo lo que hace
	 * inútil una parte plana.
	 *
	 * @param string $html Cuerpo HTML ya envuelto.
	 * @return string
	 */
	public static function html_to_plain( string $html ): string {
		// Fuera lo que no es contenido: la hoja y el preheader invisible.
		$texto = (string) preg_replace( '#<(style|script|title)\b[^>]*>.*?</\1>#is', '', $html );

		// Cada enlace se lleva su destino al lado.
		$texto = (string) preg_replace_callback(
			'#<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is',
			static function ( array $m ): string {
				$etiqueta = trim( (string) wp_strip_all_tags( $m[2] ) );
				$url      = trim( (string) $m[1] );
				if ( '' === $etiqueta || 0 === strpos( $url, 'mailto:' ) ) {
					return $url;
				}
				return $etiqueta . ': ' . $url;
			},
			$texto
		);

		$texto = (string) preg_replace( '#<br\s*/?>#i', "\n", $texto );
		$texto = (string) preg_replace( '#</(p|div|tr|h[1-6]|li)>#i', "\n", $texto );
		$texto = wp_strip_all_tags( $texto );
		$texto = html_entity_decode( $texto, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// El preheader de la cabecera rellena su línea con caracteres
		// INVISIBLES (combining grapheme joiner + zero-width non-joiner) para
		// alargar la vista previa de la bandeja. En HTML no se ven; en la parte
		// plana salían como basura en la primera línea.
		$texto = (string) preg_replace( '/[\x{034F}\x{200B}-\x{200D}\x{FEFF}\x{00AD}]/u', '', $texto );
		$texto = str_replace( "\xC2\xA0", ' ', $texto );

		// Espacios de indentación de las plantillas + líneas en blanco de más.
		$texto = (string) preg_replace( '/[ \t]+/', ' ', $texto );
		$texto = (string) preg_replace( '/ ?\n ?/', "\n", $texto );
		$texto = (string) preg_replace( '/\n{3,}/', "\n\n", $texto );

		return trim( $texto );
	}

	/**
	 * Año del último saludo de cumpleaños ENVIADO (se sella sólo con
	 * transporte confirmado). Birthday lo lee para volver a pedir el saludo
	 * cuando el regalo ya se pagó pero el correo no salió.
	 */
	public const META_BIRTHDAY_GREETED_YEAR = '_akb_rewards_birthday_email_year';

	/**
	 * Cuelga los hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'akb_rewards_after_order_award', array( $this, 'send_order_email' ), 100, 3 );
		add_action( 'akb_rewards_award_already_done', array( $this, 'maybe_resend' ), 10, 1 );
		add_action( 'akb_rewards_review_points_paid', array( $this, 'send_review_email' ), 100, 3 );
		add_action( 'akb_rewards_birthday_paid', array( $this, 'send_birthday_email' ), 100, 3 );
		add_action( 'akb_rewards_expiry_warning', array( $this, 'send_expiry_warning' ), 100, 3 );
		add_action( 'akb_rewards_referral_paid', array( $this, 'send_referral_email' ), 100, 4 );
		add_action( self::HOOK_RETRY_ORDER, array( $this, 'retry_order_email' ), 10, 2 );
		add_action( self::HOOK_RETRY_REVIEW, array( $this, 'retry_review_email' ), 10, 4 );
		add_action( self::HOOK_RETRY_REFERRAL, array( $this, 'retry_referral_email' ), 10, 5 );
	}

	/**
	 * Aviso de piedad: «tienes N puntos por vencer — úsalos». Brandeado, con
	 * CTA de canje. El marcador anti-spam (un aviso/mes) se sella AQUÍ y solo
	 * con transporte confirmado — si el SMTP cae, mañana se reintenta.
	 *
	 * @param int $user_id   Usuario.
	 * @param int $soon      Puntos que vencerán.
	 * @param int $warn_days Ventana del aviso.
	 * @return void
	 */
	public function send_expiry_warning( $user_id, $soon, $warn_days ): void {
		// Revisa el sello del mes para evitar duplicidad de avisos de vencimiento.
		$mes = ( new \DateTimeImmutable( 'now', wp_timezone() ) )->format( 'Y-m' );
		if ( (string) get_user_meta( (int) $user_id, Expiry::META_WARNED, true ) === $mes ) {
			return;
		}

		$user = get_user_by( 'id', (int) $user_id );
		if ( ! $user instanceof \WP_User || '' === (string) $user->user_email ) {
			return;
		}

		// Destino: el panel de puntos, NO el checkout. El checkout con el
		// carrito vacío es un callejón sin salida —WooCommerce devuelve al
		// carrito con «tu carrito está vacío»— mientras que
		// `/mi-cuenta/rewards/` muestra el saldo y arma la intención de canje
		// para la compra siguiente, que es el paso que de verdad salva los
		// puntos.
		$body = sprintf(
			'<p style="margin:0 0 12px">Tienes <strong>%s Puntos Akibara</strong> que vencerán dentro de los próximos %d días.</p>
			<p style="margin:0 0 12px">Cada punto vale %s de descuento: úsalos en tu próximo tomo antes de que se pierdan.%s</p>%s',
			esc_html( number_format_i18n( (int) $soon ) ),
			(int) $warn_days,
			esc_html( Options::redeem_label() ),
			esc_html( self::redeem_hint() ),
			self::cta( 'Canjear mis puntos', self::rewards_url() )
		);

		$sent = self::send_branded(
			(string) $user->user_email,
			sprintf( '[Akibara] %s Puntos Akibara por vencer — úsalos antes', number_format_i18n( (int) $soon ) ),
			'Tus puntos están por vencer',
			$body,
			self::context( 'expiry', (int) $user_id )
		);

		if ( $sent ) {
			// Un aviso al mes, sellado SOLO con transporte confirmado: si el
			// SMTP está caído, la corrida de mañana reintenta.
			update_user_meta(
				(int) $user_id,
				Expiry::META_WARNED,
				( new \DateTimeImmutable( 'now', wp_timezone() ) )->format( 'Y-m' )
			);
		}
	}

	/**
	 * Correo de felicitación y regalo de cumpleaños con enlace a Mi cuenta.
	 *
	 * @param int $user_id Usuario.
	 * @param int $points  Puntos regalados (el monto configurado).
	 * @param int $year    Año del regalo.
	 * @return void
	 */
	public function send_birthday_email( $user_id, $points, $year ): void {
		$user = get_user_by( 'id', (int) $user_id );
		if ( ! $user instanceof \WP_User || '' === (string) $user->user_email ) {
			return;
		}

		if ( (int) get_user_meta( (int) $user_id, self::META_BIRTHDAY_GREETED_YEAR, true ) >= (int) $year ) {
			return; // Ya saludado este año.
		}

		$balance = Ledger::balance( (int) $user_id );

		$body = sprintf(
			'<p style="margin:0 0 12px">¡Feliz cumpleaños! 🎂 Te regalamos <strong>%s Puntos Akibara</strong> por tu día.</p>
			<p style="margin:0 0 12px">Ya están en tu cuenta: tu saldo es de <strong>%s puntos</strong> y cada punto vale %s de descuento.%s</p>%s',
			esc_html( number_format_i18n( (int) $points ) ),
			esc_html( number_format_i18n( $balance ) ),
			esc_html( Options::redeem_label() ),
			esc_html( self::redeem_hint() ),
			self::cta( 'Canjear mis puntos', self::rewards_url() )
		);

		$sent = self::send_branded(
			(string) $user->user_email,
			sprintf( '[Akibara] 🎂 %s Puntos Akibara de regalo por tu cumpleaños', number_format_i18n( (int) $points ) ),
			'¡Feliz cumpleaños de parte de Akibara!',
			$body,
			self::context( 'birthday', (int) $user_id )
		);

		if ( $sent ) {
			update_user_meta( (int) $user_id, self::META_BIRTHDAY_GREETED_YEAR, (int) $year );
		}
	}

	/**
	 * Correo brandeado del premio de reseña: «tus %d puntos por la reseña».
	 * Sellado por comment-meta solo con envío confirmado (misma disciplina
	 * que el correo de pedido); el banner de marca llega por el filtro
	 * compartido akibara_email_logo_banner_url del ecosistema.
	 *
	 * @param \WP_Comment $comment Reseña premiada.
	 * @param int         $user_id Cliente.
	 * @param int         $points  Puntos acreditados.
	 * @return void
	 */
	public function send_review_email( $comment, $user_id, $points, $attempt = 1 ): void {
		if ( ! $comment instanceof \WP_Comment ) {
			return;
		}

		$comment_id = (int) $comment->comment_ID;
		if ( '' !== (string) get_comment_meta( $comment_id, '_akb_rewards_review_email_sent', true ) ) {
			return;
		}

		$user = get_user_by( 'id', (int) $user_id );
		$to   = $user instanceof \WP_User ? (string) $user->user_email : (string) $comment->comment_author_email;
		if ( '' === $to ) {
			return;
		}

		$balance = Ledger::balance( (int) $user_id );
		$titulo  = get_the_title( (int) $comment->comment_post_ID );

		// Este era el único correo del programa que llegaba SIN un solo enlace:
		// avisaba del premio y dejaba al cliente sin camino a su saldo.
		$body = sprintf(
			'<p style="margin:0 0 12px">¡Tu reseña de <strong>%s</strong> fue aprobada! Sumaste <strong>%s Puntos Akibara</strong> por compartirla con foto, equivalentes a <strong>%s CLP de descuento</strong>.</p>
			<p style="margin:0 0 12px">Tu saldo actual es de <strong>%s puntos</strong> — cada punto vale %s en tu próxima compra.%s</p>
			<p style="margin:0"><span style="color:#CC0010;font-weight:bold">Gracias por ayudar a otros coleccionistas a decidir.</span></p>%s',
			esc_html( (string) $titulo ),
			esc_html( number_format_i18n( (int) $points ) ),
			esc_html( Options::points_label( (int) $points ) ),
			esc_html( number_format_i18n( $balance ) ),
			esc_html( Options::redeem_label() ),
			esc_html( self::redeem_hint() ),
			self::cta( 'Ver mis puntos', self::rewards_url() )
		);

		$sent = self::send_branded(
			$to,
			sprintf( '[Akibara] Tu reseña sumó %s Puntos Akibara', number_format_i18n( (int) $points ) ),
			'Tu reseña sumó puntos',
			$body,
			self::context( 'review', (int) $user_id, null, $comment )
		);

		if ( $sent ) {
			update_comment_meta( $comment_id, '_akb_rewards_review_email_sent', time() );
			return;
		}

		// El correo se reintenta por separado del premio, que sólo se acredita una vez.
		self::retry_later( self::HOOK_RETRY_REVIEW, array( $comment_id, (int) $user_id, (int) $points ), (int) $attempt );
	}

	/**
	 * Reintento agendado del correo de reseña premiada.
	 *
	 * @param int $comment_id Reseña.
	 * @param int $user_id    Cliente.
	 * @param int $points     Puntos acreditados.
	 * @param int $attempt    Intento que corre.
	 * @return void
	 */
	public function retry_review_email( $comment_id, $user_id, $points, $attempt ): void {
		$comment = get_comment( (int) $comment_id );
		if ( $comment instanceof \WP_Comment ) {
			$this->send_review_email( $comment, (int) $user_id, (int) $points, (int) $attempt );
		}
	}

	/**
	 * Correo al padrino: su invitación le hizo ganar puntos.
	 *
	 * Comunica al usuario que su recomendación se concretó exitosamente, preservando
	 * la privacidad del invitado sin exponer detalles de su compra.
	 *
	 * Valida que el premio continúe vigente en el ledger antes del envío.
	 *
	 * @param int           $referrer Padrino.
	 * @param int           $invitee  Invitado.
	 * @param int           $points   Puntos pagados.
	 * @param \WC_Order|int $order    Pedido que pagó el premio (su id, en el reintento).
	 * @param int           $attempt  Intento que corre.
	 * @return void
	 */
	public function send_referral_email( $referrer, $invitee, $points, $order = 0, $attempt = 1 ): void {
		$referrer = (int) $referrer;
		$invitee  = (int) $invitee;
		$order_id = $order instanceof \WC_Order ? (int) $order->get_id() : (int) $order;

		if ( $order_id <= 0 || (int) get_user_meta( $invitee, Referrals::META_REFERRAL_ORDER, true ) !== $order_id ) {
			return; // El premio se revirtió (o no hay pedido): nada que avisar.
		}

		// Valida en el ledger que el crédito del referido continúe vigente antes de enviar.
		if ( Ledger::sum_movements( 'user', $invitee, array( 'referral', 'referral_reversal' ) ) < (int) $points ) {
			return;
		}

		if ( (int) get_user_meta( $invitee, self::META_REFERRAL_EMAIL_SENT, true ) === $order_id ) {
			return;
		}

		$user = get_user_by( 'id', $referrer );
		if ( ! $user instanceof \WP_User || '' === (string) $user->user_email ) {
			return;
		}

		$body = sprintf(
			'<p style="margin:0 0 12px">¡Buenas noticias! Tu amigo completó su primera compra y acreditamos <strong>%s Puntos Akibara</strong> en tu cuenta.</p>
			<p style="margin:0 0 12px">Tu saldo actual es de <strong>%s puntos</strong> — cada punto equivale a %s para descontar en tus próximos mangas y cómics.%s</p>
			<p style="margin:0;color:#888;font-size:13px">¡Muchas gracias por recomendar Akibara a tus amigos!</p>%s',
			esc_html( number_format_i18n( (int) $points ) ),
			esc_html( number_format_i18n( Ledger::balance( $referrer ) ) ),
			esc_html( Options::redeem_label() ),
			esc_html( self::redeem_hint() ),
			self::cta( 'Ver mis puntos', self::rewards_url() )
		);

		$sent = self::send_branded(
			(string) $user->user_email,
			sprintf( '[Akibara] ¡Tu amigo completó su primera compra! Acreditamos %s Puntos Akibara en tu saldo', number_format_i18n( (int) $points ) ),
			'¡Tu amigo completó su primera compra!',
			$body,
			self::context( 'referral', $referrer )
		);

		if ( $sent ) {
			update_user_meta( $invitee, self::META_REFERRAL_EMAIL_SENT, $order_id );
			return;
		}

		self::retry_later( self::HOOK_RETRY_REFERRAL, array( $referrer, $invitee, (int) $points, $order_id ), (int) $attempt );
	}

	/**
	 * Reintento agendado del correo al padrino.
	 *
	 * @param int $referrer Padrino.
	 * @param int $invitee  Invitado.
	 * @param int $points   Puntos pagados.
	 * @param int $order_id Pedido que pagó el premio.
	 * @param int $attempt  Intento que corre.
	 * @return void
	 */
	public function retry_referral_email( $referrer, $invitee, $points, $order_id, $attempt ): void {
		$this->send_referral_email( (int) $referrer, (int) $invitee, (int) $points, (int) $order_id, (int) $attempt );
	}

	/**
	 * Reintento agendado del correo de puntos de un pedido.
	 *
	 * @param int $order_id Pedido.
	 * @param int $attempt  Intento que corre.
	 * @return void
	 */
	public function retry_order_email( $order_id, $attempt ): void {
		$order = wc_get_order( (int) $order_id );
		if ( $order instanceof \WC_Order ) {
			$this->maybe_resend( $order, (int) $attempt );
		}
	}

	/**
	 * Agenda el siguiente intento de un correo al cliente, o avisa al dueño si
	 * ya no quedan.
	 *
	 * @param string           $hook    Acción del reintento.
	 * @param array<int,mixed> $args    Argumentos sin el intento.
	 * @param int              $attempt Intento que acaba de fallar.
	 * @return void
	 */
	private static function retry_later( string $hook, array $args, int $attempt ): void {
		if ( $attempt >= self::MAX_ATTEMPTS ) {
			/**
			 * Un correo al cliente no salió tras todos sus intentos. Las
			 * alertas al dueño escuchan acá.
			 *
			 * @param string           $hook Reintento agotado.
			 * @param array<int,mixed> $args Sus argumentos, con el último intento al final.
			 */
			do_action( 'akb_rewards_email_gave_up', $hook, array_merge( $args, array( $attempt ) ) );
			return;
		}

		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + self::RETRY_DELAY, $hook, array_merge( $args, array( $attempt + 1 ) ) );
		}
	}

	/**
	 * Reintento: el pedido ya estaba acreditado pero el correo pudo haber
	 * fallado. Si no está sellado, se reenvía.
	 *
	 * @param \WC_Order $order Pedido ya acreditado.
	 * @return void
	 */
	public function maybe_resend( $order, $attempt = 1 ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		if ( '' !== (string) $order->get_meta( self::META_EMAIL_SENT ) ) {
			return;
		}

		$user_id = (int) $order->get_customer_id();
		$earned  = (int) $order->get_meta( Accrual::META_EARNED );

		if ( $user_id > 0 && $earned > 0 ) {
			$bonus = (int) $order->get_meta( Accrual::META_WELCOME_BONUS );
			$this->deliver( $order, $user_id, $earned - $bonus, $bonus, (int) $attempt );
		}
	}

	/**
	 * Correo consolidado tras la acreditación (compra + bono si lo hubo).
	 *
	 * @param \WC_Order $order   Pedido acreditado.
	 * @param int       $user_id Cliente.
	 * @param int       $points  Puntos de la compra (sin bono).
	 * @return void
	 */
	public function send_order_email( $order, $user_id, $points ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		if ( '' !== (string) $order->get_meta( self::META_EMAIL_SENT ) ) {
			return;
		}

		$bonus = (int) $order->get_meta( Accrual::META_WELCOME_BONUS );

		// El aviso de acreditación se envía si hubo puntos por compra o bono de bienvenida.
		if ( (int) $points <= 0 && $bonus <= 0 ) {
			return;
		}

		$this->deliver( $order, (int) $user_id, (int) $points, $bonus );
	}

	/**
	 * La condición del canje, dicha donde se empuja a canjear.
	 *
	 * Los correos son la superficie que MANDA a actuar («úsalos antes de que se
	 * pierdan», «canjéalos hoy mismo»): callar el carrito mínimo manda al
	 * cliente a una pantalla donde no puede hacer lo que el correo le pidió.
	 *
	 * @return string Frase lista para concatenar, o '' si no hay mínimo.
	 */
	private static function redeem_hint(): string {
		$minimo = Options::redeem_min_cart();

		return $minimo > 0
			? sprintf( ' Se canjean en compras desde $%s en productos.', number_format_i18n( $minimo ) )
			: '';
	}

	/**
	 * Construye y envía el correo; sella la meta solo si el transporte
	 * confirmó.
	 *
	 * @param \WC_Order $order   Pedido.
	 * @param int       $user_id Cliente.
	 * @param int       $points  Puntos de la compra.
	 * @param int       $bonus   Bono de bienvenida incluido (0 si no hubo).
	 * @return void
	 */
	private function deliver( \WC_Order $order, int $user_id, int $points, int $bonus, int $attempt = 1 ): void {
		// El saldo pertenece a la cuenta: se envía a su correo, con facturación
		// como respaldo únicamente si la cuenta no tiene dirección.
		$cuenta = get_user_by( 'id', $user_id );
		$to     = $cuenta instanceof \WP_User ? (string) $cuenta->user_email : '';
		$propio = '' !== $to;

		if ( '' === $to ) {
			$to = (string) $order->get_billing_email();
		}

		if ( '' === $to ) {
			return;
		}

		$total   = $points + $bonus;
		$balance = Ledger::balance( $user_id );

		$subject = $points > 0
			? sprintf( '[Akibara] Sumaste %s Puntos Akibara con tu pedido', number_format_i18n( $total ) )
			: sprintf( '[Akibara] Te regalamos %s Puntos Akibara de bienvenida', number_format_i18n( $total ) );
		$heading = 'Tus Puntos Akibara';

		// Utiliza el nombre de facturación como respaldo si el usuario no tiene first_name asignado.
		$name     = $propio ? trim( (string) get_user_meta( $user_id, 'first_name', true ) ) : '';
		$es_misma = ! $propio || strtolower( $to ) === strtolower( trim( (string) $order->get_billing_email() ) );
		if ( '' === $name && $es_misma ) {
			$name = trim( (string) $order->get_billing_first_name() );
		}
		$hola = '' !== $name ? sprintf( '¡Hola, %s!', esc_html( $name ) ) : '¡Hola!';

		$lines = array();
		if ( $points > 0 ) {
			$lines[] = sprintf(
				'<p style="margin:0 0 12px;">%s Tu pedido <strong>#%d</strong> ya está confirmado y con él sumaste <strong>%s Puntos Akibara</strong>.</p>',
				$hola,
				(int) $order->get_id(),
				esc_html( number_format_i18n( $points ) )
			);

			if ( $bonus > 0 ) {
				$lines[] = sprintf(
					'<p style="margin:0 0 12px;">Además, por ser tu primera compra te regalamos un <strong>bono de bienvenida de %s puntos</strong>.</p>',
					esc_html( number_format_i18n( $bonus ) )
				);
			}
		} else {
			// Sólo el bono: nunca «sumaste 0 Puntos Akibara».
			$lines[] = sprintf(
				'<p style="margin:0 0 12px;">%s Tu pedido <strong>#%d</strong> ya está confirmado y, por ser tu primera compra, te regalamos un <strong>bono de bienvenida de %s puntos</strong>.</p>',
				$hola,
				(int) $order->get_id(),
				esc_html( number_format_i18n( $bonus ) )
			);
		}

		$lines[] = sprintf(
			'<p style="margin:0 0 12px;">Tu saldo actual es de <strong>%s puntos</strong> — cada punto vale %s.%s</p>',
			esc_html( number_format_i18n( $balance ) ),
			esc_html( Options::redeem_label() ),
			esc_html( self::redeem_hint() )
		);

		// El CTA manda: el botón lleva al saldo. La página del programa queda
		// como enlace secundario —es información, no acción— y sólo si existe.
		$lines[] = self::cta( 'Ver mis puntos', self::rewards_url() );

		$programa_url = ProgramPage::url();
		if ( '' !== $programa_url ) {
			$lines[] = sprintf(
				'<p style="margin:12px 0 0;font-size:13px;"><a href="%s" style="color:#CC0010;">Conoce cómo funciona el programa &rarr;</a></p>',
				esc_url( $programa_url )
			);
		}

		$body = implode( '', $lines );

		$sent = self::send_branded( $to, $subject, $heading, $body, self::context( 'order', $user_id, $order ) );

		if ( $sent ) {
			$order->update_meta_data( self::META_EMAIL_SENT, time() );
			$order->save_meta_data();
			return;
		}

		$order->add_order_note(
			$attempt < self::MAX_ATTEMPTS
				? sprintf( 'Puntos Akibara: el correo de puntos NO pudo enviarse (intento %d de %d); se reintentará solo en 6 horas.', $attempt, self::MAX_ATTEMPTS )
				: sprintf( 'Puntos Akibara: el correo de puntos NO pudo enviarse tras %d intentos.', self::MAX_ATTEMPTS )
		);

		self::retry_later( self::HOOK_RETRY_ORDER, array( (int) $order->get_id() ), $attempt );
	}
}
