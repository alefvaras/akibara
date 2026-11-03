<?php
/**
 * Candado: invalidar el WebP del hero no puede depender de wp_options.
 *
 * `Renderer::find_adjacent_webp()` cachea 12 h por imagen bajo
 * `akb_hero_webp_<md5(url)>`. La invalidación al guardar los settings era un
 * `DELETE ... WHERE option_name LIKE '_transient_akb_hero_webp_%'`, y producción
 * corre object cache persistente (drop-in de LiteSpeed): los transients no viven
 * en esa tabla, así que el DELETE era código muerto — 0 filas que borrar, medido
 * en Docker el 2026-08-23 con `wp_using_ext_object_cache(true)`.
 *
 * La INVARIANTE: tras guardar los settings, la entrada cacheada de cada imagen
 * afectada —las que SALEN y las que ENTRAN— deja de resolver. Da igual cómo se
 * consiga; lo que no puede volver a pasar es que se consiga sólo cuando los
 * transients están en la tabla.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\HomeHero;

use Akibara\Business\Modules\HomeHero\HomeHero;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );

final class HomeHeroWebpInvalidationTest extends TestCase {

	/** Object cache en memoria: representa producción, no wp_options. */
	private array $object_cache = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->object_cache = array();

		Functions\when( 'wp_get_attachment_image_url' )->alias(
			static fn( int $id ): string => 'https://example.test/uploads/hero-' . $id . '.png'
		);
		Functions\when( 'delete_transient' )->alias(
			function ( string $key ): bool {
				unset( $this->object_cache[ $key ] );
				return true;
			}
		);
		Functions\when( 'get_transient' )->alias(
			fn( string $key ) => $this->object_cache[ $key ] ?? false
		);
		Functions\when( 'get_option' )->justReturn( array() );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox Guardar los settings invalida el WebP de los slides que salen y de
	 *          los que entran, con los transients fuera de wp_options.
	 */
	public function test_saving_settings_invalidates_old_and_new_slides(): void {
		$viejos = array( 'slides' => array( array( 'desktop_id' => 11, 'mobile_id' => 12 ) ) );
		$nuevos = array( 'slides' => array( array( 'desktop_id' => 21, 'mobile_id' => 22 ) ) );

		foreach ( array( 11, 12, 21, 22 ) as $id ) {
			$this->object_cache[ $this->key_for( $id ) ] = '0';
		}

		// Sin `global $wpdb` definido: si la implementación vuelve al DELETE, aquí
		// muere. Es deliberado — ese camino es exactamente el que no debe existir.
		( new HomeHero() )->invalidate_webp_cache_all( $viejos, $nuevos, 'akibara_hero_settings' );

		foreach ( array( 11, 12, 21, 22 ) as $id ) {
			self::assertFalse(
				$this->object_cache[ $this->key_for( $id ) ] ?? false,
				sprintf( 'La entrada del attachment %d tenía que dejar de resolver.', $id )
			);
		}
	}

	/**
	 * @testdox Un slide desactivado también se invalida: su entrada existe igual.
	 */
	public function test_inactive_slides_are_invalidated_too(): void {
		$settings = array(
			'slides' => array(
				array( 'desktop_id' => 31, 'mobile_id' => 0, 'is_active' => false ),
			),
		);
		$this->object_cache[ $this->key_for( 31 ) ] = 'https://example.test/uploads/hero-31.webp';

		( new HomeHero() )->invalidate_webp_cache_all( $settings, $settings, 'akibara_hero_settings' );

		self::assertFalse( $this->object_cache[ $this->key_for( 31 ) ] ?? false );
	}

	/**
	 * @testdox Sin slides no revienta ni intenta adivinar.
	 */
	public function test_empty_settings_are_a_noop(): void {
		$this->object_cache['akb_hero_webp_intacto'] = 'x';

		( new HomeHero() )->invalidate_webp_cache_all( array(), array(), 'akibara_hero_settings' );

		self::assertSame( 'x', $this->object_cache['akb_hero_webp_intacto'] );
	}

	private function key_for( int $attachment_id ): string {
		return 'akb_hero_webp_' . md5( 'https://example.test/uploads/hero-' . $attachment_id . '.png' );
	}
}
