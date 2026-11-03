<?php
/**
 * La migración de fechas por país no puede costar una consulta por visita.
 *
 * Hallazgo S-f66cd817: `maybe_run_lazy()` colgaba de `plugins_loaded`, leía en
 * cada petición del front una option que no viaja en `alloptions` —un SELECT
 * por nombre— y encima no podía hacer su trabajo: a esa altura WooCommerce aún
 * no registró `pa_pais`, así que se rendía en el guard siguiente. La lectura se
 * pagaba siempre y no compraba nada.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\EstimatedDelivery;

use PHPUnit\Framework\TestCase;

final class MigracionNoSeLeeEnCadaVisitaTest extends TestCase {

	private function plugin_source(): string {
		return (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Plugin.php' );
	}

	/** @testdox El arranque de los módulos ya no llama a la migración. */
	public function test_no_corre_en_plugins_loaded(): void {
		$src = $this->plugin_source();

		self::assertStringNotContainsString(
			"\t\tEdaMigration::maybe_run_lazy();",
			$src,
			'Volvió a llamarse en el registro de módulos: eso es un SELECT por visita.'
		);
	}

	/** @testdox Corre cuando cambia la versión, que es cuando puede hacer algo. */
	public function test_corre_en_el_camino_de_upgrade(): void {
		self::assertMatchesRegularExpression(
			"/add_action\(\s*'akibara_business_upgraded',\s*array\( EdaMigration::class, 'maybe_run_lazy' \)/",
			$this->plugin_source()
		);
	}

	/** @testdox Queda una red de reintento, pero no la paga el visitante. */
	public function test_la_red_no_corre_en_el_front(): void {
		$src       = $this->plugin_source();
		$migracion = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Modules/EstimatedDelivery/Migration.php' );

		self::assertMatchesRegularExpression(
			"/add_action\(\s*'init',\s*array\( EdaMigration::class, 'maybe_retry_in_admin' \),\s*999\s*\)/",
			$src
		);
		self::assertStringContainsString( "! is_admin() && ! wp_doing_cron()", $migracion, 'El visitante no puede pagar el reintento.' );
		self::assertStringContainsString( 'RETRY_MEMO', $migracion, 'Sin memo, cada petición de admin vuelve a leer la option.' );
	}

	/** @testdox La bandera de la migración no entró al calentamiento del front. */
	public function test_no_se_calienta_como_si_fuera_del_front(): void {
		self::assertStringNotContainsString(
			"'akibara_eda_pa_pais_migrated_at',",
			$this->plugin_source(),
			'Calentarla sería admitir que se lee en cada visita; ya no se lee.'
		);
	}
}
