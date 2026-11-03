<?php
/**
 * Candados del plugin.
 *
 * (a) Ningún símbolo spar_ en el código de src/ salvo la migración: el plugin
 *     REEMPLAZA a SPAR y colgarse de sus funciones resucitaría el acople que
 *     estamos matando. El escaneo salta comentarios (token_get_all): un
 *     docblock que EXPLICA por qué no usamos algo no puede tumbar la prueba
 *     que lo prohíbe (lección 2026-08-30, tres veces en un día).
 * (b) El fee de canje se identifica por la CONSTANTE, jamás por texto
 *     traducible: la detección por string fue la causa raíz del doble
 *     descuento del plugin viejo.
 * (c) La versión de la cabecera y la constante no pueden divergir.
 *
 * @package Akibara\Rewards\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Tests;

use Akibara\Rewards\Points\Redemption;
use PHPUnit\Framework\TestCase;

/**
 * @since 0.1.0
 */
final class GuardrailsTest extends TestCase {

	/**
	 * @return array<int,string>
	 */
	private function src_files(): array {
		$dir      = dirname( __DIR__ ) . '/src';
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir ) );
		$files    = array();

		foreach ( $iterator as $file ) {
			if ( $file instanceof \SplFileInfo && 'php' === $file->getExtension() ) {
				$files[] = $file->getPathname();
			}
		}

		sort( $files );
		$this->assertNotEmpty( $files, 'El árbol src/ no puede estar vacío.' );

		return $files;
	}

	public function test_no_spar_symbols_survive_outside_the_migration(): void {
		foreach ( $this->src_files() as $path ) {
			if ( str_ends_with( $path, 'Points/Migration.php' ) ) {
				continue; // La migración LEE el mundo SPAR por diseño.
			}
			if ( str_ends_with( $path, 'Points/AccountRewards.php' ) ) {
				continue; // DEFINE el shim spar_get_user_points (spec 37):
				// compatibilidad hacia los llamadores, no dependencia de SPAR.
			}

			$offenders = array();
			foreach ( token_get_all( (string) file_get_contents( $path ) ) as $token ) {
				if ( ! is_array( $token ) ) {
					continue;
				}
				[ $id, $text, $line ] = $token;
				if ( T_COMMENT === $id || T_DOC_COMMENT === $id ) {
					continue; // Los comentarios pueden nombrar lo prohibido.
				}
				if ( false !== stripos( $text, 'spar_' ) ) {
					$offenders[] = sprintf( '%s:%d (%s)', basename( $path ), $line, trim( $text ) );
				}
			}

			$this->assertSame(
				array(),
				$offenders,
				'Símbolos spar_ vivos fuera de la migración: ' . implode( ', ', $offenders )
			);
		}
	}

	public function test_the_redemption_fee_is_identified_by_the_constant_not_by_a_translation(): void {
		// Antes esta prueba fijaba la ALINEACIÓN del fuente (un espacio de más
		// en la declaración la tumbaba) y prohibía toda traducción en el
		// fichero. Lo que importa es la CONDUCTA: el fee se escribe y se
		// reconoce por la constante, así que traducir la etiqueta no puede
		// romper la captura — el bug exacto del plugin viejo.
		$this->assertSame( 'Puntos Akibara', Redemption::FEE_NAME, 'El valor de la constante es el contrato.' );

		$order = new \WC_Order();
		$order->items['fee'][] = new \WC_Order_Item( Redemption::FEE_NAME, -840.34, -159.66 );

		$this->assertSame(
			1000.0,
			Redemption::granted_gross( $order ),
			'Con la interfaz traducida, el fee sigue siendo reconocible por su constante.'
		);

		$ajeno = new \WC_Order();
		$ajeno->items['fee'][] = new \WC_Order_Item( 'Akibara Points', -840.34, -159.66 );

		$this->assertSame(
			0.0,
			Redemption::granted_gross( $ajeno ),
			'Un fee ajeno con el texto traducido NO se cobra como canje.'
		);
	}

	public function test_header_version_matches_the_constant(): void {
		$main = (string) file_get_contents( dirname( __DIR__ ) . '/akibara-rewards.php' );

		$this->assertSame( 1, preg_match( '/^ \* Version:\s+(\S+)$/m', $main, $header ) );
		$this->assertSame( 1, preg_match( "/define\( 'AKIBARA_REWARDS_VERSION', '([^']+)' \)/", $main, $constant ) );
		$this->assertSame( $header[1], $constant[1], 'La cabecera y la constante de versión divergen.' );
	}

	public function test_every_src_file_declares_strict_types_and_abspath_guard(): void {
		foreach ( $this->src_files() as $path ) {
			$source = (string) file_get_contents( $path );
			$this->assertStringContainsString( 'declare( strict_types = 1 );', $source, basename( $path ) );
			$this->assertStringContainsString( "defined( 'ABSPATH' ) || exit;", $source, basename( $path ) );
		}
	}
}
