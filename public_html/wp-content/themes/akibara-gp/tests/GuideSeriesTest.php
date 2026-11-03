<?php
/**
 * Contrato de la serie de una guía del blog (UC-12 = UM-17, inc/guide-series.php).
 *
 * Lo que se fija aquí es la parte del TEMA: que los datos de la serie se piden a
 * akibara-business por el filtro `akb_guide_series_card` (qué serie, portada,
 * conteos y etiqueta de caché son de business desde D6; sus pruebas están en
 * tests/Modules/RelatedGuides/GuideSeriesCardTest.php del plugin), que la
 * tarjeta se escapa y lleva UN solo enlace, que pinta los conteos tal como
 * llegan, dónde se intercala en el contenido (sólo en su primer nivel) y que el
 * texto del dueño nunca pasa por sprintf.
 *
 * @package akibara-gp\Tests
 */

declare( strict_types = 1 );

namespace Akibara\GP\Tests;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class GuideSeriesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'add_action' )->justReturn( null );
		// esc_attr/esc_url/esc_js ya existen en wp-stubs.php; esc_html escapa de verdad
		// para que la prueba vea lo mismo que el navegador.
		Functions\when( 'esc_html' )->alias( static fn( $t ): string => htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ) );

		require_once dirname( __DIR__ ) . '/inc/guide-series.php';
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox Los datos de la serie se piden a akibara-business por su filtro, con el ID de la entrada.
	 */
	public function test_los_datos_llegan_por_el_filtro_de_business(): void {
		$datos = array(
			'name'    => 'The Climber',
			'url'     => 'https://akibara.test/serie/the-climber/',
			'cover'   => '',
			'estados' => array(),
		);
		Filters\expectApplied( 'akb_guide_series_card' )->once()->with( null, 900001 )->andReturn( $datos );

		self::assertSame( $datos, akibara_gp_guide_series_data( 900001 ) );
	}

	/**
	 * Sin business, sin serie o con una respuesta rara, la entrada queda como
	 * siempre: sin tarjeta y con el cierre a la tienda.
	 *
	 * @testdox Sin datos válidos (sin plugin, sin serie, sin nombre o URL) no hay tarjeta.
	 */
	public function test_sin_datos_validos_no_hay_serie(): void {
		self::assertNull( akibara_gp_guide_series_data( 900002 ), 'Sin nadie en el filtro: null.' );
		self::assertNull( akibara_gp_guide_series_data( 0 ) );

		Filters\expectApplied( 'akb_guide_series_card' )->andReturn( array( 'name' => '', 'url' => 'x' ), 'no-es-array', array( 'name' => 'Nana' ) );
		self::assertNull( akibara_gp_guide_series_data( 900003 ) );
		self::assertNull( akibara_gp_guide_series_data( 900004 ) );
		self::assertNull( akibara_gp_guide_series_data( 900005 ) );
	}

	/**
	 * @testdox La tarjeta escapa el nombre, pone el nombre de la serie en el botón sin sprintf y lleva un solo enlace.
	 */
	public function test_tarjeta_escapada_con_un_solo_enlace(): void {
		$html = akibara_gp_render_guide_series_card(
			array(
				'name'    => 'Rurouni <Kenshin> 100%',
				'url'     => 'https://akibara.test/serie/rurouni-kenshin/',
				'cover'   => 'https://akibara.test/wp-content/uploads/portadas/rk-1.avif',
				'estados' => array(
					array( 'label' => 'Disponible', 'texto' => '10 tomos' ),
					array( 'label' => 'A pedido', 'texto' => '1 tomo' ),
					array( 'label' => 'Agotado', 'texto' => '<b>3</b> tomos' ),
				),
			),
			'La serie en Akibara',
			'Ver los tomos de {serie} al 100%'
		);

		// El nombre de la región sale del rótulo del dueño y del nombre de la serie
		// escritos dentro, no de un prefijo clavado en el tema (revisión T7-1, D3).
		self::assertStringContainsString( '<aside class="akb-guide-series" aria-labelledby="akb-guide-series-kicker akb-guide-series-name">', $html );
		self::assertStringContainsString( '<p class="akb-guide-series__kicker" id="akb-guide-series-kicker">La serie en Akibara</p>', $html );
		self::assertStringContainsString( '<p class="akb-guide-series__name" id="akb-guide-series-name">Rurouni &lt;Kenshin&gt; 100%</p>', $html );
		self::assertStringNotContainsString( 'aria-label=', $html, 'sin literales propios del tema en el nombre accesible' );
		self::assertStringNotContainsString( 'Serie: ', $html );
		self::assertStringContainsString( '>Ver los tomos de Rurouni &lt;Kenshin&gt; 100% al 100%</a>', $html );
		self::assertSame( 1, substr_count( $html, '<a ' ), 'la portada no enlaza aparte: dos paradas de Tab al mismo destino' );
		self::assertStringContainsString( 'href="https://akibara.test/serie/rurouni-kenshin/"', $html );
		self::assertStringContainsString( 'alt="" width="300" height="450" loading="lazy"', $html, 'la portada declara su alto (sin salto al cargar) y es decorativa: el nombre ya va escrito' );
		self::assertStringContainsString( '<span class="akb-guide-series__state">Disponible</span> 10 tomos', $html );
		self::assertStringContainsString( '<span class="akb-guide-series__state">A pedido</span> 1 tomo<', $html );
		self::assertStringContainsString( '<span class="akb-guide-series__state">Agotado</span> &lt;b&gt;3&lt;/b&gt; tomos', $html, 'lo que llega se escapa: se imprime, no se interpreta' );
	}

	/**
	 * @testdox Sin portada ni conteos la tarjeta sigue saliendo con rótulo, nombre y botón; sin nombre o URL no sale.
	 */
	public function test_tarjeta_degrada_sin_datos_opcionales(): void {
		$html = akibara_gp_render_guide_series_card(
			array(
				'name'    => 'Nana',
				'url'     => 'https://akibara.test/serie/nana/',
				'cover'   => '',
				'estados' => array(),
			),
			'La serie en Akibara',
			'Ver los tomos'
		);

		self::assertStringNotContainsString( '<img', $html );
		self::assertStringNotContainsString( 'akb-guide-series__stock', $html );
		self::assertStringContainsString( '>Ver los tomos</a>', $html );
		// El rótulo no es opcional (revisión T7-2, D4): el aria-labelledby apunta
		// siempre a los dos id, y los dos existen.
		self::assertStringContainsString( 'aria-labelledby="akb-guide-series-kicker akb-guide-series-name"', $html );
		self::assertStringContainsString( 'id="akb-guide-series-kicker">La serie en Akibara</p>', $html );
		self::assertStringContainsString( 'id="akb-guide-series-name">Nana</p>', $html );

		self::assertSame( '', akibara_gp_render_guide_series_card( array( 'name' => '', 'url' => 'x', 'cover' => '', 'estados' => array() ), 'k', 'b' ) );
		self::assertSame( '', akibara_gp_render_guide_series_card( array( 'name' => 'Nana', 'url' => '', 'cover' => '', 'estados' => array() ), 'k', 'b' ) );
	}

	/**
	 * @testdox La tarjeta va justo antes del primer H2 —tras la respuesta corta con que abre la guía—, no dentro de él.
	 */
	public function test_se_intercala_antes_del_primer_h2(): void {
		$content = '<p>The Climber está completo en 17 tomos.</p><p>Otro.</p><h2 class="wp-block-heading">Cuántos tomos tiene</h2><p>…</p><h2>Otra</h2>';

		$out = akibara_gp_insert_guide_series_card( $content, '<aside class="akb-guide-series"></aside>' );

		self::assertSame( 1, substr_count( $out, 'akb-guide-series' ) );
		self::assertLessThan( strpos( $out, '<h2 class="wp-block-heading">' ), strpos( $out, '<aside' ) );
		self::assertGreaterThan( strpos( $out, '<p>Otro.</p>' ), strpos( $out, '<aside' ) );

		// El índice de Rank Math trae su propio H2 dentro de un <div> (revisión
		// T7-2, D2): la tarjeta va tras el índice, antes del primer H2 de la guía,
		// nunca dentro del índice.
		$toc  = '<p>Intro</p><div class="wp-block-rank-math-toc-block" id="rank-math-toc"><h2>Tabla de contenidos</h2><nav><ul><li><a href="#a">Cuántos tomos</a></li></ul></nav></div><h2 id="a">Cuántos tomos</h2><p>…</p>';
		$card = '<aside class="akb-guide-series"></aside>';
		self::assertSame(
			'<p>Intro</p><div class="wp-block-rank-math-toc-block" id="rank-math-toc"><h2>Tabla de contenidos</h2><nav><ul><li><a href="#a">Cuántos tomos</a></li></ul></nav></div>' . $card . "\n" . '<h2 id="a">Cuántos tomos</h2><p>…</p>',
			akibara_gp_insert_guide_series_card( $toc, $card )
		);
	}

	/**
	 * @testdox Sólo cuentan el H2 y el párrafo del primer nivel: nunca dentro de un grupo, un details, una cita o una tabla.
	 */
	public function test_solo_el_primer_nivel_del_contenido(): void {
		$card = '<aside class="akb-guide-series"></aside>';

		// Un <details> de preguntas con su H2 antes de la primera sección: dentro
		// quedaría oculta mientras esté cerrado.
		$details = '<p>Intro.</p><details class="wp-block-details"><summary>¿Está completo?</summary><h2>Respuesta</h2><p>Sí.</p></details><h2>Sección</h2>';
		$out     = akibara_gp_insert_guide_series_card( $details, $card );
		self::assertGreaterThan( strpos( $out, '</details>' ), strpos( $out, $card ) );
		self::assertSame( strpos( $out, '<h2>Sección</h2>' ), strpos( $out, $card ) + strlen( $card ) + 1 );

		// Grupos anidados y mayúsculas: se cuentan los niveles, no la primera coincidencia.
		$grupo = '<DIV class="wp-block-group"><div class="inner"><H2>Dentro</H2></div><p>x</p></DIV><H2 class="a">Fuera</H2>';
		$out   = akibara_gp_insert_guide_series_card( $grupo, $card );
		self::assertStringEndsWith( '</DIV>' . $card . "\n" . '<H2 class="a">Fuera</H2>', $out );

		// Sin H2 de primer nivel: tras el primer párrafo de primer nivel, no tras
		// el de una cita o una tabla.
		$sin_h2 = '<blockquote class="wp-block-quote"><p>Cita.</p></blockquote><table><tr><td><p>celda</p></td></tr></table><p>Uno.</p><p>Dos.</p>';
		$out    = akibara_gp_insert_guide_series_card( $sin_h2, $card );
		self::assertSame( strpos( $out, '<p>Uno.</p>' ) + strlen( '<p>Uno.</p>' ) + 1, strpos( $out, $card ) );

		// Todo el contenido dentro de un solo grupo: al principio, fuera de él.
		$todo = '<div class="wp-block-group"><p>Intro.</p><h2>Sección</h2></div>';
		self::assertSame( $card . "\n" . $todo, akibara_gp_insert_guide_series_card( $todo, $card ) );

		// Comentarios HTML y el interior de <script> no abren ni cierran niveles.
		$ruido = '<!-- <div> --><script>var s = "<div><h2>";</script><p>a</p><h2>Real</h2>';
		$out   = akibara_gp_insert_guide_series_card( $ruido, $card );
		self::assertSame( strpos( $out, '<h2>Real</h2>' ), strpos( $out, $card ) + strlen( $card ) + 1 );

		// Un cierre de más no deja el nivel en negativo (ni convierte un H2 anidado en «de primer nivel»).
		$roto = '</div><section><h2>Anidado</h2></section><h2>Bueno</h2>';
		$out  = akibara_gp_insert_guide_series_card( $roto, $card );
		self::assertSame( strpos( $out, '<h2>Bueno</h2>' ), strpos( $out, $card ) + strlen( $card ) + 1 );
	}

	/**
	 * @testdox Sin H2 va tras el primer párrafo; sin párrafos, al principio; sin tarjeta, el contenido no cambia.
	 */
	public function test_otros_puntos_de_insercion(): void {
		$card = '<aside class="akb-guide-series"></aside>';

		$sin_h2 = akibara_gp_insert_guide_series_card( '<p>Uno.</p><p>Dos.</p>', $card );
		self::assertStringStartsWith( '<p>Uno.</p>', $sin_h2 );
		self::assertLessThan( strpos( $sin_h2, '<p>Dos.</p>' ), strpos( $sin_h2, '<aside' ) );

		self::assertStringStartsWith( $card, akibara_gp_insert_guide_series_card( '<ul><li>x</li></ul>', $card ) );

		// `<h2x>` no es un H2 y `<header>` tampoco: el patrón exige `<h2` + espacio o `>`.
		$raro = '<p>a</p><h2x>no</h2x><header>h</header>';
		self::assertStringStartsWith( '<p>a</p>' . "\n" . $card, akibara_gp_insert_guide_series_card( $raro, $card ) );

		self::assertSame( '<p>x</p>', akibara_gp_insert_guide_series_card( '<p>x</p>', '' ) );
	}
}
