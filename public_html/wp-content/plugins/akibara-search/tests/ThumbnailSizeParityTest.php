<?php
/**
 * Paridad del tamaño de miniatura entre el índice y el camino vivo.
 *
 * El autocomplete pinta la portada en una caja de 40×40 (`object-fit: cover`) y
 * pedía `woocommerce_thumbnail`, o sea 350×525 — 29,5 KB de media, 7 por panel:
 * 220 KB medidos por apertura, en la superficie móvil que se dispara mientras
 * el cliente todavía teclea. El derivado de 150×150 ya existe para todo el
 * catálogo y pesa 5,6 KB.
 *
 * La invariante que este test protege NO es el nombre del tamaño sino la
 * PARIDAD: `DocumentBuilder` materializa `docs.thumb_url` y `ProductPresenter`
 * sirve el camino vivo, y el Reconciler compara ese campo uno contra otro. Si
 * los dos lados piden tamaños distintos, la reconciliación diaria marca TODOS
 * los documentos como desalineados.
 *
 * @package Akibara\Search\Tests
 */

declare( strict_types = 1 );

namespace {
	// WC_Product vive como doble ÚNICO en Support/wp-function-stubs.php: tres
	// copias distintas tras `class_exists` hacían que la forma ganadora dependiera
	// del orden de ejecución (flake medido con --order-by=random).
}

namespace Akibara\Search\Tests {

use Akibara\Search\DocumentBuilder;
use Akibara\Search\ProductPresenter;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

require_once dirname( __DIR__ ) . '/src/ProductPresenter.php';

/** wpdb mínimo: los lectores guardados sólo miran `last_error`. */
final class ThumbnailParityWpdb {

	public string $prefix = 'wp_';

	public string $last_error = '';
}

/** Producto mínimo: sólo la identidad de imagen que los dos lados leen. */
final class ThumbnailParityProduct extends \WC_Product {

	public function get_image_id(): int {
		return 77;
	}
}

final class ThumbnailSizeParityTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// La URL devuelta CODIFICA el tamaño pedido: así la igualdad de las dos
		// puntas es igualdad del tamaño, no una coincidencia de literales.
		Functions\when( 'wp_get_attachment_image_url' )->alias(
			static fn( int $id, string $size ): string => 'https://akibara.test/uploads/' . $id . '-' . $size . '.avif'
		);
		Functions\when( 'is_wp_error' )->justReturn( false );

		// Los dos lectores guardados consultan `last_error` del $wpdb vivo.
		$GLOBALS['wpdb'] = new ThumbnailParityWpdb();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @testdox El índice y el camino vivo piden la MISMA miniatura. */
	public function test_el_indice_y_el_camino_vivo_piden_la_misma_miniatura(): void {
		$product = new ThumbnailParityProduct( 501 );

		$indexed = $this->invoke( DocumentBuilder::class, new DocumentBuilder(), 'thumb_url', $product );
		$live    = $this->invoke( ProductPresenter::class, new ProductPresenter( $GLOBALS['wpdb'] ), 'live_thumb_url', $product );

		self::assertSame( $indexed, $live, 'Un tamaño distinto por lado desalinea todos los docs en el Reconciler.' );
	}

	/** @testdox La miniatura servida NO es la portada de catálogo de 350 px. */
	public function test_la_miniatura_no_es_la_portada_de_catalogo(): void {
		self::assertNotSame(
			'woocommerce_thumbnail',
			DocumentBuilder::THUMB_SIZE,
			'350×525 para una caja de 40 px son 220 KB por panel abierto.'
		);
		self::assertSame(
			'https://akibara.test/uploads/77-' . DocumentBuilder::THUMB_SIZE . '.avif',
			$this->invoke( DocumentBuilder::class, new DocumentBuilder(), 'thumb_url', new ThumbnailParityProduct( 501 ) )
		);
	}

	/**
	 * @param class-string $class  Clase.
	 * @param object       $object Instancia.
	 * @param string       $method Método privado.
	 * @param mixed        $arg    Argumento.
	 * @return mixed
	 */
	private function invoke( string $class, object $object, string $method, $arg ) {
		$reflection = new ReflectionMethod( $class, $method );
		$reflection->setAccessible( true );

		return $reflection->invoke( $object, $arg );
	}
}
}
