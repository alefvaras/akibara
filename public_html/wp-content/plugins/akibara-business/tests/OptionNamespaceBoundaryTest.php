<?php
/**
 * Contrato de frontera entre los namespaces de opciones de los dos plugins.
 *
 * `akb_` está COMPARTIDO: `akb_search_*` es del plugin akibara-search y
 * `akb_*` a secas es de akibara-business. Los `uninstall.php` de ambos filtran
 * en base a esa frontera, así que romperla tiene una consecuencia concreta y
 * silenciosa: un `DELETE ... LIKE 'akb\_%'` del lado del buscador borraría la
 * configuración de Business (lista de espera, favoritos, Arma tu Pack) sin que
 * nadie se entere hasta mucho después.
 *
 * Este test convierte esa convención en algo verificable, en vez de un
 * comentario que alguien puede no leer (auditoría 2026-07-28, D9).
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests;

use PHPUnit\Framework\TestCase;

final class OptionNamespaceBoundaryTest extends TestCase {

	/**
	 * Nombres de opción literales declarados en un árbol de código.
	 *
	 * Busca tanto las constantes (`const OPTION_X = 'nombre';`) como las
	 * llamadas directas (`get_option( 'nombre' )`), que son las dos formas que
	 * usan ambos plugins.
	 *
	 * @param string $root Directorio a escanear.
	 * @param string $prefix_filter Solo devuelve los que empiecen así ('' = todos).
	 * @return array<int, string>
	 */
	private function option_literals( string $root, string $prefix_filter = '' ): array {
		$found = array();

		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root ) );
		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
				continue;
			}
			$source = (string) file_get_contents( $file->getPathname() );

			preg_match_all(
				"/(?:const\s+OPT[A-Z0-9_]*\s*=\s*|(?:get|update|delete|add)_option\(\s*)'([a-z0-9_]+)'/i",
				$source,
				$matches
			);

			foreach ( $matches[1] as $name ) {
				if ( '' === $prefix_filter || str_starts_with( $name, $prefix_filter ) ) {
					$found[ $name ] = true;
				}
			}
		}

		ksort( $found );

		return array_keys( $found );
	}

	private function plugins_dir(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * @testdox Business must not claim option names inside the search namespace.
	 */
	public function test_business_does_not_use_the_search_namespace(): void {
		$business = $this->plugins_dir() . '/akibara-business/src';

		$intruders = array_filter(
			$this->option_literals( $business, 'akb_' ),
			static fn( string $name ): bool => str_starts_with( $name, 'akb_search_' )
		);

		self::assertSame(
			array(),
			array_values( $intruders ),
			'Business reclamó una opción del namespace del buscador; su uninstall.php la excluye y quedaría huérfana.'
		);
	}

	/**
	 * @testdox Search must keep every option inside its own namespace.
	 */
	public function test_search_keeps_every_option_inside_its_namespace(): void {
		$search = $this->plugins_dir() . '/akibara-search/src';

		if ( ! is_dir( $search ) ) {
			self::markTestSkipped( 'El plugin akibara-search no está en este árbol.' );
		}

		// CONTRATO CRUZADO permitido: search LEE (no posee) el tope de descuento
		// de business dentro de su pasada autoritativa — un get_option cacheado
		// rompería esa lectura, así que el literal debe vivir allá (falla del
		// escéptico 2026-09-01; dueño real: Utilities\DiscountCeiling::OPTION).
		$leidas_de_business = array( 'akb_discount_max_pct' );

		$outside = array_filter(
			$this->option_literals( $search, 'akb' ),
			static fn( string $name ): bool => ! str_starts_with( $name, 'akb_search_' )
				&& ! in_array( $name, $leidas_de_business, true )
		);

		self::assertSame(
			array(),
			array_values( $outside ),
			'El buscador declaró una opción fuera de akb_search_*; el uninstall de Business la borraría por error.'
		);
	}

	/**
	 * El DELETE de Business tiene que excluir el namespace de CADA plugin
	 * hermano, no sólo el del buscador.
	 *
	 * Se comprueba sobre la consulta que el archivo arma de verdad —la lista de
	 * hermanos se interpola en tiempo de ejecución—, no sobre su texto: así el
	 * contrato sigue en pie aunque cambie la forma de construirla.
	 *
	 * @dataProvider siblingNamespaces
	 */
	public function test_business_uninstall_excludes_every_sibling_namespace( string $prefijo, string $porque ): void {
		self::assertContains( $prefijo, $this->declared_sibling_prefixes(), $porque );
	}

	/** @return array<string, array{0: string, 1: string}> */
	public static function siblingNamespaces(): array {
		return array(
			'buscador'      => array( 'akb\\_search\\_', 'Desinstalar Business borraría el índice y las facetas del buscador.' ),
			'vocabulario'   => array( 'akb\\_vocab\\_', 'El vocabulario del buscador vive en transients akb_vocab_*.' ),
			'landing cache' => array( 'akb\\_lc\\_', 'Caché de landings del buscador.' ),
			'rate limit'    => array( 'akb\\_rlm\\_', 'Límites de tasa del buscador.' ),
			'puntos'        => array( 'akb\\_rewards\\_', 'Las 36 opciones del programa de puntos son de akibara-rewards.' ),
			'bloques'       => array( 'akb\\_blocks\\_', 'akibara-cart-checkout-blocks no tiene uninstall propio todavía.' ),
			'pudo log'      => array( 'akb\\_pudo\\_log\\_', 'El log de PUDO lo escribe el plugin de bloques, no éste.' ),
		);
	}

	/**
	 * Los mu-plugins NO se desinstalan: siguen vivos después del barrido.
	 *
	 * Hallazgo S-890b95b2: el DELETE por prefijo se llevaba la memoria de cortes
	 * del vigilante del correo —el mismo que existe porque la tienda pasó tres
	 * días sin enviar uno— y las marcas del vigía de Blue, que entonces volvía a
	 * avisar de un problema ya resuelto.
	 *
	 * @dataProvider muPluginOptions
	 */
	public function test_business_uninstall_excludes_mu_plugin_options( string $option, string $fichero ): void {
		$archivo = (string) file_get_contents( $this->plugins_dir() . '/akibara-business/uninstall.php' );

		self::assertMatchesRegularExpression(
			'/\$akibara_hermanos\s*=\s*array_merge\(\s*\$akibara_hermanos,\s*\$akibara_mu_plugins\s*\);/',
			$archivo,
			'La lista de mu-plugins tiene que entrar en las exclusiones, no sólo declararse.'
		);

		self::assertContains(
			$option,
			$this->declared_prefixes( $archivo, 'akibara_mu_plugins' ),
			sprintf( 'Desinstalar Business dejaría amnésico a %s, que sigue instalado.', $fichero )
		);
	}

	/** @return array<string, array{0: string, 1: string}> */
	public static function muPluginOptions(): array {
		return array(
			'centinela del correo'  => array( 'akibara\_mail\_transport\_watch', 'mu-plugins/akibara-mail-transport-watch.php' ),
			// REAPERTURA S-890b95b2: el transporte guarda su propia memoria de
			// cortes (Transport::OPTION_OUTAGE), distinta de la del centinela
			// que lo vigila, y también quedaba fuera del uninstall.
			'rastro de cortes del correo' => array( 'akibara\_mail\_transport\_outage', 'mu-plugins/akibara-correo-smtp.php' ),
			'vigía de Blue'         => array( 'akb\_bluex\_watch\_', 'mu-plugins/akibara-bluex-integrity-watch.php' ),
			'rotación del log'      => array( 'akibara\_debug\_log\_rotation\_checked', 'mu-plugins/akibara-debug-log-rotation.php' ),
		);
	}

	/**
	 * Literales declarados en un `array()` con nombre dentro del uninstall.
	 *
	 * @param string $archivo Fuente del uninstall.
	 * @param string $variable Nombre de la variable, sin `$`.
	 * @return array<int, string>
	 */
	private function declared_prefixes( string $archivo, string $variable ): array {
		self::assertSame(
			1,
			preg_match( '/\$' . preg_quote( $variable, '/' ) . '\s*=\s*array\(([^)]*)\);/s', $archivo, $m ),
			sprintf( 'El uninstall debe declarar $%s.', $variable )
		);

		preg_match_all( "/'([^']+)'/", $m[1], $prefijos );

		return array_map(
			static fn( string $prefijo ): string => str_replace( '\\\\', '\\', $prefijo ),
			$prefijos[1]
		);
	}

	/**
	 * Prefijos que `uninstall.php` declara como hermanos y excluye del barrido.
	 *
	 * @return array<int, string>
	 */
	private function declared_sibling_prefixes(): array {
		$archivo = (string) file_get_contents( $this->plugins_dir() . '/akibara-business/uninstall.php' );

		self::assertSame(
			1,
			preg_match( '/\$akibara_hermanos\s*=\s*array\(([^)]*)\);/s', $archivo, $m ),
			'El uninstall debe declarar la lista de namespaces hermanos en $akibara_hermanos.'
		);

		// Y esa lista tiene que ser la que arma las exclusiones: si alguien la
		// declara y no la usa, el DELETE vuelve a llevarse lo ajeno.
		self::assertMatchesRegularExpression(
			'/foreach \(\s*\$akibara_hermanos as .*?NOT LIKE/s',
			$archivo,
			'La lista de hermanos tiene que generar las cláusulas NOT LIKE.'
		);

		preg_match_all( "/'([^']+)'/", $m[1], $prefijos );

		// El fuente escribe `\\_` (literal PHP de un `\_` para el LIKE): se
		// deshace la comilla del literal para comparar contra el prefijo real.
		return array_map(
			static fn( string $prefijo ): string => str_replace( '\\\\', '\\', $prefijo ),
			$prefijos[1]
		);
	}

	/**
	 * @testdox Search uninstall must never sweep the shared akb_ prefix.
	 */
	public function test_search_uninstall_never_sweeps_the_shared_prefix(): void {
		$path = $this->plugins_dir() . '/akibara-search/uninstall.php';

		if ( ! is_readable( $path ) ) {
			self::markTestSkipped( 'El plugin akibara-search no está en este árbol.' );
		}

		$uninstall = (string) file_get_contents( $path );

		// El accidente concreto que este contrato existe para impedir.
		self::assertDoesNotMatchRegularExpression(
			"/LIKE\s+'akb\\\\\\\\_%'/",
			$uninstall,
			'Un glob akb_% en el uninstall del buscador borra las opciones de Business.'
		);
		// Sin `%`: el buscador arma sus prefijos en una lista y los interpola en
		// el LIKE, así que el comodín no viaja pegado al literal.
		self::assertStringContainsString(
			"akb\\\\_search\\\\_",
			$uninstall,
			'El uninstall del buscador debe filtrar por su propio namespace.'
		);
	}
}
