<?php
/**
 * El autocompletar de direcciones pide su clave al catálogo de accesos.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\AddressAutocomplete;

use Akibara\Business\Modules\AddressAutocomplete\AddressAutocomplete;
use Akibara\Business\Tests\Support\CatalogoDeAccesos;
use Akibara\ProviderAccess\Access;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class AddressAutocompleteTest extends TestCase {

	/** Una clave que el catálogo acepta: `AIza` y 35 caracteres más. */
	private const VALIDA = 'AIza' . 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

	/** 38 caracteres: lo que queda al pegar una clave a medias. */
	private const TRUNCADA = 'AIza' . 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// El catálogo NO se carga aquí: una de las pruebas mide justo el caso de
		// que el mu-plugin no esté, y cargarlo en `setUp()` se lo llevaría por
		// delante. Cada prueba que lo necesita lo pide.
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'admin_url' )->alias( static fn( string $ruta = '' ): string => 'https://akibara.cl/wp-admin/' . $ruta );
		Functions\when( 'current_user_can' )->justReturn( true );
		// N-orden-suite (2026-09-19): `get_current_screen` no lo stubea NINGUNA
		// prueba de este fichero, pero `MarcaTermFieldsTest` sí lo hace, y Brain
		// Monkey/Patchwork deja la función redefinida para el resto del PROCESO.
		// Con `--order-by=reverse` esa prueba corre ANTES que ésta y
		// `function_exists( 'get_current_screen' )` miente (true por contagio):
		// sin este stub propio, `maybe_render_misconfig_notice()` la llama sin
		// expectativa y Brain Monkey revienta con MissingFunctionExpectations.
		Functions\when( 'get_current_screen' )->justReturn( null );
	}

	protected function tearDown(): void {
		if ( class_exists( '\Akibara\ProviderAccess\Access', false ) ) {
			Access::flush();
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Deja el catálogo cargado y sin memo, como al empezar una petición.
	 */
	private function con_catalogo(): void {
		CatalogoDeAccesos::cargar();
		Access::flush();
	}

	/**
	 * @testdox A truncated key stored in the database must never reach the customer.
	 */
	public function test_una_clave_truncada_en_la_base_no_llega_al_cliente(): void {
		$this->con_catalogo();

		// El defecto que cierra esta prueba: el módulo leía la option a pelo, así
		// que la pantalla de accesos decía «formato inválido» mientras la clave
		// truncada se imprimía en el HTML del cliente y fallaba en su navegador.
		$this->options(
			array(
				'akibara_google_maps_api_key'          => self::TRUNCADA,
				'akibara_address_autocomplete_enabled' => 'yes',
			)
		);

		$sut = new AddressAutocomplete();

		self::assertSame( '', $sut->get_api_key(), 'Una clave que el catálogo rechaza no se sirve.' );
		self::assertFalse( $sut->is_ready(), 'Sin clave utilizable el módulo no se monta.' );
	}

	/**
	 * @testdox A valid key stored in the database still works, exactly as before.
	 */
	public function test_la_option_sigue_siendo_fuente_valida(): void {
		$this->con_catalogo();

		$this->options(
			array(
				'akibara_google_maps_api_key'          => self::VALIDA,
				'akibara_address_autocomplete_enabled' => 'yes',
			)
		);

		$sut = new AddressAutocomplete();

		self::assertSame( self::VALIDA, $sut->get_api_key() );
		self::assertTrue( $sut->is_ready() );
	}

	/**
	 * @testdox The admin notice must tell «written but invalid» apart from «missing».
	 */
	public function test_el_aviso_distingue_escrita_e_invalida_de_ausente(): void {
		$this->con_catalogo();

		$this->options(
			array(
				'akibara_google_maps_api_key'          => self::TRUNCADA,
				'akibara_address_autocomplete_enabled' => 'yes',
			)
		);

		$escrita_pero_invalida = $this->render_notice( new AddressAutocomplete() );

		self::assertStringContainsString( 'su formato no valida', $escrita_pero_invalida );
		self::assertStringNotContainsString( 'sin API key', $escrita_pero_invalida, 'Decir «falta» manda a escribir lo que ya está escrito.' );

		$this->options(
			array(
				'akibara_google_maps_api_key'          => '',
				'akibara_address_autocomplete_enabled' => 'yes',
			)
		);
		Access::flush();

		$ausente = $this->render_notice( new AddressAutocomplete() );

		self::assertStringContainsString( 'sin API key', $ausente );
	}

	/**
	 * @testdox With a usable key there is no notice at all.
	 */
	public function test_con_clave_utilizable_no_hay_aviso(): void {
		$this->con_catalogo();

		$this->options(
			array(
				'akibara_google_maps_api_key'          => self::VALIDA,
				'akibara_address_autocomplete_enabled' => 'yes',
			)
		);

		self::assertSame( '', $this->render_notice( new AddressAutocomplete() ) );
	}

	/**
	 * @testdox An invalid key living in the database must leave the panel field editable.
	 */
	public function test_una_clave_invalida_de_la_base_deja_el_campo_editable(): void {
		$this->con_catalogo();

		$this->options(
			array(
				'akibara_google_maps_api_key'          => self::TRUNCADA,
				'akibara_address_autocomplete_enabled' => 'yes',
			)
		);

		$campo = $this->key_field( new AddressAutocomplete() );

		self::assertArrayNotHasKey( 'custom_attributes', $campo, 'La clave está en la base: el dueño tiene que poder corregirla desde el panel.' );
		self::assertStringContainsString( 'no valida', (string) $campo['desc'] );
	}

	/**
	 * Una constante contamina el proceso: PHP no permite redefinirla.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @testdox A truncated constant blocks the panel field and says where to fix it.
	 */
	public function test_una_constante_truncada_bloquea_el_campo_y_dice_donde_corregirla(): void {
		define( 'AKB_GOOGLE_MAPS_API_KEY', self::TRUNCADA );
		$this->con_catalogo();

		$this->options(
			array(
				'akibara_google_maps_api_key'          => '',
				'akibara_address_autocomplete_enabled' => 'yes',
			)
		);

		$sut = new AddressAutocomplete();

		self::assertSame( '', $sut->get_api_key() );

		$campo = $this->key_field( $sut );

		// La constante manda aunque no valide: `resolve()` sólo cae a la base de
		// datos cuando la constante está ausente o vacía. Ofrecer el campo sería
		// invitar a una corrección que no surte efecto.
		self::assertSame( array( 'readonly' => 'readonly' ), $campo['custom_attributes'] ?? null );
		self::assertStringContainsString( 'wp-config.php', (string) $campo['desc'] );
		self::assertStringContainsString( 'no valida', (string) $campo['desc'] );
	}

	/**
	 * Sin cargar el catálogo a propósito: mide el mu-plugin ausente.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @testdox Without the access catalog the module switches off and says why.
	 */
	public function test_sin_catalogo_el_modulo_se_apaga_diciendo_por_que(): void {
		self::assertFalse( class_exists( '\Akibara\ProviderAccess\Access' ), 'Esta prueba mide justo el caso de que el mu-plugin no esté.' );

		$this->options(
			array(
				'akibara_google_maps_api_key'          => self::VALIDA,
				'akibara_address_autocomplete_enabled' => 'yes',
			)
		);

		$sut = new AddressAutocomplete();

		self::assertSame( '', $sut->get_api_key() );
		self::assertFalse( $sut->is_ready() );
		self::assertStringContainsString( 'akibara-provider-access.php', $this->render_notice( $sut ) );
	}

	/**
	 * Options deterministas para esta prueba.
	 *
	 * @param array<string, string> $valores Valores por nombre de option.
	 */
	private function options( array $valores ): void {
		Functions\when( 'get_option' )->alias(
			/**
			 * @param string $nombre     Nombre de la option.
			 * @param mixed  $por_defecto Valor por defecto.
			 * @return mixed
			 */
			static function ( $nombre, $por_defecto = false ) use ( $valores ) {
				return array_key_exists( (string) $nombre, $valores ) ? $valores[ (string) $nombre ] : $por_defecto;
			}
		);
	}

	private function render_notice( AddressAutocomplete $sut ): string {
		ob_start();
		$sut->maybe_render_misconfig_notice();

		return (string) ob_get_clean();
	}

	/**
	 * El campo de la API key dentro de los ajustes del panel.
	 *
	 * @return array<string, mixed>
	 */
	private function key_field( AddressAutocomplete $sut ): array {
		foreach ( $sut->get_settings() as $campo ) {
			if ( 'akibara_google_maps_api_key' === ( $campo['id'] ?? '' ) ) {
				return $campo;
			}
		}

		self::fail( 'El panel dejó de ofrecer el campo de la API key.' );
	}
}
