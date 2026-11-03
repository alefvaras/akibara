<?php
/**
 * Contrato del swap AVIF → JPG de la imagen social.
 *
 * WhatsApp y X no renderizan AVIF de forma fiable, así que `SeoSocial` cambia
 * el `og:image` de las portadas por la derivada JPG que produce
 * `SocialImageDerivatives`. El cambio tiene que ser atómico —URL y `type` a la
 * vez— porque emitir la URL nueva con `og:image:type image/avif` deja la
 * etiqueta mintiendo sobre el archivo.
 *
 * Estos casos usan el directorio real, no un doble: `portadas_dir()` decide por
 * `is_dir()` y el swap por `file_exists()`, y el fallback «sin hermana física
 * se conserva el AVIF» sólo se prueba de verdad contra el sistema de archivos.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\SeoSocial;

use Akibara\Business\Modules\SeoSocial\SeoSocial;
use Akibara\Business\Modules\SeoSocial\SocialImageDerivatives;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * @since 1.107.2
 */
final class SocialImageSwapTest extends TestCase {

	/** Raíz temporal que hace las veces de WP_CONTENT_DIR. */
	private static string $root = '';

	/** Portada de prueba, escrita en el directorio activo. */
	private static string $fixture = '';

	/** Ficheros que escriben las pruebas de `ensure_now()`, para limpiarlos. */
	private static array $escritos = array();

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		// WP_CONTENT_DIR es una constante y varias suites de este módulo la
		// necesitan: la primera que llega la fija y las demás reutilizan su
		// directorio. Antes esta clase exigía ser la primera y se SALTABA sus
		// ocho casos en cuanto otra suite se le adelantaba — verde silencioso.
		if ( ! defined( 'WP_CONTENT_DIR' ) ) {
			self::$root = sys_get_temp_dir() . '/akb-social-' . uniqid();
			mkdir( self::$root . '/uploads/portadas', 0777, true );
			define( 'WP_CONTENT_DIR', self::$root );
		}

		$dir = WP_CONTENT_DIR . '/uploads/portadas';
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0777, true );
		}

		// Portada con derivada social y portada sin ella: los dos lados del
		// fallback.
		self::$fixture = $dir . '/berserk-01' . SocialImageDerivatives::SUFFIX;
		file_put_contents( self::$fixture, 'jpg' );

		// Portadas AVIF de verdad (GD las escribe) SIN hermana: lo que
		// `ensure_now()` tiene que resolver en el acto. Y un «AVIF» que no lo
		// es, para el lado de la caché negativa.
		$lienzo = imagecreatetruecolor( 4, 4 );
		imagefill( $lienzo, 0, 0, imagecolorallocate( $lienzo, 200, 30, 30 ) );
		foreach ( array( 'ahora-01', 'ahora-02' ) as $slug ) {
			imageavif( $lienzo, $dir . '/' . $slug . '.avif' );
			self::$escritos[] = $dir . '/' . $slug . '.avif';
			self::$escritos[] = $dir . '/' . $slug . SocialImageDerivatives::SUFFIX;
		}
		imagedestroy( $lienzo );
		file_put_contents( $dir . '/rota-01.avif', 'esto no es un avif' );
		self::$escritos[] = $dir . '/rota-01.avif';
		self::$escritos[] = $dir . '/rota-01' . SocialImageDerivatives::SUFFIX;
	}

	public static function tearDownAfterClass(): void {
		if ( '' !== self::$fixture && is_file( self::$fixture ) ) {
			unlink( self::$fixture );
		}
		foreach ( self::$escritos as $fichero ) {
			if ( is_file( $fichero ) ) {
				unlink( $fichero );
			}
		}
		if ( '' !== self::$root && is_dir( self::$root ) ) {
			array_map( 'unlink', (array) glob( self::$root . '/uploads/portadas/*' ) );
			rmdir( self::$root . '/uploads/portadas' );
			rmdir( self::$root . '/uploads' );
			rmdir( self::$root );
		}
		parent::tearDownAfterClass();
	}

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// `ensure_for_slug()` encola trabajo de cron cuando falta la hermana;
		// acá sólo se mide el swap del og:image.
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_single_event' )->justReturn( true );
		// RecompressorTest define `wp_getimagesize` con un alias que sobrevive
		// a su tearDown (Brain Monkey limpia la expectativa, no el símbolo):
		// en orden natural las medidas del og:image salían de SU fixture.
		// Aquí la función mide el fichero de verdad, como en WordPress.
		Functions\when( 'wp_getimagesize' )->alias(
			static fn( string $path ) => @getimagesize( $path ) // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox Cambia el AVIF original por su derivada JPG y corrige el tipo.
	 */
	public function test_swaps_original_avif_for_its_jpeg_sibling(): void {
		$module = new SeoSocial();

		$result = $module->prefer_jpeg_social_image(
			array(
				'url'    => 'https://akibara.cl/wp-content/uploads/portadas/berserk-01.avif',
				'type'   => 'image/avif',
				'width'  => 758,
				'height' => 1136,
			)
		);

		self::assertSame( 'https://akibara.cl/wp-content/uploads/portadas/berserk-01-og.jpg', $result['url'] );
		self::assertSame( 'image/jpeg', $result['type'] );
		// La fixture «jpg» no es una imagen legible: las medidas del AVIF no
		// se heredan (serían falsas) y tampoco se inventan.
		self::assertArrayNotHasKey( 'width', $result );
		self::assertArrayNotHasKey( 'height', $result );
	}

	/**
	 * R8-og-sin-medidas (2026-09-20): borrar `width`/`height` dejaba
	 * `og:image:width/height` vacíos en ~1.600 fichas. Se emiten las de la
	 * hermana JPG real.
	 *
	 * @testdox La hermana JPG emite sus medidas reales, no las del AVIF.
	 */
	public function test_the_jpeg_sibling_carries_its_real_dimensions(): void {
		$dir    = WP_CONTENT_DIR . '/uploads/portadas';
		$target = $dir . '/medidas-01' . SocialImageDerivatives::SUFFIX;
		$lienzo = imagecreatetruecolor( 3, 5 );
		imagejpeg( $lienzo, $target );
		imagedestroy( $lienzo );
		self::$escritos[] = $target;

		$result = ( new SeoSocial() )->prefer_jpeg_social_image(
			array(
				'url'    => 'https://akibara.cl/wp-content/uploads/portadas/medidas-01-682x1024.avif',
				'type'   => 'image/avif',
				'width'  => 682,
				'height' => 1024,
			)
		);

		self::assertSame( 'https://akibara.cl/wp-content/uploads/portadas/medidas-01-og.jpg', $result['url'] );
		self::assertSame( 3, $result['width'] );
		self::assertSame( 5, $result['height'] );
	}

	/**
	 * R8-hermana-sin-tope (2026-09-20): 367 de 1.699 hermanas pesaban más de
	 * 300 KB porque salían 1:1 del original (hasta 5.596×8.394). La conversión
	 * acota el lado mayor a `MAX_SIDE` conservando la proporción.
	 *
	 * @testdox `ensure_now()` acota la hermana al lado mayor permitido.
	 */
	public function test_ensure_now_caps_the_sibling_to_the_max_side(): void {
		if ( ! function_exists( 'imageavif' ) || ! function_exists( 'imagecreatefromavif' ) ) {
			self::markTestSkipped( 'Sin AVIF en GD.' );
		}
		$dir    = WP_CONTENT_DIR . '/uploads/portadas';
		$ancho  = SocialImageDerivatives::MAX_SIDE + 300;
		$lienzo = imagecreatetruecolor( $ancho, 30 );
		imagefill( $lienzo, 0, 0, imagecolorallocate( $lienzo, 20, 20, 200 ) );
		imageavif( $lienzo, $dir . '/grande-01.avif' );
		imagedestroy( $lienzo );
		self::$escritos[] = $dir . '/grande-01.avif';
		self::$escritos[] = $dir . '/grande-01' . SocialImageDerivatives::SUFFIX;

		self::assertTrue( SocialImageDerivatives::ensure_now( 'grande-01' ) );

		$medidas = getimagesize( $dir . '/grande-01' . SocialImageDerivatives::SUFFIX );
		self::assertSame( SocialImageDerivatives::MAX_SIDE, $medidas[0] );
		self::assertSame( (int) round( 30 * SocialImageDerivatives::MAX_SIDE / $ancho ), $medidas[1], 'La proporción se conserva.' );
	}

	/**
	 * Revisión R8 (H5): las 367 hermanas de producción que salieron sin tope
	 * son POSTERIORES a su AVIF, así que el mtime solo nunca las rehacía. Una
	 * hermana fresca pero más grande que el tope se vuelve a escribir — el caso
	 * real es vertical, como una portada.
	 *
	 * @testdox Una hermana existente que supera el tope se rehace aunque sea más nueva que el AVIF.
	 */
	public function test_an_oversized_existing_sibling_is_rebuilt(): void {
		if ( ! function_exists( 'imageavif' ) || ! function_exists( 'imagecreatefromavif' ) ) {
			self::markTestSkipped( 'Sin AVIF en GD.' );
		}
		$dir    = WP_CONTENT_DIR . '/uploads/portadas';
		$alto   = SocialImageDerivatives::MAX_SIDE * 2;
		$lienzo = imagecreatetruecolor( 40, $alto );
		imagefill( $lienzo, 0, 0, imagecolorallocate( $lienzo, 200, 120, 20 ) );
		imageavif( $lienzo, $dir . '/alta-01.avif' );
		// La hermana «vieja»: 1:1, escrita DESPUÉS del AVIF, como en producción.
		$target = $dir . '/alta-01' . SocialImageDerivatives::SUFFIX;
		imagejpeg( $lienzo, $target );
		touch( $target, time() + 5 );
		imagedestroy( $lienzo );
		self::$escritos[] = $dir . '/alta-01.avif';
		self::$escritos[] = $target;
		self::assertSame( $alto, getimagesize( $target )[1], 'Antes: la hermana mide el doble del tope.' );

		self::assertTrue( SocialImageDerivatives::ensure_now( 'alta-01' ) );

		$medidas = getimagesize( $target );
		self::assertSame( SocialImageDerivatives::MAX_SIDE, $medidas[1], 'Después: acotada por el lado mayor (el alto).' );
		self::assertSame( 20, $medidas[0] );

		// Ya acotada, la siguiente llamada la da por fresca: un mtime sembrado
		// que sobrevive demuestra que NO se reescribió (revisión R8, 6.1).
		// El sello se LEE del fichero tras el touch (en la suite `time()` y el
		// reloj del sistema de archivos difieren en un segundo): lo que se
		// afirma es que ese valor sobrevive, y una reconversión lo pisaría con
		// «ahora», 50 s por debajo.
		touch( $target, time() + 50 );
		clearstatcache( true, $target );
		$sello = filemtime( $target );
		self::assertTrue( SocialImageDerivatives::ensure_now( 'alta-01' ) );
		clearstatcache( true, $target );
		self::assertSame( $sello, filemtime( $target ), 'Fresca: no se volvió a convertir.' );
	}

	/**
	 * @testdox Acepta las variantes de tamaño de WordPress y apunta igual al original.
	 */
	public function test_accepts_wordpress_size_variants(): void {
		$module = new SeoSocial();

		// Rank Math elige la variante más cercana a 1200 px cuando el featured
		// image es grande: sin esto, las guías salían en AVIF pese a existir la
		// derivada [QA 2026-08-16].
		$result = $module->prefer_jpeg_social_image(
			array(
				'url'  => 'https://akibara.cl/wp-content/uploads/portadas/berserk-01-682x1024.avif',
				'type' => 'image/avif',
			)
		);

		self::assertSame( 'https://akibara.cl/wp-content/uploads/portadas/berserk-01-og.jpg', $result['url'] );
		self::assertSame( 'image/jpeg', $result['type'] );
	}

	/**
	 * @testdox Conserva el AVIF cuando la derivada no existe en disco.
	 */
	public function test_keeps_avif_when_the_sibling_is_missing(): void {
		$module = new SeoSocial();
		$input  = array(
			'url'  => 'https://akibara.cl/wp-content/uploads/portadas/sin-derivada.avif',
			'type' => 'image/avif',
		);

		// Mejor un preview posiblemente roto que un 404 seguro.
		self::assertSame( $input, $module->prefer_jpeg_social_image( $input ) );
	}

	/**
	 * @testdox No toca imágenes ajenas a /portadas/ ni formatos que no sean AVIF.
	 */
	public function test_leaves_unrelated_images_untouched(): void {
		$module = new SeoSocial();

		$ajena = array( 'url' => 'https://akibara.cl/wp-content/uploads/2026/08/banner.avif', 'type' => 'image/avif' );
		self::assertSame( $ajena, $module->prefer_jpeg_social_image( $ajena ) );

		$jpg = array( 'url' => 'https://akibara.cl/wp-content/uploads/portadas/berserk-01.jpg', 'type' => 'image/jpeg' );
		self::assertSame( $jpg, $module->prefer_jpeg_social_image( $jpg ) );
	}

	/**
	 * @testdox Devuelve la entrada intacta ante formas inesperadas.
	 */
	public function test_is_defensive_about_the_incoming_shape(): void {
		$module = new SeoSocial();

		self::assertSame( '', $module->prefer_jpeg_social_image( '' ) );
		self::assertNull( $module->prefer_jpeg_social_image( null ) );
		self::assertSame( array(), $module->prefer_jpeg_social_image( array() ) );
		self::assertSame(
			array( 'url' => 123 ),
			$module->prefer_jpeg_social_image( array( 'url' => 123 ) )
		);
	}
	/**
	 * @testdox Una variante huérfana sigue sirviendo aunque el AVIF original ya no esté.
	 *
	 * Reemplazar la portada borra el `.avif` viejo. Si en ese momento se
	 * descartara también la `-og.jpg` que sí existe, la ficha volvería a
	 * compartirse sin imagen justo después de mejorarle la portada.
	 */
	public function test_an_orphan_derivative_is_still_usable(): void {
		$huerfana = SocialImageDerivatives::portadas_dir() . '/huerfana-og.jpg';
		file_put_contents( $huerfana, 'jpg' );

		try {
			self::assertTrue( SocialImageDerivatives::ensure_for_slug( 'huerfana' ) );
		} finally {
			unlink( $huerfana );
		}
	}

	/**
	 * @testdox Sin portada de ninguna clase no se inventa nada.
	 */
	public function test_a_missing_cover_yields_nothing(): void {
		self::assertFalse( SocialImageDerivatives::ensure_for_slug( 'no-existe-esta-portada' ) );
	}

	/**
	 * @testdox Un slug con caracteres fuera del patrón nunca toca el disco.
	 */
	public function test_a_hostile_slug_is_refused(): void {
		self::assertFalse( SocialImageDerivatives::ensure_for_slug( '../../wp-config' ) );
		self::assertFalse( SocialImageDerivatives::ensure_for_slug( 'Portada Con Espacios' ) );
	}

	/**
	 * @testdox `ensure_now()` escribe la hermana que falta en el acto y la deja como JPEG legible.
	 */
	public function test_ensure_now_writes_the_missing_sibling_at_once(): void {
		$dir    = WP_CONTENT_DIR . '/uploads/portadas';
		$target = $dir . '/ahora-01' . SocialImageDerivatives::SUFFIX;
		self::assertFileDoesNotExist( $target, 'La fixture nace sin hermana.' );

		self::assertTrue( SocialImageDerivatives::ensure_now( 'ahora-01' ) );
		self::assertFileExists( $target );
		self::assertSame( 'image/jpeg', (string) ( getimagesize( $target )['mime'] ?? '' ), 'Lo escrito es un JPEG de verdad, no un AVIF renombrado.' );

		self::assertTrue( SocialImageDerivatives::ensure_now( 'ahora-01' ), 'La segunda llamada encuentra la hermana fresca y no vuelve a convertir.' );
	}

	/**
	 * @testdox `ensure_now()` respeta la caché negativa: un slug marcado como fallido no se reintenta ni se escribe.
	 */
	public function test_ensure_now_respects_the_negative_cache(): void {
		Functions\when( 'get_transient' )->alias( fn( $clave ) => 'akb_og_fail_ahora-02' === $clave ? 1 : false );

		self::assertFalse( SocialImageDerivatives::ensure_now( 'ahora-02' ) );
		self::assertFileDoesNotExist( WP_CONTENT_DIR . '/uploads/portadas/ahora-02' . SocialImageDerivatives::SUFFIX, 'Con la marca de fallo puesta no se convierte, aunque el AVIF sea válido.' );
	}

	/**
	 * @testdox `ensure_now()` deja la marca de fallo cuando el AVIF no se puede leer, y no toca el disco.
	 */
	public function test_ensure_now_records_the_failure_when_the_avif_is_unreadable(): void {
		$fallidos = array();
		Functions\when( 'set_transient' )->alias(
			function ( $clave ) use ( &$fallidos ) {
				$fallidos[] = $clave;

				return true;
			}
		);

		self::assertFalse( SocialImageDerivatives::ensure_now( 'rota-01' ) );
		self::assertSame( array( 'akb_og_fail_rota-01' ), $fallidos, 'El fallo queda anotado para que el siguiente carrito no repita el decode.' );
		self::assertFileDoesNotExist( WP_CONTENT_DIR . '/uploads/portadas/rota-01' . SocialImageDerivatives::SUFFIX );
	}

	/**
	 * @testdox `ensure_now()` rechaza slugs hostiles y portadas inexistentes sin tocar el disco.
	 */
	public function test_ensure_now_refuses_hostile_slugs_and_missing_covers(): void {
		self::assertFalse( SocialImageDerivatives::ensure_now( '../../wp-config' ) );
		self::assertFalse( SocialImageDerivatives::ensure_now( 'Portada Con Espacios' ) );
		self::assertFalse( SocialImageDerivatives::ensure_now( 'no-existe-esta-portada' ) );
	}
}
