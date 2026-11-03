<?php
/**
 * El motivo de los tomos que no entran al carrito desde un pack, y su texto.
 *
 * Deuda previa (verificación del lote de UX del tema, 2026-09-25): «Arma tu
 * pack» y «Elegir tomos» respondían un texto fijo, «No se pudo agregar.
 * Inténtalo de nuevo.», fuera cual fuera el motivo, y dejaban marcados tomos que
 * un reintento nunca iba a sumar.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\SeriesBundles;

use Akibara\Business\Modules\AdminPanel\AdminPanel;
use Akibara\Business\Modules\CopyManager\CopyManager;
use Akibara\Business\Modules\SeriesBundles\SelectionCart;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/** Tomo con el stock que pida cada caso: las preguntas de `RejectionReason`, con la regla de WooCommerce. */
final class SelectionCartProductDouble extends \WC_Product {

	public int $unidades = 1;

	public function is_sold_individually(): bool {
		return false;
	}

	public function is_in_stock(): bool {
		return 'outofstock' !== $this->get_stock_status();
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

	public function get_stock_managed_by_id(): int {
		return (int) $this->get_id();
	}
}

/**
 * Aislamiento necesario para declarar el doble global `WC_Cart`.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class SelectionCartTest extends TestCase {

	/** @var array<int, SelectionCartProductDouble> */
	private array $productos = array();

	/** @var array<string, string> */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		if ( ! class_exists( '\WC_Cart' ) ) {
			eval(
				'namespace { class WC_Cart {
					public array $results;
					public array $quantities;
					public function __construct( array $results = array(), array $quantities = array() ) { $this->results = $results; $this->quantities = $quantities; }
					public function add_to_cart( int $id, int $qty = 1 ) {
						unset( $qty );
						$ok = $this->results[ $id ] ?? true;
						if ( ! $ok ) { $GLOBALS["akibara_selection_notices"]["error"][] = array( "notice" => "Aviso de WooCommerce." ); }
						return $ok;
					}
					public function get_cart_item_quantities() { return $this->quantities; }
				} }'
			);
		}

		$this->productos = array();
		$this->options   = array();
		$GLOBALS['akibara_selection_notices'] = array();
		Functions\when( 'wc_get_notices' )->alias( static fn(): array => $GLOBALS['akibara_selection_notices'] );
		Functions\when( 'wc_set_notices' )->alias(
			static function ( $avisos ): void {
				$GLOBALS['akibara_selection_notices'] = $avisos;
			}
		);
		Functions\when( 'wc_get_product' )->alias( fn( int $id ) => $this->productos[ $id ] ?? null );
		Functions\when( 'get_option' )->alias( fn( string $name, $default = false ) => $this->options[ $name ] ?? $default );
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( $v ): string => (string) preg_replace( '/<[^>]*>/', '', (string) $v ) );
		Functions\when( 'sanitize_text_field' )->alias( static fn( $v ): string => trim( (string) preg_replace( '/\s+/', ' ', (string) $v ) ) );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['akibara_selection_notices'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	private function tomo( int $id, string $stock = 'instock', int $unidades = 1 ): void {
		$tomo           = new SelectionCartProductDouble( $stock );
		$tomo->akb_id   = $id;
		$tomo->unidades = $unidades;

		$this->productos[ $id ] = $tomo;
	}

	/** @testdox Todo lo elegido ya está en el carrito con todas sus unidades: el texto «ya en el carrito», y reintentar no se ofrece. */
	public function test_todo_ya_en_el_carrito(): void {
		$this->tomo( 1 );
		$this->tomo( 2 );

		$r = SelectionCart::add( new \WC_Cart( array( 1 => false, 2 => false ), array( 1 => 1, 2 => 1 ) ), array( 1, 2 ), array( 1, 2 ) );

		self::assertSame( array( 'added_ids' => array(), 'rejected_ids' => array( 1, 2 ), 'message' => SelectionCart::DEFAULT_YA_EN_CARRITO ), $r );
	}

	/** @testdox Un tomo que se agotó mientras el cliente elegía no pasa la puerta del catálogo, pero tiene motivo: se desmarca y se dice. */
	public function test_agotado_fuera_de_la_puerta(): void {
		$this->tomo( 1, 'outofstock', 0 );
		$this->tomo( 2 );

		$r = SelectionCart::add( new \WC_Cart(), array( 1, 2 ), array( 2 ) );

		self::assertSame( array( 2 ), $r['added_ids'] );
		self::assertSame( array( 1 ), $r['rejected_ids'] );
		self::assertSame( SelectionCart::DEFAULT_SIN_STOCK, $r['message'], 'Algo entró, pero el agotado hay que explicarlo.' );
	}

	/** @testdox Si queda uno sin motivo que el estado explique, manda el texto de reintentar: ése sí sigue marcado. */
	public function test_sin_motivo_manda_reintentar(): void {
		$this->tomo( 1 );
		$this->tomo( 2, 'instock', 3 );
		$this->tomo( 3, 'outofstock', 0 );

		$r = SelectionCart::add( new \WC_Cart( array( 1 => false, 2 => false ), array( 1 => 1 ) ), array( 1, 2, 3 ), array( 1, 2 ) );

		self::assertSame( array(), $r['added_ids'] );
		self::assertSame( array( 1, 3 ), $r['rejected_ids'], 'El 2 tiene unidades y no está en el carrito: reintentar puede servir.' );
		self::assertSame( SelectionCart::DEFAULT_ERROR, $r['message'] );
	}

	/** @testdox Todo entra: ni motivo ni rechazados, y la cola de avisos queda como estaba. */
	public function test_todo_entra(): void {
		$this->tomo( 1 );
		$antes = array( 'notice' => array( array( 'notice' => 'Aviso previo.' ) ) );
		$GLOBALS['akibara_selection_notices'] = $antes;

		$r = SelectionCart::add( new \WC_Cart(), array( 1 ), array( 1 ) );

		self::assertSame( array( 'added_ids' => array( 1 ), 'rejected_ids' => array(), 'message' => '' ), $r );
		self::assertSame( $antes, $GLOBALS['akibara_selection_notices'] );
	}

	/** @testdox Los avisos que WooCommerce deja por cada tomo rechazado no quedan en la cola: la página siguiente no los apila. */
	public function test_los_avisos_del_rechazo_no_quedan_en_la_cola(): void {
		$this->tomo( 1 );
		$this->tomo( 2 );
		$antes = array( 'success' => array( array( 'notice' => 'Aviso previo.' ) ) );
		$GLOBALS['akibara_selection_notices'] = $antes;

		SelectionCart::add( new \WC_Cart( array( 1 => false, 2 => false ), array( 1 => 1, 2 => 1 ) ), array( 1, 2 ), array( 1, 2 ) );

		self::assertSame( $antes, $GLOBALS['akibara_selection_notices'] );
	}

	/** @testdox Los tres textos son del dueño: se leen con el saneador con que el panel los guarda, y vacío vuelve al de fábrica. */
	public function test_los_textos_son_del_dueno(): void {
		$this->tomo( 1 );
		$panel = new CopyManager( new AdminPanel() );
		$casos = array(
			SelectionCart::OPTION_YA_EN_CARRITO => array( new \WC_Cart( array( 1 => false ), array( 1 => 1 ) ), array( 1 ), array( 1 ) ),
			SelectionCart::OPTION_SIN_STOCK     => array( new \WC_Cart(), array( 1 ), array() ),
			SelectionCart::OPTION_ERROR         => array( new \WC_Cart( array( 1 => false ) ), array( 1 ), array( 1 ) ),
		);
		foreach ( $casos as $option => $caso ) {
			if ( SelectionCart::OPTION_SIN_STOCK === $option ) {
				$this->tomo( 1, 'outofstock', 0 );
			} else {
				$this->tomo( 1 );
			}
			foreach ( array( "<b>Texto</b>\n\ndel dueño", '', '   ' ) as $escrito ) {
				$this->options[ $option ] = $panel->sanitize_required_copy( $escrito, array( 'id' => $option ), $escrito );
				$guardado                 = SelectionCart::add( ...$caso )['message'];
				$this->options[ $option ] = $escrito;

				self::assertSame( $guardado, SelectionCart::add( ...$caso )['message'], 'El lector acepta lo mismo que guarda el panel: «' . $escrito . '» en ' . $option . '.' );
				self::assertSame( '' === trim( $escrito ) ? SelectionCart::defaults()[ $option ] : 'Texto del dueño', $guardado );
			}
			unset( $this->options[ $option ] );
		}
		self::assertSame( SelectionCart::DEFAULT_ERROR, SelectionCart::error_text() );
	}

	/** @testdox Los tres están en Akibara → Textos, con su literal de fábrica. */
	public function test_los_textos_estan_en_el_panel(): void {
		Functions\when( 'get_posts' )->justReturn( array() );
		$campos = array();
		foreach ( ( new CopyManager( new AdminPanel() ) )->get_settings() as $campo ) {
			if ( isset( $campo['id'] ) ) {
				$campos[ $campo['id'] ] = $campo;
			}
		}

		self::assertCount( 3, SelectionCart::defaults() );
		foreach ( SelectionCart::defaults() as $option => $fabrica ) {
			self::assertArrayHasKey( $option, $campos, 'Un texto que el cliente lee tiene que poder editarse sin código.' );
			self::assertSame( $fabrica, $campos[ $option ]['default'] );
			self::assertSame( 'text', $campos[ $option ]['type'] );
		}
	}
}
