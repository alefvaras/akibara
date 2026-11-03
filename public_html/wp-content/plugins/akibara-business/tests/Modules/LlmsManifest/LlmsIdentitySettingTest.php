<?php
/**
 * Candado de la identidad que llms.txt le declara a los agentes de IA.
 *
 * Dos trampas que este archivo fija:
 *
 *   1. La pestaña «Salud SEO» nació como ACTION SECTION (sin formulario). Con
 *      `is_action = true`, `AdminPanel::handle_save()` vuelve temprano: el campo
 *      se pinta, el dueño escribe, aprieta «Guardar» y no pasa NADA.
 *   2. El texto viaja al bloque `>` del manifiesto. Sanearlo con el normalizador
 *      de enlaces habría escapado los paréntesis de «Flow (Webpay)» y cambiado
 *      el archivo servido el mismo día que se estrenó la pantalla.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\LlmsManifest;

use Akibara\Business\Modules\LlmsManifest\LlmsManifest;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/** Panel mínimo que registra lo que le manden. */
final class LlmsIdentityPanelSpy {

	/** @var array<string, array<string, mixed>> */
	public array $secciones = array();

	/**
	 * @param callable      $settings_fn Callback de ajustes.
	 * @param callable|null $header_fn   Callback del encabezado.
	 */
	public function add_section( string $id, string $label, $settings_fn, $header_fn = null, bool $is_action = false, string $cap = '', string $group = '' ): void {
		unset( $cap );
		$this->secciones[ $id ] = array(
			'label'       => $label,
			'settings_fn' => $settings_fn,
			'header_fn'   => $header_fn,
			'is_action'   => $is_action,
			'group'       => $group,
		);
	}
}

/** Plugin mínimo que sólo sabe entregar el panel. */
final class LlmsIdentityPluginSpy {

	public LlmsIdentityPanelSpy $panel;

	public function __construct( LlmsIdentityPanelSpy $panel ) {
		$this->panel = $panel;
	}

	/**
	 * @return LlmsIdentityPanelSpy|null
	 */
	public function module( string $id ) {
		return 'admin_panel' === $id ? $this->panel : null;
	}
}

final class LlmsIdentitySettingTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['akibara_test_options'] = array();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['akibara_test_options'] );
		parent::tearDown();
	}

	/**
	 * @testdox Sin nada guardado, llms.txt declara la identidad de siempre — paréntesis incluidos
	 */
	public function test_the_default_identity_is_served_verbatim(): void {
		$texto = LlmsManifest::render( array(), array(), false );
		$lines = explode( "\n", $texto );

		self::assertSame(
			'> Tienda online chilena especializada en manga y cómics originales en español, con tomos disponibles, a pedido e importación. Despacho a todo Chile y retiro coordinado en Metro San Miguel, Santiago. Pagos con Mercado Pago y Flow (Webpay).',
			$lines[2]
		);
		self::assertStringNotContainsString( '\\(Webpay\\)', $texto, 'El resumen es prosa: escapar sus paréntesis cambiaría el archivo servido.' );
	}

	/**
	 * @testdox La identidad guardada es la que leen los agentes
	 */
	public function test_a_saved_identity_reaches_the_manifest(): void {
		$GLOBALS['akibara_test_options'][ LlmsManifest::OPTION_IDENTITY ] = 'Librería chilena de manga con retiro en Providencia.';

		$lines = explode( "\n", LlmsManifest::render( array(), array(), false ) );

		self::assertSame( '> Librería chilena de manga con retiro en Providencia.', $lines[2] );
	}

	/**
	 * @testdox Una identidad en blanco vuelve a la de fábrica: el formato exige el resumen
	 */
	public function test_a_blank_identity_falls_back(): void {
		$GLOBALS['akibara_test_options'][ LlmsManifest::OPTION_IDENTITY ] = "  \n ";

		self::assertSame( LlmsManifest::DEFAULT_IDENTITY, LlmsManifest::identity() );
	}

	/**
	 * @testdox Los saltos de línea se aplanan al guardar: el blockquote es UNA línea
	 */
	public function test_saving_flattens_the_text_to_one_line(): void {
		$modulo = new LlmsManifest();

		$guardado = $modulo->sanitize_identity(
			'',
			array(),
			"Tienda de manga.\n\nRetiro en San Miguel.\t Pagos con Flow (Webpay)."
		);

		self::assertSame( 'Tienda de manga. Retiro en San Miguel. Pagos con Flow (Webpay).', $guardado );
		self::assertSame( LlmsManifest::DEFAULT_IDENTITY, $modulo->sanitize_identity( '', array(), '   ' ) );
		self::assertSame( LlmsManifest::DEFAULT_IDENTITY, $modulo->sanitize_identity( '', array(), array( 'x' ) ) );
	}

	/**
	 * @testdox Salud SEO es una sección CON formulario, o el campo no se guardaría
	 */
	public function test_the_tab_is_no_longer_an_action_section(): void {
		$panel = new LlmsIdentityPanelSpy();
		( new LlmsManifest() )->register_admin_section( new LlmsIdentityPluginSpy( $panel ) );

		$seccion = $panel->secciones[ LlmsManifest::PANEL_TAB_ID ];

		self::assertFalse( $seccion['is_action'], 'Con is_action=true el panel no guarda NADA de esta pestaña.' );
		self::assertIsCallable( $seccion['header_fn'], 'El estado y los botones siguen siendo el encabezado.' );

		$por_id = array();
		foreach ( ( $seccion['settings_fn'] )() as $campo ) {
			if ( isset( $campo['id'] ) ) {
				$por_id[ $campo['id'] ] = $campo;
			}
		}

		self::assertArrayHasKey( LlmsManifest::OPTION_IDENTITY, $por_id );
		self::assertSame( 'textarea', $por_id[ LlmsManifest::OPTION_IDENTITY ]['type'] );
		self::assertSame( LlmsManifest::DEFAULT_IDENTITY, $por_id[ LlmsManifest::OPTION_IDENTITY ]['default'] );
		self::assertFalse( $por_id[ LlmsManifest::OPTION_IDENTITY ]['autoload'] ?? true, 'SRV-4: guardada desde el panel, fuera de alloptions.' );
	}

	/**
	 * @testdox La identidad de fábrica anterior, guardada por el panel, pasa a la vigente al actualizar el plugin; la escrita por el dueño no se toca.
	 */
	public function test_la_identidad_de_fabrica_anterior_se_actualiza(): void {
		$anterior = 'Tienda online chilena especializada en manga y cómics originales en español, con productos disponibles, preventas e importación. Despacho a todo Chile y retiro coordinado en Metro San Miguel, Santiago. Pagos con Mercado Pago y Flow (Webpay).';
		Monkey\setUp();
		try {
			$escritos = array();
			Functions\when( 'update_option' )->alias(
				static function ( string $option, $value, $autoload = null ) use ( &$escritos ): bool {
					self::assertFalse( $autoload, 'SRV-4: la migración escribe fuera de alloptions.' );
					$escritos[]                                  = array( $option, $value );
					$GLOBALS['akibara_test_options'][ $option ] = $value;
					return true;
				}
			);

			// Lo que hay en producción (leído el 2026-09-23): la de antes, tal cual.
			$GLOBALS['akibara_test_options'][ LlmsManifest::OPTION_IDENTITY ] = $anterior;
			self::assertTrue( LlmsManifest::migrate_factory_identity() );
			self::assertSame( array( array( LlmsManifest::OPTION_IDENTITY, LlmsManifest::DEFAULT_IDENTITY ) ), $escritos );
			self::assertStringNotContainsString( 'preventas', LlmsManifest::identity() );

			// Idempotente: ya al día, no escribe.
			self::assertFalse( LlmsManifest::migrate_factory_identity() );

			// Con espacios o saltos de línea de más sigue siendo la de fábrica.
			$GLOBALS['akibara_test_options'][ LlmsManifest::OPTION_IDENTITY ] = "  {$anterior}\n";
			self::assertTrue( LlmsManifest::migrate_factory_identity() );

			// Lo que escribió el dueño, aunque diga «preventas», es suyo.
			$escritos = array();
			$GLOBALS['akibara_test_options'][ LlmsManifest::OPTION_IDENTITY ] = 'Librería con preventas en Providencia.';
			self::assertFalse( LlmsManifest::migrate_factory_identity() );
			// Y sin fila no hay nada que migrar: rige la de fábrica.
			unset( $GLOBALS['akibara_test_options'][ LlmsManifest::OPTION_IDENTITY ] );
			self::assertFalse( LlmsManifest::migrate_factory_identity() );
			self::assertSame( array(), $escritos );
		} finally {
			Monkey\tearDown();
		}
	}

	/**
	 * @testdox La migración corre al actualizar el plugin, el mismo momento que tira las demás cachés.
	 */
	public function test_la_migracion_se_engancha_a_la_actualizacion(): void {
		$fuente = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Modules/LlmsManifest/LlmsManifest.php' );

		self::assertStringContainsString( "add_action( 'akibara_business_upgraded', array( self::class, 'migrate_factory_identity' ), 10, 0 );", $fuente );
		self::assertStringContainsString( "add_action( 'akibara_business_upgraded', array( self::class, 'drop_identity_autoload' ), 10, 0 );", $fuente );
	}

	/**
	 * SRV-4 (verificación de producción 2026-09-26): en producción la fila viaja
	 * con autoload = on y sólo la leen la cola de llms.txt y el panel.
	 *
	 * @testdox Al actualizar el plugin, la identidad sale de alloptions; si ya estaba fuera, no escribe.
	 */
	public function test_al_actualizar_la_identidad_sale_de_alloptions(): void {
		Monkey\setUp();
		try {
			$llamadas = array();
			$fuera    = false;
			Functions\when( 'wp_set_option_autoload' )->alias(
				static function ( string $option, $autoload ) use ( &$llamadas, &$fuera ): bool {
					$llamadas[] = array( $option, $autoload );
					// Como el núcleo: sólo devuelve true cuando cambió algo.
					$cambia = ! $fuera;
					$fuera  = true;
					return $cambia;
				}
			);

			self::assertTrue( LlmsManifest::drop_identity_autoload() );
			self::assertFalse( LlmsManifest::drop_identity_autoload(), 'Idempotente.' );
			self::assertSame( array( array( LlmsManifest::OPTION_IDENTITY, false ), array( LlmsManifest::OPTION_IDENTITY, false ) ), $llamadas );
		} finally {
			Monkey\tearDown();
		}
	}
}
