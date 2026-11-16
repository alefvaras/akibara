<?php
// QA · ¿sigue vivo el transporte de correo tras el renombrado? SOLO LECTURA.
// No manda ni un correo: sólo comprueba que la clase está cargada, que el
// transporte se declara habilitado y que engancha PHPMailer.
//
//   ssh akibara 'cd ~/domains/akibara.cl/public_html && /usr/local/bin/wp eval-file - --path=.' < este-fichero
$clase = 'Akibara\\HostingerMail\\Transport';

printf( "fichero vivo:        %s\n", file_exists( WPMU_PLUGIN_DIR . '/akibara-correo-smtp.php' ) ? 'akibara-correo-smtp.php SI' : 'NO ESTA' );
printf( "nombre viejo:        %s\n", file_exists( WPMU_PLUGIN_DIR . '/akibara-hostinger-mail.php' ) ? 'TODAVIA EXISTE (mal)' : 'no existe (bien)' );
printf( "clase cargada:       %s\n", class_exists( $clase ) ? 'SI' : 'NO' );

// OJO: is_enabled() es PRIVADO. Llamarlo desde fuera revienta y el fatal se come
// toda la salida anterior. Se consulta por Reflection, que es lectura pura.
if ( class_exists( $clase ) && method_exists( $clase, 'is_enabled' ) ) {
	$m = new ReflectionMethod( $clase, 'is_enabled' );
	$m->setAccessible( true );
	printf( "is_enabled():        %s\n", var_export( $m->invoke( null ), true ) );
}

printf( "hook phpmailer_init: %s\n", has_action( 'phpmailer_init' ) ? 'enganchado' : 'AUSENTE' );
printf( "hook wp_mail_from:   %s\n", has_filter( 'wp_mail_from' ) ? 'enganchado' : 'ausente' );

// El centinela: ¿mira ya el nombre nuevo y encuentra su copia de rescate?
$ref = new ReflectionClass( 'Akibara\\MailTransportWatch\\Watch' );
echo "centinela:           clase presente\n";
foreach ( array( 'TARGET', 'BACKUP_DIR' ) as $k ) {
	if ( $ref->hasConstant( $k ) ) {
		printf( "  %-10s = %s\n", $k, (string) $ref->getConstant( $k ) );
	}
}
