<?php
/**
 * El guardia del mailer no puede vivir en una clase que extienda `WC_Email`.
 *
 * EL DEFECTO: `mailer_ready()` comprobaba si `WC_Email` se podía cargar, y vivía
 * en `AkibaraEmail`, que empieza por `abstract class AkibaraEmail extends
 * WC_Email`. Llamarlo obligaba a autocargar ese fichero, y el `extends` reventaba
 * antes de ejecutar una sola línea del método — justo en el caso que el guardia
 * quería detectar. Sólo devolvía `true` cuando ya no hacía falta.
 *
 * Dos corridas del resumen de series muertas en producción, las dos «vía WP Cron»:
 *   385728  2026-09-02 00:50:03  Class "WC_Email" not found
 *   396865  2026-09-06 09:20:03  Class "WC_Email" not found
 * La misma acción «vía Async Request» completaba sin problema.
 *
 * POR QUÉ ESTA PRUEBA ES DE FUENTE Y NO DE COMPORTAMIENTO: ninguna prueba puede
 * DES-cargar una clase ya cargada, así que el escenario real —llegar al guardia
 * con `WC_Email` ausente— no es reproducible dentro de PHPUnit. El punto ciego no
 * estaba en el guardia: estaba en LLEGAR al guardia. Lo que sí se puede fijar, y
 * es lo que importa, es la propiedad estructural que lo hacía imposible.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Services;

use Akibara\Business\Services\AkibaraEmail;
use Akibara\Business\Services\MailerBoot;
use PHPUnit\Framework\TestCase;

final class MailerBootTest extends TestCase {

	/** @return string */
	private function fuente( string $relativa ): string {
		return (string) file_get_contents(
			dirname( __DIR__, 2 ) . '/src/' . $relativa
		);
	}

	/**
	 * @testdox MailerBoot no extiende ni implementa nada: por eso siempre se puede autocargar.
	 */
	public function test_el_guardia_no_hereda_de_nadie(): void {
		$fuente = $this->fuente( 'Services/MailerBoot.php' );

		self::assertMatchesRegularExpression(
			'/final class MailerBoot \{/',
			$fuente,
			'Cualquier `extends` o `implements` aquí reintroduce el fatal: para preguntar si'
				. ' WC_Email se puede cargar, esta clase tiene que cargarse SIN WC_Email.'
		);
	}

	/**
	 * @testdox Los dos carriles de correo preguntan al guardia nuevo, no a la clase hija.
	 *
	 * Series y lista de espera compartían el mismo guardia y, por tanto, la misma
	 * bomba. Que el segundo no hubiera estallado todavía era calendario, no
	 * inmunidad: se dispara justo tras un cambio de stock, casi siempre en una
	 * petición de admin donde el mailer ya está cargado.
	 *
	 * @dataProvider carriles
	 */
	public function test_los_carriles_usan_el_guardia_seguro( string $relativa ): void {
		$fuente = $this->fuente( $relativa );
		// Sin comentarios: los docblocks que explican el arreglo nombran justo lo
		// que la prueba prohíbe.
		$fuente = (string) preg_replace( '#/\*.*?\*/|//[^\n]*#s', '', $fuente );

		self::assertStringNotContainsString(
			'AkibaraEmail::mailer_ready',
			$fuente,
			'Llamar al guardia en la clase que extiende WC_Email es el defecto original.'
		);
		self::assertStringNotContainsString(
			'AkibaraEmail::from_mailer',
			$fuente,
			'`from_mailer` es estático en la misma clase: mismo problema idéntico.'
		);
		self::assertStringContainsString( 'MailerBoot::', $fuente );
	}

	/** @return array<string, array{0: string}> */
	public static function carriles(): array {
		return array(
			'avisos de serie'  => array( 'Modules/SeriesFollow/DigestRunner.php' ),
			'lista de espera'  => array( 'Modules/BackInStock/NotificationDispatcher.php' ),
		);
	}

	/**
	 * @testdox La clase base de los correos ya no expone estáticos que se llamen antes de tiempo.
	 *
	 * La regla general, que es la que evita que esto vuelva por otra puerta:
	 * nada que haya que preguntar ANTES de que WooCommerce cargue el mailer puede
	 * vivir en una clase que herede de `WC_Email`.
	 */
	public function test_la_clase_base_no_expone_estaticos(): void {
		$fuente = $this->fuente( 'Services/AkibaraEmail.php' );
		$fuente = (string) preg_replace( '#/\*.*?\*/|//[^\n]*#s', '', $fuente );

		self::assertDoesNotMatchRegularExpression(
			'/public static function/',
			$fuente,
			'Un estático aquí sólo se puede llamar autocargando una hija de WC_Email,'
				. ' que es la carga que revienta cuando WC_Email no está.'
		);
	}

	/**
	 * S-c2a19ef3 (2026-09-19): cada correo de la casa tiene DOS interruptores
	 * —el ajuste de Akibara y el `is_enabled()` de WC— en dos pantallas
	 * distintas, y el panel de Akibara sólo leía el primero. Este método es la
	 * fuente única que ambos paneles (BackInStock y SeriesFollow) consultan
	 * para saber el estado REAL del segundo.
	 *
	 * @testdox `wc_email_enabled()` resuelve el estado real del WC_Email, no el ajuste de Akibara.
	 */
	public function test_wc_email_enabled_resuelve_el_estado_real_del_email(): void {
		$email = new class() extends AkibaraEmail {
			public bool $habilitado = true;

			// phpcs:ignore Squiz.Commenting.FunctionComment.Missing
			public function __construct() {}

			public function is_enabled(): bool {
				return $this->habilitado;
			}

			protected function template_args( bool $plain_text ): array {
				unset( $plain_text );
				return array();
			}

			protected function form_field_copy(): array {
				return array();
			}
		};

		self::assertTrue( MailerBoot::wc_email_enabled( get_class( $email ), static fn () => $email ) );

		$email->habilitado = false;
		self::assertFalse( MailerBoot::wc_email_enabled( get_class( $email ), static fn () => $email ) );
	}

	/**
	 * Sin mailer resoluble no hay nada que avisar todavía —es una carrera de
	 * arranque, no un ajuste apagado— así que el llamador no debe pintar un
	 * aviso de desacuerdo con un `false` que en realidad es «no sé».
	 *
	 * @testdox Sin poder resolver el email, `wc_email_enabled()` devuelve null.
	 */
	public function test_wc_email_enabled_devuelve_null_sin_mailer_resoluble(): void {
		self::assertNull( MailerBoot::wc_email_enabled( AkibaraEmail::class, static fn () => null ) );
	}
}
