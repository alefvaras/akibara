<?php
/**
 * El barrido de preventas vencidas: refresca, avanza y NO miente la fecha.
 *
 * El cron toca los reservables cuya fecha de lanzamiento ya pasó para que el
 * buscador reindexe y las cachés de página se purguen — el estado se DERIVA al
 * leer, así que no hay meta que mutar. Dos defectos que arrastraba (auditoría
 * 2026-08-31):
 *
 *   1. `wp_update_post` reescribe `post_modified`, y ese campo alimenta el
 *      `lastmod` del sitemap y el `dateModified` de las landings de serie: el
 *      barrido le juraba a Google que hasta 50 fichas se editaban cada día, para
 *      siempre, cuando lo único que cambiaba era la caché.
 *   2. Nada marcaba lo procesado, así que la consulta devolvía la MISMA lista
 *      todos los días: el refresco ya estaba hecho desde el primer día, y los
 *      que caían fuera del tope de 50 no llegaban a ser tocados nunca.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\Stock;

use Akibara\Business\Modules\Stock\PreventaVencidaCron;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class PreventaVencidaCronTest extends TestCase {

	/** @var array<int, array<string, mixed>> Llamadas a wp_update_post. */
	private array $updates = array();

	/** @var array<string, array<string, mixed>> Metas escritas: "id|key" => valor. */
	private array $metas = array();

	/** @var array<int, array<string, mixed>> Consultas pedidas a get_posts. */
	private array $queries = array();

	/** @var array<int, int> IDs que devuelve get_posts. */
	private array $candidatos = array( 11, 22 );

	/** @var array<int, callable> Filtros vivos sobre wp_insert_post_data. */
	private array $filtros = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->updates    = array();
		$this->metas      = array();
		$this->queries    = array();
		$this->filtros    = array();
		$this->candidatos = array( 11, 22 );

		Functions\when( 'wp_timezone' )->alias(
			static fn(): \DateTimeZone => new \DateTimeZone( 'America/Santiago' )
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'get_posts' )->alias(
			function ( array $args ): array {
				$this->queries[] = $args;
				return $this->candidatos;
			}
		);
		Functions\when( 'get_post' )->alias(
			static fn( int $id ) => (object) array(
				'ID'                => $id,
				'post_modified'     => '2026-01-05 10:00:00',
				'post_modified_gmt' => '2026-01-05 13:00:00',
			)
		);
		Functions\when( 'update_post_meta' )->alias(
			function ( int $id, string $key, $value ): bool {
				$this->metas[ $id . '|' . $key ] = $value;
				return true;
			}
		);
		Functions\when( 'add_filter' )->alias(
			function ( string $hook, $callback ): bool {
				if ( 'wp_insert_post_data' === $hook ) {
					$this->filtros[] = $callback;
				}
				return true;
			}
		);
		Functions\when( 'remove_filter' )->alias(
			function ( string $hook, $callback ): bool {
				$this->filtros = array();
				return true;
			}
		);
		Functions\when( 'wp_update_post' )->alias(
			function ( array $post ) {
				// Reproduce lo que hace WordPress: arma $data con el
				// post_modified de AHORA y lo pasa por el filtro justo antes del
				// UPDATE. Ese es el punto exacto donde se repone la fecha.
				$data = array(
					'post_modified'     => '2026-08-31 23:59:59',
					'post_modified_gmt' => '2026-09-01 02:59:59',
				);
				foreach ( $this->filtros as $filtro ) {
					$data = $filtro( $data, $post );
				}
				$this->updates[] = array(
					'id'   => $post['ID'],
					'data' => $data,
				);
				return (int) $post['ID'];
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox Toca los vencidos sin moverles la fecha de modificación.
	 */
	public function test_el_touch_conserva_post_modified(): void {
		$tocados = ( new PreventaVencidaCron() )->handle();

		self::assertSame( 2, $tocados );
		self::assertCount( 2, $this->updates );

		foreach ( $this->updates as $update ) {
			self::assertSame(
				'2026-01-05 10:00:00',
				$update['data']['post_modified'],
				'El barrido no puede reescribir el lastmod del sitemap: sólo refresca cachés.'
			);
			self::assertSame( '2026-01-05 13:00:00', $update['data']['post_modified_gmt'] );
		}
	}

	/**
	 * @testdox Marca lo procesado para que el cursor avance.
	 */
	public function test_marca_lo_procesado(): void {
		( new PreventaVencidaCron() )->handle();

		self::assertSame( '1', $this->metas[ '11|' . PreventaVencidaCron::META_SWEPT ] ?? null );
		self::assertSame( '1', $this->metas[ '22|' . PreventaVencidaCron::META_SWEPT ] ?? null );
	}

	/**
	 * @testdox La consulta EXCLUYE lo ya barrido, que es lo que hace avanzar el tope.
	 */
	public function test_la_consulta_excluye_lo_ya_barrido(): void {
		( new PreventaVencidaCron() )->handle();

		$meta_query = $this->queries[0]['meta_query'];
		$claves     = array_column( $meta_query, 'key' );

		self::assertContains( PreventaVencidaCron::META_SWEPT, $claves );

		foreach ( $meta_query as $clausula ) {
			if ( PreventaVencidaCron::META_SWEPT === ( $clausula['key'] ?? '' ) ) {
				self::assertSame( 'NOT EXISTS', $clausula['compare'] );
			}
		}
	}

	/**
	 * @testdox Mover la fecha de lanzamiento borra la marca: ese vencimiento se barre otra vez.
	 */
	public function test_cambiar_la_fecha_olvida_el_barrido(): void {
		$borradas = array();
		Functions\when( 'delete_post_meta' )->alias(
			static function ( int $id, string $key ) use ( &$borradas ): bool {
				$borradas[] = $id . '|' . $key;
				return true;
			}
		);

		( new PreventaVencidaCron() )->forget_sweep( 77 );

		self::assertSame( array( '77|' . PreventaVencidaCron::META_SWEPT ), $borradas );
	}
}
