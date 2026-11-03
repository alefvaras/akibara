<?php
/**
 * Composition-root contract for the private account collection.
 *
 * Antes este archivo no EJECUTABA nada: eran cuatro expresiones regulares sobre
 * el texto de `Plugin.php`, sensibles a espacios y a saltos de línea. Pasaban
 * con el código comentado y se ponían rojas al reformatear un argumento, que es
 * exactamente al revés de lo que un contrato tiene que hacer.
 *
 * Lo que de verdad importa acá es que UNA sola autoridad de compras netas
 * alimente las dos superficies (packs de serie y «Mi colección»), y que esa
 * autoridad enganche sus oyentes. Eso sí se puede observar.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\AccountCollection;

use Akibara\Business\Modules\AccountCollection\AccountCollection;
use Akibara\Business\Modules\AccountCollection\CollectionResolver;
use Akibara\Business\Modules\SeriesBundles\CustomerCollection;
use Akibara\Business\Modules\SeriesBundles\SeriesBundles;
use Brain\Monkey;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class PluginWiringTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * La autoridad de propiedad engancha sus oyentes al registrarse.
	 *
	 * Sin esto la colección del cliente se queda congelada: los cambios de
	 * estado, los reembolsos y las ediciones de línea no invalidan nada.
	 */
	public function test_the_ownership_authority_registers_its_listeners(): void {
		$colection = new CustomerCollection();

		Monkey\Actions\expectAdded( 'woocommerce_order_status_changed' )->once();
		Monkey\Actions\expectAdded( 'woocommerce_order_refunded' )->once();
		Monkey\Actions\expectAdded( 'woocommerce_update_order' )->once();
		Monkey\Actions\expectAdded( 'shutdown' )->once();

		$colection->register();
		$this->addToAssertionCount( 4 );
	}

	/**
	 * Las dos superficies USAN la autoridad que se les entrega, en vez de
	 * fabricarse una propia.
	 *
	 * Eso es lo que hace que compartir una sola instancia sirva de algo: dos
	 * autoridades son dos cachés de propiedad que pueden contradecirse sobre el
	 * mismo cliente, y el síntoma sería un pack que cuenta un tomo que «Mi
	 * colección» dice que no está comprado.
	 *
	 * @dataProvider surfacesThatNeedTheAuthority
	 */
	public function test_both_surfaces_use_the_authority_they_are_given( string $clase, callable $construir ): void {
		$autoridad = new CustomerCollection();
		$superficie = $construir( $autoridad );

		$propiedad = ( new ReflectionClass( $clase ) )->getProperty( 'customer_collection' );
		$propiedad->setAccessible( true );

		self::assertSame(
			$autoridad,
			$propiedad->getValue( $superficie ),
			$clase . ' se fabricó su propia autoridad en vez de usar la compartida.'
		);
	}

	/** @return array<string, array{0: string, 1: callable}> */
	public static function surfacesThatNeedTheAuthority(): array {
		return array(
			'packs de serie' => array(
				SeriesBundles::class,
				static fn( CustomerCollection $autoridad ): SeriesBundles => new SeriesBundles( null, $autoridad ),
			),
			'mi colección'   => array(
				CollectionResolver::class,
				static fn( CustomerCollection $autoridad ): CollectionResolver => new CollectionResolver( $autoridad ),
			),
		);
	}

	/**
	 * El endpoint de «Mi colección» resuelve a través del callable que recibe,
	 * que es como el composition root le pasa el resolver compartido.
	 */
	public function test_the_account_endpoint_resolves_through_the_injected_callable(): void {
		$pedidos = array();
		$modulo  = new AccountCollection(
			static function ( int $customer_id ) use ( &$pedidos ): array {
				$pedidos[] = $customer_id;

				return array( 'status' => 'empty' );
			}
		);

		$renderer = ( new ReflectionClass( $modulo ) )->getProperty( 'endpoint' );
		$renderer->setAccessible( true );
		$endpoint = $renderer->getValue( $modulo );

		$propiedad = ( new ReflectionClass( $endpoint ) )->getProperty( 'renderer' );
		$propiedad->setAccessible( true );
		$vista = $propiedad->getValue( $endpoint );

		$resolver = ( new ReflectionClass( $vista ) )->getProperty( 'resolver' );
		$resolver->setAccessible( true );
		( $resolver->getValue( $vista ) )( 42 );

		self::assertSame( array( 42 ), $pedidos, 'El endpoint tiene que usar el resolver inyectado, no fabricarse uno.' );
	}

	/**
	 * Y en el composition root la autoridad se construye UNA sola vez.
	 *
	 * Ésta sí mira el fuente, porque el defecto que previene es literalmente «se
	 * instanció dos veces»: no fija formato ni orden de argumentos, sólo cuenta
	 * construcciones.
	 */
	public function test_the_composition_root_builds_exactly_one_authority(): void {
		$fuente = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Plugin.php' );

		self::assertSame(
			1,
			substr_count( $fuente, 'new CustomerCollection()' ),
			'Dos autoridades = dos cachés de propiedad que se contradicen entre superficies.'
		);
	}
}
