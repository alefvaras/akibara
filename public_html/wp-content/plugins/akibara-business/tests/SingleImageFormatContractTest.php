<?php
/**
 * Contract for keeping product images free of the OLD local JPEG sidecar machinery.
 *
 * Doctrina vigente (R11-portada-avif-en-correos, 2026-09-20): la capa antigua
 * —`EmailSafeImages`/`OgImageSafe`, un `.email.jpg` por adjunto, placeholder y
 * conversor propio— sigue prohibida. La ÚNICA derivada JPEG sancionada es la
 * hermana `-og.jpg` de `/uploads/portadas/` que produce
 * `SeoSocial\SocialImageDerivatives` (1.106, S-fa09cca0), y los ganchos que
 * pasan por ella —`rank_math/opengraph/{red}/image_array` (SeoSocial),
 * `woocommerce_order_item_thumbnail` (EmailBrand), `email_url()` en BackInStock
 * y SeriesFollow; Brevo la recibe por el og:image— son legítimos. Hasta 2026-09-20 este candado
 * prohibía esos dos nombres de gancho, pero estaba ciego: SeoSocial los
 * registraba por interpolación y pasaba igual.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests;

use PHPUnit\Framework\TestCase;

final class SingleImageFormatContractTest extends TestCase {

	private function plugin_root(): string {
		return dirname( __DIR__ );
	}

	private function source_tree(): string {
		$source   = (string) file_get_contents( $this->plugin_root() . '/akibara-business.php' );
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $this->plugin_root() . '/src' )
		);

		foreach ( $iterator as $file ) {
			if ( $file->isFile() && 'php' === $file->getExtension() ) {
				$source .= "\n" . (string) file_get_contents( $file->getPathname() );
			}
		}

		return $source;
	}

	/**
	 * @testdox Runtime must not contain JPEG sidecar generators, consumers, hooks, or placeholder assets.
	 */
	public function test_runtime_has_no_jpeg_sidecar_layer(): void {
		$removed_paths = array(
			'/src/Modules/EmailSafeImages/EmailSafeImages.php',
			'/src/Modules/EmailSafeImages/EmailSafeImageResolver.php',
			'/src/Modules/EmailSafeImages/SafeJpegUrlResolver.php',
			'/src/Modules/EmailSafeImages/GdImageConverter.php',
			'/src/Modules/EmailSafeImages/ImageConverterInterface.php',
			'/src/Modules/OgImageSafe/OgImageSafe.php',
			'/assets/img/email-placeholder.jpg',
		);
		$forbidden_tokens = array(
			'Modules\\EmailSafeImages',
			'Modules\\OgImageSafe',
			'EmailSafeImages',
			'EmailSafeImageResolver',
			'SafeJpegUrlResolver',
			'GdImageConverter',
			'ImageConverterInterface',
			'OgImageSafe',
			'.email.jpg',
			'email-placeholder.jpg',
			"['email_safe_images']",
			"['og_image_safe']",
			'to_safe_jpeg_url',
			'purge_attachment_cache',
		);
		$source_tree = $this->source_tree();

		foreach ( $removed_paths as $path ) {
			self::assertFileDoesNotExist( $this->plugin_root() . $path, "Removed JPEG layer file must stay absent: {$path}" );
		}

		foreach ( $forbidden_tokens as $token ) {
			self::assertStringNotContainsString( $token, $source_tree, "Runtime must not reintroduce JPEG sidecar token: {$token}" );
		}
	}
}
