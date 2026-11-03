<?php
/**
 * La marca de «otra versión del mismo tomo».
 *
 * El enum es cerrado y se sanea AL LEER: `wp_postmeta` también se escribe por
 * WP-CLI y por importaciones, y un valor desconocido no puede convertir un tomo
 * normal en invisible para los avisos, la colección y la landing.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Services;

use Akibara\Business\Services\VolumeVariant;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class VolumeVariantTest extends TestCase {

	/** @var array<int, mixed> Valor crudo de la meta por producto. */
	private array $meta = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->meta = array();
		Functions\when( 'get_post_meta' )->alias(
			fn ( int $id, string $key = '', bool $single = false ) => VolumeVariant::META_KEY === $key
				? ( $this->meta[ $id ] ?? '' )
				: ''
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @testdox Los tres tipos del enum marcan variante. */
	public function test_los_tres_tipos_del_enum_marcan_variante(): void {
		foreach ( array( VolumeVariant::KIND_EDICION_ESPECIAL, VolumeVariant::KIND_PORTADA_ALTERNATIVA, VolumeVariant::KIND_REEDICION ) as $kind ) {
			$this->meta[ 10 ] = $kind;

			self::assertTrue( VolumeVariant::is_variant( 10 ), $kind );
			self::assertSame( $kind, VolumeVariant::kind_of( 10 ) );
		}
	}

	/** @testdox Sin marca, un producto es un tomo normal. */
	public function test_sin_marca_es_un_tomo_normal(): void {
		self::assertFalse( VolumeVariant::is_variant( 10 ) );
		self::assertSame( VolumeVariant::KIND_NONE, VolumeVariant::kind_of( 10 ) );
	}

	/**
	 * @testdox [NEG] Un valor fuera del enum se lee como tomo normal, no como variante.
	 * @dataProvider basura_provider
	 *
	 * @param mixed $raw Valor crudo escrito fuera del formulario.
	 */
	public function test_un_valor_fuera_del_enum_no_marca_variante( $raw ): void {
		$this->meta[ 10 ] = $raw;

		self::assertFalse( VolumeVariant::is_variant( 10 ) );
		self::assertSame( VolumeVariant::KIND_NONE, VolumeVariant::kind_of( 10 ) );
	}

	/** @return array<string, array{mixed}> */
	public function basura_provider(): array {
		return array(
			'texto libre'    => array( 'edicion especial' ),
			'mayúsculas'     => array( 'EDICION_ESPECIAL' ),
			'booleano'       => array( true ),
			'número'         => array( 1 ),
			'arreglo'        => array( array( 'edicion_especial' ) ),
			'nulo'           => array( null ),
			'clave inventada' => array( 'variante' ),
		);
	}

	/** @testdox Un id inválido no consulta la base y devuelve tomo normal. */
	public function test_un_id_invalido_devuelve_tomo_normal(): void {
		Functions\when( 'get_post_meta' )->alias(
			static function (): string {
				self::fail( 'Un id inválido no debe llegar a consultar la meta.' );
			}
		);

		self::assertFalse( VolumeVariant::is_variant( 0 ) );
		self::assertFalse( VolumeVariant::is_variant( -3 ) );
	}

	/** @testdox `is_variant_kind()` acepta el valor crudo que traen las consultas en lote. */
	public function test_is_variant_kind_sanea_el_valor_crudo(): void {
		self::assertTrue( VolumeVariant::is_variant_kind( VolumeVariant::KIND_REEDICION ) );
		self::assertFalse( VolumeVariant::is_variant_kind( null ) );
		self::assertFalse( VolumeVariant::is_variant_kind( '' ) );
		self::assertFalse( VolumeVariant::is_variant_kind( 'otra cosa' ) );
	}

	/**
	 * Los lectores en SQL —el agregado de la landing, el del directorio y el
	 * orden por tomos de la editorial— traen la marca en la propia fila y no
	 * pueden llamar a `sanitize_kind()`. La condición se compone acá para que
	 * PHP y SQL contesten lo mismo: antes el SQL comparaba «distinto de vacío» y
	 * ascendía a variante cualquier tipeo que PHP descarta.
	 *
	 * @testdox La condición SQL pregunta por el enum cerrado, no por «no vacío».
	 */
	public function test_la_condicion_sql_usa_el_enum_cerrado(): void {
		$kinds = VolumeVariant::variant_kinds();

		self::assertSame(
			array(
				VolumeVariant::KIND_EDICION_ESPECIAL,
				VolumeVariant::KIND_PORTADA_ALTERNATIVA,
				VolumeVariant::KIND_REEDICION,
			),
			$kinds,
			'La lista de SQL es el enum sin el tomo normal, y en su orden.'
		);
		self::assertNotContains( VolumeVariant::KIND_NONE, $kinds );

		$condition = VolumeVariant::sql_normal_volume_condition( 'vm.meta_value' );

		self::assertSame(
			"TRIM( COALESCE( vm.meta_value, '' ) ) NOT IN ( %s, %s, %s )",
			$condition,
			'Un `%s` por tipo: el caller pasa `variant_kinds()` en esa posición.'
		);
		self::assertSame( count( $kinds ), substr_count( $condition, '%s' ) );
		self::assertStringNotContainsString( "= ''", $condition );
	}

	/**
	 * El candado de la equivalencia, con los valores que la medición del espejo
	 * encontró: los cinco daban «tomo normal» en PHP y «VARIANTE» en el SQL
	 * viejo. Acá se comprueba que hoy los dos lados dicen lo mismo.
	 *
	 * @testdox PHP y SQL clasifican igual los valores basura medidos en el espejo.
	 *
	 * @dataProvider valores_medidos_en_el_espejo
	 *
	 * @param string $raw       Valor crudo del meta.
	 * @param bool   $esperado  Si es variante.
	 */
	public function test_php_y_sql_clasifican_igual( string $raw, bool $esperado ): void {
		self::assertSame( $esperado, VolumeVariant::is_variant_kind( $raw ) );
		self::assertSame( $esperado, in_array( trim( $raw ), VolumeVariant::variant_kinds(), true ) );
	}

	/** @return array<string, array{0:string,1:bool}> */
	public static function valores_medidos_en_el_espejo(): array {
		return array(
			'con guion'       => array( 'edicion-especial', false ),
			'con espacios'    => array( 'Edicion Especial', false ),
			'en mayúsculas'   => array( 'EDICION_ESPECIAL', false ),
			'un uno'          => array( '1', false ),
			'un yes'          => array( 'yes', false ),
			'vacío'           => array( '', false ),
			'del enum'        => array( 'edicion_especial', true ),
			'del enum con espacio' => array( ' reedicion ', true ),
		);
	}

	/** @testdox El enum tiene etiqueta en castellano para cada valor, incluido el vacío. */
	public function test_cada_valor_del_enum_tiene_etiqueta(): void {
		$kinds = VolumeVariant::kinds();

		self::assertArrayHasKey( VolumeVariant::KIND_NONE, $kinds );
		self::assertCount( 4, $kinds );
		foreach ( $kinds as $label ) {
			self::assertNotSame( '', trim( $label ) );
		}
	}
}
