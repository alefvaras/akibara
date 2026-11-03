<?php
/**
 * El botón «Recalcular la vitrina ahora» tiene que decir la verdad.
 *
 * Defecto declarado en el manual del dueño: «"Ya había un recálculo en cola"
 * puede salir sin nada pendiente». La causa es `as_next_scheduled_action()`,
 * que devuelve también la acción EN CURSO (y las vencidas), así que pulsar el
 * botón mientras corría la vitrina diaria contestaba «ya había uno en cola» y
 * el clic no encolaba nada.
 *
 * S-1c0e2031 (2026-09-19): ese mismo distingo de «en curso» se usó para NO
 * encolar nada, y con eso un producto que pasa a `publish` mientras la vitrina
 * se recalcula quedaba sin recogerse hasta la corrida diaria (hasta 24 h) o el
 * próximo publish/unpublish. Ahora, en curso o no, si no hay nada PENDIENTE se
 * encola una corrida siguiente — el mismo guard que
 * `SeriesFollow\DigestRunner::schedule_digest()` ya usa.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\CatalogOrder;

use Akibara\Business\Modules\CatalogOrder\CatalogOrder;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class CatalogOrderAvisoRecalculoTest extends TestCase {

	/** @var array<int,array{0:int,1:string}> */
	private array $encoladas = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->encoladas = array();

		Functions\when( 'as_schedule_single_action' )->alias(
			function ( int $timestamp, string $hook ): int {
				$this->encoladas[] = array( $timestamp, $hook );
				return count( $this->encoladas );
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** Llama al método privado igual que lo llama el botón del panel. */
	private function pulsar(): string {
		$m = new \ReflectionMethod( CatalogOrder::class, 'schedule_recompute' );
		$m->setAccessible( true );

		return (string) $m->invoke( new CatalogOrder(), 0 );
	}

	/**
	 * Modela el contrato REAL de Action Scheduler (S-1c0e2031, revisión
	 * independiente 2026-09-19): `as_has_scheduled_action()` no es una bandera
	 * independiente de «en curso» — `functions.php` de este mismo repo la
	 * define consultando `status => array( STATUS_RUNNING, STATUS_PENDING )`,
	 * así que responde `true` si hay PENDIENTE *o* EN CURSO. La versión
	 * anterior de esta prueba mockeaba las dos señales por separado
	 * (`estado(false, true)`), una combinación que el Action Scheduler real
	 * nunca produce y que tapaba que `hay_recalculo_pendiente()` seguía
	 * usando esa función para decidir el guard.
	 */
	private function estado( bool $pendiente, bool $en_curso ): void {
		Functions\when( 'as_has_scheduled_action' )->justReturn( $pendiente || $en_curso );

		Functions\when( 'as_get_scheduled_actions' )->alias(
			function ( array $query ) use ( $pendiente, $en_curso ) {
				switch ( $query['status'] ?? null ) {
					case 'pending':
						return $pendiente ? array( 123 ) : array();
					case 'in-progress':
						return $en_curso ? array( 456 ) : array();
					default:
						return array();
				}
			}
		);
	}

	/** @testdox Sin nada pendiente ni corriendo, el botón encola. */
	public function test_encola_cuando_no_hay_nada(): void {
		$this->estado( false, false );

		self::assertSame( 'encolada', $this->pulsar() );
		self::assertCount( 1, $this->encoladas );
	}

	/** @testdox Con un recálculo esperando en la cola, no se duplica. */
	public function test_no_duplica_lo_pendiente(): void {
		$this->estado( true, false );

		self::assertSame( 'ya-encolada', $this->pulsar() );
		self::assertSame( array(), $this->encoladas, 'Nada que encolar: ya hay uno esperando.' );
	}

	/**
	 * El caso del defecto original: la vitrina se está recalculando AHORA y no
	 * hay nada pendiente. Antes se contestaba «ya había un recálculo en cola»,
	 * que era falso; ahora se dice lo que pasa Y ADEMÁS se encola una corrida
	 * siguiente (S-1c0e2031: sin eso, un `publish` ocurrido mientras la corrida
	 * en curso ya leyó el catálogo se perdía hasta la corrida diaria).
	 *
	 * @testdox Con una corrida en curso se dice eso Y se encola la siguiente.
	 */
	public function test_distingue_la_corrida_en_curso(): void {
		$this->estado( false, true );

		self::assertSame( 'en-curso-y-encolada', $this->pulsar() );
		self::assertCount( 1, $this->encoladas, 'S-1c0e2031: en curso no es un candado, hay que encolar la siguiente para no perder lo publicado mientras tanto.' );
	}

	/**
	 * S-1c0e2031: el caso real que perdía altas de catálogo. Un producto pasa a
	 * `publish` (dispara `on_status_change()`, la vía real del bug, no la
	 * llamada directa a `schedule_recompute()`) mientras la vitrina ya está
	 * recalculándose y nada más está en cola: antes del arreglo, ese aviso no
	 * encolaba nada y el producto quedaba sin ordenar hasta la corrida diaria
	 * (hasta 24 h) o el próximo publish/unpublish.
	 *
	 * @testdox Publicar un producto mientras la vitrina se recalcula SÍ encola la siguiente corrida.
	 */
	public function test_publicar_durante_un_recalculo_en_curso_no_pierde_el_alta(): void {
		$this->estado( false, true );

		( new CatalogOrder() )->on_status_change( 'publish', 'draft', new \WP_Post() );

		self::assertCount(
			1,
			$this->encoladas,
			'El alta publicada mientras la vitrina se recalculaba se perdía: nada quedaba encolado para recogerla.'
		);
	}
}
