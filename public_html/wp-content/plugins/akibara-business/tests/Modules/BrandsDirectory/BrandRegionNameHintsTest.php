<?php
/**
 * Las pistas de nombre de `BrandRegion` no deben adivinar una región para
 * editoriales que tienen ediciones en MÁS de un país.
 *
 * La casa tiene escrito (2026-08-18, `regla-editoriales-pais-de-origen`) que
 * Ivrea y Panini publican tanto en Argentina como en España — los slugs reales
 * siempre traen el sufijo (`ivrea-argentina`, `panini-espana`, ver
 * `BrandLandings`) y el paso 2 (slug heurístico) ya los resuelve antes de
 * llegar aquí. Las pistas de nombre («panini», «ivrea» a secas) sólo se
 * ejercitan si algún día existe un término SIN sufijo de país y fuera del
 * `SLUG_MAP`; en ese caso, apostar por España sería el dato contrario al que
 * la propia casa escribió (S-d2f72d40, 2026-09-19).
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\BrandsDirectory;

use Akibara\Business\Modules\BrandsDirectory\BrandRegion;
use Brain\Monkey;
use PHPUnit\Framework\TestCase;

final class BrandRegionNameHintsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox Un nombre «Ivrea» a secas NO se adivina como España.
	 */
	public function test_ivrea_a_secas_no_es_espana(): void {
		self::assertSame(
			BrandRegion::REGION_OTROS,
			BrandRegion::for_name( 'Ivrea' ),
			'Ivrea tiene 855 tomos en Argentina y 69 en España: adivinar España es la apuesta contraria a los datos.'
		);
	}

	/**
	 * @testdox Un nombre «Panini» a secas NO se adivina como España.
	 */
	public function test_panini_a_secas_no_es_espana(): void {
		self::assertSame(
			BrandRegion::REGION_OTROS,
			BrandRegion::for_name( 'Panini' ),
			'Panini tiene 372 tomos en Argentina y 49 en España: adivinar España es la apuesta contraria a los datos.'
		);
	}

	/**
	 * Planeta Comic sí es de un solo país en el catálogo (sin entrada gemela
	 * `-argentina` en `BrandLandings`): esta pista de nombre no tiene el mismo
	 * defecto y el arreglo de arriba no debe borrarla por error.
	 *
	 * @testdox «Planeta» a secas sigue clasificando como España.
	 */
	public function test_planeta_a_secas_sigue_siendo_espana(): void {
		self::assertSame( BrandRegion::REGION_ESPANA, BrandRegion::for_name( 'Planeta' ) );
	}

	/**
	 * El slug heurístico (paso 2, no las pistas de nombre) es lo que clasifica
	 * a las editoriales reales: este candado no debe hacer que Ivrea/Panini
	 * pierdan su región cuando el término SÍ trae el sufijo.
	 *
	 * @testdox El slug con sufijo sigue resolviendo Ivrea/Panini normalmente.
	 */
	public function test_el_slug_con_sufijo_sigue_funcionando(): void {
		self::assertSame( BrandRegion::REGION_ARGENTINA, BrandRegion::for_slug( 'ivrea-argentina' ) );
		self::assertSame( BrandRegion::REGION_ESPANA, BrandRegion::for_slug( 'ivrea-espana' ) );
		self::assertSame( BrandRegion::REGION_ARGENTINA, BrandRegion::for_slug( 'panini-argentina' ) );
		self::assertSame( BrandRegion::REGION_ESPANA, BrandRegion::for_slug( 'panini-espana' ) );
	}
}
