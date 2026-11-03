<?php
/**
 * Contrato de la política de purga del panel Akibara.
 *
 * Guardar cualquier pestaña disparaba `litespeed_purge_all`, y como LiteSpeed
 * corre con la integración de Cloudflare activa, eso vaciaba el BORDE completo:
 * cambiar un texto que sólo se ve en el carrito devolvía al origen las 1.568
 * fichas, las 106 landings de serie, la portada y el blog (medido 2026-08-22:
 * landing de serie 48 → 1.057 ms, blog 54 → 710 ms).
 *
 * La corrección es OPT-OUT a propósito: el default sigue siendo purgar. Este
 * test fija esa asimetría, que es lo que evita repetir el defecto de la
 * auditoría 2026-07-28 (secciones que cambiaban HTML cacheado sin invalidar).
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\AdminPanel;

use Akibara\Business\Modules\AdminPanel\AdminPanel;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionClassConstant;

/**
 * @since 1.108.9
 */
final class PanelPurgePolicyTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		$_POST = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @return array<int, string> */
	private function exempt(): array {
		// PHP 8.4 retiró `setAccessible()` de ReflectionClassConstant (desde 8.1
		// la reflexión ya lee constantes privadas sin permiso explícito).
		return (array) ( new ReflectionClassConstant( AdminPanel::class, 'SECTIONS_WITHOUT_CACHED_SURFACE' ) )->getValue();
	}

	/**
	 * `catalog_order` (2026-09-22) entra por otra razón que las demás: su
	 * superficie SÍ está cacheada, pero guardar no la cambia. Lo que reordena la
	 * tienda es el recálculo que el guardado encola, y ése purga al terminar
	 * (CatalogOrderVitrinaTest lo fija). Purgar al guardar enfriaba el borde
	 * minutos antes del cambio y el aviso «ya está visible» era falso.
	 *
	 * `home_series` (2026-09-26) también: la portada SÍ está cacheada, pero sus
	 * tres options purgan por su cuenta la etiqueta de las páginas que llevan
	 * la vitrina (HomeSeriesPurgeTest lo fija). El purge all devolvía al origen
	 * todo el sitio, borde incluido, por una lista que sólo se ve en la portada.
	 */
	public function test_only_uncached_surfaces_are_exempt(): void {
		self::assertSame(
			array( 'address', 'native_cart', 'free_shipping_progress', 'imagenes', 'velocidad', 'home_series', 'catalog_order' ),
			array_keys( $this->exempt() ),
			'La lista de exenciones es corta y explícita: ampliarla exige comprobar que la sección no toca HTML cacheado o que purga por su cuenta justo lo que cambia.'
		);
	}

	/**
	 * Desde S-45c17f64 (2026-09-19) la lista es slug => motivo VISIBLE, no
	 * sólo slugs: el aviso de éxito cita el motivo de CADA sección exenta en
	 * vez de una frase única («carrito y checkout») que para `imagenes` y
	 * `velocidad` era falsa. Una exención nueva sin motivo propio heredaría
	 * el de la anterior en el array —silenciosamente—, así que se exige uno
	 * no vacío por entrada.
	 *
	 * @testdox Cada sección exenta declara su propio motivo, no vacío.
	 */
	public function test_every_exempt_section_declares_its_own_reason(): void {
		foreach ( $this->exempt() as $seccion => $motivo ) {
			self::assertNotSame( '', trim( (string) $motivo ), "La sección «{$seccion}» no declara motivo." );
		}

		// Y que ninguna comparta literalmente el motivo de otra: eso sería la
		// misma frase genérica reencarnada.
		self::assertSame(
			count( $this->exempt() ),
			count( array_unique( $this->exempt() ) ),
			'Dos secciones exentas no pueden compartir el mismo motivo textual.'
		);
	}

	/**
	 * Las secciones que SÍ imprimen en superficies cacheadas nunca pueden entrar
	 * a la lista. Si alguien las agrega, el dueño volvería a guardar sin ver el
	 * cambio en el sitio — el defecto original.
	 *
	 * @dataProvider cachedSections
	 */
	public function test_sections_with_cached_surfaces_still_purge( string $section, string $why ): void {
		self::assertNotContains( $section, array_keys( $this->exempt() ), $why );
	}

	/**
	 * Entrar al panel, mirar y pulsar Guardar es lo que hace cualquiera al
	 * revisar una configuración. Eso tiraba el borde entero de Cloudflare y
	 * dejaba la portada en 2,55 s —siete a diez veces más lenta— para el
	 * visitante siguiente. Medido en producción el 2026-09-06.
	 *
	 * @testdox Guardar SIN cambiar nada no agenda ninguna purga.
	 */
	public function test_saving_without_changes_schedules_no_purge(): void {
		$disparados = array();
		$purga      = $this->run_handle_save( 'announcement', $disparados, 0 );

		self::assertNull(
			$purga,
			'Sin cambios no hay nada que invalidar: purgar sólo cuesta la primera visita de otro.'
		);
		self::assertSame( array(), $disparados );
	}

	/** @return array<string, array{0: string, 1: string}> */
	public static function cachedSections(): array {
		return array(
			'preventa'      => array( 'preventa', 'Los banners de fecha estimada se imprimen en fichas y listados cacheados.' ),
			'lista espera'  => array( 'back_in_stock', 'El formulario «Avísame» vive en fichas agotadas, que se cachean.' ),
			'descuentos'    => array( 'taxonomy_discount', 'Mueve precios: afecta a todo el catálogo cacheado.' ),
			'arma tu pack'  => array( 'series_bundles', 'Se renderiza en la ficha y en la landing de serie, ambas cacheadas.' ),
			'anuncios'      => array( 'announcement', 'La barra se imprime en el HTML de todas las páginas.' ),
			'branding'      => array( 'branding', 'Inyecta CSS en wp_head de todo el sitio.' ),
			'textos'        => array( 'copys', 'Los textos alimentan fichas y listados cacheados.' ),
		);
	}

	/**
	 * Guardar una sección NO exenta agenda el purge global.
	 *
	 * Antes esto era un `preg_match` sobre el fuente de `AdminPanel.php`: fijaba
	 * la ALINEACIÓN del `if`, no la conducta, así que reformatear el archivo lo
	 * ponía rojo y mover el `add_action` a otro sitio lo dejaba verde. Ahora se
	 * corre `handle_save()` de verdad y se observa el hook.
	 */
	public function test_saving_a_cached_section_schedules_the_global_purge(): void {
		$disparados = array();
		$purga      = $this->run_handle_save( 'announcement', $disparados );

		self::assertNotNull( $purga, 'Una sección que imprime en HTML cacheado tiene que agendar el purge.' );

		$purga();
		self::assertSame( array( 'litespeed_purge_all' ), $disparados );
	}

	/**
	 * Y guardar una de las tres exentas no toca el borde.
	 */
	public function test_saving_an_exempt_section_does_not_purge(): void {
		$disparados = array();

		self::assertNull(
			$this->run_handle_save( 'native_cart', $disparados ),
			'Las secciones sin superficie cacheada no pueden vaciar el borde entero.'
		);
		self::assertSame( array(), $disparados );
	}

	/**
	 * Ejecuta `handle_save()` para un tab y devuelve el callback que quedó
	 * colgado de `shutdown`, o null si no se agendó purga.
	 *
	 * @param string             $tab        Slug de la pestaña guardada.
	 * @param array<int, string> $disparados Recoge las acciones que se disparan.
	 * @return callable|null
	 */
	/**
	 * @param string             $tab        Pestaña a guardar.
	 * @param array<int, string> $disparados Hooks disparados, por referencia.
	 * @param int                $cambios    Cuántas options cambian DE VERDAD en
	 *                                       este guardado. Por defecto una: el
	 *                                       caso normal es que el operador venga
	 *                                       a cambiar algo. Con 0 se modela el
	 *                                       guardado que no toca nada, que desde
	 *                                       2026-09-06 ya no purga.
	 */
	private function run_handle_save( string $tab, array &$disparados, int $cambios = 1 ): ?callable {
		$panel = new AdminPanel();
		$panel->add_section( $tab, 'Sección', static fn(): array => array() );

		$purga      = null;
		$contadores = array();
		Functions\when( 'add_action' )->alias(
			static function ( string $hook, $callback ) use ( &$purga, &$contadores ): void {
				if ( 'shutdown' === $hook ) {
					$purga = $callback;
				}
				if ( 'updated_option' === $hook || 'added_option' === $hook ) {
					$contadores[] = $callback;
				}
			}
		);
		Functions\when( 'remove_action' )->justReturn( true );
		Functions\when( 'do_action' )->alias(
			static function ( string $hook ) use ( &$disparados ): void {
				$disparados[] = $hook;
			}
		);
		Functions\when( 'check_admin_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'sanitize_key' )->returnArg();
		// La escritura de verdad dispara `updated_option`: es lo que el panel
		// cuenta para saber si hay algo que invalidar. Un doble que no lo dispara
		// modela un guardado que no cambió NADA, y ese caso ya no purga.
		Functions\when( 'woocommerce_update_options' )->alias(
			static function () use ( &$contadores, $cambios ): void {
				foreach ( $contadores as $contador ) {
					for ( $i = 0; $i < $cambios; $i++ ) {
						$contador();
					}
				}
			}
		);
		Functions\when( 'admin_url' )->justReturn( 'https://akibara.cl/wp-admin/admin.php' );
		Functions\when( 'add_query_arg' )->justReturn( 'https://akibara.cl/wp-admin/admin.php' );
		// `handle_save()` termina en redirect + exit; la excepción hace de exit.
		Functions\when( 'wp_safe_redirect' )->alias(
			static function (): void {
				throw new \RuntimeException( 'redirect' );
			}
		);

		$_POST = array(
			'akibara_panel_save' => '1',
			'akibara_current_tab' => $tab,
		);

		try {
			$panel->handle_save();
			self::fail( 'handle_save() debe redirigir tras guardar.' );
		} catch ( \RuntimeException $e ) {
			self::assertSame( 'redirect', $e->getMessage() );
		}

		return $purga;
	}
}
