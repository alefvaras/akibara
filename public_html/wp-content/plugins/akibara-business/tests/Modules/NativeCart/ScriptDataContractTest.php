<?php
/**
 * Candado: cada clave que el drawer LEE tiene que existir en lo que el servidor EMITE.
 *
 * El aviso de preventa del carrito jamás se pintó. `to_script_data()` publicaba
 * `showPedidoPolicy` (renombrado cuando el vocabulario pasó de «preventa» a
 * «pedido») y `renderStatus()` seguía preguntando por `settings.showPreventaPolicy`,
 * que llegaba `undefined` — o sea siempre falso. El interruptor del admin decía
 * «encendido», producción corría con el default `yes`, y el cliente nunca vio una
 * sola línea. Ningún test lo vio porque los que había comprobaban la existencia
 * del interruptor y del copy por separado, nunca que las dos puntas nombraran lo
 * mismo.
 *
 * La invariante que se fija aquí no es una lista de claves escrita a mano —esa
 * también se desincroniza—: es que el CONJUNTO leído por el JS esté contenido en
 * el CONJUNTO emitido por PHP. Se cierra tanto para las claves de primer nivel
 * como para las de `copy`.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\NativeCart;

use Akibara\Business\Modules\NativeCart\Settings;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class ScriptDataContractTest extends TestCase {

	/**
	 * Claves que NO vienen de `to_script_data()` sino del array que
	 * `enqueue_assets()` le hace merge en `wp_localize_script()`. Se verifican
	 * aparte contra el fuente para que la lista no sea una promesa.
	 */
	private const LOCALIZED_EXTRAS = array( 'ajaxUrl', 'cartUrl', 'checkoutUrl', 'nonce' );

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'wp_strip_all_tags' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wc_get_page_permalink' )->justReturn( 'https://akibara.cl/tienda/' );
		Functions\when( 'get_option' )->alias( static fn( string $option, $default = false ) => $default );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function frontendSource(): string {
		return (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/js/native-cart.js' );
	}

	/**
	 * Claves de primer nivel que el JS consulta sobre el objeto de config
	 * (`config.X` es el localizado; `settings.X` es la copia fresca que viaja en
	 * cada respuesta del endpoint — el mismo array).
	 *
	 * @return array<int, string>
	 */
	private function keys_read_by_javascript(): array {
		preg_match_all( '/\b(?:config|settings)\.([A-Za-z_][A-Za-z0-9_]*)/', $this->frontendSource(), $m );

		return array_values( array_unique( $m[1] ) );
	}

	/**
	 * Claves de `copy` que el JS consulta, por las dos vías que usa: el helper
	 * `text('clave', fallback)` y el acceso directo `settings.copy.clave`.
	 *
	 * @return array<int, string>
	 */
	private function copy_keys_read_by_javascript(): array {
		$source = $this->frontendSource();

		preg_match_all( "/\btext\(\s*'([A-Za-z0-9_]+)'/", $source, $helper );
		preg_match_all( '/\b(?:config|settings)\.copy\.([A-Za-z_][A-Za-z0-9_]*)/', $source, $direct );

		return array_values( array_unique( array_merge( $helper[1], $direct[1] ) ) );
	}

	/**
	 * @testdox Toda clave que el drawer lee la emite el servidor.
	 */
	public function test_every_key_the_drawer_reads_is_emitted(): void {
		$emitidas = array_merge(
			array_keys( ( new Settings() )->to_script_data() ),
			self::LOCALIZED_EXTRAS
		);

		$leidas = $this->keys_read_by_javascript();
		self::assertNotEmpty( $leidas, 'Si el barrido no encuentra nada, el candado no está midiendo nada.' );

		foreach ( $leidas as $clave ) {
			self::assertContains(
				$clave,
				$emitidas,
				sprintf(
					'`%s` la lee assets/js/native-cart.js y no viaja en el payload: llega `undefined`, o sea apagada para siempre.',
					$clave
				)
			);
		}
	}

	/**
	 * @testdox Toda cadena de `copy` que el drawer pide existe en el payload.
	 */
	public function test_every_copy_key_the_drawer_reads_is_emitted(): void {
		$copy = ( new Settings() )->to_script_data()['copy'];
		self::assertIsArray( $copy );

		$leidas = $this->copy_keys_read_by_javascript();
		self::assertNotEmpty( $leidas );

		foreach ( $leidas as $clave ) {
			self::assertArrayHasKey(
				$clave,
				$copy,
				sprintf( '`copy.%s` se pide en el drawer y no se emite: el cliente ve el fallback en duro, no el texto del dueño.', $clave )
			);
		}
	}

	/**
	 * @testdox Las claves que no salen de to_script_data() las pone enqueue_assets().
	 */
	public function test_the_localized_extras_really_come_from_enqueue_assets(): void {
		$php = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Modules/NativeCart/NativeCart.php' );

		foreach ( self::LOCALIZED_EXTRAS as $clave ) {
			self::assertMatchesRegularExpression(
				"/'" . preg_quote( $clave, '/' ) . "'\s*=>/",
				$php,
				'La lista de extras del candado tiene que corresponder al merge real de wp_localize_script().'
			);
		}
	}

	/**
	 * @testdox El interruptor del aviso de preventa gobierna la clave que el JS consulta.
	 */
	public function test_the_preventa_toggle_drives_the_key_the_javascript_reads(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $option, $default = false ) => Settings::OPTION_SHOW_PREVENTA_POLICY === $option ? 'no' : $default
		);
		self::assertFalse( ( new Settings() )->to_script_data()['showPreventaPolicy'] );

		Functions\when( 'get_option' )->alias(
			static fn( string $option, $default = false ) => Settings::OPTION_SHOW_PREVENTA_POLICY === $option ? 'yes' : $default
		);
		self::assertTrue( ( new Settings() )->to_script_data()['showPreventaPolicy'] );
	}
}
