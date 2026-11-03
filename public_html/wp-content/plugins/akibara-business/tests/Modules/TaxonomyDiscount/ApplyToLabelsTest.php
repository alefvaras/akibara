<?php
/**
 * El alcance del descuento: la etiqueta dice la verdad y los VALORES no se tocan.
 *
 * «Disponibles + preventas» nombraba el conjunto vacío y callaba el que sí
 * existe. Medido en producción el 2026-09-05: 1.131 disponibles, CERO preventas
 * y 398 a pedido. Elegir esa opción creyendo que añadía reservas ponía el
 * descuento sobre casi 400 tomos que hay que pedir a la editorial — 324 sólo en
 * Ivrea Argentina, la marca de la promo de ese fin de semana. Es una decisión de
 * margen, no de etiquetas.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\TaxonomyDiscount;

use Akibara\Business\Modules\TaxonomyDiscount\Settings;
use Akibara\Business\Modules\TaxonomyDiscount\TaxonomyDiscount;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class ApplyToLabelsTest extends TestCase {

	/** @var mixed El `$wpdb` de la suite, para devolverlo tal cual. */
	private $wpdb_original = null;

	private bool $tenia_wpdb = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// El conteo de preventas pregunta la hora de la tienda. Se dobla aquí y
		// no en cada prueba: al correr el fichero solo lo resolvía otro test que
		// ya lo había definido, y en la suite entera el resultado dependía del
		// orden [[function-exists-miente-en-la-suite]].
		Functions\when( 'wp_timezone' )->alias( static fn(): \DateTimeZone => new \DateTimeZone( 'America/Santiago' ) );
		// Se guarda y se REPONE, nunca se hace `unset`: dejar la suite sin
		// `$wpdb` rompe a quien corra después, y el fallo aparece a kilómetros de
		// aquí — dos pruebas de LlmsManifest, verdes en aislamiento y rojas en la
		// suite completa.
		$this->tenia_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_original = $GLOBALS['wpdb'] ?? null;

		Functions\when( 'number_format_i18n' )->alias(
			static fn( $n ): string => number_format( (float) $n, 0, ',', '.' )
		);
		$this->reset_memo();
	}

	protected function tearDown(): void {
		$this->reset_memo();
		if ( $this->tenia_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_original;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/** El memo vive por petición: entre pruebas hay que devolverlo a null. */
	private function reset_memo(): void {
		$p = new \ReflectionProperty( TaxonomyDiscount::class, 'stock_counts_memo' );
		$p->setAccessible( true );
		$p->setValue( null, null );
	}

	/**
	 * `$wpdb` doble que devuelve el catálogo pedido, o falla si `$error` viene.
	 *
	 * @param array<string, int> $por_estado meta_value => cuántos.
	 */
	private function con_catalogo( array $por_estado, string $error = '', int $preventas = 0 ): void {
		$GLOBALS['wpdb'] = new class( $por_estado, $error, $preventas ) {
			public string $last_error;
			public string $postmeta = 'wp_postmeta';
			public string $posts    = 'wp_posts';
			/** @var array<string, int> */
			private array $filas;

			/** El error que la CONSULTA producirá, no el que hay antes de ella. */
			private string $error_al_consultar;

			/** Cuántos reservables son PREVENTA de verdad (lanzamiento pendiente). */
			private int $preventas;

			/**
			 * @param array<string, int> $filas     Conteo por estado.
			 * @param string             $error     Error a producir.
			 * @param int                $preventas Reservables con lanzamiento pendiente.
			 */
			public function __construct( array $filas, string $error, int $preventas = 0 ) {
				$this->filas              = $filas;
				$this->error_al_consultar = $error;
				$this->preventas          = $preventas;
				$this->last_error         = '';
			}

			/**
			 * El conteo de preventas va por `get_var`, no por `get_results`.
			 *
			 * @param string $sql Consulta.
			 * @return string|null
			 */
			public function get_var( string $sql ) {
				if ( '' !== $this->error_al_consultar ) {
					$this->last_error = $this->error_al_consultar;

					return null;
				}

				return (string) $this->preventas;
			}

			/** @param mixed ...$args Valores del prepare. */
			public function prepare( string $sql, ...$args ): string {
				return $sql;
			}

			/**
			 * @param string $sql    Consulta.
			 * @param string $output Formato.
			 * @return array<int, array<string, mixed>>|null
			 */
			public function get_results( string $sql, string $output = 'OBJECT' ) {
				// Como el `$wpdb` real: el llamador limpia `last_error` ANTES de
				// consultar, así que el error tiene que aparecer AQUÍ. Ponerlo en
				// el constructor lo borraba el propio código bajo prueba y el
				// doble dejaba de modelar el fallo que decía modelar.
				if ( '' !== $this->error_al_consultar ) {
					$this->last_error = $this->error_al_consultar;

					return null;
				}
				$salida = array();
				foreach ( $this->filas as $estado => $n ) {
					$salida[] = array(
						'estado' => $estado,
						'n'      => $n,
					);
				}

				return $salida;
			}
		};
	}

	/**
	 * @return array<string, string>
	 */
	private function labels(): array {
		$m = new ReflectionMethod( TaxonomyDiscount::class, 'apply_to_labels' );
		$m->setAccessible( true );

		return (array) $m->invoke( null );
	}

	/**
	 * EL CANDADO QUE IMPORTA. Los valores son parte del contrato de la foto
	 * editorial que valida akibara-search: cambiar uno hace que la foto se
	 * rechace ENTERA y se apague el buscador con sus facetas. El 2026-09-05 esa
	 * misma clase de desajuste dejó la tienda tres horas sin buscar.
	 *
	 * @testdox Los valores del alcance NO cambian: son contrato con el buscador.
	 */
	public function test_the_values_are_a_contract_and_never_change(): void {
		$this->con_catalogo(
			array(
				'instock'     => 1131,
				'onbackorder' => 398,
				'outofstock'  => 102,
			)
		);

		self::assertSame(
			array( 'instock_only', 'instock_and_onbackorder', 'all' ),
			array_keys( $this->labels() ),
			'Estos tres literales viajan en la foto de precios y los valida AdoptedSnapshot::valid_option_value(). Renombrar la ETIQUETA es seguro; tocar el VALOR apaga el buscador.'
		);
		self::assertSame( Settings::APPLY_INSTOCK_ONLY, 'instock_only' );
		self::assertSame( Settings::APPLY_INSTOCK_AND_ONBACKORDER, 'instock_and_onbackorder' );
		self::assertSame( Settings::APPLY_ALL, 'all' );
	}

	/**
	 * @testdox La etiqueta dice «a pedido», que es lo que de verdad añade, y trae su número.
	 */
	public function test_the_label_names_what_it_really_adds(): void {
		$this->con_catalogo(
			array(
				'instock'     => 1131,
				'onbackorder' => 398,
				'outofstock'  => 102,
			)
		);

		$labels = $this->labels();

		self::assertStringContainsString( 'a pedido', $labels[ Settings::APPLY_INSTOCK_AND_ONBACKORDER ] );
		self::assertStringNotContainsString(
			'preventa',
			mb_strtolower( implode( ' ', $labels ) ),
			'Preventas hay CERO: nombrarlas esconde los 398 productos que sí entran.'
		);
		self::assertStringContainsString( '(1.529)', $labels[ Settings::APPLY_INSTOCK_AND_ONBACKORDER ], '1.131 + 398.' );
		self::assertStringContainsString( '(1.131)', $labels[ Settings::APPLY_INSTOCK_ONLY ] );
		self::assertStringContainsString( '(1.631)', $labels[ Settings::APPLY_ALL ] );
	}

	/**
	 * Un número inventado sería peor que ninguno: sobre esa cifra se decide a
	 * cuántos tomos se les aplica el descuento.
	 *
	 * @testdox Si el conteo no se puede leer, la etiqueta sale SIN número.
	 */
	public function test_without_a_reliable_count_no_number_is_shown(): void {
		$this->con_catalogo( array(), 'Lost connection to MySQL server' );

		$labels = $this->labels();

		foreach ( $labels as $etiqueta ) {
			// «(en stock)» es parte de la etiqueta de siempre; lo que no puede
			// aparecer es un paréntesis con cifras dentro.
			self::assertDoesNotMatchRegularExpression(
				'/\(\s*[\d.,]+\s*\)/',
				$etiqueta,
				'Sin conteo fiable no se pinta un número: uno inventado sería peor que ninguno.'
			);
		}
		self::assertStringContainsString( 'a pedido', $labels[ Settings::APPLY_INSTOCK_AND_ONBACKORDER ] );
	}

	/**
	 * @testdox El número de «Disponibles + a pedido» NO cuenta las preventas.
	 *
	 * Hallazgo S-bbc79576: `onbackorder` agrupa «a pedido» y «preventa», y el
	 * descuento editorial excluye las preventas por decisión del dueño. Sumarlas
	 * prometía más productos de los que de verdad reciben el descuento, y ese
	 * número es sobre el que se decide el margen.
	 */
	public function test_el_conteo_descuenta_las_preventas(): void {
		$this->reset_memo();
		$this->con_catalogo( array( 'instock' => 100, 'onbackorder' => 30, 'outofstock' => 5 ), '', 12 );

		$etiquetas = $this->labels();

		// 100 disponibles + (30 reservables − 12 preventas) = 118.
		self::assertStringContainsString( '(118)', $etiquetas[ Settings::APPLY_INSTOCK_AND_ONBACKORDER ] );
		self::assertStringContainsString( '(100)', $etiquetas[ Settings::APPLY_INSTOCK_ONLY ] );
	}

	/** @testdox Sin preventas en el catálogo, el número es el de siempre. */
	public function test_sin_preventas_el_conteo_no_cambia(): void {
		$this->reset_memo();
		$this->con_catalogo( array( 'instock' => 100, 'onbackorder' => 30, 'outofstock' => 5 ), '', 0 );

		self::assertStringContainsString( '(130)', $this->labels()[ Settings::APPLY_INSTOCK_AND_ONBACKORDER ] );
	}
}
