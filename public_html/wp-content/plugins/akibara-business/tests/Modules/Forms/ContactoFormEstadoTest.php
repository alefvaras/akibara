<?php
/**
 * R3-dc2c02a9 (2026-09-19): `ContactoForm::render()` cumple el CONTRATO que
 * `template-parts/forms/contacto.php` (tema) documenta — define
 * `$akb_contacto_estado` ('' | 'ok' | 'error') ANTES del include, leída de
 * `$_GET['akb_contacto']` con lista blanca — sin depender del tema real.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\Forms;

use Akibara\Business\Modules\Forms\ContactoForm;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class ContactoFormEstadoTest extends TestCase {

	private const FIXTURE = __DIR__ . '/../../fixtures/contacto-estado-probe.php';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'locate_template' )->justReturn( self::FIXTURE );
		Functions\when( 'sanitize_key' )->alias(
			static fn( string $v ): string => preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $v ) )
		);
	}

	protected function tearDown(): void {
		unset( $_GET['akb_contacto'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @testdox Sin query string, la plantilla recibe el estado vacío (sin aviso). */
	public function test_sin_query_string_el_estado_es_vacio(): void {
		self::assertSame( 'VACIO', ( new ContactoForm() )->render() );
	}

	/** @testdox `?akb_contacto=ok` llega tal cual a la plantilla. */
	public function test_ok_llega_a_la_plantilla(): void {
		$_GET['akb_contacto'] = 'ok';

		self::assertSame( 'ok', ( new ContactoForm() )->render() );
	}

	/** @testdox `?akb_contacto=error` llega tal cual a la plantilla. */
	public function test_error_llega_a_la_plantilla(): void {
		$_GET['akb_contacto'] = 'error';

		self::assertSame( 'error', ( new ContactoForm() )->render() );
	}

	/**
	 * @testdox Cualquier otro valor —incluido uno hostil— cae a vacío: la lista blanca es real, no una comparación suelta.
	 */
	public function test_un_valor_fuera_de_la_lista_blanca_cae_a_vacio(): void {
		$_GET['akb_contacto'] = '<script>alert(1)</script>';

		self::assertSame( 'VACIO', ( new ContactoForm() )->render() );
	}
}
