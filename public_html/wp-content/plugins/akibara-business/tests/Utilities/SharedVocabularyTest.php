<?php
/**
 * Las dos utilidades que nacieron de la auditoría 2026-08-31, y sus candados.
 *
 *   - `PedidoPolicy::mes()` — la tabla de meses en español estaba copiada CUATRO
 *     veces (PedidoPolicy, CustomerVisibility, OrderTimeline y SeriesFacts), y
 *     una de las copias documentaba «abreviados» sobre nombres completos.
 *   - `StoreTime::zone()` — el idioma `function_exists( 'wp_timezone' ) ? … : UTC`
 *     estaba copiado diez veces y las copias ya no decían lo mismo: unas caían a
 *     UTC, otras a null, y `ReminderEmail` llamaba `wp_timezone()` SIN guarda.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Utilities;

use Akibara\Business\Modules\EstimatedDelivery\PedidoPolicy;
use Akibara\Business\Utilities\StoreTime;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class SharedVocabularyTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox Los doce meses salen completos y en minúscula.
	 */
	public function test_los_meses_son_completos_y_en_minuscula(): void {
		self::assertSame(
			array(
				'enero',
				'febrero',
				'marzo',
				'abril',
				'mayo',
				'junio',
				'julio',
				'agosto',
				'septiembre',
				'octubre',
				'noviembre',
				'diciembre',
			),
			array_map( static fn( int $n ): string => PedidoPolicy::mes( $n ), range( 1, 12 ) )
		);
	}

	/**
	 * @testdox Fuera de rango devuelve '', que es el predicado de validez.
	 *
	 * @dataProvider fuera_de_rango
	 */
	public function test_fuera_de_rango_devuelve_vacio( int $mes ): void {
		self::assertSame( '', PedidoPolicy::mes( $mes ) );
	}

	/**
	 * @return array<string, array{0:int}>
	 */
	public static function fuera_de_rango(): array {
		return array(
			'cero'     => array( 0 ),
			'trece'    => array( 13 ),
			'negativo' => array( -1 ),
		);
	}

	/**
	 * El candado: la tabla se declara UNA vez.
	 */
	public function test_ninguna_otra_clase_declara_la_tabla_completa(): void {
		$src       = dirname( __DIR__, 2 ) . '/src';
		$ofensores = array();

		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $src ) );
		foreach ( $iterator as $file ) {
			if ( ! $file instanceof \SplFileInfo || 'php' !== $file->getExtension() ) {
				continue;
			}
			if ( false !== strpos( $file->getPathname(), '/EstimatedDelivery/PedidoPolicy.php' ) ) {
				continue;
			}

			$contenido = (string) file_get_contents( $file->getPathname() );
			// «enero» y «diciembre» en el mismo fichero: eso es la tabla entera.
			// La tabla ABREVIADA de BannerRenderer ('ene'…'dic') es otra cosa y no
			// cae acá.
			if ( false !== strpos( $contenido, "'enero'" ) && false !== strpos( $contenido, "'diciembre'" ) ) {
				$ofensores[] = $file->getPathname();
			}
		}

		self::assertSame(
			array(),
			$ofensores,
			'Los meses completos se declaran SOLO en PedidoPolicy; se leen por PedidoPolicy::mes().'
		);
	}

	/**
	 * @testdox Con WordPress cargado, la zona es la de la tienda.
	 */
	public function test_la_zona_es_la_del_sitio(): void {
		Functions\when( 'wp_timezone' )->alias(
			static fn(): \DateTimeZone => new \DateTimeZone( 'America/Santiago' )
		);

		self::assertSame( 'America/Santiago', StoreTime::zone()->getName() );
	}

	/**
	 * El candado: nadie vuelve a escribir el idioma a mano.
	 *
	 * Incluye el caso INVERSO, que es el que reventaba: `wp_timezone()` sin
	 * guarda. En un bootstrap sin WordPress —el de la suite— eso es un fatal, y
	 * `ReminderEmail::summary_max_ymd()` lo tenía.
	 *
	 * Cubre también el otro reverso (N-storetime, 2026-09-19): hardcodear el
	 * NOMBRE de la zona (`new DateTimeZone( 'America/Santiago' )`), que
	 * `RemindersCron` tenía en dos sitios. Sin `StoreTime::zone()` de por
	 * medio, ese hardcode no tiene el fallback a UTC cuando WordPress no está
	 * cargado —la misma clase de bug, con la mitad del idioma en vez de la
	 * función completa.
	 */
	public function test_nadie_reimplementa_la_zona_de_la_tienda(): void {
		$src       = dirname( __DIR__, 2 ) . '/src';
		$ofensores = array();

		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $src ) );
		foreach ( $iterator as $file ) {
			if ( ! $file instanceof \SplFileInfo || 'php' !== $file->getExtension() ) {
				continue;
			}
			if ( false !== strpos( $file->getPathname(), '/Utilities/StoreTime.php' ) ) {
				continue;
			}

			$contenido = (string) file_get_contents( $file->getPathname() );
			foreach ( preg_split( '/\R/', $contenido ) as $numero => $linea ) {
				// Sólo CÓDIGO: los comentarios que NOMBRAN la función explican, no
				// reimplementan (mismo criterio que el candado de `onbackorder`).
				if ( 1 === preg_match( '#^\s*(\*|//)#', $linea ) ) {
					continue;
				}
				if ( false !== strpos( $linea, 'wp_timezone(' )
					|| false !== strpos( $linea, "DateTimeZone( 'America/Santiago' )" ) ) {
					$ofensores[] = basename( $file->getPathname() ) . ':' . ( $numero + 1 );
				}
			}
		}

		self::assertSame(
			array(),
			$ofensores,
			'La zona de la tienda se resuelve SOLO por StoreTime::zone().'
		);
	}
}
