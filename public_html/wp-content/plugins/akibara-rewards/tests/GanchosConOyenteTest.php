<?php
/**
 * Todo aviso que el programa dispara tiene quien lo escuche.
 *
 * El 2026-09-13 aparecieron siete `do_action( 'akb_rewards_…' )` sin un solo
 * oyente, varios con el comentario «las alertas del panel escuchan acá». Un
 * aviso que nadie escucha es código muerto que además miente: quien lee el
 * código cree que el dueño se entera.
 *
 * Esta prueba lee el fuente, no ejecuta: cada nombre literal disparado tiene
 * que aparecer también en un `add_action` del propio plugin.
 *
 * @package Akibara\Rewards\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Tests;

use PHPUnit\Framework\TestCase;

/**
 * @since 0.4.1
 */
final class GanchosConOyenteTest extends TestCase {

	/** @testdox Cada aviso akb_rewards_* que se dispara tiene un oyente en el plugin. */
	public function test_cada_aviso_disparado_tiene_oyente(): void {
		$fuente   = dirname( __DIR__ ) . '/src';
		$ficheros = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $fuente, \FilesystemIterator::SKIP_DOTS ) );

		$disparados = array();
		$oyentes    = array();
		foreach ( $ficheros as $fichero ) {
			if ( 'php' !== $fichero->getExtension() ) {
				continue;
			}
			$codigo = (string) file_get_contents( $fichero->getPathname() );
			// Fuera los comentarios: un nombre citado en un docblock no es un oyente.
			$codigo = (string) preg_replace( '#/\*.*?\*/|//[^\n]*#s', '', $codigo );

			preg_match_all( "/do_action\(\s*'(akb_rewards_[a-z_]+)'/", $codigo, $d );
			preg_match_all( "/add_action\(\s*'(akb_rewards_[a-z_]+)'/", $codigo, $o );
			foreach ( $d[1] as $nombre ) {
				$disparados[ $nombre ][] = basename( $fichero->getPathname() );
			}
			$oyentes = array_merge( $oyentes, $o[1] );
		}

		$this->assertNotSame( array(), $disparados, 'La lectura del fuente no encontró nada: la prueba no estaría midiendo.' );

		$huerfanos = array_diff_key( $disparados, array_flip( $oyentes ) );
		$this->assertSame(
			array(),
			array_map( static fn( array $donde ): string => implode( ', ', array_unique( $donde ) ), $huerfanos ),
			'Avisos disparados sin oyente (o se escuchan, o se borran).'
		);
	}
}
