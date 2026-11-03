<?php
/**
 * Tests for HomeHero responsive preload resources.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\HomeHero;

use Akibara\Business\Modules\HomeHero\HomeHero;
use Akibara\Business\Modules\HomeHero\Settings;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );

final class HomeHeroTest extends TestCase {

	/**
	 * @var array<string,mixed>
	 */
	private array $settings;

	private int $queried_object_id = 210;

	private string $current_content = '[akb_hero]';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->settings = Settings::defaults();

		Functions\when( 'get_queried_object_id' )->alias(
			fn(): int => $this->queried_object_id
		);
		Functions\when( 'get_post_field' )->alias(
			function ( string $field, int $post_id ) {
				if ( 'post_content' !== $field || $post_id !== $this->queried_object_id ) {
					return '';
				}
				return $this->current_content;
			}
		);
		Functions\when( 'has_shortcode' )->alias(
			static fn( string $content, string $tag ): bool => str_contains( $content, '[' . $tag )
		);
		Functions\when( 'get_option' )->alias(
			function ( string $key, $default = false ) {
				if ( Settings::OPTION_KEY === $key ) {
					return $this->settings;
				}
				return $default;
			}
		);
		Functions\when( 'wp_get_upload_dir' )->justReturn(
			array(
				'basedir' => '/tmp/akb-home-hero-preload-tests',
				'baseurl' => 'https://example.test/wp-content/uploads',
			)
		);
		Functions\when( 'get_attached_file' )->alias(
			static function ( int $attachment_id ): string {
				$extension = $attachment_id >= 9100 ? 'png' : 'avif';
				return sprintf( '/tmp/akb-home-hero-preload-tests/preload-%d.%s', $attachment_id, $extension );
			}
		);
		Functions\when( 'wp_get_attachment_image_srcset' )->alias(
			static fn( int $attachment_id ): string => sprintf(
				'https://example.test/wp-content/uploads/preload-%1$d-350.avif 350w, https://example.test/wp-content/uploads/preload-%1$d.avif 1920w',
				$attachment_id
			)
		);
		Functions\when( 'wp_get_attachment_image_sizes' )->justReturn( '(max-width: 1920px) 100vw, 1920px' );
		Functions\when( 'get_transient' )->alias(
			static function ( string $key ) {
				$desktop_png = 'https://example.test/wp-content/uploads/preload-9101.png';
				$mobile_png  = 'https://example.test/wp-content/uploads/preload-9102.png';

				if ( 'akb_hero_webp_' . md5( $desktop_png ) === $key ) {
					return 'https://example.test/wp-content/uploads/preload-9101.webp';
				}
				if ( 'akb_hero_webp_' . md5( $mobile_png ) === $key ) {
					return 'https://example.test/wp-content/uploads/preload-9102.webp';
				}
				return false;
			}
		);
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'wp_parse_url' )->alias(
			static function ( string $url, int $component = -1 ) {
				return -1 === $component ? parse_url( $url ) : parse_url( $url, $component );
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_preload_includes_imagesrcset_and_imagesizes_for_original_format(): void {
		$this->settings = $this->settings_with_ids( 9001, 9002 );

		$resources = ( new HomeHero() )->inject_preload( array() );

		self::assertCount( 2, $resources );
		self::assertSame(
			'https://example.test/wp-content/uploads/preload-9001-350.avif 350w, https://example.test/wp-content/uploads/preload-9001.avif 1920w',
			$resources[0]['imagesrcset']
		);
		self::assertSame( '(max-width: 1920px) 100vw, 1920px', $resources[0]['imagesizes'] );
		self::assertSame(
			'https://example.test/wp-content/uploads/preload-9002-350.avif 350w, https://example.test/wp-content/uploads/preload-9002.avif 1920w',
			$resources[1]['imagesrcset']
		);
		self::assertSame( '(max-width: 768px)', $resources[1]['media'] );
	}

	public function test_preload_omits_responsive_attributes_for_adjacent_webp(): void {
		$this->settings = $this->settings_with_ids( 9101, 9102 );

		$resources = ( new HomeHero() )->inject_preload( array() );

		self::assertCount( 2, $resources );
		self::assertSame( 'https://example.test/wp-content/uploads/preload-9101.webp', $resources[0]['href'] );
		self::assertSame( 'image/webp', $resources[0]['type'] );
		self::assertArrayNotHasKey( 'imagesrcset', $resources[0] );
		self::assertArrayNotHasKey( 'imagesizes', $resources[0] );
		self::assertSame( 'https://example.test/wp-content/uploads/preload-9102.webp', $resources[1]['href'] );
		self::assertArrayNotHasKey( 'imagesrcset', $resources[1] );
		self::assertArrayNotHasKey( 'imagesizes', $resources[1] );
	}

	/**
	 * @testdox Should preload on a non-front page that contains the hero shortcode.
	 */
	public function test_preload_follows_shortcode_on_non_front_page(): void {
		$this->queried_object_id = 777;
		$this->current_content   = '<main>[akb_hero class="campaign"]</main>';
		$this->settings          = $this->settings_with_ids( 9001, 9002 );

		$resources = ( new HomeHero() )->inject_preload( array() );

		self::assertCount( 2, $resources );
		self::assertSame( 'https://example.test/wp-content/uploads/preload-9001.avif', $resources[0]['href'] );
	}

	/**
	 * @testdox Should not preload on the front page when its content lacks the shortcode.
	 */
	public function test_preload_skips_current_page_without_shortcode(): void {
		$this->current_content = '<main><h1>Portada sin hero</h1></main>';
		$this->settings        = $this->settings_with_ids( 9001, 9002 );
		$existing              = array(
			array(
				'href' => 'https://example.test/existing.css',
				'as'   => 'style',
			),
		);

		$resources = ( new HomeHero() )->inject_preload( $existing );

		self::assertSame( $existing, $resources );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function settings_with_ids( int $desktop_id, int $mobile_id ): array {
		return array(
			'version'  => Settings::SCHEMA_VERSION,
			'slides'   => array(
				array_merge(
					Settings::default_slide(),
					array(
						'desktop_id' => $desktop_id,
						'mobile_id'  => $mobile_id,
						'is_active'  => true,
					)
				),
			),
			'carousel' => Settings::default_carousel(),
		);
	}
}
