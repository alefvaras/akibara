<?php
/**
 * Invalida en Cloudflare las páginas afectadas por cambios de catálogo.
 *
 * SeriesCacheTags invalida origen y EdgePurge propaga las URLs al borde. Una
 * purga dirigida evita enfriar el sitio entero por cambiar un precio o stock.
 * Usa las credenciales de la integración LiteSpeed/Cloudflare; sin esa
 * configuración no opera. El TTL del borde y la caché del origen son capas
 * distintas y no sustituyen la invalidación explícita de cada página afectada.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\SiteSpeed;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.116.16
 */
final class EdgePurge {

	/** Interruptor del panel. */
	public const ENABLED_OPTION = 'akibara_edge_purge_enabled';

	/** Acción diferida que hace la llamada a Cloudflare. */
	public const HOOK = 'akibara_edge_purge_run';

	/** Grupo de Action Scheduler. */
	private const GROUP = 'akibara-business';

	/** Cloudflare admite 30 ficheros por llamada. */
	private const LOTE = 30;

	/**
	 * Tope de reintentos de una purga fallida.
	 *
	 * La respuesta puede fallar por transporte, servidor, JSON o autorización sin
	 * lanzar una excepción de Action Scheduler. El módulo agenda sus reintentos
	 * explícitamente y los acota para no continuar contra una caída permanente.
	 */
	private const MAX_REINTENTOS = 3;

	/** Segundos entre un fallo y su reintento: corto, porque el borde se cura solo a los 10 minutos. */
	private const REINTENTO_SEGUNDOS = 75;

	/**
	 * URLs acumuladas en esta petición.
	 *
	 * Se juntan y se mandan UNA vez al terminar: guardar diez productos seguidos
	 * son diez purgas de serie repetidas, y cada llamada a Cloudflare cuenta
	 * contra la cuota de la zona.
	 *
	 * @var array<string, true>
	 */
	private static array $pendientes = array();

	/** @var bool Si ya se enganchó el envío al final de la petición. */
	private static bool $enganchado = false;

	/**
	 * Cuelga la acción diferida.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( self::HOOK, array( self::class, 'run' ), 10, 2 );
	}

	/**
	 * ¿El carril está encendido?
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		return 'no' !== (string) get_option( self::ENABLED_OPTION, 'yes' );
	}

	/**
	 * Anota una URL para purgar en el borde.
	 *
	 * @param string $url URL absoluta del sitio.
	 * @return void
	 */
	public static function anotar( string $url ): void {
		$url = trim( $url );
		if ( '' === $url || ! self::is_enabled() ) {
			return;
		}

		// Sólo lo propio: una URL de otro dominio la rechazaría Cloudflare y
		// tumbaría el lote entero.
		if ( 0 !== strpos( $url, (string) home_url() ) ) {
			return;
		}

		self::$pendientes[ $url ] = true;

		if ( ! self::$enganchado ) {
			self::$enganchado = true;
			add_action( 'shutdown', array( self::class, 'despachar' ), 20 );
		}
	}

	/**
	 * Encola la purga al terminar la petición.
	 *
	 * Diferida a propósito: la llamada a Cloudflare son cientos de milisegundos
	 * contra una API externa, y esto corre mientras el dueño guarda un producto.
	 *
	 * @return void
	 */
	public static function despachar(): void {
		$urls             = array_keys( self::$pendientes );
		self::$pendientes = array();

		if ( array() === $urls || ! self::is_enabled() ) {
			return;
		}

		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			self::run( $urls );
			return;
		}

		as_enqueue_async_action( self::HOOK, array( $urls ), self::GROUP );
	}

	/**
	 * Purga en Cloudflare las URLs indicadas.
	 *
	 * @param array<int, string> $urls    URLs absolutas.
	 * @param int                $intento Reintentos ya hechos para ESTE lote (0 = primer intento).
	 *                                    Action Scheduler llama al hook con `array_values($args)`,
	 *                                    así que este es el segundo argumento posicional: nunca
	 *                                    renombrar sin revisar `despachar()` y el reintento de abajo.
	 * @return void
	 */
	public static function run( $urls, int $intento = 0 ): void {
		$urls = array_values( array_unique( array_filter( array_map( 'strval', (array) $urls ) ) ) );
		if ( array() === $urls || ! self::is_enabled() ) {
			return;
		}

		$acceso = self::credenciales();
		if ( null === $acceso ) {
			return;
		}

		// Se trocea por URL (no por prefijo ya calculado): un reintento necesita
		// volver a llamar a `run()` con las URLs de ESE lote, y `prefijos()` sólo
		// sabe leer URLs completas (con host), no el prefijo `host/ruta` que ya
		// perdió el esquema.
		foreach ( array_chunk( $urls, self::LOTE ) as $urls_del_lote ) {
			$prefijos_del_lote = self::prefijos( $urls_del_lote );
			if ( array() === $prefijos_del_lote ) {
				continue;
			}

			$respuesta = wp_remote_post(
				'https://api.cloudflare.com/client/v4/zones/' . rawurlencode( $acceso['zona'] ) . '/purge_cache',
				array(
					'timeout' => 15,
					'headers' => self::cabeceras( $acceso ),
					'body'    => (string) wp_json_encode( array( 'prefixes' => $prefijos_del_lote ) ),
				)
			);

			self::anotar_resultado( $respuesta, $urls_del_lote, $intento );
		}
	}

	/**
	 * Convierte URLs absolutas en prefijos host/ruta para invalidación dirigida.
	 *
	 * El prefijo omite esquema y conserva la ruta de la página. La purga alcanza
	 * esa página y sus descendientes sin vaciar el sitio completo ni depender de
	 * una única variante de cabeceras de la respuesta.
	 *
	 * @param array<int, string> $urls URLs absolutas.
	 * @return array<int, string>
	 */
	private static function prefijos( array $urls ): array {
		$prefijos = array();

		foreach ( $urls as $url ) {
			$partes = wp_parse_url( $url );
			if ( empty( $partes['host'] ) ) {
				continue;
			}

			$prefijos[] = $partes['host'] . ( $partes['path'] ?? '/' );
		}

		return array_values( array_unique( $prefijos ) );
	}

	/**
	 * Credenciales de Cloudflare que ya guardó LiteSpeed.
	 *
	 * @return array{zona:string,email:string,clave:string}|null
	 */
	private static function credenciales(): ?array {
		$zona  = (string) get_option( 'litespeed.conf.cdn-cloudflare_zone', '' );
		$email = (string) get_option( 'litespeed.conf.cdn-cloudflare_email', '' );
		$clave = (string) get_option( 'litespeed.conf.cdn-cloudflare_key', '' );

		// El correo sólo hace falta con la clave global; con token no se usa.
		if ( '' === $zona || '' === $clave ) {
			return null;
		}

		return array(
			'zona'  => $zona,
			'email' => $email,
			'clave' => $clave,
		);
	}

	/**
	 * Selecciona cabeceras según el formato de credencial de Cloudflare.
	 *
	 * Una Global API Key se envía con el correo; un token se envía como Bearer.
	 * Se usa el mismo criterio de formato que la integración de LiteSpeed:
	 * prefijo cfk_ o hexadecimal de37–45 caracteres para clave global, otro formato
	 * para token. La credencial no se imprime en diagnóstico.
	 *
	 * @param array{zona:string,email:string,clave:string} $acceso Credenciales.
	 * @return array<string, string>
	 */
	private static function cabeceras( array $acceso ): array {
		$es_clave_global = 0 === strncmp( $acceso['clave'], 'cfk_', 4 )
			|| 1 === preg_match( '/^[0-9a-f]{37,45}$/', $acceso['clave'] );

		if ( $es_clave_global ) {
			return array(
				'Content-Type' => 'application/json',
				'X-Auth-Email' => $acceso['email'],
				'X-Auth-Key'   => $acceso['clave'],
			);
		}

		return array(
			'Content-Type'  => 'application/json',
			'Authorization' => 'Bearer ' . $acceso['clave'],
		);
	}

	/**
	 * Registra la respuesta de purga y agenda recuperación acotada.
	 *
	 * El lote conserva URLs para reintentar, sin almacenar la credencial en el log.
	 * akibara_edge_purge_last.agotado sólo es verdadero cuando ya no queda un
	 * reintento pendiente. Un fallo transitorio recuperable no genera una alerta
	 * definitiva; health_alert() interpreta ese mismo estado.
	 *
	 * @param array<int, string>            $lote      URLs del lote (no prefijos: hacen falta para reintentar).
	 * @param array<string,mixed>|\WP_Error $respuesta Respuesta de wp_remote_post.
	 * @param int                           $intento   Reintentos ya hechos para este lote.
	 * @return void
	 */
	private static function anotar_resultado( $respuesta, array $lote, int $intento ): void {
		$ok     = false;
		$motivo = 'sin respuesta';

		if ( is_wp_error( $respuesta ) ) {
			$motivo = $respuesta->get_error_message();
		} else {
			$codigo = (int) wp_remote_retrieve_response_code( $respuesta );
			$cuerpo = json_decode( (string) wp_remote_retrieve_body( $respuesta ), true );
			$ok     = 200 === $codigo && is_array( $cuerpo ) && ! empty( $cuerpo['success'] );
			$motivo = $ok ? 'ok' : sprintf( 'HTTP %d', $codigo );
		}

		$queda_reintento = ! $ok && $intento < self::MAX_REINTENTOS;

		update_option(
			'akibara_edge_purge_last',
			array(
				'time'    => time(),
				'ok'      => $ok,
				'motivo'  => $motivo,
				'urls'    => count( $lote ),
				'intento' => $intento,
				// true sólo cuando el fallo YA agotó los reintentos: es la única
				// condición que enciende la alerta del panel.
				'agotado' => ! $ok && ! $queda_reintento,
			),
			false
		);

		if ( $ok ) {
			return;
		}

		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->warning(
				sprintf(
					'Purga de borde fallida (%s) para %d URL(s), intento %d de %d.',
					$motivo,
					count( $lote ),
					$intento + 1,
					self::MAX_REINTENTOS + 1
				),
				array( 'source' => 'akibara-edge-purge' )
			);
		}

		if ( $queda_reintento && function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action(
				time() + self::REINTENTO_SEGUNDOS,
				self::HOOK,
				array( $lote, $intento + 1 ),
				self::GROUP
			);
		}
	}

	/**
	 * Alerta para «Alertas del Sistema» (AdminDashboard) cuando la purga de
	 * borde lleva fallando de forma SOSTENIDA: agotó sus reintentos y sigue en
	 * `ok=false`. Un fallo suelto (con reintento todavía pendiente) no llega a
	 * marcar `agotado` y por lo tanto no aparece aquí — encenderla por eso
	 * enseñaría al dueño a ignorar la tarjeta, igual que ya se decidió para los
	 * crons en {@see \Akibara\Business\Modules\AdminDashboard\AdminDashboard::health_alerts()}.
	 *
	 * @return array{level:'warning', html:string}|null
	 */
	public static function health_alert(): ?array {
		$ultimo = get_option( 'akibara_edge_purge_last', array() );
		if ( ! is_array( $ultimo ) || empty( $ultimo['agotado'] ) || ! empty( $ultimo['ok'] ) ) {
			return null;
		}

		$motivo        = (string) ( $ultimo['motivo'] ?? 'desconocido' );
		$es_credencial = (bool) preg_match( '/^HTTP 40[13]$/', $motivo );
		$cuando        = isset( $ultimo['time'] ) ? human_time_diff( (int) $ultimo['time'], time() ) : 'un rato';

		if ( $es_credencial ) {
			return array(
				'level' => 'warning',
				'html'  => sprintf(
					'☁️ <strong>Cloudflare rechaza la purga de precio/stock</strong> (%s) desde hace %s: revisa las credenciales de Cloudflare en LiteSpeed Cache → CDN. Mientras tanto, un cliente invitado puede ver el precio o el stock viejo hasta 10 minutos tras cada cambio.',
					esc_html( $motivo ),
					esc_html( $cuando )
				),
			);
		}

		return array(
			'level' => 'warning',
			'html'  => sprintf(
				'☁️ <strong>La purga de precio/stock a Cloudflare lleva fallando</strong> (%s) desde hace %s, tras varios reintentos. Un cliente invitado puede ver el precio o el stock viejo hasta 10 minutos tras cada cambio; si sigue así, revisa el estado de Cloudflare o sus credenciales en LiteSpeed Cache → CDN.',
				esc_html( $motivo ),
				esc_html( $cuando )
			),
		);
	}
}
