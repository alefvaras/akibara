<?php
/**
 * El docblock de BrandsDirectory no vuelve a inventar un plugin `pwb-brand` (S-fee95df0).
 *
 * Hasta el 2026-09-19 el docblock del bootstrap decía que `product_brand`
 * estaba deshabilitada «duplicada con `pwb-brand`» y que la fuente de verdad
 * era esa taxonomy — que no existe ni existió en este código: cada clase del
 * módulo (RegionMeta, BrandFeatured, Shortcode, ...) usa `product_brand`
 * directamente como su única taxonomy. Es el fichero que un lector nuevo abre
 * primero para entender el área de editoriales.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\BrandsDirectory;

use PHPUnit\Framework\TestCase;

final class BrandsDirectoryDocblockTest extends TestCase {

	/** @testdox El docblock no vuelve a citar pwb-brand como fuente de verdad de editoriales. */
	public function test_docblock_does_not_claim_pwb_brand_is_the_source_of_truth(): void {
		$fuente = (string) file_get_contents(
			dirname( __DIR__, 3 ) . '/src/Modules/BrandsDirectory/BrandsDirectory.php'
		);

		self::assertStringNotContainsString(
			'Single source of truth = pwb-brand',
			$fuente,
			'pwb-brand no es un plugin instalado en este árbol: product_brand es la única taxonomy real de editoriales.'
		);
	}
}
