<?php
/**
 * Un clon del sitio no puede colar sus propias URLs de staging a IndexNow.
 *
 * Hallazgo R3-b9fecf46 (2026-09-19): las cuatro guardas de
 * `akibara-local-external-indexing-guard.php` sólo miraban `WP_ENVIRONMENT_TYPE`,
 * que vive en `wp-config.php` y viaja con cualquier copia. Un staging de
 * Hostinger —o un backup restaurado en otro dominio— hereda `production` y,
 * con el mismo Rank Math Instant Indexing activo y la MISMA clave de IndexNow
 * que producción (ambos vienen en la BD clonada), el clon podía verificarse
 * contra sí mismo y colar su URL de staging a Bing/Yandex como si fuera
 * contenido real de la tienda. Es el mismo modo de fallo que ya cerró el cepo
 * del correo (S-6625d798) y el de Clarity (S-62b187e9): lo único que NO viaja
 * con la copia es el dominio.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\MuPlugins;

use Akibara\HostingerMail\Transport;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WP_Error;

final class LocalExternalIndexingGuardTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		if ( ! class_exists( Transport::class, false ) ) {
			require_once dirname( __DIR__, 4 ) . '/mu-plugins/akibara-correo-smtp.php';
		}
		if ( ! function_exists( 'akibara_external_indexing_es_la_tienda_de_verdad' ) ) {
			require_once dirname( __DIR__, 4 ) . '/mu-plugins/akibara-local-external-indexing-guard.php';
		}
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox Sólo el dominio real Y el entorno production dejan pasar el envío.
	 * @dataProvider sitios
	 */
	public function test_solo_la_tienda_de_verdad( string $entorno, string $home, bool $esperado, string $porque ): void {
		Functions\when( 'wp_get_environment_type' )->justReturn( $entorno );
		Functions\when( 'home_url' )->justReturn( $home );

		self::assertSame( $esperado, akibara_external_indexing_es_la_tienda_de_verdad(), $porque );
	}

	/** @return array<string, array{0:string,1:string,2:bool,3:string}> */
	public static function sitios(): array {
		return array(
			'la tienda'             => array( 'production', 'https://akibara.cl', true, 'La tienda real sí indexa.' ),
			'clon con production'   => array( 'production', 'https://staging.hostingersite.com', false, 'Un staging hereda el wp-config entero, incluido el entorno.' ),
			'espejo local'          => array( 'local', 'http://localhost:8080', false, 'El QA en Docker no debe hablar con IndexNow.' ),
			'dominio real en local' => array( 'local', 'https://akibara.cl', false, 'Las dos condiciones, no una.' ),
		);
	}

	/**
	 * @testdox En un clon con env=production y dominio de staging, las cuatro guardas bloquean.
	 */
	public function test_las_cuatro_guardas_bloquean_en_un_clon_de_staging(): void {
		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );
		Functions\when( 'home_url' )->justReturn( 'https://staging.hostingersite.com' );
		Functions\when( 'wp_parse_url' )->alias(
			static fn( string $url, int $component = -1 ) => -1 === $component ? parse_url( $url ) : parse_url( $url, $component ) // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- doble de wp_parse_url para la suite.
		);

		self::assertFalse(
			akibara_external_indexing_url_guard( 'https://staging.hostingersite.com/manga/uno/' ),
			'publish_url/submit_url: la URL de staging no debe salir hacia IndexNow.'
		);

		self::assertSame(
			'log-anterior',
			akibara_external_indexing_log_guard( 'log-nuevo', 'log-anterior' ),
			'El log del intento bloqueado no debe quedar escrito en el clon.'
		);

		self::assertSame(
			'log-anterior',
			akibara_external_indexing_global_option_guard( 'log-nuevo', 'rank_math_indexnow_log', 'log-anterior' ),
			'El filtro global repite el bloqueo para la misma opción.'
		);
		self::assertSame(
			'otro-valor',
			akibara_external_indexing_global_option_guard( 'otro-valor', 'otra_opcion_cualquiera', 'valor-anterior' ),
			'Cualquier otra opción no se toca.'
		);

		$resultado = akibara_external_indexing_http_guard( null, array(), 'https://api.indexnow.org/indexnow' );
		self::assertInstanceOf(
			WP_Error::class,
			$resultado,
			'La defensa en profundidad debe cortar la petición HTTP incluso si algo saltó los filtros anteriores.'
		);
	}

	/**
	 * @testdox En la tienda real, las cuatro guardas dejan pasar el envío sin tocarlo.
	 */
	public function test_las_cuatro_guardas_dejan_pasar_en_la_tienda_real(): void {
		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );
		Functions\when( 'home_url' )->justReturn( 'https://akibara.cl' );
		Functions\when( 'wp_parse_url' )->alias(
			static fn( string $url, int $component = -1 ) => -1 === $component ? parse_url( $url ) : parse_url( $url, $component ) // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- doble de wp_parse_url para la suite.
		);

		self::assertSame(
			'https://akibara.cl/manga/uno/',
			akibara_external_indexing_url_guard( 'https://akibara.cl/manga/uno/' )
		);
		self::assertSame( 'log-nuevo', akibara_external_indexing_log_guard( 'log-nuevo', 'log-anterior' ) );
		self::assertSame(
			'log-nuevo',
			akibara_external_indexing_global_option_guard( 'log-nuevo', 'rank_math_indexnow_log', 'log-anterior' )
		);

		$preempt = 'sin-tocar';
		self::assertSame(
			$preempt,
			akibara_external_indexing_http_guard( $preempt, array(), 'https://api.indexnow.org/indexnow' )
		);
	}
}
