<?php
/**
 * SiteSpeed — pestaña «Velocidad» del panel: recalentado nocturno de las fichas,
 * aviso de precios a Cloudflare, etiqueta de Google Ads, GA4 y Clarity después
 * de la carga, el carrito sin lo que sólo usa la página de pago y el detalle de
 * los errores de los scripts de terceros.
 *
 * Contexto y mediciones en `ops/informe-rendimiento-2026-09-15.md`.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\SiteSpeed;

use Akibara\Business\Modules\AdminPanel\AdminPanel;
use Akibara\Business\Modules\ScriptDiagnostics\CrossOriginScripts;

defined( 'ABSPATH' ) || exit;

final class SiteSpeed {

	/** Pestaña propia del panel. */
	public const PANEL_TAB_ID = 'velocidad';

	/** Acción de `admin-post.php` que recalienta ahora. */
	private const ADMIN_ACTION = 'akibara_cache_warm_now';

	/**
	 * Expone el alcance del interruptor de retraso de Analytics.
	 *
	 * GlobalSiteTag de Google for WooCommerce imprime gtag.js de Ads sin pasar por
	 * wp_script_attributes. Esa etiqueta puede procesar el config de GA4 en la cola
	 * compartida y cargar Analytics antes de load. Retrasar Analytics no implica
	 * retrasar Ads; la activación de conversiones tiene un ajuste separado.
	 */
	public const ADS_CAVEAT = 'Si además cargas la etiqueta de Google Ads (más abajo), Google Analytics no espera: la etiqueta de Ads usa el mismo programa de Google (gtag.js), se descarga apenas se abre la página y trae Analytics con ella. Clarity sí sigue esperando.';

	/** El mismo aviso, cuando las dos están encendidas a la vez. */
	public const ADS_WARNING = 'Tienes encendidos «Cargar Google Analytics y Clarity después de la carga» y la etiqueta de Google Ads. Con las dos, Google Analytics se descarga al abrir la página, junto con la etiqueta de Ads; sólo Clarity espera a que termine de cargar. Para que Analytics también espere, apaga la etiqueta de Google Ads mientras no tengas campañas.';

	private AdminPanel $panel;

	private CacheWarmer $warmer;

	private SeriesCacheTags $tags;

	private AnalyticsAfterLoad $analytics;

	private LeanCart $lean_cart;

	public function __construct( AdminPanel $panel, CacheWarmer $warmer, SeriesCacheTags $tags, AnalyticsAfterLoad $analytics, LeanCart $lean_cart ) {
		$this->panel     = $panel;
		$this->warmer    = $warmer;
		$this->tags      = $tags;
		$this->analytics = $analytics;
		$this->lean_cart = $lean_cart;
	}

	public function register(): void {
		$this->warmer->register();
		$this->tags->register();
		$this->analytics->register();
		$this->lean_cart->register();
		add_action( 'init', array( $this, 'register_admin_tab' ) );
		add_action( 'admin_post_' . self::ADMIN_ACTION, array( $this, 'handle_warm_now' ) );
	}

	/**
	 * @internal Callback de `init`.
	 */
	public function register_admin_tab(): void {
		$this->panel->add_section(
			self::PANEL_TAB_ID,
			'Velocidad',
			array( $this, 'get_settings' ),
			array( $this, 'render_admin_section' ),
			false,
			'',
			'sitio'
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function get_settings(): array {
		$campos = array(
			array(
				'title' => 'Fichas listas antes del primer cliente',
				'type'  => 'title',
				'id'    => 'akb_site_speed_settings',
				'desc'  => 'Cada noche, desde las 00:20 y hasta las 06:00, el servidor abre todas las fichas de producto, primero las que más se venden, y las deja guardadas hasta la medianoche siguiente. Así, quien entra desde Google recibe la ficha en una fracción de segundo en vez de esperar a que se arme. Computador y celular comparten la misma copia. Si a las 06:00 no terminó, para: de día el servidor es de los clientes.',
			),
			array(
				'title'   => 'Recalentar las fichas cada noche',
				'id'      => CacheWarmer::OPTION_ENABLED,
				'type'    => 'checkbox',
				'desc'    => 'Encendido',
				'default' => 'yes',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'akb_site_speed_settings',
			),
		);

		$campos[] = array(
			'title' => 'Precios al día en el borde',
			'type'  => 'title',
			'id'    => 'akb_site_speed_edge',
			'desc'  => 'Cuando cambias el precio o el stock de un tomo, la tienda borra su ficha y las de su serie. Este control también avisa a Cloudflare para que actualice su copia de esas páginas. Apágalo únicamente si Cloudflare empieza a rechazar los avisos.',
		);
		$campos[] = array(
			'title'   => 'Avisar a Cloudflare al cambiar precios o stock',
			'id'      => EdgePurge::ENABLED_OPTION,
			'type'    => 'checkbox',
			'desc'    => 'Encendido',
			'default' => 'yes',
		);
		$campos[] = array(
			'type' => 'sectionend',
			'id'   => 'akb_site_speed_edge',
		);

		// Este interruptor existía desde 1.108.26 y sólo se podía apagar por
		// línea de comandos, siendo que su propio módulo advierte que marcar un
		// host que no devuelve la cabecera correcta BLOQUEA su script —en la
		// página de pago, eso es quedarse sin cobrar—. Un apagado de emergencia
		// que exige un técnico no es un apagado de emergencia.
		$campos[] = array(
			'title' => 'Detalle de los errores de terceros',
			'type'  => 'title',
			'id'    => 'akb_site_speed_crossorigin',
			'desc'  => 'Cuando falla un script de otra empresa —Mercado Pago, Google, Clarity— el navegador sólo informa «Script error.», sin decir qué pasó ni dónde. Con esto encendido se le pide el detalle a esos servicios, que fueron comprobados uno por uno. No cambia nada de lo que ve el cliente ni arregla ningún error: hace que se puedan ver. Apágalo si un pago o una medición deja de funcionar justo después de que uno de esos servicios cambie algo por su lado; vuelve a la normalidad en la siguiente visita.',
		);
		$campos[] = array(
			'title'   => 'Pedir el detalle de los errores de terceros',
			'id'      => CrossOriginScripts::OPTION_ENABLED,
			'type'    => 'checkbox',
			'desc'    => 'Encendido',
			'default' => 'yes',
		);
		$campos[] = array(
			'type' => 'sectionend',
			'id'   => 'akb_site_speed_crossorigin',
		);

		// Apagado por defecto: diferir medición reduce competencia de carga pero
		// puede omitir visitas que salen antes. El dueño decide esa relación.

		$campos[] = array(
			'title' => 'Google Analytics y Clarity después de la carga',
			'type'  => 'title',
			'id'    => 'akb_site_speed_analytics',
			'desc'  => 'Google Analytics (188 KB) y Clarity se descargan hoy al mismo tiempo que la portada del tomo, y le quitan ancho de banda justo cuando el cliente espera verla. Encendido, esperan a que la página termine de cargar —o al primer toque, tecla o desplazamiento del cliente— y recién ahí se descargan. Medido en un celular de prueba con red lenta: en la ficha de un tomo la portada aparece unos 0,3 segundos antes y la página termina de cargar 1,3 segundos antes; en el catálogo la primera portada ya ganaba y no cambia. El precio: quien se va antes de que la página termine de cargar deja de contar en Analytics y en Clarity. Los eventos de compra de Clarity (agregar al carrito, pagar) siguen midiéndose: esperan en cola hasta que llega su etiqueta.'
				// La etiqueta Ads impresa en crudo no pasa por el filtro de retraso y puede
				// procesar también Analytics en la cola compartida. El panel expone ese límite.

				. ( GoogleAdsTag::glue_active() ? ' ' . self::ADS_CAVEAT : '' ),
		);
		$campos[] = array(
			'title'   => 'Cargar Google Analytics y Clarity después de la carga',
			'id'      => AnalyticsAfterLoad::OPTION,
			'type'    => 'checkbox',
			'desc'    => 'Encendido',
			'default' => 'no',
		);
		$campos[] = array(
			'type' => 'sectionend',
			'id'   => 'akb_site_speed_analytics',
		);

		// LeanCart evita ejecutar recursos de pago en carrito y los precarga para
		// checkout desde el inicio de carga. El ajuste sigue disponible para requisitos
		// del proveedor; no debe prometer que los recursos dejan de descargarse.

		if ( LeanCart::glue_active() ) {
			$campos[] = array(
				'title' => 'El carrito, sin lo que sólo usa la página de pago',
				'type'  => 'title',
				'id'    => 'akb_site_speed_lean_cart',
				'desc'  => 'Mercado Pago carga en el carrito su programa de pago con tarjeta, su verificación antifraude y sus estilos, aunque en el carrito no se paga: todo eso lo usa la página de pago. Encendido, el carrito no los ejecuta: los descarga en segundo plano desde que empieza a cargar, para que la página de pago los encuentre ya descargados aunque el cliente pase a pagar antes de que el carrito termine de cargar, igual que con el interruptor apagado. Medido en producción el 26 de septiembre de 2026, en un celular lento simulado: el tiempo en que el carrito no responde al dedo mientras carga baja de 1,8 a 0,5 segundos. Lo que descarga y ejecuta la página de pago es lo mismo con el interruptor encendido o apagado, y la verificación antifraude que viaja con el pago la hace ella. Apágalo sólo si Mercado Pago te pide tener su programa también en el carrito.',
			);
			$campos[] = array(
				'title'    => 'Quitar del carrito lo que sólo usa la página de pago',
				'id'       => LeanCart::OPTION,
				'type'     => 'checkbox',
				'desc'     => 'Encendido',
				'default'  => LeanCart::DEFAULT,
				// Sólo se lee en /carrito/: no tiene por qué viajar en `alloptions`
				// de cada página. La calienta `Plugin::FRONT_READ_OPTIONS`.
				'autoload' => false,
			);
			$campos[] = array(
				'type' => 'sectionend',
				'id'   => 'akb_site_speed_lean_cart',
			);
		}

		if ( GoogleAdsTag::glue_active() ) {
			$campos[] = array(
				'title' => 'Etiqueta de Google Ads',
				'type'  => 'title',
				'id'    => 'akb_site_speed_ads',
				'desc'  => 'Google for WooCommerce la usa para medir las compras que llegan desde campañas de Google Ads. Pesa 146 KB y se carga en todas las páginas. Apágala mientras no tengas campañas y enciéndela antes de lanzar una. Google Shopping y el catálogo de Merchant Center no dependen de ella.',
			);
			$campos[] = array(
				'title'   => 'Cargar la etiqueta de Google Ads',
				'id'      => GoogleAdsTag::OPTION,
				'type'    => 'checkbox',
				'desc'    => 'Encendida',
				'default' => 'no',
			);
			$campos[] = array(
				'type' => 'sectionend',
				'id'   => 'akb_site_speed_ads',
			);
		}

		return $campos;
	}

	/**
	 * @internal Callback de `admin_post_akibara_cache_warm_now`.
	 */
	public function handle_warm_now(): void {
		if ( ! current_user_can( AdminPanel::PANEL_CAP ) ) {
			wp_die( 'Sin permisos.', 'akibara', array( 'response' => 403 ) );
		}

		check_admin_referer( self::ADMIN_ACTION );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'            => 'akibara-panel',
					'tab'             => self::PANEL_TAB_ID,
					'akb_recalentado' => $this->warmer->start_manual(),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Estado del recalentado y su botón.
	 *
	 * @internal Callback de `AdminPanel::add_section($header_fn)`.
	 */
	public function render_admin_section(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- bandera de sólo lectura puesta por nuestro propio wp_safe_redirect.
		$resultado = isset( $_GET['akb_recalentado'] ) ? sanitize_key( wp_unslash( (string) $_GET['akb_recalentado'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$aviso = match ( $resultado ) {
			'encolada'    => array( 'success', 'Recalentado encolado. Lo hace el servidor en segundo plano, de a poco: vuelve a esta pantalla para ver cuánto lleva.' ),
			'ya-en-curso' => array( 'info', 'Ya hay un recalentado en curso; no se empezó otro.' ),
			'apagado'     => array( 'error', 'El recalentado está apagado. Enciéndelo y guarda antes de pedirlo.' ),
			'sin-cola'    => array( 'error', 'No se pudo encolar: falta la cola de tareas de WooCommerce (Action Scheduler).' ),
			'sin-fichas'  => array( 'info', 'No hay fichas publicadas que recalentar.' ),
			default       => null,
		};
		?>
		<h2>Estado</h2>

		<?php if ( null !== $aviso ) : ?>
			<div class="notice notice-<?php echo esc_attr( $aviso[0] ); ?> is-dismissible"><p><?php echo esc_html( $aviso[1] ); ?></p></div>
		<?php endif; ?>

		<?php $cruce = self::analytics_ads_warning( GoogleAdsTag::glue_active() && GoogleAdsTag::enabled(), AnalyticsAfterLoad::enabled() ); ?>
		<?php if ( '' !== $cruce ) : ?>
			<div class="notice notice-warning inline"><p><?php echo esc_html( $cruce ); ?></p></div>
		<?php endif; ?>

		<p><?php echo esc_html( self::describe_state( CacheWarmer::state(), CacheWarmer::enabled() ) ); ?></p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( self::ADMIN_ACTION ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ADMIN_ACTION ); ?>">
			<button type="submit" class="button">Recalentar las fichas ahora</button>
		</form>
		<p class="description">Úsalo después de un cambio grande de precios o de un despliegue, que vacían la caché. Trabaja de a poco y a cualquier hora.</p>
		<?php
	}

	/**
	 * Aviso de la pestaña cuando la etiqueta de Ads deshace el retraso de
	 * Analytics, o '' si no hay nada que avisar.
	 *
	 * @param bool $ads_tag_loads        Google for WooCommerce está y la etiqueta de Ads se carga.
	 * @param bool $analytics_after_load El interruptor de Analytics y Clarity está encendido.
	 */
	public static function analytics_ads_warning( bool $ads_tag_loads, bool $analytics_after_load ): string {
		return $ads_tag_loads && $analytics_after_load ? self::ADS_WARNING : '';
	}

	/**
	 * El estado en una frase para el dueño.
	 *
	 * @param array<string, int|string> $estado  Estado de {@see CacheWarmer::state()}.
	 * @param bool                      $enabled Si el recalentado está encendido.
	 */
	public static function describe_state( array $estado, bool $enabled ): string {
		$prefijo = $enabled ? '' : 'Apagado. ';
		if ( 0 === (int) $estado['started'] ) {
			return $prefijo . 'Todavía no se ha recalentado ninguna ficha.';
		}

		$abierta = 0 === (int) $estado['finished'];
		$cuando  = wp_date( 'd/m/Y H:i', (int) ( $abierta ? $estado['started'] : $estado['finished'] ) );
		$cabeza  = $abierta
			? sprintf( 'En curso desde el %s: %s de %s fichas', $cuando, number_format_i18n( (int) $estado['cursor'] ), number_format_i18n( (int) $estado['total'] ) )
			: sprintf(
				'%s el %s: %s de %s fichas',
				1 === (int) $estado['cortada'] ? sprintf( 'Se cortó a las %02d:00', CacheWarmer::END_HOUR ) : 'Última pasada terminada',
				$cuando,
				number_format_i18n( (int) $estado['cursor'] ),
				number_format_i18n( (int) $estado['total'] )
			);

		$frase = sprintf(
			'%s%s · %s guardadas ahora · %s ya estaban guardadas · %s no se guardan (redirección o sin caché)',
			$prefijo,
			$cabeza,
			number_format_i18n( (int) $estado['calentadas'] ),
			number_format_i18n( (int) $estado['ya_calientes'] ),
			number_format_i18n( (int) $estado['sin_cache'] )
		);
		if ( (int) $estado['errores'] > 0 ) {
			$frase .= sprintf( ' · %s con error. Último: %s', number_format_i18n( (int) $estado['errores'] ), (string) $estado['ultimo_error'] );
		}

		return $frase . '.';
	}
}
