<?php
/**
 * Una fecha basura o un «aplica a» fuera de dominio apagaban el buscador para
 * todos los clientes. El campo `date` sólo lo valida el navegador, y WooCommerce
 * no acota los `radio`. Demostrado con los sanitizadores reales en el espejo
 * (QA con Opus, 2026-09-11).
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\TaxonomyDiscount;

use Akibara\Business\Modules\TaxonomyDiscount\Settings;
use Akibara\Business\Modules\TaxonomyDiscount\TaxonomyDiscount;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class VentanaYAplicaASeSaneanTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'sanitize_text_field' )->alias( static fn( string $v ): string => trim( $v ) );
		Functions\when( 'wp_unslash' )->returnArg();
	}

	protected function tearDown(): void {
		unset( $_POST[ Settings::OPTION_FROM ], $_POST[ Settings::OPTION_TO ] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @return array<int, string> */
	private function errores( ?string $desde, ?string $hasta, string $tab = TaxonomyDiscount::PANEL_TAB_ID ): array {
		unset( $_POST[ Settings::OPTION_FROM ], $_POST[ Settings::OPTION_TO ] );
		if ( null !== $desde ) {
			$_POST[ Settings::OPTION_FROM ] = $desde;
		}
		if ( null !== $hasta ) {
			$_POST[ Settings::OPTION_TO ] = $hasta;
		}

		return ( new TaxonomyDiscount() )->validate_window_dates( array(), $tab );
	}

	/**
	 * @testdox Una fecha que no existe bloquea el guardado y dice por qué.
	 * @dataProvider fechas_basura
	 */
	public function test_una_fecha_basura_bloquea_el_guardado( string $hasta ): void {
		$errores = $this->errores( '2026-09-01', $hasta );

		self::assertCount( 1, $errores, $hasta );
		self::assertStringContainsString( $hasta, $errores[0] );
		self::assertStringContainsString( 'no caducaría nunca', $errores[0] );
	}

	/** @return array<string, array{0: string}> */
	public static function fechas_basura(): array {
		return array(
			'palabra'                 => array( 'ayer' ),
			'mes 13'                  => array( '2026-13-01' ),
			'29 de febrero sin bisiesto' => array( '2027-02-29' ),
			'formato chileno'         => array( '10-09-2026' ),
			'con hora'                => array( '2026-09-10 12:00' ),
		);
	}

	/**
	 * @testdox La ventana invertida también se bloquea.
	 */
	public function test_la_ventana_invertida_se_bloquea(): void {
		$errores = $this->errores( '2026-09-20', '2026-09-10' );

		self::assertCount( 1, $errores );
		self::assertStringContainsString( 'invertida', $errores[0] );
	}

	/**
	 * @testdox Lo válido pasa: fechas reales, ventana bien, vacíos, y el bisiesto de verdad.
	 * @dataProvider ventanas_validas
	 */
	public function test_lo_valido_pasa( ?string $desde, ?string $hasta ): void {
		self::assertSame( array(), $this->errores( $desde, $hasta ) );
	}

	/** @return array<string, array{0: ?string, 1: ?string}> */
	public static function ventanas_validas(): array {
		return array(
			'ventana normal'          => array( '2026-09-05', '2026-09-06' ),
			'un solo día'             => array( '2026-09-05', '2026-09-05' ),
			'sin fin'                 => array( '2026-09-05', '' ),
			'sin principio'           => array( '', '2026-12-31' ),
			'las dos vacías'          => array( '', '' ),
			'bisiesto real'           => array( '2028-02-29', '2028-03-01' ),
			'otra pestaña no postea'  => array( null, null ),
		);
	}

	/**
	 * @testdox Otra pestaña no bloquea aunque haya fechas malas en el POST.
	 */
	public function test_otra_pestana_no_se_entera(): void {
		self::assertSame( array(), $this->errores( 'ayer', 'basura', 'branding' ) );
	}

	/**
	 * @testdox «Aplica a» fuera de dominio cae a «solo con stock»; dentro, se respeta.
	 * @dataProvider aplica_a
	 */
	public function test_aplica_a_se_acota( string $crudo, string $esperado ): void {
		self::assertSame( $esperado, ( new TaxonomyDiscount() )->sanitize_apply_to( '', array(), $crudo ) );
	}

	/**
	 * @testdox La política ante rebajas manuales fuera de dominio cae a «respetar la rebaja»; dentro, se respeta.
	 * @dataProvider politicas
	 */
	public function test_la_politica_de_rebajas_se_acota( string $crudo, string $esperado ): void {
		self::assertSame( $esperado, ( new TaxonomyDiscount() )->sanitize_sale_policy( '', array(), $crudo ) );
	}

	/** @return array<string, array{0: string, 1: string}> */
	public static function politicas(): array {
		return array(
			'basura'               => array( 'basura', Settings::POLICY_SKIP_IF_ON_SALE ),
			'vacío'                => array( '', Settings::POLICY_SKIP_IF_ON_SALE ),
			'mayúsculas'           => array( 'GREATEST_WINS', Settings::POLICY_SKIP_IF_ON_SALE ),
			'con espacios'         => array( '  greatest_wins ', Settings::POLICY_GREATEST_WINS ),
			'respetar'             => array( 'skip_if_on_sale', Settings::POLICY_SKIP_IF_ON_SALE ),
			'precio más bajo gana' => array( 'greatest_wins', Settings::POLICY_GREATEST_WINS ),
			'reemplazar'           => array( 'from_regular', Settings::POLICY_FROM_REGULAR ),
			'encadenar'            => array( 'stack_on_sale', Settings::POLICY_STACK_ON_SALE ),
		);
	}

	/** @testdox El saneador y el lector comparten el mismo dominio cerrado. */
	public function test_el_saneador_engancha_el_mismo_dominio_que_el_lector(): void {
		self::assertSame(
			array( 'greatest_wins', 'skip_if_on_sale', 'from_regular', 'stack_on_sale' ),
			Settings::sale_policies()
		);
	}

	/** @return array<string, array{0: string, 1: string}> */
	public static function aplica_a(): array {
		return array(
			'basura'         => array( 'basura', Settings::APPLY_INSTOCK_ONLY ),
			'vacío'          => array( '', Settings::APPLY_INSTOCK_ONLY ),
			'mayúsculas'     => array( 'ALL', Settings::APPLY_INSTOCK_ONLY ),
			'instock_only'   => array( 'instock_only', Settings::APPLY_INSTOCK_ONLY ),
			'con backorder'  => array( 'instock_and_onbackorder', Settings::APPLY_INSTOCK_AND_ONBACKORDER ),
			'all'            => array( 'all', Settings::APPLY_ALL ),
			'con espacios'   => array( '  all  ', Settings::APPLY_ALL ),
		);
	}
}
