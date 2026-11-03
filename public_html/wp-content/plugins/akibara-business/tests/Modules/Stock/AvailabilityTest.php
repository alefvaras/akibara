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

use Akibara\Business\Modules\EstimatedDelivery\DateCalculator;
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

	private function stub_metas( string $eta_type, string $release = '' ): void {
		Functions\when( 'get_post_meta' )->alias(
			static function ( int $post_id, string $key, bool $single = true ) use ( $eta_type, $release ) {
				unset( $post_id, $single );

				if ( Availability::META_TYPE === $key ) {
					return $eta_type;
				}

				return Availability::META_RELEASE_DATE === $key ? $release : '';
			}
		);
	}

	private function ahora(): int {
		return ( new \DateTimeImmutable( self::HOY, new \DateTimeZone( self::TZ ) ) )->getTimestamp();
	}

	/* --- Estados derivados del stock crudo --- */

	public function test_instock_es_disponible(): void {
		$this->stub_metas( '' );
		$this->assertSame( Availability::DISPONIBLE, Availability::of( $this->producto( StockStatus::IN_STOCK ), $this->ahora() ) );
	}

	public function test_outofstock_es_agotado(): void {
		$this->stub_metas( '' );
		$this->assertSame( Availability::AGOTADO, Availability::of( $this->producto( StockStatus::OUT_OF_STOCK ), $this->ahora() ) );
	}

	public function test_estado_desconocido_cae_a_agotado_fail_closed(): void {
		$this->stub_metas( '' );
		$this->assertSame( Availability::AGOTADO, Availability::of( $this->producto( 'loquesea' ), $this->ahora() ) );
	}

	/* --- Verdad 1: el default de lo reservable es A PEDIDO --- */

	/**
	 * Sin meta (NULL en prod), 'auto' y 'disabled' son todos A pedido: cargar
	 * un tomo de backlist no requiere tocar el panel. También un tipo basura.
	 *
	 * @dataProvider tipos_que_no_son_preventa
	 */
	public function test_reservable_sin_lanzamiento_pendiente_es_a_pedido( string $eta_type ): void {
		$this->stub_metas( $eta_type );
		$producto = $this->producto( StockStatus::ON_BACKORDER );

		$this->assertSame( Availability::A_PEDIDO, Availability::of( $producto, $this->ahora() ) );
		$this->assertTrue( Availability::is_a_pedido( $producto, $this->ahora() ) );
		$this->assertFalse( Availability::is_preventa( $producto, $this->ahora() ) );
	}

	/** @return array<string, array{string}> */
	public function tipos_que_no_son_preventa(): array {
		return array(
			'sin meta (default absoluto)' => array( '' ),
			'auto'                        => array( Availability::TYPE_AUTO ),
			'disabled'                    => array( Availability::TYPE_DISABLED ),
			'tipo basura'                 => array( 'garbage' ),
		);
	}

	/* --- Verdad 2: preventa exige lanzamiento pendiente --- */

	public function test_tba_es_preventa(): void {
		$this->stub_metas( Availability::TYPE_TBA );
		$this->assertSame( Availability::PREVENTA, Availability::of( $this->producto( StockStatus::ON_BACKORDER ), $this->ahora() ) );
	}

	public function test_release_date_futura_es_preventa(): void {
		$this->stub_metas( Availability::TYPE_RELEASE_DATE, '2026-10-15' );
		$this->assertSame( Availability::PREVENTA, Availability::of( $this->producto( StockStatus::ON_BACKORDER ), $this->ahora() ) );
	}

	/**
	 * El día del lanzamiento sigue siendo preventa (degrada al día SIGUIENTE) —
	 * la misma semántica de corte que DateCalculator::resolve_base_timestamp().
	 */
	public function test_release_date_hoy_sigue_siendo_preventa(): void {
		$this->stub_metas( Availability::TYPE_RELEASE_DATE, '2026-08-29' );
		$this->assertSame( Availability::PREVENTA, Availability::of( $this->producto( StockStatus::ON_BACKORDER ), $this->ahora() ) );
	}

	/* --- Verdad 3: la preventa caduca sola --- */

	public function test_release_date_pasada_degrada_a_a_pedido(): void {
		$this->stub_metas( Availability::TYPE_RELEASE_DATE, '2026-08-28' );
		$producto = $this->producto( StockStatus::ON_BACKORDER );

		$this->assertSame( Availability::A_PEDIDO, Availability::of( $producto, $this->ahora() ) );
		$this->assertFalse( Availability::is_preventa( $producto, $this->ahora() ) );
	}

	/**
	 * La comparación es por día CALENDARIO en la zona de la tienda, no por
	 * segundos: a las 23:00 CL del día del lanzamiento el server (UTC) ya está
	 * en el día siguiente, y la preventa NO debe caducar 4 horas antes.
	 */
	public function test_la_degradacion_respeta_la_zona_horaria_de_la_tienda(): void {
		$this->stub_metas( Availability::TYPE_RELEASE_DATE, '2026-08-29' );
		$tarde_noche = ( new \DateTimeImmutable( '2026-08-29 23:30:00', new \DateTimeZone( self::TZ ) ) )->getTimestamp();

		$this->assertSame(
			Availability::PREVENTA,
			Availability::of( $this->producto( StockStatus::ON_BACKORDER ), $tarde_noche )
		);
	}

	public function test_release_date_corrupta_degrada_a_a_pedido(): void {
		$this->stub_metas( Availability::TYPE_RELEASE_DATE, 'no-es-una-fecha' );
		$this->assertSame( Availability::A_PEDIDO, Availability::of( $this->producto( StockStatus::ON_BACKORDER ), $this->ahora() ) );
	}

	public function test_release_date_vacia_degrada_a_a_pedido(): void {
		$this->stub_metas( Availability::TYPE_RELEASE_DATE, '' );
		$this->assertSame( Availability::A_PEDIDO, Availability::of( $this->producto( StockStatus::ON_BACKORDER ), $this->ahora() ) );
	}

	/* --- Vocabulario --- */

	public function test_el_vocabulario_visible_cubre_los_cuatro_estados(): void {
		$this->assertSame(
			array(
				Availability::DISPONIBLE => 'Disponible',
				Availability::A_PEDIDO   => 'A pedido',
				Availability::PREVENTA   => 'Preventa',
				Availability::AGOTADO    => 'Agotado',
			),
			Availability::LABELS
		);
	}

	public function test_label_of_deriva_del_estado_resuelto(): void {
		$this->stub_metas( Availability::TYPE_AUTO );
		$this->assertSame( 'A pedido', Availability::label_of( $this->producto( StockStatus::ON_BACKORDER ), $this->ahora() ) );

		$this->stub_metas( Availability::TYPE_TBA );
		$this->assertSame( 'Preventa', Availability::label_of( $this->producto( StockStatus::ON_BACKORDER ), $this->ahora() ) );
	}

	public function test_label_for_state_desconocido_cae_a_agotado(): void {
		$this->assertSame( 'Agotado', Availability::label_for_state( 'inventado' ) );
	}

	/* --- Sincronía de contratos --- */

	/**
	 * DateCalculator referencia los meta keys canónicos de Availability. Si
	 * alguien "des-unifica" las constantes, el estado comercial y el cálculo de
	 * fechas leerían filas distintas y la degradación quedaría muda.
	 */
	public function test_date_calculator_comparte_los_meta_keys_canonicos(): void {
		$this->assertSame( Availability::META_TYPE, DateCalculator::META_TYPE );
		$this->assertSame( Availability::META_RELEASE_DATE, DateCalculator::META_RELEASE_DATE );
	}

	/* --- Candado de arquitectura --- */

	/**
	 * El predicado StockStatus::is_preventa() se RETIRÓ en 1.109.0: su nombre
	 * mentía (onbackorder ≠ preventa; son dos estados comerciales). Quien
	 * pregunte «¿reservable?» usa is_reservable(); quien necesite distinguir
	 * usa Availability. Este candado impide que el nombre viejo reaparezca y
	 * reviva la ambigüedad que costó separar.
	 */
	public function test_nadie_vuelve_a_usar_el_predicado_ambiguo_is_preventa_de_stock_status(): void {
		$src = dirname( __DIR__, 3 ) . '/src';

		$ofensores = array();
		$iterator  = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $src ) );
		foreach ( $iterator as $file ) {
			if ( ! $file instanceof \SplFileInfo || 'php' !== $file->getExtension() ) {
				continue;
			}
			$contents = (string) file_get_contents( $file->getPathname() );
			if ( false !== strpos( $contents, 'StockStatus::is_preventa(' ) ) {
				$ofensores[] = $file->getPathname();
			}
		}

		$this->assertSame( array(), $ofensores, 'StockStatus::is_preventa() fue retirado: usá is_reservable() o Availability::is_preventa().' );
	}

	/**
	 * Los literales de `stock_status` solo pueden vivir en el módulo Stock (que
	 * define las constantes). En cualquier otro sitio son una comparación a mano
	 * que esquiva al resolvedor — el patrón que dejó al backlist entero
	 * vendiéndose como «Preventa».
	 *
	 * El candado era ASIMÉTRICO hasta 2026-08-31: prohibía 'onbackorder' y dejaba
	 * pasar 'instock', y esa mitad abierta tenía siete ofensores vivos, uno de
	 * ellos (`TaxonomyDiscount\Settings::is_stock_eligible`) comparando con el
	 * literal en una línea y con la constante en la siguiente. Los tres estados
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
			'onbackorder' => array( StockStatus::ON_BACKORDER, 'StockStatus::ON_BACKORDER' ),
			'outofstock'  => array( StockStatus::OUT_OF_STOCK, 'StockStatus::OUT_OF_STOCK' ),
		);
	}
}
