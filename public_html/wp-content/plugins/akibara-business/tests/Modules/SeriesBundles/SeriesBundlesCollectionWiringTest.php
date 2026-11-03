<?php
/**
 * Shared collection ownership wiring across bundle surfaces.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\SeriesBundles;

use Akibara\Business\Modules\SeriesBundles\CartSeriesCollector;
use Akibara\Business\Modules\SeriesBundles\CustomerCollection;
use Akibara\Business\Modules\SeriesBundles\EligibilityGuard;
use Akibara\Business\Modules\SeriesBundles\PromotionPolicy;
use Akibara\Business\Modules\SeriesBundles\SeriesDepth;
use Akibara\Business\Modules\SeriesBundles\SeriesBundles;
use Akibara\Business\Modules\SeriesBundles\Settings;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionObject;

final class SeriesBundlesCollectionWiringTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @testdox Builder, landing and invalidation hooks share one ownership service. */
	public function test_collection_reader_is_shared_by_all_bundle_surfaces(): void {
		$collection = new CustomerCollection();
		$module     = new SeriesBundles( null, $collection );
		$module_ref = new ReflectionObject( $module );

		$collection_property = $module_ref->getProperty( 'customer_collection' );
		$collection_property->setAccessible( true );
		self::assertSame( $collection, $collection_property->getValue( $module ) );

		foreach ( array( 'builder_widget', 'landing_offer' ) as $component_name ) {
			$component_property = $module_ref->getProperty( $component_name );
			$component_property->setAccessible( true );
			$component     = $component_property->getValue( $module );
			$component_ref = new ReflectionObject( $component );
			$reader        = $component_ref->getProperty( 'customer_collection' );
			$reader->setAccessible( true );

			self::assertSame(
				$collection,
				$reader->getValue( $component ),
				$component_name . ' no debe crear otro lector de propiedad.'
			);
		}
	}

	/** @testdox El upgrade ejecuta la migración persistida sólo desde la versión objetivo. */
	public function test_upgrade_runs_the_persisted_policy_migration_from_target_version(): void {
		$raw      = (string) json_encode(
			array(
				'10' => array( 'enabled' => true, 'tiers' => array( array( 'min' => 5, 'pct' => 10 ) ) ),
			)
		);
		$reads    = 0;
		$writes   = array();
		$settings = new Settings();
		$policy   = new PromotionPolicy(
			$settings,
			new SeriesDepth( $settings ),
			new CartSeriesCollector( $settings, new EligibilityGuard( $settings ) ),
			static fn( int $term_id, array $_planned ): int => 10 === $term_id ? 8 : 0,
			static function () use ( &$reads, $raw ): string {
				++$reads;

				return $raw;
			},
			static function ( string $expected, array $updated ) use ( &$writes ): bool {
				$writes[] = array( $expected, $updated );

				return true;
			}
		);
		$module     = new SeriesBundles();
		$module_ref = new ReflectionObject( $module );
		$property   = $module_ref->getProperty( 'promotion_policy' );
		$property->setAccessible( true );
		$property->setValue( $module, $policy );

		$module->standardize_promotion_policy( '1.102.8', '1.102.7' );
		self::assertSame( 0, $reads );
		self::assertSame( array(), $writes );

		$module->standardize_promotion_policy( '1.102.9', '1.102.8' );
		self::assertSame( 1, $reads );
		self::assertCount( 1, $writes );
		self::assertSame( $raw, $writes[0][0] );
		self::assertSame(
			array( array( 'min' => 5, 'pct' => 8 ), array( 'min' => 7, 'pct' => 10 ) ),
			$writes[0][1]['10']['tiers']
		);
	}
}
