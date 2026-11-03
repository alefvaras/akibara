<?php
/**
 * Pruebas del contrato compartido del `@graph` JSON-LD.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Utilities;

use Akibara\Business\Utilities\SchemaGraph;
use PHPUnit\Framework\TestCase;

final class SchemaGraphTest extends TestCase {

	/**
	 * El `@type` de JSON-LD puede ser un escalar o una lista, y el grafo lo
	 * compone un plugin de terceros: la forma exacta no la controlamos. Había
	 * tres implementaciones de esta pregunta con tres semánticas distintas.
	 *
	 * @dataProvider nodos
	 *
	 * @testdox Should recognise scalar and list @type declarations the same way.
	 *
	 * @param array<string,mixed> $node     Nodo del grafo.
	 * @param string              $type     Tipo buscado.
	 * @param bool                $esperado Resultado.
	 * @param string              $porque   Motivo legible.
	 */
	public function test_reconoce_tipos_escalares_y_listas( array $node, string $type, bool $esperado, string $porque ): void {
		self::assertSame( $esperado, SchemaGraph::node_has_type( $node, $type ), $porque );
	}

	/**
	 * @return array<string, array{0:array<string,mixed>,1:string,2:bool,3:string}>
	 */
	public static function nodos(): array {
		return array(
			'escalar exacto'      => array( array( '@type' => 'Product' ), 'Product', true, 'La forma más común.' ),
			'escalar distinto'    => array( array( '@type' => 'WebPage' ), 'Product', false, 'No confundir tipos.' ),
			'lista con el tipo'   => array( array( '@type' => array( 'CollectionPage', 'WebPage' ) ), 'WebPage', true, 'Rank Math emite listas.' ),
			'lista sin el tipo'   => array( array( '@type' => array( 'CollectionPage' ) ), 'WebPage', false, 'La lista es exhaustiva.' ),
			'sin @type'           => array( array( '@id' => '#x' ), 'WebPage', false, 'Un nodo sin tipo no es de ningún tipo.' ),
			'@type nulo'          => array( array( '@type' => null ), 'WebPage', false, 'Ni siquiera con el tipo vacío.' ),
			'@type no textual'    => array( array( '@type' => array( array( 'x' ) ) ), 'WebPage', false, 'Una forma ilegible nunca casa.' ),
			'tipo buscado vacío'  => array( array( '@type' => 'Product' ), '', false, 'Buscar «nada» no puede casar con todo.' ),
		);
	}

	/**
	 * El orden entre los nueve callbacks de `rank_math/json_ld` es un contrato:
	 * quien adjunta a la página tiene que correr después de quien la construye,
	 * y la higiene final después de todos. Antes ese contrato sólo existía como
	 * números sueltos en siete ficheros distintos.
	 *
	 * @testdox Should keep the graph stages strictly ordered, with cleanup last.
	 */
	public function test_las_etapas_del_grafo_estan_ordenadas(): void {
		$etapas = array(
			SchemaGraph::PRODUCT_OFFERS,
			SchemaGraph::SERIES_COLLECTION,
			SchemaGraph::PAGE_ATTACHMENTS,
			SchemaGraph::ROUTE_COLLECTIONS,
			SchemaGraph::PRODUCT_RELATIONSHIPS,
			SchemaGraph::GLOBAL_CLEANUP,
		);

		$ordenadas = $etapas;
		sort( $ordenadas );

		self::assertSame( $ordenadas, $etapas, 'Las etapas tienen que declararse en el orden en que corren.' );
		self::assertSame( count( $etapas ), count( array_unique( $etapas ) ), 'Dos etapas con el mismo número no son dos etapas.' );
		self::assertSame( max( $etapas ), SchemaGraph::GLOBAL_CLEANUP, 'La higiene limpia lo que dejaron TODAS las anteriores.' );
	}

	/**
	 * INVARIANTE de registro: ningún módulo puede volver a cablear un número
	 * suelto en su `add_filter( 'rank_math/json_ld', … )`.
	 *
	 * @testdox Should register every json_ld callback through the shared stage constants.
	 */
	public function test_todos_los_callbacks_usan_las_constantes_de_etapa(): void {
		$raiz     = dirname( __DIR__, 2 ) . '/src';
		$sueltos  = array();
		$ficheros = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $raiz ) );

		foreach ( $ficheros as $fichero ) {
			if ( ! $fichero->isFile() || 'php' !== $fichero->getExtension() ) {
				continue;
			}
			$contenido = (string) file_get_contents( (string) $fichero->getPathname() );
			if ( 1 === preg_match( "#add_filter\(\s*'rank_math/json_ld',[^;]*?,\s*\d+\s*,#s", $contenido ) ) {
				$sueltos[] = str_replace( $raiz . '/', '', (string) $fichero->getPathname() );
			}
		}

		self::assertSame( array(), $sueltos, 'La prioridad se declara como etapa de SchemaGraph, no como número suelto.' );
	}
}
