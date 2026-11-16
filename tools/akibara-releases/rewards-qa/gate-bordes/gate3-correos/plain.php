<?php
/**
 * Lee (SIN mutar) el cuerpo plain de los WC_Email propios y cuenta sus enlaces.
 */

use Akibara\Business\Modules\SeriesFollow\SeriesFollowEmail;
use Akibara\Business\Modules\BackInStock\InstockEmail;

WC()->mailer();

$clases = array();
foreach ( WC()->mailer()->get_emails() as $key => $email ) {
	if ( $email instanceof \Akibara\Business\Services\AkibaraEmail ) {
		$clases[ $key ] = $email;
	}
}

echo "== WC_Email propios registrados ==\n";
foreach ( $clases as $key => $email ) {
	echo sprintf(
		"%-34s id=%-24s email_type=%-10s enabled=%s\n",
		$key,
		$email->id,
		$email->get_email_type(),
		var_export( $email->is_enabled(), true )
	);
	echo '   template_plain = ' . $email->template_plain . ' | existe = '
		. ( file_exists( $email->template_base . $email->template_plain ) ? 'SI' : 'NO' ) . "\n";
	echo '   content_type   = ' . $email->get_content_type() . "\n";
	$opciones = $email->get_email_type_options();
	echo '   opciones tipo  = ' . implode( ', ', array_keys( $opciones ) ) . "\n";
}

echo "\n== ¿Multipart disponible en WooCommerce? ==\n";
echo 'class_exists DOMDocument = ' . var_export( class_exists( 'DOMDocument' ), true ) . "\n";

echo "\n== Correos CORE de WooCommerce: su email_type ==\n";
foreach ( WC()->mailer()->get_emails() as $key => $email ) {
	if ( ! $email instanceof \Akibara\Business\Services\AkibaraEmail ) {
		echo sprintf( "%-40s id=%-32s type=%s\n", $key, $email->id, $email->get_email_type() );
	}
}
