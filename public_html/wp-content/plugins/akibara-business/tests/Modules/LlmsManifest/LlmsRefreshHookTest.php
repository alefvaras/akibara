<?php
/**
 * La descripción de la tienda tiene que llegar a /llms.txt al guardarla.
 *
 * El dueño escribe la identidad en «Salud SEO», ve «Ajustes guardados
 * correctamente», y el fichero no cambia hasta el refresco diario: hasta 24
 * horas. Medido por QA el 2026-09-05 con doce minutos de vigilancia, y con el
 * cron del espejo comprobadamente sano.
 *
 * La causa no era la que parecía. El módulo se engancha a `added_option_{...}` y
 * `updated_option_{...}`, y **esos dos hooks no existen en WordPress**. Core
 * mezcla dos familias de nombres:
 *
 *   - con sufijo, ANTES de escribir:  `add_option_{$option}` (option.php:1176)
 *                                     `update_option_{$option}` (option.php:1019)
 *   - sin sufijo, DESPUÉS de escribir: `added_option`, `updated_option`
 *
 * Es decir, `added_option_` y `updated_option_` no los dispara nadie nunca: el
 * callback no se llamaba, no se encolaba la cita, y el generador —que funciona
 * perfectamente— no tenía quién lo llamara hasta el cron del día siguiente.
 *
 * Hacen falta LOS DOS: la primera vez que se guarda la identidad la fila no
 * existe, `update_option()` deriva en `add_option()` y sólo salta
 * `add_option_{$option}`; a partir de ahí salta `update_option_{$option}`.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\LlmsManifest;

use Akibara\Business\Modules\LlmsManifest\LlmsManifest;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class LlmsRefreshHookTest extends TestCase {

	/** Los nombres que WordPress dispara de verdad, comprobados en wp-includes/option.php. */
	private const HOOKS_QUE_EXISTEN = array( 'add_option_', 'update_option_' );

	/** Los que no dispara nadie, y a los que este módulo estuvo enganchado. */
	private const HOOKS_FANTASMA = array( 'added_option_', 'updated_option_', 'deleted_option_' );

	private function fuente(): string {
		return (string) file_get_contents(
			dirname( __DIR__, 3 ) . '/src/Modules/LlmsManifest/LlmsManifest.php'
		);
	}

	private function option_identidad(): string {
		$constante = new ReflectionClass( LlmsManifest::class );

		return (string) $constante->getConstant( 'OPTION_IDENTITY' );
	}

	/**
	 * @testdox El refresco se engancha a los dos hooks que WordPress sí dispara.
	 */
	public function test_the_refresh_is_wired_to_hooks_that_actually_fire(): void {
		$fuente = $this->fuente();

		foreach ( self::HOOKS_QUE_EXISTEN as $hook ) {
			self::assertStringContainsString(
				"add_action( '" . $hook . "' . self::OPTION_IDENTITY",
				$fuente,
				'Sin este hook, guardar la identidad no encola nada y /llms.txt espera al cron diario.'
			);
		}
	}

	/**
	 * @testdox No queda enganchado a ningún hook que WordPress no dispare.
	 */
	public function test_no_ghost_hooks_remain(): void {
		$fuente = $this->fuente();

		foreach ( self::HOOKS_FANTASMA as $hook ) {
			self::assertStringNotContainsString(
				"add_action( '" . $hook . "' . self::OPTION_IDENTITY",
				$fuente,
				'`' . $hook . '{option}` no lo dispara nadie: engancharse ahí es no engancharse.'
			);
		}
	}

	/**
	 * El candado que impide que esto vuelva a pasar en otro módulo: si alguien
	 * escribe un hook con esta forma, el nombre tiene que existir en Core.
	 *
	 * @testdox Ningún módulo del plugin se engancha a `added_option_` ni a `updated_option_`.
	 */
	public function test_no_module_in_the_plugin_uses_the_ghost_hooks(): void {
		$raiz  = dirname( __DIR__, 3 ) . '/src';
		$malos = array();

		$iterador = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $raiz ) );
		foreach ( $iterador as $fichero ) {
			if ( ! $fichero->isFile() || 'php' !== $fichero->getExtension() ) {
				continue;
			}
			$contenido = (string) file_get_contents( (string) $fichero );
			foreach ( self::HOOKS_FANTASMA as $hook ) {
				if ( str_contains( $contenido, "add_action( '" . $hook ) ) {
					$malos[] = basename( (string) $fichero ) . ' → ' . $hook;
				}
			}
		}

		self::assertSame(
			array(),
			$malos,
			'Core dispara `add_option_{$option}` y `update_option_{$option}`; las formas en pasado no llevan sufijo.'
		);
	}

	/**
	 * @testdox El callback del refresco sigue existiendo y es público.
	 */
	public function test_the_callback_is_still_there(): void {
		self::assertTrue(
			method_exists( LlmsManifest::class, 'queue_refresh_after_identity_change' ),
			'Los hooks nuevos apuntan al mismo método; si desaparece, no se encola nada.'
		);
		self::assertNotSame( '', $this->option_identidad(), 'La option de identidad tiene que tener nombre.' );
	}
}
