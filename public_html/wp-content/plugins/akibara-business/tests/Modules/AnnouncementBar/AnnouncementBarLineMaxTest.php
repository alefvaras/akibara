<?php
/**
 * Los dos campos de mensajes de la barra llevan el límite por línea que lee el
 * contador de admin-panel.js.
 *
 * QA escribió 300 caracteres el 2026-09-05 y se guardaron sin aviso; en móvil
 * el mensaje se corta con «…». El límite no es `maxlength` (es por LÍNEA y en
 * escritorio cabe más): es un `data-akb-line-max` que el JS convierte en un
 * contador que avisa sin bloquear.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\AnnouncementBar;

use Akibara\Business\Modules\AnnouncementBar\AnnouncementBar;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class AnnouncementBarLineMaxTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'get_option' )->justReturn( '' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * 40 era una línea; desde QA-RV-7 (2026-09-24) la cinta del teléfono admite
	 * dos, y el límite medido a 360px en Chromium y WebKit es 80 (ver `LINE_MAX`).
	 *
	 * @testdox Los dos textareas de mensajes declaran `data-akb-line-max` con el límite de la ayuda (~80).
	 */
	public function test_los_textareas_de_mensajes_llevan_el_limite_por_linea(): void {
		$con_limite = array();
		foreach ( ( new AnnouncementBar() )->get_settings() as $campo ) {
			if ( 'textarea' !== ( $campo['type'] ?? '' ) ) {
				continue;
			}
			$con_limite[ (string) $campo['id'] ] = $campo['custom_attributes']['data-akb-line-max'] ?? null;
			self::assertStringContainsString( '~' . AnnouncementBar::LINE_MAX . ' caracteres por línea', (string) $campo['desc'], 'La ayuda y el contador tienen que decir el mismo número.' );
		}

		self::assertSame(
			array(
				'akibara_announcement_messages'   => AnnouncementBar::LINE_MAX,
				'akibara_announcement_promo_text' => AnnouncementBar::LINE_MAX,
			),
			$con_limite
		);
		self::assertSame( '80', AnnouncementBar::LINE_MAX );
	}
}
