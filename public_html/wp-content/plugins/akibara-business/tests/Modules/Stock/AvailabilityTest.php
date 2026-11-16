<?php
/**
 * Contrato del estado comercial (Availability) — la separación A pedido / Preventa.
 *
 * Fija las tres verdades que motivaron la clase (2026-08-29):
 *
 *   1. El DEFAULT de todo lo reservable es «A pedido»: sin configurar nada, un
 *      producto `onbackorder` jamás se presenta como preventa. (Al momento del
 *      diseño, los 422 reservables de producción eran exactamente este caso.)
 *   2. «Preventa» exige un lanzamiento pendiente: fecha futura o TBA.
 *   3. La preventa CADUCA SOLA: fecha de lanzamiento pasada → A pedido. Antes
 *      la ficha quedaba diciendo «Preventa» y emitiendo schema.org/PreOrder
 *      con availabilityStarts en el pasado.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\Stock;

use Akibara\Business\Modules\Stock\Availability;
use Akibara\Business\Modules\Stock\StockStatus;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * @since 1.109.0
 */
final class AvailabilityTest extends TestCase {

	private const TZ = 'America/Santiago';

	/** Reloj fijo de la suite: 2026-08-29 15:00 hora de Chile. */
	private const HOY = '2026-08-29 15:00:00';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'wp_timezone' )->alias(
			static fn(): \DateTimeZone => new \DateTimeZone( self::TZ )
		);
		Functions\when( '__' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function producto( string $stock_status ): \WC_Product {
		return new class( $stock_status ) extends \WC_Product {
			public function get_id(): int {
				return 4242;
			}
		};
	}

	private function ahora(): int {
		return ( new \DateTimeImmutable( self::HOY, new \DateTimeZone( self::TZ ) ) )->getTimestamp();
	}

	/* --- Estados derivados del stock crudo --- */

	public function test_instock_es_disponible(): void {
		$this->assertSame( Availability::DISPONIBLE, Availability::of( $this->producto( StockStatus::IN_STOCK ), $this->ahora() ) );
	}

	public function test_outofstock_es_agotado(): void {
		$this->assertSame( Availability::AGOTADO, Availability::of( $this->producto( StockStatus::OUT_OF_STOCK ), $this->ahora() ) );
	}

	public function test_estado_desconocido_cae_a_agotado_fail_closed(): void {
		$this->assertSame( Availability::AGOTADO, Availability::of( $this->producto( 'loquesea' ), $this->ahora() ) );
	}

	/* --- Vocabulario --- */

	public function test_el_vocabulario_visible_cubre_los_dos_estados(): void {
		$this->assertSame(
			array(
				Availability::DISPONIBLE => 'Disponible',
				Availability::AGOTADO    => 'Agotado',
			),
			Availability::LABELS
		);
	}

	public function test_label_of_deriva_del_estado_resuelto(): void {
		$this->assertSame( 'Disponible', Availability::label_of( $this->producto( StockStatus::IN_STOCK ), $this->ahora() ) );
		$this->assertSame( 'Agotado', Availability::label_of( $this->producto( StockStatus::OUT_OF_STOCK ), $this->ahora() ) );
	}

	/* --- Candado de arquitectura --- */

	/**
	 * Los predicados de preventa, reserva y a pedido fueron retirados bajo el modelo binario.
	 * Este candado impide que reaparezcan.
	 */
	public function test_nadie_vuelve_a_usar_predicados_deprecados_de_stock(): void {
		$src = dirname( __DIR__, 3 ) . '/src';

		$ofensores = array();
		$iterator  = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $src ) );
		foreach ( $iterator as $file ) {
			if ( ! $file instanceof \SplFileInfo || 'php' !== $file->getExtension() ) {
				continue;
			}
			$contents = (string) file_get_contents( $file->getPathname() );
			if (
				false !== strpos( $contents, 'is_preventa(' )
				|| false !== strpos( $contents, 'is_a_pedido(' )
				|| false !== strpos( $contents, 'is_reservable(' )
			) {
				$ofensores[] = $file->getPathname();
			}
		}

		$this->assertSame( array(), $ofensores, 'Los predicados de preventa/reserva fueron retirados.' );
	}

	/**
	 * Los literales de `stock_status` solo pueden vivir en el módulo Stock (que
	 * define las constantes). En cualquier otro sitio son una comparación a mano
	 * que esquiva al resolvedor — el patrón que dejó al backlist entero
	 * vendiéndose como «Preventa».
	 *
	 * El candado era ASIMÉTRICO hasta 2026-08-31: prohibía 'onbackorder' y dejaba
	 * pasar 'instock', y esa mitad abierta tenía varios ofensores vivos comparando
	 * con el literal en una línea y con la constante en la siguiente. Los tres estados
	 * son el mismo vocabulario y se vigilan igual.
	 *
	 * Se inspeccionan TOKENS (token_get_all), no el texto: los docblocks que
	 * documentan el valor no son ofensa. Y se exceptúa el literal usado como
	 * CLAVE de un array propio (`$facts['instock']`, `'instock' => 3`): ahí no
	 * hay comparación de stock_status, hay una estructura nuestra cuyas claves
	 * dan la casualidad de llamarse igual. El brazo de un `match` NO es una
	 * clave aunque también lleve `=>`: compara, y ofende (revisión B1-REV3-1).
	 *
	 * Y el literal DENTRO de un SQL (`meta_value = 'outofstock'` en una cadena,
	 * interpolada o no) tampoco es un token propio: se busca entre comillas
	 * dentro de las cadenas. Esos cinco SQL (SeriesNewestOrder, AdminDashboard,
	 * EditionFacts y CacheWarmer) pasan el estado por `prepare()`.
	 *
	 * @dataProvider proveedor_literales_de_stock
	 */
	public function test_los_literales_de_stock_no_se_comparan_a_mano_fuera_de_stock( string $valor, string $constante ): void {
		$src = dirname( __DIR__, 3 ) . '/src';

		$ofensores = array();
		$iterator  = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $src ) );
		foreach ( $iterator as $file ) {
			if ( ! $file instanceof \SplFileInfo || 'php' !== $file->getExtension() ) {
				continue;
			}
			if ( false !== strpos( $file->getPathname(), '/Modules/Stock/' ) ) {
				continue;
			}

			$tokens = array_values(
				array_filter(
					token_get_all( (string) file_get_contents( $file->getPathname() ) ),
					static fn( $t ): bool => ! is_array( $t )
						|| ! in_array( $t[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true )
				)
			);

			// Pila de lo que está abierto en cada token: '(' '[' '{' o 'match'
			// (el cuerpo de un `match`). Hace falta para distinguir la clave de
			// un array (`'instock' => 3`) del brazo de un `match`
			// (`'outofstock' => …`), que se escriben igual y no son lo mismo: el
			// brazo COMPARA. Hasta la revisión B1-REV3-1 los dos se eximían y
			// `Interleaver::availability_tier()` pasaba el candado comparando
			// literales.
			$abiertos     = array();
			$match_espera = false;
			foreach ( $tokens as $i => $token ) {
				if ( is_array( $token ) && T_MATCH === $token[0] ) {
					$match_espera = true;
				}
				if ( '{' === $token || ( is_array( $token ) && in_array( $token[0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) ) {
					$abiertos[]   = $match_espera ? 'match' : '{';
					$match_espera = false;
				} elseif ( '(' === $token || '[' === $token ) {
					$abiertos[] = $token;
				} elseif ( ')' === $token || ']' === $token || '}' === $token ) {
					array_pop( $abiertos );
				}

				// El literal ENTRE COMILLAS dentro de otra cadena es un SQL que
				// compara (`meta_value = 'outofstock'`, `IN ('instock', …)`), con o
				// sin interpolación: se pasa por `prepare()` con la constante.
				if ( is_array( $token )
					&& in_array( $token[0], array( T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE ), true )
					&& ! in_array( $token[1], array( "'" . $valor . "'", '"' . $valor . '"' ), true )
					&& false !== strpos( $token[1], "'" . $valor . "'" ) ) {
					$ofensores[] = $file->getPathname() . ':' . $token[2] . ' (dentro de un SQL)';
					continue;
				}

				// Igualdad EXACTA del token, no substring: un valor de option como
				// 'instock_and_onbackorder' o una frase de UI que nombra el estado
				// no son comparaciones de stock_status y no ofenden.
				if ( ! is_array( $token )
					|| T_CONSTANT_ENCAPSED_STRING !== $token[0]
					|| ! in_array( $token[1], array( "'" . $valor . "'", '"' . $valor . '"' ), true ) ) {
					continue;
				}

				$siguiente = $tokens[ $i + 1 ] ?? null;
				if ( ']' === $siguiente ) {
					continue; // Lectura por clave: $facts['instock'].
				}
				if ( is_array( $siguiente ) && T_DOUBLE_ARROW === $siguiente[0] && 'match' !== end( $abiertos ) ) {
					continue; // Clave de un array literal: 'instock' => 3.
				}

				$ofensores[] = $file->getPathname() . ':' . $token[2];
			}
		}

		$this->assertSame(
			array(),
			$ofensores,
			"El literal '{$valor}' fuera de Stock esquiva al resolvedor: usá {$constante} o Availability."
		);
	}

	/**
	 * @return array<string, array{0:string, 1:string}>
	 */
	public static function proveedor_literales_de_stock(): array {
		return array(
			'instock'     => array( StockStatus::IN_STOCK, 'StockStatus::IN_STOCK' ),
			'outofstock'  => array( StockStatus::OUT_OF_STOCK, 'StockStatus::OUT_OF_STOCK' ),
			'onbackorder' => array( StockStatus::ON_BACKORDER, 'StockStatus::ON_BACKORDER' ),
		);
	}
}
