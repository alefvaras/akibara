<?php
/**
 * Negociación de contenido: parser de `Accept` (release 1.108.33).
 *
 * El caso que justifica todo el fichero es el primero. La cabecera REAL de
 * Chrome termina en `*​/*;q=0.8`, así que técnicamente acepta `text/markdown`
 * con q=0.8. Una implementación por substring —o una que ordene sólo por q sin
 * mirar especificidad— le sirve markdown a todos los navegadores del mundo.
 *
 * @package Akibara\Business\Tests
 */

declare(strict_types=1);

namespace Akibara\Business\Tests\Modules\AgentReadiness;

use Akibara\Business\Modules\AgentReadiness\AcceptParser;
use PHPUnit\Framework\TestCase;

final class AcceptParserTest extends TestCase {

	private const OFRECIDOS = array( 'text/html', 'text/markdown' );

	/**
	 * @dataProvider casos
	 */
	public function test_elige_la_representacion_correcta( string $cabecera, ?string $esperado, string $porque ): void {
		$this->assertSame( $esperado, AcceptParser::mejor( $cabecera, self::OFRECIDOS ), $porque );
	}

	/**
	 * @return array<string, array{0:string,1:?string,2:string}>
	 */
	public static function casos(): array {
		return array(
			'Chrome real no puede recibir markdown' => array(
				'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
				'text/html',
				'Su */*;q=0.8 acepta markdown; gana text/html por ser el rango más específico.',
			),
			'agente que pide markdown a secas' => array(
				'text/markdown',
				'text/markdown',
				'Petición explícita y sin competencia.',
			),
			'agente con fallback a html' => array(
				'text/markdown, text/html;q=0.8',
				'text/markdown',
				'Markdown va a q=1 implícito y html a 0.8.',
			),
			'preferencia invertida' => array(
				'text/html, text/markdown;q=0.9',
				'text/html',
				'Aquí el cliente prefiere html; respetarlo es la mitad del contrato.',
			),
			'markdown rechazado con q=0' => array(
				'text/html, text/markdown;q=0',
				'text/html',
				'q=0 es rechazo explícito, no preferencia baja.',
			),
			'cliente que sólo acepta un tipo que no servimos' => array(
				'application/pdf',
				null,
				'Único caso donde corresponde 406.',
			),
			'comodín total' => array(
				'*/*',
				'text/html',
				'Sin preferencia, manda el orden del servidor: html primero.',
			),
			'cabecera ausente' => array(
				'',
				'text/html',
				'RFC 9110: sin Accept se acepta cualquier cosa.',
			),
			'cabecera ilegible' => array(
				'basura sin barra',
				'text/html',
				'Degradar a la representación normal, nunca 406 por un header roto.',
			),
			'comodín de subtipo' => array(
				'text/*',
				'text/html',
				'text/* casa con los dos; desempata la preferencia del servidor.',
			),
			'q mayor en el comodín no gana al específico' => array(
				'text/markdown;q=0.5, */*;q=1.0',
				'text/html',
				'html recibe q=1.0 por el comodín y markdown 0.5: gana html por q.',
			),
			'espacios y mayúsculas' => array(
				'  TEXT/MARKDOWN ;  Q=1.0 ',
				'text/markdown',
				'El media type y los parámetros no distinguen mayúsculas.',
			),
			'q fuera de rango cae del lado seguro' => array(
				'text/markdown;q=9, text/html',
				'text/html',
				'q=9 es inválido (RFC 9110 acota a 0..1). Se recorta a 1, empata con el q=1 '
					. 'implícito de html y desempata la preferencia del servidor. Ante una cabecera '
					. 'malformada se sirve la representación normal, nunca la exótica.',
			),
		);
	}

	public function test_sin_tipos_ofrecidos_no_hay_nada_que_negociar(): void {
		$this->assertNull( AcceptParser::mejor( 'text/markdown', array() ) );
	}

	public function test_el_orden_de_los_ofrecidos_desempata(): void {
		// Mismo header, distinta preferencia del servidor.
		$this->assertSame( 'text/markdown', AcceptParser::mejor( '*/*', array( 'text/markdown', 'text/html' ) ) );
		$this->assertSame( 'text/html', AcceptParser::mejor( '*/*', array( 'text/html', 'text/markdown' ) ) );
	}
}
