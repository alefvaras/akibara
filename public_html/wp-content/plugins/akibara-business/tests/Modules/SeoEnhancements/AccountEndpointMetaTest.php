<?php
/** Títulos de cuenta sin un segundo catálogo de nombres ni datos del usuario. */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\SeoEnhancements;

use Akibara\Business\Modules\SeoEnhancements\AccountEndpointMeta;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class AccountEndpointMetaTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'is_account_page' )->justReturn( true );
		Functions\when( 'get_bloginfo' )->justReturn( 'Akibara' );
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( string $text, bool $remove_breaks = false ): string => trim( strip_tags( $text ) ) );
		Functions\when( 'apply_filters' )->alias( static fn( string $hook, $value ) => $value );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @testdox El nombre se obtiene del endpoint nativo y conserva el resto del título de WordPress. */
	public function test_uses_the_native_endpoint_label_for_document_and_social_title(): void {
		$query = new class() {
			public function get_current_endpoint(): string {
				return 'coleccion';
			}
			public function get_endpoint_title( string $endpoint ): string {
				TestCase::assertSame( 'coleccion', $endpoint );
				return '<span>Mi colección</span>';
			}
		};
		Functions\when( 'WC' )->justReturn( (object) array( 'query' => $query ) );
		$sut = new AccountEndpointMeta();

		self::assertSame( array( 'title' => 'Mi colección', 'site' => 'Akibara', 'page' => 'Página 2' ), $sut->document_title_parts( array( 'title' => 'Mi cuenta', 'site' => 'Akibara', 'page' => 'Página 2' ) ) );
		self::assertSame( 'Mi colección - Akibara', $sut->title( 'Mi cuenta - Akibara' ) );
	}

	/** @testdox Cambiar el título WooCommerce o el nombre de tienda llega a los metadatos sin otro mapa. */
	public function test_respects_endpoint_renaming_and_the_native_document_separator(): void {
		$query = new class() {
			public function get_current_endpoint(): string {
				return 'otro-endpoint';
			}
			public function get_endpoint_title( string $endpoint ): string {
				return 'Tu espacio lector';
			}
		};
		Functions\when( 'WC' )->justReturn( (object) array( 'query' => $query ) );
		Functions\when( 'get_bloginfo' )->justReturn( 'Akibara Chile' );
		Functions\when( 'apply_filters' )->alias( static fn( string $hook, $value ) => 'document_title_separator' === $hook ? '|' : $value );

		self::assertSame( 'Tu espacio lector | Akibara Chile', ( new AccountEndpointMeta() )->title( 'Mi cuenta' ) );
	}

	/** @testdox El dashboard y los endpoints sin título conservan sus metadatos previos. */
	public function test_preserves_original_metadata_when_the_endpoint_has_no_title(): void {
		$query = new class() {
			public string $endpoint = '';
			public function get_current_endpoint(): string {
				return $this->endpoint;
			}
			public function get_endpoint_title( string $endpoint ): string {
				return '';
			}
		};
		Functions\when( 'WC' )->justReturn( (object) array( 'query' => $query ) );
		$sut = new AccountEndpointMeta();
		$parts = array( 'title' => 'Mi cuenta', 'site' => 'Akibara' );

		self::assertSame( $parts, $sut->document_title_parts( $parts ) );
		self::assertSame( 'Mi cuenta - Akibara', $sut->title( 'Mi cuenta - Akibara' ) );
		$query->endpoint = 'desconocido';
		self::assertSame( 'Mi cuenta - Akibara', $sut->title( 'Mi cuenta - Akibara' ) );
	}

	/** @testdox Fuera de Mi cuenta no consulta endpoints ni modifica metadatos públicos. */
	public function test_does_not_change_public_pages_or_uninitialized_woocommerce(): void {
		Functions\when( 'is_account_page' )->justReturn( false );
		Functions\expect( 'WC' )->never();
		$sut = new AccountEndpointMeta();
		self::assertSame( array( 'title' => 'One Piece' ), $sut->document_title_parts( array( 'title' => 'One Piece' ) ) );
		self::assertSame( 'One Piece - Akibara', $sut->title( 'One Piece - Akibara' ) );
	}

	/** @testdox Una cuenta sin query inicializada mantiene el título servido por la plataforma. */
	public function test_preserves_title_when_woocommerce_query_is_unavailable(): void {
		Functions\when( 'WC' )->justReturn( (object) array( 'query' => null ) );
		self::assertSame( 'Mi cuenta - Akibara', ( new AccountEndpointMeta() )->title( 'Mi cuenta - Akibara' ) );
	}

	/** @testdox Las tarjetas sociales usan la URL nativa del endpoint raíz y no incorporan parámetros privados. */
	public function test_social_url_uses_native_root_endpoint_and_preserves_parameterized_routes(): void {
		$query = new class() {
			public function get_current_endpoint(): string {
				return 'coleccion';
			}
		};
		Functions\when( 'WC' )->justReturn( (object) array( 'query' => $query ) );
		Functions\when( 'get_query_var' )->justReturn( '' );
		Functions\when( 'wc_get_account_endpoint_url' )->alias( static fn( string $endpoint ): string => 'https://akibara.test/mi-cuenta/' . $endpoint . '/' );
		$sut = new AccountEndpointMeta();
		self::assertSame( 'https://akibara.test/mi-cuenta/coleccion/', $sut->social_url( 'https://akibara.test/mi-cuenta/' ) );

		Functions\when( 'get_query_var' )->justReturn( '1234' );
		self::assertSame( 'https://akibara.test/mi-cuenta/', $sut->social_url( 'https://akibara.test/mi-cuenta/' ) );
		Functions\when( 'is_account_page' )->justReturn( false );
		self::assertSame( 'https://akibara.test/serie/one-piece/', $sut->social_url( 'https://akibara.test/serie/one-piece/' ) );
	}
}
