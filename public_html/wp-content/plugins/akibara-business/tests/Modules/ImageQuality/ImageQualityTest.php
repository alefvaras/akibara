<?php
/**
 * La calidad AVIF: una sola noción de validez para guardar y leer, y un filtro
 * que sólo toca AVIF.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\ImageQuality;

use Akibara\Business\Modules\AdminPanel\AdminPanel;
use Akibara\Business\Modules\ImageQuality\ImageQuality;
use Akibara\Business\Modules\ImageQuality\Recompressor;
use Akibara\Business\Tests\Support\OptionsRowDouble;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class ImageQualityTest extends TestCase {

	use OptionsRowDouble;

	/** @var array<string, mixed> */
	private array $options = array();


	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->options = array();
		Functions\when( 'get_option' )->alias( fn( string $name, $default = false ) => $this->options[ $name ] ?? $default );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'number_format_i18n' )->alias( static fn( $n, $d = 0 ) => number_format( (float) $n, (int) $d, ',', '.' ) );
		Functions\when( 'wp_date' )->alias( static fn( string $f, int $t ) => gmdate( 'd/m/Y H:i', $t ) );

		// El botón llega hasta el candado de la recompresión (SQL sobre la fila).
		$this->instalar_doble_de_options();
	}

	protected function tearDown(): void {
		$_POST = array();
		$this->retirar_doble_de_options();
		Monkey\tearDown();
		parent::tearDown();
	}

	private function module(): ImageQuality {
		return new ImageQuality( new AdminPanel(), new Recompressor() );
	}

	/** @return array<string, array{0: mixed, 1: ?int}> */
	public static function values(): array {
		return array(
			'entero en rango'      => array( 60, 60 ),
			'texto en rango'       => array( '55', 55 ),
			'con espacios'         => array( ' 40 ', 40 ),
			'borde superior'       => array( '90', 90 ),
			'bajo el mínimo'       => array( '39', null ),
			'sobre el máximo'      => array( 91, null ),
			'decimal'              => array( '60.5', null ),
			'con porcentaje'       => array( '60%', null ),
			'vacío'                => array( '', null ),
			'negativo'             => array( '-60', null ),
			'nulo'                 => array( null, null ),
			'flotante'             => array( 60.0, null ),
		);
	}

	/**
	 * @dataProvider values
	 * @testdox Validez de la calidad: $valor → $esperado
	 *
	 * @param mixed    $valor    Lo enviado o guardado.
	 * @param int|null $esperado Calidad o null.
	 */
	public function test_quality_has_one_notion_of_validity( $valor, ?int $esperado ): void {
		self::assertSame( $esperado, ImageQuality::parse_quality( $valor ) );
	}

	/**
	 * @testdox Sin fila o con una fila inválida rige 60; con una válida, la guardada.
	 */
	public function test_current_quality_falls_back_to_the_owner_choice(): void {
		self::assertSame( 60, ImageQuality::current_quality() );

		$this->options[ ImageQuality::OPTION_QUALITY ] = 'abc';
		self::assertSame( 60, ImageQuality::current_quality() );

		$this->options[ ImageQuality::OPTION_QUALITY ] = '50';
		self::assertSame( 50, ImageQuality::current_quality() );
	}

	/**
	 * @testdox El filtro fija la calidad de AVIF y deja intactos JPEG y WebP.
	 */
	public function test_filter_only_touches_avif(): void {
		$this->options[ ImageQuality::OPTION_QUALITY ] = '55';
		$modulo = $this->module();

		self::assertSame( 55, $modulo->filter_quality( 82, 'image/avif' ) );
		self::assertSame( 82, $modulo->filter_quality( 82, 'image/jpeg' ) );
		self::assertSame( 86, $modulo->filter_quality( 86, 'image/webp' ) );
	}

	/**
	 * @testdox Una calidad inválida detiene el guardado de su pestaña, y sólo de ella.
	 */
	public function test_invalid_quality_blocks_only_its_tab(): void {
		$modulo = $this->module();

		$_POST[ ImageQuality::OPTION_QUALITY ] = '30';
		$errores = $modulo->validate_save( array(), ImageQuality::PANEL_TAB_ID );
		self::assertCount( 1, $errores );
		self::assertStringContainsString( 'entre 40 y 90', $errores[0] );

		self::assertSame( array(), $modulo->validate_save( array(), 'branding' ), 'Otra pestaña no mira este campo.' );

		$_POST[ ImageQuality::OPTION_QUALITY ] = '60';
		self::assertSame( array(), $modulo->validate_save( array(), ImageQuality::PANEL_TAB_ID ) );

		unset( $_POST[ ImageQuality::OPTION_QUALITY ] );
		self::assertSame( array(), $modulo->validate_save( array(), ImageQuality::PANEL_TAB_ID ) );
	}

	/**
	 * @testdox Lo que se escribe es la calidad normalizada, nunca un valor inválido.
	 */
	public function test_saved_value_is_always_valid(): void {
		self::assertSame( '45', ImageQuality::sanitize_for_save( ' 45 ' ) );

		$this->options[ ImageQuality::OPTION_QUALITY ] = '70';
		self::assertSame( '70', ImageQuality::sanitize_for_save( '9000' ) );
		self::assertSame( '70', ImageQuality::sanitize_for_save( null ), 'Campo ausente: queda la vigente.' );
	}

	/**
	 * @testdox El campo del panel declara el rango y el valor por defecto que valida el código.
	 */
	public function test_panel_field_matches_the_validator(): void {
		$campos = array_column( $this->module()->get_settings(), null, 'id' );
		$campo  = $campos[ ImageQuality::OPTION_QUALITY ];

		self::assertSame( 'number', $campo['type'] );
		self::assertSame( '60', $campo['default'] );
		self::assertSame( ImageQuality::MIN_QUALITY, $campo['custom_attributes']['min'] );
		self::assertSame( ImageQuality::MAX_QUALITY, $campo['custom_attributes']['max'] );
	}

	/**
	 * @testdox El estado se cuenta en palabras: sin empezar, en curso, detenido y terminado con su purga.
	 */
	public function test_state_is_described_for_the_owner(): void {
		$estado = Recompressor::state();
		self::assertSame( 'Todavía no se ha recomprimido nada.', ImageQuality::describe_state( $estado ) );

		$estado = array_merge(
			$estado,
			array(
				'quality'       => 60,
				'started'       => 1000,
				'total'         => 1604,
				'revisadas'     => 400,
				'reemplazados'  => 900,
				'bytes_antes'   => 200 * 1048576,
				'bytes_despues' => 120 * 1048576,
				'errores'       => 2,
				'omitidas'      => 3,
			)
		);
		$en_curso = ImageQuality::describe_state( $estado );
		self::assertStringStartsWith( 'Recompresión en curso con calidad 60', $en_curso );
		self::assertStringContainsString( '400 de 1.604 portadas', $en_curso );
		self::assertStringContainsString( '80,0 MB menos', $en_curso );
		self::assertStringContainsString( '3 portadas se dejaron como estaban', $en_curso );
		self::assertStringContainsString( '2 tamaños no se pudieron revisar', $en_curso );

		$detenida = ImageQuality::describe_state( $estado, true );
		self::assertStringStartsWith( 'La recompresión con calidad 60 se detuvo sin terminar', $detenida );
		self::assertStringContainsString( 'Pulsa el botón para retomarla: con la misma calidad sigue donde quedó.', $detenida );

		$estado['finished']       = 5000;
		$estado['purga_agendada'] = 9000;
		$terminada                = ImageQuality::describe_state( $estado );
		self::assertStringStartsWith( 'Última recompresión, terminada el', $terminada );
		self::assertStringContainsString( 'se vacían el', $terminada );

		$estado['purga_agendada'] = 0;
		$estado['purgada']        = 9500;
		self::assertStringContainsString( 'se vaciaron el', ImageQuality::describe_state( $estado ) );
	}

	/**
	 * El botón lanza una tarea que toca 1.600 portadas: sin capacidad ni nonce,
	 * cualquiera con un enlace la dispararía (revisión independiente, 2026-09-15).
	 *
	 * @testdox El botón exige permiso y nonce, y devuelve al dueño a su pestaña con el resultado.
	 */
	public function test_the_button_requires_capability_and_nonce(): void {
		$pasos = array();
		Functions\when( 'current_user_can' )->alias(
			static function ( string $cap ) use ( &$pasos ): bool {
				$pasos[] = 'cap:' . $cap;
				return false;
			}
		);
		Functions\when( 'wp_die' )->alias(
			static function ( string $mensaje ) use ( &$pasos ): void {
				$pasos[] = 'die';
				throw new \RuntimeException( $mensaje );
			}
		);
		Functions\expect( 'check_admin_referer' )->never();

		try {
			$this->module()->handle_recompress();
			self::fail( 'Sin permisos tiene que morir antes de tocar nada.' );
		} catch ( \RuntimeException $e ) {
			self::assertStringContainsString( 'Sin permisos', $e->getMessage() );
		}
		self::assertSame( array( 'cap:' . AdminPanel::PANEL_CAP, 'die' ), $pasos );
	}

	/**
	 * @testdox Con permiso y nonce, el botón encola y redirige a la pestaña Imágenes con la bandera del resultado.
	 */
	public function test_the_button_enqueues_and_redirects_with_the_flag(): void {
		$destino = '';
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_admin_referer' )->justReturn( true );
		Functions\when( 'admin_url' )->alias( static fn( string $ruta ) => 'https://akibara.cl/wp-admin/' . $ruta );
		Functions\when( 'add_query_arg' )->alias(
			static function ( array $args, string $url ): string {
				return $url . '?' . http_build_query( $args );
			}
		);
		Functions\when( 'wp_safe_redirect' )->alias(
			static function ( string $url ) use ( &$destino ): void {
				$destino = $url;
				throw new \RuntimeException( 'redirigido' );
			}
		);
		// La cola y el guardado del estado, explícitos: sin ellos el resultado dependía
		// de qué otra clase hubiera definido antes estas funciones (con
		// `function_exists()` contagiado daba «sin-cola» o reventaba en `update_option`).
		$encoladas = array();
		Functions\when( 'update_option' )->alias(
			function ( string $nombre, $valor ): bool {
				$this->options[ $nombre ] = $valor;
				return true;
			}
		);
		Functions\when( 'as_get_scheduled_actions' )->justReturn( array() );
		Functions\when( 'as_schedule_single_action' )->alias(
			static function ( int $cuando, string $hook ) use ( &$encoladas ): int {
				$encoladas[] = $hook;
				return count( $encoladas );
			}
		);

		try {
			$this->module()->handle_recompress();
			self::fail( 'El redirect corta la petición.' );
		} catch ( \RuntimeException $e ) {
			unset( $e );
		}

		self::assertStringContainsString( 'page=akibara-panel', $destino );
		self::assertStringContainsString( 'tab=' . ImageQuality::PANEL_TAB_ID, $destino );
		self::assertStringContainsString( 'akb_recompresion=encolada', $destino, 'Con permiso, nonce y cola disponible, el botón encola.' );
		self::assertSame( array( Recompressor::HOOK ), $encoladas, 'Una sola corrida encolada.' );
		self::assertSame( 60, Recompressor::state()['quality'], 'La pasada queda con la calidad vigente.' );
	}

	/**
	 * `start()` devuelve tres cadenas y el panel las traduce con un `match`. Si
	 * alguien añade un cuarto valor, el dueño pulsa el botón y no ve ningún aviso.
	 *
	 * @testdox Los tres resultados de la recompresión tienen aviso en el panel.
	 */
	public function test_every_start_result_has_a_notice(): void {
		$fuente = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Modules/ImageQuality/ImageQuality.php' );
		$recompressor = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Modules/ImageQuality/Recompressor.php' );
		$sin_comentarios = static fn( string $php ): string => (string) preg_replace( '#/\*[\s\S]*?\*/|//[^\n]*#', '', $php );

		preg_match_all( "/return '([a-z-]+)';/", $sin_comentarios( $recompressor ), $devueltos );
		$resultados = array_values( array_unique( array_filter( $devueltos[1], static fn( string $v ): bool => in_array( $v, array( 'encolada', 'ya-en-curso', 'sin-cola' ), true ) ) ) );
		self::assertCount( 3, $resultados, 'start() sigue devolviendo exactamente estos tres.' );

		foreach ( $resultados as $resultado ) {
			self::assertMatchesRegularExpression(
				"/'" . preg_quote( $resultado, '/' ) . "'\s*=>\s*array\(/",
				$sin_comentarios( $fuente ),
				"El panel no traduce «{$resultado}»: el dueño pulsaría el botón sin ver aviso."
			);
		}
	}
}
