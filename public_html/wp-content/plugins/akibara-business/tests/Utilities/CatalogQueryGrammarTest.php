<?php
/**
 * Pruebas de la gramática pública de filtrado del catálogo.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Utilities;

use Akibara\Business\Utilities\CatalogQueryGrammar;
use PHPUnit\Framework\TestCase;

final class CatalogQueryGrammarTest extends TestCase {

	/**
	 * @dataProvider parametros
	 *
	 * @testdox Should recognise only catalog filter parameters.
	 */
	public function test_reconoce_solo_la_gramatica_del_catalogo( string $key, bool $esperado, string $porque ): void {
		self::assertSame( $esperado, CatalogQueryGrammar::is_filter_parameter( $key ), $porque );
	}

	/**
	 * @return array<string, array{0:string,1:bool,2:string}>
	 */
	public static function parametros(): array {
		return array(
			'faceta del buscador'    => array( '_disponibilidad', true, 'El prefijo `_` es del buscador first-party.' ),
			'búsqueda por texto'     => array( '_buscar', true, 'Participa de la política de robots.' ),
			'paginación'             => array( '_paged', false, 'Paginar avanza dentro de la MISMA colección.' ),
			'filtro de WooCommerce'  => array( 'filter_editorial', true, 'Gramática nativa de atributos.' ),
			'tipo de filtro Woo'     => array( 'query_type_editorial', true, 'Acompaña a `filter_*`.' ),
			'orden'                  => array( 'orderby', true, 'Reordenar produce una variante.' ),
			'precio mínimo'          => array( 'min_price', true, 'Acota la colección.' ),
			'campaña utm'            => array( 'utm_source', false, 'Lo añade el anunciante, no el catálogo.' ),
			'clic de Google Ads'     => array( 'gclid', false, 'Lo añade la red publicitaria.' ),
			'clic de Meta'           => array( 'fbclid', false, 'Lo añade la red social.' ),
			'referido arbitrario'    => array( 'ref', false, 'No pertenece a ninguna gramática de catálogo.' ),
		);
	}

	/** @testdox Should detect a facet hidden among tracking parameters. */
	public function test_detecta_una_faceta_entre_parametros_de_campana(): void {
		self::assertFalse(
			CatalogQueryGrammar::has_filter_parameter( array( 'utm_source' => 'ig', 'gclid' => 'x' ) )
		);
		self::assertTrue(
			CatalogQueryGrammar::has_filter_parameter( array( 'utm_source' => 'ig', 'orderby' => 'price' ) )
		);
		self::assertFalse( CatalogQueryGrammar::has_filter_parameter( array() ) );
	}

	/**
	 * Claves numéricas (`?_disponibilidad`, sin `=`, llega como `0 => '...'` en
	 * algunos servidores) no son nombres de parámetro y no deben decidir nada.
	 *
	 * @testdox Should ignore non-string query keys.
	 */
	public function test_ignora_claves_no_textuales(): void {
		self::assertFalse( CatalogQueryGrammar::has_filter_parameter( array( 0 => 'orderby' ) ) );
	}
}
