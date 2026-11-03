<?php
/**
 * Contrato entre el warming de descubrimiento y los headers SEO.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\MuPlugins;

use PHPUnit\Framework\TestCase;

final class DiscoveryWarmingTest extends TestCase {

	private static function warmer_source(): string {
		return (string) file_get_contents( dirname( __DIR__, 4 ) . '/mu-plugins/akibara-discovery-warming.php' );
	}

	/** @testdox Discovery XML has one explicit four-hour edge-cache authority while browsers do not retain it. */
	public function test_declares_bounded_edge_cache_headers(): void {
		$source = self::warmer_source();

		self::assertStringContainsString( 'Version: 1.6.0', $source );
		self::assertStringContainsString( "\$shared_cache = 'public, max-age=14400'", $source );
		self::assertStringContainsString( "'Cache-Control']                = 'no-store, must-revalidate, max-age=0'", $source );
		self::assertStringContainsString( "'Cloudflare-CDN-Cache-Control'] = \$shared_cache", $source );
		self::assertStringContainsString( "'CDN-Cache-Control']            = \$shared_cache", $source );
		self::assertStringNotContainsString( "'Cloudflare-CDN-Cache-Control'] = 'no-store", $source );
	}

	/**
	 * @testdox La disponibilidad para bots no vuelve a depender de un warming periódico.
	 *
	 * Lo que este candado protege es la DECISIÓN de 1.6.0: el calentamiento por
	 * HTTP se retiró porque un HIT no renueva el TTL de Cloudflare —así que
	 * mantenía caliente sin parar un objeto de 4 h— y porque una respuesta 429 del
	 * origen llegó a reemplazar un objeto expirado. Cloudflare resuelve eso antes
	 * del origen.
	 *
	 * Hasta el 2026-08-23 exigía además la presencia literal de la limpieza del
	 * evento legado. Esa limpieza colgaba de `init` prioridad 1, o sea corría en
	 * TODAS las peticiones del sitio para un trabajo declarado de una sola vez, y
	 * ya lo había terminado: verificado que `akibara_discovery_warming` no existe
	 * ni en producción ni en Docker y que no queda un solo callback registrado
	 * para ese hook. Exigir el literal de una limpieza terminada es fijar el
	 * andamio, no la propiedad.
	 */
	public function test_discovery_never_returns_to_periodic_http_warming(): void {
		$source = self::warmer_source();

		self::assertStringNotContainsString( 'wp_remote_get(', $source );
		self::assertStringNotContainsString( 'wp_safe_remote_get(', $source );
		self::assertStringNotContainsString( 'wp_schedule_event(', $source );
		self::assertStringNotContainsString( "add_action( 'akibara_discovery_warming'", $source );
		self::assertStringNotContainsString( "'interval' => 3 * HOUR_IN_SECONDS", $source );

		// Y nada de trabajo recurrente colgado de cada petición: este fichero
		// existe para declarar cabeceras, no para ejecutar tareas.
		self::assertStringNotContainsString( "add_action(\n\t'init'", $source );
	}

	/** @testdox Business does not emit a contradictory no-store directive for the same sitemaps. */
	public function test_business_has_no_competing_sitemap_cache_policy(): void {
		$business = (string) file_get_contents(
			dirname( __DIR__, 2 ) . '/src/Modules/SeoEnhancements/SeoEnhancements.php'
		);

		self::assertStringNotContainsString( 'prevent_sitemap_edge_cache', $business );
		self::assertStringNotContainsString( 'Cloudflare-CDN-Cache-Control', $business );
	}
}
