<?php
/**
 * Guarda de la suite: ninguna prueba termina con deuda de cierre en un runtime editorial.
 *
 * POR QUÉ EXISTE (0.1.64). `EditorialDiscountPublication::enter_fence()` registra
 * `release_on_shutdown()` con `register_shutdown_function()`. Si una prueba deja un
 * marco abierto, ese cierre corre al APAGAR PHP, con Brain Monkey ya desmontado:
 * `AuditLog::record()` llama a `get_option()` sin stub y PHP muere con un fatal. La
 * suite había impreso `OK (680 tests…)` y salía con rc=255 — un rojo que sólo se veía
 * en el código de salida, y que `qa-search.sh` marca como fallo. Lo dejó una prueba
 * que cercaba un cambio real sin modelar el `updated_option` con el que Core lo
 * confirma.
 *
 * Con esta guarda el mismo descuido falla la PRUEBA que lo causa, con su nombre, en
 * vez del proceso entero. `MarcosEditorialesVigiladosTest` exige además que todo
 * fichero que construya el runtime editorial pase por aquí.
 *
 * Uso: `$this->vigilar_publicacion( new EditorialDiscountPublication( … ) )` al
 * construir, y `$this->soltar_publicaciones_vigiladas();` como PRIMERA línea del
 * `tearDown()` (los stubs siguen montados).
 *
 * @package Akibara\Search\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Search\Tests\Support;

use Akibara\Search\EditorialDiscountPublication;
use ReflectionProperty;

trait MarcosEditorialesVigilados {

	/** @var array<int, EditorialDiscountPublication> */
	private array $publicaciones_vigiladas = array();

	/**
	 * Registra un runtime editorial para vigilar su deuda de cierre.
	 *
	 * @param EditorialDiscountPublication $publicacion Runtime recién construido.
	 * @return EditorialDiscountPublication El mismo, para usarlo en línea.
	 */
	protected function vigilar_publicacion( EditorialDiscountPublication $publicacion ): EditorialDiscountPublication {
		$this->publicaciones_vigiladas[] = $publicacion;
		return $publicacion;
	}

	/**
	 * Falla la prueba si deja algo que `release_on_shutdown()` tendría que cerrar.
	 *
	 * PHPUnit lo llama sólo si la prueba no falló antes; la limpieza de
	 * `soltar_publicaciones_vigiladas()` corre siempre.
	 */
	protected function assertPostConditions(): void {
		parent::assertPostConditions();
		foreach ( $this->publicaciones_vigiladas as $publicacion ) {
			self::assertSame(
				array(),
				self::deuda_de_cierre( $publicacion ),
				'La prueba terminó con un marco editorial abierto: ciérralo en la propia prueba'
					. ' (modela el `updated_option` de Core o llama a release_on_shutdown()). Si no,'
					. ' el cierre corre al apagar PHP sin stubs y la suite sale con rc=255.'
			);
		}
	}

	/**
	 * Suelta la deuda que quede, con los stubs aún montados, y olvida los runtimes.
	 *
	 * @return void
	 */
	protected function soltar_publicaciones_vigiladas(): void {
		foreach ( $this->publicaciones_vigiladas as $publicacion ) {
			if ( array() !== self::deuda_de_cierre( $publicacion ) ) {
				$publicacion->release_on_shutdown();
			}
		}
		$this->publicaciones_vigiladas = array();
	}

	/**
	 * Lo que haría que `release_on_shutdown()` llamara a WordPress al apagar PHP.
	 *
	 * @param EditorialDiscountPublication $publicacion Runtime vigilado.
	 * @return array<string, int> Propiedad => cantidad pendiente; vacío si no hay deuda.
	 */
	protected static function deuda_de_cierre( EditorialDiscountPublication $publicacion ): array {
		$deuda = array();
		foreach ( array( 'option_frames', 'meta_frames', 'blocked_writes', 'vetoed_deletes', 'fence_depth' ) as $nombre ) {
			$propiedad = new ReflectionProperty( EditorialDiscountPublication::class, $nombre );
			$propiedad->setAccessible( true );
			$valor    = $propiedad->getValue( $publicacion );
			$cantidad = is_array( $valor ) ? count( $valor ) : (int) $valor;
			if ( 0 < $cantidad ) {
				$deuda[ $nombre ] = $cantidad;
			}
		}
		return $deuda;
	}
}
