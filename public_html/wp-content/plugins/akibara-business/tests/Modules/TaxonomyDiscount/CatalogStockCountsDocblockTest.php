<?php
/**
 * S-d8cce055 (2026-09-19): el docblock de `catalog_stock_counts()` se
 * contradecía a sí mismo en el mismo método.
 *
 * El docblock decía «cacheada cinco minutos». El comentario del cuerpo, dos
 * líneas más abajo, explica por qué NO hay transient y el memo vive sólo por
 * PETICIÓN. Quien leyera sólo el docblock —la parte que aparece en el hover
 * del IDE, sin bajar al cuerpo— creería que el conteo puede ir hasta cinco
 * minutos desfasado (no puede: es exacto por carga) o que ya existe un
 * transient reutilizable (tampoco existe).
 *
 * Candado de FUENTE y no de comportamiento: lo que se rompía era la prosa, no
 * ningún valor devuelto — montar el catálogo completo para probar un docblock
 * sería desproporcionado, y `TaxonomyDiscountCatalogCountsTest`/
 * `ApplyToLabelsTest` ya cubren el comportamiento del memo.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\TaxonomyDiscount;

use PHPUnit\Framework\TestCase;

final class CatalogStockCountsDocblockTest extends TestCase {

	private function docblock(): string {
		$fuente = (string) file_get_contents(
			dirname( __DIR__, 3 ) . '/src/Modules/TaxonomyDiscount/TaxonomyDiscount.php'
		);

		$fin = strpos( $fuente, 'private static function catalog_stock_counts(): array {' );
		self::assertNotFalse( $fin, 'El método tiene que existir.' );

		// El docblock es el `/** ... */` inmediatamente anterior a la firma.
		$inicio_bloque = strrpos( substr( $fuente, 0, (int) $fin ), '/**' );
		self::assertNotFalse( $inicio_bloque, 'No se encontró el docblock del método.' );

		return substr( $fuente, (int) $inicio_bloque, (int) $fin - (int) $inicio_bloque );
	}

	/**
	 * @testdox El docblock no promete un transient de cinco minutos que no existe.
	 */
	public function test_the_docblock_does_not_promise_a_five_minute_cache(): void {
		self::assertStringNotContainsString(
			'cacheada cinco minutos',
			$this->docblock(),
			'El memo es por PETICIÓN, no un transient: prometer «cinco minutos» hace creer que el número puede ir desfasado ese tiempo.'
		);
	}

	/**
	 * @testdox El docblock dice la verdad: memo por petición.
	 */
	public function test_the_docblock_says_the_truth(): void {
		self::assertStringContainsString(
			'memoizada por PETICIÓN',
			$this->docblock(),
			'Tiene que decir lo mismo que el comentario del cuerpo, no una versión distinta de la misma decisión.'
		);
	}
}
