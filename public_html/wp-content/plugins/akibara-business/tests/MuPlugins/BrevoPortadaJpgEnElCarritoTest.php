<?php
/** @package Akibara\Business\Tests */
declare( strict_types = 1 );

namespace SendinblueWoocommerce\Managers {
	if ( ! class_exists( CartEventsManagers::class ) ) {
		class CartEventsManagers {
			public function save_anonymous_user_as_blacklisted(): void {}
			public function the_action_function(): void {}
			public function wp_login_action(): void {}
		}
	}
}

namespace Akibara\Business\Tests\MuPlugins {
	use Akibara\Business\Modules\SeoSocial\EmailImageDerivatives;
	use Brain\Monkey;
	use Brain\Monkey\Functions;
	use PHPUnit\Framework\TestCase;

	final class BrevoPortadaJpgEnElCarritoTest extends TestCase {
		private string $root;
		private array $transients = array();
		private array $scheduled = array();
		private const BASE = 'https://akibara.cl/wp-content/uploads/';
		private const ENDPOINT = 'https://in-automate.brevo.com/api/v2/trackEvent';

		protected function setUp(): void {
			parent::setUp();
			Monkey\setUp();
			$this->root = sys_get_temp_dir() . '/akb-email-test-' . uniqid();
			mkdir( $this->root . '/2026/10', 0777, true );
			$this->reset_request();
			Functions\when( 'wp_get_upload_dir' )->justReturn( array( 'basedir' => $this->root, 'baseurl' => rtrim( self::BASE, '/' ) ) );
			Functions\when( 'content_url' )->alias( fn( $path ) => 'https://akibara.cl/wp-content' . $path );
			Functions\when( 'wp_mkdir_p' )->alias( fn( $dir ) => mkdir( $dir, 0777, true ) );
			Functions\when( 'wp_convert_hr_to_bytes' )->justReturn( 512 * 1024 * 1024 );
			Functions\when( 'get_transient' )->alias( fn( $key ) => $this->transients[ $key ] ?? false );
			Functions\when( 'set_transient' )->alias( function ( $key, $value ) { $this->transients[ $key ] = $value; return true; } );
			Functions\when( 'delete_transient' )->alias( function ( $key ) { unset( $this->transients[ $key ] ); return true; } );
			Functions\when( 'wp_next_scheduled' )->justReturn( false );
			Functions\when( 'wp_schedule_single_event' )->alias( function ( $time, $hook, $args ) { $this->scheduled[] = array( $time, $hook, $args ); return true; } );
			Functions\when( 'wp_json_encode' )->alias( fn( $data, $flags = 0 ) => json_encode( $data, $flags ) );
			if ( ! function_exists( 'akibara_brevo_imagenes_del_evento' ) ) {
				require_once dirname( __DIR__, 4 ) . '/mu-plugins/akibara-brevo-portada-jpg-en-el-carrito.php';
			}
		}

		protected function tearDown(): void {
			$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->root, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
			foreach ( $files as $file ) {
				$file->isDir() && ! $file->isLink() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
			}
			rmdir( $this->root );
			Monkey\tearDown();
			parent::tearDown();
		}

		private function reset_request(): void {
			foreach ( array( 'conversions' => 0, 'resolved' => array() ) as $name => $value ) {
				$property = new \ReflectionProperty( EmailImageDerivatives::class, $name );
				$property->setAccessible( true );
				$property->setValue( null, $value );
			}
		}

		private function source( string $name = 'kagurabachi10.avif', int $width = 400, int $height = 587 ): string {
			$image = imagecreatetruecolor( $width, $height );
			imagefill( $image, 0, 0, imagecolorallocate( $image, 190, 30, 40 ) );
			$path = $this->root . '/2026/10/' . $name;
			str_ends_with( $name, '.webp' ) ? imagewebp( $image, $path ) : imageavif( $image, $path );
			imagedestroy( $image );
			return self::BASE . '2026/10/' . $name;
		}

		private function file( string $url ): string {
			return $this->root . '/' . substr( $url, strlen( self::BASE ) );
		}

		private function payload( string $image ): array {
			return array( 'method' => 'POST', 'blocking' => false, 'timeout' => 5, 'headers' => array( 'ma-key' => 'fake-test-key' ), 'body' => json_encode( array(
				'event' => 'cart_updated', 'email' => 'qa@example.invalid', 'event_id' => 'qa-local', 'properties' => (object) array(),
				'eventdata' => array( 'data' => array( 'total' => 14990.0, 'tax' => 2393, 'url' => 'https://akibara.cl/carrito/?test=1', 'items' => array( array( 'id' => 28046, 'name' => 'Kagurabachi 10', 'image' => $image, 'quantity' => 1, 'price' => 14990 ) ) ) ),
			), JSON_PRESERVE_ZERO_FRACTION ) );
		}

		/** @testdox Monthly AVIF and WebP are real bounded JPEGs and retain aspect ratio. */
		public function test_monthly_images(): void {
			foreach ( array( 'new.avif', 'Cover.WEBP.webp' ) as $name ) {
				$url = EmailImageDerivatives::url( $this->source( $name, 800, 1200 ) );
				$file = $this->file( $url );
				self::assertStringEndsWith( '.jpg', $url );
				self::assertSame( array( 427, 640, IMAGETYPE_JPEG ), array_slice( getimagesize( $file ), 0, 3 ) );
				self::assertLessThanOrEqual( 262144, filesize( $file ) );
				self::assertInstanceOf( \GdImage::class, imagecreatefromjpeg( $file ) );
			}
			self::assertSame( array(), glob( $this->root . '/akibara-email/.email-*' ) );
		}

		/** @testdox Query, crop and upload share a reusable JPEG without touching the source. */
		public function test_cache_and_upload(): void {
			$source = $this->source();
			$crop = $this->source( 'kagurabachi10-200x300.avif', 200, 300 );
			$hash = hash_file( 'sha256', $this->file( $source ) );
			Functions\when( 'wp_get_attachment_url' )->justReturn( $source );
			$metadata = array( 'file' => '2026/10/kagurabachi10.avif', 'width' => 400 );
			self::assertSame( $metadata, EmailImageDerivatives::on_upload( $metadata, 28047 ) );
			$url = EmailImageDerivatives::url( $source );
			$mtime = filemtime( $this->file( $url ) );
			$this->reset_request();
			self::assertSame( $url, EmailImageDerivatives::url( $source . '?version=2#preview' ) );
			self::assertSame( $url, EmailImageDerivatives::url( $crop ) );
			self::assertSame( $mtime, filemtime( $this->file( $url ) ) );
			self::assertSame( $hash, hash_file( 'sha256', $this->file( $source ) ) );
		}

		/** @testdox A changed source gets a new URL; damaged JPEG cache is repaired. */
		public function test_repair_and_invalidation(): void {
			$source = $this->source();
			$url = EmailImageDerivatives::url( $source );
			file_put_contents( $this->file( $url ), 'broken jpeg' );
			$this->reset_request();
			self::assertSame( $url, EmailImageDerivatives::url( $source ) );
			self::assertSame( IMAGETYPE_JPEG, getimagesize( $this->file( $url ) )[2] );
			touch( $this->file( $source ), time() + 10 );
			clearstatcache();
			$this->reset_request();
			self::assertNotSame( $url, EmailImageDerivatives::url( $source ) );
			self::assertFileExists( $this->file( $url ), 'Old emails retain their immutable image.' );
		}

		/** @testdox Invalid source and budget exhaustion use a compatible fallback and avoid repeated decoding. */
		public function test_limits_and_failure_cache(): void {
			$url = $this->source();
			file_put_contents( $this->file( $url ), 'invalid avif' );
			$fallback = EmailImageDerivatives::url( $url );
			self::assertStringEndsWith( 'akb-email-logo-banner.png', $fallback );
			self::assertCount( 1, $this->transients );
			$this->reset_request();
			self::assertSame( $fallback, EmailImageDerivatives::url( $url ) );
			for ( $n = 0; $n < 3; ++$n ) {
				self::assertStringEndsWith( '.jpg', EmailImageDerivatives::url( $this->source( "cover{$n}.avif" ) ) );
			}
			self::assertSame( $fallback, EmailImageDerivatives::url( $this->source( 'last.avif' ) ) );
			self::assertCount( 1, $this->scheduled );
			self::assertSame( 'akibara_brevo_email_image', $this->scheduled[0][1] );
		}

		/** @testdox Only the exact cart endpoint and image field change, including AJAX-built payloads. */
		public function test_payload_preserves_all_commerce_fields(): void {
			$args = $this->payload( $this->source() );
			$adapted = akibara_brevo_imagenes_del_evento( $args, self::ENDPOINT );
			$before = json_decode( $args['body'] );
			$after = json_decode( $adapted['body'] );
			self::assertStringEndsWith( '.jpg', $after->eventdata->data->items[0]->image );
			$after->eventdata->data->items[0]->image = $before->eventdata->data->items[0]->image;
			self::assertEquals( $before, $after );
			unset( $args['body'], $adapted['body'] );
			self::assertSame( $args, $adapted );
		}

		/** @testdox Unrelated or malformed requests pass through byte for byte. */
		public function test_unrelated_requests(): void {
			$args = $this->payload( $this->source() );
			foreach ( array( self::ENDPOINT . '?x=1', self::ENDPOINT . '/extra', str_replace( 'brevo.com', 'brevo.com.evil.test', self::ENDPOINT ) ) as $url ) {
				self::assertSame( $args, akibara_brevo_imagenes_del_evento( $args, $url ) );
			}
			foreach ( array( '{bad json', '[]', '{"event":"cart_deleted"}', str_replace( 'cart_updated', 'order_completed', $args['body'] ), array( 'multipart' ) ) as $body ) {
				$test = array_replace( $args, array( 'body' => $body ) );
				self::assertSame( $test, akibara_brevo_imagenes_del_evento( $test, self::ENDPOINT ) );
			}
			$args['method'] = 'GET';
			self::assertSame( $args, akibara_brevo_imagenes_del_evento( $args, self::ENDPOINT ) );
		}

		/** @testdox Foreign hosts, traversal and compatible formats never trigger conversion. */
		public function test_url_scope(): void {
			foreach ( array( 'https://evil.test/wp-content/uploads/2026/10/x.avif', self::BASE . '../x.avif', self::BASE . '%2e%2e/x.avif', self::BASE . '2026%00/x.avif', self::BASE . 'x.jpg', self::BASE . 'x.png', 'file:///etc/passwd.avif' ) as $url ) {
				self::assertSame( $url, EmailImageDerivatives::url( $url ) );
			}
			self::assertDirectoryDoesNotExist( $this->root . '/akibara-email' );
		}

		/** @testdox Registration hooks the final payload without a global attachment image filter. */
		public function test_registration(): void {
			akibara_brevo_registrar_imagenes();
			self::assertSame( 20, has_filter( 'http_request_args', 'akibara_brevo_imagenes_del_evento' ) );
			self::assertFalse( has_filter( 'wp_get_attachment_image_src', 'akibara_brevo_portada_en_jpg' ) );
		}
	}
}
