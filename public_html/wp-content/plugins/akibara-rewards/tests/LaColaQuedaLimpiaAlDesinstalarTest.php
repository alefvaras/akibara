<?php
/**
 * Desinstalar no puede dejar acciones propias vivas en la cola.
 *
 * Hallazgo S-963bf905: el desinstalador retiraba tres de los nueve hooks que el
 * plugin encola. Las seis que faltaban son justo las que pueden estar PENDIENTES
 * en ese momento —un lote de vencimiento a medias, un aviso encolado, un correo
 * esperando su reintento—: sin el plugin no queda oyente y Action Scheduler las
 * reintenta y falla, en un sitio donde ya nadie las mira.
 *
 * El candado es contra la FUENTE porque `uninstall.php` sólo corre dentro de
 * WordPress al borrar el plugin: lo que se fija es que ningún hook nazca sin su
 * línea de limpieza.
 *
 * @package Akibara\Rewards\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Tests;

use PHPUnit\Framework\TestCase;

final class LaColaQuedaLimpiaAlDesinstalarTest extends TestCase {

	private function raiz(): string {
		return dirname( __DIR__ );
	}

	/** @return array<int, string> Todos los hooks declarados como `const HOOK…`. */
	private function hooks_declarados(): array {
		$hooks    = array();
		$ficheros = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->raiz() . '/src' ) );

		foreach ( $ficheros as $fichero ) {
			if ( ! $fichero->isFile() || 'php' !== $fichero->getExtension() ) {
				continue;
			}
			if ( preg_match_all( "/const\s+HOOK[A-Z_]*\s*=\s*'([a-z0-9_]+)'/", (string) file_get_contents( $fichero->getPathname() ), $m ) ) {
				$hooks = array_merge( $hooks, $m[1] );
			}
		}

		return array_values( array_unique( $hooks ) );
	}

	/** @testdox Cada hook que el plugin encola se retira al desinstalar. */
	public function test_ningun_hook_queda_huerfano(): void {
		$uninstall = (string) file_get_contents( $this->raiz() . '/uninstall.php' );
		$hooks     = $this->hooks_declarados();

		self::assertNotEmpty( $hooks, 'Si no se encuentra ningún hook, el candado no está midiendo nada.' );

		foreach ( $hooks as $hook ) {
			self::assertStringContainsString(
				"as_unschedule_all_actions( '" . $hook . "' )",
				$uninstall,
				sprintf( 'El hook «%s» sobrevive a la desinstalación y fallaría en la cola para siempre.', $hook )
			);
		}
	}

	/** @testdox La limpieza corre ANTES de la guarda de purga: no depende de consentir el borrado. */
	public function test_la_limpieza_no_depende_de_la_purga(): void {
		$uninstall = (string) file_get_contents( $this->raiz() . '/uninstall.php' );

		$posicion_limpieza = strpos( $uninstall, 'as_unschedule_all_actions' );
		// La GUARDA, no la mención del docblock de arriba (que la explica).
		$posicion_guarda   = strpos( $uninstall, "if ( ! defined( 'AKIBARA_REWARDS_ALLOW_WIPE' )" );

		self::assertIsInt( $posicion_limpieza );
		self::assertIsInt( $posicion_guarda );
		self::assertLessThan( $posicion_guarda, $posicion_limpieza, 'Con la guarda delante, desinstalar sin purgar dejaría la cola sucia.' );
	}
}
