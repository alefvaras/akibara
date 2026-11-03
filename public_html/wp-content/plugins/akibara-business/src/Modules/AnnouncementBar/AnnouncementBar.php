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

use Akibara\Business\Utilities\PluginAssetVersion;

use Akibara\Business\Modules\DiscountCopy\DiscountCopy;
use Akibara\Business\Modules\ShippingCopy\ShippingCopy;

defined( 'ABSPATH' ) || exit;

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
	private const OPTION_BG       = 'akibara_announcement_bg';
	private const OPTION_COLOR    = 'akibara_announcement_color';
	private const OPTION_BORDER   = 'akibara_announcement_border';

	/**
	 * Controles de rotación automática. Sus valores iniciales mantienen la
	 * rotación activada cada seis segundos; el dueño puede cambiarlos en el panel.
	 */
	private const OPTION_ROTATE      = 'akibara_announcement_rotate';
	private const OPTION_ROTATE_SECS = 'akibara_announcement_rotate_secs';

	/**
	 * Carril promocional con vigencia propia.
	 *
	 * Los mensajes permanentes comunican propuestas fijas (envío gratis, retiro en tienda).
	 * El carril promocional vincula su vigencia temporal automáticamente para evitar que
	 * promociones vencidas continúen anunciándose en la tienda.
	 */
	private const OPTION_PROMO_TEXT            = 'akibara_announcement_promo_text';
	private const OPTION_PROMO_FROM            = 'akibara_announcement_promo_from';
	private const OPTION_PROMO_TO              = 'akibara_announcement_promo_to';
	private const OPTION_PROMO_FOLLOW_DISCOUNT = 'akibara_announcement_promo_follow_discount';

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
	 * `translateX` de la animación de entrada). Ese test compara regla a regla:
	 * la copia a mano ya había derivado —el anillo de foco decía `#FFD60A`
	 * mientras la hoja y las otras 48 apariciones del token dicen `#FFD700`—, y
	 * una divergencia así no se ve mirando, sólo midiendo.
	 */
	private const CRITICAL_CSS =
		'#akb-ann-bar{min-height:48px;display:flex;align-items:center;justify-content:center;position:relative;padding:2px 16px;box-sizing:border-box;overflow:hidden;background:transparent}'
		. '#akb-ann-bar.akb-hidden{display:none!important}'
		. '#akb-ann-bar.akb-ann-bar--multi{display:grid;grid-template-columns:60px minmax(0,1fr) 60px}'
		. '.akb-ann-content{display:grid;grid-template-columns:minmax(0,1fr);grid-template-rows:1fr;width:100%;min-width:0}'
		. '.akb-ann-bar--multi .akb-ann-content{grid-column:2;width:100%;max-width:none}'
		. '.akb-ann-msg{grid-column:1/-1;grid-row:1/-1;justify-self:center;align-self:center;display:inline-flex;align-items:center;justify-content:center;position:relative;max-width:100%;font-family:var(--font-heading,"Inter",sans-serif);font-style:normal;font-synthesis:none;transform:skewX(-5deg);font-size:15px;font-weight:400;text-transform:uppercase;letter-spacing:.04em;padding:6px 20px;color:var(--akb-ann-color,#fff);pointer-events:none}'
		. '.akb-ann-msg::before{content:"";position:absolute;inset:0;box-shadow:-4px 4px 0 var(--akb-ann-border,#D90010);transform:skew(-12deg);opacity:0;visibility:hidden;z-index:-1}'
		. '.akb-ann-msg::after{content:"";position:absolute;inset:0;background:var(--akb-ann-bg,#0a0a0a);transform:skew(-12deg);opacity:0;visibility:hidden;z-index:0}'
		. '.akb-ann-msg-inner{position:relative;z-index:1;transform:skew(4deg);opacity:0;visibility:hidden;display:inline-block;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}'
		. '.akb-ann-msg.akb-on::before{opacity:1;visibility:visible}'
		. '.akb-ann-msg.akb-on::after{opacity:1;visibility:visible;transform:skew(-12deg)}'
		. '.akb-ann-msg.akb-on .akb-ann-msg-inner{opacity:1;visibility:visible;transform:skew(4deg);pointer-events:auto}'
		. '.akb-ann-msg--link{padding:0}'
		. '.akb-ann-msg--link .akb-ann-msg-inner{box-sizing:border-box;padding:6px 20px;color:inherit;text-decoration:underline;text-decoration-thickness:1px;text-underline-offset:3px;cursor:pointer}'
		. '#akb-ann-bar .akb-ann-next{grid-column:3;flex:0 0 60px;width:60px;height:44px;margin-left:0;padding:0;border:0;background:transparent;color:var(--akb-ann-color,#fff);display:flex;align-items:center;justify-content:center;gap:6px;opacity:.74;appearance:none;-webkit-appearance:none;transition:opacity .18s ease,color .18s ease!important;will-change:auto}'
		. '#akb-ann-bar .akb-ann-next:hover,#akb-ann-bar .akb-ann-next:focus{background:transparent;box-shadow:none;transform:none}'
		. '#akb-ann-bar .akb-ann-next:active{background:transparent!important;box-shadow:none!important;transform:none!important}'
		. '#akb-ann-bar .akb-ann-next:focus-visible{outline:var(--aki-focus-outline,3px solid #ffffff);outline-offset:-4px}'
		. '.akb-ann-next__count{font-family:var(--font-heading,"Inter",sans-serif);font-size:11px;font-weight:400;line-height:1;letter-spacing:.06em}'
		. '.akb-ann-next__arrow{width:8px;height:8px;border-top:2px solid currentColor;border-right:2px solid currentColor;transform:rotate(45deg)}'
		. '.akb-ann-status{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}'
		. '@media(prefers-reduced-motion:reduce){#akb-ann-bar .akb-ann-next{transition:none!important}}'
		. '@media(max-width:768px){#akb-ann-bar{min-height:44px;padding:0 8px}#akb-ann-bar.akb-ann-bar--multi{grid-template-columns:56px minmax(0,1fr) 56px}.akb-ann-bar--multi .akb-ann-content{max-width:none}.akb-ann-msg{font-size:12px;letter-spacing:.03em;padding:3px 8px}.akb-ann-msg--link{padding:0}.akb-ann-msg--link .akb-ann-msg-inner{padding:3px 8px 0;border-block:12px solid transparent;border-block-end-width:15px;margin:-12px 0}#akb-ann-bar .akb-ann-msg-inner{display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:2;line-clamp:2;white-space:normal;line-height:1.3}#akb-ann-bar .akb-ann-next{flex-basis:56px;width:56px;margin-left:0}}';

	private ?array $messages = null;

	/** Dedup por petición: N escrituras de esta pestaña programan UN purge. */
	private bool $purge_scheduled = false;

	public function register(): void {
		if ( ! is_admin() ) {
			add_action( 'wp', array( $this, 'maybe_init_bar' ) );
		}
		add_filter( 'akibara_panel_options_saved_with_warning', array( $this, 'report_saved_with_warning' ), 10, 2 );

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
	 * Todas las options cuyo valor pinta HTML cacheado de esta pieza.
	 *
	 * @return array<int, string>
	 */
	private function purgeable_options(): array {
		return array(
			self::OPTION_ENABLED,
			self::OPTION_MESSAGES,
			self::OPTION_BG,
			self::OPTION_COLOR,
			self::OPTION_BORDER,
			self::OPTION_PROMO_TEXT,
			self::OPTION_PROMO_FROM,
			self::OPTION_PROMO_TO,
			self::OPTION_PROMO_FOLLOW_DISCOUNT,
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
		add_action( 'shutdown', array( $this, 'do_purge' ), 99 );
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

	public function maybe_init_bar(): void {
		if ( 'yes' !== get_option( self::OPTION_ENABLED, 'yes' ) ) {
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

		if ( 1 < count( $this->get_messages() ) ) {
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
	 * Un color de verdad, o el de fábrica.
	 *
	 * Acepta exactamente lo que ofrece el selector del panel: `#rgb` o `#rrggbb`.
	 * No usa `sanitize_hex_color()` a propósito —es una función del admin y esto
	 * corre en cada página del sitio—: la regla cabe en una expresión y así la
	 * pieza no depende de qué haya cargado WordPress en ese momento.
	 *
	 * Pura para poder probarla sin montar WordPress.
	 *
	 * @param mixed  $valor       Lo guardado en la option.
	 * @param string $por_defecto Color de fábrica de ese hueco.
	 */
	public static function color_o_por_defecto( $valor, string $por_defecto ): string {
		$valor = is_scalar( $valor ) ? trim( (string) $valor ) : '';

		return 1 === preg_match( '/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i', $valor ) ? $valor : $por_defecto;
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
		// Valida los colores al leerlos y los escapa una sola vez al imprimir style.
		// Valores inválidos heredan los colores de fábrica. El fondo inicial #0a0a0a
		// coincide con CRITICAL_CSS y announcement-bar.css, evitando discrepancias
		// entre el fallback CSS y la variable declarada.
		$bg     = self::color_o_por_defecto( get_option( self::OPTION_BG, '' ), '#0a0a0a' );
		$color  = self::color_o_por_defecto( get_option( self::OPTION_COLOR, '' ), '#ffffff' );
		$border = self::color_o_por_defecto( get_option( self::OPTION_BORDER, '' ), '#D90010' );

		// CSS estático de esta clase, sin un solo dato de entrada: escaparlo
		// rompería el propio CSS (`>` en los selectores, comillas de las fuentes).
		echo '<style id="akb-ann-bar-critical" data-no-optimize="1" data-no-defer="1">' . self::CRITICAL_CSS . '</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- self::CRITICAL_CSS es una constante de clase con CSS literal, sin un solo dato de entrada; escaparla rompería los «>» de los selectores y las comillas de las fuentes.

		$bar_class = $has_multiple ? 'akb-ann-bar akb-ann-bar--multi' : 'akb-ann-bar';
		echo '<div id="akb-ann-bar" class="' . esc_attr( $bar_class ) . '" style="--akb-ann-bg:' . esc_attr( $bg ) . ';--akb-ann-color:' . esc_attr( $color ) . ';--akb-ann-border:' . esc_attr( $border ) . ';"'
			. ' data-rotate="' . ( self::rotates() ? '1' : '0' ) . '" data-rotate-ms="' . esc_attr( (string) ( self::rotate_seconds() * 1000 ) ) . '"'
			. ' role="region" aria-label="' . esc_attr( 'Anuncios de la tienda' ) . '">' . "\n";
		echo '<div id="akb-ann-content" class="akb-ann-content">' . "\n";
		$allowed_message_html = array(
			'a'      => array(
				'href'   => array(),
				'target' => array(),
			),
			'strong' => array(),
			'em'     => array(),
		);
		// Dentro de un mensaje-enlace no puede ir otro <a>: anidados son HTML
		// inválido y el navegador los parte en dos.
		$allowed_in_link = array_diff_key( $allowed_message_html, array( 'a' => true ) );
		foreach ( $messages as $i => $msg ) {
			$linea         = self::split_link( $msg );
			$message_class = 0 === $i ? 'akb-ann-msg akb-on' : 'akb-ann-msg';
			if ( '' !== $linea['url'] ) {
				$message_class .= ' akb-ann-msg--link';
			}
			echo '<span class="' . esc_attr( $message_class ) . '"';
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
		// Las tres formas en la misma expresión (la misma de admin-panel.js): si
		// la dirección lleva un «|» propio, la expresión retrocede hasta la barra
		// que la separa del texto, como hacía antes.
		$url = 1 === preg_match( '~^(.*\S)\s*\|\s*(/(?!/)\S*|https?://\S+|(?:[a-z0-9-]+\.)+[a-z]{2,}(?:[/?#]\S*)?)\s*$~iu', $line, $m ) ? self::link_target( $m[2] ) : '';
		if ( '' === $url ) {
			return array(
				'text' => $line,
				'url'  => '',
			);
		}

		return array(
			'text' => $m[1],
			'url'  => $url,
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
			if ( false === $barra || '' !== self::split_link( $linea )['url'] ) {
				continue;
			}
			$tras = trim( substr( $linea, $barra + 1 ) );
			// Una ruta con espacios sigue siendo un intento; lo demás, sólo si es
			// UNA pieza sin espacios («Instagram: @akibara» es texto).
			$parece = '' === $tras
				|| '/' === $tras[0]
				|| ( 1 !== preg_match( '/\s/u', $tras )
					&& ( 1 === preg_match( '~^[a-z][a-z0-9+.-]*:|\.[a-z]{2,}~i', $tras ) || 1 === preg_match( '~^[a-z0-9-]+$~', $tras ) ) );
			if ( $parece ) {
				$avisos[ $i + 1 ] = array(
					'tras'      => $tras,
					'sin_texto' => '' === trim( substr( $linea, 0, $barra ) ),
				);
			}
		}

		return $avisos;
	}

	/**
	 * Avisa, sin bloquear, de las líneas con un enlace que no se reconoció.
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
			foreach ( self::unrecognized_links( (string) get_option( $option, '' ) ) as $numero => $linea ) {
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
				$avisos[ $option ] = ucfirst( implode( '; ', $partes ) ) . '. El cliente ve ese final como texto, con la barra. Para enlazar, escribe la dirección empezando por / (una página de la tienda, como /envios/) o por https://, sin espacios.';
			}
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

		$raw = ShippingCopy::resolve_in( $raw );
		$raw = DiscountCopy::resolve_in( $raw );

		return array_values( array_filter( array_map( 'trim', explode( "\n", $raw ) ) ) );
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

		return self::split_lines( (string) get_option( self::OPTION_PROMO_TEXT, '' ) );
	}

	/**
	 * ¿El mensaje promocional se muestra AHORA?
	 *
	 * Público porque el panel pinta con él la línea de estado: el dueño tiene
	 * que poder leer en la misma pantalla si lo que acaba de escribir se está
	 * viendo o no, en vez de ir a la portada a adivinarlo.
	 */
	public function promo_active(): bool {
		if ( '' === trim( (string) get_option( self::OPTION_PROMO_TEXT, '' ) ) ) {
			return false;
		}

		// Atado a la campaña: la vigencia NO se reimplementa acá, la decide el
		// módulo de Descuentos —el mismo booleano que gobierna el precio—, así
		// que el banner y la ficha no pueden discrepar.
		if ( 'yes' === get_option( self::OPTION_PROMO_FOLLOW_DISCOUNT, 'no' ) ) {
			return DiscountCopy::campaign_active();
		}

		return self::within_window(
			(string) get_option( self::OPTION_PROMO_FROM, '' ),
			(string) get_option( self::OPTION_PROMO_TO, '' )
		);
	}

	/**
	 * Ventana inclusiva en los DOS extremos, comparada como `Y-m-d` en la hora
	 * del sitio. Misma semántica exacta que `TaxonomyDiscount\Settings`: el
	 * dueño escribe las dos fechas en pantallas distintas y no tiene por qué
	 * recordar que una incluye el último día y la otra no.
	 *
	 * @param string $from Fecha de inicio, o ''.
	 * @param string $to   Fecha de término, o ''.
	 */
	private static function within_window( string $from, string $to ): bool {
		if ( '' === $from && '' === $to ) {
			return true;
		}

		$hoy = function_exists( 'current_time' ) ? (string) current_time( 'Y-m-d' ) : gmdate( 'Y-m-d' );
		if ( '' === $hoy ) {
			return false;
		}

		if ( '' !== $from && $hoy < $from ) {
			return false;
		}
		if ( '' !== $to && $hoy > $to ) {
			return false;
		}

		return true;
	}

	/**
	 * Qué está pasando con el carril promocional, en una frase, para el panel.
	 */
	private function promo_status_text(): string {
		// El interruptor maestro «Activar» gobierna el renderizado global (`maybe_init_bar()`):
		// si está desactivado, ni los anuncios fijos ni los promocionales se renderizan en la tienda.
		if ( 'yes' !== get_option( self::OPTION_ENABLED, 'yes' ) ) {
			return 'Ahora mismo: <strong>oculto</strong> — la barra completa está desactivada (interruptor «Activar» de arriba), aunque este mensaje esté vigente.';
		}

		if ( '' === trim( (string) get_option( self::OPTION_PROMO_TEXT, '' ) ) ) {
			return 'Ahora mismo: <strong>no hay mensaje promocional</strong>; la barra muestra sólo los fijos.';
		}

		if ( $this->promo_active() ) {
			return 'Ahora mismo: <strong>se está viendo en la tienda</strong>.';
		}

		if ( 'yes' === get_option( self::OPTION_PROMO_FOLLOW_DISCOUNT, 'no' ) ) {
			return 'Ahora mismo: <strong>oculto</strong> — no hay ningún descuento por editorial vigente. Volverá solo cuando la campaña empiece.';
		}

		return 'Ahora mismo: <strong>oculto</strong> — hoy cae fuera de las fechas de abajo. El texto se conserva.';
	}

	public function get_settings(): array {
		return array(
			array(
				'title' => 'Barra de anuncios',
				'type'  => 'title',
				'desc'  => 'Mensajes promocionales navegables que aparecen sobre el header en todo el sitio. Acepta HTML básico (&lt;strong&gt;, &lt;em&gt;) y un enlace por mensaje (ver «Mensajes»).',
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
				// admin-panel.js pinta debajo un contador dinámico por línea con este
				// límite (self::LINE_MAX), orientando la redacción en móvil sin forzar bloqueo duro.
				'custom_attributes' => array( 'data-akb-line-max' => self::LINE_MAX ),
			),
			array(
				'title'   => 'Mensaje promocional (con vigencia)',
				// La línea de estado se calcula al pintar el panel, no al
				// guardar: el dueño abre esta pestaña días después de escribir
				// la promo y lo primero que necesita saber es si hoy se está
				// viendo. Sin esto, la única forma de averiguarlo es abrir la
				// portada en otra pestaña y mirar.
				'desc'    => 'Se pinta ANTES de los mensajes fijos y <strong>desaparece solo</strong> cuando termina la vigencia. '
					. 'Mismo límite de ~' . self::LINE_MAX . ' caracteres por línea, y mismo enlace opcional al final (<code>| /marca/ivrea-argentina/</code>). Vacíalo y la barra vuelve a mostrar sólo los fijos.<br>'
					. 'Acepta <code>[akb_envio_gratis]</code>, <code>[akb_descuento_pct]</code> y <code>[akb_descuento_editorial]</code>: '
					. 'estos dos últimos salen del módulo de Descuentos, así que si mañana bajas el porcentaje el banner se corrige solo.<br>'
					. $this->promo_status_text(),
				'id'      => self::OPTION_PROMO_TEXT,
				'type'    => 'textarea',
				'default' => '',
				'css'     => 'width:420px;height:80px;font-size:13px;',
				'custom_attributes' => array( 'data-akb-line-max' => self::LINE_MAX ),
			),
			array(
				'title'   => 'Seguir la vigencia del descuento',
				'desc'    => 'El mensaje aparece y desaparece junto con la campaña de <strong>Akibara → Descuentos</strong>, ignorando las fechas de abajo. '
					. 'Es la opción a prueba de olvidos: con esto marcado no existe el caso de un banner anunciando un descuento que ya terminó.',
				'id'      => self::OPTION_PROMO_FOLLOW_DISCOUNT,
				'type'    => 'checkbox',
				'default' => 'no',
			),
			array(
				'title'       => 'Mostrar desde (opcional)',
				'desc'        => 'Sólo se usan si la casilla de arriba está desmarcada. Con las dos fechas vacías el mensaje se muestra siempre, igual que uno fijo.',
				'desc_tip'    => true,
				'id'          => self::OPTION_PROMO_FROM,
				'type'        => 'date',
				'placeholder' => 'YYYY-MM-DD',
				'css'         => 'min-width:160px;',
			),
			array(
				'title'       => 'Hasta',
				'desc'        => 'Inclusive: el último día el mensaje todavía se ve, y desaparece al día siguiente.',
				'desc_tip'    => true,
				'id'          => self::OPTION_PROMO_TO,
				'type'        => 'date',
				'placeholder' => 'YYYY-MM-DD',
				'css'         => 'min-width:160px;',
			),
			array(
				'title'   => 'Fondo de la etiqueta (Cinta P5)',
				'id'      => self::OPTION_BG,
				'type'    => 'color',
				'default' => '#D90010',
			),
			array(
				'title'   => 'Color de texto',
				'id'      => self::OPTION_COLOR,
				'type'    => 'color',
				'default' => '#ffffff',
			),
			array(
				'title'   => 'Color de sombra (Estilo P5)',
				'desc'    => 'Color de la sombra sólida agresiva proyectada por la etiqueta.',
				'id'      => self::OPTION_BORDER,
				'type'    => 'color',
				'default' => '#D90010',
			),
			array(
				'title'   => 'Rotar sola entre los mensajes',
				// El Slider de la portada ya traía su casilla de «rotar solo» y
				// sus segundos; la barra rotaba sola sin que nadie pudiera
				// apagarla. Mismo mando para los dos (manual del dueño: «la barra
				// rota sola y el Slider no: criterios opuestos»).
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
		);
	}
}
