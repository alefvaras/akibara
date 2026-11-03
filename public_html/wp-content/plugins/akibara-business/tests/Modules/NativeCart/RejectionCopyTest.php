<?php
/**
 * QAM-6 (QA integral 2026-09-24): el motivo de un «Añadir al carrito»
 * rechazado sale con las palabras que el dueño edita en Akibara → Textos.
 *
 * Antes la tarjeta y la barra de compra pintaban la frase de WooCommerce sin
 * singular —«tenemos 1 existencias y has añadido 1 en tu carrito»—, lo más
 * frecuente en una tienda con 956 tomos de una sola unidad.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\NativeCart;

use Akibara\Business\Modules\AdminPanel\AdminPanel;
use Akibara\Business\Modules\CopyManager\CopyManager;
use Akibara\Business\Modules\NativeCart\RejectionCopy;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Tomo con el stock que pida cada caso: las preguntas de `WC_Product` que hace
 * `RejectionReason`, con la regla de `has_enough_stock()` de WooCommerce.
 */
final class RejectionCopyProductDouble extends \WC_Product {

	public int $unidades = 1;

	public bool $en_stock = true;

	public function get_name(): string {
		return 'Dan Da Dan 02';
	}

	public function get_stock_managed_by_id(): int {
		return 12952;
	}

	public function is_sold_individually(): bool {
		return false;
	}

	public function is_purchasable(): bool {
		return true;
	}

	public function is_in_stock(): bool {
		return $this->en_stock;
	}

	public function managing_stock(): bool {
		return true;
	}

	/**
	 * @param int $cantidad Unidades.
	 */
	public function has_enough_stock( $cantidad ): bool {
		return $this->unidades >= $cantidad;
	}
}

final class RejectionCopyTest extends TestCase {

	/** Aviso de WooCommerce 11.1 en español, con su enlace. */
	private const AVISO_YA_EN_CARRITO = 'No puedes añadir esa cantidad al carrito &mdash; tenemos 1 existencias y has añadido 1 en tu carrito. <a href="https://akibara.cl/carrito/" class="button wc-forward">Ver carrito</a>';

	private const AVISO_NO_ALCANZA = 'No puedes añadir esa cantidad de &quot;Dan Da Dan 02&quot; al carrito porque no hay suficientes existencias (quedan 1).';

	private const AVISO_AGOTADO = 'No puedes añadir «Dan Da Dan 02» al carrito porque el producto está agotado.';

	/**
	 * The System Under Test.
	 *
	 * @var RejectionCopy
	 */
	private $sut;

	/** @var array<string, mixed> */
	private array $options = array();

	/** @var array<int, int> Unidades en el carrito por id gestionado. */
	private array $carrito = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->options = array();
		$this->carrito = array();
		Functions\when( 'get_option' )->alias( fn( string $name, $default = false ) => $this->options[ $name ] ?? $default );
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( $v ): string => (string) preg_replace( '/<[^>]*>/', '', (string) $v ) );
		Functions\when( 'esc_html' )->alias( static fn( $v ): string => htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ) );
		// Sin `WC()`: definirla con Brain Monkey la deja definida para el resto de
		// la suite, y las clases que preguntan `function_exists( 'WC' )` cambian
		// de camino (AccessWall, GlobalToggle; medido con --order-by=random).
		$this->sut = new RejectionCopy( fn (): array => $this->carrito );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function tomo( int $unidades, bool $en_stock = true ): RejectionCopyProductDouble {
		$tomo           = new RejectionCopyProductDouble();
		$tomo->unidades = $unidades;
		$tomo->en_stock = $en_stock;

		return $tomo;
	}

	/**
	 * @testdox Sin tocar el panel, el tomo de una unidad ya en el carrito dice su motivo con el singular bien puesto.
	 */
	public function test_ya_en_carrito_de_fabrica(): void {
		$aviso = $this->sut->handle_already_in_cart_message( self::AVISO_YA_EN_CARRITO, $this->tomo( 1 ), 1, 1 );

		self::assertStringStartsWith( RejectionCopy::DEFAULT_YA_EN_CARRITO, $aviso );
		self::assertStringNotContainsString( '1 existencias', $aviso );
		self::assertStringEndsWith( '<a href="https://akibara.cl/carrito/" class="button wc-forward">Ver carrito</a>', $aviso, 'El «Ver carrito» de WooCommerce se conserva.' );
	}

	/**
	 * @testdox Con dos unidades y una en el carrito cabe otra: el aviso sigue siendo el de WooCommerce, que dice cuántas quedan.
	 */
	public function test_si_cabe_una_mas_no_se_toca(): void {
		$original = 'No puedes añadir esa cantidad al carrito &mdash; tenemos 2 existencias y has añadido 1 en tu carrito.';

		self::assertSame( $original, $this->sut->handle_already_in_cart_message( $original, $this->tomo( 2 ), 2, 1 ) );
	}

	/**
	 * @testdox Pedir más de lo que hay con el carrito ya lleno también es «ya en el carrito».
	 */
	public function test_no_alcanza_con_el_carrito_lleno(): void {
		$this->carrito = array( 12952 => 1 );

		self::assertSame( RejectionCopy::DEFAULT_YA_EN_CARRITO, $this->sut->handle_not_enough_stock_message( self::AVISO_NO_ALCANZA, $this->tomo( 1 ), 1 ) );
	}

	/**
	 * @testdox Pedir más de lo que hay con el carrito vacío deja el aviso que cuenta cuántas quedan.
	 */
	public function test_no_alcanza_con_el_carrito_vacio(): void {
		self::assertSame( self::AVISO_NO_ALCANZA, $this->sut->handle_not_enough_stock_message( self::AVISO_NO_ALCANZA, $this->tomo( 1 ), 1 ) );
	}

	/**
	 * @testdox Un tomo agotado dice de fábrica exactamente lo que ya decía WooCommerce.
	 */
	public function test_agotado_de_fabrica_es_la_frase_de_siempre(): void {
		self::assertSame( self::AVISO_AGOTADO, html_entity_decode( $this->sut->handle_out_of_stock_message( self::AVISO_AGOTADO, $this->tomo( 0, false ) ), ENT_QUOTES, 'UTF-8' ) );
	}

	/**
	 * @testdox El texto del dueño cambia {producto} por el nombre del tomo y deja cualquier otro «%» tal cual.
	 */
	public function test_el_texto_del_dueno_con_marcador_y_sin_sprintf(): void {
		$this->options[ RejectionCopy::OPTION_SIN_STOCK ] = '«{producto}» se agotó al 100%s — %1$d';

		self::assertSame(
			'«Dan Da Dan 02» se agotó al 100%s — %1$d',
			RejectionCopy::text( 'sin_stock', $this->tomo( 0, false ) )
		);
	}

	/**
	 * @testdox El texto del dueño sale en una línea, sin etiquetas y escapado.
	 */
	public function test_el_texto_del_dueno_se_limpia_y_se_escapa(): void {
		$this->options[ RejectionCopy::OPTION_YA_EN_CARRITO ] = "Ya tienes <b>todo</b>\nlo que queda & más";

		self::assertSame(
			'Ya tienes todo lo que queda &amp; más <a href="https://akibara.cl/carrito/" class="button wc-forward">Ver carrito</a>',
			$this->sut->handle_already_in_cart_message( self::AVISO_YA_EN_CARRITO, $this->tomo( 1 ), 1, 1 )
		);
	}

	/**
	 * @testdox Guardado y lectura usan el mismo saneador: lo que el panel guarda es lo que la tienda muestra, y vacío vuelve al de fábrica.
	 */
	public function test_guardado_y_lector_dicen_lo_mismo(): void {
		$panel = new CopyManager( new AdminPanel() );
		$tomo  = $this->tomo( 1 );

		foreach ( array( '', '   ', "<i>Ya</i>\n\nlo tienes todo", 'Ya está en tu carrito: {producto}' ) as $escrito ) {
			$guardado = $panel->sanitize_required_copy( $escrito, array( 'id' => RejectionCopy::OPTION_YA_EN_CARRITO ), $escrito );
			$this->options[ RejectionCopy::OPTION_YA_EN_CARRITO ] = $escrito;
			$leido_crudo = RejectionCopy::text( 'ya_en_carrito', $tomo );
			$this->options[ RejectionCopy::OPTION_YA_EN_CARRITO ] = $guardado;

			self::assertSame( $leido_crudo, RejectionCopy::text( 'ya_en_carrito', $tomo ), 'El lector no puede aceptar algo distinto de lo que guarda el panel: «' . $escrito . '».' );
		}
		self::assertSame( RejectionCopy::DEFAULT_YA_EN_CARRITO, $panel->sanitize_required_copy( '', array( 'id' => RejectionCopy::OPTION_YA_EN_CARRITO ), '' ) );
		self::assertSame( RejectionCopy::DEFAULT_SIN_STOCK, $panel->sanitize_required_copy( ' ', array( 'id' => RejectionCopy::OPTION_SIN_STOCK ), ' ' ) );
	}

	/**
	 * @testdox Los dos textos están en Akibara → Textos con su literal de fábrica.
	 */
	public function test_los_textos_estan_en_el_panel(): void {
		Functions\when( 'get_posts' )->justReturn( array() );
		$campos = array();
		foreach ( ( new CopyManager( new AdminPanel() ) )->get_settings() as $campo ) {
			if ( isset( $campo['id'] ) ) {
				$campos[ $campo['id'] ] = $campo;
			}
		}

		foreach ( RejectionCopy::defaults() as $option => $fabrica ) {
			self::assertArrayHasKey( $option, $campos, 'Un texto que el cliente lee tiene que poder editarse sin código.' );
			self::assertSame( $fabrica, $campos[ $option ]['default'] );
			self::assertSame( 'text', $campos[ $option ]['type'] );
		}
	}

	/**
	 * @testdox Se engancha a los tres avisos de stock de WooCommerce.
	 */
	public function test_registra_los_filtros(): void {
		$this->sut->register();

		self::assertNotFalse( has_filter( 'woocommerce_cart_product_not_enough_stock_already_in_cart_message', array( $this->sut, 'handle_already_in_cart_message' ) ) );
		self::assertNotFalse( has_filter( 'woocommerce_cart_product_not_enough_stock_message', array( $this->sut, 'handle_not_enough_stock_message' ) ) );
		self::assertNotFalse( has_filter( 'woocommerce_cart_product_out_of_stock_message', array( $this->sut, 'handle_out_of_stock_message' ) ) );
	}
}
