<?php
/**
 * Pruebas del guardado administrativo de la configuración BYP.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\SeriesBundles\Admin;

/**
 * `series_names()` traduce los IDs de serie a nombres para que los errores del
 * panel digan «Naruto» y no «Serie #364». Estas pruebas no cargan WordPress ni
 * usan Brain Monkey, así que el símbolo se resuelve en el propio namespace del
 * controlador.
 *
 * @param array<string,mixed> $args
 * @return array<int,\WP_Term>
 */
function get_terms( array $args ): array {
	$out = array();
	foreach ( (array) ( $args['include'] ?? array() ) as $id ) {
		$term           = new \WP_Term( (int) $id, 'serie-' . (int) $id, 'Serie ' . (int) $id );
		$term->taxonomy = 'pa_serie';
		$out[]          = $term;
	}

	return $out;
}

namespace Akibara\Business\Tests\Modules\SeriesBundles;

use Akibara\Business\Modules\SeriesBundles\Admin\BypConfigController;
use Akibara\Business\Modules\SeriesBundles\CartSeriesCollector;
use Akibara\Business\Modules\SeriesBundles\EligibilityGuard;
use Akibara\Business\Modules\SeriesBundles\PromotionPolicy;
use Akibara\Business\Modules\SeriesBundles\SeriesDepth;
use Akibara\Business\Modules\SeriesBundles\Settings;
use Akibara\Business\Modules\SeriesBundles\VolumeTierResolver;
use PHPUnit\Framework\TestCase;

final class BypConfigControllerTest extends TestCase {
	/** @testdox El guardado valida el snapshot completo y preserva exactamente las entradas rechazadas. */
	public function test_manual_save_preserves_previous_entries_rejected_by_overlap_or_depth(): void {
		$planned_by_term = array();
		$controller      = $this->controller_with_depth(
			static function ( int $term_id, array $planned ) use ( &$planned_by_term ): int {
				$planned_by_term[ $term_id ] = $planned;
				if ( 11 === $term_id && in_array( 10, $planned, true ) ) {
					return 0;
				}

				return array( 10 => 5, 12 => 5, 13 => 5 )[ $term_id ] ?? 0;
			}
		);
		$current         = array(
			'11' => array( 'enabled' => false, 'tiers' => array( array( 'min' => 4, 'pct' => 7 ) ), 'owner_note' => 'holdout' ),
			'12' => array( 'enabled' => true, 'tiers' => array( array( 'min' => 5, 'pct' => 8 ) ), 'owner_note' => 'preservar' ),
		);
		$submitted       = array(
			10 => array( 'enabled' => true, 'tiers' => array( array( 'min' => 5, 'pct' => 8 ) ) ),
			11 => array( 'enabled' => true, 'tiers' => array( array( 'min' => 5, 'pct' => 8 ) ) ),
			12 => array(
				'enabled' => true,
				'tiers'   => array( array( 'min' => 5, 'pct' => 8 ), array( 'min' => 7, 'pct' => 10 ) ),
			),
			13 => array( 'enabled' => true, 'tiers' => array( array( 'min' => 5, 'pct' => 8 ) ) ),
		);

		$method = new \ReflectionMethod( $controller, 'validate_submitted_config' );
		$method->setAccessible( true );
		/** @var array{config:array<string|int,mixed>,notices:array<int,array{type:string,message:string}>} $actual */
		$actual = $method->invoke( $controller, $current, $submitted );

		self::assertSame( array( 10, 12 ), $planned_by_term[11], 'La segunda alta debe ver todo el snapshot activo.' );
		self::assertSame(
			array( 'enabled' => true, 'tiers' => array( array( 'min' => 5, 'pct' => 8 ) ) ),
			$actual['config']['10'],
			'La primera alta nueva debe incorporarse antes de validar la segunda.'
		);
		self::assertSame( $current['11'], $actual['config']['11'], 'Un solapamiento no debe destruir el holdout previo.' );
		self::assertSame( $current['12'], $actual['config']['12'], 'Un tramo inalcanzable no debe sustituir la versión previa.' );
		self::assertSame(
			array( 'enabled' => true, 'tiers' => array( array( 'min' => 5, 'pct' => 8 ) ) ),
			$actual['config']['13'],
			'Una entrada independiente y alcanzable debe guardarse.'
		);
		self::assertCount( 2, $actual['notices'] );
		// El aviso tiene que nombrar la serie como el dueño la ve en pantalla.
		// Antes decía «Serie #11», y ese número no aparece en ninguna otra parte
		// del panel: con 119 acordeones, señalaba el problema sin decir dónde.
		// Se fija la propiedad —el nombre del término, no su ID—, no el literal.
		self::assertStringContainsString( 'Serie 11', $actual['notices'][0]['message'] );
		self::assertStringNotContainsString( '#11', $actual['notices'][0]['message'] );
		self::assertStringContainsString( 'tomos', $actual['notices'][0]['message'] );
		self::assertStringContainsString( 'Serie 12', $actual['notices'][1]['message'] );
		self::assertStringNotContainsString( '#12', $actual['notices'][1]['message'] );
		self::assertStringContainsString( 'inalcanzables', $actual['notices'][1]['message'] );
	}

	/**
	 * @testdox Guardar sin tocar una serie no saca errores sobre ella.
	 *
	 * EL DEFECTO, medido en producción el 2026-09-06: el formulario envía TODAS
	 * las series en cada guardado, así que cambiar el título de un pack sacaba
	 * dos errores rojos —«Atelier Of Witch Hat: No se puede habilitar» y
	 * «Dorohedoro: No se puede habilitar»— sobre series que están habilitadas y
	 * sirviendo su pack. El dueño no tocó ninguna de las dos.
	 *
	 * El coste no es el ruido: es que enseña a ignorar los avisos. El día que uno
	 * sea de verdad, ya se habrá aprendido a descartarlo.
	 */
	public function test_una_serie_que_nadie_toco_no_saca_errores(): void {
		// Profundidad 0: con las reglas de hoy, esta serie NO pasaría la
		// validación. Es el caso de Dorohedoro, que está activa y sirviendo.
		$controller = $this->controller_with_depth( static fn (): int => 0 );
		$current    = array(
			'20' => array( 'enabled' => true, 'tiers' => array( array( 'min' => 7, 'pct' => 10 ) ), 'owner_note' => 'viene de antes' ),
		);
		$submitted  = array(
			20 => array( 'enabled' => true, 'tiers' => array( array( 'min' => 7, 'pct' => 10 ) ) ),
		);

		$method = new \ReflectionMethod( $controller, 'validate_submitted_config' );
		$method->setAccessible( true );
		/** @var array{config:array<string|int,mixed>,notices:array<int,array{type:string,message:string}>} $actual */
		$actual = $method->invoke( $controller, $current, $submitted );

		self::assertSame( array(), $actual['notices'], 'Nadie tocó esa serie: no hay nada que reprochar.' );
		self::assertSame( $current['20'], $actual['config']['20'], 'Y se conserva tal cual estaba, con sus notas.' );
	}

	/**
	 * @testdox Pero si SÍ se cambia un tramo, se vuelve a validar y se rechaza.
	 *
	 * La otra mitad. Sin ella, el arreglo de arriba sería una puerta abierta:
	 * cualquier serie activa quedaría exenta de validación para siempre.
	 */
	public function test_un_cambio_de_verdad_si_se_valida(): void {
		$controller = $this->controller_with_depth( static fn (): int => 0 );
		$current    = array(
			'20' => array( 'enabled' => true, 'tiers' => array( array( 'min' => 7, 'pct' => 10 ) ) ),
		);
		$submitted  = array(
			20 => array( 'enabled' => true, 'tiers' => array( array( 'min' => 7, 'pct' => 15 ) ) ),
		);

		$method = new \ReflectionMethod( $controller, 'validate_submitted_config' );
		$method->setAccessible( true );
		/** @var array{config:array<string|int,mixed>,notices:array<int,array{type:string,message:string}>} $actual */
		$actual = $method->invoke( $controller, $current, $submitted );

		self::assertCount( 1, $actual['notices'], 'Cambiar el porcentaje es un cambio: se valida.' );
		self::assertSame( $current['20'], $actual['config']['20'], 'Y lo rechazado conserva lo anterior.' );
	}

	/**
	 * @testdox Encender una serie apagada SIEMPRE se valida, aunque los tramos coincidan.
	 */
	public function test_encender_una_apagada_siempre_se_valida(): void {
		$controller = $this->controller_with_depth( static fn (): int => 0 );
		$current    = array(
			'20' => array( 'enabled' => false, 'tiers' => array( array( 'min' => 7, 'pct' => 10 ) ) ),
		);
		$submitted  = array(
			20 => array( 'enabled' => true, 'tiers' => array( array( 'min' => 7, 'pct' => 10 ) ) ),
		);

		$method = new \ReflectionMethod( $controller, 'validate_submitted_config' );
		$method->setAccessible( true );
		/** @var array{notices:array<int,array{type:string,message:string}>} $actual */
		$actual = $method->invoke( $controller, $current, $submitted );

		self::assertCount( 1, $actual['notices'], 'Pasar de apagada a encendida es un cambio, y ahí la validación es lo que se quiere.' );
	}

	/** @testdox Las bajas se aplican antes de las altas y permiten activar la serie que deja de estar solapada. */
	public function test_manual_save_applies_disables_before_validating_new_enables(): void {
		$planned_for_second = null;
		$controller         = $this->controller_with_depth(
			static function ( int $term_id, array $planned ) use ( &$planned_for_second ): int {
				if ( 11 === $term_id ) {
					$planned_for_second = $planned;

					return in_array( 10, $planned, true ) ? 0 : 5;
				}

				return 5;
			}
		);
		$current            = array(
			'10' => array( 'enabled' => true, 'tiers' => array( array( 'min' => 5, 'pct' => 8 ) ) ),
			'11' => array( 'enabled' => false, 'tiers' => array( array( 'min' => 5, 'pct' => 8 ) ) ),
		);
		$submitted          = array(
			10 => array( 'enabled' => false, 'tiers' => array( array( 'min' => 5, 'pct' => 8 ) ) ),
			11 => array( 'enabled' => true, 'tiers' => array( array( 'min' => 5, 'pct' => 8 ) ) ),
		);

		$method = new \ReflectionMethod( $controller, 'validate_submitted_config' );
		$method->setAccessible( true );
		/** @var array{config:array<string|int,mixed>,notices:array<int,array{type:string,message:string}>} $actual */
		$actual = $method->invoke( $controller, $current, $submitted );

		self::assertSame( array(), $planned_for_second );
		self::assertFalse( $actual['config']['10']['enabled'] );
		self::assertTrue( $actual['config']['11']['enabled'] );
		self::assertSame( array(), $actual['notices'] );
	}

	private function controller_with_depth( callable $depth_override ): BypConfigController {
		$settings = new Settings();
		$policy   = new PromotionPolicy(
			$settings,
			new SeriesDepth( $settings ),
			new CartSeriesCollector( $settings, new EligibilityGuard( $settings ) ),
			$depth_override
		);

		return new BypConfigController( $settings, new VolumeTierResolver(), $policy );
	}
	/**
	 * @testdox Una serie rechazada se devuelve tal como se escribió, para repintarla.
	 *
	 * El guardado conserva la configuración ANTERIOR de la serie rechazada —eso
	 * está bien y tiene su propia prueba—, pero el formulario se repintaba desde
	 * esa configuración anterior: el dueño leía «revisa los errores» y encontraba
	 * sus tramos borrados. Corregir un tramo inválido exige poder verlo.
	 */
	public function test_a_rejected_series_is_handed_back_verbatim(): void {
		$controller = $this->controller_with_depth( static fn( int $term_id ): int => 0 );

		$submitted = array(
			10 => array(
				'enabled' => true,
				'tiers'   => array( array( 'min' => 5, 'pct' => 8 ), array( 'min' => 5, 'pct' => 12 ) ),
			),
		);

		$method = new \ReflectionMethod( $controller, 'validate_submitted_config' );
		$method->setAccessible( true );
		$actual = $method->invoke( $controller, array(), $submitted );

		self::assertNotSame( array(), $actual['notices'], 'El caso de prueba tiene que ser realmente inválido.' );
		self::assertArrayHasKey( 10, $actual['rejected'], 'Sin esto el formulario no tiene qué repintar.' );
		self::assertTrue( (bool) $actual['rejected'][10]['enabled'] );
		self::assertNotSame( array(), $actual['rejected'][10]['tiers'] );
	}
}
