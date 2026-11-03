<?php
/**
 * El plugin de bloques retira sus datos, y sólo los suyos.
 *
 * Hallazgo S-653f9a16: no había `uninstall.php`, así que la cuenta de scripts
 * de Mercado Pago y el acelerador del log de PUDO sobrevivían para siempre a
 * la desinstalación. El barrido de akibara-business los excluye a propósito
 * —`akb_` es un prefijo compartido y borrarle la configuración a un plugin
 * instalado es irreversible—, así que nadie los retiraba.
 *
 * @package Akibara\CartCheckoutBlocks\Tests
 */

declare( strict_types = 1 );

namespace Akibara\CartCheckoutBlocks\Tests;

use PHPUnit\Framework\TestCase;

final class DesinstalarRetiraLoPropioTest extends TestCase {

	private function fuente(): string {
		$ruta = dirname( __DIR__, 2 ) . '/uninstall.php';
		self::assertFileExists( $ruta, 'Sin desinstalador, los datos del plugin no los retira nadie.' );

		return (string) file_get_contents( $ruta );
	}

	/** @testdox Se retiran los dos prefijos propios, con sus transients. */
	public function test_retira_lo_propio(): void {
		$src = $this->fuente();

		// El fichero escribe `\\_` (literal PHP de un `\_` para el LIKE), así que
		// el patrón buscado lleva las dos barras tal como están en la fuente.
		foreach ( array( 'akb\\\\_blocks\\\\_%', '_transient\\\\_akb\\\\_blocks\\\\_%', '_transient\\\\_akb\\\\_pudo\\\\_log\\\\_%' ) as $patron ) {
			self::assertStringContainsString( $patron, $src, sprintf( 'Falta barrer «%s».', $patron ) );
		}
	}

	/** @testdox No se toca el namespace de ningún plugin hermano. */
	public function test_no_toca_lo_ajeno(): void {
		$src = $this->fuente();

		self::assertStringNotContainsString( "LIKE 'akb\\\\_%'", $src, 'Ese patrón se llevaría la configuración entera de akibara-business.' );
		foreach ( array( 'akb\\\\_search\\\\_', 'akb\\\\_rewards\\\\_', 'akibara\\\\_' ) as $ajeno ) {
			self::assertStringNotContainsString( $ajeno, $src, sprintf( '«%s» es de otro plugin.', $ajeno ) );
		}
	}

	/** @testdox Sólo corre desde la desinstalación de WordPress. */
	public function test_no_corre_suelto(): void {
		self::assertStringContainsString( "defined( 'WP_UNINSTALL_PLUGIN' ) || exit;", $this->fuente() );
	}
}
