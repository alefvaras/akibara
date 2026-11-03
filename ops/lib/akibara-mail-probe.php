<?php
/**
 * Sonda del transporte de correo. La ejecuta ops/akibara-prod-mail-health.sh con
 * `wp eval-file` en producción. Solo lee y autentica contra el SMTP: NO envía nada.
 *
 * Vive en un archivo aparte porque el escapado de barras invertidas de un `wp eval`
 * inline a través de ssh+bash rompió el namespace Akibara\HostingerMail (2026-08-21).
 * Sin declare(strict_types): `wp eval-file` evalúa el cuerpo y la declaración ya no sería
 * la primera sentencia (fatal).
 */

$env     = wp_get_environment_type();
$enabled = defined( 'AKIBARA_HOSTINGER_MAIL_ENABLED' ) && true === AKIBARA_HOSTINGER_MAIL_ENABLED;
$hook    = class_exists( 'Akibara\\HostingerMail\\Transport' )
	&& false !== has_action( 'phpmailer_init', array( 'Akibara\\HostingerMail\\Transport', 'configure_phpmailer' ) );

echo 'env=', $env, ' enabled=', var_export( $enabled, true ), ' hook=', var_export( $hook, true ), "\n";
if ( ! $hook ) {
	return;
}

require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';

$mailer = new PHPMailer\PHPMailer\PHPMailer( true );
do_action_ref_array( 'phpmailer_init', array( &$mailer ) );
echo 'transport=', $mailer->Mailer, ' host=', $mailer->Host, ':', $mailer->Port, ' secure=', $mailer->SMTPSecure, "\n";

try {
	$mailer->smtpConnect();
	$mailer->smtpClose();
	echo "smtp=auth-ok\n";
} catch ( Throwable $e ) {
	echo 'smtp=FAIL ', $e->getMessage(), "\n";
}
