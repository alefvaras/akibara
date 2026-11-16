<?php
/**
 * AnnouncementBar — carril manual de anuncios promocionales.
 *
 * Inyecta la barra inmediatamente después de <body> vía wp_body_open.
 * Los mensajes y la configuración se gestionan desde Akibara → Barra de anuncios
 * (panel propio del plugin, no una pestaña nativa de WooCommerce → Ajustes).
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\AnnouncementBar;

use Akibara\Business\Modules\DiscountCopy\DiscountCopy;
use Akibara\Business\Modules\ShippingCopy\ShippingCopy;
use Akibara\Business\Utilities\OptionsFromDatabase;
use Akibara\Business\Utilities\PluginAssetVersion;
use Akibara\Business\Utilities\StoreTime;

defined( 'ABSPATH' ) || exit;

/**
 * @api
 * @psalm-suppress HookInvalidArgs
 * @psalm-suppress HookNotFound
 * @psalm-suppress RedundantCast
 */
// @phpstan-ignore complexity.classLike
final class AnnouncementBar {

	private const OPTION_ENABLED  = 'akibara_announcement_enabled';
	private const OPTION_MESSAGES = 'akibara_announcement_messages';

	/** Pestaña del panel (`Plugin::register_admin_panel()`). */
	private const PANEL_TAB = 'announcement';

	/**
	 * Caracteres por línea a partir de los cuales el mensaje se corta con «…»
	 * en un móvil de 360px. Lo lee el contador de admin-panel.js; la ayuda del
	 * campo lo repite.
	 *
	 * La cinta admite dos líneas en móvil. Para texto renderizado en 360px caben
	 * aproximadamente 80-90 caracteres (a 320px, de 71 a 78).
	 * El valor 80 deja margen en los dos motores a 360px, no a 320: ahí un mensaje que
	 * el contador acepta puede cortarse si incluye emojis o glifos anchos.
	 * `announcement-bar.css`).
	 */
	public const LINE_MAX = '80';

	/**
	 * Controles de rotación automática. Sus valores iniciales mantienen la
	 * rotación activada cada seis segundos; el dueño puede cambiarlos en el panel.
	 */
	private const OPTION_ROTATE      = 'akibara_announcement_rotate';
	private const OPTION_ROTATE_SECS = 'akibara_announcement_rotate_secs';

	/**
	 * Carril promocional vinculado directamente a Akibara → Descuentos.
	 *
	 * Los mensajes permanentes comunican propuestas fijas (envío gratis, retiro en tienda).
	 * El carril promocional toma su vigencia y campaña exclusivamente de Descuentos por
	 * taxonomía para evitar fechas duplicadas o banners promoviendo rebajas terminadas.
	 */
	private const OPTION_PROMO_TEXT = 'akibara_announcement_promo_text';

	public const OPTION_COUNTDOWN_ENABLED = 'akibara_announcement_countdown_enabled';
	public const OPTION_COUNTDOWN_TIME    = 'akibara_announcement_countdown_time';
	public const OPTION_COUNTDOWN_LABEL   = 'akibara_announcement_countdown_label';

	private const LINK_PATTERN      = '~^(.*\S)(\s*\|\s*(/(?!/)\S*|https?://\S+|(?:[a-z0-9-]+\.)+[a-z]{2,}(?:[/?#]\S*)?)\s*)$~iu';
	private const URL_TOKEN_PATTERN = '/^\[akb_descuento_url(?:\s+[^\]]*)?\]$/i';

	/**
	 * CSS crítico de la barra, en línea y fuera del optimizador.
	 *
	 * La barra es el primer elemento del <body> y su hoja viaja dentro del CSS
	 * combinado/diferido de LiteSpeed, que llega DESPUÉS del primer pintado →
	 * FOUC (letras crudas, mensajes apilados sin estilo). Acá va lo mínimo para
	 * que pinte estilada en el primer frame; `announcement-bar.css` añade las
	 * transiciones, los offsets de entrada y el resto del responsive.
	 *
	 * CONTRATO: cada declaración de acá tiene que decir lo MISMO que la regla
	 * homónima de `assets/css/announcement-bar.css`, salvo las divergencias
	 * deliberadas que `AnnouncementBarCriticalCssTest` enumera (los
	 * `translateX` de la animación de entrada).
	 */
	private const CRITICAL_CSS =
		'#akb-ann-bar{min-height:48px;display:flex;align-items:center;justify-content:center;position:relative;padding:2px 16px;box-sizing:border-box;overflow:hidden;background:transparent}'
		. '#akb-ann-bar[hidden],#akb-ann-bar.akb-hidden{display:none!important}'
		. '#akb-ann-bar.akb-ann-bar--multi{display:grid;grid-template-columns:60px minmax(0,1fr) 60px}'
		. '.akb-ann-content{display:grid;grid-template-columns:minmax(0,1fr);grid-template-rows:1fr;width:100%;min-width:0}'
		. '.akb-ann-bar--multi .akb-ann-content{grid-column:2;width:100%;max-width:none}'
		. '.akb-ann-msg{grid-column:1/-1;grid-row:1/-1;justify-self:center;align-self:center;display:inline-flex;align-items:center;justify-content:center;position:relative;max-width:100%;font-family:var(--font-heading,"Inter",sans-serif);font-style:normal;font-synthesis:none;transform:skewX(-5deg);font-size:15px;font-weight:400;text-transform:uppercase;letter-spacing:.04em;padding:6px 20px;color:#fff;pointer-events:none}'
		. '.akb-ann-msg[hidden]{display:none}'
		. '.akb-ann-msg::before{content:"";position:absolute;inset:0;box-shadow:-4px 4px 0 #D90010;transform:skew(-12deg);opacity:0;visibility:hidden;z-index:-1}'
		. '.akb-ann-msg::after{content:"";position:absolute;inset:0;background:#0a0a0a;transform:skew(-12deg);opacity:0;visibility:hidden;z-index:0}'
		. '.akb-ann-msg-inner{position:relative;z-index:1;transform:skew(4deg);opacity:0;visibility:hidden;display:inline-block;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}'
		. '.akb-ann-msg.akb-on::before{opacity:1;visibility:visible}'
		. '.akb-ann-msg.akb-on::after{opacity:1;visibility:visible;transform:skew(-12deg)}'
		. '.akb-ann-msg.akb-on .akb-ann-msg-inner{opacity:1;visibility:visible;transform:skew(4deg);pointer-events:auto}'
		. '.akb-ann-msg--link{padding:0}'
		. '.akb-ann-msg--link .akb-ann-msg-inner{box-sizing:border-box;padding:6px 20px;color:inherit;text-decoration:underline;text-decoration-thickness:1px;text-underline-offset:3px;cursor:pointer}'
		. '#akb-ann-bar .akb-ann-next{grid-column:3;flex:0 0 60px;width:60px;height:44px;margin-left:0;padding:0;border:0;background:transparent;color:#fff;display:flex;align-items:center;justify-content:center;gap:6px;opacity:.74;appearance:none;-webkit-appearance:none;transition:opacity .18s ease,color .18s ease!important;will-change:auto}'
		. '#akb-ann-bar .akb-ann-next[hidden]{display:none}'
		. '#akb-ann-bar .akb-ann-next:hover,#akb-ann-bar .akb-ann-next:focus{background:transparent;box-shadow:none;transform:none}'
		. '#akb-ann-bar .akb-ann-next:active{background:transparent!important;box-shadow:none!important;transform:none!important}'
		. '#akb-ann-bar .akb-ann-next:focus-visible{outline:var(--aki-focus-outline,3px solid #fff);outline-offset:-4px}'
		. '.akb-ann-next__count{font-family:var(--font-heading,"Inter",sans-serif);font-size:11px;font-weight:400;line-height:1;letter-spacing:.06em}'
		. '.akb-ann-next__arrow{width:8px;height:8px;border-top:2px solid currentcolor;border-right:2px solid currentcolor;transform:rotate(45deg)}'
		. '.akb-ann-status{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip-path:inset(50%);white-space:nowrap;border:0}'
		. '.akb-ann-countdown{display:inline-flex;align-items:center;gap:4px;margin-left:8px;padding:2px 7px;background:#000;border:1.5px solid #fc0;box-shadow:2px 2px 0 rgba(0,0,0,0.5);border-radius:3px;font-size:11px;font-weight:700;line-height:1.2;letter-spacing:.04em;color:#fff;vertical-align:middle;text-transform:none;transform:skew(-4deg)}'
		. '.akb-ann-countdown[hidden]{display:none}'
		. '.akb-ann-countdown__icon{flex-shrink:0}'
		. '.akb-ann-countdown__prefix{color:#fff;font-size:10px;opacity:.9}'
		. '.akb-ann-countdown__digits{color:#fc0;font-family:ui-monospace,SFMono-Regular,"SF Mono",Menlo,Consolas,monospace;font-weight:800;letter-spacing:.05em}'
		. '@media(prefers-reduced-motion:reduce){#akb-ann-bar .akb-ann-next{transition:none!important}}'
		. '@media(max-width:768px){#akb-ann-bar{min-height:44px;padding:0 8px}#akb-ann-bar.akb-ann-bar--multi{grid-template-columns:56px minmax(0,1fr) 56px}.akb-ann-bar--multi .akb-ann-content{max-width:none}.akb-ann-msg{font-size:12px;letter-spacing:.03em;padding:3px 8px}.akb-ann-msg--link{padding:0}.akb-ann-msg--link .akb-ann-msg-inner{padding:3px 8px 0;border-block:12px solid transparent;border-block-end-width:15px;margin:-12px 0}#akb-ann-bar .akb-ann-msg-inner{display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:2;line-clamp:2;white-space:normal;line-height:1.3}#akb-ann-bar .akb-ann-next{flex-basis:56px;width:56px;margin-left:0}.akb-ann-countdown{font-size:10px;padding:1px 5px;margin-left:4px}.akb-ann-countdown__prefix{font-size:9px}}';

	private ?array $messages = null;

	/** Dedup por petición: N escrituras de esta pestaña programan UN purge. */
	private bool $purge_scheduled = false;

	public function register(): void {
		if ( ! is_admin() ) {
			add_action( 'wp', array( $this, 'maybe_init_bar' ) );
		}
		add_filter( 'akibara_panel_save_errors', array( $this, 'validate_save' ), 10, 2 );
		add_filter( 'akibara_panel_options_saved_with_warning', array( $this, 'report_saved_with_warning' ), 10, 2 );
		add_filter( 'woocommerce_admin_settings_sanitize_option_' . self::OPTION_COUNTDOWN_TIME, array( PromoSchedule::class, 'parse_time_hm' ) );

		// La purga de caché ante cambios en las opciones se deduplica y se programa
		// en `shutdown` para ejecutarse una sola vez. Cuando el guardado se realiza desde
		// el panel administrativo, `AdminPanel::handle_save()` centraliza la purga, evitando
		// llamadas redundantes y reduciendo la carga en los bordes de Cloudflare y LiteSpeed.
		// Se preserva el hook para cubrir modificaciones directas vía WP-CLI o API.
		foreach ( $this->purgeable_options() as $option ) {
			add_action( 'update_option_' . $option, array( $this, 'schedule_purge' ) );
			add_action( 'add_option_' . $option, array( $this, 'schedule_purge' ) );
		}
	}

	/**
	 * Rechaza formatos inválidos en hora de cierre o fecha antes de persistir opciones.
	 *
	 * @internal Callback de `akibara_panel_save_errors`.
	 *
	 * @param mixed  $errors Mensajes acumulados.
	 * @param string $tab    Pestaña que se guarda.
	 * @return mixed
	 */
	public function validate_save( $errors, $tab ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- `AdminPanel::handle_save()` verifica el nonce antes de aplicar este filtro.
		if ( self::PANEL_TAB !== $tab || ! is_array( $errors ) ) {
			return $errors;
		}

		if ( isset( $_POST[ self::OPTION_COUNTDOWN_TIME ] ) ) {
			$hora = trim( sanitize_text_field( wp_unslash( (string) $_POST[ self::OPTION_COUNTDOWN_TIME ] ) ) );
			if ( '' !== $hora && ! PromoSchedule::is_valid_time_hm( $hora ) ) {
				$errors[] = 'La hora de cierre de la cuenta regresiva debe tener formato 24h HH:MM entre 00:00 y 23:59 (ej. 23:59). No se guardó nada.';
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return $errors;
	}

	/**
	 * Todas las options cuyo valor pinta HTML cacheado de esta pieza.
	 *
	 * @return array<int, string>
	 */
	private function purgeable_options(): array {
		return array(
			self::OPTION_ENABLED,
			self::OPTION_MESSAGES,
			self::OPTION_ROTATE,
			self::OPTION_ROTATE_SECS,
			self::OPTION_PROMO_TEXT,
			self::OPTION_COUNTDOWN_ENABLED,
			self::OPTION_COUNTDOWN_TIME,
			self::OPTION_COUNTDOWN_LABEL,
		);
	}

	/**
	 * @internal Callback de `update_option_*` / `add_option_*`.
	 */
	public function schedule_purge(): void {
		if ( $this->purge_scheduled ) {
			return;
		}
		if ( self::el_panel_ya_purga() ) {
			return;
		}
		$this->purge_scheduled = true;
		// Prioridad -10 para correr antes de LiteSpeed Core::send_headers() (shutdown:0) en CLI/cron.
		add_action( 'shutdown', array( $this, 'do_purge' ), -10 );
	}

	/**
	 * ¿Viene esta escritura de guardar una pestaña del panel?
	 *
	 * `AdminPanel::handle_save()` programa su propio `litespeed_purge_all` en
	 * `shutdown`, con la misma prioridad, para cualquier cambio real de esta
	 * pestaña. Actuar también aquí purgaría el borde dos veces en el mismo
	 * guardado. Se mira el mismo centinela que usa `LscPurger`: no autoriza
	 * nada —el panel ya validó su nonce y su capacidad—, sólo responde si hay
	 * alguien más purgando en esta misma petición.
	 */
	private static function el_panel_ya_purga(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- no autoriza nada: sólo evita purgar dos veces lo mismo.
		return isset( $_POST['akibara_panel_save'] );
	}

	/**
	 * @internal Callback de `shutdown`.
	 */
	public function do_purge(): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook de LiteSpeed: es SU purga.
		do_action( 'litespeed_purge_all' );
	}

	public static function is_enabled(): bool {
		$bar = get_option( 'akibara_announcement_bar_enabled', '' );
		if ( '' !== $bar ) {
			return 'yes' === $bar;
		}
		return 'yes' === get_option( self::OPTION_ENABLED, 'yes' );
	}

	public function maybe_init_bar(): void {
		if ( ! self::is_enabled() ) {
			return;
		}
		if ( empty( $this->get_messages() ) ) {
			return;
		}
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_body_open',       array( $this, 'render_bar' ) );
	}

	public function enqueue_assets(): void {
		wp_enqueue_style(
			'akb-announcement-bar',
			AKIBARA_BUSINESS_URL . 'assets/css/announcement-bar.css',
			array(),
			PluginAssetVersion::for_path( 'assets/css/announcement-bar.css' )
		);

		if ( 1 < count( $this->get_messages() ) || array() !== $this->promo_lines() ) {
			wp_enqueue_script(
				'akb-announcement-bar',
				AKIBARA_BUSINESS_URL . 'assets/js/announcement-bar.js',
				array(),
				PluginAssetVersion::for_path( 'assets/js/announcement-bar.js' ),
				true
			);
		}
	}

	/**
	 * ¿La barra rota sola?
	 *
	 * @return bool
	 */
	public static function rotates(): bool {
		return 'no' !== (string) get_option( self::OPTION_ROTATE, 'yes' );
	}

	/**
	 * Cada cuántos segundos cambia de mensaje.
	 *
	 * Acotado entre 3 y 30: por debajo no da tiempo a leer y por encima la
	 * segunda línea no se ve nunca.
	 *
	 * @return int
	 */
	public static function rotate_seconds(): int {
		$segundos = (int) get_option( self::OPTION_ROTATE_SECS, 6 );

		return max( 3, min( 30, 0 === $segundos ? 6 : $segundos ) );
	}

	public function render_bar(): void {
		$messages      = $this->get_messages();
		$has_multiple  = 1 < count( $messages );
		$message_count = count( $messages );

		// CSS estático de esta clase, sin un solo dato de entrada: escaparlo
		// rompería el propio CSS (`>` en los selectores, comillas de las fuentes).
		echo '<style id="akb-ann-bar-critical" data-no-optimize="1" data-no-defer="1">' . self::CRITICAL_CSS . '</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- self::CRITICAL_CSS es una constante de clase con CSS literal, sin un solo dato de entrada; escaparla rompería los «>» de los selectores y las comillas de las fuentes.

		$bar_class = $has_multiple ? 'akb-ann-bar akb-ann-bar--multi' : 'akb-ann-bar';
		echo '<div id="akb-ann-bar" class="' . esc_attr( $bar_class ) . '"'
			. ' data-rotate="' . ( self::rotates() ? '1' : '0' ) . '" data-rotate-ms="' . esc_attr( (string) ( self::rotate_seconds() * 1000 ) ) . '"'
			. ' role="region" aria-label="' . esc_attr( 'Anuncios de la tienda' ) . '">' . "\n";
		echo '<div id="akb-ann-content" class="akb-ann-content">' . "\n";
		$allowed_message_html = array(
			'a'        => array(
				'href'   => array(),
				'target' => array(),
			),
			'strong'   => array(),
			'em'       => array(),
			'span'     => array(
				'class'                    => array(),
				'data-akb-countdown-epoch' => array(),
				'role'                     => array(),
				'aria-live'                => array(),
				'aria-hidden'              => array(),
			),
			'svg'      => array(
				'class'           => array(),
				'width'           => array(),
				'height'          => array(),
				'viewbox'         => array(),
				'fill'            => array(),
				'stroke'          => array(),
				'stroke-width'    => array(),
				'stroke-linecap'  => array(),
				'stroke-linejoin' => array(),
				'aria-hidden'     => array(),
			),
			'circle'   => array(
				'cx' => array(),
				'cy' => array(),
				'r'  => array(),
			),
			'polyline' => array(
				'points' => array(),
			),
		);
		// Dentro de un mensaje-enlace no puede ir otro <a>: anidados son HTML
		// inválido y el navegador los parte en dos.
		$allowed_in_link = array_diff_key( $allowed_message_html, array( 'a' => true ) );
		$promo_count        = count( $this->promo_lines() );
		$campaign_end_epoch = $promo_count > 0 ? PromoSchedule::campaign_end_epoch() : null;
		foreach ( $messages as $i => $msg ) {
			$linea         = self::split_link( $msg );
			$message_class = 0 === $i ? 'akb-ann-msg akb-on' : 'akb-ann-msg';
			if ( '' !== $linea['url'] ) {
				$message_class .= ' akb-ann-msg--link';
			}
			echo '<span class="' . esc_attr( $message_class ) . '"';
			if ( $i < $promo_count ) {
				echo ' data-akb-promo-msg="1"';
				if ( null !== $campaign_end_epoch ) {
					echo ' data-akb-promo-expires-epoch="' . (int) ( $campaign_end_epoch * 1000 ) . '"';
				}
			}
			if ( 0 !== $i ) {
				echo ' aria-hidden="true" inert';
			}
			if ( '' !== $linea['url'] ) {
				// La cinta entera es el enlace: el <a> ocupa la caja del texto
				// interior, que en esta variante lleva el relleno de la cinta.
				echo '><a class="akb-ann-msg-inner" href="' . esc_url( $linea['url'] ) . '">' . wp_kses( $linea['text'], $allowed_in_link ) . '</a></span>' . "\n";
				continue;
			}
			echo '><span class="akb-ann-msg-inner">' . wp_kses( $msg, $allowed_message_html ) . '</span></span>' . "\n";
		}
		echo '</div>' . "\n";
		if ( $has_multiple ) {
			$next_label = sprintf(
				'Siguiente anuncio. %1$d/%2$d',
				1,
				$message_count
			);
			echo '<button type="button" class="akb-ann-next" data-akb-ann-next aria-controls="akb-ann-content" aria-label="' . esc_attr( $next_label ) . '" disabled>'
				. '<span class="akb-ann-next__count" data-akb-ann-count aria-hidden="true">1/' . (int) $message_count . '</span>'
				. '<span class="akb-ann-next__arrow" aria-hidden="true"></span>'
				. '</button>' . "\n";
			echo '<span class="akb-ann-status" data-akb-ann-status role="status" aria-live="polite" aria-atomic="true"></span>' . "\n";
		}
		echo '</div>' . "\n";
	}

	/**
	 * Separa la ruta y el sufijo de un enlace escrito como «Texto | /ruta/».
	 *
	 * @param string $line Una línea ya resuelta.
	 * @return array{text: string, suffix: string, url: string}|null
	 */
	private static function parse_pipe_link( string $line ): ?array {
		if ( 1 !== preg_match( self::LINK_PATTERN, $line, $m ) ) {
			return null;
		}

		$url = self::link_target( trim( $m[3] ) );
		if ( '' === $url ) {
			return null;
		}

		return array(
			'text'   => $m[1],
			'suffix' => $m[2],
			'url'    => $url,
		);
	}

	/**
	 * Separa el enlace opcional de un mensaje mediante la convención «Texto | /ruta/».
	 *
	 * Permite incorporar enlaces directos sin requerir marcado HTML manual.
	 * Solo se interpreta como enlace la porción posterior a la última barra vertical
	 * si coincide con una dirección válida (ver `link_target()`).
	 *
	 * @param string $line Una línea ya resuelta (montos y porcentajes incluidos).
	 * @return array{text:string,url:string}
	 */
	public static function split_link( string $line ): array {
		$parsed = self::parse_pipe_link( $line );
		if ( null === $parsed ) {
			return array(
				'text' => $line,
				'url'  => '',
			);
		}

		return array(
			'text' => $parsed['text'],
			'url'  => $parsed['url'],
		);
	}

	/**
	 * La dirección de un enlace de la barra, o '' si lo escrito no es una URL válida.
	 *
	 * Admite rutas relativas de la tienda («/envios/») o URLs absolutas («https://…»),
	 * normalizando esquemas y enlaces canónicos internos sin saltos de subdominio.
	 *
	 * @param string $raw Lo que va tras la última barra, sin espacios alrededor.
	 */
	private static function link_target( string $raw ): string {
		if ( 1 === preg_match( '~^/(?!/)\S*$~', $raw ) ) {
			return home_url( $raw );
		}
		if ( 1 === preg_match( '~^(https?)(://\S+)$~i', $raw, $m ) ) {
			return strtolower( $m[1] ) . $m[2];
		}
		if ( 1 !== preg_match( '~^((?:[a-z0-9-]+\.)+[a-z]{2,})([/?#]\S*)?$~i', $raw, $m ) ) {
			return '';
		}

		$host  = strtolower( $m[1] );
		$ruta  = $m[2] ?? '';
		$tuyo  = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$mismo = '' !== $tuyo && preg_replace( '/^www\./', '', $host ) === preg_replace( '/^www\./', '', strtolower( $tuyo ) );
		if ( $mismo ) {
			return home_url( '' === $ruta ? '/' : ( '/' === $ruta[0] ? $ruta : '/' . $ruta ) );
		}

		return 'https://' . $host . $ruta;
	}

	/**
	 * Detecta líneas que terminan en «| algo» que parece un enlace mal estructurado.
	 *
	 * Identifica intentos fallidos de enlace para alertar al usuario antes de guardar
	 * y prevenir que la barra muestre textos truncados o rutas rotas.
	 *
	 * @param string $raw Texto del campo tal como se guardó, una línea por mensaje.
	 * @return array<int, array{tras:string, sin_texto:bool}> Número de línea del
	 *         campo (desde 1) => lo escrito tras la barra y si faltaba el texto.
	 */
	public static function unrecognized_links( string $raw ): array {
		$avisos = array();
		foreach ( explode( "\n", str_replace( "\r", '', $raw ) ) as $i => $linea ) {
			$linea = trim( $linea );
			$barra = strrpos( $linea, '|' );
			if ( false === $barra ) {
				continue;
			}

			$antes = trim( substr( $linea, 0, $barra ) );
			$tras  = trim( substr( $linea, $barra + 1 ) );

			if ( 1 === preg_match( self::URL_TOKEN_PATTERN, $tras ) ) {
				if ( '' === $antes ) {
					$avisos[ $i + 1 ] = array(
						'tras'      => $tras,
						'sin_texto' => true,
					);
				}
				continue;
			}

			if ( '' !== self::split_link( $linea )['url'] ) {
				continue;
			}

			// Una ruta con espacios sigue siendo un intento; lo demás, sólo si es
			// UNA pieza sin espacios («Instagram: @akibara» es texto).
			$parece = '' === $tras
				|| '/' === $tras[0]
				|| ( 1 !== preg_match( '/\s/u', $tras )
					&& ( 1 === preg_match( '~^[a-z][a-z0-9+.-]*:|\.[a-z]{2,}~i', $tras ) || 1 === preg_match( '~^[a-z0-9-]+$~', $tras ) ) );
			if ( $parece ) {
				$avisos[ $i + 1 ] = array(
					'tras'      => $tras,
					'sin_texto' => '' === $antes,
				);
			}
		}

		return $avisos;
	}

	/**
	 * Avisa, sin bloquear, sobre enlaces dudosos o promesas ocultas/en pausa tras guardar.
	 *
	 * @internal Callback de `akibara_panel_options_saved_with_warning`.
	 *
	 * @param array<string, string> $avisos option => qué revisar.
	 * @param string                $tab    Pestaña guardada.
	 * @return array<string, string>
	 */
	public function report_saved_with_warning( array $avisos, string $tab ): array {
		if ( self::PANEL_TAB !== $tab ) {
			return $avisos;
		}

		foreach ( array( self::OPTION_MESSAGES, self::OPTION_PROMO_TEXT ) as $option ) {
			$partes = array();
			$raw    = (string) get_option( $option, '' );
			foreach ( self::unrecognized_links( $raw ) as $numero => $linea ) {
				if ( $linea['sin_texto'] ) {
					$partes[] = 'la línea ' . $numero . ' no tiene texto antes de «|»';
				} elseif ( '' === $linea['tras'] ) {
					$partes[] = 'la línea ' . $numero . ' termina en «|» sin dirección';
				} else {
					// El aviso se pinta con `wp_kses_post()`: lo escrito por el dueño va
					// escapado para que se lea tal cual, etiquetas incluidas.
					$partes[] = 'en la línea ' . $numero . ' «' . esc_html( $linea['tras'] ) . '» no es una dirección';
				}
			}
			if ( array() !== $partes ) {
				$avisos[ $option ] = ucfirst( implode( '; ', $partes ) ) . '. El cliente ve ese final como texto, con la barra. Para enlazar, escribe | [akb_descuento_url] o una dirección empezando por / (como /envios/) o https://, sin espacios.';
			}
		}

		$fixed_raw = (string) get_option( self::OPTION_MESSAGES, '' );
		if ( ! isset( $avisos[ self::OPTION_MESSAGES ] ) && false !== strpos( $fixed_raw, '[akb_descuento_' ) ) {
			$avisos[ self::OPTION_MESSAGES ] = 'Incluye tokens [akb_descuento_*] que quedan vacíos cuando termina la campaña. Muévelo al campo «Mensaje promocional» para que aparezca y desaparezca automáticamente junto a Descuentos.';
		}

		$window = DiscountCopy::campaign_window( true );
		$promo  = trim( (string) get_option( self::OPTION_PROMO_TEXT, '' ) );
		if ( '' !== $promo && ! isset( $avisos[ self::OPTION_PROMO_TEXT ] ) && ! $window['active'] ) {
			$avisos[ self::OPTION_PROMO_TEXT ] = match ( $window['reason'] ) {
				'scheduled' => 'El mensaje quedó guardado, pero permanecerá oculto hasta el ' . esc_html( PromoSchedule::format_ymd_cl( $window['from'] ) ) . ', cuando empiece la campaña de Akibara → Descuentos.',
				'expired'   => 'El mensaje quedó guardado, pero la vigencia de Akibara → Descuentos ya venció; renueva las fechas allá para publicarlo.',
				'no_rules'  => 'El mensaje quedó guardado, pero Akibara → Descuentos no tiene ninguna regla con % mayor a 0 y stock elegible; no se mostrará hasta asignar un descuento.',
				default     => 'El mensaje quedó guardado, pero permanecerá oculto mientras Akibara → Descuentos esté apagado.',
			};
		}

		if ( 'yes' === (string) get_option( self::OPTION_COUNTDOWN_ENABLED, 'no' ) && '' === $window['to'] ) {
			$avisos[ self::OPTION_COUNTDOWN_ENABLED ] = 'Activaste la cuenta regresiva, pero permanecerá en pausa porque la campaña en Akibara → Descuentos no tiene fecha «Vigencia hasta».';
		}

		return $avisos;
	}

	private function get_messages(): array {
		if ( null !== $this->messages ) {
			return $this->messages;
		}

		// El promocional va PRIMERO: es el que tiene fecha de vencimiento y el
		// que el cliente tiene que leer antes de que se acabe. Los permanentes
		// siguen ahí y la barra los rota igual.
		$this->messages = array_merge(
			$this->promo_lines(),
			self::split_lines( (string) get_option( self::OPTION_MESSAGES, '' ) )
		);

		return $this->messages;
	}

	/**
	 * Un texto multilínea del panel → las líneas listas para pintar.
	 *
	 * Los montos y porcentajes se escriben `[akb_envio_gratis]`,
	 * `[akb_descuento_pct]` y `[akb_descuento_editorial]`, y se resuelven con el
	 * valor VIVO del panel: así el mensaje no puede quedarse anunciando un dato
	 * viejo cuando el dueño lo cambia en su módulo. Se resuelven SÓLO esos tres,
	 * no `do_shortcode()` entero: este texto se pinta en todas las páginas del
	 * sitio y no tiene por qué ejecutar shortcodes arbitrarios.
	 *
	 * @param string $raw Texto crudo de la option.
	 * @return array<int, string>
	 */
	private static function split_lines( string $raw ): array {
		if ( '' === trim( $raw ) ) {
			return array();
		}

		$raw   = ShippingCopy::resolve_in( $raw );
		$raw   = str_replace( '[akb_cuenta_regresiva]', '', $raw );
		$lines = array();

		foreach ( explode( "\n", str_replace( "\r", '', $raw ) ) as $line ) {
			$has_url_token = 1 === preg_match( '/^(.*\S)\s*\|\s*(\[akb_descuento_url(?:\s+[^\]]*)?\])\s*$/i', $line, $um );
			$resolved      = DiscountCopy::resolve_in( $line );
			if ( $has_url_token && '' === trim( DiscountCopy::resolve_in( $um[2] ) ) ) {
				$resolved = DiscountCopy::resolve_in( $um[1] );
			}
			$clean = trim( (string) preg_replace( '/[ \t]{2,}/', ' ', $resolved ) );
			if ( '' !== $clean ) {
				$lines[] = $clean;
			}
		}

		return $lines;
	}

	/**
	 * Devuelve el timestamp epoch en segundos (UTC/Unix) para el cierre de la promoción,
	 * o null si la campaña en Descuentos está inactiva o sin fecha «Vigencia hasta».
	 *
	 * @return int|null
	 */
	public static function get_countdown_target(): ?int {
		return PromoSchedule::countdown_target_epoch(
			(string) get_option( self::OPTION_COUNTDOWN_TIME, PromoSchedule::DEFAULT_CUTOFF_HM )
		);
	}

	/**
	 * Renderiza el badge Neo-Brutalista de cuenta regresiva en HTML.
	 *
	 * @param int|null $target_epoch Epoch en segundos.
	 * @return string HTML seguro del chip de cuenta regresiva.
	 */
	public static function countdown_markup( ?int $target_epoch = null ): string {
		$epoch = $target_epoch ?? self::get_countdown_target();
		if ( null === $epoch || $epoch <= time() ) {
			return '';
		}

		$now   = time();
		$diff  = max( 0, $epoch - $now );
		$days  = (int) floor( $diff / 86400 );
		$hours = (int) floor( ( $diff % 86400 ) / 3600 );
		$mins  = (int) floor( ( $diff % 3600 ) / 60 );
		$secs  = $diff % 60;

		if ( $days > 0 ) {
			$digits = sprintf( '%dd %02dh %02dm %02ds', $days, $hours, $mins, $secs );
		} else {
			$digits = sprintf( '%02dh %02dm %02ds', $hours, $mins, $secs );
		}

		$label      = trim( (string) get_option( self::OPTION_COUNTDOWN_LABEL, 'Termina en' ) );
		$label_html = '' !== $label ? '<span class="akb-ann-countdown__prefix">' . esc_html( $label ) . '</span> ' : '';
		$clock_svg  = '<svg class="akb-ann-countdown__icon" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 15 14"/></svg>';

		return '<span class="akb-ann-countdown" data-akb-countdown-epoch="' . ( $epoch * 1000 ) . '" role="timer" aria-live="off">'
			. $clock_svg . ' '
			. $label_html
			. '<span class="akb-ann-countdown__digits">' . esc_html( $digits ) . '</span>'
			. '</span>';
	}

	/**
	 * Adjunta el badge al mensaje respetando tanto «Texto | /url/» como `<a href="...">texto</a>`.
	 */
	public static function attach_countdown( string $line, string $badge ): string {
		if ( '' === $badge ) {
			return $line;
		}

		$parsed = self::parse_pipe_link( $line );
		if ( null !== $parsed ) {
			return $parsed['text'] . ' ' . $badge . $parsed['suffix'];
		}

		if ( 1 === preg_match( '~^\s*(<a\b[^>]*>)((?:(?!<a\b).)*)(</a>)\s*$~is', $line, $am ) ) {
			return $am[1] . $am[2] . ' ' . $badge . $am[3];
		}

		return $line . ' ' . $badge;
	}

	/**
	 * Las líneas del carril promocional, o vacío si hoy no está vigente.
	 *
	 * @return array<int, string>
	 */
	private function promo_lines(): array {
		if ( ! $this->promo_active() ) {
			return array();
		}

		$raw = (string) get_option( self::OPTION_PROMO_TEXT, '' );
		if ( '' === trim( $raw ) ) {
			return array();
		}

		$lines = self::split_lines( $raw );
		if ( empty( $lines ) ) {
			return array();
		}

		$countdown_enabled = 'yes' === get_option( self::OPTION_COUNTDOWN_ENABLED, 'no' );
		$target_epoch      = self::get_countdown_target();

		if ( $countdown_enabled && null !== $target_epoch ) {
			$badge = self::countdown_markup( $target_epoch );
			foreach ( $lines as $idx => $line ) {
				if ( false === strpos( $line, 'akb-ann-countdown' ) ) {
					$lines[ $idx ] = self::attach_countdown( $line, $badge );
					break;
				}
			}
		}

		return $lines;
	}

	/**
	 * ¿El mensaje promocional se muestra AHORA?
	 *
	 * Su vigencia es exclusiva de `Akibara → Descuentos` (vía `DiscountCopy`):
	 * interruptor encendido + rango `[from, to]` vigente + al menos una regla
	 * elegible con porcentaje > 0.
	 */
	public function promo_active(): bool {
		if ( '' === trim( (string) get_option( self::OPTION_PROMO_TEXT, '' ) ) ) {
			return false;
		}

		return DiscountCopy::campaign_active();
	}

	/**
	 * Foto fresca de configuración del banner para tarjetas cruzadas de admin.
	 *
	 * @return array{bar_enabled: bool, promo_text: string, has_text: bool, countdown_enabled: bool, countdown_time: string, countdown_label: string, edit_url: string}
	 */
	public static function promo_overview(): array {
		$keys = array(
			'akibara_announcement_bar_enabled',
			self::OPTION_ENABLED,
			self::OPTION_PROMO_TEXT,
			self::OPTION_COUNTDOWN_ENABLED,
			self::OPTION_COUNTDOWN_TIME,
			self::OPTION_COUNTDOWN_LABEL,
		);
		$db   = OptionsFromDatabase::read( $keys );

		$read = static function ( string $key, string $default ) use ( $db ): string {
			if ( is_array( $db ) && array_key_exists( $key, $db ) ) {
				return (string) $db[ $key ];
			}

			return (string) get_option( $key, $default );
		};

		$bar_legacy  = $read( 'akibara_announcement_bar_enabled', '' );
		$bar_enabled = '' !== $bar_legacy ? ( 'yes' === $bar_legacy ) : ( 'yes' === $read( self::OPTION_ENABLED, 'yes' ) );
		$promo_text  = trim( $read( self::OPTION_PROMO_TEXT, '' ) );

		return array(
			'bar_enabled'       => $bar_enabled,
			'promo_text'        => $promo_text,
			'has_text'          => '' !== $promo_text,
			'countdown_enabled' => 'yes' === $read( self::OPTION_COUNTDOWN_ENABLED, 'no' ),
			'countdown_time'    => PromoSchedule::parse_time_hm( $read( self::OPTION_COUNTDOWN_TIME, PromoSchedule::DEFAULT_CUTOFF_HM ) ),
			'countdown_label'   => trim( $read( self::OPTION_COUNTDOWN_LABEL, 'Termina en' ) ),
			'edit_url'          => esc_url( admin_url( 'admin.php?page=akibara-panel&tab=' . self::PANEL_TAB . '#' . self::OPTION_PROMO_TEXT ) ),
		);
	}

	/**
	 * Resuelve texto visible y URL de destino de la primera línea promocional para vistas previas en admin.
	 *
	 * @return array{text: string, url: string}
	 */
	public static function preview_promo_line( string $promo_text ): array {
		if ( '' === trim( $promo_text ) ) {
			return array(
				'text' => '',
				'url'  => '',
			);
		}

		$first_raw = explode( "\n", str_replace( "\r", '', trim( $promo_text ) ) )[0];
		$resolved  = trim( (string) preg_replace( '/[ \t]{2,}/', ' ', DiscountCopy::resolve_in( str_replace( '[akb_cuenta_regresiva]', '', $first_raw ) ) ) );
		$parsed    = self::split_link( $resolved );

		return array(
			'text' => trim( wp_strip_all_tags( $parsed['text'] ) ),
			'url'  => $parsed['url'],
		);
	}

	/**
	 * Card puente en `Akibara → Barra de anuncios` mostrando campaña vinculada y vista previa.
	 */
	public function render_bridge_card(): void { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh
		$window   = DiscountCopy::campaign_window( true );
		$overview = self::promo_overview();
		$edit_url = esc_url( admin_url( 'admin.php?page=akibara-panel&tab=taxonomy_discount' ) );
		$from_cl  = PromoSchedule::format_ymd_cl( $window['from'] );
		$to_cl    = PromoSchedule::format_ymd_cl( $window['to'] );
		$rango    = ( '' === $from_cl && '' === $to_cl )
			? 'sin fecha límite'
			: ( '' !== $from_cl ? $from_cl : 'hoy' ) . ' → ' . ( '' !== $to_cl ? $to_cl : 'sin término' );

		$dot_class   = 'akb-bridge-card__dot--off';
		$status_text = 'Campaña apagada en Descuentos';
		if ( $window['active'] ) {
			$dot_class   = 'akb-bridge-card__dot--on';
			$status_text = 'Campaña activa ahora (' . esc_html( $rango ) . ')';
		} elseif ( 'scheduled' === $window['reason'] ) {
			$dot_class   = 'akb-bridge-card__dot--warn';
			$status_text = 'Programada desde el ' . esc_html( $from_cl ) . ' (' . esc_html( $rango ) . ')';
		} elseif ( 'expired' === $window['reason'] ) {
			$dot_class   = 'akb-bridge-card__dot--off';
			$status_text = 'Vigencia vencida (' . esc_html( $rango ) . ')';
		} elseif ( 'no_rules' === $window['reason'] ) {
			$dot_class   = 'akb-bridge-card__dot--warn';
			$status_text = 'Encendida en Descuentos, pero sin reglas > 0% con productos elegibles';
		}

		$rule         = $window['rule'];
		$apply_labels = array(
			'instock_only'            => 'Solo en stock',
			'instock_and_onbackorder' => 'En stock y reservas',
			'all'                     => 'Todo el catálogo del término',
		);
		$apply_label  = $apply_labels[ $window['apply_to'] ] ?? $window['apply_to'];
		$preview      = $overview['has_text'] ? self::preview_promo_line( $overview['promo_text'] ) : array( 'text' => '', 'url' => '' );
		$preview_line = $preview['text'];
		$preview_dest = $preview['url'];
		?>
		<section class="akb-bridge-card" aria-labelledby="akb-ann-bridge-heading">
			<div class="akb-bridge-card__head">
				<h2 id="akb-ann-bridge-heading" class="akb-bridge-card__title">
					<span class="akb-bridge-card__dot <?php echo esc_attr( $dot_class ); ?>" aria-hidden="true"></span>
					Campaña conectada desde Akibara → Descuentos: <?php echo $status_text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapado arriba. ?>
				</h2>
				<a href="<?php echo $edit_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ya pasa por esc_url. ?>" class="button akb-bridge-card__cta">Ir a Akibara → Descuentos</a>
			</div>
			<div class="akb-bridge-card__grid">
				<?php if ( is_array( $rule ) ) : ?>
					<div>
						<strong>Regla principal:</strong> <?php echo esc_html( (string) $rule['term_name'] ); ?> · <strong><?php echo (int) $rule['pct']; ?>% dcto</strong> · <?php echo esc_html( $apply_label ); ?>
						<?php if ( (int) ( $rule['rules_count'] ?? 1 ) > 1 ) : ?>
							· <em>(<?php echo (int) $rule['rules_count']; ?> reglas activas en total)</em>
						<?php endif; ?>
					</div>
				<?php endif; ?>
				<?php if ( '' !== $preview_line ) : ?>
					<div class="akb-bridge-card__preview">
						<span>Vista previa:</span>
						<strong><?php echo esc_html( $preview_line ); ?></strong>
						<?php if ( $overview['countdown_enabled'] && '' !== $to_cl ) : ?>
							<code><?php echo esc_html( ( '' !== $overview['countdown_label'] ? $overview['countdown_label'] . ' ' : '' ) . 'hasta ' . $to_cl . ' ' . $overview['countdown_time'] ); ?></code>
						<?php endif; ?>
					</div>
				<?php endif; ?>
				<?php if ( '' !== $preview_dest ) : ?>
					<div class="akb-bridge-card__dest"><strong>Destino actual del banner:</strong> <?php echo esc_html( $preview_dest ); ?></div>
				<?php elseif ( $window['active'] ) : ?>
					<div class="akb-bridge-card__dest"><strong>Enlace dinámico <code>[akb_descuento_url]</code>:</strong> <?php echo esc_html( DiscountCopy::target_url() ); ?></div>
				<?php endif; ?>
				<?php if ( is_array( $rule ) && (int) ( $rule['rules_count'] ?? 1 ) > 1 ) : ?>
					<p class="akb-bridge-card__note">Hay <?php echo (int) $rule['rules_count']; ?> términos con descuento activo: <code>[akb_descuento_*]</code> toma la regla de mayor descuento (<?php echo esc_html( (string) $rule['term_name'] ); ?>, <?php echo (int) $rule['pct']; ?>%). Si prefieres anunciar todas juntas, escribe el texto general y enlaza con <code>| /tienda/</code> o <code>| [akb_descuento_url alcance="tienda"]</code>.</p>
				<?php endif; ?>
			</div>
		</section>
		<?php
	}

	/**
	 * Muestra en el texto del campo promocional las fechas reales de Descuentos.
	 */
	private function discount_dates_status_text(): string {
		$window   = DiscountCopy::campaign_window( true );
		$edit_url = esc_url( admin_url( 'admin.php?page=akibara-panel&tab=taxonomy_discount' ) );
		$from_cl  = PromoSchedule::format_ymd_cl( $window['from'] );
		$to_cl    = PromoSchedule::format_ymd_cl( $window['to'] );

		if ( 'scheduled' === $window['reason'] ) {
			return '<br><span style="color:#8a4f00;">Programada en Akibara → Descuentos desde <strong>' . esc_html( $from_cl ) . '</strong> (<a href="' . $edit_url . '">Ir a Descuentos</a>).</span>';
		}
		if ( 'expired' === $window['reason'] || 'disabled' === $window['reason'] ) {
			return '<br><span style="color:#646970;">Estado en Descuentos: <strong>apagado o fuera de vigencia</strong> (<a href="' . $edit_url . '">Ir a Descuentos</a>).</span>';
		}
		if ( 'no_rules' === $window['reason'] ) {
			return '<br><span style="color:#8a4f00;">Descuentos está encendido, pero <strong>sin reglas &gt; 0% con productos elegibles</strong> (<a href="' . $edit_url . '">Configurar reglas</a>).</span>';
		}

		if ( '' !== $from_cl || '' !== $to_cl ) {
			$rango = ( '' !== $from_cl ? $from_cl : 'hoy' ) . ' → ' . ( '' !== $to_cl ? $to_cl : 'sin término' );
			return '<br><span style="color:#1e4620;">Vigencia activa desde Akibara → Descuentos: <strong>' . esc_html( $rango ) . '</strong> (<a href="' . $edit_url . '">Editar campaña</a>).</span>';
		}

		return '<br><span style="color:#1e4620;">Vigencia activa en Akibara → Descuentos <strong>sin fecha límite</strong> (<a href="' . $edit_url . '">Editar campaña</a>).</span>';
	}

	/**
	 * Muestra al dueño la resolución exacta del contador en hora Chile.
	 */
	private function countdown_status_text(): string {
		if ( 'yes' !== get_option( self::OPTION_COUNTDOWN_ENABLED, 'no' ) ) {
			return '<br><span style="color:#646970;">Ahora mismo: <strong>apagado</strong> (el anuncio se muestra sin contador).</span>';
		}

		$window = DiscountCopy::campaign_window( true );
		if ( ! $window['active'] ) {
			$edit_url = esc_url( admin_url( 'admin.php?page=akibara-panel&tab=taxonomy_discount' ) );
			return '<br><span style="color:#646970;">Ahora mismo: <strong>en espera</strong> — el mensaje y el reloj se activarán juntos cuando «<a href="' . $edit_url . '">Akibara → Descuentos</a>» tenga una campaña activa con «Vigencia hasta».</span>';
		}

		$target = self::get_countdown_target();
		if ( null === $target ) {
			$edit_url = esc_url( admin_url( 'admin.php?page=akibara-panel&tab=taxonomy_discount' ) );
			return '<br><span style="color:#8a4f00;">⚠ <strong>Reloj en pausa:</strong> la campaña activa en Descuentos no tiene fecha límite («Vigencia hasta» vacío). Agrégala en <a href="' . $edit_url . '">Akibara → Descuentos</a> para mostrar el contador.</span>';
		}

		return '<br><span style="color:#1e4620;">Reloj activo en tienda hasta: <strong>' . esc_html( StoreTime::format( $target, 'd/m/Y H:i' ) ) . '</strong> (hora oficial de Chile).</span>';
	}

	/**
	 * Qué está pasando con el carril promocional, en una frase, para el panel.
	 */
	private function promo_status_text(): string {
		if ( ! self::is_enabled() ) {
			return 'Ahora mismo: <strong>oculto</strong> — la barra completa está desactivada (interruptor «Activar» de arriba), aunque exista una campaña.';
		}

		if ( '' === trim( (string) get_option( self::OPTION_PROMO_TEXT, '' ) ) ) {
			return 'Ahora mismo: <strong>no hay mensaje promocional</strong>; la barra muestra sólo los fijos.';
		}

		$window   = DiscountCopy::campaign_window( true );
		$edit_url = esc_url( admin_url( 'admin.php?page=akibara-panel&tab=taxonomy_discount' ) );

		if ( $window['active'] ) {
			$rule = $window['rule'];
			$info = is_array( $rule ) ? sprintf( ' (%s · %d%%)', (string) $rule['term_name'], (int) $rule['pct'] ) : '';
			return 'Ahora mismo: <strong>se está viendo en la tienda' . esc_html( $info ) . '</strong>.';
		}

		return match ( $window['reason'] ) {
			'scheduled' => 'Ahora mismo: <strong>oculto (programado)</strong> — empezará el ' . esc_html( PromoSchedule::format_ymd_cl( $window['from'] ) ) . ' según <a href="' . $edit_url . '">Akibara → Descuentos</a>.',
			'expired'   => 'Ahora mismo: <strong>oculto</strong> — la vigencia en <a href="' . $edit_url . '">Akibara → Descuentos</a> ya terminó.',
			'no_rules'  => 'Ahora mismo: <strong>oculto</strong> — <a href="' . $edit_url . '">Akibara → Descuentos</a> está encendido, pero sin reglas &gt; 0% con stock elegible.',
			default     => 'Ahora mismo: <strong>oculto</strong> — no hay campaña vigente en <a href="' . $edit_url . '">Akibara → Descuentos</a>. Volverá solo al activarse.',
		};
	}

	public function get_settings(): array {
		return array(
			array(
				'title' => 'Barra general y mensajes fijos',
				'type'  => 'title',
				'desc'  => 'Mensajes permanentes navegables sobre el header en todo el sitio. Acepta HTML básico (&lt;strong&gt;, &lt;em&gt;) y un enlace por mensaje (<code>Texto | /ruta/</code>).',
				'id'    => 'akibara_announcement_section',
			),
			array(
				'title'   => 'Activar',
				'id'      => self::OPTION_ENABLED,
				'type'    => 'checkbox',
				'default' => 'yes',
				'desc'    => 'Muestra la barra sobre el header en todo el sitio. Al desactivarla los mensajes se conservan.',
			),
			array(
				'title'   => 'Mensajes',
				// El límite NO es decorativo: en un móvil de 360px la cinta ocupa
				// hasta dos renglones y pasados los ~80 caracteres se corta con
				// puntos suspensivos (ver `LINE_MAX`), y el recorte se come justo
				// el final —que es donde suele estar la promesa—. Un mensaje que
				// llega cortado pierde toda su fuerza, así que el número va escrito
				// aquí, donde se redacta.
				'desc'    => 'Un mensaje por línea. Mínimo 1 para activar la barra. '
					. '<strong>Máximo ~' . self::LINE_MAX . ' caracteres por línea</strong>: en el celular el mensaje ocupa hasta dos renglones y, si no cabe, se corta con «…» y pierde fuerza. '
					. 'Escribe <code>[akb_envio_gratis]</code> donde quieras el monto del envío gratis y saldrá siempre el del panel.<br>'
					. '<strong>Para que un mensaje lleve a una página</strong>, termina la línea con una barra y la dirección: '
					. '<code>Retiro sin costo en Metro San Miguel | /envios/</code>. La dirección no cuenta para el límite y no se ve: '
					. 'el cliente ve el mensaje subrayado y, al tocarlo, llega ahí. Sin barra, el mensaje es sólo texto y no se puede tocar.',
				'id'      => self::OPTION_MESSAGES,
				'type'    => 'textarea',
				'default' => '',
				'css'     => 'width:420px;height:130px;font-size:13px;',
				'custom_attributes' => array( 'data-akb-line-max' => self::LINE_MAX ),
			),
			array(
				'title'   => 'Rotar sola entre los mensajes',
				'desc'    => 'Con dos o más mensajes, la barra los va cambiando. Apagada, el cliente los pasa con la flecha. Con «reducir movimiento» activado en el sistema del cliente, nunca rota sola.',
				'id'      => self::OPTION_ROTATE,
				'type'    => 'checkbox',
				'default' => 'yes',
			),
			array(
				'title'             => 'Cada cuántos segundos cambia',
				'desc'              => 'Entre 3 y 30 segundos. De fábrica: 6.',
				'id'                => self::OPTION_ROTATE_SECS,
				'type'              => 'number',
				'default'           => 6,
				'custom_attributes' => array(
					'min'  => 3,
					'max'  => 30,
					'step' => 1,
				),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'akibara_announcement_section',
			),
			array(
				'title' => 'Anuncio promocional (conectado a Akibara → Descuentos)',
				'type'  => 'title',
				'desc'  => 'Aparece y desaparece automáticamente usando exclusivamente las fechas y reglas configuradas en <strong>Akibara → Descuentos</strong>.',
				'id'    => 'akibara_announcement_promo_section',
			),
			array(
				'title'   => 'Mensaje promocional (con vigencia)',
				'desc'    => 'Se pinta ANTES de los mensajes fijos mientras la campaña de <strong>Akibara → Descuentos</strong> esté vigente y desaparece solo al terminar. '
					. 'Mismo límite de ~' . self::LINE_MAX . ' caracteres por línea. Vacíalo para mostrar sólo los fijos.<br>'
					. 'Tokens en vivo de Descuentos: <code>[akb_descuento_pct]</code>, <code>[akb_descuento_editorial]</code>, <code>[akb_descuento_url]</code> (enlaza al catálogo/marca ganador respetando disponibilidad) y <code>[akb_descuento_vigencia]</code>.<br>'
					. '<strong>Ejemplo recomendado:</strong> <code>¡Semana [akb_descuento_editorial]: hasta [akb_descuento_pct]! | [akb_descuento_url]</code>.<br>'
					. $this->promo_status_text()
					. $this->discount_dates_status_text(),
				'id'      => self::OPTION_PROMO_TEXT,
				'type'    => 'textarea',
				'default' => '',
				'css'     => 'width:420px;height:80px;font-size:13px;',
				'custom_attributes' => array( 'data-akb-line-max' => self::LINE_MAX ),
			),
			array(
				'title'   => 'Cuenta regresiva (Countdown Timer)',
				'desc'    => 'Muestra un reloj Neo-Brutalista en vivo junto al mensaje promocional hasta la fecha límite («Vigencia hasta») definida en <strong>Akibara → Descuentos</strong>.'
					. $this->countdown_status_text(),
				'id'      => self::OPTION_COUNTDOWN_ENABLED,
				'type'    => 'checkbox',
				'default' => 'no',
			),
			array(
				'title'             => 'Hora de cierre de la cuenta regresiva',
				'desc'              => 'Hora exacta del último día en formato 24h Chile (HH:MM, entre 00:00 y 23:59). Por defecto 23:59.',
				'id'                => self::OPTION_COUNTDOWN_TIME,
				'type'              => 'text',
				'placeholder'       => PromoSchedule::DEFAULT_CUTOFF_HM,
				'default'           => PromoSchedule::DEFAULT_CUTOFF_HM,
				'css'               => 'width:80px;',
				'custom_attributes' => array(
					'pattern'   => '([01]\d|2[0-3]):[0-5]\d',
					'maxlength' => '5',
					'inputmode' => 'numeric',
				),
			),
			array(
				'title'       => 'Texto previo del contador',
				'desc'        => 'Texto breve que acompaña al reloj (ej. «Termina en», «Quedan»). Puedes dejarlo vacío.',
				'id'          => self::OPTION_COUNTDOWN_LABEL,
				'type'        => 'text',
				'placeholder' => 'Termina en',
				'default'     => 'Termina en',
				'css'         => 'width:180px;',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'akibara_announcement_promo_section',
			),
		);
	}
}
