<?php
/**
 * `akb_wishlist_flushed` no puede volver a borrarse en dos sitios (S-1015e39f).
 *
 * Hasta el 2026-09-19 la activación de `akibara-business.php` volvía a hacer
 * `delete_option( 'akb_wishlist_flushed' )` con el comentario «force re-flush
 * para registrar /favoritos/», prometiendo un efecto que ningún consumidor
 * implementaba: la option no la lee nadie desde que `run_upgrade_tasks()`
 * centralizó el flush de reglas en un único punto. La única baja legítima es
 * la de `Plugin::cleanup_orphan_options()`, que documenta correctamente por
 * qué es un marcador retirado.
 *
 * Candado de fuente: el defecto es código muerto duplicado, no una diferencia
 * de comportamiento observable (`delete_option` de una key ausente es un
 * no-op silencioso en los dos sitios), así que un test de comportamiento no
 * caería sin el arreglo.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\BackInStock;

use PHPUnit\Framework\TestCase;

final class WishlistFlushedNoEsCodigoMuertoTest extends TestCase {

	/** @testdox La activación ya no borra akb_wishlist_flushed por su cuenta. */
	public function test_activation_no_longer_deletes_the_dead_option(): void {
		$activacion = (string) file_get_contents( dirname( __DIR__, 3 ) . '/akibara-business.php' );

		self::assertStringNotContainsString(
			"delete_option( 'akb_wishlist_flushed' )",
			$activacion,
			'akb_wishlist_flushed es código muerto: sólo debe borrarse desde cleanup_orphan_options().'
		);
	}

	/** @testdox cleanup_orphan_options() sigue siendo la única baja de akb_wishlist_flushed. */
	public function test_cleanup_orphan_options_stays_the_single_source(): void {
		$plugin = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Plugin.php' );

		self::assertStringContainsString( "delete_option( 'akb_wishlist_flushed' );", $plugin );
	}
}
