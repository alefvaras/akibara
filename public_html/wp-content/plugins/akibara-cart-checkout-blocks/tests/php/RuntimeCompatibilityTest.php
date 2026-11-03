<?php
/**
 * Pruebas de los parches de compatibilidad con terceros.
 *
 * @package Akibara\CartCheckoutBlocks\Tests
 */

declare( strict_types = 1 );

namespace Akibara\CartCheckoutBlocks\Tests;

use Akibara\CartCheckoutBlocks\BlocksPage;
use Akibara\CartCheckoutBlocks\RuntimeCompatibility;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class RuntimeCompatibilityTest extends TestCase {

	/**
	 * Árboles de plugins de mentira creados por la prueba en curso.
	 *
	 * @var array<int,string>
	 */
	private array $temporary_trees = array();

	protected function tearDown(): void {
		foreach ( $this->temporary_trees as $tree ) {
			$this->remove_tree( $tree );
		}
		$this->temporary_trees = array();

		parent::tearDown();
	}

	private function remove_tree( string $path ): void {
		if ( is_dir( $path ) ) {
			foreach ( (array) scandir( $path ) as $entry ) {
				if ( '.' !== $entry && '..' !== $entry ) {
					$this->remove_tree( $path . '/' . $entry );
				}
			}
			rmdir( $path );

			return;
		}

		if ( is_file( $path ) ) {
			unlink( $path );
		}
	}

	/**
	 * Candado por invariante: en una página Blocks se retira el selector de
	 * comunas clásico de Blue Express (y su gemelo Blocks, que es un no-op).
	 *
	 * Medido en Docker el 2026-08-23 sobre el HTML servido a un móvil:
	 * /carrito/ 233.911 → 227.428 bytes (−6.483) y /checkout/ 255.166 →
	 * 242.856 (−12.310, porque allí el catálogo de comunas venía DUPLICADO:
	 * `wc-city-select-js-extra` 5.589 B más `bluex-blocks-city-select-js-extra`
	 * 5.599 B). El candado fija el comportamiento, no los bytes.
	 *
	 * @dataProvider blocks_pages
	 *
	 * @testdox Should drop the classic Blue Express city select on a $page Block page.
	 */
	public function test_drops_classic_city_select_on_blocks_pages( string $page ): void {
		$sut = $this->integration( 'cart' === $page, 'checkout' === $page );

		$sut->dequeue_classic_city_select();

		self::assertSame(
			array( 'wc-city-select', 'bluex-blocks-city-select' ),
			$GLOBALS['akibara_test_dequeued'],
			'Las dos variantes del selector de comunas de Blue son peso muerto en Blocks.'
		);
	}

	/**
	 * @return array<string,array{string}>
	 */
	public function blocks_pages(): array {
		return array(
			'Cart Block'     => array( 'cart' ),
			'Checkout Block' => array( 'checkout' ),
		);
	}

	/**
	 * Fuera de Blocks NO se toca nada: el endpoint clásico de direcciones de Mi
	 * cuenta usa ese mismo `wc-city-select` y es el único selector que tiene.
	 *
	 * @testdox Should never touch the classic city select outside Block pages.
	 */
	public function test_keeps_classic_city_select_outside_blocks_pages(): void {
		$sut = $this->integration( false, false );

		$sut->dequeue_classic_city_select();

		self::assertSame( array(), $GLOBALS['akibara_test_dequeued'] );
	}

	/**
	 * Candado por invariante: los observadores de Mercado Pago se retiran por
	 * lista LITERAL, nunca por prefijo.
	 *
	 * Medido en Docker el 2026-08-23: en /carrito/ la cola de scripts trae
	 * `wc_mercadopago_woocommerce_scripts` y `wc_mercadopago_health_monitor`,
	 * que comparten prefijo con los retirados y deben sobrevivir. Un barrido
	 * por `wc_mercadopago_*` se los llevaría por delante.
	 *
	 * @testdox Should dequeue only the declared Mercado Pago handles, never by prefix.
	 */
	public function test_dequeues_only_declared_mercado_pago_handles(): void {
		$sut = $this->integration( true, false );

		$sut->dequeue_classic_mercado_pago_assets();

		$declared = ( new ReflectionClass( RuntimeCompatibility::class ) )
			->getConstant( 'MERCADO_PAGO_CLASSIC_HANDLES' );

		self::assertSame( $declared, $GLOBALS['akibara_test_dequeued'] );
		foreach ( array( 'wc_mercadopago_woocommerce_scripts', 'wc_mercadopago_health_monitor' ) as $survivor ) {
			self::assertNotContains(
				$survivor,
				$GLOBALS['akibara_test_dequeued'],
				'Los scripts que Mercado Pago sí necesita en Blocks no pueden caer por compartir prefijo.'
			);
		}
	}

	/**
	 * El barrido por prefijo es justamente lo que NO debe volver: fijamos que
	 * el código no recorre la cola de `wp_scripts()`.
	 *
	 * @testdox Should not sweep the script queue looking for a handle prefix.
	 */
	public function test_never_sweeps_the_script_queue(): void {
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/src/RuntimeCompatibility.php' );

		self::assertIsString( $source );
		self::assertStringNotContainsString( 'wp_scripts()->queue', $source );
		self::assertSame(
			0,
			preg_match( '/strpos\(\s*\$handle/', $source ),
			'Retirar por prefijo cambiaría una lista auditable por un barrido ciego sobre un tercero.'
		);
	}

	/**
	 * Site Health informa la VERDAD del código instalado del tercero, no de la
	 * cola de scripts (en admin la cola del frontend no existe y cualquier
	 * lectura diría siempre «ya no está registrado»).
	 *
	 * @testdox Should report both third-party patches as still required.
	 */
	public function test_health_test_reports_patches_still_required(): void {
		$plugins = $this->plugin_tree( true, false, 3 );
		$sut     = $this->integration( false, false, $plugins );

		$result = $sut->run_compatibility_health_test();

		self::assertSame( 'good', $result['status'] );
		self::assertStringContainsString( 'sigue sin empaquetar', $result['description'] );
		self::assertStringContainsString( 'sigue declarando 3 de 15', $result['description'] );
	}

	/**
	 * El día que Flow empaquete su `blocks.asset.php` y Mercado Pago deje de
	 * declarar los observadores, el parche sobra y hay que enterarse.
	 *
	 * @testdox Should flag both patches for review once the third parties fix themselves.
	 */
	public function test_health_test_flags_patches_that_became_useless(): void {
		$plugins = $this->plugin_tree( true, true, 0 );
		$sut     = $this->integration( false, false, $plugins );

		$result = $sut->run_compatibility_health_test();

		self::assertSame( 'recommended', $result['status'] );
		self::assertStringContainsString( 'sobra', $result['description'] );
		self::assertStringContainsString( 'quedó obsoleta', $result['description'] );
	}

	/**
	 * @testdox Should publish the compatibility check as a direct Site Health test.
	 */
	public function test_registers_the_health_test(): void {
		$sut   = $this->integration( false, false );
		$tests = $sut->register_compatibility_health_test( array( 'direct' => array() ) );

		self::assertArrayHasKey( 'akibara_blocks_third_party_patches', $tests['direct'] );
		self::assertSame(
			array( $sut, 'run_compatibility_health_test' ),
			$tests['direct']['akibara_blocks_third_party_patches']['test']
		);
	}

	private function integration( bool $cart, bool $checkout, ?string $plugin_dir = null ): RuntimeCompatibility {
		require_once __DIR__ . '/fixtures/woocommerce-functions.php';
		require_once __DIR__ . '/fixtures/runtime-compatibility.php';

		$GLOBALS['akibara_test_dequeued']    = array();
		$GLOBALS['akibara_test_blocks_page'] = array(
			'cart'     => $cart,
			'checkout' => $checkout,
		);

		if ( null !== $plugin_dir && ! defined( 'WP_PLUGIN_DIR' ) ) {
			define( 'WP_PLUGIN_DIR', $plugin_dir );
		}

		return new RuntimeCompatibility( new BlocksPage() );
	}

	/**
	 * Construye un árbol de plugins de mentira para las dos ramas del informe.
	 *
	 * @param bool $flow_installed  Si Flow está instalado.
	 * @param bool $flow_ships_asset Si Flow ya empaqueta blocks.asset.php.
	 * @param int  $mercado_pago_handles Cuántos observadores sigue declarando.
	 */
	private function plugin_tree( bool $flow_installed, bool $flow_ships_asset, int $mercado_pago_handles ): string {
		$root = sys_get_temp_dir() . '/akb-rc-' . uniqid( '', true );
		$this->temporary_trees[] = $root;

		if ( $flow_installed ) {
			mkdir( $root . '/flowpaymentfl/assets/js/frontend', 0777, true );
			if ( $flow_ships_asset ) {
				file_put_contents( $root . '/flowpaymentfl/assets/js/frontend/blocks.asset.php', '<?php return array();' );
			}
		}

		mkdir( $root . '/woocommerce-mercadopago/src/Gateways', 0777, true );
		mkdir( $root . '/woocommerce-mercadopago/src/Blocks', 0777, true );

		$declared = array_slice(
			(array) ( new ReflectionClass( RuntimeCompatibility::class ) )->getConstant( 'MERCADO_PAGO_CLASSIC_HANDLES' ),
			0,
			$mercado_pago_handles
		);
		$body     = '<?php ';
		foreach ( $declared as $handle ) {
			$body .= "wp_enqueue_script( '" . $handle . "' ); ";
		}
		file_put_contents( $root . '/woocommerce-mercadopago/src/Gateways/AbstractGateway.php', $body );

		return $root;
	}
	/**
	 * @testdox La señal distingue «Mercado Pago los declara» de «el carrito los retira».
	 *
	 * Son dos preguntas distintas y la señal anterior contestaba la primera
	 * diciendo que contestaba la segunda: informaba «el carrito Blocks los sigue
	 * retirando» mirando sólo si Mercado Pago seguía declarando los handles en su
	 * código. Medido en Docker el 2026-08-24 sobre el HTML servido de /carrito/
	 * con producto: de los 15 de la lista se encolan CERO —los que sí aparecen
	 * son otros cuatro, ninguno en la lista—, o sea que el desencolado no retiraba
	 * nada mientras la pantalla de salud decía que sí.
	 *
	 * Un parche que sobrevive a su motivo con una señal que afirma lo contrario es
	 * exactamente lo que esa señal venía a evitar.
	 */
	public function test_health_signal_reports_what_the_cart_actually_dequeued(): void {
		$modulo = $this->integration( true, false );

		$GLOBALS['akibara_test_options']          = array();
		$GLOBALS['akb_test_enqueued_scripts'] = array();
		$modulo->dequeue_classic_mercado_pago_assets();

		$observado = $GLOBALS['akibara_test_options']['akb_blocks_mp_dequeue_observed'] ?? null;
		self::assertIsArray( $observado, 'La pasada del carrito tiene que dejar constancia de lo que retiró.' );
		self::assertSame( 0, (int) $observado['retirados'], 'Con la cola vacía no se retira nada, y eso hay que poder verlo.' );

		// Y con handles realmente encolados, el número tiene que subir.
		$GLOBALS['akb_test_enqueued_scripts'] = array( 'wc_mercadopago_checkout_update', 'wc_mercadopago_supertoken' );
		$modulo->dequeue_classic_mercado_pago_assets();

		$observado = $GLOBALS['akibara_test_options']['akb_blocks_mp_dequeue_observed'] ?? null;
		self::assertSame( 2, (int) $observado['retirados'], 'Lo que se cuenta es lo que estaba en la cola, no lo que la lista espera.' );
	}

	/**
	 * @testdox La señal fecha el último CAMBIO de la cuenta, nunca «la última carga».
	 *
	 * La option sólo se reescribe cuando `retirados` cambia, así que su sello se
	 * congela ahí. La frase decía «en la última carga del carrito Blocks (hace
	 * X)»: a los seis meses de operación normal, con el carrito cargándose a
	 * diario, el dueño leería que su carrito no se carga hace medio año. Es una
	 * señal que existe justamente para no engañar.
	 */
	public function test_health_signal_dates_the_change_not_the_last_cart_load(): void {
		$plugins = $this->plugin_tree( true, false, 3 );
		$modulo  = $this->integration( true, false, $plugins );

		$GLOBALS['akibara_test_options']          = array();
		$GLOBALS['akb_test_enqueued_scripts'] = array();
		$modulo->dequeue_classic_mercado_pago_assets();

		$sin_encolados = $modulo->run_compatibility_health_test();

		$GLOBALS['akb_test_enqueued_scripts'] = array( 'wc_mercadopago_checkout_update', 'wc_mercadopago_supertoken' );
		$modulo->dequeue_classic_mercado_pago_assets();

		$con_encolados = $modulo->run_compatibility_health_test();

		foreach ( array( $sin_encolados, $con_encolados ) as $resultado ) {
			self::assertStringNotContainsString(
				'última carga del carrito',
				$resultado['description'],
				'El sello guardado es el del último CAMBIO de la cuenta, no el de una carga cualquiera.'
			);
			self::assertStringContainsString( 'la última vez que esa cuenta cambió', $resultado['description'] );
		}

		self::assertStringContainsString( 'no encontró NINGUNO encolado', $sin_encolados['description'] );
		self::assertStringContainsString( 'retiró 2', $con_encolados['description'] );
	}
}
