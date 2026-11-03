<?php
/**
 * El tope de lote del relleno acota lo PROCESADO, no lo rellenado.
 *
 * Contando rellenos, un candidato sin tomo siguiente no consumía cupo: el cron
 * recorría la lista ENTERA de candidatos todos los días —con su búsqueda de
 * volúmenes por producto— y el tope sólo limitaba las escrituras, que es
 * justamente la parte barata (auditoría 2026-08-31).
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace {
	defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );
	defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );
}

namespace Akibara\Business\Tests\Modules\UpsellsAutofill {

	use Akibara\Business\Modules\UpsellsAutofill\UpsellsAutofill;
	use Brain\Monkey;
	use Brain\Monkey\Functions;
	use PHPUnit\Framework\TestCase;
	use ReflectionMethod;

	final class BatchCapTest extends TestCase {

		protected function setUp(): void {
			parent::setUp();
			Monkey\setUp();

			// Sin términos de serie no hay tomo siguiente: cada candidato que se
			// PROCESA cuenta como saltado, y ese contador es la medida exacta de
			// cuántos recorrió el lote.
			Functions\when( 'get_the_terms' )->justReturn( false );

			$GLOBALS['wpdb'] = new class() {
				public string $posts              = 'wp_posts';
				public string $postmeta           = 'wp_postmeta';
				public string $term_relationships = 'wp_term_relationships';
				public string $term_taxonomy      = 'wp_term_taxonomy';

				/** @return array<int, string> */
				public function get_col( string $query ): array {
					return array_map( 'strval', range( 1, 10 ) );
				}
			};
		}

		protected function tearDown(): void {
			unset( $GLOBALS['wpdb'] );
			Monkey\tearDown();
			parent::tearDown();
		}

		/**
		 * @param int $max_updates Tope del lote.
		 * @return array{candidates:int, filled:int, skipped:int, updated_ids:array<int,int>}
		 */
		private function correr( int $max_updates ): array {
			$metodo = new ReflectionMethod( UpsellsAutofill::class, 'run_fill' );
			$metodo->setAccessible( true );

			return $metodo->invoke( new UpsellsAutofill(), true, 0, false, $max_updates );
		}

		/**
		 * @testdox Con diez candidatos y tope de tres, se recorren tres.
		 */
		public function test_el_tope_corta_el_recorrido(): void {
			$stats = $this->correr( 3 );

			self::assertSame( 10, $stats['candidates'], 'Los candidatos son los que hay, no los que se procesan.' );
			self::assertSame(
				3,
				$stats['skipped'],
				'El tope tiene que cortar el recorrido: contando sólo rellenos, el cron pasaba por los diez.'
			);
			self::assertSame( 0, $stats['filled'] );
		}

		/**
		 * @testdox Sin tope se recorren todos.
		 */
		public function test_sin_tope_se_recorren_todos(): void {
			self::assertSame( 10, $this->correr( 0 )['skipped'] );
		}

		/**
		 * @testdox Un tope mayor que la lista no inventa recorrido.
		 */
		public function test_un_tope_holgado_no_cambia_nada(): void {
			self::assertSame( 10, $this->correr( 100 )['skipped'] );
		}
	}
}
