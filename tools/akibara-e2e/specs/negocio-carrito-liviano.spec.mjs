// @ts-check
/**
 * El carrito sin lo que sólo usa la página de pago (PR-2, verificación de
 * producción del 2026-09-26).
 *
 * Mercado Pago encola en el carrito su SDK (67 KB), la huella antifraude
 * (security.js) y los textos de error de la tarjeta, más dos hojas, aunque en el
 * carrito de bloques no hay nada suyo que usar. Medido en producción: el carrito
 * vacío pesaba 1.428 KB y en un móvil lento pasaba 1,8 s sin responder mientras
 * cargaba; sin esto, 1.334 KB y 0,5 s. akibara-business los saca del carrito
 * (interruptor en Akibara → Velocidad, encendido de fábrica) y los deja en la
 * página de pago, que es donde se usan.
 *
 * Cuatro pruebas:
 *
 *  1. La cola de WordPress del carrito y de la página de pago, renderizada en el
 *     espejo con Mercado Pago ACTIVO y en modo de pruebas sólo para ese proceso
 *     (filtros de sus options y `payment_gateways()->init()`): el espejo lo
 *     tiene apagado y sin credenciales de producción, así que la página servida
 *     no lo muestra, y sembrarlo para toda la suite cambiaría la página de pago
 *     de las demás pruebas que corren en paralelo. Es el código real de Mercado
 *     Pago y de business, y el render no escribe en el espejo ni sale a la red:
 *     lo comprueba la propia prueba (ver `conMercadoPago`). Compara además
 *     la cola ENTERA con el interruptor encendido y apagado: en el carrito
 *     cambian esos cinco y nada más, y en la página de pago no cambia nada.
 *  2. La página servida, en Chromium y en WebKit: el carrito vacío carga, sin
 *     errores, sin esos cinco y CON los guiones de bloques de Mercado Pago
 *     (dependencias del carrito de WooCommerce). Sólo mira: vale también contra
 *     producción. En el espejo, con Mercado Pago apagado, esos cinco no estarían
 *     de todos modos: ahí mide la 3.
 *  3. La misma página en el navegador, pero con Mercado Pago ACTIVO: el
 *     documento del carrito lo renderiza WordPress entero (plantilla incluida)
 *     en el proceso de WP-CLI de la prueba 1, con su misma guardia, y se le
 *     sirve al navegador; hojas, guiones y Store API los sirve el espejo. Así
 *     falla sin el cambio también aquí: con el interruptor apagado, los cinco
 *     vuelven.
 *  4. Lo que ve quien pasa del carrito a la página de pago, con la caché del
 *     navegador encendida (revisiones C-N, 2026-09-26). Hoy, en producción, el
 *     carrito ejecuta el SDK y la página de pago lo saca de la caché que dejó
 *     el carrito. Sin él en el carrito, la página de pago lo bajaba de la red
 *     (66 KB y una conexión propia: el SDK listo hasta 0,9 s más tarde en un
 *     móvil lento). LeanCart los pide desde el `<head>` sin ejecutarlos:
 *     `prefetch` en Chromium y, en WebKit, que no lo tiene, `preload` dentro de
 *     un marco oculto. La prueba recorre carrito → página de pago en Chromium y
 *     WebKit, apagado y encendido, de dos maneras, y la página de pago tiene que
 *     encontrar los cinco en la caché en todos los casos:
 *      - saliendo del carrito antes de su `load`, 0,8 s después de
 *        DOMContentLoaded, con un tercero asíncrono que no termina de llegar
 *        (gtag.js o Brevo en un móvil lento) y el SDK frenado a 200 KB/s
 *        (REV-CN-2o-1: la precarga esperaba al `load` y aquí no llegaba);
 *      - quedándose en el carrito más de lo que WebKit tarda en revisar sus
 *        `preload` (3 s tras el `load`): sin avisos de «preloaded … but not
 *        used» en la consola (REV-CN-2o-2).
 */

import { existsSync } from 'node:fs';
import http from 'node:http';
import { expect, test, webkit } from '@playwright/test';
import { soloLectura } from './soporte/tienda.mjs';
import { wpEspejo } from './soporte/tema-espejo.mjs';

/** WebKit ya instalado en esta máquina (ver negocio-correcciones-qa-2). `AKB_WEBKIT` cambia la ruta. */
const WEBKIT = process.env.AKB_WEBKIT || `${ process.env.HOME }/Library/Caches/ms-playwright/webkit-2342/pw_run.sh`;

/** Lo que el carrito deja de cargar (`LeanCart::SCRIPT_HANDLES` y `LeanCart::STYLE_HANDLES`). */
const GUIONES_FUERA = [ 'wc_mercadopago_sdk', 'wc_mercadopago_security_session', 'wc_mercadopago_custom_card_form_error_codes' ];
const HOJAS_FUERA = [ 'mercadopago_vars_css', 'wc_mercadopago_checkout_components' ];

/**
 * Lo de Mercado Pago que el carrito de bloques necesita: sus métodos de pago,
 * dependencias de `wc-cart-block-frontend`. Una lista más ancha (un barrido por
 * prefijo) se los llevaría.
 */
const SE_QUEDAN = [ 'wc_mercadopago_basic_blocks', 'wc_mercadopago_custom_blocks' ];

/**
 * Los neutralizadores que actúan en cada página (ver `conMercadoPago`).
 * La observación sólo en el carrito: en la página de pago `RuntimeCompatibility`
 * no retira nada y no la escribe.
 */
const NEUTRALIZADOS = {
	cart: [ 'marca', 'miniaturas', 'modo_pruebas', 'observacion' ],
	checkout: [ 'marca', 'miniaturas', 'modo_pruebas' ],
	// Sin el retiro de akibara-cart-checkout-blocks (ver `arrastreSinElRetiroDeBloques`), que es quien la anota.
	carritoSinRetiro: [ 'marca', 'miniaturas', 'modo_pruebas' ],
};

/** `GUIONES_FUERA` como array de PHP, para los renders. */
const GUIONES_FUERA_PHP = `array( ${ GUIONES_FUERA.map( ( h ) => `'${ h }'` ).join( ', ' ) } )`;

/** Lo que está en `a` y no en `b`, ordenado. */
const resta = ( /** @type {string[]} */ a, /** @type {string[]} */ b ) => a.filter( ( h ) => ! b.includes( h ) ).sort();

/**
 * Corre en el espejo `cuerpo` (PHP que renderiza algo y deja en `$akb_salida`
 * lo que devuelve) con Mercado Pago activo en modo de pruebas, sólo en ese
 * proceso de WP-CLI, y comprueba la guardia.
 *
 * El modo de pruebas se fuerza en `checkbox_checkout_test_mode`, la option que
 * lee Mercado Pago (`Store::getCheckboxCheckoutTestMode()`, sin prefijo `_mp_`).
 * Con lo que guarda producción (`no`) y sin token de producción, la pasarela no
 * estaría disponible y la prueba no mediría nada: por eso lo comprueba un
 * fixture.
 *
 * Con Mercado Pago forzado, el render escribía en el espejo y salía a la red
 * (revisiones S-N, 2026-09-26). Lo hacía por tres lados:
 *
 *  - `RuntimeCompatibility` (checkout-blocks) anota en
 *    `akb_blocks_mp_dequeue_observed` cuántos guiones de Mercado Pago retira
 *    el carrito. Con Mercado Pago forzado son 15, y el espejo, que lo tiene
 *    apagado, se quedaba diciéndole a Site Health que el carrito retira 15
 *    cuando retira 0.
 *  - El `ScriptHealthMonitor` de Mercado Pago escribe `mp_health_script_checked`
 *    (1 h). Si esa marca no está, además le avisa a Mercado Pago (POST a
 *    api.mercadopago.com, `mp_script_dequeued_detected`) de los guiones que el
 *    carrito le quitó.
 *  - La pasarela de tarjeta, al construirse, pide las miniaturas de los medios
 *    de pago (`Seller::getPaymentMethodsThumbnails()`). Si su caché de 1 h no
 *    está, hace un GET a api.mercadopago.com con el token de pruebas y escribe
 *    el transitorio `sha1('getPaymentMethodsThumbnailspk' . token)`. Ese GET
 *    lo hace el SDK de Mercado Pago con su propio curl (`CurlRequester`), no con
 *    la API HTTP de WordPress. Las miniaturas no cambian la cola.
 *
 * Aquí los tres se neutralizan en el propio proceso, y una guardia comprueba
 * que no queda nada más. La guardia bloquea y apunta:
 *
 *  - toda escritura en la base (filtro `query`);
 *  - toda petición de la API HTTP de WordPress (`pre_http_request`, que sólo
 *    cubre esa API);
 *  - todo registro de WooCommerce, que en el espejo es un fichero.
 *
 * Además corta la red de todo curl del proceso con un proxy que no existe
 * (`127.0.0.1:9`, variables de entorno que libcurl lee en cada petición): el
 * SDK de Mercado Pago no pasa por `pre_http_request`. Una petición cortada del
 * SDK deja rastro, porque Mercado Pago la reporta (`mp_api_error`, con
 * `wp_remote_post`, que cae en `http`) y la anota en su registro (`registros`).
 * Un fixture comprueba que el corte funciona.
 *
 * La prueba falla si alguna lista no sale vacía, y también si algún
 * neutralizador no actuó: cada uno, incluido el forzado del modo de pruebas,
 * se apunta en `neutralizados` al actuar. Así mide lo mismo en cualquier
 * espejo, tenga guardado lo que tenga —la marca de Mercado Pago viva o
 * caducada, la caché de miniaturas llena o vacía, el modo de pruebas en `yes`
 * o en `no`—: un neutralizador que se quite o que enganche un nombre
 * equivocado deja de apuntarse y la prueba cae aunque esa vez no hubiera nada
 * que escribir ni pedir. (Con la caché caliente, un render sin neutralizar las
 * miniaturas no pediría nada; con el modo de pruebas guardado en `yes`, como
 * en este espejo, un forzado muerto pasaría el fixture.)
 *
 * La observación del carrito es la única que depende de lo guardado:
 * `RuntimeCompatibility` sólo la reescribe cuando el número cambia. Por eso el
 * proceso lee 0 —lo que anota el carrito con Mercado Pago apagado— y, con
 * Mercado Pago forzado (15), intenta escribir siempre y su neutralizador actúa
 * siempre. Con 15 guardado y sin ese 0, no intentaría nada y la prueba caería
 * por el neutralizador que no se apuntó.
 *
 * La guardia sigue puesta hasta que termina el proceso. Así también bloquea lo
 * que WP-CLI escribe al cerrar (el evento de Clarity, el candado y el disparo
 * de wp-cron), pero eso no cuenta como fallo, porque lo hace cualquier orden
 * `wp`, incluido el contenedor de cron cada minuto.
 *
 * Un render de la página ENTERA (prueba 3) hace además lo que cualquier
 * visita: LiteSpeed anota su caché de avatares y pregunta a Gravatar. La
 * guardia lo bloquea igual, pero ahí sólo cuenta como fallo lo de Mercado Pago
 * (`soloMercadoPago`).
 *
 * @param {string} etiqueta Para los mensajes.
 * @param {''|'yes'|'no'} interruptor '' = lo guardado (de fábrica, encendido).
 * @param {string} cuerpo PHP que corre después de los forzados.
 * @param {string[]} esperados Los neutralizadores que tienen que actuar.
 * @param {boolean} [soloMercadoPago] Escrituras y peticiones: sólo cuentan las de Mercado Pago.
 * @returns {Promise<Record<string, any>>} Lo que el cuerpo dejó en `$akb_salida`.
 */
async function conMercadoPago( etiqueta, interruptor, cuerpo, esperados, soloMercadoPago = false ) {
	const php = `
		foreach ( array( 'http_proxy', 'https_proxy', 'all_proxy', 'HTTPS_PROXY', 'ALL_PROXY' ) as $akb_v ) { putenv( $akb_v . '=http://127.0.0.1:9' ); }
		putenv( 'no_proxy' );
		putenv( 'NO_PROXY' );
		$akb_escrituras = array();
		$akb_http = array();
		$akb_registros = array();
		add_filter( 'query', static function ( $sql ) use ( &$akb_escrituras ) {
			if ( ! preg_match( '/^\\s*(INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP|TRUNCATE|RENAME)\\b/i', $sql ) ) { return $sql; }
			$akb_escrituras[] = substr( preg_replace( '/\\s+/', ' ', $sql ), 0, 160 );
			return 'SELECT 1 FROM DUAL WHERE 0';
		}, PHP_INT_MAX );
		add_filter( 'pre_http_request', static function ( $pre, $args, $url ) use ( &$akb_http ) {
			$akb_http[] = ( $args['method'] ?? 'GET' ) . ' ' . strtok( $url, '?' );
			return new WP_Error( 'akb_render_sin_red', 'El render de la prueba no sale a la red.' );
		}, PHP_INT_MAX, 3 );
		add_filter( 'woocommerce_logger_log_message', static function ( $mensaje, $nivel ) use ( &$akb_registros ) {
			$akb_registros[] = $nivel . ' ' . substr( is_string( $mensaje ) ? $mensaje : wp_json_encode( $mensaje ), 0, 200 );
			return null;
		}, PHP_INT_MAX, 2 );
		$akb_sonda = curl_init( 'https://akb-sin-red.invalid/' );
		curl_setopt_array( $akb_sonda, array( CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT_MS => 1000, CURLOPT_RESOLVE => array( 'akb-sin-red.invalid:443:127.0.0.1' ) ) );
		curl_exec( $akb_sonda );
		$akb_red_cortada = false !== stripos( curl_error( $akb_sonda ), 'proxy' );
		$akb_neutralizados = array();
		$akb_n = static function ( string $quien, $valor ) use ( &$akb_neutralizados ) { $akb_neutralizados[ $quien ] = true; return $valor; };
		add_filter( 'pre_option_akb_blocks_mp_dequeue_observed', static fn() => array( 'retirados' => 0, 'at' => 1 ) );
		add_filter( 'pre_update_option_akb_blocks_mp_dequeue_observed', static fn( $nuevo, $viejo ) => $akb_n( 'observacion', $viejo ), 10, 2 );
		add_filter( 'pre_transient_mp_health_script_checked', static fn() => $akb_n( 'marca', true ) );
		$forzar = static function ( $v ) { $v = is_array( $v ) ? $v : array(); $v['enabled'] = 'yes'; return $v; };
		add_filter( 'option_woocommerce_woo-mercado-pago-custom_settings', $forzar );
		add_filter( 'option_woocommerce_woo-mercado-pago-basic_settings', $forzar );
		add_filter( 'pre_option_checkbox_checkout_test_mode', static fn() => $akb_n( 'modo_pruebas', 'yes' ) );
		$akb_miniaturas = sha1( 'getPaymentMethodsThumbnailspk' . $GLOBALS['mercadopago']->sellerConfig->getCredentialsAccessToken() );
		add_filter( 'pre_transient_' . $akb_miniaturas, static fn() => $akb_n( 'miniaturas', array( 'akb' => '' ) ) );
		if ( '${ interruptor }' !== '' ) { add_filter( 'pre_option_akb_lean_cart', static fn() => '${ interruptor }' ); }
		WC()->payment_gateways()->init();
		$akb_salida = array();
		${ cuerpo }
		echo 'AKB-SALIDA ' . wp_json_encode( array( 'salida' => $akb_salida, 'modo_pruebas' => $GLOBALS['mercadopago']->storeConfig->isTestMode(), 'red_cortada' => $akb_red_cortada, 'escrituras' => $akb_escrituras, 'http' => $akb_http, 'registros' => $akb_registros, 'neutralizados' => array_keys( $akb_neutralizados ) ) );
	`;
	const texto = await wpEspejo( php );
	const linea = texto.split( '\n' ).find( ( l ) => l.startsWith( 'AKB-SALIDA ' ) );
	expect( linea, `WP-CLI no devolvió nada: ${ texto.slice( -400 ) }` ).toBeTruthy();
	const { salida, escrituras, http, registros, neutralizados, red_cortada: redCortada, modo_pruebas: modoPruebas } = JSON.parse( /** @type {string} */ ( linea ).slice( 'AKB-SALIDA '.length ) );
	const deMercadoPago = ( /** @type {string[]} */ l ) => ( soloMercadoPago ? l.filter( ( x ) => /mercadopago|mercadolibre|mp_health|akb_blocks_mp|_transient_(timeout_)?[0-9a-f]{40}/i.test( x ) ) : l );
	expect( redCortada, `${ etiqueta }: fixture: el proxy muerto corta el curl del proceso (si no, el SDK de Mercado Pago saldría a la red sin pasar por la guardia)` ).toBe( true );
	expect( modoPruebas, `${ etiqueta }: fixture: Mercado Pago quedó en modo de pruebas en el proceso` ).toBe( true );
	// `soft`: si falla, el informe trae las cuatro listas, no sólo la primera.
	expect.soft( deMercadoPago( escrituras ), `${ etiqueta }: el render no escribe en el espejo` ).toEqual( [] );
	expect.soft( deMercadoPago( http ), `${ etiqueta }: el render no sale a la red (tampoco el aviso de Mercado Pago ni el reporte de una petición cortada de su SDK)` ).toEqual( [] );
	expect.soft( registros, `${ etiqueta }: el render no escribe registros (en el espejo son ficheros; ahí queda también la petición cortada del SDK)` ).toEqual( [] );
	expect.soft( [ ...neutralizados ].sort(), `${ etiqueta }: actuaron todos los neutralizadores (uno que falta se quitó o engancha un nombre equivocado)` ).toEqual( esperados );
	return salida;
}

/**
 * La cola de WordPress (guiones y hojas) de una página, entera, y cuáles de los
 * tres guiones se imprimen: `wp_script_is( …, 'enqueued' )` también es verdadero
 * para lo que un guion de la cola arrastra como dependencia, que WordPress
 * imprime aunque se haya sacado de la cola.
 *
 * @param {'cart'|'checkout'} pagina
 * @param {''|'yes'|'no'} interruptor '' = lo guardado (de fábrica, encendido).
 * @returns {Promise<{is_cart:boolean,is_checkout:boolean,js:string[],css:string[],se_imprimen:string[]}>}
 */
async function colaConMercadoPago( pagina, interruptor ) {
	const cuerpo = `
		wp( array( 'page_id' => wc_get_page_id( '${ pagina }' ) ) );
		wc_load_cart();
		do_action( 'wp_enqueue_scripts' );
		$akb_salida = array( 'is_cart' => is_cart(), 'is_checkout' => is_checkout(), 'js' => array_values( wp_scripts()->queue ), 'css' => array_values( wp_styles()->queue ), 'se_imprimen' => array_values( array_filter( ${ GUIONES_FUERA_PHP }, static fn( $h ) => wp_script_is( $h, 'enqueued' ) ) ) );
	`;
	return /** @type {any} */ ( await conMercadoPago( pagina, interruptor, cuerpo, NEUTRALIZADOS[ pagina ] ) );
}

/**
 * Qué guiones de los que quedan en la cola del carrito arrastran, por
 * dependencia, alguno de los tres, cuando falta el retiro de
 * akibara-cart-checkout-blocks (`RuntimeCompatibility::dequeue_classic_mercado_pago_assets`,
 * en la 999). Revisión C-N (REV-CN-1f-1): hoy `wc_mercadopago_custom_card_form`
 * declara `wc_mercadopago_custom_card_form_error_codes`, y el retiro de LeanCart
 * sólo se sostiene porque el plugin de bloques saca antes ese guion.
 *
 * Es el código real de WordPress y de Mercado Pago: las dependencias salen de lo
 * registrado, también las de los `*.block.asset.php`.
 *
 * @returns {Promise<{ retiro: boolean, suya: string[], arrastran: Record<string, string[]> }>}
 */
async function arrastreSinElRetiroDeBloques() {
	const cuerpo = `
		wp( array( 'page_id' => wc_get_page_id( 'cart' ) ) );
		wc_load_cart();
		$akb_retiro = false;
		foreach ( $GLOBALS['wp_filter']['wp_enqueue_scripts']->callbacks[999] ?? array() as $akb_cb ) {
			if ( is_array( $akb_cb['function'] ) && $akb_cb['function'][0] instanceof Akibara\\CartCheckoutBlocks\\RuntimeCompatibility && 'dequeue_classic_mercado_pago_assets' === $akb_cb['function'][1] ) {
				$akb_retiro = remove_action( 'wp_enqueue_scripts', $akb_cb['function'], 999 );
			}
		}
		do_action( 'wp_enqueue_scripts' );
		$akb_s = wp_scripts();
		$akb_deps = static function ( string $h, array $visto = array() ) use ( $akb_s, &$akb_deps ): array {
			foreach ( $akb_s->registered[ $h ]->deps ?? array() as $akb_d ) {
				if ( ! isset( $visto[ $akb_d ] ) ) {
					$visto[ $akb_d ] = true;
					$visto = $akb_deps( $akb_d, $visto );
				}
			}
			return $visto;
		};
		$akb_arrastran = array();
		foreach ( $akb_s->queue as $akb_q ) {
			foreach ( array_intersect( ${ GUIONES_FUERA_PHP }, array_keys( $akb_deps( $akb_q ) ) ) as $akb_h ) {
				$akb_arrastran[ $akb_h ][] = $akb_q;
			}
		}
		$akb_salida = array( 'retiro' => $akb_retiro, 'suya' => ( new ReflectionClassConstant( Akibara\\CartCheckoutBlocks\\RuntimeCompatibility::class, 'MERCADO_PAGO_CLASSIC_HANDLES' ) )->getValue(), 'arrastran' => $akb_arrastran );
	`;
	return /** @type {any} */ ( await conMercadoPago( 'carrito sin el retiro de bloques', '', cuerpo, NEUTRALIZADOS.carritoSinRetiro ) );
}

/**
 * El HTML del carrito vacío (o de la página de pago) tal como lo sirve el
 * espejo, pero con Mercado Pago activo: WordPress entero, plantilla incluida.
 *
 * WordPress 6.9 abre su propio búfer al cargar la plantilla (sube al `<head>`
 * las hojas que se encolan tarde, como las del bloque del carrito): se vacía en
 * el nuestro para que su callback corra; si no, esas hojas no salen.
 *
 * La página de pago con el carrito vacío redirigiría al carrito (y el `exit`
 * cerraría el proceso): se le quita esa redirección, como hace una visita con
 * algo en el carrito.
 *
 * @param {'cart'|'checkout'} pagina
 * @param {string} agente User-agent del navegador (Mercado Pago distingue el móvil).
 * @param {''|'yes'|'no'} interruptor
 * @param {string} [arnes] PHP que corre antes del render (filtros sólo de la prueba).
 * @returns {Promise<string>} El documento.
 */
async function paginaConMercadoPago( pagina, agente, interruptor, arnes = '' ) {
	const cuerpo = `
		${ arnes }
		${ 'checkout' === pagina ? "add_filter( 'woocommerce_checkout_redirect_empty_cart', '__return_false' );" : '' }
		$_SERVER['REQUEST_URI'] = wp_parse_url( wc_get_page_permalink( '${ pagina }' ), PHP_URL_PATH );
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['HTTP_HOST'] = wp_parse_url( home_url(), PHP_URL_HOST ) . ( wp_parse_url( home_url(), PHP_URL_PORT ) ? ':' . wp_parse_url( home_url(), PHP_URL_PORT ) : '' );
		$_SERVER['HTTP_USER_AGENT'] = base64_decode( '${ Buffer.from( agente ).toString( 'base64' ) }' );
		defined( 'WP_USE_THEMES' ) || define( 'WP_USE_THEMES', true );
		wc_load_cart();
		$GLOBALS['wp']->main();
		$akb_nivel = ob_get_level();
		ob_start();
		require ABSPATH . WPINC . '/template-loader.php';
		while ( ob_get_level() > $akb_nivel + 1 ) { ob_end_flush(); }
		$akb_salida = array( 'es' => 'cart' === '${ pagina }' ? is_cart() : is_checkout(), 'html' => base64_encode( ob_get_clean() ) );
	`;
	const { es, html } = await conMercadoPago( `${ pagina } servido${ interruptor ? ` (${ interruptor })` : '' }`, interruptor, cuerpo, NEUTRALIZADOS[ pagina ], true );
	expect( es, `Fixture: el documento renderizado es ${ 'cart' === pagina ? 'el carrito' : 'la página de pago' }.` ).toBe( true );
	return Buffer.from( html, 'base64' ).toString( 'utf8' );
}

/**
 * Lo que WordPress imprimió en la página: los handles de sus `<script src>` y de
 * sus hojas (ids con sufijo `-js`/`-css`). Lo que un guion inserta después
 * —el security.js que pide la huella, por ejemplo— no cuenta: es consecuencia.
 *
 * @param {import('@playwright/test').Page} p
 */
const servidoEn = ( p ) => p.evaluate( () => ( {
	js: [ ...document.querySelectorAll( 'script[id$="-js"][src]' ) ].map( ( s ) => s.id.replace( /-js$/, '' ) ),
	css: [ ...document.querySelectorAll( 'link[rel="stylesheet"][id$="-css"]' ) ].map( ( l ) => l.id.replace( /-css$/, '' ) ),
} ) );

/**
 * Junta los errores de JavaScript de la página, sin los de terceros de producción (GA4, Clarity: ver E2E-6).
 *
 * @param {import('@playwright/test').Page} p
 * @returns {string[]} Se va llenando.
 */
function erroresDe( p ) {
	const errores = /** @type {string[]} */ ( [] );
	p.on( 'pageerror', ( e ) => {
		if ( ! /googletagmanager|clarity\.ms/.test( e.stack || '' ) ) {
			errores.push( e.message );
		}
	} );
	return errores;
}

/**
 * Sólo mirar: nada de lo que el navegador mande por POST (fragmentos del
 * carrito, huellas de terceros) sale de aquí.
 *
 * @param {import('@playwright/test').Page} p
 */
const soloMirar = ( p ) => p.route( '**/*', ( r ) => ( [ 'GET', 'HEAD' ].includes( r.request().method() ) ? r.continue() : r.abort() ) );

/** Host del SDK en la prueba 4 (ver `ARNES_CACHE`); lo atiende `proxyDeLaPrueba`. */
const SDK_FALSO = 'sdk.akb-prueba.test';

/**
 * Host de un tercero asíncrono que retrasa el `load` del carrito, como gtag.js o
 * el de Brevo en un móvil lento: `proxyDeLaPrueba` no lo contesta hasta que la
 * prueba lo suelta.
 */
const TERCERO_LENTO = 'tercero.akb-prueba.test';

/** El SDK de la prueba: el tamaño del de producción (~70 KB). */
const CUERPO_SDK = `window.MercadoPago = function MercadoPago() {};/*${ 'x'.repeat( 70_000 ) }*/`;

/**
 * Cuando el cliente ya puede tocar «Finalizar compra»: 0,8 s después de
 * DOMContentLoaded (lo que midió la revisión REV-CN-2o-1).
 */
const SALIDA_TRAS_DCL = 800;

/**
 * Cuánto se queda en el carrito quien no sale enseguida: más allá de los 3 s tras
 * el `load` en que WebKit revisa los `preload` sin usar y avisa en la consola.
 */
const QUEDARSE_TRAS_LOAD = 4_500;

/** El aviso de WebKit (y de Chromium) por un `preload` que el documento no usó. */
const AVISO_PRELOAD = /preloaded using link preload but not used/;

/**
 * Lo que la prueba 4 cambia en el render, sólo en su proceso de WP-CLI, para
 * que la caché del navegador se comporte como en producción:
 *
 *  - La versión. En el espejo (`wp_get_environment_type()` = `local`) Mercado
 *    Pago añade `time()` a la de sus guiones y hojas (`Url::assetVersion()`):
 *    el carrito pide `ver=8.9.3.1790457912` y la página de pago
 *    `ver=8.9.3.1790457914`, y nunca comparten caché. Producción imprime
 *    `ver=8.9.3`. Se quita el sufijo en los filtros por los que pasa toda URL
 *    que WordPress imprime.
 *  - El SDK. Sale de un host de la prueba con las cabeceras que manda
 *    sdk.mercadopago.com (medidas el 2026-09-26: `public, max-age=86400`,
 *    `Access-Control-Allow-Origin: *`, sin `Vary`), para no depender de la
 *    red. El host entra en la lista de `CrossOriginScripts`, así que la
 *    etiqueta lleva `crossorigin="anonymous"`, como en producción.
 */
const ARNES_CACHE = `
	$akb_como_produccion = static fn( $url ) => preg_replace( '/([?&]ver=\\d+\\.\\d+\\.\\d+)\\.\\d{10}(?=&|#|$)/', '$1', str_replace( 'https://sdk.mercadopago.com/', 'http://${ SDK_FALSO }/', (string) $url ) );
	add_filter( 'script_loader_src', $akb_como_produccion, PHP_INT_MAX );
	add_filter( 'style_loader_src', $akb_como_produccion, PHP_INT_MAX );
	add_filter( 'akb_crossorigin_verified_hosts', static fn( $hosts ) => array_merge( (array) $hosts, array( '${ SDK_FALSO }' ) ) );
`;

/**
 * Las URL con que la página de pago pide esos cinco, tal como las imprime
 * WordPress (la etiqueta con `id="<handle>-js"` o `-css`).
 *
 * @param {string} html
 * @returns {Record<string, string>} Por handle; '' si no está.
 */
function urlsDeLosCinco( html ) {
	const ids = [ ...GUIONES_FUERA.map( ( h ) => [ h, `${ h }-js` ] ), ...HOJAS_FUERA.map( ( h ) => [ h, `${ h }-css` ] ) ];
	return Object.fromEntries( ids.map( ( [ handle, id ] ) => {
		const etiqueta = html.match( new RegExp( `<(?:script|link)\\b[^>]*\\bid=["']${ id }["'][^>]*>` ) );
		const url = etiqueta && etiqueta[ 0 ].match( /\b(?:src|href)=["']([^"']+)["']/ );
		return [ handle, url ? url[ 1 ].replace( /&#0?38;|&amp;/g, '&' ) : '' ];
	} ) );
}

/**
 * Las pistas `<link rel="prefetch">` de un documento: URL (decodificada), `as` y
 * `crossorigin` (`null` si no lo lleva).
 *
 * @param {string} html
 * @returns {{ url: string, as: string|null, crossorigin: string|null }[]}
 */
function pistasPrefetch( html ) {
	const atributo = ( /** @type {string} */ etiqueta, /** @type {string} */ nombre ) => {
		const m = etiqueta.match( new RegExp( `\\s${ nombre }(?:=(?:"([^"]*)"|'([^']*)'|([^\\s>]+)))?(?=[\\s/>])` ) );
		return m ? ( m[ 1 ] ?? m[ 2 ] ?? m[ 3 ] ?? '' ).replace( /&#0?38;|&amp;/g, '&' ) : null;
	};
	return [ ...html.matchAll( /<link\b[^>]*>/g ) ]
		.map( ( [ etiqueta ] ) => etiqueta )
		.filter( ( etiqueta ) => 'prefetch' === atributo( etiqueta, 'rel' ) )
		.map( ( etiqueta ) => ( { url: /** @type {string} */ ( atributo( etiqueta, 'href' ) ), as: atributo( etiqueta, 'as' ), crossorigin: atributo( etiqueta, 'crossorigin' ) } ) );
}

/**
 * Proxy HTTP de la prueba 4. `page.route` no sirve aquí: en Playwright 1.56
 * activar el enrutado apaga la caché HTTP del navegador («Enabling routing
 * disables http cache»), que es justo lo que se mide. El proxy:
 *
 *  - entrega los documentos renderizados (`documentos`, por ruta y sin query);
 *  - atiende `SDK_FALSO` con las cabeceras de producción y a la velocidad de un
 *    móvil lento (75 ms de espera y 200 KB/s);
 *  - retiene `TERCERO_LENTO` hasta `soltar()`;
 *  - reenvía al espejo lo demás, sólo GET y HEAD (el resto, 403: sólo mirar);
 *  - no abre túneles (CONNECT, 403): nada sale a la red;
 *  - apunta cada petición con la `fase` en curso, y si llegó entera.
 *
 * @param {string} espejo Origen del espejo (`baseURL`).
 */
async function proxyDeLaPrueba( espejo ) {
	const origen = new URL( espejo );
	/** @type {import('node:http').ServerResponse[]} */
	const retenidos = [];
	const proxy = {
		fase: '',
		/** @type {Record<string, string>} */
		documentos: {},
		/** @type {{ fase: string, metodo: string, url: string, entera: boolean }[]} */
		pedidos: [],
		/** Contesta (vacío) lo que `TERCERO_LENTO` tenía retenido. */
		soltar: () => retenidos.splice( 0 ).forEach( ( r ) => r.writableEnded || r.destroyed || r.end() ),
		servidor: http.createServer( ( req, res ) => {
			const url = new URL( /** @type {string} */ ( req.url ), origen );
			const metodo = /** @type {string} */ ( req.method );
			const pedido = { fase: proxy.fase, metodo, url: url.href, entera: false };
			proxy.pedidos.push( pedido );
			res.on( 'finish', () => {
				pedido.entera = true;
			} );
			if ( ! [ 'GET', 'HEAD' ].includes( metodo ) ) {
				res.writeHead( 403 ).end();
				return;
			}
			if ( SDK_FALSO === url.host ) {
				setTimeout( () => {
					res.writeHead( 200, { 'content-type': 'application/javascript', 'cache-control': 'public, max-age=86400', 'access-control-allow-origin': '*' } );
					if ( 'GET' !== metodo ) {
						res.end();
						return;
					}
					// 4 KB cada 20 ms: 200 KB/s.
					let enviado = 0;
					const goteo = setInterval( () => {
						if ( res.destroyed ) {
							clearInterval( goteo );
							return;
						}
						res.write( CUERPO_SDK.slice( enviado, enviado += 4096 ) );
						if ( enviado >= CUERPO_SDK.length ) {
							clearInterval( goteo );
							res.end();
						}
					}, 20 );
				}, 75 );
				return;
			}
			if ( TERCERO_LENTO === url.host ) {
				res.writeHead( 200, { 'content-type': 'application/javascript', 'cache-control': 'no-store' } );
				retenidos.push( res );
				return;
			}
			if ( url.host !== origen.host ) {
				res.writeHead( 403 ).end();
				return;
			}
			if ( ! url.search && proxy.documentos[ url.pathname ] ) {
				res.writeHead( 200, { 'content-type': 'text/html; charset=UTF-8', 'cache-control': 'no-store' } );
				res.end( proxy.documentos[ url.pathname ] );
				return;
			}
			const cabeceras = { ...req.headers };
			delete cabeceras[ 'proxy-connection' ];
			const reenvio = http.request( { host: origen.hostname, port: origen.port, method: metodo, path: url.pathname + url.search, headers: cabeceras }, ( r ) => {
				res.writeHead( /** @type {number} */ ( r.statusCode ), r.headers );
				r.pipe( res );
			} );
			reenvio.on( 'error', () => res.destroy() );
			req.pipe( reenvio );
		} ),
	};
	proxy.servidor.on( 'connect', ( req, socket ) => {
		proxy.pedidos.push( { fase: proxy.fase, metodo: 'CONNECT', url: /** @type {string} */ ( req.url ) } );
		socket.end( 'HTTP/1.1 403 Forbidden\r\n\r\n' );
	} );
	await new Promise( ( listo ) => proxy.servidor.listen( 0, '127.0.0.1', () => listo( null ) ) );
	const { port } = /** @type {import('node:net').AddressInfo} */ ( proxy.servidor.address() );
	return { proxy, servidor: `http://127.0.0.1:${ port }` };
}

test.describe( 'carrito liviano (negocio)', () => {
	test( 'con Mercado Pago activo, el carrito no encola lo de cobro y la página de pago sí; apagado, vuelve todo; nada más cambia', async () => {
		test.skip( soloLectura, 'renderiza con WP-CLI en el espejo' );
		test.skip( 'escritorio' !== test.info().project.name, 'es la cola del servidor: no depende del tamaño de pantalla' );
		test.setTimeout( 300_000 );

		const carrito = await test.step( 'carrito, de fábrica', () => colaConMercadoPago( 'cart', '' ) );
		expect( carrito.is_cart, 'Fixture: la página renderizada es el carrito.' ).toBe( true );
		for ( const h of GUIONES_FUERA ) {
			expect( carrito.js, `el carrito no encola ${ h }` ).not.toContain( h );
		}
		for ( const h of HOJAS_FUERA ) {
			expect( carrito.css, `el carrito no encola la hoja ${ h }` ).not.toContain( h );
		}
		expect( carrito.se_imprimen, 'y ningún guion que se queda los arrastra como dependencia: no se imprimen' ).toEqual( [] );

		const pago = await test.step( 'página de pago, de fábrica', () => colaConMercadoPago( 'checkout', '' ) );
		expect( pago.is_checkout, 'Fixture: la página renderizada es la de pago.' ).toBe( true );
		// Si Mercado Pago no quedó activo en el proceso, lo de arriba pasaría sin medir nada.
		expect( pago.js, 'Fixture: Mercado Pago activo encola su formulario de tarjeta en la página de pago.' ).toContain( 'wc_mercadopago_custom_checkout' );
		expect( pago.js, 'la página de pago conserva el SDK, la huella y los errores de la tarjeta' ).toEqual( expect.arrayContaining( GUIONES_FUERA ) );
		expect( pago.se_imprimen, 'y los imprime' ).toEqual( GUIONES_FUERA );
		expect( pago.css, 'y sus hojas' ).toEqual( expect.arrayContaining( HOJAS_FUERA ) );

		const apagado = await test.step( 'carrito, con el interruptor apagado', () => colaConMercadoPago( 'cart', 'no' ) );
		expect( apagado.js, 'apagado, el carrito queda como lo encola Mercado Pago' ).toEqual( expect.arrayContaining( GUIONES_FUERA ) );
		expect( apagado.se_imprimen ).toEqual( GUIONES_FUERA );
		expect( apagado.css ).toEqual( expect.arrayContaining( HOJAS_FUERA ) );

		// Y nada más: el resto del carrito (bloques, Blue Express, puntos, envío
		// gratis, cupones…) y la página de pago entera no dependen del interruptor.
		expect( resta( apagado.js, carrito.js ), 'encendido, el carrito deja fuera esos tres guiones y ninguno más' ).toEqual( [ ...GUIONES_FUERA ].sort() );
		expect( resta( apagado.css, carrito.css ), 'y esas dos hojas y ninguna más' ).toEqual( [ ...HOJAS_FUERA ].sort() );
		expect( resta( carrito.js, apagado.js ).concat( resta( carrito.css, apagado.css ) ), 'y no añade nada' ).toEqual( [] );
		const pagoApagado = await test.step( 'página de pago, con el interruptor apagado', () => colaConMercadoPago( 'checkout', 'no' ) );
		expect( pagoApagado.js, 'la página de pago encola lo mismo con el interruptor encendido y apagado' ).toEqual( pago.js );
		expect( pagoApagado.css ).toEqual( pago.css );

		// REV-CN-1f-1. Que no se impriman depende también de akibara-cart-checkout-blocks:
		// sin su retiro, un guion que se queda arrastraría uno de los tres. Lo que
		// dice el docblock de LeanCart, fijado: quién arrastra qué hoy, y que todo
		// el que arrastra lo retira ese plugin.
		const arrastre = await test.step( 'carrito, sin el retiro de akibara-cart-checkout-blocks', () => arrastreSinElRetiroDeBloques() );
		expect( arrastre.retiro, 'Fixture: se quitó el retiro de akibara-cart-checkout-blocks' ).toBe( true );
		expect( arrastre.arrastran, 'hoy sólo el formulario de tarjeta arrastra uno de los tres (si cambia, hay que revisar el docblock de LeanCart)' ).toEqual( { wc_mercadopago_custom_card_form_error_codes: [ 'wc_mercadopago_custom_card_form' ] } );
		expect( resta( Object.values( arrastre.arrastran ).flat(), arrastre.suya ), 'todo guion que arrastra uno de los tres lo retira akibara-cart-checkout-blocks antes que LeanCart' ).toEqual( [] );
	} );

	test( 'el carrito servido carga sin errores, sin lo de cobro y con lo que el carrito de bloques necesita', async ( { page, baseURL } ) => {
		test.setTimeout( 180_000 );
		expect( existsSync( WEBKIT ), `WebKit en ${ WEBKIT } (o la ruta en AKB_WEBKIT)` ).toBe( true );

		const { use } = test.info().project;
		const navegador = await webkit.launch( { executablePath: WEBKIT } );
		try {
			const contexto = await navegador.newContext( {
				baseURL,
				viewport: use.viewport,
				hasTouch: use.hasTouch,
				userAgent: use.userAgent,
				storageState: use.storageState,
			} );
			const paginas = { chromium: page, webkit: await contexto.newPage() };

			for ( const [ motor, p ] of Object.entries( paginas ) ) {
				const errores = erroresDe( p );
				await soloMirar( p );

				await test.step( `${ motor }: abrir el carrito vacío`, async () => {
					await p.goto( '/carrito/', { waitUntil: 'load', timeout: 90_000 } );
					await expect( p.locator( '.wp-block-woocommerce-empty-cart-block' ).first() ).toBeVisible( { timeout: 30_000 } );
				} );

				const servido = await servidoEn( p );
				test.skip( ! servido.js.includes( 'wc_mercadopago_custom_blocks' ), 'Mercado Pago no está activo en este entorno' );

				for ( const h of GUIONES_FUERA ) {
					expect( servido.js, `${ motor }: el carrito servido no trae ${ h }` ).not.toContain( h );
				}
				for ( const h of HOJAS_FUERA ) {
					expect( servido.css, `${ motor }: el carrito servido no trae la hoja ${ h }` ).not.toContain( h );
				}
				for ( const h of SE_QUEDAN ) {
					expect( servido.js, `${ motor }: ${ h } se queda (el carrito de bloques lo necesita)` ).toContain( h );
				}
				expect( servido.js, `${ motor }: el carrito de bloques sigue cargando` ).toContain( 'wc-cart-block-frontend' );
				expect( errores, `${ motor }: sin errores de JavaScript` ).toEqual( [] );
			}
		} finally {
			await navegador.close();
		}
	} );

	test( 'en el espejo, con Mercado Pago activo: el carrito servido no trae lo de cobro, carga sin errores y conserva lo que necesita; apagado, lo trae', async ( { page, baseURL } ) => {
		test.skip( soloLectura, 'renderiza con WP-CLI en el espejo' );
		test.setTimeout( 300_000 );
		expect( existsSync( WEBKIT ), `WebKit en ${ WEBKIT } (o la ruta en AKB_WEBKIT)` ).toBe( true );

		const { use } = test.info().project;
		const navegador = await webkit.launch( { executablePath: WEBKIT } );
		// Sólo el documento del carrito sale del render; lo demás lo sirve el espejo.
		const DOCUMENTO = /\/carrito\/(\?.*)?$/;
		try {
			const contexto = await navegador.newContext( { baseURL, viewport: use.viewport, hasTouch: use.hasTouch, userAgent: use.userAgent } );
			const paginas = { chromium: page, webkit: await contexto.newPage() };

			for ( const [ motor, p ] of Object.entries( paginas ) ) {
				const errores = erroresDe( p );
				await soloMirar( p );
				let apagado = { js: /** @type {string[]} */ ( [] ), css: /** @type {string[]} */ ( [] ) };
				for ( const interruptor of /** @type {const} */ ( [ 'no', '' ] ) ) {
					const html = await test.step( `${ motor }: el carrito con Mercado Pago activo, interruptor ${ interruptor || 'de fábrica' }`, () => paginaConMercadoPago( 'cart', /** @type {string} */ ( use.userAgent ), interruptor ) );
					await p.route( DOCUMENTO, ( r ) => ( 'document' === r.request().resourceType() ? r.fulfill( { status: 200, contentType: 'text/html; charset=UTF-8', body: html } ) : r.fallback() ) );
					errores.length = 0;
					await p.goto( '/carrito/', { waitUntil: 'load', timeout: 90_000 } );
					await expect( p.locator( '.wp-block-woocommerce-empty-cart-block' ).first(), `${ motor }: el carrito vacío se pinta` ).toBeVisible( { timeout: 30_000 } );
					const servido = await servidoEn( p );
					await p.unroute( DOCUMENTO );

					if ( 'no' === interruptor ) {
						// Si Mercado Pago no quedó activo en el render, lo de abajo pasaría sin medir nada.
						expect( servido.js, `${ motor }: fixture: apagado, el carrito trae el SDK, la huella y los errores de la tarjeta` ).toEqual( expect.arrayContaining( GUIONES_FUERA ) );
						expect( servido.css, `${ motor }: fixture: y sus dos hojas` ).toEqual( expect.arrayContaining( HOJAS_FUERA ) );
						apagado = servido;
						continue;
					}
					// Entre apagado y encendido cambian esos cinco y nada más.
					expect( resta( apagado.js, servido.js ), `${ motor }: encendido, el carrito servido deja fuera esos tres guiones y ninguno más` ).toEqual( [ ...GUIONES_FUERA ].sort() );
					expect( resta( apagado.css, servido.css ), `${ motor }: y esas dos hojas y ninguna más` ).toEqual( [ ...HOJAS_FUERA ].sort() );
					expect( resta( servido.js, apagado.js ).concat( resta( servido.css, apagado.css ) ), `${ motor }: y no añade nada` ).toEqual( [] );
					for ( const h of GUIONES_FUERA ) {
						expect( servido.js, `${ motor }: el carrito servido no trae ${ h }` ).not.toContain( h );
					}
					for ( const h of HOJAS_FUERA ) {
						expect( servido.css, `${ motor }: el carrito servido no trae la hoja ${ h }` ).not.toContain( h );
					}
					for ( const h of SE_QUEDAN ) {
						expect( servido.js, `${ motor }: ${ h } se queda (el carrito de bloques lo necesita)` ).toContain( h );
					}
					expect( servido.js, `${ motor }: el carrito de bloques sigue cargando` ).toContain( 'wc-cart-block-frontend' );
					expect( errores, `${ motor }: sin errores de JavaScript` ).toEqual( [] );
				}
			}
		} finally {
			await navegador.close();
		}
	} );

	test( 'con la caché del navegador encendida, la página de pago encuentra los cinco como hoy en producción, también si el cliente sale del carrito antes de que termine de cargar; en el carrito no se ejecutan ni dejan avisos', async ( { browser, baseURL } ) => {
		test.skip( soloLectura, 'renderiza con WP-CLI en el espejo' );
		test.setTimeout( 420_000 );
		expect( existsSync( WEBKIT ), `WebKit en ${ WEBKIT } (o la ruta en AKB_WEBKIT)` ).toBe( true );

		const { use } = test.info().project;
		const agente = /** @type {string} */ ( use.userAgent );
		const carrito = {
			apagado: await test.step( 'el carrito, apagado', () => paginaConMercadoPago( 'cart', agente, 'no', ARNES_CACHE ) ),
			encendido: await test.step( 'el carrito, de fábrica', () => paginaConMercadoPago( 'cart', agente, '', ARNES_CACHE ) ),
		};
		const pago = await test.step( 'la página de pago', () => paginaConMercadoPago( 'checkout', agente, '', ARNES_CACHE ) );

		const urls = urlsDeLosCinco( pago );
		const cinco = Object.values( urls );
		expect( cinco.filter( ( u ) => ! u ), 'Fixture: la página de pago imprime los cinco' ).toEqual( [] );
		expect( urls.wc_mercadopago_sdk, 'Fixture: el SDK sale del host de la prueba con la versión de producción' ).toMatch( new RegExp( `^http://${ SDK_FALSO.replace( /\./g, '\\.' ) }/js/v2\\?ver=\\d+\\.\\d+\\.\\d+$` ) );
		expect( pago, 'Fixture: y con crossorigin, como en producción' ).toMatch( /<script crossorigin="anonymous"[^>]*\bid="wc_mercadopago_sdk-js"/ );
		expect( cinco.filter( ( u ) => /ver=\d+\.\d+\.\d+\.\d{10}/.test( u ) ), 'Fixture: ninguna versión lleva el time() del espejo' ).toEqual( [] );

		// REV-CN-2o-1. Lo que imprime WordPress en el <head> del carrito: una pista
		// `prefetch` por cada uno, con su `as` y el `crossorigin` de la etiqueta de
		// la página de pago, y el guion de Safari antes de la primera hoja.
		const cabeza = ( /** @type {string} */ html ) => html.slice( 0, html.indexOf( '</head>' ) );
		const esperadas = [ ...GUIONES_FUERA.map( ( h ) => [ h, 'script' ] ), ...HOJAS_FUERA.map( ( h ) => [ h, 'style' ] ) ].map( ( [ h, as ] ) => {
			const etiqueta = /** @type {RegExpMatchArray} */ ( pago.match( new RegExp( `<(?:script|link)\\b[^>]*\\bid=["']${ h }-${ 'script' === as ? 'js' : 'css' }["'][^>]*>` ) ) )[ 0 ];
			const crossorigin = etiqueta.match( /\scrossorigin(?:=["']([^"']*)["'])?/ );
			return { url: urls[ h ], as, crossorigin: crossorigin ? crossorigin[ 1 ] ?? '' : null };
		} );
		const deLosCinco = ( /** @type {string} */ html ) => pistasPrefetch( html ).filter( ( x ) => cinco.includes( x.url ) );
		expect( deLosCinco( cabeza( carrito.encendido ) ), 'encendido, el <head> del carrito pide los cinco con prefetch, como los pide la página de pago' ).toEqual( esperadas );
		expect( deLosCinco( carrito.apagado ), 'apagado, ninguna pista para ellos' ).toEqual( [] );
		const guion = cabeza( carrito.encendido ).indexOf( 'id="akb-lean-cart-warmup"' );
		expect( guion, 'el guion de Safari va en el <head>' ).toBeGreaterThan( -1 );
		expect( guion, 'y antes de la primera hoja (detrás de una hoja esperaría a que ésta baje)' ).toBeLessThan( carrito.encendido.search( /<link\b[^>]*rel=["']stylesheet["']/ ) );
		expect( carrito.apagado, 'apagado, sin guion' ).not.toContain( 'akb-lean-cart-warmup' );

		// Un tercero asíncrono que no termina de llegar: el `load` del carrito no
		// llega mientras el cliente está en él. Igual en los dos estados.
		const conTerceroLento = ( /** @type {string} */ html ) => html.replace( '</head>', `<script async src="http://${ TERCERO_LENTO }/lento.js"></script></head>` );

		const { proxy, servidor } = await proxyDeLaPrueba( /** @type {string} */ ( baseURL ) );
		proxy.documentos[ '/checkout/' ] = pago;
		try {
			// Chromium, el del proyecto (el proxy va en cada contexto; también para
			// localhost, que Chromium se salta si no se le dice `<-loopback>`); WebKit, el instalado.
			const motores = /** @type {const} */ ( [
				[ 'chromium', async () => browser, { server: servidor, bypass: '<-loopback>' } ],
				[ 'webkit', () => webkit.launch( { executablePath: WEBKIT } ), { server: servidor } ],
			] );
			for ( const [ motor, lanzar, proxyDelContexto ] of motores ) {
				const navegador = await lanzar();
				try {
					for ( const estado of /** @type {const} */ ( [ 'apagado', 'encendido' ] ) ) {
						for ( const salida of /** @type {const} */ ( [ 'antes de la carga', 'tras la carga' ] ) ) {
							const donde = `${ motor }, ${ estado }, saliendo ${ salida }`;
							const temprano = 'antes de la carga' === salida;
							proxy.documentos[ '/carrito/' ] = temprano ? conTerceroLento( carrito[ estado ] ) : carrito[ estado ];
							// Un contexto nuevo: la caché empieza vacía.
							const contexto = await navegador.newContext( { baseURL, proxy: proxyDelContexto, viewport: use.viewport, hasTouch: use.hasTouch, isMobile: use.isMobile, userAgent: agente } );
							const p = await contexto.newPage();
							const errores = erroresDe( p );
							const avisos = /** @type {string[]} */ ( [] );
							p.on( 'console', ( m ) => {
								if ( AVISO_PRELOAD.test( m.text() ) ) {
									avisos.push( m.text() );
								}
							} );

							proxy.fase = `${ donde }: carrito`;
							const deLaRedEnCarrito = () => cinco.map( ( u ) => proxy.pedidos.filter( ( x ) => x.fase === `${ donde }: carrito` && x.url === u ).length );
							if ( temprano ) {
								await p.goto( '/carrito/', { waitUntil: 'domcontentloaded', timeout: 90_000 } );
								await p.waitForTimeout( SALIDA_TRAS_DCL );
								expect( await p.evaluate( () => document.readyState ), `${ donde }: fixture: el cliente sale antes del load del carrito` ).toBe( 'interactive' );
							} else {
								await p.goto( '/carrito/', { waitUntil: 'load', timeout: 90_000 } );
								await expect( p.locator( '.wp-block-woocommerce-empty-cart-block' ).first(), `${ donde }: el carrito vacío se pinta` ).toBeVisible( { timeout: 30_000 } );
								// Los cinco descargados enteros, y más allá de la revisión de WebKit.
								await expect.poll( () => cinco.every( ( u ) => proxy.pedidos.some( ( x ) => x.fase === `${ donde }: carrito` && x.url === u && x.entera ) ), { timeout: 20_000 } ).toBe( true );
								await p.waitForFunction( ( ms ) => performance.now() > /** @type {PerformanceNavigationTiming} */ ( performance.getEntriesByType( 'navigation' )[ 0 ] ).loadEventEnd + ms, QUEDARSE_TRAS_LOAD, { timeout: 30_000 } );
							}
							// Qué pidió el carrito, de qué documento (el suyo o su marco) y cuándo.
							const enCarrito = await p.evaluate( ( u ) => {
								const navegacion = /** @type {PerformanceNavigationTiming} */ ( performance.getEntriesByType( 'navigation' )[ 0 ] );
								const carga = navegacion.loadEventStart ? performance.timeOrigin + navegacion.loadEventStart : Infinity;
								const ventanas = [ window, ...[ ...document.querySelectorAll( 'iframe' ) ].map( ( f ) => /** @type {Window} */ ( f.contentWindow ) ) ];
								return {
									mercadoPago: typeof ( /** @type {any} */ ( window ) ).MercadoPago,
									cinco: u.map( ( x ) => ventanas.flatMap( ( w ) => w.performance.getEntriesByName( x ).map( ( e ) => `${ /** @type {PerformanceResourceTiming} */ ( e ).initiatorType }${ window === w ? '' : ' del marco' }${ w.performance.timeOrigin + e.startTime < carga ? ' durante la carga' : ' tras la carga' }` ) ).join( ' + ' ) ),
								};
							}, cinco );
							if ( 'apagado' === estado ) {
								// Así está hoy producción: el carrito ejecuta el SDK y deja los cinco en la caché.
								expect( enCarrito.mercadoPago, `${ donde }: fixture: el carrito ejecuta el SDK, como hoy en producción` ).toBe( 'function' );
								expect( deLaRedEnCarrito(), `${ donde }: fixture: el carrito pide cada uno una vez` ).toEqual( [ 1, 1, 1, 1, 1 ] );
							} else {
								expect.soft( enCarrito.mercadoPago, `${ donde }: en el carrito no se ejecuta el SDK` ).toBe( 'undefined' );
								expect.soft( enCarrito.cinco, `${ donde }: cada uno se pide una vez, con un <link> (prefetch en Chromium, preload en un marco en WebKit) y mientras el carrito carga` ).toEqual( cinco.map( () => ( 'webkit' === motor ? 'link del marco durante la carga' : 'link durante la carga' ) ) );
								expect.soft( deLaRedEnCarrito(), `${ donde }: y una sola petición cada uno` ).toEqual( [ 1, 1, 1, 1, 1 ] );
								expect.soft( errores, `${ donde }: sin errores de JavaScript en el carrito` ).toEqual( [] );
							}
							if ( ! temprano ) {
								expect.soft( avisos, `${ donde }: sin avisos de preload sin usar en la consola del carrito (REV-CN-2o-2)` ).toEqual( [] );
							}

							proxy.fase = `${ donde }: pago`;
							await p.goto( '/checkout/', { waitUntil: 'load', timeout: 90_000 } );
							await p.waitForFunction( () => 'function' === typeof ( /** @type {any} */ ( window ) ).MercadoPago, null, { timeout: 30_000 } );
							const deLaRedEnPago = proxy.pedidos.filter( ( x ) => x.fase === proxy.fase && cinco.includes( x.url ) ).map( ( x ) => x.url );
							expect.soft( deLaRedEnPago, `${ donde }: la página de pago encuentra los cinco en la caché (0 bytes de red), como hoy en producción` ).toEqual( [] );
							proxy.soltar();
							await contexto.close();
						}
					}
				} finally {
					if ( navegador !== browser ) {
						await navegador.close();
					}
				}
			}
		} finally {
			proxy.soltar();
			proxy.servidor.closeAllConnections();
			await new Promise( ( listo ) => proxy.servidor.close( () => listo( null ) ) );
		}
	} );
} );
