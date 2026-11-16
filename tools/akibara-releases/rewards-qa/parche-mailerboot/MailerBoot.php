<?php
/**
 * MailerBoot — «¿está cargable WC_Email?», preguntado desde fuera de WC_Email.
 *
 * POR QUÉ EXISTE COMO CLASE APARTE: esta comprobación vivía en
 * `AkibaraEmail::mailer_ready()`, y `AkibaraEmail extends WC_Email`. Llamar al
 * método obligaba a autocargar esa clase, y su declaración fallaba con
 * `Class "WC_Email" not found` justo en el único caso que la comprobación
 * existía para detectar. El guardia provocaba el fatal que debía evitar
 * (producción: acción 385728, akb_sf_digest_run, 2026-09-02 00:50:03).
 *
 * Esta clase NO extiende nada a propósito. Es la condición de que funcione.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Services;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.110.3
 */
final class MailerBoot {

	/**
	 * ¿Se puede usar `WC_Email` en esta petición?
	 *
	 * @return bool
	 */
	public static function ready(): bool {
		if ( class_exists( 'WC_Email' ) ) {
			return true;
		}

		if ( ! function_exists( 'WC' ) ) {
			return false;
		}

		// Efecto secundario buscado: `WC_Emails::init()` hace el include que el
		// autoloader no sabe hacer.
		WC()->mailer();

		return class_exists( 'WC_Email' );
	}
}
