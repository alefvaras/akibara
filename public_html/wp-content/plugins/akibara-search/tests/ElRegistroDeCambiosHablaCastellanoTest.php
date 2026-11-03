<?php
/**
 * El «Registro de cambios» del panel le habla al dueño en castellano.
 *
 * La columna «Acción» traducía cinco códigos; los otros veinte —todos los que
 * escribe la máquina— salían como `receipt_reproof_rechazado` o
 * `serving_recovery`. Y en el detalle del guardado, `relaxed_notice` aparecía
 * como slug crudo porque faltaba su etiqueta (hallazgo S-516dbffe, 2026-09-19).
 *
 * La fuente de verdad es el CÓDIGO: la prueba recorre `src/` buscando cada
 * código literal que se le pasa a `AuditLog::record()` y exige su frase.
 *
 * @package Akibara\Search\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Search\Tests;

use Akibara\Search\SearchAdminSection;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

require_once dirname( __DIR__ ) . '/src/SearchAdminSection.php';

final class ElRegistroDeCambiosHablaCastellanoTest extends TestCase {

	/** @return array<int,string> */
	private static function codigos_que_se_escriben(): array {
		$codigos  = array();
		$ficheros = array_merge(
			(array) glob( dirname( __DIR__ ) . '/src/*.php' ),
			(array) glob( dirname( __DIR__ ) . '/src/*/*.php' )
		);
		foreach ( $ficheros as $fichero ) {
			$fuente = (string) file_get_contents( (string) $fichero );
			// `AuditLog::record( 'codigo'` en una línea o con el código en la siguiente,
			// y el ternario de SearchAdminSection (`$force ? 'a' : 'b'`).
			if ( preg_match_all( "/AuditLog::record\\(\\s*(?:\\\$\\w+\\s*\\?\\s*)?'([a-z_]+)'(?:\\s*:\\s*'([a-z_]+)')?/", $fuente, $m ) ) {
				$codigos = array_merge( $codigos, $m[1], array_filter( $m[2] ) );
			}
		}
		$codigos = array_values( array_unique( $codigos ) );
		sort( $codigos );

		return $codigos;
	}

	/** @testdox Cada código que el plugin escribe en el registro tiene su frase en castellano. */
	public function test_cada_codigo_tiene_su_frase(): void {
		$etiqueta = new ReflectionMethod( SearchAdminSection::class, 'audit_action_label' );
		$etiqueta->setAccessible( true );
		$panel = ( new ReflectionClass( SearchAdminSection::class ) )->newInstanceWithoutConstructor();

		$codigos = self::codigos_que_se_escriben();
		self::assertGreaterThan( 20, count( $codigos ), 'Si el barrido encuentra pocos, el patrón dejó de ver las llamadas.' );

		$crudos = array();
		foreach ( $codigos as $codigo ) {
			if ( $etiqueta->invoke( $panel, $codigo ) === $codigo ) {
				$crudos[] = $codigo;
			}
		}

		self::assertSame( array(), $crudos, 'Estos códigos se le muestran al dueño tal cual: añade su frase en audit_action_label().' );
	}

	/** @testdox Cada ajuste que entra al diff del guardado tiene su etiqueta, incluido el aviso de editorial. */
	public function test_cada_ajuste_del_diff_tiene_etiqueta(): void {
		$diff = new ReflectionMethod( SearchAdminSection::class, 'settings_changes' );
		$diff->setAccessible( true );
		$panel = ( new ReflectionClass( SearchAdminSection::class ) )->newInstanceWithoutConstructor();

		$fuente = (string) file_get_contents( dirname( __DIR__ ) . '/src/SearchAdminSection.php' );
		// El guardado arma `$after` una vez y lo usa para dos cosas: decidir qué
		// se escribe y el diff del registro (QA-PANEL-6, 2026-09-24).
		self::assertSame( 1, preg_match( "/\\\$changes\\s*=\\s*\\\$this->settings_changes\\(\\s*\\\$before,\\s*\\\$after\\s*\\);/", $fuente ), 'No encuentro la llamada que arma el diff.' );
		self::assertSame( 1, preg_match( "/\\\$after\\s*=\\s*array\\((.*?)\\);/s", $fuente, $m ), 'No encuentro el `$after` que alimenta el diff.' );
		preg_match_all( "/'([a-z_]+)'\\s*=>/", $m[1], $claves );

		self::assertContains( 'relaxed_notice', $claves[1] );

		foreach ( $claves[1] as $clave ) {
			$linea = $diff->invoke( $panel, array( $clave => 'a' ), array( $clave => 'b' ) );
			self::assertStringNotContainsString( $clave . ':', (string) ( $linea[0] ?? '' ), "«{$clave}» sale como slug crudo en el registro." );
		}
	}
}
