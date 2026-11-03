<?php
/**
 * Contrato ejecutable de `inc/asset-dequeue.php`.
 *
 * R3-2c3c4720 (2026-09-19), medido en producción con un carrito de invitado de
 * 1 producto: /contacto/ y /faq/ servían `data-akb-cart-count="0"` (el conteo
 * de la copia en caché de Cloudflare/LiteSpeed) porque
 * `akibara_gp_dequeue_unused_assets()` retiraba `wc-cart-fragments` —el único
 * mecanismo que corrige el badge del header contra HTML servido desde
 * caché— en TODA página sin rejilla de producto, incluidas las de contenido
 * estático y las entradas de blog. El arreglo separa `wc-cart-fragments` de
 * `wc-add-to-cart`/`woocommerce`: los dos últimos siguen saliendo donde no hay
 * botón de compra, `wc-cart-fragments` se queda encolado en cualquier página
 * de invitado que no sea carrito/checkout/mi-cuenta.
 *
 * @package akibara-gp\Tests
 */

declare( strict_types = 1 );

namespace Akibara\GP\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class AssetDequeueTest extends TestCase {

	/** @var array<int, string> */
	private array $scripts_dequeued = array();

	/** @var array<int, string> */
	private array $styles_dequeued = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->scripts_dequeued = array();
		$this->styles_dequeued  = array();

		Functions\when( 'wp_dequeue_script' )->alias(
			function ( $handle ): void {
				$this->scripts_dequeued[] = $handle;
			}
		);
		Functions\when( 'wp_dequeue_style' )->alias(
			function ( $handle ): void {
				$this->styles_dequeued[] = $handle;
			}
		);

		// El require vive en setUp(), DESPUÉS de Monkey\setUp(): el fichero
		// registra `add_action( 'wp_enqueue_scripts', ..., 99 )` a nivel de
		// módulo, y `add_action()` sólo existe como no-op una vez que Brain
		// Monkey inicializó sus stubs de WordPress (mismo patrón que
		// WooCommerceHooksTest para inc/woocommerce.php).
		require_once dirname( __DIR__ ) . '/inc/page-targets.php';
		require_once dirname( __DIR__ ) . '/inc/asset-dequeue.php';
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Deja todas las condicionales de ruta en falso salvo las que se pidan.
	 * Cubre tanto las que consulta `akibara_gp_dequeue_unused_assets()`
	 * directo como las que consulta, por debajo, `akibara_gp_surface_has_
	 * product_loop()` (inc/page-targets.php).
	 *
	 * `is_product_taxonomy` entra por CONTAGIO (memoria del proyecto,
	 * «function-exists-miente-en-la-suite»): en orden `reverse`, otro fichero
	 * de esta misma suite (WooCommerceHooksTest) carga inc/woocommerce.php
	 * antes que este, y `akibara_gp_is_catalog_archive()` —que sí llama a
	 * `is_product_taxonomy()`— pasa de function_exists()=false a true para
	 * TODO el resto del proceso. Sin estabearla aquí, esta suite pasa en
	 * orden natural y truena sólo con `--order-by=reverse`.
	 *
	 * @param array<string, mixed> $verdaderas Nombre de función => valor.
	 */
	private function ruta( array $verdaderas = array() ): void {
		foreach ( array(
			'is_checkout',
			'is_cart',
			'is_account_page',
			'is_shop',
			'is_product',
			'is_product_category',
			'is_product_tag',
			'is_product_taxonomy',
			'is_search',
			'is_404',
			'is_front_page',
			'is_page',
		) as $condicional ) {
			Functions\when( $condicional )->justReturn( $verdaderas[ $condicional ] ?? false );
		}
		Functions\when( 'is_woocommerce' )->justReturn( $verdaderas['is_woocommerce'] ?? true );
		// La página del carrito (id 6 en el espejo) y si lleva el bloque de carrito.
		Functions\when( 'wc_get_page_id' )->justReturn( 6 );
		Functions\when( 'has_block' )->alias(
			static fn( $bloque, $post = null ) => 'woocommerce/cart' === $bloque && 6 === $post && ( $verdaderas['carrito_de_bloques'] ?? false )
		);
		Functions\when( 'is_tax' )->justReturn( $verdaderas['is_tax'] ?? false );
		Functions\when( 'get_query_var' )->justReturn( $verdaderas['get_query_var'] ?? '' );
	}

	/**
	 * EL DEFECTO QUE CIERRA. Antes `wc-cart-fragments` salía junto con
	 * `wc-add-to-cart`/`woocommerce` en cualquier página sin rejilla de
	 * producto — exactamente las páginas de contenido estático (contacto,
	 * faq, ...) que Cloudflare/LiteSpeed sirven desde caché de invitado, y
	 * donde `wc-cart-fragments` es el único mecanismo que corrige el badge
	 * del carrito contra ese HTML cacheado.
	 *
	 * @testdox Una página de contenido sin rejilla retira add-to-cart/woocommerce pero deja wc-cart-fragments encolado.
	 */
	public function test_content_page_keeps_cart_fragments_but_drops_the_rest(): void {
		$this->ruta(); // Todo en falso: ninguna condicional de rejilla se cumple.

		akibara_gp_dequeue_unused_assets();

		self::assertContains( 'wc-add-to-cart', $this->scripts_dequeued );
		self::assertContains( 'woocommerce', $this->scripts_dequeued );
		self::assertNotContains(
			'wc-cart-fragments',
			$this->scripts_dequeued,
			'wc-cart-fragments es el único mecanismo que corrige el badge del header contra el HTML cacheado; no debe retirarse en páginas de contenido.'
		);
		self::assertContains( 'woocommerce-layout', $this->styles_dequeued );
		self::assertContains( 'woocommerce-smallscreen', $this->styles_dequeued );
		self::assertContains( 'woocommerce-general', $this->styles_dequeued );
	}

	/**
	 * Blog y demás `is_singular('post')` entran por la misma rama que
	 * cualquier página de contenido: ninguna condicional de rejilla de
	 * producto los cubre.
	 *
	 * @testdox Una entrada de blog también conserva wc-cart-fragments.
	 */
	public function test_blog_single_keeps_cart_fragments(): void {
		$this->ruta();

		akibara_gp_dequeue_unused_assets();

		self::assertNotContains( 'wc-cart-fragments', $this->scripts_dequeued );
	}

	/**
	 * @testdox Una superficie con rejilla (tienda) no retira ningún script de carrito.
	 */
	public function test_shop_keeps_every_cart_script(): void {
		$this->ruta( array( 'is_shop' => true ) );

		akibara_gp_dequeue_unused_assets();

		// La tienda SÍ dequeuea los calculadores de envío (no está en
		// carrito/checkout), pero ninguno de los tres scripts de carrito.
		self::assertNotContains( 'wc-add-to-cart', $this->scripts_dequeued );
		self::assertNotContains( 'woocommerce', $this->scripts_dequeued );
		self::assertNotContains( 'wc-cart-fragments', $this->scripts_dequeued );
		self::assertSame( array(), $this->styles_dequeued );
	}

	/**
	 * QAM-3 (QA integral, 2026-09-24): en /carrito/ de bloques, frontend/cart.js
	 * no tiene formulario que manejar pero recarga la página entera al oír
	 * `added_to_cart`: agregar desde «Más vendidos» del carrito vacío costaba una
	 * recarga completa.
	 *
	 * @testdox En el carrito de bloques se retira el guion del carrito clásico (y nada más); en un carrito clásico, no.
	 */
	public function test_block_cart_drops_the_classic_cart_script(): void {
		$this->ruta( array( 'is_cart' => true, 'carrito_de_bloques' => true ) );
		akibara_gp_dequeue_unused_assets();
		self::assertSame( array( 'wc-cart' ), $this->scripts_dequeued, 'Sólo wc-cart: el carrito sigue necesitando todo lo demás.' );
		self::assertSame( array(), $this->styles_dequeued );

		$this->scripts_dequeued = array();
		$this->ruta( array( 'is_cart' => true, 'carrito_de_bloques' => false ) );
		akibara_gp_dequeue_unused_assets();
		self::assertSame( array(), $this->scripts_dequeued, 'Un carrito clásico usa cart.js: se queda.' );

		// Fuera del carrito no se toca aunque la página del carrito sea de bloques.
		$this->scripts_dequeued = array();
		$this->ruta( array( 'is_checkout' => true, 'carrito_de_bloques' => true ) );
		akibara_gp_dequeue_unused_assets();
		self::assertNotContains( 'wc-cart', $this->scripts_dequeued );
	}

	/**
	 * @testdox Checkout, carrito y mi-cuenta salen temprano sin tocar ningún handle.
	 */
	public function test_dynamic_cart_surfaces_bail_before_touching_anything(): void {
		foreach ( array( 'is_checkout', 'is_cart', 'is_account_page' ) as $condicional ) {
			$this->ruta( array( $condicional => true ) );

			akibara_gp_dequeue_unused_assets();

			self::assertSame( array(), $this->scripts_dequeued, "{$condicional} debe salir sin dequeuear scripts." );
			self::assertSame( array(), $this->styles_dequeued, "{$condicional} debe salir sin dequeuear estilos." );
		}
	}

	/**
	 * No retirarlo no basta: WooCommerce ya no lo encola solo, y en producción
	 * llegaba por un tercero (el seguimiento de páginas de Brevo). Medido en el
	 * espejo, sin Brevo: ninguna página lo cargaba. El tema lo encola él mismo.
	 *
	 * @testdox El tema encola wc-cart-fragments en toda página cacheable, y no en carrito, checkout ni Mi cuenta.
	 */
	public function test_the_theme_enqueues_cart_fragments_itself_on_cacheable_pages(): void {
		$encolados = array();
		Functions\when( 'wp_enqueue_script' )->alias(
			static function ( $handle ) use ( &$encolados ): void {
				$encolados[] = $handle;
			}
		);

		$this->ruta(); // Página de contenido.
		akibara_gp_enqueue_cart_badge_sync();
		$this->ruta( array( 'is_shop' => true ) );
		akibara_gp_enqueue_cart_badge_sync();
		self::assertSame( array( 'wc-cart-fragments', 'wc-cart-fragments' ), $encolados, 'Contenido y tienda: los dos pintan el contador desde HTML cacheado.' );

		foreach ( array( 'is_checkout', 'is_cart', 'is_account_page' ) as $condicional ) {
			$encolados = array();
			$this->ruta( array( $condicional => true ) );
			akibara_gp_enqueue_cart_badge_sync();
			self::assertSame( array(), $encolados, "{$condicional} no se cachea: el contador ya sale bien del servidor." );
		}
	}

	/**
	 * @testdox Los calculadores de envío sólo se retiran fuera de carrito/checkout.
	 */
	public function test_shipping_calculators_only_drop_outside_cart_and_checkout(): void {
		$this->ruta();

		akibara_gp_dequeue_unused_assets();

		self::assertContains( 'wc-country-select', $this->scripts_dequeued );
		self::assertContains( 'wc-address-i18n', $this->scripts_dequeued );
	}
}
