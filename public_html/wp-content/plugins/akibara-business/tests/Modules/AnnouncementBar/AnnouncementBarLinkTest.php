<?php
/**
 * Un enlace por mensaje en la barra de anuncios: «Texto | /envios/» (UM-5).
 *
 * La cinta de la barra tiene el estilo de los botones de compra y el cliente la
 * tocaba esperando que llevara a alguna parte (Clarity, 2026-09-15: seis toques
 * seguidos en «Retiro sin costo en Metro»). Enlazarla exigía escribir HTML. Estas
 * pruebas fijan que el dueño puede hacerlo sin HTML, que lo ya guardado no
 * cambia de significado y que un mensaje sin enlace no finge serlo.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\AnnouncementBar;

use Akibara\Business\Modules\AnnouncementBar\AnnouncementBar;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class AnnouncementBarLinkTest extends TestCase {

	/** @var array<string, mixed> */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->options = array();
		Functions\when( 'get_option' )->alias( fn( string $k, $default = false ) => $this->options[ $k ] ?? $default );
		Functions\when( 'home_url' )->alias( static fn( string $path = '' ): string => 'https://akibara.cl' . $path );
		Functions\when( 'wp_parse_url' )->alias( static fn( string $url, int $component = -1 ) => parse_url( $url, $component ) );
		Functions\when( 'esc_html' )->alias( static fn( $v ): string => htmlspecialchars( (string) $v, ENT_QUOTES ) );
		Functions\when( 'esc_attr' )->alias( static fn( $v ): string => htmlspecialchars( (string) $v, ENT_QUOTES ) );
		Functions\when( 'esc_url' )->alias(
			static fn( $v ): string => preg_match( '~^(https?://|/)~', (string) $v ) ? htmlspecialchars( (string) $v, ENT_QUOTES ) : ''
		);
		Functions\when( 'wp_kses' )->alias(
			static function ( $html, array $allowed ): string {
				$tags = '';
				foreach ( array_keys( $allowed ) as $tag ) {
					$tags .= '<' . $tag . '>';
				}
				return strip_tags( (string) $html, $tags );
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox «Texto | /ruta/» separa el texto del enlace y completa la ruta con el dominio.
	 */
	public function test_splits_a_store_path(): void {
		self::assertSame(
			array( 'text' => 'Retiro sin costo en Metro San Miguel', 'url' => 'https://akibara.cl/envios/' ),
			AnnouncementBar::split_link( 'Retiro sin costo en Metro San Miguel | /envios/' )
		);
		self::assertSame(
			array( 'text' => 'Ivrea -10%', 'url' => 'https://akibara.cl/marca/ivrea-argentina/?_disponibilidad%5B%5D=instock' ),
			AnnouncementBar::split_link( 'Ivrea -10% |/marca/ivrea-argentina/?_disponibilidad%5B%5D=instock  ' ),
			'Los espacios alrededor de la barra no importan.'
		);
		self::assertSame(
			array( 'text' => 'Síguenos', 'url' => 'https://instagram.com/akibara' ),
			AnnouncementBar::split_link( 'Síguenos | https://instagram.com/akibara' )
		);
	}

	/**
	 * @testdox Lo que no es una dirección tras la última barra sigue siendo texto: nada de lo guardado cambia.
	 */
	public function test_keeps_plain_text_untouched(): void {
		foreach ( array(
			'Envío GRATIS a todo Chile sobre $60.000',
			'Manga | Cómic | Novela',
			'Retiro | //evil.example/x',
			'Retiro | javascript:alert(1)',
			'| /envios/',
			'<a href="https://akibara.cl/x/">Este finde: 10% en Ivrea</a>',
		) as $linea ) {
			self::assertSame( array( 'text' => $linea, 'url' => '' ), AnnouncementBar::split_link( $linea ), $linea );
		}
	}

	/**
	 * @testdox Pintada, la cinta con enlace es un <a> que ocupa la cinta; la de texto no es tocable.
	 */
	public function test_renders_link_tape_and_plain_tape(): void {
		$this->options['akibara_announcement_messages'] = "Retiro sin costo en Metro | /envios/\nEnvío GRATIS a todo Chile\nVer <strong>Ivrea</strong> con <a href=\"/x/\">stock</a> | /marca/ivrea-argentina/";

		ob_start();
		( new AnnouncementBar() )->render_bar();
		$html = (string) ob_get_clean();

		self::assertStringContainsString(
			'<span class="akb-ann-msg akb-on akb-ann-msg--link"><a class="akb-ann-msg-inner" href="https://akibara.cl/envios/">Retiro sin costo en Metro</a></span>',
			$html
		);
		self::assertStringContainsString(
			'<span class="akb-ann-msg" aria-hidden="true" inert><span class="akb-ann-msg-inner">Envío GRATIS a todo Chile</span></span>',
			$html,
			'Sin enlace: el marcado de siempre.'
		);
		self::assertStringContainsString(
			'<span class="akb-ann-msg akb-ann-msg--link" aria-hidden="true" inert><a class="akb-ann-msg-inner" href="https://akibara.cl/marca/ivrea-argentina/">Ver <strong>Ivrea</strong> con stock</a></span>',
			$html,
			'Dentro de la cinta-enlace no puede ir otro <a>.'
		);
		self::assertStringNotContainsString( ' | ', $html, 'La dirección no se ve.' );
	}

	/**
	 * @testdox La hoja y el CSS crítico dan a la cinta-enlace el mismo relleno que a la cinta normal.
	 */
	public function test_link_tape_keeps_the_tape_geometry(): void {
		$css = (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/css/announcement-bar.css' );

		self::assertMatchesRegularExpression( '/\.akb-ann-msg--link \{\s*padding: 0;\s*\}/', $css );
		self::assertMatchesRegularExpression( '/\.akb-ann-msg--link \.akb-ann-msg-inner \{[^}]*padding: 6px 20px;[^}]*cursor: pointer;/', $css, 'Escritorio: el relleno de .akb-ann-msg (6px 20px).' );
		// Móvil (QA2-PANEL-6): el <a> ocupa el alto de la barra (12 + 3 + 15,6 +
		// 15 = 45,6 px con una línea; la barra, de 44, lo recorta) y el margen
		// negativo devuelve la cinta al relleno de .akb-ann-msg. Lo que crece es
		// un BORDE transparente y no el relleno (REV-QFB-1): `overflow: hidden`
		// recorta en la caja de relleno, y con 15px la tercera línea de un
		// mensaje largo asomaba bajo la segunda. Abajo no hay relleno y el
		// borde es de 15 (REV2-QFB-1, REV-FN1-1): con 4px todavía se veía el
		// tope de la tercera línea, y con 1px sus tildes. Con el teclado, la caja
		// de siempre, y lo que le falta abajo también es borde (REV-FN-1): con
		// `padding-bottom: 4px` la tercera línea volvía a asomar en cuanto el
		// enlace tenía el foco.
		self::assertMatchesRegularExpression( '/@media \(max-width: 768px\)[\s\S]*\.akb-ann-msg--link \.akb-ann-msg-inner \{\s*padding: 3px 8px 0;\s*border-block: 12px solid transparent;\s*border-block-end-width: 15px;\s*margin: -12px 0;/', $css, 'Móvil: el enlace se toca en toda la barra y el recorte queda en el fondo de las letras.' );
		self::assertMatchesRegularExpression( '/@media \(max-width: 768px\)[\s\S]*\.akb-ann-msg--link \.akb-ann-msg-inner:focus-visible \{\s*border-block-width: 0 3px;\s*margin: 0;\s*\}/', $css, 'Móvil con teclado: el anillo rodea la cinta, no la barra, y los 3px de abajo quedan fuera del recorte.' );

		// La línea mide 1.3 (REV-FN1-1): con 1.2 las tildes de la tercera línea
		// empiezan sobre el fondo de la segunda, donde va su subrayado, y
		// ningún recorte las separa.
		self::assertMatchesRegularExpression( '/@media \(max-width: 768px\)[\s\S]*#akb-ann-bar \.akb-ann-msg-inner \{[^}]*-webkit-line-clamp: 2;[^}]*line-height: 1\.3;\s*\}/', $css, 'Móvil: dos líneas de 1.3.' );

		// Cada regla del enlace en el teléfono, en cualquier estado: sin relleno
		// abajo, porque `overflow: hidden` recorta ahí.
		$sin_comentarios = (string) preg_replace( '#/\*.*?\*/#s', '', $css );
		$movil           = substr( $sin_comentarios, (int) strpos( $sin_comentarios, '@media (max-width: 768px)' ) );
		preg_match_all( '/([^{}]*\.akb-ann-msg--link \.akb-ann-msg-inner[^{}]*)\{([^}]*)\}/', $movil, $reglas, PREG_SET_ORDER );
		self::assertCount( 4, $reglas, 'Móvil: la regla del enlace, la del foco, la del subrayado resaltado y la de colores forzados.' );
		foreach ( $reglas as $regla ) {
			$abajo = array();
			preg_match_all( '/(?<![\w-])(padding|padding-bottom|padding-block-end|padding-block): ([^;]+);/', $regla[2], $rellenos, PREG_SET_ORDER );
			foreach ( $rellenos as $relleno ) {
				// Dónde va el valor de abajo en cada forma de escribirlo.
				$valores  = (array) preg_split( '/\s+/', trim( $relleno[2] ) );
				$posicion = array(
					'padding'       => 2,
					'padding-block' => 1,
				)[ $relleno[1] ] ?? 0;
				$abajo[]  = $valores[ $posicion ] ?? $valores[0];
			}
			foreach ( $abajo as $valor ) {
				self::assertSame( '0', $valor, trim( $regla[1] ) . ': ningún estado del enlace lleva relleno abajo, que es donde recorta el overflow.' );
			}
		}

		// El subrayado resaltado (toque y foco) es de 2px y el de reposo de 1px:
		// en el teléfono el grueso sube lo que engorda, así termina en la misma
		// fila y el recorte no se come la segunda (REV-FN-2, WebKit).
		preg_match( '/\.akb-ann-msg--link \.akb-ann-msg-inner \{[^}]*text-decoration-thickness: (\d+)px;[^}]*text-underline-offset: (\d+)px;/', $sin_comentarios, $reposo );
		preg_match( '/\.akb-ann-msg--link \.akb-ann-msg-inner:hover,\s*\.akb-ann-msg--link \.akb-ann-msg-inner:focus-visible \{[^}]*text-decoration-thickness: (\d+)px;/', $sin_comentarios, $resaltado );
		preg_match( '/\.akb-ann-msg--link \.akb-ann-msg-inner:hover,\s*\.akb-ann-msg--link \.akb-ann-msg-inner:focus-visible \{\s*text-underline-offset: (\d+)px;\s*\}/', $movil, $sube );
		self::assertCount( 3, $reposo, 'El subrayado de reposo declara grosor y distancia.' );
		self::assertCount( 2, $resaltado, 'El resaltado declara su grosor.' );
		self::assertCount( 2, $sube, 'Móvil: el resaltado declara su distancia.' );
		self::assertSame( (int) $reposo[1] + (int) $reposo[2], (int) $resaltado[1] + (int) $sube[1], 'Móvil: el subrayado resaltado termina donde el de reposo.' );

		// Con colores forzados el navegador pinta los bordes transparentes: el
		// que agranda el enlace se vuelve del color del fondo. Con `#akb-ann-bar`
		// delante, porque el CSS crítico repite `border-block: … transparent`
		// después de esta hoja y a igual especificidad ganaría.
		self::assertMatchesRegularExpression( '/@media \(max-width: 768px\) and \(forced-colors: active\) \{\s*#akb-ann-bar \.akb-ann-msg--link \.akb-ann-msg-inner \{\s*border-color: Canvas;\s*\}\s*\}/', $sin_comentarios, 'Colores forzados: el borde que agranda el enlace no se pinta.' );

		self::assertMatchesRegularExpression( '/\.akb-ann-msg:not\(\.akb-ann-msg--link\) \.akb-ann-msg-inner \{\s*cursor: default;/', $css );
		self::assertStringContainsString( '.akb-ann-msg--link .akb-ann-msg-inner:focus-visible', $css, 'El enlace lleva el anillo de foco de la casa.' );
	}

	/**
	 * REV-FN2-1: la línea de 1.3 (REV-FN1-1) hacía crecer la cinta de dos
	 * líneas de 36,8 a 39,2 px dentro de una barra de 44 que recorta, y quedaban
	 * 2,4 px libres abajo para una sombra roja y un anillo de foco de 4: la
	 * barra se comía un tercio de cada uno. Con 3px de relleno quedan 3,4. La
	 * cinta-enlace mide lo mismo que la normal en reposo y con el foco, con
	 * bordes de píxeles enteros (el navegador los redondea a píxeles del
	 * dispositivo: uno de 2,8 medía 2 con el foco y las letras subían), y el
	 * borde que agranda el toque cubre la barra entera aun con una sola línea.
	 *
	 * @testdox En el teléfono la cinta de dos líneas deja libres 3,4 px en la barra, con enlace o sin él, y el enlace se toca en toda la barra.
	 */
	public function test_la_cinta_de_dos_lineas_deja_sitio_a_la_sombra_y_al_anillo(): void {
		$css    = (string) preg_replace( '#/\*.*?\*/#s', '', (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/css/announcement-bar.css' ) );
		$movil  = substr( $css, (int) strpos( $css, '@media (max-width: 768px) {' ) );
		$regla  = static function ( string $selector ) use ( $movil ): string {
			self::assertSame( 1, preg_match( '/(?:^|\})\s*' . preg_quote( $selector, '/' ) . '\s*\{([^}]*)\}/', $movil, $m ), 'Móvil: falta la regla ' . $selector );
			return $m[1];
		};
		$px     = static function ( string $cuerpo, string $propiedad, int $posicion = 0 ): float {
			self::assertSame( 1, preg_match( '/(?<![\w-])' . preg_quote( $propiedad, '/' ) . ':\s*([^;]+);/', $cuerpo, $m ), 'Falta ' . $propiedad );
			$valores = (array) preg_split( '/\s+/', trim( $m[1] ) );
			return (float) ( $valores[ $posicion ] ?? $valores[0] );
		};

		$cinta   = $regla( '.akb-ann-msg' );
		$linea   = $px( $cinta, 'font-size' ) * $px( $regla( '#akb-ann-bar .akb-ann-msg-inner' ), 'line-height' );
		$relleno = $px( $cinta, 'padding' );
		$libre   = static fn( int $lineas ): float => ( 44 - ( $lineas * $linea + 2 * $relleno ) ) / 2;
		self::assertGreaterThanOrEqual( 3.4, round( $libre( 2 ), 3 ), 'Dos líneas: libres bajo la cinta para la sombra y el anillo de 4 px (2,4 con 4px de relleno).' );

		$enlace = $regla( '.akb-ann-msg--link .akb-ann-msg-inner' );
		$foco   = $regla( '.akb-ann-msg--link .akb-ann-msg-inner:focus-visible' );
		$bordes = array( $px( $enlace, 'border-block' ), $px( $enlace, 'border-block-end-width' ), $px( $foco, 'border-block-width', 1 ) );
		foreach ( $bordes as $borde ) {
			self::assertSame( floor( $borde ), $borde, 'Los bordes del enlace van en píxeles enteros: el navegador redondea los demás.' );
		}
		self::assertSame( $relleno, $bordes[0] + $px( $enlace, 'padding' ) + $px( $enlace, 'margin' ), 'Enlace: borde + relleno − margen, arriba, es el relleno de la cinta normal.' );
		self::assertSame( $relleno, $bordes[1] + $px( $enlace, 'margin' ), 'Enlace: borde − margen, abajo, es el relleno de la cinta normal.' );
		self::assertSame( $relleno, $px( $enlace, 'padding' ), 'Con el foco (sin borde arriba) el relleno de arriba es el de la cinta normal.' );
		self::assertSame( $relleno, $bordes[2], 'Con el foco, el borde de abajo es el relleno de la cinta normal.' );

		// Con UNA línea la cinta deja libre más: el enlace sale de ella lo que
		// mide su margen negativo (el mismo arriba y abajo), y tiene que cubrirlo
		// o el borde de la barra no se toca.
		self::assertGreaterThanOrEqual( $libre( 1 ), -$px( $enlace, 'margin' ), 'Una línea: el enlace llega a los bordes de la barra.' );
	}

	/**
	 * QA integral 2026-09-24 (QA-PANEL-1): las formas en que el dueño escribe de
	 * verdad una dirección se pintaban tal cual en la cinta de todo el sitio,
	 * «Envío gratis | www.akibara.cl/envios/», con la barra y todo.
	 *
	 * @testdox Una dirección con el esquema en mayúsculas o sin esquema también es un enlace; la de esta tienda, una página de la tienda.
	 */
	public function test_recognizes_the_addresses_the_owner_writes(): void {
		foreach ( array(
			'Envío gratis | www.akibara.cl/envios/'      => 'https://akibara.cl/envios/',
			'Envío gratis | akibara.cl/envios/'          => 'https://akibara.cl/envios/',
			'Envío gratis | WWW.Akibara.CL/envios/'      => 'https://akibara.cl/envios/',
			'Envío gratis | akibara.cl'                  => 'https://akibara.cl/',
			'Ivrea | HTTPS://akibara.cl/marca/ivrea/'    => 'https://akibara.cl/marca/ivrea/',
			'Síguenos | instagram.com/akibara'           => 'https://instagram.com/akibara',
			'Escríbenos | wa.me/56944242844'             => 'https://wa.me/56944242844',
			'Síguenos | Http://instagram.com/akibara'    => 'http://instagram.com/akibara',
		) as $linea => $url ) {
			self::assertSame( $url, AnnouncementBar::split_link( $linea )['url'], $linea );
			self::assertSame( trim( explode( '|', $linea )[0] ), AnnouncementBar::split_link( $linea )['text'], $linea );
		}

		self::assertSame(
			array( 'text' => 'Búsqueda', 'url' => 'https://akibara.cl/?s=a|b' ),
			AnnouncementBar::split_link( 'Búsqueda | https://akibara.cl/?s=a|b' ),
			'Una dirección con su propio «|» sigue separándose por la barra del texto, como antes.'
		);
	}

	/**
	 * @testdox Al guardar, avisa de cada línea que termina en algo que parece un enlace y no lo es; lo demás no avisa.
	 */
	public function test_saving_warns_about_unrecognized_addresses(): void {
		$this->options['akibara_announcement_messages'] = implode(
			"\n",
			array(
				'Envío gratis sobre $60.000',
				'Retiro sin costo | /retiro sin costo/',
				'Manga | Cómic | Novela',
				'Envío | $60.000',
				'Horario | 10:00',
				'Síguenos | Instagram: @akibara',
				'Ver más | envios',
				'Texto con intento | javascript:alert(1)',
				'Termina en barra sola |',
				'| /envios/',
				'Envío gratis | www.akibara.cl/envios/',
				'Ojo | <b>x</b>.cl',
			)
		);

		self::assertSame(
			array(
				2  => array( 'tras' => '/retiro sin costo/', 'sin_texto' => false ),
				7  => array( 'tras' => 'envios', 'sin_texto' => false ),
				8  => array( 'tras' => 'javascript:alert(1)', 'sin_texto' => false ),
				9  => array( 'tras' => '', 'sin_texto' => false ),
				10 => array( 'tras' => '/envios/', 'sin_texto' => true ),
				12 => array( 'tras' => '<b>x</b>.cl', 'sin_texto' => false ),
			),
			AnnouncementBar::unrecognized_links( (string) $this->options['akibara_announcement_messages'] )
		);

		$avisos = ( new AnnouncementBar() )->report_saved_with_warning( array(), 'announcement' );
		self::assertSame( array( 'akibara_announcement_messages' ), array_keys( $avisos ), 'Sólo el campo con problemas.' );
		$aviso = $avisos['akibara_announcement_messages'];
		self::assertStringStartsWith( 'En la línea 2 «/retiro sin costo/» no es una dirección; en la línea 7 «envios» no es una dirección', $aviso );
		self::assertStringContainsString( 'la línea 9 termina en «|» sin dirección', $aviso );
		self::assertStringContainsString( 'la línea 10 no tiene texto antes de «|»', $aviso );
		self::assertStringContainsString( '«&lt;b&gt;x&lt;/b&gt;.cl»', $aviso, 'Lo escrito por el dueño se lee tal cual, sin pintarse como HTML.' );
		self::assertStringContainsString( 'empezando por / (una página de la tienda, como /envios/) o por https://', $aviso );

		self::assertSame( array( 'otra' => 'x' ), ( new AnnouncementBar() )->report_saved_with_warning( array( 'otra' => 'x' ), 'copys' ), 'Otra pestaña: no toca nada.' );

		$this->options['akibara_announcement_messages'] = "Envío gratis | /envios/\nManga | Cómic";
		self::assertSame( array(), ( new AnnouncementBar() )->report_saved_with_warning( array(), 'announcement' ), 'Todo reconocido: sin aviso.' );
	}

	/**
	 * @testdox El aviso del guardado está enganchado al panel.
	 */
	public function test_the_save_warning_is_hooked(): void {
		Functions\when( 'is_admin' )->justReturn( true );
		$sut = new AnnouncementBar();
		$sut->register();

		self::assertSame( 10, has_filter( 'akibara_panel_options_saved_with_warning', array( $sut, 'report_saved_with_warning' ) ) );
	}
}
