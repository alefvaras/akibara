<?php
/**
 * Contrato de composición pública de AccountCollection.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\AccountCollection;

use Akibara\Business\Modules\AccountCollection\AccountCollection;
use Brain\Monkey;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class AccountCollectionTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @testdox API pública exige exactamente un callable resolver(int): array. */
	public function test_constructor_contract_is_one_required_callable(): void {
		$constructor = new ReflectionMethod( AccountCollection::class, '__construct' );
		$parameters  = $constructor->getParameters();

		self::assertCount( 1, $parameters );
		self::assertSame( 'resolver', $parameters[0]->getName() );
		self::assertFalse( $parameters[0]->isOptional() );
		self::assertSame( 'callable', (string) $parameters[0]->getType() );
	}

	/** @testdox El orquestador registra el riel completo sin crear hooks AJAX. */
	public function test_register_delegates_to_the_endpoint(): void {
		$module = new AccountCollection(
			static fn( int $user_id ): array => array( 'status' => 'empty', 'series' => array() )
		);

		Monkey\Actions\expectAdded( 'init' )->once();
		Monkey\Filters\expectAdded( 'woocommerce_get_query_vars' )->once();
		Monkey\Filters\expectAdded( 'woocommerce_account_menu_items' )->once();
		Monkey\Filters\expectAdded( 'woocommerce_endpoint_coleccion_title' )->once();
		Monkey\Actions\expectAdded( 'woocommerce_account_coleccion_endpoint' )->once();
		Monkey\Actions\expectAdded( 'wp_enqueue_scripts' )->once();
		Monkey\Filters\expectAdded( 'wp_headers' )->once();
		Monkey\Actions\expectAdded( 'template_redirect' )->once();
		Monkey\Actions\expectAdded( 'wp_ajax_akb_account_collection' )->never();
		Monkey\Actions\expectAdded( 'wp_ajax_nopriv_akb_account_collection' )->never();

		$module->register();
		self::addToAssertionCount( 10 );
	}

	/** @testdox Los details conservan indicador, foco válido, target táctil y reduced motion en ambos motores. */
	public function test_styles_keep_details_discoverable_and_accessible(): void {
		$css = file_get_contents( dirname( __DIR__, 3 ) . '/assets/css/account-collection.css' );

		self::assertIsString( $css );
		self::assertStringContainsString( '.akb-account-collection__owned-details summary::after', $css );
		self::assertStringContainsString( '.akb-account-collection__owned-details[open] summary::after', $css );
		self::assertStringContainsString( 'min-height: 44px', $css );
		self::assertStringContainsString( '--akb-collection-focus-outline: var(--aki-focus-outline,', $css );
		self::assertStringContainsString( '--akb-collection-focus-offset: var(--aki-focus-offset,', $css );
		// Sin el token de sombra: el foco es el outline de la casa y nada más
		// (último consumidor de --aki-focus-shadow, retirado en los remanentes).
		self::assertStringNotContainsString( 'focus-shadow', $css );
		self::assertStringNotContainsString( '--akb-collection-focus: var(--aki-focus-ring', $css );
		self::assertStringContainsString( '.akb-account-collection__continuity--up_to_date', $css );
		self::assertStringContainsString( '.akb-account-collection__availability--preorder', $css );
		self::assertStringContainsString( '.akb-account-collection__privacy-note', $css );
		self::assertMatchesRegularExpression(
			'/\.akb-account-collection :is\(a, summary\):focus-visible\s*\{[^}]*box-shadow:\s*none;[^}]*outline:\s*var\(--akb-collection-focus-outline\);[^}]*outline-offset:\s*var\(--akb-collection-focus-offset\);/s',
			$css
		);
		self::assertStringContainsString( '@media (prefers-reduced-motion: reduce)', $css );
	}
}
