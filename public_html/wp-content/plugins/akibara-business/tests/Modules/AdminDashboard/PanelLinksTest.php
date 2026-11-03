<?php
/**
 * Los accesos rápidos del Dashboard llevan a pestañas que existen.
 *
 * QA midió el 2026-09-05 (asunto `2026-09-05-qa-panel-todos-los-modulos`,
 * punto 4) que «Pedidos y preventa» apuntaba a `tab=order_preventa`, un id que
 * ninguna sección registra: `AdminPanel::render()` no encuentra la pestaña y
 * cae a la primera, así que el enlace aterrizaba en el propio Dashboard. El id
 * real es `Settings::PANEL_TAB_ID` (`preventa`). Esta prueba lee los enlaces
 * del Dashboard y las secciones que `Plugin.php` registra, y exige que cada
 * `tab=` esté entre ellas.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\AdminDashboard;

use PHPUnit\Framework\TestCase;

final class PanelLinksTest extends TestCase {

	private const SRC = __DIR__ . '/../../../src';

	/**
	 * @testdox Cada `tab=` que enlaza el Dashboard es una sección registrada en Plugin.php.
	 */
	public function test_every_dashboard_link_points_to_a_registered_section(): void {
		$dashboard = (string) file_get_contents( self::SRC . '/Modules/AdminDashboard/AdminDashboard.php' );
		$plugin    = (string) file_get_contents( self::SRC . '/Plugin.php' );

		$registradas = $this->secciones_registradas( $plugin );
		self::assertGreaterThan( 10, count( $registradas ), 'Plugin.php registra más de diez secciones; si no se leen, la prueba no mide nada.' );

		$enlazadas = $this->pestanas_enlazadas( $dashboard );
		self::assertNotEmpty( $enlazadas, 'El Dashboard enlaza pestañas del panel; si no se leen, la prueba no mide nada.' );

		foreach ( $enlazadas as $tab ) {
			self::assertContains(
				$tab,
				$registradas,
				"El Dashboard enlaza a tab={$tab}, que ninguna sección registra: AdminPanel cae a la primera pestaña y el enlace no lleva a ninguna parte."
			);
		}
	}

	/**
	 * Ids de sección registrados con `add_section()`: literales y constantes `PANEL_TAB_ID`.
	 *
	 * @return array<int, string>
	 */
	private function secciones_registradas( string $plugin ): array {
		preg_match_all( "/add_section\(\s*'([a-z_]+)'/", $plugin, $literales );
		preg_match_all( '/add_section\(\s*(\\\\?[A-Za-z0-9_\\\\]+)::PANEL_TAB_ID/', $plugin, $constantes );

		$ids = $literales[1];
		foreach ( $constantes[1] as $referencia ) {
			$ids[] = $this->constante( $referencia, $plugin );
		}

		return $ids;
	}

	/**
	 * Ids que el Dashboard enlaza: `tab=<id>` literal, el mapa `'tab' => '<id>'` y `tab=' . Clase::PANEL_TAB_ID`.
	 *
	 * @return array<int, string>
	 */
	private function pestanas_enlazadas( string $dashboard ): array {
		preg_match_all( "/page=akibara-panel&tab=([a-z_]+)'/", $dashboard, $literales );
		preg_match_all( "/'tab'\s*=>\s*'([a-z_]+)'/", $dashboard, $mapa );
		preg_match_all( "/page=akibara-panel&tab=' \. (\\\\?[A-Za-z0-9_\\\\]+)::PANEL_TAB_ID/", $dashboard, $constantes );

		$ids = array_merge( $literales[1], $mapa[1] );
		foreach ( $constantes[1] as $referencia ) {
			$ids[] = $this->constante( $referencia, $dashboard );
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Resuelve `Clase::PANEL_TAB_ID` con los `use` del fichero que la nombra.
	 */
	private function constante( string $referencia, string $fuente ): string {
		if ( str_starts_with( $referencia, '\\' ) ) {
			$clase = substr( $referencia, 1 );
		} else {
			preg_match_all( '/^use\s+([A-Za-z0-9_\\\\]+)(?:\s+as\s+([A-Za-z0-9_]+))?;/m', $fuente, $usos, PREG_SET_ORDER );
			$alias = array();
			foreach ( $usos as $uso ) {
				$corto           = isset( $uso[2] ) && '' !== $uso[2] ? $uso[2] : (string) substr( (string) strrchr( '\\' . $uso[1], '\\' ), 1 );
				$alias[ $corto ] = $uso[1];
			}
			$clase = $alias[ $referencia ] ?? $referencia;
		}

		self::assertTrue( class_exists( $clase ), "La clase {$clase} (de {$referencia}) no existe: no se puede leer su PANEL_TAB_ID." );

		return (string) constant( $clase . '::PANEL_TAB_ID' );
	}
}
