<?php
/**
 * Toda option vigilada tiene que tener su validador. Sin excepciones.
 *
 * El 2026-09-05 la tienda estuvo tres horas sin buscador (503) y con el catálogo
 * de 1.631 productos servido SIN filtros. La causa no fue un fallo de infra ni
 * una carrera: `akb_discount_max_pct` estaba en `options()` —la lista de bytes
 * que forman la foto autoritativa— pero NO tenía rama en
 * `valid_source_option_value()`, así que caía en el `return false` final y era
 * inválida con cualquier valor, incluido su propio default de 15.
 *
 * La cascada, medida en producción: foto no autoritativa → `stage_global()` sin
 * frame → `filter_pre_update_option()` devuelve el valor viejo (las escrituras
 * se rechazan EN SILENCIO y `update_option()` da false, así que el dueño no
 * puede ni encender ni apagar nada desde el panel) → `capture_price_authority()`
 * null → 1.625 filas de trabajo en `blocked` → `serving_ready()` false → 503 y
 * facetas apagadas. Un candado que se cierra por dentro: ni el panel, ni WP-CLI,
 * ni el watchdog podían reabrirlo.
 *
 * Bastó que la fila existiera en la base para que todo cayera, y basta con
 * añadir a `options()` una option nueva sin validador para repetirlo. Este
 * candado es justamente eso: las dos listas no pueden volver a divergir.
 *
 * @package Akibara\Search\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Search\Tests;

use Akibara\Search\EditorialDiscountPublication;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class EditorialSourceOptionDomainTest extends TestCase {

	/**
	 * @return array<int, string>
	 */
	private function watched_options(): array {
		$method = new ReflectionMethod( EditorialDiscountPublication::class, 'options' );
		$method->setAccessible( true );

		return (array) $method->invoke( null );
	}

	/**
	 * @param string $name  Option.
	 * @param string $value Valor candidato.
	 * @return bool
	 */
	private function valid( string $name, string $value ): bool {
		$method = new ReflectionMethod( EditorialDiscountPublication::class, 'valid_source_option_value' );
		$method->setAccessible( true );

		return (bool) $method->invoke( null, $name, $value );
	}

	/**
	 * El candado de fondo: si una option entra en la foto sin validador, su
	 * PRIMERA escritura deja la tienda sin buscador ni filtros, en silencio.
	 *
	 * @testdox Cada option vigilada acepta al menos un valor: ninguna queda sin validador.
	 */
	public function test_every_watched_option_has_a_reachable_domain(): void {
		// Un candidato válido por option, escrito a mano. Si alguien añade una
		// option a options() y no la valida, este test cae con su nombre.
		$candidatos = array(
			'akibara_taxonomy_discount_enabled'             => array( 'yes', 'no' ),
			'akibara_taxonomy_discount_from'                => array( '', '2026-09-05' ),
			'akibara_taxonomy_discount_to'                  => array( '', '2026-09-07' ),
			'akibara_taxonomy_discount_apply_to'            => array( 'instock_only', 'all' ),
			'akibara_taxonomy_discount_sale_policy'         => array( 'greatest_wins', 'from_regular' ),
			'akibara_business_search_feed_contract_version' => array( '2' ),
			'akb_discount_max_pct'                          => array( '1', '15', '90' ),
			'woocommerce_price_num_decimals'                => array( '0', '2' ),
			'timezone_string'                               => array( 'America/Santiago' ),
			'gmt_offset'                                    => array( '-4', '0', '5.5' ),
		);

		foreach ( $this->watched_options() as $option ) {
			self::assertArrayHasKey(
				$option,
				$candidatos,
				sprintf(
					'«%s» entró en options() sin candidato conocido. Añádele su dominio en valid_source_option_value() Y aquí: una option vigilada sin validador deja la tienda sin buscador ni filtros en cuanto se escribe.',
					$option
				)
			);

			$acepta = false;
			foreach ( $candidatos[ $option ] as $valor ) {
				if ( $this->valid( $option, $valor ) ) {
					$acepta = true;
					break;
				}
			}

			self::assertTrue(
				$acepta,
				sprintf(
					'«%s» rechaza TODOS sus valores legítimos: cae en el «return false» final de valid_source_option_value(). Eso invalida la foto autoritativa para siempre y cierra el buscador (incidente 2026-09-05).',
					$option
				)
			);
		}
	}

	/**
	 * El caso exacto que se cobró la caída, con su default incluido.
	 *
	 * @testdox El tope de descuento acepta su dominio real (1..90) y su propio default de 15.
	 */
	public function test_the_discount_ceiling_accepts_its_own_domain(): void {
		foreach ( array( '1', '10', '15', '50', '90' ) as $valor ) {
			self::assertTrue( $this->valid( 'akb_discount_max_pct', $valor ), $valor . ' es un tope legítimo.' );
		}
	}

	/**
	 * Fallar cerrado sigue siendo lo correcto para lo que NO es del dominio: el
	 * defecto no era el «return false», era que faltaba la rama.
	 *
	 * @testdox Fuera de 1..90, o con forma rara, el tope se rechaza.
	 */
	public function test_the_discount_ceiling_rejects_everything_else(): void {
		foreach ( array( '0', '91', '100', '-5', '', '015', '15.5', 'abc', ' 15', '15 ', '+15' ) as $valor ) {
			self::assertFalse( $this->valid( 'akb_discount_max_pct', $valor ), '«' . $valor . '» no puede pasar.' );
		}
	}

	/**
	 * @testdox Una option que no está en la foto se rechaza igual que antes.
	 */
	public function test_unknown_options_still_fail_closed(): void {
		self::assertFalse( $this->valid( 'akb_option_que_no_existe', 'yes' ) );
		self::assertFalse( $this->valid( '', '' ) );
	}
}
