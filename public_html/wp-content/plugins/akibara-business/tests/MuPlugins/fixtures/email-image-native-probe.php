<?php
/** Test-only isolated process: use the installed WordPress metadata reader, without Brain Monkey. */
declare( strict_types = 1 );

define( 'ABSPATH', rtrim( $argv[1], '/' ) . '/' );
define( 'WPINC', 'wp-includes' );
define( 'KB_IN_BYTES', 1024 );
define( 'MB_IN_BYTES', 1024 * KB_IN_BYTES );
define( 'GB_IN_BYTES', 1024 * MB_IN_BYTES );
ini_set( 'display_errors', 'stderr' );
function add_shortcode( $tag, $callback ): void {}
require ABSPATH . WPINC . '/load.php';
require ABSPATH . WPINC . '/functions.php';
require ABSPATH . WPINC . '/media.php';
require ABSPATH . 'wp-content/plugins/akibara-business/src/Modules/SeoSocial/EmailImageDerivatives.php';

$source = $argv[2];
$target = $argv[3];
$before = hash_file( 'sha256', $source );
$convert = new ReflectionMethod( Akibara\Business\Modules\SeoSocial\EmailImageDerivatives::class, 'convert' );
$convert->setAccessible( true );
$result = $convert->invoke( null, $source, $target );
echo json_encode(
	array(
		'php' => PHP_VERSION,
		'native' => getimagesize( $source ),
		'core' => wp_getimagesize( $source ),
		'converted' => $result,
		'jpeg' => $result ? getimagesize( $target ) : false,
		'original_unchanged' => $before === hash_file( 'sha256', $source ),
		'temporaries' => glob( dirname( $target ) . '/.email-*' ),
	)
);
