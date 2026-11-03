<?php
/**
 * Resuelve el copy del muro de acceso por endpoint desde Akibara → Textos.
 * La línea de contexto identifica el producto de redirect_to y ofrece volver
 * a su ficha; templates/myaccount/form-login.php consume estos datos.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\GoogleLoginButton;

use Akibara\Business\Services\NavigationLinks;
use Akibara\Business\Utilities\Text;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.115.1
 */
final class AccessWall {

	public const OPTION_TITLE     = 'akb_copy_wall_title';
	public const OPTION_SUB       = 'akb_copy_wall_sub';
	public const OPTION_SUB_LOGIN = 'akb_copy_wall_sub_login';

	public const OPTION_FAVORITOS_TITLE = 'akb_copy_wall_favoritos_title';
	public const OPTION_FAVORITOS_SUB   = 'akb_copy_wall_favoritos_sub';
	public const OPTION_COLECCION_TITLE = 'akb_copy_wall_coleccion_title';
	public const OPTION_COLECCION_SUB   = 'akb_copy_wall_coleccion_sub';
	public const OPTION_ORDERS_TITLE    = 'akb_copy_wall_orders_title';
	public const OPTION_ORDERS_SUB      = 'akb_copy_wall_orders_sub';

	public const OPTION_RETURN = 'akb_copy_wall_return';

	public const DEFAULT_TITLE     = 'Accede a Akibara';
	public const DEFAULT_SUB       = 'Elige cómo continuar para comprar, seguir tus pedidos y gestionar tus preventas.';
	public const DEFAULT_SUB_LOGIN = 'Inicia sesión para seguir tus pedidos y gestionar tus preventas.';

	public const DEFAULT_FAVORITOS_TITLE = 'Los tomos que guardaste te esperan';
	public const DEFAULT_FAVORITOS_SUB   = 'Entra y verás tu lista completa, con el precio y la disponibilidad de cada uno.';
	public const DEFAULT_COLECCION_TITLE = 'Retoma cada serie donde la dejaste';
	public const DEFAULT_COLECCION_SUB   = 'Entra y verás qué tomos tienes marcados y cuáles te faltan.';
	public const DEFAULT_ORDERS_TITLE    = 'Sigue tus compras y sus envíos';
	public const DEFAULT_ORDERS_SUB      = 'Entra para ver en qué va cada pedido y por dónde viene.';

	public const DEFAULT_RETURN = 'Al entrar te llevamos de vuelta a {producto}.';

	/**
	 * El hueco donde va el nombre del tomo en la línea de contexto.
	 *
	 * NUNCA `sprintf`: el texto lo edita el dueño y un «%» suelto sería un
	 * fatal en la página de acceso [[copys-del-dueno-nunca-sprintf]].
	 */
	public const RETURN_MARKER = '{producto}';

	/**
	 * Endpoint de la cuenta → sus dos copys.
	 *
	 * La clave es la query var de WooCommerce, no el slug de la URL: son
	 * iguales en los tres casos, pero quien manda al elegir el texto es la
	 * primera. Un endpoint que no esté aquí cae al texto general, que es lo
	 * correcto: mejor el cartel de siempre que uno que hable de otra cosa.
	 *
	 * @return array<string, array{title: string, sub: string}>
	 */
	private static function endpoints(): array {
		return array(
			'favoritos' => array(
				'title' => self::OPTION_FAVORITOS_TITLE,
				'sub'   => self::OPTION_FAVORITOS_SUB,
			),
			'coleccion' => array(
				'title' => self::OPTION_COLECCION_TITLE,
				'sub'   => self::OPTION_COLECCION_SUB,
			),
			'orders'    => array(
				'title' => self::OPTION_ORDERS_TITLE,
				'sub'   => self::OPTION_ORDERS_SUB,
			),
		);
	}

	/**
	 * option → literal, para que CopyManager los exponga y los sanee como
	 * copys de una línea obligatorios (vaciar uno lo devuelve a su literal).
	 *
	 * @return array<string, string>
	 */
	public static function defaults(): array {
		return array(
			self::OPTION_TITLE            => self::DEFAULT_TITLE,
			self::OPTION_SUB              => self::DEFAULT_SUB,
			self::OPTION_SUB_LOGIN        => self::DEFAULT_SUB_LOGIN,
			self::OPTION_FAVORITOS_TITLE  => self::DEFAULT_FAVORITOS_TITLE,
			self::OPTION_FAVORITOS_SUB    => self::DEFAULT_FAVORITOS_SUB,
			self::OPTION_COLECCION_TITLE  => self::DEFAULT_COLECCION_TITLE,
			self::OPTION_COLECCION_SUB    => self::DEFAULT_COLECCION_SUB,
			self::OPTION_ORDERS_TITLE     => self::DEFAULT_ORDERS_TITLE,
			self::OPTION_ORDERS_SUB       => self::DEFAULT_ORDERS_SUB,
			self::OPTION_RETURN           => self::DEFAULT_RETURN,
		);
	}

	/**
	 * La query var de cuenta que sirve esta petición, '' si es el panel
	 * general o si WooCommerce todavía no puede responder.
	 *
	 * Sólo devuelve endpoints con texto propio: cualquier otro es, para el
	 * muro, el caso general.
	 */
	public static function current_endpoint(): string {
		if ( ! function_exists( 'WC' ) ) {
			return '';
		}

		$wc = WC();
		if ( ! is_object( $wc ) || ! isset( $wc->query ) || ! is_object( $wc->query ) ) {
			return '';
		}
		if ( ! method_exists( $wc->query, 'get_current_endpoint' ) ) {
			return '';
		}

		$endpoint = (string) $wc->query->get_current_endpoint();

		return isset( self::endpoints()[ $endpoint ] ) ? $endpoint : '';
	}

	/**
	 * Título y subtítulo ya resueltos para una petición del muro.
	 *
	 * @param string $endpoint         Query var de cuenta ('' = panel general).
	 * @param bool   $register_enabled ¿WooCommerce permite crear cuenta aquí?
	 * @return array{title: string, sub: string}
	 */
	public static function copy( string $endpoint, bool $register_enabled ): array {
		self::prime();

		$propios = self::endpoints()[ $endpoint ] ?? null;
		if ( null !== $propios ) {
			return array(
				'title' => self::text( $propios['title'] ),
				'sub'   => self::text( $propios['sub'] ),
			);
		}

		return array(
			'title' => self::text( self::OPTION_TITLE ),
			// Sin registro no hay nada que «elegir»: el cartel general lo dice
			// de las dos maneras y aquí se toma la que corresponde.
			'sub'   => self::text( $register_enabled ? self::OPTION_SUB : self::OPTION_SUB_LOGIN ),
		);
	}

	/**
	 * El destino al que el cliente quiere volver, o '' si no hay ninguno que
	 * merezca confianza.
	 *
	 * Es el mismo `redirect_to` que WooCommerce honrará al entrar, filtrado
	 * por la misma regla que lo puso en el enlace: sólo páginas públicas de
	 * este sitio, nunca `/wp-admin/`.
	 */
	public static function redirect_target(): string {
		if ( ! isset( $_GET['redirect_to'] ) || ! is_string( $_GET['redirect_to'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- parámetro de navegación, no cambia nada.
			return '';
		}

		// No se «sanea»: se valida contra una lista blanca —páginas públicas de
		// este sitio— y lo que no pasa se descarta entero. Recortar un destino
		// sospechoso hasta dejarlo aceptable sería peor que no usarlo.
		$crudo = (string) wp_unslash( $_GET['redirect_to'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Recommended -- validado por lista blanca en safe_internal_destination().

		return NavigationLinks::safe_internal_destination( $crudo );
	}

	/**
	 * La línea que nombra el tomo del que viene el cliente, con su enlace de
	 * vuelta. HTML ya escapado, o '' cuando no hay tomo que nombrar.
	 *
	 * Sólo se nombra un producto PUBLICADO: el `redirect_to` viaja en la URL y
	 * cualquiera puede escribirlo, así que sirve para descubrir borradores o
	 * privados si se acepta lo que diga. El enlace se construye desde el
	 * permalink del producto encontrado, no desde la URL recibida.
	 */
	public static function return_notice(): string {
		$destino = self::redirect_target();
		if ( '' === $destino ) {
			return '';
		}

		$post_id = self::product_id_for( $destino );
		if ( $post_id <= 0 ) {
			return '';
		}

		$titulo = trim( (string) get_the_title( $post_id ) );
		$enlace = (string) get_permalink( $post_id );
		if ( '' === $titulo || '' === $enlace ) {
			return '';
		}

		$ancla = '<a class="akb-account-auth__back" href="' . esc_url( $enlace ) . '">' . esc_html( $titulo ) . '</a>';
		$linea = esc_html( self::text( self::OPTION_RETURN ) );

		if ( false === strpos( $linea, self::RETURN_MARKER ) ) {
			// El dueño borró el marcador: el tomo se añade al final antes que
			// desaparecer, que es justo el defecto que esta línea vino a cerrar.
			return trim( $linea . ' ' . $ancla );
		}

		return str_replace( self::RETURN_MARKER, $ancla, $linea );
	}

	/**
	 * Resuelve exclusivamente productos publicados de una URL interna validada.
	 * Primero usa url_to_postid; si la estructura de enlaces sin base no resuelve,
	 * busca el último segmento como slug limitado a product. Verifica publicación
	 * al final para no filtrar títulos de borradores desde redirect_to.
	 *
	 * @param string $destino URL interna ya validada.
	 * @return int
	 */
	private static function product_id_for( string $destino ): int {
		$ruta              = (string) wp_parse_url( $destino, PHP_URL_PATH );
		$trimmed_path      = trim( $ruta, '/' );
		$is_single_segment = '' !== $trimmed_path && false === strpos( $trimmed_path, '/' );

		$post_id = 0;

		// En Akibara los tomos viven en la raíz (/slug-del-tomo/). Para rutas de
		// un solo segmento, consultar primero get_page_by_path() ahorra recorrer
		// todas las reglas de reescritura de WordPress en url_to_postid().
		if ( $is_single_segment && function_exists( 'get_page_by_path' ) ) {
			$encontrado = get_page_by_path( $trimmed_path, OBJECT, 'product' );
			$post_id    = $encontrado instanceof \WP_Post ? (int) $encontrado->ID : 0;
		}

		// Fallback: si no resolvió (ej. tiene prefijo /product/, es multisegmento,
		// o get_page_by_path no estaba disponible).
		if ( $post_id <= 0 && function_exists( 'url_to_postid' ) ) {
			$post_id = (int) url_to_postid( $destino );
		}

		// Si aún no resuelve pero hay slug en una ruta multisegmento (ej. /catalogo/tomo-1/)
		if ( $post_id <= 0 && ! $is_single_segment && function_exists( 'get_page_by_path' ) ) {
			$slug = '' === $ruta ? '' : basename( untrailingslashit( $ruta ) );
			if ( '' !== $slug ) {
				$encontrado = get_page_by_path( $slug, OBJECT, 'product' );
				$post_id    = $encontrado instanceof \WP_Post ? (int) $encontrado->ID : 0;
			}
		}

		if ( $post_id <= 0 ) {
			return 0;
		}

		$post = get_post( $post_id );
		if ( ! $post || 'product' !== $post->post_type || 'publish' !== $post->post_status ) {
			return 0;
		}

		return $post_id;
	}

	/**
	 * A dónde mandar al cliente después de entrar, para hornearlo como
	 * `<input name="redirect">` en los dos formularios.
	 *
	 * WooCommerce honra `$_POST['redirect']` al iniciar sesión y al crear la
	 * cuenta; sin ese campo cae al `Referer`, que en un envío desde el muro es
	 * el muro mismo, así que el cliente entraba y aterrizaba en el panel de su
	 * cuenta, lejos del tomo que estaba mirando.
	 *
	 * El botón de Google tampoco volvía, al contrario de lo que decía aquí antes:
	 * su `state` de OAuth llevaba el muro. Ahora los TRES caminos leen este mismo
	 * valor — los dos formularios lo hornean como campo y
	 * {@see GoogleLoginButton::google_returns_where_the_customer_came_from()} se
	 * lo da al tercero por el filtro que login-with-google expone.
	 *
	 * @param string $endpoint Query var de cuenta ('' = panel general).
	 */
	public static function redirect_field_value( string $endpoint ): string {
		$destino = self::redirect_target();
		if ( '' !== $destino ) {
			return $destino;
		}

		// Sin `redirect_to`, el destino natural es el endpoint que se pidió:
		// quien entra por /mi-cuenta/favoritos/ quiere sus favoritos. El
		// `Referer` suele decir lo mismo, pero puede llegar recortado.
		if ( '' === $endpoint || ! isset( self::endpoints()[ $endpoint ] ) || ! function_exists( 'wc_get_endpoint_url' ) ) {
			return '';
		}

		return NavigationLinks::safe_internal_destination( (string) wc_get_endpoint_url( $endpoint ) );
	}

	/**
	 * Un copy de una línea, saneado, con su literal como red.
	 *
	 * @param string $option Una de las constantes OPTION_*.
	 */
	private static function text( string $option ): string {
		return Text::one_line_option( $option, self::defaults()[ $option ] ?? '' );
	}

	/**
	 * Calienta las options del muro en una sola consulta. El muro pinta como
	 * mucho tres de ellas, pero Core anota en `notoptions` las que no existen,
	 * así que la primera visita tras un despliegue no paga una consulta por
	 * texto sin guardar.
	 */
	private static function prime(): void {
		static $primed = false;
		if ( $primed || ! function_exists( 'wp_prime_option_caches' ) ) {
			return;
		}
		$primed = true;
		wp_prime_option_caches( array_keys( self::defaults() ) );
	}
}
