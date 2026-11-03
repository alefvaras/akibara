<?php
/**
 * Contrato del puntaje de «Lo más solicitado» (AdminDashboard::score_demand).
 *
 * El ranking decide compras del dueño, así que el orden tiene que ser
 * EXPLICABLE y estable: esperando aviso ×3 > búsqueda sin resultado ×2 >
 * favorito/venta ×1. Función pura a propósito — se prueba sin WP.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\AdminDashboard;

use Akibara\Business\Modules\AdminDashboard\AdminDashboard;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * @since 1.109.5
 */
final class DemandRankingTest extends TestCase {

	public function test_waiting_subscribers_outrank_searches_and_favorites(): void {
		$rows = AdminDashboard::score_demand(
			array( 101 => 2 ),                    // 2 esperando aviso  → 6
			array( 202 => 3 ),                    // 3 favoritos        → 3
			array(),
			array(
				array(
					'query' => 'bakemonogatari',
					's90'   => 2,                  // 2 búsquedas       → 4
					's30'   => 1,
				),
			)
		);

		self::assertSame( array( 'product', 'gap', 'product' ), array_column( $rows, 'type' ) );
		self::assertSame( 101, $rows[0]['id'], 'Dos correos esperando encabezan.' );
		self::assertSame( 'bakemonogatari', $rows[1]['query'] );
		self::assertSame( 202, $rows[2]['id'] );
	}

	public function test_signals_for_the_same_product_accumulate(): void {
		$rows = AdminDashboard::score_demand(
			array( 300 => 1 ),
			array( 300 => 2 ),
			array( 300 => 4 ),
			array()
		);

		self::assertCount( 1, $rows );
		self::assertSame( 3 + 2 + 4, $rows[0]['score'] );
		self::assertSame( 1, $rows[0]['esperando'] );
		self::assertSame( 2, $rows[0]['favoritos'] );
		self::assertSame( 4, $rows[0]['ventas'] );
	}

	public function test_caps_at_ten_rows_and_drops_zero_scores(): void {
		$bis = array();
		for ( $i = 1; $i <= 12; $i++ ) {
			$bis[ $i ] = $i; // 12 productos con señal creciente.
		}

		$rows = AdminDashboard::score_demand( $bis, array( 999 => 0 ), array(), array() );

		self::assertCount( 10, $rows );
		self::assertSame( 12, $rows[0]['id'], 'El más pedido encabeza.' );
		self::assertNotContains( 999, array_column( $rows, 'id' ), 'Un score 0 no es demanda: no aparece.' );
	}

	public function test_empty_signals_produce_an_empty_ranking(): void {
		self::assertSame( array(), AdminDashboard::score_demand( array(), array(), array(), array() ) );
	}

	/* --- Continuidad de colecciones (v2) --- */

	public function test_a_collector_streak_outweighs_a_favorite(): void {
		$rows = AdminDashboard::score_demand(
			array(),
			array( 500 => 3 ),          // 3 favoritos → 3
			array(),
			array(),
			array( 400 => 2 )           // 2 coleccionistas en racha → 4
		);

		self::assertSame( 400, $rows[0]['id'], 'Dos coleccionistas en racha valen más que tres favoritos.' );
		self::assertSame( 4, $rows[0]['score'] );
		self::assertSame( 2, $rows[0]['racha'] );
	}

	public function test_a_missing_next_volume_becomes_a_serie_gap_row(): void {
		$rows = AdminDashboard::score_demand(
			array(),
			array(),
			array(),
			array(),
			array(),
			array( array( 'serie' => 'kagurabachi', 'vol' => 8, 'clientes' => 5 ) )
		);

		self::assertSame( 'serie_gap', $rows[0]['type'] );
		self::assertSame( 10, $rows[0]['score'] );
		self::assertSame( 8, $rows[0]['vol'] );
	}

	public function test_unowned_title_requests_rank_with_triple_weight_and_editorial(): void {
		$rows = AdminDashboard::score_demand(
			array(),
			array(),
			array(),
			array(
				array( 'query' => 'inuyasha kanzenban 01', 's90' => 1, 's30' => 1 ),
			),
			array(),
			array(),
			array(
				'inuyasha kanzenban 01' => array(
					'title'     => 'InuYasha (Edición Integral) 01',
					'editorial' => 'Panini',
					'intent'    => 'a_pedido',
					'count'     => 3,
				),
			)
		);

		self::assertCount( 1, $rows );
		self::assertSame( 'gap', $rows[0]['type'] );
		self::assertSame( 'InuYasha (Edición Integral) 01', $rows[0]['query'] );
		self::assertSame( 'Panini', $rows[0]['editorial'] );
		self::assertSame( 3, $rows[0]['solicitudes'] );
		self::assertSame( 3 * 3 + 2 * 1, $rows[0]['score'], 'Cada solicitud pesa x3 y cada búsqueda x2.' );
	}

	public function test_continuity_requires_a_streak_and_reads_the_next_volume(): void {
		$resultado = AdminDashboard::continuity_from_maps(
			array(
				// Cliente 1: tomos 1-2 de ranma (racha) → siguiente 3 (existe, agotado).
				1 => array( 11, 12 ),
				// Cliente 2: SOLO el tomo 1 de ranma → sin racha, no cuenta.
				2 => array( 11 ),
				// Cliente 3: tomos 6-7 de kagurabachi → siguiente 8 SIN ficha.
				3 => array( 26, 27 ),
				// Cliente 4: tomos 1-2, siguiente 3 EN STOCK → no es señal (ya puede comprarlo).
				4 => array( 41, 42 ),
			),
			array(
				11 => array( 'serie' => 'ranma', 'vol' => 1 ),
				12 => array( 'serie' => 'ranma', 'vol' => 2 ),
				13 => array( 'serie' => 'ranma', 'vol' => 3 ),
				26 => array( 'serie' => 'kagurabachi', 'vol' => 6 ),
				27 => array( 'serie' => 'kagurabachi', 'vol' => 7 ),
				41 => array( 'serie' => 'frieren', 'vol' => 1 ),
				42 => array( 'serie' => 'frieren', 'vol' => 2 ),
				43 => array( 'serie' => 'frieren', 'vol' => 3 ),
			),
			array(
				'ranma'       => array( 1 => 11, 2 => 12, 3 => 13 ),
				'kagurabachi' => array( 6 => 26, 7 => 27 ),
				'frieren'     => array( 1 => 41, 2 => 42, 3 => 43 ),
			),
			array(
				13 => 'outofstock',
				43 => 'instock',
			)
		);

		self::assertSame( array( 13 => 1 ), $resultado['productos'], 'Solo el siguiente tomo agotado de una racha real.' );
		self::assertCount( 1, $resultado['huecos'] );
		self::assertSame( 'kagurabachi', $resultado['huecos'][0]['serie'] );
		self::assertSame( 8, $resultado['huecos'][0]['vol'] );
		self::assertSame( 1, $resultado['huecos'][0]['clientes'] );
	}

	/**
	 * Los tres falsos positivos que encontró la revisión adversarial ANTES del
	 * primer deploy — cada uno inflaba la cantidad del pedido sugerido:
	 * recompra del mismo tomo, reimpresión (dos productos, mismo vol) y tomos
	 * salteados. Ninguno es una racha.
	 *
	 * @testdox Should not count repurchases, reprints or skipped volumes as a streak.
	 */
	public function test_streak_rejects_repurchases_reprints_and_skipped_volumes(): void {
		$resultado = AdminDashboard::continuity_from_maps(
			array(
				// Recompra: dos veces el tomo 1 (dos pedidos).
				1 => array( 11, 11 ),
				// Reimpresión: dos productos distintos, mismo serie+vol.
				2 => array( 11, 91 ),
				// Salteados: tomos 1 y 7 — sin el 6, nadie espera el 8.
				3 => array( 11, 17 ),
			),
			array(
				11 => array( 'serie' => 'ranma', 'vol' => 1 ),
				91 => array( 'serie' => 'ranma', 'vol' => 1 ),
				17 => array( 'serie' => 'ranma', 'vol' => 7 ),
				12 => array( 'serie' => 'ranma', 'vol' => 2 ),
				18 => array( 'serie' => 'ranma', 'vol' => 8 ),
			),
			array( 'ranma' => array( 1 => 11, 2 => 12, 7 => 17, 8 => 18 ) ),
			array(
				12 => 'outofstock',
				18 => 'outofstock',
			)
		);

		self::assertSame( array(), $resultado['productos'], 'Ni la recompra, ni la reimpresión, ni los tomos salteados son rachas.' );
		self::assertSame( array(), $resultado['huecos'] );
	}

	/**
	 * El radar «buscados y no encontrados» vive TAMBIÉN en el Centro de Mando
	 * (pedido del dueño 2026-08-30): la cabina diaria es esta, el log completo
	 * queda en Buscador → Búsquedas.
	 *
	 * Antes esto era un grep del fuente de la pantalla entera: pasaba con el
	 * bloque comentado y caía al reformatear una línea. Ahora se PINTA el radar
	 * con huecos de mentira y se mira lo que sale.
	 */
	public function test_the_dashboard_paints_the_searched_not_found_radar(): void {
		$html = $this->render_radar(
			array(
				array(
					'query' => 'bakemonogatari',
					's90'   => 42,
					's30'   => 7,
				),
				array(
					'query' => 'vagabond 38',
					's90'   => 12,
					's30'   => 0,
				),
			)
		);

		self::assertStringContainsString( 'Buscados y no encontrados (90 días)', $html );
		self::assertStringContainsString( '<strong>bakemonogatari</strong>', $html );
		self::assertStringContainsString( '<strong>vagabond 38</strong>', $html );
		// Sin demanda este mes se pinta un guion, no un cero que parezca dato.
		self::assertStringContainsString( '—', $html );
		self::assertStringContainsString( 'Crear ficha', $html );
		self::assertStringContainsString( 'page=akibara-search&sub=busquedas', $html );
		self::assertStringContainsString( 'Ver el log completo en Buscador', $html );
	}

	/**
	 * Sin huecos no se pinta un bloque vacío.
	 */
	public function test_the_radar_disappears_when_there_is_nothing_to_show(): void {
		self::assertSame( '', trim( $this->render_radar( array() ) ) );
	}

	/**
	 * El enlace «Descartar» se lo pide al buscador, que es el dueño de la acción
	 * y su nonce, y desaparece cuando ese puente no da URL.
	 *
	 * `SearchAdminSectionDouble` (abajo) hace de plugin hermano: devuelve una URL
	 * reconocible para una búsqueda y cadena vacía para la otra. Si business
	 * volviera a construir la acción y el nonce a mano, la primera aserción
	 * fallaría —la URL sería otra— y la segunda pintaría un enlace que el
	 * buscador no puede atender.
	 */
	public function test_the_dismiss_link_comes_from_the_search_plugin_or_not_at_all(): void {
		$html = $this->render_radar(
			array(
				array(
					'query' => 'bakemonogatari',
					's90'   => 42,
					's30'   => 7,
				),
				array(
					'query' => 'sin-puente',
					's90'   => 3,
					's30'   => 1,
				),
			)
		);

		self::assertStringContainsString(
			'PUENTE-DEL-BUSCADOR/bakemonogatari',
			$html,
			'La URL sale del puente del buscador, no de literales copiados acá.'
		);
		self::assertSame(
			1,
			substr_count( $html, '>Descartar<span' ),
			'La búsqueda sin URL del puente no puede ofrecer un botón muerto.'
		);
	}

	/**
	 * Pinta el radar con los huecos dados, sin tocar base de datos.
	 *
	 * @param array<int, array<string, mixed>> $huecos Huecos de mentira.
	 */
	private function render_radar( array $huecos ): string {
		Monkey\setUp();

		Functions\when( 'akibara_search_demand_gaps' )->justReturn( $huecos );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'number_format_i18n' )->alias( static fn( $n ): string => (string) $n );
		Functions\when( 'admin_url' )->alias(
			static fn( string $path = '' ): string => 'https://akibara.cl/wp-admin/' . $path
		);
		Functions\when( 'add_query_arg' )->alias(
			static fn( array $args, string $url ): string => $url . '?' . http_build_query( $args )
		);

		ob_start();
		( new AdminDashboard() )->render_demand_gaps_radar();
		$html = (string) ob_get_clean();

		Monkey\tearDown();

		return $html;
	}
}

namespace Akibara\Search;

/**
 * Doble del plugin hermano: business sólo puede consumir su puente.
 *
 * Vive acá con el nombre real porque `AdminDashboard::gap_dismiss_url()` lo
 * busca por `class_exists()`. Devolver '' para una búsqueda concreta simula el
 * caso en que el puente no puede dar URL.
 */
final class SearchAdminSection {

	public const ACTION_GAP_DISMISS = 'akb_search_gap_dismiss';

	public static function gap_action_url( string $action, string $query ): string {
		if ( 'sin-puente' === $query ) {
			return '';
		}

		return 'PUENTE-DEL-BUSCADOR/' . $query . '?action=' . $action;
	}
}
