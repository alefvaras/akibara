<?php
/**
 * Lo que ve el cliente en la bandeja, renderizando las plantillas de verdad.
 *
 * Fichero aparte a propósito: `SeriesFollowEmailTest` dobla
 * `wc_get_template_html()`, así que allí las plantillas nunca corren y el HTML
 * real no se mira nunca. Aquí se hace `require` del fichero, como en
 * `PreventaTemplatesOutstandingQtyTest`.
 *
 * Y se comprueban LAS DOS PARTES. `email_type` es `multipart`: el correo lleva
 * HTML y texto plano siempre, y una mitad que mienta es media promesa rota —la
 * lee justo quien tiene el cliente en modo texto.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\SeriesFollow;

use Akibara\Business\Modules\SeriesFollow\VolumePhrase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class SeriesFollowTemplateTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		// La parte plana no usa esc_html()/esc_url(): borran apóstrofes (revisión 1.116.8).
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_kses' )->alias( static fn( $texto ): string => strip_tags( (string) $texto ) );
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'wp_strip_all_tags' )->returnArg();
		// La cabecera y el pie del correo los pinta WooCommerce; aquí se miden
		// las tres superficies de ESTE módulo.
		Functions\when( 'do_action' )->justReturn( null );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param array<int, array<string, mixed>> $groups   Bloques del correo.
	 * @param string                           $variante 'html' o 'plain'.
	 * @return string
	 */
	private function render( array $groups, string $variante = 'html' ): string {
		// El contrato completo del template, tal como lo declara su docblock.
		$vars = array(
			'groups'             => $groups,
			'collection_url'     => 'https://akibara.test/mi-cuenta/coleccion/',
			'unsubscribe_url'    => 'https://akibara.test/baja/todo',
			'email_heading'      => 'Novedades de tu colección',
			'additional_content' => '',
			'sent_to_admin'      => false,
			'plain_text'         => 'plain' === $variante,
			'email'              => new \stdClass(),
		);

		$ruta = AKIBARA_BUSINESS_DIR . 'templates/emails/'
			. ( 'plain' === $variante ? 'plain/' : '' )
			. 'customer-series-follow.php';

		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- contrato del template.
		extract( $vars );
		unset( $vars );
		ob_start();
		require $ruta;

		return (string) ob_get_clean();
	}

	/**
	 * @param array<int, int> $tomos Tomos que cubre el bloque.
	 * @return array<string, mixed>
	 */
	private function bloque( string $serie, int $volume, array $tomos = array() ): array {
		$tomos = VolumePhrase::normalize( array() === $tomos ? array( $volume ) : $tomos );

		return array(
			'term_id'        => 42,
			'series_name'    => $serie,
			'volume'         => $volume,
			'product_id'     => 5000 + $volume,
			'title'          => $serie . ' ' . $volume,
			'url'            => 'https://akibara.test/p/' . ( 5000 + $volume ),
			'price_html'     => '$8.990',
			'image_url'      => '',
			'cutoff'         => '',
			'availability'   => 'Disponible',
			'unfollow_url'   => 'https://akibara.test/baja/42',
			'volumes'        => $tomos,
			'volumes_count'  => count( $tomos ),
			'volumes_phrase' => VolumePhrase::of( $tomos ),
		);
	}

	/** @testdox El bloque de tanda dice cuántos tomos son y cuáles. */
	public function test_el_bloque_de_tanda_dice_cuantos_y_cuales(): void {
		$html = $this->render( array( $this->bloque( 'Kingdom', 74, array( 71, 72, 73, 74 ) ) ) );

		self::assertStringContainsString( 'Kingdom — tomos 71 al 74', $html );
		self::assertStringContainsString( 'Tomo 74:', $html );
		self::assertStringContainsString( 'Ver el tomo 74', $html );
	}

	/**
	 * @testdox El precio nunca queda suelto encima de una frase de varios tomos.
	 *
	 * EL RIESGO GRANDE de este cambio: que «$8.990» se lea como el precio de los
	 * cuatro. `build_group()` carga UN producto — el precio, el estado y el corte
	 * son del tomo que se muestra y de ninguno más.
	 */
	public function test_el_precio_nunca_queda_suelto_encima_de_una_frase_de_varios_tomos(): void {
		$html = $this->render( array( $this->bloque( 'Kingdom', 74, array( 71, 72, 73, 74 ) ) ) );

		$prefijo = strpos( $html, 'Tomo 74:' );
		$precio  = strpos( $html, '$8.990' );

		self::assertNotFalse( $prefijo );
		self::assertNotFalse( $precio );
		self::assertLessThan( $precio, $prefijo, 'El precio va DESPUÉS de decir de qué tomo es.' );
	}

	/** @testdox Un solo tomo deja el bloque como estaba: «tomo 107» y «Ver el tomo». */
	public function test_el_bloque_de_un_solo_tomo_no_cambia(): void {
		$html = $this->render( array( $this->bloque( 'One Piece', 107 ) ) );

		self::assertStringContainsString( 'One Piece — tomo 107', $html );
		self::assertStringContainsString( '>Ver el tomo</a>', $html );
		self::assertStringNotContainsString( 'Tomo 107:', $html, 'Con un tomo el prefijo sobra.' );
	}

	/**
	 * @testdox La entradilla no se contradice con el bloque de abajo.
	 *
	 * `count( $groups )` cuenta SERIES. Con una serie y cuatro tomos, «Salió un
	 * tomo nuevo» arriba y «tomos 71 al 74» abajo es un correo que se desmiente a
	 * sí mismo, y eso es peor que el original.
	 */
	public function test_la_intro_no_se_contradice_con_el_bloque(): void {
		foreach ( array( 'html', 'plain' ) as $variante ) {
			$salida = $this->render( array( $this->bloque( 'Kingdom', 74, array( 71, 72, 73, 74 ) ) ), $variante );

			self::assertStringNotContainsString( 'Salió un tomo nuevo', $salida, "Se contradice en la parte {$variante}." );
			self::assertStringContainsString( 'Salieron 4 tomos nuevos', $salida );
		}
	}

	/** @testdox La entradilla tiene tres ramas: un tomo, varios de una serie, y varias series. */
	public function test_la_intro_tiene_las_tres_ramas(): void {
		self::assertStringContainsString(
			'Salió un tomo nuevo de una serie que estás siguiendo.',
			$this->render( array( $this->bloque( 'One Piece', 107 ) ) )
		);
		self::assertStringContainsString(
			'Salieron 4 tomos nuevos de una serie que estás siguiendo.',
			$this->render( array( $this->bloque( 'Kingdom', 74, array( 71, 72, 73, 74 ) ) ) )
		);
		self::assertStringContainsString(
			'Salieron tomos nuevos de series que estás siguiendo.',
			$this->render(
				array(
					$this->bloque( 'One Piece', 107 ),
					$this->bloque( 'Berserk', 42 ),
				)
			)
		);
	}

	/**
	 * @testdox La parte plana dice los mismos tomos que la HTML.
	 *
	 * Si sólo se toca el HTML, la mitad del correo sigue leyendo el texto viejo.
	 */
	public function test_la_plana_dice_los_mismos_tomos_que_la_html(): void {
		$grupos = array( $this->bloque( 'Kingdom', 74, array( 71, 72, 73, 74 ) ) );

		$plana = $this->render( $grupos, 'plain' );

		self::assertStringContainsString( 'Kingdom — tomos 71 al 74', $plana );
		self::assertStringContainsString( 'Tomo 74: Disponible', $plana );
	}

	/**
	 * @testdox Un bloque SIN las claves nuevas no rompe la plantilla.
	 *
	 * Guarda los `??`: `template_args()` reenvía cualquier array que le pasen, y
	 * un warning dentro de `wc_get_template_html()` ensucia el cuerpo del correo
	 * ya enviado.
	 */
	public function test_un_grupo_sin_las_claves_nuevas_no_rompe_la_plantilla(): void {
		$bloque = $this->bloque( 'One Piece', 107 );
		unset( $bloque['volumes'], $bloque['volumes_count'], $bloque['volumes_phrase'] );

		$html = $this->render( array( $bloque ) );

		self::assertStringContainsString( 'One Piece — tomo 107', $html );
		self::assertStringContainsString( 'Salió un tomo nuevo', $html );
	}

	/**
	 * @testdox Ninguna superficie de este correo dice «siguiente», ni con uno ni con cuatro.
	 *
	 * Arreglar un texto exige barrer TODAS sus superficies. El encabezado es la
	 * que se olvida, y es la primera que se lee.
	 */
	public function test_ninguna_superficie_dice_siguiente(): void {
		foreach ( array( 'html', 'plain' ) as $variante ) {
			foreach (
				array(
					'un tomo'      => array( $this->bloque( 'One Piece', 107 ) ),
					'cuatro tomos' => array( $this->bloque( 'Kingdom', 74, array( 71, 72, 73, 74 ) ) ),
				) as $caso => $grupos
			) {
				self::assertStringNotContainsString(
					'siguiente',
					mb_strtolower( $this->render( $grupos, $variante ) ),
					"Dice «siguiente» en {$variante} con {$caso}."
				);
			}
		}
	}

	/**
	 * @testdox Sin emojis ni exclamaciones en las dos plantillas de este correo.
	 *
	 * Acotado a propósito a este módulo: `ReminderEmail` tiene una exclamación
	 * deliberada y sellada por su propia prueba.
	 */
	public function test_sin_emojis_ni_exclamaciones(): void {
		foreach ( array( 'html', 'plain' ) as $variante ) {
			$salida = $this->render(
				array( $this->bloque( 'Kingdom', 74, array( 71, 72, 73, 74 ) ) ),
				$variante
			);

			self::assertStringNotContainsString( '!', $salida, "Exclamación en la parte {$variante}." );
			self::assertDoesNotMatchRegularExpression( '/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $salida );
		}
	}

	/**
	 * @testdox El bloque que cierra un hueco muestra el copy de hueco y el plazo de llegada en HTML y plain.
	 */
	public function test_bloque_de_hueco_y_plazo_en_html_y_plain(): void {
		$bloque                   = $this->bloque( 'Nana', 5 );
		$bloque['is_gap']         = true;
		$bloque['timeframe_copy'] = 'En bodega: si pides hoy, despachamos en 24 a 48 h hábiles.';

		foreach ( array( 'html', 'plain' ) as $variante ) {
			$salida = $this->render( array( $bloque ), $variante );

			self::assertStringContainsString( 'Cierra el hueco de tu colección', $salida );
			self::assertStringContainsString( 'Disponible · En bodega: si pides hoy, despachamos en 24 a 48 h hábiles.', $salida );
			self::assertStringNotContainsString( '!', $salida );
		}
	}
}
