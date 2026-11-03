<?php
/**
 * Candado de invariante: `maybe_upgrade` se engancha UNA sola vez por request.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Fija la invariante, no el literal del hook retirado.
 *
 * Historia del defecto: `boot()` enganchaba `maybe_upgrade` a `init` 999 Y a
 * `admin_init` 999. El comentario justificaba el segundo diciendo que wp-admin
 * "nunca lo cachea LiteSpeed", pero Core dispara `init` sin condición en
 * wp-settings.php para TODA petición (wp-admin y admin-ajax.php incluidas), así
 * que `admin_init` sólo repetía el trabajo. Medido en Docker el 2026-08-23 con
 * sondas en las prioridades 998/1000 de ambos hooks: una petición a
 * admin-ajax.php ejecutaba el método dos veces y la segunda costaba
 * 0,012-0,022 ms con 0 consultas.
 *
 * Lo que este candado protege NO es "que no exista admin_init": es que el
 * método se registre exactamente una vez y sobre un hook que Core dispare en
 * todos los contextos. Si mañana el enganche se mueve a `wp_loaded`, el test
 * sigue siendo válido y hay que actualizar la lista blanca a conciencia,
 * dejando escrito por qué ese hook cubre wp-admin.
 */
final class PluginUpgradeHookTest extends TestCase {

	/**
	 * Hooks que Core dispara en toda petición (frontend, wp-admin, ajax, REST,
	 * WP-CLI). Sólo uno de estos puede llevar el upgrade, y sólo uno de ellos.
	 *
	 * @var list<string>
	 */
	private const HOOKS_UNIVERSALES = array( 'init', 'wp_loaded' );

	/**
	 * Todos los ficheros PHP del runtime del plugin.
	 *
	 * Se barre el plugin ENTERO y no sólo el cuerpo de `boot()`. Una revisión
	 * adversarial demostró el 2026-08-23 que la versión anterior de este candado
	 * se evadía de dos maneras triviales, las dos con el defecto de vuelta y el
	 * test en verde: registrando el segundo enganche con la sintaxis corta
	 * `[ $this, 'maybe_upgrade' ]`, o registrándolo desde otro método. Un candado
	 * que sólo atrapa el copia-pega exacto da confianza falsa.
	 *
	 * @return array<string,string> ruta => contenido
	 */
	private function fuentes_del_runtime(): array {
		$raiz  = dirname( __DIR__ ) . '/src';
		$out   = array();
		$iter  = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $raiz ) );
		foreach ( $iter as $fichero ) {
			if ( ! $fichero->isFile() || 'php' !== strtolower( $fichero->getExtension() ) ) {
				continue;
			}
			$out[ $fichero->getPathname() ] = (string) file_get_contents( $fichero->getPathname() );
		}

		self::assertNotEmpty( $out, 'No se pudo leer el runtime del plugin.' );

		return $out;
	}

	/**
	 * @testdox `maybe_upgrade` se engancha exactamente una vez, y sobre un hook universal.
	 */
	public function test_maybe_upgrade_se_engancha_exactamente_una_vez(): void {
		// Cubre `array( $this, '...' )`, `[ $this, '...' ]`, `$this->maybe_upgrade(...)`
		// como callable de primera clase y `'Clase::maybe_upgrade'`.
		$patron = '/add_action\(\s*\'([a-z_]+)\'\s*,\s*(?:array\s*\(|\[)?\s*(?:\$this|self::class|static::class)?\s*(?:,|->|::)?\s*(?:\'maybe_upgrade\'|maybe_upgrade)/';

		$encontrados = array();
		foreach ( $this->fuentes_del_runtime() as $ruta => $codigo ) {
			if ( false === strpos( $codigo, 'maybe_upgrade' ) ) {
				continue;
			}
			preg_match_all( $patron, $codigo, $coincidencias );
			foreach ( $coincidencias[1] as $hook ) {
				$encontrados[] = array( 'hook' => $hook, 'fichero' => basename( $ruta ) );
			}
		}

		self::assertCount(
			1,
			$encontrados,
			'maybe_upgrade debe engancharse una sola vez en TODO el plugin. Encontrado en: '
			. json_encode( $encontrados )
			. '. Dos enganches lo ejecutan dos veces en la misma petición de wp-admin sin hacer '
			. 'trabajo extra (medido: 0 consultas la segunda vez).'
		);
		self::assertContains(
			$encontrados[0]['hook'],
			self::HOOKS_UNIVERSALES,
			'El upgrade debe colgar de un hook que Core dispare en TODA petición '
			. '(frontend, wp-admin, admin-ajax, REST y WP-CLI); '
			. 'de lo contrario una migración puede quedarse sin correr.'
		);
	}
}
