<?php
/**
 * Candado de seguridad: NINGUNA vinculación automática de pedidos de invitado.
 *
 * Este archivo afirmaba lo contrario hasta 1.108.14: fijaba que el módulo se
 * enganchara a `user_register` para reasignar los pedidos del correo recién
 * registrado. Esa conducta resultó ser una fuga de datos personales en
 * producción, y el test la estaba protegiendo.
 *
 * La cadena, medida el 2026-08-23 (detalle completo en el docblock de
 * `src/Modules/GuestOrderLinking/GuestOrderLinking.php`):
 * el registro de /mi-cuenta/ pide sólo un correo —sin contraseña—, WooCommerce
 * autentica en el acto con `wc_set_customer_auth_cookie()`, y `user_register`
 * disparaba la reasignación. Cualquiera podía escribir el correo de un
 * desconocido y quedarse con su historial: 39 pedidos en 35 correos sin cuenta
 * estaban expuestos.
 *
 * WooCommerce 11 dejó la vinculación EXCLUSIVAMENTE detrás de
 * `woocommerce_customer_email_verified`. Este test existe para que nadie la
 * vuelva a adelantar sin prueba de propiedad del correo.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\GuestOrderLinking;

use Akibara\Business\Modules\GuestOrderLinking\GuestOrderLinking;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * @since 1.107.8
 */
final class GuestOrderLinkingTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox No engancha NADA: registrarse no puede reclamar pedidos ajenos.
	 */
	public function test_registers_no_hooks_at_all(): void {
		$modulo = new GuestOrderLinking();

		Monkey\Actions\expectAdded( 'user_register' )->never();
		Monkey\Actions\expectAdded( 'woocommerce_created_customer' )->never();
		Monkey\Actions\expectAdded( 'rtcamp.google_user_created' )->never();

		$modulo->register();

		self::assertTrue( true, 'Las expectativas de Brain Monkey se comprueban en el tearDown.' );
	}

	/**
	 * @testdox El módulo ya no expone ningún método que reasigne pedidos.
	 */
	public function test_no_claiming_method_survives(): void {
		self::assertFalse(
			method_exists( GuestOrderLinking::class, 'handle_claim_past_orders' ),
			'Reintroducir el reclamo exige una prueba de propiedad del correo; hoy la única del sitio es la verificación de WooCommerce.'
		);
	}

	/**
	 * @testdox El código fuente no vuelve a llamar a la función de reasignación de WooCommerce.
	 *
	 * Candado por INVARIANTE, no por literal de versión: da igual desde qué hook
	 * se llame — mientras no haya verificación del correo de por medio, llamar a
	 * `wc_update_new_customer_past_orders()` desde este plugin reabre la fuga.
	 */
	public function test_plugin_never_calls_woocommerce_order_reassignment(): void {
		$src   = dirname( __DIR__, 3 ) . '/src';
		$found = array();

		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $src ) );
		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
				continue;
			}
			$contents = (string) file_get_contents( $file->getPathname() );
			// Se ignoran los comentarios: el docblock del incidente la nombra.
			$sin_comentarios = (string) preg_replace( '#/\*.*?\*/|//[^\n]*#s', '', $contents );
			if ( str_contains( $sin_comentarios, 'wc_update_new_customer_past_orders' ) ) {
				$found[] = str_replace( $src, '', $file->getPathname() );
			}
		}

		self::assertSame(
			array(),
			$found,
			'Ningún archivo de src/ puede reasignar pedidos de invitado: eso vive detrás de la verificación de correo de WooCommerce.'
		);
	}

	/**
	 * @testdox El módulo engancha SÓLO las dos cosas que pintan, ninguna que vincule.
	 *
	 * Antes esto exigía que no enganchara nada. Desde el 2026-09-11 apaga el
	 * aviso ruidoso de WooCommerce y pinta una línea en su lugar, así que la
	 * lista deja de estar vacía. Lo que no puede cambiar es CUÁLES: en el momento
	 * en que aparezca aquí `user_register` o cualquier pariente, la fuga de
	 * pedidos ajenos vuelve.
	 */
	public function test_registering_the_module_hooks_only_display(): void {
		$enganchados = array();
		Functions\when( 'add_action' )->alias(
			static function ( string $hook ) use ( &$enganchados ): void {
				$enganchados[] = $hook;
			}
		);
		Functions\when( 'add_filter' )->alias(
			static function ( string $hook ) use ( &$enganchados ): void {
				$enganchados[] = $hook;
			}
		);

		( new GuestOrderLinking() )->register();

		self::assertSame(
			array(
				'woocommerce_customer_email_verification_should_show_prompt',
				'woocommerce_before_account_orders',
			),
			$enganchados,
			'Los dos son de pintado. Cualquier tercero aquí hay que justificarlo por escrito.'
		);
	}

	/**
	 * @testdox El aviso se apaga SIEMPRE: la pantalla no puede informar sobre un tercero.
	 *
	 * ESTE ES EL CANDADO NUEVO, y es de seguridad, no de estilo. El dueño pidió
	 * «ocultarlo cuando no haya nada que reclamar». Hacerlo al pie de la letra
	 * convierte el aviso en un lector: basta registrarse con el correo de un
	 * desconocido —WooCommerce 11 autentica en el propio alta y el formulario no
	 * pide contraseña— y mirar si el aviso sale, para saber si esa persona compró
	 * aquí. Por eso el filtro devuelve false pase lo que pase: si algún día
	 * alguien lo condiciona a los datos del correo, esta prueba cae.
	 *
	 * @dataProvider cualquier_entrada
	 *
	 * @param mixed $mostrar Lo que WooCommerce hubiera decidido.
	 * @param mixed $usuario ID de usuario.
	 */
	public function test_el_aviso_no_puede_informar_sobre_un_tercero( $mostrar, $usuario ): void {
		self::assertFalse(
			( new GuestOrderLinking() )->silenciar_aviso( $mostrar, $usuario ),
			'Una sola salida true convierte la pantalla en un lector de datos ajenos.'
		);
	}

	/** @return array<string, array{0: mixed, 1: mixed}> */
	public static function cualquier_entrada(): array {
		return array(
			'WooCommerce decía que sí'   => array( true, 42 ),
			'WooCommerce decía que no'   => array( false, 42 ),
			'sin usuario'                => array( true, 0 ),
			'usuario con muchos pedidos' => array( true, 999999 ),
		);
	}

	/**
	 * @testdox El módulo no lee ni un pedido ni una meta para decidir qué pinta.
	 *
	 * La otra mitad del candado anterior: que el filtro devuelva false no basta si
	 * alguien mete una consulta «sólo para saber». Lo que no se consulta, no se
	 * puede filtrar.
	 */
	public function test_no_consulta_datos_para_decidir(): void {
		$fuente = (string) file_get_contents(
			dirname( __DIR__, 3 ) . '/src/Modules/GuestOrderLinking/GuestOrderLinking.php'
		);
		$fuente = (string) preg_replace( '#/\*.*?\*/|//[^\n]*#s', '', $fuente );

		foreach ( array( 'wc_get_orders', 'billing_email', '$wpdb', 'get_user_meta', 'get_user_by', 'wc_get_customer' ) as $lectura ) {
			self::assertStringNotContainsString(
				$lectura,
				$fuente,
				sprintf( 'Consultar «%s» aquí es el primer paso para que la pantalla cuente algo de otro.', $lectura )
			);
		}
	}

	/**
	 * @testdox La línea se pinta con el enlace EXACTO de WooCommerce, una sola vez, y calla si WooCommerce ya habló.
	 *
	 * Hasta hoy ningún test ejecutaba `pintar_invitacion()`: se comprobaba qué
	 * hooks se enganchan, no qué sale por pantalla (revisión de Opus, 2026-09-11).
	 */
	public function test_la_linea_lleva_el_enlace_exacto_de_woocommerce(): void {
		Functions\when( 'wc_get_account_endpoint_url' )->justReturn( 'https://akibara.cl/mi-cuenta/orders/' );
		Functions\when( 'add_query_arg' )->alias(
			static fn( string $k, string $v, string $url ): string => $url . '?' . $k . '=' . $v
		);
		Functions\when( 'wp_nonce_url' )->alias(
			static fn( string $url, string $accion ): string => $url . '&_wpnonce=nonce-de-' . $accion
		);
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();

		$modulo = new GuestOrderLinking();

		// Sin que WooCommerce haya preguntado, no se pinta nada.
		ob_start();
		$modulo->pintar_invitacion();
		self::assertSame( '', ob_get_clean(), 'Sin filtro previo no hay aviso que sustituir: no se pinta.' );

		// WooCommerce iba a mostrar el aviso → se apaga y en su sitio va la línea.
		$modulo->silenciar_aviso( true, 42 );
		ob_start();
		$modulo->pintar_invitacion();
		$html = ob_get_clean();

		self::assertStringContainsString( 'class="akb-orders-claim"', $html );
		self::assertStringContainsString( 'wc_send_verification=1', $html, 'El parámetro que lee VerificationController::handle_send_request().' );
		self::assertStringContainsString( '_wpnonce=nonce-de-woocommerce-send-verification-email', $html, 'El nonce con la acción exacta de WooCommerce.' );
		self::assertStringContainsString( 'Confirma tu correo', $html );

		// Segunda llamada en la misma petición, aunque el filtro vuelva a hablar: nada.
		$modulo->silenciar_aviso( true, 42 );
		ob_start();
		$modulo->pintar_invitacion();
		self::assertSame( '', ob_get_clean(), 'Una sola línea por petición aunque la plantilla se pinte dos veces.' );
	}

	/**
	 * @testdox Si WooCommerce acaba de responder a un envío, la línea calla.
	 *
	 * Dentro del minuto de gracia volver a pulsar el enlace da «demasiado
	 * pronto»; la línea no puede invitar a hacer justo eso.
	 */
	public function test_la_linea_calla_cuando_woocommerce_ya_respondio(): void {
		$_GET['wc_verify_notice'] = 'sent';
		try {
			$modulo = new GuestOrderLinking();
			$modulo->silenciar_aviso( true, 42 );
			ob_start();
			$modulo->pintar_invitacion();
			self::assertSame( '', ob_get_clean() );
		} finally {
			unset( $_GET['wc_verify_notice'] );
		}
	}

	/**
	 * @testdox Los dos literales copiados de WooCommerce siguen existiendo allí.
	 *
	 * El enlace de «confirma tu correo» se arma con dos `private const` de
	 * WooCommerce que hay que duplicar. Si una actualización los renombra, el
	 * enlace queda muerto en la pantalla de un cliente sin que nadie se entere.
	 * Esta prueba los vuelve a leer del fuente de WooCommerce en cada corrida.
	 */
	public function test_los_literales_de_woocommerce_siguen_vivos(): void {
		$controlador = dirname( __DIR__, 4 )
			. '/woocommerce/src/Internal/CustomerEmailVerification/VerificationController.php';

		if ( ! is_readable( $controlador ) ) {
			self::markTestSkipped( 'WooCommerce no está en este árbol.' );
		}

		$fuente = (string) file_get_contents( $controlador );

		self::assertStringContainsString(
			"SEND_PARAM = 'wc_send_verification'",
			$fuente,
			'WooCommerce renombró el parámetro: hay que actualizar PARAM_ENVIAR o el enlace no hace nada.'
		);
		self::assertStringContainsString(
			"SEND_NONCE_ACTION = 'woocommerce-send-verification-email'",
			$fuente,
			'WooCommerce renombró la acción del nonce: el enlace fallaría la comprobación.'
		);
	}

	/**
	 * @testdox Ningún comentario vuelve a llamar «inerte» a este módulo (S-913a0783).
	 *
	 * Hasta el 2026-09-19, dos comentarios —éste y el de `Plugin.php`— afirmaban
	 * que `register()` «no engancha nada», falso desde el 2026-09-11: engancha
	 * el filtro que apaga el aviso de WooCommerce y la action que pinta el
	 * propio. `test_registering_the_module_hooks_only_display` ya prueba el
	 * comportamiento; este candado protege la documentación de volver a
	 * contradecirlo.
	 */
	public function test_no_comment_calls_the_module_inert(): void {
		$fuentes = array(
			dirname( __DIR__, 3 ) . '/src/Modules/GuestOrderLinking/GuestOrderLinking.php',
			dirname( __DIR__, 3 ) . '/src/Plugin.php',
		);

		foreach ( $fuentes as $ruta ) {
			$fuente = (string) file_get_contents( $ruta );
			self::assertStringNotContainsString(
				'INERTE desde 1.108.14',
				$fuente,
				"{$ruta} no puede volver a decir que GuestOrderLinking::register() no engancha nada."
			);
			self::assertDoesNotMatchRegularExpression(
				'/register\(\)`?\s*(no\s+engancha\s+nada|no\s+hace\s+nada)/i',
				$fuente,
				"{$ruta} describe register() como inofensivo, y hoy engancha dos hooks reales."
			);
		}
	}
}
