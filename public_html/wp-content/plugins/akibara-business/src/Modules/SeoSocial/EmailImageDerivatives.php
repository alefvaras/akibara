<?php
/**
 * JPEG acotado y reutilizable para las imágenes de correo de WooCommerce.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\SeoSocial;

defined( 'ABSPATH' ) || exit;

final class EmailImageDerivatives {
	private const MAX_SIDE = 640;
	private const MAX_BYTES = 262144;
	private const MAX_CONVERSIONS = 3;
	private static int $conversions = 0;
	private static array $resolved = array();

	/**
	 * No descarga URLs: sólo decodifica archivos dentro de uploads del sitio.
	 * Las variantes son inmutables por versión de fuente, para las cachés de correo.
	 */
	public static function url( string $url ): string {
		if ( isset( self::$resolved[ $url ] ) ) {
			return self::$resolved[ $url ];
		}
		$uploads = wp_get_upload_dir();
		$base    = rtrim( (string) $uploads['baseurl'], '/' ) . '/';
		$path    = strtok( $url, '?#' );
		if ( ! is_string( $path ) || ! str_starts_with( $path, $base )
			|| ! preg_match( '/\.(avif|webp)$/i', $path ) ) {
			return $url;
		}
		$relative = rawurldecode( substr( $path, strlen( $base ) ) );
		if ( preg_match( '#(?:^|/)\.\.?(/|$)|[\\\\\x00-\x1f]#', $relative ) ) {
			return $url;
		}
		$root   = realpath( $uploads['basedir'] );
		$source = false !== $root ? realpath( $root . '/' . $relative ) : false;
		if ( false === $root || false === $source || ! str_starts_with( $source, $root . '/' )
			|| ! is_file( $source ) || ! is_readable( $source ) ) {
			return self::$resolved[ $url ] = self::fallback();
		}
		// Brevo sobrescribe la imagen full con get_image() (thumbnail). La copia
		// usa el original cuando existe, compartiendo caché con el precálculo.
		$original = preg_replace( '/-\d+x\d+(?=\.(?:avif|webp)$)/i', '', $relative );
		if ( $original !== $relative ) {
			$full = realpath( $root . '/' . $original );
			if ( false !== $full && str_starts_with( $full, $root . '/' ) && is_file( $full ) && is_readable( $full ) ) {
				$source   = $full;
				$relative = $original;
			}
		}
		$key    = hash( 'sha256', 'v1|' . $relative . '|' . filemtime( $source ) . '|' . filesize( $source ) );
		$dir    = $root . '/akibara-email';
		$target = $dir . '/' . $key . '.jpg';
		$result = $base . 'akibara-email/' . $key . '.jpg';
		// Check the directory too: a symlink must never expose an external file.
		if ( is_dir( $dir ) && realpath( $dir ) !== $dir ) {
			return self::$resolved[ $url ] = self::fallback();
		}
		if ( self::valid_jpeg( $target ) ) {
			return self::$resolved[ $url ] = $result;
		}
		$fail = 'akb_email_img_' . $key;
		if ( get_transient( $fail ) ) {
			return self::$resolved[ $url ] = self::fallback();
		}
		if ( self::$conversions >= self::MAX_CONVERSIONS ) {
			if ( ! wp_next_scheduled( 'akibara_brevo_email_image', array( $path ) ) ) {
				wp_schedule_single_event( time() + 5, 'akibara_brevo_email_image', array( $path ) );
			}
			return self::$resolved[ $url ] = self::fallback();
		}
		++self::$conversions;
		// Before decoding: also prevents repeated fatal failures inside the codec.
		set_transient( $fail, 1, 6 * HOUR_IN_SECONDS );
		if ( ( is_dir( $dir ) || wp_mkdir_p( $dir ) )
			&& realpath( $dir ) === $dir && ! is_link( $target )
			&& self::convert( $source, $target ) ) {
			delete_transient( $fail );
			return self::$resolved[ $url ] = $result;
		}
		return self::$resolved[ $url ] = self::fallback();
	}

	/** Precálculo en la subida: el cliente suele encontrar la copia ya preparada. */
	public static function on_upload( $metadata, $attachment_id ) {
		$url = wp_get_attachment_url( (int) $attachment_id );
		if ( is_string( $url ) ) {
			self::url( $url );
		}
		return $metadata;
	}

	/** Imagen de marca existente si una portada no puede decodificarse. */
	private static function fallback(): string {
		return content_url( '/themes/akibara-gp/assets/img/akb-email-logo-banner.png' );
	}

	/** Cabecera JPEG, dimensiones, peso y marcador de fin; nunca sólo extensión. */
	private static function valid_jpeg( string $file ): bool {
		if ( ! is_readable( $file ) || is_link( $file ) || filesize( $file ) > self::MAX_BYTES ) {
			return false;
		}
		$size = @getimagesize( $file );
		if ( ! is_array( $size ) || IMAGETYPE_JPEG !== ( $size[2] ?? null )
			|| min( $size[0], $size[1] ) < 1 || max( $size[0], $size[1] ) > self::MAX_SIDE ) {
			return false;
		}
		$handle = fopen( $file, 'rb' );
		if ( false === $handle ) {
			return false;
		}
		fseek( $handle, -2, SEEK_END );
		$end = fread( $handle, 2 );
		fclose( $handle );
		return "\xff\xd9" === $end;
	}

	/** Decode limitado, fondo blanco, sin ampliación y publicación atómica. */
	private static function convert( string $source, string $target ): bool {
		$size = @getimagesize( $source );
		if ( ! is_array( $size ) || ! in_array( $size['mime'] ?? '', array( 'image/avif', 'image/webp' ), true )
			|| filesize( $source ) > 20 * 1024 * 1024 ) {
			return false;
		}
		$pixels = (int) $size[0] * (int) $size[1];
		$limit  = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
		if ( $pixels < 1 || $pixels > 25000000
			|| ( $limit > 0 && memory_get_usage( true ) + $pixels * 12 + 16 * 1024 * 1024 > $limit ) ) {
			return false;
		}
		$decode = 'image/avif' === $size['mime'] ? 'imagecreatefromavif' : 'imagecreatefromwebp';
		if ( ! function_exists( $decode ) ) {
			return false;
		}
		$image = null;
		$canvas = null;
		$temp = '';
		try {
			$image = @$decode( $source );
			if ( false === $image ) {
				return false;
			}
			$scale  = min( 1, self::MAX_SIDE / max( $size[0], $size[1] ) );
			$width  = max( 1, (int) round( $size[0] * $scale ) );
			$height = max( 1, (int) round( $size[1] * $scale ) );
			$canvas = imagecreatetruecolor( $width, $height );
			imagefill( $canvas, 0, 0, imagecolorallocate( $canvas, 255, 255, 255 ) );
			imagecopyresampled( $canvas, $image, 0, 0, 0, 0, $width, $height, $size[0], $size[1] );
			$temp = tempnam( dirname( $target ), '.email-' );
			if ( false === $temp || ! imagejpeg( $canvas, $temp, 82 ) || ! self::valid_jpeg( $temp ) ) {
				return false;
			}
			chmod( $temp, 0644 );
			return rename( $temp, $target );
		} catch ( \Throwable $error ) {
			return false;
		} finally {
			if ( $image instanceof \GdImage ) {
				imagedestroy( $image );
			}
			if ( $canvas instanceof \GdImage ) {
				imagedestroy( $canvas );
			}
			if ( is_string( $temp ) && '' !== $temp && is_file( $temp ) ) {
				unlink( $temp );
			}
		}
	}
}
