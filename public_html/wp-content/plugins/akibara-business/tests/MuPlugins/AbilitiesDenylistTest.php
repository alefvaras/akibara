<?php
/**
 * La denylist de abilities veta el REGISTRO, no la ejecución.
 *
 * Invariante que fija esta prueba: las abilities selladas como peligrosas salen
 * del registro con un `permission_callback` que SIEMPRE devuelve false Y con
 * `meta.mcp.public = false`. Las dos capas importan: el adaptador MCP sólo
 * lista lo que trae `mcp.public`, y el `permission_callback` cierra la otra
 * vía (REST directo). Quitar una de las dos deja la ability alcanzable.
 *
 * Por qué existe la prueba: el veto se escribió para dos abilities de Rank Math
 * y la lista quedó escrita a mano. El 2026-09-12 la ronda de QA midió que
 * WooCommerce 11.1 publica SIETE abilities propias con `mcp.public = true`
 * —entre ellas `woocommerce/product-delete`, anotada como destructiva— y que
 * ninguna pasaba por esta lista. El dueño decidió el 2026-09-13 vetar sólo la
 * de borrar. Sin este candado, la próxima ability destructiva de un tercero
 * vuelve a entrar sin que nadie se entere.
 *
 * REAPERTURA S-31b76a50 (2026-09-19): hasta entonces `permission_callback`
 * era el literal `'__return_false'`, así que un intento de ejecutar una
 * ability vetada no dejaba ni una línea de rastro. Ahora es un closure que
 * anota antes de negar — `test_un_intento_vetado_deja_rastro` fija eso.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\MuPlugins;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class AbilitiesDenylistTest extends TestCase {

	private const MU_PLUGIN = '/mu-plugins/akibara-abilities-denylist.php';

	/** @var callable|null El filtro que el fichero enganchó al cargarse. */
	private static $veto = null;

	/** @var array{0:int,1:int} Prioridad y número de argumentos con que se enganchó. */
	private static array $enganche = array( 0, 0 );

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', '/tmp/' );
		}

		// El fichero sólo llama a `add_filter` con una función anónima, así que
		// no se puede recuperar por nombre: se intercepta el registro y se
		// guarda el callback para llamarlo directamente en cada caso.
		Monkey\setUp();
		Functions\when( 'add_filter' )->alias(
			static function ( string $hook, $callback, int $prioridad = 10, int $argumentos = 1 ): bool {
				if ( 'wp_register_ability_args' === $hook ) {
					self::$veto      = $callback;
					self::$enganche = array( $prioridad, $argumentos );
				}
				return true;
			}
		);
		require_once dirname( __DIR__, 4 ) . self::MU_PLUGIN;
		Monkey\tearDown();
	}

	/**
	 * El callback del veto anota cada denegación en el rastro SI el mu-plugin
	 * del rastro está cargado — y en la suite lo está o no según qué prueba
	 * corrió antes. Sin dobles propios, esta clase pasaba en el orden natural y
	 * reventaba en el inverso (`get_current_user_id is not defined nor mocked`,
	 * 2026-09-19). Con ellos, el resultado no depende del orden.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'wc_get_logger' )->justReturn(
			new class() {
				/** @param array<string,mixed> $contexto */
				public function info( string $mensaje, array $contexto = array() ): void {
					unset( $mensaje, $contexto );
				}
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Argumentos de registro como los que trae una ability publicada por MCP.
	 *
	 * @return array<string,mixed>
	 */
	private function argumentos_de_registro(): array {
		return array(
			'label'               => 'Cualquiera',
			'permission_callback' => 'wc_rest_check_post_permissions',
			'meta'                => array(
				'mcp' => array(
					'public'      => true,
					'destructive' => true,
				),
			),
		);
	}

	/** @testdox El fichero engancha el filtro real del registro, con los dos argumentos. */
	public function test_engancha_el_filtro_del_registro(): void {
		$this->assertIsCallable( self::$veto, 'El mu-plugin no enganchó wp_register_ability_args.' );
		$this->assertSame( array( 10, 2 ), self::$enganche, 'El filtro necesita nombre y argumentos: prioridad 10, 2 argumentos.' );
	}

	/**
	 * @testdox Las abilities vetadas salen sin permiso y fuera de MCP.
	 * @dataProvider abilities_vetadas
	 */
	public function test_las_vetadas_salen_cerradas_por_las_dos_vias( string $nombre ): void {
		$salida = ( self::$veto )( $this->argumentos_de_registro(), $nombre );

		$this->assertIsCallable( $salida['permission_callback'], "«{$nombre}» debe seguir negando la ejecución." );
		$this->assertFalse( ( $salida['permission_callback'] )(), "«{$nombre}» sigue teniendo permiso de ejecución." );
		$this->assertFalse( $salida['meta']['mcp']['public'], "«{$nombre}» sigue publicada por MCP." );
	}

	/**
	 * @testdox REAPERTURA S-31b76a50: un intento de ejecutar una ability vetada deja rastro.
	 */
	public function test_un_intento_vetado_deja_rastro(): void {
		if ( ! function_exists( 'akibara_abilities_audit_anota' ) ) {
			require_once dirname( __DIR__, 4 ) . '/mu-plugins/akibara-abilities-audit.php';
		}

		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'get_userdata' )->justReturn( (object) array( 'user_login' => 'ale' ) );

		$anotado = array();
		$logger  = new class( $anotado ) {
			private array $destino;
			public function __construct( array &$destino ) {
				$this->destino = &$destino;
			}
			public function info( string $mensaje, array $contexto = array() ): void {
				$this->destino[] = $mensaje;
			}
		};
		Functions\when( 'wc_get_logger' )->justReturn( $logger );

		$salida  = ( self::$veto )( $this->argumentos_de_registro(), 'woocommerce/product-delete' );
		$negado  = ( $salida['permission_callback'] )();

		$this->assertFalse( $negado, 'debe seguir negando la ejecución además de anotar' );
		$this->assertCount( 1, $anotado, 'un intento vetado tiene que dejar UNA línea de rastro' );
		$this->assertStringContainsString( 'woocommerce/product-delete', $anotado[0] );
		$this->assertStringContainsString( '#7 ale', $anotado[0] );
		$this->assertStringContainsString( 'VETADA', $anotado[0] );
	}

	/**
	 * @testdox Lo que no está vetado pasa intacto.
	 * @dataProvider abilities_permitidas
	 */
	public function test_lo_permitido_pasa_intacto( string $nombre ): void {
		$entrada = $this->argumentos_de_registro();
		$salida  = ( self::$veto )( $entrada, $nombre );

		$this->assertSame( $entrada, $salida, "«{$nombre}» no debía tocarse: el veto es una lista cerrada." );
	}

	/** @testdox Un registro sin metadatos de MCP no revienta. */
	public function test_un_registro_sin_meta_mcp_no_revienta(): void {
		$salida = ( self::$veto )( array( 'label' => 'Sin meta' ), 'woocommerce/product-delete' );

		$this->assertIsCallable( $salida['permission_callback'] );
		$this->assertFalse( ( $salida['permission_callback'] )() );
		$this->assertArrayNotHasKey( 'meta', $salida, 'No hay que inventar metadatos que el tercero no declaró.' );
	}

	/** @testdox Un valor que no es array se devuelve tal cual. */
	public function test_un_valor_que_no_es_array_se_devuelve_tal_cual(): void {
		$this->assertNull( ( self::$veto )( null, 'woocommerce/product-delete' ) );
	}

	/** @return array<string,array{0:string}> */
	public static function abilities_vetadas(): array {
		return array(
			'auto-updates de Rank Math'      => array( 'rank-math/set-plugin-preferences' ),
			'apagar módulos de Rank Math'    => array( 'rank-math/set-module-status' ),
			'borrar un producto (WooCommerce)' => array( 'woocommerce/product-delete' ),
		);
	}

	/** @return array<string,array{0:string}> */
	public static function abilities_permitidas(): array {
		return array(
			'crear producto'          => array( 'woocommerce/product-create' ),
			'editar producto'         => array( 'woocommerce/product-update' ),
			'consultar productos'     => array( 'woocommerce/products-query' ),
			'consultar pedidos'       => array( 'woocommerce/orders-query' ),
			'cambiar estado de pedido' => array( 'woocommerce/order-update-status' ),
			'leer ajustes de Rank Math' => array( 'rank-math/get-settings' ),
		);
	}
}
