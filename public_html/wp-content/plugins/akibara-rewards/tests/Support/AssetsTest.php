<?php
/**
 * El token de caché de los assets del programa de puntos.
 *
 * Si la versión no cambia cuando cambia el fichero, el cliente se queda con el
 * JavaScript viejo: el mismo modo de fallo que ya costó servir dos versiones
 * mezcladas tras un despliegue. Por eso el token lleva versión Y mtime, y por
 * eso un fichero ausente NO inventa un mtime (ampliación de cobertura,
 * 2026-09-18).
 *
 * @package Akibara\Rewards\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Tests\Support;

use Akibara\Rewards\Support\Assets;
use PHPUnit\Framework\TestCase;

final class AssetsTest extends TestCase {

	/** @testdox La URL cuelga de la raíz declarada del plugin. */
	public function test_la_url_sale_de_la_raiz(): void {
		self::assertSame( AKIBARA_REWARDS_URL . 'assets/js/x.js', Assets::url( 'assets/js/x.js' ) );
	}

	/** @testdox El token de un fichero que existe lleva versión y marca de tiempo. */
	public function test_el_token_lleva_version_y_mtime(): void {
		// Un fichero que seguro existe: el propio plugin.
		$token = Assets::version( 'akibara-rewards.php' );

		self::assertStringStartsWith( AKIBARA_REWARDS_VERSION . '.', $token );
		self::assertMatchesRegularExpression( '/^' . preg_quote( AKIBARA_REWARDS_VERSION, '/' ) . '\.\d{9,}$/', $token );
	}

	/** @testdox Un fichero que no existe NO inventa una marca de tiempo. */
	public function test_sin_fichero_no_hay_mtime(): void {
		self::assertSame(
			AKIBARA_REWARDS_VERSION,
			Assets::version( 'assets/js/este-fichero-no-existe.js' ),
			'Un «.0» pegado al final sería un token que miente sobre un fichero que no está.'
		);
	}

	/** @testdox Dos ficheros distintos dan tokens distintos. */
	public function test_dos_ficheros_dan_tokens_distintos(): void {
		$uno = Assets::version( 'akibara-rewards.php' );
		$dos = Assets::version( 'uninstall.php' );

		self::assertNotSame( $uno, $dos, 'Si coincidieran, tocar uno no invalidaría la caché del otro.' );
	}
}
