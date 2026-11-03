<?php
/**
 * Contrato de la detección de fotos.
 *
 * Fija la forma REAL de las metas (ingeniería inversa): ivole_review_image2
 * es UNA FILA POR ADJUNTO (int) y las remotas van en arrays con 'url'. Un
 * adjunto que no es imagen —un video, o uno borrado— jamás vale como foto.
 *
 * @package Akibara\Rewards\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Tests\Reviews;

use Akibara\Rewards\Reviews\Media;
use Akibara\Rewards\Tests\Support\RewardsTestCase;
use Brain\Monkey\Functions;

/**
 * @since 0.2.0
 */
final class MediaTest extends RewardsTestCase {

	/** @var array<int,bool> Mapa attachment → ¿es imagen? */
	private array $images = array();

	protected function setUp(): void {
		parent::setUp();

		$images = &$this->images;
		Functions\when( 'wp_attachment_is' )->alias(
			static function ( string $type, int $attachment_id ) use ( &$images ): bool {
				return 'image' === $type && ( $images[ $attachment_id ] ?? false );
			}
		);
		Functions\when( 'wp_update_post' )->justReturn( 1 );
	}

	public function test_a_local_image_attachment_counts_as_real_photo(): void {
		$this->images[901]                                        = true;
		$this->wpdb->comment_meta[10][ Media::META_LOCAL_IMAGE ] = array( 901 );

		$this->assertTrue( Media::has_real_photo( 10 ) );
	}

	public function test_a_deleted_or_non_image_attachment_does_not_count(): void {
		// La fila existe pero el adjunto ya no es imagen (borrado o video).
		$this->wpdb->comment_meta[10][ Media::META_LOCAL_IMAGE ] = array( 902 );

		$this->assertFalse( Media::has_real_photo( 10 ) );
	}

	public function test_a_remote_image_with_url_counts(): void {
		$this->wpdb->comment_meta[10][ Media::META_REMOTE_IMAGE ] = array( array( 'url' => 'https://x/foto.jpg' ) );

		$this->assertTrue( Media::has_real_photo( 10 ) );
	}

	public function test_attach_photo_appends_a_row_and_reparents(): void {
		$this->images[901] = true;
		$this->images[902] = true;

		$reparented = array();
		Functions\when( 'wp_update_post' )->alias(
			static function ( array $args ) use ( &$reparented ): int {
				$reparented[] = $args;
				return (int) $args['ID'];
			}
		);

		$this->assertTrue( Media::attach_photo( 10, 901, 777 ) );
		$this->assertTrue( Media::attach_photo( 10, 902, 777 ) );

		$this->assertSame(
			array(
				array( 'ID' => 901, 'post_parent' => 777 ),
				array( 'ID' => 902, 'post_parent' => 777 ),
			),
			$reparented,
			'Cada foto se reparenta al producto (comportamiento clonado de CusRev).'
		);

		// UNA FILA POR ADJUNTO — jamás un array serializado.
		$this->assertSame( array( 901, 902 ), $this->wpdb->comment_meta[10][ Media::META_LOCAL_IMAGE ] );

		// Y NADA más: el contador agregado del tercero se retiró, así que
		// adjuntar una foto escribe una sola clave.
		$this->assertSame( array( Media::META_LOCAL_IMAGE ), array_keys( $this->wpdb->comment_meta[10] ) );
	}

	public function test_attach_photo_rejects_non_images(): void {
		$this->assertFalse( Media::attach_photo( 10, 555, 777 ) );
		$this->assertArrayNotHasKey( 10, $this->wpdb->comment_meta );
	}
}
