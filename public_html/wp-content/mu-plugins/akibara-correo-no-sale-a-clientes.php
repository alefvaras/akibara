<?php
/**
 * Plugin Name: Akibara — Fuera de producción, ningún correo llega a un cliente real
 * Description: Fuera del entorno y dominio de producción, redirige el correo a la bandeja de captura y retira Cc/Bcc reales. Conserva los destinatarios originales en asunto y cabeceras para QA. La validación del dominio se comparte con el transporte; si éste falta, la captura permanece activa.
 * Version: 1.2.0
 * Author: Akibara
 * @package Akibara\MuPlugins
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Bandeja donde aterriza todo el correo fuera de producción.
 *
 * Se puede cambiar con la constante `AKIBARA_CORREO_CAPTURA` en `wp-config.php`
 * o con la variable de entorno del mismo nombre. El dominio por defecto es
 * `.test`, que es TLD reservado: aunque alguien conecte un SMTP real, no existe
 * ningún buzón ahí al que molestar.
 *
 * @return string
 */
function akibara_correo_captura(): string {
	if ( defined( 'AKIBARA_CORREO_CAPTURA' ) && is_string( AKIBARA_CORREO_CAPTURA ) && '' !== AKIBARA_CORREO_CAPTURA ) {
		return (string) AKIBARA_CORREO_CAPTURA;
	}

	$entorno = getenv( 'AKIBARA_CORREO_CAPTURA' );

	return is_string( $entorno ) && '' !== $entorno ? $entorno : 'capturado@akibara.test';
}

/**
 * ¿Esta dirección es ya de pruebas y puede pasar tal cual?
 *
 * Se dejan pasar los TLD reservados por la RFC 2606 (`.test`, `.example`,
 * `.invalid`, `.localhost`) y el dominio de pruebas de la casa. No es una lista
 * blanca de personas: es la lista de sitios donde por definición no hay nadie.
 *
 * @param string $direccion Correo del destinatario.
 * @return bool
 */
function akibara_correo_es_de_pruebas( string $direccion ): bool {
	$dominio = strtolower( (string) substr( strrchr( $direccion, '@' ) ?: '', 1 ) );
	if ( '' === $dominio ) {
		return false;
	}

	foreach ( array( '.test', '.example', '.invalid', '.localhost' ) as $reservado ) {
		if ( str_ends_with( $dominio, $reservado ) ) {
			return true;
		}
	}

	return 'example.com' === $dominio || 'example.org' === $dominio;
}

/**
 * Extrae la dirección de un destinatario en cualquiera de las formas que acepta
 * `wp_mail`: «a@b.cl» o «Nombre <a@b.cl>».
 *
 * @param string $destinatario Entrada cruda.
 * @return string
 */
function akibara_correo_direccion( string $destinatario ): string {
	if ( 1 === preg_match( '/<([^>]+)>/', $destinatario, $coincidencia ) ) {
		return trim( $coincidencia[1] );
	}

	return trim( $destinatario );
}

/**
 * Cabeceras de `wp_mail` como array de UNA cabecera por elemento.
 *
 * Normaliza cadenas y arrays a una cabecera por elemento. Un array recibido
 * también puede contener elementos multilínea; cada uno debe separarse antes
 * de que wp_mail() interprete nombre y valor. Conserva Content-Type y Reply-To
 * como cabeceras independientes para que la captura de correo reciba el mensaje.
 *
 * @param mixed $cabeceras Cabeceras tal como las entrega `wp_mail`.
 * @return array<int, string>
 */
function akibara_correo_cabeceras_en_lineas( $cabeceras ): array {
	if ( ! is_array( $cabeceras ) ) {
		$cabeceras = explode( "\n", str_replace( "\r\n", "\n", (string) $cabeceras ) );
	}

	$lineas = array();
	foreach ( $cabeceras as $cabecera ) {
		// Un elemento de array también puede traer varias líneas dentro: el
		// llamador puede haber mezclado los dos formatos.
		foreach ( explode( "\n", str_replace( "\r\n", "\n", (string) $cabecera ) ) as $linea ) {
			$linea = trim( $linea );
			if ( '' !== $linea ) {
				$lineas[] = $linea;
			}
		}
	}

	return $lineas;
}

/**
 * Exige entorno production y dominio oficial para permitir destinatarios reales.
 * Si falta el transporte que valida los dominios, conserva la captura.
 *
 * @return bool
 */
function akibara_correo_es_la_tienda_de_verdad(): bool {
	if ( 'production' !== wp_get_environment_type() ) {
		return false;
	}

	return class_exists( '\Akibara\HostingerMail\Transport' )
		&& \Akibara\HostingerMail\Transport::is_production_host( (string) home_url() );
}

/**
 * Redirige el correo a la bandeja de captura fuera de producción.
 *
 * @param array<string, mixed> $args Argumentos de `wp_mail`.
 * @return array<string, mixed>
 */
function akibara_correo_no_sale_a_clientes( array $args ): array {
	if ( akibara_correo_es_la_tienda_de_verdad() ) {
		return $args;
	}

	$destinatarios = $args['to'] ?? array();
	$destinatarios = is_array( $destinatarios ) ? $destinatarios : preg_split( '/,\s*/', (string) $destinatarios );
	$destinatarios = array_values( array_filter( (array) $destinatarios ) );

	$reales = array();
	foreach ( $destinatarios as $destinatario ) {
		$direccion = akibara_correo_direccion( (string) $destinatario );
		if ( '' !== $direccion && ! akibara_correo_es_de_pruebas( $direccion ) ) {
			$reales[] = $direccion;
		}
	}

	// Las copias se miran ANTES de decidir si hay que intervenir, y ésa es la
	// mitad que faltaba. `wp_mail()` honra `Cc:` y `Bcc:` tal cual, así que un
	// `to` de pruebas con un `Bcc` a una persona real —la forma exacta en que
	// WooCommerce arma el aviso al dueño— se colaba entero por el retorno
	// temprano de «todos eran de prueba»: medido en Mailpit, la copia oculta
	// salía intacta. La pregunta correcta no es «¿el destinatario es real?»
	// sino «¿hay UNA sola dirección real en cualquiera de los tres campos?».
	$cabeceras     = akibara_correo_cabeceras_en_lineas( $args['headers'] ?? array() );
	$copias        = array();
	$copias_reales = array();

	foreach ( $cabeceras as $indice => $cabecera ) {
		if ( 1 !== preg_match( '/^\s*(?:cc|bcc)\s*:(.*)$/i', (string) $cabecera, $coincidencia ) ) {
			continue;
		}

		unset( $cabeceras[ $indice ] );

		// Una cabecera `Cc:` sin valor no es una copia. Anotarla haría leer
		// «había copias» en la bandeja donde no las había.
		$lista = trim( $coincidencia[1] );
		if ( '' === $lista ) {
			continue;
		}

		$copias[] = $lista;
		foreach ( (array) preg_split( '/,\s*/', $lista ) as $copia ) {
			$direccion = akibara_correo_direccion( (string) $copia );
			if ( '' !== $direccion && ! akibara_correo_es_de_pruebas( $direccion ) ) {
				$copias_reales[] = $direccion;
			}
		}
	}
	$cabeceras = array_values( $cabeceras );

	if ( array() === $reales && array() === $copias_reales ) {
		return $args; // Ni destinatario ni copias salen de los dominios reservados.
	}

	if ( array() !== $reales ) {
		// El destinatario original va en el asunto Y en una cabecera. En el asunto
		// porque es lo único que se ve en la lista de la bandeja sin abrir el correo,
		// que es como QA revisa una tanda de doce.
		$original        = implode( ', ', $reales );
		$args['to']      = akibara_correo_captura();
		$args['subject'] = '[para ' . $original . '] ' . (string) ( $args['subject'] ?? '' );
		$cabeceras[]     = 'X-Akibara-Destinatario-Original: ' . $original;
	} else {
		// Sólo las copias eran reales: el `to` ya era de pruebas y se respeta
		// tal cual... salvo que no hubiera ninguno, en cuyo caso un correo sin
		// destinatario se perdería en silencio justo cuando hay algo que ver.
		if ( array() === $destinatarios ) {
			$args['to'] = akibara_correo_captura();
		}
		$args['subject'] = '[copia retirada para ' . implode( ', ', $copias_reales ) . '] ' . (string) ( $args['subject'] ?? '' );
	}

	if ( array() !== $copias ) {
		$cabeceras[] = 'X-Akibara-Copias-Originales: ' . implode( ', ', $copias );
	}

	$cabeceras[]     = 'X-Akibara-Entorno: ' . wp_get_environment_type();
	$args['headers'] = $cabeceras;

	return $args;
}

add_filter( 'wp_mail', 'akibara_correo_no_sale_a_clientes', PHP_INT_MAX );
