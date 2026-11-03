<?php
/**
 * Guardar sin cambiar nada no puede tirar la caché de toda la tienda.
 *
 * Medido por QA en producción el 2026-09-06, mientras barría los controles del
 * panel: cada guardado purga el borde entero de Cloudflare, y la portada pasa de
 * **0,21–0,36 s a 2,55 s** — entre siete y diez veces más lenta, y no para quien
 * guarda, sino para el visitante que entre justo después.
 *
 * La purga estaba condicionada sólo por la PESTAÑA (una lista corta de secciones
 * sin superficie cacheada). Faltaba la condición obvia: **que el guardado haya
 * cambiado algo**. Entrar al panel, mirar y pulsar Guardar —que es lo que hace
 * cualquiera cuando revisa una configuración— tiraba el borde igual que un
 * cambio real.
 *
 * El default se mantiene: si algo cambió y la pestaña tiene superficie cacheada,
 * se purga. Lo que se elimina es el caso en el que no hay nada que invalidar.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\AdminPanel;

use Akibara\Business\Modules\AdminPanel\AdminPanel;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class PurgaSoloSiCambioAlgoTest extends TestCase {

	private function decide( string $tab, int $cambios ): bool {
		$metodo = new ReflectionMethod( AdminPanel::class, 'should_purge_after_save' );
		$metodo->setAccessible( true );

		return (bool) $metodo->invoke( null, $tab, $cambios );
	}

	/**
	 * @testdox Un guardado que no cambió nada NO purga.
	 */
	public function test_a_save_that_changed_nothing_does_not_purge(): void {
		self::assertFalse(
			$this->decide( 'copys', 0 ),
			'Sin cambios no hay nada que invalidar, y purgar cuesta 2,5 s a la siguiente visita.'
		);
	}

	/**
	 * @testdox Un cambio real en una pestaña con superficie cacheada sí purga.
	 */
	public function test_a_real_change_still_purges(): void {
		self::assertTrue( $this->decide( 'copys', 1 ) );
		self::assertTrue( $this->decide( 'branding', 3 ) );
	}

	/**
	 * Lo que ya funcionaba y no se toca: las secciones sin superficie cacheada.
	 * Itera TODAS las claves de la constante (S-45c17f64, 2026-09-19: antes
	 * esta prueba sólo recorría tres a mano y `imagenes`/`velocidad` quedaban
	 * sin cubrir en la decisión de purga).
	 *
	 * @testdox Las secciones sin superficie cacheada siguen sin purgar, cambien o no.
	 */
	public function test_sections_without_cached_surface_never_purge(): void {
		$exentas = array_keys(
			(array) ( new \ReflectionClassConstant( AdminPanel::class, 'SECTIONS_WITHOUT_CACHED_SURFACE' ) )->getValue()
		);

		self::assertNotEmpty( $exentas );
		foreach ( $exentas as $tab ) {
			self::assertFalse( $this->decide( $tab, 0 ) );
			self::assertFalse( $this->decide( $tab, 5 ), "Ni con cambios: la sección «{$tab}» no tiene superficie cacheada." );
		}
	}

	/**
	 * El default es purgar. Una pestaña nueva que nadie declaró tiene que
	 * purgar si cambió algo, no quedarse silenciosamente sin invalidar — ése era
	 * el defecto de 2026-07-28.
	 *
	 * @testdox Una pestaña desconocida con cambios purga, por defecto.
	 */
	public function test_an_unknown_tab_with_changes_purges(): void {
		self::assertTrue( $this->decide( 'una_pestana_nueva', 1 ) );
	}

	/**
	 * Hallazgo S-33ca8e15: el porcentaje de descuento por serie o editorial vive
	 * en TERM-META. Contando sólo options, un guardado que sólo cambiaba
	 * porcentajes daba 0 cambios: el panel no purgaba y el purgador del módulo
	 * se abstenía porque «el panel ya purga». Precios viejos en caché.
	 *
	 * @testdox Los cambios de term-meta cuentan como cambios del guardado.
	 */
	public function test_term_meta_writes_count_as_changes(): void {
		$ganchos = ( new \ReflectionClassConstant( AdminPanel::class, 'CHANGE_HOOKS' ) )->getValue();

		foreach ( array( 'updated_option', 'added_option', 'updated_term_meta', 'added_term_meta', 'deleted_term_meta' ) as $gancho ) {
			self::assertContains( $gancho, $ganchos );
		}
	}

	/**
	 * @testdox Un número de cambios negativo o raro no se interpreta como «hubo cambios».
	 */
	public function test_a_nonsense_count_is_treated_as_no_changes(): void {
		self::assertFalse( $this->decide( 'copys', -1 ) );
	}
}
