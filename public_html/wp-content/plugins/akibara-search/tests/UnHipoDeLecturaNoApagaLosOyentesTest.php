<?php
/**
 * Un hipo de lectura de la configuración no apaga los oyentes del índice.
 *
 * `Search::indexing_enabled()` falla CERRADO: devuelve false si el dueño apagó
 * la indexación Y TAMBIÉN si la foto de la configuración no se pudo leer en esa
 * petición. El arranque usaba ese mismo booleano para decidir si registrar los
 * oyentes del catálogo, así que con un hipo de lectura el producto guardado en
 * esa petición no dejaba deuda y el índice mentía hasta la revisión diaria
 * (hallazgo S-99e8391a, 2026-09-19). Servir falla cerrado; escuchar, abierto.
 *
 * @package Akibara\Search\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Search\Tests;

use Akibara\Search\Search;
use Akibara\Search\SemanticPublication;
use Brain\Monkey;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class UnHipoDeLecturaNoApagaLosOyentesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		\Patchwork\restoreAll();
		Monkey\tearDown();
		parent::tearDown();
	}

	private function se_escucha( string $valor, bool $foto_legible ): bool {
		\Patchwork\redefine( SemanticPublication::class . '::authoritative_value', static fn() => $valor );
		\Patchwork\redefine( SemanticPublication::class . '::authoritative_available', static fn(): bool => $foto_legible );

		$metodo = new ReflectionMethod( Search::class, 'indexing_listeners_wanted' );
		$metodo->setAccessible( true );

		return (bool) $metodo->invoke( null );
	}

	/** @testdox Indexación encendida y foto legible: se escucha. */
	public function test_encendida_se_escucha(): void {
		self::assertTrue( $this->se_escucha( 'yes', true ) );
	}

	/** @testdox Apagada A PROPÓSITO (la foto se leyó y dice «no»): no se escucha. */
	public function test_apagada_a_proposito_no_se_escucha(): void {
		self::assertFalse( $this->se_escucha( 'no', true ) );
	}

	/** @testdox Foto ilegible: se escucha igual, porque perder el cambio es peor que anotar deuda de más. */
	public function test_con_la_foto_ilegible_se_escucha_igual(): void {
		self::assertTrue( $this->se_escucha( 'no', false ) );
		self::assertFalse( Search::indexing_enabled(), 'Y SERVIR sigue fallando cerrado: eso no cambió.' );
	}
}
