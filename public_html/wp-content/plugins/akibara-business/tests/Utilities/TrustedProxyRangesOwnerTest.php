<?php
/**
 * Candado: los rangos de Cloudflare tienen UN dueño.
 *
 * El limitador está duplicado íntegro en akibara-search (`Support\RateLimit`)
 * porque ese plugin viaja en otro riel y no puede depender de que Business esté
 * activo — y el docblock de Business afirmaba exactamente lo contrario, «una
 * sola fuente de verdad». Lo que sí se puede unificar son los RANGOS: los dos
 * aplican `akibara_trusted_proxy_ranges` y Business lo siembra, así que
 * actualizar Cloudflare se hace en un archivo y no en dos.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Utilities;

use Akibara\Business\Utilities\RateLimit;
use PHPUnit\Framework\TestCase;

final class TrustedProxyRangesOwnerTest extends TestCase {

	/**
	 * @testdox El sembrador impone la lista canónica sobre lo que traiga el filtro.
	 */
	public function test_the_seeder_imposes_the_canonical_list(): void {
		self::assertSame(
			RateLimit::CLOUDFLARE_RANGES,
			RateLimit::seed_trusted_proxy_ranges( array( '10.0.0.0/8' ) ),
			'Con Business activo los dos limitadores tienen que confiar en la MISMA lista.'
		);
		self::assertSame( RateLimit::CLOUDFLARE_RANGES, RateLimit::seed_trusted_proxy_ranges( 'basura' ) );
	}

	/**
	 * @testdox La copia del buscador sigue cubriendo los mismos rangos.
	 *
	 * Es su respaldo para cuando Business no está: si divergen, apagar Business
	 * cambiaría en silencio en quién confía el rate-limit del autocomplete.
	 */
	public function test_the_search_fallback_still_covers_the_same_ranges(): void {
		$copia = dirname( __DIR__, 3 ) . '/akibara-search/src/Support/RateLimit.php';
		if ( ! is_readable( $copia ) ) {
			self::markTestSkipped( 'El árbol del buscador no está disponible en esta corrida.' );
		}

		$fuente = (string) file_get_contents( $copia );
		foreach ( RateLimit::CLOUDFLARE_RANGES as $rango ) {
			self::assertStringContainsString(
				"'" . $rango . "'",
				$fuente,
				sprintf( 'El respaldo del buscador se quedó sin %s: sin Business, ese edge deja de ser de confianza.', $rango )
			);
		}
	}

	/**
	 * @testdox Business engancha el sembrador en su raíz de composición.
	 */
	public function test_the_seeder_is_wired_at_boot(): void {
		self::assertMatchesRegularExpression(
			"/add_filter\(\s*'akibara_trusted_proxy_ranges',\s*array\( RateLimit::class, 'seed_trusted_proxy_ranges' \),\s*5\s*\)/",
			(string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Plugin.php' ),
			'Sin el enganche, el filtro compartido no siembra nada y vuelven a ser dos listas.'
		);
	}
}
