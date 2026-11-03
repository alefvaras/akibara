<?php
/**
 * El filtro de user-agent del registro de búsquedas.
 *
 * El dueño lee «Los más buscados» y «sin resultado» como la voz del cliente
 * (qué pedir, qué falta en la tienda). Una sonda propia que se cuele en el
 * qlog se confunde con una demanda real (2026-09-01): por eso `akibara-qa`
 * es marca de bot igual que curl o HeadlessChrome, y un navegador real sigue
 * pasando.
 *
 * @package Akibara\Search\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Search\Tests;

use Akibara\Search\QueryLogger;
use PHPUnit\Framework\TestCase;

final class QueryLoggerBotFilterTest extends TestCase {

	/**
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public static function agentes(): array {
		return array(
			'curl'                  => array( 'curl/8.7.1', true ),
			'playwright headless'   => array( 'Mozilla/5.0 (Macintosh) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/128.0 Safari/537.36', true ),
			'sonda propia marcada'  => array( 'AkibaraQA/1.0 (+sonda; no contar)', true ),
			'sonda propia móvil'    => array( 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile/15E148 Safari/604.1 akibara-qa', true ),
			'googlebot'             => array( 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', true ),
			'iphone real'           => array( 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1', false ),
			'chrome escritorio'     => array( 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36', false ),
			'vacío (sin filtro)'    => array( '', false ),
		);
	}

	/**
	 * @dataProvider agentes
	 * @testdox Clasifica el user-agent: $_dataName
	 */
	public function test_clasifica_el_user_agent( string $user_agent, bool $es_bot ): void {
		self::assertSame( $es_bot, QueryLogger::looks_like_bot( $user_agent ) );
	}
}
