<?php
/**
 * MailerBoot — arranca el mailer de WooCommerce y resuelve los correos de la casa.
 *
 * ESTA CLASE NO EXTIENDE NADA, Y ESE ES TODO SU MOTIVO DE EXISTIR.
 *
 * El guardia que comprueba si `WC_Email` se puede cargar vivía en
 * {@see AkibaraEmail}, que empieza con `abstract class AkibaraEmail extends
 * WC_Email`. Para llamar a un método estático de esa clase, PHP tiene que
 * autocargar el fichero, y esa carga ejecuta el `extends`. Si `WC_Email` no está
 * cargada —que es EXACTAMENTE el caso que el guardia quería detectar— el fatal
 * salta antes de ejecutar una sola línea del método. El guardia sólo devolvía
 * `true` cuando ya no hacía falta.
 *
 * No es teoría. Dos corridas del resumen de series muertas en producción con
 * `Class "WC_Email" not found`, las dos «vía WP Cron»:
 *
 *   385728   00:50:03  akb_sf_digest_run  failed
 *   396865   09:20:03  akb_sf_digest_run  failed
 *
 * Y la asimetría que lo explica: la misma acción lanzada «vía Async Request»
 * completa sin problema, porque ese arranque ya trae el mailer cargado.
 *
 * Qué se pierde cuando salta: el fatal ocurre ANTES del claim, así que no quema
 * filas ni deja a nadie sin aviso por marcarlo enviado en falso. Pero Action
 * Scheduler no reintenta un fatal, y la línea que reagenda la corrida siguiente
 * está al final del método al que nunca se llega: la cola se queda parada hasta
 * que alguien publique otro tomo.
 *
 * POR QUÉ EL AUTOLOADER NO PUEDE: el de WooCommerce no tiene rama para
 * `wc_email_*` y busca `includes/class-wc-email.php`, mientras el archivo vive en
 * `includes/emails/class-wc-email.php`. Sólo `WC()->mailer()` —o sea
 * `WC_Emails::init()`— hace ese include.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Services;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.115.20
 */
final class MailerBoot {

	/**
	 * ¿Se puede resolver ya un correo de la casa?
	 *
	 * Arranca el mailer antes de mirar nada y vuelve a comprobar después: la
	 * respuesta es «se puede resolver», no «existe WooCommerce».
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

	/**
	 * Resuelve la instancia registrada de un correo de la casa desde el mailer
	 * de WC.
	 *
	 * Lookup por `instanceof` y no por clave: a prueba de colisiones en
	 * `woocommerce_email_classes`. La factory inyectable es la costura de las
	 * pruebas — y es la primera rama a propósito, para que un test nunca tenga
	 * que arrancar WooCommerce entero.
	 *
	 * El tipo de retorno nombra `AkibaraEmail`, que sí extiende `WC_Email`, y aun
	 * así es seguro: PHP sólo resuelve el tipo al RETORNAR, y para entonces
	 * `ready()` ya garantizó que la clase padre está cargada. Lo que no se puede
	 * es tocar esa clase para AVERIGUAR si se puede tocar.
	 *
	 * @param class-string  $class   Clase concreta del correo buscado.
	 * @param callable|null $factory Factory inyectada (tests) o null.
	 * @return AkibaraEmail|null
	 */
	public static function from_mailer( string $class, ?callable $factory = null ): ?AkibaraEmail {
		if ( null !== $factory ) {
			$email = call_user_func( $factory );
			return $email instanceof $class && $email instanceof AkibaraEmail ? $email : null;
		}

		if ( ! self::ready() ) {
			return null;
		}

		foreach ( WC()->mailer()->get_emails() as $email ) {
			if ( $email instanceof $class && $email instanceof AkibaraEmail ) {
				return $email;
			}
		}

		return null;
	}

	/**
	 * El SEGUNDO interruptor de cada correo de la casa: `is_enabled()` del
	 * `WC_Email` en la pantalla de WooCommerce → Correos, distinto del ajuste
	 * de Akibara que prende el módulo.
	 *
	 * Fuente única para que el panel de Akibara pueda avisar cuando los dos
	 * interruptores no coinciden — antes cada módulo mostraba «activo» leyendo
	 * SOLO su propia option, sin saber que el correo estaba apagado en la otra
	 * pantalla. Ya costó tres días sin correo en producción sin que el panel
	 * dijera nada.
	 *
	 * @param class-string  $class   Clase concreta del correo.
	 * @param callable|null $factory Factory inyectada (tests), igual que en {@see self::from_mailer()}.
	 * @return bool|null true/false si se pudo resolver el email; null si el
	 *                    mailer no está cargable todavía (no hay nada que avisar
	 *                    en ese caso, es una carrera de arranque, no un ajuste).
	 */
	public static function wc_email_enabled( string $class, ?callable $factory = null ): ?bool {
		$email = self::from_mailer( $class, $factory );

		return null === $email ? null : $email->is_enabled();
	}
}
