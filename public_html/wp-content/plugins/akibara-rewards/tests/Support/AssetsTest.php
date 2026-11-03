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

	/** @testdox Cambiar un fichero invalida su token sin depender de las fechas del checkout. */
	public function test_cambiar_un_fichero_invalida_solo_su_token(): void {
		$directory = sys_get_temp_dir() . '/akb-asset-version-' . uniqid();
		mkdir( $directory );
		try {
			foreach ( array( 'a.js', 'b.js' ) as $name ) {
				file_put_contents( $directory . '/' . $name, '/* synthetic fixture */' );
				touch( $directory . '/' . $name, 1700000000 );
			}
			$before = $this->tokens_from( $directory );
			self::assertSame( $before[0], $before[1], 'Un checkout puede dar la misma fecha a dos URLs distintas.' );
			touch( $directory . '/a.js', 1700000001 );
			$after = $this->tokens_from( $directory );
			self::assertNotSame( $before[0], $after[0] );
			self::assertSame( $before[1], $after[1] );
		} finally {
			unlink( $directory . '/a.js' );
			unlink( $directory . '/b.js' );
			rmdir( $directory );
		}
	}

	/** Ejecuta el helper real con una raíz efímera, sin modificar fuentes del repo. */
	private function tokens_from( string $directory ): array {
		$program = "define('ABSPATH', '/'); define('AKIBARA_REWARDS_VERSION', '1.2.3');"
			. 'define("AKIBARA_REWARDS_DIR", ' . var_export( $directory . '/', true ) . ');'
			. 'require ' . var_export( AKIBARA_REWARDS_DIR . 'src/Support/Assets.php', true ) . ';'
			. 'echo json_encode([Akibara\\Rewards\\Support\\Assets::version("a.js"), Akibara\\Rewards\\Support\\Assets::version("b.js")]);';
		$output = array();
		$status = 0;
		exec( escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( $program ), $output, $status );
		self::assertSame( 0, $status, 'La medición del helper debe terminar correctamente.' );
		$tokens = json_decode( implode( "\n", $output ), true );
		self::assertIsArray( $tokens );
		self::assertCount( 2, $tokens );
		return $tokens;
	}
}
