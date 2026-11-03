<?php
/**
 * La tarjeta «Estado» de «Arma tu Pack» tiene que leer de la BASE, entera —el
 * mismo defecto que ya midió QA para `TaxonomyDiscount`, sin arreglar acá
 * (R4-198b3376, 2026-09-19).
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\SeriesBundles;

use Akibara\Business\Modules\SeriesBundles\Admin\AdminSection;
use Akibara\Business\Modules\SeriesBundles\Settings;
use Akibara\Business\Tests\Support\OptionsFromDatabaseWpdb;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class AdminHeaderReadsTheTruthTest extends TestCase {

	/** @var mixed */
	private $wpdb_previo;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->wpdb_previo = $GLOBALS['wpdb'] ?? null;

		Functions\when( 'esc_attr' )->returnArg( 1 );
		Functions\when( 'esc_html' )->returnArg( 1 );
		Functions\when( 'maybe_unserialize' )->alias(
			static function ( $valor ) {
				if ( is_string( $valor ) && 1 === preg_match( '/^a:\d+:\{/', $valor ) ) {
					return unserialize( $valor ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- doble de prueba.
				}
				return $valor;
			}
		);

		// El blob rancio: SIEMPRE «todo apagado, cero series, cero excluidas».
		// Si `render_header()` toca `get_option()` en cualquier forma, la
		// tarjeta pinta esto en vez de lo que de verdad se acaba de guardar.
		Functions\when( 'get_option' )->alias(
			static fn( string $o, $d = false ) => array(
				Settings::OPTION_ENABLED         => 'no',
				Settings::OPTION_BYP             => '{}',
				Settings::OPTION_EXCLUDE_CATS    => array(),
				Settings::OPTION_ALLOW_BACKORDER => 'no',
			)[ $o ] ?? $d
		);
	}

	protected function tearDown(): void {
		if ( null === $this->wpdb_previo ) {
			unset( $GLOBALS['wpdb'] );
		} else {
			$GLOBALS['wpdb'] = $this->wpdb_previo;
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox La tarjeta pinta lo que hay en la base, aunque get_option() diga lo contrario.
	 */
	public function test_la_tarjeta_pinta_lo_que_hay_en_la_base(): void {
		$doble        = new OptionsFromDatabaseWpdb();
		$doble->filas = array(
			Settings::OPTION_ENABLED         => 'yes',
			Settings::OPTION_BYP             => wp_json_encode(
				array( '12' => array( 'enabled' => true ) )
			),
			Settings::OPTION_EXCLUDE_CATS    => '5,9',
			Settings::OPTION_ALLOW_BACKORDER => 'yes',
		);
		$GLOBALS['wpdb'] = $doble;

		$admin_section = new AdminSection( new Settings() );

		ob_start();
		$admin_section->render_header();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'akb-sb-status__dot--on', $html, 'Con la base en `yes`, el punto tiene que ser el de encendido.' );
		self::assertStringContainsString( 'Activo', $html );
		self::assertStringNotContainsString( 'Apagado', $html );
		self::assertStringContainsString( '<strong>Series activas:</strong> 1', $html );
		self::assertStringContainsString( '<strong>Categorías excluidas:</strong> 2', $html );
		self::assertStringContainsString( '<strong>Pedidos y preventa:</strong> incluidas', $html );
	}

	/** @testdox Sin $wpdb disponible, la tarjeta sigue pintando algo (cae a los getters tolerantes). */
	public function test_sin_wpdb_no_revienta(): void {
		unset( $GLOBALS['wpdb'] );

		$admin_section = new AdminSection( new Settings() );

		ob_start();
		$admin_section->render_header();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Apagado', $html );
	}

	/**
	 * Candado de fuente: `render_header()` tiene que sacar sus cuatro piezas de
	 * la MISMA lectura cruda, no de los getters tolerantes que caen en
	 * `get_option()`. Sin esto, alguien podría revertir a la versión vieja sin
	 * que ninguna prueba de comportamiento lo note si además arrastrara
	 * `$GLOBALS['wpdb']` a las pruebas — el candado mide la fuente, no depende
	 * de eso.
	 *
	 * @testdox render_header() no llama a los getters tolerantes por su cuenta.
	 */
	public function test_render_header_no_llama_a_los_getters_directamente(): void {
		$fuente = (string) file_get_contents(
			dirname( __DIR__, 3 ) . '/src/Modules/SeriesBundles/Admin/AdminSection.php'
		);

		$inicio = strpos( $fuente, 'public function render_header(): void {' );
		self::assertNotFalse( $inicio, 'render_header() tiene que existir.' );
		$fin = strpos( $fuente, "\n\t}\n}", $inicio );
		self::assertNotFalse( $fin, 'No se pudo acotar el cuerpo de render_header().' );
		$cuerpo = substr( $fuente, $inicio, $fin - $inicio );

		self::assertStringContainsString( 'status_snapshot_from_database()', $cuerpo );
		self::assertStringNotContainsString( '$this->settings->is_enabled()', $cuerpo );
		self::assertStringNotContainsString( '$this->settings->get_byp_config()', $cuerpo );
		self::assertStringNotContainsString( '$this->settings->get_excluded_categories()', $cuerpo );
		self::assertStringNotContainsString( '$this->settings->allows_backorder()', $cuerpo );
	}
}
