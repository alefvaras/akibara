<?php
/**
 * Evita ejecutar en el carrito recursos de Mercado Pago propios del checkout.
 *
 * El ajuste se aplica sólo al carrito, está encendido por defecto y requiere
 * MP_VERSION. Retira una lista explícita de SDK, seguridad, errores y estilos de
 * pago, conservando los bloques que WooCommerce necesita para registrar medios
 * de pago y la telemetría permitida por RuntimeCompatibility.
 *
 * Las URLs y atributos efectivos se obtienen de la cola de WordPress antes de
 * retirar recursos. Se precargan para la página siguiente sin ejecutarlos en
 * el carrito: prefetch para navegadores compatibles y preload dentro de un
 * marco oculto para WebKit. El calentamiento comienza desde head, no espera
 * load ni el clic de navegación, y respeta versión, filtros y crossorigin.
 *
 * wp_dequeue no elimina dependencias que otro handle siga solicitando. La lista
 * se coordina con los handles clásicos retirados por el plugin de bloques;
 * una actualización del proveedor debe verificar ese grafo de dependencias.
 * Fuera del carrito no cambia la ejecución del pago ni su verificación antifraude.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\SiteSpeed;

defined( 'ABSPATH' ) || exit;

final class LeanCart {

	/** `yes` (o la fila ausente) los quita del carrito; `no` los deja como los encola Mercado Pago. */
	public const OPTION = 'akb_lean_cart';

	/** Encendido de fábrica. */
	public const DEFAULT = 'yes';

	/**
	 * Guiones de Mercado Pago que sólo usa la página de pago.
	 *
	 * @var array<int, string>
	 */
	public const SCRIPT_HANDLES = array(
		'wc_mercadopago_sdk',
		'wc_mercadopago_security_session',
		'wc_mercadopago_custom_card_form_error_codes',
	);

	/**
	 * Hojas de Mercado Pago que sólo usa la página de pago.
	 *
	 * @var array<int, string>
	 */
	public const STYLE_HANDLES = array(
		'mercadopago_vars_css',
		'wc_mercadopago_checkout_components',
	);

	/**
	 * Después de todo lo que encola: Mercado Pago en la 10 (y sus hojas en
	 * `enqueue_block_assets`, que corre dentro de la misma acción) y el retiro de
	 * akibara-cart-checkout-blocks en la 999.
	 */
	public const PRIORITY = 1000;

	/** Id del guion en línea que deja la caché caliente en Safari. */
	public const WARMUP_ID = 'akb-lean-cart-warmup';

	/**
	 * Dónde se imprime en `wp_head`: después de las pistas de `wp_resource_hints`
	 * (2), que llevan los `prefetch`, y antes de las hojas (`wp_print_styles`, 8):
	 * un guion en línea detrás de una hoja espera a que ésta termine de bajar.
	 */
	public const WARMUP_PRIORITY = 3;

	/** Dónde va, en `WARMUP_JS`, la lista de `[url, as, crossorigin o null]`. */
	public const WARMUP_SLOT = '__LISTA__';

	/**
	 * El guion en línea, con la lista en `WARMUP_SLOT`.
	 *
	 * Donde hay `prefetch` no hace nada: ya están las pistas del `<head>`. Donde no
	 * (Safari), en el acto, un marco oculto y, en su documento, un `preload` por
	 * recurso, de prioridad baja y con el `crossorigin` de la etiqueta de la página
	 * de pago. Un `preload` no ejecuta ni aplica nada.
	 */
	public const WARMUP_JS = '(function(l){var d=document,f,h;if(d.createElement("link").relList.supports("prefetch")){return}f=d.createElement("iframe");f.hidden=true;d.head.appendChild(f);h=f.contentDocument;l.forEach(function(x){var e=h.createElement("link");e.rel="preload";e.as=x[1];if(null!==x[2]){e.crossOrigin=x[2]}e.href=x[0];e.fetchPriority="low";h.head.appendChild(e)})})(__LISTA__);';

	/**
	 * Lo que se sacó del carrito en esta petición, para dejarlo en la caché.
	 *
	 * @var array<int, array{0: string, 1: string, 2: ?string}>
	 */
	private array $warmup = array();

	public function register(): void {
		if ( is_admin() ) {
			return;
		}

		add_action( 'wp_enqueue_scripts', array( $this, 'dequeue_checkout_only_assets' ), self::PRIORITY );
		add_filter( 'wp_resource_hints', array( $this, 'add_prefetch_hints' ), 10, 2 );
		add_action( 'wp_head', array( $this, 'print_warmup' ), self::WARMUP_PRIORITY );
	}

	/** ¿Está Mercado Pago? Sin él no hay nada que quitar ni interruptor que mostrar. */
	public static function glue_active(): bool {
		return defined( 'MP_VERSION' );
	}

	/** ¿Se quitan? */
	public static function enabled(): bool {
		return 'yes' === get_option( self::OPTION, self::DEFAULT );
	}

	/**
	 * @internal Callback de `wp_enqueue_scripts:1000`.
	 */
	public function dequeue_checkout_only_assets(): void {
		if ( ! self::glue_active() || ! self::is_blocks_cart() || ! self::enabled() ) {
			return;
		}

		foreach ( self::SCRIPT_HANDLES as $handle ) {
			if ( wp_script_is( $handle, 'enqueued' ) ) {
				$this->remember( wp_scripts(), $handle, 'script' );
			}
			wp_dequeue_script( $handle );
		}
		foreach ( self::STYLE_HANDLES as $handle ) {
			if ( wp_style_is( $handle, 'enqueued' ) ) {
				$this->remember( wp_styles(), $handle, 'style' );
			}
			wp_dequeue_style( $handle );
		}
	}

	/**
	 * Lo que esta petición sacó del carrito, como `<link rel="prefetch">` en el
	 * `<head>`, con su `as` y el `crossorigin` de su etiqueta (sin él si ella no
	 * lo lleva: el mismo modo de petición que en la página de pago).
	 *
	 * @internal Callback de `wp_resource_hints`.
	 *
	 * @param mixed $urls          Las pistas de este tipo.
	 * @param mixed $relation_type `dns-prefetch`, `preconnect`, `prefetch` o `prerender`.
	 * @return mixed
	 */
	public function add_prefetch_hints( $urls, $relation_type ) {
		if ( 'prefetch' !== $relation_type || ! is_array( $urls ) ) {
			return $urls;
		}

		foreach ( $this->warmup as [ $url, $as, $crossorigin ] ) {
			$urls[] = array(
				'href' => $url,
				'as'   => $as,
			) + ( null === $crossorigin ? array() : array( 'crossorigin' => $crossorigin ) );
		}

		return $urls;
	}

	/**
	 * El guion que deja la caché caliente en Safari, sólo si esta petición sacó algo.
	 *
	 * `data-no-optimize`: que el optimizador de LiteSpeed no lo mueva ni lo retrase.
	 *
	 * @internal Callback de `wp_head:3` (`WARMUP_PRIORITY`).
	 */
	public function print_warmup(): void {
		if ( array() === $this->warmup ) {
			return;
		}

		wp_print_inline_script_tag(
			str_replace( self::WARMUP_SLOT, (string) wp_json_encode( $this->warmup ), self::WARMUP_JS ),
			array(
				'id'               => self::WARMUP_ID,
				'data-no-optimize' => '1',
			)
		);
	}

	/**
	 * Lo que pediría la etiqueta de `$html` para `$handle`: su URL, ya decodificada,
	 * y su `crossorigin` (`null` si no lo lleva; '' si va sin valor).
	 *
	 * @param string $html   Lo que imprime `do_item()`: la etiqueta y lo que la
	 *                       acompaña (datos en línea, que no llevan su id).
	 * @param string $handle
	 * @param string $as     `script` o `style`.
	 * @return array{0: string, 1: string, 2: ?string}|null
	 */
	public static function warmup_entry( string $html, string $handle, string $as ): ?array {
		$es_guion = 'script' === $as;
		$etiqueta = new \WP_HTML_Tag_Processor( $html );

		while ( $etiqueta->next_tag( $es_guion ? 'SCRIPT' : 'LINK' ) ) {
			if ( $handle . ( $es_guion ? '-js' : '-css' ) !== $etiqueta->get_attribute( 'id' ) ) {
				continue;
			}

			$url         = $etiqueta->get_attribute( $es_guion ? 'src' : 'href' );
			$crossorigin = $etiqueta->get_attribute( 'crossorigin' );

			return is_string( $url ) && '' !== $url
				? array( $url, $as, null === $crossorigin ? null : ( is_string( $crossorigin ) ? $crossorigin : '' ) )
				: null;
		}

		return null;
	}

	/**
	 * Apunta la etiqueta que WordPress imprimiría para `$handle`, pidiéndosela a
	 * la propia cola: versión, `script_loader_src`/`style_loader_src` y el
	 * `crossorigin` de `script_loader_tag`, igual que en la página de pago.
	 *
	 * @param \WP_Scripts|\WP_Styles $queue
	 */
	private function remember( $queue, string $handle, string $as ): void {
		ob_start();
		$queue->do_item( $handle );
		$entrada = self::warmup_entry( (string) ob_get_clean(), $handle, $as );

		if ( null !== $entrada ) {
			$this->warmup[] = $entrada;
		}
	}

	/**
	 * El carrito de bloques, y sólo él.
	 *
	 * Con el carrito clásico (shortcode) el cliente no pasa por aquí: si la
	 * tienda volviera a él, que Mercado Pago decida como siempre.
	 */
	private static function is_blocks_cart(): bool {
		if ( ! function_exists( 'is_cart' ) || ! is_cart() ) {
			return false;
		}

		$page_id = function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'cart' ) : 0;

		return $page_id > 0 && has_block( 'woocommerce/cart', $page_id );
	}
}
