<?php
/**
 * Candado de fuente: el docblock de «sin tope» vive sobre el método correcto.
 *
 * S-960738f6 (2026-09-19): el docblock que explica por qué
 * `products_with_waiting()` NO lleva LIMIT estaba pegado sobre
 * `count_other_waiting()`, la función que le sigue en el fichero. Cualquier
 * herramienta de documentación —y cualquier lector— atribuía la explicación a
 * la función equivocada, que es justo la que evita que alguien «optimice»
 * `products_with_waiting()` metiéndole un LIMIT y rompiendo la reactivación
 * de avisos tras una pausa (deja fuera en silencio la cola larga del
 * catálogo).
 *
 * Candado de fuente porque el defecto es de POSICIÓN de un comentario, no de
 * comportamiento: no hay salida observable que distinga «docblock en el
 * método de al lado» de «docblock en el método correcto». Montar un escenario
 * de comportamiento no probaría nada que este test no pruebe mejor.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\BackInStock;

use Akibara\Business\Modules\BackInStock\SubscriberRepository;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class SubscriberRepositoryDocblockPlacementTest extends TestCase {

	/**
	 * @testdox El docblock de «sin tope» está sobre products_with_waiting(), no sobre count_other_waiting().
	 */
	public function test_no_limit_docblock_sits_on_the_right_method(): void {
		$objetivo = new ReflectionMethod( SubscriberRepository::class, 'products_with_waiting' );
		$vecino   = new ReflectionMethod( SubscriberRepository::class, 'count_other_waiting' );

		$doc_objetivo = (string) $objetivo->getDocComment();
		$doc_vecino   = (string) $vecino->getDocComment();

		self::assertStringContainsString(
			'sin techo',
			$doc_objetivo,
			'products_with_waiting() debe llevar el docblock que explica por qué la consulta no tiene LIMIT.'
		);
		self::assertStringNotContainsString(
			'sin techo',
			$doc_vecino,
			'El docblock del "sin tope" NO debe volver a quedar pegado sobre count_other_waiting() (S-960738f6).'
		);
	}
}
