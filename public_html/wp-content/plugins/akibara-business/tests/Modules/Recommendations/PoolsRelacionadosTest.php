<?php
/**
 * Los pools de «Productos relacionados» que WooCommerce guarda un día se tiran
 * cuando puede haber cambiado la consulta que los armó (revisión B2-R1).
 *
 * Medido en el espejo el 2026-09-22, recién aplicado el pool al azar: 46 fichas
 * conservaban el pool viejo (hasta 23 h más de vida) y Blue Lock 38 sacó en
 * cuatro visitas sus relacionados de las mismas seis series de siempre, justo
 * lo que el pool al azar venía a evitar.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\Recommendations;

use Akibara\Business\Modules\Recommendations\RelatedProducts;
use Akibara\Business\Modules\Recommendations\Settings;
use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class PoolsRelacionadosTest extends TestCase {

	/** @var array<int, string> Transients que se pidió borrar. */
	private array $tirados = array();

	/** @var array<int, string> Consultas hechas a `$wpdb`. */
	private array $consultas = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->tirados   = array();
		$this->consultas = array();

		Functions\when( 'delete_transient' )->alias(
			function ( string $name ): bool {
				$this->tirados[] = $name;
				return true;
			}
		);

		$test            = $this;
		$GLOBALS['wpdb'] = new class( $test ) {
			public string $posts = 'wp_posts';

			private PoolsRelacionadosTest $test;

			public function __construct( PoolsRelacionadosTest $test ) {
				$this->test = $test;
			}

			/** @return array<int, string> */
			public function get_col( string $sql ): array {
				$this->test->anotar_consulta( $sql );
				return array( '125', '30444', '16080' );
			}
		};
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/** Lo llama el `$wpdb` de mentira. */
	public function anotar_consulta( string $sql ): void {
		$this->consultas[] = $sql;
	}

	/**
	 * @testdox Tira el pool de cada producto por su clave, sin DELETE … LIKE que la caché de objetos de producción no ve.
	 */
	public function test_tira_el_pool_de_cada_producto(): void {
		$tirados = RelatedProducts::forget_pools();

		self::assertSame( array( 'wc_related_125', 'wc_related_30444', 'wc_related_16080' ), $this->tirados );
		self::assertSame( 3, $tirados );
		self::assertCount( 1, $this->consultas, 'Una sola consulta por los ids, no una por producto.' );
		self::assertStringContainsString( "post_type = 'product'", $this->consultas[0] );
		self::assertStringNotContainsStringIgnoringCase( 'delete', $this->consultas[0] );
	}

	/**
	 * @testdox Se engancha a cada actualización del plugin, a los dos guardados del modo y al borrado de su fila.
	 */
	public function test_se_engancha_a_la_actualizacion_y_al_modo(): void {
		( new RelatedProducts() )->register();

		self::assertNotFalse( Actions\has( 'akibara_business_upgraded', array( RelatedProducts::class, 'forget_pools' ) ) );
		self::assertNotFalse( Actions\has( 'update_option_' . Settings::OPTION_RELATED, array( RelatedProducts::class, 'on_mode_updated' ) ) );
		self::assertNotFalse( Actions\has( 'add_option_' . Settings::OPTION_RELATED, array( RelatedProducts::class, 'on_mode_added' ) ) );
		self::assertNotFalse( Actions\has( 'delete_option_' . Settings::OPTION_RELATED, array( RelatedProducts::class, 'forget_pools' ) ), 'Borrar la fila vuelve al de fábrica: el pool del modo anterior no puede quedarse.' );
	}

	/**
	 * @testdox Cambiar de modo tira los pools en los dos sentidos: cada modo arma el pool distinto.
	 */
	public function test_cambiar_de_modo_tira_los_pools(): void {
		RelatedProducts::on_mode_updated( Settings::RELATED_OTRA_SERIE, Settings::RELATED_COMPRABLES );
		self::assertCount( 3, $this->tirados );

		$this->tirados = array();
		RelatedProducts::on_mode_updated( Settings::RELATED_COMPRABLES, Settings::RELATED_OTRA_SERIE );
		self::assertCount( 3, $this->tirados );
	}

	/**
	 * @testdox Guardar sin cambiar la regla que rige no tira nada (un valor raro rige como el de fábrica).
	 */
	public function test_guardar_la_misma_regla_no_tira_nada(): void {
		RelatedProducts::on_mode_updated( Settings::RELATED_COMPRABLES, Settings::RELATED_COMPRABLES );
		RelatedProducts::on_mode_updated( 'basura', Settings::RELATED_OTRA_SERIE );
		RelatedProducts::on_mode_added( Settings::OPTION_RELATED, Settings::RELATED_OTRA_SERIE );

		self::assertSame( array(), $this->tirados );
		self::assertSame( array(), $this->consultas );
	}

	/**
	 * @testdox El primer guardado del modo compara contra el de fábrica.
	 */
	public function test_primer_guardado_compara_contra_el_de_fabrica(): void {
		RelatedProducts::on_mode_added( Settings::OPTION_RELATED, Settings::RELATED_COMPRABLES );

		self::assertCount( 3, $this->tirados );
	}

	/**
	 * @testdox El docblock del pool al azar ya no promete que el cambio «llega solo, en menos de un día».
	 */
	public function test_el_docblock_no_promete_un_dia(): void {
		$fuente = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Modules/Recommendations/RelatedProducts.php' );

		self::assertStringNotContainsString( 'llega solo, en menos de un día', $fuente );
		self::assertStringContainsString( 'medianoche de', $fuente, 'Tiene que contar lo que vive la ficha pintada en LiteSpeed.' );
	}
}
