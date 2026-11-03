<?php
/**
 * Los ajustes de «Recomendaciones»: el guardado nunca es más permisivo que la
 * lectura, y un valor raro no deja la ficha sin recomendaciones.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\Recommendations;

use Akibara\Business\Modules\Recommendations\Settings;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase {

	/** @var array<string, mixed> */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->options = array();
		Functions\when( 'get_option' )->alias(
			fn( string $name, $default = false ) => array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $default
		);
		Functions\when( 'wp_unslash' )->returnArg( 1 );
	}

	protected function tearDown(): void {
		unset( $_POST[ Settings::OPTION_RELATED ], $_POST[ Settings::OPTION_SERIE_AGOTADA ], $_POST[ Settings::OPTION_MAS_VENDIDOS ], $_POST[ Settings::OPTION_MAS_VENDIDOS_BUSQUEDA ] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox Sin fila, cada ajuste vale el de fábrica: relacionados y más vendidos con la regla nueva, la serie agotada como siempre.
	 */
	public function test_valores_de_fabrica(): void {
		self::assertSame( Settings::RELATED_OTRA_SERIE, Settings::current( Settings::OPTION_RELATED ) );
		self::assertSame( Settings::AGOTADA_MOSTRAR, Settings::current( Settings::OPTION_SERIE_AGOTADA ) );
		self::assertSame( Settings::MAS_VENDIDOS_UNA_POR_SERIE, Settings::current( Settings::OPTION_MAS_VENDIDOS ) );
	}

	/**
	 * @testdox Un valor escrito a mano que no existe se lee como el de fábrica.
	 */
	public function test_valor_raro_cae_al_de_fabrica(): void {
		$this->options[ Settings::OPTION_RELATED ]       = 'OTRA_SERIE';
		$this->options[ Settings::OPTION_SERIE_AGOTADA ] = array( 'ocultar' );
		$this->options[ Settings::OPTION_MAS_VENDIDOS ]  = '';

		self::assertSame( Settings::RELATED_OTRA_SERIE, Settings::current( Settings::OPTION_RELATED ) );
		self::assertSame( Settings::AGOTADA_MOSTRAR, Settings::current( Settings::OPTION_SERIE_AGOTADA ) );
		self::assertSame( Settings::MAS_VENDIDOS_UNA_POR_SERIE, Settings::current( Settings::OPTION_MAS_VENDIDOS ) );
	}

	/**
	 * @testdox Un valor guardado válido se respeta.
	 */
	public function test_valor_valido_se_respeta(): void {
		$this->options[ Settings::OPTION_SERIE_AGOTADA ] = Settings::AGOTADA_OCULTAR;

		self::assertSame( Settings::AGOTADA_OCULTAR, Settings::current( Settings::OPTION_SERIE_AGOTADA ) );
	}

	/**
	 * @testdox El guardado escribe lo enviado si es válido y, si no, deja lo vigente.
	 */
	public function test_guardado_no_mas_permisivo_que_la_lectura(): void {
		$this->options[ Settings::OPTION_RELATED ] = Settings::RELATED_COMPRABLES;
		$campo                                   = array( 'id' => Settings::OPTION_RELATED );

		self::assertSame( Settings::RELATED_OTRA_SERIE, Settings::sanitize_for_save( Settings::RELATED_OTRA_SERIE, $campo ) );
		self::assertSame( Settings::RELATED_COMPRABLES, Settings::sanitize_for_save( '<script>', $campo ), 'Lo inventado no se escribe.' );
	}

	/**
	 * @testdox Un envío fabricado con un valor que no existe tira la pestaña entera con un aviso.
	 */
	public function test_envio_fabricado_se_rechaza(): void {
		$_POST[ Settings::OPTION_RELATED ]      = Settings::RELATED_COMPRABLES;
		$_POST[ Settings::OPTION_MAS_VENDIDOS ] = 'todos';

		$errores = Settings::validate_save( array(), Settings::PANEL_TAB_ID );

		self::assertCount( 1, $errores );
		self::assertSame( array(), Settings::validate_save( array(), 'otra_pestana' ), 'Otras pestañas no se miran.' );
	}

	/**
	 * @testdox El panel ofrece exactamente los valores que la lectura acepta, y el de fábrica marcado.
	 */
	public function test_el_panel_ofrece_lo_que_la_lectura_acepta(): void {
		$campos = array();
		foreach ( Settings::get_settings() as $campo ) {
			if ( 'radio' === ( $campo['type'] ?? '' ) ) {
				$campos[ $campo['id'] ] = $campo;
			}
		}

		self::assertSame( array( Settings::OPTION_RELATED, Settings::OPTION_SERIE_AGOTADA, Settings::OPTION_MAS_VENDIDOS ), array_keys( $campos ) );
		foreach ( $campos as $id => $campo ) {
			self::assertSame( Settings::DEFAULTS[ $id ], $campo['default'] );
			foreach ( array_keys( $campo['options'] ) as $valor ) {
				self::assertSame( $valor, Settings::parse( $id, $valor ), "«{$valor}» se ofrece y la lectura lo acepta." );
			}
			self::assertNull( Settings::parse( $id, 'inventado' ) );
		}
	}

	/**
	 * QA-DIFF-TEMA-5: «Lo más vendido» dentro del aviso de «sin resultados» era
	 * una superficie comercial nueva sin interruptor. Nace encendida (es el
	 * objetivo de UC-1) y sólo la apaga un 'no' guardado.
	 *
	 * @testdox «Lo más vendido» en la búsqueda sin resultados: encendido sin fila y con un valor raro; sólo 'no' lo apaga.
	 */
	public function test_mas_vendidos_en_la_busqueda_vacia_de_fabrica_encendido(): void {
		self::assertTrue( Settings::mas_vendidos_en_busqueda(), 'Sin fila: encendido.' );

		$this->options[ Settings::OPTION_MAS_VENDIDOS_BUSQUEDA ] = 'no';
		self::assertFalse( Settings::mas_vendidos_en_busqueda(), 'Apagado desde el panel.' );
		self::assertFalse( Settings::mas_vendidos_en_busqueda( true ), 'Lo que proponga el tema no manda: manda el ajuste.' );

		$this->options[ Settings::OPTION_MAS_VENDIDOS_BUSQUEDA ] = 'yes';
		self::assertTrue( Settings::mas_vendidos_en_busqueda( false ) );

		foreach ( array( '', '0', 'false', 'NO', array( 'no' ), false ) as $raro ) {
			$this->options[ Settings::OPTION_MAS_VENDIDOS_BUSQUEDA ] = $raro;
			self::assertTrue( Settings::mas_vendidos_en_busqueda(), 'Un valor escrito a mano que no existe cae al de fábrica: ' . var_export( $raro, true ) );
		}
	}

	/**
	 * @testdox La casilla se guarda con la misma lectura: 'yes' y 'no' entran, lo demás deja lo vigente.
	 */
	public function test_la_casilla_se_guarda_con_la_misma_lectura(): void {
		$campo = array( 'id' => Settings::OPTION_MAS_VENDIDOS_BUSQUEDA );

		self::assertSame( 'no', Settings::sanitize_for_save( 'no', $campo ) );
		self::assertSame( 'yes', Settings::sanitize_for_save( 'yes', $campo ) );

		$this->options[ Settings::OPTION_MAS_VENDIDOS_BUSQUEDA ] = 'no';
		self::assertSame( 'no', Settings::sanitize_for_save( '1', $campo ), 'Lo que no es un valor de la casilla no se escribe.' );
	}

	/**
	 * El navegador manda '1' con la casilla marcada (el `value` que pinta
	 * WooCommerce); tratarlo como «valor que no existe» tiraba la pestaña entera
	 * con cada guardado que la dejara encendida.
	 *
	 * @testdox Guardar con la casilla marcada no tira la pestaña; un radio inventado sí.
	 */
	public function test_la_casilla_marcada_no_se_toma_por_envio_fabricado(): void {
		$_POST[ Settings::OPTION_RELATED ]              = Settings::RELATED_COMPRABLES;
		$_POST[ Settings::OPTION_MAS_VENDIDOS_BUSQUEDA ] = '1';

		self::assertSame( array(), Settings::validate_save( array(), Settings::PANEL_TAB_ID ) );

		$_POST[ Settings::OPTION_SERIE_AGOTADA ] = 'inventado';
		self::assertCount( 1, Settings::validate_save( array(), Settings::PANEL_TAB_ID ) );
	}

	/**
	 * @testdox El panel ofrece la casilla con su texto, marcada de fábrica, justo después de los «Más vendidos».
	 */
	public function test_el_panel_ofrece_la_casilla(): void {
		$ids    = array_column( Settings::get_settings(), 'id' );
		$campos = array_column( Settings::get_settings(), null, 'id' );
		$campo  = $campos[ Settings::OPTION_MAS_VENDIDOS_BUSQUEDA ] ?? array();

		self::assertSame( 'checkbox', $campo['type'] ?? null );
		self::assertSame( 'Mostrar «Lo más vendido» cuando una búsqueda no encuentra nada', $campo['desc'] ?? null );
		self::assertSame( Settings::DEFAULTS[ Settings::OPTION_MAS_VENDIDOS_BUSQUEDA ], $campo['default'] ?? null );
		self::assertSame( 'yes', Settings::parse( Settings::OPTION_MAS_VENDIDOS_BUSQUEDA, 'yes' ) );
		self::assertSame( 'no', Settings::parse( Settings::OPTION_MAS_VENDIDOS_BUSQUEDA, 'no' ) );

		$posicion = array_search( Settings::OPTION_MAS_VENDIDOS, $ids, true );
		self::assertSame( Settings::OPTION_MAS_VENDIDOS_BUSQUEDA, $ids[ $posicion + 1 ] ?? null );
	}

	/**
	 * El tema pregunta con un filtro y no conoce la clase: si el nombre cambia de
	 * un lado, la casilla deja de mandar sin que nada falle.
	 *
	 * @testdox El módulo responde el filtro que aplica el tema, con el mismo nombre en los dos lados.
	 */
	public function test_responde_el_filtro_que_aplica_el_tema(): void {
		( new Settings() )->register();

		self::assertSame( 'akibara_show_popular_in_empty_search', Settings::FILTER_EN_BUSQUEDA );
		self::assertNotFalse( has_filter( Settings::FILTER_EN_BUSQUEDA, array( Settings::class, 'mas_vendidos_en_busqueda' ) ) );

		$tema = dirname( __DIR__, 5 ) . '/themes/akibara-gp/inc/woocommerce.php';
		if ( ! is_readable( $tema ) ) {
			return;
		}
		self::assertStringContainsString( "apply_filters( '" . Settings::FILTER_EN_BUSQUEDA . "', true )", (string) file_get_contents( $tema ) );
	}

	/**
	 * QA integral 2026-09-24 (QA-PANEL-8): los tres ajustes quedaban al final de
	 * la herramienta de relleno sin título propio. El título se pinta al final del
	 * encabezado de la pestaña, justo encima del formulario.
	 *
	 * @testdox Los ajustes llevan su propio título, pintado tras la herramienta de relleno y antes del formulario.
	 */
	public function test_los_ajustes_llevan_titulo_propio(): void {
		ob_start();
		Settings::render_heading();
		self::assertSame( '<h3 class="akb-reco-settings-heading">Qué le recomienda la tienda al cliente</h3>', (string) ob_get_clean() );

		$plugin = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Plugin.php' );
		self::assertMatchesRegularExpression(
			'/RecommendationSettings::PANEL_TAB_ID,\s*\'Recomendaciones\',\s*array\( RecommendationSettings::class, \'get_settings\' \),\s*static function \(\) use \( \$upsells_autofill \): void \{\s*\$upsells_autofill->render_admin_section\(\);\s*RecommendationSettings::render_heading\(\);/',
			$plugin,
			'El encabezado de la pestaña termina con el título de los ajustes.'
		);
	}
}
